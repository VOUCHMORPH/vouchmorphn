<?php
/**
 * partials/money_guards.php
 *
 * Two controls the enterprise flow described but did not enforce:
 *
 * 1. SEGREGATED EXECUTION — the person who releases money must not be the
 *    person who prepared the batch, nor anyone who approved it. Before this,
 *    execute.php checked no permission at all: any logged-in user of the
 *    organisation, including a Viewer, could execute an approved batch.
 *
 * 2. DUAL CONTROL — approve.php set a batch to APPROVED on the FIRST
 *    approval, whatever the threshold said. Now a batch reaches APPROVED only
 *    when the number of distinct approvals meets approval_thresholds.
 *
 * Requires auth.php to be loaded first (requirePermission, getApprovalRequirement).
 */

declare(strict_types=1);

function vm_money_deny(string $message, bool $json): void
{
    header('HTTP/1.1 403 Forbidden');
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
    } else {
        echo '<!doctype html><meta charset="utf-8"><p style="font:16px/1.5 sans-serif;padding:32px">'
           . htmlspecialchars($message) . '</p>';
    }
    exit;
}

/** Distinct approvers who have approved this batch and not been superseded by a rejection. */
function vm_batch_approvers(PDO $db, int $batchId): array
{
    $stmt = $db->prepare(
        "SELECT approver_user_id FROM batch_approvals WHERE batch_id = :b AND decision = 'APPROVED'"
    );
    $stmt->execute([':b' => $batchId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function vm_batch_total(PDO $db, array $batch): float
{
    if (isset($batch['total_amount']) && $batch['total_amount'] !== null) {
        return (float)$batch['total_amount'];
    }
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM import_rows WHERE batch_id = :b AND validation_status = 'VALID'");
    $stmt->execute([':b' => (int)$batch['id']]);
    return (float)$stmt->fetchColumn();
}

/** How many distinct approvals this batch needs. */
function vm_required_approvals(PDO $db, array $batch): int
{
    $req = getApprovalRequirement($db, (int)($batch['department_id'] ?? 0), vm_batch_total($db, $batch));
    return max(1, (int)($req['required_count'] ?? 1));
}

/**
 * Call before showing the execute screen AND before executing.
 * Enforces: permission, segregation from preparer and approvers, dual control met.
 */
function vm_assert_can_execute(PDO $db, array $batch, array $user, bool $json = false): void
{
    if (!hasPermission('act_disburse')) {
        vm_money_deny('You do not have permission to release payments.', $json);
    }

    $me = (int)($user['id'] ?? $user['user_id'] ?? 0);
    if ($me === 0) {
        vm_money_deny('Your session is not valid. Sign in again.', $json);
    }

    if ((int)($batch['uploaded_by'] ?? 0) === $me) {
        vm_money_deny('Segregation of duties: you prepared this batch, so you cannot release it.', $json);
    }

    $approvers = vm_batch_approvers($db, (int)$batch['id']);
    if (in_array($me, $approvers, true) || (int)($batch['approved_by'] ?? 0) === $me) {
        vm_money_deny('Segregation of duties: you approved this batch, so you cannot also release it.', $json);
    }

    $needed = vm_required_approvals($db, $batch);
    if (count(array_unique($approvers)) < $needed) {
        vm_money_deny(sprintf('This batch needs %d independent approval%s before release; it has %d.',
            $needed, $needed === 1 ? '' : 's', count(array_unique($approvers))), $json);
    }
}

/**
 * Records one approval and decides whether the batch is now fully approved.
 * Returns ['status' => 'APPROVED'|'AWAITING_SECOND_APPROVAL', 'have' => n, 'need' => m].
 */
function vm_record_approval(PDO $db, array $batch, int $approverId): array
{
    // Worked out BEFORE the transaction: getApprovalRequirement() swallows its
    // own errors, and a failed query inside a transaction would abort it.
    $need = vm_required_approvals($db, $batch);

    $db->beginTransaction();
    try {
        // Lock the batch so two approvers pressing at once are counted correctly.
        $lock = $db->prepare('SELECT id FROM import_batches WHERE id = :b FOR UPDATE');
        $lock->execute([':b' => (int)$batch['id']]);

        $db->prepare(
            "INSERT INTO batch_approvals (batch_id, approver_user_id, decision, created_at)
             VALUES (:b, :a, 'APPROVED', NOW())
             ON CONFLICT (batch_id, approver_user_id) DO NOTHING"
        )->execute([':b' => (int)$batch['id'], ':a' => $approverId]);

        $have = count(array_unique(vm_batch_approvers($db, (int)$batch['id'])));
        $status = $have >= $need ? 'APPROVED' : 'AWAITING_SECOND_APPROVAL';

        if ($status === 'APPROVED') {
            $db->prepare("UPDATE import_batches SET status = 'APPROVED', approved_by = :a, approved_at = NOW() WHERE id = :b")
               ->execute([':a' => $approverId, ':b' => (int)$batch['id']]);
        } else {
            $db->prepare("UPDATE import_batches SET status = 'AWAITING_SECOND_APPROVAL' WHERE id = :b")
               ->execute([':b' => (int)$batch['id']]);
        }

        $db->commit();
        return ['status' => $status, 'have' => $have, 'need' => $need];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
