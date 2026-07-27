<?php
// enterprise/imports/add_destinations.php - Add destinations for multi-destination swap
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
$role = $user['role'] ?? 'viewer';
$userDepartmentId = $user['department_id'] ?? null;
$batchId = $_GET['batch_id'] ?? 0;

$deptService = new DepartmentService($db);

function safeHtmlAD($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

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

// ============================================================
// PERMISSIONS — this page previously had none at all: any authenticated
// role could POST here regardless of who created the batch. Mirrors
// canEditBatch() from review_batch.php/view.php, extended for
// beneficiary_registrar, whose entire role (per UserManagementService::
// ROLE_CATALOG) is adding destinations within their own department —
// exact match only, same as every other creator-role check in this app.
// ============================================================
function canAddDestinations(array $batch, $userId, string $role, ?int $userDepartmentId): bool {
    if ($role === 'owner') return true;
    if (in_array($role, ['program_officer', 'department_head'], true)) {
        return $batch['created_by'] == $userId;
    }
    if ($role === 'beneficiary_registrar') {
        return $userDepartmentId !== null && (int)$userDepartmentId === (int)($batch['department_id'] ?? 0);
    }
    return false;
}
$canEdit = canAddDestinations($batch, $userId, $role, $userDepartmentId);

// ============================================================
// STATUS GUARD — previously missing entirely, meaning destinations (and
// the batch total) could be added or cleared even after approval or
// after execution had started. Only a draft batch can be edited here.
// ============================================================
$isDraft = (strtolower($batch['status'] ?? 'draft') === 'draft');

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
// NOTE: CAZACOM/SACCUSSALIS/ZURUBANK/VOUCHMORPH read as placeholder/test
// institution names, not real Botswana participants. Worth confirming
// these get replaced with real institution adapters before this batch
// type is used for anything beyond practice — this list is exactly what
// tools/market_readiness_check.php's participant check is meant to catch.

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

$deliveryMethods = [
    'CASHOUT' => 'Cashout at Agent/ATM',
    'AGENT' => 'Agent Payout',
    'VOUCHER' => 'Voucher Code',
    'CARD' => 'Prepaid Card',
    'MOBILE_MONEY' => 'Mobile Money (No account needed)',
    'OVER_THE_COUNTER' => 'Over-the-Counter (Bank/Post Office)'
];

function identifierTypeForAsset(string $assetType): string {
    return match (strtoupper($assetType)) {
        'ACCOUNT' => 'account_number',
        'EMAIL' => 'email',
        'PHONE' => 'phone',
        default => 'wallet_id',
    };
}

/**
 * Recomputes and writes total_destinations/total_amount/pending_count/
 * identity_recipients from the FULL current set of destination rows —
 * never from just what was submitted in one POST. The previous version
 * overwrote these with only the newest batch of rows, so a second save
 * silently lost the first save's totals even though those rows were
 * still in the database.
 */
function recomputeBatchTotals(PDO $db, $batchId): void {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total,
               COALESCE(SUM(CASE WHEN is_identity_recipient THEN 1 ELSE 0 END), 0) AS identity_cnt
        FROM disbursement_destinations WHERE batch_id = :id
    ");
    $stmt->execute([':id' => $batchId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        UPDATE disbursement_batches
        SET total_destinations = :cnt, total_amount = :amt,
            pending_count = :cnt, identity_recipients = :idc, updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':cnt' => (int)$totals['cnt'], ':amt' => (float)$totals['total'],
        ':idc' => (int)$totals['identity_cnt'], ':id' => $batchId,
    ]);
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if (!$canEdit) {
        $error = "You don't have permission to modify this batch.";
    } elseif (!$isDraft) {
        $error = "This batch is no longer a draft (status: " . safeHtmlAD($batch['status']) . ") — destinations can't be added or cleared here anymore. "
            . '<a href="review_batch.php?batch_id=' . (int)$batchId . '" style="color:var(--brass); font-weight:600;">Go to Review →</a>';
    } elseif ($action === 'add_destination') {
        $destinationData = json_decode($_POST['destination_data'] ?? '[]', true);

        if (!is_array($destinationData) || empty($destinationData)) {
            $error = 'Please add at least one destination.';
        } else {
            // ============================================================
            // Validate every row BEFORE writing anything — reject the
            // whole submission on any problem rather than partially
            // inserting some rows and silently skipping others.
            // ============================================================
            $rowErrors = [];
            $seenThisSubmission = [];

            $existing = [];
            $stmt = $db->prepare("SELECT identifier, identity_value FROM disbursement_destinations WHERE batch_id = :id");
            $stmt->execute([':id' => $batchId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
                $key = strtolower(trim((string)($ex['identity_value'] ?: $ex['identifier'])));
                if ($key !== '') $existing[$key] = true;
            }

            foreach ($destinationData as $i => $dest) {
                $rowNum = $i + 1;
                $amount = filter_var($dest['amount'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($amount === false || $amount <= 0) {
                    $rowErrors[] = "Row {$rowNum}: amount must be a positive number.";
                    continue;
                }

                $isIdentity = ($dest['recipient_type'] ?? 'institution') === 'identity';
                if ($isIdentity) {
                    $idVal = trim((string)($dest['identity_value'] ?? ''));
                    if ($idVal === '') {
                        $rowErrors[] = "Row {$rowNum}: identity number is required.";
                        continue;
                    }
                    $key = strtolower($idVal);
                } else {
                    $inst = trim((string)($dest['institution'] ?? ''));
                    $ident = trim((string)($dest['identifier'] ?? ''));
                    if ($inst === '' || $ident === '') {
                        $rowErrors[] = "Row {$rowNum}: institution and identifier are required.";
                        continue;
                    }
                    $key = strtolower($ident);
                }

                // Duplicate-recipient check — same person twice in one
                // batch is the add-side version of the double-payment
                // risk hardened on the execute side. Catches duplicates
                // both within this submission and against rows already
                // saved from an earlier call.
                if ($key !== '') {
                    if (isset($seenThisSubmission[$key])) {
                        $rowErrors[] = "Row {$rowNum}: this recipient appears more than once in this submission — remove the duplicate.";
                    } elseif (isset($existing[$key])) {
                        $rowErrors[] = "Row {$rowNum}: this recipient is already in this batch from an earlier save.";
                    }
                    $seenThisSubmission[$key] = true;
                }
            }

            if (!empty($rowErrors)) {
                $error = "Fix the following before saving:<br>" . implode('<br>', array_map('htmlspecialchars', $rowErrors));
            } else {
                try {
                    $db->beginTransaction();

                    // Continue destination_index from whatever's already
                    // in the batch, instead of restarting at 1 — the old
                    // version restarted every call, producing duplicate
                    // index values that break the per-destination status
                    // updates in review_batch.php's execute loop.
                    $stmt = $db->prepare("SELECT COALESCE(MAX(destination_index), 0) FROM disbursement_destinations WHERE batch_id = :id");
                    $stmt->execute([':id' => $batchId]);
                    $nextIndex = (int)$stmt->fetchColumn();

                    foreach ($destinationData as $dest) {
                        $nextIndex++;
                        $amount = (float)$dest['amount'];
                        $isIdentityRecipient = ($dest['recipient_type'] ?? 'institution') === 'identity';

                        if ($isIdentityRecipient) {
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
                                ':index' => $nextIndex,
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
                            $assetType = $dest['asset_type'] ?? 'WALLET';
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
                                ':index' => $nextIndex,
                                ':institution' => $dest['institution'],
                                ':asset_type' => $assetType,
                                ':identifier' => $dest['identifier'],
                                ':identifier_type' => identifierTypeForAsset($assetType),
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

                    recomputeBatchTotals($db, $batchId);

                    $db->commit();

                    // ============================================================
                    // FIX: this used to also flip status to 'pending_approval'
                    // here, with NO ration check — a third, unguarded path to
                    // submission alongside review_batch.php and view.php, both
                    // of which correctly call assertBatchFitsRation() first.
                    // This page now only ever saves as a draft; submission
                    // happens exclusively through review_batch.php's already
                    // ration-checked "Submit for Approval" button.
                    // ============================================================
                    header("Location: review_batch.php?batch_id=$batchId&saved=1");
                    exit;

                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("[add_destinations] Error: " . $e->getMessage());
                    $error = "Error saving destinations: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'clear_destinations') {
        $stmt = $db->prepare("DELETE FROM disbursement_destinations WHERE batch_id = :batch_id");
        $stmt->execute([':batch_id' => $batchId]);
        recomputeBatchTotals($db, $batchId);
        $success = "All destinations cleared.";
        header("Location: add_destinations.php?batch_id=$batchId&cleared=1");
        exit;
    }
}

$csrfToken = generateCsrfToken();
$roleDisplay = strtoupper(UserManagementService::ROLE_CATALOG[$role]['label'] ?? $role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Destinations · VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #EEF1EF; --panel: #FFFFFF; --ink-900: #0F2138; --ink-700: #1D3557;
            --ink-500: #4A5A6E; --ink-300: #8A96A3; --line: #D3DAD6; --brass: #8A6D3B;
            --brass-tint: #F4EFE3; --seal-red: #7A2118; --ledger-green: #24513A;
            --identity-purple: #6f42c1; --identity-bg: #f8f0fc;
            --f-body: 'IBM Plex Sans', sans-serif; --f-cond: 'IBM Plex Sans Condensed', sans-serif;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); min-height: 100vh; font-size:14px; }
        .masthead { background: var(--ink-900); color: white; padding: 14px 32px; display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid var(--brass); flex-wrap: wrap; gap: 10px; }
        .masthead h1 { font-family:var(--f-cond); font-size: 18px; font-weight: 700; }
        .masthead .role-pill { font-size: 10px; font-weight: 700; color: var(--brass); border: 1px solid var(--brass); padding: 2px 10px; text-transform: uppercase; font-family:var(--f-cond); }
        .stage { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .card { background: var(--panel); border: 1px solid var(--line); padding: 24px; margin-bottom: 20px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid var(--line); flex-wrap: wrap; gap: 10px; }
        .card-title { font-size: 16px; font-weight: 700; text-transform: uppercase; font-family:var(--f-cond); }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--ink-500); margin-bottom: 4px; font-family:var(--f-cond); }
        .form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1.5px solid var(--line); font-size: 13px; font-family: inherit; background: #fff; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--brass); }
        .form-group .hint { font-size: 10px; color: var(--ink-300); margin-top: 2px; }
        .btn { padding: 8px 20px; border: none; font-weight: 600; font-size: 12px; cursor: pointer; transition: all 0.15s; font-family: var(--f-cond); text-transform:uppercase; letter-spacing:.03em; }
        .btn-primary { background: var(--ink-900); color: white; }
        .btn-primary:hover { background: var(--brass); }
        .btn-primary:disabled { opacity:0.5; cursor:not-allowed; }
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
        .error { background: #fbeceb; color: var(--seal-red); padding: 12px 16px; margin-bottom: 16px; border-left: 3px solid var(--seal-red); line-height:1.6; }
        .success { background: #dcfce7; color: #166534; padding: 12px 16px; margin-bottom: 16px; border-left: 3px solid #10b981; }
        .locked-notice { background: #fef3c7; color: #92400e; padding: 12px 16px; margin-bottom: 16px; border-left: 3px solid #f59e0b; }
        .destination-row { background: #f8fafc; border: 1px solid var(--line); padding: 16px; margin-bottom: 12px; position: relative; }
        .destination-row.identity-row { background: var(--identity-bg); border-color: var(--identity-purple); border-left: 4px solid var(--identity-purple); }
        .destination-row .remove-btn { position: absolute; top: 8px; right: 8px; background: #fee2e2; color: #991b1b; border: none; border-radius: 50%; width: 28px; height: 28px; cursor: pointer; font-size: 16px; line-height: 1; }
        .destination-row .identity-badge { position: absolute; top: 8px; right: 44px; background: var(--identity-purple); color: white; padding: 2px 10px; font-size: 9px; font-weight: 600; text-transform: uppercase; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
        .summary-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 16px 0; }
        .stat { background: var(--panel); padding: 14px; border: 1px solid var(--line); text-align: center; }
        .stat-value { font-size: 24px; font-weight: 700; font-family:var(--f-cond); }
        .stat-label { font-size: 10px; color: var(--ink-500); text-transform: uppercase; font-family:var(--f-cond); }
        .stat-value.identity-count { color: var(--identity-purple); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--ink-500); text-decoration: none; font-size: 12px; font-weight: 600; margin-bottom: 16px; font-family:var(--f-cond); }
        .back-link:hover { color: var(--brass); }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 24px; padding: 0 20px; }
        .step { flex: 1; text-align: center; font-size: 11px; font-weight: 600; color: var(--ink-300); text-transform: uppercase; font-family:var(--f-cond); }
        .step.active { color: var(--ink-900); }
        .step.done { color: var(--ledger-green); }
        .actions-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .workflow-status { padding: 4px 14px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; font-family:var(--f-cond); }
        .status-draft { background: var(--line); color: var(--ink-500); }
        .status-pending_approval { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fbeceb; color: var(--seal-red); }
        .toggle-group { display: flex; gap: 8px; margin-bottom: 12px; }
        .toggle-btn { padding: 6px 16px; border: 2px solid var(--line); background: white; cursor: pointer; font-size: 11px; font-weight: 600; transition: all 0.2s; font-family:var(--f-cond); }
        .toggle-btn.active { border-color: var(--brass); background: var(--brass-tint); }
        .toggle-btn.identity-active { border-color: var(--identity-purple); background: var(--identity-bg); }
        .hidden { display: none !important; }
        .identity-fields { background: var(--identity-bg); padding: 12px; margin-top: 8px; border: 1px dashed var(--identity-purple); }
        @media (max-width: 768px) {
            .grid-3, .grid-4 { grid-template-columns: 1fr; }
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
                Batch: <?php echo safeHtmlAD($batch['batch_reference']); ?>
            </span>
            <a href="../logout.php" style="color: rgba(255,255,255,0.4); text-decoration: none; margin-left: 16px; font-size: 12px;">Logout</a>
        </div>
    </div>

    <div class="stage">
        <a href="source_input.php?batch_id=<?php echo (int)$batchId; ?>" class="back-link">← Back to Source Selection</a>

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
                        <?php echo safeHtmlAD($batch['status'] ?? 'DRAFT'); ?>
                    </span>
                </span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:12px; font-size:13px;">
                <div><strong>Source:</strong> <?php echo safeHtmlAD($batch['source_institution']); ?></div>
                <div><strong>Account:</strong> <?php echo safeHtmlAD($batch['source_identifier']); ?></div>
                <div><strong>Total Amount:</strong> <?php echo number_format($batch['total_amount'] ?? 0, 2); ?> <?php echo safeHtmlAD($batch['currency'] ?? 'BWP'); ?></div>
                <div><strong>Destinations:</strong> <?php echo $batch['total_destinations'] ?? 0; ?></div>
                <div><strong>Identity Recipients:</strong> <?php echo $batch['identity_recipients'] ?? 0; ?></div>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="error">⚠️ <?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="success">✅ <?php echo safeHtmlAD($success); ?></div>
        <?php endif; ?>

        <?php if (!$canEdit): ?>
        <div class="locked-notice">🔒 You don't have permission to add or clear destinations on this batch.</div>
        <?php elseif (!$isDraft): ?>
        <div class="locked-notice">🔒 This batch is <?php echo safeHtmlAD($batch['status']); ?>, not draft — destinations are locked. <a href="review_batch.php?batch_id=<?php echo (int)$batchId; ?>" style="color:#92400e; font-weight:700;">Go to Review →</a></div>
        <?php endif; ?>

        <?php if ($canEdit && $isDraft): ?>
        <form method="POST" id="destinationForm">
            <input type="hidden" name="csrf_token" value="<?php echo safeHtmlAD($csrfToken); ?>">
            <input type="hidden" name="action" value="add_destination">
            <input type="hidden" name="destination_data" id="destinationData" value="[]">

            <div class="card">
                <div class="card-header">
                    <span class="card-title">👥 Add Destinations</span>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addRow('institution')">➕ Add Institution</button>
                        <button type="button" class="btn btn-identity btn-sm" onclick="addRow('identity')">🆔 Add Identity Recipient</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="addMultipleRows(5)">➕ Add 5</button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="clearRows()">🗑 Clear Form</button>
                    </div>
                </div>

                <div id="destinationsContainer"></div>

                <div class="summary-stats">
                    <div class="stat"><div class="stat-value" id="destCount">0</div><div class="stat-label">Destinations</div></div>
                    <div class="stat"><div class="stat-value identity-count" id="identityCount">0</div><div class="stat-label">Identity Recipients</div></div>
                    <div class="stat"><div class="stat-value" id="destTotal">BWP 0.00</div><div class="stat-label">Total Amount (this form)</div></div>
                    <div class="stat"><div class="stat-value" id="destValid">0</div><div class="stat-label">Valid Entries</div></div>
                </div>

                <div class="actions-bar">
                    <button type="button" class="btn btn-secondary" onclick="addRow('institution')">➕ Add Another</button>
                    <button type="button" class="btn btn-identity" onclick="addRow('identity')">🆔 Add Identity Recipient</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled>💾 Save Destinations (draft)</button>
                    <a href="review_batch.php?batch_id=<?php echo (int)$batchId; ?>" class="btn btn-outline">📋 Review &amp; Submit</a>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <?php if (!empty($destinations)): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Existing Destinations (<?php echo count($destinations); ?>)</span>
                <?php if ($canEdit && $isDraft): ?>
                <form method="POST" onsubmit="return confirm('Remove ALL destinations from this batch? This cannot be undone.')">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtmlAD($csrfToken); ?>">
                    <input type="hidden" name="action" value="clear_destinations">
                    <button type="submit" class="btn btn-danger btn-sm">🗑 Clear All</button>
                </form>
                <?php endif; ?>
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
                            <th style="padding:10px; text-align:left;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($destinations as $dest): ?>
                        <tr style="border-bottom:1px solid var(--line); <?php echo ($dest['is_identity_recipient'] ?? false) ? 'background:var(--identity-bg);' : ''; ?>">
                            <td style="padding:10px;"><?php echo $dest['destination_index']; ?></td>
                            <td style="padding:10px;">
                                <?php if ($dest['is_identity_recipient'] ?? false): ?>
                                <span style="background:var(--identity-purple); color:white; padding:2px 8px; font-size:10px;">🆔 IDENTITY</span>
                                <?php else: ?>
                                <span style="background:var(--ledger-green); color:white; padding:2px 8px; font-size:10px;">🏛️ INSTITUTION</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;">
                                <?php if ($dest['is_identity_recipient'] ?? false): ?>
                                <?php echo safeHtmlAD($identityTypes[$dest['identity_type']] ?? $dest['identity_type'] ?? 'Identity'); ?>
                                <?php else: ?>
                                <?php echo safeHtmlAD($dest['institution']); ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;">
                                <?php echo safeHtmlAD(($dest['is_identity_recipient'] ?? false) ? ($dest['identity_value'] ?? $dest['identifier']) : $dest['identifier']); ?>
                            </td>
                            <td style="padding:10px;"><?php echo number_format($dest['amount'], 2); ?></td>
                            <td style="padding:10px;"><?php echo safeHtmlAD($dest['beneficiary_name'] ?? '-'); ?></td>
                            <td style="padding:10px;">
                                <span class="workflow-status status-<?php echo strtolower($dest['status']); ?>">
                                    <?php echo safeHtmlAD($dest['status']); ?>
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

    <script>
        let rowCount = 0;

        function getRowTemplate() {
            return `
            <div class="destination-row" data-row-id="${rowCount}" data-type="institution">
                <button type="button" class="remove-btn" onclick="removeRow(this)">✕</button>
                <div class="identity-badge hidden">🆔 IDENTITY</div>
                <div class="toggle-group">
                    <button type="button" class="toggle-btn active" data-type="institution" onclick="toggleRecipientType(this)">🏛️ Institution Account</button>
                    <button type="button" class="toggle-btn" data-type="identity" onclick="toggleRecipientType(this)">🆔 Identity-Based</button>
                </div>
                <div class="institution-fields">
                    <div class="grid-4">
                        <div class="form-group">
                            <label>Institution *</label>
                            <select class="dest-institution">
                                <option value="">Select</option>
                                <?php foreach ($participants as $p): ?>
                                <option value="<?php echo safeHtmlAD($p); ?>"><?php echo safeHtmlAD($p); ?></option>
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
            </div>`;
        }

        function addRow(type) {
            const container = document.getElementById('destinationsContainer');
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = getRowTemplate();
            const row = tempDiv.firstElementChild;
            row.dataset.type = type || 'institution';

            row.querySelectorAll('input, select').forEach(el => {
                el.addEventListener('input', () => { updateSummary(); enableSubmit(); });
                el.addEventListener('change', () => { updateSummary(); enableSubmit(); });
                if (el.tagName === 'INPUT') el.addEventListener('keyup', () => { updateSummary(); enableSubmit(); });
            });

            if (type === 'identity') {
                row.classList.add('identity-row');
                row.querySelector('.identity-badge')?.classList.remove('hidden');
                row.querySelector('.institution-fields')?.classList.add('hidden');
                row.querySelector('.identity-fields')?.classList.remove('hidden');
                row.querySelectorAll('.toggle-btn').forEach(btn => {
                    btn.classList.remove('active', 'identity-active');
                    if (btn.dataset.type === 'identity') btn.classList.add('identity-active');
                });
            }

            container.appendChild(row);
            rowCount++;
            updateSummary();
            enableSubmit();
        }

        function toggleRecipientType(btn) {
            const row = btn.closest('.destination-row');
            const type = btn.dataset.type;
            row.querySelectorAll('.toggle-btn').forEach(t => t.classList.remove('active', 'identity-active'));

            if (type === 'identity') {
                btn.classList.add('identity-active');
                row.classList.add('identity-row');
                row.querySelector('.identity-badge')?.classList.remove('hidden');
                row.querySelector('.institution-fields')?.classList.add('hidden');
                row.querySelector('.identity-fields')?.classList.remove('hidden');
                row.dataset.type = 'identity';
            } else {
                btn.classList.add('active');
                row.classList.remove('identity-row');
                row.querySelector('.identity-badge')?.classList.add('hidden');
                row.querySelector('.institution-fields')?.classList.remove('hidden');
                row.querySelector('.identity-fields')?.classList.add('hidden');
                row.dataset.type = 'institution';
            }
            updateSummary();
            enableSubmit();
        }

        function addMultipleRows(count) { for (let i = 0; i < count; i++) addRow('institution'); }

        function removeRow(btn) {
            const row = btn.closest('.destination-row');
            if (document.querySelectorAll('.destination-row').length > 1) {
                row.remove();
                updateSummary();
                enableSubmit();
            } else {
                alert('You need at least one destination.');
            }
        }

        function clearRows() {
            const rows = document.querySelectorAll('.destination-row');
            if (rows.length <= 1) { alert('You need at least one destination.'); return; }
            if (confirm('Clear all rows from this form? (This only clears what you\'ve typed — it does not touch anything already saved.)')) {
                rows.forEach((row, index) => { if (index > 0) row.remove(); });
                const firstRow = document.querySelector('.destination-row');
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

        function rowAmount(row) {
            let amount = 0;
            row.querySelectorAll('.dest-amount').forEach(inp => { amount += parseFloat(inp.value) || 0; });
            return amount;
        }

        function updateSummary() {
            const rows = document.querySelectorAll('.destination-row');
            let total = 0, valid = 0, identityCount = 0;

            rows.forEach(row => {
                const isIdentity = row.dataset.type === 'identity';
                const amount = rowAmount(row);
                if (isIdentity) {
                    identityCount++;
                    const t = row.querySelector('.dest-identity-type')?.value;
                    const v = row.querySelector('.dest-identity-value')?.value?.trim();
                    if (t && v && amount > 0) { total += amount; valid++; }
                } else {
                    const inst = row.querySelector('.dest-institution')?.value;
                    const ident = row.querySelector('.dest-identifier')?.value?.trim();
                    if (inst && ident && amount > 0) { total += amount; valid++; }
                }
            });

            document.getElementById('destCount').textContent = rows.length;
            document.getElementById('identityCount').textContent = identityCount;
            document.getElementById('destTotal').textContent = 'BWP ' + total.toFixed(2);
            document.getElementById('destValid').textContent = valid;
        }

        function enableSubmit() {
            let hasValid = false;
            document.querySelectorAll('.destination-row').forEach(row => {
                const isIdentity = row.dataset.type === 'identity';
                const amount = rowAmount(row);
                if (isIdentity) {
                    const t = row.querySelector('.dest-identity-type')?.value;
                    const v = row.querySelector('.dest-identity-value')?.value?.trim();
                    if (t && v && amount > 0) hasValid = true;
                } else {
                    const inst = row.querySelector('.dest-institution')?.value;
                    const ident = row.querySelector('.dest-identifier')?.value?.trim();
                    if (inst && ident && amount > 0) hasValid = true;
                }
            });
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) submitBtn.disabled = !hasValid;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('destinationsContainer');
            if (container) {
                addRow('institution');
                addRow('identity');
            }
        });

        const destForm = document.getElementById('destinationForm');
        if (destForm) {
            destForm.addEventListener('submit', function(e) {
                const entries = [];
                document.querySelectorAll('.destination-row').forEach(row => {
                    const isIdentity = row.dataset.type === 'identity';
                    const amount = rowAmount(row);
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
                            entries.push({ recipient_type: 'identity', identity_type: identityType, identity_value: identityValue, amount, beneficiary_name: name, beneficiary_phone: phone, beneficiary_email: email, delivery_method: deliveryIdentity, currency });
                        }
                    } else {
                        const institution = row.querySelector('.dest-institution')?.value || '';
                        const assetType = row.querySelector('.dest-asset-type')?.value || 'WALLET';
                        const identifier = row.querySelector('.dest-identifier')?.value?.trim() || '';
                        if (institution && identifier && amount > 0) {
                            entries.push({ recipient_type: 'institution', institution, asset_type: assetType, identifier, amount, beneficiary_name: name, beneficiary_phone: phone, beneficiary_email: email, delivery_method: delivery, currency });
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
        }
    </script>
</body>
</html>
