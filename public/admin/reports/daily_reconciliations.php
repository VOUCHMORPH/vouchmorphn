<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Define project root
define('PROJECT_ROOT', dirname(__DIR__, 3));

// Load required classes
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;

// Check if admin is logged in
if (!SessionManager::isAdminLoggedIn()) {
    header('Location: /admin/admin_login.php');
    exit();
}

// Get admin info from session
$adminId = SessionManager::getAdminId();
$adminUsername = SessionManager::getAdminUsername();
$adminFullName = SessionManager::get('admin_full_name');
$adminRoleId = SessionManager::getAdminRoleId();
$adminCountry = SessionManager::getAdminCountry();

// Load configuration
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
$config = array();
if (file_exists($configPath)) {
    require_once $configPath;
    try {
        $config = \Core\Config\LoadCountry::getConfig();
        if (!is_array($config)) {
            $config = array();
        }
    } catch (Throwable $e) {
        error_log("[DAILY RECONCILIATION] Config error: " . $e->getMessage());
        $config = array();
    }
}

$countryCode = $adminCountry ?: (isset($config['country_code']) ? $config['country_code'] : 'BW');
$countryName = isset($config['country']) ? $config['country'] : 'Botswana';
$currencySymbol = isset($config['currency_symbol']) ? $config['currency_symbol'] : 'BWP';

// Database connection
try {
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    error_log("[DAILY RECONCILIATION] Database connected successfully");
} catch (Exception $e) {
    error_log("[DAILY RECONCILIATION] DB Error: " . $e->getMessage());
    die("Database connection failed. Please check configuration.");
}

// Date range
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$institutionFilter = isset($_GET['institution']) ? $_GET['institution'] : null;
$currencyFilter = isset($_GET['currency']) ? $_GET['currency'] : null;

// --------------------------------------------------------------------
// FETCH DAILY TRANSACTIONS
// --------------------------------------------------------------------
$transactionsQuery = "
    SELECT 
        sr.swap_id,
        sr.swap_uuid,
        sr.amount,
        sr.source_currency,
        sr.destination_currency,
        sr.exchange_rate,
        sr.status,
        sr.created_at,
        sr.source_details,
        sr.destination_details,
        sr.retry_count,
        sr.total_cashout_fee,
        sr.forex_fee_amount,
        ht.hold_reference,
        ht.status as hold_status,
        ht.amount as hold_amount,
        sfc.total_amount as collected_fee,
        sfc.fee_type,
        sfc.vat_amount
    FROM swap_requests sr
    LEFT JOIN hold_transactions ht ON sr.swap_uuid = ht.swap_reference
    LEFT JOIN swap_fee_collections sfc ON sr.swap_uuid = sfc.swap_reference
    WHERE DATE(sr.created_at) BETWEEN :date_from AND :date_to
";

$params = array(
    ':date_from' => $dateFrom,
    ':date_to' => $dateTo
);

if ($institutionFilter) {
    $transactionsQuery .= " AND (sr.source_details LIKE :institution_filter OR sr.destination_details LIKE :institution_filter)";
    $params[':institution_filter'] = '%' . $institutionFilter . '%';
}

if ($currencyFilter) {
    $transactionsQuery .= " AND (sr.source_currency = :currency OR sr.destination_currency = :currency)";
    $params[':currency'] = $currencyFilter;
}

$transactionsQuery .= " ORDER BY sr.created_at DESC";

$stmt = $db->prepare($transactionsQuery);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------------------------
// DAILY SUMMARY
// --------------------------------------------------------------------
$summaryQuery = "
    SELECT 
        COUNT(*) as total_swaps,
        COALESCE(SUM(amount), 0) as total_volume,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :date_from AND :date_to
";
$stmt = $db->prepare($summaryQuery);
$stmt->execute(array(':date_from' => $dateFrom, ':date_to' => $dateTo));
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// --------------------------------------------------------------------
// DAILY BREAKDOWN BY DATE
// --------------------------------------------------------------------
$dailyBreakdownQuery = "
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as transaction_count,
        COALESCE(SUM(amount), 0) as total_amount,
        status
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :date_from AND :date_to
    GROUP BY DATE(created_at), status
    ORDER BY date DESC, status
