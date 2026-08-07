<?php
declare(strict_types=1);

namespace Infrastructure\Banks;

use Infrastructure\Adapters\InstitutionAdapterInterface;
use Infrastructure\Auth\AuthSchemeRegistry;
use Domain\Services\Routing\Exceptions\SwitchUnavailableException;

/**
 * Adapter for the CENTRALSWITCH rail. Implements InstitutionAdapterInterface
 * so InstitutionAdapterFactory can hand it out identically to any other
 * institution adapter (this previously implemented the older BankAPIInterface,
 * whose method names/signatures don't match InstitutionAdapterInterface at
 * all - debitFunds() vs debit(), verifyAsset($payload) vs
 * verifyAsset($payload, $context), etc. - so it was never actually reachable
 * via the factory; confirmed nothing in the codebase ever instantiated this
 * class directly either, so this rewrite is safe).
 *
 * Unlike GenericBankClient, this has no hold concept at all (a switch settles
 * instantly, it doesn't reserve) — every hold/release/cashout/account method
 * returns an explicit "not applicable" response rather than silently no-op-ing,
 * since the ONLY method a SWITCH-mode swap actually calls is submitTransfer()
 * (see SwitchExecutionStrategy::execute()).
 */
class CentralSwitchAdapter implements InstitutionAdapterInterface
{
    private AuthSchemeRegistry $authRegistry;
    private array $config;
    private string $institution;

    /**
     * Constructor shape matches what InstitutionAdapterFactory actually
     * calls for every adapter_class: (bankClient, logger, institution,
     * participant). $bankClient/$logger aren't needed here - this adapter
     * builds its own HTTP config directly from endpoints.yaml, the same
     * way GenericBankClient does internally, rather than going through it.
     */
    public function __construct($bankClient, $logger, string $institution, array $participant)
    {
        $this->institution = $institution;
        $this->authRegistry = new AuthSchemeRegistry();
        $this->config = $this->loadEndpointsYamlConfig($institution);
    }

    private function loadEndpointsYamlConfig(string $institution): array
    {
        $countryName = $GLOBALS['country_config']['name'] ?? 'Botswana';
        $yamlPath = __DIR__ . '/../../Core/Config/Countries/' . $countryName . '/endpoints.yaml';

        if (!file_exists($yamlPath)) {
            throw new \RuntimeException("endpoints.yaml not found at {$yamlPath} while building CentralSwitchAdapter");
        }

        if (!function_exists('yaml_parse_file')) {
            throw new \RuntimeException(
                "php-yaml extension not available - CentralSwitchAdapter requires it to correctly " .
                "parse endpoints.yaml's nested auth/endpoints blocks (a hand-rolled regex parser " .
                "silently drops nested YAML - see the switch_participant_ids parsing bug this fix follows)."
            );
        }

        $parsed = yaml_parse_file($yamlPath);
        $config = $parsed[$institution] ?? null;

        if ($config === null) {
            throw new \RuntimeException("No endpoints.yaml entry found for institution: {$institution}");
        }

        return $config;
    }

    private function baseUrl(): string
    {
        return rtrim($this->config['base_url'], '/');
    }

    private function endpoint(string $key): string
    {
        return $this->config['endpoints']['common'][$key]
            ?? throw new \RuntimeException("No CENTRALSWITCH endpoint configured for: {$key}");
    }

    private function send(string $endpointKey, array $payload): array
    {
        $url = $this->baseUrl() . $this->endpoint($endpointKey);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $secretVar = $this->config['auth']['secret_source']['name'] ?? null;
        $secret = $secretVar ? (getenv($secretVar) ?: '') : '';

        $authHeaders = $this->authRegistry->sign($this->config['auth']['type'], $body, [
            'secret' => $secret,
            'timestamp_header' => $this->config['message_profile']['timestamp_header'] ?? 'X-Api-Timestamp',
            'signature_header' => $this->config['message_profile']['signature_header'] ?? 'X-Api-Signature',
        ]);

        $headers = ['Content-Type: application/json'];
        foreach ($authHeaders as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => ($this->config['timeout_ms'] ?? 10000) / 1000,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode === 0) {
            throw new SwitchUnavailableException("Central switch unreachable: {$curlError}");
        }

        $data = json_decode($response, true) ?? [];
        return ['http_code' => $httpCode, 'body' => $data];
    }

