<?php
/**
 * Work Control Dashboard - System Monitoring
 */

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load configuration
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
require_once $configPath;
$config = \Core\Config\LoadCountry::getConfig();

require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// Database connection
if (isset($config['db']['swap'])) {
    $dbConfig = $config['db']['swap'];
} else {
    $databaseUrl = getenv('DATABASE_URL');
    $db = parse_url($databaseUrl);
    $dbConfig = [
        'host' => $db['host'] ?? 'localhost',
        'port' => (int)($db['port'] ?? 5432),
        'database' => ltrim($db['path'] ?? '', '/'),
        'username' => $db['user'] ?? 'postgres',
        'password' => $db['pass'] ?? '',
    ];
}
$dbConfig['type'] = 'pgsql';
$db = DBConnection::getInstance($dbConfig);

// ============================================================
// FIXED: Removed all "deleted_at" references - users table doesn't have this column
// ============================================================

// Get data - NO deleted_at column
$users = $db->query("SELECT user_id, phone, email, full_name, created_at, status FROM users LIMIT 10")->fetchAll();
$transactions = $db->query("SELECT swap_id, user_id, amount, status, created_at FROM swap_requests ORDER BY created_at DESC LIMIT 20")->fetchAll();

// Get counts - NO deleted_at condition
$userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$txCount = $db->query("SELECT COUNT(*) FROM swap_requests")->fetchColumn();
$pendingCount = $db->query("SELECT COUNT(*) FROM swap_requests WHERE status = 'pending'")->fetchColumn();
$completedCount = $db->query("SELECT COUNT(*) FROM swap_requests WHERE status = 'completed'")->fetchColumn();
$failedCount = $db->query("SELECT COUNT(*) FROM swap_requests WHERE status = 'failed'")->fetchColumn();

// Get admin count - admins table DOES have deleted_at
$adminCount = $db->query("SELECT COUNT(*) FROM admins WHERE deleted_at IS NULL")->fetchColumn();

$countryCode = $config['country_code'] ?? 'BW';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';
$countryName = $config['country'] ?? 'Botswana';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · WORK CONTROL</title>
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
            padding: 24px;
        }

        .dashboard {
            max-width: 1600px;
            margin: 0 auto;
        }

        /* HEADER */
        .admin-header {
            background: #001B44;
            border-bottom: 5px solid #FFDA63;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .logo {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 2px;
            color: #fff;
        }

        .logo span {
            color: #FFDA63;
        }

        .country-badge {
            padding: 5px 15px;
            background: rgba(255, 218, 99, 0.2);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .back-btn {
            padding: 8px 16px;
            background: transparent;
            border: 2px solid #FFDA63;
            color: #FFDA63;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: #FFDA63;
            color: #001B44;
        }

        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            box-shadow: 4px 4px 0 #A1B5D8;
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #001B44;
        }

        .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #666;
            margin-top: 8px;
        }

        /* CARDS */
        .card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #001B44;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 1px;
        }

        .card-badge {
            background: #001B44;
            color: #fff;
            padding: 3px 10px;
            font-size: 0.7rem;
        }

        /* TABLES */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        th {
            background: #001B44;
            color: #fff;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
        }

        .status-completed {
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

        .status-active {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        /* FOOTER */
        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 20px 30px;
            font-size: 0.7rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
            margin-top: 30px;
        }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            body { padding: 16px; }
            .admin-header { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Header -->
        <div class="admin-header">
            <div class="logo">VOUCHMORPH <span>WORK CONTROL</span></div>
            <div class="country-badge"><?php echo htmlspecialchars($countryCode); ?> · <?php echo htmlspecialchars($countryName); ?></div>
            <a href="admin_dashboard.php" class="back-btn">← BACK TO DASHBOARD</a>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($userCount); ?></div>
                <div class="stat-label">TOTAL USERS</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($txCount); ?></div>
                <div class="stat-label">TOTAL TRANSACTIONS</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($pendingCount); ?></div>
                <div class="stat-label">PENDING</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($completedCount); ?></div>
                <div class="stat-label">COMPLETED</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($failedCount); ?></div>
                <div class="stat-label">FAILED</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($adminCount); ?></div>
                <div class="stat-label">ACTIVE ADMINS</div>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card">
            <div class="card-header">
                <span>📋 RECENT USERS</span>
                <span class="card-badge">LAST 10</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Full Name</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="6" style="text-align: center;">No users found</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($user['full_name'] ?? 'N/A'); ?></td>
                                <td><span class="status-badge status-<?php echo htmlspecialchars($user['status'] ?? 'active'); ?>"><?php echo strtoupper($user['status'] ?? 'ACTIVE'); ?></span></td>
                                <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="card">
            <div class="card-header">
                <span>💸 RECENT TRANSACTIONS</span>
                <span class="card-badge">LAST 20</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Swap ID</th>
                            <th>User ID</th>
                            <th>Amount (<?php echo htmlspecialchars($currencySymbol); ?>)</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="5" style="text-align: center;">No transactions found</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tx['swap_id']); ?></td>
                                <td><?php echo htmlspecialchars($tx['user_id']); ?></td>
                                <td><?php echo number_format($tx['amount'], 2); ?></td>
                                <td><span class="status-badge status-<?php echo htmlspecialchars($tx['status']); ?>"><?php echo strtoupper($tx['status']); ?></span></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="admin-footer">
            <p>VOUCHMORPH · <?php echo htmlspecialchars($countryName); ?> · WORK CONTROL DASHBOARD</p>
            <p style="margin-top: 5px;">Real-time system monitoring</p>
        </div>
    </div>
</body>
</html>
