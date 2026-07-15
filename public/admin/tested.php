<?php
/**
 * test_dashboard_db.php - Test script for admin_dashboard.php database connectivity
 * Place this file in the same directory as admin_dashboard.php and run it directly
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================================
// CONFIGURATION
// ============================================================
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load required classes
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;

// ============================================================
// COLOR CODES FOR OUTPUT
// ============================================================
define('COLOR_GREEN', "\033[32m");
define('COLOR_RED', "\033[31m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_CYAN', "\033[36m");
define('COLOR_WHITE', "\033[37m");
define('COLOR_RESET', "\033[0m");

// Check if running in CLI or browser
$isCli = (php_sapi_name() === 'cli');

function output($message, $type = 'info', $indent = 0) {
    $prefix = str_repeat('  ', $indent);
    $colors = [
        'info' => COLOR_BLUE,
        'success' => COLOR_GREEN,
        'error' => COLOR_RED,
        'warning' => COLOR_YELLOW,
        'header' => COLOR_CYAN,
    ];
    
    $color = $colors[$type] ?? COLOR_WHITE;
    
    if (php_sapi_name() === 'cli') {
        echo $color . $prefix . $message . COLOR_RESET . PHP_EOL;
    } else {
        $htmlClass = $type;
        echo "<div class='test-output {$htmlClass}' style='padding: 4px 8px; margin: 2px 0; font-family: monospace; font-size: 13px;'>";
        echo str_repeat('&nbsp;&nbsp;', $indent);
        echo htmlspecialchars($message);
        echo "</div>";
    }
}

function outputHeader($message) {
    $line = str_repeat('=', strlen($message) + 10);
    output($line, 'header');
    output("  " . $message, 'header');
    output($line, 'header');
}

function outputSubHeader($message) {
    output("--- " . $message . " ---", 'info');
}

function outputSuccess($message, $indent = 0) {
    output("✅ " . $message, 'success', $indent);
}

function outputError($message, $indent = 0) {
    output("❌ " . $message, 'error', $indent);
}

function outputWarning($message, $indent = 0) {
    output("⚠️ " . $message, 'warning', $indent);
}

function outputInfo($message, $indent = 0) {
    output("ℹ️ " . $message, 'info', $indent);
}

function outputData($message, $indent = 0) {
    output("📊 " . $message, 'info', $indent);
}

// ============================================================
// HTML HEADER FOR BROWSER
// ============================================================
if (!$isCli) {
    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard Database Test</title>
    <style>
        body { font-family: 'Courier New', monospace; background: #1a1a2e; color: #e0e0e0; padding: 20px; max-width: 1200px; margin: 0 auto; }
        .test-output { padding: 6px 12px; margin: 2px 0; border-radius: 4px; }
        .test-output.info { background: #1a2a3a; color: #6cb4ee; }
        .test-output.success { background: #1a3a2a; color: #6ecc8e; }
        .test-output.error { background: #3a1a1a; color: #ee6b6b; }
        .test-output.warning { background: #3a3a1a; color: #eedd6b; }
        .test-output.header { background: #2a2a4a; color: #88ddff; font-weight: bold; }
        .summary-box { background: #2a2a3a; border-radius: 8px; padding: 16px; margin: 16px 0; border: 1px solid #444; }
        .summary-box.pass { border-color: #6ecc8e; }
        .summary-box.fail { border-color: #ee6b6b; }
        .summary-box.warn { border-color: #eedd6b; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 8px 0; }
        th { background: #2a2a4a; padding: 6px 10px; text-align: left; }
        td { padding: 4px 10px; border-bottom: 1px solid #333; }
        .value-null { color: #888; font-style: italic; }
        .value-number { color: #88ddff; }
        .value-string { color: #dd88ff; }
        .value-boolean { color: #88ff88; }
    </style>
</head>
<body>
    <h1>🔍 Dashboard Database Test</h1>
    <div style="margin-bottom: 20px; color: #888; font-size: 14px;">
        Testing database connectivity and data loading for admin_dashboard.php
    </div>
HTML;
}

// ============================================================
// START TESTS
// ============================================================
$testResults = [
    'passed' => 0,
    'failed' => 0,
    'warnings' => 0,
    'total' => 0
];

function recordResult($type) {
    global $testResults;
    $testResults['total']++;
    if ($type === 'pass') $testResults['passed']++;
    elseif ($type === 'fail') $testResults['failed']++;
    elseif ($type === 'warn') $testResults['warnings']++;
}

outputHeader("DATABASE CONNECTION TESTS");

// ============================================================
// TEST 1: Database Connection
// ============================================================
outputSubHeader("1. Testing Database Connection");

try {
    $db = DBConnection::getConnection();
    if ($db) {
        outputSuccess("Database connection successful", 1);
        recordResult('pass');
    } else {
        outputError("Database connection failed - returned null", 1);
        recordResult('fail');
        exit("Cannot continue without database connection.");
    }
} catch (Throwable $e) {
    outputError("Database connection exception: " . $e->getMessage(), 1);
    outputError("File: " . $e->getFile() . " Line: " . $e->getLine(), 2);
    recordResult('fail');
    exit("Cannot continue without database connection.");
}

// ============================================================
// TEST 2: Database Query Test
// ============================================================
outputSubHeader("2. Testing Basic Query");

try {
    $result = $db->query("SELECT 1 as test");
    if ($result) {
        $row = $result->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['test'] == 1) {
            outputSuccess("Basic query successful", 1);
            recordResult('pass');
        } else {
            outputError("Query returned unexpected result", 1);
            recordResult('fail');
        }
    } else {
        outputError("Query failed", 1);
        recordResult('fail');
    }
} catch (Throwable $e) {
    outputError("Query exception: " . $e->getMessage(), 1);
    recordResult('fail');
}

// ============================================================
// TEST 3: Check Critical Tables
// ============================================================
outputSubHeader("3. Checking Critical Tables");

$criticalTables = [
    'swap_requests',
    'users',
    'hold_transactions',
    'cashout_authorizations',
    'fee_invoices',
    'audit_logs',
    'settlement_queue',
    'swap_fee_collections',
    'participants',
    'swap_transactions',
    'cross_border_messages',
    'payment_instructions'
];

$missingTables = [];
$tableCounts = [];

foreach ($criticalTables as $table) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table");
        $stmt->execute([':table' => $table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        
        if ($exists) {
            // Get row count
            try {
                $countStmt = $db->query("SELECT COUNT(*) FROM {$table}");
                $count = (int)$countStmt->fetchColumn();
                $tableCounts[$table] = $count;
                outputSuccess("Table '{$table}' exists with {$count} rows", 1);
            } catch (Throwable $e) {
                outputWarning("Table '{$table}' exists but count failed: " . $e->getMessage(), 1);
                recordResult('warn');
            }
            recordResult('pass');
        } else {
            outputError("Table '{$table}' does NOT exist", 1);
            $missingTables[] = $table;
            recordResult('fail');
        }
    } catch (Throwable $e) {
        outputError("Error checking table '{$table}': " . $e->getMessage(), 1);
        recordResult('fail');
    }
}

if (!empty($missingTables)) {
    outputWarning("Missing tables: " . implode(', ', $missingTables), 1);
}

// ============================================================
// TEST 4: Check Table Structure
// ============================================================
outputSubHeader("4. Checking Table Structure");

$tableStructures = [
    'swap_requests' => ['swap_id', 'swap_uuid', 'amount', 'from_currency', 'to_currency', 'status', 'created_at'],
    'users' => ['user_id', 'username', 'email', 'phone', 'verified', 'created_at'],
    'fee_invoices' => ['invoice_id', 'invoice_uuid', 'fee_amount', 'total_amount', 'status', 'created_at'],
];

foreach ($tableStructures as $table => $expectedColumns) {
    try {
        $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table");
        $stmt->execute([':table' => $table]);
        $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $missingColumns = array_diff($expectedColumns, $existingColumns);
        
        if (empty($missingColumns)) {
            outputSuccess("Table '{$table}' has all expected columns", 1);
            recordResult('pass');
        } else {
            outputWarning("Table '{$table}' missing columns: " . implode(', ', $missingColumns), 1);
            recordResult('warn');
        }
    } catch (Throwable $e) {
        outputError("Error checking structure for '{$table}': " . $e->getMessage(), 1);
        recordResult('fail');
    }
}

// ============================================================
// TEST 5: Test Data Queries
// ============================================================
outputSubHeader("5. Testing Data Queries");

// Test 5a: Swap Requests
try {
    $stmt = $db->query("SELECT COUNT(*) as count, MIN(created_at) as oldest, MAX(created_at) as newest FROM swap_requests");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        outputData("Swap Requests: {$result['count']} records", 1);
        outputInfo("Oldest: {$result['oldest']}, Newest: {$result['newest']}", 2);
        
        if ($result['count'] > 0) {
            outputSuccess("Swap requests data available", 1);
            recordResult('pass');
        } else {
            outputWarning("No swap requests found - database may be empty", 1);
            recordResult('warn');
        }
    } else {
        outputError("Failed to query swap_requests", 1);
        recordResult('fail');
    }
} catch (Throwable $e) {
    outputError("Swap requests query error: " . $e->getMessage(), 1);
    recordResult('fail');
}

// Test 5b: Recent Swaps
try {
    $stmt = $db->query("
        SELECT 
            swap_uuid, amount, status, created_at 
        FROM swap_requests 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($recent)) {
        outputSuccess("Recent swaps data available (5 most recent)", 1);
        outputInfo("  Most recent: {$recent[0]['swap_uuid']} - {$recent[0]['amount']} ({$recent[0]['status']})", 2);
        recordResult('pass');
    } else {
        outputWarning("No recent swaps found", 1);
        recordResult('warn');
    }
} catch (Throwable $e) {
    outputError("Recent swaps query error: " . $e->getMessage(), 1);
    recordResult('fail');
}

// Test 5c: Fee Invoices
try {
    $stmt = $db->query("SELECT COUNT(*) as count, SUM(fee_amount) as total_fees, SUM(total_amount) as total_amount FROM fee_invoices");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        outputData("Fee Invoices: {$result['count']} records", 1);
        outputInfo("Total Fees: " . number_format($result['total_fees'] ?? 0, 2) . ", Total Amount: " . number_format($result['total_amount'] ?? 0, 2), 2);
        
        if ($result['count'] > 0) {
            outputSuccess("Fee invoice data available", 1);
            recordResult('pass');
        } else {
            outputWarning("No fee invoices found", 1);
            recordResult('warn');
        }
    } else {
        outputError("Failed to query fee_invoices", 1);
        recordResult('fail');
    }
} catch (Throwable $e) {
    outputError("Fee invoices query error: " . $e->getMessage(), 1);
    recordResult('fail');
}

// Test 5d: Users
try {
    $stmt = $db->query("SELECT COUNT(*) as count, COUNT(DISTINCT role_id) as roles FROM users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        outputData("Users: {$result['count']} records, {$result['roles']} distinct roles", 1);
        
        if ($result['count'] > 0) {
            outputSuccess("User data available", 1);
            recordResult('pass');
        } else {
            outputWarning("No users found", 1);
            recordResult('warn');
        }
    } else {
        outputError("Failed to query users", 1);
        recordResult('fail');
    }
} catch (Throwable $e) {
    outputError("Users query error: " . $e->getMessage(), 1);
    recordResult('fail');
}

// ============================================================
// TEST 6: Test Metrics Queries
// ============================================================
outputSubHeader("6. Testing Metrics Queries");

$metricsQueries = [
    'total_swaps' => "SELECT COUNT(*) FROM swap_requests",
    'total_holds' => "SELECT COUNT(*) FROM hold_transactions",
    'total_cashouts' => "SELECT COUNT(*) FROM cashout_authorizations",
    'total_invoices' => "SELECT COUNT(*) FROM fee_invoices",
    'total_audit_logs' => "SELECT COUNT(*) FROM audit_logs",
];

foreach ($metricsQueries as $metric => $query) {
    try {
        $stmt = $db->query($query);
        $count = (int)$stmt->fetchColumn();
        outputData("{$metric}: {$count}", 1);
        recordResult('pass');
    } catch (Throwable $e) {
        outputError("Failed to query {$metric}: " . $e->getMessage(), 1);
        recordResult('fail');
    }
}

// ============================================================
// TEST 7: Test JSON Field Access
// ============================================================
outputSubHeader("7. Testing JSON Field Access");

try {
    // Check if source_details JSON field exists and can be queried
    $stmt = $db->prepare("
        SELECT source_details 
        FROM swap_requests 
        WHERE source_details IS NOT NULL 
        LIMIT 1
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && isset($result['source_details'])) {
        $json = json_decode($result['source_details'], true);
        if ($json && is_array($json)) {
            outputSuccess("JSON fields are accessible and valid", 1);
            outputInfo("Sample JSON keys: " . implode(', ', array_keys($json)), 2);
            recordResult('pass');
        } else {
            outputWarning("JSON field exists but is not valid JSON", 1);
            recordResult('warn');
        }
    } else {
        outputWarning("No swap_requests with source_details found", 1);
        recordResult('warn');
    }
} catch (Throwable $e) {
    outputError("JSON field test failed: " . $e->getMessage(), 1);
    recordResult('fail');
}

// ============================================================
// TEST 8: Check Session and Role Data
// ============================================================
outputSubHeader("8. Checking Session Data (simulated)");

// Check if we're in a session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sessionCheck = [
    'admin_id' => $_SESSION['admin_id'] ?? null,
    'admin_username' => $_SESSION['admin_username'] ?? null,
    'admin_full_name' => $_SESSION['admin_full_name'] ?? null,
    'admin_role_id' => $_SESSION['admin_role_id'] ?? null,
    'admin_country' => $_SESSION['admin_country'] ?? null,
];

if ($sessionCheck['admin_id']) {
    outputSuccess("Session data found for admin ID: {$sessionCheck['admin_id']}", 1);
    outputInfo("Username: {$sessionCheck['admin_username']}, Role ID: {$sessionCheck['admin_role_id']}", 2);
    recordResult('pass');
} else {
    outputWarning("No admin session data found - dashboard may not be logged in", 1);
    outputInfo("To test with session, login first then run this test", 2);
    recordResult('warn');
}

// ============================================================
// TEST 9: Check Database Permissions
// ============================================================
outputSubHeader("9. Checking Database Permissions");

try {
    // Test SELECT permission
    $db->query("SELECT 1");
    outputSuccess("SELECT permission: OK", 1);
    recordResult('pass');
    
    // Test INSERT permission (with rollback)
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO audit_logs (action, performed_by_id) VALUES ('TEST_PERMISSION_CHECK', 0)");
        $stmt->execute();
        $db->rollBack();
        outputSuccess("INSERT permission: OK", 1);
        recordResult('pass');
    } catch (Throwable $e) {
        // Not all tables may allow empty values, try an alternative
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO swap_requests (swap_uuid, amount, status) VALUES ('TEST', 0, 'TEST')");
            $stmt->execute();
            $db->rollBack();
            outputSuccess("INSERT permission: OK (alternative table)", 1);
            recordResult('pass');
        } catch (Throwable $e2) {
            outputWarning("INSERT permission: Limited - " . $e2->getMessage(), 1);
            recordResult('warn');
        }
    }
} catch (Throwable $e) {
    outputError("Database permission check failed: " . $e->getMessage(), 1);
    recordResult('fail');
}

// ============================================================
// SUMMARY
// ============================================================
outputHeader("TEST SUMMARY");

$total = $testResults['total'];
$passed = $testResults['passed'];
$failed = $testResults['failed'];
$warnings = $testResults['warnings'];

if ($isCli) {
    echo "\n";
}

$summaryData = [
    ['Test', 'Count', 'Status'],
    ['Passed', $passed, "✅"],
    ['Failed', $failed, "❌"],
    ['Warnings', $warnings, "⚠️"],
    ['Total', $total, "📊"],
];

if ($isCli) {
    foreach ($summaryData as $row) {
        printf("  %-10s %-6s %s\n", $row[0], $row[1], $row[2]);
    }
    echo "\n";
    
    if ($failed === 0 && $warnings === 0) {
        echo COLOR_GREEN . "✅ ALL TESTS PASSED!" . COLOR_RESET . "\n";
    } elseif ($failed === 0 && $warnings > 0) {
        echo COLOR_YELLOW . "⚠️ PASSED WITH WARNINGS (" . $warnings . ")" . COLOR_RESET . "\n";
    } else {
        echo COLOR_RED . "❌ " . $failed . " TESTS FAILED" . COLOR_RESET . "\n";
    }
} else {
    echo <<<HTML
    <div class="summary-box " . ($failed === 0 ? 'pass' : ($warnings > 0 ? 'warn' : 'fail')) . ">
        <table>
            <thead>
                <tr><th>Test</th><th>Count</th><th>Status</th></tr>
            </thead>
            <tbody>
HTML;
    
    foreach ($summaryData as $row) {
        $color = match($row[0]) {
            'Passed' => '#6ecc8e',
            'Failed' => '#ee6b6b',
            'Warnings' => '#eedd6b',
            'Total' => '#88ddff',
            default => '#ffffff'
        };
        echo "<tr><td>{$row[0]}</td><td>{$row[1]}</td><td style='color:{$color}'>{$row[2]}</td></tr>";
    }
    
    echo <<<HTML
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 20px; padding: 16px; background: #1a2a3a; border-radius: 8px;">
        <h3>💡 Troubleshooting Tips</h3>
        <ul style="color: #ccc; line-height: 1.8;">
HTML;
    
    if ($missingTables) {
        echo "<li style='color: #ee6b6b;'>⚠️ Missing tables: " . implode(', ', $missingTables) . " - Run migrations to create them.</li>";
    }
    
    if (!$sessionCheck['admin_id']) {
        echo "<li style='color: #eedd6b;'>⚠️ No admin session found - Login first or check session management.</li>";
    }
    
    if ($tableCounts['swap_requests'] === 0 && $tableCounts['fee_invoices'] === 0) {
        echo "<li style='color: #eedd6b;'>⚠️ No data found in critical tables - Database may be empty.</li>";
    }
    
    echo <<<HTML
            <li style='color: #88ddff;'>ℹ️ Check file permissions for DBConnection.php</li>
            <li style='color: #88ddff;'>ℹ️ Verify database credentials in config</li>
            <li style='color: #88ddff;'>ℹ️ Check if tables are in correct schema ('public')</li>
        </ul>
    </div>
HTML;
}

// ============================================================
// FOOTER
// ============================================================
if (!$isCli) {
    echo <<<HTML
    <div style="margin-top: 20px; color: #666; font-size: 12px; text-align: center; border-top: 1px solid #333; padding-top: 16px;">
        Test completed at: <?php echo date('Y-m-d H:i:s'); ?>
    </div>
</body>
</html>
HTML;
}

// ============================================================
// EXIT WITH APPROPRIATE CODE
// ============================================================
exit($failed > 0 ? 1 : 0);
?>
