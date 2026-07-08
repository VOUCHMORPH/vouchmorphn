<?php
// index.php
require_once 'auth.php';
$user = requireEnterpriseAuth(); // This handles session verification

// Get database connection
$pdo = getDBConnection();
$orgId = getOrganizationId();

// Get stats
$stats = [];

try {
    // Total batches this month
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total, COALESCE(SUM(total_amount), 0) as amount 
        FROM import_batches 
        WHERE organization_id = :org_id 
        AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute([':org_id' => $orgId]);
    $stats['batches'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'amount' => 0];

    // Pending approvals
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as amount 
        FROM import_batches 
        WHERE organization_id = :org_id AND status = 'READY_FOR_APPROVAL'
    ");
    $stmt->execute([':org_id' => $orgId]);
    $stats['pending_approval'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'amount' => 0];

    // Recent batches
    $stmt = $pdo->prepare("
        SELECT * FROM import_batches 
        WHERE organization_id = :org_id 
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute([':org_id' => $orgId]);
    $recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Beneficiary count - using organizations_beneficiaries table
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM organizations_beneficiaries 
        WHERE organization_id = :org_id AND is_active = true
    ");
    $stmt->execute([':org_id' => $orgId]);
    $beneficiaryCount = $stmt->fetchColumn() ?: 0;

    // Successful payments this month
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as amount 
        FROM import_batches 
        WHERE organization_id = :org_id 
        AND status = 'COMPLETED'
        AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute([':org_id' => $orgId]);
    $successfulPayments = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'amount' => 0];
    
} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats = [
        'batches' => ['total' => 0, 'amount' => 0],
        'pending_approval' => ['count' => 0, 'amount' => 0]
    ];
    $recentBatches = [];
    $beneficiaryCount = 0;
    $successfulPayments = ['count' => 0, 'amount' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }
        .app { display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: #0f172a;
            color: #e2e8f0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 24px;
            border-bottom: 1px solid #1e293b;
        }
        .sidebar-header h2 {
            font-size: 20px;
            font-weight: 700;
        }
        .sidebar-header span { color: #fbbf24; }
        .sidebar-nav { padding: 20px 0; }
        .nav-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.2s;
        }
        .nav-item:hover, .nav-item.active {
            background: #1e293b;
            color: white;
        }
        .nav-item.active { border-left: 3px solid #fbbf24; }
        
        /* Main content */
        .main {
            flex: 1;
            margin-left: 280px;
            padding: 24px 32px;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .greeting h1 { font-size: 28px; font-weight: 700; }
        .greeting p { color: #64748b; margin-top: 4px; }
        .user-menu {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #0f172a;
            font-size: 18px;
        }
        
        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-value { font-size: 32px; font-weight: 700; }
        .stat-label { color: #64748b; font-size: 14px; margin-top: 4px; }
        
        /* Quick actions */
        .quick-actions {
            display: flex;
            gap: 16px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        .action-btn {
            padding: 14px 28px;
            border-radius: 40px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .action-primary {
            background: #0f172a;
            color: white;
        }
        .action-primary:hover {
            background: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15,23,42,0.2);
        }
        .action-secondary {
            background: white;
            color: #0f172a;
            border: 1px solid #e2e8f0;
        }
        .action-secondary:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        
        /* Table */
        .card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 14px 20px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 13px;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-completed, .status-success { background: #dcfce7; color: #166534; }
        .status-processing, .status-pending { background: #fef3c7; color: #92400e; }
        .status-ready_for_approval { background: #e0e7ff; color: #3730a3; }
        .status-failed, .status-error { background: #fee2e2; color: #991b1b; }
        .status-draft { background: #f1f5f9; color: #475569; }
        
        .view-link {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }
        .view-link:hover { text-decoration: underline; }
        
        code {
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #64748b;
        }
        .empty-state a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="app">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>VouchMorph <span>Enterprise</span></h2>
        </div>
        <div class="sidebar-nav">
            <a href="index.php" class="nav-item active">📊 Dashboard</a>
            <a href="imports/upload.php" class="nav-item">📁 New Payment</a>
            <a href="batches/index.php" class="nav-item">📦 Batches</a>
            <a href="beneficiaries/index.php" class="nav-item">👥 Beneficiaries</a>
            <a href="templates/index.php" class="nav-item">📋 Templates</a>
            <a href="reports/index.php" class="nav-item">📄 Reports</a>
            <a href="settings/index.php" class="nav-item">⚙️ Settings</a>
            <hr style="margin: 20px 24px; border-color: #1e293b;">
            <a href="logout.php" class="nav-item">🚪 Logout</a>
        </div>
    </div>
    
    <!-- Main content -->
    <div class="main">
        <div class="top-bar">
            <div class="greeting">
                <h1>Welcome back, <?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?></h1>
                <p><?php echo htmlspecialchars($user['organization_name']); ?> • <?php echo ucfirst($user['role']); ?></p>
            </div>
            <div class="user-menu">
                <div class="avatar"><?php echo strtoupper(substr($user['full_name'] ?? $user['email'], 0, 1)); ?></div>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">BWP <?php echo number_format($stats['batches']['amount'] ?? 0, 2); ?></div>
                <div class="stat-label">Disbursed this month</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($successfulPayments['count'] ?? 0); ?></div>
                <div class="stat-label">Successful payments</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['pending_approval']['count'] ?? 0); ?></div>
                <div class="stat-label">Pending approval</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($beneficiaryCount); ?></div>
                <div class="stat-label">Saved beneficiaries</div>
            </div>
        </div>
        
        <div class="quick-actions">
            <a href="imports/upload.php" class="action-btn action-primary">📁 + New Bulk Payment</a>
            <a href="beneficiaries/import.php" class="action-btn action-secondary">👥 Import Beneficiaries</a>
            <a href="templates/index.php" class="action-btn action-secondary">📋 Manage Templates</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                📦 Recent Batches
                <a href="batches/index.php">View all →</a>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Name</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentBatches)): ?>
                            <?php foreach ($recentBatches as $batch): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($batch['batch_reference'] ?? $batch['id']); ?></code></td>
                                <td><?php echo htmlspecialchars($batch['batch_name'] ?? 'Batch #' . $batch['id']); ?></td>
                                <td>BWP <?php echo number_format($batch['total_amount'] ?? 0, 2); ?></td>
                                <td><span class="status status-<?php echo strtolower(str_replace(' ', '_', $batch['status'] ?? 'draft')); ?>"><?php echo htmlspecialchars($batch['status'] ?? 'Draft'); ?></span></td>
                                <td><?php echo date('M d, H:i', strtotime($batch['created_at'] ?? 'now')); ?></td>
                                <td><a href="batches/view.php?id=<?php echo $batch['id']; ?>" class="view-link">View →</a></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-state">
                                    No batches yet. <a href="imports/upload.php">Create your first batch →</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
