<?php
declare(strict_types=1);

namespace Security\Auth;

use Infrastructure\Credentials\CredentialsRepository;

/**
 * Checks a user's login PIN, with the sign-in lockout.
 *
 * Used by the sign-in page and wherever an already-signed-in user must
 * re-enter their PIN (adding or changing their email or phone), so both
 * share one counter: 5 wrong PINs, from anywhere, lock it for 30 minutes.
 *
 * While the credentials database lacks the lockout columns (see
 * CredentialsRepository::supportsUserLoginLockout()), the PIN is still
 * checked — only the counting is skipped.
 */
final class LoginPinVerifier
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 30;

    public const OK = 'ok';
    public const WRONG_PIN = 'wrong_pin';
    public const LOCKED = 'locked';
    /** The user has no login credential at all — treat like a wrong PIN. */
    public const NO_CREDENTIAL = 'no_credential';

    private CredentialsRepository $credentials;
    private ?bool $lockoutAvailable = null;

    public function __construct(CredentialsRepository $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * @return array{result: string, locked_until: ?string}
     */
    public function check(int $userId, string $pin): array
    {
        $lockout = $this->lockoutAvailable();
        $lock = $lockout ? $this->credentials->findUserLoginLock($userId) : null;

        // While locked, the PIN is not even looked at: a correct guess
        // during the lock must not tell anyone it was correct.
        if ($lock !== null && self::isLocked($lock['locked_until'] ?? null)) {
            return ['result' => self::LOCKED, 'locked_until' => (string) $lock['locked_until']];
        }

        $credential = $this->credentials->findUserCredentialByUserId($userId);
        if (empty($credential['password_hash'])) {
            return ['result' => self::NO_CREDENTIAL, 'locked_until' => null];
        }

        if ($pin !== '' && password_verify($pin, (string) $credential['password_hash'])) {
            if ($lock !== null && ((int) ($lock['failed_login_attempts'] ?? 0) > 0 || !empty($lock['locked_until']))) {
                $this->credentials->resetUserLoginAttempts($userId);
            }
            return ['result' => self::OK, 'locked_until' => null];
        }

        if ($lockout) {
            $state = $this->credentials->recordFailedUserLoginAttempt($userId, self::MAX_ATTEMPTS, self::LOCK_MINUTES);
            if (!empty($state['locked_until'])) {
                return ['result' => self::LOCKED, 'locked_until' => (string) $state['locked_until']];
            }
        }

        return ['result' => self::WRONG_PIN, 'locked_until' => null];
    }

    public static function isLocked(?string $lockedUntil, ?int $now = null): bool
    {
        if ($lockedUntil === null || $lockedUntil === '') {
            return false;
        }
        $until = strtotime($lockedUntil);

        return $until !== false && $until > ($now ?? time());
    }

    private function lockoutAvailable(): bool
    {
        if ($this->lockoutAvailable === null) {
            try {
                $this->lockoutAvailable = $this->credentials->supportsUserLoginLockout();
            } catch (\Throwable $e) {
                error_log("[LoginPinVerifier] Could not check for the lockout columns: " . $e->getMessage());
                $this->lockoutAvailable = false;
            }
            if (!$this->lockoutAvailable) {
                error_log("[LoginPinVerifier] user_credentials has no lockout columns yet — wrong PINs are not being counted. Apply scripts/credentials_db/2026_09_27_user_login_lockout.sql (/admin/run_sign_in_migrations.php).");
            }
        }

        return $this->lockoutAvailable;
    }
}
