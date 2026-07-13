<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Throwable;
use Core\Database\DBConnection;

/**
 * Service class for country-specific audit logging.
 * Uses a simple fallback logger if no logger is provided.
 */
class AuditTrailService
{
    private PDO $db;
    private array $config;
    private $logger = null;  // Can be any logger with info() and error() methods
    private string $countryCode;

    public function __construct(
        PDO $db,
        array $config,
        $logger = null,  // Accept any logger, or null for fallback
        string $countryCode = 'BW'
    ) {
        $this->db = $db;
        $this->config = $config;
        
        // If no logger provided, create a simple fallback logger
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

        // Verify audit_logs table exists
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM audit_logs LIMIT 1");
            $stmt->execute();
        } catch (Throwable $e) {
            $this->logger->warning('Audit table may not exist: ' . $e->getMessage());
            $this->createAuditTableIfMissing();
        }
    }

    /**
     * Create audit_logs table if it doesn't exist
     */
    private function createAuditTableIfMissing(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS audit_logs (
                audit_id SERIAL PRIMARY KEY,
                entity VARCHAR(255) NOT NULL,
                entity_id INTEGER,
                action VARCHAR(100) NOT NULL,
                category VARCHAR(100) NOT NULL,
                severity VARCHAR(20) DEFAULT 'INFO',
                old_value TEXT,
                new_value TEXT,
                performed_by INTEGER,
                ip_address VARCHAR(45),
                user_agent TEXT,
                geo_location VARCHAR(100),
                performed_at TIMESTAMP DEFAULT NOW(),
                immutable BOOLEAN DEFAULT TRUE,
                country_code VARCHAR(10),
                created_at TIMESTAMP DEFAULT NOW()
            );

            CREATE INDEX IF NOT EXISTS idx_audit_logs_performed_at ON audit_logs(performed_at DESC);
            CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity, entity_id);
            CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action);
            CREATE INDEX IF NOT EXISTS idx_audit_logs_category ON audit_logs(category);
            CREATE INDEX IF NOT EXISTS idx_audit_logs_country ON audit_logs(country_code);
        ";

        try {
            $this->db->exec($sql);
            $this->logger->info('audit_logs table created successfully');
        } catch (Throwable $e) {
            $this->logger->error('Failed to create audit_logs table: ' . $e->getMessage());
        }
    }

    /**
     * Records an action for the local country admin.
     */
    public function recordLog(
        string $entity,
        ?int $entityId,
        string $action,
        string $category,
        string $severity = 'INFO',
        ?string $oldValue = null,
        ?string $newValue = null,
        ?int $performedBy = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $geoLocation = null,
        bool $immutable = true
    ): bool {
        $sql = "INSERT INTO audit_logs (
                    entity, entity_id, action, category, severity, old_value, new_value,
                    performed_by, ip_address, user_agent, geo_location, performed_at, immutable,
                    country_code, created_at
                ) VALUES (
                    :entity, :entity_id, :action, :category, :severity, :old_value, :new_value,
                    :performed_by, :ip_address, :user_agent, :geo_location, NOW(), :immutable,
                    :country_code, NOW()
                )";

        try {
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':entity'       => $entity,
                ':entity_id'    => $entityId,
                ':action'       => $action,
                ':category'     => $category,
                ':severity'     => $severity,
                ':old_value'    => $oldValue,
                ':new_value'    => $newValue,
                ':performed_by' => $performedBy,
                ':ip_address'   => $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent'   => $userAgent ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
                ':geo_location' => $geoLocation,
                ':immutable'    => $immutable ? 1 : 0,
                ':country_code' => $this->countryCode
            ]);

            if ($result) {
                $this->logger->info([
                    'action' => $action,
                    'entity' => $entity,
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
                'entity' => $entity,
                'action' => $action
            ]);
            return false;
        }
    }

    /**
     * Returns logs ONLY for the admin's currently loaded country.
     */
    public function getAuditLogs(int $limit = 100, array $filters = []): array
    {
        $sql = "
            SELECT 
                al.audit_id AS id,
                COALESCE(a.username, 'Admin ID: ' || al.performed_by) AS username,
                al.action,
                al.category,
                al.severity,
                al.performed_at AS timestamp,
                al.ip_address,
                al.old_value,
                al.new_value,
                al.entity,
                al.entity_id,
                al.country_code
            FROM 
                audit_logs al
            LEFT JOIN 
                admins a ON al.performed_by = a.admin_id
            WHERE 
                al.country_code = :country_code
        ";

        $params = [':country_code' => $this->countryCode];

        // Apply filters
        if (!empty($filters['entity'])) {
            $sql .= " AND al.entity = :entity";
            $params[':entity'] = $filters['entity'];
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
            $sql .= " AND (al.entity ILIKE :search OR al.action ILIKE :search OR al.category ILIKE :search)";
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
     * Get audit logs by entity
     */
    public function getLogsForEntity(string $entity, int $entityId, int $limit = 50): array
    {
        return $this->getAuditLogs($limit, [
            'entity' => $entity,
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
        $sql = "
            SELECT COUNT(*) as count
            FROM audit_logs al
            WHERE al.country_code = :country_code
        ";

        $params = [':country_code' => $this->countryCode];

        if (!empty($filters['entity'])) {
            $sql .= " AND al.entity = :entity";
            $params[':entity'] = $filters['entity'];
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
     * Log that someone viewed the audit trail
     */
    public function logAuditView(array $filters, int $performedBy, string $ip, string $userAgent): bool
    {
        return $this->recordLog(
            'audit_trail',
            null,
            'VIEW_AUDIT_TRAIL',
            'security',
            'INFO',
            null,
            json_encode(['filters' => $filters]),
            $performedBy,
            $ip,
            $userAgent
        );
    }

    /**
     * Clean up old audit logs (retention policy)
     */
    public function cleanOldLogs(int $daysToKeep = 90): int
    {
        $sql = "
            DELETE FROM audit_logs
            WHERE performed_at < NOW() - INTERVAL :days DAY
            AND immutable = false
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
