<?php
/**
 * enterprise/settings/users.php - HR / USER MANAGEMENT
 *
 * The organization's own staff roster: create logins, assign role +
 * department scope, deactivate/reactivate, reset passwords. Gated to
 * Owner / IT Manager / IT Officer (matches $canManageUsers everywhere
 * else in the dashboard).
 *
 * Deliberately does NOT create organizations — that's a separate,
 * higher-trust surface (see platform-admin/organizations/create.php) so
 * that no in-org role can ever spin up a rival organization from inside
 * its own dashboard.
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

$deptService = new DepartmentService($db);
$userMgmt = new UserManagementService($db);

$canManageUsers = in_array($role, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true);
if (!$canManageUsers) {
    die("You don't have permission to manage users. This area is limited to Owner, IT Manager, and IT Officer roles.");
}

function safeHtmlU($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getRoleLabelU($roleKey) {
    return UserManagementService::ROLE_CATALOG[$roleKey]['label'] ?? ucfirst(str_replace('_', ' ', $roleKey));
}

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

// ============================================================
// HANDLE ACTIONS
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Manage Users</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #EEF1EF; --panel: #FFFFFF; --ink-900: #0F2138; --ink-700: #1D3557;
            --ink-500: #4A5A6E; --ink-300: #8A96A3; --line: #D3DAD6; --line-strong: #AEB8B2;
            --brass: #8A6D3B; --brass-tint: #F4EFE3; --seal-red: #7A2118; --amber: #8A5A0B;
            --ledger-green: #24513A; --green-tint: #E5EEE7; --blue-tint: #E7EEF4;
            --danger: #b3261e; --danger-bg: #fbeceb;
            --f-body: 'IBM Plex Sans', sans-serif; --f-cond: 'IBM Plex Sans Condensed', sans-serif; --f-mono: 'IBM Plex Mono', monospace;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); min-height:100vh; font-size:14px; line-height:1.5; -webkit-font-smoothing:antialiased; }
        .masthead { background: var(--ink-900); color:#fff; padding:14px 32px; display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid var(--brass); flex-wrap:wrap; gap:10px; }
        .masthead h1 { font-family:var(--f-cond); font-size:18px; font-weight:700; letter-spacing:.04em; }
        .masthead .logout-link { color:rgba(255,255,255,0.4); text-decoration:none; font-size:11px; font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em; }
        .masthead .logout-link:hover { color:var(--brass); }
        .stage { max-width:1200px; margin:0 auto; padding:28px 20px; }
        .back-link { display:inline-flex; align-items:center; gap:6px; color:var(--ink-500); text-decoration:none; font-size:13px; font-weight:600; margin-bottom:20px; font-family:var(--f-cond); }
        .back-link:hover { color:var(--brass); }
        .card { background:var(--panel); border:1px solid var(--line); padding:24px; margin-bottom:20px; }
        .card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--line); flex-wrap:wrap; gap:10px; }
        .card-title { font-size:16px; font-weight:700; font-family:var(--f-cond); letter-spacing:.02em; }
        .error-msg { background:var(--danger-bg); color:var(--danger); padding:14px 18px; margin-bottom:16px; border-left:3px solid var(--danger); }
        .success-msg { background:var(--green-tint); color:var(--ledger-green); padding:14px 18px; margin-bottom:16px; border-left:3px solid var(--ledger-green); }
        .creds-box { background:var(--ink-900); color:#fff; padding:20px 24px; margin-bottom:20px; border-left:4px solid var(--brass); }
        .creds-box .warn { color:#fbbf24; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; font-family:var(--f-cond); margin-bottom:10px; }
        .creds-row { display:flex; gap:16px; flex-wrap:wrap; align-items:center; margin-bottom:6px; font-size:14px; }
        .creds-row .k { color:var(--ink-300); font-family:var(--f-cond); font-size:11px; text-transform:uppercase; letter-spacing:.04em; min-width:90px; }
        .creds-row .v { font-family:var(--f-mono); font-weight:700; font-size:16px; color:#fff; }
        .creds-copy-btn { background:var(--brass); color:var(--ink-900); border:none; padding:4px 12px; font-size:11px; font-weight:700; cursor:pointer; font-family:var(--f-cond); text-transform:uppercase; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th { background:var(--paper); color:var(--ink-500); padding:10px 14px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:600; border-bottom:2px solid var(--line); font-family:var(--f-cond); }
        td { padding:10px 14px; border-bottom:1px solid var(--line); vertical-align:middle; font-size:13px; }
        tr:hover { background:var(--brass-tint); }
        .role-pill { display:inline-block; padding:2px 10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; font-family:var(--f-cond); background:var(--blue-tint); color:#1e40af; }
        .role-pill.exec { background:var(--green-tint); color:var(--ledger-green); }
        .role-pill.admin { background:var(--brass-tint); color:var(--brass); }
        .status-pill { display:inline-block; padding:2px 10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; font-family:var(--f-cond); }
        .status-pill.active { background:var(--green-tint); color:var(--ledger-green); }
        .status-pill.inactive { background:var(--danger-bg); color:var(--danger); }
        .scope-tag { font-size:11px; color:var(--ink-500); }
        .scope-tag.unrestricted { color:var(--amber); font-weight:600; }
        .btn { padding:6px 16px; border:none; font-weight:600; font-size:11px; cursor:pointer; transition:all 0.15s; font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em; }
        .btn:hover { opacity:0.85; }
        .btn-primary { background:var(--ink-900); color:#fff; }
        .btn-primary:hover { background:var(--brass); color:var(--ink-900); }
        .btn-danger { background:var(--seal-red); color:#fff; }
        .btn-outline { background:transparent; border:1px solid var(--line); color:var(--ink-500); }
        .btn-outline:hover { border-color:var(--brass); color:var(--ink-900); background:var(--brass-tint); }
        .btn-sm { padding:4px 12px; font-size:10px; }
        .row-actions { display:flex; gap:6px; flex-wrap:wrap; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .form-group { margin-bottom:14px; }
        .form-group label { display:block; margin-bottom:6px; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-family:var(--f-cond); color:var(--ink-500); }
        .form-group input[type=text], .form-group input[type=email], .form-group select {
            width:100%; padding:9px 12px; border:1.5px solid var(--line); font-size:13.5px; font-family:var(--f-body); background:var(--paper); color:var(--ink-900);
        }
        .form-group input:focus, .form-group select:focus { outline:none; border-color:var(--brass); background:var(--panel); }
        .role-desc-panel { background:var(--blue-tint); border-left:3px solid #3b82f6; padding:12px 16px; font-size:12.5px; color:#1e3a5f; margin-top:-4px; margin-bottom:14px; min-height:20px; }
        .role-desc-panel .cat { font-weight:700; font-family:var(--f-cond); text-transform:uppercase; font-size:10px; letter-spacing:.05em; display:block; margin-bottom:4px; color:#1e40af; }
        .dept-field-note { font-size:11.5px; color:var(--ink-300); margin-top:4px; }
        .dept-field.disabled-mode select { opacity:0.5; }
        .empty-state { padding:40px 20px; text-align:center; color:var(--ink-300); }
        .toggle-edit-btn { background:none; border:none; color:var(--brass); cursor:pointer; font-family:var(--f-cond); font-size:11px; text-transform:uppercase; font-weight:700; letter-spacing:.04em; padding:0; }
        .edit-row { display:none; background:var(--brass-tint); }
        .edit-row.show { display:table-row; }
        .edit-row td { padding:16px; }
        @media (max-width:768px) { .form-grid { grid-template-columns:1fr; } .stage { padding:16px; } .card { padding:16px; } table { font-size:12px; } th,td { padding:6px 8px; } }
        @media (prefers-color-scheme:dark) {
            :root { --paper:#1B2733; --panel:#1B2733; --ink-900:#ECEFF2; --ink-700:#D5DCE0; --ink-500:#93A2AC; --ink-300:#6B7A85; --line:#2C3A45; }
            .masthead { background:#0d1a26; }
            .card { background:#1B2733; border-color:#2C3A45; }
            .card-header { border-color:#2C3A45; }
            th { background:#1B2733; color:#93A2AC; border-color:#2C3A45; }
            td { border-color:#2C3A45; }
            tr:hover { background:#22303A; }
            .form-group input, .form-group select { background:#22303A; color:#ECEFF2; border-color:#2C3A45; }
            .edit-row { background:#22303A; }
        }
    </style>
</head>
<body>
    <div class="masthead">
        <h1>VOUCHMORPH · Manage Users</h1>
        <a href="../index.php" class="logout-link" style="color:var(--brass);">← Dashboard</a>
    </div>

    <div class="stage">
        <a href="../index.php" class="back-link">← Dashboard</a>

        <?php if ($error): ?>
        <div class="error-msg">⚠️ <?php echo safeHtmlU($error); ?></div>
        <?php endif; ?>

        <?php if ($newCredentials): ?>
        <div class="creds-box">
            <div class="warn">⚠ One-time display — copy this now, it will not be shown again</div>
            <div class="creds-row"><span class="k">Name</span><span class="v" style="font-size:14px; font-weight:400;"><?php echo safeHtmlU($newCredentials['name']); ?></span></div>
            <div class="creds-row"><span class="k">Email</span><span class="v" style="font-size:14px; font-weight:400;"><?php echo safeHtmlU($newCredentials['email']); ?></span></div>
            <div class="creds-row">
                <span class="k">Temp password</span>
                <span class="v" id="tempPwText"><?php echo safeHtmlU($newCredentials['temp_password']); ?></span>
                <button type="button" class="creds-copy-btn" onclick="copyTempPassword()">📋 Copy</button>
            </div>
            <div style="font-size:12px; color:rgba(255,255,255,0.6); margin-top:10px;">
                They'll be required to set a new password on first login. Relay this credential to them through a secure channel — not email in plain text if this account handles anything above viewer/auditor level.
            </div>
        </div>
        <?php elseif ($success): ?>
        <div class="success-msg">✅ <?php echo safeHtmlU($success); ?></div>
        <?php endif; ?>

        <!-- Create User -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">➕ Add Staff Account</span>
            </div>
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

        <!-- Roster -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">👥 Staff Roster (<?php echo count($users); ?>)</span>
            </div>
            <?php if (empty($users)): ?>
            <div class="empty-state"><p>No staff accounts yet — create the first one above.</p></div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th><th>Email</th><th>Role</th><th>Scope</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u):
                        $rc = UserManagementService::ROLE_CATALOG[$u['role']] ?? null;
                        $pillClass = $rc && $rc['category'] === 'Executive' ? 'exec' : ($rc && $rc['category'] === 'Administration' ? 'admin' : '');
                        $mode = $rc['department_mode'] ?? 'none';
                        $rowId = 'u' . (int)$u['user_id'];
                    ?>
                    <tr>
                        <td><?php echo safeHtmlU($u['full_name']); ?></td>
                        <td><?php echo safeHtmlU($u['email']); ?></td>
                        <td><span class="role-pill <?php echo $pillClass; ?>"><?php echo safeHtmlU(getRoleLabelU($u['role'])); ?></span></td>
                        <td>
                            <?php if ($mode === 'none'): ?>
                            <span class="scope-tag">— (always org-wide)</span>
                            <?php elseif ($u['department_id'] === null): ?>
                            <span class="scope-tag unrestricted">⚠ Org-wide (unscoped)</span>
                            <?php else: ?>
                            <span class="scope-tag"><?php echo safeHtmlU($u['department_name'] ?? 'Unknown department'); ?> + sub-departments</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="status-pill <?php echo $u['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="toggle-edit-btn" onclick="toggleEdit('<?php echo $rowId; ?>')">✏️ Edit</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Reset password for <?php echo safeHtmlU(addslashes($u['full_name'])); ?>? A new temporary password will be generated.')">
                                    <input type="hidden" name="csrf_token" value="<?php echo safeHtmlU($csrfToken); ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="target_user_id" value="<?php echo (int)$u['user_id']; ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">🔑 Reset PW</button>
                                </form>
                                <?php if ($u['is_active']): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate <?php echo safeHtmlU(addslashes($u['full_name'])); ?>? They will lose access immediately. This can be undone.')">
                                    <input type="hidden" name="csrf_token" value="<?php echo safeHtmlU($csrfToken); ?>">
                                    <input type="hidden" name="action" value="deactivate_user">
                                    <input type="hidden" name="target_user_id" value="<?php echo (int)$u['user_id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Deactivate</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo safeHtmlU($csrfToken); ?>">
                                    <input type="hidden" name="action" value="reactivate_user">
                                    <input type="hidden" name="target_user_id" value="<?php echo (int)$u['user_id']; ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Reactivate</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr class="edit-row" id="edit_<?php echo $rowId; ?>">
                        <td colspan="6">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo safeHtmlU($csrfToken); ?>">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="target_user_id" value="<?php echo (int)$u['user_id']; ?>">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Role</label>
                                        <select name="role" id="roleSelect_<?php echo $rowId; ?>" onchange="onRoleChange('<?php echo $rowId; ?>')">
                                            <?php foreach (UserManagementService::ROLE_CATALOG as $key => $info): ?>
                                            <option value="<?php echo safeHtmlU($key); ?>" <?php echo $key === $u['role'] ? 'selected' : ''; ?>><?php echo safeHtmlU($info['label']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group dept-field" id="deptField_<?php echo $rowId; ?>">
                                        <label>Department</label>
                                        <select name="department_id" id="deptSelect_<?php echo $rowId; ?>">
                                            <option value="">— None / Organization-wide —</option>
                                            <?php foreach ($flatDepartments as $d): ?>
                                            <option value="<?php echo $d['id']; ?>" <?php echo ((int)$u['department_id'] === $d['id']) ? 'selected' : ''; ?>><?php echo str_repeat('— ', $d['depth']) . safeHtmlU($d['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="role-desc-panel" id="roleDescPanel_<?php echo $rowId; ?>"></div>
                                <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                                <button type="button" class="btn btn-outline btn-sm" onclick="toggleEdit('<?php echo $rowId; ?>')">Cancel</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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

        function toggleEdit(rowId) {
            const row = document.getElementById('edit_' + rowId);
            row.classList.toggle('show');
            if (row.classList.contains('show')) {
                onRoleChange(rowId);
            }
        }

        function copyTempPassword() {
            const text = document.getElementById('tempPwText').textContent;
            navigator.clipboard.writeText(text).then(() => {
                event.target.textContent = '✅ Copied';
                setTimeout(() => { event.target.textContent = '📋 Copy'; }, 1500);
            });
        }
    </script>
</body>
</html>
