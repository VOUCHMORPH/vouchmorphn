<?php
declare(strict_types=1);

/**
 * VouchMorph - Card Shipping Tracking API
 */

define('ROOT_PATH', dirname(__DIR__, 4));

// ============================================
// BOOTSTRAP - Load container
// ============================================
$container = require_once ROOT_PATH . '/src/bootstrap.php';

// ============================================
// HEADERS & CORS
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
// LOAD SYSTEM CONFIG & CORE (FIXED PATHS)
// ============================================
require_once ROOT_PATH . '/src/Core/Config/SystemCountry.php';
require_once ROOT_PATH . '/src/Core/Config/LoadCountry.php';

$country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW';

// ============================================
// LOAD REQUIRED CLASSES
// ============================================
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
use Domain\Services\CardService;

// ============================================
// LOAD ENVIRONMENT
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
// AUTHENTICATION
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
// GET PARAMETERS
// ============================================
$cardId = $_GET['id'] ?? $_GET['card_suffix'] ?? '';
if (!$cardId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Card ID or suffix required']);
    exit();
}

// ============================================
// DATABASE CONNECTION - from container
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
// FETCH CARD SHIPPING INFO
// ============================================
try {
    $sql = is_numeric($cardId) 
        ? "SELECT * FROM message_cards WHERE card_id = ?"
        : "SELECT * FROM message_cards WHERE card_suffix = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$card || $card['card_category'] !== 'PHYSICAL') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Physical card not found']);
        exit();
    }
    
    // Calculate estimated delivery
    $estimatedDelivery = null;
    if ($card['delivery_status'] === 'shipped' && !$card['delivered_at']) {
        $shippedDate = new DateTime($card['shipped_at'] ?? 'now');
        $estimatedDelivery = $shippedDate->modify('+3 days')->format('Y-m-d');
    }
    
    $response = [
        'success' => true,
        'card_suffix' => $card['card_suffix'],
        'status' => $card['lifecycle_status'],
        'delivery_status' => $card['delivery_status'] ?? 'pending',
        'tracking_number' => $card['tracking_number'] ?? null,
        'estimated_delivery' => $estimatedDelivery
    ];
    
    if ($card['delivered_at']) {
        $response['delivered_at'] = $card['delivered_at'];
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
