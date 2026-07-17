<?php
declare(strict_types=1);

/**
 * =============================================================================
 * cashout-confirm webhook controller
 * =============================================================================
 * Receives: POST /api/v1/swap/cashout-confirm
 * Caller:   atm_cashout_voucher.php on the bank side, after cash is dispensed
 * =============================================================================
 */

header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

while (ob_get_level()) {
    ob_end_clean();
}

function respond(int $httpCode, array $body): void
{
    http_response_code($httpCode);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// BOOTSTRAP - CORRECT PATHS
// ============================================================

// File is at: /var/www/html/public/api/v1/swap/cashout_confirm.php
// __DIR__ = /var/www/html/public/api/v1/swap
// dirname(__DIR__, 4) = /var/www/html
$baseDir = dirname(__DIR__, 4);

$autoloadFile = $baseDir . '/vendor/autoload.php';

if (!file_exists($autoloadFile)) {
    respond(500, ['status' => 'ERROR', 'message' => 'Autoloader not found at: ' . $autoloadFile]);
}
require_once $autoloadFile;

// Load Bootstrap
$bootstrapFile = $baseDir . '/src/bootstrap.php';
if (!file_exists($bootstrapFile)) {
    respond(500, ['status' => 'ERROR', 'message' => 'Bootstrap not found at: ' . $bootstrapFile]);
}
require_once $bootstrapFile;

use Domain\Services\SwapService;
use Core\Config\LoadCountry;
use Core\Database\DBConnection;

// ============================================================
// GET DATABASE CONNECTION
// ============================================================

try {
    $db = DBConnection::getConnection();
} catch (Exception $e) {
    error_log("[CashoutConfirmWebhook] DB Connection failed: " . $e->getMessage());
    respond(500, ['status' => 'ERROR', 'message' => 'Database connection failed: ' . $e->getMessage()]);
}

// ============================================================
// LOAD COUNTRY CONFIG
// ============================================================

try {
    $countryConfig = LoadCountry::getConfig();
    $config = $countryConfig;
} catch (Exception $e) {
    error_log("[CashoutConfirmWebhook] Config load failed: " . $e->getMessage());
    respond(500, ['status' => 'ERROR', 'message' => 'Config load failed: ' . $e->getMessage()]);
}

// ============================================================
// READ INPUT
// ============================================================

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    respond(400, ['status' => 'ERROR', 'message' => 'Invalid JSON input']);
}

if (!isset($data['voucher_number'])) {
    respond(400, ['status' => 'ERROR', 'message' => 'Missing required field: voucher_number']);
}

error_log("[CashoutConfirmWebhook] Received: " . json_encode($data));

// ============================================================
// BUILD PAYLOAD - IDENTIFIERS ONLY
// ============================================================

$confirmPayload = [
    'voucher_number'    => $data['voucher_number'],
    'swap_reference'    => $data['swap_reference'] ?? null,
    'atm_id'            => $data['atm_id'] ?? 'ATM001',
    'cashout_reference' => $data['cashout_reference'] ?? null,
    'requester'         => $data['requester'] ?? 'BANK_SYSTEM',
    'is_callback'       => true,
    'cashout_point'     => 'ATM',
];

// ============================================================
// CONFIRM
// ============================================================

try {
    $swapService = new SwapService($db, $config, 'Botswana');
    $result = $swapService->confirmCashout($confirmPayload);

    error_log("[CashoutConfirmWebhook] confirmCashout result: " . json_encode($result));

    respond(200, [
        'status' => 'SUCCESS',
        'result' => $result,
    ]);

} catch (RuntimeException $e) {
    error_log("[CashoutConfirmWebhook] confirmCashout failed: " . $e->getMessage());
    respond(422, [
        'status' => 'ERROR',
        'message' => $e->getMessage(),
    ]);
} catch (Exception $e) {
    error_log("[CashoutConfirmWebhook] Unexpected error: " . $e->getMessage());
    error_log("[CashoutConfirmWebhook] Trace: " . $e->getTraceAsString());
    respond(500, [
        'status' => 'ERROR',
        'message' => 'Internal error: ' . $e->getMessage(),
    ]);
}
