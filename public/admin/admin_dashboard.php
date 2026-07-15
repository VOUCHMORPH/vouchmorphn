<?php
declare(strict_types=1);

error_reporting(E_ALL); 
ini_set('display_errors', 1);
session_start();

// Define project root
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load required classes FIRST
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;

// Check if admin is logged in
if (!SessionManager::isAdminLoggedIn()) {
    header('Location: admin_login.php');
    exit();
}

// Load configuration for country data only (not database)
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
if (file_exists($configPath)) {
    require_once $configPath;
    try {
        $config = \Core\Config\LoadCountry::getConfig();
        if (!is_array($config)) {
            $config = [];
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] Config error: " . $e->getMessage());
        $config = [];
    }
} else {
    $config = [];
}

// Get admin info from session
$adminId = SessionManager::getAdminId();
$adminUsername = SessionManager::getAdminUsername();
$adminFullName = SessionManager::get('admin_full_name');
$adminRoleId = SessionManager::getAdminRoleId();
$adminCountry = SessionManager::getAdminCountry();

// Get role name based on role_id
$roleNames = [
    999 => 'Super Admin',
    3 => 'Regulator',
    4 => 'Compliance Officer',
    5 => 'Auditor'
];
$roleName = $roleNames[$adminRoleId] ?? 'Administrator';

// Initialize database connection using DBConnection (Single Source of Truth)
try {
    $db = DBConnection::getConnection();
    
    if (!$db) {
        throw new Exception("Database connection failed - DATABASE_URL not set or invalid");
    }
    
    // Test connection
    $stmt = $db->query("SELECT 1");
    $stmt->fetch();
    error_log("[ADMIN DASHBOARD] Database connected successfully via DBConnection");
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] DB Error: " . $e->getMessage());
    die("Database connection failed. Please check configuration.");
}

// Get country code for display
$countryCode = $adminCountry ?: ($config['country_code'] ?? 'BW');
$countryName = $config['country'] ?? 'Botswana';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';

// Load participants from config
$participants = $config['participants'] ?? [];

// Define role-based permissions
$hasAccess = function($permission) use ($adminRoleId) {
    $permissions = [
        999 => ['all'],
        3 => ['view_dashboard', 'view_reports', 'audit_logs', 'compliance_checks'],
        4 => ['view_dashboard', 'view_reports', 'manage_compliance', 'review_transactions', 'kyc_verification'],
        5 => ['view_dashboard', 'view_reports', 'audit_logs', 'read_only']
    ];
    
    $userPerms = $permissions[$adminRoleId] ?? [];
    return in_array('all', $userPerms) || in_array($permission, $userPerms);
};

// ============================================================
// COMPREHENSIVE SYSTEM METRICS
// ============================================================

