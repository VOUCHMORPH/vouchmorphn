<?php
/**
 * Daily Reconciliation Report
 * Uses ONLY tables and columns that exist in the database
 */

// Simple session check
session_start();
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin_username'])) {
    header('Location: admin_login.php');
    exit();
}

require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

try {
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Date selection
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$prevDate = date('Y-m-d', strtotime("-1 day", strtotime($selectedDate)));
$nextDate = date('Y-m-d', strtotime("+1 day", strtotime($selectedDate)));

// ============================================================
// 1. DAILY SUMMARY FROM swap_requests
// ============================================================
$summaryQuery = "
    SELECT 
        COUNT(*) as total_swaps,
        COALESCE(SUM(amount), 0) as total_volume,
        COALESCE(AVG(amount), 0) as avg_amount,
        COALESCE(MIN(amount), 0) as min_amount,
        COALESCE(MAX(amount), 0) as max_amount,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM swap_requests
    WHERE DATE(created_at) = :date
";
$stmt = $db->prepare($summaryQuery);
$stmt->execute(array(':date' => $selectedDate));
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// 2. HOURLY BREAKDOWN FROM swap_requests
// ============================================================
$hourlyQuery = "
    SELECT 
        EXTRACT(HOUR FROM created_at) as hour,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as volume,
        status
    FROM swap_requests
    WHERE DATE(created_at) = :date
    GROUP BY EXTRACT(HOUR FROM created_at), status
    ORDER BY hour ASC
";
$stmt = $db->prepare($hourlyQuery);
$stmt->execute(array(':date' => $selectedDate));
$hourlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by hour
$hourlyBreakdown = array();
for ($i = 0; $i < 24; $i++) {
    $hourlyBreakdown[$i] = array(
        'hour' => $i,
        'total' => 0,
        'volume' => 0,
        'status' => array()
    );
}
foreach ($hourlyData as $row) {
    $hour = (int)$row['hour'];
    $hourlyBreakdown[$hour]['total'] += $row['count'];
    $hourlyBreakdown[$hour]['volume'] += $row['volume'];
    $hourlyBreakdown[$hour]['status'][$row['status']] = $row['count'];
}

// ============================================================
// 3. FEE SUMMARY FROM swap_fee_collections
// ============================================================
$feeQuery = "
    SELECT 
        fee_type,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as total,
        COALESCE(SUM(vat_amount), 0) as vat,
        currency
    FROM swap_fee_collections
    WHERE DATE(created_at) = :date
    GROUP BY fee_type, currency
    ORDER BY total DESC
";
$stmt = $db->prepare($feeQuery);
$stmt->execute(array(':date' => $selectedDate));
$fees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 4. CROSS-BORDER ACTIVITY FROM cross_border_messages
// ============================================================
$crossBorderQuery = "
    SELECT 
        source_country,
        destination_country,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as volume,
        source_currency,
        destination_currency
    FROM cross_border_messages
    WHERE DATE(created_at) = :date
    GROUP BY source_country, destination_country, source_currency, destination_currency
    ORDER BY count DESC
    LIMIT 10
";
$stmt = $db->prepare($crossBorderQuery);
$stmt->execute(array(':date' => $selectedDate));
$crossBorder = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 5. SETTLEMENT STATUS FROM settlement_queue
// ============================================================
$settlementQuery = "
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total
    FROM settlement_queue
    WHERE DATE(created_at) = :date
    GROUP BY status
";
$stmt = $db->prepare($settlementQuery);
$stmt->execute(array(':date' => $selectedDate));
$settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 6. SETTLEMENT OUTBOX STATUS FROM settlement_outbox
// ============================================================
$outboxQuery = "
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total
    FROM settlement_outbox
    WHERE DATE(created_at) = :date
    GROUP BY status
";
$stmt = $db->prepare($outboxQuery);
$stmt->execute(array(':date' => $selectedDate));
$outboxStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 7. CASHOUT AUTHORIZATIONS FROM cashout_authorizations
// ============================================================
$cashoutQuery = "
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total
    FROM cashout_authorizations
    WHERE DATE(created_at) = :date
    GROUP BY status
";
$stmt = $db->prepare($cashoutQuery);
$stmt->execute(array(':date' => $selectedDate));
$cashouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 8. DEPOSIT TRANSACTIONS FROM deposit_transactions
// ============================================================
$depositQuery = "
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total
    FROM deposit_transactions
    WHERE DATE(created_at) = :date
    GROUP BY status
";
$stmt = $db->prepare($depositQuery);
$stmt->execute(array(':date' => $selectedDate));
$deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 9. ACTIVE HOLDS FROM hold_transactions
// ============================================================
$holdQuery = "
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total
    FROM hold_transactions
    WHERE DATE(created_at) = :date
    GROUP BY status
";
$stmt = $db->prepare($holdQuery);
$stmt->execute(array(':date' => $selectedDate));
$holds = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 10. RETRY STATISTICS FROM cashout_retry_tracking
// ============================================================
$retryQuery = "
    SELECT 
        COUNT(*) as total_retries,
        SUM(CASE WHEN free_retry_used THEN 1 ELSE 0 END) as free_retries,
        SUM(CASE WHEN free_retry_used = FALSE THEN 1 ELSE 0 END) as paid_retries
    FROM cashout_retry_tracking
    WHERE DATE(created_at) = :date
";
$stmt = $db->prepare($retryQuery);
$stmt->execute(array(':date' => $selectedDate));
$retryStats = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// 11. CALCULATE SAFE VALUES FOR DISPLAY
// ============================================================
$totalSwaps = isset($summary['total_swaps']) ? (int)$summary['total_swaps'] : 0;
$totalVolume = isset($summary['total_volume']) ? (float)$summary['total_volume'] : 0;
$avgAmount = isset($summary['avg_amount']) ? (float)$summary['avg_amount'] : 0;
$minAmount = isset($summary['min_amount']) ? (float)$summary['min_amount'] : 0;
$maxAmount = isset($summary['max_amount']) ? (float)$summary['max_amount'] : 0;
$completed = isset($summary['completed']) ? (int)$summary['completed'] : 0;
$failed = isset($summary['failed']) ? (int)$summary['failed'] : 0;
$pending = isset($summary['pending']) ? (int)$summary['pending'] : 0;
$cancelled = isset($summary['cancelled']) ? (int)$summary['cancelled'] : 0;

// Fee totals
$totalFees = 0;
$totalVat = 0;
foreach ($fees as $fee) {
    $totalFees += (float)($fee['total'] ?? 0);
    $totalVat += (float)($fee['vat'] ?? 0);
}

// Retry totals
$totalRetries = isset($retryStats['total_retries']) ? (int)$retryStats['total_retries'] : 0;
$freeRetries = isset($retryStats['free_retries']) ? (int)$retryStats['free_retries'] : 0;
$paidRetries = isset($retryStats['paid_retries']) ? (int)$retryStats['paid_retries'] : 0;

// Check if date is today
$isToday = ($selectedDate === date('Y-m-d'));

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daily_reconciliation_' . $selectedDate . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array('VOUCHMORPH DAILY RECONCILIATION REPORT'));
    fputcsv($output, array('Date', date('F j, Y', strtotime($selectedDate))));
    fputcsv($output, array('Generated At', date('Y-m-d H:i:s')));
    fputcsv($output, array());
    
    fputcsv($output, array('SUMMARY'));
    fputcsv($output, array('Total Swaps', $totalSwaps));
    fputcsv($output, array('Total Volume', number_format($totalVolume, 2)));
    fputcsv($output, array('Average', number_format($avgAmount, 2)));
    fputcsv($output, array('Min', number_format($minAmount, 2)));
    fputcsv($output, array('Max', number_format($maxAmount, 2)));
    fputcsv($output, array('Completed', $completed));
    fputcsv($output, array('Failed', $failed));
    fputcsv($output, array('Pending', $pending));
    fputcsv($output, array('Cancelled', $cancelled));
    fputcsv($output, array('Total Fees', number_format($totalFees, 2)));
    fputcsv($output, array('Total VAT', number_format($totalVat, 2)));
    fputcsv($output, array('Total Retries', $totalRetries));
    fputcsv($output, array('Free Retries', $freeRetries));
    fputcsv($output, array('Paid Retries', $paidRetries));
    
    fclose($output);
    exit;
}

