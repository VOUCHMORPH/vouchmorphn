# Credentials database isolation

Login secrets (password hashes, and for admins the failed-login/lockout
counters that protect them) live in a separate database from the main
application data, isolated behind their own connection and access
controls. This exists to satisfy the financial-regulatory requirement to
keep authentication secrets out of the same database as operational and
customer data — a breach of the main database alone no longer exposes any
password hash, and a breach of the credentials database alone exposes only
hashes against opaque numeric ids, no names, emails, or account data.

## What moved, and what didn't

| Stays in the main database | Moves to the credentials database |
|---|---|
| `users.username`, `users.email` (login identifiers, also used for display/contact) | `users.password_hash` |
| `admins.username`, `admins.email` | `admins.password_hash`, `admins.failed_login_attempts`, `admins.locked_until` |
| Everything else — KYC, phone, roles, org membership, MFA secrets, audit trail | — |

The credentials database only ever sees the numeric id the main database
already uses (`users.user_id` / `admins.admin_id`). It never stores a
username or email — identifier lookup (turning a submitted username/email
into an id) still happens in the main database exactly as before; the
credentials database only answers "is this password right for this id."
That avoids keeping a second, easily-drifting copy of identifying data in
sync across two databases.

`organization_users.password_hash` was removed rather than migrated: it
was a write-only duplicate that no login path ever read (enterprise login
authenticates against `users.password_hash` via the
`organization_users -> users` join) and had already drifted out of sync
with the real credential — `UserManagementService::resetPassword()` used
to update only that column, so a password "reset" there silently didn't
change what the person could actually log in with. That bug is fixed as
part of this change: `resetPassword()` now updates the real credential.

## Components

- `src/Core/Database/CredentialsDBConnection.php` — singleton PDO
  connection to the credentials database, reading `CREDENTIALS_DATABASE_URL`.
  Mirrors `DBConnection.php`'s shape.
- `src/Infrastructure/Credentials/CredentialsRepository.php` — the only
  class that reads or writes login secrets. Every touchpoint (user login,
  registration, admin login/creation/reset, enterprise login) goes
  through it.
- `scripts/credentials_db/schema.sql` — creates `user_credentials` and
  `admin_credentials` in the credentials database.
- `scripts/management/migrate_credentials_to_secure_db.php` — one-time,
  non-destructive copy of existing password hashes from the main database
  into the credentials database.
- `scripts/credentials_db/phase2_drop_columns.sql` — destructive, manual,
  run only after the cutover below is verified.

## Cutover procedure

1. **Provision the credentials database.** A separate Postgres database
   (can be on the same server, but a separate logical database — a
   separate host/instance is stronger isolation and closer to what most
   financial-regulatory guidance expects). Note its connection string.

2. **Set `CREDENTIALS_DATABASE_URL`** in every environment that runs this
   app (same format as `DATABASE_URL`). Set it as a platform environment
   variable (e.g. a Railway service variable), the same way
   `DATABASE_URL` already is — **do not** add it to the `.env` file, which
   is currently a tracked file in this repository (currently a broken
   symlink with no real secret in it, but not a place to start putting
   one either).

3. **Create the schema:**
   ```
   psql "$CREDENTIALS_DATABASE_URL" -f scripts/credentials_db/schema.sql
   ```

4. **Dry-run the migration**, then apply it, then verify it:
   ```
   php scripts/management/migrate_credentials_to_secure_db.php
   php scripts/management/migrate_credentials_to_secure_db.php --apply
   php scripts/management/migrate_credentials_to_secure_db.php --verify
   ```
   The migration is idempotent — safe to re-run `--apply` to pick up
   accounts created between runs, right up until the app is deployed on
   the new code.

5. **Deploy the application code from this change.** From this point,
   every login and every new account creation reads/writes the
   credentials database exclusively. The main database's
   `password_hash` columns become inert (still present, no longer read
   or written) — that's deliberate, so this step is reversible by
   reverting the deploy if something is wrong.

6. **Verify in production**: a real consumer login, a real platform-admin
   login, and a real enterprise/org login, each actually exercised
   end-to-end.

7. **Only then**, run `scripts/credentials_db/phase2_drop_columns.sql`
   against the main database — after a fresh backup. This is the
   irreversible step; everything before it can be rolled back by
   reverting the code deploy.

## Operational notes

- **No cross-database transactions.** The main database and the
  credentials database are two separate PDO connections; Postgres cannot
  transact across them. Code that creates a new identity (main-DB INSERT
  + credentials-DB INSERT) treats it as a two-step operation: the
  credentials write happens before the main-DB transaction commits, so a
  failure there rolls back the main-DB row too (no user left with no way
  to log in). The converse — main-DB commit failing after the credentials
  row was already written — is not compensated; it's an accepted,
  extremely rare edge case for a plain-INSERT commit, left as a residual
  risk rather than implementing distributed two-phase commit for it.
- **Backup/restore** of the two databases should be coordinated (same
  point in time, or close to it) so a restore doesn't leave main-DB rows
  with no matching credential or vice versa.
- **Access control**: the credentials database should have its own,
  narrower set of credentials/roles than the main database — a separate
  database is only a meaningful isolation boundary if it also has
  separate access controls.
