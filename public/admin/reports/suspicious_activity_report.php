<?php
/**
 * Suspicious Activity Report
 * AML/KYC Compliance Monitoring - Country Agnostic
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

// Get country from config or use default
$country = 'Botswana'; // Default
$configPath = __DIR__ . '/../../../src/Core/Config/SystemCountry.php';
if (file_exists($configPath)) {
    try {
        $country = require $configPath;
        if (!is_string($country) || empty($country)) {
            $country = 'Botswana';
        }
    } catch (Exception $e) {
        $country = 'Botswana';
    }
}

// ============================================================
// 1. GET SUSPICIOUS TRANSACTIONS
// ============================================================
$suspiciousQuery = "
    SELECT 
        s.swap_id,
        s.user_id,
        s.amount,
        s.currency,
        s.status,
        s.created_at,
        s.source_institution,
        s.destination_institution,
        s.asset_type,
        s.swap_type,
        u.full_name as user_name,
        u.email as user_email,
        u.phone as user_phone,
        u.kyc_status,
        u.status as user_status
    FROM swap_requests s
    LEFT JOIN users u ON s.user_id = u.user_id
    WHERE s.status IN ('pending', 'failed', 'cancelled')
       OR s.amount > 100000
       OR s.currency != 'BWP'
    ORDER BY s.created_at DESC
    LIMIT 100
";
$stmt = $db->prepare($suspiciousQuery);
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 2. GET SETTLEMENT ISSUES
// ============================================================
$settlementQuery = "
    SELECT 
        sq.id,
        sq.debtor,
        sq.creditor,
        sq.amount,
        sq.currency,
        sq.status,
        sq.created_at
    FROM settlement_queue sq
    WHERE sq.status = 'PENDING'
       OR sq.status = 'FAILED'
    ORDER BY sq.created_at DESC
    LIMIT 50
";
$stmt = $db->prepare($settlementQuery);
$stmt->execute();
$settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 3. GET HOLDS THAT ARE STALE
// ============================================================
$holdQuery = "
    SELECT 
        ht.hold_id,
        ht.hold_reference,
        ht.swap_reference,
        ht.participant_name,
        ht.amount,
        ht.currency,
        ht.status,
        ht.placed_at,
        ht.created_at
    FROM hold_transactions ht
    WHERE ht.status IN ('ACTIVE', 'HELD', 'PENDING_CASHOUT')
      AND ht.placed_at < NOW() - INTERVAL '1 hour'
    ORDER BY ht.placed_at ASC
    LIMIT 50
";
$stmt = $db->prepare($holdQuery);
$stmt->execute();
$holds = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 4. GET FAILED CASHOUTS
// ============================================================
$cashoutQuery = "
    SELECT 
        ca.auth_id,
        ca.swap_reference,
        ca.client_phone,
        ca.amount,
        ca.currency,
        ca.status,
        ca.created_at,
        ca.cashout_point,
        ca.cashout_provider
    FROM cashout_authorizations ca
    WHERE ca.status IN ('FAILED', 'EXPIRED')
    ORDER BY ca.created_at DESC
    LIMIT 50
";
$stmt = $db->prepare($cashoutQuery);
$stmt->execute();
$failedCashouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 5. CALCULATE SUMMARY
// ============================================================
$totalSuspicious = count($transactions);
$pendingSwaps = 0;
$failedSwaps = 0;
$highValue = 0;
$crossBorder = 0;

foreach ($transactions as $tx) {
    if ($tx['status'] === 'pending') $pendingSwaps++;
    if ($tx['status'] === 'failed') $failedSwaps++;
    if ((float)$tx['amount'] > 100000) $highValue++;
    if ($tx['currency'] !== 'BWP') $crossBorder++;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="suspicious_activity_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array(
        'Swap ID',
        'User ID',
        'User Name',
        'Amount',
        'Currency',
        'Status',
        'Source Institution',
        'Destination Institution',
        'Asset Type',
        'Swap Type',
        'KYC Status',
        'Created At'
    ));
    
    foreach ($transactions as $row) {
        fputcsv($output, array(
            $row['swap_id'] ?? '',
            $row['user_id'] ?? '',
            $row['user_name'] ?? '',
            $row['amount'] ?? '',
            $row['currency'] ?? '',
            $row['status'] ?? '',
            $row['source_institution'] ?? '',
            $row['destination_institution'] ?? '',
            $row['asset_type'] ?? '',
            $row['swap_type'] ?? '',
            $row['kyc_status'] ?? '',
            $row['created_at'] ?? ''
        ));
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
    <title>Suspicious Activity Report - VouchMorph</title>
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

        .btn-refresh {
            background: #17a2b8;
            color: white;
            border: 2px solid #17a2b8;
        }

        .btn-refresh:hover {
            background: #138496;
            border-color: #138496;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .stat-card .value.danger { color: #dc3545; }
        .stat-card .value.warning { color: #ffc107; }
        .stat-card .value.success { color: #28a745; }
        .stat-card .sub { font-size: 0.7rem; color: #666; margin-top: 8px; }

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

        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 20px 30px;
            font-size: 0.7rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
            margin-top: 30px;
        }

        .footer-info {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 0.8rem;
            color: #6c757d;
            text-align: center;
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
            <div class="country-badge"><?php echo strtoupper($country); ?></div>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Administrator'; ?></div>
                <div class="user-role"><?php echo $_SESSION['admin_role'] ?? 'Admin'; ?></div>
            </div>
            <a href="../admin_logout.php" class="logout-btn">LOGOUT</a>
        </div>
    </header>

    <nav class="admin-nav">
        <a href="../admin_dashboard.php" class="nav-item">DASHBOARD</a>
        <a href="daily_reconciliations.php" class="nav-item">DAILY REPORT</a>
        <a href="monthly_reconciliations.php" class="nav-item">MONTHLY REPORT</a>
        <a href="../admin_dashboard.php?view=audit" class="nav-item">AUDIT</a>
        <a href="suspicious_activity_report.php" class="nav-item active">🚨 SUSPICIOUS</a>
        <?php if (isset($_SESSION['admin_role_id']) && $_SESSION['admin_role_id'] == 999): ?>
            <a href="../admin_management.php" class="nav-item">ADMIN</a>
        <?php endif; ?>
        <a href="../admin_dashboard.php" class="dashboard-link">← Back to Dashboard</a>
    </nav>

    <main class="admin-content">
        <div class="content-header">
            <div>
                <h1>🚨 Suspicious Activity Report</h1>
                <div class="timestamp">AML/KYC Compliance Monitoring · Generated: <?php echo date('Y-m-d H:i:s'); ?></div>
            </div>
            <div class="report-actions">
                <button onclick="location.reload()" class="btn btn-refresh">🔄 Refresh</button>
                <a href="?export=csv" class="btn btn-success">📥 CSV</a>
                <a href="../admin_dashboard.php" class="btn btn-outline">⬅ BACK</a>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Suspicious</h3>
                <div class="value <?php echo $totalSuspicious > 10 ? 'danger' : ($totalSuspicious > 5 ? 'warning' : 'success'); ?>">
                    <?php echo number_format($totalSuspicious); ?>
                </div>
                <div class="sub">Flagged transactions</div>
            </div>
            <div class="stat-card">
                <h3>Pending Swaps</h3>
                <div class="value warning"><?php echo number_format($pendingSwaps); ?></div>
                <div class="sub">Need review</div>
            </div>
            <div class="stat-card">
                <h3>Failed Swaps</h3>
                <div class="value danger"><?php echo number_format($failedSwaps); ?></div>
                <div class="sub">Failed transactions</div>
            </div>
            <div class="stat-card">
                <h3>High Value</h3>
                <div class="value danger"><?php echo number_format($highValue); ?></div>
                <div class="sub">Over 100,000</div>
            </div>
            <div class="stat-card">
                <h3>Cross Border</h3>
                <div class="value warning"><?php echo number_format($crossBorder); ?></div>
                <div class="sub">Foreign currency</div>
            </div>
            <div class="stat-card">
                <h3>Stale Holds</h3>
                <div class="value <?php echo count($holds) > 10 ? 'danger' : 'warning'; ?>">
                    <?php echo number_format(count($holds)); ?>
                </div>
                <div class="sub">Active > 1 hour</div>
            </div>
        </div>

        <!-- Suspicious Transactions -->
        <div class="section">
            <div class="section-header">
                <h2>🚨 Suspicious Transactions</h2>
                <span class="badge"><?php echo count($transactions); ?> flagged</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Swap ID</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Type</th>
                            <th>KYC</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($tx['swap_id'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($tx['user_name'] ?? $tx['user_id'] ?? 'N/A'); ?></td>
                                    <td><strong><?php echo number_format((float)($tx['amount'] ?? 0), 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($tx['currency'] ?? 'BWP'); ?></td>
                                    <td>
                                        <?php 
                                        $status = $tx['status'] ?? 'unknown';
                                        $badgeClass = match($status) {
                                            'pending' => 'badge-warning',
                                            'failed' => 'badge-danger',
                                            'completed' => 'badge-success',
                                            default => 'badge-info'
                                        };
                                        ?>
                                        <span class="badge-status <?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars(strtoupper($status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($tx['source_institution'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($tx['destination_institution'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($tx['swap_type'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $kyc = $tx['kyc_status'] ?? 'pending';
                                        $kycBadge = $kyc === 'verified' ? 'badge-success' : 'badge-warning';
                                        ?>
                                        <span class="badge-status <?php echo $kycBadge; ?>">
                                            <?php echo htmlspecialchars(strtoupper($kyc)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($tx['created_at'] ?? 'now')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center" style="padding: 40px;">
                                    <div style="font-size: 48px;">✅</div>
                                    <p style="margin-top: 10px; font-size: 16px;">No suspicious activity detected</p>
                                    <p style="color: #6c757d; font-size: 13px;">All transactions appear to be within normal parameters</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Settlement Issues -->
        <div class="section">
            <div class="section-header">
                <h2>📤 Settlement Issues</h2>
                <span class="badge"><?php echo count($settlements); ?> pending/failed</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Debtor</th>
                            <th>Creditor</th>
                            <th class="text-right">Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($settlements)): ?>
                            <?php foreach ($settlements as $s): ?>
                                <tr>
                                    <td><?php echo $s['id']; ?></td>
                                    <td><?php echo htmlspecialchars($s['debtor'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($s['creditor'] ?? 'N/A'); ?></td>
                                    <td class="text-right"><?php echo number_format((float)($s['amount'] ?? 0), 2); ?></td>
                                    <td><?php echo htmlspecialchars($s['currency'] ?? 'BWP'); ?></td>
                                    <td>
                                        <span class="badge-status <?php echo ($s['status'] ?? '') === 'PENDING' ? 'badge-warning' : 'badge-danger'; ?>">
                                            <?php echo htmlspecialchars($s['status'] ?? 'UNKNOWN'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($s['created_at'] ?? 'now')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center" style="padding: 20px; color: #6c757d;">No settlement issues</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Stale Holds -->
        <div class="section">
            <div class="section-header">
                <h2>🔒 Stale Holds</h2>
                <span class="badge"><?php echo count($holds); ?> active > 1hr</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Hold ID</th>
                            <th>Reference</th>
                            <th>Participant</th>
                            <th class="text-right">Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Placed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($holds)): ?>
                            <?php foreach ($holds as $h): ?>
                                <tr>
                                    <td><?php echo $h['hold_id']; ?></td>
                                    <td><?php echo htmlspecialchars($h['hold_reference'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($h['participant_name'] ?? 'N/A'); ?></td>
                                    <td class="text-right"><?php echo number_format((float)($h['amount'] ?? 0), 2); ?></td>
                                    <td><?php echo htmlspecialchars($h['currency'] ?? 'BWP'); ?></td>
                                    <td>
                                        <span class="badge-status badge-warning">
                                            <?php echo htmlspecialchars($h['status'] ?? 'UNKNOWN'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($h['placed_at'] ?? 'now')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center" style="padding: 20px; color: #6c757d;">No stale holds</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Failed Cashouts -->
        <div class="section">
            <div class="section-header">
                <h2>🏧 Failed Cashouts</h2>
                <span class="badge"><?php echo count($failedCashouts); ?> failed/expired</span>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Auth ID</th>
                            <th>Reference</th>
                            <th>Phone</th>
                            <th class="text-right">Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Provider</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($failedCashouts)): ?>
                            <?php foreach ($failedCashouts as $c): ?>
                                <tr>
                                    <td><?php echo $c['auth_id']; ?></td>
                                    <td><?php echo htmlspecialchars($c['swap_reference'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($c['client_phone'] ?? 'N/A'); ?></td>
                                    <td class="text-right"><?php echo number_format((float)($c['amount'] ?? 0), 2); ?></td>
                                    <td><?php echo htmlspecialchars($c['currency'] ?? 'BWP'); ?></td>
                                    <td>
                                        <span class="badge-status <?php echo ($c['status'] ?? '') === 'FAILED' ? 'badge-danger' : 'badge-secondary'; ?>">
                                            <?php echo htmlspecialchars($c['status'] ?? 'UNKNOWN'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['cashout_provider'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($c['created_at'] ?? 'now')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center" style="padding: 20px; color: #6c757d;">No failed cashouts</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="footer-info">
            <p>Report generated: <?= date('Y-m-d H:i:s') ?> | 
            Total flagged: <?= count($transactions) ?> | 
            System: VouchMorph Compliance Monitoring</p>
        </div>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · <?php echo strtoupper($country); ?> · <?php echo date('Y'); ?></p>
        <p style="margin-top: 5px;">Regulatory Sandbox Participant · Suspicious Activity Report</p>
    </footer>
</body>
</html>
