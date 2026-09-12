<?php
declare(strict_types=1);

namespace Infrastructure\Credentials;

use PDO;

/**
 * Shared logic for the one-time users/admins -> credentials-DB migration.
 *
 * Pulled out of scripts/management/migrate_credentials_to_secure_db.php so
 * the same, single implementation can be driven either from that CLI
 * script or from the browser-based admin trigger
 * (public/admin/run_credentials_migration.php) for environments without
 * shell access — two front ends, one migration logic, so they can't drift
 * apart from each other.
 *
 * Every method takes an $out callback (string $line): void instead of
 * printing directly, so the CLI script can write to STDOUT and the web
 * page can append to an HTML buffer without duplicating any of the
 * actual migration logic.
 */
class CredentialsMigrationRunner
{
    /**
     * True if $table has a column named $column, on the given connection.
     * The checked-in SQL migration files in this repo have drifted from
     * the live schema, so this introspects the real schema rather than
     * assuming column lists.
     */
    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.columns WHERE table_name = :t AND column_name = :c"
        );
        $stmt->execute([':t' => $table, ':c' => $column]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    }

    public static function migrateUsers(PDO $mainDb, PDO $credDb, bool $apply, int $batchSize, callable $out): array
    {
        $label = 'users';
        $total = (int)$mainDb->query("SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL")->fetchColumn();
        $out("[{$label}] {$total} row(s) in users with a password_hash to migrate.");
        if ($total === 0) {
            return ['total' => 0, 'migrated' => 0];
        }

        $migrated = 0;
        $lastId = 0;
        $upsertStmt = $credDb->prepare("
            INSERT INTO user_credentials (user_id, password_hash, created_at, updated_at)
            VALUES (:id, :hash, NOW(), NOW())
            ON CONFLICT (user_id) DO UPDATE SET password_hash = EXCLUDED.password_hash, updated_at = NOW()
        ");

        while (true) {
            $stmt = $mainDb->prepare("
                SELECT user_id AS id, password_hash
                FROM users
                WHERE user_id > :lastId AND password_hash IS NOT NULL
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
                        $upsertStmt->execute([':id' => $row['id'], ':hash' => $row['password_hash']]);
                    }
                    $credDb->commit();
                } catch (\Throwable $e) {
                    $credDb->rollBack();
                    $out("[{$label}] Batch starting after id {$lastId} failed: " . $e->getMessage());
                    throw $e;
                }
            }

            $migrated += count($rows);
            $lastId = (int)end($rows)['id'];
            $out("[{$label}] " . ($apply ? "Migrated" : "Would migrate") . " {$migrated}/{$total} (through id {$lastId})...");
        }

        return ['total' => $total, 'migrated' => $migrated];
    }

    public static function migrateAdmins(PDO $mainDb, PDO $credDb, bool $apply, int $batchSize, callable $out): array
    {
        $label = 'admins';
        $hasLockoutCols = self::hasColumn($mainDb, 'admins', 'failed_login_attempts') && self::hasColumn($mainDb, 'admins', 'locked_until');
        $attemptsSelect = $hasLockoutCols ? 'failed_login_attempts' : '0 AS failed_login_attempts';
        $lockedSelect = $hasLockoutCols ? 'locked_until' : 'NULL AS locked_until';

        $total = (int)$mainDb->query("SELECT COUNT(*) FROM admins WHERE password_hash IS NOT NULL")->fetchColumn();
        $out("[{$label}] {$total} row(s) in admins with a password_hash to migrate.");
        if ($total === 0) {
            return ['total' => 0, 'migrated' => 0];
        }

        $migrated = 0;
        $lastId = 0;
        $upsertStmt = $credDb->prepare("
            INSERT INTO admin_credentials (admin_id, password_hash, failed_login_attempts, locked_until, created_at, updated_at)
            VALUES (:id, :hash, :attempts, :locked, NOW(), NOW())
            ON CONFLICT (admin_id) DO UPDATE
                SET password_hash = EXCLUDED.password_hash,
                    failed_login_attempts = EXCLUDED.failed_login_attempts,
                    locked_until = EXCLUDED.locked_until,
                    updated_at = NOW()
        ");

        while (true) {
            $stmt = $mainDb->prepare("
                SELECT admin_id AS id, password_hash, {$attemptsSelect}, {$lockedSelect}
                FROM admins
                WHERE admin_id > :lastId AND password_hash IS NOT NULL
                ORDER BY admin_id ASC
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
                            ':hash' => $row['password_hash'],
                            ':attempts' => $row['failed_login_attempts'] ?? 0,
                            ':locked' => $row['locked_until'],
                        ]);
                    }
                    $credDb->commit();
                } catch (\Throwable $e) {
                    $credDb->rollBack();
                    $out("[{$label}] Batch starting after id {$lastId} failed: " . $e->getMessage());
                    throw $e;
                }
            }

            $migrated += count($rows);
            $lastId = (int)end($rows)['id'];
            $out("[{$label}] " . ($apply ? "Migrated" : "Would migrate") . " {$migrated}/{$total} (through id {$lastId})...");
        }

        return ['total' => $total, 'migrated' => $migrated];
    }

    public static function verifyTable(
        PDO $mainDb,
        PDO $credDb,
        string $label,
        string $sourceTable,
        string $idColumn,
        string $destTable,
        string $destIdColumn,
        callable $out
    ): bool {
        $sourceCount = (int)$mainDb->query("SELECT COUNT(*) FROM {$sourceTable} WHERE password_hash IS NOT NULL")->fetchColumn();
        $destCount = (int)$credDb->query("SELECT COUNT(*) FROM {$destTable}")->fetchColumn();

        $out("[{$label}] source rows with a password: {$sourceCount} | credentials DB rows: {$destCount}");

        if ($sourceCount !== $destCount) {
            $out("[{$label}] MISMATCH — re-run apply to reconcile.");
            return false;
        }

        $sample = $mainDb->query("
            SELECT {$idColumn} AS id, password_hash FROM {$sourceTable}
            WHERE password_hash IS NOT NULL ORDER BY random() LIMIT 25
        ")->fetchAll(PDO::FETCH_ASSOC);

        $mismatches = 0;
        $checkStmt = $credDb->prepare("SELECT password_hash FROM {$destTable} WHERE {$destIdColumn} = :id");
        foreach ($sample as $row) {
            $checkStmt->execute([':id' => $row['id']]);
            $destRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$destRow || $destRow['password_hash'] !== $row['password_hash']) {
                $mismatches++;
                $out("[{$label}] Spot-check mismatch for id={$row['id']}");
            }
        }

        if ($mismatches > 0) {
            $out("[{$label}] {$mismatches}/" . count($sample) . " spot-checked rows differ.");
            return false;
        }

        $out("[{$label}] OK — counts match and " . count($sample) . " spot-checked rows are identical.");
        return true;
    }
}
