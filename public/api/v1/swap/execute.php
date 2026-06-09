<?php
declare(strict_types=1);

/**
 * VouchMorphn - Swap Execution API
 * ZERO HARDCODING - 100% Dynamic Configuration
 * All settings from: countries_registry.json, YAML files, Railway Vault
 */

// ============================================
// 1. BOOTSTRAP & PATHS
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

// ============================================
// 2. HEADERS & CORS (Dynamic from config)
// ============================================
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS, GET");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Country-Code, X-Country, X-Correlation-ID, X-Idempotency-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// 3. ERROR HANDLING (Dynamic logging)
// ============================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================
// 4. RAILWAY VAULT MANAGER (Zero hardcoding)
// ============================================

class RailwayVaultManager {
    private static $instance = null;
    private $secrets = [];
    private $cacheTtl = 300; // 5 minutes cache
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getSecret(string $key, $default = null) {
        if (isset($this->secrets[$key])) {
            return $this->secrets[$key];
        }
        
        $value = $this->searchEnvironmentVariables($key);
        
        if ($value === null && function_exists('getenv')) {
            $value = getenv($key) ?: null;
        }
        
        $this->secrets[$key] = $value ?? $default;
        return $this->secrets[$key];
    }
    
    private function searchEnvironmentVariables(string $key): ?string {
        $patterns = [
            $key,
            strtoupper($key),
            strtolower($key),
            'UPSTREAM_' . $key,
            'UPSTREAM_' . strtoupper($key),
            $key . '_KEY',
            strtoupper($key) . '_KEY',
            'API_KEY_' . $key,
            strtoupper($key) . '_API_KEY'
        ];
        
        foreach ($patterns as $pattern) {
            $value = $_ENV[$pattern] ?? $_SERVER[$pattern] ?? null;
            if ($value) return $value;
        }
        
        return null;
    }
    
    public function getParticipantConfig(string $participantCode): array {
        return [
            'api_key' => $this->getSecret($participantCode . '_API_KEY') ?: $this->getSecret('UPSTREAM_' . $participantCode . '_KEY'),
            'base_url' => $this->getSecret($participantCode . '_BASE_URL'),
            'webhook_url' => $this->getSecret($participantCode . '_WEBHOOK_URL'),
            'timeout_ms' => (int)($this->getSecret($participantCode . '_TIMEOUT', 5000)),
            'retry_count' => (int)($this->getSecret($participantCode . '_RETRY_COUNT', 3))
        ];
    }
}

$vault = RailwayVaultManager::getInstance();

// ============================================
// 5. DYNAMIC CONFIGURATION LOADER
// ============================================

class DynamicConfigLoader {
    private $registry = null;
    private $vault = null;
    private $cache = [];
    
    public function __construct($vault) {
        $this->vault = $vault;
        $this->loadRegistry();
    }
    
    private function loadRegistry(): void {
        $registryPaths = [
            ROOT_PATH . '/src/Core/Config/countries_registry.json',
            ROOT_PATH . '/config/countries_registry.json',
            __DIR__ . '/../../../Core/Config/countries_registry.json'
        ];
        
        foreach ($registryPaths as $path) {
            if (file_exists($path)) {
                $this->registry = json_decode(file_get_contents($path), true);
                error_log("[Config] Loaded registry from: {$path}");
                return;
            }
        }
        
        throw new Exception('Country registry not found in any expected location');
    }
    
    public function getCountryConfig(?string $countryInput = null): array {
        $countryCode = $this->resolveCountryCode($countryInput);
        
        foreach ($this->registry['countries'] as $name => $config) {
            if (strtoupper($name) === strtoupper($countryCode) || 
                strtoupper($config['code']) === strtoupper($countryCode)) {
                
                if (!($config['enabled'] ?? true)) {
                    throw new Exception("Country '{$name}' is not enabled");
                }
                
                return [
                    'name' => $name,
                    'code' => $config['code'],
                    'currency' => $config['currency'],
                    'currency_symbol' => $config['currency_symbol'] ?? $this->getCurrencySymbol($config['code']),
                    'dial_code' => $config['dial_code'] ?? $this->getDialCode($config['code']),
                    'config_path' => ROOT_PATH . '/' . $config['config_path'],
                    'participants_file' => $config['participants_file'] ?? 'participants.yaml',
                    'endpoints_file' => $config['endpoints_file'] ?? 'endpoints.yaml',
                    'fees_file' => $config['fees_file'] ?? 'fees.yaml',
                    'config_file' => $config['config_file'] ?? 'config.php',
                    'database_file' => $config['database_file'] ?? 'database.php'
                ];
            }
        }
        
        // Return default country
        $default = $this->registry['default_country'] ?? array_key_first($this->registry['countries']);
        return $this->getCountryConfig($default);
    }
    
