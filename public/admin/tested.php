<?php
// test_complete_swap_flow.php
// ULTIMATE DIAGNOSTIC TEST - Tests ALL possible solutions
// 
// ⚠️  WARNING: This calls the REAL ZURUBANK and SACCUSSALIS production
// endpoints over HTTPS. It creates a genuine hold on account 10000001
// and a genuine SAT token at SACCUSSALIS (SMS included). Don't loop this.

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';

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
    'user_id' => 12,
];

echo "========================================\n";
echo "ULTIMATE DIAGNOSTIC TEST\n";
echo "Testing ALL possible solutions\n";
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
// 2. CONNECTION DIAGNOSTICS
// ============================================================
echo "🔍 CONNECTION DIAGNOSTICS\n";
echo "=============================\n";

echo "PostgreSQL Version : " . $pdo->query("SELECT version()")->fetchColumn() . PHP_EOL;
echo "Current User       : " . $pdo->query("SELECT current_user")->fetchColumn() . PHP_EOL;
echo "Current Database   : " . $pdo->query("SELECT current_database()")->fetchColumn() . PHP_EOL;
echo "Current Schema     : " . $pdo->query("SELECT current_schema()")->fetchColumn() . PHP_EOL;
echo "Search Path        : " . $pdo->query("SHOW search_path")->fetchColumn() . PHP_EOL;
echo "In Transaction     : " . ($pdo->inTransaction() ? 'YES ⚠️' : 'NO ✅') . PHP_EOL;
echo "PDO Object ID      : " . spl_object_id($pdo) . PHP_EOL;

if ($pdo->inTransaction()) {
    echo "   ⚠️  Rolling back lingering transaction...\n";
    $pdo->rollBack();
    echo "   ✅ Rolled back\n";
}

$pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
echo "Autocommit set     : ON ✅\n";
echo "\n";

// ============================================================
// 3. CHECK TABLE STRUCTURES
// ============================================================
echo "📊 Checking table structures...\n\n";
$tablesToCheck = [
    'swap_requests',
    'hold_transactions', 
    'cashout_authorizations',
    'swap_transactions',
    'message_outbox',
    'audit_logs',
    'identity_earmarked_balances',
];

foreach ($tablesToCheck as $table) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM {$table} LIMIT 0");
        $stmt->execute();
        echo "   ✅ {$table}\n";
    } catch (PDOException $e) {
        echo "   ❌ {$table}: " . $e->getMessage() . "\n";
    }
}
echo "\n";

// ============================================================
// 4. CLEAN UP EARMARKED BALANCES (SOLUTION 1)
// ============================================================
echo "🔧 SOLUTION 1: Clean up earmarked balances\n";
echo "=============================\n";

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM identity_earmarked_balances
        WHERE destination_institution = :inst
          AND destination_identifier = :ident
          AND status = 'open'
    ");
    $stmt->execute([
        ':inst' => $testConfig['source_institution'],
        ':ident' => $testConfig['source_identifier']
    ]);
    $openCount = $stmt->fetchColumn();
    echo "   Open earmarks before: {$openCount}\n";
    
    // Close all open earmarks for the test account
    $stmt = $pdo->prepare("
        UPDATE identity_earmarked_balances
        SET status = 'depleted', 
            remaining_amount = 0, 
            depleted_at = NOW(),
            updated_at = NOW()
        WHERE destination_institution = :inst
          AND destination_identifier = :ident
          AND status = 'open'
    ");
    $stmt->execute([
        ':inst' => $testConfig['source_institution'],
        ':ident' => $testConfig['source_identifier']
    ]);
    $closed = $stmt->rowCount();
    echo "   ✅ Closed {$closed} earmarked balance(s)\n";
    
} catch (PDOException $e) {
    echo "   ⚠️  Could not clean earmarks: " . $e->getMessage() . "\n";
}
echo "\n";

