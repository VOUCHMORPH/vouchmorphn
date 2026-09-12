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
 *   --table=users|admins   Restrict to one table (default: both).
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

if (class_exists('Dotenv\Dotenv') && file_exists(dirname(__DIR__, 2) . '/.env')) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
}

use Core\Database\DBConnection;
use Core\Database\CredentialsDBConnection;

// ============================================================================
// CLI ARGS
// ============================================================================

$options = getopt('', ['apply', 'verify', 'table:', 'batch-size:']);
$apply = isset($options['apply']);
$verify = isset($options['verify']);
$tableFilter = $options['table'] ?? 'all';
$batchSize = isset($options['batch-size']) ? max(1, (int)$options['batch-size']) : 500;

if (!in_array($tableFilter, ['all', 'users', 'admins'], true)) {
    fwrite(STDERR, "Invalid --table value. Use 'users', 'admins', or omit for both.\n");
    exit(1);
}

function out(string $line): void {
    fwrite(STDOUT, $line . "\n");
}

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

out("Source (main):        {$mainStatus['host']}/{$mainStatus['database']}");
out("Destination (creds):  {$credStatus['host']}/{$credStatus['database']}");
out("Mode: " . ($verify ? "VERIFY" : ($apply ? "APPLY" : "DRY RUN (pass --apply to write)")));
out("");

/**
 * True if $table has a column named $column, on the given connection.
 * The checked-in SQL migration files in this repo have drifted from the
 * live schema (organization_users, in particular, has extra columns in
 * production that aren't in any committed .sql file), so this script
 * introspects the real schema rather than assuming column lists.
 */
function hasColumn(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.columns WHERE table_name = :t AND column_name = :c"
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return $cache[$key] = (bool)$stmt->fetchColumn();
}

// ============================================================================
// MIGRATE users -> user_credentials  (id, password_hash)
// ============================================================================

function migrateUsers(PDO $mainDb, PDO $credDb, bool $apply, int $batchSize): array
{
    $label = 'users';
    $total = (int)$mainDb->query("SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL")->fetchColumn();
    out("[{$label}] {$total} row(s) in users with a password_hash to migrate.");
    if ($total === 0) {
        return ['total' => 0, 'migrated' => 0];
    }

    $migrated = 0;
    $lastId = 0;
    $upsertStmt = $credDb->prepare("
        INSERT INTO user_credentials (user_id, password_hash, created_at, updated_at)
        VALUES (:id, :hash, NOW(), NOW())
        ON CONFLICT (user_id) DO UPDATE SET password_hash = EXCLUDED.password_hash, updated_at = NOW()
    ");

    while (true) {
        $stmt = $mainDb->prepare("
            SELECT user_id AS id, password_hash
            FROM users
            WHERE user_id > :lastId AND password_hash IS NOT NULL
            ORDER BY user_id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            break;
        }

        if ($apply) {
            $credDb->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $upsertStmt->execute([':id' => $row['id'], ':hash' => $row['password_hash']]);
                }
                $credDb->commit();
            } catch (\Throwable $e) {
                $credDb->rollBack();
                fwrite(STDERR, "[{$label}] Batch starting after id {$lastId} failed: " . $e->getMessage() . "\n");
                throw $e;
            }
        }

        $migrated += count($rows);
        $lastId = (int)end($rows)['id'];
        out("[{$label}] " . ($apply ? "Migrated" : "Would migrate") . " {$migrated}/{$total} (through id {$lastId})...");
    }

    return ['total' => $total, 'migrated' => $migrated];
}

// ============================================================================
// MIGRATE admins -> admin_credentials  (id, password_hash, lockout state)
// ============================================================================

