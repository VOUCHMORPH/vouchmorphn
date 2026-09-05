<?php
/**
 * Get the logged-in user's registered identities
 * POST /user/identities.php
 *
 * Response:
 * {
 *   "success": true,
 *   "data": { "identities": [ { "id", "identity_type", "identity_value", "status", "verified", "verified_at", "created_at" }, ... ] }
 * }
 */

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

header('Content-Type: application/json');

try {
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

    $db = DBConnection::getConnection();
    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $config = LoadCountry::getConfig();

    $swapService = new SwapService($db, $config, $country);

    $identities = $swapService->getUserIdentities((int)$userId);

    echo json_encode([
        'success' => true,
        'data' => ['identities' => $identities]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
