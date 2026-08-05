<?php
declare(strict_types=1);

/**
 * SwapTracer (PostgreSQL)
 * -----------------------
 * Records a step-by-step trace of a single swap's execution to the
 * swap_traces / swap_trace_summary tables, so WorkControl (or any other
 * tool) can reconstruct exactly what happened -- validation, country
 * resolution, routing decision, adapter calls, signature verification,
 * hold placement, settlement, atomic commit -- for any given swap.
 *
 * DESIGN PRINCIPLES:
 * - Tracing must NEVER break or slow the actual swap in a meaningful way.
 *   Every DB call is wrapped in try/catch; a failure to write a trace
 *   step is logged via error_log() and swallowed, never thrown.
 * - Each step captures how long it took since the previous step
 *   (duration_ms), so slow steps are visible without extra instrumentation.
 * - finish() must be called exactly once per logical swap attempt (it's
 *   idempotent if called twice), ideally from a finally/catch path, so
 *   swap_trace_summary always reaches a terminal state even when an
 *   exception is thrown mid-swap.
 * - A swap's real reference is often not known until partway through
 *   execution (e.g. it's generated inside SwapService). Start the
 *   tracer under a temporary identifier -- the derived idempotency_key
 *   works well, since it's already deterministic and known immediately
 *   after the request is parsed -- and call rekey() once the real
 *   swap_reference exists.
 *
 * USAGE:
 *
 *   $tracer = new SwapTracer($pdo, $input['idempotency_key']);
 *   $tracer->setSummary([
 *       'swap_type' => $swapType,
 *       'source_institution' => $fromInstitution,
 *       'destination_institution' => $toInstitution,
 *       'amount' => $amount,
 *       'currency' => $currency,
 *   ]);
 *
 *   try {
 *       $tracer->success('VALIDATION', 'Input validated');
 *       // ... swap logic ...
 *       $tracer->rekey($result['reference']); // once the real reference exists
 *       $tracer->finish(true);
 *   } catch (\Throwable $e) {
 *       $tracer->error('EXCEPTION', get_class($e), $e->getMessage());
 *       $tracer->finish(false);
 *       throw $e;
 *   }
 */
class SwapTracer
{
    private PDO $db;
    private string $swapReference;
    private int $stepIndex = 0;
    private float $swapStartTime;
    private float $lastStepTime;
    private ?string $firstErrorStep = null;
    private array $summary = [];
    private bool $finished = false;

    public function __construct(PDO $db, string $swapReference)
    {
        $this->db = $db;
        $this->swapReference = $swapReference;
        $this->swapStartTime = microtime(true);
        $this->lastStepTime = $this->swapStartTime;

        $this->safely(function () {
            $stmt = $this->db->prepare(
                "INSERT INTO swap_trace_summary (swap_reference, overall_status, started_at)
                 VALUES (:ref, 'in_progress', clock_timestamp())
                 ON CONFLICT (swap_reference) DO UPDATE
                     SET overall_status = 'in_progress', started_at = clock_timestamp()"
            );
            $stmt->execute(['ref' => $this->swapReference]);
        });
    }

    /**
     * Record one traced step. Never throws -- a tracing failure must
     * never break the swap it's observing.
     */
    public function step(
        string $category,
        string $stepName,
        string $status = 'info',
        ?string $message = null,
        array $details = [],
        ?string $institution = null
    ): void {
        $this->safely(function () use ($category, $stepName, $status, $message, $details, $institution) {
            $now = microtime(true);
            $durationMs = round(($now - $this->lastStepTime) * 1000, 3);
            $this->lastStepTime = $now;
            $this->stepIndex++;

            $stmt = $this->db->prepare(
                "INSERT INTO swap_traces
                    (swap_reference, step_index, category, step_name, status, message, details, institution, duration_ms)
                 VALUES (:ref, :idx, :cat, :name, :status, :msg, :details::jsonb, :inst, :dur)"
            );
            $stmt->execute([
                'ref'     => $this->swapReference,
                'idx'     => $this->stepIndex,
                'cat'     => $category,
                'name'    => $stepName,
                'status'  => $status,
                'msg'     => $message,
                'details' => $details ? json_encode($details) : null,
                'inst'    => $institution,
                'dur'     => $durationMs,
            ]);

            if ($status === 'error' && $this->firstErrorStep === null) {
                $this->firstErrorStep = $stepName;
            }
        });
    }

