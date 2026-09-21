<?php
// api/v1/agent/finalize_single_hold.php - Process ONE hold at a time
// NOTE: This is a legacy endpoint. For new implementations, use finalize_claim.php (aggregated)
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

    // Incident Command gate: service, claim flow and this agent.
    require_once dirname(__DIR__, 4) . '/src/Application/Incident/IncidentDesk.php';
require_once dirname(__DIR__, 4) . '/src/Application/Incident/Playbooks.php';
require_once dirname(__DIR__, 4) . '/src/Application/Incident/ServiceControls.php';
$gate = \Application\Incident\ServiceControls::check($db, ['amount' => 0, 'flow' => 'IDENTITY_CLAIM', 'agent_id' => (string)$userId]);
    if ($gate !== null) {
        http_response_code($gate['http']);
        echo json_encode(['success' => false, 'error' => $gate['message'], 'code' => $gate['code'], 'funds_moved' => false]);
        exit;
    }

    // Get the specific hold
    $hold = $swapService->getIdentitySwapByHoldId($holdId);
    if (!$hold || $hold['status'] !== 'pending') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Hold not found or not pending']);
        exit;
    }

    // ============================================================
    // FIX: Check if the identity is already authorized (green light)
    // If so, we can skip the PIN verification and just finalize
    // ============================================================
    $identityType = $hold['identity_type'] ?? null;
    $identityValue = $hold['identity_value'] ?? null;
    $isAuthorized = false;
    
    if ($identityType && $identityValue) {
        $isAuthorized = $swapService->isIdentityAuthorized($identityType, $identityValue);
        if ($isAuthorized) {
            error_log("[agent/finalize_single_hold] Identity {$identityType}={$identityValue} is already authorized. Skipping PIN verification.");
        }
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

    // Build confirmation payload
    $confirmationPayload = [
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
    ];

    // ============================================================
    // FIX: If identity is already authorized, skip PIN verification
    // This allows the single-hold endpoint to work with the new flow
    // ============================================================
    if ($isAuthorized) {
        $confirmationPayload['_skip_pin_verification'] = true;
        $confirmationPayload['_authorized_by'] = 'identity_authorization';
        // We still need to send the PIN for the confirmation payload,
        // but finalizeIdentityHoldNoPin will skip verification
        $confirmationPayload['pin'] = $pin;
    } else {
        // Not authorized - PIN will be verified by the service
        $confirmationPayload['pin'] = $pin;
    }

    // Process just THIS hold as its own transaction
    $result = $swapService->finalizeIdentityHoldNoPin($hold, $confirmationPayload);

    // ============================================================
    // FIX: After finalizing this hold, if the identity was NOT already
    // authorized, the PIN verification inside finalizeIdentityHoldNoPin
    // will have marked the identity as authorized (green light).
    // This means the agent can now finalize ALL other holds without
    // re-entering the PIN.
    // ============================================================
    
    $response = [
        'success' => true,
        'data' => $result,
        'message' => 'Hold finalized successfully.'
    ];
    
    // If this was the first hold authorized, let the agent know they can do more
    if (!$isAuthorized && $identityType && $identityValue) {
        // Check if there are other pending holds for this identity
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM identity_swap_holds 
            WHERE identity_type = :type 
              AND identity_value = :value 
              AND status = 'pending' 
              AND hold_id != :hold_id
              AND hold_expires_at > NOW()
        ");
        $stmt->execute([
            ':type' => $identityType,
            ':value' => $identityValue,
            ':hold_id' => $holdId
        ]);
        $otherHolds = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($otherHolds && (int)$otherHolds['count'] > 0) {
            $response['message'] = "Hold finalized. This identity is now authorized (green light). You can finalize the remaining {$otherHolds['count']} hold(s) without re-entering the PIN.";
            $response['remaining_holds'] = (int)$otherHolds['count'];
            $response['identity_authorized'] = true;
        } else {
            $response['message'] = 'Hold finalized successfully. No other holds remaining for this identity.';
        }
    } else if ($isAuthorized) {
        $response['message'] = 'Hold finalized successfully (identity was already authorized).';
    }

    echo json_encode($response);
    
} catch (\Throwable $e) {
    error_log("[agent/finalize_single_hold] Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
