<?php
// Infrastructure/Banks/GenericBankClient.php

namespace Infrastructure\Banks; 


require_once __DIR__ . '/Contracts/BankAPIInterface.php';
require_once __DIR__ . '/../MessageAdapters/MessageAdapterFactory.php';

use Infrastructure\Banks\Contracts\BankAPIInterface;
use Infrastructure\MessageAdapters\MessageAdapterFactory;
use Infrastructure\Crypto\MessageSigner;
use Infrastructure\Crypto\CertificateManager;

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
    protected ?MessageSigner $signer = null;
    protected ?CertificateManager $certManager = null;
    
    // NEW: YAML configuration cache
    protected ?array $yamlEndpoints = null;
    protected ?string $yamlBaseUrl = null;

    public function __construct(array $config, ?array $requestPayload = null, ?array $headers = null, ?string $endpoint = null)
    {
        $this->config = $config;
        
        // Increase memory limit for large certificate responses
        ini_set('memory_limit', '512M');
        
        // Determine bank prefix for environment variables
        $this->bankPrefix = strtoupper($this->config['provider_code'] ?? '');
        if (empty($this->bankPrefix) && isset($this->config['name'])) {
            $this->bankPrefix = strtoupper($this->config['name']);
        }
        
        // NEW: Load YAML endpoints configuration from country folder
        $this->loadYamlEndpoints();
        
        // Initialize MessageSigner for RSA signatures
        try {
            $this->signer = new MessageSigner();
        } catch (\Exception $e) {
            error_log("MessageSigner init failed: " . $e->getMessage());
        }
        
        // Initialize CertificateManager for Visa/Mastercard style PKI
        try {
            $this->certManager = new CertificateManager();
            if ($this->certManager->isConfigured()) {
                error_log("GenericBankClient: CertificateManager initialized for {$this->bankPrefix}");
            }
        } catch (\Exception $e) {
            error_log("CertificateManager init failed: " . $e->getMessage());
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
        error_log("CertificateManager: " . ($this->certManager && $this->certManager->isConfigured() ? "ENABLED" : "DISABLED"));
    }
    
    // ============================================================================
    // NEW: YAML ENDPOINT LOADING FROM COUNTRY FOLDER
    // ============================================================================
    
    protected function loadYamlEndpoints(): void
    {
        $countryName = $this->getCountryFromConfig();
        $yamlPath = __DIR__ . '/../../Core/Config/Countries/' . $countryName . '/endpoints.yaml';
        
        if (!file_exists($yamlPath)) {
            error_log("No endpoints.yaml found at: {$yamlPath}");
            return;
        }
        
        error_log("Loading endpoints from YAML: {$yamlPath}");
        $content = file_get_contents($yamlPath);
        $parsed = $this->parseEndpointsYaml($content);
        
        $bankCode = $this->config['provider_code'] ?? $this->bankPrefix;
        
        if (isset($parsed[$bankCode])) {
            if (isset($parsed[$bankCode]['base_url'])) {
                $this->yamlBaseUrl = rtrim($parsed[$bankCode]['base_url'], '/');
                error_log("YAML base URL for {$bankCode}: {$this->yamlBaseUrl}");
            }
            if (isset($parsed[$bankCode]['endpoints'])) {
                $this->yamlEndpoints = $parsed[$bankCode]['endpoints'];
                error_log("Loaded YAML endpoints for {$bankCode}");
            }
        }
    }
    
    protected function getCountryFromConfig(): string
    {
        // Try to get from config first
        if (isset($this->config['country_code'])) {
            return $this->config['country_code'];
        }
        
        // Try from environment
        $country = getenv('VOUCHMORPH_COUNTRY');
        if ($country) {
            return $country;
        }
        
        // Default to Botswana
        return 'Botswana';
    }
    
    protected function parseEndpointsYaml(string $content): array
    {
        $result = [];
        $lines = explode("\n", $content);
        $currentBank = null;
        $currentSection = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            // Bank header (e.g., "ZURUBANK:")
            if (preg_match('/^([A-Z_]+):$/', $line, $matches)) {
                $currentBank = $matches[1];
                $result[$currentBank] = [];
                $currentSection = null;
                continue;
            }
            
            if ($currentBank) {
                // Base URL
                if (preg_match('/^  base_url: "?(.+?)"?$/', $line, $matches)) {
                    $result[$currentBank]['base_url'] = rtrim($matches[1], '"');
                    continue;
                }
                
                // Endpoints section
                if (preg_match('/^  endpoints:$/', $line)) {
                    $currentSection = 'endpoints';
                    $result[$currentBank]['endpoints'] = [];
                    continue;
                }
                
                // Source endpoints
                if ($currentSection === 'endpoints' && preg_match('/^    source:$/', $line)) {
                    $currentSection = 'source';
                    $result[$currentBank]['endpoints']['source'] = [];
                    continue;
                }
                
                // Destination cashout endpoints
                if ($currentSection === 'endpoints' && preg_match('/^    destination_cashout:$/', $line)) {
                    $currentSection = 'destination_cashout';
                    $result[$currentBank]['endpoints']['destination_cashout'] = [];
                    continue;
                }
                
                // Destination deposit endpoints
                if ($currentSection === 'endpoints' && preg_match('/^    destination_deposit:$/', $line)) {
                    $currentSection = 'destination_deposit';
                    $result[$currentBank]['endpoints']['destination_deposit'] = [];
                    continue;
                }
                
                // Common endpoints
                if ($currentSection === 'endpoints' && preg_match('/^    common:$/', $line)) {
                    $currentSection = 'common';
                    $result[$currentBank]['endpoints']['common'] = [];
                    continue;
                }
                
                // Endpoint key-value pairs
                if (preg_match('/^      ([a-z_]+): "?(.+?)"?$/', $line, $matches)) {
                    $key = $matches[1];
                    $value = rtrim($matches[2], '"');
                    
                    if ($currentSection === 'source') {
                        $result[$currentBank]['endpoints']['source'][$key] = $value;
                    } elseif ($currentSection === 'destination_cashout') {
                        $result[$currentBank]['endpoints']['destination_cashout'][$key] = $value;
                    } elseif ($currentSection === 'destination_deposit') {
                        $result[$currentBank]['endpoints']['destination_deposit'][$key] = $value;
                    } elseif ($currentSection === 'common') {
                        $result[$currentBank]['endpoints']['common'][$key] = $value;
                    }
                    continue;
                }
            }
        }
        
        return $result;
    }
    
    public function getDetectedFormat(): ?string { return $this->detectedFormat; }
    public function getDetectionConfidence(): ?int { return $this->detectionConfidence; }
    public function getDetectionSource(): ?string { return $this->detectionSource; }
    public function getDetectionDetails(): array { return $this->detectionDetails; }

    // ============================================================================
    // BASE URL FROM YAML, ENVIRONMENT, OR CONFIG (UPDATED)
    // ============================================================================

    protected function getBaseUrl(): string
    {
        // Priority 1: YAML config (NEW)
        if ($this->yamlBaseUrl) {
            error_log("Using base URL from YAML: {$this->yamlBaseUrl}");
            return $this->yamlBaseUrl;
        }
        
        // Priority 2: Environment variable
        if ($this->bankPrefix) {
            $envVar = $this->bankPrefix . '_BASE_URL';
            $baseUrl = getenv($envVar);
            if ($baseUrl && !empty($baseUrl)) {
                error_log("Using base URL from env: {$envVar} = {$baseUrl}");
                return rtrim($baseUrl, '/');
            }
        }
        
        // Priority 3: Generic env var
        $genericBaseUrl = getenv('BANK_BASE_URL');
        if ($genericBaseUrl && !empty($genericBaseUrl)) {
            error_log("Using base URL from generic env: BANK_BASE_URL = {$genericBaseUrl}");
            return rtrim($genericBaseUrl, '/');
        }
        
        // Priority 4: Config array
        $configUrl = $this->config['base_url'] ?? '';
        if (!empty($configUrl)) {
            error_log("Using base URL from config: {$configUrl}");
            return rtrim($configUrl, '/');
        }
        
        error_log("WARNING: No base URL found for bank");
        return '';
    }

    protected function getApiKey(): ?string
    {
        if ($this->bankPrefix) {
            $envVar = $this->bankPrefix . '_API_KEY';
            $apiKey = getenv($envVar);
            if ($apiKey && !empty($apiKey)) {
                return $apiKey;
            }
        }
        
        $genericApiKey = getenv('BANK_API_KEY');
        if ($genericApiKey && !empty($genericApiKey)) {
            return $genericApiKey;
        }
        
        if (isset($this->config['security']['api_key']['value'])) {
            return $this->config['security']['api_key']['value'];
        }
        
        return null;
    }

    // ============================================================================
    // GET ENDPOINT - UPDATED WITH YAML SUPPORT
    // ============================================================================

    protected function getEndpoint(string $action): ?string
    {
        // Action to YAML path mapping
        $yamlPathMap = [
            // Source actions
            'verify_asset' => ['source', 'verify_asset'],
            'verifyAsset' => ['source', 'verify_asset'],
            'verifyAssetSigned' => ['source', 'verify_asset'],
            'place_hold' => ['source', 'place_hold'],
            'placeHold' => ['source', 'place_hold'],
            'placeHoldSigned' => ['source', 'place_hold'],
            'debit_funds' => ['source', 'debit_funds'],
            'debitHold' => ['source', 'debit_funds'],
            'release_hold' => ['source', 'release_hold'],
            'releaseHold' => ['source', 'release_hold'],
            
            // Destination cashout actions
            'generate_token' => ['destination_cashout', 'generate_token'],
            'generateToken' => ['destination_cashout', 'generate_token'],
            'generateTokenWithProof' => ['destination_cashout', 'generate_token'],
            'verify_token' => ['destination_cashout', 'verify_token'],
            'verifyToken' => ['destination_cashout', 'verify_token'],
            'confirm_cashout' => ['destination_cashout', 'confirm_cashout'],
            'confirmCashout' => ['destination_cashout', 'confirm_cashout'],
            
            // Destination deposit actions
            'process_deposit' => ['destination_deposit', 'process_deposit'],
            'processDeposit' => ['destination_deposit', 'process_deposit'],
            'processDepositWithProof' => ['destination_deposit', 'process_deposit'],
            'verify_account' => ['destination_deposit', 'verify_account'],
            'verifyAccount' => ['destination_deposit', 'verify_account'],
            
            // Common actions
            'transfer' => ['common', 'transfer'],
            'transferWithProof' => ['common', 'transfer'],
            'reverse' => ['common', 'reverse'],
            'status' => ['common', 'status'],
            'check_status' => ['common', 'status'],
            'reverse_transaction' => ['common', 'reverse'],
            'account_balance' => ['source', 'get_balance'],
            'transactions' => ['source', 'get_transactions'],
        ];
        
        // Priority 1: YAML endpoints (NEW)
        if ($this->yamlEndpoints && isset($yamlPathMap[$action])) {
            [$section, $key] = $yamlPathMap[$action];
            if (isset($this->yamlEndpoints[$section][$key])) {
                $endpoint = $this->yamlEndpoints[$section][$key];
                error_log("Endpoint from YAML for {$action}: {$endpoint}");
                return $endpoint;
            }
        }
        
        // Priority 2: Environment variables (existing)
        $envMap = [
            'verify_asset' => 'VERIFY_ENDPOINT',
            'place_hold' => 'HOLD_ENDPOINT',
            'release_hold' => 'RELEASE_HOLD_ENDPOINT',
            'debit_funds' => 'DEBIT_ENDPOINT',
            'generate_token' => 'GENERATE_TOKEN_ENDPOINT',
            'generate_token_with_proof' => 'GENERATE_TOKEN_ENDPOINT',
            'verify_token' => 'VERIFY_TOKEN_ENDPOINT',
            'confirm_cashout' => 'CONFIRM_CASHOUT_ENDPOINT',
            'process_deposit' => 'PROCESS_DEPOSIT_ENDPOINT',
            'process_deposit_with_proof' => 'PROCESS_DEPOSIT_ENDPOINT',
            'check_status' => 'STATUS_ENDPOINT',
            'reverse_transaction' => 'REVERSE_ENDPOINT',
            'account_balance' => 'BALANCE_ENDPOINT',
            'transactions' => 'TRANSACTIONS_ENDPOINT',
            'transfer_with_proof' => 'TRANSFER_ENDPOINT',
        ];
        
        $actionKey = $envMap[$action] ?? null;
        
        if ($actionKey && $this->bankPrefix) {
            $envVar = $this->bankPrefix . '_' . $actionKey;
            $endpoint = getenv($envVar);
            if ($endpoint && !empty($endpoint)) {
                error_log("Using endpoint from env: {$envVar} = {$endpoint}");
                return $endpoint;
            }
        }
        
        if ($actionKey) {
            $genericVar = 'BANK_' . $actionKey;
            $endpoint = getenv($genericVar);
            if ($endpoint && !empty($endpoint)) {
                error_log("Using endpoint from generic env: {$genericVar} = {$endpoint}");
                return $endpoint;
            }
        }
        
        // Priority 3: Config array (existing)
        if (isset($this->config['endpoints']['source'][$action])) {
            return $this->config['endpoints']['source'][$action];
        }
        
        if (isset($this->config['endpoints']['destination_cashout'][$action])) {
            return $this->config['endpoints']['destination_cashout'][$action];
        }
        
        if (isset($this->config['endpoints']['destination_deposit'][$action])) {
            return $this->config['endpoints']['destination_deposit'][$action];
        }
        
        if (isset($this->config['endpoints']['common'][$action])) {
            return $this->config['endpoints']['common'][$action];
        }
        
        if (isset($this->config['resource_endpoints'][$action])) {
            return $this->config['resource_endpoints'][$action];
        }
        
        error_log("No endpoint found for action: {$action}");
        return null;
    }

    // ============================================================================
    // OAUTH METHODS (unchanged from original)
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
    // HELPER: ADD SOURCE IDENTIFIER TO PAYLOAD
    // ============================================================================

    protected function addSourceIdentifier(array $payload): array
    {
        $sourceIdentifier = $payload['source_identifier'] ?? 
                            $payload['wallet_phone'] ?? 
                            $payload['phone'] ?? 
                            $payload['national_id'] ?? 
                            $payload['email'] ?? null;
        
        if ($sourceIdentifier) {
            $payload['source_identifier'] = $sourceIdentifier;
            $payload['wallet_phone'] = $sourceIdentifier;
            $payload['phone'] = $sourceIdentifier;
            $payload['national_id'] = $sourceIdentifier;
            $payload['email'] = $sourceIdentifier;
            
            error_log("[GenericBankClient] Source identifier added: {$sourceIdentifier}");
        } else {
            error_log("[GenericBankClient] WARNING: No source identifier found in payload");
        }
        
        return $payload;
    }

    // ============================================================================
    // SOURCE ROLE METHODS
    // ============================================================================

    public function verifyAsset(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyAsset ===");
        $payload = $this->addSourceIdentifier($payload);
        return $this->send('verify_asset', $payload, $payload['access_token'] ?? null);
    }

    public function placeHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: placeHold ===");
        $payload = $this->addSourceIdentifier($payload);
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
    // PROTECTED HELPERS - WITH LARGE RESPONSE HANDLING (PRESERVED)
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
        error_log("Payload length: " . strlen(json_encode($payload)));
        
        $jsonPayload = json_encode($payload);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->config['timeout_ms'] ?? 60000,
            CURLOPT_BUFFERSIZE => 262144,
            CURLOPT_MAXFILESIZE => 5242880,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_VERBOSE => false,
            CURLOPT_ENCODING => '',
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 30,
            CURLOPT_TCP_KEEPINTVL => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        
        curl_close($ch);
        
        // Check for response truncation
        if ($contentLength > 0 && strlen($response) < $contentLength) {
            error_log("WARNING: Response truncated! Expected {$contentLength} bytes, got " . strlen($response));
            // Retry with larger buffer
            $ch2 = curl_init($url);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_BUFFERSIZE => 1048576,
                CURLOPT_MAXFILESIZE => 10485760,
                CURLOPT_ENCODING => ''
            ]);
            $response = curl_exec($ch2);
            $curlError = curl_error($ch2);
            curl_close($ch2);
            error_log("Retry response length: " . strlen($response));
        }
        
        error_log("Response HTTP {$httpCode} - Content-Length: {$contentLength}, Actual: " . strlen($response));
        error_log("Response preview: " . substr($response, 0, 500));
        
        if ($curlError) {
            error_log("cURL error: {$curlError}");
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($decodedResponse === null && !empty($response)) {
            error_log("Failed to decode JSON response. Raw response: " . substr($response, 0, 1000));
        }
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'status_code' => $httpCode,
            'data' => $decodedResponse ?? [],
            'raw_response' => $response,
            'curl_error' => $curlError,
            'detected_format' => $this->detectedFormat,
            'response_size' => strlen($response)
        ];
    }

    // ============================================================================
    // SIGNED METHODS FOR BANK-GRADE TRUST (RSA + Certificates)
    // ============================================================================

    protected function createSignedPayload(array $payload, string $requester = 'VOUCHMORPH'): array
    {
        $payload = $this->addSourceIdentifier($payload);
        
        if ($this->certManager && $this->certManager->isConfigured()) {
            error_log("[GenericBankClient] Using CertificateManager for signing ({$requester})");
            return $this->certManager->createSignedRequest($payload, $requester);
        }
        
        if ($this->signer) {
            error_log("[GenericBankClient] Using MessageSigner for signing ({$requester})");
            return $this->signer->createSignedRequest($payload, $requester);
        }
        
        error_log("[GenericBankClient] WARNING: No signing method available - using HMAC fallback");
        $payload['requester'] = $requester;
        $payload['timestamp'] = time();
        $privateKey = getenv('VOUCHMORPH_PRIVATE_KEY');
        if ($privateKey) {
            $payloadJson = json_encode($payload);
            $signature = base64_encode(hash_hmac('sha256', $payloadJson, $privateKey, true));
            $payload['signature'] = $signature;
        }
        return $payload;
    }

    public function verifyAssetSigned(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyAssetSigned ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->send('verify_asset', $signedPayload, $signedPayload['access_token'] ?? null);
    }

    public function placeHoldSigned(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: placeHoldSigned ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->send('place_hold', $signedPayload, $signedPayload['access_token'] ?? null);
    }

    public function transferWithProof(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: transferWithProof ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->send('transfer_with_proof', $signedPayload);
    }

    public function generateTokenWithProof(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: generateTokenWithProof ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->send('generate_token', $signedPayload);
    }

    public function processDepositWithProof(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: processDepositWithProof ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->send('process_deposit', $signedPayload);
    }
    
    protected function buildHeaders(array $payload, ?string $accessToken = null): array
    {
        $headers = ['Content-Type: application/json'];
        
        // Add Accept header for large responses
        $headers[] = 'Accept: application/json';
        $headers[] = 'Accept-Encoding: gzip, deflate';
        
        if ($this->detectedFormat) {
            $headers[] = 'X-Detected-Format: ' . $this->detectedFormat;
        }
        
        if ($accessToken) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }
        
        if (isset($payload['reference'])) {
            $headers[] = 'X-Correlation-ID: ' . $payload['reference'];
        }
        
        $apiKey = $this->getApiKey();
        if ($apiKey) {
            $headerName = $this->config['security']['api_key']['header_name'] ?? 'X-API-Key';
            $headers[] = $headerName . ': ' . $apiKey;
        }
        
        return $headers;
    }
}
