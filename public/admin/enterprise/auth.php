<?php
// auth.php - Enterprise authentication helper

if (session_status() === PHP_SESSION_NONE) {
    // These must be set BEFORE session_start()
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');   // Requires HTTPS
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Import the DBConnection class
require_once dirname(__DIR__, 3) . '/src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// ============================================================================
// DATABASE CONNECTION
// ============================================================================
function getDBConnection() {
    return DBConnection::getConnection();
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

        // ============================================================
        // FIX: org_status was compared with a case-sensitive !== 'ACTIVE',
        // but organizations.status is stored lowercase ('active'). login.php's
        // own initial query already had (and had this same bug fixed in) a
        // case-insensitive check; this re-validation on every subsequent
        // request did not, so it was undoing that fix immediately after
        // login — the account passed the login query, then failed this
        // check on the very next page load, every time, for every account.
        // ============================================================
        if (!$result || !$result['is_active'] || strtoupper((string)$result['org_status']) !== 'ACTIVE') {
            session_destroy();
            header('Location: /admin/enterprise/login.php?error=Account+inactive');
            exit;
        }

        // Keep department_id AND role fresh in session
        $_SESSION['enterprise_user']['department_id'] = $result['department_id'];
        $_SESSION['enterprise_user']['role'] = $result['role'];
    } catch (PDOException $e) {
        // Fail closed: if we cannot confirm the account is still active,
        // we do not let the request through on a stale session.
        error_log("Auth verification error: " . $e->getMessage());
        header('HTTP/1.1 503 Service Unavailable');
        exit('Unable to verify your session. Please try again shortly.');
    }

    // Role induction gate: nothing but induction, manual and sign-out until
    // the user has declared their induction at its current version.
    require_once __DIR__ . '/partials/induction_gate.php';
    enterpriseInductionGate($_SESSION['enterprise_user']);

    return $_SESSION['enterprise_user'];
}

/**
 * Permission check order:
 *   1. owner bypasses everything, org-wide
 *   2. per-user JSONB override in organization_users.permissions
 *   3. role's default permission set from organization_role_permissions
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
        return false;
    }
}

function requirePermission($permission) {
    if (!hasPermission($permission)) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied. You need permission: ' . htmlspecialchars($permission));
    }
}

/**
 * Department scoping: department-scoped roles can only act on their own department
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
 * Maker-checker enforcement: blocks self-approval
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
 * Dual control: returns required approver count for a given amount
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
        return ['required_count' => 1, 'required_role' => 'approver'];
    }
}

/**
 * CSRF protection
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfToken(?string $token): void {
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token. Refresh the page and try again.']);
        exit;
    }
}

/**
 * Role helper functions
 */
function getCurrentUserRole(): ?array {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return null;
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT role_code, role_name, role_level, is_system_role
            FROM organization_role_catalog
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

function hasRole(string $roleCode): bool {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return false;
    return strtolower($user['role'] ?? '') === strtolower($roleCode);
}

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
