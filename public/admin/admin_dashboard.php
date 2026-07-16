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
            'generate_reports', 'role_reports'
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
            'regulatory_reports', 'generate_reports'
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
// REPORT GENERATION ENGINE
// ============================================================
function generateReport($type, $dateFrom, $dateTo, $format = 'html') {
    global $db;
    
    $reportData = [];
    $reportTitle = '';
    $columns = [];
    
    switch ($type) {
        case 'financial':
            $reportTitle = 'Financial Report';
            $stmt = $db->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(amount), 0) as total_amount,
                    COALESCE(SUM(fee_amount), 0) as total_fees,
                    COUNT(CASE WHEN status ILIKE '%completed%' THEN 1 END) as completed_count,
                    COUNT(CASE WHEN status ILIKE '%failed%' THEN 1 END) as failed_count
                FROM vw_all_swaps
                WHERE created_at BETWEEN :date_from AND :date_to
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Date', 'Transactions', 'Total Amount', 'Total Fees', 'Completed', 'Failed'];
            break;
            
        case 'regulatory':
            $reportTitle = 'Regulatory Settlement Report';
            $stmt = $db->prepare("
                SELECT 
                    swap_reference,
                    source_institution,
                    destination_institution,
                    amount,
                    currency,
                    fee_amount,
                    status,
                    created_at,
                    CASE 
                        WHEN source_institution != destination_institution THEN 'CROSS_BORDER'
                        ELSE 'DOMESTIC'
                    END as transaction_type
                FROM vw_all_swaps
                WHERE created_at BETWEEN :date_from AND :date_to
                ORDER BY created_at DESC
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Reference', 'Source', 'Destination', 'Amount', 'Currency', 'Fee', 'Status', 'Type', 'Date'];
            break;
            
        case 'settlement':
            $reportTitle = 'Settlement Report';
            $stmt = $db->prepare("
                SELECT 
                    reference,
                    debtor,
                    creditor,
                    amount,
                    currency,
                    status,
                    created_at,
                    updated_at
                FROM settlement_queue
                WHERE created_at BETWEEN :date_from AND :date_to
                ORDER BY created_at DESC
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Reference', 'Debtor', 'Creditor', 'Amount', 'Currency', 'Status', 'Created', 'Updated'];
            break;
            
        case 'revenue':
            $reportTitle = 'Revenue Report';
            $stmt = $db->prepare("
                SELECT 
                    source_institution,
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(amount), 0) as total_volume,
                    COALESCE(SUM(fee_amount), 0) as total_fees,
                    COALESCE(AVG(fee_amount), 0) as avg_fee,
                    MAX(created_at) as last_transaction
                FROM vw_all_swaps
                WHERE created_at BETWEEN :date_from AND :date_to
                GROUP BY source_institution
                ORDER BY total_fees DESC
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Institution', 'Transactions', 'Total Volume', 'Total Fees', 'Avg Fee', 'Last Activity'];
            break;
            
        case 'compliance':
            $reportTitle = 'Compliance Report';
            $stmt = $db->prepare("
                SELECT 
                    reference,
                    source_institution,
                    amount,
                    currency,
                    status,
                    created_at,
                    CASE 
                        WHEN amount > 10000 THEN 'HIGH'
                        WHEN amount > 5000 THEN 'MEDIUM'
                        ELSE 'LOW'
                    END as risk_level
                FROM vw_all_swaps
                WHERE created_at BETWEEN :date_from AND :date_to
                ORDER BY amount DESC
                LIMIT 500
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Reference', 'Source', 'Amount', 'Currency', 'Status', 'Date', 'Risk Level'];
            break;
            
        case 'audit':
            $reportTitle = 'Audit Report';
            $stmt = $db->prepare("
                SELECT 
                    audit_id,
                    action,
                    entity_type,
                    performed_by_type,
                    performed_by_id,
                    details,
                    performed_at,
                    ip_address
                FROM audit_logs
                WHERE performed_at BETWEEN :date_from AND :date_to
                ORDER BY performed_at DESC
                LIMIT 1000
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['ID', 'Action', 'Entity', 'Performed By', 'Details', 'Date', 'IP'];
            break;
            
        case 'net_positions':
            $reportTitle = 'Net Positions Report';
            $stmt = $db->query("
                SELECT debtor, creditor, amount, currency_code, updated_at
                FROM net_positions
                WHERE amount > 0.01
                ORDER BY amount DESC
            ");
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Debtor', 'Creditor', 'Amount', 'Currency', 'Updated'];
            break;
            
        case 'cross_border':
            $reportTitle = 'Cross-Border Report';
            $stmt = $db->prepare("
                SELECT 
                    swap_reference,
                    source_institution,
                    destination_institution,
                    amount,
                    currency,
                    exchange_rate,
                    corridor_fee,
                    status,
                    created_at
                FROM cross_border_messages
                WHERE created_at BETWEEN :date_from AND :date_to
                ORDER BY created_at DESC
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Reference', 'Source', 'Destination', 'Amount', 'Currency', 'Rate', 'Corridor Fee', 'Status', 'Date'];
            break;
            
        case 'forex':
            $reportTitle = 'Forex Report';
            $stmt = $db->prepare("
                SELECT 
                    base_currency,
                    quote_currency,
                    bid_rate,
                    ask_rate,
                    mid_rate,
                    source,
                    created_at
                FROM fx_rates
                WHERE created_at BETWEEN :date_from AND :date_to
                ORDER BY created_at DESC
                LIMIT 500
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Base', 'Quote', 'Bid', 'Ask', 'Mid', 'Source', 'Date'];
            break;
            
        case 'invoice':
            $reportTitle = 'Invoice Report';
            $stmt = $db->prepare("
                SELECT 
                    message_uuid as invoice_uuid,
                    message_payload->>'fee_type' as fee_type,
                    (message_payload->>'total_amount')::numeric as total_amount,
                    (message_payload->>'fee_amount')::numeric as fee_amount,
                    (message_payload->>'vat_amount')::numeric as vat_amount,
                    status,
                    created_at
                FROM settlement_outbox
                WHERE message_type = 'FEE_INVOICE'
                AND created_at BETWEEN :date_from AND :date_to
                ORDER BY created_at DESC
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Invoice', 'Fee Type', 'Fee Amount', 'VAT', 'Total', 'Status', 'Date'];
            break;
            
        case 'fee':
        case 'participant':
            $reportTitle = 'Participant Fee Report';
            $stmt = $db->prepare("
                SELECT 
                    source_institution,
                    message_payload->>'fee_type' as fee_type,
                    COUNT(*) as invoice_count,
                    SUM((message_payload->>'fee_amount')::numeric) as total_fee,
                    SUM((message_payload->>'vat_amount')::numeric) as total_vat,
                    SUM((message_payload->>'total_amount')::numeric) as total_amount,
                    COUNT(CASE WHEN status = 'ACKNOWLEDGED' THEN 1 END) as paid_count
                FROM settlement_outbox
                WHERE message_type = 'FEE_INVOICE'
                AND created_at BETWEEN :date_from AND :date_to
                GROUP BY source_institution, message_payload->>'fee_type'
                ORDER BY total_amount DESC
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Institution', 'Fee Type', 'Invoices', 'Total Fee', 'VAT', 'Total Amount', 'Paid'];
            break;
            
        case 'transaction':
            $reportTitle = 'Transaction Report';
            $stmt = $db->prepare("
                SELECT 
                    swap_reference,
                    source_institution,
                    destination_institution,
                    amount,
                    currency,
                    fee_amount,
                    status,
                    created_at
                FROM vw_all_swaps
                WHERE created_at BETWEEN :date_from AND :date_to
                ORDER BY created_at DESC
                LIMIT 1000
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Reference', 'Source', 'Destination', 'Amount', 'Currency', 'Fee', 'Status', 'Date'];
            break;
            
        case 'aml':
            $reportTitle = 'AML Monitoring Report';
            $stmt = $db->prepare("
                SELECT 
                    performed_at,
                    result,
                    score,
                    flagged_reasons,
                    entity_id,
                    entity_type
                FROM aml_checks
                WHERE performed_at BETWEEN :date_from AND :date_to
                AND result = 'FLAGGED'
                ORDER BY score DESC
                LIMIT 200
            ");
            $stmt->execute([':date_from' => $dateFrom . ' 00:00:00', ':date_to' => $dateTo . ' 23:59:59']);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = ['Date', 'Result', 'Score', 'Reasons', 'Entity ID', 'Entity Type'];
            break;
            
        default:
            return ['error' => 'Unsupported report type: ' . $type];
    }
    
    return [
        'title' => $reportTitle,
        'data' => $reportData,
        'columns' => $columns,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'generated_at' => date('Y-m-d H:i:s'),
        'count' => count($reportData)
    ];
}

// ============================================================
// LIVE TRANSACTIONS DATA
// ============================================================
$liveTransactions = [];
$liveStats = [];

try {
    $possibleTables = ['vw_all_swaps', 'swap_requests', 'swap_transactions'];
    $liveTable = null;
    
    foreach ($possibleTables as $table) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table");
            $stmt->execute([':table' => $table]);
            if ((int)$stmt->fetchColumn() > 0) {
                $liveTable = $table;
                break;
            }
        } catch (Throwable $e) {}
    }
    
    if ($liveTable) {
        $query = "SELECT * FROM {$liveTable} ORDER BY created_at DESC LIMIT 50";
        $stmt = $db->query($query);
        $liveTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $statStmt = $db->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status ILIKE '%completed%' OR status ILIKE '%success%' THEN 1 END) as completed,
                COUNT(CASE WHEN status ILIKE '%pending%' OR status ILIKE '%processing%' THEN 1 END) as pending,
                COUNT(CASE WHEN status ILIKE '%failed%' OR status ILIKE '%error%' THEN 1 END) as failed,
                COALESCE(SUM(amount), 0) as total_amount
            FROM {$liveTable}
            WHERE created_at >= NOW() - INTERVAL '24 hours'
        ");
        $liveStats = $statStmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Live transactions error: " . $e->getMessage());
}

// ============================================================
// FETCH TABLE DATA
// ============================================================
$tableData = [];
$tablesToFetch = [
    'swap_requests' => ['label' => '📋 Swap Requests', 'order' => 'created_at DESC', 'limit' => 100],
    'swap_transactions' => ['label' => '🔄 Swap Transactions', 'order' => 'created_at DESC', 'limit' => 100],
    'settlement_queue' => ['label' => '📤 Settlement Queue', 'order' => 'created_at DESC', 'limit' => 100],
    'audit_logs' => ['label' => '📝 Audit Logs', 'order' => 'performed_at DESC', 'limit' => 100],
];

foreach ($tablesToFetch as $table => $config) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table");
        $stmt->execute([':table' => $table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        
        if ($exists) {
            $orderBy = $config['order'] ?? 'created_at DESC';
            $limit = $config['limit'] ?? 100;
            $dataStmt = $db->query("SELECT * FROM {$table} ORDER BY {$orderBy} LIMIT {$limit}");
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
            $colStmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '{$table}' ORDER BY ordinal_position");
            $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $tableData[$table] = [
                'exists' => true,
                'rows' => $rows,
                'count' => count($rows),
                'label' => $config['label'],
                'columns' => $columns
            ];
        } else {
            $tableData[$table] = ['exists' => false, 'rows' => [], 'count' => 0, 'label' => $config['label'], 'columns' => []];
        }
    } catch (Throwable $e) {
        $tableData[$table] = ['exists' => false, 'rows' => [], 'count' => 0, 'label' => $config['label'], 'columns' => []];
    }
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
} catch (Throwable $e) {
    $metrics = array_fill_keys(['total_users', 'total_swaps', 'pending_settlements', 'total_fees', 'recent_swaps_24h'], 0);
}

// ============================================================
// METRICS FOR ROLE-SPECIFIC DASHBOARD
// ============================================================
$roleMetrics = [];
if ($isRegulator || $isSuperAdmin) {
    $roleMetrics['regulatory_volume'] = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM vw_all_swaps WHERE created_at >= NOW() - INTERVAL '7 days'")->fetchColumn();
    $roleMetrics['cross_border_count'] = (int)$db->query("SELECT COUNT(*) FROM cross_border_messages WHERE created_at >= NOW() - INTERVAL '7 days'")->fetchColumn();
}
if ($isFinanceManager || $isSuperAdmin) {
    $roleMetrics['outstanding_invoices'] = (int)$db->query("SELECT COUNT(*) FROM settlement_outbox WHERE message_type = 'FEE_INVOICE' AND status != 'ACKNOWLEDGED'")->fetchColumn();
    $roleMetrics['total_revenue'] = (float)$db->query("SELECT COALESCE(SUM((message_payload->>'total_amount')::numeric), 0) FROM settlement_outbox WHERE message_type = 'FEE_INVOICE'")->fetchColumn();
}
if ($isSettlementOfficer || $isSuperAdmin) {
    $roleMetrics['pending_settlements'] = (int)$db->query("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'")->fetchColumn();
    $roleMetrics['settlement_volume'] = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM settlement_queue WHERE status = 'PENDING'")->fetchColumn();
}
if ($isCompliance || $isComplianceAuditor || $isSuperAdmin) {
    $roleMetrics['flagged_transactions'] = (int)$db->query("SELECT COUNT(*) FROM aml_checks WHERE result = 'FLAGGED' AND performed_at >= NOW() - INTERVAL '7 days'")->fetchColumn();
    $roleMetrics['pending_reviews'] = (int)$db->query("SELECT COUNT(*) FROM swap_requests WHERE status = 'PENDING_REVIEW'")->fetchColumn();
}

// ============================================================
// HANDLE REPORT GENERATION REQUEST
// ============================================================
$generatedReport = null;
$reportError = null;

if ($action === 'generate_report' && !empty($reportType) && canGenerateReport($reportType)) {
    try {
        $generatedReport = generateReport($reportType, $dateFrom, $dateTo, $format);
        if (isset($generatedReport['error'])) {
            $reportError = $generatedReport['error'];
            $generatedReport = null;
        }
    } catch (Throwable $e) {
        $reportError = "Failed to generate report: " . $e->getMessage();
    }
}

// ============================================================
// HANDLE INVOICE GENERATION
// ============================================================
if ($action === 'generate_invoice' && hasPermission('generate_invoice')) {
    try {
        require_once PROJECT_ROOT . '/src/Domain/Services/Settlement/HybridSettlementStrategy.php';
        $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) as total_amount FROM vw_all_swaps WHERE created_at >= CURRENT_DATE");
        $dailyStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalAmount = (float)($dailyStats['total_amount'] ?? 0);
        $feeAmount = $totalAmount * 0.015;
        
        $settlement = new \Domain\Services\Settlement\HybridSettlementStrategy($db);
        $invoiceUuid = $settlement->invoiceFee(
            'DAILY_SETTLEMENT_' . date('Ymd'),
            'VOUCHMORPH_SYSTEM',
            0,
            'DAILY_SETTLEMENT_FEE',
            $feeAmount,
            'BWP'
        );
        $success = "Invoice {$invoiceUuid} generated successfully for " . date('Y-m-d');
    } catch (Throwable $e) {
        $error = "Failed to generate invoice: " . $e->getMessage();
    }
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
        
        .report-filter {
            background: #f8f9fa;
            padding: 16px;
            border: 2px solid #001B44;
            margin-bottom: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .report-filter label {
            font-size: 0.6rem;
            text-transform: uppercase;
            color: #666;
            display: block;
            margin-bottom: 4px;
        }
        .report-filter select, .report-filter input {
            padding: 6px 10px;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.7rem;
            background: #fff;
        }
        .report-filter .btn { margin-left: auto; }
        
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
        
        .live-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #28a745;
            animation: pulse 1.5s ease-in-out infinite;
            margin-right: 8px;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        .auto-refresh-toggle {
            cursor: pointer;
            padding: 4px 12px;
            border-radius: 4px;
            border: 2px solid #001B44;
            font-size: 0.65rem;
            font-weight: 600;
            background: #fff;
            color: #001B44;
            transition: all 0.2s;
        }
        .auto-refresh-toggle.active {
            background: #28a745;
            color: #fff;
            border-color: #28a745;
        }
        
        .role-specific-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        
        @media (max-width: 768px) {
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .admin-nav { padding: 0 12px; gap: 10px; }
            .admin-content { padding: 12px; }
            .admin-header { padding: 12px; }
            th, td { font-size: 0.55rem; padding: 4px 6px; }
            .report-filter { flex-direction: column; align-items: stretch; }
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
        
        <?php if (canView('live_transactions')): ?>
        <a href="?view=live_transactions" class="nav-item <?php echo $view === 'live_transactions' ? 'active' : ''; ?>">🔴 LIVE TXNS</a>
        <?php endif; ?>
        
        <?php if (canView('generate_reports')): ?>
        <a href="?view=generate_reports" class="nav-item <?php echo $view === 'generate_reports' ? 'active' : ''; ?>">📄 REPORTS</a>
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
        <!-- DASHBOARD VIEW - ROLE SPECIFIC -->
        <!-- ============================================================ -->
        <?php if ($view === 'dashboard'): ?>
        <div class="content-header">
            <h1>📊 <?php echo safeHtml($roleName); ?> DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></div>
        </div>

        <!-- Role-specific metrics -->
        <?php if (!empty($roleMetrics)): ?>
        <div class="role-specific-metrics">
            <?php if (isset($roleMetrics['regulatory_volume'])): ?>
            <div class="metric-card" style="border-color: #8B0000;">
                <div class="metric-label">🏛️ 7-Day Regulatory Volume</div>
                <div class="metric-value"><?php echo number_format($roleMetrics['regulatory_volume'], 2); ?> BWP</div>
            </div>
            <?php endif; ?>
            <?php if (isset($roleMetrics['cross_border_count'])): ?>
            <div class="metric-card" style="border-color: #17a2b8;">
                <div class="metric-label">🌍 Cross-Border (7d)</div>
                <div class="metric-value"><?php echo number_format($roleMetrics['cross_border_count']); ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($roleMetrics['outstanding_invoices'])): ?>
            <div class="metric-card" style="border-color: #28a745;">
                <div class="metric-label">💰 Outstanding Invoices</div>
                <div class="metric-value"><?php echo number_format($roleMetrics['outstanding_invoices']); ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($roleMetrics['total_revenue'])): ?>
            <div class="metric-card" style="border-color: #ffc107;">
                <div class="metric-label">📈 Total Revenue</div>
                <div class="metric-value"><?php echo number_format($roleMetrics['total_revenue'], 2); ?> BWP</div>
            </div>
            <?php endif; ?>
            <?php if (isset($roleMetrics['pending_settlements'])): ?>
            <div class="metric-card" style="border-color: #856404;">
                <div class="metric-label">⏳ Pending Settlements</div>
                <div class="metric-value"><?php echo number_format($roleMetrics['pending_settlements']); ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($roleMetrics['settlement_volume'])): ?>
            <div class="metric-card" style="border-color: #17a2b8;">
                <div class="metric-label">💰 Settlement Volume</div>
                <div class="metric-value"><?php echo number_format($roleMetrics['settlement_volume'], 2); ?> BWP</div>
            </div>
            <?php endif; ?>
            <?php if (isset($roleMetrics['flagged_transactions'])): ?>
            <div class="metric-card" style="border-color: #dc3545;">
                <div class="metric-label">🚨 Flagged Transactions</div>
                <div class="metric-value" style="color:#dc3545;"><?php echo number_format($roleMetrics['flagged_transactions']); ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($roleMetrics['pending_reviews'])): ?>
            <div class="metric-card" style="border-color: #6f42c1;">
                <div class="metric-label">📋 Pending Reviews</div>
                <div class="metric-value"><?php echo number_format($roleMetrics['pending_reviews']); ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- General Metrics -->
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
        </div>

        <!-- Quick action links -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">⚡ Quick Actions</span>
            </div>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <?php if (canView('live_transactions')): ?>
                <a href="?view=live_transactions" class="btn btn-primary">🔴 View Live Transactions</a>
                <?php endif; ?>
                <?php if (canView('generate_reports')): ?>
                <a href="?view=generate_reports" class="btn btn-success">📄 Generate Reports</a>
                <?php endif; ?>
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
            <?php if (!empty($error)): ?>
            <div style="margin-top:12px; padding:12px; background:#f8d7da; color:#721c24; border:2px solid #f5c6cb; border-radius:4px;">
                ❌ <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- LIVE TRANSACTIONS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'live_transactions' && canView('live_transactions')): ?>
        <div class="content-header">
            <h1><span class="live-indicator"></span> 🔴 LIVE TRANSACTIONS</h1>
            <div class="timestamp">
                <?php echo date('Y-m-d H:i:s'); ?>
                <span style="margin-left:16px; font-size:0.65rem; color:#666;">
                    <?php echo count($liveTransactions); ?> transactions
                </span>
                <button class="auto-refresh-toggle active" onclick="toggleAutoRefresh()" id="refreshToggle">🔄 AUTO-REFRESH ON</button>
            </div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));">
            <div class="metric-card"><div class="metric-label">Total (24h)</div><div class="metric-value"><?php echo number_format($liveStats['total'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color:#28a745;"><div class="metric-label">✅ Completed</div><div class="metric-value" style="color:#28a745;"><?php echo number_format($liveStats['completed'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color:#856404;"><div class="metric-label">⏳ Pending</div><div class="metric-value" style="color:#856404;"><?php echo number_format($liveStats['pending'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color:#dc3545;"><div class="metric-label">❌ Failed</div><div class="metric-value" style="color:#dc3545;"><?php echo number_format($liveStats['failed'] ?? 0); ?></div></div>
            <div class="metric-card" style="border-color:#17a2b8;"><div class="metric-label">💰 Volume</div><div class="metric-value"><?php echo number_format($liveStats['total_amount'] ?? 0, 2); ?></div></div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Live Transaction Feed</span>
                <span class="card-badge" id="liveCount"><?php echo count($liveTransactions); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>#</th><th>Reference</th><th>Amount</th><th>Currency</th><th>Type</th><th>Status</th><th>Source</th><th>Destination</th><th>Fee</th><th>Created</th></tr>
                    </thead>
                    <tbody id="liveTransactionsBody">
                        <?php if (empty($liveTransactions)): ?>
                        <tr><td colspan="10" class="empty-state">No live transactions found</td></tr>
                        <?php else: ?>
                        <?php foreach ($liveTransactions as $index => $row): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo safeHtml(substr($row['reference'] ?? $row['swap_reference'] ?? 'N/A', 0, 12)); ?></td>
                            <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                            <td><?php echo safeHtml($row['currency'] ?? 'BWP'); ?></td>
                            <td><span class="status status-info"><?php echo safeHtml($row['swap_type'] ?? $row['type'] ?? 'SWAP'); ?></span></td>
                            <td>
                                <?php 
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = match(true) {
                                    str_contains($status, 'complet'), str_contains($status, 'success'), str_contains($status, 'debited') => 'success',
                                    str_contains($status, 'pending'), str_contains($status, 'sent'), str_contains($status, 'processing') => 'pending',
                                    str_contains($status, 'fail'), str_contains($status, 'error'), str_contains($status, 'expired') => 'failed',
                                    default => 'info'
                                };
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo safeHtml($row['source_institution'] ?? $row['source'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['destination_institution'] ?? $row['destination'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['fee_amount'] ?? $row['fee'] ?? 0), 2); ?></td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($row['created_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            let autoRefresh = true;
            let refreshInterval = null;
            
            function toggleAutoRefresh() {
                autoRefresh = !autoRefresh;
                const toggle = document.getElementById('refreshToggle');
                toggle.textContent = autoRefresh ? '🔄 AUTO-REFRESH ON' : '🔄 AUTO-REFRESH OFF';
                if (autoRefresh) { startAutoRefresh(); } 
                else { clearInterval(refreshInterval); }
            }
            
            function startAutoRefresh() {
                clearInterval(refreshInterval);
                refreshInterval = setInterval(function() {
                    fetch(window.location.href + '&ajax=1')
                        .then(response => response.json())
                        .then(data => {
                            if (data.transactions) {
                                const tbody = document.getElementById('liveTransactionsBody');
                                let html = '';
                                data.transactions.forEach((row, i) => {
                                    const status = (row.status || 'pending').toLowerCase();
                                    let cls = 'info';
                                    if (status.includes('complet') || status.includes('success')) cls = 'success';
                                    else if (status.includes('pending') || status.includes('processing')) cls = 'pending';
                                    else if (status.includes('fail') || status.includes('error')) cls = 'failed';
                                    html += `<tr>
                                        <td>${i+1}</td>
                                        <td>${(row.reference || row.swap_reference || 'N/A').substring(0,12)}</td>
                                        <td><strong>${Number(row.amount || 0).toFixed(2)}</strong></td>
                                        <td>${row.currency || 'BWP'}</td>
                                        <td><span class="status status-info">${row.swap_type || row.type || 'SWAP'}</span></td>
                                        <td><span class="status status-${cls}">${row.status || 'pending'}</span></td>
                                        <td>${row.source_institution || row.source || 'N/A'}</td>
                                        <td>${row.destination_institution || row.destination || 'N/A'}</td>
                                        <td>${Number(row.fee_amount || row.fee || 0).toFixed(2)}</td>
                                        <td>${new Date(row.created_at).toLocaleString()}</td>
                                    </tr>`;
                                });
                                tbody.innerHTML = html;
                                document.getElementById('liveCount').textContent = data.transactions.length;
                            }
                        })
                        .catch(e => console.error('Refresh failed:', e));
                }, 5000);
            }
            startAutoRefresh();
        </script>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- GENERATE REPORTS VIEW - ROLE SPECIFIC -->
        <!-- ============================================================ -->
        <?php if ($view === 'generate_reports' && canView('generate_reports')): ?>
        <div class="content-header">
            <h1>📄 GENERATE REPORTS</h1>
            <div class="timestamp">Role: <?php echo safeHtml($roleName); ?></div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <!-- Report Generation Form -->
        <div class="report-filter">
            <div>
                <label>Report Type</label>
                <select name="report_type" id="reportType" onchange="updateReportFields()">
                    <option value="">-- Select Report --</option>
                    <?php 
                    $reportOptions = [
                        'financial' => '💰 Financial Report',
                        'regulatory' => '🏛️ Regulatory Report',
                        'settlement' => '📤 Settlement Report',
                        'revenue' => '📈 Revenue Report',
                        'compliance' => '📋 Compliance Report',
                        'audit' => '📝 Audit Report',
                        'net_positions' => '⚖️ Net Positions Report',
                        'cross_border' => '🌍 Cross-Border Report',
                        'forex' => '💱 Forex Report',
                        'invoice' => '📄 Invoice Report',
                        'fee' => '💲 Fee Report',
                        'transaction' => '🔄 Transaction Report',
                        'aml' => '🛡️ AML Report'
                    ];
                    foreach ($reportOptions as $key => $label):
                        if (canGenerateReport($key) || $isSuperAdmin):
                    ?>
                    <option value="<?php echo $key; ?>" <?php echo $reportType === $key ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endif; endforeach; ?>
                </select>
            </div>
            <div>
                <label>Date From</label>
                <input type="date" name="date_from" id="dateFrom" value="<?php echo $dateFrom; ?>">
            </div>
            <div>
                <label>Date To</label>
                <input type="date" name="date_to" id="dateTo" value="<?php echo $dateTo; ?>">
            </div>
            <div>
                <label>Format</label>
                <select name="format" id="format">
                    <option value="html">📄 HTML</option>
                    <option value="csv">📊 CSV</option>
                    <option value="json">📋 JSON</option>
                </select>
            </div>
            <button class="btn btn-primary" onclick="generateReport()">📄 Generate Report</button>
        </div>

        <!-- Report Results -->
        <?php if ($generatedReport && !isset($generatedReport['error'])): ?>
        <div class="card" style="border-left: 6px solid #28a745;">
            <div class="card-header">
                <span class="card-title">✅ <?php echo safeHtml($generatedReport['title']); ?></span>
                <span class="card-badge"><?php echo $generatedReport['count']; ?> RECORDS</span>
                <span style="font-size:0.6rem; color:#666;">
                    <?php echo $generatedReport['date_from']; ?> → <?php echo $generatedReport['date_to']; ?>
                    · Generated: <?php echo $generatedReport['generated_at']; ?>
                </span>
            </div>
            
            <?php if ($format === 'csv'): ?>
            <div class="table-responsive">
                <pre style="background:#1e293b; color:#4ade80; padding:12px; font-size:0.6rem; overflow-x:auto; max-height:400px; overflow-y:auto;">
<?php 
// CSV output
if (!empty($generatedReport['data'])) {
    echo implode(',', $generatedReport['columns']) . "\n";
    foreach ($generatedReport['data'] as $row) {
        $values = [];
        foreach ($generatedReport['columns'] as $col) {
            $values[] = '"' . str_replace('"', '""', (string)($row[$col] ?? '')) . '"';
        }
        echo implode(',', $values) . "\n";
    }
}
?>
                </pre>
            </div>
            <?php elseif ($format === 'json'): ?>
            <pre style="background:#1e293b; color:#4ade80; padding:12px; font-size:0.6rem; overflow-x:auto; max-height:400px; overflow-y:auto;"><?php echo safeHtml(json_encode($generatedReport['data'], JSON_PRETTY_PRINT)); ?></pre>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($generatedReport['columns'] as $col): ?>
                            <th><?php echo safeHtml($col); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($generatedReport['data'])): ?>
                        <tr><td colspan="<?php echo count($generatedReport['columns']); ?>" class="empty-state">No data found</td></tr>
                        <?php else: ?>
                        <?php foreach ($generatedReport['data'] as $row): ?>
                        <tr>
                            <?php foreach ($generatedReport['columns'] as $col): ?>
                            <td>
                                <?php 
                                $value = $row[$col] ?? '';
                                if (is_null($value)) echo '<span style="color:#999;">NULL</span>';
                                elseif (is_numeric($value) && (strpos($col, 'amount') !== false || strpos($col, 'fee') !== false || strpos($col, 'rate') !== false)) {
                                    echo number_format((float)$value, 2);
                                } elseif (is_string($value) && in_array($col, ['status', 'type', 'action'])) {
                                    $statusClass = match(strtolower($value)) {
                                        'completed', 'success', 'paid', 'active', 'approved', 'settled', 'acknowledged' => 'success',
                                        'pending', 'sent', 'processing', 'reserved' => 'pending',
                                        'failed', 'error', 'expired', 'declined' => 'failed',
                                        default => 'info'
                                    };
                                    echo '<span class="status status-' . $statusClass . '">' . safeHtml($value) . '</span>';
                                } else {
                                    echo safeHtml(substr((string)$value, 0, 100));
                                }
                                ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div style="margin-top:12px; display:flex; gap:12px; flex-wrap:wrap;">
                <button class="btn btn-success" onclick="exportReport('csv')">📊 Export CSV</button>
                <button class="btn btn-primary" onclick="exportReport('json')">📋 Export JSON</button>
                <button class="btn" onclick="window.print()">🖨️ Print</button>
            </div>
        </div>
        <?php elseif ($reportError): ?>
        <div class="card" style="border-left: 6px solid #dc3545;">
            <div class="card-header">
                <span class="card-title">❌ Error</span>
            </div>
            <div style="padding:16px; color:#dc3545;">
                <?php echo safeHtml($reportError); ?>
            </div>
        </div>
        <?php endif; ?>

        <script>
            function generateReport() {
                const type = document.getElementById('reportType').value;
                const dateFrom = document.getElementById('dateFrom').value;
                const dateTo = document.getElementById('dateTo').value;
                const format = document.getElementById('format').value;
                
                if (!type) {
                    alert('Please select a report type');
                    return;
                }
                
                window.location.href = '?view=generate_reports&action=generate_report&report_type=' + type + 
                    '&date_from=' + dateFrom + '&date_to=' + dateTo + '&format=' + format;
            }
            
            function exportReport(format) {
                const type = document.getElementById('reportType').value;
                const dateFrom = document.getElementById('dateFrom').value;
                const dateTo = document.getElementById('dateTo').value;
                window.location.href = '?view=generate_reports&action=generate_report&report_type=' + type + 
                    '&date_from=' + dateFrom + '&date_to=' + dateTo + '&format=' + format;
            }
            
            function updateReportFields() {
                const type = document.getElementById('reportType').value;
                // Show/hide date fields based on report type
                const dateFields = document.querySelectorAll('.report-filter input[type="date"]');
                const hideFor = ['net_positions'];
                if (hideFor.includes(type)) {
                    dateFields.forEach(f => f.closest('div').style.opacity = '0.5');
                } else {
                    dateFields.forEach(f => f.closest('div').style.opacity = '1');
                }
            }
        </script>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- REGULATORY VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'regulatory' && ($isRegulator || $isSuperAdmin)): ?>
        <div class="content-header">
            <h1>🏛️ REGULATORY OVERSIGHT</h1>
            <div class="timestamp">Bank of Botswana · <?php echo date('Y-m-d H:i:s'); ?></div>
        </div>

        <div class="card" style="border-left:6px solid #8B0000;">
            <div class="card-header">
                <span class="card-title">📊 Regulatory Summary</span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
                <div><strong>Total Volume (7d):</strong> <?php echo number_format($roleMetrics['regulatory_volume'] ?? 0, 2); ?> BWP</div>
                <div><strong>Cross-Border (7d):</strong> <?php echo number_format($roleMetrics['cross_border_count'] ?? 0); ?></div>
                <div><strong>Total Swaps:</strong> <?php echo number_format($metrics['total_swaps'] ?? 0); ?></div>
                <div><strong>Pending Settlements:</strong> <?php echo number_format($metrics['pending_settlements'] ?? 0); ?></div>
                <div><strong>Total Fees:</strong> <?php echo number_format($metrics['total_fees'] ?? 0, 2); ?> BWP</div>
            </div>
            <div style="margin-top:16px;">
                <a href="?view=generate_reports&report_type=regulatory" class="btn btn-regulator">📄 Generate Regulatory Report</a>
                <a href="?view=generate_reports&report_type=net_positions" class="btn btn-regulator">⚖️ View Net Positions</a>
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
                        <tr><th>ID</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php $txRows = $tableData['swap_requests']['rows'] ?? []; ?>
                        <?php if (empty($txRows)): ?>
                        <tr><td colspan="4" class="empty-state">No transactions found</td></tr>
                        <?php else: ?>
                        <?php foreach ($txRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['swap_uuid'] ?? $row['swap_id'] ?? 'N/A', 0, 12)); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = match($status) {
                                    'completed', 'success' => 'success',
                                    'pending', 'processing' => 'pending',
                                    'failed', 'error' => 'failed',
                                    default => 'info'
                                };
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
        <!-- SETTLEMENTS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'settlements' && canView('settlements')): ?>
        <div class="content-header">
            <h1>📤 SETTLEMENTS</h1>
            <div class="timestamp">Settlement queue</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Settlement Queue</span>
                <span class="card-badge"><?php echo count($tableData['settlement_queue']['rows'] ?? []); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Debtor</th><th>Creditor</th><th>Amount</th><th>Status</th><th>Created</th></tr>
                    </thead>
                    <tbody>
                        <?php $sqRows = $tableData['settlement_queue']['rows'] ?? []; ?>
                        <?php if (empty($sqRows)): ?>
                        <tr><td colspan="5" class="empty-state">No settlements found</td></tr>
                        <?php else: ?>
                        <?php foreach ($sqRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml($row['debtor'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['creditor'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = match($status) {
                                    'completed', 'settled' => 'success',
                                    'pending', 'processing' => 'pending',
                                    'failed', 'error' => 'failed',
                                    default => 'info'
                                };
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
                <span class="card-title">Audit Records</span>
                <span class="card-badge"><?php echo count($tableData['audit_logs']['rows'] ?? []); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Time</th><th>Action</th><th>Entity</th><th>User</th><th>IP</th></tr>
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
        <!-- FEE BREAKDOWN VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'fee_breakdown' && hasFinancialAccess()): ?>
        <div class="content-header">
            <h1>📊 FEE BREAKDOWN</h1>
            <div class="timestamp">Fee analysis by type</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">💰 Fee Breakdown</span>
                <span class="card-badge">Fee Analysis</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Fee Type</th><th>Count</th><th>Total Fee</th><th>VAT</th><th>Total with VAT</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $feeBreakdown = [];
                        try {
                            $stmt = $db->query("
                                SELECT 
                                    message_payload->>'fee_type' as fee_type,
                                    COUNT(*) as count,
                                    SUM((message_payload->>'fee_amount')::numeric) as total_fee,
                                    SUM((message_payload->>'vat_amount')::numeric) as total_vat,
                                    SUM((message_payload->>'total_amount')::numeric) as total_with_vat,
                                    status
                                FROM settlement_outbox
                                WHERE message_type = 'FEE_INVOICE'
                                GROUP BY message_payload->>'fee_type', status
                                ORDER BY total_fee DESC
                            ");
                            $feeBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Throwable $e) {
                            error_log("[ADMIN DASHBOARD] Fee breakdown error: " . $e->getMessage());
                        }
                        ?>
                        <?php if (empty($feeBreakdown)): ?>
                        <tr><td colspan="6" class="empty-state">No fee records found</td></tr>
                        <?php else: ?>
                        <?php foreach ($feeBreakdown as $fee): ?>
                        <tr>
                            <td><?php echo safeHtml($fee['fee_type'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($fee['count'] ?? 0); ?></td>
                            <td><?php echo number_format((float)($fee['total_fee'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($fee['total_vat'] ?? 0), 2); ?></td>
                            <td><strong><?php echo number_format((float)($fee['total_with_vat'] ?? 0), 2); ?></strong></td>
                            <td>
                                <?php 
                                $status = strtolower($fee['status'] ?? 'pending');
                                $class = match($status) {
                                    'acknowledged', 'completed', 'paid' => 'success',
                                    'sent' => 'pending',
                                    'failed' => 'failed',
                                    default => 'info'
                                };
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
        <!-- INVOICES VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'invoices' && hasPermission('generate_invoice')): ?>
        <div class="content-header">
            <h1>💰 INVOICE MANAGEMENT</h1>
            <div class="timestamp">Fee invoices</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📄 Generate Invoice</span>
            </div>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <a href="?action=generate_invoice" class="btn btn-success" onclick="return confirm('Generate daily invoice?')">📄 Generate Daily Invoice</a>
            </div>
            <?php if (!empty($success)): ?>
            <div style="margin-top:12px; padding:12px; background:#d4edda; color:#155724; border:2px solid #c3e6cb; border-radius:4px;">
                ✅ <?php echo safeHtml($success); ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
            <div style="margin-top:12px; padding:12px; background:#f8d7da; color:#721c24; border:2px solid #f5c6cb; border-radius:4px;">
                ❌ <?php echo safeHtml($error); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- ALL TABLES VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'all_tables' && $isSuperAdmin): ?>
        <div class="content-header">
            <h1>📋 ALL DATABASE TABLES</h1>
            <div class="timestamp">Complete database view · <?php echo date('Y-m-d H:i:s'); ?></div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <?php foreach ($tableData as $tableName => $data): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?php echo $data['label'] ?? $tableName; ?></span>
                <span class="card-badge"><?php echo $data['count']; ?> RECORDS</span>
            </div>
            <?php if (!$data['exists']): ?>
            <div class="empty-state">Table <code><?php echo $tableName; ?></code> does not exist</div>
            <?php elseif (empty($data['rows'])): ?>
            <div class="empty-state">No records found</div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($data['columns'] as $col): ?>
                            <th><?php echo safeHtml($col); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($data['columns'] as $col): ?>
                            <td>
                                <?php 
                                $value = $row[$col] ?? '';
                                if (is_null($value)) echo '<span style="color:#999;">NULL</span>';
                                elseif (is_string($value) && strlen($value) > 100) echo safeHtml(substr($value, 0, 100)) . '...';
                                elseif (is_numeric($value) && strpos($col, 'amount') !== false) echo number_format((float)$value, 2);
                                elseif (is_string($value) && in_array($col, ['status', 'type'])) {
                                    $statusClass = match(strtolower($value)) {
                                        'completed', 'success', 'paid', 'active' => 'success',
                                        'pending', 'sent', 'processing' => 'pending',
                                        'failed', 'error' => 'failed',
                                        default => 'info'
                                    };
                                    echo '<span class="status status-' . $statusClass . '">' . safeHtml($value) . '</span>';
                                } elseif (is_array($value) || is_object($value)) {
                                    echo '<pre style="font-size:0.55rem; max-height:50px; overflow:auto;">' . safeHtml(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
                                } else {
                                    echo safeHtml($value);
                                }
                                ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- ACCESS DENIED -->
        <!-- ============================================================ -->
        <?php if (!canView($view) && $view !== 'dashboard' && $view !== 'all_tables' && $view !== 'live_transactions' && $view !== 'generate_reports'): ?>
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
