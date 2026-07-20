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
    
    // YAML configuration cache
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
        
        // Load YAML endpoints configuration from country folder
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
        error_log("YAML Base URL: " . ($this->yamlBaseUrl ?? 'NULL'));
        error_log("YAML Endpoints loaded: " . ($this->yamlEndpoints ? 'YES' : 'NO'));
        error_log("Detected Format: {$this->detectedFormat}");
        error_log("CertificateManager: " . ($this->certManager && $this->certManager->isConfigured() ? "ENABLED" : "DISABLED"));
    }
    
    // ============================================================================
    // YAML ENDPOINT LOADING FROM COUNTRY FOLDER
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
        
        error_log("=== YAML PARSING DEBUG ===");
        error_log("Parsed banks: " . implode(', ', array_keys($parsed)));
        
        $bankCode = $this->config['provider_code'] ?? $this->bankPrefix;
        error_log("Looking for bank code: '{$bankCode}'");
        
        // Try exact match first
        if (isset($parsed[$bankCode])) {
            error_log("Exact match found for: {$bankCode}");
            if (isset($parsed[$bankCode]['base_url'])) {
                $this->yamlBaseUrl = rtrim($parsed[$bankCode]['base_url'], '/');
                error_log("YAML base URL for {$bankCode}: {$this->yamlBaseUrl}");
            }
            if (isset($parsed[$bankCode]['endpoints'])) {
                $this->yamlEndpoints = $parsed[$bankCode]['endpoints'];
                error_log("Loaded YAML endpoints for {$bankCode}");
            }
            if (isset($parsed[$bankCode]['source_linking'])) {
                $this->yamlEndpoints['source_linking'] = $parsed[$bankCode]['source_linking'];
                error_log("Loaded source_linking endpoints for {$bankCode}");
            }
        } else {
            // Try case-insensitive match
            error_log("Exact match NOT found, trying case-insensitive...");
            foreach ($parsed as $key => $value) {
                if (strtoupper($key) === strtoupper($bankCode)) {
                    error_log("Found case-insensitive match: {$key} for {$bankCode}");
                    if (isset($value['base_url'])) {
                        $this->yamlBaseUrl = rtrim($value['base_url'], '/');
                        error_log("YAML base URL from case-insensitive match: {$this->yamlBaseUrl}");
                    }
                    if (isset($value['endpoints'])) {
                        $this->yamlEndpoints = $value['endpoints'];
                        error_log("Loaded YAML endpoints from case-insensitive match");
                    }
                    if (isset($value['source_linking'])) {
                        $this->yamlEndpoints['source_linking'] = $value['source_linking'];
                        error_log("Loaded source_linking endpoints from case-insensitive match");
                    }
                    break;
                }
            }
            
            if (!$this->yamlBaseUrl) {
                error_log("Bank code '{$bankCode}' NOT FOUND in parsed YAML");
                error_log("Available banks: " . implode(', ', array_keys($parsed)));
            }
        }
        error_log("=== END YAML PARSING DEBUG ===");
    }
    
    protected function getCountryFromConfig(): string
    {
        if (isset($this->config['country_code'])) {
            return $this->config['country_code'];
        }
        $country = getenv('VOUCHMORPH_COUNTRY');
        if ($country) {
            return $country;
        }
        return 'Botswana';
    }
    
    protected function parseEndpointsYaml(string $content): array
    {
        $result = [];
        $lines = explode("\n", $content);
        $currentBank = null;
        $currentSection = null;
        $currentSubSection = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^([A-Z_]+):$/', $line, $matches)) {
                $currentBank = $matches[1];
                $result[$currentBank] = [];
                $currentSection = null;
                $currentSubSection = null;
                continue;
            }
            
            if ($currentBank) {
                // Base URL
                if (preg_match('/^  base_url: "?(.+?)"?$/', $line, $matches)) {
                    $result[$currentBank]['base_url'] = rtrim($matches[1], '"');
                    continue;
                }
                
                // Source linking section
                if (preg_match('/^  source_linking:$/', $line)) {
                    $currentSection = 'source_linking';
                    $result[$currentBank]['source_linking'] = [];
                    continue;
                }
                
                // Source linking endpoints (4 spaces)
                if ($currentSection === 'source_linking' && preg_match('/^    ([a-z_]+): "?(.+?)"?$/', $line, $matches)) {
                    $key = $matches[1];
                    $value = rtrim($matches[2], '"');
                    $result[$currentBank]['source_linking'][$key] = $value;
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
                    $currentSubSection = 'source';
                    $result[$currentBank]['endpoints']['source'] = [];
                    continue;
                }
                
                // Destination cashout endpoints
                if ($currentSection === 'endpoints' && preg_match('/^    destination_cashout:$/', $line)) {
                    $currentSubSection = 'destination_cashout';
                    $result[$currentBank]['endpoints']['destination_cashout'] = [];
                    continue;
                }
                
                // Destination deposit endpoints
                if ($currentSection === 'endpoints' && preg_match('/^    destination_deposit:$/', $line)) {
                    $currentSubSection = 'destination_deposit';
                    $result[$currentBank]['endpoints']['destination_deposit'] = [];
                    continue;
                }
                
                // Common endpoints
                if ($currentSection === 'endpoints' && preg_match('/^    common:$/', $line)) {
                    $currentSubSection = 'common';
                    $result[$currentBank]['endpoints']['common'] = [];
                    continue;
                }
                
                // Endpoint key-value pairs
                if ($currentSubSection && preg_match('/^      ([a-z_]+): "?(.+?)"?$/', $line, $matches)) {
                    $key = $matches[1];
                    $value = rtrim($matches[2], '"');
                    $result[$currentBank]['endpoints'][$currentSubSection][$key] = $value;
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
    // BASE URL FROM YAML, ENVIRONMENT, OR CONFIG
    // ============================================================================

    protected function getBaseUrl(): string
    {
        if ($this->yamlBaseUrl) {
            error_log("Using base URL from YAML: {$this->yamlBaseUrl}");
            return $this->yamlBaseUrl;
        }
        
        if ($this->bankPrefix) {
            $envVar = $this->bankPrefix . '_BASE_URL';
            $baseUrl = getenv($envVar);
            if ($baseUrl && !empty($baseUrl)) {
                error_log("Using base URL from env: {$envVar} = {$baseUrl}");
                return rtrim($baseUrl, '/');
            }
        }
        
        $genericBaseUrl = getenv('BANK_BASE_URL');
        if ($genericBaseUrl && !empty($genericBaseUrl)) {
            error_log("Using base URL from generic env: BANK_BASE_URL = {$genericBaseUrl}");
            return rtrim($genericBaseUrl, '/');
        }
        
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
        // Check config auth section
        $authConfig = $this->config['auth'] ?? null;
        if ($authConfig && isset($authConfig['secret_source'])) {
            if ($authConfig['secret_source']['type'] === 'env_var') {
                $envName = $authConfig['secret_source']['name'] ?? '';
                if ($envName) {
                    $apiKey = getenv($envName);
                    if ($apiKey && !empty($apiKey)) {
                        return $apiKey;
                    }
                }
            }
        }
        
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
    // GET ENDPOINT - WITH YAML SUPPORT
    // ============================================================================

    protected function getEndpoint(string $action): ?string
    {
        $yamlPathMap = [
            'verify_asset' => ['source', 'verify_asset'],
            'verifyAsset' => ['source', 'verify_asset'],
            'verifyAssetSigned' => ['source', 'verify_asset'],
            'verify_account' => ['destination_deposit', 'verify_account'],
            'verifyAccount' => ['destination_deposit', 'verify_account'],
            'place_hold' => ['source', 'place_hold'],
            'placeHold' => ['source', 'place_hold'],
            'placeHoldSigned' => ['source', 'place_hold'],
            'debit_funds' => ['source', 'debit_funds'],
            'debitHold' => ['source', 'debit_funds'],
            'release_hold' => ['source', 'release_hold'],
            'releaseHold' => ['source', 'release_hold'],
            'get_balance' => ['source', 'get_balance'],         
            'balance' => ['source', 'get_balance'], 
            'generate_token' => ['destination_cashout', 'generate_token'],
            'generateToken' => ['destination_cashout', 'generate_token'],
            'generateTokenWithProof' => ['destination_cashout', 'generate_token'],
            'verify_token' => ['destination_cashout', 'verify_token'],
            'verifyToken' => ['destination_cashout', 'verify_token'],
            'confirm_cashout' => ['destination_cashout', 'confirm_cashout'],
            'confirmCashout' => ['destination_cashout', 'confirm_cashout'],
            'process_deposit' => ['destination_deposit', 'process_deposit'],
            'processDeposit' => ['destination_deposit', 'process_deposit'],
            'processDepositWithProof' => ['destination_deposit', 'process_deposit'],
            'transfer' => ['common', 'transfer'],
            'transferWithProof' => ['common', 'transfer'],
            'reverse' => ['common', 'reverse'],
            'status' => ['common', 'status'],
            'check_status' => ['common', 'status'],
            'reverse_transaction' => ['common', 'reverse'],
            'account_balance' => ['source', 'get_balance'],
            'transactions' => ['source', 'get_transactions'],
        ];
        
        if ($this->yamlEndpoints && isset($yamlPathMap[$action])) {
            [$section, $key] = $yamlPathMap[$action];
            if (isset($this->yamlEndpoints[$section][$key])) {
                $endpoint = $this->yamlEndpoints[$section][$key];
                error_log("Endpoint from YAML for {$action}: {$endpoint}");
                return $endpoint;
            }
        }
        
        $envMap = [
            'verify_asset' => 'VERIFY_ENDPOINT',
            'verify_account' => 'VERIFY_ACCOUNT_ENDPOINT',
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
    // SOURCE LINKING METHODS (Hooking)
    // ============================================================================

    protected function getSourceLinkingEndpoint(string $action): ?string
    {
        if ($this->yamlEndpoints && isset($this->yamlEndpoints['source_linking'][$action])) {
            $endpoint = $this->yamlEndpoints['source_linking'][$action];
            error_log("[GenericBankClient] Source linking endpoint from YAML for {$action}: {$endpoint}");
            return $endpoint;
        }
        
        error_log("[GenericBankClient] No source linking endpoint found for: {$action}");
        return null;
    }

    public function initiateSourceLink(array $params): array
    {
        error_log("[GenericBankClient] initiateSourceLink called");
        
        $endpoint = $this->getSourceLinkingEndpoint('initiate');
        if (!$endpoint) {
            return ['success' => false, 'message' => 'Source linking not configured for this institution'];
        }
        
        $baseUrl = $this->getBaseUrl();
        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        
        // ============================================================
        // Detect OAuth from endpoint path, not just config
        // ============================================================
        $oauthConfig = $this->config['oauth'] ?? null;
        $isOAuthEndpoint = strpos($endpoint, 'oauth') !== false || 
                           strpos($endpoint, 'authorize') !== false;
        
        // Use OAuth if configured OR if the endpoint looks like OAuth
        if ($oauthConfig || $isOAuthEndpoint) {
            error_log("[GenericBankClient] OAuth detected! endpoint={$endpoint}, isOAuthEndpoint=" . ($isOAuthEndpoint ? 'YES' : 'NO'));
            
            // FIX: Safely check oauthConfig before accessing array keys
            $clientId = (is_array($oauthConfig) && !empty($oauthConfig['client_id']))
                ? $oauthConfig['client_id']
                : (getenv('CLIENT_ID') ?: 'VOUCHMORPH_APP_ID');
            $redirectUri = $params['redirect_uri'] ?? $oauthConfig['redirect_uri'] ?? 'https://vouchmorphn-production.up.railway.app/api/v1/agent/oauth_callback.php';
            $state = $params['state'] ?? bin2hex(random_bytes(16));
            $scopes = $params['scope'] ?? $oauthConfig['scopes'] ?? ['read_balance', 'read_transactions', 'payments'];
            
            $authUrl = $url . '?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => implode(' ', $scopes),
                'state' => $state
            ]);
            
            $_SESSION['oauth_state_' . $state] = [
                'user_id' => $params['user_id'] ?? 0,
                'institution' => $this->config['provider_code'] ?? 'unknown',
                'identifier' => $params['identifier'] ?? '',
                'asset_type' => $params['asset_type'] ?? 'ACCOUNT'
            ];
            
            error_log("[GenericBankClient] OAuth redirect URL: " . $authUrl);
            
            return [
                'success' => true,
                'auth_type' => 'oauth',
                'redirect_url' => $authUrl,
                'state' => $state,
                'message' => 'Redirect to bank authorization page'
            ];
        }
        
        // ============================================================
        // Fallback to OTP flow (only if not OAuth)
        // ============================================================
        $authId = $params['auth_id'] ?? 'AUTH_' . date('Ymd') . '_' . bin2hex(random_bytes(6));
        $identifier = $params['identifier'] ?? '';
        $assetType = $params['asset_type'] ?? 'BANK-WALLET';
        
        $payload = [
            'auth_id' => $authId,
            'identifier' => $identifier,
            'asset_type' => $assetType,
            'action' => 'link_source',
            'timestamp' => time()
        ];
        
        $result = $this->sendSourceLinkingRequest('initiate', $payload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Failed to initiate'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'auth_type' => 'otp',
            'auth_id' => $authId,
            'message' => $data['message'] ?? 'OTP sent to your phone',
            'expires_in' => $data['expires_in'] ?? 300,
            'method' => $data['method'] ?? 'sms'
        ];
    }

    public function verifySourceLink(array $params): array
    {
        error_log("[GenericBankClient] verifySourceLink called");
        
        $endpoint = $this->getSourceLinkingEndpoint('verify');
        if (!$endpoint) {
            return ['success' => false, 'message' => 'Source linking not configured for this institution'];
        }
        
        $baseUrl = $this->getBaseUrl();
        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        
        // ============================================================
        // Check if this is an OAuth callback (has 'code' parameter)
        // ============================================================
        $oauthConfig = $this->config['oauth'] ?? null;
        $isOAuthEndpoint = strpos($endpoint, 'oauth') !== false || 
                           strpos($endpoint, 'token') !== false ||
                           strpos($endpoint, 'authorize') !== false;
        
        // ALSO check if the params contain 'code' - that's a strong OAuth indicator
        $hasCode = isset($params['code']) && !empty($params['code']);
        
        // Use OAuth if configured OR if the endpoint looks like OAuth OR if 'code' is present
        if ($oauthConfig || $isOAuthEndpoint || $hasCode) {
            error_log("[GenericBankClient] OAuth verification detected! endpoint={$endpoint}, hasCode=" . ($hasCode ? 'YES' : 'NO'));
            
            // FIX: Safely check oauthConfig before accessing array keys
            $payload = [
                'grant_type' => 'authorization_code',
                'code' => $params['code'],
                'redirect_uri' => $params['redirect_uri'] ?? $oauthConfig['redirect_uri'] ?? 'https://vouchmorphn-production.up.railway.app/api/v1/agent/oauth_callback.php',
                'client_id' => (is_array($oauthConfig) && !empty($oauthConfig['client_id']))
                    ? $oauthConfig['client_id']
                    : (getenv('CLIENT_ID') ?: 'VOUCHMORPH_APP_ID'),
                'client_secret' => (is_array($oauthConfig) && !empty($oauthConfig['client_secret']))
                    ? $oauthConfig['client_secret']
                    : (getenv('CLIENT_SECRET') ?: 'YOUR_BANK_SECRET')
            ];
            
            $result = $this->sendSourceLinkingRequest('verify', $payload, 'application/x-www-form-urlencoded');
            
            if (!$result['success']) {
                return ['success' => false, 'message' => $result['message'] ?? 'Failed to exchange code'];
            }
            
            $data = $result['data'] ?? $result;
            
            return [
                'success' => true,
                'authorized' => true,
                'source_reference' => 'SRC_' . date('Ymd') . '_' . bin2hex(random_bytes(8)),
                'access_token' => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at' => date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600)),
                'token_type' => $data['token_type'] ?? 'Bearer'
            ];
        }
        
        // ============================================================
        // OTP verification path (only if not OAuth)
        // ============================================================
        if (!isset($params['auth_id'])) {
            error_log("[GenericBankClient] OTP verification missing auth_id");
            return ['success' => false, 'message' => 'auth_id required for OTP verification'];
        }
        
        if (!isset($params['otp'])) {
            error_log("[GenericBankClient] OTP verification missing otp");
            return ['success' => false, 'message' => 'otp required for verification'];
        }
        
        $payload = [
            'auth_id' => $params['auth_id'],
            'otp' => $params['otp'],
            'timestamp' => time()
        ];
        
        $result = $this->sendSourceLinkingRequest('verify', $payload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Invalid OTP'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'authorized' => true,
            'source_reference' => $data['source_reference'] ?? 'SRC_' . bin2hex(random_bytes(8)),
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', time() + 3600),
            'holder_name' => $data['holder_name'] ?? null
        ];
    }

    public function refreshSourceToken(array $params): array
    {
        error_log("[GenericBankClient] refreshSourceToken called");
        
        $endpoint = $this->getSourceLinkingEndpoint('refresh');
        if (!$endpoint) {
            return ['success' => false, 'message' => 'Source linking not configured for this institution'];
        }
        
        $baseUrl = $this->getBaseUrl();
        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        
        $oauthConfig = $this->config['oauth'] ?? null;
        $payload = ['refresh_token' => $params['refresh_token']];
        
        if ($oauthConfig) {
            $payload['grant_type'] = 'refresh_token';
            // Already inside if ($oauthConfig) so it's safe
            $payload['client_id'] = $oauthConfig['client_id'] ?: (getenv('CLIENT_ID') ?: 'VOUCHMORPH_APP_ID');
            $payload['client_secret'] = $oauthConfig['client_secret'] ?: (getenv('CLIENT_SECRET') ?: 'YOUR_BANK_SECRET');
        }
        
        $result = $this->sendSourceLinkingRequest('refresh', $payload, 'application/x-www-form-urlencoded');
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Failed to refresh token'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'access_token' => $data['access_token'],
            'expires_at' => date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600))
        ];
    }

    public function revokeSourceToken(array $params): array
    {
        error_log("[GenericBankClient] revokeSourceToken called");
        
        $endpoint = $this->getSourceLinkingEndpoint('revoke');
        if (!$endpoint) {
            return ['success' => false, 'message' => 'Source linking not configured for this institution'];
        }
        
        $baseUrl = $this->getBaseUrl();
        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        
        $oauthConfig = $this->config['oauth'] ?? null;
        $payload = [
            'token' => $params['token'],
            'token_type_hint' => $params['token_type'] ?? 'access_token'
        ];
        
        if ($oauthConfig) {
            // Already inside if ($oauthConfig) so it's safe
            $payload['client_id'] = $oauthConfig['client_id'] ?: (getenv('CLIENT_ID') ?: 'VOUCHMORPH_APP_ID');
            $payload['client_secret'] = $oauthConfig['client_secret'] ?: (getenv('CLIENT_SECRET') ?: 'YOUR_BANK_SECRET');
        }
        
        $result = $this->sendSourceLinkingRequest('revoke', $payload, 'application/x-www-form-urlencoded');
        
        return [
            'success' => $result['success'],
            'message' => $result['message'] ?? 'Token revoked successfully'
        ];
    }

    public function useSourceToken(array $params): array
    {
        error_log("[GenericBankClient] useSourceToken called");
        
        $endpoint = $this->getSourceLinkingEndpoint('use');
        if (!$endpoint) {
            return ['success' => false, 'message' => 'Source linking not configured for this institution'];
        }
        
        $baseUrl = $this->getBaseUrl();
        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        
        $payload = [
            'source_reference' => $params['source_reference'],
            'access_token' => $params['access_token'],
            'action' => 'VERIFY_ASSET',
            'amount' => $params['amount'] ?? 0,
            'currency' => $params['currency'] ?? 'BWP',
            'timestamp' => time()
        ];
        
        $result = $this->sendSourceLinkingRequest('use', $payload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Failed to use source'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'verified' => $data['verified'] ?? true,
            'balance' => $data['balance'] ?? 0,
            'available_balance' => $data['available_balance'] ?? 0,
            'holder_name' => $data['holder_name'] ?? null
        ];
    }

    protected function sendSourceLinkingRequest(string $action, array $payload, string $contentType = 'application/json'): array
    {
        $endpoint = $this->getSourceLinkingEndpoint($action);
        if (!$endpoint) {
            return ['success' => false, 'message' => "Endpoint not found for: {$action}"];
        }
        
        $baseUrl = $this->getBaseUrl();
        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        
        $headers = ['Accept: application/json'];
        $headers[] = 'Content-Type: ' . $contentType;
        
        $apiKey = $this->getApiKey();
        if ($apiKey) {
            $authConfig = $this->config['auth'] ?? [];
            $headerName = $authConfig['header_name'] ?? 'X-API-Key';
            $headers[] = $headerName . ': ' . $apiKey;
        }
        
        if ($contentType === 'application/x-www-form-urlencoded') {
            $postFields = http_build_query($payload);
        } else {
            $postFields = json_encode($payload);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("[GenericBankClient] Request error: " . $error);
            return ['success' => false, 'message' => 'Connection error: ' . $error];
        }
        
        $decoded = json_decode($response, true);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $decoded ?? [],
            'message' => $decoded['message'] ?? ($httpCode < 300 ? 'Success' : 'HTTP ' . $httpCode)
        ];
    }

    // ============================================================================
    // OAUTH METHODS
    // ============================================================================

    public function getAuthorizationUrl(string $redirectUri, string $state, array $scope = []): string
    {
        $oauthConfig = $this->config['oauth'] ?? null;
        if (!$oauthConfig) {
            throw new \RuntimeException("OAuth2 not configured for " . ($this->config['provider_code'] ?? 'unknown'));
        }
        
        $baseUrl = $this->getBaseUrl();
        $authEndpoint = $oauthConfig['authorization_url'] ?? '/oauth/authorize';
        
        $params = [
            'response_type' => 'code',
            'client_id' => $oauthConfig['client_id'] ?? getenv('CLIENT_ID') ?? '',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', $scope ?: $oauthConfig['scopes'] ?? ['read_balance']),
            'code_challenge_method' => 'S256'
        ];
        
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        
        $_SESSION['oauth_code_verifier_' . $state] = $codeVerifier;
        $params['code_challenge'] = $codeChallenge;
        
        return $baseUrl . $authEndpoint . '?' . http_build_query($params);
    }

    public function exchangeCodeForToken(string $code, string $redirectUri): array
    {
        $oauthConfig = $this->config['oauth'] ?? null;
        if (!$oauthConfig) {
            throw new \RuntimeException("OAuth2 not configured");
        }
        
        $baseUrl = $this->getBaseUrl();
        $tokenEndpoint = $oauthConfig['token_url'] ?? '/oauth/token';
        
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $oauthConfig['client_id'] ?? getenv('CLIENT_ID') ?? '',
            'client_secret' => $oauthConfig['client_secret'] ?? getenv('CLIENT_SECRET') ?? ''
        ];
        
        $result = $this->sendSourceLinkingRequest('verify', $payload, 'application/x-www-form-urlencoded');
        
        if (!$result['success']) {
            throw new \RuntimeException("Failed to exchange code for token: " . ($result['message'] ?? 'Unknown error'));
        }
        
        $data = $result['data'] ?? [];
        
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
        $oauthConfig = $this->config['oauth'] ?? null;
        if (!$oauthConfig) {
            throw new \RuntimeException("OAuth2 not configured");
        }
        
        $result = $this->refreshSourceToken(['refresh_token' => $refreshToken]);
        
        if (!$result['success']) {
            throw new \RuntimeException("Failed to refresh token: " . ($result['message'] ?? 'Unknown error'));
        }
        
        return [
            'access_token' => $result['access_token'],
            'expires_in' => 3600
        ];
    }

    public function revokeToken(string $token, string $tokenType = 'access_token'): bool
    {
        $result = $this->revokeSourceToken(['token' => $token, 'token_type' => $tokenType]);
        return $result['success'] ?? false;
    }

    public function getUserInfo(string $accessToken): array
    {
        $oauthConfig = $this->config['oauth'] ?? null;
        $baseUrl = $this->getBaseUrl();
        $userinfoEndpoint = $oauthConfig['userinfo_url'] ?? '/oauth/userinfo';
        
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

    // ============================================================================
    // DEBIT FUNDS WITH CERTIFICATE AND HOLD_REFERENCE
    // ============================================================================

    public function debitFunds(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: debitFunds ===");
        error_log("[GenericBankClient] debitFunds received payload keys: " . implode(', ', array_keys($payload)));
        
        // Extract hold_reference from payload
        $holdRef = $payload['hold_reference'] ?? $payload['reference'] ?? null;
        error_log("[GenericBankClient] debitFunds: hold_reference extracted: " . ($holdRef ?? 'NULL'));
        
        // Create signed payload with certificate
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        
        // Ensure required fields are present
        if (!isset($signedPayload['from_institution']) && isset($payload['from_institution'])) {
            $signedPayload['from_institution'] = $payload['from_institution'];
        }
        if (!isset($signedPayload['source_institution']) && isset($payload['source_institution'])) {
            $signedPayload['source_institution'] = $payload['source_institution'];
        }
        
        // CRITICAL: Ensure hold_reference is in the signed payload
        if ($holdRef) {
            $signedPayload['hold_reference'] = $holdRef;
            $signedPayload['reference'] = $holdRef;
            error_log("[GenericBankClient] debitFunds: Set hold_reference={$holdRef} in signed payload");
        } else {
            error_log("[GenericBankClient] debitFunds: WARNING - No hold_reference found!");
        }
        
        // Add action if not present
        if (!isset($signedPayload['action'])) {
            $signedPayload['action'] = 'DEBIT_FUNDS';
        }
        
        // Ensure amount is present
        if (!isset($signedPayload['amount']) && isset($payload['amount'])) {
            $signedPayload['amount'] = $payload['amount'];
        }
        
        error_log("[GenericBankClient] debitFunds final: from_institution={$signedPayload['from_institution']}, amount={$signedPayload['amount']}, hold_reference={$signedPayload['hold_reference']}");
        error_log("[GenericBankClient] debitFunds signed payload keys: " . implode(', ', array_keys($signedPayload)));
        
        return $this->send('debit_funds', $signedPayload, $signedPayload['access_token'] ?? null);
    }

    /**
     * @deprecated Use debitFunds() directly instead. debitHold() reconstructs
     * the payload and drops fields like wallet_pin, pin, asset_fields.
     * Keeping this for backward compatibility only.
     */
    public function debitHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: debitHold (DEPRECATED - use debitFunds) ===");
        if (!isset($payload['hold_reference'])) {
            error_log("[GenericBankClient] debitHold ERROR: hold_reference is required");
            return ['success' => false, 'message' => 'hold_reference is required', 'data' => []];
        }
        
        $debitPayload = [
            'reference' => $payload['reference'] ?? $payload['hold_reference'],
            'hold_reference' => $payload['hold_reference'],
            'amount' => $payload['amount'] ?? null,
            'reason' => $payload['reason'] ?? 'Debit hold for completed swap',
            'action' => 'DEBIT_HOLD',
            'from_institution' => $payload['from_institution'] ?? $this->bankPrefix,
            'source_institution' => $payload['source_institution'] ?? $this->bankPrefix,
        ];
        
        error_log("[GenericBankClient] debitHold: hold_reference={$debitPayload['hold_reference']}, amount={$debitPayload['amount']}");
        error_log("[GenericBankClient] debitHold WARNING: This reconstructs the payload and drops fields like wallet_pin, pin, asset_fields");
        
        // Pass to debitFunds which handles certificate and hold_reference
        return $this->debitFunds($debitPayload);
    }

    public function getBalance(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: getBalance ===");
        
        $payload = $this->addSourceIdentifier($payload);
        
        // PIN is optional - only include if present
        if (isset($payload['pin']) && !empty($payload['pin'])) {
            $payload['wallet_pin'] = $payload['pin'];
            error_log("[GenericBankClient] PIN found for balance check");
        } elseif (isset($payload['wallet_pin']) && !empty($payload['wallet_pin'])) {
            $payload['pin'] = $payload['wallet_pin'];
            error_log("[GenericBankClient] wallet_pin found for balance check");
        }
        
        $accessToken = $payload['access_token'] ?? null;
        $result = $this->send('get_balance', $payload, $accessToken);
        
        if ($result['success'] && isset($result['data'])) {
            $data = $result['data'];
            
            if (isset($data['data']) && is_array($data['data'])) {
                $result['data'] = $data['data'];
                $balance = $result['data']['balance'] ?? $result['data']['available_balance'] ?? 0;
                error_log("[GenericBankClient] Balance from nested data: {$balance}");
            } elseif (isset($data['balance'])) {
                $balance = $data['balance'];
                error_log("[GenericBankClient] Balance from top-level data: {$balance}");
            } elseif (isset($data['available_balance'])) {
                $balance = $data['available_balance'];
                error_log("[GenericBankClient] Balance from available_balance: {$balance}");
            }
            
            if (!isset($result['data']['balance']) && isset($balance)) {
                $result['data']['balance'] = $balance;
            }
            if (!isset($result['data']['available_balance']) && isset($balance)) {
                $result['data']['available_balance'] = $balance;
            }
        }
        
        return $result;
    }

    // ============================================================================
    // DESTINATION ROLE METHODS - CASHOUT TOKEN
    // ============================================================================

    public function generateToken(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: generateToken (CASHOUT TOKEN) ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->send('generate_token', $signedPayload);
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
    // DESTINATION ROLE METHODS - ACCOUNT VERIFICATION
    // ============================================================================

    /**
     * Verify a destination account exists and is valid
     * FIXED: Now passes through original payload instead of reconstructing
     */
    public function verifyAccount(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyAccount ===");
        error_log("[GenericBankClient] verifyAccount received payload keys: " . implode(', ', array_keys($payload)));
        
        // Extract destination identifier from various possible locations
        $destinationIdentifier = $payload['account_identifier'] ?? 
                                 $payload['destination_identifier'] ?? 
                                 $payload['identifier'] ?? 
                                 $payload['account_number'] ?? 
                                 $payload['phone'] ?? 
                                 $payload['email'] ?? 
                                 $payload['national_id'] ?? null;
        
        $identifierType = $payload['identifier_type'] ?? 
                          $payload['destination_identifier_type'] ?? 
                          'account';
        
        if (!$destinationIdentifier) {
            error_log("[GenericBankClient] No destination identifier found in payload");
            return [
                'verified' => false,
                'success' => false,
                'message' => 'No destination identifier provided. Required: account_identifier, destination_identifier, or identifier'
            ];
        }
        
        error_log("[GenericBankClient] Verifying account: {$destinationIdentifier} (type: {$identifierType})");
        
        // FIXED: Pass through the original payload and only add what's necessary
        $verifyPayload = $payload;
        
        // Ensure required fields are present
        if (!isset($verifyPayload['action'])) {
            $verifyPayload['action'] = 'VERIFY_ACCOUNT';
        }
        if (!isset($verifyPayload['account_identifier'])) {
            $verifyPayload['account_identifier'] = $destinationIdentifier;
        }
        if (!isset($verifyPayload['identifier_type'])) {
            $verifyPayload['identifier_type'] = $identifierType;
        }
        if (!isset($verifyPayload['timestamp'])) {
            $verifyPayload['timestamp'] = time();
        }
        if (!isset($verifyPayload['reference'])) {
            $verifyPayload['reference'] = 'VERIFY_' . bin2hex(random_bytes(6));
        }
        if (!isset($verifyPayload['requester'])) {
            $verifyPayload['requester'] = 'VOUCHMORPH';
        }
        
        // CRITICAL: Preserve destination_asset_type if provided
        if (isset($payload['destination_asset_type']) && !isset($verifyPayload['destination_asset_type'])) {
            $verifyPayload['destination_asset_type'] = $payload['destination_asset_type'];
        }
        if (isset($payload['asset_type']) && !isset($verifyPayload['asset_type'])) {
            $verifyPayload['asset_type'] = $payload['asset_type'];
        }
        
        // Send the verification request
        $result = $this->send('verify_account', $verifyPayload, $payload['access_token'] ?? null);
        
        error_log("[GenericBankClient] verifyAccount response HTTP: " . ($result['status_code'] ?? 'unknown'));
        
        if (!$result['success']) {
            return [
                'verified' => false,
                'success' => false,
                'message' => $result['data']['message'] ?? $result['curl_error'] ?? 'Account verification failed',
                'http_code' => $result['status_code'] ?? 0,
                'data' => $result['data'] ?? []
            ];
        }
        
        $data = $result['data'] ?? [];
        
        // Check if verified from response
        $verified = $data['verified'] ?? $data['success'] ?? true;
        
        return [
            'verified' => $verified,
            'success' => true,
            'message' => $data['message'] ?? 'Account verified successfully',
            'account_name' => $data['account_name'] ?? $data['holder_name'] ?? $data['name'] ?? null,
            'account_type' => $data['account_type'] ?? $data['type'] ?? null,
            'currency' => $data['currency'] ?? null,
            'status' => $data['status'] ?? 'active',
            'account_identifier' => $destinationIdentifier,
            'identifier_type' => $identifierType,
            'data' => $data
        ];
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
            case 'VERIFY_ACCOUNT':
                return $this->verifyAccount($payload);
            default:
                return $this->processDeposit($payload);
        }
    }

    public function reverse(array $payload): array
    {
        return $this->reverseTransaction($payload);
    }

    // ============================================================================
    // PROTECTED HELPERS - WITH LARGE RESPONSE HANDLING
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
        
        if ($contentLength > 0 && strlen($response) < $contentLength) {
            error_log("WARNING: Response truncated! Expected {$contentLength} bytes, got " . strlen($response));
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
        
        // ✅ FIX: Require valid JSON decode, not just HTTP 200
        // This prevents treating malformed/broken responses as success
        return [
            'success' => $httpCode >= 200 && $httpCode < 300 && $decodedResponse !== null,
            'status_code' => $httpCode,
            'data' => $decodedResponse ?? [],
            'raw_response' => $response,
            'curl_error' => $curlError,
            'detected_format' => $this->detectedFormat,
            'response_size' => strlen($response)
        ];
    }

    /**
     * Create a signed payload with proper certificate and signature.
     * FIXED: Preserves ALL fields, especially voucher_number and voucher_pin.
     * FIXED: PIN is OPTIONAL - never required.
     * FIXED: No hardcoded bank names - uses configuration.
     */
    protected function createSignedPayload(array $payload, string $requester = 'VOUCHMORPH'): array
    {
        // ============================================================
        // PRESERVE VOUCHER FIELDS AND DETECT PIN AT THE VERY START
        // ============================================================
        $voucherNumber = $payload['voucher_number'] ?? null;
        $voucherPin = $payload['voucher_pin'] ?? null;
        $sourceIdentifier = $payload['source_identifier'] ?? null;
        
        // Add aliases that institutions might expect (BEFORE any processing)
        // These are common field name variants across different banks
        if ($voucherNumber) {
            $payload['voucherNumber'] = $voucherNumber;
            $payload['voucher_no'] = $voucherNumber;
            $payload['voucherId'] = $voucherNumber;
            error_log("[GenericBankClient] Added voucher_number aliases: $voucherNumber");
        }
        if ($voucherPin) {
            $payload['voucherPin'] = $voucherPin;
            $payload['voucherPIN'] = $voucherPin;
            error_log("[GenericBankClient] Added voucher_pin aliases: $voucherPin");
        }
        
        // ============================================================
        // DETECT PIN (OPTIONAL - NEVER REQUIRED)
        // PIN is only forwarded if present, never required
        // ============================================================
        $pinFound = false;
        
        // Check ALL PIN fields including voucher_pin FIRST
        if (isset($payload['pin']) && !empty($payload['pin'])) {
            $pinFound = true;
            error_log("[GenericBankClient] PIN found at top level 'pin'");
        } elseif (isset($payload['wallet_pin']) && !empty($payload['wallet_pin'])) {
            $payload['pin'] = $payload['wallet_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in 'wallet_pin'");
        } elseif (isset($payload['voucher_pin']) && !empty($payload['voucher_pin'])) {
            // Detect voucher_pin
            $payload['pin'] = $payload['voucher_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in 'voucher_pin': " . substr($payload['voucher_pin'], 0, 2) . '****');
        } elseif (isset($payload['voucherPin']) && !empty($payload['voucherPin'])) {
            $payload['pin'] = $payload['voucherPin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in 'voucherPin'");
        } elseif (isset($payload['voucherPIN']) && !empty($payload['voucherPIN'])) {
            $payload['pin'] = $payload['voucherPIN'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in 'voucherPIN'");
        } elseif (isset($payload['atm_pin']) && !empty($payload['atm_pin'])) {
            $payload['pin'] = $payload['atm_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in 'atm_pin'");
        } elseif (isset($payload['source']['pin']) && !empty($payload['source']['pin'])) {
            $payload['pin'] = $payload['source']['pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in source.pin");
        } elseif (isset($payload['source']['wallet_pin']) && !empty($payload['source']['wallet_pin'])) {
            $payload['pin'] = $payload['source']['wallet_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in source.wallet_pin");
        } elseif (isset($payload['asset_fields']['voucher_pin']) && !empty($payload['asset_fields']['voucher_pin'])) {
            $payload['pin'] = $payload['asset_fields']['voucher_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in asset_fields.voucher_pin");
        } elseif (isset($payload['asset_fields']['wallet_pin']) && !empty($payload['asset_fields']['wallet_pin'])) {
            $payload['pin'] = $payload['asset_fields']['wallet_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in asset_fields.wallet_pin");
        } elseif (isset($payload['asset_fields']['pin']) && !empty($payload['asset_fields']['pin'])) {
            $payload['pin'] = $payload['asset_fields']['pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in asset_fields.pin");
        } elseif (isset($payload['asset_fields']['card_pin']) && !empty($payload['asset_fields']['card_pin'])) {
            $payload['pin'] = $payload['asset_fields']['card_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in asset_fields.card_pin");
        } elseif (isset($payload['asset_fields']['atm_pin']) && !empty($payload['asset_fields']['atm_pin'])) {
            $payload['pin'] = $payload['asset_fields']['atm_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in asset_fields.atm_pin");
        }
        
        // ============================================================
        // PIN IS OPTIONAL - NEVER CHANGE asset_type based on PIN
        // asset_type is preserved as-is (VOUCHER, ACCOUNT, etc.)
        // ============================================================
        // REMOVED: The asset_type clobber that set 'PIN'
        // The following lines have been REMOVED:
        // if ($pinFound) {
        //     $payload['asset_type'] = 'PIN';
        // }
        
        // Log PIN status without modifying asset_type
        if ($pinFound) {
            error_log("[GenericBankClient] PIN found (optional), asset_type remains: " . ($payload['asset_type'] ?? 'not set'));
        } else {
            error_log("[GenericBankClient] No PIN found in payload - using alternative authentication");
        }
        
        // ============================================================
        // Now proceed with normal processing
        // ============================================================
        $payload = $this->addSourceIdentifier($payload);
        
        // ============================================================
        // CRITICAL: RESTORE VOUCHER FIELDS AFTER ALL PROCESSING
        // These MUST be present BEFORE signing
        // ============================================================
        if ($voucherNumber) {
            $payload['voucher_number'] = $voucherNumber;
            $payload['voucherNumber'] = $voucherNumber;
            $payload['voucher_no'] = $voucherNumber;
            $payload['voucherId'] = $voucherNumber;
            error_log("[GenericBankClient] Restored voucher_number: $voucherNumber");
        }
        if ($voucherPin) {
            $payload['voucher_pin'] = $voucherPin;
            $payload['voucherPin'] = $voucherPin;
            $payload['voucherPIN'] = $voucherPin;
            error_log("[GenericBankClient] Restored voucher_pin: " . substr($voucherPin, 0, 2) . '****');
        }
        
        // ============================================================
        // SIGN THE PAYLOAD - ALL FIELDS MUST BE PRESENT BEFORE SIGNING
        // DO NOT MODIFY AFTER SIGNING - THAT BREAKS THE SIGNATURE!
        // ============================================================
        if ($this->certManager && $this->certManager->isConfigured()) {
            error_log("[GenericBankClient] Using CertificateManager for signing ({$requester})");
            $result = $this->certManager->createSignedRequest($payload, $requester);
            $this->assertSigningIntegrity($result, $voucherNumber, $voucherPin, 'CertificateManager');
            return $result;
        }
        
        if ($this->signer) {
            error_log("[GenericBankClient] Using MessageSigner for signing ({$requester})");
            $result = $this->signer->createSignedRequest($payload, $requester);
            $this->assertSigningIntegrity($result, $voucherNumber, $voucherPin, 'MessageSigner');
            return $result;
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

    /**
     * Verify a signer did not silently drop fields that were present in the
     * payload before signing. Deliberately does NOT restore missing fields:
     * mutating a signed result would desynchronize it from what was actually
     * signed - the exact bug the "no modification after signing" rule exists
     * to prevent. Instead, if something critical is missing post-sign, fail
     * loudly here rather than let an incomplete signed payload go out to the
     * bank, or let downstream code treat this as a whole, trustworthy result.
     *
     * @throws \RuntimeException if the signer dropped a required field
     */
    protected function assertSigningIntegrity(
        array $result,
        ?string $voucherNumber,
        ?string $voucherPin,
        string $signerName
    ): void {
        $missing = [];

        if ($voucherNumber && empty($result['voucher_number'])) {
            $missing[] = 'voucher_number';
        }
        if ($voucherPin && empty($result['voucher_pin'])) {
            $missing[] = 'voucher_pin';
        }
        if (empty($result['signature']) && empty($result['certificate'])) {
            $missing[] = 'signature/certificate';
        }

        if (!empty($missing)) {
            $msg = "[GenericBankClient] {$signerName} dropped required field(s) during signing: " . implode(', ', $missing);
            error_log($msg);
            throw new \RuntimeException($msg);
        }
    }

    public function verifyAssetSigned(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyAssetSigned ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->send('verify_asset', $signedPayload, $signedPayload['access_token'] ?? null);
    }

    public function placeHoldSigned(array $payload): array    {
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

    // ============================================================================
    // FIXED: processDepositWithProof - Now passes through original payload
    // ============================================================================

    public function processDepositWithProof(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: processDepositWithProof ===");
        error_log("[GenericBankClient] processDepositWithProof received payload keys: " . implode(', ', array_keys($payload)));
        
        // FIXED: Pass through the original payload instead of reconstructing
        // Use createSignedPayload which preserves all fields
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        
        // Ensure required fields are present
        if (!isset($signedPayload['action'])) {
            $signedPayload['action'] = 'PROCESS_DEPOSIT_WITH_PROOF';
        }
        
        // Ensure reference is present
        if (!isset($signedPayload['reference'])) {
            $signedPayload['reference'] = $payload['reference'] ?? $this->generateReference();
        }
        
        // CRITICAL: Preserve destination_asset_type and asset_type
        if (isset($payload['destination_asset_type']) && !isset($signedPayload['destination_asset_type'])) {
            $signedPayload['destination_asset_type'] = $payload['destination_asset_type'];
        }
        if (isset($payload['asset_type']) && !isset($signedPayload['asset_type'])) {
            $signedPayload['asset_type'] = $payload['asset_type'];
        }
        
        // CRITICAL: Ensure source/destination institutions are preserved
        if (isset($payload['from_institution']) && !isset($signedPayload['from_institution'])) {
            $signedPayload['from_institution'] = $payload['from_institution'];
        }
        if (isset($payload['source_institution']) && !isset($signedPayload['source_institution'])) {
            $signedPayload['source_institution'] = $payload['source_institution'];
        }
        if (isset($payload['to_institution']) && !isset($signedPayload['to_institution'])) {
            $signedPayload['to_institution'] = $payload['to_institution'];
        }
        if (isset($payload['destination_institution']) && !isset($signedPayload['destination_institution'])) {
            $signedPayload['destination_institution'] = $payload['destination_institution'];
        }
        
        // CRITICAL: Preserve verification data
        if (isset($payload['source_verification']) && !isset($signedPayload['source_verification'])) {
            $signedPayload['source_verification'] = $payload['source_verification'];
        }
        if (isset($payload['source_hold']) && !isset($signedPayload['source_hold'])) {
            $signedPayload['source_hold'] = $payload['source_hold'];
        }
        if (isset($payload['account_verification']) && !isset($signedPayload['account_verification'])) {
            $signedPayload['account_verification'] = $payload['account_verification'];
        }
        
        // CRITICAL: Preserve hold_reference
        if (isset($payload['hold_reference']) && !isset($signedPayload['hold_reference'])) {
            $signedPayload['hold_reference'] = $payload['hold_reference'];
        }
        if (isset($payload['_skip_hold']) && !isset($signedPayload['_skip_hold'])) {
            $signedPayload['_skip_hold'] = $payload['_skip_hold'];
        }
        
        error_log("[GenericBankClient] processDepositWithProof final payload keys: " . implode(', ', array_keys($signedPayload)));
        
        return $this->send('process_deposit', $signedPayload);
    }

    /**
     * Generate a reference if not provided
     */
    private function generateReference(): string
    {
        return 'DEP_' . time() . '_' . bin2hex(random_bytes(6));
    }
    
    protected function buildHeaders(array $payload, ?string $accessToken = null): array
    {
        $headers = ['Content-Type: application/json'];
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
            $authConfig = $this->config['auth'] ?? [];
            $headerName = $authConfig['header_name'] ?? 'X-API-Key';
            $headers[] = $headerName . ': ' . $apiKey;
        }
        
        return $headers;
    }
}