function migrateAdmins(PDO $mainDb, PDO $credDb, bool $apply, int $batchSize): array
{
    $label = 'admins';
    $hasLockoutCols = hasColumn($mainDb, 'admins', 'failed_login_attempts') && hasColumn($mainDb, 'admins', 'locked_until');
    $attemptsSelect = $hasLockoutCols ? 'failed_login_attempts' : '0 AS failed_login_attempts';
    $lockedSelect = $hasLockoutCols ? 'locked_until' : 'NULL AS locked_until';

    $total = (int)$mainDb->query("SELECT COUNT(*) FROM admins WHERE password_hash IS NOT NULL")->fetchColumn();
    out("[{$label}] {$total} row(s) in admins with a password_hash to migrate.");
    if ($total === 0) {
        return ['total' => 0, 'migrated' => 0];
    }

    $migrated = 0;
    $lastId = 0;
    $upsertStmt = $credDb->prepare("
        INSERT INTO admin_credentials (admin_id, password_hash, failed_login_attempts, locked_until, created_at, updated_at)
        VALUES (:id, :hash, :attempts, :locked, NOW(), NOW())
        ON CONFLICT (admin_id) DO UPDATE
            SET password_hash = EXCLUDED.password_hash,
                failed_login_attempts = EXCLUDED.failed_login_attempts,
                locked_until = EXCLUDED.locked_until,
                updated_at = NOW()
    ");

    while (true) {
        $stmt = $mainDb->prepare("
            SELECT admin_id AS id, password_hash, {$attemptsSelect}, {$lockedSelect}
            FROM admins
            WHERE admin_id > :lastId AND password_hash IS NOT NULL
            ORDER BY admin_id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            break;
        }

        if ($apply) {
            $credDb->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $upsertStmt->execute([
                        ':id' => $row['id'],
                        ':hash' => $row['password_hash'],
                        ':attempts' => $row['failed_login_attempts'] ?? 0,
                        ':locked' => $row['locked_until'],
                    ]);
                }
                $credDb->commit();
            } catch (\Throwable $e) {
                $credDb->rollBack();
                fwrite(STDERR, "[{$label}] Batch starting after id {$lastId} failed: " . $e->getMessage() . "\n");
                throw $e;
            }
        }

        $migrated += count($rows);
        $lastId = (int)end($rows)['id'];
        out("[{$label}] " . ($apply ? "Migrated" : "Would migrate") . " {$migrated}/{$total} (through id {$lastId})...");
    }

    return ['total' => $total, 'migrated' => $migrated];
}

// ============================================================================
// VERIFY ONE TABLE
// ============================================================================

function verifyTable(
    PDO $mainDb,
    PDO $credDb,
    string $label,
    string $sourceTable,
    string $idColumn,
    string $destTable,
    string $destIdColumn
): bool {
    $sourceCount = (int)$mainDb->query("SELECT COUNT(*) FROM {$sourceTable} WHERE password_hash IS NOT NULL")->fetchColumn();
    $destCount = (int)$credDb->query("SELECT COUNT(*) FROM {$destTable}")->fetchColumn();

    out("[{$label}] source rows with a password: {$sourceCount} | credentials DB rows: {$destCount}");

    if ($sourceCount !== $destCount) {
        out("[{$label}] MISMATCH — re-run with --apply to reconcile.");
        return false;
    }

    // Spot-check a sample of hashes actually match byte-for-byte.
    $sample = $mainDb->query("
        SELECT {$idColumn} AS id, password_hash FROM {$sourceTable}
        WHERE password_hash IS NOT NULL ORDER BY random() LIMIT 25
    ")->fetchAll(PDO::FETCH_ASSOC);

    $mismatches = 0;
    $checkStmt = $credDb->prepare("SELECT password_hash FROM {$destTable} WHERE {$destIdColumn} = :id");
    foreach ($sample as $row) {
        $checkStmt->execute([':id' => $row['id']]);
        $destRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$destRow || $destRow['password_hash'] !== $row['password_hash']) {
            $mismatches++;
            out("[{$label}] Spot-check mismatch for id={$row['id']}");
        }
    }

    if ($mismatches > 0) {
        out("[{$label}] {$mismatches}/" . count($sample) . " spot-checked rows differ.");
        return false;
    }

    out("[{$label}] OK — counts match and " . count($sample) . " spot-checked rows are identical.");
    return true;
}

// ============================================================================
// RUN
// ============================================================================

$allOk = true;

if ($tableFilter === 'all' || $tableFilter === 'users') {
    if ($verify) {
        $allOk = verifyTable($mainDb, $credDb, 'users', 'users', 'user_id', 'user_credentials', 'user_id') && $allOk;
    } else {
        migrateUsers($mainDb, $credDb, $apply, $batchSize);
    }
    out("");
}

if ($tableFilter === 'all' || $tableFilter === 'admins') {
    if ($verify) {
        $allOk = verifyTable($mainDb, $credDb, 'admins', 'admins', 'admin_id', 'admin_credentials', 'admin_id') && $allOk;
    } else {
        migrateAdmins($mainDb, $credDb, $apply, $batchSize);
    }
    out("");
}

if (!$apply && !$verify) {
    out("Dry run complete. Nothing was written. Re-run with --apply to perform the copy,");
    out("then with --verify to confirm it landed correctly.");
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
