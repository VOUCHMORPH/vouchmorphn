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
-- exist purely to protect that hash; plus, for users, the transaction
-- PIN hash and its own lockout counters. No username, email, or any other
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

-- The transaction PIN a user types to claim money sent to one of their
-- verified identities (claim_type 'account_pin' in SwapService) — moved
-- out of users.transaction_pin_* for the same reason the password hash
-- was. For every self-registered and agent-registered user it starts out
-- as the very same hash as their login credential above, so leaving it in
-- the main database left a copy of the login secret there too.
--
-- copied_from_main_db is migration bookkeeping, not policy: it is TRUE
-- only while a row is exactly what
-- scripts/management/migrate_transaction_pins_to_secure_db.php copied
-- from the main database, and every write the app makes clears it. The
-- migration only overwrites rows where it is still TRUE, so re-running it
-- after the code cutover can never revert a PIN the user has since
-- changed here, or undo a lockout the app has recorded here.
CREATE TABLE IF NOT EXISTS user_transaction_pins (
    user_id               BIGINT PRIMARY KEY,         -- = main DB users.user_id
    pin_hash              VARCHAR(255) NOT NULL,
    failed_attempts       INT NOT NULL DEFAULT 0,
    locked_until          TIMESTAMP WITH TIME ZONE,
    pin_set_at            TIMESTAMP WITH TIME ZONE,
    copied_from_main_db   BOOLEAN NOT NULL DEFAULT FALSE,
    created_at            TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
