<?php
declare(strict_types=1);

/**
 * admin/reconcile_stuck_identity_claims.php
 *
 * Browser front end for StuckIdentityClaimReconciler, for environments
 * without a shell — same rationale and the same auth pattern as
 * run_swap_identity_v2_migrations.php.
 *
 * Finds identity-swap holds that were debited at the source bank but whose
 * claim never completed, and closes them out. See the service for why they
 * exist and why the two groups are treated differently.
 *
 * Nothing is written unless "Cancel these holds" is pressed. Opening the
 * page, and the Preview button, only ever read.
 *
 * Gated behind requirePlatformConfigAuth(), the same guard the migration
 * runner uses, since this changes the state of money records.
 *
 * Safe to delete once the backlog from the old debit-first claim order is
 * cleared — claims no longer strand holds this way, so it has no other
 * purpose.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/auth.php';

use Core\Database\DBConnection;
use Domain\Services\StuckIdentityClaimReconciler;

$admin = requirePlatformConfigAuth();

function safeHtmlR($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = null;
$found = null;
$applied = null;
$dbLabel = null;
$minAge = StuckIdentityClaimReconciler::DEFAULT_MIN_AGE_MINUTES;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    $minAge = max(1, (int)($_POST['min_age_minutes'] ?? StuckIdentityClaimReconciler::DEFAULT_MIN_AGE_MINUTES));

    try {
        $db = DBConnection::getConnection();
        if (!$db) {
            throw new RuntimeException('Could not connect to the main database (DATABASE_URL).');
        }
        $status = DBConnection::getStatus();
        $dbLabel = "{$status['host']}/{$status['database']}";

        $reconciler = new StuckIdentityClaimReconciler($db);
        $found = $reconciler->find($minAge);

        if ($action === 'apply') {
            // Re-read rather than trusting anything posted back from the
            // browser: the caller confirms an intent to close whatever is
            // currently stuck, not a list of ids it could edit.
            $applied = $reconciler->cancel($found['cancellable']);
            $found = $reconciler->find($minAge);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Stuck identity claims</title>
<style>
    body { font-family: -apple-system, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; color: #1a1a1a; }
    h1 { font-size: 20px; }
    h2 { font-size: 15px; margin: 24px 0 8px; }
    .step { border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin-bottom: 16px; }
    .step p { font-size: 13px; color: #555; margin: 0 0 12px; }
    button { padding: 10px 16px; border-radius: 4px; border: 1px solid #333; background: #f5f5f5; cursor: pointer; font-size: 13px; }
    button.primary { background: #1a1a1a; color: #fff; }
    input[type=number] { padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 90px; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 16px; }
    td, th { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; }
    .error { background: #fbeceb; color: #b3261e; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
    .ok { background: #e8f5ec; color: #1a7f37; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
    .warn { background: #fff5e6; color: #8a5300; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
    .muted { font-size: 12px; color: #666; }
</style>
</head>
<body>

<h1>Stuck identity claims</h1>
<p class="muted">
    Logged in as <?php echo safeHtmlR($admin['username'] ?? $admin['email'] ?? 'admin'); ?>.
    <?php if ($dbLabel !== null): ?>Connected to <strong><?php echo safeHtmlR($dbLabel); ?></strong>.<?php endif; ?>
</p>

<p class="muted">
    Holds debited at the source bank whose claim never finished. They were left
    <code>pending</code> by the old debit-first claim order, so they are still offered as
    claimable even though the money already went back to the customer — and every retry
    fails on a hold reference the bank has already spent.
</p>

<?php if ($error): ?>
<div class="error">Error: <?php echo safeHtmlR($error); ?></div>
<?php endif; ?>

<?php if ($applied !== null): ?>
<div class="ok">
    Cancelled <?php echo count($applied['cancelled']); ?> hold(s)<?php
        echo $applied['cancelled'] ? ': ' . safeHtmlR(implode(', ', $applied['cancelled'])) : ''; ?>.
    <?php if ($applied['skipped']): ?>
        Skipped <?php echo count($applied['skipped']); ?> (no longer <code>pending</code> — claimed or changed in the meantime):
        <?php echo safeHtmlR(implode(', ', $applied['skipped'])); ?>.
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="step">
    <p>
        Preview reads only — nothing is written until you press the second button.
        Holds debited less than this many minutes ago are never shown, so a claim
        still in flight cannot be touched.
    </p>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlR($csrfToken); ?>">
        <input type="hidden" name="action" value="preview">
        <label class="muted">Minimum age (minutes)
            <input type="number" name="min_age_minutes" min="1" value="<?php echo safeHtmlR($minAge); ?>">
        </label>
        <button type="submit">Preview stuck holds</button>
    </form>
</div>

<?php if ($found !== null): ?>

    <?php if ($found['needs_human']): ?>
    <h2>Left alone — needs a human (<?php echo count($found['needs_human']); ?>)</h2>
    <div class="warn">
        These carry an unresolved manual-reconciliation record, meaning the credit back to the
        customer <em>also</em> failed. The money may genuinely still be missing, so these are
        never cancelled — closing one would bury the case.
    </div>
    <table>
        <tr><th>Hold</th><th>Swap reference</th><th>Amount</th><th>Source</th><th>Reason</th></tr>
        <?php foreach ($found['needs_human'] as $row): ?>
        <tr>
            <td><?php echo safeHtmlR($row['hold_id']); ?></td>
            <td><?php echo safeHtmlR($row['swap_reference']); ?></td>
            <td><?php echo safeHtmlR($row['currency'] . ' ' . $row['amount']); ?></td>
            <td><?php echo safeHtmlR($row['source_institution']); ?></td>
            <td class="muted"><?php echo safeHtmlR($row['reconciliation_reason']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <h2>Safe to close (<?php echo count($found['cancellable']); ?>)</h2>
    <?php if (!$found['cancellable']): ?>
        <p class="muted">Nothing to cancel.</p>
    <?php else: ?>
    <table>
        <tr><th>Hold</th><th>Swap reference</th><th>Amount</th><th>Source</th><th>Identity</th><th>Debited at</th></tr>
        <?php foreach ($found['cancellable'] as $row): ?>
        <tr>
            <td><?php echo safeHtmlR($row['hold_id']); ?></td>
            <td><?php echo safeHtmlR($row['swap_reference']); ?></td>
            <td><?php echo safeHtmlR($row['currency'] . ' ' . $row['amount']); ?></td>
            <td><?php echo safeHtmlR($row['source_institution']); ?></td>
            <td><?php echo safeHtmlR($row['identity_type']); ?></td>
            <td class="muted"><?php echo safeHtmlR($row['debited_at']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="step">
        <p>
            Marks each of the <?php echo count($found['cancellable']); ?> hold(s) above
            <code>cancelled</code> so they stop being offered. Verify a couple against the source
            bank's own records first — the money should already be back in the customer's account.
        </p>
        <form method="POST" onsubmit="return confirm('This marks these holds cancelled in the main database. Continue?');">
            <input type="hidden" name="csrf_token" value="<?php echo safeHtmlR($csrfToken); ?>">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="min_age_minutes" value="<?php echo safeHtmlR($minAge); ?>">
            <button type="submit" class="primary">Cancel these holds</button>
        </form>
    </div>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>
