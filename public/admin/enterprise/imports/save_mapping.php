<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();

$input = json_decode(file_get_contents('php://input'), true);

$templateName = $input['template_name'] ?? '';
$columnMapping = $input['column_mapping'] ?? [];
$batchId = $input['batch_id'] ?? 0;
$fileHeaders = $input['file_headers'] ?? [];

if (empty($templateName)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Template name is required']);
    exit;
}

if (empty($columnMapping)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No mapping data provided']);
    exit;
}

try {
    // Check if template already exists
    $stmt = $db->prepare("
        SELECT id FROM import_templates 
        WHERE name = :name AND organization_id = :org_id
    ");
    $stmt->execute([':name' => $templateName, ':org_id' => $orgId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing template
        $stmt = $db->prepare("
            UPDATE import_templates 
            SET column_mapping = :mapping, 
                file_headers = :headers, 
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':mapping' => json_encode($columnMapping),
            ':headers' => json_encode($fileHeaders),
            ':id' => $existing['id']
        ]);
    } else {
        // Insert new template
        $stmt = $db->prepare("
            INSERT INTO import_templates (
                name, organization_id, column_mapping, file_headers, created_at, updated_at
            ) VALUES (
                :name, :org_id, :mapping, :headers, NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':name' => $templateName,
            ':org_id' => $orgId,
            ':mapping' => json_encode($columnMapping),
            ':headers' => json_encode($fileHeaders)
        ]);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Template saved successfully']);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error saving template: ' . $e->getMessage()
    ]);
}
?>