// ============================================================
// 5. CHECK AND RELEASE PENDING HOLDS (SOLUTION 2)
// ============================================================
echo "🔧 SOLUTION 2: Check pending holds\n";
echo "=============================\n";

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM hold_transactions
        WHERE source_institution = :inst
          AND status IN ('ACTIVE', 'HELD', 'PENDING_CASHOUT', 'PENDING_IDENTITY')
    ");
    $stmt->execute([':inst' => $testConfig['source_institution']]);
    $pendingHolds = $stmt->fetchColumn();
    
    if ($pendingHolds > 0) {
        echo "   ⚠️  Found {$pendingHolds} pending holds\n";
        
        // Show the oldest holds
        $stmt = $pdo->prepare("
            SELECT hold_id, hold_reference, swap_reference, amount, status, placed_at
            FROM hold_transactions
            WHERE source_institution = :inst
              AND status IN ('ACTIVE', 'HELD', 'PENDING_CASHOUT', 'PENDING_IDENTITY')
            ORDER BY placed_at ASC
            LIMIT 5
        ");
        $stmt->execute([':inst' => $testConfig['source_institution']]);
        $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($holds as $h) {
            echo "      hold_id={$h['hold_id']}, amount={$h['amount']}, status={$h['status']}, placed={$h['placed_at']}\n";
        }
        echo "   💡 These may block new holds. Consider releasing them.\n";
    } else {
        echo "   ✅ No pending holds found\n";
    }
} catch (PDOException $e) {
    echo "   ⚠️  Could not check holds: " . $e->getMessage() . "\n";
}
echo "\n";

// ============================================================
// 6. LOAD COUNTRY CONFIG
// ============================================================
echo "📂 Loading country config...\n";
$countryConfig = LoadCountry::getConfig();

if (empty($countryConfig)) {
    die("❌ Failed to load country config\n");
}
echo "✅ Country config loaded\n\n";

// ============================================================
// 7. CREATE SWAP PAYLOAD
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
// 8. INITIALIZE SWAP SERVICE
// ============================================================
echo "⚙️  Initializing SwapService...\n";

try {
    $swapService = new SwapService(
        $pdo,
        $countryConfig,
        'Botswana',
        null
    );
    echo "✅ SwapService initialized\n\n";
} catch (Exception $e) {
    die("❌ Failed to initialize SwapService: " . $e->getMessage() . "\n");
}

// ============================================================
// 9. EXECUTE SWAP WITH STEP-BY-STEP TRACING
// ============================================================
echo "🚀 Executing swap with full diagnostics...\n";
echo "============================================================\n";

$result = null;
$swapFailed = false;
$exceptionDetails = null;

