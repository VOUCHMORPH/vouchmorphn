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
error_log("[GetCardSources] cookie=" . json_encode($_COOKIE) . " session_id=" . session_id());
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

$cardSuffix = preg_replace('/[^A-Za-z0-9]/', '', (string)$input['card_suffix']);

$db = $container->get(PDO::class);
$swapService = $container->get('Domain\Services\SwapService');

try {
    // Confirm the card exists and is active before resolving anything.
    $cardStmt = $db->prepare("
        SELECT card_suffix, card_scheme, cardholder_name, lifecycle_status, currency
        FROM message_cards WHERE card_suffix = :suffix
    ");
    $cardStmt->execute([':suffix' => $cardSuffix]);
    $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Card not found']);
        exit();
    }
    if ($card['lifecycle_status'] !== 'ACTIVE') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This card is not active']);
        exit();
    }

    // FIX 1: Changed status from 'ACTIVE' to 'HOOKED' to match what
    // hookSourcesToCard() actually writes to the database.
    // FIX 2: Changed 'identifier' to 'source_identifier' to match the
    // actual column name in card_pool_hook_sources.
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
    ");
    $sourceStmt->execute([':suffix' => $cardSuffix]);
    $hookedRows = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);

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
