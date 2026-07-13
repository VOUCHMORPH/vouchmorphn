<?php
// src/Infrastructure/SMS/SmsGatewayClient.php

declare(strict_types=1);

namespace Infrastructure\SMS;

use Infrastructure\SMS\Contracts\ProviderInterface;
use Security\Encryption\KeyVault;
use Exception;
use RuntimeException;
use Psr\Log\LoggerInterface;

/**
 * Production SMS Gateway Client
 * Supports multiple providers via configuration
 */
class SmsGatewayClient implements ProviderInterface
{
    private array $config;
    private KeyVault $keyVault;
    private ?LoggerInterface $logger;
    private ?string $lastError = null;
    private array $circuitBreakerState = [];
    
    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->keyVault = KeyVault::getInstance();
        $this->logger = $logger;
        
        $this->validateConfiguration();
    }
    
    /**
     * Validate required configuration
     * @throws RuntimeException
     */
    private function validateConfiguration(): void
    {
        $required = ['base_url', 'provider', 'api_key_ref'];
        foreach ($required as $field) {
            if (empty($this->config[$field])) {
                throw new RuntimeException("Missing required config: {$field}");
            }
        }
        
        // Validate provider has required endpoint configuration
        if (!isset($this->config['endpoints']['send']) || empty($this->config['endpoints']['send'])) {
            throw new RuntimeException("Provider '{$this->config['provider']}' missing send endpoint configuration");
        }
    }
    
    /**
     * Send SMS message
     */
    public function send(string $to, string $message, string $reference = ''): array
    {
        $startTime = microtime(true);
        $messageId = $reference ?: $this->generateMessageId();
        
        // Check circuit breaker
        if ($this->isCircuitOpen()) {
            $error = "Circuit breaker open for provider: {$this->config['provider']}";
            $this->lastError = $error;
            return $this->buildErrorResponse($messageId, $to, $error);
        }
        
        try {
            $apiKey = $this->getApiKey();
            $formattedTo = $this->formatPhoneNumber($to);
            
            $payload = $this->buildPayload($formattedTo, $message, $messageId);
            $headers = $this->buildHeaders($apiKey, $messageId);
            
            $response = $this->executeRequest($payload, $headers);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->recordSuccess();
            $this->logSuccess($messageId, $formattedTo, $duration);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'provider_message_id' => $this->extractProviderMessageId($response),
                'to' => $formattedTo,
                'status' => 'sent',
                'provider' => $this->config['provider'],
                'duration_ms' => $duration,
                'sent_at' => gmdate('c')
            ];
            
        } catch (Exception $e) {
            $this->recordFailure();
            $this->lastError = $e->getMessage();
            $this->logFailure($messageId, $to, $e->getMessage());
            
            return $this->buildErrorResponse($messageId, $to, $e->getMessage());
        }
    }
    
    /**
     * Get delivery status from provider
     */
    public function getDeliveryStatus(string $messageId): array
    {
        try {
            $apiKey = $this->getApiKey();
            $endpoint = $this->config['endpoints']['status'] ?? null;
            
            if (!$endpoint) {
                return [
                    'status' => 'unknown',
                    'message_id' => $messageId,
                    'error' => 'Status endpoint not configured'
                ];
            }
            
            $url = rtrim($this->config['base_url'], '/') . $endpoint . '/' . urlencode($messageId);
            $headers = $this->buildHeaders($apiKey, $messageId);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout'] ?? 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->config['ssl_verify'] ?? true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return [
                    'status' => $this->extractDeliveryStatus($data),
                    'message_id' => $messageId,
                    'provider_response' => $data,
                    'checked_at' => gmdate('c')
                ];
            }
            
            return [
                'status' => 'unknown',
                'message_id' => $messageId,
                'http_code' => $httpCode,
                'checked_at' => gmdate('c')
            ];
            
        } catch (Exception $e) {
            $this->logError("Delivery status check failed: " . $e->getMessage());
            return [
                'status' => 'unknown',
                'message_id' => $messageId,
                'error' => $e->getMessage(),
                'checked_at' => gmdate('c')
            ];
        }
    }
    
    /**
     * Get provider name
     */
    public function getProviderName(): string
    {
        return $this->config['provider'];
    }
    
    /**
     * Check if provider is available
     */
    public function isAvailable(): bool
    {
        $healthEndpoint = $this->config['endpoints']['health'] ?? null;
        
        if (!$healthEndpoint) {
            // If no health endpoint, assume available
            return true;
        }
        
        try {
            $url = rtrim($this->config['base_url'], '/') . $healthEndpoint;
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->config['ssl_verify'] ?? true);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $isAvailable = $httpCode >= 200 && $httpCode < 500;
            
            if (!$isAvailable && $this->logger) {
                $this->logger->warning("Provider health check failed", [
                    'provider' => $this->config['provider'],
                    'http_code' => $httpCode
                ]);
            }
            
            return $isAvailable;
            
        } catch (Exception $e) {
            $this->logError("Health check failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get last error
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }
    
    /**
     * Get API key from environment or KeyVault
     * FIXED: Now checks environment variables directly with multiple variations
     * @throws RuntimeException
     */
    private function getApiKey(): string
    {
        $apiKeyRef = $this->config['api_key_ref'] ?? $this->config['api_key_env'] ?? 'CAZACOM_API_KEY';
        
        // Try environment variables first (Railway)
        $apiKey = getenv($apiKeyRef);
        
        if (!$apiKey) {
            // Try common variations
            $variations = [
                strtoupper($apiKeyRef),
                strtolower($apiKeyRef),
                str_replace('_API_KEY', '', $apiKeyRef) . '_API_KEY',
                strtoupper(str_replace('_API_KEY', '', $apiKeyRef)) . '_API_KEY',
                str_replace('_API_KEY', '_KEY', $apiKeyRef),
                strtoupper(str_replace('_API_KEY', '_KEY', $apiKeyRef)),
            ];
            
            foreach ($variations as $variant) {
                $apiKey = getenv($variant);
                if ($apiKey) {
                    error_log("[SmsGatewayClient] Found API key using variation: {$variant}");
                    break;
                }
            }
        }
        
        // If still not found, try KeyVault as fallback
        if (!$apiKey) {
            try {
                $apiKey = $this->keyVault->get($apiKeyRef);
            } catch (Exception $e) {
                // KeyVault lookup failed
                error_log("[SmsGatewayClient] KeyVault lookup failed: " . $e->getMessage());
            }
        }
        
        if (empty($apiKey)) {
            error_log("[SmsGatewayClient] API key not found. Looking for: {$apiKeyRef}");
            error_log("[SmsGatewayClient] Available env keys: " . implode(', ', array_keys($_ENV)));
            throw new RuntimeException("API key not found: {$apiKeyRef}");
        }
        
        return $apiKey;
    }
    
    /**
     * Generate unique message ID
     */
    private function generateMessageId(): string
    {
        return 'SMS_' . bin2hex(random_bytes(16));
    }
    
    /**
     * Format phone number according to provider requirements
     */
    private function formatPhoneNumber(string $phone): string
    {
        $format = $this->config['phone_format'] ?? ['strip_prefix' => false, 'add_prefix' => null];
        
        // Strip any non-digits
        $digits = preg_replace('/[^0-9]/', '', $phone);
        
        // Apply format rules from config
        if (isset($format['strip_prefix'])) {
            $prefixLength = (int)($format['strip_prefix'] ?? 0);
            if ($prefixLength > 0) {
                $digits = substr($digits, $prefixLength);
            }
        }
        
        if (isset($format['add_prefix']) && !empty($format['add_prefix'])) {
            $digits = $format['add_prefix'] . $digits;
        }
        
        if (isset($format['add_plus']) && $format['add_plus'] === true) {
            return '+' . $digits;
        }
        
        return $digits;
    }
    
    /**
     * Build payload according to provider specification
     */
    private function buildPayload(string $to, string $message, string $messageId): array
    {
        $payloadTemplate = $this->config['payload_template'] ?? [];
        $payload = [];
        
        foreach ($payloadTemplate as $key => $value) {
            $payload[$key] = $this->interpolatePayloadValue($value, [
                'to' => $to,
                'message' => $message,
                'message_id' => $messageId,
                'sender' => $this->config['sender'] ?? null,
                'timestamp' => time()
            ]);
        }
        
        return $payload;
    }
    
    /**
     * Interpolate payload values with variables
     */
    private function interpolatePayloadValue($value, array $context)
    {
        if (!is_string($value)) {
            return $value;
        }
        
        return preg_replace_callback('/\{(\w+)\}/', function($matches) use ($context) {
            return $context[$matches[1]] ?? $matches[0];
        }, $value);
    }
    
    /**
     * Build headers according to provider specification
     */
    private function buildHeaders(string $apiKey, string $messageId): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Request-ID: ' . $messageId,
            'X-Timestamp: ' . time()
        ];
        
        $authConfig = $this->config['authentication'] ?? ['type' => 'header', 'key' => 'X-API-Key'];
        
        switch ($authConfig['type']) {
            case 'header':
                $headerKey = $authConfig['key'] ?? 'X-API-Key';
                $headers[] = "{$headerKey}: {$apiKey}";
                break;
            case 'bearer':
                $headers[] = "Authorization: Bearer {$apiKey}";
                break;
            case 'basic':
                $encoded = base64_encode($apiKey . ':' . ($authConfig['password'] ?? ''));
                $headers[] = "Authorization: Basic {$encoded}";
                break;
        }
        
        // Add any custom headers from config
        if (!empty($this->config['headers'])) {
            foreach ($this->config['headers'] as $key => $value) {
                $headers[] = "{$key}: {$value}";
            }
        }
        
        return $headers;
    }
    
    /**
     * Execute HTTP request
     * @throws Exception
     */
    private function executeRequest(array $payload, array $headers): array
    {
        $url = rtrim($this->config['base_url'], '/') . $this->config['endpoints']['send'];
        $method = $this->config['method'] ?? 'POST';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout'] ?? 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->config['ssl_verify'] ?? true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($payload));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception("CURL error: {$curlError}");
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("HTTP error {$httpCode}: {$response}");
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    /**
     * Extract provider message ID from response
     */
    private function extractProviderMessageId(array $response): ?string
    {
        $path = $this->config['response_mappings']['message_id'] ?? null;
        
        if (!$path) {
            return null;
        }
        
        return $this->getNestedValue($response, explode('.', $path));
    }
    
    /**
     * Extract delivery status from provider response
     */
    private function extractDeliveryStatus(array $response): string
    {
        $path = $this->config['response_mappings']['status'] ?? null;
        
        if (!$path) {
            return 'unknown';
        }
        
        $status = $this->getNestedValue($response, explode('.', $path));
        $mappings = $this->config['status_mappings'] ?? [];
        
        return $mappings[$status] ?? $status;
    }
    
    /**
     * Get nested array value by path
     */
    private function getNestedValue(array $data, array $keys)
    {
        $current = $data;
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }
    
    /**
     * Check if circuit breaker is open
     */
    private function isCircuitOpen(): bool
    {
        $config = $this->config['circuit_breaker'] ?? ['enabled' => false];
        
        if (!$config['enabled']) {
            return false;
        }
        
        $provider = $this->config['provider'];
        
        if (!isset($this->circuitBreakerState[$provider])) {
            $this->circuitBreakerState[$provider] = [
                'failures' => 0,
                'last_failure' => null,
                'state' => 'closed'
            ];
        }
        
        $state = $this->circuitBreakerState[$provider];
        
        if ($state['state'] === 'open') {
            $timeout = $config['timeout_seconds'] ?? 60;
            if (time() - ($state['last_failure'] ?? 0) > $timeout) {
                // Try to close the circuit
                $this->circuitBreakerState[$provider]['state'] = 'half-open';
                return false;
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Record successful request
     */
    private function recordSuccess(): void
    {
        $provider = $this->config['provider'];
        
        if (isset($this->circuitBreakerState[$provider])) {
            $this->circuitBreakerState[$provider] = [
                'failures' => 0,
                'last_failure' => null,
                'state' => 'closed'
            ];
        }
    }
    
    /**
     * Record failed request
     */
    private function recordFailure(): void
    {
        $config = $this->config['circuit_breaker'] ?? ['enabled' => false];
        
        if (!$config['enabled']) {
            return;
        }
        
        $provider = $this->config['provider'];
        $threshold = $config['failure_threshold'] ?? 5;
        
        if (!isset($this->circuitBreakerState[$provider])) {
            $this->circuitBreakerState[$provider] = [
                'failures' => 0,
                'last_failure' => null,
                'state' => 'closed'
            ];
        }
        
        $this->circuitBreakerState[$provider]['failures']++;
        $this->circuitBreakerState[$provider]['last_failure'] = time();
        
        if ($this->circuitBreakerState[$provider]['failures'] >= $threshold) {
            $this->circuitBreakerState[$provider]['state'] = 'open';
            $this->logError("Circuit breaker opened for provider: {$provider}");
        }
    }
    
    /**
     * Build error response
     */
    private function buildErrorResponse(string $messageId, string $to, string $error): array
    {
        return [
            'success' => false,
            'message_id' => $messageId,
            'to' => $to,
            'status' => 'failed',
            'provider' => $this->config['provider'],
            'error' => $error,
            'timestamp' => gmdate('c')
        ];
    }
    
    /**
     * Log successful send
     */
    private function logSuccess(string $messageId, string $to, float $duration): void
    {
        if (!$this->logger) {
            return;
        }
        
        $this->logger->info('SMS sent successfully', [
            'message_id' => $messageId,
            'to' => $this->maskPhoneNumber($to),
            'provider' => $this->config['provider'],
            'duration_ms' => $duration
        ]);
    }
    
    /**
     * Log failed send
     */
    private function logFailure(string $messageId, string $to, string $error): void
    {
        if (!$this->logger) {
            error_log("[SmsGatewayClient] Failed to send SMS: {$error}");
            return;
        }
        
        $this->logger->error('SMS send failed', [
            'message_id' => $messageId,
            'to' => $this->maskPhoneNumber($to),
            'provider' => $this->config['provider'],
            'error' => $error
        ]);
    }
    
    /**
     * Log error
     */
    private function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message, ['provider' => $this->config['provider']]);
        } else {
            error_log("[SmsGatewayClient] {$message}");
        }
    }
    
    /**
     * Mask phone number for logging (show last 4 digits)
     */
    private function maskPhoneNumber(string $phone): string
    {
        $length = strlen($phone);
        if ($length <= 4) {
            return '****';
        }
        
        $masked = str_repeat('*', $length - 4) . substr($phone, -4);
        return $masked;
    }
}
