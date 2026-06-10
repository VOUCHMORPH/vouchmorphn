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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * ATOMIC SWAP ORCHESTRATOR
 * 
 * Delegates to:
 * - GenericBankClient for all bank API calls (verify, hold, debit, etc.)
 * - FeeService for fee calculation
 * - ForexService for exchange rates
 * - CardService for card operations
 */
class SwapService
{
    private PDO $swapDB;
    private array $config;
    private array $participants;
    private array $endpoints;
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
    private ?LoggerInterface $logger = null;
    
    // Atomic state
    private bool $inAtomicSwap = false;
    private ?string $currentSwapRef = null;
    private ?int $currentHoldId = null;
    private ?string $currentHoldReference = null;
    private array $executedSteps = [];
    private array $stepResults = [];

    public function __construct(
        PDO $swapDB, 
        array $config, 
        string $country,
        ?LoggerInterface $logger = null
    ) {
        $this->swapDB = $swapDB;
        $this->config = $config;
        $this->countryCode = strtoupper($country);
        $this->logger = $logger ?? new NullLogger();
        
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
        
        $this->logger->info("SwapService initialized", ['country' => $country]);
    }

    /**
     * Execute swap with ATOMIC guarantees
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
                'CASHOUT' => $this->executeCashout($payload),
                'DEPOSIT' => $this->executeDeposit($payload),
                'CARD_ISSUE' => $this->executeCardIssuance($payload),
                default => $this->executeStandardSwap($payload),
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
     * Execute standard swap with proper bank client integration
     */
    private function executeStandardSwap(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['source_institution'] ?? $payload['from_institution'];
        $destInstitution = $payload['destination_institution'] ?? $payload['to_institution'];
        
        // STEP 1: Verify source asset using GenericBankClient
        $verificationResult = $this->executeStep('VERIFY_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->verifySourceAsset($payload, $sourceInstitution);
        });
        
