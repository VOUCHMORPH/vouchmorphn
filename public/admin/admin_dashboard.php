<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Define project root
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load configuration using the new system
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
if (!file_exists($configPath)) {
    die("Configuration system not found.");
}

require_once $configPath;

try {
    $config = \Core\Config\LoadCountry::getConfig();
    if (!is_array($config)) {
        die("Configuration failed to load.");
    }
} catch (Throwable $e) {
    die("Config error: " . $e->getMessage());
}

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

// Initialize database connection
try {
    if (isset($config['db']['swap']) && is_array($config['db']['swap'])) {
        $dbConfig = $config['db']['swap'];
    } else {
        $databaseUrl = getenv('DATABASE_URL');
        if ($databaseUrl) {
            $db = parse_url($databaseUrl);
            $dbConfig = [
                'host' => $db['host'] ?? 'localhost',
                'port' => (int)($db['port'] ?? 5432),
                'database' => ltrim($db['path'] ?? '', '/'),
                'username' => $db['user'] ?? 'postgres',
                'password' => $db['pass'] ?? '',
            ];
        } else {
            $dbConfig = [
                'host' => getenv('DB_HOST') ?: 'localhost',
                'port' => (int)(getenv('DB_PORT') ?: 5432),
                'database' => getenv('DB_NAME') ?: 'swap_system_bw',
                'username' => getenv('DB_USER') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
            ];
        }
    }
    
    $dbConfig['type'] = 'pgsql';
    $dbConfig['options'] = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    
    $db = DBConnection::getInstance($dbConfig);
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] DB Error: " . $e->getMessage());
    die("Database connection failed.");
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
        999 => ['all'], // Super Admin
        3 => ['view_dashboard', 'view_reports', 'audit_logs', 'compliance_checks'], // Regulator
        4 => ['view_dashboard', 'view_reports', 'manage_compliance', 'review_transactions', 'kyc_verification'], // Compliance
        5 => ['view_dashboard', 'view_reports', 'audit_logs', 'read_only'] // Auditor
    ];
    
    $userPerms = $permissions[$adminRoleId] ?? [];
    return in_array('all', $userPerms) || in_array($permission, $userPerms);
};

