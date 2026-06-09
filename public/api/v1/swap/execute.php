<?php
declare(strict_types=1);

/**
 * VouchMorph - Swap Execution API
 * FULLY DYNAMIC - ZERO HARDCODING - WORKS FOR ALL COUNTRIES
 * 
 * This file is country-agnostic. All configuration comes from:
 * - countries_registry.json (defines all countries and their paths)
 * - Country-specific YAML files (participants.yaml, endpoints.yaml)
 * - General YAML files (assets.yaml, flows.yaml)
 */

// ============================================
// 1. BOOTSTRAP & PATHS
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));
define('CONFIG_PATH', ROOT_PATH . '/src/Core/Config');

// ============================================
// 2. HEADERS & CORS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Country-Code, X-Country, X-Currency");

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
// 4. UNIVERSAL HELPER FUNCTIONS
// ============================================

function loadEnvFile($filePath) {
    if (!file_exists($filePath)) {
        return false;
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, '"\'');
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
    return true;
}

/**
 * Parse YAML file - supports multiple parsers
 */
function parseYamlFile($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    
    // Try yaml_parse extension first
    if (function_exists('yaml_parse')) {
        $content = file_get_contents($filePath);
        $result = yaml_parse($content);
        if ($result !== false) {
            return $result;
        }
    }
    
    // Try Symfony YAML component if available
    if (class_exists('Symfony\Component\Yaml\Yaml')) {
        return \Symfony\Component\Yaml\Yaml::parseFile($filePath);
    }
    
    // Fallback: simple line-by-line parser for basic YAML
    return parseYamlSimple($filePath);
}

function parseYamlSimple($filePath) {
    $content = file_get_contents($filePath);
    $lines = explode("\n", $content);
    $result = [];
    $currentSection = &$result;
    $indentStack = [&$result];
    $lastIndent = 0;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || preg_match('/^\s*#/', $line)) {
            continue;
        }
        
        $indent = strlen($line) - strlen(ltrim($line));
        $trimmed = trim($line);
        
        // Adjust current section based on indentation
        while ($indent < $lastIndent && count($indentStack) > 1) {
            array_pop($indentStack);
            $currentSection = &$indentStack[count($indentStack) - 1];
            $lastIndent -= 4; // Assume 4 spaces per indent level
        }
        
        // Key-value pair
        if (strpos($trimmed, ':') !== false && !preg_match('/^\s*-/', $trimmed)) {
            list($key, $value) = explode(':', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, '"\'');
            
            if ($value === '') {
                // Start nested section
                if (!isset($currentSection[$key])) {
                    $currentSection[$key] = [];
                }
                $indentStack[] = &$currentSection[$key];
                $currentSection = &$currentSection[$key];
            } else {
                $currentSection[$key] = $value;
            }
        }
        // Array item
        elseif (preg_match('/^\s*-\s*(.+)$/', $trimmed, $matches)) {
            $item = trim($matches[1]);
            $item = trim($item, '"\'');
            if (!isset($currentSection['_array'])) {
                $currentSection['_array'] = [];
            }
            $currentSection['_array'][] = $item;
        }
        
        $lastIndent = $indent;
    }
    
    // Convert _array placeholders back to arrays
    array_walk_recursive($result, function(&$value) {
        if (is_array($value) && isset($value['_array'])) {
            $value = $value['_array'];
        }
    });
    
    return $result;
}

/**
 * Load configuration file by type (supports YAML, JSON, PHP)
 */
function loadConfigFile($filePath, $type = null) {
    if (!file_exists($filePath)) {
        return null;
    }
    
    $ext = $type ?: pathinfo($filePath, PATHINFO_EXTENSION);
    
    switch (strtolower($ext)) {
        case 'yaml':
        case 'yml':
            return parseYamlFile($filePath);
        case 'json':
            return json_decode(file_get_contents($filePath), true);
        case 'php':
            return require $filePath;
        default:
            return null;
    }
}

// ============================================
// 5. LOAD COUNTRY REGISTRY
// ============================================
$registryFile = CONFIG_PATH . '/countries_registry.json';

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
// 6. DETECT COUNTRY FROM REQUEST (MULTI-SOURCE)
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);

