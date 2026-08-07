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
$this->certManager = \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
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
            'transfer_with_proof' => ['common', 'transfer'],   
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
        
        $oauthConfig = $this->config['oauth'] ?? null;
        $isOAuthEndpoint = strpos($endpoint, 'oauth') !== false || 
                           strpos($endpoint, 'authorize') !== false;
        
        if ($oauthConfig || $isOAuthEndpoint) {
            error_log("[GenericBankClient] OAuth detected! endpoint={$endpoint}, isOAuthEndpoint=" . ($isOAuthEndpoint ? 'YES' : 'NO'));
            
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
        
        $oauthConfig = $this->config['oauth'] ?? null;
        $isOAuthEndpoint = strpos($endpoint, 'oauth') !== false || 
                           strpos($endpoint, 'token') !== false ||
                           strpos($endpoint, 'authorize') !== false;
        
        $hasCode = isset($params['code']) && !empty($params['code']);
        
        if ($oauthConfig || $isOAuthEndpoint || $hasCode) {
            error_log("[GenericBankClient] OAuth verification detected! endpoint={$endpoint}, hasCode=" . ($hasCode ? 'YES' : 'NO'));
            
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
            $payload['account_number'] = $sourceIdentifier;  
            
            error_log("[GenericBankClient] Source identifier added: {$sourceIdentifier}");
        } else {
            error_log("[GenericBankClient] WARNING: No source identifier found in payload");
        }
        
        return $payload;
    }

    // ============================================================================
    // SOURCE ROLE METHODS - STANDARDIZED RESPONSES
    // ============================================================================

    public function verifyAsset(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyAsset ===");
        $payload = $this->addSourceIdentifier($payload);
        $result = $this->send('verify_asset', $payload, $payload['access_token'] ?? null);
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => $result['success'] ?? false,
            'verified' => $result['success'] ?? false,
            'data' => $data,
            'message' => $data['message'] ?? ($result['success'] ? 'Asset verified' : 'Verification failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null
        ];
    }

    /**
     * FIX: Ensure certificate and signature are sent to the bank's hold endpoint
     * 
     * The issue was that ZuruBank's hold.php was receiving requests without
     * the certificate, causing "Certificate required - please upgrade to 
     * certificate-based authentication" errors.
     * 
     * This method now ensures that:
     * 1. The payload is properly signed using CertificateManager or MessageSigner
     * 2. The certificate is included in the payload
     * 3. The signature is included in the payload
     */
    public function placeHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: placeHold ===");
        
        // Add source identifier for the source account
        $payload = $this->addSourceIdentifier($payload);
        
        // Generate a reference if not provided
        if (!isset($payload['reference'])) {
            $payload['reference'] = 'HOLD_' . uniqid();
        }
        if (!isset($payload['expiry'])) {
            $payload['expiry'] = date('Y-m-d H:i:s', strtotime('+24 hours'));
        }
        
        // ============================================================
        // FIX: Ensure certificate and signature are included in the payload
        // This is the critical fix for the "Certificate required" error
        // ============================================================
        
        // Check if the payload already has certificate and signature
        $hasCertificate = isset($payload['certificate']) && !empty($payload['certificate']);
        $hasSignature = isset($payload['signature']) && !empty($payload['signature']);
        
        error_log("[GenericBankClient] placeHold: hasCertificate={$hasCertificate}, hasSignature={$hasSignature}");
        
        // If we have a CertificateManager and the payload is not already signed
        if ($this->certManager && $this->certManager->isConfigured()) {
            if (!$hasCertificate || !$hasSignature) {
                error_log("[GenericBankClient] placeHold: Using CertificateManager to sign payload");
                
                // Get the certificate from CertificateManager
                $certificate = $this->certManager->getMyCertificate();
                if ($certificate) {
                    $payload['certificate'] = $certificate;
                    error_log("[GenericBankClient] placeHold: Added certificate (length: " . strlen($certificate) . ")");
                } else {
                    error_log("[GenericBankClient] placeHold: WARNING - No certificate available from CertificateManager");
                }
                
                // Sign the payload
                if (isset($payload['certificate'])) {
                    // Create a signed request using CertificateManager
                    $signedResult = $this->certManager->createSignedRequest($payload, 'VOUCHMORPH');
                    
                    // Merge the signed result back into the payload
                    if (isset($signedResult['signature'])) {
                        $payload['signature'] = $signedResult['signature'];
                        error_log("[GenericBankClient] placeHold: Added signature (length: " . strlen($payload['signature']) . ")");
                    }
                    if (isset($signedResult['certificate'])) {
                        $payload['certificate'] = $signedResult['certificate'];
                    }
                    if (isset($signedResult['requester'])) {
                        $payload['requester'] = $signedResult['requester'];
                    }
                    if (isset($signedResult['timestamp'])) {
                        $payload['timestamp'] = $signedResult['timestamp'];
                    }
                    
                    error_log("[GenericBankClient] placeHold: Payload signed using CertificateManager");
                }
            } else {
                error_log("[GenericBankClient] placeHold: Payload already has certificate and signature, using as-is");
            }
        } elseif ($this->signer && (!$hasCertificate || !$hasSignature)) {
            // Fallback to MessageSigner if CertificateManager is not available
            error_log("[GenericBankClient] placeHold: Using MessageSigner to sign payload");
            
            $signedResult = $this->signer->createSignedRequest($payload, 'VOUCHMORPH');
            
            if (isset($signedResult['signature'])) {
                $payload['signature'] = $signedResult['signature'];
                error_log("[GenericBankClient] placeHold: Added signature (length: " . strlen($payload['signature']) . ")");
            }
            if (isset($signedResult['certificate'])) {
                $payload['certificate'] = $signedResult['certificate'];
            }
            if (isset($signedResult['requester'])) {
                $payload['requester'] = $signedResult['requester'];
            }
            if (isset($signedResult['timestamp'])) {
                $payload['timestamp'] = $signedResult['timestamp'];
            }
        } elseif (!$hasCertificate || !$hasSignature) {
            // If no signing method is available, log a warning
            error_log("[GenericBankClient] placeHold: WARNING - No signing method available, attempting fallback");
            
            // Simple fallback using HMAC if a private key is available
            $privateKey = getenv('VOUCHMORPH_PRIVATE_KEY');
            if ($privateKey) {
                $payload['requester'] = 'VOUCHMORPH';
                $payload['timestamp'] = time();
                $payloadJson = json_encode($payload);
                $signature = base64_encode(hash_hmac('sha256', $payloadJson, $privateKey, true));
                $payload['signature'] = $signature;
                error_log("[GenericBankClient] placeHold: Using HMAC fallback signature");
            } else {
                error_log("[GenericBankClient] placeHold: CRITICAL - No private key available for signing!");
            }
        }
        
        // Log what we're sending
        error_log("[GenericBankClient] placeHold: Sending hold request with certificate=" . 
                  (isset($payload['certificate']) ? 'YES (length: ' . strlen($payload['certificate']) . ')' : 'NO'));
        error_log("[GenericBankClient] placeHold: Sending hold request with signature=" . 
                  (isset($payload['signature']) ? 'YES (length: ' . strlen($payload['signature']) . ')' : 'NO'));
        
        // Send the request
        $result = $this->send('place_hold', $payload, $payload['access_token'] ?? null);
        
        $data = $result['data'] ?? [];
        
        // If the hold failed due to certificate issues, log it clearly
        if (!$result['success'] && isset($data['message']) && 
            strpos($data['message'], 'Certificate required') !== false) {
            error_log("[GenericBankClient] placeHold: ❌ HOLD FAILED - Certificate required but not sent or invalid");
            error_log("[GenericBankClient] placeHold: Payload certificate key exists: " . 
                      (isset($payload['certificate']) ? 'YES' : 'NO'));
            error_log("[GenericBankClient] placeHold: Payload signature key exists: " . 
                      (isset($payload['signature']) ? 'YES' : 'NO'));
        }
        
        return [
            'success' => $result['success'] ?? false,
            'hold_placed' => $result['success'] ?? false,
            'hold_reference' => $data['hold_reference'] ?? $data['reference'] ?? null,
            'hold_id' => $data['hold_id'] ?? null,
            'status' => $data['status'] ?? 'ACTIVE',
            'data' => $data,
            'message' => $data['message'] ?? ($result['success'] ? 'Hold placed' : 'Hold failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'signature' => $data['signature'] ?? $payload['signature'] ?? null,
            'certificate' => $data['certificate'] ?? $payload['certificate'] ?? null,
            'timestamp' => $data['timestamp'] ?? time()
        ];
    }

    public function releaseHold(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: releaseHold ===");
        
        if (!isset($payload['reference'])) {
            $payload['reference'] = 'RELEASE_' . uniqid();
        }
        if (!isset($payload['action'])) {
            $payload['action'] = 'RELEASE_HOLD';
        }
        
        // Ensure certificate and signature are included for release hold too
        if ($this->certManager && $this->certManager->isConfigured()) {
            if (!isset($payload['certificate']) || !isset($payload['signature'])) {
                $signedResult = $this->certManager->createSignedRequest($payload, 'VOUCHMORPH');
                if (isset($signedResult['certificate'])) {
                    $payload['certificate'] = $signedResult['certificate'];
                }
                if (isset($signedResult['signature'])) {
                    $payload['signature'] = $signedResult['signature'];
                }
                if (isset($signedResult['requester'])) {
                    $payload['requester'] = $signedResult['requester'];
                }
                if (isset($signedResult['timestamp'])) {
                    $payload['timestamp'] = $signedResult['timestamp'];
                }
            }
        }
        
        $result = $this->send('release_hold', $payload);
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => $result['success'] ?? false,
            'released' => $result['success'] ?? false,
            'hold_reference' => $payload['hold_reference'] ?? null,
            'status' => $data['status'] ?? 'RELEASED',
            'data' => $data,
            'message' => $data['message'] ?? ($result['success'] ? 'Hold released' : 'Release failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'released_at' => $data['released_at'] ?? date('Y-m-d H:i:s')
        ];
    }

    // ============================================================================
    // DEBIT FUNDS - STANDARDIZED
    // ============================================================================

   public function debitFunds(array $payload): array
{
    error_log("=== GENERIC BANK CLIENT: debitFunds ===");
    error_log("[GenericBankClient] debitFunds received payload keys: " . implode(', ', array_keys($payload)));
    
    // debitFunds is always a source-directed call (debiting the ORIGIN
    // account/wallet), so it's safe and correct to backfill phone/
    // wallet_phone/national_id/email from source_identifier here.
    $payload = $this->addSourceIdentifier($payload);
    
    $holdRef = $payload['hold_reference'] ?? $payload['reference'] ?? null;
    error_log("[GenericBankClient] debitFunds: hold_reference extracted: " . ($holdRef ?? 'NULL'));
    
    if ($holdRef) {
        $payload['hold_reference'] = $holdRef;
        $payload['reference'] = $holdRef;
    }
    
    if (!isset($payload['from_institution'])) {
        $payload['from_institution'] = $payload['source_institution'] ?? $this->bankPrefix;
    }
    if (!isset($payload['source_institution'])) {
        $payload['source_institution'] = $payload['from_institution'] ?? $this->bankPrefix;
    }
    if (!isset($payload['action'])) {
        $payload['action'] = 'DEBIT_FUNDS';
    }
    if (!isset($payload['reference']) || empty($payload['reference'])) {
        $payload['reference'] = 'DEBIT_' . uniqid();
    }
    
    // Ensure certificate and signature are included for debit
    if ($this->certManager && $this->certManager->isConfigured()) {
        if (!isset($payload['certificate']) || !isset($payload['signature'])) {
            $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
            $payload = array_merge($payload, $signedPayload);
        }
    } else {
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        $payload = array_merge($payload, $signedPayload);
    }
    
    error_log("[GenericBankClient] debitFunds final: from_institution={$payload['from_institution']}, amount={$payload['amount']}, hold_reference={$payload['hold_reference']}");
    
    $result = $this->send('debit_funds', $payload, $payload['access_token'] ?? null);
    
    $data = $result['data'] ?? [];
    
    // FIX: ZuruBank's notify_debit.php nests transaction_reference one level
    // deeper under "data" instead of flattening it like hold.php/credit_funds.php do.
    if (isset($data['data']) && is_array($data['data'])) {
        $data = array_merge($data, $data['data']);
    }
    
    return [
        'success' => $result['success'] ?? false,
        'debited' => $result['success'] ?? false,
        'transaction_reference' => $data['transaction_reference'] ?? $data['reference'] ?? null,
        'status' => $data['status'] ?? 'COMPLETED',
        'data' => $data,
        'message' => $data['message'] ?? ($result['success'] ? 'Debit successful' : 'Debit failed'),
        'status_code' => $result['status_code'] ?? 0,
        'curl_error' => $result['curl_error'] ?? null,
        'raw_response' => $result['raw_response'] ?? null
    ];
}
    public function getBalance(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: getBalance ===");
        
        $payload = $this->addSourceIdentifier($payload);
        
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
    // DESTINATION METHODS - STANDARDIZED
    // ============================================================================

    public function generateToken(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: generateToken (CASHOUT TOKEN) ===");
        // FIX: Avoid double-signing. If this payload was already signed
        // upstream (e.g. by generateTokenWithProof(), which calls
        // createSignedPayload() before handing off to this method),
        // re-signing here would treat the existing signature/certificate
        // as ordinary data fields, sign over them, and then overwrite
        // them with a brand-new signature — producing a request whose
        // signature can never be reconstructed/verified by the receiver.
        if (!empty($payload['signature']) && !empty($payload['certificate'])) {
            error_log("[GenericBankClient] generateToken: payload already signed upstream, skipping re-sign");
            $signedPayload = $payload;
        } else {
            $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        }
        $result = $this->send('generate_token', $signedPayload);
        
        $data = $result['data'] ?? [];
        
        // FIX: Some destination banks (e.g. SACCUSSALIS) return their
        // transaction identifier as 'sat_number' rather than any of
        // 'cashout_code' / 'code' / 'voucher_number' / 'swap_code'.
        // Without this fallback, the code is silently lost — the bank
        // genuinely generated a valid token, but cashout_code,
        // voucher_number, and swap_code all resolve to null here, and
        // that null gets persisted into cashout_authorizations, so the
        // client-facing swap code never displays even though the PIN
        // (atm_pin) does, since that field was already mapped correctly.
        return [
            'success' => $result['success'] ?? false,
            'cashout_code' => $data['cashout_code'] ?? $data['code'] ?? $data['sat_number'] ?? null,
            'atm_pin' => $data['atm_pin'] ?? $data['pin'] ?? null,
            'voucher_number' => $data['voucher_number'] ?? $data['sat_number'] ?? null,
            'swap_code' => $data['swap_code'] ?? $data['voucher_number'] ?? $data['sat_number'] ?? null,
            'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours')),
            'transaction_reference' => $data['transaction_reference'] ?? $data['sat_number'] ?? null,
            'data' => $data,
            'message' => $data['message'] ?? ($result['success'] ? 'Token generated' : 'Token generation failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null
        ];
    }

    public function verifyToken(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyToken ===");
        
        if (!isset($payload['reference'])) {
            $payload['reference'] = 'VERIFY_TOKEN_' . uniqid();
        }
        
        $result = $this->send('verify_token', $payload);
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => $result['success'] ?? false,
            'verified' => $data['verified'] ?? $result['success'] ?? false,
            'amount' => $data['amount'] ?? null,
            'beneficiary' => $data['beneficiary'] ?? null,
            'data' => $data,
            'message' => $data['message'] ?? ($result['success'] ? 'Token verified' : 'Verification failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null
        ];
    }

    public function confirmCashout(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: confirmCashout ===");
        
        if (!isset($payload['completed_at'])) {
            $payload['completed_at'] = date('Y-m-d H:i:s');
        }
        if (!isset($payload['action'])) {
            $payload['action'] = 'CONFIRM_CASHOUT';
        }
        
        $result = $this->send('confirm_cashout', $payload);
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => $result['success'] ?? false,
            'confirmed' => $data['confirmed'] ?? $result['success'] ?? false,
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'settlement_triggered' => $data['settlement_triggered'] ?? false,
            'data' => $data,
            'message' => $data['message'] ?? ($result['success'] ? 'Cashout confirmed' : 'Confirmation failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null
        ];
    }

    public function processDeposit(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: processDeposit ===");
        
        if (!isset($payload['reference'])) {
            $payload['reference'] = 'DEPOSIT_' . uniqid();
        }
        if (!isset($payload['action'])) {
            $payload['action'] = 'PROCESS_DEPOSIT';
        }
        
        $destinationAssetType = $payload['destination_asset_type'] ?? $payload['asset_type'] ?? 'ACCOUNT';
        $payload['destination_asset_type'] = $destinationAssetType;
        $payload['asset_type'] = $destinationAssetType;
        
        $result = $this->send('process_deposit', $payload, $payload['access_token'] ?? null);
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => $result['success'] ?? false,
            'processed' => $result['success'] ?? false,
            'credited' => $result['success'] ?? false,
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'status' => $data['status'] ?? 'COMPLETED',
            'new_balance' => $data['new_balance'] ?? null,
            'data' => $data,
            'message' => $data['message'] ?? ($result['success'] ? 'Deposit processed' : 'Deposit failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null
        ];
    }

    // ============================================================================
    // ACCOUNT VERIFICATION - STANDARDIZED
    // ============================================================================

    public function verifyAccount(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyAccount ===");
        error_log("[GenericBankClient] verifyAccount received payload keys: " . implode(', ', array_keys($payload)));
        
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
                'success' => false,
                'verified' => false,
                'message' => 'No destination identifier provided. Required: account_identifier, destination_identifier, or identifier',
                'account_identifier' => null,
                'identifier_type' => $identifierType
            ];
        }
        
        error_log("[GenericBankClient] Verifying account: {$destinationIdentifier} (type: {$identifierType})");
        
        $verifyPayload = $payload;
        
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
        
        if (isset($payload['destination_asset_type']) && !isset($verifyPayload['destination_asset_type'])) {
            $verifyPayload['destination_asset_type'] = $payload['destination_asset_type'];
        }
        if (isset($payload['asset_type']) && !isset($verifyPayload['asset_type'])) {
            $verifyPayload['asset_type'] = $payload['asset_type'];
        }
        
        $result = $this->send('verify_account', $verifyPayload, $payload['access_token'] ?? null);
        
        error_log("[GenericBankClient] verifyAccount response HTTP: " . ($result['status_code'] ?? 'unknown'));
        
        $data = $result['data'] ?? [];
        
        if (!$result['success']) {
            return [
                'success' => false,
                'verified' => false,
                'message' => $data['message'] ?? $result['curl_error'] ?? 'Account verification failed',
                'account_identifier' => $destinationIdentifier,
                'identifier_type' => $identifierType,
                'status_code' => $result['status_code'] ?? 0,
                'data' => $data,
                'curl_error' => $result['curl_error'] ?? null,
                'raw_response' => $result['raw_response'] ?? null
            ];
        }
        
        return [
            'success' => true,
            'verified' => $data['verified'] ?? $data['success'] ?? true,
            'message' => $data['message'] ?? 'Account verified successfully',
            'account_name' => $data['account_name'] ?? $data['holder_name'] ?? $data['name'] ?? null,
            'account_type' => $data['account_type'] ?? $data['type'] ?? null,
            'currency' => $data['currency'] ?? null,
            'status' => $data['status'] ?? 'active',
            'account_identifier' => $destinationIdentifier,
            'identifier_type' => $identifierType,
            'data' => $data,
            'status_code' => $result['status_code'] ?? 0,
            'raw_response' => $result['raw_response'] ?? null
        ];
    }

    // ============================================================================
    // SIGNED METHODS - STANDARDIZED
    // ============================================================================

    public function verifyAssetSigned(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: verifyAssetSigned ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->verifyAsset($signedPayload);
    }

   public function placeHoldSigned(array $payload): array
{
    error_log("=== GENERIC BANK CLIENT: placeHoldSigned ===");
    $payload = $this->addSourceIdentifier($payload);        // ← moved here, before signing
    $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
    return $this->placeHold($signedPayload);
}

    public function processDepositWithProof(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: processDepositWithProof ===");
        error_log("[GenericBankClient] processDepositWithProof received payload keys: " . implode(', ', array_keys($payload)));
        
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        
        if (!isset($signedPayload['action'])) {
            $signedPayload['action'] = 'PROCESS_DEPOSIT_WITH_PROOF';
        }
        if (!isset($signedPayload['reference'])) {
            $signedPayload['reference'] = $payload['reference'] ?? $this->generateReference();
        }
        
        if (isset($payload['destination_asset_type']) && !isset($signedPayload['destination_asset_type'])) {
            $signedPayload['destination_asset_type'] = $payload['destination_asset_type'];
        }
        if (isset($payload['asset_type']) && !isset($signedPayload['asset_type'])) {
            $signedPayload['asset_type'] = $payload['asset_type'];
        }
        
        $institutionFields = ['from_institution', 'source_institution', 'to_institution', 'destination_institution'];
        foreach ($institutionFields as $field) {
            if (isset($payload[$field]) && !isset($signedPayload[$field])) {
                $signedPayload[$field] = $payload[$field];
            }
        }
        
        $verificationFields = ['source_verification', 'source_hold', 'account_verification'];
        foreach ($verificationFields as $field) {
            if (isset($payload[$field]) && !isset($signedPayload[$field])) {
                $signedPayload[$field] = $payload[$field];
            }
        }
        
        if (isset($payload['hold_reference']) && !isset($signedPayload['hold_reference'])) {
            $signedPayload['hold_reference'] = $payload['hold_reference'];
        }
        if (isset($payload['_skip_hold']) && !isset($signedPayload['_skip_hold'])) {
            $signedPayload['_skip_hold'] = $payload['_skip_hold'];
        }
        
        error_log("[GenericBankClient] processDepositWithProof final payload keys: " . implode(', ', array_keys($signedPayload)));
        
        return $this->processDeposit($signedPayload);
    }

    public function generateTokenWithProof(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: generateTokenWithProof ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->generateToken($signedPayload);
    }

    public function transferWithProof(array $payload): array
    {
        error_log("=== GENERIC BANK CLIENT: transferWithProof ===");
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');
        return $this->send('transfer_with_proof', $signedPayload);
    }

    // ============================================================================
    // COMMON METHODS
    // ============================================================================

    public function checkStatus(string $reference): array
    {
        error_log("=== GENERIC BANK CLIENT: checkStatus ===");
        return $this->send('check_status', ['reference' => $reference]);
    }
    
    public function checkSettlementStatus(array $payload): array
{
    error_log("=== GENERIC BANK CLIENT: checkSettlementStatus ===");
    $result = $this->send('checkSettlementStatus', $payload);

    $data = $result['data'] ?? [];

    return [
        'success' => $result['success'] ?? false,
        'settled' => $data['settled'] ?? false,
        'settlement_reference' => $data['settlement_reference'] ?? null,
        'message' => $data['message'] ?? ($result['success'] ? 'Checked' : 'Check failed'),
        'status_code' => $result['status_code'] ?? 0,
        'data' => $data,
    ];
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
                return $this->debitFunds($payload);
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

    /**
     * @deprecated Use debitFunds() directly instead.
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
        
        return $this->debitFunds($debitPayload);
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
        
        // Debug: Log whether certificate and signature are in the payload
        error_log("[GenericBankClient] send({$action}): Payload has certificate: " . (isset($payload['certificate']) ? 'YES' : 'NO'));
        error_log("[GenericBankClient] send({$action}): Payload has signature: " . (isset($payload['signature']) ? 'YES' : 'NO'));
        if (isset($payload['certificate'])) {
            error_log("[GenericBankClient] send({$action}): Certificate length: " . strlen($payload['certificate']));
        }
        if (isset($payload['signature'])) {
            error_log("[GenericBankClient] send({$action}): Signature length: " . strlen($payload['signature']));
        }
        
        $headers = $this->buildHeaders($payload, $accessToken);
        
        error_log("Sending request to: {$url}");
        error_log("Payload length: " . strlen(json_encode($payload)));
        
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);        
        
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
        
        $bodySuccessFlag = null;
        if (is_array($decodedResponse)) {
            if (array_key_exists('success', $decodedResponse)) {
                $bodySuccessFlag = (bool)$decodedResponse['success'];
            } elseif (array_key_exists('status', $decodedResponse)) {
                $bodySuccessFlag = strtoupper((string)$decodedResponse['status']) === 'SUCCESS';
            } elseif (array_key_exists('hold_placed', $decodedResponse) && $action === 'place_hold') {
                $bodySuccessFlag = (bool)$decodedResponse['hold_placed'];
            }
        }

        $httpOk = $httpCode >= 200 && $httpCode < 300 && $decodedResponse !== null;
        $overallSuccess = $httpOk && ($bodySuccessFlag === null ? true : $bodySuccessFlag);

        if ($httpOk && $bodySuccessFlag === false) {
            error_log("send({$action}): HTTP {$httpCode} but response body reports success=false - treating as FAILURE. Body: " . substr($response, 0, 300));
        }

        return [
            'success' => $overallSuccess,
            'status_code' => $httpCode,
            'data' => $decodedResponse ?? [],
            'raw_response' => $response,
            'curl_error' => $curlError,
            'detected_format' => $this->detectedFormat,
            'response_size' => strlen($response)
        ];
    }

    // ============================================================================
    // FIXED: createSignedPayload() - Removed unconditional addSourceIdentifier()
    // ============================================================================
    protected function createSignedPayload(array $payload, string $requester = 'VOUCHMORPH'): array
    {
        $voucherNumber = $payload['voucher_number'] ?? null;
        $voucherPin = $payload['voucher_pin'] ?? null;
        
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
        
        $pinFound = false;
        
        if (isset($payload['pin']) && !empty($payload['pin'])) {
            $pinFound = true;
            error_log("[GenericBankClient] PIN found at top level 'pin'");
        } elseif (isset($payload['wallet_pin']) && !empty($payload['wallet_pin'])) {
            $payload['pin'] = $payload['wallet_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in 'wallet_pin'");
        } elseif (isset($payload['voucher_pin']) && !empty($payload['voucher_pin'])) {
            $payload['pin'] = $payload['voucher_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in 'voucher_pin'");
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
        } elseif (isset($payload['asset_fields']['atm_pin']) && !empty($payload['asset_fields']['atm_pin'])) {
            $payload['pin'] = $payload['asset_fields']['atm_pin'];
            $pinFound = true;
            error_log("[GenericBankClient] PIN found in asset_fields.atm_pin");
        }
        
        if ($pinFound) {
            error_log("[GenericBankClient] PIN found (optional), asset_type remains: " . ($payload['asset_type'] ?? 'not set'));
        } else {
            error_log("[GenericBankClient] No PIN found in payload - using alternative authentication");
        }
        
        // ============================================================
        // FIX: REMOVED the unconditional addSourceIdentifier() call
        // that used to sit here. createSignedPayload() is shared by BOTH:
        //
        //   - source-directed calls (verifyAssetSigned, placeHoldSigned)
        //     - where phone/wallet_phone/national_id/email correctly mean
        //       "the source account's own identifier", and
        //
        //   - destination-directed calls (processDepositWithProof,
        //     generateTokenWithProof, transferWithProof)
        //     - where SwapService::processDepositWithProof() deliberately
        //       sets phone/wallet_phone to the DESTINATION wallet's phone
        //       number.
        //
        // addSourceIdentifier() unconditionally overwrote wallet_phone/
        // phone/national_id/email with the SOURCE identifier regardless of
        // which of these two cases applied. For every ACCOUNT -> WALLET
        // deposit between institutions, this meant the destination bank's
        // credit() call received the SOURCE account number in the phone
        // field instead of the real destination wallet number.
        //
        // addSourceIdentifier() is now called explicitly only by the
        // source-role methods that need it - see debitFunds(), verifyAsset(),
        // and placeHold() above/below.
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

    private function generateReference(): string
    {
        return 'DEP_' . time() . '_' . bin2hex(random_bytes(6));
    }
}
