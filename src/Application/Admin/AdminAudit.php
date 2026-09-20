<?php
declare(strict_types=1);

namespace Application\Admin;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Records what administrators do, in audit_logs, using the columns that
 * table actually has.
 *
 * The hash chain (prev_hash, entry_hash, chain_seq) is computed by the
 * database trigger added in 2026_09_19_audit_chain_enforced.sql, so this
 * class never touches those columns and cannot get them wrong.
 *
 * Two ways to call it:
 *  - record()        throws if the row cannot be written. Use it for
 *                    actions that change something (approve, reject,
 *                    pause, release): wrap the change and the audit write
 *                    in one transaction so neither happens without the
 *                    other.
 *  - recordOrLog()   never throws. Use it for read access (report views,
 *                    exports, lookups) where failing the page would be
 *                    worse than a missing access record; failures still
 *                    land in the PHP error log and audit_log_failures.
 */
final class AdminAudit
{
    public const CATEGORY_ADMIN = 'ADMIN';
    public const CATEGORY_DATA_ACCESS = 'DATA_ACCESS';
    public const CATEGORY_SECURITY = 'SECURITY';

    public static function record(
        PDO $db,
        ?int $adminId,
        string $action,
        string $entityType,
        string $entityId,
        ?array $oldValue = null,
        ?array $newValue = null,
        string $severity = 'info',
        string $category = self::CATEGORY_ADMIN
    ): void {
        $stmt = $db->prepare("
            INSERT INTO audit_logs (
                entity_type, entity_id, action, category, severity,
                old_value, new_value,
                performed_by_type, performed_by_id,
                ip_address, user_agent, request_id
            ) VALUES (
                :entity_type, :entity_id, :action, :category, :severity,
                CAST(:old_value AS jsonb), CAST(:new_value AS jsonb),
                'admin', :performed_by_id,
                CAST(:ip AS inet), :user_agent, :request_id
            )
        ");
        $ok = $stmt->execute([
            ':entity_type' => substr($entityType, 0, 50),
            ':entity_id' => substr($entityId, 0, 255),
            ':action' => substr($action, 0, 50),
            ':category' => substr($category, 0, 50),
            ':severity' => substr($severity, 0, 20),
            ':old_value' => $oldValue === null ? null : json_encode($oldValue, JSON_UNESCAPED_SLASHES),
            ':new_value' => $newValue === null ? null : json_encode($newValue, JSON_UNESCAPED_SLASHES),
            ':performed_by_id' => $adminId,
            ':ip' => self::clientIp(),
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ':request_id' => self::requestId(),
        ]);
        if (!$ok) {
            throw new RuntimeException('Audit write failed for ' . $action);
        }
    }

    public static function recordOrLog(
        PDO $db,
        ?int $adminId,
        string $action,
        string $entityType,
        string $entityId,
        ?array $newValue = null,
        string $category = self::CATEGORY_DATA_ACCESS,
        string $severity = 'info'
    ): bool {
        try {
            self::record($db, $adminId, $action, $entityType, $entityId, null, $newValue, $severity, $category);
            return true;
        } catch (Throwable $e) {
            error_log('[AdminAudit] ' . $action . ' not recorded: ' . $e->getMessage());
            try {
                $f = $db->prepare("INSERT INTO audit_log_failures (swap_reference, swap_type, reason) VALUES (:ref, 'ADMIN', :reason)");
                $f->execute([':ref' => substr($action . ':' . $entityId, 0, 255), ':reason' => 'Admin audit write failed: ' . $e->getMessage()]);
            } catch (Throwable $ignored) {
                // Already in the PHP error log; nothing further is possible.
            }
            return false;
        }
    }

    /**
     * Runs the database's own chain check.
     * @return array{state:string,total:int,first_bad_seq:?int,reason:?string,head_seq:?int,head_hash:?string,head_at:?string,error:?string}
     */
    public static function verifyChain(PDO $db): array
    {
        $out = ['state' => 'unknown', 'total' => 0, 'first_bad_seq' => null, 'reason' => null,
                'head_seq' => null, 'head_hash' => null, 'head_at' => null, 'error' => null];
        try {
            $v = $db->query("SELECT total_rows, intact, first_bad_seq, reason FROM audit_chain_verify()")->fetch(PDO::FETCH_ASSOC);
            $out['total'] = (int)$v['total_rows'];
            $out['state'] = $v['intact'] ? 'intact' : 'broken';
            $out['first_bad_seq'] = $v['first_bad_seq'] !== null ? (int)$v['first_bad_seq'] : null;
            $out['reason'] = $v['reason'];
            $h = $db->query("SELECT chain_seq, entry_hash, performed_at FROM audit_chain_head()")->fetch(PDO::FETCH_ASSOC);
            if ($h) {
                $out['head_seq'] = (int)$h['chain_seq'];
                $out['head_hash'] = $h['entry_hash'];
                $out['head_at'] = $h['performed_at'];
            }
        } catch (Throwable $e) {
            // Most likely the 2026_09_19 migration has not been applied.
            $out['state'] = 'unknown';
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    /** Masks all but the last 3 characters, so lookups are traceable without copying personal data into the audit log. */
    public static function mask(string $value): string
    {
        $len = strlen($value);
        return $len <= 3 ? str_repeat('*', $len) : str_repeat('*', $len - 3) . substr($value, -3);
    }

    private static function clientIp(): ?string
    {
        // Behind Railway's proxy the real client is the first X-Forwarded-For entry.
        $candidates = [];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $candidates[] = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        $candidates[] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return null;
    }

    private static function requestId(): string
    {
        static $id = null;
        if ($id === null) {
            $id = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8)));
        }
        return substr($id, 0, 100);
    }
}