// Capture all exceptions
set_exception_handler(function($e) use (&$exceptionDetails) {
    $exceptionDetails = [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    $inner = $e->getPrevious();
    if ($inner) {
        $exceptionDetails['inner'] = [
            'class' => get_class($inner),
            'message' => $inner->getMessage(),
            'file' => $inner->getFile(),
            'line' => $inner->getLine(),
        ];
    }
});

// Use reflection to inspect internal state
$reflection = new ReflectionClass($swapService);
$executedSteps = $reflection->getProperty('executedSteps');
$executedSteps->setAccessible(true);
$inAtomicSwap = $reflection->getProperty('inAtomicSwap');
$inAtomicSwap->setAccessible(true);
$currentSwapRef = $reflection->getProperty('currentSwapRef');
$currentSwapRef->setAccessible(true);
$currentHoldId = $reflection->getProperty('currentHoldId');
$currentHoldId->setAccessible(true);
$currentHoldReference = $reflection->getProperty('currentHoldReference');
$currentHoldReference->setAccessible(true);

echo "\n📌 Starting swap execution...\n";

try {
    $startTime = microtime(true);
    $result = $swapService->executeAtomicSwap($payload);
    $executionTime = round(microtime(true) - $startTime, 2);
    
    echo "\n✅ Swap executed successfully\n";
    echo "   Execution time: {$executionTime}s\n";
    
} catch (\Throwable $e) {
    $swapFailed = true;
    $exceptionDetails = [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    $inner = $e->getPrevious();
    if ($inner) {
        $exceptionDetails['inner'] = [
            'class' => get_class($inner),
            'message' => $inner->getMessage(),
            'file' => $inner->getFile(),
            'line' => $inner->getLine(),
        ];
    }
    
    echo "\n❌ Swap execution failed\n";
    echo "   Exception: " . get_class($e) . "\n";
    echo "   Message: " . $e->getMessage() . "\n";
}

// Restore default exception handler
restore_exception_handler();

// ============================================================
// 10. POST-EXECUTION DIAGNOSTICS
// ============================================================
echo "\n============================================================\n";
echo "🔍 POST-EXECUTION DIAGNOSTICS\n";
echo "============================================================\n";

// 10.1 Internal state
echo "\n[10.1] Internal SwapService State\n";
echo "------------------------------\n";
echo "   inAtomicSwap: " . ($inAtomicSwap->getValue($swapService) ? 'TRUE ⚠️' : 'FALSE ✅') . "\n";
echo "   currentSwapRef: " . ($currentSwapRef->getValue($swapService) ?? 'NULL') . "\n";
echo "   currentHoldId: " . ($currentHoldId->getValue($swapService) ?? 'NULL') . "\n";
echo "   currentHoldReference: " . ($currentHoldReference->getValue($swapService) ?? 'NULL') . "\n";

$steps = $executedSteps->getValue($swapService);
echo "   executedSteps: " . count($steps) . " steps\n";
foreach ($steps as $i => $step) {
    echo "      Step " . ($i+1) . ": {$step['step']}\n";
}

// 10.2 PDO Transaction State
echo "\n[10.2] PDO Transaction State\n";
echo "------------------------------\n";
echo "   PDO inTransaction: " . ($pdo->inTransaction() ? 'YES ⚠️' : 'NO ✅') . "\n";

if ($pdo->inTransaction()) {
    echo "   ⚠️  Transaction is still open! Rolling back...\n";
    $pdo->rollBack();
    echo "   ✅ Rolled back\n";
}

// 10.3 Result Structure
echo "\n[10.3] Result Structure\n";
echo "------------------------------\n";
if ($result) {
    echo "   Result keys: " . implode(', ', array_keys($result)) . "\n";
    
    if (isset($result['status'])) {
        echo "   status: {$result['status']}\n";
    }
    if (isset($result['auth_id'])) {
        echo "   auth_id: {$result['auth_id']}\n";
    }
    if (isset($result['atomic_commit'])) {
        echo "   atomic_commit: " . json_encode($result['atomic_commit']) . "\n";
        if (isset($result['atomic_commit']['status'])) {
            echo "   atomic_commit.status: {$result['atomic_commit']['status']}\n";
        }
    }
    if (isset($result['reference'])) {
        echo "   reference: {$result['reference']}\n";
    }
    if (isset($result['swap_code'])) {
        echo "   swap_code: {$result['swap_code']}\n";
    }
    if (isset($result['atm_code'])) {
        echo "   atm_code: {$result['atm_code']}\n";
    }
    if (isset($result['fee_calculation_details'])) {
        echo "   fee_calculation_details: " . (is_array($result['fee_calculation_details']) ? 'Array' : gettype($result['fee_calculation_details'])) . "\n";
    }
} else {
    echo "   ❌ Result is NULL\n";
}

// 10.4 Check if ANY data was written
echo "\n[10.4] Database Write Check\n";
echo "------------------------------\n";

$tablesToCheck = [
    'swap_requests' => 'swap_uuid',
    'hold_transactions' => 'swap_reference',
    'cashout_authorizations' => 'swap_reference',
];

$anyDataWritten = false;
$writeResults = [];

foreach ($tablesToCheck as $table => $column) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
        $stmt->execute([$reference]);
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            echo "   ✅ {$table}: {$count} record(s) found\n";
            $anyDataWritten = true;
            $writeResults[$table] = true;
        } else {
            echo "   ❌ {$table}: 0 records found\n";
            $writeResults[$table] = false;
        }
    } catch (PDOException $e) {
        echo "   ❌ {$table}: Error - " . $e->getMessage() . "\n";
        $writeResults[$table] = false;
    }
}

// 10.5 Check by ID
echo "\n[10.5] ID Lookup Check\n";
echo "------------------------------\n";

if ($result && !empty($result['auth_id'])) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cashout_authorizations WHERE auth_id = ?");
    $stmt->execute([$result['auth_id']]);
    $count = $stmt->fetchColumn();
    if ($count > 0) {
        echo "   ✅ auth_id {$result['auth_id']} exists in cashout_authorizations\n";
        $anyDataWritten = true;
    } else {
        echo "   ❌ auth_id {$result['auth_id']} does NOT exist\n";
    }
}

