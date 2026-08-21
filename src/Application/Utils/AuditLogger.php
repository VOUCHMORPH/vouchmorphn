<?php
declare(strict_types=1);

namespace Application\Utils;

use PDO;
use Throwable;
use Domain\Models\AuditLog;

/**
 * Wraps the existing Domain\Models\AuditLog (action/performed_by/target/
 * created_at only — a much narrower schema than the richer call shape
 * authorize.php actually uses). Rather than silently drop the extra
 * context (severity, category, entity_id, structured details),
 * everything beyond the base three columns is folded into a single
 * JSON-encoded `target` string, so nothing recorded here is lost even
 * though the underlying table wasn't designed for it.
 *
 * ROOT CAUSE FIX (confirmed live 21 Aug 2026): this file previously
 * required AuditLog.php explicitly via require_once. composer.json
 * defines a PSR-4 autoload mapping for the WHOLE Domain\ namespace
 * (Domain\ -> src/Domain/), so \Domain\Models\AuditLog is ALREADY
 * autoloaded automatically the moment it's referenced anywhere in a
 * request — the explicit require_once here was redundant, and is
 * exactly what caused "Cannot declare class AuditLog, because the
 * name is already in use": the autoloader had already declared it
 * before this file's own require_once tried to declare it again.
 * `use Domain\Models\AuditLog;` at the top of this file is now the
 * ONLY reference — Composer's autoloader handles the rest, exactly
 * once, no matter how many places in the codebase reference the class.
 *
 * DEFENSIVE INSTANTIATION KEPT: AuditLog.php itself re-requires
 * src/bootstrap.php internally, using a path built from its own
 * directory that resolves to '.../src/src/bootstrap.php' — a doubled
 * 'src' segment that looks like a genuine bug in that file, not fixed
 * here. That internal require_once still fires the FIRST time the
 * autoloader pulls the file in (autoloading doesn't skip a file's own
 * top-level code), and a failed require_once is a catchable \Error in
 * PHP 8 — so instantiation below stays wrapped in try/catch, which is
 * what lets audit logging degrade cleanly to "log to error_log and
 * continue" if that internal bug is ever hit, instead of taking down
 * whatever called AuditLogger.
 */
class AuditLogger
{
    private ?AuditLog $auditLog = null;

    public function __construct(?PDO $db = null)
    {
        try {
            $db = $db ?? $this->resolveDb();
            if ($db === null) {
                error_log("[AuditLogger] No PDO connection available — audit logging disabled for this request.");
                return;
            }

            $this->auditLog = new AuditLog($db);

        } catch (Throwable $e) {
            // Catches AuditLog.php's own internal bootstrap require
            // failing (its doubled-'src' path bug, if still present) as
            // well as any other constructor failure — audit logging is
            // never allowed to take the caller down with it.
            error_log("[AuditLogger] Failed to initialize — audit logging disabled for this request: " . $e->getMessage());
            $this->auditLog = null;
        }
    }

    private function resolveDb(): ?PDO
    {
        try {
            // Core\ is also PSR-4 autoloaded (composer.json), so no
            // manual require needed here either — same fix applied.
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
     * @param string      $action     What happened, e.g. 'CARD_AUTH_APPROVED'
     * @param string      $severity   e.g. 'INFO', 'WARNING', 'CRITICAL' — folded into target
     * @param string      $category   e.g. 'card_network' — folded into target
     * @param string|int|null $performedBy  Who/what performed the action; defaults to 'SYSTEM'
     * @param string|int|null $entityId     Optional entity identifier — folded into target
     * @param array       $details    Arbitrary structured context — folded into target as JSON
     */
    public function log(
        string $action,
        string $severity = 'INFO',
        string $category = 'general',
        $performedBy = null,
        $entityId = null,
        array $details = []
    ): void {
        if ($this->auditLog === null) {
            // Audit logging unavailable this request — never block the
            // caller over it, but don't lose the signal silently either.
            error_log("[AuditLogger] log() skipped (no DB/model available) — action='{$action}' severity={$severity} category={$category} details=" . json_encode($details));
            return;
        }

        try {
            $performedByLabel = $performedBy !== null ? (string)$performedBy : 'SYSTEM';

            $targetPayload = json_encode(array_filter([
                'severity' => $severity,
                'category' => $category,
                'entity_id' => $entityId,
                'details' => $details ?: null,
            ], fn($v) => $v !== null));

            $this->auditLog->log($action, $performedByLabel, $targetPayload ?: '{}');

        } catch (Throwable $e) {
            // Same non-blocking posture as every other audit/tracking
            // write in this codebase (see SwapService::writeAuditLogEntry()
            // and its writeAuditFallback()) — a logging failure must never
            // surface as the caller's own failure.
            error_log("[AuditLogger] Failed to write audit log entry for action='{$action}': " . $e->getMessage());
        }
    }
}
