<?php
// test_complete_swap_flow.php
// Complete test: Execute swap and verify all tables

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../src/Domain/Services/SwapService.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

// ============================================================
// CONFIGURATION
// ============================================================
$testConfig = [
    'source_institution' => 'ZURUBANK',
    'source_identifier' => '10000001',
    'source_asset_type' => 'ACCOUNT',
    'destination_institution' => 'SACCUSSALIS',
    'beneficiary_phone' => '+26770000000',
    'amount' => 1000,
    'currency' => 'BWP',
    'swap_type' => 'CASHOUT',
    'user_id' => 12,  // Adjust to your test user ID
];

echo "========================================\n";
echo "COMPLETE SWAP FLOW TEST\n";
echo "========================================\n\n";

// ============================================================
// 1. GET DATABASE CONNECTION
// ============================================================
$pdo = DBConnection::getConnection();

if (!$pdo) {
    die("❌ Failed to connect to database\n");
}

echo "✅ Database connected\n\n";

// ============================================================
// 2. LOAD COUNTRY CONFIG
// ============================================================
echo "📂 Loading country config...\n";
$countryConfig = LoadCountry::getConfig();

if (empty($countryConfig)) {
    die("❌ Failed to load country config\n");
}

echo "✅ Country config loaded\n\n";

// ============================================================
// 3. CREATE SWAP PAYLOAD
// ============================================================
echo "📝 Creating swap payload...\n";

$reference = 'SWAP_TEST_' . time() . '_' . bin2hex(random_bytes(4));

$payload = [
    'swap_type' => $testConfig['swap_type'],
    'reference' => $reference,
    'idempotency_key' => 'IDEMP_' . $reference,
    'user_id' => $testConfig['user_id'],
    'from_institution' => $testConfig['source_institution'],
    'source_institution' => $testConfig['source_institution'],
    'asset_type' => $testConfig['source_asset_type'],
    'amount' => $testConfig['amount'],
    'currency' => $testConfig['currency'],
    'source_identifier' => $testConfig['source_identifier'],
    'source_identifier_type' => 'auto',
    'to_institution' => $testConfig['destination_institution'],
    'destination_institution' => $testConfig['destination_institution'],
    'beneficiary_phone' => $testConfig['beneficiary_phone'],
    'client_phone' => $testConfig['beneficiary_phone'],
    'delivery_method' => 'ATM',
    'destination_currency' => $testConfig['currency'],
];

echo "   Reference: {$reference}\n";
echo "   Amount: {$testConfig['amount']} {$testConfig['currency']}\n";
echo "   Source: {$testConfig['source_institution']} {$testConfig['source_identifier']}\n";
echo "   Destination: {$testConfig['destination_institution']}\n";
echo "   Phone: {$testConfig['beneficiary_phone']}\n\n";

// ============================================================
// 4. INITIALIZE SWAP SERVICE
// ============================================================
echo "⚙️  Initializing SwapService...\n";

try {
    // Load participants from config
    $participants = $countryConfig['participants'] ?? [];
    $feesConfig = $countryConfig['fees'] ?? [];
    $config = $countryConfig;
    
    $swapService = new SwapService(
        $pdo,
        $config,
        'Botswana',
        null  // Use default logger
    );
    echo "✅ SwapService initialized\n\n";
} catch (Exception $e) {
    die("❌ Failed to initialize SwapService: " . $e->getMessage() . "\n");
}

// ============================================================
// 5. EXECUTE THE SWAP
// ============================================================
echo "🚀 Executing swap...\n";

try {
    $startTime = microtime(true);
    $result = $swapService->executeAtomicSwap($payload);
    $executionTime = round(microtime(true) - $startTime, 2);
    
    if (isset($result['status']) && $result['status'] === 'pending') {
        echo "✅ Swap executed successfully (pending)\n";
    } elseif (isset($result['status']) && $result['status'] === 'success') {
        echo "✅ Swap executed successfully (completed)\n";
    } else {
        echo "⚠️  Swap returned: " . json_encode($result) . "\n";
    }
    
    echo "   Reference: {$reference}\n";
    echo "   Execution time: {$executionTime}s\n";
    
    if (isset($result['auth_id'])) {
        echo "   Auth ID: {$result['auth_id']}\n";
    }
    if (isset($result['swap_code'])) {
        echo "   Swap Code: {$result['swap_code']}\n";
    }
    if (isset($result['atm_code'])) {
        echo "   ATM PIN: {$result['atm_code']}\n";
    }
    if (isset($result['voucher_number'])) {
        echo "   Voucher Number: {$result['voucher_number']}\n";
    }
    
    echo "\n";
    
} catch (Exception $e) {
    die("❌ Swap execution failed: " . $e->getMessage() . "\n");
}

