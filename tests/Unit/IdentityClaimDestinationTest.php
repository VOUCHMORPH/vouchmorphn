<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;
use Infrastructure\Adapters\GenericInstitutionAdapter;
use Infrastructure\Adapters\InstitutionAdapterFactory;
use Infrastructure\Adapters\InstitutionAdapterInterface;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * A claimed identity swap is paid into the destination the claimer chose, as
 * the kind of destination it is. The claim form used to send only a number,
 * claim_identity.php then called it an 'account', and the single-source payout
 * (SwapService::deliverDirectClaim() -> settlePosToMerchant()) dropped the
 * destination's asset type besides - so a claim into a wallet reached the bank
 * as an ACCOUNT deposit to an "account" numbered with the wallet's phone
 * number, and never arrived in the wallet.
 *
 * Covers SwapService::claimDepositDestination() (what the self-service claim
 * makes of the claimer's choice) and the credit the destination bank is sent.
 * No database: SwapService is built without its constructor, with the
 * participants the tests need and a bank adapter that records what it's asked.
 */
class IdentityClaimDestinationTest extends TestCase
{
    private const PARTICIPANTS = [
        'ZURUBANK' => [
            'name' => 'Zuru Bank',
            'asset_types' => ['ACCOUNT', 'VOUCHER', 'CARD'],
            'settlement_account' => ['BWP' => ['identifier' => 'IDENTITY-SETTLEMENT', 'identifier_type' => 'account_number']],
        ],
        'SACCUSSALIS' => [
            'name' => 'Saccussalis',
            'asset_types' => ['ACCOUNT', 'VOUCHER', 'WALLET', 'CARD'],
            'settlement_account' => ['BWP' => ['identifier' => '10000001', 'identifier_type' => 'account_number']],
        ],
        // Lists its kinds by alias.
        'KGALAGADI' => [
            'name' => 'Kgalagadi Bank',
            'asset_types' => ['SAVINGS-ACCOUNT', 'MNO-WALLET'],
        ],
    ];

    private SwapService $service;
    /** Every credit the destination bank was sent. */
    private array $credits = [];

    protected function setUp(): void
    {
        $this->service = (new ReflectionClass(SwapService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(SwapService::class, 'participants'))->setValue($this->service, self::PARTICIPANTS);

        $test = $this;
        $factory = new class($test) extends InstitutionAdapterFactory {
            public function __construct(private IdentityClaimDestinationTest $test) {}

            public function getAdapter(string $institution): InstitutionAdapterInterface
            {
                $test = $this->test;
                return new class($test) extends GenericInstitutionAdapter {
                    public function __construct(private IdentityClaimDestinationTest $test) {}

                    public function credit(array $payload, array $context): array
                    {
                        return $this->test->recordCredit($payload);
                    }
                };
            }
        };
        (new ReflectionProperty(SwapService::class, 'adapterFactory'))->setValue($this->service, $factory);
        $this->credits = [];
    }

    /** @internal called by the adapter stub */
    public function recordCredit(array $payload): array
    {
        $this->credits[] = $payload;
        return ['credited' => true, 'transaction_reference' => 'CR_' . count($this->credits)];
    }

    private function destination(string $institution, array $details): array
    {
        $method = new ReflectionMethod(SwapService::class, 'claimDepositDestination');
        return $method->invoke($this->service, $institution, $details);
    }

    private function deliver(array $heldByInstitution, string $institution, array $details): array
    {
        $method = new ReflectionMethod(SwapService::class, 'deliverDirectClaim');
        return $method->invoke($this->service, $heldByInstitution, 95.0, $institution, 'DEPOSIT', $details, 'BWP', null, 'CONSOL_TEST');
    }

    // ------------------------------------------------------------
    // What the claimer chose
    // ------------------------------------------------------------

    public function testAWalletIsDescribedAsAWallet(): void
    {
        $details = $this->destination('SACCUSSALIS', ['destination_identifier' => '71234567', 'destination_asset_type' => 'WALLET']);

        $this->assertSame('WALLET', $details['destination_asset_type']);
        $this->assertSame('phone', $details['destination_identifier_type']);
    }

    public function testAnAccountIsDescribedAsAnAccount(): void
    {
        $details = $this->destination('ZURUBANK', ['destination_identifier' => '1002003', 'destination_asset_type' => 'ACCOUNT', 'destination_identifier_type' => 'account_number']);

        $this->assertSame('ACCOUNT', $details['destination_asset_type']);
        $this->assertSame('account_number', $details['destination_identifier_type']);
    }

    public function testAClientThatSendsOnlyANumberStillGetsAnAccount(): void
    {
        // What every claim was before the form said which kind it is.
        $details = $this->destination('ZURUBANK', ['destination_identifier' => '1002003', 'destination_identifier_type' => 'account']);

        $this->assertSame('ACCOUNT', $details['destination_asset_type']);
        $this->assertSame('account_number', $details['destination_identifier_type']);
    }

    public function testAPhoneNumberWithoutAKindIsAWallet(): void
    {
        $details = $this->destination('SACCUSSALIS', ['destination_identifier' => '71234567', 'destination_identifier_type' => 'phone']);

        $this->assertSame('WALLET', $details['destination_asset_type']);
        $this->assertSame('phone', $details['destination_identifier_type']);
    }

    public function testWalletAliasesAreWallets(): void
    {
        $details = $this->destination('SACCUSSALIS', ['destination_identifier' => '71234567', 'destination_asset_type' => 'BANK-WALLET']);

        $this->assertSame('WALLET', $details['destination_asset_type']);
    }

    public function testKindsAnInstitutionListsByAliasAreOffered(): void
    {
        $this->assertSame('ACCOUNT', $this->destination('KGALAGADI', ['destination_identifier' => '1', 'destination_asset_type' => 'ACCOUNT'])['destination_asset_type']);
        $this->assertSame('WALLET', $this->destination('KGALAGADI', ['destination_identifier' => '2', 'destination_asset_type' => 'WALLET'])['destination_asset_type']);
    }

    public function testAWalletAtABankWithoutWalletsIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Zuru Bank doesn't offer wallets");

        $this->destination('ZURUBANK', ['destination_identifier' => '71234567', 'destination_asset_type' => 'WALLET']);
    }

    public function testOnlyAnAccountOrAWalletCanReceiveAClaim(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deposited into an account or a wallet');

        $this->destination('SACCUSSALIS', ['destination_identifier' => '4111111111111111', 'destination_asset_type' => 'CARD']);
    }

    // ------------------------------------------------------------
    // What the destination bank is sent
    // ------------------------------------------------------------

    public function testASingleSourceClaimIsPaidIntoTheChosenWallet(): void
    {
        $details = $this->destination('SACCUSSALIS', ['destination_identifier' => '71234567', 'destination_asset_type' => 'WALLET']);

        $delivery = $this->deliver(['ZURUBANK' => 100.0], 'SACCUSSALIS', $details);

        $this->assertSame(['ZURUBANK' => 95.0], $delivery['legs']);
        $this->assertCount(1, $this->credits);
        $credit = $this->credits[0];
        $this->assertSame('WALLET', $credit['destination_asset_type'], 'the single-source payout used to send every claim as an ACCOUNT');
        $this->assertSame('71234567', $credit['destination_identifier']);
        $this->assertSame('71234567', $credit['wallet_phone']);
        $this->assertArrayNotHasKey('account_number', $credit);
        $this->assertSame('SACCUSSALIS', $credit['destination_institution']);
        $this->assertEquals(95.0, $credit['amount']);
    }

    public function testASingleSourceClaimKeepsTheWalletKindWhateverItsIdentifierType(): void
    {
        // Only the asset type says "wallet" here: the payout has to pass it on.
        $details = $this->destination('SACCUSSALIS', ['destination_identifier' => 'W-778812', 'destination_asset_type' => 'WALLET', 'destination_identifier_type' => 'wallet_id']);
        $this->assertSame('wallet_id', $details['destination_identifier_type']);

        $this->deliver(['ZURUBANK' => 100.0], 'SACCUSSALIS', $details);

        $this->assertSame('WALLET', $this->credits[0]['destination_asset_type']);
        $this->assertSame('W-778812', $this->credits[0]['wallet_phone']);
    }

    public function testASingleSourceClaimIsPaidIntoTheChosenAccount(): void
    {
        $details = $this->destination('ZURUBANK', ['destination_identifier' => '1002003', 'destination_asset_type' => 'ACCOUNT']);

        $this->deliver(['SACCUSSALIS' => 100.0], 'ZURUBANK', $details);

        $credit = $this->credits[0];
        $this->assertSame('ACCOUNT', $credit['destination_asset_type']);
        $this->assertSame('1002003', $credit['account_number']);
        $this->assertSame('ZURUBANK', $credit['destination_institution']);
        $this->assertSame('10000001', $credit['source_identifier'], 'paid from the source institution\'s settlement account');
    }

    public function testAPooledClaimIsPaidIntoTheChosenWalletToo(): void
    {
        $details = $this->destination('SACCUSSALIS', ['destination_identifier' => '71234567', 'destination_asset_type' => 'WALLET']);

        $delivery = $this->deliver(['ZURUBANK' => 60.0, 'SACCUSSALIS' => 40.0], 'SACCUSSALIS', $details);

        $this->assertEqualsWithDelta(95.0, array_sum($delivery['legs']), 0.001);
        $this->assertCount(1, $this->credits, 'one deposit for what the claimer asked for');
        $this->assertSame('WALLET', $this->credits[0]['destination_asset_type']);
        $this->assertSame('71234567', $this->credits[0]['wallet_phone']);
    }
}
