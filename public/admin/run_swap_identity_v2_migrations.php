<?php
declare(strict_types=1);

/**
 * platform-admin/run_swap_identity_v2_migrations.php
 *
 * Browser-based front end for running the pending migrations behind
 * the swap-to-identity algorithm v2 build-out, for environments where a
 * shell isn't available (e.g. some Railway plans) — same rationale and
 * same auth pattern as run_credentials_migration.php.
 *
 * Scoped deliberately narrow: this runs exactly these files, never
 * arbitrary SQL and never anything from database/migrations/ this page
 * doesn't explicitly name. It does NOT include
 * 2026_08_30_message_cards_unique_user_id.sql — that migration predates
 * this work, is unrelated to it, and whether it's already applied is out
 * of scope for this page to assume or touch.
 *
 *   1. database/migrations/2026_09_15_reservation_accounts.sql
 *   2. database/migrations/2026_09_16_source_account_type.sql
 *   3. database/migrations/2026_09_17_reservation_account_consumed_status.sql
 *   4. database/migrations/2026_09_18_activity_and_sub_requests.sql
 *   5. database/migrations/2026_09_24_reservation_account_claim_pin_lockout.sql
 *   6. database/migrations/2026_09_27_source_registration_otp_lockout.sql
 *      (not swap-to-identity work, but the same kind of attempt limit, and
 *      shipped the same way - listed here so it can be applied without a
 *      shell)
 *
 * All six are idempotent (IF NOT EXISTS / DROP...IF EXISTS guards
 * throughout) — safe to click Apply more than once. Each file's own
 * BEGIN/COMMIT makes it atomic; a failure partway through one file rolls
 * that file back, and this page stops before running any file after it,
 * since later files may depend on earlier ones' schema.
 *
 * Gated behind requirePlatformConfigAuth() — the same guard
 * organizations/create.php and run_credentials_migration.php use, since
 * this writes schema to the main database.
 *
 * Safe to delete once all of these migrations are applied and verified in
 * every environment that needs them — it has no other purpose.
 */

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/auth.php';

use Core\Database\DBConnection;

$admin = requirePlatformConfigAuth();

/** @var array<int, array{key: string, label: string, path: string}> */
const MIGRATIONS = [
    [
        'key' => 'reservation_accounts',
        'label' => '2026_09_15_reservation_accounts.sql',
        'path' => __DIR__ . '/../../database/migrations/2026_09_15_reservation_accounts.sql',
    ],
    [
        'key' => 'source_account_type',
        'label' => '2026_09_16_source_account_type.sql',
        'path' => __DIR__ . '/../../database/migrations/2026_09_16_source_account_type.sql',
    ],
    [
        'key' => 'reservation_account_consumed_status',
        'label' => '2026_09_17_reservation_account_consumed_status.sql',
        'path' => __DIR__ . '/../../database/migrations/2026_09_17_reservation_account_consumed_status.sql',
    ],
    [
        'key' => 'activity_and_sub_requests',
        'label' => '2026_09_18_activity_and_sub_requests.sql',
        'path' => __DIR__ . '/../../database/migrations/2026_09_18_activity_and_sub_requests.sql',
    ],
    [
        'key' => 'reservation_account_claim_pin_lockout',
        'label' => '2026_09_24_reservation_account_claim_pin_lockout.sql',
        'path' => __DIR__ . '/../../database/migrations/2026_09_24_reservation_account_claim_pin_lockout.sql',
    ],
    [
        'key' => 'source_registration_otp_lockout',
        'label' => '2026_09_27_source_registration_otp_lockout.sql',
        'path' => __DIR__ . '/../../database/migrations/2026_09_27_source_registration_otp_lockout.sql',
    ],
];

