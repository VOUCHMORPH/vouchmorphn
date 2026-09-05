-- Transaction Audit Trail
-- Tracks the full lifecycle of every money-movement transaction:
-- where it originated, who sent it, who received it, how much moved,
-- and when it started and finished.
--
-- Apply with: psql "$DATABASE_URL" -f src/Core/Database/sql/transaction_audit_trail.sql

CREATE TABLE IF NOT EXISTS transaction_audit_trail (
    id                      BIGSERIAL PRIMARY KEY,

    -- Links back to the underlying transaction (transactions.transaction_id,
    -- swap_ledgers.swap_reference, etc). Not a foreign key on purpose: this
    -- table must keep recording even if the source row is deleted/archived.
    transaction_reference   VARCHAR(100)    NOT NULL,
    transaction_type        VARCHAR(50)     NOT NULL DEFAULT 'TRANSFER',

    -- Where the transaction began (channel/endpoint/institution/service name)
    origin                  VARCHAR(150)    NOT NULL,

    -- Who sent the money
    sender_name             VARCHAR(150)    NOT NULL,
    sender_account          VARCHAR(100),
    sender_institution      VARCHAR(100),

    -- Who received the money
    receiver_name           VARCHAR(150)    NOT NULL,
    receiver_account        VARCHAR(100),
    receiver_institution    VARCHAR(100),

    -- How much moved
    amount                  NUMERIC(20,8)   NOT NULL,
    currency_code           CHAR(3)         NOT NULL DEFAULT 'BWP',

    -- Lifecycle: STARTED -> COMPLETED | FAILED | REVERSED
    status                  VARCHAR(20)     NOT NULL DEFAULT 'STARTED',

    started_at              TIMESTAMPTZ     NOT NULL,
    ended_at                TIMESTAMPTZ,
    duration_ms             BIGINT,

    metadata                JSONB,
    ip_address              VARCHAR(64),

    created_at              TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_txn_audit_reference   ON transaction_audit_trail (transaction_reference);
CREATE INDEX IF NOT EXISTS idx_txn_audit_sender       ON transaction_audit_trail (sender_account);
CREATE INDEX IF NOT EXISTS idx_txn_audit_receiver     ON transaction_audit_trail (receiver_account);
CREATE INDEX IF NOT EXISTS idx_txn_audit_started_at   ON transaction_audit_trail (started_at);
CREATE INDEX IF NOT EXISTS idx_txn_audit_status       ON transaction_audit_trail (status);
