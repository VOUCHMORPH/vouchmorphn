<?php


declare(strict_types=1);

namespace ADMIN_LAYER\Middleware;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

class RoleMiddleware
{
    private array $roleHierarchy = [
        'GLOBAL_OWNER' => 100,
        'admin' => 200,
        'REGULATOR' => 300,
        'COMPLIANCE' => 400,
        'AUDITOR' => 500,
        'SUPPORT' => 600,
        'user' => 999
    ];

    private array $permissions = [
        'GLOBAL_OWNER' => [
            'view_dashboard',
            'manage_admins',
            'edit_config',
            'broadcast',
            'trigger_cron',
            'view_transactions',
            'view_audit_logs',
            'export_data',
            'generate_reports',
            'manage_countries'
        ],
        'admin' => [
            'view_dashboard',
            'manage_admins',
            'broadcast',
            'view_transactions',
            'view_audit_logs',
            'export_data',
            'generate_reports'
        ],
        'REGULATOR' => [
            'view_dashboard',
            'view_transactions',
            'view_audit_logs',
            'generate_reports',
            'export_data'
        ],
        'COMPLIANCE' => [
            'view_dashboard',
            'view_transactions',
            'view_audit_logs',
            'export_data'
        ],
        'AUDITOR' => [
            'view_dashboard',
            'view_transactions',
            'view_audit_logs',
            'export_data'
        ],
        'SUPPORT' => [
            'view_dashboard'
        ]
    ];

    public function hasAccess(string $role, string $permission): bool
    {
        // GLOBAL_OWNER has access to everything
        if ($role === 'GLOBAL_OWNER') {
            return true;
        }

        return in_array($permission, $this->permissions[$role] ?? []);
    }

    public function hasAnyAccess(string $role, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasAccess($role, $permission)) {
                return true;
            }
        }
        return false;
    }

    public function hasAllAccess(string $role, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasAccess($role, $permission)) {
                return false;
            }
        }
        return true;
    }

    public function getRoleLevel(string $role): int
    {
        return $this->roleHierarchy[$role] ?? 999;
    }

    public function isRoleHigher(string $role1, string $role2): bool
    {
        return $this->getRoleLevel($role1) < $this->getRoleLevel($role2);
    }

    public function getVisibleMetrics(string $role): array
    {
        $metrics = [
            'GLOBAL_OWNER' => ['total_users', 'total_transactions', 'total_volume', 'active_participants', 'pending_settlements', 'total_fees', 'net_position'],
            'admin' => ['total_users', 'total_transactions', 'total_volume', 'active_participants', 'pending_settlements', 'total_fees'],
            'REGULATOR' => ['total_transactions', 'total_volume', 'active_participants', 'pending_settlements', 'net_position'],
            'COMPLIANCE' => ['total_transactions', 'total_volume', 'pending_settlements'],
            'AUDITOR' => ['total_transactions', 'total_volume', 'pending_settlements'],
            'SUPPORT' => ['total_transactions']
        ];

        return $metrics[$role] ?? ['total_transactions'];
    }

    public function getDefaultRoute(string $role): string
    {
        return match($role) {
            'GLOBAL_OWNER' => 'dashboard.php',
            'admin' => 'dashboard.php',
            'REGULATOR' => 'regulatory_dashboard.php',
            'COMPLIANCE' => 'compliance_dashboard.php',
            'AUDITOR' => 'audit_dashboard.php',
            'SUPPORT' => 'support_dashboard.php',
            default => 'dashboard.php'
        };
    }
}
