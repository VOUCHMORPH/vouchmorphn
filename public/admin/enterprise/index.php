<?php
// index.php - ENTERPRISE COMMAND CENTER DASHBOARD
// SHARP EDGES - No rounded corners, bold geometric design
require_once 'auth.php';
$user = requireEnterpriseAuth();

$pdo = getDBConnection();
$orgId = getOrganizationId();

// Get stats with trend calculations
try {
    // This month's disbursements
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(total_amount), 0) as amount,
            COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) as completed_amount
        FROM import_batches 
        WHERE organization_id = :org_id 
        AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute([':org_id' => $orgId]);
    $currentMonth = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'amount' => 0, 'completed_amount' => 0];

    // Previous month for trend
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as amount
        FROM import_batches 
        WHERE organization_id = :org_id 
        AND created_at >= DATE_TRUNC('month', CURRENT_DATE - INTERVAL '1 month')
        AND created_at < DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute([':org_id' => $orgId]);
    $previousMonth = $stmt->fetchColumn() ?: 0;

    // Success rate
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END), 0) as completed
        FROM import_batches 
        WHERE organization_id = :org_id 
        AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute([':org_id' => $orgId]);
    $successData = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'completed' => 0];
    $successRate = $successData['total'] > 0 ? round(($successData['completed'] / $successData['total']) * 100, 2) : 0;

    // Pending approvals
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as amount 
        FROM import_batches 
        WHERE organization_id = :org_id AND status = 'READY_FOR_APPROVAL'
    ");
    $stmt->execute([':org_id' => $orgId]);
    $pendingApproval = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'amount' => 0];

    // Beneficiaries
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM organization_beneficiaries 
        WHERE organization_id = :org_id AND is_active = true
    ");
    $stmt->execute([':org_id' => $orgId]);
    $beneficiaryCount = $stmt->fetchColumn() ?: 0;

    // Recent batches
    $stmt = $pdo->prepare("
        SELECT * FROM import_batches 
        WHERE organization_id = :org_id 
        ORDER BY created_at DESC LIMIT 8
    ");
    $stmt->execute([':org_id' => $orgId]);
    $recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate trend
    $trend = $previousMonth > 0 ? round((($currentMonth['amount'] - $previousMonth) / $previousMonth) * 100, 1) : 0;

    $stats = [
        'disbursed' => $currentMonth['amount'],
        'success_rate' => $successRate,
        'pending' => $pendingApproval['count'],
        'beneficiaries' => $beneficiaryCount,
        'trend' => $trend,
        'successful_payments' => $successData['completed']
    ];
    
} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats = [
        'disbursed' => 0,
        'success_rate' => 0,
        'pending' => 0,
        'beneficiaries' => 0,
        'trend' => 0,
        'successful_payments' => 0
    ];
    $recentBatches = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           ROOT VARIABLES - SHARP EDGES THEME
           ============================================================ */
        :root {
            --primary-900: #0a1628;
            --primary-800: #0f1f3a;
            --primary-700: #1a2d4a;
            --primary-600: #2a4a7a;
            
            --accent-400: #fbbf24;
            --accent-500: #f59e0b;
            --accent-600: #d97706;
            
            --success: #10b981;
            --success-bg: #d1fae5;
            --warning: #f59e0b;
            --warning-bg: #fef3c7;
            --danger: #ef4444;
            --danger-bg: #fee2e2;
            --info: #3b82f6;
            --info-bg: #dbeafe;
            
            --surface-50: #f8fafc;
            --surface-100: #f1f5f9;
            --surface-200: #e2e8f0;
            --surface-300: #cbd5e1;
            --surface-400: #94a3b8;
            --surface-500: #64748b;
            --surface-600: #475569;
            --surface-700: #334155;
            --surface-800: #1e293b;
            --surface-900: #0f172a;
            
            --font-display: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-mono: 'JetBrains Mono', 'Fira Code', monospace;
            
            /* SHARP EDGES - NO ROUNDED CORNERS */
            --radius-none: 0;
            --transition: all 0.2s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        /* ============================================================
           RESET & BASE
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: var(--font-display);
            background: var(--surface-100);
            color: var(--surface-900);
            min-height: 100vh;
        }

        /* ============================================================
           SCROLLBAR - Sharp edges
           ============================================================ */
        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: var(--surface-100); }
        ::-webkit-scrollbar-thumb { background: var(--surface-400); border-radius: 0; }
        ::-webkit-scrollbar-thumb:hover { background: var(--surface-500); }

        /* ============================================================
           APP LAYOUT
           ============================================================ */
        .app { display: flex; min-height: 100vh; }

        /* ============================================================
           SIDEBAR - Sharp edges, no rounded corners
           ============================================================ */
        .sidebar {
            width: 280px;
            background: var(--primary-900);
            color: #e2e8f0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            z-index: 100;
            border-right: 2px solid var(--accent-500);
        }

        .sidebar-header {
            padding: 28px 24px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.08);
            flex-shrink: 0;
        }

        .sidebar-header .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-header .logo-icon {
            width: 44px;
            height: 44px;
            background: var(--accent-500);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 18px;
            color: var(--primary-900);
            /* NO ROUNDED CORNERS */
            border-radius: 0;
        }

        .sidebar-header h2 {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .sidebar-header h2 span { color: var(--accent-400); }

        .sidebar-header .org-badge {
            font-size: 11px;
            color: var(--surface-400);
            margin-top: 4px;
            font-weight: 500;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border-left: 2px solid var(--accent-500);
            padding-left: 10px;
        }

        .sidebar-nav {
            padding: 16px 12px;
            flex: 1;
        }

        .sidebar-nav .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--surface-500);
            padding: 12px 12px 8px;
            font-weight: 700;
            border-bottom: 2px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 8px;
        }

        .nav-item {
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--surface-300);
            text-decoration: none;
            border-radius: 0;
            transition: var(--transition);
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 1px;
            border-left: 2px solid transparent;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            border-left: 2px solid var(--surface-400);
        }

        .nav-item.active {
            background: rgba(251, 191, 36, 0.10);
            color: var(--accent-400);
            border-left: 2px solid var(--accent-500);
        }

        .nav-item .icon { font-size: 18px; width: 24px; text-align: center; }
        .nav-item .badge {
            margin-left: auto;
            background: var(--accent-500);
            color: var(--primary-900);
            font-size: 10px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 0;
        }

        .sidebar-footer {
            padding: 16px 24px;
            border-top: 2px solid rgba(255, 255, 255, 0.08);
            flex-shrink: 0;
        }

        .sidebar-footer .user-card {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-footer .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--accent-500);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            color: var(--primary-900);
            border-radius: 0;
        }

        .sidebar-footer .user-info {
            flex: 1;
        }
        .sidebar-footer .user-info .name {
            font-size: 13px;
            font-weight: 600;
            color: white;
        }
        .sidebar-footer .user-info .role {
            font-size: 11px;
            color: var(--surface-400);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* ============================================================
           MAIN CONTENT
           ============================================================ */
        .main {
            flex: 1;
            margin-left: 280px;
            padding: 0;
            min-height: 100vh;
            background: var(--surface-100);
        }

        /* ============================================================
           TOP BAR - Sharp edges
           ============================================================ */
        .top-bar {
            padding: 20px 40px 16px;
            background: white;
            border-bottom: 2px solid var(--surface-200);
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .top-bar .greeting h1 {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }
        .top-bar .greeting p {
            color: var(--surface-500);
            font-size: 14px;
            margin-top: 2px;
        }

        .top-bar .greeting .org-tag {
            display: inline-block;
            background: var(--primary-900);
            color: var(--accent-400);
            padding: 2px 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 0;
            margin-left: 8px;
        }

        .top-bar .actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .top-bar .btn-icon {
            width: 40px;
            height: 40px;
            border: 2px solid var(--surface-200);
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            cursor: pointer;
            transition: var(--transition);
            color: var(--surface-600);
            border-radius: 0;
        }
        .top-bar .btn-icon:hover {
            border-color: var(--primary-500);
            color: var(--primary-500);
            background: var(--surface-50);
        }

        .top-bar .datetime {
            font-size: 13px;
            color: var(--surface-500);
            font-weight: 500;
            font-family: var(--font-mono);
            letter-spacing: 0.5px;
            border: 2px solid var(--surface-200);
            padding: 6px 14px;
            border-radius: 0;
        }

        /* ============================================================
           DASHBOARD CONTENT
           ============================================================ */
        .dashboard-content {
            padding: 28px 40px 40px;
        }

        /* ============================================================
           STATS GRID - Sharp edges
           ============================================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            padding: 24px 28px;
            border: 2px solid var(--surface-200);
            border-radius: 0;
            transition: var(--transition);
            position: relative;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent-500);
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover {
            border-color: var(--surface-300);
            transform: translateY(-2px);
        }

        .stat-card:hover::before { opacity: 1; }

        .stat-card .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            border-radius: 0;
            border: 2px solid var(--surface-200);
        }

        .stat-card .stat-icon.blue { background: var(--info-bg); color: var(--info); border-color: var(--info); }
        .stat-card .stat-icon.green { background: var(--success-bg); color: var(--success); border-color: var(--success); }
        .stat-card .stat-icon.yellow { background: var(--warning-bg); color: var(--warning); border-color: var(--warning); }
        .stat-card .stat-icon.purple { background: #ede9fe; color: #7c3aed; border-color: #7c3aed; }

        .stat-card .stat-value {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -1px;
            margin-top: 12px;
            font-family: var(--font-mono);
        }

        .stat-card .stat-label {
            color: var(--surface-500);
            font-size: 13px;
            font-weight: 500;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .stat-card .stat-trend {
            font-size: 12px;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 0;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 2px solid transparent;
        }

        .stat-card .stat-trend.up { background: var(--success-bg); color: var(--success); border-color: var(--success); }
        .stat-card .stat-trend.down { background: var(--danger-bg); color: var(--danger); border-color: var(--danger); }
        .stat-card .stat-trend.neutral { background: var(--surface-200); color: var(--surface-600); border-color: var(--surface-300); }

        /* ============================================================
           QUICK ACTIONS - Sharp edges
           ============================================================ */
        .quick-actions {
            display: flex;
            gap: 12px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 12px 24px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            border-radius: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .action-btn.primary {
            background: var(--primary-900);
            color: white;
            border: 2px solid var(--primary-900);
        }
        .action-btn.primary:hover {
            background: var(--primary-800);
            transform: translateY(-2px);
        }

        .action-btn.secondary {
            background: white;
            color: var(--surface-700);
            border: 2px solid var(--surface-200);
        }
        .action-btn.secondary:hover {
            background: var(--surface-50);
            border-color: var(--surface-300);
            transform: translateY(-2px);
        }

        .action-btn.gold {
            background: var(--accent-500);
            color: var(--primary-900);
            border: 2px solid var(--accent-600);
        }
        .action-btn.gold:hover {
            background: var(--accent-400);
            transform: translateY(-2px);
        }

        /* ============================================================
           CARDS - Sharp edges
           ============================================================ */
        .card {
            background: white;
            border: 2px solid var(--surface-200);
            border-radius: 0;
            overflow: hidden;
            margin-bottom: 28px;
        }

        .card-header {
            padding: 18px 24px;
            border-bottom: 2px solid var(--surface-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface-50);
        }

        .card-header h3 {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .card-header a {
            color: var(--info);
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: var(--transition);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid transparent;
        }
        .card-header a:hover { border-bottom-color: var(--info); }

        /* ============================================================
           TABLE - Sharp edges
           ============================================================ */
        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px 20px;
            text-align: left;
            border-bottom: 1px solid var(--surface-200);
        }

        th {
            background: var(--surface-50);
            font-weight: 700;
            font-size: 10px;
            color: var(--surface-500);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 2px solid var(--surface-200);
        }

        tr:hover td { background: var(--surface-50); }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 0;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 2px solid transparent;
        }

        .status .dot {
            width: 6px;
            height: 6px;
            display: inline-block;
        }

        .status.completed { background: var(--success-bg); color: var(--success); border-color: var(--success); }
        .status.completed .dot { background: var(--success); }

        .status.processing { background: var(--warning-bg); color: var(--warning); border-color: var(--warning); }
        .status.processing .dot { background: var(--warning); animation: pulse 1.5s infinite; }

        .status.ready_for_approval { background: var(--info-bg); color: var(--info); border-color: var(--info); }
        .status.ready_for_approval .dot { background: var(--info); }

        .status.failed { background: var(--danger-bg); color: var(--danger); border-color: var(--danger); }
        .status.failed .dot { background: var(--danger); }

        .status.draft { background: var(--surface-200); color: var(--surface-600); border-color: var(--surface-300); }
        .status.draft .dot { background: var(--surface-400); }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        .view-link {
            color: var(--info);
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: var(--transition);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid transparent;
            padding-bottom: 2px;
        }
        .view-link:hover { border-bottom-color: var(--info); }

        code {
            background: var(--surface-100);
            padding: 2px 10px;
            font-family: var(--font-mono);
            font-size: 12px;
            font-weight: 600;
            color: var(--surface-700);
            border: 1px solid var(--surface-200);
            border-radius: 0;
        }

        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--surface-500);
        }
        .empty-state .icon { font-size: 48px; margin-bottom: 12px; display: block; }
        .empty-state h4 { font-size: 18px; color: var(--surface-700); margin-bottom: 4px; }
        .empty-state a { color: var(--info); text-decoration: none; font-weight: 600; border-bottom: 2px solid transparent; }
        .empty-state a:hover { border-bottom-color: var(--info); }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 992px) {
            .sidebar { width: 64px; }
            .sidebar-header h2, .sidebar-header .org-badge, 
            .sidebar-nav .nav-label, .nav-item span:not(.icon),
            .sidebar-footer .user-info { display: none; }
            .sidebar-header .logo { justify-content: center; }
            .sidebar-header { padding: 16px; }
            .nav-item { justify-content: center; padding: 12px; border-left: none !important; }
            .nav-item.active { border-left: none !important; border-top: 2px solid var(--accent-500); }
            .sidebar-footer .user-card { justify-content: center; }
            .main { margin-left: 64px; }
            .dashboard-content { padding: 20px; }
            .top-bar { padding: 16px 20px; }
        }

        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: 1fr; }
            .quick-actions { flex-direction: column; }
            .action-btn { justify-content: center; }
            .top-bar .greeting h1 { font-size: 18px; }
            .top-bar .datetime { display: none; }
        }
    </style>
