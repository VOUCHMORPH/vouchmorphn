<?php

declare(strict_types=1);

use Domain\Services\TransactionAuditTrailService;
use PHPUnit\Framework\TestCase;

class TransactionAuditTrailServiceTest extends TestCase
{
    private PDO $db;
    private TransactionAuditTrailService $trail;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE transaction_audit_trail (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_reference TEXT NOT NULL,
                transaction_type TEXT NOT NULL DEFAULT "TRANSFER",
                origin TEXT NOT NULL,
                sender_name TEXT NOT NULL,
                sender_account TEXT,
                sender_institution TEXT,
                receiver_name TEXT NOT NULL,
                receiver_account TEXT,
                receiver_institution TEXT,
                amount NUMERIC NOT NULL,
                currency_code TEXT NOT NULL DEFAULT "BWP",
                status TEXT NOT NULL DEFAULT "STARTED",
                started_at TEXT NOT NULL,
                ended_at TEXT,
                duration_ms INTEGER,
                metadata TEXT,
                ip_address TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');

        $this->trail = new TransactionAuditTrailService($this->db);
    }

    public function testBeginTransactionRejectsMissingFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->trail->beginTransaction(['transaction_reference' => 'TXN-1']);
    }

    public function testBeginTransactionRecordsSenderReceiverAmountAndStartTimestamp(): void
    {
        $trailId = $this->trail->beginTransaction([
            'transaction_reference' => 'TXN-100',
            'transaction_type'      => 'SWAP',
            'origin'                => 'USSD',
            'sender_name'           => 'Alice Moyo',
            'sender_account'        => 'ACC-SENDER-1',
            'receiver_name'         => 'Bob Khama',
            'receiver_account'      => 'ACC-RECEIVER-1',
            'amount'                => 250.50,
            'currency_code'         => 'bwp',
        ]);

        $this->assertIsInt($trailId);

        $row = $this->trail->getTrail($trailId);

        $this->assertNotNull($row);
        $this->assertSame('TXN-100', $row['transaction_reference']);
        $this->assertSame('Alice Moyo', $row['sender_name']);
        $this->assertSame('Bob Khama', $row['receiver_name']);
        $this->assertEquals(250.50, $row['amount']);
        $this->assertSame('BWP', $row['currency_code']);
        $this->assertSame('STARTED', $row['status']);
        $this->assertNotEmpty($row['started_at']);
        $this->assertNotEmpty($row['created_at']);
        $this->assertNull($row['ended_at']);
    }

    public function testCompleteTransactionSetsEndTimestampAndDuration(): void
    {
        $trailId = $this->trail->beginTransaction([
            'transaction_reference' => 'TXN-200',
            'origin'                => 'MOBILE_APP',
            'sender_name'           => 'Alice Moyo',
            'receiver_name'         => 'Bob Khama',
            'amount'                => 100,
            'currency_code'         => 'BWP',
        ]);

        usleep(2000);

        $result = $this->trail->completeTransaction($trailId, 'completed');
        $this->assertTrue($result);

        $row = $this->trail->getTrail($trailId);
        $this->assertSame('COMPLETED', $row['status']);
        $this->assertNotEmpty($row['ended_at']);
        $this->assertGreaterThanOrEqual(0, (int)$row['duration_ms']);
    }

    public function testFailTransactionRecordsReasonInMetadata(): void
    {
        $trailId = $this->trail->beginTransaction([
            'transaction_reference' => 'TXN-300',
            'origin'                => 'API',
            'sender_name'           => 'Alice Moyo',
            'receiver_name'         => 'Bob Khama',
            'amount'                => 75,
            'currency_code'         => 'BWP',
        ]);

        $this->trail->failTransaction($trailId, 'Insufficient funds');

        $row = $this->trail->getTrail($trailId);
        $this->assertSame('FAILED', $row['status']);
        $metadata = json_decode($row['metadata'], true);
        $this->assertSame('Insufficient funds', $metadata['failure_reason']);
    }

    public function testSearchFiltersBySenderAccountAndStatus(): void
    {
        $t1 = $this->trail->beginTransaction([
            'transaction_reference' => 'TXN-400',
            'origin'                => 'USSD',
            'sender_name'           => 'Alice Moyo',
            'sender_account'        => 'ACC-1',
            'receiver_name'         => 'Bob Khama',
            'amount'                => 10,
            'currency_code'         => 'BWP',
        ]);
        $this->trail->completeTransaction($t1, 'COMPLETED');

        $this->trail->beginTransaction([
            'transaction_reference' => 'TXN-401',
            'origin'                => 'USSD',
            'sender_name'           => 'Carol Seretse',
            'sender_account'        => 'ACC-2',
            'receiver_name'         => 'Bob Khama',
            'amount'                => 20,
            'currency_code'         => 'BWP',
        ]);

        $results = $this->trail->search(['sender_account' => 'ACC-1', 'status' => 'COMPLETED']);

        $this->assertCount(1, $results);
        $this->assertSame('TXN-400', $results[0]['transaction_reference']);
    }

    public function testGetHistoryForReferenceReturnsAllEntriesForThatTransaction(): void
    {
        $this->trail->recordCompletedTransaction([
            'transaction_reference' => 'TXN-500',
            'origin'                => 'API',
            'sender_name'           => 'Alice Moyo',
            'receiver_name'         => 'Bob Khama',
            'amount'                => 500,
            'currency_code'         => 'BWP',
        ]);

        $history = $this->trail->getHistoryForReference('TXN-500');

        $this->assertCount(1, $history);
        $this->assertSame('COMPLETED', $history[0]['status']);
    }
}
