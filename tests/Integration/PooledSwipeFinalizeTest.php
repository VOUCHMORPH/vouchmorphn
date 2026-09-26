<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Domain\Services\CardService;
use Domain\Services\ContributionCalculator;
use Domain\Services\FeeService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * CardService::finalizePooledSwipe() - the step the card pool worker runs after
 * authorizePooledSwipe() approves a VouchMorph Card swipe at an ATM or POS:
 * debit each hooked source its share, release what the swipe didn't use of
 * each hold, and settle to the institution that paid out.
 *
 * It read its minimum share per source through a FeeService method that
 * didn't exist, so every finalization threw an Error - which its
 * catch (Exception) let through with the transaction still open. And it
 * matched ContributionCalculator's shares to the hooked sources by position,
 * which the calculator doesn't keep: vouchers come first, and a source left
 * out of the swipe shifts every source after it.
 *
 * Runs against a real PostgreSQL server, with the same throwaway-schema
 * pattern and variable as CardHookRemainderTest:
 *
 *     CREDENTIALS_MIGRATION_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/PooledSwipeFinalizeTest.php
 *
 * The institutions (debit, hold release, settlement) are stubbed; the card's
 * own bookkeeping and the contribution split are real.
 */
class PooledSwipeFinalizeTest extends TestCase
{
    private const SCHEMA = 'pooled_swipe_finalize';
    private const OWNER_ID = 42;

    private static ?PDO $db = null;

