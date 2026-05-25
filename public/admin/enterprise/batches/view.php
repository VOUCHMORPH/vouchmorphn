<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getInstance();
$orgId = getOrganizationId();
$batchId = $_GET['id'] ?? 0;

$stmt = $db->prepare("SELECT * FROM import_batches WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

// Get payment instructions for this batch
$stmt = $db->prepare("
    SELECT * FROM payment_instructions 
    WHERE batch_id = :batch_id 
    ORDER BY created_at
");
$stmt->execute([':batch_id' => $batchId]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get import rows with validation status
$stmt = $db->prepare("
    SELECT * FROM import_rows 
    WHERE batch_id = :batch_id 
    ORDER BY row_number
");
$stmt->execute([':batch_id' => $batchId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$successCount = count(array_filter($payments, fn($p) => $p['status'] === 'SUCCESS'));
$failedCount = count(array_filter($payments, fn($p) => $p['status'] === 'FAILED'));
$pendingCount = count(array_filter($payments, fn($p) => $p['status'] === 'PENDING'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Details - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }
        .app { display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px;
            background: #0f172a;
            color: #e2e8f0;
            position: fixed;
            height: 100vh;
        }
        .sidebar-header { padding: 24px; border-bottom: 1px solid #1e293b; }
        .sidebar-header h2 { font-size: 20px; font-weight: 700; }
        .sidebar-header span { color: #fbbf24; }
        .sidebar-nav { padding: 20px 0; }
        .nav-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e1;
            text-decoration: none;
        }
        .nav-item:hover { background: #1e293b; color: white; }
        .main {
            flex: 1;
            margin-left: 280px;
            padding: 24px 32px;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .greeting h1 { font-size: 28px; font-weight: 700; }
        .card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
        }
        .card-body { padding: 24px; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-value { font-size: 32px; font-weight: 700; }
        .stat-label { color: #64748b; font-size: 14px; margin-top: 4px; }
        .btn-group { display: flex; gap: 16px; }
        .btn {
            padding: 10px 20px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .status-SUCCESS { background: #dcfce7; color: #166534; }
        .status-FAILED { background: #fee2e2; color: #991b1b; }
        .status-PENDING { background: #fef3c7; color: #92400e; }
        .alert {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="app">
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>VouchMorph <span>Enterprise</span></h2>
        </div>
        <div class="sidebar-nav">
            <a href="../index.php" class="nav-item">📊 Dashboard</a>
            <a href="../imports/upload.php" class="nav-item">📁 New Payment</a>
            <a href="index.php" class="nav-item active">📦 Batches</a>
            <a href="../beneficiaries/index.php" class="nav-item">👥 Beneficiaries</a>
            <a href="../templates/index.php" class="nav-item">📋 Templates</a>
            <a href="../reports/index.php" class="nav-item">📄 Reports</a>
            <a href="../settings/index.php" class="nav-item">⚙️ Settings</a>
        </div>
    </div>
    
    <div class="main">
        <div class="top-bar">
            <div class="greeting">
                <h1>Batch: <?php echo htmlspecialchars($batch['batch_reference']); ?></h1>
            </div>
            <div class="btn-group">
                <?php if ($batch['status'] === 'READY_FOR_APPROVAL' && ($user['role'] === 'admin' || $user['role'] === 'owner')): ?>
                    <button class="btn btn-success" onclick="approveBatch()">✓ Approve Batch</button>
                    <button class="btn btn-danger" onclick="rejectBatch()">✗ Reject</button>
                <?php endif; ?>
                <?php if ($batch['status'] === 'APPROVED'): ?>
                    <a href="../imports/execute.php?batch_id=<?php echo $batchId; ?>" class="btn btn-primary">▶ Execute Batch</a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-secondary">← Back</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">P<?php echo number_format($batch['total_amount'], 2); ?></div>
                <div class="stat-label">Total Amount</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $batch['total_rows']; ?></div>
                <div class="stat-label">Total Recipients</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $successCount; ?></div>
                <div class="stat-label">✅ Successful</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $failedCount; ?></div>
                <div class="stat-label">❌ Failed</div>
            </div>
        </div>
        
        <?php if ($batch['status'] === 'READY_FOR_APPROVAL'): ?>
        <div class="alert">
            ⏳ This batch is pending approval. An authorized approver must review and approve before execution.
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">📋 Batch Details</div>
            <div class="card-body">
                <table style="width: auto;">
                    <tr><td style="border: none; padding: 8px 0;"><strong>Batch Name:</strong></td><td style="border: none;"><?php echo htmlspecialchars($batch['batch_name']); ?></td></tr>
                    <tr><td style="border: none; padding: 8px 0;"><strong>Created:</strong></td><td style="border: none;"><?php echo date('F d, Y H:i:s', strtotime($batch['created_at'])); ?></td></tr>
                    <tr><td style="border: none; padding: 8px 0;"><strong>Uploaded By:</strong></td><td style="border: none;">User ID: <?php echo $batch['uploaded_by']; ?></td></tr>
                    <tr><td style="border: none; padding: 8px 0;"><strong>Status:</strong></td><td style="border: none;"><span class="status status-<?php echo $batch['status']; ?>"><?php echo $batch['status']; ?></span></td></tr>
                    <?php if ($batch['approved_at']): ?>
                    <tr><td style="border: none; padding: 8px 0;"><strong>Approved:</strong></td><td style="border: none;"><?php echo date('F d, Y H:i:s', strtotime($batch['approved_at'])); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">💰 Payment Instructions</div>
            <div class="card-body">
                <div style="overflow-x: auto; max-height: 400px;">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Recipient</th><th>Destination</th><th>Amount</th><th>Swap Reference</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo $payment['id']; ?></td>
                                <td><?php echo htmlspecialchars($payment['recipient_name']); ?></td>
                                <td><?php echo htmlspecialchars($payment['destination_value']); ?></td>
                                <td>P<?php echo number_format($payment['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($payment['swap_reference'] ?? '-'); ?></td>
                                <td><span class="status status-<?php echo $payment['status']; ?>"><?php echo $payment['status']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($payments)): ?>
                            <tr><td colspan="6" style="text-align: center; padding: 40px;">No payments executed yet. Approve and execute batch to begin.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function approveBatch() {
    fetch('../imports/approve.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            batch_id: <?php echo $batchId; ?>,
            action: 'approve'
        })
    }).then(res => res.json()).then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function rejectBatch() {
    const reason = prompt('Reason for rejection:');
    if (reason) {
        fetch('../imports/approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                batch_id: <?php echo $batchId; ?>,
                action: 'reject',
                reason: reason
            })
        }).then(res => res.json()).then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        });
    }
}
</script>
</body>
</html>
