<?php
declare(strict_types=1);

namespace Infrastructure;

use Infrastructure\USSD\Contracts\UssdGatewayAdapter;
use Infrastructure\QRcodes\QrCodeService;
use Infrastructure\QRcodes\EmvQrAdapter;
use RuntimeException;

/**
 * Loads channel configs the same way MessageAdapterFactory loads message
 * format configs: one global registry (Core/Config/channel_adapters.php)
 * plus per-country YAML for the specifics of each gateway/provider.
 */
class ChannelAdapterFactory
{
    private array $globalConfig;
    private string $countryCode;
    private array $instances = [];

    public function __construct(string $countryCode)
    {
        $this->countryCode = trim($countryCode);

        $configFile = dirname(__DIR__) . '/Core/Config/channel_adapters.php';
        if (!file_exists($configFile)) {
            throw new RuntimeException("Missing config: {$configFile}");
        }

        $config = require $configFile;
        if (!is_array($config)) {
            throw new RuntimeException("Invalid config file: {$configFile}");
        }

        $this->globalConfig = $config;
    }

    /**
     * Get a USSD gateway adapter by gateway key (e.g. 'AFRICASTALKING_STYLE').
     * Gateway key normally comes from country config (which gateway that
     * country's USSD shortcode is bound to).
     */
    public function getUssdGatewayAdapter(string $gatewayKey): UssdGatewayAdapter
    {
        $cacheKey = "ussd_{$this->countryCode}_{$gatewayKey}";
        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }

        $yamlPath = dirname(__DIR__)
            . "/Core/Config/Countries/{$this->countryCode}/ussd_gateways.yaml";

        if (!file_exists($yamlPath)) {
            throw new RuntimeException("USSD gateway config not found: {$yamlPath}");
        }

        $allGateways = $this->parseSimpleYaml(file_get_contents($yamlPath));

        if (!isset($allGateways[$gatewayKey])) {
            throw new RuntimeException("USSD gateway '{$gatewayKey}' not defined in {$yamlPath}");
        }

        $adapter = new UssdGatewayAdapter($allGateways[$gatewayKey], $gatewayKey);
        $this->instances[$cacheKey] = $adapter;

        return $adapter;
    }

    /**
     * Get the QrCodeService pre-loaded with all adapters registered for
     * this country (EMVCo by default, plus any proprietary ones configured).
     */
    public function getQrCodeService(): QrCodeService
    {
        $cacheKey = "qr_{$this->countryCode}";
        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }

        $service = new QrCodeService();

        $qrConfigPath = dirname(__DIR__)
            . "/Core/Config/Countries/{$this->countryCode}/qr_providers.yaml";

        $institutionTagMap = [];
        if (file_exists($qrConfigPath)) {
            $qrConfig = $this->parseSimpleYaml(file_get_contents($qrConfigPath));
            $institutionTagMap = $qrConfig['EMVCO']['institution_tag_map'] ?? [];
        }

        $service->registerAdapter(new EmvQrAdapter($institutionTagMap));

        // Additional proprietary QR adapters get registered here based on
        // $this->globalConfig['qr_adapters'] the same way message adapters
        // are loaded - left as an extension point once you have a concrete
        // proprietary spec (e.g. M-Pesa QR) to build against.

        $this->instances[$cacheKey] = $service;
        return $service;
    }

    /**
     * Minimal YAML parser matching the style already used in your codebase
     * (GenericBankClient::parseEndpointsYaml / SwapService::parseYaml).
     * Swap for symfony/yaml if you'd rather - not required for this to work.
     */
    private function parseSimpleYaml(string $content): array
    {
        $result = [];
        $lines = explode("\n", $content);
        $stack = [&$result];
        $indents = [0];

        foreach ($lines as $line) {
            if (trim($line) === '' || ltrim($line)[0] === '#') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            $trimmed = trim($line);

            while (count($indents) > 1 && $indent < end($indents)) {
                array_pop($indents);
                array_pop($stack);
            }

            if (preg_match('/^([\w.-]+):\s*$/', $trimmed, $m)) {
                $stack[count($stack) - 1][$m[1]] = [];
                $stack[] = &$stack[count($stack) - 1][$m[1]];
                $indents[] = $indent + 2;
            } elseif (preg_match('/^-\s*([\w.\/-]+)$/', $trimmed, $m)) {
                $stack[count($stack) - 1][] = $m[1];
            } elseif (preg_match('/^([\w.-]+):\s*\[(.*)\]$/', $trimmed, $m)) {
                // Inline flow-style list, e.g. `key: [a, b, c]`
                $items = array_map(
                    fn($v) => trim($v, '"\' '),
                    $m[2] === '' ? [] : explode(',', $m[2])
                );
                $stack[count($stack) - 1][$m[1]] = $items;
            } elseif (preg_match('/^([\w.-]+):\s*(.+)$/', $trimmed, $m)) {
                $value = trim($m[2], '"\' ');
                $stack[count($stack) - 1][$m[1]] = $value;
            }
        }

        return $result;
    }
}
