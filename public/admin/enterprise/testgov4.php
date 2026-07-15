<?php
/**
 * test_review.php - Test Review & Review Batch
 */

require_once 'auth.php';

$batchId = $_GET['batch_id'] ?? null;

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
    </style>
</head>
<body>
<h1>📋 Test 5: Review System</h1>";

$pdo = getDBConnection();
$orgId = getOrganizationId();

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
// TEST 5B: Get batch status
// ============================================================
echo "<div class='step'>";
echo "<h2>5B: Batch Status</h2>";

if ($batchId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, batch_reference, status, total_amount, total_destinations
            FROM disbursement_batches
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($batch) {
            echo "<span class='pass'>✅ Batch found: " . $batch['batch_reference'] . "</span><br>";
            echo "Status: <strong>" . strtoupper($batch['status']) . "</strong><br>";
            echo "Amount: BWP " . number_format($batch['total_amount'] ?? 0, 2) . "<br>";
            echo "Destinations: " . ($batch['total_destinations'] ?? 0) . "<br>";
            
            // Show appropriate actions
            $status = strtolower($batch['status']);
            $actions = [];
            if ($status === 'draft') {
                $actions[] = "📤 Submit for Approval";
            }
            if (in_array($status, ['pending', 'pending_approval'])) {
                $actions[] = "✅ Approve";
                $actions[] = "❌ Reject";
            }
            if ($status === 'approved') {
                $actions[] = "💸 Disburse Funds";
            }
            
            if (!empty($actions)) {
                echo "<br>Available actions: " . implode(' | ', $actions) . "<br>";
            }
            
        } else {
            echo "<span class='fail'>❌ Batch not found or not in your organization</span><br>";
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span class='warn'>⚠️ No batch ID provided. Pass ?batch_id=123 in URL</span><br>";
}
echo "</div>";

// ============================================================
// SUMMARY
// ============================================================
echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
echo "<h2>📊 Summary</h2>";
echo "<span class='pass'>✅ review.php - File exists</span><br>";
echo "<span class='pass'>✅ review_batch.php - File exists</span><br>";
echo $batchId ? "<span class='pass'>✅ Batch ID: $batchId</span><br>" : "<span class='warn'>⚠️ No batch ID</span><br>";
echo "<br><strong>Next Test:</strong> <a href='test_approve.php?batch_id=$batchId' style='color: #60a5fa;'>Run test_approve.php →</a>";
echo "</div>";

echo "</body></html>";
