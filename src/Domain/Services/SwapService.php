<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\SMS\SmsNotificationService;

/**
 * SwapService - Orchestrates swaps
 * 
 * Responsibilities:
 * - Verify source asset
 * - Place hold on source
 * - Deduct FULL fee amount from transaction
 * - Process destination (cashout, deposit, card)
 * - Track retry attempts for cashout
 * - Debit source
 * 
 * Fee splitting and settlement accounting is handled by HybridSettlementStrategy
 */
class SwapService
{
    private PDO $swapDB;
    private array $config;
    private array $participants;
    private HybridSettlementStrategy $settlement;
    private ?SmsNotificationService $smsService = null;
    private ?CardService $cardService = null;
    private ?ForexService $forexService = null;
    private ?FeeService $feeService = null;
    private ?array $fxContext = null;
    private array $atmNotes = [];

    private const HOLD_EXPIRY_HOURS = 24;
    private const LOG_FILE = '/tmp/vouchmorphn_swap_audit.log';

    private const PHONE_FIELDS = [
        'phone', 'wallet_phone', 'ewallet_phone', 'card_phone',
        'claimant_phone', 'beneficiary_phone', 'account_phone'
    ];

    public function __construct(
        PDO $swapDB, 
        array $settings, 
        string $country, 
        string $encryptionKey, 
        array $config
    ) {
        $this->swapDB = $swapDB;
        $this->config = $config;
        $this->countryCode = strtoupper($country);
        
        $this->participants = $config['participants'] ?? [];
        $this->participants = array_change_key_case($this->participants, CASE_LOWER);
        
        $this->atmNotes = $config['atm_notes'] ?? ['BWP' => [10, 20, 50, 100, 200]];
        $this->feeService = new FeeService($config['fees'] ?? [], $config['currency'] ?? 'BWP');
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        $this->forexService = new ForexService($this->swapDB, $config, $this->participants, $this->feeService);
        $this->cardService = new CardService($this->swapDB, $this->countryCode, $this->participants['vouchmorph'] ?? []);
        
        if (isset($config['communication']['sms_gateway']['enabled']) && $config['communication']['sms_gateway']['enabled']) {
            $this->smsService = new SmsNotificationService($this->swapDB, $config['communication']['sms_gateway']);
        }
    }

