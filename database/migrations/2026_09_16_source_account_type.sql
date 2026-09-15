-- Adds source_account_type to identity_swap_holds: the classification of
-- the SOURCE account a swap-to-identity hold was placed against
-- (GOVERNMENT / BUSINESS_OR_TRUST / PERSONAL), populated at hold-placement
-- time via SwapService::verifySourceAccountType() (reuses the existing
-- verifyAccount() adapter call already proven for destination-registration,
-- against the source account instead).
--
-- This is what lets Phase D expiry handling (cancelExpiredIdentitySwaps())
-- branch correctly: GOVERNMENT/BUSINESS_OR_TRUST money is owed to the
-- identity and cannot be un-sent, so it parks in a reservation account at
-- the source institution on expiry; PERSONAL money is a lapsed gift and
-- releases back to the sender. See the swap-to-identity algorithm v2 plan,
-- §2 and §8.
--
-- identity_swap_holds predates tracked migrations (no CREATE TABLE for it
-- anywhere in this repo -- same situation as identity_holding_positions,
-- see 2026_09_15_reservation_accounts.sql), hence ALTER rather than CREATE.

BEGIN;

ALTER TABLE identity_swap_holds
    ADD COLUMN IF NOT EXISTS source_account_type VARCHAR(30);

COMMIT;
