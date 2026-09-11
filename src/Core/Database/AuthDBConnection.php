<?php
namespace Core\Database;

use PDO;
use PDOException;
use Exception;

/**
 * SINGLE SOURCE OF TRUTH for the isolated credentials-store connection.
 * Railway-only, reads AUTH_DATABASE_URL (never DATABASE_URL) so that
 * usernames/password hashes/MFA secrets live in a database separate
 * from the main transactional data — mirrors DBConnection's pattern.
 */
class AuthDBConnection
{
    private static ?PDO $connection = null;
    private static bool $initialized = false;
    private static ?array $parsedConfig = null;

    /**
     * Parse AUTH_DATABASE_URL - the ONLY source of truth for the auth DB
     * @throws Exception if AUTH_DATABASE_URL is not set
     */
    private static function parseDatabaseUrl(): array
    {
        $url = getenv('AUTH_DATABASE_URL');

        if (!$url) {
            throw new Exception("AUTH_DATABASE_URL environment variable is required for credential storage");
        }

        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['host'])) {
            throw new Exception("Invalid AUTH_DATABASE_URL format: {$url}");
        }

        $password = $parsed['pass'] ?? '';

        error_log("[AuthDBConnection] Parsed AUTH_DATABASE_URL: host={$parsed['host']}, dbname=" . ltrim($parsed['path'] ?? '', '/'));

        return [
            'host' => $parsed['host'],
            'port' => $parsed['port'] ?? 5432,
            'dbname' => ltrim($parsed['path'] ?? '', '/'),
            'user' => $parsed['user'] ?? 'postgres',
            'password' => $password,
        ];
    }

    /**
     * Get the single auth-database connection instance
     */
    public static function getConnection(): ?PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        if (self::$initialized) {
            return null;
        }

        self::$initialized = true;

        try {
            $config = self::parseDatabaseUrl();
            self::$parsedConfig = $config;

            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;sslmode=require",
                $config['host'],
                $config['port'],
                $config['dbname']
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ];

            error_log("[AuthDBConnection] Connecting to: {$config['host']}:{$config['port']}/{$config['dbname']}");

            self::$connection = new PDO(
                $dsn,
                $config['user'],
                $config['password'],
                $options
            );

            self::$connection->query("SELECT 1");

            error_log("[AuthDBConnection] Connection successful");

            return self::$connection;

        } catch (PDOException $e) {
            error_log("[AuthDBConnection] PDO Error: " . $e->getMessage());
            self::$connection = null;
            return null;
        } catch (Exception $e) {
            error_log("[AuthDBConnection] Config Error: " . $e->getMessage());
            self::$connection = null;
            return null;
        }
    }

    public static function isConnected(): bool
    {
        if (self::$connection === null) {
            return false;
        }

        try {
            self::$connection->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            self::$connection = null;
            return false;
        }
    }

    public static function getStatus(): array
    {
        if (self::$parsedConfig === null) {
            return ['connected' => false, 'error' => 'Not initialized'];
        }

        return [
            'connected' => self::isConnected(),
            'host' => self::$parsedConfig['host'],
            'port' => self::$parsedConfig['port'],
            'database' => self::$parsedConfig['dbname'],
            'user' => self::$parsedConfig['user'],
            'has_password' => !empty(self::$parsedConfig['password']),
        ];
    }

    /**
     * Reset connection (for testing only)
     */
    public static function reset(): void
    {
        self::$connection = null;
        self::$initialized = false;
        self::$parsedConfig = null;
    }
}
