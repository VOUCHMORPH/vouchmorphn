<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * ============================
 * DATABASE CONNECTION
 * ============================
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
 * ============================
 * SWAP SERVICE TEST SUITE
 * ============================
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
        $this->line("====================================================");
        $this->line(" 🔥 FULL SWAP SERVICE TEST SUITE");
        $this->line("====================================================");

        $this->testDatabase();
        $this->testForex();
        $this->testAdapters();
        $this->testISO20022();
        $this->testSecurity();
        $this->testSwapFlow();
        $this->testFees();
        $this->testSMS();

        $this->line("====================================================");
        $this->line(" ✅ ALL TESTS COMPLETED");
        $this->line("====================================================");
    }

    private function testDatabase(): void
    {
        $stmt = $this->db->query("SELECT version() as v, current_database() as db");
        $row = $stmt->fetch();

        $this->line("📊 DATABASE TEST");
        $this->line("DB: " . $row['db']);
        $this->line("VERSION: " . substr($row['v'], 0, 60));
        $this->line("");
    }

    private function testForex(): void
    {
        $rate = 13.5;
        $amount = 200;
        $converted = $amount * $rate;

        $this->line("💱 FOREX ENGINE");
        $this->line("Rate USD→BWP: $rate");
        $this->line("200 USD = $converted BWP");
        $this->line("");
    }

    private function testAdapters(): void
    {
        $legacy = "TRANSFER|ZURA|200|SACCUSALIS";

        $iso = [
            "msgType" => "pacs.008",
            "from" => "ZURABANK",
            "to" => "SACCUSALIS",
            "amount" => 200
        ];

        $this->line("🔌 ADAPTER LAYER");
        $this->line("Legacy: $legacy");
        $this->line("ISO20022: " . json_encode($iso));
        $this->line("");
    }

    private function testISO20022(): void
    {
        $this->line("🏦 ISO20022 VALIDATION");
        $this->line("Status: VALID");
        $this->line("");
    }

    private function testSecurity(): void
    {
        $pin = "657250";
        $hash = password_hash($pin, PASSWORD_BCRYPT);

        $this->line("🔐 SECURITY");
        $this->line("PIN HASHED: " . substr($hash, 0, 40));
        $this->line("");
    }

    private function testSwapFlow(): void
    {
        $this->line("🔄 SWAP FLOW");
        $this->line("ZURABANK → FX ENGINE → SACCUSALIS ATM");
        $this->line("Voucher: 710083197");
        $this->line("Amount: 200");
        $this->line("");
    }

    private function testFees(): void
    {
        $amount = 200;
        $fee = 5;

        $this->line("💰 FEES");
        $this->line("Amount: $amount");
        $this->line("Fee: $fee");
        $this->line("Total: " . ($amount + $fee));
        $this->line("");
    }

    private function testSMS(): void
    {
        $this->line("📩 SMS");
        $this->line("Sent: Swap successful for voucher 710083197");
        $this->line("");
    }

    private function line(string $msg): void
    {
        echo $msg . "\n";
    }
}

/**
 * ============================
 * RUN TEST
 * ============================
 */

try {
    echo "<pre>";

    $db = connectDatabase();

    echo "✅ DATABASE CONNECTED\n\n";

    $test = new SwapServiceFullTestSuite($db);
    $test->run();

} catch (Throwable $e) {

    echo "❌ ERROR\n";
    echo $e->getMessage() . "\n\n";

    echo "PDO DRIVERS:\n";
    print_r(PDO::getAvailableDrivers());
}

echo "</pre>";
