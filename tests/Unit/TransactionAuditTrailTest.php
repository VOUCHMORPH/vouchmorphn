<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\AuditTrailService;
use Domain\Services\SwapService;
use Infrastructure\Adapters\InstitutionAdapterInterface;
use Infrastructure\Adapters\InstitutionAdapterFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Locks in the guarantee that every completed payment or transfer carries a
 * unique transaction reference AND an audit record naming who moved the
 * money, when, and where it went.
 *
 * None of this held before. audit_logs.entity_id was bigint while the only
 * writer on the money path passed a string swap reference, so every
 * financial audit insert failed with 22P02 and was diverted to a dead-letter
 * table nothing read. AuditTrailService separately named a `timestamp`
 * column that exists on no Botswana schema and sent uppercase severities
 * that its own CHECK constraint rejects -- then returned true when the table
 * was unreadable, reporting success for a row it had not written. A debit
 * could report success with transaction_reference => null.
 *
 * The sqlite schema below deliberately mirrors the real Botswana audit_logs
 * AFTER 2026_09_16_transaction_audit_integrity.sql: entity_id is TEXT, the
 * severity CHECK is present and lowercase, prev_hash/entry_hash exist, and
 * there is NO `timestamp` column. A regression in any of those fails here.
 */
class TransactionAuditTrailTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The production SQL uses Postgres' NOW(); give sqlite the same name
        // rather than altering the query under test.
        $this->db->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
        $this->createAuditLogsTable($this->db);
    }

    private function createAuditLogsTable(PDO $db, bool $withDetailsColumn = false): void
    {
        // `details` is the plain-text payload column some deployments have.
        // Where it exists writeAuditLogEntry() uses it in preference to the
        // jsonb columns, which lets the payload assertions below run without
        // Postgres-specific ::jsonb casts.
        $details = $withDetailsColumn ? 'details TEXT,' : '';
        $db->exec("
            CREATE TABLE audit_logs (
                audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
                audit_uuid TEXT,
                entity_type TEXT,
                entity_id TEXT,
                action TEXT,
                category TEXT,
                severity TEXT DEFAULT 'info'
                    CHECK (severity IN ('info','warning','error','critical')),
                old_value TEXT,
                new_value TEXT,
                changes TEXT,
                {$details}
                performed_by_type TEXT
                    CHECK (performed_by_type IN ('user','admin','system')),
                performed_by_id INTEGER,
                ip_address TEXT,
                user_agent TEXT,
                geo_location TEXT,
                request_id TEXT,
                performed_at TEXT DEFAULT CURRENT_TIMESTAMP,
                prev_hash TEXT,
                entry_hash TEXT,
                event_type TEXT,
                client_id TEXT,
                endpoint TEXT,
                duration_ms INTEGER
            )
        ");
    }

    private function makeAuditTrailService(PDO $db): AuditTrailService
    {
        return new AuditTrailService($db, ['country' => 'BW'], null, 'BW');
    }

    // ================================================================
    // AuditTrailService
    // ================================================================

    public function testRecordLogActuallyWritesARow(): void
    {
        $service = $this->makeAuditTrailService($this->db);

        $this->assertTrue(
            $service->recordLog('swap_requests', 1001, 'LOGIN_SUCCESS', 'security', 'INFO'),
            'recordLog() should report success when the row really lands'
        );

        $rows = $this->db->query("SELECT * FROM audit_logs")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows, 'exactly one audit row should have been written');
        $this->assertSame('LOGIN_SUCCESS', $rows[0]['action']);
        // Uppercase in, DB-legal lowercase stored: the constraint the old
        // code violated on every single insert.
        $this->assertSame('info', $rows[0]['severity']);
    }

    /**
     * @dataProvider severityProvider
     */
    public function testSeverityIsNormalizedToADatabaseLegalValue(string $given, string $expected): void
    {
        $service = $this->makeAuditTrailService($this->db);
        $service->recordLog('swap_requests', 1, 'ACT_' . $given, 'security', $given);

        $stmt = $this->db->prepare("SELECT severity FROM audit_logs WHERE action = :a");
        $stmt->execute([':a' => 'ACT_' . $given]);
        $this->assertSame($expected, $stmt->fetchColumn());
    }

    public static function severityProvider(): array
    {
        return [
            'uppercase info'     => ['INFO', 'info'],
            'uppercase warning'  => ['WARNING', 'warning'],
            'uppercase error'    => ['ERROR', 'error'],
            'uppercase critical' => ['CRITICAL', 'critical'],
            'mixed case'         => ['WaRnInG', 'warning'],
            // DEBUG was in the old PHP list but has never been a legal DB
            // value; it must not reach the constraint.
            'debug maps to info' => ['DEBUG', 'info'],
            'unknown maps to info' => ['NONSENSE', 'info'],
        ];
    }

    public function testRecordLogReportsFailureWhenTheAuditTableIsUnreadable(): void
    {
        $emptyDb = new PDO('sqlite::memory:');
        $emptyDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $emptyDb->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
        // No audit_logs table at all.

        $service = $this->makeAuditTrailService($emptyDb);

        // This returned TRUE before -- claiming a successful audit write for
        // a row that was never written, which is how an unwritable audit
        // table went unnoticed.
        $this->assertFalse(
            $service->recordLog('swap_requests', 1, 'SWAP_EXECUTED', 'financial', 'INFO'),
            'an audit write that did not happen must not be reported as success'
        );
    }

    public function testASwapReferenceIsAValidEntityId(): void
    {
        $service = $this->makeAuditTrailService($this->db);
        $reference = 'SWAP_1758041234_a1b2c3d4e5f6a7b8';

        $this->assertTrue(
            $service->recordLog('swap_requests', $reference, 'SWAP_DEPOSIT_CREATED', 'financial', 'info'),
            'a string swap reference must be storable as entity_id'
        );

        $stmt = $this->db->prepare("SELECT entity_id FROM audit_logs WHERE action = 'SWAP_DEPOSIT_CREATED'");
        $stmt->execute();
        $this->assertSame($reference, $stmt->fetchColumn());
    }

    public function testGetLogsForEntityFiltersByTheEntityIdItWasGiven(): void
    {
        $service = $this->makeAuditTrailService($this->db);
        $service->recordLog('swap_requests', 'SWAP_AAA', 'SWAP_DEPOSIT_CREATED', 'financial', 'info');
        $service->recordLog('swap_requests', 'SWAP_BBB', 'SWAP_DEPOSIT_CREATED', 'financial', 'info');

        $logs = $service->getLogsForEntity('swap_requests', 'SWAP_AAA');

        // This used to drop the id and return every entity's rows.
        $this->assertCount(1, $logs);
        $this->assertSame('SWAP_AAA', $logs[0]['entity_id']);
    }

    // ================================================================
    // SwapService: the financial audit record
    // ================================================================

    private function makeSwapService(PDO $db): SwapService
    {
        // Built without the constructor on purpose: it loads country config,
        // certificates and adapters from the environment, none of which this
        // test needs or should depend on.
        $service = (new ReflectionClass(SwapService::class))->newInstanceWithoutConstructor();
        $this->setPrivate($service, 'swapDB', $db);
        $this->setPrivate($service, 'logger', new class {
            public function __call($name, $args) {}
        });
        return $service;
    }

    private function setPrivate(object $object, string $property, $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

    private function callPrivate(object $object, string $method, array $args)
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    public function testFinancialAuditRowRecordsWhoWhenAndWhereTheMoneyWent(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createAuditLogsTable($db, true);

        $service = $this->makeSwapService($db);
        $this->setPrivate($service, 'currentSwapRef', 'SWAP_1758041234_deadbeefdeadbeef');
        $this->setPrivate($service, 'currentHoldReference', 'HOLD_XYZ');
        $this->setPrivate($service, 'currentDebitReference', 'DBT_1758041234_cafebabecafebabe');
        $this->setPrivate($service, 'currentDebitReferenceIsLocal', false);
        $this->setPrivate($service, 'currentSwapStartedAt', '2026-09-16 10:00:00.000000');
        $this->setPrivate($service, 'currentClientInitiatedAt', '2026-09-16 09:59:58.500000');
        $this->setPrivate($service, 'feeCalculationDetails', ['total_fee' => 10.00]);

        $written = $this->callPrivate($service, 'populateAuditLog', [
            'SWAP_1758041234_deadbeefdeadbeef',
            'DEPOSIT',
            [
                'amount' => 1500.00,
                'currency' => 'BWP',
                'status' => 'completed',
                'from_institution' => 'ZURUBANK',
                'to_institution' => 'FNBB',
            ],
            [
                'source_institution' => 'ZURUBANK',
                'source_identifier' => '62000111222',
                'asset_type' => 'ACCOUNT',
                'destination_institution' => 'FNBB',
                'destination_identifier' => '71999888777',
                'destination_asset_type' => 'ACCOUNT',
            ],
            4242,
            ['transaction_reference' => 'FNBB_TXN_9001'],
        ]);

        $this->assertTrue($written, 'the financial audit row should have been written');

        $row = $db->query("SELECT * FROM audit_logs")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'a financial audit row must exist');
        $this->assertSame('SWAP_DEPOSIT_CREATED', $row['action']);
        $this->assertSame('financial', $row['category']);
        $this->assertSame('SWAP_1758041234_deadbeefdeadbeef', $row['entity_id']);
        $this->assertSame('info', $row['severity']);
        $this->assertNotEmpty($row['entry_hash'], 'the tamper-evident chain hash must be written');

        // The payload used to be null, so even a row that landed said
        // nothing about the money.
        $payload = json_decode($row['details'], true);
        $this->assertIsArray($payload, 'the audit row must carry a decodable payload');

        // WHAT. assertEquals, not assertSame: a whole-number float comes
        // back from json_decode() as an int, which says nothing about
        // whether the right value was recorded.
        $this->assertEquals(1500.00, $payload['amount']);
        $this->assertSame('BWP', $payload['currency']);
        $this->assertEquals(10.00, $payload['fee']);

        // WHERE the money went
        $this->assertSame('ZURUBANK', $payload['source']['institution']);
        $this->assertSame('62000111222', $payload['source']['identifier']);
        $this->assertSame('FNBB', $payload['destination']['institution']);
        $this->assertSame('71999888777', $payload['destination']['identifier']);

        // WHO
        $this->assertSame(4242, $payload['actor']['user_id']);
        $this->assertSame('user', $payload['actor']['type']);

        // WHEN
        $this->assertSame('2026-09-16 10:00:00.000000', $payload['started_at']);
        $this->assertSame('2026-09-16 09:59:58.500000', $payload['client_initiated_at']);
        $this->assertNotEmpty($payload['recorded_at']);

        // THE RECEIPTS
        $this->assertSame('SWAP_1758041234_deadbeefdeadbeef', $payload['references']['swap']);
        $this->assertSame('HOLD_XYZ', $payload['references']['hold']);
        $this->assertSame('DBT_1758041234_cafebabecafebabe', $payload['references']['debit']);
        $this->assertFalse($payload['references']['debit_reference_is_local']);
        $this->assertSame('FNBB_TXN_9001', $payload['references']['destination']);
    }

    // ================================================================
    // SwapService: a successful debit always carries a reference
    // ================================================================

    private function makeSwapServiceWithDebitAdapter(array $debitResponse): SwapService
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $service = $this->makeSwapService($db);

        FakeDebitAdapter::$debitResponse = $debitResponse;
        FakeDebitAdapter::$debitCalls = [];

        // Same substitution technique the reservation-account tests use: a
        // real factory, pointed at a fake adapter through participant config.
        $participants = ['TESTBANK' => ['adapter_class' => FakeDebitAdapter::class]];
        $this->setPrivate($service, 'participants', $participants);
        $this->setPrivate($service, 'adapterFactory', new InstitutionAdapterFactory($participants, null));
        $this->setPrivate($service, 'currentSwapRef', 'SWAP_1758041234_abcdefabcdefabcd');
        $this->setPrivate($service, 'currentHoldReference', 'HOLD_1');
        $this->setPrivate($service, 'signedPayloads', []);

        return $service;
    }

    public function testDebitSucceedingWithoutAnInstitutionReferenceStillGetsOne(): void
    {
        // A real adapter response shape: the debit worked, but the
        // institution returned no per-transaction reference.
        $service = $this->makeSwapServiceWithDebitAdapter(['debited' => true]);

        $result = $service->debitSource(['amount' => 100.00], 'TESTBANK');

        $this->assertTrue($result['debited']);
        $this->assertNotNull(
            $result['transaction_reference'],
            'money left the source, so there must be a receipt for it'
        );
        $this->assertNotSame('', $result['transaction_reference']);
        $this->assertStringStartsWith('DBT_', $result['transaction_reference']);
        $this->assertTrue(
            $result['transaction_reference_is_local'],
            'a reference we minted must be distinguishable from the institution\'s own'
        );
    }

    public function testDebitReferenceFromTheInstitutionIsPreserved(): void
    {
        $service = $this->makeSwapServiceWithDebitAdapter([
            'debited' => true,
            'transaction_reference' => 'TESTBANK_TXN_5150',
        ]);

        $result = $service->debitSource(['amount' => 100.00], 'TESTBANK');

        $this->assertSame('TESTBANK_TXN_5150', $result['transaction_reference']);
        $this->assertFalse($result['transaction_reference_is_local']);
    }

    public function testFailedDebitIsNotGivenAReference(): void
    {
        $service = $this->makeSwapServiceWithDebitAdapter(['debited' => false, 'message' => 'insufficient funds']);

        $result = $service->debitSource(['amount' => 100.00], 'TESTBANK');

        $this->assertFalse($result['debited']);
        $this->assertNull(
            $result['transaction_reference'],
            'no money moved, so there is nothing to issue a receipt for'
        );
        $this->assertFalse($result['transaction_reference_is_local']);
    }

    public function testEachMintedDebitReferenceIsUnique(): void
    {
        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            $service = $this->makeSwapServiceWithDebitAdapter(['debited' => true]);
            $seen[] = $service->debitSource(['amount' => 1.00], 'TESTBANK')['transaction_reference'];
        }

        $this->assertCount(25, array_unique($seen), 'every issued reference must be unique');
    }
}

