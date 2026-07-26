<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use PDOException;
use RuntimeException;

/**
 * DEPARTMENT SERVICE
 *
 * Deliberately its own class, not folded into SwapService. It owns a
 * different domain (org structure / budget ceilings) than SwapService
 * (payment rails / holds / settlement) and doesn't touch adapters, holds,
 * or signed payloads at all. Keeping it separate means:
 *   - this file stays readable on its own
 *   - SwapService doesn't grow a department/ration-shaped hole in the
 *     middle of its swap-execution logic
 *   - the two can be tested and reasoned about independently
 *
 * ROLE MODEL THIS ENFORCES (mirrors enterprise/index.php):
 *   - Top roles (owner, it_manager_enterprise): create departments and
 *     sub-departments directly, set/change budget_ceiling, decide
 *     sub-department requests, approve/reject ration borrows.
 *   - finance_officer: also approves/rejects ration borrows (same as top
 *     roles) and can see the source-accounts area, but does NOT create
 *     departments or set ceilings.
 *   - department_head: requests sub-departments (goes to top roles for
 *     approval), requests ration borrows from other departments (goes to
 *     top/finance for approval), creates/submits batches against their
 *     own department's ration.
 *   - Batch staff (program_officer, department_head): builds and submits
 *     batches against their department's ration only. Never touches
 *     source accounts, ceilings, or borrow decisions.
 *
 * "Ration" here is your existing budget_ceiling / amount_disbursed_ytd
 * columns on `departments` — there is no separate rations table. See the
 * migration file (001_departments_ration_migration.sql) for the only
 * schema additions this class needs: parent_department_id on
 * departments, department_id on disbursement_batches, and two new
 * tables (ration_borrow_requests, sub_department_requests).
 */
class DepartmentService
{
    private PDO $db;
    private $logger;

    private const TOP_ROLES = ['owner', 'it_manager_enterprise'];
    private const BORROW_APPROVER_ROLES = ['owner', 'it_manager_enterprise', 'finance_officer'];

