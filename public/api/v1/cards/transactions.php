<?php
declare(strict_types=1);

/**
 * VouchMorphn - Card Transactions API
 * Retrieves transaction history for a message card
 */

// ============================================
// 1. BOOTSTRAP & PATHS
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

// ============================================
// 2. HEADERS & CORS
// ============================================
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
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

use Domain\Services\CardService;
use Domain\Helpers\CardHelper;

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
// 8. GET QUERY PARAMETERS
// ============================================
$cardNumber = $_GET['card_number'] ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;

if (!$cardNumber) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'card_number parameter is required'
    ]);
    exit();
}

$cardNumber = preg_replace('/[^0-9]/', '', $cardNumber);

if (strlen($cardNumber) < 15 || strlen($cardNumber) > 19) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid card number format'
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
    error_log("Database connection error in transactions API: " . $e->getMessage());
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

// ============================================
// 11. EXECUTE CARD TRANSACTIONS QUERY
// ============================================
try {
    $cardService = new CardService($pdo, $country, $config);
    $result = $cardService->getCardTransactions($cardNumber, $limit);
    
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    error_log("Card transactions error for {$country} - card ending in " . substr($cardNumber, -4) . ": " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
