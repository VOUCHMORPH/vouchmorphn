<?php
declare(strict_types=1);

namespace Application\Utils;

use PDO;
use Throwable;

/**
 * ROOT CAUSE FIX (confirmed live 21 Aug 2026): the real audit_logs
 * table has columns audit_id, audit_uuid, entity_type, entity_id,
 * action, category, severity, old_value, new_value, changes,
 * performed_by_type, performed_by_id, ip_address, user_agent,
 * geo_location, request_id, performed_at, integrity_hash, timestamp,
 * event_type, client_id, endpoint, duration_ms, prev_hash, entry_hash
 * — nothing like the narrow action/performed_by/target/created_at
 * shape Domain\Models\AuditLog assumed. That model was built against
 * the wrong schema from the start; every fix so far (namespace,
 * strict_types, use PDO) made the CLASS load correctly, but the
 * INSERT inside it was always going to fail against the real table.
 *
 * Rather than patch that model again, this class now writes directly
 * to audit_logs, using the SAME technique
 * SwapService::writeAuditLogEntry() already uses successfully against
 * this exact table: introspect the real columns at runtime, only
 * insert what exists, and chain entry_hash = SHA256(prev_hash ||
 * canonical fields) the same way, so this audit trail is provably
 * continuous with the one SwapService already writes — one real audit
 * log, not two different half-working ones.
 *
 * No dependency on Domain\Models\AuditLog remains.
 */
class AuditLogger
{
    private ?PDO $db;
    private array $columns = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? $this->resolveDb();

        if ($this->db === null) {
            error_log("[AuditLogger] No PDO connection available — audit logging disabled for this request.");
            return;
        }

        try {
            $stmt = $this->db->query("SELECT * FROM audit_logs LIMIT 0");
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $this->columns[] = $stmt->getColumnMeta($i)['name'];
            }
        } catch (Throwable $e) {
            error_log("[AuditLogger] audit_logs table unreadable — audit logging disabled for this request: " . $e->getMessage());
            $this->db = null;
        }
    }

    private function resolveDb(): ?PDO
    {
        try {
            if (class_exists('\Core\Database\DBConnection')) {
                return \Core\Database\DBConnection::getConnection();
            }
        } catch (Throwable $e) {
            error_log("[AuditLogger] resolveDb() failed: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Matches the call shape authorize.php already uses:
     *   $auditLogger->log('CARD_AUTH_APPROVED', 'INFO', 'card_network', null, null, [...]);
     *
     * @param string      $action     What happened, e.g. 'CARD_AUTH_APPROVED' — written to both action and event_type
     * @param string      $severity   e.g. 'info', 'warning', 'critical'. Normalized
     *                                to lowercase before insert: audit_logs_severity_check
     *                                only accepts info|warning|error|critical and Postgres
     *                                compares case sensitively, so the uppercase values
     *                                callers pass would otherwise fail the constraint on
     *                                every write.
     * @param string      $category   e.g. 'card_network' — written to both category and entity_type
     * @param string|int|null $performedBy  Who/what performed the action — written to performed_by_id if numeric
     * @param string|int|null $entityId     Optional entity identifier — written to entity_id, falls back to $performedBy
     * @param array       $details    Arbitrary structured context — written to new_value as JSON (closest real column for free-form payload)
     */
    public function log(
        string $action,
        string $severity = 'INFO',
        string $category = 'general',
        $performedBy = null,
        $entityId = null,
        array $details = []
    ): void {
        if ($this->db === null || empty($this->columns)) {
            error_log("[AuditLogger] log() skipped (no DB/schema available) — action='{$action}' severity={$severity} category={$category} details=" . json_encode($details));
            return;
        }

        try {
            // audit_logs_severity_check accepts info|warning|error|critical,
            // lowercase. Callers here pass 'INFO'/'WARNING'/'ERROR', which
            // failed the constraint on every insert and was swallowed by the
            // catch below -- so none of these rows were ever written.
            $severity = strtolower($severity);
            if ($severity === 'debug') {
                $severity = 'info';
            }
            if (!in_array($severity, ['info', 'warning', 'error', 'critical'], true)) {
                $severity = 'info';
            }

            $performedById = is_numeric($performedBy) ? (int)$performedBy : null;
            $performedByType = $performedById !== null ? 'user' : 'system';
            $resolvedEntityId = $entityId !== null ? (string)$entityId : (string)($performedBy ?? $action);
            $performedAt = date('Y-m-d H:i:s');

            // Same hash-chain technique as SwapService::writeAuditLogEntry() —
            // global chain (not per-entity), so this audit trail is
            // provably continuous with every other entry already written
            // to this table by the rest of the system.
            $prevHash = null;
            if (in_array('entry_hash', $this->columns, true)) {
                try {
                    $prevHash = $this->db->query(
                        "SELECT entry_hash FROM audit_logs ORDER BY audit_id DESC LIMIT 1"
                    )->fetchColumn() ?: null;
                } catch (Throwable $e) {
                    // No prior rows yet — chain starts at null.
                }
            }

            $canonical = json_encode([
                'entity_type' => $category,
                'entity_id' => $resolvedEntityId,
                'action' => $action,
                'performed_at' => $performedAt,
                'performed_by_id' => $performedById,
            ]);
            $entryHash = hash('sha256', ($prevHash ?? '') . $canonical);

            $values = [
                'entity_type' => $category,
                'entity_id' => $resolvedEntityId,
                'action' => $action,
                'category' => $category,
                'severity' => $severity,
                'performed_by_type' => $performedByType,
                'performed_by_id' => $performedById,
                'performed_at' => $performedAt,
                'timestamp' => $performedAt,
                'event_type' => $action,
                'audit_uuid' => $this->generateUuid(),
                'prev_hash' => $prevHash,
                'entry_hash' => $entryHash,
                'new_value' => !empty($details) ? json_encode($details) : null,
            ];

            $fields = [];
            $placeholders = [];
            $params = [];
            foreach ($values as $col => $val) {
                if (in_array($col, $this->columns, true) && $val !== null) {
                    $fields[] = $col;
                    $placeholders[] = ":{$col}";
                    $params[":{$col}"] = $val;
                }
            }

            if (empty($fields)) {
                error_log("[AuditLogger] No matching columns found for action='{$action}' — audit_logs schema may have changed again.");
                return;
            }

            $sql = "INSERT INTO audit_logs (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

        } catch (Throwable $e) {
            // Same non-blocking posture as every other audit/tracking
            // write in this codebase — a logging failure must never
            // surface as the caller's own failure.
            error_log("[AuditLogger] Failed to write audit log entry for action='{$action}': " . $e->getMessage());
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
