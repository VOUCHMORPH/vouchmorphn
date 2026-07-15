<?php
/**
 * admin_dashboard.php - VouchMorph Enhanced Role-Based Admin Dashboard
 * Features: Fee Breakdown, Revenue Distribution, Participant Fee Analysis
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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;

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
// ENHANCED ROLE DEFINITIONS - Full Financial & Administrative Access
// ============================================================

$roleDefinitions = [
    // ============================================================
    // 999 - Super Admin (Full Access)
    // ============================================================
    999 => [
        'name' => 'Super Admin',
        'level' => 100,
        'permissions' => ['all'],
        'view' => [
            'dashboard', 'transactions', 'holds', 'audit', 
            'invoices', 'reports', 'regulatory', 'users', 
            'settings', 'all_tables', 'fee_breakdown', 
            'revenue_split', 'participant_fees', 'financial_dashboard',
            'settlement_analysis', 'forex_fees', 'corridor_fees'
        ],
        'actions' => [
            'create', 'edit', 'delete', 'export', 'approve', 
            'reject', 'generate_invoice', 'manage_users',
            'view_fee_breakdown', 'view_revenue_split', 
            'generate_financial_report', 'manage_fees'
        ],
        'label' => '🔴 Super Admin',
        'badge_color' => '#dc3545'
    ],

    // ============================================================
    // 3 - Central Bank Regulator
    // ============================================================
    3 => [
        'name' => 'Central Bank Regulator',
        'level' => 90,
        'permissions' => [
            'view_all', 'audit_logs', 'regulatory_oversight', 
            'export_reports', 'view_fee_breakdown', 'view_revenue_split'
        ],
        'view' => [
            'dashboard', 'regulatory', 'audit', 'reports', 
            'transactions_readonly', 'all_tables_readonly',
            'fee_breakdown', 'revenue_split', 'financial_dashboard'
        ],
        'actions' => [
            'view', 'export', 'approve_regulatory', 
            'generate_regulatory_report', 'view_fee_breakdown'
        ],
        'label' => '🏛️ Central Bank Regulator',
        'badge_color' => '#8B0000'
    ],

    // ============================================================
    // 4 - Compliance Officer
    // ============================================================
    4 => [
        'name' => 'Compliance Officer',
        'level' => 80,
        'permissions' => [
            'review_transactions', 'kyc_verification', 
            'compliance_checks', 'view_fee_breakdown'
        ],
        'view' => [
            'dashboard', 'transactions', 'audit', 'reports', 
            'compliance', 'all_tables_readonly', 'fee_breakdown'
        ],
        'actions' => ['view', 'review', 'approve', 'reject', 'export'],
        'label' => '📋 Compliance Officer',
        'badge_color' => '#0056b3'
    ],

    // ============================================================
    // 5 - Auditor
    // ============================================================
    5 => [
        'name' => 'Auditor',
        'level' => 70,
        'permissions' => [
            'read_only', 'audit_logs', 'view_reports', 
            'view_fee_breakdown', 'view_revenue_split'
        ],
        'view' => [
            'dashboard', 'audit', 'reports', 
            'transactions_readonly', 'all_tables_readonly',
            'fee_breakdown', 'revenue_split'
        ],
        'actions' => ['view', 'export'],
        'label' => '🔍 Auditor',
        'badge_color' => '#6c757d'
    ],

    // ============================================================
    // 10 - Finance Manager (NEW)
    // ============================================================
    10 => [
        'name' => 'Finance Manager',
        'level' => 85,
        'permissions' => [
            'view_financials', 'view_fees', 'view_invoices',
            'generate_invoices', 'view_participant_fees',
            'export_financial_reports', 'view_revenue_split',
            'manage_billing', 'view_forex_fees'
        ],
        'view' => [
            'dashboard', 'invoices', 'fee_breakdown', 
            'participant_fees', 'revenue_split',
            'financial_dashboard', 'settlement_analysis',
            'reports', 'forex_fees'
        ],
        'actions' => [
            'view', 'export', 'generate_invoice', 
            'view_fee_breakdown', 'view_revenue_split',
            'generate_financial_report'
        ],
        'label' => '💰 Finance Manager',
        'badge_color' => '#28a745'
    ],

    // ============================================================
    // 11 - Settlement Officer (NEW)
    // ============================================================
    11 => [
        'name' => 'Settlement Officer',
        'level' => 75,
        'permissions' => [
            'view_settlements', 'process_settlements',
            'view_net_positions', 'view_corridor_fees',
            'export_settlement_reports', 'view_settlement_analysis'
        ],
        'view' => [
            'dashboard', 'settlements', 'net_positions',
            'corridor_fees', 'reports', 'settlement_analysis'
        ],
        'actions' => ['view', 'process', 'export', 'acknowledge_settlement'],
        'label' => '🏦 Settlement Officer',
        'badge_color' => '#17a2b8'
    ],

    // ============================================================
    // 12 - Revenue Officer (NEW)
    // ============================================================
    12 => [
        'name' => 'Revenue Officer',
        'level' => 80,
        'permissions' => [
            'view_revenue', 'view_fee_collections',
            'view_participant_revenue', 'generate_revenue_reports',
            'view_forex_fees', 'view_revenue_breakdown'
        ],
        'view' => [
            'dashboard', 'revenue', 'fee_collections',
            'participant_revenue', 'forex_fees', 'reports',
            'revenue_breakdown'
        ],
        'actions' => ['view', 'export', 'generate_report', 'view_revenue_breakdown'],
        'label' => '📊 Revenue Officer',
        'badge_color' => '#ffc107'
    ],

    // ============================================================
    // 13 - Compliance Auditor (NEW)
    // ============================================================
    13 => [
        'name' => 'Compliance Auditor',
        'level' => 78,
        'permissions' => [
            'view_compliance', 'audit_transactions',
            'view_aml_reports', 'view_suspicious_activity',
            'generate_compliance_reports', 'view_fee_compliance'
        ],
        'view' => [
            'dashboard', 'compliance', 'audit',
            'transactions_readonly', 'reports', 'aml_monitoring',
            'fee_compliance'
        ],
        'actions' => [
            'view', 'export', 'generate_compliance_report',
            'flag_suspicious', 'view_fee_compliance'
        ],
        'label' => '🔐 Compliance Auditor',
        'badge_color' => '#6f42c1'
    ]
];

// Get role info based on role_id
$roleInfo = $roleDefinitions[$adminRoleId] ?? $roleDefinitions[5];
$roleName = $roleInfo['name'] ?? 'Auditor';
$userPermissions = $roleInfo['permissions'] ?? ['read_only'];
$availableViews = $roleInfo['view'] ?? ['dashboard'];
$availableActions = $roleInfo['actions'] ?? ['view'];

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

function isReadOnly() {
    global $isReadOnly;
    return $isReadOnly;
}

function hasFinancialAccess() {
    global $isFinanceManager, $isRevenueOfficer, $isSuperAdmin;
    return $isFinanceManager || $isRevenueOfficer || $isSuperAdmin;
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

// Helper for safe HTML
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// INVOICE GENERATION
// ============================================================
if ($action === 'generate_invoice' && hasPermission('generate_invoice')) {
    try {
        $invoiceDate = date('Y-m-d');
        $invoiceRef = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        $stmt = $db->prepare("
            SELECT 
                COALESCE(SUM(amount), 0) as total_amount,
                COUNT(*) as transaction_count,
                COUNT(CASE WHEN status IN ('COMPLETED', 'success') THEN 1 END) as completed_count
            FROM swap_requests 
            WHERE DATE(created_at) = CURRENT_DATE
        ");
        $stmt->execute();
        $dailyStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $feeRate = 0.015;
        $totalAmount = (float)($dailyStats['total_amount'] ?? 0);
        $feeAmount = $totalAmount * $feeRate;
        
        $stmt = $db->prepare("
            INSERT INTO fee_invoices (
                invoice_uuid, swap_reference, source_institution, 
                fee_type, fee_amount, currency, total_amount, 
                vat_amount, status, created_at
            ) VALUES (
                :uuid, :ref, :source,
                :fee_type, :fee_amount, :currency, :total,
                :vat, 'SENT', NOW()
            ) RETURNING invoice_uuid
        ");
        $stmt->execute([
            ':uuid' => $invoiceRef,
            ':ref' => 'DAILY_SETTLEMENT_' . date('Ymd'),
            ':source' => 'VOUCHMORPH_SYSTEM',
            ':fee_type' => 'DAILY_SETTLEMENT_FEE',
            ':fee_amount' => $feeAmount,
            ':currency' => 'BWP',
            ':total' => $totalAmount,
            ':vat' => $feeAmount * 0.14
        ]);
        
        $success = "Invoice {$invoiceRef} generated successfully for " . date('Y-m-d');
        error_log("[ADMIN DASHBOARD] Invoice generated: {$invoiceRef}");
        
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] Invoice generation error: " . $e->getMessage());
        $error = "Failed to generate invoice: " . $e->getMessage();
    }
}

// ============================================================
// FETCH TABLE DATA
// ============================================================
$tableData = [];
$tablesToFetch = [
    'swap_requests' => ['label' => '📋 Swap Requests', 'order' => 'created_at DESC', 'limit' => 100],
    'hold_transactions' => ['label' => '🔒 Hold Transactions', 'order' => 'created_at DESC', 'limit' => 100],
    'identity_swap_holds' => ['label' => '🆔 Identity Swap Holds', 'order' => 'created_at DESC', 'limit' => 100],
    'cashout_authorizations' => ['label' => '🏧 Cashout Authorizations', 'order' => 'created_at DESC', 'limit' => 100],
    'fee_invoices' => ['label' => '💰 Fee Invoices', 'order' => 'created_at DESC', 'limit' => 100],
    'settlement_queue' => ['label' => '📤 Settlement Queue', 'order' => 'created_at DESC', 'limit' => 100],
    'audit_logs' => ['label' => '📝 Audit Logs', 'order' => 'performed_at DESC', 'limit' => 100],
    'swap_fee_collections' => ['label' => '💳 Fee Collections', 'order' => 'created_at DESC', 'limit' => 100],
    'net_positions' => ['label' => '⚖️ Net Positions', 'order' => 'created_at DESC', 'limit' => 100],
];

foreach ($tablesToFetch as $table => $config) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table");
        $stmt->execute([':table' => $table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        
        if ($exists) {
            $orderBy = $config['order'] ?? 'created_at DESC';
            $limit = $config['limit'] ?? 100;
            $rows = $db->query("SELECT * FROM {$table} ORDER BY {$orderBy} LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
            
            $tableData[$table] = [
                'exists' => true,
                'rows' => $rows,
                'count' => count($rows),
                'label' => $config['label'],
                'columns' => !empty($rows) ? array_keys($rows[0]) : []
            ];
        } else {
            $tableData[$table] = ['exists' => false, 'rows' => [], 'count' => 0, 'label' => $config['label'], 'columns' => []];
        }
    } catch (Throwable $e) {
        $tableData[$table] = ['exists' => false, 'rows' => [], 'count' => 0, 'label' => $config['label'], 'columns' => [], 'error' => $e->getMessage()];
    }
}

// ============================================================
// FEE BREAKDOWN DATA
// ============================================================
$feeBreakdown = [];
$revenueSplit = [];
$participantFees = [];
$dailyStats = [];

try {
    // Fee breakdown by type
    $stmt = $db->query("
        SELECT 
            fee_type,
            COUNT(*) as count,
            SUM(fee_amount) as total_fee,
            SUM(total_amount) as total_with_vat,
            SUM(vat_amount) as total_vat,
            status
        FROM fee_invoices 
        GROUP BY fee_type, status
        ORDER BY total_fee DESC
    ");
    $feeBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Revenue split by participant
    $stmt = $db->query("
        SELECT 
            source_institution,
            COUNT(*) as transaction_count,
            SUM(fee_amount) as total_fee,
            SUM(total_amount) as total_revenue,
            SUM(vat_amount) as total_vat,
            COUNT(CASE WHEN status = 'PAID' THEN 1 END) as paid_count
        FROM fee_invoices 
        GROUP BY source_institution
        ORDER BY total_revenue DESC
    ");
    $revenueSplit = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Participant fee breakdown
    $stmt = $db->query("
        SELECT 
            fi.source_institution,
            fi.fee_type,
            COUNT(fi.id) as invoice_count,
            SUM(fi.fee_amount) as total_fee,
            SUM(fi.vat_amount) as total_vat,
            SUM(fi.total_amount) as total_amount,
            COUNT(CASE WHEN fi.status = 'PAID' THEN 1 END) as paid_count,
            COUNT(CASE WHEN fi.status = 'SENT' THEN 1 END) as pending_count
        FROM fee_invoices fi
        GROUP BY fi.source_institution, fi.fee_type
        ORDER BY total_amount DESC
    ");
    $participantFees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Daily stats
    $stmt = $db->query("
        SELECT 
            COALESCE(SUM(amount), 0) as total_amount,
            COUNT(*) as transaction_count,
            COUNT(CASE WHEN status IN ('COMPLETED', 'success') THEN 1 END) as completed_count
        FROM swap_requests 
        WHERE DATE(created_at) = CURRENT_DATE
    ");
    $dailyStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Fee data error: " . $e->getMessage());
}

// ============================================================
// METRICS
// ============================================================
$metrics = [];
try {
    $metrics['total_users'] = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $metrics['total_swaps'] = (int)$db->query("SELECT COUNT(*) FROM swap_requests")->fetchColumn();
    $metrics['total_holds'] = (int)$db->query("SELECT COUNT(*) FROM hold_transactions")->fetchColumn();
    $metrics['total_cashouts'] = (int)$db->query("SELECT COUNT(*) FROM cashout_authorizations")->fetchColumn();
    $metrics['total_invoices'] = (int)$db->query("SELECT COUNT(*) FROM fee_invoices")->fetchColumn();
    $metrics['total_audit_logs'] = (int)$db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    $metrics['total_fee_collections'] = (int)$db->query("SELECT COUNT(*) FROM swap_fee_collections")->fetchColumn();
    $metrics['total_fees'] = (float)$db->query("SELECT COALESCE(SUM(fee_amount), 0) FROM fee_invoices")->fetchColumn();
} catch (Throwable $e) {
    $metrics = array_fill_keys([
        'total_users', 'total_swaps', 'total_holds', 'total_cashouts', 
        'total_invoices', 'total_audit_logs', 'total_fee_collections', 'total_fees'
    ], 0);
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
        }
        td { padding: 5px 10px; border-bottom: 1px solid #eee; font-size: 0.65rem; }
        tr:hover { background: #f5f5f5; }
        
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
        
        .fee-box {
            background: #f0fdf4;
            border: 2px solid #28a745;
            padding: 16px;
            margin-bottom: 16px;
            border-left: 6px solid #28a745;
        }
        .fee-box .title {
            font-weight: 700;
            color: #28a745;
            font-size: 0.9rem;
            margin-bottom: 8px;
        }
        
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
        
        @media (max-width: 768px) {
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .admin-nav { padding: 0 12px; gap: 10px; }
            .admin-content { padding: 12px; }
            .admin-header { padding: 12px; }
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
        
        <?php if (canView('transactions') || canView('transactions_readonly')): ?>
        <a href="?view=transactions" class="nav-item <?php echo $view === 'transactions' ? 'active' : ''; ?>">📋 TRANSACTIONS</a>
        <?php endif; ?>
        
        <?php if (canView('invoices') && hasPermission('generate_invoice')): ?>
        <a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">💰 INVOICES</a>
        <?php endif; ?>
        
        <?php if (canView('fee_breakdown') && hasFinancialAccess()): ?>
        <a href="?view=fee_breakdown" class="nav-item finance <?php echo $view === 'fee_breakdown' ? 'active' : ''; ?>">📊 FEES</a>
        <?php endif; ?>
        
        <?php if (canView('participant_fees') && hasFinancialAccess()): ?>
        <a href="?view=participant_fees" class="nav-item finance <?php echo $view === 'participant_fees' ? 'active' : ''; ?>">🏛️ PARTICIPANTS</a>
        <?php endif; ?>
        
        <?php if (canView('revenue_split') && hasFinancialAccess()): ?>
        <a href="?view=revenue_split" class="nav-item finance <?php echo $view === 'revenue_split' ? 'active' : ''; ?>">📈 REVENUE</a>
        <?php endif; ?>
        
        <?php if (canView('regulatory') && ($isRegulator || $isSuperAdmin)): ?>
        <a href="?view=regulatory" class="nav-item regulator <?php echo $view === 'regulatory' ? 'active' : ''; ?>">🏛️ REGULATORY</a>
        <?php endif; ?>
        
        <?php if (canView('audit')): ?>
        <a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">📝 AUDIT</a>
        <?php endif; ?>
        
        <?php if (canView('reports')): ?>
        <a href="?view=reports" class="nav-item <?php echo $view === 'reports' ? 'active' : ''; ?>">📈 REPORTS</a>
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
            <h1>📊 DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></div>
        </div>

        <div class="metrics-grid">
            <?php if (hasPermission('view_all') || $isSuperAdmin): ?>
            <div class="metric-card">
                <div class="metric-label">Total Users</div>
                <div class="metric-value"><?php echo number_format($metrics['total_users']); ?></div>
            </div>
            <?php endif; ?>
            <div class="metric-card">
                <div class="metric-label">Total Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['total_swaps']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Active Holds</div>
                <div class="metric-value"><?php echo number_format($metrics['total_holds']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Cashouts</div>
                <div class="metric-value"><?php echo number_format($metrics['total_cashouts']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Invoices</div>
                <div class="metric-value"><?php echo number_format($metrics['total_invoices']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Fees (BWP)</div>
                <div class="metric-value"><?php echo number_format($metrics['total_fees'], 2); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Fee Collections</div>
                <div class="metric-value"><?php echo number_format($metrics['total_fee_collections']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Audit Logs</div>
                <div class="metric-value"><?php echo number_format($metrics['total_audit_logs']); ?></div>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="fee-box">
            <div class="title">💰 Financial Summary</div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-top:8px;">
                <div><strong>Today's Volume:</strong> <?php echo number_format($dailyStats['total_amount'] ?? 0, 2); ?> BWP</div>
                <div><strong>Today's Transactions:</strong> <?php echo number_format($dailyStats['transaction_count'] ?? 0); ?></div>
                <div><strong>Completed Today:</strong> <?php echo number_format($dailyStats['completed_count'] ?? 0); ?></div>
                <div><strong>Total Fees Collected:</strong> <?php echo number_format($metrics['total_fees'] ?? 0, 2); ?> BWP</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- FEE BREAKDOWN VIEW (Finance Manager, Revenue Officer, Super Admin) -->
        <!-- ============================================================ -->
        <?php if ($view === 'fee_breakdown' && hasFinancialAccess()): ?>
        <div class="content-header">
            <h1>📊 FEE BREAKDOWN</h1>
            <div class="timestamp">Detailed fee analysis by type</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">💰 Fee Breakdown by Type</span>
                <span class="card-badge"><?php echo count($feeBreakdown); ?> TYPES</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=fee_invoices&export_id=all" class="btn btn-finance">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fee Type</th>
                            <th>Count</th>
                            <th>Total Fee</th>
                            <th>VAT</th>
                            <th>Total with VAT</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($feeBreakdown)): ?>
                        <tr><td colspan="6" class="empty-state">No fee records found</td></tr>
                        <?php else: ?>
                        <?php foreach ($feeBreakdown as $fee): ?>
                        <tr>
                            <td><?php echo safeHtml($fee['fee_type']); ?></td>
                            <td><?php echo number_format($fee['count']); ?></td>
                            <td><?php echo number_format($fee['total_fee'], 2); ?> BWP</td>
                            <td><?php echo number_format($fee['total_vat'] ?? 0, 2); ?> BWP</td>
                            <td><strong><?php echo number_format($fee['total_with_vat'], 2); ?> BWP</strong></td>
                            <td>
                                <?php 
                                $status = strtolower($fee['status'] ?? 'pending');
                                $class = $status === 'paid' ? 'success' : ($status === 'sent' ? 'pending' : 'info');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($fee['status'] ?? 'pending'); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- PARTICIPANT FEES VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'participant_fees' && hasFinancialAccess()): ?>
        <div class="content-header">
            <h1>🏛️ PARTICIPANT FEE BREAKDOWN</h1>
            <div class="timestamp">Fees by participant and type</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📊 Participant Fee Collection</span>
                <span class="card-badge"><?php echo count($participantFees); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Participant</th>
                            <th>Fee Type</th>
                            <th>Invoices</th>
                            <th>Total Fee</th>
                            <th>VAT</th>
                            <th>Total Amount</th>
                            <th>Paid</th>
                            <th>Pending</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($participantFees)): ?>
                        <tr><td colspan="8" class="empty-state">No participant fee records found</td></tr>
                        <?php else: ?>
                        <?php foreach ($participantFees as $row): ?>
                        <tr>
                            <td><strong><?php echo safeHtml($row['source_institution']); ?></strong></td>
                            <td><?php echo safeHtml($row['fee_type']); ?></td>
                            <td><?php echo number_format($row['invoice_count']); ?></td>
                            <td><?php echo number_format($row['total_fee'], 2); ?> BWP</td>
                            <td><?php echo number_format($row['total_vat'], 2); ?> BWP</td>
                            <td><strong><?php echo number_format($row['total_amount'], 2); ?> BWP</strong></td>
                            <td><?php echo number_format($row['paid_count'] ?? 0); ?></td>
                            <td><?php echo number_format($row['pending_count'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- REVENUE SPLIT VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'revenue_split' && hasFinancialAccess()): ?>
        <div class="content-header">
            <h1>📈 REVENUE DISTRIBUTION</h1>
            <div class="timestamp">Revenue split by participant</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">💰 Revenue by Participant</span>
                <span class="card-badge"><?php echo count($revenueSplit); ?> PARTICIPANTS</span>
            </div>
            <?php 
            $totalRevenue = array_sum(array_column($revenueSplit, 'total_revenue'));
            ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Participant</th>
                            <th>Transactions</th>
                            <th>Total Fees</th>
                            <th>VAT</th>
                            <th>Total Revenue</th>
                            <th>Percentage</th>
                            <th>Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($revenueSplit)): ?>
                        <tr><td colspan="7" class="empty-state">No revenue records found</td></tr>
                        <?php else: ?>
                        <?php foreach ($revenueSplit as $row): 
                        $percentage = $totalRevenue > 0 ? ($row['total_revenue'] / $totalRevenue) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo safeHtml($row['source_institution']); ?></strong></td>
                            <td><?php echo number_format($row['transaction_count']); ?></td>
                            <td><?php echo number_format($row['total_fee'], 2); ?> BWP</td>
                            <td><?php echo number_format($row['total_vat'] ?? 0, 2); ?> BWP</td>
                            <td><strong><?php echo number_format($row['total_revenue'], 2); ?> BWP</strong></td>
                            <td><?php echo number_format($percentage, 1); ?>%</td>
                            <td><?php echo number_format($row['paid_count'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalRevenue > 0): ?>
            <div style="padding:12px; background:#f8f9fa; margin-top:12px; border-top:2px solid #001B44;">
                <strong>Total Revenue:</strong> <?php echo number_format($totalRevenue, 2); ?> BWP
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- REGULATORY VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'regulatory' && ($isRegulator || $isSuperAdmin)): ?>
        <div class="content-header">
            <h1>🏛️ REGULATORY OVERSIGHT</h1>
            <div class="timestamp">Bank of Botswana · <?php echo date('Y-m-d H:i:s'); ?></div>
        </div>

        <div class="regulatory-box" style="background:#fdf6f6; border:2px solid #8B0000; padding:16px; margin-bottom:16px; border-left:6px solid #8B0000;">
            <div class="title" style="font-weight:700; color:#8B0000; font-size:0.9rem; margin-bottom:8px;">🏛️ Central Bank Regulatory Dashboard</div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-top:12px;">
                <div><strong>Daily Volume:</strong> <?php echo number_format($dailyStats['total_amount'] ?? 0, 2); ?> BWP</div>
                <div><strong>Total Fees Collected:</strong> <?php echo number_format($metrics['total_fees'] ?? 0, 2); ?> BWP</div>
                <div><strong>Total Invoices:</strong> <?php echo number_format($metrics['total_invoices'] ?? 0); ?></div>
                <div><strong>Fee Collections:</strong> <?php echo number_format($metrics['total_fee_collections'] ?? 0); ?></div>
                <div><strong>Pending Settlements:</strong> <?php echo number_format($tableData['settlement_queue']['count'] ?? 0); ?></div>
                <div><strong>Total Transactions:</strong> <?php echo number_format($metrics['total_swaps'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Regulatory Audit Log -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📝 Regulatory Audit Trail</span>
                <span class="card-badge">Last 50 Actions</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=audit_logs&export_id=all" class="btn btn-regulator">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Performed By</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $auditRows = array_slice($tableData['audit_logs']['rows'] ?? [], 0, 50); ?>
                        <?php if (empty($auditRows)): ?>
                        <tr><td colspan="5" class="empty-state">No audit records found</td></tr>
                        <?php else: ?>
                        <?php foreach ($auditRows as $row): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i', strtotime($row['performed_at'] ?? 'now')); ?></td>
                            <td><?php echo safeHtml($row['action'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['entity_type'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['performed_by_type'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['ip_address'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- TRANSACTIONS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'transactions' && (canView('transactions') || canView('transactions_readonly'))): ?>
        <div class="content-header">
            <h1>📋 TRANSACTIONS</h1>
            <div class="timestamp">All swap transactions</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">All Transactions</span>
                <span class="card-badge"><?php echo count($tableData['swap_requests']['rows'] ?? []); ?> RECORDS</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=swap_requests&export_id=all" class="btn btn-primary">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <?php if (hasPermission('review_transactions')): ?>
                            <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $txRows = $tableData['swap_requests']['rows'] ?? []; ?>
                        <?php if (empty($txRows)): ?>
                        <tr><td colspan="5" class="empty-state">No transactions found</td></tr>
                        <?php else: ?>
                        <?php foreach ($txRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['swap_uuid'] ?? $row['swap_id'] ?? 'N/A', 0, 12)); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($row['created_at'] ?? 'now')); ?></td>
                            <?php if (hasPermission('review_transactions') && !$isReadOnly): ?>
                            <td>
                                <a href="?view=transactions&id=<?php echo $row['swap_id'] ?? $row['swap_uuid'] ?? ''; ?>" style="font-size:0.6rem; color:#001B44;">View</a>
                                <?php if (hasPermission('approve')): ?>
                                <button class="btn" style="font-size:0.55rem; padding:2px 8px;">Approve</button>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- INVOICES VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'invoices' && hasPermission('generate_invoice')): ?>
        <div class="content-header">
            <h1>💰 INVOICE MANAGEMENT</h1>
            <div class="timestamp">Fee invoices and billing</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">💰 Daily Invoice Generation</span>
                <span class="card-badge">End of Day</span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; padding:8px 0;">
                <div><strong>Today's Volume:</strong> <?php echo number_format($dailyStats['total_amount'] ?? 0, 2); ?> BWP</div>
                <div><strong>Fee Rate:</strong> 1.5%</div>
                <div><strong>Estimated Fee:</strong> <?php echo number_format(($dailyStats['total_amount'] ?? 0) * 0.015, 2); ?> BWP</div>
            </div>
            <div style="margin-top:12px; display:flex; gap:12px; flex-wrap:wrap;">
                <a href="?action=generate_invoice" class="btn btn-success" onclick="return confirm('Generate end-of-day invoice?')">📄 Generate Daily Invoice</a>
                <a href="?view=invoices&export=fee_invoices&format=csv" class="btn btn-primary">📊 Export Invoices</a>
            </div>
            <?php if (!empty($success)): ?>
            <div style="margin-top:12px; padding:12px; background:#d4edda; color:#155724; border:2px solid #c3e6cb; border-radius:4px;">
                ✅ <?php echo safeHtml($success); ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Recent Invoices</span>
                <span class="card-badge"><?php echo count($tableData['fee_invoices']['rows'] ?? []); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $invRows = array_slice($tableData['fee_invoices']['rows'] ?? [], 0, 20); ?>
                        <?php if (empty($invRows)): ?>
                        <tr><td colspan="5" class="empty-state">No invoices found</td></tr>
                        <?php else: ?>
                        <?php foreach ($invRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['invoice_uuid'] ?? 'N/A', 0, 12)); ?></td>
                            <td><?php echo safeHtml($row['fee_type'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['total_amount'] ?? 0), 2); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = $status === 'paid' ? 'success' : ($status === 'sent' ? 'pending' : 'info');
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span>
                            </td>
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
        <!-- AUDIT VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'audit' && canView('audit')): ?>
        <div class="content-header">
            <h1>📝 AUDIT LOGS</h1>
            <div class="timestamp">System audit trail</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">All Audit Records</span>
                <span class="card-badge"><?php echo count($tableData['audit_logs']['rows'] ?? []); ?> RECORDS</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=audit_logs&export_id=all" class="btn btn-primary">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>User</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $auditRows = $tableData['audit_logs']['rows'] ?? []; ?>
                        <?php if (empty($auditRows)): ?>
                        <tr><td colspan="5" class="empty-state">No audit records found</td></tr>
                        <?php else: ?>
                        <?php foreach (array_slice($auditRows, 0, 50) as $row): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i', strtotime($row['performed_at'] ?? 'now')); ?></td>
                            <td><?php echo safeHtml($row['action'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['entity_type'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['performed_by_id'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['ip_address'] ?? 'N/A'); ?></td>
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
        <?php if (!canView($view) && $view !== 'dashboard'): ?>
        <div class="card">
            <div class="empty-state">
                <div class="icon">🚫</div>
                <h2>Access Denied</h2>
                <p>You do not have permission to view this page.</p>
                <p style="font-size:0.8rem; color:#666; margin-top:8px;">Contact your administrator for access.</p>
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
