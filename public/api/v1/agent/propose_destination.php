<?php
// api/v1/agent/propose_destination.php - Register as an agent (propose destination account)
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
$institution = trim($body['institution'] ?? '');
$assetType = trim($body['asset_type'] ?? 'ACCOUNT');
$identifier = trim($body['identifier'] ?? '');
$identifierType = trim($body['identifier_type'] ?? 'account_number');
$accountName = trim($body['account_name'] ?? '') ?: null;

if ($institution === '' || $identifier === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Institution and account identifier are required']);
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
    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    $result = $swapService->proposeAgentDestinationAccount(
        (int)$userId,
        $institution,
        $assetType,
        $identifier,
        $identifierType,
        $accountName
    );

    echo json_encode(['success' => true, 'data' => $result]);
} catch (\Throwable $e) {
    error_log("[propose_destination] Error for user {$userId}: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
