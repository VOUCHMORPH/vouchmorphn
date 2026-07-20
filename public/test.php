<?php
/**
 * test_aggressive_aggregated_finalize.php
 * 
 * Aggressively tests finalizing ALL holds for an identity
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

// ============================================================
// CONFIGURATION - UPDATED WITH CORRECT PIN FROM SMS
// ============================================================
$identityType = 'national_id';
$identityValue = 'ID123456789';
$testPin = '631659';  // CORRECT PIN FROM SMS
$destinationAccountId = 7;
$agentUserId = 12;
$cashNowAmount = 200.00;

// ============================================================
// BOOTSTRAP
// ============================================================
$db = DBConnection::getConnection();
$config = LoadCountry::getConfig();
$swapService = new SwapService($db, $config, 'Botswana');

echo "\n" . str_repeat('=', 80) . "\n";
echo "  AGGRESSIVE TEST: FINALIZE ALL HOLDS\n";
echo "  PIN FROM SMS: {$testPin}\n";
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
// STEP 2: VERIFY THE PIN WORKS
// ============================================================
echo "\n🔐 STEP 2: VERIFYING PIN\n";
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
        echo "   Checking if this PIN matches any other hold...\n";
        
        $foundMatch = false;
        foreach ($pendingHolds as $hold) {
            if (!empty($hold['otp_pin_hash']) && password_verify($testPin, $hold['otp_pin_hash'])) {
                echo "   ✅ PIN matches hold {$hold['hold_id']}!\n";
                $foundMatch = true;
                $holdWithPin = $hold;
                break;
            }
        }
        
        if (!$foundMatch) {
            echo "   ❌ PIN does not match ANY hold. Please check the correct PIN.\n";
            exit(1);
        }
    }
} else {
    echo "⚠️ No hold with PIN to test against.\n";
}

// ============================================================
// STEP 3: ATTEMPT TO FINALIZE ALL HOLDS
// ============================================================
echo "\n🚀 STEP 3: FINALIZING ALL HOLDS\n";
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
// STEP 4: VERIFY WHAT HAPPENED
// ============================================================
echo "\n📊 STEP 4: VERIFYING RESULTS\n";
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
// STEP 5: CHECK THE PENDING HOLDS SPECIFICALLY
// ============================================================
if ($pendingCount > 0) {
    echo "\n📊 STEP 5: DETAILED CHECK OF PENDING HOLDS\n";
    echo str_repeat('-', 40) . "\n";
    
    $stmt = $db->prepare("
        SELECT hold_id, amount, status, otp_pin_hash, authorized_at, completed_at, created_at
        FROM identity_swap_holds 
        WHERE identity_type = :type 
          AND identity_value = :value 
          AND status = 'pending'
    ");
    $stmt->execute([':type' => $identityType, ':value' => $identityValue]);
    $pendingDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($pendingDetails as $hold) {
        echo "Hold {$hold['hold_id']}: {$hold['amount']} BWP\n";
        echo "  - has PIN hash: " . (!empty($hold['otp_pin_hash']) ? 'YES' : 'NO') . "\n";
        echo "  - authorized_at: " . ($hold['authorized_at'] ?? 'NULL') . "\n";
        echo "  - created_at: {$hold['created_at']}\n";
    }
}

// ============================================================
// STEP 6: SUMMARY
// ============================================================
echo "\n🔍 STEP 6: SUMMARY\n";
echo str_repeat('-', 40) . "\n";

if ($completedCount === count($allHolds)) {
    echo "✅ SUCCESS: All holds were completed!\n";
    echo "   The aggregated claim is working correctly.\n";
} elseif ($completedCount > 0 && $pendingCount > 0) {
    echo "⚠️ PARTIAL: {$completedCount} holds completed, {$pendingCount} still pending.\n";
    echo "   Check STEP 5 for details on pending holds.\n";
} elseif ($confirmedCount > 0) {
    echo "⚠️ HOLDS STUCK AT 'confirmed': {$confirmedCount} hold(s)\n";
    echo "   This means the PIN passed but the transaction failed.\n";
} else {
    echo "❌ No holds were completed.\n";
}

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";
