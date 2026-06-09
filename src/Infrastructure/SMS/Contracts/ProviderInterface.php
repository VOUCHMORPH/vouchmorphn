<?php
// File: /var/www/html/src/Infrastructure/SMS/Contracts/ProviderInterface.php

declare(strict_types=1);

namespace Infrastructure\SMS\Contracts;

interface ProviderInterface
{
    /**
     * Send an SMS message
     * 
     * @param string $to Recipient phone number
     * @param string $message Message content
     * @param string $reference Optional reference ID
     * @return array Response with status, message_id, etc.
     */
    public function send(string $to, string $message, string $reference = ''): array;
    
    /**
     * Check delivery status of an SMS
     * 
     * @param string $messageId The message ID from send()
     * @return array Status information
     */
    public function getDeliveryStatus(string $messageId): array;
    
    /**
     * Get provider name
     * 
     * @return string
     */
    public function getProviderName(): string;
    
    /**
     * Check if provider is available/healthy
     * 
     * @return bool
     */
    public function isAvailable(): bool;
}
