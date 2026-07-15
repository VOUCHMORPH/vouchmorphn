<?php
/**
 * enterprise/imports/review.php
 * 
 * FIXED: Uses disbursement_batches and disbursement_destinations
 * instead of import_batches and import_rows
 */
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$batchId = $_GET['batch_id'] ?? 0;

// FIXED: Use disbursement_batches
$stmt = $db->prepare("
    SELECT * FROM disbursement_batches 
    WHERE id = :id AND organization_id = :org_id
");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

// FIXED: Get source from source_accounts
$source = null;
if ($batch['source_account_id']) {
    $stmt = $db->prepare("
        SELECT * FROM source_accounts 
        WHERE id = :id AND organization_id = :org_id
    ");
    $stmt->execute([':id' => $batch['source_account_id'], ':org_id' => $orgId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
}

// FIXED: Use disbursement_destinations
$stmt = $db->prepare("
    SELECT * FROM disbursement_destinations
    WHERE batch_id = :batch_id
    ORDER BY destination_index
");
$stmt->execute([':batch_id' => $batchId]);
$destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalAmount = array_sum(array_column($destinations, 'amount'));
$feeRate = 0.015;
$totalFee = $totalAmount * $feeRate;
$netAmount = $totalAmount - $totalFee;

$csrfToken = generateCsrfToken();
$userRole = $user['role'] ?? 'viewer';
$canSubmit = in_array($userRole, ['owner', 'program_officer', 'department_head']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Batch - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #0f172a; }
        .app { display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background: #0f172a; color: #e2e8f0; position: fixed; height: 100vh; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid #1e293b; }
        .sidebar-header h2 { font-size: 20px; font-weight: 700; }
        .sidebar-header span { color: #fbbf24; }
        .sidebar-nav { padding: 20px 0; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #cbd5e1; text-decoration: none; }
        .nav-item:hover { background: #1e293b; color: white; }
        .main { flex: 1; margin-left: 280px; padding: 24px 32px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }
        .greeting h1 { font-size: 28px; font-weight: 700; }
        .step-indicator { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; max-width: 800px; }
        .step { flex: 1; text-align: center; }
        .step-number { width: 32px; height: 32px; background: #e2e8f0; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; margin-bottom: 8px; }
        .step.completed .step-number { background: #10b981; color: white; }
        .step.active .step-number { background: #0f172a; color: white; }
        .step-label { font-size: 12px; color: #64748b; }
        .card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; font-weight: 600; }
        .card-body { padding: 24px; }
        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
        .summary-card { background: #f8fafc; padding: 20px; border-radius: 16px; text-align: center; }
        .summary-label { font-size: 13px; color: #64748b; margin-bottom: 8px; }
        .summary-value { font-size: 28px; font-weight: 700; }
        .fee-breakdown { background: #f0fdf4; padding: 20px; border-radius: 16px; margin-bottom: 24px; }
        .fee-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #dcfce7; }
        .total-row { display: flex; justify-content: space-between; padding: 12px 0; font-weight: 700; border-top: 2px solid #10b981; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        .btn-group { display: flex; gap: 16px; justify-content: flex-end; margin-top: 24px; }
        .btn { padding: 12px 28px; border-radius: 40px; font-weight: 600; cursor: pointer; border: none; font-size: 14px; }
        .btn-primary { background: #0f172a; color: white; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .btn-success { background: #10b981; color: white; }
        .requires-approval { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px; border-radius: 12px; margin-bottom: 20px; }
        .identity-badge { background: #6f42c1; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; }
    </style>
</head>
<body>
<div class="app">
    <div class="sidebar">
        <div class="sidebar-header"><h2>VouchMorph <span>Enterprise</span></h2></div>
        <div class="sidebar-nav">
            <a href="../index.php" class="nav-item">📊 Dashboard</a>
            <a href="source_input.php" class="nav-item active">📁 New Payment</a>
            <a href="../batches/index.php" class="nav-item">📦 Batches</a>
            <a href="../beneficiaries.php" class="nav-item">👥 Beneficiaries</a>
            <a href="../reports.php" class="nav-item">📄 Reports</a>
            <a href="../settings.php" class="nav-item">⚙️ Settings</a>
        </div>
    </div>

    <div class="main">
        <div class="top-bar"><div class="greeting"><h1>Review & Approve</h1></div></div>

        <div class="step-indicator">
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Source</div></div>
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Destinations</div></div>
            <div class="step active"><div class="step-number">3</div><div class="step-label">Review</div></div>
            <div class="step"><div class="step-number">4</div><div class="step-label">Approve</div></div>
            <div class="step"><div class="step-number">5</div><div class="step-label">Execute</div></div>
        </div>

        <div class="requires-approval">
            ⚠️ This batch requires approval before execution. An authorized approver must review and confirm.
        </div>

        <div class="card">
            <div class="card-header">📊 Payment Summary</div>
            <div class="card-body">
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Total Recipients</div>
                        <div class="summary-value"><?php echo count($destinations); ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Gross Amount</div>
                        <div class="summary-value">P<?php echo number_format($totalAmount, 2); ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Source Account</div>
                        <div class="summary-value" style="font-size: 16px;">
                            <?php 
                            if ($source) {
                                echo htmlspecialchars($source['institution'] . ' - ' . $source['source_identifier']);
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="fee-breakdown">
                    <h4 style="margin-bottom: 12px;">💰 Fee Breakdown</h4>
                    <div class="fee-row"><span>Gross Amount</span><span>P<?php echo number_format($totalAmount, 2); ?></span></div>
                    <div class="fee-row"><span>VouchMorph Fee (<?php echo $feeRate * 100; ?>%)</span><span>- P<?php echo number_format($totalFee, 2); ?></span></div>
                    <div class="total-row"><span>Net to Destination</span><span>P<?php echo number_format($netAmount, 2); ?></span></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">📋 Destination List (<?php echo count($destinations); ?> recipients)</div>
            <div class="card-body">
                <div style="overflow-x: auto; max-height: 400px;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Type</th>
                                <th>Name</th>
                                <th>Destination</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($destinations as $index => $dest): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <?php if ($dest['is_identity_recipient'] ?? false): ?>
                                    <span class="identity-badge">🆔 IDENTITY</span>
                                    <?php else: ?>
                                    <span style="background:#e2e8f0; padding:2px 8px; border-radius:12px; font-size:10px;">🏛️ INST</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($dest['beneficiary_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php 
                                    if ($dest['is_identity_recipient'] ?? false) {
                                        echo htmlspecialchars($dest['identity_type'] . ': ' . $dest['identity_value']);
                                    } else {
                                        echo htmlspecialchars($dest['identifier']);
                                    }
                                    ?>
                                    <br><small>
                                        <?php echo htmlspecialchars($dest['institution'] ?? 'N/A'); ?>
                                        · <?php echo htmlspecialchars($dest['delivery_method']); ?>
                                    </small>
                                </td>
                                <td>P<?php echo number_format($dest['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="btn-group">
            <button class="btn btn-secondary" onclick="location.href='add_destinations.php?batch_id=<?php echo $batchId; ?>'">← Back</button>
            <?php if ($canSubmit && $batch['status'] === 'draft'): ?>
            <button class="btn btn-success" onclick="submitForApproval()">✓ Submit for Approval</button>
            <?php endif; ?>
            <?php if ($batch['status'] === 'pending_approval'): ?>
            <button class="btn btn-primary" disabled>⏳ Pending Approval</button>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const BATCH_ID = <?php echo (int)$batchId; ?>;

function submitForApproval() {
    if (!confirm('Submit this batch for approval? Once submitted, it cannot be edited without approval.')) {
        return;
    }
    
    fetch('approve.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            batch_id: BATCH_ID,
            action: 'submit',
            csrf_token: CSRF_TOKEN
        })
    }).then(res => res.json()).then(data => {
        if (data.success) {
            window.location.href = '../batches/view.php?id=' + BATCH_ID;
        } else {
            alert('Error: ' + data.error);
        }
    }).catch(err => {
        alert('Network error: ' + err.message);
    });
}
</script>
</body>
</html>
