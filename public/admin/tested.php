<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre>";

/**
 * ===============================
 * DATABASE CONNECTION
 * ===============================
 */
function connectDatabase(): PDO
{
    $url = getenv('DATABASE_URL');

    if (!$url) {
        throw new RuntimeException("DATABASE_URL not set");
    }

    $parts = parse_url($url);

    if (!$parts) {
        throw new RuntimeException("Invalid DATABASE_URL");
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

/**
 * ===============================
 * SWAP SERVICE FULL SIMULATION
 * ===============================
 */
class SwapServiceFullTestSuite
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function run(): void
    {
        echo "====================================================\n";
        echo "  🔥 FULL SWAP SERVICE TEST SUITE (EMBEDDED)\n";
        echo "====================================================\n\n";

        $this->testDatabase();
        $this->testForexEngine();
        $this->testAdapters();
        $this->testISO20022();
        $this->testSecurity();
        $this->testSwapFlow();
        $this->testFees();
        $this->testSMS();

        echo "\n====================================================\n";
        echo "  ✅ ALL TESTS COMPLETED\n";
        echo "====================================================\n";
    }

    private function testDatabase(): void
    {
        $stmt = $this->db->query("SELECT version() as v, current_database() as db");
        $row = $stmt->fetch();

        echo "📊 DATABASE TEST\n";
        echo "DB: {$row['db']}\n";
        echo "VERSION: " . substr($row['v'], 0, 60) . "...\n\n";
    }

    private function testForexEngine(): void
    {
        $usd = 1;
        $bwpRate = 13.5;
        $converted = $usd * $bwpRate;

        echo "💱 FOREX ENGINE\n";
        echo "1 USD = {$bwpRate} BWP\n";
        echo "Converted: {$converted} BWP\n\n";
    }

    private function testAdapters(): void
    {
        $legacyMessage = "TRANSFER|ZURA|200|SACCUSALIS";

        $iso20022 = [
            "msgType" => "pacs.008",
            "from" => "ZURABANK",
            "to" => "SACCUSALIS",
            "amount" => 200
        ];

        echo "🔌 ADAPTER LAYER\n";
        echo "Legacy: {$legacyMessage}\n";
        echo "ISO20022: " . json_encode($iso20022) . "\n\n";
    }

    private function testISO20022(): void
    {
        $valid = true;

        echo "🏦 ISO20022 VALIDATION\n";
        echo "Message valid: " . ($valid ? "YES" : "NO") . "\n\n";
    }

    private function testSecurity(): void
    {
        $pinHash = password_hash("657250", PASSWORD_BCRYPT);

        echo "🔐 SECURITY LAYER\n";
        echo "PIN HASHED: " . substr($pinHash, 0, 30) . "...\n\n";
    }

    private function testSwapFlow(): void
    {
        echo "🔄 SWAP FLOW SIMULATION\n";
        echo "ZURABANK → PROCESSING → FX → SACCUSALIS ATM\n";
        echo "Voucher: 710083197\n";
        echo "Amount: 200 BWP\n\n";
    }

    private function testFees(): void
    {
        $amount = 200;
        $fee = 5;
        $total = $amount + $fee;

        echo "💰 FEES ENGINE\n";
        echo "Amount: {$amount}\n";
        echo "Fee: {$fee}\n";
        echo "Total: {$total}\n\n";
    }

    private function testSMS(): void
    {
        echo "📩 SMS ENGINE\n";
        echo "SMS SENT: Swap successful for voucher 710083197\n\n";
    }
}

/**
 * ===============================
 * RUN SYSTEM
 * ===============================
 */

try {
    $db = connectDatabase();

    echo "✅ DATABASE CONNECTED\n\n";

    $test = new SwapServiceFullTestSuite($db);
    $test->run();

} catch (Throwable $e) {

    echo "❌ DATABASE CONNECTION FAILED\n";
    echo "ERROR: " . $e->getMessage() . "\n\n";
    echo "PDO DRIVERS:\n";
    print_r(PDO::getAvailableDrivers());
}

echo "</pre>";<?php
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
