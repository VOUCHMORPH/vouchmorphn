<?php
declare(strict_types=1);

namespace Domain\Services;

use Core\Database\DBConnection;
use Psr\Log\LoggerInterface;

/**
 * Audit Trail Service - Unified for ALL Countries
 * 
 * This service handles logging of all system events across all countries
 * Uses the standard audit_logs table schema
 */
class AuditTrailService
{
    private $db;
    private $config;
    private $logger;
    private $country;
    private $tableName = 'audit_logs';

    /**
     * Constructor
     * 
     * @param object $db Database connection
     * @param array $config Configuration array
     * @param LoggerInterface|null $logger Logger instance
     * @param string $country Country code (e.g., 'BW', 'ZA', 'KE')
     */
    public function __construct($db, array $config = [], ?LoggerInterface $logger = null, string $country = 'BW')
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->country = strtoupper($country);
    }

    /**
     * Record an audit log entry - Unified for ALL Countries
     * 
     * @param string $entityType Type of entity (e.g., 'admin_login', 'user', 'transaction')
     * @param int|null $entityId ID of the entity
     * @param string $action Action performed (e.g., 'LOGIN_SUCCESS', 'USER_CREATED')
     * @param string $category Category (e.g., 'security', 'admin', 'system')
     * @param string $severity Severity (e.g., 'INFO', 'WARNING', 'ERROR', 'CRITICAL')
     * @param string|null $oldValue Old value (JSON encoded)
     * @param string|null $newValue New value (JSON encoded)
     * @param int|null $performedById ID of the user who performed the action
     * @param string|null $ipAddress IP address of the client
     * @param string|null $userAgent User agent of the client
     * @param string|null $geoLocation Geo location data
     * @param string|null $requestId Request ID for tracking
     * @param array|null $changes Array of changes (will be JSON encoded)
     * @param int|null $durationMs Duration in milliseconds
     * @param string|null $endpoint Endpoint being accessed
     * @param string|null $eventType Event type
     * @param int|null $clientId Client ID
     * @return bool Success status
     */
    public function recordLog(
        string $entityType,
        ?int $entityId,
        string $action,
        string $category = 'system',
        string $severity = 'INFO',
        ?string $oldValue = null,
        ?string $newValue = null,
        ?int $performedById = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $geoLocation = null,
        ?string $requestId = null,
        ?array $changes = null,
        ?int $durationMs = null,
        ?string $endpoint = null,
        ?string $eventType = null,
        ?int $clientId = null
    ): bool {
        // Validate and sanitize IP address for PostgreSQL inet type
        $ipAddress = $this->sanitizeIpAddress($ipAddress);
        
        try {
            // Generate UUID for audit trail
            $auditUuid = $this->generateUuid();
            
            // Get current timestamp
            $performedAt = date('Y-m-d H:i:s');
            
            // Encode changes if provided
            $changesJson = $changes !== null ? json_encode($changes) : null;
            
            // Add country context to all logs
            $metadata = [
                'country' => $this->country,
                'environment' => $this->getEnvironment(),
                'timestamp' => $performedAt
            ];
            
            // Merge with changes if present
            if ($changesJson) {
                $changesData = json_decode($changesJson, true) ?? [];
                $changesData['_metadata'] = $metadata;
                $changesJson = json_encode($changesData);
            } else {
                $changesJson = json_encode(['_metadata' => $metadata]);
            }
            
            // Build the query with correct column names
            $sql = "INSERT INTO {$this->tableName} (
                audit_uuid,
                entity_type,
                entity_id,
                action,
                category,
                severity,
                old_value,
                new_value,
                changes,
                performed_by_type,
                performed_by_id,
                ip_address,
                user_agent,
                geo_location,
                request_id,
                performed_at,
                timestamp,
                event_type,
                client_id,
                endpoint,
                duration_ms,
                integrity_hash
            ) VALUES (
                :audit_uuid,
                :entity_type,
                :entity_id,
                :action,
                :category,
                :severity,
                :old_value,
                :new_value,
                :changes,
                :performed_by_type,
                :performed_by_id,
                :ip_address,
                :user_agent,
                :geo_location,
                :request_id,
                :performed_at,
                :performed_at,
                :event_type,
                :client_id,
                :endpoint,
                :duration_ms,
                :integrity_hash
            )";

            // Determine performed_by_type (admin, user, system)
            $performedByType = 'system';
            if ($performedById !== null) {
                // Check if it's an admin or regular user
                $performedByType = $this->getPerformedByType($performedById);
            }

            // Generate integrity hash
            $integrityData = $auditUuid . $entityType . $entityId . $action . $performedAt . $this->country;
            $integrityHash = hash('sha256', $integrityData);

            $stmt = $this->db->prepare($sql);
            
            $params = [
                ':audit_uuid' => $auditUuid,
                ':entity_type' => $entityType,
                ':entity_id' => $entityId,
                ':action' => $action,
                ':category' => $category,
                ':severity' => $severity,
                ':old_value' => $oldValue,
                ':new_value' => $newValue,
                ':changes' => $changesJson,
                ':performed_by_type' => $performedByType,
                ':performed_by_id' => $performedById,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
                ':geo_location' => $geoLocation,
                ':request_id' => $requestId ?? $this->generateRequestId(),
                ':performed_at' => $performedAt,
                ':event_type' => $eventType ?? $category,
                ':client_id' => $clientId,
                ':endpoint' => $endpoint ?? ($_SERVER['REQUEST_URI'] ?? null),
                ':duration_ms' => $durationMs,
                ':integrity_hash' => $integrityHash
            ];

            $result = $stmt->execute($params);

            if ($this->logger) {
                $this->logger->debug('Audit log recorded', [
                    'country' => $this->country,
                    'entity_type' => $entityType,
                    'action' => $action,
                    'audit_uuid' => $auditUuid
                ]);
            }

            return $result;

        } catch (\Throwable $e) {
            // Log error but don't fail the main operation
            $errorMsg = "Failed to record audit log for country {$this->country}: " . $e->getMessage();
            
            if ($this->logger) {
                $this->logger->error($errorMsg, [
                    'country' => $this->country,
                    'entity_type' => $entityType,
                    'action' => $action,
                    'exception' => $e
                ]);
            } else {
                error_log($errorMsg);
            }
            
            // Try to record in a fallback log file with country context
            $this->fallbackLog($entityType, $entityId, $action, $category, $severity, $ipAddress);
            
            return false;
        }
    }

    /**
     * Sanitize IP address for PostgreSQL inet type
     * Takes only the first IP if multiple are present
     */
    private function sanitizeIpAddress(?string $ip): ?string
    {
        if (empty($ip)) {
            return null;
        }
        
        // Handle X-Forwarded-For with multiple IPs
        if (strpos($ip, ',') !== false) {
            $ips = explode(',', $ip);
            $ip = trim($ips[0]);
        }
        
        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        
        return $ip;
    }

    /**
     * Determine performed_by_type based on ID
     */
    private function getPerformedByType(int $id): string
    {
        try {
            // Check if it's an admin
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM admins WHERE admin_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                return 'admin';
            }
            
            // Check if it's a regular user
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE user_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                return 'user';
            }
            
            return 'system';
        } catch (\Throwable $e) {
            return 'system';
        }
    }

    /**
     * Get current environment
     */
    private function getEnvironment(): string
    {
        return $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
    }

    /**
     * Fallback logging when database fails
     */
    private function fallbackLog(
        string $entityType,
        ?int $entityId,
        string $action,
        string $category,
        string $severity,
        ?string $ipAddress
    ): void {
        $logEntry = sprintf(
            "[%s] [%s] [%s] [%s] [%s] [ID:%s] [IP:%s] [Country:%s]\n",
            date('Y-m-d H:i:s'),
            $severity,
            $category,
            $entityType,
            $action,
            $entityId ?? 'NULL',
            $ipAddress ?? 'UNKNOWN',
            $this->country
        );
        
        $logFile = __DIR__ . '/../../../logs/audit_fallback_' . strtolower($this->country) . '.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Generate a UUID v4
     */
    private function generateUuid(): string
    {
        try {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        } catch (\Exception $e) {
            return uniqid() . '-' . bin2hex(random_bytes(8));
        }
    }

    /**
     * Generate a request ID
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * Get audit logs with filters - Works for ALL Countries
     */
    public function getLogs(
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $action = null,
        ?string $category = null,
        ?string $severity = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $country = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        try {
            $sql = "SELECT * FROM {$this->tableName} WHERE 1=1";
            $params = [];

            if ($entityType !== null) {
                $sql .= " AND entity_type = :entity_type";
                $params[':entity_type'] = $entityType;
            }

            if ($entityId !== null) {
                $sql .= " AND entity_id = :entity_id";
                $params[':entity_id'] = $entityId;
            }

            if ($action !== null) {
                $sql .= " AND action = :action";
                $params[':action'] = $action;
            }

            if ($category !== null) {
                $sql .= " AND category = :category";
                $params[':category'] = $category;
            }

            if ($severity !== null) {
                $sql .= " AND severity = :severity";
                $params[':severity'] = $severity;
            }

            if ($dateFrom !== null) {
                $sql .= " AND performed_at >= :date_from";
                $params[':date_from'] = $dateFrom;
            }

            if ($dateTo !== null) {
                $sql .= " AND performed_at <= :date_to";
                $params[':date_to'] = $dateTo;
            }

            if ($country !== null) {
                // Check if country is stored in changes metadata
                $sql .= " AND changes LIKE :country_pattern";
                $params[':country_pattern'] = '%"country":"' . strtoupper($country) . '"%';
            } elseif ($this->country !== null) {
                // Default to current country if not specified
                $sql .= " AND changes LIKE :country_pattern";
                $params[':country_pattern'] = '%"country":"' . $this->country . '"%';
            }

            $sql .= " ORDER BY performed_at DESC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;

            $stmt = $this->db->prepare($sql);
            
            foreach ($params as $key => $value) {
                if ($key === ':limit' || $key === ':offset') {
                    $stmt->bindValue($key, $value, \PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            error_log("Failed to get audit logs for country {$this->country}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get audit log count for a specific country
     */
    public function getLogCount(
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $action = null,
        ?string $country = null
    ): int {
        try {
            $sql = "SELECT COUNT(*) FROM {$this->tableName} WHERE 1=1";
            $params = [];

            if ($entityType !== null) {
                $sql .= " AND entity_type = :entity_type";
                $params[':entity_type'] = $entityType;
            }

            if ($entityId !== null) {
                $sql .= " AND entity_id = :entity_id";
                $params[':entity_id'] = $entityId;
            }

            if ($action !== null) {
                $sql .= " AND action = :action";
                $params[':action'] = $action;
            }

            if ($country !== null) {
                $sql .= " AND changes LIKE :country_pattern";
                $params[':country_pattern'] = '%"country":"' . strtoupper($country) . '"%';
            } elseif ($this->country !== null) {
                $sql .= " AND changes LIKE :country_pattern";
                $params[':country_pattern'] = '%"country":"' . $this->country . '"%';
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();

        } catch (\Throwable $e) {
            error_log("Failed to get audit log count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get audit logs by country
     */
    public function getLogsByCountry(string $country, int $limit = 100, int $offset = 0): array
    {
        return $this->getLogs(null, null, null, null, null, null, null, $country, $limit, $offset);
    }

    /**
     * Get audit logs by user
     */
    public function getLogsByUser(int $userId, int $limit = 100, int $offset = 0): array
    {
        return $this->getLogs(null, null, null, null, null, null, null, null, $limit, $offset);
    }

    /**
     * Clean up old audit logs
     */
    public function cleanOldLogs(int $daysToKeep = 90): int
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysToKeep days"));
            $sql = "DELETE FROM {$this->tableName} WHERE performed_at < :cutoff_date";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':cutoff_date' => $cutoffDate]);
            $deletedCount = $stmt->rowCount();
            
            error_log("Cleaned {$deletedCount} old audit logs (older than {$daysToKeep} days)");
            return $deletedCount;

        } catch (\Throwable $e) {
            error_log("Failed to clean old audit logs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get audit statistics by country
     */
    public function getStatisticsByCountry(?string $country = null): array
    {
        try {
            $countryFilter = $country ?? $this->country;
            $sql = "
                SELECT 
                    category,
                    severity,
                    action,
                    COUNT(*) as count,
                    DATE(performed_at) as date
                FROM {$this->tableName}
                WHERE changes LIKE :country_pattern
                GROUP BY category, severity, action, DATE(performed_at)
                ORDER BY date DESC, count DESC
                LIMIT 100
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':country_pattern' => '%"country":"' . strtoupper($countryFilter) . '"%']);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            error_log("Failed to get audit statistics: " . $e->getMessage());
            return [];
        }
    }
}
