<?php
declare(strict_types=1);

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
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Domain\Services\CardService;
use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$userId = (int)(SessionManager::getUser()['user_id'] ?? 0);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not resolve user from session']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['card_suffix'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'card_suffix is required']);
    exit();
}

$db = $container->get(PDO::class);
$cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));

try {
    $result = $cardService->regenerateTotpSecret($input['card_suffix'], $userId);
    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    error_log("[RegenerateTotp] Failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not regenerate swipe code right now.']);
}
