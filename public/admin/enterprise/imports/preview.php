<?php
require_once __DIR__ . '/../../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

use Core\Database\DBConnection;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
$previewData = ['headers' => [], 'rows' => []];
$totalAmount = 0;

if (file_exists($filePath)) {
    $extension = strtolower(pathinfo($batch['original_filename'], PATHINFO_EXTENSION));
    
    try {
        // Excel file support
        if (in_array($extension, ['xls', 'xlsx', 'xlsm', 'xltx', 'xltm'])) {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (!empty($rows)) {
                $headers = array_shift($rows);
                $previewData['headers'] = $headers;
                $previewData['rows'] = array_slice($rows, 0, 10);
                
                // Calculate total amount
                $amountIndex = array_search('amount', array_map('strtolower', $headers));
                if ($amountIndex === false) {
                    $amountIndex = array_search('AMOUNT', $headers);
                }
                if ($amountIndex === false) {
                    $amountIndex = array_search('Amount', $headers);
                }
                if ($amountIndex !== false) {
                    foreach ($rows as $row) {
                        $totalAmount += floatval($row[$amountIndex] ?? 0);
                    }
                }
            }
        }
        // CSV file support
        elseif ($extension === 'csv') {
            $handle = fopen($filePath, 'r');
            if ($handle) {
                // Detect delimiter
                $firstLine = fgets($handle);
                rewind($handle);
                $delimiter = ',';
                if (strpos($firstLine, ';') !== false) {
                    $delimiter = ';';
                } elseif (strpos($firstLine, "\t") !== false) {
                    $delimiter = "\t";
                }
                
                $headers = fgetcsv($handle, 0, $delimiter);
                if ($headers) {
                    $previewData['headers'] = $headers;
                    $rows = [];
                    $rowCount = 0;
                    while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $rowCount < 10) {
                        // Ensure row has same number of columns as headers
                        while (count($row) < count($headers)) {
                            $row[] = '';
                        }
                        $rows[] = array_slice($row, 0, count($headers));
                        $rowCount++;
                    }
                    $previewData['rows'] = $rows;
                    
                    // Calculate total amount
                    $amountIndex = array_search('amount', array_map('strtolower', $headers));
                    if ($amountIndex === false) {
                        $amountIndex = array_search('AMOUNT', $headers);
                    }
                    if ($amountIndex === false) {
                        $amountIndex = array_search('Amount', $headers);
                    }
                    if ($amountIndex !== false) {
                        // Re-read file to calculate total
                        rewind($handle);
                        fgetcsv($handle, 0, $delimiter); // Skip headers
                        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $totalAmount += floatval($row[$amountIndex] ?? 0);
                        }
                    }
                }
                fclose($handle);
            }
        }
        // JSON file support
        elseif ($extension === 'json') {
            $content = file_get_contents($filePath);
            $data = json_decode($content, true);
            
            if ($data) {
                $rows = $data['data'] ?? $data['records'] ?? $data;
                if (!isset($rows[0])) {
                    $rows = [$rows];
                }
                
                if (!empty($rows)) {
                    $previewData['headers'] = array_keys($rows[0]);
                    $previewData['rows'] = array_slice($rows, 0, 10);
                    
                    // Calculate total amount
                    $amountIndex = array_search('amount', array_map('strtolower', $previewData['headers']));
                    if ($amountIndex !== false) {
                        foreach ($rows as $row) {
                            $totalAmount += floatval($row[$previewData['headers'][$amountIndex]] ?? 0);
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Preview error: " . $e->getMessage());
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
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
            background: white;
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
            min-width: 150px;
        }
        .stat-value { font-size: 28px; font-weight: 700; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 4px; }
        .btn-group { display: flex; gap: 16px; justify-content: flex-end; margin-top: 24px; flex-wrap: wrap; }
        .btn {
            padding: 12px 28px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #1e293b; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-warning:hover { background: #d97706; }
        .save-template {
            margin-top: 20px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .save-template input {
            flex: 1;
            min-width: 200px;
            padding: 10px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
        }
        .file-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-excel { background: #dcfce7; color: #166534; }
        .badge-csv { background: #fef3c7; color: #92400e; }
        .badge-json { background: #dbeafe; color: #1e40af; }
        .no-data { text-align: center; padding: 40px; color: #64748b; }
        .no-data .icon { font-size: 48px; margin-bottom: 16px; }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 16px; }
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
                <span style="font-size: 14px; color: #64748b;">
                    File: <?php echo htmlspecialchars($batch['original_filename']); ?>
                    <span class="file-badge badge-<?php 
                        $ext = strtolower(pathinfo($batch['original_filename'], PATHINFO_EXTENSION));
                        echo in_array($ext, ['xls', 'xlsx', 'xlsm']) ? 'excel' : $ext;
                    ?>">
                        <?php echo strtoupper($ext); ?>
                    </span>
                </span>
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
                <div class="stat-value" id="total-amount">P<?php echo number_format($totalAmount, 2); ?></div>
                <div class="stat-label">Total Amount</div>
            </div>
            <div class="stat">
                <div class="stat-value" id="total-columns"><?php echo count($previewData['headers'] ?? []); ?></div>
                <div class="stat-label">Columns</div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <span>📋 File Preview (first 10 rows)</span>
                <span style="font-size: 12px; color: #64748b;">Map each column to a system field</span>
            </div>
            <div class="card-body">
                <div class="preview-table" id="preview-table">
                    <?php if (empty($previewData['headers']) || empty($previewData['rows'])): ?>
                    <div class="no-data">
                        <div class="icon">📭</div>
                        <p>No data found in file or file is empty.</p>
                        <p style="font-size: 13px; margin-top: 8px;">Please check your file format and try again.</p>
                        <button class="btn btn-secondary" onclick="location.href='upload.php'" style="margin-top: 16px;">← Upload New File</button>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="save-template">
                    <input type="text" id="template-name" placeholder="Save this mapping as template...">
                    <button class="btn btn-secondary" onclick="saveMapping()">💾 Save Mapping</button>
                    <button class="btn btn-warning" onclick="loadTemplate()">📂 Load Template</button>
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
    
    // Show total rows
    document.getElementById('total-rows').textContent = rows.length;
    document.getElementById('total-columns').textContent = headers.length;
    
    // Calculate total amount from preview data (already calculated server-side)
    // But we'll also display it
    const totalAmount = <?php echo $totalAmount; ?>;
    document.getElementById('total-amount').textContent = 'P' + totalAmount.toLocaleString();
    
    if (!headers.length || !rows.length) {
        return;
    }
    
    // Find amount column for highlighting
    const amountIndex = headers.findIndex(h => 
        h && (h.toUpperCase().includes('AMOUNT') || h.toLowerCase().includes('amount'))
    );
    
    let html = '<table><thead><tr>';
    for (let i = 0; i < headers.length; i++) {
        const header = headers[i] || 'Column ' + (i+1);
        const isAmount = (i === amountIndex);
        html += `<th style="${isAmount ? 'border-right: 3px solid #10b981;' : ''}">
            ${header}
            ${isAmount ? '<span style="color: #10b981; font-size: 10px;">💰</span>' : ''}
            <br>
            <select class="mapping-select" data-col="${i}" onchange="updateMapping(${i}, this.value)">
                <option value="">— Skip Column —</option>`;
        for (const [field, label] of Object.entries(systemFields)) {
            // Try to auto-match
            const autoMatch = header.toLowerCase().includes(field.replace('_', ' ')) || 
                             field.toLowerCase().includes(header.toLowerCase()) ||
                             (field === 'amount' && isAmount);
            html += `<option value="${field}" ${autoMatch ? 'selected' : ''}>${label}</option>`;
        }
        html += `</select></th>`;
    }
    html += '</tr></thead><tbody>';
    
    for (let i = 0; i < Math.min(rows.length, 10); i++) {
        html += '<tr>';
        const row = rows[i];
        for (let j = 0; j < headers.length; j++) {
            const value = row[j] !== undefined && row[j] !== null ? row[j] : '';
            const isAmount = (j === amountIndex && value);
            html += `<td style="${isAmount ? 'font-weight: 600; color: #10b981;' : ''}">${value}</td>`;
        }
        html += '</tr>';
    }
    html += '</tbody></table>';
    
    document.getElementById('preview-table').innerHTML = html;
    
    // Initialize mapping from auto-selected values
    document.querySelectorAll('.mapping-select').forEach(select => {
        const col = parseInt(select.dataset.col);
        if (select.value) {
            columnMapping[headers[col]] = select.value;
        }
    });
}

function updateMapping(colIndex, fieldName) {
    const header = fileData.headers[colIndex];
    if (header) {
        if (fieldName) {
            columnMapping[header] = fieldName;
        } else {
            delete columnMapping[header];
        }
    }
}

function saveMapping() {
    const templateName = document.getElementById('template-name').value.trim();
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
            batch_id: batchId,
            file_headers: fileData.headers
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('✅ Template saved successfully!');
            document.getElementById('template-name').value = '';
        } else {
            alert('❌ Error saving template: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('❌ Error: ' + err.message);
    });
}

function loadTemplate() {
    const templateName = prompt('Enter template name to load:');
    if (!templateName) return;
    
    fetch(`load_mapping.php?template_name=${encodeURIComponent(templateName)}&batch_id=${batchId}`)
    .then(res => res.json())
    .then(data => {
        if (data.success && data.mapping) {
            columnMapping = data.mapping;
            // Update dropdowns
            document.querySelectorAll('.mapping-select').forEach(select => {
                const col = parseInt(select.dataset.col);
                const header = fileData.headers[col];
                const value = columnMapping[header] || '';
                select.value = value;
            });
            alert('✅ Template loaded successfully!');
        } else {
            alert('❌ Template not found or error loading');
        }
    })
    .catch(err => {
        alert('❌ Error loading template: ' + err.message);
    });
}

function proceedToValidate() {
    // Save mapping first
    fetch('save_batch_mapping.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            batch_id: batchId,
            column_mapping: columnMapping,
            file_headers: fileData.headers
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.href = `validate.php?batch_id=${batchId}`;
        } else {
            alert('❌ Error saving mapping: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('❌ Error: ' + err.message);
    });
}

// Auto-render on page load
document.addEventListener('DOMContentLoaded', renderPreview);
</script>
</body>
</html>
