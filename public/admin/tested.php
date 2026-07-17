<?php
/**
 * admin_diagnostic.php - Admin Dashboard Diagnostic Test
 * Tests database, views, tables, and each dashboard section
 * Run this from the browser or command line
 */

// ============================================================
// CONFIGURATION
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

define('PROJECT_ROOT', dirname(__DIR__, 2));

require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;

// Check admin login
if (!SessionManager::isAdminLoggedIn()) {
    // Try to login via session
    if (!isset($_SESSION['admin_id'])) {
        // For CLI or direct access, we need to login
        if (php_sapi_name() !== 'cli') {
            header('Location: admin_login.php');
            exit();
        }
    }
}

// Get admin info
$adminId = SessionManager::getAdminId() ?? $_SESSION['admin_id'] ?? null;
$adminRoleId = SessionManager::getAdminRoleId() ?? $_SESSION['admin_role_id'] ?? null;

// Database connection
try {
    $db = DBConnection::getConnection();
    if (!$db) throw new Exception("Database connection failed");
    $db->query("SELECT 1");
    $dbConnected = true;
} catch (Throwable $e) {
    $dbConnected = false;
    $dbError = $e->getMessage();
}

// ============================================================
// TEST FUNCTIONS
// ============================================================

function testTable($db, $table) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '$table'");
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) {
            // Get actual record count
            $stmt2 = $db->query("SELECT COUNT(*) FROM $table");
            $records = (int)$stmt2->fetchColumn();
            return ['exists' => true, 'records' => $records];
        }
        return ['exists' => false, 'records' => 0];
    } catch (Exception $e) {
        return ['exists' => false, 'records' => 0, 'error' => $e->getMessage()];
    }
}

function testView($db, $view) {
    try {
        $stmt = $db->query("SELECT EXISTS (SELECT 1 FROM pg_views WHERE viewname = '$view')");
        $exists = (bool)$stmt->fetchColumn();
        if ($exists) {
            $stmt2 = $db->query("SELECT COUNT(*) FROM $view");
            $records = (int)$stmt2->fetchColumn();
            return ['exists' => true, 'records' => $records];
        }
        return ['exists' => false, 'records' => 0];
    } catch (Exception $e) {
        return ['exists' => false, 'records' => 0, 'error' => $e->getMessage()];
    }
}

