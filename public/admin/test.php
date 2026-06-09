<?php
// /var/www/html/public/admin/test-swap-fixed.php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);
ini_set('memory_limit', '512M');

$isCli = (php_sapi_name() === 'cli');

// Color codes for output
if ($isCli) {
    $GREEN = "\033[32m";
    $RED = "\033[31m";
    $YELLOW = "\033[33m";
    $BLUE = "\033[34m";
    $CYAN = "\033[36m";
    $RESET = "\033[0m";
    $BOLD = "\033[1m";
} else {
    $GREEN = '<span style="color: #22c55e;">';
    $RED = '<span style="color: #ef4444;">';
    $YELLOW = '<span style="color: #eab308;">';
    $BLUE = '<span style="color: #3b82f6;">';
    $CYAN = '<span style="color: #06b6d4;">';
    $RESET = '</span>';
    $BOLD = '<strong>';
}

function color(string $text, string $color): string {
    global $GREEN, $RED, $YELLOW, $BLUE, $CYAN, $RESET;
    
    switch ($color) {
        case 'green': return $GREEN . $text . $RESET;
        case 'red': return $RED . $text . $RESET;
        case 'yellow': return $YELLOW . $text . $RESET;
        case 'blue': return $BLUE . $text . $RESET;
        case 'cyan': return $CYAN . $text . $RESET;
        default: return $text;
    }
}

