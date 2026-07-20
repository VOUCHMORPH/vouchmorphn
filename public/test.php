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
// CONFIGURATION - UPDATED WITH CORRECT PIN
// ============================================================
$identityType = 'national_id';
$identityValue = 'ID123456789';
$testPin = '963633';  // CORRECT PIN FROM SMS
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
echo "  AGGRESSIVE TEST: FINALIZE ALL HOLDS (WITH CORRECT PIN)\n";
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
// STEP 2: CHECK IF IDENTITY IS ALREADY AUTHORIZED
// ============================================================
echo "\n🔑 STEP 2: CHECKING AUTHORIZATION STATUS\n";
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
// STEP 3: VERIFY THE PIN WORKS
// ============================================================
echo "\n🔐 STEP 3: VERIFYING PIN\n";
echo str_repeat('-', 40) . "\n";

// Find a hold with a PIN hash and test the PIN
$holdWithPin = null;
foreach ($pendingHolds as $hold) {
    if (!empty($hold['otp_pin_hash'])) {
        $holdWithPin = $hold;
        break;
    }
}

if ($holdWithPin) {
    $hash = $holdWithPin['otp_pin_hash'];
    $pinMatches = password_verify($testPin, $hash);
    echo "PIN verification test against hold {$holdWithPin['hold_id']}: " . ($pinMatches ? '✅ SUCCESS - PIN matches!' : '❌ FAILED - PIN does NOT match!') . "\n";
    
    if (!$pinMatches) {
        echo "   The test PIN '{$testPin}' does NOT match the stored hash.\n";
        echo "   Please check the correct PIN from SMS logs.\n";
        exit(1);
    }
} else {
    echo "⚠️ No hold with PIN to test against.\n";
}

// ============================================================
// STEP 4: ATTEMPT TO FINALIZE ALL HOLDS
// ============================================================
echo "\n🚀 STEP 4: FINALIZING ALL HOLDS\n";
echo str_repeat('-', 40) . "\n";

try {
    $startTime = microtime(true);
    
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
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    echo "✅ SUCCESS in {$duration} seconds!\n";
    echo "Result:\n";
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
    $completedAt = !empty($hold['completed_at']) ? "completed: {$hold['completed_at']}" : '';
    echo "  - Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$status}, {$hasPinNow}, {$authAt} {$completedAt}\n";
    if ($status === 'completed') $completedCount++;
    elseif ($status === 'pending') $pendingCount++;
    elseif ($status === 'confirmed') $confirmedCount++;
    elseif ($status === 'failed') $failedCount++;
}
echo "Completed: {$completedCount}, Confirmed: {$confirmedCount}, Pending: {$pendingCount}, Failed: {$failedCount}\n";

// ============================================================
// STEP 6: CHECK THE 6 PENDING HOLDS SPECIFICALLY
// ============================================================
echo "\n📊 STEP 6: SPECIFIC CHECK OF THE 6 PENDING HOLDS\n";
echo str_repeat('-', 40) . "\n";

$pendingIds = [400, 401, 415, 422, 423, 424];
$placeholders = implode(',', array_fill(0, count($pendingIds), '?'));
$stmt = $db->prepare("
    SELECT hold_id, amount, status, otp_pin_hash, authorized_at, completed_at
    FROM identity_swap_holds 
    WHERE hold_id IN ({$placeholders})
");
$stmt->execute($pendingIds);
$specificHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($specificHolds as $hold) {
    echo "Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$hold['status']}\n";
    echo "  - has PIN hash: " . (!empty($hold['otp_pin_hash']) ? 'YES' : 'NO') . "\n";
    echo "  - authorized_at: " . ($hold['authorized_at'] ?? 'NULL') . "\n";
    echo "  - completed_at: " . ($hold['completed_at'] ?? 'NULL') . "\n";
}

// ============================================================
// STEP 7: CHECK DEPOSIT TRANSACTIONS
// ============================================================
echo "\n📊 STEP 7: RECENT DEPOSIT TRANSACTIONS\n";
echo str_repeat('-', 40) . "\n";

$stmt = $db->prepare("
    SELECT transaction_reference, amount, currency, status, created_at
    FROM deposit_transactions 
    WHERE transaction_reference LIKE 'AGG_REMAIN_%' 
       OR transaction_reference LIKE 'SWAP_REMAINDER_%'
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute();
$deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($deposits as $dep) {
    echo "  - {$dep['transaction_reference']}: {$dep['amount']} {$dep['currency']} - {$dep['status']} at {$dep['created_at']}\n";
}

// ============================================================
// STEP 8: DIAGNOSTIC ANALYSIS
// ============================================================
echo "\n🔍 STEP 8: DIAGNOSTIC ANALYSIS\n";
echo str_repeat('-', 40) . "\n";

if ($completedCount === 0 && $pendingCount > 0 && $confirmedCount === 0) {
    echo "❌ CRITICAL: No holds were completed!\n";
    echo "   Check the error message above.\n";
} elseif ($confirmedCount > 0 && $completedCount === 0) {
    echo "⚠️ HOLDS STUCK AT 'confirmed': {$confirmedCount} hold(s)\n";
    echo "   This means the PIN passed but the transaction failed.\n";
} elseif ($completedCount > 0 && $pendingCount > 0) {
    echo "⚠️ PARTIAL: Only {$completedCount} holds completed. {$pendingCount} still pending.\n";
    echo "   This means the loop ran but some holds failed.\n";
    echo "   Check STEP 6 for details on which holds are still pending.\n";
} elseif ($completedCount === count($allHolds)) {
    echo "✅ SUCCESS: All holds were completed!\n";
    echo "   The aggregated claim is working correctly.\n";
}

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";
