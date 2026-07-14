<?php
// ADMIN_LAYER/reports/audit_trails.php

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';
require_once __DIR__ . '/../../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../../src/Domain/Services/AuditTrailService.php';
require_once __DIR__ . '/../../roles.php'; // Include our enhanced RoleManager

use Application\Utils\SessionManager;
use Domain\Services\AuditTrailService;
use Core\Database\DBConnection;

// Start session
SessionManager::start();

$user = SessionManager::getUser();

// ============================================================
// ROLE CHECK - Using Database Roles (SINGLE SOURCE OF TRUTH)
// ============================================================

$roleManager = new RoleManager();

// Option 1: Check if user has the 'view_audit_logs' permission
if (!currentUserHasPermission('view_audit_logs')) {
    // Log denied access
    error_log("[AUDIT_ACCESS_DENIED] user_id=" . ($user['admin_id'] ?? $user['user_id'] ?? 'unknown') . 
              " role=" . ($user['role'] ?? 'none') . 
              " ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') .
              " - Missing 'view_audit_logs' permission");
    
    http_response_code(403);
    echo "<p style='text-align:center;color:red;font-weight:bold;'>Access denied - Insufficient permissions</p>";
    exit;
}

// Option 2: If you want to check specific roles instead:
// $allowedRoles = $roleManager->getAuditViewerRoles();
// if (!in_array($user['role'] ?? '', $allowedRoles)) { ... }

// ============================================================
// GET FILTERS FROM REQUEST
// ============================================================

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

// ============================================================
// FETCH AUDIT LOGS
// ============================================================

try {
    $db = DBConnection::getConnection();
    $config = []; // Load from your config system
    
    // Get country from session
    $countryCode = SessionManager::getAdminCountry() ?? 'BW';
    
    $auditService = new AuditTrailService($db, $config, null, $countryCode);
    
    // LOG THAT SOMEONE VIEWED THE AUDIT TRAIL
    $auditService->recordLog(
        'audit_trail',
        null,
        'VIEW_AUDIT_TRAIL',
        'security',
        'INFO',
        null,
        json_encode([
            'filters' => $filters,
            'limit' => $limit,
            'user_role' => $user['role'] ?? 'unknown'
        ]),
        $user['admin_id'] ?? $user['user_id'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    );
    
    // Get logs with filters
    $logs = $auditService->getAuditLogs($limit, $filters);
    $totalCount = $auditService->getLogCount($filters);
    
    // Get available filters for dropdowns
    $categories = $auditService->getCategories();
    $actions = $auditService->getActions();
    
} catch (Exception $e) {
    error_log("AuditTrailService error: " . $e->getMessage());
    $logs = [];
    $totalCount = 0;
    $categories = [];
    $actions = [];
}

// Add API logs
$apiLogStmt = $db->prepare("
    SELECT 
        COUNT(*) as total_calls,
        SUM(CASE WHEN success THEN 1 ELSE 0 END) as successful,
        AVG(duration_ms) as avg_duration,
        message_type
    FROM api_message_logs
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    GROUP BY message_type
");
$apiLogStmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
$apiLogs = $apiLogStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CSV EXPORT
// ============================================================

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Verify permission again for export
    if (!currentUserHasPermission('export_data')) {
        http_response_code(403);
        echo "Export denied - Insufficient permissions";
        exit;
    }
    
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

// ============================================================
// GET USER'S ROLE INFO FOR DISPLAY
// ============================================================

$userRole = $user['role'] ?? 'unknown';
$roleInfo = $roleManager->getRoleByName($userRole);
$roleLevel = $roleInfo['role_level'] ?? 'N/A';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trails</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style>
        /* ... keep existing styles ... */
        
        .user-role-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #2c3e50;
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .role-level-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .role-level-high { background: #e74c3c; }
        .role-level-medium { background: #f39c12; }
        .role-level-low { background: #27ae60; }
        
        /* ... rest of styles ... */
    </style>
</head>
<body>
    <div class="dashboard-content">
        <!-- User Role Display -->
        <div style="margin-bottom: 20px; padding: 10px 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #3498db; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>Logged in as:</strong> 
                <?= htmlspecialchars($user['username'] ?? $user['email'] ?? 'Unknown') ?>
                <span class="user-role-badge">
                    <?= htmlspecialchars($userRole) ?>
                    (Level <?= $roleLevel ?>)
                </span>
            </div>
            <div style="font-size: 13px; color: #7f8c8d;">
                Country: <?= htmlspecialchars($countryCode ?? 'BW') ?>
            </div>
        </div>
        
        <div class="audit-header">
            <h2>📋 Audit Trails</h2>
            <div class="audit-actions">
                <?php if (currentUserHasPermission('export_data')): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-export">📥 Export CSV</a>
                <?php endif; ?>
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
                <select name="action">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $action): ?>
                        <option value="<?= htmlspecialchars($action) ?>" <?= ($_GET['action'] ?? '') === $action ? 'selected' : '' ?>>
                            <?= htmlspecialchars($action) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Category</label>
                <select name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category) ?>" <?= ($_GET['category'] ?? '') === $category ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
