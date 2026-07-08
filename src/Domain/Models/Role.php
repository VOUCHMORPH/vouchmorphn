<?php
// Enhanced Role Management

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use Core\Database\DBConnection;
use Domain\Services\AuditTrailService;
use Application\Utils\SessionManager;

class RoleManager {
    private PDO $db;
    private AuditTrailService $auditService;
    private array $rolePermissions = [];
    
    // Standard role definitions for payments platform
    private const ROLE_DEFINITIONS = [
        'GLOBAL_OWNER' => [
            'permissions' => ['*'],
            'can_approve' => true,
            'can_initiate' => true,
            'can_configure' => true,
            'can_audit' => true,
            'requires_dual_control' => false // They ARE the ultimate authority
        ],
        'COUNTRY_MIDDLEMAN' => [
            'permissions' => ['view_reports', 'manage_users', 'approve_settlements'],
            'can_approve' => true,
            'can_initiate' => false, // Can't initiate, only approve
            'can_configure' => false,
            'can_audit' => true,
            'requires_dual_control' => true // Needs second approval
        ],
        'AUDITOR' => [
            'permissions' => ['view_audit_logs', 'view_reports'],
            'can_approve' => false,
            'can_initiate' => false,
            'can_configure' => false,
            'can_audit' => true,
            'requires_dual_control' => false
        ],
        'admin' => [
            'permissions' => ['view_reports', 'manage_local_users'],
            'can_approve' => false,
            'can_initiate' => false,
            'can_configure' => false,
            'can_audit' => false,
            'requires_dual_control' => false
        ]
    ];
    
    public function __construct(PDO $db, AuditTrailService $auditService) {
        $this->db = $db;
        $this->auditService = $auditService;
        $this->loadPermissions();
    }
    
    private function loadPermissions(): void {
        // Load from database with caching
        $stmt = $this->db->query("SELECT * FROM role_permissions");
        $this->rolePermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function hasPermission(string $role, string $permission): bool {
        // Check if role has specific permission
        if ($role === 'GLOBAL_OWNER') return true; // Has all permissions
        
        foreach ($this->rolePermissions as $rp) {
            if ($rp['role_name'] === $role && $rp['permission'] === $permission) {
                return true;
            }
        }
        return false;
    }
    
    public function requiresDualControl(string $action): bool {
        // Check if an action requires two-person approval
        $stmt = $this->db->prepare(
            "SELECT requires_approval FROM actions WHERE action_name = :action"
        );
        $stmt->execute([':action' => $action]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['requires_approval'] ?? false;
    }
}
