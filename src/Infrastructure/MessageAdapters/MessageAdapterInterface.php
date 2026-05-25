<?php
declare(strict_types=1);

namespace Infrastructure\MessageAdapters;

use Core\Transaction\InternalTransaction;

/**
 * Message Adapter Interface
 * All message format adapters must implement this interface
 */
interface MessageAdapterInterface
{
    /**
     * Convert internal transaction to external message format
     * 
     * @param InternalTransaction $transaction
     * @return string The formatted message (XML, JSON, etc.)
     */
    public function toExternal(InternalTransaction $transaction): string;
    
    /**
     * Parse external message to internal transaction
     * 
     * @param string $message The incoming message
     * @return InternalTransaction
     */
    public function toInternal(string $message): InternalTransaction;
    
    /**
     * Validate message format and structure
     * 
     * @param string $message The message to validate
     * @return bool
     */
    public function validate(string $message): bool;
    
    /**
     * Get the adapter version
     * 
     * @return string
     */
    public function getVersion(): string;
}
