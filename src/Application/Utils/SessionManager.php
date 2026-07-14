<?php
declare(strict_types=1);

namespace Application\Utils;

/**
 * SessionManager
 * ---------------
 * Unified session management for VouchMorph.
 * Supports: users (from users table), admins (from admins table)
 * Roles come from roles table - NOT hardcoded.
 */
class SessionManager
{
    private const SESSION_LIFETIME = 86400; // 24 hours
    private const IDLE_TIMEOUT = 1800; // 30 minutes

    /**
     * Start session securely
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Security settings
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string)self::SESSION_LIFETIME);
        ini_set('session.cookie_lifetime', (string)self::SESSION_LIFETIME);
        
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');
        }

        session_start();

        // Check idle timeout
        if (self::isLoggedIn()) {
            $lastActivity = $_SESSION['_last_activity'] ?? 0;
            if (time() - $lastActivity > self::IDLE_TIMEOUT) {
                self::logout();
            }
        }
    }

    // ============================================================
    // CORE SESSION METHODS
    // ============================================================

    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function regenerateId(): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    // ============================================================
    // LOGIN METHODS
    // ============================================================

    /**
     * Login a user from the 'users' table
     * 
     * @param array $userData Must contain: user_id, username, email, phone, role_id
     *                        Optional: full_name, phone2, phone3, etc.
     *                        role_name and permissions should be joined from roles table
     */
    public static function loginUser(array $userData): void
    {
        self::start();
        self::regenerateId();

        $_SESSION['user'] = [
            'id' => $userData['user_id'],
            'username' => $userData['username'] ?? null,
            'full_name' => $userData['full_name'] ?? $userData['username'] ?? 'User',
            'phone' => $userData['phone'] ?? null,
            'phone2' => $userData['phone2'] ?? null,
            'phone3' => $userData['phone3'] ?? null,
            'email' => $userData['email'] ?? null,
            // Role data from roles table (joined in query)
            'role' => $userData['role_name'] ?? null,
            'role_id' => $userData['role_id'] ?? null,
            'role_level' => $userData['role_level'] ?? null,
            'permissions' => $userData['permissions'] ?? [],
            'verified' => $userData['verified'] ?? 0,
            'kyc_verified' => $userData['kyc_verified'] ?? 0,
            'wallet_uuid' => $userData['wallet_uuid'] ?? null,
            'country_code' => $userData['country_code'] ?? null,
        ];
        
        $_SESSION['_logged_in'] = true;
        $_SESSION['_login_time'] = time();
        $_SESSION['_last_activity'] = time();
        $_SESSION['_user_type'] = 'user';
        $_SESSION['_login_source'] = 'users';
    }

    /**
     * Login an admin from the 'admins' table
     * 
     * @param array $adminData Must contain: admin_id, username, email, role_id
     *                         Optional: full_name, phone, country_code
     *                         role_name and permissions should be joined from roles table
     */
    public static function loginAdmin(array $adminData): void
    {
        self::start();
        self::regenerateId();

        $_SESSION['user'] = [
            'id' => $adminData['admin_id'],
            'username' => $adminData['username'] ?? null,
            'full_name' => $adminData['full_name'] ?? $adminData['username'] ?? 'Admin',
            'phone' => $adminData['phone'] ?? null,
            'email' => $adminData['email'] ?? null,
            // Role data from roles table (joined in query)
            'role' => $adminData['role_name'] ?? null,
            'role_id' => $adminData['role_id'] ?? null,
            'role_level' => $adminData['role_level'] ?? null,
            'permissions' => $adminData['permissions'] ?? [],
            'country_code' => $adminData['country_code'] ?? null,
            'mfa_enabled' => $adminData['mfa_enabled'] ?? 0,
        ];
        
        $_SESSION['_logged_in'] = true;
        $_SESSION['_login_time'] = time();
        $_SESSION['_last_activity'] = time();
        $_SESSION['_user_type'] = 'admin';
        $_SESSION['_login_source'] = 'admins';
    }

    /**
     * Universal login - auto-detects if user or admin
     * Use this when you don't know which table the user came from
     */
    public static function login(array $userData): void
    {
        // Check if this is admin data (has admin_id instead of user_id)
        if (isset($userData['admin_id'])) {
            self::loginAdmin($userData);
        } else {
            self::loginUser($userData);
        }
    }

    // ============================================================
    // CHECK LOGIN STATUS
    // ============================================================

    public static function isLoggedIn(): bool
    {
        self::start();
        return isset($_SESSION['_logged_in']) && $_SESSION['_logged_in'] === true;
    }

