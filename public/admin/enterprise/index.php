<?php
// index.php - VOUCHMORPH NATIONAL DISBURSEMENT OPERATING PLATFORM
// Fits comfortably on a laptop screen at normal data volumes; scrolls gracefully if content grows
require_once 'auth.php';
$user = requireEnterpriseAuth();

$pdo = getDBConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';
$departmentId = $user['department_id'] ?? null;

// ============================================================
// ROLE-BASED DATA FETCHING
// ============================================================

$roleFilter = '';
$roleParams = [':org_id' => $orgId];

if (!in_array($userRole, ['owner', 'auditor'])) {
    $roleFilter = ' AND department_id = :dept_id ';
    $roleParams[':dept_id'] = $departmentId;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(total_amount), 0) as amount,
            COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) as completed_amount
        FROM import_batches 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute($roleParams);
    $currentMonth = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'amount' => 0, 'completed_amount' => 0];

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as amount 
        FROM import_batches 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        AND status = 'READY_FOR_APPROVAL'
    ");
    $stmt->execute($roleParams);
    $pendingApproval = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'amount' => 0];

    $stmt = $pdo->prepare("
        SELECT * FROM import_batches 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        ORDER BY created_at DESC LIMIT 4
    ");
    $stmt->execute($roleParams);
    $recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM organization_beneficiaries 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        AND is_active = true
    ");
    $stmt->execute($roleParams);
    $beneficiaryCount = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END), 0) as completed
        FROM import_batches 
        WHERE organization_id = :org_id 
        " . $roleFilter . "
        AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute($roleParams);
    $successData = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'completed' => 0];
    $successRate = $successData['total'] > 0 ? round(($successData['completed'] / $successData['total']) * 100, 2) : 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM disbursement_programs WHERE organization_id = :org_id AND status = 'ACTIVE'");
    $stmt->execute([':org_id' => $orgId]);
    $programCount = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM departments WHERE organization_id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    $departmentCount = $stmt->fetchColumn() ?: 0;

    $stats = [
        'disbursed' => $currentMonth['amount'],
        'success_rate' => $successRate,
        'pending' => $pendingApproval['count'],
        'beneficiaries' => $beneficiaryCount,
        'successful_payments' => $successData['completed'],
        'programs' => $programCount,
        'departments' => $departmentCount
    ];

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats = ['disbursed' => 0, 'success_rate' => 0, 'pending' => 0, 'beneficiaries' => 0, 'successful_payments' => 0, 'programs' => 0, 'departments' => 0];
    $recentBatches = [];
}

$config = [
    'show_actions' => in_array($userRole, ['owner', 'program_officer', 'department_head']),
    'show_beneficiaries' => in_array($userRole, ['owner', 'auditor', 'program_officer', 'beneficiary_registrar', 'department_head', 'viewer']),
    'show_all_batches' => !in_array($userRole, ['beneficiary_registrar']),
];

