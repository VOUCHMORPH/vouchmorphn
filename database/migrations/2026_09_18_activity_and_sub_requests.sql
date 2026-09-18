-- Activity and sub-request logging (workflow stage 2: "log the client
-- sub-request parameters into the activity and sub-request tables").
--
-- Neither table existed. What a client asked for was only ever visible
-- indirectly -- scattered across audit_logs, the per-swap tables and the
-- hold rows -- so there was nowhere to answer "what did this person
-- request, and which legs did it break into?" in one query.
--
-- swap_activity     -- one row per client request. The thing the person
--                      actually asked for.
-- swap_sub_requests -- one row per source leg of that request, each with
--                      its own unique reference. A single-source request
--                      has exactly one; a multi-source bundle (the
--                      50 + 150 + 100 shape) has one per contributing
--                      source, which is what makes the bundle auditable
--                      leg by leg instead of only in total.
--
-- Named swap_* to match this schema's existing convention
-- (swap_requests, swap_integrity_findings,
-- swap_manual_reconciliation_required) rather than bare `activity` /
-- `sub_requests`, which would collide far too easily in a shared database.

BEGIN;

CREATE TABLE IF NOT EXISTS swap_activity (
    id              BIGSERIAL PRIMARY KEY,
    reference       VARCHAR(128) NOT NULL,
    activity_type   VARCHAR(64)  NOT NULL,
    actor_type      VARCHAR(32),
    actor_id        BIGINT,
    institution     VARCHAR(100),
    currency        CHAR(3),
    total_amount    NUMERIC(18,2),
    source_count    INTEGER      NOT NULL DEFAULT 0,
    status          VARCHAR(32)  NOT NULL,
    parameters      JSONB,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS swap_activity_reference_idx
    ON swap_activity (reference);
CREATE INDEX IF NOT EXISTS swap_activity_actor_idx
    ON swap_activity (actor_type, actor_id, created_at DESC);

CREATE TABLE IF NOT EXISTS swap_sub_requests (
    id                 BIGSERIAL PRIMARY KEY,
    activity_id        BIGINT       NOT NULL
                       REFERENCES swap_activity (id) ON DELETE CASCADE,
    parent_reference   VARCHAR(128) NOT NULL,
    sub_reference      VARCHAR(128) NOT NULL,
    source_institution VARCHAR(100),
    hold_id            BIGINT,
    hold_reference     VARCHAR(128),
    amount             NUMERIC(18,2) NOT NULL,
    currency           CHAR(3),
    status             VARCHAR(32)  NOT NULL,
    parameters         JSONB,
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Stage 4 requires each leg of a bundle to carry its own unique
-- reference; this is what actually enforces it.
CREATE UNIQUE INDEX IF NOT EXISTS swap_sub_requests_unique_leg_idx
    ON swap_sub_requests (parent_reference, sub_reference);

CREATE INDEX IF NOT EXISTS swap_sub_requests_activity_idx
    ON swap_sub_requests (activity_id);
CREATE INDEX IF NOT EXISTS swap_sub_requests_hold_idx
    ON swap_sub_requests (hold_id);

COMMIT;
