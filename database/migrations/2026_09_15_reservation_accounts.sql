-- Reservation accounts: dedicated, bank-controlled accounts opened per
-- beneficiary (user_id) per institution per currency, used to hold the
-- unclaimed remainder of an identity swap instead of parking it in the
-- shared pooled identity_accounts.holding_identifier account.
--
-- The UNIQUE(user_id, institution, currency) constraint is the mechanism
-- that guarantees one person with several claimable identities (phone,
-- email, national_id, ...) resolves to a single reservation account per
-- institution, no matter which identity triggered the claim -- see
-- ReservationAccountService::resolveOrCreateReservationAccount(), which
-- relies on INSERT ... ON CONFLICT (user_id, institution, currency) DO
-- NOTHING to make concurrent claim requests race safely onto one row.

BEGIN;

CREATE TABLE IF NOT EXISTS reservation_accounts (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(user_id),
    institution VARCHAR(100) NOT NULL,
    currency CHAR(3) NOT NULL,
    account_identifier VARCHAR(100),
    account_identifier_type VARCHAR(30) DEFAULT 'account_number',
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CONSTRAINT reservation_accounts_status_check
        CHECK (status IN ('pending', 'active', 'failed')),
    bank_reference VARCHAR(100),
    request_payload JSONB,
    response_payload JSONB,
    requested_at TIMESTAMPTZ DEFAULT now(),
    activated_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT now(),
    updated_at TIMESTAMPTZ DEFAULT now(),
    UNIQUE (user_id, institution, currency)
);

CREATE INDEX IF NOT EXISTS reservation_accounts_institution_status_idx
    ON reservation_accounts (institution, status);

-- identity_holding_positions predates this migration and has no tracked
-- CREATE TABLE anywhere in the repo (see SwapService::placeHoldOnHoldingRemainder());
-- these columns let the sweep job attribute an open pooled position to a
-- resolved person and record where/when it was swept once that person's
-- reservation account at the same institution/currency becomes active.
-- hold_released_at marks that the bank-side hold on the pooled holding
-- account has already been released for this position, independent of
-- whether the deposit into the reservation account that follows it has
-- succeeded yet. Without it, a retry after "release succeeded, deposit
-- failed" would re-issue releaseHold on an already-released hold_reference
-- -- most bank APIs reject that, permanently stalling the sweep. See
-- ReservationAccountService::sweepPosition().
--
-- sweep_claimed_at records when a position was atomically claimed
-- (status -> 'sweeping') for processing. If the worker dies between the
-- claim and finishing (crash, OOM, deploy restart), the position would
-- otherwise sit in 'sweeping' forever -- excluded from both the sweep
-- query and the cron discovery query, with no automated recovery. See
-- ReservationAccountService::reclaimStaleSweepingPositions().
ALTER TABLE identity_holding_positions
    ADD COLUMN IF NOT EXISTS owner_user_id BIGINT REFERENCES users(user_id),
    ADD COLUMN IF NOT EXISTS swept_to_reservation_account_id BIGINT REFERENCES reservation_accounts(id),
    ADD COLUMN IF NOT EXISTS swept_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS hold_released_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS sweep_claimed_at TIMESTAMPTZ;

-- DROP + CREATE rather than CREATE INDEX IF NOT EXISTS: this partial
-- index's WHERE predicate has already changed once during development
-- (open -> open/sweep_failed). IF NOT EXISTS would silently keep an
-- earlier, narrower predicate on any database this migration already ran
-- against, degrading sweep_failed lookups to a full scan without any
-- error -- DROP+CREATE makes a predicate edit here always take effect.
DROP INDEX IF EXISTS identity_holding_positions_sweep_idx;
CREATE INDEX identity_holding_positions_sweep_idx
    ON identity_holding_positions (owner_user_id, institution, currency)
    WHERE status IN ('open', 'sweep_failed');

COMMIT;
