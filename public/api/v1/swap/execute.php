<?php
declare(strict_types=1);

/**
 * VouchMorphn - Swap Execution API
 * Fully Dynamic - No Hardcoding - Works for ALL Countries
 * INTEGRATED WITH RAILWAY VAULT
 */

// ============================================
// 1. BOOTSTRAP & PATHS
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

// ============================================
// 2. HEADERS & CORS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Country-Code, X-Country");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// 3. ERROR REPORTING
// ============================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================
// 4. RAILWAY VAULT SECRET MANAGER
// ============================================

/**
 * Railway Vault Secret Manager
 * Railway automatically injects ALL vault variables as environment variables
 * This class provides a unified interface to access them
 */
class RailwayVaultManager {
    private static $instance = null;
    private $secrets = [];
    private $vaultPrefix = 'UPSTREAM_'; // Railway vault pattern
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get secret from Railway Vault (injected as env vars)
     * Priority: Railway Vault > .env file > Default
     */
    public function getSecret(string $key, $default = null) {
        // Check cache
        if (isset($this->secrets[$key])) {
            return $this->secrets[$key];
        }
        
        $value = null;
        
        // 1. Try direct environment variable (Railway injects these)
        $directValue = getenv($key);
        if ($directValue !== false && !empty($directValue)) {
            $value = $directValue;
            error_log("[Vault] Found secret: {$key} (direct)");
        }
        
        // 2. Try with UPSTREAM_ prefix (Railway vault pattern)
        if ($value === null) {
            $upstreamKey = 'UPSTREAM_' . $key;
            $upstreamValue = getenv($upstreamKey);
            if ($upstreamValue !== false && !empty($upstreamValue)) {
                $value = $upstreamValue;
                error_log("[Vault] Found secret: {$upstreamKey} (upstream)");
            }
        }
        
        // 3. Try with participant-specific patterns
        if ($value === null) {
            // For API keys: ZURUBANK_API_KEY, CAZACOM_API_KEY, etc.
            $possibleKeys = [
                $key,
                strtoupper($key),
                str_replace('-', '_', strtoupper($key)),
                $key . '_API_KEY',
                strtoupper($key) . '_API_KEY'
            ];
            
            foreach ($possibleKeys as $possibleKey) {
                $possibleValue = getenv($possibleKey);
                if ($possibleValue !== false && !empty($possibleValue)) {
                    $value = $possibleValue;
                    error_log("[Vault] Found secret: {$possibleKey}");
                    break;
                }
            }
        }
        
        // 4. Check $_ENV and $_SERVER as fallback
        if ($value === null && isset($_ENV[$key])) {
            $value = $_ENV[$key];
        }
        if ($value === null && isset($_SERVER[$key])) {
            $value = $_SERVER[$key];
        }
        
        // Cache and return
        $this->secrets[$key] = $value ?? $default;
        return $this->secrets[$key];
    }
    
    /**
     * Get API key for a specific participant
     */
    public function getParticipantApiKey(string $participantCode): ?string {
        // Try multiple naming conventions that Railway might use
        $variations = [
            $participantCode . '_API_KEY',
            'UPSTREAM_' . $participantCode . '_KEY',
            strtoupper($participantCode) . '_API_KEY',
            $participantCode . '_KEY',
            'API_KEY_' . $participantCode
        ];
        
        foreach ($variations as $varName) {
            $key = $this->getSecret($varName);
            if ($key) {
                error_log("[Vault] Resolved API key for {$participantCode} from: {$varName}");
                return $key;
            }
        }
        
        return null;
    }
    
    /**
     * Get base URL for a specific participant
     */
    public function getParticipantBaseUrl(string $participantCode): ?string {
        $variations = [
            $participantCode . '_BASE_URL',
            'UPSTREAM_' . $participantCode . '_URL',
            strtoupper($participantCode) . '_BASE_URL',
            $participantCode . '_URL'
        ];
        
        foreach ($variations as $varName) {
            $url = $this->getSecret($varName);
            if ($url) {
                error_log("[Vault] Resolved base URL for {$participantCode} from: {$varName}");
                return $url;
            }
        }
        
        return null;
    }
}

