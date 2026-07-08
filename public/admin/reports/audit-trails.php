<?php
// ADMIN_LAYER/reports/audit_trails.php

// Load bootstrap first
require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

// Include required files with correct paths
require_once __DIR__ . '/../../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../../src/Domain/Services/AuditTrailService.php';

// Use proper namespaces
use Application\Utils\SessionManager;
use Domain\Services\AuditTrailService;

// Start session
SessionManager::start();

// Allow multiple roles
$user = SessionManager::getUser();
$allowedRoles = ['admin', 'GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'AUDITOR'];
if (!$user || !in_array($user['role'] ?? '', $allowedRoles)) {
    http_response_code(403);
    echo "<p style='text-align:center;color:red;font-weight:bold;'>Access denied</p>";
    exit;
}

// --- FETCH AUDIT LOGS ---
try {
    $auditService = new AuditTrailService();
    $logs = $auditService->getAuditLogs();
} catch (Exception $e) {
    error_log("AuditTrailService error: " . $e->getMessage());
    $logs = [];
}

// --- CSV Export ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_trails_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Enhanced CSV headers
    fputcsv($output, [
        'Log ID', 
        'User/Admin', 
        'Action', 
        'Date/Time', 
        'IP Address',
        'User Agent',
        'Details'
    ]);
    
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'] ?? $log['log_id'] ?? '',
            $log['username'] ?? $log['user'] ?? $log['admin'] ?? '',
            $log['action'] ?? $log['action_type'] ?? '',
            $log['timestamp'] ?? $log['created_at'] ?? $log['date'] ?? '',
            $log['ip_address'] ?? $log['ip'] ?? '',
            $log['user_agent'] ?? $log['browser'] ?? '',
            $log['details'] ?? $log['description'] ?? ''
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
        .dashboard-content { 
            padding: 20px; 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .audit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .audit-header h2 {
            margin: 0;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .audit-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .btn-download {
            display: inline-block;
            padding: 8px 20px;
            background: #3498db;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-download:hover {
            background: #2980b9;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
        }
        
        .btn-refresh {
            background: #27ae60;
        }
        
        .btn-refresh:hover {
            background: #229954;
            box-shadow: 0 2px 8px rgba(39, 174, 96, 0.3);
        }
        
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
        
        .stat-item {
            text-align: center;
        }
        
        .stat-item .label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .stat-item .value {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
            margin-top: 5px;
        }
        
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
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
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
        
        .report-table td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        
        .report-table tr:hover {
            background: #f8f9fa;
        }
        
        .report-table tr:nth-child(even) {
            background: #fafbfc;
        }
        
        .report-table tr:nth-child(even):hover {
            background: #f0f1f3;
        }
        
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
        
        .action-type {
            font-weight: 500;
        }
        
        .action-login { color: #27ae60; }
        .action-logout { color: #e67e22; }
        .action-create { color: #3498db; }
        .action-update { color: #9b59b6; }
        .action-delete { color: #e74c3c; }
        .action-export { color: #1abc9c; }
        
        .no-data {
            text-align: center;
            padding: 50px 20px;
            color: #7f8c8d;
        }
        
        .no-data .icon {
            font-size: 48px;
            display: block;
            margin-bottom: 15px;
        }
        
        .no-data h3 {
            margin: 0 0 10px 0;
            color: #2c3e50;
        }
        
        .no-data p {
            margin: 0;
            font-size: 14px;
        }
        
        .timestamp {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #7f8c8d;
        }
        
        .ip-address {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            background: #f1f3f5;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        @media (max-width: 768px) {
            .audit-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .audit-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .audit-actions .btn-download {
                width: 100%;
                text-align: center;
            }
            
            .audit-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .report-table {
                font-size: 12px;
            }
            
            .report-table th,
            .report-table td {
                padding: 8px 6px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-content">
        <div class="audit-header">
            <h2>📋 Audit Trails</h2>
            <div class="audit-actions">
                <button onclick="location.reload()" class="btn-download btn-refresh">🔄 Refresh</button>
                <a href="?export=csv" class="btn-download">📥 Download CSV</a>
            </div>
        </div>
        
        <!-- Statistics Summary -->
        <?php if (!empty($logs)): 
            $totalActions = count($logs);
            $uniqueUsers = count(array_unique(array_column($logs, 'username')));
            $todayActions = count(array_filter($logs, function($log) {
                $date = $log['timestamp'] ?? $log['created_at'] ?? '';
                return strpos($date, date('Y-m-d')) === 0;
            }));
        ?>
        <div class="audit-stats">
            <div class="stat-item">
                <div class="label">Total Actions</div>
                <div class="value actions"><?= $totalActions ?></div>
            </div>
            <div class="stat-item">
                <div class="label">Active Users</div>
                <div class="value users"><?= $uniqueUsers ?></div>
            </div>
            <div class="stat-item">
                <div class="label">Today's Activity</div>
                <div class="value today"><?= $todayActions ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Audit Table -->
        <div class="report-table-container">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Log ID</th>
                        <th>User/Admin</th>
                        <th>Action</th>
                        <th>Date/Time</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($logs)): ?>
                        <?php foreach ($logs as $log): 
                            $action = $log['action'] ?? $log['action_type'] ?? '';
                            $actionClass = strtolower($action);
                        ?>
                            <tr>
                                <td>
                                    <strong>#<?= htmlspecialchars($log['id'] ?? $log['log_id'] ?? '') ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars($log['username'] ?? $log['user'] ?? $log['admin'] ?? 'System') ?>
                                </td>
                                <td>
                                    <span class="action-type action-<?= $actionClass ?>">
                                        <?= htmlspecialchars($action ?: 'Unknown') ?>
                                    </span>
                                </td>
                                <td class="timestamp">
                                    <?= htmlspecialchars($log['timestamp'] ?? $log['created_at'] ?? $log['date'] ?? '') ?>
                                </td>
                                <td>
                                    <span class="ip-address">
                                        <?= htmlspecialchars($log['ip_address'] ?? $log['ip'] ?? 'N/A') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">
                                <div class="no-data">
                                    <span class="icon">📭</span>
                                    <h3>No Audit Logs Available</h3>
                                    <p>System activity will appear here once users start interacting with the dashboard.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Footer Information -->
        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; font-size: 13px; color: #7f8c8d; text-align: center; border: 1px solid #e9ecef;">
            <span>📊 Report generated: <?= date('Y-m-d H:i:s') ?></span>
            <span style="margin: 0 15px;">|</span>
            <span>📝 Total entries: <?= count($logs ?? []) ?></span>
            <span style="margin: 0 15px;">|</span>
            <span>🔒 Secure audit trail - VouchMorph System</span>
        </div>
    </div>
</body>
</html>
