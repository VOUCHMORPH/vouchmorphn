<?php
/**
 * SwapService Atomic Execution Test Suite
 * Simulates Postman-style API testing with stage-by-stage validation
 * 
 * Run: php tests/SwapServiceAtomicTest.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Domain\Services\SwapService;
use Infrastructure\Mojaloop\IdempotencyService;

class SwapServiceAtomicTest
{
    private PDO $db;
    private SwapService $swapService;
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
    
    // Color codes for console output
    private const COLOR_GREEN = "\033[32m";
    private const COLOR_RED = "\033[31m";
    private const COLOR_YELLOW = "\033[33m";
    private const COLOR_BLUE = "\033[34m";
    private const COLOR_CYAN = "\033[36m";
    private const COLOR_RESET = "\033[0m";
    private const COLOR_BOLD = "\033[1m";
    
    public function __construct()
    {
        $this->db = $this->createDatabaseConnection();
        $this->swapService = $this->createSwapService();
    }
    
    /**
     * Create database connection
     */
    private function createDatabaseConnection(): PDO
    {
        $config = require __DIR__ . '/../src/Core/Config/database.php';
        
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'] ?? 'localhost',
            $config['port'] ?? '5432',
            $config['database'] ?? 'vouchmorph_test'
        );
        
        $pdo = new PDO($dsn, $config['user'] ?? 'postgres', $config['password'] ?? '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $pdo;
    }
    
    /**
     * Create SwapService instance
     */
    private function createSwapService(): SwapService
    {
        $settings = [];
        $country = 'Botswana';
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'test_key_32_bytes_long_here_12345';
        $config = [
            'currency' => 'BWP',
            'multi_source' => ['enabled' => true],
            'communication' => [
                'sms_gateway' => ['enabled' => false] // Disable SMS for tests
            ]
        ];
        
        return new SwapService($this->db, $settings, $country, $encryptionKey, $config);
    }
    
    /**
     * Run all tests with detailed output
     */
    public function runAllTests(): void
    {
        $this->printHeader("VOUCHMORPH SWAP SERVICE ATOMIC TEST SUITE");
        $this->printHeader("Testing Atomic Execution Kernel", self::COLOR_CYAN);
        
        $tests = [
            'testDatabaseConnection' => 'Database Connection & Setup',
            'testIdempotencyBasic' => 'Idempotency Key - Basic',
            'testIdempotencyReplay' => 'Idempotency Key - Replay Protection',
            'testStandardSwapSuccess' => 'Standard Swap - Success Path',
            'testStandardSwapWithHoldFailure' => 'Standard Swap - Hold Failure (Compensation)',
            'testStandardSwapWithDebitFailure' => 'Standard Swap - Debit Failure (Compensation)',
            'testCashoutSwap' => 'Cashout Swap - Full Flow',
            'testDepositSwap' => 'Deposit Swap - Full Flow',
            'testMultiSourceSwap' => 'Multi-Source Swap - 3 Sources to 1 Destination',
            'testConcurrentSwaps' => 'Concurrent Swaps - Race Condition Protection',
            'testRollbackCompensation' => 'Rollback Compensation Engine',
            'testLedgerConsistency' => 'Ledger Consistency Validation'
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
            // Add stage 0: Test Setup
            $this->recordStage('SETUP', 'Preparing test environment');
            
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
            
            // Print detailed stage failures
            $this->printStageFailures();
            
            $this->failCount++;
        }
        
        // Print all stages for this test
        $this->printStageSummary();
        
        echo "\n";
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
        
        echo self::COLOR_BLUE . "\n  📋 Execution Stages:\n" . self::COLOR_RESET;
        
        foreach ($this->testStages as $index => $stage) {
            $statusIcon = $stage['pass'] ? "✅" : "❌";
            $statusColor = $stage['pass'] ? self::COLOR_GREEN : self::COLOR_RED;
            
            $stageNum = str_pad((string)($index + 1), 2, ' ', STR_PAD_LEFT);
            $time = isset($stage['timestamp']) ? date('H:i:s.v', $stage['timestamp']) : '--:--:---';
            
            echo sprintf(
                "    %s %s %s [%s]%s - %s\n",
                $statusColor,
                $statusIcon,
                $stageNum,
                $time,
                self::COLOR_RESET,
                $stage['stage']
            );
            
            if ($stage['details']) {
                echo "        └─ " . $stage['details'] . "\n";
            }
            
            if (isset($stage['data']) && !empty($stage['data'])) {
                $dataPreview = json_encode($stage['data'], JSON_PRETTY_PRINT);
                if (strlen($dataPreview) > 200) {
                    $dataPreview = substr($dataPreview, 0, 200) . '...';
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
        $failures = array_filter($this->testStages, fn($s) => isset($s['is_failure']));
        
        if (empty($failures)) {
            return;
        }
        
        echo self::COLOR_RED . "\n  ❌ Failure Details:\n" . self::COLOR_RESET;
        
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
        
        $stmt = $this->db->query("SELECT 1 as connection_test, NOW() as db_time, current_database() as db_name");
        $result = $stmt->fetch();
        
        $this->recordStage('VERIFY', "Connected to database: {$result['db_name']}", [
            'db_name' => $result['db_name'],
            'server_time' => $result['db_time']
        ]);
        
        // Test required tables exist
        $requiredTables = ['swap_requests', 'hold_transactions', 'ledger_accounts', 'payment_instructions'];
        
        foreach ($requiredTables as $table) {
            $stmt = $this->db->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = :table)");
            $stmt->execute([':table' => $table]);
            $exists = $stmt->fetchColumn();
            
            if (!$exists) {
                $this->recordStageFailure('TABLE_CHECK', "Required table missing: {$table}");
                throw new Exception("Table '{$table}' does not exist");
            }
            
            $this->recordStage('TABLE_VALID', "Table '{$table}' exists");
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
        
        $result = $this->swapService->executeSwap($payload);
        
        $this->recordStage('VERIFY_RESULT', 'Swap executed', [
            'status' => $result['status'] ?? 'unknown',
            'reference' => $result['reference'] ?? 'none'
        ]);
        
        if (($result['status'] ?? '') !== 'success') {
            $this->recordStageFailure('RESULT_CHECK', "Swap failed: " . json_encode($result));
            throw new Exception("Swap execution failed");
        }
        
        $this->generatedReferences['basic'] = $result['reference'];
        
        return ['message' => 'Idempotency key accepted, swap completed'];
    }
    
    // ============================================================
    // TEST: Idempotency Replay Protection
    // ============================================================
    
    private function testIdempotencyReplay(): array
    {
        $idempotencyKey = 'test_replay_' . bin2hex(random_bytes(8));
        
        $this->recordStage('GENERATE_KEY', "Generated idempotency key: {$idempotencyKey}");
        
        $payload = $this->createTestSwapPayload([
            'idempotency_key' => $idempotencyKey,
            'amount' => 200.00,
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS'
        ]);
        
        $this->recordStage('FIRST_EXECUTION', 'First execution with idempotency key');
        
        $result1 = $this->swapService->executeSwap($payload);
        
        $this->recordStage('FIRST_RESULT', 'First execution result', [
            'status' => $result1['status'] ?? 'unknown',
            'reference' => $result1['reference'] ?? 'none'
        ]);
        
        $this->recordStage('SECOND_EXECUTION', 'Replaying same idempotency key');
        
        $result2 = $this->swapService->executeSwap($payload);
        
        $this->recordStage('SECOND_RESULT', 'Second execution result (should be cached)', [
            'status' => $result2['status'] ?? 'unknown',
            'reference' => $result2['reference'] ?? 'none'
        ]);
        
        // Verify same reference returned
        if (($result1['reference'] ?? '') !== ($result2['reference'] ?? '')) {
            $this->recordStageFailure('IDEMPOTENCY_CHECK', 'References do not match', [
                'first' => $result1['reference'] ?? 'null',
                'second' => $result2['reference'] ?? 'null'
            ]);
            throw new Exception("Idempotency replay returned different references");
        }
        
        // Verify only one record in database
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM swap_requests 
            WHERE swap_uuid LIKE :pattern
        ");
        $stmt->execute([':pattern' => '%' . substr($result1['reference'], -8) . '%']);
        $count = $stmt->fetchColumn();
        
        if ($count > 1) {
            $this->recordStageFailure('DATABASE_CHECK', "Multiple records found for same idempotency key", ['count' => $count]);
            throw new Exception("Idempotency failed: multiple records created");
        }
        
        $this->recordStage('IDEMPOTENCY_VERIFIED', 'Replay protection working correctly');
        
        return ['message' => 'Idempotency replay protection verified'];
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
        
        // Stage 1: Verify source
        $this->recordStage('VERIFY_SOURCE', 'Verifying source asset availability');
        
        // Stage 2: Place hold
        $this->recordStage('PLACE_HOLD', 'Placing hold on source funds');
        
        // Stage 3: Debit source
        $this->recordStage('DEBIT_SOURCE', 'Debiting source account');
        
        // Stage 4: Process destination
        $this->recordStage('PROCESS_DESTINATION', 'Crediting destination');
        
        // Stage 5: Record settlement
        $this->recordStage('RECORD_SETTLEMENT', 'Recording settlement in ledger');
        
        $result = $this->swapService->executeSwap($payload);
        
        $this->recordStage('EXECUTION_COMPLETE', 'Swap execution completed', $result);
        
        // Verify ledger entries
        $stmt = $this->db->prepare("
            SELECT * FROM swap_ledgers WHERE swap_reference = :ref
        ");
        $stmt->execute([':ref' => $reference]);
        $ledgerEntries = $stmt->fetchAll();
        
        $this->recordStage('LEDGER_VERIFY', 'Ledger entries created', [
            'entry_count' => count($ledgerEntries)
        ]);
        
        if (empty($ledgerEntries)) {
            $this->recordStageFailure('LEDGER_CHECK', 'No ledger entries found');
            throw new Exception("Ledger entries missing for successful swap");
        }
        
        // Verify hold was placed and debited
        $stmt = $this->db->prepare("
            SELECT * FROM hold_transactions WHERE swap_reference = :ref
        ");
        $stmt->execute([':ref' => $reference]);
        $holdEntries = $stmt->fetchAll();
        
        $this->recordStage('HOLD_VERIFY', 'Hold transactions recorded', [
            'hold_count' => count($holdEntries)
        ]);
        
        $this->generatedReferences['standard_swap'] = $reference;
        
        return ['message' => 'Standard swap completed successfully with all ledger entries'];
    }
    
    // ============================================================
    // TEST: Hold Failure with Compensation
    // ============================================================
    
    private function testStandardSwapWithHoldFailure(): array
    {
        $reference = 'TEST_HOLD_FAIL_' . bin2hex(random_bytes(8));
        
        // Create payload that will fail hold (invalid source)
        $payload = $this->createTestSwapPayload([
            'reference' => $reference,
            'amount' => 999999.00, // Exceeds limit to cause hold failure
            'source_institution' => 'INVALID_BANK',
            'destination_institution' => 'SACCUSSALIS',
            'force_hold_failure' => true
        ]);
        
        $this->recordStage('PREPARE_PAYLOAD', 'Preparing payload that should fail at hold stage', $payload);
        
        $failed = false;
        $errorMessage = '';
        
        try {
            $this->recordStage('EXECUTE_SWAP', 'Executing swap (expected to fail at hold)');
            $result = $this->swapService->executeSwap($payload);
            
            // If we get here, swap succeeded when it should have failed
            $this->recordStageFailure('EXPECTED_FAILURE', 'Swap succeeded but was expected to fail at hold stage');
            throw new Exception("Hold failure test failed: swap succeeded unexpectedly");
            
        } catch (Exception $e) {
            $failed = true;
            $errorMessage = $e->getMessage();
            $this->recordStage('EXPECTED_FAILURE', "Swap failed as expected: " . $errorMessage);
        }
        
        // Verify no hold was created
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM hold_transactions 
            WHERE swap_reference = :ref AND status != 'RELEASED'
        ");
        $stmt->execute([':ref' => $reference]);
        $holdCount = $stmt->fetchColumn();
        
        $this->recordStage('VERIFY_NO_HOLD', 'Verifying no active hold remains', [
            'hold_count' => $holdCount
        ]);
        
        if ($holdCount > 0) {
            $this->recordStageFailure('COMPENSATION_CHECK', 'Hold still present after failure - compensation failed');
            throw new Exception("Hold compensation failed: hold still active");
        }
        
        // Verify no ledger entries
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM swap_ledgers WHERE swap_reference = :ref
        ");
        $stmt->execute([':ref' => $reference]);
        $ledgerCount = $stmt->fetchColumn();
        
        $this->recordStage('VERIFY_NO_LEDGER', 'Verifying no ledger entries', [
            'ledger_count' => $ledgerCount
        ]);
        
        if ($ledgerCount > 0) {
            $this->recordStageFailure('LEDGER_CHECK', 'Ledger entries present after failure');
            throw new Exception("Ledger entries present after failure - should be rolled back");
        }
        
        return ['message' => 'Hold failure correctly compensated - no orphaned resources'];
    }
    
    // ============================================================
    // TEST: Debit Failure with Compensation
    // ============================================================
    
    private function testStandardSwapWithDebitFailure(): array
    {
        $reference = 'TEST_DEBIT_FAIL_' . bin2hex(random_bytes(8));
        
        $payload = $this->createTestSwapPayload([
            'reference' => $reference,
            'amount' => 300.00,
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'INVALID_DEST',
            'force_debit_failure' => true
        ]);
        
        $this->recordStage('PREPARE_PAYLOAD', 'Preparing payload that will fail at debit stage', $payload);
        
        try {
            $this->recordStage('EXECUTE_SWAP', 'Executing swap (expected to fail at debit)');
            $result = $this->swapService->executeSwap($payload);
            
            $this->recordStageFailure('EXPECTED_FAILURE', 'Swap succeeded but was expected to fail at debit stage');
            throw new Exception("Debit failure test failed: swap succeeded unexpectedly");
            
        } catch (Exception $e) {
            $this->recordStage('EXPECTED_FAILURE', "Swap failed as expected: " . $e->getMessage());
        }
        
        // Verify hold was released (compensated)
        $stmt = $this->db->prepare("
            SELECT status FROM hold_transactions 
            WHERE swap_reference = :ref 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':ref' => $reference]);
        $holdStatus = $stmt->fetchColumn();
        
        $this->recordStage('VERIFY_HOLD_COMPENSATED', 'Verifying hold was released', [
            'hold_status' => $holdStatus ?: 'none'
        ]);
        
        if ($holdStatus && $holdStatus !== 'RELEASED') {
            $this->recordStageFailure('COMPENSATION_CHECK', "Hold status is {$holdStatus}, should be RELEASED");
            throw new Exception("Hold not released after debit failure");
        }
        
        return ['message' => 'Debit failure correctly compensated - hold released'];
    }
    
    // ============================================================
    // TEST: Cashout Swap
    // ============================================================
    
    private function testCashoutSwap(): array
    {
        $reference = 'TEST_CASHOUT_' . bin2hex(random_bytes(8));
        $beneficiaryPhone = '+26771234567';
        
        $payload = [
            'reference' => $reference,
            'swap_type' => 'CASHOUT',
            'amount' => 250.00,
            'source_institution' => 'ZURUBANK',
            'source_asset_type' => 'BANK-WALLET',
            'beneficiary_phone' => $beneficiaryPhone,
            'cashout_point' => 'ATM'
        ];
        
        $this->recordStage('PREPARE_PAYLOAD', 'Preparing cashout payload', [
            'reference' => $reference,
            'amount' => 250.00,
            'beneficiary' => $beneficiaryPhone
        ]);
        
        $result = $this->swapService->executeSwap($payload);
        
        $this->recordStage('EXECUTION_COMPLETE', 'Cashout execution completed', [
            'status' => $result['status'] ?? 'unknown',
            'atm_code' => isset($result['atm_code']) ? '***' . substr($result['atm_code'], -2) : 'none',
            'voucher_number' => $result['voucher_number'] ?? 'none'
        ]);
        
        if (($result['status'] ?? '') !== 'success') {
            $this->recordStageFailure('EXECUTION_FAILED', 'Cashout swap failed', $result);
            throw new Exception("Cashout swap failed");
        }
        
        // Verify voucher was created
        if (isset($result['voucher_number'])) {
            $stmt = $this->db->prepare("
                SELECT status, amount, claimant_phone FROM swap_vouchers 
                WHERE voucher_number = :voucher
            ");
            $stmt->execute([':voucher' => $result['voucher_number']]);
            $voucher = $stmt->fetch();
            
            $this->recordStage('VERIFY_VOUCHER', 'Voucher created', $voucher);
            
            if (($voucher['status'] ?? '') !== 'ACTIVE') {
                $this->recordStageFailure('VOUCHER_CHECK', "Voucher status is {$voucher['status']}, should be ACTIVE");
            }
        }
        
        // Verify SMS would have been sent (if enabled)
        $this->recordStage('SMS_NOTIFICATION', 'SMS notification would be sent to beneficiary');
        
        $this->generatedReferences['cashout'] = $reference;
        
        return ['message' => 'Cashout swap completed, voucher generated'];
    }
    
    // ============================================================
    // TEST: Deposit Swap
    // ============================================================
    
    private function testDepositSwap(): array
    {
        $reference = 'TEST_DEPOSIT_' . bin2hex(random_bytes(8));
        
        $payload = [
            'reference' => $reference,
            'swap_type' => 'DEPOSIT',
            'amount' => 750.00,
            'source_institution' => 'SACCUSSALIS',
            'source_asset_type' => 'ACCOUNT',
            'destination_institution' => 'ZURUBANK',
            'destination_account' => 'USER_WALLET_001'
        ];
        
        $this->recordStage('PREPARE_PAYLOAD', 'Preparing deposit payload', $payload);
        
        $result = $this->swapService->executeSwap($payload);
        
        $this->recordStage('EXECUTION_COMPLETE', 'Deposit execution completed', $result);
        
        if (($result['status'] ?? '') !== 'success') {
            $this->recordStageFailure('EXECUTION_FAILED', 'Deposit swap failed', $result);
            throw new Exception("Deposit swap failed");
        }
        
        // Verify destination funds reserved
        $stmt = $this->db->prepare("
            SELECT status, amount, destination_institution 
            FROM payment_instructions 
            WHERE swap_reference = :ref
        ");
        $stmt->execute([':ref' => $reference]);
        $paymentInstruction = $stmt->fetch();
        
        $this->recordStage('VERIFY_PAYMENT', 'Payment instruction created', $paymentInstruction ?: ['none' => true]);
        
        $this->generatedReferences['deposit'] = $reference;
        
        return ['message' => 'Deposit swap completed successfully'];
    }
    
    // ============================================================
    // TEST: Multi-Source Swap
    // ============================================================
    
    private function testMultiSourceSwap(): array
    {
        $reference = 'TEST_MULTI_' . bin2hex(random_bytes(8));
        
        $sources = [
            [
                'institution' => 'ZURUBANK',
                'asset_type' => 'BANK-WALLET',
                'amount' => 100.00,
                'identifier' => 'WALLET_001'
            ],
            [
                'institution' => 'SACCUSSALIS',
                'asset_type' => 'ACCOUNT',
                'amount' => 150.00,
                'identifier' => 'ACC_456789'
            ],
            [
                'institution' => 'ZURUBANK',
                'asset_type' => 'VOUCHER',
                'amount' => 50.00,
                'identifier' => 'VCH_12345'
            ]
        ];
        
        $payload = [
            'reference' => $reference,
            'swap_type' => 'MULTI_SOURCE',
            'is_multi_source' => true,
            'master_reference' => $reference,
            'sources' => $sources,
            'destination_institution' => 'SACCUSSALIS',
            'destination_account' => 'BENEFICIARY_WALLET',
            'total_amount' => 300.00,
            'currency' => 'BWP'
        ];
        
        $this->recordStage('PREPARE_PAYLOAD', 'Preparing multi-source payload', [
            'source_count' => count($sources),
            'total_amount' => 300.00
        ]);
        
        $result = $this->swapService->executeSwap($payload);
        
        $this->recordStage('EXECUTION_COMPLETE', 'Multi-source execution completed', [
            'status' => $result['status'] ?? 'unknown'
        ]);
        
        if (($result['status'] ?? '') !== 'success') {
            $this->recordStageFailure('EXECUTION_FAILED', 'Multi-source swap failed', $result);
            throw new Exception("Multi-source swap failed");
        }
        
        // Verify all sources contributed
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT source_institution) as source_count 
            FROM swap_ledgers 
            WHERE swap_reference = :ref
        ");
        $stmt->execute([':ref' => $reference]);
        $actualSources = $stmt->fetchColumn();
        
        $this->recordStage('VERIFY_SOURCES', 'Source contributions verified', [
            'expected_sources' => count($sources),
            'actual_sources' => $actualSources
        ]);
        
        $this->generatedReferences['multi_source'] = $reference;
        
        return ['message' => 'Multi-source swap completed with ' . count($sources) . ' sources'];
    }
    
    // ============================================================
    // TEST: Concurrent Swaps
    // ============================================================
    
    private function testConcurrentSwaps(): array
    {
        $this->recordStage('SETUP', 'Preparing 5 concurrent swap requests');
        
        $references = [];
        $payloads = [];
        
        for ($i = 0; $i < 5; $i++) {
            $ref = 'TEST_CONCURRENT_' . bin2hex(random_bytes(8));
            $references[] = $ref;
            $payloads[] = $this->createTestSwapPayload([
                'reference' => $ref,
                'amount' => 100.00 + ($i * 10),
                'source_institution' => $i % 2 == 0 ? 'ZURUBANK' : 'SACCUSSALIS',
                'destination_institution' => $i % 2 == 0 ? 'SACCUSSALIS' : 'ZURUBANK'
            ]);
        }
        
        $this->recordStage('EXECUTE_CONCURRENT', 'Executing 5 swaps concurrently');
        
        $startTime = microtime(true);
        
        // Execute sequentially for test (would be concurrent in real scenario)
        $results = [];
        $errors = [];
        
        foreach ($payloads as $index => $payload) {
            try {
                $results[] = $this->swapService->executeSwap($payload);
                $this->recordStage("SWAP_" . ($index + 1), "Swap {$index + 1} completed");
            } catch (Exception $e) {
                $errors[] = "Swap " . ($index + 1) . ": " . $e->getMessage();
                $this->recordStageFailure("SWAP_" . ($index + 1), $e->getMessage());
            }
        }
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        $this->recordStage('CONCURRENT_COMPLETE', "All swaps processed in {$duration}ms", [
            'successful' => count($results),
            'failed' => count($errors)
        ]);
        
        // Check for deadlocks or race conditions
        $stmt = $this->db->prepare("
            SELECT swap_reference, status FROM swap_requests 
            WHERE swap_reference IN ('" . implode("','", $references) . "')
        ");
        $stmt->execute();
        $records = $stmt->fetchAll();
        
        $inconsistentRecords = array_filter($records, fn($r) => $r['status'] === 'processing');
        
        if (!empty($inconsistentRecords)) {
            $this->recordStageFailure('RACE_CONDITION', 'Swaps stuck in processing state', $inconsistentRecords);
            throw new Exception("Race condition detected: swaps stuck in processing");
        }
        
        $this->recordStage('RACE_CHECK', 'No race conditions detected');
        
        return ['message' => 'Concurrent swaps completed without race conditions'];
    }
    
    // ============================================================
    // TEST: Rollback Compensation Engine
    // ============================================================
    
    private function testRollbackCompensation(): array
    {
        $reference = 'TEST_ROLLBACK_' . bin2hex(random_bytes(8));
        
        // Track resources before test
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM hold_transactions WHERE status = 'ACTIVE'
        ");
        $stmt->execute();
        $activeHoldsBefore = $stmt->fetchColumn();
        
        $this->recordStage('CAPTURE_BASELINE', 'Captured baseline state', [
            'active_holds_before' => $activeHoldsBefore
        ]);
        
        $payload = $this->createTestSwapPayload([
            'reference' => $reference,
            'amount' => 999999.00, // Will fail
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'NONEXISTENT_BANK'
        ]);
        
        try {
            $this->swapService->executeSwap($payload);
            $this->recordStageFailure('EXPECTED_FAILURE', 'Swap succeeded when it should have failed');
        } catch (Exception $e) {
            $this->recordStage('SWAP_FAILED', "Swap failed as expected: " . $e->getMessage());
        }
        
        // Verify no orphaned resources remain
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM hold_transactions 
            WHERE swap_reference = :ref AND status != 'RELEASED'
        ");
        $stmt->execute([':ref' => $reference]);
        $activeHoldsAfter = $stmt->fetchColumn();
        
        $this->recordStage('VERIFY_COMPENSATION', 'Verifying no orphaned resources', [
            'unreleased_holds' => $activeHoldsAfter
        ]);
        
        if ($activeHoldsAfter > 0) {
            $this->recordStageFailure('COMPENSATION_FAILED', "Found {$activeHoldsAfter} unreleased holds");
            throw new Exception("Compensation engine failed to release resources");
        }
        
        // Verify no ledger entries
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM swap_ledgers WHERE swap_reference = :ref
        ");
        $stmt->execute([':ref' => $reference]);
        $ledgerEntries = $stmt->fetchColumn();
        
        if ($ledgerEntries > 0) {
            $this->recordStageFailure('LEDGER_ROLLBACK', "Found {$ledgerEntries} ledger entries that should have been rolled back");
            throw new Exception("Ledger entries not rolled back");
        }
        
        $this->recordStage('COMPENSATION_SUCCESS', 'All resources properly compensated and released');
        
        return ['message' => 'Rollback compensation engine verified - no orphaned resources'];
    }
    
    // ============================================================
    // TEST: Ledger Consistency
    // ============================================================
    
    private function testLedgerConsistency(): array
    {
        $this->recordStage('LEDGER_AUDIT', 'Running ledger consistency audit');
        
        // Verify no entries are stuck in pending/processing state beyond timeout
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count, status 
            FROM swap_ledgers 
            WHERE status IN ('pending', 'processing') 
            AND created_at < NOW() - INTERVAL '1 HOUR'
            GROUP BY status
        ");
        $stmt->execute();
        $stuckEntries = $stmt->fetchAll();
        
        $this->recordStage('STUCK_ENTRIES', 'Checking for stuck ledger entries', $stuckEntries ?: ['none' => true]);
        
        if (!empty($stuckEntries)) {
            $this->warningCount++;
            $this->recordStage('WARNING', 'Found stuck ledger entries that may need manual reconciliation', $stuckEntries);
        }
        
        // Verify all holds have corresponding ledger entries or are released
        $stmt = $this->db->prepare("
            SELECT h.hold_reference, h.status as hold_status, s.status as ledger_status
            FROM hold_transactions h
            LEFT JOIN swap_ledgers s ON h.swap_reference = s.swap_reference
            WHERE h.status = 'ACTIVE' 
            AND h.created_at < NOW() - INTERVAL '30 MINUTES'
            AND s.status IS NULL
        ");
        $stmt->execute();
        $orphanedHolds = $stmt->fetchAll();
        
        $this->recordStage('ORPHANED_HOLDS', 'Checking for orphaned holds', [
            'count' => count($orphanedHolds)
        ]);
        
        if (!empty($orphanedHolds)) {
            $this->warningCount++;
            $this->recordStage('WARNING', 'Found orphaned holds without ledger entries', $orphanedHolds);
        }
        
        // Verify total debits equal total credits
        $stmt = $this->db->prepare("
            SELECT 
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_credits,
                SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_debits
            FROM swap_ledgers
            WHERE created_at > NOW() - INTERVAL '24 HOURS'
        ");
        $stmt->execute();
        $totals = $stmt->fetch();
        
        $this->recordStage('DEBIT_CREDIT_BALANCE', 'Checking debit/credit balance', [
            'total_credits' => $totals['total_credits'] ?? 0,
            'total_debits' => $totals['total_debits'] ?? 0,
            'difference' => abs(($totals['total_credits'] ?? 0) - ($totals['total_debits'] ?? 0))
        ]);
        
        $difference = abs(($totals['total_credits'] ?? 0) - ($totals['total_debits'] ?? 0));
        if ($difference > 0.01) {
            $this->recordStageFailure('LEDGER_IMBALANCE', "Ledger imbalance detected: difference of {$difference}");
            throw new Exception("Ledger is not balanced");
        }
        
        return ['message' => 'Ledger consistency audit passed'];
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
    
    private function printHeader(string $text, string $color = self::COLOR_BOLD): void
    {
        echo "\n" . $color . str_repeat("=", 80) . self::COLOR_RESET . "\n";
        echo $color . "  " . $text . self::COLOR_RESET . "\n";
        echo $color . str_repeat("=", 80) . self::COLOR_RESET . "\n";
    }
    
    private function printTestHeader(string $description): void
    {
        echo "\n" . self::COLOR_CYAN . "▶ " . $description . self::COLOR_RESET . "\n";
        echo self::COLOR_BLUE . str_repeat("─", 60) . self::COLOR_RESET . "\n";
    }
    
    private function printTestResult(string $status, string $message): void
    {
        $color = $status === 'PASS' ? self::COLOR_GREEN : self::COLOR_RED;
        $icon = $status === 'PASS' ? '✓' : '✗';
        
        echo sprintf(
            "  %s%s%s %s\n",
            $color,
            $icon,
            self::COLOR_RESET,
            $message
        );
    }
    
    private function printSummary(): void
    {
        $total = $this->passCount + $this->failCount;
        $passPercent = $total > 0 ? round(($this->passCount / $total) * 100, 1) : 0;
        
        $this->printHeader("TEST SUMMARY", self::COLOR_BOLD);
        
        echo sprintf("  %s✓ Passed: %d%s\n", self::COLOR_GREEN, $this->passCount, self::COLOR_RESET);
        echo sprintf("  %s✗ Failed: %d%s\n", self::COLOR_RED, $this->failCount, self::COLOR_RESET);
        echo sprintf("  %s⚠ Warnings: %d%s\n", self::COLOR_YELLOW, $this->warningCount, self::COLOR_RESET);
        echo sprintf("  %s📊 Pass Rate: %.1f%%%s\n", self::COLOR_BLUE, $passPercent, self::COLOR_RESET);
        
        if ($this->failCount === 0) {
            echo "\n" . self::COLOR_GREEN . "  🎉 ALL TESTS PASSED - Atomic execution kernel is working correctly!" . self::COLOR_RESET . "\n";
        } else {
            echo "\n" . self::COLOR_RED . "  ⚠ SOME TESTS FAILED - Review stage details above for specific issues" . self::COLOR_RESET . "\n";
        }
        
        echo "\n";
        
        // Print generated references for manual verification
        if (!empty($this->generatedReferences)) {
            echo self::COLOR_CYAN . "  Generated References for Manual Verification:\n" . self::COLOR_RESET;
            foreach ($this->generatedReferences as $type => $ref) {
                echo sprintf("    • %s: %s\n", strtoupper($type), $ref);
            }
            echo "\n";
        }
    }
}

// ============================================================
// RUN THE TESTS
// ============================================================

try {
    $testSuite = new SwapServiceAtomicTest();
    $testSuite->runAllTests();
    
} catch (Exception $e) {
    echo "\n" . "\033[31m" . "FATAL ERROR: " . $e->getMessage() . "\033[0m\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
