<?php
// test_complete_swap_flow.php
// Complete test: Execute swap and verify all tables
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
// 2. CHECK TABLE STRUCTURES FIRST
// ============================================================
echo "📊 Checking table structures...\n\n";

$tablesToCheck = [
    'swap_requests',
    'hold_transactions', 
    'cashout_authorizations',
    'swap_transactions',
    'message_outbox',
    'audit_logs',
    'identity_earmarked_balances', // NEW: check for stuck dust from earlier tests
];

foreach ($tablesToCheck as $table) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM {$table} LIMIT 0");
        $stmt->execute();
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $col = $stmt->getColumnMeta($i);
            $columns[] = $col['name'];
        }
        echo "   ✅ {$table}: " . implode(', ', $columns) . "\n";
    } catch (PDOException $e) {
        echo "   ❌ {$table}: " . $e->getMessage() . "\n";
    }
}
echo "\n";

// ============================================================
// 2b. NEW: Check for pre-existing earmarked balance dust on this
// account BEFORE running the swap. If this shows an open row with
// remaining_amount just above the threshold, validateEarmarkedWithdrawal()
// may reject the swap before it even reaches verification - which would
// explain a "nothing recorded" failure with zero DB writes.
// ============================================================
echo "🔍 Checking for pre-existing earmarked balance on source account...\n";
try {
    $stmt = $pdo->prepare("
        SELECT id, remaining_amount, smallest_note_amount, total_cashout_fee_amount, status, created_at
        FROM identity_earmarked_balances
        WHERE destination_institution = :inst
          AND destination_identifier = :ident
          AND status = 'open'
        ORDER BY created_at ASC
    ");
    $stmt->execute([
        ':inst' => $testConfig['source_institution'],
        ':ident' => $testConfig['source_identifier'],
    ]);
    $earmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($earmarks) {
        echo "   ⚠️  FOUND " . count($earmarks) . " OPEN earmarked balance(s) on this account:\n";
        foreach ($earmarks as $e) {
            $threshold = (float)$e['smallest_note_amount'] + (float)$e['total_cashout_fee_amount'];
            echo "      id={$e['id']} remaining={$e['remaining_amount']} threshold={$threshold} created_at={$e['created_at']}\n";
        }
        echo "      If (remaining - test_amount) is > 0 and <= threshold, validateEarmarkedWithdrawal()\n";
        echo "      will REJECT this swap before verification even starts.\n";
    } else {
        echo "   ✅ No open earmarked balances on this account - not a factor\n";
    }
} catch (PDOException $e) {
    echo "   ⚠️  Could not check earmarked balances: " . $e->getMessage() . "\n";
}
echo "\n";

// ============================================================
// 3. LOAD COUNTRY CONFIG
// ============================================================
echo "📂 Loading country config...\n";
$countryConfig = LoadCountry::getConfig();

if (empty($countryConfig)) {
    die("❌ Failed to load country config\n");
}

echo "✅ Country config loaded\n\n";

// ============================================================
// 4. CREATE SWAP PAYLOAD
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
// 5. INITIALIZE SWAP SERVICE
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
// 6. EXECUTE THE SWAP
// ============================================================
echo "🚀 Executing swap...\n";

$result = null;
$swapFailed = false;

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

    if (isset($result['auth_id']))         echo "   Auth ID: {$result['auth_id']}\n";
    if (isset($result['swap_code']))       echo "   Swap Code: " . var_export($result['swap_code'], true) . "\n";
    if (isset($result['atm_code']))        echo "   ATM PIN: " . var_export($result['atm_code'], true) . "\n";
    if (isset($result['voucher_number']))  echo "   Voucher Number: " . var_export($result['voucher_number'], true) . "\n";

    // NEW: dump the full result so nothing is hidden - this is the
    // equivalent of the [DIAG] log lines, but guaranteed in-sync with
    // whatever code is actually running (no opcache/deploy ambiguity).
    echo "\n   --- FULL RESULT ARRAY ---\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    echo "   --- END RESULT ARRAY ---\n\n";

} catch (\Throwable $e) {
    // NEW: this is the fix that matters most. executeAtomicSwap() wraps
    // the real failure in RuntimeException("Swap failed: ...", 0, $e) -
    // the ORIGINAL exception (with the real message/step) is in
    // getPrevious(), and the old script threw it away entirely.
    $swapFailed = true;
    echo "❌ Swap execution failed\n";
    echo "   Outer message: " . $e->getMessage() . "\n";

    $inner = $e->getPrevious();
    if ($inner) {
        echo "   Inner exception class: " . get_class($inner) . "\n";
        echo "   Inner message: " . $inner->getMessage() . "\n";
        echo "   Inner trace (first 5 frames):\n";
        $trace = explode("\n", $inner->getTraceAsString());
        foreach (array_slice($trace, 0, 5) as $line) {
            echo "      {$line}\n";
        }
    } else {
        echo "   (no inner exception - this IS the original error)\n";
        echo "   Trace (first 5 frames):\n";
        $trace = explode("\n", $e->getTraceAsString());
        foreach (array_slice($trace, 0, 5) as $line) {
            echo "      {$line}\n";
        }
    }
    echo "\n";
    // Deliberately NOT dying here - we still want to check which tables
    // (if any) got partially written before the rollback, since
    // rollbackAtomicSwap() only undoes the local PDO transaction, not
    // any real bank-side hold that may have already been placed.
}

