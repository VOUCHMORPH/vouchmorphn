<?php
/**
 * sandbox_readiness_test.php
 *
 * Answers three concrete questions with real data, not guesses:
 *
 *   1. If a court, the Bank of Botswana, or a disputing participant demands
 *      the FULL record of a specific transaction, can we actually produce
 *      it? (Picks a real recent swap and tries to reconstruct it end-to-end
 *      across every table that should reference it.)
 *   2. Is the system internally consistent right now - stuck holds, orphaned
 *      records, missing signatures, settlements that don't tie out?
 *   3. Can we generate invoices? (Checks what invoice-related data actually
 *      exists; does NOT assume - flags what it can't confirm.)
 *
 * Read top to bottom. Anything marked FAIL or WARN is something to fix
 * before you'd want to stand behind it in front of a regulator.
 *
 * Run this directly in a browser as an admin, same way you ran the earlier
 * diagnostic. It is READ-ONLY - no writes, no mutations, safe to run
 * against production/sandbox at any time.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

define('PROJECT_ROOT', dirname(__DIR__, 2));
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;

if (!SessionManager::isAdminLoggedIn()) {
    die("Not logged in as admin. Log in via admin_login.php first, then run this in the same browser session.");
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Returns column names for a table, or [] if it can't be inspected. */
function getColumns(PDO $db, string $table): array {
    try {
        $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = :t");
        $stmt->execute([':t' => $table]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'column_name');
    } catch (Throwable $e) {
        return [];
    }
}

/** First column name from $candidates that actually exists in $columns, or null. */
function pickColumn(array $columns, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (in_array($c, $columns, true)) return $c;
    }
    return null;
}

$results = []; // section => [ ['label'=>, 'status'=>PASS|WARN|FAIL|INFO, 'detail'=>] ]

function record(&$results, string $section, string $status, string $label, string $detail = '') {
    $results[$section][] = ['status' => $status, 'label' => $label, 'detail' => $detail];
}

try {
    $db = DBConnection::getConnection();
    if (!$db) throw new Exception("no connection object returned");
    $db->query("SELECT 1");
} catch (Throwable $e) {
    die("<h1 style='font-family:monospace;color:#dc3545;'>DATABASE CONNECTION FAILED: " . h($e->getMessage()) . "</h1>");
}

// ============================================================
// SECTION 1: CORE TABLE HEALTH
// ============================================================
$coreTables = [
    'vw_all_swaps', 'multi_destination_swaps', 'multi_source_swaps',
    'identity_swap_holds', 'hold_transactions', 'settlement_queue',
    'settlement_outbox', 'net_positions', 'audit_logs', 'swap_requests',
    'swap_transactions', 'cashout_authorizations'
];
foreach ($coreTables as $t) {
    try {
        $exists = $db->query("SELECT to_regclass('{$t}')")->fetchColumn();
        if (!$exists) {
            record($results, 'Core Tables', 'FAIL', $t, 'Table/view does not exist');
            continue;
        }
        $count = (int)$db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        record($results, 'Core Tables', $count > 0 ? 'PASS' : 'WARN', $t, "{$count} records" . ($count === 0 ? ' - empty, confirm this is expected' : ''));
    } catch (Throwable $e) {
        record($results, 'Core Tables', 'FAIL', $t, 'Query error: ' . $e->getMessage());
    }
}

// ============================================================
// SECTION 2: vw_all_swaps CAP CHECK
// This is the bug from the earlier conversation - confirms whether it's
// still capping the row count regardless of real swap volume.
// ============================================================
try {
    $viewDef = $db->query("SELECT pg_get_viewdef('vw_all_swaps', true)")->fetchColumn();
    $totalReal = (int)$db->query("SELECT COUNT(*) FROM swap_requests")->fetchColumn();
    $viewCount = (int)$db->query("SELECT COUNT(*) FROM vw_all_swaps")->fetchColumn();
    $hasLimitClause = (stripos($viewDef, 'limit') !== false);

    if ($hasLimitClause) {
        record($results, 'View Cap Check', 'FAIL', 'vw_all_swaps has a LIMIT clause',
            'This will silently drop transactions once you exceed the limit. View definition: ' . substr($viewDef, 0, 400) . (strlen($viewDef) > 400 ? '...' : ''));
    } else {
        record($results, 'View Cap Check', 'PASS', 'vw_all_swaps has no LIMIT clause',
            "swap_requests has {$totalReal} rows, vw_all_swaps returns {$viewCount} rows.");
    }
} catch (Throwable $e) {
    record($results, 'View Cap Check', 'WARN', 'Could not inspect vw_all_swaps definition', $e->getMessage());
}

