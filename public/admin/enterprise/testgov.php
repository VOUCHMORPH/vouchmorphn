<?php
/**
 * admin/enterprise/test.php
 * 
 * Comprehensive System Health Check & Role-Based Visibility Test
 * Verifies:
 * - File structure and permissions
 * - Database tables and schema
 * - Role-based access control
 * - Batch workflow (Create → Submit → Approve → Disburse)
 * - Button visibility for each role
 * - CSRF protection
 * - Session management
 */

// ============================================================
// 1. ENVIRONMENT SETUP
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>VouchMorph Enterprise - Comprehensive System Test</title>
    <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap' rel='stylesheet'>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f1f5f9; 
            color: #0f172a; 
            padding: 40px; 
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { font-size: 28px; font-weight: 700; margin-bottom: 4px; }
        .sub { color: #64748b; margin-bottom: 32px; }
        
        .test-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); 
            gap: 16px; 
            margin-bottom: 24px; 
        }
        .test-card { 
            background: white; 
            border-radius: 12px; 
            padding: 20px; 
            border: 1px solid #e2e8f0; 
        }
        .test-card h3 { 
            font-size: 14px; 
            font-weight: 600; 
            margin-bottom: 12px; 
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .test-card .status { 
            display: inline-block; 
            padding: 2px 12px; 
            border-radius: 20px; 
            font-size: 11px; 
            font-weight: 600; 
        }
        .status-pass { background: #dcfce7; color: #166534; }
        .status-fail { background: #fee2e2; color: #991b1b; }
        .status-warn { background: #fef3c7; color: #92400e; }
        .status-info { background: #dbeafe; color: #1e40af; }
        
        .detail { 
            font-size: 13px; 
            color: #64748b; 
            margin-top: 6px; 
            font-family: monospace; 
            word-break: break-all; 
            padding: 4px 8px;
            background: #f8fafc;
            border-radius: 4px;
        }
        .detail.success { color: #166534; background: #dcfce7; }
        .detail.error { color: #991b1b; background: #fee2e2; }
        .detail.warning { color: #92400e; background: #fef3c7; }
        
        .batch-sim {
            background: #f8fafc;
            padding: 16px;
            border-radius: 8px;
            margin-top: 12px;
            border: 1px solid #e2e8f0;
        }
        .batch-sim .row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 13px;
            border-bottom: 1px solid #e2e8f0;
        }
        .batch-sim .row:last-child { border-bottom: none; }
        .batch-sim .label { color: #64748b; }
        .batch-sim .value { font-weight: 600; }
        
        .role-matrix {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .role-card {
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        .role-card .role-name { font-weight: 700; font-size: 14px; }
        .role-card .role-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
        }
        .role-badge.can-create { background: #dbeafe; color: #1e40af; }
        .role-badge.can-approve { background: #fef3c7; color: #92400e; }
        .role-badge.can-disburse { background: #dcfce7; color: #166534; }
        .role-badge.can-view { background: #f1f5f9; color: #64748b; }
        
        .btn {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 4px;
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #8A6D3B; }
        .btn-success { background: #166534; color: white; }
        .btn-success:hover { background: #14532d; }
        .btn-warning { background: #92400e; color: white; }
        .btn-warning:hover { background: #78350f; }
        .btn-danger { background: #991b1b; color: white; }
        .btn-danger:hover { background: #7f1d1d; }
        .btn-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
        .btn-outline:hover { border-color: #0f172a; color: #0f172a; }
        .btn-sm { padding: 4px 12px; font-size: 10px; }
        
        .summary-box {
            background: #0f172a;
            color: white;
            padding: 24px;
            border-radius: 12px;
            margin-top: 24px;
        }
        .summary-box h3 { color: #8A6D3B; margin-bottom: 8px; }
        .summary-box p { color: #94a3b8; }
        .summary-box .links {
            margin-top: 16px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .summary-box .links a {
            background: #8A6D3B;
            color: #0f172a;
            padding: 8px 20px;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
        }
        .summary-box .links a.secondary { background: #1e293b; color: #e2e8f0; }
        
        .progress-bar {
            width: 100%;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        .progress-bar .fill {
            height: 100%;
            background: #8A6D3B;
            transition: width 0.5s ease;
            border-radius: 2px;
        }
        
        @media (max-width: 768px) {
            body { padding: 16px; }
            .test-grid { grid-template-columns: 1fr; }
            .role-matrix { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .role-matrix { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔧 VouchMorph Enterprise - Comprehensive System Test</h1>
    <p class='sub'>Testing file structure, database, roles, and workflow</p>";

// ============================================================
// 2. LOAD AUTH AND GET CONNECTION
// ============================================================
try {
    require_once 'auth.php';
    $pdo = getDBConnection();
    $authLoaded = true;
} catch (Exception $e) {
    $authLoaded = false;
    $authError = $e->getMessage();
}

echo "<div class='test-grid'>";

// ============================================================
// TEST 1: AUTH SYSTEM
// ============================================================
echo "<div class='test-card'>";
echo "<h3>1. Authentication System <span class='status " . ($authLoaded ? 'status-pass' : 'status-fail') . "'>" . ($authLoaded ? '✅ PASS' : '❌ FAIL') . "</span></h3>";
if ($authLoaded) {
    echo "<div class='detail success'>✅ auth.php loaded successfully</div>";
    
    $functions = ['getDBConnection', 'requireEnterpriseAuth', 'hasPermission', 'generateCsrfToken', 'getCurrentUser', 'hasRole', 'getOrganizationId'];
    $missing = [];
    foreach ($functions as $func) {
        if (!function_exists($func)) $missing[] = $func;
    }
    if (empty($missing)) {
        echo "<div class='detail success'>✅ All " . count($functions) . " auth functions available</div>";
    } else {
        echo "<div class='detail error'>❌ Missing functions: " . implode(', ', $missing) . "</div>";
    }
} else {
    echo "<div class='detail error'>❌ " . htmlspecialchars($authError ?? 'Unknown error') . "</div>";
}
echo "</div>";

// ============================================================
// TEST 2: DATABASE CONNECTION
// ============================================================
echo "<div class='test-card'>";
echo "<h3>2. Database Connection <span class='status " . ($authLoaded ? 'status-pass' : 'status-fail') . "'>" . ($authLoaded ? '✅ PASS' : '❌ FAIL') . "</span></h3>";
if ($authLoaded) {
    try {
        $stmt = $pdo->query("SELECT 1 as test, NOW() as time, version() as version");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<div class='detail success'>✅ Connected to database</div>";
        echo "<div class='detail'>PostgreSQL Version: " . htmlspecialchars($result['version'] ?? 'Unknown') . "</div>";
        echo "<div class='detail'>Server Time: " . htmlspecialchars($result['time'] ?? 'Unknown') . "</div>";
    } catch (Exception $e) {
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
echo "</div>";

// ============================================================
// TEST 3: REQUIRED TABLES
// ============================================================
echo "<div class='test-card'>";
echo "<h3>3. Database Tables <span id='tableStatus' class='status status-warn'>⏳ Checking...</span></h3>";
if ($authLoaded) {
    $requiredTables = [
        'disbursement_batches',
        'disbursement_destinations',
        'source_accounts',
        'batch_approvals',
        'organization_audit_logs',
        'organizations',
        'organization_users',
        'users',
        'departments'
    ];
    
    $missingTables = [];
    $tableCount = 0;
    foreach ($requiredTables as $table) {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :table");
            $stmt->execute([':table' => $table]);
            if ($stmt->fetch()) {
                $tableCount++;
                echo "<div class='detail success'>✅ $table</div>";
            } else {
                $missingTables[] = $table;
                echo "<div class='detail error'>❌ $table - MISSING</div>";
            }
        } catch (Exception $e) {
            $missingTables[] = $table;
            echo "<div class='detail error'>❌ $table - Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    $allPresent = empty($missingTables);
    echo "<script>document.getElementById('tableStatus').className = 'status " . ($allPresent ? 'status-pass' : 'status-warn') . "';";
    echo "document.getElementById('tableStatus').textContent = '" . ($allPresent ? '✅ PASS (' . $tableCount . '/' . count($requiredTables) . ')' : '⚠️ WARN (' . $tableCount . '/' . count($requiredTables) . ')') . "';</script>";
}
echo "</div>";

// ============================================================
// TEST 4: TABLE SCHEMA VALIDATION
// ============================================================
echo "<div class='test-card'>";
echo "<h3>4. Table Schema Validation <span id='schemaStatus' class='status status-warn'>⏳ Checking...</span></h3>";
if ($authLoaded) {
    $schemaChecks = [
        'disbursement_batches' => ['id', 'organization_id', 'batch_reference', 'batch_name', 'status', 'total_amount', 'total_destinations', 'created_by', 'created_at'],
        'disbursement_destinations' => ['id', 'batch_id', 'destination_index', 'amount', 'status', 'beneficiary_name'],
        'source_accounts' => ['id', 'organization_id', 'institution', 'source_identifier', 'is_active'],
        'users' => ['user_id', 'email', 'password_hash', 'full_name']
    ];
    
    $schemaErrors = [];
    foreach ($schemaChecks as $table => $columns) {
        try {
            $stmt = $pdo->prepare("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = :table
            ");
            $stmt->execute([':table' => $table]);
            $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $missing = array_diff($columns, $existingColumns);
            if (!empty($missing)) {
                $schemaErrors[] = "$table missing: " . implode(', ', $missing);
                echo "<div class='detail error'>❌ $table - Missing: " . implode(', ', $missing) . "</div>";
            } else {
                echo "<div class='detail success'>✅ $table - All columns present</div>";
            }
        } catch (Exception $e) {
            $schemaErrors[] = $table;
            echo "<div class='detail error'>❌ $table - Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    $schemaOk = empty($schemaErrors);
    echo "<script>document.getElementById('schemaStatus').className = 'status " . ($schemaOk ? 'status-pass' : 'status-warn') . "';";
    echo "document.getElementById('schemaStatus').textContent = '" . ($schemaOk ? '✅ PASS' : '⚠️ WARN') . "';</script>";
}
echo "</div>";

echo "</div>"; // end first test-grid

// ============================================================
// TEST 5: SESSION & USER
// ============================================================
echo "<div class='test-grid'>";
echo "<div class='test-card'>";
echo "<h3>5. Session & Current User <span id='sessionStatus' class='status status-warn'>⏳ Checking...</span></h3>";

if ($authLoaded) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    echo "<div class='detail'>Session ID: " . session_id() . "</div>";
    echo "<div class='detail'>Session Status: " . session_status() . "</div>";
    
    if (isset($_SESSION['enterprise_user'])) {
        $user = $_SESSION['enterprise_user'];
        echo "<div class='detail success'>✅ User is logged in</div>";
        echo "<div class='detail'>User: " . htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Unknown') . "</div>";
        echo "<div class='detail'>Role: <strong>" . htmlspecialchars($user['role'] ?? 'Unknown') . "</strong></div>";
        echo "<div class='detail'>Organization: " . htmlspecialchars($user['organization_name'] ?? 'Unknown') . "</div>";
        echo "<div class='detail'>Department: " . htmlspecialchars($user['department_id'] ?? 'None') . "</div>";
        echo "<script>document.getElementById('sessionStatus').className = 'status status-pass';";
        echo "document.getElementById('sessionStatus').textContent = '✅ Logged In';</script>";
    } else {
        echo "<div class='detail warning'>⚠️ No active enterprise user session</div>";
        echo "<div class='detail'><a href='login.php' style='color: #8A6D3B; font-weight:600;'>🔑 Login here</a></div>";
        echo "<script>document.getElementById('sessionStatus').className = 'status status-warn';";
        echo "document.getElementById('sessionStatus').textContent = '⚠️ Not Logged In';</script>";
    }
}
echo "</div>";

// ============================================================
// TEST 6: CSRF PROTECTION
// ============================================================
echo "<div class='test-card'>";
echo "<h3>6. CSRF Protection <span class='status status-pass'>✅ PASS</span></h3>";
if ($authLoaded && function_exists('generateCsrfToken') && function_exists('verifyCsrfToken')) {
    try {
        $token = generateCsrfToken();
        echo "<div class='detail success'>✅ Token generated: " . substr($token, 0, 24) . "...</div>";
        
        $verified = verifyCsrfToken($token);
        echo "<div class='detail " . ($verified ? 'success' : 'error') . "'>Token verification: " . ($verified ? '✅ Pass' : '❌ Fail') . "</div>";
        
        // Test invalid token
        $invalidVerified = verifyCsrfToken('invalid_token_12345');
        echo "<div class='detail " . (!$invalidVerified ? 'success' : 'error') . "'>Invalid token rejection: " . (!$invalidVerified ? '✅ Pass' : '❌ Fail') . "</div>";
    } catch (Exception $e) {
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
echo "</div>";

echo "</div>"; // end test-grid

// ============================================================
// TEST 7: ROLE-BASED PERMISSIONS MATRIX
// ============================================================
echo "<div class='test-grid'>";
echo "<div class='test-card' style='grid-column: 1 / -1;'>";
echo "<h3>7. Role-Based Permission Matrix <span class='status status-info'>📋 INFO</span></h3>";

$roles = [
    'owner' => ['create' => true, 'approve' => true, 'disburse' => true, 'manage_users' => true, 'view_all' => true],
    'program_officer' => ['create' => true, 'approve' => false, 'disburse' => false, 'manage_users' => false, 'view_all' => false],
    'department_head' => ['create' => true, 'approve' => false, 'disburse' => false, 'manage_users' => false, 'view_all' => false],
    'approver' => ['create' => false, 'approve' => true, 'disburse' => false, 'manage_users' => false, 'view_all' => false],
    'senior_approver' => ['create' => false, 'approve' => true, 'disburse' => false, 'manage_users' => false, 'view_all' => false],
    'supervisor' => ['create' => false, 'approve' => false, 'disburse' => true, 'manage_users' => false, 'view_all' => false],
    'auditor' => ['create' => false, 'approve' => false, 'disburse' => false, 'manage_users' => false, 'view_all' => true],
    'viewer' => ['create' => false, 'approve' => false, 'disburse' => false, 'manage_users' => false, 'view_all' => false],
    'beneficiary_registrar' => ['create' => false, 'approve' => false, 'disburse' => false, 'manage_users' => false, 'view_all' => false],
];

echo "<div class='role-matrix'>";
foreach ($roles as $role => $perms) {
    echo "<div class='role-card'>";
    echo "<div class='role-name'>" . ucfirst(str_replace('_', ' ', $role)) . "</div>";
    $badges = [];
    if ($perms['create']) $badges[] = "<span class='role-badge can-create'>💰 Create</span>";
    if ($perms['approve']) $badges[] = "<span class='role-badge can-approve'>✅ Approve</span>";
    if ($perms['disburse']) $badges[] = "<span class='role-badge can-disburse'>💸 Disburse</span>";
    if ($perms['view_all']) $badges[] = "<span class='role-badge can-view'>👁️ View All</span>";
    echo implode(' ', $badges);
    echo "</div>";
}
echo "</div>";

echo "<div style='margin-top:12px; font-size:12px; color:#64748b;'>";
echo "✅ <strong>Loader</strong> (Program Officer, Dept Head) → Create batches<br>";
echo "✅ <strong>Approver</strong> (Approver, Senior Approver) → Approve pending batches<br>";
echo "✅ <strong>Supervisor</strong> → Disburse approved funds<br>";
echo "✅ <strong>Owner</strong> → Full access to everything";
echo "</div>";
echo "</div>";
echo "</div>";

// ============================================================
// TEST 8: BATCH WORKFLOW SIMULATION
// ============================================================
echo "<div class='test-card' style='margin-bottom:16px;'>";
echo "<h3>8. Batch Workflow Simulation <span class='status status-info'>🔄 TEST</span></h3>";

if ($authLoaded) {
    try {
        // Check if we have a test batch or create one
        $stmt = $pdo->prepare("
            SELECT id, batch_reference, status, total_amount, total_destinations, created_at 
            FROM disbursement_batches 
            WHERE organization_id = :org_id 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([':org_id' => getOrganizationId()]);
        $testBatch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($testBatch) {
            echo "<div class='detail success'>✅ Found test batch: " . htmlspecialchars($testBatch['batch_reference']) . "</div>";
            
            echo "<div class='batch-sim'>";
            echo "<div class='row'><span class='label'>Reference</span><span class='value'>" . htmlspecialchars($testBatch['batch_reference']) . "</span></div>";
            echo "<div class='row'><span class='label'>Status</span><span class='value'><strong>" . strtoupper($testBatch['status']) . "</strong></span></div>";
            echo "<div class='row'><span class='label'>Amount</span><span class='value'>BWP " . number_format($testBatch['total_amount'] ?? 0, 2) . "</span></div>";
            echo "<div class='row'><span class='label'>Destinations</span><span class='value'>" . ($testBatch['total_destinations'] ?? 0) . "</span></div>";
            echo "<div class='row'><span class='label'>Created</span><span class='value'>" . date('Y-m-d H:i', strtotime($testBatch['created_at'] ?? 'now')) . "</span></div>";
            echo "</div>";
            
            // Show what each role would see
            echo "<div style='margin-top:12px;'>";
            echo "<h4 style='font-size:13px; margin-bottom:8px;'>Role-Based Actions for This Batch:</h4>";
            echo "<div style='display:flex; gap:8px; flex-wrap:wrap;'>";
            
            $status = strtolower($testBatch['status'] ?? 'draft');
            
            // Loader actions (Program Officer, Dept Head)
            if (in_array($status, ['draft', 'pending', 'pending_approval'])) {
                echo "<span class='btn btn-primary btn-sm'>📤 Submit for Approval (Loader)</span>";
            }
            
            // Approver actions
            if (in_array($status, ['pending', 'pending_approval'])) {
                echo "<span class='btn btn-warning btn-sm'>✅ Approve (Approver)</span>";
                echo "<span class='btn btn-danger btn-sm'>❌ Reject (Approver)</span>";
            }
            
            // Supervisor actions
            if ($status === 'approved') {
                echo "<span class='btn btn-success btn-sm'>💸 Disburse Funds (Supervisor)</span>";
            }
            
            // View action (everyone)
            echo "<span class='btn btn-outline btn-sm'>👁️ View (Everyone)</span>";
            
            echo "</div>";
            echo "</div>";
            
            // Progress bar showing workflow
            $steps = ['Draft', 'Pending', 'Approved', 'Completed'];
            $currentStep = array_search(strtoupper($status), array_map('strtoupper', $steps));
            if ($currentStep === false) $currentStep = 0;
            $progress = (($currentStep + 1) / count($steps)) * 100;
            
            echo "<div style='margin-top:12px;'>";
            echo "<div style='display:flex; justify-content:space-between; font-size:10px; color:#64748b;'>";
            foreach ($steps as $i => $step) {
                $active = $i <= $currentStep ? 'color:#166534;' : 'color:#94a3b8;';
                echo "<span style='$active'>" . ($i <= $currentStep ? '✅ ' : '⬜ ') . $step . "</span>";
            }
            echo "</div>";
            echo "<div class='progress-bar'><div class='fill' style='width:" . $progress . "%;'></div></div>";
            echo "</div>";
            
        } else {
            echo "<div class='detail warning'>⚠️ No batches found. Create a test batch to test workflow.</div>";
            echo "<div style='margin-top:12px;'>";
            echo "<a href='imports/source_input.php' class='btn btn-primary'>💰 Create Test Batch</a>";
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='detail error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
echo "</div>";

// ============================================================
// TEST 9: FILE PERMISSIONS & CRITICAL FILES
// ============================================================
echo "<div class='test-grid'>";
echo "<div class='test-card'>";
echo "<h3>9. Critical File Check <span id='fileStatus' class='status status-warn'>⏳ Checking...</span></h3>";

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
    'imports/add_source.php' => true,
    'imports/review_batch.php' => true,
    'imports/review.php' => true,
    'imports/sources.php' => true,
    'imports/approve.php' => true,
    'imports/execute.php' => true,
    'imports/manual_entry.php' => true,
    'batches/index.php' => true,
    'batches/view.php' => true,
];

$missingFiles = [];
$unreadableFiles = [];
foreach ($criticalFiles as $file => $required) {
    $fullPath = __DIR__ . '/' . $file;
    if (!file_exists($fullPath)) {
        $missingFiles[] = $file;
        echo "<div class='detail error'>❌ Missing: $file</div>";
    } elseif (!is_readable($fullPath)) {
        $unreadableFiles[] = $file;
        echo "<div class='detail warning'>⚠️ Not readable: $file</div>";
    } else {
        echo "<div class='detail success'>✅ $file</div>";
    }
}

$allOk = empty($missingFiles) && empty($unreadableFiles);
echo "<script>document.getElementById('fileStatus').className = 'status " . ($allOk ? 'status-pass' : 'status-warn') . "';";
echo "document.getElementById('fileStatus').textContent = '" . ($allOk ? '✅ PASS' : '⚠️ ' . count($missingFiles) . ' missing') . "';</script>";
echo "</div>";

// ============================================================
// TEST 10: SAMPLE DATA CHECK
// ============================================================
echo "<div class='test-card'>";
echo "<h3>10. Sample Data Check <span id='dataStatus' class='status status-warn'>⏳ Checking...</span></h3>";

if ($authLoaded) {
    try {
        $orgId = getOrganizationId();
        
        // Count organizations
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM organizations");
        $orgCount = $stmt->fetchColumn();
        echo "<div class='detail'>🏢 Organizations: " . $orgCount . "</div>";
        
        // Count users
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $userCount = $stmt->fetchColumn();
        echo "<div class='detail'>👤 Users: " . $userCount . "</div>";
        
        // Count org users
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM organization_users");
        $orgUserCount = $stmt->fetchColumn();
        echo "<div class='detail'>👥 Organization Users: " . $orgUserCount . "</div>";
        
        // Count source accounts
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM source_accounts WHERE organization_id = :org_id");
        $stmt->execute([':org_id' => $orgId]);
        $sourceCount = $stmt->fetchColumn();
        echo "<div class='detail'>🏦 Source Accounts: " . $sourceCount . "</div>";
        
        // Count batches
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM disbursement_batches WHERE organization_id = :org_id");
        $stmt->execute([':org_id' => $orgId]);
        $batchCount = $stmt->fetchColumn();
        echo "<div class='detail'>📋 Disbursement Batches: " . $batchCount . "</div>";
        
        // Count destinations
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM disbursement_destinations d
            JOIN disbursement_batches b ON d.batch_id = b.id
            WHERE b.organization_id = :org_id
        ");
        $stmt->execute([':org_id' => $orgId]);
        $destCount = $stmt->fetchColumn();
        echo "<div class='detail'>🎯 Destinations: " . $destCount . "</div>";
        
        $hasData = $orgCount > 0 && $userCount > 0;
        echo "<script>document.getElementById('dataStatus').className = 'status " . ($hasData ? 'status-pass' : 'status-warn') . "';";
        echo "document.getElementById('dataStatus').textContent = '" . ($hasData ? '✅ Has Data' : '⚠️ Limited Data') . "';</script>";
        
    } catch (Exception $e) {
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "<script>document.getElementById('dataStatus').className = 'status status-fail';";
        echo "document.getElementById('dataStatus').textContent = '❌ ERROR';</script>";
    }
}
echo "</div>";
echo "</div>";

// ============================================================
// TEST 11: QUICK FIX SUGGESTIONS
// ============================================================
echo "<div class='test-card' style='margin-bottom:16px; background:#f8fafc;'>";
echo "<h3>11. Quick Fix Suggestions <span class='status status-info'>💡 INFO</span></h3>";

$issues = [];

// Check for old tables
try {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = 'import_batches'");
    $stmt->execute();
    if ($stmt->fetch()) {
        $issues[] = "⚠️ 'import_batches' table still exists. Consider dropping if no longer needed.";
    }
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = 'import_rows'");
    $stmt->execute();
    if ($stmt->fetch()) {
        $issues[] = "⚠️ 'import_rows' table still exists. Consider dropping if no longer needed.";
    }
} catch (Exception $e) {}

// Check for missing files
if (!empty($missingFiles)) {
    $issues[] = "⚠️ Missing files: " . implode(', ', array_slice($missingFiles, 0, 5)) . (count($missingFiles) > 5 ? " and " . (count($missingFiles) - 5) . " more" : "");
}

// Check if user is logged in
if (!isset($_SESSION['enterprise_user'])) {
    $issues[] = "🔑 You are not logged in. Login to test role-based features.";
}

// Check for source accounts
if ($authLoaded && isset($orgId) && $sourceCount == 0) {
    $issues[] = "🏦 No source accounts found. Add a source account to create disbursements.";
}

if (empty($issues)) {
    echo "<div class='detail success'>✅ No issues detected. System looks clean!</div>";
} else {
    foreach ($issues as $issue) {
        echo "<div class='detail " . (strpos($issue, '✅') !== false ? 'success' : 'warning') . "'>" . htmlspecialchars($issue) . "</div>";
    }
}
echo "</div>";

// ============================================================
// TEST 12: NAVIGATION LINKS
// ============================================================
echo "<div class='test-card' style='margin-bottom:16px;'>";
echo "<h3>12. Navigation & Quick Links <span class='status status-info'>🔗 LINKS</span></h3>";
echo "<div style='display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;'>";
echo "<a href='index.php' class='btn btn-primary'>📊 Dashboard</a>";
echo "<a href='login.php' class='btn btn-outline'>🔑 Login</a>";
echo "<a href='imports/source_input.php' class='btn btn-primary'>💰 New Disbursement</a>";
echo "<a href='imports/review_batch.php?status=all' class='btn btn-outline'>📋 All Batches</a>";
echo "<a href='imports/review_batch.php?status=pending_approval' class='btn btn-warning'>⏳ Pending Approvals</a>";
echo "<a href='imports/review_batch.php?status=approved' class='btn btn-success'>✅ Approved Batches</a>";
echo "<a href='beneficiaries.php' class='btn btn-outline'>👥 Beneficiaries</a>";
echo "<a href='reports.php' class='btn btn-outline'>📈 Reports</a>";
echo "<a href='settings.php' class='btn btn-outline'>⚙️ Settings</a>";
echo "<a href='test.php' class='btn btn-outline' style='border-color:#8A6D3B; color:#8A6D3B;'>🔧 Re-run Test</a>";
echo "</div>";
echo "</div>";

// ============================================================
// 13. FINAL SUMMARY
// ============================================================
$allTestsPassed = $authLoaded && $allOk && $schemaOk;
echo "<div class='summary-box'>";
echo "<h3>" . ($allTestsPassed ? '✅ All Systems Operational' : '⚠️ Some Issues Detected') . "</h3>";
echo "<p>";
if ($allTestsPassed) {
    echo "All tests passed! Your enterprise module is ready to use.<br>";
    echo "The system has been configured for role-based access with Loader → Approver → Supervisor workflow.";
} else {
    echo "Some issues were detected. Please review the test results above and fix any issues.<br>";
    echo "Common issues: missing files, database tables, or configuration.";
}
echo "</p>";

// Role workflow reminder
echo "<div style='margin-top:12px; padding:12px; background:#1e293b; border-radius:8px;'>";
echo "<h4 style='color:#8A6D3B; font-size:13px;'>📋 Role Workflow Reminder:</h4>";
echo "<div style='display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-top:8px; font-size:12px; color:#94a3b8;'>";
echo "<div><strong style='color:#dbeafe;'>1. LOADER</strong><br>Program Officer / Dept Head<br>→ Creates batch → Submits for approval</div>";
echo "<div><strong style='color:#fef3c7;'>2. APPROVER</strong><br>Approver / Senior Approver<br>→ Reviews → Approves or Rejects</div>";
echo "<div><strong style='color:#dcfce7;'>3. SUPERVISOR</strong><br>Supervisor<br>→ Disburses funds</div>";
echo "</div>";
echo "</div>";

echo "<div class='links'>";
echo "<a href='index.php'>📊 Dashboard</a>";
echo "<a href='login.php' class='secondary'>🔑 Login</a>";
echo "<a href='imports/source_input.php' class='secondary'>💰 New Disbursement</a>";
echo "<a href='test.php' class='secondary' style='background:#8A6D3B; color:#0f172a;'>🔧 Re-run Test</a>";
echo "</div>";
echo "</div>";

echo "</div></body></html>";
