<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Increment 5 of the swap-to-identity algorithm v2 build-out: locks in
 * SwapService::computeNetObligationsByInstitution(), the pure calculation
 * behind Point X's obligation-ledger wiring into
 * executeIdentityClaimWithSplit(). Pure/stateless and static so it's
 * directly testable without the full SwapService dependency graph.
 */
class PointXObligationTest extends TestCase
{
    private function compute(array $holds, array $landedHoldIds): array
    {
        $method = new \ReflectionMethod(SwapService::class, 'computeNetObligationsByInstitution');
        $method->setAccessible(true);
        return $method->invoke(null, $holds, $landedHoldIds);
    }

    private function hold(int $id, string $institution, float $amount, float $fh): array
    {
        return [
            'hold_id' => $id,
            'source_institution' => $institution,
            'amount' => $amount,
            'metadata' => json_encode(['hold_fee' => ['total_fee' => $fh]]),
        ];
    }

    public function testSingleHoldNetsFhOutOfContribution(): void
    {
        $holds = [$this->hold(1, 'ZURUBANK', 500.00, 1.00)];
        $result = $this->compute($holds, [1]);

        $this->assertSame(['ZURUBANK' => 499.00], $result);
    }

    public function testMultipleHoldsSameInstitutionAreSummedNotSeparate(): void
    {
        // Spec §8 2h / 2g: Point X fans out ONCE per institution, not once
        // per hold -- two holds from the same source institution in one
        // pool must collapse into a single obligation entry.
        $holds = [
            $this->hold(1, 'ZURUBANK', 200.00, 1.00),
            $this->hold(2, 'ZURUBANK', 300.00, 1.00),
        ];
        $result = $this->compute($holds, [1, 2]);

        $this->assertSame(['ZURUBANK' => 498.00], $result);
    }

    public function testMultipleHoldsDifferentInstitutionsStayGroupedSeparately(): void
    {
        $holds = [
            $this->hold(1, 'ZURUBANK', 200.00, 1.00),
            $this->hold(2, 'SACCUSSALIS', 300.00, 1.00),
        ];
        $result = $this->compute($holds, [1, 2]);

        $this->assertSame(['ZURUBANK' => 199.00, 'SACCUSSALIS' => 299.00], $result);
    }

    public function testFailedHoldsAreExcludedEntirely(): void
    {
        // A hold that never landed (finalizeHoldToReceiving() threw) must
        // never generate an obligation -- there's nothing to net, no value
        // transferred to the destination for it.
        $holds = [
            $this->hold(1, 'ZURUBANK', 200.00, 1.00),
            $this->hold(2, 'SACCUSSALIS', 300.00, 1.00),
        ];
        $result = $this->compute($holds, [1]); // only hold 1 landed

        $this->assertSame(['ZURUBANK' => 199.00], $result);
        $this->assertArrayNotHasKey('SACCUSSALIS', $result);
    }

    public function testWaivedHoldFeeIsNotNettedOut(): void
    {
        $holds = [[
            'hold_id' => 1,
            'source_institution' => 'ZURUBANK',
            'amount' => 500.00,
            'metadata' => json_encode(['hold_fee' => ['total_fee' => 1.00, 'waived' => true]]),
        ]];
        $result = $this->compute($holds, [1]);

        $this->assertSame(['ZURUBANK' => 500.00], $result);
    }

    public function testEmptyLandedListProducesEmptyObligations(): void
    {
        $holds = [$this->hold(1, 'ZURUBANK', 500.00, 1.00)];
        $this->assertSame([], $this->compute($holds, []));
    }
}
