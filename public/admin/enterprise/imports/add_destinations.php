<?php
// enterprise/imports/add_destinations.php - Add destinations for multi-destination swap
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

// Get batch
$batch = null;
if ($batchId) {
    $stmt = $db->prepare("
        SELECT * FROM disbursement_batches 
        WHERE id = :id AND organization_id = :org_id
    ");
    $stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$batch) {
    die("Batch not found.");
}

// Get existing destinations
$destinations = [];
$stmt = $db->prepare("
    SELECT * FROM disbursement_destinations 
    WHERE batch_id = :batch_id
    ORDER BY destination_index
");
$stmt->execute([':batch_id' => $batchId]);
$destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get participants for dropdown
$participants = [];
try {
    $stmt = $db->prepare("
        SELECT DISTINCT institution FROM source_accounts 
        WHERE organization_id = :org_id AND is_active = true
        UNION 
        SELECT 'CAZACOM' UNION SELECT 'SACCUSSALIS' UNION SELECT 'ZURUBANK' UNION SELECT 'VOUCHMORPH'
    ");
    $stmt->execute([':org_id' => $orgId]);
    $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $participants = ['CAZACOM', 'SACCUSSALIS', 'ZURUBANK', 'VOUCHMORPH'];
}

// ============================================================
// IDENTITY-BASED RECIPIENT TYPES
// ============================================================
$identityTypes = [
    'national_id' => 'National ID (Omang)',
    'voters_id' => "Voter's ID",
    'passport' => 'Passport Number',
    'drivers_license' => "Driver's License",
    'refugee_id' => 'Refugee ID',
    'birth_certificate' => 'Birth Certificate',
    'student_id' => 'Student ID',
    'employee_id' => 'Employee ID',
    'tribal_id' => 'Tribal ID',
    'residence_permit' => 'Residence Permit'
];

// ============================================================
// DELIVERY METHODS FOR IDENTITY-BASED RECIPIENTS
// ============================================================
$deliveryMethods = [
    'CASHOUT' => 'Cashout at Agent/ATM',
    'AGENT' => 'Agent Payout',
    'VOUCHER' => 'Voucher Code',
    'CARD' => 'Prepaid Card',
    'MOBILE_MONEY' => 'Mobile Money (No account needed)',
    'OVER_THE_COUNTER' => 'Over-the-Counter (Bank/Post Office)'
];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_destination') {
        $destinationData = json_decode($_POST['destination_data'] ?? '[]', true);
        
        if (empty($destinationData)) {
            $error = 'Please add at least one destination.';
        } else {
            try {
                $db->beginTransaction();
                
                $totalAmount = 0;
                $destCount = 0;
                $identityDestCount = 0;
                
                foreach ($destinationData as $dest) {
                    $destCount++;
                    $amount = floatval($dest['amount'] ?? 0);
                    $totalAmount += $amount;
                    
                    // Determine if this is an identity-based recipient
                    $isIdentityRecipient = ($dest['recipient_type'] ?? 'institution') === 'identity';
                    
                    if ($isIdentityRecipient) {
                        $identityDestCount++;
                        
                        $stmt = $db->prepare("
                            INSERT INTO disbursement_destinations (
                                batch_id, destination_index, institution, asset_type,
                                identifier, identifier_type, amount, currency,
                                delivery_method, beneficiary_name, beneficiary_phone,
                                beneficiary_email, beneficiary_national_id,
                                identity_type, identity_value, is_identity_recipient,
                                status
                            ) VALUES (
                                :batch_id, :index, :institution, :asset_type,
                                :identifier, :identifier_type, :amount, :currency,
                                :delivery_method, :beneficiary_name, :beneficiary_phone,
                                :beneficiary_email, :beneficiary_national_id,
                                :identity_type, :identity_value, true,
                                'PENDING'
                            )
                        ");
                        
                        $stmt->execute([
                            ':batch_id' => $batchId,
                            ':index' => $destCount,
                            ':institution' => 'IDENTITY_RECIPIENT',
                            ':asset_type' => 'IDENTITY',
                            ':identifier' => $dest['identity_value'] ?? '',
                            ':identifier_type' => $dest['identity_type'] ?? 'national_id',
                            ':amount' => $amount,
                            ':currency' => $dest['currency'] ?? 'BWP',
                            ':delivery_method' => $dest['delivery_method'] ?? 'AGENT',
                            ':beneficiary_name' => $dest['beneficiary_name'] ?? '',
                            ':beneficiary_phone' => $dest['beneficiary_phone'] ?? '',
                            ':beneficiary_email' => $dest['beneficiary_email'] ?? '',
                            ':beneficiary_national_id' => $dest['identity_value'] ?? '',
                            ':identity_type' => $dest['identity_type'] ?? 'national_id',
                            ':identity_value' => $dest['identity_value'] ?? ''
                        ]);
                        
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO disbursement_destinations (
                                batch_id, destination_index, institution, asset_type,
                                identifier, identifier_type, amount, currency,
                                delivery_method, beneficiary_name, beneficiary_phone,
                                beneficiary_email, beneficiary_national_id,
                                identity_type, identity_value, is_identity_recipient,
                                status
                            ) VALUES (
                                :batch_id, :index, :institution, :asset_type,
                                :identifier, :identifier_type, :amount, :currency,
                                :delivery_method, :beneficiary_name, :beneficiary_phone,
                                :beneficiary_email, :beneficiary_national_id,
                                :identity_type, :identity_value, false,
                                'PENDING'
                            )
                        ");
                        
                        $stmt->execute([
                            ':batch_id' => $batchId,
                            ':index' => $destCount,
                            ':institution' => $dest['institution'],
                            ':asset_type' => $dest['asset_type'] ?? 'WALLET',
                            ':identifier' => $dest['identifier'],
                            ':identifier_type' => $dest['identifier_type'] ?? 'phone',
                            ':amount' => $amount,
                            ':currency' => $dest['currency'] ?? 'BWP',
                            ':delivery_method' => $dest['delivery_method'] ?? 'DEPOSIT',
                            ':beneficiary_name' => $dest['beneficiary_name'] ?? '',
                            ':beneficiary_phone' => $dest['beneficiary_phone'] ?? '',
                            ':beneficiary_email' => $dest['beneficiary_email'] ?? '',
                            ':beneficiary_national_id' => $dest['beneficiary_national_id'] ?? '',
                            ':identity_type' => null,
                            ':identity_value' => null
                        ]);
                    }
                }
                
                // Update batch total
                $stmt = $db->prepare("
                    UPDATE disbursement_batches 
                    SET total_destinations = :count,
                        total_amount = :amount,
                        pending_count = :count,
                        identity_recipients = :identity_count,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':count' => $destCount,
                    ':amount' => $totalAmount,
                    ':identity_count' => $identityDestCount,
                    ':id' => $batchId
                ]);
                
                $db->commit();
                
                header("Location: review_batch.php?batch_id=$batchId");
                exit;
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("[add_destinations] Error: " . $e->getMessage());
                $error = "Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'clear_destinations') {
        $stmt = $db->prepare("DELETE FROM disbursement_destinations WHERE batch_id = :batch_id");
        $stmt->execute([':batch_id' => $batchId]);
        $success = "All destinations cleared.";
    }
}

$csrfToken = generateCsrfToken();
$roleDisplay = strtoupper($user['role'] ?? 'USER');
$orgName = htmlspecialchars($user['organization_name'] ?? 'ORGANIZATIONAL');

// Check if user can submit for approval
$canSubmit = in_array($user['role'] ?? '', ['owner', 'program_officer', 'department_head']);
$canApprove = in_array($user['role'] ?? '', ['owner', 'approver', 'senior_approver']);
$canExecute = in_array($user['role'] ?? '', ['owner']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Destinations · VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ... (keep all existing styles) ... */
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
            --identity-purple: #6f42c1;
            --identity-bg: #f8f0fc;
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
        .stage { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
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
        .form-group { margin-bottom: 12px; }
        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--ink-500);
            margin-bottom: 4px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1.5px solid var(--line);
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
            background: #fff;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--brass);
        }
        .form-group .hint {
            font-size: 10px;
            color: var(--ink-300);
            margin-top: 2px;
        }
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s;
            font-family: inherit;
        }
        .btn-primary { background: var(--ink-900); color: white; }
        .btn-primary:hover { background: var(--brass); }
        .btn-success { background: var(--ledger-green); color: white; }
        .btn-success:hover { background: #1a3d2c; }
        .btn-secondary { background: var(--line); color: var(--ink-700); }
        .btn-secondary:hover { background: #c0c8c4; }
        .btn-danger { background: var(--seal-red); color: white; }
        .btn-danger:hover { background: #5a1812; }
        .btn-outline { background: transparent; border: 2px solid var(--line); }
        .btn-outline:hover { border-color: var(--brass); }
        .btn-identity { background: var(--identity-purple); color: white; }
        .btn-identity:hover { background: #5a32a3; }
        .btn-sm { padding: 4px 12px; font-size: 10px; }
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
        .destination-row {
            background: #f8fafc;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            position: relative;
        }
        .destination-row.identity-row {
            background: var(--identity-bg);
            border-color: var(--identity-purple);
            border-left: 4px solid var(--identity-purple);
        }
        .destination-row .remove-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #fee2e2;
            color: #991b1b;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
        }
        .destination-row .identity-badge {
            position: absolute;
            top: 8px;
            right: 44px;
            background: var(--identity-purple);
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
        .grid-5 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr; gap: 12px; }
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin: 16px 0;
        }
        .stat {
            background: var(--panel);
            padding: 14px;
            border-radius: 8px;
            border: 1px solid var(--line);
            text-align: center;
        }
        .stat-value { font-size: 24px; font-weight: 700; }
        .stat-label { font-size: 10px; color: var(--ink-500); text-transform: uppercase; }
        .stat-value.identity-count { color: var(--identity-purple); }
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
        .actions-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .workflow-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        .status-draft { background: var(--line); color: var(--ink-500); }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fbeceb; color: var(--seal-red); }
        .toggle-group {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }
        .toggle-btn {
            padding: 6px 16px;
            border: 2px solid var(--line);
            border-radius: 20px;
            background: white;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .toggle-btn.active {
            border-color: var(--brass);
            background: var(--brass-tint);
        }
        .toggle-btn.identity-active {
            border-color: var(--identity-purple);
            background: var(--identity-bg);
        }
        .hidden { display: none !important; }
        .identity-fields {
            background: var(--identity-bg);
            border-radius: 8px;
            padding: 12px;
            margin-top: 8px;
            border: 1px dashed var(--identity-purple);
        }
        @media (max-width: 768px) {
            .grid-3, .grid-4, .grid-5 { grid-template-columns: 1fr; }
            .masthead { flex-direction: column; text-align: center; }
            .step-indicator { flex-wrap: wrap; gap: 8px; }
            .step { flex: 0 0 45%; }
            .summary-stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <div class="masthead">
        <h1>VouchMorph · Multi-Destination Disbursement</h1>
        <div>
            <span class="role-pill"><?php echo $roleDisplay; ?></span>
            <span style="color:var(--ink-300); font-size:12px; margin-left:12px;">
                Batch: <?php echo htmlspecialchars($batch['batch_reference']); ?>
            </span>
            <a href="../logout.php" style="color: rgba(255,255,255,0.4); text-decoration: none; margin-left: 16px; font-size: 12px;">Logout</a>
        </div>
    </div>

    <div class="stage">
        <a href="source_input.php?batch_id=<?php echo $batchId; ?>" class="back-link">← Back to Source Selection</a>

        <div class="step-indicator">
            <span class="step done">1. Select Source</span>
            <span class="step active">2. Add Destinations</span>
            <span class="step">3. Review</span>
            <span class="step">4. Approve</span>
            <span class="step">5. Execute</span>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Batch Status</span>
                <span>
                    <span class="workflow-status status-<?php echo strtolower($batch['status'] ?? 'draft'); ?>">
                        <?php echo htmlspecialchars($batch['status'] ?? 'DRAFT'); ?>
                    </span>
                </span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:12px; font-size:13px;">
                <div><strong>Source:</strong> <?php echo htmlspecialchars($batch['source_institution']); ?></div>
                <div><strong>Account:</strong> <?php echo htmlspecialchars($batch['source_identifier']); ?></div>
                <div><strong>Total Amount:</strong> <?php echo number_format($batch['total_amount'] ?? 0, 2); ?> <?php echo htmlspecialchars($batch['currency'] ?? 'BWP'); ?></div>
                <div><strong>Destinations:</strong> <?php echo $batch['total_destinations'] ?? 0; ?></div>
                <div><strong>Identity Recipients:</strong> <?php echo $batch['identity_recipients'] ?? 0; ?></div>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="success">✅ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" id="destinationForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="add_destination">
            <input type="hidden" name="destination_data" id="destinationData" value="[]">

            <div class="card">
                <div class="card-header">
                    <span class="card-title">👥 Add Destinations</span>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addRow('institution')">➕ Add Institution</button>
                        <button type="button" class="btn btn-identity btn-sm" onclick="addRow('identity')">🆔 Add Identity Recipient</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="addMultipleRows(5)">➕ Add 5</button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="clearRows()">🗑 Clear All</button>
                    </div>
                </div>

                <div id="destinationsContainer">
                    <!-- Rows added by JavaScript -->
                    <div class="destination-row" id="rowTemplate" style="display: none;">
                        <button type="button" class="remove-btn" onclick="removeRow(this)">✕</button>
                        <div class="identity-badge hidden">🆔 IDENTITY</div>
                        
                        <!-- Recipient Type Toggle -->
                        <div class="toggle-group">
                            <button type="button" class="toggle-btn active" data-type="institution" onclick="toggleRecipientType(this)">🏛️ Institution Account</button>
                            <button type="button" class="toggle-btn" data-type="identity" onclick="toggleRecipientType(this)">🆔 Identity-Based</button>
                        </div>

                        <!-- Institution Fields -->
                        <div class="institution-fields">
                            <div class="grid-4">
                                <div class="form-group">
                                    <label>Institution *</label>
                                    <select class="dest-institution">
                                        <option value="">Select</option>
                                        <?php foreach ($participants as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Asset Type</label>
                                    <select class="dest-asset-type">
                                        <option value="WALLET">Wallet</option>
                                        <option value="ACCOUNT">Bank Account</option>
                                        <option value="PHONE">Phone Number</option>
                                        <option value="EMAIL">Email</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Identifier *</label>
                                    <input type="text" class="dest-identifier" placeholder="Phone, Account, Email">
                                </div>
                                <div class="form-group">
                                    <label>Amount *</label>
                                    <input type="number" class="dest-amount" placeholder="0.00" step="0.01" min="0.01">
                                </div>
                            </div>
                        </div>

                        <!-- Identity Fields -->
                        <div class="identity-fields hidden">
                            <div class="grid-3">
                                <div class="form-group">
                                    <label>Identity Type *</label>
                                    <select class="dest-identity-type">
                                        <?php foreach ($identityTypes as $key => $label): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Identity Number *</label>
                                    <input type="text" class="dest-identity-value" placeholder="Enter ID number">
                                    <div class="hint">e.g., National ID, Voter's ID, Passport</div>
                                </div>
                                <div class="form-group">
                                    <label>Amount *</label>
                                    <input type="number" class="dest-amount" placeholder="0.00" step="0.01" min="0.01">
                                </div>
                            </div>
                            <div class="grid-3">
                                <div class="form-group">
                                    <label>Delivery Method *</label>
                                    <select class="dest-delivery-identity">
                                        <?php foreach ($deliveryMethods as $key => $label): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Beneficiary Name</label>
                                    <input type="text" class="dest-beneficiary-name" placeholder="Full name">
                                </div>
                                <div class="form-group">
                                    <label>Beneficiary Phone</label>
                                    <input type="tel" class="dest-beneficiary-phone" placeholder="+267XXXXXXXX">
                                    <div class="hint">For SMS notification</div>
                                </div>
                            </div>
                        </div>

                        <!-- Common Fields -->
                        <div class="grid-3" style="margin-top:8px;">
                            <div class="form-group">
                                <label>Beneficiary Email</label>
                                <input type="email" class="dest-beneficiary-email" placeholder="email@example.com">
                            </div>
                            <div class="form-group">
                                <label>Delivery Method</label>
                                <select class="dest-delivery">
                                    <option value="DEPOSIT">Deposit</option>
                                    <option value="CASHOUT">Cashout</option>
                                    <option value="VOUCHER">Voucher</option>
                                    <option value="ATM">ATM</option>
                                    <option value="AGENT">Agent</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Currency</label>
                                <select class="dest-currency">
                                    <option value="BWP">BWP - Botswana Pula</option>
                                    <option value="ZAR">ZAR - South African Rand</option>
                                    <option value="USD">USD - US Dollar</option>
                                    <option value="EUR">EUR - Euro</option>
                                    <option value="GBP">GBP - British Pound</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="summary-stats">
                    <div class="stat">
                        <div class="stat-value" id="destCount">0</div>
                        <div class="stat-label">Destinations</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value identity-count" id="identityCount">0</div>
                        <div class="stat-label">Identity Recipients</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="destTotal">BWP 0.00</div>
                        <div class="stat-label">Total Amount</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="destValid">0</div>
                        <div class="stat-label">Valid Entries</div>
                    </div>
                </div>

                <div class="actions-bar">
                    <button type="button" class="btn btn-secondary" onclick="addRow('institution')">➕ Add Another</button>
                    <button type="button" class="btn btn-identity" onclick="addRow('identity')">🆔 Add Identity Recipient</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled>💾 Save & Review</button>
                    <?php if ($batch['total_destinations'] > 0 && $canSubmit): ?>
                    <a href="submit_approval.php?batch_id=<?php echo $batchId; ?>" class="btn btn-success">📤 Submit for Approval</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php if (!empty($destinations)): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Existing Destinations</span>
                <div>
                    <span style="font-size:11px; color:var(--ink-300); margin-right:12px;">
                        <?php 
                        $identityCount = array_filter($destinations, function($d) { 
                            return ($d['is_identity_recipient'] ?? false) == true; 
                        });
                        echo count($identityCount) . ' identity recipients';
                        ?>
                    </span>
                    <span class="btn btn-danger btn-sm" onclick="clearDestinations()">🗑 Clear All</span>
                </div>
            </div>
            <div class="table-responsive">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:var(--ink-900); color:white;">
                            <th style="padding:10px; text-align:left;">#</th>
                            <th style="padding:10px; text-align:left;">Type</th>
                            <th style="padding:10px; text-align:left;">Institution/Identity</th>
                            <th style="padding:10px; text-align:left;">Identifier</th>
                            <th style="padding:10px; text-align:left;">Amount</th>
                            <th style="padding:10px; text-align:left;">Beneficiary</th>
                            <th style="padding:10px; text-align:left;">Delivery</th>
                            <th style="padding:10px; text-align:left;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($destinations as $dest): ?>
                        <tr style="border-bottom:1px solid var(--line); <?php echo ($dest['is_identity_recipient'] ?? false) ? 'background:var(--identity-bg);' : ''; ?>">
                            <td style="padding:10px;"><?php echo $dest['destination_index']; ?></td>
                            <td style="padding:10px;">
                                <?php if ($dest['is_identity_recipient'] ?? false): ?>
                                <span style="background:var(--identity-purple); color:white; padding:2px 8px; border-radius:12px; font-size:10px;">🆔 IDENTITY</span>
                                <?php else: ?>
                                <span style="background:var(--ledger-green); color:white; padding:2px 8px; border-radius:12px; font-size:10px;">🏛️ INSTITUTION</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;">
                                <?php if ($dest['is_identity_recipient'] ?? false): ?>
                                <?php echo htmlspecialchars($identityTypes[$dest['identity_type']] ?? $dest['identity_type'] ?? 'Identity'); ?>
                                <?php else: ?>
                                <?php echo htmlspecialchars($dest['institution']); ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;">
                                <?php if ($dest['is_identity_recipient'] ?? false): ?>
                                <?php echo htmlspecialchars($dest['identity_value'] ?? $dest['identifier']); ?>
                                <?php else: ?>
                                <?php echo htmlspecialchars($dest['identifier']); ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;"><?php echo number_format($dest['amount'], 2); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($dest['beneficiary_name'] ?? '-'); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($dest['delivery_method']); ?></td>
                            <td style="padding:10px;">
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
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!-- FIXED JAVASCRIPT -->
    <!-- ============================================================ -->
    <script>
        let rowCount = 0;

        function getTemplate() {
            return document.getElementById('rowTemplate').cloneNode(true);
        }

        function addRow(type) {
            const container = document.getElementById('destinationsContainer');
            const template = getTemplate();
            template.style.display = 'block';
            template.id = 'dest_row_' + rowCount;
            
            // Set row type
            template.dataset.type = type || 'institution';
            
            // Add event listeners to all inputs
            template.querySelectorAll('input, select').forEach(el => {
                el.addEventListener('input', function() {
                    updateSummary();
                    enableSubmit();
                });
                el.addEventListener('change', function() {
                    updateSummary();
                    enableSubmit();
                });
            });
            
            // Set initial visibility
            if (type === 'identity') {
                template.classList.add('identity-row');
                const badge = template.querySelector('.identity-badge');
                if (badge) badge.classList.remove('hidden');
                const instFields = template.querySelector('.institution-fields');
                if (instFields) instFields.classList.add('hidden');
                const identityFields = template.querySelector('.identity-fields');
                if (identityFields) identityFields.classList.remove('hidden');
                
                // Set toggle buttons
                const toggles = template.querySelectorAll('.toggle-btn');
                toggles.forEach(btn => {
                    btn.classList.remove('active', 'identity-active');
                    if (btn.dataset.type === 'identity') {
                        btn.classList.add('identity-active');
                    }
                });
            } else {
                const badge = template.querySelector('.identity-badge');
                if (badge) badge.classList.add('hidden');
                const instFields = template.querySelector('.institution-fields');
                if (instFields) instFields.classList.remove('hidden');
                const identityFields = template.querySelector('.identity-fields');
                if (identityFields) identityFields.classList.add('hidden');
                
                const toggles = template.querySelectorAll('.toggle-btn');
                toggles.forEach(btn => {
                    btn.classList.remove('active', 'identity-active');
                    if (btn.dataset.type === 'institution') {
                        btn.classList.add('active');
                    }
                });
            }
            
            container.appendChild(template);
            rowCount++;
            updateSummary();
            enableSubmit();
        }

        function toggleRecipientType(btn) {
            const row = btn.closest('.destination-row');
            const type = btn.dataset.type;
            const toggles = row.querySelectorAll('.toggle-btn');
            
            toggles.forEach(t => {
                t.classList.remove('active', 'identity-active');
            });
            
            if (type === 'identity') {
                btn.classList.add('identity-active');
                row.classList.add('identity-row');
                const badge = row.querySelector('.identity-badge');
                if (badge) badge.classList.remove('hidden');
                const instFields = row.querySelector('.institution-fields');
                if (instFields) instFields.classList.add('hidden');
                const identityFields = row.querySelector('.identity-fields');
                if (identityFields) identityFields.classList.remove('hidden');
                row.dataset.type = 'identity';
            } else {
                btn.classList.add('active');
                row.classList.remove('identity-row');
                const badge = row.querySelector('.identity-badge');
                if (badge) badge.classList.add('hidden');
                const instFields = row.querySelector('.institution-fields');
                if (instFields) instFields.classList.remove('hidden');
                const identityFields = row.querySelector('.identity-fields');
                if (identityFields) identityFields.classList.add('hidden');
                row.dataset.type = 'institution';
            }
            
            updateSummary();
            enableSubmit();
        }

        function addMultipleRows(count) {
            for (let i = 0; i < count; i++) addRow('institution');
        }

        function removeRow(btn) {
            const row = btn.closest('.destination-row');
            const visibleRows = document.querySelectorAll('.destination-row:not([style*="display: none"])');
            if (visibleRows.length > 1) {
                row.remove();
                updateSummary();
                enableSubmit();
            } else {
                alert('You need at least one destination.');
            }
        }

        function clearRows() {
            const rows = document.querySelectorAll('.destination-row:not([style*="display: none"])');
            if (rows.length <= 1) {
                alert('You need at least one destination.');
                return;
            }
            if (confirm('Remove all destinations?')) {
                rows.forEach((row, index) => {
                    if (index > 0) row.remove();
                });
                const firstRow = document.querySelector('.destination-row:not([style*="display: none"])');
                if (firstRow) {
                    firstRow.querySelectorAll('input, select').forEach(el => {
                        if (el.tagName === 'INPUT') el.value = '';
                        else if (el.tagName === 'SELECT') el.selectedIndex = 0;
                    });
                    const toggle = firstRow.querySelector('.toggle-btn[data-type="institution"]');
                    if (toggle) toggleRecipientType(toggle);
                }
                rowCount = 1;
                updateSummary();
                enableSubmit();
            }
        }

        function updateSummary() {
            const rows = document.querySelectorAll('.destination-row:not([style*="display: none"])');
            let total = 0;
            let valid = 0;
            let identityCount = 0;
            let institutionCount = 0;

            rows.forEach(row => {
                const isIdentity = row.dataset.type === 'identity';
                const amountInput = row.querySelector('.dest-amount');
                const amount = parseFloat(amountInput?.value) || 0;
                
                if (isIdentity) {
                    identityCount++;
                    const identityType = row.querySelector('.dest-identity-type')?.value;
                    const identityValue = row.querySelector('.dest-identity-value')?.value?.trim();
                    if (identityType && identityValue && amount > 0) {
                        total += amount;
                        valid++;
                    }
                } else {
                    institutionCount++;
                    const inst = row.querySelector('.dest-institution')?.value;
                    const ident = row.querySelector('.dest-identifier')?.value?.trim();
                    if (inst && ident && amount > 0) {
                        total += amount;
                        valid++;
                    }
                }
            });

            document.getElementById('destCount').textContent = rows.length;
            document.getElementById('identityCount').textContent = identityCount;
            document.getElementById('destTotal').textContent = 'BWP ' + total.toFixed(2);
            document.getElementById('destValid').textContent = valid;
        }

        function enableSubmit() {
            const rows = document.querySelectorAll('.destination-row:not([style*="display: none"])');
            let hasValid = false;

            rows.forEach(row => {
                const isIdentity = row.dataset.type === 'identity';
                const amountInput = row.querySelector('.dest-amount');
                const amount = parseFloat(amountInput?.value) || 0;
                
                if (isIdentity) {
                    const identityType = row.querySelector('.dest-identity-type')?.value;
                    const identityValue = row.querySelector('.dest-identity-value')?.value?.trim();
                    if (identityType && identityValue && amount > 0) {
                        hasValid = true;
                    }
                } else {
                    const inst = row.querySelector('.dest-institution')?.value;
                    const ident = row.querySelector('.dest-identifier')?.value?.trim();
                    if (inst && ident && amount > 0) {
                        hasValid = true;
                    }
                }
            });

            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.disabled = !hasValid;
            }
        }

        // Initialize with 2 rows - one institution, one identity
        document.addEventListener('DOMContentLoaded', function() {
            addRow('institution');
            addRow('identity');
        });

        // Before submit, gather all entries
        document.getElementById('destinationForm').addEventListener('submit', function(e) {
            const rows = document.querySelectorAll('.destination-row:not([style*="display: none"])');
            const entries = [];

            rows.forEach(row => {
                const isIdentity = row.dataset.type === 'identity';
                const amountInput = row.querySelector('.dest-amount');
                const amount = parseFloat(amountInput?.value) || 0;
                const name = row.querySelector('.dest-beneficiary-name')?.value?.trim() || '';
                const phone = row.querySelector('.dest-beneficiary-phone')?.value?.trim() || '';
                const email = row.querySelector('.dest-beneficiary-email')?.value?.trim() || '';
                const currency = row.querySelector('.dest-currency')?.value || 'BWP';
                const delivery = row.querySelector('.dest-delivery')?.value || 'DEPOSIT';

                if (isIdentity) {
                    const identityType = row.querySelector('.dest-identity-type')?.value || 'national_id';
                    const identityValue = row.querySelector('.dest-identity-value')?.value?.trim() || '';
                    const deliveryIdentity = row.querySelector('.dest-delivery-identity')?.value || 'AGENT';
                    
                    if (identityType && identityValue && amount > 0) {
                        entries.push({
                            recipient_type: 'identity',
                            identity_type: identityType,
                            identity_value: identityValue,
                            amount: amount,
                            beneficiary_name: name,
                            beneficiary_phone: phone,
                            beneficiary_email: email,
                            delivery_method: deliveryIdentity,
                            currency: currency
                        });
                    }
                } else {
                    const institution = row.querySelector('.dest-institution')?.value || '';
                    const assetType = row.querySelector('.dest-asset-type')?.value || 'WALLET';
                    const identifier = row.querySelector('.dest-identifier')?.value?.trim() || '';
                    
                    if (institution && identifier && amount > 0) {
                        entries.push({
                            recipient_type: 'institution',
                            institution: institution,
                            asset_type: assetType,
                            identifier: identifier,
                            identifier_type: assetType === 'ACCOUNT' ? 'account_number' : 'phone',
                            amount: amount,
                            beneficiary_name: name,
                            beneficiary_phone: phone,
                            beneficiary_email: email,
                            delivery_method: delivery,
                            currency: currency
                        });
                    }
                }
            });

            if (entries.length === 0) {
                e.preventDefault();
                alert('Please add at least one valid destination.');
                return;
            }

            document.getElementById('destinationData').value = JSON.stringify(entries);
        });

        function clearDestinations() {
            if (confirm('Are you sure you want to clear all destinations?')) {
                window.location.href = 'add_destinations.php?batch_id=<?php echo $batchId; ?>&action=clear';
            }
        }
    </script>
</body>
</html>
