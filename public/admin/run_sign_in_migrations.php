<?php
declare(strict_types=1);

/**
 * platform-admin/run_sign_in_migrations.php
 *
 * Browser-based front end for the two schema changes behind email sign-up
 * and verified-email sign-in, for deploys without a shell (Railway) — same
 * rationale and auth pattern as run_swap_identity_v2_migrations.php:
 *
 *   1. database/migrations/2026_09_27_email_sign_in.sql            (main DB)
 *      users.phone optional, users.email_verified_at + backfill, one
 *      account per verified email.
 *   2. scripts/credentials_db/2026_09_27_user_login_lockout.sql    (credentials DB)
 *      user_credentials.failed_login_attempts / locked_until.
 *
 * Its own page rather than more rows on the swap-to-identity one: that
 * page is deliberately scoped to its own migrations, and this one needs
 * the credentials database connection too.
 *
 * Both files are idempotent, each wrapped in its own BEGIN/COMMIT. The
 * code that uses them ships in the same deploy and works before they are
 * applied (Domain\Identity\SignInSchema) — but email sign-up fails until
 * step 1 has run, so click Apply straight after the deploy.
 *
 * Gated behind requirePlatformConfigAuth(), since it writes schema.
 * Safe to delete once both are applied everywhere.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Database/CredentialsDBConnection.php';
require_once __DIR__ . '/auth.php';

use Core\Database\DBConnection;
use Core\Database\CredentialsDBConnection;
use Domain\Identity\SignInSchema;

$admin = requirePlatformConfigAuth();

const SIGN_IN_MIGRATIONS = [
    [
        'key' => 'email_sign_in',
        'db' => 'main',
        'label' => 'database/migrations/2026_09_27_email_sign_in.sql (main database)',
        'path' => __DIR__ . '/../../database/migrations/2026_09_27_email_sign_in.sql',
    ],
    [
        'key' => 'user_login_lockout',
        'db' => 'credentials',
        'label' => 'scripts/credentials_db/2026_09_27_user_login_lockout.sql (credentials database)',
        'path' => __DIR__ . '/../../scripts/credentials_db/2026_09_27_user_login_lockout.sql',
    ],
];

function safeHtmlS($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Introspects the live schema rather than keeping a ledger, like the
 * other runner pages. null = that database could not be reached.
 *
 * @return array<string, ?bool>
 */
function checkSignInMigrationStatus(?PDO $mainDb, ?PDO $credDb): array {
    SignInSchema::forget();
    $status = ['email_sign_in' => null, 'user_login_lockout' => null];

    if ($mainDb) {
        $phoneNullable = $mainDb->query("
            SELECT is_nullable FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'phone'
        ")->fetchColumn();
        $indexExists = (bool)$mainDb->query("
            SELECT 1 FROM pg_indexes
            WHERE schemaname = current_schema() AND indexname = 'idx_users_verified_email'
        ")->fetchColumn();
        $status['email_sign_in'] = SignInSchema::hasEmailVerifiedAt($mainDb)
            && $phoneNullable === 'YES'
            && $indexExists;
    }
    if ($credDb) {
        $status['user_login_lockout'] = SignInSchema::hasUserLoginLockout($credDb);
    }

    return $status;
}

$output = [];
$error = null;
$actionRun = null;
$statusBefore = null;
$statusAfter = null;
$mainDb = null;
$credDb = null;

try {
    $mainDb = DBConnection::getConnection();
    $credDb = CredentialsDBConnection::getConnection();
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    $actionRun = $action;

    try {
        if (!$mainDb) {
            throw new RuntimeException("Could not connect to the main database (DATABASE_URL).");
        }
        if (!$credDb) {
            throw new RuntimeException("Could not connect to the credentials database (CREDENTIALS_DATABASE_URL).");
        }
        $mainStatus = DBConnection::getStatus();
        $credStatus = CredentialsDBConnection::getStatus();
        $output[] = "Main:        {$mainStatus['host']}/{$mainStatus['database']}";
        $output[] = "Credentials: {$credStatus['host']}/{$credStatus['database']}";
        $output[] = "";

        $statusBefore = checkSignInMigrationStatus($mainDb, $credDb);

        if ($action === 'status') {
            $output[] = "Status check only — nothing was run.";
        } elseif ($action === 'apply') {
            foreach (SIGN_IN_MIGRATIONS as $migration) {
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

                $db = $migration['db'] === 'main' ? $mainDb : $credDb;
                $output[] = "RUN   {$migration['label']} ...";
                try {
                    $db->exec($sql);
                    $output[] = "OK    {$migration['label']} applied.";
                } catch (\Throwable $e) {
                    $output[] = "FAIL  {$migration['label']}: " . $e->getMessage();
                    throw new RuntimeException(
                        "Stopped after {$migration['label']} failed. Its own BEGIN/COMMIT rolled it back; anything above marked OK is committed.",
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

        $statusAfter = checkSignInMigrationStatus($mainDb, $credDb);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
} elseif ($error === null) {
    try {
        $statusBefore = checkSignInMigrationStatus($mainDb, $credDb);
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
<title>Sign-in migrations</title>
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

<h1>Email sign-up and sign-in — pending migrations</h1>
<p>Logged in as <?php echo safeHtmlS($admin['username'] ?? $admin['email'] ?? 'admin'); ?>.</p>

<?php if ($error): ?>
<div class="error">Error: <?php echo safeHtmlS($error); ?></div>
<?php endif; ?>

<?php if ($displayStatus !== null): ?>
<table>
<tr><th>Migration</th><th>Status</th></tr>
<?php foreach (SIGN_IN_MIGRATIONS as $migration): $state = $displayStatus[$migration['key']] ?? null; ?>
<tr>
    <td><?php echo safeHtmlS($migration['label']); ?></td>
    <td class="<?php echo $state ? 'applied' : 'pending'; ?>"><?php echo $state === null ? 'Database not reachable' : ($state ? 'Applied' : 'Pending'); ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($actionRun && !$error): ?>
<pre><?php echo safeHtmlS(implode("\n", $output)); ?></pre>
<?php endif; ?>

<div class="step">
    <h2>Check status</h2>
    <p>Read-only — looks at information_schema / pg_catalog to see whether each change is already there. Changes nothing.</p>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlS($csrfToken); ?>">
        <input type="hidden" name="action" value="status">
        <button type="submit">Check status</button>
    </form>
</div>

<div class="step">
    <h2>Apply pending migrations</h2>
    <p>Runs whichever of the two files are not yet applied, main database first, stopping if one fails. Safe to run more than once. Until the first one is applied, email sign-up fails and email sign-in cannot tell verified addresses from unverified ones.</p>
    <form method="POST" onsubmit="return confirm('This will run schema-changing SQL against the main and credentials databases. Continue?');">
        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlS($csrfToken); ?>">
        <input type="hidden" name="action" value="apply">
        <button type="submit" class="primary">Apply pending migrations</button>
    </form>
</div>

<p style="font-size:12px;color:#888;">This page only runs the two files named above, never arbitrary SQL.</p>

</body>
</html>
