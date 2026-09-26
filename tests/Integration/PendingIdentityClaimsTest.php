<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * What is still waiting to be claimed, as the recipient's app
 * (SwapService::getPendingClaimsForUser(), behind pending_claims.php and the
 * dashboard's "Finalize identity swap" prompt) and an agent's search
 * (getAggregatedIdentityBalance()) see it.
 *
 * A cash-out claim doesn't close its holds: they stay 'pending', locked to the
 * claim's code by claim_reference, until the cash is collected. Both lists
 * used to go on showing them, so "Finalize identity swap" stayed on the
 * recipient's screen after they had claimed, and an agent was offered money
 * that could no longer be claimed. A hold that is claimed outright is
 * 'completed' and was already left out.
 *
 * Runs against a real PostgreSQL server, with the same throwaway-schema
 * pattern and variable as IdentityClaimPinTest:
 *
 *     CREDENTIALS_MIGRATION_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/PendingIdentityClaimsTest.php
 */
class PendingIdentityClaimsTest extends TestCase
{
    private const SCHEMA = 'pending_identity_claims';
    private const OWNER_ID = 42;
    private const IDENTITY = ['national_id', '123456789'];

    private static ?PDO $db = null;
    private SwapService $service;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('CREDENTIALS_MIGRATION_TEST_DATABASE_URL');
        if (!$url) {
            return;
        }
        $parts = parse_url($url);
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $parts['host'] ?? 'localhost', $parts['port'] ?? 5432, ltrim($parts['path'] ?? '', '/'));
        // Same options as DBConnection.
        self::$db = new PDO($dsn, $parts['user'] ?? 'postgres', $parts['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$db->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        self::$db->exec('CREATE SCHEMA ' . self::SCHEMA);
        self::$db->exec('SET search_path TO ' . self::SCHEMA);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('Set CREDENTIALS_MIGRATION_TEST_DATABASE_URL to a throwaway PostgreSQL database to run this.');
        }
        // No tracked CREATE TABLE for these in this repository; these are the
        // columns the pending-claim queries read.
        self::$db->exec('DROP TABLE IF EXISTS user_identities, identity_swap_holds, hold_transactions');
        self::$db->exec("CREATE TABLE user_identities (id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL, identity_type VARCHAR(30) NOT NULL, identity_value VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL)");
        self::$db->exec("CREATE TABLE hold_transactions (hold_id BIGINT PRIMARY KEY, status VARCHAR(30))");
        self::$db->exec("
            CREATE TABLE identity_swap_holds (
                hold_id             BIGSERIAL PRIMARY KEY,
                swap_reference      VARCHAR(100) NOT NULL,
                identity_type       VARCHAR(30) NOT NULL,
                identity_value      VARCHAR(100) NOT NULL,
                amount              NUMERIC(18,2) NOT NULL,
                currency            CHAR(3) NOT NULL DEFAULT 'BWP',
                status              VARCHAR(30) NOT NULL DEFAULT 'pending',
                hold_expires_at     TIMESTAMP NOT NULL,
                claim_reference     VARCHAR(100),
                source_institution  VARCHAR(50),
                source_identifier   VARCHAR(100),
                metadata            JSONB,
                created_at          TIMESTAMP DEFAULT clock_timestamp()
            )
        ");
        self::$db->prepare("INSERT INTO user_identities (user_id, identity_type, identity_value, status) VALUES (?, ?, ?, 'verified')")
            ->execute([self::OWNER_ID, self::IDENTITY[0], self::IDENTITY[1]]);

        $this->service = (new ReflectionClass(SwapService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(SwapService::class, 'swapDB'))->setValue($this->service, self::$db);
    }

    private function hold(string $reference, float $amount, string $status = 'pending', ?string $claimReference = null): void
    {
        self::$db->prepare("
            INSERT INTO identity_swap_holds (swap_reference, identity_type, identity_value, amount, status, hold_expires_at, claim_reference, source_institution)
            VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL '1 day', ?, 'ZURUBANK')
        ")->execute([$reference, self::IDENTITY[0], self::IDENTITY[1], $amount, $status, $claimReference]);
    }

    private function waiting(): array
    {
        return array_column($this->service->getPendingClaimsForUser(self::OWNER_ID), 'swap_reference');
    }

    public function testMoneyClaimedAsACashOutCodeIsNoLongerWaiting(): void
    {
        $this->hold('SWAP_CLAIMED', 100, 'pending', 'CONSOL_1');   // cash-out code issued, not collected yet
        $this->hold('SWAP_WAITING', 40);

        $this->assertSame(['SWAP_WAITING'], $this->waiting());
    }

    public function testMoneyClaimedOutrightIsNoLongerWaiting(): void
    {
        $this->hold('SWAP_DEPOSITED', 100, 'completed');

        $this->assertSame([], $this->waiting());
    }

    public function testMoneyIsWaitingAgainWhenItsCashOutCodeExpiresUnused(): void
    {
        $this->hold('SWAP_CLAIMED', 100, 'pending', 'CONSOL_1');
        $this->assertSame([], $this->waiting());

        // What expireIdentityCashoutClaim() does when the code runs out.
        self::$db->exec("UPDATE identity_swap_holds SET claim_reference = NULL WHERE claim_reference = 'CONSOL_1'");

        $this->assertSame(['SWAP_CLAIMED'], $this->waiting());
    }

    public function testAnAgentIsNotOfferedMoneyAlreadyClaimedAsACashOutCode(): void
    {
        $this->hold('SWAP_CLAIMED', 100, 'pending', 'CONSOL_1');
        $this->hold('SWAP_WAITING', 40);

        $balance = $this->service->getAggregatedIdentityBalance(self::IDENTITY[0], self::IDENTITY[1]);

        $this->assertEquals(40, $balance['total_amount']);
        $this->assertSame(1, (int)$balance['swap_count']);
        $this->assertSame(['SWAP_WAITING'], $balance['swap_references']);

        self::$db->exec("UPDATE identity_swap_holds SET claim_reference = 'CONSOL_2' WHERE swap_reference = 'SWAP_WAITING'");
        $this->assertNull($this->service->getAggregatedIdentityBalance(self::IDENTITY[0], self::IDENTITY[1]));
    }
}
