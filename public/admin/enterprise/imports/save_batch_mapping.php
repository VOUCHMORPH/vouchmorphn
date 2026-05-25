<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getInstance();
$orgId = getOrganizationId();

$data = json_decode(file_get_contents('php://input'), true);

if ($data && isset($data['batch_id'])) {
    $stmt = $db->prepare("
        UPDATE import_batches 
        SET import_mapping = :mapping, status = 'MAPPED'
        WHERE id = :id AND organization_id = :org_id
    ");
    
    $result = $stmt->execute([
        ':mapping' => json_encode($data['column_mapping']),
        ':id' => $data['batch_id'],
        ':org_id' => $orgId
    ]);
    
    echo json_encode(['success' => $result]);
} else {
    echo json_encode(['success' => false]);
}
