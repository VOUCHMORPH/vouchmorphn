<?php
declare(strict_types=1);

/**
 * Card Pool Finalize Worker
 *
 * Polls card_pool_finalize_queue for approved swipes and runs the real,
 * potentially-slow work: debiting pooled sources, releasing unused hold
 * remainders, settling to the merchant, and billing any shortfall to the
 * specific source account whose debit failed - never the card owner,
 * never the other sources.
 *
 * Run continuously (systemd/supervisor), same pattern as outbox-worker.php.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
$container = require_once ROOT_PATH . '/src/bootstrap.php';

use Domain\Services\CardService;
use Domain\Services\SwapService;
use Domain\Services\ContributionCalculator;
use Domain\Services\Settlement\HybridSettlementStrategy;

$db = $container->get(PDO::class);
$cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));
$swapService = $container->get('Domain\Services\SwapService') ?? null;
$settlement = new HybridSettlementStrategy($db);
$contributionCalculator = new ContributionCalculator();

echo "[CardPoolWorker] Started\n";

while (true) {
    $stmt = $db->prepare("
        SELECT * FROM card_pool_finalize_queue
        WHERE status = 'PENDING' AND attempts < 5
        ORDER BY created_at ASC
        LIMIT 10
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($jobs)) {
        sleep(2);
        continue;
    }

    foreach ($jobs as $job) {
        $db->prepare("UPDATE card_pool_finalize_queue SET status = 'PROCESSING', attempts = attempts + 1 WHERE id = ?")
            ->execute([$job['id']]);

        try {
            $merchantContext = json_decode($job['merchant_context'], true) ?? [];

            $result = $cardService->finalizePooledSwipe(
                $job['hook_reference'],
                $swapService,
                $settlement,
                $contributionCalculator,
                $merchantContext
            );

            $db->prepare("UPDATE card_pool_finalize_queue SET status = 'DONE', processed_at = NOW() WHERE id = ?")
                ->execute([$job['id']]);

            echo "[CardPoolWorker] Finalized {$job['hook_reference']} - " .
                 (empty($result['shortfall_bills']) ? "no shortfalls" : count($result['shortfall_bills']) . " shortfall bill(s)") . "\n";

        } catch (Exception $e) {
            error_log("[CardPoolWorker] Finalize failed for {$job['hook_reference']}: " . $e->getMessage());

            $status = $job['attempts'] + 1 >= 5 ? 'FAILED' : 'PENDING';
            $db->prepare("UPDATE card_pool_finalize_queue SET status = ? WHERE id = ?")
                ->execute([$status, $job['id']]);

            if ($status === 'FAILED') {
                error_log("[CardPoolWorker] GIVING UP on {$job['hook_reference']} after 5 attempts - needs manual review. The merchant has already been paid; this failure means settlement/shortfall billing may be incomplete.");
            }
        }
    }
}
