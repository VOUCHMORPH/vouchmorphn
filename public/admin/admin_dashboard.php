<?php
/**
 * admin_dashboard.php - VouchMorph Enhanced Role-Based Admin Dashboard
 * Features: Role-specific views, Report Generation, Live Transactions
 * Role IDs: 999=Super Admin, 3=Regulator, 4=Compliance, 5=Auditor
 *           10=Finance Manager, 11=Settlement Officer, 12=Revenue Officer
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Define project root
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load required classes
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;

// Check if admin is logged in
if (!SessionManager::isAdminLoggedIn()) {
    header('Location: admin_login.php');
    exit();
}

// Get admin info
$adminId = SessionManager::getAdminId();
$adminUsername = SessionManager::getAdminUsername();
$adminFullName = SessionManager::get('admin_full_name');
$adminRoleId = SessionManager::getAdminRoleId();
$adminCountry = SessionManager::getAdminCountry();

// ============================================================
// ENHANCED ROLE DEFINITIONS WITH REPORT PERMISSIONS
// ============================================================

$roleDefinitions = [
    999 => [
        'name' => 'Super Admin',
        'level' => 100,
        'permissions' => ['all'],
        'view' => [
            'dashboard', 'live_transactions', 'transactions', 'holds', 'audit', 
            'invoices', 'reports', 'regulatory', 'users', 'all_tables', 'fee_breakdown', 
            'revenue_split', 'participant_fees', 'financial_dashboard',
            'settlement_analysis', 'forex_fees', 'corridor_fees',
            'recent_swaps', 'swap_transactions', 'cross_border',
            'settlements', 'net_positions', 'regulatory_reports',
            'generate_reports', 'role_reports', 'multi_destination'
        ],
        'actions' => [
            'create', 'edit', 'delete', 'export', 'approve', 'reject', 
            'generate_invoice', 'manage_users', 'view_fee_breakdown', 
            'view_revenue_split', 'generate_financial_report', 'manage_fees',
            'generate_all_reports'
        ],
        'report_types' => ['all', 'financial', 'regulatory', 'compliance', 'audit', 'settlement', 'revenue'],
        'label' => '🔴 Super Admin',
        'badge_color' => '#dc3545'
    ],
    3 => [
        'name' => 'Central Bank Regulator',
        'level' => 90,
        'permissions' => ['view_all', 'audit_logs', 'regulatory_oversight', 'export_reports'],
        'view' => [
            'dashboard', 'regulatory', 'audit', 'reports', 'transactions_readonly',
            'fee_breakdown', 'revenue_split', 'financial_dashboard',
            'recent_swaps', 'cross_border', 'net_positions',
            'regulatory_reports', 'generate_reports', 'multi_destination'
        ],
        'actions' => ['view', 'export', 'approve_regulatory', 'generate_regulatory_report'],
        'report_types' => ['regulatory', 'compliance', 'audit', 'net_positions', 'cross_border'],
        'label' => '🏛️ Central Bank Regulator',
        'badge_color' => '#8B0000'
    ],
    4 => [
        'name' => 'Compliance Officer',
        'level' => 80,
        'permissions' => ['review_transactions', 'kyc_verification', 'compliance_checks'],
        'view' => [
            'dashboard', 'transactions', 'audit', 'reports', 'compliance',
            'fee_breakdown', 'recent_swaps', 'generate_reports'
        ],
        'actions' => ['view', 'review', 'approve', 'reject', 'export', 'generate_compliance_report'],
        'report_types' => ['compliance', 'audit', 'transaction', 'aml'],
        'label' => '📋 Compliance Officer',
        'badge_color' => '#0056b3'
    ],
    5 => [
        'name' => 'Auditor',
        'level' => 70,
        'permissions' => ['read_only', 'audit_logs', 'view_reports'],
        'view' => [
            'dashboard', 'audit', 'reports', 'transactions_readonly',
            'fee_breakdown', 'revenue_split', 'recent_swaps',
            'net_positions', 'generate_reports'
        ],
        'actions' => ['view', 'export', 'generate_audit_report'],
        'report_types' => ['audit', 'transaction', 'fee', 'compliance'],
        'label' => '🔍 Auditor',
        'badge_color' => '#6c757d'
    ],
    10 => [
        'name' => 'Finance Manager',
        'level' => 85,
        'permissions' => [
            'view_financials', 'view_fees', 'view_invoices',
            'generate_invoices', 'export_financial_reports'
        ],
        'view' => [
            'dashboard', 'invoices', 'fee_breakdown', 'participant_fees',
            'revenue_split', 'financial_dashboard', 'settlement_analysis',
            'reports', 'forex_fees', 'recent_swaps', 'swap_transactions',
            'settlements', 'net_positions', 'generate_reports'
        ],
        'actions' => ['view', 'export', 'generate_invoice', 'generate_financial_report'],
        'report_types' => ['financial', 'fee', 'revenue', 'settlement', 'forex', 'invoice'],
        'label' => '💰 Finance Manager',
        'badge_color' => '#28a745'
    ],
    11 => [
        'name' => 'Settlement Officer',
        'level' => 75,
        'permissions' => [
            'view_settlements', 'process_settlements',
            'view_net_positions', 'view_corridor_fees'
        ],
        'view' => [
            'dashboard', 'settlements', 'net_positions',
            'corridor_fees', 'reports', 'settlement_analysis',
            'recent_swaps', 'cross_border', 'generate_reports'
        ],
        'actions' => ['view', 'process', 'export', 'acknowledge_settlement', 'generate_settlement_report'],
        'report_types' => ['settlement', 'net_positions', 'corridor', 'cross_border'],
        'label' => '🏦 Settlement Officer',
        'badge_color' => '#17a2b8'
    ],
    12 => [
        'name' => 'Revenue Officer',
        'level' => 80,
        'permissions' => [
            'view_revenue', 'view_fee_collections',
            'view_participant_revenue', 'generate_revenue_reports'
        ],
        'view' => [
            'dashboard', 'revenue', 'fee_collections',
            'participant_revenue', 'forex_fees', 'reports',
            'revenue_breakdown', 'recent_swaps', 'generate_reports'
        ],
        'actions' => ['view', 'export', 'generate_report', 'generate_revenue_report'],
        'report_types' => ['revenue', 'fee', 'participant', 'forex'],
        'label' => '📊 Revenue Officer',
        'badge_color' => '#ffc107'
    ],
    13 => [
        'name' => 'Compliance Auditor',
        'level' => 78,
        'permissions' => [
            'view_compliance', 'audit_transactions',
            'view_aml_reports', 'generate_compliance_reports'
        ],
        'view' => [
            'dashboard', 'compliance', 'audit', 'transactions_readonly',
            'reports', 'aml_monitoring', 'fee_compliance', 'recent_swaps',
            'generate_reports'
        ],
        'actions' => ['view', 'export', 'generate_compliance_report', 'flag_suspicious'],
        'report_types' => ['compliance', 'aml', 'audit', 'fee_compliance'],
        'label' => '🔐 Compliance Auditor',
        'badge_color' => '#6f42c1'
    ]
];

// Get role info
$roleInfo = $roleDefinitions[$adminRoleId] ?? $roleDefinitions[5];
$roleName = $roleInfo['name'] ?? 'Auditor';
$userPermissions = $roleInfo['permissions'] ?? ['read_only'];
$availableViews = $roleInfo['view'] ?? ['dashboard'];
$availableActions = $roleInfo['actions'] ?? ['view'];
$reportTypes = $roleInfo['report_types'] ?? [];

// Role type flags
$isSuperAdmin = ($adminRoleId === 999);
$isRegulator = ($adminRoleId === 3);
$isCompliance = ($adminRoleId === 4);
$isAuditor = ($adminRoleId === 5);
$isFinanceManager = ($adminRoleId === 10);
$isSettlementOfficer = ($adminRoleId === 11);
$isRevenueOfficer = ($adminRoleId === 12);
$isComplianceAuditor = ($adminRoleId === 13);
$isReadOnly = in_array('read_only', $userPermissions) || $isAuditor || $isRegulator;

// Permission helpers
function hasPermission($permission) {
    global $userPermissions, $roleInfo;
    return in_array('all', $userPermissions) || in_array($permission, $userPermissions) || in_array($permission, $roleInfo['actions'] ?? []);
}

function canView($view) {
    global $availableViews, $isSuperAdmin;
    return $isSuperAdmin || in_array($view, $availableViews);
}

function canGenerateReport($reportType) {
    global $reportTypes, $isSuperAdmin;
    return $isSuperAdmin || in_array($reportType, $reportTypes);
}

function isReadOnly() {
    global $isReadOnly;
    return $isReadOnly;
}

function hasFinancialAccess() {
    global $isFinanceManager, $isRevenueOfficer, $isSuperAdmin;
    return $isFinanceManager || $isRevenueOfficer || $isSuperAdmin;
}

function getRoleDisplayName() {
    global $roleInfo;
    return $roleInfo['label'] ?? $roleInfo['name'] ?? 'User';
}

// Database connection
try {
    $db = DBConnection::getConnection();
    if (!$db) throw new Exception("Database connection failed");
    $db->query("SELECT 1");
    error_log("[ADMIN DASHBOARD] Database connected successfully");
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] DB Error: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// Get view and parameters
$view = $_GET['view'] ?? 'dashboard';
$search = $_GET['search'] ?? '';
$exportTable = $_GET['export'] ?? '';
$action = $_GET['action'] ?? '';
$reportType = $_GET['report_type'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'html';

// Helper for safe HTML
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// FETCH MULTI-DESTINATION SWAPS
// ============================================================
$multiDestinationSwaps = [];
try {
    $stmt = $db->query("
        SELECT * FROM multi_destination_swaps 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $multiDestinationSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Multi-destination fetch error: " . $e->getMessage());
}

// ============================================================
// FETCH MULTI-SOURCE SWAPS
// ============================================================
$multiSourceSwaps = [];
try {
    $stmt = $db->query("
        SELECT * FROM multi_source_swaps 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $multiSourceSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Multi-source fetch error: " . $e->getMessage());
}

// ============================================================
// FETCH RECENT IDENTITY SWAPS
// ============================================================
$identitySwaps = [];
try {
    $stmt = $db->query("
        SELECT * FROM identity_swap_holds 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $identitySwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Identity swaps fetch error: " . $e->getMessage());
}

// ============================================================
// FETCH RECENT SWAPS FROM vw_all_swaps
// ============================================================
$recentSwaps = [];
try {
    $stmt = $db->query("
        SELECT 
            reference,
            swap_reference,
            swap_type,
            source_institution,
            destination_institution,
            amount,
            currency,
            status,
            fee_amount,
            created_at,
            CASE 
                WHEN swap_type IN ('MULTI_DESTINATION') THEN 'MULTI_DEST'
                WHEN swap_type IN ('MULTI_SOURCE') THEN 'MULTI_SRC'
                WHEN swap_type IN ('IDENTITY') THEN 'IDENTITY'
                ELSE swap_type
            END as display_type
        FROM vw_all_swaps 
        ORDER BY created_at DESC 
        LIMIT 100
    ");
    $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Recent swaps fetch error: " . $e->getMessage());
}

// ============================================================
// METRICS
// ============================================================
$metrics = [];
try {
    $metrics['total_users'] = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $metrics['total_swaps'] = (int)$db->query("SELECT COUNT(*) FROM vw_all_swaps")->fetchColumn();
    $metrics['pending_settlements'] = (int)$db->query("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'")->fetchColumn();
    $metrics['total_fees'] = (float)$db->query("SELECT COALESCE(SUM((message_payload->>'fee_amount')::numeric), 0) FROM settlement_outbox WHERE message_type = 'FEE_INVOICE'")->fetchColumn();
    $metrics['recent_swaps_24h'] = (int)$db->query("SELECT COUNT(*) FROM vw_all_swaps WHERE created_at >= NOW() - INTERVAL '24 hours'")->fetchColumn();
    $metrics['multi_destination_count'] = (int)$db->query("SELECT COUNT(*) FROM multi_destination_swaps")->fetchColumn();
    $metrics['multi_source_count'] = (int)$db->query("SELECT COUNT(*) FROM multi_source_swaps")->fetchColumn();
    $metrics['identity_swaps_pending'] = (int)$db->query("SELECT COUNT(*) FROM identity_swap_holds WHERE status = 'pending'")->fetchColumn();
} catch (Throwable $e) {
    $metrics = array_fill_keys(['total_users', 'total_swaps', 'pending_settlements', 'total_fees', 'recent_swaps_24h', 'multi_destination_count', 'multi_source_count', 'identity_swaps_pending'], 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · <?php echo safeHtml($roleName); ?> DASHBOARD</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #f7f9fc;
            color: #001B44;
            min-height: 100vh;
        }
        .admin-header {
            background: #001B44;
            border-bottom: 5px solid #FFDA63;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
            flex-wrap: wrap;
            gap: 10px;
        }
        .header-left { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .logo { font-size: 1.1rem; font-weight: 700; letter-spacing: 2px; }
        .logo span { color: #FFDA63; }
        .role-badge {
            padding: 4px 12px;
            background: <?php echo $roleInfo['badge_color'] ?? '#FFDA63'; ?>;
            color: #fff;
            font-size: 0.7rem;
            text-transform: uppercase;
            border-radius: 4px;
            font-weight: 700;
        }
        .readonly-badge {
            padding: 4px 12px;
            background: #f8d7da;
            color: #721c24;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 4px;
            border: 1px solid #f5c6cb;
        }
        .user-info { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; color: #FFDA63; font-size: 0.9rem; }
        .user-role { font-size: 0.65rem; color: #A1B5D8; text-transform: uppercase; }
        .logout-btn {
            padding: 6px 14px;
            background: transparent;
            border: 2px solid #FFDA63;
            color: #FFDA63;
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 600;
            transition: all 0.2s;
            border-radius: 4px;
        }
        .logout-btn:hover { background: #FFDA63; color: #001B44; }
        
        .admin-nav {
            background: #fff;
            border-bottom: 2px solid #001B44;
            padding: 0 24px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: 12px 0;
            color: #666;
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .nav-item:hover { color: #001B44; }
        .nav-item.active { color: #001B44; border-bottom-color: #FFDA63; }
        .nav-item.finance { color: #28a745; }
        .nav-item.finance.active { border-bottom-color: #28a745; }
        .nav-item.regulator { color: #8B0000; }
        .nav-item.regulator.active { border-bottom-color: #8B0000; }
        
        .admin-content { padding: 24px; max-width: 1600px; margin: 0 auto; }
        .content-header {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .content-header h1 { font-size: 1.3rem; font-weight: 600; color: #001B44; }
        .content-header .timestamp { color: #666; font-size: 0.7rem; }
        
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .metric-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 12px 16px;
            box-shadow: 3px 3px 0 #A1B5D8;
        }
        .metric-label {
            font-size: 0.55rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .metric-value { font-size: 1.5rem; font-weight: 600; color: #001B44; }
        .metric-value .sub { font-size: 0.8rem; color: #666; }
        .metric-value .small { font-size: 0.9rem; }
        
        .card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 3px 3px 0 #A1B5D8;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #001B44;
            flex-wrap: wrap;
            gap: 8px;
        }
        .card-title { font-size: 0.9rem; font-weight: 700; text-transform: uppercase; }
        .card-badge {
            padding: 2px 10px;
            background: #001B44;
            color: #fff;
            font-size: 0.6rem;
            border-radius: 12px;
        }
        .card-badge.success { background: #28a745; }
        .card-badge.warning { background: #856404; }
        .card-badge.danger { background: #dc3545; }
        .card-badge.info { background: #17a2b8; }
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.7rem; }
        th {
            background: #001B44;
            color: #fff;
            padding: 6px 10px;
            font-weight: 600;
            text-align: left;
            font-size: 0.55rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        td { padding: 5px 10px; border-bottom: 1px solid #eee; font-size: 0.65rem; }
        tr:hover { background: #f5f5f5; }
        tr:nth-child(even) { background: #fafafa; }
        
        .status {
            display: inline-block;
            padding: 1px 8px;
            font-size: 0.55rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
            border-radius: 3px;
        }
        .status-success { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .status-pending { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .status-failed { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .status-info { background: #cce5ff; color: #004085; border-color: #b8daff; }
        .status-warning { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .status-processing { background: #cce5ff; color: #004085; border-color: #b8daff; }
        .status-identity { background: #e8d5f5; color: #6f42c1; border-color: #d4b8e8; }
        
        .btn {
            padding: 6px 14px;
            font-size: 0.65rem;
            border: 2px solid #001B44;
            background: #fff;
            color: #001B44;
            cursor: pointer;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 600;
            text-transform: uppercase;
            transition: all 0.2s;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: #001B44; color: #fff; }
        .btn-primary { background: #001B44; color: #fff; }
        .btn-primary:hover { background: #FFDA63; color: #001B44; border-color: #FFDA63; }
        .btn-success { border-color: #28a745; color: #28a745; }
        .btn-success:hover { background: #28a745; color: #fff; }
        .btn-danger { border-color: #dc3545; color: #dc3545; }
        .btn-danger:hover { background: #dc3545; color: #fff; }
        .btn-regulator { border-color: #8B0000; color: #8B0000; }
        .btn-regulator:hover { background: #8B0000; color: #fff; }
        .btn-finance { border-color: #28a745; color: #28a745; }
        .btn-finance:hover { background: #28a745; color: #fff; }
        .btn-sm { padding: 2px 8px; font-size: 0.55rem; }
        
        .empty-state { text-align: center; padding: 30px; color: #999; }
        .empty-state .icon { font-size: 2rem; margin-bottom: 8px; }
        
        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 16px 24px;
            font-size: 0.65rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
            margin-top: 24px;
        }
        
        .destination-detail {
            background: #f8f9fa;
            padding: 8px 12px;
            margin: 4px 0;
            border-left: 3px solid #001B44;
            font-size: 0.6rem;
        }
        .destination-detail .label { color: #666; font-weight: 600; }
        .destination-detail .value { color: #001B44; }
        .destination-detail.failed { border-left-color: #dc3545; background: #f8d7da; }
        .destination-detail.success { border-left-color: #28a745; background: #d4edda; }
        .destination-detail.pending { border-left-color: #856404; background: #fff3cd; }
        .destination-detail.identity { border-left-color: #6f42c1; background: #e8d5f5; }
        
        .fee-breakdown-detail {
            font-size: 0.55rem;
            color: #666;
            margin-top: 4px;
        }
        .fee-breakdown-detail .amount { color: #28a745; font-weight: 600; }
        
        .expandable { cursor: pointer; }
        .expandable:hover { background: #f0f0f0; }
        .expand-content { display: none; padding: 8px; background: #f8f9fa; border-top: 1px solid #eee; }
        .expand-content.show { display: block; }
        
        @media (max-width: 768px) {
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .admin-nav { padding: 0 12px; gap: 10px; }
            .admin-content { padding: 12px; }
            .admin-header { padding: 12px; }
            th, td { font-size: 0.55rem; padding: 4px 6px; }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="role-badge"><?php echo safeHtml($roleInfo['label'] ?? $roleName); ?></div>
            <?php if ($isReadOnly): ?>
            <span class="readonly-badge">🔒 READ ONLY</span>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo safeHtml($adminFullName ?: $adminUsername); ?></div>
                <div class="user-role"><?php echo safeHtml($roleName); ?></div>
            </div>
            <a href="admin_logout.php" class="logout-btn">LOGOUT</a>
        </div>
    </header>

    <nav class="admin-nav">
        <?php if (canView('dashboard')): ?>
        <a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">📊 DASHBOARD</a>
        <?php endif; ?>
        
        <?php if (canView('multi_destination')): ?>
        <a href="?view=multi_destination" class="nav-item <?php echo $view === 'multi_destination' ? 'active' : ''; ?>">🎯 MULTI-DEST</a>
        <?php endif; ?>
        
        <?php if (canView('recent_swaps')): ?>
        <a href="?view=recent_swaps" class="nav-item <?php echo $view === 'recent_swaps' ? 'active' : ''; ?>">🔄 SWAPS</a>
        <?php endif; ?>
        
        <?php if (canView('settlements')): ?>
        <a href="?view=settlements" class="nav-item <?php echo $view === 'settlements' ? 'active' : ''; ?>">📤 SETTLEMENTS</a>
        <?php endif; ?>
        
        <?php if (canView('regulatory')): ?>
        <a href="?view=regulatory" class="nav-item regulator <?php echo $view === 'regulatory' ? 'active' : ''; ?>">🏛️ REGULATORY</a>
        <?php endif; ?>
        
        <?php if (canView('audit')): ?>
        <a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">📝 AUDIT</a>
        <?php endif; ?>
        
        <?php if (canView('fee_breakdown') && hasFinancialAccess()): ?>
        <a href="?view=fee_breakdown" class="nav-item finance <?php echo $view === 'fee_breakdown' ? 'active' : ''; ?>">📊 FEES</a>
        <?php endif; ?>
        
        <?php if (canView('invoices') && hasPermission('generate_invoice')): ?>
        <a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">💰 INVOICES</a>
        <?php endif; ?>
        
        <?php if (canView('all_tables') && $isSuperAdmin): ?>
        <a href="?view=all_tables" class="nav-item <?php echo $view === 'all_tables' ? 'active' : ''; ?>">📋 TABLES</a>
        <?php endif; ?>
    </nav>

    <main class="admin-content">
        <!-- ============================================================ -->
        <!-- DASHBOARD VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'dashboard'): ?>
        <div class="content-header">
            <h1>📊 <?php echo safeHtml($roleName); ?> DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></div>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['total_swaps'] ?? 0); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Users</div>
                <div class="metric-value"><?php echo number_format($metrics['total_users'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #856404;">
                <div class="metric-label">Pending Settlements</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_settlements'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #28a745;">
                <div class="metric-label">Total Fees</div>
                <div class="metric-value"><?php echo number_format($metrics['total_fees'] ?? 0, 2); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">24h Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['recent_swaps_24h'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #6f42c1;">
                <div class="metric-label">Multi-Destination</div>
                <div class="metric-value"><?php echo number_format($metrics['multi_destination_count'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #17a2b8;">
                <div class="metric-label">Multi-Source</div>
                <div class="metric-value"><?php echo number_format($metrics['multi_source_count'] ?? 0); ?></div>
            </div>
            <div class="metric-card" style="border-color: #8B0000;">
                <div class="metric-label">Pending Identity</div>
                <div class="metric-value"><?php echo number_format($metrics['identity_swaps_pending'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">⚡ Quick Actions</span>
            </div>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <?php if (hasPermission('generate_invoice')): ?>
                <a href="?action=generate_invoice" class="btn btn-finance">💰 Generate Invoice</a>
                <?php endif; ?>
                <?php if (canView('all_tables') && $isSuperAdmin): ?>
                <a href="?view=all_tables" class="btn">📋 View All Tables</a>
                <?php endif; ?>
            </div>
            <?php if (!empty($success)): ?>
            <div style="margin-top:12px; padding:12px; background:#d4edda; color:#155724; border:2px solid #c3e6cb; border-radius:4px;">
                ✅ <?php echo safeHtml($success); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- MULTI-DESTINATION VIEW - DETAILED -->
        <!-- ============================================================ -->
        <?php if ($view === 'multi_destination' && canView('multi_destination')): ?>
        <div class="content-header">
            <h1>🎯 MULTI-DESTINATION SWAPS</h1>
            <div class="timestamp">Detailed multi-destination swap reports</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <?php foreach ($multiDestinationSwaps as $swap): ?>
        <div class="card" style="border-left: 6px solid <?php echo $swap['status'] === 'completed' ? '#28a745' : ($swap['status'] === 'partial' ? '#856404' : '#dc3545'); ?>;">
            <div class="card-header">
                <span class="card-title">
                    <?php echo safeHtml($swap['reference']); ?>
                    <span style="font-size:0.55rem; font-weight:400; color:#666;">
                        <?php echo date('Y-m-d H:i', strtotime($swap['created_at'])); ?>
                    </span>
                </span>
                <span class="card-badge <?php echo $swap['status'] === 'completed' ? 'success' : ($swap['status'] === 'partial' ? 'warning' : 'danger'); ?>">
                    <?php echo strtoupper($swap['status'] ?? 'UNKNOWN'); ?>
                </span>
            </div>
            
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:8px; margin-bottom:12px; font-size:0.65rem;">
                <div><strong>Source:</strong> <?php echo safeHtml($swap['source_institution']); ?></div>
                <div><strong>Total:</strong> <?php echo number_format((float)($swap['total_amount'] ?? 0), 2); ?></div>
                <div><strong>Destinations:</strong> <?php echo $swap['total_destinations']; ?></div>
                <div><strong>✅ Success:</strong> <?php echo $swap['successful_count'] ?? 0; ?></div>
                <div><strong>❌ Failed:</strong> <?php echo $swap['failed_count'] ?? 0; ?></div>
                <div><strong>💰 Fees:</strong> <?php echo number_format((float)($swap['total_fees'] ?? 0), 2); ?></div>
                <div><strong>📦 Delivered:</strong> <?php echo number_format((float)($swap['total_delivered'] ?? 0), 2); ?></div>
            </div>
            
            <div style="font-size:0.6rem; color:#666; margin-bottom:8px;">
                <strong>Destinations Payload:</strong>
            </div>
            
            <?php 
            $destinations = json_decode($swap['destinations_payload'] ?? '[]', true);
            $results = json_decode($swap['results_payload'] ?? '[]', true);
            
            if (!empty($destinations)):
            ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Institution</th>
                            <th>Identifier</th>
                            <th>Status</th>
                            <th>Hold Ref</th>
                            <th>Fee</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($destinations as $idx => $dest):
                            $result = $results[$idx] ?? [];
                            $status = $result['status'] ?? 'pending';
                            $error = $result['error'] ?? null;
                            $isIdentity = isset($dest['identity_type']) || isset($dest['identity_value']);
                            $isCashout = isset($dest['delivery_method']) && $dest['delivery_method'] === 'ATM';
                            $isBank = isset($dest['to_institution']) && !$isIdentity && !$isCashout;
                        ?>
                        <tr class="<?php echo $status === 'failed' ? 'failed' : ''; ?>">
                            <td><?php echo $idx + 1; ?></td>
                            <td>
                                <?php if ($isIdentity): ?>
                                <span class="status status-identity">IDENTITY</span>
                                <?php elseif ($isCashout): ?>
                                <span class="status status-warning">CASHOUT</span>
                                <?php elseif ($isBank): ?>
                                <span class="status status-info">DEPOSIT</span>
                                <?php else: ?>
                                <span class="status status-info">UNKNOWN</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo number_format((float)($dest['amount'] ?? 0), 2); ?></strong></td>
                            <td><?php echo safeHtml($dest['to_institution'] ?? $dest['destination_institution'] ?? ($isIdentity ? 'IDENTITY' : 'N/A')); ?></td>
                            <td>
                                <?php 
                                if ($isIdentity) {
                                    echo safeHtml($dest['identity_type'] ?? 'national_id') . ': ' . safeHtml($dest['identity_value'] ?? 'N/A');
                                } elseif ($isCashout) {
                                    echo safeHtml($dest['beneficiary_phone'] ?? 'N/A');
                                } else {
                                    echo safeHtml($dest['destination_identifier'] ?? 'N/A');
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                $statusClass = match($status) {
                                    'success', 'completed' => 'success',
                                    'failed' => 'failed',
                                    'pending', 'pending_identity_confirmation' => 'pending',
                                    default => 'info'
                                };
                                $statusLabel = $status === 'pending_identity_confirmation' ? 'PENDING_ID' : ($status ?: 'PENDING');
                                ?>
                                <span class="status status-<?php echo $statusClass; ?>"><?php echo safeHtml(strtoupper($statusLabel)); ?></span>
                            </td>
                            <td><?php echo safeHtml(substr($result['hold_reference'] ?? 'N/A', 0, 12)); ?>…</td>
                            <td><?php echo number_format((float)($result['fee'] ?? 0), 2); ?></td>
                            <td>
                                <?php if ($error): ?>
                                <span style="color:#dc3545; font-size:0.55rem;"><?php echo safeHtml(substr($error, 0, 60)); ?></span>
                                <?php else: ?>
                                <span style="color:#999;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Raw JSON details (collapsible) -->
            <details style="margin-top:12px;">
                <summary style="cursor:pointer; font-size:0.6rem; color:#666;">📄 Raw JSON details</summary>
                <pre style="background:#1e293b; color:#4ade80; padding:12px; font-size:0.55rem; overflow-x:auto; max-height:300px; overflow-y:auto; margin-top:8px;"><?php echo safeHtml(json_encode(json_decode($swap['destinations_payload'] ?? '[]', true), JSON_PRETTY_PRINT)); ?></pre>
            </details>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- RECENT SWAPS VIEW - DETAILED -->
        <!-- ============================================================ -->
        <?php if ($view === 'recent_swaps' && canView('recent_swaps')): ?>
        <div class="content-header">
            <h1>🔄 RECENT SWAPS</h1>
            <div class="timestamp">Detailed swap transactions</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">All Swaps</span>
                <span class="card-badge"><?php echo count($recentSwaps); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Fee</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSwaps)): ?>
                        <tr><td colspan="9" class="empty-state">No swaps found</td></tr>
                        <?php else: ?>
                        <?php foreach ($recentSwaps as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['reference'] ?? 'N/A', 0, 16)); ?></td>
                            <td>
                                <?php 
                                $type = $row['display_type'] ?? $row['swap_type'] ?? 'STANDARD';
                                $typeClass = match($type) {
                                    'MULTI_DEST', 'MULTI_DESTINATION' => 'status-info',
                                    'MULTI_SRC', 'MULTI_SOURCE' => 'status-processing',
                                    'IDENTITY' => 'status-identity',
                                    'CASHOUT' => 'status-warning',
                                    default => 'status-info'
                                };
                                ?>
                                <span class="status <?php echo $typeClass; ?>"><?php echo safeHtml($type); ?></span>
                            </td>
                            <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                            <td><?php echo safeHtml($row['currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = match(true) {
                                    str_contains($status, 'complet') || str_contains($status, 'success') => 'success',
                                    str_contains($status, 'pending') || str_contains($status, 'processing') || str_contains($status, 'confirmation') => 'pending',
                                    str_contains($status, 'fail') || str_contains($status, 'error') || str_contains($status, 'expired') => 'failed',
                                    default => 'info'
                                };
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo safeHtml($row['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['destination_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['fee_amount'] ?? 0), 2); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($row['created_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- ACCESS DENIED -->
        <!-- ============================================================ -->
        <?php if (!canView($view) && $view !== 'dashboard' && $view !== 'all_tables' && $view !== 'multi_destination'): ?>
        <div class="card">
            <div class="empty-state">
                <div class="icon">🚫</div>
                <h2>Access Denied</h2>
                <p>You do not have permission to view this page.</p>
                <a href="?view=dashboard" class="btn" style="margin-top:16px;">Return to Dashboard</a>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · <?php echo safeHtml($roleName); ?> · <?php echo date('Y'); ?></p>
        <p style="margin-top:4px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>
</body>
</html>
