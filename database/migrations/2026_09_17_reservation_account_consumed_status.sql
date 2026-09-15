-- Widens reservation_accounts.status's CHECK constraint to allow
-- 'consumed' -- the terminal state for residual rollover (swap-to-identity
-- algorithm v2, §9 / plan §7): a position pulled into a new claim as a
-- synthetic hold and fully spent there, via
-- ReservationAccountService::closePosition(). Distinct from 'failed'
-- (creation never worked) and from an 'active' account simply being at a
-- zero real-world balance (VouchMorph doesn't track a local balance --
-- the bank is the source of truth on that).
--
-- Postgres has no ALTER CONSTRAINT to widen a CHECK in place -- drop and
-- recreate under the same constraint name, same convention the original
-- 2026_09_15_reservation_accounts.sql migration used for its DROP+CREATE
-- INDEX (a predicate/definition change must always take effect, not be
-- silently skipped by an IF NOT EXISTS-style guard).

BEGIN;

ALTER TABLE reservation_accounts
    DROP CONSTRAINT IF EXISTS reservation_accounts_status_check;

ALTER TABLE reservation_accounts
    ADD CONSTRAINT reservation_accounts_status_check
    CHECK (status IN ('pending', 'active', 'failed', 'consumed'));

COMMIT;
