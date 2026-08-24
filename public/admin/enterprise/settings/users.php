<?php
/**
 * enterprise/settings/users.php - HR / USER MANAGEMENT
 *
 * ADAPTED, not rebuilt. Every real call to UserManagementService
 * (createUser, updateUserRole, setActive, resetPassword, listUsers,
 * getUser, ROLE_CATALOG) is unchanged, CSRF was already correct, the
 * dynamic role-description JS and department-mode logic are untouched.
 *
 * What changed: the top-level gate used to be a bare die() with no
 * page chrome at all — the exact kind of "disorganized storeroom"
 * moment this whole redesign has been about fixing — now a real
 * styled 403 inside the shell, same pattern as Departments' 403.
 * Role/nav logic now comes from partials/permissions.php instead of
 * a third hand-rolled copy of the same hardcoded role arrays already
 * found and fixed on index.php, departments/index.php, and settings.php.
 */
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../src/Domain/Services/DepartmentService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/UserManagementService.php';
use Core\Database\DBConnection;
use Domain\Services\DepartmentService;
use Domain\Services\UserManagementService;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;
$role = $user['role'] ?? 'viewer';
$userRole = $role;
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization';
$basePath = '../';

function safeHtmlU($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function getRoleLabelU($roleKey) {
    return UserManagementService::ROLE_CATALOG[$roleKey]['label'] ?? ucfirst(str_replace('_', ' ', $roleKey));
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

require __DIR__ . '/../partials/permissions.php';

if (!$canManageUsers) {
    http_response_code(403);
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>VOUCHMORPH · Manage Users</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600&family=IBM+Plex+Sans+Condensed:wght@700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../partials/shell.css"></head><body>
    <?php $navItems = []; $currentNavKey = null; $attentionActive = false; require __DIR__ . '/../partials/shell-head.php'; ?>
    <div class="stage-view active">
        <div class="stage-head"><div><div class="stage-eyebrow">Access denied</div><div class="stage-title">Manage Users</div></div></div>
        <div class="banner"><div class="lbl">Not authorized</div><div class="desc">This area is limited to Owner, IT Manager, and IT Officer roles. Your role (<?php echo safeHtmlU(getRoleLabel($userRole)); ?>) does not have it.</div></div>
    </div>
    <?php require __DIR__ . '/../partials/shell-foot.php'; ?>
    </body></html>
    <?php
    exit;
}

$deptService = new DepartmentService($db);
$userMgmt = new UserManagementService($db);

/**
 * Flattens getDepartmentTree() into an indent-annotated list for the
 * department <select> — so "Chibia Municipality" visibly nests under
 * "Huíla Province" in the dropdown instead of appearing as an
 * unconnected flat list.
 */
function flattenDeptTree(array $nodes, int $depth = 0): array {
    $out = [];
    foreach ($nodes as $node) {
        $out[] = ['id' => (int)$node['id'], 'name' => $node['name'], 'depth' => $depth];
        if (!empty($node['children'])) {
            $out = array_merge($out, flattenDeptTree($node['children'], $depth + 1));
        }
    }
    return $out;
}

$departmentTree = $deptService->getDepartmentTree($orgId);
$flatDepartments = flattenDeptTree($departmentTree);

/**
 * One user row + its inline edit form. Extracted out of what used to
 * be one giant flat <table> so it can be reused inside Headquarters'
 * section, every department's section, and every sub-department's
 * section without three copies of the same markup.
 */
function renderUserRow(array $u, array $flatDepartments, string $csrfToken): string {
    $rc = UserManagementService::ROLE_CATALOG[$u['role']] ?? null;
    $pillClass = $rc && $rc['category'] === 'Executive' ? 'exec' : ($rc && $rc['category'] === 'Administration' ? 'admin' : '');
    $rowId = 'u' . (int)$u['user_id'];
    $searchBlob = strtolower($u['full_name'] . ' ' . $u['email']);

    $html = '<div class="staff-row" data-search="' . safeHtmlU($searchBlob) . '">';
    $html .= '<div class="staff-row-main">';
    $html .= '<div><span class="role-pill ' . $pillClass . '">' . safeHtmlU(getRoleLabelU($u['role'])) . '</span> <strong>' . safeHtmlU($u['full_name']) . '</strong> <span style="opacity:.6;font-size:12px;">' . safeHtmlU($u['email']) . '</span> <span class="status status-' . ($u['is_active'] ? 'completed' : 'rejected') . '" style="margin-left:6px;">' . ($u['is_active'] ? 'Active' : 'Inactive') . '</span></div>';
    $html .= '<div class="staff-row-actions">';
    $html .= '<button type="button" class="toggle-edit-btn" onclick="toggleStaffEdit(\'' . $rowId . '\')">Edit</button>';
    $html .= '<form method="post" style="display:inline;" onsubmit="return confirm(\'Reset password for ' . safeHtmlU(addslashes($u['full_name'])) . '? A new temporary password will be generated.\')">';
    $html .= '<input type="hidden" name="csrf_token" value="' . safeHtmlU($csrfToken) . '"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="target_user_id" value="' . (int)$u['user_id'] . '">';
    $html .= '<button type="submit" class="btn-mini outline">Reset PW</button></form>';
    if ($u['is_active']) {
        $html .= '<form method="post" style="display:inline;" onsubmit="return confirm(\'Deactivate ' . safeHtmlU(addslashes($u['full_name'])) . '? They will lose access immediately. This can be undone.\')">';
        $html .= '<input type="hidden" name="csrf_token" value="' . safeHtmlU($csrfToken) . '"><input type="hidden" name="action" value="deactivate_user"><input type="hidden" name="target_user_id" value="' . (int)$u['user_id'] . '">';
        $html .= '<button type="submit" class="btn-mini danger">Deactivate</button></form>';
    } else {
        $html .= '<form method="post" style="display:inline;">';
        $html .= '<input type="hidden" name="csrf_token" value="' . safeHtmlU($csrfToken) . '"><input type="hidden" name="action" value="reactivate_user"><input type="hidden" name="target_user_id" value="' . (int)$u['user_id'] . '">';
        $html .= '<button type="submit" class="btn-mini outline">Reactivate</button></form>';
    }
    $html .= '</div></div>';

    $html .= '<form method="post" class="staff-edit-form" id="edit-' . $rowId . '">';
    $html .= '<input type="hidden" name="csrf_token" value="' . safeHtmlU($csrfToken) . '"><input type="hidden" name="action" value="update_user"><input type="hidden" name="target_user_id" value="' . (int)$u['user_id'] . '">';
    $html .= '<div class="form-group"><label>Role</label><select name="role" id="roleSelect_' . $rowId . '" onchange="onRoleChange(\'' . $rowId . '\')">';
    foreach (UserManagementService::ROLE_CATALOG as $key => $info) {
        $html .= '<option value="' . safeHtmlU($key) . '"' . ($key === $u['role'] ? ' selected' : '') . '>' . safeHtmlU($info['label']) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '<div class="form-group dept-field" id="deptField_' . $rowId . '"><label>Department</label><select name="department_id" id="deptSelect_' . $rowId . '"><option value="">— None / Organization-wide —</option>';
    foreach ($flatDepartments as $d) {
        $html .= '<option value="' . $d['id'] . '"' . ((int)$u['department_id'] === $d['id'] ? ' selected' : '') . '>' . str_repeat('— ', $d['depth']) . safeHtmlU($d['name']) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '<div class="role-desc-panel" id="roleDescPanel_' . $rowId . '" style="grid-column:1/-1;"></div>';
    $html .= '<div style="grid-column:1/-1;display:flex;gap:8px;"><button type="submit" class="btn btn-primary btn-sm">Save Changes</button><button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById(\'edit-' . $rowId . '\').classList.remove(\'open-row\')">Cancel</button></div>';
    $html .= '</form></div>';
    return $html;
}

/**
 * One department's accordion: its own directly-assigned staff, an
 * "+ Add here" shortcut into the Create form at the top of the page,
 * then every sub-department nested one level deeper, recursively —
 * exactly the Headquarters → Department → Sub-department shape a
 * large government structure actually has, browsable without
 * rendering thousands of rows open at once.
 */
function renderDeptUserAccordion(array $node, array $usersByDepartment, array $flatDepartments, string $csrfToken, int $depth = 0): string {
    $deptId = (int)$node['id'];
    $people = $usersByDepartment[$deptId] ?? [];
    $childCount = count($node['children'] ?? []);
    $totalUnder = count($people); // direct only — shown alongside child accordions, not double-counted

    $html = '<details class="org-accordion" data-search-scope data-dept-name="' . safeHtmlU(strtolower($node['name'])) . '"' . ($depth === 0 ? ' open' : '') . '>';
    $html .= '<summary class="org-accordion-summary"><span><span class="arrow">&rsaquo;</span> ' . safeHtmlU($node['name']) . '</span><span class="count">' . $totalUnder . ' direct' . ($childCount > 0 ? ' &middot; ' . $childCount . ' sub-dept' . ($childCount > 1 ? 's' : '') : '') . '</span></summary>';
    $html .= '<div class="org-accordion-body">';
    $html .= '<button type="button" class="org-add-link" onclick="addToDepartment(' . $deptId . ', ' . json_encode($node['name']) . ')">+ Add a member to ' . safeHtmlU($node['name']) . '</button>';
    if (!empty($people)) {
        $html .= '<div style="margin-top:10px;">';
        foreach ($people as $person) { $html .= renderUserRow($person, $flatDepartments, $csrfToken); }
        $html .= '</div>';
    } else {
        $html .= '<div class="org-accordion-empty">No staff assigned directly to this department.</div>';
    }
    if (!empty($node['children'])) {
        $html .= '<div class="org-accordion-children">';
        foreach ($node['children'] as $child) {
            $html .= renderDeptUserAccordion($child, $usersByDepartment, $flatDepartments, $csrfToken, $depth + 1);
        }
        $html .= '</div>';
    }
    $html .= '</div></details>';
    return $html;
}

// ============================================================
// HANDLE ACTIONS — completely unchanged real logic.
// ============================================================
$error = '';
$success = '';
$newCredentials = null; // ['name'=>, 'email'=>, 'temp_password'=>] shown exactly once

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_user') {
            $result = $userMgmt->createUser($orgId, [
                'full_name' => $_POST['full_name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'role' => $_POST['role'] ?? '',
                'department_id' => $_POST['department_id'] ?? null,
                'password' => $_POST['password'] ?? '',
            ], (int)$userId, $role);

            $newCredentials = [
                'name' => $_POST['full_name'] ?? '',
                'email' => strtolower(trim($_POST['email'] ?? '')),
                'temp_password' => $result['temp_password'],
            ];
            $success = "Account created for " . safeHtmlU($_POST['full_name'] ?? '') . ".";

        } elseif ($action === 'update_user') {
            $userMgmt->updateUserRole(
                $orgId,
                (int)($_POST['target_user_id'] ?? 0),
                $_POST['role'] ?? '',
                !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
                (int)$userId,
                $role
            );
            $success = "Account updated.";

        } elseif ($action === 'deactivate_user') {
            $userMgmt->setActive($orgId, (int)($_POST['target_user_id'] ?? 0), false, (int)$userId, $role);
            $success = "Account deactivated.";

        } elseif ($action === 'reactivate_user') {
            $userMgmt->setActive($orgId, (int)($_POST['target_user_id'] ?? 0), true, (int)$userId, $role);
            $success = "Account reactivated.";

        } elseif ($action === 'reset_password') {
            $tempPassword = $userMgmt->resetPassword($orgId, (int)($_POST['target_user_id'] ?? 0), (int)$userId, $role);
            $targetInfo = $userMgmt->getUser($orgId, (int)($_POST['target_user_id'] ?? 0));
            $newCredentials = [
                'name' => $targetInfo['full_name'] ?? '',
                'email' => $targetInfo['email'] ?? '',
                'temp_password' => $tempPassword,
            ];
            $success = "Password reset for " . safeHtmlU($targetInfo['full_name'] ?? 'this user') . ".";
        }
    } catch (\RuntimeException $e) {
        $error = $e->getMessage();
    } catch (\Throwable $e) {
        error_log("[settings/users] Unexpected error: " . $e->getMessage());
        $error = "Something went wrong. Please try again.";
    }
}

$users = $userMgmt->listUsers($orgId);
$csrfToken = generateCsrfToken();
$roleCatalogJson = json_encode(UserManagementService::ROLE_CATALOG, JSON_HEX_APOS | JSON_HEX_QUOT);

// ============================================================
// SHARED SHELL SETUP — same $navItems contract as every other page,
// sourced from the one shared permissions file instead of a third
// hand-rolled copy.
// ============================================================
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
    error_log("[settings/users] Nav badge query error: " . $e->getMessage());
}

$navItems = [
    ['key' => 'hub', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => '../index.php', 'show' => true],
    ['key' => 'attention', 'icon' => 'bell', 'label' => 'Attention', 'href' => '../index.php#stage-attention', 'show' => $canViewAttentionTile, 'badge' => ($navPendingApprovals > 0 && $canApprove) ? $navPendingApprovals : null],
    ['key' => 'batches', 'icon' => 'layers', 'label' => 'Batches', 'href' => '../index.php#stage-batches', 'show' => $canViewBatchesTile],
    ['key' => 'activity', 'icon' => 'history', 'label' => 'Activity', 'href' => '../index.php#stage-activity', 'show' => $canViewActivityTile],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace', 'href' => '../index.php#stage-trace', 'show' => $canTrace],
    ['key' => 'reports', 'icon' => 'file', 'label' => 'Reports', 'href' => '../index.php#stage-reports', 'show' => $canViewReports],
    ['key' => 'departments', 'icon' => 'sitemap', 'label' => 'Departments', 'href' => '../departments/index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'beneficiaries', 'icon' => 'users', 'label' => 'Beneficiaries', 'href' => '../beneficiaries.php', 'show' => $canViewBeneficiariesTile],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => '../imports/add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => $navPendingSourceConfirmations > 0 ? $navPendingSourceConfirmations : null],
    ['key' => 'team', 'icon' => 'shield', 'label' => 'Team', 'href' => 'users.php', 'show' => $canManageUsers],
];
$currentNavKey = 'team';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users · VOUCHMORPH Enterprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../partials/shell.css">
    <style>
        /* Compatibility bridge — same pattern as departments/index.php
           and settings.php. Note the old hardcoded blue (#1e40af etc.)
           for the role-description panel is replaced with --sky, not
           aliased — blue isn't a token this system has, and hardcoding
           it here would just ignore skin switching (War Room/Alpha/Gala
           would all render an out-of-place blue box). */
        :root { --ink-900: var(--ink); --ink-500: var(--ink); --ink-300: var(--ink); --brass: var(--sky); --brass-tint: var(--sky-tint); --amber: var(--sky-deep); --seal-red: var(--danger); --danger-bg: var(--danger-tint); --ledger-green: var(--sky-deep); --green-tint: var(--sky-tint); }
        .page-header { margin-bottom: var(--u4); padding-bottom: var(--u3); border-bottom: var(--border) solid var(--ink); }
        .page-header h1 { font-family: var(--f-display); font-size: 26px; font-weight: 700; text-transform: uppercase; }
        .page-header .sub { font-size: 12.5px; opacity: .65; margin-top: 4px; }
        .info-panel { border: var(--border) solid var(--ink); border-left-width: var(--u1); padding: var(--u2) var(--u3); margin-bottom: var(--u3); }
        .card { border: var(--border) solid var(--ink); padding: var(--u3); margin-bottom: var(--u3); background: var(--paper); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--u3); padding-bottom: var(--u2); border-bottom: var(--border) solid var(--ink); }
        .card-title { font-family: var(--f-display); font-weight: 700; font-size: 15px; text-transform: uppercase; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--u2); margin-bottom: var(--u2); }
        .form-group label { display: block; font-family: var(--f-mono); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; opacity: .7; }
        .form-group input, .form-group select { width: 100%; height: var(--u5); padding: 0 var(--u2); border: var(--border) solid var(--ink); background: var(--paper); color: var(--ink); font-family: var(--f-body); font-size: 13px; }
        .table-responsive { overflow-x: auto; }
        .empty-state { text-align: center; padding: var(--u5) var(--u3); font-family: var(--f-mono); font-size: 12.5px; opacity: .55; }
        .creds-row { display:flex; gap:16px; flex-wrap:wrap; align-items:center; margin-bottom:6px; font-size:14px; }
        .creds-row .k { font-family: var(--f-mono); font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; opacity: .65; min-width:90px; }
        .creds-row .v { font-family: var(--f-mono); font-weight: 700; font-size: 15px; }
        .creds-copy-btn { background: var(--sky); color: var(--ink); border: var(--border) solid var(--ink); padding: 4px 12px; font-size: 11px; font-weight: 700; cursor: pointer; font-family: var(--f-display); text-transform: uppercase; }
        .role-pill { display: inline-block; padding: 2px 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; font-family: var(--f-display); border: var(--border) solid var(--ink); }
        .role-pill.exec { background: var(--sky); }
        .role-pill.admin { background: var(--paper-dim); }
        .scope-tag { font-size: 11px; opacity: .7; }
        .scope-tag.unrestricted { color: var(--sky-deep); font-weight: 700; }
        .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .role-desc-panel { background: var(--sky-tint); border-left: var(--u1) solid var(--sky-deep); padding: var(--u2) var(--u3); font-size: 12.5px; margin-top: -4px; margin-bottom: var(--u3); min-height: 20px; }
        .role-desc-panel .cat { font-weight: 700; font-family: var(--f-display); text-transform: uppercase; font-size: 10px; letter-spacing: .05em; display: block; margin-bottom: 4px; color: var(--sky-deep); }
        .dept-field-note { font-size: 11.5px; opacity: .55; margin-top: 4px; }
        .toggle-edit-btn { background: none; border: none; color: var(--sky-deep); cursor: pointer; font-family: var(--f-display); font-size: 11px; text-transform: uppercase; font-weight: 700; letter-spacing: .04em; padding: 0; }
        .btn-mini { display: inline-flex; align-items: center; height: 26px; padding: 0 10px; border: var(--border) solid var(--ink); background: var(--paper); color: var(--ink); font-family: var(--f-display); font-weight: 700; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; cursor: pointer; }
        .btn-mini:hover { background: var(--ink); color: var(--paper); }
        .btn-mini.outline { background: var(--paper); }
        .btn-mini.danger { background: var(--danger); color: var(--paper); border-color: var(--danger); }
        .roster-search { max-width: 480px; margin-bottom: var(--u4); }
        .edit-row { display: none; background: var(--paper-dim); }
        .edit-row.show { display: table-row; }
        .edit-row td { padding: var(--u3); }
        @media (max-width:768px) { table { font-size: 12px; } th,td { padding: 6px 8px; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../partials/shell-head.php'; ?>
    <div class="stage-view active">
        <div class="page-header">
            <h1>Manage Users</h1>
            <div class="sub">Create logins, assign roles and department scope, and manage account status.</div>
        </div>

        <?php if ($error): ?>
        <div class="info-panel" style="border-left-color: var(--danger); background: var(--danger-tint);">
            <div style="color:var(--danger); font-weight:700;">&#9888; <?php echo safeHtmlU($error); ?></div>
        </div>
        <?php endif; ?>

        <?php if ($newCredentials): ?>
        <div class="card" style="border-left: var(--u1) solid var(--sky);">
            <div style="font-family:var(--f-display); font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; margin-bottom:10px;">&#9888; One-time display — copy this now, it will not be shown again</div>
            <div class="creds-row"><span class="k">Name</span><span class="v" style="font-weight:400;"><?php echo safeHtmlU($newCredentials['name']); ?></span></div>
            <div class="creds-row"><span class="k">Email</span><span class="v" style="font-weight:400;"><?php echo safeHtmlU($newCredentials['email']); ?></span></div>
            <div class="creds-row">
                <span class="k">Temp password</span>
                <span class="v" id="tempPwText"><?php echo safeHtmlU($newCredentials['temp_password']); ?></span>
                <button type="button" class="creds-copy-btn" onclick="copyTempPassword()">Copy</button>
            </div>
            <div style="font-size:12px; opacity:.65; margin-top:10px;">
                They'll be required to set a new password on first login. Relay this credential to them through a secure channel — not email in plain text if this account handles anything above viewer/auditor level.
            </div>
        </div>
        <?php elseif ($success): ?>
        <div class="info-panel" style="border-left-color: var(--sky); background: var(--sky-tint);">
            <div style="font-weight:700;"><?php echo safeHtmlU($success); ?></div>
        </div>
        <?php endif; ?>

        <!-- Create User -->
        <div class="card">
            <div class="card-header"><span class="card-title">Add Staff Account</span></div>
            <form method="POST" id="createUserForm">
                <input type="hidden" name="csrf_token" value="<?php echo safeHtmlU($csrfToken); ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" required placeholder="e.g. Teresa Bumba">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required placeholder="name@organization.gov">
                    </div>
                </div>
                <div class="form-group">
                    <label>Password (optional)</label>
                    <input type="text" name="password" placeholder="Leave blank for a random generated one">
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="roleSelect" required onchange="onRoleChange('create')">
                        <option value="">— Select a role —</option>
                        <?php foreach (UserManagementService::ROLE_CATALOG as $key => $info): ?>
                        <option value="<?php echo safeHtmlU($key); ?>"><?php echo safeHtmlU($info['label']); ?> (<?php echo safeHtmlU($info['category']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="role-desc-panel" id="roleDescPanel_create">Select a role above to see what it can see and do — this list is tailored to your organization's own department structure below.</div>
                <div class="form-group dept-field" id="deptField_create">
                    <label id="deptLabel_create">Department</label>
                    <select name="department_id" id="deptSelect_create">
                        <option value="">— None / Organization-wide —</option>
                        <?php foreach ($flatDepartments as $d): ?>
                        <option value="<?php echo $d['id']; ?>"><?php echo str_repeat('— ', $d['depth']) . safeHtmlU($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="dept-field-note" id="deptNote_create"></div>
                </div>
                <button type="submit" class="btn btn-primary">Create Account</button>
            </form>
        </div>

        <!-- Roster — organized by Headquarters / Department / Sub-department,
             not one flat table. For an org the size of a national
             government, a flat list of every staffer is unusable; this
             is the same shape the Departments page's org chart already
             uses, just browsable and editable for account management. -->
        <div class="card">
            <div class="card-header"><span class="card-title">Staff Roster (<?php echo count($users); ?>)</span></div>
            <?php if (empty($users)): ?>
            <div class="empty-state">No staff accounts yet — create the first one above.</div>
            <?php else:
                $usersByDepartment = [];
                $hqUsers = [];
                foreach ($users as $u) {
                    if ($u['department_id'] === null) { $hqUsers[] = $u; }
                    else { $usersByDepartment[(int)$u['department_id']][] = $u; }
                }
            ?>
            <div class="field roster-search">
                <input type="text" id="rosterSearch" placeholder="Search by department, name, or email&hellip;" oninput="filterRoster(this.value)">
            </div>
            <div id="rosterTree">
                <details class="org-accordion" data-search-scope data-dept-name="headquarters" open>
                    <summary class="org-accordion-summary"><span><span class="arrow">&rsaquo;</span> Headquarters</span><span class="count"><?php echo count($hqUsers); ?> direct</span></summary>
                    <div class="org-accordion-body">
                        <button type="button" class="org-add-link" onclick="addToDepartment(null, 'Headquarters')">+ Add a member to Headquarters</button>
                        <?php if (!empty($hqUsers)): ?>
                        <div style="margin-top:10px;"><?php foreach ($hqUsers as $person) { echo renderUserRow($person, $flatDepartments, $csrfToken); } ?></div>
                        <?php else: ?>
                        <div class="org-accordion-empty">No staff assigned directly to Headquarters.</div>
                        <?php endif; ?>
                    </div>
                </details>
                <?php foreach ($departmentTree as $rootNode) { echo renderDeptUserAccordion($rootNode, $usersByDepartment, $flatDepartments, $csrfToken); } ?>
                <div class="org-search-empty" id="rosterSearchEmpty">No departments or staff match that search.</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const ROLE_CATALOG = <?php echo $roleCatalogJson; ?>;

        function onRoleChange(scope) {
            const sel = scope === 'create' ? document.getElementById('roleSelect') : document.getElementById('roleSelect_' + scope);
            const descPanel = scope === 'create' ? document.getElementById('roleDescPanel_create') : document.getElementById('roleDescPanel_' + scope);
            const deptField = scope === 'create' ? document.getElementById('deptField_create') : document.getElementById('deptField_' + scope);
            const deptLabel = scope === 'create' ? document.getElementById('deptLabel_create') : null;
            const deptNote = scope === 'create' ? document.getElementById('deptNote_create') : null;

            const info = ROLE_CATALOG[sel.value];
            if (!info) {
                descPanel.innerHTML = 'Select a role above to see what it can see and do.';
                return;
            }
            descPanel.innerHTML = '<span class="cat">' + info.category + '</span>' + info.description;

            if (info.department_mode === 'none') {
                deptField.style.display = 'none';
            } else {
                deptField.style.display = '';
                if (deptLabel) {
                    deptLabel.textContent = info.department_mode === 'required_exact' ? 'Department (required)' : 'Department (optional)';
                }
                if (deptNote) {
                    deptNote.textContent = info.department_mode === 'required_exact'
                        ? 'This role always needs one specific department — it never operates organization-wide.'
                        : 'Leave blank for organization-wide authority, or pick one department to scope this account to it and every sub-department beneath it.';
                }
            }
        }

        function toggleStaffEdit(rowId) {
            const row = document.getElementById('edit-' + rowId);
            if (!row) return;
            row.classList.toggle('open-row');
            if (row.classList.contains('open-row')) onRoleChange(rowId);
        }

        function copyTempPassword() {
            const text = document.getElementById('tempPwText').textContent;
            navigator.clipboard.writeText(text).then(() => {
                event.target.textContent = 'Copied';
                setTimeout(() => { event.target.textContent = 'Copy'; }, 1500);
            });
        }

        // ------------------------------------------------------------
        // "+ Add a member to X" — scrolls to the existing Create form
        // and pre-fills its department. Doesn't fight the role-based
        // show/hide logic above: if the currently-selected role has no
        // department concept, the field stays hidden until a role that
        // needs one is picked, exactly like manual entry would.
        // ------------------------------------------------------------
        function addToDepartment(deptId, deptName) {
            document.getElementById('createUserForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
            const sel = document.getElementById('deptSelect_create');
            if (sel) {
                sel.value = (deptId === null) ? '' : String(deptId);
                sel.style.outline = '3px solid var(--sky)';
                setTimeout(() => { sel.style.outline = ''; }, 1500);
            }
            const nameInput = document.querySelector('#createUserForm input[name="full_name"]');
            if (nameInput) nameInput.focus();
        }

        // ------------------------------------------------------------
        // REAL-TIME HIERARCHY SEARCH — matches a department by name OR
        // any staff member's name/email inside it, at any depth. A
        // department stays visible if IT matches, if any of its own
        // staff match, or if any sub-department beneath it matches —
        // so searching "Kano" surfaces the State, its LGAs, and every
        // matching staffer, not just an exact hit.
        //
        // HONEST LIMIT: this filters what's already in the page's DOM.
        // For a handful of departments and a few hundred staff this is
        // instant. For a roster in the many-thousands (genuinely
        // possible at national-government scale), the page itself would
        // need server-side, paginated search instead of shipping every
        // row up front — a real next step if this roster ever gets
        // that large, not something to pretend this already handles.
        // ------------------------------------------------------------
        function filterRoster(query) {
            const q = query.trim().toLowerCase();
            const tree = document.getElementById('rosterTree');
            const emptyMsg = document.getElementById('rosterSearchEmpty');
            if (!tree) return;

            function evalAccordion(acc) {
                const deptName = acc.dataset.deptName || '';
                const selfMatch = !q || deptName.includes(q);
                const body = acc.querySelector(':scope > .org-accordion-body');
                const staffRows = body ? Array.from(body.querySelectorAll(':scope > div > .staff-row')) : [];
                let anyStaffMatch = false;
                staffRows.forEach(function (row) {
                    const matches = !q || selfMatch || (row.dataset.search || '').includes(q);
                    row.style.display = matches ? '' : 'none';
                    if (matches) anyStaffMatch = true;
                });

                const childWrap = body ? body.querySelector(':scope > .org-accordion-children') : null;
                const childAccordions = childWrap ? Array.from(childWrap.querySelectorAll(':scope > .org-accordion')) : [];
                let anyChildVisible = false;
                childAccordions.forEach(function (child) { if (evalAccordion(child)) anyChildVisible = true; });

                const visible = !q || selfMatch || anyStaffMatch || anyChildVisible;
                acc.style.display = visible ? '' : 'none';
                if (q && visible) acc.open = true;
                return visible;
            }

            let anyVisible = false;
            Array.from(tree.querySelectorAll(':scope > .org-accordion')).forEach(function (acc) {
                if (evalAccordion(acc)) anyVisible = true;
            });
            if (emptyMsg) emptyMsg.style.display = (q && !anyVisible) ? 'block' : 'none';
        }
    </script>
<?php
$dbHealthy = DBConnection::isConnected();
$footerNote = 'Ledger sync: ' . ($dbHealthy ? 'OK' : 'DEGRADED');
require __DIR__ . '/../partials/shell-foot.php';
?>
</body>
</html>
