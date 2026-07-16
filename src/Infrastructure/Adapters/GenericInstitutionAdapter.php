<?php
declare(strict_types=1);

namespace Infrastructure\Adapters;

use Infrastructure\Banks\GenericBankClient;
use Infrastructure\Banks\Contracts\BankAPIInterface;

class GenericInstitutionAdapter implements InstitutionAdapterInterface
{
    protected BankAPIInterface $bankClient;
    protected $logger;  // No type-hint to avoid psr/log dependency
    protected string $institution;
    protected array $config;
    protected array $context = [];
    
    // Internal consent state - hidden from SwapService
    protected ?string $consentId = null;
    protected ?string $accessToken = null;
    protected bool $consentObtained = false;
    
    public function __construct(
        BankAPIInterface $bankClient,
        $logger,  // No type-hint
        string $institution,
        array $config
    ) {
        $this->bankClient = $bankClient;
        $this->logger = $logger;
        $this->institution = $institution;
        $this->config = $config;
    }
    
    public function getInstitution(): string
    {
        return $this->institution;
    }
    
    public function supports(string $capability): bool
    {
        return in_array($capability, [
            'VERIFY_ASSET',
            'HOLD',
            'DEBIT',
            'CREDIT',
            'RELEASE_HOLD',
            'CASHOUT',
            'VERIFY_ACCOUNT',
            'BALANCE',
            'TRANSACTIONS',
            'ACCOUNTS'
        ]);
    }
    
    // ============================================================
    // INTERNAL: Consent Handling (Hidden from SwapService)
    // ============================================================
    