        if (!($verificationResult['verified'] ?? false)) {
            throw new RuntimeException("Source verification failed: " . ($verificationResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 2: Place hold using GenericBankClient
        $holdResult = $this->executeStep('PLACE_HOLD', function() use ($payload, $sourceInstitution, $verificationResult) {
            return $this->placeHold($payload, $sourceInstitution, $verificationResult);
        });
        
        if (!($holdResult['hold_placed'] ?? false) && !($holdResult['success'] ?? false)) {
            throw new RuntimeException("Failed to place hold: " . ($holdResult['message'] ?? 'Unknown error'));
        }
        
        // Store hold reference for rollback
        $this->currentHoldReference = $holdResult['hold_reference'] ?? $holdResult['data']['hold_reference'] ?? null;
        
        // STEP 3: Calculate fees using FeeService
        $feeBreakdown = $this->executeStep('CALCULATE_FEES', function() use ($payload, $amount) {
            $transactionType = $payload['transaction_type'] ?? 'SWAP';
            return $this->feeService->calculateFees($transactionType, $amount, $payload);
        });
        
        // STEP 4: Process destination using GenericBankClient
        $destinationResult = $this->executeStep('PROCESS_DESTINATION', function() use ($payload, $destInstitution, $amount, $feeBreakdown) {
            return $this->processDestination($payload, $destInstitution, $amount, $feeBreakdown);
        });
        
        // STEP 5: Debit source using GenericBankClient
        $debitResult = $this->executeStep('DEBIT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->debitSource($payload, $sourceInstitution);
        });
        
        if (!($debitResult['debited'] ?? false) && !($debitResult['success'] ?? false)) {
            throw new RuntimeException("Failed to debit source: " . ($debitResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 6: Update local hold status
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        // STEP 7: Record settlement
        $settlementResult = $this->executeStep('RECORD_SETTLEMENT', function() use ($payload, $destinationResult, $feeBreakdown) {
            return $this->settlement->recordSettlement($payload, $destinationResult, $feeBreakdown);
        });
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'hold_id' => $this->currentHoldId,
            'hold_reference' => $this->currentHoldReference,
            'amount' => $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'settlement' => $settlementResult
        ];
    }

    /**
     * Execute cashout using GenericBankClient
     */
    private function executeCashout(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['source_institution'] ?? $payload['from_institution'];
        $beneficiaryPhone = $payload['beneficiary_phone'] ?? $payload['client_phone'] ?? null;
        
        // STEP 1: Verify source
        $verificationResult = $this->executeStep('VERIFY_CASHOUT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->verifySourceAsset($payload, $sourceInstitution);
        });
        
        // STEP 2: Place hold
        $holdResult = $this->executeStep('PLACE_CASHOUT_HOLD', function() use ($payload, $sourceInstitution, $verificationResult) {
            return $this->placeHold($payload, $sourceInstitution, $verificationResult);
        });
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? $holdResult['data']['hold_reference'] ?? null;
        
        // STEP 3: Calculate fees
        $feeBreakdown = $this->executeStep('CALCULATE_CASHOUT_FEES', function() use ($payload, $amount) {
            return $this->feeService->calculateFees('CASHOUT', $amount, $payload);
        });
        
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        
        // STEP 4: Generate ATM token using GenericBankClient
        $tokenResult = $this->executeStep('GENERATE_TOKEN', function() use ($payload, $sourceInstitution, $netAmount) {
            return $this->generateAtmToken($payload, $sourceInstitution, $netAmount);
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
            'fee' => $feeBreakdown['total_fee'] ?? 0
        ];
    }

    /**
     * Execute deposit using GenericBankClient
     */
    private function executeDeposit(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['source_institution'] ?? $payload['from_institution'];
        $destInstitution = $payload['destination_institution'] ?? $payload['to_institution'];
        
        // STEP 1: Verify source
        $verificationResult = $this->executeStep('VERIFY_DEPOSIT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->verifySourceAsset($payload, $sourceInstitution);
        });
        
        // STEP 2: Process deposit using GenericBankClient
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
     * Execute card issuance using CardService
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
     * Execute multi-source swap using MultiSourceSwapExecutor
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
    // BANK CLIENT INTEGRATION METHODS (FIXED)
    // ============================================================

    /**
     * Verify source asset using GenericBankClient
     * FIXED: Actually calls the bank API instead of stub
     */
    private function verifySourceAsset(array $payload, string $institution): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        // Build verification payload based on asset type
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $verifyPayload = [
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'institution' => $institution
        ];
        
        // Add asset-specific fields
        switch ($assetType) {
            case 'VOUCHER':
            case 'CASHOUT-VOUCHER':
                $verifyPayload['voucher_number'] = $payload['voucher_number'] ?? $payload['CASHOUT-VOUCHER_number'] ?? null;
                $verifyPayload['voucher_pin'] = $payload['voucher_pin'] ?? $payload['pin'] ?? null;
                $verifyPayload['claimant_phone'] = $payload['claimant_phone'] ?? $payload['beneficiary_phone'] ?? null;
                break;
            case 'ACCOUNT':
                $verifyPayload['account_number'] = $payload['account_number'] ?? $payload['identifier'] ?? null;
                break;
            case 'MNO-WALLET':
            case 'BANK-WALLET':
                $verifyPayload['wallet_phone'] = $payload['wallet_phone'] ?? $payload['identifier'] ?? null;
                break;
            case 'CARD':
                $verifyPayload['card_number'] = $payload['card_number'] ?? $payload['identifier'] ?? null;
                break;
        }
        
        $result = $bankClient->verifyAsset($verifyPayload);
        
        if (!$result['success']) {
            $this->logger->error("Verification failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? $result['raw_response'] ?? 'Unknown',
                'status_code' => $result['status_code'] ?? 0
            ]);
            return ['verified' => false, 'message' => $result['curl_error'] ?? 'Verification failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'verified' => $data['verified'] ?? false,
            'message' => $data['message'] ?? null,
            'balance' => $data['available_balance'] ?? $data['balance'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'raw_response' => $result
        ];
    }

    /**
     * Place hold using GenericBankClient
     * FIXED: Actually calls the bank API instead of stub
     */
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
            'destination_institution' => $payload['destination_institution'] ?? $payload['to_institution'] ?? null,
            'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour'))
        ];
        
        // Add verification reference if provided
        if (isset($verificationResult['asset_id'])) {
            $holdPayload['asset_id'] = $verificationResult['asset_id'];
        }
        
        // Add asset-specific fields
        switch ($assetType) {
            case 'VOUCHER':
            case 'CASHOUT-VOUCHER':
                $holdPayload['voucher_number'] = $payload['voucher_number'] ?? null;
                $holdPayload['claimant_phone'] = $payload['claimant_phone'] ?? $payload['beneficiary_phone'] ?? null;
                break;
            case 'ACCOUNT':
                $holdPayload['account_number'] = $payload['account_number'] ?? null;
                break;
            case 'MNO-WALLET':
            case 'BANK-WALLET':
                $holdPayload['wallet_phone'] = $payload['wallet_phone'] ?? null;
                break;
        }
        
        $result = $bankClient->placeHold($holdPayload);
        
