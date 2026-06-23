<?php
// Infrastructure/Banks/Contracts/BankAPIInterface.php

namespace Infrastructure\Banks\Contracts;

/**
 * Complete Bank API Interface for VouchMorph Swap System
 * Supports all wallet_types: ACCOUNT, VOUCHER, E-WALLET, WALLET, CARD, ATM, AGENT
 * Handles both Source and Destination roles, plus OAuth authentication
 * 
 * NEW: Source Linking (Hooking) methods for one-time authorization
 */
interface BankAPIInterface
{
    // ============================================================================
    // SOURCE LINKING METHODS (Hooking)
    // ============================================================================

    /**
     * Initiate source linking - Step 1
     * - ZURUBANK: Returns OAuth redirect URL
     * - SACCUSSALIS: Sends OTP to user's phone
     * 
     * @param array $params
     *   - institution: string
     *   - identifier: string (phone, email, account number)
     *   - asset_type: string (ACCOUNT, BANK-WALLET, etc.)
     *   - redirect_uri: string (for OAuth)
     *   - state: string (for CSRF protection)
     *   - user_id: int
     * 
     * @return array
     *   - success: bool
     *   - auth_type: 'oauth'|'otp'|'ussd'
     *   - auth_id: string (for OTP verification)
     *   - redirect_url: string (for OAuth)
     *   - message: string
     *   - expires_in: int
     */
    public function initiateSourceLink(array $params): array;

    /**
     * Verify source linking - Step 2
     * - ZURUBANK: Exchange code for access_token
     * - SACCUSSALIS: Verify OTP
     * 
     * @param array $params
     *   - For OAuth: auth_type='oauth', code=string
     *   - For OTP: auth_type='otp', auth_id=string, otp=string
     * 
     * @return array
     *   - success: bool
     *   - authorized: bool
     *   - source_reference: string
     *   - access_token: string
     *   - refresh_token: string|null
     *   - expires_at: string
     *   - holder_name: string|null
     */
    public function verifySourceLink(array $params): array;

    /**
     * Refresh source token
     * 
     * @param array $params
     *   - refresh_token: string
     * 
     * @return array
     *   - success: bool
     *   - access_token: string
     *   - expires_at: string
     */
    public function refreshSourceToken(array $params): array;

    /**
     * Revoke source token
     * 
     * @param array $params
     *   - token: string (access_token or refresh_token)
     *   - token_type: string (access_token|refresh_token)
     * 
     * @return array
     *   - success: bool
     *   - message: string
     */
    public function revokeSourceToken(array $params): array;

    /**
     * Use source token to verify asset
     * 
     * @param array $params
     *   - source_reference: string
     *   - access_token: string
     *   - amount: float (optional)
     *   - currency: string (optional)
     * 
     * @return array
     *   - success: bool
     *   - verified: bool
     *   - balance: float
     *   - available_balance: float
     *   - holder_name: string|null
     */
    public function useSourceToken(array $params): array;

    // ============================================================================
    // OAUTH METHODS - For linking bank accounts (legacy, now part of source linking)
    // ============================================================================

    /**
     * Get OAuth authorization URL for redirecting user to bank
     * 
     * @param string $redirectUri Callback URL after authorization
     * @param string $state CSRF protection state parameter
     * @param array $scope Requested scopes
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
     * 
     * @param array $payload Contains:
     * - reference: string
     * - asset_type: string (E-WALLET|VOUCHER|ACCOUNT|CARD)
     * - amount: float
     * - credentials: array (phone, number, pin, etc.)
     * - access_token: string (for hooked sources)
     * - source_reference: string (for hooked sources)
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
     * - access_token: string (for hooked sources)
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
     * - access_token: string (for hooked sources)
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
     * generate_token - Creates ATM/agent withdrawal code
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
     * - destination_id: string
     * - amount: float
     * - source_hold_reference: string
     * - access_token: string (for hooked sources)
     * @return array ['processed' => bool, 'transaction_reference' => string, 'new_balance' => float]
     */
    public function processDeposit(array $payload): array;

    // ============================================================================
    // SIGNED METHODS (for VouchMorph to Bank communication)
    // ============================================================================

    /**
     * verifyAssetSigned - verify_asset with RSA signature and certificate
     */
    public function verifyAssetSigned(array $payload): array;

    /**
     * placeHoldSigned - place_hold with RSA signature and certificate
     */
    public function placeHoldSigned(array $payload): array;

    /**
     * transferWithProof - transfer with proof of source
     */
    public function transferWithProof(array $payload): array;

    /**
     * generateTokenWithProof - generate token with proof
     */
    public function generateTokenWithProof(array $payload): array;

    /**
     * processDepositWithProof - deposit with proof
     */
    public function processDepositWithProof(array $payload): array;

    // ============================================================================
    // LEGACY/COMPATIBILITY METHODS
    // ============================================================================

    /**
     * transfer - Legacy method for various transfer types
     * 
     * @param array $payload Transfer details
     * @param string|null $type Transfer type
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
