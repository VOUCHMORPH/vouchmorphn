<?php
// Infrastructure/Banks/GenericBankClient.php (FIXED)

namespace Infrastructure\Banks;

require_once __DIR__ . '/Contracts/BankAPIInterface.php';

use Infrastructure\Banks\Contracts\BankAPIInterface;
use Infrastructure\MessageAdapters\MessageAdapterFactory;

class GenericBankClient implements BankAPIInterface
{
    protected array $config;
    protected $httpClient;
    protected ?string $detectedFormat = null;
    protected ?int $detectionConfidence = null;
    protected ?string $detectionSource = null;
    protected array $detectionDetails = [];
    protected ?string $cachedAccessToken = null;
    protected ?int $tokenExpiresAt = null;
    protected ?MessageAdapterFactory $adapterFactory = null;

    public function __construct(array $config, ?array $requestPayload = null, ?array $headers = null, ?string $endpoint = null)
    {
        $this->config = $config;
        
        // Initialize MessageAdapterFactory with country from config
        $countryCode = $config['country_code'] ?? 'Botswana';
        try {
            $this->adapterFactory = new MessageAdapterFactory($countryCode);
            $this->detectedFormat = 'JSON'; // Default format
            $this->detectionConfidence = 80;
            $this->detectionSource = 'generic_client';
        } catch (\Exception $e) {
            error_log("MessageAdapterFactory init failed: " . $e->getMessage());
            $this->detectedFormat = 'JSON';
            $this->detectionConfidence = 50;
            $this->detectionSource = 'fallback';
        }
        
        error_log("=== GENERIC BANK CLIENT INIT ===");
        error_log("Bank: " . ($this->config['provider_code'] ?? 'unknown'));
        error_log("Detected Format: {$this->detectedFormat}");
    }
    
    public function getDetectedFormat(): ?string { return $this->detectedFormat; }
    public function getDetectionConfidence(): ?int { return $this->detectionConfidence; }
    public function getDetectionSource(): ?string { return $this->detectionSource; }
    public function getDetectionDetails(): array { return $this->detectionDetails; }

    // ============================================================================
    // OAUTH METHODS
    // ============================================================================

    public function getAuthorizationUrl(string $redirectUri, string $state, array $scope = []): string
    {
        $oauthConfig = $this->config['security']['oauth2'] ?? null;
        if (!$oauthConfig) {
            throw new \RuntimeException("OAuth2 not configured for " . ($this->config['provider_code'] ?? 'unknown'));
        }
        
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $authEndpoint = $oauthConfig['authorization_endpoint'] ?? '/oauth/authorize';
        
        $params = [
            'response_type' => 'code',
            'client_id' => getenv($oauthConfig['client_id_env']) ?: $oauthConfig['client_id'] ?? '',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', $scope ?: explode(' ', $oauthConfig['scope'] ?? 'read_balance')),
            'code_challenge_method' => $oauthConfig['code_challenge_method'] ?? 'S256'
        ];
        
        // Generate PKCE code verifier and challenge
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        
        $_SESSION['oauth_code_verifier_' . $state] = $codeVerifier;
        $params['code_challenge'] = $codeChallenge;
        
        return $baseUrl . $authEndpoint . '?' . http_build_query($params);
    }

    public function exchangeCodeForToken(string $code, string $redirectUri): array
    {
        $oauthConfig = $this->config['security']['oauth2'] ?? null;
        if (!$oauthConfig) {
            throw new \RuntimeException("OAuth2 not configured");
        }
        
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $tokenEndpoint = $oauthConfig['token_endpoint'] ?? '/oauth/token';
        
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => getenv($oauthConfig['client_id_env']) ?: $oauthConfig['client_id'] ?? '',
            'client_secret' => getenv($oauthConfig['client_secret_env']) ?: $oauthConfig['client_secret'] ?? ''
        ];
        
        $ch = curl_init($baseUrl . $tokenEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Token exchange failed: HTTP $httpCode, Response: $response");
            throw new \RuntimeException("Failed to exchange code for token");
        }
        
        $data = json_decode($response, true);
        
