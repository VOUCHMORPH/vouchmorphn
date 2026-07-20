<?php
/**
 * test_aggressive_aggregated_finalize.php
 * 
 * Aggressively tests finalizing ALL holds for an identity
 * This bypasses the dashboard and directly tests the backend
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

// ============================================================
// CONFIGURATION - CHANGE THESE TO MATCH YOUR TEST
// ============================================================
$identityType = 'national_id';
$identityValue = 'ID123456789';  // CHANGE THIS to your test identity
$testPin = '123456';  // CHANGE THIS to the actual PIN
$destinationAccountId = 7;  // Agent's destination account ID
$agentUserId = 12;  // Agent's user ID
$cashNowAmount = 200.00;  // Amount to give client in cash

// ============================================================
// BOOTSTRAP
// ============================================================
$db = DBConnection::getConnection();
$config = LoadCountry::getConfig();
$swapService = new SwapService($db, $config, 'Botswana');

echo "\n" . str_repeat('=', 80) . "\n";
echo "  AGGRESSIVE TEST: FINALIZE ALL HOLDS\n";
echo str_repeat('=', 80) . "\n";

// ============================================================
// STEP 1: CHECK ALL PENDING HOLDS
// ============================================================
echo "\n📊 STEP 1: CHECKING PENDING HOLDS\n";
echo str_repeat('-', 40) . "\n";

$stmt = $db->prepare("
    SELECT hold_id, swap_reference, amount, currency, status, 
           identity_type, identity_value, created_at, hold_expires_at,
           otp_pin_hash, otp_pin_sent_to, authorized_at
    FROM identity_swap_holds 
    WHERE identity_type = :type 
      AND identity_value = :value 
      AND status = 'pending'
      AND hold_expires_at > NOW()
    ORDER BY created_at ASC
");
$stmt->execute([':type' => $identityType, ':value' => $identityValue]);
$pendingHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($pendingHolds) . " pending hold(s) for identity:\n";
$totalGross = 0;
foreach ($pendingHolds as $hold) {
    $totalGross += (float)$hold['amount'];
    echo "  - Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$hold['status']}, has PIN: " . (!empty($hold['otp_pin_hash']) ? 'YES' : 'NO') . "\n";
}
echo "Total gross amount: {$totalGross} BWP\n";

if (empty($pendingHolds)) {
    echo "❌ No pending holds found. Exiting.\n";
    exit(1);
}

// ============================================================
// STEP 2: CHECK IF IDENTITY IS ALREADY AUTHORIZED
// ============================================================
echo "\n🔑 STEP 2: CHECKING AUTHORIZATION STATUS\n";
echo str_repeat('-', 40) . "\n";

$isAuthorized = $swapService->isIdentityAuthorized($identityType, $identityValue);
echo "Identity authorized: " . ($isAuthorized ? 'YES ✅' : 'NO ❌') . "\n";

// ============================================================
// STEP 3: GET THE LATEST PIN
// ============================================================
echo "\n📱 STEP 3: CHECKING PIN\n";
echo str_repeat('-', 40) . "\n";

// Find a hold with a PIN hash
$holdWithPin = null;
foreach ($pendingHolds as $hold) {
    if (!empty($hold['otp_pin_hash'])) {
        $holdWithPin = $hold;
        break;
    }
}

if ($holdWithPin) {
    echo "Found hold with PIN: Hold {$holdWithPin['hold_id']}\n";
    echo "PIN sent to: {$holdWithPin['otp_pin_sent_to']}\n";
    echo "Using test PIN: {$testPin}\n";
} else {
    echo "⚠️ No hold with PIN found. You may need to create a new hold first.\n";
}

// ============================================================
// STEP 4: ATTEMPT TO FINALIZE ALL HOLDS
// ============================================================
echo "\n🚀 STEP 4: FINALIZING ALL HOLDS\n";
echo str_repeat('-', 40) . "\n";

try {
    // This is the critical call - it should process ALL holds
    $result = $swapService->finalizeAggregatedIdentityClaim(
        $identityType,
        $identityValue,
        $testPin,
        'agent',
        $agentUserId,
        $destinationAccountId,
        $cashNowAmount,
        $agentUserId
    );
    
    echo "✅ SUCCESS! Result:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    
} catch (\Throwable $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

// ============================================================
// STEP 5: VERIFY WHAT HAPPENED
// ============================================================
echo "\n📊 STEP 5: VERIFYING RESULTS\n";
echo str_repeat('-', 40) . "\n";

// Check holds after attempt
$stmt = $db->prepare("
    SELECT hold_id, amount, status, authorized_at, completed_at
    FROM identity_swap_holds 
    WHERE identity_type = :type 
      AND identity_value = :value 
    ORDER BY created_at ASC
");
$stmt->execute([':type' => $identityType, ':value' => $identityValue]);
$allHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "All holds for this identity:\n";
$completedCount = 0;
$pendingCount = 0;
foreach ($allHolds as $hold) {
    $status = $hold['status'];
    echo "  - Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$status}\n";
    if ($status === 'completed') $completedCount++;
    if ($status === 'pending') $pendingCount++;
}
echo "Completed: {$completedCount}, Pending: {$pendingCount}\n";

// Check deposit transactions
$stmt = $db->prepare("
    SELECT transaction_reference, amount, currency, status, created_at
    FROM deposit_transactions 
    WHERE transaction_reference LIKE 'AGG_REMAIN_%' 
       OR transaction_reference LIKE 'SWAP_REMAINDER_%'
       OR transaction_reference LIKE 'SWAP_%'
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute();
$deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nRecent deposit transactions:\n";
foreach ($deposits as $dep) {
    echo "  - {$dep['transaction_reference']}: {$dep['amount']} {$dep['currency']} - {$dep['status']}\n";
}

// ============================================================
// STEP 6: WHAT WENT WRONG?
// ============================================================
echo "\n🔍 STEP 6: DIAGNOSTIC ANALYSIS\n";
echo str_repeat('-', 40) . "\n";

if ($completedCount === 0 && $pendingCount > 0) {
    echo "❌ CRITICAL: No holds were completed!\n";
    echo "   This means finalizeAggregatedIdentityClaim() is not processing holds.\n";
    echo "   Check:\n";
    echo "   1. The PIN verification - is it passing?\n";
    echo "   2. The loop in finalizeAggregatedIdentityClaim() - is it iterating?\n";
    echo "   3. The executeSingleHoldTransaction() - is it being called?\n";
} elseif ($completedCount > 0 && $pendingCount > 0) {
    echo "⚠️ PARTIAL: Only {$completedCount} of " . ($completedCount + $pendingCount) . " holds completed.\n";
    echo "   This means the loop ran but some holds failed.\n";
    echo "   Check the failed holds for errors.\n";
} elseif ($completedCount === count($allHolds)) {
    echo "✅ SUCCESS: All holds were completed!\n";
    echo "   The aggregated claim is working correctly.\n";
}

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";
