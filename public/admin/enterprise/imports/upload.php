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

// ============================================================
// ACTION BUTTONS - SAME AS INDEX
// ============================================================
$actions = [];
if ($config['show_actions']) {
    $actions[] = ['label' => 'DISBURSE FUNDS', 'href' => 'upload.php', 'mark' => '§1', 'badge' => null, 'active' => true];
}
if ($config['show_all_batches']) {
    $actions[] = ['label' => 'BATCHES', 'href' => '../batches/index.php', 'mark' => '§2', 'badge' => $stats['total_batches'] > 0 ? $stats['total_batches'] : null];
}
$actions[] = ['label' => 'PENDING APPROVALS', 'href' => '../batches/index.php?filter=pending', 'mark' => '§3', 'badge' => $stats['pending'] > 0 ? $stats['pending'] : null];
if ($config['show_beneficiaries']) {
    $actions[] = ['label' => 'BENEFICIARIES', 'href' => '../beneficiaries/index.php', 'mark' => '§4', 'badge' => $stats['beneficiaries'] > 0 ? $stats['beneficiaries'] : null];
}
if ($config['show_governance']) {
    $actions[] = ['label' => 'AUDIT TRAIL', 'href' => '../reports/audit_trail.php', 'mark' => '§5', 'badge' => null];
}
$actions[] = ['label' => 'REPORTS', 'href' => '../reports/index.php', 'mark' => '§6', 'badge' => null];

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

        /* ============================================================
           MASTHEAD - PERFECTLY CENTERED TITLE, USER MENU ON RIGHT
           ============================================================ */
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

        /* User menu - positioned absolutely on the right */
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

        /* ============================================================
           VOUCHMORPH™ WATERMARK - ROTATED 90° ON LEFT SIDE
           ============================================================ */
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

        /* ============================================================
           CENTRAL LAYOUT
           ============================================================ */
        .stage {
            flex: 1; width: 100%; display: flex; flex-direction: column; align-items: center;
            padding: 140px 20px 60px;
            position: relative;
            z-index: 1;
        }
        .stage-inner { width: 100%; max-width: 980px; display: flex; flex-direction: column; align-items: center; gap: 34px; }

        .welcome { text-align: center; margin-bottom: 12px; }
        .welcome .eyebrow { justify-content: center; }
        .welcome h2 { font-size: 20px; font-weight: 700; letter-spacing: 0.01em; text-transform: uppercase; margin-top: 6px; }
        .welcome p { font-family: var(--f-cond); font-size: 11px; color: var(--ink-500); margin-top: 4px; letter-spacing: 0.02em; text-transform: uppercase; }

        /* ============================================================
           ACTION LAUNCHER
           ============================================================ */
        .launcher { width: 100%; }
        .launcher-grid {
            display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; margin-top: 28px;
        }
        .action-btn {
            position: relative;
            width: 190px;
            padding: 22px 16px 16px;
            background: var(--panel);
            border: 1.5px solid var(--ink-900);
            text-decoration: none;
            color: var(--ink-900);
            display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px;
            transition: background 0.12s ease, transform 0.12s ease;
        }
        .action-btn:hover { background: var(--brass-tint); transform: translateY(-2px); }
        .action-btn .mark { font-family: var(--f-mono); font-size: 10px; color: var(--brass); font-weight: 700; letter-spacing: 0.08em; }
        .action-btn .label { font-family: var(--f-cond); font-weight: 700; font-size: 13px; letter-spacing: 0.06em; text-transform: uppercase; }
        .action-btn .badge {
            position: absolute; top: -9px; right: -9px;
            background: var(--seal-red); color: white; font-family: var(--f-mono); font-weight: 700;
            font-size: 10px; min-width: 20px; height: 20px; display: flex; align-items: center; justify-content: center;
            padding: 0 5px; border: 1.5px solid var(--paper);
        }
        .action-btn.active {
            background: var(--brass-tint);
            border-color: var(--brass);
        }
        .action-btn.active .mark {
            color: var(--brass);
        }

        /* ============================================================
           UPLOAD FORM - INSIDE THE SAME STAGE INNER
           ============================================================ */
        .upload-form {
            width: 100%;
        }
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
        }

        @media (max-width: 640px) {
            .masthead h1 { font-size: 14px; }
            .masthead .file-ref { font-size: 9px; }
            .masthead .user-menu { gap: 8px; }
            .masthead .user-menu .role-pill { font-size: 8px; padding: 1px 6px; }
            .masthead .user-menu .time { font-size: 8px; }
            .masthead .user-menu .menu-link { font-size: 8px; padding: 2px 6px; }
            .stage { padding: 60px 14px 30px; }
            .action-btn { width: 150px; padding: 18px 12px 14px; }
            .vouchmorph-watermark { display: none; }
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #131C24; --panel: #1B2733; --line: #2C3A45; --line-strong: #3C4C58;
                --ink-900: #ECEFF2; --ink-700: #C9D2D9; --ink-500: #93A2AC; --ink-300: #6B7A85;
                --brass-tint: #22303A; --blue-tint: #1D2A38; --green-tint: #17261D;
            }
            .action-btn { border-color: var(--ink-900); }
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
        }
    </style>
</head>
<body>
    <!-- VouchMorph™ Watermark -->
    <div class="vouchmorph-watermark">VouchMorph<span class="tm">™</span></div>

    <!-- Masthead - IDENTICAL TO INDEX -->
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

    <!-- Central stage - IDENTICAL TO INDEX -->
    <div class="stage">
        <div class="stage-inner">

            <!-- Welcome - IDENTICAL TO INDEX -->
            <div class="welcome">
                <div class="eyebrow"><span class="section-mark">§</span>Registry Access</div>
                <h2>WELCOME, <?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 24)); ?></h2>
                <p>SELECT A WORKING PAGE FOR YOUR ROLE<?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?></p>
            </div>

            <!-- Role-based launcher - IDENTICAL TO INDEX -->
            <div class="launcher">
                <div class="launcher-grid">
                    <?php foreach ($actions as $action): ?>
                    <a href="<?php echo htmlspecialchars($action['href']); ?>" class="action-btn <?php echo isset($action['active']) && $action['active'] ? 'active' : ''; ?>">
                        <?php if ($action['badge'] !== null): ?>
                        <span class="badge"><?php echo (int)$action['badge']; ?></span>
                        <?php endif; ?>
                        <span class="mark"><?php echo htmlspecialchars($action['mark']); ?></span>
                        <span class="label"><?php echo htmlspecialchars($action['label']); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ==========================================================
                 UPLOAD FORM - The only thing different from index
                 ========================================================== -->
            <div class="doc-panel" style="width:100%; padding:28px 32px;">
                <div class="eyebrow" style="margin-bottom:16px;">
                    <span class="section-mark">§</span>New Disbursement Upload
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

    <!-- Footer - IDENTICAL TO INDEX -->
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
