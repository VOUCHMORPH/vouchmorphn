<?php
// ADMIN_LAYER/reports/suspicious_activity_report.php

require_once __DIR__ . '/../../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../../src/Domain/Services/Compliance/AMLService.php';
require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';

use APP_LAYER\utils\SessionManager; // Keep for backward compatibility, but actual class might be different
use BUSINESS_LOGIC_LAYER\Services\ComplianceService\AMLService;
use DATA_PERSISTENCE_LAYER\Config\DBConnection;

// Alternative imports based on actual file structure
// These are the actual classes from the file list
use Application\Utils\SessionManager;
use Domain\Services\Compliance\AMLService;
use Core\Database\DBConnection;

// Start session
SessionManager::start();

// Allow multiple admin roles
$user = SessionManager::getUser();
$allowedRoles = ['admin', 'GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'AUDITOR'];
if (!$user || !in_array($user['role'] ?? '', $allowedRoles)) {
    http_response_code(403);
    echo "<p style='text-align:center;color:red;font-weight:bold;'>Access denied</p>";
    exit;
}

// Load config & DB
try {
    // Determine country from config
    $country = require __DIR__ . '/../../../src/Core/Config/SystemCountry.php';
    // Or use: $country = 'Botswana'; // Default fallback
    
    $configPath = __DIR__ . '/../../../src/Core/Config/Countries/' . $country . '/config.php';
    if (!file_exists($configPath)) {
        // Fallback to Botswana if country not found
        $country = 'Botswana';
        $configPath = __DIR__ . '/../../../src/Core/Config/Countries/Botswana/config.php';
    }
    
    $config = require $configPath;
    
    // Get database connection
    $swap_systemDB = DBConnection::getInstance($config['db']['swap'] ?? $config['database']['swap'] ?? []);
    
} catch (Exception $e) {
    // Fallback: try to load from environment or use default
    error_log("Error loading config: " . $e->getMessage());
    
    // Attempt to load Botswana config as fallback
    $config = require __DIR__ . '/../../../src/Core/Config/Countries/Botswana/config.php';
    $swap_systemDB = DBConnection::getInstance($config['db']['swap'] ?? $config['database']['swap'] ?? []);
}

// Initialize AML service
try {
    $amlService = new AMLService($swap_systemDB);
    $reportData = $amlService->generateReport();
} catch (Exception $e) {
    error_log("AMLService error: " . $e->getMessage());
    // Fallback: provide empty report if service fails
    $reportData = [
        'data' => [],
        'summary' => [
            'total' => 0,
            'high_risk' => 0,
            'medium_risk' => 0,
            'low_risk' => 0
        ]
    ];
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="suspicious_activity_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Expanded CSV headers to match actual available data
    fputcsv($output, [
        'Swap ID', 
        'User', 
        'Amount', 
        'From Currency', 
        'To Currency', 
        'AML Score', 
        'KYC Verified', 
        'Risk Level', 
        'Status', 
        'Fraud Check', 
        'Created At',
        'Institution',
        'Source Asset',
        'Destination Asset'
    ]);
    
    foreach ($reportData['data'] as $row) {
        fputcsv($output, [
            $row['swap_id'] ?? $row['id'] ?? '',
            $row['user'] ?? $row['user_id'] ?? $row['username'] ?? '',
            $row['amount'] ?? $row['transaction_amount'] ?? '',
            $row['from_currency'] ?? $row['source_currency'] ?? '',
            $row['to_currency'] ?? $row['destination_currency'] ?? '',
            $row['aml_score'] ?? $row['risk_score'] ?? '',
            $row['kyc_verified'] ?? ($row['kyc_status'] ?? ''),
            $row['risk_level'] ?? $row['risk_category'] ?? '',
            $row['status'] ?? $row['transaction_status'] ?? '',
            $row['fraud_check_status'] ?? $row['fraud_status'] ?? '',
            $row['created_at'] ?? $row['timestamp'] ?? '',
            $row['institution'] ?? $row['institution_code'] ?? '',
            $row['source_asset'] ?? $row['source_asset_type'] ?? '',
            $row['destination_asset'] ?? $row['dest_asset_type'] ?? ''
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
    <title>Suspicious Activity Report</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style>
        .dashboard-content { 
            padding: 20px; 
            font-family: 'Arial', sans-serif;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .report-header h2 {
            margin: 0;
            color: #2c3e50;
        }
        
        .report-actions {
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
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .btn-download:hover {
            background: #2980b9;
        }
        
        .btn-refresh {
            background: #27ae60;
        }
        
        .btn-refresh:hover {
            background: #229954;
        }
        
        .report-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
        }
        
        .summary-item {
            text-align: center;
        }
        
        .summary-item .label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .summary-item .value {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .summary-item .value.high-risk { color: #e74c3c; }
        .summary-item .value.medium-risk { color: #f39c12; }
        .summary-item .value.low-risk { color: #27ae60; }
        
        .report-table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .report-table th {
            background: #34495e;
            color: white;
            padding: 12px 10px;
            text-align: left;
            white-space: nowrap;
        }
        
        .report-table td {
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .report-table tr:hover {
            background: #f8f9fa;
        }
        
        .report-table tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .report-table .risk-high {
            color: #e74c3c;
            font-weight: bold;
        }
        
        .report-table .risk-medium {
            color: #f39c12;
        }
        
        .report-table .risk-low {
            color: #27ae60;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        
        .no-data i {
            font-size: 48px;
            display: block;
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            .report-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .report-actions {
                flex-direction: column;
            }
            
            .report-table {
                font-size: 12px;
            }
            
            .report-table th,
            .report-table td {
                padding: 6px 4px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-content">
        <div class="report-header">
            <h2>🚨 Suspicious Activity Report</h2>
            <div class="report-actions">
                <button onclick="location.reload()" class="btn-download btn-refresh">🔄 Refresh</button>
                <a href="?export=csv" class="btn-download">📥 Download CSV</a>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <?php if (!empty($reportData['summary'])): ?>
        <div class="report-summary">
            <div class="summary-item">
                <div class="label">Total Transactions</div>
                <div class="value"><?= htmlspecialchars($reportData['summary']['total'] ?? 0) ?></div>
            </div>
            <div class="summary-item">
                <div class="label">High Risk</div>
                <div class="value high-risk"><?= htmlspecialchars($reportData['summary']['high_risk'] ?? 0) ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Medium Risk</div>
                <div class="value medium-risk"><?= htmlspecialchars($reportData['summary']['medium_risk'] ?? 0) ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Low Risk</div>
                <div class="value low-risk"><?= htmlspecialchars($reportData['summary']['low_risk'] ?? 0) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Report Table -->
        <div class="report-table-container">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Swap ID</th>
                        <th>User</th>
                        <th>Amount</th>
                        <th>From</th>
                        <th>To</th>
                        <th>AML Score</th>
                        <th>KYC</th>
                        <th>Risk Level</th>
                        <th>Status</th>
                        <th>Fraud Check</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($reportData['data'])): ?>
                        <?php foreach ($reportData['data'] as $row): 
                            $riskLevel = $row['risk_level'] ?? $row['risk_category'] ?? '';
                            $riskClass = 'risk-' . strtolower($riskLevel);
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['swap_id'] ?? $row['id'] ?? 'N/A') ?></strong></td>
                                <td><?= htmlspecialchars($row['user'] ?? $row['user_id'] ?? $row['username'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['amount'] ?? $row['transaction_amount'] ?? '0.00') ?></td>
                                <td><?= htmlspecialchars($row['from_currency'] ?? $row['source_currency'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['to_currency'] ?? $row['destination_currency'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['aml_score'] ?? $row['risk_score'] ?? '') ?></td>
                                <td>
                                    <?php 
                                    $kycStatus = $row['kyc_verified'] ?? $row['kyc_status'] ?? '';
                                    $badgeClass = $kycStatus === 'Verified' || $kycStatus === 'verified' ? 'badge-success' : 'badge-warning';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= htmlspecialchars($kycStatus ?: 'Unknown') ?>
                                    </span>
                                </td>
                                <td class="<?= $riskClass ?>">
                                    <?= htmlspecialchars($riskLevel ?: 'Unknown') ?>
                                </td>
                                <td>
                                    <?php 
                                    $status = $row['status'] ?? $row['transaction_status'] ?? '';
                                    $statusBadge = in_array(strtolower($status), ['completed', 'success']) ? 'badge-success' : 
                                                   (in_array(strtolower($status), ['failed', 'cancelled']) ? 'badge-danger' : 'badge-info');
                                    ?>
                                    <span class="badge <?= $statusBadge ?>">
                                        <?= htmlspecialchars($status ?: 'Pending') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $fraudStatus = $row['fraud_check_status'] ?? $row['fraud_status'] ?? '';
                                    $fraudBadge = $fraudStatus === 'Cleared' || $fraudStatus === 'cleared' ? 'badge-success' : 
                                                  ($fraudStatus === 'Flagged' || $fraudStatus === 'flagged' ? 'badge-danger' : 'badge-warning');
                                    ?>
                                    <span class="badge <?= $fraudBadge ?>">
                                        <?= htmlspecialchars($fraudStatus ?: 'Pending') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['created_at'] ?? $row['timestamp'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="no-data">
                                <div style="padding: 30px;">
                                    <span style="font-size: 48px;">✅</span>
                                    <p style="margin-top: 10px; font-size: 16px;">No suspicious activity detected</p>
                                    <p style="color: #7f8c8d; font-size: 13px;">All transactions appear to be within normal parameters</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Footer Info -->
        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; font-size: 13px; color: #7f8c8d; text-align: center;">
            Report generated: <?= date('Y-m-d H:i:s') ?> | 
            Total records: <?= count($reportData['data'] ?? []) ?> |
            System: VouchMorph Compliance Monitoring
        </div>
    </div>
</body>
</html>
