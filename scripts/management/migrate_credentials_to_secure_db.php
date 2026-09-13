<?php
declare(strict_types=1);

/**
 * One-time migration: copy login secrets (username, email, password_hash)
 * from the main database's `users` and `admins` tables into the separate
 * credentials database.
 *
 * This is phase 1 of the credentials-isolation change (see
 * docs/security/credentials-isolation.md): a non-destructive COPY. It
 * never modifies or deletes anything in the main database — the source
 * columns are left exactly as they are so the app keeps working
 * unmodified until the code changes in this same change set are deployed
 * and verified. Dropping the source columns is a separate, explicitly
 * manual step (scripts/credentials_db/phase2_drop_columns.sql) — do not
 * run that until this script's --verify output is clean AND the app has
 * been running on the credentials DB in production for a while.
 *
 * The actual migration logic lives in
 * Infrastructure\Credentials\CredentialsMigrationRunner, shared with the
 * browser-based trigger (public/admin/run_credentials_migration.php) for
 * environments without shell access — this file is just the CLI front end.
 *
 * Usage:
 *   php scripts/management/migrate_credentials_to_secure_db.php
 *       Dry run (default). Prints how many rows would be migrated and
 *       shows a few examples (hashes redacted). Writes nothing.
 *
 *   php scripts/management/migrate_credentials_to_secure_db.php --apply
 *       Performs the copy. Idempotent — upserts on the primary key, so
 *       it can be re-run safely (e.g. to pick up accounts created after
 *       the first run, right up until code is cut over to the new DB).
 *
 *   php scripts/management/migrate_credentials_to_secure_db.php --verify
 *       Compares row counts and spot-checks a sample of password hashes
 *       between the two databases. Run this after --apply.
 *
 *   --table=users|admins|transaction_pins   Restrict to one table (default: all).
 *   --batch-size=N         Rows per batch (default 500).
 *
 * Requires both DATABASE_URL (source) and CREDENTIALS_DATABASE_URL
 * (destination) to be set. Refuses to run if either is missing, or if
 * they resolve to the same host+dbname (that would defeat the entire
 * point of the migration).
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/src/Core/Database/DBConnection.php';
require_once dirname(__DIR__, 2) . '/src/Core/Database/CredentialsDBConnection.php';
require_once dirname(__DIR__, 2) . '/src/Infrastructure/Credentials/CredentialsMigrationRunner.php';

if (class_exists('Dotenv\Dotenv') && file_exists(dirname(__DIR__, 2) . '/.env')) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
}

use Core\Database\DBConnection;
use Core\Database\CredentialsDBConnection;
use Infrastructure\Credentials\CredentialsMigrationRunner;

// ============================================================================
// CLI ARGS
// ============================================================================

$options = getopt('', ['apply', 'verify', 'table:', 'batch-size:']);
$apply = isset($options['apply']);
$verify = isset($options['verify']);
$tableFilter = $options['table'] ?? 'all';
$batchSize = isset($options['batch-size']) ? max(1, (int)$options['batch-size']) : 500;

if (!in_array($tableFilter, ['all', 'users', 'admins', 'transaction_pins'], true)) {
    fwrite(STDERR, "Invalid --table value. Use 'users', 'admins', 'transaction_pins', or omit for all.\n");
    exit(1);
}

$out = function (string $line): void {
    fwrite(STDOUT, $line . "\n");
};

// ============================================================================
// CONNECT
// ============================================================================

$mainDb = DBConnection::getConnection();
if (!$mainDb) {
    fwrite(STDERR, "Could not connect to the main database (DATABASE_URL). Aborting.\n");
    exit(1);
}

$credDb = CredentialsDBConnection::getConnection();
if (!$credDb) {
    fwrite(STDERR, "Could not connect to the credentials database (CREDENTIALS_DATABASE_URL). Aborting.\n");
    exit(1);
}

// Refuse to run if both URLs resolve to the same place — that would
// silently no-op the isolation this migration exists to create.
$mainStatus = DBConnection::getStatus();
$credStatus = CredentialsDBConnection::getStatus();
if (
    ($mainStatus['host'] ?? null) === ($credStatus['host'] ?? null) &&
    ($mainStatus['database'] ?? null) === ($credStatus['database'] ?? null)
) {
    fwrite(STDERR,
        "DATABASE_URL and CREDENTIALS_DATABASE_URL point at the same database " .
        "({$mainStatus['host']}/{$mainStatus['database']}). Refusing to run — set " .
        "CREDENTIALS_DATABASE_URL to a genuinely separate database first.\n"
    );
    exit(1);
}

$out("Source (main):        {$mainStatus['host']}/{$mainStatus['database']}");
$out("Destination (creds):  {$credStatus['host']}/{$credStatus['database']}");
$out("Mode: " . ($verify ? "VERIFY" : ($apply ? "APPLY" : "DRY RUN (pass --apply to write)")));
$out("");

// ============================================================================
// RUN
// ============================================================================

$allOk = true;

if ($tableFilter === 'all' || $tableFilter === 'users') {
    if ($verify) {
        $allOk = CredentialsMigrationRunner::verifyTable($mainDb, $credDb, 'users', 'users', 'user_id', 'user_credentials', 'user_id', $out) && $allOk;
    } else {
        CredentialsMigrationRunner::migrateUsers($mainDb, $credDb, $apply, $batchSize, $out);
    }
    $out("");
}

if ($tableFilter === 'all' || $tableFilter === 'admins') {
    if ($verify) {
        $allOk = CredentialsMigrationRunner::verifyTable($mainDb, $credDb, 'admins', 'admins', 'admin_id', 'admin_credentials', 'admin_id', $out) && $allOk;
    } else {
        CredentialsMigrationRunner::migrateAdmins($mainDb, $credDb, $apply, $batchSize, $out);
    }
    $out("");
}

if ($tableFilter === 'all' || $tableFilter === 'transaction_pins') {
    if ($verify) {
        $allOk = CredentialsMigrationRunner::verifyTransactionPins($mainDb, $credDb, $out) && $allOk;
    } else {
        CredentialsMigrationRunner::migrateTransactionPins($mainDb, $credDb, $apply, $batchSize, $out);
    }
    $out("");
}

if (!$apply && !$verify) {
    $out("Dry run complete. Nothing was written. Re-run with --apply to perform the copy,");
    $out("then with --verify to confirm it landed correctly.");
}

// Deliberately NOT migrating organization_users.password_hash: the live
// enterprise login (public/admin/enterprise/login.php) authenticates
// against users.password_hash via the organization_users -> users join
// and never reads organization_users.password_hash — it's a write-only
// duplicate (see UserManagementService::resetPassword(), which updates
// only organization_users, and organizations/create.php, which writes
// the same hash to both tables). The code changes in this branch stop
// writing to that column; scripts/credentials_db/phase2_drop_columns.sql
// drops it once the cutover is verified.
if ($verify) {
    exit($allOk ? 0 : 1);
}
