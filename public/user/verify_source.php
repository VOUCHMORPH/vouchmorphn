<?php
/**
 * Verify source with OTP
 * POST /user/verify_source.php
 *
 * Request body:
 * {
 *   "attempt_id": 123,
 *   "otp": "123456"
 * }
 *
 * Response:
 * {
 *   "id": 456,
 *   "status": "active",
 *   "message": "Ownership verified"
 * }
 *
 * SECURITY FIX: this endpoint used to authenticate nobody. It read an
 * X-API-Key header and never checked it, took user_id from the request
 * body, and when none was sent looked the user up FROM THE ATTEMPT BEING
 * COMPLETED - so anyone could complete anyone's pending registration by
 * guessing its code, with no limit on guesses, and every code tried was
 * written to the log. A source verified here is what makes an account
 * spendable, so it now requires the signed-in customer, completes only
 * their own attempts (SwapService::completeUserSourceRegistration() is
 * scoped by user_id and stops an attempt after five wrong codes), and
 * never logs the code.
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';

use Application\Utils\SessionManager;
use Domain\Services\SwapService;
use Core\Database\DBConnection;

header('Content-Type: application/json');

try {
    SessionManager::start();
    if (!SessionManager::isLoggedIn() || !SessionManager::isUser()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Please sign in to verify a source.', 'message' => 'Please sign in to verify a source.']);
        exit;
    }

    $userId = (int)(SessionManager::getUser()['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Please sign in to verify a source.', 'message' => 'Please sign in to verify a source.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new RuntimeException("Invalid JSON input");
    }

    if (empty($input['attempt_id']) || !isset($input['otp']) || !is_scalar($input['otp']) || trim((string)$input['otp']) === '') {
        throw new RuntimeException("Missing required fields: attempt_id, otp");
    }

    $attemptId = (int)$input['attempt_id'];
    $otp = trim((string)$input['otp']);

    error_log("[verify_source] Verifying: user_id={$userId}, attempt_id={$attemptId}");

    $db = DBConnection::getConnection();
    if (!$db) {
        throw new RuntimeException("Database connection failed");
    }

    $country = getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $config = \Core\Config\LoadCountry::getConfig();

    $swapService = new SwapService($db, $config, $country);

    $result = $swapService->completeUserSourceRegistration($userId, $attemptId, $otp);

    echo json_encode([
        'success' => true,
        'data' => $result
    ]);

} catch (Exception $e) {
    error_log("[verify_source] ERROR: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage()
    ]);
}
