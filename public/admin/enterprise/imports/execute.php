<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// ============================================================
// FIX: Use getConnection() instead of getInstance()
// ============================================================
$db = DBConnection::getConnection();

$orgId = getOrganizationId();
$batchId = $_GET['batch_id'] ?? 0;

// Rest of your code remains the same...
$stmt = $db->prepare("SELECT * FROM import_batches WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch || $batch['status'] !== 'APPROVED') {
    die("Batch not approved or not found");
}

// Get valid rows
$stmt = $db->prepare("SELECT * FROM import_rows WHERE batch_id = :batch_id AND validation_status = 'VALID'");
$stmt->execute([':batch_id' => $batchId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$processed = 0;
$successful = 0;
$failed = 0;

// Process each payment
foreach ($rows as $row) {
    $processed++;
    
    // Call existing SwapService
    // This is where you integrate with your existing SwapService
    try {
        // Simulate swap execution
        $swapReference = 'SWAP_' . date('Ymd_His') . '_' . strtoupper(substr(uniqid(), -8));
        
        // Insert payment instruction
        $stmt = $db->prepare("
            INSERT INTO payment_instructions (
                organization_id, batch_id, import_row_id, source_type, source_id,
                destination_type, destination_provider, destination_value,
                recipient_name, recipient_phone, amount, currency, swap_reference, status
            ) VALUES (
                :org_id, :batch_id, :row_id, 'organization_wallet', :source_id,
                :dest_type, :dest_provider, :dest_value, :name, :phone,
                :amount, :currency, :swap_ref, 'SUCCESS'
            )
        ");
        
        $stmt->execute([
            ':org_id' => $orgId,
            ':batch_id' => $batchId,
            ':row_id' => $row['id'],
            ':source_id' => $batch['source_id'],
            ':dest_type' => $row['destination_type'],
            ':dest_provider' => $row['destination_provider'],
            ':dest_value' => $row['destination_value'],
            ':name' => $row['recipient_name'],
            ':phone' => $row['recipient_phone'],
            ':amount' => $row['amount'],
            ':currency' => $row['currency'],
            ':swap_ref' => $swapReference
        ]);
        
        $successful++;
    } catch (Exception $e) {
        $failed++;
    }
    
    // Update progress (for AJAX polling)
    if (function_exists('ob_flush')) {
        echo "Progress: $processed/$total\n";
        ob_flush();
        flush();
    }
}

// Update batch status
$status = $failed === 0 ? 'COMPLETED' : ($successful > 0 ? 'PARTIAL' : 'FAILED');
$stmt = $db->prepare("
    UPDATE import_batches 
    SET status = :status, successful_count = :success, failed_count = :failed, 
        pending_count = 0, completed_at = NOW()
    WHERE id = :id
");
$stmt->execute([
    ':status' => $status,
    ':success' => $successful,
    ':failed' => $failed,
    ':id' => $batchId
]);

// Redirect to batch view
header("Location: ../batches/view.php?id=$batchId&success=1");
exit;