function testTableColumns($db, $table) {
    try {
        $stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '$table' ORDER BY ordinal_position");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

function checkAdminLogin() {
    return SessionManager::isAdminLoggedIn() || isset($_SESSION['admin_id']);
}

function getAdminInfo() {
    return [
        'id' => SessionManager::getAdminId() ?? $_SESSION['admin_id'] ?? null,
        'role_id' => SessionManager::getAdminRoleId() ?? $_SESSION['admin_role_id'] ?? null,
        'username' => SessionManager::getAdminUsername() ?? $_SESSION['admin_username'] ?? 'Unknown'
    ];
}

// ============================================================
// RUN TESTS
// ============================================================

$results = [
    'database' => ['connected' => $dbConnected, 'error' => $dbError ?? null],
    'admin' => ['logged_in' => checkAdminLogin(), 'info' => getAdminInfo()],
    'tables' => [],
    'views' => [],
    'sections' => []
];

if ($dbConnected) {
    // Test Tables
    $tables = [
        'vw_all_swaps',
        'multi_destination_swaps',
        'multi_source_swaps',
        'identity_swap_holds',
        'hold_transactions',
        'settlement_queue',
        'settlement_outbox',
        'net_positions',
        'audit_logs',
        'swap_requests',
        'swap_transactions',
        'cashout_authorizations',
        'financial_holds'
    ];
    
    foreach ($tables as $table) {
        $results['tables'][$table] = testTable($db, $table);
        if ($results['tables'][$table]['exists']) {
            $results['tables'][$table]['columns'] = testTableColumns($db, $table);
        }
    }
    
    // Test Views
    $views = [
        'vw_all_swaps'
    ];
    
    foreach ($views as $view) {
        $results['views'][$view] = testView($db, $view);
    }
}

// ============================================================
// GENERATE REPORT
// ============================================================

// HTML Output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · ADMIN DIAGNOSTIC</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #0d0d1a;
            color: #e0e0e0;
            min-height: 100vh;
            padding: 24px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #00f0ff; font-size: 1.5rem; margin-bottom: 8px; }
        .subtitle { color: #8888a0; font-size: 0.8rem; margin-bottom: 24px; }
        
        .card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }
        .card-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #00f0ff;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-title .badge {
            font-size: 0.6rem;
            padding: 2px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        .badge-success { background: rgba(0,230,118,0.2); color: #00e676; border: 1px solid rgba(0,230,118,0.3); }
        .badge-failed { background: rgba(255,82,82,0.2); color: #ff5252; border: 1px solid rgba(255,82,82,0.3); }
        .badge-warning { background: rgba(255,193,7,0.2); color: #ffc107; border: 1px solid rgba(255,193,7,0.3); }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
        
        .stat-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.04); font-size: 0.7rem; }
        .stat-row .label { color: #8888a0; }
        .stat-row .value { font-weight: 600; color: #fff; }
        .stat-row .value.green { color: #00e676; }
        .stat-row .value.red { color: #ff5252; }
        .stat-row .value.yellow { color: #ffc107; }
        .stat-row .value.cyan { color: #00f0ff; }
        
        .table-responsive { overflow-x: auto; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.65rem; }
        th { background: rgba(0,240,255,0.08); color: #00f0ff; padding: 6px 10px; text-align: left; font-weight: 600; text-transform: uppercase; font-size: 0.55rem; }
        td { padding: 5px 10px; border-bottom: 1px solid rgba(255,255,255,0.04); }
        .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .status-dot.green { background: #00e676; }
        .status-dot.red { background: #ff5252; }
        .status-dot.yellow { background: #ffc107; }
        .status-dot.cyan { background: #00f0ff; }
        
        .section-result {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
        }
        .section-result.pass { background: rgba(0,230,118,0.15); color: #00e676; }
        .section-result.fail { background: rgba(255,82,82,0.15); color: #ff5252; }
        .section-result.empty { background: rgba(255,193,7,0.15); color: #ffc107; }
        
        .code-block {
            background: rgba(0,0,0,0.3);
            padding: 12px;
            border-radius: 6px;
            font-size: 0.6rem;
            color: #4ade80;
            overflow-x: auto;
            margin-top: 8px;
        }
        
        .footer {
            text-align: center;
            color: #505070;
            font-size: 0.65rem;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.04);
        }
        
        .summary-box {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .summary-item {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 8px;
            padding: 12px 16px;
            text-align: center;
        }
        .summary-item .number { font-size: 1.5rem; font-weight: 700; }
        .summary-item .label { font-size: 0.55rem; color: #8888a0; text-transform: uppercase; margin-top: 4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 ADMIN DASHBOARD DIAGNOSTIC</h1>
    <div class="subtitle">Comprehensive system check · <?php echo date('Y-m-d H:i:s'); ?></div>

    <!-- Summary -->
    <div class="summary-box">
        <?php
        $totalTables = count($results['tables']);
        $existingTables = 0;
        $totalRecords = 0;
        foreach ($results['tables'] as $table) {
            if ($table['exists']) {
                $existingTables++;
                $totalRecords += $table['records'];
            }
        }
        ?>
        <div class="summary-item">
            <div class="number" style="color: <?php echo $dbConnected ? '#00e676' : '#ff5252'; ?>">
                <?php echo $dbConnected ? '✅' : '❌'; ?>
            </div>
            <div class="label">Database</div>
        </div>
        <div class="summary-item">
            <div class="number" style="color: #00f0ff;"><?php echo $existingTables; ?>/<?php echo $totalTables; ?></div>
            <div class="label">Tables Found</div>
        </div>
        <div class="summary-item">
            <div class="number" style="color: #00e676;"><?php echo number_format($totalRecords); ?></div>
            <div class="label">Total Records</div>
        </div>
        <div class="summary-item">
            <div class="number" style="color: <?php echo checkAdminLogin() ? '#00e676' : '#ff5252'; ?>">
                <?php echo checkAdminLogin() ? '✅' : '❌'; ?>
            </div>
            <div class="label">Admin Session</div>
        </div>
    </div>

    <!-- Database Status -->
    <div class="card">
        <div class="card-title">
            <span>📊 Database Connection</span>
            <span class="badge <?php echo $dbConnected ? 'badge-success' : 'badge-failed'; ?>">
                <?php echo $dbConnected ? 'CONNECTED' : 'FAILED'; ?>
            </span>
        </div>
        <?php if ($dbConnected): ?>
        <div class="stat-row">
            <span class="label">Status</span>
            <span class="value green">Connected successfully</span>
        </div>
        <?php else: ?>
        <div class="stat-row">
            <span class="label">Error</span>
            <span class="value red"><?php echo safeHtml($dbError ?? 'Unknown error'); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Admin Session -->
    <div class="card">
        <div class="card-title">
            <span>👤 Admin Session</span>
            <span class="badge <?php echo checkAdminLogin() ? 'badge-success' : 'badge-failed'; ?>">
                <?php echo checkAdminLogin() ? 'LOGGED IN' : 'NOT LOGGED IN'; ?>
            </span>
        </div>
        <?php if (checkAdminLogin()): ?>
        <div class="stat-row">
            <span class="label">Admin ID</span>
            <span class="value"><?php echo safeHtml($results['admin']['info']['id'] ?? 'N/A'); ?></span>
        </div>
        <div class="stat-row">
            <span class="label">Role ID</span>
            <span class="value"><?php echo safeHtml($results['admin']['info']['role_id'] ?? 'N/A'); ?></span>
        </div>
        <div class="stat-row">
            <span class="label">Username</span>
            <span class="value"><?php echo safeHtml($results['admin']['info']['username'] ?? 'N/A'); ?></span>
        </div>
        <?php else: ?>
        <div class="stat-row">
            <span class="label">Status</span>
            <span class="value red">Please login to run full tests</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tables -->
    <div class="card">
        <div class="card-title">
            <span>📋 Database Tables</span>
            <span class="badge badge-info"><?php echo $existingTables; ?> / <?php echo $totalTables; ?> FOUND</span>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Status</th>
                        <th>Records</th>
                        <th>Columns</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['tables'] as $table => $info): ?>
                    <tr>
                        <td><code><?php echo safeHtml($table); ?></code></td>
                        <td>
                            <span class="status-dot <?php echo $info['exists'] ? 'green' : 'red'; ?>"></span>
                            <?php echo $info['exists'] ? '✅ Exists' : '❌ Missing'; ?>
                        </td>
                        <td>
                            <?php if ($info['exists']): ?>
                            <span style="color: #00e676;"><?php echo number_format($info['records']); ?></span>
                            <?php else: ?>
                            <span style="color: #8888a0;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($info['exists'] && !empty($info['columns'])): ?>
                            <?php echo count($info['columns']); ?> columns
                            <span style="color: #8888a0; font-size: 0.55rem;">
                                (<?php echo safeHtml(implode(', ', array_slice($info['columns'], 0, 5))); ?><?php if (count($info['columns']) > 5) echo '...'; ?>)
                            </span>
                            <?php else: ?>
                            <span style="color: #8888a0;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Views -->
    <div class="card">
        <div class="card-title">
            <span>👁️ Database Views</span>
            <span class="badge badge-info">CRITICAL FOR DASHBOARD</span>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>View</th>
                        <th>Status</th>
                        <th>Records</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['views'] as $view => $info): ?>
                    <tr>
                        <td><code><?php echo safeHtml($view); ?></code></td>
                        <td>
                            <span class="status-dot <?php echo $info['exists'] ? 'green' : 'red'; ?>"></span>
                            <?php echo $info['exists'] ? '✅ Exists' : '❌ Missing'; ?>
                        </td>
                        <td>
                            <?php if ($info['exists']): ?>
                            <span style="color: #00e676;"><?php echo number_format($info['records']); ?></span>
                            <?php else: ?>
                            <span style="color: #ff5252;">⚠️ REQUIRED</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!isset($results['views']['vw_all_swaps']) || !$results['views']['vw_all_swaps']['exists']): ?>
        <div class="code-block">
            ⚠️ vw_all_swaps view is MISSING. Run this SQL to create it:<br><br>
            CREATE OR REPLACE VIEW vw_all_swaps AS<br>
            SELECT hold_reference AS reference, swap_reference, 'HOLD' AS swap_type, ...<br>
            FROM hold_transactions<br>
            UNION ALL<br>
            SELECT NULL AS reference, reference AS swap_reference, 'MULTI_DESTINATION' AS swap_type, ...<br>
            FROM multi_destination_swaps;
        </div>
        <?php endif; ?>
    </div>

    <!-- Dashboard Sections -->
    <div class="card">
        <div class="card-title">
            <span>📱 Dashboard Sections</span>
            <span class="badge badge-info">FUNCTIONALITY TEST</span>
        </div>
        
        <?php
        // Test each section by actually fetching the page
        $sections = [
            'dashboard' => 'Dashboard',
            'live_transactions' => 'Live Transactions',
            'multi_destination' => 'Multi-Destination',
            'recent_swaps' => 'Recent Swaps',
            'settlements' => 'Settlements',
            'regulatory' => 'Regulatory',
            'audit' => 'Audit',
            'fee_breakdown' => 'Fee Breakdown',
            'invoices' => 'Invoices'
        ];
        
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/admin/admin_dashboard.php';
        $sessionCookie = session_name() . '=' . session_id();
        
        foreach ($sections as $section => $name):
            $url = $baseUrl . '?view=' . $section;
            
            // Use curl to test the page
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $hasFatal = strpos($response, 'Fatal error') !== false;
            $hasWarning = strpos($response, 'Warning:') !== false;
            $hasEmpty = strpos($response, 'No .* found') !== false || strpos($response, 'empty-state') !== false;
            $hasData = strpos($response, 'RECORDS') !== false && !$hasEmpty;
            
            $status = 'pass';
            $statusText = '✅ Working';
            $statusClass = 'pass';
            if ($hasFatal) {
                $status = 'fail';
                $statusText = '❌ Fatal Error';
                $statusClass = 'fail';
            } elseif ($hasWarning) {
                $status = 'warning';
                $statusText = '⚠️ Warnings';
                $statusClass = 'empty';
            } elseif ($hasEmpty) {
                $status = 'empty';
                $statusText = '📭 Empty (no data)';
                $statusClass = 'empty';
            } elseif ($hasData) {
                $status = 'pass';
                $statusText = '✅ Data Found';
                $statusClass = 'pass';
            }
            $results['sections'][$section] = ['status' => $status, 'http' => $httpCode];
        ?>
        <div class="stat-row">
            <span class="label"><?php echo safeHtml($name); ?></span>
            <span class="value">
                <span class="section-result <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                <?php if ($httpCode > 0): ?>
                <span style="color: #8888a0; font-size: 0.55rem;">(HTTP <?php echo $httpCode; ?>)</span>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Recommendations -->
    <div class="card" style="border-left: 3px solid #00f0ff;">
        <div class="card-title">
            <span>💡 Recommendations</span>
        </div>
        
        <?php
        $issues = [];
        
        if (!$dbConnected) {
            $issues[] = '❌ Database connection failed - check DATABASE_URL environment variable';
        }
        
        if (!isset($results['views']['vw_all_swaps']) || !$results['views']['vw_all_swaps']['exists']) {
            $issues[] = '❌ vw_all_swaps view is MISSING - this is critical for the dashboard';
        }
        
        if (isset($results['views']['vw_all_swaps']) && $results['views']['vw_all_swaps']['exists'] && $results['views']['vw_all_swaps']['records'] == 0) {
            $issues[] = '⚠️ vw_all_swaps view exists but has 0 records - no swap data found';
        }
        
        if (isset($results['tables']['multi_destination_swaps']) && $results['tables']['multi_destination_swaps']['exists'] && $results['tables']['multi_destination_swaps']['records'] == 0) {
            $issues[] = '⚠️ multi_destination_swaps table is empty - no multi-destination data';
        }
        
        if (isset($results['tables']['hold_transactions']) && $results['tables']['hold_transactions']['exists'] && $results['tables']['hold_transactions']['records'] == 0) {
            $issues[] = '⚠️ hold_transactions table is empty - no hold data';
        }
        
        // Check section failures
        foreach ($results['sections'] as $section => $info) {
            if ($info['status'] === 'fail') {
                $issues[] = '❌ ' . ucfirst(str_replace('_', ' ', $section)) . ' section has fatal errors';
            }
        }
        
        if (empty($issues)) {
            echo '<div style="color: #00e676; font-size: 0.9rem;">✅ All systems operational! The dashboard should be working correctly.</div>';
        } else {
            echo '<div style="font-size: 0.7rem;">';
            foreach ($issues as $issue) {
                echo '<div style="padding: 4px 0;">' . $issue . '</div>';
            }
            echo '</div>';
        }
        ?>
    </div>

    <!-- Quick Fix SQL -->
    <div class="card" style="border-left: 3px solid #ffc107;">
        <div class="card-title">
            <span>🔧 Quick Fix SQL</span>
        </div>
        <div class="code-block">
            -- Check if vw_all_swaps exists<br>
            SELECT EXISTS (SELECT 1 FROM pg_views WHERE viewname = 'vw_all_swaps');<br><br>
            
            -- Count records in key tables<br>
            SELECT 'vw_all_swaps' as table_name, COUNT(*) as records FROM vw_all_swaps<br>
            UNION ALL<br>
            SELECT 'multi_destination_swaps', COUNT(*) FROM multi_destination_swaps<br>
            UNION ALL<br>
            SELECT 'hold_transactions', COUNT(*) FROM hold_transactions<br>
            UNION ALL<br>
            SELECT 'settlement_queue', COUNT(*) FROM settlement_queue;
        </div>
    </div>

    <div class="footer">
        VOUCHMORPH Admin Diagnostic · <?php echo date('Y'); ?>
    </div>
</div>

<?php
// Helper function
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
</body>
</html>
