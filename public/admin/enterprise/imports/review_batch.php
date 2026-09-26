<?php
// enterprise/imports/review_batch.php - Review and approve batch
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// ============================================================
// LOAD SWAPSERVICE AND DEPENDENCIES
// ============================================================
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/DepartmentService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/UserManagementService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/BatchExecutionQueueService.php';
require_once __DIR__ . '/../../../../src/Core/Config/LoadCountry.php';

use Domain\Services\SwapService;
use Domain\Services\DepartmentService;
use Domain\Services\UserManagementService;
use Domain\Services\BatchExecutionQueueService;
use Core\Config\LoadCountry;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;
$batchId = $_GET['batch_id'] ?? 0;

$error = '';
$success = '';
$failedDestinations = [];

// How long a batch may sit in 'executing' before we treat it as stuck
// (crashed/timed-out request) rather than genuinely still running, and
// offer the Owner a manual Resume. See the P0 fix notes below the
// execute action for why resuming is now safe.
const EXECUTING_STUCK_THRESHOLD_SECONDS = 600; // 10 minutes

// ============================================================
// HELPER: Check if user can edit this batch
// ============================================================
function canEditBatch($batchCreatedBy, $currentUserId, $userRole) {
    if ($userRole === 'owner') return true;
    if (in_array($userRole, ['program_officer', 'department_head'])) {
        return $batchCreatedBy == $currentUserId;
    }
    return false;
}

function safeHtmlRb($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount, $currency = 'BWP') {
    return number_format((float)$amount, 2) . ' ' . $currency;
}

// A destination counts as "not yet attempted" only if it has no status
// or is still the default PENDING. Anything else (SUCCESS, COMPLETED,
// PENDING_IDENTITY_CONFIRMATION, FAILED) has already had a real attempt
// made against it and must never be silently re-run by Execute/Resume —
// FAILED ones are retried explicitly via retry_destination.php instead,
// exactly like the existing single-destination Retry button already
// does, so we don't invent a second, less careful retry path here.
function destinationNotYetAttempted($dest) {
    $s = strtoupper(trim((string)($dest['status'] ?? 'PENDING')));
    return $s === '' || $s === 'PENDING';
}

