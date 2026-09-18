<?php

declare(strict_types=1);

namespace Infrastructure\SMS;

use PDO;
use Exception;

/**
 * SMS Notification Service
 * Handles all SMS communications with customers
 * 
 * TELCO-AWARE ROUTING: resolves phone numbers to specific telcos
 * (Cazacom, Mascom, Orange) based on prefix, using communication.json
 */
class SmsNotificationService
{
    private PDO $db;
    private array $config;
    private array $gatewayCache = [];
    
    private const LOG_FILE = '/tmp/vouchmorph_sms_service.log';
    
    public function __construct(PDO $db, array $config = [])
    {
        $this->db = $db;
        $this->config = $config; // full communication.json: {sms_gateway, telcos}
        $this->gatewayCache = []; // no longer built at construction time — built per-send, per-telco

        $this->ensureSmsLogsTable();
    }
    
    /**
     * Match a phone number to its telco by prefix, per communication.json's
     * routing table. Returns null if no enabled telco matches — e.g. a
     * Mascom/Orange number today, since only Cazacom has real credentials.
     */
    private function resolveTelcoForPhone(string $phoneNumber): ?array
    {
        $telcos = $this->config['telcos'] ?? [];
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Strip Botswana country code (267) if present, to compare against
        // the two-digit local prefixes telcos are keyed by.
        if (str_starts_with($digits, '267')) {
            $digits = substr($digits, 3);
        }
        $localPrefix = substr($digits, 0, 2);

        foreach ($telcos as $telcoKey => $telco) {
            if (empty($telco['enabled']) || empty($telco['sms_enabled'])) {
                continue; // e.g. mascom/orange — present in config but not live
            }
            if (in_array($localPrefix, $telco['prefixes'] ?? [], true)) {
                return [$telcoKey, $telco];
            }
        }

        return null;
    }

    /**
     * Translate communication.json's flat telco shape into the nested shape
     * SmsGatewayClient::validateConfiguration() actually requires.
     */
    private function normalizeTelcoConfig(string $telcoKey, array $telco): array
    {
        return [
            'provider' => $telcoKey,
            'base_url' => $telco['base_url'] ?? '',
            'api_key_ref' => $telco['api_key_env'] ?? '',
            'sender' => $telco['sender_id'] ?? null,
            'timeout' => $telco['timeout'] ?? 30,
            'endpoints' => [
                'send' => $telco['endpoint'] ?? '',
            ],
            'payload_template' => $telco['payload_template'] ?? [],
            'authentication' => [
                'type' => 'header',
                'key' => $telco['api_key_header'] ?? 'X-API-Key',
            ],
            'response_mappings' => [
                'message_id' => $telco['response_message_id_path'] ?? 'message_id',
                'status' => $telco['response_status_path'] ?? 'status',
            ],
        ];
    }

