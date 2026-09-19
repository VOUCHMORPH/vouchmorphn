<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * The policy question behind the hold-release callback.
 *
 * Phase D is explicit that GOVERNMENT and BUSINESS_OR_TRUST money is owed to
 * the identity and cannot be un-sent: on expiry it parks in a reservation
 * account at the source rather than going back to the sender. PERSONAL money
 * is a lapsed gift and does release back.
 *
 * So a bank reporting that it released a hold is routine for one of those
 * and a policy breach for the other, and recordBankHoldRelease() has to tell
 * them apart. This locks in the classification that decision rests on.
 */
class BankHoldReleaseTest extends TestCase
{
    private function owedToIdentity(?string $sourceAccountType): bool
    {
        $m = new \ReflectionMethod(SwapService::class, 'isSourceMoneyOwedToIdentity');
        $m->setAccessible(true);
        return $m->invoke(null, $sourceAccountType);
    }

    public function testGovernmentAndBusinessMoneyIsOwedToTheIdentity(): void
    {
        // A bank releasing one of these has handed back money the
        // beneficiary was owed, so the callback must flag it.
        $this->assertTrue($this->owedToIdentity('GOVERNMENT'));
        $this->assertTrue($this->owedToIdentity('BUSINESS_OR_TRUST'));
    }

    public function testPersonalMoneyIsNotOwedSoAReleaseIsRoutine(): void
    {
        $this->assertFalse($this->owedToIdentity('PERSONAL'));
    }

    /**
     * verifySourceAccountType() falls back to PERSONAL on any classification
     * failure, and an unclassified hold must not be treated as owed on the
     * strength of a guess.
     */
    public function testAnUnclassifiedSourceIsNotTreatedAsOwed(): void
    {
        foreach ([null, '', 'UNKNOWN', 'government', 'Business_Or_Trust'] as $value) {
            $this->assertFalse(
                $this->owedToIdentity($value),
                var_export($value, true) . ' must not count as owed to the identity'
            );
        }
    }

    public function testTheCallbackIsReachableAsAPublicEntryPoint(): void
    {
        $m = new \ReflectionMethod(SwapService::class, 'recordBankHoldRelease');

        $this->assertTrue($m->isPublic(), 'the callback handler has to be able to call it');

        $params = array_map(fn($p) => $p->getName(), $m->getParameters());
        $this->assertSame(['holdReference', 'institution', 'reason', 'releasedAt'], $params);

        // reason and released_at are optional in the payload the banks are
        // given, so they must be optional here too.
        $this->assertTrue($m->getParameters()[2]->isOptional());
        $this->assertTrue($m->getParameters()[3]->isOptional());
    }
}
