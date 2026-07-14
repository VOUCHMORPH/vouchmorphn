<?php
declare(strict_types=1);

// --- SYSTEM & SESSION SETUP ---
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use Domain\Services\FeeService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\ForexService;

// Session management
session_start();

// Check if admin is logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: ../admin_login.php');
    exit;
}

$user = $_SESSION['user'];
$countryCode = $user['country_code'] ?? 'BW';

// --- LOAD COUNTRY CONFIGURATION ---
$countryConfigPath = __DIR__ . "/../../../config/countries/" . strtolower($countryCode) . "/config.php";
$countryConfig = file_exists($countryConfigPath) ? require $countryConfigPath : [];

// --- DATABASE CONNECTION ---
$dbConfig = require __DIR__ . '/../../../config/database.php';
$swapDB = new PDO(
    "pgsql:host={$dbConfig['host']};dbname={$dbConfig['dbname']}",
    $dbConfig['user'],
    $dbConfig['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- LOAD FEES CONFIGURATION ---
$feesConfigPath = __DIR__ . "/../../../config/countries/" . strtolower($countryCode) . "/fees.json";
$feesConfig = file_exists($feesConfigPath) ? json_decode(file_get_contents($feesConfigPath), true) : [];

// --- INITIALIZE SERVICES ---
$feeService = new FeeService($feesConfig, 'BWP');
$settlement = new HybridSettlementStrategy($swapDB);
$forexService = new ForexService($swapDB, $countryConfig, [], $feeService);

// --- DATE RANGE (Current Month or Selected) ---
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');
$firstDayOfMonth = date('Y-m-01', strtotime("{$year}-{$month}-01"));
$lastDayOfMonth = date('Y-m-t', strtotime("{$year}-{$month}-01"));
$institutionFilter = $_GET['institution'] ?? null;
$currencyFilter = $_GET['currency'] ?? null;

// --- FETCH MONTHLY LEDGER RECONCILIATIONS ---
$reconciliationsQuery = "
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

$stmt = $swapDB->prepare($reconciliationsQuery);
$stmt->execute([':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth]);
$rawReconciliations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group reconciliations by date
$reconciliations = [];
foreach ($rawReconciliations as $row) {
    $date = $row['date'];
    if (!isset($reconciliations[$date])) {
        $reconciliations[$date] = [
            'date' => $date,
            'total_transactions' => 0,
            'total_amount' => 0,
            'status' => []
        ];
    }
    $reconciliations[$date]['total_transactions'] += $row['total_transactions'];
    $reconciliations[$date]['total_amount'] += $row['total_amount'];
    $reconciliations[$date]['status'][$row['status']] = $row['total_transactions'];
}

// --- FETCH MONTHLY SWAPS SUMMARY ---
$swapsQuery = "
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
$stmt = $swapDB->prepare($swapsQuery);
$stmt->execute([':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth]);
$swapsSummary = $stmt->fetch(PDO::FETCH_ASSOC);

// --- FETCH MONTHLY FEE BREAKDOWN ---
$feeQuery = "
    SELECT 
        sfc.fee_type,
        COUNT(*) as count,
        SUM(sfc.total_amount) as total_amount,
        SUM(sfc.vat_amount) as total_vat,
        sfc.currency
    FROM swap_fee_collections sfc
    JOIN swap_requests sr ON sfc.swap_reference = sr.swap_uuid
    WHERE DATE(sr.created_at) BETWEEN :start_date AND :end_date
    GROUP BY sfc.fee_type, sfc.currency
    ORDER BY total_amount DESC
";
$stmt = $swapDB->prepare($feeQuery);
$stmt->execute([':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth]);
$feeBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH MONTHLY CROSS-BORDER ACTIVITY ---
$crossBorderQuery = "
    SELECT 
        source_country,
        destination_country,
        COUNT(*) as transaction_count,
        SUM(source_amount) as total_source_amount,
        SUM(converted_amount) as total_destination_amount,
        SUM(corridor_fee) as total_corridor_fees,
        source_currency,
        destination_currency,
        AVG(exchange_rate) as avg_exchange_rate
    FROM cross_border_ledger
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    GROUP BY source_country, destination_country, source_currency, destination_currency
    ORDER BY transaction_count DESC
";
$stmt = $swapDB->prepare($crossBorderQuery);
$stmt->execute([':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth]);
$crossBorderActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH MONTHLY CASHOUT RETRY STATISTICS ---
$retryQuery = "
    SELECT 
        COUNT(*) as total_retries,
        SUM(CASE WHEN free_retry_used THEN 1 ELSE 0 END) as free_retries_used,
        SUM(CASE WHEN free_retry_used = FALSE THEN 1 ELSE 0 END) as paid_retries,
        AVG(retry_count) as avg_retry_count
    FROM cashout_retry_tracking
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
";
$stmt = $swapDB->prepare($retryQuery);
$stmt->execute([':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth]);
$retryStats = $stmt->fetch(PDO::FETCH_ASSOC);

// --- FETCH MONTHLY CORRIDOR ACTIVITY ---
$corridorQuery = "
    SELECT 
        source_country,
        destination_country,
        COUNT(*) as settlement_count,
        SUM(source_amount) as total_settled,
        SUM(corridor_fee) as total_fees
    FROM corridor_settlement_ledger
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    GROUP BY source_country, destination_country
    ORDER BY settlement_count DESC
";
$stmt = $swapDB->prepare($corridorQuery);
$stmt->execute([':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth]);
$corridorActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH MONTHLY FX ACTIVITY ---
$fxQuery = "
    SELECT 
        source_currency,
        destination_currency,
        COUNT(*) as fx_transactions,
        SUM(amount) as total_fx_volume,
        SUM(forex_fee_amount) as total_forex_fees,
        AVG(exchange_rate) as avg_rate
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    AND source_currency != destination_currency
    GROUP BY source_currency, destination_currency
";
$stmt = $swapDB->prepare($fxQuery);
$stmt->execute([':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth]);
$fxActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH MONTHLY HOOK STATISTICS ---
$hookQuery = "
    SELECT 
        COUNT(*) as total_hooks,
        COUNT(DISTINCT user_identifier) as unique_users,
        SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as active_hooks
    FROM user_hooks
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
";
$stmt = $swapDB->prepare($hookQuery);
$stmt->execute([':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth]);
$hookStats = $stmt->fetch(PDO::FETCH_ASSOC);

// --- FETCH TOP INSTITUTIONS BY VOLUME ---
// FIX: Use JSON_EXTRACT for PostgreSQL compatibility
$topInstitutionsQuery = "
    SELECT 
        source_details->>'institution' as institution,
        COUNT(*) as transaction_count,
        SUM(amount) as total_volume,
        SUM(total_cashout_fee) as total_fees
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
    AND source_details IS NOT NULL
    AND source_details->>'institution' IS NOT NULL
    GROUP BY source_details->>'institution'
    ORDER BY total_volume DESC
    LIMIT 10
";
$stmt = $swapDB->prepare($topInstitutionsQuery);
$stmt->execute([':start_date' => $firstDayOfMonth, ':end_date' => $lastDayOfMonth]);
$topInstitutions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- CALCULATE TOTALS ---
$totalFxVolume = array_sum(array_column($fxActivity, 'total_fx_volume'));
$totalForexFees = array_sum(array_column($fxActivity, 'total_forex_fees'));
$totalFees = array_sum(array_column($feeBreakdown, 'total_amount'));
$totalVat = array_sum(array_column($feeBreakdown, 'total_vat'));

// --- GET INSTITUTIONS LIST FOR FILTER ---
// FIX: Use JSON_EXTRACT for PostgreSQL compatibility
$instStmt = $swapDB->prepare("
    SELECT DISTINCT 
        source_details->>'institution' as institution
    FROM swap_requests
    WHERE source_details IS NOT NULL
    AND source_details->>'institution' IS NOT NULL
    UNION
    SELECT DISTINCT 
        destination_details->>'institution' as institution
    FROM swap_requests
    WHERE destination_details IS NOT NULL
    AND destination_details->>'institution' IS NOT NULL
");
$instStmt->execute();
$institutions = $instStmt->fetchAll(PDO::FETCH_COLUMN);

// Filter out empty values
$institutions = array_filter($institutions);

// --- AUDIT LOG ---
$logFile = __DIR__ . '/../../../storage/logs/monthly_reconciliations.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Monthly reconciliations run for {$firstDayOfMonth} to {$lastDayOfMonth} by {$user['username'] ?? 'unknown'}\n", FILE_APPEND);

// --- CSV EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="monthly_reconciliation_' . $year . '_' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['VOUCHMORPH MONTHLY RECONCILIATION REPORT']);
    fputcsv($output, ['Month', date('F Y', strtotime("{$year}-{$month}-01"))]);
    fputcsv($output, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // Summary
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Swaps', $swapsSummary['total_swaps'] ?? 0]);
    fputcsv($output, ['Successful Swaps', $swapsSummary['successful_swaps'] ?? 0]);
    fputcsv($output, ['Failed Swaps', $swapsSummary['failed_swaps'] ?? 0]);
    fputcsv($output, ['Pending Swaps', $swapsSummary['pending_swaps'] ?? 0]);
    fputcsv($output, ['Cancelled Swaps', $swapsSummary['cancelled_swaps'] ?? 0]);
    fputcsv($output, ['Total Volume', number_format($swapsSummary['total_amount'] ?? 0, 2)]);
    fputcsv($output, ['Total Fees Collected', number_format($totalFees, 2)]);
    fputcsv($output, ['Total VAT', number_format($totalVat, 2)]);
    fputcsv($output, ['Total FX Volume', number_format($totalFxVolume, 2)]);
    fputcsv($output, ['Total Forex Fees', number_format($totalForexFees, 2)]);
    fputcsv($output, ['Total Retries', $retryStats['total_retries'] ?? 0]);
    fputcsv($output, ['Free Retries Used', $retryStats['free_retries_used'] ?? 0]);
    fputcsv($output, []);
    
    // Daily Breakdown
    fputcsv($output, ['DAILY BREAKDOWN']);
    fputcsv($output, ['Date', 'Total Transactions', 'Total Amount', 'Status Breakdown']);
    foreach ($reconciliations as $row) {
        $statusStr = '';
        foreach ($row['status'] as $status => $count) {
            $statusStr .= "$status: $count, ";
        }
        fputcsv($output, [
            $row['date'],
            $row['total_transactions'],
            number_format($row['total_amount'], 2),
            rtrim($statusStr, ', ')
        ]);
    }
    fputcsv($output, []);
    
    // Fee Breakdown
    fputcsv($output, ['FEE BREAKDOWN']);
    fputcsv($output, ['Fee Type', 'Count', 'Total Amount', 'VAT', 'Currency']);
    foreach ($feeBreakdown as $fee) {
        fputcsv($output, [
            $fee['fee_type'],
            $fee['count'],
            number_format($fee['total_amount'], 2),
            number_format($fee['total_vat'], 2),
            $fee['currency']
        ]);
    }
    fputcsv($output, []);
    
    // Cross Border Activity
    fputcsv($output, ['CROSS-BORDER ACTIVITY']);
    fputcsv($output, ['From', 'To', 'Transactions', 'Volume (Source)', 'Volume (Dest)', 'Corridor Fees', 'Avg Rate']);
    foreach ($crossBorderActivity as $cb) {
        fputcsv($output, [
            $cb['source_country'],
            $cb['destination_country'],
            $cb['transaction_count'],
            number_format($cb['total_source_amount'], 2),
            number_format($cb['total_destination_amount'], 2),
            number_format($cb['total_corridor_fees'] ?? 0, 2),
            number_format($cb['avg_exchange_rate'], 4)
        ]);
    }
    fputcsv($output, []);
    
    // FX Activity
    fputcsv($output, ['FX ACTIVITY']);
    fputcsv($output, ['From', 'To', 'Transactions', 'Volume', 'Forex Fees', 'Avg Rate']);
    foreach ($fxActivity as $fx) {
        fputcsv($output, [
            $fx['source_currency'],
            $fx['destination_currency'],
            $fx['fx_transactions'],
            number_format($fx['total_fx_volume'], 2),
            number_format($fx['total_forex_fees'], 2),
            number_format($fx['avg_rate'], 4)
        ]);
    }
    fputcsv($output, []);
    
    // Top Institutions
    fputcsv($output, ['TOP INSTITUTIONS BY VOLUME']);
    fputcsv($output, ['Institution', 'Transactions', 'Total Volume', 'Total Fees']);
    foreach ($topInstitutions as $inst) {
        fputcsv($output, [
            $inst['institution'],
            $inst['transaction_count'],
            number_format($inst['total_volume'], 2),
            number_format($inst['total_fees'] ?? 0, 2)
        ]);
    }
    
    fclose($output);
    exit;
}

// --- MONTH NAVIGATION ---
$prevMonth = date('Y-m', strtotime("-1 month", strtotime("{$year}-{$month}-01")));
$nextMonth = date('Y-m', strtotime("+1 month", strtotime("{$year}-{$month}-01")));
$monthName = date('F Y', strtotime("{$year}-{$month}-01"));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Reconciliations - VouchMorph</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            color: #333;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .dashboard-header h1 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .dashboard-header p {
            opacity: 0.8;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .month-nav {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .month-nav .nav-btn {
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: white;
            transition: background 0.3s;
        }
        
        .month-nav .nav-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .month-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 16px;
            font-weight: 600;
        }
        
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
        }
        
        .filter-group select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            min-width: 180px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #1e7e34;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
            color: #1a1a2e;
        }
        
        .stat-card .trend {
            font-size: 12px;
            margin-top: 5px;
        }
        
        .trend-up { color: #28a745; }
        .trend-down { color: #dc3545; }
        .trend-neutral { color: #ffc107; }
        
        .section {
            background: white;
            border-radius: 12px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .section-header h2 {
            font-size: 18px;
            font-weight: 600;
        }
        
        .section-header span {
            transition: transform 0.2s;
        }
        
        .section-content {
            padding: 20px;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-cancelled { background: #e2e3e5; color: #383d41; }
        
        .export-buttons {
            display: flex;
            gap: 10px;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .summary-card {
            background: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            border-left: 4px solid;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-bar {
                flex-direction: column;
            }
            .filter-group select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div>
                <h1>📆 Monthly Reconciliations Report</h1>
                <p>VouchMorph Settlement & Transaction Reconciliation - Monthly Overview</p>
            </div>
            <div class="month-nav">
                <a href="?year=<?= explode('-', $prevMonth)[0] ?>&month=<?= explode('-', $prevMonth)[1] ?>" class="nav-btn">← Previous Month</a>
                <div class="month-badge"><?= $monthName ?></div>
                <a href="?year=<?= explode('-', $nextMonth)[0] ?>&month=<?= explode('-', $nextMonth)[1] ?>" class="nav-btn">Next Month →</a>
                <a href="?year=<?= date('Y') ?>&month=<?= date('m') ?>" class="nav-btn">Current Month</a>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label>Institution</label>
                <select id="institution">
                    <option value="">All Institutions</option>
                    <?php foreach ($institutions as $inst): ?>
                        <option value="<?= htmlspecialchars($inst) ?>" <?= ($institutionFilter == $inst) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($inst) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Currency</label>
                <select id="currency">
                    <option value="">All Currencies</option>
                    <option value="BWP" <?= ($currencyFilter == 'BWP') ? 'selected' : '' ?>>BWP - Pula</option>
                    <option value="ZAR" <?= ($currencyFilter == 'ZAR') ? 'selected' : '' ?>>ZAR - Rand</option>
                    <option value="USD" <?= ($currencyFilter == 'USD') ? 'selected' : '' ?>>USD - Dollar</option>
                    <option value="EUR" <?= ($currencyFilter == 'EUR') ? 'selected' : '' ?>>EUR - Euro</option>
                    <option value="NGN" <?= ($currencyFilter == 'NGN') ? 'selected' : '' ?>>NGN - Naira</option>
                    <option value="KES" <?= ($currencyFilter == 'KES') ? 'selected' : '' ?>>KES - Shilling</option>
                </select>
            </div>
            <div class="filter-group">
                <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
                <button class="btn btn-success" onclick="exportCSV()">Export CSV</button>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Swaps</h3>
                <div class="value"><?= number_format($swapsSummary['total_swaps'] ?? 0) ?></div>
                <div class="trend trend-up">↑ <?= number_format($swapsSummary['successful_swaps'] ?? 0) ?> successful</div>
            </div>
            <div class="stat-card">
                <h3>Total Volume</h3>
                <div class="value"><?= number_format($swapsSummary['total_amount'] ?? 0, 2) ?></div>
                <div class="trend">Across all currencies</div>
            </div>
            <div class="stat-card">
                <h3>Fees Collected</h3>
                <div class="value"><?= number_format($totalFees, 2) ?></div>
                <div class="trend">+ <?= number_format($totalVat, 2) ?> VAT</div>
            </div>
            <div class="stat-card">
                <h3>Cashout Retries</h3>
                <div class="value"><?= number_format($retryStats['total_retries'] ?? 0) ?></div>
                <div class="trend"><?= number_format($retryStats['free_retries_used'] ?? 0) ?> free retries</div>
            </div>
            <div class="stat-card">
                <h3>FX Volume</h3>
                <div class="value"><?= number_format($totalFxVolume, 2) ?></div>
                <div class="trend">Fees: <?= number_format($totalForexFees, 2) ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Hooks</h3>
                <div class="value"><?= number_format($hookStats['active_hooks'] ?? 0) ?></div>
                <div class="trend"><?= number_format($hookStats['unique_users'] ?? 0) ?> users</div>
            </div>
        </div>
        
        <!-- Monthly Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card" style="border-left-color: #28a745;">
                <div style="font-size: 24px; font-weight: bold;"><?= number_format($swapsSummary['successful_swaps'] ?? 0) ?></div>
                <div style="font-size: 12px; color: #666;">Successful Swaps</div>
            </div>
            <div class="summary-card" style="border-left-color: #dc3545;">
                <div style="font-size: 24px; font-weight: bold;"><?= number_format($swapsSummary['failed_swaps'] ?? 0) ?></div>
                <div style="font-size: 12px; color: #666;">Failed Swaps</div>
            </div>
            <div class="summary-card" style="border-left-color: #ffc107;">
                <div style="font-size: 24px; font-weight: bold;"><?= number_format($swapsSummary['pending_swaps'] ?? 0) ?></div>
                <div style="font-size: 12px; color: #666;">Pending Swaps</div>
            </div>
            <div class="summary-card" style="border-left-color: #6c757d;">
                <div style="font-size: 24px; font-weight: bold;"><?= number_format($swapsSummary['cancelled_swaps'] ?? 0) ?></div>
                <div style="font-size: 12px; color: #666;">Cancelled Swaps</div>
            </div>
        </div>
        
        <!-- Daily Breakdown Section -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('dailyBreakdown')">
                <h2>📆 Daily Breakdown (<?= $monthName ?>)</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="dailyBreakdown">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Transactions</th>
                            <th>Total Amount</th>
                            <th>Status Breakdown</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($reconciliations)): ?>
                            <?php foreach ($reconciliations as $row): ?>
                                <tr>
                                    <td><strong><?= date('D, M j, Y', strtotime($row['date'])) ?></strong></td>
                                    <td><?= number_format($row['total_transactions']) ?></td>
                                    <td><?= number_format($row['total_amount'], 2) ?></td>
                                    <td>
                                        <?php foreach ($row['status'] as $status => $count): ?>
                                            <span class="status-badge status-<?= $status ?>"><?= ucfirst($status) ?>: <?= $count ?></span>
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
        
        <!-- Fee Breakdown Section -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('feeBreakdown')">
                <h2>💰 Fee Breakdown (<?= $monthName ?>)</h2>
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
                                    <td><?= htmlspecialchars($fee['fee_type']) ?></td>
                                    <td><?= number_format($fee['count']) ?></td>
                                    <td><strong><?= number_format($fee['total_amount'], 2) ?></strong></td>
                                    <td><?= number_format($fee['total_vat'], 2) ?></td>
                                    <td><?= htmlspecialchars($fee['currency']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No fee data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Cross-Border Activity Section -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('crossBorder')">
                <h2>🌍 Cross-Border Activity (<?= $monthName ?>)</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="crossBorder">
                <table>
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Transactions</th>
                            <th>Volume (Source)</th>
                            <th>Volume (Dest)</th>
                            <th>Corridor Fees</th>
                            <th>Avg Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($crossBorderActivity)): ?>
                            <?php foreach ($crossBorderActivity as $cb): ?>
                                <tr>
                                    <td><span class="status-badge"><?= htmlspecialchars($cb['source_country']) ?></span></td>
                                    <td><span class="status-badge"><?= htmlspecialchars($cb['destination_country']) ?></span></td>
                                    <td><?= number_format($cb['transaction_count']) ?></td>
                                    <td><?= number_format($cb['total_source_amount'], 2) ?> <?= htmlspecialchars($cb['source_currency']) ?></td>
                                    <td><?= number_format($cb['total_destination_amount'], 2) ?> <?= htmlspecialchars($cb['destination_currency']) ?></td>
                                    <td><?= number_format($cb['total_corridor_fees'] ?? 0, 2) ?></td>
                                    <td><?= number_format($cb['avg_exchange_rate'], 4) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align:center;">No cross-border activity this month</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- FX Activity Section -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('fxActivity')">
                <h2>💱 FX Activity (<?= $monthName ?>)</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="fxActivity">
                <table>
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Transactions</th>
                            <th>Volume</th>
                            <th>Forex Fees</th>
                            <th>Avg Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($fxActivity)): ?>
                            <?php foreach ($fxActivity as $fx): ?>
                                <tr>
                                    <td><?= htmlspecialchars($fx['source_currency']) ?></td>
                                    <td><?= htmlspecialchars($fx['destination_currency']) ?></td>
                                    <td><?= number_format($fx['fx_transactions']) ?></td>
                                    <td><?= number_format($fx['total_fx_volume'], 2) ?></td>
                                    <td><strong><?= number_format($fx['total_forex_fees'], 2) ?></strong></td>
                                    <td><?= number_format($fx['avg_rate'], 4) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center;">No FX activity this month</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Corridor Activity Section -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('corridorActivity')">
                <h2>🔄 Corridor Settlement Activity</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="corridorActivity">
                <table>
                    <thead>
                        <tr>
                            <th>Source Country</th>
                            <th>Destination Country</th>
                            <th>Settlements</th>
                            <th>Total Settled</th>
                            <th>Corridor Fees</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($corridorActivity)): ?>
                            <?php foreach ($corridorActivity as $corridor): ?>
                                <tr>
                                    <td><?= htmlspecialchars($corridor['source_country']) ?></td>
                                    <td><?= htmlspecialchars($corridor['destination_country']) ?></td>
                                    <td><?= number_format($corridor['settlement_count']) ?></td>
                                    <td><?= number_format($corridor['total_settled'], 2) ?></td>
                                    <td><?= number_format($corridor['total_fees'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No corridor activity this month</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Top Institutions Section -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('topInstitutions')">
                <h2>🏦 Top Institutions by Volume (<?= $monthName ?>)</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="topInstitutions">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Institution</th>
                            <th>Transactions</th>
                            <th>Total Volume</th>
                            <th>Total Fees</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($topInstitutions)): ?>
                            <?php $rank = 1; ?>
                            <?php foreach ($topInstitutions as $inst): ?>
                                <tr>
                                    <td><strong>#<?= $rank++ ?></strong></td>
                                    <td><?= htmlspecialchars($inst['institution']) ?></td>
                                    <td><?= number_format($inst['transaction_count']) ?></td>
                                    <td><?= number_format($inst['total_volume'], 2) ?></td>
                                    <td><?= number_format($inst['total_fees'] ?? 0, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No institution data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function applyFilters() {
            const institution = document.getElementById('institution').value;
            const currency = document.getElementById('currency').value;
            const urlParams = new URLSearchParams(window.location.search);
            
            if (institution) urlParams.set('institution', institution);
            else urlParams.delete('institution');
            
            if (currency) urlParams.set('currency', currency);
            else urlParams.delete('currency');
            
            // Preserve year and month
            const year = new URLSearchParams(window.location.search).get('year');
            const month = new URLSearchParams(window.location.search).get('month');
            if (year) urlParams.set('year', year);
            if (month) urlParams.set('month', month);
            
            window.location.href = '?' + urlParams.toString();
        }
        
        function exportCSV() {
            const institution = document.getElementById('institution').value;
            const currency = document.getElementById('currency').value;
            const urlParams = new URLSearchParams(window.location.search);
            
            urlParams.set('export', 'csv');
            if (institution) urlParams.set('institution', institution);
            if (currency) urlParams.set('currency', currency);
            
            window.location.href = '?' + urlParams.toString();
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
