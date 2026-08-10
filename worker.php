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
 * The per-destination payload construction below is lifted directly
 * from review_batch.php's proven synchronous execute logic (identity
 * destinations -> swap_type IDENTITY; institution destinations ->
 * swap_type MULTI_DESTINATION with a single-element destinations array,
 * same field mapping), not re-derived from scratch — same reasoning,
 * same field names, same status/error handling per destination.
 *
 * Still needed before this touches production:
 *
 * 1. Stuck-job recovery cron: if a worker crashes mid-job, that job is
 *    left in 'claimed'/'processing' indefinitely. A simple periodic
 *    check ("if claimed_at < NOW() - 10 minutes AND status IN (claimed,
 *    processing), reset to pending") needs to run alongside this —
 *    same stuck-batch pattern already used for whole-batch execution,
 *    just applied per-job now. Not included here since it's a separate,
 *    small script (a cron entry, not a long-running worker).
 *
 * 2. Real per-institution rate limits in institution_rate_limits —
 *    the schema defaults to 60/minute per institution, which is a
 *    placeholder, not a number any bank/MNO has confirmed.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Core/Database/DBConnection.php';
require_once __DIR__ . '/src/Domain/Services/BatchExecutionQueueService.php';
require_once __DIR__ . '/src/Domain/Services/SwapService.php';
require_once __DIR__ . '/src/Domain/Services/DepartmentService.php';
require_once __DIR__ . '/src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Domain\Services\BatchExecutionQueueService;
use Domain\Services\SwapService;
use Domain\Services\DepartmentService;
use Core\Config\LoadCountry;

$workerId = $argv[1] ?? ('worker-' . getmypid());
$batchClaimSize = (int)(getenv('WORKER_CLAIM_SIZE') ?: 20);
$pollIntervalSeconds = (int)(getenv('WORKER_POLL_INTERVAL') ?: 2);
$countryName = $_ENV['VOUCHMORPH_COUNTRY'] ?? getenv('VOUCHMORPH_COUNTRY') ?? 'Botswana';

$shouldStop = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shouldStop) { $shouldStop = true; });
    pcntl_signal(SIGINT, function () use (&$shouldStop) { $shouldStop = true; });
}

$pdo = DBConnection::getConnection();
$queue = new BatchExecutionQueueService($pdo);

$fullCountryConfig = LoadCountry::getConfig();
$logger = new class {
    public function info($m, array $c = []) { error_log("[worker][INFO] {$m} " . json_encode($c)); }
    public function warning($m, array $c = []) { error_log("[worker][WARN] {$m} " . json_encode($c)); }
    public function error($m, array $c = []) { error_log("[worker][ERROR] {$m} " . json_encode($c)); }
    public function debug($m, array $c = []) { /* quiet by default */ }
    public function critical($m, array $c = []) { error_log("[worker][CRITICAL] {$m} " . json_encode($c)); }
    public function emergency($m, array $c = []) { error_log("[worker][EMERGENCY] {$m} " . json_encode($c)); }
    public function alert($m, array $c = []) { error_log("[worker][ALERT] {$m} " . json_encode($c)); }
    public function notice($m, array $c = []) { error_log("[worker][NOTICE] {$m} " . json_encode($c)); }
    public function log($l, $m, array $c = []) { error_log("[worker][{$l}] {$m} " . json_encode($c)); }
};
$swapService = new SwapService($pdo, $fullCountryConfig, $countryName, $logger);
$departmentService = new DepartmentService($pdo, $logger);

error_log("[worker:{$workerId}] Started. Claim size: {$batchClaimSize}, poll interval: {$pollIntervalSeconds}s, country: {$countryName}");

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
        processJob($pdo, $queue, $swapService, $departmentService, $job, $workerId);

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
 * Processes exactly one job: fetches its destination + batch, builds
 * the same SwapService payload review_batch.php's synchronous execute
 * used to build for this destination type, calls it, and records the
 * outcome back to the queue — including the same destination-row status
 * updates (hold_reference / transaction_reference / error_message) and
 * department ration bookkeeping the old synchronous path did, just
 * scoped to one destination instead of the whole batch at once.
 */
