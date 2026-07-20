<?php
// api/v1/agent/finalize_claim.php
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

$identityType = (string)($body['identity_type'] ?? '');
$identityValue = (string)($body['identity_value'] ?? '');
$pin = (string)($body['pin'] ?? '');
$documentVerified = ($body['identity_document_verified'] ?? false) === true;
$destinationAccountId = (int)($body['destination_account_id'] ?? 0);
$cashNowAmount = (float)($body['cash_now_amount'] ?? 0);

if (!$identityType || !$identityValue) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'identity_type and identity_value are required']);
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

    // Use the aggregated claim method - this handles ALL holds for the identity
    // The PIN is the "green light" - one PIN verifies and authorizes ALL holds
    $result = $swapService->finalizeAggregatedIdentityClaim(
        $identityType,
        $identityValue,
        $pin,
        'agent',
        $userId,
        $destinationAccountId,
        $cashNowAmount,
        $userId
    );

    echo json_encode(['success' => true, 'data' => $result]);
    
} catch (\Throwable $e) {
    error_log("[agent/finalize_claim] Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
