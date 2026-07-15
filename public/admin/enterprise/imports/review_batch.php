<?php
// enterprise/imports/review_batch.php - Review and approve batch
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// ============================================================
// FIX: Load SwapService and dependencies directly
// ============================================================
require_once '../../../../src/Domain/Services/SwapService.php';
require_once '../../../../src/Core/Config/LoadCountry.php';

use Domain\Services\SwapService;
use Core\Config\LoadCountry;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;
$batchId = $_GET['batch_id'] ?? 0;

$error = '';
$success = '';

// ============================================================
// HELPER: Check if user can edit this batch
// ============================================================
function canEditBatch($batchCreatedBy, $currentUserId, $userRole) {
    // Owner can edit everything
    if ($userRole === 'owner') return true;
    // Loaders (program_officer, department_head) can only edit their own
    if (in_array($userRole, ['program_officer', 'department_head'])) {
        return $batchCreatedBy == $currentUserId;
    }
    // Everyone else cannot edit
    return false;
}

// ============================================================
// GET BATCH
// ============================================================
$batch = null;
if ($batchId) {
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
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$batch) {
    die("Batch not found.");
}

// ============================================================
// CHECK PERMISSIONS FOR THIS BATCH
// ============================================================
$isOwnBatch = ($batch['created_by'] == $userId);
$canEdit = canEditBatch($batch['created_by'], $userId, $user['role'] ?? 'viewer');
$isReadOnly = !$canEdit;

