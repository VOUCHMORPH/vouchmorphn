<?php
// index.php - ENTERPRISE COMMAND CENTER DASHBOARD
// FULLY RESPONSIVE - Phones, Tablets, Laptops, 4K Displays
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

    // 14-day trend
    $sparkline = [];
    try {
        $trendStmt = $pdo->prepare("
            SELECT DATE(created_at) as d, COALESCE(SUM(total_amount),0) as amt
            FROM import_batches
            WHERE organization_id = :org_id
            " . $roleFilter . "
            AND created_at >= CURRENT_DATE - INTERVAL '14 days'
            GROUP BY DATE(created_at) ORDER BY d ASC
        ");
        $trendStmt->execute($roleParams);
        $sparkline = array_column($trendStmt->fetchAll(PDO::FETCH_ASSOC), 'amt');
    } catch (PDOException $e) {
        $sparkline = [];
    }

    $stats = [
        'disbursed' => $currentMonth['amount'],
        'success_rate' => $successRate,
        'pending' => $pendingApproval['count'],
        'beneficiaries' => $beneficiaryCount,
        'successful_payments' => $successData['completed']
    ];

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats = ['disbursed' => 0, 'success_rate' => 0, 'pending' => 0, 'beneficiaries' => 0, 'successful_payments' => 0];
    $recentBatches = [];
    $sparkline = [];
}

// ============================================================
// ROLE-BASED UI CONFIGURATION
// ============================================================

$roleConfigs = [
    'owner' => [
        'title' => 'Executive Command Center', 'icon' => 'compass',
        'subtitle' => 'Full organizational oversight',
        'show_actions' => true, 'show_beneficiaries' => true, 'show_templates' => true,
        'show_reports' => true, 'show_settings' => true, 'show_department_filter' => true, 'show_all_batches' => true,
    ],
    'auditor' => [
        'title' => 'Audit & Compliance Dashboard', 'icon' => 'shield',
        'subtitle' => 'Read-only oversight of all departments',
        'show_actions' => false, 'show_beneficiaries' => true, 'show_templates' => false,
        'show_reports' => true, 'show_settings' => false, 'show_department_filter' => true, 'show_all_batches' => true,
    ],
    'approver' => [
        'title' => 'Approval Dashboard', 'icon' => 'check-circle',
        'subtitle' => 'Review and approve pending disbursements',
        'show_actions' => false, 'show_beneficiaries' => false, 'show_templates' => false,
        'show_reports' => true, 'show_settings' => false, 'show_department_filter' => true, 'show_all_batches' => true,
        'pending_count' => $stats['pending']
    ],
    'senior_approver' => [
        'title' => 'Senior Approval Dashboard', 'icon' => 'shield-check',
        'subtitle' => 'High-value disbursement approvals',
        'show_actions' => false, 'show_beneficiaries' => false, 'show_templates' => false,
        'show_reports' => true, 'show_settings' => false, 'show_department_filter' => true, 'show_all_batches' => true,
        'pending_count' => $stats['pending']
    ],
    'program_officer' => [
        'title' => 'Disbursement Officer Dashboard', 'icon' => 'send',
        'subtitle' => 'Create and manage disbursements',
        'show_actions' => true, 'show_beneficiaries' => true, 'show_templates' => true,
        'show_reports' => true, 'show_settings' => false, 'show_department_filter' => true, 'show_all_batches' => true,
    ],
    'beneficiary_registrar' => [
        'title' => 'Beneficiary Management', 'icon' => 'users',
        'subtitle' => 'Manage beneficiary records',
        'show_actions' => false, 'show_beneficiaries' => true, 'show_templates' => false,
        'show_reports' => false, 'show_settings' => false, 'show_department_filter' => true, 'show_all_batches' => false,
    ],
    'viewer' => [
        'title' => 'View-Only Dashboard', 'icon' => 'eye',
        'subtitle' => 'Read-only access to departmental data',
        'show_actions' => false, 'show_beneficiaries' => true, 'show_templates' => false,
        'show_reports' => true, 'show_settings' => false, 'show_department_filter' => true, 'show_all_batches' => true,
    ],
    'department_head' => [
        'title' => 'Department Dashboard', 'icon' => 'layout',
        'subtitle' => 'Oversee departmental disbursements',
        'show_actions' => true, 'show_beneficiaries' => true, 'show_templates' => true,
        'show_reports' => true, 'show_settings' => false, 'show_department_filter' => true, 'show_all_batches' => true,
    ]
];

