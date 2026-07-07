<?php
declare(strict_types=1);

/**
 * scripts/cron/card-pool-queue-watchdog.php
 *
 * Runs on a schedule (cron, every 2-5 minutes) - NOT a long-running daemon
 * like card-pool-finalize-worker.php. Its only job is to detect when the
 * worker itself has stopped running or fallen behind, which the worker's
 * own dead-letter alert cannot catch (that alert only fires after 5
 * processing attempts on a given job - if nothing is polling the queue at
 * all, no job ever reaches attempt #1, let alone #5, so the worker-side
 * alert stays silent while approved swipes sit unsettled indefinitely).
 *
 * This is a genuinely different failure mode from the worker's dead-letter
 * case:
 *   - Worker dead-letter alert: "the worker IS running, but this specific
 *     job keeps failing after real attempts."
 *   - This watchdog: "nothing is processing the queue AT ALL - the worker
 *     process itself may be down, crashed, or was never started."
 *
 * Add to crontab, e.g.:
 *   every 3 minutes -> php /path/to/scripts/cron/card-pool-queue-watchdog.php
 */

define('ROOT_PATH', dirname(__DIR__, 2));
$container = require_once ROOT_PATH . '/src/bootstrap.php';

use Infrastructure\SMS\SmsNotificationService;

$db = $container->get(PDO::class);

// Threshold: if a job has been sitting in PENDING for longer than this,
// something is wrong - either the worker isn't running, or it's badly
// backlogged. 2 minutes is generous relative to the worker's own 2-second
// poll loop; a healthy worker should never let a job sit this long.
const STALE_THRESHOLD_MINUTES = 2;

$stmt = $db->prepare("
    SELECT hook_reference, created_at, attempts
    FROM card_pool_finalize_queue
    WHERE status = 'PENDING'
    AND created_at < NOW() - INTERVAL '" . STALE_THRESHOLD_MINUTES . " minutes'
    ORDER BY created_at ASC
");
$stmt->execute();
$staleJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$staleCount = count($staleJobs);

if ($staleCount === 0) {
    echo "[CardPoolWatchdog] OK - no stale PENDING jobs\n";
    exit(0);
}

$oldestAgeMinutes = null;
if (!empty($staleJobs)) {
    $oldest = end($staleJobs);
    $oldestAgeMinutes = round((time() - strtotime($oldest['created_at'])) / 60, 1);
}

error_log("[CardPoolWatchdog] ALERT - {$staleCount} stale PENDING job(s), oldest is {$oldestAgeMinutes} minute(s) old. The finalize worker may not be running.");

// ============================================================
// SMS ALERT - same escalation path as the worker's own dead-letter alert,
// same OPS_ALERT_PHONE, so ops gets a consistent single channel for both
// failure modes rather than two different alerting mechanisms to watch.
// ============================================================
$opsPhone = getenv('OPS_ALERT_PHONE');
$participants = $container->get('participants') ?? [];
$smsConfig = $participants['sms'] ?? ($container->get('countryConfig')['sms'] ?? []);
$smsService = !empty($smsConfig) ? new SmsNotificationService($smsConfig) : null;

if ($opsPhone && $smsService) {
    try {
        $message = "URGENT: Card pool finalize queue has {$staleCount} stale PENDING job(s), " .
                   "oldest is {$oldestAgeMinutes} min old. The finalize worker may be down. " .
                   "Merchants may have approved swipes that are not yet settled.";
        $smsService->send($opsPhone, $message);
        echo "[CardPoolWatchdog] SMS alert sent to {$opsPhone}\n";
    } catch (Exception $smsErr) {
        error_log("[CardPoolWatchdog] Alert SMS itself failed: " . $smsErr->getMessage());
        echo "[CardPoolWatchdog] SMS alert FAILED: " . $smsErr->getMessage() . "\n";
    }
} else {
    echo "[CardPoolWatchdog] Could not send SMS - OPS_ALERT_PHONE or SmsNotificationService unavailable\n";
    error_log("[CardPoolWatchdog] Could not send SMS alert - OPS_ALERT_PHONE or SmsNotificationService unavailable");
}

echo "[CardPoolWatchdog] {$staleCount} stale job(s) detected - see error_log for details\n";
exit(1); // non-zero exit lets cron/monitoring systems flag this run as unhealthy too