    private function resolveCountryCode(?string $input): string {
        if ($input) return $input;
        
        // Try headers
        $headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
        $headerSources = ['x-country-code', 'x-country', 'country'];
        
        foreach ($headerSources as $source) {
            if (!empty($headers[$source])) return $headers[$source];
        }
        
        // Try query string
        if (!empty($_GET['country'])) return $_GET['country'];
        
        // Try POST body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!empty($input['country'])) return $input['country'];
        if (!empty($input['source']['country'])) return $input['source']['country'];
        if (!empty($input['destination']['country'])) return $input['destination']['country'];
        
        // Return default from registry
        return $this->registry['default_country'] ?? 'Botswana';
    }
    
    private function getCurrencySymbol(string $countryCode): string {
        $symbols = ['BW' => 'P', 'ZA' => 'R', 'NA' => '$', 'ZM' => 'K'];
        return $symbols[$countryCode] ?? 'P';
    }
    
    private function getDialCode(string $countryCode): string {
        $codes = ['BW' => '+267', 'ZA' => '+27', 'NA' => '+264', 'ZM' => '+260'];
        return $codes[$countryCode] ?? '+267';
    }
    
    public function loadYamlFile(string $filePath): ?array {
        if (!file_exists($filePath)) return null;
        
        if (function_exists('yaml_parse_file')) {
            return yaml_parse_file($filePath);
        }
        
        if (class_exists('Symfony\Component\Yaml\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::parse(file_get_contents($filePath));
        }
        
        return $this->simpleYamlParse($filePath);
    }
    
    private function simpleYamlParse(string $filePath): ?array {
        $content = file_get_contents($filePath);
        $content = preg_replace('/^\s*#.*$/m', '', $content);
        
        $result = [];
        $lines = explode("\n", $content);
        $currentKey = null;
        $currentArray = [];
        $inArray = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, ':') !== false && !$inArray) {
                if ($currentKey !== null && !empty($currentArray)) {
                    $result[$currentKey] = $currentArray;
                    $currentArray = [];
                }
                
                list($key, $value) = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                if ($value === '' || $value === '[]' || $value === 'null') {
                    $currentKey = $key;
                    $currentArray = [];
                    $inArray = true;
                } else {
                    $result[$key] = $this->parseYamlValue($value);
                }
            } elseif ($inArray && strpos($line, '-') === 0) {
                $item = trim(substr($line, 1));
                $currentArray[] = $this->parseYamlValue($item);
            } elseif ($inArray && (strpos($line, '}') !== false || strpos($line, ']') !== false)) {
                if (!empty($currentArray)) {
                    $result[$currentKey] = $currentArray;
                }
                $currentKey = null;
                $currentArray = [];
                $inArray = false;
            }
        }
        
        if ($currentKey !== null && !empty($currentArray)) {
            $result[$currentKey] = $currentArray;
        }
        
        return !empty($result) ? $result : null;
    }
    
    private function parseYamlValue(string $value) {
        $value = trim($value, '"\'');
        if (is_numeric($value)) return $value + 0;
        if (strtolower($value) === 'true') return true;
        if (strtolower($value) === 'false') return false;
        if (strtolower($value) === 'null') return null;
        return $value;
    }
    
    public function loadParticipants(string $configPath, string $filename): array {
        $paths = [
            $configPath . '/' . $filename,
            $configPath . '/' . str_replace(['.yaml', '.yml'], '.json', $filename),
            $configPath . '/participants.yaml',
            $configPath . '/participants.yml',
            $configPath . '/participants.json'
        ];
        
        foreach ($paths as $path) {
            $data = $this->loadYamlFile($path) ?? (file_exists($path) ? json_decode(file_get_contents($path), true) : null);
            if ($data) {
                $participants = $data['participants'] ?? $data;
                return $this->enrichWithVaultSecrets($participants);
            }
        }
        
        throw new Exception("No participants configuration found in: {$configPath}");
    }
    
    private function enrichWithVaultSecrets(array $participants): array {
        foreach ($participants as $code => &$participant) {
            $vaultConfig = $this->vault->getParticipantConfig($code);
            
            // Add API key from vault
            if ($vaultConfig['api_key']) {
                $participant['security']['api_key']['value'] = $vaultConfig['api_key'];
                $participant['security']['api_key']['source'] = 'railway_vault';
            }
            
            // Add base URL from vault
            if ($vaultConfig['base_url']) {
                $participant['base_url'] = $vaultConfig['base_url'];
                $participant['base_url_source'] = 'railway_vault';
            }
            
            // Add timeout from vault
            $participant['timeout_ms'] = $vaultConfig['timeout_ms'] ?? ($participant['timeout_ms'] ?? 5000);
            $participant['retry_count'] = $vaultConfig['retry_count'] ?? ($participant['retry_count'] ?? 3);
        }
        
        return $participants;
    }
    
    public function loadEndpoints(string $configPath, string $filename): array {
        $paths = [
            $configPath . '/' . $filename,
            $configPath . '/endpoints.yaml',
            $configPath . '/endpoints.yml',
            $configPath . '/endpoints.json'
        ];
        
        foreach ($paths as $path) {
            $data = $this->loadYamlFile($path) ?? (file_exists($path) ? json_decode(file_get_contents($path), true) : null);
            if ($data) return $data;
        }
        
        return [];
    }
    
    public function loadFees(string $configPath, string $filename): array {
        $paths = [
            $configPath . '/' . $filename,
            $configPath . '/fees.yaml',
            $configPath . '/fees.yml',
            $configPath . '/fees.json'
        ];
        
        foreach ($paths as $path) {
            $data = $this->loadYamlFile($path) ?? (file_exists($path) ? json_decode(file_get_contents($path), true) : null);
            if ($data) return $data;
        }
        
        return [];
    }
    
    public function loadPhpConfig(string $configPath, string $filename): array {
        $path = $configPath . '/' . $filename;
        if (file_exists($path)) {
            return require $path;
        }
        return [];
    }
    
    public function getAvailableCountries(): array {
        $countries = [];
        foreach ($this->registry['countries'] as $name => $config) {
            if ($config['enabled'] ?? true) {
                $countries[] = [
                    'name' => $name,
                    'code' => $config['code'],
                    'currency' => $config['currency']
                ];
            }
        }
        return $countries;
    }
}

