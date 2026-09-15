<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SettlementRecoveryException;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Increment 6 of the swap-to-identity algorithm v2 build-out.
 * SettlementRecoveryException::$wasCompensated is what lets
 * finalizeHoldToReceiving()'s catch block tell "retry+compensation
 * self-healed this, no manual reconciliation needed" apart from "manual
 * reconciliation was already flagged inside retrySettlementOrCompensate()"
 * -- the full retry/compensation flow itself needs the FakeInstitutionAdapter
 * harness extension tracked separately (adding credit()/debit() response
 * queues) to test end-to-end; this locks in the exception's own contract.
 */
class SettlementRecoveryExceptionTest extends TestCase
{
    public function testCarriesWasCompensatedTrueForSuccessfulCompensation(): void
    {
        $e = new SettlementRecoveryException('settled via compensation', true);
        $this->assertTrue($e->wasCompensated);
        $this->assertSame('settled via compensation', $e->getMessage());
    }

    public function testCarriesWasCompensatedFalseForFlaggedReconciliation(): void
    {
        $e = new SettlementRecoveryException('needs a human', false);
        $this->assertFalse($e->wasCompensated);
    }

    public function testIsARuntimeException(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new SettlementRecoveryException('x', true));
    }
}
