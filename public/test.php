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
$testPin = '641904';  // CHANGE THIS to the actual PIN
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
    $hasPin = !empty($hold['otp_pin_hash']) ? 'YES' : 'NO';
    $authStatus = !empty($hold['authorized_at']) ? "AUTHORIZED at {$hold['authorized_at']}" : 'NOT AUTHORIZED';
    echo "  - Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$hold['status']}, has PIN: {$hasPin}, {$authStatus}\n";
}
echo "Total gross amount: {$totalGross} BWP\n";

if (empty($pendingHolds)) {
    echo "❌ No pending holds found. Exiting.\n";
    exit(1);
}

// ============================================================
// STEP 2: CHECK IF IDENTITY IS ALREADY AUTHORIZED (DIRECT SQL)
// ============================================================
echo "\n🔑 STEP 2: CHECKING AUTHORIZATION STATUS (via SQL)\n";
echo str_repeat('-', 40) . "\n";

$stmt = $db->prepare("
    SELECT 1 FROM identity_swap_holds 
    WHERE identity_type = :type 
      AND identity_value = :value 
      AND status = 'pending'
      AND authorized_at IS NOT NULL
      AND authorized_at > NOW() - INTERVAL '1 hour'
    LIMIT 1
");
$stmt->execute([':type' => $identityType, ':value' => $identityValue]);
$isAuthorized = (bool)$stmt->fetchColumn();

echo "Identity authorized (green light): " . ($isAuthorized ? 'YES ✅' : 'NO ❌') . "\n";

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
// STEP 3.5: VERIFY THE PIN WORKS FIRST
// ============================================================
echo "\n🔐 STEP 3.5: VERIFYING PIN AGAINST A HOLD\n";
echo str_repeat('-', 40) . "\n";

if ($holdWithPin) {
    $hash = $holdWithPin['otp_pin_hash'];
    $pinMatches = password_verify($testPin, $hash);
    echo "PIN verification test: " . ($pinMatches ? '✅ SUCCESS - PIN matches!' : '❌ FAILED - PIN does NOT match!') . "\n";
    if (!$pinMatches) {
        echo "   The test PIN '{$testPin}' does NOT match the stored hash for hold {$holdWithPin['hold_id']}.\n";
        echo "   You need to use the correct PIN. Check the SMS logs.\n";
    }
} else {
    echo "⚠️ No hold with PIN to test against.\n";
}

// ============================================================
// STEP 4: ATTEMPT TO FINALIZE ALL HOLDS
// ============================================================
echo "\n🚀 STEP 4: FINALIZING ALL HOLDS\n";
echo str_repeat('-', 40) . "\n";

if (!$pinMatches && $holdWithPin) {
    echo "⚠️ SKIPPING finalization because PIN doesn't match.\n";
    echo "   Please update \$testPin to the correct PIN from the SMS.\n";
} else {
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
}

// ============================================================
// STEP 5: VERIFY WHAT HAPPENED
// ============================================================
echo "\n📊 STEP 5: VERIFYING RESULTS\n";
echo str_repeat('-', 40) . "\n";

// Check holds after attempt
$stmt = $db->prepare("
    SELECT hold_id, amount, status, authorized_at, completed_at, otp_pin_hash
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
$confirmedCount = 0;
$failedCount = 0;
foreach ($allHolds as $hold) {
    $status = $hold['status'];
    $hasPinNow = !empty($hold['otp_pin_hash']) ? 'has PIN' : 'PIN cleared';
    $authAt = !empty($hold['authorized_at']) ? "auth: {$hold['authorized_at']}" : 'not auth';
    echo "  - Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$status}, {$hasPinNow}, {$authAt}\n";
    if ($status === 'completed') $completedCount++;
    elseif ($status === 'pending') $pendingCount++;
    elseif ($status === 'confirmed') $confirmedCount++;
    elseif ($status === 'failed') $failedCount++;
}
echo "Completed: {$completedCount}, Confirmed: {$confirmedCount}, Pending: {$pendingCount}, Failed: {$failedCount}\n";

// Check deposit transactions
$stmt = $db->prepare("
    SELECT transaction_reference, amount, currency, status, created_at
    FROM deposit_transactions 
    WHERE transaction_reference LIKE 'AGG_REMAIN_%' 
       OR transaction_reference LIKE 'SWAP_REMAINDER_%'
       OR transaction_reference LIKE 'SWAP_%'
    ORDER BY created_at DESC
    LIMIT 15
");
$stmt->execute();
$deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nRecent deposit transactions:\n";
foreach ($deposits as $dep) {
    echo "  - {$dep['transaction_reference']}: {$dep['amount']} {$dep['currency']} - {$dep['status']} at {$dep['created_at']}\n";
}

// ============================================================
// STEP 6: WHAT WENT WRONG?
// ============================================================
echo "\n🔍 STEP 6: DIAGNOSTIC ANALYSIS\n";
echo str_repeat('-', 40) . "\n";

if ($completedCount === 0 && $pendingCount > 0 && $confirmedCount === 0) {
    echo "❌ CRITICAL: No holds were completed or confirmed!\n";
    echo "   This means finalizeAggregatedIdentityClaim() is not processing holds.\n";
    echo "   Possible causes:\n";
    echo "   1. The PIN verification failed (check STEP 3.5)\n";
    echo "   2. The identity is not properly authorized\n";
    echo "   3. The destination account is invalid\n";
} elseif ($confirmedCount > 0 && $completedCount === 0) {
    echo "⚠️ HOLDS STUCK AT 'confirmed': {$confirmedCount} hold(s)\n";
    echo "   This means the PIN passed but the transaction failed.\n";
    echo "   Check:\n";
    echo "   1. The source account has sufficient balance\n";
    echo "   2. The destination account is valid and active\n";
    echo "   3. The bank adapter is working properly\n";
} elseif ($completedCount > 0 && $pendingCount > 0) {
    echo "⚠️ PARTIAL: Only {$completedCount} of " . ($completedCount + $pendingCount + $confirmedCount) . " holds completed.\n";
    echo "   This means the loop ran but some holds failed.\n";
    echo "   Check the failed/confirmed holds for errors.\n";
} elseif ($completedCount === count($allHolds)) {
    echo "✅ SUCCESS: All holds were completed!\n";
    echo "   The aggregated claim is working correctly.\n";
}

// ============================================================
// STEP 7: SPECIFIC HOLD ANALYSIS
// ============================================================
echo "\n🔍 STEP 7: DETAILED HOLD ANALYSIS\n";
echo str_repeat('-', 40) . "\n";

foreach ($allHolds as $hold) {
    if ($hold['status'] === 'confirmed') {
        echo "⚠️ Hold {$hold['hold_id']} is stuck at 'confirmed'.\n";
        echo "   This usually means the transaction started but failed mid-way.\n";
        echo "   Check the source balance and destination account.\n";
    }
    if ($hold['status'] === 'pending' && empty($hold['otp_pin_hash'])) {
        echo "ℹ️ Hold {$hold['hold_id']} is pending with no PIN hash.\n";
        echo "   This means the PIN was cleared but the hold wasn't completed.\n";
        echo "   The PIN was probably verified but the finalization failed.\n";
    }
    if ($hold['status'] === 'pending' && !empty($hold['otp_pin_hash'])) {
        echo "ℹ️ Hold {$hold['hold_id']} is pending with PIN still intact.\n";
        echo "   This hold was never processed.\n";
    }
}

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";
