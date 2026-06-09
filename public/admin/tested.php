<?php
// /var/www/html/tests/EnterpriseTestSuite.php

declare(strict_types=1);

/**
 * VOUCHMORPH ENTERPRISE TEST SUITE
 * 
 * This test suite pushes the SwapService to production-grade limits:
 * - Security penetration testing
 * - High-volume stress tests (10,000+ concurrent transactions)
 * - Settlement integrity validation
 * - Byzantine fault tolerance
 * - Race condition detection
 * - Memory leak detection
 * - Performance benchmarking
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '2048M');
set_time_limit(0);

require_once __DIR__ . '/../src/bootstrap.php';

use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Mojaloop\IdempotencyService;

class EnterpriseTestSuite
{
    private PDO $db;
    private SwapService $swapService;
    private HybridSettlementStrategy $settlement;
    private array $testResults = [];
    private array $metrics = [];
    private int $totalTests = 0;
    private int $passedTests = 0;
    private int $failedTests = 0;
    private float $startTime;
    private array $securityViolations = [];
    private array $performanceBaseline = [];
    
    // Test thresholds
    private const STRESS_TEST_TRANSACTIONS = 10000;
    private const MAX_RESPONSE_TIME_MS = 500;
    private const MAX_CONCURRENT_USERS = 1000;
    private const SETTLEMENT_TOLERANCE = 0.01; // 1 cent tolerance
    
    // Security constants
    private const SQL_INJECTION_PAYLOADS = [
        "' OR '1'='1",
        "'; DROP TABLE swap_requests; --",
        "1' AND '1'='1",
        "1' OR '1'='1' --",
        "' UNION SELECT * FROM users --",
        "admin' --",
        "'; SELECT pg_sleep(5); --"
    ];
    
    private const XSS_PAYLOADS = [
        "<script>alert('XSS')</script>",
        "<img src=x onerror=alert('XSS')>",
        "javascript:alert('XSS')",
        "<svg onload=alert('XSS')>",
        "'; alert('XSS'); //"
    ];
    
    private const RACE_CONDITION_SCENARIOS = [
        'double_spend' => 'Attempting to spend same voucher twice',
        'balance_overflow' => 'Attempting to exceed balance limits',
        'concurrent_holds' => 'Multiple holds on same asset',
        'fee_manipulation' => 'Attempting fee bypass'
    ];
    
    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->db = $this->createDatabaseConnection();
        $this->swapService = $this->createSwapService();
        $this->settlement = new HybridSettlementStrategy($this->db);
        
        $this->printHeader("VOUCHMORPH ENTERPRISE TEST SUITE", "⚡");
        $this->printHeader("Pushing SwapService to World-Class Standards", "🏆");
    }
    
    private function createDatabaseConnection(): PDO
    {
        $config = [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'port' => getenv('DB_PORT') ?: '5432',
            'database' => getenv('DB_DATABASE') ?: 'vouchmorphn_test',
            'user' => getenv('DB_USER') ?: 'postgres',
            'password' => getenv('DB_PASSWORD') ?: ''
        ];
        
        // Create test database if it doesn't exist
        try {
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s', $config['host'], $config['port']),
                $config['user'],
                $config['password']
            );
            $pdo->exec("CREATE DATABASE IF NOT EXISTS {$config['database']}");
        } catch (PDOException $e) {
            // Database might already exist
        }
        
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
        $pdo = new PDO($dsn, $config['user'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $pdo;
    }
    
    private function createSwapService(): SwapService
    {
        $settings = [];
        $country = 'Botswana';
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: bin2hex(random_bytes(32));
        $config = [
            'currency' => 'BWP',
            'multi_source' => ['enabled' => true],
            'communication' => ['sms_gateway' => ['enabled' => false]],
            'security' => [
                'max_transaction_amount' => 100000,
                'daily_limit' => 500000,
                'rate_limit_per_minute' => 100,
                'require_2fa_for_amount' => 10000
            ],
            'settlement' => [
                'batch_size' => 1000,
                'max_retries' => 3,
                'timeout_seconds' => 30
            ]
        ];
        
        return new SwapService($this->db, $settings, $country, $encryptionKey, $config);
    }
    
    // ============================================================
    // SECURITY TESTING SUITE
    // ============================================================
    
    public function testSecurityPenetration(): array
    {
        $this->printSection("🔒 SECURITY PENETRATION TESTING");
        $results = [];
        
        // 1. SQL Injection Prevention
        $results['sql_injection'] = $this->testSQLInjectionPrevention();
        
        // 2. XSS Prevention
        $results['xss_prevention'] = $this->testXSSPrevention();
        
        // 3. Parameter Tampering
        $results['parameter_tampering'] = $this->testParameterTampering();
        
        // 4. Race Conditions (Double Spend)
        $results['race_conditions'] = $this->testRaceConditions();
        
        // 5. Replay Attacks
        $results['replay_attacks'] = $this->testReplayAttackPrevention();
        
        // 6. Authorization Bypass
        $results['auth_bypass'] = $this->testAuthorizationBypass();
        
        // 7. Rate Limiting
        $results['rate_limiting'] = $this->testRateLimiting();
        
        // 8. Input Validation
        $results['input_validation'] = $this->testInputValidation();
        
        // 9. Cryptographic Strength
        $results['crypto_strength'] = $this->testCryptographicStrength();
        
        // 10. Logging & Monitoring
        $results['audit_logging'] = $this->testAuditLogging();
        
        return $results;
    }
    
    private function testSQLInjectionPrevention(): array
    {
        $this->printSubsection("SQL Injection Prevention");
        $passed = 0;
        $failed = 0;
        
        foreach (self::SQL_INJECTION_PAYLOADS as $payload) {
            try {
                $testPayload = [
                    'reference' => 'SEC_TEST_' . bin2hex(random_bytes(4)),
                    'amount' => 100,
                    'source_institution' => $payload,
                    'destination_institution' => 'SACCUSSALIS'
                ];
                
                $result = $this->swapService->executeSwap($testPayload);
                
                // If it executed without throwing, check that the payload wasn't interpreted as SQL
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM swap_requests 
                    WHERE source_institution = :source
                ");
                $stmt->execute([':source' => $payload]);
                $count = $stmt->fetchColumn();
                
                if ($count === 0 || $count === 1) {
                    $passed++;
                    $this->logSecurity("✓ SQL Injection blocked: " . substr($payload, 0, 50), 'pass');
                } else {
                    $failed++;
                    $this->logSecurity("✗ SQL Injection possible: " . substr($payload, 0, 50), 'fail');
                    $this->securityViolations[] = "SQL Injection vector: {$payload}";
                }
            } catch (Exception $e) {
                // Exception means validation caught it - GOOD
                $passed++;
                $this->logSecurity("✓ SQL Injection blocked (exception): " . substr($payload, 0, 50), 'pass');
            }
        }
        
        return ['passed' => $passed, 'failed' => $failed, 'total' => $passed + $failed];
    }
    
    private function testXSSPrevention(): array
    {
        $this->printSubsection("XSS Prevention");
        $passed = 0;
        $failed = 0;
        
        foreach (self::XSS_PAYLOADS as $payload) {
            try {
                $testPayload = [
                    'reference' => 'XSS_TEST_' . bin2hex(random_bytes(4)),
                    'amount' => 100,
                    'source_institution' => 'ZURUBANK',
                    'destination_institution' => $payload,
                    'description' => $payload
                ];
                
                $result = $this->swapService->executeSwap($testPayload);
                
                // Check if response contains unescaped payload
                $responseJson = json_encode($result);
                if (strpos($responseJson, $payload) !== false && 
                    strpos($responseJson, htmlspecialchars($payload)) === false) {
                    $failed++;
                    $this->logSecurity("✗ XSS Vector found: " . substr($payload, 0, 50), 'fail');
                    $this->securityViolations[] = "XSS Vector: {$payload}";
                } else {
                    $passed++;
                    $this->logSecurity("✓ XSS blocked: " . substr($payload, 0, 50), 'pass');
                }
            } catch (Exception $e) {
                $passed++;
                $this->logSecurity("✓ XSS blocked (exception): " . substr($payload, 0, 50), 'pass');
            }
        }
        
        return ['passed' => $passed, 'failed' => $failed, 'total' => $passed + $failed];
    }
    
    private function testParameterTampering(): array
    {
        $this->printSubsection("Parameter Tampering Protection");
        $tests = [
            'negative_amount' => ['amount' => -100, 'expected' => 'reject'],
            'zero_amount' => ['amount' => 0, 'expected' => 'reject'],
            'string_amount' => ['amount' => 'abc', 'expected' => 'reject'],
            'null_amount' => ['amount' => null, 'expected' => 'reject'],
            'array_amount' => ['amount' => [100], 'expected' => 'reject'],
            'negative_fee' => ['fee' => -10, 'expected' => 'reject'],
            'invalid_currency' => ['currency' => 'XXX', 'expected' => 'reject'],
            'empty_reference' => ['reference' => '', 'expected' => 'auto_generate'],
            'excessive_amount' => ['amount' => 999999999, 'expected' => 'limit_check']
        ];
        
        $passed = 0;
        $failed = 0;
        
        foreach ($tests as $testName => $test) {
            try {
                $payload = [
                    'reference' => 'TAMPER_TEST_' . bin2hex(random_bytes(4)),
                    'source_institution' => 'ZURUBANK',
                    'destination_institution' => 'SACCUSSALIS'
                ];
                $payload = array_merge($payload, $test);
                
                $result = $this->swapService->executeSwap($payload);
                
                if ($test['expected'] === 'reject') {
                    $failed++;
                    $this->logSecurity("✗ Parameter tampering allowed: {$testName}", 'fail');
                } else {
                    $passed++;
                    $this->logSecurity("✓ Parameter tampering blocked: {$testName}", 'pass');
                }
            } catch (Exception $e) {
                if ($test['expected'] === 'reject') {
                    $passed++;
                    $this->logSecurity("✓ Parameter tampering rejected: {$testName}", 'pass');
                } else {
                    $failed++;
                    $this->logSecurity("✗ Valid parameter rejected: {$testName}", 'fail');
                }
            }
        }
        
        return ['passed' => $passed, 'failed' => $failed, 'total' => $passed + $failed];
    }
    
    private function testRaceConditions(): array
    {
        $this->printSubsection("Race Condition & Double-Spend Protection");
        
        // Create a test voucher
        $testVoucher = $this->createTestVoucher(1000);
        $voucherNumber = $testVoucher['voucher_number'];
        $results = [];
        
        foreach (self::RACE_CONDITION_SCENARIOS as $scenario => $description) {
            $this->logSecurity("Testing: {$description}", 'info');
            
            try {
                switch ($scenario) {
                    case 'double_spend':
                        $results[$scenario] = $this->testDoubleSpend($voucherNumber);
                        break;
                    case 'balance_overflow':
                        $results[$scenario] = $this->testBalanceOverflow();
                        break;
                    case 'concurrent_holds':
                        $results[$scenario] = $this->testConcurrentHolds($voucherNumber);
                        break;
                    case 'fee_manipulation':
                        $results[$scenario] = $this->testFeeManipulation();
                        break;
                }
            } catch (Exception $e) {
                $results[$scenario] = ['passed' => false, 'error' => $e->getMessage()];
            }
        }
        
        return $results;
    }
    
    private function testDoubleSpend(string $voucherNumber): array
    {
        // Launch concurrent requests for same voucher
        $concurrentRequests = 10;
        $results = [];
        $pidMap = [];
        
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                // Fork failed, do sequential
                try {
                    $payload = $this->createSwapPayload(['source_identifier' => $voucherNumber]);
                    $results[] = $this->swapService->executeSwap($payload);
                } catch (Exception $e) {
                    $results[] = ['error' => $e->getMessage()];
                }
            } elseif ($pid == 0) {
                // Child process
                try {
                    $payload = $this->createSwapPayload(['source_identifier' => $voucherNumber]);
                    $result = $this->swapService->executeSwap($payload);
                    file_put_contents("/tmp/double_spend_{$i}.json", json_encode($result));
                } catch (Exception $e) {
                    file_put_contents("/tmp/double_spend_{$i}.error", $e->getMessage());
                }
                exit(0);
            } else {
                $pidMap[] = $pid;
            }
        }
        
        // Wait for all children
        foreach ($pidMap as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Count successful spends
        $successfulSpends = 0;
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $resultFile = "/tmp/double_spend_{$i}.json";
            if (file_exists($resultFile)) {
                $result = json_decode(file_get_contents($resultFile), true);
                if (isset($result['status']) && $result['status'] === 'success') {
                    $successfulSpends++;
                }
                unlink($resultFile);
            }
        }
        
        $protected = ($successfulSpends === 1);
        
        if (!$protected) {
            $this->securityViolations[] = "Double-spend possible: {$successfulSpends}/{$concurrentRequests} succeeded";
        }
        
        return [
            'passed' => $protected,
            'successful_spends' => $successfulSpends,
            'total_attempts' => $concurrentRequests,
            'message' => $protected ? "Double-spend prevented" : "Double-spend detected!"
        ];
    }
    
    private function testBalanceOverflow(): array
    {
        $maxAmount = PHP_INT_MAX;
        
        try {
            $payload = $this->createSwapPayload(['amount' => $maxAmount]);
            $result = $this->swapService->executeSwap($payload);
            
            // Check if balance constraints were enforced
            $stmt = $this->db->prepare("
                SELECT amount FROM swap_requests 
                WHERE swap_uuid = :ref
            ");
            $stmt->execute([':ref' => $payload['reference']]);
            $actualAmount = $stmt->fetchColumn();
            
            if ($actualAmount > 10000000) { // More than 10 million BWP
                $this->securityViolations[] = "Balance overflow not prevented: {$actualAmount}";
                return ['passed' => false, 'message' => 'Overflow not prevented'];
            }
            
            return ['passed' => true, 'message' => 'Balance limits enforced'];
        } catch (Exception $e) {
            return ['passed' => true, 'message' => 'Overflow rejected: ' . $e->getMessage()];
        }
    }
    
    private function testConcurrentHolds(string $voucherNumber): array
    {
        // Try to place multiple holds on same voucher
        $holdAttempts = 5;
        $successfulHolds = 0;
        
        for ($i = 0; $i < $holdAttempts; $i++) {
            try {
                $payload = $this->createSwapPayload([
                    'source_identifier' => $voucherNumber,
                    'hold_only' => true
                ]);
                // This would call a hold-specific method
                $successfulHolds++;
            } catch (Exception $e) {
                // Hold prevented - good
            }
        }
        
        $protected = ($successfulHolds === 1);
        
        if (!$protected) {
            $this->securityViolations[] = "Concurrent holds possible: {$successfulHolds} holds placed";
        }
        
        return [
            'passed' => $protected,
            'holds_placed' => $successfulHolds,
            'message' => $protected ? "Concurrent holds prevented" : "Multiple holds allowed"
        ];
    }
    
    private function testFeeManipulation(): array
    {
        $testCases = [
            ['fee' => -100, 'expected_fee' => 0],
            ['fee' => 'free', 'expected_fee' => 0],
            ['fee' => null, 'expected_fee' => 0],
            ['fee' => ['discount' => 100], 'expected_fee' => 0],
            ['fee' => "0; DROP TABLE fees;", 'expected_fee' => 0]
        ];
        
        $passed = 0;
        
        foreach ($testCases as $test) {
            try {
                $payload = $this->createSwapPayload(['fee' => $test['fee']]);
                $result = $this->swapService->executeSwap($payload);
                
                // Verify fee wasn't manipulated
                $stmt = $this->db->prepare("
                    SELECT fee_amount FROM swap_ledgers 
                    WHERE swap_reference = :ref
                ");
                $stmt->execute([':ref' => $payload['reference']]);
                $actualFee = $stmt->fetchColumn();
                
                if ($actualFee == $test['expected_fee']) {
                    $passed++;
                }
            } catch (Exception $e) {
                // Rejected - good
                $passed++;
            }
        }
        
        return ['passed' => $passed, 'total' => count($testCases)];
    }
    
    private function testReplayAttackPrevention(): array
    {
        $this->printSubsection("Replay Attack Prevention");
        
        $idempotencyKey = 'REPLAY_TEST_' . bin2hex(random_bytes(8));
        $payload = $this->createSwapPayload(['idempotency_key' => $idempotencyKey]);
        
        // First request
        $result1 = $this->swapService->executeSwap($payload);
        $ref1 = $result1['reference'];
        
        // Second request with same key (should be rejected or return cached)
        $result2 = $this->swapService->executeSwap($payload);
        $ref2 = $result2['reference'] ?? null;
        
        // Third request with same key
        $result3 = $this->swapService->executeSwap($payload);
        $ref3 = $result3['reference'] ?? null;
        
        $replayProtected = ($ref1 === $ref2 && $ref2 === $ref3);
        
        if (!$replayProtected) {
            $this->securityViolations[] = "Replay attack possible: different references for same idempotency key";
        }
        
        // Verify only one record in database
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM swap_requests 
            WHERE swap_uuid LIKE :pattern
        ");
        $stmt->execute([':pattern' => '%' . substr($ref1, -8) . '%']);
        $recordCount = $stmt->fetchColumn();
        
        return [
            'passed' => $replayProtected && $recordCount == 1,
            'message' => $replayProtected ? "Replay attacks prevented" : "Replay attack possible",
            'records_created' => $recordCount
        ];
    }
    
    private function testAuthorizationBypass(): array
    {
        $this->printSubsection("Authorization Bypass Testing");
        
        $tests = [
            'no_auth' => ['headers' => [], 'expected' => 'reject'],
            'invalid_token' => ['headers' => ['Authorization' => 'Bearer invalid'], 'expected' => 'reject'],
            'expired_token' => ['headers' => ['Authorization' => 'Bearer expired'], 'expected' => 'reject'],
            'wrong_user' => ['headers' => ['X-User-ID' => '999999'], 'expected' => 'reject']
        ];
        
        $passed = 0;
        
        foreach ($tests as $testName => $test) {
            try {
                $payload = $this->createSwapPayload();
                // Add auth headers to payload if your system uses them
                $payload['auth'] = $test['headers'];
                
                $result = $this->swapService->executeSwap($payload);
                
                if ($test['expected'] === 'reject') {
                    $this->logSecurity("✗ Authorization bypass possible: {$testName}", 'fail');
                } else {
                    $passed++;
                }
            } catch (Exception $e) {
                if ($test['expected'] === 'reject') {
                    $passed++;
                    $this->logSecurity("✓ Authorization enforced: {$testName}", 'pass');
                }
            }
        }
        
        return ['passed' => $passed, 'total' => count($tests)];
    }
    
    private function testRateLimiting(): array
    {
        $this->printSubsection("Rate Limiting Protection");
        
        $requestsPerMinute = 150; // Should exceed limit
        $successCount = 0;
        $rateLimitHit = false;
        
        for ($i = 0; $i < $requestsPerMinute; $i++) {
            try {
                $payload = $this->createSwapPayload(['amount' => 1]);
                $result = $this->swapService->executeSwap($payload);
                $successCount++;
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'rate limit') !== false) {
                    $rateLimitHit = true;
                    break;
                }
            }
        }
        
        $rateLimitingActive = $rateLimitHit && $successCount < $requestsPerMinute;
        
        if (!$rateLimitingActive) {
            $this->securityViolations[] = "Rate limiting not enforced: {$successCount}/{$requestsPerMinute} succeeded";
        }
        
        return [
            'passed' => $rateLimitingActive,
            'successful_requests' => $successCount,
            'rate_limit_triggered' => $rateLimitHit,
            'message' => $rateLimitingActive ? "Rate limiting active" : "Rate limiting weak"
        ];
    }
    
    private function testInputValidation(): array
    {
        $this->printSubsection("Input Validation");
        
        $invalidInputs = [
            'phone' => ['+123', 'invalid', '123', '2671234567890123'],
            'email' => ['invalid', 'test@', '@example.com', 'test@test@test.com'],
            'amount' => ['-100', '0', 'abc', '1.999', '100.9999'],
            'reference' => [str_repeat('a', 300), 'invalid chars !@#$%', ''],
            'institution' => ['invalid_bank_that_does_not_exist', 'XSS<script>', 'SQL\' OR 1=1']
        ];
        
        $passed = 0;
        $total = 0;
        
        foreach ($invalidInputs as $field => $values) {
            foreach ($values as $value) {
                $total++;
                try {
                    $payload = $this->createSwapPayload([$field => $value]);
                    $result = $this->swapService->executeSwap($payload);
                    
                    // If it succeeded, verify the value was sanitized
                    $this->logSecurity("⚠ Input accepted but should be validated: {$field}={$value}", 'warn');
                } catch (Exception $e) {
                    $passed++;
                    $this->logSecurity("✓ Invalid {$field} rejected: {$value}", 'pass');
                }
            }
        }
        
        return ['passed' => $passed, 'total' => $total, 'percentage' => round($passed / $total * 100, 2)];
    }
    
    private function testCryptographicStrength(): array
    {
        $this->printSubsection("Cryptographic Strength");
        
        // Test 1: Check if sensitive data is encrypted
        $sensitivePayload = [
            'reference' => 'CRYPTO_TEST_' . bin2hex(random_bytes(4)),
            'amount' => 1000,
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS',
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'pin' => '1234'
        ];
        
        $result = $this->swapService->executeSwap($sensitivePayload);
        
        // Check database for plaintext sensitive data
        $stmt = $this->db->prepare("
            SELECT * FROM swap_requests 
            WHERE swap_uuid = :ref
        ");
        $stmt->execute([':ref' => $sensitivePayload['reference']]);
        $record = $stmt->fetch();
        
        $recordJson = json_encode($record);
        $hasPlaintextCard = strpos($recordJson, '4111111111111111') !== false;
        $hasPlaintextCVV = strpos($recordJson, '123') !== false && strpos($recordJson, '"cvv"') !== false;
        
        $encryptionStrength = !$hasPlaintextCard && !$hasPlaintextCVV;
        
        // Test 2: Check if hashing is used for passwords/pins
        $stmt = $this->db->prepare("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = 'users' 
            AND column_name IN ('password_hash', 'pin_hash', 'transaction_pin_hash')
        ");
        $hasHashedColumns = $stmt->rowCount() > 0;
        
        return [
            'passed' => $encryptionStrength && $hasHashedColumns,
            'sensitive_data_encrypted' => $encryptionStrength,
            'passwords_hashed' => $hasHashedColumns,
            'message' => $encryptionStrength ? "Cryptography strong" : "Cryptography weak - plaintext sensitive data found"
        ];
    }
    
    private function testAuditLogging(): array
    {
        $this->printSubsection("Audit Logging");
        
        $testReference = 'AUDIT_TEST_' . bin2hex(random_bytes(4));
        $payload = $this->createSwapPayload(['reference' => $testReference]);
        
        $result = $this->swapService->executeSwap($payload);
        
        // Check audit logs
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM audit_logs 
            WHERE entity_id = :ref 
            OR new_value LIKE :ref_pattern
        ");
        $stmt->execute([
            ':ref' => $testReference,
            ':ref_pattern' => '%' . $testReference . '%'
        ]);
        $auditCount = $stmt->fetchColumn();
        
        $hasAuditTrail = $auditCount > 0;
        
        if (!$hasAuditTrail) {
            $this->securityViolations[] = "No audit trail found for transaction";
        }
        
        return [
            'passed' => $hasAuditTrail,
            'audit_records' => $auditCount,
            'message' => $hasAuditTrail ? "Audit logging active" : "Audit logging missing"
        ];
    }
    
    // ============================================================
    // PERFORMANCE & STRESS TESTING
    // ============================================================
    
    public function testPerformance(): array
    {
        $this->printSection("⚡ PERFORMANCE BENCHMARKING");
        
        $results = [
            'latency' => $this->testLatency(),
            'throughput' => $this->testThroughput(),
            'concurrency' => $this->testConcurrency(),
            'memory_usage' => $this->testMemoryUsage(),
            'database_performance' => $this->testDatabasePerformance(),
            'settlement_performance' => $this->testSettlementPerformance()
        ];
        
        return $results;
    }
    
    private function testLatency(): array
    {
        $this->printSubsection("API Latency");
        
        $samples = 100;
        $latencies = [];
        
        for ($i = 0; $i < $samples; $i++) {
            $payload = $this->createSwapPayload(['amount' => rand(10, 1000)]);
            
            $start = microtime(true);
            try {
                $this->swapService->executeSwap($payload);
                $latency = (microtime(true) - $start) * 1000;
                $latencies[] = $latency;
            } catch (Exception $e) {
                // Skip failed requests for latency test
            }
        }
        
        if (empty($latencies)) {
            return ['error' => 'No successful requests'];
        }
        
        sort($latencies);
        $avgLatency = array_sum($latencies) / count($latencies);
        $p95Latency = $latencies[floor(count($latencies) * 0.95)];
        $p99Latency = $latencies[floor(count($latencies) * 0.99)];
        
        $meetsStandard = $p95Latency < self::MAX_RESPONSE_TIME_MS;
        
        $this->metrics['latency'] = [
            'average_ms' => round($avgLatency, 2),
            'p95_ms' => round($p95Latency, 2),
            'p99_ms' => round($p99Latency, 2),
            'samples' => count($latencies)
        ];
        
        return [
            'passed' => $meetsStandard,
            'average_ms' => round($avgLatency, 2),
            'p95_ms' => round($p95Latency, 2),
            'p99_ms' => round($p99Latency, 2),
            'message' => $meetsStandard ? "Latency within limits" : "Latency exceeds limits"
        ];
    }
    
    private function testThroughput(): array
    {
        $this->printSubsection("Transaction Throughput");
        
        $transactions = self::STRESS_TEST_TRANSACTIONS;
        $start = microtime(true);
        $successCount = 0;
        
        for ($i = 0; $i < $transactions; $i++) {
            try {
                $payload = $this->createSwapPayload(['amount' => rand(1, 100)]);
                $this->swapService->executeSwap($payload);
                $successCount++;
            } catch (Exception $e) {
                // Count failures
            }
        }
        
        $duration = microtime(true) - $start;
        $tps = $successCount / $duration;
        
        $this->metrics['throughput'] = [
            'transactions' => $transactions,
            'successful' => $successCount,
            'failed' => $transactions - $successCount,
            'duration_seconds' => round($duration, 2),
            'tps' => round($tps, 2)
        ];
        
        $meetsStandard = $tps > 100; // 100+ transactions per second
        
        return [
            'passed' => $meetsStandard,
            'tps' => round($tps, 2),
            'success_rate' => round(($successCount / $transactions) * 100, 2),
            'message' => $meetsStandard ? "Throughput excellent" : "Throughput needs improvement"
        ];
    }
    
    private function testConcurrency(): array
    {
        $this->printSubsection("Concurrent User Handling");
        
        $concurrentUsers = self::MAX_CONCURRENT_USERS;
        $userIds = [];
        $start = microtime(true);
        
        // Simulate concurrent users using process forking
        for ($i = 0; $i < $concurrentUsers; $i++) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                // Fallback to sequential
                $payload = $this->createSwapPayload();
                try {
                    $this->swapService->executeSwap($payload);
                } catch (Exception $e) {
                    // Ignore
                }
            } elseif ($pid == 0) {
                // Child process
                $payload = $this->createSwapPayload(['user_id' => $i]);
                try {
                    $this->swapService->executeSwap($payload);
                } catch (Exception $e) {
                    file_put_contents("/tmp/concurrency_error_{$i}.log", $e->getMessage());
                }
                exit(0);
            } else {
                $userIds[] = $pid;
            }
        }
        
        // Wait for all children
        foreach ($userIds as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        $duration = microtime(true) - $start;
        
        // Check for deadlocks or contention
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM pg_locks WHERE granted = false
        ");
        $stmt->execute();
        $pendingLocks = $stmt->fetchColumn();
        
        $noDeadlocks = $pendingLocks == 0;
        
        $this->metrics['concurrency'] = [
            'concurrent_users' => $concurrentUsers,
            'duration_seconds' => round($duration, 2),
            'pending_locks' => $pendingLocks
        ];
        
        return [
            'passed' => $noDeadlocks,
            'response_time_ms' => round($duration * 1000, 2),
            'message' => $noDeadlocks ? "Concurrency handled well" : "Deadlocks detected"
        ];
    }
    
    private function testMemoryUsage(): array
    {
        $this->printSubsection("Memory Usage");
        
        $initialMemory = memory_get_usage(true);
        $peakMemory = $initialMemory;
        
        // Run many transactions to check for leaks
        for ($i = 0; $i < 1000; $i++) {
            $payload = $this->createSwapPayload();
            try {
                $this->swapService->executeSwap($payload);
            } catch (Exception $e) {
                // Continue
            }
            
            $currentPeak = memory_get_peak_usage(true);
            if ($currentPeak > $peakMemory) {
                $peakMemory = $currentPeak;
            }
        }
        
        $memoryLeak = ($peakMemory - $initialMemory) > 50 * 1024 * 1024; // 50MB leak threshold
        
        $this->metrics['memory'] = [
            'initial_mb' => round($initialMemory / 1024 / 1024, 2),
            'peak_mb' => round($peakMemory / 1024 / 1024, 2),
            'increase_mb' => round(($peakMemory - $initialMemory) / 1024 / 1024, 2)
        ];
        
        return [
            'passed' => !$memoryLeak,
            'memory_increase_mb' => round(($peakMemory - $initialMemory) / 1024 / 1024, 2),
            'message' => $memoryLeak ? "Possible memory leak detected" : "Memory usage stable"
        ];
    }
    
    private function testDatabasePerformance(): array
    {
        $this->printSubsection("Database Performance");
        
        $queries = [
            'simple_select' => "SELECT 1",
            'swap_lookup' => "SELECT * FROM swap_requests LIMIT 1",
            'aggregate' => "SELECT COUNT(*) FROM swap_requests",
            'join' => "SELECT s.*, h.* FROM swap_requests s LEFT JOIN hold_transactions h ON s.swap_uuid = h.swap_reference LIMIT 10",
            'index_scan' => "SELECT * FROM swap_requests WHERE created_at > NOW() - INTERVAL '1 day'"
        ];
        
        $results = [];
        
        foreach ($queries as $name => $sql) {
            $times = [];
            for ($i = 0; $i < 10; $i++) {
                $start = microtime(true);
                $this->db->query($sql);
                $times[] = (microtime(true) - $start) * 1000;
            }
            $avgTime = array_sum($times) / count($times);
            $results[$name] = round($avgTime, 2);
        }
        
        $slowQueries = array_filter($results, fn($t) => $t > 100);
        $performanceGood = empty($slowQueries);
        
        $this->metrics['database'] = $results;
        
        return [
            'passed' => $performanceGood,
            'query_times_ms' => $results,
            'message' => $performanceGood ? "Database performance good" : "Slow queries detected"
        ];
    }
    
    private function testSettlementPerformance(): array
    {
        $this->printSubsection("Settlement Performance");
        
        $batchSize = 1000;
        $settlements = [];
        
        // Create test settlements
        for ($i = 0; $i < $batchSize; $i++) {
            $settlements[] = [
                'debtor' => 'BANK_' . rand(1, 5),
                'creditor' => 'BANK_' . rand(1, 5),
                'amount' => rand(100, 10000)
            ];
        }
        
        $start = microtime(true);
        
        try {
            foreach ($settlements as $settlement) {
                $this->settlement->processSettlement($settlement);
            }
            $duration = (microtime(true) - $start) * 1000;
            
            $settlementsPerSecond = $batchSize / ($duration / 1000);
            
            $performanceGood = $settlementsPerSecond > 100;
            
            return [
                'passed' => $performanceGood,
                'batch_size' => $batchSize,
                'duration_ms' => round($duration, 2),
                'settlements_per_second' => round($settlementsPerSecond, 2),
                'message' => $performanceGood ? "Settlement performance excellent" : "Settlement performance needs improvement"
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'error' => $e->getMessage(),
                'message' => "Settlement failed"
            ];
        }
    }
    
    // ============================================================
    // SETTLEMENT INTEGRITY TESTING
    // ============================================================
    
    public function testSettlementIntegrity(): array
    {
        $this->printSection("💰 SETTLEMENT INTEGRITY");
        
        $results = [
            'double_entry' => $this->testDoubleEntryAccounting(),
            'netting_accuracy' => $this->testNettingAccuracy(),
            'reconciliation' => $this->testReconciliation(),
            'rollback_integrity' => $this->testRollbackIntegrity(),
            'concurrent_settlement' => $this->testConcurrentSettlement(),
            'partial_settlement' => $this->testPartialSettlement(),
            'foreign_currency' => $this->testForeignCurrencySettlement(),
            'fee_allocation' => $this->testFeeAllocationAccuracy()
        ];
        
        return $results;
    }
    
    private function testDoubleEntryAccounting(): array
    {
        $this->printSubsection("Double-Entry Accounting");
        
        $testRef = 'ACC_TEST_' . bin2hex(random_bytes(4));
        $amount = 1000;
        
        $payload = $this->createSwapPayload([
            'reference' => $testRef,
            'amount' => $amount
        ]);
        
        $this->swapService->executeSwap($payload);
        
        // Verify double-entry: every debit has corresponding credit
        $stmt = $this->db->prepare("
            SELECT 
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_credits,
                SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_debits
            FROM swap_ledgers 
            WHERE swap_reference = :ref
        ");
        $stmt->execute([':ref' => $testRef]);
        $totals = $stmt->fetch();
        
        $isBalanced = abs($totals['total_credits'] - $totals['total_debits']) < self::SETTLEMENT_TOLERANCE;
        
        if (!$isBalanced) {
            $this->securityViolations[] = "Double-entry accounting violation: Credits={$totals['total_credits']}, Debits={$totals['total_debits']}";
        }
        
        return [
            'passed' => $isBalanced,
            'total_credits' => $totals['total_credits'],
            'total_debits' => $totals['total_debits'],
            'difference' => abs($totals['total_credits'] - $totals['total_debits']),
            'message' => $isBalanced ? "Double-entry accounting correct" : "Accounting imbalance detected"
        ];
    }
    
    private function testNettingAccuracy(): array
    {
        $this->printSubsection("Netting Accuracy");
        
        // Create multiple transactions between same parties
        $transactions = [];
        $debtor = 'BANK_A';
        $creditor = 'BANK_B';
        
        for ($i = 0; $i < 50; $i++) {
            $amount = rand(100, 10000);
            $transactions[] = [
                'debtor' => $debtor,
                'creditor' => $creditor,
                'amount' => $amount
            ];
        }
        
        $expectedNet = array_sum(array_column($transactions, 'amount'));
        
        // Process netting
        $netResult = $this->settlement->calculateNetPositions($transactions);
        $actualNet = 0;
        
        foreach ($netResult as $net) {
            if ($net['debtor'] === $debtor && $net['creditor'] === $creditor) {
                $actualNet = $net['amount'];
                break;
            }
        }
        
        $nettingAccurate = abs($expectedNet - $actualNet) < self::SETTLEMENT_TOLERANCE;
        
        return [
            'passed' => $nettingAccurate,
            'expected_net' => $expectedNet,
            'actual_net' => $actualNet,
            'difference' => abs($expectedNet - $actualNet),
            'message' => $nettingAccurate ? "Netting accurate" : "Netting calculation error"
        ];
    }
    
    private function testReconciliation(): array
    {
        $this->printSubsection("Reconciliation");
        
        // Create test data
        $testDate = date('Y-m-d');
        $totalAmount = 0;
        
        for ($i = 0; $i < 100; $i++) {
            $amount = rand(100, 10000);
            $totalAmount += $amount;
            
            $payload = $this->createSwapPayload(['amount' => $amount]);
            $this->swapService->executeSwap($payload);
        }
        
        // Run reconciliation
        $reconciliation = $this->settlement->reconcileDate($testDate);
        
        $isReconciled = abs($reconciliation['total_processed'] - $totalAmount) < self::SETTLEMENT_TOLERANCE;
        
        return [
            'passed' => $isReconciled,
            'expected_total' => $totalAmount,
            'reconciled_total' => $reconciliation['total_processed'] ?? 0,
            'discrepancy' => abs($totalAmount - ($reconciliation['total_processed'] ?? 0)),
            'message' => $isReconciled ? "Reconciliation successful" : "Reconciliation failed"
        ];
    }
    
    private function testRollbackIntegrity(): array
    {
        $this->printSubsection("Rollback Integrity");
        
        // Capture initial state
        $stmt = $this->db->prepare("
            SELECT SUM(amount) as total FROM swap_ledgers
        ");
        $stmt->execute();
        $initialTotal = $stmt->fetchColumn();
        
        // Create transaction that will fail
        try {
            $payload = $this->createSwapPayload(['amount' => 999999999]); // Will likely fail
            $this->swapService->executeSwap($payload);
        } catch (Exception $e) {
            // Expected failure
        }
        
        // Verify state rolled back
        $stmt = $this->db->prepare("
            SELECT SUM(amount) as total FROM swap_ledgers
        ");
        $stmt->execute();
        $finalTotal = $stmt->fetchColumn();
        
        $rollbackSuccessful = abs($initialTotal - $finalTotal) < self::SETTLEMENT_TOLERANCE;
        
        return [
            'passed' => $rollbackSuccessful,
            'initial_total' => $initialTotal,
            'final_total' => $finalTotal,
            'message' => $rollbackSuccessful ? "Rollback integrity maintained" : "Rollback failed - state corrupted"
        ];
    }
    
    private function testConcurrentSettlement(): array
    {
        $this->printSubsection("Concurrent Settlement");
        
        $processes = [];
        
        for ($i = 0; $i < 10; $i++) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                // Sequential fallback
                $this->processSettlementBatch("BATCH_{$i}");
            } elseif ($pid == 0) {
                // Child process
                $this->processSettlementBatch("BATCH_{$i}");
                exit(0);
            } else {
                $processes[] = $pid;
            }
        }
        
        // Wait for all children
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        // Check for settlement conflicts
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM settlement_queue 
            WHERE status = 'conflict'
        ");
        $stmt->execute();
        $conflicts = $stmt->fetchColumn();
        
        return [
            'passed' => $conflicts == 0,
            'conflicts_detected' => $conflicts,
            'message' => $conflicts == 0 ? "Concurrent settlement handled" : "Settlement conflicts detected"
        ];
    }
    
    private function processSettlementBatch(string $batchId): void
    {
        try {
            $this->settlement->processBatch($batchId);
        } catch (Exception $e) {
            error_log("Batch {$batchId} failed: " . $e->getMessage());
        }
    }
    
    private function testPartialSettlement(): array
    {
        $this->printSubsection("Partial Settlement");
        
        $totalAmount = 10000;
        $partialAmount = 6000;
        
        // Create settlement instruction
        $settlementId = $this->settlement->createSettlementInstruction([
            'debtor' => 'BANK_A',
            'creditor' => 'BANK_B',
            'amount' => $totalAmount
        ]);
        
        // Process partial settlement
        $partialResult = $this->settlement->processPartialSettlement($settlementId, $partialAmount);
        
        // Verify remaining amount
        $remaining = $this->settlement->getRemainingAmount($settlementId);
        
        $partialHandled = ($remaining == ($totalAmount - $partialAmount));
        
        return [
            'passed' => $partialHandled,
            'total_amount' => $totalAmount,
            'partial_amount' => $partialAmount,
            'remaining' => $remaining,
            'message' => $partialHandled ? "Partial settlement handled" : "Partial settlement error"
        ];
    }
    
    private function testForeignCurrencySettlement(): array
    {
        $this->printSubsection("Foreign Currency Settlement");
        
        $currencies = ['USD', 'EUR', 'ZAR', 'GBP', 'BWP'];
        $results = [];
        
        foreach ($currencies as $currency) {
            try {
                $payload = $this->createSwapPayload([
                    'currency' => $currency,
                    'amount' => 100
                ]);
                
                $result = $this->swapService->executeSwap($payload);
                
                $results[$currency] = [
                    'success' => true,
                    'reference' => $result['reference']
                ];
            } catch (Exception $e) {
                $results[$currency] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $foreignCurrencySupport = !empty(array_filter($results, fn($r) => $r['success']));
        
        return [
            'passed' => $foreignCurrencySupport,
            'supported_currencies' => array_keys(array_filter($results, fn($r) => $r['success'])),
            'message' => $foreignCurrencySupport ? "Foreign currency supported" : "Foreign currency issues"
        ];
    }
    
    private function testFeeAllocationAccuracy(): array
    {
        $this->printSubsection("Fee Allocation Accuracy");
        
        $testCases = [
            ['amount' => 1000, 'fee' => 10, 'expected_net' => 990],
            ['amount' => 500, 'fee' => 0, 'expected_net' => 500],
            ['amount' => 100, 'fee' => 5.5, 'expected_net' => 94.5],
            ['amount' => 10000, 'fee' => 150, 'expected_net' => 9850]
        ];
        
        $passed = 0;
        
        foreach ($testCases as $test) {
            $payload = $this->createSwapPayload([
                'amount' => $test['amount'],
                'fee' => $test['fee']
            ]);
            
            $result = $this->swapService->executeSwap($payload);
            
            // Verify fee allocation in database
            $stmt = $this->db->prepare("
                SELECT SUM(amount) as total_credited 
                FROM swap_ledgers 
                WHERE swap_reference = :ref AND amount > 0
            ");
            $stmt->execute([':ref' => $payload['reference']]);
            $actualNet = $stmt->fetchColumn();
            
            if (abs($actualNet - $test['expected_net']) < self::SETTLEMENT_TOLERANCE) {
                $passed++;
            }
        }
        
        return [
            'passed' => $passed == count($testCases),
            'passed_tests' => $passed,
            'total_tests' => count($testCases),
            'message' => $passed == count($testCases) ? "Fee allocation accurate" : "Fee allocation errors"
        ];
    }
    
    // ============================================================
    // HELPER METHODS
    // ============================================================
    
    private function createTestVoucher(float $amount): array
    {
        $voucherNumber = 'VCH_TEST_' . bin2hex(random_bytes(8));
        $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $stmt = $this->db->prepare("
            INSERT INTO swap_vouchers (voucher_number, pin_hash, amount, status, created_at)
            VALUES (:number, :pin, :amount, 'ACTIVE', NOW())
            RETURNING voucher_id
        ");
        $stmt->execute([
            ':number' => $voucherNumber,
            ':pin' => password_hash($pin, PASSWORD_DEFAULT),
            ':amount' => $amount
        ]);
        
        return [
            'voucher_number' => $voucherNumber,
            'pin' => $pin,
            'amount' => $amount
        ];
    }
    
    private function createSwapPayload(array $overrides = []): array
    {
        $default = [
            'reference' => 'TEST_' . bin2hex(random_bytes(8)),
            'amount' => rand(10, 1000),
            'currency' => 'BWP',
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS',
            'asset_type' => 'BANK-WALLET',
            'timestamp' => microtime(true),
            'test_id' => uniqid()
        ];
        
        return array_merge($default, $overrides);
    }
    
    private function logSecurity(string $message, string $level): void
    {
        $icon = match($level) {
            'pass' => '✓',
            'fail' => '✗',
            'warn' => '⚠',
            default => 'ℹ'
        };
        
        echo "    {$icon} {$message}\n";
    }
    
    private function printHeader(string $title, string $icon = "🔬"): void
    {
        echo "\n" . str_repeat("═", 80) . "\n";
        echo "{$icon}  {$title}\n";
        echo str_repeat("═", 80) . "\n";
    }
    
    private function printSection(string $title): void
    {
        echo "\n" . str_repeat("─", 80) . "\n";
        echo "📋 {$title}\n";
        echo str_repeat("─", 80) . "\n";
    }
    
    private function printSubsection(string $title): void
    {
        echo "\n  ▸ {$title}\n";
        echo "  " . str_repeat("∙", strlen($title) + 3) . "\n";
    }
    
    private function printResults(): void
    {
        $duration = round(microtime(true) - $this->startTime, 2);
        
        $this->printHeader("TEST RESULTS SUMMARY", "📊");
        
        echo "\n  Duration: {$duration} seconds\n";
        echo "  Total Tests: {$this->totalTests}\n";
        echo "  Passed: {$this->passedTests}\n";
        echo "  Failed: {$this->failedTests}\n";
        echo "  Pass Rate: " . round(($this->passedTests / max(1, $this->totalTests)) * 100, 2) . "%\n";
        
        if (!empty($this->securityViolations)) {
            echo "\n  ⚠ SECURITY VIOLATIONS DETECTED:\n";
            foreach ($this->securityViolations as $violation) {
                echo "    • {$violation}\n";
            }
        }
        
        echo "\n  📈 Performance Metrics:\n";
        foreach ($this->metrics as $metric => $values) {
            echo "    • {$metric}: " . json_encode($values) . "\n";
        }
        
        $finalGrade = $this->calculateGrade();
        echo "\n  🏆 FINAL GRADE: {$finalGrade}\n";
    }
    
    private function calculateGrade(): string
    {
        $passRate = ($this->passedTests / max(1, $this->totalTests)) * 100;
        
        if ($passRate >= 95 && empty($this->securityViolations)) {
            return "A+ (World-Class)";
        } elseif ($passRate >= 90 && count($this->securityViolations) <= 2) {
            return "A (Enterprise Ready)";
        } elseif ($passRate >= 80) {
            return "B (Production Ready with Improvements)";
        } elseif ($passRate >= 70) {
            return "C (Needs Work)";
        } else {
            return "F (Critical Issues)";
        }
    }
    
    public function run(): void
    {
        // Run all test suites
        $securityResults = $this->testSecurityPenetration();
        $performanceResults = $this->testPerformance();
        $settlementResults = $this->testSettlementIntegrity();
        
        // Calculate totals
        $this->aggregateResults($securityResults);
        $this->aggregateResults($performanceResults);
        $this->aggregateResults($settlementResults);
        
        $this->printResults();
    }
    
    private function aggregateResults(array $results): void
    {
        foreach ($results as $category => $result) {
            if (is_array($result) && isset($result['passed'])) {
                $this->totalTests++;
                if ($result['passed']) {
                    $this->passedTests++;
                } else {
                    $this->failedTests++;
                }
            } elseif (is_array($result)) {
                // Recursively aggregate nested results
                $this->aggregateResults($result);
            }
        }
    }
}

// Run the test suite
if (php_sapi_name() === 'cli') {
    $suite = new EnterpriseTestSuite();
    $suite->run();
} else {
    echo "<pre>";
    $suite = new EnterpriseTestSuite();
    $suite->run();
    echo "</pre>";
}
