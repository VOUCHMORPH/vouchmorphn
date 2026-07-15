<?php
/**
 * test_simple.php - Simple System Test
 * Tests core functionality without requiring complex configs
 */

// Suppress session warnings
error_reporting(E_ALL ^ E_WARNING);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>System Test</title>
    <style>
        body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 40px; }
        .pass { color: #4ade80; }
        .fail { color: #f87171; }
        .warn { color: #fbbf24; }
        .info { color: #60a5fa; }
        .box { background: #1e293b; padding: 20px; border-radius: 8px; margin: 10px 0; }
        .step { margin: 20px 0; padding: 15px; border-left: 3px solid #64748b; background: #1e293b; border-radius: 4px; }
        .step.pass { border-color: #4ade80; }
        .step.fail { border-color: #f87171; }
        .step.warn { border-color: #fbbf24; }
        .step.info { border-color: #60a5fa; }
        .btn { background: #4ade80; color: #0f172a; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #22c55e; }
        .btn-primary { background: #60a5fa; color: #0f172a; }
        .btn-primary:hover { background: #3b82f6; }
        .login-form { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; max-width: 400px; margin: 10px 0; }
        .login-form label { display: block; margin: 8px 0 4px; color: #94a3b8; }
        .login-form input { width: 100%; padding: 8px 12px; border: 1px solid #334155; border-radius: 4px; background: #0f172a; color: #e2e8f0; }
        .login-form .btn { margin-top: 12px; width: 100%; }
    </style>
</head>
<body>
<h1>🔧 VouchMorph System Test</h1>
<p class='info'>Testing database connection, tables, and authentication</p>";

// ============================================================
// TEST 1: Database Connection
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 1: Database Connection</h2>";

try {
    require_once 'auth.php';
    $pdo = getDBConnection();
    echo "<span class='pass'>✅ Database connected</span><br>";
    
    $stmt = $pdo->query("SELECT version() as version, now() as time");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Version: " . ($row['version'] ?? 'Unknown') . "<br>";
    echo "Server Time: " . ($row['time'] ?? 'Unknown') . "<br>";
    $dbConnected = true;
} catch (Exception $e) {
    echo "<span class='fail'>❌ Database error: " . $e->getMessage() . "</span><br>";
    $dbConnected = false;
}
echo "</div>";

// ============================================================
// TEST 2: Tables
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 2: Required Tables</h2>";

if ($dbConnected) {
    $tables = [
        'disbursement_batches',
        'disbursement_destinations',
        'source_accounts',
        'batch_approvals',
        'organizations',
        'organization_users',
        'users'
    ];
    
    $allExist = true;
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :table");
            $stmt->execute([':table' => $table]);
            if ($stmt->fetch()) {
                echo "<span class='pass'>✅ $table</span><br>";
            } else {
                echo "<span class='fail'>❌ $table - MISSING</span><br>";
                $allExist = false;
            }
        } catch (Exception $e) {
            echo "<span class='fail'>❌ $table - Error: " . $e->getMessage() . "</span><br>";
            $allExist = false;
        }
    }
    
    if ($allExist) {
        echo "<span class='pass'>✅ All tables exist</span><br>";
    }
}
echo "</div>";

// ============================================================
// TEST 3: Authentication
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 3: Authentication</h2>";

if (isset($_SESSION['enterprise_user'])) {
    $user = $_SESSION['enterprise_user'];
    echo "<span class='pass'>✅ Logged in</span><br>";
    echo "User: " . ($user['full_name'] ?? $user['username'] ?? 'Unknown') . "<br>";
    echo "Role: <strong>" . ($user['role'] ?? 'Unknown') . "</strong><br>";
    echo "Organization ID: " . ($user['organization_id'] ?? 'N/A') . "<br>";
    echo "Organization: " . ($user['organization_name'] ?? 'N/A') . "<br>";
    $isLoggedIn = true;
    $orgId = $user['organization_id'] ?? null;
} else {
    echo "<span class='warn'>⚠️ Not logged in</span><br>";
    echo "<div class='login-form'>";
    echo "<h3 style='color:#fbbf24;'>🔑 Login</h3>";
    echo "<form method='POST' action='login.php'>";
    echo "<label>Email</label>";
    echo "<input type='email' name='email' value='program_officer@example.com' placeholder='Enter email'>";
    echo "<label>Password</label>";
    echo "<input type='password' name='password' value='password123' placeholder='Enter password'>";
    echo "<button type='submit' class='btn btn-primary'>Login</button>";
    echo "</form>";
    echo "<p style='margin-top:8px; font-size:11px; color:#64748b;'>Try: program_officer@example.com / password123</p>";
    echo "</div>";
    $isLoggedIn = false;
    $orgId = null;
}
echo "</div>";

// ============================================================
// TEST 4: Organization Data (if logged in)
// ============================================================
if ($isLoggedIn && $dbConnected) {
    echo "<div class='step'>";
    echo "<h2>Test 4: Organization Data</h2>";
    
    try {
        $stmt = $pdo->prepare("SELECT id, name, country_code, status FROM organizations WHERE id = :id");
        $stmt->execute([':id' => $orgId]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($org) {
            echo "<span class='pass'>✅ Organization found</span><br>";
            echo "Name: " . ($org['name'] ?? 'N/A') . "<br>";
            echo "Country: " . ($org['country_code'] ?? 'N/A') . "<br>";
            echo "Status: " . ($org['status'] ?? 'N/A') . "<br>";
        } else {
            echo "<span class='fail'>❌ Organization not found</span><br>";
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
    echo "</div>";
    
    // ============================================================
    // TEST 5: Source Accounts
    // ============================================================
    echo "<div class='step'>";
    echo "<h2>Test 5: Source Accounts</h2>";
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM source_accounts WHERE organization_id = :org_id AND is_active = true");
        $stmt->execute([':org_id' => $orgId]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            echo "<span class='pass'>✅ Found $count source accounts</span><br>";
            
            $stmt = $pdo->prepare("SELECT institution, source_identifier, balance FROM source_accounts WHERE organization_id = :org_id AND is_active = true LIMIT 5");
            $stmt->execute([':org_id' => $orgId]);
            $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($sources as $s) {
                echo "<span class='info'>🏦 " . $s['institution'] . " - " . $s['source_identifier'] . " (BWP " . number_format($s['balance'] ?? 0, 2) . ")</span><br>";
            }
        } else {
            echo "<span class='warn'>⚠️ No source accounts found</span><br>";
            echo "<a href='imports/add_source.php' class='btn'>➕ Add Source Account</a>";
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
    echo "</div>";
    
    // ============================================================
    // TEST 6: Batch Summary
    // ============================================================
    echo "<div class='step'>";
    echo "<h2>Test 6: Batch Summary</h2>";
    
    try {
        // Total batches
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id");
        $stmt->execute([':org_id' => $orgId]);
        $total = $stmt->fetchColumn();
        echo "<span class='pass'>📋 Total Batches: $total</span><br>";
        
        // By status
        $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM disbursement_batches WHERE organization_id = :org_id GROUP BY status");
        $stmt->execute([':org_id' => $orgId]);
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($statuses)) {
            foreach ($statuses as $s) {
                $icon = match(strtolower($s['status'])) {
                    'draft' => '📝',
                    'pending', 'pending_approval' => '⏳',
                    'approved' => '✅',
                    'completed', 'executed' => '✔️',
                    'rejected' => '❌',
                    default => '📋'
                };
                echo "<span class='info'>$icon " . strtoupper($s['status']) . ": " . $s['count'] . "</span><br>";
            }
        }
        
        // Total disbursed
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('completed', 'executed')");
        $stmt->execute([':org_id' => $orgId]);
        $totalDisbursed = $stmt->fetchColumn();
        echo "<span class='pass'>💰 Total Disbursed: BWP " . number_format($totalDisbursed, 2) . "</span><br>";
        
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
    echo "</div>";
}

// ============================================================
// SUMMARY
// ============================================================
echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
echo "<h2>📊 Summary</h2>";
echo "<span class='pass'>✅ Database: " . ($dbConnected ? 'Connected' : 'Failed') . "</span><br>";
echo "<span class='pass'>✅ Tables: " . ($allExist ?? false ? 'All present' : 'Check above') . "</span><br>";
echo $isLoggedIn ? "<span class='pass'>✅ Logged in</span><br>" : "<span class='warn'>⚠️ Not logged in - Login above</span><br>";

if ($isLoggedIn) {
    echo "<br><strong>Quick Links:</strong><br>";
    echo "<a href='index.php' class='btn' style='margin:4px;'>📊 Dashboard</a>";
    echo "<a href='imports/source_input.php' class='btn' style='margin:4px;'>💰 New Disbursement</a>";
    echo "<a href='imports/review_batch.php?status=all' class='btn' style='margin:4px;'>📋 Batches</a>";
    echo "<a href='imports/add_source.php' class='btn' style='margin:4px;'>🏦 Add Source</a>";
} else {
    echo "<br><a href='login.php' class='btn btn-primary'>🔑 Go to Login</a>";
}
echo "</div>";

echo "</body></html>";
