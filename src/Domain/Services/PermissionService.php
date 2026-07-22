<?php

declare(strict_types=1);

namespace Domain\Services;

use PDO;

class PermissionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Check if a user has a system-level permission
     */
    public function hasSystemPermission(int $userId, string $permissionName): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 
            FROM user_roles ur
            JOIN role_permissions rp ON rp.role_id = ur.role_id
            JOIN permissions p ON p.id = rp.permission_id
            WHERE ur.user_id = :user_id AND p.name = :permission_name
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':permission_name' => $permissionName
        ]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Check if an organization role has a specific permission
     */
    public function hasOrganizationPermission(string $roleCode, string $permissionCode): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 
            FROM organization_role_permissions 
            WHERE role_code = :role_code 
            AND permission_code = :permission_code
            LIMIT 1
        ");
        $stmt->execute([
            ':role_code' => $roleCode,
            ':permission_code' => $permissionCode
        ]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Get all system permissions for a user
     */
    public function getUserSystemPermissions(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.name 
            FROM user_roles ur
            JOIN role_permissions rp ON rp.role_id = ur.role_id
            JOIN permissions p ON p.id = rp.permission_id
            WHERE ur.user_id = :user_id
            GROUP BY p.name
            ORDER BY p.name
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get all organization permissions for a role
     */
    public function getOrganizationPermissions(string $roleCode): array
    {
        $stmt = $this->db->prepare("
            SELECT permission_code 
            FROM organization_role_permissions 
            WHERE role_code = :role_code
            ORDER BY permission_code
        ");
        $stmt->execute([':role_code' => $roleCode]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Grant a system permission to a role
     */
    public function grantSystemPermission(int $roleId, int $permissionId): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO role_permissions (role_id, permission_id)
            VALUES (:role_id, :permission_id)
            ON CONFLICT (role_id, permission_id) DO NOTHING
        ");
        return $stmt->execute([
            ':role_id' => $roleId,
            ':permission_id' => $permissionId
        ]);
    }

    /**
     * Revoke a system permission from a role
     */
    public function revokeSystemPermission(int $roleId, int $permissionId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM role_permissions 
            WHERE role_id = :role_id 
            AND permission_id = :permission_id
        ");
        return $stmt->execute([
            ':role_id' => $roleId,
            ':permission_id' => $permissionId
        ]);
    }

    /**
     * Grant an organization permission to a role
     */
    public function grantOrganizationPermission(string $roleCode, string $permissionCode): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO organization_role_permissions (role_code, permission_code)
            VALUES (:role_code, :permission_code)
            ON CONFLICT (role_code, permission_code) DO NOTHING
        ");
        return $stmt->execute([
            ':role_code' => $roleCode,
            ':permission_code' => $permissionCode
        ]);
    }

    /**
     * Revoke an organization permission from a role
     */
    public function revokeOrganizationPermission(string $roleCode, string $permissionCode): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM organization_role_permissions 
            WHERE role_code = :role_code 
            AND permission_code = :permission_code
        ");
        return $stmt->execute([
            ':role_code' => $roleCode,
            ':permission_code' => $permissionCode
        ]);
    }
}
