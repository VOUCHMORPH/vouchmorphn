<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;
use PDOException;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\Settlement\UniversalFeeEngine;
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
use Infrastructure\Crypto\CertificateManager;
use Psr\Log\LoggerInterface;

/**
 * SIGNED ATOMIC SWAP ORCHESTRATOR
 * 
 * CASHOUT FLOW (3 steps at destination):
 * 1. generate_token - Creates ATM/Agent cashout code
 * 2. verify_token - Validates code when user cashes out
 * 3. confirm_cashout - Confirms cashout completed, THEN debit source
 * 
 * DEPOSIT FLOW (1 step at destination):
 * 1. process_deposit - Direct deposit to account/wallet, THEN debit source
 */
class SwapService
{
    private PDO $swapDB;
    private array $config = [];
    private array $participants = [];
    private array $endpoints = [];
    private string $countryCode;
    private array $feesConfig = [];
    private array $atmNotes = [];
    private array $feeCalculationDetails = [];
    
    private HybridSettlementStrategy $settlement;
    private UniversalFeeEngine $feeEngine;
    private FeeService $feeService;
    private ForexService $forexService;
    private ?CardService $cardService = null;
    private ?SmsNotificationService $smsService = null;
    private ?ContributionCalculator $contributionCalculator = null;
    private ?MultiSourceFeeCalculator $multiSourceFeeCalculator = null;
    private ?MultiSourceSwapExecutor $multiSourceExecutor = null;
    private $logger = null;
    
    private MessageSigner $messageSigner;
    private SignatureVerifier $signatureVerifier;
    private ?CertificateManager $certificateManager = null;
    
    private bool $inAtomicSwap = false;
    private ?string $currentSwapRef = null;
    private ?int $currentHoldId = null;
    private ?string $currentHoldReference = null;
    private array $executedSteps = [];
    private array $stepResults = [];
    private array $signedPayloads = [];
    
    // Current transaction state from fee engine
    private array $currentTransactionState = [];

