<?php
// api/v1/agent/search_claim.php
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

if (!$identityType || !$identityValue) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'identity_type and identity_value are required']);
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

    // Get aggregated identity balance - shows ALL holds grouped
    $result = $swapService->getAggregatedIdentityBalance($identityType, $identityValue);

    if ($result === null) {
        echo json_encode(['success' => true, 'data' => null, 'message' => 'No pending balance found']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $result]);
    
} catch (\Throwable $e) {
    error_log("[agent/search_claim] Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
