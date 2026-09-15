<?php
declare(strict_types=1);

namespace Infrastructure\Banks;

use Infrastructure\Adapters\InstitutionAdapterInterface;
use Infrastructure\Auth\AuthSchemeRegistry;
use Infrastructure\Messaging\MessageFormatterInterface;
use Infrastructure\Messaging\MessageFormatterFactory;
use Domain\Services\Routing\Exceptions\SwitchUnavailableException;

/**
 * Adapter for the CENTRALSWITCH rail. Implements InstitutionAdapterInterface
 * so InstitutionAdapterFactory can hand it out identically to any other
 * institution adapter.
 *
 * ============================================================================
 * PROTOCOL PLUGGABILITY - READ BEFORE INTEGRATING A REAL SWITCH
 * ============================================================================
 * The mock CENTRALSWITCH speaks JSON over plain HTTPS with HMAC-signed
 * bodies. A REAL national switch operator's spec could look completely
 * different - mutual TLS, OAuth2, ISO 20022 XML messages, or any
 * combination of those. Rather than hardcode one protocol, this class
 * treats three axes as independently configurable, the same way DIRECT
 * vs SWITCH routing is already config-driven rather than hardcoded:
 *
 *   1. AUTH SCHEME (endpoints.yaml auth.type) - API_KEY, HMAC_SHARED_SECRET,
 *      or OAUTH_BEARER, via AuthSchemeRegistry. Already fully pluggable;
 *      switching schemes is a config change, not a code change.
 *
 *   2. TRANSPORT (endpoints.yaml transport.mtls) - plain HTTPS, or mutual
 *      TLS with a client certificate. Independent of auth scheme: a real
 *      switch could require mTLS AND a signed body, not one or the other.
 *
 *   3. MESSAGE FORMAT (endpoints.yaml message_format) - JSON (default,
 *      fully working) or ISO20022 (interface exists, NOT implemented -
 *      see Infrastructure\Messaging\Iso20022MessageFormatter's docblock;
 *      building it blind without a real schema would be worse than
 *      leaving it a loud stub).
 *
 * Swapping any one of these for a real switch's actual requirements should
 * mean a new AuthSchemeInterface/MessageFormatterInterface implementation
 * plus config, NOT a rewrite of this class, SwitchExecutionStrategy, or
 * anything in the routing layer above it.
 * ============================================================================
 */
class CentralSwitchAdapter implements InstitutionAdapterInterface
{
    private AuthSchemeRegistry $authRegistry;
    private MessageFormatterInterface $formatter;
    private array $config;
    private string $institution;

    /** Temp file paths for mTLS material, cleaned up in the destructor. */
    private array $mtlsTempFiles = [];

    public function __construct($bankClient, $logger, string $institution, array $participant)
    {
        $this->institution = $institution;
        $this->authRegistry = new AuthSchemeRegistry();
        $this->config = $this->loadEndpointsYamlConfig($institution);
        $this->formatter = MessageFormatterFactory::forConfig($this->config);
    }

    public function __destruct()
    {
        // mTLS material is written to disk only for the lifetime of this
        // request - never left behind for a later process to stumble on.
        foreach ($this->mtlsTempFiles as $path) {
            if (is_string($path) && file_exists($path)) {
                @unlink($path);
            }
        }
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

    /**
     * Resolves one secret from endpoints.yaml's env_var-source convention.
     * Shared by mTLS material and (indirectly, via AuthSchemeRegistry) the
     * HMAC/API-key secret - one place that knows how "secret_source" is
     * structured, rather than each caller re-deriving it.
     */
    private function resolveSecret(?array $secretSource): ?string
    {
        if (!$secretSource || ($secretSource['type'] ?? null) !== 'env_var') {
            return null;
        }
        $name = $secretSource['name'] ?? null;
        if (!$name) return null;
        $value = getenv($name);
        return $value ?: null;
    }

    /**
     * Writes PEM content (already stored as env var content elsewhere in
     * this codebase - see VOUCHMORPH_CERT_CONTENT, ZURUBANK_CERT_CONTENT,
     * etc.) to a request-scoped temp file, since curl's CURLOPT_SSLCERT/
     * CURLOPT_SSLKEY need a filesystem path, not a raw string, on most
     * libcurl builds. Tracked in $this->mtlsTempFiles for cleanup.
     */
    private function materializePemToTempFile(string $pemContent, string $label): string
    {
        // Env vars commonly store PEM content with literal \n escapes
        // rather than real newlines - same normalization already used
        // elsewhere in this codebase for cert/key content.
        $normalized = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $pemContent);

        $path = tempnam(sys_get_temp_dir(), 'switch_mtls_' . $label . '_');
        if ($path === false) {
            throw new \RuntimeException("Failed to create temp file for mTLS {$label}");
        }
        chmod($path, 0600);
        file_put_contents($path, $normalized);
        $this->mtlsTempFiles[] = $path;
        return $path;
    }

