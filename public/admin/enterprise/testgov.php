<?php
/**
 * admin/enterprise/test.php
 * 
 * Enterprise System Health Check & Test Script
 * Run this to verify everything is working correctly
 */

// ============================================================
// 1. ENVIRONMENT CHECK
// ============================================================
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>VouchMorph Enterprise - System Test</title>
    <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap' rel='stylesheet'>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #0f172a; padding: 40px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .sub { color: #64748b; margin-bottom: 32px; }
        .test-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .test-card { background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; }
        .test-card h3 { font-size: 14px; font-weight: 600; margin-bottom: 12px; }
        .test-card .status { display: inline-block; padding: 2px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .status-pass { background: #dcfce7; color: #166534; }
        .status-fail { background: #fee2e2; color: #991b1b; }
        .status-warn { background: #fef3c7; color: #92400e; }
        .detail { font-size: 13px; color: #64748b; margin-top: 8px; font-family: monospace; word-break: break-all; }
        .success { background: #dcfce7; border-left: 4px solid #10b981; padding: 16px; border-radius: 8px; margin: 12px 0; }
        .error { background: #fee2e2; border-left: 4px solid #ef4444; padding: 16px; border-radius: 8px; margin: 12px 0; }
        .warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px; border-radius: 8px; margin: 12px 0; }
        pre { background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
        .summary-box { background: #0f172a; color: white; padding: 24px; border-radius: 12px; margin-top: 24px; }
        .summary-box h3 { color: #8A6D3B; margin-bottom: 8px; }
        .summary-box p { color: #94a3b8; }
        .summary-box .links { margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
        .summary-box .links a { background: #8A6D3B; color: #0f172a; padding: 8px 20px; border-radius: 20px; text-decoration: none; font-weight: 600; }
        .summary-box .links a.secondary { background: #1e293b; color: #e2e8f0; }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔧 VouchMorph Enterprise System Test</h1>
    <p class='sub'>Running comprehensive health check for the enterprise module</p>";

// ============================================================
// 2. LOAD REQUIRED FILES
// ============================================================
echo "<div class='test-grid'>";

// Test 1: Auth.php
echo "<div class='test-card'>";
echo "<h3>1. Auth System</h3>";
try {
    require_once 'auth.php';
    echo "<span class='status status-pass'>✅ PASS</span>";
    echo "<div class='detail'>auth.php loaded successfully</div>";
    
    // Check functions
    $functions = ['getDBConnection', 'requireEnterpriseAuth', 'hasPermission', 'generateCsrfToken', 'getCurrentUser'];
    $missing = [];
    foreach ($functions as $func) {
        if (!function_exists($func)) $missing[] = $func;
    }
    if (empty($missing)) {
        echo "<div class='detail'>✅ All auth functions available</div>";
    } else {
        echo "<div class='detail'>❌ Missing functions: " . implode(', ', $missing) . "</div>";
    }
} catch (Exception $e) {
    echo "<span class='status status-fail'>❌ FAIL</span>";
    echo "<div class='detail'>" . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Test 2: Database Connection
echo "<div class='test-card'>";
echo "<h3>2. Database Connection</h3>";
try {
    $pdo = getDBConnection();
    echo "<span class='status status-pass'>✅ PASS</span>";
    echo "<div class='detail'>Connected to database successfully</div>";
    
    // Test query
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    if ($result && $result['test'] == 1) {
        echo "<div class='detail'>✅ Database query works</div>";
    }
} catch (Exception $e) {
    echo "<span class='status status-fail'>❌ FAIL</span>";
    echo "<div class='detail'>" . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Test 3: Required Tables
echo "<div class='test-card'>";
echo "<h3>3. Database Tables</h3>";
try {
    $pdo = getDBConnection();
    $requiredTables = [
        'disbursement_batches',
        'disbursement_destinations',
        'source_accounts',
        'batch_approvals',
        'organization_audit_logs',
        'organizations',
        'organization_users',
        'users'
    ];
    
    $missingTables = [];
    foreach ($requiredTables as $table) {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :table");
        $stmt->execute([':table' => $table]);
        if (!$stmt->fetch()) {
            $missingTables[] = $table;
        }
    }
    
    if (empty($missingTables)) {
        echo "<span class='status status-pass'>✅ PASS</span>";
        echo "<div class='detail'>All required tables exist</div>";
    } else {
        echo "<span class='status status-warn'>⚠️ WARN</span>";
        echo "<div class='detail'>Missing tables: " . implode(', ', $missingTables) . "</div>";
    }
} catch (Exception $e) {
    echo "<span class='status status-fail'>❌ FAIL</span>";
    echo "<div class='detail'>" . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Test 4: Session
echo "<div class='test-card'>";
echo "<h3>4. Session System</h3>";
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    echo "<span class='status status-pass'>✅ PASS</span>";
    echo "<div class='detail'>Session ID: " . session_id() . "</div>";
    echo "<div class='detail'>Session status: " . session_status() . "</div>";
} catch (Exception $e) {
    echo "<span class='status status-fail'>❌ FAIL</span>";
    echo "<div class='detail'>" . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Test 5: Critical Files
echo "<div class='test-card'>";
echo "<h3>5. Critical Files</h3>";
try {
    $criticalFiles = [
        'auth.php' => true,
        'index.php' => true,
        'login.php' => true,
        'logout.php' => true,
        'beneficiaries.php' => true,
        'reports.php' => true,
        'settings.php' => true,
        'imports/source_input.php' => true,
        'imports/add_destinations.php' => true,
        'imports/review_batch.php' => true,
        'imports/approve.php' => true,
        'imports/execute.php' => true,
        'imports/manual_entry.php' => true,
        'batches/index.php' => true,
        'batches/view.php' => true,
    ];
    
    $missingFiles = [];
    foreach ($criticalFiles as $file => $required) {
        $fullPath = __DIR__ . '/' . $file;
        if (!file_exists($fullPath)) {
            $missingFiles[] = $file;
        }
    }
    
    if (empty($missingFiles)) {
        echo "<span class='status status-pass'>✅ PASS</span>";
        echo "<div class='detail'>All critical files exist</div>";
    } else {
        echo "<span class='status status-warn'>⚠️ WARN</span>";
        echo "<div class='detail'>Missing: " . implode(', ', $missingFiles) . "</div>";
    }
} catch (Exception $e) {
    echo "<span class='status status-fail'>❌ FAIL</span>";
    echo "<div class='detail'>" . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

echo "</div>"; // end test-grid

// ============================================================
// 6. Current User Check
// ============================================================
echo "<div class='test-grid'>";
echo "<div class='test-card'>";
echo "<h3>6. Current User Session</h3>";
try {
    if (isset($_SESSION['enterprise_user'])) {
        $user = $_SESSION['enterprise_user'];
        echo "<span class='status status-pass'>✅ Logged In</span>";
        echo "<div class='detail'>User: " . htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Unknown') . "</div>";
        echo "<div class='detail'>Role: " . htmlspecialchars($user['role'] ?? 'Unknown') . "</div>";
        echo "<div class='detail'>Organization: " . htmlspecialchars($user['organization_name'] ?? 'Unknown') . "</div>";
        echo "<div class='detail'>Department: " . htmlspecialchars($user['department_id'] ?? 'None') . "</div>";
    } else {
        echo "<span class='status status-warn'>⚠️ Not Logged In</span>";
        echo "<div class='detail'>No active enterprise user session</div>";
        echo "<div class='detail'><a href='login.php' style='color: #8A6D3B;'>Login here</a></div>";
    }
} catch (Exception $e) {
    echo "<span class='status status-fail'>❌ FAIL</span>";
    echo "<div class='detail'>" . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Test 7: CSRF Protection
echo "<div class='test-card'>";
echo "<h3>7. CSRF Protection</h3>";
try {
    $token = generateCsrfToken();
    echo "<span class='status status-pass'>✅ PASS</span>";
    echo "<div class='detail'>Token generated: " . substr($token, 0, 20) . "...</div>";
    
    $verified = verifyCsrfToken($token);
    echo "<div class='detail'>Token verification: " . ($verified ? '✅ Pass' : '❌ Fail') . "</div>";
} catch (Exception $e) {
    echo "<span class='status status-fail'>❌ FAIL</span>";
    echo "<div class='detail'>" . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

echo "</div>"; // end test-grid

// ============================================================
// 7. SUMMARY
// ============================================================
echo "<div class='summary-box'>";
echo "<h3>📊 Test Summary</h3>";
echo "<p>All tests completed. ";
echo "If all tests passed, your enterprise module is ready to use.<br>";
echo "If any tests failed, please check the details above and fix the issues.</p>";

echo "<div class='links'>";
echo "<a href='index.php'>📊 Dashboard</a>";
echo "<a href='login.php' class='secondary'>🔑 Login</a>";
echo "<a href='imports/source_input.php' class='secondary'>💰 New Disbursement</a>";
echo "<a href='batches/index.php' class='secondary'>📋 Batches</a>";
echo "</div>";
echo "</div>";

echo "</div></body></html>";
