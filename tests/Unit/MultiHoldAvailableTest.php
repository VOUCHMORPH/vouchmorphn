<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Increment 7 of the swap-to-identity algorithm v2 build-out: locks in
 * SwapService::computeAvailableForPendingHolds(), which implements spec
 * invariant #9 ("the client is shown A, never T. They can never claim
 * Fh") -- shared by previewIdentityClaimAvailable() and the cash-now
 * validation in both finalizeAggregatedIdentityClaim() and
 * finalizeAggregatedIdentityClaimSelfService(), neither of which
 * correctly enforced this before (the agent path validated against
 * gross T; the self-service path didn't validate at all).
 */
class MultiHoldAvailableTest extends TestCase
{
    private function compute(array $holds): array
    {
        $method = new \ReflectionMethod(SwapService::class, 'computeAvailableForPendingHolds');
        $method->setAccessible(true);
        return $method->invoke(null, $holds);
    }

    private function hold(float $amount, float $fh): array
    {
        return [
            'amount' => $amount,
            'metadata' => json_encode(['hold_fee' => ['total_fee' => $fh]]),
        ];
    }

    public function testSingleHoldSubtractsItsOwnFh(): void
    {
        $result = $this->compute([$this->hold(500.00, 1.00)]);

        $this->assertSame(500.00, $result['gross']);
        $this->assertSame(1.00, $result['hold_fees']);
        $this->assertSame(499.00, $result['available']);
    }

    public function testMultipleHoldsSumFhAcrossThePool(): void
    {
        // Spec §8 2b: Fh = Fh1 + Fh2 + ... + Fhn -- each hold carries its
        // own hold-time fee, summed across the pool.
        $result = $this->compute([
            $this->hold(200.00, 1.00),
            $this->hold(300.00, 1.00),
            $this->hold(150.00, 1.00),
        ]);

        $this->assertSame(650.00, $result['gross']);
        $this->assertSame(3.00, $result['hold_fees']);
        $this->assertSame(647.00, $result['available']);
    }

    public function testWaivedHoldFeeIsNotSubtracted(): void
    {
        $result = $this->compute([[
            'amount' => 500.00,
            'metadata' => json_encode(['hold_fee' => ['total_fee' => 1.00, 'waived' => true]]),
        ]]);

        $this->assertSame(0.00, $result['hold_fees']);
        $this->assertSame(500.00, $result['available']);
    }

    public function testEmptyPoolIsAllZero(): void
    {
        $result = $this->compute([]);
        $this->assertSame(0.0, $result['gross']);
        $this->assertSame(0.0, $result['hold_fees']);
        $this->assertSame(0.0, $result['available']);
    }
}
