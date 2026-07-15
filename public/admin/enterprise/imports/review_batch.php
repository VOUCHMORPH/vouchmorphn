<?php
// enterprise/imports/review_batch.php - Review and approve batch
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;
$batchId = $_GET['batch_id'] ?? 0;

$error = '';
$success = '';
$orgName = htmlspecialchars($user['organization_name'] ?? 'ORGANIZATIONAL');
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$roleDisplay = strtoupper($user['role'] ?? 'USER');

// Get batch
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

// Get destinations
$destinations = [];
$stmt = $db->prepare("
    SELECT * FROM disbursement_destinations 
    WHERE batch_id = :batch_id
    ORDER BY destination_index
");
$stmt->execute([':batch_id' => $batchId]);
$destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'submit_for_approval') {
        $stmt = $db->prepare("
            UPDATE disbursement_batches 
            SET status = 'PENDING_APPROVAL',
                submitted_by = :user_id,
                submitted_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND status = 'DRAFT'
        ");
        $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
        $success = "Batch submitted for approval.";
        $batch['status'] = 'PENDING_APPROVAL';
        
    } elseif ($action === 'approve') {
        $stmt = $db->prepare("
            UPDATE disbursement_batches 
            SET status = 'APPROVED',
                approved_by = :user_id,
                approved_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND status = 'PENDING_APPROVAL'
        ");
        $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
        $success = "Batch approved.";
        $batch['status'] = 'APPROVED';
        
    } elseif ($action === 'reject') {
        $reason = $_POST['rejection_reason'] ?? 'No reason provided';
        $stmt = $db->prepare("
            UPDATE disbursement_batches 
            SET status = 'REJECTED',
                rejection_reason = :reason,
                reviewed_by = :user_id,
                reviewed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':reason' => $reason, ':user_id' => $userId, ':id' => $batchId]);
        $success = "Batch rejected.";
        $batch['status'] = 'REJECTED';
        
    } elseif ($action === 'execute') {
        // Execute the multi-destination swap
        try {
            require_once '../../../../src/BusinessLogicLayer/services/SwapService.php';
            $swapService = new SwapService($db, [], 'Botswana');
            
            // Build multi-destination payload
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
                    'destination_asset_type' => $dest['asset_type'],
                    'destination_identifier' => $dest['identifier'],
                    'destination_identifier_type' => $dest['identifier_type'],
                    'amount' => (float)$dest['amount'],
                    'currency' => $dest['currency'] ?? 'BWP',
                    'delivery_method' => $dest['delivery_method'],
                    'beneficiary_phone' => $dest['beneficiary_phone'],
                    'beneficiary_name' => $dest['beneficiary_name']
                ];
            }
            
            // Execute the swap
            $result = $swapService->executeAtomicSwap($payload);
            
            // Update batch status
            $status = $result['status'] ?? 'COMPLETED';
            $successCount = $result['successful_destinations'] ?? 0;
            $failedCount = $result['failed_destinations'] ?? 0;
            
            $stmt = $db->prepare("
                UPDATE disbursement_batches 
                SET status = :status,
                    successful_count = :success,
                    failed_count = :failed,
                    pending_count = 0,
                    executed_by = :user_id,
                    executed_at = NOW(),
                    results_payload = :results::jsonb,
                    completed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $status,
                ':success' => $successCount,
                ':failed' => $failedCount,
                ':user_id' => $userId,
                ':results' => json_encode($result),
                ':id' => $batchId
            ]);
            
            // Update individual destination statuses
            foreach ($result['destinations'] ?? [] as $idx => $destResult) {
                $stmt = $db->prepare("
                    UPDATE disbursement_destinations 
                    SET status = :status,
                        hold_reference = :hold_ref,
                        transaction_reference = :tx_ref,
                        error_message = :error
                    WHERE batch_id = :batch_id AND destination_index = :idx
                ");
                $stmt->execute([
                    ':status' => $destResult['status'] ?? 'FAILED',
                    ':hold_ref' => $destResult['hold_reference'] ?? null,
                    ':tx_ref' => $destResult['transaction_reference'] ?? null,
                    ':error' => $destResult['error'] ?? null,
                    ':batch_id' => $batchId,
                    ':idx' => $idx + 1
                ]);
            }
            
            $success = "Batch executed successfully! $successCount succeeded, $failedCount failed.";
            $batch['status'] = $status;
            
        } catch (Exception $e) {
            error_log("[review_batch] Execution error: " . $e->getMessage());
            $error = "Execution failed: " . $e->getMessage();
        }
    }
}

