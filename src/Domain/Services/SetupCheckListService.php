<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;

/**
 * SetupChecklistService — computes whether an organization has completed
 * the minimum setup needed before it can safely create disbursement
 * batches, and drives the guided onboarding view on the Owner's first
 * landings on the dashboard.
 *
 * Deliberately does NOT hard-gate on "every department has a budget
 * ceiling set." Per the department-scoping design elsewhere in this
 * codebase, a department intentionally left with no ceiling ("no vote",
 * limited only by the live source account balance at execute time) is a
 * valid, deliberate choice — not an incomplete one. That's surfaced as a
 * nudge in the checklist, not a blocker.
 */
class SetupChecklistService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{
     *   steps: array<int, array{key:string,label:string,description:string,done:bool,count:int,action_label:string,action_href:string}>,
     *   ready_for_batches: bool,
     *   departments_without_budget: int
     * }
     */
    public function getStatus(int $organizationId): array
    {
        $staffCount = $this->countAdditionalStaff($organizationId);
        $departmentCount = $this->countDepartments($organizationId);
        $confirmedSourceCount = $this->countConfirmedSources($organizationId);
        $deptsWithoutBudget = $this->countDepartmentsWithoutBudget($organizationId);

        $steps = [
            [
                'key' => 'staff',
                'label' => 'Add your team',
                'description' => 'Create logins for the people who will approve, upload, or oversee disbursements — IT Manager, Finance Officer, Approvers, and anyone else with a role beyond yours.',
                'done' => $staffCount > 0,
                'count' => $staffCount,
                'action_label' => 'Manage Users',
                'action_href' => 'settings/users.php',
            ],
            [
                'key' => 'department',
                'label' => 'Create at least one department',
                'description' => 'Departments (provinces, ministries, programs — whatever fits your structure) are what disbursement batches are organized under, and where local staff and budgets are scoped.',
                'done' => $departmentCount > 0,
                'count' => $departmentCount,
                'action_label' => 'Manage Departments',
                'action_href' => 'departments/index.php',
            ],
            [
                'key' => 'source',
                'label' => 'Add and confirm a source account',
                'description' => 'The account, wallet, or card disbursements will actually be paid from. A Finance Officer proposes it; you or an IT Manager confirm it — never the same person, by design.',
                'done' => $confirmedSourceCount > 0,
                'count' => $confirmedSourceCount,
                'action_label' => 'Manage Source Accounts',
                'action_href' => 'imports/add_source.php',
            ],
        ];

        $allDone = true;
        foreach ($steps as $step) {
            if (!$step['done']) {
                $allDone = false;
                break;
            }
        }

        return [
            'steps' => $steps,
            'ready_for_batches' => $allDone,
            'departments_without_budget' => $deptsWithoutBudget,
        ];
    }

    private function countAdditionalStaff(int $organizationId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM organization_users
            WHERE organization_id = :org_id AND is_active = true AND role <> 'owner'
        ");
        $stmt->execute([':org_id' => $organizationId]);
        return (int)$stmt->fetchColumn();
    }

    private function countDepartments(int $organizationId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM departments
            WHERE organization_id = :org_id AND status = 'active'
        ");
        $stmt->execute([':org_id' => $organizationId]);
        return (int)$stmt->fetchColumn();
    }

    private function countConfirmedSources(int $organizationId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM source_accounts
            WHERE organization_id = :org_id AND status = 'active' AND deleted_at IS NULL
        ");
        $stmt->execute([':org_id' => $organizationId]);
        return (int)$stmt->fetchColumn();
    }

    private function countDepartmentsWithoutBudget(int $organizationId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM departments
            WHERE organization_id = :org_id AND status = 'active' AND budget_ceiling IS NULL
        ");
        $stmt->execute([':org_id' => $organizationId]);
        return (int)$stmt->fetchColumn();
    }
}