// ============================================================
// GET BATCH / DESTINATIONS (as functions so we can cheaply re-fetch
// after mutating actions, instead of trusting stale in-memory arrays)
// ============================================================
function loadBatch(PDO $db, $batchId, $orgId) {
    $stmt = $db->prepare("
        SELECT b.*,
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
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function loadDestinations(PDO $db, $batchId): array {
    $stmt = $db->prepare("
        SELECT * FROM disbursement_destinations
        WHERE batch_id = :batch_id
        ORDER BY destination_index
    ");
    $stmt->execute([':batch_id' => $batchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$batch = loadBatch($db, $batchId, $orgId);

if (!$batch) {
    die("Batch not found.");
}

// ============================================================
// CHECK PERMISSIONS - EXPLICIT ROLE-BASED
// ============================================================
$role = $user['role'] ?? 'viewer';
$isOwnBatch = ($batch['created_by'] == $userId);
$canEdit = canEditBatch($batch['created_by'], $userId, $role);
$isReadOnly = !$canEdit;

// ============================================================
// EXPLICIT ROLE PERMISSIONS - CLEAR AND UNAMBIGUOUS
// ============================================================
$canSubmit = in_array($role, ['owner', 'program_officer', 'department_head']) && $canEdit;
$canApprove = in_array($role, ['approver', 'senior_approver']);

// Execute/DISBURSE: OWNER ONLY - NO EXCEPTIONS
$canExecute = ($role === 'owner');
if ($role === 'approver' || $role === 'senior_approver') {
    $canExecute = false;
    $canApprove = true;
}

$isApprover = ($role === 'approver' || $role === 'senior_approver');
$executeDisabled = !$canExecute;
$executeDisabledReason = '';

if ($isApprover) {
    $executeDisabledReason = 'Approvers cannot execute disbursements. Only Owners can disburse funds.';
} elseif ($role === 'program_officer' || $role === 'department_head') {
    $executeDisabledReason = 'Program Officers and Department Heads cannot execute disbursements. Only Owners can disburse funds.';
} elseif ($role === 'viewer') {
    $executeDisabledReason = 'Viewers cannot execute disbursements.';
}

// ============================================================
// DEPARTMENT / RATION CONTEXT
// ============================================================
$deptService = new DepartmentService($db);
$executionQueue = new BatchExecutionQueueService($db);
$departmentInfo = null;
$rationInfo = null;

if (!empty($batch['department_id'])) {
    try {
        $stmt = $db->prepare("SELECT id, name, code FROM departments WHERE id = :id");
        $stmt->execute([':id' => $batch['department_id']]);
        $departmentInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        $rationInfo = $deptService->getAvailableRation((int)$batch['department_id']);
    } catch (\Throwable $e) {
        error_log("[review_batch] Failed to load department/ration info: " . $e->getMessage());
    }
}

// ============================================================
// DEPARTMENT SCOPE — narrows approve/execute past the role check above.
// organization_users.department_id = NULL on an owner/approver/
// senior_approver account means that account is deliberately
// unrestricted (an HQ-level signatory) — this is the "some roles can
// see all departments" case. A department-scoped account of the same
// role can only approve/execute batches inside its own department or
// its sub-departments (ministry -> department -> sub-department). This
// never loosens anything the role check above already forbids — an
// approver still can never execute, regardless of scope — it only ever
// narrows further.
// ============================================================
$actingUserDepartmentId = $user['department_id'] ?? null;
$batchDepartmentId = $batch['department_id'] ?? null;
$inDeptScope = $deptService->isDepartmentInScope($actingUserDepartmentId, $batchDepartmentId);

if (!$inDeptScope) {
    if ($canApprove) {
        $canApprove = false;
    }
    if ($canExecute) {
        $canExecute = false;
    }
    $executeDisabledReason = $batchDepartmentId === null
        ? 'This batch has no department assigned, so a department-scoped account cannot act on it. Contact an admin to assign one.'
        : 'This batch belongs to a different department than the one assigned to your account.';
}

// ============================================================
// GET DESTINATIONS
// ============================================================
$destinations = loadDestinations($db, $batchId);

// ============================================================
// HANDLE ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'execute' && !$canExecute) {
        $error = "🚫 SECURITY BLOCK: You do not have permission to execute this disbursement. Only Owners can disburse funds.";
        error_log("[SECURITY] User " . ($userId ?? 'unknown') . " (role: $role) attempted to execute batch $batchId without permission");
    } elseif ($isReadOnly && !in_array($action, ['approve', 'reject', 'execute'])) {
        $error = "You cannot modify this batch. It was created by another user.";
    } else {
        if ($action === 'submit_for_approval') {
            // RATION CHECK — gates the draft -> pending_approval transition.
            if (empty($batch['department_id'])) {
                $error = "This batch has no department assigned, so its budget ration can't be checked. Contact an admin before submitting.";
            } else {
                try {
                    $deptService->assertBatchFitsRation(
                        (int)$batch['department_id'],
                        (float)($batch['total_amount'] ?? 0)
                    );
                } catch (\RuntimeException $e) {
                    $error = "🚫 " . $e->getMessage()
                        . ' <a href="../departments/index.php" style="color:var(--brass); font-weight:600;">Request a ration borrow →</a>';
                }
            }

            if (!$error) {
                $stmt = $db->prepare("
                    UPDATE disbursement_batches
                    SET status = 'pending_approval',
                        submitted_by = :user_id,
                        submitted_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id AND LOWER(status) = 'draft'
                ");
                $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
                $success = "Batch submitted for approval.";
            }

        } elseif ($action === 'approve') {
            // RATION RE-CHECK at approval time too — ration is live.
            if (empty($batch['department_id'])) {
                $error = "This batch has no department assigned — cannot verify budget ration before approving.";
            } else {
                try {
                    $deptService->assertBatchFitsRation(
                        (int)$batch['department_id'],
                        (float)($batch['total_amount'] ?? 0)
                    );
                } catch (\RuntimeException $e) {
                    $error = "🚫 This batch no longer fits its department's ration: " . $e->getMessage()
                        . ' <a href="../departments/index.php" style="color:var(--brass); font-weight:600;">Review ration →</a>';
                }
            }

            if (!$error) {
                $stmt = $db->prepare("
                    UPDATE disbursement_batches
                    SET status = 'approved',
                        approved_by = :user_id,
                        approved_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id AND LOWER(status) = 'pending_approval'
                ");
                $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
                $success = "Batch approved.";
            }

        } elseif ($action === 'reject') {
            $reason = $_POST['rejection_reason'] ?? 'No reason provided';
            $stmt = $db->prepare("
                UPDATE disbursement_batches
                SET status = 'rejected',
                    rejection_reason = :reason,
                    reviewed_by = :user_id,
                    reviewed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':reason' => $reason, ':user_id' => $userId, ':id' => $batchId]);
            $success = "Batch rejected.";

        } elseif ($action === 'execute') {
            // ============================================================
            // ASYNC EXECUTION — replaces the old synchronous "loop every
            // destination inline inside this one HTTP request" path. That
            // approach could not survive real batch sizes (a batch of
            // thousands to millions of destinations will exceed PHP's
            // execution time limit long before finishing, leaving the
            // batch stuck mid-run with no way to know how far it got).
            //
            // Execution is now: explode the batch into one durable job
            // per destination (BatchExecutionQueueService::enqueueBatch),
            // and let independent worker.php processes drain that queue
            // — see 006_batch_execution_queue_migration.sql and
            // worker.php. This request's only job is to enqueue and
            // return immediately; per-destination status, transaction
            // references, and department ration bookkeeping are now
            // updated incrementally by the workers as each job completes,
            // not all at once here.
            //
            // enqueueBatch() is itself safe against double-clicks/
            // concurrent requests (it locks the batch row and only
            // creates jobs for destinations that don't already have one),
            // so the separate atomic claim-lock this file used to do
            // inline is now handled there instead.
            // ============================================================
            try {
                $created = $executionQueue->enqueueBatch((int)$batchId);
                $success = $created > 0
                    ? "🚀 Batch queued for execution — {$created} destination(s) enqueued. Refresh this page to see live progress as workers process them."
                    : "This batch's destinations were already queued or resolved on a previous attempt — nothing new to enqueue. Refresh to see current status.";
            } catch (\RuntimeException $e) {
                $error = "❌ " . $e->getMessage();
                error_log("[review_batch] enqueueBatch failed for batch {$batchId}: " . $e->getMessage());
            }

        } elseif ($action === 'retry_failed_jobs') {
            if (!$canExecute) {
                $error = "🚫 Only the Owner can retry failed destinations.";
            } else {
                $retried = $executionQueue->retryFailedJobs((int)$batchId);
                $success = $retried > 0
                    ? "🔁 {$retried} failed destination(s) requeued for another attempt."
                    : "No permanently-failed destinations found to retry.";
            }
        }
    }

    // Refresh everything from DB before rendering — the enqueue action,
    // status transitions, and department bookkeeping above changed rows
    // out from under the snapshots loaded at the top of the page.
    $refreshedBatch = loadBatch($db, $batchId, $orgId);
    if ($refreshedBatch) {
        $batch = $refreshedBatch;
    }
    $destinations = loadDestinations($db, $batchId);

    if (!empty($batch['department_id'])) {
        try {
            $rationInfo = $deptService->getAvailableRation((int)$batch['department_id']);
        } catch (\Throwable $e) {
            error_log("[review_batch] Failed to refresh ration info: " . $e->getMessage());
        }
    }
}

$csrfToken = generateCsrfToken();
$roleDisplay = UserManagementService::ROLE_CATALOG[$role]['label'] ?? strtoupper($role);
$roleDisplay = strtoupper($roleDisplay);
$status = strtolower($batch['status'] ?? 'draft');

// ============================================================
// QUEUE PROGRESS — replaces the old single-request "stuck after 10
// minutes" heuristic. With async execution, 'executing' is the NORMAL
// state for as long as workers are still draining the queue — for a
// million-destination batch that could genuinely be hours, not a sign
// of anything wrong. The real signal is whether jobs are still moving,
// which getBatchProgress() reports directly instead of guessing from a
// timestamp.
// ============================================================
$queueProgress = null;
if (in_array($status, ['executing', 'partially_completed', 'completed'], true)) {
    try {
        $queueProgress = $executionQueue->getBatchProgress((int)$batchId);
    } catch (\Throwable $e) {
        error_log("[review_batch] Failed to get queue progress: " . $e->getMessage());
    }
}
$hasFailedJobs = $queueProgress && (int)($queueProgress['permanently_failed'] ?? 0) > 0;

// FINAL SAFETY CHECK: Approvers should NEVER see Execute button
$showExecuteButton = ($status === 'approved' && $canExecute && !$isApprover);
$showRetryFailedButton = ($hasFailedJobs && $canExecute && !$isApprover);

// ============================================================
// NEW, DISPLAY-ONLY completeness check for the recipient manifest table
// (mockup's "VALID"/"REVIEW" badges). No such field exists server-side —
// this is purely a client-facing data-completeness hint computed from
// already-loaded row data. It must NEVER be wired into canSubmit/
// canApprove/canExecute/showExecuteButton or any POST handler above —
// a batch with REVIEW-flagged rows stays exactly as submittable/
// approvable/executable as it is today.
// ============================================================
function destinationCompletenessLabel(array $dest): array {
    $hasName = trim((string)($dest['beneficiary_name'] ?? '')) !== '';
    $hasAmount = (float)($dest['amount'] ?? 0) > 0;
    $hasIdentifier = trim((string)($dest['identifier'] ?? '')) !== '';
    return ($hasName && $hasAmount && $hasIdentifier)
        ? ['label' => 'VALID', 'tone' => 'completed']
        : ['label' => 'REVIEW', 'tone' => 'pending'];
}

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
function getStatusClass($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => 'draft',
        'pending', 'pending_approval' => 'pending',
        'approved' => 'approved',
        'executing' => 'pending',
        'partially_completed', 'partial_success' => 'pending',
        'completed', 'executed' => 'completed',
        'rejected' => 'rejected',
        'cancelled', 'failed' => 'rejected',
        default => 'draft'
    };
}
function getStatusLabel($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => '📝 Draft',
        'pending', 'pending_approval' => '⏳ Pending',
        'approved' => '✅ Approved',
        'executing' => '⚙️ Executing',
        'partially_completed', 'partial_success' => '⚠️ Partially completed',
        'completed' => '✔️ Completed',
        'executed' => '🚀 Executed',
        'rejected' => '❌ Rejected',
        'cancelled' => '🚫 Cancelled',
        'failed' => '❌ Failed',
        default => ucfirst($status)
    };
}
// Destination-level statuses (SUCCESS/FAILED/PENDING_IDENTITY_CONFIRMATION/
// PROCESSING) don't match the batch-level vocabulary above, so they get
// their own small mapping onto the same 5 shell.css tones.
function destStatusClass($status) {
    $status = strtoupper((string)$status);
    return match($status) {
        'SUCCESS', 'COMPLETED' => 'completed',
        'FAILED' => 'rejected',
        'PENDING_IDENTITY_CONFIRMATION' => 'approved',
        'PENDING', 'PROCESSING' => 'pending',
        default => 'draft',
    };
}

// ============================================================
// SHARED SHELL SETUP — same contract as index.php/departments/index.php,
// so this page's nav is generated by the exact same code, not a
// hand-copied lookalike.
//
// IMPORTANT: this page already has its own $canApprove above (line
// ~122), deliberately NARROW — it gates whether THIS user can approve
// THIS batch. The sidebar badge needs the dashboard's BROADER formula
// (an owner/it_manager_enterprise should still see how many batches
// org-wide are pending, even though their own $canApprove here is
// false). Reusing the narrow one would under-count for those roles, so
// this is a deliberately separate variable, not a rename.
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

// Unlike source_input.php, this page cannot render at all without an
// existing batch (see the die() above) — so by construction, setup was
// already completed at least once. No live check needed here.
$setupReady = true;

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
    error_log("[review_batch] Nav badge query error: " . $e->getMessage());
}

$navItems = [
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => '../index.php', 'show' => true],
    ['key' => 'disbursements', 'icon' => 'wallet', 'label' => 'Disbursements', 'href' => '../batches/index.php?status=all', 'show' => true, 'badge' => ($navPendingApprovals > 0 && $navCanApprove) ? $navPendingApprovals : null],
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

$stageTrackerStatus = $batch['status'] ?? 'draft';

// Default so the Actions section (which reads this) never hits an
// undefined-variable warning on a batch with no department/ration info —
// no ration info means nothing to block on, not a manufactured blocker.
$thisBatchFits = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Batch · Review · VOUCHMORPH Enterprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../partials/shell.css">
    <link rel="stylesheet" href="../partials/stage-tracker.css">
    <style>
        .readonly-badge { display: inline-block; padding: 4px 12px; background: var(--amber-bg); color: var(--amber); font-size: 10px; font-weight: 600; text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.04em; border: 1px solid var(--amber); }
        .ration-panel { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line); }
        .ration-panel .ration-title { font-family: var(--f-cond); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-500); margin-bottom: 10px; }
        .ration-bar-track { height: 8px; background: var(--paper); border: 1px solid var(--line); margin-bottom: 10px; }
        .ration-bar-fill { height: 100%; }
        .ration-stats { display: flex; gap: 20px; flex-wrap: wrap; font-size: 12.5px; color: var(--ink-500); }
        .ration-stats strong { color: var(--ink-900); }
        .ration-stats .danger strong { color: var(--danger); }
        .ration-stats .ok strong { color: var(--ledger-green); }
        .queue-progress { background: var(--panel); border: 1.5px solid var(--brass); padding: 18px 22px; margin-bottom: 16px; }
        .queue-progress .qp-title { font-family: var(--f-cond); font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--brass); margin-bottom: 10px; }
        .queue-bar-track { height: 12px; background: var(--paper); border: 1px solid var(--line); margin-bottom: 10px; }
        .queue-bar-fill { height: 100%; background: var(--ledger-green); transition: width 0.3s; }
        .queue-bar-fill.has-failures { background: var(--amber); }
        .queue-stats { display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; color: var(--ink-500); }
        .queue-stats strong { color: var(--ink-900); }
        .queue-stats .danger strong { color: var(--danger); }
        .table-code { font-family: var(--f-mono); font-size: 11px; background: var(--paper); padding: 2px 6px; }
        .btn-retry { background: var(--blue-tint); color: #1e40af; padding: 4px 14px; font-size: 11px; border: none; cursor: pointer; font-family: var(--f-cond); font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-retry:hover { background: #bfdbfe; }
        .btn-approver-locked { background: var(--amber-bg); color: var(--amber); border: 1px solid var(--amber); padding: 8px 22px; font-weight: 600; font-size: 12px; cursor: not-allowed; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.8; }
        .rejection-form { display: none; margin-top: 12px; padding: 16px; background: var(--danger-bg); }
        .rejection-form.show { display: block; }
        .rejection-form textarea { width: 100%; padding: 10px; border: 1px solid var(--line); min-height: 80px; font-family: var(--f-body); font-size: 13px; background: var(--panel); box-sizing: border-box; }
        .rejection-form textarea:focus { outline: 2px solid var(--brass); outline-offset: 1px; }
        .rejection-form .form-group { margin-bottom: 12px; }
        .actions-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 16px; }
        @media (max-width: 768px) { .actions-bar { flex-direction: column; align-items: stretch; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../partials/shell-head.php'; ?>
            <div class="page-header">
                <div>
                    <h1>Create Batch</h1>
                    <div class="sub">Steps 3–5 — validate, authorize, and execute <span style="font-family:var(--f-mono);"><?php echo safeHtml($batch['batch_reference']); ?></span>.</div>
                </div>
                <div class="page-header-actions">
                    <span style="font-size:11px; color:var(--ink-300); font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em; align-self:center;"><?php echo safeHtml($roleDisplay); ?></span>
                </div>
            </div>

            <?php require __DIR__ . '/../partials/stage-tracker.php'; ?>

            <?php if ($error): ?>
            <div class="info-panel" style="border-left-color: var(--seal-red); background: var(--danger-bg);">
                <div class="label" style="color:var(--danger);">⚠️ <?php echo $error; ?></div>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="info-panel" style="border-left-color: var(--ledger-green); background: var(--green-tint);">
                <div class="label" style="color:var(--ledger-green);">✅ <?php echo $success; ?></div>
            </div>
            <?php endif; ?>

            <?php if ($queueProgress && $queueProgress['total'] > 0): ?>
            <div class="queue-progress">
                <div class="qp-title">⚙️ Execution Progress</div>
                <div class="queue-bar-track">
                    <div class="queue-bar-fill<?php echo $hasFailedJobs ? ' has-failures' : ''; ?>" style="width:<?php echo $queueProgress['percent_done']; ?>%;"></div>
                </div>
                <div class="queue-stats">
                    <span>Total: <strong><?php echo (int)$queueProgress['total']; ?></strong></span>
                    <span>✅ Completed: <strong><?php echo (int)$queueProgress['completed']; ?></strong></span>
                    <span>⏳ In flight: <strong><?php echo (int)$queueProgress['in_flight']; ?></strong></span>
                    <?php if ($hasFailedJobs): ?>
                    <span class="danger">❌ Failed: <strong><?php echo (int)$queueProgress['permanently_failed']; ?></strong></span>
                    <?php endif; ?>
                    <span><?php echo $queueProgress['percent_done']; ?>% done</span>
                </div>
                <?php if ((int)$queueProgress['in_flight'] > 0): ?>
                <p style="font-size:12.5px; color:var(--ink-300); margin-top:10px;">
                    Workers are processing this batch in the background — safe to leave this page and check back later.
                    <a href="review_batch.php?batch_id=<?php echo $batchId; ?>" style="color:var(--brass); font-weight:600;">Refresh</a> to see the latest progress.
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($isReadOnly && !in_array($role, ['owner', 'approver', 'senior_approver'])): ?>
            <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg);">
                <div class="label" style="color:var(--amber);">🔒 Read-Only Mode</div>
                <div class="desc">This batch was created by <?php echo safeHtml($batch['created_by_name'] ?? 'another user'); ?>. You can view the details but cannot make changes.</div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Batch Summary</span>
                    <span style="display:flex; align-items:center; gap:8px;">
                        <span class="status status-<?php echo getStatusClass($status); ?>"><?php echo getStatusLabel($status); ?></span>
                        <?php if ($isReadOnly): ?>
                        <span class="readonly-badge">🔒 Read-Only</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="grid-3">
                    <div><strong>Batch Reference:</strong> <?php echo safeHtml($batch['batch_reference']); ?></div>
                    <div><strong>Batch Name:</strong> <?php echo safeHtml($batch['batch_name']); ?></div>
                    <div><strong>Created:</strong> <?php echo safeHtml(vm_local_time($batch['created_at'])); ?></div>
                    <div><strong>Source Institution:</strong> <?php echo safeHtml($batch['source_institution']); ?></div>
                    <div><strong>Source Account:</strong> <?php echo safeHtml($batch['source_identifier']); ?></div>
                    <div><strong>Currency:</strong> <?php echo safeHtml($batch['currency'] ?? 'BWP'); ?></div>
                    <div><strong>Total Amount:</strong> <?php echo number_format($batch['total_amount'] ?? 0, 2); ?> <?php echo safeHtml($batch['currency'] ?? 'BWP'); ?></div>
                    <div><strong>Total Destinations:</strong> <?php echo $batch['total_destinations'] ?? 0; ?></div>
                    <div><strong>Created By:</strong> <?php echo safeHtml($batch['created_by_name'] ?? 'N/A'); ?></div>
                    <div><strong>Department:</strong> <?php echo $departmentInfo ? safeHtml($departmentInfo['name']) : '<span style="color:var(--danger);">Not assigned</span>'; ?></div>
                </div>

                <?php if ($batch['submitted_at']): ?>
                <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--line);">
                    <strong>Submitted:</strong> <?php echo safeHtml(vm_local_time($batch['submitted_at'])); ?>
                    by <?php echo safeHtml($batch['submitted_by_name'] ?? 'N/A'); ?>
                </div>
                <?php endif; ?>

                <?php if ($batch['approved_at']): ?>
                <div>
                    <strong>Approved:</strong> <?php echo safeHtml(vm_local_time($batch['approved_at'])); ?>
                    by <?php echo safeHtml($batch['approved_by_name'] ?? 'N/A'); ?>
                </div>
                <?php endif; ?>

                <?php if ($batch['rejection_reason']): ?>
                <div class="info-panel" style="border-left-color: var(--seal-red); background: var(--danger-bg); margin-top:14px;">
                    <div class="label" style="color:var(--danger);">Rejection Reason</div>
                    <div class="desc" style="color:var(--danger);"><?php echo safeHtml($batch['rejection_reason']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($batch['executed_at']): ?>
                <div>
                    <strong>Last Execution Attempt:</strong> <?php echo safeHtml(vm_local_time($batch['executed_at'])); ?>
                    by <?php echo safeHtml($batch['executed_by_name'] ?? 'N/A'); ?>
                </div>
                <?php endif; ?>

                <?php if ($rationInfo): ?>
                <?php
                    $utilPct = $rationInfo['ceiling'] > 0
                        ? min(100, round((($rationInfo['disbursed_ytd'] + $rationInfo['reserved_in_flight']) / $rationInfo['ceiling']) * 100, 1))
                        : 0;
                    $barColor = $rationInfo['available'] < 0 ? 'var(--danger)' : ($utilPct >= 80 ? 'var(--amber)' : 'var(--ledger-green)');
                    $thisBatchFits = (float)($batch['total_amount'] ?? 0) <= $rationInfo['available'] || in_array($status, ['approved', 'completed', 'executing', 'partial_success', 'partially_completed']);
                ?>
                <div class="ration-panel">
                    <div class="ration-title">Department Budget Ration<?php echo $departmentInfo ? ' — ' . safeHtml($departmentInfo['name']) : ''; ?></div>
                    <div class="ration-bar-track"><div class="ration-bar-fill" style="width:<?php echo $utilPct; ?>%; background:<?php echo $barColor; ?>;"></div></div>
                    <div class="ration-stats">
                        <span>Ceiling: <strong><?php echo formatCurrency($rationInfo['ceiling'], $rationInfo['currency']); ?></strong></span>
                        <span>Disbursed YTD: <strong><?php echo formatCurrency($rationInfo['disbursed_ytd'], $rationInfo['currency']); ?></strong></span>
                        <span>Reserved (pending/approved batches): <strong><?php echo formatCurrency($rationInfo['reserved_in_flight'], $rationInfo['currency']); ?></strong></span>
                        <span class="<?php echo $rationInfo['available'] < 0 ? 'danger' : 'ok'; ?>">Available: <strong><?php echo formatCurrency($rationInfo['available'], $rationInfo['currency']); ?></strong></span>
                    </div>
                </div>
                <?php if (!$thisBatchFits): ?>
                <div class="info-panel" style="border-left-color: var(--seal-red); background: var(--danger-bg); margin-top:14px;">
                    <div class="label" style="color:var(--danger);">⚠️ Budget Ceiling Exceeded</div>
                    <div class="desc">This batch's total (<span class="highlight"><?php echo formatCurrency($batch['total_amount'] ?? 0, $rationInfo['currency']); ?></span>) exceeds this department's available ration. Submitting or approving is blocked until the department borrows more ration or the batch is reduced.</div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Recipient Manifest (<?php echo count($destinations); ?>)</span>
                    <?php if ($hasFailedJobs): ?>
                    <span style="color:var(--danger); font-weight:600; font-family:var(--f-cond);">
                        ⚠️ <?php echo (int)$queueProgress['permanently_failed']; ?> failed
                    </span>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th><th>Institution</th><th>Identifier</th><th>Amount</th>
                                <th>Beneficiary</th><th>Delivery</th><th>Data Check</th><th>Status</th>
                                <th>Transaction Ref</th><th>Error Message</th>
                                <?php if ($role === 'owner'): ?>
                                <th>Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($destinations as $dest): ?>
                            <?php $completeness = destinationCompletenessLabel($dest); ?>
                            <tr>
                                <td><?php echo $dest['destination_index']; ?></td>
                                <td><?php echo safeHtml($dest['institution']); ?></td>
                                <td><?php echo safeHtml($dest['identifier']); ?></td>
                                <td><?php echo number_format($dest['amount'], 2); ?> <?php echo safeHtml($dest['currency'] ?? 'BWP'); ?></td>
                                <td><?php echo safeHtml($dest['beneficiary_name'] ?? '-'); ?></td>
                                <td><?php echo safeHtml($dest['delivery_method']); ?></td>
                                <td><span class="status status-<?php echo $completeness['tone']; ?>"><?php echo $completeness['label']; ?></span></td>
                                <td>
                                    <?php
                                    $statusClass = destStatusClass($dest['status'] ?? 'PENDING');
                                    $displayStatus = $dest['status'] ?? 'PENDING';
                                    if (in_array(strtoupper($displayStatus), ['COMPLETED', 'SUCCESS'])) {
                                        $displayStatus = '✅ ' . $displayStatus;
                                    } elseif (strtoupper($displayStatus) === 'FAILED') {
                                        $displayStatus = '❌ ' . $displayStatus;
                                    } elseif (in_array(strtoupper($displayStatus), ['PENDING', 'PROCESSING'])) {
                                        $displayStatus = '⏳ ' . $displayStatus;
                                    }
                                    ?>
                                    <span class="status status-<?php echo $statusClass; ?>"><?php echo safeHtml($displayStatus); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($dest['transaction_reference'])): ?>
                                        <code class="table-code"><?php echo safeHtml($dest['transaction_reference']); ?></code>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td style="color:var(--danger); font-size:12px; max-width:200px;">
                                    <?php echo safeHtml($dest['error_message'] ?? ''); ?>
                                </td>
                                <?php if ($role === 'owner' && strtolower($dest['status'] ?? '') === 'failed'): ?>
                                <td>
                                    <a href="retry_destination.php?batch_id=<?php echo $batchId; ?>&dest_idx=<?php echo $dest['destination_index']; ?>" class="btn-retry">🔄 Retry</a>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Actions</span>
                    <span style="display:flex; gap:8px;">
                        <?php if ($isReadOnly): ?>
                        <span class="readonly-badge">🔒 Read-Only</span>
                        <?php endif; ?>
                        <?php if ($isApprover): ?>
                        <span class="readonly-badge">🔑 Approver Mode</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="actions-bar">
                    <?php if ($status === 'draft' && $canSubmit && !$isReadOnly): ?>
                        <?php if (!$thisBatchFits): ?>
                        <a href="../departments/index.php" class="btn btn-warning"><?php echo svgIcon('warning'); ?> Request Budget Increase</a>
                        <span style="font-size:12px; color:var(--ink-500);">This batch exceeds the department's available ration — submission is blocked until it's resolved.</span>
                        <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                            <input type="hidden" name="action" value="submit_for_approval">
                            <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this batch for approval?')">📤 Submit for Approval</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($status === 'pending_approval' && $canApprove): ?>
                        <?php if (!$thisBatchFits): ?>
                        <a href="../departments/index.php" class="btn btn-warning"><?php echo svgIcon('warning'); ?> Request Budget Increase</a>
                        <span style="font-size:12px; color:var(--ink-500);">This batch no longer fits the department's ration — approval is blocked until it's resolved.</span>
                        <?php else: ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this batch?')">
                            <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-success">✅ Approve</button>
                        </form>
                        <button class="btn btn-danger" onclick="toggleRejection()">❌ Reject</button>
                        <?php endif; ?>
                        <div class="rejection-form" id="rejectionForm">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                                <input type="hidden" name="action" value="reject">
                                <div class="form-group">
                                    <label>Rejection Reason</label>
                                    <textarea name="rejection_reason" required></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger">Submit Rejection</button>
                                <button type="button" class="btn btn-outline" onclick="toggleRejection()" style="margin-left:8px;">Cancel</button>
                            </form>
                        </div>
                    <?php elseif ($status === 'pending_approval' && $isApprover && !$canApprove): ?>
                    <button class="btn-approver-locked" disabled style="cursor:not-allowed;">🔒 OUTSIDE YOUR DEPARTMENT</button>
                    <span style="font-size:12px; color:var(--ink-500); margin-left:4px;"><?php echo safeHtml($executeDisabledReason); ?></span>
                    <?php endif; ?>

                    <?php if ($status === 'approved'): ?>
                        <?php if ($showExecuteButton): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ EXECUTE DISBURSEMENT: This will queue real fund transfers for background processing. Only proceed if you have verified all approvals. Continue?')">
                            <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                            <input type="hidden" name="action" value="execute">
                            <button type="submit" class="btn btn-danger">🚀 EXECUTE DISBURSEMENT</button>
                        </form>
                        <?php else: ?>
                        <button class="btn-approver-locked" disabled style="cursor:not-allowed;">🔒 DISBURSEMENT LOCKED</button>
                        <span style="font-size:12px; color:var(--ink-500); margin-left:4px;">
                            <?php echo $executeDisabledReason ?: 'Only Owners can execute disbursements'; ?>
                        </span>
                        <?php if ($isApprover): ?>
                        <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg); margin-top:8px; width:100%;">
                            <div class="label" style="color:var(--amber);">🔑 Approver Notice</div>
                            <div class="desc">You have approved this batch. The disbursement will be executed by an <strong>Owner</strong> after final review. You do not have permission to disburse funds.</div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($status === 'executing'): ?>
                    <a href="review_batch.php?batch_id=<?php echo $batchId; ?>" class="btn btn-outline"><?php echo svgIcon('clock'); ?> Refresh Progress</a>
                    <?php endif; ?>

                    <?php if ($showRetryFailedButton): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Requeue every permanently-failed destination in this batch for another attempt? Fix whatever caused the failure (e.g. a bad phone number) before retrying if you can.')">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="retry_failed_jobs">
                        <button type="submit" class="btn btn-warning">🔁 RETRY FAILED DESTINATIONS</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($status === 'draft' && $canEdit && !$isReadOnly): ?>
                    <a href="add_destinations.php?batch_id=<?php echo $batchId; ?>" class="btn btn-outline">✏️ Edit Destinations</a>
                    <?php endif; ?>

                    <a href="../batches/index.php" class="btn btn-outline">📋 All Batches</a>
                    <a href="../index.php" class="btn btn-outline">🏠 Dashboard</a>
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
