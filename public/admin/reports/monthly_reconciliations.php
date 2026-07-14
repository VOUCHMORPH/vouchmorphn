<?php
declare(strict_types=1);

// --- SYSTEM & SESSION SETUP ---
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use Domain\Services\FeeService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\ForexService;
use Core\Database\DBConnection;

// Session management
session_start();

// Check if admin is logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: ../admin_login.php');
    exit;
}

$user = $_SESSION['user'];
$countryCode = isset($user['country_code']) ? $user['country_code'] : 'BW';

// --- LOAD COUNTRY CONFIGURATION ---
$countryConfigPath = __DIR__ . "/../../../config/countries/" . strtolower($countryCode) . "/config.php";
$countryConfig = file_exists($countryConfigPath) ? require $countryConfigPath : array();

// --- DATABASE CONNECTION ---
try {
    $swapDB = DBConnection::getConnection();
    if (!$swapDB) {
        throw new Exception("Database connection failed");
    }
    error_log("[MONTHLY RECONCILIATION] Database connected successfully");
} catch (Exception $e) {
    error_log("[MONTHLY RECONCILIATION] DB Error: " . $e->getMessage());
    die("Database connection failed. Please check configuration.");
}

// --- LOAD FEES CONFIGURATION ---
$feesConfigPath = __DIR__ . "/../../../config/countries/" . strtolower($countryCode) . "/fees.json";
$feesConfig = file_exists($feesConfigPath) ? json_decode(file_get_contents($feesConfigPath), true) : array();

// --- INITIALIZE SERVICES ---
$feeService = new FeeService($feesConfig, 'BWP');
$settlement = new HybridSettlementStrategy($swapDB);
$forexService = new ForexService($swapDB, $countryConfig, array(), $feeService);

// --- DATE RANGE ---
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$firstDayOfMonth = date('Y-m-01', strtotime($year . '-' . $month . '-01'));
$lastDayOfMonth = date('Y-m-t', strtotime($year . '-' . $month . '-01'));
$institutionFilter = isset($_GET['institution']) ? $_GET['institution'] : null;
$currencyFilter = isset($_GET['currency']) ? $_GET['currency'] : null;

