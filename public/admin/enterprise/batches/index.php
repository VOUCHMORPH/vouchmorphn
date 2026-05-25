<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getInstance();
$orgId = getOrganizationId();

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM import_batches WHERE organization_id = :org_id";
$params = [':org_id' => $orgId];

if ($status !== 'all') {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

if ($search) {
    $sql .= " AND (batch_reference ILIKE :search OR batch_name ILIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status counts for filters
$stmt = $db->prepare("
    SELECT status, COUNT(*) as count FROM import_batches 
    WHERE organization_id = :org_id GROUP BY status
");
$stmt->execute([':org_id' => $orgId]);
$statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batches - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }
        .app { display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px;
            background: #0f172a;
            color: #e2e8f0;
            position: fixed;
            height: 100vh;
        }
        .sidebar-header { padding: 24px; border-bottom: 1px solid #1e293b; }
        .sidebar-header h2 { font-size: 20px; font-weight: 700; }
        .sidebar-header span { color: #fbbf24; }
        .sidebar-nav { padding: 20px 0; }
        .nav-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e1;
            text-decoration: none;
        }
        .nav-item:hover, .nav-item.active { background: #1e293b; color: white; }
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
            flex-wrap: wrap;
            gap: 16px;
        }
        .greeting h1 { font-size: 28px; font-weight: 700; }
        .filter-bar {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-btn {
            padding: 8px 20px;
            border-radius: 40px;
            background: white;
            border: 1px solid #e2e8f0;
            text-decoration: none;
            color: #0f172a;
            font-size: 14px;
        }
        .filter-btn.active {
            background: #0f172a;
            color: white;
            border-color: #0f172a;
        }
        .search-input {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            width: 250px;
        }
        .card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 20px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; font-size: 13px; }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-COMPLETED { background: #dcfce7; color: #166534; }
        .status-PROCESSING { background: #fef3c7; color: #92400e; }
        .status-READY_FOR_APPROVAL { background: #e0e7ff; color: #3730a3; }
        .status-FAILED { background: #fee2e2; color: #991b1b; }
        .status-PARTIAL { background: #fed7aa; color: #9a3412; }
        .btn-sm {
            padding: 6px 12px;
            border-radius: 20px;
            background: #f1f5f9;
            text-decoration: none;
            color: #0f172a;
            font-size: 12px;
        }
    </style>
</head>
<body>
<div class="app">
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>VouchMorph <span>Enterprise</span></h2>
        </div>
        <div class="sidebar-nav">
            <a href="../index.php" class="nav-item">📊 Dashboard</a>
            <a href="../imports/upload.php" class="nav-item">📁 New Payment</a>
            <a href="index.php" class="nav-item active">📦 Batches</a>
            <a href="../beneficiaries/index.php" class="nav-item">👥 Beneficiaries</a>
            <a href="../templates/index.php" class="nav-item">📋 Templates</a>
            <a href="../reports/index.php" class="nav-item">📄 Reports</a>
            <a href="../settings/index.php" class="nav-item">⚙️ Settings</a>
        </div>
    </div>
    
    <div class="main">
        <div class="top-bar">
            <div class="greeting">
                <h1>Payment Batches</h1>
            </div>
            <a href="../imports/upload.php" class="btn-sm" style="background: #0f172a; color: white; padding: 10px 20px;">+ New Batch</a>
        </div>
        
        <div class="filter-bar">
            <a href="?status=all" class="filter-btn <?php echo $status === 'all' ? 'active' : ''; ?>">All (<?php echo array_sum($statusCounts); ?>)</a>
            <a href="?status=READY_FOR_APPROVAL" class="filter-btn <?php echo $status === 'READY_FOR_APPROVAL' ? 'active' : ''; ?>">Pending Approval (<?php echo $statusCounts['READY_FOR_APPROVAL'] ?? 0; ?>)</a>
            <a href="?status=COMPLETED" class="filter-btn <?php echo $status === 'COMPLETED' ? 'active' : ''; ?>">Completed (<?php echo $statusCounts['COMPLETED'] ?? 0; ?>)</a>
            <a href="?status=PROCESSING" class="filter-btn <?php echo $status === 'PROCESSING' ? 'active' : ''; ?>">Processing (<?php echo $statusCounts['PROCESSING'] ?? 0; ?>)</a>
            <a href="?status=FAILED" class="filter-btn <?php echo $status === 'FAILED' ? 'active' : ''; ?>">Failed (<?php echo $statusCounts['FAILED'] ?? 0; ?>)</a>
            <form method="GET" style="margin-left: auto;">
                <input type="text" name="search" class="search-input" placeholder="Search batches..." value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" name="status" value="<?php echo $status; ?>">
            </form>
        </div>
        
        <div class="card">
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Reference</th><th>Batch Name</th><th>Date</th><th>Amount</th><th>Recipients</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batches)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 60px;">No batches found. <a href="../imports/upload.php">Create your first batch →</a></td></tr>
                        <?php endif; ?>
                        <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($batch['batch_reference']); ?></code></td>
                            <td><?php echo htmlspecialchars($batch['batch_name']); ?></td>
                            <td><?php echo date('M d, Y H:i', strtotime($batch['created_at'])); ?></td>
                            <td>P<?php echo number_format($batch['total_amount'], 2); ?></td>
                            <td><?php echo $batch['total_rows']; ?></td>
                            <td><span class="status status-<?php echo $batch['status']; ?>"><?php echo $batch['status']; ?></span></td>
                            <td><a href="view.php?id=<?php echo $batch['id']; ?>" class="btn-sm">View →</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
