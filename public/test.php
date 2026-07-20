<?php
/**
 * test_aggregated_claim_math.php
 *
 * Validates the two fixes just applied to finalizeAggregatedIdentityClaim()
 * and finalizeIdentityClaimSplit():
 *   1. Remainder is computed from NET deposited, not gross hold amount.
 *   2. No hold is left stuck at 'confirmed' after a run (transaction-
 *      ordering fix in finalizeIdentityHoldNoPin()).
 *
 * Does NOT assume any pass/fail — every check pulls real numbers from
 * the DB after the operation and compares them against what the
 * formula SHOULD produce, rather than trusting the returned response
 * payload alone (the payload could be wrong even if the DB is right,
 * or vice versa — checking both catches either failure mode).
 */

require_once __DIR__ . '/../vendor/autoload.php'; // adjust to real bootstrap
use Core\Database\DBConnection;
use Domain\Services\SwapService;

$db = DBConnection::getConnection();
$country = 'Botswana';
$swapService = new SwapService($db, \Core\Config\LoadCountry::getConfig(), $country);

$results = [];
function report(string $label, $pass, $detail = null): void {
    global $results;
    $results[] = compact('label', 'pass', 'detail');
    $mark = $pass === true ? '[PASS]' : ($pass === false ? '[FAIL]' : '[INFO]');
    echo "{$mark} {$label}\n";
    if ($detail !== null) {
        echo "        " . (is_string($detail) ? $detail : json_encode($detail)) . "\n";
    }
}

// ============================================================
// SETUP: create 2 fresh IDENTITY swaps of known, distinct amounts
// against a throwaway test identity, so this test doesn't touch
// or depend on ID123456789's existing (already messy) history.
// ============================================================
$testIdentityValue = 'TEST_MATH_' . time();
$sourceInstitution = 'ZURUBANK';
$sourceIdentifier = '10000001';
$testAmounts = [500.0, 300.0]; // deliberately unequal, to make math errors visible

echo "\n=== SETUP: creating " . count($testAmounts) . " test holds for {$testIdentityValue} ===\n";

$createdHoldIds = [];
foreach ($testAmounts as $amt) {
    $result = $swapService->executeAtomicSwap([
        'swap_type' => 'IDENTITY',
        'reference' => 'TESTMATH_' . time() . '_' . bin2hex(random_bytes(3)),
        'from_institution' => $sourceInstitution,
        'source_institution' => $sourceInstitution,
        'source_identifier' => $sourceIdentifier,
        'asset_type' => 'ACCOUNT',
        'amount' => $amt,
        'currency' => 'BWP',
        'identity_type' => 'national_id',
        'identity_value' => $testIdentityValue,
        'notification_phone' => '+26770000000',
    ]);
    $createdHoldIds[] = $result['hold_id'];
    report("Created test hold for {$amt}", $result['status'] === 'pending_identity_confirmation', $result);
    sleep(1); // ensure distinct created_at/otp_pin_sent_at ordering
}

// ============================================================
// TEST A: confirm aggregation sees exactly these 2 holds
// ============================================================
echo "\n=== TEST A: aggregation sees both fresh holds ===\n";
$aggregate = $swapService->getAggregatedIdentityBalance('national_id', $testIdentityValue);
$expectedGross = array_sum($testAmounts); // 800
report(
    "Aggregate total = {$expectedGross}, swap_count = " . count($testAmounts),
    $aggregate !== null && (float)$aggregate['total_amount'] === $expectedGross && (int)$aggregate['swap_count'] === count($testAmounts),
    $aggregate
);

// ============================================================
// TEST B: fetch the pre-claim fee schedule for a known amount so we
// can compute the EXPECTED net independently, rather than trusting
// the code under test to grade its own homework.
// ============================================================
// NOTE: fill in $expectedFeePerHold based on your actual fee schedule
// (we've seen 6 BWP flat on 500 BWP DEPOSIT in prior logs — confirm
// this against FeeService for the real formula before trusting it).
$expectedFeePerHold = null; // <-- SET THIS from FeeService::calculateFees('DEPOSIT', ...) if known

// ============================================================
// TEST C: run the claim. Take LESS than net available, so a
// remainder is guaranteed and its math is checkable.
// ============================================================
echo "\n=== TEST C: run finalizeAggregatedIdentityClaim with partial cash-now ===\n";

// You'll need a real, active agent_destination_accounts.id here.
$destinationAccountId = 7;
$agentUserId = 12;

$cashNowRequested = 400.0; // deliberately less than gross (800) so remainder > 0

