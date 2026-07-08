<?php
// ADMIN_LAYER/reports/audit_trails.php

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';
require_once __DIR__ . '/../../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../../src/Domain/Services/AuditTrailService.php';

use Application\Utils\SessionManager;
use Domain\Services\AuditTrailService;
use Core\Database\DBConnection;

SessionManager::start();

$user = SessionManager::getUser();
$allowedRoles = ['admin', 'GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'AUDITOR'];

// LOG ACCESS ATTEMPT FIRST
if (!$user || !in_array($user['role'] ?? '', $allowedRoles)) {
    error_log("[AUDIT_ACCESS_DENIED] user_id=" . ($user['user_id'] ?? 'unknown') . 
              " role=" . ($user['role'] ?? 'none') . 
              " ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(403);
    echo "<p style='text-align:center;color:red;font-weight:bold;'>Access denied</p>";
    exit;
}

// Get filters from request
$limit = min((int)($_GET['limit'] ?? 100), 500);
$filters = [
    'entity' => $_GET['entity'] ?? null,
    'action' => $_GET['action'] ?? null,
    'category' => $_GET['category'] ?? null,
    'severity' => $_GET['severity'] ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to' => $_GET['date_to'] ?? null,
    'search' => $_GET['search'] ?? null
];
// Remove empty filters
$filters = array_filter($filters);

try {
    $db = DBConnection::getConnection();
    $config = []; // Load from your config system
    
    // Get country from session
    $countryCode = SessionManager::getAdminCountry() ?? 'BW';
    
    // Pass ALL required constructor parameters
    $auditService = new AuditTrailService($db, $config, null, $countryCode);
    
    // Log that someone viewed the audit trail
    $auditService->recordLog(
        'audit_trail',
        null,
        'VIEW',
        'security',
        'INFO',
        null,
        json_encode(['filters' => $filters, 'limit' => $limit]),
        $user['admin_id'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    );
    
    // Get logs with filters
    $logs = $auditService->getAuditLogs($limit, $filters);
    $totalCount = $auditService->getLogCount($filters);
    
} catch (Exception $e) {
    error_log("AuditTrailService error: " . $e->getMessage());
    $logs = [];
    $totalCount = 0;
}

// CSV Export with filters
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_trails_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, [
        'Log ID', 'User/Admin', 'Action', 'Category', 'Severity',
        'Date/Time', 'IP Address', 'Entity', 'Entity ID', 'Details'
    ]);
    
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'] ?? '',
            $log['username'] ?? 'System',
            $log['action'] ?? '',
            $log['category'] ?? '',
            $log['severity'] ?? 'INFO',
            $log['timestamp'] ?? '',
            $log['ip_address'] ?? 'N/A',
            $log['entity'] ?? '',
            $log['entity_id'] ?? '',
            $log['old_value'] ?? $log['new_value'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trails</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style>
        /* Keep your existing styles */
        .dashboard-content { padding: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; max-width: 1400px; margin: 0 auto; }
        .audit-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .audit-header h2 { margin: 0; color: #2c3e50; font-weight: 600; }
        .audit-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 8px 20px; background: #3498db; color: #fff; text-decoration: none; border-radius: 6px; border: none; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s ease; }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3); }
        .btn-refresh { background: #27ae60; }
        .btn-refresh:hover { background: #229954; box-shadow: 0 2px 8px rgba(39, 174, 96, 0.3); }
        .btn-export { background: #f39c12; }
        .btn-export:hover { background: #e67e22; box-shadow: 0 2px 8px rgba(243, 156, 18, 0.3); }
        .btn-reset { background: #95a5a6; }
        .btn-reset:hover { background: #7f8c8d; }
        
        .filter-section { 
            background: #f8f9fa; 
            padding: 20px; 
            border-radius: 10px; 
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 12px; font-weight: 600; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
        .filter-group input, .filter-group select { 
            padding: 8px 12px; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
            font-size: 13px;
            font-family: inherit;
        }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #3498db; }
        .filter-actions { display: flex; align-items: flex-end; gap: 10px; }
        
        .audit-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }
        .stat-item { text-align: center; }
        .stat-item .label { font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .stat-item .value { font-size: 28px; font-weight: bold; color: #2c3e50; margin-top: 5px; }
        .stat-item .value.actions { color: #3498db; }
        .stat-item .value.users { color: #27ae60; }
        .stat-item .value.today { color: #e67e22; }
        
        .report-table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .report-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .report-table th {
            background: #34495e;
            color: white;
            padding: 14px 12px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .report-table td { padding: 12px; border-bottom: 1px solid #ecf0f1; vertical-align: middle; }
        .report-table tr:hover { background: #f8f9fa; }
        
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        
        .timestamp { font-family: 'Courier New', monospace; font-size: 13px; color: #7f8c8d; }
        .ip-address { font-family: 'Courier New', monospace; font-size: 13px; background: #f1f3f5; padding: 2px 8px; border-radius: 4px; display: inline-block; }
        
        .no-data { text-align: center; padding: 50px 20px; color: #7f8c8d; }
        .no-data .icon { font-size: 48px; display: block; margin-bottom: 15px; }
        .no-data h3 { margin: 0 0 10px 0; color: #2c3e50; }
        
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }
        .pagination-info { color: #7f8c8d; font-size: 14px; }
        .pagination-controls { display: flex; gap: 10px; }
        .pagination-controls .btn { padding: 6px 15px; font-size: 13px; }
        
        @media (max-width: 768px) {
            .filter-section { grid-template-columns: 1fr; }
            .audit-header { flex-direction: column; align-items: stretch; }
            .audit-actions { flex-direction: column; width: 100%; }
            .audit-actions .btn { width: 100%; text-align: center; }
            .report-table { font-size: 12px; }
            .report-table th, .report-table td { padding: 8px 6px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-content">
        <div class="audit-header">
            <h2>📋 Audit Trails</h2>
            <div class="audit-actions">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-export">📥 Export CSV</a>
                <button onclick="location.reload()" class="btn btn-refresh">🔄 Refresh</button>
            </div>
        </div>
        
        <!-- Filter Section -->
        <form method="GET" class="filter-section">
            <div class="filter-group">
                <label>Entity</label>
                <input type="text" name="entity" placeholder="e.g., settlement, user" value="<?= htmlspecialchars($_GET['entity'] ?? '') ?>">
            </div>
            <div class="filter-group">
                <label>Action</label>
                <input type="text" name="action" placeholder="e.g., CREATE, UPDATE" value="<?= htmlspecialchars($_GET['action'] ?? '') ?>">
            </div>
            <div class="filter-group">
                <label>Category</label>
                <input type="text" name="category" placeholder="security, financial, admin" value="<?= htmlspecialchars($_GET['category'] ?? '') ?>">
            </div>
            <div class="filter-group">
                <label>Severity</label>
                <select name="severity">
                    <option value="">All</option>
                    <option value="INFO" <?= ($_GET['severity'] ?? '') === 'INFO' ? 'selected' : '' ?>>INFO</option>
                    <option value="WARNING" <?= ($_GET['severity'] ?? '') === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                    <option value="ERROR" <?= ($_GET['severity'] ?? '') === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                    <option value="CRITICAL" <?= ($_GET['severity'] ?? '') === 'CRITICAL' ? 'selected' : '' ?>>CRITICAL</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Date From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
            </div>
            <div class="filter-group">
                <label>Date To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
            </div>
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Search all fields..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            </div>
            <div class="filter-group filter-actions">
                <button type="submit" class="btn" style="background:#3498db;">Apply Filters</button>
                <a href="?" class="btn btn-reset">Reset</a>
            </div>
        </form>
        
        <!-- Statistics -->
        <?php if (!empty($logs)): 
            $totalActions = count($logs);
            $uniqueUsers = count(array_unique(array_column($logs, 'username')));
            $todayActions = count(array_filter($logs, function($log) {
                $date = $log['timestamp'] ?? '';
                return strpos($date, date('Y-m-d')) === 0;
            }));
        ?>
        <div class="audit-stats">
            <div class="stat-item">
                <div class="label">Showing</div>
                <div class="value actions"><?= $totalActions ?></div>
            </div>
            <div class="stat-item">
                <div class="label">Unique Users</div>
                <div class="value users"><?= $uniqueUsers ?></div>
            </div>
            <div class="stat-item">
                <div class="label">Today's Activity</div>
                <div class="value today"><?= $todayActions ?></div>
            </div>
            <div class="stat-item">
                <div class="label">Total Records</div>
                <div class="value" style="color:#8e44ad;"><?= $totalCount ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Audit Table -->
        <div class="report-table-container">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Category</th>
                        <th>Severity</th>
                        <th>Date/Time</th>
                        <th>IP</th>
                        <th>Entity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($logs)): ?>
                        <?php foreach ($logs as $log): 
                            $severity = $log['severity'] ?? 'INFO';
                            $severityClass = match($severity) {
                                'CRITICAL', 'ERROR' => 'danger',
                                'WARNING' => 'warning',
                                'INFO' => 'info',
                                default => 'secondary'
                            };
                        ?>
                            <tr>
                                <td><strong>#<?= htmlspecialchars($log['id'] ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
                                <td><span class="badge badge-<?= $severityClass ?>"><?= htmlspecialchars($log['action'] ?? '') ?></span></td>
                                <td><?= htmlspecialchars($log['category'] ?? '') ?></td>
                                <td><span class="badge badge-<?= $severityClass ?>"><?= htmlspecialchars($severity) ?></span></td>
                                <td class="timestamp"><?= htmlspecialchars($log['timestamp'] ?? '') ?></td>
                                <td><span class="ip-address"><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></span></td>
                                <td><?= htmlspecialchars($log['entity'] ?? '') ?> <?= $log['entity_id'] ? '#' . $log['entity_id'] : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="no-data">
                                    <span class="icon">📭</span>
                                    <h3>No Audit Logs Found</h3>
                                    <p><?= isset($_GET['entity']) || isset($_GET['action']) ? 'Try adjusting your filters.' : 'System activity will appear here once users start interacting with the dashboard.' ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; font-size: 13px; color: #7f8c8d; text-align: center; border: 1px solid #e9ecef;">
            <span>📊 Report generated: <?= date('Y-m-d H:i:s') ?></span>
            <span style="margin: 0 15px;">|</span>
            <span>📝 Showing <?= count($logs ?? []) ?> of <?= $totalCount ?? 0 ?> records</span>
            <span style="margin: 0 15px;">|</span>
            <span>🔒 Secure audit trail - VouchMorph System</span>
        </div>
    </div>
</body>
</html>