    public static function user(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function userId(): ?int
    {
        $user = self::user();
        return $user['id'] ?? null;
    }

    public static function username(): ?string
    {
        $user = self::user();
        return $user['username'] ?? null;
    }

    public static function displayName(): string
    {
        $user = self::user();
        return $user['full_name'] ?? $user['username'] ?? 'User';
    }

    public static function phone(): ?string
    {
        $user = self::user();
        return $user['phone'] ?? null;
    }

    public static function phone2(): ?string
    {
        $user = self::user();
        return $user['phone2'] ?? null;
    }

    public static function phone3(): ?string
    {
        $user = self::user();
        return $user['phone3'] ?? null;
    }

    public static function email(): ?string
    {
        $user = self::user();
        return $user['email'] ?? null;
    }

    // ============================================================
    // ROLE METHODS (from roles table via JOIN)
    // ============================================================

    public static function role(): ?string
    {
        $user = self::user();
        return $user['role'] ?? null;
    }

    public static function roleId(): ?int
    {
        $user = self::user();
        return $user['role_id'] ?? null;
    }

    public static function roleLevel(): ?int
    {
        $user = self::user();
        return $user['role_level'] ?? null;
    }

    public static function permissions(): array
    {
        $user = self::user();
        $perms = $user['permissions'] ?? [];
        
        if (is_string($perms)) {
            $perms = json_decode($perms, true) ?? [];
        }
        
        return is_array($perms) ? $perms : [];
    }

    public static function hasPermission(string $permission): bool
    {
        $perms = self::permissions();
        return in_array($permission, $perms, true) || in_array('full_access', $perms, true);
    }

    /**
     * Check if user has any of the given roles
     */
    public static function hasRole(array|string $roles): bool
    {
        if (is_string($roles)) {
            $roles = [$roles];
        }
        
        $userRole = self::role();
        return in_array($userRole, $roles, true);
    }

    /**
     * Check specific role from roles table
     */
    public static function isRole(string $role): bool
    {
        return self::role() === $role;
    }

    // ============================================================
    // USER TYPE METHODS
    // ============================================================

    public static function userType(): string
    {
        self::start();
        return $_SESSION['_user_type'] ?? 'user';
    }

    public static function isAdmin(): bool
    {
        return self::userType() === 'admin';
    }

    public static function isUser(): bool
    {
        return self::userType() === 'user';
    }

    public static function loginSource(): string
    {
        self::start();
        return $_SESSION['_login_source'] ?? 'users';
    }

    // ============================================================
    // USER SPECIFIC (from users table)
    // ============================================================

    public static function isVerified(): bool
    {
        $user = self::user();
        return (bool)($user['verified'] ?? 0);
    }

    public static function isKycVerified(): bool
    {
        $user = self::user();
        return (bool)($user['kyc_verified'] ?? 0);
    }

    public static function walletUuid(): ?string
    {
        $user = self::user();
        return $user['wallet_uuid'] ?? null;
    }

    // ============================================================
    // ADMIN SPECIFIC (from admins table)
    // ============================================================

    public static function isMfaEnabled(): bool
    {
        $user = self::user();
        return (bool)($user['mfa_enabled'] ?? 0);
    }

    public static function countryCode(): ?string
    {
        $user = self::user();
        return $user['country_code'] ?? null;
    }

    // ============================================================
    // LOGOUT
    // ============================================================

    public static function logout(): void
    {
        self::start();
        
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 3600,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        session_destroy();
    }

    // ============================================================
    // FLASH MESSAGES
    // ============================================================

    public static function flash(string $key, string $message): void
    {
        self::start();
        $_SESSION['_flash'][$key] = $message;
    }

    public static function getFlash(string $key): ?string
    {
        self::start();
        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $message;
    }

    public static function hasFlash(string $key): bool
    {
        self::start();
        return isset($_SESSION['_flash'][$key]);
    }

    // ============================================================
    // SESSION REFRESH
    // ============================================================

    public static function refresh(): void
    {
        self::start();
        $_SESSION['_last_activity'] = time();
    }

    // ============================================================
    // REQUIRE LOGIN HELPERS
    // ============================================================

    public static function requireLogin(string $redirectTo = '/login.php'): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . $redirectTo);
            exit();
        }
    }

    public static function requireAdmin(string $redirectTo = '/admin/login.php'): void
    {
        if (!self::isLoggedIn() || !self::isAdmin()) {
            header('Location: ' . $redirectTo);
            exit();
        }
    }

    public static function requireUser(string $redirectTo = '/user/login.php'): void
    {
        if (!self::isLoggedIn() || !self::isUser()) {
            header('Location: ' . $redirectTo);
            exit();
        }
    }

    public static function requireRole(string|array $roles, string $redirectTo = '/login.php'): void
    {
        if (!self::isLoggedIn() || !self::hasRole($roles)) {
            header('Location: ' . $redirectTo);
            exit();
        }
    }

    public static function requirePermission(string $permission, string $redirectTo = '/login.php'): void
    {
        if (!self::isLoggedIn() || !self::hasPermission($permission)) {
            header('Location: ' . $redirectTo);
            exit();
        }
    }

    // ============================================================
    // GET LOGIN TIME
    // ============================================================

    public static function loginTime(): int
    {
        self::start();
        return $_SESSION['_login_time'] ?? 0;
    }

    public static function lastActivity(): int
    {
        self::start();
        return $_SESSION['_last_activity'] ?? 0;
    }

    // ==== BACKWARD-COMPATIBILITY ALIASES ====

    public static function isAdminLoggedIn(): bool
    {
        return self::isLoggedIn() && self::isAdmin();
    }

    public static function isUserLoggedIn(): bool
    {
        return self::isLoggedIn() && self::isUser();
    }

    public static function getAdminId(): ?int
    {
        return self::userId();
    }

    public static function getAdminUsername(): ?string
    {
        return self::username();
    }

    public static function getAdminRoleId(): ?int
    {
        return self::roleId();
    }

    public static function getAdminCountry(): ?string
    {
        return self::countryCode();
    }

    public static function getAdmin(): ?array
    {
        return self::getUser();
    }

    public static function getUser(): ?array
    {
        $u = self::user();
        if ($u === null) {
            return null;
        }
        $u['user_id']  = $u['user_id']  ?? $u['id'] ?? null;
        $u['admin_id'] = $u['admin_id'] ?? $u['id'] ?? null;
        return $u;
    }

    public static function getUserId(): ?int
    {
        return self::userId();
    }

    public static function setUser(array $userData): void
    {
        self::loginUser($userData);
    }

    public static function setAdmin(array $adminData): void
    {
        self::loginAdmin($adminData);
    }

    public static function logoutAdmin(): void
    {
        self::logout();
    }

    public static function logoutUser(): void
    {
        self::logout();
    }

    public static function destroy(): void
    {
        self::logout();
    }

}
