<?php
declare(strict_types=1);

/**
 * platform-admin/run_transaction_pin_migration.php
 *
 * Browser-based front end for the one-time users.transaction_pin_* ->
 * credentials-DB migration (see docs/security/credentials-isolation.md),
 * for environments where a shell isn't available (e.g. some Railway
 * plans). Drives the exact same logic as
 * scripts/management/migrate_transaction_pins_to_secure_db.php — both call
 * Infrastructure\Credentials\TransactionPinMigrationRunner, so there is
 * only one implementation of the migration to trust.
 *
 * Its own page rather than more buttons on run_credentials_migration.php:
 * that page's Apply re-copies passwords, which after its cutover would put
 * stale hashes back over passwords changed since. Nothing here touches a
 * password, and this page's Apply is safe to click again after the new
 * code is deployed (it never overwrites a row the app has changed).
 *
 * Gated behind requirePlatformConfigAuth() — the same guard
 * run_credentials_migration.php uses — since this writes to the
 * credentials database. Only ever COPIES data (dry run and apply); the
 * destructive phase-2 column drop
 * (scripts/credentials_db/phase2_drop_transaction_pin_columns.sql) stays a
 * deliberate, manual SQL statement, not a click away in a browser.
 *
 * Safe to delete once the migration is done and verified — it has no
 * other purpose.
 */

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Database/CredentialsDBConnection.php';
require_once __DIR__ . '/../../src/Infrastructure/Credentials/CredentialsMigrationRunner.php';
require_once __DIR__ . '/../../src/Infrastructure/Credentials/TransactionPinMigrationRunner.php';
require_once __DIR__ . '/auth.php';

use Core\Database\DBConnection;
use Core\Database\CredentialsDBConnection;
use Infrastructure\Credentials\TransactionPinMigrationRunner;

$admin = requirePlatformConfigAuth();

$output = [];
$error = null;
$actionRun = null;

function safeHtmlT($value): string {
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
            TransactionPinMigrationRunner::migrate($mainDb, $credDb, $apply, 500, $out);
            $out("");
            $out($apply ? "Apply complete." : "Dry run complete — nothing was written.");
        } elseif ($action === 'verify') {
            $ok = TransactionPinMigrationRunner::verify($mainDb, $credDb, 500, $out);
            $out("");
            $out($ok ? "VERIFY PASSED." : "VERIFY FAILED — see above.");
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
<title>Transaction PIN migration</title>
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
</style>
</head>
<body>

<h1>Transaction PIN migration</h1>
<p>Logged in as <?php echo safeHtmlT($admin['username'] ?? $admin['email'] ?? 'admin'); ?>. Run these in order: Dry run first, then Apply, then Verify.</p>

<?php if ($error): ?>
<div class="error">Error: <?php echo safeHtmlT($error); ?></div>
<?php endif; ?>

<?php if ($actionRun && !$error): ?>
<pre><?php echo safeHtmlT(implode("\n", $output)); ?></pre>
<?php endif; ?>

<div class="step">
    <h2>1. Dry run</h2>
    <p>Shows how many users' transaction PINs would be copied. Writes nothing.</p>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlT($csrfToken); ?>">
        <input type="hidden" name="action" value="dry_run">
        <button type="submit">Run dry run</button>
    </form>
</div>

<div class="step">
    <h2>2. Apply</h2>
    <p>Copies transaction PIN hashes (and their failed-attempt / lockout state) into the credentials database. Safe to run more than once, including after the new code is deployed — it never overwrites a PIN the app has changed since.</p>
    <form method="POST" onsubmit="return confirm('This will copy real PIN data into the credentials database. Continue?');">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlT($csrfToken); ?>">
        <input type="hidden" name="action" value="apply">
        <button type="submit" class="danger">Apply migration</button>
    </form>
</div>

<div class="step">
    <h2>3. Verify</h2>
    <p>Checks that every user with a PIN in the main database has an up-to-date copy in the credentials database.</p>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlT($csrfToken); ?>">
        <input type="hidden" name="action" value="verify">
        <button type="submit" class="primary">Run verify</button>
    </form>
</div>

<p style="font-size:12px;color:#888;">This page only copies data — it never deletes anything from the main database. The separate, irreversible cleanup step (dropping the old transaction_pin_* columns) is intentionally not here; it's a manual SQL step run later, once you've confirmed real PIN claims work. See docs/security/credentials-isolation.md.</p>

</body>
</html>
