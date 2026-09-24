<?php
declare(strict_types=1);

namespace Infrastructure\Credentials;

use DateTimeImmutable;
use PDO;

/**
 * Shared logic for the one-time copy of users' transaction PINs
 * (users.transaction_pin_*) from the main database into the credentials
 * database's user_transaction_pins table.
 *
 * Driven from both scripts/management/migrate_transaction_pins_to_secure_db.php
 * (CLI) and public/admin/run_transaction_pin_migration.php (browser, for
 * environments without shell access) — two front ends, one migration
 * logic, the same split as CredentialsMigrationRunner.
 *
 * Kept apart from CredentialsMigrationRunner on purpose. That migration
 * has its own cutover, and re-running its users/admins copy after it would
 * put stale main-DB password hashes back over passwords changed since;
 * nothing here reads or writes a password.
 *
 * Unlike that copy, this one can't clobber anything the app has written:
 * it only overwrites credentials-DB rows still marked copied_from_main_db,
 * and every app write clears that mark (see CredentialsRepository). So it
 * is safe to re-run after the code cutover, too — e.g. to pick up a PIN
 * set on the old code between the last run and the deploy.
 *
 * Every method takes an $out callback (string $line): void instead of
 * printing directly, like CredentialsMigrationRunner.
 */
class TransactionPinMigrationRunner
{
    private const LABEL = 'transaction_pins';

