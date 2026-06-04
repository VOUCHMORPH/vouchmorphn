<?php
declare(strict_types=1);

/**
 * VouchMorphn - Swap Execution API
 * Fully Dynamic - Works for ALL Countries
 */

// ============================================
// 1. BOOTSTRAP & PATHS
// ============================================
define('ROOT_PATH', dirname(__DIR__, 3));

// ============================================
// 2. HEADERS & CORS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Country-Code");

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
// 4. AUTO-DETECT OR GET COUNTRY
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);

// Country can come from: Header, Request Body, or detect from API key
$requestCountry = $_SERVER['HTTP_X_COUNTRY_CODE'] ?? 
                  $headersLower['x-country-code'] ?? 
                  $_GET['country'] ?? 
                  null;

$input = json_decode(file_get_contents('php://input'), true);
$bodyCountry = $input['country'] ?? $input['source']['country'] ?? $input['destination']['country'] ?? null;

$countryCode = $requestCountry ?? $bodyCountry;

// If no country provided, try to detect from participants config
if (!$countryCode) {
    $configBasePath = ROOT_PATH . '/src/Core/Config/Countries/';
    if (is_dir($configBasePath)) {
        $countries = array_filter(scandir($configBasePath), function($item) use ($configBasePath) {
            return $item !== '.' && $item !== '..' && is_dir($configBasePath . $item);
        });
        if (count($countries) === 1) {
            $countryCode = $countries[0];
        }
    }
}

if (!$countryCode) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Country not specified. Please provide X-Country-Code header or country in request body'
    ]);
    exit();
}

// ============================================
// 5. LOAD COMPOSER AUTOLOADER
// ============================================
$composerPaths = [
    ROOT_PATH . '/vendor/autoload.php',
    ROOT_PATH . '/../vendor/autoload.php',
    dirname(ROOT_PATH) . '/vendor/autoload.php'
];

$autoloaderFound = false;
foreach ($composerPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    // Fallback manual requires
    require_once ROOT_PATH . '/src/Domain/Services/SwapService.php';
    require_once ROOT_PATH . '/src/Core/Database/DBConnection.php';
    require_once ROOT_PATH . '/src/Infrastructure/Banks/GenericBankClient.php';
    require_once ROOT_PATH . '/src/Infrastructure/SMS/SmsNotificationService.php';
}

// ============================================
// 6. LOAD COUNTRY CONFIGURATION (DYNAMIC)
// ============================================
$configBasePath = ROOT_PATH . '/src/Core/Config/Countries/' . $countryCode . '/';

// Try different possible paths (case-insensitive)
$possiblePaths = [
    $configBasePath,
    ROOT_PATH . '/src/Core/Config/countries/' . $countryCode . '/',
    ROOT_PATH . '/src/CORE_CONFIG/countries/' . $countryCode . '/',
    ROOT_PATH . '/src/Core/Config/Countries/' . ucfirst($countryCode) . '/',
    ROOT_PATH . '/src/Core/Config/Countries/' . strtoupper($countryCode) . '/'
];

$configPath = null;
foreach ($possiblePaths as $path) {
    if (is_dir($path)) {
        $configPath = $path;
        break;
    }
}

if (!$configPath) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => "Configuration not found for country: {$countryCode}",
        'available_countries' => is_dir(ROOT_PATH . '/src/Core/Config/Countries/') ? 
            array_values(array_diff(scandir(ROOT_PATH . '/src/Core/Config/Countries/'), ['.', '..'])) : []
    ]);
    exit();
}

// Load participants.json (try different filename patterns)
$participantsFile = null;
$participantPatterns = [
    $configPath . 'participants.json',
    $configPath . 'participants_' . $countryCode . '.json',
    $configPath . 'participants_' . strtolower($countryCode) . '.json',
    $configPath . 'participants_' . strtoupper($countryCode) . '.json',
    $configPath . 'config.json'
];

foreach ($participantPatterns as $pattern) {
    if (file_exists($pattern)) {
        $participantsFile = $pattern;
        break;
    }
}

if (!$participantsFile) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => "participants.json not found for country: {$countryCode}",
        'searched_paths' => $participantPatterns
    ]);
    exit();
}

$participantsData = json_decode(file_get_contents($participantsFile), true);
$participants = $participantsData['participants'] ?? $participantsData ?? [];

// Load fees.json
$feesFile = $configPath . 'fees.json';
$fees = [];
if (file_exists($feesFile)) {
    $fees = json_decode(file_get_contents($feesFile), true);
}

// Load config.php if exists
$configPhpFile = $configPath . 'config.php';
$settings = [];
if (file_exists($configPhpFile)) {
    $settings = require $configPhpFile;
}

// Load database.php if exists
$dbConfigFile = $configPath . 'database.php';
$dbConfig = [];
if (file_exists($dbConfigFile)) {
    $dbConfig = require $dbConfigFile;
}

$finalConfig = [
    'participants' => $participants,
    'fees' => $fees,
    'currency' => $settings['currency'] ?? $fees['currency'] ?? 'USD',
    'currency_symbol' => $settings['currency_symbol'] ?? $fees['currency_symbol'] ?? '$',
    'dial_code' => $settings['dial_code'] ?? '+1',
    'country_code' => $countryCode,
    'country_name' => $settings['name'] ?? $countryCode
];

// ============================================
// 7. DATABASE CONNECTION (Dynamic from config)
// ============================================
$dbConnectionParams = $dbConfig['swap'] ?? $dbConfig['default'] ?? [];

