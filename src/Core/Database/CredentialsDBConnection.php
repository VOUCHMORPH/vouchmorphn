<?php
namespace Core\Database;

use PDO;
use PDOException;
use Exception;

/**
 * Connection to the isolated credentials database.
 *
 * Login secrets (username/email + password_hash) for users and admins live
 * here instead of the main application database, per the financial
 * regulations requirement to separate authentication secrets from
 * operational/business data. Deliberately its own class rather than a
 * parameter on DBConnection — the two databases have different
 * credentials, different access policies, and should never accidentally
 * share a connection or a transaction.
 *
 * Mirrors DBConnection's shape (singleton, URL-only config, same SSL/DSN
 * handling) so anyone who understands one understands the other.
 */
class CredentialsDBConnection
{
    private static ?PDO $connection = null;
    private static bool $initialized = false;
    private static ?array $parsedConfig = null;

    /**
     * Parse CREDENTIALS_DATABASE_URL — the only source of truth for this
     * connection. Never falls back to DATABASE_URL: if the credentials DB
     * isn't configured, callers must fail loudly rather than silently
     * writing secrets into the main database.
     *
     * @throws Exception if CREDENTIALS_DATABASE_URL is not set
     */
    private static function parseDatabaseUrl(): array
    {
        $url = getenv('CREDENTIALS_DATABASE_URL');

        if (!$url) {
            throw new Exception("CREDENTIALS_DATABASE_URL environment variable is required");
        }

        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['host'])) {
            throw new Exception("Invalid CREDENTIALS_DATABASE_URL format");
        }

        $password = $parsed['pass'] ?? '';

        error_log("[CredentialsDBConnection] Parsed CREDENTIALS_DATABASE_URL: host={$parsed['host']}, dbname=" . ltrim($parsed['path'] ?? '', '/'));

        return [
            'host' => $parsed['host'],
            'port' => $parsed['port'] ?? 5432,
            'dbname' => ltrim($parsed['path'] ?? '', '/'),
            'user' => $parsed['user'] ?? 'postgres',
            'password' => $password,
        ];
    }

    /**
     * Get the single credentials-database connection instance.
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

            error_log("[CredentialsDBConnection] Connecting to: {$config['host']}:{$config['port']}/{$config['dbname']}");

            self::$connection = new PDO(
                $dsn,
                $config['user'],
                $config['password'],
                $options
            );

            self::$connection->query("SELECT 1");

            error_log("[CredentialsDBConnection] Connection successful");

            return self::$connection;

        } catch (PDOException $e) {
            error_log("[CredentialsDBConnection] PDO Error: " . $e->getMessage());
            self::$connection = null;
            return null;
        } catch (Exception $e) {
            error_log("[CredentialsDBConnection] Config Error: " . $e->getMessage());
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
