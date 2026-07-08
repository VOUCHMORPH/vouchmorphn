<?php
// upload.php - ENTERPRISE DISBURSEMENT UPLOAD
// MATCHES INDEX.PHP STYLE - Government Registry Aesthetic
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
                ':dept_id' => getUserDepartmentScope(),
            ]);
            $batchId = $db->lastInsertId();

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
$roleDisplay = strtoupper($user['role'] ?? 'USER');
$orgName = htmlspecialchars($user['organization_name'] ?? 'GOVERNMENT');
$fileRef = 'VM/' . date('Y') . '/' . date('md') . '-' . str_pad((string)(rand(1, 999)), 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · NEW DISBURSEMENT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper:        #EEF1EF;
            --panel:        #FFFFFF;
            --ink-900:      #0F2138;
            --ink-700:      #1D3557;
            --ink-500:      #4A5A6E;
            --ink-300:      #8A96A3;
            --line:         #D3DAD6;
            --line-strong:  #AEB8B2;
            --brass:        #8A6D3B;
            --brass-tint:   #F4EFE3;
            --seal-red:     #7A2118;
            --amber:        #8A5A0B;
            --ledger-green: #24513A;
            --green-tint:   #E5EEE7;
            --blue-tint:    #E7EEF4;

            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }

        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            font-size: 13px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--line-strong); }
        a { color: inherit; text-decoration: none; }
        button { font-family: inherit; cursor: pointer; }

        .app { display: flex; min-height: 100vh; }

        /* ============================================================
           SIDEBAR - MATCHES INDEX
           ============================================================ */
        .sidebar {
            width: 220px;
            background: var(--ink-900);
            color: #dbe1ea;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow: hidden;
            flex-shrink: 0;
            border-right: 2px solid var(--brass);
        }

        .sidebar-header { padding: 16px 18px 12px; border-bottom: 1px solid rgba(255,255,255,0.05); flex-shrink: 0; }
        .sidebar-header .brand { font-family: var(--f-mono); font-weight: 700; font-size: 12px; letter-spacing: 2px; color: var(--brass); }
        .sidebar-header .sub { font-size: 7px; font-weight: 500; color: rgba(255,255,255,0.2); text-transform: uppercase; letter-spacing: 1.5px; }
        .sidebar-header .org { font-size: 9px; color: rgba(255,255,255,0.35); margin-top: 4px; padding-top: 4px; border-top: 1px solid rgba(255,255,255,0.05); }

        .sidebar-nav { padding: 8px 10px; overflow-y: auto; flex: 1; }
        .sidebar-nav .nav-group { font-size: 7px; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255,255,255,0.12); padding: 10px 8px 3px; font-weight: 600; }

        .nav-item {
            display: flex; align-items: center; gap: 8px; padding: 5px 8px;
            color: rgba(255,255,255,0.35); text-decoration: none; font-size: 11px;
            font-weight: 500; transition: all 0.15s ease; border-left: 2px solid transparent;
        }
        .nav-item:hover { background: rgba(255,255,255,0.04); color: rgba(255,255,255,0.7); }
        .nav-item.active { background: rgba(138,109,59,0.08); color: var(--brass); border-left-color: var(--brass); }
        .nav-item .icon { width: 14px; text-align: center; font-size: 11px; opacity: 0.5; }
        .nav-item.active .icon { opacity: 1; }

        .sidebar-footer { padding: 8px 12px; border-top: 1px solid rgba(255,255,255,0.05); flex-shrink: 0; }
        .sidebar-footer .user-row { display: flex; align-items: center; gap: 8px; }
        .sidebar-footer .avatar {
            width: 22px; height: 22px; background: var(--brass); display: flex; align-items: center;
            justify-content: center; font-weight: 700; font-size: 9px; color: var(--ink-900);
            font-family: var(--f-mono); flex-shrink: 0;
        }
        .sidebar-footer .user-info { flex: 1; min-width: 0; }
        .sidebar-footer .user-info .name { font-size: 10px; font-weight: 600; color: rgba(255,255,255,0.7); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-footer .user-info .meta { font-size: 7px; color: rgba(255,255,255,0.2); text-transform: uppercase; letter-spacing: 0.3px; }
        .sidebar-footer .logout-link { color: rgba(255,255,255,0.15); font-size: 12px; transition: all 0.15s ease; }
        .sidebar-footer .logout-link:hover { color: var(--brass); }

        /* ============================================================
           MAIN CONTENT
           ============================================================ */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: var(--paper);
        }

        /* ============================================================
           MASTHEAD - MATCHES INDEX
           ============================================================ */
        .masthead {
            background: var(--ink-900);
            color: white;
            padding: 12px 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            border-bottom: 3px solid var(--brass);
            min-height: 72px;
            flex-shrink: 0;
        }
        .masthead .center {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            flex: 1;
        }
        .masthead h1 {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            text-align: center;
        }
        .masthead .file-ref {
            font-family: var(--f-mono);
            font-size: 10px;
            color: rgba(255,255,255,0.3);
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            text-align: center;
        }
        .masthead .user-menu {
            position: absolute;
            right: 32px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .masthead .user-menu .role-pill {
            font-family: var(--f-cond);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: var(--brass);
            border: 1px solid var(--brass);
            padding: 2px 10px;
            text-transform: uppercase;
        }
        .masthead .user-menu .status-dot {
            display: inline-block;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #5FAE7E;
            margin-right: 3px;
        }
        .masthead .user-menu .time {
            font-family: var(--f-mono);
            font-size: 9px;
            color: rgba(255,255,255,0.35);
        }
        .masthead .user-menu .menu-divider {
            width: 1px;
            height: 18px;
            background: rgba(255,255,255,0.06);
        }
        .masthead .user-menu .menu-link {
            color: rgba(255,255,255,0.3);
            text-decoration: none;
            font-family: var(--f-cond);
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: all 0.15s ease;
            padding: 3px 6px;
            border: 1px solid transparent;
        }
        .masthead .user-menu .menu-link:hover {
            color: var(--brass);
            border-color: var(--brass);
        }
        .masthead .user-menu .menu-link.logout-link:hover {
            color: var(--seal-red);
            border-color: var(--seal-red);
        }
        .masthead .user-menu .menu-link .icon { margin-right: 3px; }

        /* ============================================================
           DASHBOARD CONTENT
           ============================================================ */
        .dashboard-content {
            flex: 1;
            padding: 24px 32px 40px;
            max-width: 980px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
        }

        /* ============================================================
           STEP INDICATOR
           ============================================================ */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            padding: 0 20px;
            flex-shrink: 0;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step::after {
            content: '';
            position: absolute;
            top: 14px;
            left: 55%;
            width: 90%;
            height: 2px;
            background: var(--line);
            z-index: 0;
        }
        .step:last-child::after { display: none; }
        .step .step-number {
            width: 28px;
            height: 28px;
            background: var(--line);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 11px;
            font-family: var(--f-mono);
            color: var(--ink-300);
            position: relative;
            z-index: 1;
            border: 2px solid var(--line);
        }
        .step.active .step-number {
            background: var(--brass);
            border-color: var(--brass);
            color: white;
        }
        .step.done .step-number {
            background: var(--ledger-green);
            border-color: var(--ledger-green);
            color: white;
        }
        .step .step-label {
            font-size: 9px;
            color: var(--ink-300);
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            font-family: var(--f-cond);
        }
        .step.active .step-label { color: var(--ink-900); }
        .step.done .step-label { color: var(--ledger-green); }

        /* ============================================================
           PANEL - MATCHES INDEX
           ============================================================ */
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            overflow: hidden;
            position: relative;
            flex: 1;
        }
        .panel::before {
            content: "";
            position: absolute;
            top: -1px;
            left: -1px;
            width: 9px;
            height: 9px;
            border-top: 2px solid var(--brass);
            border-left: 2px solid var(--brass);
            pointer-events: none;
        }
        .panel::after {
            content: "";
            position: absolute;
            bottom: -1px;
            right: -1px;
            width: 9px;
            height: 9px;
            border-bottom: 2px solid var(--brass);
            border-right: 2px solid var(--brass);
            pointer-events: none;
        }

        .panel-header {
            padding: 10px 20px;
            background: var(--brass-tint);
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--ink-500);
            font-family: var(--f-cond);
            flex-shrink: 0;
        }
        .panel-header .section-mark { color: var(--brass); margin-right: 5px; }

        .panel-body { padding: 24px 28px; }

        /* ============================================================
           FORM ELEMENTS
           ============================================================ */
        .form-group { margin-bottom: 20px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--ink-500);
            font-family: var(--f-cond);
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--line);
            font-size: 13px;
            font-family: var(--f-body);
            background: #fdfcf9;
            transition: border-color .15s, background .15s;
            color: var(--ink-900);
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--brass);
            background: #fff;
        }
        .form-group input::placeholder { color: var(--ink-300); opacity: 0.7; }

        /* ============================================================
           UPLOAD AREA
           ============================================================ */
        .upload-area {
            border: 2px dashed var(--line);
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            background: var(--brass-tint);
        }
        .upload-area:hover {
            border-color: var(--brass);
            background: #f8f5ee;
        }
        .upload-area .icon {
            font-size: 36px;
            display: block;
            margin-bottom: 12px;
            color: var(--brass);
        }
        .upload-area .title {
            font-weight: 600;
            font-size: 14px;
            color: var(--ink-700);
            margin-bottom: 4px;
        }
        .upload-area .hint {
            font-size: 11px;
            color: var(--ink-300);
        }

        .supported-formats {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        .format-badge {
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 4px 12px;
            font-size: 9px;
            font-weight: 600;
            color: var(--ink-500);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-family: var(--f-cond);
        }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn {
            padding: 11px 24px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-family: var(--f-cond);
            border: 1.5px solid transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-primary {
            background: var(--ink-900);
            color: white;
            border-color: var(--ink-900);
            width: 100%;
            margin-top: 20px;
        }
        .btn-primary:hover {
            background: var(--brass);
            border-color: var(--brass);
            color: var(--ink-900);
        }

        .error {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fbeceb;
            color: var(--seal-red);
            padding: 10px 14px;
            margin-bottom: 18px;
            font-size: 12px;
            border-left: 3px solid var(--seal-red);
            font-weight: 500;
        }
        .error .icon { font-size: 16px; flex-shrink: 0; }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ink-500);
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            font-family: var(--f-cond);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid transparent;
            transition: all 0.15s ease;
            margin-bottom: 16px;
        }
        .back-link:hover {
            color: var(--brass);
            border-bottom-color: var(--brass);
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 992px) {
            .sidebar { width: 60px; }
            .sidebar-header h2, .sidebar-header .org, .sidebar-nav .nav-group,
            .nav-item span:not(.icon), .sidebar-footer .user-info { display: none; }
            .sidebar-header .brand { font-size: 9px; }
            .nav-item { justify-content: center; }
            .sidebar-footer .user-row { justify-content: center; }
            .main { margin-left: 60px; }
            .masthead { padding: 10px 16px; min-height: 60px; }
            .masthead h1 { font-size: 13px; }
            .masthead .file-ref { font-size: 8px; }
            .masthead .user-menu { right: 12px; gap: 8px; }
            .masthead .user-menu .time { display: none; }
            .dashboard-content { padding: 16px; }
            .panel-body { padding: 16px; }
            .step-indicator { padding: 0 8px; }
            .step .step-label { font-size: 7px; }
        }

        @media (max-width: 640px) {
            .masthead { flex-direction: column; min-height: auto; padding: 10px 12px; gap: 4px; }
            .masthead .user-menu { position: static; transform: none; flex-wrap: wrap; justify-content: center; }
            .masthead h1 { font-size: 12px; }
            .dashboard-content { padding: 12px; }
            .panel-body { padding: 12px; }
            .upload-area { padding: 24px 12px; }
            .step-indicator { flex-wrap: wrap; gap: 8px; }
            .step { flex: 0 0 45%; }
            .step::after { display: none; }
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #131C24;
                --panel: #1B2733;
                --line: #2C3A45;
                --line-strong: #3C4C58;
                --ink-900: #ECEFF2;
                --ink-700: #C9D2D9;
                --ink-500: #93A2AC;
                --ink-300: #6B7A85;
                --brass-tint: #22303A;
                --blue-tint: #1D2A38;
                --green-tint: #17261D;
            }
            .form-group input,
            .form-group select {
                background: #1B2733;
                border-color: #2C3A45;
                color: #ECEFF2;
            }
            .form-group input:focus,
            .form-group select:focus {
                border-color: var(--brass);
                background: #22303A;
            }
            .form-group input::placeholder { color: #6B7A85; }
            .upload-area {
                background: #1B2733;
                border-color: #2C3A45;
            }
            .upload-area:hover {
                border-color: var(--brass);
                background: #22303A;
            }
            .upload-area .title { color: #C9D2D9; }
            .format-badge {
                background: #1B2733;
                border-color: #2C3A45;
                color: #93A2AC;
            }
            .error {
                background: #2A1A1A;
                border-color: var(--seal-red);
                color: #E8A0A0;
            }
            .btn-primary {
                background: #2C3A45;
                border-color: #2C3A45;
                color: #ECEFF2;
            }
            .btn-primary:hover {
                background: var(--brass);
                border-color: var(--brass);
                color: var(--ink-900);
            }
            .step .step-number { background: #2C3A45; }
            .step.active .step-number { background: var(--brass); }
        }
    </style>
</head>
<body>
    <div class="app">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="brand">VOUCHMORPH</div>
                <div class="sub">Enterprise</div>
                <div class="org"><?php echo $orgName; ?></div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-group">Main</div>
                <a href="../index.php" class="nav-item"><span class="icon">◈</span><span>Dashboard</span></a>
                <a href="upload.php" class="nav-item active"><span class="icon">▣</span><span>New Disbursement</span></a>
                <a href="../batches/index.php" class="nav-item"><span class="icon">▦</span><span>Batches</span></a>
                <a href="../beneficiaries/index.php" class="nav-item"><span class="icon">◈</span><span>Beneficiaries</span></a>
                <div class="nav-group">Management</div>
                <a href="../templates/index.php" class="nav-item"><span class="icon">▤</span><span>Templates</span></a>
                <a href="../reports/index.php" class="nav-item"><span class="icon">▥</span><span>Reports</span></a>
                <a href="../settings/index.php" class="nav-item"><span class="icon">◆</span><span>Settings</span></a>
            </nav>
            <div class="sidebar-footer">
                <div class="user-row">
                    <div class="avatar"><?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 1)); ?></div>
                    <div class="user-info">
                        <div class="name"><?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></div>
                        <div class="meta"><?php echo $roleDisplay; ?></div>
                    </div>
                    <a href="../logout.php" class="logout-link">↗</a>
                </div>
            </div>
        </div>

        <!-- Main -->
        <div class="main">
            <!-- Masthead -->
            <div class="masthead">
                <div class="center">
                    <h1>VouchMorph — Sovereign Disbursement Platform</h1>
                    <div class="file-ref">FILE NO. <?php echo htmlspecialchars($fileRef); ?> · <?php echo strtoupper(date('d M Y')); ?></div>
                </div>
                <div class="user-menu">
                    <span class="role-pill"><?php echo $roleDisplay; ?></span>
                    <span class="time"><span class="status-dot"></span><?php echo date('H:i'); ?> UTC+2</span>
                    <span class="menu-divider"></span>
                    <a href="../settings/index.php" class="menu-link"><span class="icon">⚙</span>Settings</a>
                    <a href="../logout.php" class="menu-link logout-link"><span class="icon">↗</span>Sign Out</a>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Back link -->
                <a href="../index.php" class="back-link">← Return to Dashboard</a>

                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active">
                        <div class="step-number">1</div>
                        <div class="step-label">Upload</div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-label">Map</div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-label">Validate</div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-label">Execute</div>
                    </div>
                </div>

                <!-- Upload Panel -->
                <div class="panel">
                    <div class="panel-header">
                        <span><span class="section-mark">§</span>New Disbursement Upload</span>
                        <span>STEP 1 OF 4</span>
                    </div>
                    <div class="panel-body">
                        <?php if ($error): ?>
                        <div class="error">
                            <span class="icon">⚠</span>
                            <span><?php echo htmlspecialchars($error); ?></span>
                        </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
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
                                <span class="icon">📄</span>
                                <div class="title">Click to upload or drag and drop</div>
                                <div class="hint">CSV, Excel, JSON, XML</div>
                                <input type="file" name="payment_file" id="file_input" style="display: none" accept=".csv,.xlsx,.xls,.json,.xml">
                            </div>

                            <div class="supported-formats">
                                <span class="format-badge">Excel (.xlsx, .xls)</span>
                                <span class="format-badge">CSV</span>
                                <span class="format-badge">JSON</span>
                                <span class="format-badge">XML</span>
                            </div>

                            <button type="submit" class="btn btn-primary">Continue to Preview →</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('file_input').addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                var fileName = e.target.files[0].name;
                var uploadArea = this.closest('.upload-area');
                uploadArea.querySelector('.title').textContent = '📄 ' + fileName;
                uploadArea.querySelector('.hint').textContent = 'File selected · Click to change';
            }
        });
    </script>
</body>
</html>
