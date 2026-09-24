-- Attempt limit for reservation accounts' claim PINs.
--
-- reservation_accounts.claim_pin_hash (written by
-- ReservationAccountService::rememberClaimPin()) is a copy of the one-time
-- code of the claim that parked a balance there, and it opens the
-- identity's claim pool (SwapService::authenticateIdentityClaimPool()). A
-- wrong PIN used to be counted only against the pending swaps' one-time
-- codes, so once the pool held only reservation money nothing counted at
-- all, and a 6-digit code could be guessed without limit.
--
-- Same policy as every other claim credential: 5 misses lock the PIN for
-- 30 minutes, every further miss locks it again, and a match or a new PIN
-- resets the count (ReservationAccountService::recordFailedClaimPinAttempt(),
-- resetClaimPinAttempts(), rememberClaimPin()).
--
-- Apply BEFORE deploying the code that uses these columns: that code
-- counts a wrong PIN here and resets the count on a match, and both fail
-- until the columns exist. Idempotent (IF NOT EXISTS), safe to re-run.
--
-- claim_pin_hash itself predates tracked migrations (as identity_swap_holds
-- does, see 2026_09_16_source_account_type.sql), hence ALTER only.

BEGIN;

ALTER TABLE reservation_accounts
    ADD COLUMN IF NOT EXISTS claim_pin_attempts INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS claim_pin_locked_until TIMESTAMPTZ;

COMMIT;
