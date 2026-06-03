<?php
// Infrastructure/Banks/Contracts/BankAPIInterface.php

namespace Infrastructure\Banks\Contracts;

/**
 * Complete Bank API Interface for VouchMorph Swap System
 * Supports all wallet_types: ACCOUNT, VOUCHER, E-WALLET, WALLET, CARD, ATM, AGENT
 * Handles both Source and Destination roles, plus OAuth authentication
 */
interface BankAPIInterface
{
    // ============================================================================
    // OAUTH METHODS - For linking bank accounts
    // ============================================================================

    /**
     * Get OAuth authorization URL for redirecting user to bank
     * 
     * @param string $redirectUri Callback URL after authorization
     * @param string $state CSRF protection state parameter
     * @param array $scope Requested scopes (read_balance, initiate_payment, etc.)
     * @return string Authorization URL
     */
    public function getAuthorizationUrl(string $redirectUri, string $state, array $scope = []): string;

    /**
     * Exchange authorization code for access token
     * 
     * @param string $code Authorization code from bank
     * @param string $redirectUri Redirect URI used in authorization
     * @return array ['access_token' => string, 'refresh_token' => string, 'expires_in' => int]
     */
    public function exchangeCodeForToken(string $code, string $redirectUri): array;

    /**
     * Refresh expired access token
     * 
     * @param string $refreshToken Refresh token
     * @return array ['access_token' => string, 'expires_in' => int]
     */
    public function refreshAccessToken(string $refreshToken): array;

    /**
     * Revoke access token (when user unlinks account)
     * 
     * @param string $token Access token or refresh token
     * @param string $tokenType 'access_token' or 'refresh_token'
     * @return bool True if revoked successfully
     */
    public function revokeToken(string $token, string $tokenType = 'access_token'): bool;

    /**
     * Get user info from bank using access token
     * 
     * @param string $accessToken Valid access token
     * @return array ['user_id' => string, 'accounts' => array, 'name' => string]
     */
    public function getUserInfo(string $accessToken): array;

    /**
     * Get account balance using access token
     * 
     * @param string $accessToken Valid access token
     * @param string $accountId Account identifier
     * @return array ['balance' => float, 'currency' => string, 'account_number' => string]
     */
    public function getAccountBalance(string $accessToken, string $accountId): array;

    /**
     * Get transaction history using access token
     * 
     * @param string $accessToken Valid access token
     * @param string $accountId Account identifier
     * @param int $limit Max transactions to return
     * @param int $offset Pagination offset
     * @return array ['transactions' => array, 'total' => int]
     */
    public function getTransactions(string $accessToken, string $accountId, int $limit = 50, int $offset = 0): array;

    // ============================================================================
    // SOURCE ROLE METHODS - Used by BOTH flows
    // ============================================================================

    /**
     * verify_asset - Verifies the customer owns the asset and has funds
     * Used for: e-wallet, voucher, account, card
     * 
     * @param array $payload Contains:
     * - reference: string
     * - asset_type: string (E-WALLET|VOUCHER|ACCOUNT|CARD)
     * - amount: float
     * - credentials: array (phone, number, pin, etc.)
     * - access_token: string (optional for OAuth-authenticated requests)
     * @return array ['verified' => bool, 'asset_id' => string, 'balance' => float]
     */
    public function verifyAsset(array $payload): array;

    /**
     * place_hold - Locks funds for pending transaction
     * 
     * @param array $payload Contains:
     * - reference: string
     * - asset_id: string
     * - amount: float
     * - expiry: string (hold expiry)
     * - access_token: string (optional)
     * @return array ['hold_placed' => bool, 'hold_reference' => string]
     */
    public function placeHold(array $payload): array;

    /**
     * debit_funds - Final debit after successful transaction
     * 
     * @param array $payload Contains:
     * - hold_reference: string
     * - amount: float
     * - destination_details: array
     * - access_token: string (optional)
     * @return array ['debited' => bool, 'transaction_reference' => string]
     */
    public function debitFunds(array $payload): array;

    /**
     * release_hold - Releases locked funds (for failures/reversals)
     * 
     * @param array $payload Contains:
     * - hold_reference: string
     * - reason: string
     * @return array ['released' => bool]
     */
    public function releaseHold(array $payload): array;

    // ============================================================================
    // DESTINATION ROLE METHODS - CASHOUT FLOW (ATM/AGENT)
    // ============================================================================

    /**
     * generate_token - Creates ATM/agent withdrawal code (CASHOUT TOKEN - NOT OAUTH)
     * This is DIFFERENT from OAuth token. This is a one-time cashout code.
     * 
     * @param array $payload Contains:
     * - reference: string
     * - beneficiary_phone: string
     * - amount: float
     * - code_hash: string
     * - expiry: string
     * @return array ['token_generated' => bool, 'token_reference' => string, 'atm_pin' => string]
     */
    public function generateToken(array $payload): array;

    /**
     * verify_token - Validates cashout token at cashout time
     * 
     * @param array $payload Contains:
     * - token_reference: string
     * - entered_code: string
     * @return array ['verified' => bool, 'amount' => float, 'beneficiary' => string]
     */
    public function verifyToken(array $payload): array;

    /**
     * confirm_cashout - Confirms cash was dispensed
     * 
     * @param array $payload Contains:
     * - token_reference: string
     * - dispensed_notes: array
     * - completed_at: string
     * @return array ['confirmed' => bool, 'settlement_triggered' => bool]
     */
    public function confirmCashout(array $payload): array;

    // ============================================================================
    // DESTINATION ROLE METHODS - DEPOSIT FLOW (ACCOUNT/WALLET)
    // ============================================================================

    /**
     * process_deposit - Credits funds to account/wallet/e-wallet/card
     * 
     * @param array $payload Contains:
     * - reference: string
     * - source_institution: string
     * - destination_type: string (ACCOUNT|WALLET|E-WALLET|CARD)
     * - destination_id: string (account number, wallet phone, card number)
     * - amount: float
     * - source_hold_reference: string
     * - access_token: string (optional)
     * @return array ['processed' => bool, 'transaction_reference' => string, 'new_balance' => float]
     */
    public function processDeposit(array $payload): array;

    // ============================================================================
    // LEGACY/COMPATIBILITY METHODS
    // ============================================================================

    /**
     * transfer - Legacy method for various transfer types
     * 
     * @param array $payload Transfer details
     * @param string|null $type Transfer type (generate_atm_code, deposit_direct, etc.)
     * @return array Transfer result
     */
    public function transfer(array $payload, ?string $type = null): array;

    /**
     * reverse - Reverse a previous transaction
     * 
     * @param array $payload Contains transaction reference
     * @return array Reversal result
     */
    public function reverse(array $payload): array;

    /**
     * checkStatus - Check status of a transaction
     * 
     * @param string $reference Transaction reference
     * @return array Status information
     */
    public function checkStatus(string $reference): array;
}
