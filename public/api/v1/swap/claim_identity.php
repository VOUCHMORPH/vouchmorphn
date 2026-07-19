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
$swapReference = $body['swap_reference'] ?? null;
$pin = (string)($body['pin'] ?? '');
$destinationType = strtoupper($body['destination_type'] ?? 'CASHOUT');

if (!$swapReference) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'swap_reference is required']);
    exit;
}
if (!in_array($destinationType, ['CASHOUT', 'DEPOSIT'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'destination_type must be CASHOUT or DEPOSIT']);
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

    // IMPORTANT: this endpoint is self-service only. confirmed_by_type
    // is hardcoded to 'user' and confirmed_by_id is taken from the
    // SESSION, never from client input - a user cannot claim on behalf
    // of anyone else through this endpoint, and cannot spoof another
    // user's id. The agent-finalization path is a separate endpoint
    // (not built yet) that will require the agent's own authenticated
    // session plus the physical-document flags.
    $result = $swapService->executeAtomicSwap([
        'swap_type' => 'CONFIRM_IDENTITY',
        'swap_reference' => $swapReference,
        'confirmed_by_type' => 'user',
        'confirmed_by_id' => (int)$userId,
        'confirmation_method' => 'dashboard',
        'destination_type' => $destinationType,
        'pin' => $pin,
        'destination_institution' => $body['destination_institution'] ?? null,
        'destination_identifier' => $body['destination_identifier'] ?? null,
        'destination_identifier_type' => $body['destination_identifier_type'] ?? 'account',
        'delivery_method' => $body['delivery_method'] ?? 'ATM',
        'beneficiary_phone' => $body['beneficiary_phone'] ?? null,
    ]);

    echo json_encode(['success' => true, 'data' => $result]);
} catch (\Throwable $e) {
    error_log("[claim_identity] Error for user {$userId}: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
