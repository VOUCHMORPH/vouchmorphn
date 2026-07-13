<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Throwable;
use Core\Database\DBConnection;

/**
 * Service class for country-specific audit logging.
 * Uses a simple fallback logger if no logger is provided.
 * MATCHES ACTUAL audit_logs TABLE SCHEMA
 */
class AuditTrailService
{
    private PDO $db;
    private array $config;
    private $logger = null;
    private string $countryCode;
    private bool $tableReady = false;

    public function __construct(
        PDO $db,
        array $config,
        $logger = null,
        string $countryCode = 'BW'
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
        
        $this->countryCode = $countryCode;
        $this->tableReady = $this->checkTableReady();
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
     * Matches the actual audit_logs table schema:
     * audit_id, audit_uuid, entity_type, entity_id, action, category, severity,
     * old_value, new_value, changes, performed_by_type, performed_by_id,
     * ip_address, user_agent, geo_location, request_id, performed_at,
     * integrity_hash, timestamp, event_type, client_id, endpoint, duration_ms
     */
    public function recordLog(
        string $entityType,
        ?int $entityId,
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
        // If table is not ready, log to error_log as fallback
        if (!$this->tableReady) {
            error_log("[AUDIT_FALLBACK] {$action} on {$entityType} (ID: {$entityId}) - {$category} - {$severity}");
            return true;
        }

        try {
            $sql = "INSERT INTO audit_logs (
                        entity_type, entity_id, action, category, severity,
                        old_value, new_value, changes,
                        performed_by_type, performed_by_id,
                        ip_address, user_agent, geo_location,
                        request_id, performed_at, event_type, endpoint, duration_ms,
                        country_code, timestamp
                    ) VALUES (
                        :entity_type, :entity_id, :action, :category, :severity,
                        :old_value, :new_value, :changes,
                        :performed_by_type, :performed_by_id,
                        :ip_address, :user_agent, :geo_location,
                        :request_id, NOW(), :event_type, :endpoint, :duration_ms,
                        :country_code, NOW()
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
                ':changes'           => $changes ? json_encode($changes) : null,
                ':performed_by_type' => $performedByType,
                ':performed_by_id'   => $performedById,
                ':ip_address'        => $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent'        => $userAgent ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
                ':geo_location'      => $geoLocation,
                ':request_id'        => $requestId ?? uniqid('req_', true),
                ':event_type'        => $eventType ?? $action,
                ':endpoint'          => $endpoint ?? $_SERVER['REQUEST_URI'] ?? null,
                ':duration_ms'       => $durationMs,
                ':country_code'      => $this->countryCode
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
                'action' => $action
            ]);
            
            // Fallback to error_log
            error_log("[AUDIT_FALLBACK] {$action} on {$entityType} (ID: {$entityId}) - DB Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns logs ONLY for the admin's currently loaded country.
     * Matches actual table schema
     */
    public function getAuditLogs(int $limit = 100, array $filters = []): array
    {
        if (!$this->tableReady) {
            return [];
        }

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
                al.country_code,
                al.event_type,
                al.endpoint,
                al.duration_ms,
                al.request_id
            FROM 
                audit_logs al
            WHERE 
                al.country_code = :country_code
        ";

        $params = [':country_code' => $this->countryCode];

        // Apply filters
        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = :entity_type";
            $params[':entity_type'] = $filters['entity_type'];
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
            $sql .= " AND al.severity = :severity";
            $params[':severity'] = $filters['severity'];
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
     * Get audit logs by entity type
     */
    public function getLogsForEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        return $this->getAuditLogs($limit, [
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);
    }

    /**
     * Get recent audit logs by severity
     */
    public function getLogsBySeverity(string $severity, int $limit = 50): array
    {
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
        if (!$this->tableReady) {
            return 0;
        }

        $sql = "
            SELECT COUNT(*) as count
            FROM audit_logs al
            WHERE al.country_code = :country_code
        ";

        $params = [':country_code' => $this->countryCode];

        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = :entity_type";
            $params[':entity_type'] = $filters['entity_type'];
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
            $sql .= " AND al.severity = :severity";
            $params[':severity'] = $filters['severity'];
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
        if (!$this->tableReady) {
            return [];
        }

        $sql = "
            SELECT DISTINCT category
            FROM audit_logs
            WHERE country_code = :country_code
            ORDER BY category ASC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':country_code' => $this->countryCode]);
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
        if (!$this->tableReady) {
            return [];
        }

        $sql = "
            SELECT DISTINCT action
            FROM audit_logs
            WHERE country_code = :country_code
            ORDER BY action ASC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':country_code' => $this->countryCode]);
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
        if (!$this->tableReady) {
            return [];
        }

        $sql = "
            SELECT DISTINCT entity_type
            FROM audit_logs
            WHERE country_code = :country_code
            ORDER BY entity_type ASC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':country_code' => $this->countryCode]);
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
     * Clean up old audit logs (retention policy)
     */
    public function cleanOldLogs(int $daysToKeep = 90): int
    {
        if (!$this->tableReady) {
            return 0;
        }

        $sql = "
            DELETE FROM audit_logs
            WHERE performed_at < NOW() - INTERVAL :days DAY
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':days', $daysToKeep, PDO::PARAM_INT);
            $stmt->execute();
            $deleted = $stmt->rowCount();
            $this->logger->info("Cleaned {$deleted} audit logs older than {$daysToKeep} days");
            return $deleted;
        } catch (Throwable $e) {
            $this->logger->error('Clean old logs error: ' . $e->getMessage());
            return 0;
        }
    }
}
