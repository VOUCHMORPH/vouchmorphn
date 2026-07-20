<?php
// api/v1/agent/finalize_single_hold.php - Process ONE hold at a time
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

$holdId = (int)($body['hold_id'] ?? 0);
$pin = (string)($body['pin'] ?? '');
$documentVerified = ($body['identity_document_verified'] ?? false) === true;
$destinationAccountId = (int)($body['destination_account_id'] ?? 0);

if (!$holdId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'hold_id is required']);
    exit;
}
if (!$pin) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'PIN is required']);
    exit;
}
if (!$documentVerified) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Document verification required']);
    exit;
}
if (!$destinationAccountId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Destination account required']);
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
    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    if (!$swapService->isApprovedAgent((int)$userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Not an approved agent']);
        exit;
    }

    // Get the specific hold
    $hold = $swapService->getIdentitySwapByHoldId($holdId);
    if (!$hold || $hold['status'] !== 'pending') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Hold not found or not pending']);
        exit;
    }

    // Get the destination account
    $stmt = $db->prepare("
        SELECT institution, identifier, identifier_type, asset_type
        FROM agent_destination_accounts
        WHERE id = :id AND user_id = :user_id AND status = 'active' AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $destinationAccountId, ':user_id' => $userId]);
    $destAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$destAccount) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid destination account']);
        exit;
    }

    // Process just THIS hold as its own transaction
    $result = $swapService->finalizeIdentityHoldNoPin($hold, [
        'confirmed_by_type' => 'agent',
        'confirmed_by_id' => $userId,
        'identity_document_verified' => true,
        'destination_type' => 'DEPOSIT',
        'destination_institution' => $destAccount['institution'],
        'destination_identifier' => $destAccount['identifier'],
        'destination_identifier_type' => $destAccount['identifier_type'],
        'destination_asset_type' => $destAccount['asset_type'],
        'client_phone' => $hold['otp_pin_sent_to'],
        'beneficiary_phone' => $hold['otp_pin_sent_to'],
    ]);

    echo json_encode(['success' => true, 'data' => $result]);
    
} catch (\Throwable $e) {
    error_log("[agent/finalize_single_hold] Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
