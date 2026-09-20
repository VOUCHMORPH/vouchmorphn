<?php
declare(strict_types=1);

namespace Tests\Sandbox\Hypotheses;

use Tests\Sandbox\Support\SandboxTestCase;
use Domain\Services\SwapService;

/**
 * H4 — value addressed to an identity is delivered only to a correctly
 *       verified person, and otherwise resolves under the finality rule of
 *       the Identity Reservation Protocol (VM-IRP-001):
 *         PERSONAL source     -> returned to sender on expiry, no fee;
 *         DISBURSEMENT source -> carried into the recipient's Reservation
 *                                Account, never reverted to the disburser.
 *
 * Two layers of evidence:
 *  1) classification is a pure function -> asserted without a DB;
 *  2) the finality data model exists and enforces single-identity accounts
 *     and the 'consumed' terminal state -> asserted against the schema.
 */
final class H4_IdentityFinalityTest extends SandboxTestCase
{
    private function classify(string $raw): string
    {
        $m = new \ReflectionMethod(SwapService::class, 'classifySourceAccountType');
        $m->setAccessible(true);
        return $m->invoke(null, $raw);
    }

    /** The disposition split is by source type, and disbursement != personal. */
    public function testDisbursementAndPersonalClassifyDifferently(): void
    {
        $this->assertSame('GOVERNMENT', $this->classify('GOVERNMENT'));
        $this->assertSame('BUSINESS_OR_TRUST', $this->classify('TRUST'));
        $this->assertSame('PERSONAL', $this->classify('savings'));
        // These three must never collapse to the same bucket, or the finality
        // rule cannot tell a grant (carry) from a gift (return).
        $buckets = array_unique([
            $this->classify('GOVERNMENT'),
            $this->classify('TRUST'),
            $this->classify('savings'),
        ]);
        $this->assertCount(3, $buckets, 'GOVERNMENT, TRUST and PERSONAL must be distinct dispositions');
    }

    /** identity_swap_holds records the source type finality depends on. */
    public function testHoldsCarrySourceAccountType(): void
    {
        $db = $this->requireDb();
        if (!$this->tableExists($db, 'identity_swap_holds')) {
            $this->markTestSkipped('identity_swap_holds not present in this schema');
        }
        $col = $db->query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name='identity_swap_holds' AND column_name='source_account_type'"
        )->fetchColumn();
        $this->assertTrue((bool) $col, 'identity_swap_holds must record source_account_type for finality');
    }

    /**
     * A Reservation Account is single-identity per institution per currency —
     * the UNIQUE(user_id, institution, currency) constraint is what stops one
     * person's carried value from pooling with another's (VM-IRP-001 §3).
     */
    public function testReservationAccountIsSingleIdentityPerInstitution(): void
    {
        $db = $this->requireDb();
        if (!$this->tableExists($db, 'reservation_accounts')) {
            $this->markTestSkipped('reservation_accounts not present in this schema');
        }
        $uniques = $db->query(
            "SELECT indexdef FROM pg_indexes
             WHERE tablename='reservation_accounts' AND indexdef ILIKE '%UNIQUE%'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $found = false;
        foreach ($uniques as $def) {
            $d = strtolower($def);
            if (str_contains($d, 'user_id') && str_contains($d, 'institution') && str_contains($d, 'currency')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'reservation_accounts needs UNIQUE(user_id, institution, currency)');
    }

    /**
     * The finality model has a terminal 'consumed' state so a carried position,
     * once executed once, can never be executed again (single execution point,
     * VM-IRP-001 §4 / VM-TD-001 Experiment 37).
     */
    public function testReservationAccountHasConsumedTerminalState(): void
    {
        $db = $this->requireDb();
        if (!$this->tableExists($db, 'reservation_accounts')) {
            $this->markTestSkipped('reservation_accounts not present in this schema');
        }
        $check = $db->query(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint
             WHERE conname LIKE 'reservation_accounts_status%'"
        )->fetchColumn();
        $this->assertNotFalse($check, 'reservation_accounts must constrain status');
        $this->assertStringContainsStringIgnoringCase('consumed', (string) $check,
            "status must include the 'consumed' terminal state for single-execution finality");
    }

    /**
     * Live-state safety: a hold that is disputed after payment must not be
     * auto-released, so value can never be paid twice. Proven here as a data
     * invariant — no active hold may share a swap_reference with a released one.
     */
    public function testNoHoldIsBothActiveAndReleasedForOneReference(): void
    {
        $db = $this->requireDb();
        if (!$this->tableExists($db, 'identity_swap_holds')) {
            $this->markTestSkipped('identity_swap_holds not present');
        }
        $dupes = $db->query(
            "SELECT swap_reference FROM identity_swap_holds
             GROUP BY swap_reference
             HAVING count(*) FILTER (WHERE status='active') > 0
                AND count(*) FILTER (WHERE status='released') > 0"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([], $dupes,
            'A swap_reference must never have both an active and a released hold (double-pay risk)');
    }
}
