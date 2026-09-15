<?php

use PHPUnit\Framework\TestCase;
use Infrastructure\Adapters\InstitutionAdapterInterface;
use Infrastructure\Adapters\InstitutionAdapterFactory;
use Domain\Services\ReservationAccountService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Test double standing in for GenericInstitutionAdapter. InstitutionAdapterFactory
 * always calls `new $adapterClass($bankClient, $logger, $institution, $participant)`
 * (see InstitutionAdapterFactory::getAdapter()) and picks $adapterClass from the
 * participant config's adapter_class field, so pointing a fake participant's
 * adapter_class at this class is enough to substitute it in — no changes to
 * production code needed. Canned responses/call counts are keyed by institution
 * in static arrays so each test configures only the institution it cares about.
 */
class FakeInstitutionAdapter implements InstitutionAdapterInterface
{
    /** @var array<string, array> */
    public static array $createReservationAccountResponses = [];
    /** @var array<string, int> */
    public static array $createReservationAccountCallCounts = [];
    /** @var array<string, array> */
    public static array $releaseHoldResponses = [];
    /** @var array<string, array> */
    public static array $releaseHoldCalls = [];
    /** @var array<string, array> */
    public static array $creditResponses = [];
    /** @var array<string, array> */
    public static array $creditCalls = [];

    public static function reset(): void
    {
        self::$createReservationAccountResponses = [];
        self::$createReservationAccountCallCounts = [];
        self::$releaseHoldResponses = [];
        self::$releaseHoldCalls = [];
        self::$creditResponses = [];
        self::$creditCalls = [];
    }

    private string $institution;

    public function __construct($bankClient, $logger, string $institution, array $config)
    {
        $this->institution = $institution;
    }

    public function createReservationAccount(array $payload, array $context): array
    {
        self::$createReservationAccountCallCounts[$this->institution] =
            (self::$createReservationAccountCallCounts[$this->institution] ?? 0) + 1;

        $queue = self::$createReservationAccountResponses[$this->institution] ?? [];
        if (empty($queue)) {
            return ['success' => false, 'message' => 'no fake response configured for ' . $this->institution];
        }
        if (count($queue) > 1) {
            return array_shift(self::$createReservationAccountResponses[$this->institution]);
        }
        return $queue[0];
    }

    public function getReservationAccountStatus(array $payload, array $context): array
    {
        return ['success' => true, 'status' => 'active'];
    }

    public function releaseHold(array $payload, array $context): array
    {
        self::$releaseHoldCalls[$this->institution][] = $payload;
        return self::$releaseHoldResponses[$this->institution] ?? ['released' => true, 'success' => true];
    }

    public function credit(array $payload, array $context): array
    {
        self::$creditCalls[$this->institution][] = $payload;

        $configured = self::$creditResponses[$this->institution] ?? null;
        if ($configured === null) {
            return ['credited' => true, 'success' => true, 'transaction_reference' => 'FAKE_TXN'];
        }
        // A queue (list of responses, one per call) if the first element is
        // itself an array; otherwise treat it as a single fixed response.
        if (isset($configured[0]) && is_array($configured[0])) {
            return count($configured) > 1 ? array_shift(self::$creditResponses[$this->institution]) : $configured[0];
        }
        return $configured;
    }

    public function verifyAsset(array $payload, array $context): array { return ['verified' => false, 'success' => false]; }
    public function placeHold(array $payload, array $context): array { return ['hold_placed' => false, 'success' => false]; }
    public function debit(array $payload, array $context): array { return ['debited' => false, 'success' => false]; }
    public function generateCashoutToken(array $payload, array $context): array { return ['success' => false]; }
    public function verifyCashoutToken(array $payload, array $context): array { return ['verified' => false]; }
    public function confirmCashout(array $payload, array $context): array { return ['confirmed' => false]; }
    public function verifyAccount(array $payload, array $context): array { return ['verified' => false, 'success' => false]; }
    public function getBalance(array $payload, array $context): array { return ['success' => false, 'balance' => 0]; }
    public function getTransactions(array $payload, array $context): array { return ['success' => false, 'transactions' => []]; }
    public function checkSettlementStatus(array $payload, array $context): array { return ['success' => false, 'settled' => false]; }
    public function getAccounts(array $payload, array $context): array { return ['success' => false, 'accounts' => []]; }
    public function supports(string $capability): bool { return true; }
    public function getInstitution(): string { return $this->institution; }
}

class ReservationAccountServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        FakeInstitutionAdapter::reset();

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // ReservationAccountService's queries use now() (Postgres) — register
        // it as a SQLite UDF rather than changing the production SQL.
        $this->db->sqliteCreateFunction('now', function () {
            return date('Y-m-d H:i:s');
        });

        $this->db->exec("
            CREATE TABLE reservation_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                institution TEXT NOT NULL,
                currency TEXT NOT NULL,
                account_identifier TEXT,
                account_identifier_type TEXT DEFAULT 'account_number',
                status TEXT NOT NULL DEFAULT 'pending',
                bank_reference TEXT,
                request_payload TEXT,
                response_payload TEXT,
                requested_at TEXT DEFAULT (datetime('now')),
                activated_at TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now')),
                UNIQUE(user_id, institution, currency)
            )
        ");

        $this->db->exec("
            CREATE TABLE identity_holding_positions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                identity_type TEXT,
                identity_value TEXT,
                institution TEXT,
                currency TEXT,
                holding_identifier TEXT,
                hold_reference TEXT,
                amount REAL,
                source_hold_ids TEXT,
                consolidation_reference TEXT,
                status TEXT DEFAULT 'open',
                owner_user_id INTEGER,
                swept_to_reservation_account_id INTEGER,
                swept_at TEXT,
                hold_released_at TEXT,
                sweep_claimed_at TEXT,
                created_at TEXT DEFAULT (datetime('now'))
            )
        ");
    }

    /**
     * @param array $extraCapabilities merged into the fake participant's capabilities block
     */
    private function makeService(
        string $institution,
        bool $reservationAccountsSupported = true,
        int $pollAttempts = 2,
        int $pollDelaySeconds = 0
    ): ReservationAccountService {
        $participants = [
            $institution => [
                'provider_code' => $institution,
                'adapter_class' => FakeInstitutionAdapter::class,
                'capabilities' => [
                    'reservation_accounts' => $reservationAccountsSupported,
                ],
            ],
        ];

        $logger = new class {
            public function __call($name, $args) {}
        };

        $adapterFactory = new InstitutionAdapterFactory($participants, $logger);

        return new ReservationAccountService($this->db, $participants, $adapterFactory, $logger, $pollAttempts, $pollDelaySeconds);
    }

    private function countReservationAccounts(int $userId, string $institution, string $currency): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM reservation_accounts WHERE user_id = ? AND institution = ? AND currency = ?");
        $stmt->execute([$userId, $institution, $currency]);
        return (int)$stmt->fetchColumn();
    }

    // ------------------------------------------------------------
    // 1. Get-or-create dedup
    // ------------------------------------------------------------
    public function testResolveOrCreateReusesExistingActiveAccount(): void
    {
        $institution = 'ZURUBANK';
        FakeInstitutionAdapter::$createReservationAccountResponses[$institution] = [[
            'success' => true,
            'status' => 'active',
            'account_identifier' => 'RESACC-001',
            'account_identifier_type' => 'account_number',
        ]];

        $service = $this->makeService($institution);

        $first = $service->resolveOrCreateReservationAccount(42, $institution, 'BWP');
        $second = $service->resolveOrCreateReservationAccount(42, $institution, 'BWP');

        $this->assertSame('active', $first['status']);
        $this->assertSame('RESACC-001', $first['account_identifier']);
        $this->assertSame('active', $second['status']);
        $this->assertSame('RESACC-001', $second['account_identifier']);

        $this->assertSame(1, FakeInstitutionAdapter::$createReservationAccountCallCounts[$institution] ?? 0);
        $this->assertSame(1, $this->countReservationAccounts(42, $institution, 'BWP'));
    }

    // ------------------------------------------------------------
    // 2. Multi-identity dedup — two claim events for the SAME resolved
    // person (the point of keying by user_id, not identity_value) must
    // land on the same row.
    // ------------------------------------------------------------
    public function testSamePersonDifferentIdentitiesShareOneAccount(): void
    {
        $institution = 'SACCUSSALIS';
        FakeInstitutionAdapter::$createReservationAccountResponses[$institution] = [[
            'success' => true,
            'status' => 'active',
            'account_identifier' => 'RESACC-777',
        ]];

        $service = $this->makeService($institution);

        // Simulates: money claimed via the phone identity, then separately
        // via the email identity — both resolve to the same user_id=7.
        $viaPhoneClaim = $service->resolveOrCreateReservationAccount(7, $institution, 'BWP');
        $viaEmailClaim = $service->resolveOrCreateReservationAccount(7, $institution, 'BWP');

        $this->assertSame($viaPhoneClaim['account_identifier'], $viaEmailClaim['account_identifier']);
        $this->assertSame(1, $this->countReservationAccounts(7, $institution, 'BWP'));
        $this->assertSame(1, FakeInstitutionAdapter::$createReservationAccountCallCounts[$institution] ?? 0);
    }

    // ------------------------------------------------------------
    // 3a. Concurrency: a row already 'pending' (another request won the
    // INSERT..ON CONFLICT race) must not be duplicated, and this caller
    // must not call the bank itself.
    // ------------------------------------------------------------
    public function testConcurrentPendingCreationIsNotDuplicated(): void
    {
        $institution = 'CAZACOM';
        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, bank_reference)
            VALUES (5, ?, 'BWP', 'pending', 'RESACC_5_INFLIGHT')
        ")->execute([$institution]);

        $service = $this->makeService($institution, true, 2, 0);

        $result = $service->resolveOrCreateReservationAccount(5, $institution, 'BWP');

        $this->assertSame('pending', $result['status']);
        $this->assertSame(1, $this->countReservationAccounts(5, $institution, 'BWP'));
        $this->assertArrayNotHasKey($institution, FakeInstitutionAdapter::$createReservationAccountCallCounts);
    }

    // ------------------------------------------------------------
    // 3b. Concurrency: a row left 'failed' by a previous attempt is
    // reclaimed and retried exactly once, never duplicated.
    // ------------------------------------------------------------
    public function testFailedAccountIsReclaimedAndRetried(): void
    {
        $institution = 'MTN';
        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, bank_reference)
            VALUES (9, ?, 'BWP', 'failed', 'RESACC_9_RETRY')
        ")->execute([$institution]);

        FakeInstitutionAdapter::$createReservationAccountResponses[$institution] = [[
            'success' => true,
            'status' => 'active',
            'account_identifier' => 'RESACC-RECLAIMED',
        ]];

        $service = $this->makeService($institution);
        $result = $service->resolveOrCreateReservationAccount(9, $institution, 'BWP');

        $this->assertSame('active', $result['status']);
        $this->assertSame('RESACC-RECLAIMED', $result['account_identifier']);
        $this->assertSame(1, $this->countReservationAccounts(9, $institution, 'BWP'));
        $this->assertSame(1, FakeInstitutionAdapter::$createReservationAccountCallCounts[$institution] ?? 0);
    }

    // ------------------------------------------------------------
    // 4. Capability gate off — institution not onboarded.
    // ------------------------------------------------------------
    public function testUnsupportedInstitutionReturnsUnsupported(): void
    {
        $institution = 'ABSA';
        $service = $this->makeService($institution, false);

        $result = $service->resolveOrCreateReservationAccount(1, $institution, 'BWP');

        $this->assertFalse($result['supported']);
        $this->assertSame(0, $this->countReservationAccounts(1, $institution, 'BWP'));
        $this->assertArrayNotHasKey($institution, FakeInstitutionAdapter::$createReservationAccountCallCounts);
    }

    // ------------------------------------------------------------
    // 5. Async pending path, then a webhook confirms it.
    // ------------------------------------------------------------
    public function testAsyncPendingThenConfirmActivationActivates(): void
    {
        $institution = 'ZURUBANK';
        FakeInstitutionAdapter::$createReservationAccountResponses[$institution] = [[
            'success' => true,
            'status' => 'pending',
        ]];

        $service = $this->makeService($institution);
        $result = $service->resolveOrCreateReservationAccount(3, $institution, 'BWP');

        $this->assertSame('pending', $result['status']);

        $stmt = $this->db->prepare("SELECT bank_reference FROM reservation_accounts WHERE user_id = 3 AND institution = ?");
        $stmt->execute([$institution]);
        $bankReference = $stmt->fetchColumn();
        $this->assertNotEmpty($bankReference);

        $activatedId = $service->confirmActivation($bankReference, 'RESACC-ASYNC', 'account_number', ['confirmed' => true]);
        $this->assertNotNull($activatedId);

        $stmt = $this->db->prepare("SELECT status, account_identifier FROM reservation_accounts WHERE id = ?");
        $stmt->execute([$activatedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('active', $row['status']);
        $this->assertSame('RESACC-ASYNC', $row['account_identifier']);
    }

    // ------------------------------------------------------------
    // 6. Bank rejection.
    // ------------------------------------------------------------
    public function testBankRejectionMarksAccountFailed(): void
    {
        $institution = 'SACCUSSALIS';
        FakeInstitutionAdapter::$createReservationAccountResponses[$institution] = [[
            'success' => false,
            'message' => 'KYC declined',
        ]];

        $service = $this->makeService($institution);
        $result = $service->resolveOrCreateReservationAccount(11, $institution, 'BWP');

        $this->assertSame('failed', $result['status']);

        $stmt = $this->db->prepare("SELECT status FROM reservation_accounts WHERE user_id = 11 AND institution = ?");
        $stmt->execute([$institution]);
        $this->assertSame('failed', $stmt->fetchColumn());
    }

    // ------------------------------------------------------------
    // 7. Sweep on activation — releases the pooled hold, deposits into
    // the reservation account, and flips the position to swept.
    // ------------------------------------------------------------
    public function testSweepOpenPositionsReleasesHoldAndDeposits(): void
    {
        $institution = 'ZURUBANK';

        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier, bank_reference)
            VALUES (21, ?, 'BWP', 'active', 'RESACC-SWEEP', 'RESACC_21_SWEEP')
        ")->execute([$institution]);
        $accountId = (int)$this->db->lastInsertId();

        $this->db->prepare("
            INSERT INTO identity_holding_positions (identity_type, identity_value, institution, currency, holding_identifier, hold_reference, amount, status, owner_user_id)
            VALUES ('phone', '+26771234567', ?, 'BWP', 'HOLDING-ACC', 'HOLD-REF-1', 150.00, 'open', 21)
        ")->execute([$institution]);
        $positionId = (int)$this->db->lastInsertId();

        $service = $this->makeService($institution);
        $result = $service->sweepOpenPositionsFor($accountId);

        $this->assertSame(1, $result['swept']);
        $this->assertSame(0, $result['failed']);

        $this->assertCount(1, FakeInstitutionAdapter::$releaseHoldCalls[$institution] ?? []);
        $this->assertSame('HOLD-REF-1', FakeInstitutionAdapter::$releaseHoldCalls[$institution][0]['hold_reference']);
        $this->assertCount(1, FakeInstitutionAdapter::$creditCalls[$institution] ?? []);
        $this->assertSame('RESACC-SWEEP', FakeInstitutionAdapter::$creditCalls[$institution][0]['destination_identifier']);

        $stmt = $this->db->prepare("SELECT status, swept_to_reservation_account_id FROM identity_holding_positions WHERE id = ?");
        $stmt->execute([$positionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('swept', $row['status']);
        $this->assertSame($accountId, (int)$row['swept_to_reservation_account_id']);
    }

    // ------------------------------------------------------------
    // 8. Cron discovery query only returns active accounts with an open position.
    // ------------------------------------------------------------
    public function testFindActiveAccountIdsWithOpenPositions(): void
    {
        $institution = 'CAZACOM';

        // Active account WITH an open position — should be found.
        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier)
            VALUES (31, ?, 'BWP', 'active', 'RESACC-A')
        ")->execute([$institution]);
        $activeWithOpenId = (int)$this->db->lastInsertId();
        $this->db->prepare("
            INSERT INTO identity_holding_positions (institution, currency, amount, status, owner_user_id)
            VALUES (?, 'BWP', 50.00, 'open', 31)
        ")->execute([$institution]);

        // Active account with NO open position — should not be found.
        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier)
            VALUES (32, ?, 'BWP', 'active', 'RESACC-B')
        ")->execute([$institution]);

        // Pending account WITH an open position — should not be found (not active).
        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status)
            VALUES (33, ?, 'BWP', 'pending')
        ")->execute([$institution]);
        $this->db->prepare("
            INSERT INTO identity_holding_positions (institution, currency, amount, status, owner_user_id)
            VALUES (?, 'BWP', 20.00, 'open', 33)
        ")->execute([$institution]);

        $service = $this->makeService($institution);
        $ids = $service->findActiveAccountIdsWithOpenPositions(50);

        $this->assertSame([$activeWithOpenId], $ids);
    }

    // ------------------------------------------------------------
    // 9. Sweep retry: a transient failure leaves the position open;
    // the next run (bank now healthy) sweeps it successfully.
    // ------------------------------------------------------------
    public function testSweepRetriesAfterTransientFailure(): void
    {
        $institution = 'MTN';

        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier)
            VALUES (41, ?, 'BWP', 'active', 'RESACC-RETRY')
        ")->execute([$institution]);
        $accountId = (int)$this->db->lastInsertId();

        $this->db->prepare("
            INSERT INTO identity_holding_positions (institution, currency, hold_reference, amount, status, owner_user_id)
            VALUES (?, 'BWP', 'HOLD-REF-9', 75.00, 'open', 41)
        ")->execute([$institution]);
        $positionId = (int)$this->db->lastInsertId();

        // First attempt: the release call itself fails.
        FakeInstitutionAdapter::$releaseHoldResponses[$institution] = ['released' => false, 'success' => false, 'message' => 'timeout'];

        $service = $this->makeService($institution);
        $firstAttempt = $service->sweepOpenPositionsFor($accountId);
        $this->assertSame(0, $firstAttempt['swept']);
        $this->assertSame(1, $firstAttempt['failed']);

        $stmt = $this->db->prepare("SELECT status, hold_released_at FROM identity_holding_positions WHERE id = ?");
        $stmt->execute([$positionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('sweep_failed', $row['status']);
        $this->assertEmpty($row['hold_released_at']);

        // Second attempt: bank is healthy again.
        FakeInstitutionAdapter::$releaseHoldResponses[$institution] = ['released' => true, 'success' => true];
        $secondAttempt = $service->sweepOpenPositionsFor($accountId);
        $this->assertSame(1, $secondAttempt['swept']);
        $this->assertSame(0, $secondAttempt['failed']);

        $stmt->execute([$positionId]);
        $this->assertSame('swept', $stmt->fetchColumn());
    }

    // ------------------------------------------------------------
    // 10. A position already claimed by a concurrent sweep (status =
    // 'sweeping', simulating the callback and cron racing each other)
    // is never double-processed by this run.
    // ------------------------------------------------------------
    public function testSweepSkipsPositionAlreadyClaimedByConcurrentSweep(): void
    {
        $institution = 'ZURUBANK';

        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier)
            VALUES (51, ?, 'BWP', 'active', 'RESACC-CONCURRENT')
        ")->execute([$institution]);
        $accountId = (int)$this->db->lastInsertId();

        // Already claimed by "another" concurrent sweep run.
        $this->db->prepare("
            INSERT INTO identity_holding_positions (institution, currency, hold_reference, amount, status, owner_user_id)
            VALUES (?, 'BWP', 'HOLD-REF-CONCURRENT', 40.00, 'sweeping', 51)
        ")->execute([$institution]);
        $positionId = (int)$this->db->lastInsertId();

        $service = $this->makeService($institution);
        $result = $service->sweepOpenPositionsFor($accountId);

        $this->assertSame(0, $result['swept']);
        $this->assertSame(0, $result['failed']);
        $this->assertArrayNotHasKey($institution, FakeInstitutionAdapter::$releaseHoldCalls);
        $this->assertArrayNotHasKey($institution, FakeInstitutionAdapter::$creditCalls);

        $stmt = $this->db->prepare("SELECT status FROM identity_holding_positions WHERE id = ?");
        $stmt->execute([$positionId]);
        $this->assertSame('sweeping', $stmt->fetchColumn());
    }

    // ------------------------------------------------------------
    // 11. Release succeeds but the deposit fails: a retry must NOT
    // re-release the (already-released) hold_reference — most bank APIs
    // reject a second release of the same hold.
    // ------------------------------------------------------------
    public function testSweepRetryDoesNotReReleaseAnAlreadyReleasedHold(): void
    {
        $institution = 'CAZACOM';

        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier)
            VALUES (61, ?, 'BWP', 'active', 'RESACC-PARTIAL')
        ")->execute([$institution]);
        $accountId = (int)$this->db->lastInsertId();

        $this->db->prepare("
            INSERT INTO identity_holding_positions (institution, currency, hold_reference, amount, status, owner_user_id)
            VALUES (?, 'BWP', 'HOLD-REF-PARTIAL', 90.00, 'open', 61)
        ")->execute([$institution]);
        $positionId = (int)$this->db->lastInsertId();

        // Release always succeeds; deposit fails once then succeeds.
        FakeInstitutionAdapter::$releaseHoldResponses[$institution] = ['released' => true, 'success' => true];
        FakeInstitutionAdapter::$creditResponses[$institution] = [
            ['credited' => false, 'success' => false, 'message' => 'network blip'],
            ['credited' => true, 'success' => true, 'transaction_reference' => 'FAKE_TXN'],
        ];

        $service = $this->makeService($institution);

        $first = $service->sweepOpenPositionsFor($accountId);
        $this->assertSame(0, $first['swept']);
        $this->assertSame(1, $first['failed']);
        $this->assertCount(1, FakeInstitutionAdapter::$releaseHoldCalls[$institution] ?? []);

        $stmt = $this->db->prepare("SELECT status, hold_released_at FROM identity_holding_positions WHERE id = ?");
        $stmt->execute([$positionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('sweep_failed', $row['status']);
        $this->assertNotEmpty($row['hold_released_at']);

        $second = $service->sweepOpenPositionsFor($accountId);
        $this->assertSame(1, $second['swept']);
        $this->assertSame(0, $second['failed']);

        // Still exactly one release call across BOTH attempts.
        $this->assertCount(1, FakeInstitutionAdapter::$releaseHoldCalls[$institution] ?? []);
        $this->assertCount(2, FakeInstitutionAdapter::$creditCalls[$institution] ?? []);

        $stmt->execute([$positionId]);
        $this->assertSame('swept', $stmt->fetch(PDO::FETCH_ASSOC)['status']);
    }

    // ------------------------------------------------------------
    // 12. A reservation_accounts row orphaned mid-creation (crashed
    // before the bank was ever reached: response_payload still NULL,
    // stuck 'pending' well past the staleness window) is reclaimed and
    // retried rather than silently falling back to pooled holding forever.
    // ------------------------------------------------------------
    public function testOrphanedPendingAccountIsReclaimedAfterStaleness(): void
    {
        $institution = 'SACCUSSALIS';

        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, bank_reference, response_payload, requested_at)
            VALUES (71, ?, 'BWP', 'pending', 'RESACC_71_ORPHAN', NULL, datetime('now', '-20 minutes'))
        ")->execute([$institution]);

        FakeInstitutionAdapter::$createReservationAccountResponses[$institution] = [[
            'success' => true,
            'status' => 'active',
            'account_identifier' => 'RESACC-RECOVERED',
        ]];

        $service = $this->makeService($institution);
        $result = $service->resolveOrCreateReservationAccount(71, $institution, 'BWP');

        $this->assertSame('active', $result['status']);
        $this->assertSame('RESACC-RECOVERED', $result['account_identifier']);
        $this->assertSame(1, $this->countReservationAccounts(71, $institution, 'BWP'));
        $this->assertSame(1, FakeInstitutionAdapter::$createReservationAccountCallCounts[$institution] ?? 0);
    }

    // ------------------------------------------------------------
    // 13. A genuinely async-pending account (the bank DID acknowledge —
    // response_payload is set) must NEVER be reclaimed by the staleness
    // timeout, no matter how old, since it's still legitimately waiting
    // on the bank's own callback.
    // ------------------------------------------------------------
    public function testGenuineAsyncPendingAccountIsNeverReclaimedByStaleness(): void
    {
        $institution = 'MTN';

        $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, bank_reference, response_payload, requested_at)
            VALUES (81, ?, 'BWP', 'pending', 'RESACC_81_ASYNC', '{\"status\":\"pending\"}', datetime('now', '-1 hour'))
        ")->execute([$institution]);

        $service = $this->makeService($institution);
        $result = $service->resolveOrCreateReservationAccount(81, $institution, 'BWP');

        $this->assertSame('pending', $result['status']);
        $this->assertSame(1, $this->countReservationAccounts(81, $institution, 'BWP'));
        $this->assertArrayNotHasKey($institution, FakeInstitutionAdapter::$createReservationAccountCallCounts);
    }

    // ------------------------------------------------------------
    // 14. A position stuck in 'sweeping' because its worker died
    // mid-flight is reclaimed (-> 'sweep_failed', so the ordinary sweep
    // query picks it back up next run) once stale enough.
    // ------------------------------------------------------------
    public function testStaleSweepingPositionIsReclaimed(): void
    {
        $this->db->exec("
            INSERT INTO identity_holding_positions (institution, currency, amount, status, owner_user_id, sweep_claimed_at)
            VALUES ('ZURUBANK', 'BWP', 30.00, 'sweeping', 91, datetime('now', '-20 minutes'))
        ");
        $positionId = (int)$this->db->lastInsertId();

        $service = $this->makeService('ZURUBANK');
        $reclaimed = $service->reclaimStaleSweepingPositions(50);

        $this->assertSame(1, $reclaimed);

        $stmt = $this->db->prepare("SELECT status FROM identity_holding_positions WHERE id = ?");
        $stmt->execute([$positionId]);
        $this->assertSame('sweep_failed', $stmt->fetchColumn());
    }

    // ------------------------------------------------------------
    // 15. A position that's only just been claimed (a worker is
    // plausibly still alive and working on it) must NOT be touched.
    // ------------------------------------------------------------
    public function testFreshSweepingPositionIsNotReclaimed(): void
    {
        $this->db->exec("
            INSERT INTO identity_holding_positions (institution, currency, amount, status, owner_user_id, sweep_claimed_at)
            VALUES ('ZURUBANK', 'BWP', 30.00, 'sweeping', 92, datetime('now'))
        ");
        $positionId = (int)$this->db->lastInsertId();

        $service = $this->makeService('ZURUBANK');
        $reclaimed = $service->reclaimStaleSweepingPositions(50);

        $this->assertSame(0, $reclaimed);

        $stmt = $this->db->prepare("SELECT status FROM identity_holding_positions WHERE id = ?");
        $stmt->execute([$positionId]);
        $this->assertSame('sweeping', $stmt->fetchColumn());
    }

    // ------------------------------------------------------------
    // 16. getById() / closePosition() -- Increment 7 (residual rollover,
    // swap-to-identity algorithm v2 §9 / plan §7). SwapService::
    // initiateResidualRollover() looks a position up by id (it's not
    // resolving by user/institution/currency, the caller already knows
    // which position it wants) and closes it once a new hold representing
    // its value exists.
    // ------------------------------------------------------------
    public function testGetByIdReturnsTheAccountRow(): void
    {
        $this->db->exec("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier)
            VALUES (77, 'ZURUBANK', 'BWP', 'active', 'ACC-ROLLOVER-1')
        ");
        $id = (int)$this->db->lastInsertId();

        $service = $this->makeService('ZURUBANK');
        $row = $service->getById($id);

        $this->assertNotNull($row);
        $this->assertSame('ACC-ROLLOVER-1', $row['account_identifier']);
        $this->assertSame('active', $row['status']);
    }

    public function testGetByIdReturnsNullForUnknownId(): void
    {
        $service = $this->makeService('ZURUBANK');
        $this->assertNull($service->getById(999999));
    }

    public function testClosePositionTransitionsActiveToConsumed(): void
    {
        $this->db->exec("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier)
            VALUES (78, 'ZURUBANK', 'BWP', 'active', 'ACC-ROLLOVER-2')
        ");
        $id = (int)$this->db->lastInsertId();

        $service = $this->makeService('ZURUBANK');
        $closed = $service->closePosition($id);

        $this->assertTrue($closed);
        $this->assertSame('consumed', $service->getById($id)['status']);
    }

    public function testClosePositionIsCompareAndSwapNotDoubleCloseable(): void
    {
        // Spec §9 3f: "close position P (fully consumed)" -- must happen
        // exactly once. A second close attempt (e.g. a retried rollover
        // request) must not report success against an already-consumed
        // position.
        $this->db->exec("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier)
            VALUES (79, 'ZURUBANK', 'BWP', 'active', 'ACC-ROLLOVER-3')
        ");
        $id = (int)$this->db->lastInsertId();

        $service = $this->makeService('ZURUBANK');
        $this->assertTrue($service->closePosition($id));
        $this->assertFalse($service->closePosition($id), 'second close attempt must not succeed');
    }

    public function testClosePositionRefusesToCloseAPendingOrFailedAccount(): void
    {
        $this->db->exec("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, account_identifier)
            VALUES (80, 'ZURUBANK', 'BWP', 'pending', NULL)
        ");
        $pendingId = (int)$this->db->lastInsertId();

        $service = $this->makeService('ZURUBANK');
        $this->assertFalse($service->closePosition($pendingId));
        $this->assertSame('pending', $service->getById($pendingId)['status']);
    }
}
