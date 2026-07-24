<?php
// test_complete_swap_flow.php
// Complete test: Execute swap and verify all tables with ULTRA-AGGRESSIVE DIAGNOSTICS
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
echo "COMPLETE SWAP FLOW TEST (ULTRA DIAGNOSTIC)\n";
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
echo "In Transaction     : " . ($pdo->inTransaction() ? 'YES ⚠️' : 'NO ✅') . PHP_EOL;
echo "PDO Object ID      : " . spl_object_id($pdo) . PHP_EOL;

if ($pdo->inTransaction()) {
    echo "   ⚠️  Rolling back lingering transaction...\n";
    $pdo->rollBack();
    echo "   ✅ Rolled back\n";
}

// Ensure autocommit is ON
try {
    $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
    echo "Autocommit set     : ON ✅\n";
} catch (PDOException $e) {
    echo "Autocommit set     : ⚠️ " . $e->getMessage() . "\n";
}
echo "\n";

// ============================================================
// 3. ENABLE PDO EXCEPTIONS AND DEBUG
// ============================================================
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// Create a custom error handler to catch PDO exceptions
$lastException = null;
set_exception_handler(function($e) use (&$lastException) {
    $lastException = $e;
    echo "\n🔥 EXCEPTION CAUGHT: " . get_class($e) . "\n";
    echo "   Message: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Trace:\n" . $e->getTraceAsString() . "\n";
});

// ============================================================
// 4. LOAD COUNTRY CONFIG
// ============================================================
echo "📂 Loading country config...\n";
$countryConfig = LoadCountry::getConfig();

if (empty($countryConfig)) {
    die("❌ Failed to load country config\n");
}
echo "✅ Country config loaded\n\n";

// ============================================================
// 5. CHECK PARTICIPANTS
// ============================================================
$participants = $countryConfig['participants'] ?? [];
echo "🔍 PARTICIPANT CHECK\n";
echo "=============================\n";
echo "   Source '{$testConfig['source_institution']}': " . (isset($participants[$testConfig['source_institution']]) ? '✅ FOUND' : '❌ NOT FOUND') . "\n";
echo "   Destination '{$testConfig['destination_institution']}': " . (isset($participants[$testConfig['destination_institution']]) ? '✅ FOUND' : '❌ NOT FOUND') . "\n";
echo "\n";

// ============================================================
// 6. CHECK TABLE STRUCTURES
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
echo "🚀 Executing swap with step-by-step tracing...\n";
echo "============================================================\n";

$result = null;
$swapFailed = false;
$exceptionDetails = null;

// Create a reflection to access private methods for testing
$reflection = new ReflectionClass($swapService);

// Get the current swap reference before execution
$currentSwapRef = $reflection->getProperty('currentSwapRef');
$currentSwapRef->setAccessible(true);

// Get the inAtomicSwap flag
$inAtomicSwap = $reflection->getProperty('inAtomicSwap');
$inAtomicSwap->setAccessible(true);

// Get the executedSteps
$executedSteps = $reflection->getProperty('executedSteps');
$executedSteps->setAccessible(true);

// Get the stepResults
$stepResults = $reflection->getProperty('stepResults');
$stepResults->setAccessible(true);

echo "📌 Starting swap execution...\n";

try {
    $startTime = microtime(true);
    
    // ============================================================
    // STEP 1: Execute the swap
    // ============================================================
    echo "\n[STEP 1] Calling executeAtomicSwap()...\n";
    $result = $swapService->executeAtomicSwap($payload);
    $executionTime = round(microtime(true) - $startTime, 2);
    
    echo "[STEP 1] ✅ executeAtomicSwap() returned successfully\n";
    echo "   Execution time: {$executionTime}s\n";
    
    // ============================================================
    // STEP 2: Check the state after execution
    // ============================================================
    echo "\n[STEP 2] Checking internal state...\n";
    
    // Check inAtomicSwap flag
    $inAtomic = $inAtomicSwap->getValue($swapService);
    echo "   inAtomicSwap: " . ($inAtomic ? 'TRUE ⚠️' : 'FALSE ✅') . "\n";
    
    // Check current swap reference
    $swapRef = $currentSwapRef->getValue($swapService);
    echo "   currentSwapRef: " . ($swapRef ?? 'NULL') . "\n";
    
    // Check executed steps
    $steps = $executedSteps->getValue($swapService);
    echo "   executedSteps: " . count($steps) . " steps\n";
    foreach ($steps as $i => $step) {
        echo "      Step " . ($i+1) . ": {$step['step']}\n";
    }
    
    // ============================================================
    // STEP 3: Check PDO transaction state
    // ============================================================
    echo "\n[STEP 3] Checking PDO transaction state...\n";
    $inTransaction = $pdo->inTransaction();
    echo "   PDO inTransaction: " . ($inTransaction ? 'YES ⚠️' : 'NO ✅') . "\n";
    
    if ($inTransaction) {
        echo "   ⚠️  Transaction is still open! This means commit() was NOT called.\n";
        echo "   🔍 Rolling back to clean up...\n";
        $pdo->rollBack();
        echo "   ✅ Rolled back\n";
    }
    
    // ============================================================
    // STEP 4: Check result structure
    // ============================================================
    echo "\n[STEP 4] Checking result structure...\n";
    
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
    } else {
        echo "   ❌ Result is NULL\n";
    }

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
    
    echo "\n❌ SWAP EXECUTION FAILED\n";
    echo "   Exception class: " . get_class($e) . "\n";
    echo "   Message: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Trace:\n" . $e->getTraceAsString() . "\n";

    $inner = $e->getPrevious();
    if ($inner) {
        echo "\n   INNER EXCEPTION:\n";
        echo "   Class: " . get_class($inner) . "\n";
        echo "   Message: " . $inner->getMessage() . "\n";
        echo "   File: " . $inner->getFile() . ":" . $inner->getLine() . "\n";
        echo "   Trace:\n" . $inner->getTraceAsString() . "\n";
        
        $exceptionDetails['inner'] = [
            'class' => get_class($inner),
            'message' => $inner->getMessage(),
            'file' => $inner->getFile(),
            'line' => $inner->getLine(),
        ];
    }
}