    // ============================================================
    // The one real method - what SwitchExecutionStrategy actually calls.
    // ============================================================
    public function submitTransfer(array $payload): array
    {
        $result = $this->send('submit_transfer', $payload);

        if ($result['http_code'] >= 200 && $result['http_code'] < 300 && ($result['body']['success'] ?? false)) {
            return ['success' => true, 'data' => $result['body']['data'] ?? []];
        }

        return ['success' => false, 'message' => $result['body']['message'] ?? "HTTP {$result['http_code']}"];
    }

    public function getTransferStatus(string $reference): array
    {
        $result = $this->send('transaction_status', ['reference' => $reference]);
        return $result['body'];
    }

    // ------------------------------------------------------------
    // Not applicable to a switch rail — explicit refusal, not a
    // silent no-op, so a caller that mistakenly routes a hold-based
    // operation here fails loudly and clearly.
    // ------------------------------------------------------------
    private function notApplicable(string $method): array
    {
        return [
            'success' => false,
            'not_applicable' => true,
            'message' => "{$method} is not applicable to CENTRALSWITCH — switches settle atomically via submitTransfer(), they have no hold/cashout/account concept of their own.",
        ];
    }

    // ============================================================
    // InstitutionAdapterInterface's required surface. Never called for
    // a SWITCH-mode swap in practice, but must exist with the correct
    // (payload, context) signature to satisfy the interface.
    // ============================================================
    public function verifyAsset(array $payload, array $context): array { return $this->notApplicable('verifyAsset'); }
    public function placeHold(array $payload, array $context): array { return $this->notApplicable('placeHold'); }
    public function debit(array $payload, array $context): array { return $this->notApplicable('debit'); }
    public function credit(array $payload, array $context): array { return $this->notApplicable('credit'); }
    public function releaseHold(array $payload, array $context): array { return $this->notApplicable('releaseHold'); }
    public function generateCashoutToken(array $payload, array $context): array { return $this->notApplicable('generateCashoutToken'); }
    public function verifyCashoutToken(array $payload, array $context): array { return $this->notApplicable('verifyCashoutToken'); }
    public function confirmCashout(array $payload, array $context): array { return $this->notApplicable('confirmCashout'); }
    public function verifyAccount(array $payload, array $context): array { return $this->notApplicable('verifyAccount'); }
    public function getBalance(array $payload, array $context): array { return $this->notApplicable('getBalance'); }
    public function getTransactions(array $payload, array $context): array { return $this->notApplicable('getTransactions'); }
    public function checkSettlementStatus(array $payload, array $context): array { return $this->notApplicable('checkSettlementStatus'); }
    public function getAccounts(array $payload, array $context): array { return $this->notApplicable('getAccounts'); }

    public function supports(string $capability): bool
    {
        return $capability === 'submitTransfer';
    }

    public function getInstitution(): string
    {
        return $this->institution;
    }

    // ============================================================
    // Harmless convenience aliases kept from the original file - not
    // part of InstitutionAdapterInterface, don't collide with it,
    // safe to keep for anything that might call them directly.
    // ============================================================
    public function transferWithProof(array $payload): array { return $this->submitTransfer($payload); }
    public function processDeposit(array $payload): array { return $this->submitTransfer($payload); }
    public function processDepositWithProof(array $payload): array { return $this->submitTransfer($payload); }
    public function transfer(array $payload, ?string $type = null): array { return $this->submitTransfer($payload); }
    public function checkStatus(string $reference): array { return $this->getTransferStatus($reference); }
}