// ============================================================
// 7. VERIFY ALL TABLES - DIRECT QUERIES WITH CORRECT COLUMNS
// ============================================================
echo "═══════════════════════════════════════════════════════\n";
echo "🔍 VERIFYING TABLES\n";
echo "═══════════════════════════════════════════════════════\n\n";

// NEW: pre-declare every variable used in the summary so nothing is
// undefined if a check is skipped or throws.
$allPassed = true;
$swapId = null;
$holdId = null;
$authId = null;
$swapRequest = null;
$holds = null;
$cashoutAuth = null;
$swapTx = null;
$swapCodePopulated = null; // NEW: explicit tri-state (null=no row, true/false=row exists)

// -----------------------------------------------------------------
// 7.1 swap_requests
// -----------------------------------------------------------------
echo "📋 1. swap_requests\n";
try {
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
        echo "      created_at: {$swapRequest['created_at']}\n";
        $swapId = $swapRequest['swap_id'];
    } else {
        echo "   ❌ NOT FOUND\n";
        $allPassed = false;
    }
} catch (PDOException $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    $allPassed = false;
}
echo "\n";

// -----------------------------------------------------------------
// 7.2 hold_transactions
// -----------------------------------------------------------------
echo "📋 2. hold_transactions\n";
try {
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
            echo "      hold_reference: {$hold['hold_reference']}\n";
            echo "      amount: {$hold['amount']}\n";
            echo "      status: {$hold['status']}\n";
            $holdId = $hold['hold_id'];
        }
    } else {
        echo "   ❌ NOT FOUND\n";
        $allPassed = false;
    }
} catch (PDOException $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    $allPassed = false;
}
echo "\n";

