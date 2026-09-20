<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Throwable;
use Core\Database\DBConnection;

/**
 * Service class for country-specific audit logging.
 * Country is handled via session/config, NOT stored in database.
 * The changes JSON column can optionally include country for reference.
 * 
 * MATCHES ACTUAL audit_logs TABLE SCHEMA:
 * audit_id, audit_uuid, entity_type, entity_id, action, category, severity,
 * old_value, new_value, changes, performed_by_type, performed_by_id,
 * ip_address, user_agent, geo_location, request_id, performed_at,
 * prev_hash, entry_hash, event_type, client_id, endpoint, duration_ms
 *
 * This list previously claimed `integrity_hash` and `timestamp` as well.
 * Neither exists on any Botswana schema -- they are South-Africa-only --
 * and naming `timestamp` in the INSERT made every single write fail with
 * "column timestamp does not exist". entity_id is VARCHAR as of
 * 2026_09_16_transaction_audit_integrity.sql, so a swap reference is a
 * legal entity id here, not just a numeric row id.
 */
class AuditTrailService
{
    private PDO $db;
    private array $config;
    private $logger = null;
    private string $countryCode;

    // Valid severity levels that match the database constraint
    // (audit_logs_severity_check). Postgres compares these case
    // sensitively, so they must be lowercase: the previous uppercase list
    // meant every insert this class attempted violated the constraint and
    // was swallowed by the catch below. 'DEBUG' was never a legal value
    // and 'critical' was missing.
    private const VALID_SEVERITIES = ['info', 'warning', 'error', 'critical'];

    public function __construct(
        PDO $db,
        array $config,
        $logger = null,
        ?string $countryCode = null
    ) {
        $this->db = $db;
        $this->config = $config;
        
        if ($logger === null) {
            $this->logger = new class() {
                public function info($msg): void {
                    error_log("[INFO] " . json_encode($msg));
                }
                public function error($msg): void {
                    error_log("[ERROR] " . json_encode($msg));
                }
                public function warning($msg): void {
                    error_log("[WARNING] " . json_encode($msg));
                }
                public function debug($msg): void {
                    error_log("[DEBUG] " . json_encode($msg));
                }
            };
        } else {
            $this->logger = $logger;
        }
        
        // Get country code from config or session
        if ($countryCode === null) {
            $this->countryCode = $this->getCountryCodeFromConfig();
        } else {
            $this->countryCode = strtoupper($countryCode);
        }
        
        $this->logger->info('AuditTrailService initialized for country: ' . $this->countryCode);
    }

