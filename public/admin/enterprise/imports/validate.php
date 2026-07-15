<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;
use PhpOffice\PhpSpreadsheet\IOFactory;  // <-- MOVED THIS TO THE TOP

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$batchId = $_GET['batch_id'] ?? 0;

$stmt = $db->prepare("SELECT * FROM import_batches WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

$mapping = json_decode($batch['import_mapping'] ?? '{}', true);

// Parse file and validate rows
$filePath = '/tmp/vouchmorph_uploads/' . $batchId . '_' . $batch['original_filename'];
$validRows = [];
$invalidRows = [];
$warningRows = [];
$totalAmount = 0;
$rowNumber = 0;

if (file_exists($filePath)) {
    $extension = strtolower(pathinfo($batch['original_filename'], PATHINFO_EXTENSION));
    
    if ($extension === 'csv') {
        $handle = fopen($filePath, 'r');
        $headers = fgetcsv($handle);
        
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $mapped = [];
            $errors = [];
            $warnings = [];
            
            foreach ($headers as $idx => $header) {
                $systemField = $mapping[$header] ?? null;
                if ($systemField && isset($row[$idx])) {
                    $mapped[$systemField] = trim($row[$idx]);
                }
            }
            
            // Validate required fields
            if (empty($mapped['amount']) || $mapped['amount'] <= 0) {
                $errors[] = 'Invalid or missing amount';
            } else {
                $totalAmount += floatval($mapped['amount']);
            }
            
            if (empty($mapped['phone']) && empty($mapped['account_number']) && empty($mapped['wallet_id'])) {
                $errors[] = 'No destination identifier (phone/account/wallet)';
            }
            
            if (empty($mapped['full_name']) && empty($mapped['first_name'])) {
                $warnings[] = 'Missing recipient name';
            }
            
            // Phone validation
            if (!empty($mapped['phone'])) {
                $phone = preg_replace('/[^0-9]/', '', $mapped['phone']);
                if (strlen($phone) === 8) {
                    $mapped['phone'] = '+267' . $phone;
                    $warnings[] = 'Phone number normalized to +267 format';
                } elseif (strlen($phone) === 9 && substr($phone, 0, 1) === '0') {
                    $mapped['phone'] = '+267' . substr($phone, 1);
                    $warnings[] = 'Phone number normalized to +267 format';
                } elseif (strlen($phone) === 10 && substr($phone, 0, 2) === '27') {
                    $mapped['phone'] = '+' . $phone;
                    $warnings[] = 'Phone number normalized to +27 format';
                } elseif (strlen($phone) === 11 && substr($phone, 0, 3) === '267') {
                    $mapped['phone'] = '+' . $phone;
                    $warnings[] = 'Phone number normalized to +267 format';
                }
            }
            
            // Account validation
            if (!empty($mapped['account_number']) && empty($mapped['destination_provider'])) {
                $warnings[] = 'Bank account provided but no bank code. Will attempt auto-detection.';
            }
            
            // Amount validation
            if (!empty($mapped['amount']) && is_numeric($mapped['amount']) && $mapped['amount'] > 100000) {
                $warnings[] = 'Amount exceeds normal threshold. Please verify.';
            }
            
            $rowData = [
                'batch_id' => $batchId,
                'row_number' => $rowNumber,
                'raw_data' => json_encode($row),
                'mapped_data' => json_encode($mapped),
                'recipient_name' => $mapped['full_name'] ?? ($mapped['first_name'] . ' ' . ($mapped['last_name'] ?? '')),
                'recipient_phone' => $mapped['phone'] ?? null,
                'recipient_national_id' => $mapped['national_id'] ?? null,
                'amount' => floatval($mapped['amount'] ?? 0),
                'currency' => $mapped['currency'] ?? 'BWP',
                'destination_type' => $mapped['destination_type'] ?? ($mapped['wallet_id'] ? 'WALLET' : ($mapped['account_number'] ? 'ACCOUNT' : 'PHONE')),
                'destination_provider' => $mapped['destination_provider'] ?? null,
                'destination_value' => $mapped['phone'] ?? $mapped['account_number'] ?? $mapped['wallet_id'] ?? null,
                'validation_status' => empty($errors) ? 'VALID' : 'INVALID',
                'validation_errors' => json_encode($errors),
                'validation_warnings' => json_encode($warnings)
            ];
            
            // Insert into import_rows
            $insertStmt = $db->prepare("
                INSERT INTO import_rows (
                    batch_id, row_number, raw_data, mapped_data, recipient_name, recipient_phone,
                    recipient_national_id, amount, currency, destination_type, destination_provider,
                    destination_value, validation_status, validation_errors, validation_warnings
                ) VALUES (
                    :batch_id, :row_number, :raw_data, :mapped_data, :recipient_name, :recipient_phone,
                    :recipient_national_id, :amount, :currency, :destination_type, :destination_provider,
                    :destination_value, :validation_status, :validation_errors, :validation_warnings
                )
            ");
            
            $insertStmt->execute([
                ':batch_id' => $batchId,
                ':row_number' => $rowNumber,
                ':raw_data' => $rowData['raw_data'],
                ':mapped_data' => $rowData['mapped_data'],
                ':recipient_name' => $rowData['recipient_name'],
                ':recipient_phone' => $rowData['recipient_phone'],
                ':recipient_national_id' => $rowData['recipient_national_id'],
                ':amount' => $rowData['amount'],
                ':currency' => $rowData['currency'],
                ':destination_type' => $rowData['destination_type'],
                ':destination_provider' => $rowData['destination_provider'],
                ':destination_value' => $rowData['destination_value'],
                ':validation_status' => $rowData['validation_status'],
                ':validation_errors' => $rowData['validation_errors'],
                ':validation_warnings' => $rowData['validation_warnings']
            ]);
            
            if (empty($errors)) {
                $validRows[] = $rowData;
            } else {
                $invalidRows[] = $rowData;
            }
        }
        fclose($handle);
    } elseif ($extension === 'xlsx' || $extension === 'xls') {
        // Excel processing with PhpSpreadsheet
        try {
            require_once '../../../../vendor/autoload.php';
            // use PhpOffice\PhpSpreadsheet\IOFactory; // <-- REMOVED THIS LINE FROM HERE
            
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            $headers = array_shift($rows);
            
            foreach ($rows as $row) {
                $rowNumber++;
                $mapped = [];
                $errors = [];
                $warnings = [];
                
                foreach ($headers as $idx => $header) {
                    $systemField = $mapping[$header] ?? null;
                    if ($systemField && isset($row[$idx])) {
                        $mapped[$systemField] = trim($row[$idx]);
                    }
                }
                
                // Same validation logic as CSV
                if (empty($mapped['amount']) || $mapped['amount'] <= 0) {
                    $errors[] = 'Invalid or missing amount';
                } else {
                    $totalAmount += floatval($mapped['amount']);
                }
                
                if (empty($mapped['phone']) && empty($mapped['account_number']) && empty($mapped['wallet_id'])) {
                    $errors[] = 'No destination identifier (phone/account/wallet)';
                }
                
                if (empty($mapped['full_name']) && empty($mapped['first_name'])) {
                    $warnings[] = 'Missing recipient name';
                }
                
                // Phone validation
                if (!empty($mapped['phone'])) {
                    $phone = preg_replace('/[^0-9]/', '', $mapped['phone']);
                    if (strlen($phone) === 8) {
                        $mapped['phone'] = '+267' . $phone;
                        $warnings[] = 'Phone number normalized to +267 format';
                    } elseif (strlen($phone) === 9 && substr($phone, 0, 1) === '0') {
                        $mapped['phone'] = '+267' . substr($phone, 1);
                        $warnings[] = 'Phone number normalized to +267 format';
                    }
                }
                
                $rowData = [
                    'batch_id' => $batchId,
                    'row_number' => $rowNumber,
                    'raw_data' => json_encode($row),
                    'mapped_data' => json_encode($mapped),
                    'recipient_name' => $mapped['full_name'] ?? ($mapped['first_name'] . ' ' . ($mapped['last_name'] ?? '')),
                    'recipient_phone' => $mapped['phone'] ?? null,
                    'recipient_national_id' => $mapped['national_id'] ?? null,
                    'amount' => floatval($mapped['amount'] ?? 0),
                    'currency' => $mapped['currency'] ?? 'BWP',
                    'destination_type' => $mapped['destination_type'] ?? ($mapped['wallet_id'] ? 'WALLET' : ($mapped['account_number'] ? 'ACCOUNT' : 'PHONE')),
                    'destination_provider' => $mapped['destination_provider'] ?? null,
                    'destination_value' => $mapped['phone'] ?? $mapped['account_number'] ?? $mapped['wallet_id'] ?? null,
                    'validation_status' => empty($errors) ? 'VALID' : 'INVALID',
                    'validation_errors' => json_encode($errors),
                    'validation_warnings' => json_encode($warnings)
                ];
                
                $insertStmt = $db->prepare("
                    INSERT INTO import_rows (
                        batch_id, row_number, raw_data, mapped_data, recipient_name, recipient_phone,
                        recipient_national_id, amount, currency, destination_type, destination_provider,
                        destination_value, validation_status, validation_errors, validation_warnings
                    ) VALUES (
                        :batch_id, :row_number, :raw_data, :mapped_data, :recipient_name, :recipient_phone,
                        :recipient_national_id, :amount, :currency, :destination_type, :destination_provider,
                        :destination_value, :validation_status, :validation_errors, :validation_warnings
                    )
                ");
                
                $insertStmt->execute([
                    ':batch_id' => $batchId,
                    ':row_number' => $rowNumber,
                    ':raw_data' => $rowData['raw_data'],
                    ':mapped_data' => $rowData['mapped_data'],
                    ':recipient_name' => $rowData['recipient_name'],
                    ':recipient_phone' => $rowData['recipient_phone'],
                    ':recipient_national_id' => $rowData['recipient_national_id'],
                    ':amount' => $rowData['amount'],
                    ':currency' => $rowData['currency'],
                    ':destination_type' => $rowData['destination_type'],
                    ':destination_provider' => $rowData['destination_provider'],
                    ':destination_value' => $rowData['destination_value'],
                    ':validation_status' => $rowData['validation_status'],
                    ':validation_errors' => $rowData['validation_errors'],
                    ':validation_warnings' => $rowData['validation_warnings']
                ]);
                
                if (empty($errors)) {
                    $validRows[] = $rowData;
                } else {
                    $invalidRows[] = $rowData;
                }
            }
        } catch (Exception $e) {
            error_log("Excel parsing error: " . $e->getMessage());
        }
    } elseif ($extension === 'json') {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);
        $rows = $data['data'] ?? $data['records'] ?? $data;
        
        // If associative array, convert to indexed
        if (!isset($rows[0])) {
            $rows = [$rows];
        }
        
        $headers = array_keys($rows[0]);
        
        foreach ($rows as $row) {
            $rowNumber++;
            $mapped = [];
            $errors = [];
            $warnings = [];
            
            foreach ($headers as $header) {
                $systemField = $mapping[$header] ?? null;
                if ($systemField && isset($row[$header])) {
                    $mapped[$systemField] = trim($row[$header]);
                }
            }
            
            // Same validation logic
            if (empty($mapped['amount']) || $mapped['amount'] <= 0) {
                $errors[] = 'Invalid or missing amount';
            } else {
                $totalAmount += floatval($mapped['amount']);
            }
            
            if (empty($mapped['phone']) && empty($mapped['account_number']) && empty($mapped['wallet_id'])) {
                $errors[] = 'No destination identifier (phone/account/wallet)';
            }
            
            if (empty($mapped['full_name']) && empty($mapped['first_name'])) {
                $warnings[] = 'Missing recipient name';
            }
            
            $rowData = [
                'batch_id' => $batchId,
                'row_number' => $rowNumber,
                'raw_data' => json_encode($row),
                'mapped_data' => json_encode($mapped),
                'recipient_name' => $mapped['full_name'] ?? ($mapped['first_name'] . ' ' . ($mapped['last_name'] ?? '')),
                'recipient_phone' => $mapped['phone'] ?? null,
                'recipient_national_id' => $mapped['national_id'] ?? null,
                'amount' => floatval($mapped['amount'] ?? 0),
                'currency' => $mapped['currency'] ?? 'BWP',
                'destination_type' => $mapped['destination_type'] ?? ($mapped['wallet_id'] ? 'WALLET' : ($mapped['account_number'] ? 'ACCOUNT' : 'PHONE')),
                'destination_provider' => $mapped['destination_provider'] ?? null,
                'destination_value' => $mapped['phone'] ?? $mapped['account_number'] ?? $mapped['wallet_id'] ?? null,
                'validation_status' => empty($errors) ? 'VALID' : 'INVALID',
                'validation_errors' => json_encode($errors),
                'validation_warnings' => json_encode($warnings)
            ];
            
            $insertStmt = $db->prepare("
                INSERT INTO import_rows (
                    batch_id, row_number, raw_data, mapped_data, recipient_name, recipient_phone,
                    recipient_national_id, amount, currency, destination_type, destination_provider,
                    destination_value, validation_status, validation_errors, validation_warnings
                ) VALUES (
                    :batch_id, :row_number, :raw_data, :mapped_data, :recipient_name, :recipient_phone,
                    :recipient_national_id, :amount, :currency, :destination_type, :destination_provider,
                    :destination_value, :validation_status, :validation_errors, :validation_warnings
                )
            ");
            
            $insertStmt->execute([
                ':batch_id' => $batchId,
                ':row_number' => $rowNumber,
                ':raw_data' => $rowData['raw_data'],
                ':mapped_data' => $rowData['mapped_data'],
                ':recipient_name' => $rowData['recipient_name'],
                ':recipient_phone' => $rowData['recipient_phone'],
                ':recipient_national_id' => $rowData['recipient_national_id'],
                ':amount' => $rowData['amount'],
                ':currency' => $rowData['currency'],
                ':destination_type' => $rowData['destination_type'],
                ':destination_provider' => $rowData['destination_provider'],
                ':destination_value' => $rowData['destination_value'],
                ':validation_status' => $rowData['validation_status'],
                ':validation_errors' => $rowData['validation_errors'],
                ':validation_warnings' => $rowData['validation_warnings']
            ]);
            
            if (empty($errors)) {
                $validRows[] = $rowData;
            } else {
                $invalidRows[] = $rowData;
            }
        }
    }
}

// Update batch with counts
$validCount = count($validRows);
$invalidCount = count($invalidRows);
$status = $invalidCount > 0 ? ($validCount > 0 ? 'VALIDATED_WITH_ERRORS' : 'VALIDATION_FAILED') : 'READY_FOR_APPROVAL';

$stmt = $db->prepare("
    UPDATE import_batches 
    SET total_rows = :total, valid_rows = :valid, invalid_rows = :invalid, 
        total_amount = :amount, status = :status
    WHERE id = :id
");
$stmt->execute([
    ':total' => $rowNumber,
    ':valid' => $validCount,
    ':invalid' => $invalidCount,
    ':amount' => $totalAmount,
    ':status' => $status,
    ':id' => $batchId
]);

// Get warning rows for display
$stmt = $db->prepare("
    SELECT * FROM import_rows 
    WHERE batch_id = :batch_id AND validation_status = 'VALID' 
    AND validation_warnings IS NOT NULL AND validation_warnings != '[]'
");
$stmt->execute([':batch_id' => $batchId]);
$warningRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validation Results - VouchMorph Enterprise</title>
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
        .nav-item:hover, .nav-item.active { background: #1e293b; color: white; }
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-value { font-size: 32px; font-weight: 700; }
        .stat-value.valid { color: #10b981; }
        .stat-value.invalid { color: #ef4444; }
        .stat-value.warning { color: #f59e0b; }
        .stat-label { color: #64748b; font-size: 14px; margin-top: 4px; }
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
        }
        .card-body { padding: 24px; }
        .error-list, .warning-list {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .error-list { background: #fef2f2; }
        .warning-list { background: #fffbeb; }
        .error-item {
            padding: 8px 0;
            border-bottom: 1px solid #fecaca;
            font-size: 13px;
            color: #991b1b;
        }
        .warning-item {
            padding: 8px 0;
            border-bottom: 1px solid #fde68a;
            font-size: 13px;
            color: #92400e;
        }
        .btn-group { display: flex; gap: 16px; justify-content: flex-end; margin-top: 24px; }
        .btn {
            padding: 12px 28px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #10b981; }
        .alert-warning { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
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
                <h1>Validation Results</h1>
            </div>
        </div>
        
        <div class="step-indicator">
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Upload</div></div>
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Map</div></div>
            <div class="step active"><div class="step-number">3</div><div class="step-label">Validate</div></div>
            <div class="step"><div class="step-number">4</div><div class="step-label">Source</div></div>
            <div class="step"><div class="step-number">5</div><div class="step-label">Review</div></div>
            <div class="step"><div class="step-number">6</div><div class="step-label">Execute</div></div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value valid"><?php echo number_format($validCount); ?></div>
                <div class="stat-label">✅ Valid Rows</div>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo count($warningRows) > 0 ? 'warning' : ''; ?>"><?php echo number_format(count($warningRows)); ?></div>
                <div class="stat-label">⚠️ With Warnings</div>
            </div>
            <div class="stat-card">
                <div class="stat-value invalid"><?php echo number_format($invalidCount); ?></div>
                <div class="stat-label">❌ Invalid Rows</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">P<?php echo number_format($totalAmount, 2); ?></div>
                <div class="stat-label">💰 Total Amount</div>
            </div>
        </div>
        
        <?php if ($invalidCount === 0 && count($warningRows) === 0): ?>
            <div class="alert alert-success">
                ✅ All <?php echo $validCount; ?> rows are valid! Ready to proceed to source selection.
            </div>
        <?php elseif ($invalidCount === 0 && count($warningRows) > 0): ?>
            <div class="alert alert-warning">
                ⚠️ All <?php echo $validCount; ?> rows are valid, but <?php echo count($warningRows); ?> rows have warnings. Review below or proceed.
            </div>
        <?php elseif ($invalidCount > 0 && $validCount > 0): ?>
            <div class="alert alert-warning">
                ⚠️ Partial validation: <?php echo $validCount; ?> valid, <?php echo $invalidCount; ?> invalid. Fix invalid rows or proceed with valid ones.
            </div>
        <?php else: ?>
            <div class="alert alert-error">
                ❌ Validation failed: No valid rows found. Please check your file and column mapping.
            </div>
        <?php endif; ?>
        
        <?php if (count($warningRows) > 0): ?>
        <div class="card">
            <div class="card-header">
                ⚠️ Rows with Warnings (<?php echo count($warningRows); ?>)
                <button class="btn btn-secondary btn-sm" onclick="downloadWarningReport()">📥 Download Warnings</button>
            </div>
            <div class="card-body">
                <div class="warning-list">
                    <?php foreach (array_slice($warningRows, 0, 20) as $row): ?>
                    <div class="warning-item">
                        <strong>Row <?php echo $row['row_number']; ?>:</strong> 
                        <?php 
                            $warnings = json_decode($row['validation_warnings'] ?? '[]', true);
                            echo implode('; ', $warnings);
                        ?>
                        <?php if ($row['recipient_name']): ?>
                            <br><span style="font-size: 11px; color: #666;">Recipient: <?php echo htmlspecialchars($row['recipient_name']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($warningRows) > 20): ?>
                        <div class="warning-item" style="text-align: center;">
                            ... and <?php echo count($warningRows) - 20; ?> more warnings
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($invalidCount > 0): ?>
        <div class="card">
            <div class="card-header">
                ❌ Invalid Rows (<?php echo $invalidCount; ?>)
                <button class="btn btn-secondary btn-sm" onclick="downloadErrorReport()">📥 Download Errors</button>
            </div>
            <div class="card-body">
                <div class="error-list">
                    <?php foreach (array_slice($invalidRows, 0, 20) as $row): ?>
                    <div class="error-item">
                        <strong>Row <?php echo $row['row_number']; ?>:</strong> 
                        <?php 
                            $errors = json_decode($row['validation_errors'] ?? '[]', true);
                            echo implode('; ', $errors);
                        ?>
                        <?php if ($row['recipient_name']): ?>
                            <br><span style="font-size: 11px; color: #666;">Recipient: <?php echo htmlspecialchars($row['recipient_name']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($invalidCount > 20): ?>
                        <div class="error-item" style="text-align: center;">
                            ... and <?php echo $invalidCount - 20; ?> more errors
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">✅ Valid Rows Preview (first 20)</div>
            <div class="card-body">
                <div style="overflow-x: auto; max-height: 400px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Name</th>
                                <th>Phone/Account</th>
                                <th>Amount</th>
                                <th>Destination Type</th>
                                <th>Warnings</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($validRows, 0, 20) as $row): ?>
                            <tr>
                                <td><?php echo $row['row_number']; ?></td>
                                <td><?php echo htmlspecialchars($row['recipient_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['destination_value'] ?? '-'); ?></td>
                                <td>P<?php echo number_format($row['amount'], 2); ?></td>
                                <td><?php echo $row['destination_type']; ?></td>
                                <td>
                                    <?php 
                                        $warnings = json_decode($row['validation_warnings'] ?? '[]', true);
                                        if (!empty($warnings)) {
                                            echo '<span style="color: #f59e0b;">⚠️ ' . count($warnings) . '</span>';
                                        } else {
                                            echo '—';
                                        }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($validRows)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px;">
                                    No valid rows found. Please check your file format and column mapping.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="btn-group">
            <button class="btn btn-secondary" onclick="location.href='preview.php?batch_id=<?php echo $batchId; ?>'">← Back to Mapping</button>
            <?php if ($validCount > 0): ?>
                <button class="btn btn-primary" onclick="proceedToSources()">Continue to Source Selection →</button>
            <?php else: ?>
                <button class="btn btn-danger" onclick="location.href='upload.php'">Upload New File</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function downloadErrorReport() {
    window.location.href = `download_report.php?batch_id=<?php echo $batchId; ?>&type=errors`;
}

function downloadWarningReport() {
    window.location.href = `download_report.php?batch_id=<?php echo $batchId; ?>&type=warnings`;
}

function proceedToSources() {
    window.location.href = `sources.php?batch_id=<?php echo $batchId; ?>`;
}

// Auto-refresh status if processing (for future use)
function pollStatus() {
    fetch(`get_batch_status.php?batch_id=<?php echo $batchId; ?>`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'PROCESSING') {
                setTimeout(pollStatus, 2000);
            }
        });
}
</script>
</body>
</html>