    public function __construct(
        PDO $swapDB, 
        array $config, 
        string $country,
        ?LoggerInterface $logger = null
    ) {
        $this->swapDB = $swapDB;
        $this->config = $config;
        $this->countryCode = strtoupper($country);
        
        if ($logger === null) {
            $this->logger = new class {
                public function info($message, array $context = []) {
                    error_log("[SwapService][INFO] " . $message . " " . json_encode($context));
                }
                public function error($message, array $context = []) {
                    error_log("[SwapService][ERROR] " . $message . " " . json_encode($context));
                }
                public function warning($message, array $context = []) {
                    error_log("[SwapService][WARNING] " . $message . " " . json_encode($context));
                }
                public function debug($message, array $context = []) {
                    error_log("[SwapService][DEBUG] " . $message . " " . json_encode($context));
                }
                public function log($level, $message, array $context = []) {
                    error_log("[SwapService][{$level}] " . $message . " " . json_encode($context));
                }
                public function __call($name, $args) {
                    error_log("[SwapService][{$name}] " . ($args[0] ?? '') . " " . json_encode($args[1] ?? []));
                }
            };
        } else {
            $this->logger = $logger;
        }
        
        $this->messageSigner = new MessageSigner();
        $this->signatureVerifier = new SignatureVerifier($this->swapDB);
        
        if (class_exists('Infrastructure\Crypto\CertificateManager')) {
            $this->certificateManager = new CertificateManager('VOUCHMORPH');
            if ($this->certificateManager->isConfigured()) {
                $this->logger->info("CertificateManager initialized for VOUCHMORPH (CA trust model)");
            } else {
                $this->logger->warning("CertificateManager not fully configured - falling back to legacy signatures");
            }
        }
        
        $this->loadConfiguration($country);
        $this->loadAtmNotes($country);
        
        // Initialize settlement services
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        $this->feeEngine = new UniversalFeeEngine(
            $this->swapDB,
            $this->config,
            $this->feesConfig,
            $this->participants,
            $this->atmNotes[$this->config['currency'] ?? 'BWP'] ?? [200, 100, 50, 20, 10]
        );
        $this->feeService = new FeeService($this->feesConfig, $this->config);
        $this->feeService->setParticipants($this->participants); 
        $this->forexService = new ForexService($this->swapDB, $this->config, $this->participants, $this->feeService);
        
        // Initialize SMS service
        $smsConfig = $this->participants['sms'] ?? [];
        if (!empty($smsConfig)) {
            $this->smsService = new SmsNotificationService($smsConfig);
        }
        
        $vouchmorphConfig = $this->participants['vouchmorph'] ?? [];
        if (!empty($vouchmorphConfig)) {
            $this->cardService = new CardService($this->swapDB, $this->countryCode, $vouchmorphConfig);
        }
        
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

    private function loadAtmNotes(string $country): void
    {
        $atmNotesPath = __DIR__ . '/../../Core/Config/Countries/' . $country . '/atm_notes.json';
        
        if (file_exists($atmNotesPath)) {
            $this->atmNotes = json_decode(file_get_contents($atmNotesPath), true);
            error_log("[SwapService] Loaded ATM notes for {$country}: " . json_encode($this->atmNotes));
        } else {
            $currency = $this->config['currency'] ?? 'BWP';
            $this->atmNotes[$currency] = [200, 100, 50, 20, 10];
            error_log("[SwapService] Using default ATM notes for {$currency}: " . json_encode($this->atmNotes[$currency]));
        }
    }

    private function validateCashoutAmount(float $requestedAmount, string $currency): array
    {
        $denominations = $this->atmNotes[$currency] ?? [200, 100, 50, 20, 10];
        sort($denominations);
        
        $remaining = $requestedAmount;
        $noteBreakdown = [];
        
        foreach (array_reverse($denominations) as $note) {
            if ($remaining >= $note) {
                $count = floor($remaining / $note);
                $noteBreakdown[$note] = $count;
                $remaining = round($remaining - ($note * $count), 2);
            }
        }
        
        $dispensableAmount = $requestedAmount - $remaining;
        $remainderBalance = $remaining;
        
        return [
            'requested_amount' => $requestedAmount,
            'dispensable_amount' => $dispensableAmount,
            'remainder_balance' => $remainderBalance,
            'note_breakdown' => $noteBreakdown,
            'denominations_used' => $denominations,
            'is_exact' => ($remainderBalance == 0),
            'message' => $remainderBalance > 0 
                ? "Cannot dispense exact amount. Will dispense {$dispensableAmount} ({$remainderBalance} will remain in account)"
                : "Exact amount can be dispensed"
        ];
    }

    private function calculateFeesWithDetails(string $feeType, float $amount, array $payload): array
    {
        $this->feeCalculationDetails = [];
        
        // Use UniversalFeeEngine for mathematical calculation
        $context = array_merge($payload, [
            'swap_reference' => $this->currentSwapRef,
            'multiplier' => $this->atmNotes[$payload['currency'] ?? 'BWP'][0] ?? 100
        ]);
        
        $this->currentTransactionState = $this->feeEngine->calculateAnticipatedFees($amount, $feeType, $context);
        
        $this->feeCalculationDetails = [
            'fee_type' => $feeType,
            'original_amount' => $amount,
            'total_fee' => $this->currentTransactionState['total_fee'],
            'net_amount' => $this->currentTransactionState['target_amount'],
            'distributable_pool' => $this->currentTransactionState['distributable_pool'],
            'platform_cut' => $this->currentTransactionState['platform_cut'],
            'source_cut' => $this->currentTransactionState['source_cut'],
            'destination_base_share' => $this->currentTransactionState['destination_base_share'],
            'generate_code_fee' => $this->currentTransactionState['generate_code_fee'],
            'cashout_completion_fee' => $this->currentTransactionState['cashout_completion_fee'],
            'dispensable_amount' => $this->currentTransactionState['dispensable_amount'],
            'remainder_balance' => $this->currentTransactionState['remainder_balance'],
            'multiplier' => $this->currentTransactionState['multiplier'],
            'fee_registry' => $this->feeEngine->getFeeRegistry()
        ];
        
        return [
            'total_fee' => $this->currentTransactionState['total_fee'],
            'net_amount' => $this->currentTransactionState['target_amount'],
            'components' => $this->feeCalculationDetails
        ];
    }

    private function adjustAmountForDelivery(float $amount, string $deliveryMethod, string $currency): array
    {
        $deliveryMethod = strtoupper($deliveryMethod);
        
        if (in_array($deliveryMethod, ['DEPOSIT', 'VOUCHER', 'AGENT', 'WALLET', 'CARD'])) {
            return [
                'deliverable_amount' => $amount,
                'remainder_at_source' => 0,
                'is_adjusted' => false,
                'message' => "Full amount {$amount} will be delivered via {$deliveryMethod}"
            ];
        }
        
        if ($deliveryMethod === 'ATM') {
            $validation = $this->validateCashoutAmount($amount, $currency);
            
            if ($validation['dispensable_amount'] <= 0) {
                return [
                    'deliverable_amount' => 0,
                    'remainder_at_source' => $amount,
                    'is_adjusted' => true,
                    'message' => "Amount {$amount} cannot be dispensed by ATM. Please use AGENT cashout."
                ];
            }
            
            return [
                'deliverable_amount' => $validation['dispensable_amount'],
                'remainder_at_source' => $validation['remainder_balance'],
                'note_breakdown' => $validation['note_breakdown'],
                'is_adjusted' => $validation['remainder_balance'] > 0,
                'message' => $validation['message']
            ];
        }
        
        return [
            'deliverable_amount' => $amount,
            'remainder_at_source' => 0,
            'is_adjusted' => false,
            'message' => "Amount {$amount} accepted for {$deliveryMethod}"
        ];
    }

    private function extractSourceIdentifier(array $payload): array
    {
        $sourceIdentifier = null;
        $sourceIdentifierType = null;
        
        if (!empty($payload['source_identifier'])) {
            $sourceIdentifier = $payload['source_identifier'];
            $sourceIdentifierType = $payload['source_identifier_type'] ?? 'auto';
        } elseif (!empty($payload['source_phone'])) {
            $sourceIdentifier = $payload['source_phone'];
            $sourceIdentifierType = 'phone';
        } elseif (!empty($payload['source_wallet_phone'])) {
            $sourceIdentifier = $payload['source_wallet_phone'];
            $sourceIdentifierType = 'phone';
        } elseif (!empty($payload['wallet_phone'])) {
            $sourceIdentifier = $payload['wallet_phone'];
            $sourceIdentifierType = 'phone';
        } elseif (!empty($payload['phone'])) {
            $sourceIdentifier = $payload['phone'];
            $sourceIdentifierType = 'phone';
        } elseif (!empty($payload['source_national_id'])) {
            $sourceIdentifier = $payload['source_national_id'];
            $sourceIdentifierType = 'national_id';
        } elseif (!empty($payload['national_id'])) {
            $sourceIdentifier = $payload['national_id'];
            $sourceIdentifierType = 'national_id';
        } elseif (!empty($payload['source_email'])) {
            $sourceIdentifier = $payload['source_email'];
            $sourceIdentifierType = 'email';
        } elseif (!empty($payload['email'])) {
            $sourceIdentifier = $payload['email'];
            $sourceIdentifierType = 'email';
        }
        
        return [
            'identifier' => $sourceIdentifier,
            'type' => $sourceIdentifierType,
            'has_value' => !empty($sourceIdentifier)
        ];
    }

    private function extractDestinationIdentifier(array $payload): array
    {
        $destinationIdentifier = null;
        $destinationIdentifierType = null;
        
        if (!empty($payload['destination_identifier'])) {
            $destinationIdentifier = $payload['destination_identifier'];
            $destinationIdentifierType = $payload['destination_identifier_type'] ?? 'auto';
        } elseif (!empty($payload['destination_account'])) {
            $destinationIdentifier = $payload['destination_account'];
            $destinationIdentifierType = 'account';
        } elseif (!empty($payload['destination_phone'])) {
            $destinationIdentifier = $payload['destination_phone'];
            $destinationIdentifierType = 'phone';
        } elseif (!empty($payload['destination_national_id'])) {
            $destinationIdentifier = $payload['destination_national_id'];
            $destinationIdentifierType = 'national_id';
        } elseif (!empty($payload['destination_email'])) {
            $destinationIdentifier = $payload['destination_email'];
            $destinationIdentifierType = 'email';
        } elseif (!empty($payload['beneficiary_account'])) {
            $destinationIdentifier = $payload['beneficiary_account'];
            $destinationIdentifierType = 'account';
        } elseif (!empty($payload['beneficiary_phone'])) {
            $destinationIdentifier = $payload['beneficiary_phone'];
            $destinationIdentifierType = 'phone';
        }
        
        return [
            'identifier' => $destinationIdentifier,
            'type' => $destinationIdentifierType,
            'has_value' => !empty($destinationIdentifier)
        ];
    }

    private function extractBeneficiaryPhone(array $payload): ?string
    {
        return $payload['beneficiary_phone'] ?? 
               $payload['beneficiary_identifier'] ?? 
               $payload['client_phone'] ?? 
               null;
    }

    public function executeAtomicSwap(array $payload): array
    {
        if (isset($payload['original_payload'])) {
            error_log("[SwapService] Signed envelope detected, extracting original_payload");
            $this->signedPayloads['envelope'] = [
                'signature' => $payload['signature'] ?? null,
                'timestamp' => $payload['timestamp'] ?? null
            ];
            $payload = $payload['original_payload'];
        }
        
        $sourceInst = $payload['from_institution'] ?? $payload['source_institution'] ?? null;
        $destInst = $payload['to_institution'] ?? $payload['destination_institution'] ?? null;
        $swapType = $payload['swap_type'] ?? 'STANDARD';
        
        if (empty($sourceInst)) {
            throw new RuntimeException("Missing source institution (from_institution or source_institution)");
        }
        if (empty($destInst)) {
            throw new RuntimeException("Missing destination institution (to_institution or destination_institution)");
        }
        
        error_log("[SwapService] Source: {$sourceInst}, Dest: {$destInst}, Type: {$swapType}");
        
        if ($swapType === 'CASHOUT') {
            $amount = (float)($payload['amount'] ?? 0);
            $currency = $payload['currency'] ?? $this->config['currency'] ?? 'BWP';
            $validation = $this->validateCashoutAmount($amount, $currency);
            
            if (!$validation['is_exact']) {
                $this->logger->warning("Cashout amount cannot be dispensed exactly", $validation);
                $payload['_cashout_validation'] = $validation;
            }
            
            if ($validation['dispensable_amount'] != $amount) {
                $payload['original_requested_amount'] = $amount;
                $payload['amount'] = $validation['dispensable_amount'];
                $payload['remainder_balance'] = $validation['remainder_balance'];
                $payload['note_breakdown'] = $validation['note_breakdown'];
            }
        }
        
        $ref = $payload['reference'] ?? $this->generateReference();
        $idempotencyKey = $payload['idempotency_key'] ?? $payload['idempotencyKey'] ?? null;
        
        if ($idempotencyKey) {
            $cached = $this->checkIdempotency($idempotencyKey);
            if ($cached) {
                $this->logger->info("Idempotency cache hit", ['key' => $idempotencyKey]);
                return $cached;
            }
        }
        
        $this->beginAtomicSwap($ref);
        
        try {
            $result = match($swapType) {
                'MULTI_SOURCE' => $this->executeMultiSourceSwap($payload),
                'CASHOUT' => $this->executeSignedCashout($payload),
                'DEPOSIT' => $this->executeSignedDeposit($payload),
                'CARD_ISSUE' => $this->executeCardIssuance($payload),
                'VERIFY_CASHOUT' => $this->verifyCashout($payload),
                'CONFIRM_CASHOUT' => $this->confirmCashout($payload),
                default => $this->executeSignedStandardSwap($payload),
            };
            
            if (!empty($this->feeCalculationDetails)) {
                $result['fee_calculation_details'] = $this->feeCalculationDetails;
            }
            
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

    // ============================================================
    // EXECUTE SIGNED CASHOUT - GENERATE CODE ONLY (NO DEBIT)
    // ============================================================

    private function executeSignedCashout(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedCashout START (CODE GENERATION ONLY) =====");
        
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        $deliveryMethod = strtoupper($payload['delivery_method'] ?? 'ATM');
        
        if (isset($payload['_cashout_validation'])) {
            $this->feeCalculationDetails['cashout_validation'] = $payload['_cashout_validation'];
        }
        if (isset($payload['note_breakdown'])) {
            $this->feeCalculationDetails['note_breakdown'] = $payload['note_breakdown'];
        }
        
        // STEP 1: VERIFY ASSET - MUST succeed
        error_log("[SwapService] STEP 1: Verifying asset with source institution: {$sourceInstitution}");
        $verificationResult = $this->executeStep('VERIFY_ASSET_SIGNED', function() use ($payload, $sourceInstitution) {
            return $this->verifyAssetSigned($payload, $sourceInstitution);
        });
        
        if (!($verificationResult['verified'] ?? false)) {
            $errorMessage = $verificationResult['message'] ?? 'Asset verification failed';
            error_log("[SwapService] VERIFICATION FAILED: {$errorMessage}");
            throw new RuntimeException("Asset verification failed: {$errorMessage}");
        }
        
        error_log("[SwapService] Asset verified successfully");
        
        $this->signedPayloads['verification'] = [
            'payload' => $verificationResult['original_payload'],
            'signature' => $verificationResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $verificationResult['timestamp']
        ];
        
        // STEP 2: PLACE HOLD - MUST succeed
        error_log("[SwapService] STEP 2: Placing hold on asset at source: {$sourceInstitution}");
        $holdResult = $this->executeStep('PLACE_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
            return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        });
        
        if (!($holdResult['hold_placed'] ?? false)) {
            $errorMessage = $holdResult['message'] ?? 'Failed to place hold';
            error_log("[SwapService] HOLD FAILED: {$errorMessage}");
            throw new RuntimeException("Hold failed: {$errorMessage}");
        }
        
        error_log("[SwapService] Hold placed successfully");
        
        $this->signedPayloads['hold'] = [
            'payload' => $holdResult['original_payload'],
            'signature' => $holdResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $holdResult['timestamp']
        ];
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? $holdResult['data']['hold_reference'] ?? null;
        
        // STEP 3: CALCULATE FEES USING UNIVERSAL FEE ENGINE
        error_log("[SwapService] STEP 3: Calculating fees using UniversalFeeEngine");
        $feeBreakdown = $this->calculateFeesWithDetails('CASHOUT', $amount, $payload);
        $amountToSend = $this->currentTransactionState['dispensable_amount'];
        $remainderAtSource = $this->currentTransactionState['remainder_balance'];
        
        error_log("[SwapService] Amount after fees: {$this->currentTransactionState['target_amount']}, Deliverable: {$amountToSend}, Remainder: {$remainderAtSource}");
        
        if ($amountToSend <= 0) {
            throw new RuntimeException("Amount after fees cannot be delivered via {$deliveryMethod}");
        }
        
        // STEP 4: GENERATE CODE AT DESTINATION
        error_log("[SwapService] STEP 4: Generating cashout code at DESTINATION: {$destinationInstitution}");
        
        $generateResult = $this->generateCashoutToken($payload, $destinationInstitution, $amountToSend);
        
        if (!($generateResult['success'] ?? false)) {
            throw new RuntimeException("Code generation failed: " . ($generateResult['message'] ?? 'Unknown error'));
        }
        
        if (empty($generateResult['atm_pin']) && empty($generateResult['voucher_number'])) {
            throw new RuntimeException("Destination failed: No code generated");
        }
        
        error_log("[SwapService] Cashout code generated successfully");
        
        // STEP 5: SEND SMS with code (optional)
        if ($beneficiaryPhone && $this->smsService && isset($generateResult['atm_pin'])) {
            try {
                $this->smsService->sendCashoutCode(
                    $beneficiaryPhone,
                    $generateResult['atm_pin'],
                    $amountToSend,
                    $generateResult['voucher_number'] ?? null
                );
            } catch (Exception $e) {
                error_log("[SwapService] SMS failed but continuing: " . $e->getMessage());
            }
        }
        
        // STEP 6: UPDATE HOLD TO PENDING_CASHOUT (NOT DEBITED YET!)
        $this->updateHoldStatus($this->currentHoldId, 'PENDING_CASHOUT');
        
        // Store pending cashout data for later confirmation
        $this->storePendingCashout($this->currentSwapRef, $this->currentHoldReference, $generateResult, $sourceInstitution, $amountToSend, $feeBreakdown);
        
        // IMPORTANT: DO NOT DEBIT SOURCE HERE!
        // Source will be debited ONLY after user successfully cashes out
        
        $result = [
            'status' => 'pending_cashout',
            'reference' => $this->currentSwapRef,
            'hold_reference' => $this->currentHoldReference,
            'atm_code' => $generateResult['atm_pin'] ?? null,
            'voucher_number' => $generateResult['voucher_number'] ?? null,
            'amount' => $amountToSend,
            'original_requested_amount' => $payload['original_requested_amount'] ?? $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'delivery_method' => $deliveryMethod,
            'message' => 'Cashout code generated. User must cash out at ATM/Agent to complete the swap.',
            'fee_calculation_details' => $this->feeCalculationDetails,
            'signature_chain' => $this->signedPayloads
        ];
        
        error_log("[SwapService] ===== executeSignedCashout SUCCESS (pending cashout) =====");
        
        return $result;
    }

    /**
     * Verify cashout token - Step 2 of cashout flow
     * Called when user enters code at ATM/Agent
     */
    public function verifyCashout(array $payload): array
    {
        error_log("[SwapService] ===== verifyCashout START =====");
        
        $code = $payload['code'] ?? null;
        $voucherNumber = $payload['voucher_number'] ?? null;
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        
        if (!$code && !$voucherNumber) {
            throw new RuntimeException("Code or voucher number required");
        }
        
        $participant = $this->getParticipant($destinationInstitution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $verifyPayload = [
            'reference' => $payload['reference'] ?? $this->generateReference(),
            'code' => $code,
            'voucher_number' => $voucherNumber,
            'action' => 'VERIFY_TOKEN'
        ];
        
        $result = $bankClient->verifyToken($verifyPayload);
        
        if (!$result['success']) {
            throw new RuntimeException("Token verification failed: " . ($result['curl_error'] ?? 'Unknown error'));
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'status' => 'verified',
            'verified' => $data['verified'] ?? false,
            'amount' => $data['amount'] ?? 0,
            'message' => $data['message'] ?? 'Code verified successfully'
        ];
    }

    /**
     * Confirm cashout - Step 3 of cashout flow
     * Called AFTER user successfully withdraws cash
     * THIS IS WHERE SOURCE GETS DEBITED
     */
    public function confirmCashout(array $payload): array
    {
        error_log("[SwapService] ===== confirmCashout START =====");
        
        $swapReference = $payload['swap_reference'] ?? null;
        $holdReference = $payload['hold_reference'] ?? null;
        $code = $payload['code'] ?? null;
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        $actualDeliveredAmount = (float)($payload['actual_amount'] ?? 0);
        
        if (!$swapReference && !$holdReference) {
            throw new RuntimeException("Swap reference or hold reference required");
        }
        
        // Get pending cashout data from database
        $pendingCashout = $this->getPendingCashout($swapReference, $holdReference);
        
        if (!$pendingCashout) {
            throw new RuntimeException("No pending cashout found for reference: {$swapReference}");
        }
        
        $sourceInstitution = $pendingCashout['source_institution'];
        
        // STEP 1: Confirm cashout with destination institution
        $participant = $this->getParticipant($destinationInstitution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $confirmPayload = [
            'reference' => $swapReference,
            'hold_reference' => $holdReference,
            'code' => $code,
            'voucher_number' => $pendingCashout['voucher_number'],
            'amount' => $pendingCashout['amount'],
            'action' => 'CONFIRM_CASHOUT'
        ];
        
        $confirmResult = $bankClient->confirmCashout($confirmPayload);
        
        if (!$confirmResult['success']) {
            throw new RuntimeException("Cashout confirmation failed: " . ($confirmResult['curl_error'] ?? 'Unknown error'));
        }
        
        $data = $confirmResult['data'] ?? [];
        
        if (!($data['confirmed'] ?? false)) {
            throw new RuntimeException("Cashout not confirmed by destination institution");
        }
        
        // STEP 2: CALCULATE STANDARD COMPLETION USING FEE ENGINE
        $completionResult = $this->feeEngine->calculateStandardCompletion($actualDeliveredAmount ?: $pendingCashout['amount']);
        
        // STEP 3: DEBIT SOURCE (ONLY NOW!)
        error_log("[SwapService] Cashout confirmed, debiting source: {$sourceInstitution} for {$completionResult['final_source_debit']}");
        
        $debitPayload = [
            'reference' => $swapReference,
            'hold_reference' => $holdReference,
            'amount' => $completionResult['final_source_debit'],
            'reason' => 'Cashout completed successfully'
        ];
        
        $debitResult = $this->debitSource($debitPayload, $sourceInstitution);
        
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed: " . ($debitResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 4: UPDATE HOLD STATUS
        $this->updateHoldStatus($pendingCashout['hold_id'], 'DEBITED');
        
        // STEP 5: RECORD SETTLEMENT (DELEGATE TO HYBRID SETTLEMENT)
        $settlementResult = $this->settlement->updateNetPosition(
            $swapReference,
            $sourceInstitution,
            $destinationInstitution,
            $completionResult['final_source_debit'],
            'CASHOUT_COMPLETED',
            $this->config['currency'] ?? 'BWP'
        );
        
        // STEP 6: INVOICE FEES (DELEGATE TO HYBRID SETTLEMENT)
        $this->settlement->invoiceFee(
            $swapReference,
            $sourceInstitution,
            $this->getParticipantId($sourceInstitution),
            'CASHOUT_COMPLETION_FEE',
            $pendingCashout['fee'],
            $this->config['currency'] ?? 'BWP'
        );
        
        // STEP 7: Mark cashout as completed
        $this->markCashoutCompleted($swapReference, $holdReference);
        
        // STEP 8: Record settlement proof
        $this->feeEngine->recordSettlementProof([
            'swap_reference' => $swapReference,
            'completion_result' => $completionResult,
            'final_debit' => $completionResult['final_source_debit'],
            'client_refund' => $completionResult['client_refund']
        ]);
        
        return [
            'status' => 'completed',
            'reference' => $swapReference,
            'hold_reference' => $holdReference,
            'message' => 'Cashout completed successfully',
            'amount' => $pendingCashout['amount'],
            'fee' => $pendingCashout['fee'],
            'client_refund' => $completionResult['client_refund'],
            'settlement' => $settlementResult
        ];
    }

    // ============================================================
    // EXECUTE SIGNED DEPOSIT - With Account Verification
    // ============================================================

    private function executeSignedDeposit(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedDeposit START =====");
        
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        $destinationIdentifier = $this->extractDestinationIdentifier($payload);
        
        // STEP 1: VERIFY ASSET AT SOURCE - MUST succeed
        error_log("[SwapService] STEP 1: Verifying asset with source institution: {$sourceInstitution}");
        $verificationResult = $this->executeStep('VERIFY_ASSET_SIGNED', function() use ($payload, $sourceInstitution) {
            return $this->verifyAssetSigned($payload, $sourceInstitution);
        });
        
        if (!($verificationResult['verified'] ?? false)) {
            throw new RuntimeException("Asset verification failed: " . ($verificationResult['message'] ?? 'Unknown error'));
        }
        
        $this->signedPayloads['verification'] = [
            'payload' => $verificationResult['original_payload'],
            'signature' => $verificationResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $verificationResult['timestamp']
        ];
        
        // STEP 2: VERIFY DESTINATION ACCOUNT - MUST succeed
        error_log("[SwapService] STEP 2: Verifying destination account at: {$destinationInstitution}");
        
        if (empty($destinationIdentifier['identifier'])) {
            throw new RuntimeException("Destination identifier is required for deposit");
        }
        
        $accountVerification = $this->executeStep('VERIFY_ACCOUNT', function() use ($payload, $destinationInstitution, $destinationIdentifier) {
            return $this->verifyAccount($payload, $destinationInstitution, $destinationIdentifier);
        });
        
        if (!($accountVerification['verified'] ?? false)) {
            throw new RuntimeException("Destination account verification failed: " . ($accountVerification['message'] ?? 'Account not found'));
        }
        
        // STEP 3: CALCULATE FEES USING UNIVERSAL FEE ENGINE
        error_log("[SwapService] STEP 3: Calculating fees using UniversalFeeEngine");
        $feeBreakdown = $this->calculateFeesWithDetails('DEPOSIT', $amount, $payload);
        $netAmount = $this->currentTransactionState['target_amount'];
        
        // STEP 4: PLACE HOLD ON SOURCE
        error_log("[SwapService] STEP 4: Placing hold on source: {$sourceInstitution}");
        $holdResult = $this->executeStep('PLACE_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
            return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        });
        
        if (!($holdResult['hold_placed'] ?? false)) {
            throw new RuntimeException("Hold failed: " . ($holdResult['message'] ?? 'Unknown error'));
        }
        
        $this->signedPayloads['hold'] = [
            'payload' => $holdResult['original_payload'],
            'signature' => $holdResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $holdResult['timestamp']
        ];
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? null;
        
        // STEP 5: PROCESS DEPOSIT AT DESTINATION
        error_log("[SwapService] STEP 5: Processing deposit at DESTINATION: {$destinationInstitution} for amount: {$netAmount}");
        
        $depositResult = $this->executeStep('PROCESS_DEPOSIT_WITH_PROOF', function() use ($payload, $destinationInstitution, $netAmount, $accountVerification) {
            $depositPayload = $payload;
            $depositPayload['amount'] = $netAmount;
            $depositPayload['account_verification'] = $accountVerification;
            return $this->processDepositWithProof($depositPayload, $destinationInstitution, $netAmount);
        });
        
        if (!($depositResult['success'] ?? false)) {
            throw new RuntimeException("Deposit failed: " . ($depositResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 6: DEBIT SOURCE
        error_log("[SwapService] STEP 6: Debiting source {$sourceInstitution} for amount: {$amount}");
        $debitResult = $this->executeStep('DEBIT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->debitSource($payload, $sourceInstitution);
        });
        
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed: " . ($debitResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 7: UPDATE HOLD STATUS
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        // STEP 8: RECORD SETTLEMENT
        $settlementResult = $this->settlement->updateNetPosition(
            $this->currentSwapRef,
            $sourceInstitution,
            $destinationInstitution,
            $netAmount,
            'DEPOSIT_COMPLETED',
            $this->config['currency'] ?? 'BWP'
        );
        
        // STEP 9: INVOICE FEES
        $this->settlement->invoiceFee(
            $this->currentSwapRef,
            $sourceInstitution,
            $this->getParticipantId($sourceInstitution),
            'VOUCHMORPH_FEE',
            $feeBreakdown['total_fee'] ?? 0,
            $this->config['currency'] ?? 'BWP'
        );
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'original_amount' => $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'fee_calculation_details' => $this->feeCalculationDetails,
            'deposit_reference' => $depositResult['transaction_reference'] ?? null,
            'destination_account' => $destinationIdentifier['identifier'],
            'settlement' => $settlementResult,
            'signature_chain' => $this->signedPayloads
        ];
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    private function verifyAccount(array $payload, string $institution, array $destinationIdentifier): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $verifyPayload = [
            'action' => 'VERIFY_ACCOUNT',
            'reference' => $this->currentSwapRef,
            'account_identifier' => $destinationIdentifier['identifier'],
            'identifier_type' => $destinationIdentifier['type'],
            'requester' => 'VOUCHMORPH',
            'timestamp' => time()
        ];
        
        $result = $bankClient->verifyAccount($verifyPayload);
        
        if (!$result['success']) {
            return ['verified' => false, 'message' => $result['curl_error'] ?? 'Account verification failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'verified' => $data['verified'] ?? false,
            'message' => $data['message'] ?? null,
            'account_name' => $data['account_name'] ?? null
        ];
    }

    private function generateCashoutToken(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        
        $tokenPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'hold_reference' => $this->currentHoldReference,
            'action' => 'GENERATE_TOKEN',
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null,
            'beneficiary_phone' => $beneficiaryPhone
        ];
        
        if (isset($payload['note_breakdown'])) {
            $tokenPayload['note_breakdown'] = $payload['note_breakdown'];
        }
        
        $result = $bankClient->generateToken($tokenPayload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Token generation failed'];
        }
        
        $data = $result['data'] ?? [];
        
        if (empty($data['atm_pin']) && empty($data['voucher_number'])) {
            return ['success' => false, 'message' => 'No code generated'];
        }
        
        return [
            'success' => true,
            'atm_pin' => $data['atm_pin'] ?? null,
            'voucher_number' => $data['voucher_number'] ?? null,
            'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'))
        ];
    }

    private function storePendingCashout(string $swapRef, string $holdRef, array $codeData, string $sourceInstitution, float $amount, array $feeBreakdown): void
    {
        $sql = "
            INSERT INTO pending_cashouts (
                swap_reference, hold_reference, source_institution, destination_institution,
                amount, fee, atm_pin, voucher_number, expires_at, status, created_at
            ) VALUES (
                :swap_ref, :hold_ref, :source_inst, :dest_inst,
                :amount, :fee, :atm_pin, :voucher_number, :expires_at, 'PENDING', NOW()
            )
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':swap_ref' => $swapRef,
                ':hold_ref' => $holdRef,
                ':source_inst' => $sourceInstitution,
                ':dest_inst' => $this->currentSwapRef,
                ':amount' => $amount,
                ':fee' => $feeBreakdown['total_fee'] ?? 0,
                ':atm_pin' => $codeData['atm_pin'] ?? null,
                ':voucher_number' => $codeData['voucher_number'] ?? null,
                ':expires_at' => $codeData['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'))
            ]);
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to store pending cashout: " . $e->getMessage());
        }
    }

    private function getPendingCashout(?string $swapRef, ?string $holdRef): ?array
    {
        $sql = "
            SELECT * FROM pending_cashouts 
            WHERE (swap_reference = :swap_ref OR hold_reference = :hold_ref)
            AND status = 'PENDING'
            AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':swap_ref' => $swapRef, ':hold_ref' => $holdRef]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['hold_id'] = $row['id'] ?? null;
            }
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to get pending cashout: " . $e->getMessage());
            return null;
        }
    }

    private function markCashoutCompleted(string $swapRef, string $holdRef): void
    {
        $sql = "UPDATE pending_cashouts SET status = 'COMPLETED', completed_at = NOW() WHERE swap_reference = :swap_ref OR hold_reference = :hold_ref";
        $this->swapDB->prepare($sql)->execute([':swap_ref' => $swapRef, ':hold_ref' => $holdRef]);
    }

    private function markCashoutExpired(string $swapRef, string $holdRef): void
    {
        $sql = "UPDATE pending_cashouts SET status = 'EXPIRED', expired_at = NOW() WHERE swap_reference = :swap_ref OR hold_reference = :hold_ref";
        $this->swapDB->prepare($sql)->execute([':swap_ref' => $swapRef, ':hold_ref' => $holdRef]);
    }

    private function getParticipantId(string $institution): int
    {
        foreach ($this->participants as $code => $participant) {
            if (strtoupper($code) === strtoupper($institution)) {
                return $participant['id'] ?? 0;
            }
        }
        return 0;
    }

    /**
     * Handle expired cashout token (called by cron job)
     */
    public function handleExpiredCashout(string $swapReference): array
    {
        error_log("[SwapService] ===== handleExpiredCashout START for {$swapReference} =====");
        
        $pendingCashout = $this->getPendingCashout($swapReference, null);
        
        if (!$pendingCashout) {
            throw new RuntimeException("No pending cashout found for reference: {$swapReference}");
        }
        
        if ($pendingCashout['expires_at'] > date('Y-m-d H:i:s')) {
            throw new RuntimeException("Cashout has not expired yet");
        }
        
        // Calculate expired settlement using fee engine
        $expiredResult = $this->feeEngine->calculateExpiredSettlement();
        
        // Release hold (no debit, client gets partial refund)
        $this->updateHoldStatus($pendingCashout['hold_id'], 'RELEASED');
        
        // Mark cashout as expired
        $this->markCashoutExpired($swapReference, $pendingCashout['hold_reference']);
        
        // Record settlement
        $this->settlement->updateNetPosition(
            $swapReference,
            $pendingCashout['source_institution'],
            'VOUCHMORPH',
            $expiredResult['retained_fee'],
            'EXPIRED_CASHOUT_PENALTY',
            $this->config['currency'] ?? 'BWP'
        );
        
        // Record proof
        $this->feeEngine->recordSettlementProof([
            'swap_reference' => $swapReference,
            'expired_result' => $expiredResult,
            'client_refund' => $expiredResult['client_refund'],
            'retained_fee' => $expiredResult['retained_fee']
        ]);
        
        return [
            'status' => 'expired',
            'reference' => $swapReference,
            'client_refund' => $expiredResult['client_refund'],
            'retained_fee' => $expiredResult['retained_fee'],
            'platform_revenue' => $expiredResult['platform_revenue'],
            'source_revenue' => $expiredResult['source_revenue'],
            'destination_revenue' => $expiredResult['destination_revenue']
        ];
    }

    // ============================================================
    // SIGNED INSTITUTION COMMUNICATION METHODS
    // ============================================================

    private function verifyAssetSigned(array $payload, string $institution): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $timestamp = time();
        $sourceId = $this->extractSourceIdentifier($payload);
        
        $verifyPayload = [
            'action' => 'VERIFY_ASSET',
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? $this->config['currency'] ?? 'BWP',
            'institution' => $institution,
            'timestamp' => $timestamp,
            'swap_type' => $payload['swap_type'] ?? 'STANDARD',
            'requester' => 'VOUCHMORPH'
        ];
        
        if ($sourceId['has_value']) {
            $verifyPayload['source_identifier'] = $sourceId['identifier'];
            $verifyPayload['source_identifier_type'] = $sourceId['type'];
        }
        
        $result = $bankClient->verifyAssetSigned($verifyPayload);
        
        if (!$result['success']) {
            return ['verified' => false, 'message' => 'Unable to reach source institution'];
        }
        
        $data = $result['data'] ?? [];
        $verified = $data['verified'] ?? false;
        
        if ($verified !== true) {
            return ['verified' => false, 'message' => $data['message'] ?? 'Asset not available'];
        }
        
        return [
            'verified' => true,
            'message' => $data['message'] ?? 'Asset verified',
            'asset_id' => $data['asset_id'] ?? null,
            'original_payload' => $data['payload'] ?? null,
            'signature' => $data['signature'] ?? null,
            'certificate' => $data['certificate'] ?? null,
            'timestamp' => $data['timestamp'] ?? $timestamp
        ];
    }

    private function placeHoldSigned(array $payload, string $institution, array $verificationResult): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $timestamp = time();
        $sourceId = $this->extractSourceIdentifier($payload);
        
        $holdPayload = [
            'action' => 'PLACE_HOLD',
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? $this->config['currency'] ?? 'BWP',
            'hold_reason' => $payload['hold_reason'] ?? 'PENDING_SWAP',
            'destination_institution' => $payload['to_institution'] ?? $payload['destination_institution'] ?? null,
            'expiry' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'timestamp' => $timestamp
        ];
        
        if ($sourceId['has_value']) {
            $holdPayload['source_identifier'] = $sourceId['identifier'];
            $holdPayload['source_identifier_type'] = $sourceId['type'];
        }
        
        if (isset($verificationResult['asset_id'])) {
            $holdPayload['asset_id'] = $verificationResult['asset_id'];
        }
        
        $result = $bankClient->placeHoldSigned($holdPayload);
        
        if (!$result['success']) {
            return ['hold_placed' => false, 'message' => $result['curl_error'] ?? 'Hold request failed'];
        }
        
        $data = $result['data'] ?? [];
        
        $holdId = $this->createLocalHold($payload, $institution, $data['hold_reference'] ?? null);
        $this->currentHoldId = $holdId;
        
        return [
            'hold_placed' => true,
            'hold_reference' => $data['hold_reference'] ?? null,
            'local_hold_id' => $holdId,
            'message' => $data['message'] ?? 'Hold placed successfully',
            'original_payload' => $data['payload'] ?? null,
            'signature' => $data['signature'] ?? null,
            'certificate' => $data['certificate'] ?? null,
            'timestamp' => $data['timestamp'] ?? $timestamp
        ];
    }

    private function processDepositWithProof(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $destId = $this->extractDestinationIdentifier($payload);
        
        $depositPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'source_details' => $payload['source_details'] ?? [],
            'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null
        ];
        
        if ($destId['has_value']) {
            $depositPayload['destination_identifier'] = $destId['identifier'];
            $depositPayload['destination_identifier_type'] = $destId['type'];
        }
        
        $result = $bankClient->processDepositWithProof($depositPayload);
        
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

    private function debitSource(array $payload, string $institution): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $debitPayload = [
            'reference' => $payload['reference'] ?? $this->currentSwapRef,
            'hold_reference' => $payload['hold_reference'] ?? $this->currentHoldReference,
            'amount' => $payload['amount'] ?? 0,
            'reason' => $payload['reason'] ?? 'Swap completed successfully'
        ];
        
        $result = $bankClient->debitFunds($debitPayload);
        
        if (!$result['success']) {
            return ['debited' => false, 'message' => $result['curl_error'] ?? 'Debit failed'];
        }
        
        return [
            'debited' => true,
            'transaction_reference' => $result['data']['transaction_reference'] ?? null,
            'message' => $result['data']['message'] ?? 'Debit successful'
        ];
    }

    private function executeSignedStandardSwap(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $destInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        
        $verificationResult = $this->verifyAssetSigned($payload, $sourceInstitution);
        if (!($verificationResult['verified'] ?? false)) {
            throw new RuntimeException("Asset verification failed");
        }
        
        $holdResult = $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        if (!($holdResult['hold_placed'] ?? false)) {
            throw new RuntimeException("Hold failed");
        }
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? null;
        
        $feeBreakdown = $this->calculateFeesWithDetails('SWAP', $amount, $payload);
        $netAmount = $this->currentTransactionState['target_amount'] ?? ($amount - ($feeBreakdown['total_fee'] ?? 0));
        
        $destinationResult = $this->processDestinationWithProof($payload, $destInstitution, $netAmount);
        if (!($destinationResult['success'] ?? false)) {
            throw new RuntimeException("Destination processing failed");
        }
        
        $debitResult = $this->debitSource($payload, $sourceInstitution);
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed");
        }
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'fee' => $feeBreakdown['total_fee'] ?? 0
        ];
    }

    private function processDestinationWithProof(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $destId = $this->extractDestinationIdentifier($payload);
        
        $transferPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_type' => $payload['destination_type'] ?? 'ACCOUNT',
            'destination_identifier' => $payload['destination_identifier'] ?? null,
            'action' => 'PROCESS_TRANSFER_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null
        ];
        
        if ($destId['has_value']) {
            $transferPayload['destination_identifier'] = $destId['identifier'];
            $transferPayload['destination_identifier_type'] = $destId['type'];
        }
        
        $result = $bankClient->transferWithProof($transferPayload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Processing failed'];
        }
        
        return ['success' => true];
    }

    private function executeMultiSourceSwap(array $payload): array
    {
        if (!$this->multiSourceExecutor) {
            throw new RuntimeException("Multi-source swap executor not initialized");
        }
        return $this->multiSourceExecutor->execute($payload);
    }

    private function executeCardIssuance(array $payload): array
    {
        if (!$this->cardService) {
            throw new RuntimeException("Card service not initialized");
        }
        return $this->cardService->issueCard($payload);
    }

    // ============================================================
    // LOCAL DATABASE OPERATIONS
    // ============================================================

    private function createLocalHold(array $payload, string $institution, ?string $externalHoldRef): int
    {
        $sourceId = $this->extractSourceIdentifier($payload);
        
        $sourceDetails = [
            'source_identifier' => $sourceId['identifier'],
            'source_identifier_type' => $sourceId['type'],
            'source_institution' => $institution,
            'asset_type' => $payload['asset_type'] ?? 'ACCOUNT',
            'original_payload' => $payload
        ];
        
        $metadata = [
            'swap_reference' => $this->currentSwapRef,
            'external_hold_reference' => $externalHoldRef,
            'signature_chain' => $this->signedPayloads
        ];
        
        $sql = "
            INSERT INTO hold_transactions (
                hold_reference, swap_reference, participant_name, asset_type,
                amount, currency, status, source_details, destination_institution,
                metadata, placed_at, created_at, updated_at, source_institution
            ) VALUES (
                :hold_ref, :swap_ref, :participant_name, :asset_type,
                :amount, :currency, 'ACTIVE', :source_details::jsonb, :destination,
                :metadata::jsonb, NOW(), NOW(), NOW(), :source_institution
            ) RETURNING hold_id
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':hold_ref' => 'HOLD_' . $this->currentSwapRef,
                ':swap_ref' => $this->currentSwapRef,
                ':participant_name' => $institution,
                ':asset_type' => $payload['asset_type'] ?? 'ACCOUNT',
                ':amount' => $payload['amount'] ?? 0,
                ':currency' => $payload['currency'] ?? 'BWP',
                ':source_details' => json_encode($sourceDetails),
                ':destination' => $payload['to_institution'] ?? $payload['destination_institution'] ?? null,
                ':metadata' => json_encode($metadata),
                ':source_institution' => $institution
            ]);
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['hold_id'] : 0;
            
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to create local hold: " . $e->getMessage());
            throw new RuntimeException("Failed to create local hold: " . $e->getMessage());
        }
    }

    private function updateHoldStatus(?int $holdId, string $status): void
    {
        if ($holdId === null) return;
        
        $validStatuses = ['ACTIVE', 'HELD', 'PENDING_CASHOUT', 'DEBITED', 'RELEASED', 'CANCELLED', 'FAILED'];
        if (!in_array($status, $validStatuses)) return;
        
        $sql = "
            UPDATE hold_transactions 
            SET status = :status,
                debited_at = CASE WHEN :status = 'DEBITED' THEN NOW() ELSE debited_at END,
                released_at = CASE WHEN :status = 'RELEASED' THEN NOW() ELSE released_at END,
                updated_at = NOW()
            WHERE hold_id = :hold_id
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':status' => $status, ':hold_id' => $holdId]);
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to update hold status: " . $e->getMessage());
        }
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
        
        $this->logger->info("Atomic swap committed", $result);
        $this->resetAtomicState();
        return $result;
    }

    private function rollbackAtomicSwap(string $reason): array
    {
        if ($this->currentHoldId) {
            $this->updateHoldStatus($this->currentHoldId, 'RELEASED');
        }
        
        $this->swapDB->rollBack();
        
        $result = [
            'status' => 'rolled_back',
            'reference' => $this->currentSwapRef,
            'reason' => $reason
        ];
        
        $this->logger->warning("Atomic swap rolled back", $result);
        $this->resetAtomicState();
        return $result;
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
        $this->executedSteps[] = ['step' => $stepName, 'timestamp' => microtime(true)];
        return $operation();
    }

    private function getLastStep(): string
    {
        if (empty($this->executedSteps)) return 'none';
        $last = end($this->executedSteps);
        return $last['step'];
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    private function generateReference(): string
    {
        return 'SWAP_' . time() . '_' . bin2hex(random_bytes(8));
    }

    private function checkIdempotency(string $key): ?array
    {
        try {
            $sql = "SELECT result FROM idempotency_keys WHERE key = :key AND created_at > NOW() - INTERVAL '24 hours'";
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? json_decode($row['result'], true) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function storeIdempotencyResult(string $key, array $result): void
    {
        try {
            $sql = "INSERT INTO idempotency_keys (key, operation, result, created_at) VALUES (:key, 'swap', :result::jsonb, NOW()) ON CONFLICT (key) DO UPDATE SET result = EXCLUDED.result, created_at = NOW()";
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':key' => $key, ':result' => json_encode($result)]);
        } catch (Exception $e) {
            // Ignore idempotency storage errors
        }
    }

    private function loadConfiguration(string $country): void
    {
        $countryPath = __DIR__ . '/../../Core/Config/Countries/' . $country;
        
        $participantsPath = $countryPath . '/participants.yaml';
        if (file_exists($participantsPath)) {
            $this->participants = $this->parseYaml($participantsPath);
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
        $lines = explode("\n", $content);
        $participantsData = [];
        $currentKey = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
                $currentKey = $matches[1];
                $participantsData[$currentKey] = [];
                continue;
            }
            
            if ($currentKey && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $value = trim($matches[2], '"\'');
                $participantsData[$currentKey][$matches[1]] = $value;
            }
        }
        
        return $participantsData;
    }

    // ============================================================
    // PUBLIC METHODS
    // ============================================================

    public function getParticipant(string $institution): array
    {
        if (isset($this->participants[$institution])) {
            return $this->participants[$institution];
        }
        
        foreach ($this->participants as $code => $participant) {
            if (strtolower($code) === strtolower($institution)) {
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
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getAtmDenominations(string $currency): array
    {
        return $this->atmNotes[$currency] ?? [200, 100, 50, 20, 10];
    }

    public function calculateNoteBreakdown(float $amount, string $currency): array
    {
        return $this->validateCashoutAmount($amount, $currency);
    }
}
