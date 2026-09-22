<?php
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();   // was getInstance(), which does not exist: every call crashed
require_once __DIR__ . '/../partials/money_guards.php';
$orgId = getOrganizationId();

$data = json_decode(file_get_contents('php://input'), true);

// FIXED: CSRF check -- the frontend fetch() call must include csrf_token in
// the JSON body now (see review.php's updated submitForApproval()).
requireCsrfToken($data['csrf_token'] ?? null);

$batchId = $data['batch_id'] ?? 0;
$action = $data['action'] ?? '';

$stmt = $db->prepare("SELECT * FROM import_batches WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    echo json_encode(['success' => false, 'error' => 'Batch not found']);
    exit;
}

// FIXED: department scoping -- a department-scoped user (program_officer,
// approver, etc.) can only act on batches belonging to their own
// department. getUserDepartmentScope() returns null for org-wide roles
// (owner, auditor), in which case no filter applies.
$deptScope = getUserDepartmentScope();
if ($deptScope !== null && (int)($batch['department_id'] ?? 0) !== (int)$deptScope) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'This batch belongs to a different department than yours.']);
    exit;
}

function writeAuditLog(PDO $db, int $orgId, $userId, string $action, int $batchId, array $oldValues, array $newValues): void {
    try {
        $stmt = $db->prepare("
            INSERT INTO organization_audit_logs (
                organization_id, user_id, action, entity_type, entity_id,
                old_values, new_values, ip_address, user_agent, created_at
            ) VALUES (
                :org_id, :user_id, :action, 'import_batch', :entity_id,
                :old_values, :new_values, :ip, :ua, NOW()
            )
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':user_id' => $userId,
            ':action' => $action,
            ':entity_id' => $batchId,
            ':old_values' => json_encode($oldValues),
            ':new_values' => json_encode($newValues),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (PDOException $e) {
        error_log("[approve.php] Failed to write audit log: " . $e->getMessage());
    }
}

$currentUserId = $user['id'] ?? $user['user_id'] ?? null;

if ($action === 'submit') {
    // FIXED: was completely unauthenticated-by-permission before -- anyone
    // logged in could push any batch to READY_FOR_APPROVAL. Uploading and
    // submitting for approval are naturally the same actor (program_officer),
    // so this checks upload_batch rather than a separate submit permission.
    requirePermission('create_batch');   // one permission vocabulary across dashboard and endpoints

    $stmt = $db->prepare("
        UPDATE import_batches
        SET status = 'READY_FOR_APPROVAL', requires_approval = true
        WHERE id = :id
    ");
    $stmt->execute([':id' => $batchId]);

    writeAuditLog($db, $orgId, $currentUserId, 'BATCH_SUBMITTED', $batchId,
        ['status' => $batch['status']], ['status' => 'READY_FOR_APPROVAL']);

    echo json_encode(['success' => true]);

} elseif ($action === 'approve') {
    requirePermission('act_approve');
    assertNotSelfApproving($batch['uploaded_by']);

    if (!in_array($batch['status'], ['READY_FOR_APPROVAL', 'AWAITING_SECOND_APPROVAL'], true)) {
        echo json_encode(['success' => false, 'error' => "This batch is {$batch['status']} and cannot be approved."]);
        exit;
    }

    // Dual control, enforced: the batch becomes APPROVED only when the number
    // of distinct approvals meets approval_thresholds for its amount.
    $result = vm_record_approval($db, $batch, (int)$currentUserId);

    writeAuditLog($db, $orgId, $currentUserId, 'BATCH_APPROVED', $batchId,
        ['status' => $batch['status']],
        ['status' => $result['status'], 'approvals' => $result['have'], 'required' => $result['need']]);

    echo json_encode(['success' => true] + $result);

} elseif ($action === 'reject') {
    // FIXED: previously had NO permission check at all on reject.
    requirePermission('act_approve');

    // FIXED: maker-checker applies to rejection too -- an uploader
    // shouldn't be able to reject (and thus control the outcome of) their
    // own batch either.
    assertNotSelfApproving($batch['uploaded_by']);

    $reason = $data['reason'] ?? 'No reason provided';

    $stmt = $db->prepare("
        UPDATE import_batches
        SET status = 'REJECTED', rejection_reason = :reason
        WHERE id = :id
    ");
    $stmt->execute([':reason' => $reason, ':id' => $batchId]);

    try {
        $stmt = $db->prepare("
            INSERT INTO batch_approvals (batch_id, approver_user_id, decision, reason, created_at)
            VALUES (:batch_id, :approver_id, 'REJECTED', :reason, NOW())
            ON CONFLICT (batch_id, approver_user_id) DO NOTHING
        ");
        $stmt->execute([':batch_id' => $batchId, ':approver_id' => $currentUserId, ':reason' => $reason]);
    } catch (PDOException $e) {
        error_log("[approve.php] Failed to record batch_approvals: " . $e->getMessage());
    }

    writeAuditLog($db, $orgId, $currentUserId, 'BATCH_REJECTED', $batchId,
        ['status' => $batch['status']], ['status' => 'REJECTED', 'reason' => $reason]);

    echo json_encode(['success' => true]);

} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
