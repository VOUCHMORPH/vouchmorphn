<?php
// batches.php - LIST ALL BATCHES
require_once __DIR__ . '/auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();

// Get filter parameters
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$limit = $_GET['limit'] ?? 50;
$page = $_GET['page'] ?? 1;
$offset = ($page - 1) * $limit;

// Build query
$whereConditions = ["b.organization_id = :org_id"];
$params = [':org_id' => $orgId];

if ($status) {
    $whereConditions[] = "b.status = :status";
    $params[':status'] = $status;
}

if ($search) {
    $whereConditions[] = "(b.batch_reference ILIKE :search OR b.batch_name ILIKE :search)";
    $params[':search'] = "%$search%";
}

$whereClause = implode(" AND ", $whereConditions);

// Get total count
$countStmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM import_batches b
    WHERE $whereClause
");
$countStmt->execute($params);
$totalBatches = $countStmt->fetchColumn();

// Get batches
$stmt = $db->prepare("
    SELECT 
        b.*,
        u.full_name as uploaded_by_name,
        d.name as department_name,
        p.name as program_name,
        (SELECT COUNT(*) FROM import_rows WHERE batch_id = b.id) as row_count,
        (SELECT COUNT(*) FROM import_rows WHERE batch_id = b.id AND validation_status = 'VALID') as valid_count,
        (SELECT COUNT(*) FROM import_rows WHERE batch_id = b.id AND validation_status = 'INVALID') as invalid_count,
        (SELECT COALESCE(SUM(amount), 0) FROM import_rows WHERE batch_id = b.id) as total_amount
    FROM import_batches b
    LEFT JOIN users u ON b.uploaded_by = u.user_id
    LEFT JOIN departments d ON b.department_id = d.id
    LEFT JOIN programs p ON b.program_id = p.id
    WHERE $whereClause
    ORDER BY b.created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = ceil($totalBatches / $limit);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Batches - VouchMorph Enterprise</title>
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
            overflow-y: auto;
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
            transition: all 0.2s;
        }
        .nav-item:hover { background: #1e293b; color: white; }
        .nav-item.active { background: #1e293b; color: white; border-right: 3px solid #fbbf24; }
        .main {
            flex: 1;
            margin-left: 280px;
            padding: 24px 32px;
            max-width: calc(100% - 280px);
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .greeting h1 { font-size: 24px; font-weight: 700; }
        .greeting .subtitle { font-size: 14px; color: #64748b; margin-top: 4px; }
        
        .card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .card-body { padding: 24px; }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-secondary { background: #e2e8f0; color: #475569; }
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8fafc; }
        
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            font-size: 13px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #1e293b; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        
        .filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .filters input, .filters select {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
        }
        
        .pagination {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-top: 16px;
        }
        .pagination a {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: #0f172a;
        }
        .pagination a.active {
            background: #0f172a;
            color: white;
        }
        .pagination a:hover { background: #e2e8f0; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .stat-value { font-size: 20px; font-weight: 700; }
        .stat-label { font-size: 12px; color: #64748b; }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 16px; max-width: 100%; }
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
            <a href="manual.php" class="nav-item">📝 Manual Entry</a>
            <a href="batches.php" class="nav-item active">📦 All Batches</a>
            <a href="../beneficiaries/index.php" class="nav-item">👥 Beneficiaries</a>
            <a href="../templates/index.php" class="nav-item">📋 Templates</a>
            <a href="../reports/index.php" class="nav-item">📄 Reports</a>
            <a href="../settings/index.php" class="nav-item">⚙️ Settings</a>
        </div>
    </div>
    
    <div class="main">
        <div class="top-bar">
            <div class="greeting">
                <h1>📦 All Batches</h1>
                <div class="subtitle"><?php echo $totalBatches; ?> batches found</div>
            </div>
            <a href="manual.php" class="btn btn-success">+ New Batch</a>
        </div>
        
        <!-- Filters -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="filters">
                    <input type="text" name="search" placeholder="Search batches..." value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="UPLOADED" <?php echo $status === 'UPLOADED' ? 'selected' : ''; ?>>Uploaded</option>
                        <option value="READY_FOR_APPROVAL" <?php echo $status === 'READY_FOR_APPROVAL' ? 'selected' : ''; ?>>Ready for Approval</option>
                        <option value="VALIDATED_WITH_ERRORS" <?php echo $status === 'VALIDATED_WITH_ERRORS' ? 'selected' : ''; ?>>Has Errors</option>
                        <option value="APPROVED" <?php echo $status === 'APPROVED' ? 'selected' : ''; ?>>Approved</option>
                        <option value="EXECUTED" <?php echo $status === 'EXECUTED' ? 'selected' : ''; ?>>Executed</option>
                        <option value="REJECTED" <?php echo $status === 'REJECTED' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    <select name="limit">
                        <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="batches.php" class="btn btn-secondary">Clear</a>
                </form>
            </div>
        </div>
        
        <!-- Batches Table -->
        <div class="card">
            <div class="card-header">
                <span>📋 Batch List</span>
                <span style="font-size: 12px; color: #64748b;">Showing <?php echo count($batches); ?> of <?php echo $totalBatches; ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($batches)): ?>
                    <div style="text-align: center; padding: 40px; color: #64748b;">
                        <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
                        <p>No batches found</p>
                        <p style="font-size: 13px; margin-top: 8px;">Create your first batch using the Manual Entry page.</p>
                        <a href="manual.php" class="btn btn-success" style="margin-top: 16px;">+ Create Batch</a>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Rows</th>
                                    <th>Valid</th>
                                    <th>Invalid</th>
                                    <th>Total Amount</th>
                                    <th>Department</th>
                                    <th>Created</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batches as $batch): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($batch['batch_reference']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($batch['batch_name']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo match($batch['status']) {
                                                'READY_FOR_APPROVAL' => 'success',
                                                'VALIDATED_WITH_ERRORS' => 'warning',
                                                'APPROVED' => 'info',
                                                'EXECUTED' => 'info',
                                                'UPLOADED' => 'secondary',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo str_replace('_', ' ', $batch['status'] ?? 'Unknown'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $batch['row_count'] ?? 0; ?></td>
                                    <td style="color: #10b981;"><?php echo $batch['valid_count'] ?? 0; ?></td>
                                    <td style="color: #ef4444;"><?php echo $batch['invalid_count'] ?? 0; ?></td>
                                    <td><strong>P<?php echo number_format($batch['total_amount'] ?? 0, 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($batch['department_name'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars(vm_local_time($batch['created_at'], 'd M Y'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <a href="review.php?batch_id=<?php echo $batch['id']; ?>" class="btn btn-primary" style="font-size: 11px; padding: 4px 12px;">
                                            View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&status=<?php echo $status; ?>&search=<?php echo $search; ?>&limit=<?php echo $limit; ?>" 
                               class="<?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
