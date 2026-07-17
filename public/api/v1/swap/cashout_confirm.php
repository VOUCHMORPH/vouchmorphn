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
// BOOTSTRAP
// ============================================================

$baseDir = dirname(__DIR__, 3); // adjust to your actual layout
require_once $baseDir . '/config/db.php';                 // provides $pdo
require_once $baseDir . '/vendor/autoload.php';            // Composer autoload for Domain\Services\SwapService etc.
require_once $baseDir . '/helpers/crypto.php';             // must expose verify_signature()

use Domain\Services\SwapService;

// ============================================================
// READ + VALIDATE INPUT SHAPE
// ============================================================

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    respond(400, ['status' => 'ERROR', 'message' => 'Invalid JSON input']);
}

$requiredFields = ['voucher_number', 'amount', 'atm_id', 'cashout_reference', 'requester', 'timestamp'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        respond(400, ['status' => 'ERROR', 'message' => "Missing required field: {$field}"]);
    }
}

// Reject stale/replayed requests — adjust window to taste
$maxSkewSeconds = 300;
if (abs(time() - (int)$data['timestamp']) > $maxSkewSeconds) {
    error_log("[CashoutConfirmWebhook] Rejected: timestamp outside allowed skew");
    respond(400, ['status' => 'ERROR', 'message' => 'Request timestamp outside allowed window']);
}

// ============================================================
// VERIFY SIGNATURE — REQUIRED, NOT OPTIONAL
// ============================================================
// This is the gate that makes the rest of this controller safe. If your
// crypto.php doesn't yet have a real verify_signature() implementation,
// stop here and build that before wiring this endpoint live — do not
// fall back to "trust known source names" the way atm_cashout_voucher.php
// currently does. A string match on 'requester' is not authentication.

if (!isset($data['signature'])) {
    error_log("[CashoutConfirmWebhook] Rejected: no signature present");
    respond(401, ['status' => 'ERROR', 'message' => 'Signature required']);
}

$claimedSource = $data['requester'] ?? null; // e.g. 'ZURUBANK_ATM_ATM001' or 'ZURUBANK'
$institutionCode = extractInstitutionCode($claimedSource); // e.g. 'ZURUBANK'

$signaturePayload = $data;
$providedSignature = $signaturePayload['signature'];
unset($signaturePayload['signature']);

$verified = verify_signature($signaturePayload, $providedSignature, $institutionCode);

if (!$verified) {
    error_log("[CashoutConfirmWebhook] Rejected: signature verification FAILED for claimed source {$claimedSource}");
    respond(401, ['status' => 'ERROR', 'message' => 'Signature verification failed']);
}

error_log("[CashoutConfirmWebhook] Signature verified for source: {$institutionCode}");

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
    'atm_id'            => $data['atm_id'],
    'cashout_reference' => $data['cashout_reference'],
    'requester'         => $claimedSource,
    'is_callback'       => true,
    'cashout_point'     => 'ATM',
];

// ============================================================
// CONFIRM
// ============================================================

try {
    $country = 'BW'; // or derive from config/routing
    $swapService = new SwapService($pdo, [], $country);

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
    respond(500, [
        'status' => 'ERROR',
        'message' => 'Internal error processing confirmation',
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
