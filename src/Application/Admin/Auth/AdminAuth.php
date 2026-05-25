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
                error_log("[ADMIN AUTH] Login failed: User not found - {$username}");
                return ['success' => false, 'message' => 'Invalid username or password.'];
            }
            
            // Verify password
            if (!password_verify($password, $admin['password_hash'])) {
                error_log("[ADMIN AUTH] Login failed: Invalid password for {$username}");
                return ['success' => false, 'message' => 'Invalid username or password.'];
            }
            
            // Check if account is deleted
            if ($admin['deleted_at'] !== null) {
                error_log("[ADMIN AUTH] Login failed: Deleted account - {$username}");
                return ['success' => false, 'message' => 'Account not found.'];
            }
            
            // Check country access (if country_code is set and not matching)
            if (!empty($admin['country_code']) && $admin['country_code'] !== $country && $admin['role_id'] != 999) {
                error_log("[ADMIN AUTH] Login failed: Country mismatch - {$username} tried {$country} but has {$admin['country_code']}");
                return ['success' => false, 'message' => 'You do not have access to this country\'s admin panel.'];
            }
            
            // Update last login
            $updateStmt = $this->db->prepare("
                UPDATE admins 
                SET last_login_at = NOW(), 
                    last_login_ip = :ip 
                WHERE admin_id = :admin_id
            ");
            $updateStmt->execute([
                ':ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ':admin_id' => $admin['admin_id']
            ]);
            
            // Store admin info in session
            SessionManager::set('admin_id', $admin['admin_id']);
            SessionManager::set('admin_username', $admin['username']);
            SessionManager::set('admin_email', $admin['email']);
            SessionManager::set('admin_full_name', $admin['full_name']);
            SessionManager::set('admin_role_id', $admin['role_id']);
            SessionManager::set('admin_country', $country);
            SessionManager::set('admin_logged_in', true);
            
            // Check if MFA is enabled
            if ($admin['mfa_enabled'] == 't' || $admin['mfa_enabled'] === true || $admin['mfa_enabled'] === 1) {
                if (!empty($admin['mfa_secret'])) {
                    SessionManager::set('admin_mfa_pending', true);
                    error_log("[ADMIN AUTH] MFA required for {$username}");
                    return [
                        'success' => true,
                        'mfa_required' => true,
                        'admin_id' => $admin['admin_id'],
                        'message' => 'MFA verification required.'
                    ];
                }
            }
            
            error_log("[ADMIN AUTH] Login successful: {$username} (Role: {$admin['role_id']})");
            return [
                'success' => true,
                'message' => 'Login successful.',
                'admin_id' => $admin['admin_id']
            ];
            
        } catch (\Throwable $e) {
            error_log("[ADMIN AUTH] Login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }
    
    /**
     * Verify MFA code
     */
    public function verifyMfa(string $code, string $country): array
    {
        try {
            // Get pending admin from session
            $adminId = SessionManager::get('admin_id');
            
            if (!$adminId) {
                return ['success' => false, 'message' => 'Session expired. Please login again.'];
            }
            
            // Get admin with MFA secret
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
            
            // Check if MFA is enabled
            $mfaEnabled = ($admin['mfa_enabled'] == 't' || $admin['mfa_enabled'] === true || $admin['mfa_enabled'] === 1);
            
            if (!$mfaEnabled || empty($admin['mfa_secret'])) {
                // Clear MFA pending flag and continue
                SessionManager::remove('admin_mfa_pending');
                return ['success' => true, 'message' => 'MFA not required.'];
            }
            
            // Verify MFA code
            $isValid = $this->verifyTOTP($code, $admin['mfa_secret']);
            
            if (!$isValid) {
                error_log("[ADMIN AUTH] MFA failed for {$admin['username']}");
                return ['success' => false, 'message' => 'Invalid authentication code.'];
            }
            
            // Clear MFA pending flag
            SessionManager::remove('admin_mfa_pending');
            
            error_log("[ADMIN AUTH] MFA verified for {$admin['username']}");
            return ['success' => true, 'message' => 'MFA verified successfully.'];
            
        } catch (\Throwable $e) {
            error_log("[ADMIN AUTH] MFA error: " . $e->getMessage());
            return ['success' => false, 'message' => 'MFA verification failed.'];
        }
    }
    
    /**
     * Verify TOTP code (simplified - replace with actual TOTP library)
     * For production, use: \OTPHP\TOTP::create($secret)->verify($code)
     */
    private function verifyTOTP(string $code, string $secret): bool
    {
        // Simple validation - replace with proper TOTP in production
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }
        
        // For production, use a proper TOTP library
        // Example with OTPHP: 
        // $totp = \OTPHP\TOTP::create($secret);
        // return $totp->verify($code);
        
        // Placeholder for now - accept any 6-digit code if secret is set
        // Remove this in production!
        return true;
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
        
        // Role ID mapping based on your table:
        // 999 = Global Admin (Super Admin)
        // 3 = Regulator (BOB)
        // 4 = Compliance Officer
        // 5 = Auditor
        
        // Super admin has all permissions
        if ($roleId === 999) {
            return true;
        }
        
        // Define role-based permissions
        $permissions = [
            3 => [  // Regulator (BOB)
                'view_dashboard',
                'view_reports',
                'audit_logs',
                'compliance_checks'
            ],
            4 => [  // Compliance Officer
                'view_dashboard',
                'view_reports',
                'manage_compliance',
                'review_transactions',
                'kyc_verification'
            ],
            5 => [  // Auditor
                'view_dashboard',
                'view_reports',
                'audit_logs',
                'read_only'
            ]
        ];
        
        $rolePermissions = $permissions[$roleId] ?? [];
        
        return in_array($permission, $rolePermissions);
    }
    
    /**
     * Check if admin is super admin (global_admin)
     */
    public static function isSuperAdmin(): bool
    {
        return self::getCurrentRoleId() === 999;
    }
    
    /**
     * Check if admin is regulator
     */
    public static function isRegulator(): bool
    {
        return self::getCurrentRoleId() === 3;
    }
    
    /**
     * Check if admin is compliance officer
     */
    public static function isComplianceOfficer(): bool
    {
        return self::getCurrentRoleId() === 4;
    }
    
    /**
     * Check if admin is auditor
     */
    public static function isAuditor(): bool
    {
        return self::getCurrentRoleId() === 5;
    }
}
