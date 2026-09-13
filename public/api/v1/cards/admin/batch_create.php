<?php
declare(strict_types=1);

/**
 * VouchMorph - Admin: Create Card Batch
 * Procedural version - no class context
 */

define('ROOT_PATH', dirname(__DIR__, 5));

// ============================================
// BOOTSTRAP - Load all dependencies
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
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
// ADMIN ROLE CHECK (Implement your admin logic)
// ============================================
// You need to implement proper admin authentication here
// For now, we'll skip the role check for testing
/*
$userId = getUserIdFromToken(); // You need to implement this
$isAdmin = checkUserRole($userId, 'admin');
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit();
}
*/

// ============================================
// GET INPUT
// ============================================
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

$required = ['bin_prefix', 'card_scheme', 'quantity', 'expiry_year', 'expiry_month'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "$field required"]);
        exit();
    }
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
// HELPER FUNCTION TO GENERATE BATCH CARDS
// ============================================
function generateBatchCard($pdo, $batchId, $input, $index) {
    // card_suffix is only the last 4 digits and generateCardNumber() has
    // no DB awareness -- it can and does collide with other cards
    // (confirmed in production against auto-provisioned user cards).
    // Retry until the suffix is confirmed unused rather than assuming
    // it's unique.
    $suffixCheckStmt = $pdo->prepare("SELECT 1 FROM message_cards WHERE card_suffix = :suffix LIMIT 1");
    $cardNumber = null;
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $candidateNumber = generateCardNumber($input['bin_prefix'], $index);
        $suffixCheckStmt->execute([':suffix' => substr($candidateNumber, -4)]);
        if (!$suffixCheckStmt->fetchColumn()) {
            $cardNumber = $candidateNumber;
            break;
        }
    }
    if ($cardNumber === null) {
        throw new RuntimeException("generateBatchCard: could not generate a unique card_suffix after 25 attempts (batch {$batchId}, index {$index})");
    }

    // Generate card details
    $cardNumberHash = hash('sha256', $cardNumber);
    $cardSuffix = substr($cardNumber, -4);

    // FIXED: Cast CVV to string before hashing
    $cvv = (string)rand(100, 999);
    // Ensure 3-digit CVV with leading zeros if needed
    $cvv = str_pad($cvv, 3, '0', STR_PAD_LEFT);
    $cvvHash = hash('sha256', $cvv);
    
    $stmt = $pdo->prepare("
        INSERT INTO message_cards (
            card_number_hash,
            card_suffix,
            cvv_hash,
            card_category,
            card_scheme,
            batch_id,
            batch_sequence,
            lifecycle_status,
            financial_status,
            expiry_year,
            expiry_month,
            metadata
        ) VALUES (
            :hash,
            :suffix,
            :cvv_hash,
            'PHYSICAL',
            :scheme,
            :batch_id,
            :seq,
            'IN_BATCH',
            'UNFUNDED',
            :exp_year,
            :exp_month,
            :metadata
        )
    ");
    
    $stmt->execute([
        ':hash' => $cardNumberHash,
        ':suffix' => $cardSuffix,
        ':cvv_hash' => $cvvHash,
        ':scheme' => $input['card_scheme'],
        ':batch_id' => $batchId,
        ':seq' => $index + 1,
        ':exp_year' => $input['expiry_year'],
        ':exp_month' => $input['expiry_month'],
        ':metadata' => json_encode([
            'bin' => $input['bin_prefix'],
            'produced_at' => date('Y-m-d H:i:s'),
            'cvv_generated' => true
        ])
    ]);
}

function generateCardNumber($bin, $index) {
    // Generate a unique card number: BIN + random digits + check digit
    $random = str_pad($index . rand(1000, 9999), 9, '0', STR_PAD_LEFT);
    $withoutCheck = $bin . $random;
    $checkDigit = calculateLuhnCheckDigit($withoutCheck);
    return $withoutCheck . $checkDigit;
}

function calculateLuhnCheckDigit($number) {
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int)$number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n = ($n % 10) + 1;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return (string)((10 - ($sum % 10)) % 10);
}

// ============================================
// CREATE BATCH
// ============================================
try {
    $pdo->beginTransaction();
    
    $batchRef = 'BATCH-' . date('Ymd') . '-' . strtoupper(uniqid());
    
    // Create batch record
    $batchStmt = $pdo->prepare("
        INSERT INTO card_batches (
            batch_reference, bin_prefix, card_scheme, card_type,
            quantity_produced, quantity_remaining, expiry_year, expiry_month,
            status, received_at, metadata
        ) VALUES (
            :ref, :bin, :scheme, 'PHYSICAL',
            :qty, :qty, :exp_year, :exp_month,
            'INVENTORY', NOW(), :metadata
        ) RETURNING batch_id
    ");
    
    $batchStmt->execute([
        ':ref' => $batchRef,
        ':bin' => $input['bin_prefix'],
        ':scheme' => $input['card_scheme'],
        ':qty' => $input['quantity'],
        ':exp_year' => $input['expiry_year'],
        ':exp_month' => $input['expiry_month'],
        ':metadata' => json_encode($input['metadata'] ?? [])
    ]);
    
    $batchId = $batchStmt->fetchColumn();
    
    // Generate individual cards in the batch
    for ($i = 0; $i < $input['quantity']; $i++) {
        generateBatchCard($pdo, $batchId, $input, $i);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'batch_reference' => $batchRef,
        'batch_id' => $batchId,
        'cards_generated' => $input['quantity'],
        'message' => 'Card batch created successfully'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
