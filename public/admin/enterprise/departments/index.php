<?php
/**
 * enterprise/departments/index.php - Department & Ration Dashboard
 *
 * ADAPTED, not rebuilt: every real function below (DepartmentService/
 * UserManagementService calls, sub-department + ration-borrow
 * workflow, staff quick-add, ceiling math, audit logging, CSRF) is
 * unchanged from what this page already was. A previous, much
 * simpler fabricated version of this file existed for a few turns
 * of this conversation, built without ever having seen this real
 * one — it has been discarded entirely, not merged.
 *
 * What actually changed here: the shell integration (this page now
 * uses partials/permissions.php instead of its own second hardcoded
 * copy of role logic) and the presentation layer (old shell.css class
 * names → current ones). See the two notes below for exactly how.
 *
 * NOTE 1 — COMPATIBILITY ALIASES, not a permanent fixture: this page
 * has ~300 lines of inline `style="...var(--brass)..."` etc. across
 * renderDeptRow()/renderHierarchyNode() using the OLD design system's
 * token names (--ink-900, --brass, --seal-red, --panel, ...). Rather
 * than hand-edit every inline style (real risk of breaking a working
 * page for a cosmetic rename), the <style> block below aliases the
 * old token names to the current ones (--ink, --sky, --danger,
 * --paper, ...). This means the legacy inline styles keep working
 * AND automatically respond to skin switching (War Room/Alpha/Gala)
 * for free. It also means a few old CLASS names with no current
 * equivalent (.page-header, .panel-grid, .task-row, .form-grid, ...)
 * are given local re-definitions in that same <style> block instead
 * of being renamed throughout the HTML. Normalize this properly in a
 * quieter maintenance pass — this is a bridge, not the final form.
 *
 * NOTE 2 — a new guessed permission code appears here that doesn't
 * exist elsewhere in this codebase: 'approve_borrow' (owner,
 * it_manager_enterprise, finance_officer in the original hardcoded
 * check). Like every other permission code in partials/permissions.php,
 * this needs to actually exist in organization_role_permissions or
 * it silently resolves to false for everyone except owner.
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../src/Domain/Services/DepartmentService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/UserManagementService.php';

use Domain\Services\DepartmentService;
use Domain\Services\UserManagementService;
use Core\Database\DBConnection;

$user = requireEnterpriseAuth();
$pdo = getDBConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization';
$basePath = '../';

// FIX: this page used to carry its own second hardcoded copy of role
// logic ($isTopRole/$isDepartmentHead/$canApproveBorrow as inline
// in_array() checks) — the exact "two sources of truth" duplication
// problem already found and fixed in index.php. Now uses the same
// shared file, same real hasPermission()-backed system.
require __DIR__ . '/../partials/permissions.php';
$canApproveBorrow = can('approve_borrow');

// This page is for org-structure management only. Batch staff, plain
// finance visibility, auditors etc. get bounced back to the dashboard —
// they have no actions here and don't need to see ceilings org-wide.
if (!$isTopRole && !$isDepartmentHead) {
    header('Location: ../index.php');
    exit;
}

$deptService = new DepartmentService($pdo);
function logDepartmentAudit(PDO $pdo, int $orgId, ?int $actorId, string $action, ?int $entityId, array $values): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO organization_audit_logs (
                organization_id, user_id, action, entity_type, entity_id,
                new_values, ip_address, user_agent, created_at
            ) VALUES (
                :org_id, :user_id, :action, 'department', :entity_id,
                :new_values, :ip, :ua, NOW()
            )
        ");
        $stmt->execute([
            ':org_id' => $orgId, ':user_id' => $actorId, ':action' => $action,
            ':entity_id' => $entityId, ':new_values' => json_encode($values),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (PDOException $e) {
        error_log("[departments] Audit log failed: " . $e->getMessage());
    }
}

$userMgmt = new UserManagementService($pdo);

// ============================================================
// HANDLE FORM SUBMISSIONS (POST) — completely unchanged. CSRF was
// already correctly wired here (requireCsrfToken()) before I ever
// saw this file — nothing to fix in this block.
// ============================================================
$flashMessage = null;
$flashType = 'success';
$newDepartmentId = null;
$newStaffCredentials = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create_department':
                if (!$isTopRole) throw new RuntimeException('Not authorized.');
                $newId = $deptService->createDepartment(
                    $orgId,
                    trim($_POST['name'] ?? ''),
                    trim($_POST['code'] ?? '') ?: null,
                    trim($_POST['cost_center'] ?? '') ?: null,
                    (isset($_POST['budget_ceiling']) && trim($_POST['budget_ceiling']) !== '') ? (float)$_POST['budget_ceiling'] : null,
                    (int)$userId,
                    $userRole
                );
                $newDepartmentId = $newId;
                $flashMessage = 'Department created. Assign an Uploader, Approver, and/or Disburser below — all optional, leave any of them blank and Headquarters covers it.';
                break;

            case 'create_sub_department':
                if (!$isTopRole) throw new RuntimeException('Not authorized.');
                $newId = $deptService->createSubDepartment(
                    (int)($_POST['parent_id'] ?? 0),
                    trim($_POST['name'] ?? ''),
                    trim($_POST['code'] ?? '') ?: null,
                    trim($_POST['cost_center'] ?? '') ?: null,
                    (isset($_POST['budget_ceiling']) && trim($_POST['budget_ceiling']) !== '') ? (float)$_POST['budget_ceiling'] : null,
                    (int)$userId,
                    $userRole
                );
                $newDepartmentId = $newId;
                $flashMessage = 'Sub-department created. Assign an Uploader, Approver, and/or Disburser below — all optional, leave any of them blank and the parent department (or Headquarters) covers it.';
                break;

            case 'quick_add_staff':
                if (!$isTopRole) throw new RuntimeException('Not authorized.');
                $staffDeptId = (int)($_POST['department_id'] ?? 0);
                $staffRole = $_POST['staff_role'] ?? '';
                if (!in_array($staffRole, ['program_officer', 'approver', 'owner'], true)) {
                    throw new RuntimeException('Invalid role for quick-add — use Manage Users for other roles.');
                }
                $staffName = trim($_POST['staff_name'] ?? '');
                $staffEmail = trim($_POST['staff_email'] ?? '');
                $result = $userMgmt->createUser($orgId, [
                    'full_name' => $staffName,
                    'email' => $staffEmail,
                    'phone' => $_POST['staff_phone'] ?? '',
                    'role' => $staffRole,
                    'department_id' => $staffDeptId,
                    'password' => $_POST['staff_password'] ?? '',
                ], (int)$userId, $userRole);
                $newDepartmentId = $staffDeptId;
                $newStaffCredentials = [
                    'name' => $staffName,
                    'email' => strtolower($staffEmail),
                    'temp_password' => $result['temp_password'],
                ];
                $flashMessage = 'Staff account created.';
                break;

            case 'request_sub_department':
                if (!$isDepartmentHead) throw new RuntimeException('Not authorized.');
                $deptService->requestSubDepartment(
                    $orgId,
                    (int)($_POST['parent_id'] ?? 0),
                    trim($_POST['name'] ?? ''),
                    trim($_POST['code'] ?? '') ?: null,
                    isset($_POST['requested_ceiling']) && $_POST['requested_ceiling'] !== ''
                        ? (float)$_POST['requested_ceiling'] : null,
                    (int)$userId,
                    $userRole
                );
                $flashMessage = 'Sub-department request submitted for approval.';
                break;

            case 'decide_sub_department_request':
                if (!$isTopRole) throw new RuntimeException('Not authorized.');
                $result = $deptService->decideSubDepartmentRequest(
                    (int)($_POST['request_id'] ?? 0),
                    ($_POST['decision'] ?? '') === 'approve',
                    (int)$userId,
                    $userRole
                );
                $flashMessage = $result['approved'] ? 'Sub-department request approved.' : 'Sub-department request rejected.';
                break;

            case 'update_ceiling':
                if (!$isTopRole) throw new RuntimeException('Not authorized.');
                $deptService->updateDepartmentCeiling(
                    (int)($_POST['department_id'] ?? 0),
                    (isset($_POST['new_ceiling']) && trim($_POST['new_ceiling']) !== '') ? (float)$_POST['new_ceiling'] : null,
                    (int)$userId,
                    $userRole
                );
                $flashMessage = 'Ceiling updated.';
                break;

            case 'edit_department':
                if (!$isTopRole) throw new RuntimeException('Not authorized.');
                $editDeptId = (int)($_POST['department_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM departments WHERE id = :id AND organization_id = :org_id");
                $stmt->execute([':id' => $editDeptId, ':org_id' => $orgId]);
                $existingDept = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingDept) throw new RuntimeException('Department not found.');

                $newName = trim($_POST['name'] ?? '');
                $newCode = trim($_POST['code'] ?? '') ?: null;
                $newCostCenter = trim($_POST['cost_center'] ?? '') ?: null;
                if ($newName === '') throw new RuntimeException('Department name is required.');

                $stmt = $pdo->prepare("
                    UPDATE departments
                    SET name = :name, code = :code, cost_center = :cost_center, updated_at = NOW()
                    WHERE id = :id AND organization_id = :org_id
                ");
                $stmt->execute([
                    ':name' => $newName, ':code' => $newCode, ':cost_center' => $newCostCenter,
                    ':id' => $editDeptId, ':org_id' => $orgId,
                ]);

                logDepartmentAudit($pdo, $orgId, (int)$userId, 'DEPARTMENT_EDITED', $editDeptId, [
                    'before' => ['name' => $existingDept['name'], 'code' => $existingDept['code'], 'cost_center' => $existingDept['cost_center']],
                    'after' => ['name' => $newName, 'code' => $newCode, 'cost_center' => $newCostCenter],
                ]);
                $newDepartmentId = $editDeptId;
                $flashMessage = 'Department updated.';
                break;

            case 'set_department_status':
                if (!$isTopRole) throw new RuntimeException('Not authorized.');
                $statusDeptId = (int)($_POST['department_id'] ?? 0);
                $newStatus = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';

                $stmt = $pdo->prepare("SELECT name, status FROM departments WHERE id = :id AND organization_id = :org_id");
                $stmt->execute([':id' => $statusDeptId, ':org_id' => $orgId]);
                $existingDept = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingDept) throw new RuntimeException('Department not found.');

                $stmt = $pdo->prepare("UPDATE departments SET status = :status, updated_at = NOW() WHERE id = :id AND organization_id = :org_id");
                $stmt->execute([':status' => $newStatus, ':id' => $statusDeptId, ':org_id' => $orgId]);

                logDepartmentAudit($pdo, $orgId, (int)$userId, 'DEPARTMENT_' . strtoupper($newStatus), $statusDeptId, [
                    'name' => $existingDept['name'], 'before_status' => $existingDept['status'], 'after_status' => $newStatus,
                ]);
                $flashMessage = 'Department ' . ($newStatus === 'active' ? 'reactivated' : 'deactivated') . '.';
                break;

            case 'request_borrow':
                if (!$isDepartmentHead) throw new RuntimeException('Not authorized.');
                $deptService->requestBorrow(
                    $orgId,
                    (int)($_POST['borrowing_department_id'] ?? 0),
                    (int)($_POST['lending_department_id'] ?? 0),
                    (float)($_POST['amount'] ?? 0),
                    trim($_POST['reason'] ?? ''),
                    (int)$userId,
                    $userRole
                );
                $flashMessage = 'Borrow request submitted for approval.';
                break;

            case 'approve_borrow':
                if (!$canApproveBorrow) throw new RuntimeException('Not authorized.');
                $deptService->approveBorrowRequest((int)($_POST['request_id'] ?? 0), (int)$userId, $userRole);
                $flashMessage = 'Borrow approved — ceiling transferred.';
                break;

            case 'reject_borrow':
                if (!$canApproveBorrow) throw new RuntimeException('Not authorized.');
                $deptService->rejectBorrowRequest(
                    (int)($_POST['request_id'] ?? 0),
                    (int)$userId,
                    $userRole,
                    trim($_POST['reason'] ?? '')
                );
                $flashMessage = 'Borrow request rejected.';
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (\Throwable $e) {
        $flashMessage = $e->getMessage();
        $flashType = 'error';
        error_log('[Departments] Action failed: ' . $e->getMessage());
    }

    $_SESSION['dept_flash'] = [
        'message' => $flashMessage,
        'type' => $flashType,
        'new_department_id' => $newDepartmentId,
        'staff_credentials' => $newStaffCredentials,
    ];
    header('Location: index.php');
    exit;
}

if (isset($_SESSION['dept_flash'])) {
    $flashMessage = $_SESSION['dept_flash']['message'];
    $flashType = $_SESSION['dept_flash']['type'];
    $newDepartmentId = $_SESSION['dept_flash']['new_department_id'] ?? null;
    $newStaffCredentials = $_SESSION['dept_flash']['staff_credentials'] ?? null;
    unset($_SESSION['dept_flash']);
}

// ============================================================
// FETCH DATA — unchanged
// ============================================================
$tree = $deptService->getDepartmentTree($orgId);
$flatDepartments = $deptService->getDepartmentsFlat($orgId);

$myDepartment = $isDepartmentHead ? $deptService->getDepartmentHeadedBy((int)$userId) : null;
$myDepartmentRation = $myDepartment ? $deptService->getAvailableRation((int)$myDepartment['id']) : null;

$pendingSubDeptRequests = $isTopRole ? $deptService->getPendingSubDepartmentRequests($orgId) : [];
$pendingBorrowRequests = $canApproveBorrow ? $deptService->getPendingBorrowRequests($orgId) : [];

$staffByDepartment = [];
$hqStaff = [];
$sourceAccountSummary = ['confirmed' => 0, 'pending' => 0, 'names' => []];
if ($isTopRole) {
    $stmt = $pdo->prepare("
        SELECT department_id, user_id, full_name, role, is_active
        FROM organization_users
        WHERE organization_id = :org_id AND department_id IS NOT NULL
        ORDER BY role ASC, full_name ASC
    ");
    $stmt->execute([':org_id' => $orgId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $staffByDepartment[(int)$row['department_id']][] = $row;
    }

    $stmt = $pdo->prepare("
        SELECT user_id, full_name, role, is_active
        FROM organization_users
        WHERE organization_id = :org_id AND department_id IS NULL AND is_active = true
        ORDER BY role ASC, full_name ASC
    ");
    $stmt->execute([':org_id' => $orgId]);
    $hqStaff = $stmt->fetchAll(PDO::FETCH_ASSOC);

    try {
        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) as c, array_agg(institution) as institutions
            FROM source_accounts
            WHERE organization_id = :org_id AND deleted_at IS NULL
            GROUP BY status
        ");
        $stmt->execute([':org_id' => $orgId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['status'] === 'active') {
                $sourceAccountSummary['confirmed'] = (int)$row['c'];
                $sourceAccountSummary['names'] = array_filter((array)($row['institutions'] ?? []));
            } elseif ($row['status'] === 'pending_confirmation') {
                $sourceAccountSummary['pending'] = (int)$row['c'];
            }
        }
    } catch (PDOException $e) {
        error_log("[departments] Source account summary error: " . $e->getMessage());
    }
}

// ============================================================
// Real aggregates for the stat cards — computed from the same $tree
// the rest of this page already trusts.
// ============================================================
function sumTreeCeilings(array $nodes, bool $rootLevel, array &$rootTotal, array &$allocatedTotal): void {
    foreach ($nodes as $node) {
        if ($rootLevel) {
            if ($node['has_ceiling']) { $rootTotal[0] += (float)$node['budget_ceiling']; }
        } else {
            if ($node['has_ceiling']) { $allocatedTotal[0] += (float)$node['budget_ceiling']; }
        }
        if (!empty($node['children'])) {
            sumTreeCeilings($node['children'], false, $rootTotal, $allocatedTotal);
        }
    }
}
$rootTotalBox = [0.0];
$allocatedTotalBox = [0.0];
sumTreeCeilings($tree, true, $rootTotalBox, $allocatedTotalBox);
$totalGlobalBudget = $rootTotalBox[0];
$totalAllocated = $allocatedTotalBox[0];
$unallocatedReserve = $totalGlobalBudget - $totalAllocated;

/** First department_head-role staffer on record for a department, or null. */
function resolveDepartmentHead(int $deptId, array $staffByDepartment): ?string {
    foreach ($staffByDepartment[$deptId] ?? [] as $person) {
        if ($person['role'] === 'department_head' && $person['is_active']) {
            return $person['full_name'];
        }
    }
    return null;
}

