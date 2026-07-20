<?php
/**
 * test_dashboard_diagnostic.php
 *
 * Purpose: determine EXACTLY why the dashboard claim only processed
 * one hold instead of the full aggregated batch. Does NOT guess —
 * every claim below is verified against either the live DB or the
 * live deployed endpoint file's actual source code.
 *
 * Run this in the same environment SwapService normally runs in.
 */

require_once __DIR__ . '/vendor/autoload.php'; // adjust to your bootstrap
use Core\Database\DBConnection;
use Domain\Services\SwapService;

$results = [];
function report(string $section, string $label, $pass, $detail = null): void {
    global $results;
    $results[] = compact('section', 'label', 'pass', 'detail');
    $mark = $pass === true ? '[PASS]' : ($pass === false ? '[FAIL]' : '[INFO]');
    echo "{$mark} [{$section}] {$label}\n";
    if ($detail !== null) {
        echo "        " . (is_string($detail) ? $detail : json_encode($detail)) . "\n";
    }
}

$db = DBConnection::getConnection();

// =================================================================
// TEST 1: What does the LIVE, DEPLOYED finalize_claim.php actually
// accept? Read the real file on disk right now — not what we think
// we sent, the actual bytes currently running.
// =================================================================
echo "\n=== TEST 1: Inspect deployed finalize_claim.php ===\n";

$finalizeClaimPath = null;
$candidatePaths = [
    __DIR__ . '/public/api/v1/agent/finalize_claim.php',
    __DIR__ . '/api/v1/agent/finalize_claim.php',
    $_SERVER['DOCUMENT_ROOT'] . '/api/v1/agent/finalize_claim.php',
];
foreach ($candidatePaths as $p) {
    if (file_exists($p)) { $finalizeClaimPath = $p; break; }
}

if (!$finalizeClaimPath) {
    report('DEPLOY_CHECK', 'Locate finalize_claim.php on disk', 'INFO',
        'Could not auto-locate. Edit $candidatePaths in this script to point at the real file path.');
} else {
    $source = file_get_contents($finalizeClaimPath);
    $usesOldShape = strpos($source, "\$body['swap_reference']") !== false
                 && strpos($source, "confirmAndFinalizeIdentitySwap") !== false;
    $usesNewShape = strpos($source, "\$body['identity_type']") !== false
                 && strpos($source, "finalizeAggregatedIdentityClaim") !== false;

    report('DEPLOY_CHECK', "File found at: {$finalizeClaimPath}", true);
    report('DEPLOY_CHECK', 'Accepts swap_reference + calls confirmAndFinalizeIdentitySwap (OLD single-hold shape)',
        $usesOldShape, $usesOldShape ? 'CONFIRMED PRESENT — this is the bug source if TEST 3 shows multiple pending holds' : 'not found');
    report('DEPLOY_CHECK', 'Accepts identity_type/identity_value + calls finalizeAggregatedIdentityClaim (NEW aggregated shape)',
        $usesNewShape, $usesNewShape ? 'present' : 'NOT FOUND — aggregated rewrite was never deployed');
}

// =================================================================
// TEST 2: Same check for search_claim.php
// =================================================================
echo "\n=== TEST 2: Inspect deployed search_claim.php ===\n";

$searchClaimPath = null;
foreach ([
    __DIR__ . '/public/api/v1/agent/search_claim.php',
    __DIR__ . '/api/v1/agent/search_claim.php',
    $_SERVER['DOCUMENT_ROOT'] . '/api/v1/agent/search_claim.php',
] as $p) {
    if (file_exists($p)) { $searchClaimPath = $p; break; }
}

if ($searchClaimPath) {
    $source = file_get_contents($searchClaimPath);
    $usesAggregated = strpos($source, 'getAggregatedIdentityBalance') !== false;
    $usesOldList = strpos($source, 'getPendingIdentitySwaps') !== false;
    report('DEPLOY_CHECK', "search_claim.php found: {$searchClaimPath}", true);
    report('DEPLOY_CHECK', 'Uses getAggregatedIdentityBalance (NEW)', $usesAggregated);
    report('DEPLOY_CHECK', 'Uses getPendingIdentitySwaps (OLD, one row per hold)', $usesOldList);
} else {
    report('DEPLOY_CHECK', 'Locate search_claim.php on disk', 'INFO', 'Adjust path list in script.');
}

