<?php
/**
 * test_batches.php - Check Database for Batches
 * Shows all batches in the system with their details
 */

// Suppress session warnings
error_reporting(E_ALL ^ E_WARNING);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>Batch Database Check</title>
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
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 6px 10px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #1e293b; color: #94a3b8; }
        tr:hover { background: #1e293b; }
        .btn { background: #4ade80; color: #0f172a; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #22c55e; }
        .status-draft { color: #94a3b8; }
        .status-pending { color: #fbbf24; }
        .status-approved { color: #60a5fa; }
        .status-completed { color: #4ade80; }
        .status-rejected { color: #f87171; }
    </style>
</head>
<body>
<h1>📋 Batch Database Check</h1>
<p class='info'>Checking all batches in the system</p>";

// ============================================================
// LOAD AUTH
// ============================================================
try {
    require_once 'auth.php';
    $pdo = getDBConnection();
    $orgId = getOrganizationId();
    $user = getCurrentUser();
    
    echo "<div class='step pass'>";
    echo "<h2>✅ Authentication</h2>";
    if ($user) {
        echo "Logged in as: " . ($user['full_name'] ?? $user['username'] ?? 'User') . "<br>";
        echo "Role: <strong>" . ($user['role'] ?? 'Unknown') . "</strong><br>";
        echo "Organization ID: " . ($orgId ?? 'N/A') . "<br>";
    } else {
        echo "<span class='warn'>⚠️ Not logged in - showing all batches</span><br>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='step fail'>";
    echo "<h2>❌ Authentication Failed</h2>";
    echo "Error: " . $e->getMessage() . "<br>";
    echo "</div>";
    exit;
}

// ============================================================
// CHECK BATCHES
// ============================================================
echo "<div class='step'>";
echo "<h2>📊 Batch Statistics</h2>";

try {
    // Total batches in system
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM disbursement_batches");
    $totalBatches = $stmt->fetchColumn();
    echo "<span class='pass'>📋 Total Batches in System: $totalBatches</span><br>";
    
    // Batches for this organization
    if ($orgId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id");
        $stmt->execute([':org_id' => $orgId]);
        $orgBatches = $stmt->fetchColumn();
        echo "<span class='pass'>🏢 Batches for your Organization: $orgBatches</span><br>";
    }
    
    // Batches by status
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM disbursement_batches 
        GROUP BY status 
        ORDER BY count DESC
    ");
    $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<br><strong>Status Distribution:</strong><br>";
    if (!empty($statusCounts)) {
        foreach ($statusCounts as $sc) {
            $status = strtoupper($sc['status']);
            $color = match(strtolower($status)) {
                'draft' => 'status-draft',
                'pending', 'pending_approval' => 'status-pending',
                'approved' => 'status-approved',
                'completed', 'executed' => 'status-completed',
                'rejected' => 'status-rejected',
                default => ''
            };
            echo "<span class='$color'>$status: " . $sc['count'] . "</span><br>";
        }
    } else {
        echo "<span class='warn'>⚠️ No batches found</span><br>";
    }
    
    // Total amount disbursed
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM disbursement_batches 
        WHERE status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')
    ");
    $totalDisbursed = $stmt->fetchColumn();
    echo "<br><span class='pass'>💰 Total Disbursed: BWP " . number_format($totalDisbursed, 2) . "</span><br>";
    
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// LIST ALL BATCHES
// ============================================================
echo "<div class='step'>";
echo "<h2>📋 All Batches</h2>";

try {
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            batch_reference, 
            batch_name, 
            status, 
            total_amount, 
            total_destinations,
            identity_recipients,
            created_at,
            created_by,
            submitted_at,
            approved_at,
            executed_at
        FROM disbursement_batches 
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($batches)) {
        echo "<span class='warn'>⚠️ No batches found in the database</span><br>";
        echo "<a href='imports/source_input.php' class='btn'>💰 Create Your First Batch</a>";
    } else {
        echo "<span class='pass'>✅ Found " . count($batches) . " batches</span><br><br>";
        
        echo "<table>";
        echo "<tr>";
        echo "<th>ID</th>";
        echo "<th>Reference</th>";
        echo "<th>Name</th>";
        echo "<th>Status</th>";
        echo "<th>Amount</th>";
        echo "<th>Dest</th>";
        echo "<th>Identity</th>";
        echo "<th>Created</th>";
        echo "<th>Actions</th>";
        echo "</tr>";
        
        foreach ($batches as $batch) {
            $status = strtoupper($batch['status'] ?? 'UNKNOWN');
            $color = match(strtolower($status)) {
                'draft' => 'status-draft',
                'pending', 'pending_approval' => 'status-pending',
                'approved' => 'status-approved',
                'completed', 'executed' => 'status-completed',
                'rejected' => 'status-rejected',
                default => ''
            };
            
            echo "<tr>";
            echo "<td>" . $batch['id'] . "</td>";
            echo "<td><strong>" . ($batch['batch_reference'] ?? 'N/A') . "</strong></td>";
            echo "<td>" . ($batch['batch_name'] ?? '—') . "</td>";
            echo "<td class='$color'>" . $status . "</td>";
            echo "<td>BWP " . number_format($batch['total_amount'] ?? 0, 2) . "</td>";
            echo "<td>" . ($batch['total_destinations'] ?? 0) . "</td>";
            echo "<td>" . ($batch['identity_recipients'] ?? 0) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($batch['created_at'] ?? 'now')) . "</td>";
            echo "<td>";
            echo "<a href='batches/view.php?id=" . $batch['id'] . "' style='color:#60a5fa;'>View</a>";
            if (strtolower($status) === 'draft') {
                echo " | <a href='imports/add_destinations.php?batch_id=" . $batch['id'] . "' style='color:#fbbf24;'>Edit</a>";
            }
            if (strtolower($status) === 'pending_approval' || strtolower($status) === 'pending') {
                echo " | <a href='imports/review_batch.php?batch_id=" . $batch['id'] . "' style='color:#fbbf24;'>Review</a>";
            }
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// CHECK DESTINATIONS
// ============================================================
echo "<div class='step'>";
echo "<h2>🎯 Destinations</h2>";

try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as total, 
               SUM(CASE WHEN is_identity_recipient = true THEN 1 ELSE 0 END) as identity_count,
               SUM(amount) as total_amount
        FROM disbursement_destinations
    ");
    $destStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<span class='pass'>📌 Total Destinations: " . ($destStats['total'] ?? 0) . "</span><br>";
    echo "<span class='info'>🆔 Identity Recipients: " . ($destStats['identity_count'] ?? 0) . "</span><br>";
    echo "<span class='pass'>💰 Total Amount: BWP " . number_format($destStats['total_amount'] ?? 0, 2) . "</span><br>";
    
    // Show recent destinations
    $stmt = $pdo->prepare("
        SELECT d.*, b.batch_reference 
        FROM disbursement_destinations d
        LEFT JOIN disbursement_batches b ON d.batch_id = b.id
        ORDER BY d.id DESC
        LIMIT 10
    ");
    $stmt->execute();
    $dests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($dests)) {
        echo "<br><strong>Recent Destinations:</strong><br>";
        echo "<table>";
        echo "<tr><th>Batch</th><th>#</th><th>Institution</th><th>Amount</th><th>Beneficiary</th><th>Status</th></tr>";
        foreach ($dests as $d) {
            echo "<tr>";
            echo "<td>" . ($d['batch_reference'] ?? 'N/A') . "</td>";
            echo "<td>" . ($d['destination_index'] ?? '') . "</td>";
            echo "<td>" . ($d['institution'] ?? '') . "</td>";
            echo "<td>BWP " . number_format($d['amount'] ?? 0, 2) . "</td>";
            echo "<td>" . ($d['beneficiary_name'] ?? '—') . "</td>";
            echo "<td>" . ($d['status'] ?? 'PENDING') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// QUICK FIX SUGGESTIONS
// ============================================================
echo "<div class='step'>";
echo "<h2>💡 Quick Fix Suggestions</h2>";

try {
    // Check for old tables
    $stmt = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'import_batches'");
    if ($stmt->fetch()) {
        echo "<span class='warn'>⚠️ 'import_batches' table still exists. Consider dropping if no longer needed.</span><br>";
    }
    
    $stmt = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'import_rows'");
    if ($stmt->fetch()) {
        echo "<span class='warn'>⚠️ 'import_rows' table still exists. Consider dropping if no longer needed.</span><br>";
    }
    
    // Check if there are any batches
    $stmt = $pdo->query("SELECT COUNT(*) FROM disbursement_batches");
    $batchCount = $stmt->fetchColumn();
    
    if ($batchCount == 0) {
        echo "<span class='warn'>⚠️ No batches found. Create your first batch:</span><br>";
        echo "<a href='imports/source_input.php' class='btn'>💰 Create First Batch</a><br>";
    }
    
    // Check for source accounts
    if ($orgId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM source_accounts WHERE organization_id = :org_id");
        $stmt->execute([':org_id' => $orgId]);
        $sourceCount = $stmt->fetchColumn();
        
        if ($sourceCount == 0) {
            echo "<span class='warn'>⚠️ No source accounts. Add one:</span><br>";
            echo "<a href='imports/add_source.php' class='btn'>🏦 Add Source Account</a><br>";
        }
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

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM disbursement_batches");
    $total = $stmt->fetchColumn();
    echo "<span class='pass'>📋 Total Batches: $total</span><br>";
    
    if ($total > 0) {
        echo "<span class='pass'>✅ Batches exist in the database</span><br>";
        echo "<br><strong>Quick Actions:</strong><br>";
        echo "<a href='imports/review_batch.php?status=all' class='btn' style='margin:4px;'>📋 View All Batches</a>";
        echo "<a href='imports/source_input.php' class='btn' style='margin:4px;'>💰 New Batch</a>";
        echo "<a href='index.php' class='btn' style='margin:4px;'>📊 Dashboard</a>";
    } else {
        echo "<span class='warn'>⚠️ No batches found</span><br>";
        echo "<br><a href='imports/source_input.php' class='btn'>💰 Create Your First Batch</a>";
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

echo "</body></html>";
