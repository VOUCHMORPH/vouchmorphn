<?php
declare(strict_types=1);

/**
 * VouchMorph Card Network - Unhook Single Source Endpoint
 *
 * Releases ONE currently-HELD source from a card's active hook,
 * leaving every other contributor untouched — the per-source
 * counterpart to unhook.php (which releases everything at once).
 *
 * AUTH: session only, same reasoning as hook.php/unhook.php — this is
 * the user's own browser after logging in. Either the card owner or
 * the source's own contributor may call this (enforced inside
 * CardService::releaseHookSource() against card_pool_hooks.user_id
 * and card_pool_hook_sources.owner_user_id, never a user_id passed in
 * the request body).
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Domain\Services\CardService;
use Application\Utils\SessionManager;

SessionManager::start();

// An admin session's id is an admin_id, which can equal some customer's
// user_id - so only a customer session may release a customer's holds.
if (!SessionManager::isLoggedIn() || !SessionManager::isUser()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$requestingUserId = (int)(SessionManager::getUser()['user_id'] ?? 0);
if (!$requestingUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not resolve user from session']);
    exit();
}

// ============================================================
// INPUT
// ============================================================
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload: ' . json_last_error_msg()]);
    exit();
}

if (empty($input['hook_source_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'hook_source_id is required']);
    exit();
}

// ============================================================
// EXECUTE
// ============================================================
$db = $container->get(PDO::class);
$swapService = $container->get('Domain\Services\SwapService');
$cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));

try {
    $result = $cardService->releaseHookSource((int)$input['hook_source_id'], $requestingUserId, $swapService);

    http_response_code($result['success'] ?? false ? 200 : 422);
    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[UnhookSource] Unhook failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => 'Unhook failed due to a system error',
    ]);
}
