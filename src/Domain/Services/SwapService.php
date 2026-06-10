<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\FeeService;
use Domain\Services\ForexService;
use Domain\Services\CardService;
use Domain\Services\ContributionCalculator;
use Domain\Services\MultiSourceFeeCalculator;
use Domain\Services\MultiSourceSwapExecutor;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\SMS\SmsNotificationService;
use Infrastructure\Mojaloop\IdempotencyService;
use Infrastructure\Crypto\SignatureVerifier;
use Infrastructure\Crypto\MessageSigner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SIGNED ATOMIC SWAP ORCHESTRATOR
 * 
 * Bank-grade trust model:
 * - VouchMorph does NOT create trust
 * - Trust is carried in cryptographic signatures
 * - Each bank signs its own assertions
 * - Destination verifies source signature directly
 */
class SwapService
{
    private PDO $swapDB;
    private array $config = [];
    private array $participants = [];
    private array $endpoints = [];
    private string $countryCode;
    private array $feesConfig = [];
    
    // Service dependencies
    private HybridSettlementStrategy $settlement;
    private FeeService $feeService;
    private ForexService $forexService;
    private ?CardService $cardService = null;
    private ?SmsNotificationService $smsService = null;
    private ?ContributionCalculator $contributionCalculator = null;
    private ?MultiSourceFeeCalculator $multiSourceFeeCalculator = null;
    private ?MultiSourceSwapExecutor $multiSourceExecutor = null;
    private $logger = null;
    
    // Crypto services
    private MessageSigner $messageSigner;
    private SignatureVerifier $signatureVerifier;
    
    // Atomic state
    private bool $inAtomicSwap = false;
    private ?string $currentSwapRef = null;
    private ?int $currentHoldId = null;
    private ?string $currentHoldReference = null;
    private array $executedSteps = [];
    private array $stepResults = [];
    
    // Store signed payloads for forwarding
    private array $signedPayloads = [];

    public function __construct(
        PDO $swapDB, 
        array $config, 
        string $country,
        ?LoggerInterface $logger = null
    ) {
        $this->swapDB = $swapDB;
        $this->config = $config;
        $this->countryCode = strtoupper($country);
        $this->logger = $logger ?? new class { public function __call($name, $args) {} };
        
        // Initialize crypto
        $this->messageSigner = new MessageSigner();
        $this->signatureVerifier = new SignatureVerifier();
        
        // Load configuration
        $this->loadConfiguration($country);
        
        // Initialize services
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        $this->feeService = new FeeService($this->feesConfig, $this->config['currency'] ?? 'BWP');
        $this->forexService = new ForexService($this->swapDB, $this->config, $this->participants, $this->feeService);
        
        // Initialize card service if configured
        $vouchmorphConfig = $this->participants['vouchmorph'] ?? [];
        if (!empty($vouchmorphConfig)) {
            $this->cardService = new CardService($this->swapDB, $this->countryCode, $vouchmorphConfig);
        }
        
        // Initialize multi-source components
        $this->contributionCalculator = new ContributionCalculator();
        $this->multiSourceFeeCalculator = new MultiSourceFeeCalculator($this->config, $this->countryCode);
        $this->multiSourceExecutor = new MultiSourceSwapExecutor(
            $this->swapDB,
            $this,
            $this->settlement,
            $this->config,
            $this->countryCode
        );
        
        $this->logger->info("Signed SwapService initialized", ['country' => $country]);
    }