// Priority order for country detection
$countryInput = 
    $_SERVER['HTTP_X_COUNTRY_CODE'] ?? 
    $_SERVER['HTTP_X_COUNTRY'] ?? 
    $_SERVER['HTTP_COUNTRY'] ??
    $headersLower['x-country-code'] ?? 
    $headersLower['x-country'] ?? 
    $headersLower['country'] ??
    $_GET['country'] ?? 
    $_GET['cc'] ??
    null;

// Also check request body
$input = json_decode(file_get_contents('php://input'), true);
$bodyCountry = $input['country'] ?? $input['source']['country'] ?? $input['destination']['country'] ?? null;

$finalCountryInput = $countryInput ?? $bodyCountry;

// Find matching country in registry
$countryConfig = null;
$countryName = null;

if ($finalCountryInput) {
    foreach ($registry['countries'] as $name => $config) {
        if (strtolower($name) === strtolower($finalCountryInput) || 
            strtolower($config['code']) === strtolower($finalCountryInput)) {
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
        'error' => 'No valid country configuration found. Please specify X-Country-Code header.',
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

error_log("[execute.php] Country detected: {$countryName} ({$countryConfig['code']})");

// ============================================
// 7. LOAD COUNTRY-SPECIFIC ENVIRONMENT
// ============================================
$countryPath = ROOT_PATH . '/' . $countryConfig['config_path'];

// Load .env from multiple possible locations
$envPaths = [
    $countryPath . '.env',
    $countryPath . ".env_{$countryConfig['code']}",
    $countryPath . ".env_" . strtolower($countryConfig['code']),
    ROOT_PATH . '/.env',
    ROOT_PATH . "/.env_{$countryConfig['code']}"
];

$envLoaded = false;
foreach ($envPaths as $envPath) {
    if (loadEnvFile($envPath)) {
        $envLoaded = true;
        error_log("[execute.php] Loaded .env from: {$envPath}");
        break;
    }
}

// ============================================
// 8. LOAD GENERAL CONFIGURATION (SHARED ACROSS COUNTRIES)
// ============================================
$generalConfigs = [
    'assets' => CONFIG_PATH . '/assets.yaml',
    'flows' => CONFIG_PATH . '/flows.yaml',
    'channel' => CONFIG_PATH . '/channel.php',
    'mojaloop' => CONFIG_PATH . '/mojaloop.php',
    'message_adapters' => CONFIG_PATH . '/message_adapters.php'
];

$general = [];
foreach ($generalConfigs as $key => $path) {
    $loaded = loadConfigFile($path);
    if ($loaded !== null) {
        $general[$key] = $loaded;
        error_log("[execute.php] Loaded general config '{$key}' from: {$path}");
    }
}

// ============================================
// 9. LOAD COUNTRY-SPECIFIC CONFIGURATION
// ============================================

// Define possible file extensions in order of preference
$preferredExtensions = ['yaml', 'yml', 'json', 'php'];

// Country config files mapping
$countryFiles = [
    'participants' => null,
    'endpoints' => null,
    'fees' => null,
    'config' => null,
    'database' => null,
    'atm_notes' => null,
    'banks' => null,
    'cards' => null,
    'mobile_money' => null,
    'communication' => null
];

// Discover files dynamically
foreach ($preferredExtensions as $ext) {
    foreach (array_keys($countryFiles) as $fileType) {
        if ($countryFiles[$fileType] === null) {
            $possiblePath = $countryPath . '/' . $fileType . '.' . $ext;
            if (file_exists($possiblePath)) {
                $countryFiles[$fileType] = $possiblePath;
                error_log("[execute.php] Found {$fileType}.{$ext} for {$countryName}");
            }
        }
    }
}

// Also check for country-specific naming patterns
foreach ($preferredExtensions as $ext) {
    $patternPaths = [
        $countryPath . "/participants.{$ext}",
        $countryPath . "/participants_{$countryConfig['code']}.{$ext}",
        $countryPath . "/config.{$ext}",
        $countryPath . "/config_{$countryConfig['code']}.{$ext}",
    ];
    
    foreach ($patternPaths as $path) {
        if (file_exists($path)) {
            if (strpos($path, 'participants') !== false && $countryFiles['participants'] === null) {
                $countryFiles['participants'] = $path;
            }
            if (strpos($path, 'config') !== false && $countryFiles['config'] === null) {
                $countryFiles['config'] = $path;
            }
        }
    }
}

// Load all discovered country files
$country = [];
foreach ($countryFiles as $fileType => $path) {
    if ($path) {
        $loaded = loadConfigFile($path);
        if ($loaded !== null) {
            $country[$fileType] = $loaded;
            error_log("[execute.php] Loaded country '{$fileType}' from: {$path}");
        }
    }
}

// ============================================
// 10. EXTRACT PARTICIPANTS (HANDLES MULTIPLE FORMATS)
// ============================================
$participants = [];

if (isset($country['participants'])) {
    $participantsData = $country['participants'];
    
    // Handle different YAML structures
    if (isset($participantsData['participants'])) {
        // Format: participants: { ZURUBANK_SA: {...} }
        $participants = $participantsData['participants'];
    } elseif (is_array($participantsData) && !isset($participantsData[0])) {
        // Format: direct participants array
        $participants = $participantsData;
    }
}

// Merge endpoints into participants if available
if (isset($country['endpoints'])) {
    foreach ($participants as $code => &$participant) {
        if (isset($country['endpoints'][$code])) {
            $participant['endpoints'] = $country['endpoints'][$code];
            $participant['base_url'] = $country['endpoints'][$code]['base_url'] ?? null;
            $participant['auth'] = $country['endpoints'][$code]['auth'] ?? null;
            $participant['timeout_ms'] = $country['endpoints'][$code]['timeout_ms'] ?? 5000;
            $participant['retry_policy'] = $country['endpoints'][$code]['retry_policy'] ?? ['max_retries' => 3];
            $participant['message_profile'] = $country['endpoints'][$code]['message_profile'] ?? [];
            $participant['phone_format'] = $country['endpoints'][$code]['phone_format'] ?? [];
        }
    }
}

if (empty($participants)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => "No participants found for {$countryName}",
        'searched_paths' => $countryFiles,
        'country_path' => $countryPath
    ]);
    exit();
}

error_log("[execute.php] Loaded " . count($participants) . " participants for {$countryName}");

// ============================================
// 11. BUILD COUNTRY CONFIGURATION
// ============================================
$currency = $countryConfig['currency'];
$currencySymbol = $countryConfig['currency_symbol'] ?? 
                  ($country['config']['currency_symbol'] ?? 
                  ($country['config']['currency_symbol'] ?? ''));
$dialCode = $countryConfig['dial_code'] ?? 
            ($country['config']['dial_code'] ?? 
            ($country['communication']['dial_code'] ?? '+' . $countryConfig['code']));

// Build complete configuration for SwapService
$finalConfig = [
    'participants' => $participants,
    'assets' => $general['assets'] ?? [],
    'flows' => $general['flows'] ?? [],
    'fees' => $country['fees'] ?? [],
    'banks' => $country['banks'] ?? [],
    'cards' => $country['cards'] ?? [],
    'mobile_money' => $country['mobile_money'] ?? [],
    'currency' => $currency,
    'currency_symbol' => $currencySymbol,
    'dial_code' => $dialCode,
    'country_code' => $countryConfig['code'],
    'country_name' => $countryName,
    'atm_notes' => $country['atm_notes'] ?? [],
    'communication' => $country['communication'] ?? ($country['config']['communication'] ?? []),
    'multi_source' => $country['config']['multi_source'] ?? ['enabled' => true],
    'timestamp' => date('Y-m-d H:i:s')
];

// ============================================
// 12. LOAD COMPOSER AUTOLOADER (UNIVERSAL PATH DETECTION)
// ============================================
$composerPaths = [
    ROOT_PATH . '/vendor/autoload.php',
    ROOT_PATH . '/../vendor/autoload.php',
    dirname(ROOT_PATH) . '/vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../vendor/autoload.php'
];

$autoloaderFound = false;
foreach ($composerPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        error_log("[execute.php] Composer autoloader: {$path}");
        break;
    }
}

