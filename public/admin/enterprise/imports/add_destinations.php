<?php
// enterprise/imports/add_destinations.php - Add destinations for multi-destination swap
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../src/Domain/Services/DepartmentService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/UserManagementService.php';
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
// AUTO-REPAIR: If batch has destinations but totals are 0, fix them
// ============================================================
function repairBatchTotals(PDO $db, $batchId): void {
    // Check current totals
    $stmt = $db->prepare("
        SELECT total_destinations, total_amount
        FROM disbursement_batches
        WHERE id = :id
    ");
    $stmt->execute([':id' => $batchId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);

    // Count actual destinations
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
        FROM disbursement_destinations
        WHERE batch_id = :id
    ");
    $stmt->execute([':id' => $batchId]);
    $actual = $stmt->fetch(PDO::FETCH_ASSOC);

    // If mismatch, fix it
    if ((int)$batch['total_destinations'] !== (int)$actual['cnt'] ||
        (float)$batch['total_amount'] !== (float)$actual['total']) {
        error_log("[add_destinations] Repairing batch {$batchId}: total_destinations {$batch['total_destinations']}->{$actual['cnt']}, total_amount {$batch['total_amount']}->{$actual['total']}");

        $stmt = $db->prepare("
            UPDATE disbursement_batches
            SET total_destinations = :cnt, total_amount = :amt,
                pending_count = :cnt, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':cnt' => (int)$actual['cnt'],
            ':amt' => (float)$actual['total'],
            ':id' => $batchId
        ]);
    }
}

// ============================================================
// AUTO-REPAIR: Run immediately after loading the batch
// ============================================================
repairBatchTotals($db, $batchId);

// Refresh batch data after repair
$stmt = $db->prepare("
    SELECT * FROM disbursement_batches
    WHERE id = :id AND organization_id = :org_id
");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

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

function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function getRoleLabel($role) {
    $labels = [
        'owner' => 'Owner', 'it_manager_enterprise' => 'IT Manager', 'it_officer_enterprise' => 'IT Officer',
        'it_support' => 'IT Support', 'department_head' => 'Department Head', 'program_officer' => 'Uploader',
        'finance_officer' => 'Finance Officer', 'approver' => 'Approver', 'senior_approver' => 'Senior Approver',
        'supervisor' => 'Supervisor', 'beneficiary_registrar' => 'Beneficiary Registrar', 'auditor' => 'Auditor', 'viewer' => 'Viewer',
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}
function getStatusClass($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => 'draft',
        'pending', 'pending_approval' => 'pending',
        'approved' => 'approved',
        'executing' => 'pending',
        'completed', 'executed' => 'completed',
        'rejected' => 'rejected',
        'cancelled' => 'rejected',
        default => 'draft'
    };
}
function getStatusLabel($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => '📝 Draft',
        'pending', 'pending_approval' => '⏳ Pending',
        'approved' => '✅ Approved',
        'executing' => '⚙️ Executing',
        'completed' => '✔️ Completed',
        'executed' => '🚀 Executed',
        'rejected' => '❌ Rejected',
        'cancelled' => '🚫 Cancelled',
        default => ucfirst($status)
    };
}
// Destination-level statuses (SUCCESS/FAILED/PENDING_IDENTITY_CONFIRMATION/
// PROCESSING) don't match the batch-level vocabulary getStatusClass()
// above covers, so they get their own small mapping onto the same 5
// shell.css tones rather than a second parallel set of status colors.
function destStatusClass($status) {
    $status = strtoupper((string)$status);
    return match($status) {
        'SUCCESS', 'COMPLETED' => 'completed',
        'FAILED' => 'rejected',
        'PENDING_IDENTITY_CONFIRMATION' => 'approved',
        'PENDING', 'PROCESSING' => 'pending',
        default => 'draft',
    };
}

// ============================================================
// SHARED SHELL SETUP — same contract as index.php/departments/index.php,
// so this page's nav is generated by the exact same code, not a
// hand-copied lookalike.
// ============================================================
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization';
$userRole = $role;
$basePath = '../';
$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$isDepartmentHead = ($userRole === 'department_head');
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head'], true);
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise'], true);
$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true);
$canSeeSourceAccountsArea = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer'], true);
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer'], true);
$canManageDepartments = $isTopRole;

