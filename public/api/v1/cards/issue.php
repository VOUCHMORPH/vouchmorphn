<?php
declare(strict_types=1);

/**
 * VouchMorphn - Card Issuance API
 * Country-specific API keys from .env_{countrycode}
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
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

// ============================================
// 3. BOOTSTRAP - Load container
// ============================================
$container = require_once ROOT_PATH . '/src/bootstrap.php';

// ============================================
// 4. LOAD SYSTEM CONFIG & CORE (FIXED PATHS)
// ============================================
require_once ROOT_PATH . '/src/Core/Config/SystemCountry.php';
require_once ROOT_PATH . '/src/Core/Config/LoadCountry.php';

$country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW';

// ============================================
// 5. LOAD REQUIRED CLASSES (FIXED PATHS)
// ============================================
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
require_once ROOT_PATH . '/src/Domain/Helpers/CardHelper.php';
require_once ROOT_PATH . '/src/Infrastructure/Cards/CardNumberGenerator.php';

use Domain\Services\CardService;
use Domain\Helpers\CardHelper;
use Infrastructure\Cards\CardNumberGenerator;

// ============================================
// 6. LOAD COUNTRY-SPECIFIC ENVIRONMENT VARIABLES
// ============================================
$envFile = ROOT_PATH . "/src/Core/Config/Countries/{$country}/.env_{$country}";
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('get_env_val')) {
    function get_env_val(string $key) {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? ($_SERVER[$key] ?? null);
        }
        return $val;
    }
}

// ============================================
// 7. AUTHENTICATION - No hardcoded fallback
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

$validKeys = array_filter([
    get_env_val('API_KEY_SYSTEM')
]);

$participantsFile = ROOT_PATH . "/src/Core/Config/Countries/{$country}/participants_{$country}.json";
if (file_exists($participantsFile)) {
    $participantsData = json_decode(file_get_contents($participantsFile), true);
    
    if (isset($participantsData['participants'])) {
        foreach ($participantsData['participants'] as $participantName => $participant) {
            $envKey = 'API_KEY_' . strtoupper($participantName);
            $keyValue = get_env_val($envKey);
            if ($keyValue) {
                $validKeys[] = $keyValue;
            }
            
            if (isset($participant['provider_code'])) {
                $providerEnvKey = 'API_KEY_' . strtoupper($participant['provider_code']);
                $providerKeyValue = get_env_val($providerEnvKey);
                if ($providerKeyValue) {
                    $validKeys[] = $providerKeyValue;
                }
            }
        }
    }
}

$validKeys = array_filter($validKeys);

if (!$providedKey || !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Invalid or missing API key',
        'debug' => ['country' => $country]
    ]);
    exit();
}

// ============================================
// 8. GET INPUT
// ============================================
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON payload: ' . json_last_error_msg()
    ]);
    exit();
}

if (empty($input['hold_reference'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'hold_reference is required'
    ]);
    exit();
}

// ============================================
// 9. DATABASE CONNECTION - from container
// ============================================
try {
    $pdo = $container->get(PDO::class);
    if (!$pdo) throw new Exception('Database connection failed');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection error'
    ]);
    exit();
}

// ============================================
// 10. LOAD COUNTRY-SPECIFIC CARD CONFIG
// ============================================
$config = [];
$cardConfigPath = ROOT_PATH . "/src/Core/Config/Countries/{$country}/card_config_{$country}.json";
if (file_exists($cardConfigPath)) {
    $config = json_decode(file_get_contents($cardConfigPath), true);
}

if (!isset($config['card_bin'])) {
    $defaultBins = [
        'BW' => '411111',
        'KE' => '511111',
        'NG' => '611111',
        'ZA' => '711111',
    ];
    $config['card_bin'] = $defaultBins[$country] ?? '411111';
}

// ============================================
// 11. EXECUTE CARD ISSUANCE
// ============================================
try {
    $cardService = new CardService($pdo, $country, $config);
    $result = $cardService->issueCard($input);
    
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Card issuance error for {$country}: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
