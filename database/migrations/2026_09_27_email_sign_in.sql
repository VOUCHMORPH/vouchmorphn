-- Email sign-up and verified-email sign-in.
--
-- 1. users.phone stops being required. Sign-up has offered an Email tab
--    for a while (public/user/register.php), but public/user/verify_otp.php
--    inserts phone = NULL for those accounts, and the canonical schema has
--    `phone ... NOT NULL` — so wherever that constraint is still live,
--    every email sign-up failed after the code was accepted. UNIQUE (phone)
--    stays: Postgres treats NULLs as distinct, so any number of email-only
--    accounts can have no phone.
--
-- 2. users.email_verified_at records that the address received a code
--    and the code came back. Sign-in only accepts an email that has one
--    (public/user/login.php). Phone-only sign-ups are given a made-up
--    address (<username>@<country>.vouchmorphn.com) to satisfy
--    `email NOT NULL`; it is never verified, so it can never sign anyone in.
--
-- 3. Backfill: a self-service account whose email is not a made-up one
--    got it by answering the code sent to that address at sign-up (email
--    tab, or an ID sign-up that chose email for the code), so it counts as
--    verified from created_at. Agent-registered accounts and anything
--    ambiguous are left unverified — their owners get the dashboard banner
--    and verify it themselves. An address that appears on more than one
--    row (e.g. differing only in case) is skipped on every row, so the
--    unique index below can always be built.
--
-- 4. One account per verified email, compared case-insensitively.
--
-- Idempotent: every statement is guarded or a no-op when already applied.
--
-- Apply with:   psql "$DATABASE_URL" -f database/migrations/2026_09_27_email_sign_in.sql
-- or, without a shell, the "Apply" button on /admin/run_sign_in_migrations.php
-- straight after deploying the code that ships with it. The code works
-- before this runs (it checks for email_verified_at and falls back to
-- "any address that is not a made-up one"), so the order is not critical —
-- but email sign-up keeps failing until step 1 has run.

BEGIN;

ALTER TABLE users ALTER COLUMN phone DROP NOT NULL;

ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMPTZ NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'users'
          AND column_name = 'registration_channel'
    ) THEN
        UPDATE users u
        SET email_verified_at = COALESCE(u.created_at, NOW())
        WHERE u.email_verified_at IS NULL
          AND u.registration_channel = 'self'
          AND u.email IS NOT NULL
          AND u.email <> ''
          AND lower(u.email) NOT LIKE '%.vouchmorphn.com'
          AND NOT EXISTS (
              SELECT 1
              FROM users other
              WHERE other.user_id <> u.user_id
                AND lower(other.email) = lower(u.email)
          );
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS idx_users_verified_email
    ON users (lower(email))
    WHERE email_verified_at IS NOT NULL;

COMMIT;
