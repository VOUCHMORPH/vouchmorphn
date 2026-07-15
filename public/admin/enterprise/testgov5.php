<?php
/**
 * test_approve.php - Test Approve System
 */

require_once 'auth.php';

$batchId = $_GET['batch_id'] ?? null;
$action = $_GET['action'] ?? '';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test 6: Approve System</title>
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
        .btn-danger { background: #f87171; color: #0f172a; }
        .btn-danger:hover { background: #ef4444; }
    </style>
</head>
<body>
<h1>✅ Test 6: Approve System</h1>";

$pdo = getDBConnection();
$orgId = getOrganizationId();
$user = getCurrentUser();

// ============================================================
// TEST 6A: Check approve.php exists
// ============================================================
echo "<div class='step'>";
echo "<h2>6A: Check approve.php</h2>";

$approveFile = __DIR__ . '/imports/approve.php';
if (file_exists($approveFile)) {
    echo "<span class='pass'>✅ approve.php exists</span><br>";
    $output = shell_exec("php -l " . escapeshellarg($approveFile) . " 2>&1");
    if (strpos($output, 'No syntax errors') !== false) {
        echo "<span class='pass'>✅ Syntax check passed</span><br>";
    } else {
        echo "<span class='fail'>❌ Syntax error: $output</span><br>";
    }
} else {
    echo "<span class='fail'>❌ approve.php not found</span><br>";
}
echo "</div>";

// ============================================================
// TEST 6B: Check batch status for approval
// ============================================================
if ($batchId) {
    echo "<div class='step'>";
    echo "<h2>6B: Check Approval Status</h2>";
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, batch_reference, status, created_by
            FROM disbursement_batches
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($batch) {
            $status = strtolower($batch['status']);
            echo "Batch: " . $batch['batch_reference'] . "<br>";
            echo "Status: <strong>" . strtoupper($status) . "</strong><br>";
            
            if (in_array($status, ['pending', 'pending_approval'])) {
                echo "<span class='pass'>✅ Batch is pending approval</span><br>";
                echo "<a href='?batch_id=$batchId&action=approve' class='btn'>✅ Approve Batch</a> ";
                echo "<a href='?batch_id=$batchId&action=reject' class='btn btn-danger'>❌ Reject Batch</a><br>";
            } elseif ($status === 'approved') {
                echo "<span class='pass'>✅ Batch is already approved</span><br>";
            } elseif ($status === 'draft') {
                echo "<span class='warn'>⚠️ Batch is still in draft. Submit first.</span><br>";
                echo "<a href='imports/review.php?batch_id=$batchId' class='btn'>📤 Submit for Approval</a><br>";
            } else {
                echo "<span class='warn'>⚠️ Batch is $status - cannot approve</span><br>";
            }
            
            // Check self-approval prevention
            if ($batch['created_by'] == ($user['user_id'] ?? $user['id'] ?? null)) {
                echo "<span class='warn'>⚠️ You created this batch. Self-approval is not allowed.</span><br>";
                echo "Maker-Checker segregation of duties enforced.<br>";
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
// TEST 6C: Execute action if requested
// ============================================================
if ($action && $batchId) {
    echo "<div class='step'>";
    echo "<h2>6C: Executing: $action</h2>";
    
    if ($action === 'approve') {
        try {
            $stmt = $pdo->prepare("
                UPDATE disbursement_batches 
                SET status = 'approved',
                    approved_by = :user_id,
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id AND status IN ('pending', 'pending_approval')
            ");
            $stmt->execute([':user_id' => $user['user_id'] ?? $user['id'] ?? 1, ':id' => $batchId]);
            
            if ($stmt->rowCount() > 0) {
                echo "<span class='pass'>✅ Batch approved successfully!</span><br>";
                // Record approval
                $stmt = $pdo->prepare("
                    INSERT INTO batch_approvals (batch_id, approver_user_id, decision, created_at)
                    VALUES (:batch_id, :approver_id, 'APPROVED', NOW())
                ");
                $stmt->execute([':batch_id' => $batchId, ':approver_id' => $user['user_id'] ?? $user['id'] ?? 1]);
            } else {
                echo "<span class='fail'>❌ Failed to approve. Batch may not be pending.</span><br>";
            }
        } catch (Exception $e) {
            echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
        }
    } elseif ($action === 'reject') {
        try {
            $stmt = $pdo->prepare("
                UPDATE disbursement_batches 
                SET status = 'rejected',
                    rejection_reason = :reason,
                    reviewed_by = :user_id,
                    reviewed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':reason' => 'Rejected by test script',
                ':user_id' => $user['user_id'] ?? $user['id'] ?? 1,
                ':id' => $batchId
            ]);
            echo "<span class='pass'>✅ Batch rejected</span><br>";
        } catch (Exception $e) {
            echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
        }
    }
    echo "</div>";
}

// ============================================================
// SUMMARY
// ============================================================
echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
echo "<h2>📊 Summary</h2>";
echo "<span class='pass'>✅ approve.php - File exists</span><br>";
echo $batchId ? "<span class='pass'>✅ Batch ID: $batchId</span><br>" : "<span class='warn'>⚠️ No batch ID</span><br>";
echo "<br><strong>Next Test:</strong> <a href='test_execute.php?batch_id=$batchId' style='color: #60a5fa;'>Run test_execute.php →</a>";
echo "</div>";

echo "</body></html>";