    /**
     * Main entry point - Execute a swap
     */
    public function executeSwap(array $payload): array
    {
        $this->swapDB->beginTransaction();
        $swapRef = bin2hex(random_bytes(16));
        $originalSwapRef = $payload['original_swap_reference'] ?? null;
        $isRetry = !empty($originalSwapRef);
        
        try {
            $source = $payload['source'];
            $destination = $payload['destination'];
            $isCashout = ($destination['delivery_mode'] ?? 'deposit') === 'cashout';
            
            $sourceParticipant = $this->getParticipant($source['institution']);
            $destParticipant = $this->getParticipant($destination['institution']);
            
            $source = $this->sanitizePhones($source, $sourceParticipant);
            
            // STEP 1: Verify source asset
            $verification = $this->verifySourceAsset($swapRef, $source, $sourceParticipant);
            if (!$verification['verified']) {
                throw new RuntimeException($verification['message']);
            }
            
            $sourceCurrency = $verification['currency'] ?? $source['currency'] ?? 'BWP';
            $destCurrency = $this->getDestinationCurrency($destination, $destParticipant);
            $sourceAmount = (float)$source['amount'];
            
            // STEP 2: Handle FX if needed
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
            
            // STEP 3: Place hold on source
            $holdResult = $this->placeHold($swapRef, $source, $sourceParticipant);
            
            // STEP 4: Calculate FULL fee (deduct entire amount at once)
            $feeCalculation = $this->feeService->calculateAllFees(
                $creditAmount,
                $this->getTransactionType($destination),
                $sourceCurrency,
                $destCurrency,
                $this->getInstitutionCountry($source['institution']),
                $this->getInstitutionCountry($destination['institution'])
            );
            
            $totalFees = $feeCalculation['total_fees'];
            $netAmount = $creditAmount - $totalFees;
            
            // STEP 5: For cashout, ensure net amount is ATM-dispensable
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
            
            // STEP 6: Record swap in database
            $swapId = $this->recordSwap($swapRef, $source, $destination, $sourceCurrency, $destCurrency, $verification, $this->fxContext, $isRetry, $originalSwapRef);
            
            // STEP 7: Store fee record
            $this->storeFees($swapRef, $feeCalculation, $source['institution'], $destination['institution'], $isRetry, $originalSwapRef);
            
            // STEP 8: Process destination
            $result = $this->processDestination($swapId, $swapRef, $source, $destination, $destCurrency, $holdResult, $dispensedAmount);
            
            // STEP 9: Handle undispensed remainder (stays in source account)
            if ($isCashout && $undispensedAmount > 0.01) {
                $this->handleUndispensedRemainder($swapRef, $undispensedAmount);
                $result['undispensed_amount'] = $undispensedAmount;
                $result['dispensed_notes'] = $atmNotes;
            }
            
            // STEP 10: Debit source (only the dispensed amount for cashout)
            $debitAmount = $isCashout ? $dispensedAmount : $sourceAmount;
            if (!$this->shouldSkipDebit($destination)) {
                $this->debitSource($swapRef, $holdResult, $sourceParticipant, $debitAmount);
            }
            
            // STEP 11: Notify settlement strategy about fee splits
            $this->notifySettlementStrategy($swapRef, $feeCalculation, $source['institution'], $destination['institution'], $isCashout);
            
            // STEP 12: If cashout succeeded and was a retry, mark original fee as used
            if ($isCashout && $isRetry) {
                $this->markRetryFeeAsUsed($originalSwapRef);
            }
            
            $this->swapDB->commit();
            
            return $this->buildResponse($swapRef, $holdResult, $result, $this->fxContext, $isRetry, $atmNotes);
            
        } catch (Exception $e) {
            $this->swapDB->rollBack();
            $this->releaseHoldIfNeeded($holdResult ?? null);
            
            // Track failed cashout for retry eligibility
            if (isset($destination) && $isCashout) {
                $this->recordFailedCashout($swapRef, $source, $e->getMessage(), $originalSwapRef);
            }
            
            return ['status' => 'error', 'message' => $e->getMessage(), 'swap_reference' => $swapRef];
        }
    }
    
    /**
     * Get dispensable amount based on ATM denominations
     */
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
        
