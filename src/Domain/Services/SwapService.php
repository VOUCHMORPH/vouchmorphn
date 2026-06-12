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
        
        // Use provided logger or create a simple fallback
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
        
        // Initialize crypto
        $this->messageSigner = new MessageSigner();
        $this->signatureVerifier = new SignatureVerifier($this->swapDB);
        
        // Initialize Certificate Manager for Visa/Mastercard style PKI
        if (class_exists('Infrastructure\Crypto\CertificateManager')) {
            $this->certificateManager = new CertificateManager('VOUCHMORPH');
            if ($this->certificateManager->isConfigured()) {
                $this->logger->info("CertificateManager initialized for VOUCHMORPH (CA trust model)");
            } else {
                $this->logger->warning("CertificateManager not fully configured - falling back to legacy signatures");
            }
        }
        
        // Load configuration
        $this->loadConfiguration($country);
        
        // Load ATM notes from country config
        $this->loadAtmNotes($country);
        
        // Initialize services
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        $this->feeService = new FeeService($this->feesConfig, $this->config['currency'] ?? 'BWP');
        $this->feeService->setParticipants($this->participants); 
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
     * Load ATM notes from country configuration
     */
    private function loadAtmNotes(string $country): void
    {
        $atmNotesPath = __DIR__ . '/../../Core/Config/Countries/' . $country . '/atm_notes.json';
        
        if (file_exists($atmNotesPath)) {
            $this->atmNotes = json_decode(file_get_contents($atmNotesPath), true);
            error_log("[SwapService] Loaded ATM notes for {$country}: " . json_encode($this->atmNotes));
        } else {
            // Default denominations if file not found
            $currency = $this->config['currency'] ?? 'BWP';
            $this->atmNotes[$currency] = [200, 100, 50, 20, 10];
            error_log("[SwapService] Using default ATM notes for {$currency}: " . json_encode($this->atmNotes[$currency]));
        }
    }

    /**
     * Validate and break down cashout amount into available denominations
     */
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

    /**
     * Calculate fees with detailed breakdown
     */
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

    // ============================================================
    // SOURCE IDENTIFIER EXTRACTION - Who is sending money
    // ============================================================

    /**
     * Extract source identifier from payload
     * Returns phone, national_id, or email based on what's available
     */
    private function extractSourceIdentifier(array $payload): array
    {
        $sourceIdentifier = null;
        $sourceIdentifierType = null;
        
        // Priority: explicit source_identifier > source_phone > wallet_phone > phone > national_id > email
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

    /**
     * Extract destination identifier from payload
     * Who receives the money (for DEPOSIT)
     */
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

    /**
     * Extract beneficiary phone for cashout (where to send ATM code)
     */
    private function extractBeneficiaryPhone(array $payload): ?string
    {
        return $payload['beneficiary_phone'] ?? 
               $payload['beneficiary_identifier'] ?? 
               $payload['client_phone'] ?? 
               null;
    }

    public function executeAtomicSwap(array $payload): array
    {
        // UNWRAP SIGNED ENVELOPE IF PRESENT
        if (isset($payload['original_payload'])) {
            error_log("[SwapService] Signed envelope detected, extracting original_payload");
            $this->signedPayloads['envelope'] = [
                'signature' => $payload['signature'] ?? null,
                'timestamp' => $payload['timestamp'] ?? null
            ];
            $payload = $payload['original_payload'];
        }
        
        // VALIDATE REQUIRED FIELDS
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
        
        // For CASHOUT type, validate the amount can be dispensed with available notes
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
        
        // Idempotency check
        if ($idempotencyKey) {
            $cached = $this->checkIdempotency($idempotencyKey);
            if ($cached) {
                $this->logger->info("Idempotency cache hit", ['key' => $idempotencyKey]);
                return $cached;
            }
        }
        
        $isMultiSource = $this->isMultiSourceContribution($payload);
        
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
    // EXECUTE SIGNED CASHOUT - With Source Identifier
    // ============================================================

    private function executeSignedCashout(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedCashout START =====");
        
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        
        if (isset($payload['_cashout_validation'])) {
            $this->feeCalculationDetails['cashout_validation'] = $payload['_cashout_validation'];
        }
        if (isset($payload['note_breakdown'])) {
            $this->feeCalculationDetails['note_breakdown'] = $payload['note_breakdown'];
        }
        
        // STEP 1: VERIFY ASSET - Pass source identifier to source bank
        error_log("[SwapService] STEP 1: Verifying asset with source institution: {$sourceInstitution}");
        $verificationResult = $this->executeStep('VERIFY_ASSET_SIGNED', function() use ($payload, $sourceInstitution) {
            return $this->verifyAssetSigned($payload, $sourceInstitution);
        });
        
        if (!($verificationResult['verified'] ?? false)) {
            $errorMessage = $verificationResult['message'] ?? 'Asset verification failed - asset not available for swap';
            error_log("[SwapService] VERIFICATION FAILED: {$errorMessage}");
            throw new RuntimeException("Asset not available: {$errorMessage}");
        }
        
        error_log("[SwapService] Asset verified successfully - proceeding to hold");
        
        $this->signedPayloads['verification'] = [
            'payload' => $verificationResult['original_payload'],
            'signature' => $verificationResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $verificationResult['timestamp']
        ];
        
        // STEP 2: PLACE HOLD
        error_log("[SwapService] STEP 2: Placing hold on asset");
        $holdResult = $this->executeStep('PLACE_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
            return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        });
        
        if (!($holdResult['hold_placed'] ?? false)) {
            $errorMessage = $holdResult['message'] ?? 'Failed to place hold on asset';
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
        
        // STEP 4: GENERATE ATM TOKEN
        error_log("[SwapService] STEP 4: Generating ATM token");
        $tokenResult = $this->executeStep('GENERATE_TOKEN_WITH_PROOF', function() use ($payload, $sourceInstitution, $netAmount) {
            $tokenPayload = $payload;
            if (isset($this->feeCalculationDetails['note_breakdown'])) {
                $tokenPayload['note_breakdown'] = $this->feeCalculationDetails['note_breakdown'];
            }
            return $this->generateAtmTokenWithProof($tokenPayload, $sourceInstitution, $netAmount);
        });
        
        // STEP 5: SEND SMS to beneficiary phone
        if ($beneficiaryPhone && $this->smsService && isset($tokenResult['atm_pin'])) {
            error_log("[SwapService] STEP 5: Sending SMS to {$beneficiaryPhone}");
            $this->executeStep('SEND_SMS', function() use ($beneficiaryPhone, $tokenResult, $netAmount) {
                return $this->smsService->sendCashoutCode(
                    $beneficiaryPhone,
                    $tokenResult['atm_pin'],
                    $netAmount,
                    $tokenResult['voucher_number'] ?? null
                );
            });
        }
        
        // STEP 6: DEBIT SOURCE
        error_log("[SwapService] STEP 6: Debiting source");
        $this->executeStep('DEBIT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->debitSource($payload, $sourceInstitution);
        });
        
        // STEP 7: UPDATE HOLD STATUS
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        $result = [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'atm_code' => $tokenResult['atm_pin'] ?? null,
            'voucher_number' => $tokenResult['voucher_number'] ?? null,
            'amount' => $netAmount,
            'original_requested_amount' => $payload['original_requested_amount'] ?? $amount,
            'remainder_balance' => $payload['remainder_balance'] ?? 0,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'fee_calculation_details' => $this->feeCalculationDetails,
            'signature_chain' => $this->signedPayloads
        ];
        
        if (isset($payload['note_breakdown'])) {
            $result['note_breakdown'] = $payload['note_breakdown'];
        }
        
        return $result;
    }

    // ============================================================
    // EXECUTE SIGNED DEPOSIT - With Destination Identifier
    // ============================================================

    private function executeSignedDeposit(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedDeposit START =====");
        
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        
        // STEP 1: VERIFY ASSET - Pass source identifier to source bank
        error_log("[SwapService] STEP 1: Verifying asset with source institution: {$sourceInstitution}");
        $verificationResult = $this->executeStep('VERIFY_ASSET_SIGNED', function() use ($payload, $sourceInstitution) {
            return $this->verifyAssetSigned($payload, $sourceInstitution);
        });
        
        if (!($verificationResult['verified'] ?? false)) {
            $errorMessage = $verificationResult['message'] ?? 'Asset verification failed';
            throw new RuntimeException("Asset not available: {$errorMessage}");
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
        
        // STEP 3: PROCESS DEPOSIT - Pass destination identifier
        $depositResult = $this->executeStep('PROCESS_DEPOSIT_WITH_PROOF', function() use ($payload, $sourceInstitution, $netAmount) {
            $depositPayload = $payload;
            $depositPayload['amount'] = $netAmount;
            return $this->processDepositWithProof($depositPayload, $sourceInstitution, $netAmount);
        });
        
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
    // EXECUTE SIGNED STANDARD SWAP - With Source & Destination Identifiers
    // ============================================================

    private function executeSignedStandardSwap(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $destInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        
        // STEP 1: VERIFY ASSET - Pass source identifier to source bank
        $verificationResult = $this->executeStep('VERIFY_ASSET_SIGNED', function() use ($payload, $sourceInstitution) {
            return $this->verifyAssetSigned($payload, $sourceInstitution);
        });
        
        if (!($verificationResult['verified'] ?? false)) {
            throw new RuntimeException("Asset not available: " . ($verificationResult['message'] ?? 'Verification failed'));
        }
        
        $this->signedPayloads['verification'] = [
            'payload' => $verificationResult['original_payload'],
            'signature' => $verificationResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $verificationResult['timestamp']
        ];
        
        // STEP 2: PLACE HOLD
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
        
        // STEP 4: PROCESS DESTINATION - Pass destination identifier
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
        
        // STEP 5: DEBIT SOURCE
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

 /**
 * Place hold with source identifier
 */
private function verifyAssetSigned(array $payload, string $institution): array
{
    $participant = $this->getParticipant($institution);
    $bankClient = new GenericBankClient($participant, $payload);
    
    $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
    $timestamp = time();
    
    // Extract source identifier (who is sending the money)
    $sourceId = $this->extractSourceIdentifier($payload);
    
    // Build verification request - ask source institution about this asset
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
    
    // ADD SOURCE IDENTIFIER TO VERIFICATION PAYLOAD
    if ($sourceId['has_value']) {
        $verifyPayload['source_identifier'] = $sourceId['identifier'];
        $verifyPayload['source_identifier_type'] = $sourceId['type'];
        
        // Add to all possible fields so bank can find it regardless of naming convention
        $verifyPayload['wallet_phone'] = $sourceId['identifier'];
        $verifyPayload['phone'] = $sourceId['identifier'];
        $verifyPayload['national_id'] = $sourceId['identifier'];
        $verifyPayload['email'] = $sourceId['identifier'];
        $verifyPayload['source_phone'] = $sourceId['identifier'];
        $verifyPayload['source_wallet_phone'] = $sourceId['identifier'];
        
        error_log("[SwapService] Source identifier sent to {$institution}: {$sourceId['type']} = {$sourceId['identifier']}");
    } else {
        error_log("[SwapService] WARNING: No source identifier found for verification");
    }
    
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
    
    // Request verification from source institution
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
    $reason = $data['reason'] ?? $data['error'] ?? null;
    
    $this->logger->info("Source institution verification response", [
        'institution' => $institution,
        'asset_type' => $assetType,
        'verified' => $verified,
        'message' => $message,
        'reason' => $reason
    ]);
    
    if ($verified !== true) {
        $errorMsg = $message ?: ($reason ?: 'Asset not available for swap');
        return [
            'verified' => false,
            'message' => $errorMsg,
            'source_response' => $data
        ];
    }
    
    // FIXED: Verify certificate OR signature from response, with fallback
    if (isset($data['certificate']) && $this->certificateManager) {
        $verification = $this->certificateManager->verifySignedRequest($data);
        if (!$verification['verified']) {
            error_log("[SwapService] Invalid certificate on verify response from {$institution}");
            return ['verified' => false, 'message' => 'Invalid certificate - verification cannot be trusted'];
        }
        error_log("[SwapService] Certificate verified on verify response from {$institution}");
    } elseif (isset($data['signature']) && isset($data['payload'])) {
        try {
            $publicKey = $this->getInstitutionPublicKey($institution);
            $isValid = $this->signatureVerifier->verify(
                $data['payload'],
                $data['signature'],
                $publicKey
            );
            if (!$isValid) {
                error_log("[SwapService] Invalid signature on verify response from {$institution}");
                return ['verified' => false, 'message' => 'Invalid signature - verification cannot be trusted'];
            }
            error_log("[SwapService] Signature verified on verify response from {$institution}");
        } catch (Exception $e) {
            $this->logger->warning("Signature verification skipped", ['error' => $e->getMessage()]);
        }
    } else {
        // No verification provided - trust the response (backward compatibility)
        error_log("[SwapService] WARNING: No certificate or signature in verify response from {$institution} - trusting response");
    }
    
    return [
        'verified' => true,
        'message' => $message ?: 'Asset verified and available',
        'balance' => $data['available_balance'] ?? $data['balance'] ?? null,
        'asset_id' => $data['asset_id'] ?? null,
        'original_payload' => $data['payload'] ?? null,
        'signature' => $data['signature'] ?? null,
        'certificate' => $data['certificate'] ?? null,
        'timestamp' => $data['timestamp'] ?? $timestamp,
        'raw_response' => $result
    ];
}

    /**
 * Place hold with source identifier
 */
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
    
    // Add source identifier
    if ($sourceId['has_value']) {
        $holdPayload['source_identifier'] = $sourceId['identifier'];
        $holdPayload['source_identifier_type'] = $sourceId['type'];
    }
    
    if (isset($verificationResult['asset_id'])) {
        $holdPayload['asset_id'] = $verificationResult['asset_id'];
    }
    
    // Add asset-specific fields
    switch ($assetType) {
        case 'VOUCHER':
            $holdPayload['voucher_number'] = $payload['voucher_number'] ?? null;
            break;
        case 'ACCOUNT':
            $holdPayload['account_number'] = $payload['account_number'] ?? null;
            break;
        case 'MNO-WALLET':
        case 'BANK-WALLET':
            $holdPayload['wallet_phone'] = $sourceId['identifier'] ?: ($payload['wallet_phone'] ?? null);
            break;
    }
    
    $result = $bankClient->placeHoldSigned($holdPayload);
    
    if (!$result['success']) {
        return ['hold_placed' => false, 'message' => $result['curl_error'] ?? 'Hold request failed'];
    }
    
    $data = $result['data'] ?? [];
    
    // FIXED: Verify certificate OR signature from response, with fallback
    $verificationPassed = true;
    
    if (isset($data['certificate']) && $this->certificateManager) {
        $verification = $this->certificateManager->verifySignedRequest($data);
        $verificationPassed = $verification['verified'];
        if (!$verificationPassed) {
            error_log("[SwapService] Invalid certificate on hold response from {$institution}");
            return ['hold_placed' => false, 'message' => 'Invalid certificate on hold response'];
        }
        error_log("[SwapService] Certificate verified on hold response from {$institution}");
    } elseif (isset($data['signature']) && isset($data['payload'])) {
        try {
            $publicKey = $this->getInstitutionPublicKey($institution);
            $isValid = $this->signatureVerifier->verify(
                $data['payload'],
                $data['signature'],
                $publicKey
            );
            if (!$isValid) {
                error_log("[SwapService] Invalid signature on hold response from {$institution}");
                return ['hold_placed' => false, 'message' => 'Invalid signature on hold response'];
            }
            error_log("[SwapService] Signature verified on hold response from {$institution}");
        } catch (Exception $e) {
            error_log("[SwapService] Signature verification error: " . $e->getMessage());
            // Don't fail - trust the response
        }
    } else {
        // No verification provided - trust the response (backward compatibility)
        error_log("[SwapService] WARNING: No certificate or signature in hold response from {$institution} - trusting response");
    }
    
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

    /**
     * Process destination with source verification proof and destination identifier
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
        
        // Extract destination identifier (who receives the money)
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
        
        // ADD DESTINATION IDENTIFIER (who receives the money)
        if ($destId['has_value']) {
            $transferPayload['destination_identifier'] = $destId['identifier'];
            $transferPayload['destination_identifier_type'] = $destId['type'];
            $transferPayload['destination_account'] = $destId['identifier'];
            $transferPayload['beneficiary_account'] = $destId['identifier'];
            
            error_log("[SwapService] Destination identifier sent to {$institution}: {$destId['type']} = {$destId['identifier']}");
        }
        
        // ADD BENEFICIARY PHONE FOR CASHOUT (where to send ATM code)
        if ($beneficiaryPhone) {
            $transferPayload['beneficiary_phone'] = $beneficiaryPhone;
            $transferPayload['sms_phone'] = $beneficiaryPhone;
            error_log("[SwapService] Beneficiary phone sent to {$institution}: {$beneficiaryPhone}");
        }
        
        // Add destination details if provided
        if (!empty($payload['destination_details'])) {
            $transferPayload['destination_details'] = $payload['destination_details'];
        }
        
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
            'destination_signature' => $data['signature'] ?? null,
            'destination_certificate' => $data['certificate'] ?? null
        ];
    }

    /**
     * Process deposit with destination identifier
     */
    private function processDepositWithProof(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        // Extract destination identifier
        $destId = $this->extractDestinationIdentifier($payload);
        
        $depositPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'source_details' => $payload['source_details'] ?? [],
            'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null
        ];
        
        // ADD DESTINATION IDENTIFIER
        if ($destId['has_value']) {
            $depositPayload['destination_identifier'] = $destId['identifier'];
            $depositPayload['destination_identifier_type'] = $destId['type'];
            $depositPayload['client_phone'] = $destId['identifier'];
            $depositPayload['client_account'] = $destId['identifier'];
            $depositPayload['beneficiary_account'] = $destId['identifier'];
            
            error_log("[SwapService] Deposit destination identifier: {$destId['type']} = {$destId['identifier']}");
        }
        
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
     * Generate ATM token with proof
     */
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
     * Record settlement with proof
     */
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
        
        switch ($assetType) {
            case 'VOUCHER':
                $verifyPayload['voucher_number'] = $payload['voucher_number'] ?? null;
                break;
            case 'ACCOUNT':
                $verifyPayload['account_number'] = $payload['account_number'] ?? null;
                break;
            case 'MNO-WALLET':
                $verifyPayload['wallet_phone'] = $sourceId['identifier'] ?: ($payload['wallet_phone'] ?? null);
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
        
        switch ($assetType) {
            case 'VOUCHER':
                $holdPayload['voucher_number'] = $payload['voucher_number'] ?? null;
                break;
            case 'ACCOUNT':
                $holdPayload['account_number'] = $payload['account_number'] ?? null;
                break;
            case 'MNO-WALLET':
                $holdPayload['wallet_phone'] = $sourceId['identifier'] ?: ($payload['wallet_phone'] ?? null);
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
                source_identifier,
                source_identifier_type,
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
                :source_identifier,
                :source_identifier_type,
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
            ':source_details' => json_encode($payload['source_details'] ?? $payload),
            ':source_identifier' => $sourceId['identifier'],
            ':source_identifier_type' => $sourceId['type'],
            ':destination' => $payload['to_institution'] ?? $payload['destination_institution'] ?? null,
            ':external_ref' => $externalHoldRef,
            ':signature_chain' => json_encode($this->signedPayloads)
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$row['hold_id'];
    }

    private function updateHoldStatus(?int $holdId, string $status): void
    {
        if ($holdId === null) {
            error_log("[SwapService] WARNING: updateHoldStatus called with NULL holdId, status: {$status}");
            return;
        }
        
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
        
        error_log("[SwapService] Hold {$holdId} updated to status: {$status}");
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
