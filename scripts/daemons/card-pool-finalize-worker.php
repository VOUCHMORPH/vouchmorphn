<?php
declare(strict_types=1);

/**
 * card-pool-finalize-worker.php
 *
 * The piece named in authorize.php's own header comment but never
 * actually built: "the real debit/settlement happens asynchronously
 * afterward, picked up by scripts/daemons/card-pool-finalize-worker.php
 * from the card_pool_finalize_queue table". Confirmed live 21 Aug 2026:
 * card_pool_finalize_queue only ever had rows INSERTed into it
 * (authorize.php, Iso8583AuthorizationBridge) or DELETEd from it
 * (CardService::reversePooledSwipe()) — nothing ever read a PENDING
 * row and actually called finalizePooledSwipe(). Every "approved"
 * swipe this session held at authorization only; no source was ever
 * debited past the hold, no destination was ever settled.
 *
 * This is a polling worker, not a queue consumer library — deliberately
 * simple (SELECT ... FOR UPDATE SKIP LOCKED, process, mark done, sleep,
 * repeat) so it can run as a single long-lived Railway process without
 * needing a message broker. Safe to run more than one instance
 * concurrently — SKIP LOCKED means two workers never grab the same row.
 *
 * DEPLOYMENT: this needs to run as its OWN Railway service (a
 * background worker, not a web service — no HTTP port to bind to,
 * just `php card-pool-finalize-worker.php` as the start command),
 * separate from the vouchmorphn web service. It needs the same
 * DATABASE_URL and bootstrap dependencies as the rest of the app.
 *
 * SCHEMA NOTE: confirmed live 21 Aug 2026 against the real
 * card_pool_finalize_queue table — columns are id, hook_reference,
 * merchant_context, status, attempts, created_at, processed_at.
 * There is no result or last_error column, so a completed row's full
 * result and a failed row's error message are only ever written to
 * error_log, not persisted on the row itself. Worth adding both
 * columns later for failure visibility without digging through logs —
 * not done here since it wasn't asked for and changes the schema.
 */

require_once __DIR__ . '/../../bootstrap.php'; // adjust relative path to match this file's real deployed location
require_once __DIR__ . '/../../src/Domain/Services/CardService.php';
require_once __DIR__ . '/../../src/Domain/Services/ContributionCalculator.php';

use Domain\Services\CardService;
use Domain\Services\ContributionCalculator;

const POLL_INTERVAL_SECONDS = 3;
const MAX_ATTEMPTS_PER_ROW = 5;

function runWorkerLoop($container): void
{
    $db = $container->get(PDO::class);
    $swapService = $container->get('Domain\Services\SwapService');
    $settlement = $container->get('Domain\Services\Settlement\HybridSettlementStrategy');
    $cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));
    $contributionCalculator = new ContributionCalculator();

    error_log("[FinalizeWorker] Started. Polling card_pool_finalize_queue every " . POLL_INTERVAL_SECONDS . "s.");

    while (true) {
        try {
            processNextBatch($db, $cardService, $swapService, $settlement, $contributionCalculator);
        } catch (\Throwable $e) {
            // A failure in the polling loop itself must never kill the
            // worker process — log loudly and keep going, same
            // non-blocking discipline as every other background piece
            // in this codebase.
            error_log("[FinalizeWorker] CRITICAL: unhandled error in poll loop: " . $e->getMessage());
        }
        sleep(POLL_INTERVAL_SECONDS);
    }
}

function processNextBatch(
    PDO $db,
    CardService $cardService,
    $swapService,
    $settlement,
    ContributionCalculator $contributionCalculator
): void {
    $db->beginTransaction();

    try {
        // FOR UPDATE SKIP LOCKED: if multiple worker instances run
        // concurrently, each grabs a DIFFERENT pending row instead of
        // blocking on or double-processing the same one.
        $stmt = $db->prepare("
            SELECT id, hook_reference, merchant_context, attempts
            FROM card_pool_finalize_queue
            WHERE status = 'PENDING'
              AND (attempts IS NULL OR attempts < :max_attempts)
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        ");
        $stmt->execute([':max_attempts' => MAX_ATTEMPTS_PER_ROW]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $db->commit();
            return; // nothing pending — normal, quiet case
        }

        // Mark IN_PROGRESS immediately so a slow finalize doesn't get
        // picked up twice by another worker instance before it commits.
        $db->prepare("
            UPDATE card_pool_finalize_queue
            SET status = 'IN_PROGRESS', attempts = COALESCE(attempts, 0) + 1
            WHERE id = ?
        ")->execute([$row['id']]);

        $db->commit();

    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("[FinalizeWorker] Failed to claim a queue row: " . $e->getMessage());
        return;
    }

    // Actual finalize runs in its OWN transaction, inside
    // finalizePooledSwipe() itself — deliberately not nested inside the
    // claim transaction above, so a long-running finalize doesn't hold
    // the SKIP LOCKED row lock any longer than necessary for other
    // workers.
    $hookReference = $row['hook_reference'];
    $merchantContext = json_decode($row['merchant_context'], true) ?: [];

    error_log("[FinalizeWorker] Processing hook_reference={$hookReference} (attempt " . ($row['attempts'] + 1) . ")");

    try {
        $result = $cardService->finalizePooledSwipe(
            $hookReference,
            $swapService,
            $settlement,
            $contributionCalculator,
            $merchantContext
        );

        $db->prepare("
            UPDATE card_pool_finalize_queue
            SET status = 'COMPLETED', processed_at = NOW()
            WHERE id = ?
        ")->execute([$row['id']]);

        error_log("[FinalizeWorker] SUCCESS hook_reference={$hookReference} total_debited=" .
            ($result['total_debited'] ?? 'unknown') . " full_result=" . json_encode($result));

    } catch (\Throwable $e) {
        $newStatus = ($row['attempts'] + 1 >= MAX_ATTEMPTS_PER_ROW) ? 'FAILED' : 'PENDING';

        error_log("[FinalizeWorker] FAILED hook_reference={$hookReference}: " . $e->getMessage() .
            " — status set to {$newStatus}");

        try {
            $db->prepare("
                UPDATE card_pool_finalize_queue
                SET status = ?, processed_at = CASE WHEN ? = 'FAILED' THEN NOW() ELSE processed_at END
                WHERE id = ?
            ")->execute([$newStatus, $newStatus, $row['id']]);
        } catch (\Throwable $updateErr) {
            error_log("[FinalizeWorker] EMERGENCY: could not even update queue row status after finalize failure: " . $updateErr->getMessage());
        }

        if ($newStatus === 'FAILED') {
            error_log("[FinalizeWorker] CRITICAL: hook_reference={$hookReference} exhausted {$row['attempts']} attempts and is now FAILED — needs manual investigation. A swipe was authorized but could not be finalized after repeated attempts.");
        }
    }
}

// ============================================================
// ENTRY POINT
// ============================================================
$container = require_once __DIR__ . '/../../bootstrap.php'; // adjust to match real deployment path
runWorkerLoop($container);
