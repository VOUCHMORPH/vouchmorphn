<?php
declare(strict_types=1);

/**
 * worker.php — batch execution queue worker
 *
 * Run as many of these as you want in parallel to increase throughput:
 *   php worker.php worker-1 &
 *   php worker.php worker-2 &
 *   php worker.php worker-3 &
 * ...or as N separate processes under systemd/supervisor, or N replicas
 * of a small Railway service pointed at this script. There is no shared
 * in-memory state between workers — every coordination point (claiming
 * jobs, rate limiting) goes through the database's row locking, so
 * workers can be added or removed at any time without any handshake.
 *
 * ============================================================================
 * IMPORTANT — things that still need real values before this touches
 * production, not left as an exercise:
 * ============================================================================
 *
 * 1. $sourceInstitution / how each job builds its SwapService payload is
 *    a SKELETON below — it shows the shape (claim → build payload → call
 *    SwapService → mark completed/failed) but the actual payload
 *    construction needs to pull the REAL destination/batch data from
 *    disbursement_destinations and disbursement_batches, matching
 *    whatever review_batch.php's original synchronous execute loop was
 *    already building per destination. I don't have that exact payload-
 *    building code in front of me this session (it lives in
 *    review_batch.php, which handles several destination types —
 *    account/wallet/identity — differently). Wiring that in is the next
 *    concrete step, ideally done by whoever already owns that file, so
 *    it's a lift-and-shift of proven logic rather than a rewrite.
 *
 * 2. Process supervision: if a worker crashes mid-job, that job is left
 *    in 'claimed' or 'processing' status indefinitely. A separate,
 *    simple cron ("if claimed_at < NOW() - 10 minutes, reset to
 *    pending") is needed alongside this — same stuck-job-recovery
 *    pattern already used for whole-batch execution elsewhere in this
 *    codebase, just applied per-job now.
 *
 * 3. Graceful shutdown: this loop checks for a SIGTERM between jobs, not
 *    mid-job, so a `docker stop` / Railway redeploy won't corrupt an
 *    in-progress job — but confirm your process manager gives it a few
 *    seconds grace period before SIGKILL.
 * ============================================================================
 */

require_once __DIR__ . '/src/Core/Database/DBConnection.php';
require_once __DIR__ . '/src/Domain/Services/BatchExecutionQueueService.php';
// require_once __DIR__ . '/src/Domain/Services/SwapService.php'; // uncomment once wired in

use Core\Database\DBConnection;
use Domain\Services\BatchExecutionQueueService;

$workerId = $argv[1] ?? ('worker-' . getmypid());
$batchClaimSize = (int)(getenv('WORKER_CLAIM_SIZE') ?: 20);
$pollIntervalSeconds = (int)(getenv('WORKER_POLL_INTERVAL') ?: 2);

$shouldStop = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shouldStop) { $shouldStop = true; });
    pcntl_signal(SIGINT, function () use (&$shouldStop) { $shouldStop = true; });
}

$pdo = DBConnection::getConnection();
$queue = new BatchExecutionQueueService($pdo);

error_log("[worker:{$workerId}] Started. Claim size: {$batchClaimSize}, poll interval: {$pollIntervalSeconds}s");

while (!$shouldStop) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    try {
        $jobs = $queue->claimJobs($workerId, $batchClaimSize);
    } catch (\Throwable $e) {
        error_log("[worker:{$workerId}] claimJobs failed: " . $e->getMessage());
        sleep($pollIntervalSeconds);
        continue;
    }

    if (empty($jobs)) {
        sleep($pollIntervalSeconds);
        continue;
    }

    error_log("[worker:{$workerId}] Claimed " . count($jobs) . " job(s)");

    foreach ($jobs as $job) {
        processJob($pdo, $queue, $job, $workerId);

        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        if ($shouldStop) {
            error_log("[worker:{$workerId}] Shutdown signal received, finishing current batch of jobs then exiting.");
            break;
        }
    }
}

error_log("[worker:{$workerId}] Stopped cleanly.");
exit(0);


/**
 * Processes exactly one job: builds the SwapService payload for its
 * destination, calls the atomic swap, and records completion or failure
 * back to the queue. THE PAYLOAD-BUILDING SECTION IS A PLACEHOLDER — see
 * note #1 at the top of this file.
 */
function processJob(PDO $pdo, BatchExecutionQueueService $queue, array $job, string $workerId): void
{
    $jobId = (int)$job['id'];

    try {
        $stmt = $pdo->prepare("
            UPDATE batch_execution_jobs SET status = 'processing', updated_at = NOW() WHERE id = :id
        ");
        $stmt->execute([':id' => $jobId]);

        // ============================================================
        // PLACEHOLDER — replace with the real destination/batch fetch +
        // SwapService payload construction, matching whatever
        // review_batch.php's synchronous execute loop already builds for
        // ACCOUNT / WALLET / IDENTITY destinations respectively.
        // ============================================================
        $stmt = $pdo->prepare("
            SELECT d.*, b.source_institution, b.currency, b.organization_id
            FROM disbursement_destinations d
            INNER JOIN disbursement_batches b ON b.id = d.batch_id
            WHERE d.id = :destination_id
        ");
        $stmt->execute([':destination_id' => $job['destination_id']]);
        $destination = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$destination) {
            $queue->markFailed($jobId, "Destination {$job['destination_id']} not found");
            return;
        }

        // $swapService = new \Domain\Services\SwapService($pdo, $config, $countryCode, $logger);
        // $payload = [ /* built from $destination, matching review_batch.php's existing logic */ ];
        // $payload['idempotency_key'] = $job['idempotency_key'];
        // $result = $swapService->executeAtomicSwap($payload);
        //
        // if (($result['status'] ?? null) === 'success' || ($result['status'] ?? null) === 'pending') {
        //     $queue->markCompleted($jobId, $result['reference'] ?? null);
        // } else {
        //     $queue->markFailed($jobId, $result['message'] ?? 'Unknown failure');
        // }

        error_log("[worker:{$workerId}] Job {$jobId} (destination {$job['destination_id']}): SwapService call not yet wired in — see PLACEHOLDER note in worker.php");
        $queue->markFailed($jobId, 'SwapService integration not yet wired into worker.php — placeholder only');

    } catch (\Throwable $e) {
        error_log("[worker:{$workerId}] Job {$jobId} threw: " . $e->getMessage());
        try {
            $queue->markFailed($jobId, $e->getMessage());
        } catch (\Throwable $inner) {
            error_log("[worker:{$workerId}] markFailed itself threw for job {$jobId}: " . $inner->getMessage());
        }
    }
}