    /**
     * Get country code from config or session
     * Follows the same pattern as user/login.php
     */
    private function getCountryCodeFromConfig(): string
    {
        // Check if SYSTEM_COUNTRY is defined (from bootstrap)
        if (defined('SYSTEM_COUNTRY')) {
            return SYSTEM_COUNTRY;
        }
        
        // Check session for admin_country
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['admin_country'])) {
            return strtoupper($_SESSION['admin_country']);
        }
        
        // Check session for user country
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_country'])) {
            return strtoupper($_SESSION['user_country']);
        }
        
        // Try to get from config array
        if (isset($this->config['country_code'])) {
            return strtoupper($this->config['country_code']);
        }
        
        if (isset($this->config['country'])) {
            return strtoupper($this->config['country']);
        }
        
        // Try to get from environment
        $envCountry = getenv('VOUCHMORPH_COUNTRY');
        if ($envCountry) {
            return strtoupper($envCountry);
        }
        
        // Default fallback
        $this->logger->warning('No country code found, using default: BW');
        return 'BW';
    }

    /**
     * Normalize severity to a valid value
     */
    private function normalizeSeverity(string $severity): string
    {
        $lower = strtolower($severity);
        // 'debug' was accepted by the old PHP-side list but has never been
        // a legal database value; map it to the closest one that is.
        if ($lower === 'debug') {
            $lower = 'info';
        }
        if (in_array($lower, self::VALID_SEVERITIES, true)) {
            return $lower;
        }
        $this->logger->warning("Invalid severity '{$severity}' normalized to info");
        return 'info';
    }

    /**
     * Check if audit_logs table exists
     */
    private function checkTableReady(): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM audit_logs LIMIT 1");
            $stmt->execute();
            return true;
        } catch (Throwable $e) {
            $this->logger->warning('Audit table not ready: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Records an action for the local country admin.
     * Country is NOT stored in database (no country_code column).
     * It's included in the changes JSON for reference if needed.
     */
    public function recordLog(
        string $entityType,
        int|string|null $entityId,
        string $action,
        string $category,
        string $severity = 'INFO',
        ?string $oldValue = null,
        ?string $newValue = null,
        ?int $performedById = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $geoLocation = null,
        ?array $changes = null,
        ?string $requestId = null,
        ?string $eventType = null,
        ?string $endpoint = null,
        ?int $durationMs = null
    ): bool {
        // Normalize severity to valid value
        $severity = $this->normalizeSeverity($severity);

        // Check table readiness. This returns FALSE, not true: no row was
        // written, and telling the caller otherwise is how an unwritable
        // audit table came to look like a healthy one. Callers that treat
        // a false return as "escalate" now get the chance to.
        if (!$this->checkTableReady()) {
            error_log("[AUDIT_FALLBACK] {$action} on {$entityType} (ID: {$entityId}) - {$category} - {$severity} - Country: {$this->countryCode}");
            return false;
        }

        try {
            // Build changes array with country metadata (optional - for reference only)
            $changesData = $changes ?? [];
            if (!isset($changesData['_metadata'])) {
                $changesData['_metadata'] = [];
            }
            $changesData['_metadata']['country'] = $this->countryCode;
            $changesData['_metadata']['timestamp'] = date('Y-m-d H:i:s');

            // SQL - NO country_code column (doesn't exist in table), and
            // NO `timestamp` column either: that one is South-Africa-only,
            // and naming it here made every insert on a Botswana database
            // fail with "column timestamp does not exist". performed_at is
            // this table's timestamp.
            $sql = "INSERT INTO audit_logs (
                        entity_type, entity_id, action, category, severity,
                        old_value, new_value, changes,
                        performed_by_type, performed_by_id,
                        ip_address, user_agent, geo_location,
                        request_id, performed_at, event_type, endpoint, duration_ms
                    ) VALUES (
                        :entity_type, :entity_id, :action, :category, :severity,
                        :old_value, :new_value, :changes,
                        :performed_by_type, :performed_by_id,
                        :ip_address, :user_agent, :geo_location,
                        :request_id, NOW(), :event_type, :endpoint, :duration_ms
                    )";

            $stmt = $this->db->prepare($sql);
            
            // Determine performed_by_type
            $performedByType = 'admin';
            if ($performedById === null) {
                $performedByType = 'system';
            }

            $result = $stmt->execute([
                ':entity_type'       => $entityType,
                ':entity_id'         => $entityId,
                ':action'            => $action,
                ':category'          => $category,
                ':severity'          => $severity,
                ':old_value'         => $oldValue,
                ':new_value'         => $newValue,
                ':changes'           => json_encode($changesData),
                ':performed_by_type' => $performedByType,
                ':performed_by_id'   => $performedById,
                ':ip_address'        => $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent'        => $userAgent ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
                ':geo_location'      => $geoLocation,
                ':request_id'        => $requestId ?? uniqid('req_', true),
                ':event_type'        => $eventType ?? $action,
                ':endpoint'          => $endpoint ?? $_SERVER['REQUEST_URI'] ?? null,
                ':duration_ms'       => $durationMs
            ]);

            if ($result) {
                $this->logger->info([
                    'action' => $action,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'category' => $category,
                    'severity' => $severity,
                    'country' => $this->countryCode
                ]);
            }

            return $result;
            
        } catch (Throwable $e) {
            $this->logger->error([
                'error' => $e->getMessage(),
                'entity_type' => $entityType,
                'action' => $action,
                'country' => $this->countryCode
            ]);
            
            error_log("[AUDIT_FALLBACK] {$action} on {$entityType} (ID: {$entityId}) - Country: {$this->countryCode} - DB Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns logs - filters by country from session/config, not database
     * Since country isn't stored in DB, we filter by the current country context
     */
    public function getAuditLogs(int $limit = 100, array $filters = []): array
    {
        if (!$this->checkTableReady()) {
            return [];
        }

        // Note: Since country_code doesn't exist in the table,
        // we can't filter by country in the query.
        // Country context is handled at the application level via session/config.
        // The country is stored in changes JSON for reference only.

        $sql = "
            SELECT 
                al.audit_id AS id,
                al.entity_type,
                al.entity_id,
                al.action,
                al.category,
                al.severity,
                al.performed_at AS timestamp,
                al.ip_address,
                al.old_value,
                al.new_value,
                al.changes,
                al.performed_by_type,
                al.performed_by_id,
                al.event_type,
                al.endpoint,
                al.duration_ms,
                al.request_id
            FROM 
                audit_logs al
            WHERE 
                1=1
        ";

        $params = [];

        // Apply filters (excluding country - handled at application level)
        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = :entity_type";
            $params[':entity_type'] = $filters['entity_type'];
        }

        // entity_id is VARCHAR since 2026_09_16_transaction_audit_integrity.sql,
        // so this matches a swap reference as readily as a numeric row id.
        // Bound as a string deliberately -- binding it as an int here would
        // reintroduce the very type mismatch this change exists to fix.
        if (isset($filters['entity_id']) && $filters['entity_id'] !== '' && $filters['entity_id'] !== null) {
            $sql .= " AND al.entity_id = :entity_id";
            $params[':entity_id'] = (string)$filters['entity_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND al.action = :action";
            $params[':action'] = $filters['action'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND al.category = :category";
            $params[':category'] = $filters['category'];
        }

        if (!empty($filters['severity'])) {
            $severity = $this->normalizeSeverity($filters['severity']);
            $sql .= " AND al.severity = :severity";
            $params[':severity'] = $severity;
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.performed_at >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND al.performed_at <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (al.entity_type ILIKE :search OR al.action ILIKE :search OR al.category ILIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY al.performed_at DESC LIMIT :limit";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

            foreach ($params as $key => $value) {
                if (is_int($value)) {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value, PDO::PARAM_STR);
                }
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->logger->error('Audit view error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get audit logs for one specific entity.
     *
     * This used to drop $entityId on the floor and filter by entity_type
     * alone, so asking for one swap's history returned every swap's.
     */
    public function getLogsForEntity(string $entityType, int|string $entityId, int $limit = 50): array
    {
        return $this->getAuditLogs($limit, [
            'entity_type' => $entityType,
            'entity_id'   => $entityId
        ]);
    }

    /**
     * Get recent audit logs by severity
     */
    public function getLogsBySeverity(string $severity, int $limit = 50): array
    {
        $severity = $this->normalizeSeverity($severity);
        return $this->getAuditLogs($limit, ['severity' => $severity]);
    }

    /**
     * Get audit logs by category
     */
    public function getLogsByCategory(string $category, int $limit = 50): array
    {
        return $this->getAuditLogs($limit, ['category' => $category]);
    }

    /**
     * Get log count for dashboard
     */
    public function getLogCount(array $filters = []): int
    {
        if (!$this->checkTableReady()) {
            return 0;
        }

        $sql = "
            SELECT COUNT(*) as count
            FROM audit_logs al
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = :entity_type";
            $params[':entity_type'] = $filters['entity_type'];
        }

        // entity_id is VARCHAR since 2026_09_16_transaction_audit_integrity.sql,
        // so this matches a swap reference as readily as a numeric row id.
        // Bound as a string deliberately -- binding it as an int here would
        // reintroduce the very type mismatch this change exists to fix.
        if (isset($filters['entity_id']) && $filters['entity_id'] !== '' && $filters['entity_id'] !== null) {
            $sql .= " AND al.entity_id = :entity_id";
            $params[':entity_id'] = (string)$filters['entity_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND al.action = :action";
            $params[':action'] = $filters['action'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND al.category = :category";
            $params[':category'] = $filters['category'];
        }

        if (!empty($filters['severity'])) {
            $severity = $this->normalizeSeverity($filters['severity']);
            $sql .= " AND al.severity = :severity";
            $params[':severity'] = $severity;
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.performed_at >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND al.performed_at <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                if (is_int($value)) {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value, PDO::PARAM_STR);
                }
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (Throwable $e) {
            $this->logger->error('Log count error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get unique categories for filtering
     */
    public function getCategories(): array
    {
        if (!$this->checkTableReady()) {
            return [];
        }

        $sql = "
            SELECT DISTINCT category
            FROM audit_logs
            ORDER BY category ASC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $this->logger->error('Get categories error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get unique actions for filtering
     */
    public function getActions(): array
    {
        if (!$this->checkTableReady()) {
            return [];
        }

        $sql = "
            SELECT DISTINCT action
            FROM audit_logs
            ORDER BY action ASC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $this->logger->error('Get actions error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get unique entity types for filtering
     */
    public function getEntityTypes(): array
    {
        if (!$this->checkTableReady()) {
            return [];
        }

        $sql = "
            SELECT DISTINCT entity_type
            FROM audit_logs
            ORDER BY entity_type ASC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $this->logger->error('Get entity types error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Log that someone viewed the audit trail
     */
    public function logAuditView(array $filters, int $performedById, string $ip, string $userAgent): bool
    {
        return $this->recordLog(
            'audit_trail',
            null,
            'VIEW_AUDIT_TRAIL',
            'security',
            'INFO',
            null,
            json_encode(['filters' => $filters]),
            $performedById,
            $ip,
            $userAgent
        );
    }

    /**
     * Audit logs are never deleted by the application.
     *
     * This used to DELETE rows older than 90 days. AML record-keeping
     * requires at least 5 years, PCI DSS at least 12 months, and deleting
     * any row breaks the audit hash chain. The database now refuses
     * DELETE on audit_logs (2026_09_19_audit_chain_enforced.sql), so this
     * method only records that something asked for it.
     */
    public function cleanOldLogs(int $daysToKeep = 90): int
    {
        $this->logger->warning("Refused request to delete audit logs older than {$daysToKeep} days: audit logs are append-only and retained for at least 5 years");
        return 0;
    }

    /**
     * Get current country code (from session/config)
     */
    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    /**
     * Set country code
     */
    public function setCountryCode(string $countryCode): void
    {
        $this->countryCode = strtoupper($countryCode);
        $this->logger->info('Country code updated to: ' . $this->countryCode);
    }
}