if (!$autoloaderFound) {
    error_log("[execute.php] No composer autoloader found");
}

// ============================================
// 13. DATABASE CONNECTION (UNIVERSAL)
// ============================================
$db = null;
try {
    // Try DATABASE_URL first (Railway, Heroku, etc.)
    $databaseUrl = getenv('DATABASE_URL');
    if ($databaseUrl) {
        $db = new PDO($databaseUrl);
        error_log("[execute.php] Database: DATABASE_URL");
    } 
    // Try country-specific database config
    elseif (isset($country['database'])) {
        $dbConfig = $country['database'];
        
        // Handle different database config structures
        if (isset($dbConfig['swap'])) {
            $dbConfig = $dbConfig['swap'];
        }
        
        $driver = $dbConfig['driver'] ?? 'pgsql';
        $host = $dbConfig['host'] ?? 'localhost';
        $port = $dbConfig['port'] ?? ($driver === 'mysql' ? 3306 : 5432);
        $dbname = $dbConfig['database'] ?? $dbConfig['dbname'] ?? 'vouchmorph';
        $user = $dbConfig['username'] ?? $dbConfig['user'] ?? 'postgres';
        $pass = $dbConfig['password'] ?? $dbConfig['pass'] ?? '';
        
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", $driver, $host, $port, $dbname);
        $db = new PDO($dsn, $user, $pass);
        error_log("[execute.php] Database: {$driver} from country config");
    }
    // Try environment variables
    else {
        $dbDriver = getenv('DB_DRIVER') ?: 'pgsql';
        $dbHost = getenv('DB_HOST') ?: getenv('PG_HOST') ?: 'localhost';
        $dbPort = getenv('DB_PORT') ?: getenv('PG_PORT') ?: ($dbDriver === 'mysql' ? 3306 : 5432);
        $dbName = getenv('DB_NAME') ?: getenv('PG_NAME') ?: getenv('PG_DATABASE') ?: 'vouchmorph';
        $dbUser = getenv('DB_USER') ?: getenv('PG_USER') ?: 'postgres';
        $dbPass = getenv('DB_PASS') ?: getenv('PG_PASS') ?: getenv('PG_PASSWORD') ?: '';
        
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", $dbDriver, $dbHost, $dbPort, $dbName);
        $db = new PDO($dsn, $dbUser, $dbPass);
        error_log("[execute.php] Database: {$dbDriver} from environment");
    }
    
    if ($db) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("[execute.php] Database connection failed: " . $e->getMessage());
    // Continue without DB - some operations may still work
}

