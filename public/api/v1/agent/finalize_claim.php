<?php
// api/v1/agent/finalize_claim.php - Agent deposits a client's identity-swap money into their own approved account
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../src/Application/Utils/SessionManager.php';
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
$swapReference = $body['swap_reference'] ?? null;
$pin = (string)($body['pin'] ?? '');
$documentVerified = ($body['identity_document_verified'] ?? false) === true;
$destinationAccountId = (int)($body['destination_account_id'] ?? 0);

if (!$swapReference) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'swap_reference is required']);
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

require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

try {
    $db = DBConnection::getConnection();

    // ============================================================
    // SECURITY: NEVER trust destination_institution/identifier from
    // the client directly - that would let a compromised agent
    // session redirect a deposit to an account the agent doesn't
    // actually own or isn't approved for. Look it up server-side
    // from THIS agent's own approved destinations only.
    // ============================================================
    $stmt = $db->prepare("
        SELECT institution, asset_type, identifier, identifier_type
        FROM agent_destination_accounts
        WHERE id = :id AND user_id = :user_id AND status = 'active' AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $destinationAccountId, ':user_id' => $userId]);
    $destAccount = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$destAccount) {
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

    $result = $swapService->executeAtomicSwap([
        'swap_type' => 'CONFIRM_IDENTITY',
        'swap_reference' => $swapReference,
        'confirmed_by_type' => 'agent',
        'confirmed_by_id' => (int)$userId,
        'confirmation_method' => 'agent_portal',
        'destination_type' => 'DEPOSIT',
        'pin' => $pin,
        'identity_document_verified' => true,
        // Server-verified values only - never from $body.
        'destination_institution' => $destAccount['institution'],
        'destination_identifier' => $destAccount['identifier'],
        'destination_identifier_type' => $destAccount['identifier_type'],
    ]);

    echo json_encode(['success' => true, 'data' => $result]);
} catch (\Throwable $e) {
    error_log("[agent/finalize_claim] Error for agent {$userId}: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
