<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// ============================================================
// LOAD SWAPSERVICE AND DEPENDENCIES
// ============================================================
require_once '../../../../vendor/autoload.php';
require_once '../../../../src/Domain/Services/SwapService.php';
require_once '../../../../src/Domain/Services/DepartmentService.php';
require_once '../../../../src/Core/Config/LoadCountry.php';

use Domain\Services\SwapService;
use Domain\Services\DepartmentService;
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

function safeHtmlRb($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount, $currency = 'BWP') {
    return number_format((float)$amount, 2) . ' ' . $currency;
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
// CHECK PERMISSIONS - EXPLICIT ROLE-BASED
// ============================================================
$role = $user['role'] ?? 'viewer';
$isOwnBatch = ($batch['created_by'] == $userId);
$canEdit = canEditBatch($batch['created_by'], $userId, $role);
$isReadOnly = !$canEdit;

// ============================================================
// EXPLICIT ROLE PERMISSIONS - CLEAR AND UNAMBIGUOUS
// ============================================================
// Submit: Owner, Program Officer, Department Head (own batches)
$canSubmit = in_array($role, ['owner', 'program_officer', 'department_head']) && $canEdit;

// Approve: Approver or Senior Approver ONLY
$canApprove = in_array($role, ['approver', 'senior_approver']);

// Execute/DISBURSE: OWNER ONLY - NO EXCEPTIONS
$canExecute = ($role === 'owner');

// SAFETY: Double-check that approvers cannot execute under any circumstances
if ($role === 'approver' || $role === 'senior_approver') {
    $canExecute = false;
    $canApprove = true; // Approvers CAN approve
}

// ============================================================
// EXPLICIT BLOCK: Approvers CANNOT execute/disburse
// ============================================================
$isApprover = ($role === 'approver' || $role === 'senior_approver');
$executeDisabled = !$canExecute;
$executeDisabledReason = '';

if ($isApprover) {
    $executeDisabledReason = 'Approvers cannot execute disbursements. Only Owners can disburse funds.';
} elseif ($role === 'program_officer' || $role === 'department_head') {
    $executeDisabledReason = 'Program Officers and Department Heads cannot execute disbursements. Only Owners can disburse funds.';
} elseif ($role === 'viewer') {
    $executeDisabledReason = 'Viewers cannot execute disbursements.';
}

// ============================================================
// DEPARTMENT / RATION CONTEXT
// ============================================================
// Loaded for two purposes: (1) enforce the ration check when
// submit_for_approval / approve fire below, (2) show the department's
// live ration status on the summary card so a submitter can see BEFORE
// hitting submit whether this batch fits.
$deptService = new DepartmentService($db);
$departmentInfo = null;
$rationInfo = null;

if (!empty($batch['department_id'])) {
    try {
        $stmt = $db->prepare("SELECT id, name, code FROM departments WHERE id = :id");
        $stmt->execute([':id' => $batch['department_id']]);
        $departmentInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        $rationInfo = $deptService->getAvailableRation((int)$batch['department_id']);
    } catch (\Throwable $e) {
        error_log("[review_batch] Failed to load department/ration info: " . $e->getMessage());
    }
}

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
error_log("Department ID: " . ($batch['department_id'] ?? 'NULL'));
error_log("Destinations Count: " . count($destinations));
error_log("User Role: " . $role);
error_log("Can Execute: " . ($canExecute ? 'YES' : 'NO'));

foreach ($destinations as $idx => $dest) {
    error_log("Destination $idx: " . json_encode($dest));
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    
    // ============================================================
    // SECURITY: Explicitly block execute for non-owners
    // ============================================================
    if ($action === 'execute' && !$canExecute) {
        $error = "🚫 SECURITY BLOCK: You do not have permission to execute this disbursement. Only Owners can disburse funds.";
        error_log("[SECURITY] User " . ($userId ?? 'unknown') . " (role: $role) attempted to execute batch $batchId without permission");
        // Don't proceed with any further processing
    } elseif ($isReadOnly && !in_array($action, ['approve', 'reject', 'execute'])) {
        $error = "You cannot modify this batch. It was created by another user.";
    } else {
        if ($action === 'submit_for_approval') {
            // ============================================================
            // RATION CHECK — gates the draft -> pending_approval transition.
            // A batch that would blow its department's remaining ration
            // never gets submitted; the submitter sees exactly why (ceiling,
            // disbursed, reserved, shortfall) and a link to request a borrow
            // instead of a silent failure or a rejection three steps later.
            // ============================================================
            if (empty($batch['department_id'])) {
                $error = "This batch has no department assigned, so its budget ration can't be checked. Contact an admin before submitting.";
            } else {
                try {
                    $deptService->assertBatchFitsRation(
                        (int)$batch['department_id'],
                        (float)($batch['total_amount'] ?? 0)
                    );
                } catch (\RuntimeException $e) {
                    $error = "🚫 " . $e->getMessage()
                        . ' <a href="../departments/index.php" style="color:var(--brass); font-weight:600;">Request a ration borrow →</a>';
                }
            }

            if (!$error) {
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
            }
            
        } elseif ($action === 'approve') {
            // ============================================================
            // RATION RE-CHECK at approval time too. Ration is live (other
            // batches for the same department may have been approved,
            // disbursed, or a borrow may have been rejected since this
            // batch was submitted), so re-verify rather than trusting the
            // check that ran at submit time.
            // ============================================================
            if (empty($batch['department_id'])) {
                $error = "This batch has no department assigned — cannot verify budget ration before approving.";
            } else {
                try {
                    $deptService->assertBatchFitsRation(
                        (int)$batch['department_id'],
                        (float)($batch['total_amount'] ?? 0)
                    );
                } catch (\RuntimeException $e) {
                    $error = "🚫 This batch no longer fits its department's ration: " . $e->getMessage()
                        . ' <a href="../departments/index.php" style="color:var(--brass); font-weight:600;">Review ration →</a>';
                }
            }

            if (!$error) {
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
            }
            
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
            // UPDATED: NEW IDEMPOTENT EXECUTION LOGIC
            // This will only run if $canExecute is true (owner only)
            // ============================================================
            error_log("=== REVIEW_BATCH DEBUG: EXECUTION STARTED ===");
            
            try {
                // ============================================================
                // STEP 1: Lock the batch immediately (prevents double-click)
                // ============================================================
                $db->query("UPDATE disbursement_batches SET status = 'executing', 
                            execution_started_at = NOW() 
                            WHERE id = ? AND status = 'approved'", 
                            [$batchId]);

                if ($db->affectedRows() === 0) {
                    // Check if batch is already in a terminal state
                    $checkStmt = $db->prepare("SELECT status FROM disbursement_batches WHERE id = ?");
                    $checkStmt->execute([$batchId]);
                    $currentStatus = $checkStmt->fetchColumn();
                    
                    if (in_array($currentStatus, ['completed', 'partial_success', 'failed'])) {
                        $error = "Batch has already been processed (status: $currentStatus).";
                    } else {
                        $error = "Batch is already being processed or is not approved.";
                    }
                    
                    // Refresh batch status to show current state
                    $refreshStmt = $db->prepare("SELECT status FROM disbursement_batches WHERE id = ?");
                    $refreshStmt->execute([$batchId]);
                    $batch['status'] = $refreshStmt->fetchColumn() ?: $batch['status'];
                    
                    header("Location: review_batch.php?batch_id=" . $batchId);
                    exit;
                }

                // ============================================================
                // STEP 2: Fetch destinations, filtering out already-processed ones
                // ============================================================
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
                
                // Get destinations that haven't been processed yet
                $destStmt = $db->prepare("
                    SELECT * FROM disbursement_destinations 
                    WHERE batch_id = ? 
                    AND status NOT IN ('completed', 'success', 'failed')
                    ORDER BY destination_index
                ");
                $destStmt->execute([$batchId]);
                $pendingDestinations = $destStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($pendingDestinations)) {
                    $error = "No pending destinations to process.";
                    $db->query("UPDATE disbursement_batches SET status = 'completed' WHERE id = ?", [$batchId]);
                    header("Location: review_batch.php?batch_id=" . $batchId);
                    exit;
                }
                
                error_log("[review_batch] Pending destinations: " . count($pendingDestinations));
                
                $overallSuccess = 0;
                $overallFailed = 0;
                $failedDestinations = [];
                $sourceInstitution = $batch['source_institution'];
                $sourceAccountIdentifier = $batch['source_identifier'];
                $currency = $batch['currency'] ?? 'BWP';
                
                // ============================================================
                // Process each destination with idempotency keys
                // ============================================================
                foreach ($pendingDestinations as $dest) {
                    error_log("[review_batch] Processing destination: " . json_encode($dest));
                    
                    // ============================================================
                    // FIX: PASS THE IDEMPOTENCY KEY
                    // ============================================================
                    $idempotencyKey = $batch['batch_reference'] . '_ID_' . $dest['destination_index'];
                    
                    // Build the swap request
                    $swapRequest = [
                        'reference' => $idempotencyKey,
                        'idempotency_key' => $idempotencyKey,  // <-- THIS IS THE KEY
                        'from_institution' => $sourceInstitution,
                        'to_institution' => $dest['institution'],
                        'amount' => (float)$dest['amount'],
                        'currency' => $currency,
                        'source_identifier' => $sourceAccountIdentifier,
                        'destination_identifier' => $dest['identifier'],
                        'destination_identifier_type' => $dest['identifier_type'] ?? 'account',
                        'asset_type' => $dest['asset_type'] ?? 'WALLET',
                        'user_id' => $_SESSION['user_id'] ?? $userId,
                        'beneficiary_name' => $dest['beneficiary_name'] ?? null,
                        'beneficiary_phone' => $dest['beneficiary_phone'] ?? null,
                        'delivery_method' => $dest['delivery_method'] ?? 'DEPOSIT',
                        'swap_type' => 'SINGLE_DESTINATION',
                        'source_institution' => $sourceInstitution,
                    ];
                    
                    error_log("[review_batch] Swap request with idempotency key: " . $idempotencyKey);
                    
                    try {
                        // ============================================================
                        // executeAtomicSwap ALREADY checks idempotency internally!
                        // See lines ~2500-2506 in your SwapService.php
                        // ============================================================
                        $result = $swapService->executeAtomicSwap($swapRequest);
                        
                        // Mark individual destination as completed
                        $updateStmt = $db->prepare("
                            UPDATE disbursement_destinations 
                            SET status = 'completed', 
                                completed_at = NOW(), 
                                transaction_reference = ?,
                                hold_reference = ?
                            WHERE id = ?
                        ");
                        $updateStmt->execute([
                            $result['reference'] ?? $result['swap_reference'] ?? $result['transaction_reference'] ?? null,
                            $result['hold_reference'] ?? null,
                            $dest['id']
                        ]);
                        
                        $overallSuccess++;
                        error_log("[review_batch] Destination {$dest['destination_index']} completed successfully");
                        
                    } catch (Exception $e) {
                        // Mark as failed, allow retry for this specific destination
                        $errorMsg = $e->getMessage();
                        
                        $updateStmt = $db->prepare("
                            UPDATE disbursement_destinations 
                            SET status = 'failed', 
                                error_message = ?,
                                completed_at = NOW()
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$errorMsg, $dest['id']]);
                        
                        $failedDestinations[] = [
                            'index' => $dest['destination_index'],
                            'institution' => $dest['institution'],
                            'beneficiary' => $dest['beneficiary_name'] ?? $dest['identifier'],
                            'amount' => $dest['amount'],
                            'currency' => $dest['currency'] ?? 'BWP',
                            'error' => $errorMsg
                        ];
                        
                        $overallFailed++;
                        error_log("[review_batch] Destination {$dest['destination_index']} failed: " . $errorMsg);
                    }
                }
                
                // ============================================================
                // STEP 3: Finalize batch status
                // ============================================================
                // Count failed destinations after processing
                $failedCountStmt = $db->prepare("
                    SELECT COUNT(*) FROM disbursement_destinations 
                    WHERE batch_id = ? AND status = 'failed'
                ");
                $failedCountStmt->execute([$batchId]);
                $totalFailed = $failedCountStmt->fetchColumn() ?: 0;
                
                // Count completed destinations
                $completedCountStmt = $db->prepare("
                    SELECT COUNT(*) FROM disbursement_destinations 
                    WHERE batch_id = ? AND status IN ('completed', 'success')
                ");
                $completedCountStmt->execute([$batchId]);
                $totalCompleted = $completedCountStmt->fetchColumn() ?: 0;
                
                // Count total destinations
                $totalCountStmt = $db->prepare("
                    SELECT COUNT(*) FROM disbursement_destinations 
                    WHERE batch_id = ?
                ");
                $totalCountStmt->execute([$batchId]);
                $totalDestinations = $totalCountStmt->fetchColumn() ?: 0;
                
                if ($totalFailed > 0 && $totalCompleted == 0) {
                    // All failed
                    $updateStmt = $db->prepare("
                        UPDATE disbursement_batches 
                        SET status = 'failed', 
                            execution_completed_at = NOW(),
                            failed_count = ?,
                            successful_count = 0,
                            pending_count = 0,
                            executed_by = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$totalFailed, $userId, $batchId]);
                    $error = "All destinations failed. $totalFailed destinations failed.";
                    
                } elseif ($totalFailed > 0 && $totalCompleted > 0) {
                    // Partial success
                    $updateStmt = $db->prepare("
                        UPDATE disbursement_batches 
                        SET status = 'partial_success', 
                            execution_completed_at = NOW(),
                            failed_count = ?,
                            successful_count = ?,
                            pending_count = 0,
                            executed_by = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$totalFailed, $totalCompleted, $userId, $batchId]);
                    
                    $success = "⚠️ Batch partially executed.<br>";
                    $success .= "✅ <strong>$totalCompleted succeeded</strong><br>";
                    $success .= "❌ <strong>$totalFailed failed</strong><br><br>";
                    
                    if (!empty($failedDestinations)) {
                        $success .= "<strong>Failed Destinations:</strong><br>";
                        foreach ($failedDestinations as $failed) {
                            $success .= "• <strong>#{$failed['index']}</strong> - {$failed['institution']} - ";
                            $success .= "{$failed['beneficiary']} - " . number_format($failed['amount'], 2) . " {$failed['currency']}<br>";
                            $success .= "  <span style='color:#7A2118; font-size:12px;'>Error: " . htmlspecialchars($failed['error']) . "</span><br>";
                        }
                    }
                    
                } else {
                    // All succeeded
                    $updateStmt = $db->prepare("
                        UPDATE disbursement_batches 
                        SET status = 'completed', 
                            execution_completed_at = NOW(),
                            failed_count = 0,
                            successful_count = ?,
                            pending_count = 0,
                            executed_by = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$totalCompleted, $userId, $batchId]);
                    $success = "✅ All $totalCompleted destinations paid successfully!";
                }
                
                // ============================================================
                // Ration bookkeeping: amount_disbursed_ytd only reflects
                // amounts that actually moved. Only the successfully-executed
                // portion of this batch counts toward it — failed/pending
                // destinations were never disbursed and must not be added.
                // ============================================================
                if (!empty($batch['department_id']) && $totalCompleted > 0) {
                    try {
                        // Calculate successful amount from completed destinations
                        $successAmountStmt = $db->prepare("
                            SELECT SUM(amount) FROM disbursement_destinations 
                            WHERE batch_id = ? AND status IN ('completed', 'success')
                        ");
                        $successAmountStmt->execute([$batchId]);
                        $successfulAmount = $successAmountStmt->fetchColumn() ?: 0;
                        
                        if ($successfulAmount > 0) {
                            $stmt = $db->prepare("
                                UPDATE departments
                                SET amount_disbursed_ytd = amount_disbursed_ytd + :amt, updated_at = NOW()
                                WHERE id = :id
                            ");
                            $stmt->execute([':amt' => $successfulAmount, ':id' => $batch['department_id']]);
                            error_log("[review_batch] Department {$batch['department_id']} amount_disbursed_ytd increased by {$successfulAmount}");
                        }
                    } catch (\Throwable $e) {
                        // Non-fatal: the actual disbursement already happened via
                        // SwapService and that result is authoritative.
                        error_log("[review_batch] Failed to update department amount_disbursed_ytd: " . $e->getMessage());
                    }
                }
                
                // Refresh batch status
                $refreshStmt = $db->prepare("SELECT status FROM disbursement_batches WHERE id = ?");
                $refreshStmt->execute([$batchId]);
                $batch['status'] = $refreshStmt->fetchColumn() ?: $batch['status'];
                
            } catch (Exception $e) {
                error_log("[review_batch] Execution error: " . $e->getMessage());
                error_log("[review_batch] Trace: " . $e->getTraceAsString());
                
                // Update batch status to failed if critical error
                try {
                    $db->query("
                        UPDATE disbursement_batches 
                        SET status = 'failed',
                            error_message = ?,
                            execution_completed_at = NOW()
                        WHERE id = ?
                    ", [$e->getMessage(), $batchId]);
                } catch (\Exception $updateError) {
                    error_log("[review_batch] Failed to update batch status: " . $updateError->getMessage());
                }
                
                $error = "❌ Execution failed: " . $e->getMessage();
            }
        }
    }

    // Ration figures may have changed as a result of the action just taken
    // (submit/approve reserve ration by keeping the batch counted in
    // 'reserved_in_flight', execute converts reserved into disbursed) —
    // refresh before rendering so the summary card shows current numbers,
    // not the pre-action snapshot loaded above.
    if (!empty($batch['department_id'])) {
        try {
            $rationInfo = $deptService->getAvailableRation((int)$batch['department_id']);
        } catch (\Throwable $e) {
            error_log("[review_batch] Failed to refresh ration info: " . $e->getMessage());
        }
    }
}

$csrfToken = generateCsrfToken();
$roleDisplay = strtoupper($role);
$status = strtolower($batch['status'] ?? 'draft');

// ============================================================
// FINAL SAFETY CHECK: Approvers should NEVER see Execute button
// ============================================================
$showExecuteButton = ($status === 'approved' && $canExecute && !$isApprover);
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
        .masthead .role-pill.approver {
            border-color: #f59e0b;
            color: #f59e0b;
        }
        .masthead .role-pill.owner {
            border-color: var(--ledger-green);
            color: var(--ledger-green);
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
        .status-executing { background: var(--blue-tint); color: #1e40af; }

        /* ============================================================
           GRID
           ============================================================ */
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        /* ============================================================
           RATION PANEL
           ============================================================ */
        .ration-panel {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
        }
        .ration-panel .ration-title {
            font-family: var(--f-cond);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--ink-500);
            margin-bottom: 10px;
        }
        .ration-bar-track { height: 8px; background: var(--paper); border: 1px solid var(--line); margin-bottom: 10px; }
        .ration-bar-fill { height: 100%; }
        .ration-stats { display: flex; gap: 20px; flex-wrap: wrap; font-size: 12.5px; color: var(--ink-500); }
        .ration-stats strong { color: var(--ink-900); }
        .ration-stats .danger strong { color: var(--danger); }
        .ration-stats .ok strong { color: var(--ledger-green); }

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
        .btn-execute:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: var(--ink-300);
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
        
        .btn-approver-locked {
            background: #fef3c7;
            color: var(--amber);
            border: 1px solid #f59e0b;
            padding: 8px 22px;
            font-weight: 600;
            font-size: 12px;
            cursor: not-allowed;
            font-family: var(--f-cond);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            opacity: 0.7;
        }
        .btn-approver-locked:hover {
            opacity: 0.7;
        }

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
           SECURITY NOTICE
           ============================================================ */
        .security-notice {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 12px 16px;
            margin-top: 12px;
            font-size: 13px;
            color: var(--amber);
        }
        .security-notice strong {
            color: var(--amber);
        }
        .security-notice .lock-icon {
            font-size: 18px;
            margin-right: 8px;
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
            .security-notice { background: #1e293b; border-left-color: #f59e0b; color: #fbbf24; }
            .btn-approver-locked { background: #1e293b; border-color: #f59e0b; color: #fbbf24; }
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
            <span class="role-pill <?php echo $role === 'owner' ? 'owner' : ($isApprover ? 'approver' : ''); ?>">
                <?php echo $roleDisplay; ?>
            </span>
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
        <?php if ($isReadOnly && !in_array($role, ['owner', 'approver', 'senior_approver'])): ?>
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
                <div><strong>Department:</strong> <?php echo $departmentInfo ? htmlspecialchars($departmentInfo['name']) : '<span style="color:var(--danger);">Not assigned</span>'; ?></div>
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

            <!-- ============================================================
                 RATION PANEL — live department budget status for this batch.
                 Shown to everyone who can see the batch; only submitters and
                 approvers act on it, but visibility helps everyone understand
                 why a submit/approve might get blocked.
                 ============================================================ -->
            <?php if ($rationInfo): ?>
            <?php
                $utilPct = $rationInfo['ceiling'] > 0
                    ? min(100, round((($rationInfo['disbursed_ytd'] + $rationInfo['reserved_in_flight']) / $rationInfo['ceiling']) * 100, 1))
                    : 0;
                $barColor = $rationInfo['available'] < 0 ? 'var(--danger)' : ($utilPct >= 80 ? 'var(--amber)' : 'var(--ledger-green)');
                $thisBatchFits = (float)($batch['total_amount'] ?? 0) <= $rationInfo['available'] || in_array($status, ['approved', 'completed', 'executed', 'partial_success']);
            ?>
            <div class="ration-panel">
                <div class="ration-title">💰 Department Ration<?php echo $departmentInfo ? ' — ' . safeHtmlRb($departmentInfo['name']) : ''; ?></div>
                <div class="ration-bar-track"><div class="ration-bar-fill" style="width:<?php echo $utilPct; ?>%; background:<?php echo $barColor; ?>;"></div></div>
                <div class="ration-stats">
                    <span>Ceiling: <strong><?php echo formatCurrency($rationInfo['ceiling'], $rationInfo['currency']); ?></strong></span>
                    <span>Disbursed YTD: <strong><?php echo formatCurrency($rationInfo['disbursed_ytd'], $rationInfo['currency']); ?></strong></span>
                    <span>Reserved (pending/approved batches): <strong><?php echo formatCurrency($rationInfo['reserved_in_flight'], $rationInfo['currency']); ?></strong></span>
                    <span class="<?php echo $rationInfo['available'] < 0 ? 'danger' : 'ok'; ?>">Available: <strong><?php echo formatCurrency($rationInfo['available'], $rationInfo['currency']); ?></strong></span>
                </div>
                <?php if (!$thisBatchFits): ?>
                <div style="margin-top:10px; font-size:12.5px; color:var(--danger);">
                    ⚠️ This batch's total (<?php echo formatCurrency($batch['total_amount'] ?? 0, $rationInfo['currency']); ?>) exceeds available ration.
                    Submitting will be blocked until the department borrows more ration or the batch is reduced.
                    <a href="../departments/index.php" style="color:var(--brass); font-weight:600;">Go to Departments →</a>
                </div>
                <?php endif; ?>
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
                            <?php if ($role === 'owner' && in_array($status, ['partial_success', 'failed'])): ?>
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
                            <?php if ($role === 'owner' && in_array($status, ['partial_success', 'failed']) && strtolower($dest['status'] ?? '') === 'failed'): ?>
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
                <?php if ($isApprover): ?>
                <span class="readonly-badge" style="background: #fef3c7; border-color: #f59e0b; color: var(--amber);">
                    🔑 Approver Mode
                </span>
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

                <!-- ============================================================ -->
                <!-- EXECUTE / DISBURSE BUTTON - STRICTLY CONTROLLED                -->
                <!-- APPROVERS CANNOT SEE OR USE THIS BUTTON                       -->
                <!-- ============================================================ -->
                <?php if ($status === 'approved'): ?>
                    <?php if ($showExecuteButton): ?>
                        <!-- Owner only - Execute button visible -->
                        <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ EXECUTE DISBURSEMENT: This will move real funds. Only proceed if you have verified all approvals. Continue?')">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="execute">
                            <button type="submit" class="btn btn-execute">
                                🚀 EXECUTE DISBURSEMENT
                            </button>
                        </form>
                    <?php else: ?>
                        <!-- Non-Owner (Approver, Program Officer, Viewer) - Show locked button -->
                        <button class="btn-approver-locked" disabled style="cursor:not-allowed;">
                            🔒 DISBURSEMENT LOCKED
                        </button>
                        <span style="font-size:12px; color:var(--ink-500); margin-left:4px;">
                            <?php echo $executeDisabledReason ?: 'Only Owners can execute disbursements'; ?>
                        </span>
                        
                        <?php if ($isApprover): ?>
                        <div class="security-notice" style="margin-top:8px; width:100%;">
                            <span class="lock-icon">🔑</span>
                            <strong>Approver Notice:</strong> You have approved this batch. The disbursement will be executed by an 
                            <strong>Owner</strong> after final review. You do not have permission to disburse funds.
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
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
