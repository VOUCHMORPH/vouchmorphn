-- Attempt limit for source registration codes.
--
-- A customer's source is verified by the code its institution sends to the
-- account holder (SwapService::completeUserSourceRegistration()). That code
-- is the proof the account is theirs: once it passes, the account can be
-- hooked to a card and spent from. Wrong codes were never counted, and
-- resendOtpForAttempt() pushed the attempt's expiry out on every resend, so
-- a code could be guessed without limit and someone else's account
-- registered as a verified source.
--
-- Now 5 wrong codes fail the attempt (SwapService::recordFailedSourceOtp());
-- starting again gets a new code from the institution. A resend never
-- resets the count.
--
-- Until this is applied the code still refuses unlimited guesses: with
-- nowhere to count, the FIRST wrong code fails the attempt. Apply it to give
-- customers their five tries.
--     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_09_27_source_registration_otp_lockout.sql
-- Without a shell, apply it from /admin/run_swap_identity_v2_migrations.php.
--
-- Idempotent (IF NOT EXISTS), safe to re-run.
--
-- user_source_registration_attempts predates tracked migrations (as
-- identity_swap_holds does, see 2026_09_16_source_account_type.sql), hence
-- ALTER only.

BEGIN;

ALTER TABLE user_source_registration_attempts
    ADD COLUMN IF NOT EXISTS otp_failed_attempts INT NOT NULL DEFAULT 0;

COMMIT;
