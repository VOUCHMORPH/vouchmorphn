<?php
namespace Core\Database;

require_once __DIR__ . '/AuthDBConnection.php';

use PDO;
use RuntimeException;

/**
 * Single place credential reads/writes go through. Backed by
 * AuthDBConnection (AUTH_DATABASE_URL) — a database separate from the
 * main transactional DBConnection (DATABASE_URL) — so usernames,
 * password hashes, and admin MFA/lockout state never live alongside
 * financial data.
 *
 * Rows are keyed by the id assigned in the main database (users.user_id
 * / admins.admin_id). There is no cross-database foreign key, so
 * callers are responsible for resolving that id (by phone/email/etc.)
 * against the main DB first when the caller only has an identifier.
 */
class CredentialsRepository
{
    private static function db(): PDO
    {
        $db = AuthDBConnection::getConnection();
        if (!$db) {
            throw new RuntimeException('Auth database connection unavailable (AUTH_DATABASE_URL not set or unreachable)');
        }
        return $db;
    }

    private static function pgBool(bool $value): string
    {
        return $value ? 't' : 'f';
    }

    // ================================================================
    // Users
    // ================================================================

    public static function userUsernameExists(string $username): bool
    {
        $stmt = self::db()->prepare("SELECT 1 FROM user_credentials WHERE username = :u");
        $stmt->execute([':u' => $username]);
        return (bool)$stmt->fetchColumn();
    }

    public static function getUserCredentials(int $userId): ?array
    {
        $stmt = self::db()->prepare("SELECT user_id, username, password_hash FROM user_credentials WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function createUserCredentials(int $userId, string $username, string $passwordHash): void
    {
        $stmt = self::db()->prepare("
            INSERT INTO user_credentials (user_id, username, password_hash, created_at, updated_at)
            VALUES (:user_id, :username, :hash, NOW(), NOW())
            ON CONFLICT (user_id) DO UPDATE SET
                username = EXCLUDED.username,
                password_hash = EXCLUDED.password_hash,
                updated_at = NOW()
        ");
        $stmt->execute([':user_id' => $userId, ':username' => $username, ':hash' => $passwordHash]);
    }

    public static function updateUserPassword(int $userId, string $passwordHash): void
    {
        $stmt = self::db()->prepare("UPDATE user_credentials SET password_hash = :hash, updated_at = NOW() WHERE user_id = :id");
        $stmt->execute([':hash' => $passwordHash, ':id' => $userId]);
    }

    // ================================================================
    // Admins
    // ================================================================

    public static function adminUsernameExists(string $username): bool
    {
        $stmt = self::db()->prepare("SELECT 1 FROM admin_credentials WHERE username = :u");
        $stmt->execute([':u' => $username]);
        return (bool)$stmt->fetchColumn();
    }

    public static function findAdminCredentialsByUsername(string $username): ?array
    {
        $stmt = self::db()->prepare("SELECT * FROM admin_credentials WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function getAdminCredentials(int $adminId): ?array
    {
        $stmt = self::db()->prepare("SELECT * FROM admin_credentials WHERE admin_id = :id");
        $stmt->execute([':id' => $adminId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Batched lookup for listing pages (e.g. admin_management.php) that
     * previously got username/mfa_enabled for free via `SELECT * FROM admins`.
     *
     * @param int[] $adminIds
     * @return array<int,array> keyed by admin_id
     */
    public static function getAdminCredentialsBatch(array $adminIds): array
    {
        $adminIds = array_values(array_unique(array_map('intval', $adminIds)));
        if (empty($adminIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
        $stmt = self::db()->prepare("SELECT * FROM admin_credentials WHERE admin_id IN ($placeholders)");
        $stmt->execute($adminIds);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['admin_id']] = $row;
        }
        return $result;
    }

    public static function createAdminCredentials(int $adminId, string $username, string $passwordHash, bool $mfaEnabled = false): void
    {
        $stmt = self::db()->prepare("
            INSERT INTO admin_credentials (admin_id, username, password_hash, mfa_enabled, created_at, updated_at)
            VALUES (:admin_id, :username, :hash, :mfa_enabled, NOW(), NOW())
            ON CONFLICT (admin_id) DO UPDATE SET
                username = EXCLUDED.username,
                password_hash = EXCLUDED.password_hash,
                mfa_enabled = EXCLUDED.mfa_enabled,
                updated_at = NOW()
        ");
        $stmt->execute([
            ':admin_id' => $adminId,
            ':username' => $username,
            ':hash' => $passwordHash,
            ':mfa_enabled' => self::pgBool($mfaEnabled),
        ]);
    }

    public static function updateAdminPassword(int $adminId, string $passwordHash): void
    {
        $stmt = self::db()->prepare("UPDATE admin_credentials SET password_hash = :hash, updated_at = NOW() WHERE admin_id = :id");
        $stmt->execute([':hash' => $passwordHash, ':id' => $adminId]);
    }

    public static function updateAdminUsernameAndMfa(int $adminId, string $username, bool $mfaEnabled): void
    {
        $stmt = self::db()->prepare("
            UPDATE admin_credentials SET username = :username, mfa_enabled = :mfa_enabled, updated_at = NOW()
            WHERE admin_id = :id
        ");
        $stmt->execute([
            ':username' => $username,
            ':mfa_enabled' => self::pgBool($mfaEnabled),
            ':id' => $adminId,
        ]);
    }

    public static function updateAdminMfaSecret(int $adminId, ?string $mfaSecret, bool $mfaEnabled): void
    {
        $stmt = self::db()->prepare("
            UPDATE admin_credentials SET mfa_secret = :secret, mfa_enabled = :enabled, updated_at = NOW()
            WHERE admin_id = :id
        ");
        $stmt->execute([
            ':secret' => $mfaSecret,
            ':enabled' => self::pgBool($mfaEnabled),
            ':id' => $adminId,
        ]);
    }

    public static function recordAdminFailedAttempt(int $adminId, int $newCount, ?string $lockedUntil): void
    {
        $stmt = self::db()->prepare("
            UPDATE admin_credentials SET failed_login_attempts = :count, locked_until = :locked, updated_at = NOW()
            WHERE admin_id = :id
        ");
        $stmt->execute([':count' => $newCount, ':locked' => $lockedUntil, ':id' => $adminId]);
    }

    public static function resetAdminFailedAttempts(int $adminId): void
    {
        $stmt = self::db()->prepare("
            UPDATE admin_credentials SET failed_login_attempts = 0, locked_until = NULL, updated_at = NOW()
            WHERE admin_id = :id
        ");
        $stmt->execute([':id' => $adminId]);
    }
}
