<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

/**
 * Money must not be able to enter a hold that no claim can ever leave.
 *
 * Every delivery path out of a hold -- settlePosDirect(), settlePosViaSwitch(),
 * settleDirect(), settleViaSwitch() and generateCashoutFromSettlement() --
 * calls getSourceSettlementAccount() on the SOURCE institution, because the
 * credit instruction sent to the destination names that account as the
 * funding counterparty. An institution whose settlement_account is still an
 * onboarding placeholder therefore fails EVERY claim.
 *
 * It used to fail at STEP 2 of the claim, which is after the sender's money
 * is already held: funds locked up until expiry, with the claimant told to
 * contact ops. The destination side of this was already guarded (the claim
 * dropdown filters on identity_accounts), but a claimant does not choose the
 * source -- the sender does, at send time. So the check has to happen there.
 */
class IdentitySendSourceReadinessTest extends TestCase
{
    private function service(): SwapService
    {
        $class = new \ReflectionClass(SwapService::class);
        // No constructor: it needs a live database, and the check under test
        // reads nothing but participant config.
        $service = $class->newInstanceWithoutConstructor();

        $participants = $class->getProperty('participants');
        $participants->setAccessible(true);
        $participants->setValue($service, LoadCountry::getConfig()['participants'] ?? []);

        $logger = $class->getProperty('logger');
        $logger->setAccessible(true);
        $logger->setValue($service, new class {
            public function error($m, $c = []) {}
            public function warning($m, $c = []) {}
            public function info($m, $c = []) {}
        });

        return $service;
    }

    private function assertSource(string $institution, string $currency = 'BWP'): void
    {
        $m = new \ReflectionMethod(SwapService::class, 'assertSourceCanDeliverClaims');
        $m->setAccessible(true);
        $m->invoke($this->service(), $institution, $currency);
    }

    public function testAConfiguredSourceIsAllowedToSend(): void
    {
        $this->assertSource('ZURUBANK');
        // No exception is the assertion; make that explicit for the reader.
        $this->addToAssertionCount(1);
    }

    public function testASourceStillOnPlaceholdersIsRefusedAtSendTime(): void
    {
        $this->expectException(RuntimeException::class);
        // The wording matters: at send time nobody is claiming yet, so the
        // settlement-account phrasing written for claimants would confuse.
        $this->expectExceptionMessageMatches('/cannot yet be used to send to an identity/');

        $this->assertSource('SACCUSSALIS');
    }

    public function testTheRefusalNamesTheUnderlyingConfigProblem(): void
    {
        try {
            $this->assertSource('SACCUSSALIS');
            $this->fail('expected SACCUSSALIS to be refused');
        } catch (RuntimeException $e) {
            // Whoever reads this in a log needs to know what to fix, not
            // just that something is wrong.
            $this->assertStringContainsString('settlement_account.BWP.identifier', $e->getMessage());
            $this->assertStringContainsString('REPLACE_WITH_REAL', $e->getMessage());
        }
    }

    public function testTheRefusalSaysNoMoneyMoved(): void
    {
        // The claim-time failure already tells the user their money is safe.
        // The send-time one has to do the same, or it reads like a debit
        // that half-happened.
        try {
            $this->assertSource('SACCUSSALIS');
            $this->fail('expected SACCUSSALIS to be refused');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/[Nn]othing has been held/', $e->getMessage());
        }
    }

    public function testAnUnknownInstitutionIsRefusedRatherThanWavedThrough(): void
    {
        $this->expectException(RuntimeException::class);
        $this->assertSource('NOT_A_REAL_BANK');
    }
}
