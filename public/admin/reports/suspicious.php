<?php

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

// ADMIN_LAYER/reports/suspicious_activity_report.php

require_once __DIR__ . '/../../../src/Application/utils/session_manager.php';
require_once __DIR__ . '/../../../src/Domain/services/ComplianceService/AMLService.php';
require_once __DIR__ . '/../../../src/Core/Database/config/DBConnection.php';

use APP_LAYER\utils\SessionManager;
use BUSINESS_LOGIC_LAYER\Services\ComplianceService\AMLService;
use DATA_PERSISTENCE_LAYER\Config\DBConnection;

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
$country = require __DIR__ . '/../../../src/Core/Config/system_country.php';
$config  = require __DIR__ . '/../../../src/Core/Config/config_' . $country . '.php';
$swap_systemDB = DBConnection::getInstance($config['db']['swap']);

// AML service
$amlService = new AMLService($swap_systemDB);
$reportData = $amlService->generateReport();

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="suspicious_activity_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Swap ID', 'User', 'Amount', 'From', 'To', 'AML Score', 'KYC Verified', 'Risk Level', 'Status', 'Fraud Check', 'Created At']);
    foreach ($reportData['data'] as $row) {
        fputcsv($output, [
            $row['swap_id'] ?? '',
            $row['user'] ?? '',
            $row['amount'] ?? '',
            $row['from_currency'] ?? '',
            $row['to_currency'] ?? '',
            $row['aml_score'] ?? '',
            $row['kyc_verified'] ?? '',
            $row['risk_level'] ?? '',
            $row['status'] ?? '',
            $row['fraud_check_status'] ?? '',
            $row['created_at'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}
?>

<div class="dashboard-content">
    <h2>Suspicious Activity Report</h2>
    <a href="?export=csv" class="btn-download">Download CSV</a>

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
                    <th>KYC Verified</th>
                    <th>Risk Level</th>
                    <th>Status</th>
                    <th>Fraud Check</th>
                    <th>Created At</th>
                </tr>
            </thead>
            <tbody>
                <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 if (!empty($reportData['data'])): ?>
                    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach ($reportData['data'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['swap_id'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['user'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['amount'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['from_currency'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['to_currency'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['aml_score'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['kyc_verified'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['risk_level'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['status'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['fraud_check_status'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
                        </tr>
                    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
                <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 else: ?>
                    <tr><td colspan="11" style="text-align:center;">No suspicious activity detected</td></tr>
                <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.dashboard-content { padding:20px; font-family:'Arial',sans-serif; }
.report-table-container { overflow-x:auto; margin-top:20px; }
.report-table { width:100%; border-collapse: collapse; }
.report-table th, .report-table td { border:1px solid #ddd; padding:10px; text-align:center; }
.report-table th { background-color:#f5f5f5; font-weight:bold; }
.report-table tr:nth-child(even){ background-color:#f9f9f9; }
.btn-download { display:inline-block; padding:8px 16px; background:#007bff; color:#fff; text-decoration:none; margin-bottom:10px; border-radius:4px; }
.btn-download:hover{ background:#0056b3; }
</style>

