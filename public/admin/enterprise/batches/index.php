<?php
/**
 * batches/index.php - List all disbursement batches
 * Enterprise batch management dashboard
 */
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$departmentId = $user['department_id'] ?? null;

// Filters
$statusFilter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query
$params = [':org_id' => $orgId];
$where = ["organization_id = :org_id"];

// Department scope - keep original behavior
if (in_array($userRole, ['department_head', 'program_officer'])) {
    $where[] = "department_id = :dept_id";
    $params[':dept_id'] = $departmentId;
}

// Status filter - keep original behavior
if ($statusFilter !== 'all') {
    $where[] = "LOWER(status) = LOWER(:status)";
    $params[':status'] = $statusFilter;
}

// Search
if ($search) {
    $where[] = "(batch_reference ILIKE :search OR batch_name ILIKE :search OR source_institution ILIKE :search)";
    $params[':search'] = "%$search%";
}

// Role-based visibility
if ($userRole === 'owner' || $userRole === 'it_manager_enterprise') {
    // Owners and IT Managers see ALL batches
} elseif (in_array($userRole, ['auditor', 'viewer'])) {
    $where[] = "status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')";
} elseif (in_array($userRole, ['approver', 'senior_approver'])) {
    $where[] = "status IN ('pending', 'pending_approval', 'approved', 'draft', 'PENDING', 'PENDING_APPROVAL', 'APPROVED')";
} elseif ($userRole === 'supervisor') {
    $where[] = "status IN ('approved', 'completed', 'executed', 'APPROVED', 'COMPLETED', 'EXECUTED')";
} elseif (in_array($userRole, ['program_officer', 'department_head'])) {
    $where[] = "(created_by = :user_id OR status IN ('pending', 'pending_approval', 'approved', 'draft', 'PENDING', 'PENDING_APPROVAL', 'APPROVED'))";
    $params[':user_id'] = $userId;
}

$whereClause = implode(" AND ", $where);

// FIX: Added currency to SELECT
$stmt = $db->prepare("
    SELECT 
        id, batch_reference, batch_name, source_institution,
        total_amount, currency, total_destinations, status, created_at,
        updated_at, created_by,
        approved_at, executed_at
    FROM disbursement_batches
    WHERE $whereClause
    ORDER BY 
        CASE 
            WHEN status IN ('pending', 'pending_approval', 'PENDING', 'PENDING_APPROVAL') THEN 1
            WHEN status = 'approved' THEN 2
            WHEN status = 'draft' THEN 3
            ELSE 4
        END,
        created_at DESC
");
$stmt->execute($params);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for status badges
$counts = [];
$stmt = $db->prepare("SELECT status, COUNT(*) as count FROM disbursement_batches WHERE organization_id = :org_id GROUP BY status");
$stmt->execute([':org_id' => $orgId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $counts[strtolower($row['status'])] = $row['count'];
}

$roleDisplay = strtoupper($userRole);
$orgName = htmlspecialchars($user['organization_name'] ?? 'ORGANIZATIONAL');

function getStatusClass($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => 'draft',
        'pending', 'pending_approval' => 'pending',
        'approved' => 'approved',
        'completed', 'executed' => 'completed',
        'rejected' => 'rejected',
        default => 'draft'
    };
}

function getStatusLabel($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => '📝 Draft',
        'pending', 'pending_approval' => '⏳ Pending',
        'approved' => '✅ Approved',
        'completed' => '✔️ Completed',
        'executed' => '🚀 Executed',
        'rejected' => '❌ Rejected',
        default => ucfirst($status)
    };
}

// FIX: Updated to use currency parameter
function formatCurrency($amount, $currency = 'BWP') {
    return $currency . ' ' . number_format((float)$amount, 2);
}

