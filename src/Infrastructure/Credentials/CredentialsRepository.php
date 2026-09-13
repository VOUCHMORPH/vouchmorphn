<?php
declare(strict_types=1);

namespace Infrastructure\Credentials;

use PDO;
use RuntimeException;

/**
 * Single point of access for login secrets (password_hash, plus admin
 * lockout counters) against the isolated credentials database.
 *
 * Every place in the app that used to read or write users.password_hash,
 * admins.password_hash, or organization_users.password_hash directly goes
 * through here instead — so the credentials database is the only place
 * those secrets live, and there is exactly one code path to review for
 * how they're hashed, verified, and rate-limited.
 *
 * Deliberately keyed by numeric id only (users.user_id / admins.admin_id)
 * — no username or email lives in this database. Identifier lookup
 * (turning a submitted username/email into an id) stays in the main
 * database exactly as it already worked; this repository only answers
 * "what's the password hash / lockout state for this id." That keeps
 * this database free of PII (a breach here reveals only hashes against
 * opaque integers) and avoids having to keep a second, easily-drifting
 * copy of username/email in sync across two databases — the same kind
 * of duplicate-secret drift found in organization_users.password_hash
 * (see UserManagementService::resetPassword, which updated only that
 * table while the real login check reads users.password_hash — the two
 * had silently gone out of sync).
 *
 * Cross-database consistency note: the main database (users/admins rows)
 * and this credentials database are two separate PDO connections — a
 * single ACID transaction cannot span both. Callers that create a new
 * identity (main-DB INSERT + credentials-DB INSERT) must treat this as a
 * two-step operation and compensate (roll back/delete the main-DB row)
 * if the second step fails; see the create*() call sites for the
 * pattern. Once a row exists in both places, ordinary updates (e.g.
 * password changes) touch only the credentials database and need no
 * coordination.
 */
class CredentialsRepository
{
    private PDO $db;

    public function __construct(PDO $credentialsDb)
    {
        $this->db = $credentialsDb;
    }

    // ============================================================================
    // USERS
    // ============================================================================

