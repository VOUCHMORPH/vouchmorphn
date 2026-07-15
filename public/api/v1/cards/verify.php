<?php
declare(strict_types=1);

/**
 * VouchMorphn - Card Verification API
 * Verifies card authorizations (message-based cards)
 */

// ============================================
// 1. BOOTSTRAP & PATHS
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

// ============================================
// 2. HEADERS
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Correlation-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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

use Domain\Services\CardService;

// ============================================
// 6. LOAD ENVIRONMENT
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
// 7. AUTHENTICATION - NO HARDCODED FALLBACK
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

$validKeys = array_filter([get_env_val('API_KEY_SYSTEM')]);
if (!$providedKey || !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// ============================================
// 8. GET INPUT
// ============================================
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_GET;
}

// ============================================
// 9. DATABASE CONNECTION - from container
// ============================================
try {
    $pdo = $container->get(PDO::class);
    if (!$pdo) throw new Exception('Database connection failed');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
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
// 11. EXECUTE VERIFICATION
// ============================================
try {
    $cardService = new CardService($pdo, $country, $config);
    
    $assetType = $input['asset_type'] ?? '';
    $amount = (float)($input['amount'] ?? 0);
    $reference = $input['reference'] ?? '';
    
    if ($assetType === 'CARD') {
        $cardSuffix = $input['card']['card_suffix'] ?? 
                     $input['card_suffix'] ?? 
                     $input['card_number'] ?? null;
        
        if (!$cardSuffix) {
            throw new Exception("Card identifier required");
        }
        
        $cardInfo = $cardService->getCardAuthorization($cardSuffix);
        
        if ($cardInfo['remaining_balance'] < $amount) {
            echo json_encode([
                'verified' => false,
                'message' => 'Insufficient authorization on card',
                'available' => $cardInfo['remaining_balance'],
                'requested' => $amount
            ]);
            exit;
        }
        
        echo json_encode([
            'verified' => true,
            'asset_id' => $cardInfo['authorization_id'],
            'asset_type' => 'CARD_AUTHORIZATION',
            'available_balance' => $cardInfo['remaining_balance'],
            'holder_name' => $cardInfo['cardholder_name'],
            'hold_reference' => $cardInfo['hold_reference'],
            'is_message' => true,
            'metadata' => [
                'card_suffix' => $cardSuffix,
                'authorized_amount' => $cardInfo['authorized_amount'],
                'expiry' => $cardInfo['expiry']
            ]
        ]);
        
    } elseif ($assetType === 'E-WALLET' || $assetType === 'ACCOUNT') {
        echo json_encode([
            'verified' => false,
            'message' => 'E-WALLET verification not implemented in this endpoint'
        ]);
        
    } else {
        throw new Exception("Unsupported asset type: {$assetType}");
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'verified' => false,
        'error' => $e->getMessage()
    ]);
}