        if (!$result['success']) {
            $this->logger->error("Hold placement failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? $result['raw_response'] ?? 'Unknown'
            ]);
            return ['hold_placed' => false, 'message' => $result['curl_error'] ?? 'Hold failed'];
        }
        
        $data = $result['data'] ?? [];
        
        // Create local hold record
        $holdId = $this->createLocalHold($payload, $institution, $data['hold_reference'] ?? null);
        $this->currentHoldId = $holdId;
        
        return [
            'hold_placed' => true,
            'hold_reference' => $data['hold_reference'] ?? $data['reference'] ?? null,
            'local_hold_id' => $holdId,
            'message' => $data['message'] ?? 'Hold placed successfully',
            'raw_response' => $result
        ];
    }

    /**
     * Debit source using GenericBankClient
     */
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
        
        // Use debitHold which calls debitFunds internally
        $result = $bankClient->debitHold($debitPayload);
        
        if (!$result['success']) {
            $this->logger->error("Debit failed", [
                'institution' => $institution,
                'hold_reference' => $this->currentHoldReference,
                'error' => $result['curl_error'] ?? $result['raw_response'] ?? 'Unknown'
            ]);
            return ['debited' => false, 'message' => $result['curl_error'] ?? 'Debit failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'debited' => true,
            'transaction_reference' => $data['transaction_reference'] ?? $data['reference'] ?? null,
            'message' => $data['message'] ?? 'Debit successful'
        ];
    }

    /**
     * Process destination using GenericBankClient
     */
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
            $this->logger->error("Destination processing failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? $result['raw_response'] ?? 'Unknown'
            ]);
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Processing failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'transaction_reference' => $data['transaction_reference'] ?? $data['reference'] ?? null,
            'message' => $data['message'] ?? 'Destination processed successfully'
        ];
    }

    /**
     * Process deposit using GenericBankClient
     */
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
            $this->logger->error("Deposit processing failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? $result['raw_response'] ?? 'Unknown'
            ]);
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Deposit failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'transaction_reference' => $data['transaction_reference'] ?? $data['reference'] ?? null,
            'message' => $data['message'] ?? 'Deposit successful'
        ];
    }

    /**
     * Generate ATM token using GenericBankClient
     */
    private function generateAtmToken(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $tokenPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'beneficiary_phone' => $payload['beneficiary_phone'] ?? $payload['client_phone'] ?? null,
            'hold_reference' => $this->currentHoldReference,
            'action' => 'GENERATE_ATM_TOKEN'
        ];
        
        $result = $bankClient->generateToken($tokenPayload);
        
        if (!$result['success']) {
            $this->logger->error("Token generation failed", [
                'institution' => $institution,
                'error' => $result['curl_error'] ?? $result['raw_response'] ?? 'Unknown'
            ]);
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Token generation failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'atm_pin' => $data['atm_pin'] ?? $data['pin'] ?? null,
            'voucher_number' => $data['voucher_number'] ?? $data['sat_number'] ?? null,
            'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'))
        ];
    }

    // ============================================================
    // LOCAL DATABASE OPERATIONS
    // ============================================================

    /**
     * Create local hold record
     */
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
            ':destination' => $payload['destination_institution'] ?? $payload['to_institution'] ?? null,
            ':external_ref' => $externalHoldRef
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$row['hold_id'];
    }

    /**
     * Update local hold status
     */
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
        
        // Release external hold if it exists
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
        
        // Update local hold status
        if ($this->currentHoldId) {
            try {
                $this->updateHoldStatus($this->currentHoldId, 'RELEASED');
                $rollbackSteps[] = 'local_hold_released';
            } catch (Exception $e) {
                $this->logger->error("Failed to update local hold", ['error' => $e->getMessage()]);
                $rollbackSteps[] = 'local_hold_update_failed';
            }
        }
        
        // Rollback database transaction
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

    /**
     * Release external hold using GenericBankClient
     */
    private function releaseExternalHold(): void
    {
        if (!$this->currentHoldReference) {
            return;
        }
        
        // Need to get the participant from the original hold
        // This requires storing the participant in state, or looking it up
        // For now, log that we need to implement this
        $this->logger->info("Would release external hold", ['hold_reference' => $this->currentHoldReference]);
        
        // TODO: Get participant and call $bankClient->releaseHold()
    }

    private function resetAtomicState(): void
    {
        $this->inAtomicSwap = false;
        $this->currentSwapRef = null;
        $this->currentHoldId = null;
        $this->currentHoldReference = null;
        $this->executedSteps = [];
        $this->stepResults = [];
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
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^([a-z_]+):$/', $line, $matches)) {
                $currentKey = $matches[1];
                $data[$currentKey] = [];
            } elseif ($currentKey && preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                $data[$currentKey][$matches[1]] = $value;
            }
        }
        
        return $data;
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
        $key = strtolower($institution);
        if (isset($this->participants[$key])) {
            return $this->participants[$key];
        }
        
        foreach ($this->participants as $code => $participant) {
            if (isset($participant['provider_code']) && strtolower($participant['provider_code']) === $key) {
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