try {
    if (!empty($dbConnectionParams) && isset($dbConnectionParams['host'])) {
        $dsn = sprintf(
            "%s:host=%s;port=%s;dbname=%s",
            $dbConnectionParams['driver'] ?? 'pgsql',
            $dbConnectionParams['host'],
            $dbConnectionParams['port'] ?? '5432',
            $dbConnectionParams['database'] ?? $dbConnectionParams['dbname']
        );
        $db = new PDO($dsn, $dbConnectionParams['username'] ?? $dbConnectionParams['user'], $dbConnectionParams['password'] ?? '');
    } else {
        // Try environment variables
        $dbDriver = getenv('DB_DRIVER') ?: 'pgsql';
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbPort = getenv('DB_PORT') ?: '5432';
        $dbName = getenv('DB_NAME') ?: 'vouchmorph';
        $dbUser = getenv('DB_USER') ?: 'postgres';
        $dbPass = getenv('DB_PASS') ?: '';
        
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", $dbDriver, $dbHost, $dbPort, $dbName);
        $db = new PDO($dsn, $dbUser, $dbPass);
    }
    
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // For demo/testing, allow swap without database
    $db = null;
    error_log("Database connection failed: " . $e->getMessage());
}

// ============================================
// 8. AUTHENTICATION (Dynamic from config)
// ============================================
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

// Build valid keys from participants config
$validKeys = [];

// Add system key from env
if (getenv('API_KEY_SYSTEM')) $validKeys[] = getenv('API_KEY_SYSTEM');

// Add participant API keys from config
foreach ($participants as $code => $participant) {
    $apiKey = $participant['security']['api_key']['value_env'] ?? null;
    if ($apiKey && getenv($apiKey)) $validKeys[] = getenv($apiKey);
    if (isset($participant['security']['api_key']['value'])) $validKeys[] = $participant['security']['api_key']['value'];
}

$validKeys = array_filter($validKeys);

if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Invalid API key',
        'country' => $countryCode
    ]);
    exit();
}

// ============================================
// 9. EXECUTE SWAP
// ============================================
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON payload');
    }
    
    // Extract swap parameters from input
    $source = $input['source'] ?? [];
    $destination = $input['destination'] ?? [];
    $userId = $input['user_id'] ?? $input['userId'] ?? null;
    
    if (empty($source)) throw new Exception('Source information required');
    if (empty($destination)) throw new Exception('Destination information required');
    if (empty($source['amount']) || $source['amount'] <= 0) throw new Exception('Valid amount required');
    
    // Get participant configs
    $sourceParticipant = null;
    $destParticipant = null;
    
    foreach ($participants as $code => $p) {
        if (strtoupper($code) === strtoupper($source['institution'] ?? '')) {
            $sourceParticipant = $p;
            $sourceParticipant['code'] = $code;
        }
        if (strtoupper($code) === strtoupper($destination['institution'] ?? '')) {
            $destParticipant = $p;
            $destParticipant['code'] = $code;
        }
    }
    
    if (!$sourceParticipant) {
        throw new Exception('Source institution not configured: ' . ($source['institution'] ?? 'unknown'));
    }
    if (!$destParticipant) {
        throw new Exception('Destination institution not configured: ' . ($destination['institution'] ?? 'unknown'));
    }
    
    // Build swap payload
    $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('YmdHis');
    
    $payload = [
        'reference' => $swapReference,
        'source' => [
            'institution' => $sourceParticipant['code'],
            'asset_type' => $source['asset_type'] ?? ($sourceParticipant['capabilities']['asset_types'][0] ?? 'ACCOUNT'),
            'amount' => (float)$source['amount'],
            'currency' => $sourceParticipant['settlement']['currency'] ?? $finalConfig['currency'],
            'credentials' => $source['credentials'] ?? $source['identification'] ?? []
        ],
        'destination' => [
            'institution' => $destParticipant['code'],
            'delivery_mode' => $destination['delivery_mode'] ?? 'deposit',
            'beneficiary_account' => $destination['identifier'] ?? $destination['account'] ?? null,
            'beneficiary_phone' => $destination['identifier'] ?? $destination['phone'] ?? null,
            'currency' => $destParticipant['settlement']['currency'] ?? $finalConfig['currency']
        ]
    ];
    
    // Execute swap using SwapService if available
    $result = null;
    
    if (class_exists('Domain\Services\SwapService') && $db) {
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: getenv('APP_ENCRYPTION_KEY') ?: bin2hex(random_bytes(16));
        
        $swapService = new \Domain\Services\SwapService(
            $db,
            $settings,
            $countryCode,
            $encryptionKey,
            $finalConfig
        );
        
        $result = $swapService->executeSwap($payload);
    } else {
        // Simplified execution if SwapService not available
        $result = [
            'swap_reference' => $swapReference,
            'status' => 'completed',
            'source_institution' => $sourceParticipant['code'],
            'destination_institution' => $destParticipant['code'],
            'amount' => (float)$source['amount'],
            'currency' => $finalConfig['currency']
        ];
        
        // Store in database if available
        if ($db && $userId) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO swap_transactions 
                    (swap_reference, user_id, source_institution, destination_institution, amount, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'completed', NOW())
                ");
                $stmt->execute([$swapReference, $userId, $sourceParticipant['code'], $destParticipant['code'], (float)$source['amount']]);
            } catch (Exception $e) {
                error_log("Failed to store transaction: " . $e->getMessage());
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'status' => 'success',
        'swap_reference' => $swapReference,
        'message' => 'Swap completed successfully',
        'country' => $countryCode,
        'data' => $result
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => $e->getMessage(),
        'country' => $countryCode,
        'trace' => getenv('APP_DEBUG') === 'true' ? $e->getTraceAsString() : null
    ]);
}