    /**
     * Applies mTLS curl options if transport.mtls.enabled is true in this
     * institution's endpoints.yaml block. No-op (plain HTTPS, unchanged
     * behavior) if that block is absent - existing institutions with no
     * transport.mtls config are completely unaffected by this method
     * existing.
     */
    private function applyMutualTls($curlHandle): void
    {
        $mtls = $this->config['transport']['mtls'] ?? null;
        if (!$mtls || empty($mtls['enabled'])) {
            return;
        }

        $certContent = $this->resolveSecret($mtls['cert_source'] ?? null);
        $keyContent = $this->resolveSecret($mtls['key_source'] ?? null);
        $caContent = $this->resolveSecret($mtls['ca_source'] ?? null);
        $keyPassphrase = $this->resolveSecret($mtls['key_passphrase_source'] ?? null);

        if (!$certContent || !$keyContent) {
            throw new \RuntimeException(
                "transport.mtls.enabled is true for {$this->institution} but cert_source/key_source " .
                "did not resolve to actual values - check the referenced env vars are set."
            );
        }

        $certPath = $this->materializePemToTempFile($certContent, 'cert');
        $keyPath = $this->materializePemToTempFile($keyContent, 'key');

        curl_setopt($curlHandle, CURLOPT_SSLCERT, $certPath);
        curl_setopt($curlHandle, CURLOPT_SSLCERTTYPE, 'PEM');
        curl_setopt($curlHandle, CURLOPT_SSLKEY, $keyPath);
        curl_setopt($curlHandle, CURLOPT_SSLKEYTYPE, 'PEM');
        if ($keyPassphrase) {
            curl_setopt($curlHandle, CURLOPT_SSLKEYPASSWD, $keyPassphrase);
        }
        if ($caContent) {
            $caPath = $this->materializePemToTempFile($caContent, 'ca');
            curl_setopt($curlHandle, CURLOPT_CAINFO, $caPath);
        }
    }

    private function send(string $endpointKey, array $payload): array
    {
        $url = $this->baseUrl() . $this->endpoint($endpointKey);
        $body = $this->formatter->encode($payload);

        $headers = ['Content-Type: ' . $this->formatter->contentType()];

        // Auth scheme (API_KEY / HMAC_SHARED_SECRET / OAUTH_BEARER) - fully
        // independent of transport below. Skipped entirely if this
        // institution's config has no auth.type (e.g. an mTLS-only switch
        // where the client certificate itself IS the credential).
        $authType = $this->config['auth']['type'] ?? null;
        if ($authType) {
            $secret = $this->resolveSecret($this->config['auth']['secret_source'] ?? null);
            $authHeaders = $this->authRegistry->sign($authType, $body, [
                'secret' => $secret,
                'key' => $secret,
                'access_token' => $secret,
                'timestamp_header' => $this->config['message_profile']['timestamp_header'] ?? 'X-Api-Timestamp',
                'signature_header' => $this->config['message_profile']['signature_header'] ?? 'X-Api-Signature',
                'header_name' => $this->config['auth']['header_name'] ?? 'X-API-Key',
            ]);
            foreach ($authHeaders as $name => $value) {
                $headers[] = "{$name}: {$value}";
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => ($this->config['timeout_ms'] ?? 10000) / 1000,
        ]);

        // Transport (mTLS) - independent of the auth headers set above.
        $this->applyMutualTls($ch);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode === 0) {
            throw new SwitchUnavailableException("Central switch unreachable: {$curlError}");
        }

        $data = $this->formatter->decode($response);
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
    public function createReservationAccount(array $payload, array $context): array { return $this->notApplicable('createReservationAccount'); }
    public function getReservationAccountStatus(array $payload, array $context): array { return $this->notApplicable('getReservationAccountStatus'); }

    public function supports(string $capability): bool
    {
        return $capability === 'submitTransfer';
    }

    public function getInstitution(): string
    {
        return $this->institution;
    }

    // ============================================================
    // Harmless convenience aliases - not part of InstitutionAdapterInterface,
    // don't collide with it, kept for anything that might call them directly.
    // ============================================================
    public function transferWithProof(array $payload): array { return $this->submitTransfer($payload); }
    public function processDeposit(array $payload): array { return $this->submitTransfer($payload); }
    public function processDepositWithProof(array $payload): array { return $this->submitTransfer($payload); }
    public function transfer(array $payload, ?string $type = null): array { return $this->submitTransfer($payload); }
    public function checkStatus(string $reference): array { return $this->getTransferStatus($reference); }
}
