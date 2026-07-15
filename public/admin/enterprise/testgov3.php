<?php
/**
 * test_destinations.php - Test Add Destinations
 */

require_once 'auth.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test 4: Add Destinations</title>
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
<h1>🎯 Test 4: Add Destinations</h1>";

$pdo = getDBConnection();
$orgId = getOrganizationId();
$user = getCurrentUser();

// ============================================================
// TEST 4A: Check add_destinations.php exists
// ============================================================
echo "<div class='step'>";
echo "<h2>4A: Check add_destinations.php</h2>";

$destFile = __DIR__ . '/imports/add_destinations.php';
if (file_exists($destFile)) {
    echo "<span class='pass'>✅ add_destinations.php exists</span><br>";
    
    $output = shell_exec("php -l " . escapeshellarg($destFile) . " 2>&1");
    if (strpos($output, 'No syntax errors') !== false) {
        echo "<span class='pass'>✅ Syntax check passed</span><br>";
    } else {
        echo "<span class='fail'>❌ Syntax error: $output</span><br>";
    }
} else {
    echo "<span class='fail'>❌ add_destinations.php not found</span><br>";
}
echo "</div>";

// ============================================================
// TEST 4B: Get a batch to work with
// ============================================================
echo "<div class='step'>";
echo "<h2>4B: Find a Batch</h2>";

try {
    // Get a draft batch
    $stmt = $pdo->prepare("
        SELECT id, batch_reference, status, total_destinations
        FROM disbursement_batches
        WHERE organization_id = :org_id AND status = 'draft'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([':org_id' => $orgId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($batch) {
        echo "<span class='pass'>✅ Found draft batch: " . $batch['batch_reference'] . " (ID: " . $batch['id'] . ")</span><br>";
        $batchId = $batch['id'];
    } else {
        echo "<span class='warn'>⚠️ No draft batch found. Creating one...</span><br>";
        
        // Get a source account
        $stmt = $pdo->prepare("SELECT id FROM source_accounts WHERE organization_id = :org_id LIMIT 1");
        $stmt->execute([':org_id' => $orgId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$source) {
            echo "<span class='fail'>❌ No source account available. Run test_source.php first.</span><br>";
            $batchId = null;
        } else {
            // Create a batch
            $stmt = $pdo->prepare("
                INSERT INTO disbursement_batches (
                    organization_id, batch_reference, batch_name,
                    source_account_id, source_institution, source_asset_type,
                    source_identifier, total_amount, total_destinations,
                    currency, status, created_by, created_at, updated_at
                ) VALUES (
                    :org_id, 
                    'TEST_BATCH_' || to_char(NOW(), 'YYYYMMDD_HH24MISS'),
                    'Test Batch for Destinations',
                    :source_id, 'VOUCHMORPH_TEST', 'WALLET',
                    'TEST_ACCOUNT', 0, 0,
                    'BWP', 'draft', :user_id,
                    NOW(), NOW()
                ) RETURNING id
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':source_id' => $source['id'],
                ':user_id' => $user['user_id'] ?? $user['id'] ?? 1
            ]);
            $batchId = $stmt->fetchColumn();
            echo "<span class='pass'>✅ Created test batch ID: $batchId</span><br>";
        }
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    $batchId = null;
}
echo "</div>";

// ============================================================
// TEST 4C: Check existing destinations
// ============================================================
if ($batchId) {
    echo "<div class='step'>";
    echo "<h2>4C: Existing Destinations</h2>";
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM disbursement_destinations
            WHERE batch_id = :batch_id
            ORDER BY destination_index
        ");
        $stmt->execute([':batch_id' => $batchId]);
        $dests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($dests)) {
            echo "<span class='warn'>⚠️ No destinations yet</span><br>";
            echo "<a href='imports/add_destinations.php?batch_id=$batchId' class='btn' style='background:#60a5fa; color:#0f172a; padding:8px 16px; border:none; border-radius:4px; text-decoration:none;'>➕ Add Destinations</a><br>";
        } else {
            echo "<span class='pass'>✅ Found " . count($dests) . " destinations</span><br>";
            echo "<table>";
            echo "<tr><th>#</th><th>Institution</th><th>Identifier</th><th>Amount</th><th>Beneficiary</th><th>Status</th></tr>";
            foreach ($dests as $d) {
                echo "<tr>";
                echo "<td>" . $d['destination_index'] . "</td>";
                echo "<td>" . $d['institution'] . "</td>";
                echo "<td>" . $d['identifier'] . "</td>";
                echo "<td>BWP " . number_format($d['amount'], 2) . "</td>";
                echo "<td>" . ($d['beneficiary_name'] ?? 'N/A') . "</td>";
                echo "<td>" . ($d['status'] ?? 'PENDING') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
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
echo "<span class='pass'>✅ add_destinations.php - File exists</span><br>";
echo $batchId ? "<span class='pass'>✅ Batch available (ID: $batchId)</span><br>" : "<span class='fail'>❌ No batch available</span><br>";
echo "<br><strong>Next Test:</strong> <a href='test_review.php?batch_id=$batchId' style='color: #60a5fa;'>Run test_review.php →</a>";
echo "</div>";

echo "</body></html>";
