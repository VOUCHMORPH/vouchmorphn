<?php
// upload.php - ENTERPRISE DISBURSEMENT UPLOAD
// MATCHES INDEX.PHP EXACTLY - Same layout, same components
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

// ============================================================
// FIX: Check if user exists in organization_users before audit log
// ============================================================
$userExistsInOrg = false;
$userId = $user['id'] ?? $user['user_id'] ?? null;

if ($userId) {
    $userCheck = $db->prepare("
        SELECT id FROM organization_users 
        WHERE user_id = :user_id AND organization_id = :org_id AND is_active = true
    ");
    $userCheck->execute([
        ':user_id' => $userId,
        ':org_id' => $orgId
    ]);
    $orgUser = $userCheck->fetch(PDO::FETCH_ASSOC);
    $userExistsInOrg = !empty($orgUser);
}

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
                ':user_id' => $userId,
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

            // ============================================================
            // FIX: Only write audit log if user exists in organization_users
            // ============================================================
            if ($userExistsInOrg) {
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
                        ':user_id' => $userId,
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
            } else {
                error_log("[upload.php] Skipping audit log - user_id {$userId} not found in organization_users");
            }

            header("Location: preview.php?batch_id=$batchId");
            exit;
        }
    }
}

$csrfToken = generateCsrfToken();

// Get stats for consistency with index.php
$stats = ['total_batches' => 0, 'pending' => 0, 'beneficiaries' => 0];
try {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM import_batches WHERE organization_id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    $stats['total_batches'] = $stmt->fetchColumn() ?: 0;

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM import_batches WHERE organization_id = :org_id AND status = 'READY_FOR_APPROVAL'");
    $stmt->execute([':org_id' => $orgId]);
    $stats['pending'] = $stmt->fetchColumn() ?: 0;

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM organization_beneficiaries WHERE organization_id = :org_id AND is_active = true");
    $stmt->execute([':org_id' => $orgId]);
    $stats['beneficiaries'] = $stmt->fetchColumn() ?: 0;
} catch (PDOException $e) {
    // Silent fail
}

$config = [
    'show_actions' => in_array($user['role'] ?? '', ['owner', 'program_officer', 'department_head']),
    'show_beneficiaries' => in_array($user['role'] ?? '', ['owner', 'auditor', 'program_officer', 'beneficiary_registrar', 'department_head', 'viewer']),
    'show_all_batches' => !in_array($user['role'] ?? '', ['beneficiary_registrar']),
    'show_governance' => in_array($user['role'] ?? '', ['owner', 'auditor', 'department_head']),
    'show_settings' => in_array($user['role'] ?? '', ['owner']),
];

