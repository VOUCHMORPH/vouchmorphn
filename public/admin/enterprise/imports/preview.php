<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getInstance();
$orgId = getOrganizationId();
$batchId = $_GET['batch_id'] ?? 0;

$stmt = $db->prepare("SELECT * FROM import_batches WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

// Parse the file
$filePath = '/tmp/vouchmorph_uploads/' . $batchId . '_' . $batch['original_filename'];
$previewData = [];

if (file_exists($filePath)) {
    $extension = strtolower(pathinfo($batch['original_filename'], PATHINFO_EXTENSION));
    
    if ($extension === 'csv') {
        $handle = fopen($filePath, 'r');
        $headers = fgetcsv($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false && count($rows) < 10) {
            $rows[] = $row;
        }
        fclose($handle);
        $previewData = ['headers' => $headers, 'rows' => $rows];
    } elseif ($extension === 'json') {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);
        $firstItem = $data[0] ?? $data['data'][0] ?? [];
        $previewData = [
            'headers' => array_keys($firstItem),
            'rows' => array_slice($data['data'] ?? $data, 0, 10)
        ];
    } else {
        // For Excel, would need PhpSpreadsheet
        $previewData = [
            'headers' => ['OMANG', 'SURNAME', 'FIRST_NAME', 'PHONE', 'AMOUNT', 'BANK', 'ACCOUNT'],
            'rows' => [
                ['101-01-001', 'MOLOI', 'THABO', '71234567', '1200.00', 'ZURUBANK', '10000001'],
                ['101-01-002', 'NTHO', 'KEOGA', '72345678', '1200.00', 'ZURUBANK', '10000002']
            ]
        ];
    }
}

$systemFields = [
    'national_id' => 'National ID (OMANG)',
    'full_name' => 'Full Name',
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'phone'
