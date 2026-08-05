<?php
declare(strict_types=1);

namespace Infrastructure\Adapters;

interface InstitutionAdapterInterface
{
    // ============================================================
    // CORE SWAP OPERATIONS - Required for swaps to work
    // ============================================================
    
    /**
     * Verify asset exists and has sufficient funds
     * INTERNAL: Handles consent if needed (PSD2) or not (M-Pesa)
     */
    public function verifyAsset(array $payload, array $context): array;
    
    /**
     * Place a hold on an asset
     * INTERNAL: Handles consent if needed
     */
    public function placeHold(array $payload, array $context): array;
    
    /**
     * Debit funds from source
     * INTERNAL: Handles consent if needed
     */
    public function debit(array $payload, array $context): array;
    
    /**
     * Credit funds to destination
     * INTERNAL: Handles consent if needed
     */
    public function credit(array $payload, array $context): array;
    
    /**
     * Release a hold on an asset
     * CRITICAL: Required for multi-source rollback and error recovery
     * INTERNAL: Handles consent if needed
     * 
     * @param array $payload Release payload (hold_reference, reason, etc.)
     * @param array $context Context (swap_reference, institution, etc.)
     * @return array Result with 'released' key and status
     */
    public function releaseHold(array $payload, array $context): array;
    
    /**
     * Generate cashout token
     * INTERNAL: Handles consent if needed
     */
    public function generateCashoutToken(array $payload, array $context): array;
    
    /**
     * Verify cashout token
     */
    public function verifyCashoutToken(array $payload, array $context): array;
    
    /**
     * Confirm cashout
     */
    public function confirmCashout(array $payload, array $context): array;
    
    /**
     * Verify account exists (for destination validation)
     */
    public function verifyAccount(array $payload, array $context): array;
    
    // ============================================================
    // DASHBOARD/UX OPERATIONS - Optional, for UI display
    // ============================================================
    
    /**
     * Get balance for dashboard display
     * Called when user views their account in the dashboard
     * NOT part of swap flow
     */
    public function getBalance(array $payload, array $context): array;
    
    /**
     * Get transaction history for dashboard display
     * NOT part of swap flow
     */
    public function getTransactions(array $payload, array $context): array;


    public function checkSettlementStatus(array $payload, array $context): array
    
    /**
     * Get list of accounts for dashboard display
     * NOT part of swap flow
     */
    public function getAccounts(array $payload, array $context): array;
    
    // ============================================================
    // ADAPTER METADATA
    // ============================================================
    
    /**
     * Check if this adapter supports a capability
     */
    public function supports(string $capability): bool;
    
    /**
     * Get the institution this adapter is for
     */
    public function getInstitution(): string;
}
