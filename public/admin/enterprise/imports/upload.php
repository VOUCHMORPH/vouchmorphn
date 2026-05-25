<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();

$organizations = $db->prepare("SELECT * FROM organizations WHERE id = :id");
$organizations->execute([':id' => $user['organization_id']]);
$org = $organizations->fetch(PDO::FETCH_ASSOC);

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['payment_file'];
    $originalName = $file['name'];
    $tmpPath = $file['tmp_name'];
    
    // Detect file type
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['csv', 'xlsx', 'xls', 'json', 'xml'];
    
    if (!in_array($extension, $allowed)) {
        $error = "Unsupported file format. Allowed: " . implode(', ', $allowed);
    } else {
        // Create batch record
        $batchRef = 'BATCH_' . strtoupper(uniqid());
        $stmt = $db->prepare("
            INSERT INTO import_batches (
                organization_id, batch_reference, batch_name, original_filename, 
                source_format, status, uploaded_by
            ) VALUES (
                :org_id, :ref, :name, :filename, 
                :format, 'UPLOADED', :user_id
            )
        ");
        
        $stmt->execute([
            ':org_id' => $user['organization_id'],
            ':ref' => $batchRef,
            ':name' => $_POST['batch_name'] ?? $originalName,
            ':filename' => $originalName,
            ':format' => $extension === 'xlsx' ? 'EXCEL' : strtoupper($extension),
            ':user_id' => $user['id']
        ]);
        
        $batchId = $db->lastInsertId();
        
        // Save file to temp location
        $uploadDir = '/tmp/vouchmorph_uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $savedPath = $uploadDir . $batchId . '_' . $originalName;
        move_uploaded_file($tmpPath, $savedPath);
        
        // Redirect to preview
        header("Location: preview.php?batch_id=$batchId");
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Upload Payment File - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            color: #1e293b;
        }
        .container { max-width: 800px; margin: 60px auto; padding: 0 20px; }
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .card-header {
            padding: 30px 40px 0 40px;
        }
        .card-header h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .card-header p { color: #64748b; }
        .card-body { padding: 30px 40px 40px 40px; }
        .upload-area {
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            padding: 50px 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #f8fafc;
        }
        .upload-area:hover { border-color: #3b82f6; background: #eff6ff; }
        .upload-icon { font-size: 48px; margin-bottom: 16px; }
        .btn {
            padding: 12px 24px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
        }
        .btn-primary {
            background: #1e293b;
            color: white;
            width: 100%;
            margin-top: 20px;
        }
        .btn-primary:hover { background: #0f172a; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
        }
        .error { background: #fef2f2; color: #dc2626; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
        .supported-formats {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .format-badge {
            background: #e2e8f0;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="card-header">
            <h1>📁 New Bulk Payment</h1>
            <p>Upload your payment file — any format, any source</p>
        </div>
        <div class="card-body">
            <?php if (isset($error)): ?>
                <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Batch Name (optional)</label>
                    <input type="text" name="batch_name" placeholder="e.g., Pensioners April 2026">
                </div>
                
                <div class="upload-area" onclick="document.getElementById('file_input').click()">
                    <div class="upload-icon">📄</div>
                    <div style="font-weight: 500; margin-bottom: 8px;">Click to upload or drag and drop</div>
                    <div style="font-size: 12px; color: #64748b;">CSV, Excel, JSON, XML, Oracle export</div>
                    <input type="file" name="payment_file" id="file_input" style="display: none" accept=".csv,.xlsx,.xls,.json,.xml">
                </div>
                
                <div class="supported-formats">
                    <span class="format-badge">📊 Excel (.xlsx, .xls)</span>
                    <span class="format-badge">📈 CSV</span>
                    <span class="format-badge">🔷 JSON</span>
                    <span class="format-badge">📋 XML</span>
                    <span class="format-badge">🏛 Oracle Export</span>
                    <span class="format-badge">💼 SAP Payroll</span>
                </div>
                
                <button type="submit" class="btn btn-primary">Continue to Preview →</button>
            </form>
        </div>
    </div>
</div>
<script>
    document.getElementById('file_input').addEventListener('change', function(e) {
        if (e.target.files.length > 0) {
            e.target.closest('form').submit();
        }
    });
</script>
</body>
</html>