// ============================================================
// SECTION 3: DATA INTEGRITY - things that would embarrass you if a
// regulator found them before you did.
// ============================================================
try {
    $stuck = (int)$db->query("
        SELECT COUNT(*) FROM hold_transactions
        WHERE status IN ('ACTIVE','HELD','PENDING_CASHOUT','PENDING_IDENTITY')
          AND created_at < NOW() - INTERVAL '24 hours'
    ")->fetchColumn();
    record($results, 'Data Integrity', $stuck === 0 ? 'PASS' : 'WARN', 'Stuck holds (non-terminal >24h)', "{$stuck} found");
} catch (Throwable $e) {
    record($results, 'Data Integrity', 'WARN', 'Stuck holds check failed', $e->getMessage());
}

try {
    $expiredIdentity = (int)$db->query("
        SELECT COUNT(*) FROM identity_swap_holds
        WHERE status = 'pending' AND hold_expires_at < NOW()
    ")->fetchColumn();
    record($results, 'Data Integrity', $expiredIdentity === 0 ? 'PASS' : 'WARN', 'Expired identity swaps not cancelled', "{$expiredIdentity} found");
} catch (Throwable $e) {
    record($results, 'Data Integrity', 'WARN', 'Expired identity check failed', $e->getMessage());
}

try {
    // Holds marked DEBITED but with no debited_at timestamp - suggests the
    // status was set without the corresponding audit trail field.
    $orphanDebits = (int)$db->query("
        SELECT COUNT(*) FROM hold_transactions
        WHERE status = 'DEBITED' AND debited_at IS NULL
    ")->fetchColumn();
    record($results, 'Data Integrity', $orphanDebits === 0 ? 'PASS' : 'FAIL', 'DEBITED holds missing debited_at timestamp',
        "{$orphanDebits} found" . ($orphanDebits > 0 ? ' - these will fail latency/audit queries and look inconsistent under review' : ''));
} catch (Throwable $e) {
    record($results, 'Data Integrity', 'WARN', 'Orphan debit check failed', $e->getMessage());
}

try {
    $failedDestCount = (int)$db->query("SELECT COUNT(*) FROM multi_destination_swaps WHERE failed_count > 0")->fetchColumn();
    record($results, 'Data Integrity', $failedDestCount === 0 ? 'PASS' : 'WARN', 'Multi-destination swaps with at least one failed leg', "{$failedDestCount} found");
} catch (Throwable $e) {
    record($results, 'Data Integrity', 'WARN', 'Failed-destination check failed', $e->getMessage());
}

// ============================================================
// SECTION 4: THE ACTUAL TEST - can we reconstruct one real transaction
// end-to-end? Picks a real, recent, completed swap and pulls together
// everything that should reference it. This is what "produce the record"
// looks like in practice.
// ============================================================
$reconstructionTarget = null;
try {
    $stmt = $db->query("
        SELECT swap_reference, reference, swap_type, source_institution,
               destination_institution, amount, currency, status, created_at
        FROM vw_all_swaps
        WHERE status ILIKE '%debited%' OR status ILIKE '%completed%' OR status ILIKE '%success%'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $reconstructionTarget = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    record($results, 'Transaction Reconstruction', 'FAIL', 'Could not select a target transaction', $e->getMessage());
}

if ($reconstructionTarget) {
    $ref = $reconstructionTarget['swap_reference'] ?: $reconstructionTarget['reference'];
    record($results, 'Transaction Reconstruction', 'INFO', 'Target transaction selected', "Reference: {$ref} | Type: {$reconstructionTarget['swap_type']} | Amount: {$reconstructionTarget['amount']} {$reconstructionTarget['currency']} | Status: {$reconstructionTarget['status']} | Created: {$reconstructionTarget['created_at']}");

    // 4a. Hold record(s) - direct and any sub-references (_DEST_0, _ID_0 etc.)
    try {
        $stmt = $db->prepare("SELECT hold_reference, swap_reference, participant_name, asset_type, amount, status, created_at, debited_at FROM hold_transactions WHERE swap_reference = :ref OR swap_reference LIKE :refLike ORDER BY created_at");
        $stmt->execute([':ref' => $ref, ':refLike' => $ref . '\_%']);
        $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        record($results, 'Transaction Reconstruction', count($holds) > 0 ? 'PASS' : 'FAIL', 'Hold record(s) found', count($holds) . ' hold(s): ' . implode('; ', array_map(fn($x) => "{$x['hold_reference']} ({$x['status']}, {$x['participant_name']}, {$x['amount']})", $holds)));
    } catch (Throwable $e) {
        record($results, 'Transaction Reconstruction', 'WARN', 'Hold lookup failed', $e->getMessage());
    }

    // 4b. Settlement trail - dynamically discover the right column since
    // we don't assume settlement_queue/settlement_outbox schema.
    try {
        $cols = getColumns($db, 'settlement_outbox');
        $refCol = pickColumn($cols, ['swap_reference', 'reference']);
        if ($refCol) {
            $stmt = $db->prepare("SELECT message_id, message_type, source_institution, destination_institution, created_at FROM settlement_outbox WHERE {$refCol} = :ref OR {$refCol} LIKE :refLike");
            $stmt->execute([':ref' => $ref, ':refLike' => $ref . '%']);
            $outbox = $stmt->fetchAll(PDO::FETCH_ASSOC);
            record($results, 'Transaction Reconstruction', count($outbox) > 0 ? 'PASS' : 'WARN', 'Settlement outbox messages found', count($outbox) . ' message(s) via column "' . $refCol . '"');
        } else {
            record($results, 'Transaction Reconstruction', 'WARN', 'Could not find a reference column on settlement_outbox', 'Columns present: ' . implode(', ', $cols));
        }
    } catch (Throwable $e) {
        record($results, 'Transaction Reconstruction', 'WARN', 'Settlement outbox lookup failed', $e->getMessage());
    }

    try {
        $cols = getColumns($db, 'settlement_queue');
        $refCol = pickColumn($cols, ['swap_reference', 'reference', 'transaction_reference']);
        if ($refCol) {
            $stmt = $db->prepare("SELECT * FROM settlement_queue WHERE {$refCol} = :ref OR {$refCol} LIKE :refLike LIMIT 10");
            $stmt->execute([':ref' => $ref, ':refLike' => $ref . '%']);
            $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
            record($results, 'Transaction Reconstruction', count($queue) > 0 ? 'PASS' : 'WARN', 'Settlement queue entries found', count($queue) . ' entr(y/ies) via column "' . $refCol . '"');
        } else {
            record($results, 'Transaction Reconstruction', 'WARN', 'settlement_queue has no per-transaction reference column',
                'Columns present: ' . implode(', ', $cols) . '. This means settlement_queue tracks net institution-to-institution positions, not individual transactions - you cannot trace THIS specific swap to a settlement_queue row directly. If a regulator asks "show me the settlement for transaction X", you currently cannot answer from settlement_queue alone - you would need to reconcile via net_positions and the fee/amount matching manually.');
        }
    } catch (Throwable $e) {
        record($results, 'Transaction Reconstruction', 'WARN', 'Settlement queue lookup failed', $e->getMessage());
    }

    // 4c. Audit log coverage
    try {
        $cols = getColumns($db, 'audit_logs');
        $entityCol = pickColumn($cols, ['entity_id']);
        if ($entityCol) {
            $stmt = $db->prepare("SELECT audit_id, entity_type, action, created_at FROM audit_logs WHERE entity_id::text = :ref OR entity_id::text LIKE :refLike ORDER BY created_at LIMIT 20");
            $stmt->execute([':ref' => $ref, ':refLike' => '%' . $ref . '%']);
            $audit = $stmt->fetchAll(PDO::FETCH_ASSOC);
            record($results, 'Transaction Reconstruction', count($audit) > 0 ? 'PASS' : 'FAIL', 'Audit log entries found', count($audit) . ' entr(y/ies)' . (count($audit) === 0 ? ' - if this is really zero, you have NO audit trail for this transaction, which is a direct problem for any regulator or court request' : ''));
        } else {
            record($results, 'Transaction Reconstruction', 'WARN', 'Could not identify entity_id column on audit_logs', 'Columns present: ' . implode(', ', $cols));
        }
    } catch (Throwable $e) {
        record($results, 'Transaction Reconstruction', 'WARN', 'Audit log lookup failed', $e->getMessage());
    }

    // 4d. Signature chain - was this a signed, certificate-backed hold?
    try {
        $stmt = $db->prepare("SELECT metadata FROM hold_transactions WHERE swap_reference = :ref OR swap_reference LIKE :refLike LIMIT 1");
        $stmt->execute([':ref' => $ref, ':refLike' => $ref . '\_%']);
        $meta = $stmt->fetchColumn();
        if ($meta) {
            $decoded = json_decode($meta, true);
            $hasSig = !empty($decoded['signature_chain']) || !empty($decoded['hold_result']['signature']);
            record($results, 'Transaction Reconstruction', $hasSig ? 'PASS' : 'WARN', 'Signature/proof chain present in hold metadata', $hasSig ? 'Signature data found' : 'No signature found in metadata - this weakens the evidentiary value of the record');
        } else {
            record($results, 'Transaction Reconstruction', 'WARN', 'No hold metadata to check for signatures', '');
        }
    } catch (Throwable $e) {
        record($results, 'Transaction Reconstruction', 'WARN', 'Signature check failed', $e->getMessage());
    }
} else {
    record($results, 'Transaction Reconstruction', 'FAIL', 'No completed transaction found to test against', 'Cannot verify reconstruction capability with zero completed swaps in vw_all_swaps');
}

// ============================================================
// SECTION 5: INVOICE CAPABILITY - checked, not assumed.
// ============================================================
try {
    $invoiceCount = (int)$db->query("SELECT COUNT(*) FROM settlement_outbox WHERE message_type = 'FEE_INVOICE'")->fetchColumn();
    record($results, 'Invoice Capability', $invoiceCount > 0 ? 'PASS' : 'WARN', 'FEE_INVOICE messages in settlement_outbox', "{$invoiceCount} found" . ($invoiceCount === 0 ? ' - either no invoices have been generated yet, or invoice generation is not wired to this table' : ''));
} catch (Throwable $e) {
    record($results, 'Invoice Capability', 'WARN', 'Could not query settlement_outbox for invoices', $e->getMessage());
}

try {
    $hasInvoiceTable = $db->query("SELECT to_regclass('invoices')")->fetchColumn();
    if ($hasInvoiceTable) {
        $invCount = (int)$db->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
        record($results, 'Invoice Capability', 'PASS', 'Dedicated invoices table exists', "{$invCount} records");
    } else {
        record($results, 'Invoice Capability', 'INFO', 'No dedicated "invoices" table', 'Invoicing (if it exists) is likely driven entirely through settlement_outbox FEE_INVOICE messages, not a separate ledger table');
    }
} catch (Throwable $e) {
    record($results, 'Invoice Capability', 'WARN', 'Could not check for invoices table', $e->getMessage());
}

record($results, 'Invoice Capability', 'INFO', 'Cannot confirm invoice PDF/document generation from the database alone',
    'This diagnostic can only see what data exists, not whether the actual invoice-generation code (the file behind ?action=generate_invoice) produces a correct, complete document. Share that file directly and it can be reviewed the same way the hold.php files were.');

// ============================================================
// SECTION 6: SETTLEMENT CONSISTENCY - do debited swaps have a settlement
// counterpart, or does money move without a paper trail?
// ============================================================
try {
    $debitedCount = (int)$db->query("SELECT COUNT(*) FROM hold_transactions WHERE status = 'DEBITED'")->fetchColumn();
    $cols = getColumns($db, 'settlement_outbox');
    if (in_array('swap_reference', $cols, true)) {
        $settledRefs = (int)$db->query("SELECT COUNT(DISTINCT swap_reference) FROM settlement_outbox")->fetchColumn();
        record($results, 'Settlement Consistency', 'INFO', 'Debited holds vs distinct settled references',
            "{$debitedCount} debited holds in hold_transactions vs {$settledRefs} distinct swap_reference values in settlement_outbox. These won't match 1:1 (multi-destination swaps have many holds per one settlement batch), but if settled refs is dramatically lower, settlements are lagging or not firing for some swap types.");
    }
} catch (Throwable $e) {
    record($results, 'Settlement Consistency', 'WARN', 'Settlement consistency check failed', $e->getMessage());
}

// ============================================================
// RENDER
// ============================================================
$statusColor = ['PASS' => '#28a745', 'WARN' => '#856404', 'FAIL' => '#dc3545', 'INFO' => '#004085'];
$statusBg = ['PASS' => '#d4edda', 'WARN' => '#fff3cd', 'FAIL' => '#f8d7da', 'INFO' => '#cce5ff'];

$totalFail = 0; $totalWarn = 0; $totalPass = 0;
foreach ($results as $items) {
    foreach ($items as $i) {
        if ($i['status'] === 'FAIL') $totalFail++;
        if ($i['status'] === 'WARN') $totalWarn++;
        if ($i['status'] === 'PASS') $totalPass++;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Sandbox Readiness Test</title>
<style>
    body { font-family: 'IBM Plex Mono', monospace; background:#f7f9fc; color:#001B44; padding:24px; max-width:1100px; margin:0 auto; }
    h1 { font-size:1.3rem; }
    .summary { display:flex; gap:12px; margin:16px 0 24px; flex-wrap:wrap; }
    .summary div { padding:10px 18px; border-radius:6px; font-weight:700; font-size:0.9rem; }
    .section { background:#fff; border:2px solid #001B44; border-radius:6px; padding:16px; margin-bottom:16px; box-shadow:3px 3px 0 #A1B5D8; }
    .section h2 { font-size:0.85rem; text-transform:uppercase; margin-bottom:10px; border-bottom:2px solid #001B44; padding-bottom:6px; }
    .row { display:flex; gap:10px; padding:6px 0; border-bottom:1px solid #eee; align-items:flex-start; font-size:0.75rem; }
    .badge { padding:2px 8px; border-radius:4px; font-weight:700; font-size:0.65rem; white-space:nowrap; }
    .label { font-weight:600; min-width:280px; }
    .detail { color:#555; }
</style>
</head>
<body>
<h1>🔍 Sandbox Readiness / Court-Defensibility Test</h1>
<p style="color:#666; font-size:0.75rem;">Run at <?php echo date('Y-m-d H:i:s'); ?> · Read-only, no data was modified</p>

<div class="summary">
    <div style="background:#d4edda;color:#155724;">✅ <?php echo $totalPass; ?> PASS</div>
    <div style="background:#fff3cd;color:#856404;">⚠️ <?php echo $totalWarn; ?> WARN</div>
    <div style="background:#f8d7da;color:#721c24;">❌ <?php echo $totalFail; ?> FAIL</div>
</div>

<?php if ($totalFail > 0): ?>
<div class="section" style="border-color:#dc3545;">
    <h2 style="color:#dc3545;">⚠️ Bottom line</h2>
    <p style="font-size:0.8rem;">There are <?php echo $totalFail; ?> FAIL item(s) below. Those are the ones that would actually hurt you in front of a regulator or in a dispute - fix those first. WARN items are worth reviewing but aren't necessarily broken.</p>
</div>
<?php else: ?>
<div class="section" style="border-color:#28a745;">
    <h2 style="color:#28a745;">✅ Bottom line</h2>
    <p style="font-size:0.8rem;">No hard failures. Review the WARN items below - some of them (like settlement_queue not having a per-transaction reference) are structural things worth knowing about even if they're not "broken."</p>
</div>
<?php endif; ?>

<?php foreach ($results as $section => $items): ?>
<div class="section">
    <h2><?php echo h($section); ?></h2>
    <?php foreach ($items as $item): ?>
    <div class="row">
        <span class="badge" style="background:<?php echo $statusBg[$item['status']]; ?>; color:<?php echo $statusColor[$item['status']]; ?>;"><?php echo $item['status']; ?></span>
        <span class="label"><?php echo h($item['label']); ?></span>
        <span class="detail"><?php echo h($item['detail']); ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

</body>
</html>
