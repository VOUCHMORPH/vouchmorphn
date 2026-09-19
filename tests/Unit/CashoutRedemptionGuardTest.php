<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * A cashout code must stop being redeemable once the money behind it has
 * gone back to the customer.
 *
 * confirmCashout() used to refuse exactly one state, COMPLETED, and never
 * looked at code_expiry at all -- while its sibling lookup
 * getCashoutAuthorization() had been filtering on
 * `status IN ('PENDING','VERIFIED') AND code_expiry > NOW()` the whole
 * time. The money-moving path was the one without the guards.
 *
 * The asymmetry these tests pin down is the important part: refusing
 * before cash is dispensed is free, refusing after it is dispensed strands
 * money that is already in someone's hand.
 */
class CashoutRedemptionGuardTest extends TestCase
{
    private function callStatic(string $method, array $args)
    {
        $m = new \ReflectionMethod(SwapService::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(null, $args);
    }

    // ------------------------------------------------------------
    // "Has the hold already gone back to the customer?"
    // ------------------------------------------------------------

    public function testExpiredStatusMeansTheHoldIsGone(): void
    {
        $this->assertTrue($this->callStatic('cashoutHoldAlreadyReleased', [['status' => 'EXPIRED']]));
    }

    public function testAReleasedAtStampAloneIsEnough(): void
    {
        // released_at is the newer of the two columns; a deployment that
        // predates it must still get the right answer from the status, and
        // one that has it must not need the status to agree.
        $this->assertTrue($this->callStatic('cashoutHoldAlreadyReleased', [[
            'status' => 'DEBIT_FAILED',
            'released_at' => '2026-09-19 10:00:00',
        ]]));
    }

    public function testLiveAuthorizationsAreNotTreatedAsReleased(): void
    {
        foreach (['PENDING', 'VERIFIED', 'DEBIT_FAILED'] as $status) {
            $this->assertFalse(
                $this->callStatic('cashoutHoldAlreadyReleased', [['status' => $status]]),
                "{$status} is still redeemable"
            );
        }
    }

    public function testAnAbsentStatusKeyDoesNotCrashOrFalselyRefuse(): void
    {
        $this->assertFalse($this->callStatic('cashoutHoldAlreadyReleased', [[]]));
    }

    // ------------------------------------------------------------
    // "Is the code past its expiry?"
    // ------------------------------------------------------------

    public function testACodePastItsExpiryIsExpired(): void
    {
        $this->assertTrue($this->callStatic('cashoutCodeHasExpired', [
            ['code_expiry' => '2026-09-19 10:00:00'],
            strtotime('2026-09-19 10:00:01'),
        ]));
    }

    public function testACodeStillWithinItsExpiryIsNot(): void
    {
        $this->assertFalse($this->callStatic('cashoutCodeHasExpired', [
            ['code_expiry' => '2026-09-19 10:00:00'],
            strtotime('2026-09-19 09:59:59'),
        ]));
    }

    public function testAMissingExpiryIsNotTreatedAsExpired(): void
    {
        // code_expiry is nullable and comes from the destination's own
        // response. "We were never told" must not be read as "it lapsed",
        // or every institution that omits the field loses all its cashouts.
        foreach ([[], ['code_expiry' => null], ['code_expiry' => '']] as $auth) {
            $this->assertFalse($this->callStatic('cashoutCodeHasExpired', [$auth, time()]));
        }
    }

    public function testAnUnparseableExpiryIsNotTreatedAsExpired(): void
    {
        $this->assertFalse($this->callStatic('cashoutCodeHasExpired', [
            ['code_expiry' => 'not a date'],
            time(),
        ]));
    }
}