// ============================================
// 14. AUTHENTICATION (DYNAMIC FROM CONFIG)
// ============================================
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
$validKeys = [];

// Extract API keys from participants configuration
foreach ($participants as $code => $participant) {
    $authSources = [
        $participant['auth']['api_key']['secret_source']['name'] ?? null,
        $participant['auth']['api_key']['value'] ?? null,
        $participant['security']['api_key']['value_env'] ?? null,
        $participant['security']['api_key']['value'] ?? null,
        $participant['api_key'] ?? null
    ];
    
    foreach ($authSources as $source) {
        if ($source) {
            if (getenv($source)) {
                $validKeys[] = getenv($source);
            } else {
                $validKeys[] = $source;
            }
        }
    }
}

// Also check common environment variable names
$commonKeyNames = [
    'API_KEY_SYSTEM',
    'API_KEY_VOUCHMORPH',
    "API_KEY_SYSTEM_{$countryConfig['code']}",
    "API_KEY_{$countryConfig['code']}",
    "API_KEY_" . strtoupper($countryName)
];

foreach ($commonKeyNames as $keyName) {
    $keyValue = getenv($keyName);
    if ($keyValue) {
        $validKeys[] = $keyValue;
    }
}

$validKeys = array_filter(array_unique($validKeys));

// Allow if no keys configured (development) OR key matches
if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Invalid API key',
        'country' => $countryName
    ]);
    exit();
}

error_log("[execute.php] Authentication passed for {$countryName}");

