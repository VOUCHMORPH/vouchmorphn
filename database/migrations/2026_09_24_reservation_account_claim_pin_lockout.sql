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
-- With a shell, apply it BEFORE deploying the code that uses these columns:
-- that code counts a wrong PIN here and resets the count on a match, and
-- both fail until the columns exist.
--     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_09_24_reservation_account_claim_pin_lockout.sql
-- Without one, deploy first and apply it straight away from
-- /admin/run_swap_identity_v2_migrations.php, which ships with that code.
-- Until then, a claim that involves a reservation account's claim PIN fails
-- before any money moves, and a claim that leaves a remainder in one goes
-- through without saving its code there. Nothing else is affected.
--
-- Idempotent (IF NOT EXISTS), safe to re-run.
--
-- claim_pin_hash itself predates tracked migrations (as identity_swap_holds
-- does, see 2026_09_16_source_account_type.sql), hence ALTER only.

BEGIN;

ALTER TABLE reservation_accounts
    ADD COLUMN IF NOT EXISTS claim_pin_attempts INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS claim_pin_locked_until TIMESTAMPTZ;

COMMIT;
