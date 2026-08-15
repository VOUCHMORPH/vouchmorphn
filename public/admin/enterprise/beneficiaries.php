<?php
/**
 * beneficiaries.php - Beneficiary Management
 * Placeholder - Will be implemented later
 */
require_once __DIR__ . '/auth.php';
use Core\Database\DBConnection;

$user = requireEnterpriseAuth();
$db = getDBConnection();
$orgId = getOrganizationId();

$userRole = $user['role'] ?? 'viewer';
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization'; // RAW — shell-head.php escapes it itself

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

$basePath = '';
$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$isDepartmentHead = ($userRole === 'department_head');
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head'], true);
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise'], true);
$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true);
$canSeeSourceAccountsArea = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer'], true);
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer'], true);
$canManageDepartments = $isTopRole;
$setupReady = true;

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
} catch (PDOException $e) { error_log("[beneficiaries] Nav badge query error: " . $e->getMessage()); }

$navItems = [
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => $basePath . 'index.php', 'show' => true],
    ['key' => 'disbursements', 'icon' => 'wallet', 'label' => 'Disbursements', 'href' => $basePath . 'batches/index.php?status=all', 'show' => true, 'badge' => ($navPendingApprovals > 0 && $canApprove) ? $navPendingApprovals : null],
    ['key' => 'beneficiaries', 'icon' => 'people', 'label' => 'Beneficiaries', 'href' => $basePath . 'beneficiaries.php', 'show' => true, 'active' => true],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace Payment', 'href' => $basePath . 'index.php#trace', 'show' => $canTrace],
    ['key' => 'departments', 'icon' => 'building', 'label' => 'Departments', 'href' => $basePath . 'departments/index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => $basePath . 'imports/add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => $navPendingSourceConfirmations > 0 ? $navPendingSourceConfirmations : null],
    ['key' => 'team', 'icon' => 'idcard', 'label' => 'Team', 'href' => $basePath . 'settings/users.php', 'show' => $canManageUsers],
    ['key' => 'reports', 'icon' => 'chart', 'label' => 'Reports', 'href' => $basePath . 'reports.php', 'show' => true],
];
$navUtility = [
    ['key' => 'settings', 'icon' => 'gear', 'label' => 'Settings', 'href' => $basePath . 'settings.php', 'show' => true],
    ['key' => 'logout', 'icon' => 'logout', 'label' => 'Log Out', 'href' => $basePath . 'logout.php', 'show' => true],
];
$topbarSearchShow = $canTrace;
$topbarSearchAction = $basePath . 'index.php';
$topbarSearchName = 'trace';
$topbarSearchPlaceholder = 'Search batch reference, phone, national ID…';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Beneficiaries · <?php echo safeHtml($orgName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="partials/shell.css">
</head>
<body>
    <?php require __DIR__ . '/partials/shell-head.php'; ?>
            <div class="page-header">
                <div>
                    <h1>Beneficiary Management</h1>
                    <div class="sub">Manage the people who receive disbursements.</div>
                </div>
            </div>

            <div class="card">
                <div class="empty-state">
                    <div class="icon">👥</div>
                    <p>This module is under development. You can manage beneficiaries from the disbursement workflow.</p>
                    <p style="margin-top:8px; font-size:12.5px;">Use the <strong>"Add Destinations"</strong> flow in the disbursement process to add beneficiaries.</p>
                    <a href="<?php echo safeHtml($basePath . 'imports/add_destinations.php'); ?>" class="btn btn-primary" style="margin-top:16px;"><?php echo svgIcon('plus'); ?> Add Beneficiaries</a>
                </div>
            </div>
        <?php
        $dbHealthy = DBConnection::isConnected();
        $footerStatusLine = 'LEDGER SYNC: ' . ($dbHealthy ? '<span class="ok">OK</span>' : '<span class="bad">DEGRADED</span>');
        require __DIR__ . '/partials/shell-foot.php';
        ?>
</body>
</html>
