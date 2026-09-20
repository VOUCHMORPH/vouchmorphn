-- Functional indexes for the sign-in identifier lookup.
--
-- The lookup in public/user/login.php used to be a plain equality match
-- on each identifier column, which found a user only when the value they
-- typed was byte-for-byte the value stored at sign-up. It is now matched
-- through Domain\Identity\UserIdentifierLookup, which compares email
-- case-insensitively and ID document numbers ignoring case and
-- punctuation, so real account holders stop being told "User not found"
-- over a difference in shape they cannot see.
--
-- lower()/regexp_replace() on a column cannot use a plain b-tree index,
-- so without these the widened lookup would fall back to a sequential
-- scan of `users` on every sign-in attempt. The expressions below are
-- written to match the ones in UserIdentifierLookup::buildMatch()
-- character for character -- if you change one, change both, or the
-- planner will quietly stop using the index.
--
-- The phone columns need nothing new: those are matched with an IN list
-- of literal values, which uses the existing b-tree indexes.
--
-- Idempotent, and each index is guarded on its column existing, because
-- the identifier columns are added at runtime by register.php on
-- databases provisioned before they were introduced.
--
-- Apply with:   psql "$DATABASE_URL" -f database/migrations/2026_09_20_identifier_lookup_indexes.sql
--
-- The fix itself does not depend on this file — the lookup is correct
-- without the indexes, just slower — so it can be applied after the
-- code ships. CREATE INDEX takes a write lock on `users` for as long as
-- it runs; on a table large enough for that to matter, build the same
-- indexes with CREATE INDEX CONCURRENTLY instead, one statement at a
-- time and outside any transaction (CONCURRENTLY cannot run inside the
-- BEGIN/COMMIT below, nor inside the DO block).

BEGIN;

CREATE INDEX IF NOT EXISTS idx_users_email_lower
    ON users (lower(email));

DO $$
DECLARE
    col TEXT;
BEGIN
    FOREACH col IN ARRAY ARRAY['national_id', 'drivers_license', 'passport']
    LOOP
        IF EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_name = 'users'
              AND column_name = col
        ) THEN
            EXECUTE format(
                'CREATE INDEX IF NOT EXISTS idx_users_%1$s_canonical '
                || 'ON users (upper(regexp_replace(%1$I, ''[^A-Za-z0-9]'', '''', ''g''))) '
                || 'WHERE %1$I IS NOT NULL',
                col
            );
        END IF;
    END LOOP;
END $$;

COMMIT;