// ============================================================
// 10. POST-EXECUTION DATABASE STATE CHECK
// ============================================================
echo "\n============================================================\n";
echo "🔍 POST-EXECUTION DATABASE STATE\n";
echo "============================================================\n";

// Check if any data was written at all
echo "\n📊 Checking if ANY data was written...\n";

$tablesToCheck = [
    'swap_requests' => 'swap_uuid',
    'hold_transactions' => 'swap_reference',
    'cashout_authorizations' => 'swap_reference',
];

$anyDataWritten = false;
foreach ($tablesToCheck as $table => $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$reference]);
    $count = $stmt->fetchColumn();
    if ($count > 0) {
        echo "   ✅ {$table}: {$count} record(s) found\n";
        $anyDataWritten = true;
    } else {
        echo "   ❌ {$table}: 0 records found\n";
    }
}

// Check if auth_id exists (by ID, not reference)
if ($result && !empty($result['auth_id'])) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cashout_authorizations WHERE auth_id = ?");
    $stmt->execute([$result['auth_id']]);
    $count = $stmt->fetchColumn();
    if ($count > 0) {
        echo "   ✅ auth_id {$result['auth_id']} exists in cashout_authorizations\n";
        $anyDataWritten = true;
    } else {
        echo "   ❌ auth_id {$result['auth_id']} does NOT exist in cashout_authorizations\n";
    }
}

