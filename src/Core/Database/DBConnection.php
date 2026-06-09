<?php
namespace Core\Database;

use PDO;
use PDOException;
use Exception;

/**
 * SINGLE SOURCE OF TRUTH for database connections
 * Railway-only, no fallback chains, no conflicting configs
 */
class DBConnection
{
    private static ?PDO $connection = null;
    private static bool $initialized = false;
    private static ?array $parsedConfig = null;

    /**
     * Parse DATABASE_URL - Railway's ONLY source of truth
     * @throws Exception if DATABASE_URL is not set
     */
    private static function parseDatabaseUrl(): array
    {
        $url = getenv('DATABASE_URL');
        
        if (!$url) {
            throw new Exception("DATABASE_URL environment variable is required on Railway");
        }
        
        $parsed = parse_url($url);
        
        if (!$parsed || !isset($parsed['host'])) {
            throw new Exception("Invalid DATABASE_URL format: {$url}");
        }
        
        // Extract password - may be null if not provided
        $password = $parsed['pass'] ?? '';
        
        error_log("[DBConnection] Parsed DATABASE_URL: host={$parsed['host']}, dbname=" . ltrim($parsed['path'] ?? '', '/'));
        
        return [
            'host' => $parsed['host'],
            'port' => $parsed['port'] ?? 5432,
            'dbname' => ltrim($parsed['path'] ?? '', '/'),
            'user' => $parsed['user'] ?? 'postgres',
            'password' => $password,
        ];
    }

    /**
     * Get the single database connection instance
     */
    public static function getConnection(): ?PDO
    {
        // Return existing connection if available
        if (self::$connection !== null) {
            return self::$connection;
        }
        
        // Prevent multiple connection attempts
        if (self::$initialized) {
            return null;
        }
        
        self::$initialized = true;
        
        try {
            $config = self::parseDatabaseUrl();
            self::$parsedConfig = $config;
            
            // Build DSN - Railway requires SSL
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
            
            error_log("[DBConnection] Connecting to: {$config['host']}:{$config['port']}/{$config['dbname']}");
            
            self::$connection = new PDO(
                $dsn,
                $config['user'],
                $config['password'],
                $options
            );
            
            // Verify connection
            self::$connection->query("SELECT 1");
            
            error_log("[DBConnection] Connection successful");
            
            return self::$connection;
            
        } catch (PDOException $e) {
            error_log("[DBConnection] PDO Error: " . $e->getMessage());
            self::$connection = null;
            return null;
        } catch (Exception $e) {
            error_log("[DBConnection] Config Error: " . $e->getMessage());
            self::$connection = null;
            return null;
        }
    }

    /**
     * Check if connected
     */
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

    /**
     * Get connection status for debugging
     */
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

// ============================================================================
// DEBUG: Log available PDO drivers at module load time
// ============================================================================
error_log("[DBConnection] Available PDO drivers: " . implode(", ", PDO::getAvailableDrivers()));