// -----------------------------------------------------------------
// 7.3 cashout_authorizations
// -----------------------------------------------------------------
echo "📋 3. cashout_authorizations\n";
try {
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
            $codeEmpty = empty($auth['swap_code']);
            $pinEmpty = empty($auth['pin_code']);
            $swapCodePopulated = !$codeEmpty;

            echo "      auth_id: {$auth['auth_id']}\n";
            echo "      client_phone: {$auth['client_phone']}\n";
            echo "      amount: {$auth['amount']}\n";
            echo "      swap_code: " . var_export($auth['swap_code'], true) . ($codeEmpty ? "   ⚠️ EMPTY - THIS IS THE BUG" : "   ✅") . "\n";
            echo "      pin_code: " . var_export($auth['pin_code'], true) . ($pinEmpty ? "   ⚠️ EMPTY" : "   ✅") . "\n";
            echo "      source_identifier: " . var_export($auth['source_identifier'] ?? null, true) . "\n";
            echo "      status: {$auth['status']}\n";
            $authId = $auth['auth_id'];

            // NEW: explicit hard assertion, not just a print
            if ($codeEmpty) {
                echo "      ❌ ASSERTION FAILED: swap_code must not be empty for a generated cashout\n";
                $allPassed = false;
            }
        }
    } else {
        echo "   ❌ NOT FOUND\n";
        $allPassed = false;
    }
} catch (PDOException $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    $allPassed = false;
}
echo "\n";

// -----------------------------------------------------------------
// 7.4 swap_transactions
// -----------------------------------------------------------------
echo "📋 4. swap_transactions\n";
if ($swapId) {
    try {
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
                echo "      transaction_id: {$tx['transaction_id']}\n";
                echo "      amount: {$tx['amount']}\n";
                echo "      status: {$tx['status']}\n";
                echo "      user_id: {$tx['user_id']}\n";
            }
        } else {
            echo "   ❌ NOT FOUND\n";
            $allPassed = false;
        }
    } catch (PDOException $e) {
        echo "   ❌ ERROR: " . $e->getMessage() . "\n";
        $allPassed = false;
    }
} else {
    echo "   ⏭️  SKIPPED (no swap_id)\n";
}
echo "\n";

