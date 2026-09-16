<?php
declare(strict_types=1);

/**
 * stuck_job_recovery.php
 *
 * Run this on a schedule (every 2-5 minutes) — cron, Railway's cron
 * plugin, or any scheduler you already have:
 *
 * Written as an explicit minute list, not a step value: a literal "*"
 * followed by "/" in this line closed the block comment, so everything
 * after it parsed as PHP and this script died with "syntax error,
 * unexpected token *" before recovering a single job. The list below is
 * equivalent to every 3 minutes.
 *
 *   0,3,6,9,12,15,18,21,24,27,30,33,36,39,42,45,48,51,54,57 * * * * php /var/www/html/stuck_job_recovery.php >> /var/log/stuck_job_recovery.log 2>&1
 *
 * Finds any job left claimed by a worker that crashed, was killed, or
 * lost its database connection before finishing, and recovers it —
 * either back to 'pending' for another worker to retry, or to
 * 'permanently_failed' if it's already exhausted its retry attempts
 * (same threshold as a genuine processing failure, not treated any
 * more leniently just because the cause was a crash rather than a
 * real error).
 *
 * This is intentionally a single one-shot run per invocation, not a
 * long-running loop like worker.php — cron re-running it every few
 * minutes IS the loop. Safe to run overlapping invocations too: the
 * underlying SQL only touches rows whose claimed_at is already past
 * the threshold, so nothing here can interfere with jobs a worker is
 * actively, legitimately still processing.
 */

require_once __DIR__ . '/src/Core/Database/DBConnection.php';
require_once __DIR__ . '/src/Domain/Services/BatchExecutionQueueService.php';

use Core\Database\DBConnection;
use Domain\Services\BatchExecutionQueueService;

$stuckThresholdSeconds = (int)(getenv('STUCK_JOB_THRESHOLD_SECONDS') ?: 600);

$pdo = DBConnection::getConnection();
$queue = new BatchExecutionQueueService($pdo);

$startedAt = date('Y-m-d H:i:s');

try {
    $recovered = $queue->recoverStuckJobs($stuckThresholdSeconds);

    if (empty($recovered)) {
        echo "[{$startedAt}] No stuck jobs found (threshold: {$stuckThresholdSeconds}s).\n";
        exit(0);
    }

    echo "[{$startedAt}] Recovered " . count($recovered) . " stuck job(s):\n";
    foreach ($recovered as $job) {
        echo "  - job {$job['job_id']} (batch {$job['batch_id']}), claimed by '{$job['claimed_by']}', stuck for {$job['age_seconds']}s\n";
    }

    // A high count in one run is worth a human noticing — could mean a
    // worker fleet crashed together (bad deploy, DB connectivity blip),
    // not just isolated one-off failures. This prints loudly to stdout/
    // the cron log; wire it into whatever alerting you already have
    // (Slack webhook, PagerDuty, etc.) once that exists.
    if (count($recovered) >= 20) {
        echo "[{$startedAt}] ⚠️  WARNING: " . count($recovered) . " stuck jobs recovered in a single run — this is unusually high and may indicate a worker fleet crash, not isolated failures. Investigate worker logs.\n";
    }

    exit(0);
} catch (\Throwable $e) {
    error_log("[stuck_job_recovery] Fatal error: " . $e->getMessage());
    echo "[{$startedAt}] FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
