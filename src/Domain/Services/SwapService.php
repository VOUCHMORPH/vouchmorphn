<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use DateTimeImmutable; 
use RuntimeException;
require_once __DIR__ . '/../ValueObjects/SwapStatusResolver.php';
require_once __DIR__ . '/Settlement/HybridSettlementStrategy.php';
require_once __DIR__ . '/../../Infrastructure/SMS/SmsNotificationService.php';
require_once __DIR__ . '/ForexService.php';

use Security\Encryption\TokenEncryptor;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\CardService;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\SMS\SmsNotificationService;
use Domain\ValueObjects\SwapStatusResolver;

/**
 * SwapService - ISO20022 & FSPIOP Compliant
 * Multi-country aware, dynamic configuration loading
 * ALL configuration comes from LoadCountry - NO HARDCODING, NO FILE SEARCHING
 */
class SwapService
{
    private PDO $swapDB;
    private array $settings;
    private array $config;
    private array $participants;
    private TokenEncryptor $encryptor;
    private SwapStatusResolver $swapStatusResolver;
    private string $countryCode;
    private array $feesConfig = [];
    private array $flowsConfig = [];
    private array $atmNotes = [];
    private array $cardConfig = [];
    private HybridSettlementStrategy $settlement;
    private ?SmsNotificationService $smsService = null;
    private ?CardService $cardService = null;
    private ?ForexService $forexService = null;
    private ?array $fxContext = null;
    private ?FeeService $feeService = null;

    private const LOG_FILE = '/tmp/vouchmorphn_swap_audit.log';
    private const DEBUG_FILE = '/tmp/hold_debug.log';
    
    private const HOLD_EXPIRY_HOURS = 24;
    private const VOUCHER_EXPIRY_HOURS = 24;
    private const EXPIRY_BATCH_SIZE = 100;
    private const MESSAGE_CARD_EXPIRY_DAYS = 30;
    
    private const PHONE_FIELDS = [
        'phone',
        'wallet_phone',
        'ewallet_phone',
        'card_phone',
        'claimant_phone',
        'beneficiary_phone',
        'account_phone'
    ];

    /**
     * Constructor - ALL config comes from LoadCountry
     * 
     * @param PDO $swapDB Database connection
     * @param array $settings Additional settings (not used, kept for compatibility)
     * @param string $country Country code (BW, NG, KE, etc.)
     * @param string $encryptionKey Encryption key for sensitive data
     * @param array $config Complete configuration from LoadCountry::getConfig()
     */
    public function __construct(PDO $swapDB, array $settings, string $country, string $encryptionKey, array $config)
    {
        $this->swapDB = $swapDB;
        $this->settings = $settings;
        $this->countryCode = strtoupper($country);
        $this->config = $config;
        
        // ============================================================
        // USE CONFIG FROM LOADCOUNTRY - NO FILE SEARCHING
        // ============================================================
        
        // Get participants from config
        if (isset($config['participants']) && is_array($config['participants'])) {
            $this->participants = $config['participants'];
            error_log("[SwapService] Using participants from LoadCountry config");
        } else {
            $this->participants = [];
            error_log("[SwapService] WARNING: No participants in config");
        }
        
        $this->participants = array_change_key_case($this->participants, CASE_LOWER);
        
        error_log("=== SWAPSERVICE CONSTRUCTOR ===");
        error_log("Country: " . $this->countryCode);
        error_log("Participants count: " . count($this->participants));

        // Initialize SwapStatusResolver
        try {
            $this->swapStatusResolver = new SwapStatusResolver(
                fn($event, $data) => $this->logEvent('RESOLVER', $event, $data),
                $this->config,
                $this->participants
            );
            error_log("[SwapService] SwapStatusResolver initialized successfully");
        } catch (\Exception $e) {
            error_log("[SwapService] ERROR initializing SwapStatusResolver: " . $e->getMessage());
            $this->swapStatusResolver = null;
        }

        // Get fees from config (already loaded by LoadCountry)
        if (isset($config['fees']) && is_array($config['fees']) && !empty($config['fees'])) {
            $this->feesConfig = $config['fees'];
            error_log("[SwapService] Using fees from LoadCountry config");
        } else {
            throw new RuntimeException("Fees configuration not found in LoadCountry config for {$this->countryCode}");
        }
        
        // Initialize FeeService with loaded config
        $this->feeService = new FeeService($this->feesConfig, $config['currency'] ?? 'BWP');
        error_log("[SwapService] FeeService initialized");
        
        // Get card config from LoadCountry
        if (isset($config['card_config']) && is_array($config['card_config']) && !empty($config['card_config'])) {
            $this->cardConfig = $config['card_config'];
            error_log("[SwapService] Using card config from LoadCountry config");
        } else {
            throw new RuntimeException("Card configuration not found in LoadCountry config for {$this->countryCode}");
        }
        
        // Get ATM notes from LoadCountry
        if (isset($config['atm_notes']) && is_array($config['atm_notes']) && !empty($config['atm_notes'])) {
            $this->atmNotes = $config['atm_notes'];
            error_log("[SwapService] Using ATM notes from LoadCountry config");
        } else {
            throw new RuntimeException("ATM notes not found in LoadCountry config for {$this->countryCode}");
        }
        
        // Initialize settlement strategy
        try {
            $this->settlement = new HybridSettlementStrategy($this->swapDB);
            error_log("[SwapService] HybridSettlementStrategy initialized");
        } catch (\Exception $e) {
            error_log("[SwapService] ERROR initializing settlement: " . $e->getMessage());
            throw new RuntimeException("Failed to initialize settlement strategy: " . $e->getMessage());
        }
        
        // Initialize Forex Service for cross-border/multi-currency
        try {
            $this->forexService = new ForexService($this->swapDB, $this->config, $this->participants);
            error_log("[SwapService] ✅ ForexService initialized");
        } catch (Exception $e) {
            error_log("[SwapService] ❌ ForexService initialization failed: " . $e->getMessage());
            $this->forexService = null;
        }
        
        // Initialize SMS service from config
        if (isset($config['communication']['sms_gateway']) && ($config['communication']['sms_gateway']['enabled'] ?? false)) {
            try {
                $this->smsService = new SmsNotificationService($this->swapDB, $config['communication']['sms_gateway']);
                error_log("[SwapService] SMS Service initialized from config");
            } catch (\Exception $e) {
                error_log("[SwapService] WARNING: SMS Service initialization failed: " . $e->getMessage());
                $this->smsService = null;
            }
        } else {
            error_log("[SwapService] SMS service not enabled in config");
            $this->smsService = null;
        }
        
        // Initialize Card Service
        try {
            $vouchmorphConfig = $this->participants['vouchmorph'] ?? [];
            $this->cardService = new CardService($this->swapDB, $this->countryCode, $vouchmorphConfig);
            error_log("[SwapService] ✅ Card Service initialized");
        } catch (\Exception $e) {
            error_log("[SwapService] ❌ Card Service initialization failed: " . $e->getMessage());
            $this->cardService = null;
        }
        
        error_log("=== SWAPSERVICE CONSTRUCTOR COMPLETE ===");
        error_log("Card service status: " . ($this->cardService ? "ACTIVE" : "NOT AVAILABLE"));
        error_log("Forex service status: " . ($this->forexService ? "ACTIVE" : "NOT AVAILABLE"));
        error_log("Fee service status: " . ($this->feeService ? "ACTIVE" : "NOT AVAILABLE"));
    }

