<?php
declare(strict_types=1);

/**
 * settlement_confirmation_worker.php
 * ===================================
 * Closes the loop between SwapService::recordSettlementPending() and
 * GenericBankClient::checkSettlementStatus() -- both already existed
 * before this file, nothing was calling them together on a schedule.
 *
 * WHAT THIS DOES:
 * For every swap_requests row with settlement_status = 'UNCONFIRMED'
 * (set by recordSettlementPending()), this asks the destination
 * institution's adapter whether the debit actually landed on their
 * side. If confirmed, it updates settlement_confirmations and
 * swap_requests.settlement_status to CONFIRMED. If an institution
 * reports the settlement failed or a swap has been unconfirmed for
 * too long, it's flagged for human review rather than silently retried
 * forever.
 *
 * WHAT THIS DOES NOT DO:
 * - It does not move money. It only asks "did the money you already
 *   received/sent actually land," and records the answer.
 * - It does not touch net_positions / settlement_outbox (the interbank
 *   NET obligation ledger in HybridSettlementStrategy) -- that's a
 *   separate concern from per-swap delivery confirmation. See
 *   settlement_acknowledge.php for that side.
 *
 * SCHEDULING:
 * Run this every 1-5 minutes via cron, a queue worker, or your
 * platform's scheduled-job mechanism:
 * Written as an explicit minute list, not a step value: a literal "*"
 * followed by "/" in this line closed the block comment, so everything
 * after it parsed as PHP and this worker died with "syntax error,
 * unexpected token *" before confirming a single settlement. The list
 * below is equivalent to every 5 minutes.
 *
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/settlement_confirmation_worker.php >> /var/log/vouchmorph/settlement_worker.log 2>&1
 *
 * Safe to run concurrently with itself IF your job runner guarantees
 * only one instance at a time; this script does not implement its own
 * locking. Add a lock file / advisory lock if your scheduler can't
 * guarantee that.
 */

require_once __DIR__ . '/../../vendor/autoload.php'; // adjust to your actual vendor path
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;
use Infrastructure\Adapters\InstitutionAdapterFactory;

// How many minutes an UNCONFIRMED settlement can sit before it's flagged
// for human attention instead of being polled again silently forever.
const STALE_THRESHOLD_MINUTES = 60 * 24; // 24 hours -- tune to your institutions' real settlement windows

// Max settlements to process per run, to keep each invocation bounded.
const BATCH_SIZE = 200;

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

logLine('Settlement confirmation worker starting');

// ============================================================
// 1. Pull pending confirmations
// ============================================================
$stmt = $db->prepare("
    SELECT sc.*, sr.destination_country, sr.source_country
    FROM settlement_confirmations sc
    LEFT JOIN swap_requests sr ON sr.swap_uuid = sc.swap_reference
    WHERE sc.status = 'PENDING'
    ORDER BY sc.id ASC
    LIMIT :limit
");
$stmt->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
$stmt->execute();
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

logLine('Found ' . count($pending) . ' PENDING settlement confirmation(s)');

if (empty($pending)) {
    logLine('Nothing to do. Exiting.');
    exit(0);
}

// ============================================================
// 2. Load participants config once (same pattern as execute.php)
// ============================================================
try {
    $fullCountryConfig = \Core\Config\LoadCountry::getConfig();
    $participants = $fullCountryConfig['participants'] ?? [];
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to load country config: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$logger = new class {
    public function info($m, $c = []) { fwrite(STDOUT, "[INFO] $m " . json_encode($c) . PHP_EOL); }
    public function warning($m, $c = []) { fwrite(STDOUT, "[WARN] $m " . json_encode($c) . PHP_EOL); }
    public function error($m, $c = []) { fwrite(STDERR, "[ERROR] $m " . json_encode($c) . PHP_EOL); }
};

$adapterFactory = new InstitutionAdapterFactory($participants, $logger);

$confirmed = 0;
$stillPending = 0;
$flaggedStale = 0;
$errors = 0;

foreach ($pending as $row) {
    $swapRef = $row['swap_reference'];
    $destInstitution = $row['destination_institution'];
    $settlementRef = $row['settlement_reference'];
    $mode = strtoupper($row['confirmation_mode'] ?? 'POLL');

    // Non-POLL modes (e.g. a future WEBHOOK mode where the institution
    // calls back into VouchMorph instead) are intentionally skipped here --
    // this worker only handles the polling side.
    if ($mode !== 'POLL') {
        continue;
    }

    try {
        $adapter = $adapterFactory->getAdapter($destInstitution);

        $result = $adapter->checkSettlementStatus(
            [
                'reference' => $settlementRef,
                'swap_reference' => $swapRef,
            ],
            [
                'swap_reference' => $swapRef,
                'destination_institution' => $destInstitution,
            ]
        );

        if (!empty($result['settled'])) {
            $updateStmt = $db->prepare("
                UPDATE settlement_confirmations
                SET status = 'CONFIRMED', confirmed_at = NOW(), confirmation_details = :details::jsonb
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':details' => json_encode($result),
                ':id' => $row['id'],
            ]);

            $srStmt = $db->prepare("
                UPDATE swap_requests SET settlement_status = 'CONFIRMED' WHERE swap_uuid = :ref
            ");
            $srStmt->execute([':ref' => $swapRef]);

            logLine("CONFIRMED: swap_reference={$swapRef} destination={$destInstitution}");
            $confirmed++;
        } else {
            // Not settled yet -- check staleness
            $createdAt = strtotime($row['created_at'] ?? 'now');
            $ageMinutes = (time() - $createdAt) / 60;

            if ($ageMinutes > STALE_THRESHOLD_MINUTES) {
                $flagStmt = $db->prepare("
                    UPDATE settlement_confirmations
                    SET status = 'STALE_NEEDS_REVIEW', confirmation_details = :details::jsonb
                    WHERE id = :id
                ");
                $flagStmt->execute([
                    ':details' => json_encode(array_merge($result, ['age_minutes' => round($ageMinutes)])),
                    ':id' => $row['id'],
                ]);
                logLine("FLAGGED STALE: swap_reference={$swapRef} destination={$destInstitution} age={$ageMinutes}min -- needs human review");
                $flaggedStale++;
            } else {
                logLine("still pending: swap_reference={$swapRef} destination={$destInstitution} age=" . round($ageMinutes) . "min");
                $stillPending++;
            }
        }

    } catch (Throwable $e) {
        logLine("ERROR checking swap_reference={$swapRef} destination={$destInstitution}: " . $e->getMessage());
        $errors++;
        // Deliberately does NOT mark the row failed -- a transient error
        // (network blip, institution briefly down) should be retried on
        // the next run, not permanently flagged. Only genuine staleness
        // (checked above) escalates to human review.
    }
}

logLine("Run complete: confirmed={$confirmed} still_pending={$stillPending} flagged_stale={$flaggedStale} errors={$errors}");

// Non-zero exit if there were errors, so cron/monitoring can alert on it,
// but this is advisory -- individual failures don't corrupt state.
exit($errors > 0 ? 2 : 0);
