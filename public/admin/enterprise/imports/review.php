<?php
/**
 * enterprise/imports/review.php
 * 
 * FIXED: Uses disbursement_batches and disbursement_destinations
 * instead of import_batches and import_rows
 */
require_once __DIR__ . '/../../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
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
$orgName = htmlspecialchars($user['organization_name'] ?? 'ORGANIZATIONAL');
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$roleDisplay = strtoupper($user['role'] ?? 'USER');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Batch · VOUCHMORPH</title>
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

        /* ===== SUMMARY GRID ===== */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: #f8fafc;
            padding: 16px 20px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        .summary-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #94a3b8;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .summary-value {
            font-size: 24px;
            font-weight: 700;
            margin-top: 4px;
        }

        /* ===== FEE BREAKDOWN ===== */
        .fee-breakdown {
            background: #f0fdf4;
            padding: 16px 20px;
            border-radius: 12px;
            border: 1px solid #dcfce7;
        }
        .fee-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #dcfce7;
            font-size: 13px;
        }
        .fee-row:last-child { border-bottom: none; }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0 4px;
            font-weight: 700;
            border-top: 2px solid #166534;
            margin-top: 4px;
            font-size: 14px;
        }

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

        /* ===== BADGES ===== */
        .identity-badge {
            background: #6f42c1;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .institution-badge {
            background: #e2e8f0;
            color: #64748b;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
        }

        /* ===== ALERT ===== */
        .requires-approval {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .requires-approval .icon { font-size: 18px; }

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
        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        .btn-group {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-top: 16px;
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
            .summary-grid { grid-template-columns: 1fr; }
            .step-indicator { flex-wrap: wrap; gap: 8px; }
            .step { flex: 0 0 45%; }
            .btn-group { flex-direction: column; }
            .btn-group .btn { width: 100%; text-align: center; }
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
                <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
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
                <div class="sub">Review batch details before submission</div>
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

        <!-- Approval Notice -->
        <div class="requires-approval">
            <span class="icon">⚠️</span>
            <span>This batch requires approval before execution. An authorized approver must review and confirm.</span>
        </div>

        <!-- Payment Summary -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📊 Payment Summary</span>
                <span class="card-badge"><?php echo count($destinations); ?> RECIPIENTS</span>
            </div>
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
                <h4 style="margin-bottom: 10px; font-size: 13px;">💰 Fee Breakdown</h4>
                <div class="fee-row"><span>Gross Amount</span><span>P<?php echo number_format($totalAmount, 2); ?></span></div>
                <div class="fee-row"><span>VouchMorph Fee (<?php echo $feeRate * 100; ?>%)</span><span>- P<?php echo number_format($totalFee, 2); ?></span></div>
                <div class="total-row"><span>Net to Destination</span><span>P<?php echo number_format($netAmount, 2); ?></span></div>
            </div>
        </div>

        <!-- Destination List -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Destination List</span>
                <span class="card-badge"><?php echo count($destinations); ?> DESTINATIONS</span>
            </div>
            <div class="table-responsive">
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
                                <span class="institution-badge">🏛️ INST</span>
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
                                <br><small style="color:#94a3b8;">
                                    <?php echo htmlspecialchars($dest['institution'] ?? 'N/A'); ?>
                                    · <?php echo htmlspecialchars($dest['delivery_method']); ?>
                                </small>
                            </td>
                            <td><strong>P<?php echo number_format($dest['amount'], 2); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Actions -->
        <div class="btn-group">
            <button class="btn btn-secondary" onclick="location.href='add_destinations.php?batch_id=<?php echo $batchId; ?>'">← Back</button>
            <?php if ($canSubmit && $batch['status'] === 'draft'): ?>
            <button class="btn btn-success" onclick="submitForApproval()">✓ Submit for Approval</button>
            <?php endif; ?>
            <?php if ($batch['status'] === 'pending_approval'): ?>
            <button class="btn btn-primary" disabled>⏳ Pending Approval</button>
            <?php endif; ?>
            <?php if ($batch['status'] === 'approved'): ?>
            <button class="btn btn-success" disabled>✅ Approved</button>
            <?php endif; ?>
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
