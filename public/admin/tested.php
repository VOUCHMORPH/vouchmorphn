<?php
declare(strict_types=1);

// Get absolute paths
$projectRoot = dirname(__DIR__, 2); // /var/www/html
$testFile = $projectRoot . '/tests/System/SwapServiceFullTestSuite.php';

// Debug: Show what we're trying to load
error_log("Looking for test file at: " . $testFile);

if (!file_exists($testFile)) {
    die("Test file not found at: " . $testFile . "\n\n" .
        "Project root: " . $projectRoot . "\n" .
        "Directory contents of tests/System/:\n" .
        shell_exec("ls -la " . $projectRoot . "/tests/System/ 2>&1"));
}

require_once $testFile;

// Also check if we need to load the test class
if (!class_exists('SwapServiceFullTestSuite')) {
    die("Class SwapServiceFullTestSuite not found in file: " . $testFile);
}

// Now try to connect to database
function connectDatabase(): PDO
{
    $url = getenv('DATABASE_URL');
    if (!$url) {
        throw new RuntimeException("DATABASE_URL not set");
    }

    $parts = parse_url($url);
    if (!$parts) {
        throw new RuntimeException("Invalid DATABASE_URL format");
    }

    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? 5432;
    $db   = ltrim($parts['path'] ?? '', '/');
    $user = $parts['user'] ?? '';
    $pass = $parts['pass'] ?? '';

    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

try {
    $db = connectDatabase();

    echo "<pre>";
    echo "✅ DATABASE CONNECTED\n\n";

    $test = new SwapServiceFullTestSuite($db);
    
    if (method_exists($test, 'run')) {
        $test->run();
    } elseif (method_exists($test, 'runAllTests')) {
        $test->runAllTests();
    } else {
        echo "No run method found in SwapServiceFullTestSuite\n";
    }

    echo "</pre>";

} catch (Throwable $e) {
    echo "<pre>";
    echo "❌ DATABASE CONNECTION FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nTrace: " . $e->getTraceAsString() . "\n";
    echo "</pre>";
}
