<?php
// index.php - VOUCHMORPH NATIONAL DISBURSEMENT REGISTRY
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
$fileRef = 'VM/' . date('Y') . '/' . date('md') . '-' . str_pad((string)($stats['pending'] + $stats['programs']), 3, '0', STR_PAD_LEFT);

function formatCurrency($amount) {
    return 'BWP ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph · National Disbursement Registry</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           TOKENS
           ============================================================ */
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
            --seal-red-tint:#F6E9E7;
            --amber:        #8A5A0B;
            --amber-tint:   #F6EEDD;
            --ledger-green: #24513A;
            --green-tint:   #E5EEE7;
            --blue-tint:    #E7EEF4;

            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;

            --sidebar-width: 216px;
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
            display: flex;
            min-height: 100vh;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--line-strong); }

        a { color: inherit; }

        /* ============================================================
           SIGNATURE: registration corner-marks on every doc panel
           ============================================================ */
        .doc-panel {
            position: relative;
            background: var(--panel);
            border: 1px solid var(--line);
        }
        .doc-panel::before,
        .doc-panel::after {
            content: "";
            position: absolute;
            width: 9px;
            height: 9px;
            pointer-events: none;
        }
        .doc-panel::before {
            top: -1px; left: -1px;
            border-top: 2px solid var(--brass);
            border-left: 2px solid var(--brass);
        }
        .doc-panel::after {
            bottom: -1px; right: -1px;
            border-bottom: 2px solid var(--brass);
            border-right: 2px solid var(--brass);
        }

        /* ============================================================
           SIGNATURE: ink-stamp status marks
           ============================================================ */
        .stamp {
            display: inline-block;
            padding: 2px 8px;
            border: 1.5px solid currentColor;
            transform: rotate(-2.5deg);
            font-family: var(--f-mono);
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .stamp.completed  { color: var(--ledger-green); }
        .stamp.processing { color: var(--ink-700); }
        .stamp.pending     { color: var(--amber); }
        .stamp.failed      { color: var(--seal-red); }
        .stamp.draft       { color: var(--ink-300); }
        .stamp.threshold   { color: var(--seal-red); background: var(--seal-red-tint); }

        /* ============================================================
           EYEBROW / SECTION MARK utility
           ============================================================ */
        .eyebrow {
            font-family: var(--f-cond);
            font-weight: 600;
            font-size: 10px;
            letter-spacing: 0.11em;
            text-transform: uppercase;
            color: var(--ink-500);
        }
        .section-mark { color: var(--brass); font-weight: 700; margin-right: 5px; }

        /* ============================================================
           APP SHELL
           ============================================================ */
        .app { display: flex; width: 100%; min-height: 100vh; }

        /* ============================================================
           SIDEBAR — "Registry Index"
           ============================================================ */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--ink-900);
            color: white;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            flex-shrink: 0;
            border-right: 3px solid var(--brass);
        }

        .sidebar-header { padding: 20px 18px 14px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header .brand-row { display: flex; align-items: center; gap: 8px; }
        .sidebar-header .brand { font-family: var(--f-mono); font-weight: 700; font-size: 15px; letter-spacing: 0.02em; color: white; }
        .sidebar-header .brand em { color: var(--brass); font-style: normal; }
        .sidebar-header .sub { font-family: var(--f-cond); font-size: 9px; font-weight: 600; letter-spacing: 0.14em; color: rgba(255,255,255,0.35); text-transform: uppercase; margin-top: 4px; }
        .sidebar-header .org {
            font-family: var(--f-mono); font-size: 9px; color: rgba(255,255,255,0.45);
            margin-top: 12px; padding-top: 10px; border-top: 1px dashed rgba(255,255,255,0.15);
            letter-spacing: 0.03em;
        }

        .sidebar-nav { padding: 10px 10px; overflow-y: auto; flex: 1; }
        .nav-group {
            font-family: var(--f-cond); font-size: 9px; text-transform: uppercase; letter-spacing: 0.14em;
            color: rgba(255,255,255,0.28); padding: 14px 8px 4px; font-weight: 700;
        }
        .nav-group:first-child { padding-top: 4px; }

        .nav-item {
            display: flex; align-items: center; gap: 4px;
            padding: 7px 8px;
            color: rgba(255,255,255,0.55);
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 500;
            border-left: 2px solid transparent;
            transition: background 0.12s ease, color 0.12s ease;
        }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: rgba(255,255,255,0.85); }
        .nav-item.active { background: rgba(138,109,59,0.16); color: var(--brass); border-left-color: var(--brass); font-weight: 600; }
        .nav-item .mark { font-family: var(--f-mono); opacity: 0.65; font-size: 11px; width: 13px; }
        .nav-item.active .mark { opacity: 1; }
        .nav-item .count {
            margin-left: auto; background: var(--brass); color: var(--ink-900);
            font-size: 9px; font-weight: 700; padding: 1px 5px; font-family: var(--f-mono);
        }

        .sidebar-footer { padding: 12px 16px; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-footer .user-row { display: flex; align-items: center; gap: 9px; }
        .sidebar-footer .avatar {
            width: 27px; height: 27px; background: var(--brass); flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--f-mono); font-weight: 700; font-size: 10px; color: var(--ink-900);
        }
        .sidebar-footer .user-info { flex: 1; min-width: 0; }
        .sidebar-footer .name { font-size: 11.5px; font-weight: 600; color: rgba(255,255,255,0.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-footer .meta { font-family: var(--f-cond); font-size: 9px; color: rgba(255,255,255,0.35); text-transform: uppercase; letter-spacing: 0.08em; }
        .sidebar-footer .logout { color: rgba(255,255,255,0.3); text-decoration: none; font-size: 14px; }
        .sidebar-footer .logout:hover { color: var(--brass); }

        /* ============================================================
           MAIN
           ============================================================ */
        .main { flex: 1; display: flex; flex-direction: column; min-height: 100vh; min-width: 0; }

        /* ============================================================
           MASTHEAD — official document header
           ============================================================ */
        .masthead {
            background: var(--ink-900);
            color: white;
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            flex-wrap: wrap;
            gap: 10px;
        }
        .masthead .left { display: flex; align-items: center; gap: 14px; }
        .seal {
            width: 38px; height: 38px; border-radius: 50%;
            border: 1.5px solid var(--brass);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; position: relative;
        }
        .seal::before {
            content: ""; position: absolute; inset: 4px; border-radius: 50%;
            border: 1px solid rgba(138,109,59,0.5);
        }
        .seal span { font-family: var(--f-mono); font-weight: 700; font-size: 11px; color: var(--brass); letter-spacing: -0.02em; }
        .masthead .titling h1 { font-size: 15px; font-weight: 600; letter-spacing: 0.01em; }
        .masthead .titling .file-ref { font-family: var(--f-mono); font-size: 10px; color: rgba(255,255,255,0.4); margin-top: 2px; letter-spacing: 0.02em; }
        .masthead .right { display: flex; align-items: center; gap: 14px; }
        .classification {
            font-family: var(--f-cond); font-size: 9px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase;
            color: #E7B8B2; border: 1px solid #8C3A30; padding: 3px 8px; transform: rotate(-1.5deg);
        }
        .clock { font-family: var(--f-mono); font-size: 11px; color: rgba(255,255,255,0.55); }
        .status-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #5FAE7E; margin-right: 5px; }
        .menu-toggle { display: none; background: none; border: none; color: white; font-size: 18px; cursor: pointer; }

        /* ============================================================
           CONTENT
           ============================================================ */
        .content { flex: 1; padding: 22px 28px 0; max-width: 1560px; width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 16px; }

        /* ============================================================
           STATEMENT OF ACCOUNT — hero ledger strip
           ============================================================ */
        .statement { padding: 20px 24px 16px; }
        .statement-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 14px; }
        .statement-head .eyebrow { }
        .statement-head .period { font-family: var(--f-mono); font-size: 10px; color: var(--ink-300); }
        .statement-row { display: flex; align-items: flex-end; gap: 0; flex-wrap: wrap; }
        .statement-item { padding: 0 22px; border-left: 1px solid var(--line); }
        .statement-item:first-child { border-left: none; padding-left: 0; }
        .statement-item .label { font-family: var(--f-cond); font-size: 10px; font-weight: 600; letter-spacing: 0.09em; text-transform: uppercase; color: var(--ink-500); }
        .statement-item .value { font-family: var(--f-mono); font-weight: 700; margin-top: 6px; font-variant-numeric: tabular-nums; }
        .statement-item .trend { font-family: var(--f-cond); font-size: 10px; font-weight: 600; margin-top: 5px; letter-spacing: 0.02em; }
        .trend.up { color: var(--ledger-green); }
        .trend.neutral { color: var(--ink-300); }

        .statement-item.hero { padding-left: 0; }
        .statement-item.hero .value {
            font-size: 30px;
            border-bottom: 4px double var(--ink-900);
            padding-bottom: 8px;
            display: inline-block;
        }
        .statement-item:not(.hero) .value { font-size: 19px; color: var(--ink-700); }

        /* ============================================================
           SYSTEM STRIP
           ============================================================ */
        .system-strip {
            display: flex; flex-wrap: wrap; gap: 22px;
            padding: 9px 24px;
        }
        .system-strip .item { display: flex; align-items: center; gap: 6px; font-family: var(--f-cond); font-size: 10.5px; font-weight: 600; letter-spacing: 0.04em; color: var(--ink-500); text-transform: uppercase; }
        .system-strip .dot { width: 5px; height: 5px; border-radius: 50%; background: var(--ledger-green); }

        /* ============================================================
           MIDDLE GRID
           ============================================================ */
        .middle-grid { display: grid; grid-template-columns: 1fr 1.15fr 1fr; gap: 14px; }

        .panel-head {
            padding: 9px 16px;
            background: var(--brass-tint);
            border-bottom: 1px solid var(--line);
            display: flex; justify-content: space-between; align-items: center;
        }
        .panel-head .eyebrow { color: var(--ink-700); }
        .panel-head .count-chip { font-family: var(--f-mono); font-size: 10px; font-weight: 700; color: white; background: var(--ink-900); padding: 1px 6px; }
        .panel-body { padding: 12px 16px; }

        /* Schedule A — pending approvals */
        .sched-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--line); }
        .sched-row:last-of-type { border-bottom: none; }
        .sched-row .ref { font-family: var(--f-mono); font-size: 11px; font-weight: 600; color: var(--ink-700); }
        .sched-row .amt { font-family: var(--f-mono); font-size: 11.5px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .view-all { display: inline-block; margin-top: 10px; font-family: var(--f-cond); font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-decoration: none; color: var(--ink-700); border-bottom: 1px solid var(--brass); }

        /* Processing status */
        .pipeline-steps { display: flex; gap: 5px; }
        .pipeline-steps .step {
            flex: 1; padding: 7px 4px; text-align: center;
            font-family: var(--f-cond); font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
            border: 1px solid var(--line); color: var(--ink-300);
        }
        .pipeline-steps .step.done { border-color: var(--ledger-green); color: var(--ledger-green); background: var(--green-tint); }
        .pipeline-steps .step.active { border-color: var(--ink-900); color: var(--ink-900); background: var(--blue-tint); }
        .pipeline-bar { height: 5px; background: var(--line); margin: 12px 0 6px; position: relative; }
        .pipeline-bar .fill { height: 100%; background: var(--ledger-green); width: 72%; }
        .pipeline-figures { display: flex; justify-content: space-between; }
        .pipeline-figures div { text-align: center; }
        .pipeline-figures .n { font-family: var(--f-mono); font-weight: 700; font-size: 15px; display: block; }
        .pipeline-figures .l { font-family: var(--f-cond); font-size: 8.5px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ink-300); }

        /* Activity log */
        .log-row { display: flex; gap: 10px; padding: 6px 0; border-bottom: 1px solid var(--line); font-size: 11px; color: var(--ink-500); }
        .log-row:last-child { border-bottom: none; }
        .log-row .t { font-family: var(--f-mono); font-size: 10px; color: var(--ink-300); flex-shrink: 0; width: 36px; }
        .log-row strong { color: var(--ink-900); font-weight: 600; }
        .live-flag { font-family: var(--f-cond); font-size: 9px; font-weight: 700; color: var(--ledger-green); letter-spacing: 0.06em; text-transform: uppercase; }
        .live-flag::before { content: "●"; margin-right: 3px; animation: blink 1.8s infinite; }
        @keyframes blink { 0%,100%{opacity:1;} 50%{opacity:0.25;} }

        /* ============================================================
           REGISTER TABLE
           ============================================================ */
        .register-wrap { flex: 1; display: flex; flex-direction: column; min-height: 0; padding-bottom: 0; }
        .table-scroll { max-height: 340px; overflow-y: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 10px 16px; text-align: left; border-bottom: 1px solid var(--line); }
        th {
            background: var(--brass-tint); font-family: var(--f-cond); font-weight: 700; font-size: 10px;
            color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.07em;
            border-bottom: 2px solid var(--line-strong); position: sticky; top: 0;
        }
        tbody tr:nth-child(even) td { background: #F8FAF8; }
        tbody tr:hover td { background: var(--blue-tint); }
        td code { font-family: var(--f-mono); font-size: 11px; font-weight: 600; color: var(--ink-700); background: var(--brass-tint); padding: 1px 5px; }
        td .amt { font-family: var(--f-mono); font-weight: 700; font-variant-numeric: tabular-nums; }
        .action-link { font-family: var(--f-cond); font-weight: 700; font-size: 11px; letter-spacing: 0.04em; text-decoration: none; color: var(--ink-700); border-bottom: 1px solid var(--brass); }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state { text-align: center; padding: 26px 12px; color: var(--ink-300); }
        .empty-state .mark { font-family: var(--f-mono); font-size: 20px; display: block; margin-bottom: 8px; color: var(--brass); }
        .empty-state p { font-size: 11.5px; }
        .empty-state a { color: var(--ink-700); font-weight: 600; text-decoration: none; border-bottom: 1px solid var(--brass); }

        /* ============================================================
           FOOTER — official notice
           ============================================================ */
        .page-footer {
            padding: 16px 0 22px;
            text-align: center;
            border-top: 1px solid var(--line);
            margin-top: 6px;
        }
        .page-footer .notice { font-family: var(--f-cond); font-size: 9.5px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ink-300); }
        .page-footer .role-line { font-family: var(--f-mono); font-size: 9px; color: var(--ink-300); margin-top: 4px; }

        /* ============================================================
           SIDEBAR OVERLAY (mobile)
           ============================================================ */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(15,33,56,0.6); z-index: 99; }
        .sidebar-overlay.active { display: block; }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1180px) {
            .middle-grid { grid-template-columns: 1fr 1fr; }
            .middle-grid .panel:nth-child(3) { grid-column: 1 / -1; }
            .statement-row { row-gap: 14px; }
        }
        @media (max-width: 960px) {
            .sidebar { position: fixed; top: 0; left: 0; transform: translateX(-100%); z-index: 100; transition: transform 0.2s ease; }
            .sidebar.open { transform: translateX(0); }
            .menu-toggle { display: block; }
            .content { padding: 16px 16px 0; }
            .middle-grid { grid-template-columns: 1fr; }
            .middle-grid .panel:nth-child(3) { grid-column: auto; }
            .statement-item { border-left: none; padding: 10px 0 0; border-top: 1px solid var(--line); }
            .statement-item:first-child { border-top: none; }
            .statement-row { flex-direction: column; }
        }
        @media (max-width: 600px) {
            .masthead { padding: 12px 16px; }
            .classification { display: none; }
            .statement { padding: 16px 16px 12px; }
            .statement-item.hero .value { font-size: 24px; }
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
            tbody tr:nth-child(even) td { background: #182129; }
        }
    </style>
</head>
<body>
    <div class="app">
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="brand-row">
                    <span class="brand">VOUCH<em>MORPH</em></span>
                </div>
                <div class="sub">National Disbursement Registry</div>
                <div class="org"><?php echo substr($orgName, 0, 26); ?></div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-group">Mission</div>
                <a href="index.php" class="nav-item active"><span class="mark">§</span>Command</a>
                <?php if ($config['show_actions']): ?>
                <a href="imports/upload.php" class="nav-item"><span class="mark">§</span>Disburse</a>
                <?php endif; ?>
                <a href="batches/index.php" class="nav-item"><span class="mark">§</span>Batches <?php if ($stats['pending'] > 0): ?><span class="count"><?php echo $stats['pending']; ?></span><?php endif; ?></a>
                <?php if ($config['show_beneficiaries']): ?>
                <a href="beneficiaries/index.php" class="nav-item"><span class="mark">§</span>Beneficiaries</a>
                <?php endif; ?>
                <div class="nav-group">Approvals</div>
                <a href="batches/index.php?filter=pending" class="nav-item"><span class="mark">§</span>Pending <?php if ($stats['pending'] > 0): ?><span class="count"><?php echo $stats['pending']; ?></span><?php endif; ?></a>
                <div class="nav-group">Governance</div>
                <a href="reports/audit_trail.php" class="nav-item"><span class="mark">§</span>Audit</a>
                <a href="reports/index.php" class="nav-item"><span class="mark">§</span>Reports</a>
                <div class="nav-group">System</div>
                <a href="testgov.php" class="nav-item"><span class="mark">§</span>Diagnostics</a>
            </nav>
            <div class="sidebar-footer">
                <div class="user-row">
                    <div class="avatar"><?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 1)); ?></div>
                    <div class="user-info">
                        <div class="name"><?php echo substr($user['full_name'] ?? $user['email'], 0, 14); ?></div>
                        <div class="meta"><?php echo $roleDisplay; ?></div>
                    </div>
                    <a href="logout.php" class="logout" title="Sign out">↗</a>
                </div>
            </div>
        </aside>

        <!-- Main -->
        <main class="main">
            <!-- Masthead -->
            <div class="masthead">
                <div class="left">
                    <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle menu">≡</button>
                    <div class="seal"><span>VM</span></div>
                    <div class="titling">
                        <h1><?php echo $orgName; ?> — National Disbursement</h1>
                        <div class="file-ref">FILE NO. <?php echo htmlspecialchars($fileRef); ?> &nbsp;·&nbsp; <?php echo date('d M Y'); ?></div>
                    </div>
                </div>
                <div class="right">
                    <span class="classification">Official · Restricted</span>
                    <span class="clock"><span class="status-dot"></span><?php echo date('H:i'); ?> UTC+2</span>
                </div>
            </div>

            <!-- Content -->
            <div class="content">

                <!-- Statement of Account -->
                <section class="statement doc-panel">
                    <div class="statement-head">
                        <span class="eyebrow"><span class="section-mark">§1</span>Statement of Account</span>
                        <span class="period">Period ending <?php echo date('d M Y'); ?></span>
                    </div>
                    <div class="statement-row">
                        <div class="statement-item hero">
                            <div class="label">Total Disbursed</div>
                            <div class="value"><?php echo formatCurrency($stats['disbursed']); ?></div>
                            <div class="trend up">▲ 8.2% vs. prior period</div>
                        </div>
                        <div class="statement-item">
                            <div class="label">Success Rate</div>
                            <div class="value"><?php echo number_format($stats['success_rate'], 1); ?>%</div>
                            <div class="trend up">▲ 0.02%</div>
                        </div>
                        <div class="statement-item">
                            <div class="label">Awaiting Approval</div>
                            <div class="value"><?php echo number_format($stats['pending']); ?></div>
                            <div class="trend neutral">Held for review</div>
                        </div>
                        <div class="statement-item">
                            <div class="label">Beneficiaries</div>
                            <div class="value"><?php echo number_format($stats['beneficiaries']); ?></div>
                            <div class="trend neutral">On register</div>
                        </div>
                        <div class="statement-item">
                            <div class="label">Active Programs</div>
                            <div class="value"><?php echo number_format($stats['programs']); ?></div>
                            <div class="trend neutral">In force</div>
                        </div>
                        <div class="statement-item">
                            <div class="label">Departments</div>
                            <div class="value"><?php echo number_format($stats['departments']); ?></div>
                            <div class="trend neutral">Reporting</div>
                        </div>
                    </div>
                </section>

                <!-- System strip -->
                <div class="system-strip doc-panel">
                    <span class="item"><span class="dot"></span>Database</span>
                    <span class="item"><span class="dot"></span>Identity</span>
                    <span class="item"><span class="dot"></span>Swap Engine</span>
                    <span class="item"><span class="dot"></span>Banking Rails</span>
                    <span class="item"><span class="dot"></span>Audit Log</span>
                    <span class="item"><span class="dot"></span>Security</span>
                </div>

                <!-- Middle grid -->
                <div class="middle-grid">
                    <div class="panel doc-panel">
                        <div class="panel-head">
                            <span class="eyebrow"><span class="section-mark">§2</span>Schedule A — Pending Authorisation</span>
                            <span class="count-chip"><?php echo $stats['pending']; ?></span>
                        </div>
                        <div class="panel-body">
                            <?php if ($stats['pending'] > 0): ?>
                            <div class="sched-row"><span class="ref">GOV-440</span><span class="amt">BWP 12,400.00</span><span class="stamp pending">Pending</span></div>
                            <div class="sched-row"><span class="ref">GOV-439</span><span class="amt">BWP 8,200.00</span><span class="stamp pending">Pending</span></div>
                            <div class="sched-row"><span class="ref">GOV-438</span><span class="amt">BWP 125,000.00</span><span class="stamp threshold">Over limit</span></div>
                            <a href="batches/index.php?filter=pending" class="view-all">View schedule →</a>
                            <?php else: ?>
                            <div class="empty-state"><span class="mark">§</span><p>Nothing pending authorisation.</p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="panel doc-panel">
                        <div class="panel-head">
                            <span class="eyebrow"><span class="section-mark">§3</span>Processing Status</span>
                            <span class="count-chip">72%</span>
                        </div>
                        <div class="panel-body">
                            <div class="pipeline-steps">
                                <span class="step done">Draft</span>
                                <span class="step done">Approved</span>
                                <span class="step active">Executing</span>
                                <span class="step">Complete</span>
                            </div>
                            <div class="pipeline-bar"><div class="fill"></div></div>
                            <div class="pipeline-figures">
                                <div><span class="n">12</span><span class="l">Draft</span></div>
                                <div><span class="n">8</span><span class="l">Approved</span></div>
                                <div><span class="n">18</span><span class="l">Executing</span></div>
                                <div><span class="n">47</span><span class="l">Complete</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="panel doc-panel">
                        <div class="panel-head">
                            <span class="eyebrow"><span class="section-mark">§4</span>Activity Log</span>
                            <span class="live-flag">Live</span>
                        </div>
                        <div class="panel-body">
                            <div class="log-row"><span class="t">15:34</span><span><strong>Approved</strong> — batch GOV-440</span></div>
                            <div class="log-row"><span class="t">15:31</span><span><strong>Imported</strong> — 2,100 beneficiaries</span></div>
                            <div class="log-row"><span class="t">15:29</span><span><strong>Verified</strong> — identity checks cleared</span></div>
                            <div class="log-row"><span class="t">15:27</span><span><strong>Completed</strong> — treasury settlement</span></div>
                            <div class="log-row"><span class="t">15:20</span><span><strong>Created</strong> — audit record</span></div>
                        </div>
                    </div>
                </div>

                <!-- Register table -->
                <div class="register-wrap panel doc-panel">
                    <div class="panel-head">
                        <span class="eyebrow"><span class="section-mark">§5</span>Register of Recent Batches</span>
                        <?php if ($config['show_all_batches']): ?>
                        <a href="batches/index.php" class="action-link">View full register →</a>
                        <?php endif; ?>
                    </div>
                    <div class="table-scroll">
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
                                        <td><?php echo htmlspecialchars(substr($batch['batch_name'] ?? 'Batch #' . $batch['id'], 0, 24)); ?></td>
                                        <td><?php echo htmlspecialchars($batch['program_name'] ?? '—'); ?></td>
                                        <td><span class="amt"><?php echo formatCurrency($batch['total_amount'] ?? 0); ?></span></td>
                                        <td><span class="stamp <?php echo $status; ?>"><?php echo htmlspecialchars($statusDisplay); ?></span></td>
                                        <td><?php echo date('d M', strtotime($batch['created_at'] ?? 'now')); ?></td>
                                        <td><a href="batches/view.php?id=<?php echo $batch['id']; ?>" class="action-link">Review</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7"><div class="empty-state"><span class="mark">§</span><p>No batches on record. <a href="imports/upload.php">Create the first entry →</a></p></div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Footer -->
                <footer class="page-footer">
                    <div class="notice">System-generated statement · Distribution restricted · ISO 27001 · © <?php echo date('Y'); ?> VouchMorph</div>
                    <div class="role-line"><?php echo $roleDisplay; ?><?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?> · <?php echo htmlspecialchars($fileRef); ?></div>
                </footer>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); document.getElementById('sidebarOverlay').classList.toggle('active'); }
        function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeSidebar(); });
        window.addEventListener('resize', function() { if (window.innerWidth > 960) closeSidebar(); });
    </script>
</body>
</html>
