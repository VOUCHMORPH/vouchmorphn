<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Lookup by Suffix
 *
 * Manual-entry counterpart to ResolveQr.php: same "whose card is this,
 * confirm before hooking" contract, but for a user who types a card
 * suffix directly instead of scanning. card_suffix is already treated
 * as a public, non-secret handle everywhere else in this codebase
 * (URLs, activation references, the QR payload itself) — so a direct
 * lookup here needs no signature/crypto, unlike ResolveQr.php's QR
 * decode path. Hooking itself still goes through hook.php's own
 * consent/verify/hold pipeline; this endpoint only identifies the card.
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
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || empty($input['card_suffix'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'card_suffix is required']);
    exit();
}

// Strip anything but alphanumerics — this is a lookup key, not a
// query fragment, but no reason to trust raw user input further than
// it needs to be trusted.
$suffix = preg_replace('/[^A-Za-z0-9]/', '', (string)$input['card_suffix']);
if ($suffix === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'card_suffix is required']);
    exit();
}

$db = $container->get(PDO::class);

try {
    // Filter by lifecycle_status = 'ACTIVE' in the query itself, not only
    // in PHP afterward -- card_suffix alone can match more than one row
    // (confirmed in production: it collides with unassigned IN_BATCH
    // physical inventory and can collide with other users' cards), so an
    // unrelated inactive row must not be allowed to block resolution of
    // a card that really is active.
    $stmt = $db->prepare("
        SELECT card_suffix, cardholder_name, lifecycle_status
        FROM message_cards WHERE card_suffix = :suffix AND lifecycle_status = 'ACTIVE'
    ");
    $stmt->execute([':suffix' => $suffix]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No active card found with that number.']);
        exit();
    }

    // Same "first name + last initial only" privacy rule as ResolveQr.php
    // — this is a stranger's card being confirmed, not a full identity reveal.
    $nameParts = preg_split('/\s+/', trim((string)$card['cardholder_name']));
    $displayName = count($nameParts) >= 2
        ? $nameParts[0] . ' ' . mb_substr(end($nameParts), 0, 1) . '.'
        // BUG FIX: ?? only falls back on null, but preg_split() on an
        // empty/whitespace-only name returns [''] - a non-null empty
        // string - so a card with no cardholder_name on file rendered as
        // "Hook to 's Card" with the name missing. ?: falls back on any
        // falsy value, empty string included.
        : ($nameParts[0] ?: 'VouchMorph user');

    echo json_encode([
        'success' => true,
        'data' => [
            'card_suffix' => $card['card_suffix'],
            'display_name' => $displayName,
        ],
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[LookupBySuffix] Failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Lookup failed.']);
}
