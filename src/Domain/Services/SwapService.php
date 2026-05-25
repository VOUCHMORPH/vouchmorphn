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
 * SwapService - Orchestrates swaps using dedicated services
 * 
 * Delegates to:
 * - FeeService: All fee calculations
 * - ForexService: FX rates and profit
 * - HybridSettlementStrategy: Settlement messaging
 * - CardService: Card operations
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

    private const HOLD_EXPIRY_HOURS = 24;
    private const MESSAGE_CARD_EXPIRY_DAYS = 30;
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
        
        // Load participants
        $this->participants = $config['participants'] ?? [];
        $this->participants = array_change_key_case($this->participants, CASE_LOWER);
        
        // Initialize fee service (delegates all fee logic)
        $this->feeService = new FeeService($config['fees'] ?? [], $config['currency'] ?? 'BWP');
        
        // Initialize settlement strategy
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        
        // Initialize forex service
        $this->forexService = new ForexService($this->swapDB, $config, $this->participants, $this->feeService);
        
        // Initialize card service
        $this->cardService = new CardService($this->swapDB, $this->countryCode, $this->participants['vouchmorph'] ?? []);
        
        // Initialize SMS if configured
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
        
        try {
            // Validate and extract payload
            $source = $payload['source'];
            $destination = $payload['destination'];
            
            // Find participants
            $sourceParticipant = $this->getParticipant($source['institution']);
            $destParticipant = $this->getParticipant($destination['institution']);
            
            // Sanitize phones
            $source = $this->sanitizePhones($source, $sourceParticipant);
            
            // Step 1: Verify source asset (delegates to GenericBankClient)
            $verification = $this->verifySourceAsset($swapRef, $source, $sourceParticipant);
            if (!$verification['verified']) {
                throw new RuntimeException($verification['message']);
            }
            
            $sourceCurrency = $verification['currency'] ?? $source['currency'] ?? 'BWP';
            $destCurrency = $this->getDestinationCurrency($destination, $destParticipant);
            $sourceAmount = (float)$source['amount'];
            
            // Step 2: Handle FX if needed (delegates to ForexService)
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
            
            // Step 3: Place hold on source (delegates to GenericBankClient)
            $holdResult = $this->placeHold($swapRef, $source, $verification, $sourceParticipant, $destination);
            
            // Step 4: Calculate ALL fees (DELEGATED TO FEE SERVICE)
            $feeCalculation = $this->feeService->calculateAllFees(
                $creditAmount,
                $this->getTransactionType($destination),
                $sourceCurrency,
                $destCurrency,
                $this->getInstitutionCountry($source['institution']),
                $this->getInstitutionCountry($destination['institution'])
            );
            
            $netAmount = $feeCalculation['net_amount'];
            
            // Step 5: Record swap in database
            $swapId = $this->recordSwap($swapRef, $source, $destination, $sourceCurrency, $destCurrency, $verification, $this->fxContext);
            
            // Step 6: Store fee record
            $this->storeFees($swapRef, $feeCalculation, $source['institution'], $destination['institution']);
            
            // Step 7: Process destination (cashout, deposit, card)
            $result = $this->processDestination($swapId, $swapRef, $source, $destination, $destCurrency, $holdResult, $netAmount);
            
            // Step 8: Debit source (unless message-based card)
            if (!$this->shouldSkipDebit($destination)) {
                $this->debitSource($swapRef, $source, $holdResult, $sourceParticipant);
            }
            
            // Step 9: Track FX profit if applicable
            if ($this->fxContext && $this->fxContext['needs_fx']) {
                $this->trackFxProfit($swapRef, $sourceCurrency, $destCurrency, $sourceAmount);
            }
            
            $this->swapDB->commit();
            
            return $this->buildResponse($swapRef, $holdResult, $result, $this->fxContext);
            
        } catch (Exception $e) {
            $this->swapDB->rollBack();
            $this->releaseHoldIfNeeded($holdResult ?? null);
            return ['status' => 'error', 'message' => $e->getMessage(), 'swap_reference' => $swapRef];
        }
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
     * Find institution key in participants array
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
     * Get transaction type from destination
     */
    private function getTransactionType(array $destination): string
    {
        $deliveryMode = $destination['delivery_mode'] ?? 'deposit';
        return match ($deliveryMode) {
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
     * Check if debit should be skipped (message-based card)
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
     * Verify source asset (calls bank API)
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
        
        // Add identifier based on asset type
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
        return [
            'verified' => true,
            'currency' => $data['currency'] ?? null,
            'asset_details' => $data
        ];
    }

    /**
     * Place hold on source asset
     */
    private function placeHold(string $swapRef, array $source, array $verification, array $participant, array $destination): array
    {
        $bankClient = new GenericBankClient($participant);
        
        $payload = [
            'reference' => $swapRef,
            'asset_type' => $source['asset_type'],
            'amount' => $source['amount'],
            'expiry_hours' => self::HOLD_EXPIRY_HOURS
        ];
        
        $result = $bankClient->placeHold($payload);
        
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
    private function debitSource(string $swapRef, array $source, array $holdResult, array $participant): void
    {
        $bankClient = new GenericBankClient($participant);
        
        $result = $bankClient->debitHold([
            'reference' => $swapRef,
            'hold_reference' => $holdResult['hold_reference'],
            'amount' => $source['amount']
        ]);
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Debit failed: " . ($result['message'] ?? 'Unknown error'));
        }
    }

    /**
     * Release hold if needed (on failure)
     */
    private function releaseHoldIfNeeded(?array $holdResult): void
    {
        if (!$holdResult || !($holdResult['hold_placed'] ?? false)) {
            return;
        }
        // Hold release would happen via bank client, but we log it
        $this->logEvent('HOLD_NOT_RELEASED', ['hold_reference' => $holdResult['hold_reference'] ?? 'unknown']);
    }

    /**
     * Process destination based on delivery mode
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
        $deliveryMode = $destination['delivery_mode'] ?? 'deposit';
        
        return match ($deliveryMode) {
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
        
        // Format phone for destination institution
        if (isset($cashoutData['beneficiary_phone'])) {
            $cashoutData['beneficiary_phone'] = $this->formatPhoneForInstitution($cashoutData['beneficiary_phone'], $destParticipant);
        }
        
        // Request token from destination bank
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
        
        // Send SMS with code
        if ($this->smsService && ($cashoutData['beneficiary_phone'] ?? false)) {
            $this->smsService->send(
                $cashoutData['beneficiary_phone'],
                "Your withdrawal code: {$code}\nAmount: {$netAmount} {$currency}"
            );
        }
        
        // Queue settlement
        $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'cashout', $currency);
        
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
        
        $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'deposit', $currency);
        
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
    private function storeFees(string $swapRef, array $feeCalculation, string $sourceInstitution, string $destinationInstitution): void
    {
        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_fee_collections 
            (swap_reference, fee_type, total_amount, currency, source_institution, destination_institution, 
             split_config, vat_amount, status, fee_breakdown)
            VALUES (?, 'COMPREHENSIVE', ?, ?, ?, ?, ?::jsonb, ?, 'COLLECTED', ?::jsonb)
        ");
        
        $stmt->execute([
            $swapRef,
            $feeCalculation['total_fees'],
            $this->config['currency'] ?? 'BWP',
            $sourceInstitution,
            $destinationInstitution,
            json_encode(['platform_percent' => 35, 'source_percent' => 15, 'destination_percent' => 50]),
            $feeCalculation['vat'],
            json_encode($feeCalculation)
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
        ?array $fxContext
    ): int {
        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_requests 
            (swap_uuid, from_currency, to_currency, amount, source_details, destination_details,
             source_currency, destination_currency, exchange_rate, fx_quote_uuid, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            RETURNING swap_id
        ");
        
        $stmt->execute([
            $swapRef,
            $sourceCurrency,
            $destCurrency,
            $source['amount'],
            json_encode(['institution' => $source['institution'], 'asset_type' => $source['asset_type']]),
            json_encode(['institution' => $destination['institution'], 'delivery_mode' => $destination['delivery_mode'] ?? 'deposit']),
            $sourceCurrency,
            $destCurrency,
            $fxContext['rate'] ?? null,
            $fxContext['quote_uuid'] ?? null
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
            
            $stmt->execute([
                $swapRef,
                "{$sourceCurrency}/{$destCurrency}",
                $wholesaleRate,
                $clientRate,
                $profitPerUnit,
                $amount,
                $amount * $profitPerUnit
            ]);
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
     * Sanitize phones in payload
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
    private function buildResponse(string $swapRef, array $holdResult, array $result, ?array $fxContext): array
    {
        $response = [
            'status' => 'success',
            'swap_reference' => $swapRef,
            'hold_reference' => $holdResult['hold_reference'] ?? null
        ];
        
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
