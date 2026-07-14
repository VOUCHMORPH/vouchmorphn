<?php
/**
 * Monthly Reconciliation Report
 * Aggregates daily data for monthly view
 */

// Simple session check
session_start();
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin_username'])) {
    header('Location: ../admin_login.php');
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

// Month selection
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$firstDay = date('Y-m-01', strtotime($year . '-' . $month . '-01'));
$lastDay = date('Y-m-t', strtotime($year . '-' . $month . '-01'));

// ============================================================
// MONTHLY SUMMARY
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
    WHERE DATE(created_at) BETWEEN :start AND :end
";
$stmt = $db->prepare($summaryQuery);
$stmt->execute(array(':start' => $firstDay, ':end' => $lastDay));
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// DAILY BREAKDOWN
// ============================================================
$dailyQuery = "
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as volume,
        status
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :start AND :end
    GROUP BY DATE(created_at), status
    ORDER BY date DESC
";
$stmt = $db->prepare($dailyQuery);
$stmt->execute(array(':start' => $firstDay, ':end' => $lastDay));
$dailyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date
$dailyBreakdown = array();
foreach ($dailyData as $row) {
    $date = $row['date'];
    if (!isset($dailyBreakdown[$date])) {
        $dailyBreakdown[$date] = array(
            'date' => $date,
            'total' => 0,
            'volume' => 0,
            'status' => array()
        );
    }
    $dailyBreakdown[$date]['total'] += $row['count'];
    $dailyBreakdown[$date]['volume'] += $row['volume'];
    $dailyBreakdown[$date]['status'][$row['status']] = $row['count'];
}

// ============================================================
// INSTITUTION SUMMARY
// ============================================================
$instQuery = "
    SELECT 
        source_details->>'institution' as institution,
        COUNT(*) as tx_count,
        COALESCE(SUM(amount), 0) as total_volume
    FROM swap_requests
    WHERE DATE(created_at) BETWEEN :start AND :end
    AND source_details IS NOT NULL
    AND source_details->>'institution' IS NOT NULL
    GROUP BY source_details->>'institution'
    ORDER BY total_volume DESC
    LIMIT 20
";
$stmt = $db->prepare($instQuery);
$stmt->execute(array(':start' => $firstDay, ':end' => $lastDay));
$institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// FEE SUMMARY
// ============================================================
$feeQuery = "
    SELECT 
        fee_type,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as total,
        COALESCE(SUM(vat_amount), 0) as vat
    FROM swap_fee_collections
    WHERE DATE(created_at) BETWEEN :start AND :end
    GROUP BY fee_type
";
$stmt = $db->prepare($feeQuery);
$stmt->execute(array(':start' => $firstDay, ':end' => $lastDay));
$fees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// SETTLEMENT SUMMARY
// ============================================================
$settlementQuery = "
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total
    FROM settlement_outbox
    WHERE DATE(created_at) BETWEEN :start AND :end
    GROUP BY status
";
$stmt = $db->prepare($settlementQuery);
$stmt->execute(array(':start' => $firstDay, ':end' => $lastDay));
$settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CORRIDOR SUMMARY
// ============================================================
$corridorQuery = "
    SELECT 
        source_country,
        destination_country,
        COUNT(*) as count,
        COALESCE(SUM(source_amount), 0) as volume
    FROM corridor_settlement_ledger
    WHERE DATE(created_at) BETWEEN :start AND :end
    GROUP BY source_country, destination_country
";
$stmt = $db->prepare($corridorQuery);
$stmt->execute(array(':start' => $firstDay, ':end' => $lastDay));
$corridors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CALCULATE SAFE VALUES FOR DISPLAY
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

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="monthly_reconciliation_' . $year . '_' . $month . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array('VOUCHMORPH MONTHLY RECONCILIATION REPORT'));
    fputcsv($output, array('Month', date('F Y', strtotime($year . '-' . $month . '-01'))));
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
    
    fclose($output);
    exit;
}

