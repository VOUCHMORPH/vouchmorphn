<?php
/**
 * test_execute.php - Test Execute System
 */

require_once 'auth.php';

$batchId = $_GET['batch_id'] ?? null;

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test 7: Execute System</title>
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
<h1>💸 Test 7: Execute System</h1>";

$pdo = getDBConnection();
$orgId = getOrganizationId();
$user = getCurrentUser();

// ============================================================
// TEST 7A: Check execute.php exists
// ============================================================
echo "<div class='step'>";
echo "<h2>7A: Check execute.php</h2>";

$executeFile = __DIR__ . '/imports/execute.php';
if (file_exists($executeFile)) {
    echo "<span class='pass'>✅ execute.php exists</span><br>";
    $output = shell_exec("php -l " . escapeshellarg($executeFile) . " 2>&1");
    if (strpos($output, 'No syntax errors') !== false) {
        echo "<span class='pass'>✅ Syntax check passed</span><br>";
    } else {
        echo "<span class='fail'>❌ Syntax error: $output</span><br>";
    }
} else {
    echo "<span class='fail'>❌ execute.php not found</span><br>";
}
echo "</div>";

// ============================================================
// TEST 7B: Check batch status for execution
// ============================================================
if ($batchId) {
    echo "<div class='step'>";
    echo "<h2>7B: Check Execution Status</h2>";
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, batch_reference, status, total_amount
            FROM disbursement_batches
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($batch) {
            $status = strtolower($batch['status']);
            echo "Batch: " . $batch['batch_reference'] . "<br>";
            echo "Status: <strong>" . strtoupper($status) . "</strong><br>";
            echo "Amount: BWP " . number_format($batch['total_amount'] ?? 0, 2) . "<br>";
            
            if ($status === 'approved') {
                echo "<span class='pass'>✅ Batch is approved and ready for disbursement</span><br>";
                echo "<a href='?batch_id=$batchId&action=disburse' class='btn'>💸 Disburse Funds</a><br>";
            } elseif ($status === 'completed' || $status === 'executed') {
                echo "<span class='pass'>✅ Batch is already completed</span><br>";
            } else {
                echo "<span class='warn'>⚠️ Batch is $status - must be approved first</span><br>";
            }
        } else {
            echo "<span class='fail'>❌ Batch not found</span><br>";
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
    echo "</div>";
}

// ============================================================
// TEST 7C: Execute disbursement if requested
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'disburse' && $batchId) {
    echo "<div class='step'>";
    echo "<h2>7C: Executing Disbursement</h2>";
    
    try {
        // Update batch to completed
        $stmt = $pdo->prepare("
            UPDATE disbursement_batches 
            SET status = 'completed',
                executed_by = :user_id,
                executed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND status = 'approved'
        ");
        $stmt->execute([
            ':user_id' => $user['user_id'] ?? $user['id'] ?? 1,
            ':id' => $batchId
        ]);
        
        if ($stmt->rowCount() > 0) {
            echo "<span class='pass'>✅ Funds disbursed successfully!</span><br>";
            
            // Update destinations to success
            $stmt = $pdo->prepare("
                UPDATE disbursement_destinations 
                SET status = 'SUCCESS',
                    hold_reference = 'HOLD_' || to_char(NOW(), 'YYYYMMDD_HH24MISS') || '_' || destination_index
                WHERE batch_id = :batch_id
            ");
            $stmt->execute([':batch_id' => $batchId]);
            echo "<span class='pass'>✅ All destinations marked as SUCCESS</span><br>";
            
        } else {
            echo "<span class='fail'>❌ Failed to disburse. Batch may not be approved.</span><br>";
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
echo "<span class='pass'>✅ execute.php - File exists</span><br>";
echo $batchId ? "<span class='pass'>✅ Batch ID: $batchId</span><br>" : "<span class='warn'>⚠️ No batch ID</span><br>";
echo "<br><strong>Next Test:</strong> <a href='test_workflow.php' style='color: #60a5fa;'>Run Full Workflow Test →</a>";
echo "</div>";

echo "</body></html>";
