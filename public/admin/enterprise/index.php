<?php
/**
 * enterprise/settings/users.php
 *
 * The "system admin creates login profiles" screen -- IT Manager Enterprise,
 * IT Officer Enterprise, and Owner can create/deactivate organization_users
 * accounts here. Enforces the hierarchy from earlier in this conversation:
 * IT Manager can add/remove anyone; IT Officer is restricted to lower-tier
 * operational roles; IT Support gets no add/delete power at all.
 *
 * A person must have a base `users` record (their global VouchMorph
 * identity, phone+PIN login) before being granted an organization role --
 * this screen searches for that record by phone/email first, and only
 * creates a new one if nothing matches.
 */
require_once 'auth.php';
$user = requireEnterpriseAuth();
$pdo = getDBConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';

// Roles this screen is allowed to touch at all
$canManage = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise']);
if (!$canManage) {
    header('HTTP/1.1 403 Forbidden');
    die('Only an Owner, IT Manager, or IT Officer can manage user accounts.');
}

// What roles THIS user is allowed to assign to someone else, per the
// hierarchy: IT Manager/Owner = everyone. IT Officer = operational roles
// only, not approver/senior_approver/department_head/owner/it_manager.
$assignableRoles = in_array($userRole, ['owner', 'it_manager_enterprise'])
    ? ['owner', 'department_head', 'program_officer', 'approver', 'senior_approver',
       'beneficiary_registrar', 'auditor', 'viewer',
       'it_manager_enterprise', 'it_officer_enterprise', 'it_support']
    : ['program_officer', 'beneficiary_registrar', 'viewer', 'it_support']; // IT Officer's ceiling

