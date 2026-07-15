<?php
/**
 * admin_dashboard_debug.php - Admin Dashboard with Debug Mode
 * Shows table status and record counts for all tables
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
    error_log("[ADMIN DASHBOARD] Database connected successfully");
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] DB Error: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// ============================================================
// DEBUG: TABLE SCHEMA CHECK
// ============================================================
$tables = [
    'audit_logs',
    'hold_transactions',
    'swap_requests',
    'users',
    'admins',
    'cashout_authorizations',
    'identity_swap_holds',
    'fee_invoices',
    'settlement_queue',
    'settlement_outbox',
    'swap_ledgers',
    'swap_fee_collections',
    'net_positions',
    'deposit_transactions',
    'swap_vouchers',
    'cross_border_messages',
    'admin_actions',
    'organization_audit_logs',
    'regulatory_reports',
    'participants',
    'departments',
    'organization_users'
];

$tableStatus = [];
$totalRecords = 0;

foreach ($tables as $table) {
    try {
        // Check if table exists
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = :table
        ");
        $stmt->execute([':table' => $table]);
        $exists = (int)$stmt->fetchColumn() > 0;
        
        if ($exists) {
            // Get record count
            $countStmt = $db->query("SELECT COUNT(*) FROM " . $table);
            $count = (int)$countStmt->fetchColumn();
            $totalRecords += $count;
            
            // Get column list
            $colStmt = $db->prepare("
                SELECT column_name, data_type 
                FROM information_schema.columns 
                WHERE table_schema = 'public' AND table_name = :table
                ORDER BY column_name
            ");
            $colStmt->execute([':table' => $table]);
            $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get last record if exists
            $lastRecord = null;
            if ($count > 0) {
                try {
                    $lastStmt = $db->query("SELECT * FROM " . $table . " ORDER BY created_at DESC LIMIT 1");
                    $lastRecord = $lastStmt->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    // Try with id or other column
                    try {
                        $lastStmt = $db->query("SELECT * FROM " . $table . " ORDER BY id DESC LIMIT 1");
                        $lastRecord = $lastStmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Throwable $e2) {
                        $lastRecord = null;
                    }
                }
            }
            
            $tableStatus[$table] = [
                'exists' => true,
                'count' => $count,
                'columns' => $columns,
                'last_record' => $lastRecord
            ];
        } else {
            $tableStatus[$table] = [
                'exists' => false,
                'count' => 0,
                'columns' => [],
                'last_record' => null
            ];
        }
    } catch (Throwable $e) {
        $tableStatus[$table] = [
            'exists' => false,
            'count' => 0,
            'columns' => [],
            'last_record' => null,
            'error' => $e->getMessage()
        ];
    }
}

// ============================================================
// GET RECENT DATA FROM KEY TABLES
// ============================================================
$recentAuditLogs = [];
try {
    $stmt = $db->query("
        SELECT 
            audit_id,
            audit_uuid,
            entity_type,
            entity_id,
            action,
            category,
            severity,
            performed_at,
            ip_address,
            performed_by_type,
            performed_by_id
        FROM audit_logs 
        ORDER BY performed_at DESC 
        LIMIT 20
    ");
    $recentAuditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Audit logs query error: " . $e->getMessage());
    $recentAuditLogs = [];
}

$recentHolds = [];
try {
    $stmt = $db->query("
        SELECT 
            hold_id,
            hold_reference,
            swap_reference,
            participant_name,
            asset_type,
            amount,
            currency,
            status,
            source_institution,
            destination_institution,
            placed_at,
            created_at
        FROM hold_transactions 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $recentHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Holds query error: " . $e->getMessage());
    $recentHolds = [];
}

// Get view
$view = $_GET['view'] ?? 'dashboard';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

// ============================================================
// DEBUG: Check if audit_logs has records by trying to insert a test record
// ============================================================
$auditWriteTest = false;
if (isset($db)) {
    try {
        $testUuid = 'TEST_' . uniqid();
        $stmt = $db->prepare("
            INSERT INTO audit_logs (
                audit_uuid, 
                entity_type, 
                entity_id, 
                action, 
                category, 
                severity,
                performed_by_type,
                performed_by_id,
                ip_address,
                performed_at
            ) VALUES (
                :uuid,
                'test',
                0,
                'TEST_ENTRY',
                'debug',
                'INFO',
                'system',
                0,
                '127.0.0.1',
                NOW()
            )
        ");
        $stmt->execute([':uuid' => $testUuid]);
        $auditWriteTest = true;
        
        // Delete test record
        $db->prepare("DELETE FROM audit_logs WHERE audit_uuid = :uuid")->execute([':uuid' => $testUuid]);
    } catch (Throwable $e) {
        $auditWriteTest = false;
        error_log("[ADMIN DASHBOARD] Audit write test failed: " . $e->getMessage());
    }
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
        .debug-badge {
            padding: 5px 15px;
            background: rgba(255,0,0,0.2);
            border: 1px solid #ff4444;
            color: #ff4444;
            font-size: 0.8rem;
            text-transform: uppercase;
            font-weight: 700;
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
        .debug-link {
            color: #ff4444 !important;
            border-bottom-color: #ff4444 !important;
        }
        
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
        
        /* Debug Cards */
        .debug-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .debug-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            box-shadow: 4px 4px 0 #A1B5D8;
        }
        .debug-card .status {
            display: inline-block;
            padding: 2px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 3px;
        }
        .debug-card .status-exists {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .debug-card .status-missing {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .debug-card .status-empty {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        .debug-card .table-name {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        .debug-card .count {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            overflow: hidden;
            margin-bottom: 30px;
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
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .empty-state .icon {
            font-size: 3rem;
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            .debug-grid { grid-template-columns: 1fr; }
            .admin-nav { padding: 0 15px; gap: 15px; }
            .admin-content { padding: 20px; }
            .admin-header { padding: 15px; }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="country-badge">BOTSWANA</div>
            <?php if ($debug): ?>
            <div class="debug-badge">🔍 DEBUG MODE</div>
            <?php endif; ?>
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
        <a href="?view=debug&debug=1" class="nav-item <?php echo $debug ? 'active debug-link' : ''; ?>">🔍 DEBUG</a>
        <a href="?view=audit" class="nav-item <?php echo $view === 'audit' ? 'active' : ''; ?>">AUDIT LOGS</a>
        <a href="?view=holds" class="nav-item <?php echo $view === 'holds' ? 'active' : ''; ?>">HOLDS</a>
        <?php if ($adminRoleId === 999): ?>
        <a href="admin_management.php" class="nav-item">ADMINISTRATORS</a>
        <?php endif; ?>
    </nav>

    <main class="admin-content">
        <?php if ($view === 'debug' || $debug): ?>
        <!-- ============================================================ -->
        <!-- DEBUG VIEW - Shows all tables and their status -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>🔍 DATABASE DEBUG</h1>
            <div class="timestamp">Table status and record counts</div>
        </div>

        <!-- Summary -->
        <div class="debug-grid">
            <div class="debug-card">
                <div class="table-name">📊 Total Tables</div>
                <div class="count"><?php echo count(array_filter($tableStatus, fn($t) => $t['exists'])); ?>/<?php echo count($tables); ?></div>
                <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                    <?php echo count(array_filter($tableStatus, fn($t) => !$t['exists'])); ?> tables missing
                </div>
            </div>
            <div class="debug-card">
                <div class="table-name">📝 Total Records</div>
                <div class="count"><?php echo number_format($totalRecords); ?></div>
                <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                    Across all existing tables
                </div>
            </div>
            <div class="debug-card">
                <div class="table-name">🔐 Audit Write Test</div>
                <div class="count" style="font-size: 1.5rem;">
                    <?php if ($auditWriteTest): ?>
                    <span style="color: green;">✅ PASSED</span>
                    <?php else: ?>
                    <span style="color: red;">❌ FAILED</span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                    <?php echo $auditWriteTest ? 'Can insert into audit_logs' : 'Cannot write to audit_logs - check permissions'; ?>
                </div>
            </div>
        </div>

        <!-- Table Status Grid -->
        <div class="debug-grid">
            <?php foreach ($tableStatus as $table => $status): ?>
            <div class="debug-card">
                <div class="table-name">
                    <?php echo htmlspecialchars($table); ?>
                    <?php if ($status['exists']): ?>
                    <span class="status status-exists">EXISTS</span>
                    <?php else: ?>
                    <span class="status status-missing">MISSING</span>
                    <?php endif; ?>
                </div>
                <div class="count">
                    <?php if ($status['exists']): ?>
                    <?php echo number_format($status['count']); ?>
                    <?php if ($status['count'] === 0): ?>
                    <span class="status status-empty">EMPTY</span>
                    <?php endif; ?>
                    <?php else: ?>
                    —
                    <?php endif; ?>
                </div>
                <div style="font-size: 0.7rem; color: #666; margin-top: 5px;">
                    <?php if ($status['exists']): ?>
                    <?php echo count($status['columns']); ?> columns
                    <?php if (!empty($status['last_record'])): ?>
                    <br>Last record: <?php echo date('Y-m-d H:i', strtotime($status['last_record']['created_at'] ?? $status['last_record']['performed_at'] ?? 'now')); ?>
                    <?php endif; ?>
                    <?php if (isset($status['error'])): ?>
                    <br><span style="color: red;">Error: <?php echo htmlspecialchars($status['error']); ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    Table does not exist
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php elseif ($view === 'audit'): ?>
        <!-- ============================================================ -->
        <!-- AUDIT LOGS VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>📋 AUDIT LOGS</h1>
            <div class="timestamp">System audit trail</div>
            <a href="?view=debug&debug=1" class="nav-item debug-link" style="padding: 8px 16px; border: 2px solid #ff4444; border-radius: 4px;">🔍 Debug Tables</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Audit Entries</span>
                <span class="card-badge"><?php echo count($recentAuditLogs); ?> RECORDS</span>
            </div>
            <?php if (empty($recentAuditLogs)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No audit logs found in the database.</p>
                <p style="font-size: 0.8rem; margin-top: 10px; color: #666;">
                    <?php if ($auditWriteTest): ?>
                    ✅ Database write test passed - logs should appear when actions are performed.
                    <?php else: ?>
                    ❌ Audit write test failed - check database permissions.
                    <?php endif; ?>
                </p>
                <p style="font-size: 0.8rem; margin-top: 5px; color: #666;">
                    Try logging in/out or performing an admin action to generate logs.
                </p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Category</th>
                            <th>Severity</th>
                            <th>Performed By</th>
                            <th>IP</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAuditLogs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($log['audit_id'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['action'] ?? 'N/A')); ?></td>
                            <td>
                                <?php echo htmlspecialchars((string)($log['entity_type'] ?? 'N/A')); ?>
                                <?php if (!empty($log['entity_id'])): ?>
                                <br><small style="color: #999;">ID: <?php echo htmlspecialchars((string)$log['entity_id']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status status-<?php 
                                    $category = strtolower($log['category'] ?? '');
                                    echo $category === 'security' ? 'success' : 'info';
                                ?>">
                                    <?php echo htmlspecialchars((string)($log['category'] ?? 'N/A')); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status status-<?php 
                                    $severity = strtolower($log['severity'] ?? '');
                                    echo $severity === 'critical' ? 'failed' : 'info';
                                ?>">
                                    <?php echo htmlspecialchars((string)($log['severity'] ?? 'N/A')); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $performer = $log['performed_by_type'] ?? '';
                                if ($performer === 'admin') {
                                    echo '👤 Admin #' . htmlspecialchars((string)($log['performed_by_id'] ?? 'N/A'));
                                } elseif ($performer === 'user') {
                                    echo '👤 User #' . htmlspecialchars((string)($log['performed_by_id'] ?? 'N/A'));
                                } elseif ($performer === 'system') {
                                    echo '⚙️ System';
                                } else {
                                    echo htmlspecialchars((string)($performer ?: 'N/A'));
                                }
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars((string)($log['ip_address'] ?? 'N/A')); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($log['performed_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif ($view === 'holds'): ?>
        <!-- ============================================================ -->
        <!-- HOLDS VIEW -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>🔒 HOLD TRANSACTIONS</h1>
            <div class="timestamp">Active and historical holds</div>
            <a href="?view=debug&debug=1" class="nav-item debug-link" style="padding: 8px 16px; border: 2px solid #ff4444; border-radius: 4px;">🔍 Debug Tables</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <span class="card-title">Hold Records</span>
                <span class="card-badge"><?php echo count($recentHolds); ?> RECORDS</span>
            </div>
            <?php if (empty($recentHolds)): ?>
            <div class="empty-state">
                <div class="icon">🔒</div>
                <p>No hold transactions found in the database.</p>
                <p style="font-size: 0.8rem; margin-top: 10px; color: #666;">
                    Holds are created when swaps are processed. Check if any swaps have been executed.
                </p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Hold Ref</th>
                            <th>Swap Ref</th>
                            <th>Participant</th>
                            <th>Asset</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentHolds as $hold): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(substr($hold['hold_reference'] ?? '', 0, 12)); ?></td>
                            <td><?php echo htmlspecialchars(substr($hold['swap_reference'] ?? '', 0, 12)); ?></td>
                            <td><?php echo htmlspecialchars((string)($hold['participant_name'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($hold['asset_type'] ?? 'N/A')); ?></td>
                            <td><?php echo number_format((float)($hold['amount'] ?? 0), 2); ?> <?php echo htmlspecialchars((string)($hold['currency'] ?? 'BWP')); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($hold['status'] ?? '');
                                $class = $status === 'active' || $status === 'held' ? 'success' : ($status === 'released' ? 'info' : 'pending');
                                ?>
                                <span class="status status-<?php echo $class; ?>">
                                    <?php echo htmlspecialchars((string)($hold['status'] ?? 'N/A')); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars((string)($hold['source_institution'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($hold['destination_institution'] ?? 'N/A')); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($hold['created_at'] ?? $hold['placed_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ============================================================ -->
        <!-- DEFAULT DASHBOARD -->
        <!-- ============================================================ -->
        <div class="content-header">
            <h1>EXECUTIVE DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?> · Botswana Time</div>
            <a href="?view=debug&debug=1" class="nav-item debug-link" style="padding: 8px 16px; border: 2px solid #ff4444; border-radius: 4px;">🔍 Debug Mode</a>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📊 System Overview</span>
                <span class="card-badge">LIVE</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; padding: 10px;">
                <?php foreach ($tableStatus as $table => $status): ?>
                <div style="background: #f8f9fa; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="font-weight: 700; font-size: 0.8rem;"><?php echo htmlspecialchars($table); ?></div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: <?php echo $status['exists'] ? ($status['count'] > 0 ? '#155724' : '#856404') : '#721c24'; ?>;">
                        <?php echo $status['exists'] ? number_format($status['count']) : '❌'; ?>
                    </div>
                    <div style="font-size: 0.7rem; color: #666;">
                        <?php echo $status['exists'] ? ($status['count'] > 0 ? 'records found' : 'empty') : 'missing'; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">🔐 Audit Logs Status</span>
                <span class="card-badge"><?php echo count($recentAuditLogs); ?> RECORDS</span>
            </div>
            <?php if (empty($recentAuditLogs)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No audit logs found.</p>
                <p style="font-size: 0.8rem; color: #666;">
                    <?php if ($auditWriteTest): ?>
                    ✅ Audit writes are working. Logs will appear when admin actions are performed.
                    <?php else: ?>
                    ❌ Audit writes are failing. Check database permissions.
                    <?php endif; ?>
                </p>
                <p style="margin-top: 10px;">
                    <a href="?view=audit" style="color: #001B44; font-weight: 600;">View Audit Logs →</a>
                </p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Category</th>
                            <th>Performed By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($recentAuditLogs, 0, 10) as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($log['action'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['entity_type'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['category'] ?? 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars((string)($log['performed_by_type'] ?? 'N/A')); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($log['performed_at'] ?? 'now')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · Botswana · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>
</body>
</html>
