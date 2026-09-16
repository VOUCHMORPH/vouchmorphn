<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Locks in SwapService::splitNetPayoutByInstitution(), the arithmetic behind
 * the collapsed identity-claim payout path (executeIdentityClaimDirect()).
 *
 * This is the one place in that path where money can silently go missing: a
 * pooled claim is debited out of several institutions' settlement accounts
 * and paid to the beneficiary as one credit per institution, so if the legs
 * don't sum to exactly the net payout, the difference is either stranded in
 * a settlement account or credited out of thin air.
 */
class DirectClaimPayoutSplitTest extends TestCase
{
    private function split(array $debitedByInstitution, float $netPayoutAmount): array
    {
        $method = new \ReflectionMethod(SwapService::class, 'splitNetPayoutByInstitution');
        $method->setAccessible(true);
        return $method->invoke(null, $debitedByInstitution, $netPayoutAmount);
    }

    public function testSingleInstitutionTakesTheWholeNetPayout(): void
    {
        $result = $this->split(['ZURUBANK' => 500.00], 497.50);

        $this->assertSame(['ZURUBANK' => 497.50], $result);
    }

    public function testEvenSplitAcrossTwoInstitutions(): void
    {
        $result = $this->split(['ZURUBANK' => 250.00, 'SACCUSSALIS' => 250.00], 400.00);

        $this->assertSame(['ZURUBANK' => 200.00, 'SACCUSSALIS' => 200.00], $result);
    }

    public function testSplitIsProportionalToWhatEachInstitutionWasDebited(): void
    {
        $result = $this->split(['ZURUBANK' => 750.00, 'CAZACOM' => 250.00], 1000.00);

        $this->assertSame(['ZURUBANK' => 750.00, 'CAZACOM' => 250.00], $result);
    }

    /**
     * The case rounded percentages get wrong: 100/3 per leg is 33.33, which
     * sums to 99.99 and strands a cent. The last leg must absorb it.
     */
    public function testRoundingResidualIsAbsorbedSoLegsSumExactly(): void
    {
        $debited = ['ZURUBANK' => 100.00, 'SACCUSSALIS' => 100.00, 'CAZACOM' => 100.00];
        $result = $this->split($debited, 100.00);

        $this->assertSame(100.00, round(array_sum($result), 2), 'legs must sum to the net payout exactly');
        $this->assertSame(33.33, $result['ZURUBANK']);
        $this->assertSame(33.33, $result['SACCUSSALIS']);
        $this->assertSame(33.34, $result['CAZACOM'], 'final leg absorbs the residual cent');
    }

    public function testLegsAlwaysSumToNetPayoutAcrossAwkwardAmounts(): void
    {
        $cases = [
            [['A' => 33.33, 'B' => 66.67], 87.11],
            [['A' => 1.00, 'B' => 2.00, 'C' => 97.00], 99.99],
            [['A' => 0.01, 'B' => 0.01, 'C' => 0.01], 0.03],
            [['A' => 1234.56, 'B' => 7.89], 1000.01],
        ];

        foreach ($cases as [$debited, $net]) {
            $result = $this->split($debited, $net);
            $this->assertSame(
                $net,
                round(array_sum($result), 2),
                'legs must sum to ' . $net . ' for ' . json_encode($debited)
            );
        }
    }

    /**
     * A leg too small to round up to a cent must be dropped, not sent as a
     * zero-value credit -- and dropping it must not lose the money, which
     * the residual rule hands to the final leg.
     */
    public function testSubCentLegIsDroppedWithoutLosingMoney(): void
    {
        $result = $this->split(['TINY' => 0.001, 'ZURUBANK' => 10000.00], 100.00);

        $this->assertArrayNotHasKey('TINY', $result);
        $this->assertSame(100.00, round(array_sum($result), 2));
    }

    public function testNothingDebitedYieldsNoLegs(): void
    {
        $this->assertSame([], $this->split([], 100.00));
        $this->assertSame([], $this->split(['ZURUBANK' => 0.0], 100.00));
    }
}
