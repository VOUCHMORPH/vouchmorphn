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
use Infrastructure\SMS\SmsNotificationService;

$db = $container->get(PDO::class);
$cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));
$swapService = $container->get('Domain\Services\SwapService') ?? null;
$settlement = new HybridSettlementStrategy($db);
$contributionCalculator = new ContributionCalculator();

// ============================================================
// SMS ALERT SERVICE
// ============================================================
// Load SMS config from participants to alert ops when a job
// permanently fails after 5 attempts. This is a critical alert
// because the merchant has already been paid, but settlement
// and shortfall billing are stuck - ops needs to intervene.
$smsConfig = [];
$participants = $container->get('participants') ?? [];
if (!empty($participants['sms'])) {
    $smsConfig = $participants['sms'];
} else {
    // Fallback: try to load from country config
    $countryConfig = $container->get('countryConfig') ?? [];
    $smsConfig = $countryConfig['sms'] ?? [];
}
$smsService = !empty($smsConfig) ? new SmsNotificationService($smsConfig) : null;

if ($smsService) {
    echo "[CardPoolWorker] SMS service initialized\n";
} else {
    echo "[CardPoolWorker] SMS service NOT available - alerts will be logged only\n";
}

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

                // ============================================================
                // SMS ALERT TO OPS
                // ============================================================
                $opsPhone = getenv('OPS_ALERT_PHONE');
                if ($opsPhone && $smsService) {
                    try {
                        $message = "URGENT: Card pool finalize FAILED after 5 attempts - {$job['hook_reference']}. Merchant already paid, settlement/billing incomplete. Needs manual review.";
                        $smsService->send($opsPhone, $message);
                        echo "[CardPoolWorker] SMS alert sent to {$opsPhone} for {$job['hook_reference']}\n";
                    } catch (Exception $smsErr) {
                        error_log("[CardPoolWorker] Alert SMS itself failed: " . $smsErr->getMessage());
                        echo "[CardPoolWorker] Alert SMS FAILED: " . $smsErr->getMessage() . "\n";
                    }
                } elseif (!$opsPhone) {
                    error_log("[CardPoolWorker] OPS_ALERT_PHONE not set - SMS alert not sent");
                    echo "[CardPoolWorker] OPS_ALERT_PHONE not set - SMS alert not sent\n";
                } elseif (!$smsService) {
                    error_log("[CardPoolWorker] SMS service not available - alert not sent");
                    echo "[CardPoolWorker] SMS service not available - alert not sent\n";
                }
            }
        }
    }
}