$error = '';
$success = '';
$csrfToken = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $roleToAssign = $_POST['role'] ?? '';
        $deptId = $_POST['department_id'] ?: null;

        if (!in_array($roleToAssign, $assignableRoles)) {
            $error = 'You are not permitted to assign that role.';
        } elseif (empty($phone) || empty($fullName)) {
            $error = 'Phone number and full name are required.';
        } else {
            try {
                $pdo->beginTransaction();

                // Does a global users record already exist for this phone?
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE phone = :phone OR (email = :email AND :email != '')");
                $stmt->execute([':phone' => $phone, ':email' => $email]);
                $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingUser) {
                    $targetUserId = $existingUser['user_id'];
                } else {
                    // Create a base identity with a random PIN -- they'll
                    // need to reset it via forgot.php on first login, since
                    // we don't want to email/SMS a real PIN in plaintext.
                    $tempPin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, email, phone, password_hash, full_name, verified, created_at, updated_at)
                        VALUES (:username, :email, :phone, :hash, :name, true, NOW(), NOW())
                        RETURNING user_id
                    ");
                    $stmt->execute([
                        ':username' => $phone,
                        ':email' => $email ?: null,
                        ':phone' => $phone,
                        ':hash' => password_hash($tempPin, PASSWORD_DEFAULT),
                        ':name' => $fullName,
                    ]);
                    $targetUserId = $stmt->fetchColumn();
                    $success = "New account created. Temporary PIN: {$tempPin} -- share this securely, it will not be shown again.";
                }

                // Already an org member?
                $stmt = $pdo->prepare("SELECT id FROM organization_users WHERE organization_id = :org_id AND user_id = :user_id");
                $stmt->execute([':org_id' => $orgId, ':user_id' => $targetUserId]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    $error = 'This person is already a member of your organization. Edit their existing role instead of creating a new one.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO organization_users (organization_id, user_id, role, department_id, is_active, invited_by, invited_at, created_at, updated_at)
                        VALUES (:org_id, :user_id, :role, :dept_id, true, :invited_by, NOW(), NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':org_id' => $orgId,
                        ':user_id' => $targetUserId,
                        ':role' => $roleToAssign,
                        ':dept_id' => $deptId,
                        ':invited_by' => $user['id'] ?? $user['user_id'] ?? null,
                    ]);

                    try {
                        $auditStmt = $pdo->prepare("
                            INSERT INTO organization_audit_logs (organization_id, user_id, action, entity_type, entity_id, new_values, ip_address, user_agent, created_at)
                            VALUES (:org_id, :actor_id, 'USER_CREATED', 'organization_users', :entity_id, :new_values, :ip, :ua, NOW())
                        ");
                        $auditStmt->execute([
                            ':org_id' => $orgId,
                            ':actor_id' => $user['id'] ?? $user['user_id'] ?? null,
                            ':entity_id' => $targetUserId,
                            ':new_values' => json_encode(['role' => $roleToAssign, 'department_id' => $deptId, 'full_name' => $fullName]),
                            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ]);
                    } catch (PDOException $e) {
                        error_log("[users.php] Audit log failed: " . $e->getMessage());
                    }

                    $pdo->commit();
                    if (!$success) $success = 'User added to your organization.';
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("[users.php] create_user failed: " . $e->getMessage());
                $error = 'Could not create this user. Please check the details and try again.';
            }
        }
    } elseif ($action === 'deactivate_user') {
        $targetOrgUserId = (int)($_POST['org_user_id'] ?? 0);

        // Fetch target's current role to enforce the hierarchy ceiling
        $stmt = $pdo->prepare("SELECT role FROM organization_users WHERE id = :id AND organization_id = :org_id");
        $stmt->execute([':id' => $targetOrgUserId, ':org_id' => $orgId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            $error = 'User not found.';
        } elseif (!in_array($target['role'], $assignableRoles)) {
            // IT Officer trying to deactivate an approver/owner/etc: denied.
            $error = 'You do not have permission to remove a user with that role.';
        } else {
            $stmt = $pdo->prepare("UPDATE organization_users SET is_active = false, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $targetOrgUserId]);

            try {
                $auditStmt = $pdo->prepare("
                    INSERT INTO organization_audit_logs (organization_id, user_id, action, entity_type, entity_id, old_values, created_at)
                    VALUES (:org_id, :actor_id, 'USER_DEACTIVATED', 'organization_users', :entity_id, :old_values, NOW())
                ");
                $auditStmt->execute([
                    ':org_id' => $orgId,
                    ':actor_id' => $user['id'] ?? $user['user_id'] ?? null,
                    ':entity_id' => $targetOrgUserId,
                    ':old_values' => json_encode(['role' => $target['role']]),
                ]);
            } catch (PDOException $e) {
                error_log("[users.php] Audit log failed: " . $e->getMessage());
            }
            $success = 'User deactivated.';
        }
    }
}

$stmt = $pdo->prepare("
    SELECT ou.id, ou.role, ou.is_active, ou.department_id, ou.created_at,
           u.full_name, u.phone, u.email, d.name as department_name
    FROM organization_users ou
    JOIN users u ON ou.user_id = u.user_id
    LEFT JOIN departments d ON ou.department_id = d.id
    WHERE ou.organization_id = :org_id
    ORDER BY ou.is_active DESC, ou.role, u.full_name
");
$stmt->execute([':org_id' => $orgId]);
$orgUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, name FROM departments WHERE organization_id = :org_id ORDER BY name");
$stmt->execute([':org_id' => $orgId]);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// HELPER: Safe htmlspecialchars wrapper for PHP 8.1+
// ============================================================
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users — VouchMorph Enterprise</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:#f1f5f9; color:#0f172a; padding:32px; }
.wrap { max-width:1100px; margin:0 auto; }
h1 { font-size:22px; font-weight:700; margin-bottom:6px; }
.sub { color:#64748b; font-size:13.5px; margin-bottom:24px; }
.card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; margin-bottom:24px; }
.card h3 { font-size:15px; font-weight:700; margin-bottom:16px; }
.field-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
label { display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:5px; text-transform:uppercase; letter-spacing:.4px; }
input, select { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; }
.btn { background:#0f172a; color:#fff; border:none; padding:11px 24px; border-radius:30px; font-weight:600; font-size:13.5px; cursor:pointer; }
.btn-danger { background:#fee2e2; color:#991b1b; border:none; padding:6px 14px; border-radius:20px; font-weight:600; font-size:11.5px; cursor:pointer; }
.error { background:#fef2f2; color:#dc2626; padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:13.5px; }
.success { background:#f0fdf4; color:#166534; padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:13.5px; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th,td { padding:11px 14px; text-align:left; border-bottom:1px solid #e2e8f0; }
th { background:#f8fafc; font-weight:700; font-size:10.5px; text-transform:uppercase; color:#64748b; letter-spacing:.5px; }
.role-pill { font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; background:#f1f5f9; text-transform:uppercase; }
.inactive { opacity:.5; }
.hierarchy-note { background:#eff6ff; border-left:3px solid #3b82f6; padding:12px 16px; border-radius:8px; font-size:12.5px; color:#1e3a8a; margin-bottom:20px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Manage Users</h1>
    <p class="sub">Signed in as <?php echo safeHtml(strtoupper($userRole)); ?> — you can assign: <?php echo safeHtml(implode(', ', $assignableRoles)); ?></p>

    <?php if ($error): ?><div class="error">⚠️ <?php echo safeHtml($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success">✓ <?php echo safeHtml($success); ?></div><?php endif; ?>

    <div class="hierarchy-note">
        <strong>Hierarchy in effect:</strong> IT Manager Enterprise and Owner can add or remove anyone.
        IT Officer Enterprise can only add/remove Program Officers, Beneficiary Registrars, Viewers, and IT Support —
        not Approvers, Senior Approvers, Department Heads, Owners, or other IT Managers.
    </div>

    <div class="card">
        <h3>Add a user</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
            <input type="hidden" name="action" value="create_user">
            <div class="field-row">
                <div><label>Full Name</label><input type="text" name="full_name" required></div>
                <div><label>Phone Number</label><input type="text" name="phone" required placeholder="71234567"></div>
            </div>
            <div class="field-row">
                <div><label>Email (optional)</label><input type="email" name="email"></div>
                <div>
                    <label>Role</label>
                    <select name="role" required>
                        <?php foreach ($assignableRoles as $r): ?>
                        <option value="<?php echo safeHtml($r); ?>"><?php echo safeHtml(ucwords(str_replace('_', ' ', $r))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field-row" style="grid-template-columns:1fr;">
                <div>
                    <label>Department (leave blank for organization-wide roles like Owner/Auditor)</label>
                    <select name="department_id">
                        <option value="">— None —</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?php echo $d['id']; ?>"><?php echo safeHtml($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn">Create Login</button>
        </form>
    </div>

    <div class="card">
        <h3>Current users (<?php echo count($orgUsers); ?>)</h3>
        <table>
            <thead><tr><th>Name</th><th>Contact</th><th>Role</th><th>Department</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($orgUsers as $ou): ?>
                <tr class="<?php echo $ou['is_active'] ? '' : 'inactive'; ?>">
                    <td><?php echo safeHtml($ou['full_name']); ?></td>
                    <td>
                        <?php echo safeHtml($ou['phone']); ?>
                        <?php if (!empty($ou['email'])): ?>
                        <br><small><?php echo safeHtml($ou['email']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="role-pill"><?php echo safeHtml(str_replace('_', ' ', $ou['role'])); ?></span></td>
                    <td><?php echo safeHtml($ou['department_name'] ?? '—'); ?></td>
                    <td><?php echo $ou['is_active'] ? 'Active' : 'Inactive'; ?></td>
                    <td>
                        <?php if ($ou['is_active'] && in_array($ou['role'], $assignableRoles)): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this user?');">
                            <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                            <input type="hidden" name="action" value="deactivate_user">
                            <input type="hidden" name="org_user_id" value="<?php echo $ou['id']; ?>">
                            <button type="submit" class="btn-danger">Deactivate</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
