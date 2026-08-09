<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Cancel Contribution Session (owner only)
 *
 * Does NOT release the underlying hook holds — those belong to the
 * card's hook, not this session, and stay live for a possible new
 * session or the swipe fast-path until the hook itself expires or is
 * explicitly unhooked.
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

$db = $container->get(PDO::class);
$service = new CardContributionSessionService($db, new ContributionCalculator());

try {
    $result = $service->cancel((int)$input['session_id'], $userId, $input['reason'] ?? 'Cancelled by owner');
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $result], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
