<?php
declare(strict_types=1);

namespace Infrastructure\Adapters;

interface InstitutionAdapterInterface
{
    /**
     * Get balance for an account/wallet
     * 
     * @param array $payload Contains: account_id, amount (optional), currency (optional)
     * @param array $context Previous results (hold_id, etc.)
     * @return array ['success' => bool, 'balance' => float, 'currency' => string, 'account_id' => string]
     */
    public function getBalance(array $payload, array $context): array;
    
    /**
     * Verify asset exists and has sufficient funds
     * 
     * @param array $payload Contains: account_id, amount, currency
     * @param array $context Previous results (balance, etc.)
     * @return array ['verified' => bool, 'account_id' => string, 'balance' => float]
     */
    public function verifyAsset(array $payload, array $context): array;
    
    /**
     * Place a hold on an asset
     * 
     * @param array $payload Contains: account_id, amount, currency
     * @param array $context Previous results (verification, etc.)
     * @return array ['hold_placed' => bool, 'hold_id' => string, 'hold_reference' => string]
     */
    public function placeHold(array $payload, array $context): array;
    
    /**
     * Debit funds from source
     * 
     * @param array $payload Contains: hold_id, amount, currency
     * @param array $context Previous results (hold, etc.)
     * @return array ['debited' => bool, 'transaction_reference' => string]
     */
    public function debit(array $payload, array $context): array;
    
    /**
     * Credit funds to destination
     * 
     * @param array $payload Contains: account_id, amount, currency
     * @param array $context Previous results
     * @return array ['credited' => bool, 'transaction_reference' => string]
     */
    public function credit(array $payload, array $context): array;
    
    /**
     * Generate cashout token
     * 
     * @param array $payload Contains: account_id, amount, currency, phone
     * @param array $context Previous results
     * @return array ['success' => bool, 'cashout_code' => string, 'expires_at' => string]
     */
    public function generateCashoutToken(array $payload, array $context): array;
    
    /**
     * Verify cashout token
     * 
     * @param array $payload Contains: cashout_code
     * @param array $context Previous results
     * @return array ['verified' => bool, 'amount' => float]
     */
    public function verifyCashoutToken(array $payload, array $context): array;
    
    /**
     * Confirm cashout
     * 
     * @param array $payload Contains: cashout_code
     * @param array $context Previous results
     * @return array ['confirmed' => bool, 'transaction_reference' => string]
     */
    public function confirmCashout(array $payload, array $context): array;
    
    /**
     * Verify account exists
     * 
     * @param array $payload Contains: account_id
     * @param array $context Previous results
     * @return array ['verified' => bool, 'account_name' => string]
     */
    public function verifyAccount(array $payload, array $context): array;
    
    /**
     * Check if this adapter supports a capability
     */
    public function supports(string $capability): bool;
    
    /**
     * Get the institution this adapter is for
     */
    public function getInstitution(): string;
}