// Get destinations
$destinations = [];
$stmt = $db->prepare("
    SELECT * FROM disbursement_destinations 
    WHERE batch_id = :batch_id
    ORDER BY destination_index
");
$stmt->execute([':batch_id' => $batchId]);
$destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// HANDLE ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    
    // Prevent editing if read-only
    if ($isReadOnly && !in_array($action, ['approve', 'reject', 'execute'])) {
        $error = "You cannot modify this batch. It was created by another user.";
    } else {
        if ($action === 'submit_for_approval') {
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
            $batch['status'] = 'pending_approval';
            
        } elseif ($action === 'approve') {
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
            $batch['status'] = 'approved';
            
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
            $batch['status'] = 'rejected';
            
        } elseif ($action === 'execute') {
            // ============================================================
            // FIXED: Direct SwapService call - NO HTTP!
            // ============================================================
            try {
                // Load country configuration
                $countryName = $_ENV['VOUCHMORPH_COUNTRY'] ?? getenv('VOUCHMORPH_COUNTRY') ?? 'Botswana';
                $fullCountryConfig = LoadCountry::getConfig();
                
                error_log("[review_batch] Initializing SwapService...");
                
                // Instantiate SwapService with 3 args
                $swapService = new SwapService(
                    $db, 
                    $fullCountryConfig, 
                    $countryName
                );
                
                error_log("[review_batch] SwapService initialized successfully");
                
                // Build the payload
                $payload = [
                    'swap_type' => 'MULTI_DESTINATION',
                    'reference' => $batch['batch_reference'],
                    'from_institution' => $batch['source_institution'],
                    'source_institution' => $batch['source_institution'],
                    'asset_type' => $batch['source_asset_type'] ?? 'ACCOUNT',
                    'source_identifier' => $batch['source_identifier'],
                    'amount' => (float)$batch['total_amount'],
                    'currency' => $batch['currency'] ?? 'BWP',
                    'destinations' => []
                ];
                
                foreach ($destinations as $dest) {
                    $payload['destinations'][] = [
                        'to_institution' => $dest['institution'],
                        'destination_institution' => $dest['institution'],
                        'destination_asset_type' => $dest['asset_type'] ?? 'WALLET',
                        'destination_identifier' => $dest['identifier'],
                        'destination_identifier_type' => $dest['identifier_type'] ?? 'account',
                        'amount' => (float)$dest['amount'],
                        'currency' => $dest['currency'] ?? 'BWP',
                        'delivery_method' => $dest['delivery_method'] ?? 'DEPOSIT',
                        'beneficiary_phone' => $dest['beneficiary_phone'],
                        'beneficiary_name' => $dest['beneficiary_name']
                    ];
                }
                
                error_log("[review_batch] Executing multi-destination swap with " . count($destinations) . " destinations");
                error_log("[review_batch] Payload: " . json_encode($payload));
                
                // Execute the swap
                $result = $swapService->executeAtomicSwap($payload);
                
                error_log("[review_batch] Swap completed, status: " . ($result['status'] ?? 'unknown'));
                
                // Update batch status
                $status = $result['status'] ?? 'COMPLETED';
                $successCount = $result['successful_destinations'] ?? 0;
                $failedCount = $result['failed_destinations'] ?? 0;
                $pendingCount = $result['pending_count'] ?? 0;
                
                $stmt = $db->prepare("
                    UPDATE disbursement_batches 
                    SET status = :status,
                        successful_count = :success,
                        failed_count = :failed,
                        pending_count = :pending,
                        executed_by = :user_id,
                        executed_at = NOW(),
                        results_payload = :results::jsonb,
                        completed_at = CASE 
                            WHEN LOWER(:status) IN ('completed', 'success') THEN NOW() 
                            ELSE completed_at 
                        END,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':status' => $status,
                    ':success' => $successCount,
                    ':failed' => $failedCount,
                    ':pending' => $pendingCount,
                    ':user_id' => $userId,
                    ':results' => json_encode($result),
                    ':id' => $batchId
                ]);
                
                // Update destination statuses
                if (isset($result['destinations']) && is_array($result['destinations'])) {
                    foreach ($result['destinations'] as $idx => $destResult) {
                        $destIndex = $destResult['index'] ?? $idx;
                        $stmt = $db->prepare("
                            UPDATE disbursement_destinations 
                            SET status = :status,
                                hold_reference = :hold_ref,
                                transaction_reference = :tx_ref,
                                error_message = :error
                            WHERE batch_id = :batch_id AND destination_index = :idx
                        ");
                        $stmt->execute([
                            ':status' => $destResult['status'] ?? ($destResult['success'] ? 'SUCCESS' : 'FAILED'),
                            ':hold_ref' => $destResult['hold_reference'] ?? null,
                            ':tx_ref' => $destResult['transaction_reference'] ?? null,
                            ':error' => $destResult['error'] ?? $destResult['message'] ?? null,
                            ':batch_id' => $batchId,
                            ':idx' => $destIndex + 1
                        ]);
                    }
                }
                
                $success = "Batch executed successfully! $successCount succeeded, $failedCount failed.";
                $batch['status'] = $status;
                
            } catch (Exception $e) {
                error_log("[review_batch] Execution error: " . $e->getMessage());
                error_log("[review_batch] Trace: " . $e->getTraceAsString());
                $error = "Execution failed: " . $e->getMessage();
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$roleDisplay = strtoupper($user['role'] ?? 'USER');

// ============================================================
// PERMISSIONS - Owner can submit and execute, but NOT approve
// ============================================================
$canSubmit = in_array($user['role'] ?? '', ['owner', 'program_officer', 'department_head']) && $canEdit;
$canApprove = in_array($user['role'] ?? '', ['approver', 'senior_approver']);
$canExecute = in_array($user['role'] ?? '', ['owner']);

$status = strtolower($batch['status'] ?? 'draft');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review & Approve · VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #EEF1EF;
            --panel: #FFFFFF;
            --ink-900: #0F2138;
            --ink-700: #1D3557;
            --ink-500: #4A5A6E;
            --ink-300: #8A96A3;
            --line: #D3DAD6;
            --brass: #8A6D3B;
            --brass-tint: #F4EFE3;
            --seal-red: #7A2118;
            --ledger-green: #24513A;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Sans', sans-serif;
            background: var(--paper);
            color: var(--ink-900);
            min-height: 100vh;
        }
        .masthead {
            background: var(--ink-900);
            color: white;
            padding: 14px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--brass);
            flex-wrap: wrap;
            gap: 10px;
        }
        .masthead h1 { font-size: 18px; font-weight: 700; }
        .masthead .role-pill {
            font-size: 10px;
            font-weight: 700;
            color: var(--brass);
            border: 1px solid var(--brass);
            padding: 2px 10px;
            text-transform: uppercase;
        }
        .stage { max-width: 1100px; margin: 0 auto; padding: 30px 20px; }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--line);
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-title { font-size: 16px; font-weight: 700; text-transform: uppercase; }
        .workflow-status {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        .status-draft { background: var(--line); color: var(--ink-500); }
        .status-pending_approval { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fbeceb; color: var(--seal-red); }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-failed { background: #fbeceb; color: var(--seal-red); }
        .readonly-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid #f59e0b;
        }
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s;
            font-family: inherit;
        }
        .btn-primary { background: var(--ink-900); color: white; }
        .btn-primary:hover { background: var(--brass); }
        .btn-success { background: var(--ledger-green); color: white; }
        .btn-success:hover { background: #1a3d2c; }
        .btn-danger { background: var(--seal-red); color: white; }
        .btn-danger:hover { background: #5a1812; }
        .btn-secondary { background: var(--line); color: var(--ink-700); }
        .btn-secondary:hover { background: var(--line-strong); }
        .btn-outline { background: transparent; border: 2px solid var(--line); }
        .btn-outline:hover { border-color: var(--brass); }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: var(--ink-900); color: white; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid var(--line); }
        .actions-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ink-500);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .back-link:hover { color: var(--brass); }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            padding: 0 20px;
        }
        .step {
            flex: 1;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: var(--ink-300);
            text-transform: uppercase;
        }
        .step.active { color: var(--ink-900); }
        .step.done { color: var(--ledger-green); }
        .error {
            background: #fbeceb;
            color: var(--seal-red);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border-left: 3px solid var(--seal-red);
        }
        .success {
            background: #dcfce7;
            color: #166534;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border-left: 3px solid #10b981;
        }
        .rejection-form {
            display: none;
            margin-top: 12px;
            padding: 16px;
            background: #fbeceb;
            border-radius: 8px;
        }
        .rejection-form.show { display: block; }
        .rejection-form textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--line);
            border-radius: 8px;
            min-height: 80px;
            font-family: inherit;
        }
        @media (max-width: 768px) {
            .grid-3 { grid-template-columns: 1fr; }
            .masthead { flex-direction: column; text-align: center; }
            .step-indicator { flex-wrap: wrap; gap: 8px; }
            .step { flex: 0 0 45%; }
        }
    </style>
