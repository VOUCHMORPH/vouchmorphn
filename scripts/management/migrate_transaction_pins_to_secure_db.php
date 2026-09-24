<?php
declare(strict_types=1);

/**
 * One-time migration: copy users' transaction PINs (transaction_pin_hash,
 * plus the attempts/lock/set-at columns that go with it) from the main
 * database's `users` table into the credentials database's
 * user_transaction_pins table.
 *
 * Phase 1 of moving transaction PINs out of the main database (see
 * docs/security/credentials-isolation.md): a non-destructive COPY. It never
 * modifies or deletes anything in the main database. Dropping the source
 * columns is a separate, explicitly manual step
 * (scripts/credentials_db/phase2_drop_transaction_pin_columns.sql) — do not
 * run that until this script's --verify output is clean AND the app has
 * been running on the credentials DB in production for a while.
 *
 * Separate from migrate_credentials_to_secure_db.php on purpose: re-running
 * that password copy after its cutover would overwrite passwords changed
 * since. This script touches transaction PINs only.
 *
 * The actual migration logic lives in
 * Infrastructure\Credentials\TransactionPinMigrationRunner, shared with the
 * browser-based trigger (public/admin/run_transaction_pin_migration.php)
 * for environments without shell access — this file is just the CLI front
 * end.
 *
 * Usage:
 *   php scripts/management/migrate_transaction_pins_to_secure_db.php
 *       Dry run (default). Prints how many rows would be migrated. Writes
 *       nothing.
 *
 *   php scripts/management/migrate_transaction_pins_to_secure_db.php --apply
 *       Performs the copy. Idempotent, and safe to re-run even after the
 *       new code is deployed: it only ever overwrites rows it copied
 *       itself and the app hasn't changed since.
 *
 *   php scripts/management/migrate_transaction_pins_to_secure_db.php --verify
 *       Checks every user with a PIN in the main database has an
 *       up-to-date row in the credentials database. Run this after --apply.
 *
 *   --batch-size=N   Rows per batch (default 500).
 *
 * Requires both DATABASE_URL (source) and CREDENTIALS_DATABASE_URL
 * (destination) to be set. Refuses to run if either is missing, or if they
 * resolve to the same host+dbname (that would defeat the entire point of
 * the migration).
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/src/Core/Database/DBConnection.php';
require_once dirname(__DIR__, 2) . '/src/Core/Database/CredentialsDBConnection.php';
require_once dirname(__DIR__, 2) . '/src/Infrastructure/Credentials/CredentialsMigrationRunner.php';
require_once dirname(__DIR__, 2) . '/src/Infrastructure/Credentials/TransactionPinMigrationRunner.php';

if (class_exists('Dotenv\Dotenv') && file_exists(dirname(__DIR__, 2) . '/.env')) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
}

use Core\Database\DBConnection;
use Core\Database\CredentialsDBConnection;
use Infrastructure\Credentials\TransactionPinMigrationRunner;

// ============================================================================
// CLI ARGS
// ============================================================================

$options = getopt('', ['apply', 'verify', 'batch-size:']);
$apply = isset($options['apply']);
$verify = isset($options['verify']);
$batchSize = isset($options['batch-size']) ? max(1, (int)$options['batch-size']) : 500;

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

if ($verify) {
    $ok = TransactionPinMigrationRunner::verify($mainDb, $credDb, $batchSize, $out);
    exit($ok ? 0 : 1);
}

TransactionPinMigrationRunner::migrate($mainDb, $credDb, $apply, $batchSize, $out);
$out("");

if (!$apply) {
    $out("Dry run complete. Nothing was written. Re-run with --apply to perform the copy,");
    $out("then with --verify to confirm it landed correctly.");
}
