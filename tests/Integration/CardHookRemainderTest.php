<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\CardContributionSessionService;
use Domain\Services\CardService;
use Domain\Services\ContributionCalculator;
use Domain\Services\MultiSource\PoolCoordinator;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * A swap paid from the VouchMorph Card spends only what it uses of each hooked
 * source (CardContributionSessionService::execute()). Hook P500, swap P40, and
 * P460 stays hooked under the same hold, ready for the next swap. Every
 * contributing source used to be marked DEBITED outright, so the other P460
 * vanished from the card as if all P500 had been swapped.
 *
 * Because the rest stays under the same hold, a swap whose debit is deferred
 * (a cash-out code, a swap to an identity) still has to collect its share from
 * that hold later, and the hold can only be released whole - so while such a
 * swap is pending, unhooking must not release it
 * (CardService::isHoldReservedForPendingSwap()).
 *
 * Runs against a real PostgreSQL server, with the same throwaway-schema
 * pattern and variable as IdentityClaimPinTest:
 *
 *     CREDENTIALS_MIGRATION_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/CardHookRemainderTest.php
 *
 * PoolCoordinator (the pool that debits the sources and pays the destination)
 * and the bank's hold release are stubbed; the card's own bookkeeping is real.
 */
class CardHookRemainderTest extends TestCase
{
    private const SCHEMA = 'card_hook_remainder';
    private const OWNER_ID = 42;
    private const CARD = '4821';

    private static ?PDO $db = null;