$config = $roleConfigs[$userRole] ?? $roleConfigs['viewer'];

$departmentName = '';
if ($departmentId) {
    $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = :id AND organization_id = :org_id");
    $stmt->execute([':id' => $departmentId, ':org_id' => $orgId]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    $departmentName = $dept['name'] ?? '';
}

$roleDisplay = ucwords(str_replace('_', ' ', $userRole));

// ============================================================
// ICON SYSTEM
// ============================================================
function icon($name, $size = 18) {
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
        'compass' => '<circle cx="12" cy="12" r="9"/><path d="m14.5 9.5-2 5-5 2 2-5 5-2Z"/>',
        'shield' => '<path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6l-7-3Z"/>',
        'shield-check' => '<path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'send' => '<path d="M4 11.5 20 4l-6.5 16-3-7-7-1.5Z"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c0-3.3 2.5-5.5 5.5-5.5s5.5 2.2 5.5 5.5"/><path d="M15.5 8.2a3 3 0 0 1 0 5.8"/><path d="M17.5 13.5c2.4.4 4 2.3 4 5.5"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.8"/>',
        'layout' => '<rect x="3" y="3" width="18" height="18" rx="1"/><path d="M3 9h18M9 9v12"/>',
        'plus-square' => '<rect x="3" y="3" width="18" height="18" rx="1"/><path d="M12 8v8M8 12h8"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
        'file-text' => '<path d="M6 2h9l4 4v16H6V2Z"/><path d="M15 2v4h4M9 12h6M9 16h6M9 8h2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V19a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H4a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H10a1.6 1.6 0 0 0 1-1.5V4a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V10c.1.6.6 1.1 1.5 1H20a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z"/>',
        'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 13 6 9Z"/><path d="M9.5 17a2.5 2.5 0 0 0 5 0"/>',
        'refresh' => '<path d="M20 11A8 8 0 0 0 5.5 6.5L4 8M4 13a8 8 0 0 0 14.5 4.5L20 16"/><path d="M4 4v4h4M20 20v-4h-4"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'currency' => '<circle cx="12" cy="12" r="9"/><path d="M12 6.5v11M15 9.2c0-1.2-1.3-2.2-3-2.2s-3 .8-3 2c0 3 6 1.5 6 4.5 0 1.2-1.3 2-3 2s-3-1-3-2.2"/>',
        'trending-up' => '<path d="m3 16 6-6 4 4 8-9"/><path d="M15 5h6v6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'inbox' => '<path d="M4 12h4l2 3h4l2-3h4"/><path d="M5.5 5h13l2.5 7v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-7l2.5-7Z"/>',
    ];
    $p = $paths[$name] ?? $paths['grid'];
    return "<svg width=\"{$size}\" height=\"{$size}\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.75\" stroke-linecap=\"round\" stroke-linejoin=\"round\">{$p}</svg>";
}

$roleAccents = [
    'owner' => 'gold', 'department_head' => 'gold',
    'approver' => 'green', 'senior_approver' => 'green',
    'auditor' => 'blue', 'viewer' => 'blue',
    'program_officer' => 'slate', 'beneficiary_registrar' => 'slate',
];
$roleAccent = $roleAccents[$userRole] ?? 'slate';

