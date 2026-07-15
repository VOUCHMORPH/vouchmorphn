<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();

$templateName = $_GET['template_name'] ?? '';
$batchId = $_GET['batch_id'] ?? 0;

if (empty($templateName)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Template name is required']);
    exit;
}

try {
    // Get template from database
    $stmt = $db->prepare("
        SELECT * FROM import_templates 
        WHERE name = :name AND organization_id = :org_id
    ");
    $stmt->execute([':name' => $templateName, ':org_id' => $orgId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Template not found']);
        exit;
    }
    
    // Decode the column mapping
    $mapping = json_decode($template['column_mapping'] ?? '{}', true);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'mapping' => $mapping,
        'template_name' => $template['name'],
        'headers' => json_decode($template['file_headers'] ?? '[]', true)
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error loading template: ' . $e->getMessage()
    ]);
}
?>
