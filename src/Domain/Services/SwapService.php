<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\SMS\SmsNotificationService;
use Infrastructure\SMS\SmsGatewayClient;

// Include required service files
require_once __DIR__ . '/ForexService.php';
require_once __DIR__ . '/FeeService.php';
require_once __DIR__ . '/CardService.php';
require_once __DIR__ . '/Settlement/HybridSettlementStrategy.php';
require_once __DIR__ . '/ContributionCalculator.php';
require_once __DIR__ . '/MultiSourceFeeCalculator.php';
require_once __DIR__ . '/MultiSourceSwapExecutor.php';

// Include SMS files
require_once __DIR__ . '/../../Infrastructure/SMS/SmsGatewayClient.php';
require_once __DIR__ . '/../../Infrastructure/SMS/SmsNotificationService.php';

class SwapService
{
    private PDO $swapDB;
    private array $settings;
    private array $config;
    private array $participants;
    private string $countryCode;
    private array $feesConfig = [];
    private array $atmNotes = [];
    private array $cardConfig = [];
    private HybridSettlementStrategy $settlement;
    private ?SmsNotificationService $smsService = null;
    private ?CardService $cardService = null;
    private ?ForexService $forexService = null;
    private ?array $fxContext = null;
    private ?FeeService $feeService = null;
    
    // Multi-source properties
    private ?MultiSourceSwapExecutor $multiSourceExecutor = null;
    private ?ContributionCalculator $contributionCalculator = null;
    private ?MultiSourceFeeCalculator $multiSourceFeeCalculator = null;

    private const HOLD_EXPIRY_HOURS = 24;
    private const MESSAGE_CARD_EXPIRY_DAYS = 30;
    private const LOG_FILE = '/tmp/vouchmorphn_swap_audit.log';

    private const PHONE_FIELDS = [
        'phone', 'wallet_phone', 'ewallet_phone', 'card_phone',
        'claimant_phone', 'beneficiary_phone', 'account_phone'
    ];

    // Supported asset types
    private const ASSET_TYPES = [
        'ACCOUNT', 'VOUCHER', 'E-WALLET', 'WALLET', 'CARD', 'ATM', 'ATM_VOUCHER'
    ];

    public function __construct(
        PDO $swapDB, 
        array $settings, 
        string $country, 
        string $encryptionKey, 
        array $config
    ) {
        $this->swapDB = $swapDB;
        $this->settings = $settings;
        $this->countryCode = strtoupper($country);
        $this->config = $config;
        
        // Load participants
        $this->participants = $config['participants'] ?? [];
        $this->participants = array_change_key_case($this->participants, CASE_LOWER);
        
        // Load ATM notes
        $this->atmNotes = $config['atm_notes'] ?? ['BWP' => [10, 20, 50, 100, 200]];
        
        // Initialize fee service
        $this->feeService = new FeeService($config['fees'] ?? [], $config['currency'] ?? 'BWP');
        
        // Initialize settlement strategy
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        
        // Initialize forex service
        $this->forexService = new ForexService($this->swapDB, $config, $this->participants, $this->feeService);
        
        // Initialize card service
        $vouchmorphConfig = $this->participants['vouchmorph'] ?? [];
        $this->cardService = new CardService($this->swapDB, $this->countryCode, $vouchmorphConfig);
        
        // Initialize multi-source components
        $this->contributionCalculator = new ContributionCalculator();
        $this->multiSourceFeeCalculator = new MultiSourceFeeCalculator($config, $this->countryCode);
        $this->multiSourceExecutor = new MultiSourceSwapExecutor(
            $this->swapDB,
            $this,
            $this->settlement,
            $config,
            $this->countryCode
        );
        
        // Initialize SMS service if configured
        try {
            if (isset($config['communication']['sms_gateway']['enabled']) && $config['communication']['sms_gateway']['enabled']) {
                $smsGatewayConfig = $config['communication']['sms_gateway'];
                if (class_exists('Infrastructure\SMS\SmsNotificationService')) {
                    $this->smsService = new SmsNotificationService($this->swapDB, $smsGatewayConfig);
                    error_log("[SwapService] SMS Service initialized successfully");
                } else {
                    error_log("[SwapService] SmsNotificationService class not found, SMS disabled");
                }
            } else {
                error_log("[SwapService] SMS service not enabled in config");
            }
        } catch (Exception $e) {
            error_log("[SwapService] Failed to initialize SMS service: " . $e->getMessage());
            $this->smsService = null;
        }
    }

    /**
     * Execute multi-source swap (Many Sources → One Destination)
     */
    public function executeMultiSourceSwap(array $payload): array
    {
        if (!$this->multiSourceExecutor) {
            throw new RuntimeException("Multi-source swap executor not initialized");
        }
        
        $multiSourceEnabled = $this->config['multi_source']['enabled'] ?? true;
        if (!$multiSourceEnabled) {
            throw new RuntimeException("Multi-source swaps are not enabled");
        }
        
        return $this->multiSourceExecutor->execute($payload);
    }

    /**
     * Check if a swap is part of a multi-source transaction
     */
    public function isMultiSourceContribution(array $payload): bool
    {
        return isset($payload['is_multi_source']) && $payload['is_multi_source'] === true;
    }

    /**
     * Get master reference for multi-source contribution
     */
    public function getMasterReferenceForContribution(array $payload): ?string
    {
        return $payload['master_reference'] ?? $payload['multi_source_uuid'] ?? null;
    }

    /**
     * Get source index for multi-source contribution
     */
    public function getSourceIndexForContribution(array $payload): ?int
    {
        return $payload['source_index'] ?? null;
    }

    /**
     * Calculate multi-source fees
     */
    public function calculateMultiSourceFees(
        int $sourceCount,
        string $deliveryMode,
        float $destinationAmount,
        string $sourceCurrency = 'BWP',
        string $destinationCurrency = 'BWP'
    ): array {
        if (!$this->multiSourceFeeCalculator) {
            throw new RuntimeException("Multi-source fee calculator not initialized");
        }
        
        return $this->multiSourceFeeCalculator->calculateFees(
            $sourceCount,
            $deliveryMode,
            $destinationAmount,
            $sourceCurrency,
            $destinationCurrency
        );
    }

