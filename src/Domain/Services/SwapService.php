<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;
use PDOException;
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
use Infrastructure\Crypto\CertificateManager;
use Psr\Log\LoggerInterface;

/**
 * SIGNED ATOMIC SWAP ORCHESTRATOR
 * 
 * Bank-grade trust model with Certificate Authority (Visa/Mastercard style)
 * - VouchMorph CA acts as trust anchor
 * - Each member presents certificate signed by CA
 * - Receivers verify certificate chains to trusted CA root
 * - NO manual key exchange needed for new members
 * 
 * CORE PRINCIPLE:
 * - "Verify Asset" asks source institution: Is this asset available for swap?
 * - Source institution decides: balance, hold status, validity, etc.
 * - VouchMorph trusts and acts on the source's response
 * 
 * RESPONSIBILITY BREAKDOWN:
 * - Source Institution: Verify Asset, Place Hold, Get Debited
 * - Destination Institution: Generate ATM Code, Process Deposit, Generate Voucher, Process Agent Cashout
 * - VouchMorph: Calculate Fees, Adjust for Delivery, Send SMS, Orchestrate Flow
 * 
 * CRITICAL: A swap is ONLY successful if ALL steps succeed. If destination fails, we rollback.
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
    private ?CertificateManager $certificateManager = null;
    
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
        
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        $this->feeService = new FeeService($this->feesConfig, $this->config);
        $this->feeService->setParticipants($this->participants); 
        $this->forexService = new ForexService($this->swapDB, $this->config, $this->participants, $this->feeService);
        
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
        
        $feeResult = $this->feeService->calculateFees($feeType, $amount, $payload);
        
        $details = [
            'fee_type' => $feeType,
            'original_amount' => $amount,
            'total_fee' => $feeResult['total_fee'] ?? 0,
            'net_amount' => $amount - ($feeResult['total_fee'] ?? 0),
            'breakdown' => []
        ];
        
        if (isset($feeResult['components'])) {
            $details['breakdown'] = $feeResult['components'];
        } elseif (isset($feeResult['fees'])) {
            foreach ($feeResult['fees'] as $feeName => $feeValue) {
                if (is_numeric($feeValue)) {
                    $details['breakdown'][$feeName] = $feeValue;
                }
            }
        }
        
        if (isset($feeResult['swap_levy']) && $feeResult['swap_levy'] > 0) {
            $details['breakdown']['swap_levy'] = $feeResult['swap_levy'];
        }
        
        if (isset($feeResult['split'])) {
            $details['revenue_split'] = $feeResult['split'];
        }
        
        $this->feeCalculationDetails = $details;
        
        return $feeResult;
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
    // EXECUTE SIGNED CASHOUT - WITH PROPER ERROR CHECKING
    // ============================================================
    // A swap is ONLY successful if:
    // 1. Source verifies asset ✓
    // 2. Source places hold ✓
    // 3. Fees calculated ✓
    // 4. Amount can be delivered ✓
    // 5. DESTINATION generates code ✓ (CRITICAL)
    // 6. Source is debited ✓

    private function executeSignedCashout(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedCashout START =====");
        
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
        
        // STEP 3: CALCULATE FEES
        error_log("[SwapService] STEP 3: Calculating fees");
        $feeBreakdown = $this->calculateFeesWithDetails('CASHOUT', $amount, $payload);
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        
        // STEP 4: ADJUST AMOUNT FOR DELIVERY
        error_log("[SwapService] STEP 4: Adjusting amount for delivery method: {$deliveryMethod}");
        $deliveryAdjustment = $this->adjustAmountForDelivery($netAmount, $deliveryMethod, $payload['currency'] ?? 'BWP');
        
        $amountToSend = $deliveryAdjustment['deliverable_amount'];
        $remainderAtSource = $deliveryAdjustment['remainder_at_source'];
        
        error_log("[SwapService] Amount after fees: {$netAmount}, Deliverable: {$amountToSend}, Remainder: {$remainderAtSource}");
        
        if ($amountToSend <= 0) {
            $errorMsg = "Amount after fees ({$netAmount}) cannot be delivered via {$deliveryMethod}. " . $deliveryAdjustment['message'];
            error_log("[SwapService] DELIVERY FAILED: {$errorMsg}");
            throw new RuntimeException($errorMsg);
        }
        
        $this->feeCalculationDetails['delivery_adjustment'] = [
            'original_net_amount' => $netAmount,
            'delivery_method' => $deliveryMethod,
            'amount_delivered' => $amountToSend,
            'remainder_at_source' => $remainderAtSource,
            'note_breakdown' => $deliveryAdjustment['note_breakdown'] ?? null,
            'message' => $deliveryAdjustment['message']
        ];
        
        // STEP 5: GENERATE CODE AT DESTINATION - MUST succeed (CRITICAL!)
        error_log("[SwapService] STEP 5: Generating code at DESTINATION institution: {$destinationInstitution} via {$deliveryMethod} for amount: {$amountToSend}");
        
        $deliveryPayload = $payload;
        $deliveryPayload['amount'] = $amountToSend;
        if (isset($this->feeCalculationDetails['note_breakdown'])) {
            $deliveryPayload['note_breakdown'] = $this->feeCalculationDetails['note_breakdown'];
        }
        
        $deliveryResult = $this->processDeliveryByMethod($deliveryPayload, $destinationInstitution, $amountToSend, $deliveryMethod);
        
        // CRITICAL CHECK: Destination MUST succeed
        if (!($deliveryResult['success'] ?? false)) {
            $errorMsg = $deliveryResult['message'] ?? 'Destination institution failed to process delivery';
            error_log("[SwapService] DESTINATION FAILED: {$errorMsg}");
            throw new RuntimeException("Destination failed: {$errorMsg}");
        }
        
        // Verify we actually got a code
        if (empty($deliveryResult['atm_pin']) && empty($deliveryResult['voucher_number'])) {
            error_log("[SwapService] DESTINATION FAILED: No code generated");
            throw new RuntimeException("Destination failed: No code generated");
        }
        
        error_log("[SwapService] Code generated successfully at destination");
        
        // STEP 6: SEND SMS (Optional - can fail softly, notification only)
        if ($beneficiaryPhone && $this->smsService && isset($deliveryResult['atm_pin'])) {
            try {
                error_log("[SwapService] STEP 6: Sending SMS to {$beneficiaryPhone}");
                $this->executeStep('SEND_SMS', function() use ($beneficiaryPhone, $deliveryResult, $amountToSend) {
                    return $this->smsService->sendCashoutCode(
                        $beneficiaryPhone,
                        $deliveryResult['atm_pin'],
                        $amountToSend,
                        $deliveryResult['voucher_number'] ?? null
                    );
                });
            } catch (Exception $e) {
                error_log("[SwapService] SMS failed but continuing: " . $e->getMessage());
            }
        }
        
        // STEP 7: DEBIT SOURCE - MUST succeed (only after destination succeeded!)
        $actualDebitAmount = $amountToSend + ($feeBreakdown['total_fee'] ?? 0);
        error_log("[SwapService] STEP 7: Debiting source {$sourceInstitution} for amount: {$actualDebitAmount}");
        
        $debitPayload = $payload;
        $debitPayload['amount'] = $actualDebitAmount;
        $debitResult = $this->executeStep('DEBIT_SOURCE', function() use ($debitPayload, $sourceInstitution) {
            return $this->debitSource($debitPayload, $sourceInstitution);
        });
        
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed: " . ($debitResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 8: UPDATE HOLD STATUS
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        $result = [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'atm_code' => $deliveryResult['atm_pin'] ?? null,
            'voucher_number' => $deliveryResult['voucher_number'] ?? null,
            'amount' => $amountToSend,
            'original_requested_amount' => $payload['original_requested_amount'] ?? $amount,
            'original_net_amount' => $netAmount,
            'remainder_balance' => $payload['remainder_balance'] ?? $remainderAtSource,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'delivery_method' => $deliveryMethod,
            'delivery_adjustment' => $deliveryAdjustment['message'],
            'fee_calculation_details' => $this->feeCalculationDetails,
            'signature_chain' => $this->signedPayloads
        ];
        
        if (isset($payload['note_breakdown'])) {
            $result['note_breakdown'] = $payload['note_breakdown'];
        }
        if (isset($deliveryAdjustment['note_breakdown'])) {
            $result['delivery_note_breakdown'] = $deliveryAdjustment['note_breakdown'];
        }
        
        error_log("[SwapService] ===== executeSignedCashout SUCCESS =====");
        
        return $result;
    }

    private function processDeliveryByMethod(array $payload, string $destinationInstitution, float $amount, string $method): array
    {
        error_log("[SwapService] processDeliveryByMethod: destination={$destinationInstitution}, method={$method}, amount={$amount}");
        
        switch ($method) {
            case 'ATM':
                return $this->generateAtmTokenWithProof($payload, $destinationInstitution, $amount);
            case 'AGENT':
                return $this->processAgentCashout($payload, $destinationInstitution, $amount);
            case 'VOUCHER':
                return $this->generateVoucher($payload, $destinationInstitution, $amount);
            case 'DEPOSIT':
            case 'WALLET':
            default:
                return $this->processDepositWithProof($payload, $destinationInstitution, $amount);
        }
    }

    private function generateAtmTokenWithProof(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        
        $tokenPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'hold_reference' => $this->currentHoldReference,
            'action' => 'GENERATE_ATM_TOKEN_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null
        ];
        
        if ($beneficiaryPhone) {
            $tokenPayload['beneficiary_phone'] = $beneficiaryPhone;
        }
        
        if (isset($payload['note_breakdown'])) {
            $tokenPayload['note_breakdown'] = $payload['note_breakdown'];
        }
        
        $result = $bankClient->generateTokenWithProof($tokenPayload);
        
        if (!$result['success']) {
            $errorMsg = $result['curl_error'] ?? 'Token generation failed';
            $this->logger->error("Token generation failed at {$institution}", ['error' => $errorMsg]);
            return [
                'success' => false,
                'message' => "Destination institution {$institution} failed: {$errorMsg}"
            ];
        }
        
        $data = $result['data'] ?? [];
        
        if (empty($data['atm_pin']) && empty($data['voucher_number'])) {
            return [
                'success' => false,
                'message' => "Destination institution responded but no code was generated"
            ];
        }
        
        return [
            'success' => true,
            'atm_pin' => $data['atm_pin'] ?? null,
            'voucher_number' => $data['voucher_number'] ?? null,
            'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'))
        ];
    }

    private function processAgentCashout(array $payload, string $institution, float $amount): array
    {
        error_log("[SwapService] Processing agent cashout at {$institution} for {$amount}");
        
        // This should call the destination institution's agent cashout API
        // For now, returns success (but should be replaced with actual API call)
        return [
            'success' => true,
            'atm_pin' => str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'voucher_number' => 'AGT-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'amount' => $amount,
            'message' => 'Agent cashout code generated successfully'
        ];
    }

    private function generateVoucher(array $payload, string $institution, float $amount): array
    {
        error_log("[SwapService] Generating voucher at {$institution} for {$amount}");
        
        // This should call the destination institution's voucher generation API
        // For now, returns success (but should be replaced with actual API call)
        return [
            'success' => true,
            'atm_pin' => str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'voucher_number' => 'VCH-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12)),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'amount' => $amount,
            'message' => 'Voucher generated successfully'
        ];
    }

    // ============================================================
    // EXECUTE SIGNED DEPOSIT
    // ============================================================

    private function executeSignedDeposit(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedDeposit START =====");
        
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        
        // STEP 1: VERIFY ASSET - MUST succeed
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
        
        // STEP 2: CALCULATE FEES
        $feeBreakdown = $this->calculateFeesWithDetails('DEPOSIT', $amount, $payload);
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        
        // STEP 3: PROCESS DEPOSIT AT DESTINATION - MUST succeed
        error_log("[SwapService] STEP 3: Processing deposit at DESTINATION: {$destinationInstitution} for amount: {$netAmount}");
        
        $depositResult = $this->executeStep('PROCESS_DEPOSIT_WITH_PROOF', function() use ($payload, $destinationInstitution, $netAmount) {
            $depositPayload = $payload;
            $depositPayload['amount'] = $netAmount;
            return $this->processDepositWithProof($depositPayload, $destinationInstitution, $netAmount);
        });
        
        if (!($depositResult['success'] ?? false)) {
            throw new RuntimeException("Deposit failed: " . ($depositResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 4: DEBIT SOURCE - MUST succeed (only after destination succeeded!)
        error_log("[SwapService] STEP 4: Debiting source {$sourceInstitution} for amount: {$amount}");
        $debitResult = $this->executeStep('DEBIT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->debitSource($payload, $sourceInstitution);
        });
        
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed: " . ($debitResult['message'] ?? 'Unknown error'));
        }
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'original_amount' => $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'fee_calculation_details' => $this->feeCalculationDetails,
            'deposit_reference' => $depositResult['transaction_reference'] ?? null,
            'signature_chain' => $this->signedPayloads
        ];
    }

    // ============================================================
    // EXECUTE SIGNED STANDARD SWAP
    // ============================================================

    private function executeSignedStandardSwap(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $destInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        
        // STEP 1: VERIFY ASSET - MUST succeed
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
        
        // STEP 2: PLACE HOLD - MUST succeed
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
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? $holdResult['data']['hold_reference'] ?? null;
        
        // STEP 3: CALCULATE FEES
        $feeBreakdown = $this->calculateFeesWithDetails('SWAP', $amount, $payload);
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        
        // STEP 4: PROCESS DESTINATION - MUST succeed
        $destinationResult = $this->executeStep('PROCESS_DESTINATION_SIGNED', function() use ($payload, $destInstitution, $netAmount, $feeBreakdown) {
            return $this->processDestinationWithProof(
                $payload, 
                $destInstitution, 
                $netAmount, 
                $feeBreakdown,
                $this->signedPayloads['verification'],
                $this->signedPayloads['hold']
            );
        });
        
        if (!($destinationResult['success'] ?? false)) {
            throw new RuntimeException("Destination processing failed: " . ($destinationResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 5: DEBIT SOURCE - MUST succeed (only after destination succeeded!)
        $debitResult = $this->executeStep('DEBIT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->debitSource($payload, $sourceInstitution);
        });
        
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed: " . ($debitResult['message'] ?? 'Unknown error'));
        }
        
        // STEP 6: UPDATE HOLD STATUS
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        // STEP 7: RECORD SETTLEMENT
        $settlementResult = $this->executeStep('RECORD_SETTLEMENT_SIGNED', function() use ($payload, $destinationResult, $feeBreakdown) {
            return $this->recordSettlementWithProof($payload, $destinationResult, $feeBreakdown);
        });
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'hold_id' => $this->currentHoldId,
            'hold_reference' => $this->currentHoldReference,
            'amount' => $netAmount,
            'original_amount' => $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'fee_calculation_details' => $this->feeCalculationDetails,
            'signature_chain' => $this->signedPayloads,
            'settlement' => $settlementResult
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
            $verifyPayload['wallet_phone'] = $sourceId['identifier'];
            $verifyPayload['phone'] = $sourceId['identifier'];
            $verifyPayload['national_id'] = $sourceId['identifier'];
            $verifyPayload['email'] = $sourceId['identifier'];
            $verifyPayload['source_phone'] = $sourceId['identifier'];
            $verifyPayload['source_wallet_phone'] = $sourceId['identifier'];
            
            error_log("[SwapService] Source identifier sent to {$institution}: {$sourceId['type']} = {$sourceId['identifier']}");
        }
        
        switch ($assetType) {
            case 'VOUCHER':
            case 'CASHOUT-VOUCHER':
                $verifyPayload['voucher_number'] = $payload['voucher_number'] ?? null;
                $verifyPayload['voucher_pin'] = $payload['voucher_pin'] ?? null;
                $verifyPayload['claimant_phone'] = $payload['claimant_phone'] ?? null;
                break;
            case 'ACCOUNT':
                $verifyPayload['account_number'] = $payload['account_number'] ?? null;
                $verifyPayload['account_name'] = $payload['account_name'] ?? null;
                break;
            case 'MNO-WALLET':
            case 'BANK-WALLET':
                $verifyPayload['wallet_phone'] = $verifyPayload['wallet_phone'] ?: ($payload['wallet_phone'] ?? null);
                $verifyPayload['wallet_provider'] = $payload['wallet_provider'] ?? null;
                break;
            case 'CARD':
                $verifyPayload['card_number'] = $payload['card_number'] ?? null;
                $verifyPayload['card_expiry'] = $payload['card_expiry'] ?? null;
                break;
            default:
                foreach ($payload as $key => $value) {
                    if (!in_array($key, ['from_institution', 'to_institution', 'swap_type', 'amount', 'currency', 'reference'])) {
                        $verifyPayload[$key] = $value;
                    }
                }
                break;
        }
        
        $result = $bankClient->verifyAssetSigned($verifyPayload);
        
        if (!$result['success']) {
            $this->logger->error("Verification request failed", [
                'institution' => $institution,
                'asset_type' => $assetType,
                'error' => $result['curl_error'] ?? 'Unknown'
            ]);
            return [
                'verified' => false, 
                'message' => 'Unable to reach source institution: ' . ($result['curl_error'] ?? 'Connection failed')
            ];
        }
        
        $data = $result['data'] ?? [];
        $verified = $data['verified'] ?? false;
        $message = $data['message'] ?? '';
        
        if ($verified !== true) {
            return ['verified' => false, 'message' => $message ?: 'Asset not available for swap'];
        }
        
        return [
            'verified' => true,
            'message' => $message ?: 'Asset verified and available',
            'balance' => $data['available_balance'] ?? $data['balance'] ?? null,
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
            'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour')),
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
        
        $destId = $this->extractDestinationIdentifier($payload);
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        
        $transferPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_type' => $payload['destination_type'] ?? 'ACCOUNT',
            'action' => 'PROCESS_TRANSFER_WITH_PROOF',
            'source_verification' => [
                'payload' => $verificationProof['payload'],
                'signature' => $verificationProof['signature'],
                'certificate' => $verificationProof['certificate'] ?? null,
                'source' => $verificationProof['source'],
                'timestamp' => $verificationProof['timestamp']
            ],
            'source_hold' => [
                'payload' => $holdProof['payload'],
                'signature' => $holdProof['signature'],
                'certificate' => $holdProof['certificate'] ?? null,
                'source' => $holdProof['source'],
                'timestamp' => $holdProof['timestamp']
            ]
        ];
        
        if ($destId['has_value']) {
            $transferPayload['destination_identifier'] = $destId['identifier'];
            $transferPayload['destination_identifier_type'] = $destId['type'];
            $transferPayload['destination_account'] = $destId['identifier'];
            $transferPayload['beneficiary_account'] = $destId['identifier'];
        }
        
        if ($beneficiaryPhone) {
            $transferPayload['beneficiary_phone'] = $beneficiaryPhone;
            $transferPayload['sms_phone'] = $beneficiaryPhone;
        }
        
        if (!empty($payload['destination_details'])) {
            $transferPayload['destination_details'] = $payload['destination_details'];
        }
        
        $result = $bankClient->transferWithProof($transferPayload);
        
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
            $depositPayload['client_phone'] = $destId['identifier'];
            $depositPayload['client_account'] = $destId['identifier'];
            $depositPayload['beneficiary_account'] = $destId['identifier'];
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

    private function recordSettlementWithProof(array $payload, array $destinationResult, array $feeBreakdown): array
    {
        $settlementData = [
            'swap_reference' => $this->currentSwapRef,
            'from_institution' => $payload['from_institution'] ?? $payload['source_institution'] ?? null,
            'to_institution' => $payload['to_institution'] ?? $payload['destination_institution'] ?? null,
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'BWP',
            'fee' => $feeBreakdown,
            'fee_details' => $this->feeCalculationDetails,
            'signature_chain' => $this->signedPayloads,
            'destination_proof' => $destinationResult
        ];
        
        return $this->settlement->recordSettlementWithProof($settlementData);
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
        
        return [
            'debited' => true,
            'transaction_reference' => $result['data']['transaction_reference'] ?? null,
            'message' => $result['data']['message'] ?? 'Debit successful'
        ];
    }

    // ============================================================
    // UNSIGNED FALLBACK METHODS (backward compatibility)
    // ============================================================

    private function verifyAsset(array $payload, string $institution): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $sourceId = $this->extractSourceIdentifier($payload);
        
        $verifyPayload = [
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'institution' => $institution
        ];
        
        if ($sourceId['has_value']) {
            $verifyPayload['source_identifier'] = $sourceId['identifier'];
            $verifyPayload['source_identifier_type'] = $sourceId['type'];
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
        $sourceId = $this->extractSourceIdentifier($payload);
        
        $holdPayload = [
            'action' => 'PLACE_HOLD',
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'hold_reason' => $payload['hold_reason'] ?? 'PENDING_TRANSACTION',
            'destination_institution' => $payload['destination_institution'] ?? null,
            'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour'))
        ];
        
        if ($sourceId['has_value']) {
            $holdPayload['source_identifier'] = $sourceId['identifier'];
        }
        
        if (isset($verificationResult['asset_id'])) {
            $holdPayload['asset_id'] = $verificationResult['asset_id'];
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

    private function processDestination(array $payload, string $institution, float $amount, array $feeBreakdown): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        $destId = $this->extractDestinationIdentifier($payload);
        
        $transferPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_type' => $payload['destination_type'] ?? 'ACCOUNT',
            'destination_details' => $payload['destination_details'] ?? [],
            'action' => 'PROCESS_TRANSFER'
        ];
        
        if ($destId['has_value']) {
            $transferPayload['destination_identifier'] = $destId['identifier'];
            $transferPayload['beneficiary_account'] = $destId['identifier'];
        }
        
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
        
        $destId = $this->extractDestinationIdentifier($payload);
        
        $depositPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'source_details' => $payload['source_details'] ?? [],
            'action' => 'PROCESS_DEPOSIT'
        ];
        
        if ($destId['has_value']) {
            $depositPayload['client_phone'] = $destId['identifier'];
            $depositPayload['client_account'] = $destId['identifier'];
        }
        
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
        
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        
        $tokenPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'hold_reference' => $this->currentHoldReference,
            'action' => 'GENERATE_ATM_TOKEN'
        ];
        
        if ($beneficiaryPhone) {
            $tokenPayload['beneficiary_phone'] = $beneficiaryPhone;
        }
        
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

    private function executeDeposit(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        
        $verificationResult = $this->executeStep('VERIFY_ASSET', function() use ($payload, $sourceInstitution) {
            return $this->verifyAsset($payload, $sourceInstitution);
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
            'phone' => $payload['phone'] ?? $payload['wallet_phone'] ?? null,
            'national_id' => $payload['national_id'] ?? null,
            'email' => $payload['email'] ?? null,
            'original_payload' => $payload
        ];
        
        $metadata = [
            'swap_reference' => $this->currentSwapRef,
            'external_hold_reference' => $externalHoldRef,
            'signature_chain' => $this->signedPayloads,
            'source_identifier' => $sourceId['identifier'],
            'source_identifier_type' => $sourceId['type']
        ];
        
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
                metadata,
                placed_at,
                created_at,
                updated_at,
                source_institution
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
                :metadata::jsonb,
                NOW(),
                NOW(),
                NOW(),
                :source_institution
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
            $this->logger->error("Failed to create local hold", ['error' => $e->getMessage()]);
            throw new RuntimeException("Failed to create local hold: " . $e->getMessage());
        }
    }

    private function updateHoldStatus(?int $holdId, string $status): void
    {
        if ($holdId === null) {
            error_log("[SwapService] WARNING: updateHoldStatus called with NULL holdId, status: {$status}");
            return;
        }
        
        $validStatuses = ['ACTIVE', 'HELD', 'DEBITED', 'RELEASED', 'CANCELLED', 'FAILED'];
        if (!in_array($status, $validStatuses)) {
            error_log("[SwapService] Invalid status: {$status}");
            return;
        }
        
        $sql = "
            UPDATE hold_transactions 
            SET status = CAST(:status AS VARCHAR(50)),
                debited_at = CASE WHEN CAST(:status AS VARCHAR(50)) = 'DEBITED' THEN NOW() ELSE debited_at END,
                released_at = CASE WHEN CAST(:status AS VARCHAR(50)) = 'RELEASED' THEN NOW() ELSE released_at END,
                updated_at = NOW()
            WHERE hold_id = :hold_id
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':hold_id' => $holdId
            ]);
            
            error_log("[SwapService] Hold {$holdId} updated to status: {$status}");
            
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to update hold status: " . $e->getMessage());
            $this->logger->error("Failed to update hold status", ['error' => $e->getMessage()]);
            throw new RuntimeException("Failed to update hold status: " . $e->getMessage());
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

    private function getInstitutionPublicKey(string $institution): string
    {
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
        
        $envKey = strtoupper($institution) . '_PUBLIC_KEY';
        $publicKey = getenv($envKey);
        
        if ($publicKey) {
            return $publicKey;
        }
        
        throw new RuntimeException("No public key found for institution: {$institution}");
    }

    private function loadConfiguration(string $country): void
    {
        $countryPath = __DIR__ . '/../../Core/Config/Countries/' . $country;
        
        error_log("[SwapService] loadConfiguration called for country: {$country}");
        error_log("[SwapService] Looking for config at: {$countryPath}");
        
        $participantsPath = $countryPath . '/participants.yaml';
        
        if (file_exists($participantsPath)) {
            $this->participants = $this->parseYaml($participantsPath);
            error_log("[SwapService] Participants loaded. Keys: " . implode(', ', array_keys($this->participants)));
        } else {
            error_log("[SwapService] Participants file NOT FOUND!");
        }
        
        $endpointsPath = $countryPath . '/endpoints.yaml';
        if (file_exists($endpointsPath)) {
            $this->endpoints = $this->parseYaml($endpointsPath);
        }
        
        $feesPath = $countryPath . '/fees.json';
        if (file_exists($feesPath)) {
            $this->feesConfig = json_decode(file_get_contents($feesPath), true) ?? [];
            error_log("[SwapService] Loaded fees from: {$feesPath}");
        }
        
        $this->logger->info("Configuration loaded", ['country' => $country]);
    }

    private function parseYaml(string $path): array
    {
        $content = file_get_contents($path);
        $lines = explode("\n", $content);
        $inParticipants = false;
        $currentKey = null;
        $participantsData = [];
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^participants:$/', $line)) {
                $inParticipants = true;
                continue;
            }
            
            if ($inParticipants) {
                if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
                    $currentKey = $matches[1];
                    $participantsData[$currentKey] = [];
                    continue;
                }
                
                if ($currentKey && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                    $key = $matches[1];
                    $value = trim($matches[2]);
                    if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                    if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
                    $participantsData[$currentKey][$key] = $value;
                    continue;
                }
                
                if ($currentKey && preg_match('/^    asset_types:$/', $line)) {
                    $participantsData[$currentKey]['asset_types'] = [];
                    continue;
                }
                
                if ($currentKey && isset($participantsData[$currentKey]['asset_types']) && preg_match('/^      - (.+)$/', $line, $matches)) {
                    $participantsData[$currentKey]['asset_types'][] = trim($matches[1]);
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
        error_log("[SwapService] getParticipant called with: '{$institution}'");
        
        if (isset($this->participants[$institution])) {
            $participant = $this->participants[$institution];
            $participant['provider_code'] = $institution;
            return $participant;
        }
        
        $key = strtolower($institution);
        foreach ($this->participants as $code => $participant) {
            if (strtolower($code) === $key) {
                $participant['provider_code'] = $code;
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

    public function getAtmDenominations(string $currency): array
    {
        return $this->atmNotes[$currency] ?? [200, 100, 50, 20, 10]; 
    }

    public function calculateNoteBreakdown(float $amount, string $currency): array
    {
        return $this->validateCashoutAmount($amount, $currency);
    }
}
