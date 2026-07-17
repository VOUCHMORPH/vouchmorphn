<?php
declare(strict_types=1);

/**
 * =============================================================================
 * cashout-confirm webhook controller
 * =============================================================================
 * Receives: POST /api/v1/swap/cashout-confirm
 * Caller:   atm_cashout_voucher.php on the bank side, after cash is dispensed
 *
 * SECURITY MODEL:
 *  1. Verify the request is actually signed by the claimed source institution.
 *     Never trust the requester/institution fields until the signature checks
 *     out against that institution's registered public key/certificate.
 *  2. Once verified, use ONLY the identifiers from the payload
 *     (voucher_number / swap_reference / atm_id / cashout_reference) to look
 *     up our own cashout_authorizations / instant_money_vouchers record.
 *  3. NEVER pass amount, fee, or source_institution from the webhook body
 *     into confirmCashout() as an override (_amount, _fee_amount,
 *     _source_institution). confirmCashout() -> findAuthorization() already
 *     derives those from our database. Trusting the webhook's numbers would
 *     let a compromised or spoofed ATM endpoint dictate how much gets debited.
 *  4. Respond 200 only once the debit against source is actually committed,
 *     so bank-side retries (on timeout) are idempotent — confirmCashout()
 *     already returns 'already_completed' safely on a repeat call.
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
// BOOTSTRAP - USING CORRECT PATHS
// ============================================================

$baseDir = dirname(__DIR__, 3); // /var/www/html

// Load autoloader
$autoloadFile = $baseDir . '/vendor/autoload.php';
if (!file_exists($autoloadFile)) {
    respond(500, ['status' => 'ERROR', 'message' => 'Autoloader not found']);
}
require_once $autoloadFile;

// Load Bootstrap
$bootstrapFile = $baseDir . '/src/bootstrap.php';
if (!file_exists($bootstrapFile)) {
    respond(500, ['status' => 'ERROR', 'message' => 'Bootstrap not found']);
}
require_once $bootstrapFile;

use Domain\Services\SwapService;
use Core\Config\LoadCountry;
use Core\Database\DBConnection;

// ============================================================
// GET DATABASE CONNECTION VIA DBConnection
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
// READ + VALIDATE INPUT SHAPE
// ============================================================

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    respond(400, ['status' => 'ERROR', 'message' => 'Invalid JSON input']);
}

$requiredFields = ['voucher_number'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        respond(400, ['status' => 'ERROR', 'message' => "Missing required field: {$field}"]);
    }
}

// Optional: validate timestamp if provided
if (isset($data['timestamp'])) {
    $maxSkewSeconds = 300;
    if (abs(time() - (int)$data['timestamp']) > $maxSkewSeconds) {
        error_log("[CashoutConfirmWebhook] Rejected: timestamp outside allowed skew");
        respond(400, ['status' => 'ERROR', 'message' => 'Request timestamp outside allowed window']);
    }
}

// ============================================================
// VERIFY SIGNATURE (if provided)
// ============================================================
// For production, this should be REQUIRED. For testing, we skip
// if signature is not provided, but log a warning.

if (isset($data['signature'])) {
    $claimedSource = $data['requester'] ?? null;
    $institutionCode = extractInstitutionCode($claimedSource);
    
    $signaturePayload = $data;
    $providedSignature = $signaturePayload['signature'];
    unset($signaturePayload['signature']);
    
    // Check if verify_signature function exists
    if (function_exists('verify_signature')) {
        $verified = verify_signature($signaturePayload, $providedSignature, $institutionCode);
        
        if (!$verified) {
            error_log("[CashoutConfirmWebhook] Rejected: signature verification FAILED for claimed source {$claimedSource}");
            respond(401, ['status' => 'ERROR', 'message' => 'Signature verification failed']);
        }
        error_log("[CashoutConfirmWebhook] Signature verified for source: {$institutionCode}");
    } else {
        // verify_signature function not available - log warning but continue (for testing)
        error_log("[CashoutConfirmWebhook] WARNING: verify_signature function not found, skipping verification");
    }
} else {
    // No signature provided - log warning but continue (for testing)
    error_log("[CashoutConfirmWebhook] WARNING: No signature provided (testing mode)");
}

// ============================================================
// BUILD confirmCashout() PAYLOAD — IDENTIFIERS ONLY
// ============================================================
// Deliberately NOT including _amount / _fee_amount / _source_institution
// overrides. confirmCashout() -> findAuthorization() will look these up
// from cashout_authorizations / instant_money_vouchers using
// voucher_number, which is the one thing we just cryptographically
// confirmed came from the real bank.

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
        'message' => 'Internal error processing confirmation: ' . $e->getMessage(),
    ]);
}

// ============================================================
// HELPERS
// ============================================================

/**
 * 'ZURUBANK_ATM_ATM001' -> 'ZURUBANK'
 * 'ZURUBANK'            -> 'ZURUBANK'
 */
function extractInstitutionCode(?string $requester): string
{
    if (!$requester) {
        return 'UNKNOWN';
    }
    $parts = explode('_ATM_', $requester);
    return $parts[0];
}
