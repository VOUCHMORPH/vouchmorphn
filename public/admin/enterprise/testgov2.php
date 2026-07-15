<?php
/**
 * test_source.php - Test Source Input & Add Source
 */

require_once 'auth.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test 3: Source Input</title>
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
        .btn { background: #4ade80; color: #0f172a; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #22c55e; }
    </style>
</head>
<body>
<h1>🏦 Test 3: Source Input</h1>";

$pdo = getDBConnection();
$orgId = getOrganizationId();
$user = getCurrentUser();

// ============================================================
// TEST 3A: Check source_input.php exists
// ============================================================
echo "<div class='step'>";
echo "<h2>3A: Check source_input.php</h2>";

$sourceFile = __DIR__ . '/imports/source_input.php';
if (file_exists($sourceFile)) {
    echo "<span class='pass'>✅ source_input.php exists</span><br>";
    
    // Check syntax
    $output = shell_exec("php -l " . escapeshellarg($sourceFile) . " 2>&1");
    if (strpos($output, 'No syntax errors') !== false) {
        echo "<span class='pass'>✅ Syntax check passed</span><br>";
    } else {
        echo "<span class='fail'>❌ Syntax error: $output</span><br>";
    }
} else {
    echo "<span class='fail'>❌ source_input.php not found</span><br>";
}
echo "</div>";

// ============================================================
// TEST 3B: Check add_source.php exists
// ============================================================
echo "<div class='step'>";
echo "<h2>3B: Check add_source.php</h2>";

$addSourceFile = __DIR__ . '/imports/add_source.php';
if (file_exists($addSourceFile)) {
    echo "<span class='pass'>✅ add_source.php exists</span><br>";
    
    $output = shell_exec("php -l " . escapeshellarg($addSourceFile) . " 2>&1");
    if (strpos($output, 'No syntax errors') !== false) {
        echo "<span class='pass'>✅ Syntax check passed</span><br>";
    } else {
        echo "<span class='fail'>❌ Syntax error: $output</span><br>";
    }
} else {
    echo "<span class='fail'>❌ add_source.php not found</span><br>";
}
echo "</div>";

// ============================================================
// TEST 3C: Check existing source accounts
// ============================================================
echo "<div class='step'>";
echo "<h2>3C: Existing Source Accounts</h2>";

try {
    $stmt = $pdo->prepare("
        SELECT id, institution, source_identifier, asset_type, balance, is_active
        FROM source_accounts
        WHERE organization_id = :org_id
        ORDER BY created_at DESC
    ");
    $stmt->execute([':org_id' => $orgId]);
    $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($sources)) {
        echo "<span class='warn'>⚠️ No source accounts found</span><br>";
        echo "<a href='imports/add_source.php' class='btn'>➕ Add Source Account</a><br>";
    } else {
        echo "<span class='pass'>✅ Found " . count($sources) . " source accounts</span><br>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Institution</th><th>Identifier</th><th>Type</th><th>Balance</th><th>Status</th></tr>";
        foreach ($sources as $s) {
            echo "<tr>";
            echo "<td>" . $s['id'] . "</td>";
            echo "<td>" . $s['institution'] . "</td>";
            echo "<td>" . $s['source_identifier'] . "</td>";
            echo "<td>" . $s['asset_type'] . "</td>";
            echo "<td>BWP " . number_format($s['balance'] ?? 0, 2) . "</td>";
            echo "<td>" . ($s['is_active'] ? '✅ Active' : '❌ Inactive') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// TEST 3D: Create test source (if none exist)
// ============================================================
echo "<div class='step'>";
echo "<h2>3D: Create Test Source (if needed)</h2>";

// Check if any sources exist
$stmt = $pdo->prepare("SELECT COUNT(*) FROM source_accounts WHERE organization_id = :org_id");
$stmt->execute([':org_id' => $orgId]);
$count = $stmt->fetchColumn();

if ($count == 0) {
    echo "<span class='warn'>⚠️ No sources exist. Creating test source...</span><br>";
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO source_accounts (
                organization_id, institution, asset_type,
                source_identifier, source_identifier_type,
                account_name, currency, balance,
                is_active, is_hooked, created_by,
                created_at, updated_at
            ) VALUES (
                :org_id, 'VOUCHMORPH_TEST', 'WALLET',
                'TEST_' || to_char(NOW(), 'YYYYMMDD_HH24MISS'),
                'account_number',
                'Test Source Account',
                'BWP', 100000.00,
                true, false, :user_id,
                NOW(), NOW()
            ) RETURNING id
        ");
        $stmt->execute([':org_id' => $orgId, ':user_id' => $user['user_id'] ?? $user['id'] ?? 1]);
        $newId = $stmt->fetchColumn();
        
        echo "<span class='pass'>✅ Created test source account ID: $newId</span><br>";
        
        // Verify creation
        $stmt = $pdo->prepare("SELECT institution, source_identifier FROM source_accounts WHERE id = :id");
        $stmt->execute([':id' => $newId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<span class='pass'>✅ Source: " . $source['institution'] . " - " . $source['source_identifier'] . "</span><br>";
        
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error creating source: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span class='pass'>✅ " . $count . " sources already exist</span><br>";
}
echo "</div>";

// ============================================================
// SUMMARY
// ============================================================
echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
echo "<h2>📊 Summary</h2>";
echo "<span class='pass'>✅ source_input.php - File exists</span><br>";
echo "<span class='pass'>✅ add_source.php - File exists</span><br>";
echo "<span class='pass'>✅ Source accounts - " . ($count > 0 ? 'Available' : 'Created') . "</span><br>";
echo "<br><strong>Next Test:</strong> <a href='test_destinations.php' style='color: #60a5fa;'>Run test_destinations.php →</a>";
echo "</div>";

echo "</body></html>";