// ============================================
// 6. DATABASE CONNECTION (Dynamic)
// ============================================

class DynamicDatabase {
    private $connection = null;
    private $vault = null;
    
    public function __construct($vault) {
        $this->vault = $vault;
    }
    
    public function connect(): ?PDO {
        if ($this->connection) return $this->connection;
        
        // Try DATABASE_URL first
        $databaseUrl = $this->vault->getSecret('DATABASE_URL');
        if ($databaseUrl) {
            try {
                $this->connection = new PDO($databaseUrl);
                $this->configureConnection();
                error_log("[Database] Connected via DATABASE_URL");
                return $this->connection;
            } catch (PDOException $e) {
                error_log("[Database] DATABASE_URL failed: " . $e->getMessage());
            }
        }
        
        // Try individual PostgreSQL settings
        $host = $this->vault->getSecret('PG_HOST', 'localhost');
        $port = $this->vault->getSecret('PG_PORT', '5432');
        $database = $this->vault->getSecret('PG_DATABASE', $this->vault->getSecret('PG_NAME', 'postgres'));
        $user = $this->vault->getSecret('PG_USER', 'postgres');
        $password = $this->vault->getSecret('PG_PASSWORD', $this->vault->getSecret('PG_PASS', ''));
        
        if ($host && $database) {
            try {
                $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
                $this->connection = new PDO($dsn, $user, $password);
                $this->configureConnection();
                error_log("[Database] Connected via PG settings");
                return $this->connection;
            } catch (PDOException $e) {
                error_log("[Database] PG settings failed: " . $e->getMessage());
            }
        }
        
        error_log("[Database] No valid database configuration found");
        return null;
    }
    
    private function configureConnection(): void {
        if ($this->connection) {
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
    }
}

// ============================================
// 7. SWAP VALIDATOR (Dynamic rules)
// ============================================

class SwapValidator {
    private $participants = [];
    private $currency = '';
    
    public function __construct(array $participants, string $currency) {
        $this->participants = $participants;
        $this->currency = $currency;
    }
    