$csrfToken = generateCsrfToken();
$canSubmit = in_array($user['role'] ?? '', ['owner', 'program_officer', 'department_head']);
$canApprove = in_array($user['role'] ?? '', ['owner', 'approver', 'senior_approver']);
$canExecute = in_array($user['role'] ?? '', ['owner']);
$status = $batch['status'] ?? 'DRAFT';

// Helper functions
function getStatusClass($status) {
    $status = strtoupper($status);
    return match($status) {
        'DRAFT' => 'DRAFT',
        'PENDING_APPROVAL', 'PENDING' => 'PENDING_APPROVAL',
        'APPROVED' => 'APPROVED',
        'COMPLETED', 'EXECUTED' => 'COMPLETED',
        'REJECTED' => 'REJECTED',
        'FAILED' => 'FAILED',
        default => 'DRAFT'
    };
}

function getStatusLabel($status) {
    $status = strtoupper($status);
    return match($status) {
        'DRAFT' => '📝 Draft',
        'PENDING_APPROVAL', 'PENDING' => '⏳ Pending Approval',
        'APPROVED' => '✅ Approved',
        'COMPLETED' => '✔️ Completed',
        'EXECUTED' => '🚀 Executed',
        'REJECTED' => '❌ Rejected',
        'FAILED' => '❌ Failed',
        default => ucfirst(strtolower($status))
    };
}