// Unlike source_input.php, this page cannot render at all without an
// existing batch (see the die() above) — so by construction, setup was
// already completed at least once. No live check needed here.
$setupReady = true;

$navPendingApprovals = 0;
$navPendingSourceConfirmations = 0;
try {
    if ($canApprove) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('pending','pending_approval','PENDING','PENDING_APPROVAL')");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingApprovals = (int)$stmt->fetchColumn();
    }
    if ($canSeeSourceAccountsArea) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM source_accounts WHERE organization_id = :org_id AND status = 'pending_confirmation' AND deleted_at IS NULL");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingSourceConfirmations = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("[add_destinations] Nav badge query error: " . $e->getMessage());
}

$navItems = [
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => '../index.php', 'show' => true],
    ['key' => 'disbursements', 'icon' => 'wallet', 'label' => 'Disbursements', 'href' => '../batches/index.php?status=all', 'show' => true, 'badge' => ($navPendingApprovals > 0 && $canApprove) ? $navPendingApprovals : null],
    ['key' => 'beneficiaries', 'icon' => 'people', 'label' => 'Beneficiaries', 'href' => '../beneficiaries.php', 'show' => true],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace Payment', 'href' => '../index.php#trace', 'show' => $canTrace],
    ['key' => 'departments', 'icon' => 'building', 'label' => 'Departments', 'href' => '../departments/index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => '../imports/add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => $navPendingSourceConfirmations > 0 ? $navPendingSourceConfirmations : null],
    ['key' => 'team', 'icon' => 'idcard', 'label' => 'Team', 'href' => '../settings/users.php', 'show' => $canManageUsers],
    ['key' => 'reports', 'icon' => 'chart', 'label' => 'Reports', 'href' => '../reports.php', 'show' => true],
];
$navUtility = [
    ['key' => 'settings', 'icon' => 'gear', 'label' => 'Settings', 'href' => '../settings.php', 'show' => true],
    ['key' => 'logout', 'icon' => 'logout', 'label' => 'Log Out', 'href' => '../logout.php', 'show' => true],
];
$topbarSearchShow = $canTrace;
$topbarSearchAction = '../index.php';
$topbarSearchName = 'trace';
$topbarSearchPlaceholder = 'Search batch reference, phone, national ID…';

