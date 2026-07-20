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
$config = LoadCountry::getConfig();  // FIXED: Changed from loadConfig() to getConfig()
$swapService = new SwapService($db, $config, $country);

// Test Identity - unique per run to avoid conflicts
$testIdentityValue = 'TEST_DEPOSIT_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$testPhone = '+26770000000';
$sourceInstitution = 'ZURUBANK';
$sourceIdentifier = '10000001';
$destinationAccountId = 7;  // Must exist in agent_destination_accounts
$agentUserId = 12;          // Must exist in users table
$testPin = '493282';        // This would come from SMS

$results = [];
$traceLog = [];
$testHoldIds = [];

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
        echo "   " . (is_array($data) ? json_encode($data, JSON_PRETTY_PRINT) : $data) . "\n";
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
    'settlement_obligations',
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
        // Don't exit - let the test continue to show all issues
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
        
        // Create a hold using executeAtomicSwap with IDENTITY type
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
        
        // Store the hold_id - check multiple possible locations
        $holdId = null;
        if (isset($result['hold_id'])) {
            $holdId = $result['hold_id'];
        } elseif (isset($result['data']['hold_id'])) {
            $holdId = $result['data']['hold_id'];
        }
        
        $holdRef = null;
        if (isset($result['hold_reference'])) {
            $holdRef = $result['hold_reference'];
        } elseif (isset($result['data']['hold_reference'])) {
            $holdRef = $result['data']['hold_reference'];
        }
        
        if ($holdId) {
            $createdHoldIds[] = $holdId;
            $createdHoldRefs[] = $holdRef;
            trace("Hold created: ID {$holdId}, Reference: {$reference}, Amount: {$amt}");
            
            // Verify in database immediately
            try {
                $dbHold = dbQueryOne("SELECT * FROM identity_swap_holds WHERE hold_id = :id", [':id' => $holdId]);
                report("Hold {$holdId} created in DB with status: " . ($dbHold['status'] ?? 'unknown'), 
                       $dbHold !== false && $dbHold['status'] === 'pending',
                       $dbHold ?: 'No record found');
            } catch (PDOException $e) {
                report("Hold {$holdId} database check failed", false, $e->getMessage());
            }
        } else {
            report("Failed to get hold_id from result", false, $result);
        }
        
        sleep(1); // Ensure distinct timestamps
        
    } catch (\Throwable $e) {
        trace("Hold creation failed: " . $e->getMessage());
        trace("Exception trace: " . $e->getTraceAsString());
        report("Create hold {$amt} BWP", false, $e->getMessage());
    }
}

$totalGross = array_sum($testAmounts);
report("Total gross amount for test: {$totalGross} BWP", count($createdHoldIds) === count($testAmounts), 
       "Created " . count($createdHoldIds) . " of " . count($testAmounts) . " holds");

if (empty($createdHoldIds)) {
    report("No holds created - cannot continue", false);
    echo "\n⚠️ No holds were created. Check:\n";
    echo "   - ZURUBANK adapter is working\n";
    echo "   - The source account {$sourceIdentifier} exists and has funds\n";
    echo "   - The SwapService is properly initialized\n";
    exit(1);
}

// ============================================================
// STEP 3: VERIFY HOLDS IN DATABASE
// ============================================================
printSection("STEP 3: VERIFY HOLDS IN DATABASE");

$placeholders = implode(',', array_fill(0, count($createdHoldIds), '?'));
try {
    $holds = dbQuery("
        SELECT hold_id, swap_reference, amount, currency, status, 
               identity_type, identity_value, created_at, hold_expires_at,
               otp_pin_sent_at, otp_pin_sent_to, source_institution, source_identifier
        FROM identity_swap_holds 
        WHERE hold_id IN ({$placeholders})
        ORDER BY created_at
    ", $createdHoldIds);
    
    trace("Found " . count($holds) . " holds in database");
    
    $totalDbAmount = 0;
    foreach ($holds as $hold) {
        $totalDbAmount += (float)$hold['amount'];
        $statusOk = in_array($hold['status'], ['pending', 'confirmed', 'completed', 'debited']);
        report("Hold {$hold['hold_id']}: {$hold['amount']} {$hold['currency']}, status: {$hold['status']}",
               $statusOk,
               "Ref: {$hold['swap_reference']}, Expires: {$hold['hold_expires_at']}");
    }
    
    report("Total amount in DB matches expected: {$totalDbAmount} = {$totalGross}", 
           abs($totalDbAmount - $totalGross) < 0.01);
} catch (PDOException $e) {
    report("Failed to query holds", false, $e->getMessage());
    $holds = [];
}

// ============================================================
// STEP 4: GET THE IDENTITY SWAP HOLDS (for finalization)
// ============================================================
printSection("STEP 4: GET IDENTITY SWAP HOLDS FOR FINALIZATION");

try {
    // Query all pending holds for this identity
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
            'source_institution' => $hold['source_institution'],
            'source_identifier' => $hold['source_identifier'],
            'status' => $hold['status']
        ]);
    }
    
    report("All holds found and pending", count($pendingHolds) === count($createdHoldIds));
} catch (PDOException $e) {
    report("Failed to query pending holds", false, $e->getMessage());
    $pendingHolds = [];
}