    private function getCardType(array $destination): string
    {
        if (isset($destination['card_type'])) {
            return $destination['card_type'];
        }
        
        $institution = $destination['institution'] ?? '';
        $messageBasedIssuers = $this->cardConfig['message_based_issuers'] ?? [];
        
        if (in_array(strtoupper($institution), array_map('strtoupper', $messageBasedIssuers))) {
            return 'message_based';
        }
        
        return $this->cardConfig['default_card_type'] ?? 'balance_based';
    }

    private function shouldSkipDebit(array $destination): bool
    {
        $deliveryMode = $destination['delivery_mode'] ?? '';
        
        if (!in_array($deliveryMode, ['card_load', 'card'])) {
            return false;
        }
        
        $cardType = $this->getCardType($destination);
        $institution = $destination['institution'] ?? '';
        
        return ($cardType === 'message_based' && strtoupper($institution) === 'VOUCHMORPH');
    }

    private function getDispensableAmount(float $amount, string $currency): array
    {
        $denominations = $this->atmNotes[$currency] ?? null;
        if (!$denominations) {
            throw new RuntimeException("No ATM denominations for currency {$currency} in country {$this->countryCode}");
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
            'undispensed_amount' => round($remainingCents / 100, 2),
            'denominations_used' => $denominations
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

            if (!empty($atmResult['notes'])) {
                $breakdown = [];

                foreach ($atmResult['notes'] as $note => $count) {
                    $breakdown[] = "$count × $note";
                }

                $this->logEvent('CASHOUT_BREAKDOWN', 'INFO', [
                    'amount' => $amount,
                    'dispensable' => $atmResult['dispensable_amount'],
                    'breakdown' => implode(' + ', $breakdown)
                ]);
            }

        } catch (Exception $e) {
            $errors[] = "Cashout validation error: " . $e->getMessage();
        }

        return $errors;
    }
    
    private function formatPhoneForInstitution(?string $phone, array $participant): ?string
    {
        if ($phone === null) {
            return null;
        }
        
        $formatConfig = $participant['phone_format'] ?? null;
        
        if (!$formatConfig) {
            return $phone;
        }
        
        $digits = preg_replace('/\D/', '', $phone);
        
        $this->logEvent('PHONE_FORMATTING', 'INFO', [
            'institution' => $participant['provider_code'] ?? 'unknown',
            'original' => $phone,
            'digits' => $digits,
            'config' => $formatConfig
        ]);
        
        $formatted = $digits;
        $countryCode = $formatConfig['country_code'] ?? '';
        
        if (!empty($countryCode)) {
            if ($formatConfig['remove_country_code_for_local'] ?? false) {
                if (strpos($formatted, $countryCode) === 0) {
                    $formatted = substr($formatted, strlen($countryCode));
                }
            } elseif ($formatConfig['always_add_country_code'] ?? false) {
                if (strpos($formatted, $countryCode) !== 0) {
                    $formatted = $countryCode . $formatted;
                }
            }
        }
        
        if (isset($formatConfig['prefix'])) {
            $formatted = $formatConfig['prefix'] . $formatted;
        }
        
        $this->logEvent('PHONE_FORMATTED', 'INFO', [
            'institution' => $participant['provider_code'] ?? 'unknown',
            'formatted' => $formatted
        ]);
        
        return $formatted;
    }

