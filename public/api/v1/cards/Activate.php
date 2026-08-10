<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Activate
 *
 * Charges the activation fee from a source the owner chooses and
 * flips the card from INACTIVE to ACTIVE. Uses the container's own
 * CardService AND SwapService factories rather than constructing
 * either manually -- see bootstrap_fix_swapservice_factory.php for
 * why the SwapService factory needed fixing first (it was passing
 * the wrong arguments and would have fatally broken the moment this
 * endpoint tried to use it).
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

require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
$userData = SessionManager::getUser();
$userId = (int)($userData['id'] ?? $userData['user_id'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

foreach (['card_suffix', 'institution', 'asset_type', 'identifier'] as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "{$field} is required"]);
        exit();
    }
}

$container = require_once ROOT_PATH . '/src/bootstrap.php';

try {
    $cardService = $container->get('Domain\Services\CardService');
    $swapService = $container->get('Domain\Services\SwapService');

    $sourcePayload = [
        'institution' => $input['institution'],
        'asset_type' => $input['asset_type'],
        'source_identifier' => $input['identifier'],
        'wallet_pin' => $input['pin'] ?? $input['wallet_pin'] ?? null,
        'pin' => $input['pin'] ?? $input['wallet_pin'] ?? null,
    ];

    $result = $cardService->activateCard($input['card_suffix'], $userId, $sourcePayload, $swapService);

    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[Activate.php] FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => ['file' => basename($e->getFile()), 'line' => $e->getLine()],
    ]);
}