$formattedDate = date('F j, Y', strtotime($selectedDate));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Reconciliation - VouchMorph</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #f7f9fc;
            color: #001B44;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .admin-header {
            background: #001B44;
            border-bottom: 5px solid #FFDA63;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .logo {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 2px;
        }

        .logo span {
            color: #FFDA63;
            margin-left: 10px;
            font-size: 0.8rem;
        }

        .country-badge {
            padding: 5px 15px;
            background: rgba(255, 218, 99, 0.2);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: #FFDA63;
        }

        .user-role {
            font-size: 0.7rem;
            color: #A1B5D8;
            text-transform: uppercase;
        }

        .logout-btn {
            padding: 8px 16px;
            background: transparent;
            border: 2px solid #FFDA63;
            color: #FFDA63;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .logout-btn:hover {
            background: #FFDA63;
            color: #001B44;
        }

        .admin-nav {
            background: #fff;
            border-bottom: 2px solid #001B44;
            padding: 0 30px;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            align-items: center;
        }

        .nav-item {
            padding: 15px 0;
            color: #666;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }

        .nav-item:hover {
            color: #001B44;
        }

        .nav-item.active {
            color: #001B44;
            border-bottom-color: #FFDA63;
        }

        .dashboard-link {
            padding: 8px 16px;
            background: #FFDA63;
            color: #001B44;
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .dashboard-link:hover {
            background: #f5c842;
            transform: translateY(-1px);
        }

        .admin-content {
            flex: 1;
            padding: 30px;
        }

        .content-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .content-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #001B44;
        }

        .content-header .timestamp {
            color: #666;
            font-size: 0.8rem;
        }

        .report-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-success {
            background: #28a745;
            color: white;
            border: 2px solid #28a745;
        }

        .btn-success:hover {
            background: #1e7e34;
            border-color: #1e7e34;
        }

        .btn-outline {
            background: transparent;
            color: #001B44;
            border: 2px solid #001B44;
        }

        .btn-outline:hover {
            background: #001B44;
            color: #fff;
        }

        .btn-today {
            background: #FFDA63;
            color: #001B44;
            border: 2px solid #FFDA63;
        }

        .btn-today:hover {
            background: #f5c842;
            border-color: #f5c842;
        }

        .date-nav {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .date-nav .nav-btn {
            padding: 8px 16px;
            background: #001B44;
            color: #fff;
            border: 2px solid #001B44;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
            font-family: 'IBM Plex Mono', monospace;
        }

        .date-nav .nav-btn:hover {
            background: #FFDA63;
            color: #001B44;
            border-color: #FFDA63;
        }

        .date-badge {
            padding: 8px 20px;
            background: #FFDA63;
            color: #001B44;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .today-indicator {
            padding: 4px 12px;
            background: #28a745;
            color: #fff;
            font-size: 0.7rem;
            font-weight: 600;
            border-radius: 4px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            box-shadow: 4px 4px 0 #A1B5D8;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card h3 {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 600;
            color: #001B44;
            line-height: 1.2;
            word-break: break-word;
        }

        .stat-card .sub {
            font-size: 0.7rem;
            color: #666;
            margin-top: 8px;
        }

        .section {
            background: #fff;
            border: 2px solid #001B44;
            margin-bottom: 25px;
            overflow: hidden;
        }

        .section-header {
            background: #001B44;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-header h2 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #FFDA63;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .section-header .badge {
            padding: 4px 12px;
            background: rgba(255, 218, 99, 0.2);
            color: #FFDA63;
            font-size: 0.7rem;
            font-weight: 600;
            border: 1px solid #FFDA63;
        }

        .section-content {
            padding: 20px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th {
            background: #f8f9fa;
            padding: 12px;
            font-weight: 600;
            color: #001B44;
            text-align: left;
            border-bottom: 2px solid #001B44;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .badge-status {
            display: inline-block;
            padding: 3px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
        }

        .badge-info {
            background: #cce5ff;
            color: #004085;
            border-color: #b8daff;
        }

        .badge-secondary {
            background: #e2e3e5;
            color: #383d41;
            border-color: #d6d8db;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .hour-row {
            background: #f8f9fa;
        }

        .hour-row td {
            font-weight: 600;
        }

        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 20px 30px;
            font-size: 0.7rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
            margin-top: 30px;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .admin-nav {
                padding: 0 15px;
                gap: 15px;
            }
            .admin-content {
                padding: 20px;
            }
            .admin-header {
                padding: 15px;
            }
            .header-left {
                gap: 15px;
            }
            .content-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .stat-card .value {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="country-badge">BW · BOTSWANA</div>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator'; ?></div>
                <div class="user-role"><?php echo $_SESSION['admin_role'] ?? 'Admin'; ?></div>
            </div>
            <a href="admin_logout.php" class="logout-btn">LOGOUT</a>
        </div>
    </header>

    <nav class="admin-nav">
        <a href="admin_dashboard.php" class="nav-item">DASHBOARD</a>
        <a href="?view=reports" class="nav-item active">DAILY REPORT</a>
        <a href="monthly_reconciliation.php" class="nav-item">MONTHLY REPORT</a>
        <a href="#" class="nav-item">TRANSACTIONS</a>
        <a href="#" class="nav-item">AUDIT</a>
        <?php if (isset($_SESSION['admin_role_id']) && $_SESSION['admin_role_id'] == 999): ?>
            <a href="#" class="nav-item">CONFIGURATION</a>
        <?php endif; ?>
        <a href="admin_dashboard.php" class="dashboard-link">← Back to Dashboard</a>
    </nav>

    <main class="admin-content">
        <div class="content-header">
            <div>
                <h1>📊 Daily Reconciliation Report</h1>
                <div class="timestamp">
                    <?php echo $formattedDate; ?> 
                    <?php if ($isToday): ?>
                        <span class="today-indicator">TODAY</span>
                    <?php endif; ?>
                    · Generated: <?php echo date('Y-m-d H:i:s'); ?>
                </div>
            </div>
            <div class="report-actions">
                <div class="date-nav">
                    <a href="?date=<?php echo $prevDate; ?>" class="nav-btn">←</a>
                    <div class="date-badge"><?php echo date('M j, Y', strtotime($selectedDate)); ?></div>
                    <a href="?date=<?php echo $nextDate; ?>" class="nav-btn">→</a>
                    <a href="?date=<?php echo date('Y-m-d'); ?>" class="btn btn-today">TODAY</a>
                </div>
                <a href="?<?php echo http_build_query(array_merge($_GET, array('export' => 'csv'))); ?>" class="btn btn-success">📥 CSV</a>
                <a href="admin_dashboard.php" class="btn btn-outline">⬅ BACK</a>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Swaps</h3>
                <div class="value"><?php echo number_format($totalSwaps); ?></div>
                <div class="sub"><?php echo number_format($completed); ?> completed</div>
            </div>
            <div class="stat-card">
                <h3>Total Volume</h3>
                <div class="value"><?php echo number_format($totalVolume, 2); ?></div>
                <div class="sub">Avg: <?php echo number_format($avgAmount, 2); ?></div>
            </div>
            <div class="stat-card">
                <h3>Fees Collected</h3>
                <div class="value"><?php echo number_format($totalFees, 2); ?></div>
                <div class="sub">VAT: <?php echo number_format($totalVat, 2); ?></div>
            </div>
            <div class="stat-card">
                <h3>Status</h3>
                <div class="value" style="font-size: 1.1rem;">
                    <span class="badge-status badge-success">C: <?php echo number_format($completed); ?></span>
                    <span class="badge-status badge-danger">F: <?php echo number_format($failed); ?></span>
                    <span class="badge-status badge-warning">P: <?php echo number_format($pending); ?></span>
                </div>
                <div class="sub">Cancelled: <?php echo number_format($cancelled); ?></div>
            </div>
        </div>

        <!-- Range Summary -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Min Amount</h3>
                <div class="value"><?php echo number_format($minAmount, 2); ?></div>
                <div class="sub">Smallest transaction</div>
            </div>
            <div class="stat-card">
                <h3>Max Amount</h3>
                <div class="value"><?php echo number_format($maxAmount, 2); ?></div>
                <div class="sub">Largest transaction</div>
            </div>
            <div class="stat-card">
                <h3>Total Retries</h3>
                <div class="value"><?php echo number_format($totalRetries); ?></div>
                <div class="sub">Free: <?php echo number_format($freeRetries); ?> · Paid: <?php echo number_format($paidRetries); ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Holds</h3>
                <div class="value"><?php 
                    $activeHolds = 0;
                    foreach ($holds as $h) {
                        if ($h['status'] === 'ACTIVE' || $h['status'] === 'HELD') {
                            $activeHolds += $h['count'];
                        }
                    }
                    echo number_format($activeHolds);
                ?></div>
                <div class="sub">Pending settlement</div>
            </div>
        </div>

        <!-- Hourly Breakdown -->
        <div class="section">
            <div class="section-header">
                <h2>⏰ Hourly Breakdown</h2>
                <span class="badge">24 hours</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Hour</th>
                            <th class="text-right">Transactions</th>
                            <th class="text-right">Volume</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $hasData = false;
                        for ($i = 0; $i < 24; $i++): 
                            $hourData = $hourlyBreakdown[$i];
                            if ($hourData['total'] > 0) $hasData = true;
                        ?>
                            <tr class="<?php echo $hourData['total'] > 0 ? '' : ''; ?>">
                                <td>
                                    <strong><?php echo sprintf('%02d:00', $i); ?></strong>
                                    <?php if ($hourData['total'] > 0): ?>
                                        <span class="badge-status badge-info"><?php echo $hourData['total']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right"><?php echo number_format($hourData['total']); ?></td>
                                <td class="text-right"><?php echo number_format($hourData['volume'], 2); ?></td>
                                <td>
                                    <?php foreach ($hourData['status'] as $status => $count): ?>
                                        <span class="badge-status badge-info"><?php echo ucfirst($status); ?>: <?php echo $count; ?></span>
                                    <?php endforeach; ?>
                                    <?php if (empty($hourData['status'])): ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endfor; ?>
                        <?php if (!$hasData): ?>
                            <tr><td colspan="4" style="text-align:center;">No data available for this day</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Fee Breakdown -->
        <div class="section">
            <div class="section-header">
                <h2>💰 Fee Breakdown</h2>
                <span class="badge"><?php echo count($fees); ?> types</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Fee Type</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">VAT</th>
                            <th>Currency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($fees)): ?>
                            <?php foreach ($fees as $fee): ?>
                                <tr>
                                    <td><strong><?php echo isset($fee['fee_type']) ? htmlspecialchars($fee['fee_type']) : 'N/A'; ?></strong></td>
                                    <td class="text-right"><?php echo isset($fee['count']) ? number_format($fee['count']) : 0; ?></td>
                                    <td class="text-right"><?php echo isset($fee['total']) ? number_format((float)$fee['total'], 2) : '0.00'; ?></td>
                                    <td class="text-right"><?php echo isset($fee['vat']) ? number_format((float)$fee['vat'], 2) : '0.00'; ?></td>
                                    <td><?php echo isset($fee['currency']) ? htmlspecialchars($fee['currency']) : 'BWP'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No fee data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Cross Border Activity -->
        <div class="section">
            <div class="section-header">
                <h2>🌍 Cross Border Activity</h2>
                <span class="badge">From cross_border_messages</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th class="text-right">Transactions</th>
                            <th class="text-right">Volume</th>
                            <th>Currency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($crossBorder)): ?>
                            <?php foreach ($crossBorder as $cb): ?>
                                <tr>
                                    <td><span class="badge-status badge-info"><?php echo isset($cb['source_country']) ? htmlspecialchars($cb['source_country']) : 'N/A'; ?></span></td>
                                    <td><span class="badge-status badge-info"><?php echo isset($cb['destination_country']) ? htmlspecialchars($cb['destination_country']) : 'N/A'; ?></span></td>
                                    <td class="text-right"><?php echo isset($cb['count']) ? number_format($cb['count']) : 0; ?></td>
                                    <td class="text-right"><?php echo isset($cb['volume']) ? number_format((float)$cb['volume'], 2) : '0.00'; ?></td>
                                    <td><?php echo isset($cb['source_currency']) ? htmlspecialchars($cb['source_currency']) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No cross border activity</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Settlement Status -->
        <div class="section">
            <div class="section-header">
                <h2>📤 Settlement Status</h2>
                <span class="badge">From settlement_queue</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($settlements)): ?>
                            <?php foreach ($settlements as $s): ?>
                                <tr>
                                    <td><span class="badge-status badge-info"><?php echo isset($s['status']) ? htmlspecialchars($s['status']) : 'N/A'; ?></span></td>
                                    <td class="text-right"><?php echo isset($s['count']) ? number_format($s['count']) : 0; ?></td>
                                    <td class="text-right"><?php echo isset($s['total']) ? number_format((float)$s['total'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align:center;">No settlement data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Settlement Outbox -->
        <div class="section">
            <div class="section-header">
                <h2>📤 Settlement Outbox</h2>
                <span class="badge">From settlement_outbox</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($outboxStatus)): ?>
                            <?php foreach ($outboxStatus as $o): ?>
                                <tr>
                                    <td><span class="badge-status badge-info"><?php echo isset($o['status']) ? htmlspecialchars($o['status']) : 'N/A'; ?></span></td>
                                    <td class="text-right"><?php echo isset($o['count']) ? number_format($o['count']) : 0; ?></td>
                                    <td class="text-right"><?php echo isset($o['total']) ? number_format((float)$o['total'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align:center;">No outbox data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Hold Transactions -->
        <div class="section">
            <div class="section-header">
                <h2>🔒 Hold Transactions</h2>
                <span class="badge">From hold_transactions</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($holds)): ?>
                            <?php foreach ($holds as $h): ?>
                                <tr>
                                    <td><span class="badge-status badge-info"><?php echo isset($h['status']) ? htmlspecialchars($h['status']) : 'N/A'; ?></span></td>
                                    <td class="text-right"><?php echo isset($h['count']) ? number_format($h['count']) : 0; ?></td>
                                    <td class="text-right"><?php echo isset($h['total']) ? number_format((float)$h['total'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align:center;">No hold data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Cashout Authorizations -->
        <div class="section">
            <div class="section-header">
                <h2>🏧 Cashout Authorizations</h2>
                <span class="badge">From cashout_authorizations</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($cashouts)): ?>
                            <?php foreach ($cashouts as $c): ?>
                                <tr>
                                    <td><span class="badge-status badge-info"><?php echo isset($c['status']) ? htmlspecialchars($c['status']) : 'N/A'; ?></span></td>
                                    <td class="text-right"><?php echo isset($c['count']) ? number_format($c['count']) : 0; ?></td>
                                    <td class="text-right"><?php echo isset($c['total']) ? number_format((float)$c['total'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align:center;">No cashout data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Deposit Transactions -->
        <div class="section">
            <div class="section-header">
                <h2>💰 Deposit Transactions</h2>
                <span class="badge">From deposit_transactions</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($deposits)): ?>
                            <?php foreach ($deposits as $d): ?>
                                <tr>
                                    <td><span class="badge-status badge-info"><?php echo isset($d['status']) ? htmlspecialchars($d['status']) : 'N/A'; ?></span></td>
                                    <td class="text-right"><?php echo isset($d['count']) ? number_format($d['count']) : 0; ?></td>
                                    <td class="text-right"><?php echo isset($d['total']) ? number_format((float)$d['total'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align:center;">No deposit data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · BOTSWANA · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant · Daily Reconciliation Report</p>
    </footer>
</body>
</html>