/**
 * Stands in for GenericInstitutionAdapter. InstitutionAdapterFactory picks
 * the class from the participant config's adapter_class and always calls
 * `new $class($bankClient, $logger, $institution, $participant)`, so pointing
 * a fake participant at this class substitutes it with no production change.
 */
class FakeDebitAdapter implements InstitutionAdapterInterface
{
    public static array $debitResponse = [];
    public static array $debitCalls = [];

    private string $institution;

    public function __construct($bankClient, $logger, string $institution, array $config)
    {
        $this->institution = $institution;
    }

    public function debit(array $payload, array $context): array
    {
        self::$debitCalls[] = $payload;
        return self::$debitResponse;
    }

    public function verifyAsset(array $payload, array $context): array { return ['verified' => false]; }
    public function placeHold(array $payload, array $context): array { return ['hold_placed' => false]; }
    public function credit(array $payload, array $context): array { return ['credited' => false]; }
    public function releaseHold(array $payload, array $context): array { return ['released' => false]; }
    public function generateCashoutToken(array $payload, array $context): array { return []; }
    public function verifyCashoutToken(array $payload, array $context): array { return []; }
    public function confirmCashout(array $payload, array $context): array { return ['confirmed' => false]; }
    public function verifyAccount(array $payload, array $context): array { return ['verified' => false]; }
    public function getBalance(array $payload, array $context): array { return []; }
    public function getTransactions(array $payload, array $context): array { return []; }
    public function checkSettlementStatus(array $payload, array $context): array { return []; }
    public function getAccounts(array $payload, array $context): array { return []; }
    public function createReservationAccount(array $payload, array $context): array { return ['success' => false]; }
    public function getReservationAccountStatus(array $payload, array $context): array { return []; }
    public function supports(string $capability): bool { return true; }
    public function getInstitution(): string { return $this->institution; }
}
