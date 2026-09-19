<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * A cashout code must always die before the hold funding it.
 *
 * If it outlives the hold, the hold lapses, the money goes back to the
 * customer, and the code is still presentable at an ATM against funds that
 * are no longer reserved. The bank honours its own expiry, so a code we
 * merely recorded as short is not enough -- what matters is that we ask for
 * a shorter one, and that we never record a longer one as if it were fine.
 */
class CashoutExpiryHierarchyTest extends TestCase
{
    private function call(string $method, array $args = [])
    {
        $class = new \ReflectionClass(SwapService::class);
        // No constructor: these are pure date helpers and building the real
        // dependency graph would need a live database. The logger is set by
        // hand because the clamp path legitimately logs, and the real
        // constructor always provides one.
        $instance = $class->newInstanceWithoutConstructor();
        $logger = $class->getProperty('logger');
        $logger->setAccessible(true);
        $logger->setValue($instance, new class {
            public array $errors = [];
            public function error($m, $c = []) { $this->errors[] = $m; }
            public function warning($m, $c = []) {}
            public function info($m, $c = []) {}
        });

        $m = new \ReflectionMethod(SwapService::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($instance, $args);
    }

    private function constant(string $name)
    {
        return (new \ReflectionClass(SwapService::class))->getConstant($name);
    }

    public function testRequestedCashoutExpiryIsStrictlyBeforeTheHoldExpiry(): void
    {
        $hold = strtotime($this->call('holdExpiry'));
        $cashout = strtotime($this->call('requestedCashoutExpiry'));

        $this->assertLessThan($hold, $cashout, 'the code we ask for must die before the hold does');
        $this->assertSame(
            $this->constant('CASHOUT_EXPIRY_SAFETY_MARGIN_HOURS') * 3600,
            $hold - $cashout,
            'the gap must be exactly the configured safety margin'
        );
    }

    public function testTheSafetyMarginIsActuallyPositive(): void
    {
        // A zero or negative margin would satisfy "T_cashout <= T_hold" while
        // leaving no window at all, which is the bug this guards against.
        $this->assertGreaterThan(0, $this->constant('CASHOUT_EXPIRY_SAFETY_MARGIN_HOURS'));
        $this->assertLessThan(
            $this->constant('HOLD_WINDOW_HOURS'),
            $this->constant('CASHOUT_EXPIRY_SAFETY_MARGIN_HOURS'),
            'the margin cannot be the whole window, or codes would expire before they are issued'
        );
    }

    public function testABankExpiryShorterThanOursIsHonoured(): void
    {
        $bank = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $resolved = $this->call('resolveCashoutExpiry', [$bank, 'ZURUBANK']);

        $this->assertSame($bank, $resolved, 'a stricter bank is entitled to be stricter');
    }

    public function testABankExpiryThatOutlivesTheHoldIsClamped(): void
    {
        $resolved = strtotime($this->call('resolveCashoutExpiry', [
            date('Y-m-d H:i:s', strtotime('+7 days')),
            'ZURUBANK',
        ]));

        $this->assertLessThan(strtotime($this->call('holdExpiry')), $resolved);
        $this->assertEqualsWithDelta(strtotime($this->call('requestedCashoutExpiry')), $resolved, 2);
    }

    /**
     * The case that made this a bug in the first place: the bank returns
     * nothing, and the fallback used to be the SAME +24h as the hold.
     */
    public function testAMissingBankExpiryFallsBackBelowTheHoldNotLevelWithIt(): void
    {
        foreach ([null, ''] as $missing) {
            $resolved = strtotime($this->call('resolveCashoutExpiry', [$missing, 'ZURUBANK']));

            $this->assertLessThan(
                strtotime($this->call('holdExpiry')),
                $resolved,
                'the fallback must not land level with the hold expiry'
            );
        }
    }

    public function testAnUnparseableBankExpiryFallsBackSafely(): void
    {
        $resolved = strtotime($this->call('resolveCashoutExpiry', ['not a date', 'ZURUBANK']));

        $this->assertLessThan(strtotime($this->call('holdExpiry')), $resolved);
    }
}
