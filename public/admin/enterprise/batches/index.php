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

// FIX: Updated to use currency parameter. NOTE: this page puts the
// currency code BEFORE the amount ("BWP 500.00") while a couple of the
// other enterprise pages put it after ("500.00 BWP") — a pre-existing
// small inconsistency, left as-is since fixing display order isn't part
// of this reskin.
function formatCurrency($amount, $currency = 'BWP') {
    return $currency . ' ' . number_format((float)$amount, 2);
}

function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function getRoleLabel($role) {
    $labels = [
        'owner' => 'Owner', 'it_manager_enterprise' => 'IT Manager', 'it_officer_enterprise' => 'IT Officer',
        'it_support' => 'IT Support', 'department_head' => 'Department Head', 'program_officer' => 'Uploader',
        'finance_officer' => 'Finance Officer', 'approver' => 'Approver', 'senior_approver' => 'Senior Approver',
        'supervisor' => 'Supervisor', 'beneficiary_registrar' => 'Beneficiary Registrar', 'auditor' => 'Auditor', 'viewer' => 'Viewer',
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// ============================================================
// SHARED SHELL SETUP — same contract as index.php/departments/index.php,
// so this page's nav is generated by the exact same code, not a
// hand-copied lookalike.
// ============================================================
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization';
$basePath = '../';
$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$isDepartmentHead = ($userRole === 'department_head');
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head'], true);
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise'], true);
$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true);
$canSeeSourceAccountsArea = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer'], true);
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer'], true);
$canManageDepartments = $isTopRole;
$setupReady = true; // this page requires an authenticated, already-onboarded user to reach at all

$navPendingApprovals = 0;
$navPendingSourceConfirmations = 0;
try {
    if ($canApprove) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('pending','pending_approval','PENDING','PENDING_APPROVAL')");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingApprovals = (int)$stmt->fetchColumn();
    }
    if ($canSeeSourceAccountsArea) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM source_accounts WHERE organization_id = :org_id AND status = 'pending_confirmation' AND deleted_at IS NULL");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingSourceConfirmations = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("[batches/index] Nav badge query error: " . $e->getMessage());
}

$navItems = [
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => '../index.php', 'show' => true],
    ['key' => 'disbursements', 'icon' => 'wallet', 'label' => 'Disbursements', 'href' => 'index.php?status=all', 'show' => true, 'active' => true, 'badge' => ($navPendingApprovals > 0 && $canApprove) ? $navPendingApprovals : null],
    ['key' => 'beneficiaries', 'icon' => 'people', 'label' => 'Beneficiaries', 'href' => '../beneficiaries.php', 'show' => true],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace Payment', 'href' => '../index.php#trace', 'show' => $canTrace],
    ['key' => 'departments', 'icon' => 'building', 'label' => 'Departments', 'href' => '../departments/index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => '../imports/add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => $navPendingSourceConfirmations > 0 ? $navPendingSourceConfirmations : null],
    ['key' => 'team', 'icon' => 'idcard', 'label' => 'Team', 'href' => '../settings/users.php', 'show' => $canManageUsers],
    ['key' => 'reports', 'icon' => 'chart', 'label' => 'Reports', 'href' => '../reports.php', 'show' => true],
];
$navUtility = [
    ['key' => 'settings', 'icon' => 'gear', 'label' => 'Settings', 'href' => '../settings.php', 'show' => true],
    ['key' => 'logout', 'icon' => 'logout', 'label' => 'Log Out', 'href' => '../logout.php', 'show' => true],
];
$topbarSearchShow = true;
$topbarSearchAction = 'index.php';
$topbarSearchName = 'search';
$topbarSearchPlaceholder = 'Search reference, name, source institution…';
$topbarSearchValue = $search;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disbursements · VOUCHMORPH Enterprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../partials/shell.css">
    <style>
        .filter-tab { display:inline-flex; align-items:center; gap:6px; padding: 6px 16px; background: var(--panel); border: 1px solid var(--line); color: var(--ink-500); text-decoration: none; font-size: 12px; font-weight: 600; font-family: var(--f-cond); text-transform:uppercase; letter-spacing:.03em; }
        .filter-tab:hover { border-color: var(--brass); color: var(--ink-900); }
        .filter-tab.active { background: var(--ink-900); color: #fff; border-color: var(--ink-900); }
        .filter-tab .count { background: rgba(255,255,255,0.2); padding: 0 7px; font-size: 10px; font-family:var(--f-mono); }
        .filter-tab:not(.active) .count { background: var(--brass-tint); color: var(--brass); }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../partials/shell-head.php'; ?>
            <div class="page-header">
                <div>
                    <h1>Disbursements</h1>
                    <div class="sub">All batches you have visibility into, filterable by status.</div>
                </div>
                <?php if ($canCreate && $setupReady): ?>
                <div class="page-header-actions">
                    <a href="../imports/source_input.php" class="btn btn-primary"><?php echo svgIcon('plus'); ?> Create Batch</a>
                </div>
                <?php endif; ?>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:16px;">
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
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
                <?php if ($search): ?>
                <span style="font-size:12px; color:var(--ink-500);">Search: "<?php echo safeHtml($search); ?>" — <a href="?status=<?php echo safeHtml($statusFilter); ?>" style="color:var(--brass); font-weight:600;">Clear</a></span>
                <?php endif; ?>
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
                                <td><strong><?php echo formatCurrency($batch['total_amount'] ?? 0, $batch['currency'] ?? 'BWP'); ?></strong></td>
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

<?php
$dbHealthy = DBConnection::isConnected();
$footerStatusLine = 'LEDGER SYNC: ' . ($dbHealthy ? '<span class="ok">OK</span>' : '<span class="bad">DEGRADED</span>');
require __DIR__ . '/../partials/shell-foot.php';
?>
</body>
</html>