function safeHtmlV($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Introspects whether each migration's schema change is already present,
 * by querying information_schema / pg_catalog directly rather than
 * tracking a separate "migrations applied" ledger table (this repo has
 * none — see the migration files' own comments on identity_swap_holds
 * and identity_holding_positions predating tracked migrations).
 */
function checkMigrationStatus(PDO $db): array {
    $status = [];

    $stmt = $db->query("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = 'reservation_accounts'
        )
    ");
    $status['reservation_accounts'] = (bool)$stmt->fetchColumn();

    $stmt = $db->query("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_name = 'identity_holding_positions' AND column_name = 'owner_user_id'
        )
    ");
    $status['reservation_accounts'] = $status['reservation_accounts'] && (bool)$stmt->fetchColumn();

    $stmt = $db->query("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_name = 'identity_swap_holds' AND column_name = 'source_account_type'
        )
    ");
    $status['source_account_type'] = (bool)$stmt->fetchColumn();

    // The CHECK constraint's definition text is the only way to tell
    // whether 'consumed' has been added to it — there's no boolean flag
    // for "does this constraint allow this value".
    $stmt = $db->query("
        SELECT pg_get_constraintdef(oid) AS def
        FROM pg_constraint
        WHERE conname = 'reservation_accounts_status_check'
    ");
    $constraintDef = $stmt->fetchColumn();
    $status['reservation_account_consumed_status'] = $constraintDef !== false && str_contains((string)$constraintDef, 'consumed');

    // Both tables, not just one: the migration creates swap_sub_requests
    // with a foreign key onto swap_activity, so a run that somehow made
    // only the first is not "applied" and must not report as such.
    $stmt = $db->query("
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name IN ('swap_activity', 'swap_sub_requests')
    ");
    $status['activity_and_sub_requests'] = (int)$stmt->fetchColumn() === 2;

    $stmt = $db->query("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_name = 'reservation_accounts'
          AND column_name IN ('claim_pin_attempts', 'claim_pin_locked_until')
    ");
    $status['reservation_account_claim_pin_lockout'] = (int)$stmt->fetchColumn() === 2;

    $stmt = $db->query("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_name = 'user_source_registration_attempts' AND column_name = 'otp_failed_attempts'
        )
    ");
    $status['source_registration_otp_lockout'] = (bool)$stmt->fetchColumn();

    return $status;
}

$output = [];
$error = null;
$actionRun = null;
$statusBefore = null;
$statusAfter = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    $actionRun = $action;

    try {
        $db = DBConnection::getConnection();
        if (!$db) {
            throw new RuntimeException("Could not connect to the main database (DATABASE_URL).");
        }
        $dbStatus = DBConnection::getStatus();
        $output[] = "Connected to: {$dbStatus['host']}/{$dbStatus['database']}";
        $output[] = "";

        $statusBefore = checkMigrationStatus($db);

        if ($action === 'status') {
            $output[] = "Status check only — nothing was run.";
        } elseif ($action === 'apply') {
            foreach (MIGRATIONS as $migration) {
                if ($statusBefore[$migration['key']]) {
                    $output[] = "SKIP  {$migration['label']} — already applied.";
                    continue;
                }

                if (!is_file($migration['path'])) {
                    throw new RuntimeException("Migration file not found on this deployment: {$migration['path']}");
                }
                $sql = file_get_contents($migration['path']);
                if ($sql === false || trim($sql) === '') {
                    throw new RuntimeException("Migration file is empty or unreadable: {$migration['path']}");
                }

                $output[] = "RUN   {$migration['label']} ...";
                try {
                    $db->exec($sql);
                    $output[] = "OK    {$migration['label']} applied.";
                } catch (\Throwable $e) {
                    $output[] = "FAIL  {$migration['label']}: " . $e->getMessage();
                    // Stop here — a later migration in the list may depend
                    // on this one's schema (e.g. the consumed-status
                    // migration widens a CHECK constraint the first
                    // migration creates), so running further files after
                    // an unexplained failure could make a bad state worse.
                    throw new RuntimeException(
                        "Stopped after {$migration['label']} failed. Earlier migrations in this run (if any) already committed — see OK/SKIP lines above.",
                        0,
                        $e
                    );
                }
            }
            $output[] = "";
            $output[] = "Apply run complete.";
        } else {
            throw new RuntimeException("Unknown action.");
        }

        $statusAfter = checkMigrationStatus($db);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
} else {
    try {
        $db = DBConnection::getConnection();
        if ($db) {
            $statusBefore = checkMigrationStatus($db);
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$csrfToken = generateCsrfToken();
$displayStatus = $statusAfter ?? $statusBefore;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Swap-to-identity v2 migrations</title>
<style>
    body { font-family: -apple-system, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; color: #1a1a1a; }
    h1 { font-size: 20px; }
    .step { border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin-bottom: 16px; }
    .step h2 { font-size: 15px; margin: 0 0 8px; }
    .step p { font-size: 13px; color: #555; margin: 0 0 12px; }
    button { padding: 10px 16px; border-radius: 4px; border: 1px solid #333; background: #f5f5f5; cursor: pointer; font-size: 13px; }
    button.primary { background: #1a1a1a; color: #fff; }
    pre { background: #0b1b2b; color: #d3dad6; padding: 16px; border-radius: 6px; overflow-x: auto; font-size: 12px; white-space: pre-wrap; }
    .error { background: #fbeceb; color: #b3261e; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 16px; }
    td, th { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; }
    .applied { color: #1a7f37; font-weight: 600; }
    .pending { color: #b3261e; font-weight: 600; }
</style>
</head>
<body>

<h1>Swap-to-identity algorithm v2 — pending migrations</h1>
<p>Logged in as <?php echo safeHtmlV($admin['username'] ?? $admin['email'] ?? 'admin'); ?>.</p>

<?php if ($error): ?>
<div class="error">Error: <?php echo safeHtmlV($error); ?></div>
<?php endif; ?>

<?php if ($displayStatus !== null): ?>
<table>
<tr><th>Migration</th><th>Status</th></tr>
<?php foreach (MIGRATIONS as $migration): $applied = $displayStatus[$migration['key']] ?? false; ?>
<tr>
    <td><?php echo safeHtmlV($migration['label']); ?></td>
    <td class="<?php echo $applied ? 'applied' : 'pending'; ?>"><?php echo $applied ? 'Applied' : 'Pending'; ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($actionRun && !$error): ?>
<pre><?php echo safeHtmlV(implode("\n", $output)); ?></pre>
<?php endif; ?>

<div class="step">
    <h2>Check status</h2>
    <p>Read-only — queries information_schema/pg_catalog directly to see which of these migrations are already applied. Runs no SQL that changes anything.</p>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlV($csrfToken); ?>">
        <input type="hidden" name="action" value="status">
        <button type="submit">Check status</button>
    </form>
</div>

<div class="step">
    <h2>Apply pending migrations</h2>
    <p>Runs whichever of the files above are not yet applied, in order, stopping if one fails. Already-applied files are skipped. Safe to run more than once — every statement in these files is guarded (IF NOT EXISTS / DROP...IF EXISTS), so re-running an already-applied file is a no-op rather than an error.</p>
    <form method="POST" onsubmit="return confirm('This will run schema-changing SQL against the main database. Continue?');">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlV($csrfToken); ?>">
        <input type="hidden" name="action" value="apply">
        <button type="submit" class="primary">Apply pending migrations</button>
    </form>
</div>

<p style="font-size:12px;color:#888;">This page only runs the files named above, never arbitrary SQL. It does not touch 2026_08_30_message_cards_unique_user_id.sql (unrelated, predates this work).</p>

</body>
</html>
