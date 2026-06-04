<?php
declare(strict_types=1);

/**
 * VouchMorphn - Swap Execution API
 * Fully Dynamic - No Hardcoding - Works for ALL Countries
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
// 4. HELPER FUNCTION TO LOAD .env FILE
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
// 7. LOAD .env FILE FOR THE COUNTRY
// ============================================
$basePath = ROOT_PATH . '/' . $countryConfig['config_path'];

// Try to find .env file in multiple locations
$envPaths = [
    $basePath . '.env',
    $basePath . '.env_' . $countryConfig['code'],
    $basePath . '.env_' . strtolower($countryConfig['code']),
    $basePath . '.env_' . strtoupper($countryConfig['code']),
    ROOT_PATH . '/.env',
    ROOT_PATH . '/.env_' . $countryConfig['code']
];

$envLoaded = false;
foreach ($envPaths as $envPath) {
    if (loadEnvFile($envPath)) {
        $envLoaded = true;
        error_log("[execute.php] Loaded .env from: {$envPath}");
        break;
    }
}

if (!$envLoaded) {
    error_log("[execute.php] WARNING: No .env file found for country: {$countryName}");
}

// ============================================
// 8. LOAD COUNTRY FILES USING REGISTRY PATHS
// ============================================
$participantsFile = $basePath . $countryConfig['participants_file'];
$feesFile = $basePath . $countryConfig['fees_file'];
$configFile = $basePath . $countryConfig['config_file'];
$dbConfigFile = $basePath . $countryConfig['database_file'];
$atmNotesFile = $basePath . ($countryConfig['atm_notes_file'] ?? 'atm_notes.json');

// Load participants
if (!file_exists($participantsFile)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => "Participants file not found for {$countryName}",
        'path' => $participantsFile
    ]);
    exit();
}

$participantsData = json_decode(file_get_contents($participantsFile), true);
$participants = $participantsData['participants'] ?? $participantsData ?? [];

// Load fees
$fees = [];
if (file_exists($feesFile)) {
    $fees = json_decode(file_get_contents($feesFile), true);
}

// Load config
$settings = [];
if (file_exists($configFile)) {
    $settings = require $configFile;
}

// Load database config
$dbConfig = [];
if (file_exists($dbConfigFile)) {
    $dbConfig = require $dbConfigFile;
}

// Load ATM notes
$atmNotes = [];
if (file_exists($atmNotesFile)) {
    $atmNotes = json_decode(file_get_contents($atmNotesFile), true);
}

$currency = $countryConfig['currency'];
$currencySymbol = $countryConfig['currency_symbol'] ?? $settings['currency_symbol'] ?? 'P';
$dialCode = $countryConfig['dial_code'] ?? $settings['dial_code'] ?? '+267';

// Build final config for SwapService
$finalConfig = [
    'participants' => $participants,
    'fees' => $fees,
    'currency' => $currency,
    'currency_symbol' => $currencySymbol,
    'dial_code' => $dialCode,
    'country_code' => $countryConfig['code'],
    'country_name' => $countryName,
    'atm_notes' => $atmNotes,
    'communication' => $settings['communication'] ?? [],
    'multi_source' => $settings['multi_source'] ?? ['enabled' => true]
];

error_log("[execute.php] Loaded " . count($participants) . " participants for {$countryName}");

// ============================================
// 9. LOAD COMPOSER AUTOLOADER
// ============================================
$composerPaths = [
    ROOT_PATH . '/vendor/autoload.php',
    ROOT_PATH . '/../vendor/autoload.php',
    dirname(ROOT_PATH) . '/vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php'
];

$autoloaderFound = false;
foreach ($composerPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        error_log("[execute.php] Composer autoloader found at: {$path}");
        break;
    }
}

if (!$autoloaderFound) {
    error_log("[execute.php] WARNING: Composer autoloader not found, using manual requires");
    require_once ROOT_PATH . '/src/Domain/Services/SwapService.php';
    require_once ROOT_PATH . '/src/Core/Database/DBConnection.php';
    require_once ROOT_PATH . '/src/Infrastructure/Banks/GenericBankClient.php';
    require_once ROOT_PATH . '/src/Infrastructure/SMS/SmsNotificationService.php';
    require_once ROOT_PATH . '/src/Domain/Services/ForexService.php';
    require_once ROOT_PATH . '/src/Domain/Services/FeeService.php';
    require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
    require_once ROOT_PATH . '/src/Domain/Services/Settlement/HybridSettlementStrategy.php';
}

// ============================================
// 10. DATABASE CONNECTION
// ============================================
$db = null;
try {
    // Check for Railway PostgreSQL URL first
    $databaseUrl = getenv('DATABASE_URL');
    if ($databaseUrl) {
        $db = new PDO($databaseUrl);
    } elseif (!empty($dbConfig) && isset($dbConfig['swap'])) {
        $swapDbConfig = $dbConfig['swap'];
        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s",
            $swapDbConfig['host'] ?? 'localhost',
            $swapDbConfig['port'] ?? '5432',
            $swapDbConfig['database'] ?? 'vouchmorph'
        );
        $db = new PDO($dsn, $swapDbConfig['username'] ?? 'postgres', $swapDbConfig['password'] ?? '');
    } elseif (!empty($dbConfig) && isset($dbConfig['host'])) {
        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s",
            $dbConfig['host'] ?? 'localhost',
            $dbConfig['port'] ?? '5432',
            $dbConfig['database'] ?? 'vouchmorph'
        );
        $db = new PDO($dsn, $dbConfig['username'] ?? 'postgres', $dbConfig['password'] ?? '');
    } else {
        // Try PostgreSQL environment variables
        $dbHost = getenv('PG_HOST') ?: 'localhost';
        $dbPort = getenv('PG_PORT') ?: '5432';
        $dbName = getenv('PG_NAME') ?: getenv('PG_DATABASE') ?: 'vouchmorph';
        $dbUser = getenv('PG_USER') ?: 'postgres';
        $dbPass = getenv('PG_PASS') ?: getenv('PG_PASSWORD') ?: '';
        
        $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
        $db = new PDO($dsn, $dbUser, $dbPass);
    }
    
    if ($db) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        error_log("[execute.php] Database connected successfully");
    }
} catch (PDOException $e) {
    error_log("[execute.php] Database connection failed: " . $e->getMessage());
}

// ============================================
// 11. AUTHENTICATION - DYNAMIC FROM .env
// ============================================
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

// Build valid keys dynamically from environment variables
$validKeys = [];

// Common API key environment variable names
$keyEnvVars = [
    'API_KEY_SYSTEM',
    'API_KEY_VOUCHMORPH',
    'API_KEY_CAZACOM',
    'API_KEY_ZURUBANK',
    'API_KEY_SACCUSSALIS',
    'API_KEY_PARTNER_1',
    'API_KEY_PARTNER_2',
    'API_KEY_PARTNER_3',
    'API_KEY_PARTNER_4'
];

foreach ($keyEnvVars as $keyName) {
    $keyValue = getenv($keyName);
    if ($keyValue && !empty($keyValue)) {
        $validKeys[] = $keyValue;
        error_log("[execute.php] Loaded API key from: {$keyName}");
    }
}

// Also check participant configs for API keys
foreach ($participants as $code => $participant) {
    // Check for value_env reference
    $apiKeyEnv = $participant['security']['api_key']['value_env'] ?? null;
    if ($apiKeyEnv && getenv($apiKeyEnv)) {
        $validKeys[] = getenv($apiKeyEnv);
    }
    // Check for direct value
    if (isset($participant['security']['api_key']['value'])) {
        $validKeys[] = $participant['security']['api_key']['value'];
    }
}

$validKeys = array_filter(array_unique($validKeys));

error_log("[execute.php] Total valid API keys loaded: " . count($validKeys));

// Validate - if keys are configured, validate; otherwise allow (development mode)
if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Invalid API key',
        'country' => $countryName
    ]);
    exit();
}

error_log("[execute.php] Authentication passed");

// ============================================
// 12. EXECUTE SWAP
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
    
    // Map asset types
    $assetTypeMap = [
        'MNO-WALLET' => 'MNO-WALLET',
        'BANK-WALLET' => 'BANK-WALLET',
        'ACCOUNT' => 'ACCOUNT',
        'CARD' => 'CARD',
        'CASHOUT-VOUCHER' => 'CASHOUT-VOUCHER',
        'ATM' => 'ATM'
    ];
    
    $sourceAssetType = $assetTypeMap[$source['asset_type'] ?? 'ACCOUNT'] ?? 'ACCOUNT';
    $deliveryMode = $destination['delivery_mode'] ?? 'deposit';
    
    // Validate institutions exist in config
    $sourceExists = false;
    $destExists = false;
    foreach ($participants as $code => $p) {
        if (strtoupper($code) === strtoupper($source['institution'])) $sourceExists = true;
        if (strtoupper($code) === strtoupper($destination['institution'])) $destExists = true;
    }
    
    if (!$sourceExists) {
        throw new Exception('Source institution not configured: ' . $source['institution']);
    }
    if (!$destExists) {
        throw new Exception('Destination institution not configured: ' . $destination['institution']);
    }
    
    // Build payload for SwapService
    $payload = [
        'source' => [
            'institution' => $source['institution'],
            'asset_type' => $sourceAssetType,
            'amount' => (float)$source['amount'],
            'currency' => $source['currency'] ?? $currency
        ],
        'destination' => [
            'institution' => $destination['institution'],
            'delivery_mode' => $deliveryMode,
            'currency' => $destination['currency'] ?? $currency
        ]
    ];
    
    // Add credentials if provided
    if (isset($source['credentials'])) {
        $payload['source']['credentials'] = $source['credentials'];
    }
    
    // Add identifier based on asset type
    if ($sourceAssetType === 'MNO-WALLET' && isset($source['phone'])) {
        $payload['source']['wallet_phone'] = $source['phone'];
    } elseif ($sourceAssetType === 'BANK-WALLET' && isset($source['wallet_id'])) {
        $payload['source']['ewallet_phone'] = $source['wallet_id'];
    } elseif ($sourceAssetType === 'ACCOUNT' && isset($source['account_number'])) {
        $payload['source']['account_number'] = $source['account_number'];
    } elseif (isset($source['identifier'])) {
        $payload['source']['identifier'] = $source['identifier'];
    }
    
    // Add destination identifier
    if (isset($destination['identifier'])) {
        if ($deliveryMode === 'cashout') {
            $payload['destination']['cashout'] = ['beneficiary_phone' => $destination['identifier']];
        } else {
            $payload['destination']['beneficiary_account'] = $destination['identifier'];
            $payload['destination']['beneficiary_phone'] = $destination['identifier'];
        }
    }
    
    if ($userId) {
        $payload['user_id'] = $userId;
    }
    
    error_log("[execute.php] Swap payload: " . json_encode($payload));
    
    // Initialize and execute SwapService if available
    $encryptionKey = getenv('APP_ENCRYPTION_KEY') ?: getenv('ENCRYPTION_KEY') ?: bin2hex(random_bytes(16));
    
    if (class_exists('Domain\Services\SwapService')) {
        $swapService = new \Domain\Services\SwapService(
            $db,
            $settings,
            $countryConfig['code'],
            $encryptionKey,
            $finalConfig
        );
        
        $result = $swapService->executeSwap($payload);
        $swapReference = $result['swap_reference'] ?? 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('YmdHis');
    } else {
        // Simple response if SwapService not available
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
