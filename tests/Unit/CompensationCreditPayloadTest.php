<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * The compensating credit is the only thing standing between a customer and
 * a debit that delivered nowhere. It has never worked.
 *
 * Neither GenericInstitutionAdapter::credit() nor
 * GenericBankClient::processDepositWithProof() derives destination_account or
 * account_number from destination_identifier -- they are forwarded only if
 * the caller includes them. settlePosDirect() and settleDirect() do. The
 * compensation payload did not, so every attempt came back
 * "destination_account and valid amount are required", the recovery raised
 * instead of recovering, and the hold went to manual reconciliation with the
 * money still out of the customer's account.
 *
 * Eight of the eleven stuck holds on production carry exactly that reason.
 */
class CompensationCreditPayloadTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(
        string $identifier = '1234567890',
        float $amount = 567.00,
        string $identifierType = 'account_number',
        string $assetType = 'ACCOUNT'
    ): array {
        $m = new \ReflectionMethod(SwapService::class, 'compensationCreditPayload');
        $m->setAccessible(true);

        return $m->invoke(null, 'SWAP_1789550159875_SETTLE_COMPENSATE', $amount, 'BWP', $identifier, $identifierType, $assetType, 'ZURUBANK');
    }

    public function testItCarriesTheTwoKeysTheBanksRejectedItFor(): void
    {
        $payload = $this->payload('1234567890');

        $this->assertSame('1234567890', $payload['destination_account'] ?? null);
        $this->assertSame('1234567890', $payload['account_number'] ?? null);
    }

    public function testTheDestinationKeysAllNameTheSameAccount(): void
    {
        // Three spellings of one account. If they ever disagree, money goes
        // somewhere nobody chose.
        $payload = $this->payload('9876543210');

        $this->assertSame('9876543210', $payload['destination_identifier']);
        $this->assertSame('9876543210', $payload['destination_account']);
        $this->assertSame('9876543210', $payload['account_number']);
    }

    public function testTheAmountSurvivesAsAPositiveNumber(): void
    {
        // "valid amount are required" was the other half of the bank's
        // complaint, so this is not merely incidental.
        $payload = $this->payload('1234567890', 567.00);

        $this->assertSame(567.00, $payload['amount']);
        $this->assertGreaterThan(0, $payload['amount']);
    }

    public function testItCreditsBackToTheSourceInstitutionNotTheDestination(): void
    {
        // Compensation reverses direction: the money goes home to the
        // institution that was debited. Sending it onward to the original
        // destination would repeat the failure that caused this.
        $payload = $this->payload();

        $this->assertSame('ZURUBANK', $payload['to_institution']);
        $this->assertSame('ZURUBANK', $payload['destination_institution']);
    }

    public function testTheReferenceMarksItAsCompensationForTraceability(): void
    {
        $payload = $this->payload();

        $this->assertStringEndsWith('_COMPENSATE', $payload['reference']);
    }

    public function testIdentifierTypeAndAssetTypeArePassedThroughNotAssumed(): void
    {
        // A wallet source is compensated as a wallet, not silently as a
        // bank account.
        $payload = $this->payload('26771234567', 100.00, 'wallet_id', 'WALLET');

        $this->assertSame('wallet_id', $payload['destination_identifier_type']);
        $this->assertSame('WALLET', $payload['destination_asset_type']);
        $this->assertSame('26771234567', $payload['destination_account']);
    }

    /**
     * The shape this is supposed to mirror. settlePosDirect() is the credit
     * path the banks accept today, so anything it sends and this does not is
     * the next version of this bug.
     */
    public function testItMatchesTheDestinationKeysOfTheSettlementPayloadThatWorks(): void
    {
        $payload = $this->payload();

        foreach ([
            'reference', 'amount', 'currency',
            'destination_identifier', 'destination_identifier_type', 'destination_asset_type',
            'to_institution', 'destination_institution', 'action',
            'account_number', 'destination_account',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload, "compensation payload is missing {$key}");
        }

        $this->assertSame('PROCESS_DEPOSIT_WITH_PROOF', $payload['action']);
    }
}