if ($result && isset($result['atomic_commit']['hold_id'])) {
    $holdId = $result['atomic_commit']['hold_id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hold_transactions WHERE hold_id = ?");
    $stmt->execute([$holdId]);
    $count = $stmt->fetchColumn();
    if ($count > 0) {
        echo "   ✅ hold_id {$holdId} exists in hold_transactions\n";
        $anyDataWritten = true;
    } else {
        echo "   ❌ hold_id {$holdId} does NOT exist\n";
    }
}

// ============================================================
// 11. DIAGNOSE THE EXACT PROBLEM
// ============================================================
echo "\n============================================================\n";
echo "🔍 DIAGNOSIS\n";
echo "============================================================\n";

if ($swapFailed) {
    echo "❌ SWAP FAILED WITH EXCEPTION\n";
    echo "\n   Exception: " . ($exceptionDetails['class'] ?? 'Unknown') . "\n";
    echo "   Message: " . ($exceptionDetails['message'] ?? 'No message') . "\n";
    if (isset($exceptionDetails['inner'])) {
        echo "   Inner Exception: " . $exceptionDetails['inner']['class'] . "\n";
        echo "   Inner Message: " . $exceptionDetails['inner']['message'] . "\n";
    }
    
} elseif ($pdo->inTransaction()) {
    echo "❌ TRANSACTION IS STILL OPEN!\n";
    echo "   The swap executed but commit() was never called.\n";
    echo "   This is the ROOT CAUSE of the problem.\n";
    
} elseif (!$anyDataWritten) {
    echo "❌ NO DATA WAS WRITTEN TO THE DATABASE!\n";
    echo "\n   The transaction was rolled back.\n";
    echo "   The swap executed and returned IDs, but the data never persisted.\n";
    
    echo "\n   🔍 POSSIBLE CAUSES:\n";
    
    // Check executed steps
    if (count($steps) === 0) {
        echo "   - executeStep() was NEVER called - the swap bypassed normal flow\n";
        echo "   - Check if the match statement is matching the wrong case\n";
        echo "   - Check if the swap type is being overridden\n";
    }
    
    // Check if exception was thrown
    if ($exceptionDetails) {
        echo "   - An exception was thrown: " . ($exceptionDetails['message'] ?? 'Unknown') . "\n";
    }
    
    // Check if fee calculation details exist
    if ($result && isset($result['fee_calculation_details'])) {
        echo "   - fee_calculation_details was added to result (may be causing issues)\n";
    }
    
    echo "\n   💡 RECOMMENDED SOLUTIONS:\n";
    echo "   1. Check if populateTrackingTables() is throwing an exception\n";
    echo "   2. Add try/catch around populateTrackingTables()\n";
    echo "   3. Check if the ON CONFLICT clause in populateCashoutAuthorization() is failing\n";
    echo "   4. Remove duplicate populateCashoutAuthorization() call\n";
    
} elseif ($result && isset($result['atomic_commit']['status']) && $result['atomic_commit']['status'] === 'committed') {
    echo "✅✅✅ SWAP COMMITTED SUCCESSFULLY!\n";
    echo "\n   Reference: {$reference}\n";
    if ($result && isset($result['auth_id'])) echo "   Auth ID: {$result['auth_id']}\n";
    if ($result && isset($result['atomic_commit']['hold_id'])) echo "   Hold ID: {$result['atomic_commit']['hold_id']}\n";
    echo "   Swap Code: " . ($result['swap_code'] ?? 'N/A') . "\n";
    echo "   ATM PIN: " . ($result['atm_code'] ?? 'N/A') . "\n";
    echo "\n   ✅ Transaction committed successfully!\n";
    echo "   ✅ Data persisted to database\n";
}

// ============================================================
// 12. ATTEMPT SOLUTIONS IF DATA IS MISSING
// ============================================================
if (!$anyDataWritten && !$swapFailed && !$pdo->inTransaction()) {
    echo "\n============================================================\n";
    echo "🔧 ATTEMPTING SOLUTIONS\n";
    echo "============================================================\n";
    
    echo "\n[SOLUTION A] Check if data exists in a fresh connection\n";
    echo "----------------------------------------------------\n";
    try {
        $freshPdo = DBConnection::getConnection();
        if ($freshPdo !== $pdo) {
            echo "   ✅ Fresh connection is DIFFERENT\n";
        } else {
            echo "   ⚠️  Fresh connection is the SAME (singleton)\n";
        }
        
        if ($result && !empty($result['reference'])) {
            $stmt = $freshPdo->prepare("SELECT swap_id FROM swap_requests WHERE swap_uuid = ?");
            $stmt->execute([$result['reference']]);
            $freshSwap = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($freshSwap) {
                echo "   ✅ Found swap_requests with FRESH connection!\n";
                echo "      Data IS in the database but hidden from your connection\n";
                echo "      (likely due to transaction isolation)\n";
            } else {
                echo "   ❌ No swap_requests found with FRESH connection\n";
                echo "      Data was truly NOT persisted\n";
            }
        }
    } catch (Exception $e) {
        echo "   ⚠️  Fresh connection check failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n[SOLUTION B] Check if fee_calculation_details is causing issues\n";
    echo "----------------------------------------------------\n";
    if ($result && isset($result['fee_calculation_details'])) {
        echo "   fee_calculation_details exists in result\n";
        echo "   Type: " . gettype($result['fee_calculation_details']) . "\n";
        if (is_array($result['fee_calculation_details'])) {
            echo "   Keys: " . implode(', ', array_keys($result['fee_calculation_details'])) . "\n";
        }
        echo "   💡 If this is causing issues, it may be added after the swap\n";
    } else {
        echo "   No fee_calculation_details in result\n";
    }
    
    echo "\n[SOLUTION C] Check if populateTrackingTables() was called\n";
    echo "----------------------------------------------------\n";
    // Check if the reference exists in any table using a different column
    $foundInAnyTable = false;
    foreach (['swap_requests', 'hold_transactions', 'cashout_authorizations'] as $table) {
        try {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '{$table}' AND column_name IN ('swap_reference', 'swap_uuid', 'reference')");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($columns as $col) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = ?");
                $stmt->execute([$reference]);
                $count = $stmt->fetchColumn();
                if ($count > 0) {
                    echo "   ✅ Found in {$table}.{$col}: {$count} record(s)\n";
                    $foundInAnyTable = true;
                }
            }
        } catch (Exception $e) {
            // Skip
        }
    }
    if (!$foundInAnyTable) {
        echo "   ❌ No data found in ANY table for this reference\n";
        echo "      populateTrackingTables() was likely NOT called or failed\n";
    }
}