$departmentName = '';
if ($departmentId) {
    $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = :id AND organization_id = :org_id");
    $stmt->execute([':id' => $departmentId, ':org_id' => $orgId]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    $departmentName = $dept['name'] ?? '';
}

$roleDisplay = strtoupper($userRole);
$orgName = htmlspecialchars($user['organization_name'] ?? 'GOVERNMENT OF BOTSWANA');

function formatCurrency($amount) {
    return 'BWP ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph · National Disbursement Operations Center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           ROOT VARIABLES - EXTREMELY COMPACT
           ============================================================ */
        :root {
            --bg-primary: #0D1B2A;
            --bg-header: #11263F;
            --bg-surface: #EFF3F7;
            --bg-white: #FFFFFF;
            --border-color: #D4DAE2;
            --text-primary: #0D1B2A;
            --text-secondary: #4A5568;
            --text-muted: #718096;
            --gold: #C9972A;
            --success: #107C41;
            --success-bg: #E6F4ED;
            --warning: #A15C00;
            --warning-bg: #FDF1E6;
            --danger: #C62828;
            --danger-bg: #FDE8E8;
            --blue: #005EA2;
            --blue-bg: #E6F0F8;
            --font-body: 'IBM Plex Sans', sans-serif;
            --font-mono: 'IBM Plex Mono', monospace;
            --sidebar-width: 200px;
            --transition: all 0.15s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            height: 100%;
        }

        body {
            font-family: var(--font-body);
            background: var(--bg-surface);
            color: var(--text-primary);
            font-size: 13px;
            line-height: 1.4;
            -webkit-font-smoothing: antialiased;
            display: flex;
            min-height: 100vh;
        }

        ::-webkit-scrollbar { width: 3px; height: 3px; }
        ::-webkit-scrollbar-track { background: var(--bg-surface); }
        ::-webkit-scrollbar-thumb { background: var(--border-color); }

        .app {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* ============================================================
           SIDEBAR - ULTRA COMPACT
           ============================================================ */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-primary);
            color: white;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow: hidden;
            flex-shrink: 0;
            border-right: 1px solid rgba(255,255,255,0.05);
        }

        .sidebar-header { padding: 16px 16px 12px; border-bottom: 1px solid rgba(255,255,255,0.05); flex-shrink: 0; }
        .sidebar-header .brand { font-family: var(--font-mono); font-weight: 700; font-size: 14px; letter-spacing: 1.5px; color: var(--gold); }
        .sidebar-header .sub { font-size: 8px; font-weight: 500; color: rgba(255,255,255,0.25); text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }
        .sidebar-header .org { font-size: 9px; color: rgba(255,255,255,0.35); margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.05); }

        .sidebar-nav { padding: 10px 8px; overflow-y: auto; flex: 1; }
        .sidebar-nav .nav-group { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.15); padding: 12px 8px 4px; font-weight: 600; }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 8px;
            color: rgba(255,255,255,0.4);
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition: var(--transition);
            border-left: 2px solid transparent;
        }
        .nav-item:hover { background: rgba(255,255,255,0.04); color: rgba(255,255,255,0.7); }
        .nav-item.active { background: rgba(201,151,42,0.08); color: var(--gold); border-left-color: var(--gold); }
        .nav-item .nav-icon { width: 14px; text-align: center; font-size: 11px; flex-shrink: 0; opacity: 0.4; }
        .nav-item.active .nav-icon { opacity: 1; }
        .nav-item .badge { margin-left: auto; background: var(--gold); color: var(--bg-primary); font-size: 8px; font-weight: 700; padding: 1px 5px; font-family: var(--font-mono); }

        .sidebar-footer { padding: 10px 14px; border-top: 1px solid rgba(255,255,255,0.05); flex-shrink: 0; }
        .sidebar-footer .user-row { display: flex; align-items: center; gap: 8px; }
        .sidebar-footer .avatar { width: 26px; height: 26px; background: var(--gold); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 10px; color: var(--bg-primary); font-family: var(--font-mono); flex-shrink: 0; }
        .sidebar-footer .user-info { flex: 1; min-width: 0; }
        .sidebar-footer .user-info .name { font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.75); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-footer .user-info .meta { font-size: 8px; color: rgba(255,255,255,0.2); text-transform: uppercase; letter-spacing: 0.3px; }
        .sidebar-footer .logout-link { color: rgba(255,255,255,0.2); text-decoration: none; font-size: 13px; transition: var(--transition); }
        .sidebar-footer .logout-link:hover { color: var(--gold); }

        /* ============================================================
           MAIN - FULL HEIGHT
           ============================================================ */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-width: 0;
            background: var(--bg-surface);
        }

        /* ============================================================
           STATUS RIBBON - TINY
           ============================================================ */
        .status-ribbon {
            background: var(--bg-header);
            color: white;
            padding: 0 24px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .status-ribbon .left { display: flex; align-items: center; gap: 12px; font-size: 10px; }
        .status-ribbon .left .org-name { font-weight: 600; color: #FFFFFF; }
        .status-ribbon .left .sep { color: rgba(255,255,255,0.15); }
        .status-ribbon .left .tag { font-size: 8px; text-transform: uppercase; letter-spacing: 0.8px; padding: 1px 6px; border: 1px solid rgba(255,255,255,0.08); font-weight: 600; color: rgba(255,255,255,0.35); }
        .status-ribbon .left .tag.prod { border-color: var(--success); color: #6FCF97; }
        .status-ribbon .right { display: flex; align-items: center; gap: 10px; font-size: 10px; color: rgba(255,255,255,0.3); }
        .status-ribbon .right .dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 3px; }
        .status-ribbon .right .dot.online { background: #6FCF97; }

        /* ============================================================
           TOP BAR - TINY
           ============================================================ */
        .top-bar {
            background: var(--bg-white);
            padding: 12px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            min-height: 30px;
        }
        .top-bar .title-section h1 { font-size: 16px; font-weight: 600; }
        .top-bar .title-section .sub { font-size: 10px; color: var(--text-muted); margin-top: 2px; }
        .top-bar .actions { display: flex; align-items: center; gap: 10px; }
        .top-bar .actions .time { font-family: var(--font-mono); font-size: 10px; color: var(--text-muted); }
        .top-bar .btn-icon { width: 28px; height: 28px; background: none; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted); font-size: 12px; }
        .top-bar .btn-icon:hover { border-color: var(--gold); color: var(--gold); }
        .menu-toggle { display: none; background: none; border: none; color: var(--text-primary); font-size: 18px; cursor: pointer; padding: 2px; }

        /* ============================================================
           DASHBOARD CONTENT - FILLS ENTIRE REMAINING SPACE
           ============================================================ */
        .dashboard-content {
            flex: 1;
            padding: 16px 24px 0;
            max-width: 1600px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
        }

        .content-wrapper {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* ============================================================
           MISSION STATUS - 6 COLUMN
           ============================================================ */
        .mission-status {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            flex-shrink: 0;
        }

        .kpi-panel {
            background: var(--bg-white);
            border: 1px solid var(--border-color);
            padding: 12px 14px;
        }
        .kpi-panel .kpi-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-muted); font-weight: 600; }
        .kpi-panel .kpi-value { font-family: var(--font-mono); font-size: 21px; font-weight: 700; margin-top: 4px; letter-spacing: -0.3px; }
        .kpi-panel .kpi-trend { font-size: 9px; font-weight: 600; margin-top: 4px; }
        .kpi-panel .kpi-trend.up { color: var(--success); }
        .kpi-panel .kpi-trend.down { color: var(--danger); }
        .kpi-panel .kpi-trend.neutral { color: var(--text-muted); }

        /* ============================================================
           SYSTEM HEALTH
           ============================================================ */
        .system-health {
            display: flex;
            gap: 18px;
            padding: 10px 14px;
            background: var(--bg-white);
            border: 1px solid var(--border-color);
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .system-health .health-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            font-weight: 500;
            color: var(--text-secondary);
        }
        .system-health .health-item .dot { width: 6px; height: 6px; border-radius: 50%; }
        .system-health .health-item .dot.online { background: var(--success); }

        /* ============================================================
           MIDDLE GRID - FLEXIBLE
           ============================================================ */
        .middle-grid {
            display: grid;
            grid-template-columns: 1fr 1.2fr 1fr;
            gap: 10px;
            flex-shrink: 0;
        }

        .panel {
            background: var(--bg-white);
            border: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 8px 12px;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            flex-shrink: 0;
        }
        .panel-header .badge-count { background: var(--bg-primary); color: white; font-size: 9px; padding: 1px 6px; font-family: var(--font-mono); }
        .panel-body {
            padding: 10px 12px;
            flex: 1;
        }

        .approval-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 11px;
        }
        .approval-item:last-child { border-bottom: none; }
        .approval-item .ref { font-family: var(--font-mono); font-size: 10px; font-weight: 600; color: var(--text-secondary); }
        .approval-item .amount { font-family: var(--font-mono); font-weight: 600; font-size: 11px; }
        .approval-item .status-tag { font-size: 8px; text-transform: uppercase; padding: 2px 6px; font-weight: 600; }
        .approval-item .status-tag.pending { background: var(--warning-bg); color: var(--warning); }
        .approval-item .status-tag.threshold { background: var(--danger-bg); color: var(--danger); }

        .panel .view-all-link { font-size: 10px; color: var(--blue); text-decoration: none; font-weight: 600; display: inline-block; margin-top: 8px; }

        .pipeline-steps { display: flex; gap: 4px; padding: 4px 0; }
        .pipeline-steps .step {
            flex: 1; padding: 6px 4px; text-align: center; font-size: 8px; text-transform: uppercase;
            font-weight: 600; border: 1px solid var(--border-color); color: var(--text-muted);
        }
        .pipeline-steps .step.active { background: var(--blue-bg); border-color: var(--blue); color: var(--blue); }
        .pipeline-steps .step.done { background: var(--success-bg); border-color: var(--success); color: var(--success); }
        .pipeline-progress { height: 4px; background: var(--border-color); margin: 10px 0 6px; }
        .pipeline-progress .fill { height: 100%; background: var(--success); width: 72%; }
        .pipeline-stats { display: flex; justify-content: space-between; font-size: 9px; color: var(--text-muted); margin-top: 4px; }

        .feed-item {
            display: flex; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--border-color);
            font-size: 10px; color: var(--text-secondary);
        }
        .feed-item:last-child { border-bottom: none; }
        .feed-item .time { font-family: var(--font-mono); font-size: 9px; color: var(--text-muted); flex-shrink: 0; width: 34px; }
        .feed-item .event { flex: 1; }
        .feed-item .event strong { color: var(--text-primary); }
        .feed-item .badge-live { font-size: 8px; text-transform: uppercase; color: var(--success); font-weight: 600; animation: pulse-live 2s infinite; }

        @keyframes pulse-live { 0%,100%{opacity:1;} 50%{opacity:0.3;} }

        /* ============================================================
           TABLE - FILLS REMAINING SPACE
           ============================================================ */
        .table-panel {
            display: flex;
            flex-direction: column;
            background: var(--bg-white);
            border: 1px solid var(--border-color);
        }
        .table-panel .panel-header { flex-shrink: 0; }
        .table-wrap { max-height: 360px; overflow-y: auto; }
        .table-wrap table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background: var(--bg-surface); font-weight: 600; font-size: 9px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--border-color); position: sticky; top: 0; z-index: 1; }
        tr:hover td { background: var(--bg-surface); }

        .status-badge { font-size: 8px; text-transform: uppercase; padding: 2px 6px; font-weight: 600; }
        .status-badge.completed { background: var(--success-bg); color: var(--success); }
        .status-badge.processing { background: var(--warning-bg); color: var(--warning); }
        .status-badge.pending { background: var(--blue-bg); color: var(--blue); }
        .status-badge.failed { background: var(--danger-bg); color: var(--danger); }
        .status-badge.draft { background: var(--border-color); color: var(--text-muted); }

        .action-link { color: var(--blue); text-decoration: none; font-weight: 600; font-size: 7px; }
        .action-link:hover { text-decoration: underline; }
        code { background: var(--bg-surface); padding: 0 3px; font-family: var(--font-mono); font-size: 6px; font-weight: 600; color: var(--text-secondary); }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state { text-align: center; padding: 24px 12px; color: var(--text-muted); }
        .empty-state .icon { font-size: 22px; display: block; margin-bottom: 6px; }
        .empty-state p { font-size: 11px; }
        .empty-state a { color: var(--blue); text-decoration: none; font-weight: 600; font-size: 11px; }

        /* ============================================================
           FOOTER - TINY
           ============================================================ */
        .page-footer {
            flex-shrink: 0;
            padding: 14px 0 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 9px;
            letter-spacing: 0.5px;
            border-top: 1px solid var(--border-color);
            font-weight: 500;
            text-transform: uppercase;
            margin-top: 12px;
        }
        .page-footer .role-line { font-size: 8px; letter-spacing: 0.5px; color: var(--text-muted); margin-top: 3px; }

        /* ============================================================
           SIDEBAR OVERLAY
           ============================================================ */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; cursor: pointer; }
        .sidebar-overlay.active { display: block; }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1200px) {
            .mission-status { grid-template-columns: repeat(3, 1fr); }
            .middle-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 992px) {
            .sidebar { position: fixed; top: 0; left: 0; transform: translateX(-100%); width: 220px; height: 100vh; z-index: 100; transition: transform 0.25s ease; }
            .sidebar.open { transform: translateX(0); }
            .menu-toggle { display: block; }
            .status-ribbon { padding: 0 14px; height: auto; min-height: 28px; flex-wrap: wrap; }
            .status-ribbon .left { gap: 8px; font-size: 9px; flex-wrap: wrap; }
            .status-ribbon .right { gap: 8px; font-size: 9px; }
            .top-bar { padding: 10px 14px; flex-wrap: wrap; }
            .top-bar .title-section h1 { font-size: 14px; }
            .dashboard-content { padding: 12px 14px 0; }
            .mission-status { grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .middle-grid { grid-template-columns: 1fr; }
            .kpi-panel .kpi-value { font-size: 17px; }
        }

        @media (max-width: 640px) {
            .status-ribbon .left .tag { display: none; }
            .status-ribbon .left .sep { display: none; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar .actions { justify-content: flex-end; }
            .mission-status { grid-template-columns: repeat(2, 1fr); }
            .kpi-panel .kpi-value { font-size: 16px; }
            .kpi-panel { padding: 10px 12px; }
            .system-health { gap: 10px; padding: 8px 12px; }
            .system-health .health-item { font-size: 9px; }
            .dashboard-content { padding: 10px 12px 0; }
            .panel-body { padding: 8px 10px; }
            th, td { padding: 7px 8px; font-size: 10px; }
            .page-footer { font-size: 8px; padding: 10px 0 14px; }
            .middle-grid { gap: 8px; }
            .approval-item { font-size: 10px; }
            .approval-item .amount { font-size: 10px; }
        }

        @media (min-width: 1920px) {
            .dashboard-content { padding: 20px 32px 0; }
            .kpi-panel .kpi-value { font-size: 24px; }
            .kpi-panel { padding: 16px 18px; }
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-surface: #1A1A2E;
                --bg-white: #16213E;
                --border-color: #2A3A5E;
                --text-primary: #E2E8F0;
                --text-secondary: #A0AEC0;
                --text-muted: #718096;
            }
            .top-bar { background: #16213E; }
            .panel-header { background: #1A1A2E; }
            th { background: #1A1A2E; }
            .kpi-panel { background: #16213E; }
            .panel { background: #16213E; }
            .status-ribbon { background: #0D1B2A; }
            .system-health { background: #16213E; }
            .table-panel { background: #16213E; }
            .page-footer { border-color: #2A3A5E; }
        }
    </style>
</head>
<body>
    <div class="app">
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="brand">VOUCHMORPH</div>
                <div class="sub">National Disbursement</div>
                <div class="org"><?php echo substr($orgName, 0, 20); ?></div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-group">Mission</div>
                <a href="index.php" class="nav-item active"><span class="nav-icon">◆</span>Command</a>
                <?php if ($config['show_actions']): ?>
                <a href="imports/upload.php" class="nav-item"><span class="nav-icon">◈</span>Disburse</a>
                <?php endif; ?>
                <a href="batches/index.php" class="nav-item"><span class="nav-icon">▦</span>Batches <?php if ($stats['pending'] > 0): ?><span class="badge"><?php echo $stats['pending']; ?></span><?php endif; ?></a>
                <?php if ($config['show_beneficiaries']): ?>
                <a href="beneficiaries/index.php" class="nav-item"><span class="nav-icon">◈</span>Beneficiaries</a>
                <?php endif; ?>
                <div class="nav-group">Approvals</div>
                <a href="batches/index.php?filter=pending" class="nav-item"><span class="nav-icon">◉</span>Pending <?php if ($stats['pending'] > 0): ?><span class="badge"><?php echo $stats['pending']; ?></span><?php endif; ?></a>
                <div class="nav-group">Governance</div>
                <a href="reports/audit_trail.php" class="nav-item"><span class="nav-icon">◧</span>Audit</a>
                <a href="reports/index.php" class="nav-item"><span class="nav-icon">▥</span>Reports</a>
                <div class="nav-group">System</div>
                <a href="testgov.php" class="nav-item"><span class="nav-icon">◈</span>Diagnostics</a>
            </nav>
            <div class="sidebar-footer">
                <div class="user-row">
                    <div class="avatar"><?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 1)); ?></div>
                    <div class="user-info">
                        <div class="name"><?php echo substr($user['full_name'] ?? $user['email'], 0, 12); ?></div>
                        <div class="meta"><?php echo $roleDisplay; ?></div>
                    </div>
                    <a href="logout.php" class="logout-link" title="Sign out">↗</a>
                </div>
            </div>
        </aside>

        <!-- Main -->
        <main class="main">
            <!-- Status Ribbon -->
            <div class="status-ribbon">
                <div class="left">
                    <span class="org-name"><?php echo $orgName; ?></span>
                    <span class="sep">|</span>
                    <span>NATIONAL DISBURSEMENT</span>
                    <span class="sep">|</span>
                    <span class="tag prod">● PROD</span>
                    <span class="tag">SECURE</span>
                </div>
                <div class="right">
                    <span>ACTIVE</span>
                    <span class="sep">|</span>
                    <span><span class="dot online"></span> 98%</span>
                    <span class="sep">|</span>
                    <span><?php echo date('H:i'); ?></span>
                </div>
            </div>

            <!-- Top Bar -->
            <div class="top-bar">
                <div class="title-section">
                    <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle menu">≡</button>
                    <h1>National Disbursement Operations Center</h1>
                    <div class="sub"><?php echo date('M d, Y'); ?></div>
                </div>
                <div class="actions">
                    <span class="time">UTC+2</span>
                    <button class="btn-icon" title="Refresh" onclick="location.reload()">⟳</button>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="content-wrapper">
                    <!-- Mission Status -->
                    <div class="mission-status">
                        <div class="kpi-panel">
                            <div class="kpi-label">Disbursed</div>
                            <div class="kpi-value"><?php echo formatCurrency($stats['disbursed']); ?></div>
                            <div class="kpi-trend up">▲ 8.2%</div>
                        </div>
                        <div class="kpi-panel">
                            <div class="kpi-label">Success</div>
                            <div class="kpi-value"><?php echo number_format($stats['success_rate'], 1); ?>%</div>
                            <div class="kpi-trend up">▲ 0.02%</div>
                        </div>
                        <div class="kpi-panel">
                            <div class="kpi-label">Approvals</div>
                            <div class="kpi-value"><?php echo number_format($stats['pending']); ?></div>
                            <div class="kpi-trend neutral">Waiting</div>
                        </div>
                        <div class="kpi-panel">
                            <div class="kpi-label">Beneficiaries</div>
                            <div class="kpi-value"><?php echo number_format($stats['beneficiaries']); ?></div>
                            <div class="kpi-trend up">Active</div>
                        </div>
                        <div class="kpi-panel">
                            <div class="kpi-label">Programs</div>
                            <div class="kpi-value"><?php echo number_format($stats['programs']); ?></div>
                            <div class="kpi-trend up">Active</div>
                        </div>
                        <div class="kpi-panel">
                            <div class="kpi-label">Departments</div>
                            <div class="kpi-value"><?php echo number_format($stats['departments']); ?></div>
                            <div class="kpi-trend up">Active</div>
                        </div>
                    </div>

                    <!-- System Health -->
                    <div class="system-health">
                        <span class="health-item"><span class="dot online"></span> Database</span>
                        <span class="health-item"><span class="dot online"></span> Identity</span>
                        <span class="health-item"><span class="dot online"></span> Swap Engine</span>
                        <span class="health-item"><span class="dot online"></span> Banks</span>
                        <span class="health-item"><span class="dot online"></span> Audit</span>
                        <span class="health-item"><span class="dot online"></span> Security</span>
                    </div>

                    <!-- Middle Grid -->
                    <div class="middle-grid">
                        <div class="panel">
                            <div class="panel-header">Pending Approvals <span class="badge-count"><?php echo $stats['pending']; ?></span></div>
                            <div class="panel-body">
                                <?php if ($stats['pending'] > 0): ?>
                                <div class="approval-item"><span class="ref">GOV-440</span><span class="amount">BWP 12.4K</span><span class="status-tag pending">Pending</span></div>
                                <div class="approval-item"><span class="ref">GOV-439</span><span class="amount">BWP 8.2K</span><span class="status-tag pending">Pending</span></div>
                                <div class="approval-item"><span class="ref">GOV-438</span><span class="amount">BWP 125K</span><span class="status-tag threshold">▲</span></div>
                                <a href="batches/index.php?filter=pending" class="view-all-link">View all →</a>
                                <?php else: ?>
                                <div class="empty-state"><span class="icon">◯</span><p>No pending</p></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="panel">
                            <div class="panel-header">Pipeline <span class="badge-count">72%</span></div>
                            <div class="panel-body">
                                <div class="pipeline-steps">
                                    <span class="step done">Draft</span>
                                    <span class="step done">Approved</span>
                                    <span class="step active">Executing</span>
                                    <span class="step">Complete</span>
                                </div>
                                <div class="pipeline-progress"><div class="fill" style="width:72%;"></div></div>
                                <div class="pipeline-stats"><span>12</span><span>8</span><span>18</span><span>47</span></div>
                            </div>
                        </div>

                        <div class="panel">
                            <div class="panel-header">Live <span class="badge-live">● Live</span></div>
                            <div class="panel-body">
                                <div class="feed-item"><span class="time">15:34</span><span class="event"><strong>Approved</strong> GOV-440</span></div>
                                <div class="feed-item"><span class="time">15:31</span><span class="event"><strong>Imported</strong> 2.1K beneficiaries</span></div>
                                <div class="feed-item"><span class="time">15:29</span><span class="event"><strong>Verified</strong> Identity</span></div>
                                <div class="feed-item"><span class="time">15:27</span><span class="event"><strong>Completed</strong> Treasury</span></div>
                                <div class="feed-item"><span class="time">15:20</span><span class="event"><strong>Created</strong> Audit</span></div>
                            </div>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="table-panel">
                        <div class="panel-header">
                            Recent Batches
                            <?php if ($config['show_all_batches']): ?>
                            <a href="batches/index.php" style="font-size:6px; color:var(--blue); text-decoration:none; font-weight:600;">View all →</a>
                            <?php endif; ?>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Reference</th><th>Description</th><th>Program</th><th>Amount</th><th>Status</th><th>Created</th><th></th></tr></thead>
                                <tbody>
                                    <?php if (!empty($recentBatches)): ?>
                                        <?php foreach ($recentBatches as $batch):
                                            $status = strtolower($batch['status'] ?? 'draft');
                                            $statusDisplay = ucwords(str_replace('_', ' ', $batch['status'] ?? 'Draft'));
                                        ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($batch['batch_reference'] ?? '#' . $batch['id']); ?></code></td>
                                            <td><?php echo htmlspecialchars(substr($batch['batch_name'] ?? 'Batch #' . $batch['id'], 0, 20)); ?></td>
                                            <td><?php echo htmlspecialchars($batch['program_name'] ?? '—'); ?></td>
                                            <td><strong><?php echo formatCurrency($batch['total_amount'] ?? 0); ?></strong></td>
                                            <td><span class="status-badge <?php echo $status; ?>"><?php echo htmlspecialchars($statusDisplay); ?></span></td>
                                            <td><?php echo date('M d', strtotime($batch['created_at'] ?? 'now')); ?></td>
                                            <td><a href="batches/view.php?id=<?php echo $batch['id']; ?>" class="action-link">Review</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7"><div class="empty-state"><span class="icon">◻</span><p>No batches yet. <a href="imports/upload.php">Create →</a></p></div></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Footer -->
                    <footer class="page-footer">
                        <span>Secure · ISO 27001 · © <?php echo date('Y'); ?> VouchMorph · <?php echo $orgName; ?></span>
                        <div class="role-line"><?php echo $roleDisplay; ?><?php if ($departmentName): ?> · <?php echo substr(strtoupper($departmentName), 0, 15); ?><?php endif; ?></div>
                    </footer>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.getElementById('sidebarOverlay').classList.toggle('active'); }
        function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeSidebar(); });
        window.addEventListener('resize', function() { if (window.innerWidth > 992) closeSidebar(); });
    </script>
</body>
</html>
