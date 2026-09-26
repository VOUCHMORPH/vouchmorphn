-- ============================================================================
-- Sign-in lockout for users
--
-- Run against the CREDENTIALS database (CREDENTIALS_DATABASE_URL), never
-- the main one.
--
-- Wrong login PINs were never counted for users: anyone who knew a phone
-- number could keep guessing its 6-digit PIN. These are the same two
-- columns admin_credentials already has, used the same way — 5 wrong PINs
-- lock sign-in for 30 minutes and a correct PIN resets the count
-- (CredentialsRepository::recordFailedUserLoginAttempt() /
-- resetUserLoginAttempts(), Security\Auth\LoginPinVerifier).
--
-- schema.sql creates user_credentials with these columns already; this
-- file is for databases created before that. Idempotent.
--
-- Apply with:   psql "$CREDENTIALS_DATABASE_URL" -f scripts/credentials_db/2026_09_27_user_login_lockout.sql
-- or, without a shell, the "Apply" button on /admin/run_sign_in_migrations.php.
-- Sign-in keeps working before it runs — just without the lockout.
-- ============================================================================

BEGIN;

ALTER TABLE user_credentials ADD COLUMN IF NOT EXISTS failed_login_attempts INT NOT NULL DEFAULT 0;
ALTER TABLE user_credentials ADD COLUMN IF NOT EXISTS locked_until TIMESTAMP WITH TIME ZONE;

COMMIT;
