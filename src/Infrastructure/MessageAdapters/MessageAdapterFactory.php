<?php

namespace Infrastructure\MessageAdapters;

use RuntimeException;
use Infrastructure\MessageAdapters\MessageAdapterInterface;

class MessageAdapterFactory
{
    private array $globalConfig = [];
    private array $countryConfigs = [];
    private array $instances = [];
    private ?string $currentCountry = null;

    public function __construct(?string $countryCode = null)
    {
        $configPath = dirname(__DIR__, 3) . '/Core/Config/message_adapters.php';

        if (!file_exists($configPath)) {
            throw new RuntimeException("Missing config: Core/Config/message_adapters.php");
        }

        $this->globalConfig = require $configPath;

        if (!is_array($this->globalConfig)) {
            throw new RuntimeException("Invalid message_adapters.php: must return array");
        }

        if ($countryCode) {
            $this->setCountry($countryCode);
        }
    }

    public function setCountry(string $countryCode): self
    {
        $this->currentCountry = strtolower($countryCode);

        $configPath = dirname(__DIR__, 3)
            . "/Core/Config/Countries/{$this->currentCountry}/bank_formats.php";

        if (!file_exists($configPath)) {
            throw new RuntimeException(
                "No bank format config for country: {$countryCode}"
            );
        }

        $this->countryConfigs[$this->currentCountry] = require $configPath;

        if (!is_array($this->countryConfigs[$this->currentCountry])) {
            throw new RuntimeException("Invalid bank_formats.php for {$countryCode}");
        }

        return $this;
    }

    /**
     * ================================
     * SMART DETECTION ENGINE
     * ================================
     */
    public static function detectFromContent(array|string $payload): string
    {
        if (is_string($payload)) {
            if (str_contains($payload, '|')) return 'LEGACY';
            if (str_contains($payload, ',')) return 'CSV';
            return 'LEGACY';
        }

        if (isset($payload['businessMessageId'])
            || isset($payload['debtor'])
            || isset($payload['creditor'])
            || isset($payload['endToEndId'])) {
            return 'ISO20022';
        }

        if (isset($payload['mti']) || isset($payload['bitmap'])) {
            return 'ISO8583';
        }

        if (isset($payload['walletId']) || isset($payload['from']['type'])) {
            return 'MOBILE_MONEY';
        }

        if (isset($payload['settlementDate']) || isset($payload['valueDate'])) {
            return 'RTGS';
        }

        return 'LEGACY';
    }

    public static function detectFromHeaders(array $headers): ?string
    {
        $headers = array_change_key_case($headers, CASE_LOWER);

        $ct = $headers['content-type'] ?? '';

        return match (true) {
            str_contains($ct, 'interoperability') => 'ISO20022',
            str_contains($ct, 'mobile-money') => 'MOBILE_MONEY',
            str_contains($ct, 'iso8583') => 'ISO8583',
            str_contains($ct, 'rtgs') => 'RTGS',
            isset($headers['x-message-standard']) => strtoupper($headers['x-message-standard']),
            default => null,
        };
    }

    public static function smartDetect(
        array|string $payload = [],
        array $headers = [],
        ?string $endpoint = null,
        ?array $participant = null,
        ?string $bankCode = null
    ): array {

        $detections = [];

        if ($participant && isset($participant['message_profile']['standard'])) {
            return [
                'format' => $participant['message_profile']['standard'],
                'confidence' => 100,
                'source' => 'participant_config'
            ];
        }

        if ($bankCode && $participant['bank_formats'][$bankCode] ?? false) {
            return [
                'format' => $participant['bank_formats'][$bankCode],
                'confidence' => 95,
                'source' => 'bank_mapping'
            ];
        }

        if ($h = self::detectFromHeaders($headers)) {
            $detections[] = ['format' => $h, 'confidence' => 85, 'source' => 'headers'];
        }

        if ($endpoint) {
            if (str_contains($endpoint, 'mojaloop') || str_contains($endpoint, 'quotes')) {
                $detections[] = ['format' => 'ISO20022', 'confidence' => 80, 'source' => 'endpoint'];
            }
        }

        $content = self::detectFromContent($payload);
        if ($content) {
            $detections[] = ['format' => $content, 'confidence' => 75, 'source' => 'content'];
        }

        usort($detections, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        $best = $detections[0] ?? [
            'format' => 'ISO20022',
            'confidence' => 30,
            'source' => 'default'
        ];

        return $best + ['all' => $detections];
    }

    public function getAdapter(string $formatType): MessageAdapterInterface
    {
        if (!$this->currentCountry) {
            throw new RuntimeException("Country not set");
        }

        $key = $this->currentCountry . '_' . $formatType;

        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (!isset($this->globalConfig['adapters'][$formatType])) {
            throw new RuntimeException("Unknown adapter: {$formatType}");
        }

        $class = $this->globalConfig['adapters'][$formatType]['class'];

        return $this->instances[$key] = new $class($this->currentCountry);
    }

    public function getAdapterSmart(array|string $payload = [], array $headers = [], ?string $endpoint = null): array
    {
        if (!$this->currentCountry) {
            throw new RuntimeException("Country not set");
        }

        $participant = $this->countryConfigs[$this->currentCountry]['participant_config'] ?? null;

        $detection = self::smartDetect($payload, $headers, $endpoint, $participant);

        $adapter = $this->getAdapter($detection['format']);

        return [
            'adapter' => $adapter,
            'format' => $detection['format'],
            'confidence' => $detection['confidence'],
            'source' => $detection['source']
        ];
    }
}
