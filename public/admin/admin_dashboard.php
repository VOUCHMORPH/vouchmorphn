<?php
/**
 * admin_dashboard.php - VouchMorph Admin Dashboard
 * Works with available tables only - no missing tables/columns required
 * 
 * Available tables: users, admins, swap_requests, swap_ledgers, hold_transactions,
 * settlement_queue, settlement_outbox, swap_fee_collections, fee_invoices, 
 * net_positions, cashout_authorizations, deposit_transactions, swap_vouchers,
 * identity_swap_holds, cross_border_messages, audit_logs, admin_actions,
 * organization_audit_logs, regulatory_reports, participants, departments,
 * organization_users
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Define project root
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load required classes
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

// Get admin info
$adminId = SessionManager::getAdminId();
$adminUsername = SessionManager::getAdminUsername();
$adminFullName = SessionManager::get('admin_full_name');
$adminRoleId = SessionManager::getAdminRoleId();
$adminCountry = SessionManager::getAdminCountry();

// Role names
$roleNames = [
    999 => 'Super Admin',
    3 => 'Regulator',
    4 => 'Compliance Officer',
    5 => 'Auditor'
];
$roleName = $roleNames[$adminRoleId] ?? 'Administrator';

// Database connection
try {
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->query("SELECT 1");
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] DB Error: " . $e->getMessage());
    die("Database connection failed. Please check configuration.");
}

// Get view
$view = $_GET['view'] ?? 'dashboard';

// ============================================================
// DASHBOARD METRICS - Using only available tables
// ============================================================
$metrics = [];

try {
    // Total users
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL OR deleted_at IS NOT NULL");
    $metrics['total_users'] = (int)$stmt->fetchColumn();
    
    // Total admins
    $stmt = $db->query("SELECT COUNT(*) FROM admins WHERE deleted_at IS NULL");
    $metrics['total_admins'] = (int)$stmt->fetchColumn();
    
    // Total swaps
    $stmt = $db->query("SELECT COUNT(*) FROM swap_requests");
    $metrics['total_swaps'] = (int)$stmt->fetchColumn();
    
    // Completed swaps
    $stmt = $db->query("SELECT COUNT(*) FROM swap_requests WHERE status = 'COMPLETED' OR status = 'success'");
    $metrics['completed_swaps'] = (int)$stmt->fetchColumn();
    
    // Pending swaps
    $stmt = $db->query("SELECT COUNT(*) FROM swap_requests WHERE status = 'PENDING' OR status = 'pending'");
    $metrics['pending_swaps'] = (int)$stmt->fetchColumn();
    
    // Failed swaps
    $stmt = $db->query("SELECT COUNT(*) FROM swap_requests WHERE status = 'FAILED' OR status = 'failed'");
    $metrics['failed_swaps'] = (int)$stmt->fetchColumn();
    
    // Total volume
    $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) FROM swap_requests");
    $metrics['total_volume'] = (float)$stmt->fetchColumn();
    
    // Active holds
    $stmt = $db->query("SELECT COUNT(*) FROM hold_transactions WHERE status = 'ACTIVE' OR status = 'HELD'");
    $metrics['active_holds'] = (int)$stmt->fetchColumn();
    
    // Pending settlements
    $stmt = $db->query("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'");
    $metrics['pending_settlements'] = (int)$stmt->fetchColumn();
    
    // Completed settlements
    $stmt = $db->query("SELECT COUNT(*) FROM settlement_queue WHERE status = 'COMPLETED'");
    $metrics['completed_settlements'] = (int)$stmt->fetchColumn();
    
    // Pending cashouts
    $stmt = $db->query("SELECT COUNT(*) FROM cashout_authorizations WHERE status = 'PENDING'");
    $metrics['pending_cashouts'] = (int)$stmt->fetchColumn();
    
    // Completed cashouts
    $stmt = $db->query("SELECT COUNT(*) FROM cashout_authorizations WHERE status = 'COMPLETED'");
    $metrics['completed_cashouts'] = (int)$stmt->fetchColumn();
    
    // Pending identity holds
    $stmt = $db->query("SELECT COUNT(*) FROM identity_swap_holds WHERE status = 'pending'");
    $metrics['pending_identity_holds'] = (int)$stmt->fetchColumn();
    
    // Total fee invoices
    $stmt = $db->query("SELECT COUNT(*) FROM fee_invoices");
    $metrics['total_invoices'] = (int)$stmt->fetchColumn();
    
    // Unpaid invoices
    $stmt = $db->query("SELECT COUNT(*) FROM fee_invoices WHERE status = 'SENT'");
    $metrics['unpaid_invoices'] = (int)$stmt->fetchColumn();
    
    // Participants count
    $stmt = $db->query("SELECT COUNT(*) FROM participants");
    $metrics['total_participants'] = (int)$stmt->fetchColumn();
    
    // Today's date
    $metrics['today'] = date('Y-m-d');
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = [
        'total_users' => 0,
        'total_admins' => 0,
        'total_swaps' => 0,
        'completed_swaps' => 0,
        'pending_swaps' => 0,
        'failed_swaps' => 0,
        'total_volume' => 0,
        'active_holds' => 0,
        'pending_settlements' => 0,
        'completed_settlements' => 0,
        'pending_cashouts' => 0,
        'completed_cashouts' => 0,
        'pending_identity_holds' => 0,
        'total_invoices' => 0,
        'unpaid_invoices' => 0,
        'total_participants' => 0,
        'today' => date('Y-m-d')
    ];
}

// ============================================================
// RECENT TRANSACTIONS
// ============================================================
$recentTransactions = [];
try {
    $stmt = $db->query("
        SELECT 
            sr.swap_id,
            sr.swap_uuid,
            sr.amount,
            sr.status,
            sr.created_at,
            sr.from_currency,
            sr.to_currency,
            sr.source_country,
            sr.destination_country,
            u.username,
            u.phone,
            u.full_name as user_full_name
        FROM swap_requests sr
        LEFT JOIN users u ON sr.user_id = u.user_id
        ORDER BY sr.created_at DESC
        LIMIT 20
    ");
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Recent transactions error: " . $e->getMessage());
    $recentTransactions = [];
}

// ============================================================
// RECENT CASHOUTS
// ============================================================
$recentCashouts = [];
try {
    $stmt = $db->query("
        SELECT 
            auth_id,
            swap_reference,
            client_phone,
            source_institution,
            amount,
            currency,
            status,
            cashout_point,
            cashout_provider,
            created_at,
            completed_at
        FROM cashout_authorizations
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $recentCashouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Cashouts error: " . $e->getMessage());
}

// ============================================================
// RECENT SETTLEMENTS
// ============================================================
$recentSettlements = [];
try {
    $stmt = $db->query("
        SELECT 
            id,
            reference,
            debtor,
            creditor,
            amount,
            currency,
            status,
            created_at,
            updated_at
        FROM settlement_queue
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $recentSettlements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Settlements error: " . $e->getMessage());
}

// ============================================================
// RECENT IDENTITY SWAPS
// ============================================================
$recentIdentitySwaps = [];
try {
    $stmt = $db->query("
        SELECT 
            hold_id,
            swap_reference,
            source_institution,
            source_identifier,
            amount,
            currency,
            identity_type,
            identity_value,
            status,
            created_at,
            confirmed_at,
            completed_at
        FROM identity_swap_holds
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $recentIdentitySwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Identity swaps error: " . $e->getMessage());
}

// ============================================================
// RECENT INVOICES
// ============================================================
$recentInvoices = [];
try {
    $stmt = $db->query("
        SELECT 
            invoice_uuid,
            swap_reference,
            source_institution,
            fee_type,
            fee_amount,
            currency,
            total_amount,
            status,
            created_at,
            paid_at
        FROM fee_invoices
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Invoices error: " . $e->getMessage());
}

// ============================================================
// ADMIN ACTIONS
// ============================================================
$recentAdminActions = [];
try {
    $stmt = $db->query("
        SELECT 
            id,
            admin_id,
            action_type,
            entity_type,
            entity_id,
            status,
            ip_address,
            created_at
        FROM admin_actions
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $recentAdminActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Admin actions error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #f7f9fc;
            color: #001B44;
            min-height: 100vh;
        }
        
        /* Header */
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
        .logo span { color: #FFDA63; margin-left: 10px; font-size: 0.8rem; }
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
        .logout-btn:hover { background: #FFDA63; color: #001B44; }
        
        /* Navigation */
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
        
        /* Content */
        .admin-content { padding: 30px; max-width: 1400px; margin: 0 auto; }
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
        }
        .content-header .timestamp {
            color: #666;
            font-size: 0.8rem;
        }
        
        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        
        /* Cards */
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
            font-size: 0.8rem;
        }
        th {
            background: #001B44;
            color: #fff;
            padding: 10px 12px;
            font-weight: 600;
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #ddd;
            font-size: 0.8rem;
        }
        tr:hover { background: #f5f5f5; }
        
        .status {
            display: inline-block;
            padding: 2px 10px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
            border-radius: 3px;
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
        
        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 20px 30px;
            font-size: 0.7rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
            margin-top: 30px;
        }
        
        @media (max-width: 768px) {
            .grid-2 { grid-template-columns: 1fr; }
            .admin-nav { padding: 0 15px; gap: 15px; }
            .admin-content { padding: 20px; }
            .admin-header { padding: 15px; }
            .metric-value { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="country-badge">BOTSWANA</div>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($adminFullName ?: $adminUsername); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($roleName); ?></div>
            </div>
            <a href="admin_logout.php" class="logout-btn">LOGOUT</a>
        </div>
    </header>

    <nav class="admin-nav">
        <a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">DASHBOARD</a>
        <a href="?view=transactions" class="nav-item <?php echo $view === 'transactions' ? 'active' : ''; ?>">TRANSACTIONS</a>
        <a href="?view=cashouts" class="nav-item <?php echo $view === 'cashouts' ? 'active' : ''; ?>">CASHOUTS</a>
        <a href="?view=settlements" class="nav-item <?php echo $view === 'settlements' ? 'active' : ''; ?>">SETTLEMENTS</a>
        <a href="?view=identity" class="nav-item <?php echo $view === 'identity' ? 'active' : ''; ?>">IDENTITY</a>
        <a href="?view=invoices" class="nav-item <?php echo $view === 'invoices' ? 'active' : ''; ?>">INVOICES</a>
        <a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">AUDIT</a>
        <?php if ($adminRoleId === 999): ?>
        <a href="admin_management.php" class="nav-item">ADMINISTRATORS</a>
        <?php endif; ?>
    </nav>

    <main class="admin-content">
        <?php if ($view === 'dashboard'): ?>
        <!-- ============================================================ -->
        <!-- DASHBOARD VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>EXECUTIVE DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?> · Botswana Time</div>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total Users</div>
                <div class="metric-value"><?php echo number_format($metrics['total_users']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['total_swaps']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Completed Swaps</div>
                <div class="metric-value"><?php echo number_format($metrics['completed_swaps']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Volume (BWP)</div>
                <div class="metric-value"><?php echo number_format($metrics['total_volume'], 0); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Active Holds</div>
                <div class="metric-value"><?php echo number_format($metrics['active_holds']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Pending Settlements</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_settlements']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Pending Cashouts</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_cashouts']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Identity Holds</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_identity_holds']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Unpaid Invoices</div>
                <div class="metric-value"><?php echo number_format($metrics['unpaid_invoices']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Participants</div>
                <div class="metric-value"><?php echo number_format($metrics['total_participants']); ?></div>
            </div>
        </div>

        <div class="grid-2">
            <!-- Recent Transactions -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Recent Transactions</span>
                    <span class="card-badge">Last 20</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Amount</th>
                                <th>From/To</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentTransactions)): ?>
                            <?php foreach ($recentTransactions as $tx): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($tx['swap_uuid'] ?? $tx['swap_id'] ?? 'N/A', 0, 8)); ?></td>
                                <td><?php echo number_format((float)($tx['amount'] ?? 0), 2); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($tx['from_currency'] ?? 'BWP'); ?> 
                                    → <?php echo htmlspecialchars($tx['to_currency'] ?? 'BWP'); ?>
                                </td>
                                <td>
                                    <?php 
                                    $status = strtolower($tx['status'] ?? 'pending');
                                    $class = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                    ?>
                                    <span class="status status-<?php echo $class; ?>">
                                        <?php echo htmlspecialchars($tx['status'] ?? 'pending'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding:20px; color:#999;">No transactions found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Cashouts -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Recent Cashouts</span>
                    <span class="card-badge">Last 10</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Phone</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentCashouts)): ?>
                            <?php foreach ($recentCashouts as $co): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($co['client_phone'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format((float)($co['amount'] ?? 0), 2); ?></td>
                                <td>
                                    <?php 
                                    $status = strtolower($co['status'] ?? 'pending');
                                    $class = $status === 'completed' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                    ?>
                                    <span class="status status-<?php echo $class; ?>">
                                        <?php echo htmlspecialchars($co['status'] ?? 'pending'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($co['created_at'] ?? 'now')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="4" style="text-align:center; padding:20px; color:#999;">No cashouts found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="grid-2">
            <!-- Recent Settlements -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Recent Settlements</span>
                    <span class="card-badge">Last 10</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Debtor</th>
                                <th>Creditor</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentSettlements)): ?>
                            <?php foreach ($recentSettlements as $s): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['debtor'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($s['creditor'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format((float)($s['amount'] ?? 0), 2); ?></td>
                                <td>
                                    <?php 
                                    $status = strtolower($s['status'] ?? 'pending');
                                    $class = $status === 'completed' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                    ?>
                                    <span class="status status-<?php echo $class; ?>">
                                        <?php echo htmlspecialchars($s['status'] ?? 'pending'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($s['created_at'] ?? 'now')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding:20px; color:#999;">No settlements found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Identity Swaps -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Identity Swaps</span>
                    <span class="card-badge">Last 10</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Identity</th>
                                <th>Amount</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentIdentitySwaps)): ?>
                            <?php foreach ($recentIdentitySwaps as $id): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($id['identity_type'] ?? 'N/A'); ?>: 
                                    <?php echo htmlspecialchars(substr($id['identity_value'] ?? '', 0, 6)); ?>
                                </td>
                                <td><?php echo number_format((float)($id['amount'] ?? 0), 2); ?></td>
                                <td><?php echo htmlspecialchars($id['source_institution'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php 
                                    $status = strtolower($id['status'] ?? 'pending');
                                    $class = $status === 'completed' ? 'success' : ($status === 'pending' ? 'pending' : 'info');
                                    ?>
                                    <span class="status status-<?php echo $class; ?>">
                                        <?php echo htmlspecialchars($id['status'] ?? 'pending'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($id['created_at'] ?? 'now')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding:20px; color:#999;">No identity swaps found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($view === 'transactions'): ?>
        <!-- ============================================================ -->
        <!-- TRANSACTIONS VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>TRANSACTIONS</h1>
            <div class="timestamp">All swap transactions</div>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Amount</th>
                            <th>From/To</th>
                            <th>Status</th>
                            <th>Source Country</th>
                            <th>Dest Country</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentTransactions)): ?>
                        <?php foreach ($recentTransactions as $tx): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($tx['swap_uuid'] ?? $tx['swap_id'] ?? 'N/A', 0, 8)); ?></td>
                            <td><?php echo number_format((float)($tx['amount'] ?? 0), 2); ?></td>
                            <td>
                                <?php echo htmlspecialchars($tx['from_currency'] ?? 'BWP'); ?> 
                                → <?php echo htmlspecialchars($tx['to_currency'] ?? 'BWP'); ?>
                            </td>
                            <td>
                                <?php 
                                $status = strtolower($tx['status'] ?? 'pending');
                                $class = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>">
                                    <?php echo htmlspecialchars($tx['status'] ?? 'pending'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($tx['source_country'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($tx['destination_country'] ?? 'N/A'); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr><td colspan="7" style="text-align:center; padding:30px; color:#999;">No transactions found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($view === 'cashouts'): ?>
        <!-- ============================================================ -->
        <!-- CASHOUTS VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>CASHOUTS</h1>
            <div class="timestamp">Cashout authorizations</div>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Phone</th>
                            <th>Amount</th>
                            <th>Point</th>
                            <th>Provider</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentCashouts)): ?>
                        <?php foreach ($recentCashouts as $co): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($co['client_phone'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($co['amount'] ?? 0), 2); ?></td>
                            <td><?php echo htmlspecialchars($co['cashout_point'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($co['cashout_provider'] ?? 'N/A'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($co['status'] ?? 'pending');
                                $class = $status === 'completed' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>">
                                    <?php echo htmlspecialchars($co['status'] ?? 'pending'); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($co['created_at'] ?? 'now')); ?></td>
                            <td><?php echo $co['completed_at'] ? date('Y-m-d H:i', strtotime($co['completed_at'])) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr><td colspan="7" style="text-align:center; padding:30px; color:#999;">No cashouts found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($view === 'settlements'): ?>
        <!-- ============================================================ -->
        <!-- SETTLEMENTS VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>SETTLEMENTS</h1>
            <div class="timestamp">Settlement queue</div>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Debtor</th>
                            <th>Creditor</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentSettlements)): ?>
                        <?php foreach ($recentSettlements as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($s['reference'] ?? '', 0, 12)); ?></td>
                            <td><?php echo htmlspecialchars($s['debtor'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($s['creditor'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($s['amount'] ?? 0), 2); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($s['status'] ?? 'pending');
                                $class = $status === 'completed' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>">
                                    <?php echo htmlspecialchars($s['status'] ?? 'pending'); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($s['created_at'] ?? 'now')); ?></td>
                            <td><?php echo $s['updated_at'] ? date('Y-m-d H:i', strtotime($s['updated_at'])) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr><td colspan="7" style="text-align:center; padding:30px; color:#999;">No settlements found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($view === 'identity'): ?>
        <!-- ============================================================ -->
        <!-- IDENTITY VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>IDENTITY SWAPS</h1>
            <div class="timestamp">Identity verification holds</div>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Identity</th>
                            <th>Value</th>
                            <th>Amount</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Confirmed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentIdentitySwaps)): ?>
                        <?php foreach ($recentIdentitySwaps as $id): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($id['swap_reference'] ?? '', 0, 8)); ?></td>
                            <td><?php echo htmlspecialchars($id['identity_type'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars(substr($id['identity_value'] ?? '', 0, 10)); ?></td>
                            <td><?php echo number_format((float)($id['amount'] ?? 0), 2); ?></td>
                            <td><?php echo htmlspecialchars($id['source_institution'] ?? 'N/A'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($id['status'] ?? 'pending');
                                $class = $status === 'completed' ? 'success' : ($status === 'pending' ? 'pending' : 'info');
                                ?>
                                <span class="status status-<?php echo $class; ?>">
                                    <?php echo htmlspecialchars($id['status'] ?? 'pending'); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($id['created_at'] ?? 'now')); ?></td>
                            <td><?php echo $id['confirmed_at'] ? date('Y-m-d H:i', strtotime($id['confirmed_at'])) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr><td colspan="8" style="text-align:center; padding:30px; color:#999;">No identity swaps found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($view === 'invoices'): ?>
        <!-- ============================================================ -->
        <!-- INVOICES VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>FEE INVOICES</h1>
            <div class="timestamp">VouchMorph fee invoices</div>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Swap Ref</th>
                            <th>Source</th>
                            <th>Type</th>
                            <th>Fee</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentInvoices)): ?>
                        <?php foreach ($recentInvoices as $inv): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($inv['invoice_uuid'] ?? '', 0, 8)); ?></td>
                            <td><?php echo htmlspecialchars(substr($inv['swap_reference'] ?? '', 0, 8)); ?></td>
                            <td><?php echo htmlspecialchars($inv['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($inv['fee_type'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format((float)($inv['fee_amount'] ?? 0), 2); ?></td>
                            <td><?php echo number_format((float)($inv['total_amount'] ?? 0), 2); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($inv['status'] ?? 'pending');
                                $class = $status === 'paid' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>">
                                    <?php echo htmlspecialchars($inv['status'] ?? 'pending'); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($inv['created_at'] ?? 'now')); ?></td>
                            <td><?php echo $inv['paid_at'] ? date('Y-m-d H:i', strtotime($inv['paid_at'])) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr><td colspan="9" style="text-align:center; padding:30px; color:#999;">No invoices found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($view === 'audit'): ?>
        <!-- ============================================================ -->
        <!-- AUDIT VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>AUDIT LOGS</h1>
            <div class="timestamp">Admin actions</div>
        </div>
        <div class="card">
            <div class="table-responsive">
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
                        <?php if (!empty($recentAdminActions)): ?>
                        <?php foreach ($recentAdminActions as $action): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($action['action_type'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($action['entity_type'] ?? 'N/A'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($action['status'] ?? '');
                                $class = $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>">
                                    <?php echo htmlspecialchars($action['status'] ?? 'pending'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($action['admin_id'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($action['ip_address'] ?? 'N/A'); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($action['created_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr><td colspan="6" style="text-align:center; padding:30px; color:#999;">No audit logs found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <div class="content-header">
            <h1>Module: <?php echo ucfirst($view); ?></h1>
            <div class="timestamp">Coming soon</div>
        </div>
        <div class="card">
            <p style="padding: 20px; color: #666;">This module is currently under development.</p>
        </div>
        <?php endif; ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · Botswana · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>
</body>
</html>