function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batches · VOUCHMORPH Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            min-height: 100vh;
        }
        .header {
            background: #0f172a;
            color: #fff;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 3px solid #8A6D3B;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .logo {
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .logo span { color: #8A6D3B; }
        .role-badge {
            padding: 4px 14px;
            background: #8A6D3B;
            color: #0f172a;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-radius: 20px;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .user-name {
            font-weight: 600;
            color: #8A6D3B;
            font-size: 13px;
        }
        .user-role {
            font-size: 10px;
            color: #94a3b8;
            text-transform: uppercase;
        }
        .logout-btn {
            padding: 6px 16px;
            border: 2px solid #8A6D3B;
            color: #8A6D3B;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 20px;
            transition: all 0.15s;
        }
        .logout-btn:hover {
            background: #8A6D3B;
            color: #0f172a;
        }
        .nav {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 32px;
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: 12px 0;
            color: #64748b;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid transparent;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .nav-item:hover { color: #0f172a; }
        .nav-item.active {
            color: #0f172a;
            border-bottom-color: #8A6D3B;
        }
        .nav-item .badge {
            background: #ef4444;
            color: #fff;
            font-size: 9px;
            padding: 1px 8px;
            border-radius: 12px;
            margin-left: 4px;
        }
        .content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px 32px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .page-header h1 { font-size: 24px; font-weight: 700; }
        .filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-tab {
            padding: 6px 16px;
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #64748b;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            transition: all 0.15s;
        }
        .filter-tab:hover { border-color: #8A6D3B; color: #0f172a; }
        .filter-tab.active {
            background: #0f172a;
            color: #fff;
            border-color: #0f172a;
        }
        .filter-tab .count {
            background: rgba(255,255,255,0.2);
            padding: 0 6px;
            border-radius: 10px;
            font-size: 9px;
        }
        .filter-tab.active .count { background: rgba(255,255,255,0.2); }
        .search-box {
            display: flex;
            gap: 8px;
        }
        .search-box input {
            padding: 8px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            font-size: 13px;
            min-width: 200px;
        }
        .search-box input:focus {
            outline: none;
            border-color: #8A6D3B;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 16px;
        }
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: #f8fafc;
            color: #64748b;
            padding: 10px 14px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }
        td {
            padding: 10px 14px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        tr:hover { background: #f8fafc; }
        .status {
            display: inline-block;
            padding: 2px 10px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 20px;
            letter-spacing: 0.04em;
        }
        .status-draft { background: #f1f5f9; color: #64748b; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .btn {
            padding: 6px 16px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #0f172a; color: #fff; }
        .btn-primary:hover { background: #8A6D3B; }
        .btn-outline {
            background: transparent;
            border: 1px solid #e2e8f0;
            color: #64748b;
        }
        .btn-outline:hover {
            border-color: #0f172a;
            color: #0f172a;
        }
        .btn-sm { padding: 4px 12px; font-size: 11px; }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        .empty-state .icon { font-size: 40px; margin-bottom: 8px; }
        .footer {
            background: #0f172a;
            color: #94a3b8;
            padding: 16px 32px;
            text-align: center;
            font-size: 11px;
            border-top: 2px solid #8A6D3B;
            margin-top: 24px;
        }
        @media (max-width: 768px) {
            .header { padding: 12px 16px; }
            .nav { padding: 0 16px; gap: 16px; }
            .content { padding: 16px; }
            .filters { flex-direction: column; align-items: stretch; }
            .search-box input { min-width: auto; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>·</span> <?php echo safeHtml($orgName); ?></div>
            <span class="role-badge"><?php echo safeHtml($userRole); ?></span>
        </div>
        <div class="user-info">
            <div>
                <div class="user-name"><?php echo safeHtml($user['full_name'] ?? 'User'); ?></div>
                <div class="user-role"><?php echo safeHtml($userRole); ?></div>
            </div>
            <a href="../logout.php" class="logout-btn">Sign Out</a>
        </div>
    </header>

    <nav class="nav">
        <a href="../index.php" class="nav-item">📊 Dashboard</a>
        <a href="index.php" class="nav-item active">📋 Batches</a>
        <?php if (in_array($userRole, ['owner', 'program_officer', 'department_head'])): ?>
        <a href="../imports/source_input.php" class="nav-item primary">💰 New Batch</a>
        <?php endif; ?>
    </nav>

    <main class="content">
        <div class="page-header">
            <h1>📋 Disbursement Batches</h1>
            <div class="filters">
                <div class="search-box">
                    <form method="GET" style="display:flex; gap:8px;">
                        <input type="text" name="search" placeholder="Search batches..." value="<?php echo safeHtml($search); ?>">
                        <button type="submit" class="btn btn-outline btn-sm">Search</button>
                        <?php if ($search): ?>
                        <a href="index.php" class="btn btn-outline btn-sm">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
            <a href="?status=all" class="filter-tab <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?status=pending_approval" class="filter-tab <?php echo $statusFilter === 'pending_approval' ? 'active' : ''; ?>">
                ⏳ Pending <?php if (($counts['pending_approval'] ?? 0) > 0): ?><span class="count"><?php echo $counts['pending_approval']; ?></span><?php endif; ?>
            </a>
            <a href="?status=approved" class="filter-tab <?php echo $statusFilter === 'approved' ? 'active' : ''; ?>">
                ✅ Approved <?php if (($counts['approved'] ?? 0) > 0): ?><span class="count"><?php echo $counts['approved']; ?></span><?php endif; ?>
            </a>
            <a href="?status=completed" class="filter-tab <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>">
                ✔️ Completed <?php if (($counts['completed'] ?? 0) > 0): ?><span class="count"><?php echo $counts['completed']; ?></span><?php endif; ?>
            </a>
            <a href="?status=draft" class="filter-tab <?php echo $statusFilter === 'draft' ? 'active' : ''; ?>">
                📝 Draft <?php if (($counts['draft'] ?? 0) > 0): ?><span class="count"><?php echo $counts['draft']; ?></span><?php endif; ?>
            </a>
        </div>

        <div class="card">
            <?php if (empty($batches)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No batches found.</p>
                <?php if (in_array($userRole, ['owner', 'program_officer', 'department_head'])): ?>
                <a href="../imports/source_input.php" class="btn btn-primary" style="margin-top:12px;">Create First Batch</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Name</th>
                            <th>Source</th>
                            <th>Amount</th>
                            <th>Destinations</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><strong><?php echo safeHtml($batch['batch_reference']); ?></strong></td>
                            <td><?php echo safeHtml($batch['batch_name'] ?? '—'); ?></td>
                            <td><?php echo safeHtml($batch['source_institution'] ?? '—'); ?></td>
                            <td>
                                <strong><?php echo formatCurrency($batch['total_amount'] ?? 0, $batch['currency'] ?? 'BWP'); ?></strong>
                            </td>
                            <td><?php echo number_format($batch['total_destinations'] ?? 0); ?></td>
                            <td>
                                <span class="status status-<?php echo getStatusClass($batch['status']); ?>">
                                    <?php echo getStatusLabel($batch['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($batch['created_at'] ?? 'now')); ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $batch['id']; ?>" class="btn btn-outline btn-sm">View</a>
                                <?php if ($batch['created_by'] == $userId && strtolower($batch['status']) === 'draft'): ?>
                                <a href="../imports/add_destinations.php?batch_id=<?php echo $batch['id']; ?>" class="btn btn-primary btn-sm">✏️ Edit</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <div>VOUCHMORPH · Enterprise Disbursement Platform · <?php echo date('Y'); ?></div>
    </footer>
</body>
</html>