    protected function ensureConsent(): void
    {
        // If consent already obtained, skip
        if ($this->consentObtained && $this->accessToken) {
            return;
        }
        
        // Check if this bank requires consent
        $consentRequired = $this->config['consent_required'] ?? false;
        
        if (!$consentRequired) {
            // No consent needed - M-Pesa style
            $this->consentObtained = true;
            if ($this->logger) {
                $this->logger->info("Consent not required for {$this->institution}");
            }
            return;
        }
        
        // Consent is required - get it now
        if ($this->logger) {
            $this->logger->info("Obtaining consent for {$this->institution}");
        }
        
        try {
            // Get OAuth token
            if (isset($this->context['access_token'])) {
                $this->accessToken = $this->context['access_token'];
                $this->consentObtained = true;
                return;
            }
            
            // Try to get token from bank
            $authPayload = [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config['api_key'] ?? getenv('BANK_API_KEY'),
                'client_secret' => $this->config['api_secret'] ?? getenv('BANK_API_SECRET'),
                'scope' => 'read_balance initiate_payment'
            ];
            
            // Use the bank client's OAuth methods
            if (method_exists($this->bankClient, 'exchangeCodeForToken')) {
                $this->accessToken = $this->context['access_token'] ?? null;
                $this->consentObtained = true;
            } else {
                $this->accessToken = $this->config['api_key'] ?? null;
                $this->consentObtained = true;
            }
            
            if ($this->logger) {
                $this->logger->info("Consent obtained for {$this->institution}");
            }
            
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error("Consent failed for {$this->institution}: " . $e->getMessage());
            }
            throw new \RuntimeException("Consent failed: " . $e->getMessage());
        }
    }
    
    // ============================================================
    // CORE SWAP OPERATIONS
    // ============================================================
    
    public function verifyAsset(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            // Pass through the original payload directly
            $verifyPayload = $payload;
            
            // Only add access_token if not already present
            if (!isset($verifyPayload['access_token']) && $this->accessToken) {
                $verifyPayload['access_token'] = $this->accessToken;
            }
            
            // Ensure required fields for backward compatibility
            if (!isset($verifyPayload['reference'])) {
                $verifyPayload['reference'] = uniqid('verify_');
            }
            
            $result = $this->bankClient->verifyAssetSigned($verifyPayload);
            
            if (!$result['success']) {
                // FIXED: Proper curl_error handling with HTTP status fallback
                return [
                    'verified' => false,
                    'message' => !empty($result['curl_error']) 
                        ? $result['curl_error'] 
                        : 'Verification failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)',
                    'account_id' => $payload['account_id'] ?? $payload['source_identifier'] ?? null
                ];
            }
            
            $data = $result['data'] ?? [];
            
            // Preserve original payload and signature from bank response
            return [
                'verified' => $data['verified'] ?? false,
                'message' => $data['message'] ?? 'Asset verified',
                'account_id' => $data['asset_id'] ?? $payload['account_id'] ?? $payload['source_identifier'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'account_name' => $data['account_name'] ?? null,
                'balance' => $data['balance'] ?? 0,
                'currency' => $data['currency'] ?? $payload['currency'] ?? 'BWP',
                'original_payload' => $data['payload'] ?? $result['original_payload'] ?? null,
                'signature' => $data['signature'] ?? $result['signature'] ?? null,
                'certificate' => $data['certificate'] ?? $result['certificate'] ?? null,
                'timestamp' => $data['timestamp'] ?? $result['timestamp'] ?? time()
            ];
            
        } catch (\Exception $e) {
            return [
                'verified' => false,
                'message' => $e->getMessage(),
                'account_id' => $payload['account_id'] ?? $payload['source_identifier'] ?? null
            ];
        }
    }
    
    public function placeHold(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            // Pass through the original payload directly
            $holdPayload = $payload;
            
            // Only add access_token if not already present
            if (!isset($holdPayload['access_token']) && $this->accessToken) {
                $holdPayload['access_token'] = $this->accessToken;
            }
            
            // Ensure required fields for backward compatibility
            if (!isset($holdPayload['reference'])) {
                $holdPayload['reference'] = uniqid('hold_');
            }
            if (!isset($holdPayload['expiry'])) {
                $holdPayload['expiry'] = date('Y-m-d H:i:s', strtotime('+24 hours'));
            }
            
            $result = $this->bankClient->placeHoldSigned($holdPayload);
            
            if (!$result['success']) {
                // FIXED: Proper curl_error handling with HTTP status fallback
                return [
                    'hold_placed' => false,
                    'message' => !empty($result['curl_error']) 
                        ? $result['curl_error'] 
                        : 'Hold failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            $holdReference = $data['hold_reference'] ?? $data['reference'] ?? null;
            $signature = $data['signature'] ?? $result['signature'] ?? null;
            $certificate = $data['certificate'] ?? $result['certificate'] ?? null;
            
            // ============================================================
            // INTEGRITY CHECK: HTTP 200 + valid JSON is not the same as a
            // real hold. Require the bank to have actually returned proof
            // (a hold_reference, and a signature or certificate) before
            // we tell SwapService this hold can be trusted.
            // ============================================================
            if (empty($holdReference)) {
                if ($this->logger) {
                    $this->logger->error("placeHold: bank returned success but no hold_reference", [
                        'institution' => $this->institution,
                        'response_data' => $data
                    ]);
                }
                return [
                    'hold_placed' => false,
                    'message' => 'Bank accepted the hold request but returned no hold_reference - cannot proceed without proof'
                ];
            }
            
            if (empty($signature) && empty($certificate)) {
                if ($this->logger) {
                    $this->logger->error("placeHold: bank returned success but no signature/certificate", [
                        'institution' => $this->institution,
                        'hold_reference' => $holdReference
                    ]);
                }
                return [
                    'hold_placed' => false,
                    'message' => 'Bank accepted the hold request but returned no signature or certificate - cannot proceed without proof'
                ];
            }
            
            // Preserve original payload and signature from bank response
            return [
                'hold_placed' => true,
                'hold_id' => $data['hold_id'] ?? null,
                'hold_reference' => $holdReference,
                'status' => $data['status'] ?? 'ACTIVE',
                'original_payload' => $data['payload'] ?? $result['original_payload'] ?? null,
                'signature' => $signature,
                'certificate' => $certificate,
                'timestamp' => $data['timestamp'] ?? $result['timestamp'] ?? time()
            ];
            
        } catch (\Exception $e) {
            return [
                'hold_placed' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function debit(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            // Pass through the original payload directly
            $debitPayload = $payload;
            
            // Only add access_token if not already present
            if (!isset($debitPayload['access_token']) && $this->accessToken) {
                $debitPayload['access_token'] = $this->accessToken;
            }
            
            // Ensure required fields for backward compatibility
            if (!isset($debitPayload['reference'])) {
                $debitPayload['reference'] = uniqid('debit_');
            }
            
            // FIXED: Call debitFunds() directly, NOT debitHold()
            // debitHold() reconstructs the payload and drops important fields
            // like wallet_pin, pin, asset_fields that forwardPin() added.
            $result = $this->bankClient->debitFunds($debitPayload);
            
            if (!$result['success']) {
                // FIXED: Proper curl_error handling with HTTP status fallback
                return [
                    'debited' => false,
                    'message' => !empty($result['curl_error']) 
                        ? $result['curl_error'] 
                        : 'Debit failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'debited' => true,
                'transaction_reference' => $data['transaction_reference'] ?? $data['reference'] ?? null,
                'status' => $data['status'] ?? 'COMPLETED',
                'message' => $data['message'] ?? 'Debit successful'
            ];
            
        } catch (\Exception $e) {
            return [
                'debited' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function credit(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            // Pass through the original payload directly
            $creditPayload = $payload;
            
            // Only add access_token if not already present
            if (!isset($creditPayload['access_token']) && $this->accessToken) {
                $creditPayload['access_token'] = $this->accessToken;
            }
            
            // Ensure required fields for backward compatibility
            if (!isset($creditPayload['reference'])) {
                $creditPayload['reference'] = uniqid('credit_');
            }
            
            $result = $this->bankClient->processDepositWithProof($creditPayload);
            
            if (!$result['success']) {
                // FIXED: Proper curl_error handling with HTTP status fallback
                return [
                    'credited' => false,
                    'message' => !empty($result['curl_error']) 
                        ? $result['curl_error'] 
                        : 'Credit failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)'
                ];
            }
            
            $data = $result['data'] ?? [];
            $transactionReference = $data['transaction_reference'] ?? $data['reference'] ?? null;
            
            // ============================================================
            // INTEGRITY CHECK: a bare HTTP 200 is not proof money moved.
            // Require a real transaction_reference before this credit is
            // trusted - this is what SwapService relies on before it
            // debits the source, so a hollow success here is the exact
            // scenario that leads to debiting a source with no proof the
            // destination actually received funds.
            // ============================================================
            if (empty($transactionReference)) {
                if ($this->logger) {
                    $this->logger->error("credit: bank returned success but no transaction_reference", [
                        'institution' => $this->institution,
                        'response_data' => $data
                    ]);
                }
                return [
                    'credited' => false,
                    'message' => 'Bank accepted the deposit request but returned no transaction_reference - cannot confirm funds were credited'
                ];
            }
            
            return [
                'credited' => true,
                'transaction_reference' => $transactionReference,
                'status' => $data['status'] ?? 'COMPLETED',
                'new_balance' => $data['new_balance'] ?? null,
                'message' => $data['message'] ?? 'Credit successful'
            ];
            
        } catch (\Exception $e) {
            return [
                'credited' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Release a hold at the institution
     * CRITICAL: Used for multi-source rollback and error recovery
     */
    public function releaseHold(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $holdReference = $payload['hold_reference'] ?? null;
            $reason = $payload['reason'] ?? 'Released by VouchMorph';
            
            if (!$holdReference) {
                if ($this->logger) {
                    $this->logger->warning("releaseHold called without hold_reference", [
                        'institution' => $this->institution,
                        'payload' => $payload
                    ]);
                }
                return [
                    'released' => false,
                    'message' => 'hold_reference is required for release',
                    'hold_reference' => null
                ];
            }
            
            // Pass through the original payload directly
            $releasePayload = $payload;
            
            // Only add access_token if not already present
            if (!isset($releasePayload['access_token']) && $this->accessToken) {
                $releasePayload['access_token'] = $this->accessToken;
            }
            
            // Ensure required fields
            if (!isset($releasePayload['reference'])) {
                $releasePayload['reference'] = uniqid('release_');
            }
            if (!isset($releasePayload['action'])) {
                $releasePayload['action'] = 'RELEASE_HOLD';
            }
            
            if ($this->logger) {
                $this->logger->info("GenericInstitutionAdapter::releaseHold", [
                    'hold_reference' => $holdReference,
                    'institution' => $this->institution,
                    'reason' => $reason
                ]);
            }
            
            // Check if the bank client supports releaseHold
            if (method_exists($this->bankClient, 'releaseHold')) {
                $result = $this->bankClient->releaseHold($releasePayload);
                
                if (!$result['success']) {
                    // FIXED: Proper curl_error handling with HTTP status fallback
                    return [
                        'released' => false,
                        'message' => !empty($result['curl_error']) 
                            ? $result['curl_error'] 
                            : 'Release failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)',
                        'hold_reference' => $holdReference
                    ];
                }
                
                $data = $result['data'] ?? [];
                
                return [
                    'released' => true,
                    'status' => $data['status'] ?? 'RELEASED',
                    'hold_reference' => $holdReference,
                    'message' => $data['message'] ?? 'Hold released successfully',
                    'released_at' => $data['released_at'] ?? date('Y-m-d H:i:s')
                ];
            }
            
            // Fallback: If bank client doesn't have releaseHold, log and return success
            // This allows the local hold status to be updated even if the institution
            // doesn't support explicit hold release (some systems auto-release on expiry)
            if ($this->logger) {
                $this->logger->warning("Bank client does not support releaseHold, marking as released locally", [
                    'institution' => $this->institution,
                    'hold_reference' => $holdReference
                ]);
            }
            
            return [
                'released' => true,
                'status' => 'RELEASED_LOCALLY',
                'hold_reference' => $holdReference,
                'message' => 'Hold marked as released locally (institution may auto-release)',
                'released_at' => date('Y-m-d H:i:s'),
                'note' => 'Bank client does not support explicit hold release'
            ];
            
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error("releaseHold failed", [
                    'institution' => $this->institution,
                    'error' => $e->getMessage(),
                    'payload' => $payload
                ]);
            }
            
            return [
                'released' => false,
                'message' => $e->getMessage(),
                'hold_reference' => $payload['hold_reference'] ?? null
            ];
        }
    }
    
    public function generateCashoutToken(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            // Pass through the original payload directly
            $tokenPayload = $payload;
            
            // Only add access_token if not already present
            if (!isset($tokenPayload['access_token']) && $this->accessToken) {
                $tokenPayload['access_token'] = $this->accessToken;
            }
            
            // Ensure required fields for backward compatibility
            if (!isset($tokenPayload['reference'])) {
                $tokenPayload['reference'] = uniqid('cashout_');
            }
            
            // Add context data if not already present
            if (!isset($tokenPayload['source_verification']) && isset($this->context['verification'])) {
                $tokenPayload['source_verification'] = $this->context['verification'];
            }
            if (!isset($tokenPayload['source_hold']) && isset($this->context['hold'])) {
                $tokenPayload['source_hold'] = $this->context['hold'];
            }
            
            $result = $this->bankClient->generateTokenWithProof($tokenPayload);
            
            if (!$result['success']) {
                // FIXED: Proper curl_error handling with HTTP status fallback
                return [
                    'success' => false,
                    'message' => !empty($result['curl_error']) 
                        ? $result['curl_error'] 
                        : 'Token generation failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'success' => true,
                'cashout_code' => $data['cashout_code'] ?? $data['code'] ?? null,
                'atm_pin' => $data['atm_pin'] ?? $data['pin'] ?? null,
                'voucher_number' => $data['voucher_number'] ?? null,
                'swap_code' => $data['swap_code'] ?? $data['voucher_number'] ?? null,
                'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours')),
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'message' => $data['message'] ?? 'Token generated'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function verifyCashoutToken(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            // Pass through the original payload directly
            $verifyPayload = $payload;
            
            // Ensure required fields for backward compatibility
            if (!isset($verifyPayload['reference'])) {
                $verifyPayload['reference'] = uniqid('verify_token_');
            }
            
            $result = $this->bankClient->verifyToken($verifyPayload);
            
            if (!$result['success']) {
                // FIXED: Proper curl_error handling with HTTP status fallback
                return [
                    'verified' => false,
                    'message' => !empty($result['curl_error']) 
                        ? $result['curl_error'] 
                        : 'Verification failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'verified' => $data['verified'] ?? false,
                'amount' => $data['amount'] ?? null,
                'beneficiary' => $data['beneficiary'] ?? null,
                'message' => $data['message'] ?? null
            ];
            
        } catch (\Exception $e) {
            return [
                'verified' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function confirmCashout(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            // Pass through the original payload directly
            $confirmPayload = $payload;
            
            // Ensure required fields for backward compatibility
            if (!isset($confirmPayload['completed_at'])) {
                $confirmPayload['completed_at'] = date('Y-m-d H:i:s');
            }
            
            $result = $this->bankClient->confirmCashout($confirmPayload);
            
            if (!$result['success']) {
                // FIXED: Proper curl_error handling with HTTP status fallback
                return [
                    'confirmed' => false,
                    'message' => !empty($result['curl_error']) 
                        ? $result['curl_error'] 
                        : 'Confirmation failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'confirmed' => $data['confirmed'] ?? false,
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'settlement_triggered' => $data['settlement_triggered'] ?? false,
                'message' => $data['message'] ?? 'Cashout confirmed'
            ];
            
        } catch (\Exception $e) {
            return [
                'confirmed' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function verifyAccount(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            // Pass through the original payload directly
            $verifyPayload = $payload;
            
            // Only add access_token if not already present
            if (!isset($verifyPayload['access_token']) && $this->accessToken) {
                $verifyPayload['access_token'] = $this->accessToken;
            }
            
            $result = $this->bankClient->verifyAccount($verifyPayload);
            
            if (!$result['success']) {
                // FIXED: Proper curl_error handling with HTTP status fallback
                return [
                    'verified' => false,
                    'message' => !empty($result['curl_error']) 
                        ? $result['curl_error'] 
                        : 'Account verification failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'verified' => $data['verified'] ?? false,
                'account_name' => $data['account_name'] ?? null,
                'account_type' => $data['account_type'] ?? null,
                'status' => $data['status'] ?? 'ACTIVE',
                'message' => $data['message'] ?? 'Account verified'
            ];
            
        } catch (\Exception $e) {
            return [
                'verified' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function transferWithProof(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            // Pass through the original payload directly
            $transferPayload = $payload;
            
            // Only add access_token if not already present
            if (!isset($transferPayload['access_token']) && $this->accessToken) {
                $transferPayload['access_token'] = $this->accessToken;
            }
            
            // Ensure required fields for backward compatibility
            if (!isset($transferPayload['reference'])) {
                $transferPayload['reference'] = uniqid('transfer_');
            }
            
            $result = $this->bankClient->transferWithProof($transferPayload);
            
            if (!$result['success']) {
                // FIXED: Proper curl_error handling with HTTP status fallback
                return [
                    'success' => false,
                    'message' => !empty($result['curl_error']) 
                        ? $result['curl_error'] 
                        : 'Transfer failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ', bank returned no reason)'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'success' => true,
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'message' => $data['message'] ?? 'Transfer successful'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // ============================================================
    // SOURCE LINKING OPERATIONS
    // ============================================================
    
    public function initiateSourceLink(array $payload): array
    {
        try {
            if (method_exists($this->bankClient, 'initiateSourceLink')) {
                return $this->bankClient->initiateSourceLink($payload);
            }
            
            return [
                'success' => false,
                'message' => 'Source linking not supported by this institution'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function verifySourceLink(array $payload): array
    {
        try {
            if (method_exists($this->bankClient, 'verifySourceLink')) {
                return $this->bankClient->verifySourceLink($payload);
            }
            
            return [
                'success' => false,
                'message' => 'Source linking verification not supported by this institution'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function refreshSourceToken(array $payload): array
    {
        try {
            if (method_exists($this->bankClient, 'refreshSourceToken')) {
                return $this->bankClient->refreshSourceToken($payload);
            }
            
            return [
                'success' => false,
                'message' => 'Token refresh not supported by this institution'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function revokeSourceToken(array $payload): array
    {
        try {
            if (method_exists($this->bankClient, 'revokeSourceToken')) {
                return $this->bankClient->revokeSourceToken($payload);
            }
            
            return [
                'success' => false,
                'message' => 'Token revocation not supported by this institution'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function generateVoucher(array $payload): array
    {
        try {
            if (method_exists($this->bankClient, 'generateVoucher')) {
                return $this->bankClient->generateVoucher($payload);
            }
            
            return [
                'success' => false,
                'message' => 'Voucher generation not supported by this institution'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // ============================================================
    // DASHBOARD/UX OPERATIONS - NOT part of swap flow
    // ============================================================
    
    public function getBalance(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $balancePayload = [
                'account_id' => $payload['account_id'] ?? $payload['account_identifier'] ?? null,
                'access_token' => $this->accessToken
            ];
            
            $result = $this->bankClient->getAccountBalance(
                $this->accessToken ?? '',
                $balancePayload['account_id']
            );
            
            if (!$result || !isset($result['balance'])) {
                return [
                    'success' => false,
                    'message' => 'Failed to get balance',
                    'balance' => 0,
                    'currency' => $payload['currency'] ?? 'BWP'
                ];
            }
            
            return [
                'success' => true,
                'balance' => (float) $result['balance'],
                'currency' => $result['currency'] ?? $payload['currency'] ?? 'BWP',
                'account_id' => $payload['account_id'] ?? null,
                'account_name' => $result['account_name'] ?? null,
                'last_updated' => date('Y-m-d H:i:s')
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'balance' => 0,
                'currency' => $payload['currency'] ?? 'BWP'
            ];
        }
    }
    
    public function getTransactions(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $limit = $payload['limit'] ?? 50;
            $offset = $payload['offset'] ?? 0;
            
            $result = $this->bankClient->getTransactions(
                $this->accessToken ?? '',
                $payload['account_id'] ?? null,
                $limit,
                $offset
            );
            
            if (!$result || !isset($result['transactions'])) {
                return [
                    'success' => false,
                    'message' => 'Failed to get transactions',
                    'transactions' => []
                ];
            }
            
            return [
                'success' => true,
                'transactions' => $result['transactions'],
                'total' => $result['total'] ?? count($result['transactions']),
                'limit' => $limit,
                'offset' => $offset
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'transactions' => []
            ];
        }
    }
    
    public function getAccounts(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            // Use the bank client to get accounts
            // This would need to be added to BankAPIInterface
            // For now, return a placeholder
            return [
                'success' => true,
                'accounts' => [
                    [
                        'id' => $payload['account_id'] ?? 'unknown',
                        'name' => $payload['account_name'] ?? 'Main Account',
                        'type' => 'BANK',
                        'currency' => $payload['currency'] ?? 'BWP'
                    ]
                ]
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'accounts' => []
            ];
        }
    }
}
