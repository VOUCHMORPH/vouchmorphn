--
-- Schema for the isolated credentials database (AUTH_DATABASE_URL).
-- Holds only what previously lived in the main DB's `users.username` /
-- `users.password_hash` and `admins.username` / `admins.password_hash` /
-- `admins.mfa_enabled` / `admins.mfa_secret` /
-- `admins.failed_login_attempts` / `admins.locked_until` columns.
--
-- Rows are keyed by the id from the main database (users.user_id /
-- admins.admin_id) — there is no cross-database foreign key, so
-- referential integrity between the two databases is maintained by the
-- application (see CredentialsRepository), not by Postgres.
--

CREATE TABLE IF NOT EXISTS user_credentials (
    user_id       bigint PRIMARY KEY,
    username      character varying(100) NOT NULL,
    password_hash character varying(255) NOT NULL,
    created_at    timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at    timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS user_credentials_username_idx ON user_credentials (username);

CREATE TABLE IF NOT EXISTS admin_credentials (
    admin_id              bigint PRIMARY KEY,
    username              character varying(100) NOT NULL,
    password_hash         character varying(255) NOT NULL,
    mfa_enabled           boolean DEFAULT false,
    mfa_secret            character varying(255),
    failed_login_attempts integer DEFAULT 0,
    locked_until          timestamp with time zone,
    created_at            timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS admin_credentials_username_idx ON admin_credentials (username);