// Unified Pending Approvals feed — sub-department requests and ration
// borrow requests merged into one list, newest first.
$unifiedApprovals = [];
foreach ($pendingSubDeptRequests as $r) {
    $unifiedApprovals[] = [
        'type' => 'sub_department', 'id' => $r['id'], 'created_at' => $r['created_at'],
        'tag' => 'STRUCTURE', 'title' => 'New sub-department: ' . $r['proposed_name'],
        'meta' => 'Under ' . $r['parent_department_name'] . ($r['requested_ceiling'] !== null ? ' — requested ceiling ' . formatCurrency($r['requested_ceiling']) : ' — no ceiling requested'),
        'can_decide' => $isTopRole,
    ];
}
foreach ($pendingBorrowRequests as $r) {
    $unifiedApprovals[] = [
        'type' => 'borrow', 'id' => $r['id'], 'created_at' => $r['created_at'],
        'tag' => 'RATION', 'title' => $r['borrowing_department_name'] . ' borrowing from ' . $r['lending_department_name'],
        'meta' => formatCurrency($r['amount']) . ' — ' . $r['reason'],
        'can_decide' => $canApproveBorrow,
    ];
}
usort($unifiedApprovals, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

$quickAddSlots = [
    'program_officer' => ['label' => 'Uploader', 'desc' => 'Builds and submits this department\'s batches.'],
    'approver' => ['label' => 'Approver', 'desc' => 'Approves or rejects this department\'s batches.'],
    'owner' => ['label' => 'Disburser', 'desc' => 'Executes this department\'s approved batches.'],
];

$csrfToken = generateCsrfToken();

function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function formatCurrency($amount, $currency = 'BWP') {
    return number_format((float)$amount, 2) . ' ' . $currency;
}
function ratioBarColor(float $percent): string {
    if ($percent >= 100) return 'var(--seal-red)';
    if ($percent >= 80) return 'var(--amber)';
    return 'var(--ledger-green)';
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

/**
 * One row of the Departmental Allocations table, plus (for top roles)
 * an expandable "Manage" panel underneath. UNCHANGED from before this
 * adaptation — every class/var name here is legacy and resolved via
 * the compatibility aliases in this page's <style> block.
 */
function renderDeptRow(array $node, bool $isTopRole, array $staffByDepartment, array $quickAddSlots, ?int $newDepartmentId, string $csrfToken, int $depth = 0): string {
    $deptId = (int)$node['id'];
    $indent = $depth * 22;
    $pct = $node['utilization_percent'];
    $barColor = ratioBarColor($pct);
    $head = resolveDepartmentHead($deptId, $staffByDepartment);
    $staffCount = count($staffByDepartment[$deptId] ?? []);

    $html = '<tr>';
    $html .= '<td style="padding-left:' . (14 + $indent) . 'px;">';
    $html .= '<div style="display:flex; align-items:center; gap:10px;">';
    $html .= '<div style="width:30px; height:30px; flex-shrink:0; background:var(--paper); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; color:var(--brass);">' . svgIcon($depth > 0 ? 'idcard' : 'building') . '</div>';
    $html .= '<div><div style="font-weight:700; font-size:13.5px;">' . ($depth > 0 ? '&#8627; ' : '') . safeHtml($node['name']);
    if ($node['is_over_ration']) { $html .= ' <span class="status status-rejected" style="margin-left:6px;">OVER</span>'; }
    if ($node['status'] !== 'active') { $html .= ' <span class="status status-draft" style="margin-left:6px;">INACTIVE</span>'; }
    $html .= '</div>';
    $html .= '<div style="font-size:11px; color:var(--ink-300); font-family:var(--f-mono); text-transform:uppercase;">Head: ' . ($head ? safeHtml($head) : 'Not assigned') . '</div>';
    $html .= '</div></div></td>';

    if ($node['has_ceiling']) {
        $html .= '<td style="font-variant-numeric:tabular-nums;">' . formatCurrency($node['budget_ceiling']) . '</td>';
        $html .= '<td style="font-variant-numeric:tabular-nums; ' . ($node['is_over_ration'] ? 'color:var(--seal-red); font-weight:700;' : '') . '">' . formatCurrency($node['amount_disbursed_ytd']) . '</td>';
        $html .= '<td><div style="display:flex; align-items:center; gap:8px;"><div style="width:70px; height:6px; background:var(--paper); border:1px solid var(--line);"><div style="width:' . min(100, $pct) . '%; height:100%; background:' . $barColor . ';"></div></div><span style="font-family:var(--f-mono); font-size:12px; font-variant-numeric:tabular-nums;">' . $pct . '%</span></div></td>';
    } else {
        $html .= '<td colspan="3" style="color:var(--ink-300); font-style:italic; font-size:12.5px;">No vote — limited only by source account balance at execute time</td>';
    }

    $html .= '<td style="text-align:right;">';
    if ($isTopRole) {
        $html .= '<button type="button" class="btn-mini outline" onclick="document.getElementById(\'manage-' . $deptId . '\').classList.toggle(\'open-row\')">Manage</button>';
    }
    $html .= '</td></tr>';

    if ($isTopRole) {
        $autoOpen = ($newDepartmentId !== null && $deptId === (int)$newDepartmentId);
        $html .= '<tr id="manage-' . $deptId . '" class="manage-row' . ($autoOpen ? ' open-row' : '') . '"><td colspan="5" style="background:var(--paper); padding:18px 20px;">';

        $html .= '<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">';

        $html .= '<div>';
        $html .= '<form method="post" class="form-grid" style="margin-bottom:10px;">';
        $html .= '<input type="hidden" name="csrf_token" value="' . safeHtml($csrfToken) . '">';
        $html .= '<input type="hidden" name="action" value="edit_department">';
        $html .= '<input type="hidden" name="department_id" value="' . $deptId . '">';
        $html .= '<div class="form-group" style="grid-column:1/-1;"><label>Name</label><input type="text" name="name" value="' . safeHtml($node['name']) . '" required></div>';
        $html .= '<div class="form-group"><label>Code</label><input type="text" name="code" value="' . safeHtml($node['code'] ?? '') . '"></div>';
        $html .= '<div class="form-group"><label>Cost Center</label><input type="text" name="cost_center" value="' . safeHtml($node['cost_center'] ?? '') . '"></div>';
        $html .= '<div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-outline btn-sm">Save</button></div>';
        $html .= '</form>';

        $html .= '<form method="post" class="form-grid" style="margin-bottom:10px;">';
        $html .= '<input type="hidden" name="csrf_token" value="' . safeHtml($csrfToken) . '">';
        $html .= '<input type="hidden" name="action" value="update_ceiling">';
        $html .= '<input type="hidden" name="department_id" value="' . $deptId . '">';
        $html .= '<div class="form-group"><label>New Ceiling <span style="text-transform:none; color:var(--ink-300);">(blank = no vote)</span></label><input type="number" step="0.01" name="new_ceiling" placeholder="' . ($node['has_ceiling'] ? number_format($node['budget_ceiling'], 2) : '') . '"></div>';
        $html .= '<div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-outline btn-sm">Update Ceiling</button></div>';
        $html .= '</form>';

        $html .= '<form method="post" onsubmit="return confirm(\'' . ($node['status'] === 'active' ? 'Deactivate' : 'Reactivate') . ' this department?\')">';
        $html .= '<input type="hidden" name="csrf_token" value="' . safeHtml($csrfToken) . '">';
        $html .= '<input type="hidden" name="action" value="set_department_status">';
        $html .= '<input type="hidden" name="department_id" value="' . $deptId . '">';
        $html .= '<input type="hidden" name="new_status" value="' . ($node['status'] === 'active' ? 'inactive' : 'active') . '">';
        $html .= '<button type="submit" class="btn btn-mini ' . ($node['status'] === 'active' ? 'danger' : '') . '">' . ($node['status'] === 'active' ? 'Deactivate' : 'Reactivate') . ' Department</button>';
        $html .= '</form>';
        $html .= '</div>';

        $html .= '<div>';
        $html .= '<div style="font-family:var(--f-cond); font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-500); margin-bottom:8px;">Staffing (' . $staffCount . ')</div>';
        if ($staffCount > 0) {
            $html .= '<div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px;">';
            foreach ($staffByDepartment[$deptId] as $person) {
                $roleLabel = $quickAddSlots[$person['role']]['label'] ?? ucfirst(str_replace('_', ' ', $person['role']));
                $html .= '<div class="status ' . ($person['is_active'] ? 'status-approved' : 'status-draft') . '" style="text-transform:none;">' . safeHtml($roleLabel) . ': ' . safeHtml($person['full_name']) . ($person['is_active'] ? '' : ' (inactive)') . '</div>';
            }
            $html .= '</div>';
        } else {
            $html .= '<p style="font-size:12px; color:var(--ink-300); font-style:italic; margin-bottom:12px;">No one assigned yet — Headquarters (or the parent department) covers anything left blank.</p>';
        }
        $html .= '<div style="display:flex; flex-direction:column; gap:8px;">';
        foreach ($quickAddSlots as $roleKey => $slot) {
            $html .= '<form method="post" style="background:var(--panel); border:1px solid var(--line); padding:10px 12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">';
            $html .= '<input type="hidden" name="csrf_token" value="' . safeHtml($csrfToken) . '">';
            $html .= '<input type="hidden" name="action" value="quick_add_staff">';
            $html .= '<input type="hidden" name="department_id" value="' . $deptId . '">';
            $html .= '<input type="hidden" name="staff_role" value="' . safeHtml($roleKey) . '">';
            $html .= '<span style="font-size:11px; font-weight:700; text-transform:uppercase; min-width:76px;">+ ' . safeHtml($slot['label']) . '</span>';
            $html .= '<input type="text" name="staff_name" placeholder="Full name" required style="flex:1; min-width:100px; height:30px; padding:0 8px; border:1px solid var(--line);">';
            $html .= '<input type="email" name="staff_email" placeholder="Email" required style="flex:1; min-width:120px; height:30px; padding:0 8px; border:1px solid var(--line);">';
            $html .= '<button type="submit" class="btn-mini outline">Add</button>';
            $html .= '</form>';
        }
        $html .= '</div></div>';

        $html .= '</div></td></tr>';
    }

    foreach ($node['children'] as $child) {
        $html .= renderDeptRow($child, $isTopRole, $staffByDepartment, $quickAddSlots, $newDepartmentId, $csrfToken, $depth + 1);
    }
    return $html;
}

/**
 * One box + its children in the org-chart hierarchy diagram.
 * UPDATED: now shows the actual people in the department (role +
 * name, from the same $staffByDepartment the Allocations table
 * already uses — no new query), not just the budget ceiling. This
 * is what makes it a real organogram instead of a budget tree that
 * happens to be shaped like one.
 */
function renderHierarchyNode(array $node, array $staffByDepartment, int $currentUserId, bool $isRoot = false): string {
    $deptId = (int)$node['id'];
    $classes = 'org-node';
    if ($isRoot) $classes .= ' is-root';
    if ($node['status'] !== 'active') $classes .= ' is-inactive';
    if ($node['is_over_ration']) $classes .= ' is-over';

    $html = '<li><div class="' . $classes . '">';
    $html .= '<span class="org-name">' . safeHtml($node['name']) . '</span>';
    if ($node['has_ceiling']) {
        $html .= '<span class="org-meta">' . formatCurrency($node['budget_ceiling']) . ' &middot; ' . $node['utilization_percent'] . '% used</span>';
    } else {
        $html .= '<span class="org-meta">No vote</span>';
    }
    if ($node['status'] !== 'active') {
        $html .= '<span class="org-meta" style="color:var(--seal-red);">Inactive</span>';
    }
    $people = $staffByDepartment[$deptId] ?? [];
    if (!empty($people)) {
        $html .= '<div class="org-people">';
        foreach ($people as $person) {
            $roleAbbrev = match($person['role']) {
                'department_head' => 'HEAD', 'program_officer' => 'UPL', 'approver' => 'APR', 'owner' => 'DIS',
                default => strtoupper(substr($person['role'], 0, 3)),
            };
            // FIX: this is the actual answer to "how does the person
            // currently logged in fit into this chart" — a real
            // comparison against organization_users.user_id, not a
            // guess based on name matching.
            $isYou = isset($person['user_id']) && (int)$person['user_id'] === $currentUserId;
            $html .= '<span class="org-person' . ($person['is_active'] ? '' : ' is-inactive') . ($isYou ? ' is-you' : '') . '"><b>' . $roleAbbrev . '</b> ' . safeHtml($person['full_name']) . '</span>';
        }
        $html .= '</div>';
    } else {
        $html .= '<div class="org-people org-people-empty">No staff assigned</div>';
    }
    $html .= '</div>';
    if (!empty($node['children'])) {
        $html .= '<ul>';
        foreach ($node['children'] as $child) {
            $html .= renderHierarchyNode($child, $staffByDepartment, $currentUserId, false);
        }
        $html .= '</ul>';
    }
    $html .= '</li>';
    return $html;
}

// ============================================================
// SHARED SHELL SETUP — now the real $navItems contract, all flags
// sourced from partials/permissions.php instead of a second
// hardcoded role list.
// ============================================================
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
    error_log("[departments] Nav badge query error: " . $e->getMessage());
}

$navItems = [
    ['key' => 'hub', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => '../index.php', 'show' => true],
    ['key' => 'attention', 'icon' => 'bell', 'label' => 'Attention', 'href' => '../index.php#stage-attention', 'show' => $canViewAttentionTile, 'badge' => ($navPendingApprovals > 0 && $canApprove) ? $navPendingApprovals : null],
    ['key' => 'batches', 'icon' => 'layers', 'label' => 'Batches', 'href' => '../index.php#stage-batches', 'show' => $canViewBatchesTile],
    ['key' => 'activity', 'icon' => 'history', 'label' => 'Activity', 'href' => '../index.php#stage-activity', 'show' => $canViewActivityTile],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace', 'href' => '../index.php#stage-trace', 'show' => $canTrace],
    ['key' => 'reports', 'icon' => 'file', 'label' => 'Reports', 'href' => '../index.php#stage-reports', 'show' => $canViewReports],
    ['key' => 'departments', 'icon' => 'building', 'label' => 'Departments', 'href' => 'index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'beneficiaries', 'icon' => 'users', 'label' => 'Beneficiaries', 'href' => '../beneficiaries.php', 'show' => $canViewBeneficiariesTile],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => '../imports/add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => $navPendingSourceConfirmations > 0 ? $navPendingSourceConfirmations : null],
    ['key' => 'team', 'icon' => 'shield', 'label' => 'Team', 'href' => '../settings/users.php', 'show' => $canManageUsers],
];
$currentNavKey = 'departments';
$attentionHref = '#approvals';
$attentionActive = !empty($unifiedApprovals);
// Popout content for THIS page's own approvals, grouped by type —
// more useful here than repeating index.php's dashboard-wide inbox.
$notificationItems = [];
$structureCount = count(array_filter($unifiedApprovals, fn($r) => $r['tag'] === 'STRUCTURE'));
$ratioCount = count(array_filter($unifiedApprovals, fn($r) => $r['tag'] === 'RATION'));
if ($structureCount > 0) $notificationItems[] = ['label' => 'Sub-department requests', 'count' => $structureCount];
if ($ratioCount > 0) $notificationItems[] = ['label' => 'Ration borrow requests', 'count' => $ratioCount];

// The old topbar search box doesn't exist in the current header — this
// page's real name/code filter still works, just moved into the stage
// itself as its own small search form instead of living in the shared
// chrome.
$searchQuery = trim($_GET['q'] ?? '');
$searchHits = [];
if ($searchQuery !== '') {
    $needle = mb_strtolower($searchQuery);
    $flattenAll = function (array $nodes) use (&$flattenAll) {
        $out = [];
        foreach ($nodes as $n) {
            $out[] = $n;
            if (!empty($n['children'])) { $out = array_merge($out, $flattenAll($n['children'])); }
        }
        return $out;
    };
    foreach ($flattenAll($tree) as $n) {
        if (str_contains(mb_strtolower($n['name']), $needle) || ($n['code'] && str_contains(mb_strtolower($n['code']), $needle))) {
            $searchHits[] = $n;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Departments · <?php echo safeHtml($orgName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../partials/shell.css">
    <style>
        /* ============================================================
           COMPATIBILITY BRIDGE — see NOTE 1 in the PHP header comment.
           Aliases old token names to current ones (skin-aware for
           free), and gives a handful of old class names with no
           current equivalent a local definition instead of touching
           every occurrence in the HTML/render functions below.
           ============================================================ */
        :root {
            --ink-900: var(--ink); --ink-700: var(--ink); --ink-500: var(--ink); --ink-300: var(--ink);
            --line-strong: var(--ink); --panel: var(--paper);
            --brass: var(--sky); --brass-tint: var(--sky-tint); --amber: var(--sky); --ledger-green: var(--sky);
            --green-tint: var(--sky-tint); --seal-red: var(--danger); --danger-bg: var(--danger-tint);
            --f-cond: var(--f-display);
        }
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: var(--u2); margin-bottom: var(--u4); padding-bottom: var(--u3); border-bottom: var(--border) solid var(--ink); }
        .page-header h1 { font-family: var(--f-display); font-size: 26px; font-weight: 700; text-transform: uppercase; letter-spacing: .01em; }
        .page-header .sub { font-size: 12.5px; opacity: .65; margin-top: 4px; }
        .page-header-actions { display: flex; gap: var(--u2); flex-wrap: wrap; }
        .card-header { display: flex; justify-content: space-between; align-items: center; gap: var(--u2); flex-wrap: wrap; margin-bottom: var(--u3); padding-bottom: var(--u2); border-bottom: var(--border) solid var(--ink); }
        .card-title { font-family: var(--f-display); font-weight: 700; font-size: 15px; text-transform: uppercase; letter-spacing: .03em; display: flex; align-items: center; gap: var(--u1); }
        .card-badge { font-family: var(--f-mono); font-size: 10px; font-weight: 700; background: var(--ink); color: var(--sky); padding: 2px var(--u2); }
        .info-panel { border: var(--border) solid var(--ink); border-left-width: var(--u1); padding: var(--u2) var(--u3); margin-bottom: var(--u3); background: var(--paper); }
        .info-panel .desc { font-size: 13px; }
        .panel-grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: var(--u3); align-items: start; }
        @media (max-width: 900px) { .panel-grid { grid-template-columns: 1fr; } }
        .panel { border: var(--border) solid var(--ink); background: var(--paper); }
        .panel-head { display: flex; justify-content: space-between; align-items: center; padding: var(--u2) var(--u3); border-bottom: var(--border) solid var(--ink); }
        .panel-head .title { font-family: var(--f-display); font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; display: flex; align-items: center; gap: var(--u1); }
        .panel-body { padding: 0; }
        .empty-row { text-align: center; padding: var(--u5) var(--u3); font-family: var(--f-mono); font-size: 12.5px; opacity: .55; }
        .task-row { display: flex; gap: var(--u2); padding: var(--u2) var(--u3); border-bottom: var(--border) solid var(--ink); }
        .task-row:last-child { border-bottom: none; }
        .task-row.locked { opacity: .6; }
        .task-row .dot { width: 10px; height: 10px; border: var(--border) solid var(--ink); flex-shrink: 0; margin-top: 4px; }
        .task-row .dot.amber { background: var(--sky); }
        .task-row .dot.muted { background: var(--paper-dim); }
        .task-row .body { flex: 1; min-width: 0; }
        .task-row .top-line { display: flex; justify-content: space-between; gap: var(--u2); }
        .task-row .tag { font-family: var(--f-mono); font-size: 9.5px; font-weight: 700; text-transform: uppercase; border: var(--border) solid var(--ink); padding: 1px 6px; }
        .task-row .when { font-family: var(--f-mono); font-size: 10.5px; opacity: .5; white-space: nowrap; }
        .task-row .label { font-weight: 700; font-size: 13px; display: block; margin: 4px 0 2px; }
        .task-row .meta { font-size: 11.5px; opacity: .65; }
        .task-row .cta { display: flex; gap: var(--u1); margin-top: var(--u2); }
        .critical-pill { font-family: var(--f-mono); font-size: 10px; font-weight: 700; background: var(--ink); color: var(--sky); padding: 2px var(--u2); }
        .btn-mini { display: inline-flex; align-items: center; height: 26px; padding: 0 10px; border: var(--border) solid var(--ink); background: var(--paper); color: var(--ink); font-family: var(--f-display); font-weight: 700; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; cursor: pointer; }
        .btn-mini:hover { background: var(--ink); color: var(--paper); }
        .btn-mini.outline { background: var(--paper); }
        .btn-mini.danger { background: var(--danger); color: var(--paper); }
        .btn-mini.locked-btn { opacity: .5; cursor: not-allowed; display: inline-flex; align-items: center; gap: 4px; }
        .btn-outline { background: var(--paper); color: var(--ink); border: var(--border) solid var(--ink); }
        .btn-outline:hover { background: var(--ink); color: var(--paper); }
        .btn-warning { background: var(--sky); color: var(--ink); border: var(--border) solid var(--ink); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--u2); margin-bottom: var(--u2); }
        .form-group label { display: block; font-family: var(--f-mono); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; opacity: .7; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; height: var(--u5); padding: 0 var(--u2); border: var(--border) solid var(--ink); background: var(--paper); color: var(--ink); font-family: var(--f-body); font-size: 13px; }
        .form-group textarea { height: auto; padding: var(--u1) var(--u2); }
        .hint { font-size: 11px; opacity: .55; margin-top: 4px; }
        details.disclosure { margin-top: var(--u3); padding-top: var(--u3); border-top: var(--border) dashed var(--ink); }
        details.disclosure summary { cursor: pointer; font-weight: 700; font-size: 13px; list-style: none; font-family: var(--f-display); text-transform: uppercase; letter-spacing: .02em; }
        details.disclosure summary::-webkit-details-marker { display: none; }
        details.disclosure[open] summary { margin-bottom: var(--u3); }
        .table-responsive { overflow-x: auto; }
        .manage-row { display: none; }
        .manage-row.open-row { display: table-row; }
        .alloc-table th, .alloc-table td { vertical-align: top; }
        .accent-brass .stat-value, .stat-value.brass { color: var(--sky-deep); }
        .accent-danger { border-left: var(--u1) solid var(--danger); }

        /* ============================================================
           ORG-CHART HIERARCHY DIAGRAM — pure CSS, unchanged logic,
           token names swapped to the current system.
           ============================================================ */
        .org-chart-wrap { overflow-x: auto; padding: 24px 12px 12px; }
        .org-chart, .org-chart ul { list-style: none; margin: 0; padding: 0; display: flex; justify-content: center; }
        .org-chart { gap: 48px; min-width: min-content; }
        .org-chart ul { padding-top: 24px; position: relative; }
        .org-chart li { display: flex; flex-direction: column; align-items: center; padding: 24px 10px 0; position: relative; }
        .org-chart li::before, .org-chart li::after {
            content: ''; position: absolute; top: 0; right: 50%;
            border-top: 2px solid var(--ink); width: 50%; height: 24px;
        }
        .org-chart li::after { right: auto; left: 50%; border-left: 2px solid var(--ink); }
        .org-chart li:only-child { padding-top: 0; }
        .org-chart li:only-child::after, .org-chart li:only-child::before { display: none; }
        .org-chart li:first-child::before, .org-chart li:last-child::after { border: 0 none; }
        .org-chart li:last-child::before { border-right: 2px solid var(--ink); }
        .org-chart ul ul::before {
            content: ''; position: absolute; top: 0; left: 50%;
            border-left: 2px solid var(--ink); width: 0; height: 24px;
        }
        .org-node {
            display: inline-flex; flex-direction: column; align-items: center; gap: 3px;
            border: var(--border) solid var(--ink); background: var(--paper);
            padding: 10px 16px; min-width: 140px; white-space: nowrap;
        }
        .org-node .org-name { font-family: var(--f-display); font-weight: 700; font-size: 12.5px; }
        .org-node .org-meta { font-family: var(--f-mono); font-size: 10.5px; opacity: .65; }
        .org-node.is-root { background: var(--ink); border-color: var(--ink); }
        .org-node.is-root .org-name { color: var(--paper); }
        .org-node.is-root .org-meta { color: var(--paper); opacity: .75; }
        .org-node.is-inactive { opacity: 0.55; border-style: dashed; }
        .org-node.is-over { border-color: var(--danger); }
        .org-node.is-hq { background: var(--sky); border-color: var(--ink); }
        .org-node.is-hq .org-name, .org-node.is-hq .org-meta { color: var(--ink); }
        .org-people { margin-top: 6px; padding-top: 6px; border-top: 1px dashed var(--ink); display: flex; flex-direction: column; gap: 2px; align-items: flex-start; }
        .org-person { font-family: var(--f-mono); font-size: 10px; white-space: normal; text-align: left; }
        .org-person b { font-weight: 700; margin-right: 3px; }
        .org-person.is-inactive { opacity: 0.5; text-decoration: line-through; }
        .org-people-empty { margin-top: 6px; padding-top: 6px; border-top: 1px dashed var(--ink); font-size: 10px; font-style: italic; opacity: 0.55; }
        .org-person.is-you { background: var(--sky); color: var(--ink); padding: 1px 5px; margin: -1px -5px; font-weight: 700; }
        .org-person.is-you::after { content: ' — YOU'; font-weight: 700; }

        /* ============================================================
           STANDALONE SECTIONS — this page used to be one long scroll:
           search, flashes, department-head card, stats, the org chart,
           the allocations table, pending approvals, and every create/
           adjust form, all stacked on top of each other. The org chart
           in particular was "in the middle of other things" instead of
           standing on its own. Same center-stage principle as the rest
           of the dashboard, applied locally to this one page: one
           section visible at a time, tabs to move between them.
           ============================================================ */
        .dept-tabs { display: flex; border: var(--border) solid var(--ink); margin-bottom: var(--u4); }
        .dept-tabs button { flex: 1; height: var(--u6); background: var(--paper); border: none; border-right: var(--border) solid var(--ink); font-family: var(--f-display); font-weight: 700; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em; cursor: pointer; color: var(--ink); }
        .dept-tabs button:last-child { border-right: none; }
        .dept-tabs button.active { background: var(--sky); }
        .dept-tabs button:hover:not(.active) { background: var(--paper-dim); }
        .dept-tab { display: none; }
        .dept-tab.active { display: block; animation: stageIn 0.18s ease; }

        .dept-search-form { margin-bottom: var(--u4); }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../partials/shell-head.php'; ?>
    <div class="stage-view active">
            <div class="page-header">
                <div>
                    <h1>Departments Overview</h1>
                    <div class="sub">Manage hierarchical structures, operational budgets, and departmental approvals.</div>
                </div>
                <div class="page-header-actions">
                    <button type="button" class="btn btn-secondary" disabled title="Ledger export isn't built yet"><?php echo svgIcon('download'); ?> Export Ledger</button>
                    <?php if ($isTopRole): ?>
                    <button type="button" class="btn btn-primary" onclick="showDeptTab('create')"><?php echo svgIcon('plus'); ?> New Department</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($newStaffCredentials): ?>
            <div class="card" style="border-left:var(--u1) solid var(--sky);">
                <div style="font-family:var(--f-display); font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">&#9888; One-time display — copy this now, it will not be shown again</div>
                <div style="display:flex; gap:20px; flex-wrap:wrap; font-size:13.5px;">
                    <span><strong>Name:</strong> <?php echo safeHtml($newStaffCredentials['name']); ?></span>
                    <span><strong>Email:</strong> <?php echo safeHtml($newStaffCredentials['email']); ?></span>
                    <span><strong>Temp password:</strong> <code><?php echo safeHtml($newStaffCredentials['temp_password']); ?></code></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($flashMessage): ?>
            <div class="info-panel" style="<?php echo $flashType === 'error' ? 'border-left-color:var(--danger); background:var(--danger-tint);' : 'border-left-color:var(--sky); background:var(--sky-tint);'; ?>">
                <div class="desc"><?php echo safeHtml($flashMessage); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($isTopRole): ?>
            <!-- ============================================================
                 Real tabs, not an accident of scrolling. Each section
                 below now stands alone — pressed, it takes the full
                 width of the page; nothing else competes with it.
                 ============================================================ -->
            <div class="dept-tabs" id="deptTabs">
                <button type="button" class="active" onclick="showDeptTab('overview',this)">Overview</button>
                <button type="button" onclick="showDeptTab('chart',this)">Organization Chart</button>
                <button type="button" onclick="showDeptTab('approvals',this)">Allocations &amp; Approvals<?php echo !empty($unifiedApprovals) ? ' (' . count($unifiedApprovals) . ')' : ''; ?></button>
                <button type="button" onclick="showDeptTab('create',this)">Create &amp; Adjust</button>
            </div>
            <?php endif; ?>

            <div class="dept-tab<?php echo $isTopRole ? ' active' : ''; ?>" id="dept-tab-overview">
            <form method="get" class="dept-search-form field" style="display:flex;gap:var(--u2);align-items:flex-end;">
                <div style="flex:1;"><input type="text" name="q" placeholder="Search departments, codes&hellip;" value="<?php echo safeHtml($searchQuery); ?>"></div>
                <button type="submit" class="btn btn-secondary btn-sm"><?php echo svgIcon('search'); ?></button>
            </form>

            <?php if ($searchQuery !== ''): ?>
            <div class="card">
                <div class="card-header"><span class="card-title">Search results for "<?php echo safeHtml($searchQuery); ?>"</span><span class="card-badge"><?php echo count($searchHits); ?> FOUND</span></div>
                <?php if (empty($searchHits)): ?>
                <div class="empty-row">No departments match.</div>
                <?php else: foreach ($searchHits as $hit): ?>
                <div class="task-row"><div class="body"><span class="label"><?php echo safeHtml($hit['name']); ?></span><div class="meta"><?php echo $hit['has_ceiling'] ? formatCurrency($hit['budget_ceiling']) . ' ceiling' : 'No vote'; ?> — <?php echo $hit['utilization_percent']; ?>% utilized</div></div></div>
                <?php endforeach; endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($isDepartmentHead && $myDepartment): ?>
            <!-- DEPARTMENT HEAD VIEW -->
            <div class="card">
                <div class="card-header"><span class="card-title"><?php echo svgIcon('building'); ?> <?php echo safeHtml($myDepartment['name']); ?></span><span class="card-badge">MY DEPARTMENT</span></div>
                <div class="stat-grid" style="margin-bottom:16px;">
                    <div class="stat-card"><div class="stat-label">Ceiling</div><div class="stat-value"><?php echo formatCurrency($myDepartmentRation['ceiling'], $myDepartmentRation['currency']); ?></div></div>
                    <div class="stat-card"><div class="stat-label">Disbursed YTD</div><div class="stat-value"><?php echo formatCurrency($myDepartmentRation['disbursed_ytd'], $myDepartmentRation['currency']); ?></div></div>
                    <div class="stat-card<?php echo $myDepartmentRation['available'] < 0 ? ' accent-danger' : ''; ?>"><div class="stat-label">Available</div><div class="stat-value"><?php echo formatCurrency($myDepartmentRation['available'], $myDepartmentRation['currency']); ?></div></div>
                </div>

                <details class="disclosure">
                    <summary>+ Request a sub-department</summary>
                    <form method="post" class="form-grid" style="margin-top:10px;">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="request_sub_department">
                        <input type="hidden" name="parent_id" value="<?php echo (int)$myDepartment['id']; ?>">
                        <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
                        <div class="form-group"><label>Code (optional)</label><input type="text" name="code"></div>
                        <div class="form-group"><label>Requested Ceiling (optional)</label><input type="number" step="0.01" name="requested_ceiling"></div>
                        <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Submit Request</button></div>
                    </form>
                    <p class="hint">Goes to a top-level admin (Owner / IT Manager) for approval before it becomes an active sub-department.</p>
                </details>

                <details class="disclosure">
                    <summary>+ Request to borrow ration from another department</summary>
                    <form method="post" class="form-grid" style="margin-top:10px;">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="request_borrow">
                        <input type="hidden" name="borrowing_department_id" value="<?php echo (int)$myDepartment['id']; ?>">
                        <div class="form-group">
                            <label>Borrow From</label>
                            <select name="lending_department_id" required>
                                <option value="">Select department&hellip;</option>
                                <?php foreach ($flatDepartments as $d): if ((int)$d['id'] === (int)$myDepartment['id']) continue; ?>
                                <option value="<?php echo (int)$d['id']; ?>"><?php echo safeHtml($d['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Amount</label><input type="number" step="0.01" name="amount" required></div>
                        <div class="form-group" style="grid-column:1/-1;"><label>Reason</label><textarea name="reason" required></textarea></div>
                        <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-warning">Submit Borrow Request</button></div>
                    </form>
                    <p class="hint">Only Owner, IT Manager, or Finance Officer can approve this — the lending department is not asked to agree.</p>
                </details>
            </div>
            <?php endif; ?>

            <?php if ($isTopRole): ?>
            <div class="stat-grid">
                <div class="stat-card"><div class="stat-label">Total Global Budget</div><div class="stat-value"><?php echo formatCurrency($totalGlobalBudget); ?></div><div class="stat-sub">Sum of every root department's ceiling</div></div>
                <div class="stat-card"><div class="stat-label">Total Allocated</div><div class="stat-value"><?php echo formatCurrency($totalAllocated); ?></div><div class="stat-sub">Carved out to (sub-)departments</div></div>
                <div class="stat-card"><div class="stat-label">Unallocated Reserve</div><div class="stat-value" style="color:var(--sky-deep);"><?php echo formatCurrency($unallocatedReserve); ?></div><div class="stat-sub">Still available to allocate</div></div>
            </div>
            <?php endif; ?>
            </div><!-- /dept-tab-overview -->

            <?php if ($isTopRole): ?>
            <!-- ============================================================
                 ORGANIZATION CHART — stands alone now, full width, its
                 own tab. Shows Headquarters (staff with no department)
                 as its own box alongside the department tree, and every
                 department box now lists the actual people in it
                 (renderHierarchyNode, updated above) — a real "who is
                 where," not just a budget hierarchy shaped like one.
                 ============================================================ -->
            <div class="dept-tab" id="dept-tab-chart">
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><?php echo svgIcon('building'); ?> Organizational Hierarchy</span>
                    <span style="font-size:11.5px; opacity:.6;">Top-down reporting structure &middot; ceiling, utilization, and staff per box</span>
                </div>
                <?php if (empty($tree) && empty($hqStaff)): ?>
                <div class="empty-row">No departments yet — create the first one in Create &amp; Adjust.</div>
                <?php else: ?>
                <div class="org-chart-wrap">
                    <ul class="org-chart">
                        <li><div class="org-node is-root is-hq">
                            <span class="org-name">Headquarters</span>
                            <span class="org-meta"><?php echo count($hqStaff); ?> staff, unassigned to any department</span>
                            <?php if (!empty($hqStaff)): ?>
                            <div class="org-people">
                                <?php foreach ($hqStaff as $person):
                                    $roleAbbrev = match($person['role']) {
                                        'department_head' => 'HEAD', 'program_officer' => 'UPL', 'approver' => 'APR', 'owner' => 'DIS',
                                        default => strtoupper(substr($person['role'], 0, 3)),
                                    };
                                    $isYou = isset($person['user_id']) && (int)$person['user_id'] === (int)$userId;
                                ?>
                                <span class="org-person<?php echo $person['is_active'] ? '' : ' is-inactive'; ?><?php echo $isYou ? ' is-you' : ''; ?>"><b><?php echo $roleAbbrev; ?></b> <?php echo safeHtml($person['full_name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="org-people-empty">No unassigned staff</div>
                            <?php endif; ?>
                        </div></li>
                        <?php foreach ($tree as $rootNode) { echo renderHierarchyNode($rootNode, $staffByDepartment, (int)$userId, true); } ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            </div><!-- /dept-tab-chart -->

            <div class="dept-tab" id="dept-tab-approvals">
            <div class="panel-grid" id="approvals">
                <div class="panel">
                    <div class="panel-head">
                        <span class="title"><?php echo svgIcon('building'); ?> Departmental Allocations</span>
                        <span class="card-badge"><?php echo count($tree); ?> TOP-LEVEL</span>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($tree)): ?>
                        <div class="empty-row">No departments yet — create the first one below.</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="alloc-table">
                                <thead><tr><th>Department</th><th>Budget Ceiling</th><th>Current Spend</th><th>Utilization</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($tree as $node) { echo renderDeptRow($node, $isTopRole, $staffByDepartment, $quickAddSlots, $newDepartmentId, $csrfToken); } ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <span class="title"><?php echo svgIcon('warning'); ?> Pending Approvals</span>
                        <?php if (!empty($unifiedApprovals)): ?><span class="critical-pill"><?php echo count($unifiedApprovals); ?> REQ</span><?php endif; ?>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($unifiedApprovals)): ?>
                        <div class="empty-row">No pending requests.</div>
                        <?php else: foreach ($unifiedApprovals as $req): ?>
                        <div class="task-row<?php echo $req['can_decide'] ? '' : ' locked'; ?>">
                            <span class="dot <?php echo $req['can_decide'] ? 'amber' : 'muted'; ?>"></span>
                            <div class="body">
                                <div class="top-line">
                                    <span class="tag"><?php echo $req['tag']; ?></span>
                                    <span class="when"><?php echo date('M j, H:i', strtotime($req['created_at'])); ?></span>
                                </div>
                                <span class="label"><?php echo safeHtml($req['title']); ?></span>
                                <div class="meta"><?php echo safeHtml($req['meta']); ?></div>
                                <?php if ($req['can_decide']): ?>
                                <div class="cta">
                                    <?php if ($req['type'] === 'sub_department'): ?>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>"><input type="hidden" name="action" value="decide_sub_department_request"><input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>"><input type="hidden" name="decision" value="approve"><button type="submit" class="btn-mini">Approve</button></form>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>"><input type="hidden" name="action" value="decide_sub_department_request"><input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>"><input type="hidden" name="decision" value="reject"><button type="submit" class="btn-mini danger">Reject</button></form>
                                    <?php else: ?>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>"><input type="hidden" name="action" value="approve_borrow"><input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>"><button type="submit" class="btn-mini">Approve</button></form>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>"><input type="hidden" name="action" value="reject_borrow"><input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>"><button type="submit" class="btn-mini danger">Reject</button></form>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="cta"><button type="button" class="btn-mini locked-btn" disabled><?php echo svgIcon('lock'); ?> Locked</button></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
            </div><!-- /dept-tab-approvals -->

            <div class="dept-tab" id="dept-tab-create">
            <div class="card" id="dept-create">
                <div class="card-header"><span class="card-title"><?php echo svgIcon('plus'); ?> Create &amp; Adjust</span></div>

                <details class="disclosure" open>
                    <summary>Create top-level department</summary>
                    <form method="post" class="form-grid" style="margin-top:10px;">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_department">
                        <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="e.g. Huíla Province"></div>
                        <div class="form-group"><label>Code <span style="text-transform:none;">(optional)</span></label><input type="text" name="code" placeholder="e.g. HUI"></div>
                        <div class="form-group"><label>Cost Center <span style="text-transform:none;">(optional)</span></label><input type="text" name="cost_center"></div>
                        <div class="form-group"><label>Budget Ceiling <span style="text-transform:none;">(optional)</span></label><input type="number" step="0.01" name="budget_ceiling" placeholder="Leave blank for no vote"></div>
                        <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Create</button></div>
                    </form>
                </details>

                <details class="disclosure">
                    <summary>Create sub-department directly</summary>
                    <form method="post" class="form-grid" style="margin-top:10px;">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_sub_department">
                        <div class="form-group"><label>Parent Department</label><select name="parent_id" required><option value="">Select&hellip;</option><?php foreach ($flatDepartments as $d): ?><option value="<?php echo (int)$d['id']; ?>"><?php echo safeHtml($d['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
                        <div class="form-group"><label>Code</label><input type="text" name="code"></div>
                        <div class="form-group"><label>Budget Ceiling <span style="text-transform:none;">(optional)</span></label><input type="number" step="0.01" name="budget_ceiling" placeholder="Leave blank for no vote"></div>
                        <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Create Sub-department</button></div>
                    </form>
                    <p class="hint">Must fit within the parent's remaining (unallocated) ceiling.</p>
                </details>
            </div>
            </div><!-- /dept-tab-create -->
            <?php endif; ?>
    </div>
    <?php
    $dbHealthy = DBConnection::isConnected();
    // NOTE: the old footer supported colored <span class="ok">/<span
    // class="bad"> inline HTML; the current shell-foot.php escapes
    // $footerNote as plain text, so the color is lost here — a small,
    // acceptable simplification, flagged rather than silently dropped.
    $footerNote = 'Ledger sync: ' . ($dbHealthy ? 'OK' : 'DEGRADED');
    require __DIR__ . '/../partials/shell-foot.php';
    ?>
    <script>
    // ------------------------------------------------------------
    // Tab switcher, page-local — separate from index.php's goStage()
    // since this page's sections are NOT sidebar destinations, just
    // sub-views of this one page.
    // ------------------------------------------------------------
    function showDeptTab(name, btn) {
        document.querySelectorAll('.dept-tab').forEach(function (t) { t.classList.remove('active'); });
        var target = document.getElementById('dept-tab-' + name);
        if (target) target.classList.add('active');
        document.querySelectorAll('#deptTabs button').forEach(function (b) { b.classList.remove('active'); });
        if (btn) { btn.classList.add('active'); }
        else {
            var idx = ['overview', 'chart', 'approvals', 'create'].indexOf(name);
            var btns = document.querySelectorAll('#deptTabs button');
            if (idx >= 0 && btns[idx]) btns[idx].classList.add('active');
        }
        window.scrollTo(0, 0);
    }
    // FIX applied proactively: the header bell's popout links to
    // "#approvals", and — same bug class as the sidebar hashchange
    // issue on the main dashboard — a plain href to a fragment inside
    // a now-hidden tab does nothing on its own. Route the hash the
    // same way, including on same-document navigation.
    function routeDeptHash() {
        if (location.hash === '#approvals' && document.getElementById('dept-tab-approvals')) {
            showDeptTab('approvals');
        }
    }
    routeDeptHash();
    window.addEventListener('hashchange', routeDeptHash);
    </script>
</body>
</html>