$metrics = [];
try {
    // Today's transactions
    $stmt = $db->prepare("SELECT COUNT(*) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $metrics['today_transactions'] = (int)$stmt->fetchColumn();
    
    // Today's volume
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $metrics['today_volume'] = number_format((float)$stmt->fetchColumn(), 2);
    
    // Active holds
    $stmt = $db->prepare("SELECT COUNT(*) FROM hold_transactions WHERE status = 'ACTIVE'");
    $stmt->execute();
    $metrics['active_holds'] = (int)$stmt->fetchColumn();
    
    // Pending settlements
    $stmt = $db->prepare("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'");
    $stmt->execute();
    $metrics['pending_settlements'] = (int)$stmt->fetchColumn();
    
    // Total users
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
    $stmt->execute();
    $metrics['total_users'] = (int)$stmt->fetchColumn();
    
    // Total volume
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM swap_requests");
    $stmt->execute();
    $metrics['total_volume'] = number_format((float)$stmt->fetchColumn(), 2);
    
    // Settlement outbox pending
    $stmt = $db->prepare("SELECT COUNT(*) FROM settlement_outbox WHERE status = 'PENDING'");
    $stmt->execute();
    $metrics['pending_settlements_outbox'] = (int)$stmt->fetchColumn();
    
    // Active net positions
    $stmt = $db->prepare("SELECT COUNT(*) FROM net_positions WHERE amount > 0");
    $stmt->execute();
    $metrics['active_net_positions'] = (int)$stmt->fetchColumn();
    
    // Pending fee invoices
    $stmt = $db->prepare("SELECT COUNT(*) FROM fee_invoices WHERE status = 'SENT'");
    $stmt->execute();
    $metrics['outstanding_invoices'] = (int)$stmt->fetchColumn();
    
    // Pending regulatory reports
    $stmt = $db->prepare("SELECT COUNT(*) FROM regulatory_reports WHERE regulator_acknowledged = false");
    $stmt->execute();
    $metrics['pending_regulatory_reports'] = (int)$stmt->fetchColumn();
    
    // Cross-border messages pending
    $stmt = $db->prepare("SELECT COUNT(*) FROM cross_border_messages WHERE status = 'PENDING'");
    $stmt->execute();
    $metrics['pending_cross_border'] = (int)$stmt->fetchColumn();
    
    // Cashout authorizations pending
    $stmt = $db->prepare("SELECT COUNT(*) FROM cashout_authorizations WHERE status = 'PENDING'");
    $stmt->execute();
    $metrics['pending_cashouts'] = (int)$stmt->fetchColumn();
    
    // Identity holds pending
    $stmt = $db->prepare("SELECT COUNT(*) FROM identity_swap_holds WHERE status = 'pending'");
    $stmt->execute();
    $metrics['pending_identity_holds'] = (int)$stmt->fetchColumn();
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = array_fill_keys([
        'today_transactions', 'today_volume', 'active_holds', 'pending_settlements',
        'total_users', 'total_volume', 'pending_settlements_outbox', 'active_net_positions',
        'outstanding_invoices', 'pending_regulatory_reports', 'pending_cross_border',
        'pending_cashouts', 'pending_identity_holds'
    ], 0);
}

// ============================================================
// FORENSIC TRANSACTION QUERY WITH ALL TABLES
// ============================================================

// Get recent transactions with full forensic details
$recentTransactions = [];
try {
    $stmt = $db->prepare("
        SELECT 
            sr.swap_id,
            sr.swap_uuid,
            sr.user_id,
            sr.from_currency,
            sr.to_currency,
            sr.amount,
            sr.status,
            sr.created_at,
            sr.updated_at,
            sr.source_country,
            sr.destination_country,
            sr.forex_rate,
            sr.fee_breakdown,
            sr.metadata,
            sr.retry_count,
            sr.original_swap_ref,
            sr.forex_fee_percent,
            sr.forex_fee_amount,
            sr.total_forex_fee,
            u.full_name as user_name,
            u.email as user_email,
            u.phone as user_phone,
            u.kyc_verified,
            u.aml_score,
            u.verified as user_verified,
            u.national_id,
            u.date_of_birth,
            p.name as participant_name,
            p.type as participant_type,
            p.provider_code,
            sl.fee_amount as ledger_fee,
            sl.status as ledger_status,
            sl.currency_code as ledger_currency,
            sl.request_payload,
            sl.response_payload,
            sl.fee_calculation_details,
            sl.signature_chain,
            ht.status as hold_status,
            ht.amount as hold_amount,
            ht.hold_reference,
            ht.placed_at as hold_placed_at,
            ht.released_at as hold_released_at,
            ht.debited_at as hold_debited_at,
            ht.source_details,
            ht.metadata as hold_metadata,
            sq.status as settlement_status,
            sq.amount as settlement_amount,
            sq.debtor,
            sq.creditor,
            sq.reference as settlement_reference,
            sq.created_at as settlement_created_at,
            sq.updated_at as settlement_updated_at,
            sf.total_amount as fee_total,
            sf.vat_amount as fee_vat,
            sf.status as fee_status,
            sf.split_config,
            sf.collected_at as fee_collected_at,
            sf.currency as fee_currency,
            so.status as outbox_status,
            so.message_type,
            so.message_payload,
            so.retry_count as outbox_retry_count,
            so.sent_at,
            so.acknowledged_at,
            so.error_message,
            so.composite_signature,
            so.is_multi_source,
            np.amount as net_position,
            np.currency_code as net_currency,
            ca.client_phone,
            ca.amount as cashout_amount,
            ca.status as cashout_status,
            ca.cashout_point,
            ca.cashout_provider,
            ca.pin_code,
            ca.code_expiry,
            ca.created_at as cashout_created_at,
            ca.completed_at as cashout_completed_at,
            ca.metadata as cashout_metadata,
            dt.status as deposit_status,
            dt.amount as deposit_amount,
            dt.source_type,
            dt.source_institution as deposit_source,
            dt.destination_institution as deposit_destination,
            dt.source_account,
            dt.destination_account,
            dt.completed_at as deposit_completed_at,
            dt.metadata as deposit_metadata,
            sv.voucher_id,
            sv.amount as voucher_amount,
            sv.status as voucher_status,
            sv.expiry_at as voucher_expiry,
            sv.claimant_phone as voucher_claimant,
            sv.created_at as voucher_created_at,
            sv.code_suffix,
            sv.is_cardless_redemption,
            idh.status as identity_hold_status,
            idh.identity_type,
            idh.identity_value,
            idh.hold_expires_at as identity_expires_at,
            idh.confirmed_by_type,
            idh.confirmed_at as identity_confirmed_at,
            idh.final_destination_type,
            idh.final_transaction_reference,
            cb.status as cross_border_status,
            cb.source_country as cb_source_country,
            cb.destination_country as cb_destination_country,
            cb.exchange_rate as cb_exchange_rate,
            cb.corridor_fee as cb_corridor_fee,
            fi.invoice_uuid,
            fi.fee_type as invoice_fee_type,
            fi.total_amount as invoice_total,
            fi.status as invoice_status,
            fi.paid_at as invoice_paid_at,
            al.action as audit_action,
            al.category as audit_category,
            al.severity as audit_severity,
            al.performed_at as audit_performed_at,
            al.ip_address as audit_ip
        FROM swap_requests sr
        LEFT JOIN users u ON sr.user_id = u.user_id
        LEFT JOIN participants p ON sr.user_id = p.system_user_id
        LEFT JOIN swap_ledgers sl ON sr.swap_id = sl.swap_reference::int
        LEFT JOIN hold_transactions ht ON sr.swap_id = ht.swap_reference::int
        LEFT JOIN settlement_queue sq ON sr.swap_id = sq.reference::int
        LEFT JOIN swap_fee_collections sf ON sr.swap_id = sf.swap_reference::int
        LEFT JOIN settlement_outbox so ON sr.swap_id = so.swap_reference::int
        LEFT JOIN net_positions np ON sr.swap_id = np.id
        LEFT JOIN cashout_authorizations ca ON sr.swap_id = ca.swap_reference::int
        LEFT JOIN deposit_transactions dt ON sr.swap_id = dt.transaction_reference::int
        LEFT JOIN swap_vouchers sv ON sr.swap_id = sv.swap_id
        LEFT JOIN identity_swap_holds idh ON sr.swap_id = idh.swap_reference::int
        LEFT JOIN cross_border_messages cb ON sr.swap_id = cb.swap_reference::int
        LEFT JOIN fee_invoices fi ON sr.swap_id = fi.swap_reference::int
        LEFT JOIN audit_logs al ON sr.swap_id = al.entity_id::int AND al.entity_type = 'swap_request'
        ORDER BY sr.created_at DESC 
        LIMIT 20
    ");
    $stmt->execute();
    $recentTransactions = $stmt->fetchAll();
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Forensic query error: " . $e->getMessage());
    // Fallback to simple query
    try {
        $stmt = $db->prepare("
            SELECT swap_id, user_id, amount, status, created_at 
            FROM swap_requests 
            ORDER BY created_at DESC 
            LIMIT 20
        ");
        $stmt->execute();
        $recentTransactions = $stmt->fetchAll();
    } catch (Throwable $e2) {
        error_log("[ADMIN DASHBOARD] Fallback query error: " . $e2->getMessage());
        $recentTransactions = [];
    }
}

// Get current view
$view = $_GET['view'] ?? 'dashboard';
$transactionId = $_GET['id'] ?? null;

// Transaction detail view
$transactionDetail = null;
if ($transactionId && $view === 'transaction_detail') {
    try {
        $stmt = $db->prepare("
            SELECT 
                sr.*,
                u.full_name as user_name,
                u.email as user_email,
                u.phone as user_phone,
                u.kyc_verified,
                u.aml_score,
                u.verified as user_verified,
                u.national_id,
                u.date_of_birth,
                p.name as participant_name,
                p.type as participant_type,
                p.provider_code,
                p.base_url,
                sl.*,
                ht.*,
                sq.*,
                sf.*,
                so.*,
                np.*,
                ca.*,
                dt.*,
                sv.*,
                idh.*,
                cb.*,
                fi.*,
                al.*
            FROM swap_requests sr
            LEFT JOIN users u ON sr.user_id = u.user_id
            LEFT JOIN participants p ON sr.user_id = p.system_user_id
            LEFT JOIN swap_ledgers sl ON sr.swap_id = sl.swap_reference::int
            LEFT JOIN hold_transactions ht ON sr.swap_id = ht.swap_reference::int
            LEFT JOIN settlement_queue sq ON sr.swap_id = sq.reference::int
            LEFT JOIN swap_fee_collections sf ON sr.swap_id = sf.swap_reference::int
            LEFT JOIN settlement_outbox so ON sr.swap_id = so.swap_reference::int
            LEFT JOIN net_positions np ON sr.swap_id = np.id
            LEFT JOIN cashout_authorizations ca ON sr.swap_id = ca.swap_reference::int
            LEFT JOIN deposit_transactions dt ON sr.swap_id = dt.transaction_reference::int
            LEFT JOIN swap_vouchers sv ON sr.swap_id = sv.swap_id
            LEFT JOIN identity_swap_holds idh ON sr.swap_id = idh.swap_reference::int
            LEFT JOIN cross_border_messages cb ON sr.swap_id = cb.swap_reference::int
            LEFT JOIN fee_invoices fi ON sr.swap_id = fi.swap_reference::int
            LEFT JOIN audit_logs al ON sr.swap_id = al.entity_id::int AND al.entity_type = 'swap_request'
            WHERE sr.swap_id = :id OR sr.swap_uuid = :uuid
        ");
        $stmt->execute([':id' => $transactionId, ':uuid' => $transactionId]);
        $transactionDetail = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] Transaction detail error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · ADMIN DASHBOARD · <?php echo htmlspecialchars((string)$countryCode); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ===== BASE STYLES ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #f7f9fc;
            color: #001B44;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ===== HEADER ===== */
        .admin-header {
            background: #001B44;
            border-bottom: 5px solid #FFDA63;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }
        .logo {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 2px;
        }
        .logo span {
            color: #FFDA63;
            margin-left: 10px;
            font-size: 0.8rem;
        }
        .country-badge {
            padding: 5px 15px;
            background: rgba(255,218,99,0.2);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; color: #FFDA63; }
        .user-role { font-size: 0.7rem; color: #A1B5D8; text-transform: uppercase; }
        .logout-btn {
            padding: 8px 16px;
            background: transparent;
            border: 2px solid #FFDA63;
            color: #FFDA63;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .logout-btn:hover {
            background: #FFDA63;
            color: #001B44;
        }

        /* ===== NAVIGATION ===== */
        .admin-nav {
            background: #fff;
            border-bottom: 2px solid #001B44;
            padding: 0 30px;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            align-items: center;
        }
        .nav-item {
            padding: 15px 0;
            color: #666;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }
        .nav-item:hover { color: #001B44; }
        .nav-item.active {
            color: #001B44;
            border-bottom-color: #FFDA63;
        }
        .back-link {
            padding: 8px 16px;
            background: #001B44;
            color: #fff;
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .back-link:hover {
            background: #FFDA63;
            color: #001B44;
        }

        /* ===== CONTENT ===== */
        .admin-content { flex: 1; padding: 30px; }
        .content-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .content-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #001B44;
            margin-bottom: 5px;
        }
        .content-header .timestamp {
            color: #666;
            font-size: 0.8rem;
        }

        /* ===== METRICS ===== */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            box-shadow: 4px 4px 0 #A1B5D8;
            transition: transform 0.2s;
        }
        .metric-card:hover { transform: translateY(-2px); }
        .metric-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        .metric-value {
            font-size: 2rem;
            font-weight: 600;
            color: #001B44;
            line-height: 1.2;
            word-break: break-word;
        }

        /* ===== CARDS & TABLES ===== */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            overflow: hidden;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #001B44;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .card-badge {
            padding: 3px 10px;
            background: #001B44;
            color: #fff;
            font-size: 0.7rem;
        }

        .table-responsive { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        th {
            background: #001B44;
            color: #fff;
            padding: 12px;
            font-weight: 600;
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }
        tr:hover { background: #f5f5f5; }

        /* ===== STATUS BADGES ===== */
        .status {
            display: inline-block;
            padding: 3px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
        }
        .status-success {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
        }
        .status-failed {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        .status-info {
            background: #cce5ff;
            color: #004085;
            border-color: #b8daff;
        }
        .status-warning {
            background: #ffd8a8;
            color: #7a4a00;
            border-color: #ffb366;
        }

        /* ===== DETAIL SECTIONS ===== */
        .detail-section { margin-bottom: 20px; }
        .detail-section h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #001B44;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #FFDA63;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-item .label {
            font-size: 0.6rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 1px;
        }
        .detail-item .value {
            font-size: 0.9rem;
            font-weight: 500;
            color: #001B44;
            word-break: break-all;
        }

        .forensic-tag {
            display: inline-block;
            padding: 2px 8px;
            background: #2c3e50;
            color: #fff;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 3px;
            margin-left: 5px;
        }

        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 20px 30px;
            font-size: 0.7rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
            margin-top: 30px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .grid-2 { grid-template-columns: 1fr; }
            .admin-nav { padding: 0 15px; gap: 15px; }
            .admin-content { padding: 20px; }
            .admin-header { padding: 15px; }
            .header-left { gap: 15px; }
            .metric-value { font-size: 1.5rem; }
            .detail-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="country-badge"><?php echo htmlspecialchars((string)$countryCode); ?> · <?php echo htmlspecialchars((string)$countryName); ?></div>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars((string)($adminFullName ?: $adminUsername)); ?></div>
                <div class="user-role"><?php echo htmlspecialchars((string)$roleName); ?></div>
            </div>
            <a href="admin_logout.php" class="logout-btn">LOGOUT</a>
        </div>
    </header>

    <nav class="admin-nav">
        <a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">DASHBOARD</a>
        
        <?php if ($hasAccess('review_transactions')): ?>
            <a href="?view=transactions" class="nav-item <?php echo $view === 'transactions' ? 'active' : ''; ?>">TRANSACTIONS</a>
        <?php endif; ?>
        
        <?php if ($hasAccess('audit_logs')): ?>
            <a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">AUDIT LOGS</a>
        <?php endif; ?>
        
        <?php if ($hasAccess('view_reports')): ?>
            <a href="?view=reports" class="nav-item <?php echo $view === 'reports' ? 'active' : ''; ?>">REPORTS</a>
        <?php endif; ?>
        
        <?php if ($adminRoleId === 999): ?>
            <a href="admin_management.php" class="nav-item">ADMINISTRATORS</a>
            <a href="?view=config" class="nav-item <?php echo $view === 'config' ? 'active' : ''; ?>">CONFIGURATION</a>
        <?php endif; ?>
        
        <a href="?view=forensic" class="nav-item <?php echo $view === 'forensic' ? 'active' : ''; ?>">🔍 FORENSIC</a>
    </nav>

    <main class="admin-content">
        <?php if ($view === 'dashboard'): ?>
        <!-- ============================================================ -->
        <!-- DASHBOARD VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>EXECUTIVE DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?> · <?php echo htmlspecialchars((string)$countryName); ?> Time</div>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">TODAY'S TRANSACTIONS</div>
                <div class="metric-value"><?php echo number_format($metrics['today_transactions']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TODAY'S VOLUME</div>
                <div class="metric-value"><?php echo $metrics['today_volume']; ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">ACTIVE HOLDS</div>
                <div class="metric-value"><?php echo number_format($metrics['active_holds']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">PENDING SETTLEMENTS</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_settlements']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TOTAL USERS</div>
                <div class="metric-value"><?php echo number_format($metrics['total_users']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TOTAL VOLUME</div>
                <div class="metric-value"><?php echo $metrics['total_volume']; ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">CROSS BORDER</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_cross_border']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">IDENTITY HOLDS</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_identity_holds']); ?></div>
            </div>
        </div>

        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">PARTICIPANTS</span>
                    <span class="card-badge"><?php echo count($participants); ?> ACTIVE</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><th>Provider</th><th>Type</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php 
                            $count = 0;
                            foreach ($participants as $code => $p): 
                                if ($count++ >= 10) break;
                                $type = $p['type'] ?? $p['category'] ?? 'Unknown';
                                $status = $p['status'] ?? 'ACTIVE';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$code); ?></td>
                                <td><?php echo htmlspecialchars((string)$type); ?></td>
                                <td><span class="status status-success"><?php echo htmlspecialchars((string)$status); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($participants) === 0): ?>
                                <tr><td colspan="3" style="text-align: center;">No participants configured</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">RECENT TRANSACTIONS</span>
                    <span class="card-badge">LAST 20</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Amount</th>
                                <th>From/To</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentTransactions)): ?>
                            <?php foreach ($recentTransactions as $tx): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($tx['swap_id'] ?? 'N/A')); ?></td>
                                <td><?php echo htmlspecialchars((string)($tx['user_name'] ?? $tx['user_id'] ?? 'N/A')); ?></td>
                                <td>
                                    <?php echo htmlspecialchars((string)$currencySymbol); ?> 
                                    <?php echo number_format((float)($tx['amount'] ?? 0), 2); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars((string)($tx['from_currency'] ?? 'BWP')); ?> 
                                    → <?php echo htmlspecialchars((string)($tx['to_currency'] ?? 'BWP')); ?>
                                </td>
                                <td>
                                    <?php 
                                    $status = strtolower($tx['status'] ?? 'pending');
                                    $statusClass = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                    ?>
                                    <span class="status status-<?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars((string)($tx['status'] ?? 'pending')); ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                                <td>
                                    <a href="?view=transaction_detail&id=<?php echo $tx['swap_id'] ?? $tx['swap_uuid'] ?? ''; ?>" 
                                       style="color: #001B44; text-decoration: none; font-weight: 600; font-size: 0.7rem; text-transform: uppercase;">
                                        View →
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; padding: 20px;">No transactions found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">SYSTEM HEALTH</span>
                <span class="card-badge">LIVE</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <div><strong>Country:</strong> <?php echo htmlspecialchars((string)$countryName); ?> (<?php echo htmlspecialchars((string)$countryCode); ?>)</div>
                <div><strong>Environment:</strong> <?php echo htmlspecialchars((string)(getenv('APP_ENV') ?: 'production')); ?></div>
                <div><strong>Database:</strong> <span style="color: green;">✓ Connected</span></div>
                <div><strong>PHP Version:</strong> <?php echo phpversion(); ?></div>
                <div><strong>Server Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></div>
                <div><strong>Admin Role:</strong> <?php echo htmlspecialchars((string)$roleName); ?></div>
            </div>
        </div>

        <?php elseif ($view === 'transaction_detail' && $transactionDetail): ?>
        <!-- ============================================================ -->
        <!-- TRANSACTION DETAIL VIEW - FULL FORENSIC -->
        <!-- ============================================================ -->
        <div class="content-header">
            <div>
                <h1>🔍 Transaction Details <span class="forensic-tag">FORENSIC MODE</span></h1>
                <div class="timestamp">Transaction #<?php echo htmlspecialchars((string)($transactionDetail['swap_id'] ?? $transactionDetail['swap_uuid'] ?? 'N/A')); ?></div>
            </div>
            <div>
                <a href="?view=transactions" class="back-link">← Back to Transactions</a>
                <a href="?view=dashboard" class="back-link" style="margin-left: 10px;">← Dashboard</a>
            </div>
        </div>

        <!-- Status Summary -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
            <div class="metric-card">
                <div class="metric-label">Status</div>
                <div class="metric-value" style="font-size: 1.5rem;">
                    <span class="status status-<?php 
                        $status = strtolower($transactionDetail['status'] ?? 'pending');
                        echo $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                    ?>">
                        <?php echo htmlspecialchars((string)($transactionDetail['status'] ?? 'pending')); ?>
                    </span>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Amount</div>
                <div class="metric-value" style="font-size: 1.5rem;">
                    <?php echo htmlspecialchars((string)$currencySymbol); ?> 
                    <?php echo number_format((float)($transactionDetail['amount'] ?? 0), 2); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">User</div>
                <div class="metric-value" style="font-size: 1rem;">
                    <?php echo htmlspecialchars((string)($transactionDetail['user_name'] ?? $transactionDetail['user_id'] ?? 'N/A')); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Created</div>
                <div class="metric-value" style="font-size: 1rem;">
                    <?php echo date('Y-m-d H:i', strtotime($transactionDetail['created_at'] ?? 'now')); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Retry Count</div>
                <div class="metric-value" style="font-size: 1.5rem;">
                    <?php echo htmlspecialchars((string)($transactionDetail['retry_count'] ?? '0')); ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Forex Fee</div>
                <div class="metric-value" style="font-size: 1.2rem;">
                    <?php echo htmlspecialchars((string)$currencySymbol); ?> 
                    <?php echo number_format((float)($transactionDetail['forex_fee_amount'] ?? 0), 2); ?>
                </div>
            </div>
        </div>

        <!-- Main Details -->
        <div class="detail-section">
            <h3>📋 Transaction Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Swap ID</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['swap_id'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Swap UUID</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['swap_uuid'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $status = strtolower($transactionDetail['status'] ?? 'pending');
                            echo $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['status'] ?? 'pending')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Amount</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['amount'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">From Currency</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['from_currency'] ?? 'BWP')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">To Currency</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['to_currency'] ?? 'BWP')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Forex Rate</span>
                    <span class="value"><?php echo number_format((float)($transactionDetail['forex_rate'] ?? 1), 4); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Forex Fee %</span>
                    <span class="value"><?php echo number_format((float)($transactionDetail['forex_fee_percent'] ?? 0), 2); ?>%</span>
                </div>
                <div class="detail-item">
                    <span class="label">Source Country</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['source_country'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Destination Country</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['destination_country'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Created At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['created_at'] ?? 'now')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Updated At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['updated_at'] ?? 'now')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Original Swap Ref</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['original_swap_ref'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Retry Count</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['retry_count'] ?? '0')); ?></span>
                </div>
            </div>
        </div>

        <!-- User Details -->
        <?php if (!empty($transactionDetail['user_id'])): ?>
        <div class="detail-section">
            <h3>👤 User Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">User ID</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['user_id'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Full Name</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['user_name'] ?? $transactionDetail['full_name'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Email</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['user_email'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Phone</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['user_phone'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">National ID</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['national_id'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Date of Birth</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['date_of_birth'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">KYC Verified</span>
                    <span class="value">
                        <span class="status status-<?php echo ($transactionDetail['kyc_verified'] ?? false) ? 'success' : 'pending'; ?>">
                            <?php echo ($transactionDetail['kyc_verified'] ?? false) ? 'Verified' : 'Pending'; ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">AML Score</span>
                    <span class="value"><?php echo number_format((float)($transactionDetail['aml_score'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">User Verified</span>
                    <span class="value">
                        <span class="status status-<?php echo ($transactionDetail['user_verified'] ?? false) ? 'success' : 'pending'; ?>">
                            <?php echo ($transactionDetail['user_verified'] ?? false) ? 'Verified' : 'Pending'; ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Hold Details -->
        <?php if (!empty($transactionDetail['hold_status'])): ?>
        <div class="detail-section">
            <h3>🔒 Hold Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Hold Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $holdStatus = strtolower($transactionDetail['hold_status'] ?? '');
                            echo $holdStatus === 'active' || $holdStatus === 'held' ? 'success' : 'pending';
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['hold_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Hold Reference</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['hold_reference'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Hold Amount</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['hold_amount'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Placed At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['hold_placed_at'] ?? 'now')); ?></span>
                </div>
                <?php if (!empty($transactionDetail['hold_released_at'])): ?>
                <div class="detail-item">
                    <span class="label">Released At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['hold_released_at'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transactionDetail['hold_debited_at'])): ?>
                <div class="detail-item">
                    <span class="label">Debited At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['hold_debited_at'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transactionDetail['source_details'])): ?>
                <div class="detail-item">
                    <span class="label">Source Details</span>
                    <span class="value" style="font-size: 0.7rem; word-break: break-all;">
                        <?php echo htmlspecialchars((string)json_encode(json_decode($transactionDetail['source_details'] ?? '{}', true), JSON_PRETTY_PRINT)); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Fee Details -->
        <?php if (!empty($transactionDetail['fee_total']) || !empty($transactionDetail['ledger_fee'])): ?>
        <div class="detail-section">
            <h3>💰 Fee Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Total Fee</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['fee_total'] ?? $transactionDetail['ledger_fee'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">VAT Amount</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['fee_vat'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Fee Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $feeStatus = strtolower($transactionDetail['fee_status'] ?? $transactionDetail['ledger_status'] ?? '');
                            echo $feeStatus === 'collected' || $feeStatus === 'success' ? 'success' : 'pending';
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['fee_status'] ?? $transactionDetail['ledger_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <?php if (!empty($transactionDetail['fee_collected_at'])): ?>
                <div class="detail-item">
                    <span class="label">Collected At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['fee_collected_at'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transactionDetail['fee_calculation_details'])): ?>
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <span class="label">Fee Calculation Details</span>
                    <span class="value" style="font-size: 0.7rem; word-break: break-all;">
                        <?php echo htmlspecialchars((string)json_encode(json_decode($transactionDetail['fee_calculation_details'] ?? '{}', true), JSON_PRETTY_PRINT)); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Settlement Details -->
        <?php if (!empty($transactionDetail['settlement_status'])): ?>
        <div class="detail-section">
            <h3>📤 Settlement Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Settlement Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $settlementStatus = strtolower($transactionDetail['settlement_status'] ?? '');
                            echo $settlementStatus === 'completed' ? 'success' : ($settlementStatus === 'pending' ? 'pending' : 'info');
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['settlement_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Debtor</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['debtor'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Creditor</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['creditor'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Settlement Amount</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['settlement_amount'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Settlement Reference</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['settlement_reference'] ?? 'N/A')); ?></span>
                </div>
                <?php if (!empty($transactionDetail['outbox_status'])): ?>
                <div class="detail-item">
                    <span class="label">Outbox Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $outboxStatus = strtolower($transactionDetail['outbox_status'] ?? '');
                            echo $outboxStatus === 'sent' ? 'success' : 'pending';
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['outbox_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Message Type</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['message_type'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Outbox Retry Count</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['outbox_retry_count'] ?? '0')); ?></span>
                </div>
                <?php if (!empty($transactionDetail['composite_signature'])): ?>
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <span class="label">Composite Signature</span>
                    <span class="value" style="font-size: 0.7rem; word-break: break-all;">
                        <?php echo htmlspecialchars((string)substr($transactionDetail['composite_signature'] ?? '', 0, 200)) . '...'; ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($transactionDetail['net_position'])): ?>
                <div class="detail-item">
                    <span class="label">Net Position</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['net_position'] ?? 0), 2); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transactionDetail['error_message'])): ?>
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <span class="label">Error Message</span>
                    <span class="value" style="color: #dc3545;"><?php echo htmlspecialchars((string)($transactionDetail['error_message'] ?? 'N/A')); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Identity Swap Details -->
        <?php if (!empty($transactionDetail['identity_hold_status'])): ?>
        <div class="detail-section">
            <h3>🆔 Identity Swap Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Identity Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $idStatus = strtolower($transactionDetail['identity_hold_status'] ?? '');
                            echo $idStatus === 'completed' ? 'success' : ($idStatus === 'pending' ? 'pending' : 'info');
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['identity_hold_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Identity Type</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['identity_type'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Identity Value</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['identity_value'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Expires At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['identity_expires_at'] ?? 'now')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Confirmed By</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['confirmed_by_type'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Confirmed At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['identity_confirmed_at'] ?? 'now')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Final Destination</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['final_destination_type'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Final Transaction</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['final_transaction_reference'] ?? 'N/A')); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cross Border Details -->
        <?php if (!empty($transactionDetail['cross_border_status'])): ?>
        <div class="detail-section">
            <h3>🌍 Cross Border Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Cross Border Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $cbStatus = strtolower($transactionDetail['cross_border_status'] ?? '');
                            echo $cbStatus === 'completed' ? 'success' : 'pending';
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['cross_border_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Source Country</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['cb_source_country'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Destination Country</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['cb_destination_country'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Exchange Rate</span>
                    <span class="value"><?php echo number_format((float)($transactionDetail['cb_exchange_rate'] ?? 1), 4); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Corridor Fee</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['cb_corridor_fee'] ?? 0), 2); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cashout Details -->
        <?php if (!empty($transactionDetail['cashout_status'])): ?>
        <div class="detail-section">
            <h3>🏧 Cashout Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Cashout Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $cashoutStatus = strtolower($transactionDetail['cashout_status'] ?? '');
                            echo $cashoutStatus === 'completed' ? 'success' : 'pending';
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['cashout_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Client Phone</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['client_phone'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Cashout Amount</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['cashout_amount'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Cashout Point</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['cashout_point'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Provider</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['cashout_provider'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">PIN Code</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['pin_code'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Code Expiry</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['code_expiry'] ?? 'now')); ?></span>
                </div>
                <?php if (!empty($transactionDetail['cashout_completed_at'])): ?>
                <div class="detail-item">
                    <span class="label">Completed At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['cashout_completed_at'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Deposit Details -->
        <?php if (!empty($transactionDetail['deposit_status'])): ?>
        <div class="detail-section">
            <h3>💰 Deposit Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Deposit Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $depositStatus = strtolower($transactionDetail['deposit_status'] ?? '');
                            echo $depositStatus === 'completed' ? 'success' : 'pending';
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['deposit_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Deposit Amount</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['deposit_amount'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Source Type</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['source_type'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Source Institution</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['deposit_source'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Source Account</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['source_account'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Destination Institution</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['deposit_destination'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Destination Account</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['destination_account'] ?? 'N/A')); ?></span>
                </div>
                <?php if (!empty($transactionDetail['deposit_completed_at'])): ?>
                <div class="detail-item">
                    <span class="label">Completed At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['deposit_completed_at'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Voucher Details -->
        <?php if (!empty($transactionDetail['voucher_status'])): ?>
        <div class="detail-section">
            <h3>🎫 Voucher Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Voucher ID</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['voucher_id'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Voucher Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $voucherStatus = strtolower($transactionDetail['voucher_status'] ?? '');
                            echo $voucherStatus === 'active' ? 'success' : 'pending';
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['voucher_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Voucher Amount</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['voucher_amount'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Expiry</span>
                    <span class="value"><?php echo date('Y-m-d H:i', strtotime($transactionDetail['voucher_expiry'] ?? 'now')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Claimant</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['voucher_claimant'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Code Suffix</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['code_suffix'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Cardless Redemption</span>
                    <span class="value">
                        <span class="status status-<?php echo ($transactionDetail['is_cardless_redemption'] ?? false) ? 'success' : 'pending'; ?>">
                            <?php echo ($transactionDetail['is_cardless_redemption'] ?? false) ? 'Yes' : 'No'; ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Invoice Details -->
        <?php if (!empty($transactionDetail['invoice_uuid'])): ?>
        <div class="detail-section">
            <h3>📄 Invoice Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Invoice UUID</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['invoice_uuid'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Fee Type</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['invoice_fee_type'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Invoice Total</span>
                    <span class="value"><?php echo htmlspecialchars((string)$currencySymbol); ?> <?php echo number_format((float)($transactionDetail['invoice_total'] ?? 0), 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Invoice Status</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $invoiceStatus = strtolower($transactionDetail['invoice_status'] ?? '');
                            echo $invoiceStatus === 'paid' ? 'success' : 'pending';
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['invoice_status'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <?php if (!empty($transactionDetail['invoice_paid_at'])): ?>
                <div class="detail-item">
                    <span class="label">Paid At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['invoice_paid_at'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Audit Trail -->
        <?php if (!empty($transactionDetail['audit_action'])): ?>
        <div class="detail-section">
            <h3>📋 Audit Trail</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="label">Action</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['audit_action'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Category</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['audit_category'] ?? 'N/A')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">Severity</span>
                    <span class="value">
                        <span class="status status-<?php 
                            $severity = strtolower($transactionDetail['audit_severity'] ?? '');
                            echo $severity === 'critical' || $severity === 'error' ? 'failed' : 'info';
                        ?>">
                            <?php echo htmlspecialchars((string)($transactionDetail['audit_severity'] ?? 'N/A')); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="label">Performed At</span>
                    <span class="value"><?php echo date('Y-m-d H:i:s', strtotime($transactionDetail['audit_performed_at'] ?? 'now')); ?></span>
                </div>
                <div class="detail-item">
                    <span class="label">IP Address</span>
                    <span class="value"><?php echo htmlspecialchars((string)($transactionDetail['audit_ip'] ?? 'N/A')); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- REPORTS VIEW -->
        <!-- ============================================================ -->
        <?php elseif ($view === 'reports'): ?>
        <div class="content-header">
            <h1>REGULATORY REPORTS</h1>
            <div class="timestamp">Bank of Botswana Compliance Reports</div>
        </div>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Daily Settlement Report</span>
                </div>
                <p>End-of-day net positions and settlement amounts</p>
                <p style="margin-top: 15px;">
                    <a href="reports/daily_reconciliations.php?country=<?php echo htmlspecialchars((string)$countryCode); ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Transaction Audit Log</span>
                </div>
                <p>7-year audit trail of all swap transactions</p>
                <p style="margin-top: 15px;">
                    <a href="reports/audit_trails.php?country=<?php echo htmlspecialchars((string)$countryCode); ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Suspicious Activity Report</span>
                </div>
                <p>AML/KYC compliance and fraud monitoring</p>
                <p style="margin-top: 15px;">
                    <a href="reports/suspicious.php?country=<?php echo htmlspecialchars((string)$countryCode); ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Monthly Reconciliation</span>
                </div>
                <p>Monthly financial reconciliation report</p>
                <p style="margin-top: 15px;">
                    <a href="reports/monthly_reconciliations.php?country=<?php echo htmlspecialchars((string)$countryCode); ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Cross Border Report</span>
                </div>
                <p>International settlement and corridor activity</p>
                <p style="margin-top: 15px;">
                    <a href="reports/cross_border.php?country=<?php echo htmlspecialchars((string)$countryCode); ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Forensic Audit Report</span>
                </div>
                <p>Complete transactional forensic analysis</p>
                <p style="margin-top: 15px;">
                    <a href="reports/forensic_audit.php?country=<?php echo htmlspecialchars((string)$countryCode); ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- CONFIG VIEW -->
        <!-- ============================================================ -->
        <?php elseif ($view === 'config' && $adminRoleId === 999): ?>
        <div class="content-header">
            <h1>SYSTEM CONFIGURATION</h1>
            <div class="timestamp">Configuration Management</div>
        </div>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Country Configuration</span>
                </div>
                <p>Current Country: <strong><?php echo htmlspecialchars((string)$countryName); ?></strong></p>
                <p>Currency: <strong><?php echo htmlspecialchars((string)$currencySymbol); ?></strong></p>
                <p>Timezone: <strong>Africa/Gaborone</strong></p>
                <p style="margin-top: 15px;">
                    <a href="../../src/Core/Config/Countries/<?php echo htmlspecialchars((string)$countryCode); ?>/config.php" style="color: #001B44;">Edit Config →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Database Status</span>
                </div>
                <p>Connection: <span style="color: green;">Active</span></p>
                <p>Type: PostgreSQL</p>
                <p>Driver: PDO_pgsql</p>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- TRANSACTIONS VIEW -->
        <!-- ============================================================ -->
        <?php elseif ($view === 'transactions' && $hasAccess('review_transactions')): ?>
        <div class="content-header">
            <h1>TRANSACTION MANAGEMENT</h1>
            <div class="timestamp">Monitor and Review Transactions</div>
            <a href="?view=dashboard" class="back-link">← Back to Dashboard</a>
        </div>
        <div class="card">
            <div class="card-header">
                <span class="card-title">All Transactions</span>
                <span class="card-badge">SWAP REQUESTS</span>
            </div>
            <div class="table-responsive">
                <?php
                try {
                    $txStmt = $db->query("
                        SELECT 
                            sr.swap_id,
                            sr.swap_uuid,
                            sr.user_id,
                            sr.from_currency,
                            sr.to_currency,
                            sr.amount,
                            sr.status,
                            sr.created_at,
                            sr.retry_count,
                            u.full_name as user_name,
                            u.phone as user_phone
                        FROM swap_requests sr
                        LEFT JOIN users u ON sr.user_id = u.user_id
                        ORDER BY sr.created_at DESC 
                        LIMIT 50
                    ");
                    $allTransactions = $txStmt->fetchAll();
                } catch (Throwable $e) {
                    error_log("[ADMIN DASHBOARD] Transactions query error: " . $e->getMessage());
                    $allTransactions = [];
                }
                ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>From/To</th>
                            <th>Status</th>
                            <th>Retries</th>
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($allTransactions)): ?>
                        <?php foreach ($allTransactions as $tx): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($tx['swap_id'] ?? $tx['id'] ?? 'N/A')); ?></td>
                            <td>
                                <?php echo htmlspecialchars((string)($tx['user_name'] ?? $tx['user_id'] ?? 'N/A')); ?>
                                <?php if (!empty($tx['user_phone'])): ?>
                                <br><small style="color: #999;"><?php echo htmlspecialchars((string)$tx['user_phone']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string)$currencySymbol); ?> 
                                <?php echo number_format((float)($tx['amount'] ?? 0), 2); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string)($tx['from_currency'] ?? 'BWP')); ?> 
                                → <?php echo htmlspecialchars((string)($tx['to_currency'] ?? 'BWP')); ?>
                            </td>
                            <td>
                                <?php 
                                $status = strtolower($tx['status'] ?? 'pending');
                                $statusClass = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $statusClass; ?>">
                                    <?php echo htmlspecialchars((string)($tx['status'] ?? 'pending')); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars((string)($tx['retry_count'] ?? '0')); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                            <td>
                                <a href="?view=transaction_detail&id=<?php echo $tx['swap_id'] ?? $tx['swap_uuid'] ?? ''; ?>" 
                                   style="color: #001B44; text-decoration: none; font-weight: 600; font-size: 0.7rem; text-transform: uppercase;">
                                    🔍 View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px; color: #999;">No transactions found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- AUDIT VIEW -->
        <!-- ============================================================ -->
        <?php elseif ($view === 'audit' && $hasAccess('audit_logs')): ?>
        <div class="content-header">
            <h1>AUDIT LOGS</h1>
            <div class="timestamp">System Audit Trail</div>
            <a href="?view=dashboard" class="back-link">← Back to Dashboard</a>
        </div>
        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Activities</span>
                <span class="card-badge">ADMIN ACTIONS</span>
            </div>
            <div class="table-responsive">
                <?php
                try {
                    $auditStmt = $db->query("
                        SELECT 
                            aa.*,
                            a.username as admin_username,
                            a.full_name as admin_full_name
                        FROM admin_actions aa
                        LEFT JOIN admins a ON aa.admin_id = a.admin_id
                        ORDER BY aa.created_at DESC 
                        LIMIT 50
                    ");
                    $auditLogs = $auditStmt->fetchAll();
                } catch (Throwable $e) {
                    error_log("[ADMIN DASHBOARD] Audit query error: " . $e->getMessage());
                    $auditLogs = [];
                }
                ?>
                <table>
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Status</th>
                            <th>Admin</th>
                            <th>IP</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($auditLogs)): ?>
                        <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($log['action_type'] ?? $log['action'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['entity_type'] ?? $log['entity'] ?? 'N/A')); ?></td>
                            <td>
                                <span class="status status-<?php 
                                    $status = strtolower($log['status'] ?? '');
                                    echo $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>">
                                    <?php echo htmlspecialchars((string)($log['status'] ?? 'N/A')); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars((string)($log['admin_full_name'] ?? $log['admin_username'] ?? $log['admin_id'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['ip_address'] ?? 'N/A')); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($log['created_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px; color: #999;">No audit logs found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- FORENSIC VIEW -->
        <!-- ============================================================ -->
        <?php elseif ($view === 'forensic'): ?>
        <div class="content-header">
            <h1>🔍 FORENSIC ANALYSIS</h1>
            <div class="timestamp">Complete transactional forensic view · <?php echo date('Y-m-d H:i:s'); ?></div>
            <a href="?view=dashboard" class="back-link">← Back to Dashboard</a>
        </div>

        <!-- Forensic Summary -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
            <div class="metric-card">
                <div class="metric-label">Total Transactions</div>
                <div class="metric-value"><?php echo number_format(count($recentTransactions)); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Unique Users</div>
                <div class="metric-value"><?php echo number_format(count(array_unique(array_column($recentTransactions, 'user_id')))); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Holds Active</div>
                <div class="metric-value"><?php echo number_format($metrics['active_holds']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Settlement Pending</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_settlements']); ?></div>
            </div>
        </div>

        <!-- Full Forensic Table -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🔍 All Transactions (Forensic View)</span>
                <span class="card-badge">FULL DETAILS</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Swap ID</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Hold</th>
                            <th>Settlement</th>
                            <th>Fee</th>
                            <th>Retry</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentTransactions)): ?>
                        <?php foreach ($recentTransactions as $tx): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($tx['swap_id'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($tx['user_name'] ?? $tx['user_id'] ?? 'N/A')); ?></td>
                            <td>
                                <?php echo htmlspecialchars((string)$currencySymbol); ?> 
                                <?php echo number_format((float)($tx['amount'] ?? 0), 2); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string)($tx['from_currency'] ?? 'BWP')); ?> 
                                → <?php echo htmlspecialchars((string)($tx['to_currency'] ?? 'BWP')); ?>
                            </td>
                            <td>
                                <?php 
                                $status = strtolower($tx['status'] ?? 'pending');
                                $statusClass = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $statusClass; ?>">
                                    <?php echo htmlspecialchars((string)($tx['status'] ?? 'pending')); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($tx['hold_status'])): ?>
                                <span class="status status-<?php echo strtolower($tx['hold_status']) === 'active' ? 'success' : 'pending'; ?>">
                                    <?php echo htmlspecialchars((string)($tx['hold_status'])); ?>
                                </span>
                                <?php else: ?>
                                <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($tx['settlement_status'])): ?>
                                <span class="status status-<?php echo strtolower($tx['settlement_status']) === 'completed' ? 'success' : 'pending'; ?>">
                                    <?php echo htmlspecialchars((string)($tx['settlement_status'])); ?>
                                </span>
                                <?php else: ?>
                                <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($tx['fee_total']) || !empty($tx['ledger_fee'])): ?>
                                <?php echo htmlspecialchars((string)$currencySymbol); ?> 
                                <?php echo number_format((float)($tx['fee_total'] ?? $tx['ledger_fee'] ?? 0), 2); ?>
                                <?php else: ?>
                                <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars((string)($tx['retry_count'] ?? '0')); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                            <td>
                                <a href="?view=transaction_detail&id=<?php echo $tx['swap_id'] ?? $tx['swap_uuid'] ?? ''; ?>" 
                                   style="color: #001B44; text-decoration: none; font-weight: 600; font-size: 0.7rem; text-transform: uppercase;">
                                    🔍 Detail
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 30px; color: #999;">No transactions found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <!-- ============================================================ -->
        <!-- DEFAULT / MODULE UNDER DEVELOPMENT -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1><?php echo htmlspecialchars((string)ucfirst($view)); ?></h1>
            <div class="timestamp">Module under development</div>
            <a href="?view=dashboard" class="back-link">← Back to Dashboard</a>
        </div>
        <div class="card">
            <p>This module is currently being developed. Please check back later.</p>
        </div>
        <?php endif; ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · <?php echo htmlspecialchars((string)$countryName); ?> · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant · Forensic Audit Ready</p>
    </footer>
</body>
</html>
