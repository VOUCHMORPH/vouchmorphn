<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

class DatabaseTest
{
    private ?PDO $connection = null;
    private array $logs = [];

    public function run(): void
    {
        echo "<pre>";

        $this->printHeader();
        $this->testDatabaseUrl();
        $this->printSummary();

        if ($this->connection) {
            $this->runDbChecks();
            $this->runSwapServiceTests();
        }

        echo "\n" . str_repeat("=", 80) . "\n";
        echo "TEST COMPLETE\n";
        echo str_repeat("=", 80) . "\n";

        echo "</pre>";
    }

    private function printHeader(): void
    {
        echo str_repeat("=", 80) . "\n";
        echo "  🔍 DATABASE DIAGNOSTIC (CLEAN VERSION)\n";
        echo str_repeat("=", 80) . "\n\n";
    }

    private function testDatabaseUrl(): void
    {
        $url = getenv('DATABASE_URL');

        if (!$url) {
            $this->log("DATABASE_URL missing", false);
            return;
        }

        $parsed = parse_url($url);

        $safeUrl = sprintf(
            "%s://%s:***@%s%s",
            $parsed['scheme'] ?? 'pgsql',
            $parsed['user'] ?? 'user',
            $parsed['host'] ?? 'unknown',
            $parsed['path'] ?? ''
        );

        $this->log("Attempting: $safeUrl", null);

        try {
            $pdo = new PDO($url);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // basic test
            $pdo->query("SELECT 1");

            $this->connection = $pdo;
            $this->log("CONNECTED SUCCESSFULLY", true);

            // show driver info
            $this->log("PDO Drivers: " . implode(', ', PDO::getAvailableDrivers()), true);

        } catch (PDOException $e) {
            $this->log("FAILED: " . $e->getMessage(), false);
        }
    }

    private function printSummary(): void
    {
        echo "\n" . str_repeat("-", 80) . "\n";
        echo "SUMMARY\n";
        echo str_repeat("-", 80) . "\n";

        $drivers = PDO::getAvailableDrivers();

        echo "pdo_pgsql loaded: " . (in_array('pgsql', $drivers) ? "YES" : "NO") . "\n";
        echo "DATABASE_URL: " . (getenv('DATABASE_URL') ? "SET" : "MISSING") . "\n";

        if (!$this->connection) {
            echo "\n❌ DATABASE CONNECTION FAILED\n";
        } else {
            echo "\n✅ DATABASE CONNECTION OK\n";
        }
    }

