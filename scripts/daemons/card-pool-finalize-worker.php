<?php
declare(strict_types=1);

/**
 * card-pool-finalize-worker.php
 * Processes card_pool_finalize_queue — confirmed columns: id,
 * hook_reference, merchant_context, status, attempts, created_at,
 * processed_at.
 */

require_once __DIR__ . '/../../src/bootstrap.php';
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
            return;
        }

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
            error_log("[FinalizeWorker] EMERGENCY: could not update queue row: " . $updateErr->getMessage());
        }

        if ($newStatus === 'FAILED') {
            error_log("[FinalizeWorker] CRITICAL: hook_reference={$hookReference} exhausted attempts — needs manual investigation.");
        }
    }
}

$container = require_once __DIR__ . '/../../src/bootstrap.php';
runWorkerLoop($container);
