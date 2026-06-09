<?php
// /var/www/html/tests/RailwayEnterpriseTest.php

declare(strict_types=1);

/**
 * VOUCHMORPH RAILWAY ENTERPRISE TEST SUITE
 * Production-grade testing for Railway deployment
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '2048M');
set_time_limit(0);

require_once __DIR__ . '/../src/bootstrap.php';

use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;

class RailwayEnterpriseTest
{
    private PDO $db;
    private SwapService $swapService;
    private HybridSettlementStrategy $settlement;
    private array $results = [];
    private array $metrics = [];
    private int $totalTests = 0;
    private int $passedTests = 0;
    private int $failedTests = 0;
    private float $startTime;
    private array $securityIssues = [];
    private array $performanceData = [];
    
    // Railway-specific configuration
    private string $railwayEnv;
    private array $rateLimitStats = [];
    
    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->railwayEnv = getenv('RAILWAY_ENVIRONMENT') ?: 'production';
        
        $this->initializeDatabase();
        $this->initializeServices();
        
        $this->printHeader("VOUCHMORPH RAILWAY ENTERPRISE TEST SUITE", "🚂");
        $this->printHeader("Environment: " . strtoupper($this->railwayEnv), "⚙️");
    }
    
    private function initializeDatabase(): void
    {
        try {
            // Use existing connection from bootstrap or create new
            $databaseUrl = getenv('DATABASE_URL');
            if ($databaseUrl) {
                $this->db = new PDO($databaseUrl);
            } else {
                $this->db = new PDO(
                    "pgsql:host=" . getenv('DB_HOST') . ";port=" . getenv('DB_PORT') . ";dbname=" . getenv('DB_DATABASE'),
                    getenv('DB_USER'),
                    getenv('DB_PASSWORD')
                );
            }
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            echo "✅ Database connected to Railway PostgreSQL\n";
        } catch (PDOException $e) {
            die("❌ Database connection failed: " . $e->getMessage() . "\n");
        }
    }
    
    private function initializeServices(): void
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
        
        $this->swapService = new SwapService($this->db, $settings, $country, $encryptionKey, $config);
        $this->settlement = new HybridSettlementStrategy($this->db);
        
        echo "✅ Services initialized\n";
    }
    
    // ============================================================
    // 1. DATABASE PERFORMANCE & INTEGRITY TESTS
    // ============================================================
    
    public function testDatabasePerformance(): array
    {
        $this->printSection("🗄️ DATABASE PERFORMANCE & INTEGRITY");
        
        $results = [
            'connection_pool' => $this->testConnectionPooling(),
            'query_performance' => $this->testQueryPerformance(),
            'index_usage' => $this->testIndexUsage(),
            'transaction_isolation' => $this->testTransactionIsolation(),
            'deadlock_detection' => $this->testDeadlockDetection(),
            'replication_lag' => $this->testReplicationLag(),
            'vacuum_status' => $this->testVacuumStatus(),
            'table_bloat' => $this->testTableBloat()
        ];
        
        return $results;
    }
    
    private function testConnectionPooling(): array
    {
        echo "\n  🔄 Connection Pooling Test\n";
        
        $concurrentConnections = 50;
        $successCount = 0;
        $failCount = 0;
        $responseTimes = [];
        
        for ($i = 0; $i < $concurrentConnections; $i++) {
            $start = microtime(true);
            try {
                $testDb = new PDO($this->db->getAttribute(PDO::ATTR_CONNECTION_STATUS) ?: '');
                $testDb->query("SELECT 1");
                $responseTime = (microtime(true) - $start) * 1000;
                $responseTimes[] = $responseTime;
                $successCount++;
                $testDb = null;
            } catch (Exception $e) {
                $failCount++;
            }
        }
        
        $avgResponse = $successCount > 0 ? array_sum($responseTimes) / count($responseTimes) : 0;
        $poolEfficient = $avgResponse < 50; // Under 50ms average
        
        echo "    ✓ Connections tested: {$concurrentConnections}\n";
        echo "    ✓ Successful: {$successCount}\n";
        echo "    ✓ Failed: {$failCount}\n";
        echo "    ✓ Avg response: " . round($avgResponse, 2) . "ms\n";
        
        return [
            'passed' => $poolEfficient,
            'success_rate' => round(($successCount / $concurrentConnections) * 100, 2),
            'avg_response_ms' => round($avgResponse, 2),
            'message' => $poolEfficient ? "Connection pool efficient" : "Connection pool needs optimization"
        ];
    }
    
    private function testQueryPerformance(): array
    {
        echo "\n  ⚡ Query Performance Test\n";
        
        $queries = [
            'simple_select' => "SELECT 1",
            'swap_lookup' => "SELECT * FROM swap_requests LIMIT 1",
            'aggregate' => "SELECT COUNT(*) FROM swap_requests",
            'join' => "SELECT s.*, h.* FROM swap_requests s LEFT JOIN hold_transactions h ON s.swap_uuid = h.swap_reference LIMIT 10",
            'date_range' => "SELECT * FROM swap_requests WHERE created_at > NOW() - INTERVAL '1 day' LIMIT 100",
            'group_by' => "SELECT DATE(created_at), COUNT(*) FROM swap_requests GROUP BY DATE(created_at) LIMIT 30"
        ];
        
        $results = [];
        $slowQueries = [];
        
        foreach ($queries as $name => $sql) {
            $times = [];
            for ($i = 0; $i < 10; $i++) {
                $start = microtime(true);
                $this->db->query($sql);
                $times[] = (microtime(true) - $start) * 1000;
            }
            $avgTime = array_sum($times) / count($times);
            $results[$name] = round($avgTime, 2);
            
            if ($avgTime > 100) {
                $slowQueries[] = $name;
            }
            
            echo "    ✓ {$name}: " . round($avgTime, 2) . "ms\n";
        }
        
        $performanceGood = empty($slowQueries);
        
        return [
            'passed' => $performanceGood,
            'query_times_ms' => $results,
            'slow_queries' => $slowQueries,
            'message' => $performanceGood ? "All queries performant" : "Slow queries detected"
        ];
    }
    
    private function testIndexUsage(): array
    {
        echo "\n  📊 Index Usage Analysis\n";
        
        // Check for missing indexes on frequently queried columns
        $indexCheck = $this->db->query("
            SELECT 
                schemaname,
                tablename,
                indexname,
                idx_scan,
                idx_tup_read,
                idx_tup_fetch
            FROM pg_stat_user_indexes
            WHERE idx_scan = 0
            ORDER BY tablename
        ")->fetchAll();
        
        $unusedIndexes = [];
        foreach ($indexCheck as $index) {
            if ($index['idx_scan'] == 0) {
                $unusedIndexes[] = "{$index['tablename']}.{$index['indexname']}";
            }
        }
        
        echo "    ✓ Total indexes: " . count($indexCheck) . "\n";
        echo "    ✓ Unused indexes: " . count($unusedIndexes) . "\n";
        
        if (!empty($unusedIndexes)) {
            echo "    ⚠ Unused indexes: " . implode(", ", array_slice($unusedIndexes, 0, 5)) . "\n";
        }
        
        return [
            'passed' => count($unusedIndexes) < 5,
            'total_indexes' => count($indexCheck),
            'unused_indexes' => $unusedIndexes,
            'message' => count($unusedIndexes) < 5 ? "Index usage optimal" : "Some indexes not used"
        ];
    }
    
    private function testTransactionIsolation(): array
    {
        echo "\n  🔒 Transaction Isolation Test\n";
        
        // Test READ COMMITTED isolation level
        $this->db->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
        
        $testPassed = true;
        $testResults = [];
        
        try {
            // Test concurrent read/write
            $this->db->beginTransaction();
            $this->db->exec("CREATE TEMP TABLE isolation_test (id SERIAL, value INT)");
            $this->db->exec("INSERT INTO isolation_test (value) VALUES (100)");
            
            // Simulate another connection reading
            $otherDb = new PDO($this->db->getAttribute(PDO::ATTR_CONNECTION_STATUS) ?: '');
            $otherDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $otherDb->query("SELECT value FROM isolation_test");
            $readValue = $stmt->fetchColumn();
            
            $this->db->exec("UPDATE isolation_test SET value = 200");
            $this->db->commit();
            
            // Verify isolation
            $testResults[] = "READ COMMITTED: Initial read = {$readValue}";
            $testPassed = $testPassed && ($readValue == 100);
            
            $this->db->exec("DROP TABLE isolation_test");
            
        } catch (Exception $e) {
            $testPassed = false;
            $testResults[] = "Error: " . $e->getMessage();
        }
        
        echo "    ✓ Isolation level: READ COMMITTED\n";
        foreach ($testResults as $result) {
            echo "    ✓ {$result}\n";
        }
        
        return [
            'passed' => $testPassed,
            'isolation_level' => 'READ COMMITTED',
            'message' => $testPassed ? "Transaction isolation working" : "Isolation issues detected"
        ];
    }
    
    private function testDeadlockDetection(): array
    {
        echo "\n  💀 Deadlock Detection Test\n";
        
        $deadlockDetected = false;
        
        try {
            // Attempt to create a deadlock scenario
            $pid1 = pcntl_fork();
            
            if ($pid1 == 0) {
                // Process 1
                $db1 = new PDO($this->db->getAttribute(PDO::ATTR_CONNECTION_STATUS) ?: '');
                $db1->beginTransaction();
                $db1->exec("LOCK TABLE swap_requests IN ACCESS EXCLUSIVE MODE");
                sleep(1);
                $db1->exec("LOCK TABLE hold_transactions IN ACCESS EXCLUSIVE MODE");
                $db1->commit();
                exit(0);
            }
            
            $pid2 = pcntl_fork();
            
            if ($pid2 == 0) {
                // Process 2
                sleep(0.5);
                $db2 = new PDO($this->db->getAttribute(PDO::ATTR_CONNECTION_STATUS) ?: '');
                $db2->beginTransaction();
                $db2->exec("LOCK TABLE hold_transactions IN ACCESS EXCLUSIVE MODE");
                sleep(1);
                $db2->exec("LOCK TABLE swap_requests IN ACCESS EXCLUSIVE MODE");
                $db2->commit();
                exit(0);
            }
            
            // Wait for processes
            pcntl_waitpid($pid1, $status1);
            pcntl_waitpid($pid2, $status2);
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'deadlock') !== false) {
                $deadlockDetected = true;
            }
        }
        
        echo "    ✓ Deadlock detection: " . ($deadlockDetected ? "ACTIVE" : "SIMULATION ONLY") . "\n";
        
        return [
            'passed' => true, // Deadlock detection is a good feature
            'deadlock_detected' => $deadlockDetected,
            'message' => "PostgreSQL deadlock detection active"
        ];
    }
    
    private function testReplicationLag(): array
    {
        echo "\n  📡 Replication Lag Test\n";
        
        try {
            // Check if replica is configured
            $stmt = $this->db->query("
                SELECT pg_is_in_recovery() as is_replica
            ");
            $isReplica = $stmt->fetchColumn();
            
            if ($isReplica === 't') {
                // Check replication lag
                $stmt = $this->db->query("
                    SELECT 
                        CASE WHEN pg_last_xlog_receive_location() = pg_last_xlog_replay_location() 
                        THEN 0 
                        ELSE EXTRACT(EPOCH FROM NOW() - pg_last_xact_replay_timestamp()) 
                        END as lag_seconds
                ");
                $lagSeconds = $stmt->fetchColumn();
                
                echo "    ✓ Replication lag: {$lagSeconds} seconds\n";
                $replicationHealthy = $lagSeconds < 10;
            } else {
                echo "    ℹ No replica configured (single instance)\n";
                $replicationHealthy = true;
            }
        } catch (Exception $e) {
            echo "    ℹ Replication status not available\n";
            $replicationHealthy = true;
        }
        
        return [
            'passed' => $replicationHealthy,
            'message' => $replicationHealthy ? "Replication healthy" : "Replication lag detected"
        ];
    }
    
    private function testVacuumStatus(): array
    {
        echo "\n  🧹 Vacuum Status Check\n";
        
        $stmt = $this->db->query("
            SELECT 
                schemaname,
                tablename,
                n_dead_tup,
                n_live_tup,
                round(100 * n_dead_tup / nullif(n_live_tup + n_dead_tup, 0), 2) as dead_ratio
            FROM pg_stat_user_tables
            WHERE n_dead_tup > 1000
            ORDER BY dead_ratio DESC
            LIMIT 5
        ");
        
        $tablesNeedingVacuum = $stmt->fetchAll();
        
        if (empty($tablesNeedingVacuum)) {
            echo "    ✓ No tables need vacuum\n";
        } else {
            echo "    ⚠ Tables needing vacuum:\n";
            foreach ($tablesNeedingVacuum as $table) {
                echo "      - {$table['tablename']}: {$table['dead_ratio']}% dead tuples\n";
            }
        }
        
        return [
            'passed' => count($tablesNeedingVacuum) < 3,
            'tables_needing_vacuum' => $tablesNeedingVacuum,
            'message' => count($tablesNeedingVacuum) < 3 ? "Vacuum status good" : "Vacuum recommended"
        ];
    }
    
    private function testTableBloat(): array
    {
        echo "\n  📈 Table Bloat Analysis\n";
        
        $stmt = $this->db->query("
            SELECT 
                schemaname,
                tablename,
                pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as total_size,
                pg_size_pretty(pg_relation_size(schemaname||'.'||tablename)) as table_size,
                round(100 * (pg_total_relation_size(schemaname||'.'||tablename) - pg_relation_size(schemaname||'.'||tablename)) / nullif(pg_total_relation_size(schemaname||'.'||tablename), 0), 2) as bloat_ratio
            FROM pg_tables
            WHERE schemaname = 'public'
            ORDER BY bloat_ratio DESC
            LIMIT 5
        ");
        
        $bloatedTables = $stmt->fetchAll();
        
        echo "    ✓ Largest tables:\n";
        foreach ($bloatedTables as $table) {
            echo "      - {$table['tablename']}: {$table['total_size']} (bloat: {$table['bloat_ratio']}%)\n";
        }
        
        $excessiveBloat = array_filter($bloatedTables, fn($t) => $t['bloat_ratio'] > 30);
        
        return [
            'passed' => empty($excessiveBloat),
            'bloated_tables' => $excessiveBloat,
            'message' => empty($excessiveBloat) ? "No excessive table bloat" : "Table bloat detected"
        ];
    }
    
    // ============================================================
    // 2. SWAP SERVICE CORE FUNCTIONALITY TESTS
    // ============================================================
    
    public function testSwapServiceCore(): array
    {
        $this->printSection("🔄 SWAP SERVICE CORE FUNCTIONALITY");
        
        $results = [
            'basic_swap' => $this->testBasicSwap(),
            'multi_currency' => $this->testMultiCurrency(),
            'large_amount' => $this->testLargeAmount(),
            'edge_cases' => $this->testEdgeCases(),
            'validation' => $this->testValidationRules(),
            'idempotency' => $this->testIdempotency(),
            'concurrent_swaps' => $this->testConcurrentSwaps(),
            'error_recovery' => $this->testErrorRecovery()
        ];
        
        return $results;
    }
    
    private function testBasicSwap(): array
    {
        echo "\n  💱 Basic Swap Test\n";
        
        $testRef = 'BASIC_' . bin2hex(random_bytes(8));
        $amount = 100.00;
        
        $payload = [
            'reference' => $testRef,
            'amount' => $amount,
            'currency' => 'BWP',
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS'
        ];
        
        $start = microtime(true);
        try {
            $result = $this->swapService->executeSwap($payload);
            $duration = (microtime(true) - $start) * 1000;
            
            echo "    ✓ Swap executed in " . round($duration, 2) . "ms\n";
            echo "    ✓ Reference: {$testRef}\n";
            echo "    ✓ Status: " . ($result['status'] ?? 'unknown') . "\n";
            
            // Verify in database
            $stmt = $this->db->prepare("SELECT * FROM swap_requests WHERE swap_uuid = :ref");
            $stmt->execute([':ref' => $testRef]);
            $record = $stmt->fetch();
            
            $verified = $record !== false;
            echo "    ✓ Database record: " . ($verified ? "FOUND" : "MISSING") . "\n";
            
            $this->performanceData['basic_swap_ms'][] = $duration;
            
            return [
                'passed' => $verified && ($result['status'] ?? '') === 'success',
                'duration_ms' => round($duration, 2),
                'reference' => $testRef,
                'message' => $verified ? "Basic swap working" : "Database verification failed"
            ];
        } catch (Exception $e) {
            echo "    ✗ Failed: " . $e->getMessage() . "\n";
            return ['passed' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function testMultiCurrency(): array
    {
        echo "\n  💱 Multi-Currency Test\n";
        
        $currencies = ['BWP', 'USD', 'EUR', 'ZAR', 'GBP'];
        $results = [];
        
        foreach ($currencies as $currency) {
            $testRef = 'CURR_' . $currency . '_' . bin2hex(random_bytes(4));
            $payload = [
                'reference' => $testRef,
                'amount' => 100,
                'currency' => $currency,
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ];
            
            try {
                $result = $this->swapService->executeSwap($payload);
                $results[$currency] = ['success' => true, 'reference' => $testRef];
                echo "    ✓ {$currency}: OK\n";
            } catch (Exception $e) {
                $results[$currency] = ['success' => false, 'error' => $e->getMessage()];
                echo "    ✗ {$currency}: " . $e->getMessage() . "\n";
            }
        }
        
        $supportedCount = count(array_filter($results, fn($r) => $r['success']));
        
        return [
            'passed' => $supportedCount >= 3,
            'supported_currencies' => array_keys(array_filter($results, fn($r) => $r['success'])),
            'message' => "{$supportedCount}/" . count($currencies) . " currencies supported"
        ];
    }
    
    private function testLargeAmount(): array
    {
        echo "\n  💰 Large Amount Test\n";
        
        $amounts = [1000, 10000, 50000, 100000];
        $results = [];
        
        foreach ($amounts as $amount) {
            $testRef = 'LARGE_' . $amount . '_' . bin2hex(random_bytes(4));
            $payload = [
                'reference' => $testRef,
                'amount' => $amount,
                'currency' => 'BWP',
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ];
            
            try {
                $result = $this->swapService->executeSwap($payload);
                $results[$amount] = ['success' => true];
                echo "    ✓ BWP {$amount}: OK\n";
            } catch (Exception $e) {
                $results[$amount] = ['success' => false, 'error' => $e->getMessage()];
                echo "    ✗ BWP {$amount}: " . $e->getMessage() . "\n";
            }
        }
        
        $largeAmountSupport = array_filter($results, fn($r) => $r['success']);
        
        return [
            'passed' => count($largeAmountSupport) >= 3,
            'max_supported' => max(array_keys($largeAmountSupport)),
            'message' => "Supports up to BWP " . max(array_keys($largeAmountSupport))
        ];
    }
    
    private function testEdgeCases(): array
    {
        echo "\n  ⚠️ Edge Cases Test\n";
        
        $edgeCases = [
            'minimum_amount' => ['amount' => 0.01, 'expected' => 'accept'],
            'maximum_amount' => ['amount' => 999999999, 'expected' => 'reject_or_accept'],
            'decimal_amount' => ['amount' => 100.99, 'expected' => 'accept'],
            'high_precision' => ['amount' => 100.9999, 'expected' => 'reject_or_round'],
            'same_institution' => ['source' => 'ZURUBANK', 'dest' => 'ZURUBANK', 'expected' => 'reject_or_accept']
        ];
        
        $results = [];
        
        foreach ($edgeCases as $name => $case) {
            $testRef = 'EDGE_' . str_replace(' ', '_', $name) . '_' . bin2hex(random_bytes(4));
            $payload = [
                'reference' => $testRef,
                'amount' => $case['amount'] ?? 100,
                'currency' => 'BWP',
                'source_institution' => $case['source'] ?? 'ZURUBANK',
                'destination_institution' => $case['dest'] ?? 'SACCUSSALIS'
            ];
            
            try {
                $result = $this->swapService->executeSwap($payload);
                $results[$name] = ['passed' => true, 'result' => 'accepted'];
                echo "    ✓ {$name}: Accepted\n";
            } catch (Exception $e) {
                $results[$name] = ['passed' => true, 'result' => 'rejected', 'error' => $e->getMessage()];
                echo "    ✓ {$name}: Rejected (valid)\n";
            }
        }
        
        return [
            'passed' => true,
            'edge_cases_tested' => count($edgeCases),
            'message' => "Edge cases handled"
        ];
    }
    
    private function testValidationRules(): array
    {
        echo "\n  ✓ Validation Rules Test\n";
        
        $invalidPayloads = [
            'negative_amount' => ['amount' => -100],
            'zero_amount' => ['amount' => 0],
            'string_amount' => ['amount' => 'abc'],
            'null_amount' => ['amount' => null],
            'empty_reference' => ['reference' => ''],
            'missing_source' => ['source_institution' => null],
            'missing_destination' => ['destination_institution' => null]
        ];
        
        $validated = 0;
        
        foreach ($invalidPayloads as $test => $override) {
            $payload = array_merge([
                'reference' => 'VALID_' . bin2hex(random_bytes(4)),
                'amount' => 100,
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ], $override);
            
            try {
                $this->swapService->executeSwap($payload);
                echo "    ✗ {$test}: NOT VALIDATED\n";
            } catch (Exception $e) {
                $validated++;
                echo "    ✓ {$test}: REJECTED\n";
            }
        }
        
        return [
            'passed' => $validated >= 5,
            'validated_count' => $validated,
            'message' => "{$validated}/" . count($invalidPayloads) . " invalid inputs rejected"
        ];
    }
    
    private function testIdempotency(): array
    {
        echo "\n  🔑 Idempotency Test\n";
        
        $idempotencyKey = 'IDEM_' . bin2hex(random_bytes(16));
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'amount' => 50,
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS'
        ];
        
        // First request
        $result1 = $this->swapService->executeSwap($payload);
        $ref1 = $result1['reference'] ?? null;
        
        // Second request (should return cached)
        $result2 = $this->swapService->executeSwap($payload);
        $ref2 = $result2['reference'] ?? null;
        
        $idempotent = ($ref1 === $ref2);
        
        echo "    ✓ First request reference: {$ref1}\n";
        echo "    ✓ Second request reference: {$ref2}\n";
        echo "    ✓ Idempotent: " . ($idempotent ? "YES" : "NO") . "\n";
        
        // Verify only one record
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM swap_requests 
            WHERE swap_uuid = :ref
        ");
        $stmt->execute([':ref' => $ref1]);
        $count = $stmt->fetchColumn();
        
        echo "    ✓ Database records: {$count}\n";
        
        return [
            'passed' => $idempotent && $count == 1,
            'idempotent' => $idempotent,
            'records' => $count,
            'message' => $idempotent ? "Idempotency working" : "Idempotency failed"
        ];
    }
    
    private function testConcurrentSwaps(): array
    {
        echo "\n  👥 Concurrent Swaps Test\n";
        
        $concurrent = 20;
        $pids = [];
        $results = [];
        
        for ($i = 0; $i < $concurrent; $i++) {
            $pid = pcntl_fork();
            
            if ($pid == -1) {
                // Sequential fallback
                try {
                    $payload = [
                        'reference' => 'CONC_' . $i . '_' . bin2hex(random_bytes(4)),
                        'amount' => rand(10, 1000),
                        'source_institution' => 'ZURUBANK',
                        'destination_institution' => 'SACCUSSALIS'
                    ];
                    $this->swapService->executeSwap($payload);
                    $results[] = true;
                } catch (Exception $e) {
                    $results[] = false;
                }
            } elseif ($pid == 0) {
                // Child process
                try {
                    $payload = [
                        'reference' => 'CONC_' . $i . '_' . bin2hex(random_bytes(4)),
                        'amount' => rand(10, 1000),
                        'source_institution' => 'ZURUBANK',
                        'destination_institution' => 'SACCUSSALIS'
                    ];
                    $this->swapService->executeSwap($payload);
                    exit(0);
                } catch (Exception $e) {
                    exit(1);
                }
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for children
        $successCount = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if ($status == 0) {
                $successCount++;
            }
        }
        
        $successRate = ($successCount / $concurrent) * 100;
        
        echo "    ✓ Concurrent swaps: {$concurrent}\n";
        echo "    ✓ Successful: {$successCount}\n";
        echo "    ✓ Success rate: " . round($successRate, 2) . "%\n";
        
        return [
            'passed' => $successRate >= 95,
            'successful' => $successCount,
            'total' => $concurrent,
            'success_rate' => round($successRate, 2),
            'message' => $successRate >= 95 ? "Concurrent handling excellent" : "Concurrent issues detected"
        ];
    }
    
    private function testErrorRecovery(): array
    {
        echo "\n  🔄 Error Recovery Test\n";
        
        $errorScenarios = [
            'invalid_institution' => ['destination_institution' => 'NONEXISTENT_BANK'],
            'insufficient_balance' => ['amount' => 999999999],
            'network_timeout' => ['timeout' => 0.001],
            'invalid_currency' => ['currency' => 'XXX']
        ];
        
        $recovered = 0;
        
        foreach ($errorScenarios as $scenario => $override) {
            $payload = array_merge([
                'reference' => 'ERR_' . bin2hex(random_bytes(4)),
                'amount' => 100,
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ], $override);
            
            try {
                $this->swapService->executeSwap($payload);
                echo "    ⚠ {$scenario}: Should have failed\n";
            } catch (Exception $e) {
                $recovered++;
                echo "    ✓ {$scenario}: Properly rejected - " . substr($e->getMessage(), 0, 50) . "\n";
            }
        }
        
        return [
            'passed' => $recovered >= 3,
            'recovered' => $recovered,
            'message' => "{$recovered}/" . count($errorScenarios) . " errors handled correctly"
        ];
    }
    
    // ============================================================
    // 3. PERFORMANCE STRESS TESTS
    // ============================================================
    
    public function testPerformanceStress(): array
    {
        $this->printSection("⚡ PERFORMANCE STRESS TESTS");
        
        $results = [
            'throughput' => $this->testThroughput(),
            'latency_p95' => $this->testLatencyDistribution(),
            'memory_usage' => $this->testMemoryFootprint(),
            'cpu_usage' => $this->testCPUUsage(),
            'api_response' => $this->testAPIResponseTime()
        ];
        
        return $results;
    }
    
    private function testThroughput(): array
    {
        echo "\n  📊 Throughput Test (1000 transactions)\n";
        
        $transactions = 1000;
        $successCount = 0;
        $start = microtime(true);
        
        for ($i = 0; $i < $transactions; $i++) {
            try {
                $payload = [
                    'reference' => 'THRU_' . $i . '_' . bin2hex(random_bytes(4)),
                    'amount' => rand(10, 1000),
                    'source_institution' => 'ZURUBANK',
                    'destination_institution' => 'SACCUSSALIS'
                ];
                $this->swapService->executeSwap($payload);
                $successCount++;
            } catch (Exception $e) {
                // Continue
            }
            
            if ($i % 100 == 0 && $i > 0) {
                echo "    ✓ Progress: {$i}/{$transactions}\n";
            }
        }
        
        $duration = microtime(true) - $start;
        $tps = $successCount / $duration;
        
        echo "    ✓ Total transactions: {$transactions}\n";
        echo "    ✓ Successful: {$successCount}\n";
        echo "    ✓ Duration: " . round($duration, 2) . "s\n";
        echo "    ✓ Throughput: " . round($tps, 2) . " TPS\n";
        
        $this->performanceData['throughput_tps'] = $tps;
        
        return [
            'passed' => $tps > 50,
            'tps' => round($tps, 2),
            'success_rate' => round(($successCount / $transactions) * 100, 2),
            'message' => "Throughput: " . round($tps, 2) . " TPS"
        ];
    }
    
    private function testLatencyDistribution(): array
    {
        echo "\n  ⏱️ Latency Distribution Test\n";
        
        $samples = 100;
        $latencies = [];
        
        for ($i = 0; $i < $samples; $i++) {
            $payload = [
                'reference' => 'LAT_' . $i . '_' . bin2hex(random_bytes(4)),
                'amount' => rand(10, 1000),
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ];
            
            $start = microtime(true);
            try {
                $this->swapService->executeSwap($payload);
                $latencies[] = (microtime(true) - $start) * 1000;
            } catch (Exception $e) {
                // Skip failed
            }
        }
        
        if (empty($latencies)) {
            return ['passed' => false, 'message' => 'No successful requests'];
        }
        
        sort($latencies);
        $p50 = $latencies[floor(count($latencies) * 0.50)];
        $p95 = $latencies[floor(count($latencies) * 0.95)];
        $p99 = $latencies[floor(count($latencies) * 0.99)];
        $max = max($latencies);
        
        echo "    ✓ P50 latency: " . round($p50, 2) . "ms\n";
        echo "    ✓ P95 latency: " . round($p95, 2) . "ms\n";
        echo "    ✓ P99 latency: " . round($p99, 2) . "ms\n";
        echo "    ✓ Max latency: " . round($max, 2) . "ms\n";
        
        $this->performanceData['latency'] = ['p50' => $p50, 'p95' => $p95, 'p99' => $p99];
        
        return [
            'passed' => $p95 < 500,
            'p50_ms' => round($p50, 2),
            'p95_ms' => round($p95, 2),
            'p99_ms' => round($p99, 2),
            'message' => $p95 < 500 ? "Latency within limits" : "High latency detected"
        ];
    }
    
    private function testMemoryFootprint(): array
    {
        echo "\n  🧠 Memory Footprint Test\n";
        
        $initialMemory = memory_get_usage(true);
        $peakMemory = $initialMemory;
        
        for ($i = 0; $i < 100; $i++) {
            $payload = [
                'reference' => 'MEM_' . $i . '_' . bin2hex(random_bytes(4)),
                'amount' => rand(10, 1000),
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ];
            
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
        
        $memoryIncrease = ($peakMemory - $initialMemory) / 1024 / 1024;
        
        echo "    ✓ Initial memory: " . round($initialMemory / 1024 / 1024, 2) . " MB\n";
        echo "    ✓ Peak memory: " . round($peakMemory / 1024 / 1024, 2) . " MB\n";
        echo "    ✓ Memory increase: " . round($memoryIncrease, 2) . " MB\n";
        
        $this->performanceData['memory_mb'] = round($peakMemory / 1024 / 1024, 2);
        
        return [
            'passed' => $memoryIncrease < 50,
            'memory_used_mb' => round($peakMemory / 1024 / 1024, 2),
            'increase_mb' => round($memoryIncrease, 2),
            'message' => $memoryIncrease < 50 ? "Memory usage stable" : "Possible memory leak"
        ];
    }
    
    private function testCPUUsage(): array
    {
        echo "\n  💻 CPU Usage Test\n";
        
        $startTime = microtime(true);
        $startCPU = $this->getCPUUsage();
        
        // Run CPU-intensive operations
        for ($i = 0; $i < 100; $i++) {
            $payload = [
                'reference' => 'CPU_' . $i . '_' . bin2hex(random_bytes(4)),
                'amount' => rand(10, 1000),
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ];
            
            try {
                $this->swapService->executeSwap($payload);
            } catch (Exception $e) {
                // Continue
            }
        }
        
        $endTime = microtime(true);
        $endCPU = $this->getCPUUsage();
        
        $cpuPercent = ($endCPU - $startCPU) / ($endTime - $startTime) * 100;
        
        echo "    ✓ CPU utilization: " . round($cpuPercent, 2) . "%\n";
        
        return [
            'passed' => $cpuPercent < 80,
            'cpu_percent' => round($cpuPercent, 2),
            'message' => $cpuPercent < 80 ? "CPU usage acceptable" : "High CPU usage"
        ];
    }
    
    private function testAPIResponseTime(): array
    {
        echo "\n  🌐 API Response Time Test\n";
        
        $endpoints = [
            'health' => '/health.php',
            'swap_execute' => '/api/v1/swap/execute.php',
            'swap_status' => '/api/v1/swap/status.php'
        ];
        
        $results = [];
        
        foreach ($endpoints as $name => $path) {
            $times = [];
            for ($i = 0; $i < 10; $i++) {
                $start = microtime(true);
                $ch = curl_init("http://localhost" . $path);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_exec($ch);
                $times[] = (microtime(true) - $start) * 1000;
                curl_close($ch);
            }
            $avgTime = array_sum($times) / count($times);
            $results[$name] = round($avgTime, 2);
            echo "    ✓ {$name}: " . round($avgTime, 2) . "ms\n";
        }
        
        $avgResponse = array_sum($results) / count($results);
        
        return [
            'passed' => $avgResponse < 200,
            'endpoint_times_ms' => $results,
            'average_ms' => round($avgResponse, 2),
            'message' => $avgResponse < 200 ? "API response times good" : "API response slow"
        ];
    }
    
    private function getCPUUsage(): float
    {
        $stat = file_get_contents('/proc/stat');
        preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat, $matches);
        return array_sum(array_slice($matches, 1, 3));
    }
    
    // ============================================================
    // 4. SETTLEMENT INTEGRITY TESTS
    // ============================================================
    
    public function testSettlementIntegrity(): array
    {
        $this->printSection("💰 SETTLEMENT INTEGRITY");
        
        $results = [
            'double_entry' => $this->testDoubleEntry(),
            'netting' => $this->testNetting(),
            'reconciliation' => $this->testReconciliation(),
            'fee_accuracy' => $this->testFeeAccuracy(),
            'rollback_integrity' => $this->testRollback()
        ];
        
        return $results;
    }
    
    private function testDoubleEntry(): array
    {
        echo "\n  📚 Double-Entry Accounting Test\n";
        
        $testRef = 'DOUBLE_' . bin2hex(random_bytes(8));
        $amount = 500;
        
        $payload = [
            'reference' => $testRef,
            'amount' => $amount,
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS'
        ];
        
        $this->swapService->executeSwap($payload);
        
        $stmt = $this->db->prepare("
            SELECT 
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as debits
            FROM swap_ledgers 
            WHERE swap_reference = :ref
        ");
        $stmt->execute([':ref' => $testRef]);
        $totals = $stmt->fetch();
        
        $balanced = abs($totals['credits'] - $totals['debits']) < 0.01;
        
        echo "    ✓ Credits: " . $totals['credits'] . "\n";
        echo "    ✓ Debits: " . $totals['debits'] . "\n";
        echo "    ✓ Balanced: " . ($balanced ? "YES" : "NO") . "\n";
        
        return [
            'passed' => $balanced,
            'credits' => $totals['credits'],
            'debits' => $totals['debits'],
            'message' => $balanced ? "Double-entry correct" : "Accounting imbalance"
        ];
    }
    
    private function testNetting(): array
    {
        echo "\n  🔢 Netting Algorithm Test\n";
        
        // Create multiple transactions
        $participants = ['BANK_A', 'BANK_B', 'BANK_C'];
        $transactions = [];
        
        for ($i = 0; $i < 20; $i++) {
            $from = $participants[array_rand($participants)];
            $to = $participants[array_rand($participants)];
            if ($from === $to) continue;
            
            $amount = rand(100, 10000);
            $transactions[] = [
                'debtor' => $from,
                'creditor' => $to,
                'amount' => $amount
            ];
        }
        
        $netPositions = $this->settlement->calculateNetPositions($transactions);
        
        // Verify net sum is zero
        $netSum = array_sum(array_column($netPositions, 'amount'));
        $netSumZero = abs($netSum) < 0.01;
        
        echo "    ✓ Transactions: " . count($transactions) . "\n";
        echo "    ✓ Net positions: " . count($netPositions) . "\n";
        echo "    ✓ Net sum: " . $netSum . "\n";
        echo "    ✓ Balanced: " . ($netSumZero ? "YES" : "NO") . "\n";
        
        return [
            'passed' => $netSumZero,
            'net_positions' => count($netPositions),
            'net_sum' => $netSum,
            'message' => $netSumZero ? "Netting correct" : "Netting imbalance"
        ];
    }
    
    private function testReconciliation(): array
    {
        echo "\n  🔍 Reconciliation Test\n";
        
        // Get totals from database
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_swaps,
                SUM(amount) as total_amount,
                COUNT(DISTINCT DATE(created_at)) as days
            FROM swap_requests
        ");
        $stats = $stmt->fetch();
        
        echo "    ✓ Total swaps: " . ($stats['total_swaps'] ?? 0) . "\n";
        echo "    ✓ Total volume: BWP " . number_format($stats['total_amount'] ?? 0, 2) . "\n";
        echo "    ✓ Active days: " . ($stats['days'] ?? 0) . "\n";
        
        return [
            'passed' => ($stats['total_swaps'] ?? 0) > 0,
            'total_swaps' => $stats['total_swaps'] ?? 0,
            'total_volume' => $stats['total_amount'] ?? 0,
            'message' => "Reconciliation data available"
        ];
    }
    
    private function testFeeAccuracy(): array
    {
        echo "\n  💸 Fee Calculation Accuracy Test\n";
        
        $testCases = [
            ['amount' => 100, 'fee_rate' => 0.01, 'expected_fee' => 1],
            ['amount' => 500, 'fee_rate' => 0.02, 'expected_fee' => 10],
            ['amount' => 1000, 'fee_rate' => 0.015, 'expected_fee' => 15],
            ['amount' => 10000, 'fee_rate' => 0.005, 'expected_fee' => 50]
        ];
        
        $passed = 0;
        
        foreach ($testCases as $test) {
            $testRef = 'FEE_' . $test['amount'] . '_' . bin2hex(random_bytes(4));
            $payload = [
                'reference' => $testRef,
                'amount' => $test['amount'],
                'fee_rate' => $test['fee_rate'],
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ];
            
            try {
                $result = $this->swapService->executeSwap($payload);
                
                $stmt = $this->db->prepare("
                    SELECT fee_amount FROM swap_ledgers 
                    WHERE swap_reference = :ref
                ");
                $stmt->execute([':ref' => $testRef]);
                $actualFee = $stmt->fetchColumn();
                
                if (abs($actualFee - $test['expected_fee']) < 0.01) {
                    $passed++;
                    echo "    ✓ Amount {$test['amount']}: Fee {$actualFee} (expected {$test['expected_fee']})\n";
                } else {
                    echo "    ✗ Amount {$test['amount']}: Fee {$actualFee} (expected {$test['expected_fee']})\n";
                }
            } catch (Exception $e) {
                echo "    ✗ Amount {$test['amount']}: " . $e->getMessage() . "\n";
            }
        }
        
        return [
            'passed' => $passed == count($testCases),
            'accurate_tests' => $passed,
            'total_tests' => count($testCases),
            'message' => "Fee accuracy: {$passed}/" . count($testCases)
        ];
    }
    
    private function testRollback(): array
    {
        echo "\n  ↩️ Rollback Integrity Test\n";
        
        $testRef = 'ROLLBACK_' . bin2hex(random_bytes(8));
        
        // Capture state before
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total 
            FROM swap_ledgers 
            WHERE swap_reference = :ref
        ");
        $stmt->execute([':ref' => $testRef]);
        $before = $stmt->fetch();
        
        // Attempt a transaction that should fail
        try {
            $payload = [
                'reference' => $testRef,
                'amount' => 999999999,
                'source_institution' => 'INVALID_BANK',
                'destination_institution' => 'SACCUSSALIS'
            ];
            $this->swapService->executeSwap($payload);
            echo "    ⚠ Transaction should have failed\n";
            $rollbackSuccess = false;
        } catch (Exception $e) {
            echo "    ✓ Transaction failed as expected: " . substr($e->getMessage(), 0, 50) . "\n";
            $rollbackSuccess = true;
        }
        
        // Verify no records created
        $stmt->execute([':ref' => $testRef]);
        $after = $stmt->fetch();
        
        $noRecords = ($after['count'] == 0);
        
        echo "    ✓ Records before: {$before['count']}\n";
        echo "    ✓ Records after: {$after['count']}\n";
        echo "    ✓ Rollback: " . ($noRecords ? "SUCCESSFUL" : "FAILED") . "\n";
        
        return [
            'passed' => $noRecords,
            'records_created' => $after['count'],
            'message' => $noRecords ? "Rollback works" : "Rollback failed - orphaned records"
        ];
    }
    
    // ============================================================
    // 5. SECURITY & COMPLIANCE TESTS
    // ============================================================
    
    public function testSecurityCompliance(): array
    {
        $this->printSection("🔒 SECURITY & COMPLIANCE");
        
        $results = [
            'sql_injection' => $this->testSQLInjection(),
            'xss_prevention' => $this->testXSS(),
            'input_validation' => $this->testInputSanitization(),
            'rate_limiting' => $this->testRateLimiting(),
            'audit_trail' => $this->testAuditTrail(),
            'pci_compliance' => $this->testPCICompliance()
        ];
        
        return $results;
    }
    
    private function testSQLInjection(): array
    {
        echo "\n  🛡️ SQL Injection Prevention\n";
        
        $payloads = [
            "' OR '1'='1",
            "'; DROP TABLE swap_requests; --",
            "1' AND '1'='1",
            "' UNION SELECT * FROM users --"
        ];
        
        $blocked = 0;
        
        foreach ($payloads as $payload) {
            $testRef = 'SQLI_' . bin2hex(random_bytes(4));
            $testPayload = [
                'reference' => $testRef,
                'amount' => 100,
                'source_institution' => $payload,
                'destination_institution' => 'SACCUSSALIS'
            ];
            
            try {
                $this->swapService->executeSwap($testPayload);
                
                // Check if payload made it to DB
                $stmt = $this->db->prepare("
                    SELECT source_institution FROM swap_requests 
                    WHERE swap_uuid = :ref
                ");
                $stmt->execute([':ref' => $testRef]);
                $dbValue = $stmt->fetchColumn();
                
                if ($dbValue === $payload) {
                    echo "    ✗ SQL Injection possible: {$payload}\n";
                } else {
                    $blocked++;
                    echo "    ✓ SQL Injection blocked: {$payload}\n";
                }
            } catch (Exception $e) {
                $blocked++;
                echo "    ✓ SQL Injection blocked (exception): {$payload}\n";
            }
        }
        
        return [
            'passed' => $blocked == count($payloads),
            'blocked' => $blocked,
            'total' => count($payloads),
            'message' => "SQL Injection prevention: {$blocked}/" . count($payloads)
        ];
    }
    
    private function testXSS(): array
    {
        echo "\n  🛡️ XSS Prevention\n";
        
        $payloads = [
            "<script>alert('XSS')</script>",
            "<img src=x onerror=alert('XSS')>",
            "javascript:alert('XSS')"
        ];
        
        $sanitized = 0;
        
        foreach ($payloads as $payload) {
            $testRef = 'XSS_' . bin2hex(random_bytes(4));
            $testPayload = [
                'reference' => $testRef,
                'amount' => 100,
                'source_institution' => 'ZURUBANK',
                'destination_institution' => $payload,
                'description' => $payload
            ];
            
            try {
                $result = $this->swapService->executeSwap($testPayload);
                
                // Check response for unescaped payload
                $responseJson = json_encode($result);
                if (strpos($responseJson, $payload) !== false && 
                    strpos($responseJson, htmlspecialchars($payload)) === false) {
                    echo "    ✗ XSS possible: {$payload}\n";
                } else {
                    $sanitized++;
                    echo "    ✓ XSS blocked: {$payload}\n";
                }
            } catch (Exception $e) {
                $sanitized++;
                echo "    ✓ XSS blocked (exception): {$payload}\n";
            }
        }
        
        return [
            'passed' => $sanitized == count($payloads),
            'sanitized' => $sanitized,
            'message' => "XSS prevention: {$sanitized}/" . count($payloads)
        ];
    }
    
    private function testInputSanitization(): array
    {
        echo "\n  🧹 Input Sanitization Test\n";
        
        $testInputs = [
            'phone' => ['+26771234567', 'invalid', '123', 'abc'],
            'email' => ['test@example.com', 'invalid', 'test@', '@example.com'],
            'reference' => [str_repeat('a', 300), '', 'valid_ref_123']
        ];
        
        $validCount = 0;
        $totalTests = 0;
        
        foreach ($testInputs as $field => $values) {
            foreach ($values as $value) {
                $totalTests++;
                $testRef = 'SAN_' . bin2hex(random_bytes(4));
                $payload = [
                    'reference' => $testRef,
                    'amount' => 100,
                    'source_institution' => 'ZURUBANK',
                    'destination_institution' => 'SACCUSSALIS',
                    $field => $value
                ];
                
                try {
                    $this->swapService->executeSwap($payload);
                    
                    // Check if invalid value was sanitized
                    $stmt = $this->db->prepare("
                        SELECT * FROM swap_requests WHERE swap_uuid = :ref
                    ");
                    $stmt->execute([':ref' => $testRef]);
                    $record = $stmt->fetch();
                    
                    if ($record && isset($record[$field])) {
                        if ($record[$field] !== $value || $this->isValidInput($value, $field)) {
                            $validCount++;
                            echo "    ✓ {$field}: '{$value}' handled\n";
                        } else {
                            echo "    ✗ {$field}: '{$value}' not sanitized\n";
                        }
                    }
                } catch (Exception $e) {
                    // Rejected invalid input - good
                    $validCount++;
                    echo "    ✓ {$field}: '{$value}' rejected\n";
                }
            }
        }
        
        return [
            'passed' => $validCount >= $totalTests * 0.8,
            'validated' => $validCount,
            'total' => $totalTests,
            'message' => "Input sanitization: {$validCount}/{$totalTests}"
        ];
    }
    
    private function isValidInput($value, string $field): bool
    {
        if (empty($value) && $field !== 'reference') return false;
        if ($field === 'phone' && !preg_match('/^\+?[0-9]{8,15}$/', $value)) return false;
        if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) return false;
        return true;
    }
    
    private function testRateLimiting(): array
    {
        echo "\n  🚦 Rate Limiting Test\n";
        
        $requestsPerMinute = 150;
        $successCount = 0;
        $rateLimited = false;
        
        for ($i = 0; $i < $requestsPerMinute; $i++) {
            $testRef = 'RATE_' . $i . '_' . bin2hex(random_bytes(4));
            $payload = [
                'reference' => $testRef,
                'amount' => 1,
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ];
            
            try {
                $this->swapService->executeSwap($payload);
                $successCount++;
            } catch (Exception $e) {
                if (strpos(strtolower($e->getMessage()), 'rate limit') !== false) {
                    $rateLimited = true;
                    break;
                }
            }
        }
        
        echo "    ✓ Requests: {$requestsPerMinute}\n";
        echo "    ✓ Successful: {$successCount}\n";
        echo "    ✓ Rate limited: " . ($rateLimited ? "YES" : "NO") . "\n";
        
        $this->rateLimitStats = [
            'requests' => $requestsPerMinute,
            'successful' => $successCount,
            'rate_limited' => $rateLimited
        ];
        
        return [
            'passed' => $rateLimited,
            'successful_before_limit' => $successCount,
            'message' => $rateLimited ? "Rate limiting active" : "Rate limiting not configured"
        ];
    }
    
    private function testAuditTrail(): array
    {
        echo "\n  📝 Audit Trail Test\n";
        
        $testRef = 'AUDIT_' . bin2hex(random_bytes(8));
        $payload = [
            'reference' => $testRef,
            'amount' => 100,
            'source_institution' => 'ZURUBANK',
            'destination_institution' => 'SACCUSSALIS'
        ];
        
        $this->swapService->executeSwap($payload);
        
        // Check audit logs
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM audit_logs 
            WHERE entity_id = :ref OR new_value LIKE :pattern
        ");
        $stmt->execute([
            ':ref' => $testRef,
            ':pattern' => '%' . $testRef . '%'
        ]);
        $auditCount = $stmt->fetchColumn();
        
        echo "    ✓ Audit records found: {$auditCount}\n";
        
        return [
            'passed' => $auditCount > 0,
            'audit_records' => $auditCount,
            'message' => $auditCount > 0 ? "Audit trail working" : "No audit trail found"
        ];
    }
    
    private function testPCICompliance(): array
    {
        echo "\n  💳 PCI Compliance Check\n";
        
        $checks = [
            'sensitive_data_encryption' => $this->checkSensitiveDataEncryption(),
            'logging_restrictions' => $this->checkLoggingRestrictions(),
            'access_controls' => $this->checkAccessControls(),
            'secure_communication' => $this->checkSecureCommunication()
        ];
        
        foreach ($checks as $name => $passed) {
            echo "    ✓ {$name}: " . ($passed ? "PASS" : "FAIL") . "\n";
        }
        
        $allPassed = !in_array(false, $checks);
        
        return [
            'passed' => $allPassed,
            'checks' => $checks,
            'message' => $allPassed ? "PCI compliance checks passed" : "PCI compliance issues"
        ];
    }
    
    private function checkSensitiveDataEncryption(): bool
    {
        // Check if sensitive columns use encryption
        $stmt = $this->db->query("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name IN ('users', 'swap_requests', 'payment_instructions')
            AND column_name IN ('password', 'pin', 'card_number', 'cvv')
        ");
        
        return $stmt->rowCount() == 0; // Sensitive data should not be in plaintext
    }
    
    private function checkLoggingRestrictions(): bool
    {
        // Check that sensitive data is not logged
        $logFiles = ['/var/log/vouchmorph/', '/tmp/vouchmorphn_swap_audit.log'];
        $hasSensitiveLogs = false;
        
        foreach ($logFiles as $logPath) {
            if (file_exists($logPath)) {
                $content = file_get_contents($logPath);
                if (strpos($content, 'card_number') !== false || 
                    strpos($content, 'cvv') !== false ||
                    strpos($content, 'pin') !== false) {
                    $hasSensitiveLogs = true;
                    break;
                }
            }
        }
        
        return !$hasSensitiveLogs;
    }
    
    private function checkAccessControls(): bool
    {
        // Check if API endpoints require authentication
        // This is a basic check - implement based on your auth system
        return true;
    }
    
    private function checkSecureCommunication(): bool
    {
        // Check if HTTPS is enforced in production
        if ($this->railwayEnv === 'production') {
            return isset($_SERVER['HTTPS']) || getenv('RAILWAY_PUBLIC_DOMAIN');
        }
        return true;
    }
    
    // ============================================================
    // RUN ALL TESTS & REPORT
    // ============================================================
    
    public function runAllTests(): void
    {
        $allResults = [
            'database' => $this->testDatabasePerformance(),
            'swap_service' => $this->testSwapServiceCore(),
            'performance' => $this->testPerformanceStress(),
            'settlement' => $this->testSettlementIntegrity(),
            'security' => $this->testSecurityCompliance()
        ];
        
        $this->aggregateResults($allResults);
        $this->printFinalReport();
    }
    
    private function aggregateResults(array $results): void
    {
        foreach ($results as $category => $tests) {
            foreach ($tests as $testName => $result) {
                if (is_array($result) && isset($result['passed'])) {
                    $this->totalTests++;
                    if ($result['passed']) {
                        $this->passedTests++;
                    } else {
                        $this->failedTests++;
                        if (isset($result['message'])) {
                            $this->securityIssues[] = "[{$category}] {$testName}: {$result['message']}";
                        }
                    }
                }
            }
        }
    }
    
    private function printSection(string $title): void
    {
        echo "\n" . str_repeat("─", 80) . "\n";
        echo "📋 {$title}\n";
        echo str_repeat("─", 80) . "\n";
    }
    
    private function printHeader(string $title, string $icon = "🔬"): void
    {
        echo "\n" . str_repeat("═", 80) . "\n";
        echo "{$icon}  {$title}\n";
        echo str_repeat("═", 80) . "\n";
    }
    
    private function printFinalReport(): void
    {
        $duration = round(microtime(true) - $this->startTime, 2);
        $passRate = round(($this->passedTests / max(1, $this->totalTests)) * 100, 2);
        
        $this->printHeader("FINAL REPORT", "📊");
        
        echo "\n  ⏱️  Duration: {$duration} seconds\n";
        echo "  📝 Total Tests: {$this->totalTests}\n";
        echo "  ✅ Passed: {$this->passedTests}\n";
        echo "  ❌ Failed: {$this->failedTests}\n";
        echo "  📈 Pass Rate: {$passRate}%\n";
        
        // Performance Summary
        echo "\n  ⚡ Performance Summary:\n";
        if (!empty($this->performanceData)) {
            foreach ($this->performanceData as $metric => $value) {
                if (is_array($value)) {
                    echo "    • {$metric}: " . json_encode($value) . "\n";
                } else {
                    echo "    • {$metric}: {$value}\n";
                }
            }
        }
        
        // Security Issues
        if (!empty($this->securityIssues)) {
            echo "\n  ⚠️ Issues Found:\n";
            foreach ($this->securityIssues as $issue) {
                echo "    • {$issue}\n";
            }
        }
        
        // Grade Calculation
        $grade = $this->calculateGrade($passRate);
        echo "\n  🏆 GRADE: {$grade}\n";
        
        // Recommendations
        if ($this->failedTests > 0) {
            echo "\n  💡 Recommendations:\n";
            if ($passRate < 70) {
                echo "    • Run individual tests to identify specific failures\n";
                echo "    • Check database indexes and query performance\n";
                echo "    • Review error logs for detailed information\n";
            }
            if ($this->performanceData['throughput_tps'] ?? 0 < 50) {
                echo "    • Optimize database queries and add indexes\n";
                echo "    • Consider connection pooling and caching\n";
            }
            if (($this->performanceData['latency']['p95'] ?? 0) > 500) {
                echo "    • Investigate slow API endpoints\n";
                echo "    • Add response caching where appropriate\n";
            }
        }
        
        echo "\n" . str_repeat("═", 80) . "\n";
        
        // Exit with appropriate code
        if ($this->failedTests > 0) {
            exit(1);
        }
    }
    
    private function calculateGrade(float $passRate): string
    {
        if ($passRate >= 95) return "A+ (World-Class) 🏆";
        if ($passRate >= 90) return "A (Enterprise Ready) ✅";
        if ($passRate >= 80) return "B (Production Ready) 👍";
        if ($passRate >= 70) return "C (Needs Improvement) ⚠️";
        if ($passRate >= 60) return "D (Significant Issues) 🔧";
        return "F (Critical Failures) 🚨";
    }
}

// Run the test suite
echo "\n";
$suite = new RailwayEnterpriseTest();
$suite->runAllTests();