function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review & Approve · VOUCHMORPH</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            min-height: 100vh;
        }

        /* ===== HEADER ===== */
        .header {
            background: #0f172a;
            color: #fff;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 3px solid #8A6D3B;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .logo {
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .logo span { color: #8A6D3B; }
        .org-name {
            font-size: 13px;
            color: #94a3b8;
            padding-left: 16px;
            border-left: 1px solid rgba(255,255,255,0.1);
        }
        .role-badge {
            padding: 4px 14px;
            background: #8A6D3B;
            color: #0f172a;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-radius: 20px;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .user-details {
            text-align: right;
        }
        .user-name {
            font-weight: 600;
            color: #8A6D3B;
            font-size: 13px;
        }
        .user-role {
            font-size: 10px;
            color: #94a3b8;
            text-transform: uppercase;
        }
        .logout-btn {
            padding: 6px 16px;
            border: 2px solid #8A6D3B;
            color: #8A6D3B;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 20px;
            transition: all 0.15s;
        }
        .logout-btn:hover {
            background: #8A6D3B;
            color: #0f172a;
        }

        /* ===== NAVIGATION ===== */
        .nav {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 32px;
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: 12px 0;
            color: #64748b;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid transparent;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .nav-item:hover { color: #0f172a; }
        .nav-item.active {
            color: #0f172a;
            border-bottom-color: #8A6D3B;
        }
        .nav-item.primary { color: #0f172a; }
        .nav-item.primary:hover { color: #8A6D3B; }

        /* ===== CONTENT ===== */
        .content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 32px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
        }
        .page-header .sub {
            color: #64748b;
            font-size: 14px;
        }
        .page-header .timestamp {
            color: #94a3b8;
            font-size: 12px;
        }

        /* ===== STEP INDICATOR ===== */
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
            color: #94a3b8;
            text-transform: uppercase;
        }
        .step.active { color: #0f172a; }
        .step.done { color: #166534; }
        .step .step-number {
            display: block;
            width: 32px;
            height: 32px;
            margin: 0 auto 6px;
            background: #e2e8f0;
            border-radius: 50%;
            line-height: 32px;
            font-weight: 700;
            font-size: 12px;
            color: #64748b;
        }
        .step.done .step-number { background: #166534; color: white; }
        .step.active .step-number { background: #8A6D3B; color: white; }

        /* ===== CARDS ===== */
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 16px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 8px;
        }
        .card-title {
            font-size: 15px;
            font-weight: 700;
        }
        .card-badge {
            padding: 2px 12px;
            background: #0f172a;
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            border-radius: 20px;
        }

        /* ===== GRID ===== */
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        /* ===== STATUS BADGES ===== */
        .status {
            display: inline-block;
            padding: 4px 14px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 20px;
            letter-spacing: 0.04em;
        }
        .status-DRAFT { background: #f1f5f9; color: #64748b; }
        .status-PENDING_APPROVAL { background: #fef3c7; color: #92400e; }
        .status-APPROVED { background: #dbeafe; color: #1e40af; }
        .status-COMPLETED { background: #dcfce7; color: #166534; }
        .status-REJECTED { background: #fee2e2; color: #991b1b; }
        .status-FAILED { background: #fee2e2; color: #991b1b; }

        /* ===== TABLES ===== */
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: #f8fafc;
            color: #64748b;
            padding: 10px 14px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }
        td {
            padding: 10px 14px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        tr:hover { background: #f8fafc; }

        /* ===== BUTTONS ===== */
        .btn {
            padding: 8px 20px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { opacity: 0.85; transform: translateY(-1px); }
        .btn-primary {
            background: #0f172a;
            color: #fff;
        }
        .btn-primary:hover {
            background: #8A6D3B;
        }
        .btn-success {
            background: #166534;
            color: #fff;
        }
        .btn-success:hover {
            background: #14532d;
        }
        .btn-danger {
            background: #991b1b;
            color: #fff;
        }
        .btn-danger:hover {
            background: #7f1d1d;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        .btn-outline {
            background: transparent;
            border: 1px solid #e2e8f0;
            color: #64748b;
        }
        .btn-outline:hover {
            border-color: #0f172a;
            color: #0f172a;
        }
        .actions-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        /* ===== ALERTS ===== */
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border-left: 3px solid #991b1b;
        }
        .success {
            background: #dcfce7;
            color: #166534;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border-left: 3px solid #166534;
        }
        .rejection-form {
            display: none;
            margin-top: 12px;
            padding: 16px;
            background: #fef2f2;
            border-radius: 8px;
            border: 1px solid #fee2e2;
        }
        .rejection-form.show { display: block; }
        .rejection-form textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
            min-height: 80px;
        }
        .rejection-form .btn-group {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }

        /* ===== FOOTER ===== */
        .footer {
            background: #0f172a;
            color: #94a3b8;
            padding: 16px 32px;
            text-align: center;
            font-size: 11px;
            border-top: 2px solid #8A6D3B;
            margin-top: 24px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .header { padding: 12px 16px; }
            .nav { padding: 0 16px; gap: 16px; }
            .content { padding: 16px; }
            .grid-3 { grid-template-columns: 1fr; }
            .step-indicator { flex-wrap: wrap; gap: 8px; }
            .step { flex: 0 0 45%; }
            .actions-bar { flex-direction: column; }
            .actions-bar .btn { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
    <!-- ============================================================ -->
    <!-- HEADER -->
    <!-- ============================================================ -->
    <header class="header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>·</span> <?php echo $orgName; ?></div>
            <span class="role-badge"><?php echo $roleDisplay; ?></span>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo safeHtml($fullName); ?></div>
                <div class="user-role"><?php echo $roleDisplay; ?> · <?php echo $orgName; ?></div>
            </div>
            <a href="../logout.php" class="logout-btn">Sign Out</a>
        </div>
    </header>

    <!-- ============================================================ -->
    <!-- NAVIGATION -->
    <!-- ============================================================ -->
    <nav class="nav">
        <a href="../index.php" class="nav-item">📊 Dashboard</a>
        <a href="source_input.php" class="nav-item primary">💰 New Disbursement</a>
        <a href="../batches/index.php" class="nav-item">📋 Batches</a>
        <a href="../beneficiaries.php" class="nav-item">👥 Beneficiaries</a>
        <a href="../reports.php" class="nav-item">📈 Reports</a>
        <a href="../settings.php" class="nav-item">⚙️ Settings</a>
    </nav>

    <!-- ============================================================ -->
    <!-- CONTENT -->
    <!-- ============================================================ -->
    <main class="content">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Review & Approve</h1>
                <div class="sub">Batch: <?php echo safeHtml($batch['batch_reference']); ?></div>
            </div>
            <div class="timestamp"><?php echo date('l, F j, Y · H:i'); ?></div>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step done"><span class="step-number">✓</span>Source</div>
            <div class="step done"><span class="step-number">✓</span>Destinations</div>
            <div class="step active"><span class="step-number">3</span>Review</div>
            <div class="step"><span class="step-number">4</span>Approve</div>
            <div class="step"><span class="step-number">5</span>Execute</div>
        </div>

        <?php if ($error): ?>
        <div class="error">⚠️ <?php echo safeHtml($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="success">✅ <?php echo safeHtml($success); ?></div>
        <?php endif; ?>

        <!-- Batch Summary -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Batch Summary</span>
                <span class="status status-<?php echo getStatusClass($status); ?>">
                    <?php echo getStatusLabel($status); ?>
                </span>
            </div>
            <div class="grid-3">
                <div><strong>Batch Reference:</strong> <?php echo safeHtml($batch['batch_reference']); ?></div>
                <div><strong>Batch Name:</strong> <?php echo safeHtml($batch['batch_name']); ?></div>
                <div><strong>Created:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['created_at'])); ?></div>
                <div><strong>Source Institution:</strong> <?php echo safeHtml($batch['source_institution']); ?></div>
                <div><strong>Source Account:</strong> <?php echo safeHtml($batch['source_identifier']); ?></div>
                <div><strong>Currency:</strong> <?php echo safeHtml($batch['currency'] ?? 'BWP'); ?></div>
                <div><strong>Total Amount:</strong> <?php echo number_format($batch['total_amount'] ?? 0, 2); ?> <?php echo safeHtml($batch['currency'] ?? 'BWP'); ?></div>
                <div><strong>Total Destinations:</strong> <?php echo $batch['total_destinations'] ?? 0; ?></div>
                <div><strong>Created By:</strong> <?php echo safeHtml($batch['created_by_name'] ?? 'N/A'); ?></div>
            </div>
            <?php if ($batch['submitted_at']): ?>
            <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e2e8f0;">
                <strong>Submitted:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['submitted_at'])); ?>
                by <?php echo safeHtml($batch['submitted_by_name'] ?? 'N/A'); ?>
            </div>
            <?php endif; ?>
            <?php if ($batch['approved_at']): ?>
            <div>
                <strong>Approved:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['approved_at'])); ?>
                by <?php echo safeHtml($batch['approved_by_name'] ?? 'N/A'); ?>
            </div>
            <?php endif; ?>
            <?php if ($batch['rejection_reason']): ?>
            <div style="margin-top:12px; padding:12px; background:#fef2f2; border-radius:8px; border-left:3px solid #991b1b;">
                <strong>Rejection Reason:</strong> <?php echo safeHtml($batch['rejection_reason']); ?>
            </div>
            <?php endif; ?>
            <?php if ($batch['executed_at']): ?>
            <div>
                <strong>Executed:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['executed_at'])); ?>
                by <?php echo safeHtml($batch['executed_by_name'] ?? 'N/A'); ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Destinations -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">👥 Destinations</span>
                <span class="card-badge"><?php echo count($destinations); ?> RECIPIENTS</span>
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
                            <td><?php echo safeHtml($dest['institution']); ?></td>
                            <td><?php echo safeHtml($dest['identifier']); ?></td>
                            <td><?php echo number_format($dest['amount'], 2); ?> <?php echo safeHtml($dest['currency'] ?? 'BWP'); ?></td>
                            <td><?php echo safeHtml($dest['beneficiary_name'] ?? '-'); ?></td>
                            <td><?php echo safeHtml($dest['delivery_method']); ?></td>
                            <td>
                                <span class="status status-<?php echo strtoupper($dest['status'] ?? 'PENDING'); ?>">
                                    <?php echo $dest['status'] ?? 'PENDING'; ?>
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
            </div>
            <div class="actions-bar">
                <?php if ($status === 'DRAFT' && $canSubmit): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="submit_for_approval">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this batch for approval?')">
                        📤 Submit for Approval
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($status === 'PENDING_APPROVAL' && $canApprove): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this batch?')">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn btn-success">✅ Approve</button>
                </form>
                <button class="btn btn-danger" onclick="toggleRejection()">❌ Reject</button>
                <div class="rejection-form" id="rejectionForm">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="reject">
                        <label style="display:block; margin-bottom:4px; font-weight:600;">Rejection Reason</label>
                        <textarea name="rejection_reason" placeholder="Please provide a reason for rejection..." required></textarea>
                        <div class="btn-group">
                            <button type="submit" class="btn btn-danger">Submit Rejection</button>
                            <button type="button" class="btn btn-secondary" onclick="toggleRejection()">Cancel</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($status === 'APPROVED' && $canExecute): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Execute this multi-destination swap? This will move real funds.')">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="execute">
                    <button type="submit" class="btn btn-success">🚀 Execute Disbursement</button>
                </form>
                <?php endif; ?>

                <?php if ($status === 'DRAFT'): ?>
                <a href="add_destinations.php?batch_id=<?php echo $batchId; ?>" class="btn btn-secondary">✏️ Edit Destinations</a>
                <?php endif; ?>

                <a href="../batches/index.php" class="btn btn-outline">📋 All Batches</a>
                <a href="../index.php" class="btn btn-outline">🏠 Dashboard</a>
            </div>
        </div>
    </main>

    <!-- ============================================================ -->
    <!-- FOOTER -->
    <!-- ============================================================ -->
    <footer class="footer">
        <div>VOUCHMORPH · Enterprise Disbursement Platform · <?php echo date('Y'); ?></div>
        <div style="margin-top:4px; color: rgba(255,255,255,0.2); font-size: 10px;">
            <?php echo $orgName; ?> · Role: <?php echo $roleDisplay; ?>
        </div>
    </footer>

    <script>
        function toggleRejection() {
            document.getElementById('rejectionForm').classList.toggle('show');
        }
    </script>
</body>
</html>
