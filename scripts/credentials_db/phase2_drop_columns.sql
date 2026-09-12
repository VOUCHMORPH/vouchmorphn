-- ============================================================================
-- PHASE 2 — DESTRUCTIVE. Run manually, against the MAIN database, only
-- after ALL of the following are true:
--
--   1. CREDENTIALS_DATABASE_URL is configured in every environment that
--      runs this app (production included) and has been for a full
--      deploy cycle.
--   2. scripts/management/migrate_credentials_to_secure_db.php --apply
--      has been run against production, and --verify reports OK for
--      both `users` and `admins`.
--   3. The application code from this same change (CredentialsRepository
--      and every touchpoint that used to read/write password_hash
--      directly) has been deployed and is confirmed working — real
--      logins for consumer users, platform admins, and enterprise/org
--      users have all been exercised successfully against the new code
--      path in production.
--   4. You have a fresh backup of the main database.
--
-- This is NOT run automatically by anything in this codebase — no
-- deploy script, migration runner, or application code executes this
-- file. That is intentional: the moment this runs, any code path still
-- reading these columns directly (instead of through
-- CredentialsRepository) breaks immediately and permanently for that
-- column's data, with no easy rollback beyond the backup from step 4.
--
-- What this drops and why:
--   - users.password_hash / admins.password_hash: the actual secrets —
--     the entire point of this migration is that they stop existing
--     here.
--   - admins.failed_login_attempts / admins.locked_until: lockout state
--     that only makes sense next to the password_hash it protects; both
--     admin login entry points (public/admin/auth.php and
--     Application\Admin\Auth\AdminAuth) were switched to read/write
--     these exclusively in the credentials database, so the columns
--     here are dead weight, not a second source of truth.
--   - organization_users.password_hash: never read by any real login
--     path (public/admin/enterprise/login.php authenticates against
--     users.password_hash via the organization_users -> users join) —
--     a write-only duplicate that had already drifted out of sync with
--     the real credential (UserManagementService::resetPassword() used
--     to update only this column, silently not changing what the user
--     could actually log in with). Dropping it removes a second,
--     unmaintained copy of a secret rather than isolating one.
--
-- users.username / users.email / admins.username / admins.email are
-- deliberately NOT dropped — they're login identifiers, not secrets,
-- and stay in the main database for lookup, display, and notification
-- purposes exactly as before. Only the password hash (and, for admins,
-- its lockout counters) is exclusive to the credentials database.
-- ============================================================================

ALTER TABLE users DROP COLUMN IF EXISTS password_hash;

ALTER TABLE admins DROP COLUMN IF EXISTS password_hash;
ALTER TABLE admins DROP COLUMN IF EXISTS failed_login_attempts;
ALTER TABLE admins DROP COLUMN IF EXISTS locked_until;

ALTER TABLE organization_users DROP COLUMN IF EXISTS password_hash;
