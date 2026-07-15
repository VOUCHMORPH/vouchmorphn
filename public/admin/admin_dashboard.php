<?php
/**
 * admin_dashboard.php - VouchMorph Enhanced Admin Dashboard
 * Features: PDF Reports, Search, Table Browsing, Role-based Access
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
require_once PROJECT_ROOT . '/vendor/autoload.php'; // For PhpSpreadsheet

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

// Role definitions with permissions
$roleDefinitions = [
    999 => ['name' => 'Super Admin', 'permissions' => ['all', 'export_pdf'], 'level' => 100],
    3 => ['name' => 'Regulator', 'permissions' => ['view_dashboard', 'view_reports', 'audit_logs', 'compliance_checks', 'search_transactions', 'export_pdf'], 'level' => 80],
    4 => ['name' => 'Compliance Officer', 'permissions' => ['view_dashboard', 'view_reports', 'review_transactions', 'kyc_verification', 'search_transactions', 'export_pdf'], 'level' => 70],
    5 => ['name' => 'Auditor', 'permissions' => ['view_dashboard', 'view_reports', 'audit_logs', 'read_only', 'search_transactions', 'export_pdf'], 'level' => 60],
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

// Get view and parameters
$view = $_GET['view'] ?? 'dashboard';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$search = $_GET['search'] ?? '';
$searchTable = $_GET['search_table'] ?? '';
$transactionId = $_GET['id'] ?? null;
$exportTable = $_GET['export'] ?? '';
$exportId = $_GET['export_id'] ?? '';

// Helper for safe HTML
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// PDF GENERATION
// ============================================================
if ($exportTable && hasPermission('export_pdf')) {
    try {
        // Get data from the specified table
        $stmt = $db->prepare("SELECT * FROM " . $exportTable . " WHERE id = :id OR swap_id = :id");
        $stmt->execute([':id' => $exportId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($data)) {
            // Try with different id columns
            $stmt = $db->prepare("SELECT * FROM " . $exportTable . " LIMIT 100");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        if (!empty($data)) {
            // Create Spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Set headers
            $columns = array_keys($data[0]);
            foreach ($columns as $col => $colName) {
                $sheet->setCellValue(chr(65 + $col) . '1', $colName);
                $sheet->getStyle(chr(65 + $col) . '1')->getFont()->setBold(true);
                $sheet->getColumnDimension(chr(65 + $col))->setAutoSize(true);
            }
            
            // Add data
            $rowNum = 2;
            foreach ($data as $row) {
                $colNum = 0;
                foreach ($row as $value) {
                    $sheet->setCellValue(chr(65 + $colNum) . $rowNum, (string)$value);
                    $colNum++;
                }
                $rowNum++;
            }
            
            // Generate PDF
            $writer = new Mpdf($spreadsheet);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $exportTable . '_report.pdf"');
            $writer->save('php://output');
            exit;
        }
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] PDF Export error: " . $e->getMessage());
        // Fallback to HTML table export
        header('Content-Type: text/html');
        echo "<html><head><title>Export Error</title></head><body>";
        echo "<h2>PDF Export Error</h2>";
        echo "<p>" . safeHtml($e->getMessage()) . "</p>";
        echo "</body></html>";
        exit;
    }
}

// ============================================================
// CSV EXPORT
// ============================================================
if ($exportTable && isset($_GET['format']) && $_GET['format'] === 'csv') {
    try {
        $stmt = $db->prepare("SELECT * FROM " . $exportTable . " LIMIT 500");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $exportTable . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) {
                fputcsv($output, array_values($row));
            }
        }
        fclose($output);
        exit;
    } catch (Throwable $e) {
        error_log("[ADMIN DASHBOARD] CSV Export error: " . $e->getMessage());
    }
}

// ============================================================
// FETCH ALL TABLE DATA
// ============================================================
$tableData = [];

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

foreach ($tablesToFetch as $table => $config) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table");
        $stmt->execute([':table' => $table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        
        if ($exists) {
            $orderBy = $config['order'] ?? 'created_at DESC';
            $limit = $config['limit'] ?? 100;
            
            // If searching, apply search filter
            $whereClause = '';
            if (!empty($search) && !empty($searchTable) && $searchTable === $table) {
                $whereClause = " WHERE ";
                $searchTerms = explode(' ', $search);
                $conditions = [];
                foreach ($searchTerms as $term) {
                    $conditions[] = "CAST(" . $table . "::text ILIKE '%" . addslashes($term) . "%'";
                }
                $whereClause .= implode(' OR ', $conditions);
            }
            
            $query = "SELECT * FROM {$table} {$whereClause} ORDER BY {$orderBy} LIMIT {$limit}";
            $rows = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
            
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
            background: #fff;
            padding: 20px;
            border: 2px solid #001B44;
        }
        .search-bar input {
            flex: 1;
            min-width: 200px;
            padding: 10px 14px;
            border: 2px solid #cbd5e1;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.85rem;
            background: #fff;
            border-radius: 4px;
        }
        .search-bar input:focus { outline: none; border-color: #001B44; }
        .search-bar select {
            padding: 10px 14px;
            border: 2px solid #cbd5e1;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.85rem;
            background: #fff;
            border-radius: 4px;
            min-width: 150px;
        }
        .search-bar button {
            padding: 10px 24px;
            background: #001B44;
            color: #fff;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 600;
            cursor: pointer;
            border-radius: 4px;
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
            padding: 12px 18px;
            background: #f8fafc;
            border-bottom: 2px solid #001B44;
            cursor: pointer;
            transition: background 0.2s;
            user-select: none;
            flex-wrap: wrap;
            gap: 10px;
        }
        .table-card-header:hover { background: #f1f5f9; }
        .table-card-header .title {
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .table-card-header .badge {
            display: inline-block;
            padding: 2px 12px;
            background: #001B44;
            color: #fff;
            font-size: 0.65rem;
            border-radius: 12px;
        }
        .table-card-header .badge-empty { background: #999; }
        .table-card-header .actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .table-card-header .actions .btn {
            padding: 4px 12px;
            font-size: 0.6rem;
            border: 1px solid #001B44;
            background: #fff;
            color: #001B44;
            cursor: pointer;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 4px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .table-card-header .actions .btn:hover { background: #001B44; color: #fff; }
        .table-card-header .actions .btn-pdf { border-color: #dc3545; color: #dc3545; }
        .table-card-header .actions .btn-pdf:hover { background: #dc3545; color: #fff; }
        .table-card-header .actions .btn-csv { border-color: #28a745; color: #28a745; }
        .table-card-header .actions .btn-csv:hover { background: #28a745; color: #fff; }
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
            padding: 0 18px;
        }
        .table-card-body.open {
            max-height: 2000px;
            padding: 14px 18px;
        }
        .table-card-body .table-responsive { overflow-x: auto; }
        .table-card-body table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.7rem;
        }
        .table-card-body th {
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
        .table-card-body td {
            padding: 5px 10px;
            border-bottom: 1px solid #eee;
            font-size: 0.65rem;
            max-width: 200px;
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
            .search-bar { flex-direction: column; }
            .search-bar input, .search-bar select { min-width: 100%; }
            .table-card-body td { max-width: 120px; }
            .table-card-header { flex-direction: column; align-items: stretch; }
            .table-card-header .actions { justify-content: flex-start; }
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
        <a href="?view=tables" class="nav-item <?php echo $view === 'tables' ? 'active' : ''; ?>">📋 TABLES</a>
        <a href="?view=search" class="nav-item <?php echo $view === 'search' ? 'active' : ''; ?>">🔍 SEARCH</a>
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
                <div class="actions">
                    <?php if (hasPermission('export_pdf')): ?>
                    <a href="?export=<?php echo $table; ?>&export_id=all" class="btn btn-pdf">PDF</a>
                    <a href="?export=<?php echo $table; ?>&format=csv" class="btn btn-csv">CSV</a>
                    <?php endif; ?>
                    <span class="toggle-icon" id="icon_<?php echo $table; ?>">▼</span>
                </div>
            </div>
            <div class="table-card-body" id="body_<?php echo $table; ?>">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <?php foreach (array_slice($data['columns'], 0, 7) as $col): ?>
                                <th><?php echo safeHtml($col); ?></th>
                                <?php endforeach; ?>
                                <?php if (count($data['columns']) > 7): ?>
                                <th>...</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($data['rows'], 0, 15) as $row): ?>
                            <tr>
                                <?php 
                                $colCount = 0;
                                foreach ($row as $key => $value):
                                    if ($colCount++ >= 7) break;
                                    $display = is_string($value) ? substr($value, 0, 50) : (string)$value;
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
                                <?php if (count($row) > 7): ?>
                                <td><span style="color: #999;">+<?php echo count($row) - 7; ?> more</span></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php if ($data['count'] > 15): ?>
                            <tr><td colspan="<?php echo min(8, count($data['columns']) + 1); ?>" style="text-align:center; color:#999; font-size:0.65rem;">
                                ... and <?php echo number_format($data['count'] - 15); ?> more records
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
            <a href="?view=tables" style="padding: 10px 24px; border: 2px solid #001B44; border-radius: 4px; text-decoration: none; color: #001B44; font-weight: 600; display: inline-block;">📋 View All <?php echo count($tableData); ?> Tables →</a>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- TABLES VIEW -->
        <!-- ============================================================ -->
        <?php if ($view === 'tables'): ?>
        <div class="content-header">
            <h1>📋 ALL DATABASE TABLES</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></div>
            <a href="?view=dashboard" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px; text-decoration: none; color: #001B44; font-size: 0.7rem; font-weight: 600;">← Back</a>
        </div>

        <div class="search-bar" style="margin-bottom: 20px;">
            <form method="GET" style="display: flex; gap: 10px; flex: 1; flex-wrap: wrap; align-items: center;">
                <input type="hidden" name="view" value="tables">
                <select name="search_table">
                    <option value="">All Tables</option>
                    <?php foreach ($tablesToFetch as $table => $config): ?>
                    <option value="<?php echo $table; ?>" <?php echo $searchTable === $table ? 'selected' : ''; ?>>
                        <?php echo $config['label']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="search" placeholder="Search across selected table..." value="<?php echo safeHtml($search); ?>">
                <button type="submit">🔍 SEARCH</button>
                <?php if ($search): ?>
                <a href="?view=tables" style="padding: 10px 20px; border: 2px solid #999; color: #666; text-decoration: none; border-radius: 4px;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php foreach ($tableData as $table => $data): ?>
        <div class="table-card" id="table-<?php echo $table; ?>">
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
                <div class="actions">
                    <?php if (hasPermission('export_pdf') && $data['count'] > 0): ?>
                    <a href="?export=<?php echo $table; ?>&export_id=all" class="btn btn-pdf">📄 PDF</a>
                    <a href="?export=<?php echo $table; ?>&format=csv" class="btn btn-csv">📊 CSV</a>
                    <?php endif; ?>
                    <span class="toggle-icon" id="icon_<?php echo $table; ?>">▼</span>
                </div>
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
            <a href="?view=dashboard" style="padding: 8px 16px; border: 2px solid #001B44; border-radius: 4px; text-decoration: none; color: #001B44; font-size: 0.7rem; font-weight: 600;">← Back</a>
        </div>

        <div class="search-bar">
            <form method="GET" style="display: flex; gap: 10px; flex: 1; flex-wrap: wrap; align-items: center;">
                <input type="hidden" name="view" value="search">
                <select name="search_table">
                    <option value="">All Tables</option>
                    <?php foreach ($tablesToFetch as $table => $config): ?>
                    <option value="<?php echo $table; ?>" <?php echo $searchTable === $table ? 'selected' : ''; ?>>
                        <?php echo $config['label']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="search" placeholder="Search by ID, User, Phone, Email, Status, Currency..." 
                       value="<?php echo safeHtml($search); ?>" style="flex: 2;">
                <button type="submit">🔍 SEARCH</button>
                <?php if ($search): ?>
                <a href="?view=search" style="padding: 10px 20px; border: 2px solid #999; color: #666; text-decoration: none; border-radius: 4px;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($search): ?>
        <?php 
        $foundAny = false;
        foreach ($tableData as $table => $data):
            if ($data['count'] > 0):
                $foundAny = true;
        ?>
        <div class="table-card">
            <div class="table-card-header" onclick="toggleTable('<?php echo $table; ?>')">
                <span class="title">
                    <?php echo safeHtml($data['label'] ?? $table); ?>
                    <span class="badge"><?php echo number_format($data['count']); ?> results</span>
                </span>
                <div class="actions">
                    <?php if (hasPermission('export_pdf') && $data['count'] > 0): ?>
                    <a href="?export=<?php echo $table; ?>&export_id=all" class="btn btn-pdf">📄 PDF</a>
                    <a href="?export=<?php echo $table; ?>&format=csv" class="btn btn-csv">📊 CSV</a>
                    <?php endif; ?>
                    <span class="toggle-icon" id="icon_<?php echo $table; ?>">▼</span>
                </div>
            </div>
            <div class="table-card-body open" id="body_<?php echo $table; ?>">
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
                            <?php foreach (array_slice($data['rows'], 0, 50) as $row): ?>
                            <tr>
                                <?php 
                                $colCount = 0;
                                foreach ($row as $key => $value):
                                    if ($colCount++ >= 8) break;
                                    $display = is_string($value) ? substr($value, 0, 50) : (string)$value;
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
                            <?php if ($data['count'] > 50): ?>
                            <tr><td colspan="9" style="text-align:center; color:#999; font-size:0.65rem;">
                                ... and <?php echo number_format($data['count'] - 50); ?> more results
                            </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; endforeach; ?>
        
        <?php if (!$foundAny): ?>
        <div class="table-card">
            <div class="table-card-body open" style="padding: 40px; text-align: center; color: #999;">
                <div class="icon" style="font-size: 3rem;">🔍</div>
                <p>No results found for "<?php echo safeHtml($search); ?>"</p>
                <p style="font-size: 0.8rem; margin-top: 8px;">Try searching in a specific table or using different keywords.</p>
            </div>
        </div>
        <?php endif; ?>
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

        // Auto-expand tables with data
        <?php if ($view === 'tables' || $view === 'search'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($tableData as $table => $data): ?>
            <?php if ($data['count'] > 0): ?>
            setTimeout(function() {
                const body = document.getElementById('body_<?php echo $table; ?>');
                const icon = document.getElementById('icon_<?php echo $table; ?>');
                if (body) body.classList.add('open');
                if (icon) icon.classList.add('open');
            }, 200);
            <?php endif; ?>
            <?php endforeach; ?>
        });
        <?php endif; ?>
    </script>
</body>
</html>
