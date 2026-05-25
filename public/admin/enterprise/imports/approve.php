<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getInstance();
$orgId = getOrganizationId();

$data = json_decode(file_get_contents('php://input'), true);
$batchId = $data['batch_id'] ?? 0;
$action = $data['action'] ?? '';

$stmt = $db->prepare("SELECT * FROM import_batches WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    echo json_encode(['success' => false, 'error' => 'Batch not found']);
    exit;
}

if ($action === 'submit') {
    $stmt = $db->prepare("
        UPDATE import_batches 
        SET status = 'READY_FOR_APPROVAL', requires_approval = true
        WHERE id = :id
    ");
    $stmt->execute([':id' => $batchId]);
    
    echo json_encode(['success' => true]);
} elseif ($action === 'approve') {
    // Check if user has approval permission
    if ($user['role'] !== 'owner' && $user['role'] !== 'admin' && !in_array('approve_payments', $user['permissions'] ?? [])) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to approve payments']);
        exit;
    }
    
    $stmt = $db->prepare("
        UPDATE import_batches 
        SET status = 'APPROVED', approved_by = :user_id, approved_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':user_id' => $user['id'], ':id' => $batchId]);
    
    echo json_encode(['success' => true]);
} elseif ($action === 'reject') {
    $stmt = $db->prepare("
        UPDATE import_batches 
        SET status = 'REJECTED', rejection_reason = :reason
        WHERE id = :id
    ");
    $stmt->execute([':reason' => $data['reason'] ?? 'No reason provided', ':id' => $batchId]);
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