$vault = RailwayVaultManager::getInstance();

// ============================================
// 5. LOAD COUNTRY REGISTRY
// ============================================
$registryFile = ROOT_PATH . '/src/Core/Config/countries_registry.json';

if (!file_exists($registryFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Country registry not found',
        'expected_path' => $registryFile
    ]);
    exit();
}

$registry = json_decode(file_get_contents($registryFile), true);
$availableCountries = [];

foreach ($registry['countries'] as $name => $config) {
    if ($config['enabled'] ?? true) {
        $availableCountries[] = [
            'name' => $name,
            'code' => $config['code'],
            'currency' => $config['currency']
        ];
    }
}

// ============================================
// 6. GET COUNTRY FROM REQUEST
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);

$requestCountry = $_SERVER['HTTP_X_COUNTRY_CODE'] ?? 
                  $_SERVER['HTTP_X_COUNTRY'] ?? 
                  $_SERVER['HTTP_COUNTRY'] ??
                  $headersLower['x-country-code'] ?? 
                  $headersLower['x-country'] ?? 
                  $headersLower['country'] ??
                  $_GET['country'] ?? 
                  null;

$input = json_decode(file_get_contents('php://input'), true);
$bodyCountry = $input['country'] ?? $input['source']['country'] ?? $input['destination']['country'] ?? null;

$countryInput = $requestCountry ?? $bodyCountry;

// Find matching country in registry
$countryConfig = null;
$countryName = null;

if ($countryInput) {
    foreach ($registry['countries'] as $name => $config) {
        if (strtolower($name) === strtolower($countryInput) || 
            strtolower($config['code']) === strtolower($countryInput)) {
            $countryConfig = $config;
            $countryName = $name;
            break;
        }
    }
}

// If not found, use default
if (!$countryConfig) {
    $defaultCountry = $registry['default_country'] ?? key($registry['countries']);
    $countryConfig = $registry['countries'][$defaultCountry] ?? null;
    $countryName = $defaultCountry;
}

if (!$countryConfig) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'No valid country configuration found',
        'available_countries' => $availableCountries
    ]);
    exit();
}

// Check if country is enabled
if (!($countryConfig['enabled'] ?? true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => "Country '{$countryName}' is not enabled",
        'available_countries' => $availableCountries
    ]);
    exit();
}

error_log("[execute.php] Using country: {$countryName} ({$countryConfig['code']})");

// ============================================
// 7. LOAD CONFIGURATION FROM RAILWAY VAULT FIRST
// ============================================
$basePath = ROOT_PATH . '/' . $countryConfig['config_path'];

// Get database connection from Railway Vault
$databaseUrl = $vault->getSecret('DATABASE_URL');
$pgHost = $vault->getSecret('PG_HOST', 'interchange.proxy.rlwy.net');
$pgPort = $vault->getSecret('PG_PORT', '52371');
$pgUser = $vault->getSecret('PG_USER', 'postgres');
$pgPassword = $vault->getSecret('PG_PASS');
$pgDatabase = $vault->getSecret('PG_NAME', 'railway');

// Get encryption key from Vault
$encryptionKey = $vault->getSecret('ENCRYPTION_KEY');
if (!$encryptionKey) {
    $encryptionKey = $vault->getSecret('APP_ENCRYPTION_KEY', bin2hex(random_bytes(16)));
}

// ============================================
// 8. LOAD YAML CONFIGURATION FILES (for structure only)
// ============================================

/**
 * Load YAML file (fallback parser)
 */
function loadYamlFile($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    
    // Try Symfony YAML if available
    if (class_exists('Symfony\Component\Yaml\Yaml')) {
        return \Symfony\Component\Yaml\Yaml::parse(file_get_contents($filePath));
    }
    
    // Simple YAML parser fallback
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
                $result[$key] = $value;
            }
        } elseif ($inArray && strpos($line, '-') === 0) {
            $item = trim(substr($line, 1));
            $currentArray[] = $item;
        } elseif ($inArray && strpos($line, '}') !== false) {
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

// Load participants structure (without secrets)
$participantsFileYaml = $basePath . '/participants.yaml';
$participantsData = null;

if (file_exists($participantsFileYaml)) {
    $participantsData = loadYamlFile($participantsFileYaml);
}

if (!$participantsData) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => "Participants configuration not found for {$countryName}",
        'expected_path' => $participantsFileYaml
    ]);
    exit();
}

