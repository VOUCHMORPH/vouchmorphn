<?php
declare(strict_types=1);

namespace Application\Utils;

use PDO;
use Throwable;
use Core\Database\DBConnection;

/**
 * Wraps the existing Domain\Models\AuditLog (action/performed_by/target/
 * created_at only — a much narrower schema than the richer call shape
 * authorize.php actually uses). Rather than silently drop the extra
 * context (severity, category, entity_id, structured details),
 * everything beyond the base three columns is folded into a single
 * JSON-encoded `target` string, so nothing recorded here is lost even
 * though the underlying table wasn't designed for it.
 *
 * DEFENSIVE REQUIRE: AuditLog.php (src/Domain/Models/AuditLog.php) itself
 * re-requires src/bootstrap.php internally, using a path built from its
 * own directory (dirname(__DIR__, 2) . '/src/bootstrap.php'). From that
 * file's real location this resolves to '.../src/src/bootstrap.php' — a
 * doubled 'src' segment that looks like a genuine path bug in that file,
 * not something fixed here. Confirmed tonight: a failed require_once is
 * a catchable \Error in PHP 8 (it's exactly what crashed authorize.php
 * with an UNCAUGHT fatal when THIS file was simply missing) — so the
 * require below is wrapped in try/catch specifically so that if
 * AuditLog.php's own internal require is in fact broken, audit logging
 * degrades to "log to error_log and continue" instead of taking down
 * whatever called AuditLogger, the same way this whole investigation
 * started.
 */
class AuditLogger
{
    private $auditLog = null; // \Domain\Models\AuditLog|null — untyped to avoid a hard class dependency if the require below fails

    public function __construct(?PDO $db = null)
    {
        try {
            $modelPath = dirname(__DIR__, 2) . '/Domain/Models/AuditLog.php';
            if (!file_exists($modelPath)) {
                error_log("[AuditLogger] AuditLog model not found at expected path: {$modelPath} — audit logging disabled for this request.");
                return;
            }
            require_once $modelPath;

            if (!class_exists('\Domain\Models\AuditLog')) {
                error_log("[AuditLogger] AuditLog.php was loaded but \\Domain\\Models\\AuditLog class was not defined afterward — audit logging disabled for this request.");
                return;
            }

            $db = $db ?? $this->resolveDb();
            if ($db === null) {
                error_log("[AuditLogger] No PDO connection available — audit logging disabled for this request.");
                return;
            }

            $this->auditLog = new \Domain\Models\AuditLog($db);

        } catch (Throwable $e) {
            // Catches a failed require_once inside AuditLog.php itself
            // (e.g. its own broken bootstrap path) as well as any
            // constructor failure — audit logging is never allowed to
            // take the caller down with it.
            error_log("[AuditLogger] Failed to initialize — audit logging disabled for this request: " . $e->getMessage());
            $this->auditLog = null;
        }
    }

    private function resolveDb(): ?PDO
    {
        try {
            if (!class_exists('\Core\Database\DBConnection')) {
                $dbConnectionPath = dirname(__DIR__, 2) . '/Core/Database/DBConnection.php';
                if (file_exists($dbConnectionPath)) {
                    require_once $dbConnectionPath;
                }
            }
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
