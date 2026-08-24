<?php
/**
 * settings.php - Organization Settings
 *
 * ADAPTED, not rebuilt: the four settings categories and their gating
 * are unchanged. What changed: role logic now comes from the shared
 * partials/permissions.php instead of a hand-rolled $canManageUsers
 * check, and the presentation layer uses the current shell via the
 * same compatibility-bridge pattern already used on the Departments
 * page (old class/token names aliased locally, not hunted-and-replaced
 * throughout the HTML).
 */

require_once __DIR__ . '/auth.php';
use Core\Database\DBConnection;

$user = requireEnterpriseAuth();
$pdo = getDBConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization';
$basePath = '';

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

// FIX: was a hardcoded in_array() check duplicated from settings/users.php
// (itself duplicated from index.php originally) — same two-sources-of-
// truth risk already found and fixed on every other page. One shared file.
require __DIR__ . '/partials/permissions.php';

$navPendingApprovals = 0;
$navPendingSourceConfirmations = 0;
try {
    if ($canApprove) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('pending','pending_approval','PENDING','PENDING_APPROVAL')");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingApprovals = (int)$stmt->fetchColumn();
    }
    if ($canSeeSourceAccountsArea) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM source_accounts WHERE organization_id = :org_id AND status = 'pending_confirmation' AND deleted_at IS NULL");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingSourceConfirmations = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("[settings] Nav badge query error: " . $e->getMessage());
}

$navItems = [
    ['key' => 'hub', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => 'index.php', 'show' => true],
    ['key' => 'attention', 'icon' => 'bell', 'label' => 'Attention', 'href' => 'index.php#stage-attention', 'show' => $canViewAttentionTile, 'badge' => ($navPendingApprovals > 0 && $canApprove) ? $navPendingApprovals : null],
    ['key' => 'batches', 'icon' => 'layers', 'label' => 'Batches', 'href' => 'index.php#stage-batches', 'show' => $canViewBatchesTile],
    ['key' => 'activity', 'icon' => 'history', 'label' => 'Activity', 'href' => 'index.php#stage-activity', 'show' => $canViewActivityTile],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace', 'href' => 'index.php#stage-trace', 'show' => $canTrace],
    ['key' => 'reports', 'icon' => 'file', 'label' => 'Reports', 'href' => 'index.php#stage-reports', 'show' => $canViewReports],
    ['key' => 'departments', 'icon' => 'sitemap', 'label' => 'Departments', 'href' => 'departments/index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'beneficiaries', 'icon' => 'users', 'label' => 'Beneficiaries', 'href' => 'beneficiaries.php', 'show' => $canViewBeneficiariesTile],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => 'imports/add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => $navPendingSourceConfirmations > 0 ? $navPendingSourceConfirmations : null],
    ['key' => 'team', 'icon' => 'shield', 'label' => 'Team', 'href' => 'settings/users.php', 'show' => $canManageUsers],
];
$currentNavKey = null; // Settings is reached via the header avatar, not a sidebar row
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Settings · <?php echo safeHtml($orgName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="partials/shell.css">
    <style>
        /* Compatibility bridge — same pattern as departments/index.php. */
        :root { --ink-900: var(--ink); --ink-500: var(--ink); --ink-300: var(--ink); --brass: var(--sky); --brass-tint: var(--sky-tint); --amber: var(--sky-deep); --seal-red: var(--danger); --danger-bg: var(--danger-tint); --ledger-green: var(--sky-deep); --green-tint: var(--sky-tint); }
        .page-header { margin-bottom: var(--u4); padding-bottom: var(--u3); border-bottom: var(--border) solid var(--ink); }
        .page-header h1 { font-family: var(--f-display); font-size: 26px; font-weight: 700; text-transform: uppercase; }
        .page-header .sub { font-size: 12.5px; opacity: .65; margin-top: 4px; }
        .panel-head .title { display: flex; align-items: center; gap: var(--u1); font-family: var(--f-display); font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; padding: var(--u2) var(--u3); border-bottom: var(--border) solid var(--ink); }
        .panel-body { padding: 0 var(--u3); }
        .btn-mini { display: inline-flex; align-items: center; gap: 4px; height: 28px; padding: 0 12px; border: var(--border) solid var(--ink); background: var(--paper); color: var(--ink); font-family: var(--f-display); font-weight: 700; font-size: 11px; text-transform: uppercase; cursor: pointer; text-decoration: none; margin-top: 4px; }
        .btn-mini:hover { background: var(--ink); color: var(--paper); }
        .btn-mini.locked-btn { opacity: .45; cursor: not-allowed; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/partials/shell-head.php'; ?>
    <div class="stage-view active">
        <div class="page-header">
            <h1>Settings</h1>
            <div class="sub">Manage organization users, source accounts, and configuration.</div>
        </div>

        <div class="panel">
            <div class="panel-head"><span class="title"><?php echo svgIcon('gear'); ?> Settings Categories</span></div>
            <div class="panel-body">
                <?php if ($canManageUsers): ?>
                <div class="row">
                    <span class="row-dot"></span>
                    <div class="row-body">
                        <div class="row-title">User Management</div>
                        <div class="row-sub">Manage organization users, roles, and permissions.</div>
                        <a href="settings/users.php" class="btn-mini">Manage Users</a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($canSeeSourceAccountsArea): ?>
                <div class="row">
                    <span class="row-dot"></span>
                    <div class="row-body">
                        <div class="row-title">Source Accounts</div>
                        <div class="row-sub">Manage your organization's source accounts for disbursements.</div>
                        <a href="imports/add_source.php" class="btn-mini">Manage Sources</a>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row">
                    <span class="row-dot" style="background:var(--paper-dim);"></span>
                    <div class="row-body">
                        <div class="row-title">Organization Profile</div>
                        <div class="row-sub">View and update organization details and preferences.</div>
                        <button type="button" class="btn-mini locked-btn" disabled><?php echo svgIcon('lock'); ?> Coming Soon</button>
                    </div>
                </div>

                <div class="row">
                    <span class="row-dot" style="background:var(--paper-dim);"></span>
                    <div class="row-body">
                        <div class="row-title">Security Settings</div>
                        <div class="row-sub">Configure security preferences and audit logs.</div>
                        <button type="button" class="btn-mini locked-btn" disabled><?php echo svgIcon('lock'); ?> Coming Soon</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php
$dbHealthy = DBConnection::isConnected();
$footerNote = 'Ledger sync: ' . ($dbHealthy ? 'OK' : 'DEGRADED');
require __DIR__ . '/partials/shell-foot.php';
?>
</body>
</html>
