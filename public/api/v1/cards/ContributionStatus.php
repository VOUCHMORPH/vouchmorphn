<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Contribution Session Status
 *
 * Every hooked contributor and the card owner poll this to see the same
 * server-computed preview: who's contributing what (masked identifiers
 * only), total covered vs target, and whether execution is unlocked.
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
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

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'session_id is required']);
    exit();
}

$db = $container->get(PDO::class);
$service = new CardContributionSessionService($db, new ContributionCalculator());

try {
    $result = $service->getStatus($sessionId, $userId);
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $result], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