";
$stmt = $db->prepare($dailyBreakdownQuery);
$stmt->execute(array(':date_from' => $dateFrom, ':date_to' => $dateTo));
$dailyBreakdownRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date
$dailyBreakdown = array();
foreach ($dailyBreakdownRaw as $row) {
    $date = $row['date'];
    if (!isset($dailyBreakdown[$date])) {
        $dailyBreakdown[$date] = array(
            'date' => $date,
            'transaction_count' => 0,
            'total_amount' => 0,
            'status' => array()
        );
    }
    $dailyBreakdown[$date]['transaction_count'] += $row['transaction_count'];
    $dailyBreakdown[$date]['total_amount'] += $row['total_amount'];
    $dailyBreakdown[$date]['status'][$row['status']] = $row['transaction_count'];
}

// --------------------------------------------------------------------
// FEE BREAKDOWN
// --------------------------------------------------------------------
$feeQuery = "
    SELECT 
        fee_type,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(SUM(vat_amount), 0) as total_vat,
        currency
    FROM swap_fee_collections sfc
    JOIN swap_requests sr ON sfc.swap_reference = sr.swap_uuid
    WHERE DATE(sr.created_at) BETWEEN :date_from AND :date_to
    GROUP BY fee_type, currency
    ORDER BY total_amount DESC
";
$stmt = $db->prepare($feeQuery);
$stmt->execute(array(':date_from' => $dateFrom, ':date_to' => $dateTo));
$feeBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------------------------
// SETTLEMENT OUTBOX STATUS
// --------------------------------------------------------------------
$settlementQuery = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'SENT' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'ACKNOWLEDGED' THEN 1 ELSE 0 END) as acknowledged,
        COALESCE(SUM(amount), 0) as total_amount
    FROM settlement_outbox
    WHERE DATE(created_at) BETWEEN :date_from AND :date_to
";
$stmt = $db->prepare($settlementQuery);
$stmt->execute(array(':date_from' => $dateFrom, ':date_to' => $dateTo));
$settlementStats = $stmt->fetch(PDO::FETCH_ASSOC);

// --------------------------------------------------------------------
// CSV EXPORT
// --------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daily_reconciliation_' . $dateFrom . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array('VOUCHMORPH DAILY RECONCILIATION REPORT'));
    fputcsv($output, array('Date', $dateFrom));
    fputcsv($output, array('Generated At', date('Y-m-d H:i:s')));
    fputcsv($output, array());
    
    fputcsv($output, array('SUMMARY'));
    fputcsv($output, array('Total Swaps', $summary['total_swaps'] ?? 0));
    fputcsv($output, array('Successful', $summary['successful'] ?? 0));
    fputcsv($output, array('Failed', $summary['failed'] ?? 0));
    fputcsv($output, array('Pending', $summary['pending'] ?? 0));
    fputcsv($output, array('Cancelled', $summary['cancelled'] ?? 0));
    fputcsv($output, array('Total Volume', number_format($summary['total_volume'] ?? 0, 2)));
    fputcsv($output, array());
    
    fputcsv($output, array('TRANSACTIONS'));
    fputcsv($output, array('ID', 'Amount', 'Currency', 'Status', 'Fee', 'Created At'));
    foreach ($transactions as $tx) {
        $sourceDetails = json_decode($tx['source_details'] ?? '{}', true);
        $sourceInst = isset($sourceDetails['institution']) ? $sourceDetails['institution'] : 'N/A';
        fputcsv($output, array(
            $tx['swap_uuid'] ?? $tx['swap_id'],
            number_format($tx['amount'] ?? 0, 2),
            $tx['source_currency'] ?? 'N/A',
            $tx['status'] ?? 'N/A',
            number_format($tx['collected_fee'] ?? 0, 2),
            $tx['created_at'] ?? ''
        ));
    }
    fclose($output);
    exit;
}

