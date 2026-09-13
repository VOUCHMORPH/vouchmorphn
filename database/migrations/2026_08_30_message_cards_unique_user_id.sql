-- Adds the unique constraint on message_cards.user_id that
-- CardService::provisionUserCard() has relied on since it was written
-- (it upserts with ON CONFLICT (user_id) DO NOTHING), but which was
-- never actually created against the database.
--
-- Without this constraint, Postgres rejects that INSERT with:
--   SQLSTATE[42P10]: Invalid column reference: 7 ERROR: there is no
--   unique or exclusion constraint matching the ON CONFLICT specification
-- for every account provisioning its first card -- this is why "My Card"
-- fails to load for brand-new accounts.

BEGIN;

-- A handful of users may already have more than one message_cards row
-- (created by concurrent requests racing this code path before the
-- constraint existed). Keep exactly one row per user_id before adding
-- the constraint: prefer a row that already left INACTIVE status (a
-- real, activated/used card) over an auto-provisioned stub, and break
-- ties by the most recently created row.
WITH ranked AS (
    SELECT
        card_id,
        ROW_NUMBER() OVER (
            PARTITION BY user_id
            ORDER BY
                (lifecycle_status <> 'INACTIVE') DESC,
                created_at DESC,
                card_id DESC
        ) AS rn
    FROM message_cards
    WHERE user_id IS NOT NULL
)
DELETE FROM message_cards
WHERE card_id IN (SELECT card_id FROM ranked WHERE rn > 1);

ALTER TABLE message_cards
    ADD CONSTRAINT message_cards_user_id_unique UNIQUE (user_id);

COMMIT;
