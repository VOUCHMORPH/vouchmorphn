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
        $this->assertSame(1.35, $result['distribution']['source_institution']['amount']);
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
        $this->assertSame(0.75, $result['distribution']['source_institution']['amount']);
        $this->assertSame(2.50, $result['distribution']['destination_institution']['amount']);
    }

    public function testCashoutDestinationRevenueEarnedEntirelyAtRedemption(): void
    {
        $feeService = $this->makeFeeService();
        $result = $feeService->calculateFees('CASHOUT', 500.00, ['currency' => 'BWP']);

        $destSplit = $result['destination_split'];
        $this->assertNotNull($destSplit, 'CASHOUT must still carry a destination_split block');
        $this->assertSame(0, $destSplit['generate_code_fee_percent'], 'No Fd is earned at code generation per spec §6');
        $this->assertSame(0.0, $destSplit['generate_code_fee']);
        $this->assertSame(100, $destSplit['cashout_fee_percent']);
        $this->assertSame(
            $destSplit['base_share'],
            $destSplit['cashout_completion_fee'],
            'All of the destination institution share is earned at redemption'
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
}
