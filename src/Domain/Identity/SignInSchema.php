<?php

namespace Domain\Identity;

use PDO;

/**
 * Whether a database already has the columns the email sign-in change
 * adds (database/migrations/2026_09_27_email_sign_in.sql and
 * scripts/credentials_db/2026_09_27_user_login_lockout.sql).
 *
 * On a deploy without a shell, those files are applied from
 * /admin/run_sign_in_migrations.php — which ships with the same code, so
 * the code always runs for a while before them. Every reader asks here
 * instead of assuming, and falls back to the old behaviour until the
 * column exists, so sign-in never breaks in that window.
 *
 * Postgres is asked through information_schema; SQLite (the unit tests'
 * stand-in database) through PRAGMA table_info. Answers are cached per
 * connection for the rest of the request.
 */
final class SignInSchema
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        $key = spl_object_id($db) . ':' . $table . '.' . $column;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $found = false;
                $quoted = '"' . str_replace('"', '""', $table) . '"';
                foreach ($db->query("PRAGMA table_info({$quoted})")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    if (($col['name'] ?? null) === $column) {
                        $found = true;
                        break;
                    }
                }
            } else {
                $stmt = $db->prepare(
                    "SELECT 1 FROM information_schema.columns
                     WHERE table_schema = current_schema() AND table_name = :t AND column_name = :c"
                );
                $stmt->execute([':t' => $table, ':c' => $column]);
                $found = (bool) $stmt->fetchColumn();
            }
        } catch (\Throwable $e) {
            error_log("[SignInSchema] Could not check {$table}.{$column}: " . $e->getMessage());
            $found = false;
        }

        return self::$cache[$key] = $found;
    }

    /** users.email_verified_at — main database. */
    public static function hasEmailVerifiedAt(PDO $mainDb): bool
    {
        return self::hasColumn($mainDb, 'users', 'email_verified_at');
    }

    /** user_credentials.failed_login_attempts / locked_until — credentials database. */
    public static function hasUserLoginLockout(PDO $credentialsDb): bool
    {
        return self::hasColumn($credentialsDb, 'user_credentials', 'failed_login_attempts')
            && self::hasColumn($credentialsDb, 'user_credentials', 'locked_until');
    }

    /** Drop cached answers, e.g. right after a migration has been applied. */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
