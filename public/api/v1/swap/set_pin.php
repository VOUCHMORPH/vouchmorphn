<?php
// api/v1/swap/set_pin.php - Set or change the logged-in user's transaction PIN
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
$pin = (string)($body['pin'] ?? '');
$confirmPin = (string)($body['confirm_pin'] ?? '');

if ($pin === '' || $pin !== $confirmPin) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'PIN and confirmation must match and not be empty']);
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

    $swapService->setUserTransactionPin((int)$userId, $pin);

    echo json_encode(['success' => true, 'message' => 'Transaction PIN set successfully']);
} catch (\Throwable $e) {
    error_log("[set_pin] Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
