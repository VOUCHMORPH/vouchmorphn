<?php
// manual.php - ENTERPRISE DISBURSEMENT MANUAL ENTRY
// No file upload required - direct manual entry
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;

$error = '';
$success = '';
$entries = [];

// Get departments for dropdown
$departments = [];
$stmt = $db->prepare("SELECT id, name FROM departments WHERE organization_id = :org_id ORDER BY name");
$stmt->execute([':org_id' => $orgId]);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get programs
$programs = [];
$stmt = $db->prepare("SELECT id, name FROM programs WHERE organization_id = :org_id ORDER BY name");
$stmt->execute([':org_id' => $orgId]);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if user exists in organization_users before audit log
$userExistsInOrg = false;
if ($userId) {
    $userCheck = $db->prepare("
        SELECT id FROM organization_users 
        WHERE user_id = :user_id AND organization_id = :org_id AND is_active = true
    ");
    $userCheck->execute([
        ':user_id' => $userId,
        ':org_id' => $orgId
    ]);
    $orgUser = $userCheck->fetch(PDO::FETCH_ASSOC);
    $userExistsInOrg = !empty($orgUser);
}

$departmentId = getUserDepartmentScope();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'add_entries') {
        $entries = json_decode($_POST['entries'] ?? '[]', true);
        
        if (empty($entries)) {
            $error = 'No entries to process. Please add at least one recipient.';
        } else {
            try {
                $db->beginTransaction();

                // Create batch
                $batchRef = 'MANUAL_' . date('Ymd_His') . '_' . strtoupper(substr(uniqid(), -6));
                $batchName = $_POST['batch_name'] ?? 'Manual Entry ' . date('Y-m-d H:i');
                $paymentMode = $_POST['payment_mode'] ?? 'BULK';
                $programId = $_POST['program_id'] ?? null;
                $scheduledDate = !empty($_POST['scheduled_date']) ? $_POST['scheduled_date'] : null;
                $requiresApproval = isset($_POST['requires_approval']) ? 1 : 0;
                $requiresDualApproval = isset($_POST['requires_dual_approval']) ? 1 : 0;
                $departmentId = $_POST['department_id'] ?? $departmentId;

                // Insert batch
                $stmt = $db->prepare("
                    INSERT INTO import_batches (
                        organization_id, batch_reference, batch_name, original_filename,
                        source_format, payment_mode, scheduled_date, requires_approval,
                        requires_dual_approval, status, execution_mode, uploaded_by,
                        department_id, program_id, total_rows, total_amount, currency, created_at, updated_at
                    ) VALUES (
                        :org_id, :ref, :name, 'MANUAL_ENTRY',
                        'MANUAL', :payment_mode, :scheduled_date, :requires_approval,
                        :requires_dual_approval, 'UPLOADED', 'MANUAL', :user_id,
                        :dept_id, :program_id, 0, 0, 'BWP', NOW(), NOW()
                    ) RETURNING id
                ");
                $stmt->execute([
                    ':org_id' => $orgId,
                    ':ref' => $batchRef,
                    ':name' => $batchName,
                    ':payment_mode' => $paymentMode,
                    ':scheduled_date' => $scheduledDate,
                    ':requires_approval' => $requiresApproval,
                    ':requires_dual_approval' => $requiresDualApproval,
                    ':user_id' => $userId,
                    ':dept_id' => $departmentId,
                    ':program_id' => $programId,
                ]);
                $batchId = $db->lastInsertId();

                // Process each entry
                $validCount = 0;
                $invalidCount = 0;
                $totalAmount = 0;
                $rowNumber = 0;

                foreach ($entries as $entry) {
                    $rowNumber++;
                    $amount = floatval($entry['amount'] ?? 0);
                    $phone = trim($entry['phone'] ?? '');
                    $accountNumber = trim($entry['account_number'] ?? '');
                    $fullName = trim($entry['full_name'] ?? '');
                    $nationalId = trim($entry['national_id'] ?? '');
                    $email = trim($entry['email'] ?? '');
                    $currency = trim($entry['currency'] ?? 'BWP');
                    $destinationProvider = trim($entry['destination_provider'] ?? '');
                    $paymentReference = trim($entry['payment_reference'] ?? '');
                    $walletId = trim($entry['wallet_id'] ?? '');

                    $errors = [];
                    $warnings = [];

                    // Validate
                    if ($amount <= 0) {
                        $errors[] = 'Invalid amount';
                    } else {
                        $totalAmount += $amount;
                    }

                    if (empty($phone) && empty($accountNumber) && empty($walletId)) {
                        $errors[] = 'Phone, Account Number, or Wallet ID required';
                    }

                    if (empty($fullName)) {
                        $warnings[] = 'Recipient name missing';
                    }

                    // Normalize phone
                    if (!empty($phone)) {
                        $phone = preg_replace('/[^0-9+]/', '', $phone);
                        if (!str_starts_with($phone, '+')) {
                            $phone = '+267' . ltrim($phone, '0');
                        }
                    }

                    // Determine destination type
                    $destinationType = 'PHONE';
                    $destinationValue = $phone;
                    if (!empty($accountNumber)) {
                        $destinationType = 'ACCOUNT';
                        $destinationValue = $accountNumber;
                    } elseif (!empty($walletId)) {
                        $destinationType = 'WALLET';
                        $destinationValue = $walletId;
                    }

                    // Insert row
                    $stmt = $db->prepare("
                        INSERT INTO import_rows (
                            batch_id, row_number, recipient_name, recipient_phone,
                            recipient_national_id, amount, currency, destination_type,
                            destination_provider, destination_value, validation_status,
                            validation_errors, validation_warnings, raw_data, created_at
                        ) VALUES (
                            :batch_id, :row_number, :recipient_name, :recipient_phone,
                            :recipient_national_id, :amount, :currency, :destination_type,
                            :destination_provider, :destination_value, :validation_status,
                            :validation_errors, :validation_warnings, :raw_data, NOW()
                        )
                    ");
                    $stmt->execute([
                        ':batch_id' => $batchId,
                        ':row_number' => $rowNumber,
                        ':recipient_name' => $fullName,
                        ':recipient_phone' => $phone,
                        ':recipient_national_id' => $nationalId,
                        ':amount' => $amount,
                        ':currency' => $currency,
                        ':destination_type' => $destinationType,
                        ':destination_provider' => $destinationProvider,
                        ':destination_value' => $destinationValue,
                        ':validation_status' => empty($errors) ? 'VALID' : 'INVALID',
                        ':validation_errors' => json_encode($errors),
                        ':validation_warnings' => json_encode($warnings),
                        ':raw_data' => json_encode($entry),
                    ]);

                    if (empty($errors)) {
                        $validCount++;
                    } else {
                        $invalidCount++;
                    }
                }

                // Update batch with counts
                $stmt = $db->prepare("
                    UPDATE import_batches 
                    SET total_rows = :total, valid_rows = :valid, invalid_rows = :invalid,
                        total_amount = :amount, status = :status
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':total' => $rowNumber,
                    ':valid' => $validCount,
                    ':invalid' => $invalidCount,
                    ':amount' => $totalAmount,
                    ':status' => $invalidCount > 0 ? 'VALIDATED_WITH_ERRORS' : 'READY_FOR_APPROVAL',
                    ':id' => $batchId
                ]);

                // Only write audit log if user exists in organization_users
                if ($userExistsInOrg) {
                    try {
                        $auditStmt = $db->prepare("
                            INSERT INTO organization_audit_logs (
                                organization_id, user_id, action, entity_type, entity_id,
                                old_values, new_values, ip_address, user_agent, created_at
                            ) VALUES (
                                :org_id, :user_id, 'MANUAL_BATCH_CREATED', 'import_batch', :entity_id,
                                NULL, :new_values, :ip, :ua, NOW()
                            )
                        ");
                        $auditStmt->execute([
                            ':org_id' => $orgId,
                            ':user_id' => $userId,
                            ':entity_id' => $batchId,
                            ':new_values' => json_encode([
                                'batch_reference' => $batchRef,
                                'batch_name' => $batchName,
                                'total_entries' => $rowNumber,
                                'valid_entries' => $validCount,
                                'total_amount' => $totalAmount,
                                'payment_mode' => $paymentMode,
                            ]),
                            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ]);
                    } catch (PDOException $e) {
                        error_log("[manual.php] Failed to write audit log: " . $e->getMessage());
                    }
                }

                $db->commit();

                if ($validCount > 0) {
                    $success = "$validCount entries added successfully!" . ($invalidCount > 0 ? " $invalidCount had errors." : "");
                    header("Location: review.php?batch_id=$batchId");
                    exit;
                } else {
                    $error = "No valid entries. Please check your data.";
                    $db->rollBack();
                }

            } catch (Exception $e) {
                $db->rollBack();
                error_log("[manual.php] Error: " . $e->getMessage());
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

$csrfToken = generateCsrfToken();

// Get stats
$stats = ['total_batches' => 0, 'pending' => 0, 'beneficiaries' => 0];
try {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM import_batches WHERE organization_id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    $stats['total_batches'] = $stmt->fetchColumn() ?: 0;

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM import_batches WHERE organization_id = :org_id AND status = 'READY_FOR_APPROVAL'");
    $stmt->execute([':org_id' => $orgId]);
    $stats['pending'] = $stmt->fetchColumn() ?: 0;

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM organization_beneficiaries WHERE organization_id = :org_id AND is_active = true");
    $stmt->execute([':org_id' => $orgId]);
    $stats['beneficiaries'] = $stmt->fetchColumn() ?: 0;
} catch (PDOException $e) {
    // Silent fail
}

$config = [
    'show_actions' => in_array($user['role'] ?? '', ['owner', 'program_officer', 'department_head']),
    'show_beneficiaries' => in_array($user['role'] ?? '', ['owner', 'auditor', 'program_officer', 'beneficiary_registrar', 'department_head', 'viewer']),
    'show_all_batches' => !in_array($user['role'] ?? '', ['beneficiary_registrar']),
    'show_governance' => in_array($user['role'] ?? '', ['owner', 'auditor', 'department_head']),
    'show_settings' => in_array($user['role'] ?? '', ['owner']),
];

$departmentName = '';
if ($departmentId) {
    $stmt = $db->prepare("SELECT name FROM departments WHERE id = :id AND organization_id = :org_id");
    $stmt->execute([':id' => $departmentId, ':org_id' => $orgId]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    $departmentName = $dept['name'] ?? '';
}

$roleDisplay = strtoupper($user['role'] ?? 'USER');
$orgName = htmlspecialchars($user['organization_name'] ?? 'ORGANIZATIONAL');
$fileRef = 'VM/' . date('Y') . '/' . date('md') . '-' . str_pad((string)($stats['pending'] + 1), 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · MANUAL DISBURSEMENT</title>
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

            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }

        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            font-size: 13px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--line-strong); }
        a { color: inherit; }
        button { font-family: inherit; cursor: pointer; }

        .doc-panel { position: relative; background: var(--panel); border: 1px solid var(--line); }
        .doc-panel::before, .doc-panel::after { content: ""; position: absolute; width: 9px; height: 9px; pointer-events: none; }
        .doc-panel::before { top: -1px; left: -1px; border-top: 2px solid var(--brass); border-left: 2px solid var(--brass); }
        .doc-panel::after  { bottom: -1px; right: -1px; border-bottom: 2px solid var(--brass); border-right: 2px solid var(--brass); }

        .eyebrow { font-family: var(--f-cond); font-weight: 700; font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--ink-500); }
        .section-mark { color: var(--brass); font-weight: 700; margin-right: 5px; }

        .masthead {
            background: var(--ink-900);
            color: white;
            padding: 14px 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            border-bottom: 3px solid var(--brass);
            min-height: 80px;
        }
        .masthead .center {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            flex: 1;
        }
        .masthead h1 {
            font-size: 19px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            text-align: center;
        }
        .masthead .file-ref {
            font-family: var(--f-mono);
            font-size: 11px;
            color: rgba(255,255,255,0.35);
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            text-align: center;
        }
        .masthead .user-menu {
            position: absolute;
            right: 32px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .masthead .user-menu .role-pill {
            font-family: var(--f-cond);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: var(--brass);
            border: 1px solid var(--brass);
            padding: 2px 10px;
            text-transform: uppercase;
        }
        .masthead .user-menu .status-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #5FAE7E;
            margin-right: 4px;
        }
        .masthead .user-menu .time {
            font-family: var(--f-mono);
            font-size: 10px;
            color: rgba(255,255,255,0.4);
        }
        .masthead .user-menu .menu-divider {
            width: 1px;
            height: 20px;
            background: rgba(255,255,255,0.08);
        }
        .masthead .user-menu .menu-link {
            color: rgba(255,255,255,0.4);
            text-decoration: none;
            font-family: var(--f-cond);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: var(--transition);
            padding: 4px 8px;
            border: 1px solid transparent;
        }
        .masthead .user-menu .menu-link:hover {
            color: var(--brass);
            border-color: var(--brass);
        }
        .masthead .user-menu .menu-link.logout-link {
            color: rgba(255,255,255,0.25);
        }
        .masthead .user-menu .menu-link.logout-link:hover {
            color: var(--seal-red);
            border-color: var(--seal-red);
        }
        .masthead .user-menu .menu-link .icon {
            margin-right: 4px;
        }

        .vouchmorph-watermark {
            position: fixed;
            left: 8px;
            top: 50%;
            transform: translateY(-50%) rotate(-90deg);
            font-family: var(--f-mono);
            font-size: 11px;
            letter-spacing: 0.25em;
            color: rgba(138, 109, 59, 0.12);
            font-weight: 700;
            text-transform: uppercase;
            user-select: none;
            pointer-events: none;
            white-space: nowrap;
            z-index: 0;
        }
        .vouchmorph-watermark .tm {
            font-size: 8px;
            vertical-align: super;
            letter-spacing: 0;
        }

        .stage {
            flex: 1; width: 100%; display: flex; flex-direction: column; align-items: center;
            padding: 120px 20px 60px;
            position: relative;
            z-index: 1;
        }
        .stage-inner { width: 100%; max-width: 980px; display: flex; flex-direction: column; align-items: center; gap: 28px; }

        .welcome { text-align: center; margin-bottom: 8px; }
        .welcome .eyebrow { justify-content: center; }
        .welcome h2 { font-size: 20px; font-weight: 700; letter-spacing: 0.01em; text-transform: uppercase; margin-top: 6px; }
        .welcome p { font-family: var(--f-cond); font-size: 11px; color: var(--ink-500); margin-top: 4px; letter-spacing: 0.02em; text-transform: uppercase; }

        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            max-width: 700px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step::after {
            content: '';
            position: absolute;
            top: 14px;
            left: 55%;
            width: 90%;
            height: 2px;
            background: var(--line);
            z-index: 0;
        }
        .step:last-child::after { display: none; }
        .step .step-number {
            width: 28px;
            height: 28px;
            background: var(--line);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 11px;
            font-family: var(--f-mono);
            color: var(--ink-300);
            position: relative;
            z-index: 1;
            border: 2px solid var(--line);
        }
        .step.active .step-number {
            background: var(--brass);
            border-color: var(--brass);
            color: white;
        }
        .step.done .step-number {
            background: var(--ledger-green);
            border-color: var(--ledger-green);
            color: white;
        }
        .step .step-label {
            font-size: 9px;
            color: var(--ink-300);
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            font-family: var(--f-cond);
        }
        .step.active .step-label { color: var(--ink-900); }
        .step.done .step-label { color: var(--ledger-green); }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--ink-500);
            font-family: var(--f-cond);
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1.5px solid var(--line);
            font-size: 13px;
            font-family: var(--f-body);
            background: #fdfcf9;
            transition: border-color .15s, background .15s;
            color: var(--ink-900);
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--brass);
            background: #fff;
        }

        .entry-row {
            background: #f8fafc;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            position: relative;
        }
        .entry-row .remove-btn {
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
            font-weight: 700;
        }
        .entry-row .remove-btn:hover { background: #fecaca; }

        .grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

        .btn {
            padding: 10px 20px;
            font-weight: 700;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-family: var(--f-cond);
            border: 1.5px solid transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-primary {
            background: var(--ink-900);
            color: white;
            border-color: var(--ink-900);
        }
        .btn-primary:hover {
            background: var(--brass);
            border-color: var(--brass);
            color: var(--ink-900);
        }
        .btn-secondary {
            background: var(--line);
            color: var(--ink-700);
            border-color: var(--line);
        }
        .btn-secondary:hover {
            background: var(--line-strong);
            border-color: var(--line-strong);
        }
        .btn-success {
            background: var(--ledger-green);
            color: white;
            border-color: var(--ledger-green);
        }
        .btn-success:hover {
            background: #1a3d2c;
            border-color: #1a3d2c;
        }
        .btn-danger {
            background: var(--seal-red);
            color: white;
            border-color: var(--seal-red);
        }
        .btn-danger:hover {
            background: #5a1812;
            border-color: #5a1812;
        }
        .btn-outline {
            background: transparent;
            color: var(--ink-700);
            border-color: var(--line);
        }
        .btn-outline:hover {
            border-color: var(--brass);
            color: var(--brass);
        }
        .btn-sm { padding: 6px 14px; font-size: 9px; }
        .btn-block { width: 100%; justify-content: center; }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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
        .stat-value { font-size: 24px; font-weight: 700; color: var(--ink-900); }
        .stat-label { font-size: 9px; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--f-cond); }

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
        .card-title { font-size: 14px; font-weight: 700; text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.05em; }

        .error {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fbeceb;
            color: var(--seal-red);
            padding: 10px 14px;
            margin-bottom: 18px;
            font-size: 12px;
            border-left: 3px solid var(--seal-red);
            font-weight: 500;
        }
        .success {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #dcfce7;
            color: #166534;
            padding: 10px 14px;
            margin-bottom: 18px;
            font-size: 12px;
            border-left: 3px solid #10b981;
            font-weight: 500;
        }
        .error .icon, .success .icon { font-size: 16px; flex-shrink: 0; }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ink-500);
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            font-family: var(--f-cond);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid transparent;
            transition: all 0.15s ease;
            margin-bottom: 16px;
        }
        .back-link:hover {
            color: var(--brass);
            border-bottom-color: var(--brass);
        }

        .actions-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 12px;
        }

        .page-footer { padding: 16px 0 26px; text-align: center; border-top: 1px solid var(--line); width: 100%; }
        .page-footer .notice { font-family: var(--f-cond); font-size: 9.5px; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; color: var(--ink-300); }
        .page-footer .role-line { font-family: var(--f-mono); font-size: 9px; color: var(--ink-300); margin-top: 4px; text-transform: uppercase; }

        @media (max-width: 768px) {
            .grid-4 { grid-template-columns: 1fr 1fr; }
            .grid-2 { grid-template-columns: 1fr; }
            .summary-stats { grid-template-columns: 1fr; }
            .masthead { padding: 12px 16px; flex-direction: column; }
            .masthead .user-menu {
                position: static;
                transform: none;
                justify-content: center;
                flex-wrap: wrap;
            }
            .vouchmorph-watermark { display: none; }
            .stage { padding: 80px 16px 40px; }
            .card { padding: 16px; }
        }

        @media (max-width: 480px) {
            .grid-4 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="vouchmorph-watermark">VouchMorph<span class="tm">™</span></div>

    <div class="masthead">
        <div class="center">
            <h1><?php echo $orgName; ?> — Manual Disbursement</h1>
            <div class="file-ref">FILE NO. <?php echo htmlspecialchars($fileRef); ?> · <?php echo strtoupper(date('d M Y')); ?></div>
        </div>
        <div class="user-menu">
            <span class="role-pill"><?php echo $roleDisplay; ?></span>
            <span class="time"><span class="status-dot"></span><?php echo date('H:i'); ?> UTC+2</span>
            <span class="menu-divider"></span>
            <?php if ($config['show_settings']): ?>
            <a href="../settings/index.php" class="menu-link">
                <span class="icon">⚙</span> Settings
            </a>
            <?php endif; ?>
            <a href="../logout.php" class="menu-link logout-link">
                <span class="icon">↗</span> Sign Out
            </a>
        </div>
    </div>

    <div class="stage">
        <div class="stage-inner">
            <div class="welcome">
                <div class="eyebrow"><span class="section-mark">§</span>Registry Access</div>
                <h2>📝 MANUAL ENTRY</h2>
                <p>Enter recipient details directly — no file upload required</p>
            </div>

            <a href="../index.php" class="back-link">← Return to Dashboard</a>

            <?php if ($error): ?>
            <div class="error">
                <span class="icon">⚠</span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="success">
                <span class="icon">✓</span>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" id="entryForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_entries">
                <input type="hidden" name="entries" id="entriesInput" value="[]">

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">📋 Batch Details</span>
                        <span style="font-size: 11px; color: var(--ink-300); font-family: var(--f-cond);">Step 1 of 2</span>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Batch Name</label>
                            <input type="text" name="batch_name" placeholder="e.g., Pensioners July 2026" value="Manual Entry <?php echo date('Y-m-d H:i'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode">
                                <option value="BULK">BULK - Standard Bulk</option>
                                <option value="SINGLE">SINGLE - Single Payment</option>
                                <option value="RECURRING">RECURRING - Recurring</option>
                                <option value="EMERGENCY">EMERGENCY - Emergency</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Department</label>
                            <select name="department_id">
                                <option value="">— None —</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo $dept['id'] == $departmentId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Program (optional)</label>
                            <select name="program_id">
                                <option value="">— None —</option>
                                <?php foreach ($programs as $program): ?>
                                <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Scheduled Date</label>
                            <input type="date" name="scheduled_date">
                        </div>
                        <div class="form-group" style="display: flex; gap: 20px; align-items: center; padding-top: 8px;">
                            <label style="display: flex; align-items: center; gap: 6px; font-weight: 400; text-transform: none; font-size: 11px; color: var(--ink-700);">
                                <input type="checkbox" name="requires_approval" value="1" checked> Requires Approval
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; font-weight: 400; text-transform: none; font-size: 11px; color: var(--ink-700);">
                                <input type="checkbox" name="requires_dual_approval" value="1"> Dual Approval
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">👥 Recipients</span>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addRow()">➕ Add Row</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="addMultipleRows(5)">➕ Add 5 Rows</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="clearRows()">🗑 Clear All</button>
                        </div>
                    </div>

                    <div id="entriesContainer">
                        <!-- Rows added by JavaScript -->
                        <div class="entry-row" id="rowTemplate" style="display: none;">
                            <button type="button" class="remove-btn" onclick="removeRow(this)">✕</button>
                            <div class="grid-4">
                                <div class="form-group">
                                    <label>Full Name *</label>
                                    <input type="text" class="field-name" placeholder="Recipient full name">
                                </div>
                                <div class="form-group">
                                    <label>Phone Number</label>
                                    <input type="tel" class="field-phone" placeholder="71234567">
                                </div>
                                <div class="form-group">
                                    <label>Account Number</label>
                                    <input type="text" class="field-account" placeholder="Bank account number">
                                </div>
                                <div class="form-group">
                                    <label>Amount *</label>
                                    <input type="number" class="field-amount" placeholder="0.00" step="0.01" min="0.01">
                                </div>
                            </div>
                            <div class="grid-2" style="margin-top: 8px;">
                                <div class="form-group">
                                    <label>National ID (optional)</label>
                                    <input type="text" class="field-national" placeholder="National ID number">
                                </div>
                                <div class="form-group">
                                    <label>Provider (optional)</label>
                                    <input type="text" class="field-provider" placeholder="e.g., ZURUBANK, CAZACOM">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="summary-stats">
                        <div class="stat">
                            <div class="stat-value" id="rowCount">0</div>
                            <div class="stat-label">Recipients</div>
                        </div>
                        <div class="stat">
                            <div class="stat-value" id="totalAmount">BWP 0.00</div>
                            <div class="stat-label">Total Amount</div>
                        </div>
                        <div class="stat">
                            <div class="stat-value" id="validCount">0</div>
                            <div class="stat-label">Valid Entries</div>
                        </div>
                    </div>

                    <div class="actions-bar">
                        <button type="button" class="btn btn-secondary" onclick="addRow()">➕ Add Another</button>
                        <button type="submit" class="btn btn-success" id="submitBtn" disabled>💾 Save & Process</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <footer class="page-footer">
        <div class="notice">SECURE ENTERPRISE MANUAL ENTRY · DISTRIBUTION RESTRICTED · ISO 27001 · © <?php echo date('Y'); ?> VOUCHMORPH</div>
        <div class="role-line"><?php echo $roleDisplay; ?><?php if ($departmentName): ?> · <?php echo strtoupper($departmentName); ?><?php endif; ?> · <?php echo htmlspecialchars($fileRef); ?></div>
    </footer>

    <script>
        let rowCount = 0;

        function getTemplate() {
            return document.getElementById('rowTemplate').cloneNode(true);
        }

        function addRow() {
            const container = document.getElementById('entriesContainer');
            const template = getTemplate();
            template.style.display = 'block';
            template.id = 'row_' + rowCount;
            template.querySelectorAll('input').forEach(input => {
                input.id = input.className + '_' + rowCount;
                if (input.classList.contains('field-name')) input.required = true;
                if (input.classList.contains('field-amount')) input.required = true;
                input.addEventListener('input', updateSummary);
            });
            container.appendChild(template);
            rowCount++;
            updateSummary();
            enableSubmit();
        }

        function addMultipleRows(count) {
            for (let i = 0; i < count; i++) {
                addRow();
            }
        }

        function removeRow(btn) {
            const row = btn.closest('.entry-row');
            const visibleRows = document.querySelectorAll('.entry-row:not([style*="display: none"])');
            if (visibleRows.length > 1) {
                row.remove();
                updateSummary();
                enableSubmit();
            } else {
                alert('You need at least one recipient.');
            }
        }

        function clearRows() {
            const rows = document.querySelectorAll('.entry-row:not([style*="display: none"])');
            if (rows.length <= 1) {
                alert('You need at least one recipient.');
                return;
            }
            if (confirm('Remove all recipients?')) {
                rows.forEach((row, index) => {
                    if (index > 0) row.remove();
                });
                const firstRow = document.querySelector('.entry-row:not([style*="display: none"])');
                if (firstRow) {
                    firstRow.querySelectorAll('input').forEach(input => input.value = '');
                }
                rowCount = 1;
                updateSummary();
                enableSubmit();
            }
        }

        function updateSummary() {
            const rows = document.querySelectorAll('.entry-row:not([style*="display: none"])');
            let total = 0;
            let valid = 0;

            rows.forEach(row => {
                const name = row.querySelector('.field-name')?.value?.trim();
                const amount = parseFloat(row.querySelector('.field-amount')?.value) || 0;
                if (name && amount > 0) {
                    total += amount;
                    valid++;
                }
            });

            document.getElementById('rowCount').textContent = rows.length;
            document.getElementById('totalAmount').textContent = 'BWP ' + total.toFixed(2);
            document.getElementById('validCount').textContent = valid;
        }

        function enableSubmit() {
            const rows = document.querySelectorAll('.entry-row:not([style*="display: none"])');
            let hasValid = false;

            rows.forEach(row => {
                const name = row.querySelector('.field-name')?.value?.trim();
                const amount = row.querySelector('.field-amount')?.value;
                if (name && amount && parseFloat(amount) > 0) {
                    hasValid = true;
                }
            });

            document.getElementById('submitBtn').disabled = !hasValid;
        }

        // Initialize with 3 rows
        document.addEventListener('DOMContentLoaded', function() {
            addRow();
            addRow();
            addRow();
            updateSummary();
        });

        // Before submit, gather all entries
        document.getElementById('entryForm').addEventListener('submit', function(e) {
            const rows = document.querySelectorAll('.entry-row:not([style*="display: none"])');
            const entries = [];

            rows.forEach(row => {
                const name = row.querySelector('.field-name')?.value?.trim() || '';
                const phone = row.querySelector('.field-phone')?.value?.trim() || '';
                const account = row.querySelector('.field-account')?.value?.trim() || '';
                const amount = parseFloat(row.querySelector('.field-amount')?.value) || 0;
                const national = row.querySelector('.field-national')?.value?.trim() || '';
                const provider = row.querySelector('.field-provider')?.value?.trim() || '';

                if (name && amount > 0) {
                    entries.push({
                        full_name: name,
                        phone: phone,
                        account_number: account,
                        amount: amount,
                        national_id: national,
                        destination_provider: provider,
                        currency: 'BWP'
                    });
                }
            });

            if (entries.length === 0) {
                e.preventDefault();
                alert('Please add at least one valid recipient with name and amount.');
                return;
            }

            document.getElementById('entriesInput').value = JSON.stringify(entries);
        });
    </script>
</body>
</html>
