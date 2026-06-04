<?php
declare(strict_types=1);

/**
 * VouchMorphn - Swap Execution API
 * Fully Dynamic - Works for ALL Countries
 * Aligned with Domain\Services\SwapService
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
// 4. AUTO-DETECT OR GET COUNTRY
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);

// Try multiple header variations
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

$countryCode = $requestCountry ?? $bodyCountry;

// Debug logging
error_log("[execute.php] Country detection - Header: " . ($requestCountry ?? 'null') . ", Body: " . ($bodyCountry ?? 'null'));

// If no country provided, try to detect from participants config
if (!$countryCode) {
    $configBasePath = ROOT_PATH . '/src/Core/Config/Countries/';
    if (is_dir($configBasePath)) {
        $countries = array_filter(scandir($configBasePath), function($item) use ($configBasePath) {
            return $item !== '.' && $item !== '..' && is_dir($configBasePath . $item);
        });
        if (count($countries) === 1) {
            $countryCode = $countries[0];
            error_log("[execute.php] Auto-detected country: {$countryCode}");
        }
    }
}

// Default fallback for testing
if (!$countryCode) {
    $countryCode = 'Botswana';
    error_log("[execute.php] WARNING: No country provided, using default: {$countryCode}");
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
// 6. LOAD COUNTRY CONFIGURATION (DYNAMIC)
// ============================================
$configBasePath = ROOT_PATH . '/src/Core/Config/Countries/' . $countryCode . '/';

// Try different possible paths
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
        error_log("[execute.php] Config path found: {$path}");
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

// Load participants.json
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
        error_log("[execute.php] Participants file found: {$pattern}");
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

// Load config.php
$configPhpFile = $configPath . 'config.php';
$settings = [];
if (file_exists($configPhpFile)) {
    $settings = require $configPhpFile;
}

// Load database.php
$dbConfigFile = $configPath . 'database.php';
$dbConfig = [];
if (file_exists($dbConfigFile)) {
    $dbConfig = require $dbConfigFile;
}

// Load ATM notes if exists
$atmNotesFile = $configPath . 'atm_notes.json';
$atmNotes = [];
if (file_exists($atmNotesFile)) {
    $atmNotes = json_decode(file_get_contents($atmNotesFile), true);
}

// Build final config for SwapService
$finalConfig = [
    'participants' => $participants,
    'fees' => $fees,
    'currency' => $settings['currency'] ?? $fees['currency'] ?? 'BWP',
    'currency_symbol' => $settings['currency_symbol'] ?? $fees['currency_symbol'] ?? 'P',
    'dial_code' => $settings['dial_code'] ?? '+267',
    'country_code' => $countryCode,
    'country_name' => $settings['name'] ?? $countryCode,
    'atm_notes' => $atmNotes,
    'communication' => $settings['communication'] ?? [],
    'multi_source' => $settings['multi_source'] ?? ['enabled' => true]
];

error_log("[execute.php] Loaded " . count($participants) . " participants for {$countryCode}");

// ============================================
// 7. DATABASE CONNECTION
// ============================================
$db = null;
try {
    if (!empty($dbConfig) && isset($dbConfig['swap'])) {
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
        // Try environment variables
        $dbHost = getenv('PG_HOST') ?: 'localhost';
        $dbPort = getenv('PG_PORT') ?: '5432';
        $dbName = getenv('PG_DATABASE') ?: 'vouchmorph';
        $dbUser = getenv('PG_USER') ?: 'postgres';
        $dbPass = getenv('PG_PASSWORD') ?: '';
        
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
    // Continue without database for testing
}

// ============================================
// 8. AUTHENTICATION
// ============================================
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

$validKeys = [];
if (getenv('API_KEY_SYSTEM')) $validKeys[] = getenv('API_KEY_SYSTEM');
if (getenv('API_KEY_VOUCHMORPH')) $validKeys[] = getenv('API_KEY_VOUCHMORPH');

foreach ($participants as $code => $participant) {
    $apiKeyEnv = $participant['security']['api_key']['value_env'] ?? null;
    if ($apiKeyEnv && getenv($apiKeyEnv)) {
        $validKeys[] = getenv($apiKeyEnv);
    }
    if (isset($participant['security']['api_key']['value'])) {
        $validKeys[] = $participant['security']['api_key']['value'];
    }
}

$validKeys = array_filter($validKeys);

// Skip authentication if no keys configured (development mode)
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
// 9. EXECUTE SWAP USING SwapService
// ============================================
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON payload');
    }
    
    // Extract swap parameters
    $source = $input['source'] ?? [];
    $destination = $input['destination'] ?? [];
    $userId = $input['user_id'] ?? $input['userId'] ?? null;
    
    if (empty($source)) throw new Exception('Source information required');
    if (empty($destination)) throw new Exception('Destination information required');
    if (empty($source['amount']) || $source['amount'] <= 0) throw new Exception('Valid amount required');
    if (empty($source['institution'])) throw new Exception('Source institution required');
    if (empty($destination['institution'])) throw new Exception('Destination institution required');
    
    // Map asset types to match SwapService expectations
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
    
    // Build payload for SwapService
    $payload = [
        'source' => [
            'institution' => $source['institution'],
            'asset_type' => $sourceAssetType,
            'amount' => (float)$source['amount'],
            'currency' => $source['currency'] ?? $finalConfig['currency']
        ],
        'destination' => [
            'institution' => $destination['institution'],
            'delivery_mode' => $deliveryMode,
            'currency' => $destination['currency'] ?? $finalConfig['currency']
        ]
    ];
    
    // Add credentials if provided (for ad-hoc swaps)
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
    
    // Add user_id if available
    if ($userId) {
        $payload['user_id'] = $userId;
    }
    
    error_log("[execute.php] Swap payload: " . json_encode($payload));
    
    // Initialize and execute SwapService
    $encryptionKey = getenv('ENCRYPTION_KEY') ?: getenv('APP_ENCRYPTION_KEY') ?: bin2hex(random_bytes(16));
    
    $swapService = new \Domain\Services\SwapService(
        $db,
        $settings,
        $countryCode,
        $encryptionKey,
        $finalConfig
    );
    
    $result = $swapService->executeSwap($payload);
    
    $swapReference = $result['swap_reference'] ?? 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('YmdHis');
    
    echo json_encode([
        'success' => true,
        'status' => 'success',
        'swap_reference' => $swapReference,
        'message' => 'Swap completed successfully',
        'country' => $countryCode,
        'data' => $result
    ]);
    
} catch (Exception $e) {
    error_log("[execute.php] Swap execution failed: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => $e->getMessage(),
        'country' => $countryCode,
        'trace' => getenv('APP_DEBUG') === 'true' ? $e->getTraceAsString() : null
    ]);
}