$monthName = date('F Y', strtotime($year . '-' . $month . '-01'));
$prevMonth = date('Y-m', strtotime("-1 month", strtotime($year . '-' . $month . '-01')));
$nextMonth = date('Y-m', strtotime("+1 month", strtotime($year . '-' . $month . '-01')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Reconciliation - VouchMorph</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; color: #333; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: #1a1a2e; color: white; padding: 20px 30px; border-radius: 12px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .header h1 { font-size: 24px; }
        .header p { opacity: 0.8; font-size: 14px; }
        .month-nav { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .month-nav .nav-btn { background: rgba(255,255,255,0.2); padding: 8px 20px; border-radius: 8px; text-decoration: none; color: white; }
        .month-nav .nav-btn:hover { background: rgba(255,255,255,0.3); }
        .month-badge { background: rgba(255,255,255,0.2); padding: 8px 20px; border-radius: 20px; font-size: 16px; font-weight: 600; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 10px; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #1a1a2e; }
        .stat-card .sub { font-size: 12px; color: #999; margin-top: 5px; }
        .section { background: white; border-radius: 12px; margin-bottom: 25px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e9ecef; }
        .section-header h2 { font-size: 18px; font-weight: 600; }
        .section-content { padding: 20px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; }
        th { background: #f8f9fa; font-weight: 600; color: #495057; }
        tr:hover { background: #f8f9fa; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        .text-right { text-align: right; }
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #1e7e34; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        .flex { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>📆 Monthly Reconciliation Report</h1>
                <p><?php echo $monthName; ?> · Botswana</p>
            </div>
            <div class="month-nav">
                <a href="?year=<?php echo explode('-', $prevMonth)[0]; ?>&month=<?php echo explode('-', $prevMonth)[1]; ?>" class="nav-btn">←</a>
                <div class="month-badge"><?php echo $monthName; ?></div>
                <a href="?year=<?php echo explode('-', $nextMonth)[0]; ?>&month=<?php echo explode('-', $nextMonth)[1]; ?>" class="nav-btn">→</a>
                <a href="?year=<?php echo date('Y'); ?>&month=<?php echo date('m'); ?>" class="nav-btn">Today</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, array('export' => 'csv'))); ?>" class="btn btn-success">📥 CSV</a>
            </div>
        </div>
        
        <!-- Summary -->
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
                <h3>Range</h3>
                <div class="value" style="font-size: 20px;"><?php echo number_format($minAmount, 2); ?> - <?php echo number_format($maxAmount, 2); ?></div>
                <div class="sub">Min / Max</div>
            </div>
            <div class="stat-card">
                <h3>Status</h3>
                <div class="value" style="font-size: 18px;">
                    <span class="badge badge-success">C: <?php echo number_format($completed); ?></span>
                    <span class="badge badge-danger">F: <?php echo number_format($failed); ?></span>
                    <span class="badge badge-warning">P: <?php echo number_format($pending); ?></span>
                    <span class="badge badge-secondary">X: <?php echo number_format($cancelled); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Daily Breakdown -->
        <div class="section">
            <div class="section-header">
                <h2>📊 Daily Breakdown</h2>
                <span class="badge badge-info"><?php echo count($dailyBreakdown); ?> days</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-right">Transactions</th>
                            <th class="text-right">Volume</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($dailyBreakdown)): ?>
                            <?php foreach ($dailyBreakdown as $row): ?>
                                <tr>
                                    <td><strong><?php echo date('D, M j', strtotime($row['date'])); ?></strong></td>
                                    <td class="text-right"><?php echo number_format($row['total']); ?></td>
                                    <td class="text-right"><?php echo number_format($row['volume'], 2); ?></td>
                                    <td>
                                        <?php foreach ($row['status'] as $status => $count): ?>
                                            <span class="badge badge-info"><?php echo ucfirst($status); ?>: <?php echo $count; ?></span>
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
        
        <!-- Top Institutions -->
        <div class="section">
            <div class="section-header">
                <h2>🏦 Top Institutions</h2>
                <span class="badge badge-info">By volume</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Institution</th>
                            <th class="text-right">Transactions</th>
                            <th class="text-right">Volume</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($institutions)): ?>
                            <?php $rank = 1; ?>
                            <?php foreach ($institutions as $inst): ?>
                                <tr>
                                    <td><strong>#<?php echo $rank++; ?></strong></td>
                                    <td><?php echo isset($inst['institution']) ? htmlspecialchars($inst['institution']) : 'Unknown'; ?></td>
                                    <td class="text-right"><?php echo isset($inst['tx_count']) ? number_format($inst['tx_count']) : 0; ?></td>
                                    <td class="text-right"><?php echo isset($inst['total_volume']) ? number_format((float)$inst['total_volume'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center;">No data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Fees -->
        <div class="section">
            <div class="section-header">
                <h2>💰 Fee Summary</h2>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Fee Type</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">VAT</th>
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
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center;">No fee data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Settlements -->
        <div class="section">
            <div class="section-header">
                <h2>📤 Settlement Status</h2>
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
                                    <td><span class="badge badge-info"><?php echo isset($s['status']) ? htmlspecialchars($s['status']) : 'N/A'; ?></span></td>
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
        
        <!-- Corridors -->
        <div class="section">
            <div class="section-header">
                <h2>🌍 Corridor Activity</h2>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th class="text-right">Transactions</th>
                            <th class="text-right">Volume</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($corridors)): ?>
                            <?php foreach ($corridors as $c): ?>
                                <tr>
                                    <td><span class="badge badge-info"><?php echo isset($c['source_country']) ? htmlspecialchars($c['source_country']) : 'N/A'; ?></span></td>
                                    <td><span class="badge badge-info"><?php echo isset($c['destination_country']) ? htmlspecialchars($c['destination_country']) : 'N/A'; ?></span></td>
                                    <td class="text-right"><?php echo isset($c['count']) ? number_format($c['count']) : 0; ?></td>
                                    <td class="text-right"><?php echo isset($c['volume']) ? number_format((float)$c['volume'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center;">No corridor activity</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div style="text-align:center; padding: 20px; color: #999; font-size: 12px; border-top: 1px solid #ddd;">
            VouchMorph Monthly Reconciliation Report · <?php echo date('Y-m-d H:i:s'); ?> · Botswana
        </div>
    </div>
</body>
</html>
