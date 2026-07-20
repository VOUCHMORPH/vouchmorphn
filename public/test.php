<?php
/**
 * test_complete_aggregated_flow.php
 * 
 * Complete end-to-end test of the aggregated claim flow:
 * 1. Check pending holds for identity
 * 2. Finalize ALL holds into agent account
 * 3. Give client cash (300 BWP)
 * 4. Re-swap remainder to client's identity
 * 5. Verify everything worked
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

// ============================================================
// CONFIGURATION
// ============================================================
$identityType = 'national_id';
$identityValue = 'ID123456789';
$testPin = '763761';  // Latest PIN from SMS
$destinationAccountId = 7;  // Agent's destination account (ZuruBank 10000001)
$agentUserId = 12;  // Agent's user ID
$cashNowAmount = 300.00;  // Client wants 300 BWP in cash

// ============================================================
// BOOTSTRAP
// ============================================================
$db = DBConnection::getConnection();
$config = LoadCountry::getConfig();
$swapService = new SwapService($db, $config, 'Botswana');

echo "\n" . str_repeat('=', 80) . "\n";
echo "  COMPLETE AGGREGATED FLOW TEST\n";
echo "  Identity: {$identityValue}\n";
echo "  PIN: {$testPin}\n";
echo "  Cash to client: {$cashNowAmount} BWP\n";
echo str_repeat('=', 80) . "\n";

// ============================================================
// STEP 1: GET ALL PENDING HOLDS
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

$totalGross = 0;
$holdCount = count($pendingHolds);

echo "Found {$holdCount} pending hold(s) for identity:\n";
foreach ($pendingHolds as $hold) {
    $totalGross += (float)$hold['amount'];
    $hasPin = !empty($hold['otp_pin_hash']) ? 'YES' : 'NO';
    $authStatus = !empty($hold['authorized_at']) ? "AUTHORIZED at {$hold['authorized_at']}" : 'NOT AUTHORIZED';
    echo "  - Hold {$hold['hold_id']}: {$hold['amount']} BWP, has PIN: {$hasPin}, {$authStatus}\n";
}
echo "Total gross amount: {$totalGross} BWP\n";

if (empty($pendingHolds)) {
    echo "❌ No pending holds found. Exiting.\n";
    exit(1);
}

// ============================================================
// STEP 2: VERIFY THE PIN WORKS
// ============================================================
echo "\n🔐 STEP 2: VERIFYING PIN\n";
echo str_repeat('-', 40) . "\n";

$pinMatches = false;
$matchedHold = null;

foreach ($pendingHolds as $hold) {
    if (!empty($hold['otp_pin_hash']) && password_verify($testPin, $hold['otp_pin_hash'])) {
        $pinMatches = true;
        $matchedHold = $hold;
        break;
    }
}

if ($pinMatches && $matchedHold) {
    echo "✅ PIN '{$testPin}' matches hold {$matchedHold['hold_id']}!\n";
} else {
    echo "❌ PIN '{$testPin}' does NOT match any hold.\n";
    echo "   Please check the correct PIN from SMS logs.\n";
    exit(1);
}

// ============================================================
// STEP 3: CHECK CURRENT AGENT BALANCE (Before)
// ============================================================
echo "\n💰 STEP 3: CHECKING AGENT BALANCE (BEFORE)\n";
echo str_repeat('-', 40) . "\n";

$stmt = $db->prepare("
    SELECT balance FROM zurubank_accounts WHERE account_number = '10000001'
");
$stmt->execute();
$agentBalanceBefore = $stmt->fetchColumn();

if ($agentBalanceBefore !== false) {
    echo "Agent account 10000001 balance: {$agentBalanceBefore} BWP\n";
} else {
    echo "⚠️ Could not get agent balance. Continuing...\n";
    $agentBalanceBefore = 0;
}

// ============================================================
// STEP 4: FINALIZE ALL HOLDS
// ============================================================
echo "\n🚀 STEP 4: FINALIZING ALL HOLDS\n";
echo str_repeat('-', 40) . "\n";

echo "Total gross: {$totalGross} BWP\n";
echo "Cash to client: {$cashNowAmount} BWP\n";
echo "Estimated fees: ~" . (count($pendingHolds) * 6) . " BWP\n";
echo "Estimated net: ~" . ($totalGross - (count($pendingHolds) * 6)) . " BWP\n";
echo "Estimated remainder: ~" . ($totalGross - (count($pendingHolds) * 6) - $cashNowAmount) . " BWP\n";

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
    
    echo "\n✅ FINALIZATION COMPLETE in {$duration} seconds!\n";
    
} catch (\Throwable $e) {
    echo "❌ FINALIZATION FAILED: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

// ============================================================
// STEP 5: DISPLAY RESULTS
// ============================================================
echo "\n📊 STEP 5: RESULTS\n";
echo str_repeat('-', 40) . "\n";

if ($result) {
    $gross = $result['actually_claimed_gross'] ?? 0;
    $net = $result['actually_claimed_net'] ?? 0;
    $totalFees = $gross - $net;
    $cashGiven = $result['cash_now_amount'] ?? 0;
    $remainder = $result['remainder_reswap']['amount'] ?? 0;
    $swapCount = $result['swap_count'] ?? 0;
    $successful = count($result['successful_deposits'] ?? []);
    $failed = count($result['failed_deposits'] ?? []);
    $status = $result['status'] ?? 'unknown';
    
    echo "Status: {$status}\n";
    echo "Gross amount: {$gross} BWP\n";
    echo "Net amount (after fees): {$net} BWP\n";
    echo "Total fees: {$totalFees} BWP\n";
    echo "Cash given to client: {$cashGiven} BWP\n";
    echo "Remainder re-swapped: {$remainder} BWP\n";
    echo "Swap count: {$swapCount}\n";
    echo "Successful deposits: {$successful}\n";
    echo "Failed deposits: {$failed}\n";
    
    // Show individual deposit results
    if (!empty($result['successful_deposits'])) {
        echo "\n✅ Successful deposits:\n";
        foreach ($result['successful_deposits'] as $dep) {
            echo "  - Hold {$dep['hold_id']}: {$dep['gross_amount']} BWP → {$dep['net_deposited']} BWP (fee: " . ($dep['gross_amount'] - $dep['net_deposited']) . " BWP)\n";
        }
    }
    
    if (!empty($result['failed_deposits'])) {
        echo "\n❌ Failed deposits:\n";
        foreach ($result['failed_deposits'] as $dep) {
            echo "  - Hold {$dep['hold_id']}: {$dep['gross_amount']} BWP - {$dep['error']}\n";
        }
    }
    
    // Show remainder re-swap details
    if ($result['remainder_reswap'] && $result['remainder_reswap']['status'] === 'completed') {
        echo "\n🔄 Remainder re-swap:\n";
        echo "  - Amount: {$remainder} BWP\n";
        echo "  - Status: {$result['remainder_reswap']['status']}\n";
        echo "  - New hold reference: " . ($result['remainder_reswap']['result']['hold_reference'] ?? 'N/A') . "\n";
        echo "  - New hold ID: " . ($result['remainder_reswap']['result']['hold_id'] ?? 'N/A') . "\n";
        echo "  - Expires at: " . ($result['remainder_reswap']['result']['expires_at'] ?? 'N/A') . "\n";
    }
}

// ============================================================
// STEP 6: VERIFY DATABASE STATE
// ============================================================
echo "\n📊 STEP 6: VERIFYING DATABASE STATE\n";
echo str_repeat('-', 40) . "\n";

// Check all holds after the operation
$stmt = $db->prepare("
    SELECT hold_id, amount, status, authorized_at, completed_at
    FROM identity_swap_holds 
    WHERE identity_type = :type 
      AND identity_value = :value 
    ORDER BY created_at ASC
");
$stmt->execute([':type' => $identityType, ':value' => $identityValue]);
$allHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);

$completedCount = 0;
$pendingCount = 0;
$confirmedCount = 0;

echo "All holds after operation:\n";
foreach ($allHolds as $hold) {
    $status = $hold['status'];
    $completedAt = !empty($hold['completed_at']) ? "completed: {$hold['completed_at']}" : '';
    echo "  - Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$status} {$completedAt}\n";
    if ($status === 'completed') $completedCount++;
    elseif ($status === 'pending') $pendingCount++;
    elseif ($status === 'confirmed') $confirmedCount++;
}
echo "Completed: {$completedCount}, Confirmed: {$confirmedCount}, Pending: {$pendingCount}\n";

// Check the new remainder hold (if created)
if ($remainder > 0) {
    $stmt = $db->prepare("
        SELECT hold_id, amount, status, swap_reference, created_at
        FROM identity_swap_holds 
        WHERE swap_reference LIKE 'AGG_REMAIN_%'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute();
    $newHold = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($newHold) {
        echo "\n🔄 New remainder hold created:\n";
        echo "  - Hold ID: {$newHold['hold_id']}\n";
        echo "  - Amount: {$newHold['amount']} BWP\n";
        echo "  - Status: {$newHold['status']}\n";
        echo "  - Reference: {$newHold['swap_reference']}\n";
        echo "  - Created at: {$newHold['created_at']}\n";
    }
}

// Check deposit transactions
$stmt = $db->prepare("
    SELECT transaction_reference, amount, currency, status, created_at
    FROM deposit_transactions 
    WHERE transaction_reference LIKE 'SWAP_REMAINDER_%' 
       OR transaction_reference LIKE 'AGG_REMAIN_%'
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute();
$deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n💰 Recent deposit transactions:\n";
foreach ($deposits as $dep) {
    echo "  - {$dep['transaction_reference']}: {$dep['amount']} {$dep['currency']} - {$dep['status']}\n";
}

// ============================================================
// STEP 7: CHECK AGENT BALANCE (After)
// ============================================================
echo "\n💰 STEP 7: CHECKING AGENT BALANCE (AFTER)\n";
echo str_repeat('-', 40) . "\n";

$stmt = $db->prepare("
    SELECT balance FROM zurubank_accounts WHERE account_number = '10000001'
");
$stmt->execute();
$agentBalanceAfter = $stmt->fetchColumn();

if ($agentBalanceAfter !== false) {
    $balanceChange = $agentBalanceAfter - $agentBalanceBefore;
    echo "Agent account 10000001 balance: {$agentBalanceAfter} BWP\n";
    echo "Balance change: " . ($balanceChange > 0 ? '+' : '') . "{$balanceChange} BWP\n";
    
    // Verify the math
    $expectedChange = $net - $cashGiven - $remainder;
    echo "\nMath verification:\n";
    echo "  Net deposited: {$net} BWP\n";
    echo "  Cash given: {$cashGiven} BWP\n";
    echo "  Remainder re-swapped: {$remainder} BWP\n";
    echo "  Expected balance change: {$expectedChange} BWP\n";
    echo "  Actual balance change: {$balanceChange} BWP\n";
    echo "  Difference: " . abs($balanceChange - $expectedChange) . " BWP\n";
}

// ============================================================
// STEP 8: FINAL SUMMARY
// ============================================================
echo "\n🔍 STEP 8: FINAL SUMMARY\n";
echo str_repeat('-', 40) . "\n";

echo "✅ TEST COMPLETE\n\n";

echo "Summary:\n";
echo "  - Identity: {$identityValue}\n";
echo "  - PIN used: {$testPin}\n";
echo "  - Pending holds found: {$holdCount}\n";
echo "  - Total gross: {$totalGross} BWP\n";
echo "  - Net deposited: {$net} BWP\n";
echo "  - Total fees: " . ($totalGross - $net) . " BWP\n";
echo "  - Cash given to client: {$cashGiven} BWP\n";
echo "  - Remainder re-swapped: {$remainder} BWP\n";
echo "  - Status: {$status}\n";

if ($pendingCount === 0 && $completedCount > 0) {
    echo "\n🎉 ALL HOLDS HAVE BEEN FINALIZED!\n";
    echo "   The aggregated claim flow is working correctly.\n";
} elseif ($pendingCount > 0) {
    echo "\n⚠️ {$pendingCount} hold(s) still pending.\n";
    echo "   Check the logs for details.\n";
}

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";
