<?php
/**
 * reconcile_saccussalis_backlog.php
 *
 * Fixes the SACCUSSALIS held-funds backlog left behind by the historical
 * hold.php bug where RELEASE_HOLD/DEBIT never actually executed - every
 * hold VOUCHMORPH believed was "released" or "debited" was, in reality,
 * still ACTIVE at SACCUSSALIS, still reserving held_balance.
 *
 * PREREQUISITE: the FIXED SACCUSSALIS hold.php (the one with real action
 * dispatch for RELEASE_HOLD/DEBIT) must already be deployed and live at
 * https://saccussalis-production.up.railway.app/backend/api/v1/hold.php
 * before running this with ?confirm=1. If it's not deployed yet, every
 * row below will come back ERROR ("Missing required field") - that
 * itself is a useful signal the deploy hasn't gone out.
 *
 * HOW IT WORKS:
 *   1. Reads every hold_transactions row sourced from SACCUSSALIS that
 *      VOUCHMORPH's central records say is RELEASED or DEBITED.
 *   2. For each, calls the live SACCUSSALIS hold.php with the matching
 *      action - exactly the call SwapService itself would have made.
 *   3. Idempotent-safe: if the hold at SACCUSSALIS is ALREADY resolved
 *      there too, the endpoint returns "Hold is not active" and nothing
 *      changes - logged as ALREADY_OK, not an error. If it's still
 *      ACTIVE (the real backlog), this call is what finally fixes it.
 *
 * USAGE:
 *   No query string        -> DRY RUN. Lists every candidate row and
 *                              what action WOULD be called. No network
 *                              calls to SACCUSSALIS happen.
 *   ?confirm=1              -> LIVE RUN. Actually calls SACCUSSALIS for
 *                              every row and reports the real outcome.
 *   ?confirm=1&limit=5      -> Test on just the first 5 rows before
 *                              committing to the full batch.
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

const SACCUSSALIS_HOLD_URL = 'https://saccussalis-production.up.railway.app/backend/api/v1/hold.php';

try {
    $db = DBConnection::getConnection();
    if (!$db) throw new Exception("no connection object returned");
    $db->query("SELECT 1");
} catch (Throwable $e) {
    die("<pre style='color:#dc3545;font-family:monospace;'>DATABASE CONNECTION FAILED: " . h($e->getMessage()) . "</pre>");
}

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : null;

// ============================================================
// 1. Every SACCUSSALIS-sourced hold VOUCHMORPH believes is resolved.
// These are the reconciliation candidates.
// ============================================================
$candidates = [];
try {
    $stmt = $db->query("
        SELECT hold_reference, swap_reference, status, amount, currency, asset_type, created_at, metadata
        FROM hold_transactions
        WHERE source_institution = 'SACCUSSALIS'
          AND status IN ('RELEASED', 'DEBITED')
        ORDER BY created_at ASC
    ");
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    die("<pre style='color:#dc3545;font-family:monospace;'>Failed to read hold_transactions: " . h($e->getMessage()) . "</pre>");
}

if ($limit !== null) {
    $candidates = array_slice($candidates, 0, $limit);
}

// ============================================================
// FIX: hold_transactions.hold_reference is VOUCHMORPH's OWN internal
// bookkeeping label ('HOLD_' . swap_reference) - it was never sent to
// SACCUSSALIS. The reference SACCUSSALIS actually knows the hold by is
// swap_reference (what SwapService::createLocalHold() stores alongside
// it), with metadata->>'external_hold_reference' as a cross-check in
// case any row was created through a different path.
// ============================================================
foreach ($candidates as &$cand) {
    $metaExternal = null;
    if (!empty($cand['metadata'])) {
        $meta = json_decode($cand['metadata'], true);
        $metaExternal = $meta['external_hold_reference'] ?? null;
    }
    $cand['primary_ref'] = $cand['swap_reference'];
    $cand['fallback_ref'] = ($metaExternal && $metaExternal !== $cand['swap_reference']) ? $metaExternal : null;
}
unset($cand);

function callSaccussalisHold(string $action, string $holdReference, float $amount): array {
    $payload = [
        'action' => $action,
        'hold_reference' => $holdReference,
        'reference' => 'reconcile_' . bin2hex(random_bytes(6)),
        'amount' => $amount,
        'requester' => 'VOUCHMORPH_RECONCILE',
    ];

    $ch = curl_init(SACCUSSALIS_HOLD_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'message' => 'Network error calling SACCUSSALIS: ' . $curlError];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['ok' => false, 'message' => 'Non-JSON response (HTTP ' . $httpCode . '): ' . substr((string)$response, 0, 200)];
    }

    return ['ok' => true, 'response' => $decoded];
}

function classifyOutcome(array $resp): array {
    $msg = strtolower($resp['message'] ?? '');
    if (($resp['status'] ?? '') === 'SUCCESS') {
        return ['outcome' => 'FIXED', 'detail' => 'Was actually still ACTIVE at SACCUSSALIS - now corrected. ' . ($resp['message'] ?? '')];
    }
    if (str_contains($msg, 'not active')) {
        // Preserve the ACTUAL status SACCUSSALIS reported, e.g.
        // "Hold is not active (status: DEBITED)" vs "(status: RELEASED)" -
        // these are both "not active" but only one of them is the status
        // this call was trying to confirm. Surface the real message so a
        // RELEASED-when-should-be-DEBITED mismatch doesn't get buried
        // under a reassuring generic label.
        return ['outcome' => 'ALREADY_OK', 'detail' => $resp['message'] ?? 'Already resolved at SACCUSSALIS.'];
    }
    if (str_contains($msg, 'no hold found')) {
        return ['outcome' => 'NOT_FOUND', 'detail' => 'No matching hold at SACCUSSALIS for this reference.'];
    }
    return ['outcome' => 'ERROR', 'detail' => $resp['message'] ?? json_encode($resp)];
}

$results = [];
if ($confirm) {
    foreach ($candidates as $c) {
        $action = $c['status'] === 'DEBITED' ? 'DEBIT' : 'RELEASE_HOLD';
        $refUsed = $c['primary_ref'];
        $outcome = callSaccussalisHold($action, $refUsed, (float)$c['amount']);

        if (!$outcome['ok']) {
            $results[] = ['hold_reference' => $refUsed, 'action' => $action, 'outcome' => 'CALL_FAILED', 'detail' => $outcome['message']];
            continue;
        }

        $classified = classifyOutcome($outcome['response']);
        $fallbackTried = false;

        if ($classified['outcome'] === 'NOT_FOUND' && !empty($c['fallback_ref'])) {
            $fallbackTried = true;
            $refUsed = $c['fallback_ref'];
            $retryOutcome = callSaccussalisHold($action, $refUsed, (float)$c['amount']);
            if ($retryOutcome['ok']) {
                $classified = classifyOutcome($retryOutcome['response']);
            }
        }

        if ($classified['outcome'] === 'NOT_FOUND') {
            $classified['detail'] .= $fallbackTried
                ? ' Also tried metadata.external_hold_reference - still not found. This hold likely never existed at SACCUSSALIS; check manually whether the original PLACE_HOLD for this reference actually succeeded.'
                : ' No fallback reference available in metadata to retry with. Check manually whether the original PLACE_HOLD for this reference actually succeeded.';
        }

        // For ALREADY_OK, flag explicitly if the confirmed status doesn't
        // match what a DEBIT/RELEASE call was expecting to confirm.
        // 'COMMITTED' is confirmed legacy vocabulary for what's now
        // DEBITED (its date range overlapped with HELD's, meaning it
        // coexisted as a distinct terminal state rather than being
        // sequentially replaced) - treat it as an accepted synonym so
        // these old rows aren't wrongly flagged as mismatches.
        if ($classified['outcome'] === 'ALREADY_OK') {
            $acceptedStatuses = $action === 'DEBIT' ? ['DEBITED', 'COMMITTED'] : ['RELEASED'];
            $matchesAny = false;
            foreach ($acceptedStatuses as $s) {
                if (stripos($classified['detail'], $s) !== false) { $matchesAny = true; break; }
            }
            if (!$matchesAny) {
                $classified['outcome'] = 'STATUS_MISMATCH';
                $expectedLabel = implode(' or ', $acceptedStatuses);
                $classified['detail'] = "Expected {$expectedLabel} but SACCUSSALIS reports a different status - " . $classified['detail'];
            }
        }

        $results[] = ['hold_reference' => $refUsed, 'action' => $action, 'outcome' => $classified['outcome'], 'detail' => $classified['detail']];
    }
}

$counts = ['FIXED' => 0, 'ALREADY_OK' => 0, 'STATUS_MISMATCH' => 0, 'NOT_FOUND' => 0, 'ERROR' => 0, 'CALL_FAILED' => 0];
foreach ($results as $r) { $counts[$r['outcome']]++; }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>SACCUSSALIS Backlog Reconciliation</title>
<style>
    body { font-family:'IBM Plex Mono',monospace; background:#f7f9fc; color:#001B44; padding:24px; max-width:1100px; margin:0 auto; }
    .section { background:#fff; border:2px solid #001B44; border-radius:6px; padding:16px; margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; font-size:0.75rem; }
    th { background:#001B44; color:#fff; padding:6px 10px; text-align:left; }
    td { padding:5px 10px; border-bottom:1px solid #eee; }
    .outcome { padding:2px 8px; border-radius:4px; font-weight:700; font-size:0.65rem; }
    .FIXED { background:#d4edda; color:#155724; }
    .ALREADY_OK { background:#e2e3e5; color:#41464b; }
    .STATUS_MISMATCH { background:#ffe0b2; color:#8a4b00; }
    .NOT_FOUND { background:#fff3cd; color:#856404; }
    .ERROR, .CALL_FAILED { background:#f8d7da; color:#721c24; }
    .btn { display:inline-block; padding:10px 20px; background:#dc3545; color:#fff; text-decoration:none; border-radius:4px; font-weight:700; margin-right:8px; margin-top:12px; }
    .btn.secondary { background:#856404; }
    .summary div { display:inline-block; padding:8px 16px; margin-right:8px; margin-bottom:8px; border-radius:6px; font-weight:700; }
</style>
</head>
<body>
<h1>🔧 SACCUSSALIS Backlog Reconciliation</h1>
<p style="font-size:0.7rem; color:#20c997; font-weight:700;">SCRIPT VERSION: 2 (uses swap_reference, not the internal HOLD_ prefixed hold_reference) — if you don't see this line, the old file is still what's running.</p>
<p style="font-size:0.8rem; color:#666;">Found <?php echo count($candidates); ?> candidate hold(s) VOUCHMORPH believes are RELEASED/DEBITED for SACCUSSALIS<?php echo $limit ? " (showing first {$limit})" : ''; ?>.</p>

<?php if (!$confirm): ?>
<div class="section">
    <p><strong>This is a dry run — nothing has been called yet.</strong> Review the list below, then run with <code>?confirm=1</code> to actually call SACCUSSALIS for each one.</p>
    <p style="margin-top:8px;">Recommended: test on a handful first before committing to the full batch.</p>
</div>
<div class="section">
    <div class="table-responsive">
    <table>
        <thead><tr><th>VOUCHMORPH's Internal Label</th><th>Reference Actually Sent to SACCUSSALIS</th><th>Central Status</th><th>Would Call</th><th>Amount</th><th>Created</th></tr></thead>
        <tbody>
        <?php foreach ($candidates as $c): ?>
        <tr>
            <td style="color:#999;"><?php echo h($c['hold_reference']); ?></td>
            <td><strong><?php echo h($c['primary_ref']); ?></strong><?php if ($c['fallback_ref']): ?><br><span style="color:#856404;">fallback: <?php echo h($c['fallback_ref']); ?></span><?php endif; ?></td>
            <td><?php echo h($c['status']); ?></td>
            <td><strong><?php echo $c['status'] === 'DEBITED' ? 'DEBIT' : 'RELEASE_HOLD'; ?></strong></td>
            <td><?php echo number_format((float)$c['amount'], 2); ?> <?php echo h($c['currency'] ?? 'BWP'); ?></td>
            <td><?php echo h($c['created_at']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<a href="?confirm=1&limit=5" class="btn secondary">TEST ON FIRST 5</a>
<a href="?confirm=1" class="btn">RUN FULL RECONCILIATION</a>

<?php else: ?>
<div class="summary">
    <div style="background:#d4edda;color:#155724;">✅ Fixed: <?php echo $counts['FIXED']; ?></div>
    <div style="background:#e2e3e5;color:#41464b;">➖ Already OK: <?php echo $counts['ALREADY_OK']; ?></div>
    <div style="background:#ffe0b2;color:#8a4b00;">⚠️ Status Mismatch: <?php echo $counts['STATUS_MISMATCH']; ?></div>
    <div style="background:#fff3cd;color:#856404;">❓ Not Found: <?php echo $counts['NOT_FOUND']; ?></div>
    <div style="background:#f8d7da;color:#721c24;">❌ Errors: <?php echo $counts['ERROR'] + $counts['CALL_FAILED']; ?></div>
</div>
<?php if ($counts['ERROR'] + $counts['CALL_FAILED'] === count($results) && count($results) > 0): ?>
<div class="section" style="border-color:#dc3545;">
    <strong style="color:#dc3545;">Every single call failed the same way?</strong> That almost always means the fixed hold.php hasn't actually been deployed to SACCUSSALIS's production yet - check the "detail" column below for "Missing required field" to confirm, deploy it, then re-run.
</div>
<?php endif; ?>
<div class="section">
    <div class="table-responsive">
    <table>
        <thead><tr><th>Hold Reference</th><th>Action Called</th><th>Outcome</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><?php echo h($r['hold_reference']); ?></td>
            <td><?php echo h($r['action']); ?></td>
            <td><span class="outcome <?php echo h($r['outcome']); ?>"><?php echo h($r['outcome']); ?></span></td>
            <td><?php echo h($r['detail']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</body>
</html>
