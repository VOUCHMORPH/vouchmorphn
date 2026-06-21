<?php
declare(strict_types=1);

namespace Domain\Capabilities;

/**
 * VOUCHMORPH CAPABILITIES
 * This is VouchMorph's language. All institutions map to these.
 * 
 * NEVER change these unless VouchMorph's core functionality changes.
 */
enum Capability: string
{
    // Core Swap Capabilities
    case CONSENT = 'CONSENT';
    case VERIFY_ASSET = 'VERIFY_ASSET';
    case BALANCE = 'BALANCE';
    case HOLD = 'HOLD';
    case DEBIT = 'DEBIT';
    case CREDIT = 'CREDIT';
    case TRANSFER = 'TRANSFER';
    case CASHOUT = 'CASHOUT';
    case CASHIN = 'CASHIN';
    
    // Account Management
    case ACCOUNT_LOOKUP = 'ACCOUNT_LOOKUP';
    case ACCOUNT_CREATE = 'ACCOUNT_CREATE';
    case ACCOUNT_CLOSE = 'ACCOUNT_CLOSE';
    case TRANSACTION_HISTORY = 'TRANSACTION_HISTORY';
    
    // Payment Capabilities
    case PAYMENT_INITIATION = 'PAYMENT_INITIATION';
    case PAYMENT_STATUS = 'PAYMENT_STATUS';
    case BENEFICIARY_VALIDATE = 'BENEFICIARY_VALIDATE';
    
    // Card Capabilities
    case CARD_ISSUE = 'CARD_ISSUE';
    case CARD_BLOCK = 'CARD_BLOCK';
    case CARD_UNBLOCK = 'CARD_UNBLOCK';
    case CARD_STATUS = 'CARD_STATUS';
    case CARD_TRANSACTIONS = 'CARD_TRANSACTIONS';
    
    // FX Capabilities
    case FX_QUOTE = 'FX_QUOTE';
    case FX_CONVERT = 'FX_CONVERT';
    
    // Settlement
    case SETTLEMENT = 'SETTLEMENT';
    case RECONCILIATION = 'RECONCILIATION';
    
    /**
     * Get all capability names
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }
}
