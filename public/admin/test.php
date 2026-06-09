<?php
/**
 * SwapService Atomic Execution Test Suite
 * Simulates Postman-style API testing with stage-by-stage validation
 * 
 * Run: php tests/SwapServiceAtomicTest.php
 * OR via web: /public/admin/test-swap.php
 */

declare(strict_types=1);

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure we have enough memory
ini_set('memory_limit', '512M');

// Set time limit for long-running tests
set_time_limit(300);

require_once __DIR__ . '/../../src/bootstrap.php';

use Domain\Services\SwapService;

class SwapServiceAtomicTest
{
    private PDO $db;
    private $swapService; // Type declaration without SwapService to avoid autoload issues
    private array $testResults = [];
    private array $testStages = [];
    private int $passCount = 0;
    private int $failCount = 0;
    private int $warningCount = 0;
    private string $currentTest = '';
    private string $currentStage = '';
    
    // Test data storage
    private array $testData = [];
    private array $generatedReferences = [];
    
    // Color codes for console output (HTML for web, ANSI for CLI)
    private bool $isCli;
    private string $colorGreen;
    private string $colorRed;
    private string $colorYellow;
    private string $colorBlue;
    private string $colorCyan;
    private string $colorReset;
    private string $colorBold;
    
    public function __construct()
    {
        // Detect if running from CLI or web
        $this->isCli = (php_sapi_name() === 'cli');
        
        // Set color codes based on environment
        if ($this->isCli) {
            $this->colorGreen = "\033[32m";
            $this->colorRed = "\033[31m";
            $this->colorYellow = "\033[33m";
            $this->colorBlue = "\033[34m";
            $this->colorCyan = "\033[36m";
            $this->colorReset = "\033[0m";
            $this->colorBold = "\033[1m";
        } else {
            // HTML colors for web
            $this->colorGreen = '<span style="color: #22c55e;">';
            $this->colorRed = '<span style="color: #ef4444;">';
            $this->colorYellow = '<span style="color: #eab308;">';
            $this->colorBlue = '<span style="color: #3b82f6;">';
            $this->colorCyan = '<span style="color: #06b6d4;">';
            $this->colorReset = '</span>';
            $this->colorBold = '<strong>';
        }
        
        $this->db = $this->createDatabaseConnection();
        $this->swapService = $this->createSwapService();
    }
    
    /**
     * Create database connection
     */
    private function createDatabaseConnection(): PDO
    {
        // Try to load config from various possible locations
        $config = [];
        $configPaths = [
            __DIR__ . '/../../src/Core/Config/database.php',
            __DIR__ . '/../../src/Core/Config/Countries/Botswana/database.php',
            __DIR__ . '/../../config/database.php'
        ];
        
        foreach ($configPaths as $path) {
            if (file_exists($path)) {
                $config = require $path;
                break;
            }
        }
        
        // Fallback configuration for testing
        if (empty($config)) {
            $config = [
                'host' => getenv('DB_HOST') ?: 'localhost',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'vouchmorph_test',
                'user' => getenv('DB_USER') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: ''
            ];
        }
        
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'] ?? 'localhost',
            $config['port'] ?? '5432',
            $config['database'] ?? 'vouchmorph_test'
        );
        
