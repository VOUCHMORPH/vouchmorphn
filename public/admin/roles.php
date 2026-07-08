<?php
declare(strict_types=1);

require_once dirname(__DIR__, 1) . '/src/bootstrap.php';

use Core\Database\DBConnection;

/**
 * Role Manager - SINGLE SOURCE OF TRUTH for all roles
 * All role checks MUST go through this class
 */
class RoleManager
{
    private PDO $db;
    private static ?array $rolesCache = null;
    private static ?array $permissionsCache = null;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DBConnection::getConnection();
    }
    
    /**
     * Get ALL roles from database
     */
    public function getAllRoles(): array
    {
        if (self::$rolesCache === null) {
            $stmt = $this->db->query("
                SELECT 
                    role_id,
                    role_name,
                    role_level,
                    description,
                    can_manage_admins,
                    can_view_transactions,
                    can_edit_config,
                    can_broadcast,
                    can_trigger_cron,
                    can_generate_reports,
                    can_export_data,
                    can_view_audit_logs,
                    permissions,
                    created_at,
                    updated_at
                FROM roles 
                ORDER BY role_level ASC
            ");
            self::$rolesCache = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return self::$rolesCache;
    }
    
    /**
     * Get role by name
     */
    public function getRoleByName(string $roleName): ?array
    {
        $roles = $this->getAllRoles();
        foreach ($roles as $role) {
            if (strtolower($role['role_name']) === strtolower($roleName)) {
                return $role;
            }
        }
        return null;
    }
    
    /**
     * Get role by ID
     */
    public function getRoleById(int $roleId): ?array
    {
        $roles = $this->getAllRoles();
        foreach ($roles as $role) {
            if ($role['role_id'] === $roleId) {
                return $role;
            }
        }
        return null;
    }
    
    /**
     * Get role level (for hierarchy checks)
     */
    public function getRoleLevel(string $roleName): ?int
    {
        $role = $this->getRoleByName($roleName);
        return $role ? (int)$role['role_level'] : null;
    }
    
    /**
     * Check if a role exists
     */
    public function roleExists(string $roleName): bool
    {
        return $this->getRoleByName($roleName) !== null;
    }
    
    /**
     * Check if role has a specific permission
     */
    public function hasPermission(string $roleName, string $permission): bool
    {
        $role = $this->getRoleByName($roleName);
        if (!$role) {
            return false;
        }
        
        // Check specific boolean columns
        switch ($permission) {
            case 'manage_admins':
                return (bool)$role['can_manage_admins'];
            case 'view_transactions':
                return (bool)$role['can_view_transactions'];
            case 'edit_config':
                return (bool)$role['can_edit_config'];
            case 'broadcast':
                return (bool)$role['can_broadcast'];
            case 'trigger_cron':
                return (bool)$role['can_trigger_cron'];
            case 'generate_reports':
                return (bool)$role['can_generate_reports'];
            case 'export_data':
                return (bool)$role['can_export_data'];
            case 'view_audit_logs':
                return (bool)$role['can_view_audit_logs'];
        }
        
        // Check JSON permissions column
        $permissions = json_decode($role['permissions'] ?? '[]', true);
        return in_array($permission, $permissions);
    }
    
    /**
     * Check if role has ANY of the given permissions
     */
    public function hasAnyPermission(string $roleName, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($roleName, $permission)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get all permissions for a role
     */
    public function getRolePermissions(string $roleName): array
    {
        $role = $this->getRoleByName($roleName);
        if (!$role) {
            return [];
        }
        
        $permissions = [];
        
        // Add boolean column permissions
        $boolPermissions = [
            'manage_admins' => $role['can_manage_admins'],
            'view_transactions' => $role['can_view_transactions'],
            'edit_config' => $role['can_edit_config'],
            'broadcast' => $role['can_broadcast'],
            'trigger_cron' => $role['can_trigger_cron'],
            'generate_reports' => $role['can_generate_reports'],
            'export_data' => $role['can_export_data'],
            'view_audit_logs' => $role['can_view_audit_logs']
        ];
        
        foreach ($boolPermissions as $key => $value) {
            if ($value) {
                $permissions[] = $key;
            }
        }
        
        // Add JSON permissions
        $jsonPermissions = json_decode($role['permissions'] ?? '[]', true);
        $permissions = array_merge($permissions, $jsonPermissions);
        
        return array_unique($permissions);
    }
    
    /**
     * Get roles that have a specific permission
     */
    public function getRolesWithPermission(string $permission): array
    {
        $roles = $this->getAllRoles();
        $result = [];
        
        foreach ($roles as $role) {
            if ($this->hasPermission($role['role_name'], $permission)) {
                $result[] = $role['role_name'];
            }
        }
        
        return $result;
    }
    
    /**
     * Get roles that can view audit trails
     */
    public function getAuditViewerRoles(): array
    {
        return $this->getRolesWithPermission('view_audit_logs');
    }
    
    /**
     * Get roles that can manage admins
     */
    public function getAdminManagerRoles(): array
    {
        return $this->getRolesWithPermission('manage_admins');
    }
    
    /**
     * Check if role has access to a country
     * (For COUNTRY_MIDDLEMAN etc.)
     */
    public function hasCountryAccess(string $roleName, string $countryCode): bool
    {
        // If it's a global role, they have access to all countries
        $globalRoles = ['super_admin', 'admin', 'REGULATOR', 'AUDITOR', 'COMPLIANCE'];
        if (in_array(strtolower($roleName), array_map('strtolower', $globalRoles))) {
            return true;
        }
        
        // Otherwise check if role is specifically for this country
        // This would require a role_country_access table
        // For now, assume only super_admin and admin have global access
        return false;
    }
    
    /**
     * Validate that a role is valid and active
     */
    public function validateRole(string $roleName): bool
    {
        return $this->roleExists($roleName);
    }
    
    /**
     * Get role hierarchy - higher level = more permissions
     * Note: In your DB, lower number = higher permissions
     * (super_admin = 100, user = 999)
     */
    public function isHigherOrEqual(string $role1, string $role2): bool
    {
        $level1 = $this->getRoleLevel($role1);
        $level2 = $this->getRoleLevel($role2);
        
        if ($level1 === null || $level2 === null) {
            return false;
        }
        
        // Lower number = higher level in your system
        return $level1 <= $level2;
    }
    
    /**
     * Clear cache (useful after role changes)
     */
    public function clearCache(): void
    {
        self::$rolesCache = null;
        self::$permissionsCache = null;
    }
    
    /**
     * Get role display name with level badge
     */
    public function getRoleDisplay(string $roleName): string
    {
        $role = $this->getRoleByName($roleName);
        if (!$role) {
            return $roleName;
        }
        
        $level = $role['role_level'];
        $badge = $level <= 200 ? '🔴' : ($level <= 400 ? '🟡' : '🟢');
        
        return "{$badge} {$roleName} (Level {$level})";
    }
}

// ============================================================
// HELPER FUNCTIONS FOR EASY ACCESS
// ============================================================

/**
 * Global function to check if current user has permission
 * Use this in all your pages
 */
function currentUserHasPermission(string $permission): bool
{
    $user = \Application\Utils\SessionManager::getUser();
    if (!$user || empty($user['role'])) {
        return false;
    }
    
    $roleManager = new RoleManager();
    return $roleManager->hasPermission($user['role'], $permission);
}

/**
 * Global function to check if current user has ANY of the given permissions
 */
function currentUserHasAnyPermission(array $permissions): bool
{
    $user = \Application\Utils\SessionManager::getUser();
    if (!$user || empty($user['role'])) {
        return false;
    }
    
    $roleManager = new RoleManager();
    return $roleManager->hasAnyPermission($user['role'], $permissions);
}

/**
 * Global function to require a permission (redirects if not)
 */
function requirePermission(string $permission, string $redirectTo = 'admin_login.php'): void
{
    if (!currentUserHasPermission($permission)) {
        error_log("[SECURITY] Permission denied: {$permission} for user: " . 
                  ($_SESSION['admin_username'] ?? 'unknown'));
        header("Location: {$redirectTo}");
        exit();
    }
}

/**
 * Global function to get allowed roles for a permission
 */
function getRolesWithPermission(string $permission): array
{
    $roleManager = new RoleManager();
    return $roleManager->getRolesWithPermission($permission);
}
