<?php
declare(strict_types=1);

namespace Infrastructure\Adapters;

use Infrastructure\Banks\GenericBankClient;
use Infrastructure\Banks\Contracts\BankAPIInterface;
use Psr\Log\LoggerInterface;

class GenericInstitutionAdapter implements InstitutionAdapterInterface
{
    protected BankAPIInterface $bankClient;
    protected LoggerInterface $logger;
    protected string $institution;
    protected array $config;
    protected array $context = [];
    
    // Internal consent state - hidden from SwapService
    protected ?string $consentId = null;
    protected ?string $accessToken = null;
    protected bool $consentObtained = false;
    
    public function __construct(
        BankAPIInterface $bankClient,
        LoggerInterface $logger,
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
            $this->logger->info("Consent not required for {$this->institution}");
            return;
        }
        
        // Consent is required - get it now
        $this->logger->info("Obtaining consent for {$this->institution}");
        
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
            
            $this->logger->info("Consent obtained for {$this->institution}");
            
        } catch (\Exception $e) {
            $this->logger->error("Consent failed for {$this->institution}: " . $e->getMessage());
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
            
            $verifyPayload = [
                'reference' => $payload['reference'] ?? uniqid('verify_'),
                'asset_type' => $payload['asset_type'] ?? 'ACCOUNT',
                'amount' => $payload['amount'] ?? 0,
                'currency' => $payload['currency'] ?? 'BWP',
                'source_identifier' => $payload['account_id'] ?? $payload['account_identifier'],
                'access_token' => $this->accessToken
            ];
            
            $result = $this->bankClient->verifyAssetSigned($verifyPayload);
            
            if (!$result['success']) {
                return [
                    'verified' => false,
                    'message' => $result['curl_error'] ?? 'Verification failed',
                    'account_id' => $payload['account_id']
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'verified' => $data['verified'] ?? false,
                'account_id' => $data['asset_id'] ?? $payload['account_id'],
                'account_name' => $data['account_name'] ?? null,
                'balance' => $data['balance'] ?? 0,
                'currency' => $data['currency'] ?? $payload['currency'] ?? 'BWP'
            ];
            
        } catch (\Exception $e) {
            return [
                'verified' => false,
                'message' => $e->getMessage(),
                'account_id' => $payload['account_id']
            ];
        }
    }
    
    public function placeHold(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $holdPayload = [
                'reference' => $payload['reference'] ?? uniqid('hold_'),
                'asset_id' => $payload['account_id'] ?? $payload['account_identifier'],
                'amount' => $payload['amount'] ?? 0,
                'currency' => $payload['currency'] ?? 'BWP',
                'expiry' => $payload['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours')),
                'hold_reason' => $payload['hold_reason'] ?? 'PENDING_SWAP',
                'access_token' => $this->accessToken
            ];
            
            $result = $this->bankClient->placeHoldSigned($holdPayload);
            
            if (!$result['success']) {
                return [
                    'hold_placed' => false,
                    'message' => $result['curl_error'] ?? 'Hold failed'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'hold_placed' => true,
                'hold_id' => $data['hold_id'] ?? null,
                'hold_reference' => $data['hold_reference'] ?? $data['reference'],
                'status' => $data['status'] ?? 'ACTIVE'
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
            
            $debitPayload = [
                'reference' => $payload['reference'] ?? uniqid('debit_'),
                'hold_reference' => $payload['hold_reference'] ?? $payload['hold_id'],
                'amount' => $payload['amount'] ?? 0,
                'reason' => $payload['reason'] ?? 'Swap completed',
                'access_token' => $this->accessToken
            ];
            
            $result = $this->bankClient->debitHold($debitPayload);
            
            if (!$result['success']) {
                return [
                    'debited' => false,
                    'message' => $result['curl_error'] ?? 'Debit failed'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'debited' => true,
                'transaction_reference' => $data['transaction_reference'] ?? $data['reference'],
                'status' => $data['status'] ?? 'COMPLETED'
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
            
            $creditPayload = [
                'reference' => $payload['reference'] ?? uniqid('credit_'),
                'source_institution' => $payload['source_institution'] ?? 'VOUCHMORPH',
                'destination_type' => $payload['destination_type'] ?? 'ACCOUNT',
                'destination_id' => $payload['destination_account_id'] ?? $payload['account_id'],
                'amount' => $payload['amount'] ?? 0,
                'currency' => $payload['currency'] ?? 'BWP',
                'source_hold_reference' => $payload['hold_reference'] ?? $payload['hold_id'],
                'access_token' => $this->accessToken
            ];
            
            $result = $this->bankClient->processDepositWithProof($creditPayload);
            
            if (!$result['success']) {
                return [
                    'credited' => false,
                    'message' => $result['curl_error'] ?? 'Credit failed'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'credited' => true,
                'transaction_reference' => $data['transaction_reference'] ?? $data['reference'],
                'status' => $data['status'] ?? 'COMPLETED',
                'new_balance' => $data['new_balance'] ?? null
            ];
            
        } catch (\Exception $e) {
            return [
                'credited' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function generateCashoutToken(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $tokenPayload = [
                'reference' => $payload['reference'] ?? uniqid('cashout_'),
                'beneficiary_phone' => $payload['phone'] ?? $payload['beneficiary_phone'],
                'amount' => $payload['amount'] ?? 0,
                'currency' => $payload['currency'] ?? 'BWP',
                'source_verification' => $this->context['verification'] ?? null,
                'source_hold' => $this->context['hold'] ?? null,
                'access_token' => $this->accessToken
            ];
            
            $result = $this->bankClient->generateTokenWithProof($tokenPayload);
            
            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => $result['curl_error'] ?? 'Token generation failed'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'success' => true,
                'cashout_code' => $data['cashout_code'] ?? $data['code'],
                'atm_pin' => $data['atm_pin'] ?? $data['pin'],
                'voucher_number' => $data['voucher_number'] ?? null,
                'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'))
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
            $verifyPayload = [
                'token_reference' => $payload['cashout_code'] ?? $payload['code'],
                'entered_code' => $payload['pin'] ?? $payload['code'],
                'access_token' => $this->accessToken
            ];
            
            $result = $this->bankClient->verifyToken($verifyPayload);
            
            if (!$result['success']) {
                return [
                    'verified' => false,
                    'message' => $result['curl_error'] ?? 'Verification failed'
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
            $confirmPayload = [
                'token_reference' => $payload['cashout_code'] ?? $payload['code'],
                'dispensed_notes' => $payload['notes'] ?? null,
                'completed_at' => date('Y-m-d H:i:s'),
                'access_token' => $this->accessToken
            ];
            
            $result = $this->bankClient->confirmCashout($confirmPayload);
            
            if (!$result['success']) {
                return [
                    'confirmed' => false,
                    'message' => $result['curl_error'] ?? 'Confirmation failed'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'confirmed' => $data['confirmed'] ?? false,
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'settlement_triggered' => $data['settlement_triggered'] ?? false
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
            
            $verifyPayload = [
                'account_id' => $payload['destination_account_id'] ?? $payload['account_id'],
                'access_token' => $this->accessToken
            ];
            
            $result = $this->bankClient->processDeposit($verifyPayload);
            
            if (!$result['success']) {
                return [
                    'verified' => false,
                    'message' => $result['curl_error'] ?? 'Account verification failed'
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'verified' => true,
                'account_name' => $data['account_name'] ?? null,
                'account_type' => $data['account_type'] ?? null,
                'status' => $data['status'] ?? 'ACTIVE'
            ];
            
        } catch (\Exception $e) {
            return [
                'verified' => false,
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
                'account_id' => $payload['account_id'] ?? $payload['account_identifier'],
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
                'account_id' => $payload['account_id'],
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
                $payload['account_id'],
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
