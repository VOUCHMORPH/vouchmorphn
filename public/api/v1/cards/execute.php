<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Execute Contribution Session
 *
 * Only the card owner can call this, and only once the session's
 * status is READY (contributions fully cover the target). Debits the
 * ALREADY-HELD hook sources directly via
 * PoolCoordinator::executeFromCardHook() — no new holds are placed.
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit();
}

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Domain/Services/CardContributionSessionService.php';
require_once ROOT_PATH . '/src/Domain/Services/ContributionCalculator.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Domain\Services\CardContributionSessionService;
use Domain\Services\ContributionCalculator;
use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
$userId = (int)(SessionManager::getUser()['user_id'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

if (empty($input['session_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'session_id is required']);
    exit();
}

// PoolCoordinator needs the same dependency set it's normally
// constructed with elsewhere in the app (see PoolCoordinator's own
// constructor) — resolve it from the container the same way the rest
// of the codebase does, rather than re-wiring it here.
$db = $container->get(PDO::class);
$poolCoordinator = $container->get('Domain\Services\MultiSource\PoolCoordinator');

$service = new CardContributionSessionService($db, new ContributionCalculator());

try {
    $result = $service->execute((int)$input['session_id'], $userId, $poolCoordinator);
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $result], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
