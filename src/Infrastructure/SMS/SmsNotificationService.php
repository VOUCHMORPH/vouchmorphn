<?php

declare(strict_types=1);

namespace Infrastructure\SMS;

use PDO;
use Exception;

/**
 * SMS Notification Service
 * Handles all SMS communications with customers
 */
class SmsNotificationService
{
    private PDO $db;
    private ?SmsGatewayClient $smsGateway;
    private array $config;
    
    private const LOG_FILE = '/tmp/vouchmorph_sms_service.log';
    
    public function __construct(PDO $db, array $config = [])
    {
        $this->db = $db;
        $this->config = $config;
        $this->smsGateway = null;
        
        // Initialize SMS Gateway if configured
        try {
            // Check if SmsGatewayClient class exists
            if (class_exists('\\Infrastructure\\SMS\\SmsGatewayClient')) {
                $this->smsGateway = new SmsGatewayClient($config);
                $this->log("SmsGatewayClient initialized successfully");
            } else {
                $this->log("SmsGatewayClient class not found - SMS will be in mock mode");
            }
        } catch (Exception $e) {
            $this->smsGateway = null;
            $this->log("Failed to initialize SmsGatewayClient: " . $e->getMessage());
        }
        
        $this->ensureSmsLogsTable();
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
                    sent_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW()
                )
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
        
        // Clean phone number
        $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
        
        // Log attempt
        $logId = $this->logSmsAttempt($phoneNumber, $message, $options);
        
        // If gateway is not available, return mock success (for development)
        if (!$this->smsGateway) {
            $this->log("SMS Gateway not available - SMS would be sent to: {$phoneNumber}");
            $this->updateSmsLog($logId, 'MOCK_SENT', null, 'Mock mode - gateway not configured');
            
            return [
                'success' => true,
                'message_id' => 'MOCK-' . uniqid(),
                'message' => 'SMS would be sent (gateway not configured)'
            ];
        }
        
        try {
            // Send via gateway
            $result = $this->smsGateway->sendSms($phoneNumber, $message, $options);
            
            if ($result['success']) {
                $this->updateSmsLog($logId, 'SENT', $result['message_id'] ?? null);
                $this->log("SMS sent successfully to: {$phoneNumber}");
            } else {
                $this->updateSmsLog($logId, 'FAILED', null, $result['message'] ?? 'Unknown error');
                $this->log("SMS failed to: {$phoneNumber} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->updateSmsLog($logId, 'FAILED', null, $e->getMessage());
            $this->log("SMS exception for {$phoneNumber}: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
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
            'reference' => 'WDL-' . uniqid(),
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
     */
    private function updateSmsLog(?int $logId, string $status, ?string $messageId = null, ?string $error = null): void
    {
        if (!$logId) return;
        
        try {
            $stmt = $this->db->prepare("
                UPDATE sms_logs 
                SET status = :status,
                    message_id = :message_id,
                    error_message = :error,
                    sent_at = CASE WHEN :status IN ('SENT', 'MOCK_SENT') THEN NOW() ELSE sent_at END
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $logId,
                ':status' => $status,
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
        return $this->smsGateway !== null && $this->smsGateway->isConfigured();
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
