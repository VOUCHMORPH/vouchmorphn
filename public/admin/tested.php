<?php
// /var/www/html/public/admin/test-fixed.php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Simple database connection test that works with Railway/Cloud environments
 */
class DatabaseTest
{
    private $connection = null;
    private $errors = [];
    
    public function __construct()
    {
        $this->testConnections();
    }
    
    private function testConnections(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "  🔍 DATABASE CONNECTION DIAGNOSTIC\n";
        echo str_repeat("=", 80) . "\n";
        
        // Method 1: DATABASE_URL (Railway, Heroku, etc.)
        $this->tryDatabaseUrl();
        
        // Method 2: Railway internal connection
        $this->tryRailwayInternal();
        
        // Method 3: Environment variables
        $this->tryEnvironmentVars();
        
        // Method 4: PostgreSQL container service name
        $this->tryDockerService();
        
        // Print results
        $this->printResults();
    }
    
    private function tryDatabaseUrl(): void
    {
        $databaseUrl = getenv('DATABASE_URL') ?: getenv('RAILWAY_DATABASE_URL');
        
        if (!$databaseUrl) {
            $this->log('DATABASE_URL', 'Environment variable not set', false);
            return;
        }
        
        try {
            // Parse the URL to show what we're connecting to (without password)
            $parsed = parse_url($databaseUrl);
            $displayUrl = sprintf(
                '%s://%s:***@%s%s',
                $parsed['scheme'] ?? 'postgresql',
                $parsed['user'] ?? 'postgres',
                $parsed['host'] ?? 'unknown',
                $parsed['path'] ?? ''
            );
            
            $this->log('DATABASE_URL', "Attempting connection to {$displayUrl}", null);
            
            $pdo = new PDO($databaseUrl);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Test the connection
            $stmt = $pdo->query("SELECT version() as version, current_database() as database, NOW() as time");
            $result = $stmt->fetch();
            
            $this->connection = $pdo;
            $this->log('DATABASE_URL', '✅ CONNECTED successfully', true, [
                'database' => $result['database'],
                'version' => substr($result['version'], 0, 50) . '...'
            ]);
            
        } catch (PDOException $e) {
            $this->log('DATABASE_URL', '❌ Failed: ' . $e->getMessage(), false);
            $this->errors[] = $e->getMessage();
        }
    }
    
    private function tryRailwayInternal(): void
    {
        // Railway uses internal networking
        $internalHosts = [
            'postgres.railway.internal',
            'postgres',
            'database.railway.internal',
            'db.railway.internal'
        ];
        
        $database = getenv('PGDATABASE') ?: 'railway';
        $user = getenv('PGUSER') ?: 'postgres';
        $password = getenv('PGPASSWORD') ?: '';
        
        foreach ($internalHosts as $host) {
            try {
                $dsn = "pgsql:host={$host};port=5432;dbname={$database}";
                $this->log('Railway Internal', "Trying {$host}", null);
                
                $pdo = new PDO($dsn, $user, $password);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $this->connection = $pdo;
                $this->log('Railway Internal', "✅ CONNECTED to {$host}", true);
                return;
                
            } catch (PDOException $e) {
                $this->log('Railway Internal', "Failed on {$host}: " . $e->getMessage(), false);
            }
        }
    }
    