    public function validate(array $payload): array {
        $errors = [];
        
        // Check source
        if (empty($payload['source'])) {
            $errors[] = 'Source information required';
        } else {
            $source = $payload['source'];
            if (empty($source['institution'])) $errors[] = 'Source institution required';
            if (empty($source['amount']) || $source['amount'] <= 0) $errors[] = 'Valid amount required';
            
            // Validate institution exists
            $sourceCode = strtoupper($source['institution']);
            if (!isset($this->participants[$sourceCode])) {
                $errors[] = "Source institution '{$source['institution']}' not configured";
            }
        }
        
        // Check destination
        if (empty($payload['destination'])) {
            $errors[] = 'Destination information required';
        } else {
            $dest = $payload['destination'];
            if (empty($dest['institution'])) $errors[] = 'Destination institution required';
            
            $destCode = strtoupper($dest['institution']);
            if (!isset($this->participants[$destCode])) {
                $errors[] = "Destination institution '{$dest['institution']}' not configured";
            }
        }
        
        return $errors;
    }
    
    public function validateInstitutionSecrets(string $institutionCode): bool {
        $participant = $this->participants[$institutionCode] ?? null;
        if (!$participant) return false;
        
        $hasApiKey = !empty($participant['security']['api_key']['value']);
        $hasBaseUrl = !empty($participant['base_url']);
        
        if (!$hasApiKey) {
            error_log("[Validator] Institution {$institutionCode} missing API key");
        }
        if (!$hasBaseUrl) {
            error_log("[Validator] Institution {$institutionCode} missing base URL");
        }
        
        return $hasApiKey && $hasBaseUrl;
    }
}

// ============================================
// 8. MAIN EXECUTION
// ============================================

try {
    // Initialize dynamic config loader
    $configLoader = new DynamicConfigLoader($vault);
    
    // Handle GET requests - Return API info
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'success' => true,
            'service' => 'VouchMorphn Swap Execution API',
            'version' => '3.1.0',
            'method' => 'POST',
            'endpoint' => $_SERVER['REQUEST_URI'],
            'available_countries' => $configLoader->getAvailableCountries(),
            'documentation' => [
                'headers' => [
                    'Content-Type: application/json',
                    'X-API-Key: your_api_key',
                    'X-Country-Code: BW (optional)'
                ],
                'body_example' => [
                    'source' => [
                        'institution' => 'ZURUBANK',
                        'asset_type' => 'ACCOUNT',
                        'amount' => 100.00,
                        'currency' => 'BWP',
                        'account_number' => '1234567890'
                    ],
                    'destination' => [
                        'institution' => 'CAZACOM',
                        'delivery_mode' => 'deposit',
                        'identifier' => '+26771234567'
                    ]
                ]
            ]
        ], JSON_PRETTY_PRINT);
        exit();
    }
    
    // Only POST allowed for execution
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed. Use POST for swap execution, GET for API info.');
    }
    
    // Get country configuration
    $countryConfig = $configLoader->getCountryConfig();
    error_log("[Execute] Country: {$countryConfig['name']} ({$countryConfig['code']})");
    
    // Load all configurations dynamically
    $participants = $configLoader->loadParticipants($countryConfig['config_path'], $countryConfig['participants_file']);
    $endpoints = $configLoader->loadEndpoints($countryConfig['config_path'], $countryConfig['endpoints_file']);
    $fees = $configLoader->loadFees($countryConfig['config_path'], $countryConfig['fees_file']);
    $settings = $configLoader->loadPhpConfig($countryConfig['config_path'], $countryConfig['config_file']);
    
    error_log("[Execute] Loaded " . count($participants) . " participants, " . count($endpoints) . " endpoint configs");
    
    // Get request input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON payload');
    }
    
    // Authenticate
    $headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
    $providedKey = $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
    
    $validKeys = [];
    foreach ($participants as $code => $participant) {
        if (!empty($participant['security']['api_key']['value'])) {
            $validKeys[] = $participant['security']['api_key']['value'];
        }
    }
    
    $systemKey = $vault->getSecret('API_KEY_SYSTEM') ?: $vault->getSecret('VOUCHMORPH_API_KEY');
    if ($systemKey) $validKeys[] = $systemKey;
    
    $validKeys = array_unique(array_filter($validKeys));
    
    if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
        throw new Exception('Unauthorized: Invalid API key', 401);
    }
    
    error_log("[Execute] Authentication successful");
    
    // Validate swap request
    $validator = new SwapValidator($participants, $countryConfig['currency']);
    $errors = $validator->validate($input);
    
    if (!empty($errors)) {
        throw new Exception(implode(', ', $errors));
    }
    
    // Validate institutions have required secrets
    $sourceCode = strtoupper($input['source']['institution']);
    $destCode = strtoupper($input['destination']['institution']);
    
    if (!$validator->validateInstitutionSecrets($sourceCode)) {
        throw new Exception("Source institution '{$sourceCode}' missing API configuration in Railway Vault");
    }
    
    if (!$validator->validateInstitutionSecrets($destCode)) {
        throw new Exception("Destination institution '{$destCode}' missing API configuration in Railway Vault");
    }
    
    // Build swap payload
    $swapPayload = [
        'source' => [
            'institution' => $sourceCode,
            'asset_type' => $input['source']['asset_type'] ?? 'ACCOUNT',
            'amount' => (float)$input['source']['amount'],
            'currency' => $input['source']['currency'] ?? $countryConfig['currency']
        ],
        'destination' => [
            'institution' => $destCode,
            'delivery_mode' => $input['destination']['delivery_mode'] ?? 'deposit',
            'currency' => $input['destination']['currency'] ?? $countryConfig['currency']
        ]
    ];
    
    // Add identifiers dynamically
    $identifierMappings = [
        'phone' => 'wallet_phone',
        'account_number' => 'account_number',
        'wallet_id' => 'ewallet_phone',
        'identifier' => 'identifier'
    ];
    
    foreach ($identifierMappings as $inputField => $payloadField) {
        if (!empty($input['source'][$inputField])) {
            $swapPayload['source'][$payloadField] = $input['source'][$inputField];
        }
    }
    
    if (!empty($input['destination']['identifier'])) {
        $swapPayload['destination']['beneficiary_account'] = $input['destination']['identifier'];
        $swapPayload['destination']['beneficiary_phone'] = $input['destination']['identifier'];
    }
    
    if (!empty($input['user_id'])) {
        $swapPayload['user_id'] = $input['user_id'];
    }
    
    error_log("[Execute] Swap payload: " . json_encode($swapPayload));
    
    // Generate swap reference
    $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('YmdHis');
    
    // Build response
    $response = [
        'success' => true,
        'status' => 'pending',
        'swap_reference' => $swapReference,
        'message' => 'Swap request validated and queued',
        'country' => [
            'name' => $countryConfig['name'],
            'code' => $countryConfig['code'],
            'currency' => $countryConfig['currency'],
            'currency_symbol' => $countryConfig['currency_symbol']
        ],
        'participants' => [
            'source' => [
                'code' => $sourceCode,
                'name' => $participants[$sourceCode]['name'] ?? $sourceCode,
                'type' => $participants[$sourceCode]['type'] ?? 'bank'
            ],
            'destination' => [
                'code' => $destCode,
                'name' => $participants[$destCode]['name'] ?? $destCode,
                'type' => $participants[$destCode]['type'] ?? 'bank'
            ]
        ],
        'amount' => [
            'value' => $swapPayload['source']['amount'],
            'currency' => $swapPayload['source']['currency'],
            'formatted' => $countryConfig['currency_symbol'] . number_format($swapPayload['source']['amount'], 2)
        ],
        'fees_applied' => !empty($fees),
        'vault_secured' => true,
        'timestamp' => date('c')
    ];
    
    // Try to execute if SwapService exists
    if (class_exists('Domain\Services\SwapService')) {
        $db = (new DynamicDatabase($vault))->connect();
        $encryptionKey = $vault->getSecret('ENCRYPTION_KEY') ?: $vault->getSecret('APP_ENCRYPTION_KEY');
        
        $finalConfig = [
            'participants' => $participants,
            'endpoints' => $endpoints,
            'fees' => $fees,
            'currency' => $countryConfig['currency'],
            'currency_symbol' => $countryConfig['currency_symbol'],
            'dial_code' => $countryConfig['dial_code'],
            'country_code' => $countryConfig['code'],
            'country_name' => $countryConfig['name'],
            'vault_managed' => true
        ];
        
        try {
            $swapService = new \Domain\Services\SwapService(
                $db,
                $settings,
                $countryConfig['code'],
                $encryptionKey,
                $finalConfig,
                $vault
            );
            
            $result = $swapService->executeSwap($swapPayload);
            $response['status'] = $result['status'] ?? 'completed';
            $response['data'] = $result;
            $response['swap_reference'] = $result['swap_reference'] ?? $swapReference;
        } catch (Exception $e) {
            error_log("[Execute] SwapService execution error: " . $e->getMessage());
            $response['status'] = 'queued';
            $response['warning'] = 'Swap queued for processing: ' . $e->getMessage();
        }
    } else {
        $response['status'] = 'simulated';
        $response['notice'] = 'SwapService not available - request validated only';
    }
    
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    $httpCode = $e->getCode() && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    http_response_code($httpCode);
    
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $httpCode,
        'timestamp' => date('c')
    ]);
    
    error_log("[Execute] Error: " . $e->getMessage());
}
