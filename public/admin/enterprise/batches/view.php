<?php
/**
 * batches/view.php - ENTERPRISE DISBURSEMENT BATCH DETAILS
 * FIXED: Uses disbursement_batches and disbursement_destinations
 * ADDED: Approver actions - approve/reject with proper permissions
 */
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;
$batchId = $_GET['id'] ?? 0;

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
// PERMISSIONS
// ============================================================
$role = $user['role'] ?? 'viewer';
$isApprover = in_array($role, ['approver', 'senior_approver']);
$isOwner = ($role === 'owner');
$isCreator = ($batch['created_by'] == $userId);
$isProgramOfficer = in_array($role, ['program_officer', 'department_head']);

// Can approve: Approver or Senior Approver ONLY
$canApprove = ($isApprover && $batch['status'] === 'pending_approval');
$canReject = ($isApprover && $batch['status'] === 'pending_approval');
$canEdit = ($isOwner || ($isProgramOfficer && $isCreator && $batch['status'] === 'draft'));
$canSubmit = ($isOwner || ($isProgramOfficer && $isCreator && $batch['status'] === 'draft'));
$canExecute = ($isOwner && $batch['status'] === 'approved');

// ============================================================
// HANDLE APPROVAL ACTIONS
// ============================================================
$actionResult = null;
$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'approve' && $canApprove) {
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
                $batch = $updated;
                $actionResult = "✅ Batch approved successfully!";
                
                // Log approval
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
                $batch = $updated;
                $actionResult = "❌ Batch rejected.";
                
                // Log rejection
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
                $batch = $updated;
                $actionResult = "📤 Batch submitted for approval.";
            } else {
                throw new Exception("Batch may have been already submitted.");
            }
        }
    } catch (Exception $e) {
        $actionError = $e->getMessage();
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
    'completed', 'executed' => 'completed',
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
        .status-badge {
            padding:4px 14px; font-size:11px; font-weight:600; text-transform:uppercase;
            font-family:var(--f-cond); letter-spacing:.04em; display:inline-block;
        }
        .status-badge.draft { background:var(--line); color:var(--ink-500); }
        .status-badge.pending_approval { background:#fef3c7; color:var(--amber); }
        .status-badge.approved { background:var(--blue-tint); color:#1e40af; }
        .status-badge.completed { background:var(--green-tint); color:var(--ledger-green); }
        .status-badge.rejected { background:var(--danger-bg); color:var(--danger); }
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
            display:flex; gap:12px; flex-wrap:wrap; margin-top:12px;
        }
        .btn {
            padding:8px 22px; border:none; font-weight:600; font-size:12px;
            cursor:pointer; transition:all 0.15s; font-family:var(--f-cond);
            text-transform:uppercase; letter-spacing:.04em;
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
        .btn-approver-locked { background:#fef3c7; color:var(--amber); border:1px solid #f59e0b; padding:8px 22px; font-weight:600; font-size:12px; cursor:not-allowed; font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em; opacity:0.7; }
        @media (max-width:768px) { .grid-2, .grid-3 { grid-template-columns:1fr; } .masthead { flex-direction:column; text-align:center; } .stage { padding:16px; } .card { padding:16px; } .actions-bar { flex-direction:column; } .btn { width:100%; text-align:center; } }
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
                <?php echo strtoupper($role); ?>
            </span>
        </div>
        <div>
            <span style="color:var(--ink-300); font-size:12px; margin-right:12px;">
                <?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?>
            </span>
            <a href="../../logout.php" class="logout-link">Sign Out</a>
        </div>
    </div>

    <div class="stage">
        <a href="index.php" class="back-link">← All Batches</a>

        <?php if ($actionResult): ?>
        <div class="success-msg"><?php echo htmlspecialchars($actionResult); ?></div>
        <?php endif; ?>
        <?php if ($actionError): ?>
        <div class="error-msg"><?php echo htmlspecialchars($actionError); ?></div>
        <?php endif; ?>

        <!-- Approver Notice -->
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
                <div class="stat-value"><?php echo formatCurrency($batch['total_amount'] ?? 0); ?></div>
                <div class="stat-label">Total Amount</div>
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
                <div class="detail-item"><span class="label">Batch Reference</span><span class="value"><?php echo htmlspecialchars($batch['batch_reference']); ?></span></div>
                <div class="detail-item"><span class="label">Batch Name</span><span class="value"><?php echo htmlspecialchars($batch['batch_name']); ?></span></div>
                <div class="detail-item"><span class="label">Source Institution</span><span class="value"><?php echo htmlspecialchars($batch['source_institution']); ?></span></div>
                <div class="detail-item"><span class="label">Source Identifier</span><span class="value"><?php echo htmlspecialchars($batch['source_identifier']); ?></span></div>
                <div class="detail-item"><span class="label">Source Asset Type</span><span class="value"><?php echo htmlspecialchars($batch['source_asset_type'] ?? 'ACCOUNT'); ?></span></div>
                <div class="detail-item"><span class="label">Currency</span><span class="value"><?php echo htmlspecialchars($batch['currency'] ?? 'BWP'); ?></span></div>
                <div class="detail-item"><span class="label">Created By</span><span class="value"><?php echo htmlspecialchars($batch['created_by_name'] ?? 'N/A'); ?></span></div>
                <div class="detail-item"><span class="label">Created At</span><span class="value"><?php echo date('Y-m-d H:i', strtotime($batch['created_at'])); ?></span></div>
                <?php if ($batch['submitted_by_name']): ?>
                <div class="detail-item"><span class="label">Submitted By</span><span class="value"><?php echo htmlspecialchars($batch['submitted_by_name']); ?></span></div>
                <div class="detail-item"><span class="label">Submitted At</span><span class="value"><?php echo date('Y-m-d H:i', strtotime($batch['submitted_at'])); ?></span></div>
                <?php endif; ?>
                <?php if ($batch['approved_by_name']): ?>
                <div class="detail-item"><span class="label">Approved By</span><span class="value"><?php echo htmlspecialchars($batch['approved_by_name']); ?></span></div>
                <div class="detail-item"><span class="label">Approved At</span><span class="value"><?php echo date('Y-m-d H:i', strtotime($batch['approved_at'])); ?></span></div>
                <?php endif; ?>
                <?php if ($batch['executed_by_name']): ?>
                <div class="detail-item"><span class="label">Executed By</span><span class="value"><?php echo htmlspecialchars($batch['executed_by_name']); ?></span></div>
                <div class="detail-item"><span class="label">Executed At</span><span class="value"><?php echo date('Y-m-d H:i', strtotime($batch['executed_at'])); ?></span></div>
                <?php endif; ?>
                <?php if ($batch['rejection_reason']): ?>
                <div class="detail-item" style="grid-column:1/-1; background:var(--danger-bg); padding:12px; border:1px solid var(--danger);">
                    <span class="label" style="color:var(--danger);">Rejection Reason</span>
                    <span class="value" style="color:var(--danger);"><?php echo htmlspecialchars($batch['rejection_reason']); ?></span>
                </div>
                <?php endif; ?>
            </div>
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
                        <?php foreach ($destinations as $dest): ?>
                        <tr>
                            <td><?php echo $dest['destination_index']; ?></td>
                            <td><?php echo htmlspecialchars($dest['beneficiary_name'] ?? 'N/A'); ?></td>
                            <td>
                                <?php 
                                if ($dest['is_identity_recipient'] ?? false) {
                                    echo htmlspecialchars($dest['identity_type'] . ': ' . $dest['identity_value']);
                                } else {
                                    echo htmlspecialchars($dest['identifier']);
                                }
                                ?>
                            </td>
                            <td><span class="amt"><?php echo formatCurrency($dest['amount']); ?></span></td>
                            <td><?php echo htmlspecialchars($dest['hold_reference'] ?? $dest['transaction_reference'] ?? '-'); ?></td>
                            <td>
                                <?php 
                                $status = strtolower($dest['status'] ?? 'pending');
                                $statusClass = match($status) {
                                    'success', 'completed' => 'success',
                                    'failed' => 'failed',
                                    default => 'pending'
                                };
                                ?>
                                <span class="status-badge-sm <?php echo $statusClass; ?>"><?php echo strtoupper($dest['status'] ?? 'PENDING'); ?></span>
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
            <div class="actions-bar">
                <!-- Submit for Approval -->
                <?php if ($batch['status'] === 'draft' && $canSubmit): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="submit">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this batch for approval?')">
                        📤 Submit for Approval
                    </button>
                </form>
                <?php endif; ?>

                <!-- Approve -->
                <?php if ($batch['status'] === 'pending_approval' && $canApprove): ?>
                <form method="POST" onsubmit="return confirm('Approve this batch?')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn btn-success">✅ Approve</button>
                </form>
                <?php endif; ?>

                <!-- Reject -->
                <?php if ($batch['status'] === 'pending_approval' && $canReject): ?>
                <button class="btn btn-danger" onclick="toggleRejection()">❌ Reject</button>
                <div class="rejection-form" id="rejectionForm">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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

                <!-- Execute (Owner only) -->
                <?php if ($batch['status'] === 'approved' && $canExecute): ?>
                <form method="POST" onsubmit="return confirm('⚠️ EXECUTE DISBURSEMENT: This will move real funds. Continue?')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="execute">
                    <button type="submit" class="btn" style="background:var(--seal-red); color:#fff; font-weight:700; font-size:14px; padding:10px 32px;">
                        🚀 Execute Disbursement
                    </button>
                </form>
                <?php endif; ?>

                <!-- Edit (Draft only) -->
                <?php if ($batch['status'] === 'draft' && $canEdit): ?>
                <a href="../imports/add_destinations.php?batch_id=<?php echo $batchId; ?>" class="btn btn-secondary">✏️ Edit Destinations</a>
                <?php endif; ?>

                <!-- Back -->
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
