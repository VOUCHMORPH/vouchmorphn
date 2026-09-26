-- The 'agent' role, which the admin dashboard's Agents tab gives to
-- customers (Application\Admin\AgentRoles).
--
-- whoami.php reports is_agent from the customer's role, and the customer
-- dashboard only shows the Agent menu, where an agent registers the account
-- they pay out from, to someone whose role is 'agent'. The tracked schema
-- never created that role, and its role_name_check allowed only the five
-- original names, so the role could not even be inserted. If the Agents tab
-- says there is no 'agent' role, apply this:
--
--     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_09_26_agent_role.sql
--
-- On a database that already has an 'agent' role it changes nothing. Where
-- it adds the role:
--   - role_name_check, if the table has one, becomes whatever it allowed
--     before, OR 'agent'. Production has roles the tracked dumps do not, so
--     the old condition is carried over as it is rather than retyped here.
--   - The role gets the plain 'user' role's permissions: an agent is still
--     a customer. Every other column takes its default.
--   - Its id is one past the highest role_id in use. Some roles have
--     hand-picked ids (the admin dashboard uses 999), which the id sequence
--     can be behind.
--
-- Idempotent, safe to re-run.

BEGIN;

DO $$
DECLARE
    old_check text;
BEGIN
    IF EXISTS (SELECT 1 FROM roles WHERE role_name = 'agent') THEN
        RETURN;
    END IF;

    SELECT pg_get_expr(conbin, conrelid) INTO old_check
    FROM pg_constraint
    WHERE conrelid = 'roles'::regclass AND conname = 'role_name_check' AND contype = 'c';

    IF old_check IS NOT NULL THEN
        ALTER TABLE roles DROP CONSTRAINT role_name_check;
        EXECUTE format(
            'ALTER TABLE roles ADD CONSTRAINT role_name_check CHECK ((%s) OR role_name::text = %L)',
            old_check, 'agent'
        );
    END IF;

    INSERT INTO roles (role_id, role_name, description, permissions)
    SELECT COALESCE(MAX(role_id), 0) + 1,
           'agent',
           'Customer who pays out identity claims from an approved business account',
           COALESCE((SELECT permissions FROM roles WHERE role_name = 'user'), '[]')
    FROM roles;
END $$;

COMMIT;
