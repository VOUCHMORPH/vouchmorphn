<?php
/**
 * test_aggregated_claim_flow.php
 * 
 * Comprehensive test that traces the complete flow of:
 * 1. Creating test holds
 * 2. Searching for claims (search_claim.php)
 * 3. Finalizing claims (finalize_claim.php)
 * 4. Verifying math and database state at each step
 * 
 * This test doesn't just check final results - it traces EVERY step
 * to identify exactly where failures occur.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\Security\CertificateManager;

// ============================================================
// CONFIGURATION
// ============================================================
$country = 'Botswana';
$db = DBConnection::getConnection();
$config = LoadCountry::loadConfig($country);
$swapService = new SwapService($db, $config, $country);

// Test Identity - unique per run to avoid conflicts
$testIdentityValue = 'TEST_MATH_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$testPhone = '+26770000000';
$sourceInstitution = 'ZURUBANK';
$sourceIdentifier = '10000001';
$destinationAccountId = 7;  // Must exist in agent_destination_accounts
$agentUserId = 12;          // Must exist in users table
$testPin = '493282';        // This would come from SMS

$results = [];
$traceLog = [];

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

// ============================================================
// STEP 1: INSPECT DATABASE SCHEMA
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "DATABASE SCHEMA INSPECTION\n";
echo str_repeat('=', 80) . "\n";

// Check if required tables exist
$tables = [
    'identity_swap_holds',
    'swap_requests', 
    'deposit_transactions',
    'settlement_obligations',
    'fee_invoices',
    'agent_destination_accounts'
];

foreach ($tables as $table) {
    $result = dbQueryOne("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = :table)", [':table' => $table]);
    $exists = $result['exists'] ?? false;
    report("Table exists: {$table}", $exists);
    if ($exists) {
        // Show table structure
        $columns = dbQuery("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = :table ORDER BY ordinal_position", [':table' => $table]);
        echo "   Columns: " . implode(', ', array_column($columns, 'column_name')) . "\n";
    }
}

// ============================================================
// STEP 2: CREATE TEST HOLDS
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "STEP 2: CREATE TEST HOLDS\n";
echo str_repeat('=', 80) . "\n";

$testAmounts = [500.0, 300.0];
$createdHoldIds = [];

trace("Creating " . count($testAmounts) . " test holds for identity: {$testIdentityValue}");

foreach ($testAmounts as $index => $amt) {
    try {
        $reference = 'TESTMATH_' . time() . '_' . bin2hex(random_bytes(3));
        
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
        
        // Store the hold_id
        $holdId = $result['hold_id'] ?? null;
        if ($holdId) {
            $createdHoldIds[] = $holdId;
            trace("Hold created: ID {$holdId}, Reference: {$reference}, Amount: {$amt}");
            
            // Verify in database immediately
            $dbHold = dbQueryOne("SELECT * FROM identity_swap_holds WHERE hold_id = :id", [':id' => $holdId]);
            report("Hold {$holdId} created in DB with status: " . ($dbHold['status'] ?? 'unknown'), 
                   $dbHold !== false && $dbHold['status'] === 'pending',
                   $dbHold);
        } else {
            report("Failed to get hold_id from result", false, $result);
        }
        
        sleep(1); // Ensure distinct timestamps
        
    } catch (\Throwable $e) {
        trace("Hold creation failed: " . $e->getMessage());
        report("Create hold {$amt} BWP", false, $e->getMessage());
    }
}

$totalGross = array_sum($testAmounts);
report("Total gross amount for test: {$totalGross} BWP", count($createdHoldIds) === count($testAmounts), 
       "Created " . count($createdHoldIds) . " of " . count($testAmounts) . " holds");

// ============================================================
// STEP 3: VERIFY HOLDS ARE IN DATABASE WITH CORRECT DATA
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "STEP 3: VERIFY HOLDS IN DATABASE\n";
echo str_repeat('=', 80) . "\n";

if (!empty($createdHoldIds)) {
    $placeholders = implode(',', array_fill(0, count($createdHoldIds), '?'));
    $holds = dbQuery("
        SELECT hold_id, swap_reference, amount, currency, status, 
               identity_type, identity_value, created_at, hold_expires_at,
               otp_pin_sent_at, otp_pin_sent_to
        FROM identity_swap_holds 
        WHERE hold_id IN ({$placeholders})
        ORDER BY created_at
    ", $createdHoldIds);
    
    trace("Found " . count($holds) . " holds in database");
    
    $totalDbAmount = 0;
    foreach ($holds as $hold) {
        $totalDbAmount += (float)$hold['amount'];
        report("Hold {$hold['hold_id']}: {$hold['amount']} {$hold['currency']}, status: {$hold['status']}",
               $hold['status'] === 'pending',
               "Ref: {$hold['swap_reference']}, Expires: {$hold['hold_expires_at']}");
    }
    
    report("Total amount in DB matches expected: {$totalDbAmount} = {$totalGross}", 
           abs($totalDbAmount - $totalGross) < 0.01);
}

// ============================================================
// STEP 4: SIMULATE search_claim.php
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "STEP 4: SIMULATE search_claim.php\n";
echo str_repeat('=', 80) . "\n";

trace("Searching for claims by identity: {$testIdentityValue}");

try {
    // This is what search_claim.php would do
    $sql = "
        SELECT hold_id, swap_reference, amount, currency, 
               identity_type, identity_value, created_at, hold_expires_at,
               otp_pin_sent_at, otp_pin_sent_to
        FROM identity_swap_holds
        WHERE identity_type = :identity_type 
          AND identity_value = :identity_value
          AND status = 'pending'
          AND hold_expires_at > NOW()
        ORDER BY created_at ASC
    ";
    $searchResults = dbQuery($sql, [
        ':identity_type' => 'national_id',
        ':identity_value' => $testIdentityValue
    ]);
    
    trace("search_claim.php found " . count($searchResults) . " pending holds");
    
    $foundAll = count($searchResults) === count($createdHoldIds);
    report("Search_claim.php returns all created holds", $foundAll, 
           "Found: " . count($searchResults) . " holds, Expected: " . count($createdHoldIds));
    
    if ($foundAll) {
        foreach ($searchResults as $hold) {
            report("  - Hold {$hold['hold_id']}: {$hold['amount']} BWP", true);
        }
    }
    
} catch (\Throwable $e) {
    report("search_claim.php simulation failed", false, $e->getMessage());
}

// ============================================================
// STEP 5: SIMULATE finalize_claim.php - THE MAIN FLOW
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "STEP 5: SIMULATE finalize_claim.php\n";
echo str_repeat('=', 80) . "\n";

$cashNowRequested = 400.0;
$claimResult = null;
$claimError = null;

trace("Starting finalizeAggregatedIdentityClaim with cashNow: {$cashNowRequested}");

try {
    // This is what finalize_claim.php would call
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
    
    trace("finalizeAggregatedIdentityClaim completed", $claimResult);
    report("finalizeAggregatedIdentityClaim executed", $claimResult !== null);
    
} catch (\Throwable $e) {
    $claimError = $e;
    trace("finalizeAggregatedIdentityClaim THREW EXCEPTION", $e->getMessage());
    report("finalizeAggregatedIdentityClaim threw exception", false, [
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

// ============================================================
// STEP 6: ANALYZE CLAIM RESULT
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "STEP 6: ANALYZE CLAIM RESULT\n";
echo str_repeat('=', 80) . "\n";

if ($claimResult && isset($claimResult['status'])) {
    trace("Claim status: " . $claimResult['status']);
    
    $expectedFields = [
        'status', 'identity_type', 'identity_value', 'currency',
        'requested_full_amount', 'actually_claimed_gross',
        'total_deposited_net', 'swap_count', 'successful_deposits',
        'failed_deposits', 'cash_now_amount', 'remainder_reswap'
    ];
    
    foreach ($expectedFields as $field) {
        $hasField = array_key_exists($field, $claimResult);
        report("Response contains field: {$field}", $hasField);
        if ($hasField) {
            trace("  {$field} = " . json_encode($claimResult[$field]));
        }
    }
    
    // Check actual values
    $gross = $claimResult['actually_claimed_gross'] ?? 0;
    $net = $claimResult['total_deposited_net'] ?? 0;
    $cashNow = $claimResult['cash_now_amount'] ?? 0;
    $remainder = $claimResult['remainder_reswap']['amount'] ?? 0;
    $swapCount = $claimResult['swap_count'] ?? 0;
    
    report("Gross amount: {$gross} BWP", $gross > 0);
    report("Net amount (after fees): {$net} BWP", $net > 0);
    report("Cash now: {$cashNow} BWP", $cashNow >= 0);
    report("Remainder: {$remainder} BWP", $remainder >= 0);
    report("Swap count: {$swapCount}", $swapCount === count($createdHoldIds));
    
    // CRITICAL CHECK: Does remainder = NET - cash_now (not GROSS - cash_now)?
    $expectedRemainder = round($net - $cashNow, 2);
    $buggyRemainder = round($gross - $cashNow, 2);
    
    report("Remainder = NET - cash_now (fixed): {$remainder} = {$expectedRemainder}",
           abs($remainder - $expectedRemainder) < 0.01,
           "Difference: " . abs($remainder - $expectedRemainder));
    
    report("Remainder does NOT equal GROSS - cash_now (bug): {$remainder} != {$buggyRemainder}",
           abs($remainder - $buggyRemainder) > 0.01,
           "Difference: " . abs($remainder - $buggyRemainder));
    
    // Check successful deposits
    $successfulCount = count($claimResult['successful_deposits'] ?? []);
    $failedCount = count($claimResult['failed_deposits'] ?? []);
    
    report("Successful deposits: {$successfulCount}", $successfulCount > 0, $claimResult['successful_deposits'] ?? []);
    report("Failed deposits: {$failedCount}", $failedCount === 0, $claimResult['failed_deposits'] ?? []);
    
    // Check remainder re-swap
    if (isset($claimResult['remainder_reswap'])) {
        $remStatus = $claimResult['remainder_reswap']['status'] ?? 'unknown';
        report("Remainder re-swap status: {$remStatus}", 
               in_array($remStatus, ['completed', 'pending_identity_confirmation']),
               $claimResult['remainder_reswap']);
    }
    
} elseif ($claimError) {
    // Analyze the exception
    $errorMsg = $claimError->getMessage();
    report("Exception analysis", false, [
        'error' => $errorMsg,
        'type' => get_class($claimError)
    ]);
    
    // Check if it's a PIN error
    if (strpos($errorMsg, 'PIN') !== false || strpos($errorMsg, 'pin') !== false) {
        report("PIN verification failed - check test PIN value", false, "Test PIN: {$testPin}");
    }
    
    // Check if it's a destination account error
    if (strpos($errorMsg, 'destination') !== false) {
        report("Destination account error - check agent_destination_accounts", false, 
               "Account ID: {$destinationAccountId}, Agent: {$agentUserId}");
    }
}

// ============================================================
// STEP 7: VERIFY DATABASE STATE AFTER CLAIM
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "STEP 7: VERIFY DATABASE STATE\n";
echo str_repeat('=', 80) . "\n";

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

foreach ($finalHolds as $hold) {
    $status = $hold['status'];
    $statusOk = in_array($status, ['completed', 'pending', 'failed', 'debited']);
    
    report("Hold {$hold['hold_id']}: {$hold['amount']} BWP, status: {$status}",
           $statusOk,
           "Ref: {$hold['swap_reference']}, Updated: {$hold['updated_at']}");
    
    if ($status === 'confirmed') {
        $stuckConfirmed++;
        report("⚠️ HOLD STUCK AT 'confirmed' - NEEDS FIX", false, $hold);
    } elseif ($status === 'completed') {
        $completedCount++;
    } elseif ($status === 'pending') {
        $pendingCount++;
    }
}

report("Zero holds stuck at 'confirmed'", $stuckConfirmed === 0, "{$stuckConfirmed} stuck");
report("Completed holds: {$completedCount}", true);
report("Pending holds: {$pendingCount}", true);

// Check swap_requests
$swaps = dbQuery("
    SELECT swap_uuid, swap_id, status, reference, created_at
    FROM swap_requests
    WHERE reference LIKE 'TESTMATH_%' OR reference LIKE 'AGG_REMAIN_%'
    ORDER BY created_at DESC
");

trace("Found " . count($swaps) . " swap requests");
foreach ($swaps as $swap) {
    report("Swap: {$swap['reference']}, status: {$swap['status']}", 
           in_array($swap['status'], ['committed', 'completed']),
           $swap);
}

// Check deposit_transactions
$deposits = dbQuery("
    SELECT tx_ref, amount, currency, status, created_at
    FROM deposit_transactions
    WHERE tx_ref LIKE 'SWAP_REMAINDER_%' OR tx_ref LIKE 'TESTMATH_%'
    ORDER BY created_at DESC
");

trace("Found " . count($deposits) . " deposit transactions");
foreach ($deposits as $deposit) {
    report("Deposit: {$deposit['tx_ref']}, {$deposit['amount']} {$deposit['currency']}, status: {$deposit['status']}",
           in_array($deposit['status'], ['completed', 'pending']),
           $deposit);
}

// Check settlement_obligations
$obligations = dbQuery("
    SELECT instruction_id, swap_reference, debtor, creditor, amount, currency
    FROM settlement_obligations
    WHERE swap_reference LIKE 'TESTMATH_%' OR swap_reference LIKE 'SWAP_REMAINDER_%'
    ORDER BY created_at DESC
");

trace("Found " . count($obligations) . " settlement obligations");
foreach ($obligations as $obligation) {
    report("Settlement: {$obligation['debtor']} owes {$obligation['creditor']} {$obligation['amount']} {$obligation['currency']}",
           true,
           $obligation);
}

// Check fee_invoices
$invoices = dbQuery("
    SELECT invoice_uuid, swap_reference, fee_type, fee_amount, total_amount, currency
    FROM fee_invoices
    WHERE swap_reference LIKE 'TESTMATH_%' OR swap_reference LIKE 'SWAP_REMAINDER_%'
    ORDER BY created_at DESC
");

trace("Found " . count($invoices) . " fee invoices");
foreach ($invoices as $invoice) {
    report("Invoice: {$invoice['fee_type']} = {$invoice['fee_amount']} + VAT = {$invoice['total_amount']} {$invoice['currency']}",
           true,
           $invoice);
}

// ============================================================
// STEP 8: VERIFY MATH COMPUTATION
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "STEP 8: VERIFY MATH COMPUTATION\n";
echo str_repeat('=', 80) . "\n";

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
    
    if ($hold['status'] === 'completed' || $hold['status'] === 'debited') {
        $completedGross += $amount;
        // Net = Gross - Fee (we need to query the actual deposit for fee)
        $deposit = dbQueryOne("
            SELECT amount FROM deposit_transactions 
            WHERE tx_ref IN (SELECT swap_reference FROM identity_swap_holds WHERE hold_id = :hid)
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

// Calculate implied fees
$impliedFee = $completedGross - $netTotal;
report("Implied total fee: {$impliedFee} BWP", $impliedFee > 0);

// The expected fee for the test amounts (assuming 6 BWP per 500 BWP deposit)
// Calculate actual fee from fee_invoices
$feeInvoiceTotal = 0;
foreach ($invoices as $invoice) {
    $feeInvoiceTotal += (float)$invoice['total_amount'];
}
report("Fee invoices total: {$feeInvoiceTotal} BWP", $feeInvoiceTotal > 0);
report("Fee invoices match implied fee", abs($feeInvoiceTotal - $impliedFee) < 0.01);

// ============================================================
// STEP 9: TRACE COMPLETE FLOW
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "COMPLETE FLOW TRACE\n";
echo str_repeat('=', 80) . "\n";

$stepCount = 1;
foreach ($traceLog as $entry) {
    $time = date('H:i:s', (int)$entry['time']);
    $data = $entry['data'] ?? '';
    echo "{$stepCount}. [{$time}] {$entry['step']}\n";
    if ($data) {
        echo "   " . (is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT)) . "\n";
    }
    $stepCount++;
}

// ============================================================
// STEP 10: SUMMARY
// ============================================================
echo "\n" . str_repeat('=', 80) . "\n";
echo "FINAL SUMMARY\n";
echo str_repeat('=', 80) . "\n";

$passed = array_filter($results, fn($r) => $r['pass'] === true);
$failed = array_filter($results, fn($r) => $r['pass'] === false);
$info = array_filter($results, fn($r) => $r['pass'] === 'INFO' || $r['pass'] === null);

echo "✅ Passed: " . count($passed) . "\n";
echo "❌ Failed: " . count($failed) . "\n";
echo "ℹ️  Info: " . count($info) . "\n";

if (!empty($failed)) {
    echo "\n--- FAILURES ---\n";
    foreach ($failed as $f) {
        echo "  ❌ {$f['label']}\n";
        if (isset($f['detail'])) {
            echo "     " . (is_string($f['detail']) ? $f['detail'] : json_encode($f['detail'], JSON_PRETTY_PRINT)) . "\n";
        }
    }
}

// ============================================================
// STEP 11: DIAGNOSTIC SUGGESTIONS
// ============================================================
if (!empty($failed)) {
    echo "\n--- DIAGNOSTIC SUGGESTIONS ---\n";
    
    $hasPinError = array_filter($failed, fn($f) => stripos($f['label'], 'PIN') !== false);
    if ($hasPinError) {
        echo "• PIN verification failed. Check:\n";
        echo "  - The test PIN matches what was sent via SMS\n";
        echo "  - The PIN was stored correctly in identity_swap_holds.otp_pin_hash\n";
        echo "  - The PIN hasn't expired (check hold_expires_at)\n";
    }
    
    $hasDestinationError = array_filter($failed, fn($f) => stripos($f['label'], 'destination') !== false);
    if ($hasDestinationError) {
        echo "• Destination account error. Check:\n";
        echo "  - agent_destination_accounts.id = {$destinationAccountId} exists\n";
        echo "  - agent_destination_accounts.user_id = {$agentUserId} matches\n";
        echo "  - agent_destination_accounts.status = 'active'\n";
        echo "  - agent_destination_accounts.deleted_at IS NULL\n";
    }
    
    $hasStuckHold = array_filter($failed, fn($f) => stripos($f['label'], 'stuck') !== false);
    if ($hasStuckHold) {
        echo "• Holds stuck at 'confirmed'. This is a transaction-ordering bug:\n";
        echo "  - Check finalizeIdentityHoldNoPin() transaction ordering\n";
        echo "  - Look for missing commit/rollback around hold status update\n";
        echo "  - Verify the hold status transition: pending → confirmed → debited → completed\n";
    }
    
    $hasMathError = array_filter($failed, fn($f) => stripos($f['label'], 'Remainder') !== false);
    if ($hasMathError) {
        echo "• Remainder math bug detected:\n";
        echo "  - Remainder should be: NET - cash_now\n";
        echo "  - Old buggy formula: GROSS - cash_now\n";
        echo "  - Check swap_service.php lines around remainder calculation\n";
    }
}

// ============================================================
// CLEANUP (OPTIONAL)
// ============================================================
echo "\n--- CLEANUP ---\n";
echo "To clean up test data, run:\n";
echo "DELETE FROM identity_swap_holds WHERE identity_value = '{$testIdentityValue}';\n";
echo "DELETE FROM swap_requests WHERE reference LIKE 'TESTMATH_%' OR reference LIKE 'AGG_REMAIN_%';\n";
echo "DELETE FROM deposit_transactions WHERE tx_ref LIKE 'TESTMATH_%' OR tx_ref LIKE 'SWAP_REMAINDER_%';\n";
echo "DELETE FROM settlement_obligations WHERE swap_reference LIKE 'TESTMATH_%' OR swap_reference LIKE 'SWAP_REMAINDER_%';\n";
echo "DELETE FROM fee_invoices WHERE swap_reference LIKE 'TESTMATH_%' OR swap_reference LIKE 'SWAP_REMAINDER_%';\n";

echo "\nTest completed at " . date('Y-m-d H:i:s') . "\n";