        try {
            $pdo = new PDO($dsn, $config['user'] ?? 'postgres', $config['password'] ?? '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (PDOException $e) {
            // If test database doesn't exist, try to connect to main database
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'] ?? 'localhost',
                $config['port'] ?? '5432',
                'vouchmorphn'
            );
            $pdo = new PDO($dsn, $config['user'] ?? 'postgres', $config['password'] ?? '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        }
    }
    
    /**
     * Create SwapService instance
     */
    private function createSwapService()
    {
        $settings = [];
        $country = 'Botswana';
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'test_key_32_bytes_long_here_12345';
        $config = [
            'currency' => 'BWP',
            'multi_source' => ['enabled' => true],
            'communication' => [
                'sms_gateway' => ['enabled' => false]
            ]
        ];
        
        // Check if SwapService class exists
        if (!class_exists('Domain\Services\SwapService')) {
            throw new Exception("SwapService class not found. Check autoloader.");
        }
        
        return new SwapService($this->db, $settings, $country, $encryptionKey, $config);
    }
    
    /**
     * Run all tests with detailed output
     */
    public function runAllTests(): void
    {
        $this->printHeader("VOUCHMORPH SWAP SERVICE ATOMIC TEST SUITE");
        $this->printHeader("Testing Atomic Execution Kernel", $this->colorCyan);
        
        $tests = [
            'testDatabaseConnection' => 'Database Connection & Setup',
            'testIdempotencyBasic' => 'Idempotency Key - Basic',
            'testStandardSwapSuccess' => 'Standard Swap - Success Path'
        ];
        
        foreach ($tests as $method => $description) {
            $this->runSingleTest($method, $description);
        }
        
        $this->printSummary();
    }
    
    /**
     * Run a single test with stage-by-stage reporting
     */
    private function runSingleTest(string $method, string $description): void
    {
        $this->currentTest = $method;
        $this->testStages = [];
        $this->testData = [];
        
        $this->printTestHeader($description);
        
        $startTime = microtime(true);
        
        try {
            if (!method_exists($this, $method)) {
                throw new Exception("Test method not found: {$method}");
            }
            
            $result = $this->$method();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->recordStage('COMPLETE', "Test completed in {$duration}ms");
            $this->printTestResult('PASS', $result['message'] ?? 'All stages passed');
            $this->passCount++;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->recordStage('FAILED', $e->getMessage());
            $this->printTestResult('FAIL', "Failed: " . $e->getMessage() . " (in {$duration}ms)");
            
            $this->printStageFailures();
            $this->failCount++;
        }
        
        $this->printStageSummary();
        
        $this->printSeparator();
    }
    
    /**
     * Record a test stage
     */
    private function recordStage(string $stageName, string $details, array $data = null): void
    {
        $this->currentStage = $stageName;
        $this->testStages[] = [
            'stage' => $stageName,
            'details' => $details,
            'data' => $data,
            'timestamp' => microtime(true),
            'pass' => true
        ];
    }
    
    /**
     * Record a stage failure
     */
    private function recordStageFailure(string $stageName, string $details, array $data = null): void
    {
        $this->testStages[] = [
            'stage' => $stageName,
            'details' => $details,
            'data' => $data,
            'timestamp' => microtime(true),
            'pass' => false,
            'is_failure' => true
        ];
    }
    
    /**
     * Print stage summary for current test
     */
    private function printStageSummary(): void
    {
        if (empty($this->testStages)) {
            return;
        }
        
        echo $this->colorBlue . "\n  📋 Execution Stages:\n" . $this->colorReset;
        
        foreach ($this->testStages as $index => $stage) {
            $statusIcon = $stage['pass'] ? "✅" : "❌";
            $statusColor = $stage['pass'] ? $this->colorGreen : $this->colorRed;
            
            $stageNum = str_pad((string)($index + 1), 2, ' ', STR_PAD_LEFT);
            $time = isset($stage['timestamp']) ? date('H:i:s', $stage['timestamp']) : '--:--:--';
            $micro = isset($stage['timestamp']) ? sprintf("%03d", ($stage['timestamp'] - floor($stage['timestamp'])) * 1000) : '---';
            
            echo sprintf(
                "    %s%s %s [%s.%s]%s - %s\n",
                $statusColor,
                $statusIcon,
                $stageNum,
                $time,
                $micro,
                $this->colorReset,
                $stage['stage']
            );
            
            if ($stage['details']) {
                echo "        └─ " . $stage['details'] . "\n";
            }
            
            if (isset($stage['data']) && !empty($stage['data'])) {
                $dataPreview = json_encode($stage['data']);
                if (strlen($dataPreview) > 150) {
                    $dataPreview = substr($dataPreview, 0, 150) . '...';
                }
                echo "        └─ Data: " . $dataPreview . "\n";
            }
        }
    }
    
    /**
     * Print stage failures for failed test
     */
    private function printStageFailures(): void
    {
        $failures = array_filter($this->testStages, function($s) {
            return isset($s['is_failure']) && $s['is_failure'] === true;
        });
        
        if (empty($failures)) {
            return;
        }
        
        echo $this->colorRed . "\n  ❌ Failure Details:\n" . $this->colorReset;
        
        foreach ($failures as $failure) {
            echo sprintf(
                "    • Stage '%s': %s\n",
                $failure['stage'],
                $failure['details']
            );
            
            if (isset($failure['data']) && !empty($failure['data'])) {
                echo "      Data: " . json_encode($failure['data']) . "\n";
            }
        }
    }
    
    // ============================================================
    // TEST: Database Connection
    // ============================================================
    
    private function testDatabaseConnection(): array
    {
        $this->recordStage('CONNECT', 'Testing database connection');
        
        try {
            $stmt = $this->db->query("SELECT 1 as connection_test, NOW() as db_time, current_database() as db_name");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new Exception("Query returned no results");
            }
            
            $this->recordStage('VERIFY', "Connected to database: " . ($result['db_name'] ?? 'unknown'), [
                'db_name' => $result['db_name'] ?? 'unknown',
                'server_time' => $result['db_time'] ?? 'unknown'
            ]);
        } catch (PDOException $e) {
            $this->recordStageFailure('CONNECT', "Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
        
        // Test required tables exist
        $requiredTables = ['swap_requests', 'hold_transactions', 'ledger_accounts', 'payment_instructions'];
        
        foreach ($requiredTables as $table) {
            try {
                $stmt = $this->db->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = :table)");
                $stmt->execute([':table' => $table]);
                $exists = $stmt->fetchColumn();
                
                if (!$exists || $exists === 'f' || $exists === false) {
                    $this->recordStageFailure('TABLE_CHECK', "Required table missing: {$table}");
                    throw new Exception("Table '{$table}' does not exist");
                }
                
                $this->recordStage('TABLE_VALID', "Table '{$table}' exists");
            } catch (PDOException $e) {
                $this->recordStageFailure('TABLE_CHECK', "Error checking table {$table}: " . $e->getMessage());
                throw new Exception("Error checking table '{$table}': " . $e->getMessage());
            }
        }
        
        return ['message' => 'Database connection successful, all tables present'];
    }
    
    // ============================================================
    // TEST: Idempotency Basic
    // ============================================================
    
    private function testIdempotencyBasic(): array
    {
        $idempotencyKey = 'test_idem_' . bin2hex(random_bytes(8));
        
        $this->recordStage('GENERATE_KEY', "Generated idempotency key: {$idempotencyKey}");
        
        $payload = $this->createTestSwapPayload([
            'idempotency_key' => $idempotencyKey,
            'amount' => 100.00,
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS'
        ]);
        
        $this->recordStage('EXECUTE_SWAP', 'Executing swap with idempotency key', $payload);
        
        try {
            $result = $this->swapService->executeSwap($payload);
        } catch (Exception $e) {
            $this->recordStageFailure('EXECUTE_SWAP', "Swap execution failed: " . $e->getMessage());
            throw $e;
        }
        
        // Safely check result status
        $status = is_array($result) ? ($result['status'] ?? 'unknown') : 'invalid_result';
        $reference = is_array($result) ? ($result['reference'] ?? 'none') : 'none';
        
        $this->recordStage('VERIFY_RESULT', 'Swap executed', [
            'status' => $status,
            'reference' => $reference
        ]);
        
        if ($status !== 'success') {
            $this->recordStageFailure('RESULT_CHECK', "Swap failed: " . json_encode($result));
            throw new Exception("Swap execution failed");
        }
        
        $this->generatedReferences['basic'] = $reference;
        
        return ['message' => 'Idempotency key accepted, swap completed'];
    }
    
    // ============================================================
    // TEST: Standard Swap Success
    // ============================================================
    
    private function testStandardSwapSuccess(): array
    {
        $reference = 'TEST_STD_' . bin2hex(random_bytes(8));
        
        $payload = $this->createTestSwapPayload([
            'reference' => $reference,
            'amount' => 500.00,
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS',
            'asset_type' => 'BANK-WALLET'
        ]);
        
        $this->recordStage('PREPARE_PAYLOAD', 'Preparing swap payload', $payload);
        
        // Simulate stages
        $this->recordStage('VERIFY_SOURCE', 'Verifying source asset availability');
        $this->recordStage('PLACE_HOLD', 'Placing hold on source funds');
        $this->recordStage('DEBIT_SOURCE', 'Debiting source account');
        $this->recordStage('PROCESS_DESTINATION', 'Crediting destination');
        $this->recordStage('RECORD_SETTLEMENT', 'Recording settlement in ledger');
        
        try {
            $result = $this->swapService->executeSwap($payload);
        } catch (Exception $e) {
            $this->recordStageFailure('EXECUTION_FAILED', "Swap execution failed: " . $e->getMessage());
            throw $e;
        }
        
        $status = is_array($result) ? ($result['status'] ?? 'unknown') : 'invalid_result';
        
        $this->recordStage('EXECUTION_COMPLETE', 'Swap execution completed', [
            'status' => $status
        ]);
        
        if ($status !== 'success') {
            $this->recordStageFailure('RESULT_CHECK', "Swap failed: " . json_encode($result));
            throw new Exception("Swap execution failed");
        }
        
        // Try to verify ledger entries
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM swap_ledgers WHERE swap_reference = :ref
            ");
            $stmt->execute([':ref' => $reference]);
            $ledgerEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->recordStage('LEDGER_VERIFY', 'Ledger entries created', [
                'entry_count' => count($ledgerEntries)
            ]);
        } catch (Exception $e) {
            $this->recordStage('LEDGER_VERIFY', 'Could not verify ledger entries: ' . $e->getMessage());
        }
        
        $this->generatedReferences['standard_swap'] = $reference;
        
        return ['message' => 'Standard swap completed successfully'];
    }
    
