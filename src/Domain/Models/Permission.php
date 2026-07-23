<?php

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

class Permission
{
    public int $id;
    public string $name;
    public string $description;

    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function getByRole(int $role_id): array {
        $stmt = $this->db->prepare(
            "SELECT p.* FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id=?"
        );
        $stmt->execute([$role_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
