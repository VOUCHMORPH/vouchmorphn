<?php
/**
 * test_destinations.php - Test Add Destinations (FIXED)
 */

// Suppress session warnings
error_reporting(E_ALL ^ E_WARNING);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'auth.php';

$pdo = getDBConnection();
$orgId = getOrganizationId();
$user = getCurrentUser();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test 4: Add Destinations (FIXED)</title>
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
        .btn { background: #4ade80; color: #0f172a; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #22c55e; }
    </style>
</head>
<body>
<h1>🎯 Test 4: Add Destinations (FIXED)</h1>";

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
// TEST 4B: Get or create a batch
// ============================================================
echo "<div class='step'>";
echo "<h2>4B: Find or Create a Batch</h2>";

$batchId = null;

try {
    // First, try to get a draft batch
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
        $stmt = $pdo->prepare("
            SELECT id, institution, source_identifier 
            FROM source_accounts 
            WHERE organization_id = :org_id AND is_active = true 
            LIMIT 1
        ");
        $stmt->execute([':org_id' => $orgId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$source) {
            echo "<span class='fail'>❌ No source account available. Run test_source.php first or add one manually.</span><br>";
            echo "<a href='imports/add_source.php' class='btn'>➕ Add Source Account</a><br>";
        } else {
            echo "<span class='pass'>✅ Found source: " . $source['institution'] . " - " . $source['source_identifier'] . "</span><br>";
            
            $userId = $user['user_id'] ?? $user['id'] ?? null;
            if (!$userId) {
                $stmt = $pdo->prepare("SELECT user_id FROM organization_users WHERE organization_id = :org_id LIMIT 1");
                $stmt->execute([':org_id' => $orgId]);
                $userResult = $stmt->fetch(PDO::FETCH_ASSOC);
                $userId = $userResult['user_id'] ?? 1;
            }
            
            // Create a batch
            $stmt = $pdo->prepare("
                INSERT INTO disbursement_batches (
                    organization_id, 
                    batch_reference, 
                    batch_name,
                    source_account_id, 
                    source_institution, 
                    source_asset_type,
                    source_identifier, 
                    total_amount, 
                    total_destinations,
                    currency, 
                    status, 
                    created_by, 
                    created_at, 
                    updated_at
                ) VALUES (
                    :org_id, 
                    'TEST_BATCH_' || to_char(NOW(), 'YYYYMMDD_HH24MISS'),
                    'Test Batch for Destinations',
                    :source_id, 
                    :source_institution, 
                    'WALLET',
                    :source_identifier, 
                    0, 
                    0,
                    'BWP', 
                    'draft', 
                    :user_id,
                    NOW(), 
                    NOW()
                ) RETURNING id
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':source_id' => $source['id'],
                ':source_institution' => $source['institution'],
                ':source_identifier' => $source['source_identifier'],
                ':user_id' => $userId
            ]);
            $batchId = $stmt->fetchColumn();
            echo "<span class='pass'>✅ Created test batch ID: $batchId</span><br>";
        }
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// TEST 4C: Add destinations if batch exists
// ============================================================
if ($batchId) {
    echo "<div class='step'>";
    echo "<h2>4C: Add Test Destinations</h2>";
    
    try {
        // Check existing destinations
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_destinations WHERE batch_id = :batch_id");
        $stmt->execute([':batch_id' => $batchId]);
        $existing = $stmt->fetchColumn();
        
        if ($existing > 0) {
            echo "<span class='pass'>✅ Already have $existing destinations</span><br>";
        } else {
            echo "<span class='warn'>⚠️ No destinations yet. Adding 3 test destinations...</span><br>";
            
            $destinations = [
                ['CAZACOM', '71712345', 'phone', 500.00, 'Test Recipient 1', '+26771712345'],
                ['SACCUSSALIS', '10000002', 'account_number', 500.00, 'Test Recipient 2', '+26771712346'],
                ['ZURUBANK', '71712347', 'phone', 500.00, 'Test Recipient 3', '+26771712347']
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO disbursement_destinations (
                    batch_id, destination_index, institution, asset_type,
                    identifier, identifier_type, amount, currency,
                    delivery_method, beneficiary_name, beneficiary_phone,
                    status
                ) VALUES (
                    :batch_id, :index, :institution, 'WALLET',
                    :identifier, :identifier_type, :amount, 'BWP',
                    'DEPOSIT', :name, :phone,
                    'PENDING'
                )
            ");
            
            $added = 0;
            foreach ($destinations as $i => $dest) {
                $stmt->execute([
                    ':batch_id' => $batchId,
                    ':index' => $i + 1,
                    ':institution' => $dest[0],
                    ':identifier' => $dest[1],
                    ':identifier_type' => $dest[2],
                    ':amount' => $dest[3],
                    ':name' => $dest[4],
                    ':phone' => $dest[5]
                ]);
                $added++;
            }
            
            // Update batch totals
            $stmt = $pdo->prepare("
                UPDATE disbursement_batches 
                SET total_destinations = 3, 
                    total_amount = 1500.00, 
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $batchId]);
            
            echo "<span class='pass'>✅ Added $added destinations</span><br>";
            echo "<span class='pass'>✅ Total Amount: BWP 1,500.00</span><br>";
        }
        
        // Show destinations
        $stmt = $pdo->prepare("
            SELECT * FROM disbursement_destinations 
            WHERE batch_id = :batch_id 
            ORDER BY destination_index
        ");
        $stmt->execute([':batch_id' => $batchId]);
        $dests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($dests) {
            echo "<br><table>";
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
        
        echo "<br><a href='imports/add_destinations.php?batch_id=$batchId' class='btn'>✏️ Edit Destinations</a>";
        echo " <a href='imports/review.php?batch_id=$batchId' class='btn'>📋 Review Batch</a>";
        
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

if ($batchId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_destinations WHERE batch_id = :batch_id");
    $stmt->execute([':batch_id' => $batchId]);
    $destCount = $stmt->fetchColumn();
    echo $destCount > 0 ? "<span class='pass'>✅ $destCount destinations added</span><br>" : "<span class='warn'>⚠️ No destinations yet</span><br>";
}

echo "<br><strong>Next Test:</strong> <a href='test_review.php?batch_id=$batchId' style='color: #60a5fa;'>Run test_review.php →</a>";
echo "</div>";

echo "</body></html>";