        $this->cachedAccessToken = $data['access_token'] ?? null;
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600);
        
        return [
            'access_token' => $data['access_token'] ?? '',
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in' => $data['expires_in'] ?? 3600,
            'token_type' => $data['token_type'] ?? 'Bearer',
            'scope' => $data['scope'] ?? ''
        ];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $oauthConfig = $this->config['security']['oauth2'] ?? null;
        if (!$oauthConfig) {
            throw new \RuntimeException("OAuth2 not configured");
        }
        
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $tokenEndpoint = $oauthConfig['token_endpoint'] ?? '/oauth/token';
        
        $payload = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => getenv($oauthConfig['client_id_env']) ?: $oauthConfig['client_id'] ?? '',
            'client_secret' => getenv($oauthConfig['client_secret_env']) ?: $oauthConfig['client_secret'] ?? ''
        ];
        
        $ch = curl_init($baseUrl . $tokenEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("Failed to refresh token");
        }
        
        $data = json_decode($response, true);
        
        $this->cachedAccessToken = $data['access_token'] ?? null;
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600);
        
        return [
            'access_token' => $data['access_token'] ?? '',
            'expires_in' => $data['expires_in'] ?? 3600
        ];
    }

    public function revokeToken(string $token, string $tokenType = 'access_token'): bool
    {
        $oauthConfig = $this->config['security']['oauth2'] ?? null;
        if (!$oauthConfig) {
            return false;
        }
        
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $revokeEndpoint = $oauthConfig['revoke_endpoint'] ?? '/oauth/revoke';
        
        $payload = [
            'token' => $token,
            'token_type_hint' => $tokenType,
            'client_id' => getenv($oauthConfig['client_id_env']) ?: $oauthConfig['client_id'] ?? '',
            'client_secret' => getenv($oauthConfig['client_secret_env']) ?: $oauthConfig['client_secret'] ?? ''
        ];
        
        $ch = curl_init($baseUrl . $revokeEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    }

    public function getUserInfo(string $accessToken): array
    {
        $oauthConfig = $this->config['security']['oauth2'] ?? null;
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $userinfoEndpoint = $oauthConfig['userinfo_endpoint'] ?? '/oauth/userinfo';
        
        $ch = curl_init($baseUrl . $userinfoEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("Failed to get user info");
        }
        
        return json_decode($response, true);
    }

    public function getAccountBalance(string $accessToken, string $accountId): array
    {
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $balanceEndpoint = $this->config['resource_endpoints']['account_balance'] ?? '/api/v1/accounts/balance.php';
        
        $ch = curl_init($baseUrl . $balanceEndpoint . '?account_id=' . urlencode($accountId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("Failed to get account balance");
        }
        
        $data = json_decode($response, true);
        return $data['data'] ?? $data;
    }

    public function getTransactions(string $accessToken, string $accountId, int $limit = 50, int $offset = 0): array
    {
        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $transactionsEndpoint = $this->config['resource_endpoints']['transactions'] ?? '/api/v1/accounts/transactions.php';
        
        $url = $baseUrl . $transactionsEndpoint . '?' . http_build_query([
            'account_id' => $accountId,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("Failed to get transactions");
        }
        
        $data = json_decode($response, true);
        return $data['data'] ?? $data;
    }

    // ============================================================================
    // SOURCE ROLE METHODS
    // ============================================================================

    public function verifyAsset(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyAsset ===");
        return $this->send('verify_asset', $payload, $payload['access_token'] ?? null);
    }

    public function placeHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: placeHold ===");
        return $this->send('place_hold', $payload, $payload['access_token'] ?? null);
    }

    public function releaseHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: releaseHold ===");
        return $this->send('release_hold', $payload);
    }

    public function debitFunds(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: debitFunds ===");
        return $this->send('debit_funds', $payload, $payload['access_token'] ?? null);
    }

    public function debitHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: debitHold (maps to debitFunds) ===");
        if (!isset($payload['hold_reference'])) {
            return ['success' => false, 'message' => 'hold_reference is required', 'data' => []];
        }
        
        $debitPayload = [
            'reference' => $payload['reference'] ?? $payload['hold_reference'],
            'hold_reference' => $payload['hold_reference'],
            'amount' => $payload['amount'] ?? null,
            'reason' => $payload['reason'] ?? 'Debit hold for completed swap',
            'action' => 'DEBIT_HOLD'
        ];
        
        return $this->debitFunds($debitPayload);
    }

    // ============================================================================
    // DESTINATION ROLE METHODS - CASHOUT TOKEN
    // ============================================================================

    public function generateToken(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: generateToken (CASHOUT TOKEN) ===");
        return $this->send('generate_token', $payload);
    }

    public function verifyToken(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyToken ===");
        return $this->send('verify_token', $payload);
    }

    public function confirmCashout(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: confirmCashout ===");
        return $this->send('confirm_cashout', $payload);
    }

    public function processDeposit(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: processDeposit ===");
        return $this->send('process_deposit', $payload, $payload['access_token'] ?? null);
    }

    // ============================================================================
    // COMMON METHODS
    // ============================================================================

    public function checkStatus(string $reference): array
    {
        error_log("=== GENERIC BANK CLIENT: checkStatus ===");
        return $this->send('check_status', ['reference' => $reference]);
    }

    public function reverseTransaction(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: reverseTransaction ===");
        return $this->send('reverse_transaction', $payload);
    }

    public function authorize(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: authorize (maps to place_hold) ===");
        return $this->placeHold($payload);
    }

    public function transfer(array $payload, ?string $type = null): array
    {
        error_log("=== GENERIC BANK CLIENT: transfer called ===");
        $action = $payload['action'] ?? $type ?? '';
        
        switch ($action) {
            case 'GENERATE_ATM_TOKEN':
            case 'generate_atm_code':
                return $this->generateToken($payload);
            case 'PROCESS_DEPOSIT':
                return $this->processDeposit($payload);
            case 'AUTHORIZE_CASHOUT':
                return $this->authorize($payload);
            case 'DEBIT_HOLD':
                return $this->debitHold($payload);
            default:
                return $this->processDeposit($payload);
        }
    }

    public function reverse(array $payload): array
    {
        return $this->reverseTransaction($payload);
    }

    // ============================================================================
    // PROTECTED HELPERS
    // ============================================================================

    protected function getEndpoint(string $action): ?string
    {
        $endpointMap = [
            'verify_asset' => 'verify_asset',
            'place_hold' => 'place_hold',
            'release_hold' => 'release_hold',
            'debit_funds' => 'debit_funds',
            'generate_token' => 'generate_token',
            'verify_token' => 'verify_token',
            'confirm_cashout' => 'confirm_cashout',
            'process_deposit' => 'process_deposit',
            'check_status' => 'check_status',
            'reverse_transaction' => 'reverse_transaction',
            'account_balance' => 'account_balance',
            'transactions' => 'transactions'
        ];

        $endpointKey = $endpointMap[$action] ?? $action;
        
        // Check in resource_endpoints
        if (isset($this->config['resource_endpoints'][$endpointKey])) {
            return $this->config['resource_endpoints'][$endpointKey];
        }
        
        // Check in endpoints (alternative location)
        if (isset($this->config['endpoints'][$endpointKey])) {
            return $this->config['endpoints'][$endpointKey];
        }
        
        return null;
    }

    protected function send(string $action, array $payload, ?string $accessToken = null): array
    {
        $endpoint = $this->getEndpoint($action);
        
        if (!$endpoint) {
            error_log("Endpoint {$action} not configured for " . ($this->config['provider_code'] ?? 'unknown'));
            return [
                'success' => false,
                'error' => "Endpoint {$action} not configured",
                'data' => []
            ];
        }

        $baseUrl = rtrim($this->config['base_url'] ?? '', '/');
        $endpoint = ltrim($endpoint, '/');
        $url = $baseUrl . '/' . $endpoint;
        
        $headers = $this->buildHeaders($payload, $accessToken);
        
        error_log("Sending request to: {$url}");
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->config['timeout_ms'] ?? 30000,
            CURLOPT_VERBOSE => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        error_log("Response HTTP {$httpCode}");
        
        $decodedResponse = json_decode($response, true);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'status_code' => $httpCode,
            'data' => $decodedResponse ?? [],
            'raw_response' => $response,
            'curl_error' => $curlError,
            'detected_format' => $this->detectedFormat
        ];
    }

    protected function buildHeaders(array $payload, ?string $accessToken = null): array
    {
        $headers = ['Content-Type: application/json'];
        
        if ($this->detectedFormat) {
            $headers[] = 'X-Detected-Format: ' . $this->detectedFormat;
        }
        
        // Add OAuth Bearer token if provided
        if ($accessToken) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }
        
        if (isset($payload['reference'])) {
            $headers[] = 'X-Correlation-ID: ' . $payload['reference'];
        }
        
        // Add API key if configured (for fallback/non-OAuth endpoints)
        if (isset($this->config['security']['api_key'])) {
            $apiKey = $this->config['security']['api_key'];
            $keyValue = getenv($apiKey['value_env'] ?? '');
            if ($keyValue) {
                $headers[] = ($apiKey['header_name'] ?? 'X-API-Key') . ': ' . $keyValue;
            }
        }
        
        return $headers;
    }
}
