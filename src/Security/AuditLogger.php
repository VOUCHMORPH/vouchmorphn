<?php
// src/Security/AuditLogger.php

namespace Security;

/**
 * Immutable audit logging for compliance
 * ISO 27001:2022 Annex A.16 (Information Security Incident Management)
 */
class AuditLogger
{
    private \PDO $pdo;
    private string $logFile;
    private string $encryptionKey;
    
    public function __construct()
    {
        $this->pdo = new \PDO(getenv('DATABASE_URL'));
        $this->logFile = getenv('AUDIT_LOG_PATH') ?: '/var/log/vouchmorph/audit.log';
        $this->encryptionKey = getenv('AUDIT_ENCRYPTION_KEY') ?: bin2hex(random_bytes(32));
        
        $this->ensureLogDirectory();
    }
    
    private function ensureLogDirectory(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }
    
    /**
     * Log authentication event (non-repudiation)
     */
    public function logAuthentication(string $clientId, string $result, array $details = []): void
    {
        $entry = [
            'event_type' => 'AUTHENTICATION',
            'event_id' => bin2hex(random_bytes(16)),
            'client_id' => $clientId,
            'result' => $result,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'timestamp' => date('c'),
            'signature' => null,
            'details' => $details
        ];
        
        // Sign the entry for non-repudiation
        $entry['signature'] = $this->signEntry($entry);
        
        $this->write($entry);
        
        // Store in database for querying
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log (
                event_id, event_type, client_id, result, ip_address, user_agent, signature, details, created_at
            ) VALUES (
                :id, :type, :client, :result, :ip, :ua, :sig, :details::jsonb, NOW()
            )
        ");
        
        $stmt->execute([
            'id' => $entry['event_id'],
            'type' => $entry['event_type'],
            'client' => $clientId,
            'result' => $result,
            'ip' => $entry['ip_address'],
            'ua' => $entry['user_agent'],
            'sig' => $entry['signature'],
            'details' => json_encode($details)
        ]);
    }
    
    /**
     * Log API request
     */
    public function logApiRequest(string $requestId, string $endpoint, string $method, array $payload = [], array $response = []): void
    {
        // Remove sensitive data from logs
        $this->sanitizePayload($payload);
        $this->sanitizePayload($response);
        
        $entry = [
            'event_type' => 'API_REQUEST',
            'event_id' => $requestId,
            'endpoint' => $endpoint,
            'method' => $method,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'payload_hash' => hash('sha3-512', json_encode($payload)),
            'response_hash' => hash('sha3-512', json_encode($response)),
            'duration_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2),
            'timestamp' => date('c'),
            'signature' => null
        ];
        
        $entry['signature'] = $this->signEntry($entry);
        
        $this->write($entry);
        
        // Async store to database
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log (
                event_id, event_type, endpoint, method, ip_address, user_agent, 
                payload_hash, response_hash, duration_ms, signature, created_at
            ) VALUES (
                :id, :type, :endpoint, :method, :ip, :ua, :phash, :rhash, :duration, :sig, NOW()
            )
        ");
        
        $stmt->execute([
            'id' => $requestId,
            'type' => 'API_REQUEST',
            'endpoint' => $endpoint,
            'method' => $method,
            'ip' => $entry['ip_address'],
            'ua' => $entry['user_agent'],
            'phash' => $entry['payload_hash'],
            'rhash' => $entry['response_hash'],
            'duration' => $entry['duration_ms'],
            'sig' => $entry['signature']
        ]);
    }
    
    /**
     * Log security incident
     */
    public function logSecurityIncident(string $type, string $severity, array $details = []): void
    {
        $entry = [
            'event_type' => 'SECURITY_INCIDENT',
            'event_id' => bin2hex(random_bytes(16)),
            'incident_type' => $type,
            'severity' => $severity,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'timestamp' => date('c'),
            'details' => $details,
            'signature' => null
        ];
        
        $entry['signature'] = $this->signEntry($entry);
        
        $this->write($entry);
        
        // Alert for high severity incidents
        if ($severity === 'HIGH' || $severity === 'CRITICAL') {
            $this->sendAlert($type, $severity, $details);
        }
        
        // Store in database
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log (
                event_id, event_type, incident_type, severity, ip_address, details, signature, created_at
            ) VALUES (
                :id, :type, :incident, :severity, :ip, :details::jsonb, :sig, NOW()
            )
        ");
        
        $stmt->execute([
            'id' => $entry['event_id'],
            'type' => 'SECURITY_INCIDENT',
            'incident' => $type,
            'severity' => $severity,
            'ip' => $entry['ip_address'],
            'details' => json_encode($details),
            'sig' => $entry['signature']
        ]);
    }
    
    /**
     * Sign log entry for non-repudiation
     */
    private function signEntry(array $entry): string
    {
        // Remove signature field before signing
        $signatureFields = $entry;
        unset($signatureFields['signature']);
        
        $dataToSign = json_encode($signatureFields);
        return hash_hmac('sha3-512', $dataToSign, $this->encryptionKey);
    }
    
    /**
     * Remove sensitive data from logs
     */
    private function sanitizePayload(array &$payload): void
    {
        $sensitiveFields = ['pin', 'password', 'credentials', 'token', 'api_key', 'secret', 'private_key'];
        
        foreach ($sensitiveFields as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = '[REDACTED]';
            }
        }
    }
    
    /**
     * Write to immutable log (append-only)
     */
    private function write(array $entry): void
    {
        $line = json_encode($entry) . "\n";
        
        // Write to file (append-only, immutable)
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        
        // Also write to syslog for central collection
        openlog('VouchMorph', LOG_PID | LOG_CONS, LOG_AUTH);
        syslog(LOG_INFO, '[AUDIT] ' . $entry['event_type'] . ': ' . $entry['event_id']);
        closelog();
    }
    
    /**
     * Send alert for security incidents
     */
    private function sendAlert(string $type, string $severity, array $details): void
    {
        // Send to configured alert channel (Slack, PagerDuty, Email)
        $webhook = getenv('ALERT_WEBHOOK_URL');
        if ($webhook) {
            $ch = curl_init($webhook);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'title' => "[{$severity}] Security Incident: {$type}",
                'description' => json_encode($details),
                'timestamp' => date('c')
            ]));
            curl_exec($ch);
            curl_close($ch);
        }
    }
    
    /**
     * Generate compliance report for regulators
     */
    public function generateComplianceReport(\DateTime $start, \DateTime $end): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                DATE(created_at) as date,
                event_type,
                COUNT(*) as count,
                COUNT(DISTINCT client_id) as unique_clients,
                COUNT(DISTINCT ip_address) as unique_ips
            FROM audit_log
            WHERE created_at BETWEEN :start AND :end
            GROUP BY DATE(created_at), event_type
            ORDER BY date DESC
        ");
        
        $stmt->execute([
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s')
        ]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
