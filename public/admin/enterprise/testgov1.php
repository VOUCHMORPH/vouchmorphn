<?php
/**
 * test_database.php - Test Database Tables & Schema
 */

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test 2: Database Tables</title>
    <style>
        body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 40px; }
        .pass { color: #4ade80; }
        .fail { color: #f87171; }
        .warn { color: #fbbf24; }
        .box { background: #1e293b; padding: 20px; border-radius: 8px; margin: 10px 0; }
        .step { margin: 20px 0; padding: 15px; border-left: 3px solid #64748b; background: #1e293b; border-radius: 4px; }
        .step.pass { border-color: #4ade80; }
        .step.fail { border-color: #f87171; }
        .step.warn { border-color: #fbbf24; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #1e293b; color: #94a3b8; }
    </style>
</head>
<body>
<h1>📊 Test 2: Database Tables</h1>";

// Load auth
require_once 'auth.php';
$pdo = getDBConnection();

// ============================================================
// TEST 2A: Check Required Tables
// ============================================================
echo "<div class='step'>";
echo "<h2>2A: Required Tables</h2>";

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
$existingTables = [];

foreach ($requiredTables as $table) {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :table");
        $stmt->execute([':table' => $table]);
        if ($stmt->fetch()) {
            echo "<span class='pass'>✅ $table</span><br>";
            $existingTables[] = $table;
        } else {
            echo "<span class='fail'>❌ $table - MISSING</span><br>";
            $missingTables[] = $table;
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ $table - Error: " . $e->getMessage() . "</span><br>";
        $missingTables[] = $table;
    }
}

if (empty($missingTables)) {
    echo "<span class='pass'>✅ All " . count($requiredTables) . " tables exist</span><br>";
} else {
    echo "<span class='fail'>❌ Missing " . count($missingTables) . " tables</span><br>";
}
echo "</div>";

// ============================================================
// TEST 2B: Check Table Schema (Columns)
// ============================================================
echo "<div class='step'>";
echo "<h2>2B: Table Schema Validation</h2>";

$schemaChecks = [
    'disbursement_batches' => ['id', 'organization_id', 'batch_reference', 'batch_name', 'status', 'total_amount', 'total_destinations', 'created_by', 'created_at'],
    'disbursement_destinations' => ['id', 'batch_id', 'destination_index', 'amount', 'status', 'beneficiary_name'],
    'source_accounts' => ['id', 'organization_id', 'institution', 'source_identifier', 'is_active'],
    'users' => ['user_id', 'email', 'password_hash', 'full_name']
];

foreach ($schemaChecks as $table => $columns) {
    echo "<br><strong>$table:</strong><br>";
    try {
        $stmt = $pdo->prepare("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = :table
        ");
        $stmt->execute([':table' => $table]);
        $existingColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $existingNames = array_column($existingColumns, 'column_name');
        $missing = array_diff($columns, $existingNames);
        
        if (empty($missing)) {
            echo "<span class='pass'>✅ All columns present</span><br>";
        } else {
            echo "<span class='fail'>❌ Missing: " . implode(', ', $missing) . "</span><br>";
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
}
echo "</div>";

// ============================================================
// TEST 2C: Data Counts
// ============================================================
echo "<div class='step'>";
echo "<h2>2C: Data Counts</h2>";

try {
    $orgId = getOrganizationId();
    
    // Organizations
    $stmt = $pdo->query("SELECT COUNT(*) FROM organizations");
    echo "🏢 Organizations: " . $stmt->fetchColumn() . "<br>";
    
    // Users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    echo "👤 Users: " . $stmt->fetchColumn() . "<br>";
    
    // Organization Users
    $stmt = $pdo->query("SELECT COUNT(*) FROM organization_users");
    echo "👥 Organization Users: " . $stmt->fetchColumn() . "<br>";
    
    // Source Accounts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM source_accounts WHERE organization_id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    echo "🏦 Source Accounts: " . $stmt->fetchColumn() . "<br>";
    
    // Batches
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    echo "📋 Batches: " . $stmt->fetchColumn() . "<br>";
    
    // Destinations
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM disbursement_destinations d
        JOIN disbursement_batches b ON d.batch_id = b.id
        WHERE b.organization_id = :org_id
    ");
    $stmt->execute([':org_id' => $orgId]);
    echo "🎯 Destinations: " . $stmt->fetchColumn() . "<br>";
    
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// TEST 2D: Show Recent Batches
// ============================================================
echo "<div class='step'>";
echo "<h2>2D: Recent Batches</h2>";

try {
    $orgId = getOrganizationId();
    $stmt = $pdo->prepare("
        SELECT id, batch_reference, status, total_amount, total_destinations, created_at
        FROM disbursement_batches
        WHERE organization_id = :org_id
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([':org_id' => $orgId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($batches)) {
        echo "<span class='warn'>⚠️ No batches found</span><br>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>Reference</th><th>Status</th><th>Amount</th><th>Dest</th><th>Created</th></tr>";
        foreach ($batches as $b) {
            echo "<tr>";
            echo "<td>" . $b['id'] . "</td>";
            echo "<td>" . $b['batch_reference'] . "</td>";
            echo "<td>" . strtoupper($b['status']) . "</td>";
            echo "<td>BWP " . number_format($b['total_amount'] ?? 0, 2) . "</td>";
            echo "<td>" . ($b['total_destinations'] ?? 0) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($b['created_at'] ?? 'now')) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// SUMMARY
// ============================================================
echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
echo "<h2>📊 Summary</h2>";
$allGood = empty($missingTables);
echo $allGood ? "<span class='pass'>✅ All tables exist and schema is correct</span><br>" : "<span class='fail'>❌ Some tables or columns are missing</span><br>";
echo "<br><strong>Next Test:</strong> <a href='test_source.php' style='color: #60a5fa;'>Run test_source.php →</a>";
echo "</div>";

echo "</body></html>";
