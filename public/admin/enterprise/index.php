<?php
// index.php - VOUCHMORPH NATIONAL DISBURSEMENT REGISTRY
require_once 'auth.php';
$user = requireEnterpriseAuth();

$pdo = getDBConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';
$departmentId = $user['department_id'] ?? null;

// ============================================================
// ROLE-BASED DATA FETCHING — every figure below comes from the database.
// No placeholder rows, no sample activity, no synthetic totals.
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
        ORDER BY created_at DESC LIMIT 6
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
        'total_batches' => $currentMonth['total'],
        'success_rate' => $successRate,
        'pending' => $pendingApproval['count'],
        'pending_amount' => $pendingApproval['amount'],
        'beneficiaries' => $beneficiaryCount,
        'successful_payments' => $successData['completed'],
        'programs' => $programCount,
        'departments' => $departmentCount
    ];

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats = ['disbursed' => 0, 'total_batches' => 0, 'success_rate' => 0, 'pending' => 0, 'pending_amount' => 0, 'beneficiaries' => 0, 'successful_payments' => 0, 'programs' => 0, 'departments' => 0];
    $recentBatches = [];
}

$config = [
    'show_actions' => in_array($userRole, ['owner', 'program_officer', 'department_head']),
    'show_beneficiaries' => in_array($userRole, ['owner', 'auditor', 'program_officer', 'beneficiary_registrar', 'department_head', 'viewer']),
    'show_all_batches' => !in_array($userRole, ['beneficiary_registrar']),
    'show_governance' => in_array($userRole, ['owner', 'auditor', 'department_head']),
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

// ============================================================
// ROLE-BASED ACTION BUTTONS — each entry maps to a real working page.
// Badge values are pulled straight from $stats (real DB counts), or omitted.
// ============================================================
$actions = [];
if ($config['show_actions']) {
    $actions[] = ['label' => 'DISBURSE FUNDS', 'href' => 'imports/upload.php', 'mark' => '§1', 'badge' => null];
}
if ($config['show_all_batches']) {
    $actions[] = ['label' => 'BATCHES', 'href' => 'batches/index.php', 'mark' => '§2', 'badge' => $stats['total_batches'] > 0 ? $stats['total_batches'] : null];
}
$actions[] = ['label' => 'PENDING APPROVALS', 'href' => 'batches/index.php?filter=pending', 'mark' => '§3', 'badge' => $stats['pending'] > 0 ? $stats['pending'] : null];
if ($config['show_beneficiaries']) {
    $actions[] = ['label' => 'BENEFICIARIES', 'href' => 'beneficiaries/index.php', 'mark' => '§4', 'badge' => $stats['beneficiaries'] > 0 ? $stats['beneficiaries'] : null];
}
if ($config['show_governance']) {
    $actions[] = ['label' => 'AUDIT TRAIL', 'href' => 'reports/audit_trail.php', 'mark' => '§5', 'badge' => null];
}
$actions[] = ['label' => 'REPORTS', 'href' => 'reports/index.php', 'mark' => '§6', 'badge' => null];
if ($userRole === 'owner') {
    $actions[] = ['label' => 'DIAGNOSTICS', 'href' => 'testgov.php', 'mark' => '§7', 'badge' => null];
}

function formatCurrency($amount) {
    return 'BWP ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · NATIONAL DISBURSEMENT REGISTRY</title>
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
           MASTHEAD - BIGGER FONTS, MORE SPACING
           ============================================================ */
        .masthead {
            background: var(--ink-900); color: white; padding: 16px 32px;
            display: flex; align-items: center; justify-content: center; position: relative;
            border-bottom: 3px solid var(--brass);
        }
        .masthead .center { display: flex; align-items: center; gap: 18px; text-align: left; }
        .seal { width: 48px; height: 48px; border-radius: 50%; border: 2px solid var(--brass); display: flex; align-items: center; justify-content: center; position: relative; flex-shrink: 0; }
        .seal::before { content: ""; position: absolute; inset: 6px; border-radius: 50%; border: 1px solid rgba(138,109,59,0.5); }
        .seal span { font-family: var(--f-mono); font-weight: 700; font-size: 14px; color: var(--brass); }
        .masthead h1 { font-size: 19px; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; }
        .masthead .file-ref { font-family: var(--f-mono); font-size: 13px; color: rgba(255,255,255,0.4); margin-top: 2px; text-transform: uppercase; }
        .masthead .right-fixed {
            position: absolute; right: 32px; top: 50%; transform: translateY(-50%);
            display: flex; align-items: center; gap: 18px; font-family: var(--f-mono); font-size: 13px; color: rgba(255,255,255,0.6);
        }
        .status-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #5FAE7E; margin-right: 5px; }
        .role-pill { font-family: var(--f-cond); font-size: 11px; font-weight: 700; letter-spacing: 0.08em; color: var(--brass); border: 1px solid var(--brass); padding: 2px 10px; text-transform: uppercase; }

        /* ============================================================
           CENTRAL LAYOUT - PUSHED DOWN
           ============================================================ */
        .stage {
            flex: 1; width: 100%; display: flex; flex-direction: column; align-items: center;
            padding: 140px 20px 60px;
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

        .reveal-controls { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 6px; }
        .reveal-btn {
            font-family: var(--f-cond); font-weight: 700; font-size: 11.5px; letter-spacing: 0.08em; text-transform: uppercase;
            background: var(--ink-900); color: white; border: none; padding: 11px 22px;
        }
        .reveal-btn:hover { background: var(--brass); color: var(--ink-900); }
        .reveal-btn.is-active { background: var(--brass); color: var(--ink-900); }

        .reveal-panel {
            width: 100%;
            display: none;
            animation: fadeIn 0.15s ease;
        }
        .reveal-panel.is-open { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

        .statement { padding: 20px 24px 16px; }
        .statement-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 6px; }
        .statement-head .period { font-family: var(--f-mono); font-size: 10px; color: var(--ink-300); text-transform: uppercase; }
        .statement-row { display: flex; align-items: flex-end; gap: 0; flex-wrap: wrap; justify-content: center; }
        .statement-item { padding: 0 22px; border-left: 1px solid var(--line); text-align: center; }
        .statement-item:first-child { border-left: none; padding-left: 0; }
        .statement-item .label { font-family: var(--f-cond); font-size: 10px; font-weight: 700; letter-spacing: 0.09em; text-transform: uppercase; color: var(--ink-500); }
        .statement-item .value { font-family: var(--f-mono); font-weight: 700; margin-top: 6px; font-variant-numeric: tabular-nums; }
        .statement-item.hero .value { font-size: 28px; border-bottom: 4px double var(--ink-900); padding-bottom: 8px; display: inline-block; }
        .statement-item:not(.hero) .value { font-size: 18px; color: var(--ink-700); }

        .register-wrap { width: 100%; }
        .panel-head {
            padding: 9px 16px; background: var(--brass-tint); border-bottom: 1px solid var(--line);
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;
        }
        .panel-head .eyebrow { color: var(--ink-700); }
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
        td code { font-family: var(--f-mono); font-size: 11px; font-weight: 600; color: var(--ink-700); background: var(--brass-tint); padding: 1px 5px; text-transform: uppercase; }
        td .amt { font-family: var(--f-mono); font-weight: 700; font-variant-numeric: tabular-nums; }
        .action-link { font-family: var(--f-cond); font-weight: 700; font-size: 11px; letter-spacing: 0.06em; text-decoration: none; color: var(--ink-700); border-bottom: 1px solid var(--brass); text-transform: uppercase; }

        .empty-state { text-align: center; padding: 30px 12px; color: var(--ink-300); }
        .empty-state .mark { font-family: var(--f-mono); font-size: 20px; display: block; margin-bottom: 8px; color: var(--brass); }
        .empty-state p { font-family: var(--f-cond); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.04em; }
        .empty-state a { color: var(--ink-700); font-weight: 700; text-decoration: none; border-bottom: 1px solid var(--brass); }

        .page-footer { padding: 16px 0 26px; text-align: center; border-top: 1px solid var(--line); width: 100%; }
        .page-footer .notice { font-family: var(--f-cond); font-size: 9.5px; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; color: var(--ink-300); }
        .page-footer .role-line { font-family: var(--f-mono); font-size: 9px; color: var(--ink-300); margin-top: 4px; text-transform: uppercase; }

        @media (max-width: 640px) {
            .masthead { flex-direction: column; gap: 8px; padding: 12px 16px; }
            .masthead .right-fixed { position: static; transform: none; margin-top: 4px; }
            .stage { padding: 60px 14px 30px; }
            .action-btn { width: 150px; padding: 18px 12px 14px; }
            .statement-item { border-left: none; padding: 10px 0 0; border-top: 1px solid var(--line); flex: 1 1 100%; }
            .statement-item:first-child { border-top: none; }
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #131C24; --panel: #1B2733; --line: #2C3A45; --line-strong: #3C4C58;
                --ink-900: #ECEFF2; --ink-700: #C9D2D9; --ink-500: #93A2AC; --ink-300: #6B7A85;
                --brass-tint: #22303A; --blue-tint: #1D2A38; --green-tint: #17261D;
            }
            tbody tr:nth-child(even) td { background: #182129; }
            .action-btn { border-color: var(--ink-900); }
        }
</style>
</head>
<body>
    <!-- Masthead -->
    <div class="masthead">
        <div class="center">
            <div class="seal"><span>VM</span></div>
            <div>
                <h1><?php echo $orgName; ?> — National Disbursement</h1>
                <div class="file-ref">FILE NO. <?php echo htmlspecialchars($fileRef); ?> · <?php echo strtoupper(date('d M Y')); ?></div>
            </div>
        </div>
        <div class="right-fixed">
            <span class="role-pill"><?php echo $roleDisplay; ?></span>
            <span><span class="status-dot"></span><?php echo date('H:i'); ?> UTC+2</span>
        </div>
    </div>

    <!-- Central stage -->
    <div class="stage">
        <div class="stage-inner">

            <div class="welcome">
                <div class="eyebrow"><span class="section-mark">§</span>Registry Access</div>
                <h2>WELCOME, <?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 24)); ?></h2>
                <p>SELECT A WORKING PAGE FOR YOUR ROLE<?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?></p>
            </div>

            <!-- Role-based launcher -->
            <div class="launcher">
                <div class="launcher-grid">
                    <?php foreach ($actions as $action): ?>
                    <a href="<?php echo htmlspecialchars($action['href']); ?>" class="action-btn">
                        <?php if ($action['badge'] !== null): ?>
                        <span class="badge"><?php echo (int)$action['badge']; ?></span>
                        <?php endif; ?>
                        <span class="mark"><?php echo htmlspecialchars($action['mark']); ?></span>
                        <span class="label"><?php echo htmlspecialchars($action['label']); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Reveal controls — real data only appears once pressed -->
            <div class="reveal-controls">
                <button type="button" class="reveal-btn" id="btnStatement" onclick="togglePanel('statement')">VIEW STATEMENT OF ACCOUNT</button>
                <button type="button" class="reveal-btn" id="btnRegister" onclick="togglePanel('register')">VIEW RECENT REGISTER</button>
            </div>

            <!-- Statement of Account (real DB figures) -->
            <div class="reveal-panel doc-panel statement" id="panel-statement">
                <div class="statement-head">
                    <span class="eyebrow"><span class="section-mark">§</span>Statement of Account</span>
                    <span class="period">PERIOD ENDING <?php echo strtoupper(date('d M Y')); ?></span>
                </div>
                <div class="statement-row">
                    <div class="statement-item hero">
                        <div class="label">Total Disbursed</div>
                        <div class="value"><?php echo formatCurrency($stats['disbursed']); ?></div>
                    </div>
                    <div class="statement-item">
                        <div class="label">Success Rate</div>
                        <div class="value"><?php echo number_format($stats['success_rate'], 1); ?>%</div>
                    </div>
                    <div class="statement-item">
                        <div class="label">Batches This Month</div>
                        <div class="value"><?php echo number_format($stats['total_batches']); ?></div>
                    </div>
                    <div class="statement-item">
                        <div class="label">Awaiting Approval</div>
                        <div class="value"><?php echo number_format($stats['pending']); ?></div>
                    </div>
                    <div class="statement-item">
                        <div class="label">Beneficiaries</div>
                        <div class="value"><?php echo number_format($stats['beneficiaries']); ?></div>
                    </div>
                    <div class="statement-item">
                        <div class="label">Active Programs</div>
                        <div class="value"><?php echo number_format($stats['programs']); ?></div>
                    </div>
                    <div class="statement-item">
                        <div class="label">Departments</div>
                        <div class="value"><?php echo number_format($stats['departments']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Register of recent batches (real DB rows) -->
            <div class="reveal-panel doc-panel register-wrap" id="panel-register">
                <div class="panel-head">
                    <span class="eyebrow"><span class="section-mark">§</span>Register of Recent Batches</span>
                    <?php if ($config['show_all_batches']): ?>
                    <a href="batches/index.php" class="action-link">VIEW FULL REGISTER →</a>
                    <?php endif; ?>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Reference</th><th>Description</th><th>Program</th><th>Amount</th><th>Status</th><th>Created</th><th></th></tr></thead>
                        <tbody>
                            <?php if (!empty($recentBatches)): ?>
                                <?php foreach ($recentBatches as $batch):
                                    $status = strtolower($batch['status'] ?? 'draft');
                                    $statusDisplay = strtoupper(str_replace('_', ' ', $batch['status'] ?? 'Draft'));
                                ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($batch['batch_reference'] ?? '#' . $batch['id']); ?></code></td>
                                    <td><?php echo htmlspecialchars(substr($batch['batch_name'] ?? 'Batch #' . $batch['id'], 0, 24)); ?></td>
                                    <td><?php echo htmlspecialchars($batch['program_name'] ?? '—'); ?></td>
                                    <td><span class="amt"><?php echo formatCurrency($batch['total_amount'] ?? 0); ?></span></td>
                                    <td><span class="stamp <?php echo $status; ?>"><?php echo htmlspecialchars($statusDisplay); ?></span></td>
                                    <td><?php echo strtoupper(date('d M', strtotime($batch['created_at'] ?? 'now'))); ?></td>
                                    <td><a href="batches/view.php?id=<?php echo $batch['id']; ?>" class="action-link">REVIEW</a></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7"><div class="empty-state"><span class="mark">§</span><p>NO BATCHES ON RECORD.<?php if ($config['show_actions']): ?> <a href="imports/upload.php">CREATE THE FIRST ENTRY →</a><?php endif; ?></p></div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- Footer -->
    <footer class="page-footer">
        <div class="notice">SYSTEM-GENERATED REGISTRY · DISTRIBUTION RESTRICTED · ISO 27001 · © <?php echo date('Y'); ?> VOUCHMORPH</div>
        <div class="role-line"><?php echo $roleDisplay; ?><?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?> · <?php echo htmlspecialchars($fileRef); ?></div>
    </footer>

    <script>
        function togglePanel(name) {
            var panel = document.getElementById('panel-' + name);
            var btn = document.getElementById('btn' + name.charAt(0).toUpperCase() + name.slice(1));
            var isOpen = panel.classList.contains('is-open');
            panel.classList.toggle('is-open', !isOpen);
            btn.classList.toggle('is-active', !isOpen);
            btn.textContent = (!isOpen ? 'HIDE ' : 'VIEW ') + (name === 'statement' ? 'STATEMENT OF ACCOUNT' : 'RECENT REGISTER');
        }
    </script>
</body>
</html>
