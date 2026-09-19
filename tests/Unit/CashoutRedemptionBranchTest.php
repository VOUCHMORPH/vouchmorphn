<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Adapter that fails loudly if it is reached, so a test can prove the
 * guards either stopped execution before any bank call or deliberately
 * let it through.
 */
class RedemptionSentinelAdapter implements \Infrastructure\Adapters\InstitutionAdapterInterface
{
    public const SENTINEL = 'SENTINEL_REACHED_THE_BANK';

    /** @var list<array> */
    public static array $debitCalls = [];

    public static function reset(): void
    {
        self::$debitCalls = [];
    }

    private string $institution;

    public function __construct($bankClient, $logger, string $institution, array $config)
    {
        $this->institution = $institution;
    }

    public function debit(array $payload, array $context): array
    {
        self::$debitCalls[] = $payload;
        throw new RuntimeException(self::SENTINEL);
    }

    public function confirmCashout(array $payload, array $context): array
    {
        throw new RuntimeException(self::SENTINEL);
    }

    public function verifyAsset(array $payload, array $context): array { return ['verified' => false, 'success' => false]; }
    public function placeHold(array $payload, array $context): array { return ['hold_placed' => false, 'success' => false]; }
    public function releaseHold(array $payload, array $context): array { return ['released' => false, 'success' => false]; }
    public function credit(array $payload, array $context): array { return ['credited' => false, 'success' => false]; }
    public function generateCashoutToken(array $payload, array $context): array { return ['success' => false]; }
    public function verifyCashoutToken(array $payload, array $context): array { return ['verified' => false]; }
    public function verifyAccount(array $payload, array $context): array { return ['verified' => false, 'success' => false]; }
    public function getBalance(array $payload, array $context): array { return ['success' => false, 'balance' => 0]; }
    public function getTransactions(array $payload, array $context): array { return ['success' => false, 'transactions' => []]; }
    public function checkSettlementStatus(array $payload, array $context): array { return ['success' => false, 'settled' => false]; }
    public function getAccounts(array $payload, array $context): array { return ['success' => false, 'accounts' => []]; }
    public function createReservationAccount(array $payload, array $context): array { return ['success' => false]; }
    public function getReservationAccountStatus(array $payload, array $context): array { return ['success' => false]; }
    public function supports(string $capability): bool { return true; }
    public function getInstitution(): string { return $this->institution; }
}

/**
 * The same rules as CashoutRedemptionGuardTest, exercised through the real
 * confirmCashout() so the branch wiring is covered and not just the
 * predicates: which states reach a bank debit, and which never do.
 */
class CashoutRedemptionBranchTest extends TestCase
{
    private const SOURCE = 'ZURUBANK';

    private PDO $db;
    private SwapService $service;