</head>
<body>
    <div class="masthead">
        <h1>VouchMorph · Review Batch</h1>
        <div>
            <span class="role-pill"><?php echo $roleDisplay; ?></span>
            <span style="color:var(--ink-300); font-size:12px; margin-left:12px;">
                <?php echo htmlspecialchars($batch['batch_reference']); ?>
            </span>
            <a href="../logout.php" style="color: rgba(255,255,255,0.4); text-decoration: none; margin-left: 16px; font-size: 12px;">Logout</a>
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
        <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="success">✅ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- READ ONLY NOTICE -->
        <?php if ($isReadOnly && !in_array($user['role'] ?? '', ['owner', 'approver', 'senior_approver'])): ?>
        <div class="card" style="border-left: 4px solid #f59e0b; background: #fef3c7;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:24px;">🔒</span>
                <div>
                    <strong style="color:#92400e;">Read-Only Mode</strong>
                    <p style="color:#78350f; font-size:13px; margin-top:2px;">
                        This batch was created by <?php echo htmlspecialchars($batch['created_by_name'] ?? 'another user'); ?>. 
                        You can view the details but cannot make changes.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Batch Summary -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Batch Summary</span>
                <span>
                    <span class="workflow-status status-<?php echo $status; ?>">
                        <?php echo htmlspecialchars($status); ?>
                    </span>
                    <?php if ($isReadOnly): ?>
                    <span class="readonly-badge" style="margin-left:8px;">🔒 READ ONLY</span>
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
            </div>
            <?php if ($batch['submitted_at']): ?>
            <div style="margin-top:12px; padding-top:12px; border-top:1px solid var(--line);">
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
            <div style="margin-top:12px; padding:12px; background:#fbeceb; border-radius:8px;">
                <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($batch['rejection_reason']); ?>
            </div>
            <?php endif; ?>
            <?php if ($batch['executed_at']): ?>
            <div>
                <strong>Executed:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['executed_at'])); ?>
                by <?php echo htmlspecialchars($batch['executed_by_name'] ?? 'N/A'); ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Destinations -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">👥 Destinations (<?php echo count($destinations); ?>)</span>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Institution</th>
                            <th>Identifier</th>
                            <th>Amount</th>
                            <th>Beneficiary</th>
                            <th>Delivery</th>
                            <th>Status</th>
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
                                <span class="workflow-status status-<?php echo strtolower($dest['status']); ?>">
                                    <?php echo htmlspecialchars($dest['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Actions -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">⚡ Actions</span>
                <?php if ($isReadOnly): ?>
                <span class="readonly-badge">🔒 Read-Only</span>
                <?php endif; ?>
            </div>
            <div class="actions-bar">
                <!-- Submit for Approval - Only for OWN batches in DRAFT -->
                <?php if ($status === 'draft' && $canSubmit && !$isReadOnly): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="submit_for_approval">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this batch for approval?')">
                        📤 Submit for Approval
                    </button>
                </form>
                <?php endif; ?>

                <!-- Approve - Only for Approver and Senior Approver -->
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
                            <label style="display:block; margin-bottom:4px; font-weight:600;">Rejection Reason</label>
                            <textarea name="rejection_reason" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger">Submit Rejection</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleRejection()">Cancel</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Execute - Only for Owners -->
                <?php if ($status === 'approved' && $canExecute): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Execute this multi-destination swap? This will move real funds.')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="execute">
                    <button type="submit" class="btn btn-success">🚀 Execute Disbursement</button>
                </form>
                <?php endif; ?>

                <!-- Edit Destinations - Only for OWN batches in DRAFT -->
                <?php if ($status === 'draft' && $canEdit && !$isReadOnly): ?>
                <a href="add_destinations.php?batch_id=<?php echo $batchId; ?>" class="btn btn-secondary">✏️ Edit Destinations</a>
                <?php endif; ?>

                <!-- Always show Dashboard and Batches links -->
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
