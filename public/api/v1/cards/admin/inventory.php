<?php
declare(strict_types=1);

/**
 * VouchMorph - Admin: Card Inventory View
 */

define('ROOT_PATH', dirname(__DIR__, 5));

// ============================================
// BOOTSTRAP - Load all dependencies (SAME AS batch_create.php)
// ============================================
$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Core/Config/SystemCountry.php';
require_once ROOT_PATH . '/src/Core/Config/LoadCountry.php';
require_once ROOT_PATH . '/src/Core/Database/DBConnection.php';
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';

use Core\Database\DBConnection;

// ============================================
// HEADERS & AUTHENTICATION
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit();
}

// Load environment
$country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW';
$envFile = ROOT_PATH . "/src/Core/Config/Countries/{$country}/.env";
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

// Helper function for env vars
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
// DATABASE CONNECTION
// ============================================
try {
    $pdo = DBConnection::getConnection();
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection error']);
    exit();
}

// ============================================
// GET INVENTORY DATA
// ============================================
try {
    // Get batches summary
    $batches = $pdo->query("
        SELECT 
            batch_id,
            batch_reference,
            bin_prefix,
            card_scheme,
            quantity_produced,
            quantity_remaining,
            expiry_month || '/' || expiry_year as expiry,
            status,
            received_at
        FROM card_batches
        WHERE card_type = 'PHYSICAL'
        ORDER BY received_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get cards by status
    $cardsByStatus = $pdo->query("
        SELECT 
            lifecycle_status,
            COUNT(*) as count
        FROM message_cards
        WHERE card_category = 'PHYSICAL'
        GROUP BY lifecycle_status
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total inventory
    $totalInventory = 0;
    foreach ($cardsByStatus as $status) {
        $totalInventory += $status['count'];
    }
    
    // Get recent assignments (if any)
    $recentAssignments = [];
    try {
        $recentAssignments = $pdo->query("
            SELECT 
                mc.card_suffix,
                COALESCE(mc.cardholder_name, 'Unassigned') as cardholder_name,
                mc.lifecycle_status,
                mc.batch_assigned_at,
                u.phone
            FROM message_cards mc
            LEFT JOIN users u ON mc.user_id = u.user_id
            WHERE mc.batch_assigned_at IS NOT NULL
            ORDER BY mc.batch_assigned_at DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not have assignments yet, ignore
        $recentAssignments = [];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'batches' => $batches,
            'cards_by_status' => $cardsByStatus,
            'recent_assignments' => $recentAssignments,
            'total_inventory' => $totalInventory
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