    /**
     * Execute swap with cryptographic trust
     */
    public function executeAtomicSwap(array $payload): array
    {
        $ref = $payload['reference'] ?? $this->generateReference();
        $idempotencyKey = $payload['idempotency_key'] ?? $payload['idempotencyKey'] ?? null;
        
        // Idempotency check FIRST (no transaction yet)
        if ($idempotencyKey) {
            $cached = $this->checkIdempotency($idempotencyKey);
            if ($cached) {
                $this->logger->info("Idempotency cache hit", ['key' => $idempotencyKey]);
                return $cached;
            }
        }
        
        // Determine swap type
        $isMultiSource = $this->isMultiSourceContribution($payload);
        $swapType = $payload['swap_type'] ?? ($isMultiSource ? 'MULTI_SOURCE' : 'STANDARD');
        
        // BEGIN ATOMIC BOUNDARY
        $this->beginAtomicSwap($ref);
        
        try {
            $result = match($swapType) {
                'MULTI_SOURCE' => $this->executeMultiSourceSwap($payload),
                'CASHOUT' => $this->executeSignedCashout($payload),
                'DEPOSIT' => $this->executeSignedDeposit($payload),
                'CARD_ISSUE' => $this->executeCardIssuance($payload),
                default => $this->executeSignedStandardSwap($payload),
            };
            
            $commitResult = $this->commitAtomicSwap();
            $result = array_merge($result, ['atomic_commit' => $commitResult]);
            
            if ($idempotencyKey) {
                $this->storeIdempotencyResult($idempotencyKey, $result);
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->logger->error("Atomic swap failed", [
                'reference' => $ref,
                'step' => $this->getLastStep(),
                'error' => $e->getMessage()
            ]);
            
            $rollbackResult = $this->rollbackAtomicSwap($e->getMessage());
            
            if ($idempotencyKey) {
                $this->storeIdempotencyResult($idempotencyKey, [
                    'status' => 'failed',
                    'reference' => $ref,
                    'error' => $e->getMessage()
                ]);
            }
            
            throw new RuntimeException("Swap failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute signed standard swap with cryptographic proof
     */
    private function executeSignedStandardSwap(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['source_institution'] ?? $payload['from_institution'];
        $destInstitution = $payload['destination_institution'] ?? $payload['to_institution'];
        
        // STEP 1: Verify source asset with signature
        $verificationResult = $this->executeStep('VERIFY_SOURCE_SIGNED', function() use ($payload, $sourceInstitution) {
            return $this->verifySourceAssetSigned($payload, $sourceInstitution);
        });
        
        if (!($verificationResult['verified'] ?? false)) {
            throw new RuntimeException("Source verification failed: " . ($verificationResult['message'] ?? 'Unknown error'));
        }
        
        // Store the signed verification for forwarding
        $this->signedPayloads['verification'] = [
            'payload' => $verificationResult['original_payload'],
            'signature' => $verificationResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $verificationResult['timestamp']
        ];
        
        // STEP 2: Place hold with signature
        $holdResult = $this->executeStep('PLACE_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
            return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        });
        
        if (!($holdResult['hold_placed'] ?? false) && !($holdResult['success'] ?? false)) {
            throw new RuntimeException("Failed to place hold: " . ($holdResult['message'] ?? 'Unknown error'));
        }
        
        // Store the signed hold for forwarding
        $this->signedPayloads['hold'] = [
            'payload' => $holdResult['original_payload'],
            'signature' => $holdResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $holdResult['timestamp']
        ];
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? $holdResult['data']['hold_reference'] ?? null;
        
        // STEP 3: Calculate fees (local calculation - no signature needed)
        $feeBreakdown = $this->executeStep('CALCULATE_FEES', function() use ($payload, $amount) {
            $transactionType = $payload['transaction_type'] ?? 'SWAP';
            return $this->feeService->calculateFees($transactionType, $amount, $payload);
        });
        
        // STEP 4: Process destination with VERIFIABLE proof from source
        $destinationResult = $this->executeStep('PROCESS_DESTINATION_SIGNED', function() use ($payload, $destInstitution, $amount, $feeBreakdown) {
            // Forward the signed proof from source to destination
            return $this->processDestinationWithProof(
                $payload, 
                $destInstitution, 
                $amount, 
                $feeBreakdown,
                $this->signedPayloads['verification'],
                $this->signedPayloads['hold']
            );
        });
        
        // STEP 5: Debit source
        $debitResult = $this->executeStep('DEBIT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->debitSource($payload, $sourceInstitution);
        });
        
        if (!($debitResult['debited'] ?? false) && !($debitResult['success'] ?? false)) {
            throw new RuntimeException("Failed to debit source: " . ($debitResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 6: Update local hold status
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        // STEP 7: Record settlement with signature chain
        $settlementResult = $this->executeStep('RECORD_SETTLEMENT_SIGNED', function() use ($payload, $destinationResult, $feeBreakdown) {
            return $this->recordSettlementWithProof($payload, $destinationResult, $feeBreakdown);
        });
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'hold_id' => $this->currentHoldId,
            'hold_reference' => $this->currentHoldReference,
            'amount' => $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'signature_chain' => $this->signedPayloads,
            'settlement' => $settlementResult
        ];
    }

    /**
     * Execute signed cashout with cryptographic proof
     */
    private function executeSignedCashout(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['source_institution'] ?? $payload['from_institution'];
        $beneficiaryPhone = $payload['beneficiary_phone'] ?? $payload['client_phone'] ?? null;
        
        // STEP 1: Verify source with signature
        $verificationResult = $this->executeStep('VERIFY_CASHOUT_SOURCE_SIGNED', function() use ($payload, $sourceInstitution) {
            return $this->verifySourceAssetSigned($payload, $sourceInstitution);
        });
        
        $this->signedPayloads['verification'] = [
            'payload' => $verificationResult['original_payload'],
            'signature' => $verificationResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $verificationResult['timestamp']
        ];
        
        // STEP 2: Place hold with signature
        $holdResult = $this->executeStep('PLACE_CASHOUT_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
            return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        });
        
        $this->signedPayloads['hold'] = [
            'payload' => $holdResult['original_payload'],
            'signature' => $holdResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $holdResult['timestamp']
        ];
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? $holdResult['data']['hold_reference'] ?? null;
        
        // STEP 3: Calculate fees
        $feeBreakdown = $this->executeStep('CALCULATE_CASHOUT_FEES', function() use ($payload, $amount) {
            return $this->feeService->calculateFees('CASHOUT', $amount, $payload);
        });
        
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        
        // STEP 4: Generate ATM token with proof
        $tokenResult = $this->executeStep('GENERATE_TOKEN_WITH_PROOF', function() use ($payload, $sourceInstitution, $netAmount) {
            return $this->generateAtmTokenWithProof($payload, $sourceInstitution, $netAmount);
        });
        
        // STEP 5: Send SMS notification
        if ($beneficiaryPhone && $this->smsService && isset($tokenResult['atm_pin'])) {
            $this->executeStep('SEND_SMS', function() use ($beneficiaryPhone, $tokenResult, $netAmount) {
                return $this->smsService->sendCashoutCode(
                    $beneficiaryPhone,
                    $tokenResult['atm_pin'],
                    $netAmount,
                    $tokenResult['voucher_number'] ?? null
                );
            });
        }
        
        // STEP 6: Debit source
        $debitResult = $this->executeStep('DEBIT_CASHOUT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->debitSource($payload, $sourceInstitution);
        });
        
        // STEP 7: Update hold status
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'atm_code' => $tokenResult['atm_pin'] ?? null,
            'voucher_number' => $tokenResult['voucher_number'] ?? null,
            'amount' => $netAmount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'signature_chain' => $this->signedPayloads
        ];
    }

    /**
     * Execute signed deposit
     */
    private function executeSignedDeposit(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['source_institution'] ?? $payload['from_institution'];
        
        // STEP 1: Verify source with signature
        $verificationResult = $this->executeStep('VERIFY_DEPOSIT_SOURCE_SIGNED', function() use ($payload, $sourceInstitution) {
            return $this->verifySourceAssetSigned($payload, $sourceInstitution);
        });
        
        $this->signedPayloads['verification'] = [
            'payload' => $verificationResult['original_payload'],
            'signature' => $verificationResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $verificationResult['timestamp']
        ];
        
        // STEP 2: Process deposit with proof
        $depositResult = $this->executeStep('PROCESS_DEPOSIT_WITH_PROOF', function() use ($payload, $sourceInstitution, $amount) {
            return $this->processDepositWithProof($payload, $sourceInstitution, $amount);
        });
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'deposit_reference' => $depositResult['transaction_reference'] ?? null,
            'signature_chain' => $this->signedPayloads
        ];
    }

