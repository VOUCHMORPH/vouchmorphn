<?php
// src/Infrastructure/SMS/Contracts/ProviderInterface.php

declare(strict_types=1);

namespace Infrastructure\SMS\Contracts;

interface ProviderInterface
{
    /**
     * Send an SMS message
     * 
     * @param string $to Recipient phone number (international format)
     * @param string $message Message content
     * @param string $reference Optional reference ID for idempotency
     * @return array Response with keys: success, message_id, status, provider, etc.
     * @throws RuntimeException on configuration errors
     */
    public function send(string $to, string $message, string $reference = ''): array;
    
    /**
     * Check delivery status of an SMS
     * 
     * @param string $messageId The message ID returned from send()
     * @return array Status information with keys: status, message_id, checked_at
     */
    public function getDeliveryStatus(string $messageId): array;
    
    /**
     * Get provider name (e.g., 'cazacom', 'infobip', 'twilio')
     * 
     * @return string Provider identifier
     */
    public function getProviderName(): string;
    
    /**
     * Check if provider is available/healthy
     * 
     * @return bool True if provider is available
     */
    public function isAvailable(): bool;
    
    /**
     * Get last error message from failed operation
     * 
     * @return string|null Error message or null if no error
     */
    public function getLastError(): ?string;
}
