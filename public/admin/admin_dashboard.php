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

// Get system metrics
$metrics = [];
try {
    // Get today's transaction count
    $stmt = $db->prepare("SELECT COUNT(*) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $metrics['today_transactions'] = $stmt->fetchColumn();
    
    // Get today's volume
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $metrics['today_volume'] = number_format((float)$stmt->fetchColumn(), 2);
    
    // Get active holds
    $stmt = $db->prepare("SELECT COUNT(*) FROM hold_transactions WHERE status = 'ACTIVE'");
    $stmt->execute();
    $metrics['active_holds'] = $stmt->fetchColumn();
    
    // Get pending settlements
    $stmt = $db->prepare("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'");
    $stmt->execute();
    $metrics['pending_settlements'] = $stmt->fetchColumn();
    
} catch (Throwable $e) {
    error_log("[ADMIN DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = [
        'today_transactions' => 0,
        'today_volume' => '0.00',
        'active_holds' => 0,
        'pending_settlements' => 0
    ];
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
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 30px;
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            box-shadow: 4px 4px 0 #A1B5D8;
        }

        .metric-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .metric-value {
            font-size: 2.2rem;
            font-weight: 600;
            color: #001B44;
            line-height: 1.2;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
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
            <a href="?view=admins" class="nav-item <?php echo $view === 'admins' ? 'active' : ''; ?>">ADMINISTRATORS</a>
            <a href="?view=config" class="nav-item <?php echo $view === 'config' ? 'active' : ''; ?>">CONFIGURATION</a>
        <?php endif; ?>
    </nav>

    <main class="admin-content">
        <?php if ($view === 'dashboard'): ?>
        <div class="content-header">
            <h1>EXECUTIVE DASHBOARD</h1>
            <div class="timestamp"><?php echo date('Y-m-d H:i:s'); ?> · <?php echo htmlspecialchars($countryName); ?> Time</div>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">TODAY'S TRANSACTIONS</div>
                <div class="metric-value"><?php echo number_format($metrics['today_transactions']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TODAY'S VOLUME (<?php echo htmlspecialchars($config['currency'] ?? 'BWP'); ?>)</div>
                <div class="metric-value"><?php echo number_format($metrics['today_volume'], 2); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">ACTIVE HOLDS</div>
                <div class="metric-value"><?php echo number_format($metrics['active_holds']); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">PENDING SETTLEMENTS</div>
                <div class="metric-value"><?php echo number_format($metrics['pending_settlements']); ?></div>
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
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">SYSTEM INFORMATION</span>
                    <span class="card-badge">LIVE</span>
                </div>
                <div style="padding: 20px;">
                    <p><strong>Country:</strong> <?php echo htmlspecialchars($countryName); ?> (<?php echo htmlspecialchars($countryCode); ?>)</p>
                    <p><strong>Environment:</strong> <?php echo htmlspecialchars(getenv('APP_ENV') ?: 'production'); ?></p>
                    <p><strong>Database:</strong> Connected</p>
                    <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                    <p><strong>Server Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
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
                <p style="margin-top: 15px;"><a href="reports/daily_settlement.php" target="_blank">Generate Report →</a></p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Transaction Audit Log</span>
                </div>
                <p>7-year audit trail of all swap transactions</p>
                <p style="margin-top: 15px;"><a href="reports/transaction_audit.php" target="_blank">Generate Report →</a></p>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Compliance Report</span>
                </div>
                <p>AML/KYC compliance summary</p>
                <p style="margin-top: 15px;"><a href="reports/compliance.php" target="_blank">Generate Report →</a></p>
            </div>
        </div>
        <?php else: ?>
        <div class="content-header">
            <h1><?php echo ucfirst($view); ?></h1>
            <div class="timestamp">Module under development</div>
        </div>
        <?php endif; ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · <?php echo htmlspecialchars($countryName); ?> · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>
</body>
</html>
