<?php

declare(strict_types=1);

namespace Infrastructure\SMS;

use PDO;
use Exception;

/**
 * SMS Gateway Client
 * Handles actual SMS sending via gateway API
 */
class SmsGatewayClient
{
    private PDO $db;
    private array $config;
    private ?string $apiUrl;
    private ?string $apiKey;
    private ?string $senderId;
    
    private const LOG_FILE = '/tmp/vouchmorph_sms_gateway.log';
    
    public function __construct(PDO $db, array $config = [])
    {
        $this->db = $db;
        $this->config = $config;
        
        // FIX: Convert false to null for string type properties
        $apiUrl = $config['api_url'] ?? getenv('SMS_API_URL');
        $this->apiUrl = ($apiUrl === false) ? null : (string)$apiUrl;
        
        $apiKey = $config['api_key'] ?? getenv('SMS_API_KEY');
        $this->apiKey = ($apiKey === false) ? null : (string)$apiKey;
        
        $senderId = $config['sender_id'] ?? getenv('SMS_SENDER_ID') ?? 'VOUCHMORPH';
        $this->senderId = ($senderId === false) ? 'VOUCHMORPH' : (string)$senderId;
    }
    
    /**
     * Send SMS via gateway
     */
    public function sendSms(string $phoneNumber, string $message, array $options = []): array
    {
        $this->log("Sending SMS to: {$phoneNumber}");
        
        // If no API configured, mock the send
        if (!$this->apiUrl || !$this->apiKey) {
            return $this->mockSend($phoneNumber, $message, $options);
        }
        
        return $this->sendViaApi($phoneNumber, $message, $options);
    }
    
    /**
     * Send via actual API
     */
    private function sendViaApi(string $phoneNumber, string $message, array $options): array
    {
        $payload = [
            'to' => $phoneNumber,
            'from' => $this->senderId,
            'message' => $message,
            'api_key' => $this->apiKey,
            'reference' => $options['reference'] ?? uniqid()
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $success = ($httpCode >= 200 && $httpCode < 300);
        
        if ($success) {
            $this->log("SMS sent successfully via API to: {$phoneNumber}");
            return [
                'success' => true,
                'message_id' => $this->extractMessageId($response),
                'http_code' => $httpCode,
                'response' => $response
            ];
        } else {
            $this->log("SMS API failed: HTTP {$httpCode} - {$curlError}");
            return [
                'success' => false,
                'message' => "HTTP {$httpCode}: " . ($curlError ?: 'Unknown error'),
                'http_code' => $httpCode
            ];
        }
    }
    
    /**
     * Mock send for development
     */
    private function mockSend(string $phoneNumber, string $message, array $options): array
    {
        $this->log("MOCK SMS to: {$phoneNumber} | Message: " . substr($message, 0, 100));
        
        return [
            'success' => true,
            'message_id' => 'MOCK-' . uniqid(),
            'message' => 'SMS would be sent (mock mode)'
        ];
    }
    
    /**
     * Extract message ID from API response
     */
    private function extractMessageId(string $response): string
    {
        $data = json_decode($response, true);
        return $data['message_id'] ?? $data['id'] ?? uniqid();
    }
    
    /**
     * Check if gateway is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiUrl) && !empty($this->apiKey);
    }
    
    /**
     * Get gateway status
     */
    public function getStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'api_url' => $this->apiUrl ?: 'Not configured',
            'sender_id' => $this->senderId
        ];
    }
    
    /**
     * Log messages
     */
    private function log(string $message): void
    {
        $logEntry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents(self::LOG_FILE, $logEntry, FILE_APPEND);
        error_log("[SMS Gateway] " . $message);
    }
}
