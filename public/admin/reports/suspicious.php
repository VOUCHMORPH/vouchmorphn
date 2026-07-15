<?php
/**
 * ADMIN_LAYER/reports/suspicious_activity_report.php
 * Suspicious Activity Report - AML/KYC Compliance Monitoring
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';
require_once __DIR__ . '/../../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;

// Start session and check permissions
SessionManager::start();

$user = SessionManager::getUser();
$allowedRoles = ['admin', 'GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'AUDITOR', 'COMPLIANCE_OFFICER'];

if (!$user || !in_array($user['role'] ?? '', $allowedRoles)) {
    http_response_code(403);
    echo "<p style='text-align:center;color:red;font-weight:bold;'>Access denied - Insufficient permissions</p>";
    exit;
}

// Get country code from session or config
$countryCode = SessionManager::getAdminCountry() ?? 'BW';
$userRole = $user['role'] ?? 'unknown';

// Database connection
try {
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("[SUSPICIOUS REPORT] DB Error: " . $e->getMessage());
    die("Database connection failed. Please check configuration.");
}

// --- FILTERS ---
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$minAmlScore = (int)($_GET['min_aml_score'] ?? 0);
$riskLevel = $_GET['risk_level'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$limit = min((int)($_GET['limit'] ?? 100), 500);

// --- FETCH SUSPICIOUS ACTIVITY DATA ---
try {
    // Main query combining swap requests with AML checks and KYC status
    $query = "
        SELECT 
            sr.swap_id,
            sr.swap_uuid,
            sr.user_id,
            u.full_name as user_name,
            u.email as user_email,
            u.phone as user_phone,
            u.kyc_verified,
            u.aml_score,
            sr.amount,
            sr.from_currency as source_currency,
            sr.to_currency as destination_currency,
            sr.status as swap_status,
            sr.created_at,
            sr.source_details,
            sr.destination_details,
            sr.metadata,
            sr.retry_count,
            aml.check_id as aml_check_id,
            aml.check_type as aml_check_type,
            aml.risk_score as aml_risk_score,
            aml.status as aml_status,
            aml.findings as aml_findings,
            aml.performed_at as aml_performed_at,
            kyc.document_type as kyc_document_type,
            kyc.status as kyc_document_status,
            kyc.review_notes as kyc_notes
        FROM swap_requests sr
        LEFT JOIN users u ON sr.user_id = u.user_id
        LEFT JOIN aml_checks aml ON sr.user_id = aml.user_id 
            AND DATE(aml.performed_at) BETWEEN :date_from AND :date_to
        LEFT JOIN kyc_documents kyc ON sr.user_id = kyc.user_id
        WHERE DATE(sr.created_at) BETWEEN :date_from AND :date_to
        AND (
            sr.status IN ('failed', 'pending') 
            OR sr.retry_count > 2
            OR u.aml_score >= :min_aml_score
            OR aml.risk_score >= :min_aml_score
        )
        GROUP BY sr.swap_id, sr.swap_uuid, sr.user_id, u.full_name, u.email, u.phone, 
            u.kyc_verified, u.aml_score, sr.amount, sr.from_currency, sr.to_currency,
            sr.status, sr.created_at, sr.source_details, sr.destination_details,
            sr.metadata, sr.retry_count, aml.check_id, aml.check_type, aml.risk_score,
            aml.status, aml.findings, aml.performed_at, kyc.document_type,
            kyc.status, kyc.review_notes
        ORDER BY u.aml_score DESC, sr.created_at DESC
        LIMIT :limit
    ";

    $params = [
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
        ':min_aml_score' => $minAmlScore,
        ':limit' => $limit
    ];

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $suspiciousData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- FETCH SUMMARY STATISTICS ---
    $summaryQuery = "
        SELECT 
            COUNT(DISTINCT sr.swap_id) as total_suspicious_swaps,
            COUNT(DISTINCT sr.user_id) as unique_users,
            SUM(sr.amount) as total_amount,
            SUM(CASE WHEN u.aml_score >= 70 THEN 1 ELSE 0 END) as high_risk_users,
            SUM(CASE WHEN u.aml_score >= 50 AND u.aml_score < 70 THEN 1 ELSE 0 END) as medium_risk_users,
            SUM(CASE WHEN aml.status = 'flagged' THEN 1 ELSE 0 END) as aml_flagged,
            SUM(CASE WHEN kyc.status = 'rejected' THEN 1 ELSE 0 END) as kyc_rejected
        FROM swap_requests sr
        LEFT JOIN users u ON sr.user_id = u.user_id
        LEFT JOIN aml_checks aml ON sr.user_id = aml.user_id
        LEFT JOIN kyc_documents kyc ON sr.user_id = kyc.user_id
        WHERE DATE(sr.created_at) BETWEEN :date_from AND :date_to
        AND (
            sr.status IN ('failed', 'pending') 
            OR sr.retry_count > 2
            OR u.aml_score >= :min_aml_score
            OR aml.risk_score >= :min_aml_score
        )
    ";

    $summaryStmt = $db->prepare($summaryQuery);
    $summaryStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo, ':min_aml_score' => $minAmlScore]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // --- FETCH AML CHECK TYPES BREAKDOWN ---
    $amlBreakdownQuery = "
        SELECT 
            check_type,
            COUNT(*) as count,
            AVG(risk_score) as avg_risk_score,
            SUM(CASE WHEN status = 'flagged' THEN 1 ELSE 0 END) as flagged_count
        FROM aml_checks
        WHERE DATE(performed_at) BETWEEN :date_from AND :date_to
        GROUP BY check_type
        ORDER BY count DESC
    ";

    $amlBreakdownStmt = $db->prepare($amlBreakdownQuery);
    $amlBreakdownStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
    $amlBreakdown = $amlBreakdownStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- FETCH HIGH-RISK USERS ---
    $highRiskUsersQuery = "
        SELECT 
            u.user_id,
            u.full_name,
            u.email,
            u.phone,
            u.aml_score,
            COUNT(sr.swap_id) as swap_count,
            SUM(sr.amount) as total_volume
        FROM users u
        LEFT JOIN swap_requests sr ON u.user_id = sr.user_id
            AND DATE(sr.created_at) BETWEEN :date_from AND :date_to
        WHERE u.aml_score >= 50
        GROUP BY u.user_id, u.full_name, u.email, u.phone, u.aml_score
        ORDER BY u.aml_score DESC
        LIMIT 20
    ";

    $highRiskStmt = $db->prepare($highRiskUsersQuery);
    $highRiskStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
    $highRiskUsers = $highRiskStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("[SUSPICIOUS REPORT] Query error: " . $e->getMessage());
    $suspiciousData = [];
    $summary = ['total_suspicious_swaps' => 0, 'unique_users' => 0, 'total_amount' => 0];
    $amlBreakdown = [];
    $highRiskUsers = [];
}

// --- CSV EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="suspicious_activity_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, [
        'Swap ID', 'User ID', 'User Name', 'Email', 'Phone', 'Amount', 
        'From Currency', 'To Currency', 'Status', 'Retry Count', 
        'KYC Verified', 'AML Score', 'AML Risk Score', 'AML Status',
        'Risk Level', 'Created At', 'KYC Document Status'
    ]);
    
    foreach ($suspiciousData as $row) {
        $riskLevel = 'Low';
        $amlScore = max((float)($row['aml_score'] ?? 0), (float)($row['aml_risk_score'] ?? 0));
        if ($amlScore >= 70) $riskLevel = 'Critical';
        elseif ($amlScore >= 50) $riskLevel = 'High';
        elseif ($amlScore >= 30) $riskLevel = 'Medium';
        
        fputcsv($output, [
            $row['swap_id'] ?? '',
            $row['user_id'] ?? '',
            $row['user_name'] ?? '',
            $row['user_email'] ?? '',
            $row['user_phone'] ?? '',
            $row['amount'] ?? '',
            $row['source_currency'] ?? '',
            $row['destination_currency'] ?? '',
            $row['swap_status'] ?? '',
            $row['retry_count'] ?? 0,
            $row['kyc_verified'] ? 'Yes' : 'No',
            $row['aml_score'] ?? '',
            $row['aml_risk_score'] ?? '',
            $row['aml_status'] ?? '',
            $riskLevel,
            $row['created_at'] ?? '',
            $row['kyc_document_status'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// Determine risk level function
function getRiskLevel($amlScore) {
    if ($amlScore >= 70) return ['level' => 'Critical', 'class' => 'risk-critical'];
    if ($amlScore >= 50) return ['level' => 'High', 'class' => 'risk-high'];
    if ($amlScore >= 30) return ['level' => 'Medium', 'class' => 'risk-medium'];
    return ['level' => 'Low', 'class' => 'risk-low'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suspicious Activity Report - VouchMorph</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; color: #333; }
        .dashboard-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .dashboard-header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: white; padding: 20px 30px; border-radius: 12px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .dashboard-header h1 { font-size: 24px; font-weight: 600; }
        .dashboard-header p { opacity: 0.8; font-size: 14px; margin-top: 5px; }
        
        .filter-bar { background: white; padding: 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: #666; text-transform: uppercase; }
        .filter-group input, .filter-group select { padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; min-width: 150px; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover { background: #0056b3; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #1e7e34; }
        .btn-secondary { background: #6c757d; color: white; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 10px; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #1a1a2e; }
        .stat-card .sub { font-size: 12px; color: #999; margin-top: 5px; }
        
        .section { background: white; border-radius: 12px; margin-bottom: 25px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .section-header h2 { font-size: 18px; font-weight: 600; }
        .section-content { padding: 20px; overflow-x: auto; }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; }
        th { background: #f8f9fa; font-weight: 600; color: #495057; }
        tr:hover { background: #f8f9fa; }
        
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-pending { background: #fff3cd; color: #856404; }
        
        .risk-critical { background: #dc3545; color: white; }
        .risk-high { background: #e74c3c; color: white; }
        .risk-medium { background: #f39c12; color: white; }
        .risk-low { background: #27ae60; color: white; }
        
        .export-buttons { display: flex; gap: 10px; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div>
                <h1>🚨 Suspicious Activity Report</h1>
                <p>AML/KYC Compliance Monitoring - <?= htmlspecialchars($countryCode) ?></p>
            </div>
            <div class="export-buttons">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">📥 Export CSV</a>
                <a href="?" class="btn btn-secondary">🔄 Reset</a>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="filter-group">
                <label>Min AML Score</label>
                <input type="number" name="min_aml_score" id="min_aml_score" value="<?= htmlspecialchars($minAmlScore) ?>" min="0" max="100">
            </div>
            <div class="filter-group">
                <label>Risk Level</label>
                <select name="risk_level" id="risk_level">
                    <option value="">All Levels</option>
                    <option value="Critical" <?= $riskLevel === 'Critical' ? 'selected' : '' ?>>Critical</option>
                    <option value="High" <?= $riskLevel === 'High' ? 'selected' : '' ?>>High</option>
                    <option value="Medium" <?= $riskLevel === 'Medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="Low" <?= $riskLevel === 'Low' ? 'selected' : '' ?>>Low</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status" id="status">
                    <option value="">All Status</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>
            <div class="filter-group">
                <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Suspicious Swaps</h3>
                <div class="value"><?= number_format($summary['total_suspicious_swaps'] ?? 0) ?></div>
                <div class="sub"><?= number_format($summary['unique_users'] ?? 0) ?> unique users</div>
            </div>
            <div class="stat-card">
                <h3>Total Amount</h3>
                <div class="value"><?= number_format($summary['total_amount'] ?? 0, 2) ?></div>
                <div class="sub">BWP across all suspicious transactions</div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #dc3545;">
                <h3>High Risk Users</h3>
                <div class="value" style="color: #dc3545;"><?= number_format($summary['high_risk_users'] ?? 0) ?></div>
                <div class="sub">AML Score ≥ 70</div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #f39c12;">
                <h3>AML Flagged</h3>
                <div class="value" style="color: #f39c12;"><?= number_format($summary['aml_flagged'] ?? 0) ?></div>
                <div class="sub">Checks flagged for review</div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #e74c3c;">
                <h3>KYC Rejected</h3>
                <div class="value" style="color: #e74c3c;"><?= number_format($summary['kyc_rejected'] ?? 0) ?></div>
                <div class="sub">Documents rejected</div>
            </div>
        </div>
        
        <!-- AML Breakdown Section -->
        <?php if (!empty($amlBreakdown)): ?>
        <div class="section">
            <div class="section-header" onclick="toggleSection('amlBreakdown')">
                <h2>🔍 AML Check Breakdown</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="amlBreakdown">
                <table>
                    <thead>
                        <tr>
                            <th>Check Type</th>
                            <th>Count</th>
                            <th>Avg Risk Score</th>
                            <th>Flagged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($amlBreakdown as $aml): ?>
                        <tr>
                            <td><?= htmlspecialchars($aml['check_type']) ?></td>
                            <td><?= number_format($aml['count']) ?></td>
                            <td><?= number_format($aml['avg_risk_score'], 2) ?></td>
                            <td><?= number_format($aml['flagged_count']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- High Risk Users Section -->
        <?php if (!empty($highRiskUsers)): ?>
        <div class="section">
            <div class="section-header" onclick="toggleSection('highRiskUsers')">
                <h2>⚠️ High Risk Users</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="highRiskUsers">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>AML Score</th>
                            <th>Swaps</th>
                            <th>Total Volume</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($highRiskUsers as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['full_name'] ?? 'Unknown') ?></td>
                            <td><?= htmlspecialchars($user['email'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($user['phone'] ?? 'N/A') ?></td>
                            <td><span class="status-badge risk-high"><?= number_format($user['aml_score'], 2) ?></span></td>
                            <td><?= number_format($user['swap_count']) ?></td>
                            <td><?= number_format($user['total_volume'] ?? 0, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Suspicious Transactions Table -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('suspiciousTransactions')">
                <h2>📋 Suspicious Transactions</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="suspiciousTransactions">
                <table>
                    <thead>
                        <tr>
                            <th>Swap ID</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Status</th>
                            <th>Retry</th>
                            <th>KYC</th>
                            <th>AML Score</th>
                            <th>Risk</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($suspiciousData)): ?>
                            <?php foreach ($suspiciousData as $row): 
                                $amlScore = max((float)($row['aml_score'] ?? 0), (float)($row['aml_risk_score'] ?? 0));
                                $risk = getRiskLevel($amlScore);
                                $statusClass = match($row['swap_status'] ?? '') {
                                    'completed' => 'status-completed',
                                    'failed' => 'status-failed',
                                    'pending' => 'status-pending',
                                    default => ''
                                };
                            ?>
                            <tr>
                                <td><code><?= substr(htmlspecialchars($row['swap_uuid'] ?? $row['swap_id']), 0, 16) ?>...</code></td>
                                <td><?= htmlspecialchars($row['user_name'] ?? 'Unknown') ?></td>
                                <td><strong><?= number_format($row['amount'] ?? 0, 2) ?></strong></td>
                                <td><?= htmlspecialchars($row['source_currency'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['destination_currency'] ?? 'N/A') ?></td>
                                <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($row['swap_status'] ?? 'N/A') ?></span></td>
                                <td><?= $row['retry_count'] ?? 0 ?></td>
                                <td><?= ($row['kyc_verified'] ?? false) ? '✅' : '❌' ?></td>
                                <td><?= number_format($amlScore, 2) ?></td>
                                <td><span class="status-badge <?= $risk['class'] ?>"><?= $risk['level'] ?></span></td>
                                <td><?= date('Y-m-d H:i', strtotime($row['created_at'] ?? 'now')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="11" style="text-align:center;">No suspicious activity detected in this period</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function applyFilters() {
            const params = new URLSearchParams();
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            const minAmlScore = document.getElementById('min_aml_score').value;
            const riskLevel = document.getElementById('risk_level').value;
            const status = document.getElementById('status').value;
            
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
            if (minAmlScore) params.append('min_aml_score', minAmlScore);
            if (riskLevel) params.append('risk_level', riskLevel);
            if (status) params.append('status', status);
            
            window.location.href = '?' + params.toString();
        }
        
        function toggleSection(sectionId) {
            const section = document.getElementById(sectionId);
            const header = section.previousElementSibling;
            const arrow = header.querySelector('span');
            
            if (section.style.display === 'none') {
                section.style.display = 'block';
                arrow.textContent = '▼';
            } else {
                section.style.display = 'none';
                arrow.textContent = '▶';
            }
        }
        
        // Initialize all sections as visible
        document.querySelectorAll('.section-content').forEach(section => {
            section.style.display = 'block';
        });
    </script>
</body>
</html>