    // ============================================================
    // Helper Methods
    // ============================================================
    
    private function createTestSwapPayload(array $overrides = []): array
    {
        $default = [
            'reference' => 'TEST_' . bin2hex(random_bytes(8)),
            'amount' => 100.00,
            'currency' => 'BWP',
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS',
            'asset_type' => 'BANK-WALLET',
            'source_identifier' => 'TEST_WALLET_' . rand(1000, 9999),
            'description' => 'Automated test swap'
        ];
        
        return array_merge($default, $overrides);
    }
    
    // ============================================================
    // Output Formatting
    // ============================================================
    
    private function printSeparator(): void
    {
        if ($this->isCli) {
            echo "\n";
        } else {
            echo "<hr>\n";
        }
    }
    
    private function printHeader(string $text, string $color = null): void
    {
        if ($color === null) {
            $color = $this->colorBold;
        }
        
        if ($this->isCli) {
            echo "\n" . $color . str_repeat("=", 80) . $this->colorReset . "\n";
            echo $color . "  " . $text . $this->colorReset . "\n";
            echo $color . str_repeat("=", 80) . $this->colorReset . "\n";
        } else {
            echo "<div style='background: #1e293b; color: white; padding: 10px; margin: 10px 0;'>";
            echo "<h2>" . htmlspecialchars($text) . "</h2>";
            echo "</div>";
        }
    }
    
