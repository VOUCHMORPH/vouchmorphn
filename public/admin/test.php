<?php
// /var/www/html/public/admin/test-swap-atomic-fixed.php

declare(strict_types=1);

/**
 * VouchMorph Swap Service Atomic Test Suite
 * Fixed version with proper type handling
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);
ini_set('memory_limit', '512M');

$isCli = (php_sapi_name() === 'cli');

// Color codes for output
if ($isCli) {
    $COLOR_GREEN = "\033[32m";
    $COLOR_RED = "\033[31m";
    $COLOR_YELLOW = "\033[33m";
    $COLOR_BLUE = "\033[34m";
    $COLOR_CYAN = "\033[36m";
    $COLOR_RESET = "\033[0m";
    $COLOR_BOLD = "\033[1m";
} else {
    $COLOR_GREEN = '<span style="color: #22c55e;">';
    $COLOR_RED = '<span style="color: #ef4444;">';
    $COLOR_YELLOW = '<span style="color: #eab308;">';
    $COLOR_BLUE = '<span style="color: #3b82f6;">';
    $COLOR_CYAN = '<span style="color: #06b6d4;">';
    $COLOR_RESET = '</span>';
    $COLOR_BOLD = '<strong>';
}

function color(string $text, string $color): string {
    global $COLOR_GREEN, $COLOR_RED, $COLOR_YELLOW, $COLOR_BLUE, $COLOR_CYAN, $COLOR_RESET;
    
    switch ($color) {
        case 'green': return $COLOR_GREEN . $text . $COLOR_RESET;
        case 'red': return $COLOR_RED . $text . $COLOR_RESET;
        case 'yellow': return $COLOR_YELLOW . $text . $COLOR_RESET;
        case 'blue': return $COLOR_BLUE . $text . $COLOR_RESET;
        case 'cyan': return $COLOR_CYAN . $text . $COLOR_RESET;
        default: return $text;
    }
}

class SwapServiceAtomicTest
{
    private $db;
    private $swapService;
    private array $testStages = [];
    private int $passCount = 0;
    private int $failCount = 0;
    private int $warningCount = 0;
    private string $currentTest = '';
    private array $generatedReferences = [];
    private bool $isCli;
    
    public function __construct()
    {
        $this->isCli = (php_sapi_name() === 'cli');
        $this->db = $this->createDatabaseConnection();
        $this->swapService = $this->createSwapService();
    }
    
    private function createDatabaseConnection(): PDO
    {
        $config = [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'port' => getenv('DB_PORT') ?: '5432',
            'database' => getenv('DB_DATABASE') ?: 'vouchmorphn',
            'user' => getenv('DB_USER') ?: 'postgres',
            'password' => getenv('DB_PASSWORD') ?: ''
        ];
        
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database']
        );
        
        try {
            $pdo = new PDO($dsn, $config['user'], $config['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    private function createSwapService()
    {
        $settings = [];
        $country = 'Botswana';
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'test_key_32_bytes_for_testing_only_12345';
        $config = [
            'currency' => 'BWP',
            'multi_source' => ['enabled' => true],
            'communication' => ['sms_gateway' => ['enabled' => false]]
        ];
        
        if (class_exists('Domain\Services\SwapService')) {
            return new \Domain\Services\SwapService($this->db, $settings, $country, $encryptionKey, $config);
        }
        
        // Mock for testing if SwapService doesn't exist
        return new class($this->db, $settings, $country, $encryptionKey, $config) {
            private $db;
            public function __construct($db, $settings, $country, $encryptionKey, $config) {
                $this->db = $db;
            }
            public function executeSwap(array $payload): array {
                return ['status' => 'success', 'reference' => 'MOCK_' . uniqid()];
            }
        };
    }
    
    public function runAllTests(): void
    {
        $this->printHeader("VOUCHMORPH SWAP SERVICE ATOMIC TEST SUITE");
        
        $tests = [
            'testDatabaseConnection' => 'Database Connection & Setup'
        ];
        
        foreach ($tests as $method => $description) {
            $this->runSingleTest($method, $description);
        }
        
        $this->printSummary();
    }
    
    private function runSingleTest(string $method, string $description): void
    {
        $this->currentTest = $method;
        $this->testStages = [];
        
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
        echo "\n";
    }
    
    private function recordStage(string $stageName, string $details, array $data = null): void
    {
        $this->testStages[] = [
            'stage' => $stageName,
            'details' => $details,
            'data' => $data,
            'timestamp' => microtime(true),
            'pass' => true
        ];
    }
    
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
    
    private function printStageSummary(): void
    {
        if (empty($this->testStages)) {
            return;
        }
        
        if ($this->isCli) {
            echo color("\n  📋 Execution Stages:\n", 'blue');
        } else {
            echo "<div style='margin-top: 10px;'><strong>📋 Execution Stages:</strong></div>";
        }
        
        foreach ($this->testStages as $index => $stage) {
            $statusIcon = $stage['pass'] ? "✅" : "❌";
            $statusColor = $stage['pass'] ? 'green' : 'red';
            $stageNum = str_pad((string)($index + 1), 2, ' ', STR_PAD_LEFT);
            
            // FIXED: Convert float timestamp to integer for date()
            $timestamp = (int)($stage['timestamp']);
            $micro = sprintf("%03d", ($stage['timestamp'] - $timestamp) * 1000);
            $timeStr = date('H:i:s', $timestamp) . '.' . $micro;
            
            if ($this->isCli) {
                echo sprintf(
                    "    %s %s [%s] - %s\n",
                    color($statusIcon, $statusColor),
                    $stageNum,
                    $timeStr,
                    $stage['stage']
                );
            } else {
                echo sprintf(
                    "<div style='margin-left: 20px;'>%s %s [%s] - <strong>%s</strong></div>",
                    color($statusIcon, $statusColor),
                    $stageNum,
                    $timeStr,
                    htmlspecialchars($stage['stage'])
                );
            }
            
            if ($stage['details']) {
                if ($this->isCli) {
                    echo "        └─ " . $stage['details'] . "\n";
                } else {
                    echo "<div style='margin-left: 40px; color: #64748b;'>└─ " . htmlspecialchars($stage['details']) . "</div>";
                }
            }
            
            if (isset($stage['data']) && !empty($stage['data'])) {
                $dataPreview = json_encode($stage['data']);
                if (strlen($dataPreview) > 150) {
                    $dataPreview = substr($dataPreview, 0, 150) . '...';
                }
                if ($this->isCli) {
                    echo "        └─ Data: " . $dataPreview . "\n";
                } else {
                    echo "<div style='margin-left: 40px; font-size: 12px; color: #475569;'>└─ Data: " . htmlspecialchars($dataPreview) . "</div>";
                }
            }
        }
    }
    
    private function printStageFailures(): void
    {
        $failures = array_filter($this->testStages, function($s) {
            return isset($s['is_failure']) && $s['is_failure'] === true;
        });
        
        if (empty($failures)) {
            return;
        }
        
        if ($this->isCli) {
            echo color("\n  ❌ Failure Details:\n", 'red');
        } else {
            echo "<div style='margin-top: 10px;'><strong style='color: #ef4444;'>❌ Failure Details:</strong></div>";
        }
        
        foreach ($failures as $failure) {
            if ($this->isCli) {
                echo sprintf(
                    "    • Stage '%s': %s\n",
                    $failure['stage'],
                    $failure['details']
                );
            } else {
                echo sprintf(
                    "<div style='margin-left: 20px;'>• Stage '<strong>%s</strong>': %s</div>",
                    htmlspecialchars($failure['stage']),
                    htmlspecialchars($failure['details'])
                );
            }
            
            if (isset($failure['data']) && !empty($failure['data'])) {
                $dataStr = json_encode($failure['data']);
                if ($this->isCli) {
                    echo "      Data: " . $dataStr . "\n";
                } else {
                    echo "<div style='margin-left: 40px; font-size: 12px;'>Data: " . htmlspecialchars($dataStr) . "</div>";
                }
            }
        }
    }
    
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
    
    private function printHeader(string $text): void
    {
        $line = str_repeat("=", 80);
        
        if ($this->isCli) {
            echo "\n" . color($line, 'bold') . "\n";
            echo color("  " . $text, 'bold') . "\n";
            echo color($line, 'bold') . "\n";
        } else {
            echo "<div style='background: #1e293b; padding: 10px; margin: 10px 0; border-radius: 5px;'>";
            echo "<h2 style='margin: 0;'>" . htmlspecialchars($text) . "</h2>";
            echo "</div>";
        }
    }
    
    private function printTestHeader(string $description): void
    {
        if ($this->isCli) {
            echo "\n" . color("▶ " . $description, 'cyan') . "\n";
            echo color(str_repeat("─", 60), 'blue') . "\n";
        } else {
            echo "<div style='background: #0f172a; padding: 8px; margin: 5px 0; border-radius: 3px;'>";
            echo "<strong style='color: #06b6d4;'>▶ " . htmlspecialchars($description) . "</strong>";
            echo "</div>";
        }
    }
    
    private function printTestResult(string $status, string $message): void
    {
        $icon = $status === 'PASS' ? '✓' : '✗';
        $color = $status === 'PASS' ? 'green' : 'red';
        
        if ($this->isCli) {
            echo "  " . color($icon, $color) . " " . $message . "\n";
        } else {
            $bgColor = $status === 'PASS' ? '#22c55e20' : '#ef444420';
            echo "<div style='background: {$bgColor}; padding: 5px 10px; margin: 5px 0; border-radius: 5px;'>";
            echo color($icon, $color) . " " . htmlspecialchars($message);
            echo "</div>";
        }
    }
    
    private function printSummary(): void
    {
        $total = $this->passCount + $this->failCount;
        $passPercent = $total > 0 ? round(($this->passCount / $total) * 100, 1) : 0;
        
        $this->printHeader("TEST SUMMARY");
        
        if ($this->isCli) {
            echo color("  ✓ Passed: {$this->passCount}\n", 'green');
            echo color("  ✗ Failed: {$this->failCount}\n", 'red');
            echo color("  ⚠ Warnings: {$this->warningCount}\n", 'yellow');
            echo color("  📊 Pass Rate: {$passPercent}%\n", 'blue');
        } else {
            echo "<div style='background: #1e293b; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
            echo "<table style='width: 100%;'>";
            echo "<tr><td style='color: #22c55e;'>✓ Passed:</td><td><strong>{$this->passCount}</strong></td>";
            echo "<td style='color: #ef4444;'>✗ Failed:</td><td><strong>{$this->failCount}</strong></td>";
            echo "<td style='color: #eab308;'>⚠ Warnings:</td><td><strong>{$this->warningCount}</strong></td>";
            echo "<td style='color: #3b82f6;'>📊 Pass Rate:</td><td><strong>{$passPercent}%</strong></td></tr>";
            echo "</table>";
            echo "</div>";
        }
        
        if ($this->failCount === 0) {
            $msg = "🎉 ALL TESTS PASSED!";
            if ($this->isCli) {
                echo "\n" . color($msg, 'green') . "\n";
            } else {
                echo "<div style='background: #22c55e20; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: center;'>";
                echo "<span style='color: #22c55e; font-size: 18px;'>{$msg}</span>";
                echo "</div>";
            }
        }
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
            code { font-family: monospace; background: #1e293b; padding: 2px 4px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <div class="container">
    ';
}

// Run the tests
try {
    $testSuite = new SwapServiceAtomicTest();
    $testSuite->runAllTests();
} catch (Exception $e) {
    if ($isCli) {
        echo color("\nFATAL ERROR: " . $e->getMessage() . "\n", 'red');
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
    } else {
        echo "<div style='background: #ef4444; color: white; padding: 15px; border-radius: 5px;'>";
        echo "<strong>FATAL ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</div>";
    }
    exit(1);
}

if (!$isCli) {
    echo '</div></body></html>';
}