    public function success(string $category, string $stepName, ?string $message = null, array $details = [], ?string $institution = null): void
    {
        $this->step($category, $stepName, 'success', $message, $details, $institution);
    }

    public function error(string $category, string $stepName, ?string $message = null, array $details = [], ?string $institution = null): void
    {
        $this->step($category, $stepName, 'error', $message, $details, $institution);
    }

    public function warning(string $category, string $stepName, ?string $message = null, array $details = [], ?string $institution = null): void
    {
        $this->step($category, $stepName, 'warning', $message, $details, $institution);
    }

    public function info(string $category, string $stepName, ?string $message = null, array $details = [], ?string $institution = null): void
    {
        $this->step($category, $stepName, 'info', $message, $details, $institution);
    }

    /**
     * Attach/replace top-level summary fields. Call as many times as
     * needed -- later calls overwrite matching keys. Recognized keys
     * map directly to swap_trace_summary columns: swap_type,
     * source_institution, destination_institution, routing_mode,
     * amount, currency.
     */
    public function setSummary(array $fields): void
    {
        $this->summary = array_merge($this->summary, $fields);
    }

    /**
     * Rename this trace from its current identifier to the real swap
     * reference, once one exists. Safe to call multiple times or with
     * the same value (no-op). If a summary row already exists under
     * the new reference (e.g. a genuine idempotent retry landed on the
     * same final reference), the temporary row is merged away rather
     * than colliding on the primary key.
     */
    public function rekey(string $newReference): void
    {
        if ($newReference === $this->swapReference || $newReference === '') {
            return;
        }
        $old = $this->swapReference;

        $this->safely(function () use ($old, $newReference) {
            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare(
                    "UPDATE swap_traces SET swap_reference = :new WHERE swap_reference = :old"
                );
                $stmt->execute(['new' => $newReference, 'old' => $old]);

                $check = $this->db->prepare(
                    "SELECT 1 FROM swap_trace_summary WHERE swap_reference = :new"
                );
                $check->execute(['new' => $newReference]);

                if ($check->fetchColumn()) {
                    $del = $this->db->prepare(
                        "DELETE FROM swap_trace_summary WHERE swap_reference = :old"
                    );
                    $del->execute(['old' => $old]);
                } else {
                    $upd = $this->db->prepare(
                        "UPDATE swap_trace_summary SET swap_reference = :new WHERE swap_reference = :old"
                    );
                    $upd->execute(['new' => $newReference, 'old' => $old]);
                }

                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        });

        $this->swapReference = $newReference;
    }

    /**
     * Call once at the very end of the swap -- success or failure.
     * Idempotent: safe to call more than once (e.g. once at a specific
     * failure point, once again from an outer catch-all).
     */
    public function finish(bool $success): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        $this->safely(function () use ($success) {
            $totalMs = round((microtime(true) - $this->swapStartTime) * 1000, 3);
            $status = $success ? 'success' : 'failed';

            $fields = array_merge($this->summary, [
                'overall_status'    => $status,
                'first_error_step'  => $this->firstErrorStep,
                'total_steps'       => $this->stepIndex,
                'total_duration_ms' => $totalMs,
                'completed_at'      => (new DateTime())->format('Y-m-d H:i:s.u'),
            ]);

            $setClauses = [];
            $params = ['ref' => $this->swapReference];
            foreach ($fields as $key => $value) {
                // Guard against arbitrary keys reaching raw SQL
                if (!preg_match('/^[a-z_]+$/', $key)) {
                    continue;
                }
                $setClauses[] = "$key = :$key";
                $params[$key] = $value;
            }

            $stmt = $this->db->prepare(
                "UPDATE swap_trace_summary SET " . implode(', ', $setClauses) . " WHERE swap_reference = :ref"
            );
            $stmt->execute($params);
        });
    }

    /** Run a callback but never let a tracing failure propagate into the swap flow. */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('[SwapTracer] Failed to record trace for ' . $this->swapReference . ': ' . $e->getMessage());
        }
    }
}