// ============================================================
// 6. VERIFY ALL TABLES
// ============================================================
echo "═══════════════════════════════════════════════════════\n";
echo "🔍 VERIFYING TABLES\n";
echo "═══════════════════════════════════════════════════════\n\n";

$allPassed = true;
$swapId = null;
$holdId = null;
$authId = null;

// -----------------------------------------------------------------
// 6.1 swap_requests
// -----------------------------------------------------------------
echo "📋 1. swap_requests\n";
$stmt = $pdo->prepare("
    SELECT * FROM swap_requests 
    WHERE swap_uuid = :swap_ref 
    ORDER BY created_at DESC
");
$stmt->execute([':swap_ref' => $reference]);
$swapRequest = $stmt->fetch(PDO::FETCH_ASSOC);

if ($swapRequest) {
    echo "   ✅ FOUND\n";
    echo "      swap_id: {$swapRequest['swap_id']}\n";
    echo "      amount: {$swapRequest['amount']}\n";
    echo "      status: {$swapRequest['status']}\n";
    echo "      user_id: {$swapRequest['user_id']}\n";
    $swapId = $swapRequest['swap_id'];
} else {
    echo "   ❌ NOT FOUND\n";
    $allPassed = false;
}
echo "\n";

// -----------------------------------------------------------------
// 6.2 hold_transactions
// -----------------------------------------------------------------
echo "📋 2. hold_transactions\n";
$stmt = $pdo->prepare("
    SELECT * FROM hold_transactions 
    WHERE swap_reference = :swap_ref
    ORDER BY placed_at DESC
");
$stmt->execute([':swap_ref' => $reference]);
$holds = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($holds) {
    echo "   ✅ FOUND " . count($holds) . " hold(s)\n";
    foreach ($holds as $hold) {
        echo "      hold_id: {$hold['hold_id']}\n";
        echo "      amount: {$hold['amount']}\n";
        echo "      status: {$hold['status']}\n";
        $holdId = $hold['hold_id'];
    }
} else {
    echo "   ❌ NOT FOUND\n";
    $allPassed = false;
}
echo "\n";

// -----------------------------------------------------------------
// 6.3 cashout_authorizations
// -----------------------------------------------------------------
echo "📋 3. cashout_authorizations\n";
$stmt = $pdo->prepare("
    SELECT * FROM cashout_authorizations 
    WHERE swap_reference = :swap_ref
    ORDER BY created_at DESC
");
$stmt->execute([':swap_ref' => $reference]);
$cashoutAuth = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($cashoutAuth) {
    echo "   ✅ FOUND " . count($cashoutAuth) . " record(s)\n";
    foreach ($cashoutAuth as $auth) {
        echo "      auth_id: {$auth['auth_id']}\n";
        echo "      client_phone: {$auth['client_phone']}\n";
        echo "      amount: {$auth['amount']}\n";
        echo "      swap_code: {$auth['swap_code']}\n";
        echo "      status: {$auth['status']}\n";
        $authId = $auth['auth_id'];
    }
} else {
    echo "   ❌ NOT FOUND\n";
    $allPassed = false;
}
echo "\n";

// -----------------------------------------------------------------
// 6.4 swap_transactions
// -----------------------------------------------------------------
echo "📋 4. swap_transactions\n";
if ($swapId) {
    $stmt = $pdo->prepare("
        SELECT * FROM swap_transactions 
        WHERE swap_id = :swap_id
        ORDER BY created_at DESC
    ");
    $stmt->execute([':swap_id' => $swapId]);
    $swapTx = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($swapTx) {
        echo "   ✅ FOUND " . count($swapTx) . " record(s)\n";
        foreach ($swapTx as $tx) {
            echo "      amount: {$tx['amount']}\n";
            echo "      status: {$tx['status']}\n";
            echo "      user_id: {$tx['user_id']}\n";
        }
    } else {
        echo "   ❌ NOT FOUND\n";
        $allPassed = false;
    }
} else {
    echo "   ⏭️  SKIPPED (no swap_id)\n";
}
echo "\n";

// -----------------------------------------------------------------
// 6.5 message_outbox
// -----------------------------------------------------------------
echo "📋 5. message_outbox\n";
$stmt = $pdo->prepare("
    SELECT * FROM message_outbox 
    WHERE payload::text LIKE :search_pattern
    ORDER BY created_at DESC
");
$stmt->execute([':search_pattern' => '%' . $reference . '%']);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($messages) {
    echo "   ✅ FOUND " . count($messages) . " message(s)\n";
    foreach ($messages as $msg) {
        echo "      destination: {$msg['destination']}\n";
        echo "      status: {$msg['status']}\n";
        $payload = json_decode($msg['payload'], true);
        if ($payload && isset($payload['message'])) {
            echo "      message: " . substr($payload['message'], 0, 80) . "...\n";
        }
    }
} else {
    echo "   ⚠️  No messages found (SMS may not be configured)\n";
}
echo "\n";

// -----------------------------------------------------------------
// 6.6 audit_logs
// -----------------------------------------------------------------
echo "📋 6. audit_logs\n";
$stmt = $pdo->prepare("
    SELECT * FROM audit_logs 
    WHERE metadata::text LIKE :search_pattern
    ORDER BY performed_at DESC
    LIMIT 5
");
$stmt->execute([':search_pattern' => '%' . $reference . '%']);
$audits = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($audits) {
    echo "   ✅ FOUND " . count($audits) . " audit record(s)\n";
    foreach ($audits as $audit) {
        echo "      action: {$audit['action']}\n";
        echo "      category: {$audit['category']}\n";
    }
} else {
    echo "   ⚠️  No audit records found\n";
}
echo "\n";

// ============================================================
// 7. SUMMARY
// ============================================================
echo "═══════════════════════════════════════════════════════\n";
echo "📊 TEST SUMMARY\n";
echo "═══════════════════════════════════════════════════════\n";

$results = [
    'Swap executed' => '✅',
    'swap_requests' => $swapRequest ? '✅' : '❌',
    'hold_transactions' => $holds ? '✅' : '❌',
    'cashout_authorizations' => $cashoutAuth ? '✅' : '❌',
    'swap_transactions' => $swapTx ? '✅' : '❌',
    'message_outbox' => $messages ? '✅' : '⚠️',
];

$maxLen = max(array_map('strlen', array_keys($results)));

foreach ($results as $key => $value) {
    echo str_pad($key, $maxLen + 2) . " : {$value}\n";
}

echo "\n";

// ============================================================
// 8. FINAL VERDICT
// ============================================================
if ($allPassed) {
    echo "✅ ALL CRITICAL TABLES VERIFIED - TRANSACTION FULLY RECORDED!\n";
    echo "   Reference: {$reference}\n";
    if ($authId) {
        echo "   Auth ID: {$authId}\n";
    }
    if ($holdId) {
        echo "   Hold ID: {$holdId}\n";
    }
    if ($swapId) {
        echo "   Swap ID: {$swapId}\n";
    }
} else {
    echo "❌ SOME TABLES MISSING - CHECK THE ISSUES ABOVE\n";
    echo "   Reference: {$reference}\n";
}

echo "\n";
echo "========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";

// ============================================================
// 9. OPTIONAL: DISPLAY RAW RESULTS
// ============================================================
if ($argc > 1 && $argv[1] === '--verbose') {
    echo "\n\n=== RAW RESULTS ===\n";
    echo "swap_requests: " . json_encode($swapRequest, JSON_PRETTY_PRINT) . "\n\n";
    echo "holds: " . json_encode($holds, JSON_PRETTY_PRINT) . "\n\n";
    echo "cashout_authorizations: " . json_encode($cashoutAuth, JSON_PRETTY_PRINT) . "\n\n";
}