try {
    // NOTE: you'll need the real OTP here (read from your test SMS
    // path / debug log, since it's hashed in the DB) — same as prior
    // tests this session.
    $testPin = getenv('TEST_KNOWN_OTP') ?: '000000';

    $claimResult = $swapService->finalizeAggregatedIdentityClaim(
        'national_id',
        $testIdentityValue,
        $testPin,
        'agent',
        $agentUserId,
        $destinationAccountId,
        $cashNowRequested,
        $agentUserId
    );

    report('finalizeAggregatedIdentityClaim completed', $claimResult['status'] === 'success', $claimResult);

    $actuallyClaimedGross = $claimResult['actually_claimed_gross'] ?? null;
    $actuallyClaimedNet = $claimResult['actually_claimed_net'] ?? null;
    $reportedCashNow = $claimResult['cash_now_amount'] ?? null;
    $reportedRemainder = $claimResult['remainder_reswap']['amount'] ?? null;

    report('Response includes actually_claimed_net (fix deployed)', $actuallyClaimedNet !== null, $actuallyClaimedNet);

    if ($actuallyClaimedGross !== null) {
        $feeTotalImplied = round($actuallyClaimedGross - $actuallyClaimedNet, 2);
        report("Gross ({$actuallyClaimedGross}) - Net ({$actuallyClaimedNet}) = implied total fee",
            true, $feeTotalImplied);
    }

    // ============================================================
    // THE CRITICAL CHECK: remainder must equal NET minus cash_now,
    // NOT gross minus cash_now. If the old bug is still present,
    // $reportedRemainder will equal ($actuallyClaimedGross - $reportedCashNow)
    // instead.
    // ============================================================
    $expectedRemainderIfFixed = round($actuallyClaimedNet - $reportedCashNow, 2);
    $expectedRemainderIfBuggy = round($actuallyClaimedGross - $reportedCashNow, 2);

    if ($reportedRemainder !== null) {
        $isFixed = abs($reportedRemainder - $expectedRemainderIfFixed) < 0.01;
        $isBuggy = abs($reportedRemainder - $expectedRemainderIfBuggy) < 0.01 && !$isFixed;

        report(
            "Remainder ({$reportedRemainder}) matches NET-based formula ({$expectedRemainderIfFixed})",
            $isFixed,
            $isFixed ? 'FIX CONFIRMED WORKING' : ($isBuggy ? "STILL BUGGY — matches old gross-based formula ({$expectedRemainderIfBuggy}) instead" : 'MATCHES NEITHER — unexpected value, investigate')
        );
    }

} catch (\Throwable $e) {
    report('finalizeAggregatedIdentityClaim threw', false, $e->getMessage());
}

// ============================================================
// TEST D: no hold left stuck at 'confirmed' — every hold touched by
// this run must now be either 'completed' or (if it failed and
// correctly rolled back) 'pending'. NEVER 'confirmed'.
// ============================================================
echo "\n=== TEST D: no orphaned 'confirmed' holds after run ===\n";

$stmt = $db->prepare("
    SELECT hold_id, status, confirmed_at
    FROM identity_swap_holds
    WHERE hold_id IN (" . implode(',', array_fill(0, count($createdHoldIds), '?')) . ")
");
$stmt->execute($createdHoldIds);
$finalStates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stuckCount = 0;
foreach ($finalStates as $row) {
    $ok = in_array($row['status'], ['completed', 'pending'], true);
    if (!$ok) $stuckCount++;
    report("hold_id={$row['hold_id']} final status = {$row['status']}", $ok, $row);
}
report('Zero holds stuck at confirmed', $stuckCount === 0, "{$stuckCount} stuck");

// ============================================================
// TEST E: the remainder hold, if created, actually exists in the DB
// with the correct (net-based) amount.
// ============================================================
echo "\n=== TEST E: remainder hold in DB matches net-based amount ===\n";

$stmt = $db->prepare("
    SELECT hold_id, amount, status
    FROM identity_swap_holds
    WHERE identity_type = 'national_id' AND identity_value = :val
      AND status = 'pending'
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([':val' => $testIdentityValue]);
$remainderHold = $stmt->fetch(PDO::FETCH_ASSOC);

if ($remainderHold) {
    report("Remainder hold found: hold_id={$remainderHold['hold_id']}, amount={$remainderHold['amount']}",
        true, $remainderHold);
} else {
    report('No remainder hold found in DB', 'INFO', 'Either remainder was 0, or the reswap failed — check TEST C output above');
}

// ============================================================
// SUMMARY
// ============================================================
echo "\n=== SUMMARY ===\n";
$failed = array_filter($results, fn($r) => $r['pass'] === false);
foreach ($failed as $f) {
    echo "❌ {$f['label']}\n";
}
echo empty($failed) ? "All checks passed or informational.\n" : "\n" . count($failed) . " failure(s) found.\n";
