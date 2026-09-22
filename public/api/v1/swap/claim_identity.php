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
$swapReference = trim((string)($body['swap_reference'] ?? ''));

require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

$db = DBConnection::getConnection();

// ============================================================
// FIX: the manual "enter swap reference + PIN" form on the dashboard
// (submitDirectClaim()) only has the reference the recipient typed in,
// not the identity_type/identity_value -- it used to resolve those via
// /swap/details.php, but that endpoint restricts session access to
// swaps the LOGGED-IN USER owns (the sender), which the recipient
// claiming someone else's money never is. That made every manual claim
// fail with "Swap not found" before the claim logic even ran. Resolve
// identity_type/identity_value directly from the hold row here instead
// -- no ownership check needed, since the PIN itself (verified inside
// finalizeAggregatedIdentityClaimSelfService()) is the real
// authorization for a claim, exactly like the aggregated identity_type
// + identity_value path above.
// ============================================================
if (($identityType === '' || $identityValue === '') && $swapReference !== '') {
    $stmt = $db->prepare("SELECT identity_type, identity_value FROM identity_swap_holds WHERE swap_reference = :ref LIMIT 1");
    $stmt->execute([':ref' => $swapReference]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $identityType = strtolower((string)$row['identity_type']);
        $identityValue = (string)$row['identity_value'];
    }
}

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

try {
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
    // FIX (2026-09-22): the claimer chooses how much to claim now (C). Before,
    // no amount was passed, so every self-service claim took everything and a
    // partial claim (with the rest parked in a reservation account) was
    // impossible. Omitted = claim the full available amount.
    $claimAmount = $body['amount'] ?? $body['cash_now_amount'] ?? null;
    if ($claimAmount !== null && (!is_numeric($claimAmount) || (float)$claimAmount <= 0)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'amount must be a positive number (or omitted to claim everything)']);
        exit;
    }
    $result = $swapService->finalizeAggregatedIdentityClaimSelfService(
        $identityType,
        $identityValue,
        $pin,
        (int)$userId,
        $destinationType,
        $destinationDetails,
        $claimAmount !== null ? round((float)$claimAmount, 2) : null
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