    public function __construct(PDO $db, $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->{$level}($message, $context);
        } else {
            error_log("[DepartmentService][{$level}] {$message} " . json_encode($context));
        }
    }

    // ============================================================================
    // READ: department tree + ration status
    // ============================================================================

    /**
     * Full department tree for an organization, each node annotated with
     * its ration status (ceiling, disbursed, reserved-in-flight, available).
     * Top-level departments have parent_department_id = null; children are
     * nested under `children`.
     */
    public function getDepartmentTree(int $organizationId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, organization_id, parent_department_id, name, code,
                   cost_center, budget_ceiling, amount_disbursed_ytd,
                   head_user_id, status, created_at, updated_at
            FROM departments
            WHERE organization_id = :org_id
            ORDER BY parent_department_id NULLS FIRST, name ASC
        ");
        $stmt->execute([':org_id' => $organizationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $reservedByDept = $this->getReservedAmountsByDepartment($organizationId);

        $byId = [];
        foreach ($rows as $row) {
            $deptId = (int)$row['id'];
            $ceiling = (float)$row['budget_ceiling'];
            $disbursed = (float)$row['amount_disbursed_ytd'];
            $reserved = $reservedByDept[$deptId] ?? 0.0;
            $available = $ceiling - $disbursed - $reserved;

            $row['budget_ceiling'] = $ceiling;
            $row['amount_disbursed_ytd'] = $disbursed;
            $row['reserved_in_flight'] = $reserved;
            $row['available'] = $available;
            $row['utilization_percent'] = $ceiling > 0
                ? round((($disbursed + $reserved) / $ceiling) * 100, 1)
                : 0.0;
            $row['is_over_ration'] = $available < 0;
            $row['children'] = [];

            $byId[$deptId] = $row;
        }

        $tree = [];
        foreach ($byId as $deptId => &$node) {
            $parentId = $node['parent_department_id'] !== null ? (int)$node['parent_department_id'] : null;
            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);

        return $tree;
    }

    /**
     * Flat list version (no tree nesting) — useful for dropdowns.
     */
    public function getDepartmentsFlat(int $organizationId, bool $activeOnly = true): array
    {
        $sql = "
            SELECT id, parent_department_id, name, code, budget_ceiling, amount_disbursed_ytd, status
            FROM departments
            WHERE organization_id = :org_id
        ";
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= " ORDER BY name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':org_id' => $organizationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sum of batch totals per department that are submitted/approved but
     * not yet disbursed (so not yet reflected in amount_disbursed_ytd).
     * This is what keeps two simultaneously-pending batches from both
     * passing the ration check and jointly blowing the ceiling.
     */
    private function getReservedAmountsByDepartment(int $organizationId): array
    {
        $stmt = $this->db->prepare("
            SELECT b.department_id, COALESCE(SUM(b.total_amount), 0) AS reserved
            FROM disbursement_batches b
            JOIN departments d ON d.id = b.department_id
            WHERE d.organization_id = :org_id
              AND b.department_id IS NOT NULL
              AND UPPER(b.status) IN ('PENDING', 'PENDING_APPROVAL', 'APPROVED')
            GROUP BY b.department_id
        ");
        $stmt->execute([':org_id' => $organizationId]);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$row['department_id']] = (float)$row['reserved'];
        }
        return $out;
    }

    /**
     * Ration snapshot for a single department. Used at batch-submit time
     * and anywhere else that needs a live number rather than the whole tree.
     */
    public function getAvailableRation(int $departmentId): array
    {
        $stmt = $this->db->prepare("
            SELECT d.id, d.organization_id, d.budget_ceiling, d.amount_disbursed_ytd,
                   d.status, o.default_currency
            FROM departments d
            JOIN organizations o ON o.id = d.organization_id
            WHERE d.id = :dept_id
        ");
        $stmt->execute([':dept_id' => $departmentId]);
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dept) {
            throw new RuntimeException("Department not found: {$departmentId}");
        }
        if ($dept['status'] !== 'active') {
            throw new RuntimeException("Department is not active (status: {$dept['status']}).");
        }

        $reservedMap = $this->getReservedAmountsByDepartment((int)$dept['organization_id']);
        $reserved = $reservedMap[$departmentId] ?? 0.0;

        $ceiling = (float)$dept['budget_ceiling'];
        $disbursed = (float)$dept['amount_disbursed_ytd'];
        $available = $ceiling - $disbursed - $reserved;

        return [
            'department_id' => $departmentId,
            'ceiling' => $ceiling,
            'disbursed_ytd' => $disbursed,
            'reserved_in_flight' => $reserved,
            'available' => $available,
            'currency' => $dept['default_currency'] ?? 'BWP',
        ];
    }

    // ============================================================================
    // BATCH RATION CHECK — call this wherever a batch is submitted for approval
    // ============================================================================

    /**
     * Checks whether a batch total fits inside its department's remaining
     * ration. Does NOT mutate anything — pure check, safe to call multiple
     * times (e.g. once on the form as a live preview, once again server-side
     * on actual submit).
     */
    public function checkBatchAgainstRation(int $departmentId, float $batchTotal): array
    {
        $ration = $this->getAvailableRation($departmentId);

        if ($batchTotal <= $ration['available']) {
            return [
                'can_submit' => true,
                'requires_borrow' => false,
                'ration' => $ration,
            ];
        }

        $shortfall = round($batchTotal - $ration['available'], 2);

        return [
            'can_submit' => false,
            'requires_borrow' => true,
            'shortfall' => $shortfall,
            'currency' => $ration['currency'],
            'ration' => $ration,
            'message' => "This batch (" . number_format($batchTotal, 2) . " {$ration['currency']}) exceeds "
                . "the department's remaining ration of " . number_format($ration['available'], 2) . " {$ration['currency']} "
                . "(ceiling " . number_format($ration['ceiling'], 2) . ", disbursed so far "
                . number_format($ration['disbursed_ytd'], 2) . ", "
                . number_format($ration['reserved_in_flight'], 2) . " already tied up in other pending/approved batches). "
                . "Request a ration borrow from another department, or reduce the batch by "
                . number_format($shortfall, 2) . " {$ration['currency']}.",
        ];
    }

    /**
     * Convenience wrapper: throws if the batch doesn't fit. Use this at the
     * actual submit endpoint (as opposed to checkBatchAgainstRation, which
     * you'd use for a live "can I submit this?" preview in the UI).
     */
    public function assertBatchFitsRation(int $departmentId, float $batchTotal): void
    {
        $result = $this->checkBatchAgainstRation($departmentId, $batchTotal);
        if (!$result['can_submit']) {
            throw new RuntimeException($result['message']);
        }
    }

    // ============================================================================
    // DEPARTMENT CREATION (top roles only) — direct, no approval hop
    // ============================================================================

    public function createDepartment(
        int $organizationId,
        string $name,
        ?string $code,
        ?string $costCenter,
        float $budgetCeiling,
        int $createdBy,
        string $creatorRole
    ): int {
        $this->assertTopRole($creatorRole, 'create a department');

        $stmt = $this->db->prepare("
            INSERT INTO departments (
                organization_id, parent_department_id, name, code, cost_center,
                budget_ceiling, amount_disbursed_ytd, head_user_id, status,
                created_at, updated_at
            ) VALUES (
                :org_id, NULL, :name, :code, :cost_center,
                :ceiling, 0, NULL, 'active', NOW(), NOW()
            ) RETURNING id
        ");
        $stmt->execute([
            ':org_id' => $organizationId,
            ':name' => $name,
            ':code' => $code,
            ':cost_center' => $costCenter,
            ':ceiling' => $budgetCeiling,
        ]);
        $id = (int)$stmt->fetchColumn();

        $this->log('info', 'Department created', ['id' => $id, 'org_id' => $organizationId, 'created_by' => $createdBy]);
        return $id;
    }

    /**
     * A sub-department's ceiling is carved out of its parent's — the sum of
     * all sibling ceilings under one parent can never exceed the parent's
     * own ceiling. This is the only structural constraint tying child
     * rations to the parent; it's enforced here in application code since
     * it's a cross-row check Postgres can't express as a simple CHECK.
     */
    private function assertCeilingFitsUnderParent(int $parentId, float $newChildCeiling, ?int $excludeDepartmentId = null): void
    {
        $stmt = $this->db->prepare("SELECT budget_ceiling FROM departments WHERE id = :id AND status = 'active'");
        $stmt->execute([':id' => $parentId]);
        $parentCeiling = $stmt->fetchColumn();
        if ($parentCeiling === false) {
            throw new RuntimeException("Parent department not found or inactive.");
        }
        $parentCeiling = (float)$parentCeiling;

        $sql = "
            SELECT COALESCE(SUM(budget_ceiling), 0)
            FROM departments
            WHERE parent_department_id = :parent_id AND status = 'active'
        ";
        $params = [':parent_id' => $parentId];
        if ($excludeDepartmentId !== null) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludeDepartmentId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $existingChildrenTotal = (float)$stmt->fetchColumn();

        if ($existingChildrenTotal + $newChildCeiling > $parentCeiling) {
            $remaining = max(0, $parentCeiling - $existingChildrenTotal);
            throw new RuntimeException(
                "Sub-department ceilings can't exceed the parent department's total ceiling. "
                . "Parent ceiling: " . number_format($parentCeiling, 2)
                . ", already allocated to sub-departments: " . number_format($existingChildrenTotal, 2)
                . ", room left: " . number_format($remaining, 2) . "."
            );
        }
    }

    /**
     * Top role creating a sub-department directly — immediate, no approval
     * step. (Compare requestSubDepartment() below, which is the
     * department-head path that requires a top-role decision.)
     */
    public function createSubDepartment(
        int $parentDepartmentId,
        string $name,
        ?string $code,
        ?string $costCenter,
        float $budgetCeiling,
        int $createdBy,
        string $creatorRole
    ): int {
        $this->assertTopRole($creatorRole, 'create a sub-department');
        $this->assertCeilingFitsUnderParent($parentDepartmentId, $budgetCeiling);

        $stmt = $this->db->prepare("
            INSERT INTO departments (
                organization_id, parent_department_id, name, code, cost_center,
                budget_ceiling, amount_disbursed_ytd, head_user_id, status,
                created_at, updated_at
            )
            SELECT organization_id, :parent_id, :name, :code, :cost_center,
                   :ceiling, 0, NULL, 'active', NOW(), NOW()
            FROM departments WHERE id = :parent_id
            RETURNING id
        ");
        $stmt->execute([
            ':parent_id' => $parentDepartmentId,
            ':name' => $name,
            ':code' => $code,
            ':cost_center' => $costCenter,
            ':ceiling' => $budgetCeiling,
        ]);
        $id = (int)$stmt->fetchColumn();

        $this->log('info', 'Sub-department created directly by top role', [
            'id' => $id, 'parent_id' => $parentDepartmentId, 'created_by' => $createdBy
        ]);
        return $id;
    }

    /**
     * Top role adjusting an existing department's (or sub-department's)
     * ceiling. If it's a sub-department, re-checks the parent-fit rule.
     */
    public function updateDepartmentCeiling(int $departmentId, float $newCeiling, int $updatedBy, string $updaterRole): void
    {
        $this->assertTopRole($updaterRole, "change a department's ceiling");

        $stmt = $this->db->prepare("SELECT parent_department_id, amount_disbursed_ytd FROM departments WHERE id = :id AND status = 'active'");
        $stmt->execute([':id' => $departmentId]);
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dept) {
            throw new RuntimeException("Department not found or inactive.");
        }

        if ($newCeiling < (float)$dept['amount_disbursed_ytd']) {
            throw new RuntimeException(
                "New ceiling (" . number_format($newCeiling, 2) . ") can't be less than what's already "
                . "been disbursed this year (" . number_format((float)$dept['amount_disbursed_ytd'], 2) . ")."
            );
        }

        if ($dept['parent_department_id'] !== null) {
            $this->assertCeilingFitsUnderParent((int)$dept['parent_department_id'], $newCeiling, $departmentId);
        }

        $stmt = $this->db->prepare("UPDATE departments SET budget_ceiling = :ceiling, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':ceiling' => $newCeiling, ':id' => $departmentId]);

        $this->log('info', 'Department ceiling updated', ['department_id' => $departmentId, 'new_ceiling' => $newCeiling, 'updated_by' => $updatedBy]);
    }

    // ============================================================================
    // SUB-DEPARTMENT REQUESTS (department-head path — needs top-role approval)
    // ============================================================================

    public function requestSubDepartment(
        int $organizationId,
        int $parentDepartmentId,
        string $proposedName,
        ?string $proposedCode,
        ?float $requestedCeiling,
        int $requestedBy,
        string $requesterRole
    ): int {
        if ($requesterRole !== 'department_head') {
            throw new RuntimeException("Only a department head can request a sub-department. Top roles should create one directly instead.");
        }

        $this->assertDepartmentHeadOwnsDepartment($parentDepartmentId, $requestedBy);

        $stmt = $this->db->prepare("
            INSERT INTO sub_department_requests (
                organization_id, parent_department_id, proposed_name, proposed_code,
                requested_ceiling, requested_by, status, created_at
            ) VALUES (
                :org_id, :parent_id, :name, :code, :ceiling, :by, 'pending', NOW()
            ) RETURNING id
        ");
        $stmt->execute([
            ':org_id' => $organizationId,
            ':parent_id' => $parentDepartmentId,
            ':name' => $proposedName,
            ':code' => $proposedCode,
            ':ceiling' => $requestedCeiling,
            ':by' => $requestedBy,
        ]);
        $id = (int)$stmt->fetchColumn();

        $this->log('info', 'Sub-department requested', ['request_id' => $id, 'parent_id' => $parentDepartmentId, 'requested_by' => $requestedBy]);
        return $id;
    }

    public function getPendingSubDepartmentRequests(int $organizationId): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, d.name AS parent_department_name
            FROM sub_department_requests r
            JOIN departments d ON d.id = r.parent_department_id
            WHERE r.organization_id = :org_id AND r.status = 'pending'
            ORDER BY r.created_at ASC
        ");
        $stmt->execute([':org_id' => $organizationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Top role approves or rejects a pending sub-department request. On
     * approval this creates the actual department row (reusing
     * createSubDepartment's ceiling-fit check) and links it back via
     * resulting_department_id.
     */
    public function decideSubDepartmentRequest(int $requestId, bool $approve, int $decidedBy, string $deciderRole): array
    {
        $this->assertTopRole($deciderRole, 'decide a sub-department request');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM sub_department_requests WHERE id = :id AND status = 'pending' FOR UPDATE");
            $stmt->execute([':id' => $requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                throw new RuntimeException("Request not found or already decided.");
            }

            $resultingId = null;
            if ($approve) {
                $ceiling = $req['requested_ceiling'] !== null ? (float)$req['requested_ceiling'] : 0.0;
                $this->assertCeilingFitsUnderParent((int)$req['parent_department_id'], $ceiling);

                $stmt = $this->db->prepare("
                    INSERT INTO departments (
                        organization_id, parent_department_id, name, code, cost_center,
                        budget_ceiling, amount_disbursed_ytd, head_user_id, status,
                        created_at, updated_at
                    ) VALUES (
                        :org_id, :parent_id, :name, :code, NULL,
                        :ceiling, 0, NULL, 'active', NOW(), NOW()
                    ) RETURNING id
                ");
                $stmt->execute([
                    ':org_id' => $req['organization_id'],
                    ':parent_id' => $req['parent_department_id'],
                    ':name' => $req['proposed_name'],
                    ':code' => $req['proposed_code'],
                    ':ceiling' => $ceiling,
                ]);
                $resultingId = (int)$stmt->fetchColumn();
            }

            $stmt = $this->db->prepare("
                UPDATE sub_department_requests
                SET status = :status, decided_by = :by, decided_at = NOW(), resulting_department_id = :rid
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $approve ? 'approved' : 'rejected',
                ':by' => $decidedBy,
                ':rid' => $resultingId,
                ':id' => $requestId,
            ]);

            $this->db->commit();
            $this->log('info', 'Sub-department request decided', ['request_id' => $requestId, 'approved' => $approve, 'resulting_department_id' => $resultingId]);

            return ['success' => true, 'approved' => $approve, 'department_id' => $resultingId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ============================================================================
    // RATION BORROWING
    // ============================================================================

    /**
     * Department head requests to borrow ration from another department.
     * Per your rule: the LENDING department has no veto — only top/finance
     * roles decide. This keeps it fast instead of turning into inter-
     * department negotiation.
     */
    public function requestBorrow(
        int $organizationId,
        int $borrowingDepartmentId,
        int $lendingDepartmentId,
        float $amount,
        string $reason,
        int $requestedBy,
        string $requesterRole
    ): int {
        if ($requesterRole !== 'department_head') {
            throw new RuntimeException("Only a department head can request a ration borrow.");
        }
        if ($borrowingDepartmentId === $lendingDepartmentId) {
            throw new RuntimeException("A department cannot borrow from itself.");
        }
        if ($amount <= 0) {
            throw new RuntimeException("Borrow amount must be greater than zero.");
        }

        $this->assertDepartmentHeadOwnsDepartment($borrowingDepartmentId, $requestedBy);

        $stmt = $this->db->prepare("
            INSERT INTO ration_borrow_requests (
                organization_id, borrowing_department_id, lending_department_id,
                amount, reason, requested_by, status, created_at
            ) VALUES (
                :org_id, :borrower, :lender, :amount, :reason, :by, 'pending', NOW()
            ) RETURNING id
        ");
        $stmt->execute([
            ':org_id' => $organizationId,
            ':borrower' => $borrowingDepartmentId,
            ':lender' => $lendingDepartmentId,
            ':amount' => $amount,
            ':reason' => $reason,
            ':by' => $requestedBy,
        ]);
        $id = (int)$stmt->fetchColumn();

        $this->log('info', 'Ration borrow requested', [
            'request_id' => $id, 'borrower' => $borrowingDepartmentId,
            'lender' => $lendingDepartmentId, 'amount' => $amount
        ]);
        return $id;
    }

    public function getPendingBorrowRequests(int $organizationId): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*,
                   bd.name AS borrowing_department_name,
                   ld.name AS lending_department_name
            FROM ration_borrow_requests r
            JOIN departments bd ON bd.id = r.borrowing_department_id
            JOIN departments ld ON ld.id = r.lending_department_id
            WHERE r.organization_id = :org_id AND r.status = 'pending'
            ORDER BY r.created_at ASC
        ");
        $stmt->execute([':org_id' => $organizationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Approving a borrow TRANSFERS budget_ceiling from lender to borrower
     * directly (no separate ledger/balance table — your departments table
     * is the single source of truth for ceiling). amount_disbursed_ytd is
     * untouched on both sides; this only moves headroom, not historical
     * spend.
     */
    public function approveBorrowRequest(int $requestId, int $approverUserId, string $approverRole): array
    {
        $this->assertBorrowApproverRole($approverRole);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM ration_borrow_requests WHERE id = :id AND status = 'pending' FOR UPDATE");
            $stmt->execute([':id' => $requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                throw new RuntimeException("Borrow request not found or already decided.");
            }

            $lenderRation = $this->getAvailableRation((int)$req['lending_department_id']);
            if ((float)$req['amount'] > $lenderRation['available']) {
                throw new RuntimeException(
                    "Lending department only has " . number_format($lenderRation['available'], 2)
                    . " {$lenderRation['currency']} spare right now — cannot lend "
                    . number_format((float)$req['amount'], 2) . "."
                );
            }

            $stmt = $this->db->prepare("
                UPDATE departments SET budget_ceiling = budget_ceiling - :amt, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':amt' => $req['amount'], ':id' => $req['lending_department_id']]);

            $stmt = $this->db->prepare("
                UPDATE departments SET budget_ceiling = budget_ceiling + :amt, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':amt' => $req['amount'], ':id' => $req['borrowing_department_id']]);

            $stmt = $this->db->prepare("
                UPDATE ration_borrow_requests
                SET status = 'approved', decided_by = :by, decided_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':by' => $approverUserId, ':id' => $requestId]);

            $this->db->commit();
            $this->log('info', 'Ration borrow approved', ['request_id' => $requestId, 'approved_by' => $approverUserId]);

            return ['success' => true, 'message' => 'Borrow approved. Ceiling transferred.'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function rejectBorrowRequest(int $requestId, int $approverUserId, string $approverRole, string $reason = ''): array
    {
        $this->assertBorrowApproverRole($approverRole);

        $stmt = $this->db->prepare("
            UPDATE ration_borrow_requests
            SET status = 'rejected', decided_by = :by, decided_at = NOW()
            WHERE id = :id AND status = 'pending'
        ");
        $stmt->execute([':by' => $approverUserId, ':id' => $requestId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException("Borrow request not found or already decided.");
        }

        $this->log('info', 'Ration borrow rejected', ['request_id' => $requestId, 'rejected_by' => $approverUserId, 'reason' => $reason]);
        return ['success' => true];
    }

    // ============================================================================
    // GUARDS
    // ============================================================================

    private function assertTopRole(string $role, string $action): void
    {
        if (!in_array($role, self::TOP_ROLES, true)) {
            throw new RuntimeException("Only top-level roles (Owner, IT Manager) can {$action}.");
        }
    }

    private function assertBorrowApproverRole(string $role): void
    {
        if (!in_array($role, self::BORROW_APPROVER_ROLES, true)) {
            throw new RuntimeException("Only top or finance roles can approve or reject ration borrow requests.");
        }
    }

    private function assertDepartmentHeadOwnsDepartment(int $departmentId, int $userId): void
    {
        $stmt = $this->db->prepare("SELECT head_user_id FROM departments WHERE id = :id AND status = 'active'");
        $stmt->execute([':id' => $departmentId]);
        $headUserId = $stmt->fetchColumn();

        if ($headUserId === false) {
            throw new RuntimeException("Department not found or inactive.");
        }
        if ((int)$headUserId !== $userId) {
            throw new RuntimeException("You can only act on behalf of your own department.");
        }
    }

    /**
     * Which department (if any) this user heads. Used by pages to scope
     * "my department" views for department_head role.
     */
    public function getDepartmentHeadedBy(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM departments WHERE head_user_id = :uid AND status = 'active' LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