    /** The fee service the stubbed SwapService hands out. */
    public FeeService $fees;
    /** Amount debited, per hold reference. */
    private array $debits = [];
    /** Hold references the institutions were asked to release. */
    private array $released = [];
    /** Amount settled to the destination, per source institution. */
    private array $settled = [];

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
        // A failed finalization used to leave its transaction open; don't let
        // one test's leak fail the next.
        if (self::$db->inTransaction()) {
            self::$db->rollBack();
        }
        // None of these tables has a tracked CREATE TABLE in this repository;
        // these are the columns the card and pool code read and write.
        self::$db->exec('DROP TABLE IF EXISTS card_pool_hooks, card_pool_hook_sources, card_pool_shortfall_bills,
                                              pool_contributions, virtual_funding_pools');
        self::$db->exec("
            CREATE TABLE card_pool_hooks (
                id SERIAL PRIMARY KEY,
                hook_reference TEXT NOT NULL,
                card_suffix TEXT NOT NULL,
                user_id INT NOT NULL,
                total_held_amount NUMERIC(14,2) NOT NULL,
                currency TEXT NOT NULL DEFAULT 'BWP',
                status TEXT NOT NULL,
                swipe_amount NUMERIC(14,2),
                merchant_reference TEXT,
                expires_at TIMESTAMP NOT NULL,
                settlement_reference TEXT,
                finalized_at TIMESTAMP,
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
            CREATE TABLE card_pool_shortfall_bills (
                id SERIAL PRIMARY KEY,
                hook_source_id INT NOT NULL,
                owner_user_id INT NOT NULL,
                amount NUMERIC(14,2) NOT NULL,
                currency TEXT NOT NULL,
                reason TEXT NOT NULL,
                due_at TIMESTAMP NOT NULL
            )
        ");
        self::$db->exec("CREATE TABLE virtual_funding_pools (pool_id TEXT PRIMARY KEY, status TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT NOW())");
        self::$db->exec("CREATE TABLE pool_contributions (id SERIAL PRIMARY KEY, pool_id TEXT NOT NULL, hold_reference TEXT, contribution_amount NUMERIC(14,2), status TEXT NOT NULL)");

        // The F1 amounts fees.json sets for each product.
        $this->fees = new FeeService([
            'base_currency' => 'BWP',
            'CASHOUT' => ['fee_components' => ['F1' => ['amount' => 10.0]]],
            'DEPOSIT' => ['fee_components' => ['F1' => ['amount' => 6.0]]],
        ], ['currency' => 'BWP']);
        $this->debits = [];
        $this->released = [];
        $this->settled = [];
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * An approved swipe waiting for the worker: a SWIPE_RECEIVED hook with
     * these sources, given as [institution, asset type, held amount] plus a
     * status other than HELD if need be. Source n (from 1) is under HOLD_n.
     */
    private function swipe(float $amount, array $sources): void
    {
        self::$db->prepare("
            INSERT INTO card_pool_hooks (hook_reference, card_suffix, user_id, total_held_amount, status, swipe_amount, expires_at)
            VALUES ('HOOK_1', '4821', :owner, :total, 'SWIPE_RECEIVED', :amount, NOW() + INTERVAL '1 hour')
        ")->execute([':owner' => self::OWNER_ID, ':total' => array_sum(array_column($sources, 2)), ':amount' => $amount]);
        foreach (array_values($sources) as $i => $source) {
            [$institution, $assetType, $held] = $source;
            self::$db->prepare("
                INSERT INTO card_pool_hook_sources (hook_id, owner_user_id, institution, asset_type, source_identifier, held_amount, hold_reference, status)
                VALUES (1, :owner, :institution, :type, :ident, :held, :hold, :status)
            ")->execute([
                ':owner' => self::OWNER_ID, ':institution' => $institution, ':type' => $assetType,
                ':ident' => '7000' . ($i + 1), ':held' => $held, ':hold' => 'HOLD_' . ($i + 1),
                ':status' => $source[3] ?? 'HELD',
            ]);
        }
    }

    /** A card swap that hasn't finished yet still has to debit its share of this hold. */
    private function pendingCashOutOn(string $holdReference): void
    {
        self::$db->prepare("INSERT INTO virtual_funding_pools (pool_id, status) VALUES (?, 'PENDING_CASHOUT')")->execute(['POOL_' . $holdReference]);
        self::$db->prepare("INSERT INTO pool_contributions (pool_id, hold_reference, contribution_amount, status) VALUES (?, ?, 40, 'HELD')")
            ->execute(['POOL_' . $holdReference, $holdReference]);
    }

    private function finalize(string $channel = 'POS'): array
    {
        $card = (new ReflectionClass(CardService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(CardService::class, 'db'))->setValue($card, self::$db);

        return $card->finalizePooledSwipe(
            'HOOK_1',
            $this->institutions(),
            (new ReflectionClass(HybridSettlementStrategy::class))->newInstanceWithoutConstructor(),
            new ContributionCalculator(),
            // delivery_method ATM makes the minimum share the cash-out one.
            ['resolved_destination_institution' => 'FNBB'] + ($channel === 'ATM' ? ['delivery_method' => 'ATM'] : [])
        );
    }

    /** The institutions: every debit, release and settlement goes through. */
    private function institutions(): SwapService
    {
        $test = $this;
        return new class($test) extends SwapService {
            public function __construct(private PooledSwipeFinalizeTest $test) {}

            public function getFeeService(): FeeService
            {
                return $this->test->fees;
            }

            public function getAtmDenominations(string $currency): array
            {
                return [200, 100, 50, 20, 10];
            }

            public function debitSource(array $payload, string $institution): array
            {
                return $this->test->recordDebit($payload);
            }

            public function releaseHold(array $sourcePayload, string $institution, ?string $holdId = null, ?string $holdReference = null): array
            {
                return $this->test->recordRelease($holdReference);
            }

            public function settleCardSwipeToDestination(string $sourceInstitution, string $destinationInstitution, string $currency, float $amount, string $reference): float
            {
                return $this->test->recordSettlement($sourceInstitution, $amount);
            }
        };
    }

    /** @internal called by the institutions stub */
    public function recordDebit(array $payload): array
    {
        $this->debits[$payload['hold_reference']] = (float)$payload['amount'];
        return ['debited' => true, 'transaction_reference' => 'DEBIT_' . $payload['hold_reference']];
    }

    /** @internal called by the institutions stub */
    public function recordRelease(?string $holdReference): array
    {
        $this->released[] = $holdReference;
        return ['success' => true, 'released' => true];
    }

    /** @internal called by the institutions stub */
    public function recordSettlement(string $sourceInstitution, float $amount): float
    {
        $this->settled[$sourceInstitution] = $amount;
        return $amount;
    }

    /** Status of each source, by hold reference. */
    private function sourceStatuses(): array
    {
        $rows = self::$db->query("SELECT hold_reference, status FROM card_pool_hook_sources ORDER BY id")->fetchAll();
        return array_column($rows, 'status', 'hold_reference');
    }

    private function hookStatus(): string
    {
        return self::$db->query("SELECT status FROM card_pool_hooks WHERE id = 1")->fetchColumn();
    }

    // ------------------------------------------------------------
    // The swipe is finalized
    // ------------------------------------------------------------

    public function testEachSourceIsDebitedItsShareAndTheRestOfItsHoldReleased(): void
    {
        $this->swipe(100, [['ZURUBANK', 'ACCOUNT', 300], ['SACCUSSALIS', 'ACCOUNT', 200]]);

        $result = $this->finalize();

        $this->assertTrue($result['success']);
        $this->assertEquals(['HOLD_1' => 50.0, 'HOLD_2' => 50.0], $this->debits);
        $this->assertEqualsWithDelta(100.0, $result['total_debited'], 0.001);
        $this->assertSame(['HOLD_1', 'HOLD_2'], $this->released, 'P250 and P150 of the holds were not used');
        $this->assertEquals(['ZURUBANK' => 50.0, 'SACCUSSALIS' => 50.0], $this->settled, 'FNBB is paid from each source');
        $this->assertSame(['HOLD_1' => 'DEBITED', 'HOLD_2' => 'DEBITED'], $this->sourceStatuses());
        $this->assertSame([], $result['shortfall_bills']);
        $this->assertSame('SETTLED', $this->hookStatus());
    }

    public function testAnErrorRollsBackInsteadOfLeavingTheTransactionOpen(): void
    {
        $this->swipe(100, [['ZURUBANK', 'ACCOUNT', 300]]);
        $working = $this->fees;
        $this->fees = new class([], []) extends FeeService {
            public function getFeesConfig(): array
            {
                throw new Error('Call to undefined method');
            }
        };

        try {
            $this->finalize();
            $this->fail('the Error reaches the worker');
        } catch (Error $e) {
            $this->assertSame('Call to undefined method', $e->getMessage());
        }

        $this->assertFalse(self::$db->inTransaction(), 'the transaction was rolled back');
        $this->assertSame('SWIPE_RECEIVED', $this->hookStatus());
        $this->assertSame(['HOLD_1' => 'HELD'], $this->sourceStatuses());
        $this->assertSame([], $this->debits);

        // ...so the worker's next attempt can finalize it.
        $this->fees = $working;
        $this->assertTrue($this->finalize()['success']);
        $this->assertSame('SETTLED', $this->hookStatus());
    }

    // ------------------------------------------------------------
    // Each share is taken from the hold it belongs to
    // ------------------------------------------------------------

    public function testAVoucherIsDebitedFromItsOwnHold(): void
    {
        // The calculator puts the voucher's share first.
        $this->swipe(300, [['ZURUBANK', 'ACCOUNT', 500], ['SACCUSSALIS', 'VOUCHER', 100]]);

        $this->finalize();

        $this->assertEquals(['HOLD_1' => 200.0, 'HOLD_2' => 100.0], $this->debits);
        $this->assertSame(['HOLD_1'], $this->released, 'the voucher is used in full');
        $this->assertEquals(['ZURUBANK' => 200.0, 'SACCUSSALIS' => 100.0], $this->settled);
        $this->assertSame(['HOLD_1' => 'DEBITED', 'HOLD_2' => 'DEBITED'], $this->sourceStatuses());
    }

    public static function sourcesTheSwipeDoesNotUse(): array
    {
        return [
            'a voucher covers the swipe' => ['POS', 100, [['ZURUBANK', 'ACCOUNT', 500], ['SACCUSSALIS', 'VOUCHER', 100]], ['HOLD_2' => 100.0], ['HOLD_1']],
            'its share is under the POS minimum (DEPOSIT F1, P6)' => ['POS', 100, [['ZURUBANK', 'ACCOUNT', 5], ['SACCUSSALIS', 'ACCOUNT', 500]], ['HOLD_2' => 100.0], ['HOLD_1', 'HOLD_2']],
            // P15 clears the POS minimum but not this one.
            'its share is under the ATM minimum (CASHOUT F1 P10, plus a P10 note)' => ['ATM', 100, [['ZURUBANK', 'ACCOUNT', 15], ['SACCUSSALIS', 'ACCOUNT', 500]], ['HOLD_2' => 100.0], ['HOLD_1', 'HOLD_2']],
        ];
    }

    #[DataProvider('sourcesTheSwipeDoesNotUse')]
    public function testASourceTheSwipeDoesNotUseIsReleasedNotDebited(string $channel, float $amount, array $sources, array $debits, array $released): void
    {
        $this->swipe($amount, $sources);

        $result = $this->finalize($channel);

        $this->assertEquals($debits, $this->debits, 'nothing is taken from HOLD_1');
        $this->assertEqualsCanonicalizing($released, $this->released, 'HOLD_1 is released whole');
        $this->assertSame(['HOLD_1' => 'RELEASED', 'HOLD_2' => 'DEBITED'], $this->sourceStatuses());
        $this->assertSame([], $result['shortfall_bills']);
        $this->assertEquals(['SACCUSSALIS' => 100.0], $this->settled);
        $this->assertSame('SETTLED', $this->hookStatus());
    }

    // ------------------------------------------------------------
    // What the card already spent, or still owes, is left alone
    // ------------------------------------------------------------

    public function testOnlySourcesStillHeldAreDrawnOn(): void
    {
        $this->swipe(100, [
            ['ZURUBANK', 'ACCOUNT', 500, 'DEBITED'],       // used up by an earlier card swap
            ['SACCUSSALIS', 'ACCOUNT', 500, 'RELEASED'],   // unhooked on its own
            ['ABSA', 'ACCOUNT', 300],
        ]);

        $this->finalize();

        $this->assertEquals(['HOLD_3' => 100.0], $this->debits);
        $this->assertSame(['HOLD_3'], $this->released);
        $this->assertSame(['HOLD_1' => 'DEBITED', 'HOLD_2' => 'RELEASED', 'HOLD_3' => 'DEBITED'], $this->sourceStatuses());
    }

    public function testAHoldAPendingCardSwapStillNeedsIsNotReleased(): void
    {
        $this->swipe(100, [['ZURUBANK', 'ACCOUNT', 5], ['SACCUSSALIS', 'ACCOUNT', 500]]);
        $this->pendingCashOutOn('HOLD_1');   // not drawn on by the swipe
        $this->pendingCashOutOn('HOLD_2');   // drawn on, with P400 of it left over

        $this->finalize();

        $this->assertEquals(['HOLD_2' => 100.0], $this->debits);
        $this->assertSame([], $this->released, 'releasing either hold would release the cash-out\'s share with it');
        $this->assertSame(['HOLD_1' => 'HELD', 'HOLD_2' => 'DEBITED'], $this->sourceStatuses());
        $this->assertSame('SETTLED', $this->hookStatus());
    }
}