$stageTrackerStage = 2;
$roleDisplay = strtoupper(UserManagementService::ROLE_CATALOG[$role]['label'] ?? $role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Batch · Recipients · VOUCHMORPH Enterprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../partials/shell.css">
    <link rel="stylesheet" href="../partials/stage-tracker.css">
    <style>
        :root { --identity-purple: #6f42c1; --identity-bg: #f8f0fc; }
        .btn-identity { background: var(--identity-purple); color: #fff; }
        .btn-identity:hover { background: #5a32a3; opacity: 1; }
        .form-group { margin-bottom: 12px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
        .destination-row { background: var(--paper); border: 1px solid var(--line); padding: 16px; margin-bottom: 12px; position: relative; }
        .destination-row.identity-row { background: var(--identity-bg); border-color: var(--identity-purple); border-left: 4px solid var(--identity-purple); }
        .destination-row .remove-btn { position: absolute; top: 8px; right: 8px; background: #fee2e2; color: #991b1b; border: none; border-radius: 50%; width: 28px; height: 28px; cursor: pointer; font-size: 16px; line-height: 1; }
        .destination-row .identity-badge { position: absolute; top: 8px; right: 44px; background: var(--identity-purple); color: #fff; padding: 2px 10px; font-size: 9px; font-weight: 600; text-transform: uppercase; }
        .dest-summary-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 16px 0; }
        .dest-summary-stat { background: var(--panel); padding: 14px; border: 1px solid var(--line); text-align: center; }
        .dest-summary-value { font-size: 22px; font-weight: 700; font-family: var(--f-cond); }
        .dest-summary-label { font-size: 10px; color: var(--ink-500); text-transform: uppercase; font-family: var(--f-cond); }
        .dest-summary-value.identity-count { color: var(--identity-purple); }
        .toggle-group { display: flex; gap: 8px; margin-bottom: 12px; }
        .toggle-btn { padding: 6px 16px; border: 2px solid var(--line); background: var(--panel); cursor: pointer; font-size: 11px; font-weight: 600; transition: all 0.2s; font-family: var(--f-cond); }
        .toggle-btn.active { border-color: var(--brass); background: var(--brass-tint); }
        .toggle-btn.identity-active { border-color: var(--identity-purple); background: var(--identity-bg); }
        .hidden { display: none !important; }
        .identity-fields { background: var(--identity-bg); padding: 12px; margin-top: 8px; border: 1px dashed var(--identity-purple); }
        .actions-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        @media (max-width: 768px) {
            .grid-3, .grid-4, .dest-summary-stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../partials/shell-head.php'; ?>
            <div class="page-header">
                <div>
                    <h1>Create Batch</h1>
                    <div class="sub">Step 2 — add recipients: institution accounts or identity-based individuals.</div>
                </div>
            </div>

            <?php require __DIR__ . '/../partials/stage-tracker.php'; ?>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Batch Status</span>
                    <span style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:11px; color:var(--ink-300); font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em;"><?php echo safeHtml($roleDisplay); ?></span>
                        <span class="status status-<?php echo getStatusClass($batch['status'] ?? 'draft'); ?>">
                            <?php echo getStatusLabel($batch['status'] ?? 'draft'); ?>
                        </span>
                    </span>
                </div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:12px; font-size:13px;">
                    <div><strong>Batch:</strong> <?php echo safeHtmlAD($batch['batch_reference']); ?></div>
                    <div><strong>Source:</strong> <?php echo safeHtmlAD($batch['source_institution']); ?></div>
                    <div><strong>Account:</strong> <?php echo safeHtmlAD($batch['source_identifier']); ?></div>
                    <div><strong>Total Amount:</strong> <?php echo number_format($batch['total_amount'] ?? 0, 2); ?> <?php echo safeHtmlAD($batch['currency'] ?? 'BWP'); ?></div>
                    <div><strong>Destinations:</strong> <?php echo $batch['total_destinations'] ?? 0; ?></div>
                    <div><strong>Identity Recipients:</strong> <?php echo $batch['identity_recipients'] ?? 0; ?></div>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="info-panel" style="border-left-color: var(--seal-red); background: var(--danger-bg);">
                <div class="label" style="color:var(--danger);">⚠️ <?php echo $error; ?></div>
            </div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="info-panel" style="border-left-color: var(--ledger-green); background: var(--green-tint);">
                <div class="label" style="color:var(--ledger-green);">✅ <?php echo safeHtmlAD($success); ?></div>
            </div>
            <?php endif; ?>

            <?php if (!$canEdit): ?>
            <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg);">
                <div class="label" style="color:var(--amber);">🔒 You don't have permission to add or clear destinations on this batch.</div>
            </div>
            <?php elseif (!$isDraft): ?>
            <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg);">
                <div class="label" style="color:var(--amber);">🔒 This batch is <?php echo safeHtmlAD($batch['status']); ?>, not draft — destinations are locked.</div>
                <div class="desc"><a href="review_batch.php?batch_id=<?php echo (int)$batchId; ?>" style="color:var(--brass); font-weight:600;">Go to Review →</a></div>
            </div>
            <?php endif; ?>

            <?php if ($canEdit && $isDraft): ?>
            <form method="POST" id="destinationForm">
                <input type="hidden" name="csrf_token" value="<?php echo safeHtmlAD($csrfToken); ?>">
                <input type="hidden" name="action" value="add_destination">
                <input type="hidden" name="destination_data" id="destinationData" value="[]">

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Add Recipients</span>
                        <div class="card-actions">
                            <button type="button" class="btn btn-outline btn-sm" onclick="addRow('institution')"><?php echo svgIcon('building'); ?> Add Institution</button>
                            <button type="button" class="btn btn-identity btn-sm" onclick="addRow('identity')"><?php echo svgIcon('idcard'); ?> Add Identity Recipient</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="addMultipleRows(5)"><?php echo svgIcon('plus'); ?> Add 5</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="clearRows()">🗑 Clear Form</button>
                        </div>
                    </div>

                    <div id="destinationsContainer"></div>

                    <div class="dest-summary-stats">
                        <div class="dest-summary-stat"><div class="dest-summary-value" id="destCount">0</div><div class="dest-summary-label">Destinations</div></div>
                        <div class="dest-summary-stat"><div class="dest-summary-value identity-count" id="identityCount">0</div><div class="dest-summary-label">Identity Recipients</div></div>
                        <div class="dest-summary-stat"><div class="dest-summary-value" id="destTotal">BWP 0.00</div><div class="dest-summary-label">Total Amount (this form)</div></div>
                        <div class="dest-summary-stat"><div class="dest-summary-value" id="destValid">0</div><div class="dest-summary-label">Valid Entries</div></div>
                    </div>

                    <div class="actions-bar">
                        <button type="button" class="btn btn-outline" onclick="addRow('institution')"><?php echo svgIcon('plus'); ?> Add Another</button>
                        <button type="button" class="btn btn-identity" onclick="addRow('identity')"><?php echo svgIcon('idcard'); ?> Add Identity Recipient</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn" disabled>💾 Save Destinations (draft)</button>
                        <a href="review_batch.php?batch_id=<?php echo (int)$batchId; ?>" class="btn btn-outline">📋 Review &amp; Submit</a>
                    </div>
                </div>
            </form>
            <?php endif; ?>

            <?php if (!empty($destinations)): ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Existing Destinations (<?php echo count($destinations); ?>)</span>
                    <?php if ($canEdit && $isDraft): ?>
                    <form method="POST" onsubmit="return confirm('Remove ALL destinations from this batch? This cannot be undone.')">
                        <input type="hidden" name="csrf_token" value="<?php echo safeHtmlAD($csrfToken); ?>">
                        <input type="hidden" name="action" value="clear_destinations">
                        <button type="submit" class="btn btn-danger btn-sm">🗑 Clear All</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Type</th>
                                <th>Institution/Identity</th>
                                <th>Identifier</th>
                                <th>Amount</th>
                                <th>Beneficiary</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($destinations as $dest): ?>
                            <tr <?php echo ($dest['is_identity_recipient'] ?? false) ? 'style="background:var(--identity-bg);"' : ''; ?>>
                                <td><?php echo $dest['destination_index']; ?></td>
                                <td>
                                    <?php if ($dest['is_identity_recipient'] ?? false): ?>
                                    <span style="background:var(--identity-purple); color:#fff; padding:2px 8px; font-size:10px;">🆔 IDENTITY</span>
                                    <?php else: ?>
                                    <span style="background:var(--ledger-green); color:#fff; padding:2px 8px; font-size:10px;">🏛️ INSTITUTION</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($dest['is_identity_recipient'] ?? false): ?>
                                    <?php echo safeHtmlAD($identityTypes[$dest['identity_type']] ?? $dest['identity_type'] ?? 'Identity'); ?>
                                    <?php else: ?>
                                    <?php echo safeHtmlAD($dest['institution']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo safeHtmlAD(($dest['is_identity_recipient'] ?? false) ? ($dest['identity_value'] ?? $dest['identifier']) : $dest['identifier']); ?></td>
                                <td><?php echo number_format($dest['amount'], 2); ?></td>
                                <td><?php echo safeHtmlAD($dest['beneficiary_name'] ?? '-'); ?></td>
                                <td>
                                    <span class="status status-<?php echo destStatusClass($dest['status']); ?>">
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
<?php
$dbHealthy = DBConnection::isConnected();
$footerStatusLine = 'LEDGER SYNC: ' . ($dbHealthy ? '<span class="ok">OK</span>' : '<span class="bad">DEGRADED</span>');
require __DIR__ . '/../partials/shell-foot.php';
?>
</body>
</html>