</head>
<body>
<div class="app">
    <!-- ============================================================
    SIDEBAR
    ============================================================ -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">VM</div>
                <div>
                    <h2>VouchMorph <span>Enterprise</span></h2>
                    <div class="org-badge"><?php echo htmlspecialchars($user['organization_name'] ?? 'Government'); ?></div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="index.php" class="nav-item active">
                <span class="icon">⬛</span>
                <span>Command Center</span>
            </a>
            <a href="imports/upload.php" class="nav-item">
                <span class="icon">▣</span>
                <span>New Disbursement</span>
            </a>
            <a href="batches/index.php" class="nav-item">
                <span class="icon">▦</span>
                <span>Batches</span>
                <?php if ($stats['pending'] > 0): ?>
                    <span class="badge"><?php echo $stats['pending']; ?></span>
                <?php endif; ?>
            </a>
            <a href="beneficiaries/index.php" class="nav-item">
                <span class="icon">◈</span>
                <span>Beneficiaries</span>
            </a>

            <div class="nav-label" style="margin-top: 16px;">Management</div>
            <a href="templates/index.php" class="nav-item">
                <span class="icon">▤</span>
                <span>Templates</span>
            </a>
            <a href="reports/index.php" class="nav-item">
                <span class="icon">▥</span>
                <span>Reports</span>
            </a>
            <a href="settings/index.php" class="nav-item">
                <span class="icon">◆</span>
                <span>Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 1)); ?></div>
                <div class="user-info">
                    <div class="name"><?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></div>
                    <div class="role"><?php echo ucfirst($user['role'] ?? 'User'); ?></div>
                </div>
                <a href="logout.php" style="color: var(--surface-400); text-decoration: none; font-size: 18px; border-left: 2px solid var(--surface-400); padding-left: 12px;">↗</a>
            </div>
        </div>
    </aside>

    <!-- ============================================================
    MAIN
    ============================================================ -->
    <main class="main">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="greeting">
                <h1>⬛ Command Center <span class="org-tag"><?php echo date('Y'); ?></span></h1>
                <p><?php echo htmlspecialchars($user['organization_name']); ?> • <?php echo date('l, F j, Y'); ?></p>
            </div>
            <div class="actions">
                <span class="datetime">⏱ <?php echo date('H:i T'); ?></span>
                <button class="btn-icon" title="Notifications" onclick="alert('No new notifications')">◉</button>
                <button class="btn-icon" title="Refresh" onclick="location.reload()">⟳</button>
            </div>
        </div>

        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon blue">⧫</div>
                        <?php 
                            $trendClass = $stats['trend'] > 0 ? 'up' : ($stats['trend'] < 0 ? 'down' : 'neutral');
                            $trendSymbol = $stats['trend'] > 0 ? '▲' : ($stats['trend'] < 0 ? '▼' : '■');
                        ?>
                        <span class="stat-trend <?php echo $trendClass; ?>">
                            <?php echo $trendSymbol; ?> <?php echo abs($stats['trend']); ?>%
                        </span>
                    </div>
                    <div class="stat-value">BWP <?php echo number_format($stats['disbursed'], 2); ?></div>
                    <div class="stat-label">Disbursed This Month</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon green">◈</div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['success_rate'], 2); ?>%</div>
                    <div class="stat-label">Success Rate • <?php echo number_format($stats['successful_payments']); ?> payments</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon yellow">◉</div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
                    <div class="stat-label">Pending Approval • Awaiting review</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon purple">◊</div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['beneficiaries']); ?></div>
                    <div class="stat-label">Active Beneficiaries</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="imports/upload.php" class="action-btn gold">▣ New Disbursement</a>
                <a href="beneficiaries/import.php" class="action-btn secondary">◈ Import Beneficiaries</a>
                <a href="templates/index.php" class="action-btn secondary">▤ Templates</a>
                <a href="reports/index.php" class="action-btn secondary">▥ Reports</a>
            </div>

            <!-- Recent Batches -->
            <div class="card">
                <div class="card-header">
                    <h3>▦ Recent Disbursements</h3>
                    <a href="batches/index.php">View All ▸</a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Description</th>
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
                                    <td><strong>BWP <?php echo number_format($batch['total_amount'] ?? 0, 2); ?></strong></td>
                                    <td>
                                        <span class="status <?php echo $status; ?>">
                                            <span class="dot"></span>
                                            <?php echo htmlspecialchars($statusDisplay); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($batch['created_at'] ?? 'now')); ?></td>
                                    <td><a href="batches/view.php?id=<?php echo $batch['id']; ?>" class="view-link">Review ▸</a></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <span class="icon">▢</span>
                                            <h4>No disbursements yet</h4>
                                            <p>Start by creating your first payment batch.</p>
                                            <a href="imports/upload.php">Create New Disbursement ▸</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Footer -->
            <div style="text-align: center; padding: 16px 0; color: var(--surface-400); font-size: 11px; border-top: 2px solid var(--surface-200); letter-spacing: 0.5px; text-transform: uppercase;">
                <span>■ SECURE ENTERPRISE PLATFORM • ISO 27001 CERTIFIED</span>
                <span style="margin: 0 12px;">■</span>
                <span>© <?php echo date('Y'); ?> VOUCHMORPH • GOVERNMENT OF BOTSWANA</span>
            </div>
        </div>
    </main>
</div>
</body>
</html>
