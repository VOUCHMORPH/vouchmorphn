<?php
// batches/index.php - ENTERPRISE DISBURSEMENT BATCHES
// MATCHES INDEX.PHP AND UPLOAD.PHP STYLE
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();

// Get stats for consistency
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

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM import_batches WHERE organization_id = :org_id";
$params = [':org_id' => $orgId];

if ($status !== 'all') {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

if ($search) {
    $sql .= " AND (batch_reference ILIKE :search OR batch_name ILIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status counts for filters
$stmt = $db->prepare("
    SELECT status, COUNT(*) as count FROM import_batches 
    WHERE organization_id = :org_id GROUP BY status
");
$stmt->execute([':org_id' => $orgId]);
$statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

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

// Action buttons for launcher
$actions = [];
if ($config['show_actions']) {
    $actions[] = ['label' => 'DISBURSE FUNDS', 'href' => '../imports/upload.php', 'mark' => '§1', 'badge' => null];
}
if ($config['show_all_batches']) {
    $actions[] = ['label' => 'BATCHES', 'href' => 'index.php', 'mark' => '§2', 'badge' => $stats['total_batches'] > 0 ? $stats['total_batches'] : null, 'active' => true];
}
$actions[] = ['label' => 'PENDING APPROVALS', 'href' => 'index.php?status=READY_FOR_APPROVAL', 'mark' => '§3', 'badge' => $stats['pending'] > 0 ? $stats['pending'] : null];
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
            padding: 120px 20px 60px;
            position: relative;
            z-index: 1;
        }
        .stage-inner { width: 100%; max-width: 1100px; display: flex; flex-direction: column; align-items: center; gap: 28px; }

        .welcome { text-align: center; margin-bottom: 8px; }
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
           FILTER BAR
           ============================================================ */
        .filter-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            width: 100%;
        }
        .filter-btn {
            padding: 6px 14px;
            border: 1.5px solid var(--line);
            text-decoration: none;
            color: var(--ink-500);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: var(--f-cond);
            transition: all 0.15s ease;
            background: var(--panel);
        }
        .filter-btn:hover {
            border-color: var(--brass);
            color: var(--ink-900);
        }
        .filter-btn.active {
            background: var(--ink-900);
            border-color: var(--ink-900);
            color: white;
        }
        .filter-btn .count {
            color: var(--ink-300);
            font-weight: 400;
        }
        .filter-btn.active .count {
            color: rgba(255,255,255,0.5);
        }

        .filter-bar .search-wrap {
            margin-left: auto;
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .filter-bar .search-wrap input {
            padding: 6px 12px;
            border: 1.5px solid var(--line);
            font-size: 11px;
            font-family: var(--f-body);
            background: var(--panel);
            color: var(--ink-900);
            width: 200px;
            transition: border-color 0.15s ease;
        }
        .filter-bar .search-wrap input:focus {
            outline: none;
            border-color: var(--brass);
        }
        .filter-bar .search-wrap button {
            padding: 6px 14px;
            background: var(--ink-900);
            color: white;
            border: 1.5px solid var(--ink-900);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: var(--f-cond);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .filter-bar .search-wrap button:hover {
            background: var(--brass);
            border-color: var(--brass);
            color: var(--ink-900);
        }

        .filter-bar .new-batch-btn {
            padding: 6px 16px;
            background: var(--ink-900);
            color: white;
            border: 1.5px solid var(--ink-900);
            text-decoration: none;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: var(--f-cond);
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .filter-bar .new-batch-btn:hover {
            background: var(--brass);
            border-color: var(--brass);
            color: var(--ink-900);
        }

        /* ============================================================
           TABLE
           ============================================================ */
        .table-wrap { overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 10px 16px; text-align: left; border-bottom: 1px solid var(--line); }
        th {
            background: var(--brass-tint);
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 9px;
            color: var(--ink-500);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            border-bottom: 2px solid var(--line-strong);
            position: sticky;
            top: 0;
        }
        tbody tr:nth-child(even) td { background: #F8FAF8; }
        tbody tr:hover td { background: var(--blue-tint); }
        td code {
            font-family: var(--f-mono);
            font-size: 10px;
            font-weight: 600;
            color: var(--ink-700);
            background: var(--brass-tint);
            padding: 1px 5px;
            text-transform: uppercase;
        }
        td .amt { font-family: var(--f-mono); font-weight: 700; font-variant-numeric: tabular-nums; }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: var(--f-cond);
            border: 1.5px solid transparent;
        }
        .status-badge.completed { background: var(--green-tint); color: var(--ledger-green); border-color: var(--ledger-green); }
        .status-badge.processing { background: var(--warning-bg); color: var(--amber); border-color: var(--amber); }
        .status-badge.ready_for_approval { background: var(--blue-tint); color: var(--ink-700); border-color: var(--ink-500); }
        .status-badge.failed { background: var(--danger-bg); color: var(--seal-red); border-color: var(--seal-red); }
        .status-badge.partial { background: #fef3c7; color: #92400e; border-color: #92400e; }
        .status-badge.draft { background: var(--line); color: var(--ink-300); border-color: var(--ink-300); }

        .action-link {
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.06em;
            text-decoration: none;
            color: var(--ink-700);
            border-bottom: 1.5px solid var(--brass);
            text-transform: uppercase;
            padding-bottom: 1px;
            transition: all 0.15s ease;
        }
        .action-link:hover {
            color: var(--brass);
            border-bottom-color: var(--brass);
        }

        .empty-state { text-align: center; padding: 40px 20px; color: var(--ink-300); }
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
            .filter-bar .search-wrap { margin-left: 0; width: 100%; }
            .filter-bar .search-wrap input { flex: 1; }
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
            .filter-bar { gap: 6px; }
            .filter-btn { font-size: 8px; padding: 4px 10px; }
            .filter-bar .search-wrap input { font-size: 9px; padding: 4px 8px; }
            .filter-bar .search-wrap button { font-size: 8px; padding: 4px 10px; }
            .filter-bar .new-batch-btn { font-size: 8px; padding: 4px 10px; }
            th, td { padding: 6px 10px; font-size: 10px; }
            td code { font-size: 8px; }
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #131C24; --panel: #1B2733; --line: #2C3A45; --line-strong: #3C4C58;
                --ink-900: #ECEFF2; --ink-700: #C9D2D9; --ink-500: #93A2AC; --ink-300: #6B7A85;
                --brass-tint: #22303A; --blue-tint: #1D2A38; --green-tint: #17261D;
            }
            .vouchmorph-watermark { color: rgba(201, 151, 42, 0.08); }
            .action-btn { border-color: var(--ink-900); }
            .filter-btn {
                background: #1B2733;
                border-color: #2C3A45;
                color: #6B7A85;
            }
            .filter-btn:hover {
                border-color: var(--brass);
                color: #ECEFF2;
            }
            .filter-btn.active {
                background: #2C3A45;
                border-color: #2C3A45;
                color: #ECEFF2;
            }
            .filter-bar .search-wrap input {
                background: #1B2733;
                border-color: #2C3A45;
                color: #ECEFF2;
            }
            .filter-bar .search-wrap input:focus {
                border-color: var(--brass);
            }
            .filter-bar .search-wrap button {
                background: #2C3A45;
                border-color: #2C3A45;
                color: #ECEFF2;
            }
            .filter-bar .search-wrap button:hover {
                background: var(--brass);
                border-color: var(--brass);
                color: var(--ink-900);
            }
            .filter-bar .new-batch-btn {
                background: #2C3A45;
                border-color: #2C3A45;
                color: #ECEFF2;
            }
            .filter-bar .new-batch-btn:hover {
                background: var(--brass);
                border-color: var(--brass);
                color: var(--ink-900);
            }
            tbody tr:nth-child(even) td { background: #182129; }
            tbody tr:hover td { background: #1A2A3A; }
            th { background: #1A1A2E; }
            .status-badge.ready_for_approval { background: #1A2A3A; color: #93A2AC; }
        }
    </style>
</head>
<body>
    <!-- VouchMorph™ Watermark -->
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
                <p>VIEW AND MANAGE DISBURSEMENT BATCHES<?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?></p>
            </div>

            <!-- Role-based launcher -->
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

            <!-- Filter Bar -->
            <div class="filter-bar">
                <a href="?status=all" class="filter-btn <?php echo $status === 'all' ? 'active' : ''; ?>">
                    All <span class="count">(<?php echo array_sum($statusCounts); ?>)</span>
                </a>
                <a href="?status=READY_FOR_APPROVAL" class="filter-btn <?php echo $status === 'READY_FOR_APPROVAL' ? 'active' : ''; ?>">
                    Pending <span class="count">(<?php echo $statusCounts['READY_FOR_APPROVAL'] ?? 0; ?>)</span>
                </a>
                <a href="?status=COMPLETED" class="filter-btn <?php echo $status === 'COMPLETED' ? 'active' : ''; ?>">
                    Completed <span class="count">(<?php echo $statusCounts['COMPLETED'] ?? 0; ?>)</span>
                </a>
                <a href="?status=PROCESSING" class="filter-btn <?php echo $status === 'PROCESSING' ? 'active' : ''; ?>">
                    Processing <span class="count">(<?php echo $statusCounts['PROCESSING'] ?? 0; ?>)</span>
                </a>
                <a href="?status=FAILED" class="filter-btn <?php echo $status === 'FAILED' ? 'active' : ''; ?>">
                    Failed <span class="count">(<?php echo $statusCounts['FAILED'] ?? 0; ?>)</span>
                </a>
                <div class="search-wrap">
                    <form method="GET" style="display:flex; gap:8px; align-items:center;">
                        <input type="text" name="search" placeholder="Search batches..." value="<?php echo htmlspecialchars($search); ?>">
                        <input type="hidden" name="status" value="<?php echo $status; ?>">
                        <button type="submit">Search</button>
                    </form>
                    <a href="../imports/upload.php" class="new-batch-btn">+ New Batch</a>
                </div>
            </div>

            <!-- Batches Table -->
            <div class="doc-panel" style="width:100%; padding:0; overflow:hidden;">
                <div class="eyebrow" style="padding:10px 18px; background:var(--brass-tint); border-bottom:1px solid var(--line);">
                    <span class="section-mark">§</span>Batch Register
                    <span style="float:right; color:var(--ink-300); font-weight:400; font-size:9px;">
                        <?php echo count($batches); ?> records
                    </span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Batch Name</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Recipients</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <span class="mark">§</span>
                                        <p>NO BATCHES ON RECORD. <a href="../imports/upload.php">CREATE THE FIRST ENTRY →</a></p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($batches as $batch): 
                                $status = strtolower($batch['status'] ?? 'draft');
                                $statusDisplay = strtoupper(str_replace('_', ' ', $batch['status'] ?? 'Draft'));
                            ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($batch['batch_reference']); ?></code></td>
                                <td><?php echo htmlspecialchars($batch['batch_name']); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($batch['created_at'])); ?></td>
                                <td><span class="amt"><?php echo formatCurrency($batch['total_amount']); ?></span></td>
                                <td><?php echo $batch['total_rows']; ?></td>
                                <td><span class="status-badge <?php echo $status; ?>"><?php echo htmlspecialchars($statusDisplay); ?></span></td>
                                <td><a href="view.php?id=<?php echo $batch['id']; ?>" class="action-link">Review →</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- Footer -->
    <footer class="page-footer">
        <div class="notice">SECURE ENTERPRISE MULTI ASSET PAYMENT · DISTRIBUTION RESTRICTED · ISO 27001 · © <?php echo date('Y'); ?> VOUCHMORPH</div>
        <div class="role-line"><?php echo $roleDisplay; ?><?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?> · <?php echo htmlspecialchars($fileRef); ?></div>
    </footer>
</body>
</html>
