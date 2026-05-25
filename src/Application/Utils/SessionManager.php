<?php
declare(strict_types=1);

namespace Application\Utils;

/**
 * SessionManager
 * -----------------
 * Secure and centralized session control for VouchMorph SWAP System.
 * Supports both User and Admin sessions.
 */
class SessionManager
{
    /**
     * Start session securely (once).
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true,
            ]);
        }
    }

    // ============================================================
    // GENERIC SESSION METHODS (for both User and Admin)
    // ============================================================

    /**
     * Set a session value (generic key-value).
     */
    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Get a session value by key.
     */
    public static function get(string $key, $default = null)
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Remove a session key.
     */
    public static function remove(string $key): void
    {
        self::start();
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Check if a session key exists.
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Get all session data.
     */
    public static function getAll(): array
    {
        self::start();
        return $_SESSION;
    }

    /**
     * Clear all session data (but keep session active).
     */
    public static function clear(): void
    {
        self::start();
        $_SESSION = [];
    }

    /**
     * Regenerate session ID for security.
     */
    public static function regenerateId(): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    // ============================================================
    // USER SESSION METHODS (for frontend users)
    // ============================================================

    /**
     * Store logged-in user data in session.
     */
    public static function setUser(array $userData): void
    {
        self::start();
        $_SESSION['user'] = $userData;
        $_SESSION['user_logged_in'] = true;
        $_SESSION['logged_in'] = true; // Backward compatibility
    }

    /**
     * Retrieve current user session data.
     */
    public static function getUser(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    /**
     * Retrieve only username for display.
     */
    public static function getUserName(): string
    {
        $user = self::getUser();
        return $user['username'] ?? $user['full_name'] ?? $user['name'] ?? 'Unknown';
    }

    /**
     * Retrieve current user role.
     */
    public static function getUserRole(): ?string
    {
        $user = self::getUser();
        return $user['role'] ?? null;
    }

    /**
     * Get current user ID.
     */
    public static function getUserId(): ?int
    {
        self::start();
        return $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;
    }

    /**
     * Get current user phone.
     */
    public static function getUserPhone(): ?string
    {
        self::start();
        return $_SESSION['user_phone'] ?? $_SESSION['user']['phone'] ?? null;
    }

    /**
     * Check if regular user is logged in.
     */
    public static function isUserLoggedIn(): bool
    {
        self::start();
        return (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true)
            || (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);
    }

    // ============================================================
    // ADMIN SESSION METHODS (for backend administrators)
    // ============================================================

    /**
     * Store logged-in admin data in session.
     */
    public static function setAdmin(array $adminData): void
    {
        self::start();
        $_SESSION['admin'] = $adminData;
        $_SESSION['admin_id'] = $adminData['admin_id'] ?? $adminData['id'] ?? null;
        $_SESSION['admin_username'] = $adminData['username'] ?? null;
        $_SESSION['admin_email'] = $adminData['email'] ?? null;
        $_SESSION['admin_full_name'] = $adminData['full_name'] ?? null;
        $_SESSION['admin_role_id'] = $adminData['role_id'] ?? null;
        $_SESSION['admin_country'] = $adminData['country'] ?? $adminData['country_code'] ?? null;
        $_SESSION['admin_logged_in'] = true;
    }

    /**
     * Retrieve current admin session data.
     */
    public static function getAdmin(): ?array
    {
        self::start();
        return $_SESSION['admin'] ?? null;
    }

    /**
     * Get current admin ID.
     */
    public static function getAdminId(): ?int
    {
        self::start();
        return $_SESSION['admin_id'] ?? null;
    }

    /**
     * Get current admin username.
     */
    public static function getAdminUsername(): ?string
    {
        self::start();
        return $_SESSION['admin_username'] ?? null;
    }

    /**
     * Get current admin role ID.
     */
    public static function getAdminRoleId(): ?int
    {
        self::start();
        return $_SESSION['admin_role_id'] ?? null;
    }

    /**
     * Get current admin country.
     */
    public static function getAdminCountry(): ?string
    {
        self::start();
        return $_SESSION['admin_country'] ?? null;
    }

    /**
     * Check if admin is logged in.
     */
    public static function isAdminLoggedIn(): bool
    {
        self::start();
        return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    }

    /**
     * Check if MFA is pending for admin.
     */
    public static function isAdminMfaPending(): bool
    {
        self::start();
        return isset($_SESSION['admin_mfa_pending']) && $_SESSION['admin_mfa_pending'] === true;
    }

    /**
     * Set MFA pending flag.
     */
    public static function setAdminMfaPending(bool $pending = true): void
    {
        self::start();
        $_SESSION['admin_mfa_pending'] = $pending;
    }

    // ============================================================
    // COMPATIBILITY & LEGACY METHODS
    // ============================================================

    /**
     * Check if ANY user (regular or admin) is logged in.
     */
    public static function isLoggedIn(): bool
    {
        self::start();
        return self::isUserLoggedIn() || self::isAdminLoggedIn();
    }

    /**
     * Get current role (returns 'admin' or user role).
     */
    public static function getRole(): ?string
    {
        if (self::isAdminLoggedIn()) {
            return 'admin';
        }
        
        $user = self::getUser();
        return $user['role'] ?? null;
    }

    /**
     * Require login before allowing access (redirects to user login).
     */
    public static function requireLogin(string $redirectTo = 'login.php'): void
    {
        if (!self::isLoggedIn()) {
            header("Location: {$redirectTo}");
            exit();
        }
    }

    /**
     * Require admin login before allowing access.
     */
    public static function requireAdminLogin(string $redirectTo = 'admin_login.php'): void
    {
        if (!self::isAdminLoggedIn()) {
            header("Location: {$redirectTo}");
            exit();
        }
    }

    /**
     * Require user login before allowing access.
     */
    public static function requireUserLogin(string $redirectTo = 'user/login.php'): void
    {
        if (!self::isUserLoggedIn()) {
            header("Location: {$redirectTo}");
            exit();
        }
    }

    /**
     * End the session completely (logs out both user and admin).
     */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Logout user only (preserve admin session if exists).
     */
    public static function logoutUser(): void
    {
        self::start();
        unset($_SESSION['user']);
        unset($_SESSION['user_logged_in']);
        unset($_SESSION['user_id']);
        unset($_SESSION['user_phone']);
        unset($_SESSION['logged_in']);
    }

    /**
     * Logout admin only (preserve user session if exists).
     */
    public static function logoutAdmin(): void
    {
        self::start();
        unset($_SESSION['admin']);
        unset($_SESSION['admin_id']);
        unset($_SESSION['admin_username']);
        unset($_SESSION['admin_email']);
        unset($_SESSION['admin_full_name']);
        unset($_SESSION['admin_role_id']);
        unset($_SESSION['admin_country']);
        unset($_SESSION['admin_logged_in']);
        unset($_SESSION['admin_mfa_pending']);
    }
}
