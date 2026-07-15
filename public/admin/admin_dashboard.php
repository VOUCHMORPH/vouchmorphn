<?php
/**
 * admin_dashboard.php - VouchMorph Enhanced Admin Dashboard
 * Features: Role-based access, Transaction Search, Full Tracking, Reports, Debug Mode
 * 
 * UPDATED: Each table is displayed as a collapsible card showing all records line by line
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

// Role definitions with permissions
$roleDefinitions = [
    999 => ['name' => 'Super Admin', 'permissions' => ['all'], 'level' => 100],
    3 => ['name' => 'Regulator', 'permissions' => ['view_dashboard', 'view_reports', 'audit_logs', 'compliance_checks', 'search_transactions'], 'level' => 80],
    4 => ['name' => 'Compliance Officer', 'permissions' => ['view_dashboard', 'view_reports', 'review_transactions', 'kyc_verification', 'search_transactions'], 'level' => 70],
    5 => ['name' => 'Auditor', 'permissions' => ['view_dashboard', 'view_reports', 'audit_logs', 'read_only', 'search_transactions'], 'level' => 60],
    6 => ['name' => 'Support', 'permissions' => ['view_dashboard', 'search_transactions'], 'level' => 50]
];

$roleName = $roleDefinitions[$adminRoleId]['name'] ?? 'Administrator';
$userPermissions = $roleDefinitions[$adminRoleId]['permissions'] ?? [];

function hasPermission($permission) {
    global $userPermissions;
    return in_array('all', $userPermissions) || in_array($permission, $userPermissions);
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

$view = $_GET['view'] ?? 'dashboard';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$search = $_GET['search'] ?? '';
$transactionId = $_GET['id'] ?? null;

// Helper for safe HTML
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// FETCH ALL TABLE DATA - LINE BY LINE
// ============================================================

$tableData = [];

// Define all tables to display
$tablesToFetch = [
    'swap_requests' => ['label' => '📋 Swap Requests', 'order' => 'created_at DESC', 'limit' => 100],
    'hold_transactions' => ['label' => '🔒 Hold Transactions', 'order' => 'created_at DESC', 'limit' => 100],
    'identity_swap_holds' => ['label' => '🆔 Identity Swap Holds', 'order' => 'created_at DESC', 'limit' => 100],
    'cashout_authorizations' => ['label' => '🏧 Cashout Authorizations', 'order' => 'created_at DESC', 'limit' => 100],
    'fee_invoices' => ['label' => '💰 Fee Invoices', 'order' => 'created_at DESC', 'limit' => 100],
    'settlement_queue' => ['label' => '📤 Settlement Queue', 'order' => 'created_at DESC', 'limit' => 100],
    'settlement_outbox' => ['label' => '📨 Settlement Outbox', 'order' => 'created_at DESC', 'limit' => 100],
    'swap_ledgers' => ['label' => '📒 Swap Ledgers', 'order' => 'created_at DESC', 'limit' => 100],
    'swap_fee_collections' => ['label' => '💳 Swap Fee Collections', 'order' => 'created_at DESC', 'limit' => 100],
    'net_positions' => ['label' => '⚖️ Net Positions', 'order' => 'created_at DESC', 'limit' => 100],
    'multi_destination_swaps' => ['label' => '📦 Multi-Destination Swaps', 'order' => 'created_at DESC', 'limit' => 100],
    'idempotency_keys' => ['label' => '🔑 Idempotency Keys', 'order' => 'created_at DESC', 'limit' => 100],
    'swap_vouchers' => ['label' => '🎫 Swap Vouchers', 'order' => 'created_at DESC', 'limit' => 100],
    'cross_border_messages' => ['label' => '🌍 Cross Border Messages', 'order' => 'created_at DESC', 'limit' => 100],
    'deposit_transactions' => ['label' => '🏦 Deposit Transactions', 'order' => 'created_at DESC', 'limit' => 100],
    'audit_logs' => ['label' => '📝 Audit Logs', 'order' => 'performed_at DESC', 'limit' => 100],
    'users' => ['label' => '👤 Users', 'order' => 'created_at DESC', 'limit' => 100],
    'admins' => ['label' => '👑 Admins', 'order' => 'created_at DESC', 'limit' => 100],
];

// Fetch each table
foreach ($tablesToFetch as $table => $config) {
    try {
        // Check if table exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table");
        $stmt->execute([':table' => $table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        
        if ($exists) {
            $orderBy = $config['order'] ?? 'created_at DESC';
            $limit = $config['limit'] ?? 100;
            $stmt = $db->query("SELECT * FROM {$table} ORDER BY {$orderBy} LIMIT {$limit}");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $tableData[$table] = [
                'exists' => true,
                'rows' => $rows,
                'count' => count($rows),
                'label' => $config['label'],
                'columns' => !empty($rows) ? array_keys($rows[0]) : []
            ];
        } else {
            $tableData[$table] = [
                'exists' => false,
                'rows' => [],
                'count' => 0,
                'label' => $config['label'],
                'columns' => []
            ];
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
// SEARCH
// ============================================================
$searchResults = [];
$searchPerformed = false;
if (!empty($search) && hasPermission('search_transactions')) {
    $searchPerformed = true;
    try {
        $stmt = $db->prepare("
            SELECT 
                sr.swap_id, sr.swap_uuid, sr.user_id, sr.amount, sr.status, sr.created_at,
                sr.from_currency, sr.to_currency, sr.source_country, sr.destination_country,
                u.full_name as user_name, u.phone as user_phone, u.email as user_email
            FROM swap_requests sr
            LEFT JOIN users u ON sr.user_id = u.user_id
            WHERE 
                sr.swap_id::text ILIKE :search OR sr.swap_uuid ILIKE :search
                OR u.full_name ILIKE :search OR u.phone ILIKE :search
                OR u.email ILIKE :search OR sr.status ILIKE :search
                OR sr.from_currency ILIKE :search OR sr.to_currency ILIKE :search
            ORDER BY sr.created_at DESC LIMIT 100
        ");
        $stmt->execute([':search' => '%' . $search . '%']);
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] Search error: " . $e->getMessage());
    }
}

// ============================================================
// METRICS
// ============================================================
$metrics = [];
try {
    $stmt = $db->query("SELECT COUNT(*) FROM users");
    $metrics['total_users'] = (int)$stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM swap_requests");
    $metrics['total_swaps'] = (int)$stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM hold_transactions");
    $metrics['total_holds'] = (int)$stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM cashout_authorizations");
    $metrics['total_cashouts'] = (int)$stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM fee_invoices");
    $metrics['total_invoices'] = (int)$stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM audit_logs");
    $metrics['total_audit_logs'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = array_fill_keys(['total_users', 'total_swaps', 'total_holds', 'total_cashouts', 'total_invoices', 'total_audit_logs'], 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · ADMIN DASHBOARD</title>
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
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header-left { display: flex; align-items: center; gap: 30px; flex-wrap: wrap; }
        .logo { font-size: 1.2rem; font-weight: 700; letter-spacing: 2px; }
        .logo span { color: #FFDA63; margin-left: 10px; font-size: 0.8rem; }
        .country-badge {
            padding: 5px 15px;
            background: rgba(255,218,99,0.2);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .debug-badge {
            padding: 5px 15px;
            background: rgba(255,0,0,0.2);
            border: 1px solid #ff4444;
            color: #ff4444;
            font-size: 0.8rem;
            text-transform: uppercase;
            font-weight: 700;
        }
        .role-badge {
            padding: 5px 15px;
            background: rgba(255,218,99,0.15);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        .user-info { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
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
        
        .admin-nav {
            background: #fff;
            border-bottom: 2px solid #001B44;
            padding: 0 30px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: 15px 0;
            color: #666;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .nav-item:hover { color: #001B44; }
        .nav-item.active { color: #001B44; border-bottom-color: #FFDA63; }
        .nav-item.debug-link { color: #ff4444 !important; border-bottom-color: #ff4444 !important; }
        
        .admin-content { padding: 30px; max-width: 1600px; margin: 0 auto; }
        .content-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .content-header h1 { font-size: 1.5rem; font-weight: 600; color: #001B44; }
        .content-header .timestamp { color: #666; font-size: 0.8rem; }
        
        /* Search Bar */
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .search-bar input {
            flex: 1;
            min-width: 200px;
            padding: 12px 16px;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.9rem;
            background: #fff;
        }
        .search-bar input:focus { outline: none; border-color: #FFDA63; }
        .search-bar button {
            padding: 12px 24px;
            background: #001B44;
            color: #fff;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .search-bar button:hover { background: #FFDA63; color: #001B44; border-color: #FFDA63; }
        
        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 15px 20px;
            box-shadow: 4px 4px 0 #A1B5D8;
            transition: transform 0.2s;
        }
        .metric-card:hover { transform: translateY(-2px); }
        .metric-label {
            font-size: 0.6rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        .metric-value { font-size: 1.8rem; font-weight: 600; color: #001B44; line-height: 1.2; }
        .metric-value .sub { font-size: 0.8rem; color: #666; }
        
        /* Table Card */
        .table-card {
            background: #fff;
            border: 2px solid #001B44;
            margin-bottom: 16px;
            overflow: hidden;
            box-shadow: 4px 4px 0 #A1B5D8;
            transition: all 0.2s;
        }
        .table-card:hover { box-shadow: 6px 6px 0 #A1B5D8; }
        .table-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 20px;
            background: #f8fafc;
            border-bottom: 2px solid #001B44;
            cursor: pointer;
            transition: background 0.2s;
            user-select: none;
        }
        .table-card-header:hover { background: #f1f5f9; }
        .table-card-header .title {
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table-card-header .badge {
            display: inline-block;
            padding: 2px 12px;
            background: #001B44;
            color: #fff;
            font-size: 0.7rem;
            border-radius: 12px;
            margin-left: 10px;
        }
        .table-card-header .badge-empty {
            background: #999;
            color: #fff;
        }
        .table-card-header .toggle-icon {
            font-size: 1.2rem;
            transition: transform 0.3s;
            color: #666;
        }
        .table-card-header .toggle-icon.open { transform: rotate(180deg); }
        .table-card-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, padding 0.3s ease;
            padding: 0 20px;
        }
        .table-card-body.open {
            max-height: 2000px;
            padding: 16px 20px;
        }
        .table-card-body .table-responsive { overflow-x: auto; }
        .table-card-body table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }
        .table-card-body th {
            background: #001B44;
            color: #fff;
            padding: 8px 10px;
            font-weight: 600;
            text-align: left;
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table-card-body td {
            padding: 6px 10px;
            border-bottom: 1px solid #eee;
            font-size: 0.7rem;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .table-card-body tr:hover { background: #f5f5f5; }
        .table-card-body .empty-state {
            text-align: center;
            padding: 30px;
            color: #999;
        }
        .table-card-body .empty-state .icon { font-size: 2rem; margin-bottom: 8px; }
        
        .status {
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
            border-radius: 3px;
        }
        .status-success { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .status-pending { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .status-failed { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .status-info { background: #cce5ff; color: #004085; border-color: #b8daff; }
        
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
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .admin-nav { padding: 0 15px; gap: 10px; }
            .admin-content { padding: 15px; }
            .admin-header { padding: 15px; }
            .metric-value { font-size: 1.2rem; }
            .table-card-body td { max-width: 120px; }
            .table-card-body .table-responsive { font-size: 0.65rem; }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="country-badge">BOTSWANA</div>
            <div class="role-badge">👤 <?php echo safeHtml($roleName); ?></div>
            <?php if ($debug): ?>
            <div class="debug-badge">🔍 DEBUG MODE</div>
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
        <a href="?view=dashboard" class="nav-item <?php echo $view === 'dashboard' ? 'active' : ''; ?>">📊 DASHBOARD</a>
        <a href="?view=search" class="nav-item <?php echo $view === 'search' ? 'active' : ''; ?>">🔍 SEARCH</a>
        <a href="?view=tables" class="nav-item <?php echo $view === 'tables' ? 'active' : ''; ?>">📋 TABLES</a>
        <?php if ($debug): ?>
        <a href="?view=debug&debug=1" class="nav-item active debug-link">🔍 DEBUG</a>
        <?php else: ?>
        <a href="?view=dashboard&debug=1" class="nav-item debug-link">🔍 DEBUG</a>
        <?php endif; ?>
    </nav>

    <main class="admin-content">
        <!-- ============================================================ -->
        <!-- DASHBOARD VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'dashboard'): ?>
        <div class="content-header">
            <h1>📊 EXECUTIVE DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></div>
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
                <div class="metric-label">Total Holds</div>
                <div class="metric-value"><?php echo number_format($metrics['total_holds']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Cashouts</div>
                <div class="metric-value"><?php echo number_format($metrics['total_cashouts']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Total Invoices</div>
                <div class="metric-value"><?php echo number_format($metrics['total_invoices']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Audit Logs</div>
                <div class="metric-value"><?php echo number_format($metrics['total_audit_logs']); ?></div>
            </div>
        </div>

        <div style="text-align: right; margin-bottom: 16px;">
            <a href="?view=tables" style="color: #001B44; font-weight: 600; font-size: 0.8rem; text-transform: uppercase;">📋 View All Tables →</a>
        </div>

        <!-- Show top 5 tables with data -->
        <?php 
        $displayed = 0;
        foreach ($tableData as $table => $data):
            if ($data['count'] > 0 && $displayed < 5):
                $displayed++;
        ?>
        <div class="table-card">
            <div class="table-card-header" onclick="toggleTable('<?php echo $table; ?>')">
                <span class="title">
                    <?php echo safeHtml($data['label'] ?? $table); ?>
                    <span class="badge"><?php echo number_format($data['count']); ?> records</span>
                </span>
                <span class="toggle-icon" id="icon_<?php echo $table; ?>">▼</span>
            </div>
            <div class="table-card-body" id="body_<?php echo $table; ?>">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <?php foreach (array_slice($data['columns'], 0, 8) as $col): ?>
                                <th><?php echo safeHtml($col); ?></th>
                                <?php endforeach; ?>
                                <?php if (count($data['columns']) > 8): ?>
                                <th>...</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($data['rows'], 0, 20) as $row): ?>
                            <tr>
                                <?php 
                                $colCount = 0;
                                foreach ($row as $key => $value):
                                    if ($colCount++ >= 8) break;
                                    $display = is_string($value) ? substr($value, 0, 50) : (string)$value;
                                    // Check if it's a status field
                                    if (strpos(strtolower($key), 'status') !== false) {
                                        $statusClass = 'info';
                                        if (stripos($value, 'complete') !== false || stripos($value, 'success') !== false) $statusClass = 'success';
                                        elseif (stripos($value, 'pending') !== false) $statusClass = 'pending';
                                        elseif (stripos($value, 'fail') !== false) $statusClass = 'failed';
                                        echo '<td><span class="status status-' . $statusClass . '">' . safeHtml($display) . '</span></td>';
                                    } else {
                                        echo '<td>' . safeHtml($display) . '</td>';
                                    }
                                endforeach;
                                ?>
                                <?php if (count($row) > 8): ?>
                                <td><span style="color: #999;">+<?php echo count($row) - 8; ?> more</span></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php if ($data['count'] > 20): ?>
                            <tr><td colspan="<?php echo min(9, count($data['columns']) + 1); ?>" style="text-align:center; color:#999; font-size:0.7rem;">
                                ... and <?php echo number_format($data['count'] - 20); ?> more records
                            </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; endforeach; ?>

        <?php if ($displayed === 0): ?>
        <div class="table-card">
            <div class="table-card-body open" style="padding: 40px; text-align: center; color: #999;">
                <div class="icon" style="font-size: 3rem;">📭</div>
                <p>No data found in any tables yet.</p>
                <p style="font-size: 0.8rem; margin-top: 8px;">Records will appear here once transactions are processed.</p>
            </div>
        </div>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 20px;">
            <a href="?view=tables" class="nav-item" style="padding: 10px 24px; border: 2px solid #001B44; border-radius: 4px;">📋 View All <?php echo count($tableData); ?> Tables →</a>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- TABLES VIEW - All tables line by line -->
        <!-- ============================================================ -->
        <?php if ($view === 'tables'): ?>
        <div class="content-header">
            <h1>📋 ALL DATABASE TABLES</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>

        <?php foreach ($tableData as $table => $data): ?>
        <div class="table-card">
            <div class="table-card-header" onclick="toggleTable('<?php echo $table; ?>')">
                <span class="title">
                    <?php echo safeHtml($data['label'] ?? $table); ?>
                    <?php if (!$data['exists']): ?>
                    <span class="badge" style="background: #dc3545;">❌ MISSING</span>
                    <?php elseif ($data['count'] === 0): ?>
                    <span class="badge badge-empty">⚠️ EMPTY</span>
                    <?php else: ?>
                    <span class="badge"><?php echo number_format($data['count']); ?> records</span>
                    <?php endif; ?>
                </span>
                <span class="toggle-icon" id="icon_<?php echo $table; ?>">▼</span>
            </div>
            <div class="table-card-body" id="body_<?php echo $table; ?>">
                <?php if (!$data['exists']): ?>
                <div class="empty-state">
                    <div class="icon">❌</div>
                    <p>Table <code><?php echo safeHtml($table); ?></code> does not exist in the database.</p>
                </div>
                <?php elseif ($data['count'] === 0): ?>
                <div class="empty-state">
                    <div class="icon">📭</div>
                    <p>Table <code><?php echo safeHtml($table); ?></code> is empty.</p>
                    <p style="font-size: 0.8rem; color: #666; margin-top: 5px;">No records found.</p>
                </div>
                <?php elseif (!empty($data['error'])): ?>
                <div class="empty-state" style="color: #dc3545;">
                    <div class="icon">⚠️</div>
                    <p>Error fetching data: <?php echo safeHtml($data['error']); ?></p>
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
                                <?php foreach ($row as $key => $value): ?>
                                <td>
                                    <?php 
                                    $display = is_string($value) ? substr($value, 0, 100) : (string)$value;
                                    if (strpos(strtolower($key), 'status') !== false) {
                                        $statusClass = 'info';
                                        if (stripos($value, 'complete') !== false || stripos($value, 'success') !== false) $statusClass = 'success';
                                        elseif (stripos($value, 'pending') !== false) $statusClass = 'pending';
                                        elseif (stripos($value, 'fail') !== false) $statusClass = 'failed';
                                        echo '<span class="status status-' . $statusClass . '">' . safeHtml($display) . '</span>';
                                    } elseif (strpos(strtolower($key), 'hash') !== false || strpos(strtolower($key), 'signature') !== false) {
                                        echo safeHtml(substr($display, 0, 20) . '...');
                                    } else {
                                        echo safeHtml($display);
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
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- SEARCH VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'search'): ?>
        <div class="content-header">
            <h1>🔍 SEARCH TRANSACTIONS</h1>
            <div class="timestamp">Search across all transaction data</div>
            <a href="?view=dashboard" class="nav-item" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px;">← Back</a>
        </div>

        <div class="search-bar">
            <form method="GET" style="display: flex; gap: 10px; flex: 1; flex-wrap: wrap;">
                <input type="hidden" name="view" value="search">
                <input type="text" name="search" placeholder="Search by ID, User, Phone, Email, National ID, Status, Currency..." 
                       value="<?php echo safeHtml($search); ?>"
                       style="flex: 1; min-width: 200px; padding: 12px 16px; border: 2px solid #001B44; font-family: 'IBM Plex Mono', monospace;">
                <button type="submit">🔍 SEARCH</button>
                <?php if ($search): ?>
                <a href="?view=search" style="padding: 12px 20px; border: 2px solid #999; color: #666; text-decoration: none;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($searchPerformed): ?>
        <div class="table-card">
            <div class="table-card-header" style="cursor: default;">
                <span class="title">
                    Search Results for: "<?php echo safeHtml($search); ?>"
                    <span class="badge"><?php echo count($searchResults); ?> FOUND</span>
                </span>
            </div>
            <div class="table-card-body open">
                <?php if (empty($searchResults)): ?>
                <div class="empty-state">
                    <div class="icon">🔍</div>
                    <p>No results found for "<?php echo safeHtml($search); ?>"</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Phone</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($searchResults as $result): ?>
                            <tr>
                                <td><?php echo safeHtml(substr($result['swap_uuid'] ?? $result['swap_id'] ?? 'N/A', 0, 12)); ?></td>
                                <td><?php echo safeHtml($result['user_name'] ?? 'N/A'); ?></td>
                                <td><?php echo safeHtml($result['user_phone'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format((float)($result['amount'] ?? 0), 2); ?></td>
                                <td>
                                    <?php 
                                    $status = strtolower($result['status'] ?? 'pending');
                                    $class = $status === 'completed' || $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : 'pending');
                                    ?>
                                    <span class="status status-<?php echo $class; ?>"><?php echo safeHtml($result['status'] ?? 'pending'); ?></span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($result['created_at'] ?? 'now')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · Botswana · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>

    <script>
        function toggleTable(tableId) {
            const body = document.getElementById('body_' + tableId);
            const icon = document.getElementById('icon_' + tableId);
            if (body) {
                body.classList.toggle('open');
                if (icon) {
                    icon.classList.toggle('open');
                }
            }
        }

        // Auto-expand tables with data when in tables view
        <?php if ($view === 'tables'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($tableData as $table => $data): ?>
            <?php if ($data['count'] > 0): ?>
            setTimeout(function() {
                const body = document.getElementById('body_<?php echo $table; ?>');
                const icon = document.getElementById('icon_<?php echo $table; ?>');
                if (body) body.classList.add('open');
                if (icon) icon.classList.add('open');
            }, 100);
            <?php endif; ?>
            <?php endforeach; ?>
        });
        <?php endif; ?>
    </script>
</body>
</html>