    /** What executeFromCardHook() was asked to debit, per call. */
    private array $executed = [];
    /** What the pool reports back: COMPLETED for a deposit, PENDING_CASHOUT for a cash-out. */
    private string $poolStatus = 'COMPLETED';
    /** Hold references the bank was asked to release. */
    private array $released = [];

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
        // None of these tables has a tracked CREATE TABLE in this repository;
        // these are the columns the card and pool code read and write.
        self::$db->exec('DROP TABLE IF EXISTS card_pool_hooks, card_pool_hook_sources, card_contribution_sessions,
                                              card_contribution_entries, pool_contributions, virtual_funding_pools');
        self::$db->exec("
            CREATE TABLE card_pool_hooks (
                id SERIAL PRIMARY KEY,
                hook_reference TEXT NOT NULL,
                card_suffix TEXT NOT NULL,
                user_id INT NOT NULL,
                total_held_amount NUMERIC(14,2) NOT NULL,
                currency TEXT NOT NULL DEFAULT 'BWP',
                status TEXT NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                settlement_reference TEXT,
                finalized_at TIMESTAMP,
                unhooked_at TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        self::$db->exec("
            CREATE TABLE card_pool_hook_sources (
                id SERIAL PRIMARY KEY,
                hook_id INT NOT NULL,
                owner_user_id INT NOT NULL,
                institution TEXT NOT NULL,
                asset_type TEXT NOT NULL,
                source_identifier TEXT NOT NULL,
                held_amount NUMERIC(14,2) NOT NULL,
                hold_reference TEXT,
                status TEXT NOT NULL,
                debited_amount NUMERIC(14,2),
                debit_reference TEXT,
                released_at TIMESTAMP
            )
        ");
        self::$db->exec("
            CREATE TABLE card_contribution_sessions (
                id SERIAL PRIMARY KEY,
                session_reference TEXT NOT NULL,
                card_suffix TEXT NOT NULL,
                hook_reference TEXT NOT NULL,
                initiated_by_user_id INT NOT NULL,
                destination_payload JSONB,
                target_amount NUMERIC(14,2) NOT NULL,
                currency TEXT NOT NULL,
                strategy TEXT NOT NULL,
                min_contribution_amount NUMERIC(14,2) NOT NULL,
                status TEXT NOT NULL,
                contributions_preview JSONB,
                failure_reason TEXT,
                swap_reference TEXT,
                pool_id TEXT,
                executed_at TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                expires_at TIMESTAMP NOT NULL
            )
        ");
        self::$db->exec('CREATE TABLE card_contribution_entries (session_id INT, hook_source_id INT, contributor_user_id INT, manual_amount NUMERIC(14,2), updated_at TIMESTAMP)');
        self::$db->exec("CREATE TABLE virtual_funding_pools (pool_id TEXT PRIMARY KEY, status TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT NOW())");
        self::$db->exec("CREATE TABLE pool_contributions (id SERIAL PRIMARY KEY, pool_id TEXT NOT NULL, hold_reference TEXT, contribution_amount NUMERIC(14,2), status TEXT NOT NULL)");

        $this->executed = [];
        $this->poolStatus = 'COMPLETED';
        $this->released = [];
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /** A HOOKED pot on the card with these sources, each under its own hold. */
    private function hook(array $amounts): void
    {
        $total = array_sum($amounts);
        self::$db->prepare("
            INSERT INTO card_pool_hooks (hook_reference, card_suffix, user_id, total_held_amount, status, expires_at)
            VALUES ('HOOK_1', :card, :owner, :total, 'HOOKED', NOW() + INTERVAL '1 hour')
        ")->execute([':card' => self::CARD, ':owner' => self::OWNER_ID, ':total' => $total]);
        foreach (array_values($amounts) as $i => $amount) {
            self::$db->prepare("
                INSERT INTO card_pool_hook_sources (hook_id, owner_user_id, institution, asset_type, source_identifier, held_amount, hold_reference, status)
                VALUES (1, :owner, 'ZURUBANK', 'ACCOUNT', :ident, :amount, :hold, 'HELD')
            ")->execute([':owner' => self::OWNER_ID, ':ident' => '1000' . $i, ':amount' => $amount, ':hold' => 'HOLD_' . $i]);
        }
    }

    private function sessions(): CardContributionSessionService
    {
        return new CardContributionSessionService(self::$db, new ContributionCalculator(), 10.0, 0.01, 1800, new class {
            public function info($m, array $c = []) {}
            public function warning($m, array $c = []) {}
            public function error($m, array $c = []) {}
        });
    }

    /**
     * The pool: records what it was asked to debit, and - like the real one -
     * leaves a deferred (cash-out) pool pending with its contributions not yet
     * debited from their holds.
     */
    private function pool(): PoolCoordinator
    {
        $test = $this;
        return new class($test) extends PoolCoordinator {
            public function __construct(private CardHookRemainderTest $test) {}

            public function executeFromCardHook(array $payload, array $preHeldSources): array
            {
                return $this->test->recordPoolExecution($payload, $preHeldSources);
            }
        };
    }

    /** @internal called by the pool stub */
    public function recordPoolExecution(array $payload, array $preHeldSources): array
    {
        $poolId = 'POOL_' . (count($this->executed) + 1);
        $this->executed[] = $preHeldSources;
        self::$db->prepare("INSERT INTO virtual_funding_pools (pool_id, status) VALUES (?, ?)")->execute([$poolId, $this->poolStatus]);
        foreach ($preHeldSources as $s) {
            self::$db->prepare("INSERT INTO pool_contributions (pool_id, hold_reference, contribution_amount, status) VALUES (?, ?, ?, ?)")
                ->execute([$poolId, $s['hold_reference'], $s['amount'], $this->poolStatus === 'COMPLETED' ? 'DEBITED' : 'HELD']);
        }
        return ['success' => true, 'pool_id' => $poolId, 'reference' => $payload['reference'], 'status' => $this->poolStatus];
    }

    private function swap(float $amount): array
    {
        $sessions = $this->sessions();
        $created = $sessions->createSession(self::CARD, self::OWNER_ID, [
            'to_institution' => 'SACCUSSALIS', 'destination_identifier' => '5550001', 'delivery_method' => 'DEPOSIT',
        ], $amount, 'BWP', 'SMART');
        $this->assertSame('READY', $created['status'], 'the hooked sources cover the swap');
        return $sessions->execute($created['session_id'], self::OWNER_ID, $this->pool());
    }

    private function cardService(): CardService
    {
        $service = (new ReflectionClass(CardService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(CardService::class, 'db'))->setValue($service, self::$db);
        return $service;
    }

    /** The bank: releases whatever it is asked to. */
    private function bank(): SwapService
    {
        $test = $this;
        return new class($test) extends SwapService {
            public function __construct(private CardHookRemainderTest $test) {}

            public function releaseHold(array $sourcePayload, string $institution, ?string $holdId = null, ?string $holdReference = null): array
            {
                return $this->test->recordRelease($holdReference);
            }
        };
    }

    /** @internal called by the bank stub */
    public function recordRelease(?string $holdReference): array
    {
        $this->released[] = $holdReference;
        return ['success' => true, 'released' => true];
    }

    private function sources(): array
    {
        return self::$db->query("SELECT id, hold_reference, held_amount, status, debited_amount FROM card_pool_hook_sources ORDER BY id")->fetchAll();
    }

    private function hookRow(): array
    {
        return self::$db->query("SELECT status, total_held_amount FROM card_pool_hooks WHERE id = 1")->fetch();
    }

    // ------------------------------------------------------------
    // A swap spends only what it uses
    // ------------------------------------------------------------

    public function testTheRestOfAHookedSourceStaysHookedAfterASwap(): void
    {
        $this->hook([500]);

        $this->swap(40);

        $this->assertSame(40.0, (float)$this->executed[0][0]['amount'], 'only P40 is debited from the hold');
        $this->assertSame('HOLD_0', $this->executed[0][0]['hold_reference']);
        $source = $this->sources()[0];
        $this->assertSame('HELD', $source['status']);
        $this->assertSame('460.00', $source['held_amount']);
        $this->assertNull($source['debited_amount']);
        $this->assertSame(['status' => 'HOOKED', 'total_held_amount' => '460.00'], $this->hookRow());
    }

    public function testTheNextSwapSpendsFromWhatIsLeft(): void
    {
        $this->hook([500]);
        $this->swap(40);

        $this->swap(460);

        $this->assertSame(460.0, (float)$this->executed[1][0]['amount']);
        $this->assertSame('HOLD_0', $this->executed[1][0]['hold_reference'], 'the same hold funds the rest');
        $source = $this->sources()[0];
        $this->assertSame('DEBITED', $source['status'], 'a source the swap used up is spent');
        $this->assertSame('460.00', $source['debited_amount']);
        $this->assertSame('SETTLED', $this->hookRow()['status'], 'nothing is left hooked');
        $this->assertSame('0.00', $this->hookRow()['total_held_amount']);
    }

    public function testEachSourceKeepsWhatTheSwapDidNotTakeFromIt(): void
    {
        $this->hook([300, 200]);

        $this->swap(40);

        $taken = [];
        foreach ($this->executed[0] as $s) {
            $taken[$s['hold_reference']] = (float)$s['amount'];
        }
        $this->assertEqualsWithDelta(40.0, array_sum($taken), 0.001);
        foreach ($this->sources() as $i => $source) {
            $left = [300, 200][$i] - ($taken[$source['hold_reference']] ?? 0.0);
            $this->assertSame('HELD', $source['status']);
            $this->assertEqualsWithDelta($left, (float)$source['held_amount'], 0.001);
        }
        $this->assertSame(['status' => 'HOOKED', 'total_held_amount' => '460.00'], $this->hookRow());
    }

    // ------------------------------------------------------------
    // ...and a pending cash-out keeps its share of the hold
    // ------------------------------------------------------------

    public function testUnhookingWaitsForACashOutThatStillHasToDebitTheHold(): void
    {
        $this->hook([500]);
        $this->poolStatus = 'PENDING_CASHOUT';
        $this->swap(40);
        $this->assertSame('460.00', $this->sources()[0]['held_amount'], 'the rest stays hooked while the code is out');

        $result = $this->cardService()->releaseHook('HOOK_1', self::OWNER_ID, $this->bank());

        $this->assertSame([], $this->released, 'releasing the hold would release the cash-out\'s P40 with it');
        $this->assertTrue($result['success']);
        $this->assertSame('UNHOOK_PARTIAL', $result['status'], 'so the card hook job retries it');
        $this->assertSame([['institution' => 'ZURUBANK', 'amount' => 460.0]], $result['reserved']);
        $this->assertSame('HELD', $this->sources()[0]['status']);

        // The code is collected and the pool debits its P40: the rest can go.
        self::$db->exec("UPDATE virtual_funding_pools SET status = 'COMPLETED'");
        self::$db->exec("UPDATE pool_contributions SET status = 'DEBITED'");
        $this->assertFalse($this->cardService()->isHoldReservedForPendingSwap('HOLD_0'));
    }

    public function testASingleSourceIsNotUnhookedWhileACashOutStillNeedsItsHold(): void
    {
        $this->hook([500]);
        $this->poolStatus = 'PENDING_CASHOUT';
        $this->swap(40);

        $result = $this->cardService()->releaseHookSource((int)$this->sources()[0]['id'], self::OWNER_ID, $this->bank());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("can't be unhooked yet", $result['error']);
        $this->assertSame([], $this->released);
        $this->assertSame('HELD', $this->sources()[0]['status']);
    }

    public function testAHookWithNothingPendingIsReleasedAsBefore(): void
    {
        $this->hook([500]);
        $this->swap(40);   // a deposit: its P40 was debited when it ran

        $result = $this->cardService()->releaseHook('HOOK_1', self::OWNER_ID, $this->bank());

        $this->assertSame(['HOLD_0'], $this->released);
        $this->assertSame('UNHOOKED', $result['status']);
        $this->assertSame([], $result['reserved']);
        $this->assertSame('RELEASED', $this->sources()[0]['status']);
    }

    public function testOnlyAPendingSwapWithinTheHoldWindowReservesAHold(): void
    {
        $card = $this->cardService();
        $pool = function (string $poolId, string $poolStatus, string $contributionStatus, string $age = '0 hours') {
            self::$db->prepare("INSERT INTO virtual_funding_pools (pool_id, status, created_at) VALUES (?, ?, NOW() - CAST(? AS INTERVAL))")
                ->execute([$poolId, $poolStatus, $age]);
            self::$db->prepare("INSERT INTO pool_contributions (pool_id, hold_reference, contribution_amount, status) VALUES (?, ?, 40, ?)")
                ->execute([$poolId, 'HOLD_' . $poolId, $contributionStatus]);
        };
        $pool('CASHOUT', 'PENDING_CASHOUT', 'HELD');
        $pool('IDENTITY', 'PENDING_ID_CLAIM', 'HELD');
        $pool('DONE', 'COMPLETED', 'DEBITED');
        $pool('FAILED', 'FAILED', 'HELD');
        $pool('STALE', 'PENDING_CASHOUT', 'HELD', '25 hours');

        $this->assertTrue($card->isHoldReservedForPendingSwap('HOLD_CASHOUT'));
        $this->assertTrue($card->isHoldReservedForPendingSwap('HOLD_IDENTITY'));
        $this->assertFalse($card->isHoldReservedForPendingSwap('HOLD_DONE'));
        $this->assertFalse($card->isHoldReservedForPendingSwap('HOLD_FAILED'));
        $this->assertFalse($card->isHoldReservedForPendingSwap('HOLD_STALE'), 'past the 24-hour hold window the bank has let it go anyway');
        $this->assertFalse($card->isHoldReservedForPendingSwap('HOLD_UNKNOWN'));
        $this->assertFalse($card->isHoldReservedForPendingSwap(null));
    }
}
