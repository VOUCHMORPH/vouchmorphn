<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\FeeService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Increment 1 of the swap-to-identity algorithm v2 build-out: locks in the
 * fees.json changes made for the two-moment fee model (Fh at hold placement,
 * Fd at delivery) against the real Botswana config, not a fixture copy --
 * a future edit to fees.json that breaks these numbers should fail here.
 */
class FeeServiceTest extends TestCase
{
    private function makeFeeService(): FeeService
    {
        $feesJsonPath = __DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json';
        $feesConfig = json_decode(file_get_contents($feesJsonPath), true);

        return new FeeService($feesConfig, [], 'BWP', null);
    }

    public function testIdentityHoldFhIsFlatOnePulaRegardlessOfAmount(): void
    {
        $feeService = $this->makeFeeService();

        foreach ([50.00, 500.00, 6999.00] as $amount) {
            $result = $feeService->calculateFees('IDENTITY_HOLD', $amount, ['currency' => 'BWP']);

            $this->assertSame(1.00, $result['total_fee'], "Fh must be flat 1.00 regardless of T={$amount}");
            $this->assertSame(1.00, $result['swap_levy']);
            $this->assertSame(
                round($amount - 1.00, 2),
                $result['net_amount_source_currency'],
                'A = T - Fh must fall out of net_amount_source_currency for IDENTITY_HOLD'
            );
        }
    }

    public function testIdentityHoldDistributesEntirelyToPlatform(): void
    {
        $feeService = $this->makeFeeService();
        $result = $feeService->calculateFees('IDENTITY_HOLD', 500.00, ['currency' => 'BWP']);

        // F1 - F7 = 0 for this product: Fh is 100% VouchMorph's levy at hold
        // time. Source/destination institutions earn their share later, at
        // delivery, from CASHOUT/DEPOSIT's own distribution -- not here.
        $this->assertSame(0.0, $result['distribution']['net_distributable_pool']);
        $this->assertSame(0.0, $result['distribution']['source_institution']['amount']);
        $this->assertSame(0.0, $result['distribution']['destination_institution']['amount']);
    }

    public function testCashoutTotalsUnchangedByFhSplit(): void
    {
        $feeService = $this->makeFeeService();
        $result = $feeService->calculateFees('CASHOUT', 500.00, ['currency' => 'BWP']);

        $this->assertSame(10.00, $result['total_fee'], 'CASHOUT flat total stays 10 Pula');
        $this->assertSame(1.00, $result['swap_levy']);
        $this->assertSame(9.00, $result['distribution']['net_distributable_pool']);
        $this->assertSame(3.15, $result['distribution']['platform']['amount']);
        // Section 23 Rev. 2 (fees.json, 2026-09-21): the source's former 15%
        // is now 13% to the source plus a 2% settlement fee.
        $this->assertSame(1.17, $result['distribution']['source_institution']['amount']);
        $this->assertSame(0.18, $result['distribution']['settlement']['amount']);
        $this->assertSame(4.50, $result['distribution']['destination_institution']['amount']);
    }

    public function testDepositTotalsUnchangedByFhSplit(): void
    {
        $feeService = $this->makeFeeService();
        $result = $feeService->calculateFees('DEPOSIT', 500.00, ['currency' => 'BWP']);

        $this->assertSame(6.00, $result['total_fee'], 'DEPOSIT flat total stays 6 Pula');
        $this->assertSame(1.00, $result['swap_levy']);
        $this->assertSame(5.00, $result['distribution']['net_distributable_pool']);
        $this->assertSame(1.75, $result['distribution']['platform']['amount']);
        $this->assertSame(0.65, $result['distribution']['source_institution']['amount']);
        $this->assertSame(0.10, $result['distribution']['settlement']['amount']);
        $this->assertSame(2.50, $result['distribution']['destination_institution']['amount']);
    }

    public function testCashoutDestinationShareSplitsBetweenCodeIssueAndCashDispensed(): void
    {
        $feeService = $this->makeFeeService();
        $result = $feeService->calculateFees('CASHOUT', 500.00, ['currency' => 'BWP']);

        // Section 23 Rev. 2 (fees.json, 2026-09-21) replaced the 2026-09-15
        // 0/100 setting: 10% of the destination's share is earned when the
        // redemption code is issued, 90% when the cash is dispensed.
        $destSplit = $result['destination_split'];
        $this->assertNotNull($destSplit, 'CASHOUT must still carry a destination_split block');
        $this->assertSame(10, $destSplit['generate_code_fee_percent']);
        $this->assertSame(0.45, $destSplit['generate_code_fee']);
        $this->assertSame(90, $destSplit['cashout_fee_percent']);
        $this->assertSame(4.05, $destSplit['cashout_completion_fee']);
        $this->assertSame(
            $destSplit['base_share'],
            round($destSplit['generate_code_fee'] + $destSplit['cashout_completion_fee'], 2),
            'The two portions together are the whole destination institution share'
        );
    }

