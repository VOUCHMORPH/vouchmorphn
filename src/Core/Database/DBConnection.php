<?php
namespace Core\Database;

use PDO;
use PDOException;
use Exception;

class DBConnection
{
    private static ?PDO $connection = null;
    private static array $instances = [];
    private static bool $connectionAttempted = false;
    private static ?array $currentConfig = null;

    /**
     * Parse Railway DATABASE_URL
     */
    private static function parseRailwayUrl(): ?array
    {
        $database_url = getenv('DATABASE_URL');
        
        if (!$database_url) {
            return null;
        }
        
        $db = parse_url($database_url);
        
        // Parse query string for SSL parameters
        $params = [];
        if (isset($db['query'])) {
            parse_str($db['query'], $params);
        }
        
        return [
            'host' => $db['host'] ?? 'localhost',
            'port' => $db['port'] ?? '5432',
            'dbname' => ltrim($db['path'] ?? '', '/'),
            'user' => $db['user'] ?? 'postgres',
            'password' => $db['pass'] ?? '',
            'sslmode' => $params['sslmode'] ?? 'require'
        ];
    }

    /**
     * Get database configuration from environment (fallback)
     */
    private static function getDbConfigFromEnv(): array
    {
        // First try Railway DATABASE_URL
        $railwayConfig = self::parseRailwayUrl();
        if ($railwayConfig) {
            return $railwayConfig;
        }
        
        // Get host to detect Railway
        $host = getenv('PG_HOST') ?: 'localhost';
        $isRailway = (strpos($host, 'railway') !== false || 
                      strpos($host, 'rlwy.net') !== false ||
                      $host === 'interchange.proxy.rlwy.net');
        
        // For Railway, force database name to 'railway'
        if ($isRailway) {
            return [
                'host' => $host,
                'port' => getenv('PG_PORT') ?: 5432,
                'dbname' => 'railway',
                'user' => getenv('PG_USER') ?: 'postgres',
                'password' => getenv('PG_PASS') ?: '',
                'sslmode' => 'require'
            ];
        }
        
        // Local development
        $dbname = getenv('PG_NAME') ?: (getenv('PG_DB_SWAP') ?: (getenv('PG_DB_CORE') ?: 'swap_system_bw'));
        
        return [
            'host' => $host,
            'port' => getenv('PG_PORT') ?: 5432,
            'dbname' => $dbname,
            'user' => getenv('PG_USER') ?: 'postgres',
            'password' => getenv('PG_PASS') ?: '',
            'sslmode' => getenv('PG_SSL_MODE') ?: 'prefer'
        ];
    }

    /**
     * Normalize config from different formats
     */
    private static function normalizeConfig(array $config): array
    {
        // Handle different key names (username vs user)
        $user = $config['username'] ?? $config['user'] ?? 'postgres';
        $password = $config['password'] ?? $config['pass'] ?? '';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $dbname = $config['database'] ?? $config['dbname'] ?? 'vouchmorph';
        $sslmode = $config['sslmode'] ?? 'prefer';
        
        // Handle type/driver
        $driver = $config['type'] ?? $config['driver'] ?? 'pgsql';
        
        return [
            'host' => $host,
            'port' => (int)$port,
            'dbname' => $dbname,
            'user' => $user,
            'password' => $password,
            'sslmode' => $sslmode,
            'driver' => $driver
        ];
    }