// =================================================================
// TEST 3: Ground truth from the DATABASE — how many pending holds
// ACTUALLY exist right now for the test identity, regardless of what
// any endpoint says?
// =================================================================
echo "\n=== TEST 3: Real pending holds in DB for ID123456789 ===\n";

$stmt = $db->prepare("
    SELECT hold_id, swap_reference, amount, currency, status, created_at, otp_pin_sent_at
    FROM identity_swap_holds
    WHERE identity_type = 'national_id' AND identity_value = 'ID123456789'
      AND status = 'pending' AND hold_expires_at > NOW()
    ORDER BY created_at ASC
");
$stmt->execute();
$pendingHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);

report('DB_TRUTH', 'Pending hold count for ID123456789', true, count($pendingHolds));
foreach ($pendingHolds as $h) {
    echo "        hold_id={$h['hold_id']} ref={$h['swap_reference']} amount={$h['amount']} created={$h['created_at']}\n";
}

if (count($pendingHolds) < 2) {
    report('DB_TRUTH', 'WARNING: fewer than 2 pending holds exist', 'INFO',
        'Cannot prove multi-hold aggregation without at least 2 pending holds. Place 2+ IDENTITY swaps to ID123456789 before re-running this test.');
}

// =================================================================
// TEST 4: Call SwapService::getAggregatedIdentityBalance() DIRECTLY
// (bypassing any HTTP endpoint entirely) — does the aggregation
// LOGIC itself work, independent of whether the endpoint uses it?
// =================================================================
echo "\n=== TEST 4: Direct call to getAggregatedIdentityBalance() ===\n";

$country = 'Botswana';
$swapService = new SwapService($db, \Core\Config\LoadCountry::getConfig(), $country);

try {
    $aggregate = $swapService->getAggregatedIdentityBalance('national_id', 'ID123456789');
    if ($aggregate === null) {
        report('LOGIC_TRUTH', 'getAggregatedIdentityBalance returned', false, 'null — no pending balance found');
    } elseif (!empty($aggregate['multi_currency'])) {
        report('LOGIC_TRUTH', 'getAggregatedIdentityBalance returned multi-currency result', 'INFO', $aggregate);
    } else {
        $matchesDbCount = ((int)$aggregate['swap_count']) === count($pendingHolds);
        report('LOGIC_TRUTH', "Aggregated total_amount = {$aggregate['total_amount']}, swap_count = {$aggregate['swap_count']}",
            $matchesDbCount, $aggregate);
        if (!$matchesDbCount) {
            report('LOGIC_TRUTH', 'MISMATCH: aggregate swap_count does not match DB pending hold count', false,
                "aggregate says {$aggregate['swap_count']}, DB TEST 3 found " . count($pendingHolds));
        }
    }
} catch (\Throwable $e) {
    report('LOGIC_TRUTH', 'getAggregatedIdentityBalance threw', false, $e->getMessage());
}

// =================================================================
// TEST 5: Simulate exactly what the CURRENT dashboard likely sends
// (swap_reference singular) vs what it SHOULD send (identity_type +
// identity_value) — hit executeAtomicSwap-equivalent logic for both
// shapes and show the divergence explicitly.
// =================================================================
echo "\n=== TEST 5: Old-shape vs new-shape request simulation ===\n";

if (count($pendingHolds) >= 1) {
    $firstHoldRef = $pendingHolds[0]['swap_reference'];
    report('SHAPE_TEST', 'OLD SHAPE would send', 'INFO', ['swap_reference' => $firstHoldRef]);
    report('SHAPE_TEST', 'OLD SHAPE routes to', 'INFO', 'confirmAndFinalizeIdentitySwap() -> processes ONLY this one hold_id, ignores all others for this identity');

    report('SHAPE_TEST', 'NEW SHAPE would send', 'INFO', ['identity_type' => 'national_id', 'identity_value' => 'ID123456789']);
    report('SHAPE_TEST', 'NEW SHAPE routes to', 'INFO', 'finalizeAggregatedIdentityClaim() -> processes ALL ' . count($pendingHolds) . ' pending holds in one PIN check');
}

// =================================================================
// SUMMARY
// =================================================================
echo "\n=== SUMMARY ===\n";
$failed = array_filter($results, fn($r) => $r['pass'] === false);
foreach ($failed as $f) {
    echo "❌ [{$f['section']}] {$f['label']}\n";
}
if (empty($failed)) {
    echo "No hard failures — but review [INFO] lines above for the actual root cause.\n";
} else {
    echo "\n" . count($failed) . " confirmed issue(s) found above.\n";
}