    public function testInvariantNPlusFdPlusRPlusFhEqualsTAcrossHoldAndDeposit(): void
    {
        // Spec invariant #1: N + Fd + R + Fh == T at every claim.
        // Simulated here at the fee-calculation level: Fh comes from the
        // IDENTITY_HOLD product, Fd/N come from DEPOSIT applied to the
        // post-Fh available amount A, with R as whatever's left unclaimed.
        $feeService = $this->makeFeeService();
        $t = 500.00;

        $holdResult = $feeService->calculateFees('IDENTITY_HOLD', $t, ['currency' => 'BWP']);
        $fh = $holdResult['total_fee'];
        $a = round($t - $fh, 2);

        $claimNow = 300.00; // C < A -- partial claim
        $r = round($a - $claimNow, 2);

        $depositResult = $feeService->calculateFees('DEPOSIT', $claimNow, ['currency' => 'BWP']);
        $fd = $depositResult['total_fee'];
        $n = round($claimNow - $fd, 2);

        $this->assertSame($t, round($n + $fd + $r + $fh, 2));
    }

    /**
     * The multi_source schedule in fees.json ("extra P1 per additional source
     * beyond the first, capped at P15") has to reach what the customer is
     * actually charged. It used to be written to its own slot that nothing
     * downstream reads, so an N-source swap was billed exactly like a
     * single-source one.
     */
    public function testExtraSourceFeeIsChargedPerAdditionalSource(): void
    {
        $feeService = $this->makeFeeService();
        $sources = fn(int $n) => array_fill(0, $n, ['institution' => 'ZURUBANK', 'amount' => 100.00]);

        $single = $feeService->calculateFees('DEPOSIT', 500.00, [
            'currency' => 'BWP',
            'sources' => $sources(1),
            'is_multi_source' => false,
        ])['total_fee'];

        foreach ([2 => 1.00, 3 => 2.00, 5 => 4.00] as $count => $expectedExtra) {
            $result = $feeService->calculateFees('DEPOSIT', 500.00, [
                'currency' => 'BWP',
                'sources' => $sources($count),
                'is_multi_source' => true,
            ]);

            $this->assertSame(
                round($single + $expectedExtra, 2),
                round($result['total_fee'], 2),
                "{$count} sources must cost P{$expectedExtra} more than one"
            );
            $this->assertSame(
                round(500.00 - $result['total_fee'], 2),
                round($result['net_amount_source_currency'], 2),
                'the extra must come out of the delivered amount, not vanish'
            );
        }
    }

    public function testExtraSourceFeeIsCappedAtTheConfiguredMaximum(): void
    {
        $feeService = $this->makeFeeService();

        $base = $feeService->calculateFees('DEPOSIT', 500.00, ['currency' => 'BWP'])['total_fee'];

        foreach ([16, 20, 200] as $count) {
            $result = $feeService->calculateFees('DEPOSIT', 500.00, [
                'currency' => 'BWP',
                'sources' => array_fill(0, $count, ['institution' => 'ZURUBANK', 'amount' => 1.00]),
                'is_multi_source' => true,
            ]);

            $this->assertSame(
                round($base + 15.00, 2),
                round($result['total_fee'], 2),
                "the extra-source charge must stop at P15 however many sources ({$count}) there are"
            );
        }
    }

    /**
     * A single-source swap must be completely unaffected by the multi-source
     * branch, whether or not the caller passes the fields at all.
     */
    public function testSingleSourceFeeIsUnchanged(): void
    {
        $feeService = $this->makeFeeService();

        $unaware = $feeService->calculateFees('DEPOSIT', 500.00, ['currency' => 'BWP'])['total_fee'];
        $explicit = $feeService->calculateFees('DEPOSIT', 500.00, [
            'currency' => 'BWP',
            'sources' => [['institution' => 'ZURUBANK', 'amount' => 500.00]],
            'is_multi_source' => false,
        ])['total_fee'];

        $this->assertSame($unaware, $explicit);
    }
}
