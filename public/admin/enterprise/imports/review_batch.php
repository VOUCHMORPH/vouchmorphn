<?php
// enterprise/imports/review_batch.php - Review and approve batch
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// ============================================================
// LOAD SWAPSERVICE AND DEPENDENCIES
// ============================================================
require_once '../../../../vendor/autoload.php';
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
$failedDestinations = [];

// ============================================================
// HELPER: Check if user can edit this batch
// ============================================================
function canEditBatch($batchCreatedBy, $currentUserId, $userRole) {
    if ($userRole === 'owner') return true;
    if (in_array($userRole, ['program_officer', 'department_head'])) {
        return $batchCreatedBy == $currentUserId;
    }
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
// CHECK PERMISSIONS
// ============================================================
$isOwnBatch = ($batch['created_by'] == $userId);
$canEdit = canEditBatch($batch['created_by'], $userId, $user['role'] ?? 'viewer');
$isReadOnly = !$canEdit;

// ============================================================
// GET DESTINATIONS
// ============================================================
$destinations = [];
$stmt = $db->prepare("
    SELECT * FROM disbursement_destinations 
    WHERE batch_id = :batch_id
    ORDER BY destination_index
");
$stmt->execute([':batch_id' => $batchId]);
$destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// DEBUG: Log raw data
// ============================================================
error_log("=== REVIEW_BATCH DEBUG: RAW DATA ===");
error_log("Batch ID: " . $batchId);
error_log("Batch Reference: " . ($batch['batch_reference'] ?? 'NULL'));
error_log("Source Institution: " . ($batch['source_institution'] ?? 'NULL'));
error_log("Destinations Count: " . count($destinations));

foreach ($destinations as $idx => $dest) {
    error_log("Destination $idx: " . json_encode($dest));
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    
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
            error_log("=== REVIEW_BATCH DEBUG: EXECUTION STARTED ===");
            
            try {
                $countryName = $_ENV['VOUCHMORPH_COUNTRY'] ?? getenv('VOUCHMORPH_COUNTRY') ?? 'Botswana';
                $fullCountryConfig = LoadCountry::getConfig();
                
                error_log("[review_batch] Initializing SwapService...");
                error_log("[review_batch] Country: " . $countryName);
                
                $swapService = new SwapService(
                    $db,
                    $fullCountryConfig,
                    $countryName
                );
                
                error_log("[review_batch] SwapService initialized successfully");
                
                $identityDestinations = [];
                $institutionDestinations = [];
                
                foreach ($destinations as $dest) {
                    $isIdentity = ($dest['is_identity_recipient'] ?? false) || ($dest['institution'] ?? '') === 'IDENTITY_RECIPIENT';
                    if ($isIdentity) {
                        $identityDestinations[] = $dest;
                    } else {
                        $institutionDestinations[] = $dest;
                    }
                }
                
                error_log("[review_batch] Identity destinations: " . count($identityDestinations));
                error_log("[review_batch] Institution destinations: " . count($institutionDestinations));
                
                $allResults = [];
                $overallSuccess = 0;
                $overallFailed = 0;
                $overallPending = 0;
                $failedDestinations = [];
                
                // Identity destinations
                foreach ($identityDestinations as $dest) {
                    error_log("[review_batch] Processing identity destination: " . json_encode($dest));
                    
                    $identityPayload = [
                        'swap_type' => 'IDENTITY',
                        'reference' => $batch['batch_reference'] . '_ID_' . $dest['destination_index'],
                        'from_institution' => $batch['source_institution'],
                        'source_institution' => $batch['source_institution'],
                        'asset_type' => $batch['source_asset_type'] ?? 'ACCOUNT',
                        'source_identifier' => $batch['source_identifier'],
                        'amount' => (float)$dest['amount'],
                        'currency' => $dest['currency'] ?? $batch['currency'] ?? 'BWP',
                        'identity_type' => $dest['identity_type'] ?? 'national_id',
                        'identity_value' => $dest['identity_value'] ?? $dest['identifier'],
                    ];
                    
                    if (!empty($dest['beneficiary_phone'])) {
                        $identityPayload['notification_phone'] = $dest['beneficiary_phone'];
                    }
                    
                    error_log("[review_batch] Identity payload: " . json_encode($identityPayload));
                    
                    try {
                        $idResult = $swapService->executeAtomicSwap($identityPayload);
                        $overallPending++;
                        
                        $stmt = $db->prepare("
                            UPDATE disbursement_destinations
                            SET status = 'PENDING_IDENTITY_CONFIRMATION',
                                hold_reference = :hold_ref
                            WHERE batch_id = :batch_id AND destination_index = :idx
                        ");
                        $stmt->execute([
                            ':hold_ref' => $idResult['hold_reference'] ?? null,
                            ':batch_id' => $batchId,
                            ':idx' => $dest['destination_index'],
                        ]);
                        
                        $allResults[] = [
                            'destination_index' => $dest['destination_index'],
                            'type' => 'identity',
                            'result' => $idResult
                        ];
                        
                        error_log("[review_batch] Identity destination {$dest['destination_index']} placed on hold");
                        
                    } catch (Exception $e) {
                        $overallFailed++;
                        $errorMsg = $e->getMessage();
                        
                        $failedDestinations[] = [
                            'index' => $dest['destination_index'],
                            'institution' => $dest['institution'] ?? 'IDENTITY_RECIPIENT',
                            'beneficiary' => $dest['beneficiary_name'] ?? $dest['identity_value'] ?? 'Unknown',
                            'amount' => $dest['amount'],
                            'currency' => $dest['currency'] ?? 'BWP',
                            'error' => $errorMsg
                        ];
                        
                        error_log("[review_batch] Identity destination {$dest['destination_index']} failed: " . $errorMsg);
                        
                        $stmt = $db->prepare("
                            UPDATE disbursement_destinations
                            SET status = 'FAILED',
                                error_message = :error
                            WHERE batch_id = :batch_id AND destination_index = :idx
                        ");
                        $stmt->execute([
                            ':error' => $errorMsg,
                            ':batch_id' => $batchId,
                            ':idx' => $dest['destination_index'],
                        ]);
                    }
                }
                
                // Institution destinations - MULTI_DESTINATION
                if (!empty($institutionDestinations)) {
                    error_log("[review_batch] Processing " . count($institutionDestinations) . " institution destinations via MULTI_DESTINATION");
                    
                    $multiPayload = [
                        'swap_type' => 'MULTI_DESTINATION',
                        'reference' => $batch['batch_reference'],
                        'from_institution' => $batch['source_institution'],
                        'source_institution' => $batch['source_institution'],
                        'asset_type' => $batch['source_asset_type'] ?? 'ACCOUNT',
                        'source_identifier' => $batch['source_identifier'],
                        'amount' => array_sum(array_column($institutionDestinations, 'amount')),
                        'currency' => $batch['currency'] ?? 'BWP',
                        'destinations' => [],
                    ];
                    
                    foreach ($institutionDestinations as $dest) {
                        $multiPayload['destinations'][] = [
                            'to_institution' => $dest['institution'],
                            'destination_institution' => $dest['institution'],
                            'destination_asset_type' => $dest['asset_type'] ?? 'WALLET',
                            'destination_identifier' => $dest['identifier'],
                            'destination_identifier_type' => $dest['identifier_type'] ?? 'account',
                            'amount' => (float)$dest['amount'],
                            'currency' => $dest['currency'] ?? 'BWP',
                            'delivery_method' => $dest['delivery_method'] ?? 'DEPOSIT',
                            'beneficiary_phone' => $dest['beneficiary_phone'] ?? null,
                            'beneficiary_name' => $dest['beneficiary_name'] ?? null,
                        ];
                    }
                    
                    error_log("[review_batch] MULTI_DESTINATION payload: " . json_encode($multiPayload, JSON_PRETTY_PRINT));
                    
                    $multiResult = $swapService->executeAtomicSwap($multiPayload);
                    
                    error_log("[review_batch] MULTI_DESTINATION result: " . json_encode($multiResult, JSON_PRETTY_PRINT));
                    
                    $multiSuccess = $multiResult['successful_destinations'] ?? 0;
                    $multiFailed = $multiResult['failed_destinations'] ?? 0;
                    
                    $overallSuccess += $multiSuccess;
                    $overallFailed += $multiFailed;
                    
                    foreach ($multiResult['destinations'] ?? [] as $idx => $destResult) {
                        $origDest = $institutionDestinations[$idx] ?? null;
                        if (!$origDest) continue;
                        
                        $status = $destResult['status'] ?? 'FAILED';
                        $errorMsg = $destResult['error'] ?? null;
                        
                        if ($status !== 'SUCCESS' && $status !== 'COMPLETED') {
                            $failedDestinations[] = [
                                'index' => $origDest['destination_index'],
                                'institution' => $origDest['institution'],
                                'beneficiary' => $origDest['beneficiary_name'] ?? $origDest['identifier'],
                                'amount' => $origDest['amount'],
                                'currency' => $origDest['currency'] ?? 'BWP',
                                'error' => $errorMsg ?? 'Unknown error'
                            ];
                        }
                        
                        $stmt = $db->prepare("
                            UPDATE disbursement_destinations
                            SET status = :status,
                                hold_reference = :hold_ref,
                                transaction_reference = :tx_ref,
                                error_message = :error
                            WHERE batch_id = :batch_id AND destination_index = :idx
                        ");
                        $stmt->execute([
                            ':status' => $status,
                            ':hold_ref' => $destResult['hold_reference'] ?? null,
                            ':tx_ref' => $destResult['transaction_reference'] ?? null,
                            ':error' => $errorMsg,
                            ':batch_id' => $batchId,
                            ':idx' => $origDest['destination_index'],
                        ]);
                    }
                    
                    $allResults[] = [
                        'type' => 'multi_destination',
                        'result' => $multiResult
                    ];
                }
                
                $finalStatus = 'completed';
                if ($overallFailed > 0 && $overallSuccess > 0) {
                    $finalStatus = 'partial_success';
                } elseif ($overallFailed > 0 && $overallPending == 0 && $overallSuccess == 0) {
                    $finalStatus = 'failed';
                } elseif ($overallPending > 0) {
                    $finalStatus = 'pending_identity_confirmation';
                }
                
                $isTerminal = in_array($finalStatus, ['completed', 'success', 'COMPLETED']);
                
                error_log("[review_batch] Final status: $finalStatus (success: $overallSuccess, failed: $overallFailed, pending: $overallPending)");
                
                $stmt = $db->prepare("
                    UPDATE disbursement_batches 
                    SET status = :status,
                        successful_count = :success,
                        failed_count = :failed,
                        pending_count = :pending,
                        executed_by = :user_id,
                        executed_at = NOW(),
                        results_payload = :results::jsonb,
                        completed_at = CASE WHEN :is_terminal::boolean THEN NOW() ELSE completed_at END,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':status' => $finalStatus,
                    ':success' => $overallSuccess,
                    ':failed' => $overallFailed,
                    ':pending' => $overallPending,
                    ':user_id' => $userId,
                    ':results' => json_encode($allResults),
                    ':is_terminal' => $isTerminal ? 't' : 'f',
                    ':id' => $batchId
                ]);
                
                if ($finalStatus === 'completed') {
                    $success = "✅ All $overallSuccess destinations paid successfully!";
                } elseif ($finalStatus === 'partial_success') {
                    $success = "⚠️ Batch partially executed.<br>";
                    $success .= "✅ <strong>$overallSuccess succeeded</strong><br>";
                    $success .= "❌ <strong>$overallFailed failed</strong><br><br>";
                    $success .= "<strong>Failed Destinations:</strong><br>";
                    foreach ($failedDestinations as $failed) {
                        $success .= "• <strong>#{$failed['index']}</strong> - {$failed['institution']} - ";
                        $success .= "{$failed['beneficiary']} - " . number_format($failed['amount'], 2) . " {$failed['currency']}<br>";
                        $success .= "  <span style='color:#7A2118; font-size:12px;'>Error: " . htmlspecialchars($failed['error']) . "</span><br>";
                    }
                } elseif ($finalStatus === 'failed') {
                    $error = "❌ All $overallFailed destinations failed.<br><br>";
                    $error .= "<strong>Failed Destinations:</strong><br>";
                    foreach ($failedDestinations as $failed) {
                        $error .= "• <strong>#{$failed['index']}</strong> - {$failed['institution']} - ";
                        $error .= "{$failed['beneficiary']} - " . number_format($failed['amount'], 2) . " {$failed['currency']}<br>";
                        $error .= "  <span style='color:#7A2118; font-size:12px;'>Error: " . htmlspecialchars($failed['error']) . "</span><br>";
                    }
                } else {
                    $success = "Batch executed! $overallSuccess succeeded, $overallFailed failed, $overallPending pending identity confirmation.";
                }
                
                $batch['status'] = $finalStatus;
                
            } catch (Exception $e) {
                error_log("[review_batch] Execution error: " . $e->getMessage());
                error_log("[review_batch] Trace: " . $e->getTraceAsString());
                
                $stmt = $db->prepare("
                    UPDATE disbursement_destinations 
                    SET status = 'FAILED',
                        error_message = :error,
                        updated_at = NOW()
                    WHERE batch_id = :batch_id 
                    AND status NOT IN ('COMPLETED', 'SUCCESS')
                ");
                $stmt->execute([
                    ':error' => 'System error: ' . $e->getMessage(),
                    ':batch_id' => $batchId
                ]);
                
                $error = "❌ Execution failed: " . $e->getMessage();
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$roleDisplay = strtoupper($user['role'] ?? 'USER');

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
    <title>VOUCHMORPH · Review Batch</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           VOUCHMORPH STANDARD STYLE
           Sharp corners · Centralized · Brass/Ink-900 · Appropriate font sizes
           ============================================================ */
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
            --danger:       #b3261e;
            --danger-bg:    #fbeceb;

            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            min-height: 100vh;
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        :focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }

        /* ============================================================
           HEADER
           ============================================================ */
        .masthead {
            background: var(--ink-900);
            color: #fff;
            padding: 14px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--brass);
            flex-wrap: wrap;
            gap: 10px;
        }
        .masthead h1 {
            font-family: var(--f-cond);
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .masthead .role-pill {
            font-size: 10px;
            font-weight: 700;
            color: var(--brass);
            border: 1px solid var(--brass);
            padding: 2px 10px;
            text-transform: uppercase;
            font-family: var(--f-cond);
            letter-spacing: 0.05em;
        }
        .masthead .ref {
            color: var(--ink-300);
            font-size: 12px;
            margin-left: 12px;
            font-family: var(--f-mono);
        }
        .masthead .logout-link {
            color: rgba(255,255,255,0.4);
            text-decoration: none;
            margin-left: 16px;
            font-size: 11px;
            font-family: var(--f-cond);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .masthead .logout-link:hover {
            color: var(--brass);
        }

        /* ============================================================
           CONTENT
           ============================================================ */
        .stage {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 20px;
        }

        /* ============================================================
           BACK LINK
           ============================================================ */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ink-500);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            font-family: var(--f-cond);
            letter-spacing: 0.02em;
        }
        .back-link:hover { color: var(--brass); }

        /* ============================================================
           STEP INDICATOR
           ============================================================ */
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 28px;
            padding: 0 8px;
        }
        .step {
            flex: 1;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: var(--ink-300);
            text-transform: uppercase;
            font-family: var(--f-cond);
            letter-spacing: 0.04em;
        }
        .step.active { color: var(--ink-900); }
        .step.done { color: var(--ledger-green); }

        /* ============================================================
           CARDS
           ============================================================ */
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 24px;
            margin-bottom: 20px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            font-family: var(--f-cond);
            letter-spacing: 0.02em;
        }
        .readonly-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #fef3c7;
            color: var(--amber);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            font-family: var(--f-cond);
            letter-spacing: 0.04em;
            border: 1px solid #f59e0b;
        }

        /* ============================================================
           STATUS
           ============================================================ */
        .workflow-status {
            padding: 4px 14px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            font-family: var(--f-cond);
            letter-spacing: 0.04em;
        }
        .status-draft { background: var(--line); color: var(--ink-500); }
        .status-pending_approval { background: #fef3c7; color: var(--amber); }
        .status-approved { background: var(--blue-tint); color: #1e40af; }
        .status-rejected { background: var(--danger-bg); color: var(--danger); }
        .status-completed { background: var(--green-tint); color: var(--ledger-green); }
        .status-failed { background: var(--danger-bg); color: var(--danger); }
        .status-pending_identity_confirmation { background: var(--blue-tint); color: #1e40af; }
        .status-partial_success { background: #fef3c7; color: var(--amber); }
        .status-success { background: var(--green-tint); color: var(--ledger-green); }

        /* ============================================================
           GRID
           ============================================================ */
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        /* ============================================================
           TABLE
           ============================================================ */
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: var(--paper);
            color: var(--ink-500);
            padding: 10px 14px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            border-bottom: 2px solid var(--line);
            font-family: var(--f-cond);
        }
        td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
            font-size: 13px;
        }
        tr:hover { background: var(--brass-tint); }
        .table-code {
            font-family: var(--f-mono);
            font-size: 11px;
            background: var(--paper);
            padding: 2px 6px;
        }

        /* ============================================================
           MESSAGES
           ============================================================ */
        .error {
            background: var(--danger-bg);
            color: var(--danger);
            padding: 14px 18px;
            margin-bottom: 16px;
            border-left: 3px solid var(--danger);
            font-size: 14px;
            line-height: 1.6;
        }
        .error .failed-item {
            margin-left: 16px;
            margin-top: 4px;
            font-size: 13px;
        }
        .error .failed-item .error-msg {
            color: var(--danger);
            font-size: 12px;
        }
        .success {
            background: var(--green-tint);
            color: var(--ledger-green);
            padding: 14px 18px;
            margin-bottom: 16px;
            border-left: 3px solid var(--ledger-green);
            font-size: 14px;
            line-height: 1.6;
        }
        .success .failed-item {
            margin-left: 16px;
            margin-top: 4px;
            font-size: 13px;
            color: var(--amber);
        }
        .success .failed-item .error-msg {
            color: var(--danger);
            font-size: 12px;
        }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn {
            padding: 8px 22px;
            border: none;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s;
            font-family: var(--f-cond);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .btn:hover { opacity: 0.85; }
        .btn-primary { background: var(--ink-900); color: #fff; }
        .btn-primary:hover { background: var(--brass); color: var(--ink-900); }
        .btn-success { background: var(--ledger-green); color: #fff; }
        .btn-success:hover { background: #1a3d2c; }
        .btn-danger { background: var(--seal-red); color: #fff; }
        .btn-danger:hover { background: #5a1812; }
        .btn-secondary { background: var(--line); color: var(--ink-700); }
        .btn-secondary:hover { background: var(--line-strong); }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--line);
            color: var(--ink-500);
        }
        .btn-outline:hover {
            border-color: var(--brass);
            color: var(--ink-900);
            background: var(--brass-tint);
        }
        .btn-execute {
            background: var(--seal-red);
            color: #fff;
            font-size: 14px;
            padding: 10px 32px;
        }
        .btn-execute:hover {
            background: #5a1812;
        }
        .btn-retry {
            background: var(--blue-tint);
            color: #1e40af;
            padding: 4px 14px;
            font-size: 11px;
            border: none;
            cursor: pointer;
            font-family: var(--f-cond);
            font-weight: 600;
        }
        .btn-retry:hover { background: #bfdbfe; }

        /* ============================================================
           ACTIONS BAR
           ============================================================ */
        .actions-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        /* ============================================================
           REJECTION FORM
           ============================================================ */
        .rejection-form {
            display: none;
            margin-top: 12px;
            padding: 16px;
            background: var(--danger-bg);
        }
        .rejection-form.show { display: block; }
        .rejection-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--line);
            min-height: 80px;
            font-family: var(--f-body);
            font-size: 13px;
            background: var(--panel);
        }
        .rejection-form textarea:focus {
            outline: 2px solid var(--brass);
            outline-offset: 1px;
        }
        .rejection-form .form-group {
            margin-bottom: 12px;
        }
        .rejection-form .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-family: var(--f-cond);
            color: var(--ink-500);
        }

        /* ============================================================
           DEBUG PANEL
           ============================================================ */
        .debug-panel {
            background: #1e293b;
            color: #e2e8f0;
            padding: 16px;
            overflow-x: auto;
            font-family: var(--f-mono);
            font-size: 12px;
            white-space: pre-wrap;
            word-break: break-all;
            margin-top: 12px;
        }
        .debug-panel .label { color: #fbbf24; }
        .debug-panel .value { color: #4ade80; }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            .grid-3 { grid-template-columns: 1fr; }
            .masthead { flex-direction: column; text-align: center; padding: 12px 16px; }
            .step-indicator { flex-wrap: wrap; gap: 8px; }
            .step { flex: 0 0 45%; }
            .stage { padding: 16px; }
            .card { padding: 16px; }
            .actions-bar { flex-direction: column; }
            .btn { width: 100%; text-align: center; }
        }

        @media (max-width: 480px) {
            .masthead h1 { font-size: 15px; }
            .step { font-size: 9px; }
            table { font-size: 12px; }
            th, td { padding: 6px 8px; }
        }

        /* ============================================================
           DARK MODE SUPPORT
           ============================================================ */
        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #1B2733;
                --panel: #1B2733;
                --ink-900: #ECEFF2;
                --ink-700: #D5DCE0;
                --ink-500: #93A2AC;
                --ink-300: #6B7A85;
                --line: #2C3A45;
            }
            .masthead { background: #0d1a26; }
            .card { background: #1B2733; border-color: #2C3A45; }
            .card-header { border-color: #2C3A45; }
            th { background: #1B2733; color: #93A2AC; border-color: #2C3A45; }
            td { border-color: #2C3A45; }
            tr:hover { background: #22303A; }
            .btn-primary { background: #2C3A45; color: #ECEFF2; }
            .btn-primary:hover { background: var(--brass); color: var(--ink-900); }
            .btn-outline { border-color: #2C3A45; color: #93A2AC; }
            .btn-outline:hover { border-color: var(--brass); color: #ECEFF2; background: #22303A; }
            .status-draft { background: #2C3A45; color: #93A2AC; }
            .table-code { background: #2C3A45; color: #93A2AC; }
            .rejection-form textarea { background: #1B2733; border-color: #2C3A45; color: #ECEFF2; }
            .debug-panel { background: #0d1a26; }
        }
    </style>
</head>
<body>
    <!-- ============================================================ -->
    <!-- HEADER -->
    <!-- ============================================================ -->
    <div class="masthead">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <h1>VOUCHMORPH · Review Batch</h1>
            <span class="role-pill"><?php echo $roleDisplay; ?></span>
            <span class="ref"><?php echo htmlspecialchars($batch['batch_reference']); ?></span>
        </div>
        <div>
            <a href="../logout.php" class="logout-link">Sign Out</a>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- CONTENT -->
    <!-- ============================================================ -->
    <div class="stage">
        <a href="add_destinations.php?batch_id=<?php echo $batchId; ?>" class="back-link">← Back to Destinations</a>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <span class="step done">1. Select Source</span>
            <span class="step done">2. Add Destinations</span>
            <span class="step active">3. Review</span>
            <span class="step">4. Approve</span>
            <span class="step">5. Execute</span>
        </div>

        <!-- Messages -->
        <?php if ($error): ?>
        <div class="error">
            ⚠️ <?php echo $error; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="success">
            ✅ <?php echo $success; ?>
        </div>
        <?php endif; ?>

        <!-- Read-Only Notice -->
        <?php if ($isReadOnly && !in_array($user['role'] ?? '', ['owner', 'approver', 'senior_approver'])): ?>
        <div class="card" style="border-left: 3px solid #f59e0b; background: #fef3c7;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:22px;">🔒</span>
                <div>
                    <strong style="color:var(--amber); font-family:var(--f-cond);">Read-Only Mode</strong>
                    <p style="color:var(--ink-500); font-size:13px; margin-top:2px;">
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
                    <span class="readonly-badge" style="margin-left:8px;">🔒 Read-Only</span>
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
            <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--line);">
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
            <div style="margin-top:14px; padding:14px; background:var(--danger-bg);">
                <strong style="color:var(--danger);">Rejection Reason:</strong> 
                <span style="color:var(--danger);"><?php echo htmlspecialchars($batch['rejection_reason']); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($batch['executed_at']): ?>
            <div>
                <strong>Executed:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['executed_at'])); ?>
                by <?php echo htmlspecialchars($batch['executed_by_name'] ?? 'N/A'); ?>
            </div>
            <?php endif; ?>
            
            <?php if (in_array($status, ['partial_success', 'failed'])): ?>
            <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--line);">
                <div style="display:flex; gap:24px; flex-wrap:wrap; font-size:14px;">
                    <div><strong style="color:var(--ledger-green);">✅ Successful:</strong> <?php echo $batch['successful_count'] ?? 0; ?></div>
                    <div><strong style="color:var(--danger);">❌ Failed:</strong> <?php echo $batch['failed_count'] ?? 0; ?></div>
                    <div><strong style="color:var(--amber);">⏳ Pending:</strong> <?php echo $batch['pending_count'] ?? 0; ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Destinations -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">👥 Destinations (<?php echo count($destinations); ?>)</span>
                <?php if (in_array($status, ['partial_success', 'failed'])): ?>
                <span style="color:var(--danger); font-weight:600; font-family:var(--f-cond);">
                    ⚠️ <?php echo $batch['failed_count'] ?? 0; ?> failed
                </span>
                <?php endif; ?>
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
                            <th>Transaction Ref</th>
                            <th>Error Message</th>
                            <?php if ($user['role'] === 'owner' && in_array($status, ['partial_success', 'failed'])): ?>
                            <th>Action</th>
                            <?php endif; ?>
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
                                <?php
                                $statusClass = strtolower($dest['status'] ?? 'PENDING');
                                $displayStatus = $dest['status'] ?? 'PENDING';
                                
                                if (in_array($statusClass, ['completed', 'success'])) {
                                    $displayStatus = '✅ ' . $displayStatus;
                                } elseif ($statusClass === 'failed') {
                                    $displayStatus = '❌ ' . $displayStatus;
                                } elseif (in_array($statusClass, ['pending', 'processing'])) {
                                    $displayStatus = '⏳ ' . $displayStatus;
                                }
                                ?>
                                <span class="workflow-status status-<?php echo $statusClass; ?>">
                                    <?php echo $displayStatus; ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($dest['transaction_reference'])): ?>
                                    <code class="table-code"><?php echo htmlspecialchars($dest['transaction_reference']); ?></code>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--danger); font-size:12px; max-width:200px;">
                                <?php echo htmlspecialchars($dest['error_message'] ?? ''); ?>
                            </td>
                            <?php if ($user['role'] === 'owner' && in_array($status, ['partial_success', 'failed']) && strtolower($dest['status'] ?? '') === 'failed'): ?>
                            <td>
                                <a href="retry_destination.php?batch_id=<?php echo $batchId; ?>&dest_idx=<?php echo $dest['destination_index']; ?>" 
                                   class="btn-retry">
                                    🔄 Retry
                                </a>
                            </td>
                            <?php endif; ?>
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
                <!-- Submit for Approval -->
                <?php if ($status === 'draft' && $canSubmit && !$isReadOnly): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="submit_for_approval">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this batch for approval?')">
                        📤 Submit for Approval
                    </button>
                </form>
                <?php endif; ?>

                <!-- Approve / Reject -->
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
                            <label>Rejection Reason</label>
                            <textarea name="rejection_reason" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger">Submit Rejection</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleRejection()" style="margin-left:8px;">Cancel</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Execute - Only Owners -->
                <?php if ($status === 'approved' && $canExecute): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ EXECUTE DISBURSEMENT: This will move real funds. Only proceed if you have verified all approvals. Continue?')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="execute">
                    <button type="submit" class="btn btn-execute">
                        🚀 EXECUTE DISBURSEMENT
                    </button>
                </form>
                <?php endif; ?>

                <!-- Edit Destinations - Draft only -->
                <?php if ($status === 'draft' && $canEdit && !$isReadOnly): ?>
                <a href="add_destinations.php?batch_id=<?php echo $batchId; ?>" class="btn btn-secondary">✏️ Edit Destinations</a>
                <?php endif; ?>

                <!-- Navigation -->
                <a href="../batches/index.php" class="btn btn-outline">📋 All Batches</a>
                <a href="../index.php" class="btn btn-outline">🏠 Dashboard</a>
            </div>
        </div>

        <!-- Debug Panel -->
        <?php if ($error && strpos($error, 'Execution failed') !== false): ?>
        <div class="card" style="border-left: 3px solid #f59e0b; background: #fffbeb; margin-top: 20px;">
            <div class="card-header">
                <span class="card-title">🔍 Debug Information</span>
                <span class="readonly-badge" style="background:var(--danger); color:#fff; border-color:var(--danger);">ERROR</span>
            </div>
            <div class="debug-panel">
                <strong style="color: #fbbf24;">Error:</strong> <?php echo htmlspecialchars($error); ?><br><br>
                <strong style="color: #fbbf24;">Batch ID:</strong> <?php echo $batchId; ?><br>
                <strong style="color: #fbbf24;">Batch Reference:</strong> <?php echo htmlspecialchars($batch['batch_reference']); ?><br>
                <strong style="color: #fbbf24;">Source Institution:</strong> <?php echo htmlspecialchars($batch['source_institution']); ?><br>
                <strong style="color: #fbbf24;">Total Amount:</strong> <?php echo number_format($batch['total_amount'] ?? 0, 2); ?><br>
                <strong style="color: #fbbf24;">Destinations:</strong> <?php echo count($destinations); ?><br><br>
                
                <strong style="color: #60a5fa;">Destination Details:</strong><br>
                <?php foreach ($destinations as $idx => $dest): ?>
                <span style="color: #94a3b8;">Destination <?php echo $idx + 1; ?>:</span><br>
                &nbsp;&nbsp;Institution: <span style="color: #4ade80;"><?php echo htmlspecialchars($dest['institution'] ?? 'NULL'); ?></span><br>
                &nbsp;&nbsp;Identifier: <span style="color: #4ade80;"><?php echo htmlspecialchars($dest['identifier'] ?? 'NULL'); ?></span><br>
                &nbsp;&nbsp;Amount: <span style="color: #4ade80;"><?php echo htmlspecialchars($dest['amount'] ?? 'NULL'); ?></span><br>
                &nbsp;&nbsp;Currency: <span style="color: #4ade80;"><?php echo htmlspecialchars($dest['currency'] ?? 'NULL'); ?></span><br>
                &nbsp;&nbsp;Identity: <span style="color: <?php echo ($dest['is_identity_recipient'] ?? false) ? '#fbbf24' : '#94a3b8'; ?>;"><?php echo ($dest['is_identity_recipient'] ?? false) ? 'YES' : 'NO'; ?></span><br>
                &nbsp;&nbsp;Status: <span style="color: #4ade80;"><?php echo htmlspecialchars($dest['status'] ?? 'PENDING'); ?></span><br>
                <?php endforeach; ?>
            </div>
            <div style="margin-top: 14px;">
                <p style="color: var(--ink-500); font-size: 13px;">
                    💡 Check the server logs for the full payload dump.
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleRejection() {
            document.getElementById('rejectionForm').classList.toggle('show');
        }
    </script>
</body>
</html>
