<?php
declare(strict_types=1);

/**
 * VouchMorphn - Card Balance API
 * Retrieves current balance for a message card
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
// 5. LOAD REQUIRED CLASSES (fixed paths)
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
// 7. AUTHENTICATION - No hardcoded fallback
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
// 8. GET CARD IDENTIFIER
// ============================================
$cardNumber = $_GET['card_number'] ?? '';
$cardSuffix = $_GET['card_suffix'] ?? '';

if (!$cardNumber && !$cardSuffix) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Either card_number or card_suffix parameter is required'
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
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit();
}

// ============================================
// 10. FETCH CARD BALANCE
// ============================================
try {
    if ($cardSuffix) {
        $stmt = $pdo->prepare("
            SELECT 
                mc.card_id,
                mc.card_suffix,
                mc.cardholder_name,
                mc.remaining_amount as balance,
                'BWP' as currency,
                mc.lifecycle_status,
                mc.financial_status,
                mc.expiry_month,
                mc.expiry_year,
                mc.initial_amount,
                mc.activated_at,
                mc.last_used_at,
                COALESCE(ht.hold_reference, 'No active hold') as hold_reference,
                COALESCE(ht.source_institution, 'Not funded') as source_institution,
                (
                    SELECT COUNT(*) 
                    FROM card_transactions ct 
                    WHERE ct.card_id = mc.card_id AND ct.auth_status = 'APPROVED'
                ) as transaction_count,
                (
                    SELECT COALESCE(SUM(amount), 0)
                    FROM card_transactions ct 
                    WHERE ct.card_id = mc.card_id AND ct.auth_status = 'APPROVED'
                ) as total_spent
            FROM message_cards mc
            LEFT JOIN hold_transactions ht ON mc.hold_reference = ht.hold_reference
            WHERE mc.card_suffix = :suffix
        ");
        $stmt->execute([':suffix' => $cardSuffix]);
    } else {
        $cardNumberHash = hash('sha256', $cardNumber);
        $stmt = $pdo->prepare("
            SELECT 
                mc.card_id,
                mc.card_suffix,
                mc.cardholder_name,
                mc.remaining_amount as balance,
                'BWP' as currency,
                mc.lifecycle_status,
                mc.financial_status,
                mc.expiry_month,
                mc.expiry_year,
                mc.initial_amount,
                mc.activated_at,
                mc.last_used_at,
                COALESCE(ht.hold_reference, 'No active hold') as hold_reference,
                COALESCE(ht.source_institution, 'Not funded') as source_institution,
                (
                    SELECT COUNT(*) 
                    FROM card_transactions ct 
                    WHERE ct.card_id = mc.card_id AND ct.auth_status = 'APPROVED'
                ) as transaction_count,
                (
                    SELECT COALESCE(SUM(amount), 0)
                    FROM card_transactions ct 
                    WHERE ct.card_id = mc.card_id AND ct.auth_status = 'APPROVED'
                ) as total_spent
            FROM message_cards mc
            LEFT JOIN hold_transactions ht ON mc.hold_reference = ht.hold_reference
            WHERE mc.card_number_hash = :hash
        ");
        $stmt->execute([':hash' => $cardNumberHash]);
    }
    
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$card) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Card not found'
        ]);
        exit();
    }
    
    $response = [
        'success' => true,
        'card_id' => $card['card_id'],
        'card_suffix' => $card['card_suffix'],
        'cardholder_name' => $card['cardholder_name'],
        'balance' => (float)$card['balance'],
        'currency' => $card['currency'],
        'expiry' => sprintf("%02d/%d", $card['expiry_month'], $card['expiry_year']),
        'status' => $card['lifecycle_status'],
        'financial_status' => $card['financial_status'],
        'initial_amount' => (float)$card['initial_amount'],
        'total_spent' => (float)$card['total_spent'],
        'transaction_count' => (int)$card['transaction_count'],
        'hold_reference' => $card['hold_reference'],
        'source_institution' => $card['source_institution']
    ];
    
    if ($card['activated_at']) {
        $response['activated_at'] = $card['activated_at'];
    }
    if ($card['last_used_at']) {
        $response['last_used_at'] = $card['last_used_at'];
    }
    
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Card balance error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
