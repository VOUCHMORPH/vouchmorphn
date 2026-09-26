<?php
/**
 * Finish adding the email or phone number started with
 * /user/contact_verify_start.php: checks the 6-digit code and saves the
 * email (as verified) or phone number on the signed-in user's account.
 * POST /user/contact_verify_confirm.php
 *
 * Request body:  { "code": "123456" }
 *
 * Response:
 * { "success": true, "data": { "type": "email", "message": "Your email is verified. ..." } }
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Database/CredentialsDBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Infrastructure/SMS/Contracts/ProviderInterface.php';
require_once __DIR__ . '/../../src/Core/Factories/CommunicationFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Email/Contracts/EmailProviderInterface.php';
require_once __DIR__ . '/../../src/Infrastructure/Email/EmailGatewayClient.php';
require_once __DIR__ . '/../../src/Infrastructure/Credentials/CredentialsRepository.php';

use Application\Utils\ContactVerificationFactory;
use Application\Utils\SessionManager;
use Domain\Identity\ContactVerificationException;

header('Content-Type: application/json');

try {
    SessionManager::start();

    if (!SessionManager::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $userData = SessionManager::getUser();
    $userId = (int)($userData['id'] ?? $userData['user_id'] ?? 0);
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Session has no user id']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new ContactVerificationException('Invalid request.');
    }

    $result = ContactVerificationFactory::service()->confirm(
        $userId,
        $_SESSION[ContactVerificationFactory::SESSION_KEY] ?? null,
        (string)($input['code'] ?? '')
    );

    unset($_SESSION[ContactVerificationFactory::SESSION_KEY]);
    // Keep the session's copy in step with the account.
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['user'][$result['type'] === 'email' ? 'email' : 'phone'] = $result['value'];
    }

    echo json_encode([
        'success' => true,
        'data' => ['type' => $result['type'], 'message' => $result['message']],
    ]);

} catch (ContactVerificationException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[contact_verify_confirm] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
