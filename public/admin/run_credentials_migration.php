<?php
declare(strict_types=1);

/**
 * platform-admin/run_credentials_migration.php
 *
 * Browser-based front end for the one-time users/admins -> credentials-DB
 * migration (see docs/security/credentials-isolation.md), for
 * environments where a shell isn't available (e.g. some Railway plans).
 * Drives the exact same logic as
 * scripts/management/migrate_credentials_to_secure_db.php — both call
 * Infrastructure\Credentials\CredentialsMigrationRunner, so there is
 * only one implementation of the migration to trust.
 *
 * Gated behind requirePlatformConfigAuth() — the same guard
 * organizations/create.php uses — since this can write to the
 * credentials database. Only ever COPIES data (dry run and apply); it
 * deliberately does not expose the destructive phase-2 column drop
 * (scripts/credentials_db/phase2_drop_columns.sql) as a button — that
 * step stays a deliberate, manual SQL statement run directly against the
 * database, not a click away in a browser.
 *
 * Safe to delete once the migration is done and verified — it has no
 * other purpose.
 */

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Database/CredentialsDBConnection.php';
require_once __DIR__ . '/../../src/Infrastructure/Credentials/CredentialsMigrationRunner.php';
require_once __DIR__ . '/auth.php';

use Core\Database\DBConnection;
use Core\Database\CredentialsDBConnection;
use Infrastructure\Credentials\CredentialsMigrationRunner;

$admin = requirePlatformConfigAuth();

$output = [];
$error = null;
$actionRun = null;

function safeHtmlM($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    $actionRun = $action;

    $out = function (string $line) use (&$output): void {
        $output[] = $line;
    };

    try {
        $mainDb = DBConnection::getConnection();
        if (!$mainDb) {
            throw new RuntimeException("Could not connect to the main database (DATABASE_URL).");
        }
        $credDb = CredentialsDBConnection::getConnection();
        if (!$credDb) {
            throw new RuntimeException("Could not connect to the credentials database (CREDENTIALS_DATABASE_URL). Make sure that env var is set on this service.");
        }

        $mainStatus = DBConnection::getStatus();
        $credStatus = CredentialsDBConnection::getStatus();
        if (
            ($mainStatus['host'] ?? null) === ($credStatus['host'] ?? null) &&
            ($mainStatus['database'] ?? null) === ($credStatus['database'] ?? null)
        ) {
            throw new RuntimeException(
                "DATABASE_URL and CREDENTIALS_DATABASE_URL point at the same database " .
                "({$mainStatus['host']}/{$mainStatus['database']}) — refusing to run."
            );
        }

        $out("Source (main):       {$mainStatus['host']}/{$mainStatus['database']}");
        $out("Destination (creds): {$credStatus['host']}/{$credStatus['database']}");
        $out("");

        if ($action === 'dry_run' || $action === 'apply') {
            $apply = ($action === 'apply');
            CredentialsMigrationRunner::migrateUsers($mainDb, $credDb, $apply, 500, $out);
            $out("");
            CredentialsMigrationRunner::migrateAdmins($mainDb, $credDb, $apply, 500, $out);
            $out("");
            CredentialsMigrationRunner::migrateTransactionPins($mainDb, $credDb, $apply, 500, $out);
            $out("");
            $out($apply ? "Apply complete." : "Dry run complete — nothing was written.");
        } elseif ($action === 'verify') {
            $okUsers = CredentialsMigrationRunner::verifyTable($mainDb, $credDb, 'users', 'users', 'user_id', 'user_credentials', 'user_id', $out);
            $out("");
            $okAdmins = CredentialsMigrationRunner::verifyTable($mainDb, $credDb, 'admins', 'admins', 'admin_id', 'admin_credentials', 'admin_id', $out);
            $out("");
            $okPins = CredentialsMigrationRunner::verifyTransactionPins($mainDb, $credDb, $out);
            $out("");
            $out(($okUsers && $okAdmins && $okPins) ? "VERIFY PASSED." : "VERIFY FAILED — see mismatches above.");
        } else {
            throw new RuntimeException("Unknown action.");
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credentials migration</title>
<style>
    body { font-family: -apple-system, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; color: #1a1a1a; }
    h1 { font-size: 20px; }
    .step { border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin-bottom: 16px; }
    .step h2 { font-size: 15px; margin: 0 0 8px; }
    .step p { font-size: 13px; color: #555; margin: 0 0 12px; }
    button { padding: 10px 16px; border-radius: 4px; border: 1px solid #333; background: #f5f5f5; cursor: pointer; font-size: 13px; }
    button.primary { background: #1a1a1a; color: #fff; }
    button.danger { background: #b3261e; color: #fff; border-color: #b3261e; }
    pre { background: #0b1b2b; color: #d3dad6; padding: 16px; border-radius: 6px; overflow-x: auto; font-size: 12px; white-space: pre-wrap; }
    .error { background: #fbeceb; color: #b3261e; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
    .ok { color: #1a7f37; font-weight: 600; }
</style>
</head>
<body>

<h1>Credentials database migration</h1>
<p>Logged in as <?php echo safeHtmlM($admin['username'] ?? $admin['email'] ?? 'admin'); ?>. Run these in order: Dry run first, then Apply, then Verify.</p>

<?php if ($error): ?>
<div class="error">Error: <?php echo safeHtmlM($error); ?></div>
<?php endif; ?>

<?php if ($actionRun && !$error): ?>
<pre><?php echo safeHtmlM(implode("\n", $output)); ?></pre>
<?php endif; ?>

<div class="step">
    <h2>1. Dry run</h2>
    <p>Shows what would be copied. Writes nothing.</p>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlM($csrfToken); ?>">
        <input type="hidden" name="action" value="dry_run">
        <button type="submit">Run dry run</button>
    </form>
</div>

<div class="step">
    <h2>2. Apply</h2>
    <p>Actually copies password hashes into the credentials database. Safe to run more than once (it won't duplicate or lose data) — but confirm the dry run above looks right first.</p>
    <form method="POST" onsubmit="return confirm('This will copy real password data into the credentials database. Continue?');">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlM($csrfToken); ?>">
        <input type="hidden" name="action" value="apply">
        <button type="submit" class="danger">Apply migration</button>
    </form>
</div>

<div class="step">
    <h2>3. Verify</h2>
    <p>Confirms the row counts and a sample of password hashes match between the two databases.</p>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlM($csrfToken); ?>">
        <input type="hidden" name="action" value="verify">
        <button type="submit" class="primary">Run verify</button>
    </form>
</div>

<p style="font-size:12px;color:#888;">This page only copies data — it never deletes anything from the main database. The separate, irreversible cleanup step (dropping the old password columns) is intentionally not here; it's a manual SQL step run later, once you've confirmed real logins work. See docs/security/credentials-isolation.md.</p>

</body>
</html>
