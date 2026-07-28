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
$userMgmt = new UserManagementService($pdo);

// ============================================================
// HANDLE FORM SUBMISSIONS (POST)
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
                // Only the three roles this quick-add panel is for. Anything
                // else (finance_officer, auditor, etc.) belongs in the full
                // HR screen (settings/users.php), not this shortcut.
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
                $newDepartmentId = $staffDeptId; // keep this department's panel open to show the result
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

    // PRG pattern - avoid resubmission on refresh
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
// FETCH DATA
// ============================================================
$tree = $deptService->getDepartmentTree($orgId);
$flatDepartments = $deptService->getDepartmentsFlat($orgId);

$myDepartment = $isDepartmentHead ? $deptService->getDepartmentHeadedBy((int)$userId) : null;
$myDepartmentRation = $myDepartment ? $deptService->getAvailableRation((int)$myDepartment['id']) : null;

$pendingSubDeptRequests = $isTopRole ? $deptService->getPendingSubDepartmentRequests($orgId) : [];
$pendingBorrowRequests = $canApproveBorrow ? $deptService->getPendingBorrowRequests($orgId) : [];

// Staff already assigned per department, keyed by department_id, for the
// inline "who's staffing this department" panel — so the quick-add form
// shows what's already filled instead of just an empty form every time.
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

    // Unscoped ("HQ") staff — department_id IS NULL — for the organogram's
    // top row. This is the org-wide roster: Owner plus anyone whose
    // authority isn't tied to one department.
    $stmt = $pdo->prepare("
        SELECT full_name, role, is_active
        FROM organization_users
        WHERE organization_id = :org_id AND department_id IS NULL AND is_active = true
        ORDER BY role ASC, full_name ASC
    ");
    $stmt->execute([':org_id' => $orgId]);
    $hqStaff = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Source account counts for the organogram's "money in" node — same
    // active/pending distinction used everywhere else (add_source.php,
    // the setup checklist), so this box never disagrees with those pages.
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

// The three roles this page's quick-add panel offers, with names matched
// to how the org actually talks about these jobs. 'owner' here is a
// DEPARTMENT-SCOPED owner account — same role, same hard "only owner can
// execute" rule as everywhere else, just dedicated to one department
// rather than the whole org. Doesn't have to be the same person running
// IT or HQ — that's the point of calling it out as its own slot.
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
    if ($percent >= 100) return 'var(--danger)';
    if ($percent >= 80) return 'var(--amber)';
    return 'var(--ledger-green)';
}

/**
 * Visual, always-current snapshot of the organization: HQ team + source
 * accounts on one row, top-level departments on the row below. Rebuilt
 * fresh from the database on every page load — there's no separate
 * "state" to keep in sync, so it can never drift from what the tree/
 * staff list below it already shows in detail. Deliberately stops at
 * top-level departments rather than recursing into every sub-department
 * — the detailed tree further down the page already covers that depth;
 * this is meant to be the "what have I built so far, at a glance" view,
 * not a duplicate of it.
 */
