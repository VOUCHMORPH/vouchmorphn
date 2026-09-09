<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Core\Tracing\TransactionAuditTrail;

class TransactionAuditTrailTest extends TestCase
{
    private function makeTrail(): TransactionAuditTrail
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return new TransactionAuditTrail($db);
    }

    public function testRecordCapturesWhoWhenWhereAmountAndCurrency(): void
    {
        $trail = $this->makeTrail();

        $uuid = $trail->record([
            'transaction_reference' => 'TXN-1001',
            'event_type'            => 'COMPLETED',
            'user_id'               => 42,
            'performed_by'          => 'jane.doe',
            'source_account'        => 'ACC-SENDER-1',
            'destination_account'   => 'ACC-RECEIVER-1',
            'beneficiary_name'      => 'John Beneficiary',
            'amount'                => '150.50',
            'currency'              => 'usd',
            'status'                => 'completed',
            'channel'               => 'api',
            'ip_address'            => '203.0.113.7',
            'geo_location'          => ['country' => 'BW', 'city' => 'Gaborone'],
        ]);

        $this->assertNotNull($uuid);

        $entries = $trail->getTrailForTransaction('TXN-1001');
        $this->assertCount(1, $entries);

        $entry = $entries[0];
        $this->assertSame('jane.doe', $entry['performed_by_identifier']);
        $this->assertSame(42, (int)$entry['performed_by_user_id']);
        $this->assertSame('ACC-SENDER-1', $entry['source_account']);
        $this->assertSame('ACC-RECEIVER-1', $entry['destination_account']);
        $this->assertSame('John Beneficiary', $entry['beneficiary_name']);
        $this->assertSame(150.5, (float)$entry['amount']);
        $this->assertSame('USD', $entry['currency_code']);
        $this->assertSame('COMPLETED', $entry['status']);
        $this->assertSame('BW', $entry['geo_location']['country']);
        $this->assertNotEmpty($entry['occurred_at']);
    }

    public function testRejectsMissingRequiredFields(): void
    {
        $trail = $this->makeTrail();

        $uuid = $trail->record([
            'source_account' => 'ACC-1',
            'amount'         => '10',
            'currency'       => 'USD',
            // transaction_reference missing
        ]);

        $this->assertNull($uuid);
    }

    public function testRejectsNonPositiveAmount(): void
    {
        $trail = $this->makeTrail();

        $uuid = $trail->record([
            'transaction_reference' => 'TXN-BAD',
            'source_account'        => 'ACC-1',
            'amount'                => '-5',
            'currency'              => 'USD',
        ]);

        $this->assertNull($uuid);
    }

    public function testChainLinksSequentialEntriesAndDetectsTampering(): void
    {
        $trail = $this->makeTrail();

        $trail->record([
            'transaction_reference' => 'TXN-A',
            'source_account'        => 'ACC-1',
            'destination_account'   => 'ACC-2',
            'amount'                => '10',
            'currency'              => 'USD',
        ]);
        $trail->record([
            'transaction_reference' => 'TXN-B',
            'source_account'        => 'ACC-3',
            'destination_account'   => 'ACC-4',
            'amount'                => '20',
            'currency'              => 'EUR',
        ]);

        $result = $trail->verifyChain();
        $this->assertTrue($result['valid']);
        $this->assertSame(2, $result['checked']);

        $entries = $trail->getTrailForTransaction('TXN-B');
        $this->assertSame($trail->getTrailForTransaction('TXN-A')[0]['entry_hash'], $entries[0]['prev_hash']);
    }

    public function testVerifyChainDetectsTampering(): void
    {
        $trail = $this->makeTrail();

        $trail->record([
            'transaction_reference' => 'TXN-TAMPER',
            'source_account'        => 'ACC-1',
            'destination_account'   => 'ACC-2',
            'amount'                => '10',
            'currency'              => 'USD',
        ]);

        $db = new ReflectionProperty($trail, 'db');
        $db->setAccessible(true);
        $db->getValue($trail)->exec("UPDATE transaction_audit_trail SET amount = 999");

        $result = $trail->verifyChain();
        $this->assertFalse($result['valid']);
        $this->assertSame(1, $result['broken_at']);
    }

    public function testSearchFiltersByCurrencyAndAccount(): void
    {
        $trail = $this->makeTrail();

        $trail->record([
            'transaction_reference' => 'TXN-USD',
            'source_account'        => 'ACC-USD-SENDER',
            'amount'                => '100',
            'currency'              => 'USD',
        ]);
        $trail->record([
            'transaction_reference' => 'TXN-EUR',
            'source_account'        => 'ACC-EUR-SENDER',
            'amount'                => '200',
            'currency'              => 'EUR',
        ]);

        $usdOnly = $trail->search(['currency' => 'usd']);
        $this->assertCount(1, $usdOnly);
        $this->assertSame('TXN-USD', $usdOnly[0]['transaction_reference']);

        $bySource = $trail->search(['source_account' => 'ACC-EUR-SENDER']);
        $this->assertCount(1, $bySource);
        $this->assertSame('TXN-EUR', $bySource[0]['transaction_reference']);
    }
}