// --------------------------------------------------------------------
// GET INSTITUTIONS LIST
// --------------------------------------------------------------------
$institutions = array();
try {
    $instStmt = $db->query("
        SELECT DISTINCT source_details 
        FROM swap_requests 
        WHERE source_details IS NOT NULL 
        AND source_details != '{}'
        LIMIT 100
    ");
    $results = $instStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($results as $json) {
        if ($json) {
            $data = json_decode($json, true);
            if ($data && isset($data['institution'])) {
                $institutions[] = $data['institution'];
            }
        }
    }
    $institutions = array_unique(array_filter($institutions));
} catch (Exception $e) {
    error_log("[DAILY RECONCILIATION] Institutions error: " . $e->getMessage());
    $institutions = array();
}

// Safe values
$totalSwaps = isset($summary['total_swaps']) ? $summary['total_swaps'] : 0;
$totalVolume = isset($summary['total_volume']) ? $summary['total_volume'] : 0;
$successful = isset($summary['successful']) ? $summary['successful'] : 0;
$failed = isset($summary['failed']) ? $summary['failed'] : 0;
$pending = isset($summary['pending']) ? $summary['pending'] : 0;
$cancelled = isset($summary['cancelled']) ? $summary['cancelled'] : 0;

$totalFees = 0;
$totalVat = 0;
foreach ($feeBreakdown as $fee) {
    $totalFees += $fee['total_amount'];
    $totalVat += $fee['total_vat'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Reconciliation - VouchMorph</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; color: #333; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: white; padding: 20px 30px; border-radius: 12px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .header h1 { font-size: 24px; font-weight: 600; }
        .header p { opacity: 0.8; font-size: 14px; }
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
        .status-cancelled { background: #e2e3e5; color: #383d41; }
        .status-acknowledged { background: #cce5ff; color: #004085; }
        .export-buttons { display: flex; gap: 10px; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>📊 Daily Reconciliation Report</h1>
                <p><?php echo htmlspecialchars($countryName); ?> - Daily Settlement & Transaction Reconciliation</p>
            </div>
            <div class="export-buttons">
                <a href="?<?php echo http_build_query(array_merge($_GET, array('export' => 'csv'))); ?>" class="btn btn-success">📥 Export CSV</a>
                <a href="daily_reconciliations.php" class="btn btn-secondary">🔄 Reset</a>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" id="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" id="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="filter-group">
                <label>Institution</label>
                <select id="institution">
                    <option value="">All Institutions</option>
                    <?php foreach ($institutions as $inst): ?>
                        <option value="<?php echo htmlspecialchars($inst); ?>" <?php echo ($institutionFilter == $inst) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($inst); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Currency</label>
                <select id="currency">
                    <option value="">All Currencies</option>
                    <option value="BWP" <?php echo ($currencyFilter == 'BWP') ? 'selected' : ''; ?>>BWP - Pula</option>
                    <option value="ZAR" <?php echo ($currencyFilter == 'ZAR') ? 'selected' : ''; ?>>ZAR - Rand</option>
                    <option value="USD" <?php echo ($currencyFilter == 'USD') ? 'selected' : ''; ?>>USD - Dollar</option>
                    <option value="EUR" <?php echo ($currencyFilter == 'EUR') ? 'selected' : ''; ?>>EUR - Euro</option>
                </select>
            </div>
            <div class="filter-group">
                <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Swaps</h3>
                <div class="value"><?php echo number_format($totalSwaps); ?></div>
                <div class="sub"><?php echo number_format($successful); ?> successful</div>
            </div>
            <div class="stat-card">
                <h3>Total Volume</h3>
                <div class="value"><?php echo number_format($totalVolume, 2); ?></div>
                <div class="sub"><?php echo htmlspecialchars($currencySymbol); ?></div>
            </div>
            <div class="stat-card">
                <h3>Fees Collected</h3>
                <div class="value"><?php echo number_format($totalFees, 2); ?></div>
                <div class="sub">+ <?php echo number_format($totalVat, 2); ?> VAT</div>
            </div>
            <div class="stat-card">
                <h3>Settlements</h3>
                <div class="value"><?php echo number_format(isset($settlementStats['total']) ? $settlementStats['total'] : 0); ?></div>
                <div class="sub"><?php echo number_format(isset($settlementStats['pending']) ? $settlementStats['pending'] : 0); ?> pending</div>
            </div>
            <div class="stat-card">
                <h3>Failed</h3>
                <div class="value" style="color: #dc3545;"><?php echo number_format($failed); ?></div>
                <div class="sub"><?php echo number_format($pending); ?> pending</div>
            </div>
            <div class="stat-card">
                <h3>Cancelled</h3>
                <div class="value" style="color: #6c757d;"><?php echo number_format($cancelled); ?></div>
                <div class="sub">Total transactions</div>
            </div>
        </div>
        
        <!-- Daily Breakdown -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('dailyBreakdown')">
                <h2>📆 Daily Breakdown</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="dailyBreakdown">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Transactions</th>
                            <th>Total Amount</th>
                            <th>Status Breakdown</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($dailyBreakdown)): ?>
                            <?php foreach ($dailyBreakdown as $row): ?>
                                <tr>
                                    <td><strong><?php echo date('D, M j, Y', strtotime($row['date'])); ?></strong></td>
                                    <td><?php echo number_format($row['transaction_count']); ?></td>
                                    <td><?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td>
                                        <?php foreach ($row['status'] as $status => $count): ?>
                                            <span class="status-badge status-<?php echo $status; ?>"><?php echo ucfirst($status); ?>: <?php echo $count; ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center;">No data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Fee Breakdown -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('feeBreakdown')">
                <h2>💰 Fee Breakdown</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="feeBreakdown">
                <table>
                    <thead>
                        <tr>
                            <th>Fee Type</th>
                            <th>Count</th>
                            <th>Total Amount</th>
                            <th>VAT</th>
                            <th>Currency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($feeBreakdown)): ?>
                            <?php foreach ($feeBreakdown as $fee): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                    <td><?php echo number_format($fee['count']); ?></td>
                                    <td><strong><?php echo number_format($fee['total_amount'], 2); ?></strong></td>
                                    <td><?php echo number_format($fee['total_vat'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($fee['currency']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No fee data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Settlement Outbox -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('settlementOutbox')">
                <h2>📤 Settlement Outbox</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="settlementOutbox">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Count</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="status-badge status-pending">Pending</span></td>
                            <td><?php echo number_format(isset($settlementStats['pending']) ? $settlementStats['pending'] : 0); ?></td>
                            <td><?php echo number_format(isset($settlementStats['total_amount']) ? $settlementStats['total_amount'] : 0, 2); ?></td>
                        </tr>
                        <tr>
                            <td><span class="status-badge" style="background:#cce5ff;color:#004085;">Sent</span></td>
                            <td><?php echo number_format(isset($settlementStats['sent']) ? $settlementStats['sent'] : 0); ?></td>
                            <td>-</td>
                        </tr>
                        <tr>
                            <td><span class="status-badge status-acknowledged">Acknowledged</span></td>
                            <td><?php echo number_format(isset($settlementStats['acknowledged']) ? $settlementStats['acknowledged'] : 0); ?></td>
                            <td>-</td>
                        </tr>
                        <tr style="font-weight:bold;">
                            <td>Total</td>
                            <td><?php echo number_format(isset($settlementStats['total']) ? $settlementStats['total'] : 0); ?></td>
                            <td><?php echo number_format(isset($settlementStats['total_amount']) ? $settlementStats['total_amount'] : 0, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Transactions Table -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('transactions')">
                <h2>📋 Transactions</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="transactions">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Fee</th>
                            <th>Retry</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $tx): 
                                $statusClass = match($tx['status'] ?? '') {
                                    'completed' => 'status-completed',
                                    'failed' => 'status-failed',
                                    'pending' => 'status-pending',
                                    'cancelled' => 'status-cancelled',
                                    default => ''
                                };
                            ?>
                                <tr>
                                    <td><code><?php echo substr(htmlspecialchars($tx['swap_uuid'] ?? $tx['swap_id']), 0, 16); ?>...</code></td>
                                    <td><strong><?php echo number_format($tx['amount'] ?? 0, 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($tx['source_currency'] ?? 'N/A'); ?></td>
                                    <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($tx['status'] ?? 'N/A'); ?></span></td>
                                    <td><?php echo number_format($tx['collected_fee'] ?? 0, 2); ?></td>
                                    <td><?php echo $tx['retry_count'] ?? 0; ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align:center;">No transactions found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function applyFilters() {
            var dateFrom = document.getElementById('date_from').value;
            var dateTo = document.getElementById('date_to').value;
            var institution = document.getElementById('institution').value;
            var currency = document.getElementById('currency').value;
            
            var params = new URLSearchParams();
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
            if (institution) params.append('institution', institution);
            if (currency) params.append('currency', currency);
            
            window.location.href = '?' + params.toString();
        }
        
        function toggleSection(sectionId) {
            var section = document.getElementById(sectionId);
            var header = section.previousElementSibling;
            var arrow = header.querySelector('span');
            
            if (section.style.display === 'none') {
                section.style.display = 'block';
                arrow.textContent = '▼';
            } else {
                section.style.display = 'none';
                arrow.textContent = '▶';
            }
        }
        
        // Initialize sections
        var sections = document.querySelectorAll('.section-content');
        for (var i = 0; i < sections.length; i++) {
            sections[i].style.display = 'block';
        }
    </script>
</body>
</html>