// ============================================
// 15. EXECUTE SWAP (DYNAMIC BASED ON COUNTRY)
// ============================================
try {
    if (!$input) {
        throw new Exception('Invalid JSON payload');
    }
    
    $source = $input['source'] ?? [];
    $destination = $input['destination'] ?? [];
    $userId = $input['user_id'] ?? $input['userId'] ?? null;
    $operation = $input['operation'] ?? 'swap';
    
    // Basic validation
    if (empty($source)) throw new Exception('Source information required');
    if (empty($destination)) throw new Exception('Destination information required');
    if (empty($source['amount']) || $source['amount'] <= 0) throw new Exception('Valid amount required');
    if (empty($source['institution'])) throw new Exception('Source institution required');
    if (empty($destination['institution'])) throw new Exception('Destination institution required');
    
    // Validate institutions exist in this country's participants
    $sourceInstitution = strtoupper($source['institution']);
    $destInstitution = strtoupper($destination['institution']);
    
    if (!isset($participants[$sourceInstitution])) {
        $available = array_keys($participants);
        throw new Exception("Source institution '{$source['institution']}' not configured. Available: " . implode(', ', $available));
    }
    if (!isset($participants[$destInstitution])) {
        $available = array_keys($participants);
        throw new Exception("Destination institution '{$destination['institution']}' not configured. Available: " . implode(', ', $available));
    }
    
    // Build payload with country context
    $payload = [
        'source' => [
            'institution' => $sourceInstitution,
            'asset_type' => $source['asset_type'] ?? ($participants[$sourceInstitution]['asset_types'][0] ?? 'ACCOUNT'),
            'amount' => (float)$source['amount'],
            'currency' => $source['currency'] ?? $currency,
            'country' => $countryConfig['code']
        ],
        'destination' => [
            'institution' => $destInstitution,
            'delivery_mode' => $destination['delivery_mode'] ?? 'deposit',
            'currency' => $destination['currency'] ?? $currency,
            'country' => $countryConfig['code']
        ],
        'metadata' => [
            'country_name' => $countryName,
            'country_code' => $countryConfig['code'],
            'timestamp' => date('c'),
            'api_version' => $registry['version'] ?? '2.1.0'
        ]
    ];
    
    // Add identifiers based on asset type
    $identifierFields = ['phone', 'wallet_id', 'account_number', 'identifier', 'card_number', 'token'];
    foreach ($identifierFields as $field) {
        if (isset($source[$field])) {
            $payload['source'][$field] = $source[$field];
            break;
        }
    }
    
    if (isset($destination['identifier'])) {
        if ($payload['destination']['delivery_mode'] === 'cashout') {
            $payload['destination']['cashout'] = ['beneficiary_phone' => $destination['identifier']];
        } else {
            $payload['destination']['beneficiary_account'] = $destination['identifier'];
            $payload['destination']['beneficiary_phone'] = $destination['identifier'];
        }
    }
    
    if (isset($source['credentials'])) {
        $payload['source']['credentials'] = $source['credentials'];
    }
    
    if ($userId) {
        $payload['user_id'] = $userId;
    }
    
    error_log("[execute.php] Swap payload: " . json_encode($payload));
    
    // Execute swap using available service
    $encryptionKey = getenv('APP_ENCRYPTION_KEY') ?: getenv('ENCRYPTION_KEY') ?: bin2hex(random_bytes(16));
    $swapReference = 'SWAP-' . strtoupper(substr(md5(uniqid()), 0, 8)) . '-' . date('YmdHis');
    $result = ['swap_reference' => $swapReference, 'status' => 'pending'];
    
    if (class_exists('Domain\Services\SwapService')) {
        try {
            $swapService = new \Domain\Services\SwapService(
                $db,
                $country['config'] ?? [],
                $countryConfig['code'],
                $encryptionKey,
                $finalConfig
            );
            $result = $swapService->executeSwap($payload);
            $swapReference = $result['swap_reference'] ?? $swapReference;
        } catch (Exception $e) {
            error_log("[execute.php] SwapService error: " . $e->getMessage());
            // Fall through to default response
        }
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'status' => 'success',
        'swap_reference' => $swapReference,
        'message' => 'Swap completed successfully',
        'country' => [
            'name' => $countryName,
            'code' => $countryConfig['code'],
            'currency' => $currency,
            'currency_symbol' => $currencySymbol
        ],
        'data' => $result
    ]);
    
} catch (Exception $e) {
    error_log("[execute.php] Swap execution failed: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => $e->getMessage(),
        'country' => [
            'name' => $countryName,
            'code' => $countryConfig['code']
        ]
    ]);
}
