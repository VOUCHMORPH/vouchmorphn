<?php
declare(strict_types=1);

namespace Application\Admin\Auth;

use Application\Utils\SessionManager;

class AdminAuth
{
    private \PDO $db;
    
    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Login admin user
     */
    public function login(string $username, string $password, string $country): array
    {
        try {
            error_log("[ADMIN AUTH] Login attempt for: {$username} in country: {$country}");
            
            // Check if admin exists (username OR email)
            $stmt = $this->db->prepare("
                SELECT 
                    admin_id, 
                    username, 
                    email, 
                    password_hash, 
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
            
            error_log("[ADMIN AUTH] User found: {$admin['username']}, Role ID: {$admin['role_id']}");
            
            // Verify password
            if (!password_verify($password, $admin['password_hash'])) {
                error_log("[ADMIN AUTH] Password verification failed for: {$username}");
                return ['success' => false, 'message' => 'Invalid username or password.'];
            }
            
            error_log("[ADMIN AUTH] Password verified successfully for: {$username}");
            
            // Check if account is deleted
            if ($admin['deleted_at'] !== null) {
                error_log("[ADMIN AUTH] Account deleted: {$username}");
                return ['success' => false, 'message' => 'Account not found.'];
            }
            
            // Check country access - Super admin (role_id = 999) can access any country
            $isSuperAdmin = ($admin['role_id'] == 999);
            $hasCountryRestriction = !empty($admin['country_code']);
            $countryMatches = ($admin['country_code'] === $country);
            
            if (!$isSuperAdmin && $hasCountryRestriction && !$countryMatches) {
                error_log("[ADMIN AUTH] Country mismatch: {$username} tried {$country} but has {$admin['country_code']}");
                return ['success' => false, 'message' => 'You do not have access to this country\'s admin panel.'];
            }
            
            error_log("[ADMIN AUTH] Country check passed for: {$username}");
            
            // Update last login - NON-FATAL (wrapped in try-catch)
            try {
                $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                
                // Check if columns exist first (safe approach)
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
                // Non-fatal - don't fail the login if this fails
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
            
            // Check if MFA is enabled
            $mfaEnabled = ($admin['mfa_enabled'] === 't' || $admin['mfa_enabled'] === true || $admin['mfa_enabled'] === 1);
            
            if ($mfaEnabled && !empty($admin['mfa_secret'])) {
                SessionManager::set('admin_mfa_pending', true);
                error_log("[ADMIN AUTH] MFA required for {$username}");
                return [
                    'success' => true,
                    'mfa_required' => true,
                    'admin_id' => $admin['admin_id'],
                    'message' => 'MFA verification required.'
                ];
            }
            
            error_log("[ADMIN AUTH] Login successful: {$username} (Role: {$admin['role_id']})");
            return [
                'success' => true,
                'message' => 'Login successful.',
                'admin_id' => $admin['admin_id']
            ];
            
        } catch (\Throwable $e) {
            error_log("[ADMIN AUTH] Login error: " . $e->getMessage());
            error_log("[ADMIN AUTH] Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }
    
    /**
     * Verify MFA code
     */
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
