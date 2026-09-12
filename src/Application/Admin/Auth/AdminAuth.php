<?php
declare(strict_types=1);

namespace Application\Admin\Auth;

require_once __DIR__ . '/../../../Core/Database/CredentialsDBConnection.php';
require_once __DIR__ . '/../../../Infrastructure/Credentials/CredentialsRepository.php';

use Application\Utils\SessionManager;
use Infrastructure\Credentials\CredentialsRepository;
use PragmaRX\Google2FA\Google2FA;

class AdminAuth
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Increment failed login counter; lock account after threshold.
     * The counter and lock now live in the credentials database next to
     * the password_hash they protect, not on the main admins row.
     */
    private function recordFailedAttempt(int $adminId, int $currentCount): void
    {
        $maxAttempts = 5;
        $lockMinutes = 15;

        try {
            $result = CredentialsRepository::fromEnvironment()
                ->recordFailedAdminAttempt($adminId, $currentCount, $maxAttempts, $lockMinutes);
            if (!empty($result['locked_until'])) {
                error_log("[ADMIN AUTH] Account {$adminId} locked for {$lockMinutes} minutes after {$result['failed_login_attempts']} failed attempts");
            }
        } catch (\Throwable $e) {
            error_log("[ADMIN AUTH] Failed to record login attempt: " . $e->getMessage());
        }
    }

    /**
     * Clear failure counter and lock on successful login.
     */
    private function resetFailedAttempts(int $adminId): void
    {
        try {
            CredentialsRepository::fromEnvironment()->resetAdminFailedAttempts($adminId);
        } catch (\Throwable $e) {
            error_log("[ADMIN AUTH] Failed to reset login attempts: " . $e->getMessage());
        }
    }
    
    /**
     * Login admin user
     */
    public function login(string $username, string $password, string $country): array
    {
        try {
            error_log("[ADMIN AUTH] Login attempt for: {$username} in country: {$country}");

            // Identifier lookup (who is this) stays against the main DB.
            // Login secret + lockout state live in the separate
            // credentials database, fetched next by the admin_id this
            // resolves — that database never sees a username or email.
            $stmt = $this->db->prepare("
                SELECT
                    admin_id,
                    username,
                    email,
                    role_id,
                    mfa_enabled,
                    mfa_secret,
                    full_name,
                    country_code,
                    deleted_at
                FROM admins
                WHERE (username = :identifier OR email = :identifier)
                    AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([':identifier' => $username]);
            $admin = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$admin) {
                error_log("[ADMIN AUTH] User not found: {$username}");
                return ['success' => false, 'message' => 'Invalid username or password.'];
            }

            $credential = CredentialsRepository::fromEnvironment()->findAdminCredentialByAdminId((int)$admin['admin_id']);

            if (!$credential) {
                error_log("[ADMIN AUTH] No credential record for admin_id {$admin['admin_id']}");
                return ['success' => false, 'message' => 'Invalid username or password.'];
            }

            // --- ACCOUNT LOCKOUT CHECK ---
            if (!empty($credential['locked_until']) && strtotime($credential['locked_until']) > time()) {
                $unlockAt = date('H:i:s', strtotime($credential['locked_until']));
                error_log("[ADMIN AUTH] Account locked: {$username} until {$credential['locked_until']}");
                return ['success' => false, 'message' => "Account temporarily locked due to repeated failed attempts. Try again after {$unlockAt}."];
            }

            error_log("[ADMIN AUTH] User found: {$admin['username']}, Role ID: {$admin['role_id']}");

            // Verify password
            if (!password_verify($password, $credential['password_hash'])) {
                error_log("[ADMIN AUTH] Password verification failed for: {$username}");

                $this->recordFailedAttempt((int)$admin['admin_id'], (int)$credential['failed_login_attempts']);

                return ['success' => false, 'message' => 'Invalid username or password.'];
            }

            error_log("[ADMIN AUTH] Password verified successfully for: {$username}");

            // --- RESET FAILURE COUNTER ON SUCCESS ---
            $this->resetFailedAttempts((int)$admin['admin_id']);

            // Check country access - Super admin (role_id = 999) can access any country
            $isSuperAdmin = ($admin['role_id'] == 999);
            $hasCountryRestriction = !empty($admin['country_code']);
            $countryMatches = ($admin['country_code'] === $country);

            if (!$isSuperAdmin && $hasCountryRestriction && !$countryMatches) {
                error_log("[ADMIN AUTH] Country mismatch: {$username} tried {$country} but has {$admin['country_code']}");
                return ['success' => false, 'message' => 'You do not have access to this country\'s admin panel.'];
            }

            error_log("[ADMIN AUTH] Country check passed for: {$username}");

            // Update last login - NON-FATAL
            try {
                $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                
                // Clean IP: take first IP from comma-separated list
                if (strpos($ipAddress, ',') !== false) {
                    $ipParts = explode(',', $ipAddress);
                    $ipAddress = trim($ipParts[0]);
                }

                $checkColumns = $this->db->query("
                    SELECT column_name 
                    FROM information_schema.columns 
                    WHERE table_name = 'admins' 
                    AND column_name IN ('last_login_at', 'last_login_ip')
                ");
                $existingColumns = $checkColumns->fetchAll(\PDO::FETCH_COLUMN);

                if (in_array('last_login_at', $existingColumns) && in_array('last_login_ip', $existingColumns)) {
                    $updateStmt = $this->db->prepare("
                        UPDATE admins 
                        SET last_login_at = NOW(), 
                            last_login_ip = :ip 
                        WHERE admin_id = :admin_id
                    ");
                    $updateStmt->execute([
                        ':ip' => $ipAddress,
                        ':admin_id' => $admin['admin_id']
                    ]);
                    error_log("[ADMIN AUTH] Last login updated for: {$username}");
                } else {
                    error_log("[ADMIN AUTH] Last login columns missing, skipping update");
                }
            } catch (\Throwable $e) {
                error_log("[ADMIN AUTH] Last login update skipped (non-fatal): " . $e->getMessage());
            }

            // Clear any existing session data first
            SessionManager::remove('admin_id');
            SessionManager::remove('admin_username');
            SessionManager::remove('admin_email');
            SessionManager::remove('admin_full_name');
            SessionManager::remove('admin_role_id');
            SessionManager::remove('admin_country');
            SessionManager::remove('admin_logged_in');
            SessionManager::remove('admin_mfa_pending');

            // Store admin info in session
            SessionManager::set('admin_id', (int)$admin['admin_id']);
            SessionManager::set('admin_username', $admin['username']);
            SessionManager::set('admin_email', $admin['email']);
            SessionManager::set('admin_full_name', $admin['full_name']);
            SessionManager::set('admin_role_id', (int)$admin['role_id']);
            SessionManager::set('admin_country', $country);
            SessionManager::set('admin_logged_in', true);

            error_log("[ADMIN AUTH] Session data set for: {$username}");
            error_log("[ADMIN AUTH] Session admin_id: " . SessionManager::get('admin_id'));
            error_log("[ADMIN AUTH] Session logged_in: " . (SessionManager::get('admin_logged_in') ? 'true' : 'false'));

            // ============================================================
            // GET ROLE NAME FROM ROLES TABLE
            // ============================================================
            $roleName = null;
            try {
                $stmt = $this->db->prepare("
                    SELECT role_name FROM roles WHERE role_id = :role_id
                ");
                $stmt->execute([':role_id' => $admin['role_id']]);
                $role = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($role) {
                    $roleName = $role['role_name'];
                    error_log("[ADMIN AUTH] Retrieved role name: {$roleName} for role_id: {$admin['role_id']}");
                }
            } catch (\Throwable $e) {
                error_log("[ADMIN AUTH] Failed to retrieve role name: " . $e->getMessage());
            }

            // Check if MFA is enabled
            $mfaEnabled = ($admin['mfa_enabled'] === 't' || $admin['mfa_enabled'] === true || $admin['mfa_enabled'] === 1);

            if ($mfaEnabled && !empty($admin['mfa_secret'])) {
                SessionManager::set('admin_mfa_pending', true);
                error_log("[ADMIN AUTH] MFA required for {$username}");
                return [
                    'success' => true,
                    'mfa_required' => true,
                    'admin_id' => $admin['admin_id'],
                    'role_id' => (int)$admin['role_id'],
                    'role' => $roleName,           // FIXED: Added role name
                    'role_name' => $roleName,      // FIXED: Added role name
                    'message' => 'MFA verification required.'
                ];
            }

            error_log("[ADMIN AUTH] Login successful: {$username} (Role ID: {$admin['role_id']}, Role Name: {$roleName})");
            
            // ============================================================
            // FIXED: Return array now includes role, role_name, and role_id
            // ============================================================
            return [
                'success' => true,
                'message' => 'Login successful.',
                'admin_id' => (int)$admin['admin_id'],
                'role_id' => (int)$admin['role_id'],  // FIXED: Added role_id
                'role' => $roleName,                   // FIXED: Added role
                'role_name' => $roleName              // FIXED: Added role_name
            ];

        } catch (\Throwable $e) {
            error_log("[ADMIN AUTH] Login error: " . $e->getMessage());
            error_log("[ADMIN AUTH] Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }

    public function verifyMfa(string $code, string $country): array
    {
        try {
            $adminId = SessionManager::get('admin_id');

            if (!$adminId) {
                return ['success' => false, 'message' => 'Session expired. Please login again.'];
            }

            $stmt = $this->db->prepare("
                SELECT admin_id, username, mfa_secret, mfa_enabled
                FROM admins 
                WHERE admin_id = :id 
                    AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([':id' => $adminId]);
            $admin = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$admin) {
                return ['success' => false, 'message' => 'Admin account not found.'];
            }

            $mfaEnabled = ($admin['mfa_enabled'] === 't' || $admin['mfa_enabled'] === true || $admin['mfa_enabled'] === 1);

            if (!$mfaEnabled || empty($admin['mfa_secret'])) {
                SessionManager::remove('admin_mfa_pending');
                return ['success' => true, 'message' => 'MFA not required.'];
            }

            if (strlen($code) !== 6 || !ctype_digit($code)) {
                return ['success' => false, 'message' => 'Invalid authentication code.'];
            }

            $google2fa = new Google2FA();
            $valid = $google2fa->verifyKey($admin['mfa_secret'], $code, 1);

            if (!$valid) {
                error_log("[ADMIN AUTH] MFA code rejected for {$admin['username']}");
                return ['success' => false, 'message' => 'Invalid authentication code.'];
            }

            SessionManager::remove('admin_mfa_pending');
            error_log("[ADMIN AUTH] MFA verified for {$admin['username']}");
            return ['success' => true, 'message' => 'MFA verified successfully.'];

        } catch (\Throwable $e) {
            error_log("[ADMIN AUTH] MFA error: " . $e->getMessage());
            return ['success' => false, 'message' => 'MFA verification failed.'];
        }
    }

    /**
     * Check if admin is logged in
     */
    public static function isLoggedIn(): bool
    {
        $loggedIn = SessionManager::get('admin_logged_in') === true;
        $mfaPending = SessionManager::get('admin_mfa_pending') === true;

        return $loggedIn && !$mfaPending;
    }

    /**
     * Logout admin
     */
    public static function logout(): void
    {
        SessionManager::remove('admin_id');
        SessionManager::remove('admin_username');
        SessionManager::remove('admin_email');
        SessionManager::remove('admin_full_name');
        SessionManager::remove('admin_role_id');
        SessionManager::remove('admin_country');
        SessionManager::remove('admin_logged_in');
        SessionManager::remove('admin_mfa_pending');
        SessionManager::destroy();
    }

    /**
     * Get current admin ID
     */
    public static function getCurrentAdminId(): ?int
    {
        return SessionManager::get('admin_id');
    }

    /**
     * Get current admin role ID
     */
    public static function getCurrentRoleId(): ?int
    {
        return SessionManager::get('admin_role_id');
    }

    /**
     * Get current admin info
     */
    public static function getCurrentAdmin(): ?array
    {
        return [
            'id' => SessionManager::get('admin_id'),
            'username' => SessionManager::get('admin_username'),
            'email' => SessionManager::get('admin_email'),
            'full_name' => SessionManager::get('admin_full_name'),
            'role_id' => SessionManager::get('admin_role_id'),
            'country' => SessionManager::get('admin_country')
        ];
    }

    /**
     * Check if admin has permission based on role_id
     */
    public static function hasPermission(string $permission): bool
    {
        $roleId = self::getCurrentRoleId();

        if ($roleId === 999) {
            return true;
        }

        $permissions = [
            3 => ['view_dashboard', 'view_reports', 'audit_logs', 'compliance_checks'],
            4 => ['view_dashboard', 'view_reports', 'manage_compliance', 'review_transactions', 'kyc_verification'],
            5 => ['view_dashboard', 'view_reports', 'audit_logs', 'read_only']
        ];

        $rolePermissions = $permissions[$roleId] ?? [];

        return in_array($permission, $rolePermissions);
    }

    public static function isSuperAdmin(): bool { return self::getCurrentRoleId() === 999; }
    public static function isRegulator(): bool { return self::getCurrentRoleId() === 3; }
    public static function isComplianceOfficer(): bool { return self::getCurrentRoleId() === 4; }
    public static function isAuditor(): bool { return self::getCurrentRoleId() === 5; }
}
