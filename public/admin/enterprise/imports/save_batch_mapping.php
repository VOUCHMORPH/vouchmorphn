<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();

$input = json_decode(file_get_contents('php://input'), true);

$batchId = $input['batch_id'] ?? 0;
$columnMapping = $input['column_mapping'] ?? [];
$fileHeaders = $input['file_headers'] ?? [];

if (empty($batchId)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Batch ID is required']);
    exit;
}

try {
    // Update batch with mapping
    $stmt = $db->prepare("
        UPDATE import_batches 
        SET import_mapping = :mapping,
            file_headers = :headers,
            status = 'MAPPED'
        WHERE id = :id AND organization_id = :org_id
    ");
    $stmt->execute([
        ':mapping' => json_encode($columnMapping),
        ':headers' => json_encode($fileHeaders),
        ':id' => $batchId,
        ':org_id' => $orgId
    ]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Mapping saved successfully']);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error saving mapping: ' . $e->getMessage()
    ]);
}
?>
