<?php
/**
 * Verify a self-service identity registration with an OTP
 * POST /user/verify_identity_otp.php
 *
 * Request body:
 * {
 *   "attempt_id": 123,
 *   "otp": "123456"
 * }
 *
 * Response:
 * {
 *   "status": "verified",
 *   "verified": true,
 *   "message": "Identity verified."
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

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new RuntimeException("Invalid JSON input");
    }

    if (empty($input['attempt_id']) || empty($input['otp'])) {
        throw new RuntimeException("Missing required fields: attempt_id, otp");
    }

    $attemptId = (int)$input['attempt_id'];
    $otp = trim($input['otp']);

    $db = DBConnection::getConnection();
    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $config = LoadCountry::getConfig();

    $swapService = new SwapService($db, $config, $country);

    $result = $swapService->verifyUserIdentityOtp((int)$userId, $attemptId, $otp);

    echo json_encode([
        'success' => true,
        'data' => $result
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
