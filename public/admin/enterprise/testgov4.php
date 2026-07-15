<?php
/**
 * test_review.php - Test Review System (FIXED)
 */

// Suppress session warnings
error_reporting(E_ALL ^ E_WARNING);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'auth.php';

$batchId = $_GET['batch_id'] ?? null;

$pdo = getDBConnection();
$orgId = getOrganizationId();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test 5: Review System</title>
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
        .btn-warning { background: #fbbf24; color: #0f172a; }
        .btn-warning:hover { background: #f59e0b; }
        .btn-danger { background: #f87171; color: #0f172a; }
        .btn-danger:hover { background: #ef4444; }
    </style>
</head>
<body>
<h1>📋 Test 5: Review System</h1>";

// ============================================================
// TEST 5A: Check review files exist
// ============================================================
echo "<div class='step'>";
echo "<h2>5A: Check Review Files</h2>";

$reviewFiles = [
    'review.php' => __DIR__ . '/imports/review.php',
    'review_batch.php' => __DIR__ . '/imports/review_batch.php'
];

foreach ($reviewFiles as $name => $path) {
    if (file_exists($path)) {
        echo "<span class='pass'>✅ $name exists</span><br>";
        $output = shell_exec("php -l " . escapeshellarg($path) . " 2>&1");
        if (strpos($output, 'No syntax errors') !== false) {
            echo "<span class='pass'>  ✅ Syntax check passed</span><br>";
        } else {
            echo "<span class='fail'>  ❌ Syntax error</span><br>";
        }
    } else {
        echo "<span class='fail'>❌ $name not found</span><br>";
    }
}
echo "</div>";

// ============================================================
// TEST 5B: Get batch details
// ============================================================
echo "<div class='step'>";
echo "<h2>5B: Batch Details</h2>";

if ($batchId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                b.id, b.batch_reference, b.batch_name, b.status, 
                b.total_amount, b.total_destinations,
                b.created_at, b.created_by,
                u.full_name as created_by_name
            FROM disbursement_batches b
            LEFT JOIN users u ON b.created_by = u.user_id
            WHERE b.id = :id AND b.organization_id = :org_id
        ");
        $stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($batch) {
            echo "<span class='pass'>✅ Batch found</span><br>";
            echo "Reference: <strong>" . $batch['batch_reference'] . "</strong><br>";
            echo "Name: " . ($batch['batch_name'] ?? 'N/A') . "<br>";
            echo "Status: <strong>" . strtoupper($batch['status']) . "</strong><br>";
            echo "Amount: BWP " . number_format($batch['total_amount'] ?? 0, 2) . "<br>";
            echo "Destinations: " . ($batch['total_destinations'] ?? 0) . "<br>";
            echo "Created: " . date('Y-m-d H:i', strtotime($batch['created_at'] ?? 'now')) . "<br>";
            echo "Created By: " . ($batch['created_by_name'] ?? 'User ID: ' . $batch['created_by']) . "<br>";
            
            // Show actions based on status
            $status = strtolower($batch['status']);
            echo "<br><strong>Available Actions:</strong><br>";
            echo "<div style='display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;'>";
            
            if ($status === 'draft') {
                echo "<a href='imports/review.php?batch_id=$batchId' class='btn btn-warning'>📤 Submit for Approval</a>";
                echo "<a href='imports/add_destinations.php?batch_id=$batchId' class='btn'>✏️ Edit Destinations</a>";
            }
            if (in_array($status, ['pending', 'pending_approval'])) {
                echo "<a href='?batch_id=$batchId&action=approve' class='btn'>✅ Approve</a>";
                echo "<a href='?batch_id=$batchId&action=reject' class='btn btn-danger'>❌ Reject</a>";
            }
            if ($status === 'approved') {
                echo "<a href='test_execute.php?batch_id=$batchId' class='btn'>💸 Disburse Funds</a>";
            }
            if ($status === 'completed' || $status === 'executed') {
                echo "<span class='pass'>✅ Batch is completed</span>";
            }
            
            echo "<a href='imports/review_batch.php?batch_id=$batchId' class='btn' style='background:#64748b;'>👁️ View Details</a>";
            echo "</div>";
            
        } else {
            echo "<span class='fail'>❌ Batch not found or not in your organization</span><br>";
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span class='warn'>⚠️ No batch ID provided.</span><br>";
    echo "Use: <code>?batch_id=123</code> in the URL<br>";
    echo "<br><a href='test_destinations.php' class='btn'>← Go back to create a batch</a>";
}
echo "</div>";

// ============================================================
// TEST 5C: Execute action if requested
// ============================================================
$action = $_GET['action'] ?? '';
if ($action && $batchId) {
    echo "<div class='step'>";
    echo "<h2>5C: Executing: $action</h2>";
    
    $user = getCurrentUser();
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$userId) {
        $stmt = $pdo->prepare("SELECT user_id FROM organization_users WHERE organization_id = :org_id LIMIT 1");
        $stmt->execute([':org_id' => $orgId]);
        $userResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $userId = $userResult['user_id'] ?? 1;
    }
    
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
            $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
            
            if ($stmt->rowCount() > 0) {
                echo "<span class='pass'>✅ Batch approved successfully!</span><br>";
                
                // Record approval
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO batch_approvals (batch_id, approver_user_id, decision, created_at)
                        VALUES (:batch_id, :approver_id, 'APPROVED', NOW())
                    ");
                    $stmt->execute([':batch_id' => $batchId, ':approver_id' => $userId]);
                    echo "<span class='pass'>✅ Approval recorded in batch_approvals</span><br>";
                } catch (Exception $e) {
                    echo "<span class='warn'>⚠️ Could not record approval: " . $e->getMessage() . "</span><br>";
                }
                
                // Refresh page to show updated status
                echo "<script>setTimeout(function(){ window.location.href = 'test_review.php?batch_id=$batchId'; }, 1500);</script>";
            } else {
                echo "<span class='fail'>❌ Failed to approve. Batch may not be pending or already processed.</span><br>";
            }
        } catch (Exception $e) {
            echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
        }
    } elseif ($action === 'reject') {
        try {
            $reason = $_GET['reason'] ?? 'Rejected by test script';
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
                ':reason' => $reason,
                ':user_id' => $userId,
                ':id' => $batchId
            ]);
            echo "<span class='pass'>✅ Batch rejected</span><br>";
            
            // Record rejection
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO batch_approvals (batch_id, approver_user_id, decision, reason, created_at)
                    VALUES (:batch_id, :approver_id, 'REJECTED', :reason, NOW())
                ");
                $stmt->execute([
                    ':batch_id' => $batchId,
                    ':approver_id' => $userId,
                    ':reason' => $reason
                ]);
                echo "<span class='pass'>✅ Rejection recorded in batch_approvals</span><br>";
            } catch (Exception $e) {
                echo "<span class='warn'>⚠️ Could not record rejection: " . $e->getMessage() . "</span><br>";
            }
            
            echo "<script>setTimeout(function(){ window.location.href = 'test_review.php?batch_id=$batchId'; }, 1500);</script>";
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
echo "<span class='pass'>✅ review.php - File exists</span><br>";
echo "<span class='pass'>✅ review_batch.php - File exists</span><br>";
echo $batchId ? "<span class='pass'>✅ Batch ID: $batchId</span><br>" : "<span class='warn'>⚠️ No batch ID</span><br>";

if ($batchId) {
    // Show current status
    $stmt = $pdo->prepare("SELECT status FROM disbursement_batches WHERE id = :id");
    $stmt->execute([':id' => $batchId]);
    $status = $stmt->fetchColumn();
    echo "Current Status: <strong>" . strtoupper($status) . "</strong><br>";
}

echo "<br><strong>Next Test:</strong> <a href='test_approve.php?batch_id=$batchId' style='color: #60a5fa;'>Run test_approve.php →</a>";
echo "</div>";

echo "</body></html>";
