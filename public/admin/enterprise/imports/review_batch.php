<?php
// enterprise/imports/review_batch.php - Review and approve batch
require_once _DIR_ . '/../../auth.php';
$user = requireEnterpriseAuth();
require_once _DIR_ . '/../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// ============================================================
// LOAD SWAPSERVICE AND DEPENDENCIES
// ============================================================
require_once _DIR_ . '/../../../../vendor/autoload.php';
require_once _DIR_ . '/../../../../src/Domain/Services/SwapService.php';
require_once _DIR_ . '/../../../../src/Domain/Services/DepartmentService.php';
require_once _DIR_ . '/../../../../src/Domain/Services/UserManagementService.php';
require_once _DIR_ . '/../../../../src/Core/Config/LoadCountry.php';

use Domain\Services\SwapService;
use Domain\Services\DepartmentService;
use Domain\Services\UserManagementService;
use Core\Config\LoadCountry;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;
$batchId = $_GET['batch_id'] ?? 0;

$error = '';
$success = '';
$failedDestinations = [];

// How long a batch may sit in 'executing' before we treat it as stuck
// (crashed/timed-out request) rather than genuinely still running, and
// offer the Owner a manual Resume. See the P0 fix notes below the
// execute action for why resuming is now safe.
const EXECUTING_STUCK_THRESHOLD_SECONDS = 600; // 10 minutes

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

// A destination counts as "not yet attempted" only if it has no status
// or is still the default PENDING. Anything else (SUCCESS, COMPLETED,
// PENDING_IDENTITY_CONFIRMATION, FAILED) has already had a real attempt
// made against it and must never be silently re-run by Execute/Resume —
// FAILED ones are retried explicitly via retry_destination.php instead,
// exactly like the existing single-destination Retry button already
// does, so we don't invent a second, less careful retry path here.
function destinationNotYetAttempted($dest) {
    $s = strtoupper(trim((string)($dest['status'] ?? 'PENDING')));
    return $s === '' || $s === 'PENDING';
}

