<?php
/**
 * batches/view.php - ENTERPRISE DISBURSEMENT BATCH DETAILS
 *
 * FIX (fatal): formatCurrency() was called but never defined anywhere
 * in this file's scope — added below, and deliberately does NOT fall
 * back to a guessed currency (see note on the function itself).
 *
 * FIX: submit/approve here now run the same department ration check as
 * imports/review_batch.php. Previously this page was a second door
 * through which a batch could be submitted and approved without its
 * budget ever being verified.
 *
 * FIX: the Execute button used to POST action=execute to a branch that
 * didn't exist — silent no-op, no error shown. Execution is now handled
 * ONLY on imports/review_batch.php, which has the claim-lock /
 * idempotency / resume safety logic. Duplicating that here would
 * recreate the exact class of bug this file just hit (one file patched,
 * one forgotten), so this page links to it instead of re-implementing it.
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
$batchId = $_GET['id'] ?? 0;

// ============================================================
// HELPERS
// ============================================================
function safeHtmlView($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// FIX (fatal error): this was called throughout the file but never
// defined. Deliberately does NOT default to a guessed currency (the
// old pattern elsewhere in this codebase was `?? 'BWP'`) — VouchMorph
// doesn't hold money itself; the currency that actually matters is
// whichever institution's account is being debited or credited, and
// guessing wrong silently mislabels a real amount (an AOA payout
// displayed as if it were BWP is a real operational hazard once you're
// running outside one market, not just a cosmetic bug). If a currency
// is genuinely missing, that's surfaced instead of papered over.
// ============================================================
function formatCurrency($amount, $currency = null) {
    $formatted = number_format((float)$amount, 2);
    if ($currency === null || $currency === '') {
        return $formatted . ' <span style="color:var(--danger); font-size:11px;">⚠ currency missing</span>';
    }
    return $formatted . ' ' . safeHtmlView($currency);
}

// ============================================================
// GET BATCH
// ============================================================
$stmt = $db->prepare("
    SELECT
        b.*,
        u1.full_name as created_by_name,
        u2.full_name as submitted_by_name,
        u3.full_name as reviewed_by_name,
        u4.full_name as approved_by_name,
        u5.full_name as executed_by_name
    FROM disbursement_batches b
    LEFT JOIN users u1 ON b.created_by = u1.user_id
    LEFT JOIN users u2 ON b.submitted_by = u2.user_id
    LEFT JOIN users u3 ON b.reviewed_by = u3.user_id
    LEFT JOIN users u4 ON b.approved_by = u4.user_id
    LEFT JOIN users u5 ON b.executed_by = u5.user_id
    WHERE b.id = :id AND b.organization_id = :org_id
");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

// ============================================================
// CURRENCY RESOLUTION
// ------------------------------------------------------------
// The batch's own `currency` column is set at creation time from the
// SOURCE institution's account (imports/source_input.php) — that's the
// currency the department's ration ceiling is actually denominated in,
// since that's the real-world account being drawn down. It is NOT
// necessarily the currency every destination gets paid in: a destination
// institution can be in a different currency, in which case a forex
// conversion happens between source and destination. Each destination
// row already carries its own `currency` column independently in the
// schema — this page just needs to stop assuming they all match the
// batch's, and flag it clearly when they don't.
// ============================================================
$batchCurrency = $batch['currency'] ?? null;
if (!$batchCurrency) {
    error_log("[batches/view] Batch {$batchId} has no currency set — this should have been populated from the source institution at creation time.");
}

// ============================================================
// GET DESTINATIONS
// ============================================================
$stmt = $db->prepare("
    SELECT * FROM disbursement_destinations
    WHERE batch_id = :batch_id
    ORDER BY destination_index
");
$stmt->execute([':batch_id' => $batchId]);
$destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// DEPARTMENT / RATION CONTEXT
// ============================================================
$deptService = new DepartmentService($db);
$departmentInfo = null;
$rationInfo = null;

if (!empty($batch['department_id'])) {
    try {
        $stmt = $db->prepare("SELECT id, name, code FROM departments WHERE id = :id");
        $stmt->execute([':id' => $batch['department_id']]);
        $departmentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $rationInfo = $deptService->getAvailableRation((int)$batch['department_id']);
    } catch (\Throwable $e) {
        error_log("[batches/view] Failed to load department/ration info: " . $e->getMessage());
    }
}

// ============================================================
// PERMISSIONS
// ============================================================
$role = $user['role'] ?? 'viewer';
$isApprover = in_array($role, ['approver', 'senior_approver']);
$isOwner = ($role === 'owner');
$isCreator = ($batch['created_by'] == $userId);
$isProgramOfficer = in_array($role, ['program_officer', 'department_head']);

$canApprove = ($isApprover && $batch['status'] === 'pending_approval');
$canReject = ($isApprover && $batch['status'] === 'pending_approval');
$canEdit = ($isOwner || ($isProgramOfficer && $isCreator && $batch['status'] === 'draft'));
$canSubmit = ($isOwner || ($isProgramOfficer && $isCreator && $batch['status'] === 'draft'));
// Execute/DISBURSE: OWNER ONLY — enforced (for real) on review_batch.php.
// This flag here only controls whether we show the "Go Execute" link.
$canExecute = ($isOwner && $batch['status'] === 'approved');

// ============================================================
// DEPARTMENT SCOPE — same rule as review_batch.php: a department-scoped
// approver/owner (organization_users.department_id set) can only act on
// batches in their own department or its sub-departments. NULL means
// unrestricted (deliberate HQ-level configuration). This must match
// review_batch.php exactly, or the two pages disagree about who can act
// on the same batch — precisely the class of bug this session started
// with (formatCurrency existing on one page, missing on the other).
// ============================================================
$actingUserDepartmentId = $user['department_id'] ?? null;
$batchDepartmentId = $batch['department_id'] ?? null;
$inDeptScope = $deptService->isDepartmentInScope($actingUserDepartmentId, $batchDepartmentId);
$scopeDeniedMessage = '';
if (!$inDeptScope) {
    $canApprove = false;
    $canReject = false;
    $canExecute = false;
    $scopeDeniedMessage = $batchDepartmentId === null
        ? 'This batch has no department assigned, so a department-scoped account cannot act on it.'
        : 'This batch belongs to a different department than the one assigned to your account.';
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$actionResult = null;
$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'approve' && $canApprove) {
            // ============================================================
            // FIX: ration re-check before approving — this page used to
            // let an approver approve a batch that would blow its
            // department's budget, because only review_batch.php checked
            // this. Same rule, same place it's enforced everywhere else.
            // ============================================================
            if (empty($batch['department_id'])) {
                throw new Exception("This batch has no department assigned — cannot verify budget ration before approving.");
            }
            try {
                $deptService->assertBatchFitsRation(
                    (int)$batch['department_id'],
                    (float)($batch['total_amount'] ?? 0)
                );
            } catch (\RuntimeException $e) {
                throw new Exception("🚫 This batch no longer fits its department's ration: " . $e->getMessage());
            }

            $stmt = $db->prepare("
                UPDATE disbursement_batches
                SET status = 'approved',
                    approved_by = :user_id,
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id AND status = 'pending_approval'
                RETURNING *
            ");
            $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($updated) {
                $batch = array_merge($batch, $updated);
                $actionResult = "✅ Batch approved successfully!";

                $logStmt = $db->prepare("
                    INSERT INTO organization_audit_logs
                    (organization_id, user_id, action, entity_type, entity_id, new_values, ip_address, user_agent, created_at)
                    VALUES (:org_id, :user_id, 'APPROVE_BATCH', 'disbursement_batch', :batch_id, :values, :ip, :ua, NOW())
                ");
                $logStmt->execute([
                    ':org_id' => $orgId,
                    ':user_id' => $userId,
                    ':batch_id' => $batchId,
                    ':values' => json_encode(['status' => 'approved', 'approved_by' => $userId]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } else {
                throw new Exception("Batch may have been already processed.");
            }

        } elseif ($action === 'reject' && $canReject) {
            $reason = $_POST['rejection_reason'] ?? 'No reason provided';

            $stmt = $db->prepare("
                UPDATE disbursement_batches
                SET status = 'rejected',
                    rejection_reason = :reason,
                    reviewed_by = :user_id,
                    reviewed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id AND status = 'pending_approval'
                RETURNING *
            ");
            $stmt->execute([
                ':reason' => $reason,
                ':user_id' => $userId,
                ':id' => $batchId
            ]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($updated) {
                $batch = array_merge($batch, $updated);
                $actionResult = "❌ Batch rejected.";

                $logStmt = $db->prepare("
                    INSERT INTO organization_audit_logs
                    (organization_id, user_id, action, entity_type, entity_id, new_values, ip_address, user_agent, created_at)
                    VALUES (:org_id, :user_id, 'REJECT_BATCH', 'disbursement_batch', :batch_id, :values, :ip, :ua, NOW())
                ");
                $logStmt->execute([
                    ':org_id' => $orgId,
                    ':user_id' => $userId,
                    ':batch_id' => $batchId,
                    ':values' => json_encode(['status' => 'rejected', 'reason' => $reason]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } else {
                throw new Exception("Batch may have been already processed.");
            }

        } elseif ($action === 'submit' && $canSubmit) {
            // Same ration check as review_batch.php's submit_for_approval.
            if (empty($batch['department_id'])) {
                throw new Exception("This batch has no department assigned, so its budget ration can't be checked. Contact an admin before submitting.");
            }
            try {
                $deptService->assertBatchFitsRation(
                    (int)$batch['department_id'],
                    (float)($batch['total_amount'] ?? 0)
                );
            } catch (\RuntimeException $e) {
                throw new Exception("🚫 " . $e->getMessage());
            }

            $stmt = $db->prepare("
                UPDATE disbursement_batches
                SET status = 'pending_approval',
                    submitted_by = :user_id,
                    submitted_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id AND status = 'draft'
                RETURNING *
            ");
            $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($updated) {
                $batch = array_merge($batch, $updated);
                $actionResult = "📤 Batch submitted for approval.";
            } else {
                throw new Exception("Batch may have been already submitted.");
            }
        } elseif ($action === 'execute') {
            // Deliberately not handled here — see file header. Anyone
            // POSTing this directly gets an explicit message instead of
            // the old silent no-op.
            throw new Exception("Execution isn't performed from this page. Go to the Review page to execute — it has the duplicate-prevention and resume safety checks a disbursement needs.");
        }
    } catch (Exception $e) {
        $actionError = $e->getMessage();
    }

    // Refresh ration info since an approve/submit above may have changed it.
    if (!empty($batch['department_id'])) {
        try {
            $rationInfo = $deptService->getAvailableRation((int)$batch['department_id']);
        } catch (\Throwable $e) {
            error_log("[batches/view] Failed to refresh ration info: " . $e->getMessage());
        }
    }
}

// ============================================================
// GET STATS
// ============================================================
$successCount = count(array_filter($destinations, fn($d) => in_array(strtolower($d['status'] ?? ''), ['success', 'completed'])));
$failedCount = count(array_filter($destinations, fn($d) => strtolower($d['status'] ?? '') === 'failed'));
$pendingCount = count(array_filter($destinations, fn($d) => strtolower($d['status'] ?? '') === 'pending'));

$csrfToken = generateCsrfToken();
$statusClass = match(strtolower($batch['status'] ?? 'draft')) {
    'draft' => 'draft',
    'pending_approval' => 'pending_approval',
    'approved' => 'approved',
    'executing' => 'pending_approval',
    'completed', 'executed' => 'completed',
    'partial_success' => 'pending_approval',
    'failed' => 'rejected',
    'rejected' => 'rejected',
    default => 'draft'
};

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
$userRole = $role;
$basePath = '../';
$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$isDepartmentHead = ($userRole === 'department_head');
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head'], true);
$navCanApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise'], true);
$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true);
$canSeeSourceAccountsArea = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer'], true);
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer'], true);
$canManageDepartments = $isTopRole;
$setupReady = true; // this page cannot render without an existing batch

$navPendingApprovals = 0;
$navPendingSourceConfirmations = 0;
try {
    if ($navCanApprove) {
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
    error_log("[batches/view] Nav badge query error: " . $e->getMessage());
}

$navItems = [
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => '../index.php', 'show' => true],
    ['key' => 'disbursements', 'icon' => 'wallet', 'label' => 'Disbursements', 'href' => 'index.php?status=all', 'show' => true, 'active' => true, 'badge' => ($navPendingApprovals > 0 && $navCanApprove) ? $navPendingApprovals : null],
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
$topbarSearchShow = $canTrace;
$topbarSearchAction = '../index.php';
$topbarSearchName = 'trace';
$topbarSearchPlaceholder = 'Search batch reference, phone, national ID…';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Details · VOUCHMORPH Enterprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../partials/shell.css">
    <style>
        /* This page's own status wording ("pending_approval") doesn't
           match shell.css's shorter "pending" tone class name — rather
           than touch the existing $statusClass PHP logic, just add the
           one missing tone mapping locally. */
        .status.status-pending_approval { background: var(--amber-bg); color: var(--amber); }
        .currency-note { font-size:12px; color:var(--ink-500); margin-top:10px; padding-top:10px; border-top:1px dashed var(--line); }
        .currency-note strong { color:var(--ink-900); }
        .fx-flag { display:inline-block; margin-left:6px; padding:1px 7px; font-size:10px; background:var(--amber-bg); color:var(--amber); font-family:var(--f-cond); font-weight:700; letter-spacing:.03em; }
        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .detail-item { padding:6px 0; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; gap:10px; }
        .detail-item .label { color:var(--ink-500); font-weight:500; font-size:13px; }
        .detail-item .value { font-weight:600; font-size:13px; text-align:right; }
        .ration-panel { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line); }
        .ration-panel .ration-title { font-family: var(--f-cond); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-500); margin-bottom: 10px; }
        .ration-bar-track { height: 8px; background: var(--paper); border: 1px solid var(--line); margin-bottom: 10px; }
        .ration-bar-fill { height: 100%; }
        .ration-stats { display: flex; gap: 20px; flex-wrap: wrap; font-size: 12.5px; color: var(--ink-500); }
        .ration-stats strong { color: var(--ink-900); }
        .ration-stats .danger strong { color: var(--danger); }
        .ration-stats .ok strong { color: var(--ledger-green); }
        .amt { font-family:var(--f-mono); font-weight:600; }
        .status-badge-sm { padding:2px 10px; font-size:10px; font-weight:600; text-transform:uppercase; font-family:var(--f-cond); letter-spacing:.04em; display:inline-block; }
        .status-badge-sm.success { background:var(--green-tint); color:var(--ledger-green); }
        .status-badge-sm.failed { background:var(--danger-bg); color:var(--danger); }
        .status-badge-sm.pending { background:var(--amber-bg); color:var(--amber); }
        .status-badge-sm.completed { background:var(--green-tint); color:var(--ledger-green); }
        .rejection-form { display:none; margin-top:12px; padding:16px; background:var(--danger-bg); }
        .rejection-form.show { display:block; }
        .rejection-form textarea { width:100%; padding:10px; border:1px solid var(--line); min-height:80px; font-family:var(--f-body); font-size:13px; background:var(--panel); box-sizing:border-box; }
        .rejection-form textarea:focus { outline:2px solid var(--brass); outline-offset:1px; }
        .actions-bar { display:flex; gap:12px; flex-wrap:wrap; margin-top:12px; align-items:center; }
        @media (max-width:768px) { .grid-2 { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../partials/shell-head.php'; ?>
            <div class="page-header">
                <div>
                    <h1>Batch Details</h1>
                    <div class="sub"><span style="font-family:var(--f-mono);"><?php echo safeHtml($batch['batch_reference']); ?></span></div>
                </div>
                <div class="page-header-actions">
                    <span style="font-size:11px; color:var(--ink-300); font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em; align-self:center;"><?php echo safeHtml(getRoleLabel($role)); ?></span>
                </div>
            </div>

            <?php if ($actionResult): ?>
            <div class="info-panel" style="border-left-color: var(--ledger-green); background: var(--green-tint);">
                <div class="label" style="color:var(--ledger-green);"><?php echo safeHtmlView($actionResult); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($actionError): ?>
            <div class="info-panel" style="border-left-color: var(--seal-red); background: var(--danger-bg);">
                <div class="label" style="color:var(--danger);"><?php echo $actionError; ?></div>
            </div>
            <?php endif; ?>

            <?php if ($isApprover && $batch['status'] === 'pending_approval'): ?>
            <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg);">
                <div class="label" style="color:var(--amber);">🔑 Approval Required</div>
                <div class="desc">This batch is pending your review. Please verify all details below before approving or rejecting.</div>
            </div>
            <?php endif; ?>

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Amount (Source Currency)</div>
                    <div class="stat-value"><?php echo formatCurrency($batch['total_amount'] ?? 0, $batchCurrency); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Recipients</div>
                    <div class="stat-value"><?php echo $batch['total_destinations'] ?? 0; ?></div>
                </div>
                <div class="stat-card accent-green">
                    <div class="stat-label">✅ Successful</div>
                    <div class="stat-value" style="color:var(--ledger-green);"><?php echo $successCount; ?></div>
                </div>
                <div class="stat-card accent-danger">
                    <div class="stat-label">❌ Failed</div>
                    <div class="stat-value" style="color:var(--danger);"><?php echo $failedCount; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Batch Details</span>
                    <span class="status status-<?php echo $statusClass; ?>">
                        <?php echo strtoupper(str_replace('_', ' ', $batch['status'] ?? 'DRAFT')); ?>
                    </span>
                </div>
                <div class="grid-2">
                    <div class="detail-item"><span class="label">Batch Reference</span><span class="value"><?php echo safeHtmlView($batch['batch_reference']); ?></span></div>
                    <div class="detail-item"><span class="label">Batch Name</span><span class="value"><?php echo safeHtmlView($batch['batch_name']); ?></span></div>
                    <div class="detail-item"><span class="label">Source Institution</span><span class="value"><?php echo safeHtmlView($batch['source_institution']); ?></span></div>
                    <div class="detail-item"><span class="label">Source Identifier</span><span class="value"><?php echo safeHtmlView($batch['source_identifier']); ?></span></div>
                    <div class="detail-item"><span class="label">Source Asset Type</span><span class="value"><?php echo safeHtmlView($batch['source_asset_type'] ?? 'ACCOUNT'); ?></span></div>
                    <div class="detail-item"><span class="label">Source Currency</span><span class="value"><?php echo $batchCurrency ? safeHtmlView($batchCurrency) : '<span style="color:var(--danger);">⚠ not set</span>'; ?></span></div>
                    <div class="detail-item"><span class="label">Created By</span><span class="value"><?php echo safeHtmlView($batch['created_by_name'] ?? 'N/A'); ?></span></div>
                    <div class="detail-item"><span class="label">Created At</span><span class="value"><?php echo date('Y-m-d H:i', strtotime($batch['created_at'])); ?></span></div>
                    <div class="detail-item"><span class="label">Department</span><span class="value"><?php echo $departmentInfo ? safeHtmlView($departmentInfo['name']) : '<span style="color:var(--danger);">Not assigned</span>'; ?></span></div>
                    <?php if ($batch['submitted_by_name']): ?>
                    <div class="detail-item"><span class="label">Submitted By</span><span class="value"><?php echo safeHtmlView($batch['submitted_by_name']); ?></span></div>
                    <div class="detail-item"><span class="label">Submitted At</span><span class="value"><?php echo date('Y-m-d H:i', strtotime($batch['submitted_at'])); ?></span></div>
                    <?php endif; ?>
                    <?php if ($batch['approved_by_name']): ?>
                    <div class="detail-item"><span class="label">Approved By</span><span class="value"><?php echo safeHtmlView($batch['approved_by_name']); ?></span></div>
                    <div class="detail-item"><span class="label">Approved At</span><span class="value"><?php echo date('Y-m-d H:i', strtotime($batch['approved_at'])); ?></span></div>
                    <?php endif; ?>
                    <?php if ($batch['executed_by_name']): ?>
                    <div class="detail-item"><span class="label">Executed By</span><span class="value"><?php echo safeHtmlView($batch['executed_by_name']); ?></span></div>
                    <div class="detail-item"><span class="label">Executed At</span><span class="value"><?php echo date('Y-m-d H:i', strtotime($batch['executed_at'])); ?></span></div>
                    <?php endif; ?>
                    <?php if ($batch['rejection_reason']): ?>
                    <div class="detail-item" style="grid-column:1/-1; background:var(--danger-bg); padding:12px; border:1px solid var(--danger);">
                        <span class="label" style="color:var(--danger);">Rejection Reason</span>
                        <span class="value" style="color:var(--danger);"><?php echo safeHtmlView($batch['rejection_reason']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="currency-note">
                    💱 <strong>Currency note:</strong> the amount above is in this batch's <strong>source institution's</strong> currency
                    — that's what your department's budget ration is checked against. Individual destinations may pay out in a
                    different currency; those are marked <span class="fx-flag">⇄ FX</span> in the table below.
                </div>

                <?php if ($rationInfo): ?>
                <?php
                    $utilPct = $rationInfo['ceiling'] > 0
                        ? min(100, round((($rationInfo['disbursed_ytd'] + $rationInfo['reserved_in_flight']) / $rationInfo['ceiling']) * 100, 1))
                        : 0;
                    $barColor = $rationInfo['available'] < 0 ? 'var(--danger)' : ($utilPct >= 80 ? 'var(--amber)' : 'var(--ledger-green)');
                ?>
                <div class="ration-panel">
                    <div class="ration-title">Department Ration<?php echo $departmentInfo ? ' — ' . safeHtmlView($departmentInfo['name']) : ''; ?></div>
                    <div class="ration-bar-track"><div class="ration-bar-fill" style="width:<?php echo $utilPct; ?>%; background:<?php echo $barColor; ?>;"></div></div>
                    <div class="ration-stats">
                        <span>Ceiling: <strong><?php echo formatCurrency($rationInfo['ceiling'], $rationInfo['currency'] ?? $batchCurrency); ?></strong></span>
                        <span>Disbursed YTD: <strong><?php echo formatCurrency($rationInfo['disbursed_ytd'], $rationInfo['currency'] ?? $batchCurrency); ?></strong></span>
                        <span>Reserved: <strong><?php echo formatCurrency($rationInfo['reserved_in_flight'], $rationInfo['currency'] ?? $batchCurrency); ?></strong></span>
                        <span class="<?php echo $rationInfo['available'] < 0 ? 'danger' : 'ok'; ?>">Available: <strong><?php echo formatCurrency($rationInfo['available'], $rationInfo['currency'] ?? $batchCurrency); ?></strong></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Destinations (<?php echo count($destinations); ?>)</span>
                    <?php if ($failedCount > 0): ?>
                    <span style="color:var(--danger); font-weight:600; font-family:var(--f-cond);">⚠️ <?php echo $failedCount; ?> failed</span>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Beneficiary</th>
                                <th>Identifier</th>
                                <th>Amount</th>
                                <th>Reference</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($destinations as $dest):
                                $destCurrency = $dest['currency'] ?? null;
                                $isFx = $batchCurrency && $destCurrency && $destCurrency !== $batchCurrency;
                            ?>
                            <tr>
                                <td><?php echo $dest['destination_index']; ?></td>
                                <td><?php echo safeHtmlView($dest['beneficiary_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php
                                    if ($dest['is_identity_recipient'] ?? false) {
                                        echo safeHtmlView(($dest['identity_type'] ?? 'identity') . ': ' . ($dest['identity_value'] ?? ''));
                                    } else {
                                        echo safeHtmlView($dest['identifier']);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="amt"><?php echo formatCurrency($dest['amount'], $destCurrency); ?></span>
                                    <?php if ($isFx): ?>
                                    <span class="fx-flag" title="Paid in <?php echo safeHtmlView($destCurrency); ?>, drawn from a <?php echo safeHtmlView($batchCurrency); ?> source — forex applies.">⇄ FX</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo safeHtmlView($dest['hold_reference'] ?? $dest['transaction_reference'] ?? '-'); ?></td>
                                <td>
                                    <?php
                                    $destStatus = strtolower($dest['status'] ?? 'pending');
                                    $destStatusClass = match($destStatus) {
                                        'success', 'completed' => 'success',
                                        'failed' => 'failed',
                                        default => 'pending'
                                    };
                                    ?>
                                    <span class="status-badge-sm <?php echo $destStatusClass; ?>"><?php echo strtoupper($dest['status'] ?? 'PENDING'); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($destinations)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <div class="icon">§</div>
                                        <p>No destinations added yet</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Actions</span>
                </div>
                <?php if ($scopeDeniedMessage && $isApprover): ?>
                <div class="info-panel" style="border-left-color: var(--brass); margin-bottom:14px;">
                    <div class="desc">🔒 <?php echo safeHtmlView($scopeDeniedMessage); ?></div>
                </div>
                <?php endif; ?>
                <div class="actions-bar">
                    <?php if ($batch['status'] === 'draft' && $canSubmit): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlView($csrfToken); ?>">
                        <input type="hidden" name="action" value="submit">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this batch for approval?')">
                            📤 Submit for Approval
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($batch['status'] === 'pending_approval' && $canApprove): ?>
                    <form method="POST" onsubmit="return confirm('Approve this batch?')">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlView($csrfToken); ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success">✅ Approve</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($batch['status'] === 'pending_approval' && $canReject): ?>
                    <button class="btn btn-danger" onclick="toggleRejection()">❌ Reject</button>
                    <div class="rejection-form" id="rejectionForm">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo safeHtmlView($csrfToken); ?>">
                            <input type="hidden" name="action" value="reject">
                            <div class="form-group">
                                <label>Rejection Reason</label>
                                <textarea name="rejection_reason" required placeholder="Please provide a reason for rejecting this batch..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger">Submit Rejection</button>
                            <button type="button" class="btn btn-outline" onclick="toggleRejection()" style="margin-left:8px;">Cancel</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Execute now links to the hardened flow instead of a local (previously broken) handler -->
                    <?php if ($batch['status'] === 'approved' && $canExecute): ?>
                    <a href="../imports/review_batch.php?batch_id=<?php echo $batchId; ?>" class="btn btn-danger" style="font-weight:700; font-size:14px; padding:10px 32px;">
                        🚀 Go Execute Disbursement
                    </a>
                    <span style="font-size:12px; color:var(--ink-500);">Executed from the Review page, with duplicate-prevention and resume safety.</span>
                    <?php elseif (in_array($batch['status'], ['executing', 'partial_success', 'failed'])): ?>
                    <a href="../imports/review_batch.php?batch_id=<?php echo $batchId; ?>" class="btn btn-outline">🔍 View Execution Status / Resume</a>
                    <?php endif; ?>

                    <?php if ($batch['status'] === 'draft' && $canEdit): ?>
                    <a href="../imports/add_destinations.php?batch_id=<?php echo $batchId; ?>" class="btn btn-outline">✏️ Edit Destinations</a>
                    <?php endif; ?>

                    <a href="index.php" class="btn btn-outline">📋 All Batches</a>
                </div>
            </div>

    <script>
        function toggleRejection() {
            document.getElementById('rejectionForm').classList.toggle('show');
        }
    </script>
<?php
$dbHealthy = DBConnection::isConnected();
$footerStatusLine = 'LEDGER SYNC: ' . ($dbHealthy ? '<span class="ok">OK</span>' : '<span class="bad">DEGRADED</span>');
require __DIR__ . '/../partials/shell-foot.php';
?>
</body>
</html>
