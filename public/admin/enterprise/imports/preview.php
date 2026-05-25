<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();

$batchId = $_GET['batch_id'] ?? 0;

// Get batch info
$stmt = $db->prepare("
    SELECT * FROM import_batches 
    WHERE id = :id AND organization_id = :org_id
");
$stmt->execute([':id' => $batchId, ':org_id' => $user['organization_id']]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

// Parse the file and show preview
// This would use the ImportParser service
$previewRows = []; // Would be populated from parsed file

?>
<!DOCTYPE html>
<html>
<head>
    <title>Preview & Map Columns - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            color: #1e293b;
            padding: 40px 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { margin-bottom: 30px; }
        .header h1 { font-size: 28px; font-weight: 700; }
        .header p { color: #64748b; margin-top: 8px; }
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
            font-size: 18px;
        }
        .card-body { padding: 24px; }
        .preview-table {
            width: 100%;
            overflow-x: auto;
            font-size: 13px;
        }
        .preview-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .preview-table th, .preview-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        .preview-table th {
            background: #f8fafc;
            font-weight: 600;
            position: relative;
        }
        .mapping-select {
            margin-top: 8px;
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 12px;
            background: white;
        }
        .btn-group { display: flex; gap: 16px; justify-content: flex-end; margin-top: 24px; }
        .btn {
            padding: 12px 28px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
        }
        .btn-primary { background: #1e293b; color: white; }
        .btn-secondary { background: #e2e8f0; color: #1e293b; }
        .stats {
            display: flex;
            gap: 24px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .stat {
            background: #f8fafc;
            padding: 16px 24px;
            border-radius: 16px;
            flex: 1;
        }
        .stat-value { font-size: 32px; font-weight: 700; }
        .stat-label { font-size: 14px; color: #64748b; margin-top: 4px; }
        .save-template {
            margin-top: 16px;
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
<div class="container">
    <div class="header">
        <h1>📊 Preview & Map Columns</h1>
        <p>Batch: <?php echo htmlspecialchars($batch['batch_name']); ?> • <?php echo htmlspecialchars($batch['original_filename']); ?></p>
    </div>
    
    <div class="stats">
        <div class="stat">
            <div class="stat-value" id="total-rows">0</div>
            <div class="stat-label">Total Rows</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="total-amount">P0</div>
            <div class="stat-label">Total Amount</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="unique-recipients">0</div>
            <div class="stat-label">Unique Recipients</div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            📋 File Preview (first 10 rows)
        </div>
        <div class="card-body">
            <div class="preview-table" id="preview-table">
                <!-- Table will be populated by JavaScript -->
            </div>
            
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

<script>
// This would be populated from the backend
const fileData = <?php 
    // Simulated - would actually parse the file
    echo json_encode([
        'headers' => ['OMANG', 'SURNAME', 'FIRST_NAME', 'CELL', 'AMOUNT', 'BANK', 'ACCOUNT'],
        'rows' => [
            ['101-01-001', 'MOLOI', 'THABO', '71234567', '1200.00', 'ZURUBANK', '10000001'],
            ['101-01-002', 'NTHO', 'KEOGA', '72345678', '1200.00', 'ZURUBANK', '10000002'],
            ['101-01-003', 'SELEKA', 'MPHO', '73456789', '1200.00', 'SACCUSSALIS', '20000001']
        ]
    ]);
?>;

const systemFields = {
    'national_id': 'National ID (OMANG)',
    'full_name': 'Full Name',
    'first_name': 'First Name',
    'last_name': 'Last Name',
    'phone': 'Phone Number',
    'amount': 'Amount',
    'currency': 'Currency',
    'account_number': 'Bank Account',
    'bank_code': 'Bank Code',
    'wallet_id': 'Mobile Wallet ID',
    'destination_type': 'Destination Type',
    'destination_provider': 'Provider (ZURUBANK/SACCUSSALIS)',
    'email': 'Email Address',
    'payment_reference': 'Payment Reference'
};

function renderPreview() {
    const headers = fileData.headers;
    const rows = fileData.rows;
    
    document.getElementById('total-rows').textContent = rows.length;
    
    let totalAmount = 0;
    const amountIndex = headers.findIndex(h => h.toUpperCase().includes('AMOUNT'));
    if (amountIndex !== -1) {
        totalAmount = rows.reduce((sum, row) => sum + parseFloat(row[amountIndex] || 0), 0);
        document.getElementById('total-amount').textContent = 'P' + totalAmount.toLocaleString();
    }
    
    let html = '<table><thead><tr>';
    for (let i = 0; i < headers.length; i++) {
        html += `<th>${headers[i]}<br>
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
            html += `<td>${rows[i][j]}</td>`;
        }
        html += '</tr>';
    }
    html += '</tbody></table>';
    
    document.getElementById('preview-table').innerHTML = html;
}

let columnMapping = {};

function updateMapping(colIndex, fieldName) {
    columnMapping[fileData.headers[colIndex]] = fieldName;
    console.log('Mapping updated:', columnMapping);
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
            batch_id: <?php echo $batchId; ?>
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
    // Save mapping to batch
    fetch('save_batch_mapping.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            batch_id: <?php echo $batchId; ?>,
            column_mapping: columnMapping
        })
    }).then(() => {
        window.location.href = `validate.php?batch_id=<?php echo $batchId; ?>`;
    });
}

renderPreview();
</script>
</body>
</html>
