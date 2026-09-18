<?php
declare(strict_types=1);

/**
 * swap_integrity_reconciler.php
 * ==============================
 * Compensating control for the "local Postgres transaction vs external
 * HTTP debit" non-atomicity risk identified in code review:
 * beginAtomicSwap()/commitAtomicSwap()/rollbackAtomicSwap() give real
 * atomicity for LOCAL database writes, but debitSource() and
 * adapter->credit() are HTTP calls to independent external systems that
 * a Postgres ROLLBACK cannot undo. If debitSource() succeeds (real money
 * already moved at the source institution) and then commitAtomicSwap()
 * itself fails for any reason, the local DB can end up showing "rolled
 * back" while the source institution's own system shows money already
 * gone.
 *
 * TRUE PREVENTION of this class of bug requires a proper distributed-
 * transaction / saga pattern (compensating transactions with idempotent
 * reversal calls to each institution) -- a genuine architecture change,
 * out of scope for a single script. This job is the next-best thing:
 * DETECT the inconsistency and surface it for human reconciliation
 * quickly, rather than letting it sit silently until a customer or
 * institution complains.
 *
 * WHAT IT CHECKS:
 * 1. hold_transactions with status = 'DEBITED' but no corresponding
 *    swap_requests row with status = 'completed' -- the local record of
 *    "we told the bank to remove money" exists, but nothing confirms the
 *    swap that caused it ever finished successfully.
 * 2. hold_transactions with status = 'DEBITED' whose swap_reference has
 *    NO swap_requests row at all -- worse version of #1, total tracking
 *    gap.
 * 3. swap_requests with status = 'completed' but settlement_status still
 *    'UNCONFIRMED' after a configurable grace period -- overlaps with
 *    settlement_confirmation_worker.php's staleness check, included here
 *    too since this script is meant to be the single "is anything wrong"
 *    dashboard query, not just this one bug class.
 * 4. swap_requests with status = 'completed' but NO audit_logs row for the
 *    reference -- a transaction that finished without the record of who
 *    moved the money, when, and where it went.
 * 5. unresolved rows in audit_log_failures -- the dead letter every failed
 *    audit write lands in. Nothing read this table before, so a swap could
 *    move money, fail to record itself, write the fallback row, and have
 *    that fallback sit unnoticed indefinitely.
 * 6. negative amounts in hold_transactions or identity_swap_holds. Nothing
 *    here is ever legitimately negative: a hold and an identity hold are
 *    amounts set aside, and a fee is a deduction written as a positive
 *    number. A negative one means a wrong sign or a subtraction that ran
 *    twice.
 *
 * Note that checks 1-3 all key off hold_transactions.status = 'DEBITED'
 * or a completed swap_requests row -- writes that are themselves rolled
 * back in the failure modes where tracking never got written at all.
 * Checks 4 and 5 are the ones that catch that case, because they key off
 * the completed swap and the dead-letter table instead. Check 6 is the
 * only one that looks at the numbers rather than at a status.
 *
 * WHAT IT DOES NOT DO:
 * It does NOT attempt to auto-correct anything. Auto-"fixing" a
 * financial discrepancy by guessing which side is wrong is far riskier
 * than leaving it flagged for a human who can actually call the
 * institution and confirm reality. This script's only side effect is
 * writing rows to swap_integrity_findings for review.
 *
 * SCHEDULING: run every 15-30 minutes. Written as an explicit minute list
 * rather than a step value on purpose: a literal "*" followed by "/" in
 * this crontab line closed THIS block comment, so everything after it was
 * parsed as PHP and the whole script died with
 * "syntax error, unexpected token *" before executing a single check.
 * The compensating control for unrecorded money movement has therefore
 * never actually run. The schedule below is equivalent to every 15 min.
 *
 *   0,15,30,45 * * * * php /path/to/swap_integrity_reconciler.php >> /var/log/vouchmorph/reconciler.log 2>&1
 */

require_once __DIR__ . '/../../vendor/autoload.php'; // adjust to your actual vendor path
require_once __DIR__ . '/../Core/Database/DBConnection.php';

use Core\Database\DBConnection;

const SETTLEMENT_GRACE_PERIOD_MINUTES = 60 * 24; // matches settlement_confirmation_worker.php's threshold

function logLine(string $msg): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