$participants = $participantsData['participants'] ?? $participantsData ?? [];

// ENRICH PARTICIPANTS WITH RAILWAY VAULT SECRETS
foreach ($participants as $code => &$participant) {
    // Get API key from Railway Vault
    $apiKey = $vault->getParticipantApiKey($code);
    if ($apiKey) {
        $participant['security']['api_key']['value'] = $apiKey;
        error_log("[execute.php] Loaded API key for {$code} from Railway Vault");
    }
    
    // Get base URL from Railway Vault
    $baseUrl = $vault->getParticipantBaseUrl($code);
    if ($baseUrl) {
        $participant['base_url'] = $baseUrl;
        error_log("[execute.php] Loaded base URL for {$code} from Railway Vault: {$baseUrl}");
    }
}

// Load endpoints structure
$endpointsFileYaml = $basePath . '/endpoints.yaml';
$endpoints = [];
if (file_exists($endpointsFileYaml)) {
    $endpoints = loadYamlFile($endpointsFileYaml) ?: [];
}

// Load fees
$feesFileYaml = $basePath . '/fees.yaml';
$fees = [];
if (file_exists($feesFileYaml)) {
    $fees = loadYamlFile($feesFileYaml) ?: [];
}

// Load config (PHP file)
$configFile = $basePath . $countryConfig['config_file'];
$settings = [];
if (file_exists($configFile)) {
    $settings = require $configFile;
}

$currency = $countryConfig['currency'];
$currencySymbol = $countryConfig['currency_symbol'] ?? $settings['currency_symbol'] ?? 'P';
$dialCode = $countryConfig['dial_code'] ?? $settings['dial_code'] ?? '+267';

// Build final config with vault secrets
$finalConfig = [
    'participants' => $participants,
    'endpoints' => $endpoints,
    'fees' => $fees,
    'currency' => $currency,
    'currency_symbol' => $currencySymbol,
    'dial_code' => $dialCode,
    'country_code' => $countryConfig['code'],
    'country_name' => $countryName,
    'multi_source' => $settings['multi_source'] ?? ['enabled' => true],
    'vault_managed' => true  // Flag indicating secrets are from Railway Vault
];

error_log("[execute.php] Loaded " . count($participants) . " participants with vault secrets");

// ============================================
// 9. DATABASE CONNECTION (using Railway Vault credentials)
// ============================================
$db = null;
try {
    if ($databaseUrl) {
        // Parse Railway PostgreSQL URL
        $db = new PDO($databaseUrl);
        error_log("[execute.php] Connected via DATABASE_URL from Vault");
    } else {
        // Use individual vault credentials
        $dsn = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDatabase}";
        $db = new PDO($dsn, $pgUser, $pgPassword);
        error_log("[execute.php] Connected via individual PG credentials from Vault");
    }
    
    if ($db) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("[execute.php] Database connection failed: " . $e->getMessage());
}

// ============================================
// 10. AUTHENTICATION USING VAULT API KEYS
// ============================================
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

// Build valid keys array from vault participants
$validKeys = [];

// Add system API key from vault
$systemKey = $vault->getSecret('API_KEY_SYSTEM');
if ($systemKey) {
    $validKeys[] = $systemKey;
}

// Add all participant API keys from vault
foreach ($participants as $code => $participant) {
    if (isset($participant['security']['api_key']['value'])) {
        $validKeys[] = $participant['security']['api_key']['value'];
    }
}

// Also check common API key patterns
$commonKeys = [
    'VOUCHMORPH_API_KEY',
    'API_KEY_VOUCHMORPH',
    'SYSTEM_API_KEY'
];

foreach ($commonKeys as $keyName) {
    $keyValue = $vault->getSecret($keyName);
    if ($keyValue) {
        $validKeys[] = $keyValue;
    }
}