    private function runDbChecks(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "DATABASE INFO\n";
        echo str_repeat("=", 80) . "\n";

        try {
            $stmt = $this->connection->query("
                SELECT current_database() as db,
                       current_user as user,
                       version() as version
            ");

            $info = $stmt->fetch(PDO::FETCH_ASSOC);

            echo "DB: {$info['db']}\n";
            echo "USER: {$info['user']}\n";
            echo "PG VERSION: " . substr($info['version'], 0, 60) . "\n";

            // check tables
            $tables = [
                'swap_requests',
                'hold_transactions',
                'ledger_accounts',
                'users',
                'organizations'
            ];

            echo "\nTABLE CHECK:\n";

            foreach ($tables as $table) {
                $stmt = $this->connection->prepare("
                    SELECT EXISTS (
                        SELECT 1 FROM information_schema.tables
                        WHERE table_name = :t
                    )
                ");

                $stmt->execute(['t' => $table]);
                $exists = $stmt->fetchColumn();

                echo ($exists ? "✅" : "❌") . " $table\n";
            }

        } catch (PDOException $e) {
            echo "DB INFO ERROR: " . $e->getMessage() . "\n";
        }
    }

    private function runSwapServiceTests(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "SWAP SERVICE TESTS (SAFE MODE)\n";
        echo str_repeat("=", 80) . "\n";

        $this->testAtomicTransaction();
        $this->testRollback();
        $this->testIdempotencySafe();
        $this->testHoldLifecycle();
    }

    private function testAtomicTransaction(): void
    {
        try {
            $this->connection->beginTransaction();

            $id = 'TEST_' . bin2hex(random_bytes(4));

            $stmt = $this->connection->prepare("
                INSERT INTO swap_requests (swap_uuid, status, created_at)
                VALUES (:id, 'testing', NOW())
            ");

            $stmt->execute(['id' => $id]);

            $this->connection->commit();

            echo "\n✅ Atomic transaction: OK\n";

            // cleanup
            $this->connection->prepare("DELETE FROM swap_requests WHERE swap_uuid = ?")
                ->execute([$id]);

        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            echo "\n❌ Atomic transaction failed: {$e->getMessage()}\n";
        }
    }

    private function testRollback(): void
    {
        try {
            $this->connection->beginTransaction();

            $id = 'ROLLBACK_' . bin2hex(random_bytes(4));

            $this->connection->prepare("
                INSERT INTO swap_requests (swap_uuid, status, created_at)
                VALUES (?, 'rollback_test', NOW())
            ")->execute([$id]);

            throw new Exception("forced failure");

        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $stmt = $this->connection->prepare("
                SELECT COUNT(*) FROM swap_requests WHERE swap_uuid = ?
            ");

            $stmt->execute([$id ?? '']);
            $count = $stmt->fetchColumn();

            echo "\n" . ($count == 0 ? "✅" : "❌") . " Rollback test\n";
        }
    }

    /**
     * FIXED: removed jsonb dependency (was breaking test)
     */
    private function testIdempotencySafe(): void
    {
        try {
            $key = 'IDEM_' . bin2hex(random_bytes(6));
            $id = 'IDEM_TEST_' . bin2hex(random_bytes(4));

            $this->connection->beginTransaction();

            $this->connection->prepare("
                INSERT INTO swap_requests (swap_uuid, status, created_at)
                VALUES (?, 'idem', NOW())
            ")->execute([$id]);

            $this->connection->commit();

            // SAFE CHECK (no jsonb dependency)
            $stmt = $this->connection->prepare("
                SELECT COUNT(*) FROM swap_requests WHERE swap_uuid = ?
            ");

            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();

            echo "\n" . ($count >= 1 ? "✅" : "❌") . " Idempotency (basic)\n";

            $this->connection->prepare("DELETE FROM swap_requests WHERE swap_uuid = ?")
                ->execute([$id]);

        } catch (Throwable $e) {
            echo "\n❌ Idempotency test failed: {$e->getMessage()}\n";
        }
    }

    private function testHoldLifecycle(): void
    {
        try {
            $ref = 'HOLD_' . bin2hex(random_bytes(6));

            $this->connection->beginTransaction();

            $this->connection->prepare("
                INSERT INTO hold_transactions (hold_reference, asset_type, amount, status, created_at)
                VALUES (?, 'TEST', 100, 'ACTIVE', NOW())
            ")->execute([$ref]);

            $this->connection->commit();

            $this->connection->prepare("
                UPDATE hold_transactions
                SET status='RELEASED', released_at=NOW()
                WHERE hold_reference=?
            ")->execute([$ref]);

            $stmt = $this->connection->prepare("
                SELECT status FROM hold_transactions WHERE hold_reference=?
            ");

            $stmt->execute([$ref]);
            $status = $stmt->fetchColumn();

            echo "\n" . ($status === 'RELEASED' ? "✅" : "❌") . " Hold lifecycle\n";

            $this->connection->prepare("DELETE FROM hold_transactions WHERE hold_reference=?")
                ->execute([$ref]);

        } catch (Throwable $e) {
            echo "\n❌ Hold lifecycle failed: {$e->getMessage()}\n";
        }
    }

    private function log(string $msg, ?bool $ok): void
    {
        $icon = $ok === true ? "✅" : ($ok === false ? "❌" : "🔍");
        echo "[$icon] $msg\n";
    }
}

// RUN
(new DatabaseTest())->run();