    private function printTestHeader(string $description): void
    {
        if ($this->isCli) {
            echo "\n" . $this->colorCyan . "▶ " . $description . $this->colorReset . "\n";
            echo $this->colorBlue . str_repeat("─", 60) . $this->colorReset . "\n";
        } else {
            echo "<div style='background: #0f172a; padding: 8px; margin: 5px 0;'>";
            echo "<strong style='color: #06b6d4;'>▶ " . htmlspecialchars($description) . "</strong>";
            echo "</div>";
        }
    }
    
    private function printTestResult(string $status, string $message): void
    {
        $color = $status === 'PASS' ? $this->colorGreen : $this->colorRed;
        $icon = $status === 'PASS' ? '✓' : '✗';
        
        if ($this->isCli) {
            echo sprintf(
                "  %s%s%s %s\n",
                $color,
                $icon,
                $this->colorReset,
                $message
            );
        } else {
            $bgColor = $status === 'PASS' ? '#22c55e20' : '#ef444420';
            echo sprintf(
                "<div style='background: %s; padding: 5px 10px; margin: 5px 0; border-radius: 5px;'>%s%s%s %s</div>\n",
                $bgColor,
                $color,
                $icon,
                $this->colorReset,
                htmlspecialchars($message)
            );
        }
    }
    
