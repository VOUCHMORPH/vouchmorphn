<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Connection Test</h1>";

define('PROJECT_ROOT', dirname(__DIR__, 2));

// Test 1: Check if LoadCountry.php exists
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
echo "<p>Config path: " . $configPath . "</p>";
echo "<p>File exists: " . (file_exists($configPath) ? 'YES' : 'NO') . "</p>";

if (!file_exists($configPath)) {
    die("LoadCountry.php not found!");
}

require_once $configPath;

try {
    $config = \Core\Config\LoadCountry::getConfig();
    echo "<p style='color:green'>✓ Configuration loaded successfully</p>";
    
    // Check database config
    echo "<h2>Database Configuration:</h2>";
    echo "<pre>";
    if (isset($config['db']['swap'])) {
        echo "db['swap'] exists\n";
        echo "Host: " . ($config['db']['swap']['host'] ?? 'N/A') . "\n";
        echo "Database: " . ($config['db']['swap']['database'] ?? 'N/A') . "\n";
        echo "User: " . ($config['db']['swap']['username'] ?? 'N/A') . "\n";
    } elseif (isset($config['swap_db'])) {
        echo "swap_db exists\n";
        echo "Host: " . ($config['swap_db']['host'] ?? 'N/A') . "\n";
        echo "Database: " . ($config['swap_db']['database'] ?? 'N/A') . "\n";
    } else {
        echo "No database configuration found in config!\n";
        echo "Available keys: " . print_r(array_keys($config), true);
    }
    echo "</pre>";
    
    // Test 2: Try direct database connection
    echo "<h2>Database Connection Test:</h2>";
    
    // Get database config
    $dbConfig = null;
    if (isset($config['db']['swap'])) {
        $dbConfig = $config['db']['swap'];
    } elseif (isset($config['swap_db'])) {
        $dbConfig = $config['swap_db'];
    }
    
    if ($dbConfig) {
        try {
            $dsn = sprintf(
                "pgsql:host=%s;port=%d;dbname=%s",
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['database']
            );
            echo "<p>DSN: " . $dsn . "</p>";
            
            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Test query
            $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
            $count = $stmt->fetchColumn();
            
            echo "<p style='color:green'>✓ Database connected successfully!</p>";
            echo "<p>Number of admins: " . $count . "</p>";
            
        } catch (PDOException $e) {
            echo "<p style='color:red'>✗ Database connection failed: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color:red'>✗ No database configuration found!</p>";
    }
    
    // Test 3: Check environment variables
    echo "<h2>Environment Variables:</h2>";
    echo "<pre>";
    echo "DATABASE_URL: " . (getenv('DATABASE_URL') ? 'SET' : 'NOT SET') . "\n";
    echo "DB_HOST: " . (getenv('DB_HOST') ?: 'NOT SET') . "\n";
    echo "DB_NAME: " . (getenv('DB_NAME') ?: 'NOT SET') . "\n";
    echo "DB_USER: " . (getenv('DB_USER') ?: 'NOT SET') . "\n";
    echo "</pre>";
    
} catch (Throwable $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
    echo "<p>File: " . $e->getFile() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
}
