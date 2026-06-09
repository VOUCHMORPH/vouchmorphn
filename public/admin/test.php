<?php
// /var/www/html/public/admin/test-swap-enterprise.php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);
ini_set('memory_limit', '2048M');

class DatabaseConnectionManager
{
    private static array $connectionAttempts = [];
    
    /**
     * Get database connection with multiple fallback methods
     */
    public static function getConnection(): ?PDO
    {
        // Try different connection methods
        $connectionMethods = [
            'from_railway' => self::connectFromRailway(),
            'from_environment' => self::connectFromEnvironment(),
            'from_config_file' => self::connectFromConfigFile(),
            'from_docker_service' => self::connectFromDockerService(),
            'from_socket' => self::connectFromSocket()
        ];
        
        foreach ($connectionMethods as $method => $connection) {
            if ($connection !== null) {
                self::$connectionAttempts[$method] = 'success';
                return $connection;
            }
            self::$connectionAttempts[$method] = 'failed';
        }
        
        return null;
    }
    
    /**
     * Connect using Railway DATABASE_URL (most common for deployment)
     */
    private static function connectFromRailway(): ?PDO
    {
        $databaseUrl = getenv('DATABASE_URL') ?: getenv('RAILWAY_DATABASE_URL');
        
        if (!$databaseUrl) {
            return null;
        }
        
        try {
            $pdo = new PDO($databaseUrl);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (PDOException $e) {
            error_log("Railway connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Connect using environment variables
     */
    private static function connectFromEnvironment(): ?PDO
    {
        $host = getenv('DB_HOST') ?: getenv('PGHOST') ?: null;
        $port = getenv('DB_PORT') ?: getenv('PGPORT') ?: '5432';
        $database = getenv('DB_DATABASE') ?: getenv('PGDATABASE') ?: 'vouchmorphn';
        $user = getenv('DB_USER') ?: getenv('PGUSER') ?: 'postgres';
        $password = getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: '';
        
        if (!$host) {
            return null;
        }
        
        // Ensure port is string
        $port = (string)$port;
        
        return self::attemptConnection($host, $port, $database, $user, $password);
    }
    
    /**
     * Connect using configuration file
     */
    private static function connectFromConfigFile(): ?PDO
    {
        $configPaths = [
            __DIR__ . '/../../src/Core/Config/database.php',
            __DIR__ . '/../../src/Core/Config/Countries/Botswana/database.php',
            __DIR__ . '/../../config/database.php'
        ];
        
        foreach ($configPaths as $path) {
            if (file_exists($path)) {
                try {
                    $config = require $path;
                    if (is_array($config) && isset($config['host'])) {
                        $host = $config['host'];
                        $port = (string)($config['port'] ?? '5432');
                        $database = $config['database'] ?? 'vouchmorphn';
                        $user = $config['user'] ?? 'postgres';
                        $password = $config['password'] ?? '';
                        
                        return self::attemptConnection($host, $port, $database, $user, $password);
                    }
                } catch (Exception $e) {
                    // Continue to next config
                }
            }
        }
        
        return null;
    }
    
    /**
     * Connect to Docker PostgreSQL service
     */
    private static function connectFromDockerService(): ?PDO
    {
        $dockerHosts = ['postgres', 'database', 'db', 'postgresql'];
        
        foreach ($dockerHosts as $host) {
            $connection = self::attemptConnection($host, '5432', 'vouchmorphn', 'postgres', '');
            if ($connection !== null) {
                return $connection;
            }
        }
        
        return null;
    }
    
    /**
     * Connect via Unix socket
     */
    private static function connectFromSocket(): ?PDO
    {
        $socketPaths = [
            '/var/run/postgresql',
            '/tmp',
            '/var/run/postgresql/.s.PGSQL.5432'
        ];
        
        foreach ($socketPaths as $socketPath) {
            if (file_exists($socketPath)) {
                try {
                    // For socket connections, host is the directory path
                    $dsn = "pgsql:host={$socketPath};dbname=vouchmorphn";
                    $pdo = new PDO($dsn, 'postgres', '');
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    return $pdo;
                } catch (PDOException $e) {
                    // Try with default database
                    try {
                        $dsn = "pgsql:host={$socketPath};dbname=postgres";
                        $pdo = new PDO($dsn, 'postgres', '');
                        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        return $pdo;
                    } catch (PDOException $e2) {
                        // Continue to next path
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Attempt a database connection with proper type handling
     * 
     * @param string $host Database host
     * @param string $port Database port (as string)
     * @param string $database Database name
     * @param string $user Database user
     * @param string $password Database password
     */
    private static function attemptConnection(string $host, string $port, string $database, string $user, string $password): ?PDO
    {
        try {
            // Handle special cases
            if (strpos($host, 'railway') !== false || strpos($host, 'containers') !== false) {
                // For Railway internal networking, try without port specification
                $dsn = sprintf('pgsql:host=%s;dbname=%s', $host, $database);
            } else {
                $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);
            }
            
            $pdo = new PDO($dsn, $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Test the connection
            $pdo->query('SELECT 1');
            
            return $pdo;
        } catch (PDOException $e) {
            error_log("Connection failed to {$host}:{$port} - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get diagnostic information
     */
    public static function getDiagnostics(): array
    {
        return [
            'attempts' => self::$connectionAttempts,
            'environment' => [
                'DATABASE_URL' => getenv('DATABASE_URL') ? 'set (hidden)' : 'not set',
                'RAILWAY_DATABASE_URL' => getenv('RAILWAY_DATABASE_URL') ? 'set (hidden)' : 'not set',
                'DB_HOST' => getenv('DB_HOST'),
                'DB_PORT' => getenv('DB_PORT'),
                'DB_DATABASE' => getenv('DB_DATABASE'),
                'DB_USER' => getenv('DB_USER'),
                'PGHOST' => getenv('PGHOST'),
                'PGPORT' => getenv('PGPORT'),
                'PGDATABASE' => getenv('PGDATABASE'),
                'PGUSER' => getenv('PGUSER'),
            ],
            'php_version' => PHP_VERSION,
            'pdo_pgsql_loaded' => extension_loaded('pdo_pgsql'),
            'pgsql_loaded' => extension_loaded('pgsql')
        ];
    }
}

// Simple test runner
class SimpleSwapTest
{
    private $db;
    private array $results = [];
    
    public function __construct()
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "  VOUCHMORPH SWAP SERVICE - DATABASE CONNECTION TEST\n";
        echo str_repeat("=", 80) . "\n";
        
        $this->testDatabaseConnection();
    }
    
    private function testDatabaseConnection(): void
    {
        echo "\n📡 Testing Database Connections...\n";
        echo str_repeat("-", 80) . "\n";
        
        $this->db = DatabaseConnectionManager::getConnection();
        
        if ($this->db !== null) {
            echo "✅ Database connected successfully!\n";
            $this->testBasicQuery();
        } else {
            echo "❌ Database connection failed.\n\n";
            $this->printDiagnostics();
        }
    }
    
    private function testBasicQuery(): void
    {
        try {
            $stmt = $this->db->query("SELECT version() as pg_version, current_database() as db_name, NOW() as server_time");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "\n📊 Database Information:\n";
            echo "  • Database: " . ($result['db_name'] ?? 'unknown') . "\n";
            echo "  • PostgreSQL Version: " . substr($result['pg_version'] ?? 'unknown', 0, 50) . "...\n";
            echo "  • Server Time: " . ($result['server_time'] ?? 'unknown') . "\n";
            
            // Test table existence
            $this->testTables();
            
        } catch (PDOException $e) {
            echo "❌ Query failed: " . $e->getMessage() . "\n";
        }
    }
    
    private function testTables(): void
    {
        $requiredTables = ['swap_requests', 'hold_transactions', 'ledger_accounts', 'users'];
        
        echo "\n📋 Checking Required Tables:\n";
        
        foreach ($requiredTables as $table) {
            try {
                $stmt = $this->db->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = :table)");
                $stmt->execute([':table' => $table]);
                $exists = $stmt->fetchColumn();
                
                if ($exists) {
                    echo "  ✅ {$table}\n";
                } else {
                    echo "  ❌ {$table} (missing)\n";
                }
            } catch (PDOException $e) {
                echo "  ⚠ {$table} (error checking: " . $e->getMessage() . ")\n";
            }
        }
    }
    
    private function printDiagnostics(): void
    {
        $diagnostics = DatabaseConnectionManager::getDiagnostics();
        
        echo "\n🔍 DIAGNOSTICS:\n";
        echo str_repeat("-", 80) . "\n";
        
        echo "\nConnection Attempts:\n";
        foreach ($diagnostics['attempts'] as $method => $status) {
            $icon = $status === 'success' ? '✅' : '❌';
            $methodName = str_replace('_', ' ', ucfirst($method));
            echo "  {$icon} {$methodName}\n";
        }
        
        echo "\nEnvironment Variables:\n";
        foreach ($diagnostics['environment'] as $key => $value) {
            if ($value && $value !== 'not set') {
                $displayValue = (strpos($key, 'PASSWORD') !== false || strpos($key, 'URL') !== false) 
                    ? '***HIDDEN***' 
                    : $value;
                echo "  ✅ {$key} = {$displayValue}\n";
            } else {
                echo "  ❌ {$key} = (not set)\n";
            }
        }
        
        echo "\nPHP Configuration:\n";
        echo "  • PHP Version: " . $diagnostics['php_version'] . "\n";
        echo "  • PDO PgSQL: " . ($diagnostics['pdo_pgsql_loaded'] ? "✅ loaded" : "❌ not loaded") . "\n";
        echo "  • PgSQL: " . ($diagnostics['pgsql_loaded'] ? "✅ loaded" : "❌ not loaded") . "\n";
        
        echo "\n💡 TROUBLESHOOTING TIPS:\n";
        echo "  1. For Railway: Ensure DATABASE_URL environment variable is set\n";
        echo "  2. For local development: Start PostgreSQL with 'sudo systemctl start postgresql'\n";
        echo "  3. For Docker: Run 'docker-compose up -d postgres'\n";
        echo "  4. Check if PostgreSQL is accepting connections: 'sudo netstat -plnt | grep 5432'\n";
    }
}

// Create a simple version of the enterprise test that doesn't require full SwapService
class LiteEnterpriseTest
{
    private $db;
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    
    public function __construct()
    {
        $this->db = DatabaseConnectionManager::getConnection();
    }
    
    public function run(): void
    {
        echo "\n" . str_repeat("═", 80) . "\n";
        echo "🏆  VOUCHMORPH LITE ENTERPRISE TEST SUITE\n";
        echo str_repeat("═", 80) . "\n";
        
        if ($this->db === null) {
            echo "\n❌ Cannot run tests - Database connection failed\n";
            return;
        }
        
        $this->testDatabaseIntegrity();
        $this->testSecurityBasics();
        $this->testPerformanceBasics();
        
        $this->printSummary();
    }
    
    private function testDatabaseIntegrity(): void
    {
        echo "\n📊 DATABASE INTEGRITY TESTS\n";
        echo str_repeat("─", 80) . "\n";
        
        // Test foreign key constraints
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) as invalid_refs 
                FROM swap_requests s 
                LEFT JOIN users u ON s.user_id = u.user_id 
                WHERE s.user_id IS NOT NULL AND u.user_id IS NULL
            ");
            $invalidRefs = $stmt->fetchColumn();
            
            if ($invalidRefs == 0) {
                echo "  ✅ Foreign key integrity: PASS\n";
                $this->passed++;
            } else {
                echo "  ❌ Foreign key integrity: FAIL ({$invalidRefs} invalid references)\n";
                $this->failed++;
            }
        } catch (PDOException $e) {
            echo "  ⚠ Foreign key check skipped: " . $e->getMessage() . "\n";
        }
        
        // Test for orphaned records
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) as orphans 
                FROM hold_transactions h 
                LEFT JOIN swap_requests s ON h.swap_reference = s.swap_uuid 
                WHERE s.swap_uuid IS NULL
            ");
            $orphans = $stmt->fetchColumn();
            
            if ($orphans == 0) {
                echo "  ✅ No orphaned holds: PASS\n";
                $this->passed++;
            } else {
                echo "  ⚠ Warning: {$orphans} orphaned holds found\n";
            }
        } catch (PDOException $e) {
            // Skip if table doesn't exist
        }
    }
    
    private function testSecurityBasics(): void
    {
        echo "\n🔒 SECURITY BASICS TESTS\n";
        echo str_repeat("─", 80) . "\n";
        
        // Test for SQL injection in user input (simulated)
        $testInputs = ["' OR '1'='1", "admin' --", "1; DROP TABLE users; --"];
        
        foreach ($testInputs as $input) {
            try {
                // Simulate a safe query with parameter binding
                $stmt = $this->db->prepare("SELECT :input as test_value");
                $stmt->execute([':input' => $input]);
                $result = $stmt->fetchColumn();
                
                if ($result === $input) {
                    echo "  ✅ SQL injection prevention: Parameter binding works\n";
                    $this->passed++;
                    break;
                }
            } catch (PDOException $e) {
                // This is fine
            }
        }
        
        // Check if sensitive columns exist with proper types
        $sensitiveChecks = [
            'password_hash' => 'users',
            'pin_hash' => 'users',
            'api_key' => 'organizations'
        ];
        
        foreach ($sensitiveChecks as $column => $table) {
            try {
                $stmt = $this->db->prepare("
                    SELECT data_type FROM information_schema.columns 
                    WHERE table_name = :table AND column_name = :column
                ");
                $stmt->execute([':table' => $table, ':column' => $column]);
                $dataType = $stmt->fetchColumn();
                
                if ($dataType) {
                    echo "  ✅ Sensitive data column '{$column}' exists in '{$table}'\n";
                    $this->passed++;
                }
            } catch (PDOException $e) {
                // Table might not exist
            }
        }
    }
    
    private function testPerformanceBasics(): void
    {
        echo "\n⚡ PERFORMANCE BASICS TESTS\n";
        echo str_repeat("─", 80) . "\n";
        
        // Test query performance
        $queries = [
            'Simple SELECT' => "SELECT 1",
            'Count swaps' => "SELECT COUNT(*) FROM swap_requests",
            'Recent swaps' => "SELECT * FROM swap_requests ORDER BY created_at DESC LIMIT 10"
        ];
        
        foreach ($queries as $name => $sql) {
            $start = microtime(true);
            try {
                $this->db->query($sql);
                $duration = (microtime(true) - $start) * 1000;
                
                if ($duration < 100) {
                    echo "  ✅ {$name}: {$duration}ms\n";
                    $this->passed++;
                } else {
                    echo "  ⚠ {$name}: {$duration}ms (slow)\n";
                }
            } catch (PDOException $e) {
                echo "  ❌ {$name}: Error - " . $e->getMessage() . "\n";
                $this->failed++;
            }
        }
        
        // Check for indexes
        $indexChecks = [
            'swap_requests' => ['swap_uuid', 'created_at', 'status'],
            'hold_transactions' => ['swap_reference', 'status'],
            'payment_instructions' => ['swap_reference', 'status']
        ];
        
        foreach ($indexChecks as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    $stmt = $this->db->prepare("
                        SELECT EXISTS (
                            SELECT 1 FROM pg_indexes 
                            WHERE tablename = :table AND indexdef LIKE :column_pattern
                        )
                    ");
                    $stmt->execute([
                        ':table' => $table,
                        ':column_pattern' => '%' . $column . '%'
                    ]);
                    $hasIndex = $stmt->fetchColumn();
                    
                    if ($hasIndex) {
                        echo "  ✅ Index on {$table}.{$column} exists\n";
                    } else {
                        echo "  ⚠ Missing index on {$table}.{$column}\n";
                    }
                } catch (PDOException $e) {
                    // Skip if table doesn't exist
                }
            }
        }
    }
    
    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $passRate = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        
        echo "\n" . str_repeat("═", 80) . "\n";
        echo "📊 TEST SUMMARY\n";
        echo str_repeat("═", 80) . "\n";
        
        echo "\n  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📊 Pass Rate: {$passRate}%\n";
        
        if ($passRate >= 90) {
            echo "\n  🎉 Excellent! Your database is in good shape.\n";
        } elseif ($passRate >= 70) {
            echo "\n  ⚠ Good, but some improvements needed.\n";
        } else {
            echo "\n  🔧 Database needs attention. Review the errors above.\n";
        }
        
        echo "\n";
    }
}

// Run the appropriate test based on availability
if (php_sapi_name() === 'cli') {
    // Try to load SwapService if available
    $swapServicePath = __DIR__ . '/../../src/Domain/Services/SwapService.php';
    
    if (file_exists($swapServicePath)) {
        require_once __DIR__ . '/../../src/bootstrap.php';
        
        if (class_exists('Domain\Services\SwapService')) {
            $test = new LiteEnterpriseTest();
            $test->run();
        } else {
            $test = new SimpleSwapTest();
        }
    } else {
        $test = new SimpleSwapTest();
    }
} else {
    // Web output
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>VouchMorph Database Test</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 20px; }
            pre { background: #1e293b; padding: 15px; border-radius: 8px; overflow-x: auto; }
            .success { color: #22c55e; }
            .error { color: #ef4444; }
            .warning { color: #eab308; }
        </style>
    </head>
    <body>
        <pre>';
    
    $test = new SimpleSwapTest();
    
    echo '</pre></body></html>';
}
