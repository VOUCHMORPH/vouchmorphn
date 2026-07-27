<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use RuntimeException;

/**
 * USER MANAGEMENT SERVICE
 *
 * The HR side of an organization: who exists, what role they hold, and
 * which department (if any) that role is scoped to. Deliberately its own
 * class, same rationale as DepartmentService — this owns identity/access,
 * not payment rails or org structure, and keeping it separate means each
 * class stays reasoned-about independently.
 *
 * ROLE_CATALOG is the single source of truth for two different things at
 * once, on purpose: it's what enterprise/settings/users.php reads to show
 * a tailored explanation next to each role in the create/edit form, AND
 * it's what this class's own validation reads to decide whether a
 * department is required, optional, or irrelevant for a given role. If
 * the catalog and the validation ever disagreed, the UI would explain one
 * rule while the server enforced another — so there's exactly one array,
 * not two.
 *
 * department_mode meanings:
 *   - 'none'            : always organization-wide; any department_id
 *                          passed in is silently ignored.
 *   - 'required_exact'  : must have a department, checked by exact match
 *                          only (creator roles never cascade — see
 *                          DepartmentService's scoping note).
 *   - 'optional_scoped' : department_id may be null (org-wide, the
 *                          deliberate "sees everything" escalation) or a
 *                          specific department (scoped to it + every
 *                          descendant — see DepartmentService::
 *                          isDepartmentInScope).
 */
class UserManagementService
{
    private PDO $db;

    private const MANAGER_ROLES = ['owner', 'it_manager_enterprise', 'it_officer_enterprise'];

    public const ROLE_CATALOG = [
        'owner' => [
            'label' => 'Owner',
            'category' => 'Executive',
            'department_mode' => 'optional_scoped',
            'description' => 'The only role that can ever execute (disburse) an approved batch — hard rule, no exceptions. Leave department blank for an HQ-level account that can execute anywhere in the organization. Set a department to create a devolved disburser scoped to that department and everything under it (e.g. a provincial disbursement authority).',
        ],
        'it_manager_enterprise' => [
            'label' => 'IT Manager',
            'category' => 'Administration',
            'department_mode' => 'none',
            'description' => 'Always organization-wide. Creates departments/sub-departments, sets budget ceilings, confirms source accounts proposed by Finance, and manages other users. Cannot execute disbursements.',
        ],
        'it_officer_enterprise' => [
            'label' => 'IT Officer',
            'category' => 'Administration',
            'department_mode' => 'none',
            'description' => 'Organization-wide support role. Can manage users. Narrower than IT Manager — cannot confirm source accounts, set department ceilings, or execute disbursements.',
        ],
        'finance_officer' => [
            'label' => 'Finance Officer',
            'category' => 'Administration',
            'department_mode' => 'none',
            'description' => 'Organization-wide. Proposes source accounts (an Owner or IT Manager must confirm before use) and can approve or reject ration-borrow requests between departments. Cannot approve batches or execute disbursements.',
        ],
        'department_head' => [
            'label' => 'Department Head',
            'category' => 'Provincial / Departmental',
            'department_mode' => 'required_exact',
            'description' => 'Heads exactly one department. Can request new sub-departments and ration borrows on that department\'s behalf (both still need approval from a top role or Finance) and can build/submit batches against its budget. Assigning this role also sets that department\'s official head.',
        ],
        'program_officer' => [
            'label' => 'Uploader',
            'category' => 'Field / Operational',
            'department_mode' => 'required_exact',
            'description' => 'Builds and submits disbursement batches against one specific department\'s ration. Exact match only — never sees a parent or sibling department\'s batches, even if this department is nested under one.',
        ],
        'beneficiary_registrar' => [
            'label' => 'Beneficiary Registrar',
            'category' => 'Field / Operational',
            'department_mode' => 'required_exact',
            'description' => 'Adds citizen/recipient destinations to batches within one specific department. Never sees source accounts, and can\'t approve or execute anything.',
        ],
        'approver' => [
            'label' => 'Approver',
            'category' => 'Provincial / Departmental',
            'department_mode' => 'optional_scoped',
            'description' => 'Approves or rejects submitted batches. Leave department blank for an HQ-level approver with authority everywhere. Set a department to scope approval to that department and every sub-department beneath it. Can never execute — approval and disbursement are always different people, regardless of scope.',
        ],
        'senior_approver' => [
            'label' => 'Senior Approver',
            'category' => 'Provincial / Departmental',
            'department_mode' => 'optional_scoped',
            'description' => 'Same authority and scoping rule as Approver — typically used as the escalation tier for larger batches.',
        ],
        'auditor' => [
            'label' => 'Auditor',
            'category' => 'Oversight',
            'department_mode' => 'none',
            'description' => 'Read-only across the entire organization. Sees completed/executed batches for compliance review. Cannot create, approve, or execute anything.',
        ],
        'viewer' => [
            'label' => 'Viewer',
            'category' => 'Oversight',
            'department_mode' => 'none',
            'description' => 'Read-only across the entire organization — for external observers (e.g. a funding partner). Cannot create, approve, or execute anything.',
        ],
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ============================================================================
    // GUARDS
    // ============================================================================

    private function assertCanManageUsers(string $role): void
    {
        if (!in_array($role, self::MANAGER_ROLES, true)) {
            throw new RuntimeException("Only Owner, IT Manager, or IT Officer can manage users.");
        }
    }

    private function assertValidRole(string $role): void
    {
        if (!isset(self::ROLE_CATALOG[$role])) {
            throw new RuntimeException("Unknown role: {$role}");
        }
    }

    /**
     * Applies each role's department_mode. Throws if a creator role is
     * missing a required department; silently drops any department_id
     * passed in for a 'none' role rather than erroring, since the UI
     * shouldn't even show the field for those roles in the first place.
     */
    private function normalizeDepartmentForRole(string $role, ?int $departmentId): ?int
    {
        $mode = self::ROLE_CATALOG[$role]['department_mode'];

        if ($mode === 'none') {
            return null;
        }
        if ($mode === 'required_exact' && $departmentId === null) {
            throw new RuntimeException(self::ROLE_CATALOG[$role]['label'] . " requires a department to be assigned — this role never operates organization-wide.");
        }
        return $departmentId;
    }

    private function assertDepartmentBelongsToOrg(int $organizationId, int $departmentId): void
    {
        $stmt = $this->db->prepare("SELECT organization_id, status FROM departments WHERE id = :id");
        $stmt->execute([':id' => $departmentId]);
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dept || (int)$dept['organization_id'] !== $organizationId) {
            throw new RuntimeException("Department not found in this organization.");
        }
        if ($dept['status'] !== 'active') {
            throw new RuntimeException("Cannot assign a user to an inactive department.");
        }
    }

