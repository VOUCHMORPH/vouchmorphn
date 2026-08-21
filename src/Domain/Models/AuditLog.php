<?php
declare(strict_types=1)

require_once __DIR__ . '/../../bootstrap.php';

namespace Domain\Models;

class AuditLog
{
    public int $id;
    public string $action;
    public string $performed_by;
    public string $target;
    public string $created_at;

    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function log(string $action, string $performed_by, string $target): void {
        $stmt = $this->db->prepare(
            "INSERT INTO audit_logs (action, performed_by, target, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([$action, $performed_by, $target]);
    }
}
