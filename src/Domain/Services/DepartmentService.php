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
            // NULL means "no vote assigned — unlimited locally, limited only
            // by the source account's real balance at execute time" (see
            // assertBatchFitsRation). Casting NULL to float would silently
            // become 0.0, which reads as "zero budget" — the exact opposite
            // of what NULL is meant to mean here, so it's checked explicitly.
            $hasCeiling = ($row['budget_ceiling'] !== null);
            $ceiling = $hasCeiling ? (float)$row['budget_ceiling'] : null;
            $disbursed = (float)$row['amount_disbursed_ytd'];
            $reserved = $reservedByDept[$deptId] ?? 0.0;
            $available = $hasCeiling ? ($ceiling - $disbursed - $reserved) : null;

            $row['budget_ceiling'] = $ceiling;
            $row['has_ceiling'] = $hasCeiling;
            $row['amount_disbursed_ytd'] = $disbursed;
            $row['reserved_in_flight'] = $reserved;
            $row['available'] = $available;
            $row['utilization_percent'] = ($hasCeiling && $ceiling > 0)
                ? round((($disbursed + $reserved) / $ceiling) * 100, 1)
                : 0.0;
            // A department with no vote can never be "over ration" locally —
            // there's no local number to be over. Its real constraint is
            // whatever the source account actually has, checked at execute
            // time, not tracked here.
            $row['is_over_ration'] = $hasCeiling && ($available < 0);
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

    // ============================================================================
    // DEPARTMENT SCOPING — who can see/act on which departments' batches
    // ============================================================================
    //
    // Ministry structures need more than one level of "is this the right
    // department": a province-level approver should be able to approve a
    // municipality's batch under them, not just their own exact department.
    // These three methods are the single place that logic lives, so every
    // page that gates batch visibility/actions by department (dashboard,
    // review page, batch detail page) uses the same rule.
    //
    // The convention throughout: organization_users.department_id = NULL
    // means "unrestricted for this role" — a deliberate configuration for
    // HQ-level owners/approvers who should see everything, not a default
    // to fall back on. Callers for CREATOR roles (program_officer,
    // department_head, beneficiary_registrar) should NOT treat a null
    // department_id as unrestricted — for those roles it's a data problem
    // (every batch creator should belong to exactly one department), and
    // should be denied access rather than granted org-wide reach. Only
    // oversight roles (owner, approver, senior_approver) get the "null
    // means everything" escalation.

    /**
     * All descendant department IDs under $departmentId (children,
     * grandchildren, etc.), NOT including $departmentId itself.
     */
    public function getDescendantDepartmentIds(int $departmentId): array
    {
        $stmt = $this->db->prepare("SELECT id, parent_department_id FROM departments WHERE status = 'active'");
        $stmt->execute();
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $childrenOf = [];
        foreach ($all as $row) {
            $pid = $row['parent_department_id'] !== null ? (int)$row['parent_department_id'] : null;
            if ($pid !== null) {
                $childrenOf[$pid][] = (int)$row['id'];
            }
        }

        $descendants = [];
        $queue = $childrenOf[$departmentId] ?? [];
        while (!empty($queue)) {
            $id = array_shift($queue);
            if (in_array($id, $descendants, true)) {
                continue; // guard against a malformed cycle in the data
            }
            $descendants[] = $id;
            foreach ($childrenOf[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }
        return $descendants;
    }

    /**
     * Walks parent_department_id upward from $departmentId to its
     * top-most ancestor — the "Main Central Government Account" for the
     * sub-department budget guardrails below (see
     * checkTransactionAgainstSpendingLimits / recordApprovedDepartmentSpend).
     * Returns $departmentId itself if it has no parent (it already IS a
     * root). Deliberately does not filter by status — this is used both
     * before a payout and after one has already happened, and the latter
     * must record money that has genuinely moved regardless of a
     * department's active/inactive flag. Bounded against a cyclic
     * parent_department_id in the data, same defensive posture as
     * getDescendantDepartmentIds.
     */
    private function getRootDepartmentId(int $departmentId): int
    {
        $currentId = $departmentId;
        for ($hops = 0; $hops < 20; $hops++) {
            $stmt = $this->db->prepare("SELECT parent_department_id FROM departments WHERE id = :id");
            $stmt->execute([':id' => $currentId]);
            $parentId = $stmt->fetchColumn();

            if ($parentId === false) {
                throw new RuntimeException("Department not found: {$currentId}");
            }
            if ($parentId === null) {
                return $currentId;
            }
            $currentId = (int)$parentId;
        }
        throw new RuntimeException("Department parent chain too deep or cyclic starting from {$departmentId}.");
    }

    /**
     * The set of department IDs a user "based" in $departmentId is
     * authorized to act across: themselves plus every descendant. Returns
     * NULL (not an array) to mean "unrestricted / every department in the
     * org" when $departmentId itself is null — see the scoping note above
     * this section for which roles that's a legitimate reading for.
     */
    public function getDepartmentScopeIds(?int $departmentId): ?array
    {
        if ($departmentId === null) {
            return null;
        }
        return array_merge([$departmentId], $this->getDescendantDepartmentIds($departmentId));
    }

    /**
     * True if $targetDepartmentId falls within the scope rooted at
     * $userDepartmentId (itself or any descendant). $userDepartmentId =
     * null means unrestricted. A $targetDepartmentId of null is always
     * OUT of scope for a scoped (non-null) user — a batch with a missing
     * department shouldn't become claimable by a scoped approver/disburser
     * just because the data is incomplete; that should surface as a data
     * problem on the batch, not silently grant access.
     */
    public function isDepartmentInScope(?int $userDepartmentId, ?int $targetDepartmentId): bool
    {
        if ($userDepartmentId === null) {
            return true;
        }
        if ($targetDepartmentId === null) {
            return false;
        }
        if ($userDepartmentId === $targetDepartmentId) {
            return true;
        }
        return in_array($targetDepartmentId, $this->getDescendantDepartmentIds($userDepartmentId), true);
    }

    /**
     * Sum of batch totals per department that are submitted/approved but
     * not yet disbursed (so not yet reflected in amount_disbursed_ytd).
     * This is what keeps two simultaneously-pending batches from both
     * passing the ration check and jointly blowing the ceiling.
     *
     * NOTE: once a batch moves to 'executing' (BatchExecutionQueueService::
     * enqueueBatch), it drops out of this sum even though its destinations
     * may still be actively paying out — a pre-existing gap. The
     * sub-department spending guardrails (checkTransactionAgainstSpendingLimits
     * below) inherit this gap rather than attempting to fix it here.
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

        $hasCeiling = ($dept['budget_ceiling'] !== null);
        $ceiling = $hasCeiling ? (float)$dept['budget_ceiling'] : null;
        $disbursed = (float)$dept['amount_disbursed_ytd'];
        $available = $hasCeiling ? ($ceiling - $disbursed - $reserved) : null;

        return [
            'department_id' => $departmentId,
            'has_ceiling' => $hasCeiling,
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

        // No vote assigned — this department deliberately has no local
        // ceiling; it's constrained only by the source account's real
        // balance, which is checked at execute time (SwapService/adapter
        // layer), not here. Trade-off worth remembering: a budget problem
        // on a no-vote department is caught late (at execute), not early
        // (at submit) — see the department-creation UI copy for this.
        if (!$ration['has_ceiling']) {
            return [
                'can_submit' => true,
                'requires_borrow' => false,
                'unlimited' => true,
                'ration' => $ration,
            ];
        }

        if ($batchTotal <= $ration['available']) {
            return [
                'can_submit' => true,
                'requires_borrow' => false,
                'unlimited' => false,
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
    // TRANSACTION-TIME SPENDING GUARDRAIL — call this right before a single
    // destination/transaction is actually paid out (not at batch submit
    // time; assertBatchFitsRation above already covers the whole-batch
    // check at submit/approve time). This is the later, per-transaction
    // check: does this ONE payout still fit both (a) the sub-department's
    // own remaining budget and (b) the Main Central Government Account's
    // (its root department's) remaining available balance, checked again
    // right before money moves.
    // ============================================================================

    /**
     * Pure check (no mutation, no lock) — can this one transaction go out
     * from $departmentId right now? Two parts:
     *   (a) does it fit inside the sub-department's OWN remaining budget
     *       (ceiling - disbursed_ytd - reserved_in_flight, via
     *       getAvailableRation)?
     *   (b) does it fit inside the Main Central Government Account's (the
     *       root department's) remaining available balance, same formula?
     * A department with no ceiling ("no vote") is exempt from its own
     * half of the check — same "unlimited locally, checked at execute
     * time by the source account" semantics as checkBatchAgainstRation.
     * The atomic, race-safe re-check happens AFTER the real payout, in
     * recordApprovedDepartmentSpend() — this method is deliberately cheap
     * and unlocked so it can run inline before every single transaction
     * without adding real contention.
     */
    public function checkTransactionAgainstSpendingLimits(int $departmentId, float $amount): array
    {
        $subRation = $this->getAvailableRation($departmentId);
        $rootId = $this->getRootDepartmentId($departmentId);
        $rootRation = ($rootId === $departmentId) ? $subRation : $this->getAvailableRation($rootId);

        if ($subRation['has_ceiling'] && $amount > $subRation['available']) {
            $shortfall = round($amount - $subRation['available'], 2);
            return [
                'can_proceed' => false,
                'reason' => 'sub_department_limit',
                'sub_department_ration' => $subRation,
                'root_ration' => $rootRation,
                'message' => "Transaction exceeds sub-department spending limit. This transaction ("
                    . number_format($amount, 2) . " {$subRation['currency']}) would push total spend past "
                    . "the department's ceiling of " . number_format($subRation['ceiling'], 2) . " {$subRation['currency']} "
                    . "(disbursed so far " . number_format($subRation['disbursed_ytd'], 2) . " {$subRation['currency']}, "
                    . number_format($subRation['reserved_in_flight'], 2) . " {$subRation['currency']} reserved by other "
                    . "pending batches; shortfall " . number_format($shortfall, 2) . " {$subRation['currency']}).",
            ];
        }

        if ($rootRation['has_ceiling'] && $amount > $rootRation['available']) {
            $shortfall = round($amount - $rootRation['available'], 2);
            return [
                'can_proceed' => false,
                'reason' => 'main_account_balance',
                'sub_department_ration' => $subRation,
                'root_ration' => $rootRation,
                'message' => "Transaction exceeds Main Central Government Account available balance. "
                    . "This transaction (" . number_format($amount, 2) . " {$rootRation['currency']}) exceeds "
                    . "the central account's remaining available balance of "
                    . number_format($rootRation['available'], 2) . " {$rootRation['currency']} "
                    . "(shortfall " . number_format($shortfall, 2) . " {$rootRation['currency']}).",
            ];
        }

        return ['can_proceed' => true, 'sub_department_ration' => $subRation, 'root_ration' => $rootRation];
    }

    /**
     * Convenience wrapper: throws if the transaction doesn't fit. Use this
     * at the actual payout point (worker.php, right before calling out to
     * SwapService) — a rejected transaction never reaches the external
     * adapter, so no real money moves for something already known to bust
     * either ledger.
     */
    public function assertTransactionFitsSpendingLimits(int $departmentId, float $amount): void
    {
        $result = $this->checkTransactionAgainstSpendingLimits($departmentId, $amount);
        if (!$result['can_proceed']) {
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
        ?float $budgetCeiling,
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
     *
     * This is also the enforcement point for the sub-department Budget
     * Allocation Guardrail: a sub-department's budget limit can never
     * exceed what's still available at the Main Central Government
     * Account level. A genuine sub-department's immediate parent IS the
     * root department in this codebase's model, so checking against the
     * immediate parent's raw ceiling here already IS checking against the
     * Main Account — no need to walk up via getRootDepartmentId() here.
     * Deliberately NOT also netting the parent's own amount_disbursed_ytd/
     * reserved_in_flight into this check on top of the sibling-ceiling
     * sum: once recordApprovedDepartmentSpend() rolls every
     * sub-department's spend up into the root's own amount_disbursed_ytd
     * (dual-write), doing so here too would double-count that spend —
     * once via a sibling's ceiling already being in the sum, again via
     * the root's disbursed total that now includes it — and would wrongly
     * reject valid allocations. Ceiling allocation is bounded purely by
     * ceiling sums; actual spend is bounded, per-department, by
     * assertTransactionFitsSpendingLimits() instead.
     */
    private function assertCeilingFitsUnderParent(int $parentId, ?float $newChildCeiling, ?int $excludeDepartmentId = null): void
    {
        $stmt = $this->db->prepare("SELECT budget_ceiling FROM departments WHERE id = :id AND status = 'active'");
        $stmt->execute([':id' => $parentId]);
        $parentCeilingRaw = $stmt->fetchColumn();
        if ($parentCeilingRaw === false) {
            throw new RuntimeException("Parent department not found or inactive.");
        }

        // Parent has no vote (unlimited) — nothing to fit under, any child
        // ceiling (including another unlimited one) is fine.
        if ($parentCeilingRaw === null) {
            return;
        }

        // Parent DOES have a real ceiling — a child can't claim "unlimited"
        // underneath it, since that would let it silently escape the
        // parent's actual cap. The child must have its own number here.
        if ($newChildCeiling === null) {
            throw new RuntimeException(
                "The parent department has a fixed budget ceiling, so this sub-department needs one too — "
                . "it can't be set to \"no vote\" underneath a department that does have one."
            );
        }

        $parentCeiling = (float)$parentCeilingRaw;

        $sql = "
            SELECT COALESCE(SUM(budget_ceiling), 0)
            FROM departments
            WHERE parent_department_id = :parent_id AND status = 'active' AND budget_ceiling IS NOT NULL
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
                "Budget allocation exceeds total available central funds. "
                . "Parent ceiling: " . number_format($parentCeiling, 2)
                . ", already allocated to sibling sub-departments: " . number_format($existingChildrenTotal, 2)
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
        ?float $budgetCeiling,
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
    public function updateDepartmentCeiling(int $departmentId, ?float $newCeiling, int $updatedBy, string $updaterRole): void
    {
        $this->assertTopRole($updaterRole, "change a department's ceiling");

        $stmt = $this->db->prepare("SELECT parent_department_id, amount_disbursed_ytd FROM departments WHERE id = :id AND status = 'active'");
        $stmt->execute([':id' => $departmentId]);
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dept) {
            throw new RuntimeException("Department not found or inactive.");
        }

        if ($newCeiling !== null && $newCeiling < (float)$dept['amount_disbursed_ytd']) {
            throw new RuntimeException(
                "New ceiling (" . number_format($newCeiling, 2) . ") can't be less than what's already "
                . "been disbursed this year (" . number_format((float)$dept['amount_disbursed_ytd'], 2) . ")."
            );
        }

        if ($dept['parent_department_id'] !== null) {
            $this->assertCeilingFitsUnderParent((int)$dept['parent_department_id'], $newCeiling, $departmentId);
        }

        // Switching an EXISTING department TO "no vote" is only safe if it
        // has no active sub-departments with their own real ceilings — those
        // ceilings were only ever validated against THIS department having a
        // number to fit under (assertCeilingFitsUnderParent). Removing that
        // number after the fact would leave the children's caps referencing
        // a parent constraint that no longer exists to have been checked
        // against, so that flip is blocked here rather than allowed silently.
        if ($newCeiling === null) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM departments
                WHERE parent_department_id = :id AND status = 'active' AND budget_ceiling IS NOT NULL
            ");
            $stmt->execute([':id' => $departmentId]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    "Can't switch this department to \"no vote\" while it has sub-departments with their own fixed ceilings — "
                    . "their caps were validated against this department's ceiling existing. Update or remove those first."
                );
            }
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
    // TRANSACTION-TIME SPENDING GUARDRAIL — EXECUTION & LEDGER (Rule 3)
    // ============================================================================

    /**
     * The atomic, locked ledger write that runs AFTER a payout has already
     * been sent by the external adapter (see worker.php). True atomicity
     * across "check budget -> call adapter -> record spend" is impossible
     * (the adapter call is a live network request and can't sit inside a
     * DB lock without stalling every other transaction against this
     * department). What this method guarantees instead: it closes the
     * race between concurrent workers at the LOCAL BOOKKEEPING level by
     * re-verifying and recording the spend under a row lock, atomically,
     * immediately after the money has already moved.
     *
     * Updates TWO rows in one transaction: the transacting department's
     * own amount_disbursed_ytd, AND its root department's ("Main Central
     * Government Account") amount_disbursed_ytd. This dual-write is what
     * keeps assertCeilingFitsUnderParent's sibling-ceiling-sum check
     * meaningful once sub-departments actually spend — see that method's
     * docblock. Scope note: only the transacting department and its
     * ultimate root are updated — an intermediate department in a
     * hierarchy deeper than two levels would NOT have its own
     * amount_disbursed_ytd reflect a descendant's spend. This matches the
     * two-tier "Main Account + Sub-Departments" model this feature was
     * built for; a full ancestor-chain rollup would be a larger change
     * than was asked for.
     *
     * Both rows are locked together (ORDER BY id ASC FOR UPDATE) so
     * concurrent calls acquire locks in a consistent order. In practice
     * this access pattern can't deadlock regardless: every call only ever
     * touches {one specific sub-department, the shared root} — never two
     * different sub-departments together — so two concurrent calls can
     * only ever contend on the shared root row itself (a wait, not a
     * cycle).
     *
     * Because the payout already happened and is irreversible, this NEVER
     * throws just because a department is now over budget — a race that
     * slips past the pre-check (checkTransactionAgainstSpendingLimits) is
     * recorded truthfully (the ledger must reflect money that genuinely
     * left) and flagged via organization_audit_logs for a human to
     * reconcile. It only throws for genuine failures (department row
     * missing, DB error) — those roll back and propagate, same as
     * approveBorrowRequest.
     *
     * @param array $auditContext Extra identifying info merged into the
     *        audit log's new_values if a race is detected — e.g. job_id,
     *        destination_id, batch_id, transaction_reference.
     * @return array{race_detected:bool, sub_department:array, root:array}
     */
    public function recordApprovedDepartmentSpend(
        int $departmentId,
        float $amount,
        ?int $actorUserId,
        array $auditContext = []
    ): array {
        $rootId = $this->getRootDepartmentId($departmentId);
        $ids = array_unique([$departmentId, $rootId]);
        sort($ids);

        $this->db->beginTransaction();
        try {
            $placeholders = [];
            $params = [];
            foreach ($ids as $i => $id) {
                $placeholders[] = ":id{$i}";
                $params[":id{$i}"] = $id;
            }
            $stmt = $this->db->prepare("
                SELECT id, organization_id, budget_ceiling, amount_disbursed_ytd
                FROM departments
                WHERE id IN (" . implode(',', $placeholders) . ")
                ORDER BY id ASC
                FOR UPDATE
            ");
            $stmt->execute($params);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byId[(int)$row['id']] = $row;
            }
            if (!isset($byId[$departmentId]) || !isset($byId[$rootId])) {
                throw new RuntimeException("Department or root department not found while recording spend (department {$departmentId}, root {$rootId}).");
            }

            $raceDetected = false;
            $results = [];
            foreach ($ids as $id) {
                $row = $byId[$id];
                $ceiling = $row['budget_ceiling'] !== null ? (float)$row['budget_ceiling'] : null;
                $disbursedBefore = (float)$row['amount_disbursed_ytd'];
                $disbursedAfter = $disbursedBefore + $amount;
                $overCeiling = ($ceiling !== null && $disbursedAfter > $ceiling);
                if ($overCeiling) {
                    $raceDetected = true;
                }

                $upd = $this->db->prepare("UPDATE departments SET amount_disbursed_ytd = :new_amt, updated_at = NOW() WHERE id = :id");
                $upd->execute([':new_amt' => $disbursedAfter, ':id' => $id]);

                $results[$id] = [
                    'department_id' => $id,
                    'is_root' => ($id === $rootId),
                    'ceiling' => $ceiling,
                    'disbursed_before' => $disbursedBefore,
                    'disbursed_after' => $disbursedAfter,
                    'over_ceiling' => $overCeiling,
                ];
            }

            if ($raceDetected) {
                $stmt = $this->db->prepare("
                    INSERT INTO organization_audit_logs (
                        organization_id, user_id, action, entity_type, entity_id,
                        new_values, ip_address, user_agent, created_at
                    ) VALUES (
                        :org_id, :user_id, 'BUDGET_OVERRUN_RACE_DETECTED', 'department', :entity_id,
                        :new_values, NULL, :ua, NOW()
                    )
                ");
                $stmt->execute([
                    ':org_id' => $byId[$departmentId]['organization_id'],
                    ':user_id' => $actorUserId,
                    ':entity_id' => $departmentId,
                    ':new_values' => json_encode([
                        'department_id' => $departmentId,
                        'root_department_id' => $rootId,
                        'amount' => $amount,
                        'sub_department' => $results[$departmentId],
                        'root' => $results[$rootId],
                        'context' => $auditContext,
                    ]),
                    ':ua' => 'cli:worker.php',
                ]);
                $this->log('warning', 'Budget overrun race detected — payout already executed, flagged for manual reconciliation', [
                    'department_id' => $departmentId, 'root_id' => $rootId, 'amount' => $amount,
                ]);
            }

            $this->db->commit();
            return ['race_detected' => $raceDetected, 'sub_department' => $results[$departmentId], 'root' => $results[$rootId]];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
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