// Check if hold_id exists
if ($result && isset($result['atomic_commit']['hold_id'])) {
    $holdId = $result['atomic_commit']['hold_id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hold_transactions WHERE hold_id = ?");
    $stmt->execute([$holdId]);
    $count = $stmt->fetchColumn();
    if ($count > 0) {
        echo "   ✅ hold_id {$holdId} exists in hold_transactions\n";
        $anyDataWritten = true;
    } else {
        echo "   ❌ hold_id {$holdId} does NOT exist in hold_transactions\n";
    }
}

// ============================================================
// 11. CHECK FOR CONSTRAINTS AND ERRORS
// ============================================================
echo "\n🔍 CHECKING FOR DATABASE CONSTRAINTS\n";
echo "============================================================\n";

// Check if there's a unique constraint violation on swap_reference
try {
    $stmt = $pdo->prepare("
        SELECT conname, contype, pg_get_constraintdef(oid) 
        FROM pg_constraint 
        WHERE conrelid = 'cashout_authorizations'::regclass 
        AND contype = 'u'
    ");
    $stmt->execute();
    $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($constraints) {
        echo "   Cashout authorizations unique constraints:\n";
        foreach ($constraints as $constraint) {
            echo "      - {$constraint['conname']}: {$constraint['pg_get_constraintdef']}\n";
        }
    }
} catch (Exception $e) {
    echo "   ⚠️  Could not check constraints: " . $e->getMessage() . "\n";
}

// Check for duplicate references
try {
    $stmt = $pdo->prepare("
        SELECT swap_reference, COUNT(*) as cnt 
        FROM cashout_authorizations 
        WHERE swap_reference = ?
        GROUP BY swap_reference
    ");
    $stmt->execute([$reference]);
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($duplicates) {
        echo "   ⚠️  Found duplicates for reference '{$reference}':\n";
        foreach ($duplicates as $dup) {
            echo "      swap_reference: {$dup['swap_reference']}, count: {$dup['cnt']}\n";
        }
    } else {
        echo "   ✅ No duplicates found for reference '{$reference}'\n";
    }
} catch (Exception $e) {
    echo "   ⚠️  Could not check duplicates: " . $e->getMessage() . "\n";
}

// ============================================================
// 12. CRITICAL: CHECK IF DATA EXISTS IN A FRESH CONNECTION
// ============================================================
echo "\n🔍 CHECKING WITH FRESH CONNECTION\n";
echo "============================================================\n";

// Get a fresh connection (bypass any transaction issues)
try {
    $freshPdo = DBConnection::getConnection();
    $freshPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($freshPdo === $pdo) {
        echo "   ⚠️  Fresh connection is the SAME as test connection\n";
        echo "   (DBConnection is returning the same singleton)\n";
    } else {
        echo "   ✅ Fresh connection is DIFFERENT from test connection\n";
    }
    
    // Try to find the reference with the fresh connection
    if ($result && !empty($result['reference'])) {
        $stmt = $freshPdo->prepare("
            SELECT swap_id FROM swap_requests WHERE swap_uuid = ?
        ");
        $stmt->execute([$result['reference']]);
        $freshSwap = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($freshSwap) {
            echo "   ✅ Found swap_requests with FRESH connection!\n";
            echo "      This means the data IS in the database but hidden from your current connection\n";
            echo "      (likely due to transaction isolation - your connection is in a transaction)\n";
        } else {
            echo "   ❌ No swap_requests found with FRESH connection either\n";
            echo "      The data was truly NOT persisted (rollback happened)\n";
        }
    }
    
    // Try to find by auth_id with fresh connection
    if ($result && !empty($result['auth_id'])) {
        $stmt = $freshPdo->prepare("
            SELECT auth_id FROM cashout_authorizations WHERE auth_id = ?
        ");
        $stmt->execute([$result['auth_id']]);
        $freshAuth = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($freshAuth) {
            echo "   ✅ Found auth_id with FRESH connection!\n";
        } else {
            echo "   ❌ No auth_id found with FRESH connection\n";
        }
    }
    
    // Try to find by hold_id with fresh connection
    if ($result && isset($result['atomic_commit']['hold_id'])) {
        $stmt = $freshPdo->prepare("
            SELECT hold_id FROM hold_transactions WHERE hold_id = ?
        ");
        $stmt->execute([$result['atomic_commit']['hold_id']]);
        $freshHold = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($freshHold) {
            echo "   ✅ Found hold_id with FRESH connection!\n";
        } else {
            echo "   ❌ No hold_id found with FRESH connection\n";
        }
    }
    
} catch (Exception $e) {
    echo "   ⚠️  Fresh connection check failed: " . $e->getMessage() . "\n";
}

// ============================================================
// 13. SUMMARY
// ============================================================
echo "\n============================================================\n";
echo "📊 TEST SUMMARY\n";
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
// 14. FINAL VERDICT WITH PRECISE DIAGNOSIS
// ============================================================
echo "🏁 FINAL VERDICT\n";
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
    echo "   This indicates the transaction is being left open.\n";
    echo "   Check:\n";
    echo "   1. Is commitAtomicSwap() being called?\n";
    echo "   2. Is there an exception being swallowed?\n";
    echo "   3. Is the rollback code path being triggered?\n";
    
} elseif (!$anyDataWritten) {
    echo "❌ NO DATA WAS WRITTEN TO THE DATABASE!\n";
    echo "\n   This means the transaction was rolled back.\n";
    echo "   The swap executed and returned IDs, but the data never persisted.\n";
    echo "\n   🔍 PRECISE DIAGNOSIS:\n";
    echo "   - The rollback is happening AFTER the swap returns\n";
    echo "   - The rollback is happening in the outer try/catch block\n";
    echo "   - Check if an exception is being thrown in commitAtomicSwap()\n";
    echo "   - Check if populateTrackingTables() is throwing an exception\n";
    echo "\n   💡 FIX: Add try/catch around commitAtomicSwap() and populateTrackingTables()\n";
    
} elseif ($result && isset($result['atomic_commit']['status']) && $result['atomic_commit']['status'] === 'committed') {
    echo "✅✅✅ SWAP COMMITTED SUCCESSFULLY!\n";
    echo "\n   Reference: {$reference}\n";
    if ($result && isset($result['auth_id'])) echo "   Auth ID: {$result['auth_id']}\n";
    if ($result && isset($result['atomic_commit']['hold_id'])) echo "   Hold ID: {$result['atomic_commit']['hold_id']}\n";
    echo "\n   ✅ Transaction committed\n";
    echo "   ✅ Data persisted to database\n";
    
} else {
    echo "❌ UNKNOWN STATE\n";
    echo "   Please review the diagnostic output above.\n";
}

echo "\n";
echo "========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";
