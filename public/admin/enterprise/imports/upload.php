<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();

$stmt = $db->prepare("SELECT * FROM column_mapping_templates WHERE organization_id = :org_id ORDER BY template_name");
$stmt->execute([':org_id' => $orgId]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // FIXED: CSRF check before touching anything else
    requireCsrfToken($_POST['csrf_token'] ?? null);

    if (!isset($_FILES['payment_file']) || $_FILES['payment_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a valid file to upload';
    } else {
        $file = $_FILES['payment_file'];
        $originalName = $file['name'];
        $tmpPath = $file['tmp_name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $allowed = ['csv', 'xlsx', 'xls', 'json', 'xml'];
        if (!in_array($extension, $allowed)) {
            $error = 'Unsupported file format. Allowed: ' . implode(', ', $allowed);
        } else {
            $format = $extension === 'xlsx' ? 'EXCEL' : strtoupper($extension);
            $batchRef = 'BATCH_' . date('Ymd_His') . '_' . strtoupper(substr(uniqid(), -6));

            $stmt = $db->prepare("
                INSERT INTO import_batches (
                    organization_id, batch_reference, batch_name, original_filename,
                    source_format, status, uploaded_by, department_id, total_rows, total_amount, currency
                ) VALUES (
                    :org_id, :ref, :name, :filename, :format, 'UPLOADED', :user_id, :dept_id, 0, 0, 'BWP'
                )
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':ref' => $batchRef,
                ':name' => $_POST['batch_name'] ?: $originalName,
                ':filename' => $originalName,
                ':format' => $format,
                ':user_id' => $user['id'] ?? $user['user_id'] ?? null,
                ':dept_id' => getUserDepartmentScope(), // NULL for owner/auditor (org-wide), else their department
            ]);
            $batchId = $db->lastInsertId();

            // FIXED: 0750 instead of 0777 -- web server user only, not world-writable.
            // Beneficiary files (National IDs, phone numbers, amounts) sitting
            // world-writable in /tmp is a data protection finding in any audit.
            $uploadDir = '/tmp/vouchmorph_uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0750, true);
            $savedPath = $uploadDir . $batchId . '_' . $originalName;
            move_uploaded_file($tmpPath, $savedPath);

            $templateId = $_POST['template_id'] ?? null;
            if ($templateId) {
                $stmt = $db->prepare("SELECT column_mapping FROM column_mapping_templates WHERE id = :id AND organization_id = :org_id");
                $stmt->execute([':id' => $templateId, ':org_id' => $orgId]);
                $template = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($template) {
                    $stmt = $db->prepare("UPDATE import_batches SET import_mapping = :mapping WHERE id = :id");
                    $stmt->execute([':mapping' => $template['column_mapping'], ':id' => $batchId]);
                }
            }

            // FIXED: audit log entry -- this endpoint previously wrote nothing
            // to organization_audit_logs.
            try {
                $auditStmt = $db->prepare("
                    INSERT INTO organization_audit_logs (
                        organization_id, user_id, action, entity_type, entity_id,
                        old_values, new_values, ip_address, user_agent, created_at
                    ) VALUES (
                        :org_id, :user_id, 'BATCH_UPLOADED', 'import_batch', :entity_id,
                        NULL, :new_values, :ip, :ua, NOW()
                    )
                ");
                $auditStmt->execute([
                    ':org_id' => $orgId,
                    ':user_id' => $user['id'] ?? $user['user_id'] ?? null,
                    ':entity_id' => $batchId,
                    ':new_values' => json_encode([
                        'batch_reference' => $batchRef,
                        'original_filename' => $originalName,
                        'source_format' => $format,
                    ]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]);
            } catch (PDOException $e) {
                error_log("[upload.php] Failed to write audit log: " . $e->getMessage());
            }

            header("Location: preview.php?batch_id=$batchId");
            exit;
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Payment File - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #0f172a; }
        .app { display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background: #0f172a; color: #e2e8f0; position: fixed; height: 100vh; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid #1e293b; }
        .sidebar-header h2 { font-size: 20px; font-weight: 700; }
        .sidebar-header span { color: #fbbf24; }
        .sidebar-nav { padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #cbd5e1; text-decoration: none; }
        .nav-item:hover, .nav-item.active { background: #1e293b; color: white; }
        .main { flex: 1; margin-left: 280px; padding: 24px 32px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }
        .greeting h1 { font-size: 28px; font-weight: 700; }
        .container { max-width: 800px; }
        .card { background: white; border-radius: 16px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .upload-area { border: 2px dashed #cbd5e1; border-radius: 16px; padding: 50px 30px; text-align: center; cursor: pointer; transition: all 0.2s; background: #f8fafc; }
        .upload-area:hover { border-color: #3b82f6; background: #eff6ff; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-group input, .form-group select { width: 100%; padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 12px; font-size: 14px; }
        .btn { padding: 12px 28px; border-radius: 40px; font-weight: 600; cursor: pointer; border: none; font-size: 14px; }
        .btn-primary { background: #0f172a; color: white; width: 100%; margin-top: 20px; }
        .error { background: #fef2f2; color: #dc2626; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
        .supported-formats { display: flex; gap: 12px; justify-content: center; margin-top: 20px; flex-wrap: wrap; }
        .format-badge { background: #e2e8f0; padding: 6px 12px; border-radius: 20px; font-size: 12px; }
        .step-indicator { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; }
        .step { flex: 1; text-align: center; position: relative; }
        .step-number { width: 32px; height: 32px; background: #e2e8f0; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; margin-bottom: 8px; }
        .step.active .step-number { background: #0f172a; color: white; }
        .step-label { font-size: 12px; color: #64748b; }
        .step.active .step-label { color: #0f172a; font-weight: 500; }
    </style>
</head>
<body>
<div class="app">
    <div class="sidebar">
        <div class="sidebar-header"><h2>VouchMorph <span>Enterprise</span></h2></div>
        <div class="sidebar-nav">
            <a href="../index.php" class="nav-item">📊 Dashboard</a>
            <a href="upload.php" class="nav-item active">📁 New Payment</a>
            <a href="../batches/index.php" class="nav-item">📦 Batches</a>
            <a href="../beneficiaries/index.php" class="nav-item">👥 Beneficiaries</a>
            <a href="../templates/index.php" class="nav-item">📋 Templates</a>
            <a href="../reports/index.php" class="nav-item">📄 Reports</a>
            <a href="../settings/index.php" class="nav-item">⚙️ Settings</a>
            <hr style="margin: 20px 24px; border-color: #1e293b;">
            <a href="../logout.php" class="nav-item">🚪 Logout</a>
        </div>
    </div>

    <div class="main">
        <div class="top-bar"><div class="greeting"><h1>New Bulk Payment</h1></div></div>

        <div class="container">
            <div class="step-indicator">
                <div class="step active"><div class="step-number">1</div><div class="step-label">Upload</div></div>
                <div class="step"><div class="step-number">2</div><div class="step-label">Map</div></div>
                <div class="step"><div class="step-number">3</div><div class="step-label">Validate</div></div>
                <div class="step"><div class="step-number">4</div><div class="step-label">Execute</div></div>
            </div>

            <div class="card">
                <?php if ($error): ?><div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <!-- FIXED: CSRF token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <div class="form-group">
                        <label>Batch Name (optional)</label>
                        <input type="text" name="batch_name" placeholder="e.g., Pensioners April 2026">
                    </div>

                    <div class="form-group">
                        <label>Use Saved Template (optional)</label>
                        <select name="template_id">
                            <option value="">— No template, map manually —</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo $template['id']; ?>"><?php echo htmlspecialchars($template['template_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="upload-area" onclick="document.getElementById('file_input').click()">
                        <div style="font-size: 48px; margin-bottom: 16px;">📄</div>
                        <div style="font-weight: 500; margin-bottom: 8px;">Click to upload or drag and drop</div>
                        <div style="font-size: 12px; color: #64748b;">CSV, Excel, JSON, XML</div>
                        <input type="file" name="payment_file" id="file_input" style="display: none" accept=".csv,.xlsx,.xls,.json,.xml">
                    </div>

                    <div class="supported-formats">
                        <span class="format-badge">📊 Excel (.xlsx, .xls)</span>
                        <span class="format-badge">📈 CSV</span>
                        <span class="format-badge">🔷 JSON</span>
                        <span class="format-badge">📋 XML</span>
                    </div>

                    <button type="submit" class="btn btn-primary">Continue to Preview →</button>
                </form>
            </div>
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
