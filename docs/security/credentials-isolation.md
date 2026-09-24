# Credentials database isolation

Login secrets (password hashes, and for admins the failed-login/lockout
counters that protect them) and users' transaction PINs (with their own
lockout counters) live in a separate database from the main application
data, isolated behind their own connection and access controls. This exists to satisfy the financial-regulatory requirement to
keep authentication secrets out of the same database as operational and
customer data — a breach of the main database alone no longer exposes any
password hash, and a breach of the credentials database alone exposes only
hashes against opaque numeric ids, no names, emails, or account data.

## What moved, and what didn't

| Stays in the main database | Moves to the credentials database |
|---|---|
| `users.username`, `users.email` (login identifiers, also used for display/contact) | `users.password_hash` |
| `admins.username`, `admins.email` | `admins.password_hash`, `admins.failed_login_attempts`, `admins.locked_until` |
| `users.has_transaction_pin` (a yes/no flag, not a secret) | `users.transaction_pin_hash`, `users.transaction_pin_attempts`, `users.transaction_pin_locked_until`, `users.transaction_pin_set_at` — see [Transaction PINs](#transaction-pins) |
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
  class that reads or writes login secrets and transaction PINs. Every
  touchpoint (user login, registration, admin login/creation/reset,
  enterprise login, transaction PIN set/check) goes through it.
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

## Transaction PINs

The transaction PIN is what an account owner types to claim money sent
to one of their verified identities (`claim_type = 'account_pin'` in
`SwapService`). It moved to the credentials database for the same reason
the password hash did, plus one more: both sign-up flows (self-service,
`public/user/verify_otp.php`, and agent/org-assisted,
`public/api/v1/agent/register_identity_owner.php`) use the *same* PIN
hash as the user's login password and as their transaction PIN. So as
long as `users.transaction_pin_hash` existed, the main database kept a
copy of the login secret for every user who hadn't since changed either
one — and a 6-digit PIN's hash is cheap to crack offline.

It lives in `user_transaction_pins`, keyed by `users.user_id` only: the
hash, `failed_attempts`, `locked_until`, `pin_set_at`, and a
`copied_from_main_db` flag that only the migration uses (below). The
lockout policy is unchanged — 5 wrong PINs lock it for 30 minutes, and
every further miss re-locks it until a correct or new PIN resets the
counter. Two things got sturdier on the way: the counter is incremented
atomically, so racing wrong guesses can't overwrite each other's count,
and because it sits behind a separate connection, a miss stays recorded
even when the main-DB swap transaction the check ran inside rolls back.

The system-generated one-time PINs (claim OTPs on `identity_swap_holds`,
`reservation_accounts.claim_pin_hash`, identity-verification codes on
`user_identities`, cash-out codes) aren't set by users and stay in the
main database.

Components, in addition to the ones above:

- `CredentialsRepository` — `findUserTransactionPin()`,
  `hasUserTransactionPin()`, `setUserTransactionPin()`,
  `createUserCredentialWithTransactionPin()` (sign-up: the login
  credential and the PIN in one credentials-DB transaction),
  `recordFailedUserPinAttempt()`, `resetUserPinFailedAttempts()`.
- `src/Infrastructure/Credentials/TransactionPinMigrationRunner.php` —
  the copy and verify logic, shared by
  `scripts/management/migrate_transaction_pins_to_secure_db.php` (CLI)
  and `public/admin/run_transaction_pin_migration.php` (browser).
- `scripts/credentials_db/phase2_drop_transaction_pin_columns.sql` —
  destructive, manual, run only after the cutover below is verified.

The PIN migration is deliberately separate from the password migration.
Re-running the password copy after its cutover would put stale main-DB
hashes back over passwords changed since. The PIN copy can't do that to
PINs: it only overwrites rows still marked `copied_from_main_db`, and
every write the app makes clears that mark — so re-running it is safe
before and after the deploy.

### Transaction PIN cutover

1. **Create the table** by re-running the (idempotent) schema against the
   credentials database — in every environment, *before* the deploy in
   step 3. The new code writes the PIN at every sign-up, so without this
   table sign-ups fail (loudly, and roll back cleanly — but they fail).
   ```
   psql "$CREDENTIALS_DATABASE_URL" -f scripts/credentials_db/schema.sql
   ```

2. **Dry-run, apply, and verify** the copy:
   ```
   php scripts/management/migrate_transaction_pins_to_secure_db.php
   php scripts/management/migrate_transaction_pins_to_secure_db.php --apply
   php scripts/management/migrate_transaction_pins_to_secure_db.php --verify
   ```
   Without a shell, skip to step 3: the browser version,
   `/admin/run_transaction_pin_migration.php`, ships with this change, so
   click its Dry run, Apply and Verify buttons straight after the deploy
   instead. That order is safe too. The only cost is that until Apply
   finishes, existing users are told they have no transaction PIN set.

3. **Deploy the application code from this change.** From here, every
   PIN check, PIN change, and sign-up reads/writes the credentials
   database only; the `users.transaction_pin_*` columns become inert.

4. **Run `--apply` and `--verify` again**, straight after the deploy, to
   pick up any PIN set on the old code between step 2 and the deploy.
   Until then, such a user is told they have no transaction PIN set.

5. **Verify in production**: a real self-service identity claim finalized
   with a transaction PIN, a PIN set from the profile, and a new
   self-service and agent-assisted sign-up whose PIN then works for a
   claim.

6. **Only then**, run
   `scripts/credentials_db/phase2_drop_transaction_pin_columns.sql`
   against the main database — after a fresh backup. As with the
   password columns, everything before this step can be rolled back by
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