    public function findUserCredentialByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id, password_hash FROM user_credentials WHERE user_id = :id"
        );
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Creates the credentials row for a user that already exists in the
     * main database. Callers must have already inserted the main-DB
     * users row and pass its real user_id — see the class-level note on
     * the two-step create pattern.
     */
    public function createUserCredential(int $userId, string $passwordHash): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_credentials (user_id, password_hash, created_at, updated_at)
            VALUES (:id, :hash, NOW(), NOW())
        ");
        $stmt->execute([':id' => $userId, ':hash' => $passwordHash]);
    }

    public function updateUserPassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE user_credentials SET password_hash = :hash, updated_at = NOW() WHERE user_id = :id"
        );
        return $stmt->execute([':hash' => $passwordHash, ':id' => $userId]);
    }

    /**
     * Deletes the credentials row. Used only to compensate a failed
     * two-step create (see class-level note) — never as a general-purpose
     * "delete this user" operation.
     */
    public function deleteUserCredential(int $userId): void
    {
        $stmt = $this->db->prepare("DELETE FROM user_credentials WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
    }

    // ============================================================================
    // ADMINS
    // ============================================================================

    public function findAdminCredentialByAdminId(int $adminId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT admin_id, password_hash, failed_login_attempts, locked_until
             FROM admin_credentials WHERE admin_id = :id"
        );
        $stmt->execute([':id' => $adminId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createAdminCredential(int $adminId, string $passwordHash): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO admin_credentials (admin_id, password_hash, created_at, updated_at)
            VALUES (:id, :hash, NOW(), NOW())
        ");
        $stmt->execute([':id' => $adminId, ':hash' => $passwordHash]);
    }

    public function updateAdminPassword(int $adminId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE admin_credentials SET password_hash = :hash, updated_at = NOW() WHERE admin_id = :id"
        );
        return $stmt->execute([':hash' => $passwordHash, ':id' => $adminId]);
    }

    public function deleteAdminCredential(int $adminId): void
    {
        $stmt = $this->db->prepare("DELETE FROM admin_credentials WHERE admin_id = :id");
        $stmt->execute([':id' => $adminId]);
    }

    /**
     * Shared lockout policy used by every admin login entry point.
     * Increments the failure counter and, once $maxAttempts is reached,
     * locks the account for $lockMinutes. Returns the row's new state so
     * the caller can log/report it without a second query.
     */
    public function recordFailedAdminAttempt(int $adminId, int $currentAttempts, int $maxAttempts = 5, int $lockMinutes = 15): array
    {
        $newAttempts = $currentAttempts + 1;
        $locked = $newAttempts >= $maxAttempts;

        $stmt = $this->db->prepare("
            UPDATE admin_credentials
            SET failed_login_attempts = :attempts,
                locked_until = CASE WHEN :locked::boolean THEN NOW() + (:mins || ' minutes')::interval ELSE locked_until END,
                updated_at = NOW()
            WHERE admin_id = :id
            RETURNING failed_login_attempts, locked_until
        ");
        $stmt->execute([
            ':attempts' => $newAttempts,
            ':locked' => $locked ? 't' : 'f',
            ':mins' => $lockMinutes,
            ':id' => $adminId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['failed_login_attempts' => $newAttempts, 'locked_until' => null];
    }

    public function resetAdminFailedAttempts(int $adminId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE admin_credentials SET failed_login_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE admin_id = :id"
        );
        $stmt->execute([':id' => $adminId]);
    }

    // ============================================================================
    // USER TRANSACTION PINS
    //
    // The user's standing PIN for authorizing swaps/identity claims - a
    // real, long-lived credential like password_hash, so it lives here
    // too. This is NOT where one-time OTP codes live: those stay in the
    // main database's hold_transactions/identity_swap_holds rows as part
    // of one atomic multi-table swap transaction - see the class-level
    // note on cross-database consistency for why splitting an ephemeral,
    // already-short-lived value out of that transaction isn't worth the
    // atomicity it would cost.
    // ============================================================================

    public function findUserTransactionPin(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id, pin_hash, attempts, locked_until FROM user_transaction_pins WHERE user_id = :id"
        );
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Sets (or replaces) a user's transaction PIN, resetting any lockout
     * state - the same "clean slate on password change" behavior a login
     * password reset gets. Idempotent via upsert, so it works whether the
     * user has never set one or is changing an existing one.
     */
    public function setUserTransactionPin(int $userId, string $pinHash): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_transaction_pins (user_id, pin_hash, attempts, locked_until, set_at, created_at, updated_at)
            VALUES (:id, :hash, 0, NULL, NOW(), NOW(), NOW())
            ON CONFLICT (user_id) DO UPDATE
                SET pin_hash = EXCLUDED.pin_hash,
                    attempts = 0,
                    locked_until = NULL,
                    set_at = NOW(),
                    updated_at = NOW()
        ");
        $stmt->execute([':id' => $userId, ':hash' => $pinHash]);
    }

    /**
     * Same shared lockout policy as recordFailedAdminAttempt, applied to
     * transaction PINs instead of login passwords.
     */
    public function recordFailedTransactionPinAttempt(int $userId, int $currentAttempts, int $maxAttempts = 5, int $lockMinutes = 15): array
    {
        $newAttempts = $currentAttempts + 1;
        $locked = $newAttempts >= $maxAttempts;

        $stmt = $this->db->prepare("
            UPDATE user_transaction_pins
            SET attempts = :attempts,
                locked_until = CASE WHEN :locked::boolean THEN NOW() + (:mins || ' minutes')::interval ELSE locked_until END,
                updated_at = NOW()
            WHERE user_id = :id
            RETURNING attempts, locked_until
        ");
        $stmt->execute([
            ':attempts' => $newAttempts,
            ':locked' => $locked ? 't' : 'f',
            ':mins' => $lockMinutes,
            ':id' => $userId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['attempts' => $newAttempts, 'locked_until' => null];
    }

    public function resetTransactionPinAttempts(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE user_transaction_pins SET attempts = 0, locked_until = NULL, updated_at = NOW() WHERE user_id = :id"
        );
        $stmt->execute([':id' => $userId]);
    }

    // ============================================================================
    // FACTORY
    // ============================================================================

    /**
     * Resolves the credentials DB connection and throws a clear error if
     * CREDENTIALS_DATABASE_URL isn't configured, instead of letting every
     * call site null-check a PDO. Every touchpoint should build its
     * repository this way rather than calling CredentialsDBConnection
     * directly.
     */
    public static function fromEnvironment(): self
    {
        $pdo = \Core\Database\CredentialsDBConnection::getConnection();
        if (!$pdo) {
            throw new RuntimeException(
                "Credentials database is not reachable — check CREDENTIALS_DATABASE_URL. " .
                "Login and account creation cannot proceed without it."
            );
        }
        return new self($pdo);
    }
}