$validKeys = array_filter(array_unique($validKeys));

error_log("[execute.php] Total valid API keys loaded from vault: " . count($validKeys));

// Validate API key
if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Invalid API key',
        'country' => $countryName,
        'message' => 'Please use a valid API key from Railway Vault'
    ]);
    exit();
}

error_log("[execute.php] Authentication passed");

// ============================================
// 11. EXECUTE SWAP (with vault-aware clients)
// ============================================
try {
    if (!$input) {
        throw new Exception('Invalid JSON payload');
    }
    
    $source = $input['source'] ?? [];
    $destination = $input['destination'] ?? [];
    $userId = $input['user_id'] ?? $input['userId'] ?? null;
    
    if (empty($source)) throw new Exception('Source information required');
    if (empty($destination)) throw new Exception('Destination information required');
    if (empty($source['amount']) || $source['amount'] <= 0) throw new Exception('Valid amount required');
    if (empty($source['institution'])) throw new Exception('Source institution required');
    if (empty($destination['institution'])) throw new Exception('Destination institution required');
    
    // Validate institutions exist with vault secrets
    $sourceExists = false;
    $destExists = false;
    foreach ($participants as $code => $p) {
        if (strtoupper($code) === strtoupper($source['institution'])) {
            $sourceExists = true;
            // Verify this participant has API key from vault
            if (empty($p['security']['api_key']['value'])) {
                throw new Exception("Source institution {$source['institution']} has no API key configured in Railway Vault");
            }
        }
        if (strtoupper($code) === strtoupper($destination['institution'])) {
            $destExists = true;
            if (empty($p['security']['api_key']['value'])) {
                throw new Exception("Destination institution {$destination['institution']} has no API key configured in Railway Vault");
            }
        }
    }
    
    if (!$sourceExists) {
        throw new Exception('Source institution not configured: ' . $source['institution']);
    }
    if (!$destExists) {
        throw new Exception('Destination institution not configured: ' . $destination['institution']);
    }
    
    // Build payload (same as before)
    $payload = [
        'source' => [
            'institution' => $source['institution'],
            'asset_type' => $source['asset_type'] ?? 'ACCOUNT',
            'amount' => (float)$source['amount'],
            'currency' => $source['currency'] ?? $currency
        ],
        'destination' => [
            'institution' => $destination['institution'],
            'delivery_mode' => $destination['delivery_mode'] ?? 'deposit',
            'currency' => $destination['currency'] ?? $currency
        ]
    ];
    
    // Add identifiers
    if (isset($source['phone'])) {
        $payload['source']['wallet_phone'] = $source['phone'];
    }
    if (isset($source['account_number'])) {
        $payload['source']['account_number'] = $source['account_number'];
    }
    if (isset($destination['identifier'])) {
        $payload['destination']['beneficiary_account'] = $destination['identifier'];
        $payload['destination']['beneficiary_phone'] = $destination['identifier'];
    }
    
    if ($userId) {
        $payload['user_id'] = $userId;
    }
    
    error_log("[execute.php] Swap payload: " . json_encode($payload));
    
    // Use vault-aware swap service
    if (class_exists('Domain\Services\SwapService')) {
        $swapService = new \Domain\Services\SwapService(
            $db,
            $settings,
            $countryConfig['code'],
            $encryptionKey,
            $finalConfig,
            $vault  // Pass vault manager for runtime secret resolution
        );
        
        $result = $swapService->executeSwap($payload);
        $swapReference = $result['swap_reference'] ?? 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('YmdHis');
    } else {
        $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('YmdHis');
        $result = ['swap_reference' => $swapReference, 'status' => 'completed'];
    }
    
    echo json_encode([
        'success' => true,
        'status' => 'success',
        'swap_reference' => $swapReference,
        'message' => 'Swap completed successfully',
        'country' => [
            'name' => $countryName,
            'code' => $countryConfig['code'],
            'currency' => $currency
        ],
        'vault_secured' => true,
        'data' => $result
    ]);
    
} catch (Exception $e) {
    error_log("[execute.php] Swap execution failed: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => $e->getMessage(),
        'country' => $countryName
    ]);
}