    /**
     * Calculate contribution distribution
     */
    public function calculateContributions(
        float $targetAmount,
        array $sources,
        string $strategy = 'drain_smallest',
        ?array $userSpecified = null
    ): array {
        if (!$this->contributionCalculator) {
            throw new RuntimeException("Contribution calculator not initialized");
        }
        
        return $this->contributionCalculator->calculateContributions(
            $targetAmount,
            $sources,
            $strategy,
            $userSpecified
        );
    }

    /**
     * Get available balance for a source
     */
    public function getSourceAvailableBalance(array $source): float
    {
        try {
            $participant = $this->getParticipant($source['institution']);
            $assetType = strtoupper($source['asset_type'] ?? 'UNKNOWN');
            
            $bankClient = new GenericBankClient($participant);
            $tempRef = 'BALANCE_CHECK_' . bin2hex(random_bytes(8));
            
            $payload = [
                'reference' => $tempRef,
                'institution' => $source['institution'],
                'asset_type' => $assetType
            ];
            
            switch ($assetType) {
                case 'VOUCHER':
                    $voucher = $source['voucher'] ?? [];
                    $payload['voucher_number'] = $voucher['voucher_number'] ?? $source['identifier'] ?? null;
                    $payload['claimant_phone'] = $this->formatPhoneForInstitution(
                        $voucher['claimant_phone'] ?? $source['phone'] ?? null, 
                        $participant
                    );
                    break;
                case 'ACCOUNT':
                    $account = $source['account'] ?? [];
                    $payload['account_number'] = $account['account_number'] ?? $source['identifier'] ?? null;
                    break;
                case 'WALLET':
                    $wallet = $source['wallet'] ?? [];
                    $payload['wallet_phone'] = $this->formatPhoneForInstitution(
                        $wallet['wallet_phone'] ?? $source['identifier'] ?? null, 
                        $participant
                    );
                    break;
                case 'E-WALLET':
                    $ewallet = $source['ewallet'] ?? [];
                    $payload['ewallet_phone'] = $this->formatPhoneForInstitution(
                        $ewallet['ewallet_phone'] ?? $source['identifier'] ?? null, 
                        $participant
                    );
                    break;
                case 'CARD':
                    $card = $source['card'] ?? [];
                    $payload['card_number'] = $card['card_number'] ?? $source['identifier'] ?? null;
                    break;
                case 'ATM_VOUCHER':
                    $voucherCode = $source['voucher_code'] ?? $source['identifier'] ?? null;
                    if ($voucherCode) {
                        return $this->getVoucherBalance($voucherCode);
                    }
                    return 0;
                default:
                    return 0;
            }
            
            $result = $bankClient->verifyAsset($payload);
            
            if (($result['success'] ?? false)) {
                $data = $result['data'] ?? [];
                return (float)($data['available_balance'] ?? $data['balance'] ?? 0);
            }
            
            return 0;
            
        } catch (Exception $e) {
            error_log("[SwapService] Failed to get balance for {$source['institution']}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get voucher balance from database
     */
    private function getVoucherBalance(string $voucherCode): float
    {
        try {
            $stmt = $this->swapDB->prepare("
                SELECT amount FROM atm_vouchers 
                WHERE voucher_code = :code AND status = 'active' 
                AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $stmt->execute([':code' => $voucherCode]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (float)$result['amount'] : 0;
        } catch (Exception $e) {
            error_log("[SwapService] Failed to get voucher balance: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Use voucher for contribution
     */
    public function useVoucher(string $voucherCode, string $swapReference, float $amount): bool
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE atm_vouchers 
                SET status = 'used', 
                    used_in_swap = :swap_ref, 
                    used_at = NOW()
                WHERE voucher_code = :code 
                AND status = 'active' 
                AND amount >= :amount
                AND (expires_at IS NULL OR expires_at > NOW())
                RETURNING voucher_id
            ");
            $stmt->execute([
                ':code' => $voucherCode,
                ':swap_ref' => $swapReference,
                ':amount' => $amount
            ]);
            
            return $stmt->fetchColumn() !== false;
            
        } catch (Exception $e) {
            error_log("[SwapService] Failed to use voucher: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create ATM voucher
     */
    public function createAtmVoucher(string $voucherCode, float $amount, string $currency, string $issuingInstitution, int $expiryDays = 30): bool
    {
        try {
            $stmt = $this->swapDB->prepare("
                INSERT INTO atm_vouchers 
                (voucher_code, amount, currency, issuing_institution, expires_at, created_at)
                VALUES (:code, :amount, :currency, :issuer, NOW() + INTERVAL ':days DAY', NOW())
            ");
            $stmt->execute([
                ':code' => $voucherCode,
                ':amount' => $amount,
                ':currency' => $currency,
                ':issuer' => $issuingInstitution,
                ':days' => $expiryDays
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("[SwapService] Failed to create voucher: " . $e->getMessage());
            return false;
        }
    }

    public function executeSwap(array $payload): array
    {
        // Check if this is a multi-source contribution
        if ($this->isMultiSourceContribution($payload)) {
            $masterRef = $this->getMasterReferenceForContribution($payload);
            $sourceIndex = $this->getSourceIndexForContribution($payload);
            error_log("[SwapService] Processing multi-source contribution: Master={$masterRef}, Index={$sourceIndex}");
        }
        
        $this->swapDB->beginTransaction();
        $swapRef = bin2hex(random_bytes(16));
        $originalSwapRef = $payload['original_swap_reference'] ?? null;
        $retryCount = $this->getRetryCount($originalSwapRef);
        
        $isFirstAttempt = ($retryCount === 0);
        $isFreeRetry = ($retryCount === 1);
        $isPaidRetry = ($retryCount >= 2);
        
        try {
            $source = $payload['source'];
            $destination = $payload['destination'];
            $isCashout = ($destination['delivery_mode'] ?? 'deposit') === 'cashout';
            
            $sourceParticipant = $this->getParticipant($source['institution']);
            $destParticipant = $this->getParticipant($destination['institution']);
            
            $source = $this->sanitizePhones($source, $sourceParticipant);
            
            // Handle different asset types including VOUCHER
            $this->prepareSourceAsset($source, $swapRef);
            
            // Step 1: Verify source asset
            $verification = $this->verifySourceAsset($swapRef, $source, $sourceParticipant);
            if (!$verification['verified']) {
                throw new RuntimeException($verification['message']);
            }
            
            $sourceCurrency = $verification['currency'] ?? $source['currency'] ?? 'BWP';
            $destCurrency = $this->getDestinationCurrency($destination, $destParticipant);
            $sourceAmount = (float)$source['amount'];
            
            // Validate cashout amount if applicable
            if ($isCashout) {
                $errors = $this->validateCashoutAmount($sourceAmount, $destCurrency);
                if (!empty($errors)) {
                    throw new RuntimeException("Cashout validation failed: " . implode(', ', $errors));
                }
            }
            
            // Step 2: Handle FX if needed
            $creditAmount = $sourceAmount;
            if ($this->forexService && $sourceCurrency !== $destCurrency) {
                $this->fxContext = $this->forexService->prepareFxContext(
                    $swapRef, $sourceParticipant, $destParticipant,
                    $sourceCurrency, $sourceAmount, $destination
                );
                if ($this->fxContext['needs_fx']) {
                    $creditAmount = $this->fxContext['destination_amount'];
                    $destination['amount'] = $creditAmount;
                }
            }
            
            // Step 3: Place hold on source
            $holdResult = $this->placeHold($swapRef, $source, $sourceParticipant, $destination);
            
            // Step 4: Calculate fees based on retry status
            $feeCalculation = $this->calculateFeesWithRetryLogic(
                $creditAmount, $destination, $sourceCurrency, $destCurrency,
                $source['institution'], $destination['institution'],
                $isFirstAttempt, $isFreeRetry, $isPaidRetry, $originalSwapRef
            );
            
            $totalFees = $feeCalculation['total_fees'];
            $netAmount = $creditAmount - $totalFees;
            
            // For cashout, ensure net amount is ATM-dispensable
            $dispensedAmount = $netAmount;
            $undispensedAmount = 0;
            $atmNotes = [];
            
            if ($isCashout) {
                $atmResult = $this->getDispensableAmount($netAmount, $destCurrency);
                $dispensedAmount = $atmResult['dispensable_amount'];
                $undispensedAmount = $atmResult['undispensed_amount'];
                $atmNotes = $atmResult['notes'];
                
                if ($dispensedAmount <= 0) {
                    throw new RuntimeException("Amount {$netAmount} {$destCurrency} cannot be dispensed");
                }
            }
            
            // Step 5: Record swap
            $swapId = $this->recordSwap($swapRef, $source, $destination, $sourceCurrency, $destCurrency, $verification, $this->fxContext, $originalSwapRef, $retryCount);
            
            // Step 6: Store fee record
            $this->storeFees($swapRef, $feeCalculation, $source['institution'], $destination['institution'], $retryCount, $originalSwapRef);
            
            // Step 7: Process destination
            $result = $this->processDestination($swapId, $swapRef, $source, $destination, $destCurrency, $holdResult, $dispensedAmount);
            
            // Step 8: Handle undispensed remainder
            if ($isCashout && $undispensedAmount > 0.01) {
                $this->handleUndispensedRemainder($swapRef, $undispensedAmount);
                $result['undispensed_amount'] = $undispensedAmount;
                $result['dispensed_notes'] = $atmNotes;
            }
            
            // Step 9: Debit source
            $debitAmount = $isCashout ? $dispensedAmount : $sourceAmount;
            if (!$this->shouldSkipDebit($destination)) {
                $this->debitSource($swapRef, $holdResult, $sourceParticipant, $debitAmount);
            }
            
            // Step 10: Queue fee settlement
            $this->queueFeeSettlement($swapRef, $feeCalculation, $source['institution'], $destination['institution'], $isCashout, $isFreeRetry, $isPaidRetry, $originalSwapRef);
            
            // Step 11: Send confirmation SMS if needed
            if ($result && !empty($result['generated_code']) && isset($destination['cashout']['beneficiary_phone'])) {
                $this->sendSmsConfirmation($destination['cashout']['beneficiary_phone'], $result['generated_code'], $netAmount, $destCurrency);
            }
            
            $this->swapDB->commit();
            
            return $this->buildResponse($swapRef, $holdResult, $result, $this->fxContext, $isFreeRetry, $isPaidRetry, $atmNotes);
            
        } catch (Exception $e) {
            $this->swapDB->rollBack();
            $this->releaseHoldIfNeeded($holdResult ?? null);
            
            if ($isCashout && $isFirstAttempt) {
                $this->storeUnearnedCashoutFee($swapRef, $source, $e->getMessage());
            } elseif ($isCashout && !$isFirstAttempt) {
                $this->updateRetryCount($originalSwapRef, $e->getMessage());
            }
            
            return ['status' => 'error', 'message' => $e->getMessage(), 'swap_reference' => $swapRef];
        }
    }

    /**
     * Prepare source asset based on type (handle vouchers, etc.)
     */
    private function prepareSourceAsset(array &$source, string $swapRef): void
    {
        $assetType = strtoupper($source['asset_type'] ?? '');
        
        switch ($assetType) {
            case 'VOUCHER':
                $voucher = $source['voucher'] ?? [];
                if (isset($voucher['voucher_number'])) {
                    // Verify voucher with issuing institution (e.g., Zurubank)
                    $this->logEvent($swapRef, 'VOUCHER_DETECTED', [
                        'voucher_number' => substr($voucher['voucher_number'], -8),
                        'amount' => $source['amount'] ?? 'unknown'
                    ]);
                }
                break;
                
            case 'ATM_VOUCHER':
                if (isset($source['voucher_code'])) {
                    $voucherAmount = $this->getVoucherBalance($source['voucher_code']);
                    if ($voucherAmount < ($source['amount'] ?? 0)) {
                        throw new RuntimeException("Insufficient voucher balance: {$voucherAmount} available");
                    }
                    if (!$this->useVoucher($source['voucher_code'], $swapRef, $source['amount'])) {
                        throw new RuntimeException("Failed to use voucher: {$source['voucher_code']}");
                    }
                    $this->logEvent($swapRef, 'ATM_VOUCHER_USED', [
                        'voucher_code' => $source['voucher_code'],
                        'amount' => $source['amount']
                    ]);
                }
                break;
        }
    }

    /**
     * Validate cashout amount with ATM denominations
     */
    private function validateCashoutAmount(float $amount, string $currency): array
    {
        $errors = [];
        
        try {
            $atmResult = $this->getDispensableAmount($amount, $currency);
            
            if ($atmResult['dispensable_amount'] <= 0) {
                $errors[] = "Amount cannot be dispensed with available denominations";
            }
            
            if ($atmResult['undispensed_amount'] > 0.01) {
                $errors[] = sprintf(
                    "Amount %0.2f %s cannot be dispensed exactly. Closest dispensable: %0.2f %s",
                    $amount,
                    $currency,
                    $atmResult['dispensable_amount'],
                    $currency
                );
            }
            
        } catch (Exception $e) {
            $errors[] = "Cashout validation error: " . $e->getMessage();
        }
        
        return $errors;
    }
    
    /**
     * Send SMS confirmation
     */
    private function sendSmsConfirmation(string $phoneNumber, string $code, float $amount, string $currency): void
    {
        if (!$this->smsService) {
            error_log("[SwapService] SMS service not available, cannot send confirmation to {$phoneNumber}");
            return;
        }
        
        try {
            $message = "Your VouchMorph withdrawal code is: {$code}\n";
            $message .= "Amount: " . number_format($amount, 2) . " {$currency}\n";
            $message .= "Valid for 24 hours.\n";
            $message .= "Do not share this code with anyone.";
            
            $result = $this->smsService->sendSms($phoneNumber, $message, [
                'priority' => 'high',
                'reference' => 'WDL-' . uniqid(),
                'type' => 'withdrawal_code'
            ]);
            
            if ($result['success']) {
                error_log("[SwapService] SMS sent successfully to {$phoneNumber}");
            } else {
                error_log("[SwapService] SMS failed to {$phoneNumber}: " . ($result['message'] ?? 'Unknown error'));
            }
        } catch (Exception $e) {
            error_log("[SwapService] SMS exception: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate fees with retry logic
     */
    private function calculateFeesWithRetryLogic(
        float $amount,
        array $destination,
        string $sourceCurrency,
        string $destCurrency,
        string $sourceInstitution,
        string $destinationInstitution,
        bool $isFirstAttempt,
        bool $isFreeRetry,
        bool $isPaidRetry,
        ?string $originalSwapRef
    ): array {
        $transactionType = $this->getTransactionType($destination);
        
        $feeCalculation = $this->feeService->calculateAllFees(
            $amount, $transactionType, $sourceCurrency, $destCurrency,
            $this->getInstitutionCountry($sourceInstitution),
            $this->getInstitutionCountry($destinationInstitution)
        );
        
        if ($transactionType === 'CASHOUT') {
            $feeConfig = $this->config['fees']['fees']['CASHOUT_SWAP_FEE'] ?? null;
            if ($feeConfig) {
                $generateCodeFee = $feeConfig['retry_fee']['generate_code_fee'] ?? 0.45;
                
                if ($isFreeRetry) {
                    $unearnedCashoutFee = $this->getUnearnedCashoutFee($originalSwapRef);
                    $feeCalculation['total_fees'] = 0;
                    $feeCalculation['client_pays'] = 0;
                    $feeCalculation['vouchmorph_pays_generate_code'] = $generateCodeFee;
                    $feeCalculation['unearned_fee_used'] = $unearnedCashoutFee;
                    $feeCalculation['is_free_retry'] = true;
                } elseif ($isPaidRetry) {
                    $unearnedCashoutFee = $this->getUnearnedCashoutFee($originalSwapRef);
                    $feeCalculation['total_fees'] = $generateCodeFee;
                    $feeCalculation['client_pays'] = $generateCodeFee;
                    $feeCalculation['unearned_fee_used'] = $unearnedCashoutFee;
                    $feeCalculation['is_paid_retry'] = true;
                }
            }
        }
        
        return $feeCalculation;
    }
    
    /**
     * Get retry count
     */
    private function getRetryCount(?string $originalSwapRef): int
    {
        if (!$originalSwapRef) return 0;
        
        $stmt = $this->swapDB->prepare("
            SELECT COALESCE(retry_count, 0) FROM cashout_retry_tracking 
            WHERE original_swap_ref = :swap_ref
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':swap_ref' => $originalSwapRef]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['retry_count'] : 0;
    }
    
    /**
     * Get unearned cashout fee
     */
    private function getUnearnedCashoutFee(?string $originalSwapRef): float
    {
        if (!$originalSwapRef) return 0;
        
        $stmt = $this->swapDB->prepare("
            SELECT COALESCE(unearned_cashout_fee, 0) FROM cashout_retry_tracking 
            WHERE original_swap_ref = :swap_ref AND used = false
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':swap_ref' => $originalSwapRef]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (float)$result['unearned_cashout_fee'] : 0;
    }
    
    /**
     * Store unearned cashout fee
     */
    private function storeUnearnedCashoutFee(string $swapRef, array $source, string $error): void
    {
        $clientIdentifier = $this->extractClientIdentifier($source);
        
        $feeConfig = $this->config['fees']['fees']['CASHOUT_SWAP_FEE'] ?? null;
        if ($feeConfig) {
            $totalFee = $feeConfig['total_amount'] ?? 10.00;
            $swapLevy = $feeConfig['swap_levy'] ?? 1.00;
            $afterLevy = $totalFee - $swapLevy;
            $split = $feeConfig['split_after_levy'] ?? ['destination_institution_percent' => 50];
            $destinationShare = $afterLevy * ($split['destination_institution_percent'] / 100);
            $cashoutFee = $destinationShare * ($feeConfig['destination_split']['cashout_fee_percent'] / 100);
            
            $stmt = $this->swapDB->prepare("
                INSERT INTO cashout_retry_tracking 
                (client_identifier, original_swap_ref, retry_count, unearned_cashout_fee, last_error, created_at)
                VALUES (:client, :swap_ref, 0, :fee, :error, NOW())
            ");
            $stmt->execute([
                ':client' => $clientIdentifier,
                ':swap_ref' => $swapRef,
                ':fee' => $cashoutFee,
                ':error' => $error
            ]);
        }
    }
    
    /**
     * Update retry count
     */
    private function updateRetryCount(string $originalSwapRef, string $error): void
    {
        $stmt = $this->swapDB->prepare("
            UPDATE cashout_retry_tracking 
            SET retry_count = retry_count + 1, last_error = :error, updated_at = NOW()
            WHERE original_swap_ref = :swap_ref
        ");
        $stmt->execute([':swap_ref' => $originalSwapRef, ':error' => $error]);
    }
    
    /**
     * Queue fee settlement
     */
    private function queueFeeSettlement(
        string $swapRef, 
        array $feeCalculation, 
        string $sourceInstitution, 
        string $destinationInstitution,
        bool $isCashout,
        bool $isFreeRetry,
        bool $isPaidRetry,
        ?string $originalSwapRef
    ): void {
        $feeConfig = $this->config['fees']['fees']['CASHOUT_SWAP_FEE'] ?? 
                     $this->config['fees']['fees']['DEPOSIT_SWAP_FEE'] ?? null;
        
        if (!$feeConfig) return;
        
        $swapLevy = $feeConfig['swap_levy'] ?? 0;
        $totalFee = $feeConfig['total_amount'] ?? ($isCashout ? 10.00 : 6.00);
        
        if ($isFreeRetry) {
            $totalFee = 0;
        } elseif ($isPaidRetry) {
            $totalFee = $feeConfig['retry_fee']['generate_code_fee'] ?? 0.45;
        }
        
        $afterLevy = $totalFee - $swapLevy;
        $split = $feeConfig['split_after_levy'] ?? ['platform_percent' => 35, 'source_institution_percent' => 15, 'destination_institution_percent' => 50];
        
        $platformShare = $afterLevy * ($split['platform_percent'] / 100);
        $sourceShare = $afterLevy * ($split['source_institution_percent'] / 100);
        $destinationShare = $afterLevy * ($split['destination_institution_percent'] / 100);
        
        $currency = $this->config['currency'] ?? 'BWP';
        
        if (($isFreeRetry || $isFirstAttempt || $isPaidRetry) && ($swapLevy + $platformShare) > 0) {
            $this->settlement->invoiceFee($swapRef, $sourceInstitution, 0, 'VOUCHMORPH_FEE', $swapLevy + $platformShare, $currency);
        }
        
        if ($sourceShare > 0) {
            $this->settlement->invoiceFee($swapRef, $sourceInstitution, 0, 'SOURCE_INSTITUTION_FEE', $sourceShare, $currency);
        }
        
        if ($isCashout && isset($feeConfig['destination_split'])) {
            $generateCodeFee = $destinationShare * ($feeConfig['destination_split']['generate_code_fee_percent'] / 100);
            $cashoutFee = $destinationShare * ($feeConfig['destination_split']['cashout_fee_percent'] / 100);
            
            if ($generateCodeFee > 0) {
                if ($isFreeRetry) {
                    $this->settlement->invoiceFee($swapRef, 'VOUCHMORPH', 0, 'GENERATE_CODE_FEE_PAID_BY_VM', $generateCodeFee, $currency);
                } elseif ($isPaidRetry) {
                    $this->settlement->invoiceFee($swapRef, $sourceInstitution, 0, 'GENERATE_CODE_FEE_PAID_BY_CLIENT', $generateCodeFee, $currency);
                } else {
                    $this->settlement->invoiceFee($swapRef, $destinationInstitution, 0, 'GENERATE_CODE_FEE', $generateCodeFee, $currency);
                }
            }
            
            if ($cashoutFee > 0 && ($isFreeRetry || $isPaidRetry)) {
                $this->settlement->invoiceFee($swapRef, $destinationInstitution, 0, 'CASHOUT_FEE_FROM_UNEARNED', $cashoutFee, $currency);
            }
        }
    }
    
    private function getDispensableAmount(float $amount, string $currency): array
    {
        $denominations = $this->atmNotes[$currency] ?? null;
        if (!$denominations) {
            throw new RuntimeException("No ATM denominations for currency {$currency}");
        }
        
        rsort($denominations);
        
        $remainingCents = (int)round($amount * 100);
        $dispensedNotes = [];
        $originalAmount = $remainingCents;
        
        foreach ($denominations as $note) {
            $noteCents = (int)round($note * 100);
            if ($noteCents <= 0) continue;
            
            $count = intdiv($remainingCents, $noteCents);
            if ($count > 0) {
                $dispensedNotes[(string)$note] = $count;
                $remainingCents -= $noteCents * $count;
            }
        }
        
        $dispensableCents = $originalAmount - $remainingCents;
        
        // If exact amount cannot be dispensed, find closest
        if ($dispensableCents === 0) {
            $dispensableCents = $this->findClosestDispensableAmount($originalAmount, $denominations);
            $remainingCents = $originalAmount - $dispensableCents;
            
            if ($dispensableCents > 0) {
                $tempRemaining = $dispensableCents;
                $dispensedNotes = [];
                foreach ($denominations as $note) {
                    $noteCents = (int)round($note * 100);
                    $count = intdiv($tempRemaining, $noteCents);
                    if ($count > 0) {
                        $dispensedNotes[(string)$note] = $count;
                        $tempRemaining -= $noteCents * $count;
                    }
                }
            }
        }
        
        return [
            'dispensable_amount' => round($dispensableCents / 100, 2),
            'notes' => $dispensedNotes,
            'undispensed_amount' => round($remainingCents / 100, 2)
        ];
    }
    
    private function findClosestDispensableAmount(int $targetCents, array $denominations): int
    {
        $denomCents = array_map(fn($d) => (int)round($d * 100), $denominations);
        sort($denomCents);
        
        $dp = array_fill(0, $targetCents + 1, false);
        $dp[0] = true;
        
        for ($i = 1; $i <= $targetCents; $i++) {
            foreach ($denomCents as $denom) {
                if ($i >= $denom && $dp[$i - $denom]) {
                    $dp[$i] = true;
                    break;
                }
            }
        }
        
        for ($i = $targetCents; $i >= 0; $i--) {
            if ($dp[$i]) return $i;
        }
        
        return 0;
    }
    
    private function extractClientIdentifier(array $source): string
    {
        $fields = ['phone', 'ewallet_phone', 'wallet_phone', 'card_phone', 'claimant_phone', 'beneficiary_phone', 'account_phone', 'email'];
        
        foreach ($fields as $field) {
            if (isset($source[$field]) && !empty($source[$field])) {
                return $source[$field];
            }
            if (isset($source['cashout'][$field]) && !empty($source['cashout'][$field])) {
                return $source['cashout'][$field];
            }
        }
        
        // Check nested structures for VOUCHER, ACCOUNT, etc.
        $nestedTypes = ['voucher', 'account', 'wallet', 'ewallet', 'card'];
        foreach ($nestedTypes as $type) {
            if (isset($source[$type])) {
                foreach ($fields as $field) {
                    if (isset($source[$type][$field]) && !empty($source[$type][$field])) {
                        return $source[$type][$field];
                    }
                }
            }
        }
        
        return 'unknown_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    
    private function handleUndispensedRemainder(string $swapRef, float $amount): void
    {
        $stmt = $this->swapDB->prepare("
            UPDATE swap_requests 
            SET undispensed_amount = :amount,
                metadata = metadata || jsonb_build_object('undispensed', :amount)
            WHERE swap_uuid = :swap_ref
        ");
        $stmt->execute([':amount' => $amount, ':swap_ref' => $swapRef]);
    }
    
    public function getParticipant(string $institution): array
    {
        $key = $this->findInstitutionKey($institution);
        if (!$key || !isset($this->participants[$key])) {
            throw new RuntimeException("Institution not found: {$institution}");
        }
        return $this->participants[$key];
    }

    private function findInstitutionKey(string $search): ?string
    {
        $searchLower = strtolower($search);
        
        if (isset($this->participants[$searchLower])) {
            return $searchLower;
        }
        
        foreach ($this->participants as $key => $participant) {
            if (isset($participant['provider_code']) && strtolower($participant['provider_code']) === $searchLower) {
                return $key;
            }
        }
        return null;
    }

    private function getInstitutionCountry(string $institution): string
    {
        $key = $this->findInstitutionKey($institution);
        if ($key && isset($this->participants[$key]['country_code'])) {
            return $this->participants[$key]['country_code'];
        }
        return $this->countryCode;
    }

    private function getTransactionType(array $destination): string
    {
        return match ($destination['delivery_mode'] ?? 'deposit') {
            'cashout' => 'CASHOUT',
            'card_load' => 'CARD_LOAD',
            'card' => 'CARD_ISSUANCE',
            default => 'DEPOSIT'
        };
    }

    private function getDestinationCurrency(array $destination, array $participant): string
    {
        if (isset($destination['currency']) && !empty($destination['currency'])) {
            return strtoupper($destination['currency']);
        }
        return $participant['default_currency'] ?? $this->config['currency'] ?? 'BWP';
    }

    private function shouldSkipDebit(array $destination): bool
    {
        $deliveryMode = $destination['delivery_mode'] ?? '';
        if (!in_array($deliveryMode, ['card_load', 'card'])) {
            return false;
        }
        $institution = $destination['institution'] ?? '';
        return strtoupper($institution) === 'VOUCHMORPH';
    }

    private function verifySourceAsset(string $swapRef, array $source, array $participant): array
    {
        $assetType = strtoupper($source['asset_type'] ?? 'UNKNOWN');
        $bankClient = new GenericBankClient($participant);
        
        $payload = [
            'reference' => $swapRef,
            'institution' => $source['institution'],
            'asset_type' => $assetType,
            'amount' => $source['amount'] ?? 0
        ];
        
        // Handle all asset types including VOUCHER
        switch ($assetType) {
            case 'VOUCHER':
                $voucher = $source['voucher'] ?? [];
                $payload['voucher_number'] = $voucher['voucher_number'] ?? null;
                $payload['voucher_pin'] = $voucher['voucher_pin'] ?? null;
                $payload['claimant_phone'] = $this->formatPhoneForInstitution(
                    $voucher['claimant_phone'] ?? $source['claimant_phone'] ?? null, 
                    $participant
                );
                break;
                
            case 'ACCOUNT':
                $account = $source['account'] ?? [];
                $payload['account_number'] = $account['account_number'] ?? $source['account_number'] ?? null;
                $payload['account_pin'] = $account['account_pin'] ?? null;
                $payload['account_holder'] = $account['account_holder'] ?? null;
                break;
                
            case 'WALLET':
                $wallet = $source['wallet'] ?? [];
                $payload['wallet_phone'] = $this->formatPhoneForInstitution(
                    $wallet['wallet_phone'] ?? $source['wallet_phone'] ?? null, 
                    $participant
                );
                $payload['wallet_pin'] = $wallet['wallet_pin'] ?? null;
                break;
                
            case 'E-WALLET':
                $ewallet = $source['ewallet'] ?? [];
                $payload['ewallet_phone'] = $this->formatPhoneForInstitution(
                    $ewallet['ewallet_phone'] ?? $source['ewallet_phone'] ?? null, 
                    $participant
                );
                break;
                
            case 'CARD':
                $card = $source['card'] ?? [];
                $payload['card_number'] = $card['card_number'] ?? $source['card_number'] ?? null;
                $payload['card_pin'] = $card['card_pin'] ?? null;
                $payload['card_holder'] = $card['card_holder'] ?? null;
                break;
                
            case 'ATM_VOUCHER':
                $payload['voucher_code'] = $source['voucher_code'] ?? null;
                break;
                
            default:
                // Try to get from flat fields
                $payload['phone'] = $this->formatPhoneForInstitution(
                    $source['phone'] ?? $source['ewallet_phone'] ?? $source['wallet_phone'] ?? null, 
                    $participant
                );
                $payload['account_number'] = $source['account_number'] ?? null;
                $payload['card_number'] = $source['card_number'] ?? null;
        }
        
        $result = $bankClient->verifyAsset($payload);
        
        if (!($result['success'] ?? false)) {
            return ['verified' => false, 'message' => $result['curl_error'] ?? 'Verification failed'];
        }
        
        $data = $result['data'] ?? [];
        return [
            'verified' => true, 
            'currency' => $data['currency'] ?? null,
            'asset_details' => $data
        ];
    }

    private function placeHold(string $swapRef, array $source, array $participant, ?array $destination = null): array
    {
        $bankClient = new GenericBankClient($participant);
        $assetType = strtoupper($source['asset_type'] ?? 'UNKNOWN');
        
        $holdPayload = [
            'reference' => $swapRef,
            'asset_type' => $assetType,
            'amount' => $source['amount'],
            'expiry_hours' => self::HOLD_EXPIRY_HOURS,
            'hold_reason' => 'PENDING_TRANSACTION'
        ];
        
        // Add destination institution if provided
        if ($destination && isset($destination['institution'])) {
            $holdPayload['destination_institution'] = $destination['institution'];
        }
        
        // Add asset-specific fields
        switch ($assetType) {
            case 'VOUCHER':
                $voucher = $source['voucher'] ?? [];
                $holdPayload['voucher_number'] = $voucher['voucher_number'] ?? null;
                $holdPayload['claimant_phone'] = $this->formatPhoneForInstitution(
                    $voucher['claimant_phone'] ?? null, $participant
                );
                break;
            case 'ACCOUNT':
                $account = $source['account'] ?? [];
                $holdPayload['account_number'] = $account['account_number'] ?? $source['account_number'] ?? null;
                break;
            case 'WALLET':
                $wallet = $source['wallet'] ?? [];
                $holdPayload['wallet_phone'] = $this->formatPhoneForInstitution(
                    $wallet['wallet_phone'] ?? $source['wallet_phone'] ?? null, $participant
                );
                break;
            case 'E-WALLET':
                $ewallet = $source['ewallet'] ?? [];
                $holdPayload['ewallet_phone'] = $this->formatPhoneForInstitution(
                    $ewallet['ewallet_phone'] ?? $source['ewallet_phone'] ?? null, $participant
                );
                break;
            case 'CARD':
                $card = $source['card'] ?? [];
                $holdPayload['card_number'] = $card['card_number'] ?? $source['card_number'] ?? null;
                break;
            case 'ATM_VOUCHER':
                $holdPayload['voucher_code'] = $source['voucher_code'] ?? null;
                break;
        }
        
        $result = $bankClient->placeHold($holdPayload);
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Hold failed: " . ($result['message'] ?? 'Unknown error'));
        }
        
        $data = $result['data'] ?? [];
        return [
            'hold_placed' => true,
            'hold_reference' => $data['hold_reference'] ?? $swapRef . '-HOLD',
            'hold_expiry' => $data['hold_expiry'] ?? date('Y-m-d H:i:s', strtotime('+' . self::HOLD_EXPIRY_HOURS . ' hours'))
        ];
    }

    private function debitSource(string $swapRef, array $holdResult, array $participant, float $amount): void
    {
        $bankClient = new GenericBankClient($participant);
        
        $result = $bankClient->debitHold([
            'reference' => $swapRef,
            'hold_reference' => $holdResult['hold_reference'],
            'amount' => $amount
        ]);
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Debit failed: " . ($result['message'] ?? 'Unknown error'));
        }
    }

    private function releaseHoldIfNeeded(?array $holdResult): void
    {
        if (!$holdResult || !($holdResult['hold_placed'] ?? false)) {
            return;
        }
        $this->logEvent('HOLD_NOT_RELEASED', ['hold_reference' => $holdResult['hold_reference'] ?? 'unknown']);
    }

    private function processDestination(
        int $swapId, 
        string $swapRef, 
        array $source, 
        array $destination, 
        string $currency, 
        array $holdResult, 
        float $netAmount
    ): array {
        return match ($destination['delivery_mode'] ?? 'deposit') {
            'cashout' => $this->processCashout($swapId, $swapRef, $source, $destination, $currency, $holdResult, $netAmount),
            'card_load' => $this->processCardLoad($swapId, $swapRef, $destination, $currency, $holdResult, $netAmount),
            'card' => $this->processCardIssuance($swapId, $swapRef, $destination, $currency, $holdResult, $netAmount),
            default => $this->processDeposit($swapId, $swapRef, $source, $destination, $currency, $holdResult, $netAmount)
        };
    }

    private function processCashout(
        int $swapId, 
        string $swapRef, 
        array $source, 
        array $destination, 
        string $currency, 
        array $holdResult, 
        float $netAmount
    ): array {
        $destParticipant = $this->getParticipant($destination['institution']);
        $cashoutData = $destination['cashout'] ?? [];
        
        if (isset($cashoutData['beneficiary_phone'])) {
            $cashoutData['beneficiary_phone'] = $this->formatPhoneForInstitution($cashoutData['beneficiary_phone'], $destParticipant);
        }
        
        $bankClient = new GenericBankClient($destParticipant);
        $result = $bankClient->transfer([
            'reference' => $swapRef,
            'source_institution' => $source['institution'],
            'source_hold_reference' => $holdResult['hold_reference'] ?? null,
            'amount' => $netAmount,
            'currency' => $currency,
            'beneficiary_phone' => $cashoutData['beneficiary_phone'] ?? '',
            'action' => 'GENERATE_ATM_TOKEN'
        ], 'generate_atm_code');
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Cashout token generation failed");
        }
        
        $data = $result['data'] ?? [];
        $code = $data['pin'] ?? $data['atm_pin'] ?? null;
        
        if (!$code) {
            throw new RuntimeException("No withdrawal code received");
        }
        
        return [
            'generated_code' => $code,
            'token_reference' => $data['token_reference'] ?? null,
            'expires_at' => $data['expires_at'] ?? null
        ];
    }

    private function processDeposit(
        int $swapId, 
        string $swapRef, 
        array $source, 
        array $destination, 
        string $currency, 
        array $holdResult, 
        float $netAmount
    ): array {
        $destParticipant = $this->getParticipant($destination['institution']);
        
        $bankClient = new GenericBankClient($destParticipant);
        $result = $bankClient->transfer([
            'reference' => $swapRef,
            'source_institution' => $source['institution'],
            'source_hold_reference' => $holdResult['hold_reference'] ?? null,
            'amount' => $netAmount,
            'currency' => $currency,
            'destination_account' => $destination['beneficiary_account'] ?? $destination['beneficiary_wallet'] ?? null,
            'action' => 'PROCESS_DEPOSIT'
        ], 'deposit_direct');
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Deposit failed: " . ($result['message'] ?? 'Unknown error'));
        }
        
        return [];
    }

    private function processCardLoad(
        int $swapId, 
        string $swapRef, 
        array $destination, 
        string $currency, 
        array $holdResult, 
        float $netAmount
    ): array {
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardSuffix = $destination['card_suffix'] ?? null;
        if (!$cardSuffix) {
            throw new RuntimeException("card_suffix required for card_load");
        }
        
        $result = $this->cardService->loadCard([
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'card_suffix' => $cardSuffix,
            'amount' => $netAmount,
            'currency' => $currency
        ]);
        
        return ['card_details' => $result];
    }

    private function processCardIssuance(
        int $swapId, 
        string $swapRef, 
        array $destination, 
        string $currency, 
        array $holdResult, 
        float $netAmount
    ): array {
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardData = $destination['card'] ?? [];
        $cardholderName = $cardData['cardholder_name'] ?? $destination['beneficiary_name'] ?? 'Cardholder';
        
        $result = $this->cardService->issueCard([
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'cardholder_name' => $cardholderName,
            'initial_amount' => $netAmount,
            'currency' => $currency
        ]);
        
        return ['card_details' => $result];
    }

    private function storeFees(string $swapRef, array $feeCalculation, string $sourceInstitution, string $destinationInstitution, int $retryCount, ?string $originalSwapRef): void
    {
        $feeType = 'CASHOUT';
        if ($retryCount === 1) {
            $feeType = 'FREE_RETRY_CASHOUT';
        } elseif ($retryCount >= 2) {
            $feeType = 'PAID_RETRY_CASHOUT';
        }
        
        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_fee_collections 
            (swap_reference, fee_type, total_amount, currency, source_institution, destination_institution, 
             split_config, vat_amount, status, fee_breakdown, original_swap_ref, retry_count)
            VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, 'COLLECTED', ?::jsonb, ?, ?)
        ");
        
        $stmt->execute([
            $swapRef, $feeType, $feeCalculation['total_fees'], $this->config['currency'] ?? 'BWP',
            $sourceInstitution, $destinationInstitution,
            json_encode(['platform_percent' => 35, 'source_percent' => 15, 'destination_percent' => 50]),
            $feeCalculation['vat'], json_encode($feeCalculation), $originalSwapRef, $retryCount
        ]);
    }

    private function recordSwap(
        string $swapRef, 
        array $source, 
        array $destination, 
        string $sourceCurrency,
        string $destCurrency,
        array $verification,
        ?array $fxContext,
        ?string $originalSwapRef,
        int $retryCount
    ): int {
        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_requests 
            (swap_uuid, from_currency, to_currency, amount, source_details, destination_details,
             source_currency, destination_currency, exchange_rate, fx_quote_uuid, status, 
             original_swap_ref, retry_count, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
            RETURNING swap_id
        ");
        
        $stmt->execute([
            $swapRef, $sourceCurrency, $destCurrency, $source['amount'],
            json_encode(['institution' => $source['institution'], 'asset_type' => $source['asset_type']]),
            json_encode(['institution' => $destination['institution'], 'delivery_mode' => $destination['delivery_mode'] ?? 'deposit']),
            $sourceCurrency, $destCurrency, $fxContext['rate'] ?? null, $fxContext['quote_uuid'] ?? null,
            $originalSwapRef, $retryCount
        ]);
        
        return (int)$stmt->fetchColumn();
    }

    private function formatPhoneForInstitution(?string $phone, array $participant): ?string
    {
        if (!$phone) return null;
        
        $format = $participant['phone_format'] ?? null;
        if (!$format) return $phone;
        
        $digits = preg_replace('/\D/', '', $phone);
        $countryCode = $format['country_code'] ?? '';
        
        if ($countryCode && ($format['always_add_country_code'] ?? false)) {
            if (!str_starts_with($digits, $countryCode)) {
                return $countryCode . $digits;
            }
        }
        return $digits;
    }

    private function sanitizePhones(array $payload, array $participant): array
    {
        foreach (self::PHONE_FIELDS as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = $this->formatPhoneForInstitution($payload[$field], $participant);
            }
            foreach (['ewallet', 'wallet', 'cashout', 'voucher'] as $nested) {
                if (isset($payload[$nested][$field])) {
                    $payload[$nested][$field] = $this->formatPhoneForInstitution($payload[$nested][$field], $participant);
                }
            }
        }
        return $payload;
    }

    private function buildResponse(string $swapRef, array $holdResult, array $result, ?array $fxContext, bool $isFreeRetry, bool $isPaidRetry, array $atmNotes = []): array
    {
        $response = [
            'status' => 'success',
            'swap_reference' => $swapRef,
            'hold_reference' => $holdResult['hold_reference'] ?? null
        ];
        
        if ($isFreeRetry) {
            $response['is_free_retry'] = true;
            $response['message'] = 'Free retry (VouchMorph pays generate code fee)';
        } elseif ($isPaidRetry) {
            $response['is_paid_retry'] = true;
            $response['message'] = 'Paid retry (client pays generate code fee)';
        }
        
        if ($fxContext && $fxContext['needs_fx']) {
            $response['fx'] = [
                'source_currency' => $fxContext['source_currency'],
                'destination_currency' => $fxContext['destination_currency'],
                'exchange_rate' => $fxContext['rate'],
                'source_amount' => $fxContext['source_amount'],
                'destination_amount' => $fxContext['destination_amount']
            ];
        }
        
        if (isset($result['generated_code'])) {
            $response['withdrawal_code'] = $result['generated_code'];
            $response['expires_at'] = $result['expires_at'] ?? null;
        }
        
        if (!empty($atmNotes)) {
            $response['dispensed_notes'] = $atmNotes;
        }
        
        if (isset($result['undispensed_amount'])) {
            $response['undispensed_amount'] = $result['undispensed_amount'];
            $response['undispensed_note'] = 'Remaining funds stay in source account';
        }
        
        if (isset($result['card_details'])) {
            $response['card_details'] = $result['card_details'];
        }
        
        return $response;
    }

    private function logEvent(string $msgId, string $phase, array $data): void
    {
        $logEntry = json_encode([
            'timestamp' => date('c'), 
            'msg_id' => $msgId, 
            'phase' => $phase, 
            'details' => $data
        ]);
        file_put_contents(self::LOG_FILE, $logEntry . PHP_EOL, FILE_APPEND);
    }
}