// Get system metrics - FIXED: proper data types
$metrics = [];
try {
    // Get today's transaction count
    $stmt = $db->prepare("SELECT COUNT(*) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $metrics['today_transactions'] = (int)$stmt->fetchColumn();
    
    // Get today's volume (keep as float for calculations, string for display)
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $volumeRaw = (float)$stmt->fetchColumn();
    $metrics['today_volume_raw'] = $volumeRaw;
    $metrics['today_volume'] = number_format($volumeRaw, 2);
    
    // Get active holds
    $stmt = $db->prepare("SELECT COUNT(*) FROM hold_transactions WHERE status = 'ACTIVE'");
    $stmt->execute();
    $metrics['active_holds'] = (int)$stmt->fetchColumn();
    
    // Get pending settlements
    $stmt = $db->prepare("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'");
    $stmt->execute();
    $metrics['pending_settlements'] = (int)$stmt->fetchColumn();
    
    // Get total users
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
    $stmt->execute();
    $metrics['total_users'] = (int)$stmt->fetchColumn();
    
    // Get total swap volume (all time)
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM swap_requests");
    $stmt->execute();
    $totalVolumeRaw = (float)$stmt->fetchColumn();
    $metrics['total_volume'] = number_format($totalVolumeRaw, 2);
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = [
        'today_transactions' => 0,
        'today_volume' => '0.00',
        'today_volume_raw' => 0,
        'active_holds' => 0,
        'pending_settlements' => 0,
        'total_users' => 0,
        'total_volume' => '0.00'
    ];
}

// Get recent transactions
$recentTransactions = [];
try {
    $stmt = $db->prepare("
        SELECT swap_id, user_id, amount, status, created_at 
        FROM swap_requests 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recentTransactions = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Recent transactions error: " . $e->getMessage());
}

// Get current view
$view = $_GET['view'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · ADMIN DASHBOARD · <?php echo htmlspecialchars($countryCode); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #f7f9fc;
            color: #001B44;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
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

        .logo span {
            color: #FFDA63;
            margin-left: 10px;
            font-size: 0.8rem;
        }

        .country-badge {
            padding: 5px 15px;
            background: rgba(255, 218, 99, 0.2);
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

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: #FFDA63;
        }

        .user-role {
            font-size: 0.7rem;
            color: #A1B5D8;
            text-transform: uppercase;
        }

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

        .admin-nav {
            background: #fff;
            border-bottom: 2px solid #001B44;
            padding: 0 30px;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
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

        .nav-item:hover {
            color: #001B44;
        }

        .nav-item.active {
            color: #001B44;
            border-bottom-color: #FFDA63;
        }

        .admin-content {
            flex: 1;
            padding: 30px;
        }

        .content-header {
            margin-bottom: 30px;
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

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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

        .metric-card:hover {
            transform: translateY(-2px);
        }

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

        .table-responsive {
            overflow-x: auto;
        }

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
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }

        tr:hover {
            background: #f5f5f5;
        }

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

        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 20px 30px;
            font-size: 0.7rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
        }

        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
            .admin-nav {
                padding: 0 15px;
                gap: 15px;
            }
            .admin-content {
                padding: 20px;
            }
            .admin-header {
                padding: 15px;
            }
            .header-left {
                gap: 15px;
            }
            .metric-value {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="country-badge"><?php echo htmlspecialchars($countryCode); ?> · <?php echo htmlspecialchars($countryName); ?></div>
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
    </nav>

    <main class="admin-content">
        <?php if ($view === 'dashboard'): ?>
        <div class="content-header">
            <h1>EXECUTIVE DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?> · <?php echo htmlspecialchars($countryName); ?> Time</div>
        </div>

        <!-- Metrics Grid - FIXED: No double number_format() -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">TODAY'S TRANSACTIONS</div>
                <div class="metric-value"><?php echo number_format($metrics['today_transactions']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TODAY'S VOLUME (<?php echo htmlspecialchars($currencySymbol); ?>)</div>
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
                <div class="metric-label">TOTAL VOLUME (<?php echo htmlspecialchars($currencySymbol); ?>)</div>
                <div class="metric-value"><?php echo $metrics['total_volume']; ?></div>
            </div>
        </div>

        <div class="grid-2">
            <!-- Participants Overview -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">PARTICIPANTS</span>
                    <span class="card-badge"><?php echo count($participants); ?> ACTIVE</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
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
                                <td><?php echo htmlspecialchars($code); ?></td>
                                <td><?php echo htmlspecialchars($type); ?></td>
                                <td><span class="status status-success"><?php echo htmlspecialchars($status); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($participants) === 0): ?>
                                <tr><td colspan="3" style="text-align: center;">No participants configured</td><tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">RECENT TRANSACTIONS</span>
                    <span class="card-badge">LAST 10</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $tx): ?>
                            <tr>
                                <td><?php echo $tx['swap_id']; ?></td>
                                <td><?php echo htmlspecialchars($currencySymbol); ?> <?php echo number_format($tx['amount'], 2); ?></td>
                                <td><span class="status status-<?php echo strtolower($tx['status']) === 'completed' ? 'success' : 'pending'; ?>"><?php echo htmlspecialchars($tx['status']); ?></span></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recentTransactions)): ?>
                                <tr><td colspan="4" style="text-align: center;">No transactions yet</td><tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- System Health -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">SYSTEM HEALTH</span>
                <span class="card-badge">LIVE</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <div>
                    <strong>Country:</strong> <?php echo htmlspecialchars($countryName); ?> (<?php echo htmlspecialchars($countryCode); ?>)
                </div>
                <div>
                    <strong>Environment:</strong> <?php echo htmlspecialchars(getenv('APP_ENV') ?: 'production'); ?>
                </div>
                <div>
                    <strong>Database:</strong> <span style="color: green;">✓ Connected</span>
                </div>
                <div>
                    <strong>PHP Version:</strong> <?php echo phpversion(); ?>
                </div>
                <div>
                    <strong>Server Time:</strong> <?php echo date('Y-m-d H:i:s'); ?>
                </div>
                <div>
                    <strong>Admin Role:</strong> <?php echo htmlspecialchars($roleName); ?>
                </div>
            </div>
        </div>

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
                    <a href="reports/daily.php?country=<?php echo $countryCode; ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Transaction Audit Log</span>
                </div>
                <p>7-year audit trail of all swap transactions</p>
                <p style="margin-top: 15px;">
                    <a href="reports/audit_trails.php?country=<?php echo $countryCode; ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Suspicious Activity Report</span>
                </div>
                <p>AML/KYC compliance and fraud monitoring</p>
                <p style="margin-top: 15px;">
                    <a href="reports/suspicious.php?country=<?php echo $countryCode; ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Monthly Reconciliation</span>
                </div>
                <p>Monthly financial reconciliation report</p>
                <p style="margin-top: 15px;">
                    <a href="reports/monthly.php?country=<?php echo $countryCode; ?>" target="_blank" style="color: #001B44;">Generate Report →</a>
                </p>
            </div>
        </div>

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
                <p>Current Country: <strong><?php echo htmlspecialchars($countryName); ?></strong></p>
                <p>Currency: <strong><?php echo htmlspecialchars($currencySymbol); ?></strong></p>
                <p>Timezone: <strong>Africa/Gaborone</strong></p>
                <p style="margin-top: 15px;">
                    <a href="../../src/Core/Config/Countries/<?php echo $countryCode; ?>/config.php" style="color: #001B44;">Edit Config →</a>
                </p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Database Status</span>
                </div>
                <p>Connection: <span style="color: green;">Active</span></p>
                <p>Type: PostgreSQL</p>
                <p>Database: <?php echo htmlspecialchars($dbConfig['database'] ?? 'N/A'); ?></p>
            </div>
        </div>

        <?php elseif ($view === 'transactions' && $hasAccess('review_transactions')): ?>
        <div class="content-header">
            <h1>TRANSACTION MANAGEMENT</h1>
            <div class="timestamp">Monitor and Review Transactions</div>
        </div>
        <div class="card">
            <div class="card-header">
                <span class="card-title">All Transactions</span>
                <span class="card-badge">SWAP REQUESTS</span>
            </div>
            <div class="table-responsive">
                <?php
                $txStmt = $db->query("SELECT * FROM swap_requests ORDER BY created_at DESC LIMIT 50");
                $allTransactions = $txStmt->fetchAll();
                ?>
                <table>
                    <thead>
                        <tr><th>ID</th><th>User</th><th>Amount</th><th>Status</th><th>Created At</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allTransactions as $tx): ?>
                        <tr>
                            <td><?php echo $tx['swap_id']; ?></td>
                            <td><?php echo $tx['user_id']; ?></td>
                            <td><?php echo htmlspecialchars($currencySymbol); ?> <?php echo number_format($tx['amount'], 2); ?></td>
                            <td><span class="status status-<?php echo strtolower($tx['status']) === 'completed' ? 'success' : 'pending'; ?>"><?php echo htmlspecialchars($tx['status']); ?></span></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($view === 'audit' && $hasAccess('audit_logs')): ?>
        <div class="content-header">
            <h1>AUDIT LOGS</h1>
            <div class="timestamp">System Audit Trail</div>
        </div>
        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Activities</span>
                <span class="card-badge">ADMIN ACTIONS</span>
            </div>
            <div class="table-responsive">
                <?php
                $auditStmt = $db->query("SELECT * FROM admin_actions ORDER BY created_at DESC LIMIT 50");
                $auditLogs = $auditStmt->fetchAll();
                ?>
                <table>
                    <thead>
                        <tr><th>Action</th><th>Entity</th><th>Status</th><th>Admin</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['action_type'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($log['entity_type'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($log['status'] ?? 'N/A'); ?></td>
                            <td><?php echo $log['assigned_admin_id']; ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($log['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <div class="content-header">
            <h1><?php echo ucfirst($view); ?></h1>
            <div class="timestamp">Module under development</div>
        </div>
        <div class="card">
            <p>This module is currently being developed. Please check back later.</p>
        </div>
        <?php endif; ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · <?php echo htmlspecialchars($countryName); ?> · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>
</body>
</html>
