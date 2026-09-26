<?php
declare(strict_types=1);

/**
 * VouchMorph Card — Get Card Sources
 *
 * Resolves a VouchMorph Card into the flat list of real, hold-able
 * sources hooked to it (card_pool_hook_sources), in the exact shape
 * MultiSourceSwapExecutor::execute() expects for pool['sources']:
 * each entry has institution, asset_type, identifier.
 *
 * This is the resolution step MultiSourceSwapExecutor itself does NOT
 * do — it only executes a pool built from already-real sources. A
 * VouchMorph Card is never passed into pool['sources'] directly; it's
 * expanded here first, one layer up, before a swap request is ever
 * built.
 *
 * Defense in depth: even though CardService::hookSourcesToCard() now
 * refuses to let a card be hooked to a card, this resolver also skips
 * (rather than trusts) any hooked "source" that is itself a card, in
 * case a hook created before that guard existed still has one.
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
// (This used to write the request's cookies - the session id - to the log.)
if (!SessionManager::isLoggedIn() || !SessionManager::isUser()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
$viewerUserId = (int)(SessionManager::getUser()['user_id'] ?? 0);


$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || empty($input['card_suffix'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'card_suffix is required']);
    exit();
}

$cardSuffix = preg_replace('/[^A-Za-z0-9]/', '', (string)$input['card_suffix']);

$db = $container->get(PDO::class);
$swapService = $container->get('Domain\Services\SwapService');

try {
    // Confirm the card exists and is active before resolving anything.
    // Filter by lifecycle_status = 'ACTIVE' in the query itself, not only
    // in PHP afterward -- card_suffix alone can match more than one row
    // (confirmed in production: collisions with unassigned IN_BATCH
    // physical inventory and with other users' cards), so an unrelated
    // inactive row must not be allowed to block resolution of a card
    // that really is active.
    $cardStmt = $db->prepare("
        SELECT card_suffix, card_scheme, cardholder_name, lifecycle_status, currency, user_id
        FROM message_cards WHERE card_suffix = :suffix AND lifecycle_status = 'ACTIVE'
    ");
    $cardStmt->execute([':suffix' => $cardSuffix]);
    $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Card not found or not active']);
        exit();
    }

    // FIX 1: Changed status from 'ACTIVE' to 'HOOKED' to match what
    // hookSourcesToCard() actually writes to the database.
    // FIX 2: Changed 'identifier' to 'source_identifier' to match the
    // actual column name in card_pool_hook_sources.
    // FIX 3: This only ever filtered the PARENT hook's status
    // (cph.status = 'HOOKED') — it never checked cphs.status on the
    // individual source row itself. So a source unhooked on its own
    // (CardService::releaseHookSource(), status flips to 'RELEASED')
    // while the rest of the hook stays 'HOOKED' kept being resolved
    // here as a live, spendable source for a new swap. Every other
    // reader of this table (My.php, authorizePooledSwipe(), etc.)
    // already filters cphs.status = 'HELD'; this was the one place
    // that didn't.
        $sourceStmt = $db->prepare("
        SELECT
            cphs.institution,
            cphs.asset_type,
            cphs.source_identifier AS identifier,
            cphs.owner_user_id,
            cphs.held_amount AS authorized_amount,
            cphs.hold_reference
        FROM card_pool_hook_sources cphs
        JOIN card_pool_hooks cph ON cph.id = cphs.hook_id
        WHERE cph.card_suffix = :suffix
          AND cph.status = 'HOOKED'
          AND cphs.status = 'HELD'
          AND cph.id = (
              SELECT id FROM card_pool_hooks
              WHERE card_suffix = :suffix2 AND status = 'HOOKED'
              ORDER BY created_at DESC LIMIT 1
          )
    ");
    $sourceStmt->execute([':suffix' => $cardSuffix, ':suffix2' => $cardSuffix]);
    $hookedRows = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);

    // Only the card's owner, or someone with a source of their own on it,
    // sees what is hooked - and a contributor sees the other contributors'
    // sources masked, without their live balances. This used to answer any
    // signed-in user for any card suffix with every contributor's full
    // account number, user id and live balance.
    $viewerIsOwner = $viewerUserId > 0 && (int)$card['user_id'] === $viewerUserId;
    $viewerIsContributor = in_array($viewerUserId, array_map('intval', array_column($hookedRows, 'owner_user_id')), true);
    if (!$viewerIsOwner && !$viewerIsContributor) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "You can only see the sources on your own card, or on a card you've hooked a source to."]);
        exit();
    }

    if (empty($hookedRows)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'This card has no sources hooked to it yet. Hook a source to it before using it to pay.',
        ]);
        exit();
    }

    $sources = [];
    $skippedCardSources = 0;

    foreach ($hookedRows as $row) {
        // Defense in depth — see file header. A hooked "source" that is
        // itself a VouchMorph Card should never exist (hookSourcesToCard()
        // blocks it going forward), but skip rather than trust it if one
        // is ever found, instead of passing institution=VOUCHMORPH into
        // a swap executor that has no idea what to do with it.
        if (strtoupper($row['institution']) === 'VOUCHMORPH' || $row['asset_type'] === 'VOUCHMORPH_CARD') {
            $skippedCardSources++;
            error_log("[GetCardSources] Skipped a card-as-source hook on {$cardSuffix} — should not exist, hookSourcesToCard() should have blocked it at creation.");
            continue;
        }

        $isViewersOwn = (int)$row['owner_user_id'] === $viewerUserId;
        if (!$viewerIsOwner && !$isViewersOwn) {
            $sources[] = [
                'institution' => $row['institution'],
                'asset_type' => $row['asset_type'],
                'identifier' => \Domain\Services\SourceOwnershipGuard::mask((string)$row['identifier']),
                'owner_user_id' => null,
                'authorized_amount' => (float)$row['authorized_amount'],
                'available_balance' => round((float)$row['authorized_amount'], 2),
            ];
            continue;
        }

        // Live available balance, capped by what was actually authorized
        // at hook time — never offer more than the source owner agreed to,
        // even if their real balance is currently higher.
        $liveBalance = 0.0;
        try {
            $liveBalance = $swapService->getSourceAvailableBalance([
                'institution' => $row['institution'],
                'asset_type' => $row['asset_type'],
                'identifier' => $row['identifier'],
            ]);
        } catch (\Throwable $e) {
            error_log("[GetCardSources] Balance check failed for {$row['institution']}: " . $e->getMessage());
        }

        $available = min((float)$row['authorized_amount'], $liveBalance);

        $sources[] = [
            'institution' => $row['institution'],
            'asset_type' => $row['asset_type'],
            'identifier' => $row['identifier'],
            'owner_user_id' => (int)$row['owner_user_id'],
            'authorized_amount' => (float)$row['authorized_amount'],
            'available_balance' => round($available, 2),
        ];
    }

    if (empty($sources)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'This card has no valid hooked sources available right now.',
        ]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'card_suffix' => $card['card_suffix'],
            'card_scheme' => $card['card_scheme'] ?? null,
            'currency' => $card['currency'] ?? 'BWP',
            'sources' => $sources,
            'total_available' => round(array_sum(array_column($sources, 'available_balance')), 2),
            'skipped_invalid_sources' => $skippedCardSources,
        ],
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[GetCardSources] Failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not resolve this card\'s sources right now.']);
}