try {
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new RuntimeException('Database connection failed');
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$db->exec("
    CREATE TABLE IF NOT EXISTS swap_integrity_findings (
        id BIGSERIAL PRIMARY KEY,
        finding_type VARCHAR(64) NOT NULL,
        severity VARCHAR(16) NOT NULL, -- 'HIGH' | 'MEDIUM'
        swap_reference VARCHAR(128),
        hold_id INT,
        details JSONB,
        found_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        resolved_at TIMESTAMPTZ,
        resolved_by VARCHAR(100),
        resolution_notes TEXT
    )
");
$db->exec("
    CREATE INDEX IF NOT EXISTS idx_swap_integrity_unresolved
        ON swap_integrity_findings (found_at) WHERE resolved_at IS NULL
");

logLine('Swap integrity reconciler starting');

$totalFindings = 0;

function recordFinding(PDO $db, string $type, string $severity, ?string $swapRef, ?int $holdId, array $details): void
{
    // Avoid re-flagging the exact same unresolved finding on every run.
    $checkStmt = $db->prepare("
        SELECT id FROM swap_integrity_findings
        WHERE finding_type = :type AND swap_reference = :ref AND resolved_at IS NULL
        LIMIT 1
    ");
    $checkStmt->execute([':type' => $type, ':ref' => $swapRef]);
    if ($checkStmt->fetchColumn()) {
        return; // already flagged and unresolved, don't duplicate
    }

    $stmt = $db->prepare("
        INSERT INTO swap_integrity_findings (finding_type, severity, swap_reference, hold_id, details)
        VALUES (:type, :severity, :ref, :hold_id, :details::jsonb)
    ");
    $stmt->execute([
        ':type' => $type,
        ':severity' => $severity,
        ':ref' => $swapRef,
        ':hold_id' => $holdId,
        ':details' => json_encode($details),
    ]);
}

// ============================================================
// Check 1 & 2: DEBITED holds without a completed swap_requests row
// ============================================================
$stmt = $db->query("
    SELECT
        ht.hold_id,
        ht.swap_reference,
        ht.amount,
        ht.currency,
        ht.source_institution,
        ht.debited_at,
        sr.status AS swap_request_status,
        sr.swap_id
    FROM hold_transactions ht
    LEFT JOIN swap_requests sr ON sr.swap_uuid = ht.swap_reference
    WHERE ht.status = 'DEBITED'
      AND ht.debited_at > NOW() - INTERVAL '30 days'  -- bound the scan; adjust as needed
      AND (sr.swap_id IS NULL OR LOWER(sr.status) != 'completed')
    ORDER BY ht.debited_at DESC
    LIMIT 500
");
$mismatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($mismatches as $row) {
    $isTotalGap = $row['swap_id'] === null;
    $type = $isTotalGap ? 'DEBITED_HOLD_NO_SWAP_REQUEST' : 'DEBITED_HOLD_INCOMPLETE_SWAP_REQUEST';
    $severity = $isTotalGap ? 'HIGH' : 'MEDIUM';

    recordFinding($db, $type, $severity, $row['swap_reference'], (int)$row['hold_id'], [
        'amount' => $row['amount'],
        'currency' => $row['currency'],
        'source_institution' => $row['source_institution'],
        'debited_at' => $row['debited_at'],
        'swap_request_status' => $row['swap_request_status'],
    ]);

    logLine("FINDING [{$severity}] {$type}: hold_id={$row['hold_id']} swap_reference={$row['swap_reference']} amount={$row['amount']} {$row['currency']} debited_at={$row['debited_at']}");
    $totalFindings++;
}

// ============================================================
// Check 3: completed swaps stuck UNCONFIRMED past grace period
// (complements settlement_confirmation_worker.php's own staleness flag --
// this is a second, independent view of the same underlying risk)
// ============================================================
$stmt = $db->prepare("
    SELECT swap_uuid, amount, settlement_status, completed_at
    FROM swap_requests
    WHERE LOWER(status) = 'completed'
      AND settlement_status = 'UNCONFIRMED'
      AND completed_at < NOW() - (:grace_minutes || ' minutes')::interval
    ORDER BY completed_at ASC
    LIMIT 500
");
$stmt->execute([':grace_minutes' => SETTLEMENT_GRACE_PERIOD_MINUTES]);
$staleSettlements = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($staleSettlements as $row) {
    recordFinding($db, 'SETTLEMENT_UNCONFIRMED_PAST_GRACE_PERIOD', 'HIGH', $row['swap_uuid'], null, [
        'amount' => $row['amount'],
        'completed_at' => $row['completed_at'],
        'grace_period_minutes' => SETTLEMENT_GRACE_PERIOD_MINUTES,
    ]);

    logLine("FINDING [HIGH] SETTLEMENT_UNCONFIRMED_PAST_GRACE_PERIOD: swap_reference={$row['swap_uuid']} completed_at={$row['completed_at']}");
    $totalFindings++;
}

// ============================================================
// Check 4: completed swaps with no audit record
// ============================================================
// Every completed transaction is supposed to carry an audit row naming
// who moved the money, when, and where it went -- written by
// SwapService::populateAuditLog() via populateTrackingTables(). A
// completed swap without one means the money moved and the record of it
// did not, which is exactly the state audit_log_failures exists to
// capture and which nothing detected before this check.
//
// LEFT JOIN rather than NOT EXISTS so the missing-row case is explicit,
// and bounded to the same 30-day window the other checks use.
//
// The join is on audit_logs.entity_id, which is VARCHAR as of
// 2026_09_16_transaction_audit_integrity.sql. Against a database that has
// not had that migration applied the column is still bigint and this
// comparison raises 22P02 -- which is itself the bug being looked for, so
// the catch reports it rather than letting it kill the run.
try {
    $stmt = $db->query("
        SELECT sr.swap_uuid, sr.amount, sr.from_currency, sr.status, sr.completed_at, sr.created_at
        FROM swap_requests sr
        LEFT JOIN audit_logs al ON al.entity_id = sr.swap_uuid
        WHERE LOWER(sr.status) = 'completed'
          AND sr.created_at > NOW() - INTERVAL '30 days'
          AND al.audit_id IS NULL
        ORDER BY sr.created_at DESC
        LIMIT 500
    ");
    $missingAudit = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    logLine('WARNING: completed-swap audit check could not run: ' . $e->getMessage()
        . ' (audit_logs.entity_id may still be bigint - apply 2026_09_16_transaction_audit_integrity.sql)');
    $missingAudit = [];
}

foreach ($missingAudit as $row) {
    recordFinding($db, 'COMPLETED_SWAP_NO_AUDIT_RECORD', 'HIGH', $row['swap_uuid'], null, [
        'amount' => $row['amount'],
        'currency' => $row['from_currency'] ?? null,
        'status' => $row['status'],
        'created_at' => $row['created_at'],
        'completed_at' => $row['completed_at'],
    ]);

    logLine("FINDING [HIGH] COMPLETED_SWAP_NO_AUDIT_RECORD: swap_reference={$row['swap_uuid']} amount={$row['amount']} created_at={$row['created_at']}");
    $totalFindings++;
}

// ============================================================
// Check 5: unresolved audit_log_failures
// ============================================================
// SwapService::writeAuditFallback() writes here whenever a normal audit
// write could not happen. Its own comment says ops/compliance should
// monitor the table directly -- but nothing ever did, so this surfaces
// the backlog through the same findings table as everything else.
//
// The table is created by 2026_09_16_transaction_audit_integrity.sql;
// tolerate its absence so this script still runs on a database that has
// not had the migration applied yet.
try {
    $stmt = $db->query("
        SELECT swap_reference, swap_type, reason, created_at
        FROM audit_log_failures
        WHERE resolved_at IS NULL
          AND created_at > NOW() - INTERVAL '30 days'
        ORDER BY created_at DESC
        LIMIT 500
    ");
    $auditFailures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    logLine('audit_log_failures not queryable (migration not applied?): ' . $e->getMessage());
    $auditFailures = [];
}

foreach ($auditFailures as $row) {
    recordFinding($db, 'AUDIT_LOG_FAILURE_UNRESOLVED', 'HIGH', $row['swap_reference'], null, [
        'swap_type' => $row['swap_type'],
        'reason' => $row['reason'],
        'created_at' => $row['created_at'],
    ]);

    logLine("FINDING [HIGH] AUDIT_LOG_FAILURE_UNRESOLVED: swap_reference={$row['swap_reference']} reason={$row['reason']}");
    $totalFindings++;
}

// ============================================================
// Check 6: negative money anywhere in the swap ledger
//
// Nothing in this system is ever legitimately negative: a hold, an
// identity hold and a reservation position are all amounts set aside, and
// a fee is a deduction expressed as a positive number. A negative one
// means an amount was written with the wrong sign, or a subtraction ran
// twice -- which does not announce itself anywhere else, because every
// other check here looks at STATUS rather than at the numbers.
// ============================================================
$negativeChecks = [
    'hold_transactions'   => ['table' => 'hold_transactions',   'ref' => 'swap_reference', 'id' => 'hold_id'],
    'identity_swap_holds' => ['table' => 'identity_swap_holds', 'ref' => 'swap_reference', 'id' => 'hold_id'],
];

foreach ($negativeChecks as $label => $spec) {
    try {
        $stmt = $db->query("
            SELECT {$spec['id']} AS row_id, {$spec['ref']} AS swap_reference, amount, status
            FROM {$spec['table']}
            WHERE amount < 0
            ORDER BY {$spec['id']} DESC
            LIMIT 500
        ");
    } catch (Throwable $e) {
        // A table this deployment doesn't have is not a finding.
        logLine("Skipped negative-amount check on {$label}: " . $e->getMessage());
        continue;
    }

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        recordFinding($db, 'NEGATIVE_AMOUNT', 'HIGH', $row['swap_reference'], (int)$row['row_id'], [
            'table' => $spec['table'],
            'amount' => $row['amount'],
            'status' => $row['status'],
        ]);

        logLine("FINDING [HIGH] NEGATIVE_AMOUNT: {$spec['table']}.{$spec['id']}={$row['row_id']} amount={$row['amount']} status={$row['status']}");
        $totalFindings++;
    }
}

logLine("Run complete: {$totalFindings} new/repeated finding(s) recorded. Query swap_integrity_findings WHERE resolved_at IS NULL for the current backlog.");

// Non-zero exit if anything was found this run, so monitoring can alert.
exit($totalFindings > 0 ? 1 : 0);