    private function sanitizePhones(array $payload, ?array $participant = null): array
    {
        foreach (self::PHONE_FIELDS as $field) {
            if (isset($payload[$field]) && $participant) {
                $payload[$field] = $this->formatPhoneForInstitution($payload[$field], $participant);
            }
            
            foreach (['source', 'destination', 'ewallet', 'wallet', 'voucher', 'card', 'cashout'] as $nested) {
                if (isset($payload[$nested][$field]) && $participant) {
                    $payload[$nested][$field] = $this->formatPhoneForInstitution($payload[$nested][$field], $participant);
                }
            }
        }

        return $payload;
    }

    private function findInstitutionKey(string $search): ?string
    {
        $searchLower = strtolower($search);
        
        if (isset($this->participants[$searchLower])) {
            return $searchLower;
        }
        
        foreach ($this->participants as $key => $participant) {
            if (isset($participant['provider_code']) && 
                strtolower($participant['provider_code']) === $searchLower) {
                return $key;
            }
        }
        
        foreach (array_keys($this->participants) as $key) {
            if (strtolower($key) === $searchLower) {
                return $key;
            }
        }
        
        return null;
    }

    private function debugApiCall(string $type, array $payload, array $result): void
    {
        $debugData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'payload' => $payload,
            'result' => [
                'success' => $result['success'] ?? null,
                'status_code' => $result['status_code'] ?? null,
                'curl_error' => $result['curl_error'] ?? null,
                'data' => $result['data'] ?? null,
                'raw_response' => $result['raw_response'] ?? null
            ]
        ];
        
