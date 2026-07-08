<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
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
    'phone' => 'Phone Number',
    'amount' => 'Amount',
    'currency' => 'Currency',
    'account_number' => 'Bank Account',
    'bank_code' => 'Bank Code',
    'wallet_id' => 'Mobile Wallet ID',
    'destination_type' => 'Destination Type',
    'destination_provider' => 'Provider',
    'email' => 'Email Address',
    'payment_reference' => 'Payment Reference',
    'country_code' => 'Country Code'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview & Map - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }
        .app { display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px;
            background: #0f172a;
            color: #e2e8f0;
            position: fixed;
            height: 100vh;
        }
        .sidebar-header { padding: 24px; border-bottom: 1px solid #1e293b; }
        .sidebar-header h2 { font-size: 20px; font-weight: 700; }
        .sidebar-header span { color: #fbbf24; }
        .sidebar-nav { padding: 20px 0; }
        .nav-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e1;
            text-decoration: none;
        }
        .nav-item:hover { background: #1e293b; color: white; }
        .main {
            flex: 1;
            margin-left: 280px;
            padding: 24px 32px;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .greeting h1 { font-size: 28px; font-weight: 700; }
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
            max-width: 600px;
        }
        .step {
            flex: 1;
            text-align: center;
        }
        .step-number {
            width: 32px;
            height: 32px;
            background: #e2e8f0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .step.completed .step-number { background: #10b981; color: white; }
        .step.active .step-number { background: #0f172a; color: white; }
        .step-label { font-size: 12px; color: #64748b; }
        .step.active .step-label { color: #0f172a; font-weight: 500; }
        .card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
        }
        .card-body { padding: 24px; }
        .preview-table { overflow-x: auto; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; position: relative; }
        .mapping-select {
            margin-top: 8px;
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 12px;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .stat {
            background: #f8fafc;
            padding: 16px 24px;
            border-radius: 12px;
            flex: 1;
        }
        .stat-value { font-size: 28px; font-weight: 700; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 4px; }
        .btn-group { display: flex; gap: 16px; justify-content: flex-end; margin-top: 24px; }
        .btn {
            padding: 12px 28px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .save-template {
            margin-top: 20px;
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .save-template input {
            flex: 1;
            padding: 10px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
        }
    </style>
</head>
<body>
<div class="app">
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>VouchMorph <span>Enterprise</span></h2>
        </div>
        <div class="sidebar-nav">
            <a href="../index.php" class="nav-item">📊 Dashboard</a>
            <a href="upload.php" class="nav-item active">📁 New Payment</a>
            <a href="../batches/index.php" class="nav-item">📦 Batches</a>
            <a href="../beneficiaries/index.php" class="nav-item">👥 Beneficiaries</a>
            <a href="../templates/index.php" class="nav-item">📋 Templates</a>
            <a href="../reports/index.php" class="nav-item">📄 Reports</a>
            <a href="../settings/index.php" class="nav-item">⚙️ Settings</a>
        </div>
    </div>
    
    <div class="main">
        <div class="top-bar">
            <div class="greeting">
                <h1>Preview & Map Columns</h1>
            </div>
        </div>
        
        <div class="step-indicator">
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Upload</div></div>
            <div class="step active"><div class="step-number">2</div><div class="step-label">Map</div></div>
            <div class="step"><div class="step-number">3</div><div class="step-label">Validate</div></div>
            <div class="step"><div class="step-number">4</div><div class="step-label">Execute</div></div>
        </div>
        
        <div class="stats">
            <div class="stat">
                <div class="stat-value" id="total-rows"><?php echo count($previewData['rows'] ?? []); ?></div>
                <div class="stat-label">Preview Rows</div>
            </div>
            <div class="stat">
                <div class="stat-value" id="total-amount">P0</div>
                <div class="stat-label">Total Amount</div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">📋 File Preview (first 10 rows)</div>
            <div class="card-body">
                <div class="preview-table" id="preview-table"></div>
                
                <div class="save-template">
                    <input type="text" id="template-name" placeholder="Save this mapping as template...">
                    <button class="btn btn-secondary" onclick="saveMapping()">💾 Save Mapping</button>
                </div>
                
                <div class="btn-group">
                    <button class="btn btn-secondary" onclick="location.href='upload.php'">← Back</button>
                    <button class="btn btn-primary" onclick="proceedToValidate()">Continue to Validate →</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const fileData = <?php echo json_encode($previewData); ?>;
const batchId = <?php echo $batchId; ?>;
const systemFields = <?php echo json_encode($systemFields); ?>;

let columnMapping = {};

function renderPreview() {
    const headers = fileData.headers || [];
    const rows = fileData.rows || [];
    
    // Calculate total amount
    const amountIndex = headers.findIndex(h => h && h.toUpperCase().includes('AMOUNT'));
    if (amountIndex !== -1) {
        let total = 0;
        for (const row of rows) {
            total += parseFloat(row[amountIndex] || 0);
        }
        document.getElementById('total-amount').textContent = 'P' + total.toLocaleString();
    }
    
    let html = '<table><thead><tr>';
    for (let i = 0; i < headers.length; i++) {
        html += `<th>${headers[i] || 'Column ' + (i+1)}<br>
            <select class="mapping-select" data-col="${i}" onchange="updateMapping(${i}, this.value)">
                <option value="">— Skip Column —</option>`;
        for (const [field, label] of Object.entries(systemFields)) {
            html += `<option value="${field}">${label}</option>`;
        }
        html += `</select></th>`;
    }
    html += '</tr></thead><tbody>';
    
    for (let i = 0; i < Math.min(rows.length, 10); i++) {
        html += '<tr>';
        for (let j = 0; j < rows[i].length; j++) {
            html += `<td>${rows[i][j] || ''}</td>`;
        }
        html += '</tr>';
    }
    html += '</tbody></table>';
    
    document.getElementById('preview-table').innerHTML = html;
}

function updateMapping(colIndex, fieldName) {
    const header = fileData.headers[colIndex];
    columnMapping[header] = fieldName;
}

function saveMapping() {
    const templateName = document.getElementById('template-name').value;
    if (!templateName) {
        alert('Please enter a template name');
        return;
    }
    
    fetch('save_mapping.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            template_name: templateName,
            column_mapping: columnMapping,
            batch_id: batchId
        })
    }).then(res => res.json()).then(data => {
        if (data.success) {
            alert('Template saved successfully!');
        } else {
            alert('Error saving template');
        }
    });
}

function proceedToValidate() {
    fetch('save_batch_mapping.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            batch_id: batchId,
            column_mapping: columnMapping
        })
    }).then(() => {
        window.location.href = `validate.php?batch_id=${batchId}`;
    });
}

renderPreview();
</script>
</body>
</html>
