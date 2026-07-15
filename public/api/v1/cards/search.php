<?php
declare(strict_types=1);

/**
 * VouchMorphn - Card Search API
 * Search cards by phone number or card suffix
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
// 5. LOAD ENVIRONMENT
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
// 6. AUTHENTICATION - No hardcoded fallback
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
// 7. GET SEARCH PARAMETERS
// ============================================
$phone = $_GET['phone'] ?? '';
$suffix = $_GET['suffix'] ?? '';

if (!$phone && !$suffix) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Either phone or suffix parameter is required'
    ]);
    exit();
}

// ============================================
// 8. DATABASE CONNECTION - from container
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
// 9. SEARCH FOR CARDS
// ============================================
try {
    if ($phone) {
        $stmt = $pdo->prepare("
            SELECT 
                mc.card_id,
                mc.card_suffix,
                mc.cardholder_name,
                mc.lifecycle_status,
                mc.financial_status,
                mc.initial_amount,
                mc.remaining_amount,
                mc.expiry_month,
                mc.expiry_year,
                mc.batch_id,
                mc.batch_sequence,
                mc.activated_at,
                mc.batch_assigned_at,
                u.user_id,
                u.phone,
                u.email,
                u.username,
                ca.application_id,
                ca.id_number,
                ca.id_type
            FROM message_cards mc
            JOIN users u ON mc.user_id = u.user_id
            LEFT JOIN card_applications ca ON mc.card_id = ca.card_id
            WHERE u.phone = :phone
            ORDER BY mc.created_at DESC
        ");
        $stmt->execute([':phone' => $phone]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                mc.card_id,
                mc.card_suffix,
                mc.cardholder_name,
                mc.lifecycle_status,
                mc.financial_status,
                mc.initial_amount,
                mc.remaining_amount,
                mc.expiry_month,
                mc.expiry_year,
                mc.batch_id,
                mc.batch_sequence,
                mc.activated_at,
                mc.batch_assigned_at,
                u.user_id,
                u.phone,
                u.email,
                u.username,
                ca.application_id,
                ca.id_number,
                ca.id_type
            FROM message_cards mc
            LEFT JOIN users u ON mc.user_id = u.user_id
            LEFT JOIN card_applications ca ON mc.card_id = ca.card_id
            WHERE mc.card_suffix = :suffix
        ");
        $stmt->execute([':suffix' => $suffix]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (empty($cards)) {
        echo json_encode([
            'success' => true,
            'found' => false,
            'message' => $phone ? "No cards found for phone: $phone" : "No card found with suffix: $suffix"
        ], JSON_PRETTY_PRINT);
        exit();
    }
    
    foreach ($cards as &$card) {
        $card['expiry'] = sprintf("%02d/%d", $card['expiry_month'], $card['expiry_year']);
        $card['balance'] = (float)$card['remaining_amount'];
        unset($card['expiry_month'], $card['expiry_year'], $card['remaining_amount']);
    }
    
    echo json_encode([
        'success' => true,
        'found' => true,
        'count' => count($cards),
        'cards' => $cards
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