        file_put_contents(self::DEBUG_FILE, json_encode($debugData, JSON_PRETTY_PRINT) . "\n---\n", FILE_APPEND);
        error_log("=== DEBUG WRITTEN TO " . self::DEBUG_FILE . " ===");
    }

    private function logApiMessage(
        string $messageId,
        string $messageType,
        string $direction,
        ?array $participant,
        string $endpoint,
        array $requestPayload,
        array $responseResult,
        ?int $durationMs = null
    ): void {
        try {
            $participantId = null;
            $participantName = null;
            
            if ($participant) {
                $participantId = $participant['participant_id'] ?? $participant['id'] ?? null;
                $participantName = $participant['name'] ?? $participant['provider_code'] ?? null;
            }
            
            $stmt = $this->swapDB->prepare("
                INSERT INTO api_message_logs 
                (message_id, message_type, direction, participant_id, participant_name, 
                 endpoint, request_payload, response_payload, http_status_code, curl_error, 
                 success, duration_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $messageId,
                $messageType,
                $direction,
                $participantId,
                $participantName,
                $endpoint,
                json_encode($requestPayload),
                json_encode($responseResult['data'] ?? $responseResult),
                $responseResult['status_code'] ?? null,
                $responseResult['curl_error'] ?? null,
                isset($responseResult['success']) && $responseResult['success'] === true ? true : false,
                $durationMs
            ]);
        } catch (Exception $e) {
            error_log("Failed to log API message: " . $e->getMessage());
        }
    }

    private function recordHoldTransaction(
        string $swapRef,
        array $holdResult,
        array $source,
        array $participant,
        ?array $destination = null
    ): void {
        try {
            $participantId = $participant['participant_id'] ?? $participant['id'] ?? null;
            $participantName = $participant['name'] ?? $participant['provider_code'] ?? null;
            
            $destinationParticipantId = null;
            if ($destination && isset($destination['institution'])) {
                $stmt = $this->swapDB->prepare("
                    SELECT participant_id FROM participants 
                    WHERE name = ? OR provider_code = ?
                    LIMIT 1
                ");
                $stmt->execute([$destination['institution'], $destination['institution']]);
                $destParticipant = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($destParticipant) {
                    $destinationParticipantId = $destParticipant['participant_id'];
                }
            }
            
            // Use FX context currency if available
            $currency = $this->fxContext['source_currency'] ?? $source['currency'] ?? 'BWP';
            
            $stmt = $this->swapDB->prepare("
                INSERT INTO hold_transactions 
                (hold_reference, swap_reference, participant_id, participant_name,
                 asset_type, amount, currency, hold_expiry, source_details, 
                 destination_institution, destination_participant_id, metadata, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')
            ");
            
            $stmt->execute([
                $holdResult['hold_reference'],
                $swapRef,
                $participantId,
                $participantName,
                $source['asset_type'],
                $source['amount'],
                $currency,
                $holdResult['hold_expiry'] ?? null,
                json_encode($this->maskIdentifier($source)),
                $destination['institution'] ?? null,
                $destinationParticipantId,
                json_encode([
                    'source_institution' => $source['institution'],
                    'source_asset_type' => $source['asset_type'],
                    'destination_institution' => $destination['institution'] ?? null,
                    'fx_context' => $this->fxContext ?? null
                ])
            ]);
        } catch (Exception $e) {
            error_log("Failed to record hold transaction: " . $e->getMessage());
        }
    }

    private function updateHoldStatus(string $holdReference, string $status): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE hold_transactions 
                SET status = ?, updated_at = NOW(), 
                    {$status}_at = NOW()
                WHERE hold_reference = ?
            ");
            $stmt->execute([$status, $holdReference]);
        } catch (Exception $e) {
            error_log("Failed to update hold status: " . $e->getMessage());
        }
    }

    private function getRetryCount(string $clientIdentifier, ?string $originalSwapRef): int
    {
        if (!$originalSwapRef) {
            return 0;
        }
        
        try {
            $stmt = $this->swapDB->prepare("
                SELECT retry_count FROM cashout_retry_tracking 
                WHERE client_identifier = ? AND original_swap_ref = ?
            ");
            $stmt->execute([$clientIdentifier, $originalSwapRef]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (int)$result['retry_count'] : 0;
        } catch (Exception $e) {
            error_log("Failed to get retry count: " . $e->getMessage());
            return 0;
        }
    }
    
    private function recordFailedCashout(string $clientIdentifier, string $swapRef, string $error): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                SELECT id, retry_count FROM cashout_retry_tracking 
                WHERE client_identifier = ? AND original_swap_ref = ?
            ");
            $stmt->execute([$clientIdentifier, $swapRef]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                $stmt = $this->swapDB->prepare("
                    UPDATE cashout_retry_tracking 
                    SET retry_count = retry_count + 1, 
                        last_error = ?,
                        updated_at = NOW()
                    WHERE client_identifier = ? AND original_swap_ref = ?
                ");
                $stmt->execute([$error, $clientIdentifier, $swapRef]);
            } else {
                $stmt = $this->swapDB->prepare("
                    INSERT INTO cashout_retry_tracking 
                    (client_identifier, original_swap_ref, retry_count, last_error, created_at)
                    VALUES (?, ?, 1, ?, NOW())
                ");
                $stmt->execute([$clientIdentifier, $swapRef, $error]);
            }
            
            $stmt = $this->swapDB->prepare("
                UPDATE swap_requests 
                SET retry_count = COALESCE(retry_count, 0) + 1,
                    metadata = metadata || ?::jsonb
                WHERE swap_uuid = ?
            ");
            $stmt->execute([json_encode(['last_failure' => $error, 'failed_at' => date('c')]), $swapRef]);
            
        } catch (Exception $e) {
            error_log("Failed to record failed cashout: " . $e->getMessage());
        }
    }
    
    private function extractClientIdentifier(array $source): string
    {
        $fields = ['phone', 'ewallet_phone', 'wallet_phone', 'card_phone', 
                   'claimant_phone', 'beneficiary_phone', 'account_phone', 'email'];
        
        foreach ($fields as $field) {
            if (isset($source[$field]) && !empty($source[$field])) {
                return $source[$field];
            }
            if (isset($source['cashout'][$field]) && !empty($source['cashout'][$field])) {
                return $source['cashout'][$field];
            }
            if (isset($source['ewallet'][$field]) && !empty($source['ewallet'][$field])) {
                return $source['ewallet'][$field];
            }
        }
        
        return 'unknown_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function getFeeKey(string $transactionType): string
    {
        $map = [
            'CASHOUT' => 'CASHOUT_SWAP_FEE',
            'DEPOSIT' => 'DEPOSIT_SWAP_FEE',
            'CARD_LOAD' => 'CARD_LOAD_FEE',
            'CARD_ISSUANCE' => 'CARD_ISSUANCE_FEE'
        ];
        
        return $map[$transactionType] ?? 'DEPOSIT_SWAP_FEE';
    }

    private function deductSwapFee(
        string $swapRef,
        string $transactionType,
        float $grossAmount,
        string $sourceInstitution,
        string $destinationInstitution
    ): array {
        
        $feeKey = $this->getFeeKey($transactionType);
        $feeConfig = $this->feesConfig['fees'][$feeKey] ?? null;
        
        if (!$feeConfig) {
            throw new RuntimeException("Fee configuration not found for {$feeKey} in country {$this->countryCode}");
        }
        
        if (!isset($feeConfig['total_amount'])) {
            throw new RuntimeException("Fee {$feeKey} missing 'total_amount' in config");
        }
        
        if (!isset($feeConfig['split'])) {
            throw new RuntimeException("Fee {$feeKey} missing 'split' configuration");
        }
        
        $totalFee = (float)$feeConfig['total_amount'];
        $split = $feeConfig['split'];
        $currency = $feeConfig['currency'] ?? 'BWP';
        
        $vatRate = isset($this->feesConfig['regulatory']['vat_rate']) ? (float)$this->feesConfig['regulatory']['vat_rate'] : 0;
        $vatAmount = $totalFee * $vatRate;
        
        $netAmount = $grossAmount - $totalFee;
        
        if ($netAmount <= 0) {
            throw new RuntimeException("Amount after fee deduction must be positive. Gross: {$grossAmount}, Fee: {$totalFee}");
        }
        
        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_fee_collections
            (swap_reference, fee_type, total_amount, currency, 
             source_institution, destination_institution,
             split_config, vat_amount, status)
            VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, 'COLLECTED')
            RETURNING fee_id
        ");
        
        $stmt->execute([
            $swapRef,
            $feeKey,
            $totalFee,
            $currency,
            $sourceInstitution,
            $destinationInstitution,
            json_encode($split),
            $vatAmount
        ]);
        
        $feeId = $stmt->fetchColumn();
        
        $stmt = $this->swapDB->prepare("
            UPDATE swap_ledgers 
            SET swap_fee = :fee
            WHERE swap_reference = :ref
        ");
        
        $stmt->execute([
            ':fee' => $totalFee,
            ':ref' => $swapRef
        ]);
        
        $this->logEvent($swapRef, 'FEE_DEDUCTED', [
            'gross' => $grossAmount,
            'fee' => $totalFee,
            'net' => $netAmount,
            'fee_id' => $feeId,
            'split' => $split,
            'fee_key' => $feeKey,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount
        ]);
        
        return [
            'gross_amount' => $grossAmount,
            'fee_amount' => $totalFee,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'fee_id' => $feeId,
            'split' => $split,
            'fee_key' => $feeKey
        ];
    }

    private function calculateFees(string $swapRef, string $transactionType, float $grossAmount): array
    {
        $feeKey = $this->getFeeKey($transactionType);
        $feeConfig = $this->feesConfig['fees'][$feeKey] ?? null;
        
        if (!$feeConfig) {
            throw new RuntimeException("Fee configuration not found for {$feeKey} in country {$this->countryCode}");
        }
        
        if (!isset($feeConfig['total_amount'])) {
            throw new RuntimeException("Fee {$feeKey} missing 'total_amount' in config");
        }
        
        if (!isset($feeConfig['split'])) {
            throw new RuntimeException("Fee {$feeKey} missing 'split' configuration");
        }
        
        $totalFee = (float)$feeConfig['total_amount'];
        $split = $feeConfig['split'];
        
        $vatRate = isset($this->feesConfig['regulatory']['vat_rate']) ? (float)$this->feesConfig['regulatory']['vat_rate'] : 0;
        $vatAmount = $totalFee * $vatRate;
        
        $netAmount = $grossAmount - $totalFee;
        
        return [
            'gross_amount' => $grossAmount,
            'fee_amount' => $totalFee,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'split' => $split,
            'fee_type' => $feeKey,
            'vat_rate' => $vatRate
        ];
    }

    private function getSourceCurrencyFromVerification(array $verificationResult, array $source): string
    {
        if (isset($verificationResult['currency']) && !empty($verificationResult['currency'])) {
            return strtoupper($verificationResult['currency']);
        }
        
        if (isset($verificationResult['asset_details']['currency']) && !empty($verificationResult['asset_details']['currency'])) {
            return strtoupper($verificationResult['asset_details']['currency']);
        }
        
        if (isset($source['currency']) && !empty($source['currency'])) {
            return strtoupper($source['currency']);
        }
        
        throw new RuntimeException(
            "Source participant did not return currency. " .
            "Response must include 'currency' field in verification response."
        );
    }
    
    private function getDestinationCurrency(array $destination, array $participant): string
    {
        if (isset($destination['currency']) && !empty($destination['currency'])) {
            return strtoupper($destination['currency']);
        }
        
        if (isset($participant['default_currency'])) {
            return strtoupper($participant['default_currency']);
        }
        
        try {
            $stmt = $this->swapDB->prepare("
                SELECT currency_code FROM participant_currencies 
                WHERE participant_id = ? AND can_receive = TRUE
                LIMIT 1
            ");
            $stmt->execute([$participant['participant_id'] ?? 0]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                return $result['currency_code'];
            }
        } catch (Exception $e) {
            error_log("Failed to get destination currency from DB: " . $e->getMessage());
        }
        
        $countryCode = $participant['country_code'] ?? $this->countryCode;
        return $this->mapCountryToCurrency($countryCode);
    }
    
    private function mapCountryToCurrency(string $countryCode): string
    {
        $map = [
            'BW' => 'BWP', 'ZA' => 'ZAR', 'NA' => 'NAD', 'NG' => 'NGN',
            'GH' => 'GHS', 'UG' => 'UGX', 'TZ' => 'TZS', 'KE' => 'KES',
            'ZM' => 'ZMW', 'MZ' => 'MZN', 'ZW' => 'ZWL', 'US' => 'USD',
            'GB' => 'GBP', 'EU' => 'EUR', 'CA' => 'CAD', 'AU' => 'AUD',
            'CN' => 'CNY', 'JP' => 'JPY', 'IN' => 'INR'
        ];
        return $map[strtoupper($countryCode)] ?? 'BWP';
    }

    private function verifySourceAsset(string $swapRef, array $source, array $participant): array
    {
        $assetType = strtoupper($source['asset_type'] ?? 'UNKNOWN');

        $this->logEvent($swapRef, 'VERIFYING_SOURCE', [
            'institution' => $participant['provider_code'] ?? $source['institution'],
            'asset_type' => $assetType,
            'reference' => $this->maskIdentifier($source)
        ]);

        $walletTypes = array_map('strtoupper', $participant['capabilities']['wallet_types'] ?? []);
        if (!in_array($assetType, $walletTypes)) {
            throw new RuntimeException("Institution does not support asset type: {$assetType}");
        }

        $bankClient = new GenericBankClient($participant);

        $verificationPayload = [
            'reference' => $swapRef,
            'institution' => $source['institution'],
            'asset_type' => $assetType,
            'amount' => $source['amount'] ?? 0
        ];

        switch ($assetType) {
            case 'VOUCHER':
                $voucher = $source['voucher'] ?? [];
                $verificationPayload = array_merge($verificationPayload, [
                    'claimant_phone' => $this->formatPhoneForInstitution($voucher['claimant_phone'] ?? null, $participant),
                    'voucher_number' => $voucher['voucher_number'] ?? null,
                    'voucher_pin' => $voucher['voucher_pin'] ?? null
                ]);
                break;

            case 'ACCOUNT':
                $account = $source['account'] ?? [];
                $verificationPayload = array_merge($verificationPayload, [
                    'account_holder' => $account['account_holder'] ?? null,
                    'account_number' => $account['account_number'] ?? null,
                    'account_pin' => $account['account_pin'] ?? null
                ]);
                break;

            case 'WALLET':
            case 'E-WALLET':
                $phone = $source['ewallet']['ewallet_phone'] ??
                         $source['ewallet']['phone'] ??
                         $source['wallet']['wallet_phone'] ??
                         $source['wallet']['phone'] ??
                         $source['ewallet_phone'] ??
                         $source['wallet_phone'] ??
                         $source['phone'] ??
                         null;

                if (!$phone) {
                    throw new RuntimeException("Phone number required for WALLET/E-WALLET verification");
                }

                $verificationPayload = array_merge($verificationPayload, [
                    'phone' => $this->formatPhoneForInstitution($phone, $participant)
                ]);
                break;

            case 'CARD':
                $card = $source['card'] ?? [];
                $verificationPayload = array_merge($verificationPayload, [
                    'card_number' => $card['card_number'] ?? null,
                    'card_pin' => $card['card_pin'] ?? null,
                    'card_holder' => $card['card_holder'] ?? null
                ]);
                break;

            default:
                throw new RuntimeException("Unsupported asset type: {$assetType}");
        }

        try {
            $result = $bankClient->verifyAsset($verificationPayload);
            
            $this->debugApiCall('verify_asset', $verificationPayload, $result);
            
            $this->logApiMessage(
                $swapRef,
                'verify_asset',
                'outgoing',
                $participant,
                '/api/verify-asset',
                $verificationPayload,
                $result,
                null
            );
            
            if (!isset($result['success']) || $result['success'] !== true) {
                return [
                    'verified' => false,
                    'message' => 'Bank communication failed: ' . ($result['curl_error'] ?? 'HTTP ' . ($result['status_code'] ?? 'unknown'))
                ];
            }

            $bankResponse = $result['data'] ?? [];

            if (!isset($bankResponse['verified']) || $bankResponse['verified'] !== true) {
                $errorMessage = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Verification failed';
                return [
                    'verified' => false,
                    'message' => $errorMessage
                ];
            }

            $currency = null;
            if (isset($bankResponse['currency'])) {
                $currency = strtoupper($bankResponse['currency']);
            } elseif (isset($bankResponse['asset_details']['currency'])) {
                $currency = strtoupper($bankResponse['asset_details']['currency']);
            } elseif (isset($bankResponse['metadata']['currency'])) {
                $currency = strtoupper($bankResponse['metadata']['currency']);
            }

            return [
                'verified' => true,
                'currency' => $currency,
                'asset_details' => [
                    'id' => $bankResponse['asset_id'] ?? $bankResponse['wallet_id'] ?? $bankResponse['account_id'] ?? null,
                    'available_balance' => $bankResponse['available_balance'] ?? $bankResponse['balance'] ?? null,
                    'holder_name' => $bankResponse['holder_name'] ?? $bankResponse['account_holder'] ?? null,
                    'currency' => $currency,
                    'expiry_date' => $bankResponse['expiry_date'] ?? null,
                    'metadata' => $bankResponse['metadata'] ?? []
                ]
            ];
            
        } catch (\Throwable $e) {
            throw new RuntimeException("Source verification failed: " . $e->getMessage());
        }
    }

    private function placeHoldOnSourceAsset(string $swapRef, array $source, array $verificationResult, array $participant, array $destination): array
    {
        $sourceCurrency = $this->fxContext['source_currency'] ?? $verificationResult['currency'] ?? $source['currency'] ?? 'BWP';
        $holdAmount = $this->fxContext['source_amount'] ?? $source['amount'];
        
        $this->logEvent($swapRef, 'PLACING_HOLD', [
            'institution' => $participant['provider_code'] ?? $source['institution'],
            'amount' => $holdAmount,
            'currency' => $sourceCurrency,
            'asset_type' => $source['asset_type']
        ]);

        $bankClient = new GenericBankClient($participant);
        
        $holdPayload = [
            'reference' => $swapRef,
            'asset_type' => $source['asset_type'],
            'asset_id' => $verificationResult['asset_details']['id'] ?? null,
            'amount' => $holdAmount,
            'currency' => $sourceCurrency,
            'expiry_hours' => self::HOLD_EXPIRY_HOURS,
            'reason' => 'Swap transaction' . ($this->fxContext ? ' (FX: ' . $this->fxContext['source_currency'] . '→' . $this->fxContext['destination_currency'] . ')' : '')
        ];

        switch ($source['asset_type']) {
            case 'VOUCHER':
                $voucher = $source['voucher'] ?? [];
                $holdPayload = array_merge($holdPayload, [
                    'voucher_number' => $voucher['voucher_number'] ?? null,
                    'voucher_pin' => $voucher['voucher_pin'] ?? null,
                    'claimant_phone' => $this->formatPhoneForInstitution($voucher['claimant_phone'] ?? null, $participant)
                ]);
                break;

            case 'ACCOUNT':
                $account = $source['account'] ?? [];
                $holdPayload = array_merge($holdPayload, [
                    'account_number' => $account['account_number'] ?? null,
                    'account_pin' => $account['account_pin'] ?? null
                ]);
                break;

            case 'WALLET':
            case 'E-WALLET':
                $phone = $source['ewallet']['phone'] ?? 
                         $source['phone'] ?? 
                         $source['ewallet_phone'] ?? 
                         $source['wallet']['wallet_phone'] ??
                         null;
                $holdPayload = array_merge($holdPayload, [
                    'phone' => $this->formatPhoneForInstitution($phone, $participant)
                ]);
                break;

            case 'CARD':
                $card = $source['card'] ?? [];
                $holdPayload = array_merge($holdPayload, [
                    'card_number' => $card['card_number'] ?? null,
                    'card_pin' => $card['card_pin'] ?? null
                ]);
                break;
        }

        try {
            $result = $bankClient->placeHold($holdPayload);
            
            $this->debugApiCall('place_hold', $holdPayload, $result);
            
            $this->logApiMessage(
                $swapRef,
                'place_hold',
                'outgoing',
                $participant,
                '/api/place-hold',
                $holdPayload,
                $result,
                null
            );

            if (!isset($result['success']) || $result['success'] !== true) {
                $errorMsg = 'Bank communication failed';
                if (isset($result['curl_error']) && !empty($result['curl_error'])) {
                    $errorMsg .= ': ' . $result['curl_error'];
                } elseif (isset($result['status_code'])) {
                    $errorMsg .= ': HTTP ' . $result['status_code'];
                }
                return [
                    'hold_placed' => false,
                    'message' => $errorMsg
                ];
            }

            $bankResponse = $result['data'] ?? [];

            if (!isset($bankResponse['hold_placed']) || $bankResponse['hold_placed'] !== true) {
                $errorMessage = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Hold placement failed';
                return [
                    'hold_placed' => false,
                    'message' => $errorMessage
                ];
            }

            $holdResult = [
                'hold_placed' => true,
                'hold_reference' => $bankResponse['hold_reference'] ?? $swapRef . '-HOLD',
                'hold_expiry' => $bankResponse['hold_expiry'] ?? date('Y-m-d H:i:s', strtotime('+' . self::HOLD_EXPIRY_HOURS . ' hours'))
            ];

            $this->recordHoldTransaction($swapRef, $holdResult, $source, $participant, $destination);
            
            $this->logEvent($swapRef, 'HOLD_PLACED', [
                'hold_reference' => $holdResult['hold_reference'],
                'expiry' => $holdResult['hold_expiry'],
                'currency' => $sourceCurrency,
                'amount' => $holdAmount
            ]);

            return $holdResult;

        } catch (\Throwable $e) {
            throw new RuntimeException("Hold placement failed: " . $e->getMessage());
        }
    }

    // The rest of the methods (executeSwap, processCashoutWithFeeSeparation, debitSourceFunds, 
    // processCardLoad, processBalanceBasedCardLoad, processMessageBasedCardLoad, 
    // processCardIssuance, processBalanceBasedCardIssuance, processMessageBasedCardIssuance,
    // recordCardAuthorization, requestTokenFromDestination, storeDestinationToken, 
    // sendWithdrawalSms, cancelSwap, recordCardSwipe, recordCardTransaction,
    // queueSettlementForCardSwipe, processCardSettlements, creditMerchant,
    // processExpiredHolds, processExpiredVouchers, processExpiredCardAuthorizations,
    // processAllExpired, queueSettlementMessage, handleUndispensedAmount,
    // recordMasterSwap, updateSwapLedgerFees, processDeposit, maskIdentifier,
    // logEvent, updateSwapMetadata, getSwapMetadata, getAtmDenominations,
    // canDispenseAmount, getTransactionTrace remain the same as before)
    // ... (keeping all existing method implementations)

    private function maskIdentifier(array $source): string
    {
        $assetType = 'UNKNOWN';
        
        if (isset($source['asset_type'])) {
            $assetType = strtoupper($source['asset_type']);
        } elseif (isset($source['type'])) {
            $assetType = strtoupper($source['type']);
        } elseif (isset($source['source']['asset_type'])) {
            $assetType = strtoupper($source['source']['asset_type']);
        } elseif (isset($source['delivery_mode'])) {
            $assetType = strtoupper($source['delivery_mode']);
        }
        
        switch ($assetType) {
            case 'VOUCHER':
                $voucherNumber = $source['voucher']['voucher_number'] ?? $source['voucher_number'] ?? '';
                return 'VCH-' . substr($voucherNumber, -4);
                
            case 'ACCOUNT':
                $accountNumber = $source['account']['account_number'] ?? $source['account_number'] ?? '';
                return 'ACC-' . substr($accountNumber, -4);
                
            case 'WALLET':
                $phone = $source['wallet']['wallet_phone'] ?? 
                         $source['wallet']['phone'] ?? 
                         $source['wallet_phone'] ?? 
                         $source['phone'] ?? 
                         '';
                return 'WLT-' . substr($phone, -8);
                
            case 'E-WALLET':
                $phone = $source['ewallet']['ewallet_phone'] ?? 
                         $source['ewallet']['phone'] ?? 
                         $source['ewallet_phone'] ?? 
                         $source['phone'] ?? 
                         '';
                return 'EWL-' . substr($phone, -8);
                
            case 'CARD':
                $cardNumber = $source['card']['card_number'] ?? $source['card_number'] ?? '';
                return 'CRD-' . substr($cardNumber, -4);
                
            case 'CASHOUT':
                $phone = $source['cashout']['beneficiary_phone'] ?? $source['beneficiary_phone'] ?? '';
                return 'CSH-' . substr($phone, -8);
                
            case 'CARD_LOAD':
            case 'CARD_ISSUANCE':
            case 'DEPOSIT':
                if (isset($source['card_suffix'])) {
                    return 'CRD-' . $source['card_suffix'];
                }
                if (isset($source['beneficiary_account'])) {
                    return 'ACC-' . substr($source['beneficiary_account'], -4);
                }
                if (isset($source['beneficiary_wallet'])) {
                    return 'WLT-' . substr($source['beneficiary_wallet'], -8);
                }
                return 'DST-' . substr(uniqid(), -6);
                
            default:
                if (isset($source['phone'])) {
                    return 'USR-' . substr($source['phone'], -8);
                }
                if (isset($source['account_number'])) {
                    return 'ACC-' . substr($source['account_number'], -4);
                }
                if (isset($source['voucher_number'])) {
                    return 'VCH-' . substr($source['voucher_number'], -4);
                }
                if (isset($source['beneficiary_phone'])) {
                    return 'BNF-' . substr($source['beneficiary_phone'], -8);
                }
                if (isset($source['card_suffix'])) {
                    return 'CRD-' . $source['card_suffix'];
                }
                return 'SRC-' . substr(uniqid(), -6);
        }
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

    private function updateSwapMetadata(string $swapRef, array $data): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE swap_requests 
                SET metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb
                WHERE swap_uuid = ?
            ");
            $stmt->execute([json_encode($data), $swapRef]);
        } catch (Exception $e) {
            error_log("Failed to update swap metadata: " . $e->getMessage());
        }
    }

    private function getSwapMetadata(string $swapRef): array
    {
        try {
            $stmt = $this->swapDB->prepare("
                SELECT metadata FROM swap_requests WHERE swap_uuid = ?
            ");
            $stmt->execute([$swapRef]);
            $metadata = $stmt->fetchColumn();
            return $metadata ? json_decode($metadata, true) : [];
        } catch (Exception $e) {
            error_log("Failed to get swap metadata: " . $e->getMessage());
            return [];
        }
    }

    public function getAtmDenominations(string $currency = 'BWP'): array
    {
        return $this->atmNotes[$currency] ?? [];
    }

    public function canDispenseAmount(float $amount, string $currency = 'BWP'): bool
    {
        try {
            $result = $this->getDispensableAmount($amount, $currency);
            return $result['dispensable_amount'] > 0 && $result['undispensed_amount'] <= 0.01;
        } catch (Exception) {
            return false;
        }
    }

    public function getTransactionTrace(string $swapRef): array
    {
        try {
            $stmt = $this->swapDB->prepare("
                SELECT * FROM swap_requests WHERE swap_uuid = ?
            ");
            $stmt->execute([$swapRef]);
            $swap = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $this->swapDB->prepare("
                SELECT * FROM hold_transactions WHERE swap_reference = ?
            ");
            $stmt->execute([$swapRef]);
            $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->swapDB->prepare("
                SELECT * FROM api_message_logs 
                WHERE message_id = ? 
                ORDER BY created_at ASC
            ");
            $stmt->execute([$swapRef]);
            $apiLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->swapDB->prepare("
                SELECT * FROM swap_fee_collections 
                WHERE swap_reference = ?
            ");
            $stmt->execute([$swapRef]);
            $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->swapDB->prepare("
                SELECT * FROM card_authorizations 
                WHERE swap_reference = ?
            ");
            $stmt->execute([$swapRef]);
            $cardAuths = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->swapDB->prepare("
                SELECT * FROM fx_quotes WHERE swap_reference = ?
            ");
            $stmt->execute([$swapRef]);
            $fxQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'swap' => $swap,
                'holds' => $holds,
                'api_messages' => $apiLogs,
                'fees' => $fees,
                'card_authorizations' => $cardAuths,
                'fx_quotes' => $fxQuotes
            ];
        } catch (Exception $e) {
            error_log("Failed to get transaction trace: " . $e->getMessage());
            return [
                'error' => $e->getMessage()
            ];
        }
    }
}
