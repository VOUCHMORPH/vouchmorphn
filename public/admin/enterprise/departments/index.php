<?php
/**
 * enterprise/departments/index.php - Department & Ration Dashboard
 *
 * Visible to: top roles (owner, it_manager_enterprise) — full control.
 * department_head — sees their own department, can request sub-departments
 * and ration borrows. Everyone else (batch staff, finance_officer, viewers)
 * is redirected: this page is not for them.
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

$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$isDepartmentHead = ($userRole === 'department_head');
$canApproveBorrow = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer'], true);

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
// HANDLE FORM SUBMISSIONS (POST) — unchanged from before this redesign;
// only the HTML below this block was rebuilt.
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
        SELECT department_id, full_name, role, is_active
        FROM organization_users
        WHERE organization_id = :org_id AND department_id IS NOT NULL
        ORDER BY role ASC, full_name ASC
    ");
    $stmt->execute([':org_id' => $orgId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $staffByDepartment[(int)$row['department_id']][] = $row;
    }

    $stmt = $pdo->prepare("
        SELECT full_name, role, is_active
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
// NEW, REAL AGGREGATES for the redesign's stat cards — computed from
// the same $tree the rest of this page already trusts, not fabricated.
// "Global Budget" = every root department's own ceiling (the "Main
// Central Government Account" model the sub-department budget
// guardrails are built on — see DepartmentService::assertCeilingFitsUnderParent).
// "Allocated" = every NON-root department's ceiling — i.e. how much of
// that root capacity has actually been carved out to (sub-)departments.
// "Unallocated Reserve" is just the difference, which is exactly what
// assertCeilingFitsUnderParent's "room left" figure means, aggregated
// org-wide instead of per-parent.
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

/** First department_head-role staffer on record for a department, or null. Real lookup, not a placeholder. */
function resolveDepartmentHead(int $deptId, array $staffByDepartment): ?string {
    foreach ($staffByDepartment[$deptId] ?? [] as $person) {
        if ($person['role'] === 'department_head' && $person['is_active']) {
            return $person['full_name'];
        }
    }
    return null;
}

// Unified Pending Approvals feed — sub-department requests (top-role
// decision) and ration borrow requests (top-role/finance decision)
// merged into one list, newest first. A request type this viewer can't
// personally decide still shows (for visibility) but locked, rather
// than being silently omitted.
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
 * an expandable "Manage" panel underneath carrying the exact same real
 * forms the pre-redesign page had (edit name/code/cost-center, activate/
 * deactivate, staffing quick-add) — restyled, not reduced.
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

        // Edit + status form
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

        // Staffing
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

// ============================================================
// SHARED SHELL SETUP — same contract as index.php, so this page's nav
// is generated by the exact same code, not a hand-copied lookalike.
// ============================================================
$basePath = '../';
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head'], true);
$setupReady = true; // this page is unreachable pre-setup (owner would still be on the wizard)
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise'], true);
$canDisburse = ($userRole === 'owner');
$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true);
$canSeeSourceAccountsArea = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer'], true);
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer'], true);

// Same live badge numbers the dashboard shows, so a count on "Disbursements"
// or "Source Accounts" never disagrees depending on which page you're on.
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
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => '../index.php', 'show' => true],
    ['key' => 'disbursements', 'icon' => 'wallet', 'label' => 'Disbursements', 'href' => '../batches/index.php?status=all', 'show' => true, 'badge' => ($navPendingApprovals > 0 && $canApprove) ? $navPendingApprovals : null],
    ['key' => 'beneficiaries', 'icon' => 'people', 'label' => 'Beneficiaries', 'href' => '../beneficiaries.php', 'show' => true],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace Payment', 'href' => '../index.php#trace', 'show' => $canTrace],
    ['key' => 'departments', 'icon' => 'building', 'label' => 'Departments', 'href' => 'index.php', 'show' => true, 'active' => true],
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
$topbarSearchName = 'q';
$topbarSearchPlaceholder = 'Search departments, budgets…';
$topbarSearchValue = trim($_GET['q'] ?? '');
$attentionHref = '#approvals';
$attentionActive = !empty($unifiedApprovals);