    /**
     * Get (or lazily build) a gateway client for whichever telco this phone
     * number routes to. One instance per telco is cached for the lifetime
     * of this service instance.
     */
    private function getGatewayForPhone(string $phoneNumber): ?SmsGatewayClient
    {
        $match = $this->resolveTelcoForPhone($phoneNumber);
        if ($match === null) {
            $this->log("No enabled telco matches phone: {$phoneNumber}");
            return null;
        }

        [$telcoKey, $telco] = $match;

        if (isset($this->gatewayCache[$telcoKey])) {
            return $this->gatewayCache[$telcoKey];
        }

        try {
            $normalized = $this->normalizeTelcoConfig($telcoKey, $telco);
            $gateway = new SmsGatewayClient($normalized);
            $this->gatewayCache[$telcoKey] = $gateway;
            $this->log("SmsGatewayClient initialized for telco: {$telcoKey}");
            return $gateway;
        } catch (Exception $e) {
            $this->log("Failed to initialize gateway for {$telcoKey}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Ensure SMS logs table exists
     */
    private function ensureSmsLogsTable(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS sms_logs (
                    id BIGSERIAL PRIMARY KEY,
                    phone_number VARCHAR(20) NOT NULL,
                    message TEXT,
                    code_sent VARCHAR(10),
                    amount NUMERIC(12,2),
                    status VARCHAR(20) DEFAULT 'PENDING',
                    message_id VARCHAR(100),
                    error_message TEXT,
                    delivery_report JSON,
                    sent_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");

            // delivery_report was missing from the CREATE above while
            // processDeliveryCallback() and both /api/callback/sms*delivery.php
            // endpoints write to it. The canonical schema
            // (Countries/Botswana/database/swap_system_bw.sql) has always had
            // it, so an established database was fine -- but anywhere this
            // CREATE actually ran, the column never existed and every delivery
            // report failed, silently, because those writes sit in a catch that
            // only logs. CREATE TABLE IF NOT EXISTS cannot repair an existing
            // table, hence the ALTER.
            $this->db->exec("
                ALTER TABLE sms_logs ADD COLUMN IF NOT EXISTS delivery_report JSON
            ");

            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_sms_logs_phone ON sms_logs(phone_number);
                CREATE INDEX IF NOT EXISTS idx_sms_logs_status ON sms_logs(status);
                CREATE INDEX IF NOT EXISTS idx_sms_logs_created ON sms_logs(created_at);
            ");
        } catch (Exception $e) {
            $this->log("Failed to create sms_logs table: " . $e->getMessage());
        }
    }
    
    /**
     * Send SMS message to a phone number
     */
    public function send(string $phoneNumber, string $message, array $options = []): bool
    {
        $result = $this->sendSms($phoneNumber, $message, $options);
        return $result['success'] ?? false;
    }
    
    /**
     * Send SMS via gateway
     */
    public function sendSms(string $phoneNumber, string $message, array $options = []): array
    {
        $this->log("Sending SMS to: {$phoneNumber}");

        $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
        $logId = $this->logSmsAttempt($phoneNumber, $message, $options);

        $gateway = $this->getGatewayForPhone($phoneNumber);

        if (!$gateway) {
            $this->log("No gateway available for {$phoneNumber} — mock mode");
            $this->updateSmsLog($logId, 'MOCK_SENT', null, 'No enabled telco matched this number');

            return [
                'success' => true,
                'message_id' => 'MOCK-' . uniqid(),
                'message' => 'SMS would be sent (no matching telco configured)'
            ];
        }

        try {
            $result = $gateway->send($phoneNumber, $message, $options['reference'] ?? '');

            if ($result['success']) {
                $this->updateSmsLog($logId, 'SENT', $result['message_id'] ?? null);
                $this->log("SMS sent successfully to: {$phoneNumber}");
            } else {
                $this->updateSmsLog($logId, 'FAILED', null, $result['error'] ?? 'Unknown error');
                $this->log("SMS failed to: {$phoneNumber} - " . ($result['error'] ?? 'Unknown error'));
            }

            return $result;

        } catch (Exception $e) {
            $this->updateSmsLog($logId, 'FAILED', null, $e->getMessage());
            $this->log("SMS exception for {$phoneNumber}: " . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Send cashout code (alias for SwapService compatibility)
     */
    public function sendCashoutCode(string $phoneNumber, string $code, float $amount, string $reference = ''): array
    {
        return $this->sendWithdrawalCode($phoneNumber, $code, $amount, 'BWP', ['reference' => $reference]);
    }
    
    /**
     * Send voucher code (alias for SwapService compatibility)
     */
    public function sendVoucherCode(string $phoneNumber, string $code, float $amount, string $voucherType = 'GENERIC'): array
    {
        $message = "🎟️ VouchMorph Voucher\nCode: {$code}\nAmount: " . number_format($amount, 2) . " BWP\nType: {$voucherType}\nValid for 24 hours.";
        return $this->sendSms($phoneNumber, $message, ['reference' => 'VOUCHER-' . uniqid(), 'type' => 'voucher_code']);
    }
    
    /**
     * Send ATM withdrawal code via SMS
     */
    public function sendWithdrawalCode(
        string $phoneNumber,
        string $code,
        float $amount,
        string $currency = 'BWP',
        array $additionalInfo = []
    ): array {
        $message = $this->buildWithdrawalMessage($code, $amount, $currency, $additionalInfo);
        
        return $this->sendSms($phoneNumber, $message, [
            'priority' => 'high',
            'reference' => $additionalInfo['reference'] ?? 'WDL-' . uniqid(),
            'type' => 'withdrawal_code'
        ]);
    }
    
    /**
     * Send transaction confirmation SMS
     */
    public function sendTransactionConfirmation(
        string $phoneNumber,
        float $amount,
        string $type,
        string $reference,
        string $status = 'completed'
    ): array {
        $message = $this->buildConfirmationMessage($amount, $type, $reference, $status);
        
        return $this->sendSms($phoneNumber, $message, [
            'priority' => 'normal',
            'reference' => 'CONF-' . $reference
        ]);
    }
    
    /**
     * Send OTP for verification
     */
    public function sendOtp(string $phoneNumber, string $otp, string $purpose = 'login'): array
    {
        $message = "Your VouchMorph verification code is: {$otp}\nValid for 5 minutes.\nDo not share this code.";
        
        return $this->sendSms($phoneNumber, $message, [
            'priority' => 'high',
            'reference' => 'OTP-' . uniqid(),
            'expiry' => time() + 300 // 5 minutes
        ]);
    }
    
    /**
     * Send welcome message
     */
    public function sendWelcome(string $phoneNumber, string $name = ''): array
    {
        $message = "Welcome to VouchMorph! Your account has been successfully created.\n";
        if ($name) {
            $message = "Welcome {$name}!\n" . $message;
        }
        $message .= "Download our app to get started: https://vouchmorph.com/app";
        
        return $this->sendSms($phoneNumber, $message, [
            'priority' => 'normal',
            'reference' => 'WELCOME-' . uniqid()
        ]);
    }
    
    /**
     * Send alert for suspicious activity
     */
    public function sendSecurityAlert(string $phoneNumber, string $alertType, array $details = []): array
    {
        $message = "🔐 SECURITY ALERT: {$alertType} detected on your account.\n";
        $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
        if (!empty($details)) {
            $message .= "Details: " . json_encode($details) . "\n";
        }
        $message .= "If this wasn't you, contact support immediately: support@vouchmorph.com";
        
        return $this->sendSms($phoneNumber, $message, [
            'priority' => 'urgent',
            'reference' => 'ALERT-' . uniqid()
        ]);
    }
    
    /**
     * Build withdrawal message
     */
    private function buildWithdrawalMessage(string $code, float $amount, string $currency, array $info): string
    {
        $message = "🔐 VOUCHMORPH WITHDRAWAL\n";
        $message .= "Code: {$code}\n";
        
        if (!empty($info['pin'])) {
            $message .= "PIN: {$info['pin']}\n";
        }
        
        $message .= "Amount: " . number_format($amount, 2) . " {$currency}\n";
        $message .= "Valid for 24 hours.\n";
        $message .= "Keep this code secure! Do not share with anyone.";
        
        return $message;
    }
    
    /**
     * Build confirmation message
     */
    private function buildConfirmationMessage(float $amount, string $type, string $reference, string $status): string
    {
        $statusText = $status === 'completed' ? 'SUCCESSFUL' : strtoupper($status);
        $emoji = $status === 'completed' ? '✅' : '⚠️';
        
        $message = "{$emoji} VOUCHMORPH TRANSACTION {$statusText}\n";
        $message .= "Type: " . strtoupper($type) . "\n";
        $message .= "Amount: " . number_format($amount, 2) . " BWP\n";
        $message .= "Reference: " . substr($reference, 0, 12) . "...\n";
        $message .= "Thank you for using VouchMorph!";
        
        return $message;
    }
    
    /**
     * Clean phone number for SMS gateway
     */
    private function cleanPhoneNumber(string $phoneNumber): string
    {
        // Remove any non-digit characters except +
        $cleaned = preg_replace('/[^\d+]/', '', $phoneNumber);
        
        // Ensure it has country code
        if (!str_starts_with($cleaned, '+')) {
            if (strlen($cleaned) === 8) {
                // Botswana number without country code
                $cleaned = '+267' . $cleaned;
            } elseif (strlen($cleaned) === 9) {
                // South Africa number without country code
                $cleaned = '+27' . $cleaned;
            } elseif (strlen($cleaned) === 10 && str_starts_with($cleaned, '0')) {
                // Remove leading zero and add country code
                $cleaned = '+267' . substr($cleaned, 1);
            } else {
                $cleaned = '+' . $cleaned;
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Log SMS attempt to database
     */
    private function logSmsAttempt(string $phoneNumber, string $message, array $options): ?int
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sms_logs (phone_number, message, status, created_at)
                VALUES (:phone, :message, 'PENDING', NOW())
                RETURNING id
            ");
            $stmt->execute([
                ':phone' => $phoneNumber,
                ':message' => substr($message, 0, 500)
            ]);
            
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $this->log("Failed to log SMS attempt: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update SMS log status
     *
     * FIX: :status was previously bound once but referenced in two
     * different expression contexts (SET status = :status, and inside
     * CASE WHEN :status IN (...)). Postgres deduces conflicting types
     * for that single placeholder across the two contexts, throwing
     * SQLSTATE[42P08]. Since this runs on the SAME PDO connection/
     * transaction as the enclosing atomic swap, that error poisons the
     * whole transaction — every subsequent query in the swap then fails
     * with 25P02 until rollback. Fixed by binding two separate named
     * placeholders to the same value, each with an explicit cast.
     */
    private function updateSmsLog(?int $logId, string $status, ?string $messageId = null, ?string $error = null): void
    {
        if (!$logId) return;
        
        try {
            $stmt = $this->db->prepare("
                UPDATE sms_logs 
                SET status = :status::varchar,
                    message_id = :message_id,
                    error_message = :error,
                    sent_at = CASE WHEN :status2::varchar IN ('SENT', 'MOCK_SENT') THEN NOW() ELSE sent_at END
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $logId,
                ':status' => $status,
                ':status2' => $status,
                ':message_id' => $messageId,
                ':error' => $error
            ]);
        } catch (Exception $e) {
            $this->log("Failed to update SMS log: " . $e->getMessage());
        }
    }
    
    /**
     * Process SMS delivery callback
     */
    public function processDeliveryCallback(array $callbackData): void
    {
        $this->log("Received delivery callback: " . json_encode($callbackData));
        
        if (isset($callbackData['message_id'])) {
            try {
                $stmt = $this->db->prepare("
                    UPDATE sms_logs 
                    SET status = :status,
                        delivery_report = :report
                    WHERE message_id = :message_id
                ");
                
                $stmt->execute([
                    ':status' => $callbackData['status'] ?? 'DELIVERED',
                    ':report' => json_encode($callbackData),
                    ':message_id' => $callbackData['message_id']
                ]);
            } catch (Exception $e) {
                $this->log("Failed to process delivery callback: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Get SMS history for a phone number
     */
    public function getSmsHistory(string $phoneNumber, int $limit = 10): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM sms_logs 
                WHERE phone_number = :phone 
                ORDER BY created_at DESC 
                LIMIT :limit
            ");
            
            $stmt->execute([
                ':phone' => $phoneNumber,
                ':limit' => $limit
            ]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->log("Failed to get SMS history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get SMS statistics
     */
    public function getStatistics(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'SENT' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
                    COUNT(DISTINCT phone_number) as unique_recipients
                FROM sms_logs
                WHERE created_at >= NOW() - INTERVAL '30 days'
            ");
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->log("Failed to get SMS statistics: " . $e->getMessage());
            return [
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'pending' => 0,
                'unique_recipients' => 0
            ];
        }
    }
    
    /**
     * Check if SMS service is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->config['telcos']);
    }
    
    /**
     * Log messages
     */
    private function log(string $message): void
    {
        $logEntry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents(self::LOG_FILE, $logEntry, FILE_APPEND);
        error_log("[SMS Service] " . $message);
    }
}