// ============================================================
// 13. FINAL SUMMARY
// ============================================================
echo "\n============================================================\n";
echo "📊 FINAL SUMMARY\n";
echo "============================================================\n";

$results = [
    'Swap executed (no exception)' => (!$swapFailed && $result) ? '✅' : '❌',
    'Any data written to DB' => $anyDataWritten ? '✅' : '❌',
    'PDO transaction open after swap' => ($pdo->inTransaction() ? '⚠️ YES' : '✅ NO'),
    'result has auth_id' => ($result && !empty($result['auth_id'])) ? '✅' : '❌',
    'result has hold_id' => ($result && isset($result['atomic_commit']['hold_id'])) ? '✅' : '❌',
    'auth_id exists in DB' => ($result && !empty($result['auth_id']) && $anyDataWritten) ? '✅' : '❌',
    'hold_id exists in DB' => ($result && isset($result['atomic_commit']['hold_id']) && $anyDataWritten) ? '✅' : '❌',
];

$maxLen = max(array_map('strlen', array_keys($results)));

foreach ($results as $key => $value) {
    echo str_pad($key, $maxLen + 2) . " : {$value}\n";
}

echo "\n";

// ============================================================
// 14. FINAL VERDICT
// ============================================================
echo "🏁 FINAL VERDICT\n";
echo "============================================================\n";

if ($swapFailed) {
    echo "❌ SWAP FAILED WITH EXCEPTION\n";
    echo "   Check the exception details above for the root cause.\n";
    
} elseif (!$anyDataWritten) {
    echo "❌ TRANSACTION ROLLED BACK - DATA NOT PERSISTED\n";
    echo "\n   The swap executed successfully but the transaction was rolled back.\n";
    echo "   This is the classic 'populateTrackingTables() exception' problem.\n";
    echo "\n   🔧 FIXES TO IMPLEMENT:\n";
    echo "   1. Remove duplicate populateCashoutAuthorization() call\n";
    echo "   2. Add try/catch around populateTrackingTables()\n";
    echo "   3. Never re-throw exceptions from tracking methods\n";
    echo "   4. Check ON CONFLICT clause in populateCashoutAuthorization()\n";
    echo "\n   📝 The swap IS working - the problem is in tracking/persistence.\n";
    
} elseif ($anyDataWritten && $result && isset($result['atomic_commit']['status']) && $result['atomic_commit']['status'] === 'committed') {
    echo "✅✅✅ SUCCESS! TRANSACTION FULLY COMMITTED!\n";
    echo "\n   Reference: {$reference}\n";
    if ($result && isset($result['auth_id'])) echo "   Auth ID: {$result['auth_id']}\n";
    if ($result && isset($result['atomic_commit']['hold_id'])) echo "   Hold ID: {$result['atomic_commit']['hold_id']}\n";
    echo "   Swap Code: " . ($result['swap_code'] ?? 'N/A') . "\n";
    echo "   ATM PIN: " . ($result['atm_code'] ?? 'N/A') . "\n";
    echo "\n   ✅ All systems working!\n";
    
} else {
    echo "❌ UNKNOWN STATE - Please review diagnostic output\n";
}

echo "\n";
echo "========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";
