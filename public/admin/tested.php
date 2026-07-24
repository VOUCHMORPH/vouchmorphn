echo "\n=============================\n";
echo "DATABASE DIAGNOSTICS\n";
echo "=============================\n";

echo "Current DB : "
    . $pdo->query("SELECT current_database()")->fetchColumn()
    . PHP_EOL;

echo "Current Schema : "
    . $pdo->query("SELECT current_schema()")->fetchColumn()
    . PHP_EOL;

// -----------------------------------------------------------------
// 1. Latest swap_requests
// -----------------------------------------------------------------
echo "\n📋 Latest swap_requests\n";

$stmt = $pdo->query("
    SELECT swap_id,
           swap_uuid,
           created_at,
           amount,
           status,
           user_id
    FROM swap_requests
    ORDER BY swap_id DESC
    LIMIT 5
");

$swapRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($swapRequests) {
    echo "   ✅ FOUND " . count($swapRequests) . " record(s)\n";
    foreach ($swapRequests as $row) {
        echo "      swap_id: {$row['swap_id']}, uuid: {$row['swap_uuid']}, amount: {$row['amount']}, status: {$row['status']}, user_id: {$row['user_id']}\n";
    }
} else {
    echo "   ❌ No records found\n";
}

// -----------------------------------------------------------------
// 2. Latest hold_transactions
// -----------------------------------------------------------------
echo "\n📋 Latest hold_transactions\n";

$stmt = $pdo->query("
    SELECT hold_id,
           swap_reference,
           hold_reference,
           amount,
           status,
           placed_at
    FROM hold_transactions
    ORDER BY hold_id DESC
    LIMIT 5
");

$holds = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($holds) {
    echo "   ✅ FOUND " . count($holds) . " record(s)\n";
    foreach ($holds as $row) {
        echo "      hold_id: {$row['hold_id']}, swap_ref: {$row['swap_reference']}, amount: {$row['amount']}, status: {$row['status']}\n";
    }
} else {
    echo "   ❌ No records found\n";
}

// -----------------------------------------------------------------
// 3. Latest cashout_authorizations (with swap_code check)
// -----------------------------------------------------------------
echo "\n📋 Latest cashout_authorizations\n";

$stmt = $pdo->query("
    SELECT auth_id,
           swap_reference,
           swap_code,
           pin_code,
           amount,
           client_phone,
           status,
           created_at
    FROM cashout_authorizations
    ORDER BY auth_id DESC
    LIMIT 5
");

$cashoutAuth = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($cashoutAuth) {
    echo "   ✅ FOUND " . count($cashoutAuth) . " record(s)\n";
    foreach ($cashoutAuth as $row) {
        $codeEmpty = empty($row['swap_code']);
        $pinEmpty = empty($row['pin_code']);
        echo "      auth_id: {$row['auth_id']}, swap_ref: {$row['swap_reference']}\n";
        echo "      swap_code: " . var_export($row['swap_code'], true) . ($codeEmpty ? "   ⚠️ EMPTY" : "   ✅") . "\n";
        echo "      pin_code: " . var_export($row['pin_code'], true) . ($pinEmpty ? "   ⚠️ EMPTY" : "   ✅") . "\n";
        echo "      amount: {$row['amount']}, status: {$row['status']}\n";
    }
} else {
    echo "   ❌ No records found\n";
}

// -----------------------------------------------------------------
// 4. Latest swap_transactions
// -----------------------------------------------------------------
echo "\n📋 Latest swap_transactions\n";

$stmt = $pdo->query("
    SELECT transaction_id,
           swap_id,
           amount,
           status,
           user_id,
           created_at
    FROM swap_transactions
    ORDER BY transaction_id DESC
    LIMIT 5
");

$swapTx = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($swapTx) {
    echo "   ✅ FOUND " . count($swapTx) . " record(s)\n";
    foreach ($swapTx as $row) {
        echo "      transaction_id: {$row['transaction_id']}, swap_id: {$row['swap_id']}, amount: {$row['amount']}, status: {$row['status']}\n";
    }
} else {
    echo "   ❌ No records found\n";
}

// -----------------------------------------------------------------
// 5. Latest message_outbox
// -----------------------------------------------------------------
echo "\n📋 Latest message_outbox\n";

$stmt = $pdo->query("
    SELECT message_id,
           destination,
           subject,
           status,
           created_at
    FROM message_outbox
    ORDER BY message_id DESC
    LIMIT 5
");

$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($messages) {
    echo "   ✅ FOUND " . count($messages) . " record(s)\n";
    foreach ($messages as $row) {
        echo "      message_id: {$row['message_id']}, destination: {$row['destination']}, status: {$row['status']}\n";
    }
} else {
    echo "   ⚠️  No messages found\n";
}

// -----------------------------------------------------------------
// 6. Latest audit_logs
// -----------------------------------------------------------------
echo "\n📋 Latest audit_logs\n";

// Check audit_logs structure first
try {
    $stmt = $pdo->query("SELECT * FROM audit_logs LIMIT 0");
    $stmt->execute();
    $cols = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $col = $stmt->getColumnMeta($i);
        $cols[] = $col['name'];
    }
    
    // Build query based on available columns
    $selectFields = [];
    if (in_array('audit_log_id', $cols)) $selectFields[] = 'audit_log_id';
    if (in_array('id', $cols)) $selectFields[] = 'id';
    if (in_array('action', $cols)) $selectFields[] = 'action';
    if (in_array('category', $cols)) $selectFields[] = 'category';
    if (in_array('performed_at', $cols)) $selectFields[] = 'performed_at';
    if (in_array('created_at', $cols)) $selectFields[] = 'created_at';
    
    if (empty($selectFields)) {
        $selectFields = ['*'];
    }
    
    $stmt = $pdo->query("
        SELECT " . implode(', ', $selectFields) . "
        FROM audit_logs
        ORDER BY " . (in_array('performed_at', $cols) ? 'performed_at' : 'created_at') . " DESC
        LIMIT 5
    ");
    
    $audits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($audits) {
        echo "   ✅ FOUND " . count($audits) . " record(s)\n";
        foreach ($audits as $row) {
            $action = $row['action'] ?? $row['category'] ?? 'N/A';
            $time = $row['performed_at'] ?? $row['created_at'] ?? 'N/A';
            echo "      {$action}: {$time}\n";
        }
    } else {
        echo "   ⚠️  No audit records found\n";
    }
} catch (PDOException $e) {
    echo "   ⚠️  Could not query audit_logs: " . $e->getMessage() . "\n";
}

// -----------------------------------------------------------------
// 7. Check for orphaned/open earmarked balances
// -----------------------------------------------------------------
echo "\n📋 Open earmarked balances (potential issue)\n";

try {
    $stmt = $pdo->query("
        SELECT id,
               destination_institution,
               destination_identifier,
               remaining_amount,
               smallest_note_amount,
               total_cashout_fee_amount,
               status,
               created_at
        FROM identity_earmarked_balances
        WHERE status = 'open'
        ORDER BY created_at DESC
        LIMIT 5
    ");
    
    $earmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($earmarks) {
        echo "   ⚠️  FOUND " . count($earmarks) . " OPEN earmarked balance(s)\n";
        foreach ($earmarks as $row) {
            $threshold = (float)$row['smallest_note_amount'] + (float)$row['total_cashout_fee_amount'];
            echo "      id={$row['id']}, institution={$row['destination_institution']}, identifier={$row['destination_identifier']}\n";
            echo "      remaining={$row['remaining_amount']}, threshold={$threshold}\n";
            echo "      created_at={$row['created_at']}\n";
        }
        echo "      ⚠️  These can cause validateEarmarkedWithdrawal() to reject new swaps\n";
    } else {
        echo "   ✅ No open earmarked balances found\n";
    }
} catch (PDOException $e) {
    echo "   ⚠️  Could not query identity_earmarked_balances: " . $e->getMessage() . "\n";
}

echo "\n=============================\n";
echo "DIAGNOSTICS COMPLETE\n";
echo "=============================\n";