function printHeader(string $text): void {
    global $isCli, $BOLD;
    $line = str_repeat("=", 80);
    
    if ($isCli) {
        echo "\n" . $BOLD . $line . $RESET . "\n";
        echo $BOLD . "  " . $text . $RESET . "\n";
        echo $BOLD . $line . $RESET . "\n";
    } else {
        echo "<div style='background: #1e293b; padding: 10px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h2 style='margin: 0;'>" . htmlspecialchars($text) . "</h2>";
        echo "</div>";
    }
}

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
            'from_environment' => self::connectFromEnvironment(),
            'from_config_file' => self::connectFromConfigFile(),
            'from_docker_service' => self::connectFromDockerService(),
            'from_socket' => self::connectFromSocket(),
            'from_railway' => self::connectFromRailway()
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
        
        return self::attemptConnection($host, $port, $database, $user, $password);
    }
    
    /**
     * Connect using DATABASE_URL environment variable
     */
    private static function connectFromRailway(): ?PDO
    {
        $databaseUrl = getenv('DATABASE_URL');
        if (!$databaseUrl) {
            return null;
        }
        
        try {
            return new PDO($databaseUrl);
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * Connect using configuration file
     */
    private static function connectFromConfigFile(): ?PDO
    {
        $configPaths = [
            __DIR__ . '/../../src/Core/Config/database.php',
            __DIR__ . '/../../src/Core/Config/Countries/Botswana/database.php',
            __DIR__ . '/../../config/database.php',
            __DIR__ . '/../../.env'
        ];
        
        foreach ($configPaths as $path) {
            if (file_exists($path)) {
                if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                    $config = require $path;
                    if (is_array($config) && isset($config['host'])) {
                        return self::attemptConnection(
                            $config['host'],
                            $config['port'] ?? '5432',
                            $config['database'] ?? 'vouchmorphn',
                            $config['user'] ?? 'postgres',
                            $config['password'] ?? ''
                        );
                    }
                } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'env') {
                    $lines = file($path);
                    foreach ($lines as $line) {
                        if (strpos($line, 'DATABASE_URL=') === 0) {
                            $url = trim(substr($line, strlen('DATABASE_URL=')));
                            try {
                                return new PDO($url);
                            } catch (PDOException $e) {
                                // Continue to next method
                            }
                        }
                    }
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
                    $dsn = "pgsql:host={$socketPath};dbname=vouchmorphn";
                    $pdo = new PDO($dsn, 'postgres', '');
                    return $pdo;
                } catch (PDOException $e) {
                    // Try with default database
                    try {
                        $dsn = "pgsql:host={$socketPath};dbname=postgres";
                        $pdo = new PDO($dsn, 'postgres', '');
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
     * Attempt a database connection
     */
    private static function attemptConnection(string $host, string $port, string $database, string $user, string $password): ?PDO
    {
        try {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);
            $pdo = new PDO($dsn, $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (PDOException $e) {
            // Try with default postgres database
            try {
                $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $host, $port);
                $pdo = new PDO($dsn, $user, $password);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                return $pdo;
            } catch (PDOException $e2) {
                return null;
            }
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
                'DB_HOST' => getenv('DB_HOST'),
                'DB_PORT' => getenv('DB_PORT'),
                'DB_DATABASE' => getenv('DB_DATABASE'),
                'DB_USER' => getenv('DB_USER'),
                'DATABASE_URL' => getenv('DATABASE_URL') ? 'set' : 'not set'
            ],
            'php_version' => PHP_VERSION,
            'pdo_pgsql_loaded' => extension_loaded('pdo_pgsql')
        ];
    }
}

class SwapServiceAtomicTest
{
    private $db;
    private array $testStages = [];
    private int $passCount = 0;
    private int $failCount = 0;
    private bool $isCli;
    
    public function __construct()
    {
        $this->isCli = (php_sapi_name() === 'cli');
        $this->db = DatabaseConnectionManager::getConnection();
    }
    
    public function runAllTests(): void
    {
        printHeader("VOUCHMORPH SWAP SERVICE ATOMIC TEST SUITE");
        
        // First, test database connection
        $this->testDatabaseConnection();
        
        if ($this->db === null) {
            $this->printDatabaseDiagnostics();
            return;
        }
        
        // Add more tests here when database is connected
        $this->testBasicQuery();
    }
    
    private function testDatabaseConnection(): void
    {
        echo "\n" . color("▶ Database Connection Test", 'cyan') . "\n";
        echo color(str_repeat("─", 60), 'blue') . "\n";
        
        if ($this->db !== null) {
            echo color("  ✓ Database connected successfully", 'green') . "\n";
            $this->passCount++;
        } else {
            echo color("  ✗ Database connection failed", 'red') . "\n";
            $this->failCount++;
        }
    }
    
    private function testBasicQuery(): void
    {
        echo "\n" . color("▶ Basic Query Test", 'cyan') . "\n";
        echo color(str_repeat("─", 60), 'blue') . "\n";
        
        try {
            $stmt = $this->db->query("SELECT 1 as test, NOW() as current_time, current_database() as db_name");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo color("  ✓ Query executed successfully", 'green') . "\n";
            echo "    Database: " . ($result['db_name'] ?? 'unknown') . "\n";
            echo "    Server time: " . ($result['current_time'] ?? 'unknown') . "\n";
            $this->passCount++;
        } catch (PDOException $e) {
            echo color("  ✗ Query failed: " . $e->getMessage(), 'red') . "\n";
            $this->failCount++;
        }
    }
    
    private function printDatabaseDiagnostics(): void
    {
        echo "\n" . color("▶ Database Diagnostics", 'yellow') . "\n";
        echo color(str_repeat("─", 60), 'blue') . "\n";
        
        $diagnostics = DatabaseConnectionManager::getDiagnostics();
        
        echo "\nConnection Attempts:\n";
        foreach ($diagnostics['attempts'] as $method => $status) {
            $icon = $status === 'success' ? '✓' : '✗';
            $color = $status === 'success' ? 'green' : 'red';
            echo "  " . color($icon, $color) . " " . str_replace('_', ' ', ucfirst($method)) . "\n";
        }
        
        echo "\nEnvironment Variables:\n";
        foreach ($diagnostics['environment'] as $key => $value) {
            if ($value) {
                echo "  ✓ " . $key . " = " . $value . "\n";
            } else {
                echo "  ✗ " . $key . " = (not set)\n";
            }
        }
        
        echo "\nPHP Configuration:\n";
        echo "  Version: " . $diagnostics['php_version'] . "\n";
        echo "  PDO PgSQL: " . ($diagnostics['pdo_pgsql_loaded'] ? color("loaded", 'green') : color("not loaded", 'red')) . "\n";
        
        echo "\n" . color("Suggested Fixes:", 'yellow') . "\n";
        echo "  1. Start PostgreSQL: sudo systemctl start postgresql\n";
        echo "  2. Check PostgreSQL status: sudo systemctl status postgresql\n";
        echo "  3. For Docker: docker-compose up -d postgres\n";
        echo "  4. Set environment variables in your .env file:\n";
        echo "     DB_HOST=localhost\n";
        echo "     DB_PORT=5432\n";
        echo "     DB_DATABASE=vouchmorphn\n";
        echo "     DB_USER=postgres\n";
        echo "     DB_PASSWORD=your_password\n";
    }
    
    public function getResults(): array
    {
        return [
            'passed' => $this->passCount,
            'failed' => $this->failCount,
            'total' => $this->passCount + $this->failCount
        ];
    }
}

// Web output headers
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>VouchMorph Swap Service Test</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { 
                font-family: "Monaco", "Menlo", "Ubuntu Mono", monospace; 
                background: #0f172a; 
                color: #e2e8f0; 
                padding: 20px; 
                margin: 0;
                font-size: 14px;
            }
            .container { max-width: 1200px; margin: 0 auto; }
            pre { background: #1e293b; padding: 10px; border-radius: 5px; overflow-x: auto; }
        </style>
    </head>
    <body>
        <div class="container">
    ';
}

// Run the tests
$testSuite = new SwapServiceAtomicTest();
$testSuite->runAllTests();
$results = $testSuite->getResults();

// Print summary
printHeader("TEST SUMMARY");
echo "\n";
echo color("  ✓ Passed: " . $results['passed'], 'green') . "\n";
echo color("  ✗ Failed: " . $results['failed'], 'red') . "\n";
echo color("  📊 Total:  " . $results['total'], 'blue') . "\n";

if ($results['failed'] === 0) {
    echo "\n" . color("🎉 ALL TESTS PASSED!", 'green') . "\n";
}

if (!$isCli) {
    echo '</div></body></html>';
}
