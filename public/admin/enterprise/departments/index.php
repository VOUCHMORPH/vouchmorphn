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

require_once '../auth.php';
require_once '../../src/Domain/Services/DepartmentService.php';

use Domain\Services\DepartmentService;

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

// ============================================================
// HANDLE FORM SUBMISSIONS (POST)
// ============================================================
$flashMessage = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create_department':
                if (!$isTopRole) throw new RuntimeException('Not authorized.');
                $deptService->createDepartment(
                    $orgId,
                    trim($_POST['name'] ?? ''),
                    trim($_POST['code'] ?? '') ?: null,
                    trim($_POST['cost_center'] ?? '') ?: null,
                    (float)($_POST['budget_ceiling'] ?? 0),
                    (int)$userId,
                    $userRole
                );
                $flashMessage = 'Department created.';
                break;

            case 'create_sub_department':
                if (!$isTopRole) throw new RuntimeException('Not authorized.');
                $deptService->createSubDepartment(
                    (int)($_POST['parent_id'] ?? 0),
                    trim($_POST['name'] ?? ''),
                    trim($_POST['code'] ?? '') ?: null,
                    trim($_POST['cost_center'] ?? '') ?: null,
                    (float)($_POST['budget_ceiling'] ?? 0),
                    (int)$userId,
                    $userRole
                );
                $flashMessage = 'Sub-department created.';
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
                    (float)($_POST['new_ceiling'] ?? 0),
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
    $_SESSION['dept_flash'] = ['message' => $flashMessage, 'type' => $flashType];
    header('Location: index.php');
    exit;
}

if (isset($_SESSION['dept_flash'])) {
    $flashMessage = $_SESSION['dept_flash']['message'];
    $flashType = $_SESSION['dept_flash']['type'];
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

// Recursive department row renderer (top roles see everything; dept heads
// only ever load their own subtree via $tree already being scoped... but
// since getDepartmentTree returns the whole org tree, we filter for
// non-top roles below at render time).
function renderDepartmentNode(array $node, bool $isTopRole, ?int $myDeptId, int $depth = 0): string {
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

    $html = '<div class="dept-row" style="margin-left:' . $indent . 'px;">';
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

    $html .= '<div class="dept-bar-track"><div class="dept-bar-fill" style="width:' . min(100, $pct) . '%; background:' . $barColor . ';"></div></div>';

    $html .= '<div class="dept-stats">';
    $html .= '<span>Ceiling: <strong>' . formatCurrency($node['budget_ceiling']) . '</strong></span>';
    $html .= '<span>Disbursed YTD: ' . formatCurrency($node['amount_disbursed_ytd']) . '</span>';
    $html .= '<span>Reserved (pending): ' . formatCurrency($node['reserved_in_flight']) . '</span>';
    $html .= '<span class="' . ($node['available'] < 0 ? 'text-danger' : 'text-green') . '">Available: <strong>' . formatCurrency($node['available']) . '</strong></span>';
    $html .= '</div>';

    $html .= '</div>';

    foreach ($node['children'] as $child) {
        $html .= renderDepartmentNode($child, $isTopRole, $myDeptId, $depth + 1);
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
                <?php foreach ($tree as $node) { echo renderDepartmentNode($node, true, null); } ?>
            <?php endif; ?>

            <details style="margin-top:18px;">
                <summary>+ Create top-level department</summary>
                <form method="post" class="form-grid" style="margin-top:10px;">
                    <input type="hidden" name="action" value="create_department">
                    <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
                    <div class="form-group"><label>Code</label><input type="text" name="code"></div>
                    <div class="form-group"><label>Cost Center</label><input type="text" name="cost_center"></div>
                    <div class="form-group"><label>Budget Ceiling</label><input type="number" step="0.01" name="budget_ceiling" required></div>
                    <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Create</button></div>
                </form>
            </details>

            <details style="margin-top:14px;">
                <summary>+ Create sub-department directly</summary>
                <form method="post" class="form-grid" style="margin-top:10px;">
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
                    <div class="form-group"><label>Budget Ceiling</label><input type="number" step="0.01" name="budget_ceiling" required></div>
                    <div class="form-group" style="align-self:end;"><button type="submit" class="btn btn-primary">Create Sub-department</button></div>
                </form>
                <p style="font-size:12px; color:var(--ink-300); margin-top:8px;">Must fit within the parent's remaining (unallocated) ceiling.</p>
            </details>

            <details style="margin-top:14px;">
                <summary>+ Adjust an existing department's ceiling</summary>
                <form method="post" class="form-grid" style="margin-top:10px;">
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
                    <div class="form-group"><label>New Ceiling</label><input type="number" step="0.01" name="new_ceiling" required></div>
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
                            <input type="hidden" name="action" value="decide_sub_department_request">
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <input type="hidden" name="decision" value="approve">
                            <button type="submit" class="btn btn-success btn-sm">Approve</button>
                        </form>
                        <form method="post" style="display:inline;">
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
                            <input type="hidden" name="action" value="approve_borrow">
                            <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                            <button type="submit" class="btn btn-success btn-sm">Approve</button>
                        </form>
                        <form method="post" style="display:inline;">
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