function sparklinePath($values, $w = 96, $h = 28) {
    if (count($values) < 2) return null;
    $max = max($values) ?: 1;
    $min = min($values);
    $range = ($max - $min) ?: 1;
    $step = $w / (count($values) - 1);
    $pts = [];
    foreach ($values as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($h - (($v - $min) / $range) * $h, 1);
        $pts[] = "$x,$y";
    }
    return implode(' ', $pts);
}
$sparkPts = sparklinePath($sparkline);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config['title']); ?> — VouchMorph</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-900: #0a1628; --primary-800: #10203a; --primary-700: #16304f;
            --gold: #c9972a; --gold-bright: #e0ad3d; --gold-wash: rgba(201,151,42,.10);

            --success: #1e7a4c; --success-bg: #e8f5ee;
            --warning: #b5750b; --warning-bg: #fdf3e2;
            --danger:  #b3261e; --danger-bg:  #fbeceb;
            --info:    #1e4fd8; --info-bg:    #eaefff;
            --slate-acc: #4b5768; --slate-bg: #eef0f3;

            --surface-0: #ffffff; --surface-50: #fbfaf7; --surface-100: #f4f2ea;
            --surface-200: #e7e4d8; --surface-300: #d3cfc0; --surface-400: #a9a695;
            --surface-500: #83806f; --surface-600: #5f5d51; --surface-700: #403e35;
            --surface-900: #191712;

            --font-display: 'Fraunces', serif;
            --font-body: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            --ease: cubic-bezier(0.25, 0.46, 0.45, 0.94);
            
            --sidebar-width: 264px;
            --header-height: 72px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--font-body); background: var(--surface-100); color: var(--surface-900); min-height: 100vh; }
        :focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--surface-300); }

        .app { display: flex; min-height: 100vh; }

        /* ---------- Sidebar ---------- */
        .sidebar {
            width: var(--sidebar-width); background: var(--primary-900); color: #dbe1ea;
            position: fixed; height: 100vh; overflow-y: auto;
            display: flex; flex-direction: column; z-index: 100;
            transition: transform 0.3s var(--ease);
        }
        .sidebar-header { padding: 26px 22px; border-bottom: 1px solid rgba(255,255,255,.08); flex-shrink: 0; }
        .sidebar-header .logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-header .logo-icon {
            width: 38px; height: 38px; background: var(--gold); flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-display); font-weight: 600; font-size: 15px; color: var(--primary-900);
        }
        .sidebar-header h2 { font-size: 17px; font-weight: 600; letter-spacing: -.2px; font-family: var(--font-display); }
        .sidebar-header h2 em { font-style: normal; color: var(--gold); }
        .sidebar-header .org-badge {
            font-size: 10.5px; color: #7c8aa3; margin-top: 3px; font-weight: 500;
            letter-spacing: .4px; text-transform: uppercase;
        }

        .sidebar-nav { padding: 18px 12px; flex: 1; }
        .sidebar-nav .nav-label {
            font-size: 10px; text-transform: uppercase; letter-spacing: 1.2px; color: #556280;
            padding: 14px 12px 8px; font-weight: 700;
        }
        .nav-item {
            padding: 10px 14px; display: flex; align-items: center; gap: 12px;
            color: #b7c0d1; text-decoration: none; transition: .15s var(--ease);
            font-size: 13.5px; font-weight: 500; margin-bottom: 2px; border-left: 2px solid transparent;
        }
        .nav-item:hover { background: rgba(255,255,255,.04); color: #fff; }
        .nav-item.active { background: var(--gold-wash); color: var(--gold-bright); border-left-color: var(--gold); }
        .nav-item .icon-wrap { width: 18px; height: 18px; flex-shrink: 0; display: flex; }
        .nav-item .badge {
            margin-left: auto; background: var(--gold); color: var(--primary-900);
            font-size: 10px; font-weight: 700; padding: 1px 8px; font-family: var(--font-mono);
        }

        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,.08); flex-shrink: 0; }
        .sidebar-footer .user-card { display: flex; align-items: center; gap: 11px; }
        .sidebar-footer .user-avatar {
            width: 32px; height: 32px; background: var(--gold); flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 12.5px; color: var(--primary-900); font-family: var(--font-mono);
        }
        .sidebar-footer .user-info { flex: 1; min-width: 0; }
        .sidebar-footer .user-info .name { font-size: 12.5px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-footer .user-info .role-line { display: flex; align-items: center; gap: 6px; margin-top: 3px; flex-wrap: wrap; }
        .sidebar-footer a.logout-link { color: #7c8aa3; display: flex; }
        .sidebar-footer a.logout-link:hover { color: var(--gold-bright); }

        .role-chip {
            font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
            padding: 2px 8px; font-family: var(--font-mono);
        }
        .role-chip.gold  { background: rgba(201,151,42,.18); color: var(--gold-bright); }
        .role-chip.green { background: rgba(30,122,76,.22); color: #6fd6a3; }
        .role-chip.blue  { background: rgba(30,79,216,.22); color: #94aeff; }
        .role-chip.slate { background: rgba(148,163,184,.18); color: #b7c0d1; }
        .dept-chip { font-size: 9.5px; color: #7c8aa3; letter-spacing: .3px; }

        /* ---------- Mobile Hamburger ---------- */
        .menu-toggle {
            display: none; background: none; border: none; color: var(--surface-700);
            font-size: 24px; cursor: pointer; padding: 4px;
        }
        .sidebar-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4);
            z-index: 99; cursor: pointer;
        }

        /* ---------- Main ---------- */
        .main { flex: 1; margin-left: var(--sidebar-width); min-height: 100vh; }

        .top-bar {
            padding: 16px 24px; background: var(--surface-0); border-bottom: 1px solid var(--surface-200);
            position: sticky; top: 0; z-index: 50; display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 12px;
            min-height: var(--header-height);
        }
        .top-bar .greeting { display: flex; align-items: center; gap: 12px; }
        .top-bar .greeting .role-icon {
            width: 34px; height: 34px; background: var(--surface-100); flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; color: var(--gold);
            border: 1px solid var(--surface-200);
        }
        .top-bar .greeting h1 { font-size: 18px; font-weight: 600; letter-spacing: -.2px; font-family: var(--font-display); }
        .top-bar .greeting p { color: var(--surface-500); font-size: 12px; margin-top: 2px; }
        .top-bar .greeting .subtitle-sep { margin: 0 6px; color: var(--surface-300); }

        .top-bar .actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .top-bar .btn-icon {
            width: 34px; height: 34px; border: 1px solid var(--surface-200); background: var(--surface-0);
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            transition: .15s; color: var(--surface-600);
        }
        .top-bar .btn-icon:hover { border-color: var(--gold); color: var(--gold); }
        .top-bar .datetime {
            font-size: 11.5px; color: var(--surface-500); font-weight: 500; font-family: var(--font-mono);
            border: 1px solid var(--surface-200); padding: 6px 10px; display: flex; align-items: center; gap: 6px;
        }

        .dashboard-content { padding: 24px; max-width: 1440px; margin: 0 auto; }

        /* ---------- Stats ---------- */
        .stats-grid { display: grid; grid-template-columns: 1.4fr repeat(3, 1fr); gap: 14px; margin-bottom: 24px; }
        .stat-card {
            background: var(--surface-0); padding: 18px 20px; border: 1px solid var(--surface-200);
            position: relative; overflow: hidden;
        }
        .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background: var(--surface-200); }
        .stat-card.primary::before { background: var(--gold); }
        .stat-top { display: flex; justify-content: space-between; align-items: flex-start; }
        .stat-icon {
            width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
            background: var(--surface-100); color: var(--surface-600); border: 1px solid var(--surface-200);
        }
        .stat-icon.gold  { background: var(--gold-wash); color: var(--gold); border-color: rgba(201,151,42,.3); }
        .stat-icon.green { background: var(--success-bg); color: var(--success); border-color: rgba(30,122,76,.25); }
        .stat-icon.warn  { background: var(--warning-bg); color: var(--warning); border-color: rgba(181,117,11,.25); }
        .stat-icon.blue  { background: var(--info-bg); color: var(--info); border-color: rgba(30,79,216,.25); }

        .stat-spark { width: 80px; height: 24px; opacity: .9; }
        .stat-value { font-size: 22px; font-weight: 700; letter-spacing: -.3px; margin-top: 10px; font-family: var(--font-mono); }
        .stat-label { color: var(--surface-500); font-size: 11.5px; font-weight: 500; margin-top: 4px; }
        .stat-label .sep { color: var(--surface-300); margin: 0 4px; }

        /* ---------- Quick actions ---------- */
        .quick-actions { display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; }
        .action-btn {
            padding: 10px 18px; font-weight: 600; font-size: 12.5px; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px; transition: .15s var(--ease);
            border: 1px solid transparent; cursor: pointer;
        }
        .action-btn.primary { background: var(--primary-900); color: #fff; border-color: var(--primary-900); }
        .action-btn.primary:hover { background: var(--gold); border-color: var(--gold); color: var(--primary-900); }
        .action-btn.secondary { background: var(--surface-0); color: var(--surface-700); border-color: var(--surface-200); }
        .action-btn.secondary:hover { border-color: var(--surface-400); background: var(--surface-50); }

        /* ---------- Cards / tables ---------- */
        .card { background: var(--surface-0); border: 1px solid var(--surface-200); overflow: hidden; margin-bottom: 24px; }
        .card-header {
            padding: 14px 18px; border-bottom: 1px solid var(--surface-200); display: flex;
            justify-content: space-between; align-items: center; background: var(--surface-50);
            flex-wrap: wrap; gap: 8px;
        }
        .card-header h3 { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; letter-spacing: -.1px; }
        .card-header h3 .icon-wrap { color: var(--gold); display:flex; }
        .card-header a.view-all { color: var(--surface-600); text-decoration: none; font-weight: 600; font-size: 12px; display: flex; align-items: center; gap: 4px; }
        .card-header a.view-all:hover { color: var(--gold); }

        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 580px; }
        th, td { padding: 11px 16px; text-align: left; border-bottom: 1px solid var(--surface-100); font-size: 13px; }
        th { background: var(--surface-50); font-weight: 700; font-size: 10px; color: var(--surface-500); text-transform: uppercase; letter-spacing: .8px; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: var(--surface-50); }

        .status { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; font-size: 11px; font-weight: 600; }
        .status .dot { width: 6px; height: 6px; border-radius: 50%; }
        .status.completed { background: var(--success-bg); color: var(--success); } .status.completed .dot { background: var(--success); }
        .status.processing { background: var(--warning-bg); color: var(--warning); } .status.processing .dot { background: var(--warning); animation: pulse 1.6s infinite; }
        .status.ready_for_approval { background: var(--info-bg); color: var(--info); } .status.ready_for_approval .dot { background: var(--info); }
        .status.failed { background: var(--danger-bg); color: var(--danger); } .status.failed .dot { background: var(--danger); }
        .status.draft { background: var(--surface-100); color: var(--surface-500); } .status.draft .dot { background: var(--surface-400); }
        @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:.35;} }

        .view-link { color: var(--surface-600); text-decoration: none; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 3px; }
        .view-link:hover { color: var(--gold); }
        code { background: var(--surface-100); padding: 2px 8px; font-family: var(--font-mono); font-size: 11px; font-weight: 600; color: var(--surface-700); }

        .empty-state { text-align: center; padding: 40px 20px; color: var(--surface-500); }
        .empty-state .icon-wrap { color: var(--surface-300); margin-bottom: 12px; display: flex; justify-content: center; }
        .empty-state h4 { font-size: 15px; color: var(--surface-700); margin-bottom: 4px; font-family: var(--font-display); font-weight: 600; }
        .empty-state p { font-size: 13px; margin-bottom: 14px; }
        .empty-state a { color: var(--gold); text-decoration: none; font-weight: 600; font-size: 13px; }

        /* ---------- Role-specific widgets ---------- */
        .widget-card { border-left: 3px solid; }
        .widget-card.warn { border-left-color: var(--warning); }
        .widget-card.blue { border-left-color: var(--info); }
        .widget-card .card-header.warn-bg { background: var(--warning-bg); }
        .widget-card .card-header.blue-bg { background: var(--info-bg); }
        .widget-body { padding: 14px 18px; }
        .widget-body p { color: var(--surface-600); font-size: 13px; line-height: 1.6; }
        .widget-body strong { color: var(--surface-900); }

        footer.page-footer {
            text-align: center; padding: 16px 0; color: var(--surface-400); font-size: 10px;
            border-top: 1px solid var(--surface-200); letter-spacing: .4px; text-transform: uppercase;
        }
        footer.page-footer .role-line { font-size: 9px; letter-spacing: .8px; margin-top: 4px; color: var(--surface-400); }

        /* ============================================================
           RESPONSIVE BREAKPOINTS
           ============================================================ */

        /* ---------- Tablets & Smaller Laptops ---------- */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .stat-value { font-size: 20px; }
            .dashboard-content { padding: 20px; }
        }

        /* ---------- Tablets & Mobile ---------- */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            .sidebar.open { transform: translateX(0); }
            .menu-toggle { display: block; }
            .sidebar-overlay.active { display: block; }
            .main { margin-left: 0; }
            .top-bar .greeting h1 { font-size: 16px; }
            .top-bar .datetime { font-size: 10px; padding: 4px 8px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card { padding: 14px 16px; }
            .stat-value { font-size: 18px; }
            .stat-label { font-size: 10px; }
        }

        /* ---------- Small Tablets ---------- */
        @media (max-width: 768px) {
            .top-bar { padding: 12px 16px; }
            .top-bar .greeting .role-icon { display: none; }
            .top-bar .actions .btn-icon { width: 30px; height: 30px; }
            .dashboard-content { padding: 16px; }
            .stats-grid { gap: 8px; }
            .stat-card { padding: 12px 14px; }
            .stat-value { font-size: 16px; }
            .quick-actions { gap: 8px; }
            .action-btn { padding: 8px 14px; font-size: 11px; }
            .card-header { padding: 10px 14px; }
            .card-header h3 { font-size: 12px; }
            th, td { padding: 8px 10px; font-size: 11px; }
            .status { font-size: 10px; padding: 2px 8px; }
            .stat-spark { width: 60px; height: 20px; }
            footer.page-footer { font-size: 8px; }
        }

        /* ---------- Mobile Phones ---------- */
        @media (max-width: 480px) {
            .top-bar { padding: 10px 12px; flex-direction: column; align-items: stretch; gap: 8px; }
            .top-bar .greeting { justify-content: space-between; }
            .top-bar .greeting h1 { font-size: 14px; }
            .top-bar .greeting p { font-size: 10px; }
            .top-bar .actions { justify-content: flex-end; }
            .stats-grid { grid-template-columns: 1fr; }
            .stat-card { padding: 10px 12px; }
            .stat-value { font-size: 20px; }
            .quick-actions { flex-direction: column; }
            .action-btn { justify-content: center; width: 100%; }
            .dashboard-content { padding: 12px; }
            .card-header { flex-direction: column; align-items: flex-start; gap: 4px; }
            .table-wrap { margin: 0 -12px; }
            th, td { padding: 6px 8px; font-size: 10px; }
            .view-link { font-size: 10px; }
            code { font-size: 9px; padding: 1px 6px; }
            footer.page-footer { font-size: 7px; padding: 12px 0; }
            .sidebar { width: 100%; max-width: 300px; }
        }

        /* ---------- Large Screens (4K+) ---------- */
        @media (min-width: 1920px) {
            .dashboard-content { padding: 40px 60px; max-width: 1800px; }
            .stats-grid { gap: 24px; }
            .stat-card { padding: 32px 36px; }
            .stat-value { font-size: 36px; }
            .stat-label { font-size: 14px; }
            .top-bar { padding: 24px 48px; }
            .top-bar .greeting h1 { font-size: 28px; }
            .dashboard-content { padding: 40px 48px; }
            th, td { padding: 18px 28px; font-size: 15px; }
            .card-header { padding: 20px 28px; }
            .card-header h3 { font-size: 18px; }
        }

        /* ---------- Dark mode preference ---------- */
        @media (prefers-color-scheme: dark) {
            :root {
                --surface-0: #1a1a1a;
                --surface-50: #222222;
                --surface-100: #2a2a2a;
                --surface-200: #333333;
                --surface-300: #444444;
                --surface-400: #666666;
                --surface-500: #888888;
                --surface-600: #aaaaaa;
                --surface-700: #cccccc;
                --surface-900: #eeeeee;
            }
            .top-bar .greeting .role-icon { background: #2a2a2a; border-color: #333333; }
            .stat-card { background: #1e1e1e; border-color: #333333; }
            .stat-icon { background: #2a2a2a; border-color: #333333; }
            .card { background: #1e1e1e; border-color: #333333; }
            .card-header { background: #252525; border-color: #333333; }
            th { background: #252525; }
            .action-btn.secondary { background: #2a2a2a; border-color: #333333; color: #cccccc; }
            .action-btn.secondary:hover { background: #333333; }
            code { background: #2a2a2a; color: #aaaaaa; }
            .sidebar { background: #0a0a0a; }
            .sidebar-footer .user-info .name { color: #ddd; }
        }
    </style>
</head>
<body>
<div class="app">
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">VM</div>
                <div>
                    <h2>VouchMorph <em>Enterprise</em></h2>
                    <div class="org-badge"><?php echo htmlspecialchars($user['organization_name'] ?? 'Government'); ?></div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="index.php" class="nav-item active">
                <span class="icon-wrap"><?php echo icon('grid'); ?></span><span class="label">Command Center</span>
            </a>
            <?php if ($config['show_actions']): ?>
            <a href="imports/upload.php" class="nav-item">
                <span class="icon-wrap"><?php echo icon('plus-square'); ?></span><span class="label">New Disbursement</span>
            </a>
            <?php endif; ?>
            <a href="batches/index.php" class="nav-item">
                <span class="icon-wrap"><?php echo icon('layers'); ?></span><span class="label">Batches</span>
                <?php if ($stats['pending'] > 0 && in_array($userRole, ['approver', 'senior_approver', 'owner'])): ?>
                    <span class="badge"><?php echo $stats['pending']; ?></span>
                <?php endif; ?>
            </a>
            <?php if ($config['show_beneficiaries']): ?>
            <a href="beneficiaries/index.php" class="nav-item">
                <span class="icon-wrap"><?php echo icon('users'); ?></span><span class="label">Beneficiaries</span>
            </a>
            <?php endif; ?>

            <?php if ($config['show_templates'] || $config['show_reports'] || $config['show_settings']): ?>
            <div class="nav-label">Management</div>
            <?php endif; ?>
            <?php if ($config['show_templates']): ?>
            <a href="templates/index.php" class="nav-item">
                <span class="icon-wrap"><?php echo icon('file-text'); ?></span><span class="label">Templates</span>
            </a>
            <?php endif; ?>
            <?php if ($config['show_reports']): ?>
            <a href="reports/index.php" class="nav-item">
                <span class="icon-wrap"><?php echo icon('trending-up'); ?></span><span class="label">Reports</span>
            </a>
            <?php endif; ?>
            <?php if ($config['show_settings']): ?>
            <a href="settings/index.php" class="nav-item">
                <span class="icon-wrap"><?php echo icon('settings'); ?></span><span class="label">Settings</span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 2)); ?></div>
                <div class="user-info">
                    <div class="name"><?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></div>
                    <div class="role-line">
                        <span class="role-chip <?php echo $roleAccent; ?>"><?php echo htmlspecialchars($roleDisplay); ?></span>
                        <?php if ($departmentName): ?><span class="dept-chip"><?php echo htmlspecialchars($departmentName); ?></span><?php endif; ?>
                    </div>
                </div>
                <a href="logout.php" class="logout-link" title="Sign out"><?php echo icon('logout', 17); ?></a>
            </div>
        </div>
    </aside>

    <main class="main">
        <div class="top-bar">
            <div class="greeting">
                <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle menu">☰</button>
                <div class="role-icon"><?php echo icon($config['icon'], 19); ?></div>
                <div>
                    <h1><?php echo htmlspecialchars($config['title']); ?></h1>
                    <p>
                        <?php echo htmlspecialchars($user['organization_name']); ?>
                        <?php if ($departmentName): ?><span class="subtitle-sep">&middot;</span><?php echo htmlspecialchars($departmentName); ?><?php endif; ?>
                        <span class="subtitle-sep">&middot;</span><?php echo date('l, F j, Y'); ?>
                    </p>
                </div>
            </div>
            <div class="actions">
                <span class="datetime"><?php echo icon('clock', 13); ?><?php echo date('H:i T'); ?></span>
                <button class="btn-icon" title="Notifications" onclick="alert('No new notifications')"><?php echo icon('bell', 16); ?></button>
                <button class="btn-icon" title="Refresh" onclick="location.reload()"><?php echo icon('refresh', 16); ?></button>
            </div>
        </div>

        <div class="dashboard-content">
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-top">
                        <div class="stat-icon gold"><?php echo icon('currency', 17); ?></div>
                        <?php if ($sparkPts): ?>
                        <svg class="stat-spark" viewBox="0 0 96 28" preserveAspectRatio="none">
                            <polyline points="<?php echo $sparkPts; ?>" fill="none" stroke="#c9972a" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?php endif; ?>
                    </div>
                    <div class="stat-value">BWP <?php echo number_format($stats['disbursed'], 2); ?></div>
                    <div class="stat-label">Disbursed this month<?php echo $sparkPts ? ' <span class="sep">&middot;</span> 14-day trend' : ''; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-top"><div class="stat-icon green"><?php echo icon('trending-up', 17); ?></div></div>
                    <div class="stat-value"><?php echo number_format($stats['success_rate'], 2); ?>%</div>
                    <div class="stat-label">Success rate <span class="sep">&middot;</span> <?php echo number_format($stats['successful_payments']); ?> payments</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top"><div class="stat-icon warn"><?php echo icon('clock', 17); ?></div></div>
                    <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
                    <div class="stat-label">Pending approval</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top"><div class="stat-icon blue"><?php echo icon('users', 17); ?></div></div>
                    <div class="stat-value"><?php echo number_format($stats['beneficiaries']); ?></div>
                    <div class="stat-label">Active beneficiaries</div>
                </div>
            </div>

            <?php if ($config['show_actions']): ?>
            <div class="quick-actions">
                <a href="imports/upload.php" class="action-btn primary"><?php echo icon('plus-square', 15); ?>New Disbursement</a>
                <a href="beneficiaries/import.php" class="action-btn secondary"><?php echo icon('users', 15); ?>Import Beneficiaries</a>
                <a href="templates/index.php" class="action-btn secondary"><?php echo icon('file-text', 15); ?>Templates</a>
                <a href="reports/index.php" class="action-btn secondary"><?php echo icon('trending-up', 15); ?>Reports</a>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3><span class="icon-wrap"><?php echo icon('layers', 16); ?></span>Recent Disbursements</h3>
                    <?php if ($config['show_all_batches']): ?>
                    <a href="batches/index.php" class="view-all">View all<?php echo icon('chevron-right', 13); ?></a>
                    <?php endif; ?>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Reference</th><th>Description</th><th>Amount</th><th>Status</th><th>Created</th><th></th></tr></thead>
                        <tbody>
                            <?php if (!empty($recentBatches)): ?>
                                <?php foreach ($recentBatches as $batch):
                                    $status = strtolower($batch['status'] ?? 'draft');
                                    $statusDisplay = ucwords(str_replace('_', ' ', $batch['status'] ?? 'Draft'));
                                ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($batch['batch_reference'] ?? '#' . $batch['id']); ?></code></td>
                                    <td><?php echo htmlspecialchars($batch['batch_name'] ?? 'Batch #' . $batch['id']); ?></td>
                                    <td><strong>BWP <?php echo number_format($batch['total_amount'] ?? 0, 2); ?></strong></td>
                                    <td><span class="status <?php echo $status; ?>"><span class="dot"></span><?php echo htmlspecialchars($statusDisplay); ?></span></td>
                                    <td><?php echo date('M d, Y', strtotime($batch['created_at'] ?? 'now')); ?></td>
                                    <td>
                                        <a href="batches/view.php?id=<?php echo $batch['id']; ?>" class="view-link">
                                            <?php if (in_array($userRole, ['approver','senior_approver'])): ?>Approve<?php elseif ($userRole === 'auditor'): ?>Audit<?php else: ?>Review<?php endif; ?>
                                            <?php echo icon('chevron-right', 13); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6">
                                    <div class="empty-state">
                                        <span class="icon-wrap"><?php echo icon('inbox', 40); ?></span>
                                        <h4>No disbursements yet</h4>
                                        <p>Start by creating your first payment batch.</p>
                                        <?php if ($config['show_actions']): ?><a href="imports/upload.php">Create new disbursement →</a><?php endif; ?>
                                    </div>
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (in_array($userRole, ['approver', 'senior_approver'])): ?>
            <div class="card widget-card warn">
                <div class="card-header warn-bg">
                    <h3 style="color:var(--warning);"><?php echo icon('clock', 16); ?>Pending Approval Queue</h3>
                    <span style="font-size:13px; font-weight:700; color:var(--warning);"><?php echo $stats['pending']; ?> batches waiting</span>
                </div>
                <div class="widget-body">
                    <p><?php if ($stats['pending'] > 0): ?>You have <strong><?php echo $stats['pending']; ?></strong> batches awaiting your review. Review each carefully before approval.<?php else: ?>All batches have been reviewed. No pending approvals.<?php endif; ?></p>
                    <?php if ($stats['pending'] > 0): ?>
                    <a href="batches/index.php?filter=pending" class="action-btn primary" style="margin-top:14px; display:inline-flex;"><?php echo icon('check-circle', 15); ?>Review Pending Batches</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($userRole === 'auditor'): ?>
            <div class="card widget-card blue">
                <div class="card-header blue-bg">
                    <h3 style="color:var(--info);"><?php echo icon('shield', 16); ?>Audit Summary</h3>
                    <span style="font-size:13px; font-weight:700; color:var(--info);"><?php echo date('Y-m-d'); ?></span>
                </div>
                <div class="widget-body">
                    <p>All departmental disbursements are visible for audit purposes.<br>Total disbursements this month: <strong>BWP <?php echo number_format($stats['disbursed'], 2); ?></strong></p>
                    <a href="reports/audit_trail.php" class="action-btn secondary" style="margin-top:14px; display:inline-flex;"><?php echo icon('file-text', 15); ?>View Audit Trail</a>
                </div>
            </div>
            <?php endif; ?>

            <footer class="page-footer">
                <span>Secure Enterprise Platform &middot; ISO 27001 Certified &middot; © <?php echo date('Y'); ?> VouchMorph &middot; Government of Botswana</span>
                <div class="role-line">
                    Role: <?php echo strtoupper($userRole); ?><?php if ($departmentName): ?> &middot; Dept: <?php echo strtoupper($departmentName); ?><?php endif; ?>
                </div>
            </footer>
        </div>
    </main>
</div>

<!-- ============================================================
   JAVASCRIPT - Mobile Menu Toggle
   ============================================================ -->
<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }
    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    }
    // Close sidebar on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });
    // Auto-close sidebar on window resize to desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth > 992) closeSidebar();
    });
</script>
</body>
</html>
