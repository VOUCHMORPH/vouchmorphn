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
 *
 * WHAT IT DOES NOT DO:
 * It does NOT attempt to auto-correct anything. Auto-"fixing" a
 * financial discrepancy by guessing which side is wrong is far riskier
 * than leaving it flagged for a human who can actually call the
 * institution and confirm reality. This script's only side effect is
 * writing rows to swap_integrity_findings for review.
 *
 * SCHEDULING: run every 15-30 minutes. Crontab line (every 15 minutes -
 * written as 0-59/15 rather than the more familiar star-slash-15
 * shorthand because that shorthand's literal "star-slash" would close
 * this very comment block early and break the file):
 *   0-59/15 * * * * php /path/to/swap_integrity_reconciler.php >> /var/log/vouchmorph/reconciler.log 2>&1
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

logLine("Run complete: {$totalFindings} new/repeated finding(s) recorded. Query swap_integrity_findings WHERE resolved_at IS NULL for the current backlog.");

// Non-zero exit if anything was found this run, so monitoring can alert.
exit($totalFindings > 0 ? 1 : 0);
