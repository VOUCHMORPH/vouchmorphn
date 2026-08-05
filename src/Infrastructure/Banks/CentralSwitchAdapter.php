<?php
declare(strict_types=1);

namespace Infrastructure\Banks;

use Infrastructure\Banks\Contracts\BankAPIInterface;
use Infrastructure\Auth\AuthSchemeRegistry;
use Domain\Services\Routing\Exceptions\SwitchUnavailableException;

/**
 * Adapter for the CENTRALSWITCH rail. Implements BankAPIInterface so
 * InstitutionAdapterFactory can hand it out identically to any other
 * institution adapter. Unlike GenericBankClient, this has no hold
 * concept at all (a switch settles instantly, it doesn't reserve) —
 * every hold/release/debit-hold method returns an explicit
 * "not supported" response rather than silently no-op-ing.
 */
class CentralSwitchAdapter implements BankAPIInterface
{
    private AuthSchemeRegistry $authRegistry;

    public function __construct(private array $config)
    {
        $this->authRegistry = new AuthSchemeRegistry();
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
    // operation here fails loudly.
    // ------------------------------------------------------------
    private function notSupported(string $method): array
    {
        return ['success' => false, 'message' => "{$method} is not supported by CENTRALSWITCH — switches settle instantly, they do not hold funds"];
    }

    public function initiateSourceLink(array $params): array { return $this->notSupported('initiateSourceLink'); }
    public function verifySourceLink(array $params): array { return $this->notSupported('verifySourceLink'); }
    public function refreshSourceToken(array $params): array { return $this->notSupported('refreshSourceToken'); }
    public function revokeSourceToken(array $params): array { return $this->notSupported('revokeSourceToken'); }
    public function useSourceToken(array $params): array { return $this->notSupported('useSourceToken'); }
    public function getAuthorizationUrl(string $redirectUri, string $state, array $scope = []): string { throw new \RuntimeException('Not supported by CENTRALSWITCH'); }
    public function exchangeCodeForToken(string $code, string $redirectUri): array { return $this->notSupported('exchangeCodeForToken'); }
    public function refreshAccessToken(string $refreshToken): array { return $this->notSupported('refreshAccessToken'); }
    public function revokeToken(string $token, string $tokenType = 'access_token'): bool { return false; }
    public function getUserInfo(string $accessToken): array { return $this->notSupported('getUserInfo'); }
    public function getAccountBalance(string $accessToken, string $accountId): array { return $this->notSupported('getAccountBalance'); }
    public function getTransactions(string $accessToken, string $accountId, int $limit = 50, int $offset = 0): array { return $this->notSupported('getTransactions'); }
    public function verifyAsset(array $payload): array { return $this->notSupported('verifyAsset'); }
    public function placeHold(array $payload): array { return $this->notSupported('placeHold'); }
    public function debitFunds(array $payload): array { return $this->notSupported('debitFunds'); }
    public function releaseHold(array $payload): array { return $this->notSupported('releaseHold'); }
    public function generateToken(array $payload): array { return $this->notSupported('generateToken'); }
    public function verifyToken(array $payload): array { return $this->notSupported('verifyToken'); }
    public function confirmCashout(array $payload): array { return $this->notSupported('confirmCashout'); }
    public function processDeposit(array $payload): array { return $this->submitTransfer($payload); }
    public function verifyAssetSigned(array $payload): array { return $this->notSupported('verifyAssetSigned'); }
    public function placeHoldSigned(array $payload): array { return $this->notSupported('placeHoldSigned'); }
    public function transferWithProof(array $payload): array { return $this->submitTransfer($payload); }
    public function generateTokenWithProof(array $payload): array { return $this->notSupported('generateTokenWithProof'); }
    public function processDepositWithProof(array $payload): array { return $this->submitTransfer($payload); }
    public function transfer(array $payload, ?string $type = null): array { return $this->submitTransfer($payload); }
    public function reverse(array $payload): array { return $this->notSupported('reverse'); }
    public function checkStatus(string $reference): array { return $this->getTransferStatus($reference); }
}
