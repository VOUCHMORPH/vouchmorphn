<?php
declare(strict_types=1);

/**
 * platform-admin/auth.php
 *
 * Auth against VouchMorph's REAL admins/roles tables — not a separate
 * invented table. admins.role_id -> roles.role_id, and each role carries
 * boolean capability flags (can_edit_config, can_manage_admins, etc.)
 * plus a permissions JSON array.
 *
 * Two guards are exposed, deliberately different in strength:
 *   - requirePlatformAdminAuth(): any authenticated, non-deleted,
 *     non-locked admin. Use for read-only platform-admin pages.
 *   - requirePlatformConfigAuth(): the above, PLUS the admin's role must
 *     have can_edit_config = true. Use for anything that changes system
 *     state — creating an organization is exactly this kind of action.
 *     Today that's role_id 2 (admin) and 999 (super_admin) only;
 *     REGULATOR/COMPLIANCE/AUDITOR/SUPPORT can log in and look, but
 *     can't create an organization, even though they're legitimate
 *     admin users.
 *
 * KNOWN GAP: mfa_enabled / mfa_secret exist on the admins table but are
 * NOT checked here. If any admin has MFA turned on, this login flow
 * currently bypasses it. Flagging this explicitly rather than silently
 * shipping a login that ignores a security column the schema clearly
 * intends to use — implementing real TOTP verification is a separate
 * piece of work from wiring up the tables that already exist.
 */

require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_name('vm_platform_admin_session');
    session_start();
}

const PLATFORM_ADMIN_MAX_FAILED_ATTEMPTS = 5;
const PLATFORM_ADMIN_LOCKOUT_MINUTES = 15;

function getPlatformAdminDb(): PDO {
    return DBConnection::getConnection();
}

function loadAdminWithRole(int $adminId): ?array {
    $db = getPlatformAdminDb();
    $stmt = $db->prepare("
        SELECT a.admin_id, a.username, a.email, a.full_name, a.role_id, a.deleted_at,
               a.locked_until, a.failed_login_attempts, a.mfa_enabled, a.country_code,
               r.role_name, r.role_level, r.can_manage_admins, r.can_view_transactions,
               r.can_edit_config, r.can_broadcast, r.can_trigger_cron, r.can_generate_reports,
               r.can_export_data, r.can_view_audit_logs, r.permissions
        FROM admins a
        JOIN roles r ON r.role_id = a.role_id
        WHERE a.admin_id = :id
    ");
    $stmt->execute([':id' => $adminId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Any authenticated admin. Re-checks deleted_at/locked_until on every
 * call (not just at login) — a deactivated or newly-locked admin's
 * existing session stops working immediately.
 */
function requirePlatformAdminAuth(): array {
    if (empty($_SESSION['admin_id'])) {
        header('Location: /platform-admin/login.php');
        exit;
    }

    $admin = loadAdminWithRole((int)$_SESSION['admin_id']);
    if (!$admin || $admin['deleted_at'] !== null) {
        session_unset();
        session_destroy();
        header('Location: /platform-admin/login.php');
        exit;
    }
    if (!empty($admin['locked_until']) && strtotime($admin['locked_until']) > time()) {
        session_unset();
        session_destroy();
        header('Location: /platform-admin/login.php?locked=1');
        exit;
    }

    return $admin;
}

/**
 * requirePlatformAdminAuth(), plus can_edit_config = true on the admin's
 * role. Use this, not the bare check above, for anything that creates or
 * changes platform-level state.
 */
function requirePlatformConfigAuth(): array {
    $admin = requirePlatformAdminAuth();
    if (empty($admin['can_edit_config'])) {
        http_response_code(403);
        die("Your role (" . htmlspecialchars($admin['role_name'], ENT_QUOTES, 'UTF-8') . ") doesn't have configuration-level access. Only 'admin' and 'super_admin' roles can create organizations.");
    }
    return $admin;
}

/**
 * Accepts username OR email. Enforces failed_login_attempts /
 * locked_until using the columns already on this table — increments on
 * failure, locks for PLATFORM_ADMIN_LOCKOUT_MINUTES after
 * PLATFORM_ADMIN_MAX_FAILED_ATTEMPTS, resets both on success.
 */
function attemptPlatformAdminLogin(string $usernameOrEmail, string $password): ?array {
    $db = getPlatformAdminDb();
    $identifier = trim($usernameOrEmail);

    $stmt = $db->prepare("
        SELECT admin_id, password_hash, deleted_at, locked_until, failed_login_attempts
        FROM admins
        WHERE (username = :ident OR email = :ident_lower) AND deleted_at IS NULL
    ");
    $stmt->execute([':ident' => $identifier, ':ident_lower' => strtolower($identifier)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }
    if (!empty($row['locked_until']) && strtotime($row['locked_until']) > time()) {
        return null;
    }

    if (!password_verify($password, $row['password_hash'])) {
        $newAttempts = (int)$row['failed_login_attempts'] + 1;
        $lockUntil = $newAttempts >= PLATFORM_ADMIN_MAX_FAILED_ATTEMPTS
            ? (new DateTime('+' . PLATFORM_ADMIN_LOCKOUT_MINUTES . ' minutes'))->format('Y-m-d H:i:s')
            : null;
        $upd = $db->prepare("
            UPDATE admins SET failed_login_attempts = :attempts, locked_until = :locked, updated_at = NOW()
            WHERE admin_id = :id
        ");
        $upd->execute([':attempts' => $newAttempts, ':locked' => $lockUntil, ':id' => $row['admin_id']]);
        return null;
    }

    $upd = $db->prepare("
        UPDATE admins
        SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW(),
            last_login_ip = :ip, updated_at = NOW()
        WHERE admin_id = :id
    ");
    $upd->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':id' => $row['admin_id']]);

    $_SESSION['admin_id'] = (int)$row['admin_id'];
    return loadAdminWithRole((int)$row['admin_id']);
}

function platformAdminLogout(): void {
    session_unset();
    session_destroy();
}

if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('requireCsrfToken')) {
    function requireCsrfToken(?string $token): void {
        if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            die('Invalid or missing CSRF token. Refresh the page and try again.');
        }
    }
}
