<?php
/**
 * Debug script to test cashout authorization creation
 * Run this to see why tables aren't being updated
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$baseDir = dirname(__DIR__, 4);
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/src/bootstrap.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

echo "=== DEBUG: Cashout Authorization Test ===\n\n";

// Get database connection
try {
    $db = DBConnection::getConnection();
    echo "✅ Database connected\n\n";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
    exit;
}

// Check if cashout_authorizations table exists
try {
    $stmt = $db->query("SELECT to_regclass('cashout_authorizations')");
    $exists = $stmt->fetchColumn();
    echo "📋 cashout_authorizations table: " . ($exists ? "✅ EXISTS" : "❌ DOES NOT EXIST") . "\n";
} catch (Exception $e) {
    echo "❌ Error checking table: " . $e->getMessage() . "\n";
}

// Check table columns
try {
    $stmt = $db->query("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_name = 'cashout_authorizations'
        ORDER BY ordinal_position
    ");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\n📋 cashout_authorizations columns:\n";
    foreach ($columns as $col) {
        echo "  - " . $col['column_name'] . ": " . $col['data_type'] . "\n";
    }
} catch (Exception $e) {
    echo "❌ Error getting columns: " . $e->getMessage() . "\n";
}

// Test inserting a cashout authorization
echo "\n=== TEST: Insert Cashout Authorization ===\n";

try {
    $swapRef = 'TEST_SWAP_' . time();
    $voucherNumber = 'TEST_VOUCHER_' . time();
    
    $sql = "
        INSERT INTO cashout_authorizations (
            swap_reference,
            client_phone,
            source_institution,
            source_wallet,
            amount,
            currency,
            fee_amount,
            swap_code,
            pin_code,
            code_expiry,
            cashout_point,
            cashout_provider,
            status,
            created_at,
            updated_at,
            user_id
        ) VALUES (
            :swap_ref,
            :client_phone,
            :source_inst,
            :source_wallet,
            :amount,
            :currency,
            :fee_amount,
            :swap_code,
            :pin_code,
            :code_expiry,
            :cashout_point,
            :cashout_provider,
            'PENDING',
            NOW(),
            NOW(),
            :user_id
        ) RETURNING auth_id
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':swap_ref' => $swapRef,
        ':client_phone' => '+26770000000',
        ':source_inst' => 'ZURUBANK',
        ':source_wallet' => null,
        ':amount' => 100,
        ':currency' => 'BWP',
        ':fee_amount' => 10,
        ':swap_code' => $voucherNumber,
        ':pin_code' => '1234',
        ':code_expiry' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        ':cashout_point' => 'ATM',
        ':cashout_provider' => 'ZURUBANK',
        ':user_id' => 1
    ]);
    
    $authId = $stmt->fetchColumn();
    echo "✅ Insert successful! auth_id: " . $authId . "\n";
    echo "  swap_reference: " . $swapRef . "\n";
    echo "  swap_code: " . $voucherNumber . "\n";
    
    // Verify the insert
    $stmt = $db->query("SELECT * FROM cashout_authorizations WHERE auth_id = $authId");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "\n✅ Verification - record found:\n";
        echo "  auth_id: " . $row['auth_id'] . "\n";
        echo "  swap_reference: " . $row['swap_reference'] . "\n";
        echo "  amount: " . $row['amount'] . "\n";
        echo "  status: " . $row['status'] . "\n";
        echo "  swap_code: " . $row['swap_code'] . "\n";
    }
    
    // Clean up test data
    $db->exec("DELETE FROM cashout_authorizations WHERE auth_id = $authId");
    echo "\n🧹 Test data cleaned up\n";
    
} catch (PDOException $e) {
    echo "❌ Insert failed: " . $e->getMessage() . "\n";
    echo "  Error code: " . $e->getCode() . "\n";
}

// Check what happens when confirmCashout is called
echo "\n=== TEST: confirmCashout with test data ===\n";

try {
    // Create a test authorization
    $swapRef = 'TEST_CONFIRM_' . time();
    $voucherNumber = 'TEST_CONFIRM_' . time();
    
    $stmt = $db->prepare("
        INSERT INTO cashout_authorizations (
            swap_reference,
            client_phone,
            source_institution,
            source_wallet,
            amount,
            currency,
            fee_amount,
            swap_code,
            pin_code,
            code_expiry,
            cashout_point,
            cashout_provider,
            status,
            created_at,
            updated_at,
            user_id
        ) VALUES (
            :swap_ref,
            :client_phone,
            :source_inst,
            :source_wallet,
            :amount,
            :currency,
            :fee_amount,
            :swap_code,
            :pin_code,
            :code_expiry,
            :cashout_point,
            :cashout_provider,
            'PENDING',
            NOW(),
            NOW(),
            :user_id
        ) RETURNING auth_id
    ");
    
    $stmt->execute([
        ':swap_ref' => $swapRef,
        ':client_phone' => '+26770000000',
        ':source_inst' => 'ZURUBANK',
        ':source_wallet' => null,
        ':amount' => 100,
        ':currency' => 'BWP',
        ':fee_amount' => 10,
        ':swap_code' => $voucherNumber,
        ':pin_code' => '1234',
        ':code_expiry' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        ':cashout_point' => 'ATM',
        ':cashout_provider' => 'ZURUBANK',
        ':user_id' => 1
    ]);
    $authId = $stmt->fetchColumn();
    
    echo "✅ Test authorization created with auth_id: " . $authId . "\n";
    
    // Now try confirmCashout
    $config = LoadCountry::getConfig();
    $swapService = new SwapService($db, $config, 'Botswana');
    
    $payload = [
        'voucher_number' => $voucherNumber,
        'swap_reference' => $swapRef,
        'atm_id' => 'TEST_ATM',
        'cashout_reference' => 'TEST_CASHOUT',
        'requester' => 'TEST_SYSTEM',
        'is_callback' => true,
        'cashout_point' => 'ATM'
    ];
    
    echo "Calling confirmCashout with payload:\n";
    echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";
    
    $result = $swapService->confirmCashout($payload);
    echo "✅ confirmCashout result:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    
    // Clean up
    $db->exec("DELETE FROM cashout_authorizations WHERE auth_id = $authId");
    echo "\n🧹 Test data cleaned up\n";
    
} catch (Exception $e) {
    echo "❌ confirmCashout failed: " . $e->getMessage() . "\n";
    echo "  Trace: " . $e->getTraceAsString() . "\n";
}