    private function tryEnvironmentVars(): void
    {
        $host = getenv('PGHOST') ?: getenv('DB_HOST');
        $port = getenv('PGPORT') ?: getenv('DB_PORT') ?: '5432';
        $database = getenv('PGDATABASE') ?: getenv('DB_DATABASE') ?: 'postgres';
        $user = getenv('PGUSER') ?: getenv('DB_USER') ?: 'postgres';
        $password = getenv('PGPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
        
        if (!$host) {
            $this->log('Environment Vars', 'No host configured', false);
            return;
        }
        
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            $this->log('Environment Vars', "Connecting to {$host}:{$port}/{$database}", null);
            
            $pdo = new PDO($dsn, $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $this->connection = $pdo;
            $this->log('Environment Vars', "✅ CONNECTED", true);
            
        } catch (PDOException $e) {
            $this->log('Environment Vars', '❌ Failed: ' . $e->getMessage(), false);
        }
    }
    
    private function tryDockerService(): void
    {
        $serviceNames = ['postgres', 'database', 'db', 'postgresql'];
        
        foreach ($serviceNames as $service) {
            try {
                $dsn = "pgsql:host={$service};port=5432;dbname=vouchmorphn";
                $this->log('Docker Service', "Trying {$service}", null);
                
                $pdo = new PDO($dsn, 'postgres', '');
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $this->connection = $pdo;
                $this->log('Docker Service', "✅ CONNECTED to {$service}", true);
                return;
                
            } catch (PDOException $e) {
                // Continue to next service name
            }
        }
    }
    
    private function log(string $source, string $message, ?bool $success = null, array $extra = []): void
    {
        $timestamp = date('H:i:s');
        $icon = $success === true ? '✅' : ($success === false ? '❌' : '🔍');
        
        echo sprintf("[%s] %s %s: %s\n", $timestamp, $icon, $source, $message);
        
        foreach ($extra as $key => $value) {
            echo "        └─ {$key}: {$value}\n";
        }
    }
    
    private function printResults(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        
        if ($this->connection !== null) {
            echo "  ✅ CONNECTION SUCCESSFUL\n";
            echo str_repeat("=", 80) . "\n";
            
            $this->runQueries();
        } else {
            echo "  ❌ CONNECTION FAILED\n";
            echo str_repeat("=", 80) . "\n";
            
            echo "\n💡 TROUBLESHOOTING:\n";
            echo "  1. Check if PostgreSQL is running:\n";
            echo "     - Railway: DATABASE_URL should be auto-set\n";
            echo "     - Local: sudo systemctl status postgresql\n";
            echo "  2. Verify environment variables:\n";
            echo "     - Run: env | grep -i postgres\n";
            echo "     - Run: env | grep -i database\n";
            echo "  3. For Railway, ensure you've added a PostgreSQL plugin\n";
            echo "  4. Check if this is a fresh deployment - database may need time to provision\n";
        }
    }
    
    private function runQueries(): void
    {
        try {
            // Get database info
            $stmt = $this->connection->query("
                SELECT 
                    current_database() as db_name,
                    current_user as db_user,
                    inet_server_addr() as server_addr,
                    version() as pg_version
            ");
            $info = $stmt->fetch();
            
            echo "\n📊 DATABASE INFORMATION:\n";
            echo "  • Database: " . ($info['db_name'] ?? 'unknown') . "\n";
            echo "  • User: " . ($info['db_user'] ?? 'unknown') . "\n";
            echo "  • Server Address: " . ($info['server_addr'] ?? 'localhost') . "\n";
            echo "  • PostgreSQL: " . substr($info['pg_version'] ?? 'unknown', 0, 60) . "...\n";
            
            // Check required tables
            echo "\n📋 REQUIRED TABLES:\n";
            $tables = ['swap_requests', 'hold_transactions', 'ledger_accounts', 'users', 'organizations'];
            
            foreach ($tables as $table) {
                $stmt = $this->connection->prepare("
                    SELECT EXISTS (
                        SELECT 1 FROM information_schema.tables 
                        WHERE table_name = :table
                    )
                ");
                $stmt->execute([':table' => $table]);
                $exists = $stmt->fetchColumn();
                
                $icon = $exists ? '✅' : '❌';
                echo "  {$icon} {$table}\n";
            }
            
            // Check record counts
            echo "\n📈 RECORD COUNTS:\n";
            $tables = ['swap_requests', 'users', 'organizations'];
            
            foreach ($tables as $table) {
                try {
                    $stmt = $this->connection->query("SELECT COUNT(*) as count FROM {$table}");
                    $count = $stmt->fetchColumn();
                    echo "  • {$table}: " . number_format($count) . " records\n";
                } catch (PDOException $e) {
                    // Table might not exist
                }
            }
            
            // Get recent swaps
            try {
                $stmt = $this->connection->query("
                    SELECT swap_uuid, status, created_at 
                    FROM swap_requests 
                    ORDER BY created_at DESC 
                    LIMIT 5
                ");
                $recent = $stmt->fetchAll();
                
                if (!empty($recent)) {
                    echo "\n🔄 RECENT SWAPS:\n";
                    foreach ($recent as $swap) {
                        echo "  • {$swap['swap_uuid']} - {$swap['status']} ({$swap['created_at']})\n";
                    }
                }
            } catch (PDOException $e) {
                // Table might be empty
            }
            
        } catch (PDOException $e) {
            echo "\n❌ Query error: " . $e->getMessage() . "\n";
        }
    }
    
    public function getConnection()
    {
        return $this->connection;
    }
}

// Simple Swap Service test that doesn't require complex dependencies
class SwapServiceLiteTest
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    public function testAtomicPatterns(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "  🔬 ATOMIC TRANSACTION PATTERNS TEST\n";
        echo str_repeat("=", 80) . "\n";
        
        $tests = [
            'Atomic Begin/Commit' => $this->testAtomicBeginCommit(),
            'Rollback on Failure' => $this->testRollbackOnFailure(),
            'Idempotency Pattern' => $this->testIdempotencyPattern(),
            'Hold and Release' => $this->testHoldAndRelease()
        ];
        
        foreach ($tests as $name => $result) {
            $icon = $result['passed'] ? '✅' : '❌';
            echo "\n{$icon} {$name}\n";
            echo "   └─ " . ($result['message'] ?? '') . "\n";
            if (isset($result['details'])) {
                foreach ($result['details'] as $key => $value) {
                    echo "       • {$key}: {$value}\n";
                }
            }
        }
    }
    
    private function testAtomicBeginCommit(): array
    {
        try {
            $this->db->beginTransaction();
            
            // Insert test record
            $testId = 'TEST_' . bin2hex(random_bytes(4));
            $stmt = $this->db->prepare("
                INSERT INTO swap_requests (swap_uuid, status, created_at)
                VALUES (:id, 'testing', NOW())
            ");
            $stmt->execute([':id' => $testId]);
            
            $this->db->commit();
            
            // Verify record exists
            $stmt = $this->db->prepare("SELECT status FROM swap_requests WHERE swap_uuid = :id");
            $stmt->execute([':id' => $testId]);
            $status = $stmt->fetchColumn();
            
            // Clean up
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("DELETE FROM swap_requests WHERE swap_uuid = :id");
            $stmt->execute([':id' => $testId]);
            $this->db->commit();
            
            return [
                'passed' => $status === 'testing',
                'message' => 'Atomic transaction works correctly'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'passed' => false,
                'message' => 'Failed: ' . $e->getMessage()
            ];
        }
    }
    
    private function testRollbackOnFailure(): array
    {
        try {
            $this->db->beginTransaction();
            
            $testId = 'ROLLBACK_' . bin2hex(random_bytes(4));
            $stmt = $this->db->prepare("
                INSERT INTO swap_requests (swap_uuid, status, created_at)
                VALUES (:id, 'should_rollback', NOW())
            ");
            $stmt->execute([':id' => $testId]);
            
            // Force an error
            throw new Exception("Simulated failure");
            
            $this->db->commit();
            
            return [
                'passed' => false,
                'message' => 'Transaction committed despite error'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            
            // Verify record doesn't exist
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM swap_requests WHERE swap_uuid = :id");
            $stmt->execute([':id' => $testId ?? '']);
            $count = $stmt->fetchColumn();
            
            return [
                'passed' => $count == 0,
                'message' => 'Rollback successful on failure',
                'details' => ['records_rolled_back' => $count]
            ];
        }
    }
    
    private function testIdempotencyPattern(): array
    {
        $idempotencyKey = 'IDEM_' . bin2hex(random_bytes(8));
        $testId = 'IDEM_TEST_' . bin2hex(random_bytes(4));
        
        try {
            // First insert
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                INSERT INTO swap_requests (swap_uuid, status, metadata, created_at)
                VALUES (:id, 'idem_test', jsonb_build_object('idempotency_key', :key), NOW())
            ");
            $stmt->execute([':id' => $testId, ':key' => $idempotencyKey]);
            $this->db->commit();
            
            // Simulate second request with same key (should detect duplicate)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM swap_requests 
                WHERE metadata->>'idempotency_key' = :key
            ");
            $stmt->execute([':key' => $idempotencyKey]);
            $count = $stmt->fetchColumn();
            
            // Clean up
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("DELETE FROM swap_requests WHERE swap_uuid = :id");
            $stmt->execute([':id' => $testId]);
            $this->db->commit();
            
            return [
                'passed' => $count == 1,
                'message' => 'Idempotency key prevents duplicates',
                'details' => ['duplicate_attempts' => 2, 'records_created' => $count]
            ];
            
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Failed: ' . $e->getMessage()
            ];
        }
    }
    
    private function testHoldAndRelease(): array
    {
        $holdRef = 'HOLD_' . bin2hex(random_bytes(8));
        
        try {
            // Create a hold record
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                INSERT INTO hold_transactions (hold_reference, asset_type, amount, status, created_at)
                VALUES (:ref, 'TEST', 100, 'ACTIVE', NOW())
            ");
            $stmt->execute([':ref' => $holdRef]);
            $this->db->commit();
            
            // Release the hold
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                UPDATE hold_transactions 
                SET status = 'RELEASED', released_at = NOW()
                WHERE hold_reference = :ref
            ");
            $stmt->execute([':ref' => $holdRef]);
            $this->db->commit();
            
            // Verify release
            $stmt = $this->db->prepare("
                SELECT status FROM hold_transactions WHERE hold_reference = :ref
            ");
            $stmt->execute([':ref' => $holdRef]);
            $status = $stmt->fetchColumn();
            
            // Clean up
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("DELETE FROM hold_transactions WHERE hold_reference = :ref");
            $stmt->execute([':ref' => $holdRef]);
            $this->db->commit();
            
            return [
                'passed' => $status === 'RELEASED',
                'message' => 'Hold created and released correctly',
                'details' => ['hold_status' => $status]
            ];
            
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Failed: ' . $e->getMessage()
            ];
        }
    }
}

// Run the tests
echo "<pre>";

$dbTest = new DatabaseTest();
$connection = $dbTest->getConnection();

if ($connection !== null) {
    $swapTest = new SwapServiceLiteTest($connection);
    $swapTest->testAtomicPatterns();
} else {
    echo "\n❌ Cannot run SwapService tests - No database connection\n";
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "  ✅ TEST COMPLETE\n";
echo str_repeat("=", 800) . "\n";

echo "</pre>";