    /**
     * Get database connection with optional config
     */
    public static function getConnection(?array $config = null): ?PDO
    {
        // If config provided, use it (and reset connection)
        if ($config !== null) {
            self::$currentConfig = self::normalizeConfig($config);
            self::$connection = null;
            self::$connectionAttempted = false;
        }
        
        // Return existing connection if available
        if (self::$connection !== null) {
            return self::$connection;
        }

        if (self::$connectionAttempted) {
            return null;
        }

        self::$connectionAttempted = true;
        
        // Use provided config or fallback to environment
        $dbConfig = self::$currentConfig ?? self::getDbConfigFromEnv();

        try {
            // Build DSN based on driver
            $driver = $dbConfig['driver'] ?? 'pgsql';
            
            if ($driver === 'pgsql' || $driver === 'postgresql') {
                $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']}";
            } elseif ($driver === 'mysql') {
                $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4";
            } else {
                throw new PDOException("Unsupported driver: {$driver}");
            }
            
            // Add SSL mode if required
            if (isset($dbConfig['sslmode']) && $dbConfig['sslmode'] === 'require') {
                $dsn .= ";sslmode=require";
            }
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5
            ];
            
            error_log("[DBConnection] Connecting to: {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['dbname']}");
            
            self::$connection = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], $options);
            
            // Set search path for PostgreSQL
            if ($driver === 'pgsql' || $driver === 'postgresql') {
                self::$connection->exec("SET search_path TO public");
            }
            
            error_log("[DBConnection] Connection successful");
            
            return self::$connection;

        } catch (PDOException $e) {
            error_log("[DBConnection] Connection failed: " . $e->getMessage());
            self::$connection = null;
            return null;
        }
    }

    /**
     * Get database connection instance (alias for getConnection)
     */
    public static function getInstance(array $dbConfig = []): ?PDO
    {
        // If config provided, use it
        if (!empty($dbConfig)) {
            return self::getConnection($dbConfig);
        }
        return self::getConnection();
    }

    /**
     * Get database configuration
     */
    public function getConfig(): array
    {
        return self::$currentConfig ?? self::getDbConfigFromEnv();
    }

    /**
     * Check if database is connected
     */
    public static function isConnected(): bool
    {
        try {
            $conn = self::getConnection();
            if (!$conn) {
                return false;
            }
            $conn->query("SELECT 1")->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get connection for a specific database
     */
    public static function getDatabaseConnection(string $dbName, ?array $baseConfig = null): ?PDO
    {
        $config = $baseConfig ?? self::$currentConfig ?? self::getDbConfigFromEnv();
        $config['dbname'] = $dbName;
        
        $key = $dbName . serialize($config);
        
        if (!isset(self::$instances[$key])) {
            try {
                $driver = $config['driver'] ?? 'pgsql';
                
                if ($driver === 'pgsql' || $driver === 'postgresql') {
                    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";
                } elseif ($driver === 'mysql') {
                    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
                } else {
                    return null;
                }
                
                if (isset($config['sslmode']) && $config['sslmode'] === 'require') {
                    $dsn .= ";sslmode=require";
                }
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ];
                
                self::$instances[$key] = new PDO($dsn, $config['user'], $config['password'], $options);
                
            } catch (PDOException $e) {
                error_log("[DBConnection] Failed to connect to {$dbName}: " . $e->getMessage());
                return null;
            }
        }
        
        return self::$instances[$key] ?? null;
    }

    /**
     * Execute a query and return results
     */
    public static function query(string $sql, array $params = []): array
    {
        $conn = self::getConnection();
        if (!$conn) {
            return [];
        }
        
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[DBConnection] Query failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Execute a statement and return row count
     */
    public static function execute(string $sql, array $params = []): int
    {
        $conn = self::getConnection();
        if (!$conn) {
            return 0;
        }
        
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("[DBConnection] Execute failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Test connection and permissions
     */
    public static function testConnection(): array
    {
        $results = [
            'connection' => false,
            'user' => null,
            'database' => null,
            'permissions' => []
        ];
        
        $conn = self::getConnection();
        if (!$conn) {
            $results['error'] = 'No database connection';
            return $results;
        }
        
        try {
            $results['connection'] = true;
            $results['user'] = $conn->query("SELECT current_user")->fetchColumn();
            $results['database'] = $conn->query("SELECT current_database()")->fetchColumn();
            
            // Test permissions on key tables
            $tables = ['admins', 'participants', 'ledger_accounts', 'kyc_documents'];
            foreach ($tables as $table) {
                try {
                    $conn->query("SELECT 1 FROM $table LIMIT 0");
                    $results['permissions'][$table] = 'GRANTED';
                } catch (Exception $e) {
                    $results['permissions'][$table] = 'DENIED';
                }
            }
            
        } catch (Exception $e) {
            $results['error'] = $e->getMessage();
        }
        
        return $results;
    }

    /**
     * Get connection status
     */
    public static function getConnectionStatus(): array
    {
        $config = self::$currentConfig ?? self::getDbConfigFromEnv();
        return [
            'connected' => self::isConnected(),
            'config' => [
                'host' => $config['host'],
                'port' => $config['port'],
                'database' => $config['dbname'],
                'user' => $config['user']
            ]
        ];
    }
    
    /**
     * Reset connection (useful for testing or reconfiguration)
     */
    public static function reset(): void
    {
        self::$connection = null;
        self::$instances = [];
        self::$connectionAttempted = false;
        self::$currentConfig = null;
    }
}
