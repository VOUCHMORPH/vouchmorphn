<?php
// api/v1/agent/finalize_claim.php - Agent deposits a client's AGGREGATED identity balance into their own approved account
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$userData = SessionManager::getUser();
$userId = $userData['id'] ?? $userData['user_id'] ?? null;

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session has no user id']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ============================================================
// DEBUG: log the raw incoming payload verbatim (minus pin) so we
// can see EXACTLY what the frontend sent - this is the other half
// of the "how many holds did we think we were claiming" picture.
// ============================================================
$debugBody = $body;
if (isset($debugBody['pin'])) { $debugBody['pin'] = '[REDACTED len=' . strlen((string)$body['pin']) . ']'; }
error_log("=== [DEBUG][finalize_claim] BEGIN agent_user_id={$userId} at " . date('c') . " raw_body=" . json_encode($debugBody));

// ============================================================
// CHANGED: keyed by identity_type + identity_value now, not a
// single swap_reference - the whole point is claiming everything
// pending for this identity in one PIN check.
// ============================================================
$identityType = strtolower(trim($body['identity_type'] ?? ''));
$identityValue = trim($body['identity_value'] ?? '');
$pin = (string)($body['pin'] ?? '');
$documentVerified = ($body['identity_document_verified'] ?? false) === true;
$destinationAccountId = (int)($body['destination_account_id'] ?? 0);
$cashNowAmountRaw = $body['cash_now_amount'] ?? null;

error_log("[DEBUG][finalize_claim] parsed identity_type={$identityType} identity_value={$identityValue} destination_account_id={$destinationAccountId} cash_now_amount_raw=" . var_export($cashNowAmountRaw, true) . " pin_len=" . strlen($pin));

$agentVerifiableTypes = ['national_id', 'birth_certificate', 'voter_id'];
if (!in_array($identityType, $agentVerifiableTypes, true)) {
    error_log("[DEBUG][finalize_claim] REJECTED - not agent-verifiable type: {$identityType}");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Agents can only claim document-based identities.']);
    exit;
}
if ($identityValue === '') {
    error_log("[DEBUG][finalize_claim] REJECTED - empty identity_value");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'identity_value is required']);
    exit;
}
if (!$documentVerified) {
    error_log("[DEBUG][finalize_claim] REJECTED - document not verified");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'You must confirm you have physically verified the client\'s document']);
    exit;
}
if ($pin === '') {
    error_log("[DEBUG][finalize_claim] REJECTED - empty pin");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'The client\'s claim PIN is required']);
    exit;
}
if (!$destinationAccountId) {
    error_log("[DEBUG][finalize_claim] REJECTED - no destination_account_id");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Select which of your agent accounts to deposit into']);
    exit;
}
if ($cashNowAmountRaw !== null && !is_numeric($cashNowAmountRaw)) {
    error_log("[DEBUG][finalize_claim] REJECTED - cash_now_amount not numeric: " . var_export($cashNowAmountRaw, true));
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'cash_now_amount must be a number']);
    exit;
}

require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

try {
    $db = DBConnection::getConnection();

    // Ownership check stays as first, cheap gate. finalizeAggregatedIdentityClaim()
    // repeats it server-side too, so this is defense-in-depth, not the only check.
    $stmt = $db->prepare("
        SELECT id FROM agent_destination_accounts
        WHERE id = :id AND user_id = :user_id AND status = 'active' AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $destinationAccountId, ':user_id' => $userId]);
    $ownsDestination = (bool)$stmt->fetch();
    error_log("[DEBUG][finalize_claim] destination_account_id={$destinationAccountId} owned_by_user_id={$userId}: " . ($ownsDestination ? 'YES' : 'NO'));
    if (!$ownsDestination) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'That destination account is not one of your approved agent accounts.']);
        exit;
    }

    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    $isApproved = $swapService->isApprovedAgent((int)$userId);
    error_log("[DEBUG][finalize_claim] isApprovedAgent(user_id={$userId}) = " . ($isApproved ? 'true' : 'false'));
    if (!$isApproved) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have an approved agent destination account.']);
        exit;
    }

    // If cash_now_amount wasn't sent, default to "give the client
    // everything" - look up the aggregate first to know the full amount.
    if ($cashNowAmountRaw === null) {
        $aggregate = $swapService->getAggregatedIdentityBalance($identityType, $identityValue);
        error_log("[DEBUG][finalize_claim] cash_now_amount was null - re-fetched aggregate: " . json_encode($aggregate));
        if ($aggregate === null) {
            error_log("[DEBUG][finalize_claim] REJECTED - no pending balance on re-fetch");
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No pending balance found for this identity']);
            exit;
        }
        if (!empty($aggregate['multi_currency'])) {
            error_log("[DEBUG][finalize_claim] REJECTED - multi_currency, cash_now_amount required");
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This identity has balances in multiple currencies - specify cash_now_amount and claim one currency at a time.']);
            exit;
        }
        $cashNowAmount = (float)$aggregate['total_amount'];
    } else {
        $cashNowAmount = (float)$cashNowAmountRaw;
    }

    // ============================================================
    // DEBUG: this is the critical boundary. Everything above this
    // line matches what search_claim saw (swap_count, total_amount).
    // Everything below is inside finalizeAggregatedIdentityClaim(),
    // which is where holds actually get closed/debited. If the
    // "holds closed" count in the RESULT below doesn't match the
    // swap_count logged above / at search time, the bug is inside
    // that method's own hold-selection query - not here.
    // ============================================================
    error_log("[DEBUG][finalize_claim] >>> CALLING finalizeAggregatedIdentityClaim identity_type={$identityType} identity_value={$identityValue} cash_now_amount={$cashNowAmount} destination_account_id={$destinationAccountId} agent_user_id={$userId}");

    $result = $swapService->finalizeAggregatedIdentityClaim(
        $identityType,
        $identityValue,
        $pin,
        'agent',
        (int)$userId,
        $destinationAccountId,
        $cashNowAmount,
        (int)$userId   // agentUserId — enforces ownership again inside SwapService
    );

    // ============================================================
    // DEBUG: dump the FULL result. Look specifically for any
    // holds_closed / swap_references_processed / swap_count field
    // and compare it against the swap_count search_claim reported
    // for the same identity a moment earlier in the logs.
    // ============================================================
    error_log("[DEBUG][finalize_claim] <<< RESULT from finalizeAggregatedIdentityClaim: " . json_encode($result));
    error_log("=== [DEBUG][finalize_claim] END agent_user_id={$userId}");

    echo json_encode(['success' => true, 'data' => $result]);
} catch (\Throwable $e) {
    error_log("[DEBUG][finalize_claim] EXCEPTION: " . $e->getMessage() . " | " . $e->getTraceAsString());
    error_log("[agent/finalize_claim] Error for agent {$userId}: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