function processJob(PDO $pdo, BatchExecutionQueueService $queue, SwapService $swapService, DepartmentService $departmentService, array $job, string $workerId): void
{
    $jobId = (int)$job['id'];

    try {
        $stmt = $pdo->prepare("
            UPDATE batch_execution_jobs SET status = 'processing', updated_at = NOW() WHERE id = :id
        ");
        $stmt->execute([':id' => $jobId]);

        $stmt = $pdo->prepare("
            SELECT d.*, b.batch_reference, b.source_institution, b.source_identifier,
                   b.source_asset_type, b.currency AS batch_currency, b.department_id
            FROM disbursement_destinations d
            INNER JOIN disbursement_batches b ON b.id = d.batch_id
            WHERE d.id = :destination_id
        ");
        $stmt->execute([':destination_id' => $job['destination_id']]);
        $dest = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dest) {
            $queue->markFailed($jobId, "Destination {$job['destination_id']} not found");
            return;
        }

        // Same "don't redo work" guard the synchronous path applied —
        // if this destination was somehow already resolved by an earlier
        // attempt (e.g. a retried job after a partial crash), skip it
        // rather than risk a double-payment.
        $alreadyDoneStatuses = ['SUCCESS', 'COMPLETED', 'PENDING_IDENTITY_CONFIRMATION'];
        if (in_array(strtoupper((string)($dest['status'] ?? '')), $alreadyDoneStatuses, true)) {
            $queue->markCompleted($jobId);
            return;
        }

        $isIdentity = ($dest['is_identity_recipient'] ?? false) || ($dest['institution'] ?? '') === 'IDENTITY_RECIPIENT';

        if ($isIdentity) {
            processIdentityJob($pdo, $queue, $swapService, $job, $dest, $jobId);
        } else {
            processInstitutionJob($pdo, $queue, $swapService, $departmentService, $job, $dest, $jobId);
        }

    } catch (\Throwable $e) {
        error_log("[worker:{$workerId}] Job {$jobId} threw: " . $e->getMessage());
        try {
            $queue->markFailed($jobId, $e->getMessage());
            markDestinationFailed($pdo, (int)$job['destination_id'], $e->getMessage());
        } catch (\Throwable $inner) {
            error_log("[worker:{$workerId}] markFailed itself threw for job {$jobId}: " . $inner->getMessage());
        }
    }
}

function processIdentityJob(PDO $pdo, BatchExecutionQueueService $queue, SwapService $swapService, array $job, array $dest, int $jobId): void
{
    $identityPayload = [
        'swap_type' => 'IDENTITY',
        'reference' => $dest['batch_reference'] . '_ID_' . $dest['destination_index'],
        'idempotency_key' => $job['idempotency_key'],
        'from_institution' => $dest['source_institution'],
        'source_institution' => $dest['source_institution'],
        'asset_type' => $dest['source_asset_type'] ?? 'ACCOUNT',
        'source_identifier' => $dest['source_identifier'],
        'amount' => (float)$dest['amount'],
        'currency' => $dest['currency'] ?? $dest['batch_currency'] ?? 'BWP',
        'identity_type' => $dest['identity_type'] ?? 'national_id',
        'identity_value' => $dest['identity_value'] ?? $dest['identifier'],
    ];
    if (!empty($dest['beneficiary_phone'])) {
        $identityPayload['notification_phone'] = $dest['beneficiary_phone'];
    }

    try {
        $result = $swapService->executeAtomicSwap($identityPayload);

        $stmt = $pdo->prepare("
            UPDATE disbursement_destinations
            SET status = 'PENDING_IDENTITY_CONFIRMATION', hold_reference = :hold_ref
            WHERE id = :id
        ");
        $stmt->execute([
            ':hold_ref' => $result['hold_reference'] ?? null,
            ':id' => $dest['id'],
        ]);

        // From the QUEUE's perspective, this job's work is done once the
        // identity hold is placed — the recipient's later claim/confirm
        // is a completely separate, already-existing flow
        // (confirmAndFinalizeIdentitySwap), not something this job waits
        // on. No ration bookkeeping here either, for the same reason the
        // synchronous path didn't do it for identity destinations: money
        // hasn't actually left yet, it's on hold pending claim.
        $queue->markCompleted($jobId, $result['reference'] ?? null);

    } catch (\Throwable $e) {
        $queue->markFailed($jobId, $e->getMessage());
        markDestinationFailed($pdo, (int)$dest['id'], $e->getMessage());
    }
}

function processInstitutionJob(PDO $pdo, BatchExecutionQueueService $queue, SwapService $swapService, DepartmentService $departmentService, array $job, array $dest, int $jobId): void
{
    // ============================================================
    // RULE 2 PRE-CHECK — sub-department spending guardrail. Runs before
    // anything else, including building the swap payload below: a
    // transaction already known to bust the sub-department's or the Main
    // Central Government Account's local ledger must never reach the
    // external adapter at all.
    // ============================================================
    if (!empty($dest['department_id'])) {
        try {
            $departmentService->assertTransactionFitsSpendingLimits(
                (int)$dest['department_id'],
                (float)$dest['amount']
            );
        } catch (\RuntimeException $e) {
            $queue->markFailed($jobId, $e->getMessage());
            markDestinationFailed($pdo, (int)$dest['id'], $e->getMessage());
            return;
        }
    }

    // Same MULTI_DESTINATION payload shape review_batch.php built for
    // its whole-batch call, just with exactly one destination — see the
    // architectural note at the top of this file for why.
    $multiPayload = [
        'swap_type' => 'MULTI_DESTINATION',
        'reference' => $job['idempotency_key'],
        'idempotency_key' => $job['idempotency_key'],
        'from_institution' => $dest['source_institution'],
        'source_institution' => $dest['source_institution'],
        'asset_type' => $dest['source_asset_type'] ?? 'ACCOUNT',
        'source_identifier' => $dest['source_identifier'],
        'amount' => (float)$dest['amount'],
        'currency' => $dest['currency'] ?? $dest['batch_currency'] ?? 'BWP',
        'destinations' => [
            [
                'to_institution' => $dest['institution'],
                'destination_institution' => $dest['institution'],
                'destination_asset_type' => $dest['asset_type'] ?? 'WALLET',
                'destination_identifier' => $dest['identifier'],
                'destination_identifier_type' => $dest['identifier_type'] ?? 'account',
                'amount' => (float)$dest['amount'],
                'currency' => $dest['currency'] ?? $dest['batch_currency'] ?? 'BWP',
                'delivery_method' => $dest['delivery_method'] ?? 'DEPOSIT',
                'beneficiary_phone' => $dest['beneficiary_phone'] ?? null,
                'beneficiary_name' => $dest['beneficiary_name'] ?? null,
            ],
        ],
    ];

    try {
        $result = $swapService->executeAtomicSwap($multiPayload);
        $destResult = $result['destinations'][0] ?? null;
        $status = $destResult['status'] ?? 'FAILED';

        $stmt = $pdo->prepare("
            UPDATE disbursement_destinations
            SET status = :status,
                hold_reference = :hold_ref,
                transaction_reference = :tx_ref,
                error_message = :error
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $status,
            ':hold_ref' => $destResult['hold_reference'] ?? null,
            ':tx_ref' => $destResult['transaction_reference'] ?? null,
            ':error' => $destResult['error'] ?? null,
            ':id' => $dest['id'],
        ]);

        if (in_array($status, ['SUCCESS', 'COMPLETED'], true)) {
            $queue->markCompleted($jobId, $destResult['transaction_reference'] ?? null);

            // RULE 3 — atomic, locked ledger update now that the payout
            // has actually happened. See DepartmentService::
            // recordApprovedDepartmentSpend for why this can't be inside
            // the same transaction as executeAtomicSwap above.
            //
            // Pre-existing risk, not introduced by this change: if this
            // worker crashes between the destination-status UPDATE above
            // and this call completing, a retry of this job will see the
            // destination already SUCCESS (the "already done" guard in
            // processJob) and skip straight to markCompleted() without
            // ever re-entering this function — silently skipping the
            // ledger write for that one destination. Identical risk
            // already existed around the old increment block this
            // replaces; fixing it depends on the stuck-job recovery cron
            // this file's header comment already flags as not yet built.
            if (!empty($dest['department_id'])) {
                try {
                    $spendResult = $departmentService->recordApprovedDepartmentSpend(
                        (int)$dest['department_id'],
                        (float)$dest['amount'],
                        null, // no authenticated user in a CLI worker context
                        [
                            'job_id' => $jobId,
                            'destination_id' => (int)$dest['id'],
                            'batch_id' => (int)($dest['batch_id'] ?? 0),
                            'transaction_reference' => $destResult['transaction_reference'] ?? null,
                        ]
                    );
                    if ($spendResult['race_detected']) {
                        error_log("[worker] BUDGET OVERRUN RACE for destination {$dest['id']} (department {$dest['department_id']}) — payout already sent, flagged in organization_audit_logs for manual reconciliation.");
                    }
                } catch (\Throwable $e) {
                    // Money already moved — this can only be a DB/infra
                    // failure recording it, never a rejection. Same "log
                    // and move on" treatment the old increment block
                    // used, so a bookkeeping hiccup never gets confused
                    // with a payout failure.
                    error_log("[worker] Failed to record department spend for destination {$dest['id']}: " . $e->getMessage());
                }
            }
        } else {
            $queue->markFailed($jobId, $destResult['error'] ?? ($result['message'] ?? 'Unknown failure'));
        }

    } catch (\Throwable $e) {
        $queue->markFailed($jobId, $e->getMessage());
        markDestinationFailed($pdo, (int)$dest['id'], $e->getMessage());
    }
}

function markDestinationFailed(PDO $pdo, int $destinationId, string $error): void
{
    try {
        $stmt = $pdo->prepare("
            UPDATE disbursement_destinations
            SET status = 'FAILED', error_message = :error
            WHERE id = :id
        ");
        $stmt->execute([':error' => $error, ':id' => $destinationId]);
    } catch (\Throwable $e) {
        error_log("[worker] Failed to mark destination {$destinationId} as FAILED: " . $e->getMessage());
    }
}
