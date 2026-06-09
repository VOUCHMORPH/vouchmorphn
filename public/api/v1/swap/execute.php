<?php
declare(strict_types=1);

/**
 * VouchMorphn - Swap Execution API
 * Routes requests to SwapService - No business logic here
 */

// ============================================
// 1. BOOTSTRAP
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

// ============================================
// 2. HEADERS
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
// 4. LOAD AUTOLOADER
// ============================================
$composerPaths = [
    ROOT_PATH . '/vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php'
];

$loaded = false;
foreach ($composerPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    // Manual requires if composer not found
    require_once ROOT_PATH . '/src/Domain/Services/SwapService.php';
    require_once ROOT_PATH . '/src/Domain/Services/ForexService.php';
    require_once ROOT_PATH . '/src/Domain/Services/FeeService.php';
    require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
    require_once ROOT_PATH . '/src/Domain/Services/Settlement/HybridSettlementStrategy.php';
    require_once ROOT_PATH . '/src/Infrastructure/Banks/GenericBankClient.php';
    require_once ROOT_PATH . '/src/Infrastructure/SMS/SmsNotificationService.php';
}

// ============================================
// 5. GET COUNTRY FROM REQUEST (no hardcoding)
// ============================================
$headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
$countryInput = $headers['x-country-code'] ?? $_GET['country'] ?? null;

if (!$countryInput) {
    $input = json_decode(file_get_contents('php://input'), true);
    $countryInput = $input['country'] ?? $input['source']['country'] ?? $input['destination']['country'] ?? null;
}

// Load registry to resolve country
$registryFile = ROOT_PATH . '/src/Core/Config/countries_registry.json';
if (!file_exists($registryFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Country registry not found']);
    exit();
}

$registry = json_decode(file_get_contents($registryFile), true);
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

if (!$countryConfig) {
    $default = $registry['default_country'] ?? array_key_first($registry['countries']);
    $countryConfig = $registry['countries'][$default];
    $countryName = $default;
}

// ============================================
// 6. LOAD VAULT SECRETS (Railway)
// ============================================
$databaseUrl = getenv('DATABASE_URL');
$pgHost = getenv('PG_HOST') ?: 'localhost';
$pgPort = getenv('PG_PORT') ?: '5432';
$pgUser = getenv('PG_USER') ?: 'postgres';
$pgPass = getenv('PG_PASSWORD') ?: getenv('PG_PASS') ?: '';
$pgDb = getenv('PG_DATABASE') ?: getenv('PG_NAME') ?: 'postgres';

$encryptionKey = getenv('ENCRYPTION_KEY') ?: getenv('APP_ENCRYPTION_KEY') ?: bin2hex(random_bytes(16));

// ============================================
// 7. DATABASE CONNECTION
// ============================================
$db = null;
try {
    if ($databaseUrl) {
        $db = new PDO($databaseUrl);
    } else {
        $dsn = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}";
        $db = new PDO($dsn, $pgUser, $pgPass);
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log("[execute.php] Database connected");
} catch (PDOException $e) {
    error_log("[execute.php] DB connection failed: " . $e->getMessage());
}

// ============================================
// 8. LOAD CONFIGURATION
// ============================================
$basePath = ROOT_PATH . '/' . $countryConfig['config_path'];

// Load settings from config.php
$settings = [];
$configFile = $basePath . '/' . ($countryConfig['config_file'] ?? 'config.php');
if (file_exists($configFile)) {
    $settings = require $configFile;
}

// Build config array for SwapService (matches what SwapService expects)
$swapConfig = [
    'participants' => [],  // Will be loaded by SwapService internally
    'endpoints' => [],     // Will be loaded by SwapService internally
    'fees' => [],          // Will be loaded by SwapService internally
    'currency' => $countryConfig['currency'],
    'currency_symbol' => $countryConfig['currency_symbol'] ?? 'P',
    'dial_code' => $countryConfig['dial_code'] ?? '+267',
    'country_code' => $countryConfig['code'],
    'country_name' => $countryName,
    'communication' => $settings['communication'] ?? [],
    'multi_source' => $settings['multi_source'] ?? ['enabled' => true]
];

// ============================================
// 9. AUTHENTICATE
// ============================================
$providedKey = $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

// Get valid keys from vault
$validKeys = [];
$systemKey = getenv('API_KEY_SYSTEM') ?: getenv('VOUCHMORPH_API_KEY');
if ($systemKey) $validKeys[] = $systemKey;

// Also check participant API keys from vault
$participantKeys = ['ZURUBANK_API_KEY', 'SACCUSSALIS_API_KEY', 'CAZACOM_API_KEY'];
foreach ($participantKeys as $key) {
    $val = getenv($key);
    if ($val) $validKeys[] = $val;
}

$validKeys = array_unique(array_filter($validKeys));

if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
    exit();
}

// ============================================
// 10. EXECUTE SWAP USING SWAPSERVICE
// ============================================
try {
    // Get request input (completely dynamic, no hardcoded values)
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON payload');
    }
    
    // Check if SwapService exists
    if (!class_exists('Domain\Services\SwapService')) {
        throw new Exception('SwapService not found. Please check autoloader.');
    }
    
    // Create SwapService instance
    $swapService = new \Domain\Services\SwapService(
        $db,
        $settings,
        $countryConfig['code'],
        $encryptionKey,
        $swapConfig
    );
    
    // Route to appropriate method based on request structure
    if (isset($input['sources']) && is_array($input['sources']) && count($input['sources']) > 1) {
        // Multi-source swap
        $result = $swapService->executeMultiSourceSwap($input);
    } else {
        // Single source swap - pass the exact request as received
        $result = $swapService->executeSwap($input);
    }
    
    // Return the result exactly as SwapService returns it
    echo json_encode(array_merge([
        'success' => true,
        'country' => $countryConfig['code']
    ], $result));
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'country' => $countryConfig['code']
    ]);
    error_log("[execute.php] Error: " . $e->getMessage());
}
