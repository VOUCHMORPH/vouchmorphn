<?php
declare(strict_types=1);

namespace Application\Admin\Auth;

use Application\Utils\SessionManager;

class AdminAuth
{
    private $db;
    
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
            // Check if admin exists
            $stmt = $this->db->prepare("
                SELECT id, username, password_hash, mfa_secret, role, is_active 
                FROM admins 
                WHERE username = :username 
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);
            $admin = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$admin) {
                return ['success' => false, 'message' => 'Invalid username or password.'];
            }
            
            // Check if account is active
            if (!$admin['is_active']) {
                return ['success' => false, 'message' => 'Your account has been deactivated. Contact support.'];
            }
            
            // Verify password
            if (!password_verify($password, $admin['password_hash'])) {
                return ['success' => false, 'message' => 'Invalid username or password.'];
            }
            
            // Store admin info in session
            SessionManager::set('admin_id', $admin['id']);
            SessionManager::set('admin_username', $admin['username']);
            SessionManager::set('admin_role', $admin['role']);
            SessionManager::set('admin_country', $country);
            SessionManager::set('admin_logged_in', true);
            
            // Check if MFA is required
            if (!empty($admin['mfa_secret'])) {
                SessionManager::set('admin_mfa_pending', true);
                return [
                    'success' => true,
                    'mfa_required' => true,
                    'admin_id' => $admin['id'],
                    'message' => 'MFA verification required.'
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Login successful.',
                'admin_id' => $admin['id']
            ];
            
        } catch (\Throwable $e) {
            error_log("AdminAuth::login error: " . $e->getMessage());
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
                SELECT id, mfa_secret 
                FROM admins 
                WHERE id = :id 
                LIMIT 1
            ");
            $stmt->execute([':id' => $adminId]);
            $admin = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$admin || empty($admin['mfa_secret'])) {
                return ['success' => false, 'message' => 'MFA not configured for this account.'];
            }
            
            // Verify MFA code (simplified - use a proper TOTP library in production)
            // For production, use: \OTPHP\TOTP::create($admin['mfa_secret'])->verify($code)
            $isValid = $this->verifyTOTP($code, $admin['mfa_secret']);
            
            if (!$isValid) {
                return ['success' => false, 'message' => 'Invalid authentication code.'];
            }
            
            // Clear MFA pending flag
            SessionManager::remove('admin_mfa_pending');
            
            return ['success' => true, 'message' => 'MFA verified successfully.'];
            
        } catch (\Throwable $e) {
            error_log("AdminAuth::verifyMfa error: " . $e->getMessage());
            return ['success' => false, 'message' => 'MFA verification failed.'];
        }
    }
    
    /**
     * Verify TOTP code (simplified - replace with actual TOTP library)
     */
    private function verifyTOTP(string $code, string $secret): bool
    {
        // This is a placeholder - use a proper TOTP library in production
        // Recommended: spomky-labs/otphp
        return strlen($code) === 6 && ctype_digit($code);
    }
    
    /**
     * Check if admin is logged in
     */
    public static function isLoggedIn(): bool
    {
        return SessionManager::get('admin_logged_in') === true && SessionManager::get('admin_mfa_pending') !== true;
    }
    
    /**
     * Logout admin
     */
    public static function logout(): void
    {
        SessionManager::remove('admin_id');
        SessionManager::remove('admin_username');
        SessionManager::remove('admin_role');
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
     * Get current admin role
     */
    public static function getCurrentAdminRole(): ?string
    {
        return SessionManager::get('admin_role');
    }
    
    /**
     * Check if admin has permission
     */
    public static function hasPermission(string $permission): bool
    {
        $role = self::getCurrentAdminRole();
        
        // Super admin has all permissions
        if ($role === 'super_admin') {
            return true;
        }
        
        // Define role-based permissions
        $permissions = [
            'admin' => [
                'view_dashboard',
                'view_reports',
                'manage_users',
                'view_transactions'
            ],
            'viewer' => [
                'view_dashboard',
                'view_reports'
            ]
        ];
        
        $rolePermissions = $permissions[$role] ?? [];
        
        return in_array($permission, $rolePermissions);
    }
}
