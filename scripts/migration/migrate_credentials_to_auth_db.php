<?php
/**
 * scripts/migration/migrate_credentials_to_auth_db.php
 *
 * One-off backfill: copies existing username/password_hash (and, for
 * admins, mfa_enabled/mfa_secret/failed_login_attempts/locked_until)
 * from the main database (`users`, `admins`) into the isolated
 * credentials database (`user_credentials`, `admin_credentials` in
 * AUTH_DATABASE_URL).
 *
 * Idempotent — safe to re-run (upserts via ON CONFLICT). Run this AFTER
 * applying src/Core/Database/auth_schema.sql to the auth database and
 * BEFORE dropping the credential columns from the main database.
 *
 * Usage: php scripts/migration/migrate_credentials_to_auth_db.php
 */

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__, 2));

require_once PROJECT_ROOT . '/vendor/autoload.php';
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Core/Database/AuthDBConnection.php';

use Core\Database\DBConnection;
use Core\Database\AuthDBConnection;

if (class_exists('Dotenv\Dotenv') && file_exists(PROJECT_ROOT . '/.env')) {
    \Dotenv\Dotenv::createImmutable(PROJECT_ROOT)->load();
}

function fail(string $message): never
{
    fwrite(STDERR, "[migrate_credentials] ERROR: {$message}\n");
    exit(1);
}

$mainDb = DBConnection::getConnection();
if (!$mainDb) {
    fail('Could not connect to the main database (DATABASE_URL).');
}

$authDb = AuthDBConnection::getConnection();
if (!$authDb) {
    fail('Could not connect to the auth database (AUTH_DATABASE_URL). Provision it and apply auth_schema.sql first.');
}

echo "[migrate_credentials] Connected to both databases.\n";

// ============================================================
// users -> user_credentials
// ============================================================
$userCount = 0;
$stmt = $mainDb->query("SELECT user_id, username, password_hash FROM users WHERE username IS NOT NULL AND password_hash IS NOT NULL");
$upsertUser = $authDb->prepare("
    INSERT INTO user_credentials (user_id, username, password_hash, created_at, updated_at)
    VALUES (:user_id, :username, :hash, NOW(), NOW())
    ON CONFLICT (user_id) DO UPDATE SET
        username = EXCLUDED.username,
        password_hash = EXCLUDED.password_hash,
        updated_at = NOW()
");

foreach ($stmt as $row) {
    $upsertUser->execute([
        ':user_id' => $row['user_id'],
        ':username' => $row['username'],
        ':hash' => $row['password_hash'],
    ]);
    $userCount++;
}
echo "[migrate_credentials] Migrated {$userCount} user credential row(s).\n";

// ============================================================
// admins -> admin_credentials
// ============================================================
$adminCount = 0;
$stmt = $mainDb->query("
    SELECT admin_id, username, password_hash, mfa_enabled, mfa_secret,
           failed_login_attempts, locked_until
    FROM admins
    WHERE username IS NOT NULL AND password_hash IS NOT NULL
");
$upsertAdmin = $authDb->prepare("
    INSERT INTO admin_credentials (
        admin_id, username, password_hash, mfa_enabled, mfa_secret,
        failed_login_attempts, locked_until, created_at, updated_at
    ) VALUES (
        :admin_id, :username, :hash, :mfa_enabled, :mfa_secret,
        :failed_attempts, :locked_until, NOW(), NOW()
    )
    ON CONFLICT (admin_id) DO UPDATE SET
        username = EXCLUDED.username,
        password_hash = EXCLUDED.password_hash,
        mfa_enabled = EXCLUDED.mfa_enabled,
        mfa_secret = EXCLUDED.mfa_secret,
        failed_login_attempts = EXCLUDED.failed_login_attempts,
        locked_until = EXCLUDED.locked_until,
        updated_at = NOW()
");

foreach ($stmt as $row) {
    $upsertAdmin->execute([
        ':admin_id' => $row['admin_id'],
        ':username' => $row['username'],
        ':hash' => $row['password_hash'],
        ':mfa_enabled' => $row['mfa_enabled'] ? 't' : 'f',
        ':mfa_secret' => $row['mfa_secret'],
        ':failed_attempts' => $row['failed_login_attempts'] ?? 0,
        ':locked_until' => $row['locked_until'],
    ]);
    $adminCount++;
}
echo "[migrate_credentials] Migrated {$adminCount} admin credential row(s).\n";

// ============================================================
// Verification: row counts must match on both sides
// ============================================================
$sourceUserCount = (int)$mainDb->query("SELECT COUNT(*) FROM users WHERE username IS NOT NULL AND password_hash IS NOT NULL")->fetchColumn();
$destUserCount = (int)$authDb->query("SELECT COUNT(*) FROM user_credentials")->fetchColumn();

$sourceAdminCount = (int)$mainDb->query("SELECT COUNT(*) FROM admins WHERE username IS NOT NULL AND password_hash IS NOT NULL")->fetchColumn();
$destAdminCount = (int)$authDb->query("SELECT COUNT(*) FROM admin_credentials")->fetchColumn();

echo "\n[migrate_credentials] Verification:\n";
echo "  users:  source={$sourceUserCount} auth_db={$destUserCount}\n";
echo "  admins: source={$sourceAdminCount} auth_db={$destAdminCount}\n";

if ($destUserCount < $sourceUserCount || $destAdminCount < $sourceAdminCount) {
    fail('Row count mismatch after migration — auth DB has fewer credential rows than the source. Do not drop columns from the main DB yet.');
}

echo "\n[migrate_credentials] OK — auth DB has at least as many credential rows as the source for both tables.\n";
echo "[migrate_credentials] Next: switch the application to CredentialsRepository (already done if you're running this after the code changes), verify login manually, then drop the credential columns from the main DB.\n";