    protected function setUp(): void
    {
        RedemptionSentinelAdapter::reset();

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE cashout_authorizations (
                auth_id INTEGER PRIMARY KEY AUTOINCREMENT,
                swap_reference TEXT NOT NULL,
                source_institution TEXT,
                destination_institution TEXT,
                amount REAL,
                currency TEXT DEFAULT 'BWP',
                fee_amount REAL DEFAULT 0,
                swap_code TEXT,
                code_expiry TEXT,
                status TEXT NOT NULL,
                released_at TEXT,
                hold_reference TEXT,
                user_id INTEGER,
                created_at TEXT DEFAULT (datetime('now'))
            )
        ");

        $participants = [
            self::SOURCE => [
                'provider_code' => self::SOURCE,
                'adapter_class' => RedemptionSentinelAdapter::class,
            ],
        ];

        $reflection = new \ReflectionClass(SwapService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();

        $this->setPrivate('swapDB', $this->db);
        $this->setPrivate('logger', new class {
            public function __call($name, $args) {}
        });
        $this->setPrivate('adapterFactory', new \Infrastructure\Adapters\InstitutionAdapterFactory(
            $participants,
            new class { public function __call($name, $args) {} }
        ));
    }

    private function setPrivate(string $property, $value): void
    {
        $p = new \ReflectionProperty(SwapService::class, $property);
        $p->setAccessible(true);
        $p->setValue($this->service, $value);
    }

    private function addAuthorization(array $overrides = []): int
    {
        $row = array_merge([
            'swap_reference' => 'SWAP_TEST_1',
            'source_institution' => self::SOURCE,
            'destination_institution' => self::SOURCE,
            'amount' => 500.00,
            'currency' => 'BWP',
            'fee_amount' => 0,
            'swap_code' => 'CODE123',
            'code_expiry' => date('Y-m-d H:i:s', time() + 3600),
            'status' => 'PENDING',
            'released_at' => null,
            'hold_reference' => 'HOLD_REF_1',
            'user_id' => 1,
        ], $overrides);

        $cols = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $this->db->prepare("INSERT INTO cashout_authorizations ({$cols}) VALUES ({$placeholders})");
        $stmt->execute(array_values($row));

        return (int)$this->db->lastInsertId();
    }

    public function testAReleasedHoldIsNeverDebitedOnAnAtmCallback(): void
    {
        // Cash is already out. Debiting would take money that is no longer
        // reserved, so the answer is a reconciliation flag, not a debit.
        $authId = $this->addAuthorization(['status' => 'EXPIRED', 'released_at' => '2026-09-19 10:00:00']);

        $result = $this->service->confirmCashout([
            'auth_id' => $authId,
            'is_callback' => true,
            'voucher_number' => 'CODE123',
        ]);

        $this->assertSame('hold_already_released', $result['status']);
        $this->assertTrue($result['manual_reconciliation_required']);
        $this->assertSame([], RedemptionSentinelAdapter::$debitCalls, 'no bank debit may be attempted');
    }

    public function testAReleasedHoldIsRefusedOutrightBeforeAnyCashIsDispensed(): void
    {
        $authId = $this->addAuthorization(['status' => 'EXPIRED', 'released_at' => '2026-09-19 10:00:00']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already released/i');

        $this->service->confirmCashout([
            'auth_id' => $authId,
            'is_callback' => false,
            'to_institution' => self::SOURCE,
        ]);
    }

    public function testAnExpiredCodeIsRefusedBeforeAnyCashIsDispensed(): void
    {
        $authId = $this->addAuthorization([
            'status' => 'PENDING',
            'code_expiry' => date('Y-m-d H:i:s', time() - 60),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/expired/i');

        $this->service->confirmCashout([
            'auth_id' => $authId,
            'is_callback' => false,
            'to_institution' => self::SOURCE,
        ]);

        $this->assertSame([], RedemptionSentinelAdapter::$debitCalls);
    }

    public function testAnExpiredCodeWhoseHoldIsStillLiveIsStillDebitedOnCallback(): void
    {
        // The window between code_expiry and the sweeper's release buffer:
        // the bank honoured a stale code, but the money IS still reserved
        // for exactly this. Refusing here would strand cash already handed
        // over, so the debit must still be attempted.
        $authId = $this->addAuthorization([
            'status' => 'PENDING',
            'code_expiry' => date('Y-m-d H:i:s', time() - 60),
        ]);

        try {
            $this->service->confirmCashout([
                'auth_id' => $authId,
                'is_callback' => true,
                'voucher_number' => 'CODE123',
            ]);
            $this->fail('expected the sentinel adapter to be reached');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(RedemptionSentinelAdapter::SENTINEL, $e->getMessage());
        }

        $this->assertCount(1, RedemptionSentinelAdapter::$debitCalls, 'the debit must still be attempted');
    }

    public function testAHealthyAuthorizationIsNotBlockedByTheNewGuards(): void
    {
        $authId = $this->addAuthorization(['status' => 'PENDING']);

        try {
            $this->service->confirmCashout([
                'auth_id' => $authId,
                'is_callback' => true,
                'voucher_number' => 'CODE123',
            ]);
            $this->fail('expected the sentinel adapter to be reached');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(RedemptionSentinelAdapter::SENTINEL, $e->getMessage());
        }

        $this->assertCount(1, RedemptionSentinelAdapter::$debitCalls);
    }

    public function testAnAlreadyCompletedCashoutIsStillIdempotent(): void
    {
        $authId = $this->addAuthorization(['status' => 'COMPLETED']);

        $result = $this->service->confirmCashout([
            'auth_id' => $authId,
            'is_callback' => true,
            'voucher_number' => 'CODE123',
        ]);

        $this->assertSame('already_completed', $result['status']);
        $this->assertSame([], RedemptionSentinelAdapter::$debitCalls);
    }
}
