<?php
// index.php - VOUCHMORPH NATIONAL DISBURSEMENT OPERATING PLATFORM
// PERFECTLY BALANCED LANDSCAPE LAYOUT - Centralized, Operational
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
        ORDER BY created_at DESC LIMIT 8
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

// ============================================================
// ROLE-BASED UI CONFIGURATION
// ============================================================

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
           ROOT VARIABLES - PERFECTLY BALANCED
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
            --text-white: #FFFFFF;
            --text-light: #E2E8F0;

            --gold: #C9972A;
            --gold-light: #E8D5A3;

            --success: #107C41;
            --success-bg: #E6F4ED;
            --warning: #A15C00;
            --warning-bg: #FDF1E6;
            --danger: #C62828;
            --danger-bg: #FDE8E8;
            --blue: #005EA2;
            --blue-bg: #E6F0F8;

            --font-body: 'IBM Plex Sans', -apple-system, sans-serif;
            --font-mono: 'IBM Plex Mono', monospace;

            --sidebar-width: 260px;
            --header-height: 44px;
            --ribbon-height: 40px;
            --transition: all 0.15s ease;
        }

        /* ============================================================
           RESET
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-body);
            background: var(--bg-surface);
            color: var(--text-primary);
            min-height: 100vh;
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: var(--bg-surface); }
        ::-webkit-scrollbar-thumb { background: var(--border-color); }

        /* ============================================================
           APP LAYOUT
           ============================================================ */
        .app { display: flex; min-height: 100vh; }

        /* ============================================================
           SIDEBAR
           ============================================================ */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-primary);
            color: var(--text-light);
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow: hidden;
            flex-shrink: 0;
            border-right: 1px solid rgba(255,255,255,0.05);
        }

        .sidebar-header {
            padding: 16px 18px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            flex-shrink: 0;
        }

        .sidebar-header .brand {
            font-family: var(--font-mono);
            font-weight: 700;
            font-size: 13px;
            letter-spacing: 2px;
            color: var(--gold);
        }

        .sidebar-header .sub {
            font-size: 8px;
            font-weight: 500;
            color: rgba(255,255,255,0.3);
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .sidebar-header .org {
            font-size: 10px;
            color: rgba(255,255,255,0.5);
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid rgba(255,255,255,0.05);
            font-weight: 500;
            letter-spacing: 0.3px;
        }

        .sidebar-nav {
            padding: 8px 10px;
            overflow-y: auto;
            flex: 1;
        }

        .sidebar-nav .nav-group {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.2);
            padding: 12px 10px 4px;
            font-weight: 600;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 10px;
            color: rgba(255,255,255,0.5);
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition: var(--transition);
            border-left: 2px solid transparent;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.8);
        }

        .nav-item.active {
            background: rgba(201,151,42,0.08);
            color: var(--gold);
            border-left-color: var(--gold);
        }

        .nav-item .nav-icon {
            width: 16px;
            text-align: center;
            font-size: 12px;
            flex-shrink: 0;
            opacity: 0.5;
        }

        .nav-item.active .nav-icon { opacity: 1; }

        .nav-item .badge {
            margin-left: auto;
            background: var(--gold);
            color: var(--bg-primary);
            font-size: 8px;
            font-weight: 700;
            padding: 0 6px;
            font-family: var(--font-mono);
        }

        .sidebar-footer {
            padding: 10px 14px;
            border-top: 1px solid rgba(255,255,255,0.05);
            flex-shrink: 0;
        }

        .sidebar-footer .user-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sidebar-footer .avatar {
            width: 24px;
            height: 24px;
            background: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 10px;
            color: var(--bg-primary);
            font-family: var(--font-mono);
            flex-shrink: 0;
        }

        .sidebar-footer .user-info {
            flex: 1;
            min-width: 0;
        }

        .sidebar-footer .user-info .name {
            font-size: 11px;
            font-weight: 600;
            color: rgba(255,255,255,0.8);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-footer .user-info .meta {
            font-size: 8px;
            color: rgba(255,255,255,0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sidebar-footer .logout-link {
            color: rgba(255,255,255,0.2);
            text-decoration: none;
            font-size: 14px;
            transition: var(--transition);
        }

        .sidebar-footer .logout-link:hover { color: var(--gold); }

        /* ============================================================
           MAIN CONTENT
           ============================================================ */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: var(--bg-surface);
        }

        /* ============================================================
           STATUS RIBBON
           ============================================================ */
        .status-ribbon {
            background: var(--bg-header);
            color: var(--text-light);
            padding: 0 28px;
            height: var(--ribbon-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .status-ribbon .left {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 10px;
        }

        .status-ribbon .left .org-name {
            font-weight: 600;
            color: #FFFFFF;
            letter-spacing: 0.3px;
        }

        .status-ribbon .left .sep {
            color: rgba(255,255,255,0.1);
        }

        .status-ribbon .left .tag {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 1px 8px;
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 600;
            color: rgba(255,255,255,0.4);
        }

        .status-ribbon .left .tag.prod {
            border-color: var(--success);
            color: #6FCF97;
        }

        .status-ribbon .right {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 9px;
            color: rgba(255,255,255,0.3);
        }

        .status-ribbon .right .dot {
            display: inline-block;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            margin-right: 4px;
        }

        .status-ribbon .right .dot.online { background: #6FCF97; }

        /* ============================================================
           TOP BAR
           ============================================================ */
        .top-bar {
            background: var(--bg-white);
            padding: 8px 28px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            min-height: 48px;
        }

        .top-bar .title-section h1 {
            font-size: 15px;
            font-weight: 600;
            letter-spacing: -0.2px;
        }

        .top-bar .title-section .sub {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 400;
        }

        .top-bar .actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-bar .actions .time {
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .top-bar .btn-icon {
            width: 28px;
            height: 28px;
            background: none;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-muted);
            font-size: 12px;
        }

        .top-bar .btn-icon:hover {
            border-color: var(--gold);
            color: var(--gold);
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 18px;
            cursor: pointer;
            padding: 2px;
        }

        /* ============================================================
           DASHBOARD CONTENT - PERFECTLY CENTERED
           ============================================================ */
        .dashboard-content {
            flex: 1;
            padding: 20px 28px 0;
            max-width: 1600px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
        }

        .content-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* ============================================================
           MISSION STATUS - 6 COLUMN LANDSCAPE
           ============================================================ */
        .mission-status {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }

        .kpi-panel {
            background: var(--bg-white);
            border: 1px solid var(--border-color);
            padding: 12px 14px;
        }

        .kpi-panel .kpi-label {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .kpi-panel .kpi-value {
            font-family: var(--font-mono);
            font-size: 20px;
            font-weight: 700;
            margin-top: 1px;
            letter-spacing: -0.5px;
        }

        .kpi-panel .kpi-trend {
            font-size: 9px;
            font-weight: 600;
            margin-top: 1px;
        }

        .kpi-panel .kpi-trend.up { color: var(--success); }
        .kpi-panel .kpi-trend.down { color: var(--danger); }
        .kpi-panel .kpi-trend.neutral { color: var(--text-muted); }

        /* ============================================================
           SYSTEM HEALTH
           ============================================================ */
        .system-health {
            display: flex;
            gap: 20px;
            padding: 8px 14px;
            background: var(--bg-white);
            border: 1px solid var(--border-color);
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .system-health .health-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .system-health .health-item .dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
        }

        .system-health .health-item .dot.online { background: var(--success); }
        .system-health .health-item .dot.warning { background: var(--warning); }
        .system-health .health-item .dot.offline { background: var(--danger); }

        /* ============================================================
           THREE-COLUMN LANDSCAPE MIDDLE
           ============================================================ */
        .middle-grid {
            display: grid;
            grid-template-columns: 1fr 1.5fr 1fr;
            gap: 14px;
            margin-bottom: 16px;
        }

        /* ============================================================
           PANELS
           ============================================================ */
        .panel {
            background: var(--bg-white);
            border: 1px solid var(--border-color);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 8px 14px;
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

        .panel-header .badge-count {
            background: var(--bg-primary);
            color: white;
            font-size: 8px;
            padding: 0 6px;
            font-family: var(--font-mono);
        }

        .panel-body {
            padding: 10px 14px;
            flex: 1;
            overflow-y: auto;
        }

        /* ============================================================
           APPROVAL ITEMS
           ============================================================ */
        .approval-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 11px;
        }

        .approval-item:last-child { border-bottom: none; }

        .approval-item .ref {
            font-family: var(--font-mono);
            font-size: 10px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .approval-item .amount {
            font-family: var(--font-mono);
            font-weight: 600;
            font-size: 11px;
        }

        .approval-item .status-tag {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 1px 6px;
            font-weight: 600;
        }

        .approval-item .status-tag.pending { background: var(--warning-bg); color: var(--warning); }
        .approval-item .status-tag.threshold { background: var(--danger-bg); color: var(--danger); }

        .panel .view-all-link {
            font-size: 10px;
            color: var(--blue);
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            margin-top: 6px;
        }

        .panel .view-all-link:hover { text-decoration: underline; }

        /* ============================================================
           PIPELINE
           ============================================================ */
        .pipeline-steps {
            display: flex;
            gap: 3px;
            align-items: center;
            padding: 4px 0;
        }

        .pipeline-steps .step {
            flex: 1;
            padding: 4px 6px;
            text-align: center;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-weight: 600;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
        }

        .pipeline-steps .step.active {
            background: var(--blue-bg);
            border-color: var(--blue);
            color: var(--blue);
        }

        .pipeline-steps .step.done {
            background: var(--success-bg);
            border-color: var(--success);
            color: var(--success);
        }

        .pipeline-progress {
            height: 3px;
            background: var(--border-color);
            margin: 6px 0 3px;
            position: relative;
        }

        .pipeline-progress .fill {
            height: 100%;
            background: var(--success);
            width: 72%;
        }

        .pipeline-stats {
            display: flex;
            justify-content: space-between;
            font-size: 8px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* ============================================================
           FEED
           ============================================================ */
        .feed-item {
            display: flex;
            gap: 10px;
            padding: 4px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 10px;
            color: var(--text-secondary);
        }

        .feed-item:last-child { border-bottom: none; }

        .feed-item .time {
            font-family: var(--font-mono);
            font-size: 9px;
            color: var(--text-muted);
            flex-shrink: 0;
            width: 40px;
        }

        .feed-item .event {
            flex: 1;
        }

        .feed-item .event strong { color: var(--text-primary); }

        .feed-item .badge-live {
            font-size: 7px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--success);
            font-weight: 600;
            animation: pulse-live 2s infinite;
        }

        @keyframes pulse-live {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        /* ============================================================
           TABLE - DENSE LANDSCAPE
           ============================================================ */
        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        th, td {
            padding: 6px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background: var(--bg-surface);
            font-weight: 600;
            font-size: 8px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 2px solid var(--border-color);
        }

        tr:hover td { background: var(--bg-surface); }

        .status-badge {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 1px 6px;
            font-weight: 600;
        }

        .status-badge.completed { background: var(--success-bg); color: var(--success); }
        .status-badge.processing { background: var(--warning-bg); color: var(--warning); }
        .status-badge.pending { background: var(--blue-bg); color: var(--blue); }
        .status-badge.failed { background: var(--danger-bg); color: var(--danger); }
        .status-badge.draft { background: var(--border-color); color: var(--text-muted); }

        .action-link {
            color: var(--blue);
            text-decoration: none;
            font-weight: 600;
            font-size: 10px;
        }

        .action-link:hover { text-decoration: underline; }

        code {
            background: var(--bg-surface);
            padding: 1px 5px;
            font-family: var(--font-mono);
            font-size: 9px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state {
            text-align: center;
            padding: 20px 12px;
            color: var(--text-muted);
        }

        .empty-state .icon { font-size: 24px; margin-bottom: 4px; display: block; }
        .empty-state h4 { font-size: 12px; color: var(--text-secondary); font-weight: 600; }
        .empty-state p { font-size: 10px; }
        .empty-state a { color: var(--blue); text-decoration: none; font-weight: 600; font-size: 10px; }

        /* ============================================================
           FOOTER - PERFECTLY BALANCED
           ============================================================ */
        .page-footer {
            margin-top: auto;
            padding: 12px 0 16px;
            text-align: center;
            color: var(--text-muted);
            font-size: 8px;
            letter-spacing: 0.5px;
            border-top: 1px solid var(--border-color);
            font-weight: 500;
            text-transform: uppercase;
        }

        .page-footer .role-line {
            font-size: 7px;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            margin-top: 1px;
        }

        /* ============================================================
           SIDEBAR OVERLAY
           ============================================================ */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 99;
            cursor: pointer;
        }
        .sidebar-overlay.active { display: block; }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1400px) {
            .mission-status { grid-template-columns: repeat(3, 1fr); }
            .middle-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 1200px) {
            .mission-status { grid-template-columns: repeat(3, 1fr); }
            .middle-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 992px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                transform: translateX(-100%);
                width: 280px;
                height: 100vh;
                z-index: 100;
                transition: transform 0.25s ease;
            }
            .sidebar.open { transform: translateX(0); }
            .menu-toggle { display: block; }

            .status-ribbon { padding: 0 16px; height: auto; min-height: var(--ribbon-height); flex-wrap: wrap; gap: 4px; }
            .status-ribbon .left { gap: 10px; font-size: 9px; flex-wrap: wrap; }
            .status-ribbon .right { gap: 10px; font-size: 8px; }

            .top-bar { padding: 8px 16px; flex-wrap: wrap; gap: 6px; min-height: 44px; }
            .top-bar .title-section h1 { font-size: 13px; }

            .dashboard-content { padding: 14px 16px 0; }

            .mission-status { grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .middle-grid { grid-template-columns: 1fr; gap: 12px; }
        }

        @media (max-width: 640px) {
            .status-ribbon .left .tag { display: none; }
            .status-ribbon .left .sep { display: none; }

            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar .actions { justify-content: flex-end; }

            .mission-status { grid-template-columns: repeat(2, 1fr); gap: 6px; }
            .kpi-panel { padding: 8px 10px; }
            .kpi-panel .kpi-value { font-size: 16px; }

            .system-health { gap: 10px; padding: 6px 10px; }
            .system-health .health-item { font-size: 9px; }

            .dashboard-content { padding: 10px 10px 0; }

            th, td { padding: 4px 6px; font-size: 9px; }
            .panel-header { padding: 6px 10px; font-size: 8px; }
            .panel-body { padding: 6px 10px; }

            .page-footer { font-size: 7px; padding: 8px 0 12px; }
        }

        @media (max-width: 400px) {
            .mission-status { grid-template-columns: 1fr 1fr; }
            .kpi-panel .kpi-value { font-size: 14px; }
        }

        @media (min-width: 1920px) {
            .dashboard-content { padding: 28px 40px 0; max-width: 1800px; }
            .mission-status { gap: 14px; }
            .kpi-panel { padding: 16px 20px; }
            .kpi-panel .kpi-value { font-size: 26px; }
            .middle-grid { gap: 18px; }
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
            .page-footer { border-color: #2A3A5E; }
            .approval-item .ref { color: #A0AEC0; }
        }
    </style>
</head>
<body>
    <div class="app">
        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="brand">VOUCHMORPH</div>
                <div class="sub">National Disbursement Platform</div>
                <div class="org"><?php echo $orgName; ?></div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-group">Mission Control</div>
                <a href="index.php" class="nav-item active">
                    <span class="nav-icon">◆</span> Command Center
                </a>

                <div class="nav-group">Operations</div>
                <?php if ($config['show_actions']): ?>
                <a href="imports/upload.php" class="nav-item">
                    <span class="nav-icon">◈</span> Disbursements
                </a>
                <?php endif; ?>
                <a href="batches/index.php" class="nav-item">
                    <span class="nav-icon">▦</span> Batches
                    <?php if ($stats['pending'] > 0 && in_array($userRole, ['approver', 'senior_approver', 'owner'])): ?>
                    <span class="badge"><?php echo $stats['pending']; ?></span>
                    <?php endif; ?>
                </a>
                <?php if ($config['show_beneficiaries']): ?>
                <a href="beneficiaries/index.php" class="nav-item">
                    <span class="nav-icon">◈</span> Beneficiaries
                </a>
                <?php endif; ?>
                <a href="programs/index.php" class="nav-item">
                    <span class="nav-icon">▣</span> Programs
                </a>
                <a href="departments/index.php" class="nav-item">
                    <span class="nav-icon">▤</span> Departments
                </a>

                <div class="nav-group">Approvals</div>
                <a href="batches/index.php?filter=pending" class="nav-item">
                    <span class="nav-icon">◉</span> Pending Queue
                    <?php if ($stats['pending'] > 0): ?>
                    <span class="badge"><?php echo $stats['pending']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="batches/index.php?filter=threshold" class="nav-item">
                    <span class="nav-icon">⬡</span> Threshold Review
                </a>
                <a href="batches/index.php?filter=executed" class="nav-item">
                    <span class="nav-icon">▸</span> Executed
                </a>

                <div class="nav-group">Governance</div>
                <a href="reports/audit_trail.php" class="nav-item">
                    <span class="nav-icon">◧</span> Audit
                </a>
                <a href="reports/index.php" class="nav-item">
                    <span class="nav-icon">▥</span> Reports
                </a>

                <div class="nav-group">System</div>
                <a href="testgov.php" class="nav-item">
                    <span class="nav-icon">◈</span> Diagnostics
                </a>
                <?php if ($userRole === 'owner'): ?>
                <a href="settings/index.php" class="nav-item">
                    <span class="nav-icon">◆</span> Configuration
                </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="user-row">
                    <div class="avatar"><?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 1)); ?></div>
                    <div class="user-info">
                        <div class="name"><?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></div>
                        <div class="meta"><?php echo $roleDisplay; ?><?php if ($departmentName): ?> · <?php echo htmlspecialchars($departmentName); ?><?php endif; ?></div>
                    </div>
                    <a href="logout.php" class="logout-link" title="Sign out">↗</a>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main">
            <!-- Status Ribbon -->
            <div class="status-ribbon">
                <div class="left">
                    <span class="org-name"><?php echo $orgName; ?></span>
                    <span class="sep">|</span>
                    <span>NATIONAL DISBURSEMENT PLATFORM</span>
                    <span class="sep">|</span>
                    <span class="tag prod">● PRODUCTION</span>
                    <span class="tag">SECURE</span>
                </div>
                <div class="right">
                    <span>SESSION ACTIVE</span>
                    <span class="sep">|</span>
                    <span><span class="dot online"></span> 98% READY</span>
                    <span class="sep">|</span>
                    <span><?php echo date('H:i T'); ?></span>
                </div>
            </div>

            <!-- Top Bar -->
            <div class="top-bar">
                <div class="title-section">
                    <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle menu">≡</button>
                    <h1>National Disbursement Operations Center</h1>
                    <div class="sub"><?php echo $orgName; ?> · <?php echo date('l, F j, Y'); ?></div>
                </div>
                <div class="actions">
                    <span class="time">UTC+2</span>
                    <button class="btn-icon" title="Refresh" onclick="location.reload()">⟳</button>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="content-wrapper">
                    <!-- MISSION STATUS -->
                    <div class="mission-status">
                        <div class="kpi-panel">
                            <div class="kpi-label">Disbursed</div>
                            <div class="kpi-value"><?php echo formatCurrency($stats['disbursed']); ?></div>
                            <div class="kpi-trend up">▲ 8.2%</div>
                        </div>
                        <div class="kpi-panel">
                            <div class="kpi-label">Success Rate</div>
                            <div class="kpi-value"><?php echo number_format($stats['success_rate'], 2); ?>%</div>
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
                            <div class="kpi-trend up">Eligible</div>
                        </div>
                        <div class="kpi-panel">
                            <div class="kpi-label">Programs</div>
                            <div class="kpi-value"><?php echo number_format($stats['programs']); ?></div>
                            <div class="kpi-trend up">Active</div>
                        </div>
                        <div class="kpi-panel">
                            <div class="kpi-label">Departments</div>
                            <div class="kpi-value"><?php echo number_format($stats['departments']); ?></div>
                            <div class="kpi-trend up">Connected</div>
                        </div>
                    </div>

                    <!-- SYSTEM HEALTH -->
                    <div class="system-health">
                        <span class="health-item"><span class="dot online"></span> Database</span>
                        <span class="health-item"><span class="dot online"></span> Identity Router</span>
                        <span class="health-item"><span class="dot online"></span> Swap Engine</span>
                        <span class="health-item"><span class="dot online"></span> Bank Connectors</span>
                        <span class="health-item"><span class="dot online"></span> Audit Service</span>
                        <span class="health-item"><span class="dot online"></span> Security</span>
                    </div>

                    <!-- MIDDLE GRID -->
                    <div class="middle-grid">
                        <!-- Pending Approvals -->
                        <div class="panel">
                            <div class="panel-header">
                                Pending Approvals
                                <span class="badge-count"><?php echo $stats['pending']; ?></span>
                            </div>
                            <div class="panel-body">
                                <?php if ($stats['pending'] > 0): ?>
                                <div class="approval-item">
                                    <span class="ref">GOV-2026-440</span>
                                    <span class="amount">BWP 12,450</span>
                                    <span class="status-tag pending">Pending</span>
                                </div>
                                <div class="approval-item">
                                    <span class="ref">GOV-2026-439</span>
                                    <span class="amount">BWP 8,230</span>
                                    <span class="status-tag pending">Pending</span>
                                </div>
                                <div class="approval-item">
                                    <span class="ref">GOV-2026-438</span>
                                    <span class="amount">BWP 125,000</span>
                                    <span class="status-tag threshold">▲ Threshold</span>
                                </div>
                                <a href="batches/index.php?filter=pending" class="view-all-link">View all →</a>
                                <?php else: ?>
                                <div class="empty-state">
                                    <span class="icon">◯</span>
                                    <p>No pending approvals</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Pipeline -->
                        <div class="panel">
                            <div class="panel-header">
                                Disbursement Pipeline
                                <span class="badge-count">72%</span>
                            </div>
                            <div class="panel-body">
                                <div class="pipeline-steps">
                                    <span class="step done">Draft</span>
                                    <span class="step done">Approved</span>
                                    <span class="step active">Executing</span>
                                    <span class="step">Completed</span>
                                </div>
                                <div class="pipeline-progress">
                                    <div class="fill" style="width:72%;"></div>
                                </div>
                                <div class="pipeline-stats">
                                    <span>12 Draft</span>
                                    <span>8 Approved</span>
                                    <span>18 Executing</span>
                                    <span>47 Completed</span>
                                </div>
                            </div>
                        </div>

                        <!-- Live Feed -->
                        <div class="panel">
                            <div class="panel-header">
                                Live Operations
                                <span class="badge-live">● Live</span>
                            </div>
                            <div class="panel-body">
                                <div class="feed-item">
                                    <span class="time">15:34</span>
                                    <span class="event"><strong>Approved</strong> Batch GOV-2026-440</span>
                                </div>
                                <div class="feed-item">
                                    <span class="time">15:31</span>
                                    <span class="event"><strong>Imported</strong> 2,100 beneficiaries</span>
                                </div>
                                <div class="feed-item">
                                    <span class="time">15:29</span>
                                    <span class="event"><strong>Verified</strong> Identity Provider</span>
                                </div>
                                <div class="feed-item">
                                    <span class="time">15:27</span>
                                    <span class="event"><strong>Completed</strong> Treasury approval</span>
                                </div>
                                <div class="feed-item">
                                    <span class="time">15:20</span>
                                    <span class="event"><strong>Created</strong> Audit record</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Batches -->
                    <div class="panel" style="margin-bottom:16px;">
                        <div class="panel-header">
                            Recent Batches
                            <?php if ($config['show_all_batches']): ?>
                            <a href="batches/index.php" style="font-size:8px; color:var(--blue); text-decoration:none; font-weight:600;">View all →</a>
                            <?php endif; ?>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Description</th>
                                        <th>Program</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentBatches)): ?>
                                        <?php foreach ($recentBatches as $batch):
                                            $status = strtolower($batch['status'] ?? 'draft');
                                            $statusDisplay = ucwords(str_replace('_', ' ', $batch['status'] ?? 'Draft'));
                                        ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($batch['batch_reference'] ?? '#' . $batch['id']); ?></code></td>
                                            <td><?php echo htmlspecialchars($batch['batch_name'] ?? 'Batch #' . $batch['id']); ?></td>
                                            <td><?php echo htmlspecialchars($batch['program_name'] ?? '—'); ?></td>
                                            <td><strong><?php echo formatCurrency($batch['total_amount'] ?? 0); ?></strong></td>
                                            <td><span class="status-badge <?php echo $status; ?>"><?php echo htmlspecialchars($statusDisplay); ?></span></td>
                                            <td><?php echo date('M d', strtotime($batch['created_at'] ?? 'now')); ?></td>
                                            <td><a href="batches/view.php?id=<?php echo $batch['id']; ?>" class="action-link">Review</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">
                                                    <span class="icon">◻</span>
                                                    <p>No batches yet. <a href="imports/upload.php">Create first batch →</a></p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Footer -->
                    <footer class="page-footer">
                        <span>Secure National Disbursement Platform · ISO 27001 Certified · © <?php echo date('Y'); ?> VouchMorph · Government of Botswana</span>
                        <div class="role-line">
                            <?php echo $roleDisplay; ?> · <?php echo $orgName; ?><?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?>
                        </div>
                    </footer>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('active');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSidebar();
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) closeSidebar();
        });
    </script>
</body>
</html>
