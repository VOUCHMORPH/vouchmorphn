<?php
declare(strict_types=1);

/**
 * VouchMorph Card Network - Unhook Endpoint
 *
 * The user-facing "press unhook" action. Releases every currently-HELD
 * source on the card's active hook and returns the funds to their
 * origin — the mirror image of hook.php.
 *
 * AUTH: session only, same reasoning as hook.php — this is the user's
 * own browser after logging in, not a bank/machine callback. No
 * X-API-Key here for the same reason it doesn't belong on hook.php.
 *
 * Only the card owner can unhook (enforced inside
 * CardService::releaseHook() against card_pool_hooks.user_id, never a
 * user_id passed in the request body).
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

$cardOwnerUserId = (int)(SessionManager::getUser()['user_id'] ?? 0);
if (!$cardOwnerUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not resolve card owner from session']);
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

// Accept either the hook_reference directly, or a card_suffix (resolves
// to that card's current HOOKED hook) — the dashboard (My.php) already
// has hook_reference on hand once a hook is showing, but accepting
// card_suffix too avoids making the frontend hold onto a reference it
// doesn't otherwise need.
$hookReference = $input['hook_reference'] ?? null;

if (!$hookReference) {
    if (empty($input['card_suffix'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Either hook_reference or card_suffix is required']);
        exit();
    }

    $db = $container->get(PDO::class);
    $lookupStmt = $db->prepare("
        SELECT hook_reference FROM card_pool_hooks
        WHERE card_suffix = :suffix AND status = 'HOOKED' AND expires_at > NOW()
        ORDER BY created_at DESC LIMIT 1
    ");
    $lookupStmt->execute([':suffix' => $input['card_suffix']]);
    $hookReference = $lookupStmt->fetchColumn();

    if (!$hookReference) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No active hook found for this card']);
        exit();
    }
}

// ============================================================
// EXECUTE
// ============================================================
$db = $container->get(PDO::class);
$swapService = $container->get('Domain\Services\SwapService');
$cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));

try {
    $result = $cardService->releaseHook($hookReference, $cardOwnerUserId, $swapService);

    // Partial release (some sources failed) is still a 200 with
    // success:false and a 'failed' array — the caller needs the detail,
    // not just a status code, to know what's still held.
    http_response_code($result['success'] ?? false ? 200 : ($result['status'] ?? null ? 200 : 422));
    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[Unhook] Unhook failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => 'Unhook failed due to a system error',
    ]);
}