function renderOrganogram(string $orgName, array $hqStaff, array $tree, array $staffByDepartment, array $sourceAccountSummary): string {
    $roleLabels = [
        'owner' => 'Owner', 'it_manager_enterprise' => 'IT Manager', 'it_officer_enterprise' => 'IT Officer',
        'finance_officer' => 'Finance Officer', 'approver' => 'Approver', 'senior_approver' => 'Senior Approver',
        'auditor' => 'Auditor', 'viewer' => 'Viewer', 'department_head' => 'Dept Head',
        'program_officer' => 'Uploader', 'beneficiary_registrar' => 'Beneficiary Registrar',
    ];

    $html = '<div class="organogram">';
    $html .= '<div class="org-root">' . safeHtml($orgName) . '</div>';
    $html .= '<div class="org-connector"></div>';

    // Level 1: HQ Team + Source Accounts, side by side.
    $html .= '<div class="org-row">';

    $html .= '<div class="org-branch"><div class="org-node-stub"></div><div class="org-node hq">';
    $html .= '<div class="org-node-head">👥 HQ Team</div><div class="org-node-body">';
    if (empty($hqStaff)) {
        $html .= '<div class="org-node-empty">Only you so far</div>';
    } else {
        foreach ($hqStaff as $s) {
            $label = $roleLabels[$s['role']] ?? ucfirst(str_replace('_', ' ', $s['role']));
            $html .= '<div class="role-line">' . safeHtml($s['full_name']) . ' <span style="color:var(--ink-300);">— ' . safeHtml($label) . '</span></div>';
        }
    }
    $html .= '<a href="settings/users.php" class="org-node-cta">+ Add / Manage Team →</a>';
    $html .= '</div></div></div>';

    $html .= '<div class="org-branch"><div class="org-node-stub"></div><div class="org-node source">';
    $html .= '<div class="org-node-head">💰 Source Accounts</div><div class="org-node-body">';
    if ($sourceAccountSummary['confirmed'] > 0) {
        $names = array_unique(array_filter((array)$sourceAccountSummary['names']));
        $html .= '<div class="role-line">✅ ' . (int)$sourceAccountSummary['confirmed'] . ' confirmed';
        if (!empty($names)) {
            $html .= ' <span style="color:var(--ink-300);">(' . safeHtml(implode(', ', $names)) . ')</span>';
        }
        $html .= '</div>';
    } else {
        $html .= '<div class="org-node-empty">None confirmed yet</div>';
    }
    if ($sourceAccountSummary['pending'] > 0) {
        $html .= '<div class="role-line" style="color:var(--amber);">⏳ ' . (int)$sourceAccountSummary['pending'] . ' awaiting confirmation</div>';
    }
    $html .= '<a href="imports/add_source.php" class="org-node-cta">+ Add Source Account →</a>';
    $html .= '</div></div></div>';

    $html .= '</div>';

    // Level 2: top-level departments.
    if (!empty($tree)) {
        $html .= '<div class="org-connector"></div>';
        $html .= '<div class="org-row">';
        foreach ($tree as $node) {
            $deptId = (int)$node['id'];
            $subCount = count($node['children'] ?? []);
            $staffHere = $staffByDepartment[$deptId] ?? [];

            $html .= '<div class="org-branch"><div class="org-node-stub"></div><div class="org-node dept">';
            $html .= '<div class="org-node-head">🏢 ' . safeHtml($node['name']) . '</div><div class="org-node-body">';
            $html .= $node['has_ceiling']
                ? '<div class="role-line">Ceiling: ' . number_format((float)$node['budget_ceiling'], 2) . '</div>'
                : '<div class="role-line" style="color:var(--ink-300);">No vote (source-limited)</div>';
            $html .= empty($staffHere)
                ? '<div class="org-node-empty">No staff assigned yet</div>'
                : '<div class="role-line">' . count($staffHere) . ' staff assigned</div>';
            if ($subCount > 0) {
                $html .= '<div class="role-line" style="color:var(--ink-300);">' . $subCount . ' sub-department' . ($subCount > 1 ? 's' : '') . '</div>';
            }
            $html .= '<a href="#dept-' . $deptId . '" class="org-node-cta">+ Add Staff / Sub-dept ↓</a>';
            $html .= '</div></div></div>';
        }
        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

// Recursive department row renderer (top roles see everything; dept heads
// only ever load their own subtree via $tree already being scoped... but
// since getDepartmentTree returns the whole org tree, we filter for
// non-top roles below at render time).
function renderDepartmentNode(
    array $node,
    bool $isTopRole,
    ?int $myDeptId,
    array $staffByDepartment,
    array $quickAddSlots,
    ?int $newDepartmentId,
    string $csrfToken,
    int $depth = 0
): string {
    // department_head only sees their own department + its descendants
    if (!$isTopRole && $myDeptId !== null) {
        $isMine = ((int)$node['id'] === $myDeptId);
        $isDescendant = false; // shallow check; tree walk below handles nested visibility
        if (!$isMine) {
            // still may need to render children if one of them is mine — handled by caller filtering
        }
    }

    $pct = $node['utilization_percent'];
    $barColor = ratioBarColor($pct);
    $indent = $depth * 28;
    $deptId = (int)$node['id'];

    $html = '<div class="dept-row" id="dept-' . $deptId . '" style="margin-left:' . $indent . 'px;">';
    $html .= '<div class="dept-row-header">';
    $html .= '<span class="dept-name">' . ($depth > 0 ? '&#8627; ' : '') . safeHtml($node['name']);
    if (!empty($node['code'])) {
        $html .= ' <span class="dept-code">(' . safeHtml($node['code']) . ')</span>';
    }
    $html .= '</span>';
    if ($node['is_over_ration']) {
        $html .= '<span class="status status-rejected">OVER RATION</span>';
    }
    $html .= '</div>';

    $html .= $node['has_ceiling']
        ? '<div class="dept-bar-track"><div class="dept-bar-fill" style="width:' . min(100, $pct) . '%; background:' . $barColor . ';"></div></div>'
        : '';

    $html .= '<div class="dept-stats">';
    if ($node['has_ceiling']) {
        $html .= '<span>Ceiling: <strong>' . formatCurrency($node['budget_ceiling']) . '</strong></span>';
        $html .= '<span>Disbursed YTD: ' . formatCurrency($node['amount_disbursed_ytd']) . '</span>';
        $html .= '<span>Reserved (pending): ' . formatCurrency($node['reserved_in_flight']) . '</span>';
        $html .= '<span class="' . ($node['available'] < 0 ? 'text-danger' : 'text-green') . '">Available: <strong>' . formatCurrency($node['available']) . '</strong></span>';
    } else {
        $html .= '<span class="no-vote-badge">⚠ No vote assigned — limited only by source account balance, checked at execute time</span>';
        $html .= '<span>Disbursed YTD: ' . formatCurrency($node['amount_disbursed_ytd']) . '</span>';
    }
    $html .= '</div>';

    // ============================================================
    // INLINE STAFFING PANEL — only for top roles, who are the only ones
    // allowed to create these accounts anyway. Auto-opens right after this
    // exact department was just created, so "who's staffing this?" is the
    // very next thing shown, not a separate screen someone has to go find.
    // ============================================================
    if ($isTopRole) {
        $assigned = $staffByDepartment[$deptId] ?? [];
        $autoOpen = ($newDepartmentId !== null && $deptId === (int)$newDepartmentId);

        $html .= '<details class="staff-panel"' . ($autoOpen ? ' open' : '') . '>';
        $html .= '<summary>👥 Staffing' . (!empty($assigned) ? ' (' . count($assigned) . ')' : ' — none assigned yet') . '</summary>';
        $html .= '<div class="staff-panel-body">';

        if (!empty($assigned)) {
            $html .= '<div class="staff-list">';
            foreach ($assigned as $person) {
                $roleLabel = $quickAddSlots[$person['role']]['label'] ?? ucfirst(str_replace('_', ' ', $person['role']));
                $statusClass = $person['is_active'] ? 'staff-active' : 'staff-inactive';
                $html .= '<div class="staff-chip ' . $statusClass . '">' . safeHtml($roleLabel) . ': <strong>' . safeHtml($person['full_name']) . '</strong>'
                    . (!$person['is_active'] ? ' (inactive)' : '') . '</div>';
            }
            $html .= '</div>';
        } else {
            $html .= '<p class="staff-empty-note">No one assigned here yet — that\'s fine. Headquarters (or the parent department) covers anything left blank.</p>';
        }

        $html .= '<div class="staff-add-grid">';
        foreach ($quickAddSlots as $roleKey => $slot) {
            $html .= '<form method="post" class="staff-add-form">';
            $html .= '<input type="hidden" name="csrf_token" value="' . safeHtml($csrfToken) . '">';
            $html .= '<input type="hidden" name="action" value="quick_add_staff">';
            $html .= '<input type="hidden" name="department_id" value="' . $deptId . '">';
            $html .= '<input type="hidden" name="staff_role" value="' . safeHtml($roleKey) . '">';
            $html .= '<label class="staff-add-label">+ Add ' . safeHtml($slot['label']) . '</label>';
            $html .= '<span class="staff-add-desc">' . safeHtml($slot['desc']) . '</span>';
            $html .= '<input type="text" name="staff_name" placeholder="Full name" required>';
            $html .= '<input type="email" name="staff_email" placeholder="Email" required>';
            $html .= '<input type="text" name="staff_password" placeholder="Password (optional, random if blank)">';
            $html .= '<button type="submit" class="btn btn-secondary btn-sm">Create Login</button>';
            $html .= '</form>';
        }
        $html .= '</div>';

        $html .= '</div></details>';
    }

    $html .= '</div>';

    foreach ($node['children'] as $child) {
        $html .= renderDepartmentNode($child, $isTopRole, $myDeptId, $staffByDepartment, $quickAddSlots, $newDepartmentId, $csrfToken, $depth + 1);
    }

    return $html;
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
    <style>
        :root {
            --paper: #EEF1EF; --panel: #FFFFFF; --ink-900: #0F2138; --ink-700: #1D3557;
            --ink-500: #4A5A6E; --ink-300: #8A96A3; --line: #D3DAD6; --line-strong: #AEB8B2;
            --brass: #8A6D3B; --brass-tint: #F4EFE3; --seal-red: #7A2118;
            --amber: #8A5A0B; --amber-bg: #FEF3C7; --ledger-green: #24513A; --green-tint: #E5EEE7;
            --blue-tint: #E7EEF4; --danger: #b3261e; --danger-bg: #fbeceb;
            --max-width: 1400px; --btn-h: 36px; --btn-h-sm: 28px;
            --f-body: 'IBM Plex Sans', sans-serif; --f-cond: 'IBM Plex Sans Condensed', sans-serif; --f-mono: 'IBM Plex Mono', monospace;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        .header { background: var(--ink-900); color: #fff; border-bottom: 3px solid var(--brass); }
        .header-inner { max-width: var(--max-width); margin: 0 auto; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .logo { font-family: var(--f-cond); font-weight: 700; font-size: 18px; letter-spacing: 0.08em; text-transform: uppercase; }
        .logo span { color: var(--brass); }
        .back-link { color: var(--brass); text-decoration: none; font-size: 12px; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: 0.04em; }
        .content { max-width: var(--max-width); margin: 0 auto; padding: 28px 32px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .page-header h1 { font-family: var(--f-cond); font-size: 24px; font-weight: 700; }
        .page-header .sub { color: var(--ink-500); font-size: 14px; }

        /* ============================================================
           ORGANOGRAM — always-current visual snapshot of the org.
           Simple "each box has its own connector stub" pattern rather
           than a true measured bus-line, which needs JS to get right
           for variable-width rows — this reads as a tree clearly enough
           without it, for an internal setup tool.
           ============================================================ */
        .organogram { display: flex; flex-direction: column; align-items: center; padding: 24px 8px 8px; }
        .org-root {
            background: var(--ink-900); color: #fff; padding: 10px 26px;
            font-family: var(--f-cond); font-weight: 700; font-size: 14px;
            text-transform: uppercase; letter-spacing: 0.05em;
        }
        .org-connector { width: 2px; height: 22px; background: var(--line-strong); }
        .org-row { display: flex; gap: 22px; justify-content: center; flex-wrap: wrap; }
        .org-branch { display: flex; flex-direction: column; align-items: center; }
        .org-node-stub { width: 2px; height: 22px; background: var(--line-strong); }
        .org-node { background: var(--panel); border: 1.5px solid var(--line); width: 220px; }
        .org-node.hq { border-top: 3px solid var(--brass); }
        .org-node.source { border-top: 3px solid #1e40af; }
        .org-node.dept { border-top: 3px solid var(--ledger-green); }
        .org-node-head {
            background: var(--paper); padding: 8px 14px; font-family: var(--f-cond);
            font-weight: 700; font-size: 13px; border-bottom: 1px solid var(--line);
        }
        .org-node-body { padding: 10px 14px 12px; font-size: 12px; color: var(--ink-500); }
        .org-node-body .role-line { margin-bottom: 4px; }
        .org-node-empty { color: var(--ink-300); font-style: italic; margin-bottom: 4px; }
        .org-node-cta {
            display: inline-block; margin-top: 6px; font-size: 10.5px; font-weight: 700;
            color: var(--brass); text-decoration: none; text-transform: uppercase; letter-spacing: 0.03em;
            font-family: var(--f-cond);
        }
        .org-node-cta:hover { text-decoration: underline; }
        @media (max-width: 768px) {
            .org-row { flex-direction: column; align-items: center; }
        }
        .flash { padding: 14px 20px; margin-bottom: 20px; border-left: 4px solid; font-size: 13.5px; }
        .flash-success { background: var(--green-tint); border-color: var(--ledger-green); color: var(--ledger-green); }
        .flash-error { background: var(--danger-bg); border-color: var(--danger); color: var(--danger); }
        .card { background: var(--panel); border: 1px solid var(--line); padding: 20px 24px; margin-bottom: 20px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--line); flex-wrap: wrap; gap: 8px; }
        .card-title { font-size: 16px; font-weight: 700; font-family: var(--f-cond); }
        .card-badge { padding: 2px 12px; background: var(--ink-900); color: #fff; font-size: 10px; font-weight: 600; font-family: var(--f-cond); }
        .dept-row { padding: 14px 0; border-bottom: 1px solid var(--line); }
        .dept-row:last-child { border-bottom: none; }
        .dept-row-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .dept-name { font-weight: 600; font-size: 14px; }
        .dept-code { color: var(--ink-300); font-size: 12px; font-weight: 400; }
        .dept-bar-track { height: 8px; background: var(--paper); border: 1px solid var(--line); margin-bottom: 8px; }
        .dept-bar-fill { height: 100%; transition: width 0.2s; }
        .dept-stats { display: flex; gap: 20px; flex-wrap: wrap; font-size: 12px; color: var(--ink-500); }

        .staff-panel { margin-top: 12px; border-top: 1px dashed var(--line); padding-top: 10px; }
        .staff-panel summary { cursor: pointer; font-size: 12px; font-weight: 600; color: var(--ink-500); font-family: var(--f-cond); text-transform: uppercase; letter-spacing: .03em; }
        .staff-panel summary:hover { color: var(--brass); }
        .staff-panel-body { margin-top: 10px; padding-left: 4px; }
        .staff-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .staff-chip { font-size: 12px; padding: 4px 10px; background: var(--paper); border: 1px solid var(--line); }
        .staff-chip.staff-inactive { opacity: 0.5; text-decoration: line-through; }
        .staff-empty-note { font-size: 12.5px; color: var(--ink-300); margin-bottom: 12px; font-style: italic; }
        .staff-add-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .staff-add-form { background: var(--paper); border: 1px solid var(--line); padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; }
        .staff-add-label { font-size: 11.5px; font-weight: 700; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: .03em; color: var(--ink-900); }
        .staff-add-desc { font-size: 11px; color: var(--ink-300); margin-bottom: 2px; }
        .staff-add-form input { padding: 6px 8px; border: 1px solid var(--line); font-size: 12.5px; background: var(--panel); }
        .staff-add-form input:focus { outline: none; border-color: var(--brass); }
        .hint { font-size: 11px; color: var(--ink-300); margin-top: 3px; line-height: 1.4; }
        .no-vote-badge { font-size: 11.5px; color: var(--amber); font-style: italic; }

        .creds-banner { background: var(--ink-900); color: #fff; padding: 18px 22px; margin-bottom: 20px; border-left: 4px solid var(--brass); }
        .creds-banner .warn { color: #fbbf24; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; font-family: var(--f-cond); margin-bottom: 8px; }
        .creds-banner .row { display: flex; gap: 16px; flex-wrap: wrap; font-size: 13.5px; margin-bottom: 4px; }
        .creds-banner .row .k { color: var(--ink-300); font-family: var(--f-cond); font-size: 10.5px; text-transform: uppercase; min-width: 90px; }
        .creds-banner .row .v { font-family: var(--f-mono); font-weight: 700; }

        .empty-state { text-align: center; padding: 32px 20px; color: var(--ink-300); font-size: 13.5px; }
        .text-danger { color: var(--danger); }
        .text-green { color: var(--ledger-green); }
        .status { display: inline-block; padding: 2px 12px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; font-family: var(--f-cond); }
        .status-rejected { background: var(--danger-bg); color: var(--danger); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .form-group label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-500); margin-bottom: 6px; font-family: var(--f-cond); }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; height: var(--btn-h); padding: 0 12px; border: 1.5px solid var(--line);
            font-size: 13.5px; font-family: var(--f-body); background: var(--paper); color: var(--ink-900); box-sizing: border-box;
        }
        .form-group textarea { height: 70px; padding: 8px 12px; resize: vertical; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--brass); background: var(--panel); }
        .btn { height: var(--btn-h); padding: 0 18px; font-size: 12px; font-weight: 600; font-family: var(--f-cond); border: 1px solid transparent; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; letter-spacing: 0.04em; text-transform: uppercase; box-sizing: border-box; }
        .btn-primary { background: var(--ink-900); color: #fff; border-color: var(--ink-900); }
        .btn-primary:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }
        .btn-success { background: var(--ledger-green); color: #fff; border-color: var(--ledger-green); }
        .btn-danger { background: var(--danger); color: #fff; border-color: var(--danger); }
        .btn-outline { background: transparent; border: 1px solid var(--line); color: var(--ink-500); }
        .btn-outline:hover { border-color: var(--brass); color: var(--ink-900); background: var(--brass-tint); }
        .btn-sm { height: var(--btn-h-sm); padding: 0 14px; font-size: 11px; }
        .request-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--line); flex-wrap: wrap; gap: 10px; }
        .request-row:last-child { border-bottom: none; }
        .request-info { font-size: 13px; }
        .request-info .meta { color: var(--ink-300); font-size: 11px; margin-top: 2px; }
        .request-actions { display: flex; gap: 8px; }
        .empty-state { text-align: center; padding: 32px 20px; color: var(--ink-300); font-size: 13.5px; }
        .toggle-form { display: none; margin-top: 16px; padding-top: 16px; border-top: 1px dashed var(--line); }
        .toggle-form.open { display: block; }
        details summary { cursor: pointer; font-weight: 600; font-size: 13px; color: var(--brass); list-style: none; }
        details summary::-webkit-details-marker { display: none; }
        details[open] summary { margin-bottom: 14px; }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-inner">
            <div class="logo">VOUCHMORPH <span>·</span> DEPARTMENTS</div>
            <a href="../index.php" class="back-link">&larr; Back to Dashboard</a>
        </div>
    </header>

    <main class="content">
        <div class="page-header">
            <div>
                <h1>Departments &amp; Rations</h1>
                <div class="sub">Welcome, <?php echo safeHtml($fullName); ?></div>
            </div>
        </div>

        <?php if ($newStaffCredentials): ?>
        <div class="creds-banner">
            <div class="warn">⚠ One-time display — copy this now, it will not be shown again</div>
            <div class="row"><span class="k">Name</span><span class="v" style="font-weight:400;"><?php echo safeHtml($newStaffCredentials['name']); ?></span></div>
            <div class="row"><span class="k">Email</span><span class="v" style="font-weight:400;"><?php echo safeHtml($newStaffCredentials['email']); ?></span></div>
            <div class="row"><span class="k">Temp password</span><span class="v"><?php echo safeHtml($newStaffCredentials['temp_password']); ?></span></div>
        </div>
        <?php endif; ?>

        <?php if ($flashMessage): ?>
        <div class="flash flash-<?php echo $flashType; ?>"><?php echo safeHtml($flashMessage); ?></div>
        <?php endif; ?>

        <?php if ($isDepartmentHead && $myDepartment): ?>
        <!-- ============================================================
             DEPARTMENT HEAD VIEW: their own department + actions
             ============================================================ -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🏢 <?php echo safeHtml($myDepartment['name']); ?></span>
                <span class="card-badge">MY DEPARTMENT</span>
            </div>
            <div class="dept-stats" style="margin-bottom:16px;">
                <span>Ceiling: <strong><?php echo formatCurrency($myDepartmentRation['ceiling'], $myDepartmentRation['currency']); ?></strong></span>
                <span>Disbursed YTD: <?php echo formatCurrency($myDepartmentRation['disbursed_ytd'], $myDepartmentRation['currency']); ?></span>
                <span>Reserved (pending batches): <?php echo formatCurrency($myDepartmentRation['reserved_in_flight'], $myDepartmentRation['currency']); ?></span>
                <span class="<?php echo $myDepartmentRation['available'] < 0 ? 'text-danger' : 'text-green'; ?>">
                    Available: <strong><?php echo formatCurrency($myDepartmentRation['available'], $myDepartmentRation['currency']); ?></strong>
                </span>
            </div>

            <details>
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
                <p style="font-size:12px; color:var(--ink-300); margin-top:8px;">Goes to a top-level admin (Owner / IT Manager) for approval before it becomes an active sub-department.</p>
            </details>

            <details style="margin-top:14px;">
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
                    <div class="form-group" style="grid-column: 1 / -1;"><label>Reason</label><textarea name="reason" required></textarea></div>
                    <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-warning" style="background:var(--amber);color:#fff;">Submit Borrow Request</button></div>
                </form>
                <p style="font-size:12px; color:var(--ink-300); margin-top:8px;">Only Owner, IT Manager, or Finance Officer can approve this — the lending department is not asked to agree.</p>
            </details>
        </div>
        <?php endif; ?>

        <?php if ($isTopRole): ?>
        <!-- ============================================================
             ORGANOGRAM — the "what have I built so far" snapshot, first
             thing seen, always reflecting the current database state.
             ============================================================ -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🗺️ Organization Structure</span>
            </div>
            <?php echo renderOrganogram($orgName, $hqStaff, $tree, $staffByDepartment, $sourceAccountSummary); ?>
        </div>

        <!-- ============================================================
             TOP ROLE VIEW: full org tree + create controls
             ============================================================ -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🏢 Organization Department Tree</span>
                <span class="card-badge"><?php echo count($tree); ?> TOP-LEVEL</span>
            </div>

            <?php if (empty($tree)): ?>
            <div class="empty-state">No departments yet. Create the first one below.</div>
            <?php else: ?>
                <?php foreach ($tree as $node) { echo renderDepartmentNode($node, true, null, $staffByDepartment, $quickAddSlots, $newDepartmentId, $csrfToken); } ?>
            <?php endif; ?>

            <details style="margin-top:18px;">
                <summary>+ Create top-level department</summary>
                <form method="post" class="form-grid" style="margin-top:10px;">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_department">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" required placeholder="e.g. Huíla Province">
                        <div class="hint">What staff and reports will call it. Use the real name of the province, ministry, or program this represents — this is what shows up everywhere in the dashboard, so it's worth getting right the first time.</div>
                    </div>
                    <div class="form-group">
                        <label>Code <span style="font-weight:400; text-transform:none; color:var(--ink-300);">(optional)</span></label>
                        <input type="text" name="code" placeholder="e.g. HUI">
                        <div class="hint">A short internal reference (like an abbreviation). Not shown to beneficiaries, only used in exports and cross-references — leave blank if this department doesn't need one.</div>
                    </div>
                    <div class="form-group">
                        <label>Cost Center <span style="font-weight:400; text-transform:none; color:var(--ink-300);">(optional)</span></label>
                        <input type="text" name="cost_center" placeholder="e.g. GOV-HUI-01">
                        <div class="hint">Your own accounting or ledger code for this department, if your organization tracks spending against one externally. Purely for your records — VouchMorph doesn't use this for anything itself.</div>
                    </div>
                    <div class="form-group"><label>Budget Ceiling <span style="font-weight:400; text-transform:none; color:var(--ink-300);">(optional)</span></label><input type="number" step="0.01" name="budget_ceiling" placeholder="Leave blank for no vote"><div class="hint">Leave blank for "no vote" — this department can spend up to whatever the source account actually has, checked only at execute time instead of caught early at submission.</div></div>
                    <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Create</button></div>
                </form>
            </details>

            <details style="margin-top:14px;">
                <summary>+ Create sub-department directly</summary>
                <form method="post" class="form-grid" style="margin-top:10px;">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_sub_department">
                    <div class="form-group">
                        <label>Parent Department</label>
                        <select name="parent_id" required>
                            <option value="">Select&hellip;</option>
                            <?php foreach ($flatDepartments as $d): ?>
                            <option value="<?php echo (int)$d['id']; ?>"><?php echo safeHtml($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
                    <div class="form-group"><label>Code</label><input type="text" name="code"></div>
                    <div class="form-group"><label>Budget Ceiling <span style="font-weight:400; text-transform:none; color:var(--ink-300);">(optional)</span></label><input type="number" step="0.01" name="budget_ceiling" placeholder="Leave blank for no vote"><div class="hint">Leave blank for "no vote" — this department can spend up to whatever the source account actually has, checked only at execute time instead of caught early at submission.</div></div>
                    <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Create Sub-department</button></div>
                </form>
                <p style="font-size:12px; color:var(--ink-300); margin-top:8px;">Must fit within the parent's remaining (unallocated) ceiling.</p>
            </details>

            <details style="margin-top:14px;">
                <summary>+ Adjust an existing department's ceiling</summary>
                <form method="post" class="form-grid" style="margin-top:10px;">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_ceiling">
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" required>
                            <option value="">Select&hellip;</option>
                            <?php foreach ($flatDepartments as $d): ?>
                            <option value="<?php echo (int)$d['id']; ?>"><?php echo safeHtml($d['name']); ?> (current: <?php echo formatCurrency($d['budget_ceiling']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>New Ceiling <span style="font-weight:400; text-transform:none; color:var(--ink-300);">(optional)</span></label><input type="number" step="0.01" name="new_ceiling" placeholder="Leave blank for no vote"><div class="hint">Leave blank to switch this department to "no vote."</div></div>
                    <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Update</button></div>
                </form>
            </details>
        </div>

        <!-- ============================================================
             PENDING SUB-DEPARTMENT REQUESTS (top roles only)
             ============================================================ -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📥 Pending Sub-department Requests</span>
                <span class="card-badge"><?php echo count($pendingSubDeptRequests); ?> PENDING</span>
            </div>
            <?php if (empty($pendingSubDeptRequests)): ?>
            <div class="empty-state">No pending requests.</div>
            <?php else: ?>
                <?php foreach ($pendingSubDeptRequests as $req): ?>
                <div class="request-row">
                    <div class="request-info">
                        <strong><?php echo safeHtml($req['proposed_name']); ?></strong> under <?php echo safeHtml($req['parent_department_name']); ?>
                        <?php if ($req['requested_ceiling'] !== null): ?>
                            — requested ceiling: <?php echo formatCurrency($req['requested_ceiling']); ?>
                        <?php endif; ?>
                        <div class="meta">Requested <?php echo date('Y-m-d H:i', strtotime($req['created_at'])); ?></div>
                    </div>
                    <div class="request-actions">
                        <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                            <input type="hidden" name="action" value="decide_sub_department_request">
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <input type="hidden" name="decision" value="approve">
                            <button type="submit" class="btn btn-success btn-sm">Approve</button>
                        </form>
                        <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                            <input type="hidden" name="action" value="decide_sub_department_request">
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <input type="hidden" name="decision" value="reject">
                            <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($canApproveBorrow): ?>
        <!-- ============================================================
             PENDING BORROW REQUESTS (top roles + finance_officer)
             ============================================================ -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">💱 Pending Ration Borrow Requests</span>
                <span class="card-badge"><?php echo count($pendingBorrowRequests); ?> PENDING</span>
            </div>
            <?php if (empty($pendingBorrowRequests)): ?>
            <div class="empty-state">No pending borrow requests.</div>
            <?php else: ?>
                <?php foreach ($pendingBorrowRequests as $req): ?>
                <div class="request-row">
                    <div class="request-info">
                        <strong><?php echo safeHtml($req['borrowing_department_name']); ?></strong> wants to borrow
                        <strong><?php echo formatCurrency($req['amount']); ?></strong> from
                        <strong><?php echo safeHtml($req['lending_department_name']); ?></strong>
                        <div class="meta"><?php echo safeHtml($req['reason']); ?> — <?php echo date('Y-m-d H:i', strtotime($req['created_at'])); ?></div>
                    </div>
                    <div class="request-actions">
                        <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                            <input type="hidden" name="action" value="approve_borrow">
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <button type="submit" class="btn btn-success btn-sm">Approve</button>
                        </form>
                        <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                            <input type="hidden" name="action" value="reject_borrow">
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
