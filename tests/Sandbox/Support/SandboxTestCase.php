<?php
declare(strict_types=1);

namespace Tests\Sandbox\Support;

use PHPUnit\Framework\TestCase;
use PDO;
use Domain\Services\FeeService;

require_once __DIR__ . '/../../../vendor/autoload.php';

/**
 * Base class for the in-house sandbox evidence tests.
 *
 * These tests prove, on VouchMorph's own code, the behaviours the Bank of
 * Botswana sandbox requires (hypotheses H1-H12 in the Test Plan VM-TP-001
 * and the experiments in the Testing Design VM-TD-001). They run with no
 * live bank: fee maths and message shaping are pure functions of the
 * committed Botswana config, and the DB-backed tests use a local Postgres
 * loaded from the repo's own schema.
 *
 * A test that needs the database is skipped, not failed, when SANDBOX_TEST_DSN
 * is not set, so the suite still runs in a pure-PHP environment.
 */
abstract class SandboxTestCase extends TestCase
{
    protected static ?PDO $pdo = null;

    /** Botswana fee config, loaded once from the real committed file. */
    protected static function feesConfig(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $path = __DIR__ . '/../../../src/Core/Config/Countries/Botswana/fees.json';
            $cfg = json_decode(file_get_contents($path), true);
        }
        return $cfg;
    }

    protected static function makeFeeService(): FeeService
    {
        return new FeeService(self::feesConfig(), [], 'BWP', null);
    }

    /**
     * Returns a PDO to the local test database, or null if none is configured.
     * DSN comes from SANDBOX_TEST_DSN, e.g.
     *   pgsql:host=/tmp;port=5433;dbname=vm_bw
     * with SANDBOX_TEST_USER / SANDBOX_TEST_PASS optional.
     */
    protected function db(): ?PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $dsn = getenv('SANDBOX_TEST_DSN');
        if (!$dsn) {
            return null;
        }
        try {
            self::$pdo = new PDO(
                $dsn,
                getenv('SANDBOX_TEST_USER') ?: 'postgres',
                getenv('SANDBOX_TEST_PASS') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            return null;
        }
        return self::$pdo;
    }

    protected function requireDb(): PDO
    {
        $db = $this->db();
        if ($db === null) {
            $this->markTestSkipped('No SANDBOX_TEST_DSN configured; DB-backed evidence test skipped.');
        }
        return $db;
    }

    /** True if a table exists in the connected DB. */
    protected function tableExists(PDO $db, string $table): bool
    {
        $st = $db->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=?"
        );
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    }
}