$departmentName = '';
$departmentId = $user['department_id'] ?? null;
if ($departmentId) {
    $stmt = $db->prepare("SELECT name FROM departments WHERE id = :id AND organization_id = :org_id");
    $stmt->execute([':id' => $departmentId, ':org_id' => $orgId]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    $departmentName = $dept['name'] ?? '';
}

$roleDisplay = strtoupper($user['role'] ?? 'USER');
$orgName = htmlspecialchars($user['organization_name'] ?? 'ORGANIZATIONAL');
$fileRef = 'VM/' . date('Y') . '/' . date('md') . '-' . str_pad((string)($stats['pending'] + 1), 3, '0', STR_PAD_LEFT);

function formatCurrency($amount) {
    return 'BWP ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · MULTI-ASSET DISBURSEMENT REGISTRY</title>
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
        a { color: inherit; }
        button { font-family: inherit; cursor: pointer; }

        .doc-panel { position: relative; background: var(--panel); border: 1px solid var(--line); }
        .doc-panel::before, .doc-panel::after { content: ""; position: absolute; width: 9px; height: 9px; pointer-events: none; }
        .doc-panel::before { top: -1px; left: -1px; border-top: 2px solid var(--brass); border-left: 2px solid var(--brass); }
        .doc-panel::after  { bottom: -1px; right: -1px; border-bottom: 2px solid var(--brass); border-right: 2px solid var(--brass); }

        .stamp {
            display: inline-block; padding: 2px 8px; border: 1.5px solid currentColor;
            transform: rotate(-2.5deg); font-family: var(--f-mono); font-size: 9px; font-weight: 600;
            letter-spacing: 0.09em; text-transform: uppercase; white-space: nowrap;
        }
        .stamp.completed  { color: var(--ledger-green); }
        .stamp.processing { color: var(--ink-700); }
        .stamp.pending     { color: var(--amber); }
        .stamp.failed      { color: var(--seal-red); }
        .stamp.draft       { color: var(--ink-300); }

        .eyebrow { font-family: var(--f-cond); font-weight: 700; font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--ink-500); }
        .section-mark { color: var(--brass); font-weight: 700; margin-right: 5px; }

        /* Masthead */
        .masthead {
            background: var(--ink-900);
            color: white;
            padding: 14px 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            border-bottom: 3px solid var(--brass);
            min-height: 80px;
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
            font-size: 19px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            text-align: center;
        }
        .masthead .file-ref {
            font-family: var(--f-mono);
            font-size: 11px;
            color: rgba(255,255,255,0.35);
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
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: var(--brass);
            border: 1px solid var(--brass);
            padding: 2px 10px;
            text-transform: uppercase;
        }
        .masthead .user-menu .status-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #5FAE7E;
            margin-right: 4px;
        }
        .masthead .user-menu .time {
            font-family: var(--f-mono);
            font-size: 10px;
            color: rgba(255,255,255,0.4);
        }
        .masthead .user-menu .menu-divider {
            width: 1px;
            height: 20px;
            background: rgba(255,255,255,0.08);
        }
        .masthead .user-menu .menu-link {
            color: rgba(255,255,255,0.4);
            text-decoration: none;
            font-family: var(--f-cond);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: var(--transition);
            padding: 4px 8px;
            border: 1px solid transparent;
        }
        .masthead .user-menu .menu-link:hover {
            color: var(--brass);
            border-color: var(--brass);
        }
        .masthead .user-menu .menu-link.logout-link {
            color: rgba(255,255,255,0.25);
        }
        .masthead .user-menu .menu-link.logout-link:hover {
            color: var(--seal-red);
            border-color: var(--seal-red);
        }
        .masthead .user-menu .menu-link .icon {
            margin-right: 4px;
        }

        /* VouchMorph™ Watermark */
        .vouchmorph-watermark {
            position: fixed;
            left: 8px;
            top: 50%;
            transform: translateY(-50%) rotate(-90deg);
            font-family: var(--f-mono);
            font-size: 11px;
            letter-spacing: 0.25em;
            color: rgba(138, 109, 59, 0.12);
            font-weight: 700;
            text-transform: uppercase;
            user-select: none;
            pointer-events: none;
            white-space: nowrap;
            z-index: 0;
        }
        .vouchmorph-watermark .tm {
            font-size: 8px;
            vertical-align: super;
            letter-spacing: 0;
        }

        /* Central layout */
        .stage {
            flex: 1; width: 100%; display: flex; flex-direction: column; align-items: center;
            padding: 120px 20px 60px;
            position: relative;
            z-index: 1;
        }
        .stage-inner { width: 100%; max-width: 980px; display: flex; flex-direction: column; align-items: center; gap: 28px; }

        .welcome { text-align: center; margin-bottom: 8px; }
        .welcome .eyebrow { justify-content: center; }
        .welcome h2 { font-size: 20px; font-weight: 700; letter-spacing: 0.01em; text-transform: uppercase; margin-top: 6px; }
        .welcome p { font-family: var(--f-cond); font-size: 11px; color: var(--ink-500); margin-top: 4px; letter-spacing: 0.02em; text-transform: uppercase; }

        /* Step indicator */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            max-width: 700px;
            margin: 0 auto;
            padding: 0 20px;
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

        /* Upload form */
        .upload-form { width: 100%; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 6px;
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
        .upload-area .icon { font-size: 36px; display: block; margin-bottom: 12px; color: var(--brass); }
        .upload-area .title { font-weight: 600; font-size: 14px; color: var(--ink-700); margin-bottom: 4px; }
        .upload-area .hint { font-size: 11px; color: var(--ink-300); }

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

        .btn {
            padding: 13px 24px;
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
            width: 100%;
            margin-top: 20px;
        }
        .btn-primary {
            background: var(--ink-900);
            color: white;
            border-color: var(--ink-900);
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

        .empty-state { text-align: center; padding: 30px 12px; color: var(--ink-300); }
        .empty-state .mark { font-family: var(--f-mono); font-size: 20px; display: block; margin-bottom: 8px; color: var(--brass); }
        .empty-state p { font-family: var(--f-cond); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.04em; }
        .empty-state a { color: var(--ink-700); font-weight: 700; text-decoration: none; border-bottom: 1px solid var(--brass); }

        .page-footer { padding: 16px 0 26px; text-align: center; border-top: 1px solid var(--line); width: 100%; }
        .page-footer .notice { font-family: var(--f-cond); font-size: 9.5px; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; color: var(--ink-300); }
        .page-footer .role-line { font-family: var(--f-mono); font-size: 9px; color: var(--ink-300); margin-top: 4px; text-transform: uppercase; }

        @media (max-width: 992px) {
            .masthead { padding: 12px 16px; flex-direction: column; min-height: auto; gap: 6px; }
            .masthead .user-menu {
                position: static;
                transform: none;
                justify-content: center;
                flex-wrap: wrap;
            }
            .vouchmorph-watermark { display: none; }
            .stage { padding: 80px 16px 40px; }
            .upload-area { padding: 24px 12px; }
            .step-indicator { padding: 0 8px; }
            .step .step-label { font-size: 7px; }
        }

        @media (max-width: 640px) {
            .masthead h1 { font-size: 14px; }
            .masthead .file-ref { font-size: 9px; }
            .masthead .user-menu { gap: 8px; }
            .masthead .user-menu .role-pill { font-size: 8px; padding: 1px 6px; }
            .masthead .user-menu .time { font-size: 8px; }
            .masthead .user-menu .menu-link { font-size: 8px; padding: 2px 6px; }
            .stage { padding: 60px 14px 30px; }
            .vouchmorph-watermark { display: none; }
            .step-indicator { flex-wrap: wrap; gap: 8px; }
            .step { flex: 0 0 45%; }
            .step::after { display: none; }
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #131C24; --panel: #1B2733; --line: #2C3A45; --line-strong: #3C4C58;
                --ink-900: #ECEFF2; --ink-700: #C9D2D9; --ink-500: #93A2AC; --ink-300: #6B7A85;
                --brass-tint: #22303A; --blue-tint: #1D2A38; --green-tint: #17261D;
            }
            .vouchmorph-watermark { color: rgba(201, 151, 42, 0.08); }
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
            .step .step-number { background: #2C3A45; border-color: #2C3A45; }
            .step.active .step-number { background: var(--brass); border-color: var(--brass); }
            .step .step-label { color: #6B7A85; }
            .step.active .step-label { color: #ECEFF2; }
        }
    </style>
</head>
<body>
    <div class="vouchmorph-watermark">VouchMorph<span class="tm">™</span></div>

    <!-- Masthead -->
    <div class="masthead">
        <div class="center">
            <h1><?php echo $orgName; ?> — National Disbursement</h1>
            <div class="file-ref">FILE NO. <?php echo htmlspecialchars($fileRef); ?> · <?php echo strtoupper(date('d M Y')); ?></div>
        </div>
        <div class="user-menu">
            <span class="role-pill"><?php echo $roleDisplay; ?></span>
            <span class="time"><span class="status-dot"></span><?php echo date('H:i'); ?> UTC+2</span>
            <span class="menu-divider"></span>
            <?php if ($config['show_settings']): ?>
            <a href="../settings/index.php" class="menu-link">
                <span class="icon">⚙</span> Settings
            </a>
            <?php endif; ?>
            <a href="../logout.php" class="menu-link logout-link">
                <span class="icon">↗</span> Sign Out
            </a>
        </div>
    </div>

    <!-- Central stage -->
    <div class="stage">
        <div class="stage-inner">

            <!-- Welcome -->
            <div class="welcome">
                <div class="eyebrow"><span class="section-mark">§</span>Registry Access</div>
                <h2>WELCOME, <?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 24)); ?></h2>
                <p>UPLOAD A NEW DISBURSEMENT BATCH<?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?></p>
            </div>

            <!-- Back Link -->
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

            <!-- Upload Form -->
            <div class="doc-panel" style="width:100%; padding:28px 32px;">
                <div class="eyebrow" style="margin-bottom:16px;">
                    <span class="section-mark">§</span>New Disbursement Upload <span style="float:right; color:var(--ink-300); font-weight:400;">STEP 1 OF 4</span>
                </div>

                <?php if ($error): ?>
                <div class="error">
                    <span class="icon">⚠</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="upload-form">
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

    <!-- Footer -->
    <footer class="page-footer">
        <div class="notice">SECURE ENTERPRISE MULTI ASSET PAYMENT · DISTRIBUTION RESTRICTED · ISO 27001 · © <?php echo date('Y'); ?> VOUCHMORPH</div>
        <div class="role-line"><?php echo $roleDisplay; ?><?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?> · <?php echo htmlspecialchars($fileRef); ?></div>
    </footer>

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
