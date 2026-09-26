<?php
declare(strict_types=1);

namespace Infrastructure\Credentials;

use PDO;
use RuntimeException;

/**
 * Single point of access for login secrets (password_hash, plus admin
 * lockout counters) and users' transaction PINs (plus their lockout
 * counters) against the isolated credentials database.
 *
 * Every place in the app that used to read or write users.password_hash,
 * admins.password_hash, organization_users.password_hash, or
 * users.transaction_pin_* directly goes through here instead — so the
 * credentials database is the only place those secrets live, and there is
 * exactly one code path to review for how they're hashed, verified, and
 * rate-limited.
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
    // USER SIGN-IN LOCKOUT
    // ============================================================================
    //
    // Same policy as the admin pair below and the transaction PIN: 5 wrong
    // login PINs lock sign-in for 30 minutes, every further miss after that
    // re-locks it, and only a correct PIN resets the counter. The columns
    // come from scripts/credentials_db/2026_09_27_user_login_lockout.sql;
    // until that has run, supportsUserLoginLockout() is false and callers
    // (Security\Auth\LoginPinVerifier) skip the lockout rather than fail.

    public function supportsUserLoginLockout(): bool
    {
        return \Domain\Identity\SignInSchema::hasUserLoginLockout($this->db);
    }

    /** @return array{failed_login_attempts: int|string, locked_until: ?string}|null */
    public function findUserLoginLock(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT failed_login_attempts, locked_until FROM user_credentials WHERE user_id = :id"
        );
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Records one wrong login PIN and, once $maxAttempts is reached, locks
     * sign-in for $lockMinutes. Incremented in SQL, like
     * recordFailedUserPinAttempt(), so racing wrong guesses can't lose a
     * count. locked_until in the result is non-null only when this miss is
     * the one that locked it.
     */
    public function recordFailedUserLoginAttempt(int $userId, int $maxAttempts = 5, int $lockMinutes = 30): array
    {
        $stmt = $this->db->prepare("
            UPDATE user_credentials
            SET failed_login_attempts = failed_login_attempts + 1,
                locked_until = CASE WHEN failed_login_attempts + 1 >= :max
                                    THEN :lock_until
                                    ELSE CAST(NULL AS TIMESTAMP WITH TIME ZONE) END,
                updated_at = NOW()
            WHERE user_id = :id
            RETURNING failed_login_attempts, locked_until
        ");
        $stmt->bindValue(':max', $maxAttempts, PDO::PARAM_INT);
        $stmt->bindValue(':lock_until', date(DATE_ATOM, time() + $lockMinutes * 60));
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['failed_login_attempts' => 0, 'locked_until' => null];
    }

    public function resetUserLoginAttempts(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE user_credentials SET failed_login_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE user_id = :id"
        );
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
    // ============================================================================
    //
    // The PIN a user types to claim money sent to one of their verified
    // identities (claim_type 'account_pin' in SwapService). It used to live
    // in users.transaction_pin_* on the main database, where — for every
    // self-registered and agent-registered user — it started out as the
    // very same hash as their login credential, i.e. a second copy of the
    // login secret outside this database.
    //
    // Every write below clears copied_from_main_db, which is what stops the
    // one-time migration (TransactionPinMigrationRunner) from ever
    // overwriting a row once the app has touched it — see
    // scripts/credentials_db/schema.sql.

    public function findUserTransactionPin(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id, pin_hash, failed_attempts, locked_until, pin_set_at
             FROM user_transaction_pins WHERE user_id = :id"
        );
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Yes/no without pulling the hash out of the database, for callers
     * (the dashboard's "Set a transaction PIN" step) that only need to
     * know whether one exists.
     */
    public function hasUserTransactionPin(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM user_transaction_pins WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Sets or replaces the user's transaction PIN and clears any lockout,
     * as setting a PIN always has. Upserts, since a user who has never set
     * one has no row yet.
     */
    public function setUserTransactionPin(int $userId, string $pinHash): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_transaction_pins
                (user_id, pin_hash, failed_attempts, locked_until, pin_set_at, copied_from_main_db, created_at, updated_at)
            VALUES (:id, :hash, 0, NULL, NOW(), FALSE, NOW(), NOW())
            ON CONFLICT (user_id) DO UPDATE
                SET pin_hash = EXCLUDED.pin_hash,
                    failed_attempts = 0,
                    locked_until = NULL,
                    pin_set_at = NOW(),
                    copied_from_main_db = FALSE,
                    updated_at = NOW()
        ");
        $stmt->execute([':id' => $userId, ':hash' => $pinHash]);
    }

    /**
     * Sign-up's two secrets together: the login credential and the
     * transaction PIN (the same hash, for every sign-up path that sets
     * both), in one credentials-DB transaction so a new user can never end
     * up with one and not the other. Same contract as
     * createUserCredential(): the main-DB users row must already exist —
     * see the class-level note on the two-step create.
     */
    public function createUserCredentialWithTransactionPin(int $userId, string $passwordHash, string $pinHash): void
    {
        $this->db->beginTransaction();
        try {
            $this->createUserCredential($userId, $passwordHash);
            $this->setUserTransactionPin($userId, $pinHash);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Records one wrong PIN and, once $maxAttempts is reached, locks the
     * PIN for $lockMinutes — the policy SwapService used to apply to
     * users.transaction_pin_* directly, unchanged (including re-locking on
     * every further miss after the first lock: only a correct PIN or a new
     * PIN resets the counter).
     *
     * The counter is incremented in SQL rather than written back from a
     * value the caller read earlier, so two wrong guesses racing each
     * other can't both store the same "attempts + 1" and lose one. And
     * because this is a separate connection from the main database, the
     * miss stays recorded even when the main-DB swap transaction the PIN
     * check ran inside is rolled back.
     *
     * Returns the row's new failed_attempts / locked_until; locked_until is
     * non-null only when this miss is the one that locked the PIN.
     */
    public function recordFailedUserPinAttempt(int $userId, int $maxAttempts = 5, int $lockMinutes = 30): array
    {
        // The typed NULL is for Postgres: with only an untyped parameter
        // and a bare NULL, the CASE would resolve to text, which can't be
        // assigned to a timestamptz column.
        $stmt = $this->db->prepare("
            UPDATE user_transaction_pins
            SET failed_attempts = failed_attempts + 1,
                locked_until = CASE WHEN failed_attempts + 1 >= :max
                                    THEN :lock_until
                                    ELSE CAST(NULL AS TIMESTAMP WITH TIME ZONE) END,
                copied_from_main_db = FALSE,
                updated_at = NOW()
            WHERE user_id = :id
            RETURNING failed_attempts, locked_until
        ");
        $stmt->bindValue(':max', $maxAttempts, PDO::PARAM_INT);
        // An absolute instant (with its UTC offset) rather than a bare
        // local time, so the lock ends when intended whatever timezone
        // this database session happens to run in.
        $stmt->bindValue(':lock_until', date(DATE_ATOM, time() + $lockMinutes * 60));
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['failed_attempts' => 0, 'locked_until' => null];
    }

    public function resetUserPinFailedAttempts(int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE user_transaction_pins
            SET failed_attempts = 0, locked_until = NULL, copied_from_main_db = FALSE, updated_at = NOW()
            WHERE user_id = :id
        ");
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