    public static function migrate(PDO $mainDb, PDO $credDb, bool $apply, int $batchSize, callable $out): array
    {
        $label = self::LABEL;

        // The checked-in schema has drifted from the live one (it declares
        // pin_attempts / pin_locked_until, while the app has been reading
        // and writing transaction_pin_attempts / transaction_pin_locked_until
        // / transaction_pin_set_at), so copy what is really there and fall
        // back to "no failed attempts, not locked" for anything missing.
        $attemptsSelect = CredentialsMigrationRunner::hasColumn($mainDb, 'users', 'transaction_pin_attempts')
            ? 'transaction_pin_attempts' : '0';
        $lockedSelect = CredentialsMigrationRunner::hasColumn($mainDb, 'users', 'transaction_pin_locked_until')
            ? 'transaction_pin_locked_until' : 'NULL';
        $setAtSelect = CredentialsMigrationRunner::hasColumn($mainDb, 'users', 'transaction_pin_set_at')
            ? 'transaction_pin_set_at' : 'NULL';

        $total = (int)$mainDb->query("SELECT COUNT(*) FROM users WHERE transaction_pin_hash IS NOT NULL AND transaction_pin_hash <> ''")->fetchColumn();
        $out("[{$label}] {$total} user(s) in users with a transaction_pin_hash to migrate.");
        if ($total === 0) {
            return ['total' => 0, 'migrated' => 0, 'kept' => 0];
        }

        // The WHERE on the DO UPDATE is the re-run guard: a row the app has
        // written since (new PIN, failed attempt, lockout) is left alone.
        $upsertStmt = $credDb->prepare("
            INSERT INTO user_transaction_pins
                (user_id, pin_hash, failed_attempts, locked_until, pin_set_at, copied_from_main_db, created_at, updated_at)
            VALUES (:id, :hash, :attempts, :locked, :set_at, TRUE, NOW(), NOW())
            ON CONFLICT (user_id) DO UPDATE
                SET pin_hash = EXCLUDED.pin_hash,
                    failed_attempts = EXCLUDED.failed_attempts,
                    locked_until = EXCLUDED.locked_until,
                    pin_set_at = EXCLUDED.pin_set_at,
                    updated_at = NOW()
                WHERE user_transaction_pins.copied_from_main_db
        ");

        $migrated = 0;
        $kept = 0;
        $seen = 0;
        $lastId = 0;

        while (true) {
            $stmt = $mainDb->prepare("
                SELECT user_id AS id, transaction_pin_hash AS pin_hash,
                       {$attemptsSelect} AS failed_attempts,
                       {$lockedSelect} AS locked_until,
                       {$setAtSelect} AS pin_set_at
                FROM users
                WHERE user_id > :lastId AND transaction_pin_hash IS NOT NULL AND transaction_pin_hash <> ''
                ORDER BY user_id ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break;
            }

            if ($apply) {
                $credDb->beginTransaction();
                try {
                    foreach ($rows as $row) {
                        $upsertStmt->execute([
                            ':id' => $row['id'],
                            ':hash' => $row['pin_hash'],
                            ':attempts' => (int)($row['failed_attempts'] ?? 0),
                            ':locked' => self::absoluteTimestamp($row['locked_until']),
                            ':set_at' => $row['pin_set_at'],
                        ]);
                        if ($upsertStmt->rowCount() > 0) {
                            $migrated++;
                        } else {
                            $kept++;
                        }
                    }
                    $credDb->commit();
                } catch (\Throwable $e) {
                    $credDb->rollBack();
                    $out("[{$label}] Batch starting after id {$lastId} failed: " . $e->getMessage());
                    throw $e;
                }
            }

            $seen += count($rows);
            $lastId = (int)end($rows)['id'];
            $out("[{$label}] " . ($apply ? "Processed" : "Would migrate") . " {$seen}/{$total} (through id {$lastId})...");
        }

        if ($apply) {
            $out("[{$label}] Copied or refreshed {$migrated}; left {$kept} alone because the app has changed them in the credentials database since they were copied.");
        } else {
            $migrated = $seen;
        }

        return ['total' => $total, 'migrated' => $migrated, 'kept' => $kept];
    }

    /**
     * Checks every user with a PIN in the main database, not a sample:
     * each must have a row in the credentials database, and each row the
     * app hasn't touched since it was copied must still hold the same hash.
     * Rows the app has changed since (a new PIN, a lockout) are counted, not
     * compared — they're expected to differ once the new code is live.
     *
     * Meant to pass before scripts/credentials_db/phase2_drop_transaction_pin_columns.sql
     * is run; stays meaningful after the code cutover.
     */
    public static function verify(PDO $mainDb, PDO $credDb, int $batchSize, callable $out): bool
    {
        $label = self::LABEL;

        $sourceCount = (int)$mainDb->query("SELECT COUNT(*) FROM users WHERE transaction_pin_hash IS NOT NULL AND transaction_pin_hash <> ''")->fetchColumn();
        $destCount = (int)$credDb->query("SELECT COUNT(*) FROM user_transaction_pins")->fetchColumn();
        $out("[{$label}] main DB users with a PIN: {$sourceCount} | credentials DB rows: {$destCount}");

        $missing = 0;
        $mismatched = 0;
        $identical = 0;
        $changedSince = 0;
        $lastId = 0;

        while (true) {
            $stmt = $mainDb->prepare("
                SELECT user_id AS id, transaction_pin_hash AS pin_hash
                FROM users
                WHERE user_id > :lastId AND transaction_pin_hash IS NOT NULL AND transaction_pin_hash <> ''
                ORDER BY user_id ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break;
            }

            $ids = array_map(fn(array $r): int => (int)$r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $destStmt = $credDb->prepare(
                "SELECT user_id, pin_hash, copied_from_main_db FROM user_transaction_pins WHERE user_id IN ({$placeholders})"
            );
            $destStmt->execute($ids);
            $destById = [];
            foreach ($destStmt->fetchAll(PDO::FETCH_ASSOC) as $destRow) {
                $destById[(int)$destRow['user_id']] = $destRow;
            }

            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $destRow = $destById[$id] ?? null;
                if ($destRow === null) {
                    $missing++;
                    $out("[{$label}] Missing from the credentials database: user_id={$id}");
                } elseif (!self::isTrue($destRow['copied_from_main_db'])) {
                    $changedSince++;
                } elseif ($destRow['pin_hash'] !== $row['pin_hash']) {
                    $mismatched++;
                    $out("[{$label}] Hash differs from the main database for user_id={$id}");
                } else {
                    $identical++;
                }
            }

            $lastId = (int)end($rows)['id'];
        }

        if ($missing > 0 || $mismatched > 0) {
            $out("[{$label}] FAILED — {$missing} missing, {$mismatched} out of date. Re-run apply to reconcile.");
            return false;
        }

        $out("[{$label}] OK — every PIN in the main database is in the credentials database: "
            . "{$identical} identical to the main database, {$changedSince} changed by the app since they were copied.");
        return true;
    }

    /**
     * users.transaction_pin_locked_until was written by PHP's date() — a
     * bare local time in the app's timezone (php.ini date.timezone). Read
     * it back in that same timezone and hand over an absolute instant with
     * its offset, so a lock still in force ends at the same moment
     * whatever timezone the credentials DB session runs in. A value that
     * already carries an offset (a timestamptz column) keeps it.
     */
    private static function absoluteTimestamp($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new DateTimeImmutable((string)$value))->format(DATE_ATOM);
    }

    /** Postgres hands booleans back as true/false; SQLite as 1/0. */
    private static function isTrue($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