// If the topbar search box was used, do a simple real filter across
// department names/codes — no separate search backend, just the tree
// we already loaded.
$searchHits = [];
if ($topbarSearchValue !== '') {
    $needle = mb_strtolower($topbarSearchValue);
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
        .manage-row { display: none; }
        .manage-row.open-row { display: table-row; }
        .alloc-table th, .alloc-table td { vertical-align: top; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../partials/shell-head.php'; ?>
            <div class="page-header">
                <div>
                    <h1>Departments Overview</h1>
                    <div class="sub">Manage hierarchical structures, operational budgets, and departmental approvals.</div>
                </div>
                <div class="page-header-actions">
                    <button type="button" class="btn btn-outline" disabled title="Ledger export isn't built yet"><?php echo svgIcon('download'); ?> Export Ledger</button>
                    <?php if ($isTopRole): ?>
                    <a href="#dept-create" class="btn btn-primary"><?php echo svgIcon('plus'); ?> New Department</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($newStaffCredentials): ?>
            <div class="card" style="border-left:4px solid var(--brass); background:var(--brass-tint);">
                <div style="font-family:var(--f-cond); font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--amber); margin-bottom:8px;">⚠ One-time display — copy this now, it will not be shown again</div>
                <div style="display:flex; gap:20px; flex-wrap:wrap; font-size:13.5px;">
                    <span><strong>Name:</strong> <?php echo safeHtml($newStaffCredentials['name']); ?></span>
                    <span><strong>Email:</strong> <?php echo safeHtml($newStaffCredentials['email']); ?></span>
                    <span><strong>Temp password:</strong> <code><?php echo safeHtml($newStaffCredentials['temp_password']); ?></code></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($flashMessage): ?>
            <div class="info-panel" style="<?php echo $flashType === 'error' ? 'border-left-color:var(--seal-red); background:var(--danger-bg);' : 'border-left-color:var(--ledger-green); background:var(--green-tint);'; ?>">
                <div class="desc"><?php echo safeHtml($flashMessage); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($topbarSearchValue !== ''): ?>
            <div class="card">
                <div class="card-header"><span class="card-title">Search results for "<?php echo safeHtml($topbarSearchValue); ?>"</span><span class="card-badge"><?php echo count($searchHits); ?> FOUND</span></div>
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
                <div class="card-header"><span class="card-title">🏢 <?php echo safeHtml($myDepartment['name']); ?></span><span class="card-badge">MY DEPARTMENT</span></div>
                <div class="stat-grid" style="margin-bottom:16px;">
                    <div class="stat-card"><div class="stat-label">Ceiling</div><div class="stat-value"><?php echo formatCurrency($myDepartmentRation['ceiling'], $myDepartmentRation['currency']); ?></div></div>
                    <div class="stat-card"><div class="stat-label">Disbursed YTD</div><div class="stat-value"><?php echo formatCurrency($myDepartmentRation['disbursed_ytd'], $myDepartmentRation['currency']); ?></div></div>
                    <div class="stat-card accent-<?php echo $myDepartmentRation['available'] < 0 ? 'danger' : 'green'; ?>"><div class="stat-label">Available</div><div class="stat-value"><?php echo formatCurrency($myDepartmentRation['available'], $myDepartmentRation['currency']); ?></div></div>
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
                                <option value="">Select department…</option>
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
            <!-- ============================================================ -->
            <!-- STAT CARDS -->
            <!-- ============================================================ -->
            <div class="stat-grid">
                <div class="stat-card"><div class="stat-label">Total Global Budget</div><div class="stat-value"><?php echo formatCurrency($totalGlobalBudget); ?></div><div class="stat-sub">Sum of every root department's ceiling</div></div>
                <div class="stat-card"><div class="stat-label">Total Allocated</div><div class="stat-value"><?php echo formatCurrency($totalAllocated); ?></div><div class="stat-sub">Carved out to (sub-)departments</div></div>
                <div class="stat-card accent-brass"><div class="stat-label">Unallocated Reserve</div><div class="stat-value brass"><?php echo formatCurrency($unallocatedReserve); ?></div><div class="stat-sub">Still available to allocate</div></div>
            </div>

            <!-- ============================================================ -->
            <!-- DEPARTMENTAL ALLOCATIONS + PENDING APPROVALS -->
            <!-- ============================================================ -->
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
                        <?php if (!empty($unifiedApprovals)): ?><span class="critical-pill"><?php echo count($unifiedApprovals); ?><small style="font-size:8px;">REQ</small></span><?php endif; ?>
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

            <!-- ============================================================ -->
            <!-- CREATE / ADJUST — same real forms as before, restyled -->
            <!-- ============================================================ -->
            <div class="card" id="dept-create">
                <div class="card-header"><span class="card-title">➕ Create &amp; Adjust</span></div>

                <details class="disclosure" open>
                    <summary>Create top-level department</summary>
                    <form method="post" class="form-grid" style="margin-top:10px;">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_department">
                        <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="e.g. Huíla Province"></div>
                        <div class="form-group"><label>Code <span style="text-transform:none; color:var(--ink-300);">(optional)</span></label><input type="text" name="code" placeholder="e.g. HUI"></div>
                        <div class="form-group"><label>Cost Center <span style="text-transform:none; color:var(--ink-300);">(optional)</span></label><input type="text" name="cost_center"></div>
                        <div class="form-group"><label>Budget Ceiling <span style="text-transform:none; color:var(--ink-300);">(optional)</span></label><input type="number" step="0.01" name="budget_ceiling" placeholder="Leave blank for no vote"></div>
                        <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Create</button></div>
                    </form>
                </details>

                <details class="disclosure">
                    <summary>Create sub-department directly</summary>
                    <form method="post" class="form-grid" style="margin-top:10px;">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_sub_department">
                        <div class="form-group"><label>Parent Department</label><select name="parent_id" required><option value="">Select…</option><?php foreach ($flatDepartments as $d): ?><option value="<?php echo (int)$d['id']; ?>"><?php echo safeHtml($d['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
                        <div class="form-group"><label>Code</label><input type="text" name="code"></div>
                        <div class="form-group"><label>Budget Ceiling <span style="text-transform:none; color:var(--ink-300);">(optional)</span></label><input type="number" step="0.01" name="budget_ceiling" placeholder="Leave blank for no vote"></div>
                        <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Create Sub-department</button></div>
                    </form>
                    <p class="hint">Must fit within the parent's remaining (unallocated) ceiling.</p>
                </details>
            </div>
            <?php endif; ?>
        <?php
        $dbHealthy = DBConnection::isConnected();
        $footerStatusLine = 'LEDGER SYNC: ' . ($dbHealthy ? '<span class="ok">OK</span>' : '<span class="bad">DEGRADED</span>');
        require __DIR__ . '/../partials/shell-foot.php';
        ?>
</body>
</html>

