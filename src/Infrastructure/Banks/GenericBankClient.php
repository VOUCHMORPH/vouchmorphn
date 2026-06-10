<?php
// Infrastructure/Banks/GenericBankClient.php

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
    protected ?string $bankPrefix = null;

    public function __construct(array $config, ?array $requestPayload = null, ?array $headers = null, ?string $endpoint = null)
    {
        $this->config = $config;
        
        // Determine bank prefix for environment variables
        $this->bankPrefix = strtoupper($this->config['provider_code'] ?? '');
        if (empty($this->bankPrefix) && isset($this->config['name'])) {
            $this->bankPrefix = strtoupper($this->config['name']);
        }
        
        // Initialize MessageAdapterFactory with country from config
        $countryCode = $config['country_code'] ?? 'Botswana';
        try {
            $this->adapterFactory = new MessageAdapterFactory($countryCode);
            $this->detectedFormat = 'JSON';
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
        error_log("Bank Prefix: {$this->bankPrefix}");
        error_log("Detected Format: {$this->detectedFormat}");
    }
    
    public function getDetectedFormat(): ?string { return $this->detectedFormat; }
    public function getDetectionConfidence(): ?int { return $this->detectionConfidence; }
    public function getDetectionSource(): ?string { return $this->detectionSource; }
    public function getDetectionDetails(): array { return $this->detectionDetails; }

    // ============================================================================
    // BASE URL FROM ENVIRONMENT OR CONFIG
    // ============================================================================

    protected function getBaseUrl(): string
    {
        // Check for bank-specific base URL from environment (Railway vault)
        if ($this->bankPrefix) {
            $envVar = $this->bankPrefix . '_BASE_URL';
            $baseUrl = getenv($envVar);
            if ($baseUrl && !empty($baseUrl)) {
                error_log("Using base URL from env: {$envVar} = {$baseUrl}");
                return rtrim($baseUrl, '/');
            }
        }
        
        // Check generic base URL from environment
        $genericBaseUrl = getenv('BANK_BASE_URL');
        if ($genericBaseUrl && !empty($genericBaseUrl)) {
            error_log("Using base URL from generic env: BANK_BASE_URL = {$genericBaseUrl}");
            return rtrim($genericBaseUrl, '/');
        }
        
        // Fallback to config
        $configUrl = $this->config['base_url'] ?? '';
        if (!empty($configUrl)) {
            error_log("Using base URL from config: {$configUrl}");
            return rtrim($configUrl, '/');
        }
        
        error_log("WARNING: No base URL found for bank");
        return '';
    }

    // ============================================================================
    // API KEY FROM ENVIRONMENT
    // ============================================================================

    protected function getApiKey(): ?string
    {
        // Check for bank-specific API key from environment
        if ($this->bankPrefix) {
            $envVar = $this->bankPrefix . '_API_KEY';
            $apiKey = getenv($envVar);
            if ($apiKey && !empty($apiKey)) {
                return $apiKey;
            }
        }
        
        // Check generic API key from environment
        $genericApiKey = getenv('BANK_API_KEY');
        if ($genericApiKey && !empty($genericApiKey)) {
            return $genericApiKey;
        }
        
        // Check config
        if (isset($this->config['security']['api_key']['value'])) {
            return $this->config['security']['api_key']['value'];
        }
        
        return null;
    }

    // ============================================================================
    // ENDPOINT RESOLUTION FROM ENVIRONMENT OR CONFIG
    // ============================================================================

    protected function getEndpoint(string $action): ?string
    {
        // Map actions to environment variable names
        $envMap = [
            'verify_asset' => 'VERIFY_ENDPOINT',
            'place_hold' => 'HOLD_ENDPOINT',
            'release_hold' => 'RELEASE_HOLD_ENDPOINT',
            'debit_funds' => 'DEBIT_ENDPOINT',
            'generate_token' => 'GENERATE_TOKEN_ENDPOINT',
            'verify_token' => 'VERIFY_TOKEN_ENDPOINT',
            'confirm_cashout' => 'CONFIRM_CASHOUT_ENDPOINT',
            'process_deposit' => 'PROCESS_DEPOSIT_ENDPOINT',
            'check_status' => 'STATUS_ENDPOINT',
            'reverse_transaction' => 'REVERSE_ENDPOINT',
            'account_balance' => 'BALANCE_ENDPOINT',
            'transactions' => 'TRANSACTIONS_ENDPOINT',
        ];
        
        $actionKey = $envMap[$action] ?? null;
        
        // PRIORITY 1: Bank-specific environment variable (Railway vault)
        if ($actionKey && $this->bankPrefix) {
            $envVar = $this->bankPrefix . '_' . $actionKey;
            $endpoint = getenv($envVar);
            if ($endpoint && !empty($endpoint)) {
                error_log("Using endpoint from env: {$envVar} = {$endpoint}");
                return $endpoint;
            }
        }
        
        // PRIORITY 2: Generic environment variable
        if ($actionKey) {
            $genericVar = 'BANK_' . $actionKey;
            $endpoint = getenv($genericVar);
            if ($endpoint && !empty($endpoint)) {
                error_log("Using endpoint from generic env: {$genericVar} = {$endpoint}");
                return $endpoint;
            }
        }
        
        // PRIORITY 3: Check in endpoints.source from config (for source role methods)
        if (isset($this->config['endpoints']['source'][$action])) {
            error_log("Using endpoint from config endpoints.source: {$action} = " . $this->config['endpoints']['source'][$action]);
            return $this->config['endpoints']['source'][$action];
        }
        
        // PRIORITY 4: Check in endpoints.destination_cashout (for cashout methods)
        if (isset($this->config['endpoints']['destination_cashout'][$action])) {
            error_log("Using endpoint from config endpoints.destination_cashout: {$action} = " . $this->config['endpoints']['destination_cashout'][$action]);
            return $this->config['endpoints']['destination_cashout'][$action];
        }
        
        // PRIORITY 5: Check in endpoints.destination_deposit (for deposit methods)
        if (isset($this->config['endpoints']['destination_deposit'][$action])) {
            error_log("Using endpoint from config endpoints.destination_deposit: {$action} = " . $this->config['endpoints']['destination_deposit'][$action]);
            return $this->config['endpoints']['destination_deposit'][$action];
        }
        
        // PRIORITY 6: Check in endpoints.common (for common methods)
        if (isset($this->config['endpoints']['common'][$action])) {
            error_log("Using endpoint from config endpoints.common: {$action} = " . $this->config['endpoints']['common'][$action]);
            return $this->config['endpoints']['common'][$action];
        }
        
        // PRIORITY 7: Check in resource_endpoints (legacy)
        if (isset($this->config['resource_endpoints'][$action])) {
            error_log("Using endpoint from config resource_endpoints: {$action} = " . $this->config['resource_endpoints'][$action]);
            return $this->config['resource_endpoints'][$action];
        }
        
        error_log("No endpoint found for action: {$action}");
        return null;
    }

    // ============================================================================
    // OAUTH METHODS
    // ============================================================================

    public function getAuthorizationUrl(string $redirectUri, string $state, array $scope = []): string
    {
        $oauthConfig = $this->config['security']['oauth2'] ?? null;
        if (!$oauthConfig) {
            throw new \RuntimeException("OAuth2 not configured for " . ($this->config['provider_code'] ?? 'unknown'));
        }
        
        $baseUrl = $this->getBaseUrl();
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
        
        $baseUrl = $this->getBaseUrl();
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
        
        $baseUrl = $this->getBaseUrl();
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
        
        $baseUrl = $this->getBaseUrl();
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
        $baseUrl = $this->getBaseUrl();
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
        $baseUrl = $this->getBaseUrl();
        $balanceEndpoint = $this->getEndpoint('account_balance') ?? '/api/v1/accounts/balance.php';
        
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
        $baseUrl = $this->getBaseUrl();
        $transactionsEndpoint = $this->getEndpoint('transactions') ?? '/api/v1/accounts/transactions.php';
        
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

        $baseUrl = $this->getBaseUrl();
        
        if (empty($baseUrl)) {
            error_log("Base URL not configured for " . ($this->config['provider_code'] ?? 'unknown'));
            return [
                'success' => false,
                'error' => "Base URL not configured",
                'data' => []
            ];
        }
        
        $endpoint = ltrim($endpoint, '/');
        $url = $baseUrl . '/' . $endpoint;
        
        $headers = $this->buildHeaders($payload, $accessToken);
        
        error_log("Sending request to: {$url}");
        error_log("Payload: " . json_encode($payload));
        
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
        
        error_log("Response HTTP {$httpCode}: " . substr($response, 0, 500));
        
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
        
        // Add API key from environment or config
        $apiKey = $this->getApiKey();
        if ($apiKey) {
            $headerName = $this->config['security']['api_key']['header_name'] ?? 'X-API-Key';
            $headers[] = $headerName . ': ' . $apiKey;
            error_log("Added API key header: {$headerName}");
        }
        
        return $headers;
    }
}
