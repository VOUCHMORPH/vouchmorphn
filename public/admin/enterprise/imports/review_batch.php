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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Review Batch</title>
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); min-height: 100vh; font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        :focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }
        .masthead { background: var(--ink-900); color: #fff; padding: 14px 32px; display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid var(--brass); flex-wrap: wrap; gap: 10px; }
        .masthead h1 { font-family: var(--f-cond); font-size: 18px; font-weight: 700; letter-spacing: 0.04em; }
        .masthead .role-pill { font-size: 10px; font-weight: 700; color: var(--brass); border: 1px solid var(--brass); padding: 2px 10px; text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.05em; }
        .masthead .role-pill.approver { border-color: #f59e0b; color: #f59e0b; }
        .masthead .role-pill.owner { border-color: var(--ledger-green); color: var(--ledger-green); }
        .masthead .ref { color: var(--ink-300); font-size: 12px; margin-left: 12px; font-family: var(--f-mono); }
        .masthead .logout-link { color: rgba(255,255,255,0.4); text-decoration: none; margin-left: 16px; font-size: 11px; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: 0.04em; }
        .masthead .logout-link:hover { color: var(--brass); }
        .stage { max-width: 1200px; margin: 0 auto; padding: 28px 20px; }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--ink-500); text-decoration: none; font-size: 13px; font-weight: 600; margin-bottom: 20px; font-family: var(--f-cond); letter-spacing: 0.02em; }
        .back-link:hover { color: var(--brass); }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 28px; padding: 0 8px; }
        .step { flex: 1; text-align: center; font-size: 11px; font-weight: 600; color: var(--ink-300); text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.04em; }
        .step.active { color: var(--ink-900); }
        .step.done { color: var(--ledger-green); }
        .card { background: var(--panel); border: 1px solid var(--line); padding: 24px; margin-bottom: 20px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--line); flex-wrap: wrap; gap: 10px; }
        .card-title { font-size: 16px; font-weight: 700; font-family: var(--f-cond); letter-spacing: 0.02em; }
        .readonly-badge { display: inline-block; padding: 4px 12px; background: #fef3c7; color: var(--amber); font-size: 10px; font-weight: 600; text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.04em; border: 1px solid #f59e0b; }
        .workflow-status { padding: 4px 14px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; font-family: var(--f-cond); letter-spacing: 0.04em; }
        .status-draft { background: var(--line); color: var(--ink-500); }
        .status-pending_approval { background: #fef3c7; color: var(--amber); }
        .status-approved { background: var(--blue-tint); color: #1e40af; }
        .status-executing { background: #fef3c7; color: var(--amber); }
        .status-rejected { background: var(--danger-bg); color: var(--danger); }
        .status-completed { background: var(--green-tint); color: var(--ledger-green); }
        .status-failed { background: var(--danger-bg); color: var(--danger); }
        .status-partially_completed { background: #fef3c7; color: var(--amber); }
        .status-pending_identity_confirmation { background: var(--blue-tint); color: #1e40af; }
        .status-partial_success { background: #fef3c7; color: var(--amber); }
        .status-success { background: var(--green-tint); color: var(--ledger-green); }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
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
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: var(--paper); color: var(--ink-500); padding: 10px 14px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; border-bottom: 2px solid var(--line); font-family: var(--f-cond); }
        td { padding: 10px 14px; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 13px; }
        tr:hover { background: var(--brass-tint); }
        .table-code { font-family: var(--f-mono); font-size: 11px; background: var(--paper); padding: 2px 6px; }
        .error { background: var(--danger-bg); color: var(--danger); padding: 14px 18px; margin-bottom: 16px; border-left: 3px solid var(--danger); font-size: 14px; line-height: 1.6; }
        .success { background: var(--green-tint); color: var(--ledger-green); padding: 14px 18px; margin-bottom: 16px; border-left: 3px solid var(--ledger-green); font-size: 14px; line-height: 1.6; }
        .btn { padding: 8px 22px; border: none; font-weight: 600; font-size: 12px; cursor: pointer; transition: all 0.15s; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: 0.04em; }
        .btn:hover { opacity: 0.85; }
        .btn-primary { background: var(--ink-900); color: #fff; }
        .btn-primary:hover { background: var(--brass); color: var(--ink-900); }
        .btn-success { background: var(--ledger-green); color: #fff; }
        .btn-success:hover { background: #1a3d2c; }
        .btn-danger { background: var(--seal-red); color: #fff; }
        .btn-danger:hover { background: #5a1812; }
        .btn-secondary { background: var(--line); color: var(--ink-700); }
        .btn-secondary:hover { background: var(--line-strong); }
        .btn-outline { background: transparent; border: 1px solid var(--line); color: var(--ink-500); }
        .btn-outline:hover { border-color: var(--brass); color: var(--ink-900); background: var(--brass-tint); }
        .btn-execute { background: var(--seal-red); color: #fff; font-size: 14px; padding: 10px 32px; }
        .btn-execute:hover { background: #5a1812; }
        .btn-execute:disabled { opacity: 0.5; cursor: not-allowed; background: var(--ink-300); }
        .btn-resume { background: var(--amber); color: #fff; font-size: 13px; padding: 9px 26px; }
        .btn-resume:hover { background: #6e4800; }
        .btn-retry { background: var(--blue-tint); color: #1e40af; padding: 4px 14px; font-size: 11px; border: none; cursor: pointer; font-family: var(--f-cond); font-weight: 600; }
        .btn-retry:hover { background: #bfdbfe; }
        .btn-approver-locked { background: #fef3c7; color: var(--amber); border: 1px solid #f59e0b; padding: 8px 22px; font-weight: 600; font-size: 12px; cursor: not-allowed; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; }
        .btn-approver-locked:hover { opacity: 0.7; }
        .actions-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .rejection-form { display: none; margin-top: 12px; padding: 16px; background: var(--danger-bg); }
        .rejection-form.show { display: block; }
        .rejection-form textarea { width: 100%; padding: 10px; border: 1px solid var(--line); min-height: 80px; font-family: var(--f-body); font-size: 13px; background: var(--panel); }
        .rejection-form textarea:focus { outline: 2px solid var(--brass); outline-offset: 1px; }
        .rejection-form .form-group { margin-bottom: 12px; }
        .rejection-form .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; font-family: var(--f-cond); color: var(--ink-500); }
        .security-notice { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; margin-top: 12px; font-size: 13px; color: var(--amber); }
        .security-notice strong { color: var(--amber); }
        .security-notice .lock-icon { font-size: 18px; margin-right: 8px; }
        @media (max-width: 768px) {
            .grid-3 { grid-template-columns: 1fr; }
            .masthead { flex-direction: column; text-align: center; padding: 12px 16px; }
            .step-indicator { flex-wrap: wrap; gap: 8px; }
            .step { flex: 0 0 45%; }
            .stage { padding: 16px; }
            .card { padding: 16px; }
            .actions-bar { flex-direction: column; }
            .btn { width: 100%; text-align: center; }
        }
        @media (max-width: 480px) {
            .masthead h1 { font-size: 15px; }
            .step { font-size: 9px; }
            table { font-size: 12px; }
            th, td { padding: 6px 8px; }
        }
        @media (prefers-color-scheme: dark) {
            :root { --paper: #1B2733; --panel: #1B2733; --ink-900: #ECEFF2; --ink-700: #D5DCE0; --ink-500: #93A2AC; --ink-300: #6B7A85; --line: #2C3A45; }
            .masthead { background: #0d1a26; }
            .card { background: #1B2733; border-color: #2C3A45; }
            .card-header { border-color: #2C3A45; }
            th { background: #1B2733; color: #93A2AC; border-color: #2C3A45; }
            td { border-color: #2C3A45; }
            tr:hover { background: #22303A; }
            .btn-primary { background: #2C3A45; color: #ECEFF2; }
            .btn-primary:hover { background: var(--brass); color: var(--ink-900); }
            .btn-outline { border-color: #2C3A45; color: #93A2AC; }
            .btn-outline:hover { border-color: var(--brass); color: #ECEFF2; background: #22303A; }
            .status-draft { background: #2C3A45; color: #93A2AC; }
            .table-code { background: #2C3A45; color: #93A2AC; }
            .rejection-form textarea { background: #1B2733; border-color: #2C3A45; color: #ECEFF2; }
            .security-notice { background: #1e293b; border-left-color: #f59e0b; color: #fbbf24; }
            .btn-approver-locked { background: #1e293b; border-color: #f59e0b; color: #fbbf24; }
        }
    </style>
</head>
<body>
    <div class="masthead">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <h1>VOUCHMORPH · Review Batch</h1>
            <span class="role-pill <?php echo $role === 'owner' ? 'owner' : ($isApprover ? 'approver' : ''); ?>">
                <?php echo $roleDisplay; ?>
            </span>
            <span class="ref"><?php echo htmlspecialchars($batch['batch_reference']); ?></span>
        </div>
        <div>
            <a href="../logout.php" class="logout-link">Sign Out</a>
        </div>
    </div>

    <div class="stage">
        <a href="add_destinations.php?batch_id=<?php echo $batchId; ?>" class="back-link">← Back to Destinations</a>

        <div class="step-indicator">
            <span class="step done">1. Select Source</span>
            <span class="step done">2. Add Destinations</span>
            <span class="step active">3. Review</span>
            <span class="step">4. Approve</span>
            <span class="step">5. Execute</span>
        </div>

        <?php if ($error): ?>
        <div class="error">⚠️ <?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="success">✅ <?php echo $success; ?></div>
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
                Refresh to see the latest progress.
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($isReadOnly && !in_array($role, ['owner', 'approver', 'senior_approver'])): ?>
        <div class="card" style="border-left: 3px solid #f59e0b; background: #fef3c7;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:22px;">🔒</span>
                <div>
                    <strong style="color:var(--amber); font-family:var(--f-cond);">Read-Only Mode</strong>
                    <p style="color:var(--ink-500); font-size:13px; margin-top:2px;">
                        This batch was created by <?php echo htmlspecialchars($batch['created_by_name'] ?? 'another user'); ?>. 
                        You can view the details but cannot make changes.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Batch Summary</span>
                <span>
                    <span class="workflow-status status-<?php echo $status; ?>"><?php echo htmlspecialchars($status); ?></span>
                    <?php if ($isReadOnly): ?>
                    <span class="readonly-badge" style="margin-left:8px;">🔒 Read-Only</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="grid-3">
                <div><strong>Batch Reference:</strong> <?php echo htmlspecialchars($batch['batch_reference']); ?></div>
                <div><strong>Batch Name:</strong> <?php echo htmlspecialchars($batch['batch_name']); ?></div>
                <div><strong>Created:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['created_at'])); ?></div>
                <div><strong>Source Institution:</strong> <?php echo htmlspecialchars($batch['source_institution']); ?></div>
                <div><strong>Source Account:</strong> <?php echo htmlspecialchars($batch['source_identifier']); ?></div>
                <div><strong>Currency:</strong> <?php echo htmlspecialchars($batch['currency'] ?? 'BWP'); ?></div>
                <div><strong>Total Amount:</strong> <?php echo number_format($batch['total_amount'] ?? 0, 2); ?> <?php echo htmlspecialchars($batch['currency'] ?? 'BWP'); ?></div>
                <div><strong>Total Destinations:</strong> <?php echo $batch['total_destinations'] ?? 0; ?></div>
                <div><strong>Created By:</strong> <?php echo htmlspecialchars($batch['created_by_name'] ?? 'N/A'); ?></div>
                <div><strong>Department:</strong> <?php echo $departmentInfo ? htmlspecialchars($departmentInfo['name']) : '<span style="color:var(--danger);">Not assigned</span>'; ?></div>
            </div>

            <?php if ($batch['submitted_at']): ?>
            <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--line);">
                <strong>Submitted:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['submitted_at'])); ?>
                by <?php echo htmlspecialchars($batch['submitted_by_name'] ?? 'N/A'); ?>
            </div>
            <?php endif; ?>

            <?php if ($batch['approved_at']): ?>
            <div>
                <strong>Approved:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['approved_at'])); ?>
                by <?php echo htmlspecialchars($batch['approved_by_name'] ?? 'N/A'); ?>
            </div>
            <?php endif; ?>

            <?php if ($batch['rejection_reason']): ?>
            <div style="margin-top:14px; padding:14px; background:var(--danger-bg);">
                <strong style="color:var(--danger);">Rejection Reason:</strong>
                <span style="color:var(--danger);"><?php echo htmlspecialchars($batch['rejection_reason']); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($batch['executed_at']): ?>
            <div>
                <strong>Last Execution Attempt:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['executed_at'])); ?>
                by <?php echo htmlspecialchars($batch['executed_by_name'] ?? 'N/A'); ?>
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
                <div class="ration-title">💰 Department Ration<?php echo $departmentInfo ? ' — ' . safeHtmlRb($departmentInfo['name']) : ''; ?></div>
                <div class="ration-bar-track"><div class="ration-bar-fill" style="width:<?php echo $utilPct; ?>%; background:<?php echo $barColor; ?>;"></div></div>
                <div class="ration-stats">
                    <span>Ceiling: <strong><?php echo formatCurrency($rationInfo['ceiling'], $rationInfo['currency']); ?></strong></span>
                    <span>Disbursed YTD: <strong><?php echo formatCurrency($rationInfo['disbursed_ytd'], $rationInfo['currency']); ?></strong></span>
                    <span>Reserved (pending/approved batches): <strong><?php echo formatCurrency($rationInfo['reserved_in_flight'], $rationInfo['currency']); ?></strong></span>
                    <span class="<?php echo $rationInfo['available'] < 0 ? 'danger' : 'ok'; ?>">Available: <strong><?php echo formatCurrency($rationInfo['available'], $rationInfo['currency']); ?></strong></span>
                </div>
                <?php if (!$thisBatchFits): ?>
                <div style="margin-top:10px; font-size:12.5px; color:var(--danger);">
                    ⚠️ This batch's total (<?php echo formatCurrency($batch['total_amount'] ?? 0, $rationInfo['currency']); ?>) exceeds available ration.
                    Submitting will be blocked until the department borrows more ration or the batch is reduced.
                    <a href="../departments/index.php" style="color:var(--brass); font-weight:600;">Go to Departments →</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">👥 Destinations (<?php echo count($destinations); ?>)</span>
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
                            <th>Beneficiary</th><th>Delivery</th><th>Status</th>
                            <th>Transaction Ref</th><th>Error Message</th>
                            <?php if ($role === 'owner'): ?>
                            <th>Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($destinations as $dest): ?>
                        <tr>
                            <td><?php echo $dest['destination_index']; ?></td>
                            <td><?php echo htmlspecialchars($dest['institution']); ?></td>
                            <td><?php echo htmlspecialchars($dest['identifier']); ?></td>
                            <td><?php echo number_format($dest['amount'], 2); ?> <?php echo htmlspecialchars($dest['currency'] ?? 'BWP'); ?></td>
                            <td><?php echo htmlspecialchars($dest['beneficiary_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($dest['delivery_method']); ?></td>
                            <td>
                                <?php
                                $statusClass = strtolower($dest['status'] ?? 'PENDING');
                                $displayStatus = $dest['status'] ?? 'PENDING';
                                if (in_array($statusClass, ['completed', 'success'])) {
                                    $displayStatus = '✅ ' . $displayStatus;
                                } elseif ($statusClass === 'failed') {
                                    $displayStatus = '❌ ' . $displayStatus;
                                } elseif (in_array($statusClass, ['pending', 'processing'])) {
                                    $displayStatus = '⏳ ' . $displayStatus;
                                }
                                ?>
                                <span class="workflow-status status-<?php echo $statusClass; ?>"><?php echo $displayStatus; ?></span>
                            </td>
                            <td>
                                <?php if (!empty($dest['transaction_reference'])): ?>
                                    <code class="table-code"><?php echo htmlspecialchars($dest['transaction_reference']); ?></code>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td style="color:var(--danger); font-size:12px; max-width:200px;">
                                <?php echo htmlspecialchars($dest['error_message'] ?? ''); ?>
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
                <span class="card-title">⚡ Actions</span>
                <?php if ($isReadOnly): ?>
                <span class="readonly-badge">🔒 Read-Only</span>
                <?php endif; ?>
                <?php if ($isApprover): ?>
                <span class="readonly-badge" style="background: #fef3c7; border-color: #f59e0b; color: var(--amber);">🔑 Approver Mode</span>
                <?php endif; ?>
            </div>
            <div class="actions-bar">
                <?php if ($status === 'draft' && $canSubmit && !$isReadOnly): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="submit_for_approval">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this batch for approval?')">📤 Submit for Approval</button>
                </form>
                <?php endif; ?>

                <?php if ($status === 'pending_approval' && $canApprove): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this batch?')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn btn-success">✅ Approve</button>
                </form>
                <button class="btn btn-danger" onclick="toggleRejection()">❌ Reject</button>
                <div class="rejection-form" id="rejectionForm">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="reject">
                        <div class="form-group">
                            <label>Rejection Reason</label>
                            <textarea name="rejection_reason" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger">Submit Rejection</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleRejection()" style="margin-left:8px;">Cancel</button>
                    </form>
                </div>
                <?php elseif ($status === 'pending_approval' && $isApprover && !$canApprove): ?>
                <button class="btn-approver-locked" disabled style="cursor:not-allowed;">🔒 OUTSIDE YOUR DEPARTMENT</button>
                <span style="font-size:12px; color:var(--ink-500); margin-left:4px;"><?php echo safeHtmlRb($executeDisabledReason); ?></span>
                <?php endif; ?>

                <?php if ($status === 'approved'): ?>
                    <?php if ($showExecuteButton): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ EXECUTE DISBURSEMENT: This will queue real fund transfers for background processing. Only proceed if you have verified all approvals. Continue?')">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="execute">
                        <button type="submit" class="btn btn-execute">🚀 EXECUTE DISBURSEMENT</button>
                    </form>
                    <?php else: ?>
                    <button class="btn-approver-locked" disabled style="cursor:not-allowed;">🔒 DISBURSEMENT LOCKED</button>
                    <span style="font-size:12px; color:var(--ink-500); margin-left:4px;">
                        <?php echo $executeDisabledReason ?: 'Only Owners can execute disbursements'; ?>
                    </span>
                    <?php if ($isApprover): ?>
                    <div class="security-notice" style="margin-top:8px; width:100%;">
                        <span class="lock-icon">🔑</span>
                        <strong>Approver Notice:</strong> You have approved this batch. The disbursement will be executed by an
                        <strong>Owner</strong> after final review. You do not have permission to disburse funds.
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($status === 'executing'): ?>
                <a href="review_batch.php?batch_id=<?php echo $batchId; ?>" class="btn btn-outline">🔄 Refresh Progress</a>
                <?php endif; ?>

                <?php if ($showRetryFailedButton): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Requeue every permanently-failed destination in this batch for another attempt? Fix whatever caused the failure (e.g. a bad phone number) before retrying if you can.')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="retry_failed_jobs">
                    <button type="submit" class="btn btn-resume">🔁 RETRY FAILED DESTINATIONS</button>
                </form>
                <?php endif; ?>

                <?php if ($status === 'draft' && $canEdit && !$isReadOnly): ?>
                <a href="add_destinations.php?batch_id=<?php echo $batchId; ?>" class="btn btn-secondary">✏️ Edit Destinations</a>
                <?php endif; ?>

                <a href="../batches/index.php" class="btn btn-outline">📋 All Batches</a>
                <a href="../index.php" class="btn btn-outline">🏠 Dashboard</a>
            </div>
        </div>
    </div>

    <script>
        function toggleRejection() {
            document.getElementById('rejectionForm').classList.toggle('show');
        }
    </script>
</body>
</html>
