<?php
/**
 * Test Script: Verify SwapService Populates All Tables
 * 
 * This script will:
 * 1. Create a test swap with user_id
 * 2. Check if data is populated in all tables
 * 3. Verify user_id is stored in source_details
 * 4. Test history retrieval
 * 
 * WARNING: This creates a real swap record. Use with caution.
 * Set TEST_MODE=true to use a test reference that's easy to identify/clean up.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;

// ============================================================
// CONFIGURATION
// ============================================================
$testUserId = 1;  // Change to your test user ID
$testAmount = 1.00;  // Small amount for testing
$testReference = 'TEST_SWAP_' . time();  // Unique reference

echo "=== SWAP SERVICE TABLE POPULATION TEST ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "Test Reference: {$testReference}\n";
echo "Test User ID: {$testUserId}\n\n";

try {
    // ============================================================
    // 1. SETUP DATABASE CONNECTION
    // ============================================================
    echo "1. Connecting to database...\n";
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "   ✅ Database connected\n\n";

    // ============================================================
    // 2. GET COUNTRY CONFIG
    // ============================================================
    echo "2. Loading country configuration...\n";
    $config = \Core\Config\LoadCountry::getConfig();
    echo "   ✅ Country: " . ($config['country'] ?? 'BW') . "\n\n";

    // ============================================================
    // 3. CREATE SWAP SERVICE INSTANCE
    // ============================================================
    echo "3. Initializing SwapService...\n";
    $swapService = new SwapService($db, $config, 'BW');
    echo "   ✅ SwapService initialized\n\n";

    // ============================================================
    // 4. CREATE TEST PAYLOAD WITH USER_ID
    // ============================================================
    echo "4. Creating test swap payload...\n";
    
    $payload = [
        'user_id' => $testUserId,  // ← CRITICAL: user_id must be here!
        'swap_type' => 'DEPOSIT',
        'from_institution' => 'ZURUBANK',
        'asset_type' => 'VOUCHER',
        'amount' => $testAmount,
        'currency' => 'BWP',
        'to_institution' => 'SACCUSSALIS',
        'destination_asset_type' => 'ACCOUNT',
        'destination_identifier' => '10000001',
        'source_identifier' => '351404175',
        'reference' => $testReference,
        'idempotency_key' => 'IDEMP_' . $testReference,
        'wallet_pin' => '1234',
        'voucher_number' => '351404175'
    ];
    
    echo "   Payload:\n";
    echo "   - user_id: {$payload['user_id']}\n";
    echo "   - from: {$payload['from_institution']}\n";
    echo "   - to: {$payload['to_institution']}\n";
    echo "   - amount: {$payload['amount']} {$payload['currency']}\n";
    echo "   - reference: {$payload['reference']}\n\n";

    // ============================================================
    // 5. EXECUTE THE SWAP
    // ============================================================
    echo "5. Executing swap...\n";
    
    try {
        $result = $swapService->executeAtomicSwap($payload);
        echo "   ✅ Swap executed successfully!\n";
        echo "   Result status: " . ($result['status'] ?? 'unknown') . "\n";
        echo "   Reference: " . ($result['reference'] ?? 'N/A') . "\n\n";
    } catch (Exception $e) {
        echo "   ❌ Swap failed: " . $e->getMessage() . "\n";
        echo "   Trace: " . $e->getTraceAsString() . "\n";
        exit(1);
    }

    // ============================================================
    // 6. CHECK hold_transactions TABLE
    // ============================================================
    echo "6. Checking hold_transactions table...\n";
    
    $stmt = $db->prepare("
        SELECT 
            hold_id,
            swap_reference,
            participant_name as source_institution,
            destination_institution,
            amount,
            currency,
            status,
            source_details,
            metadata,
            created_at
        FROM hold_transactions
        WHERE swap_reference = :reference
    ");
    $stmt->execute([':reference' => $testReference]);
    $holdRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$holdRecord) {
        echo "   ❌ No record found in hold_transactions!\n";
        exit(1);
    }
    
    echo "   ✅ Found record in hold_transactions:\n";
    echo "   - hold_id: {$holdRecord['hold_id']}\n";
    echo "   - amount: {$holdRecord['amount']} {$holdRecord['currency']}\n";
    echo "   - status: {$holdRecord['status']}\n";
    echo "   - created_at: {$holdRecord['created_at']}\n";
    
    // Check source_details for user_id
    $sourceDetails = json_decode($holdRecord['source_details'], true);
    echo "   - source_details contains user_id: " . (isset($sourceDetails['user_id']) ? '✅ YES' : '❌ NO') . "\n";
    if (isset($sourceDetails['user_id'])) {
        echo "   - user_id value: " . $sourceDetails['user_id'] . "\n";
    } else {
        echo "   ⚠️  WARNING: user_id NOT found in source_details!\n";
    }
    echo "\n";

    // ============================================================
    // 7. CHECK cashout_authorization TABLE (if applicable)
    // ============================================================
    echo "7. Checking cashout_authorization table...\n";
    
    $stmt = $db->prepare("
        SELECT 
            auth_id,
            swap_reference,
            client_phone,
            swap_code,
            pin_code,
            amount,
            currency,
            status,
            created_at
        FROM cashout_authorization
        WHERE swap_reference = :reference
    ");
    $stmt->execute([':reference' => $testReference]);
    $cashoutRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cashoutRecord) {
        echo "   ✅ Found record in cashout_authorization:\n";
        echo "   - auth_id: {$cashoutRecord['auth_id']}\n";
        echo "   - swap_code: {$cashoutRecord['swap_code']}\n";
        echo "   - pin_code: {$cashoutRecord['pin_code']}\n";
        echo "   - status: {$cashoutRecord['status']}\n";
    } else {
        echo "   ℹ️  No record in cashout_authorization (expected for DEPOSIT swap)\n";
    }
    echo "\n";

    // ============================================================
    // 8. CHECK deposit_transactions TABLE
    // ============================================================
    echo "8. Checking deposit_transactions table...\n";
    
    $stmt = $db->prepare("
        SELECT 
            deposit_id,
            transaction_reference,
            source_institution,
            destination_institution,
            amount,
            currency,
            status,
            created_at
        FROM deposit_transactions
        WHERE transaction_reference = :reference
    ");
    $stmt->execute([':reference' => $testReference]);
    $depositRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($depositRecord) {
        echo "   ✅ Found record in deposit_transactions:\n";
        echo "   - deposit_id: {$depositRecord['deposit_id']}\n";
        echo "   - amount: {$depositRecord['amount']} {$depositRecord['currency']}\n";
        echo "   - status: {$depositRecord['status']}\n";
    } else {
        echo "   ⚠️  No record found in deposit_transactions!\n";
    }
    echo "\n";

    // ============================================================
    // 9. CHECK swap_requests TABLE
    // ============================================================
    echo "9. Checking swap_requests table...\n";
    
    $stmt = $db->prepare("
        SELECT 
            swap_id,
            swap_uuid,
            from_currency,
            to_currency,
            amount,
            status,
            created_at
        FROM swap_requests
        WHERE swap_uuid = :reference
    ");
    $stmt->execute([':reference' => $testReference]);
    $requestRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($requestRecord) {
        echo "   ✅ Found record in swap_requests:\n";
        echo "   - swap_id: {$requestRecord['swap_id']}\n";
        echo "   - from_currency: {$requestRecord['from_currency']}\n";
        echo "   - to_currency: {$requestRecord['to_currency']}\n";
        echo "   - status: {$requestRecord['status']}\n";
    } else {
        echo "   ⚠️  No record found in swap_requests!\n";
    }
    echo "\n";

    // ============================================================
    // 10. CHECK swap_transactions TABLE
    // ============================================================
    echo "10. Checking swap_transactions table...\n";
    
    $stmt = $db->prepare("
        SELECT 
            swap_transaction_id,
            swap_id,
            amount,
            status,
            created_at
        FROM swap_transactions
        WHERE swap_id = :reference
    ");
    $stmt->execute([':reference' => $testReference]);
    $transactionRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($transactionRecord) {
        echo "   ✅ Found record in swap_transactions:\n";
        echo "   - swap_transaction_id: {$transactionRecord['swap_transaction_id']}\n";
        echo "   - amount: {$transactionRecord['amount']}\n";
        echo "   - status: {$transactionRecord['status']}\n";
    } else {
        echo "   ⚠️  No record found in swap_transactions!\n";
    }
    echo "\n";

    // ============================================================
    // 11. CHECK message_outbox TABLE (if SMS was sent)
    // ============================================================
    echo "11. Checking message_outbox table...\n";
    
    $stmt = $db->prepare("
        SELECT 
            message_id,
            destination,
            payload,
            status,
            created_at
        FROM message_outbox
        WHERE destination LIKE '%267%'
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $smsRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($smsRecords) {
        echo "   ✅ Found " . count($smsRecords) . " recent SMS records:\n";
        foreach ($smsRecords as $sms) {
            $payload = json_decode($sms['payload'], true);
            echo "   - message_id: {$sms['message_id']}\n";
            echo "   - destination: {$sms['destination']}\n";
            echo "   - message: " . substr($payload['message'] ?? '', 0, 50) . "...\n";
            echo "   - status: {$sms['status']}\n";
        }
    } else {
        echo "   ℹ️  No SMS records found (expected if SMS not configured)\n";
    }
    echo "\n";

    // ============================================================
    // 12. TEST HISTORY API DIRECTLY
    // ============================================================
    echo "12. Testing history retrieval for user_id={$testUserId}...\n";
    
    $stmt = $db->prepare("
        SELECT 
            swap_reference,
            source_details,
            amount,
            currency,
            status,
            created_at
        FROM hold_transactions
        WHERE source_details::text LIKE :search
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->bindValue(':search', '%"user_id":' . $testUserId . '%', PDO::PARAM_STR);
    $stmt->execute();
    $historyResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($historyResults) > 0) {
        echo "   ✅ Found " . count($historyResults) . " swaps for user_id={$testUserId}:\n";
        foreach ($historyResults as $swap) {
            $details = json_decode($swap['source_details'], true);
            echo "   - reference: {$swap['swap_reference']}\n";
            echo "     amount: {$swap['amount']} {$swap['currency']}\n";
            echo "     user_id in details: " . ($details['user_id'] ?? 'NOT FOUND') . "\n";
            echo "     created: {$swap['created_at']}\n";
        }
    } else {
        echo "   ❌ No swaps found for user_id={$testUserId}!\n";
        echo "   This means the history API will return empty.\n";
    }
    echo "\n";

    // ============================================================
    // 13. SUMMARY
    // ============================================================
    echo "=== TEST SUMMARY ===\n";
    echo "Swap Reference: {$testReference}\n";
    echo "User ID: {$testUserId}\n\n";
    
    echo "Table Population:\n";
    echo "  - hold_transactions: " . ($holdRecord ? '✅' : '❌') . "\n";
    echo "  - cashout_authorization: " . ($cashoutRecord ? '✅' : 'ℹ️ (N/A for DEPOSIT)') . "\n";
    echo "  - deposit_transactions: " . ($depositRecord ? '✅' : '⚠️') . "\n";
    echo "  - swap_requests: " . ($requestRecord ? '✅' : '⚠️') . "\n";
    echo "  - swap_transactions: " . ($transactionRecord ? '✅' : '⚠️') . "\n";
    echo "  - message_outbox: " . ($smsRecords ? '✅' : 'ℹ️') . "\n\n";
    
    echo "user_id in source_details: " . (isset($sourceDetails['user_id']) ? '✅ ' . $sourceDetails['user_id'] : '❌ NOT FOUND') . "\n\n";
    
    echo "History API Test: " . (count($historyResults) > 0 ? '✅ PASSED' : '❌ FAILED') . "\n";
    echo "  - Swaps found: " . count($historyResults) . "\n";
    
    echo "\n=== TEST COMPLETE ===\n";
    echo "Finished: " . date('Y-m-d H:i:s') . "\n";
    echo "\n⚠️  Remember to clean up test records if needed!\n";
    echo "DELETE FROM hold_transactions WHERE swap_reference = '{$testReference}';\n";

} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
