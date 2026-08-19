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
    

    // ============================================================
    // INTERNAL: Consent Handling (Hidden from SwapService)
    // ============================================================
    
    protected function ensureConsent(): void
    {
        if ($this->consentObtained && $this->accessToken) {
            return;
        }
        
        $consentRequired = $this->config['consent_required'] ?? false;
        
        if (!$consentRequired) {
            $this->consentObtained = true;
            if ($this->logger) {
                $this->logger->info("Consent not required for {$this->institution}");
            }
            return;
        }
        
        if ($this->logger) {
            $this->logger->info("Obtaining consent for {$this->institution}");
        }
        
        try {
            if (isset($this->context['access_token'])) {
                $this->accessToken = $this->context['access_token'];
                $this->consentObtained = true;
                return;
            }
            
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
            'ACCOUNTS',
            'SETTLEMENT_STATUS',   // NEW
        ]);
    }
    
    // ============================================================
    // CORE SWAP OPERATIONS - STANDARDIZED
    // ============================================================
    
    public function verifyAsset(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $verifyPayload = $payload;
            
            if (!isset($verifyPayload['access_token']) && $this->accessToken) {
                $verifyPayload['access_token'] = $this->accessToken;
            }
            
            if (!isset($verifyPayload['reference'])) {
                $verifyPayload['reference'] = $context['swap_reference'] ?? uniqid('verify_');
            }
            if (!isset($verifyPayload['timestamp'])) {
                $verifyPayload['timestamp'] = time();
            }
            if (!isset($verifyPayload['requester'])) {
                $verifyPayload['requester'] = 'VOUCHMORPH';
            }
            if (!isset($verifyPayload['action'])) {
                $verifyPayload['action'] = 'VERIFY_ASSET';
            }
            if (!isset($verifyPayload['from_institution'])) {
                $verifyPayload['from_institution'] = $this->institution;
            }
            if (!isset($verifyPayload['source_institution'])) {
                $verifyPayload['source_institution'] = $this->institution;
            }
            
            $result = $this->bankClient->verifyAssetSigned($verifyPayload);
            
            if (!$result['success'] || !($result['verified'] ?? false)) {
                return [
                    'verified' => false,
                    'success' => false,
                    'message' => $result['message'] ?? $result['curl_error'] ?? 'Verification failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ')',
                    'account_id' => $payload['account_id'] ?? $payload['source_identifier'] ?? null,
                    'status_code' => $result['status_code'] ?? 0,
                    'raw_response' => $result['raw_response'] ?? null
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'verified' => $data['verified'] ?? $result['success'] ?? true,
                'success' => $data['success'] ?? $result['success'] ?? true,
                'message' => $data['message'] ?? 'Asset verified',
                'account_id' => $data['asset_id'] ?? $payload['account_id'] ?? $payload['source_identifier'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'account_name' => $data['account_name'] ?? null,
                'balance' => $data['balance'] ?? 0,
                'currency' => $data['currency'] ?? $payload['currency'] ?? 'BWP',
                'original_payload' => $data['payload'] ?? $result['original_payload'] ?? $verifyPayload,
                'signature' => $data['signature'] ?? $result['signature'] ?? null,
                'certificate' => $data['certificate'] ?? $result['certificate'] ?? null,
                'timestamp' => $data['timestamp'] ?? $result['timestamp'] ?? time(),
                'raw_response' => $result['raw_response'] ?? null,
                'status_code' => $result['status_code'] ?? 0
            ];
            
        } catch (\Exception $e) {
            return [
                'verified' => false,
                'success' => false,
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
            
            $holdPayload = $payload;
            
            if (!isset($holdPayload['access_token']) && $this->accessToken) {
                $holdPayload['access_token'] = $this->accessToken;
            }
            
            if (!isset($holdPayload['reference'])) {
                $holdPayload['reference'] = $context['swap_reference'] ?? uniqid('hold_');
            }
            if (!isset($holdPayload['expiry'])) {
                $holdPayload['expiry'] = date('Y-m-d H:i:s', strtotime('+24 hours'));
            }
            if (!isset($holdPayload['timestamp'])) {
                $holdPayload['timestamp'] = time();
            }
            if (!isset($holdPayload['action'])) {
                $holdPayload['action'] = 'PLACE_HOLD';
            }
            if (!isset($holdPayload['from_institution'])) {
                $holdPayload['from_institution'] = $this->institution;
            }
            if (!isset($holdPayload['source_institution'])) {
                $holdPayload['source_institution'] = $this->institution;
            }
            
            $result = $this->bankClient->placeHoldSigned($holdPayload);
            
            if (!$result['success'] || !($result['hold_placed'] ?? false)) {
                return [
                    'hold_placed' => false,
                    'success' => false,
                    'message' => $result['message'] ?? $result['curl_error'] ?? 'Hold failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ')',
                    'status_code' => $result['status_code'] ?? 0,
                    'raw_response' => $result['raw_response'] ?? null
                ];
            }
            
            $data = $result['data'] ?? [];
            
            // FIX: this only checked $data['hold_reference'] / $data['reference'] --
            // the RAW bank response fields -- completely ignoring $result['hold_reference'],
            // which the bank client (e.g. CardAcquirerBankClient::placeHold()) already
            // computed correctly, including its own fallback to authorization_reference
            // for acquirers like FNBB that never send a field literally named
            // hold_reference or reference. Confirmed live: FNBB's /Authorize.php
            // response only has authorization_reference/authorization_code, and this
            // layer independently re-derived (and failed to find) a hold reference the
            // bank client had already resolved one line of code away. Adding
            // authorization_reference to the local check AND falling back to the bank
            // client's own $result['hold_reference'] as the ultimate source of truth --
            // trusting the lower layer's already-correct computation instead of
            // duplicating (and under-covering) its extraction logic here.
            $holdReference = $data['hold_reference']
                ?? $data['authorization_reference']
                ?? $data['reference']
                ?? $result['hold_reference']
                ?? null;
            $signature = $data['signature'] ?? $result['signature'] ?? null;
            $certificate = $data['certificate'] ?? $result['certificate'] ?? null;
            
            if (empty($holdReference)) {
                if ($this->logger) {
                    $this->logger->error("placeHold: bank returned success but no hold_reference", [
                        'institution' => $this->institution,
                        'response_data' => $data
                    ]);
                }
                return [
                    'hold_placed' => false,
                    'success' => false,
                    'message' => 'Bank accepted the hold request but returned no hold_reference - cannot proceed without proof',
                    'raw_response' => $result['raw_response'] ?? null
                ];
            }
            
            // FIX: the signature/certificate requirement below is the correct
            // trust model for VouchMorph NETWORK PEERS (ZURUBANK, SACCUSSALIS,
            // etc.) -- they speak VouchMorph's own signed-response protocol, so
            // an unsigned "success" response from one of them really is
            // suspicious and worth rejecting.
            //
            // It's the WRONG model for CARD ACQUIRERS (FNBB and any future
            // acquirer integration). Real-world card acquiring (Stripe, Adyen,
            // a bank's own acquiring API) never signs individual authorization
            // responses message-by-message -- that isn't a gap, it's just not
            // how trust works in that relationship. What actually protects
            // this call: (1) TLS on the connection itself
            // (CURLOPT_SSL_VERIFYPEER/VERIFYHOST, already enforced in
            // GenericBankClient::send()) -- nobody can inject a fake
            // "Authorized" response without breaking TLS; (2) the REQUEST is
            // signed with VouchMorph's own certificate, which is the correct
            // direction of trust for this relationship (the acquirer
            // authenticates the merchant/PSP, not the reverse); (3) the
            // authorization_code itself (e.g. "43B0ED") IS the proof --
            // literally the same kind of code printed on a card receipt,
            // retained for the audit/dispute trail. Applying the peer-network
            // signed-response rule to an acquirer that was never going to
            // satisfy it isn't a security check catching a real problem --
            // it's the wrong trust model for this class of counterparty.
            //
            // Detected the same way CardAcquirerBankClient's own capability
            // gates are: presence of a card_acquirer block in this
            // institution's config, rather than a hardcoded class name, so any
            // future acquirer-style integration is covered automatically.
            $isCardAcquirer = isset($this->config['card_acquirer']);

            if (!$isCardAcquirer && empty($signature) && empty($certificate)) {
                if ($this->logger) {
                    $this->logger->error("placeHold: bank returned success but no signature/certificate", [
                        'institution' => $this->institution,
                        'hold_reference' => $holdReference
                    ]);
                }
                return [
                    'hold_placed' => false,
                    'success' => false,
                    'message' => 'Bank accepted the hold request but returned no signature or certificate - cannot proceed without proof',
                    'hold_reference' => $holdReference,
                    'raw_response' => $result['raw_response'] ?? null
                ];
            }
            
            return [
                'hold_placed' => true,
                'success' => true,
                'hold_reference' => $holdReference,
                'hold_id' => $data['hold_id'] ?? null,
                'status' => $data['status'] ?? 'ACTIVE',
                'original_payload' => $data['original_payload'] ?? $result['original_payload'] ?? $holdPayload,
                'signature' => $signature,
                'certificate' => $certificate,
                'timestamp' => $data['timestamp'] ?? $result['timestamp'] ?? time(),
                'message' => $data['message'] ?? 'Hold placed successfully',
                'raw_response' => $result['raw_response'] ?? null,
                'status_code' => $result['status_code'] ?? 0
            ];
            
        } catch (\Exception $e) {
            return [
                'hold_placed' => false,
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }


    
    public function debit(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $debitPayload = $payload;
            
            if (!isset($debitPayload['access_token']) && $this->accessToken) {
                $debitPayload['access_token'] = $this->accessToken;
            }
            
            if (!isset($debitPayload['reference'])) {
                $debitPayload['reference'] = $context['swap_reference'] ?? uniqid('debit_');
            }
            if (!isset($debitPayload['action'])) {
                $debitPayload['action'] = 'DEBIT_FUNDS';
            }
            if (!isset($debitPayload['from_institution'])) {
                $debitPayload['from_institution'] = $this->institution;
            }
            if (!isset($debitPayload['source_institution'])) {
                $debitPayload['source_institution'] = $this->institution;
            }
            
            if (!isset($debitPayload['hold_reference']) && isset($context['hold_reference'])) {
                $debitPayload['hold_reference'] = $context['hold_reference'];
            }
            
            $result = $this->bankClient->debitFunds($debitPayload);
            
            if (!$result['success'] || !($result['debited'] ?? false)) {
                return [
                    'debited' => false,
                    'success' => false,
                    'message' => $result['message'] ?? $result['curl_error'] ?? 'Debit failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ')',
                    'status_code' => $result['status_code'] ?? 0,
                    'raw_response' => $result['raw_response'] ?? null
                ];
            }
            
            $data = $result['data'] ?? [];

            // ============================================================
            // FIX: debit() was the one core money-movement operation with
            // no proof-of-completion check — placeHold() already refuses
            // to report success without a hold_reference, credit() already
            // refuses without a transaction_reference, but debit() would
            // report debited=true off nothing but the bank's HTTP-level
            // success flag. That flag can itself default to true on an
            // ambiguous-but-200 response (see GenericBankClient::send()'s
            // fallback when a response body has none of success/status/
            // hold_placed) — meaning a debit could be reported all the way
            // up through SwapService as completed with zero evidence it
            // actually happened. Debit is the operation that actually
            // removes money; it's the one that most needs this guard, not
            // the one that can safely skip it. Same required-field pattern
            // as placeHold()/credit() above.
            // ============================================================
            $transactionReference = $data['transaction_reference'] ?? $data['reference'] ?? null;

            if (empty($transactionReference)) {
                if ($this->logger) {
                    $this->logger->error("debit: bank returned success but no transaction_reference", [
                        'institution' => $this->institution,
                        'response_data' => $data
                    ]);
                }
                return [
                    'debited' => false,
                    'success' => false,
                    'message' => 'Bank accepted the debit request but returned no transaction_reference - cannot confirm funds were actually debited',
                    'raw_response' => $result['raw_response'] ?? null,
                    'status_code' => $result['status_code'] ?? 0
                ];
            }
            
            return [
                'debited' => true,
                'success' => true,
                'transaction_reference' => $transactionReference,
                'status' => $data['status'] ?? 'COMPLETED',
                'message' => $data['message'] ?? 'Debit successful',
                'raw_response' => $result['raw_response'] ?? null,
                'status_code' => $result['status_code'] ?? 0
            ];
            
        } catch (\Exception $e) {
            return [
                'debited' => false,
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function credit(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $creditPayload = $payload;
            
            if (!isset($creditPayload['access_token']) && $this->accessToken) {
                $creditPayload['access_token'] = $this->accessToken;
            }
            
            if (!isset($creditPayload['reference'])) {
                $creditPayload['reference'] = $context['swap_reference'] ?? uniqid('credit_');
            }
            if (!isset($creditPayload['action'])) {
                $creditPayload['action'] = 'PROCESS_DEPOSIT_WITH_PROOF';
            }
            if (!isset($creditPayload['destination_asset_type']) && isset($context['destination_asset_type'])) {
                $creditPayload['destination_asset_type'] = $context['destination_asset_type'];
            }
            if (!isset($creditPayload['asset_type']) && isset($creditPayload['destination_asset_type'])) {
                $creditPayload['asset_type'] = $creditPayload['destination_asset_type'];
            }
            if (!isset($creditPayload['from_institution']) && isset($context['source_institution'])) {
                $creditPayload['from_institution'] = $context['source_institution'];
            }
            if (!isset($creditPayload['source_institution']) && isset($context['source_institution'])) {
                $creditPayload['source_institution'] = $context['source_institution'];
            }
            if (!isset($creditPayload['to_institution'])) {
                $creditPayload['to_institution'] = $this->institution;
            }
            if (!isset($creditPayload['destination_institution'])) {
                $creditPayload['destination_institution'] = $this->institution;
            }
            if (!isset($creditPayload['hold_reference']) && isset($context['hold_reference'])) {
                $creditPayload['hold_reference'] = $context['hold_reference'];
            }
            if (!isset($creditPayload['_skip_hold'])) {
                $creditPayload['_skip_hold'] = true;
            }
            
            $result = $this->bankClient->processDepositWithProof($creditPayload);
            
            if (!$result['success'] || !($result['credited'] ?? false)) {
                return [
                    'credited' => false,
                    'success' => false,
                    'message' => $result['message'] ?? $result['curl_error'] ?? 'Credit failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ')',
                    'status_code' => $result['status_code'] ?? 0,
                    'raw_response' => $result['raw_response'] ?? null
                ];
            }
            
            $data = $result['data'] ?? [];
            $transactionReference = $data['transaction_reference'] ?? $data['reference'] ?? null;
            
            if (empty($transactionReference)) {
                if ($this->logger) {
                    $this->logger->error("credit: bank returned success but no transaction_reference", [
                        'institution' => $this->institution,
                        'response_data' => $data
                    ]);
                }
                return [
                    'credited' => false,
                    'success' => false,
                    'message' => 'Bank accepted the deposit request but returned no transaction_reference - cannot confirm funds were credited',
                    'raw_response' => $result['raw_response'] ?? null
                ];
            }
            
            return [
                'credited' => true,
                'success' => true,
                'transaction_reference' => $transactionReference,
                'status' => $data['status'] ?? 'COMPLETED',
                'new_balance' => $data['new_balance'] ?? null,
                'message' => $data['message'] ?? 'Credit successful',
                'raw_response' => $result['raw_response'] ?? null,
                'status_code' => $result['status_code'] ?? 0
            ];
            
        } catch (\Exception $e) {
            return [
                'credited' => false,
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function releaseHold(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $holdReference = $payload['hold_reference'] ?? $context['hold_reference'] ?? null;
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
                    'success' => false,
                    'message' => 'hold_reference is required for release',
                    'hold_reference' => null
                ];
            }
            
            $releasePayload = $payload;
            
            if (!isset($releasePayload['access_token']) && $this->accessToken) {
                $releasePayload['access_token'] = $this->accessToken;
            }
            
            if (!isset($releasePayload['reference'])) {
                $releasePayload['reference'] = $context['swap_reference'] ?? uniqid('release_');
            }
            if (!isset($releasePayload['action'])) {
                $releasePayload['action'] = 'RELEASE_HOLD';
            }
            if (!isset($releasePayload['from_institution'])) {
                $releasePayload['from_institution'] = $this->institution;
            }
            if (!isset($releasePayload['source_institution'])) {
                $releasePayload['source_institution'] = $this->institution;
            }
            
            if ($this->logger) {
                $this->logger->info("GenericInstitutionAdapter::releaseHold", [
                    'hold_reference' => $holdReference,
                    'institution' => $this->institution,
                    'reason' => $reason
                ]);
            }
            
            if (method_exists($this->bankClient, 'releaseHold')) {
                $result = $this->bankClient->releaseHold($releasePayload);
                
                if (!$result['success'] || !($result['released'] ?? false)) {
                    return [
                        'released' => false,
                        'success' => false,
                        'message' => $result['message'] ?? $result['curl_error'] ?? 'Release failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ')',
                        'hold_reference' => $holdReference,
                        'status_code' => $result['status_code'] ?? 0,
                        'raw_response' => $result['raw_response'] ?? null
                    ];
                }
                
                $data = $result['data'] ?? [];
                
                return [
                    'released' => true,
                    'success' => true,
                    'status' => $data['status'] ?? 'RELEASED',
                    'hold_reference' => $holdReference,
                    'message' => $data['message'] ?? 'Hold released successfully',
                    'released_at' => $data['released_at'] ?? date('Y-m-d H:i:s'),
                    'raw_response' => $result['raw_response'] ?? null,
                    'status_code' => $result['status_code'] ?? 0
                ];
            }
            
            if ($this->logger) {
                $this->logger->warning("Bank client does not support releaseHold, marking as released locally", [
                    'institution' => $this->institution,
                    'hold_reference' => $holdReference
                ]);
            }
            
            return [
                'released' => true,
                'success' => true,
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
                'success' => false,
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
            
            $tokenPayload = $payload;
            
            if (!isset($tokenPayload['access_token']) && $this->accessToken) {
                $tokenPayload['access_token'] = $this->accessToken;
            }
            
            if (!isset($tokenPayload['reference'])) {
                $tokenPayload['reference'] = $context['swap_reference'] ?? uniqid('cashout_');
            }
            if (!isset($tokenPayload['action'])) {
                $tokenPayload['action'] = 'GENERATE_TOKEN';
            }
            if (!isset($tokenPayload['from_institution']) && isset($context['source_institution'])) {
                $tokenPayload['from_institution'] = $context['source_institution'];
            }
            if (!isset($tokenPayload['source_institution']) && isset($context['source_institution'])) {
                $tokenPayload['source_institution'] = $context['source_institution'];
            }
            if (!isset($tokenPayload['to_institution'])) {
                $tokenPayload['to_institution'] = $this->institution;
            }
            if (!isset($tokenPayload['destination_institution'])) {
                $tokenPayload['destination_institution'] = $this->institution;
            }
            
            if (!isset($tokenPayload['source_verification']) && isset($this->context['verification'])) {
                $tokenPayload['source_verification'] = $this->context['verification'];
            }
            if (!isset($tokenPayload['source_hold']) && isset($this->context['hold'])) {
                $tokenPayload['source_hold'] = $this->context['hold'];
            }
            
            $result = $this->bankClient->generateTokenWithProof($tokenPayload);
            
            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? $result['curl_error'] ?? 'Token generation failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ')',
                    'status_code' => $result['status_code'] ?? 0,
                    'raw_response' => $result['raw_response'] ?? null
                ];
            }
            
            $data = $result['data'] ?? [];

            return [
                'success' => true,
                'cashout_code' => $result['cashout_code'] ?? $data['cashout_code'] ?? $data['code'] ?? $data['sat_number'] ?? null,
                'atm_pin' => $result['atm_pin'] ?? $data['atm_pin'] ?? $data['pin'] ?? null,
                'voucher_number' => $result['voucher_number'] ?? $data['voucher_number'] ?? $data['sat_number'] ?? null,
                'swap_code' => $result['swap_code'] ?? $data['swap_code'] ?? $data['voucher_number'] ?? $data['sat_number'] ?? null,
                'expires_at' => $result['expires_at'] ?? $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours')),
                'transaction_reference' => $result['transaction_reference'] ?? $data['transaction_reference'] ?? $data['sat_number'] ?? null,
                'message' => $data['message'] ?? 'Token generated',
                'raw_response' => $result['raw_response'] ?? null,
                'status_code' => $result['status_code'] ?? 0
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function verifyAccount(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $verifyPayload = $payload;
            
            if (!isset($verifyPayload['access_token']) && $this->accessToken) {
                $verifyPayload['access_token'] = $this->accessToken;
            }
            
            if (!isset($verifyPayload['reference'])) {
                $verifyPayload['reference'] = $context['swap_reference'] ?? uniqid('verify_');
            }
            if (!isset($verifyPayload['action'])) {
                $verifyPayload['action'] = 'VERIFY_ACCOUNT';
            }
            if (!isset($verifyPayload['requester'])) {
                $verifyPayload['requester'] = 'VOUCHMORPH';
            }
            if (!isset($verifyPayload['timestamp'])) {
                $verifyPayload['timestamp'] = time();
            }
            if (!isset($verifyPayload['from_institution']) && isset($context['source_institution'])) {
                $verifyPayload['from_institution'] = $context['source_institution'];
            }
            if (!isset($verifyPayload['source_institution']) && isset($context['source_institution'])) {
                $verifyPayload['source_institution'] = $context['source_institution'];
            }
            if (!isset($verifyPayload['to_institution'])) {
                $verifyPayload['to_institution'] = $this->institution;
            }
            if (!isset($verifyPayload['destination_institution'])) {
                $verifyPayload['destination_institution'] = $this->institution;
            }
            if (!isset($verifyPayload['destination_asset_type']) && isset($context['destination_asset_type'])) {
                $verifyPayload['destination_asset_type'] = $context['destination_asset_type'];
            }
            
            $result = $this->bankClient->verifyAccount($verifyPayload);
            
            if (!$result['success'] || !($result['verified'] ?? false)) {
                return [
                    'verified' => false,
                    'success' => false,
                    'message' => $result['message'] ?? $result['curl_error'] ?? 'Account verification failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ')',
                    'status_code' => $result['status_code'] ?? 0,
                    'raw_response' => $result['raw_response'] ?? null
                ];
            }
            
            $data = $result['data'] ?? [];
            
            return [
                'verified' => $data['verified'] ?? $result['success'] ?? true,
                'success' => true,
                'account_name' => $data['account_name'] ?? null,
                'account_type' => $data['account_type'] ?? null,
                'status' => $data['status'] ?? 'ACTIVE',
                'currency' => $data['currency'] ?? null,
                'message' => $data['message'] ?? 'Account verified',
                'account_identifier' => $payload['account_identifier'] ?? null,
                'identifier_type' => $payload['identifier_type'] ?? 'account',
                'data' => $data,
                'raw_response' => $result['raw_response'] ?? null,
                'status_code' => $result['status_code'] ?? 0
            ];
            
        } catch (\Exception $e) {
            return [
                'verified' => false,
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // ============================================================
    // OTHER OPERATIONS (Non-standardized - kept from original)
    // ============================================================
    
    public function verifyCashoutToken(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $verifyPayload = $payload;
            
            if (!isset($verifyPayload['reference'])) {
                $verifyPayload['reference'] = uniqid('verify_token_');
            }
            
            $result = $this->bankClient->verifyToken($verifyPayload);
            
            if (!$result['success']) {
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
            $confirmPayload = $payload;
            
            if (!isset($confirmPayload['completed_at'])) {
                $confirmPayload['completed_at'] = date('Y-m-d H:i:s');
            }
            
            $result = $this->bankClient->confirmCashout($confirmPayload);
            
            if (!$result['success']) {
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
    
    public function transferWithProof(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);
        
        try {
            $this->ensureConsent();
            
            $transferPayload = $payload;
            
            if (!isset($transferPayload['access_token']) && $this->accessToken) {
                $transferPayload['access_token'] = $this->accessToken;
            }
            
            if (!isset($transferPayload['reference'])) {
                $transferPayload['reference'] = uniqid('transfer_');
            }
            
            $result = $this->bankClient->transferWithProof($transferPayload);
            
            if (!$result['success']) {
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

        $balancePayload = $payload;

        if (!isset($balancePayload['access_token']) && $this->accessToken) {
            $balancePayload['access_token'] = $this->accessToken;
        }
        if (!isset($balancePayload['reference'])) {
            $balancePayload['reference'] = $context['swap_reference'] ?? uniqid('balance_');
        }
        if (!isset($balancePayload['action'])) {
            $balancePayload['action'] = 'GET_BALANCE';
        }
        if (!isset($balancePayload['from_institution'])) {
            $balancePayload['from_institution'] = $this->institution;
        }
        if (!isset($balancePayload['source_institution'])) {
            $balancePayload['source_institution'] = $this->institution;
        }

        $result = $this->bankClient->getBalance($balancePayload);   // was: getAccountBalance($token, $accountId)

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => $result['message'] ?? $result['curl_error'] ?? 'Balance check failed (HTTP ' . ($result['status_code'] ?? 'unknown') . ')',
                'balance' => 0,
                'currency' => $payload['currency'] ?? 'BWP',
                'status_code' => $result['status_code'] ?? 0,
                'raw_response' => $result['raw_response'] ?? null,
            ];
        }

        $data = $result['data'] ?? [];

        return [
            'success' => true,
            'balance' => (float)($data['balance'] ?? $data['available_balance'] ?? 0),
            'available_balance' => (float)($data['available_balance'] ?? $data['balance'] ?? 0),
            'currency' => $data['currency'] ?? $payload['currency'] ?? 'BWP',
            'account_id' => $payload['account_id'] ?? $payload['source_identifier'] ?? null,
            'account_name' => $data['account_name'] ?? $data['holder_name'] ?? null,
            'last_updated' => date('Y-m-d H:i:s'),
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
    // ============================================================
    // SETTLEMENT CONFIRMATION
    // ============================================================

    /**
     * Asks this institution whether a specific settlement (identified by
     * whatever reference VouchMorph originally sent them) has actually
     * landed on their side. Thin wrapper around
     * GenericBankClient::checkSettlementStatus() -- exists so callers that
     * only have an InstitutionAdapterInterface (not a raw bank client) can
     * still poll settlement status, same pattern as every other method here.
     */
    public function checkSettlementStatus(array $payload, array $context): array
    {
        $this->context = array_merge($context, $payload);

        try {
            $this->ensureConsent();

            $checkPayload = $payload;
            if (!isset($checkPayload['access_token']) && $this->accessToken) {
                $checkPayload['access_token'] = $this->accessToken;
            }
            if (!isset($checkPayload['reference'])) {
                $checkPayload['reference'] = $context['swap_reference'] ?? uniqid('settlecheck_');
            }

            if (!method_exists($this->bankClient, 'checkSettlementStatus')) {
                return [
                    'success' => false,
                    'settled' => false,
                    'message' => "{$this->institution}'s bank client does not implement checkSettlementStatus()",
                ];
            }

            $result = $this->bankClient->checkSettlementStatus($checkPayload);

            return [
                'success' => $result['success'] ?? false,
                'settled' => $result['settled'] ?? false,
                'settlement_reference' => $result['settlement_reference'] ?? null,
                'message' => $result['message'] ?? ($result['settled'] ?? false ? 'Settled' : 'Not yet settled'),
                'raw_response' => $result['raw_response'] ?? null,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'settled' => false,
                'message' => $e->getMessage(),
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