// -----------------------------------------------------------------
// 7.5 message_outbox
// -----------------------------------------------------------------
echo "📋 5. message_outbox\n";
try {
    $stmt = $pdo->prepare("
        SELECT * FROM message_outbox 
        WHERE destination = :phone
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([':phone' => $testConfig['beneficiary_phone']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($messages) {
        echo "   ✅ FOUND " . count($messages) . " message(s)\n";
        foreach ($messages as $msg) {
            echo "      message_id: {$msg['message_id']}\n";
            echo "      destination: {$msg['destination']}\n";
            echo "      status: {$msg['status']}\n";
        }
    } else {
        echo "   ⚠️  No messages found (SMS may not be configured, or cashout_code was empty - populateMessageOutbox() skips if empty)\n";
    }
} catch (PDOException $e) {
    echo "   ⚠️  Could not query message_outbox: " . $e->getMessage() . "\n";
}
echo "\n";

// -----------------------------------------------------------------
// 7.6 audit_logs
// -----------------------------------------------------------------
echo "📋 6. audit_logs\n";
try {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs LIMIT 0");
    $stmt->execute();
    $cols = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $col = $stmt->getColumnMeta($i);
        $cols[] = $col['name'];
    }

    if (in_array('metadata', $cols)) {
        $stmt = $pdo->prepare("
            SELECT * FROM audit_logs 
            WHERE metadata::text LIKE :search_pattern
            ORDER BY performed_at DESC
            LIMIT 5
        ");
        $stmt->execute([':search_pattern' => '%' . $reference . '%']);
    } elseif (in_array('entity_id', $cols)) {
        $stmt = $pdo->prepare("
            SELECT * FROM audit_logs 
            WHERE entity_id = :entity_id
            ORDER BY performed_at DESC
            LIMIT 5
        ");
        $stmt->execute([':entity_id' => $reference]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM audit_logs 
            WHERE message LIKE :search_pattern OR details LIKE :search_pattern
            ORDER BY performed_at DESC
            LIMIT 5
        ");
        $stmt->execute([':search_pattern' => '%' . $reference . '%']);
    }

    $audits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($audits) {
        echo "   ✅ FOUND " . count($audits) . " audit record(s)\n";
        foreach ($audits as $audit) {
            echo "      action: " . ($audit['action'] ?? 'N/A') . "\n";
            echo "      category: " . ($audit['category'] ?? 'N/A') . "\n";
        }
    } else {
        echo "   ⚠️  No audit records found\n";
    }
} catch (PDOException $e) {
    echo "   ⚠️  Could not query audit_logs: " . $e->getMessage() . "\n";
}
echo "\n";

// -----------------------------------------------------------------
// 7.7 user_source_accounts (source check)
// -----------------------------------------------------------------
echo "📋 7. user_source_accounts (source check)\n";
try {
    $stmt = $pdo->prepare("
        SELECT * FROM user_source_accounts 
        WHERE user_id = :user_id 
        AND institution = :institution 
        AND identifier = :identifier
        AND status = 'active'
    ");
    $stmt->execute([
        ':user_id' => $testConfig['user_id'],
        ':institution' => $testConfig['source_institution'],
        ':identifier' => $testConfig['source_identifier']
    ]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($source) {
        echo "   ✅ FOUND source account\n";
        echo "      id: {$source['id']}\n";
        echo "      asset_type: {$source['asset_type']}\n";
        echo "      status: {$source['status']}\n";
    } else {
        echo "   ⚠️  Source account not found in user_source_accounts\n";
    }
} catch (PDOException $e) {
    echo "   ⚠️  Could not query user_source_accounts: " . $e->getMessage() . "\n";
}
echo "\n";

// ============================================================
// 8. SUMMARY
// ============================================================
echo "═══════════════════════════════════════════════════════\n";
echo "📊 TEST SUMMARY\n";
echo "═══════════════════════════════════════════════════════\n";

$results = [
    'Swap executed (no exception)' => (!$swapFailed && $result) ? '✅' : '❌',
    'swap_requests'                => $swapRequest ? '✅' : '❌',
    'hold_transactions'            => $holds ? '✅' : '❌',
    'cashout_authorizations (row)' => $cashoutAuth ? '✅' : '❌',
    'cashout_authorizations (swap_code populated)' => $swapCodePopulated === true ? '✅' : ($swapCodePopulated === false ? '❌ EMPTY' : '⏭️  N/A'),
    'swap_transactions'            => $swapTx ? '✅' : '❌',
];

$maxLen = max(array_map('strlen', array_keys($results)));

foreach ($results as $key => $value) {
    echo str_pad($key, $maxLen + 2) . " : {$value}\n";
}

echo "\n";

// ============================================================
// 9. FINAL VERDICT
// ============================================================
if (!$swapFailed && $allPassed && $swapRequest && $holds && $cashoutAuth && $swapCodePopulated === true) {
    echo "✅ ALL CRITICAL TABLES VERIFIED - TRANSACTION FULLY RECORDED!\n";
    echo "   Reference: {$reference}\n";
    if ($authId) echo "   Auth ID: {$authId}\n";
    if ($holdId) echo "   Hold ID: {$holdId}\n";
    if ($swapId) echo "   Swap ID: {$swapId}\n";
} else {
    echo "❌ ISSUES FOUND - see details above\n";
    echo "   Reference: {$reference}\n";
    echo "\n🔍 DIAGNOSIS:\n";
    if ($swapFailed) echo "   - executeAtomicSwap() THREW - see 'Inner message' above for the real cause and step\n";
    if (!$swapRequest) echo "   - swap_requests not populated - check populateTrackingTables()\n";
    if (!$holds) echo "   - hold_transactions not populated - check createLocalHold()\n";
    if (!$cashoutAuth) echo "   - cashout_authorizations not populated at all - check storeCashoutAuthorization()\n";
    if ($cashoutAuth && $swapCodePopulated === false) echo "   - cashout_authorizations row EXISTS but swap_code is empty - check GenericInstitutionAdapter::generateCashoutToken() mapping (this is the known bug)\n";
    if ($swapRequest && !$swapTx) echo "   - swap_transactions not populated - check populateSwapTransaction()\n";
}

echo "\n";
echo "========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";