// --- FETCH MONTHLY SUMMARY ---
$summaryQuery = "
    SELECT 
        COUNT(*) AS total_swaps,
        COALESCE(SUM(amount), 0) AS total_amount,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_swaps,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS successful_swaps,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_swaps,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_swaps
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
";
$stmt = $swapDB->prepare($summaryQuery);
$stmt->execute(array(':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth));
$swapsSummary = $stmt->fetch(PDO::FETCH_ASSOC);

// --- FETCH DAILY BREAKDOWN ---
$dailyQuery = "
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as total_transactions,
        COALESCE(SUM(amount), 0) as total_amount,
        status
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    GROUP BY DATE(created_at), status
    ORDER BY date DESC, status
";
$stmt = $swapDB->prepare($dailyQuery);
$stmt->execute(array(':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth));
$rawDaily = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date
$dailyBreakdown = array();
foreach ($rawDaily as $row) {
    $date = $row['date'];
    if (!isset($dailyBreakdown[$date])) {
        $dailyBreakdown[$date] = array(
            'date' => $date,
            'total_transactions' => 0,
            'total_amount' => 0,
            'status' => array()
        );
    }
    $dailyBreakdown[$date]['total_transactions'] += $row['total_transactions'];
    $dailyBreakdown[$date]['total_amount'] += $row['total_amount'];
    $dailyBreakdown[$date]['status'][$row['status']] = $row['total_transactions'];
}

// --- FETCH FEE BREAKDOWN ---
$feeQuery = "
    SELECT 
        sfc.fee_type,
        COUNT(*) as count,
        COALESCE(SUM(sfc.total_amount), 0) as total_amount,
        COALESCE(SUM(sfc.vat_amount), 0) as total_vat,
        sfc.currency
    FROM swap_fee_collections sfc
    JOIN swap_requests sr ON sfc.swap_reference = sr.swap_uuid
    WHERE DATE(sr.created_at) BETWEEN :start_date AND :end_date
    GROUP BY sfc.fee_type, sfc.currency
    ORDER BY total_amount DESC
";
$stmt = $swapDB->prepare($feeQuery);
$stmt->execute(array(':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth));
$feeBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH CROSS-BORDER ACTIVITY ---
$crossBorderQuery = "
    SELECT 
        source_country,
        destination_country,
        COUNT(*) as transaction_count,
        COALESCE(SUM(source_amount), 0) as total_source_amount,
        COALESCE(SUM(converted_amount), 0) as total_destination_amount,
        COALESCE(SUM(corridor_fee), 0) as total_corridor_fees,
        source_currency,
        destination_currency,
        COALESCE(AVG(exchange_rate), 0) as avg_exchange_rate
    FROM cross_border_ledger
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    GROUP BY source_country, destination_country, source_currency, destination_currency
    ORDER BY transaction_count DESC
";
$stmt = $swapDB->prepare($crossBorderQuery);
$stmt->execute(array(':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth));
$crossBorderActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH RETRY STATISTICS ---
$retryQuery = "
    SELECT 
        COUNT(*) as total_retries,
        SUM(CASE WHEN free_retry_used THEN 1 ELSE 0 END) as free_retries_used,
        SUM(CASE WHEN free_retry_used = FALSE THEN 1 ELSE 0 END) as paid_retries,
        COALESCE(AVG(retry_count), 0) as avg_retry_count
    FROM cashout_retry_tracking
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
";
$stmt = $swapDB->prepare($retryQuery);
$stmt->execute(array(':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth));
$retryStats = $stmt->fetch(PDO::FETCH_ASSOC);

// --- FETCH CORRIDOR ACTIVITY ---
$corridorQuery = "
    SELECT 
        source_country,
        destination_country,
        COUNT(*) as settlement_count,
        COALESCE(SUM(source_amount), 0) as total_settled,
        COALESCE(SUM(corridor_fee), 0) as total_fees
    FROM corridor_settlement_ledger
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    GROUP BY source_country, destination_country
    ORDER BY settlement_count DESC
";
$stmt = $swapDB->prepare($corridorQuery);
$stmt->execute(array(':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth));
$corridorActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH FX ACTIVITY ---
$fxQuery = "
    SELECT 
        source_currency,
        destination_currency,
        COUNT(*) as fx_transactions,
        COALESCE(SUM(amount), 0) as total_fx_volume,
        COALESCE(SUM(forex_fee_amount), 0) as total_forex_fees,
        COALESCE(AVG(exchange_rate), 0) as avg_rate
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    AND source_currency != destination_currency
    GROUP BY source_currency, destination_currency
";
$stmt = $swapDB->prepare($fxQuery);
$stmt->execute(array(':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth));
$fxActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH HOOK STATISTICS ---
$hookQuery = "
    SELECT 
        COUNT(*) as total_hooks,
        COUNT(DISTINCT user_identifier) as unique_users,
        SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as active_hooks
    FROM user_hooks
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
";
$stmt = $swapDB->prepare($hookQuery);
$stmt->execute(array(':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth));
$hookStats = $stmt->fetch(PDO::FETCH_ASSOC);

// --- FETCH TOP INSTITUTIONS ---
$topInstitutionsQuery = "
    SELECT 
        source_details->>'institution' as institution,
        COUNT(*) as transaction_count,
        COALESCE(SUM(amount), 0) as total_volume,
        COALESCE(SUM(total_cashout_fee), 0) as total_fees
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    AND source_details IS NOT NULL
    AND source_details->>'institution' IS NOT NULL
    GROUP BY source_details->>'institution'
    ORDER BY total_volume DESC
    LIMIT 10
";
$stmt = $swapDB->prepare($topInstitutionsQuery);
$stmt->execute(array(':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth));
$topInstitutions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- CALCULATE TOTALS ---
$totalFxVolume = 0;
$totalForexFees = 0;
foreach ($fxActivity as $fx) {
    $totalFxVolume += $fx['total_fx_volume'];
    $totalForexFees += $fx['total_forex_fees'];
}

$totalFees = 0;
$totalVat = 0;
foreach ($feeBreakdown as $fee) {
    $totalFees += $fee['total_amount'];
    $totalVat += $fee['total_vat'];
}

// --- GET INSTITUTIONS LIST ---
$institutions = array();
try {
    $instStmt = $swapDB->query("
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
    error_log("[MONTHLY RECONCILIATION] Institutions error: " . $e->getMessage());
    $institutions = array();
}

// --- AUDIT LOG ---
$username = isset($user['username']) ? $user['username'] : 'unknown';
$logFile = __DIR__ . '/../../../storage/logs/monthly_reconciliations.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Monthly reconciliations run for {$firstDayOfMonth} to {$lastDayOfMonth} by {$username}\n", FILE_APPEND);

// --- CSV EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="monthly_reconciliation_' . $year . '_' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array('VOUCHMORPH MONTHLY RECONCILIATION REPORT'));
    fputcsv($output, array('Month', date('F Y', strtotime($year . '-' . $month . '-01'))));
    fputcsv($output, array('Generated At', date('Y-m-d H:i:s')));
    fputcsv($output, array());
    
    // Summary
    fputcsv($output, array('SUMMARY'));
    fputcsv($output, array('Total Swaps', isset($swapsSummary['total_swaps']) ? $swapsSummary['total_swaps'] : 0));
    fputcsv($output, array('Successful', isset($swapsSummary['successful_swaps']) ? $swapsSummary['successful_swaps'] : 0));
    fputcsv($output, array('Failed', isset($swapsSummary['failed_swaps']) ? $swapsSummary['failed_swaps'] : 0));
    fputcsv($output, array('Pending', isset($swapsSummary['pending_swaps']) ? $swapsSummary['pending_swaps'] : 0));
    fputcsv($output, array('Cancelled', isset($swapsSummary['cancelled_swaps']) ? $swapsSummary['cancelled_swaps'] : 0));
    fputcsv($output, array('Total Volume', number_format(isset($swapsSummary['total_amount']) ? $swapsSummary['total_amount'] : 0, 2)));
    fputcsv($output, array('Total Fees', number_format($totalFees, 2)));
    fputcsv($output, array('Total VAT', number_format($totalVat, 2)));
    fputcsv($output, array());
    
    fclose($output);
    exit;
}

// --- MONTH NAVIGATION ---
$prevMonth = date('Y-m', strtotime("-1 month", strtotime($year . '-' . $month . '-01')));
$nextMonth = date('Y-m', strtotime("+1 month", strtotime($year . '-' . $month . '-01')));
$monthName = date('F Y', strtotime($year . '-' . $month . '-01'));

// Safe values
$totalSwaps = isset($swapsSummary['total_swaps']) ? $swapsSummary['total_swaps'] : 0;
$successfulSwaps = isset($swapsSummary['successful_swaps']) ? $swapsSummary['successful_swaps'] : 0;
$failedSwaps = isset($swapsSummary['failed_swaps']) ? $swapsSummary['failed_swaps'] : 0;
$pendingSwaps = isset($swapsSummary['pending_swaps']) ? $swapsSummary['pending_swaps'] : 0;
$cancelledSwaps = isset($swapsSummary['cancelled_swaps']) ? $swapsSummary['cancelled_swaps'] : 0;
$totalAmount = isset($swapsSummary['total_amount']) ? $swapsSummary['total_amount'] : 0;
$retryTotal = isset($retryStats['total_retries']) ? $retryStats['total_retries'] : 0;
$retryFree = isset($retryStats['free_retries_used']) ? $retryStats['free_retries_used'] : 0;
$hookActive = isset($hookStats['active_hooks']) ? $hookStats['active_hooks'] : 0;
$hookUsers = isset($hookStats['unique_users']) ? $hookStats['unique_users'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Reconciliations - VouchMorph</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; color: #333; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: white; padding: 20px 30px; border-radius: 12px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .header h1 { font-size: 24px; font-weight: 600; }
        .header p { opacity: 0.8; font-size: 14px; }
        .month-nav { display: flex; gap: 15px; align-items: center; }
        .month-nav .nav-btn { background: rgba(255,255,255,0.2); padding: 8px 20px; border-radius: 8px; text-decoration: none; color: white; transition: background 0.3s; }
        .month-nav .nav-btn:hover { background: rgba(255,255,255,0.3); }
        .month-badge { background: rgba(255,255,255,0.2); padding: 8px 20px; border-radius: 20px; font-size: 16px; font-weight: 600; }
        .filter-bar { background: white; padding: 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: #666; text-transform: uppercase; }
        .filter-group select { padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; min-width: 180px; }
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover { background: #0056b3; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #1e7e34; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 10px; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #1a1a2e; }
        .stat-card .trend { font-size: 12px; margin-top: 5px; }
        .trend-up { color: #28a745; }
        .trend-down { color: #dc3545; }
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
        .summary-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; }
        .summary-card { background: white; padding: 15px; border-radius: 12px; text-align: center; border-left: 4px solid; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .summary-cards { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { flex-direction: column; }
            .filter-group select { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>📆 Monthly Reconciliations Report</h1>
                <p><?php echo htmlspecialchars($countryCode); ?> - Settlement & Transaction Reconciliation</p>
            </div>
            <div class="month-nav">
                <a href="?year=<?php echo explode('-', $prevMonth)[0]; ?>&month=<?php echo explode('-', $prevMonth)[1]; ?>" class="nav-btn">← Previous</a>
                <div class="month-badge"><?php echo $monthName; ?></div>
                <a href="?year=<?php echo explode('-', $nextMonth)[0]; ?>&month=<?php echo explode('-', $nextMonth)[1]; ?>" class="nav-btn">Next →</a>
                <a href="?year=<?php echo date('Y'); ?>&month=<?php echo date('m'); ?>" class="nav-btn">Current</a>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label>Institution</label>
                <select id="institution">
                    <option value="">All</option>
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
                    <option value="">All</option>
                    <option value="BWP" <?php echo ($currencyFilter == 'BWP') ? 'selected' : ''; ?>>BWP</option>
                    <option value="ZAR" <?php echo ($currencyFilter == 'ZAR') ? 'selected' : ''; ?>>ZAR</option>
                    <option value="USD" <?php echo ($currencyFilter == 'USD') ? 'selected' : ''; ?>>USD</option>
                    <option value="EUR" <?php echo ($currencyFilter == 'EUR') ? 'selected' : ''; ?>>EUR</option>
                </select>
            </div>
            <div class="filter-group">
                <button class="btn btn-primary" onclick="applyFilters()">Apply</button>
                <a href="?<?php echo http_build_query(array_merge($_GET, array('export' => 'csv'))); ?>" class="btn btn-success">📥 CSV</a>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Swaps</h3>
                <div class="value"><?php echo number_format($totalSwaps); ?></div>
                <div class="trend trend-up">↑ <?php echo number_format($successfulSwaps); ?> successful</div>
            </div>
            <div class="stat-card">
                <h3>Total Volume</h3>
                <div class="value"><?php echo number_format($totalAmount, 2); ?></div>
                <div class="trend">All currencies</div>
            </div>
            <div class="stat-card">
                <h3>Fees Collected</h3>
                <div class="value"><?php echo number_format($totalFees, 2); ?></div>
                <div class="trend">+ <?php echo number_format($totalVat, 2); ?> VAT</div>
            </div>
            <div class="stat-card">
                <h3>Retries</h3>
                <div class="value"><?php echo number_format($retryTotal); ?></div>
                <div class="trend"><?php echo number_format($retryFree); ?> free</div>
            </div>
            <div class="stat-card">
                <h3>FX Volume</h3>
                <div class="value"><?php echo number_format($totalFxVolume, 2); ?></div>
                <div class="trend">Fees: <?php echo number_format($totalForexFees, 2); ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Hooks</h3>
                <div class="value"><?php echo number_format($hookActive); ?></div>
                <div class="trend"><?php echo number_format($hookUsers); ?> users</div>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card" style="border-left-color: #28a745;">
                <div style="font-size: 24px; font-weight: bold;"><?php echo number_format($successfulSwaps); ?></div>
                <div style="font-size: 12px; color: #666;">Successful</div>
            </div>
            <div class="summary-card" style="border-left-color: #dc3545;">
                <div style="font-size: 24px; font-weight: bold;"><?php echo number_format($failedSwaps); ?></div>
                <div style="font-size: 12px; color: #666;">Failed</div>
            </div>
            <div class="summary-card" style="border-left-color: #ffc107;">
                <div style="font-size: 24px; font-weight: bold;"><?php echo number_format($pendingSwaps); ?></div>
                <div style="font-size: 12px; color: #666;">Pending</div>
            </div>
            <div class="summary-card" style="border-left-color: #6c757d;">
                <div style="font-size: 24px; font-weight: bold;"><?php echo number_format($cancelledSwaps); ?></div>
                <div style="font-size: 12px; color: #666;">Cancelled</div>
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
                        <tr><th>Date</th><th>Transactions</th><th>Amount</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($dailyBreakdown)): ?>
                            <?php foreach ($dailyBreakdown as $row): ?>
                                <tr>
                                    <td><strong><?php echo date('D, M j', strtotime($row['date'])); ?></strong></td>
                                    <td><?php echo number_format($row['total_transactions']); ?></td>
                                    <td><?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td>
                                        <?php foreach ($row['status'] as $status => $count): ?>
                                            <span class="status-badge status-<?php echo $status; ?>"><?php echo ucfirst($status); ?>: <?php echo $count; ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center;">No data</td></tr>
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
                        <tr><th>Type</th><th>Count</th><th>Amount</th><th>VAT</th><th>Currency</th></tr>
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
                            <tr><td colspan="5" style="text-align:center;">No fee data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Top Institutions -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('topInstitutions')">
                <h2>🏦 Top Institutions</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="topInstitutions">
                <table>
                    <thead>
                        <tr><th>#</th><th>Institution</th><th>Transactions</th><th>Volume</th><th>Fees</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($topInstitutions)): ?>
                            <?php $rank = 1; ?>
                            <?php foreach ($topInstitutions as $inst): ?>
                                <tr>
                                    <td><strong>#<?php echo $rank++; ?></strong></td>
                                    <td><?php echo htmlspecialchars($inst['institution']); ?></td>
                                    <td><?php echo number_format($inst['transaction_count']); ?></td>
                                    <td><?php echo number_format($inst['total_volume'], 2); ?></td>
                                    <td><?php echo number_format($inst['total_fees'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function applyFilters() {
            var institution = document.getElementById('institution').value;
            var currency = document.getElementById('currency').value;
            var params = new URLSearchParams(window.location.search);
            
            if (institution) params.set('institution', institution);
            else params.delete('institution');
            if (currency) params.set('currency', currency);
            else params.delete('currency');
            
            var year = new URLSearchParams(window.location.search).get('year');
            var month = new URLSearchParams(window.location.search).get('month');
            if (year) params.set('year', year);
            if (month) params.set('month', month);
            
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
        
        var sections = document.querySelectorAll('.section-content');
        for (var i = 0; i < sections.length; i++) {
            sections[i].style.display = 'block';
        }
    </script>
</body>
</html>