    // ============================================================
    // SIGNED BANK CLIENT INTEGRATION METHODS
    // ============================================================

    /**
     * Verify source asset and capture signature
     */
    private function verifySourceAssetSigned(array $payload, string $institution): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $timestamp = time();
        
        $verifyPayload = [
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'institution' => $institution,
            'timestamp' => $timestamp
        ];
        
        // Add asset-specific fields
        switch ($assetType) {
            case 'VOUCHER':
            case 'CASHOUT-VOUCHER':
                $verifyPayload['voucher_number'] = $payload['voucher_number'] ?? null;
                $verifyPayload['voucher_pin'] = $payload['voucher_pin'] ?? null;
                $verifyPayload['claimant_phone'] = $payload['claimant_phone'] ?? null;
                break;
            case 'ACCOUNT':
                $verifyPayload['account_number'] = $payload['account_number'] ?? null;
                break;
            case 'MNO-WALLET':
            case 'BANK-WALLET':
                $verifyPayload['wallet_phone'] = $payload['wallet_phone'] ?? null;
                break;
        }
        
        // Request signed response from bank
        $result = $bankClient->verifyAssetSigned($verifyPayload);
        
        if (!$result['success']) {
            $this->logger->error("Signed verification failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? 'Unknown'
            ]);
            return ['verified' => false, 'message' => $result['curl_error'] ?? 'Verification failed'];
        }
        
        $data = $result['data'] ?? [];
        
        // Verify the signature if bank provided one
        if (isset($data['signature']) && isset($data['payload'])) {
            $publicKey = $this->getInstitutionPublicKey($institution);
            $isValid = $this->signatureVerifier->verify(
                $data['payload'],
                $data['signature'],
                $publicKey
            );
            
            if (!$isValid) {
                $this->logger->error("Invalid signature from source bank", ['institution' => $institution]);
                return ['verified' => false, 'message' => 'Invalid signature from source bank'];
            }
        }
        
        return [
            'verified' => $data['verified'] ?? false,
            'message' => $data['message'] ?? null,
            'balance' => $data['available_balance'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'original_payload' => $data['payload'] ?? null,
            'signature' => $data['signature'] ?? null,
            'timestamp' => $data['timestamp'] ?? $timestamp,
            'raw_response' => $result
        ];
    }

    /**
     * Place hold and capture signature
     */
    private function placeHoldSigned(array $payload, string $institution, array $verificationResult): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $timestamp = time();
        
        $holdPayload = [
            'action' => 'PLACE_HOLD',
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'hold_reason' => $payload['hold_reason'] ?? 'PENDING_TRANSACTION',
            'destination_institution' => $payload['destination_institution'] ?? null,
            'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'timestamp' => $timestamp
        ];
        
        if (isset($verificationResult['asset_id'])) {
            $holdPayload['asset_id'] = $verificationResult['asset_id'];
        }
        
        // Add asset-specific fields
        switch ($assetType) {
            case 'VOUCHER':
                $holdPayload['voucher_number'] = $payload['voucher_number'] ?? null;
                $holdPayload['claimant_phone'] = $payload['claimant_phone'] ?? null;
                break;
            case 'ACCOUNT':
                $holdPayload['account_number'] = $payload['account_number'] ?? null;
                break;
            case 'MNO-WALLET':
                $holdPayload['wallet_phone'] = $payload['wallet_phone'] ?? null;
                break;
        }
        
        // Request signed hold response
        $result = $bankClient->placeHoldSigned($holdPayload);
        
        if (!$result['success']) {
            $this->logger->error("Signed hold placement failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? 'Unknown'
            ]);
            return ['hold_placed' => false, 'message' => $result['curl_error'] ?? 'Hold failed'];
        }
        
        $data = $result['data'] ?? [];
        
        // Verify the signature
        if (isset($data['signature']) && isset($data['payload'])) {
            $publicKey = $this->getInstitutionPublicKey($institution);
            $isValid = $this->signatureVerifier->verify(
                $data['payload'],
                $data['signature'],
                $publicKey
            );
            
            if (!$isValid) {
                $this->logger->error("Invalid signature on hold response", ['institution' => $institution]);
                return ['hold_placed' => false, 'message' => 'Invalid signature on hold response'];
            }
        }
        
        // Create local hold record
        $holdId = $this->createLocalHold($payload, $institution, $data['hold_reference'] ?? null);
        $this->currentHoldId = $holdId;
        
        return [
            'hold_placed' => true,
            'hold_reference' => $data['hold_reference'] ?? null,
            'local_hold_id' => $holdId,
            'message' => $data['message'] ?? 'Hold placed successfully',
            'original_payload' => $data['payload'] ?? null,
            'signature' => $data['signature'] ?? null,
            'timestamp' => $data['timestamp'] ?? $timestamp,
            'raw_response' => $result
        ];
    }

    /**
     * Process destination with cryptographic proof from source
     */
    private function processDestinationWithProof(
        array $payload, 
        string $institution, 
        float $amount, 
        array $feeBreakdown,
        array $verificationProof,
        array $holdProof
    ): array {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        
        // Forward the signed proofs from source bank
        $transferPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_type' => $payload['destination_type'] ?? 'ACCOUNT',
            'destination_details' => $payload['destination_details'] ?? [],
            'beneficiary_phone' => $payload['beneficiary_phone'] ?? null,
            'beneficiary_account' => $payload['beneficiary_account'] ?? null,
            'action' => 'PROCESS_TRANSFER_WITH_PROOF',
            // Forward the cryptographic proofs
            'source_verification' => [
                'payload' => $verificationProof['payload'],
                'signature' => $verificationProof['signature'],
                'source' => $verificationProof['source'],
                'timestamp' => $verificationProof['timestamp']
            ],
            'source_hold' => [
                'payload' => $holdProof['payload'],
                'signature' => $holdProof['signature'],
                'source' => $holdProof['source'],
                'timestamp' => $holdProof['timestamp']
            ]
        ];
        
        $result = $bankClient->transferWithProof($transferPayload);
        
        if (!$result['success']) {
            $this->logger->error("Destination processing failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? 'Unknown'
            ]);
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Processing failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'message' => $data['message'] ?? 'Destination processed successfully',
            'destination_signature' => $data['signature'] ?? null
        ];
    }

    /**
     * Generate ATM token with proof
     */
    private function generateAtmTokenWithProof(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $tokenPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'beneficiary_phone' => $payload['beneficiary_phone'] ?? null,
            'hold_reference' => $this->currentHoldReference,
            'action' => 'GENERATE_ATM_TOKEN_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null
        ];
        
        $result = $bankClient->generateTokenWithProof($tokenPayload);
        
        if (!$result['success']) {
            $this->logger->error("Token generation failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? 'Unknown'
            ]);
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Token generation failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'atm_pin' => $data['atm_pin'] ?? null,
            'voucher_number' => $data['voucher_number'] ?? null,
            'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'))
        ];
    }

    /**
     * Process deposit with proof
     */
    private function processDepositWithProof(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $depositPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'source_details' => $payload['source_details'] ?? [],
            'client_phone' => $payload['client_phone'] ?? null,
            'client_account' => $payload['client_account'] ?? null,
            'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null
        ];
        
        $result = $bankClient->processDepositWithProof($depositPayload);
        
        if (!$result['success']) {
            $this->logger->error("Deposit processing failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? 'Unknown'
            ]);
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Deposit failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'message' => $data['message'] ?? 'Deposit successful'
        ];
    }

    /**
     * Record settlement with signature chain
     */
    private function recordSettlementWithProof(array $payload, array $destinationResult, array $feeBreakdown): array
    {
        // Store the entire signature chain in the settlement record
        $settlementData = [
            'swap_reference' => $this->currentSwapRef,
            'from_institution' => $payload['source_institution'] ?? null,
            'to_institution' => $payload['destination_institution'] ?? null,
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'BWP',
            'fee' => $feeBreakdown,
            'signature_chain' => $this->signedPayloads,
            'destination_proof' => $destinationResult
        ];
        
        return $this->settlement->recordSettlementWithProof($settlementData);
    }

    /**
     * Get institution's public key for signature verification
     */
    private function getInstitutionPublicKey(string $institution): string
    {
        // First check database
        $stmt = $this->swapDB->prepare("
            SELECT public_key FROM institution_keys 
            WHERE institution = :institution AND is_active = true
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':institution' => $institution]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && !empty($row['public_key'])) {
            return $row['public_key'];
        }
        
        // Fallback to environment variable
        $envKey = strtoupper($institution) . '_PUBLIC_KEY';
        $publicKey = getenv($envKey);
        
        if ($publicKey) {
            return $publicKey;
        }
        
        throw new RuntimeException("No public key found for institution: {$institution}");
    }

    /**
     * Execute deposit
     */
    private function executeDeposit(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['source_institution'] ?? $payload['from_institution'];
        
        $verificationResult = $this->executeStep('VERIFY_DEPOSIT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->verifySourceAsset($payload, $sourceInstitution);
        });
        
        $depositResult = $this->executeStep('PROCESS_DEPOSIT', function() use ($payload, $sourceInstitution, $amount) {
            return $this->processDeposit($payload, $sourceInstitution, $amount);
        });
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'deposit_reference' => $depositResult['transaction_reference'] ?? null
        ];
    }

    /**
     * Execute card issuance
     */
    private function executeCardIssuance(array $payload): array
    {
        if (!$this->cardService) {
            throw new RuntimeException("Card service not initialized");
        }
        
        $userId = $payload['user_id'] ?? null;
        
        $eligibilityResult = $this->executeStep('VERIFY_ELIGIBILITY', function() use ($userId) {
            return $this->cardService->checkEligibility($userId);
        });
        
        $cardResult = $this->executeStep('CREATE_CARD', function() use ($payload) {
            return $this->cardService->createCard($payload);
        });
        
        $cardDetails = $this->executeStep('GENERATE_CARD_DETAILS', function() use ($cardResult) {
            return $this->cardService->generateCardDetails($cardResult['card_id']);
        });
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'card_id' => $cardResult['card_id'] ?? null,
            'card_last_four' => $cardDetails['last_four'] ?? null
        ];
    }

    /**
     * Execute multi-source swap
     */
    private function executeMultiSourceSwap(array $payload): array
    {
        if (!$this->multiSourceExecutor) {
            throw new RuntimeException("Multi-source swap executor not initialized");
        }
        
        return $this->executeStep('MULTI_SOURCE_EXECUTION', function() use ($payload) {
            return $this->multiSourceExecutor->execute($payload);
        });
    }

    // ============================================================
    // UNSIGNED FALLBACK METHODS (for backward compatibility)
    // ============================================================

    private function verifySourceAsset(array $payload, string $institution): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $verifyPayload = [
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'institution' => $institution
        ];
        
        switch ($assetType) {
            case 'VOUCHER':
            case 'CASHOUT-VOUCHER':
                $verifyPayload['voucher_number'] = $payload['voucher_number'] ?? null;
                $verifyPayload['voucher_pin'] = $payload['voucher_pin'] ?? null;
                $verifyPayload['claimant_phone'] = $payload['claimant_phone'] ?? null;
                break;
            case 'ACCOUNT':
                $verifyPayload['account_number'] = $payload['account_number'] ?? null;
                break;
            case 'MNO-WALLET':
            case 'BANK-WALLET':
                $verifyPayload['wallet_phone'] = $payload['wallet_phone'] ?? null;
                break;
        }
        
        $result = $bankClient->verifyAsset($verifyPayload);
        
        if (!$result['success']) {
            return ['verified' => false, 'message' => $result['curl_error'] ?? 'Verification failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'verified' => $data['verified'] ?? false,
            'message' => $data['message'] ?? null,
            'balance' => $data['available_balance'] ?? null,
            'asset_id' => $data['asset_id'] ?? null
        ];
    }

    private function placeHold(array $payload, string $institution, array $verificationResult): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $holdPayload = [
            'action' => 'PLACE_HOLD',
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'hold_reason' => $payload['hold_reason'] ?? 'PENDING_TRANSACTION',
            'destination_institution' => $payload['destination_institution'] ?? null,
            'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour'))
        ];
        
        if (isset($verificationResult['asset_id'])) {
            $holdPayload['asset_id'] = $verificationResult['asset_id'];
        }
        
        switch ($assetType) {
            case 'VOUCHER':
                $holdPayload['voucher_number'] = $payload['voucher_number'] ?? null;
                $holdPayload['claimant_phone'] = $payload['claimant_phone'] ?? null;
                break;
            case 'ACCOUNT':
                $holdPayload['account_number'] = $payload['account_number'] ?? null;
                break;
            case 'MNO-WALLET':
                $holdPayload['wallet_phone'] = $payload['wallet_phone'] ?? null;
                break;
        }
        
        $result = $bankClient->placeHold($holdPayload);
        
        if (!$result['success']) {
            return ['hold_placed' => false, 'message' => $result['curl_error'] ?? 'Hold failed'];
        }
        
        $data = $result['data'] ?? [];
        
        $holdId = $this->createLocalHold($payload, $institution, $data['hold_reference'] ?? null);
        $this->currentHoldId = $holdId;
        
        return [
            'hold_placed' => true,
            'hold_reference' => $data['hold_reference'] ?? null,
            'local_hold_id' => $holdId,
            'message' => $data['message'] ?? 'Hold placed successfully'
        ];
    }

    private function debitSource(array $payload, string $institution): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $debitPayload = [
            'reference' => $this->currentSwapRef,
            'hold_reference' => $this->currentHoldReference,
            'amount' => $payload['amount'] ?? 0,
            'reason' => 'Swap completed successfully'
        ];
        
        $result = $bankClient->debitHold($debitPayload);
        
        if (!$result['success']) {
            return ['debited' => false, 'message' => $result['curl_error'] ?? 'Debit failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'debited' => true,
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'message' => $data['message'] ?? 'Debit successful'
        ];
    }

    private function processDestination(array $payload, string $institution, float $amount, array $feeBreakdown): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        
        $transferPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_type' => $payload['destination_type'] ?? 'ACCOUNT',
            'destination_details' => $payload['destination_details'] ?? [],
            'beneficiary_phone' => $payload['beneficiary_phone'] ?? null,
            'beneficiary_account' => $payload['beneficiary_account'] ?? null,
            'action' => 'PROCESS_TRANSFER'
        ];
        
        $result = $bankClient->transfer($transferPayload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Processing failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'message' => $data['message'] ?? 'Destination processed successfully'
        ];
    }

    private function processDeposit(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $depositPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'source_details' => $payload['source_details'] ?? [],
            'client_phone' => $payload['client_phone'] ?? null,
            'client_account' => $payload['client_account'] ?? null,
            'action' => 'PROCESS_DEPOSIT'
        ];
        
        $result = $bankClient->processDeposit($depositPayload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Deposit failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'message' => $data['message'] ?? 'Deposit successful'
        ];
    }

    private function generateAtmToken(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $tokenPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'beneficiary_phone' => $payload['beneficiary_phone'] ?? null,
            'hold_reference' => $this->currentHoldReference,
            'action' => 'GENERATE_ATM_TOKEN'
        ];
        
        $result = $bankClient->generateToken($tokenPayload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Token generation failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'atm_pin' => $data['atm_pin'] ?? null,
            'voucher_number' => $data['voucher_number'] ?? null,
            'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'))
        ];
    }

    // ============================================================
    // LOCAL DATABASE OPERATIONS
    // ============================================================

    private function createLocalHold(array $payload, string $institution, ?string $externalHoldRef): int
    {
        $sql = "
            INSERT INTO hold_transactions (
                hold_reference,
                swap_reference,
                participant_name,
                asset_type,
                amount,
                currency,
                status,
                source_details,
                destination_institution,
                external_hold_reference,
                signature_chain,
                placed_at,
                created_at,
                updated_at
            ) VALUES (
                :hold_ref,
                :swap_ref,
                :participant_name,
                :asset_type,
                :amount,
                :currency,
                'ACTIVE',
                :source_details::jsonb,
                :destination,
                :external_ref,
                :signature_chain::jsonb,
                NOW(),
                NOW(),
                NOW()
            ) RETURNING hold_id
        ";
        
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':hold_ref' => 'HOLD_' . $this->currentSwapRef,
            ':swap_ref' => $this->currentSwapRef,
            ':participant_name' => $institution,
            ':asset_type' => $payload['asset_type'] ?? 'ACCOUNT',
            ':amount' => $payload['amount'] ?? 0,
            ':currency' => $payload['currency'] ?? 'BWP',
            ':source_details' => json_encode($payload['source_details'] ?? []),
            ':destination' => $payload['destination_institution'] ?? null,
            ':external_ref' => $externalHoldRef,
            ':signature_chain' => json_encode($this->signedPayloads)
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$row['hold_id'];
    }

    private function updateHoldStatus(int $holdId, string $status): void
    {
        $sql = "
            UPDATE hold_transactions 
            SET status = :status,
                debited_at = CASE WHEN :status = 'DEBITED' THEN NOW() ELSE debited_at END,
                released_at = CASE WHEN :status = 'RELEASED' THEN NOW() ELSE released_at END,
                updated_at = NOW()
            WHERE hold_id = :hold_id
        ";
        
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':hold_id' => $holdId
        ]);
    }

    // ============================================================
    // ATOMIC BOUNDARY METHODS
    // ============================================================

    private function beginAtomicSwap(string $reference): void
    {
        if ($this->inAtomicSwap) {
            throw new RuntimeException("Already in atomic swap: {$this->currentSwapRef}");
        }
        
        $this->currentSwapRef = $reference;
        $this->inAtomicSwap = true;
        $this->executedSteps = [];
        $this->stepResults = [];
        $this->signedPayloads = [];
        
        $this->swapDB->beginTransaction();
        
        $this->auditLog('SWAP_START', ['reference' => $reference]);
        $this->logger->info("Atomic swap begun", ['reference' => $reference]);
    }

    private function commitAtomicSwap(): array
    {
        $this->swapDB->commit();
        
        $result = [
            'status' => 'committed',
            'reference' => $this->currentSwapRef,
            'hold_id' => $this->currentHoldId,
            'steps_completed' => count($this->executedSteps)
        ];
        
        $this->auditLog('SWAP_COMMIT', $result);
        $this->logger->info("Atomic swap committed", $result);
        
        $this->resetAtomicState();
        return $result;
    }

    private function rollbackAtomicSwap(string $reason): array
    {
        $rollbackSteps = [];
        
        if ($this->currentHoldReference) {
            try {
                $this->releaseExternalHold();
                $rollbackSteps[] = 'external_hold_released';
            } catch (Exception $e) {
                $this->logger->error("Failed to release external hold", [
                    'hold_ref' => $this->currentHoldReference,
                    'error' => $e->getMessage()
                ]);
                $rollbackSteps[] = 'external_hold_release_failed';
            }
        }
        
        if ($this->currentHoldId) {
            try {
                $this->updateHoldStatus($this->currentHoldId, 'RELEASED');
                $rollbackSteps[] = 'local_hold_released';
            } catch (Exception $e) {
                $this->logger->error("Failed to update local hold", ['error' => $e->getMessage()]);
                $rollbackSteps[] = 'local_hold_update_failed';
            }
        }
        
        try {
            $this->swapDB->rollBack();
            $rollbackSteps[] = 'db_rolled_back';
        } catch (Exception $e) {
            $this->logger->critical("Database rollback failed!", ['error' => $e->getMessage()]);
            $rollbackSteps[] = 'db_rollback_failed';
        }
        
        $result = [
            'status' => 'rolled_back',
            'reference' => $this->currentSwapRef,
            'reason' => $reason,
            'rollback_steps' => $rollbackSteps
        ];
        
        $this->auditLog('SWAP_ROLLBACK', $result);
        $this->logger->warning("Atomic swap rolled back", $result);
        
        $this->resetAtomicState();
        return $result;
    }

    private function releaseExternalHold(): void
    {
        if (!$this->currentHoldReference) {
            return;
        }
        
        $this->logger->info("Would release external hold", ['hold_reference' => $this->currentHoldReference]);
    }

    private function resetAtomicState(): void
    {
        $this->inAtomicSwap = false;
        $this->currentSwapRef = null;
        $this->currentHoldId = null;
        $this->currentHoldReference = null;
        $this->executedSteps = [];
        $this->stepResults = [];
        $this->signedPayloads = [];
    }

    private function executeStep(string $stepName, callable $operation)
    {
        $startTime = microtime(true);
        $this->recordStep("START_{$stepName}");
        
        try {
            $result = $operation();
            $duration = (microtime(true) - $startTime) * 1000;
            $this->recordStep("SUCCESS_{$stepName}", ['duration_ms' => $duration]);
            $this->stepResults[$stepName] = $result;
            return $result;
        } catch (Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->recordStep("FAILED_{$stepName}", [
                'duration_ms' => $duration,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function recordStep(string $step, ?array $data = null): void
    {
        $this->executedSteps[] = [
            'step' => $step,
            'timestamp' => microtime(true),
            'data' => $data
        ];
        
        $this->logger->debug("Step completed", [
            'swap_ref' => $this->currentSwapRef,
            'step' => $step,
            'step_number' => count($this->executedSteps)
        ]);
    }

    private function getLastStep(): string
    {
        if (empty($this->executedSteps)) {
            return 'none';
        }
        $last = end($this->executedSteps);
        return $last['step'];
    }

    private function auditLog(string $action, array $data): void
    {
        try {
            $sql = "
                INSERT INTO audit_logs (
                    entity_type,
                    entity_id,
                    action,
                    category,
                    severity,
                    new_value,
                    performed_by_type,
                    performed_at
                ) VALUES (
                    'swap',
                    :reference,
                    :action,
                    'transaction',
                    'info',
                    :value::jsonb,
                    'system',
                    NOW()
                )
            ";
            
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':reference' => $this->currentSwapRef,
                ':action' => $action,
                ':value' => json_encode($data)
            ]);
        } catch (Exception $e) {
            $this->logger->error("Audit log failed", ['error' => $e->getMessage()]);
        }
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    private function generateReference(): string
    {
        return 'SWAP_' . bin2hex(random_bytes(16));
    }

    private function checkIdempotency(string $key): ?array
    {
        try {
            if (class_exists('Infrastructure\Mojaloop\IdempotencyService')) {
                return IdempotencyService::check($this->swapDB, $key);
            }
            
            $sql = "SELECT result FROM idempotency_keys WHERE key = :key AND created_at > NOW() - INTERVAL '24 hours'";
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $row ? json_decode($row['result'], true) : null;
        } catch (Exception $e) {
            $this->logger->error("Idempotency check failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function storeIdempotencyResult(string $key, array $result): void
    {
        try {
            if (class_exists('Infrastructure\Mojaloop\IdempotencyService')) {
                IdempotencyService::store($this->swapDB, $key, $result);
                return;
            }
            
            $sql = "
                INSERT INTO idempotency_keys (key, operation, result, created_at)
                VALUES (:key, 'swap', :result::jsonb, NOW())
                ON CONFLICT (key) DO UPDATE SET result = EXCLUDED.result, created_at = NOW()
            ";
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':key' => $key,
                ':result' => json_encode($result)
            ]);
        } catch (Exception $e) {
            $this->logger->error("Failed to store idempotency result", ['error' => $e->getMessage()]);
        }
    }

    private function loadConfiguration(string $country): void
    {
        $countryPath = __DIR__ . '/../../Core/Config/Countries/' . $country;
        
        $participantsPath = $countryPath . '/participants.yaml';
        if (file_exists($participantsPath)) {
            $this->participants = $this->parseYaml($participantsPath);
        }
        
        $endpointsPath = $countryPath . '/endpoints.yaml';
        if (file_exists($endpointsPath)) {
            $this->endpoints = $this->parseYaml($endpointsPath);
        }
        
        $feesPath = $countryPath . '/fees.json';
        if (file_exists($feesPath)) {
            $this->feesConfig = json_decode(file_get_contents($feesPath), true) ?? [];
        }
        
        $this->logger->info("Configuration loaded", ['country' => $country]);
    }

   private function parseYaml(string $path): array
{
    $content = file_get_contents($path);
    $data = [];
    $lines = explode("\n", $content);
    $currentKey = null;
    
    // First, find the participants section
    $inParticipants = false;
    $participantsData = [];
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        // Check for participants: section
        if (preg_match('/^participants:$/', $line)) {
            $inParticipants = true;
            continue;
        }
        
        if ($inParticipants) {
            // Match participant names (can be uppercase, uppercase with underscore)
            if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
                $currentKey = $matches[1];
                $participantsData[$currentKey] = [];
                continue;
            }
            
            // Match properties (indented with 4 spaces)
            if ($currentKey && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
                $participantsData[$currentKey][$key] = $value;
                continue;
            }
            
            // Match asset_types list
            if ($currentKey && preg_match('/^    asset_types:$/', $line)) {
                $participantsData[$currentKey]['asset_types'] = [];
                continue;
            }
            
            // Match items in asset_types list
            if ($currentKey && isset($participantsData[$currentKey]['asset_types']) && preg_match('/^      - (.+)$/', $line, $matches)) {
                $participantsData[$currentKey]['asset_types'][] = trim($matches[1]);
                continue;
            }
        }
    }
    
    return $participantsData;
}

    // ============================================================
    // PUBLIC METHODS
    // ============================================================

    public function isMultiSourceContribution(array $payload): bool
    {
        return isset($payload['is_multi_source']) && $payload['is_multi_source'] === true;
    }

   public function getParticipant(string $institution): array
{
    // Try exact match first
    if (isset($this->participants[$institution])) {
        return $this->participants[$institution];
    }
    
    // Try case-insensitive match
    $key = strtolower($institution);
    foreach ($this->participants as $code => $participant) {
        if (strtolower($code) === $key) {
            return $participant;
        }
        if (isset($participant['provider_code']) && strtolower($participant['provider_code']) === $key) {
            return $participant;
        }
        if (isset($participant['id']) && strtolower($participant['id']) === $key) {
            return $participant;
        }
    }
    
    throw new RuntimeException("Participant not found: {$institution}");
}

    public function getHoldStatus(int $holdId): ?array
    {
        $sql = "SELECT * FROM hold_transactions WHERE hold_id = :hold_id";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':hold_id' => $holdId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
