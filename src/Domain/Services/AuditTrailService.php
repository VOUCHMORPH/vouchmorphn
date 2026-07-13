<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Throwable;
use Core\Database\DBConnection;
// FIXED: Use the actual Logger class
use Application\Utils\Logger;

/**
 * Service class for country-specific audit logging.
 */
class AuditTrailService
{
    private PDO $db;
    private array $config;
    private ?Logger $logger = null;
    private string $countryCode;

    public function __construct(
        PDO $db,
        array $config,
        ?Logger $logger = null,
        string $countryCode = 'BW'
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger ?? new Logger();
        $this->countryCode = $countryCode;

        // Verify audit_logs table exists
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM audit_logs LIMIT 1");
            $stmt->execute();
        } catch (Throwable $e) {
            error_log("[AuditTrailService] WARNING: audit_logs table may not exist: " . $e->getMessage());
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
            error_log("[AuditTrailService] audit_logs table created successfully");
        } catch (Throwable $e) {
            error_log("[AuditTrailService] Failed to create audit_logs table: " . $e->getMessage());
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

            if ($result && $this->logger) {
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
            error_log("[AuditTrailService] Audit Log Failure: " . $e->getMessage());
            if ($this->logger) {
                $this->logger->error([
                    'error' => $e->getMessage(),
                    'entity' => $entity,
                    'action' => $action
                ]);
            }
            return false;
        }
    }

    // ... rest of the class remains the same ...
}
