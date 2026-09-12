-- ============================================================================
-- Credentials database schema
--
-- Run this against the SEPARATE credentials database (the one pointed to
-- by CREDENTIALS_DATABASE_URL), never against the main application
-- database.
--
-- Deliberately minimal: just the numeric id (the same id the main
-- database already uses — users.user_id / admins.admin_id), the password
-- hash, and — for admins — the failed-attempt/lockout counters that
-- exist purely to protect that hash. No username, email, or any other
-- PII lives here. Login identifiers (username/email) are looked up in
-- the main database as they already are today; the main database
-- resolves "who is this" to an id, and this database answers "is this
-- password right for that id." A breach of this database alone reveals
-- nothing about who anyone is — only hashes keyed by an opaque integer.
--
-- There is no cross-database foreign key (Postgres can't enforce one
-- across separate databases) — the id link is maintained by the
-- migration/creation code, not by a constraint.
--
-- Idempotent: safe to run against a fresh database or re-run against one
-- that already has these tables.
-- ============================================================================

CREATE TABLE IF NOT EXISTS user_credentials (
    user_id         BIGINT PRIMARY KEY,           -- = main DB users.user_id
    password_hash   VARCHAR(255) NOT NULL,
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_credentials (
    admin_id                BIGINT PRIMARY KEY,   -- = main DB admins.admin_id
    password_hash           VARCHAR(255) NOT NULL,
    failed_login_attempts   INT NOT NULL DEFAULT 0,
    locked_until             TIMESTAMP WITH TIME ZONE,
    created_at               TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
