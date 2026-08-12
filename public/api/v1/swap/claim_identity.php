<?php
// api/v1/swap/claim_identity.php - Self-service identity swap finalization
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
// FIX: this endpoint previously expected swap_reference (single-hold
// shape), but the dashboard actually sends identity_type +
// identity_value (aggregated shape, per the JS comment: "claims ALL
// holds at once"). The two never matched, so every claim through this
// endpoint failed. Now accepts what the frontend actually sends and
// calls the aggregated self-service method.
// ============================================================
$identityType = strtolower(trim((string)($body['identity_type'] ?? '')));
$identityValue = trim((string)($body['identity_value'] ?? ''));
$pin = (string)($body['pin'] ?? '');
$destinationType = strtoupper((string)($body['destination_type'] ?? 'CASHOUT'));

if ($identityType === '' || $identityValue === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'identity_type and identity_value are required']);
    exit;
}
if ($pin === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'pin is required']);
    exit;
}
if (!in_array($destinationType, ['CASHOUT', 'DEPOSIT'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'destination_type must be CASHOUT or DEPOSIT']);
    exit;
}

$destinationDetails = [];
if ($destinationType === 'DEPOSIT') {
    $destinationInstitution = $body['destination_institution'] ?? null;
    $destinationIdentifier = trim((string)($body['destination_identifier'] ?? ''));
    if (empty($destinationInstitution) || $destinationIdentifier === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'destination_institution and destination_identifier are required for DEPOSIT']);
        exit;
    }
    $destinationDetails['destination_institution'] = $destinationInstitution;
    $destinationDetails['destination_identifier'] = $destinationIdentifier;
    $destinationDetails['destination_identifier_type'] = $body['destination_identifier_type'] ?? 'account';
    if (!empty($body['destination_asset_type'])) {
        $destinationDetails['destination_asset_type'] = $body['destination_asset_type'];
    }
} else {
    // CASHOUT
    $destinationInstitution = $body['destination_institution'] ?? null;
    if (empty($destinationInstitution)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'destination_institution is required for CASHOUT']);
        exit;
    }
    $destinationDetails['destination_institution'] = $destinationInstitution;
    $destinationDetails['delivery_method'] = $body['delivery_method'] ?? 'ATM';
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

    // IMPORTANT: this endpoint is self-service only. confirmed_by_type
    // is hardcoded to 'user' inside finalizeAggregatedIdentityClaimSelfService()
    // and confirmed_by_id comes from the SESSION, never from client
    // input — a user cannot claim on behalf of anyone else through this
    // endpoint. Claims EVERY pending hold for the given identity in one
    // PIN check (the "green light" pattern), matching what the
    // dashboard actually offers: one PIN entry claims the whole balance.
    // The agent-assisted path (finalizeAggregatedIdentityClaim) is a
    // separate flow with its own endpoint, requiring an agent's own
    // authenticated session and a pre-registered agent destination account.
    $result = $swapService->finalizeAggregatedIdentityClaimSelfService(
        $identityType,
        $identityValue,
        $pin,
        (int)$userId,
        $destinationType,
        $destinationDetails
    );

    $message = $result['status'] === 'success'
        ? 'Funds claimed successfully!'
        : 'Some holds could not be claimed — see details.';

    echo json_encode(['success' => true, 'data' => $result, 'message' => $message]);

} catch (\Throwable $e) {
    error_log("[claim_identity] Error for user {$userId}: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
