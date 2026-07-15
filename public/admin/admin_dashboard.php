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
            'settlement_analysis', 'forex_fees', 'corridor_fees',
            'recent_swaps', 'swap_transactions', 'cross_border',
            'settlements', 'payment_instructions', 'card_transactions'
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
            'fee_breakdown', 'revenue_split', 'financial_dashboard',
            'recent_swaps', 'cross_border'
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
            'compliance', 'all_tables_readonly', 'fee_breakdown',
            'recent_swaps'
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
            'fee_breakdown', 'revenue_split', 'recent_swaps'
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
            'reports', 'forex_fees', 'recent_swaps',
            'swap_transactions', 'settlements'
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
            'corridor_fees', 'reports', 'settlement_analysis',
            'recent_swaps', 'cross_border'
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
            'revenue_breakdown', 'recent_swaps'
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
            'fee_compliance', 'recent_swaps'
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
// FETCH TABLE DATA - COMPREHENSIVE ALL TABLES
// ============================================================
$tableData = [];
$tablesToFetch = [
    // Core transaction tables
    'swap_requests' => ['label' => '📋 Swap Requests', 'order' => 'created_at DESC', 'limit' => 100],
    'swap_transactions' => ['label' => '🔄 Swap Transactions', 'order' => 'created_at DESC', 'limit' => 100],
    'swap_ledgers' => ['label' => '📒 Swap Ledgers', 'order' => 'created_at DESC', 'limit' => 100],
    'swap_fee_collections' => ['label' => '💳 Fee Collections', 'order' => 'created_at DESC', 'limit' => 100],
    'swap_vouchers' => ['label' => '🎫 Swap Vouchers', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Hold and authorization tables
    'hold_transactions' => ['label' => '🔒 Hold Transactions', 'order' => 'created_at DESC', 'limit' => 100],
    'identity_swap_holds' => ['label' => '🆔 Identity Swap Holds', 'order' => 'created_at DESC', 'limit' => 100],
    'cashout_authorizations' => ['label' => '🏧 Cashout Authorizations', 'order' => 'created_at DESC', 'limit' => 100],
    'card_authorizations' => ['label' => '🔐 Card Authorizations', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Settlement tables
    'settlement_queue' => ['label' => '📤 Settlement Queue', 'order' => 'created_at DESC', 'limit' => 100],
    'settlement_messages' => ['label' => '💬 Settlement Messages', 'order' => 'created_at DESC', 'limit' => 100],
    'settlement_outbox' => ['label' => '📤 Settlement Outbox', 'order' => 'created_at DESC', 'limit' => 100],
    'settlement_reports' => ['label' => '📊 Settlement Reports', 'order' => 'generated_at DESC', 'limit' => 100],
    'settlement_acknowledgements' => ['label' => '✅ Settlement Acknowledgements', 'order' => 'received_at DESC', 'limit' => 100],
    'net_positions' => ['label' => '⚖️ Net Positions', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Cross-border tables
    'cross_border_messages' => ['label' => '🌍 Cross-Border Messages', 'order' => 'created_at DESC', 'limit' => 100],
    'corridor_settlement_ledger' => ['label' => '🛤️ Corridor Settlement Ledger', 'order' => 'created_at DESC', 'limit' => 100],
    'vouchmorph_corridor_accounts' => ['label' => '🏦 Corridor Accounts', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Multi-source/destination tables
    'multi_source_swaps' => ['label' => '🔗 Multi-Source Swaps', 'order' => 'created_at DESC', 'limit' => 100],
    'multi_destination_swaps' => ['label' => '🎯 Multi-Destination Swaps', 'order' => 'created_at DESC', 'limit' => 100],
    'multi_source_contributions' => ['label' => '📥 Multi-Source Contributions', 'order' => 'created_at DESC', 'limit' => 100],
    'virtual_funding_pools' => ['label' => '🏊 Virtual Funding Pools', 'order' => 'created_at DESC', 'limit' => 100],
    'pool_contributions' => ['label' => '🏊 Pool Contributions', 'order' => 'created_at DESC', 'limit' => 100],
    'pool_master_signatures' => ['label' => '🔑 Pool Master Signatures', 'order' => 'created_at DESC', 'limit' => 100],
    'card_pool_hooks' => ['label' => '🪝 Card Pool Hooks', 'order' => 'created_at DESC', 'limit' => 100],
    'card_pool_hook_sources' => ['label' => '🪝 Card Pool Hook Sources', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Payment tables
    'payment_instructions' => ['label' => '💳 Payment Instructions', 'order' => 'created_at DESC', 'limit' => 100],
    'deposit_transactions' => ['label' => '💰 Deposit Transactions', 'order' => 'created_at DESC', 'limit' => 100],
    'send_to_other_transactions' => ['label' => '📤 Send to Other Transactions', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Card tables
    'card_transactions' => ['label' => '💳 Card Transactions', 'order' => 'created_at DESC', 'limit' => 100],
    'card_applications' => ['label' => '📋 Card Applications', 'order' => 'created_at DESC', 'limit' => 100],
    'card_batches' => ['label' => '📦 Card Batches', 'order' => 'created_at DESC', 'limit' => 100],
    'message_cards' => ['label' => '🃏 Message Cards', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Fee and invoice tables
    'fee_invoices' => ['label' => '💰 Fee Invoices', 'order' => 'created_at DESC', 'limit' => 100],
    'transaction_fees' => ['label' => '💲 Transaction Fees', 'order' => 'created_at DESC', 'limit' => 100],
    'participant_fee_overrides' => ['label' => '⚙️ Participant Fee Overrides', 'order' => 'created_at DESC', 'limit' => 100],
    
    // FX tables
    'fx_quotes' => ['label' => '💱 FX Quotes', 'order' => 'created_at DESC', 'limit' => 100],
    'fx_rates' => ['label' => '📈 FX Rates', 'order' => 'created_at DESC', 'limit' => 100],
    'fx_cached_rates' => ['label' => '💾 FX Cached Rates', 'order' => 'fetched_at DESC', 'limit' => 100],
    'fx_profit_records' => ['label' => '💰 FX Profit Records', 'order' => 'recorded_at DESC', 'limit' => 100],
    'fx_providers' => ['label' => '🏛️ FX Providers', 'order' => 'created_at DESC', 'limit' => 100],
    
    // AML and Compliance tables
    'aml_checks' => ['label' => '🛡️ AML Checks', 'order' => 'performed_at DESC', 'limit' => 100],
    'kyc_documents' => ['label' => '📄 KYC Documents', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Audit and Log tables
    'audit_logs' => ['label' => '📝 Audit Logs', 'order' => 'performed_at DESC', 'limit' => 100],
    'admin_actions' => ['label' => '🔧 Admin Actions', 'order' => 'created_at DESC', 'limit' => 100],
    'api_message_logs' => ['label' => '📡 API Message Logs', 'order' => 'created_at DESC', 'limit' => 100],
    'organization_audit_logs' => ['label' => '🏢 Organization Audit Logs', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Regulatory tables
    'regulator_notifications' => ['label' => '📨 Regulator Notifications', 'order' => 'created_at DESC', 'limit' => 100],
    'regulatory_reports' => ['label' => '📑 Regulatory Reports', 'order' => 'generated_at DESC', 'limit' => 100],
    'regulator_outbox' => ['label' => '📤 Regulator Outbox', 'order' => 'created_at DESC', 'limit' => 100],
    'supervisory_heartbeat' => ['label' => '💓 Supervisory Heartbeat', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Batch tables
    'batch_approvals' => ['label' => '✅ Batch Approvals', 'order' => 'created_at DESC', 'limit' => 100],
    'batch_execution_summaries' => ['label' => '📋 Batch Execution Summaries', 'order' => 'created_at DESC', 'limit' => 100],
    'batch_execution_summary' => ['label' => '📋 Batch Execution Summary', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Organization tables
    'organizations' => ['label' => '🏢 Organizations', 'order' => 'created_at DESC', 'limit' => 100],
    'organization_sources' => ['label' => '🏦 Organization Sources', 'order' => 'created_at DESC', 'limit' => 100],
    'organization_beneficiaries' => ['label' => '👥 Organization Beneficiaries', 'order' => 'created_at DESC', 'limit' => 100],
    'organization_users' => ['label' => '👤 Organization Users', 'order' => 'created_at DESC', 'limit' => 100],
    'departments' => ['label' => '🏛️ Departments', 'order' => 'created_at DESC', 'limit' => 100],
    'disbursement_programs' => ['label' => '📋 Disbursement Programs', 'order' => 'created_at DESC', 'limit' => 100],
    'disbursement_schedules' => ['label' => '📅 Disbursement Schedules', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Participant tables
    'participants' => ['label' => '🏛️ Participants', 'order' => 'provider_code ASC', 'limit' => 100],
    'participant_currencies' => ['label' => '💱 Participant Currencies', 'order' => 'created_at DESC', 'limit' => 100],
    'providers' => ['label' => '🔌 Providers', 'order' => 'created_at DESC', 'limit' => 100],
    
    // User tables
    'users' => ['label' => '👤 Users', 'order' => 'created_at DESC', 'limit' => 100],
    'admins' => ['label' => '🔑 Admins', 'order' => 'created_at DESC', 'limit' => 100],
    'user_bank_connections' => ['label' => '🏦 User Bank Connections', 'order' => 'created_at DESC', 'limit' => 100],
    'user_funding_sources' => ['label' => '💰 User Funding Sources', 'order' => 'created_at DESC', 'limit' => 100],
    'user_hooks' => ['label' => '🪝 User Hooks', 'order' => 'created_at DESC', 'limit' => 100],
    'user_identifiers' => ['label' => '🆔 User Identifiers', 'order' => 'created_at DESC', 'limit' => 100],
    'user_identities' => ['label' => '🆔 User Identities', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Import tables
    'import_batches' => ['label' => '📥 Import Batches', 'order' => 'created_at DESC', 'limit' => 100],
    'import_rows' => ['label' => '📄 Import Rows', 'order' => 'created_at DESC', 'limit' => 100],
    'column_mapping_templates' => ['label' => '📋 Column Mapping Templates', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Ledger tables
    'ledger_entries' => ['label' => '📊 Ledger Entries', 'order' => 'created_at DESC', 'limit' => 100],
    'ledger_accounts' => ['label' => '📒 Ledger Accounts', 'order' => 'created_at DESC', 'limit' => 100],
    'transaction_splits' => ['label' => '✂️ Transaction Splits', 'order' => 'created_at DESC', 'limit' => 100],
    
    // Security tables
    'oauth_tokens' => ['label' => '🔑 OAuth Tokens', 'order' => 'created_at DESC', 'limit' => 100],
    'otp_logs' => ['label' => '📱 OTP Logs', 'order' => 'created_at DESC', 'limit' => 100],
    'institution_keys' => ['label' => '🔐 Institution Keys', 'order' => 'created_at DESC', 'limit' => 100],
    'certificate_revocation_list' => ['label' => '📜 Certificate Revocation List', 'order' => 'revoked_at DESC', 'limit' => 100],
    
    // Other tables
    'sms_logs' => ['label' => '📱 SMS Logs', 'order' => 'created_at DESC', 'limit' => 100],
    'ussd_sessions' => ['label' => '📱 USSD Sessions', 'order' => 'created_at DESC', 'limit' => 100],
    'idempotency_keys' => ['label' => '🔑 Idempotency Keys', 'order' => 'created_at DESC', 'limit' => 100],
    'message_outbox' => ['label' => '📤 Message Outbox', 'order' => 'created_at DESC', 'limit' => 100],
    'approval_thresholds' => ['label' => '📊 Approval Thresholds', 'order' => 'created_at DESC', 'limit' => 100],
    'roles' => ['label' => '👥 Roles', 'order' => 'role_level DESC', 'limit' => 100],
    'beneficiary_category_reference' => ['label' => '📋 Beneficiary Categories', 'order' => 'code ASC', 'limit' => 100],
    'organization_role_catalog' => ['label' => '📋 Organization Role Catalog', 'order' => 'role_code ASC', 'limit' => 100],
    'organization_role_permissions' => ['label' => '🔑 Organization Role Permissions', 'order' => 'role_code ASC', 'limit' => 100],
    'sandbox_disclosures' => ['label' => '📄 Sandbox Disclosures', 'order' => 'created_at DESC', 'limit' => 100],
    'vouchmorph_notifications' => ['label' => '🔔 VouchMorph Notifications', 'order' => 'created_at DESC', 'limit' => 100],
    'master_settlement_signatures' => ['label' => '🔑 Master Settlement Signatures', 'order' => 'constructed_at DESC', 'limit' => 100],
];

foreach ($tablesToFetch as $table => $config) {
    try {
        // Check if table exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table");
        $stmt->execute([':table' => $table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        
        if ($exists) {
            $orderBy = $config['order'] ?? 'created_at DESC';
            $limit = $config['limit'] ?? 100;
            
            // Get column names first
            $colStmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '{$table}' ORDER BY ordinal_position");
            $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get data
            $dataStmt = $db->query("SELECT * FROM {$table} ORDER BY {$orderBy} LIMIT {$limit}");
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $tableData[$table] = [
                'exists' => true,
                'rows' => $rows,
                'count' => count($rows),
                'label' => $config['label'],
                'columns' => $columns
            ];
            
            error_log("[ADMIN DASHBOARD] Fetched {$table}: " . count($rows) . " rows");
        } else {
            $tableData[$table] = [
                'exists' => false, 
                'rows' => [], 
                'count' => 0, 
                'label' => $config['label'], 
                'columns' => [],
                'error' => 'Table does not exist'
            ];
            error_log("[ADMIN DASHBOARD] Table {$table} does not exist");
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] Error fetching {$table}: " . $e->getMessage());
        $tableData[$table] = [
            'exists' => false, 
            'rows' => [], 
            'count' => 0, 
            'label' => $config['label'], 
            'columns' => [],
            'error' => $e->getMessage()
        ];
    }
}

// ============================================================
// RECENT SWAPS WITH DETAILS - COMBINED VIEW
// ============================================================
$recentSwaps = [];
$swapDetails = [];

try {
    // Get recent swaps with related data from multiple tables
    $stmt = $db->prepare("
        SELECT 
            sr.swap_id,
            sr.swap_uuid,
            sr.amount,
            sr.from_currency,
            sr.to_currency,
            sr.status as swap_status,
            sr.created_at,
            sr.source_country,
            sr.destination_country,
            sr.forex_rate,
            sr.expected_to_amount,
            sr.forex_fee_percent,
            sr.forex_fee_amount,
            sr.source_details,
            sr.destination_details,
            sr.fee_breakdown,
            sr.trade_metadata,
            p.name as participant_name,
            p.provider_code,
            p.type as participant_type,
            st.swap_transaction_id,
            st.status as tx_status,
            st.error_message,
            st.retry_count,
            fi.invoice_uuid,
            fi.total_amount as fee_amount,
            fi.fee_type,
            fi.status as invoice_status,
            ht.hold_reference,
            ht.amount as hold_amount,
            ht.status as hold_status,
            sq.status as settlement_status,
            sq.reference as settlement_reference,
            cbm.status as cross_border_status,
            cbm.source_institution,
            cbm.destination_institution,
            cbm.corridor_fee
        FROM swap_requests sr
        LEFT JOIN participants p ON sr.source_details->>'institution' = p.name
        LEFT JOIN swap_transactions st ON sr.swap_id = st.swap_id
        LEFT JOIN fee_invoices fi ON sr.swap_uuid = fi.swap_reference
        LEFT JOIN hold_transactions ht ON sr.swap_reference = ht.swap_reference
        LEFT JOIN settlement_queue sq ON sr.swap_reference = sq.reference
        LEFT JOIN cross_border_messages cbm ON sr.swap_reference = cbm.swap_reference
        WHERE sr.created_at >= NOW() - INTERVAL '30 days'
        ORDER BY sr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get swap transaction details
    $stmt = $db->prepare("
        SELECT 
            st.swap_transaction_id,
            st.swap_id,
            st.amount,
            st.status,
            st.error_message,
            st.retry_count,
            st.created_at,
            st.metadata,
            sr.swap_uuid,
            sr.amount as swap_amount,
            sr.status as swap_status,
            sr.created_at as swap_created_at
        FROM swap_transactions st
        LEFT JOIN swap_requests sr ON st.swap_id = sr.swap_id
        ORDER BY st.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $swapDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Recent swaps error: " . $e->getMessage());
    $recentSwaps = [];
    $swapDetails = [];
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
// METRICS - Enhanced with recent activity
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
    
    // Recent activity metrics
    $metrics['recent_swaps_24h'] = (int)$db->query("
        SELECT COUNT(*) FROM swap_requests 
        WHERE created_at >= NOW() - INTERVAL '24 hours'
    ")->fetchColumn();
    
    $metrics['recent_swaps_7d'] = (int)$db->query("
        SELECT COUNT(*) FROM swap_requests 
        WHERE created_at >= NOW() - INTERVAL '7 days'
    ")->fetchColumn();
    
    $metrics['pending_settlements'] = (int)$db->query("
        SELECT COUNT(*) FROM settlement_queue 
        WHERE status = 'PENDING'
    ")->fetchColumn();
    
    $metrics['failed_transactions_24h'] = (int)$db->query("
        SELECT COUNT(*) FROM swap_requests 
        WHERE status IN ('FAILED', 'error') 
        AND created_at >= NOW() - INTERVAL '24 hours'
    ")->fetchColumn();
    
    $metrics['total_swap_transactions'] = (int)$db->query("
        SELECT COUNT(*) FROM swap_transactions
    ")->fetchColumn();
    
    $metrics['total_cross_border'] = (int)$db->query("
        SELECT COUNT(*) FROM cross_border_messages
    ")->fetchColumn();
    
    $metrics['total_payment_instructions'] = (int)$db->query("
        SELECT COUNT(*) FROM payment_instructions
    ")->fetchColumn();
    
    $metrics['total_card_transactions'] = (int)$db->query("
        SELECT COUNT(*) FROM card_transactions
    ")->fetchColumn();
    
} catch (Throwable $e) {
    $metrics = array_fill_keys([
        'total_users', 'total_swaps', 'total_holds', 'total_cashouts', 
        'total_invoices', 'total_audit_logs', 'total_fee_collections', 'total_fees',
        'recent_swaps_24h', 'recent_swaps_7d', 'pending_settlements',
        'failed_transactions_24h', 'total_swap_transactions', 'total_cross_border',
        'total_payment_instructions', 'total_card_transactions'
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
        .metric-value .trend-up { color: #28a745; font-size: 0.7rem; }
        .metric-value .trend-down { color: #dc3545; font-size: 0.7rem; }
        
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
        .status-warning { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .status-processing { background: #cce5ff; color: #004085; border-color: #b8daff; }
        
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
        
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 12px;
            font-size: 0.7rem;
            margin-bottom: 16px;
            border-radius: 4px;
            overflow-x: auto;
        }
        .debug-info code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
        }
        
        .swap-detail-row {
            background: #f8f9fa;
            border-left: 3px solid #FFDA63;
        }
        
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
        
        <?php if (canView('recent_swaps')): ?>
        <a href="?view=recent_swaps" class="nav-item <?php echo $view === 'recent_swaps' ? 'active' : ''; ?>">🔄 RECENT SWAPS</a>
        <?php endif; ?>
        
        <?php if (canView('transactions') || canView('transactions_readonly')): ?>
        <a href="?view=transactions" class="nav-item <?php echo $view === 'transactions' ? 'active' : ''; ?>">📋 TRANSACTIONS</a>
        <?php endif; ?>
        
        <?php if (canView('swap_transactions')): ?>
        <a href="?view=swap_transactions" class="nav-item <?php echo $view === 'swap_transactions' ? 'active' : ''; ?>">🔄 SWAP TXNS</a>
        <?php endif; ?>
        
        <?php if (canView('cross_border')): ?>
        <a href="?view=cross_border" class="nav-item <?php echo $view === 'cross_border' ? 'active' : ''; ?>">🌍 CROSS-BORDER</a>
        <?php endif; ?>
        
        <?php if (canView('settlements')): ?>
        <a href="?view=settlements" class="nav-item <?php echo $view === 'settlements' ? 'active' : ''; ?>">📤 SETTLEMENTS</a>
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
        
        <?php if (canView('card_transactions')): ?>
        <a href="?view=card_transactions" class="nav-item <?php echo $view === 'card_transactions' ? 'active' : ''; ?>">💳 CARDS</a>
        <?php endif; ?>
        
        <?php if (canView('payment_instructions')): ?>
        <a href="?view=payment_instructions" class="nav-item <?php echo $view === 'payment_instructions' ? 'active' : ''; ?>">💳 PAYMENTS</a>
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
            <?php if ($isSuperAdmin || $isSettlementOfficer): ?>
            <div class="metric-card">
                <div class="metric-label">Pending Settlements</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_settlements']); ?></div>
            </div>
            <?php endif; ?>
            <div class="metric-card">
                <div class="metric-label">24h Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['recent_swaps_24h']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">7d Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['recent_swaps_7d']); ?></div>
            </div>
            <?php if ($isSuperAdmin || $isFinanceManager): ?>
            <div class="metric-card">
                <div class="metric-label">Swap Transactions</div>
                <div class="metric-value"><?php echo number_format($metrics['total_swap_transactions']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Cross-Border</div>
                <div class="metric-value"><?php echo number_format($metrics['total_cross_border']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Card TXNs</div>
                <div class="metric-value"><?php echo number_format($metrics['total_card_transactions']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($metrics['failed_transactions_24h'] > 0): ?>
            <div class="metric-card" style="border-color: #dc3545;">
                <div class="metric-label">⚠️ Failed (24h)</div>
                <div class="metric-value" style="color: #dc3545;"><?php echo number_format($metrics['failed_transactions_24h']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Summary Stats -->
        <div class="fee-box">
            <div class="title">💰 Financial Summary</div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-top:8px;">
                <div><strong>Today's Volume:</strong> <?php echo number_format($dailyStats['total_amount'] ?? 0, 2); ?> BWP</div>
                <div><strong>Today's Transactions:</strong> <?php echo number_format($dailyStats['transaction_count'] ?? 0); ?></div>
                <div><strong>Completed Today:</strong> <?php echo number_format($dailyStats['completed_count'] ?? 0); ?></div>
                <div><strong>Total Fees Collected:</strong> <?php echo number_format($metrics['total_fees'] ?? 0, 2); ?> BWP</div>
                <div><strong>24h Swap Volume:</strong> <?php echo number_format($metrics['recent_swaps_24h'] ?? 0); ?> TXNs</div>
                <div><strong>Pending Settlements:</strong> <?php echo number_format($metrics['pending_settlements'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Recent Swaps Quick View -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🔄 Recent Swaps (Last 30 Days)</span>
                <span class="card-badge"><?php echo count($recentSwaps); ?> RECORDS</span>
                <a href="?view=recent_swaps" class="btn btn-sm">View All</a>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Amount</th>
                            <th>From/To</th>
                            <th>Status</th>
                            <th>Participant</th>
                            <th>Fee</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSwaps)): ?>
                        <tr><td colspan="7" class="empty-state">No recent swaps found</td></tr>
                        <?php else: ?>
                        <?php foreach (array_slice($recentSwaps, 0, 20) as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['swap_uuid'] ?? 'N/A', 0, 12)); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td><?php echo safeHtml($row['from_currency'] ?? '') . ' → ' . safeHtml($row['to_currency'] ?? ''); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['swap_status'] ?? 'pending');
                                $class = match($status) {
                                    'completed', 'success', 'paid' => 'success',
                                    'pending', 'sent', 'pending_cashout' => 'pending',
                                    'failed', 'error', 'expired' => 'failed',
                                    'processing' => 'processing',
                                    default => 'info'
                                };
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['swap_status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo safeHtml($row['participant_name'] ?? $row['provider_code'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['fee_amount'] ?? $row['forex_fee_amount'] ?? 0), 2); ?></td>
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
        <!-- RECENT SWAPS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'recent_swaps' && canView('recent_swaps')): ?>
        <div class="content-header">
            <h1>🔄 RECENT SWAPS</h1>
            <div class="timestamp">Detailed swap transactions with all related data</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back to Dashboard</a>
        </div>

        <!-- Swap Stats -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));">
            <div class="metric-card">
                <div class="metric-label">Total Swaps (30d)</div>
                <div class="metric-value"><?php echo count($recentSwaps); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Completed</div>
                <div class="metric-value" style="color:#28a745;">
                    <?php echo count(array_filter($recentSwaps, function($s) { 
                        return in_array(strtolower($s['swap_status'] ?? ''), ['completed', 'success']); 
                    })); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Pending</div>
                <div class="metric-value" style="color:#856404;">
                    <?php echo count(array_filter($recentSwaps, function($s) { 
                        return in_array(strtolower($s['swap_status'] ?? ''), ['pending', 'processing']); 
                    })); ?>
                </div>
            </div>
            <div class="metric-card" style="border-color: #dc3545;">
                <div class="metric-label">Failed</div>
                <div class="metric-value" style="color:#dc3545;">
                    <?php echo count(array_filter($recentSwaps, function($s) { 
                        return in_array(strtolower($s['swap_status'] ?? ''), ['failed', 'error', 'expired']); 
                    })); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Volume</div>
                <div class="metric-value">
                    <?php echo number_format(array_sum(array_column($recentSwaps, 'amount')), 2); ?>
                </div>
            </div>
        </div>

        <!-- Full Recent Swaps Table -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 All Recent Swaps</span>
                <span class="card-badge"><?php echo count($recentSwaps); ?> RECORDS</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=swap_requests&export_id=all" class="btn btn-primary">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Swap UUID</th>
                            <th>Amount</th>
                            <th>From/To</th>
                            <th>Status</th>
                            <th>Participant</th>
                            <th>FX Rate</th>
                            <th>Expected To</th>
                            <th>Fee %</th>
                            <th>Fee Amount</th>
                            <th>Created</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSwaps)): ?>
                        <tr><td colspan="11" class="empty-state">No swaps found</td></tr>
                        <?php else: ?>
                        <?php foreach ($recentSwaps as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['swap_uuid'] ?? 'N/A', 0, 12)) . '…'; ?></td>
                            <td><strong><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                            <td><?php echo safeHtml($row['from_currency'] ?? '') . ' → ' . safeHtml($row['to_currency'] ?? ''); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['swap_status'] ?? 'pending');
                                $class = match($status) {
                                    'completed', 'success', 'paid' => 'success',
                                    'pending', 'sent', 'pending_cashout' => 'pending',
                                    'failed', 'error', 'expired' => 'failed',
                                    'processing' => 'processing',
                                    default => 'info'
                                };
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['swap_status'] ?? 'pending'); ?></span>
                                <?php if (!empty($row['tx_status']) && $row['tx_status'] !== $row['swap_status']): ?>
                                <br><small style="color:#666;">TX: <?php echo safeHtml($row['tx_status']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo safeHtml($row['participant_name'] ?? $row['provider_code'] ?? $row['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['forex_rate'] ?? 1), 4); ?></td>
                            <td><?php echo number_format((float)($row['expected_to_amount'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($row['forex_fee_percent'] ?? 0), 2); ?>%</td>
                            <td><?php echo number_format((float)($row['forex_fee_amount'] ?? $row['fee_amount'] ?? 0), 2); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($row['created_at'] ?? 'now')); ?></td>
                            <td>
                                <?php if (!empty($row['hold_reference'])): ?>
                                <span class="status status-info">🔒 Hold</span>
                                <?php endif; ?>
                                <?php if (!empty($row['cross_border_status'])): ?>
                                <span class="status status-processing">🌍 CB</span>
                                <?php endif; ?>
                                <?php if (!empty($row['settlement_status'])): ?>
                                <span class="status status-pending">📤 Settle</span>
                                <?php endif; ?>
                                <?php if (!empty($row['error_message'])): ?>
                                <span class="status status-failed" title="<?php echo safeHtml($row['error_message']); ?>">⚠️</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Swap Transactions Details -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🔄 Swap Transaction Details</span>
                <span class="card-badge"><?php echo count($swapDetails); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>TX ID</th>
                            <th>Swap ID</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Retry Count</th>
                            <th>Error</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($swapDetails)): ?>
                        <tr><td colspan="7" class="empty-state">No swap transaction details found</td></tr>
                        <?php else: ?>
                        <?php foreach (array_slice($swapDetails, 0, 30) as $row): ?>
                        <tr class="<?php echo !empty($row['error_message']) ? 'swap-detail-row' : ''; ?>">
                            <td><?php echo safeHtml(substr($row['swap_transaction_id'] ?? 'N/A', 0, 10)); ?></td>
                            <td><?php echo safeHtml(substr($row['swap_uuid'] ?? $row['swap_id'] ?? 'N/A', 0, 12)); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? $row['swap_amount'] ?? 0), 2); ?></td>
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
                            <td><?php echo number_format($row['retry_count'] ?? 0); ?></td>
                            <td>
                                <?php if (!empty($row['error_message'])): ?>
                                <span style="color:#dc3545; font-size:0.55rem;"><?php echo safeHtml(substr($row['error_message'], 0, 50)); ?></span>
                                <?php else: ?>
                                <span style="color:#999;">—</span>
                                <?php endif; ?>
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
        <!-- FEE BREAKDOWN VIEW -->
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
                <div><strong>Pending Settlements:</strong> <?php echo number_format($metrics['pending_settlements'] ?? 0); ?></div>
                <div><strong>Total Transactions:</strong> <?php echo number_format($metrics['total_swaps'] ?? 0); ?></div>
                <div><strong>Cross-Border:</strong> <?php echo number_format($metrics['total_cross_border'] ?? 0); ?></div>
                <div><strong>24h Failed:</strong> <?php echo number_format($metrics['failed_transactions_24h'] ?? 0); ?></div>
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
                        <?php $auditRows = $tableData['audit_logs']['rows'] ?? []; ?>
                        <?php if (empty($auditRows)): ?>
                        <tr><td colspan="5" class="empty-state">No audit records found</td></tr>
                        <?php else: ?>
                        <?php foreach (array_slice($auditRows, 0, 50) as $row): ?>
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
                            <?php if (hasPermission('review_transactions') && !$isReadOnly): ?>
                            <td>
                                <a href="?view=transactions&id=<?php echo $row['swap_id'] ?? $row['swap_uuid'] ?? ''; ?>" style="font-size:0.6rem; color:#001B44;">View</a>
                                <?php if (hasPermission('approve')): ?>
                                <button class="btn btn-sm" style="font-size:0.55rem; padding:2px 8px;">Approve</button>
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
        <!-- SWAP TRANSACTIONS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'swap_transactions' && canView('swap_transactions')): ?>
        <div class="content-header">
            <h1>🔄 SWAP TRANSACTIONS</h1>
            <div class="timestamp">Detailed swap transaction records</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">All Swap Transactions</span>
                <span class="card-badge"><?php echo count($tableData['swap_transactions']['rows'] ?? []); ?> RECORDS</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=swap_transactions&export_id=all" class="btn btn-primary">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>TX ID</th>
                            <th>Swap ID</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Retry</th>
                            <th>Error</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $stRows = $tableData['swap_transactions']['rows'] ?? []; ?>
                        <?php if (empty($stRows)): ?>
                        <tr><td colspan="7" class="empty-state">No swap transactions found</td></tr>
                        <?php else: ?>
                        <?php foreach ($stRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['swap_transaction_id'] ?? 'N/A', 0, 10)); ?></td>
                            <td><?php echo safeHtml(substr($row['swap_id'] ?? 'N/A', 0, 10)); ?></td>
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
                            <td><?php echo number_format($row['retry_count'] ?? 0); ?></td>
                            <td>
                                <?php if (!empty($row['error_message'])): ?>
                                <span style="color:#dc3545; font-size:0.55rem;"><?php echo safeHtml(substr($row['error_message'], 0, 50)); ?></span>
                                <?php else: ?>
                                <span style="color:#999;">—</span>
                                <?php endif; ?>
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
        <!-- CROSS-BORDER VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'cross_border' && canView('cross_border')): ?>
        <div class="content-header">
            <h1>🌍 CROSS-BORDER TRANSACTIONS</h1>
            <div class="timestamp">Cross-border settlement messages</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Cross-Border Messages</span>
                <span class="card-badge"><?php echo count($tableData['cross_border_messages']['rows'] ?? []); ?> RECORDS</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=cross_border_messages&export_id=all" class="btn btn-primary">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>FX Rate</th>
                            <th>Corridor Fee</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $cbRows = $tableData['cross_border_messages']['rows'] ?? []; ?>
                        <?php if (empty($cbRows)): ?>
                        <tr><td colspan="9" class="empty-state">No cross-border messages found</td></tr>
                        <?php else: ?>
                        <?php foreach ($cbRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['swap_reference'] ?? $row['message_uuid'] ?? 'N/A', 0, 12)); ?></td>
                            <td><?php echo safeHtml($row['source_country'] ?? $row['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['destination_country'] ?? $row['destination_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td><?php echo safeHtml($row['source_currency'] ?? '') . '→' . safeHtml($row['destination_currency'] ?? ''); ?></td>
                            <td><?php echo number_format((float)($row['exchange_rate'] ?? 1), 4); ?></td>
                            <td><?php echo number_format((float)($row['corridor_fee'] ?? 0), 2); ?></td>
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

        <!-- Corridor Settlement Ledger -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🛤️ Corridor Settlement Ledger</span>
                <span class="card-badge"><?php echo count($tableData['corridor_settlement_ledger']['rows'] ?? []); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Amount</th>
                            <th>Rate</th>
                            <th>Fee</th>
                            <th>Status</th>
                            <th>Settled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $csRows = $tableData['corridor_settlement_ledger']['rows'] ?? []; ?>
                        <?php if (empty($csRows)): ?>
                        <tr><td colspan="8" class="empty-state">No corridor settlement records found</td></tr>
                        <?php else: ?>
                        <?php foreach ($csRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['swap_reference'] ?? 'N/A', 0, 12)); ?></td>
                            <td><?php echo safeHtml($row['source_country'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['destination_country'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['source_amount'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($row['exchange_rate'] ?? 1), 4); ?></td>
                            <td><?php echo number_format((float)($row['corridor_fee'] ?? 0), 2); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = match($status) {
                                    'settled' => 'success',
                                    'pending', 'processing' => 'pending',
                                    'failed' => 'failed',
                                    default => 'info'
                                };
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo $row['settled_at'] ? date('Y-m-d H:i', strtotime($row['settled_at'])) : '—'; ?></td>
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
            <h1>📤 SETTLEMENT QUEUE</h1>
            <div class="timestamp">Settlement processing and queue management</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <!-- Settlement Stats -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));">
            <div class="metric-card">
                <div class="metric-label">Total Settlements</div>
                <div class="metric-value"><?php echo count($tableData['settlement_queue']['rows'] ?? []); ?></div>
            </div>
            <div class="metric-card" style="border-color: #856404;">
                <div class="metric-label">Pending</div>
                <div class="metric-value" style="color:#856404;">
                    <?php echo count(array_filter($tableData['settlement_queue']['rows'] ?? [], function($s) { 
                        return strtolower($s['status'] ?? '') === 'pending'; 
                    })); ?>
                </div>
            </div>
            <div class="metric-card" style="border-color: #28a745;">
                <div class="metric-label">Completed</div>
                <div class="metric-value" style="color:#28a745;">
                    <?php echo count(array_filter($tableData['settlement_queue']['rows'] ?? [], function($s) { 
                        return strtolower($s['status'] ?? '') === 'completed'; 
                    })); ?>
                </div>
            </div>
            <div class="metric-card" style="border-color: #dc3545;">
                <div class="metric-label">Failed</div>
                <div class="metric-value" style="color:#dc3545;">
                    <?php echo count(array_filter($tableData['settlement_queue']['rows'] ?? [], function($s) { 
                        return strtolower($s['status'] ?? '') === 'failed'; 
                    })); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Amount</div>
                <div class="metric-value">
                    <?php echo number_format(array_sum(array_column($tableData['settlement_queue']['rows'] ?? [], 'amount')), 2); ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📤 Settlement Queue</span>
                <span class="card-badge"><?php echo count($tableData['settlement_queue']['rows'] ?? []); ?> RECORDS</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=settlement_queue&export_id=all" class="btn btn-primary">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Debtor</th>
                            <th>Creditor</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Reference</th>
                            <th>Created</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sqRows = $tableData['settlement_queue']['rows'] ?? []; ?>
                        <?php if (empty($sqRows)): ?>
                        <tr><td colspan="9" class="empty-state">No settlement records found</td></tr>
                        <?php else: ?>
                        <?php foreach ($sqRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['id'] ?? 'N/A', 0, 10)); ?></td>
                            <td><?php echo safeHtml($row['debtor'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['creditor'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td><?php echo safeHtml($row['currency'] ?? 'BWP'); ?></td>
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
                            <td><?php echo safeHtml(substr($row['reference'] ?? 'N/A', 0, 12)); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($row['created_at'] ?? 'now')); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($row['updated_at'] ?? $row['created_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Settlement Messages -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">💬 Settlement Messages</span>
                <span class="card-badge"><?php echo count($tableData['settlement_messages']['rows'] ?? []); ?> RECORDS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>TX ID</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $smRows = $tableData['settlement_messages']['rows'] ?? []; ?>
                        <?php if (empty($smRows)): ?>
                        <tr><td colspan="7" class="empty-state">No settlement messages found</td></tr>
                        <?php else: ?>
                        <?php foreach ($smRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['transaction_id'] ?? 'N/A', 0, 12)); ?></td>
                            <td><?php echo safeHtml($row['from_participant'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['to_participant'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td><?php echo safeHtml($row['type'] ?? 'N/A'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = match($status) {
                                    'processed', 'success' => 'success',
                                    'pending', 'processing' => 'pending',
                                    'failed' => 'failed',
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
        <!-- CARD TRANSACTIONS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'card_transactions' && canView('card_transactions')): ?>
        <div class="content-header">
            <h1>💳 CARD TRANSACTIONS</h1>
            <div class="timestamp">Card transaction records</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">All Card Transactions</span>
                <span class="card-badge"><?php echo count($tableData['card_transactions']['rows'] ?? []); ?> RECORDS</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=card_transactions&export_id=all" class="btn btn-primary">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>TX ID</th>
                            <th>Card ID</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Merchant</th>
                            <th>Auth Status</th>
                            <th>Channel</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $ctRows = $tableData['card_transactions']['rows'] ?? []; ?>
                        <?php if (empty($ctRows)): ?>
                        <tr><td colspan="8" class="empty-state">No card transactions found</td></tr>
                        <?php else: ?>
                        <?php foreach ($ctRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['transaction_id'] ?? 'N/A', 0, 10)); ?></td>
                            <td><?php echo safeHtml(substr($row['card_id'] ?? 'N/A', 0, 10)); ?></td>
                            <td><?php echo safeHtml($row['transaction_type'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td><?php echo safeHtml(substr($row['merchant_name'] ?? 'N/A', 0, 20)); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['auth_status'] ?? 'pending');
                                $class = match($status) {
                                    'approved', 'success' => 'success',
                                    'pending', 'processing' => 'pending',
                                    'declined', 'failed' => 'failed',
                                    default => 'info'
                                };
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['auth_status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo safeHtml($row['channel'] ?? 'N/A'); ?></td>
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
        <!-- PAYMENT INSTRUCTIONS VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'payment_instructions' && canView('payment_instructions')): ?>
        <div class="content-header">
            <h1>💳 PAYMENT INSTRUCTIONS</h1>
            <div class="timestamp">Payment instruction records</div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">All Payment Instructions</span>
                <span class="card-badge"><?php echo count($tableData['payment_instructions']['rows'] ?? []); ?> RECORDS</span>
                <?php if (hasPermission('export')): ?>
                <a href="?export=payment_instructions&export_id=all" class="btn btn-primary">📄 Export</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Recipient</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $piRows = $tableData['payment_instructions']['rows'] ?? []; ?>
                        <?php if (empty($piRows)): ?>
                        <tr><td colspan="8" class="empty-state">No payment instructions found</td></tr>
                        <?php else: ?>
                        <?php foreach ($piRows as $row): ?>
                        <tr>
                            <td><?php echo safeHtml(substr($row['id'] ?? 'N/A', 0, 10)); ?></td>
                            <td><?php echo safeHtml(substr($row['recipient_name'] ?? 'N/A', 0, 20)); ?></td>
                            <td><?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                            <td><?php echo safeHtml($row['currency'] ?? 'BWP'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($row['status'] ?? 'pending');
                                $class = match($status) {
                                    'completed', 'executed', 'success' => 'success',
                                    'pending', 'processing', 'reserved' => 'pending',
                                    'failed', 'error' => 'failed',
                                    default => 'info'
                                };
                                ?>
                                <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($row['status'] ?? 'pending'); ?></span>
                            </td>
                            <td><?php echo safeHtml($row['source_type'] ?? 'N/A'); ?></td>
                            <td><?php echo safeHtml($row['destination_type'] ?? 'N/A'); ?></td>
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
        <!-- ALL TABLES VIEW - SHOWS ALL DATA -->
        <!-- ============================================================ -->
        <?php if ($view === 'all_tables' && $isSuperAdmin): ?>
        <div class="content-header">
            <h1>📋 ALL DATABASE TABLES</h1>
            <div class="timestamp">Complete database view · <?php echo date('Y-m-d H:i:s'); ?></div>
            <a href="?view=dashboard" style="font-size:0.7rem; color:#001B44;">← Back to Dashboard</a>
        </div>

        <!-- Debug info -->
        <div class="debug-info">
            <strong>📊 Database Status:</strong>
            <code>Connected</code> · 
            <strong>Tables:</strong> <?php echo count(array_filter($tableData, function($t) { return $t['exists']; })); ?> found
            <?php if (!empty($tableData)): ?>
            · <strong>Total Records:</strong> <?php echo array_sum(array_column($tableData, 'count')); ?>
            <?php endif; ?>
        </div>

        <?php foreach ($tableData as $tableName => $data): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?php echo $data['label'] ?? $tableName; ?></span>
                <span class="card-badge"><?php echo $data['count']; ?> RECORDS</span>
                <?php if ($data['exists'] && hasPermission('export')): ?>
                <a href="?export=<?php echo $tableName; ?>&export_id=all" class="btn btn-primary btn-sm">📄 Export</a>
                <?php endif; ?>
            </div>
            
            <?php if (!$data['exists']): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>Table <code><?php echo $tableName; ?></code> does not exist</p>
                <?php if (!empty($data['error'])): ?>
                <p style="color:#dc3545; font-size:0.7rem; margin-top:4px;">Error: <?php echo safeHtml($data['error']); ?></p>
                <?php endif; ?>
            </div>
            <?php elseif (empty($data['rows'])): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No records found in <code><?php echo $tableName; ?></code></p>
            </div>
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
                                if (is_null($value)) {
                                    echo '<span style="color:#999;">NULL</span>';
                                } elseif (is_string($value) && strlen($value) > 100) {
                                    echo safeHtml(substr($value, 0, 100)) . '...';
                                } elseif (is_numeric($value) && strpos($col, 'amount') !== false) {
                                    echo number_format((float)$value, 2);
                                } elseif (is_string($value) && in_array($col, ['status', 'type', 'action'])) {
                                    $statusClass = match(strtolower($value)) {
                                        'completed', 'success', 'paid', 'active', 'approved', 'settled' => 'success',
                                        'pending', 'sent', 'pending_cashout', 'processing', 'reserved' => 'pending',
                                        'failed', 'error', 'expired', 'declined' => 'failed',
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
        <?php if (!canView($view) && $view !== 'dashboard' && $view !== 'all_tables'): ?>
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
