<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use PDOException;
use RuntimeException;

/**
 * BatchExecutionQueueService
 *
 * Replaces "execute the whole batch inside one HTTP request" with
 * "explode the batch into one durable job per destination, let N worker
 * processes drain the queue." See 006_batch_execution_queue_migration.sql
 * for the schema and the reasoning behind each design choice.
 *
 * This service does NOT call SwapService itself — that stays the
 * worker's job (see worker.php). This service only manages the queue:
 * creating jobs, letting a worker safely claim a batch of them without
 * two workers ever grabbing the same job, and recording the outcome.
 */
class BatchExecutionQueueService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Turns an approved batch into one job per destination. Called once,
     * at the moment an Owner clicks "Execute" — replaces the synchronous
     * per-destination loop that used to live directly in review_batch.php.
     *
     * Idempotent at the batch level: if jobs already exist for this batch
     * (e.g. the Owner double-clicked, or a retry after a partial crash),
     * this does NOT create duplicates — it only creates jobs for
     * destinations that don't already have one, mirroring the existing
     * destination-filtering logic from the old synchronous execute path.
     *
     * @return int Number of NEW jobs created (0 if this batch was already
     *             fully queued — safe to call again).
     */
    public function enqueueBatch(int $batchId): int
    {
        $this->db->beginTransaction();
        try {
            // Lock the batch row so two concurrent "Execute" clicks can't
            // both enqueue it — same atomic-claim spirit as the original
            // synchronous execute's UPDATE ... WHERE status = 'approved'.
            $stmt = $this->db->prepare("
                SELECT id, organization_id, source_institution, status
                FROM disbursement_batches
                WHERE id = :id
                FOR UPDATE
            ");
            $stmt->execute([':id' => $batchId]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new RuntimeException("Batch {$batchId} not found.");
            }
            if (!in_array($batch['status'], ['approved', 'executing'], true)) {
                throw new RuntimeException("Batch {$batchId} is not in an executable state (status: {$batch['status']}).");
            }

            // Which destinations don't have a job yet — the same
            // "don't redo work that's already done or in flight" rule
            // the old synchronous path applied by checking destination
            // status directly.
            $stmt = $this->db->prepare("
                SELECT d.id, d.destination_index, d.institution
                FROM disbursement_destinations d
                WHERE d.batch_id = :batch_id
                  AND d.status NOT IN ('SUCCESS', 'COMPLETED', 'PENDING_IDENTITY_CONFIRMATION')
                  AND NOT EXISTS (
                      SELECT 1 FROM batch_execution_jobs j
                      WHERE j.destination_id = d.id
                        AND j.status NOT IN ('permanently_failed')
                  )
            ");
            $stmt->execute([':batch_id' => $batchId]);
            $destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $created = 0;
            foreach ($destinations as $dest) {
                $idempotencyKey = 'BATCHJOB_' . $batchId . '_' . $dest['destination_index'] . '_' . bin2hex(random_bytes(4));
                $stmt = $this->db->prepare("
                    INSERT INTO batch_execution_jobs
                        (batch_id, destination_id, institution, idempotency_key, status)
                    VALUES
                        (:batch_id, :destination_id, :institution, :idempotency_key, 'pending')
                    ON CONFLICT (idempotency_key) DO NOTHING
                ");
                $stmt->execute([
                    ':batch_id' => $batchId,
                    ':destination_id' => $dest['id'],
                    ':institution' => $dest['institution'] ?? $batch['source_institution'],
                    ':idempotency_key' => $idempotencyKey,
                ]);
                $created++;
            }

            // Mark the batch as executing (queued) rather than leaving it
            // at 'approved' — this is what tells the dashboard "this is in
            // flight, not just sitting untouched."
            $stmt = $this->db->prepare("
                UPDATE disbursement_batches SET status = 'executing', updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $batchId]);

            $this->db->commit();
            return $created;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Called by a worker process to claim up to $limit pending jobs,
     * respecting each institution's rate limit. Safe to call from many
     * worker processes at once — FOR UPDATE SKIP LOCKED guarantees no
     * two workers ever claim the same row, without them blocking each
     * other waiting for locks either.
     *
     * @return array<int, array{id:int,batch_id:int,destination_id:int,institution:string,idempotency_key:string,attempt_count:int}>
     */
    public function claimJobs(string $workerId, int $limit = 20): array
    {
        $this->db->beginTransaction();
        try {
            // Which institutions are NOT currently over their per-minute
            // limit — checked and reserved atomically in the same
            // transaction as the claim, so two workers can't both think
            // there's room left for the same institution at the same
            // instant.
            $allowedInstitutions = $this->reserveRateLimitSlots($limit);

            if (empty($allowedInstitutions)) {
                $this->db->commit();
                return [];
            }

            $placeholders = [];
            $params = [];
            foreach (array_keys($allowedInstitutions) as $i => $inst) {
                $key = ":inst{$i}";
                $placeholders[] = $key;
                $params[$key] = $inst;
            }

            $stmt = $this->db->prepare("
                SELECT id, batch_id, destination_id, institution, idempotency_key, attempt_count
                FROM batch_execution_jobs
                WHERE status = 'pending'
                  AND institution IN (" . implode(',', $placeholders) . ")
                ORDER BY created_at ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            ");
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($jobs)) {
                $ids = array_column($jobs, 'id');
                $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $upd = $this->db->prepare("
                    UPDATE batch_execution_jobs
                    SET status = 'claimed', claimed_by = ?, claimed_at = NOW(), updated_at = NOW()
                    WHERE id IN ({$inPlaceholders})
                ");
                $upd->execute(array_merge([$workerId], $ids));
            }

            $this->db->commit();
            return $jobs;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Checks each distinct pending institution's rate limit, resets
     * expired windows, and increments the counter for whichever
     * institutions have room — up to $limit total slots across all of
     * them. Returns the institutions actually allowed to be claimed from
     * right now. Must be called inside the same transaction as the claim
     * itself (see claimJobs above) so the reservation is atomic.
     */
    private function reserveRateLimitSlots(int $limit): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT institution FROM batch_execution_jobs WHERE status = 'pending'
        ");
        $stmt->execute();
        $pendingInstitutions = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'institution');

        if (empty($pendingInstitutions)) {
            return [];
        }

        $allowed = [];
        $remaining = $limit;

        foreach ($pendingInstitutions as $institution) {
            if ($remaining <= 0) {
                break;
            }

            $stmt = $this->db->prepare("
                INSERT INTO institution_rate_limits (institution, current_window_start, current_window_count)
                VALUES (:inst, NOW(), 0)
                ON CONFLICT (institution) DO NOTHING
            ");
            $stmt->execute([':inst' => $institution]);

            $stmt = $this->db->prepare("
                SELECT max_per_minute, current_window_start, current_window_count
                FROM institution_rate_limits WHERE institution = :inst FOR UPDATE
            ");
            $stmt->execute([':inst' => $institution]);
            $limitRow = $stmt->fetch(PDO::FETCH_ASSOC);

            $windowAge = time() - strtotime($limitRow['current_window_start']);
            if ($windowAge >= 60) {
                // Window expired — reset it.
                $stmt = $this->db->prepare("
                    UPDATE institution_rate_limits
                    SET current_window_start = NOW(), current_window_count = 0
                    WHERE institution = :inst
                ");
                $stmt->execute([':inst' => $institution]);
                $currentCount = 0;
            } else {
                $currentCount = (int)$limitRow['current_window_count'];
            }

            $roomLeft = max(0, (int)$limitRow['max_per_minute'] - $currentCount);
            if ($roomLeft <= 0) {
                continue;
            }

            $take = min($roomLeft, $remaining);
            $stmt = $this->db->prepare("
                UPDATE institution_rate_limits
                SET current_window_count = current_window_count + :take, updated_at = NOW()
                WHERE institution = :inst
            ");
            $stmt->execute([':take' => $take, ':inst' => $institution]);

            $allowed[$institution] = $take;
            $remaining -= $take;
        }

        return $allowed;
    }

    public function markCompleted(int $jobId, ?string $transactionReference = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE batch_execution_jobs
            SET status = 'completed', completed_at = NOW(), updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $jobId]);
        $this->maybeFinalizeBatch($jobId);
    }

    /**
     * Records a failure. If the job still has attempts remaining, it goes
     * back to 'pending' (a later claim will retry it) rather than being
     * abandoned. Only after max_attempts is it marked permanently_failed
     * — at which point it needs a human to look at the reconciliation
     * report (Track D), not an automatic retry forever.
     */
    public function markFailed(int $jobId, string $error): void
    {
        $stmt = $this->db->prepare("
            SELECT attempt_count, max_attempts FROM batch_execution_jobs WHERE id = :id FOR UPDATE
        ");
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return;
        }

        $newAttempts = (int)$job['attempt_count'] + 1;
        $isFinal = $newAttempts >= (int)$job['max_attempts'];

        $stmt = $this->db->prepare("
            UPDATE batch_execution_jobs
            SET attempt_count = :attempts,
                status = :status,
                last_error = :error,
                claimed_by = NULL,
                claimed_at = NULL,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':attempts' => $newAttempts,
            ':status' => $isFinal ? 'permanently_failed' : 'pending',
            ':error' => $error,
            ':id' => $jobId,
        ]);

        if ($isFinal) {
            $this->maybeFinalizeBatch($jobId);
        }
    }

    /**
     * After each job settles, checks whether its whole batch is now
     * fully resolved (every job completed or permanently_failed) and
     * flips the batch's own status accordingly — 'completed' if
     * everything succeeded, 'partially_completed' if some jobs
     * permanently failed. This is what lets the dashboard show accurate
     * batch-level status without a separate polling process.
     */
    private function maybeFinalizeBatch(int $jobId): void
    {
        $stmt = $this->db->prepare("SELECT batch_id FROM batch_execution_jobs WHERE id = :id");
        $stmt->execute([':id' => $jobId]);
        $batchId = $stmt->fetchColumn();
        if (!$batchId) {
            return;
        }

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE status NOT IN ('completed', 'permanently_failed')) AS unresolved,
                COUNT(*) FILTER (WHERE status = 'permanently_failed') AS failed,
                COUNT(*) AS total
            FROM batch_execution_jobs WHERE batch_id = :batch_id
        ");
        $stmt->execute([':batch_id' => $batchId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);

        if ((int)$counts['unresolved'] > 0 || (int)$counts['total'] === 0) {
            return; // still in flight
        }

        $finalStatus = (int)$counts['failed'] > 0 ? 'partially_completed' : 'completed';
        $stmt = $this->db->prepare("
            UPDATE disbursement_batches
            SET status = :status, completed_at = NOW(), updated_at = NOW()
            WHERE id = :batch_id AND status NOT IN ('completed', 'partially_completed')
        ");
        $stmt->execute([':status' => $finalStatus, ':batch_id' => $batchId]);
    }

    /**
     * Progress snapshot for a batch — what the dashboard polls to show a
     * live progress bar instead of "executing..." with no further detail.
     */
    public function getBatchProgress(int $batchId): array
    {
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as c
            FROM batch_execution_jobs WHERE batch_id = :batch_id
            GROUP BY status
        ");
        $stmt->execute([':batch_id' => $batchId]);
        $counts = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'c', 'status');

        $total = array_sum($counts);
        $completed = (int)($counts['completed'] ?? 0);
        $failed = (int)($counts['permanently_failed'] ?? 0);
        $inFlight = $total - $completed - $failed;

        return [
            'total' => $total,
            'completed' => $completed,
            'permanently_failed' => $failed,
            'in_flight' => $inFlight,
            'percent_done' => $total > 0 ? round((($completed + $failed) / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Requeues every permanently_failed job in a batch back to pending
     * with attempt_count reset to 0 — for after a human has looked at
     * the reconciliation report and fixed whatever was wrong (e.g.
     * corrected a phone number) and wants those specific destinations
     * retried without re-running the whole batch.
     */
    public function retryFailedJobs(int $batchId): int
    {
        $stmt = $this->db->prepare("
            UPDATE batch_execution_jobs
            SET status = 'pending', attempt_count = 0, last_error = NULL, updated_at = NOW()
            WHERE batch_id = :batch_id AND status = 'permanently_failed'
        ");
        $stmt->execute([':batch_id' => $batchId]);
        return $stmt->rowCount();
    }
}