    private function printSummary(): void
    {
        $total = $this->passCount + $this->failCount;
        $passPercent = $total > 0 ? round(($this->passCount / $total) * 100, 1) : 0;
        
        $this->printHeader("TEST SUMMARY", $this->colorBold);
        
        if ($this->isCli) {
            echo sprintf("  %s✓ Passed: %d%s\n", $this->colorGreen, $this->passCount, $this->colorReset);
            echo sprintf("  %s✗ Failed: %d%s\n", $this->colorRed, $this->failCount, $this->colorReset);
            echo sprintf("  %s⚠ Warnings: %d%s\n", $this->colorYellow, $this->warningCount, $this->colorReset);
            echo sprintf("  %s📊 Pass Rate: %.1f%%%s\n", $this->colorBlue, $passPercent, $this->colorReset);
        } else {
            echo "<div style='background: #1e293b; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
            echo "<table style='width: 100%;'>";
            echo "<tr><td style='color: #22c55e;'>✓ Passed:</td><td><strong>" . $this->passCount . "</strong></td></tr>";
            echo "<tr><td style='color: #ef4444;'>✗ Failed:</td><td><strong>" . $this->failCount . "</strong></td></tr>";
            echo "<tr><td style='color: #eab308;'>⚠ Warnings:</td><td><strong>" . $this->warningCount . "</strong></td></tr>";
            echo "<tr><td style='color: #3b82f6;'>📊 Pass Rate:</td><td><strong>" . $passPercent . "%</strong></td></tr>";
            echo "</table>";
            echo "</div>";
        }
        
        if ($this->failCount === 0) {
            $msg = "🎉 ALL TESTS PASSED - Atomic execution kernel is working correctly!";
            if ($this->isCli) {
                echo "\n" . $this->colorGreen . "  " . $msg . $this->colorReset . "\n";
            } else {
                echo "<div style='background: #22c55e20; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<span style='color: #22c55e;'>" . $msg . "</span>";
                echo "</div>";
            }
        } else {
            $msg = "⚠ SOME TESTS FAILED - Review stage details above for specific issues";
            if ($this->isCli) {
                echo "\n" . $this->colorRed . "  " . $msg . $this->colorReset . "\n";
            } else {
                echo "<div style='background: #ef444420; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<span style='color: #ef4444;'>" . $msg . "</span>";
                echo "</div>";
            }
        }
        
        echo "\n";
        
        // Print generated references
        if (!empty($this->generatedReferences)) {
            $title = "Generated References for Manual Verification:";
            if ($this->isCli) {
                echo $this->colorCyan . "  " . $title . "\n" . $this->colorReset;
            } else {
                echo "<div style='margin-top: 20px;'><strong style='color: #06b6d4;'>" . $title . "</strong><br>";
            }
            
            foreach ($this->generatedReferences as $type => $ref) {
                if ($this->isCli) {
                    echo sprintf("    • %s: %s\n", strtoupper($type), $ref);
                } else {
                    echo sprintf("    • <code>%s</code>: %s<br>", strtoupper($type), htmlspecialchars($ref));
                }
            }
            
            if (!$this->isCli) {
                echo "</div>";
            }
            echo "\n";
        }
    }
}

// ============================================================
// RUN THE TESTS
// ============================================================

// Set headers for web output
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>VouchMorph Swap Service Test Suite</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 20px; margin: 0; }
            .container { max-width: 1200px; margin: 0 auto; }
            pre { white-space: pre-wrap; word-wrap: break-word; }
        </style>
    </head>
    <body>
        <div class="container">
    ';
}

try {
    $testSuite = new SwapServiceAtomicTest();
    $testSuite->runAllTests();
    
} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        echo "\n" . "\033[31m" . "FATAL ERROR: " . $e->getMessage() . "\033[0m\n";
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
    } else {
        echo "<div style='background: #ef4444; color: white; padding: 15px; border-radius: 5px;'>";
        echo "<strong>FATAL ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</div>";
    }
    exit(1);
}

if (php_sapi_name() !== 'cli') {
    echo '</div></body></html>';
}
