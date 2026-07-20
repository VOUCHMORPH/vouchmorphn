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

$agentVerifiableTypes = ['national_id', 'birth_certificate', 'voter_id'];
if (!in_array($identityType, $agentVerifiableTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Agents can only claim document-based identities.']);
    exit;
}
if ($identityValue === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'identity_value is required']);
    exit;
}
if (!$documentVerified) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'You must confirm you have physically verified the client\'s document']);
    exit;
}
if ($pin === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'The client\'s claim PIN is required']);
    exit;
}
if (!$destinationAccountId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Select which of your agent accounts to deposit into']);
    exit;
}
if ($cashNowAmountRaw !== null && !is_numeric($cashNowAmountRaw)) {
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
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'That destination account is not one of your approved agent accounts.']);
        exit;
    }

    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    if (!$swapService->isApprovedAgent((int)$userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have an approved agent destination account.']);
        exit;
    }

    // If cash_now_amount wasn't sent, default to "give the client
    // everything" - look up the aggregate first to know the full amount.
    if ($cashNowAmountRaw === null) {
        $aggregate = $swapService->getAggregatedIdentityBalance($identityType, $identityValue);
        if ($aggregate === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No pending balance found for this identity']);
            exit;
        }
        if (!empty($aggregate['multi_currency'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This identity has balances in multiple currencies - specify cash_now_amount and claim one currency at a time.']);
            exit;
        }
        $cashNowAmount = (float)$aggregate['total_amount'];
    } else {
        $cashNowAmount = (float)$cashNowAmountRaw;
    }

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

    echo json_encode(['success' => true, 'data' => $result]);
} catch (\Throwable $e) {
    error_log("[agent/finalize_claim] Error for agent {$userId}: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
