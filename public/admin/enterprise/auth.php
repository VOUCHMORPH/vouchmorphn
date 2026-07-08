<?php
// auth.php - Enterprise authentication helper
// Import the DBConnection class at the top level
require_once dirname(__DIR__, 3) . '/src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================================
// DATABASE CONNECTION
// FIXED: Use DBConnection::getConnection() as the single source of truth
// ============================================================================
function getDBConnection() {
    return DBConnection::getConnection(); // FIXED: was getInstance()
}

function requireEnterpriseAuth() {
    if (!isset($_SESSION['enterprise_user'])) {
        header('Location: /admin/enterprise/login.php');
        exit;
    }

    // Verify user still exists and is active
    try {
        $pdo = getDBConnection();
        $user = $_SESSION['enterprise_user'];

        $stmt = $pdo->prepare("
            SELECT ou.is_active, ou.department_id, ou.role, o.status as org_status
            FROM organization_users ou
            INNER JOIN organizations o ON ou.organization_id = o.id
            WHERE ou.id = :org_user_id
        ");
        $stmt->execute([':org_user_id' => $user['org_user_id'] ?? $user['id'] ?? null]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || !$result['is_active'] || $result['org_status'] !== 'ACTIVE') {
            session_destroy();
            header('Location: /admin/enterprise/login.php?error=Account+inactive');
            exit;
        }

        // Keep department_id AND role fresh in session in case either changed
        // since login (e.g. promoted to owner, or reassigned to a different
        // department) without forcing re-login.
        $_SESSION['enterprise_user']['department_id'] = $result['department_id'];
        $_SESSION['enterprise_user']['role'] = $result['role'];
    } catch (PDOException $e) {
        error_log("Auth verification error: " . $e->getMessage());
    }

    return $_SESSION['enterprise_user'];
}

/**
 * Permission check order:
 *   1. owner bypasses everything, org-wide
 *   2. per-user JSONB override in organization_users.permissions
 *   3. role's default permission set from organization_role_permissions
 *      (seeded by migration, not hardcoded here -- adjusting what a role can
 *      do doesn't require a code deploy)
 * Fails CLOSED on any DB error -- an unreadable permission table should never
 * silently grant access.
 */
function hasPermission($permission) {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return false;

    if (($user['role'] ?? '') === 'owner') return true;

    $overridePermissions = $user['permissions'] ?? [];
    if (in_array($permission, $overridePermissions) || in_array('*', $overridePermissions)) {
        return true;
    }

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM organization_role_permissions
            WHERE role_code = :role AND permission_code = :perm
        ");
        $stmt->execute([':role' => $user['role'] ?? '', ':perm' => $permission]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("hasPermission role-catalog lookup failed: " . $e->getMessage());
        return false; // fail closed, not open
    }
}

function requirePermission($permission) {
    if (!hasPermission($permission)) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied. You need permission: ' . htmlspecialchars($permission));
    }
}

/**
 * Department scoping: department-scoped roles (program_officer, approver,
 * senior_approver, beneficiary_registrar, viewer, department_head) can only
 * act on rows belonging to their own department_id. Organization-scoped
 * roles (owner, auditor) see everything.
 *
 * Returns null for org-wide scope (caller should NOT add a department filter),
 * or the department_id every query in this request should filter by.
 *
 * USAGE in any list/detail query:
 *   $deptScope = getUserDepartmentScope();
 *   $sql = "SELECT * FROM import_batches WHERE organization_id = :org_id";
 *   if ($deptScope !== null) { $sql .= " AND department_id = :dept_id"; }
 */
function getUserDepartmentScope(): ?int {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return null;

    $orgWideRoles = ['owner', 'auditor'];
    if (in_array($user['role'] ?? '', $orgWideRoles)) {
        return null;
    }

    return $user['department_id'] ?? null;
}

/**
 * Maker-checker enforcement: blocks a user from approving/rejecting a batch
 * they themselves uploaded. Call this from approve.php's approve AND reject
 * branches (not just approve) before taking any action.
 */
function assertNotSelfApproving($batchUploadedBy): void {
    $user = $_SESSION['enterprise_user'] ?? null;
    $currentUserId = $user['id'] ?? $user['user_id'] ?? null;

    if ($currentUserId !== null && $batchUploadedBy !== null && (int)$batchUploadedBy === (int)$currentUserId) {
        header('HTTP/1.1 403 Forbidden');
        die('Segregation of duties: you cannot approve or reject a batch you uploaded yourself. Ask another approver to review it.');
    }
}

/**
 * Dual control: given an amount and department, returns how many distinct
 * approvers are required (from approval_thresholds) and how many have
 * approved so far (from batch_approvals). Call before marking a batch fully
 * APPROVED -- if approvalsSoFar < requiredCount, keep it in a
 * PENDING_ADDITIONAL_APPROVAL state instead of flipping to APPROVED.
 */
function getApprovalRequirement(PDO $pdo, int $departmentId, float $amount): array {
    try {
        $stmt = $pdo->prepare("
            SELECT required_approver_count, required_role
            FROM approval_thresholds
            WHERE department_id = :dept_id
              AND min_amount <= :amount
              AND (max_amount IS NULL OR max_amount >= :amount)
            ORDER BY min_amount DESC
            LIMIT 1
        ");
        $stmt->execute([':dept_id' => $departmentId, ':amount' => $amount]);
        $threshold = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'required_count' => $threshold['required_approver_count'] ?? 1,
            'required_role' => $threshold['required_role'] ?? 'approver',
        ];
    } catch (PDOException $e) {
        error_log("getApprovalRequirement failed: " . $e->getMessage());
        return ['required_count' => 1, 'required_role' => 'approver']; // safe default
    }
}

/**
 * Get current user's role info
 */
function getCurrentUserRole(): ?array {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return null;
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT role_code, role_name, role_level, is_system_role
            FROM organization_roles
            WHERE role_code = :role
            LIMIT 1
        ");
        $stmt->execute([':role' => $user['role'] ?? '']);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getCurrentUserRole failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if current user has a specific role
 */
function hasRole(string $roleCode): bool {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return false;
    return strtolower($user['role'] ?? '') === strtolower($roleCode);
}

/**
 * Check if current user has any of the given roles
 */
function hasAnyRole(array $roleCodes): bool {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return false;
    $userRole = strtolower($user['role'] ?? '');
    foreach ($roleCodes as $role) {
        if (strtolower($role) === $userRole) {
            return true;
        }
    }
    return false;
}

function getOrganizationId() {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return null;
    return $user['organization_id'] ?? null;
}

function getCurrentUser() {
    return $_SESSION['enterprise_user'] ?? null;
}

function getOrganizationName() {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return null;
    return $user['organization_name'] ?? null;
}

function isAuthenticated() {
    return isset($_SESSION['enterprise_user']);
}

function logout() {
    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();
    header('Location: /admin/enterprise/login.php');
    exit;
}