    /**
     * Refuses to leave an organization with zero active Owners — that
     * would mean nothing could ever be executed again until someone with
     * direct database access fixed it. Same category of safety check as
     * the execute-idempotency work: money-adjacent actions get a guard
     * even when it's inconvenient in the moment.
     */
    private function assertNotLastActiveOwner(int $organizationId, int $excludeUserId): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM organization_users
            WHERE organization_id = :org_id AND role = 'owner' AND is_active = true AND user_id <> :uid
        ");
        $stmt->execute([':org_id' => $organizationId, ':uid' => $excludeUserId]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw new RuntimeException("This is the organization's only active Owner. Create or reactivate another Owner before removing or demoting this one — otherwise no one could ever execute a disbursement.");
        }
    }

    /**
     * A department can have at most one active head at a time — assigning
     * a new one while the current head is still active would leave it
     * ambiguous who's actually authorized to request sub-departments/
     * borrows on the department's behalf (DepartmentService checks
     * head_user_id directly). Reassignment must be explicit.
     */
    private function assignAsDepartmentHead(int $departmentId, int $userId): void
    {
        $stmt = $this->db->prepare("SELECT head_user_id, organization_id FROM departments WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $departmentId]);
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);
        $current = $dept['head_user_id'] ?? null;

        if ($current !== null && (int)$current !== $userId) {
            $chk = $this->db->prepare("SELECT is_active FROM organization_users WHERE user_id = :uid AND organization_id = :org_id");
            $chk->execute([':uid' => $current, ':org_id' => $dept['organization_id']]);
            if ((bool)$chk->fetchColumn()) {
                throw new RuntimeException("This department already has an active head. Deactivate or reassign the current head first.");
            }
        }

        $stmt = $this->db->prepare("UPDATE departments SET head_user_id = :uid, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':uid' => $userId, ':id' => $departmentId]);
    }

    /**
     * organization_users.user_id is a required FK into the real, separate
     * `users` table (global identity — shared with the consumer app and
     * platform admins' underlying accounts). This looks up-or-creates the
     * matching users row for a given email, so organization_users always
     * has something valid to point at. Checks by email first rather than
     * assuming one doesn't exist, since the same person could plausibly
     * already have a users row (e.g. they use the consumer app too, or
     * they're being added to a second organization).
     *
     * users.role_id defaults to 1 ('user' — "Regular system user" in the
     * shared roles table also used by admins) since this person's actual
     * authority comes entirely from their organization_users.role, not
     * from anything on the global users row.
     *
     * $phone is required — users.phone is NOT NULL on this schema. Throws
     * a clear error rather than attempting the insert and letting it
     * crash with a raw constraint violation.
     */
    public function ensureGlobalUser(string $fullName, string $email, string $passwordHash, string $phone): int
    {
        $email = trim(strtolower($email));
        $phone = trim($phone);

        if ($phone === '') {
            throw new RuntimeException("A phone number is required to create this login — the underlying users table requires one.");
        }

        $stmt = $this->db->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int)$existing;
        }

        $usernameBase = preg_replace('/[^a-z0-9_]/', '', strtolower(explode('@', $email)[0]));
        if ($usernameBase === '') {
            $usernameBase = 'user';
        }
        $username = $usernameBase;
        $suffix = 0;
        while (true) {
            $stmt = $this->db->prepare("SELECT 1 FROM users WHERE username = :u");
            $stmt->execute([':u' => $username]);
            if (!$stmt->fetchColumn()) {
                break;
            }
            $suffix++;
            $username = $suffix < 20 ? ($usernameBase . $suffix) : ($usernameBase . '_' . bin2hex(random_bytes(3)));
        }

        $stmt = $this->db->prepare("
            INSERT INTO users (username, email, phone, password_hash, role_id, full_name, created_at, updated_at)
            VALUES (:username, :email, :phone, :hash, 1, :full_name, NOW(), NOW())
            RETURNING user_id
        ");
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':phone' => $phone,
            ':hash' => $passwordHash,
            ':full_name' => $fullName,
        ]);
        return (int)$stmt->fetchColumn();
    }

    private function generateTempPassword(): string
    {
        // Avoids visually ambiguous characters (0/O, 1/l/I) since this
        // gets read off a screen and typed in by hand at least once.
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $pw = '';
        for ($i = 0; $i < 12; $i++) {
            $pw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pw;
    }

    // ============================================================================
    // READ
    // ============================================================================

    public function listUsers(int $organizationId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.user_id, u.full_name, u.email, u.role, u.department_id, u.is_active,
                   u.must_change_password, u.created_at, u.deactivated_at,
                   d.name AS department_name
            FROM organization_users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.organization_id = :org_id
            ORDER BY u.is_active DESC, u.role ASC, u.full_name ASC
        ");
        $stmt->execute([':org_id' => $organizationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUser(int $organizationId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.*, d.name AS department_name
            FROM organization_users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.user_id = :uid AND u.organization_id = :org_id
        ");
        $stmt->execute([':uid' => $userId, ':org_id' => $organizationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ============================================================================
    // WRITE
    // ============================================================================

    /**
     * Returns ['user_id' => ..., 'temp_password' => ...]. The temp
     * password is only ever available here, at creation time — it's
     * stored only as a hash. Show it to the admin exactly once; if it's
     * lost, use resetPassword() to issue a new one rather than trying to
     * recover the original.
     */
    public function createUser(int $organizationId, array $data, int $createdBy, string $creatorRole): array
    {
        $this->assertCanManageUsers($creatorRole);

        $fullName = trim($data['full_name'] ?? '');
        $email = trim(strtolower($data['email'] ?? ''));
        $phone = trim($data['phone'] ?? '');
        $role = $data['role'] ?? '';
        $departmentId = !empty($data['department_id']) ? (int)$data['department_id'] : null;

        if ($fullName === '') {
            throw new RuntimeException("Full name is required.");
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("A valid email is required.");
        }
        if ($phone === '') {
            throw new RuntimeException("A phone number is required.");
        }
        $this->assertValidRole($role);
        $departmentId = $this->normalizeDepartmentForRole($role, $departmentId);
        if ($departmentId !== null) {
            $this->assertDepartmentBelongsToOrg($organizationId, $departmentId);
        }

        $stmt = $this->db->prepare("SELECT 1 FROM organization_users WHERE organization_id = :org_id AND email = :email");
        $stmt->execute([':org_id' => $organizationId, ':email' => $email]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException("A user with this email already exists in this organization.");
        }

        // ============================================================
        // PRACTICE/DEMO CONVENIENCE: if the caller explicitly supplies a
        // password (e.g. a UI checkbox for "use a fixed password for this
        // demo"), use it instead of generating a random one. Defaults to
        // the random generator whenever nothing is supplied — this is an
        // opt-in convenience for setting up a practice environment
        // quickly, never something to leave enabled for a real deployment
        // with real credentials.
        // ============================================================
        $tempPassword = !empty($data['password']) ? (string)$data['password'] : $this->generateTempPassword();
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

        $this->db->beginTransaction();
        try {
            // organization_users.user_id is a required FK into the real,
            // separate `users` table — this must exist before the INSERT
            // below can succeed at all.
            $globalUserId = $this->ensureGlobalUser($fullName, $email, $hash, $phone);

            $stmt = $this->db->prepare("
                INSERT INTO organization_users (
                    organization_id, user_id, department_id, full_name, email, password_hash,
                    role, is_active, must_change_password, created_by, created_at, updated_at
                ) VALUES (
                    :org_id, :global_user_id, :dept_id, :name, :email, :hash,
                    :role, true, true, :created_by, NOW(), NOW()
                ) RETURNING id
            ");
            $stmt->execute([
                ':org_id' => $organizationId,
                ':global_user_id' => $globalUserId,
                ':dept_id' => $departmentId,
                ':name' => $fullName,
                ':email' => $email,
                ':hash' => $hash,
                ':role' => $role,
                ':created_by' => $createdBy,
            ]);
            // organization_users.id — the membership row's own primary
            // key. Confirmed against the real login.php: session identity
            // (and therefore created_by/approved_by/head_user_id/etc.
            // throughout the rest of the app) is actually the GLOBAL
            // $globalUserId (users.user_id), not this value. This id is
            // kept only as 'org_user_id' below for anything that
            // genuinely needs the membership row itself.
            $orgUserRowId = (int)$stmt->fetchColumn();

            if ($role === 'department_head' && $departmentId !== null) {
                $this->assignAsDepartmentHead($departmentId, $globalUserId);
            }

            $this->db->commit();
            return ['user_id' => $globalUserId, 'org_user_id' => $orgUserRowId, 'temp_password' => $tempPassword];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateUserRole(
        int $organizationId,
        int $targetUserId,
        string $newRole,
        ?int $departmentId,
        int $updatedBy,
        string $updaterRole
    ): void {
        $this->assertCanManageUsers($updaterRole);
        $this->assertValidRole($newRole);
        $departmentId = $this->normalizeDepartmentForRole($newRole, $departmentId);

        $stmt = $this->db->prepare("SELECT role FROM organization_users WHERE user_id = :uid AND organization_id = :org_id");
        $stmt->execute([':uid' => $targetUserId, ':org_id' => $organizationId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new RuntimeException("User not found in this organization.");
        }
        if ($existing['role'] === 'owner' && $newRole !== 'owner') {
            $this->assertNotLastActiveOwner($organizationId, $targetUserId);
        }
        if ($departmentId !== null) {
            $this->assertDepartmentBelongsToOrg($organizationId, $departmentId);
        }

        $stmt = $this->db->prepare("
            UPDATE organization_users
            SET role = :role, department_id = :dept_id, updated_at = NOW()
            WHERE user_id = :uid AND organization_id = :org_id
        ");
        $stmt->execute([
            ':role' => $newRole,
            ':dept_id' => $departmentId,
            ':uid' => $targetUserId,
            ':org_id' => $organizationId,
        ]);

        if ($newRole === 'department_head' && $departmentId !== null) {
            $this->assignAsDepartmentHead($departmentId, $targetUserId);
        }
    }

    /**
     * Soft-delete only — matches the rest of the platform (batches are
     * never hard-deleted either). Deactivating preserves the audit trail
     * of who approved/created what while a now-departed employee held
     * that role.
     */
    public function setActive(int $organizationId, int $targetUserId, bool $active, int $updatedBy, string $updaterRole): void
    {
        $this->assertCanManageUsers($updaterRole);

        if (!$active) {
            if ($targetUserId === $updatedBy) {
                throw new RuntimeException("You can't deactivate your own account.");
            }
            $stmt = $this->db->prepare("SELECT role FROM organization_users WHERE user_id = :uid AND organization_id = :org_id");
            $stmt->execute([':uid' => $targetUserId, ':org_id' => $organizationId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing && $existing['role'] === 'owner') {
                $this->assertNotLastActiveOwner($organizationId, $targetUserId);
            }
        }

        $stmt = $this->db->prepare("
            UPDATE organization_users
            SET is_active = :active,
                deactivated_at = CASE WHEN :active2::boolean THEN NULL ELSE NOW() END,
                updated_at = NOW()
            WHERE user_id = :uid AND organization_id = :org_id
        ");
        $stmt->execute([
            ':active' => $active ? 't' : 'f',
            ':active2' => $active ? 't' : 'f',
            ':uid' => $targetUserId,
            ':org_id' => $organizationId,
        ]);
    }

    public function resetPassword(int $organizationId, int $targetUserId, int $updatedBy, string $updaterRole): string
    {
        $this->assertCanManageUsers($updaterRole);

        $tempPassword = $this->generateTempPassword();
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

        $stmt = $this->db->prepare("
            UPDATE organization_users
            SET password_hash = :hash, must_change_password = true, updated_at = NOW()
            WHERE user_id = :uid AND organization_id = :org_id
        ");
        $stmt->execute([':hash' => $hash, ':uid' => $targetUserId, ':org_id' => $organizationId]);

        return $tempPassword;
    }
}
