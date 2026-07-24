<?php
// check_latest_swap_data.php
// Check the most recent records in each table

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;

$pdo = DBConnection::getConnection();

if (!$pdo) {
    die("❌ Failed to connect to database\n");
}

echo "========================================\n";
echo "LATEST SWAP DATA CHECK\n";
echo "========================================\n\n";

// 1. Latest swap_requests
echo "📋 1. Latest swap_requests (last 5):\n";
$stmt = $pdo->prepare("
    SELECT swap_id, swap_uuid, amount, status, user_id, created_at 
    FROM swap_requests 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $r) {
    echo "   {$r['swap_uuid']} | amount: {$r['amount']} | status: {$r['status']} | user_id: {$r['user_id']} | created: {$r['created_at']}\n";
}
echo "\n";

// 2. Latest hold_transactions
echo "📋 2. Latest hold_transactions (last 5):\n";
$stmt = $pdo->prepare("
    SELECT hold_id, hold_reference, swap_reference, amount, status, placed_at 
    FROM hold_transactions 
    ORDER BY placed_at DESC 
    LIMIT 5
");
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $r) {
    echo "   hold_id: {$r['hold_id']} | ref: {$r['hold_reference']} | swap: {$r['swap_reference']} | amount: {$r['amount']} | status: {$r['status']} | placed: {$r['placed_at']}\n";
}
echo "\n";

// 3. Latest cashout_authorizations
echo "📋 3. Latest cashout_authorizations (last 5):\n";
$stmt = $pdo->prepare("
    SELECT auth_id, swap_reference, client_phone, amount, swap_code, pin_code, status, created_at 
    FROM cashout_authorizations 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $r) {
    echo "   auth_id: {$r['auth_id']} | swap: {$r['swap_reference']} | phone: {$r['client_phone']} | amount: {$r['amount']} | code: {$r['swap_code']} | pin: {$r['pin_code']} | status: {$r['status']} | created: {$r['created_at']}\n";
}
echo "\n";

// 4. Search for the test phone number
echo "📋 4. Cashout authorizations for phone +26770000000:\n";
$stmt = $pdo->prepare("
    SELECT auth_id, swap_reference, amount, swap_code, pin_code, status, created_at 
    FROM cashout_authorizations 
    WHERE client_phone = :phone
    ORDER BY created_at DESC
");
$stmt->execute([':phone' => '+26770000000']);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($results) {
    foreach ($results as $r) {
        echo "   auth_id: {$r['auth_id']} | swap: {$r['swap_reference']} | amount: {$r['amount']} | code: {$r['swap_code']} | status: {$r['status']} | created: {$r['created_at']}\n";
    }
} else {
    echo "   ❌ No records found for phone +26770000000\n";
}
echo "\n";

// 5. Search for the test user_id
echo "📋 5. Swap requests for user_id 12:\n";
$stmt = $pdo->prepare("
    SELECT swap_id, swap_uuid, amount, status, created_at 
    FROM swap_requests 
    WHERE user_id = :user_id
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute([':user_id' => 12]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($results) {
    foreach ($results as $r) {
        echo "   {$r['swap_uuid']} | amount: {$r['amount']} | status: {$r['status']} | created: {$r['created_at']}\n";
    }
} else {
    echo "   ❌ No records found for user_id 12\n";
}
echo "\n";

// 6. Check if the test reference exists anywhere (partial match)
echo "📋 6. Search for partial match of 'SWAP_TEST' in all tables:\n";
$tables = ['swap_requests' => 'swap_uuid', 'hold_transactions' => 'swap_reference', 'cashout_authorizations' => 'swap_reference'];
foreach ($tables as $table => $column) {
    try {
        $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE {$column} LIKE :pattern ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([':pattern' => 'SWAP_TEST%']);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results) {
            echo "   ✅ {$table}: " . implode(', ', array_column($results, $column)) . "\n";
        } else {
            echo "   ❌ {$table}: No 'SWAP_TEST%' records found\n";
        }
    } catch (PDOException $e) {
        echo "   ❌ {$table}: ERROR - " . $e->getMessage() . "\n";
    }
}
echo "\n";

// 7. Check the auth_id from the test output (154)
echo "📋 7. Check auth_id 154 (from test output):\n";
$stmt = $pdo->prepare("
    SELECT * FROM cashout_authorizations WHERE auth_id = :auth_id
");
$stmt->execute([':auth_id' => 154]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result) {
    echo "   ✅ FOUND:\n";
    echo "      swap_reference: {$result['swap_reference']}\n";
    echo "      amount: {$result['amount']}\n";
    echo "      swap_code: {$result['swap_code']}\n";
    echo "      pin_code: {$result['pin_code']}\n";
    echo "      status: {$result['status']}\n";
    echo "      created_at: {$result['created_at']}\n";
} else {
    echo "   ❌ auth_id 154 NOT FOUND\n";
}
echo "\n";

// 8. Check hold_id 542 (from test output)
echo "📋 8. Check hold_id 542 (from test output):\n";
$stmt = $pdo->prepare("
    SELECT * FROM hold_transactions WHERE hold_id = :hold_id
");
$stmt->execute([':hold_id' => 542]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result) {
    echo "   ✅ FOUND:\n";
    echo "      hold_reference: {$result['hold_reference']}\n";
    echo "      swap_reference: {$result['swap_reference']}\n";
    echo "      amount: {$result['amount']}\n";
    echo "      status: {$result['status']}\n";
    echo "      placed_at: {$result['placed_at']}\n";
} else {
    echo "   ❌ hold_id 542 NOT FOUND\n";
}
echo "\n";

echo "========================================\n";
echo "CHECK COMPLETE\n";
echo "========================================\n";