// ============================================================
// STEP 5: TEST ISOLATED DEPOSIT - Method 1: Direct deposit
// ============================================================
printSection("STEP 5: TEST ISOLATED DEPOSIT (DIRECT)");

trace("Testing direct deposit for each hold individually");

$depositResults = [];
$depositErrors = [];

foreach ($pendingHolds as $hold) {
    $holdId = $hold['hold_id'];
    $amount = (float)$hold['amount'];
    
    trace("Processing hold {$holdId} - Amount: {$amount}");
    
    try {
        // Build confirmation payload for deposit
        $confirmationPayload = [
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
            'pin' => $testPin,  // PIN for verification
        ];
        
        trace("Confirmation payload", $confirmationPayload);
        
        // Call the method directly
        $result = $swapService->finalizeIdentityHoldNoPin($hold, $confirmationPayload);
        
        trace("Deposit result for hold {$holdId}", $result);
        
        $depositResults[] = [
            'hold_id' => $holdId,
            'swap_reference' => $hold['swap_reference'],
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
        trace("Exception trace: " . $e->getTraceAsString());
        
        $depositErrors[] = [
            'hold_id' => $holdId,
            'swap_reference' => $hold['swap_reference'],
            'gross_amount' => $amount,
            'error' => $e->getMessage()
        ];
        
        report("Hold {$holdId} deposit FAILED", false, $e->getMessage());
    }
}

// ============================================================
// STEP 6: TEST AGGREGATED CLAIM (all holds at once)
// ============================================================
printSection("STEP 6: TEST AGGREGATED CLAIM");

trace("Testing finalizeAggregatedIdentityClaim with all holds");

$cashNowRequested = 400.0;
$claimResult = null;
$claimError = null;

try {
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
    
    trace("Aggregated claim result", $claimResult);
    report("finalizeAggregatedIdentityClaim executed", $claimResult !== null);
    
    // Analyze the result
    if ($claimResult) {
        $gross = $claimResult['actually_claimed_gross'] ?? 0;
        $net = $claimResult['actually_claimed_net'] ?? 0;
        $cashNow = $claimResult['cash_now_amount'] ?? 0;
        $remainder = $claimResult['remainder_reswap']['amount'] ?? 0;
        
        report("Gross amount: {$gross} BWP", $gross > 0);
        report("Net amount: {$net} BWP", $net > 0);
        report("Cash now: {$cashNow} BWP", $cashNow >= 0);
        report("Remainder: {$remainder} BWP", $remainder >= 0);
        
        // CRITICAL CHECK: Verify remainder calculation
        $expectedRemainder = round($net - $cashNow, 2);
        $buggyRemainder = round($gross - $cashNow, 2);
        
        report("Remainder = NET - cash_now: {$remainder} = {$expectedRemainder}",
               abs($remainder - $expectedRemainder) < 0.01,
               "Difference: " . abs($remainder - $expectedRemainder));
        
        report("Remainder != GROSS - cash_now (bug check): {$remainder} != {$buggyRemainder}",
               abs($remainder - $buggyRemainder) > 0.01,
               "Difference: " . abs($remainder - $buggyRemainder));
        
        // Check successful deposits
        $successfulCount = count($claimResult['successful_deposits'] ?? []);
        $failedCount = count($claimResult['failed_deposits'] ?? []);
        report("Successful deposits: {$successfulCount}", $successfulCount > 0);
        report("Failed deposits: {$failedCount}", $failedCount === 0);
    }
    
} catch (\Throwable $e) {
    $claimError = $e;
    trace("finalizeAggregatedIdentityClaim THREW EXCEPTION: " . $e->getMessage());
    trace("Exception trace: " . $e->getTraceAsString());
    report("Aggregated claim failed", false, $e->getMessage());
}

// ============================================================
// STEP 7: VERIFY DATABASE STATE AFTER CLAIM
// ============================================================
printSection("STEP 7: VERIFY DATABASE STATE AFTER CLAIM");

try {
    // Check all holds for this identity
    $finalHolds = dbQuery("
        SELECT hold_id, swap_reference, amount, currency, status,
               confirmed_at, debited_at, completed_at, 
               updated_at, hold_expires_at
        FROM identity_swap_holds
        WHERE identity_type = 'national_id' AND identity_value = :val
        ORDER BY created_at
    ", [':val' => $testIdentityValue]);
    
    trace("Found " . count($finalHolds) . " final holds for identity");
    
    $stuckConfirmed = 0;
    $completedCount = 0;
    $pendingCount = 0;
    $failedCount = 0;
    
    foreach ($finalHolds as $hold) {
        $status = $hold['status'];
        
        report("Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$status}",
               in_array($status, ['completed', 'pending', 'failed', 'debited', 'expired']),
               "Ref: {$hold['swap_reference']}, Updated: {$hold['updated_at']}");
        
        if ($status === 'confirmed') {
            $stuckConfirmed++;
            report("⚠️ HOLD STUCK AT 'confirmed' - NEEDS FIX", false, $hold);
        } elseif ($status === 'completed') {
            $completedCount++;
        } elseif ($status === 'pending') {
            $pendingCount++;
        } elseif ($status === 'failed') {
            $failedCount++;
        }
    }
    
    report("Zero holds stuck at 'confirmed'", $stuckConfirmed === 0, "{$stuckConfirmed} stuck");
    report("Completed holds: {$completedCount}", true);
    report("Pending holds: {$pendingCount}", true);
    report("Failed holds: {$failedCount}", true);
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
           OR transaction_reference LIKE 'AGG_REMAIN_%'
        ORDER BY created_at DESC
    ");
    
    trace("Found " . count($deposits) . " deposit transactions");
    foreach ($deposits as $deposit) {
        report("Deposit: {$deposit['transaction_reference']}, {$deposit['amount']} {$deposit['currency']}, status: {$deposit['status']}",
               in_array($deposit['status'], ['completed', 'pending', 'COMPLETED']),
               $deposit);
    }
} catch (PDOException $e) {
    report("Failed to query deposits", false, $e->getMessage());
}

// Check settlement_obligations
try {
    $obligations = dbQuery("
        SELECT instruction_id, swap_reference, debtor, creditor, amount, currency
        FROM settlement_obligations
        WHERE swap_reference LIKE 'TESTDEP_%' 
           OR swap_reference LIKE 'SWAP_REMAINDER_%'
           OR swap_reference LIKE 'AGG_REMAIN_%'
        ORDER BY created_at DESC
    ");
    
    trace("Found " . count($obligations) . " settlement obligations");
    foreach ($obligations as $obligation) {
        report("Settlement: {$obligation['debtor']} owes {$obligation['creditor']} {$obligation['amount']} {$obligation['currency']}",
               true,
               $obligation);
    }
} catch (PDOException $e) {
    report("Failed to query settlement obligations", false, $e->getMessage());
}

// Check fee_invoices
try {
    $invoices = dbQuery("
        SELECT invoice_uuid, swap_reference, fee_type, fee_amount, total_amount, currency
        FROM fee_invoices
        WHERE swap_reference LIKE 'TESTDEP_%' 
           OR swap_reference LIKE 'SWAP_REMAINDER_%'
           OR swap_reference LIKE 'AGG_REMAIN_%'
        ORDER BY created_at DESC
    ");
    
    trace("Found " . count($invoices) . " fee invoices");
    foreach ($invoices as $invoice) {
        report("Invoice: {$invoice['fee_type']} = {$invoice['fee_amount']} + VAT = {$invoice['total_amount']} {$invoice['currency']}",
               true,
               $invoice);
    }
} catch (PDOException $e) {
    report("Failed to query fee invoices", false, $e->getMessage());
}

// ============================================================
// STEP 8: VERIFY MATH COMPUTATION
// ============================================================
printSection("STEP 8: VERIFY MATH COMPUTATION");

try {
    // Get all holds with their statuses
    $allHolds = dbQuery("
        SELECT hold_id, amount, status, confirmed_at, debited_at, completed_at
        FROM identity_swap_holds
        WHERE identity_type = 'national_id' AND identity_value = :val
        ORDER BY created_at
    ", [':val' => $testIdentityValue]);
    
    $grossTotal = 0;
    $netTotal = 0;
    $completedGross = 0;
    $pendingGross = 0;
    
    foreach ($allHolds as $hold) {
        $amount = (float)$hold['amount'];
        $grossTotal += $amount;
        
        if (in_array($hold['status'], ['completed', 'debited'])) {
            $completedGross += $amount;
            // Get deposit amount for this hold
            $deposit = dbQueryOne("
                SELECT amount FROM deposit_transactions 
                WHERE transaction_reference = (
                    SELECT swap_reference FROM identity_swap_holds WHERE hold_id = :hid
                )
            ", [':hid' => $hold['hold_id']]);
            
            if ($deposit) {
                $netTotal += (float)$deposit['amount'];
            }
        } elseif ($hold['status'] === 'pending') {
            $pendingGross += $amount;
        }
    }
    
    trace("Math Verification");
    report("Total gross amount: {$grossTotal} BWP", true);
    report("Completed gross: {$completedGross} BWP", true);
    report("Pending gross: {$pendingGross} BWP", true);
    report("Net deposited: {$netTotal} BWP", $netTotal > 0);
    
    if ($completedGross > 0) {
        $impliedFee = $completedGross - $netTotal;
        report("Implied total fee: {$impliedFee} BWP", $impliedFee >= 0);
    }
} catch (PDOException $e) {
    report("Math verification failed", false, $e->getMessage());
}

// ============================================================
// STEP 9: DIAGNOSTIC SECTION - Where things go wrong
// ============================================================
printSection("STEP 9: DIAGNOSTIC ANALYSIS");

echo "🔬 DIAGNOSTIC CHECKLIST:\n\n";

// Check 1: PIN verification
echo "1. PIN Verification:\n";
if (!empty($depositErrors)) {
    $pinErrors = array_filter($depositErrors, function($e) {
        return stripos($e['error'], 'PIN') !== false || stripos($e['error'], 'pin') !== false;
    });
    if (!empty($pinErrors)) {
        echo "   ❌ PIN verification failed for " . count($pinErrors) . " holds\n";
        echo "      Test PIN: {$testPin}\n";
        echo "      Check: The PIN must match what was sent via SMS\n";
        echo "      Check: identity_swap_holds.otp_pin_hash must exist\n";
    } else {
        echo "   ✅ No PIN-related errors\n";
    }
} else {
    echo "   ✅ No deposit errors to analyze\n";
}

// Check 2: Destination account
echo "\n2. Destination Account:\n";
if (isset($destCheck) && $destCheck) {
    echo "   ✅ Destination account found: {$destCheck['institution']} - {$destCheck['identifier']}\n";
} else {
    echo "   ❌ Destination account NOT found\n";
}

// Check 3: Holds stuck at 'confirmed'
echo "\n3. Holds Stuck at 'confirmed':\n";
if (isset($stuckConfirmed) && $stuckConfirmed > 0) {
    echo "   ❌ {$stuckConfirmed} hold(s) stuck at 'confirmed'\n";
    echo "      This indicates a transaction-ordering bug\n";
    echo "      Check: finalizeIdentityHoldNoPin() transaction boundaries\n";
    echo "      Check: The hold status should transition: pending → confirmed → debited → completed\n";
} else {
    echo "   ✅ No holds stuck at 'confirmed'\n";
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
        echo "   ✅ Remainder calculation is CORRECT (NET - cash_now)\n";
    } elseif (abs($remainder - $buggyRemainder) < 0.01) {
        echo "   ❌ Remainder calculation uses GROSS (bug) - should use NET\n";
        echo "      GROSS: {$gross}, NET: {$net}, Remainder: {$remainder}\n";
        echo "      Expected: {$expectedRemainder}, Buggy: {$buggyRemainder}\n";
    } else {
        echo "   ⚠️ Remainder calculation is using an unknown formula\n";
        echo "      Gross: {$gross}, Net: {$net}, CashNow: {$cashNow}, Remainder: {$remainder}\n";
    }
} else {
    echo "   ⚠️ No remainder reswap data available\n";
}

// Check 5: Fee calculation
echo "\n5. Fee Calculation:\n";
if (isset($invoices) && !empty($invoices)) {
    $totalFees = 0;
    foreach ($invoices as $invoice) {
        $totalFees += (float)$invoice['total_amount'];
    }
    if ($totalFees > 0) {
        echo "   ✅ Total fees collected: {$totalFees} BWP\n";
    } else {
        echo "   ⚠️ No fees found - check fee configuration\n";
    }
} else {
    echo "   ⚠️ No fee invoices found\n";
}

// ============================================================
// STEP 10: TRACE COMPLETE FLOW
// ============================================================
printSection("COMPLETE FLOW TRACE");

$stepCount = 1;
foreach ($traceLog as $entry) {
    $time = date('H:i:s', (int)$entry['time']);
    $data = $entry['data'] ?? '';
    echo "{$stepCount}. [{$time}] {$entry['step']}\n";
    if ($data) {
        $display = is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT);
        // Truncate long data for readability
        if (strlen($display) > 500) {
            $display = substr($display, 0, 500) . "... (truncated)";
        }
        echo "   " . $display . "\n";
    }
    $stepCount++;
}

// ============================================================
// STEP 11: SUMMARY
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
// STEP 12: CLEANUP INSTRUCTIONS
// ============================================================
printSection("CLEANUP INSTRUCTIONS");

echo "To clean up test data, run these SQL commands:\n\n";
echo "-- Delete identity swap holds\n";
echo "DELETE FROM identity_swap_holds WHERE identity_value = '{$testIdentityValue}';\n\n";
echo "-- Delete swap requests\n";
echo "DELETE FROM swap_requests WHERE swap_uuid LIKE 'TESTDEP_%' OR swap_uuid LIKE 'SWAP_REMAINDER_%' OR swap_uuid LIKE 'AGG_REMAIN_%';\n\n";
echo "-- Delete deposit transactions\n";
echo "DELETE FROM deposit_transactions WHERE transaction_reference LIKE 'TESTDEP_%' OR transaction_reference LIKE 'SWAP_REMAINDER_%' OR transaction_reference LIKE 'AGG_REMAIN_%';\n\n";
echo "-- Delete settlement obligations\n";
echo "DELETE FROM settlement_obligations WHERE swap_reference LIKE 'TESTDEP_%' OR swap_reference LIKE 'SWAP_REMAINDER_%' OR swap_reference LIKE 'AGG_REMAIN_%';\n\n";
echo "-- Delete fee invoices\n";
echo "DELETE FROM fee_invoices WHERE swap_reference LIKE 'TESTDEP_%' OR swap_reference LIKE 'SWAP_REMAINDER_%' OR swap_reference LIKE 'AGG_REMAIN_%';\n\n";

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";

// ============================================================
// STEP 13: RECOMMENDATIONS
// ============================================================
printSection("RECOMMENDATIONS");

if (isset($stuckConfirmed) && $stuckConfirmed > 0) {
    echo "🔧 FIX: Holds stuck at 'confirmed'\n";
    echo "   - The transaction boundary in finalizeIdentityHoldNoPin() needs review\n";
    echo "   - The hold status should be updated WITHIN the transaction\n";
    echo "   - Check: beginAtomicSwap() should be called BEFORE updating the hold status\n\n";
}

if (!empty($depositErrors)) {
    echo "🔧 FIX: Deposit failures\n";
    echo "   - Check that the PIN matches the one sent via SMS\n";
    echo "   - Check that the destination account is active\n";
    echo "   - Check that the source hold is still valid\n\n";
}

echo "🔧 KEY INSIGHT: The deposit method (finalizeIdentityHoldNoPin) is the core\n";
echo "   of the operation. All holds must be deposited into the agent account\n";
echo "   FIRST, then the remainder is re-swapped to the identity.\n\n";

echo "🔧 To test the deposit method in isolation:\n";
echo "   1. Create holds using initiateSwapToIdentity()\n";
echo "   2. Call finalizeIdentityHoldNoPin() directly with DEPOSIT destination_type\n";
echo "   3. Verify the deposit_transactions table has entries\n";
echo "   4. Verify the hold status changes to 'completed'\n\n";

echo "Test completed at " . date('Y-m-d H:i:s') . "\n";
