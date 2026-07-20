<?php
/**
 * test_isolated_deposit_flow.php
 * 
 * COMPREHENSIVE TEST - Focus on isolating the deposit method
 * 
 * This test:
 * 1. Creates test holds
 * 2. Tests depositing into agent account WITHOUT identity swap
 * 3. Traces EVERY step with detailed logging
 * 4. Identifies exactly where failures occur
 * 
 * KEY INSIGHT: We need to deposit ALL holds into the agent account first,
 * then handle the remainder re-swap. This test isolates that flow.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

// ============================================================
// CONFIGURATION
// ============================================================
$country = 'Botswana';
$db = DBConnection::getConnection();
$config = LoadCountry::getConfig();
$swapService = new SwapService($db, $config, $country);

// Test Identity - unique per run to avoid conflicts
$testIdentityValue = 'TEST_DEPOSIT_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$testPhone = '+26770000000';
$sourceInstitution = 'ZURUBANK';
$sourceIdentifier = '10000001';
$destinationAccountId = 7;  // Must exist in agent_destination_accounts
$agentUserId = 12;          // Must exist in users table

$results = [];
$traceLog = [];
$testHoldIds = [];
$generatedPins = [];

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function trace(string $step, $data = null): void {
    global $traceLog;
    $entry = ['step' => $step, 'time' => microtime(true)];
    if ($data !== null) {
        $entry['data'] = is_array($data) ? json_encode($data, JSON_PRETTY_PRINT) : $data;
    }
    $traceLog[] = $entry;
    echo "\n🔍 [" . date('H:i:s') . "] {$step}\n";
    if ($data !== null) {
        $display = is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT);
        if (strlen($display) > 800) {
            $display = substr($display, 0, 800) . "... (truncated)";
        }
        echo "   " . $display . "\n";
    }
}

function report(string $label, $pass, $detail = null): void {
    global $results;
    $results[] = compact('label', 'pass', 'detail');
    $mark = $pass === true ? '✅' : ($pass === false ? '❌' : 'ℹ️');
    echo "{$mark} {$label}\n";
    if ($detail !== null) {
        echo "   " . (is_string($detail) ? $detail : json_encode($detail, JSON_PRETTY_PRINT)) . "\n";
    }
}

function dbQuery(string $sql, array $params = []) {
    global $db;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dbQueryOne(string $sql, array $params = []) {
    global $db;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function dbExecute(string $sql, array $params = []) {
    global $db;
    $stmt = $db->prepare($sql);
    return $stmt->execute($params);
}

function printSection(string $title): void {
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "  " . $title . "\n";
    echo str_repeat('=', 80) . "\n";
}

// ============================================================
// STEP 1: INSPECT DATABASE SCHEMA
// ============================================================
printSection("DATABASE SCHEMA INSPECTION");

// Check required tables
$requiredTables = [
    'identity_swap_holds',
    'swap_requests',
    'deposit_transactions',
    'fee_invoices',
    'agent_destination_accounts',
    'hold_transactions'
];

foreach ($requiredTables as $table) {
    try {
        $result = dbQueryOne("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = :table)", [':table' => $table]);
        $exists = $result['exists'] ?? false;
        report("Table exists: {$table}", $exists);
    } catch (PDOException $e) {
        report("Table exists: {$table}", false, "Error checking: " . $e->getMessage());
    }
}

// Check agent destination account
try {
    $destCheck = dbQueryOne("
        SELECT id, institution, identifier, identifier_type, asset_type, status 
        FROM agent_destination_accounts 
        WHERE id = :id AND user_id = :user_id AND status = 'active' AND deleted_at IS NULL
    ", [':id' => $destinationAccountId, ':user_id' => $agentUserId]);

    if ($destCheck) {
        report("Agent destination account found", true, $destCheck);
    } else {
        report("Agent destination account NOT found or not active", false, [
            'account_id' => $destinationAccountId,
            'user_id' => $agentUserId
        ]);
    }
} catch (PDOException $e) {
    report("Agent destination account check failed", false, $e->getMessage());
}

// ============================================================
// STEP 2: CREATE TEST HOLDS
// ============================================================
printSection("STEP 2: CREATE TEST HOLDS");

$testAmounts = [500.0, 300.0, 200.0];  // Three holds to test aggregation
$createdHoldIds = [];
$createdHoldRefs = [];

trace("Creating " . count($testAmounts) . " test holds for identity: {$testIdentityValue}");

foreach ($testAmounts as $index => $amt) {
    try {
        $reference = 'TESTDEP_' . time() . '_' . $index . '_' . bin2hex(random_bytes(3));
        
        trace("Creating hold {$index}: {$amt} BWP with reference {$reference}");
        
        $result = $swapService->executeAtomicSwap([
            'swap_type' => 'IDENTITY',
            'reference' => $reference,
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'source_identifier' => $sourceIdentifier,
            'asset_type' => 'ACCOUNT',
            'amount' => $amt,
            'currency' => 'BWP',
            'identity_type' => 'national_id',
            'identity_value' => $testIdentityValue,
            'notification_phone' => $testPhone,
            'beneficiary_phone' => $testPhone,
            'user_id' => $agentUserId,
            'requester' => 'SYSTEM_TEST'
        ]);
        
        trace("Hold creation result", $result);
        
        $holdId = $result['hold_id'] ?? $result['data']['hold_id'] ?? null;
        $holdRef = $result['hold_reference'] ?? $result['data']['hold_reference'] ?? null;
        
        if ($holdId) {
            $createdHoldIds[] = $holdId;
            $createdHoldRefs[] = $holdRef;
            trace("Hold created: ID {$holdId}, Reference: {$reference}, Amount: {$amt}");
            
            // Query the hold to get the OTP PIN hash
            try {
                $dbHold = dbQueryOne("
                    SELECT hold_id, status, otp_pin_hash, otp_pin_sent_to, claim_type 
                    FROM identity_swap_holds WHERE hold_id = :id
                ", [':id' => $holdId]);
                
                if ($dbHold && $dbHold['claim_type'] === 'otp_pin') {
                    // The PIN is hashed - we need to get it from the SMS logs or message_outbox
                    // For testing, we'll retrieve it from the message_outbox table
                    $smsLog = dbQueryOne("
                        SELECT payload FROM message_outbox 
                        WHERE destination = :phone 
                        AND payload::text LIKE '%{$reference}%'
                        ORDER BY created_at DESC LIMIT 1
                    ", [':phone' => $testPhone]);
                    
                    if ($smsLog && isset($smsLog['payload'])) {
                        $payload = json_decode($smsLog['payload'], true);
                        if (isset($payload['pin'])) {
                            $generatedPins[$holdId] = $payload['pin'];
                            trace("Retrieved PIN for hold {$holdId}: {$payload['pin']}");
                        }
                    }
                }
                
                report("Hold {$holdId} created in DB with status: " . ($dbHold['status'] ?? 'unknown'), 
                       $dbHold !== false && $dbHold['status'] === 'pending',
                       ['hold_id' => $holdId, 'claim_type' => $dbHold['claim_type'] ?? 'unknown']);
            } catch (PDOException $e) {
                report("Hold {$holdId} database check failed", false, $e->getMessage());
            }
        } else {
            report("Failed to get hold_id from result", false, $result);
        }
        
        sleep(1);
        
    } catch (\Throwable $e) {
        trace("Hold creation failed: " . $e->getMessage());
        report("Create hold {$amt} BWP", false, $e->getMessage());
    }
}

$totalGross = array_sum($testAmounts);
report("Total gross amount for test: {$totalGross} BWP", count($createdHoldIds) === count($testAmounts), 
       "Created " . count($createdHoldIds) . " of " . count($testAmounts) . " holds");

if (empty($createdHoldIds)) {
    report("No holds created - cannot continue", false);
    exit(1);
}

// ============================================================
// STEP 3: GET THE IDENTITY SWAP HOLDS (for finalization)
// ============================================================
printSection("STEP 3: GET IDENTITY SWAP HOLDS FOR FINALIZATION");

try {
    $pendingHolds = dbQuery("
        SELECT * FROM identity_swap_holds
        WHERE identity_type = 'national_id' 
          AND identity_value = :identity_value
          AND status = 'pending'
          AND hold_expires_at > NOW()
        ORDER BY created_at ASC
    ", [':identity_value' => $testIdentityValue]);
    
    trace("Found " . count($pendingHolds) . " pending holds for identity");
    
    foreach ($pendingHolds as $hold) {
        trace("Hold details", [
            'hold_id' => $hold['hold_id'],
            'swap_reference' => $hold['swap_reference'],
            'amount' => $hold['amount'],
            'claim_type' => $hold['claim_type'],
            'otp_pin_sent_to' => $hold['otp_pin_sent_to'],
            'status' => $hold['status']
        ]);
    }
    
    report("All holds found and pending", count($pendingHolds) === count($createdHoldIds));
} catch (PDOException $e) {
    report("Failed to query pending holds", false, $e->getMessage());
    $pendingHolds = [];
}

// ============================================================
// STEP 4: TEST ISOLATED DEPOSIT via confirmAndFinalizeIdentitySwap
// ============================================================
printSection("STEP 4: TEST ISOLATED DEPOSIT");

trace("Testing deposit for each hold using confirmAndFinalizeIdentitySwap");

$depositResults = [];
$depositErrors = [];

foreach ($pendingHolds as $hold) {
    $holdId = $hold['hold_id'];
    $amount = (float)$hold['amount'];
    $swapRef = $hold['swap_reference'];
    
    trace("Processing hold {$holdId} - Amount: {$amount}");
    
    try {
        // Get the PIN for this hold - if we don't have it, try to find it
        $pin = $generatedPins[$holdId] ?? null;
        
        // If we don't have the PIN, try to get it from message_outbox
        if (!$pin) {
            $smsLog = dbQueryOne("
                SELECT payload FROM message_outbox 
                WHERE destination = :phone 
                AND payload::text LIKE '%{$swapRef}%'
                ORDER BY created_at DESC LIMIT 1
            ", [':phone' => $testPhone]);
            
            if ($smsLog && isset($smsLog['payload'])) {
                $payload = json_decode($smsLog['payload'], true);
                if (isset($payload['pin'])) {
                    $pin = $payload['pin'];
                    $generatedPins[$holdId] = $pin;
                }
            }
        }
        
        // If still no PIN, check if the hold has an OTP hash we can use
        if (!$pin && $hold['claim_type'] === 'otp_pin') {
            // For testing, we need the actual PIN. Since we can't reverse the hash,
            // we'll check if the PIN was stored in metadata
            $metadata = json_decode($hold['metadata'], true);
            if (isset($metadata['_test_pin'])) {
                $pin = $metadata['_test_pin'];
            }
        }
        
        if (!$pin) {
            // For OTP-based claims, we need to set a known PIN for testing
            // Since the system generates a random PIN, we'll use a known one for this test
            $pin = '123456';  // Known test PIN
            trace("WARNING: Using fallback PIN '123456' for hold {$holdId}");
        }
        
        trace("Using PIN: {$pin} for hold {$holdId}");
        
        // Build confirmation payload for deposit
        $confirmationPayload = [
            'swap_reference' => $swapRef,
            'pin' => $pin,
            'confirmed_by_type' => 'agent',
            'confirmed_by_id' => $agentUserId,
            'identity_document_verified' => true,
            'destination_type' => 'DEPOSIT',
            'destination_institution' => $destCheck['institution'] ?? 'ZURUBANK',
            'destination_identifier' => $destCheck['identifier'] ?? '10000001',
            'destination_identifier_type' => $destCheck['identifier_type'] ?? 'account_number',
            'destination_asset_type' => $destCheck['asset_type'] ?? 'ACCOUNT',
            'client_phone' => $testPhone,
            'beneficiary_phone' => $testPhone,
        ];
        
        trace("Confirmation payload", $confirmationPayload);
        
        // Use the PUBLIC method confirmAndFinalizeIdentitySwap
        $result = $swapService->confirmAndFinalizeIdentitySwap($confirmationPayload);
        
        trace("Deposit result for hold {$holdId}", $result);
        
        $depositResults[] = [
            'hold_id' => $holdId,
            'swap_reference' => $swapRef,
            'gross_amount' => $amount,
            'net_amount' => $result['amount'] ?? $amount,
            'status' => 'success',
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'result' => $result
        ];
        
        report("Hold {$holdId} deposit SUCCESS", true, [
            'amount' => $amount,
            'result_amount' => $result['amount'] ?? $amount
        ]);
        
    } catch (\Throwable $e) {
        trace("Deposit failed for hold {$holdId}: " . $e->getMessage());
        
        $depositErrors[] = [
            'hold_id' => $holdId,
            'swap_reference' => $swapRef,
            'gross_amount' => $amount,
            'error' => $e->getMessage()
        ];
        
        report("Hold {$holdId} deposit FAILED", false, $e->getMessage());
    }
}

// ============================================================
// STEP 5: TEST AGGREGATED CLAIM
// ============================================================
printSection("STEP 5: TEST AGGREGATED CLAIM");

trace("Testing finalizeAggregatedIdentityClaim with all holds");

$cashNowRequested = 400.0;
$claimResult = null;
$claimError = null;

// Get a PIN for the aggregated claim - use the first hold's PIN
$firstHold = $pendingHolds[0] ?? null;
$aggPin = $firstHold ? ($generatedPins[$firstHold['hold_id']] ?? '123456') : '123456';

trace("Using PIN for aggregated claim: {$aggPin}");

try {
    $claimResult = $swapService->finalizeAggregatedIdentityClaim(
        'national_id',
        $testIdentityValue,
        $aggPin,
        'agent',
        $agentUserId,
        $destinationAccountId,
        $cashNowRequested,
        $agentUserId
    );
    
    trace("Aggregated claim result", $claimResult);
    report("finalizeAggregatedIdentityClaim executed", $claimResult !== null);
    
    if ($claimResult) {
        $gross = $claimResult['actually_claimed_gross'] ?? 0;
        $net = $claimResult['actually_claimed_net'] ?? 0;
        $cashNow = $claimResult['cash_now_amount'] ?? 0;
        $remainder = $claimResult['remainder_reswap']['amount'] ?? 0;
        $swapCount = $claimResult['swap_count'] ?? 0;
        $successfulDeposits = count($claimResult['successful_deposits'] ?? []);
        $failedDeposits = count($claimResult['failed_deposits'] ?? []);
        
        report("Gross amount: {$gross} BWP", $gross > 0);
        report("Net amount: {$net} BWP", $net > 0);
        report("Cash now: {$cashNow} BWP", $cashNow >= 0);
        report("Remainder: {$remainder} BWP", $remainder >= 0);
        report("Swap count: {$swapCount}", $swapCount === count($pendingHolds));
        report("Successful deposits: {$successfulDeposits}", $successfulDeposits > 0);
        report("Failed deposits: {$failedDeposits}", $failedDeposits === 0);
        
        // CRITICAL CHECK: Verify remainder calculation
        $expectedRemainder = round($net - $cashNow, 2);
        $buggyRemainder = round($gross - $cashNow, 2);
        
        report("Remainder = NET - cash_now: {$remainder} = {$expectedRemainder}",
               abs($remainder - $expectedRemainder) < 0.01,
               "Difference: " . abs($remainder - $expectedRemainder));
        
        report("Remainder != GROSS - cash_now (bug check): {$remainder} != {$buggyRemainder}",
               abs($remainder - $buggyRemainder) > 0.01,
               "Difference: " . abs($remainder - $buggyRemainder));
    }
    
} catch (\Throwable $e) {
    $claimError = $e;
    trace("finalizeAggregatedIdentityClaim THREW EXCEPTION: " . $e->getMessage());
    report("Aggregated claim failed", false, $e->getMessage());
}

// ============================================================
// STEP 6: VERIFY DATABASE STATE
// ============================================================
printSection("STEP 6: VERIFY DATABASE STATE");

// Check holds status
try {
    $finalHolds = dbQuery("
        SELECT hold_id, swap_reference, amount, currency, status,
               confirmed_at, completed_at, updated_at, hold_expires_at
        FROM identity_swap_holds
        WHERE identity_type = 'national_id' AND identity_value = :val
        ORDER BY created_at
    ", [':val' => $testIdentityValue]);
    
    trace("Found " . count($finalHolds) . " final holds for identity");
    
    $stuckConfirmed = 0;
    $completedCount = 0;
    $pendingCount = 0;
    
    foreach ($finalHolds as $hold) {
        $status = $hold['status'];
        
        report("Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$status}",
               in_array($status, ['completed', 'pending', 'expired']),
               "Ref: {$hold['swap_reference']}");
        
        if ($status === 'confirmed') {
            $stuckConfirmed++;
            report("⚠️ HOLD STUCK AT 'confirmed'", false, $hold);
        } elseif ($status === 'completed') {
            $completedCount++;
        } elseif ($status === 'pending') {
            $pendingCount++;
        }
    }
    
    report("Zero holds stuck at 'confirmed'", $stuckConfirmed === 0, "{$stuckConfirmed} stuck");
    report("Completed holds: {$completedCount}", true);
    report("Pending holds: {$pendingCount}", true);
    
} catch (PDOException $e) {
    report("Failed to query final holds", false, $e->getMessage());
}

// Check deposit_transactions
try {
    $deposits = dbQuery("
        SELECT transaction_reference, client_phone, amount, currency, status, created_at
        FROM deposit_transactions
        WHERE transaction_reference LIKE 'TESTDEP_%' 
           OR transaction_reference LIKE 'SWAP_REMAINDER_%'
        ORDER BY created_at DESC LIMIT 10
    ");
    
    trace("Found " . count($deposits) . " recent deposit transactions");
    foreach ($deposits as $deposit) {
        report("Deposit: {$deposit['transaction_reference']}, {$deposit['amount']} {$deposit['currency']}, status: {$deposit['status']}",
               in_array($deposit['status'], ['completed', 'COMPLETED']),
               $deposit);
    }
} catch (PDOException $e) {
    report("Failed to query deposits", false, $e->getMessage());
}

// ============================================================
// STEP 7: DIAGNOSTIC ANALYSIS
// ============================================================
printSection("STEP 7: DIAGNOSTIC ANALYSIS");

echo "🔬 DIAGNOSTIC CHECKLIST:\n\n";

// Check 1: PIN verification
echo "1. PIN Verification:\n";
if (!empty($generatedPins)) {
    echo "   ✅ Generated " . count($generatedPins) . " PINs\n";
    foreach ($generatedPins as $holdId => $pin) {
        echo "      Hold {$holdId}: {$pin}\n";
    }
} else {
    echo "   ⚠️ No PINs were retrieved. Check message_outbox table.\n";
}

if (!empty($depositErrors)) {
    $pinErrors = array_filter($depositErrors, function($e) {
        return stripos($e['error'], 'PIN') !== false;
    });
    if (!empty($pinErrors)) {
        echo "   ❌ PIN verification failed for " . count($pinErrors) . " holds\n";
    }
}

// Check 2: Destination account
echo "\n2. Destination Account:\n";
if (isset($destCheck) && $destCheck) {
    echo "   ✅ {$destCheck['institution']} - {$destCheck['identifier']}\n";
} else {
    echo "   ❌ Not found\n";
}

// Check 3: Holds stuck
echo "\n3. Holds Status:\n";
if (isset($stuckConfirmed) && $stuckConfirmed > 0) {
    echo "   ❌ {$stuckConfirmed} hold(s) stuck at 'confirmed'\n";
} else {
    echo "   ✅ No holds stuck\n";
}

// Check 4: Remainder math
echo "\n4. Remainder Math:\n";
if ($claimResult && isset($claimResult['remainder_reswap'])) {
    $gross = $claimResult['actually_claimed_gross'] ?? 0;
    $net = $claimResult['actually_claimed_net'] ?? 0;
    $cashNow = $claimResult['cash_now_amount'] ?? 0;
    $remainder = $claimResult['remainder_reswap']['amount'] ?? 0;
    
    $expectedRemainder = round($net - $cashNow, 2);
    $buggyRemainder = round($gross - $cashNow, 2);
    
    if (abs($remainder - $expectedRemainder) < 0.01) {
        echo "   ✅ CORRECT (NET - cash_now)\n";
    } elseif (abs($remainder - $buggyRemainder) < 0.01) {
        echo "   ❌ BUG: Uses GROSS not NET\n";
        echo "      GROSS: {$gross}, NET: {$net}, Remainder: {$remainder}\n";
        echo "      Expected: {$expectedRemainder}, Buggy: {$buggyRemainder}\n";
    }
} else {
    echo "   ⚠️ No remainder data\n";
}

// ============================================================
// STEP 8: SUMMARY
// ============================================================
printSection("FINAL SUMMARY");

$passed = array_filter($results, fn($r) => $r['pass'] === true);
$failed = array_filter($results, fn($r) => $r['pass'] === false);

echo "✅ Passed: " . count($passed) . "\n";
echo "❌ Failed: " . count($failed) . "\n";

if (!empty($failed)) {
    echo "\n--- FAILURES ---\n";
    foreach ($failed as $f) {
        echo "  ❌ {$f['label']}\n";
        if (isset($f['detail'])) {
            $detail = is_string($f['detail']) ? $f['detail'] : json_encode($f['detail'], JSON_PRETTY_PRINT);
            echo "     " . $detail . "\n";
        }
    }
}

// ============================================================
// STEP 9: CLEANUP
// ============================================================
printSection("CLEANUP");

echo "Run these SQL commands to clean up:\n\n";
echo "DELETE FROM identity_swap_holds WHERE identity_value = '{$testIdentityValue}';\n";
echo "DELETE FROM swap_requests WHERE swap_uuid LIKE 'TESTDEP_%' OR swap_uuid LIKE 'SWAP_REMAINDER_%';\n";
echo "DELETE FROM deposit_transactions WHERE transaction_reference LIKE 'TESTDEP_%' OR transaction_reference LIKE 'SWAP_REMAINDER_%';\n";
echo "DELETE FROM fee_invoices WHERE swap_reference LIKE 'TESTDEP_%' OR swap_reference LIKE 'SWAP_REMAINDER_%';\n";
echo "DELETE FROM hold_transactions WHERE swap_reference LIKE 'TESTDEP_%' OR swap_reference LIKE 'SWAP_REMAINDER_%';\n";

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";
