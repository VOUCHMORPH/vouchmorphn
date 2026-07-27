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
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
require_once '../../../../src/Domain/Services/DepartmentService.php';
require_once '../../../../src/Domain/Services/UserManagementService.php';
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
    LEFT JOIN organization_users u1 ON b.created_by = u1.user_id
    LEFT JOIN organization_users u2 ON b.submitted_by = u2.user_id
    LEFT JOIN organization_users u3 ON b.reviewed_by = u3.user_id
    LEFT JOIN organization_users u4 ON b.approved_by = u4.user_id
    LEFT JOIN organization_users u5 ON b.executed_by = u5.user_id
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Batch Details</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper:        #EEF1EF;
            --panel:        #FFFFFF;
            --ink-900:      #0F2138;
            --ink-700:      #1D3557;
            --ink-500:      #4A5A6E;
            --ink-300:      #8A96A3;
            --line:         #D3DAD6;
            --line-strong:  #AEB8B2;
            --brass:        #8A6D3B;
            --brass-tint:   #F4EFE3;
            --seal-red:     #7A2118;
            --amber:        #8A5A0B;
            --ledger-green: #24513A;
            --green-tint:   #E5EEE7;
            --blue-tint:    #E7EEF4;
            --warning-bg:   #FEF3C7;
            --danger:       #b3261e;
            --danger-bg:    #FBEceb;
            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            min-height: 100vh;
            font-size: 14px;
            line-height:1.5;
            -webkit-font-smoothing:antialiased;
        }
        .masthead {
            background: var(--ink-900);
            color:#fff;
            padding:14px 32px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            border-bottom:3px solid var(--brass);
            flex-wrap:wrap;
            gap:10px;
        }
        .masthead h1 { font-family:var(--f-cond); font-size:18px; font-weight:700; letter-spacing:.04em; }
        .masthead .role-pill {
            font-size:10px; font-weight:700; color:var(--brass);
            border:1px solid var(--brass); padding:2px 10px;
            text-transform:uppercase; font-family:var(--f-cond); letter-spacing:.05em;
        }
        .masthead .role-pill.approver { border-color:#f59e0b; color:#f59e0b; }
        .masthead .role-pill.owner { border-color:var(--ledger-green); color:var(--ledger-green); }
        .masthead .logout-link {
            color:rgba(255,255,255,0.4); text-decoration:none;
            font-size:11px; font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em;
        }
        .masthead .logout-link:hover { color:var(--brass); }
        .stage { max-width:1400px; margin:0 auto; padding:28px 20px; }
        .back-link {
            display:inline-flex; align-items:center; gap:6px;
            color:var(--ink-500); text-decoration:none; font-size:13px; font-weight:600;
            margin-bottom:20px; font-family:var(--f-cond);
        }
        .back-link:hover { color:var(--brass); }
        .card {
            background:var(--panel); border:1px solid var(--line);
            padding:24px; margin-bottom:20px;
        }
        .card-header {
            display:flex; justify-content:space-between; align-items:center;
            margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--line);
            flex-wrap:wrap; gap:10px;
        }
        .card-title { font-size:16px; font-weight:700; font-family:var(--f-cond); letter-spacing:.02em; }
        .stats-grid {
            display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr));
            gap:16px; margin-bottom:24px;
        }
        .stat-card {
            background:var(--panel); border:1px solid var(--line);
            padding:16px 20px; text-align:center;
        }
        .stat-value { font-size:24px; font-weight:700; font-family:var(--f-cond); color:var(--ink-900); line-height:1.2; }
        .stat-label { font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-300); font-weight:600; font-family:var(--f-cond); margin-top:4px; }
        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
        .detail-item { padding:6px 0; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; }
        .detail-item .label { color:var(--ink-500); font-weight:500; font-size:13px; }
        .detail-item .value { font-weight:600; font-size:13px; }
        .currency-note {
            font-size:12px; color:var(--ink-500); margin-top:10px; padding-top:10px;
            border-top:1px dashed var(--line);
        }
        .currency-note strong { color:var(--ink-900); }
        .fx-flag {
            display:inline-block; margin-left:6px; padding:1px 7px; font-size:10px;
            background:var(--warning-bg); color:var(--amber); font-family:var(--f-cond);
            font-weight:700; letter-spacing:.03em;
        }
        .status-badge {
            padding:4px 14px; font-size:11px; font-weight:600; text-transform:uppercase;
            font-family:var(--f-cond); letter-spacing:.04em; display:inline-block;
        }
        .status-badge.draft { background:var(--line); color:var(--ink-500); }
        .status-badge.pending_approval { background:#fef3c7; color:var(--amber); }
        .status-badge.approved { background:var(--blue-tint); color:#1e40af; }
        .status-badge.completed { background:var(--green-tint); color:var(--ledger-green); }
        .status-badge.rejected { background:var(--danger-bg); color:var(--danger); }
        .ration-panel { margin-top:14px; padding-top:14px; border-top:1px solid var(--line); }
        .ration-panel .ration-title {
            font-family: var(--f-cond); font-size: 12px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--ink-500); margin-bottom: 10px;
        }
        .ration-bar-track { height: 8px; background: var(--paper); border: 1px solid var(--line); margin-bottom: 10px; }
        .ration-bar-fill { height: 100%; }
        .ration-stats { display: flex; gap: 20px; flex-wrap: wrap; font-size: 12.5px; color: var(--ink-500); }
        .ration-stats strong { color: var(--ink-900); }
        .ration-stats .danger strong { color: var(--danger); }
        .ration-stats .ok strong { color: var(--ledger-green); }
        .table-responsive { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th {
            background:var(--paper); color:var(--ink-500);
            padding:10px 14px; text-align:left; font-size:10px;
            text-transform:uppercase; letter-spacing:.05em; font-weight:600;
            border-bottom:2px solid var(--line); font-family:var(--f-cond);
        }
        td { padding:10px 14px; border-bottom:1px solid var(--line); vertical-align:middle; font-size:13px; }
        tr:hover { background:var(--brass-tint); }
        .amt { font-family:var(--f-mono); font-weight:600; }
        .status-badge-sm {
            padding:2px 10px; font-size:10px; font-weight:600; text-transform:uppercase;
            font-family:var(--f-cond); letter-spacing:.04em; display:inline-block;
        }
        .status-badge-sm.success { background:var(--green-tint); color:var(--ledger-green); }
        .status-badge-sm.failed { background:var(--danger-bg); color:var(--danger); }
        .status-badge-sm.pending { background:#fef3c7; color:var(--amber); }
        .status-badge-sm.completed { background:var(--green-tint); color:var(--ledger-green); }
        .actions-bar {
            display:flex; gap:12px; flex-wrap:wrap; margin-top:12px; align-items:center;
        }
        .btn {
            padding:8px 22px; border:none; font-weight:600; font-size:12px;
            cursor:pointer; transition:all 0.15s; font-family:var(--f-cond);
            text-transform:uppercase; letter-spacing:.04em; text-decoration:none; display:inline-flex; align-items:center;
        }
        .btn:hover { opacity:0.85; }
        .btn-success { background:var(--ledger-green); color:#fff; }
        .btn-success:hover { background:#1a3d2c; }
        .btn-danger { background:var(--seal-red); color:#fff; }
        .btn-danger:hover { background:#5a1812; }
        .btn-primary { background:var(--ink-900); color:#fff; }
        .btn-primary:hover { background:var(--brass); color:var(--ink-900); }
        .btn-secondary { background:var(--line); color:var(--ink-700); }
        .btn-secondary:hover { background:var(--line-strong); }
        .btn-outline { background:transparent; border:1px solid var(--line); color:var(--ink-500); }
        .btn-outline:hover { border-color:var(--brass); color:var(--ink-900); background:var(--brass-tint); }
        .btn:disabled { opacity:0.5; cursor:not-allowed; }
        .success-msg { background:var(--green-tint); color:var(--ledger-green); padding:14px 18px; margin-bottom:16px; border-left:3px solid var(--ledger-green); }
        .error-msg { background:var(--danger-bg); color:var(--danger); padding:14px 18px; margin-bottom:16px; border-left:3px solid var(--danger); }
        .rejection-form { display:none; margin-top:12px; padding:16px; background:var(--danger-bg); }
        .rejection-form.show { display:block; }
        .rejection-form textarea { width:100%; padding:10px; border:1px solid var(--line); min-height:80px; font-family:var(--f-body); font-size:13px; background:var(--panel); }
        .rejection-form textarea:focus { outline:2px solid var(--brass); outline-offset:1px; }
        .rejection-form .form-group { margin-bottom:12px; }
        .rejection-form .form-group label { display:block; margin-bottom:6px; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-family:var(--f-cond); color:var(--ink-500); }
        .empty-state { padding:40px 20px; text-align:center; color:var(--ink-300); }
        .empty-state .mark { font-size:32px; display:block; margin-bottom:8px; }
        .approver-notice { background:#fef3c7; border-left:4px solid #f59e0b; padding:12px 16px; margin-bottom:16px; display:flex; align-items:flex-start; gap:12px; }
        .approver-notice .icon { font-size:20px; flex-shrink:0; }
        .approver-notice .content { flex:1; }
        .approver-notice .content strong { color:var(--amber); font-family:var(--f-cond); }
        .info-notice { background:var(--blue-tint); border-left:4px solid #3b82f6; padding:12px 16px; margin-bottom:0; font-size:13px; color:#1e40af; }
        @media (max-width:768px) { .grid-2, .grid-3 { grid-template-columns:1fr; } .masthead { flex-direction:column; text-align:center; } .stage { padding:16px; } .card { padding:16px; } .actions-bar { flex-direction:column; align-items:stretch; } .btn { width:100%; text-align:center; justify-content:center; } }
        @media (prefers-color-scheme:dark) {
            :root { --paper:#1B2733; --panel:#1B2733; --ink-900:#ECEFF2; --ink-700:#D5DCE0; --ink-500:#93A2AC; --ink-300:#6B7A85; --line:#2C3A45; }
            .masthead { background:#0d1a26; }
            .card { background:#1B2733; border-color:#2C3A45; }
            .card-header { border-color:#2C3A45; }
            th { background:#1B2733; color:#93A2AC; border-color:#2C3A45; }
            td { border-color:#2C3A45; }
            tr:hover { background:#22303A; }
            .stat-card { background:#1B2733; border-color:#2C3A45; }
            .stat-value { color:#ECEFF2; }
            .btn-primary { background:#2C3A45; color:#ECEFF2; }
            .btn-primary:hover { background:var(--brass); color:var(--ink-900); }
        }
    </style>
</head>
<body>
    <div class="masthead">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <h1>VOUCHMORPH · Batch Details</h1>
            <span class="role-pill <?php echo $isApprover ? 'approver' : ($isOwner ? 'owner' : ''); ?>">
                <?php echo strtoupper(UserManagementService::ROLE_CATALOG[$role]['label'] ?? $role); ?>
            </span>
        </div>
        <div>
            <span style="color:var(--ink-300); font-size:12px; margin-right:12px;">
                <?php echo safeHtmlView($user['full_name'] ?? 'User'); ?>
            </span>
            <a href="../../logout.php" class="logout-link">Sign Out</a>
        </div>
    </div>

    <div class="stage">
        <a href="index.php" class="back-link">← All Batches</a>

        <?php if ($actionResult): ?>
        <div class="success-msg"><?php echo safeHtmlView($actionResult); ?></div>
        <?php endif; ?>
        <?php if ($actionError): ?>
        <div class="error-msg"><?php echo $actionError; ?></div>
        <?php endif; ?>

        <?php if ($isApprover && $batch['status'] === 'pending_approval'): ?>
        <div class="approver-notice">
            <span class="icon">🔑</span>
            <div class="content">
                <strong>Approval Required</strong>
                <p>This batch is pending your review. Please verify all details below before approving or rejecting.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo formatCurrency($batch['total_amount'] ?? 0, $batchCurrency); ?></div>
                <div class="stat-label">Total Amount (Source Currency)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $batch['total_destinations'] ?? 0; ?></div>
                <div class="stat-label">Total Recipients</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--ledger-green);"><?php echo $successCount; ?></div>
                <div class="stat-label">✅ Successful</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--danger);"><?php echo $failedCount; ?></div>
                <div class="stat-label">❌ Failed</div>
            </div>
        </div>

        <!-- Batch Details -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Batch Details</span>
                <span class="status-badge <?php echo $statusClass; ?>">
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
                <div class="ration-title">💰 Department Ration<?php echo $departmentInfo ? ' — ' . safeHtmlView($departmentInfo['name']) : ''; ?></div>
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

        <!-- Destinations -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">👥 Destinations (<?php echo count($destinations); ?>)</span>
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
                                    <span class="mark">§</span>
                                    <p>NO DESTINATIONS ADDED YET</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Actions -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">⚡ Actions</span>
            </div>
            <?php if ($scopeDeniedMessage && $isApprover): ?>
            <div class="info-notice" style="margin-bottom:14px;">🔒 <?php echo safeHtmlView($scopeDeniedMessage); ?></div>
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
                        <button type="button" class="btn btn-secondary" onclick="toggleRejection()" style="margin-left:8px;">Cancel</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Execute now links to the hardened flow instead of a local (previously broken) handler -->
                <?php if ($batch['status'] === 'approved' && $canExecute): ?>
                <a href="../imports/review_batch.php?batch_id=<?php echo $batchId; ?>" class="btn" style="background:var(--seal-red); color:#fff; font-weight:700; font-size:14px; padding:10px 32px;">
                    🚀 Go Execute Disbursement
                </a>
                <span style="font-size:12px; color:var(--ink-500);">Executed from the Review page, with duplicate-prevention and resume safety.</span>
                <?php elseif (in_array($batch['status'], ['executing', 'partial_success', 'failed'])): ?>
                <a href="../imports/review_batch.php?batch_id=<?php echo $batchId; ?>" class="btn btn-outline">🔍 View Execution Status / Resume</a>
                <?php endif; ?>

                <?php if ($batch['status'] === 'draft' && $canEdit): ?>
                <a href="../imports/add_destinations.php?batch_id=<?php echo $batchId; ?>" class="btn btn-secondary">✏️ Edit Destinations</a>
                <?php endif; ?>

                <a href="index.php" class="btn btn-outline">📋 All Batches</a>
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
