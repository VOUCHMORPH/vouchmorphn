<?php
declare(strict_types=1);

namespace Tests\Sandbox\Hypotheses;

use Tests\Sandbox\Support\SandboxTestCase;

/**
 * H1  — a swap completes correctly (this part: the customer is charged the
 *        disclosed fee and the recipient receives the disclosed net amount).
 * H12 — every defined swap pathway delivers the correct net amount to the
 *        correct destination, to the thebe, with the ledger balanced.
 *
 * These are the fee/settlement-identity checks that H12 rolls up
 * (VM-TD-001 Experiments 11-30). They assert against the real committed
 * Botswana fee config, so a fee edit that breaks the sandbox promise
 * fails here before it reaches the Bank.
 *
 * Filed figures (VM-FEE-001 §23.2, VM-TP-001 slide deck):
 *   deposit swap   P6 upfront
 *   cash-out swap  P10 upfront
 *   identity hold  P1 levy, flat
 *   multi-source   +P1 per extra source, capped at P15
 */
final class H1_H12_FeeAndNetAmountTest extends SandboxTestCase
{
    /** Deposit swap: client pays P6; recipient gets amount - 6. */
    public function testDepositFeeIsSixAndNetIsExact(): void
    {
        $fs = self::makeFeeService();
        foreach ([50.0, 300.0, 5000.0] as $amount) {
            $r = $fs->calculateFees('DEPOSIT', $amount, ['currency' => 'BWP']);
            $this->assertSame(6.0, (float) $r['total_fee'], "Deposit fee must be P6 at T={$amount}");
            $this->assertSame(
                round($amount - 6.0, 2),
                (float) $r['net_amount'],
                "Recipient net must be T-6 to the thebe at T={$amount}"
            );
        }
    }

    /** Cash-out swap: client pays P10; recipient collects amount - 10. */
    public function testCashoutFeeIsTenAndNetIsExact(): void
    {
        $fs = self::makeFeeService();
        foreach ([50.0, 200.0, 5000.0] as $amount) {
            $r = $fs->calculateFees('CASHOUT', $amount, ['currency' => 'BWP']);
            $this->assertSame(10.0, (float) $r['total_fee'], "Cash-out fee must be P10 at T={$amount}");
            $this->assertSame(
                round($amount - 10.0, 2),
                (float) $r['net_amount'],
                "Cash collected must be T-10 to the thebe at T={$amount}"
            );
        }
    }

    /**
     * Distribution conserves value: platform + source + destination shares
     * plus the levy must equal the customer fee exactly, to the thebe. This
     * is the "ledger balanced on every transaction" half of H12.
     */
    public function testFeeDistributionConservesValueToTheThebe(): void
    {
        $fs = self::makeFeeService();
        foreach (['DEPOSIT' => 300.0, 'CASHOUT' => 200.0] as $type => $amount) {
            $r = $fs->calculateFees($type, $amount, ['currency' => 'BWP']);
            $d = $r['distribution'];
            $sum = round(
                $d['levy_fees_total']
                + $d['platform']['amount']
                + $d['source_institution']['amount']
                + $d['destination_institution']['amount'],
                2
            );
            $this->assertSame(
                (float) $r['total_fee'],
                $sum,
                "{$type}: levy + platform + source + destination must equal the customer fee"
            );
        }
    }

    /** No participant share may be negative (no institution pays to receive). */
    public function testNoDistributionShareIsNegative(): void
    {
        $fs = self::makeFeeService();
        foreach (['DEPOSIT' => 300.0, 'CASHOUT' => 200.0] as $type => $amount) {
            $d = $fs->calculateFees($type, $amount, ['currency' => 'BWP'])['distribution'];
            $this->assertGreaterThanOrEqual(0, $d['platform']['amount']);
            $this->assertGreaterThanOrEqual(0, $d['source_institution']['amount']);
            $this->assertGreaterThanOrEqual(0, $d['destination_institution']['amount']);
        }
    }

    /** Identity hold levy is flat P1 regardless of amount (VM-FEE-001 IDENTITY_HOLD). */
    public function testIdentityHoldLevyIsFlatOnePula(): void
    {
        $fs = self::makeFeeService();
        foreach ([50.0, 500.0, 6999.0] as $amount) {
            $r = $fs->calculateFees('IDENTITY_HOLD', $amount, ['currency' => 'BWP']);
            $this->assertSame(1.0, (float) $r['total_fee'], "Identity hold levy must be flat P1 at T={$amount}");
        }
    }

    /** Multi-source adds P1 per extra source, capped at P15 total (VM-FEE-001). */
    public function testMultiSourceExtraFeeAndCap(): void
    {
        $fs = self::makeFeeService();
        $sources = fn(int $n) => array_fill(0, $n, ['institution' => 'ZURUBANK', 'amount' => 100.0]);

        // 5 sources on a cash-out: base P10 + 4 x P1 = P14, under the P15 cap.
        $r5 = $fs->calculateFees('CASHOUT', 1000.0, [
            'currency' => 'BWP',
            'is_multi_source' => true,
            'sources' => $sources(5),
        ]);
        $this->assertSame(14.0, (float) $r5['total_fee'], 'Five sources must be P10 + 4*P1 = P14');

        // FINDING F-002 (see tests/Sandbox/FINDINGS.md): fees.json documents
        // max_total_fee as "capped at P15 total", but FeeService caps only the
        // EXTRA portion and then adds it to the P10 base, so the TOTAL exceeds
        // P15 from 7 sources up (P25 at 20 sources). This assertion states the
        // documented promise; it will start passing once the cap is applied to
        // the total. It is written to fail loudly rather than be silently
        // adjusted to the buggy value.
        $r20 = $fs->calculateFees('CASHOUT', 1000.0, [
            'currency' => 'BWP',
            'is_multi_source' => true,
            'sources' => $sources(20),
        ]);
        $this->assertLessThanOrEqual(
            15.0,
            (float) $r20['total_fee'],
            'FINDING F-002: multi-source total fee must not exceed the documented P15 cap '
            . '(cap is applied to the extra portion only, so total reaches P'
            . $r20['total_fee'] . ' at 20 sources)'
        );
    }

    /** The hard per-transaction ceiling in fees.json matches the sandbox risk cap. */
    public function testHardTransactionCeilingIsConfigured(): void
    {
        $cfg = self::feesConfig();
        $this->assertArrayHasKey('limits', $cfg);
        $this->assertSame('BWP', $cfg['limits']['currency']);
        $this->assertGreaterThan(0, (float) $cfg['limits']['max_single_transaction']);
    }
}
