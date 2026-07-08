<?php
// batches/view.php - ENTERPRISE DISBURSEMENT BATCH DETAILS
// MATCHES INDEX.PHP AND UPLOAD.PHP STYLE
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$batchId = $_GET['id'] ?? 0;

$stmt = $db->prepare("SELECT * FROM import_batches WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

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

// Get payment instructions for this batch
$stmt = $db->prepare("
    SELECT * FROM payment_instructions 
    WHERE batch_id = :batch_id 
    ORDER BY created_at
");
$stmt->execute([':batch_id' => $batchId]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get import rows with validation status
$stmt = $db->prepare("
    SELECT * FROM import_rows 
    WHERE batch_id = :batch_id 
    ORDER BY row_number
");
$stmt->execute([':batch_id' => $batchId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$successCount = count(array_filter($payments, fn($p) => $p['status'] === 'SUCCESS'));
$failedCount = count(array_filter($payments, fn($p) => $p['status'] === 'FAILED'));
$pendingCount = count(array_filter($payments, fn($p) => $p['status'] === 'PENDING'));

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
           BACK LINK
           ============================================================ */
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
            align-self: flex-start;
        }
        .back-link:hover {
            color: var(--brass);
            border-bottom-color: var(--brass);
        }

        /* ============================================================
           BATCH HEADER
           ============================================================ */
        .batch-header {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }
        .batch-header .title-group h1 {
            font-size: 22px;
            font-weight: 700;
            font-family: var(--f-mono);
            letter-spacing: -0.5px;
        }
        .batch-header .title-group .sub {
            font-size: 11px;
            color: var(--ink-500);
            margin-top: 2px;
        }
        .batch-header .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 18px;
            font-weight: 700;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-family: var(--f-cond);
            border: 1.5px solid transparent;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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
        .btn-success {
            background: var(--ledger-green);
            color: white;
            border-color: var(--ledger-green);
        }
        .btn-success:hover {
            background: #1a6b3a;
            border-color: #1a6b3a;
        }
        .btn-danger {
            background: var(--seal-red);
            color: white;
            border-color: var(--seal-red);
        }
        .btn-danger:hover {
            background: #5a1a12;
            border-color: #5a1a12;
        }
        .btn-secondary {
            background: var(--panel);
            color: var(--ink-500);
            border-color: var(--line);
        }
        .btn-secondary:hover {
            border-color: var(--brass);
            color: var(--ink-900);
        }

        /* ============================================================
           STATS GRID
           ============================================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            width: 100%;
        }
        .stat-card {
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 16px 20px;
        }
        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            font-family: var(--f-mono);
            letter-spacing: -0.5px;
        }
        .stat-card .stat-label {
            color: var(--ink-500);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: var(--f-cond);
            margin-top: 2px;
        }

        /* ============================================================
           ALERT
           ============================================================ */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: var(--warning-bg);
            color: var(--amber);
            padding: 10px 14px;
            font-size: 12px;
            border-left: 3px solid var(--amber);
            font-weight: 500;
            width: 100%;
        }
        .alert .icon { font-size: 16px; flex-shrink: 0; }

        /* ============================================================
           DETAILS PANEL
           ============================================================ */
        .details-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 4px 20px;
            font-size: 12px;
        }
        .details-grid .label {
            font-weight: 600;
            color: var(--ink-500);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: var(--f-cond);
        }
        .details-grid .value {
            color: var(--ink-700);
        }
        .details-grid .value .status-badge {
            display: inline-block;
            padding: 1px 8px;
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
        .status-badge.approved { background: var(--green-tint); color: var(--ledger-green); border-color: var(--ledger-green); }
        .status-badge.uploaded { background: var(--blue-tint); color: var(--ink-700); border-color: var(--ink-500); }

        /* ============================================================
           PAYMENTS TABLE
           ============================================================ */
        .table-wrap { overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 8px 14px; text-align: left; border-bottom: 1px solid var(--line); }
        th {
            background: var(--brass-tint);
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 8px;
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
            font-size: 9px;
            font-weight: 600;
            color: var(--ink-700);
            background: var(--brass-tint);
            padding: 1px 5px;
        }
        td .amt { font-family: var(--f-mono); font-weight: 700; font-variant-numeric: tabular-nums; }

        .status-badge-sm {
            display: inline-block;
            padding: 1px 6px;
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: var(--f-cond);
            border: 1.5px solid transparent;
        }
        .status-badge-sm.success { background: var(--green-tint); color: var(--ledger-green); border-color: var(--ledger-green); }
        .status-badge-sm.failed { background: var(--danger-bg); color: var(--seal-red); border-color: var(--seal-red); }
        .status-badge-sm.pending { background: var(--warning-bg); color: var(--amber); border-color: var(--amber); }

        .empty-state { text-align: center; padding: 40px 20px; color: var(--ink-300); }
        .empty-state .mark { font-family: var(--f-mono); font-size: 20px; display: block; margin-bottom: 8px; color: var(--brass); }
        .empty-state p { font-family: var(--f-cond); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.04em; }

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
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .batch-header { flex-direction: column; align-items: stretch; }
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
            .stats-grid { grid-template-columns: 1fr; gap: 8px; }
            .stat-card .stat-value { font-size: 20px; }
            .batch-header .title-group h1 { font-size: 16px; }
            .btn { font-size: 8px; padding: 6px 12px; }
            th, td { padding: 5px 8px; font-size: 9px; }
            td code { font-size: 7px; }
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #131C24; --panel: #1B2733; --line: #2C3A45; --line-strong: #3C4C58;
                --ink-900: #ECEFF2; --ink-700: #C9D2D9; --ink-500: #93A2AC; --ink-300: #6B7A85;
                --brass-tint: #22303A; --blue-tint: #1D2A38; --green-tint: #17261D;
            }
            .vouchmorph-watermark { color: rgba(201, 151, 42, 0.08); }
            tbody tr:nth-child(even) td { background: #182129; }
            tbody tr:hover td { background: #1A2A3A; }
            th { background: #1A1A2E; }
            .stat-card { background: #1B2733; border-color: #2C3A45; }
            .btn-secondary {
                background: #1B2733;
                border-color: #2C3A45;
                color: #6B7A85;
            }
            .btn-secondary:hover {
                border-color: var(--brass);
                color: #ECEFF2;
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
                <h2>BATCH DETAILS</h2>
                <p>VIEW AND MANAGE DISBURSEMENT BATCH<?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?></p>
            </div>

            <!-- Back Link -->
            <a href="index.php" class="back-link">← Return to Batches</a>

            <!-- Batch Header -->
            <div class="batch-header">
                <div class="title-group">
                    <h1><?php echo htmlspecialchars($batch['batch_reference']); ?></h1>
                    <div class="sub"><?php echo htmlspecialchars($batch['batch_name']); ?></div>
                </div>
                <div class="btn-group">
                    <?php if ($batch['status'] === 'READY_FOR_APPROVAL' && in_array($user['role'] ?? '', ['owner', 'admin'])): ?>
                        <button class="btn btn-success" onclick="approveBatch()">✓ Approve</button>
                        <button class="btn btn-danger" onclick="rejectBatch()">✗ Reject</button>
                    <?php endif; ?>
                    <?php if ($batch['status'] === 'APPROVED'): ?>
                        <a href="../imports/execute.php?batch_id=<?php echo $batchId; ?>" class="btn btn-primary">▶ Execute</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo formatCurrency($batch['total_amount']); ?></div>
                    <div class="stat-label">Total Amount</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $batch['total_rows']; ?></div>
                    <div class="stat-label">Total Recipients</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $successCount; ?></div>
                    <div class="stat-label">✅ Successful</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $failedCount; ?></div>
                    <div class="stat-label">❌ Failed</div>
                </div>
            </div>

            <!-- Alert -->
            <?php if ($batch['status'] === 'READY_FOR_APPROVAL'): ?>
            <div class="alert">
                <span class="icon">⏳</span>
                <span>This batch is pending approval. An authorized approver must review and approve before execution.</span>
            </div>
            <?php endif; ?>

            <!-- Batch Details Panel -->
            <div class="doc-panel" style="width:100%; padding:18px 22px;">
                <div class="eyebrow" style="margin-bottom:12px;">
                    <span class="section-mark">§</span>Batch Details
                </div>
                <div class="details-grid">
                    <span class="label">Reference</span>
                    <span class="value"><code><?php echo htmlspecialchars($batch['batch_reference']); ?></code></span>

                    <span class="label">Batch Name</span>
                    <span class="value"><?php echo htmlspecialchars($batch['batch_name']); ?></span>

                    <span class="label">Created</span>
                    <span class="value"><?php echo date('F d, Y H:i:s', strtotime($batch['created_at'])); ?></span>

                    <span class="label">Uploaded By</span>
                    <span class="value">User ID: <?php echo $batch['uploaded_by']; ?></span>

                    <span class="label">Status</span>
                    <span class="value"><span class="status-badge <?php echo strtolower($batch['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $batch['status'])); ?></span></span>

                    <?php if ($batch['approved_at']): ?>
                    <span class="label">Approved</span>
                    <span class="value"><?php echo date('F d, Y H:i:s', strtotime($batch['approved_at'])); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payments Table -->
            <div class="doc-panel" style="width:100%; padding:0; overflow:hidden;">
                <div class="eyebrow" style="padding:10px 18px; background:var(--brass-tint); border-bottom:1px solid var(--line);">
                    <span class="section-mark">§</span>Payment Instructions
                    <span style="float:right; color:var(--ink-300); font-weight:400; font-size:9px;">
                        <?php echo count($payments); ?> records
                    </span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Recipient</th>
                                <th>Destination</th>
                                <th>Amount</th>
                                <th>Swap Reference</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo $payment['id']; ?></td>
                                <td><?php echo htmlspecialchars($payment['recipient_name']); ?></td>
                                <td><?php echo htmlspecialchars($payment['destination_value']); ?></td>
                                <td><span class="amt"><?php echo formatCurrency($payment['amount']); ?></span></td>
                                <td><?php echo htmlspecialchars($payment['swap_reference'] ?? '-'); ?></td>
                                <td><span class="status-badge-sm <?php echo strtolower($payment['status']); ?>"><?php echo $payment['status']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <span class="mark">§</span>
                                        <p>NO PAYMENTS EXECUTED YET. APPROVE AND EXECUTE BATCH TO BEGIN.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
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

    <script>
    function approveBatch() {
        if (!confirm('Are you sure you want to approve this batch?')) return;
        fetch('../imports/approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                batch_id: <?php echo $batchId; ?>,
                action: 'approve'
            })
        }).then(res => res.json()).then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        }).catch(err => {
            alert('Request failed: ' + err.message);
        });
    }

    function rejectBatch() {
        const reason = prompt('Reason for rejection:');
        if (reason === null) return;
        if (reason.trim() === '') {
            alert('Please provide a reason for rejection.');
            return;
        }
        fetch('../imports/approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                batch_id: <?php echo $batchId; ?>,
                action: 'reject',
                reason: reason
            })
        }).then(res => res.json()).then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        }).catch(err => {
            alert('Request failed: ' + err.message);
        });
    }
    </script>
</body>
</html>