        return [
            'dispensable_amount' => round($dispensableCents / 100, 2),
            'notes' => $dispensedNotes,
            'undispensed_amount' => round($remainingCents / 100, 2)
        ];
    }
    
    /**
     * Handle undispensed remainder
     */
    private function handleUndispensedRemainder(string $swapRef, float $amount): void
    {
        $stmt = $this->swapDB->prepare("
            UPDATE swap_requests 
            SET undispensed_amount = :amount,
                metadata = metadata || jsonb_build_object('undispensed', :amount)
            WHERE swap_uuid = :swap_ref
        ");
        $stmt->execute([':amount' => $amount, ':swap_ref' => $swapRef]);
        
        $this->logEvent($swapRef, 'UNDISPENSED_REMAINDER', [
            'amount' => $amount,
            'action' => 'Remaining funds stay in source account'
        ]);
    }
    
    /**
     * Notify settlement strategy about fee splits
     */
    private function notifySettlementStrategy(
        string $swapRef, 
        array $feeCalculation, 
        string $sourceInstitution, 
        string $destinationInstitution,
        bool $isCashout
    ): void {
        $feeKey = $this->getFeeKeyForTransaction($feeCalculation['transaction_type'] ?? 'DEPOSIT');
        $feeConfig = $this->config['fees']['fees'][$feeKey] ?? null;
        
        if (!$feeConfig) {
            $this->logEvent($swapRef, 'FEE_CONFIG_NOT_FOUND', ['fee_key' => $feeKey]);
            return;
        }
        
        $swapLevy = $feeConfig['swap_levy'] ?? 0;
        $afterLevy = $feeCalculation['total_fees'] - $swapLevy;
        
        $split = $feeConfig['split_after_levy'] ?? ['platform_percent' => 35, 'source_institution_percent' => 15, 'destination_institution_percent' => 50];
        
        $platformShare = $afterLevy * ($split['platform_percent'] / 100);
        $sourceShare = $afterLevy * ($split['source_institution_percent'] / 100);
        $destinationShare = $afterLevy * ($split['destination_institution_percent'] / 100);
        
        // VouchMorph gets platform share + swap levy
        $vouchmorphTotal = $swapLevy + $platformShare;
        if ($vouchmorphTotal > 0) {
            $this->settlement->invoiceFee($swapRef, $sourceInstitution, 0, 'VOUCHMORPH_FEE', $vouchmorphTotal, $this->config['currency'] ?? 'BWP');
        }
        
        // Source institution gets its share
        if ($sourceShare > 0) {
            $this->settlement->invoiceFee($swapRef, $sourceInstitution, 0, 'SOURCE_INSTITUTION_FEE', $sourceShare, $this->config['currency'] ?? 'BWP');
        }
        
        // For cashout, further split destination share
        if ($isCashout && isset($feeConfig['destination_split'])) {
            $generateCodeFee = $destinationShare * ($feeConfig['destination_split']['generate_code_fee_percent'] / 100);
            $cashoutFee = $destinationShare * ($feeConfig['destination_split']['cashout_fee_percent'] / 100);
            
            if ($generateCodeFee > 0) {
                $this->settlement->invoiceFee($swapRef, $destinationInstitution, 0, 'GENERATE_CODE_FEE', $generateCodeFee, $this->config['currency'] ?? 'BWP');
            }
            if ($cashoutFee > 0) {
                $this->settlement->invoiceFee($swapRef, $destinationInstitution, 0, 'CASHPUT_COMPLETION_FEE', $cashoutFee, $this->config['currency'] ?? 'BWP');
            }
        } else {
            // For deposit/card, destination gets full share
            if ($destinationShare > 0) {
                $this->settlement->invoiceFee($swapRef, $destinationInstitution, 0, 'DESTINATION_FEE', $destinationShare, $this->config['currency'] ?? 'BWP');
            }
        }
    }
    
    /**
     * Record failed cashout for retry eligibility
     */
    private function recordFailedCashout(string $swapRef, array $source, string $error, ?string $originalSwapRef): void
    {
        $clientIdentifier = $this->extractClientIdentifier($source);
        
        // Calculate the unearned cashout fee (only for first attempt)
        if (empty($originalSwapRef)) {
            // Get the fee configuration
            $feeConfig = $this->config['fees']['fees']['CASHOUT_SWAP_FEE'] ?? null;
            if ($feeConfig && isset($feeConfig['destination_split'])) {
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
        } else {
            // Update retry count
            $stmt = $this->swapDB->prepare("
                UPDATE cashout_retry_tracking 
                SET retry_count = retry_count + 1, last_error = :error, updated_at = NOW()
                WHERE client_identifier = :client AND original_swap_ref = :swap_ref
            ");
            $stmt->execute([
                ':client' => $clientIdentifier,
                ':swap_ref' => $originalSwapRef,
                ':error' => $error
            ]);
        }
        
        $this->logEvent($swapRef, 'FAILED_CASHOUT_RECORDED', [
            'client' => $clientIdentifier,
            'error' => $error,
            'is_retry' => !empty($originalSwapRef)
        ]);
    }
    
    /**
     * Mark retry fee as used
     */
    private function markRetryFeeAsUsed(?string $originalSwapRef): void
    {
        if (!$originalSwapRef) return;
        
        $stmt = $this->swapDB->prepare("
            UPDATE cashout_retry_tracking 
            SET used = true, used_at = NOW()
            WHERE original_swap_ref = :swap_ref AND used = false
        ");
        $stmt->execute([':swap_ref' => $originalSwapRef]);
    }
    
    /**
     * Extract client identifier from source
     */
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
        return 'unknown_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    
    /**
     * Get fee key for transaction type
     */
    private function getFeeKeyForTransaction(string $transactionType): string
    {
        return match ($transactionType) {
            'CASHOUT' => 'CASHOUT_SWAP_FEE',
            'CARD_LOAD' => 'CARD_LOAD_FEE',
            'CARD_ISSUANCE' => 'CARD_ISSUANCE_FEE',
            default => 'DEPOSIT_SWAP_FEE'
        };
    }
    
    /**
     * Get participant by institution name
     */
    private function getParticipant(string $institution): array
    {
        $key = $this->findInstitutionKey($institution);
        if (!$key || !isset($this->participants[$key])) {
            throw new RuntimeException("Institution not found: {$institution}");
        }
        return $this->participants[$key];
    }

    /**
     * Find institution key
     */
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

    /**
     * Get institution country
     */
    private function getInstitutionCountry(string $institution): string
    {
        $key = $this->findInstitutionKey($institution);
        if ($key && isset($this->participants[$key]['country_code'])) {
            return $this->participants[$key]['country_code'];
        }
        return $this->countryCode;
    }

    /**
     * Get transaction type
     */
    private function getTransactionType(array $destination): string
    {
        return match ($destination['delivery_mode'] ?? 'deposit') {
            'cashout' => 'CASHOUT',
            'card_load' => 'CARD_LOAD',
            'card' => 'CARD_ISSUANCE',
            default => 'DEPOSIT'
        };
    }

    /**
     * Get destination currency
     */
    private function getDestinationCurrency(array $destination, array $participant): string
    {
        if (isset($destination['currency']) && !empty($destination['currency'])) {
            return strtoupper($destination['currency']);
        }
        return $participant['default_currency'] ?? $this->config['currency'] ?? 'BWP';
    }

    /**
     * Check if debit should be skipped
     */
    private function shouldSkipDebit(array $destination): bool
    {
        $deliveryMode = $destination['delivery_mode'] ?? '';
        if (!in_array($deliveryMode, ['card_load', 'card'])) {
            return false;
        }
        $institution = $destination['institution'] ?? '';
        return strtoupper($institution) === 'VOUCHMORPH';
    }

    /**
     * Verify source asset
     */
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
        
        if ($assetType === 'E-WALLET' || $assetType === 'WALLET') {
            $phone = $source['ewallet_phone'] ?? $source['phone'] ?? null;
            if (!$phone) throw new RuntimeException("Phone required for {$assetType}");
            $payload['phone'] = $this->formatPhoneForInstitution($phone, $participant);
        } elseif ($assetType === 'ACCOUNT') {
            $payload['account_number'] = $source['account_number'] ?? null;
        } elseif ($assetType === 'CARD') {
            $payload['card_number'] = $source['card_number'] ?? null;
        }
        
        $result = $bankClient->verifyAsset($payload);
        
        if (!($result['success'] ?? false)) {
            return ['verified' => false, 'message' => $result['curl_error'] ?? 'Verification failed'];
        }
        
        $data = $result['data'] ?? [];
        return ['verified' => true, 'currency' => $data['currency'] ?? null, 'asset_details' => $data];
    }

    /**
     * Place hold on source asset
     */
    private function placeHold(string $swapRef, array $source, array $participant): array
    {
        $bankClient = new GenericBankClient($participant);
        
        $result = $bankClient->placeHold([
            'reference' => $swapRef,
            'asset_type' => $source['asset_type'],
            'amount' => $source['amount'],
            'expiry_hours' => self::HOLD_EXPIRY_HOURS
        ]);
        
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

    /**
     * Debit source funds
     */
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

    /**
     * Release hold if needed
     */
    private function releaseHoldIfNeeded(?array $holdResult): void
    {
        if (!$holdResult || !($holdResult['hold_placed'] ?? false)) {
            return;
        }
        $this->logEvent('HOLD_NOT_RELEASED', ['hold_reference' => $holdResult['hold_reference'] ?? 'unknown']);
    }

    /**
     * Process destination
     */
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

    /**
     * Process cashout
     */
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
        
        if ($this->smsService && ($cashoutData['beneficiary_phone'] ?? false)) {
            $this->smsService->send($cashoutData['beneficiary_phone'], "Your withdrawal code: {$code}\nAmount: {$netAmount} {$currency}");
        }
        
        return [
            'generated_code' => $code,
            'token_reference' => $data['token_reference'] ?? null,
            'expires_at' => $data['expires_at'] ?? null
        ];
    }

    /**
     * Process deposit
     */
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

    /**
     * Process card load
     */
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

    /**
     * Process card issuance
     */
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

    /**
     * Store fees in database
     */
    private function storeFees(string $swapRef, array $feeCalculation, string $sourceInstitution, string $destinationInstitution, bool $isRetry, ?string $originalSwapRef): void
    {
        $feeType = $isRetry ? 'RETRY_CASHOUT' : 'COMPREHENSIVE';
        
        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_fee_collections 
            (swap_reference, fee_type, total_amount, currency, source_institution, destination_institution, 
             split_config, vat_amount, status, fee_breakdown, original_swap_ref, is_retry)
            VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, 'COLLECTED', ?::jsonb, ?, ?)
        ");
        
        $stmt->execute([
            $swapRef, $feeType, $feeCalculation['total_fees'], $this->config['currency'] ?? 'BWP',
            $sourceInstitution, $destinationInstitution,
            json_encode(['platform_percent' => 35, 'source_percent' => 15, 'destination_percent' => 50]),
            $feeCalculation['vat'], json_encode($feeCalculation), $originalSwapRef, $isRetry
        ]);
    }

    /**
     * Record swap in database
     */
    private function recordSwap(
        string $swapRef, 
        array $source, 
        array $destination, 
        string $sourceCurrency,
        string $destCurrency,
        array $verification,
        ?array $fxContext,
        bool $isRetry,
        ?string $originalSwapRef
    ): int {
        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_requests 
            (swap_uuid, from_currency, to_currency, amount, source_details, destination_details,
             source_currency, destination_currency, exchange_rate, fx_quote_uuid, status, is_retry, original_swap_ref, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())
            RETURNING swap_id
        ");
        
        $stmt->execute([
            $swapRef, $sourceCurrency, $destCurrency, $source['amount'],
            json_encode(['institution' => $source['institution'], 'asset_type' => $source['asset_type']]),
            json_encode(['institution' => $destination['institution'], 'delivery_mode' => $destination['delivery_mode'] ?? 'deposit']),
            $sourceCurrency, $destCurrency, $fxContext['rate'] ?? null, $fxContext['quote_uuid'] ?? null,
            $isRetry, $originalSwapRef
        ]);
        
        return (int)$stmt->fetchColumn();
    }

    /**
     * Track FX profit
     */
    private function trackFxProfit(string $swapRef, string $sourceCurrency, string $destCurrency, float $amount): void
    {
        if (!$this->forexService) return;
        
        $wholesaleRate = $this->forexService->getWholesaleRate($sourceCurrency, $destCurrency);
        $clientRate = $this->fxContext['rate'] ?? 0;
        
        if ($wholesaleRate > 0 && $clientRate > 0) {
            $profitPerUnit = $wholesaleRate - $clientRate;
            
            $stmt = $this->swapDB->prepare("
                INSERT INTO fx_profit_records 
                (swap_reference, currency_pair, wholesale_rate, client_rate, profit_per_unit, amount, total_profit)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$swapRef, "{$sourceCurrency}/{$destCurrency}", $wholesaleRate, $clientRate, $profitPerUnit, $amount, $amount * $profitPerUnit]);
        }
    }

    /**
     * Format phone for institution
     */
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

    /**
     * Sanitize phones
     */
    private function sanitizePhones(array $payload, array $participant): array
    {
        foreach (self::PHONE_FIELDS as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = $this->formatPhoneForInstitution($payload[$field], $participant);
            }
            foreach (['ewallet', 'wallet', 'cashout'] as $nested) {
                if (isset($payload[$nested][$field])) {
                    $payload[$nested][$field] = $this->formatPhoneForInstitution($payload[$nested][$field], $participant);
                }
            }
        }
        return $payload;
    }

    /**
     * Build response
     */
    private function buildResponse(string $swapRef, array $holdResult, array $result, ?array $fxContext, bool $isRetry, array $atmNotes = []): array
    {
        $response = [
            'status' => 'success',
            'swap_reference' => $swapRef,
            'hold_reference' => $holdResult['hold_reference'] ?? null
        ];
        
        if ($isRetry) {
            $response['is_free_retry'] = true;
            $response['message'] = 'Free retry (swap-on-swap) applied';
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

    /**
     * Log event
     */
    private function logEvent(string $event, array $data): void
    {
        $entry = json_encode(['timestamp' => date('c'), 'event' => $event, 'data' => $data]);
        file_put_contents(self::LOG_FILE, $entry . PHP_EOL, FILE_APPEND);
    }
}
