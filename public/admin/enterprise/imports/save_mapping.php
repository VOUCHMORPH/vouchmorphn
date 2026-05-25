<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getInstance();
$orgId = getOrganizationId();

$data = json_decode(file_get_contents('php://input'), true);

if ($data && isset($data['template_name'])) {
    $stmt = $db->prepare("
        INSERT INTO column_mapping_templates (
            organization_id, template_name, column_mapping, created_by
        ) VALUES (
            :org_id, :name, :mapping, :user_id
        )
    ");
    
    $result = $stmt->execute([
        ':org_id' => $orgId,
        ':name' => $data['template_name'],
        ':mapping' => json_encode($data['column_mapping']),
        ':user_id' => $user['id']
    ]);
    
    echo json_encode(['success' => $result]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
