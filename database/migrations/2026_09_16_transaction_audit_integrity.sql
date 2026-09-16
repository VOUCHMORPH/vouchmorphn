-- Makes the financial audit trail actually writable, and gives the two
-- compliance dead-letter tables real DDL.
--
-- ROOT CAUSE
-- ----------
-- audit_logs.entity_id is declared bigint, but the only writer on the
-- money path -- SwapService::writeAuditLogEntry() -- is declared
-- `string $entityId` and passes the swap reference itself
-- ("SWAP_1758041234_a1b2c3d4e5f6a7b8"). Postgres rejects every one of
-- those inserts with:
--   SQLSTATE[22P02]: Invalid text representation: 7 ERROR: invalid
--   input syntax for type bigint: "SWAP_1758041234_a1b2c3d4e5f6a7b8"
-- The insert is caught, diverted to audit_log_failures, and the swap
-- commits anyway -- so money moves with no audit row. The committed
-- audit_logs data proves it: every existing row has entity_id NULL or a
-- small integer, and there is not a single SWAP_*_CREATED row, which is
-- the action populateAuditLog() writes on every completed swap.
--
-- The same mismatch breaks the read side. The Transaction Certificate
-- looks up "SELECT * FROM audit_logs WHERE entity_id = :ref" with a
-- string reference, throws 22P02, is swallowed, and renders "No audit
-- entries recorded for this reference" for every transaction the system
-- has ever issued.
--
-- Widening entity_id to VARCHAR is the fix rather than making the code
-- pass an integer, because the system's real identifier for a financial
-- entity IS the string reference -- it is what hold_transactions,
-- cashout_authorizations, settlement_outbox and the certificate all key
-- on. Nothing reads audit_logs.entity_id numerically: the only consumers
-- are admin_dashboard.php (equality against a reference, plus canonical
-- JSON for the hash chain) and reports/audit_trails.php (display only).
-- Existing NULL/integer rows cast losslessly via entity_id::text.
--
-- Note organization_audit_logs also has an entity_id BIGINT column. That
-- is a different table, written by DepartmentService for back-office
-- actions, and is deliberately left alone.

BEGIN;

-- 1. The root-cause fix.
ALTER TABLE audit_logs
    ALTER COLUMN entity_id TYPE VARCHAR(255) USING entity_id::text;

-- 2. The hash chain columns. writeAuditLogEntry() computes
--    entry_hash = SHA256(prev_hash || canonical fields) and
--    admin_dashboard.php's "Audit Chain Integrity" panel reads both back
--    to verify the chain -- but neither column has ever existed in any
--    schema in this repo. writeAuditLogEntry() introspects the column
--    list and silently drops them, so the chain was never written; the
--    verification query threw and the panel reported "INTACT" anyway.
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS prev_hash  VARCHAR(64);
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS entry_hash VARCHAR(64);

-- 3. audit_logs had no indexes at all. Both of these back queries that
--    already exist: the certificate's per-reference lookup, and the
--    "ORDER BY performed_at DESC" in AuditTrailService::getAuditLogs().
CREATE INDEX IF NOT EXISTS idx_audit_logs_entity
    ON audit_logs (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_performed_at
    ON audit_logs (performed_at DESC);

-- 4. audit_log_failures is the compliance dead-letter: a row in it means
--    "money moved (or a swap was attempted) without a normal audit trail
--    entry" and needs a human. Until now it was created by a
--    CREATE TABLE IF NOT EXISTS inside SwapService::writeAuditFallback(),
--    i.e. at first failure, in production, inside whatever transaction
--    the swap was holding -- which is precisely the moment that DDL is
--    least likely to succeed. Same DDL as that function used, so an
--    already-created table is left untouched.
CREATE TABLE IF NOT EXISTS audit_log_failures (
    id BIGSERIAL PRIMARY KEY,
    swap_reference VARCHAR(255) NOT NULL,
    swap_type VARCHAR(50),
    user_id INTEGER,
    reason TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMP,
    resolved_by VARCHAR(100)
);
CREATE INDEX IF NOT EXISTS idx_audit_log_failures_unresolved
    ON audit_log_failures (created_at) WHERE resolved_at IS NULL;

-- 5. swap_manual_reconciliation_required, same story: created lazily by
--    SwapService::recordManualReconciliationRequired(). A row here means
--    a source debit failed AFTER the destination was already credited,
--    so the hold was deliberately NOT released (releasing it would pay
--    the customer twice) and a human has to settle it with the
--    institution.
CREATE TABLE IF NOT EXISTS swap_manual_reconciliation_required (
    id BIGSERIAL PRIMARY KEY,
    swap_reference VARCHAR(255) NOT NULL,
    hold_reference VARCHAR(255),
    source_institution VARCHAR(100),
    destination_institution VARCHAR(100),
    amount NUMERIC(18,2),
    currency CHAR(3),
    reason TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMP,
    resolved_by VARCHAR(100),
    resolution_notes TEXT
);
CREATE INDEX IF NOT EXISTS idx_swap_manual_recon_unresolved
    ON swap_manual_reconciliation_required (created_at) WHERE resolved_at IS NULL;

COMMIT;