// ============================================================
// GET BATCH / DESTINATIONS (as functions so we can cheaply re-fetch
// after mutating actions, instead of trusting stale in-memory arrays)
// ============================================================
function loadBatch(PDO $db, $batchId, $orgId) {
    $stmt = $db->prepare("
        SELECT b.*, 
               u1.full_name as created_by_name,
               u2.full_name as submitted_by_name,
               u3.full_name as reviewed_by_name,
               u4.full_name as approved_by_name,
               u5.full_name as executed_by_name
        FROM disbursement_batches b
        LEFT JOIN organization_users u1 ON b.created_by = u1.id
        LEFT JOIN organization_users u2 ON b.submitted_by = u2.id
        LEFT JOIN organization_users u3 ON b.reviewed_by = u3.id
        LEFT JOIN organization_users u4 ON b.approved_by = u4.id
        LEFT JOIN organization_users u5 ON b.executed_by = u5.id
        WHERE b.id = :id AND b.organization_id = :org_id
    ");
    $stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function loadDestinations(PDO $db, $batchId): array {
    $stmt = $db->prepare("
        SELECT * FROM disbursement_destinations 
        WHERE batch_id = :batch_id
        ORDER BY destination_index
    ");
    $stmt->execute([':batch_id' => $batchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$batch = loadBatch($db, $batchId, $orgId);

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
$canSubmit = in_array($role, ['owner', 'program_officer', 'department_head']) && $canEdit;
$canApprove = in_array($role, ['approver', 'senior_approver']);

// Execute/DISBURSE: OWNER ONLY - NO EXCEPTIONS
$canExecute = ($role === 'owner');
if ($role === 'approver' || $role === 'senior_approver') {
    $canExecute = false;
    $canApprove = true;
}

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
// DEPARTMENT SCOPE — narrows approve/execute past the role check above.
// organization_users.department_id = NULL on an owner/approver/
// senior_approver account means that account is deliberately
// unrestricted (an HQ-level signatory) — this is the "some roles can
// see all departments" case. A department-scoped account of the same
// role can only approve/execute batches inside its own department or
// its sub-departments (ministry -> department -> sub-department). This
// never loosens anything the role check above already forbids — an
// approver still can never execute, regardless of scope — it only ever
// narrows further.
// ============================================================
$actingUserDepartmentId = $user['department_id'] ?? null;
$batchDepartmentId = $batch['department_id'] ?? null;
$inDeptScope = $deptService->isDepartmentInScope($actingUserDepartmentId, $batchDepartmentId);

if (!$inDeptScope) {
    if ($canApprove) {
        $canApprove = false;
    }
    if ($canExecute) {
        $canExecute = false;
    }
    $executeDisabledReason = $batchDepartmentId === null
        ? 'This batch has no department assigned, so a department-scoped account cannot act on it. Contact an admin to assign one.'
        : 'This batch belongs to a different department than the one assigned to your account.';
}

// ============================================================
// GET DESTINATIONS
// ============================================================
$destinations = loadDestinations($db, $batchId);

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

    if ($action === 'execute' && !$canExecute) {
        $error = "🚫 SECURITY BLOCK: You do not have permission to execute this disbursement. Only Owners can disburse funds.";
        error_log("[SECURITY] User " . ($userId ?? 'unknown') . " (role: $role) attempted to execute batch $batchId without permission");
    } elseif ($isReadOnly && !in_array($action, ['approve', 'reject', 'execute'])) {
        $error = "You cannot modify this batch. It was created by another user.";
    } else {
        if ($action === 'submit_for_approval') {
            // RATION CHECK — gates the draft -> pending_approval transition.
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
            }

        } elseif ($action === 'approve') {
            // RATION RE-CHECK at approval time too — ration is live.
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

        } elseif ($action === 'execute') {
            // ============================================================
            // P0 FIX — three problems, one block:
            //
            // 1. DOUBLE-CLICK / CONCURRENT EXECUTE: two requests hitting
            //    this action at once used to both run the full destination
            //    loop. Now the first thing we do is an atomic
            //    compare-and-set: flip status to 'executing' ONLY if it's
            //    currently 'approved' (normal case) or stuck in
            //    'executing' past the timeout threshold (resume case). If
            //    zero rows are affected, someone else already claimed it.
            //
            // 2. TIMEOUT MID-BATCH LEAVES THINGS STUCK: if PHP's execution
            //    time limit kills this request partway through, the batch
            //    used to stay 'approved' forever with no record of partial
            //    progress. Now a stuck 'executing' batch surfaces a manual
            //    "Resume" option once EXECUTING_STUCK_THRESHOLD_SECONDS has
            //    passed, and resuming is safe because of #3.
            //
            // 3. NOT IDEMPOTENT: nothing filtered out already-completed
            //    destinations before re-looping, and no idempotency_key
            //    was ever passed to SwapService. Fixed by filtering
            //    $destinations down to only never-attempted ones before
            //    building any payload, plus a deterministic idempotency
            //    key as defense-in-depth.
            // ============================================================
            error_log("=== REVIEW_BATCH DEBUG: EXECUTION STARTED ===");

            $claimStmt = $db->prepare("
                UPDATE disbursement_batches
                SET status = 'executing', updated_at = NOW()
                WHERE id = :id
                AND (
                    LOWER(status) = 'approved'
                    OR (LOWER(status) = 'executing' AND updated_at < NOW() - (:stuck_seconds || ' seconds')::interval)
                )
            ");
            $claimStmt->execute([':id' => $batchId, ':stuck_seconds' => EXECUTING_STUCK_THRESHOLD_SECONDS]);

            if ($claimStmt->rowCount() === 0) {
                $error = "This batch is currently being executed (or was already executed) by another request. Refresh the page to see its current status before trying again.";
                error_log("[review_batch] Execute claim failed for batch {$batchId} — already executing or not in an executable state");
            } else {
                error_log("[review_batch] Execute claim succeeded for batch {$batchId} — proceeding");

                $destinationsAtClaim = loadDestinations($db, $batchId);

                $identityDestinationsAll = [];
                $institutionDestinationsAll = [];
                foreach ($destinationsAtClaim as $dest) {
                    $isIdentity = ($dest['is_identity_recipient'] ?? false) || ($dest['institution'] ?? '') === 'IDENTITY_RECIPIENT';
                    if ($isIdentity) {
                        $identityDestinationsAll[] = $dest;
                    } else {
                        $institutionDestinationsAll[] = $dest;
                    }
                }

                // array_values() re-indexes sequentially — required because
                // the MULTI_DESTINATION result-matching below looks up
                // $institutionDestinations[$idx] assuming positional match
                // with what was sent in $multiPayload.
                $identityDestinations = array_values(array_filter($identityDestinationsAll, 'destinationNotYetAttempted'));
                $institutionDestinations = array_values(array_filter($institutionDestinationsAll, 'destinationNotYetAttempted'));

                $skippedAlreadyDone = (count($identityDestinationsAll) - count($identityDestinations))
                                    + (count($institutionDestinationsAll) - count($institutionDestinations));

                error_log("[review_batch] Identity destinations to process: " . count($identityDestinations) . " (skipped: " . (count($identityDestinationsAll) - count($identityDestinations)) . ")");
                error_log("[review_batch] Institution destinations to process: " . count($institutionDestinations) . " (skipped: " . (count($institutionDestinationsAll) - count($institutionDestinations)) . ")");

                $allResults = [];
                $overallSuccess = 0;
                $overallFailed = 0;
                $overallPending = 0;
                $failedDestinations = [];

                try {
                    $countryName = $_ENV['VOUCHMORPH_COUNTRY'] ?? getenv('VOUCHMORPH_COUNTRY') ?? 'Botswana';
                    $fullCountryConfig = LoadCountry::getConfig();

                    error_log("[review_batch] Initializing SwapService...");
                    error_log("[review_batch] Country: " . $countryName);

                    $swapService = new SwapService($db, $fullCountryConfig, $countryName);

                    error_log("[review_batch] SwapService initialized successfully");

                    foreach ($identityDestinations as $dest) {
                        error_log("[review_batch] Processing identity destination: " . json_encode($dest));

                        $identityPayload = [
                            'swap_type' => 'IDENTITY',
                            'reference' => $batch['batch_reference'] . '_ID_' . $dest['destination_index'],
                            // Deterministic per-destination key: safe to reuse across
                            // resumes since each identity destination is independent
                            // of what else is in the batch.
                            'idempotency_key' => $batch['batch_reference'] . '_ID_' . $dest['destination_index'],
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

                    if (!empty($institutionDestinations)) {
                        error_log("[review_batch] Processing " . count($institutionDestinations) . " institution destinations via MULTI_DESTINATION");

                        // Idempotency key scoped to the EXACT set of destination
                        // indices in THIS attempt, not just the batch reference —
                        // a static per-batch key would wrongly return attempt #1's
                        // cached (partial) result on a resume with a shrunk set.
                        $idxList = implode(',', array_column($institutionDestinations, 'destination_index'));
                        $multiIdempotencyKey = $batch['batch_reference'] . '_MULTI_' . substr(md5($idxList), 0, 12);

                        $multiPayload = [
                            'swap_type' => 'MULTI_DESTINATION',
                            'reference' => $batch['batch_reference'],
                            'idempotency_key' => $multiIdempotencyKey,
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

                        $allResults[] = ['type' => 'multi_destination', 'result' => $multiResult];
                    }

                    // Fold in whatever was already done on a PRIOR attempt so
                    // the final counts reflect the whole batch, not just what
                    // THIS request touched.
                    $priorSuccessCount = 0;
                    $priorPendingCount = 0;
                    foreach ($destinationsAtClaim as $d) {
                        if (!destinationNotYetAttempted($d)) {
                            $s = strtoupper($d['status'] ?? '');
                            if (in_array($s, ['SUCCESS', 'COMPLETED'], true)) $priorSuccessCount++;
                            if ($s === 'PENDING_IDENTITY_CONFIRMATION') $priorPendingCount++;
                        }
                    }
                    $overallSuccess += $priorSuccessCount;
                    $overallPending += $priorPendingCount;

                    $finalStatus = 'completed';
                    if ($overallFailed > 0 && ($overallSuccess > 0 || $overallPending > 0)) {
                        $finalStatus = 'partial_success';
                    } elseif ($overallFailed > 0 && $overallPending == 0 && $overallSuccess == 0) {
                        $finalStatus = 'failed';
                    } elseif ($overallPending > 0) {
                        $finalStatus = 'pending_identity_confirmation';
                    }

                    $isTerminal = in_array($finalStatus, ['completed', 'success', 'COMPLETED']);

                    error_log("[review_batch] Final status: $finalStatus (success: $overallSuccess, failed: $overallFailed, pending: $overallPending, skipped: $skippedAlreadyDone)");

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

                    // Ration bookkeeping: only the amount that actually moved on
                    // THIS attempt gets added — prior attempts' successful
                    // amounts were already added when they ran.
                    if (!empty($batch['department_id'])) {
                        try {
                            $successfulAmountThisAttempt = 0.0;
                            foreach ($allResults as $resultEntry) {
                                if (($resultEntry['type'] ?? '') === 'multi_destination') {
                                    foreach ($resultEntry['result']['destinations'] ?? [] as $idx => $destResult) {
                                        $st = $destResult['status'] ?? '';
                                        if ($st === 'SUCCESS' || $st === 'COMPLETED') {
                                            $origDest = $institutionDestinations[$idx] ?? null;
                                            if ($origDest) {
                                                $successfulAmountThisAttempt += (float)$origDest['amount'];
                                            }
                                        }
                                    }
                                }
                            }
                            if ($successfulAmountThisAttempt > 0) {
                                $stmt = $db->prepare("
                                    UPDATE departments
                                    SET amount_disbursed_ytd = amount_disbursed_ytd + :amt, updated_at = NOW()
                                    WHERE id = :id
                                ");
                                $stmt->execute([':amt' => $successfulAmountThisAttempt, ':id' => $batch['department_id']]);
                                error_log("[review_batch] Department {$batch['department_id']} amount_disbursed_ytd increased by {$successfulAmountThisAttempt}");
                            }
                        } catch (\Throwable $e) {
                            error_log("[review_batch] Failed to update department amount_disbursed_ytd: " . $e->getMessage());
                        }
                    }

                    $skippedNote = $skippedAlreadyDone > 0
                        ? " ({$skippedAlreadyDone} destination(s) already completed on a previous attempt were correctly skipped.)"
                        : '';

                    if ($finalStatus === 'completed') {
                        $success = "✅ All $overallSuccess destinations paid successfully!" . $skippedNote;
                    } elseif ($finalStatus === 'partial_success') {
                        $success = "⚠️ Batch partially executed.<br>";
                        $success .= "✅ <strong>$overallSuccess succeeded</strong><br>";
                        $success .= "❌ <strong>$overallFailed failed</strong><br>";
                        $success .= $skippedNote . "<br><br>";
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
                        $success = "Batch executed! $overallSuccess succeeded, $overallFailed failed, $overallPending pending identity confirmation." . $skippedNote;
                    }

                } catch (Exception $e) {
                    error_log("[review_batch] Execution error: " . $e->getMessage());
                    error_log("[review_batch] Trace: " . $e->getTraceAsString());

                    $stmt = $db->prepare("
                        UPDATE disbursement_destinations 
                        SET status = 'FAILED',
                            error_message = :error,
                            updated_at = NOW()
                        WHERE batch_id = :batch_id 
                        AND status NOT IN ('COMPLETED', 'SUCCESS', 'PENDING_IDENTITY_CONFIRMATION')
                    ");
                    $stmt->execute([
                        ':error' => 'System error: ' . $e->getMessage(),
                        ':batch_id' => $batchId
                    ]);

                    // FIX: the original code never reset disbursement_batches.status
                    // in this catch block, so a caught (non-timeout) exception left
                    // the batch stuck on 'executing' forever, same as an uncaught
                    // timeout would.
                    $recoveryStatus = $overallSuccess > 0 ? 'partial_success' : 'failed';
                    try {
                        $stmt = $db->prepare("
                            UPDATE disbursement_batches
                            SET status = :status, updated_at = NOW()
                            WHERE id = :id
                        ");
                        $stmt->execute([':status' => $recoveryStatus, ':id' => $batchId]);
                    } catch (\Throwable $inner) {
                        error_log("[review_batch] Failed to reset batch status after execution error: " . $inner->getMessage());
                    }

                    $error = "❌ Execution failed: " . $e->getMessage();
                }
            }
        }
    }

    // Refresh everything from DB before rendering — the executing-lock,
    // status transitions, and department bookkeeping above changed rows
    // out from under the snapshots loaded at the top of the page.
    $refreshedBatch = loadBatch($db, $batchId, $orgId);
    if ($refreshedBatch) {
        $batch = $refreshedBatch;
    }
    $destinations = loadDestinations($db, $batchId);

    if (!empty($batch['department_id'])) {
        try {
            $rationInfo = $deptService->getAvailableRation((int)$batch['department_id']);
        } catch (\Throwable $e) {
            error_log("[review_batch] Failed to refresh ration info: " . $e->getMessage());
        }
    }
}

$csrfToken = generateCsrfToken();
$roleDisplay = UserManagementService::ROLE_CATALOG[$role]['label'] ?? strtoupper($role);
$roleDisplay = strtoupper($roleDisplay);
$status = strtolower($batch['status'] ?? 'draft');

// ============================================================
// STUCK-EXECUTION DETECTION — drives the manual Resume affordance.
// This is a heuristic, not a guarantee: it can't distinguish "genuinely
// still running a very large batch" from "crashed 11 minutes ago"
// without a proper background-job architecture. It's a pragmatic
// mitigation for a synchronous-request execute path, not a replacement
// for one.
// ============================================================
$isStuckExecuting = false;
if ($status === 'executing') {
    $updatedAtTs = strtotime($batch['updated_at'] ?? $batch['created_at'] ?? 'now');
    $isStuckExecuting = (time() - $updatedAtTs) > EXECUTING_STUCK_THRESHOLD_SECONDS;
}

// FINAL SAFETY CHECK: Approvers should NEVER see Execute button
$showExecuteButton = ($status === 'approved' && $canExecute && !$isApprover);
$showResumeButton = ($status === 'executing' && $isStuckExecuting && $canExecute && !$isApprover);
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
        :root {
            --paper: #EEF1EF; --panel: #FFFFFF; --ink-900: #0F2138; --ink-700: #1D3557;
            --ink-500: #4A5A6E; --ink-300: #8A96A3; --line: #D3DAD6; --line-strong: #AEB8B2;
            --brass: #8A6D3B; --brass-tint: #F4EFE3; --seal-red: #7A2118; --amber: #8A5A0B;
            --ledger-green: #24513A; --green-tint: #E5EEE7; --blue-tint: #E7EEF4;
            --danger: #b3261e; --danger-bg: #fbeceb;
            --f-body: 'IBM Plex Sans', sans-serif; --f-cond: 'IBM Plex Sans Condensed', sans-serif; --f-mono: 'IBM Plex Mono', monospace;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); min-height: 100vh; font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        :focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }
        .masthead { background: var(--ink-900); color: #fff; padding: 14px 32px; display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid var(--brass); flex-wrap: wrap; gap: 10px; }
        .masthead h1 { font-family: var(--f-cond); font-size: 18px; font-weight: 700; letter-spacing: 0.04em; }
        .masthead .role-pill { font-size: 10px; font-weight: 700; color: var(--brass); border: 1px solid var(--brass); padding: 2px 10px; text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.05em; }
        .masthead .role-pill.approver { border-color: #f59e0b; color: #f59e0b; }
        .masthead .role-pill.owner { border-color: var(--ledger-green); color: var(--ledger-green); }
        .masthead .ref { color: var(--ink-300); font-size: 12px; margin-left: 12px; font-family: var(--f-mono); }
        .masthead .logout-link { color: rgba(255,255,255,0.4); text-decoration: none; margin-left: 16px; font-size: 11px; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: 0.04em; }
        .masthead .logout-link:hover { color: var(--brass); }
        .stage { max-width: 1200px; margin: 0 auto; padding: 28px 20px; }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--ink-500); text-decoration: none; font-size: 13px; font-weight: 600; margin-bottom: 20px; font-family: var(--f-cond); letter-spacing: 0.02em; }
        .back-link:hover { color: var(--brass); }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 28px; padding: 0 8px; }
        .step { flex: 1; text-align: center; font-size: 11px; font-weight: 600; color: var(--ink-300); text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.04em; }
        .step.active { color: var(--ink-900); }
        .step.done { color: var(--ledger-green); }
        .card { background: var(--panel); border: 1px solid var(--line); padding: 24px; margin-bottom: 20px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--line); flex-wrap: wrap; gap: 10px; }
        .card-title { font-size: 16px; font-weight: 700; font-family: var(--f-cond); letter-spacing: 0.02em; }
        .readonly-badge { display: inline-block; padding: 4px 12px; background: #fef3c7; color: var(--amber); font-size: 10px; font-weight: 600; text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.04em; border: 1px solid #f59e0b; }
        .workflow-status { padding: 4px 14px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; font-family: var(--f-cond); letter-spacing: 0.04em; }
        .status-draft { background: var(--line); color: var(--ink-500); }
        .status-pending_approval { background: #fef3c7; color: var(--amber); }
        .status-approved { background: var(--blue-tint); color: #1e40af; }
        .status-executing { background: #fef3c7; color: var(--amber); }
        .status-rejected { background: var(--danger-bg); color: var(--danger); }
        .status-completed { background: var(--green-tint); color: var(--ledger-green); }
        .status-failed { background: var(--danger-bg); color: var(--danger); }
        .status-pending_identity_confirmation { background: var(--blue-tint); color: #1e40af; }
        .status-partial_success { background: #fef3c7; color: var(--amber); }
        .status-success { background: var(--green-tint); color: var(--ledger-green); }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .ration-panel { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line); }
        .ration-panel .ration-title { font-family: var(--f-cond); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-500); margin-bottom: 10px; }
        .ration-bar-track { height: 8px; background: var(--paper); border: 1px solid var(--line); margin-bottom: 10px; }
        .ration-bar-fill { height: 100%; }
        .ration-stats { display: flex; gap: 20px; flex-wrap: wrap; font-size: 12.5px; color: var(--ink-500); }
        .ration-stats strong { color: var(--ink-900); }
        .ration-stats .danger strong { color: var(--danger); }
        .ration-stats .ok strong { color: var(--ledger-green); }
        .executing-notice { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 18px; margin-bottom: 16px; font-size: 13.5px; color: var(--amber); }
        .executing-notice strong { color: var(--amber); }
        .stuck-notice { background: var(--danger-bg); border-left: 4px solid var(--danger); padding: 14px 18px; margin-bottom: 16px; font-size: 13.5px; color: var(--danger); }
        .stuck-notice strong { color: var(--danger); }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: var(--paper); color: var(--ink-500); padding: 10px 14px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; border-bottom: 2px solid var(--line); font-family: var(--f-cond); }
        td { padding: 10px 14px; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 13px; }
        tr:hover { background: var(--brass-tint); }
        .table-code { font-family: var(--f-mono); font-size: 11px; background: var(--paper); padding: 2px 6px; }
        .error { background: var(--danger-bg); color: var(--danger); padding: 14px 18px; margin-bottom: 16px; border-left: 3px solid var(--danger); font-size: 14px; line-height: 1.6; }
        .success { background: var(--green-tint); color: var(--ledger-green); padding: 14px 18px; margin-bottom: 16px; border-left: 3px solid var(--ledger-green); font-size: 14px; line-height: 1.6; }
        .btn { padding: 8px 22px; border: none; font-weight: 600; font-size: 12px; cursor: pointer; transition: all 0.15s; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: 0.04em; }
        .btn:hover { opacity: 0.85; }
        .btn-primary { background: var(--ink-900); color: #fff; }
        .btn-primary:hover { background: var(--brass); color: var(--ink-900); }
        .btn-success { background: var(--ledger-green); color: #fff; }
        .btn-success:hover { background: #1a3d2c; }
        .btn-danger { background: var(--seal-red); color: #fff; }
        .btn-danger:hover { background: #5a1812; }
        .btn-secondary { background: var(--line); color: var(--ink-700); }
        .btn-secondary:hover { background: var(--line-strong); }
        .btn-outline { background: transparent; border: 1px solid var(--line); color: var(--ink-500); }
        .btn-outline:hover { border-color: var(--brass); color: var(--ink-900); background: var(--brass-tint); }
        .btn-execute { background: var(--seal-red); color: #fff; font-size: 14px; padding: 10px 32px; }
        .btn-execute:hover { background: #5a1812; }
        .btn-execute:disabled { opacity: 0.5; cursor: not-allowed; background: var(--ink-300); }
        .btn-resume { background: var(--amber); color: #fff; font-size: 13px; padding: 9px 26px; }
        .btn-resume:hover { background: #6e4800; }
        .btn-retry { background: var(--blue-tint); color: #1e40af; padding: 4px 14px; font-size: 11px; border: none; cursor: pointer; font-family: var(--f-cond); font-weight: 600; }
        .btn-retry:hover { background: #bfdbfe; }
        .btn-approver-locked { background: #fef3c7; color: var(--amber); border: 1px solid #f59e0b; padding: 8px 22px; font-weight: 600; font-size: 12px; cursor: not-allowed; font-family: var(--f-cond); text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; }
        .btn-approver-locked:hover { opacity: 0.7; }
        .actions-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .rejection-form { display: none; margin-top: 12px; padding: 16px; background: var(--danger-bg); }
        .rejection-form.show { display: block; }
        .rejection-form textarea { width: 100%; padding: 10px; border: 1px solid var(--line); min-height: 80px; font-family: var(--f-body); font-size: 13px; background: var(--panel); }
        .rejection-form textarea:focus { outline: 2px solid var(--brass); outline-offset: 1px; }
        .rejection-form .form-group { margin-bottom: 12px; }
        .rejection-form .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; font-family: var(--f-cond); color: var(--ink-500); }
        .security-notice { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; margin-top: 12px; font-size: 13px; color: var(--amber); }
        .security-notice strong { color: var(--amber); }
        .security-notice .lock-icon { font-size: 18px; margin-right: 8px; }
        .debug-panel { background: #1e293b; color: #e2e8f0; padding: 16px; overflow-x: auto; font-family: var(--f-mono); font-size: 12px; white-space: pre-wrap; word-break: break-all; margin-top: 12px; }
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
        @media (prefers-color-scheme: dark) {
            :root { --paper: #1B2733; --panel: #1B2733; --ink-900: #ECEFF2; --ink-700: #D5DCE0; --ink-500: #93A2AC; --ink-300: #6B7A85; --line: #2C3A45; }
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

    <div class="stage">
        <a href="add_destinations.php?batch_id=<?php echo $batchId; ?>" class="back-link">← Back to Destinations</a>

        <div class="step-indicator">
            <span class="step done">1. Select Source</span>
            <span class="step done">2. Add Destinations</span>
            <span class="step active">3. Review</span>
            <span class="step">4. Approve</span>
            <span class="step">5. Execute</span>
        </div>

        <?php if ($error): ?>
        <div class="error">⚠️ <?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="success">✅ <?php echo $success; ?></div>
        <?php endif; ?>

        <?php if ($status === 'executing' && !$isStuckExecuting): ?>
        <div class="executing-notice">
            ⏳ <strong>Execution in progress.</strong> Do not close this page or click Execute again — refresh in a
            moment to see the result. Large batches can take a while.
        </div>
        <?php elseif ($status === 'executing' && $isStuckExecuting): ?>
        <div class="stuck-notice">
            ⚠️ <strong>Execution appears stuck.</strong> It started more than <?php echo (int)(EXECUTING_STUCK_THRESHOLD_SECONDS / 60); ?> minutes ago
            and never finished — most likely a timed-out request, not a genuinely still-running one.
            <?php if ($canExecute): ?>
            It is safe to resume: destinations already paid on the earlier attempt are automatically skipped.
            <?php else: ?>
            Only the Owner can resume it.
            <?php endif; ?>
        </div>
        <?php endif; ?>

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

        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Batch Summary</span>
                <span>
                    <span class="workflow-status status-<?php echo $status; ?>"><?php echo htmlspecialchars($status); ?></span>
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
                <strong>Last Execution Attempt:</strong> <?php echo date('Y-m-d H:i', strtotime($batch['executed_at'])); ?>
                by <?php echo htmlspecialchars($batch['executed_by_name'] ?? 'N/A'); ?>
            </div>
            <?php endif; ?>

            <?php if (in_array($status, ['partial_success', 'failed', 'completed', 'executing'])): ?>
            <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--line);">
                <div style="display:flex; gap:24px; flex-wrap:wrap; font-size:14px;">
                    <div><strong style="color:var(--ledger-green);">✅ Successful:</strong> <?php echo $batch['successful_count'] ?? 0; ?></div>
                    <div><strong style="color:var(--danger);">❌ Failed:</strong> <?php echo $batch['failed_count'] ?? 0; ?></div>
                    <div><strong style="color:var(--amber);">⏳ Pending:</strong> <?php echo $batch['pending_count'] ?? 0; ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($rationInfo): ?>
            <?php
                $utilPct = $rationInfo['ceiling'] > 0
                    ? min(100, round((($rationInfo['disbursed_ytd'] + $rationInfo['reserved_in_flight']) / $rationInfo['ceiling']) * 100, 1))
                    : 0;
                $barColor = $rationInfo['available'] < 0 ? 'var(--danger)' : ($utilPct >= 80 ? 'var(--amber)' : 'var(--ledger-green)');
                $thisBatchFits = (float)($batch['total_amount'] ?? 0) <= $rationInfo['available'] || in_array($status, ['approved', 'completed', 'executing', 'partial_success']);
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
                            <th>#</th><th>Institution</th><th>Identifier</th><th>Amount</th>
                            <th>Beneficiary</th><th>Delivery</th><th>Status</th>
                            <th>Transaction Ref</th><th>Error Message</th>
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
                                <span class="workflow-status status-<?php echo $statusClass; ?>"><?php echo $displayStatus; ?></span>
                            </td>
                            <td>
                                <?php if (!empty($dest['transaction_reference'])): ?>
                                    <code class="table-code"><?php echo htmlspecialchars($dest['transaction_reference']); ?></code>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td style="color:var(--danger); font-size:12px; max-width:200px;">
                                <?php echo htmlspecialchars($dest['error_message'] ?? ''); ?>
                            </td>
                            <?php if ($role === 'owner' && in_array($status, ['partial_success', 'failed']) && strtolower($dest['status'] ?? '') === 'failed'): ?>
                            <td>
                                <a href="retry_destination.php?batch_id=<?php echo $batchId; ?>&dest_idx=<?php echo $dest['destination_index']; ?>" class="btn-retry">🔄 Retry</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">⚡ Actions</span>
                <?php if ($isReadOnly): ?>
                <span class="readonly-badge">🔒 Read-Only</span>
                <?php endif; ?>
                <?php if ($isApprover): ?>
                <span class="readonly-badge" style="background: #fef3c7; border-color: #f59e0b; color: var(--amber);">🔑 Approver Mode</span>
                <?php endif; ?>
            </div>
            <div class="actions-bar">
                <?php if ($status === 'draft' && $canSubmit && !$isReadOnly): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="submit_for_approval">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Submit this batch for approval?')">📤 Submit for Approval</button>
                </form>
                <?php endif; ?>

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
                <?php elseif ($status === 'pending_approval' && $isApprover && !$canApprove): ?>
                <button class="btn-approver-locked" disabled style="cursor:not-allowed;">🔒 OUTSIDE YOUR DEPARTMENT</button>
                <span style="font-size:12px; color:var(--ink-500); margin-left:4px;"><?php echo safeHtmlRb($executeDisabledReason); ?></span>
                <?php endif; ?>

                <?php if ($status === 'approved'): ?>
                    <?php if ($showExecuteButton): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ EXECUTE DISBURSEMENT: This will move real funds. Only proceed if you have verified all approvals. Continue?')">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="execute">
                        <button type="submit" class="btn btn-execute">🚀 EXECUTE DISBURSEMENT</button>
                    </form>
                    <?php else: ?>
                    <button class="btn-approver-locked" disabled style="cursor:not-allowed;">🔒 DISBURSEMENT LOCKED</button>
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

                <?php if ($showResumeButton): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Resume execution? Destinations already paid on the earlier attempt will be skipped automatically — only unpaid ones will be attempted.')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="execute">
                    <button type="submit" class="btn btn-resume">▶️ RESUME EXECUTION</button>
                </form>
                <?php elseif ($status === 'executing' && !$isStuckExecuting): ?>
                <button class="btn-approver-locked" disabled style="cursor:not-allowed;">⏳ EXECUTING…</button>
                <a href="review_batch.php?batch_id=<?php echo $batchId; ?>" class="btn btn-outline">🔄 Refresh Status</a>
                <?php endif; ?>

                <?php if ($status === 'draft' && $canEdit && !$isReadOnly): ?>
                <a href="add_destinations.php?batch_id=<?php echo $batchId; ?>" class="btn btn-secondary">✏️ Edit Destinations</a>
                <?php endif; ?>

                <a href="../batches/index.php" class="btn btn-outline">📋 All Batches</a>
                <a href="../index.php" class="btn btn-outline">🏠 Dashboard</a>
            </div>
        </div>

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
                &nbsp;&nbsp;Status: <span style="color: #4ade80;"><?php echo htmlspecialchars($dest['status'] ?? 'PENDING'); ?></span><br>
                <?php endforeach; ?>
            </div>
            <div style="margin-top: 14px;">
                <p style="color: var(--ink-500); font-size: 13px;">💡 Check the server logs for the full payload dump.</p>
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
