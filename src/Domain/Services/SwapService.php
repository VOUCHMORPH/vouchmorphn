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
 * ALL fees and rates are loaded from country configuration files - NO HARDCODING
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

    public function __construct(PDO $swapDB, array $settings, string $country, string $encryptionKey, array $config)
    {
        $this->swapDB = $swapDB;
        $this->settings = $settings;
        $this->countryCode = strtoupper($country);
        $this->config = $config;
        
        if (isset($config['participants']) && is_array($config['participants'])) {
            $this->participants = $config['participants'];
            error_log("[SwapService] Using new config structure with 'participants' key");
        } elseif (isset($config[0]) && is_array($config[0])) {
            $this->participants = [];
            foreach ($config as $participant) {
                if (isset($participant['id'])) {
                    $this->participants[$participant['id']] = $participant;
                }
            }
            error_log("[SwapService] Using indexed participants array structure");
        } else {
            $this->participants = $config;
            error_log("[SwapService] Using legacy direct participants array structure");
        }
        
        if (!is_array($this->participants)) {
            $this->participants = [];
            error_log("[SwapService] WARNING: Participants was not an array, reset to empty array");
        }
        
        $this->participants = array_change_key_case($this->participants, CASE_LOWER);
        
        error_log("=== SWAPSERVICE CONSTRUCTOR ===");
        error_log("Country: " . $this->countryCode);
        error_log("Participants count: " . count($this->participants));

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

        // ALWAYS load from country files - NO HARDCODING
        try {
            $this->loadCountryFees();
            error_log("[SwapService] Country fees loaded successfully from files");
        } catch (\Exception $e) {
            error_log("[SwapService] ERROR loading country fees: " . $e->getMessage());
            throw new RuntimeException("Failed to load country fees: " . $e->getMessage());
        }
        
        // Initialize FeeService with loaded config
        $this->feeService = new FeeService($this->feesConfig, 'BWP');
        error_log("[SwapService] FeeService initialized");
        
        // ALWAYS load card config from country files - NO HARDCODING
        try {
            $this->loadCardConfig();
            error_log("[SwapService] Card config loaded successfully from files");
        } catch (\Exception $e) {
            error_log("[SwapService] ERROR loading card config: " . $e->getMessage());
            throw new RuntimeException("Failed to load card config: " . $e->getMessage());
        }
        
        // ALWAYS load ATM notes from country files - NO HARDCODING
        try {
            $this->loadAtmNotes();
            error_log("[SwapService] ATM notes loaded successfully from files");
        } catch (\Exception $e) {
            error_log("[SwapService] ERROR loading ATM notes: " . $e->getMessage());
            throw new RuntimeException("Failed to load ATM notes: " . $e->getMessage());
        }
        
        try {
            $this->loadFlows();
            error_log("[SwapService] Flows loaded successfully");
        } catch (\Exception $e) {
            error_log("[SwapService] WARNING loading flows: " . $e->getMessage());
        }
        
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
        
        try {
            $this->initSmsService();
            error_log("[SwapService] SMS Service initialized successfully");
        } catch (\Exception $e) {
            error_log("[SwapService] WARNING: SMS Service initialization failed: " . $e->getMessage());
        }
        
        // Initialize Card Service
        try {
            if (isset($this->participants['vouchmorph'])) {
                $this->cardService = new CardService(
                    $this->swapDB, 
                    $this->countryCode, 
                    $this->participants['vouchmorph']
                );
                error_log("[SwapService] ✅ Card Service initialized successfully");
            } else {
                $this->cardService = new CardService(
                    $this->swapDB, 
                    $this->countryCode, 
                    []
                );
                error_log("[SwapService] ✅ Card Service initialized with default config");
            }
        } catch (\Exception $e) {
            error_log("[SwapService] ❌ Card Service initialization failed: " . $e->getMessage());
            $this->cardService = null;
        }
        
        error_log("=== SWAPSERVICE CONSTRUCTOR COMPLETE ===");
        error_log("Card service status: " . ($this->cardService ? "ACTIVE" : "NOT AVAILABLE"));
        error_log("Forex service status: " . ($this->forexService ? "ACTIVE" : "NOT AVAILABLE"));
        error_log("Fee service status: " . ($this->feeService ? "ACTIVE" : "NOT AVAILABLE"));
    }
    
    /**
     * Get country data directory path - Uses actual config file location
     * Files are in: src/Core/Config/Countries/{country}/
     */
    private function getCountryDataDir(): string
    {
        // Primary path: src/Core/Config/Countries/{CountryName}/
        $coreConfigPath = __DIR__ . "/../Core/Config/Countries/" . ucfirst(strtolower($this->countryCode));
        
        if (is_dir($coreConfigPath)) {
            error_log("[SwapService] Found country data dir in core config: {$coreConfigPath}");
            return $coreConfigPath;
        }
        
        // Try with country name from SystemCountry.php
        $systemCountryFile = __DIR__ . "/../Core/Config/SystemCountry.php";
        if (file_exists($systemCountryFile)) {
            $countryMeta = require $systemCountryFile;
            $countryName = $countryMeta['name'] ?? 'Botswana';
            $countryPath = __DIR__ . "/../Core/Config/Countries/" . $countryName;
            
            if (is_dir($countryPath)) {
                error_log("[SwapService] Found country data dir using country name: {$countryPath}");
                return $countryPath;
            }
        }
        
        // Try project root relative path
        $projectRoot = dirname(__DIR__, 3);
        $projectPath = $projectRoot . "/src/Core/Config/Countries/" . ucfirst(strtolower($this->countryCode));
        if (is_dir($projectPath)) {
            error_log("[SwapService] Using country data dir from project root: {$projectPath}");
            return $projectPath;
        }
        
        // Final error - no hardcoded fallback
        $error = "Could not find country config directory for {$this->countryCode}. ";
        $error .= "Expected path: " . $coreConfigPath;
        error_log("[SwapService] ERROR: " . $error);
        throw new RuntimeException($error);
    }
    
    /**
     * Load ATM notes from configuration - NO HARDCODING
     * MUST load from atm_notes.json file only
     */
    private function loadAtmNotes(): void
    {
        $dataDir = $this->getCountryDataDir();
        $atmFile = $dataDir . '/atm_notes.json';
        
        if (!file_exists($atmFile)) {
            throw new RuntimeException("ATM notes file missing for country {$this->countryCode}: {$atmFile}");
        }
        
        $atmContent = file_get_contents($atmFile);
        if ($atmContent === false) {
            throw new RuntimeException("Failed to read ATM notes file: {$atmFile}");
        }
        
        $this->atmNotes = json_decode($atmContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in ATM notes file: {$atmFile} - " . json_last_error_msg());
        }
        
        if (!isset($this->atmNotes['BWP']) || empty($this->atmNotes['BWP'])) {
            throw new RuntimeException("ATM notes missing BWP denominations in {$atmFile}");
        }
        
        error_log("[SwapService] ATM notes loaded from: {$atmFile}");
    }
    
    /**
     * Load card configuration from country config - NO HARDCODING
     * MUST load from cards.json file only
     */
    private function loadCardConfig(): void
    {
        $dataDir = $this->getCountryDataDir();
        $cardFile = $dataDir . '/cards.json';
        
        if (!file_exists($cardFile)) {
            throw new RuntimeException("Card config file missing for country {$this->countryCode}: {$cardFile}");
        }
        
        $content = file_get_contents($cardFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read card config file: {$cardFile}");
        }
        
        $this->cardConfig = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in card config file: {$cardFile} - " . json_last_error_msg());
        }
        
        if (!isset($this->cardConfig['message_based_issuers'])) {
            throw new RuntimeException("Card config missing 'message_based_issuers' in {$cardFile}");
        }
        
        if (!isset($this->cardConfig['default_card_type'])) {
            throw new RuntimeException("Card config missing 'default_card_type' in {$cardFile}");
        }
        
        error_log("[SwapService] Card config loaded from: {$cardFile}");
    }

    /**
     * Load country fees from configuration - NO HARDCODING
     * MUST load from fees.json file only
     */
    private function loadCountryFees(): void
    {
        $dataDir = $this->getCountryDataDir();
        
        $feesFile = $dataDir . '/fees.json';
        
        if (!file_exists($feesFile)) {
            throw new RuntimeException("Fees file missing for country {$this->countryCode}: {$feesFile}");
        }
        
        $content = file_get_contents($feesFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read fees file: {$feesFile}");
        }
        
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in fees file: {$feesFile} - " . json_last_error_msg());
        }
        
        // Validate required structure
        if (!isset($decoded['fees'])) {
            throw new RuntimeException("Missing 'fees' key in {$feesFile}");
        }
        
        if (!isset($decoded['regulatory']['vat_rate'])) {
            throw new RuntimeException("Missing 'regulatory.vat_rate' in {$feesFile}");
        }
        
        $requiredFees = ['CASHOUT_SWAP_FEE', 'DEPOSIT_SWAP_FEE', 'CARD_LOAD_FEE', 'CARD_ISSUANCE_FEE'];
        foreach ($requiredFees as $requiredFee) {
            if (!isset($decoded['fees'][$requiredFee])) {
                throw new RuntimeException("Missing required fee configuration: {$requiredFee} in {$feesFile}");
            }
            
            $fee = $decoded['fees'][$requiredFee];
            if (!isset($fee['total_amount'])) {
                throw new RuntimeException("Fee {$requiredFee} missing 'total_amount' in {$feesFile}");
            }
            
            if (!isset($fee['split'])) {
                throw new RuntimeException("Fee {$requiredFee} missing 'split' configuration in {$feesFile}");
            }
        }
        
        $this->feesConfig = $decoded;
        error_log("[SwapService] Fees loaded from: {$feesFile}");
    }

    /**
     * Initialize SMS service from country config - NO HARDCODING
     * MUST load from communication.json file only
     */
    private function initSmsService(): void
    {
        $dataDir = $this->getCountryDataDir();
        $configPath = $dataDir . '/communication.json';
        
        if (!file_exists($configPath)) {
            error_log("[SwapService] Communication config missing for {$this->countryCode}: {$configPath}");
            $this->smsService = null;
            return;
        }
        
        $content = file_get_contents($configPath);
        if ($content === false) {
            error_log("[SwapService] Failed to read communication config: {$configPath}");
            $this->smsService = null;
            return;
        }
        
        $fullConfig = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[SwapService] Invalid JSON in communication config: " . json_last_error_msg());
            $this->smsService = null;
            return;
        }
        
        $smsConfig = $fullConfig['sms_gateway'] ?? $fullConfig;
        
        if (empty($smsConfig) || !($smsConfig['enabled'] ?? false)) {
            error_log("[SwapService] SMS service disabled in config for {$this->countryCode}");
            $this->smsService = null;
            return;
        }
        
        $this->smsService = new SmsNotificationService($this->swapDB, $smsConfig);
        error_log("[SwapService] SMS Service initialized from: {$configPath}");
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

    /**
     * Get retry count for a client and original swap
     */
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
    
    /**
     * Record a failed cashout for retry tracking
     */
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
    
    /**
     * Extract client identifier from source payload
     */
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

    /**
     * Deduct swap fee using configuration - NO HARDCODING
     */
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

    /**
     * Calculate fees without deducting - NO HARDCODING
     */
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

    /**
     * Get source currency from verification response
     * Participants MUST return currency - do NOT default
     */
    private function getSourceCurrencyFromVerification(array $verificationResult, array $source): string
    {
        // Priority 1: Currency from participant response (MANDATORY)
        if (isset($verificationResult['currency']) && !empty($verificationResult['currency'])) {
            return strtoupper($verificationResult['currency']);
        }
        
        // Priority 2: Currency from asset_details
        if (isset($verificationResult['asset_details']['currency']) && !empty($verificationResult['asset_details']['currency'])) {
            return strtoupper($verificationResult['asset_details']['currency']);
        }
        
        // Priority 3: Currency from source payload
        if (isset($source['currency']) && !empty($source['currency'])) {
            return strtoupper($source['currency']);
        }
        
        // DO NOT default - participants must provide currency
        throw new RuntimeException(
            "Source participant did not return currency. " .
            "Response must include 'currency' field in verification response."
        );
    }
    
    /**
     * Get destination currency from participant config
     */
    private function getDestinationCurrency(array $destination, array $participant): string
    {
        // Priority 1: Explicit in destination payload
        if (isset($destination['currency']) && !empty($destination['currency'])) {
            return strtoupper($destination['currency']);
        }
        
        // Priority 2: From participant config
        if (isset($participant['default_currency'])) {
            return strtoupper($participant['default_currency']);
        }
        
        // Priority 3: From participant currencies table (query)
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
        
        // Fallback to country mapping
        $countryCode = $participant['country_code'] ?? $this->countryCode;
        return $this->mapCountryToCurrency($countryCode);
    }
    
    /**
     * Map country code to currency (fallback only)
     */
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

    /**
     * Verify source asset with institution - ENHANCED to capture currency
     */
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

            // Extract currency from response - CRITICAL for FX
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

    /**
     * Place hold on source asset - UPDATED for FX context
     */
    private function placeHoldOnSourceAsset(string $swapRef, array $source, array $verificationResult, array $participant, array $destination): array
    {
        // Use FX context source currency if available
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

    /**
     * Execute swap with unified payload structure - ENHANCED with FX and fee separation
     */
    public function executeSwap(array $payload): array
    { 
        $this->swapDB->beginTransaction();
        
        $holdResult = null;
        $bankClient = null;
        $swapRef = bin2hex(random_bytes(16));
        $atmResult = null;
        $cashoutResult = null;
        $this->fxContext = null;
        
        // Store original swap ref for retry tracking
        $originalSwapRef = $payload['original_swap_reference'] ?? null;
        
        $debugSteps = [];
        $debugSteps[] = ['time' => microtime(true), 'step' => 'START', 'swap_ref' => $swapRef];
        
        try {
            if (empty($payload['source']['institution'])) {
                throw new RuntimeException("Source institution is required");
            }

            if (empty($payload['destination']['institution'])) {
                throw new RuntimeException("Destination institution is required");
            }

            $source = $payload['source'];
            $destination = $payload['destination'];
            
            $debugSteps[] = ['time' => microtime(true), 'step' => 'PAYLOAD_VALIDATED', 'source' => $source['institution'], 'dest' => $destination['institution']];

            $sourceInstitutionKey = $this->findInstitutionKey($source['institution']);
            if (!$sourceInstitutionKey) {
                throw new RuntimeException("Source institution not found: {$source['institution']}");
            }
            $sourceParticipant = $this->participants[$sourceInstitutionKey];
            
            $debugSteps[] = ['time' => microtime(true), 'step' => 'SOURCE_FOUND', 'participant' => $sourceParticipant['provider_code'] ?? $sourceInstitutionKey];
            
            $source = $this->sanitizePhones($source, $sourceParticipant);
            $destination = $this->sanitizePhones($destination, null);

            // =============================================
            // STEP 1: Verify source asset and get currency
            // =============================================
            $debugSteps[] = ['time' => microtime(true), 'step' => 'START_VERIFY_ASSET'];
            $verificationResult = $this->verifySourceAsset($swapRef, $source, $sourceParticipant);
            $debugSteps[] = ['time' => microtime(true), 'step' => 'VERIFY_ASSET_COMPLETE', 'verified' => $verificationResult['verified']];

            if (!$verificationResult['verified']) {
                throw new RuntimeException(
                    "Source verification failed: " . 
                    ($verificationResult['message'] ?? 'Invalid credentials')
                );
            }
            
            // =============================================
            // STEP 2: Get source currency (MUST be provided)
            // =============================================
            $sourceCurrency = $this->getSourceCurrencyFromVerification($verificationResult, $source);
            
            $debugSteps[] = ['time' => microtime(true), 'step' => 'SOURCE_CURRENCY_DETECTED', 'currency' => $sourceCurrency];
            
            // =============================================
            // STEP 3: Get destination participant and currency
            // =============================================
            $destInstitutionKey = $this->findInstitutionKey($destination['institution']);
            if (!$destInstitutionKey) {
                throw new RuntimeException("Destination institution not found: {$destination['institution']}");
            }
            $destParticipant = $this->participants[$destInstitutionKey];
            $destinationCurrency = $this->getDestinationCurrency($destination, $destParticipant);
            
            $debugSteps[] = ['time' => microtime(true), 'step' => 'DEST_CURRENCY_DETECTED', 'currency' => $destinationCurrency];
            
            // =============================================
            // STEP 4: Handle FX if currencies differ
            // =============================================
            $sourceAmount = (float)$source['amount'];
            $creditAmount = $sourceAmount;
            $exchangeRate = null;
            
            if ($this->forexService && $sourceCurrency !== $destinationCurrency) {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'FX_NEEDED', 'from' => $sourceCurrency, 'to' => $destinationCurrency];
                
                $this->fxContext = $this->forexService->prepareFxContext(
                    $swapRef,
                    $sourceParticipant,
                    $destParticipant,
                    $sourceCurrency,
                    $sourceAmount,
                    $destination
                );
                
                $debugSteps[] = ['time' => microtime(true), 'step' => 'FX_CONTEXT_PREPARED',
                                 'needs_fx' => $this->fxContext['needs_fx'],
                                 'dest_amount' => $this->fxContext['destination_amount'] ?? null,
                                 'rate' => $this->fxContext['rate'] ?? null];
                
                if ($this->fxContext['needs_fx']) {
                    $creditAmount = $this->fxContext['destination_amount'];
                    $exchangeRate = $this->fxContext['rate'];
                    
                    // Update destination amount with FX-converted value
                    $destination['amount'] = $creditAmount;
                    $destination['original_amount'] = $sourceAmount;
                    $destination['exchange_rate'] = $exchangeRate;
                    $destination['fx_quote_uuid'] = $this->fxContext['quote_uuid'];
                    $destination['fx_rate_source'] = $this->fxContext['rate_source'] ?? null;
                    
                    $debugSteps[] = ['time' => microtime(true), 'step' => 'FX_APPLIED',
                                     'original_amount' => $sourceAmount,
                                     'converted_amount' => $creditAmount,
                                     'rate' => $exchangeRate];
                }
            } else {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'NO_FX_NEEDED', 'currency' => $sourceCurrency];
            }
            
            // =============================================
            // STEP 5: Validate cashout if needed
            // =============================================
            if (isset($destination['delivery_mode']) && $destination['delivery_mode'] === 'cashout') {
                $errors = $this->validateCashoutAmount($creditAmount, $destinationCurrency);
                if (!empty($errors)) {
                    throw new RuntimeException("Cashout validation failed: " . implode(', ', $errors));
                }
                $atmResult = $this->getDispensableAmount($creditAmount, $destinationCurrency);
                $debugSteps[] = ['time' => microtime(true), 'step' => 'CASHOUT_VALIDATED', 
                                 'dispensable' => $atmResult['dispensable_amount'],
                                 'currency' => $destinationCurrency];
            }
            
            // =============================================
            // STEP 6: Place hold (in source currency)
            // =============================================
            $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PLACE_HOLD'];
            $holdResult = $this->placeHoldOnSourceAsset($swapRef, $source, $verificationResult, $sourceParticipant, $destination);
            $bankClient = new GenericBankClient($sourceParticipant);
            
            $debugSteps[] = ['time' => microtime(true), 'step' => 'PLACE_HOLD_COMPLETE', 
                            'hold_placed' => $holdResult['hold_placed'], 
                            'hold_reference' => $holdResult['hold_reference'] ?? null];
            
            if (!$holdResult['hold_placed']) {
                throw new RuntimeException("Failed to place hold: " . ($holdResult['message'] ?? 'Unknown error'));
            }

            // =============================================
            // STEP 7: Record swap with FX info and original ref for retry
            // =============================================
            $debugSteps[] = ['time' => microtime(true), 'step' => 'START_RECORD_SWAP'];
            $swapId = $this->recordMasterSwap(
                $swapRef,
                $source,
                $destination,
                $sourceCurrency,
                $destinationCurrency,
                $verificationResult,
                $exchangeRate,
                $this->fxContext['quote_uuid'] ?? null,
                $originalSwapRef
            );
            $debugSteps[] = ['time' => microtime(true), 'step' => 'RECORD_SWAP_COMPLETE', 'swap_id' => $swapId];

            // =============================================
            // STEP 8: Process destination based on delivery mode
            // =============================================
            $debugSteps[] = ['time' => microtime(true), 'step' => 'BEFORE_PROCESS_DESTINATION', 
                            'delivery_mode' => $destination['delivery_mode'] ?? 'deposit',
                            'amount' => $creditAmount,
                            'currency' => $destinationCurrency];

            if (isset($destination['delivery_mode'])) {
                switch ($destination['delivery_mode']) {
                    case 'card_load':
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'START_CARD_LOAD'];
                        $this->processCardLoad($swapId, $swapRef, $source, $destination, $destinationCurrency, $holdResult);
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'CARD_LOAD_COMPLETE'];
                        break;
                        
                    case 'card':
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'START_CARD_ISSUANCE'];
                        $this->processCardIssuance($swapId, $swapRef, $source, $destination, $destinationCurrency, $holdResult);
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'CARD_ISSUANCE_COMPLETE'];
                        break;
                        
                    case 'cashout':
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PROCESS_CASHOUT'];
                        $cashoutResult = $this->processCashoutWithFeeSeparation(
                            $swapId, $swapRef, $source, $destination, $destinationCurrency, $holdResult, $originalSwapRef
                        );
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'PROCESS_CASHOUT_COMPLETE'];
                        break;
                        
                    case 'deposit':
                    default:
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PROCESS_DEPOSIT'];
                        $this->processDeposit($swapId, $swapRef, $source, $destination, $destinationCurrency, $holdResult);
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'PROCESS_DEPOSIT_COMPLETE'];
                        break;
                }
            } else {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PROCESS_DEPOSIT'];
                $this->processDeposit($swapId, $swapRef, $source, $destination, $destinationCurrency, $holdResult);
                $debugSteps[] = ['time' => microtime(true), 'step' => 'PROCESS_DEPOSIT_COMPLETE'];
            }

            // =============================================
            // STEP 9: Debit source funds (unless skip)
            // =============================================
            $debugSteps[] = ['time' => microtime(true), 'step' => 'CHECKING_IF_DEBIT_NEEDED'];
            
            $skipDebit = $this->shouldSkipDebit($destination);
            
            if (!$skipDebit) {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'START_DEBIT_FUNDS'];
                $this->debitSourceFunds($swapRef, $source, $holdResult, $sourceParticipant);
                $debugSteps[] = ['time' => microtime(true), 'step' => 'DEBIT_FUNDS_COMPLETE'];
            } else {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'SKIP_DEBIT_FOR_MESSAGE_CARD'];
                $this->logEvent($swapRef, 'HOLD_MAINTAINED', [
                    'hold_reference' => $holdResult['hold_reference'],
                    'reason' => 'Message-based card - funds remain at source until usage'
                ]);
            }

            // =============================================
            // STEP 10: Handle undispensed amount for cashout
            // =============================================
            if ($atmResult && $atmResult['undispensed_amount'] > 0.01) {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'HANDLE_UNDISPENSED', 
                                'amount' => $atmResult['undispensed_amount']];
                $this->handleUndispensedAmount(
                    $source['institution'],
                    $destination,
                    $atmResult['undispensed_amount'],
                    $swapRef
                );
            }

            // Mark FX quote as used if applicable
            if ($this->fxContext && isset($this->fxContext['quote_uuid'])) {
                $this->forexService->markQuoteUsed($this->fxContext['quote_uuid']);
            }

            $this->swapDB->commit();
            $debugSteps[] = ['time' => microtime(true), 'step' => 'TRANSACTION_COMMITTED'];
            
            $this->logEvent($swapRef, 'SWAP_EXECUTED', [
                'status' => 'SUCCESS',
                'source_type' => $source['asset_type'],
                'destination_type' => $destination['asset_type'],
                'skip_debit' => $skipDebit,
                'fx_applied' => $this->fxContext ? true : false,
                'fx_rate' => $exchangeRate,
                'is_retry' => !empty($originalSwapRef),
                'debug_steps' => $debugSteps
            ]);

            // =============================================
            // STEP 11: Build response
            // =============================================
            $response = [
                'status' => 'success',
                'swap_reference' => $swapRef,
                'message' => 'Swap initiated successfully',
                'hold_reference' => $holdResult['hold_reference'] ?? null,
                'debug' => $debugSteps
            ];

            // Add FX info if applicable
            if ($this->fxContext && $this->fxContext['needs_fx']) {
                $response['fx'] = [
                    'source_currency' => $this->fxContext['source_currency'],
                    'destination_currency' => $this->fxContext['destination_currency'],
                    'source_amount' => $this->fxContext['source_amount'],
                    'destination_amount' => $this->fxContext['destination_amount'],
                    'exchange_rate' => $this->fxContext['rate'],
                    'rate_source' => $this->fxContext['rate_source'] ?? 'unknown',
                    'quote_uuid' => $this->fxContext['quote_uuid'],
                    'rate_locked_until' => $this->fxContext['expires_at'] ?? null
                ];
                $response['message'] = sprintf(
                    'Swap initiated: %s %s → %s %s (Rate: 1 %s = %s %s)',
                    $this->fxContext['source_amount'],
                    $this->fxContext['source_currency'],
                    $this->fxContext['destination_amount'],
                    $this->fxContext['destination_currency'],
                    $this->fxContext['source_currency'],
                    $this->fxContext['rate'],
                    $this->fxContext['destination_currency']
                );
            }

            // Add cashout response data if applicable
            if ($cashoutResult) {
                $response['withdrawal_code'] = $cashoutResult['generated_code'];
                $response['sat_number'] = $cashoutResult['sat_number'] ?? null;
                $response['token_reference'] = $cashoutResult['token_reference'];
                $response['expires_at'] = $cashoutResult['expires_at'];
                $response['fee_breakdown'] = $cashoutResult['fee_breakdown'] ?? null;
                if (isset($response['fx'])) {
                    $response['withdrawal_currency'] = $this->fxContext['destination_currency'];
                    $response['withdrawal_amount'] = $this->fxContext['destination_amount'];
                }
                $response['message'] = 'Cashout code generated by ' . $destination['institution'];
                
                if ($cashoutResult['is_free_retry'] ?? false) {
                    $response['message'] .= ' (Free swap-on-swap applied)';
                }
            }

            if (isset($destination['delivery_mode'])) {
                $metadata = $this->getSwapMetadata($swapRef);
                
                if ($destination['delivery_mode'] === 'card') {
                    if (isset($metadata['card_issuance_result'])) {
                        $response['card_details'] = $metadata['card_issuance_result'];
                        $response['message'] = 'Card issued successfully';
                    }
                } elseif ($destination['delivery_mode'] === 'card_load') {
                    if (isset($metadata['card_load_result']) || isset($metadata['card_authorization'])) {
                        $cardInfo = $metadata['card_load_result'] ?? $metadata['card_authorization'];
                        $response['card_details'] = $cardInfo;
                        
                        $cardType = $this->getCardType($destination);
                        if ($cardType === 'message_based') {
                            $response['message'] = 'Card authorized successfully - funds remain at source until usage';
                        } else {
                            $response['message'] = 'Card loaded successfully';
                        }
                    }
                }
            }

            if (!isset($response['card_details']) && $atmResult && !$cashoutResult) {
                $response['dispensed_notes'] = $atmResult['notes'] ?? [];
            }

            return $response;

        } catch (Exception $e) {
            $debugSteps[] = ['time' => microtime(true), 'step' => 'EXCEPTION_CAUGHT', 
                            'error' => $e->getMessage(), 
                            'trace' => $e->getTraceAsString()];
            
            // Record failed cashout for retry eligibility
            if (isset($destination['delivery_mode']) && $destination['delivery_mode'] === 'cashout') {
                try {
                    $clientIdentifier = $this->extractClientIdentifier($source);
                    $this->recordFailedCashout($clientIdentifier, $swapRef, $e->getMessage());
                    $this->logEvent($swapRef, 'FAILED_CASHOUT_RECORDED', [
                        'client' => $clientIdentifier,
                        'error' => $e->getMessage()
                    ]);
                } catch (Exception $recordError) {
                    error_log("Failed to record failed cashout: " . $recordError->getMessage());
                }
            }
            
            if ($holdResult && $holdResult['hold_placed'] && $bankClient) {
                try {
                    $debugSteps[] = ['time' => microtime(true), 'step' => 'RELEASING_HOLD'];
                    $bankClient->releaseHold([
                        'hold_reference' => $holdResult['hold_reference'],
                        'reason' => 'Transaction failed: ' . $e->getMessage()
                    ]);
                    $this->logEvent($swapRef, 'HOLD_RELEASED', ['reason' => $e->getMessage()]);
                    if (isset($holdResult['hold_reference'])) {
                        $this->updateHoldStatus($holdResult['hold_reference'], 'RELEASED');
                    }
                    $debugSteps[] = ['time' => microtime(true), 'step' => 'HOLD_RELEASED'];
                } catch (Exception $releaseError) {
                    $debugSteps[] = ['time' => microtime(true), 'step' => 'HOLD_RELEASE_FAILED', 
                                    'error' => $releaseError->getMessage()];
                    error_log("Failed to release hold: " . $releaseError->getMessage());
                }
            }
            
            if ($this->swapDB->inTransaction()) {
                $this->swapDB->rollBack();
                $debugSteps[] = ['time' => microtime(true), 'step' => 'TRANSACTION_ROLLED_BACK'];
            }
            
            $this->logEvent($swapRef ?? 'N/A', 'SWAP_FAILED', [
                'error' => $e->getMessage(),
                'debug_steps' => $debugSteps
            ]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'swap_reference' => $swapRef,
                'can_retry' => (isset($destination['delivery_mode']) && $destination['delivery_mode'] === 'cashout'),
                'debug' => $debugSteps
            ];
        }
    }

    /**
     * Process cashout with proper fee separation and retry logic
     */
    private function processCashoutWithFeeSeparation(
        int $swapId, 
        string $swapRef, 
        array $source, 
        array $destination, 
        string $currency, 
        array $holdResult,
        ?string $originalSwapRef = null
    ): array {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'CASHOUT_WITH_FEE_SEPARATION_START'];
        
        try {
            // Use FX-converted amount if available
            $grossAmount = $this->fxContext 
                ? (float)($this->fxContext['destination_amount'] ?? $destination['amount'])
                : (float)$destination['amount'];
            
            $clientIdentifier = $this->extractClientIdentifier($source);
            $retryCount = $this->getRetryCount($clientIdentifier, $originalSwapRef);
            
            $debug[] = ['time' => microtime(true), 'step' => 'RETRY_CHECK', 
                       'client' => $clientIdentifier, 
                       'retry_count' => $retryCount,
                       'original_swap' => $originalSwapRef];
            
            // Determine if this is a free retry (first retry only)
            $isFreeRetry = ($originalSwapRef && $retryCount <= 1);
            $feeCalculation = null;
            $netAmount = $grossAmount;
            
            if ($isFreeRetry) {
                // FREE RETRY: Client pays nothing extra
                $feeCalculation = $this->feeService->calculateFreeRetryFees($currency);
                $netAmount = $grossAmount;
                
                $debug[] = ['time' => microtime(true), 'step' => 'FREE_RETRY_APPLIED', 
                           'fee_breakdown' => $feeCalculation];
                
                // Mark free retry as used
                $stmt = $this->swapDB->prepare("
                    UPDATE cashout_retry_tracking 
                    SET free_retry_used = TRUE, updated_at = NOW()
                    WHERE client_identifier = ? AND original_swap_ref = ?
                ");
                $stmt->execute([$clientIdentifier, $originalSwapRef]);
                
            } else {
                // FIRST ATTEMPT OR PAID RETRY: Client pays full fee
                $feeCalculation = $this->feeService->calculateFirstAttemptFees($currency);
                $netAmount = $grossAmount - $feeCalculation['total_fee'];
                
                $debug[] = ['time' => microtime(true), 'step' => 'PAID_ATTEMPT', 
                           'fee_breakdown' => $feeCalculation,
                           'net_amount' => $netAmount];
                
                // Store fee breakdown in swap_requests
                $stmt = $this->swapDB->prepare("
                    UPDATE swap_requests 
                    SET fee_breakdown = ?::jsonb,
                        total_cashout_fee = ?
                    WHERE swap_uuid = ?
                ");
                $stmt->execute([
                    json_encode($feeCalculation),
                    $feeCalculation['total_fee'],
                    $swapRef
                ]);
                
                // Invoice VouchMorph's platform share (swap levy + platform share)
                $vouchmorphTotal = $feeCalculation['vouchmorph_total_immediate'];
                if ($vouchmorphTotal > 0) {
                    $this->settlement->invoiceFee(
                        $swapRef,
                        $source['institution'],
                        0,
                        'VOUCHMORPH_PLATFORM_FEE',
                        $vouchmorphTotal,
                        $currency
                    );
                }
                
                // Invoice source institution share
                $sourceShare = $feeCalculation['source_institution_share'];
                if ($sourceShare > 0) {
                    $this->settlement->invoiceFee(
                        $swapRef,
                        $source['institution'],
                        0,
                        'SOURCE_INSTITUTION_FEE',
                        $sourceShare,
                        $currency
                    );
                }
            }
            
            if ($netAmount <= 0) {
                throw new RuntimeException("Amount after fees would be non-positive: {$grossAmount} -> net {$netAmount}");
            }
            
            $debug[] = ['time' => microtime(true), 'step' => 'NET_AMOUNT_CALCULATED', 
                       'gross' => $grossAmount, 
                       'net' => $netAmount];
            
            // Validate ATM dispensability
            $errors = $this->validateCashoutAmount($netAmount, $currency);
            if (!empty($errors)) {
                throw new RuntimeException("Cashout validation failed: " . implode(', ', $errors));
            }
            $atmResult = $this->getDispensableAmount($netAmount, $currency);
            
            $cashoutData = $destination['cashout'] ?? [];
            
            $destInstitutionKey = $this->findInstitutionKey($destination['institution']);
            if (!$destInstitutionKey) {
                throw new RuntimeException("Destination institution not found: {$destination['institution']}");
            }
            $destParticipant = $this->participants[$destInstitutionKey];
            
            if (isset($cashoutData['beneficiary_phone'])) {
                $cashoutData['beneficiary_phone'] = $this->formatPhoneForInstitution(
                    $cashoutData['beneficiary_phone'], $destParticipant
                );
            }
            
            // Request token from destination institution
            $tokenResult = $this->requestTokenFromDestination(
                $swapRef, 
                $source, 
                $destination, 
                $holdResult, 
                $destParticipant, 
                $netAmount,
                $currency
            );
            
            $generatedCode = $tokenResult['generated_code'] ?? $tokenResult['pin'] ?? null;
            $satNumber = $tokenResult['sat_number'] ?? $tokenResult['token_reference'] ?? null;
            
            if (!$generatedCode) {
                throw new RuntimeException("Destination institution did not return a withdrawal code");
            }
            
            // Store destination token
            $this->storeDestinationToken($swapId, $tokenResult);
            
            // Send SMS with code
            $this->sendWithdrawalSms(
                $cashoutData['beneficiary_phone'] ?? '', 
                $generatedCode,
                $satNumber,
                $netAmount, 
                $currency,
                $tokenResult['expires_at'] ?? null
            );
            
            // Queue settlement message
            $this->queueSettlementMessage($swapRef, $source, $destination, $grossAmount, $holdResult, null);
            
            // Update net position
            $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'cashout', $currency);
            
            $this->logEvent($swapRef, 'CASHOUT_COMPLETED', [
                'gross_amount' => $grossAmount,
                'net_amount' => $netAmount,
                'fee_breakdown' => $feeCalculation,
                'is_free_retry' => $isFreeRetry,
                'destination_code_generated' => true
            ]);
            
            return [
                'generated_code' => $generatedCode,
                'sat_number' => $satNumber,
                'token_reference' => $tokenResult['token_reference'] ?? $satNumber,
                'expires_at' => $tokenResult['expires_at'] ?? null,
                'net_amount' => $netAmount,
                'gross_amount' => $grossAmount,
                'fee_breakdown' => $feeCalculation,
                'is_free_retry' => $isFreeRetry,
                'currency' => $currency
            ];
            
        } catch (Exception $e) {
            $debug[] = ['time' => microtime(true), 'step' => 'CASHOUT_EXCEPTION', 'error' => $e->getMessage()];
            error_log("CASHOUT ERROR: " . json_encode($debug));
            
            // Record failure for retry eligibility
            try {
                $clientIdentifier = $this->extractClientIdentifier($source);
                $this->recordFailedCashout($clientIdentifier, $swapRef, $e->getMessage());
            } catch (Exception $recordError) {
                error_log("Failed to record cashout failure: " . $recordError->getMessage());
            }
            
            throw $e;
        }
    }

    /**
     * Debit source funds after successful delivery
     */
    private function debitSourceFunds(string $swapRef, array $source, array $holdResult, array $participant): void
    {
        $this->logEvent($swapRef, 'DEBITING_FUNDS', [
            'hold_reference' => $holdResult['hold_reference']
        ]);

        $bankClient = new GenericBankClient($participant);
        
        $debitPayload = [
            'reference' => $swapRef,
            'hold_reference' => $holdResult['hold_reference'],
            'amount' => $source['amount'],
            'reason' => 'Swap completed' . ($this->fxContext ? ' (FX applied)' : '')
        ];

        $result = $bankClient->debitHold($debitPayload);
        
        $this->debugApiCall('debit_hold', $debitPayload, $result);
        
        $this->logApiMessage(
            $swapRef,
            'debit_hold',
            'outgoing',
            $participant,
            '/api/debit-hold',
            $debitPayload,
            $result,
            null
        );

        if (!isset($result['success']) || $result['success'] !== true) {
            $errorMsg = 'Failed to debit funds';
            if (isset($result['curl_error']) && !empty($result['curl_error'])) {
                $errorMsg .= ': ' . $result['curl_error'];
            } elseif (isset($result['status_code'])) {
                $errorMsg .= ': HTTP ' . $result['status_code'];
            }
            throw new RuntimeException($errorMsg);
        }

        $bankResponse = $result['data'] ?? [];

        if (!isset($bankResponse['debited']) || $bankResponse['debited'] !== true) {
            $errorMessage = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Debit failed';
            throw new RuntimeException("Failed to debit funds: " . $errorMessage);
        }

        $this->updateHoldStatus($holdResult['hold_reference'], 'DEBITED');
        
        $this->logEvent($swapRef, 'FUNDS_DEBITED', [
            'hold_reference' => $holdResult['hold_reference']
        ]);
    }

    /**
     * Process card load - UPDATED for FX
     */
    private function processCardLoad(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'CARD_LOAD_START'];
        
        try {
            // Use FX-converted amount if available, otherwise use destination amount
            $grossAmount = $this->fxContext 
                ? (float)($this->fxContext['destination_amount'] ?? $destination['amount'])
                : (float)$destination['amount'];
            
            $cardType = $this->getCardType($destination);
            
            $debug[] = ['time' => microtime(true), 'step' => 'CARD_TYPE_DETERMINED', 
                       'type' => $cardType, 
                       'institution' => $destination['institution'],
                       'amount' => $grossAmount,
                       'currency' => $currency];
            
            if ($cardType === 'balance_based') {
                $this->processBalanceBasedCardLoad($swapId, $swapRef, $source, $destination, $currency, $holdResult, $grossAmount);
            } else {
                $this->processMessageBasedCardLoad($swapId, $swapRef, $source, $destination, $currency, $holdResult, $grossAmount);
            }
            
        } catch (Exception $e) {
            $debug[] = ['time' => microtime(true), 'step' => 'CARD_LOAD_EXCEPTION', 'error' => $e->getMessage()];
            error_log("CARD LOAD ERROR: " . json_encode($debug));
            throw $e;
        }
    }

    /**
     * Process balance-based card load
     */
    private function processBalanceBasedCardLoad(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult, float $grossAmount): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'BALANCE_CARD_START'];
        
        $feeDetails = $this->deductSwapFee(
            $swapRef,
            'CARD_LOAD',
            $grossAmount,
            $source['institution'],
            $destination['institution']
        );
        
        $netAmount = $feeDetails['net_amount'];
        $debug[] = ['time' => microtime(true), 'step' => 'FEE_DEDUCTED', 'fee' => $feeDetails['fee_amount'], 'net' => $netAmount];
        
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardSuffix = $destination['card_suffix'] ?? null;
        if (!$cardSuffix) {
            throw new RuntimeException("card_suffix is required for card_load");
        }
        
        $debug[] = ['time' => microtime(true), 'step' => 'LOADING_BALANCE_CARD', 'card_suffix' => $cardSuffix];
        
        $cardResult = $this->cardService->loadCard([
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'card_suffix' => $cardSuffix,
            'amount' => $netAmount,
            'currency' => $currency,
            'load_type' => 'balance'
        ]);
        
        $debug[] = ['time' => microtime(true), 'step' => 'BALANCE_CARD_LOADED', 
                   'card_suffix' => $cardResult['card_suffix'] ?? $cardSuffix,
                   'new_balance' => $cardResult['new_balance'] ?? null];
        
        $this->updateSwapMetadata($swapRef, [
            'card_load_result' => [
                'card_suffix' => $cardSuffix,
                'amount' => $netAmount,
                'currency' => $currency,
                'new_balance' => $cardResult['new_balance'] ?? null,
                'card_type' => 'balance_based',
                'fx_applied' => $this->fxContext ? true : false
            ]
        ]);
        
        $this->logEvent($swapRef, 'BALANCE_CARD_LOADED', [
            'card_suffix' => $cardSuffix,
            'amount' => $netAmount,
            'currency' => $currency
        ]);
        
        $this->queueSettlementMessage($swapRef, $source, $destination, $grossAmount, $holdResult, $feeDetails);
        $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'card_load', $currency);
    }

    /**
     * Process message-based card load
     */
    private function processMessageBasedCardLoad(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult, float $grossAmount): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'MESSAGE_CARD_START'];
        
        $feeDetails = $this->calculateFees($swapRef, 'CARD_LOAD', $grossAmount);
        $netAmount = $feeDetails['net_amount'];
        
        $debug[] = ['time' => microtime(true), 'step' => 'FEES_CALCULATED', 
                    'fee' => $feeDetails['fee_amount'], 
                    'net' => $netAmount,
                    'gross' => $grossAmount];
        
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardSuffix = $destination['card_suffix'] ?? null;
        if (!$cardSuffix) {
            throw new RuntimeException("card_suffix is required for card_load");
        }
        
        $debug[] = ['time' => microtime(true), 'step' => 'AUTHORIZING_MESSAGE_CARD', 'card_suffix' => $cardSuffix];
        
        $authData = [
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'card_suffix' => $cardSuffix,
            'amount' => $netAmount,
            'authorized_amount' => $netAmount,
            'gross_amount' => $grossAmount,
            'fee_details' => $feeDetails,
            'source_institution' => $source['institution'],
            'source_hold_reference' => $holdResult['hold_reference'],
            'currency' => $currency,
            'expiry_days' => self::MESSAGE_CARD_EXPIRY_DAYS,
            'metadata' => [
                'source_institution' => $source['institution'],
                'source_asset_type' => $source['asset_type'],
                'destination_institution' => $destination['institution'],
                'gross_amount' => $grossAmount,
                'fee_amount' => $feeDetails['fee_amount'],
                'fx_applied' => $this->fxContext ? true : false
            ]
        ];
        
        if (!method_exists($this->cardService, 'authorizeCardLoad')) {
            error_log("[CRITICAL] authorizeCardLoad method missing in CardService!");
            
            if (method_exists($this->cardService, 'loadCard')) {
                error_log("[FALLBACK] Using loadCard instead");
                $cardResult = $this->cardService->loadCard([
                    'hold_reference' => $holdResult['hold_reference'],
                    'swap_reference' => $swapRef,
                    'card_suffix' => $cardSuffix,
                    'amount' => $netAmount,
                    'currency' => $currency
                ]);
                
                $cardResult['authorized'] = true;
                $cardResult['authorized_amount'] = $netAmount;
                $cardResult['gross_amount'] = $grossAmount;
                $cardResult['status'] = 'AUTHORIZED';
            } else {
                throw new RuntimeException("Card service has neither authorizeCardLoad nor loadCard methods");
            }
        } else {
            $cardResult = $this->cardService->authorizeCardLoad($authData);
        }
        
        $debug[] = ['time' => microtime(true), 'step' => 'CARD_AUTHORIZED', 
                   'card_suffix' => $cardSuffix,
                   'result' => $cardResult];
        
        $this->recordCardAuthorization($swapId, $swapRef, $cardSuffix, $netAmount, $holdResult, $feeDetails);
        
        $this->updateSwapMetadata($swapRef, [
            'card_authorization' => [
                'card_suffix' => $cardSuffix,
                'authorized_amount' => $netAmount,
                'gross_amount' => $grossAmount,
                'fee_amount' => $feeDetails['fee_amount'],
                'currency' => $currency,
                'hold_reference' => $holdResult['hold_reference'],
                'status' => 'AUTHORIZED',
                'card_type' => 'message_based',
                'fee_details' => $feeDetails,
                'fx_applied' => $this->fxContext ? true : false
            ]
        ]);
        
        $this->logEvent($swapRef, 'MESSAGE_CARD_AUTHORIZED', [
            'card_suffix' => $cardSuffix,
            'amount' => $netAmount,
            'gross_amount' => $grossAmount,
            'currency' => $currency,
            'fee' => $feeDetails['fee_amount'],
            'hold_reference' => $holdResult['hold_reference']
        ]);
    }

    /**
     * Process card issuance
     */
    private function processCardIssuance(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'CARD_ISSUANCE_START'];
        
        try {
            $grossAmount = $this->fxContext 
                ? (float)($this->fxContext['destination_amount'] ?? $destination['amount'])
                : (float)$destination['amount'];
            $cardType = $this->getCardType($destination);
            
            $debug[] = ['time' => microtime(true), 'step' => 'CARD_TYPE_DETERMINED', 
                       'type' => $cardType, 
                       'institution' => $destination['institution'],
                       'amount' => $grossAmount,
                       'currency' => $currency];
            
            if ($cardType === 'balance_based') {
                $this->processBalanceBasedCardIssuance($swapId, $swapRef, $source, $destination, $currency, $holdResult, $grossAmount);
            } else {
                $this->processMessageBasedCardIssuance($swapId, $swapRef, $source, $destination, $currency, $holdResult, $grossAmount);
            }
            
        } catch (Exception $e) {
            $debug[] = ['time' => microtime(true), 'step' => 'CARD_ISSUANCE_EXCEPTION', 'error' => $e->getMessage()];
            error_log("CARD ISSUANCE ERROR: " . json_encode($debug));
            throw $e;
        }
    }

    /**
     * Process balance-based card issuance
     */
    private function processBalanceBasedCardIssuance(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult, float $grossAmount): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'BALANCE_CARD_ISSUANCE_START'];
        
        $feeDetails = $this->deductSwapFee(
            $swapRef,
            'CARD_ISSUANCE',
            $grossAmount,
            $source['institution'],
            $destination['institution']
        );
        
        $netAmount = $feeDetails['net_amount'];
        $debug[] = ['time' => microtime(true), 'step' => 'FEE_DEDUCTED', 'fee' => $feeDetails['fee_amount'], 'net' => $netAmount];
        
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardData = $destination['card'] ?? [];
        $cardholderName = $cardData['cardholder_name'] ?? 
                         $destination['beneficiary_name'] ?? 
                         $source['cardholder_name'] ?? 
                         'Cardholder';
        
        $debug[] = ['time' => microtime(true), 'step' => 'ISSUING_BALANCE_CARD', 'cardholder' => $cardholderName];
        
        $cardPayload = [
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'cardholder_name' => $cardholderName,
            'initial_amount' => $netAmount,
            'daily_limit' => $cardData['daily_limit'] ?? null,
            'monthly_limit' => $cardData['monthly_limit'] ?? null,
            'atm_daily_limit' => $cardData['atm_daily_limit'] ?? null,
            'currency' => $currency,
            'issued_by' => 'swap_service',
            'card_type' => 'balance_based',
            'metadata' => [
                'source_institution' => $source['institution'],
                'source_asset_type' => $source['asset_type'],
                'destination_institution' => $destination['institution'],
                'fx_applied' => $this->fxContext ? true : false
            ]
        ];
        
        $cardResult = $this->cardService->issueCard($cardPayload);
        
        $debug[] = ['time' => microtime(true), 'step' => 'BALANCE_CARD_ISSUED', 
                   'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4)];
        
        $this->updateSwapMetadata($swapRef, [
            'card_issuance_result' => [
                'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4),
                'expiry' => $cardResult['expiry'] ?? null,
                'amount' => $netAmount,
                'currency' => $currency,
                'card_id' => $cardResult['card_id'] ?? null,
                'card_type' => 'balance_based',
                'fx_applied' => $this->fxContext ? true : false
            ]
        ]);
        
        $this->logEvent($swapRef, 'BALANCE_CARD_ISSUED', [
            'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4),
            'amount' => $netAmount,
            'currency' => $currency
        ]);
        
        $this->queueSettlementMessage($swapRef, $source, $destination, $grossAmount, $holdResult, $feeDetails);
        $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'card_issuance', $currency);
    }

    /**
     * Process message-based card issuance
     */
    private function processMessageBasedCardIssuance(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult, float $grossAmount): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'MESSAGE_CARD_ISSUANCE_START'];
        
        $feeDetails = $this->calculateFees($swapRef, 'CARD_ISSUANCE', $grossAmount);
        $debug[] = ['time' => microtime(true), 'step' => 'FEES_CALCULATED', 'fee' => $feeDetails['fee_amount']];
        
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardData = $destination['card'] ?? [];
        $cardholderName = $cardData['cardholder_name'] ?? 
                         $destination['beneficiary_name'] ?? 
                         $source['cardholder_name'] ?? 
                         'Cardholder';
        
        $debug[] = ['time' => microtime(true), 'step' => 'ISSUING_MESSAGE_CARD', 'cardholder' => $cardholderName];
        
        $cardPayload = [
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'cardholder_name' => $cardholderName,
            'authorized_amount' => $grossAmount,
            'daily_limit' => $cardData['daily_limit'] ?? null,
            'monthly_limit' => $cardData['monthly_limit'] ?? null,
            'atm_daily_limit' => $cardData['atm_daily_limit'] ?? null,
            'currency' => $currency,
            'issued_by' => 'swap_service',
            'card_type' => 'message_based',
            'status' => 'AUTHORIZED',
            'expiry' => date('Y-m-d H:i:s', strtotime('+' . self::MESSAGE_CARD_EXPIRY_DAYS . ' days')),
            'metadata' => [
                'source_institution' => $source['institution'],
                'source_asset_type' => $source['asset_type'],
                'destination_institution' => $destination['institution'],
                'fee_details' => $feeDetails,
                'fx_applied' => $this->fxContext ? true : false
            ]
        ];
        
        $cardResult = $this->cardService->issueCard($cardPayload);
        
        $debug[] = ['time' => microtime(true), 'step' => 'MESSAGE_CARD_ISSUED', 
                   'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4)];
        
        $this->recordCardAuthorization($swapId, $swapRef, $cardResult['card_suffix'] ?? '', $grossAmount, $holdResult, $feeDetails);
        
        $this->updateSwapMetadata($swapRef, [
            'card_issuance_result' => [
                'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4),
                'expiry' => $cardResult['expiry'] ?? null,
                'authorized_amount' => $grossAmount,
                'currency' => $currency,
                'card_id' => $cardResult['card_id'] ?? null,
                'card_type' => 'message_based',
                'status' => 'AUTHORIZED',
                'fx_applied' => $this->fxContext ? true : false
            ]
        ]);
        
        $this->logEvent($swapRef, 'MESSAGE_CARD_ISSUED', [
            'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4),
            'amount' => $grossAmount,
            'currency' => $currency
        ]);
    }

    private function recordCardAuthorization(int $swapId, string $swapRef, string $cardSuffix, float $amount, array $holdResult, array $feeDetails): void
    {
        $expiryDays = $this->cardConfig['authorization_expiry_days'] ?? self::MESSAGE_CARD_EXPIRY_DAYS;
        
        $stmt = $this->swapDB->prepare("
            INSERT INTO card_authorizations 
            (swap_id, swap_reference, card_suffix, authorized_amount, 
             remaining_balance, hold_reference, source_institution, 
             fee_amount, vat_amount, status, expiry_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NOW() + (? || ' days')::INTERVAL, NOW())
        ");
        
        $stmt->execute([
            $swapId,
            $swapRef,
            $cardSuffix,
            $amount,
            $amount,
            $holdResult['hold_reference'],
            $source['institution'] ?? 'SACCUSSALIS',
            $feeDetails['fee_amount'],
            $feeDetails['vat_amount'],
            $expiryDays
        ]);
    }

    /**
     * Request token/code from destination institution - UPDATED for FX
     */
    private function requestTokenFromDestination(
        string $swapRef, 
        array $source, 
        array $destination, 
        array $holdResult, 
        array $participant, 
        ?float $netAmount = null,
        ?string $currency = null
    ): array {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'REQUEST_TOKEN_START'];
        
        try {
            $bankClient = new GenericBankClient($participant);
            $debug[] = ['time' => microtime(true), 'step' => 'BANK_CLIENT_CREATED'];

            $payload = [
                'reference' => $swapRef,
                'source_institution' => $source['institution'],
                'source_hold_reference' => $holdResult['hold_reference'] ?? null,
                'source_asset_type' => $source['asset_type'],
                'beneficiary_phone' => $destination['cashout']['beneficiary_phone'] ?? '',
                'amount' => $netAmount ?? $destination['amount'],
                'currency' => $currency ?? 'BWP',
                'code_hash' => null,
                'action' => 'GENERATE_ATM_TOKEN'
            ];
            
            // Add FX info if available
            if ($this->fxContext && $this->fxContext['needs_fx']) {
                $payload['fx_info'] = [
                    'source_currency' => $this->fxContext['source_currency'],
                    'destination_currency' => $this->fxContext['destination_currency'],
                    'exchange_rate' => $this->fxContext['rate'],
                    'original_amount' => $this->fxContext['source_amount'],
                    'converted_amount' => $this->fxContext['destination_amount']
                ];
            }
            
            $debug[] = ['time' => microtime(true), 'step' => 'PAYLOAD_PREPARED'];

            error_log("REQUEST_TOKEN: About to call bankClient->transfer with payload: " . json_encode($payload));
            
            $result = $bankClient->transfer($payload, 'generate_atm_code');
            
            $debug[] = ['time' => microtime(true), 'step' => 'API_CALL_COMPLETE', 'result_success' => $result['success'] ?? false];
            error_log("REQUEST_TOKEN: API call result: " . json_encode($result));

            $this->debugApiCall('generate_token', $payload, $result);

            $this->logApiMessage(
                $swapRef,
                'generate_token',
                'outgoing',
                $participant,
                '/api/generate-token',
                $payload,
                $result,
                null
            );

            if (!isset($result['success']) || $result['success'] !== true) {
                $errorMsg = 'Bank communication failed';
                if (isset($result['curl_error']) && !empty($result['curl_error'])) {
                    $errorMsg .= ': ' . $result['curl_error'];
                    $debug[] = ['time' => microtime(true), 'step' => 'CURL_ERROR', 'error' => $result['curl_error']];
                } elseif (isset($result['status_code'])) {
                    $errorMsg .= ': HTTP ' . $result['status_code'];
                    $debug[] = ['time' => microtime(true), 'step' => 'HTTP_ERROR', 'code' => $result['status_code']];
                }
                error_log("REQUEST_TOKEN ERROR: " . $errorMsg);
                throw new RuntimeException("Failed to generate token: " . $errorMsg);
            }

            $bankResponse = $result['data'] ?? [];
            $debug[] = ['time' => microtime(true), 'step' => 'BANK_RESPONSE_RECEIVED'];

            $this->logEvent($swapRef, 'TOKEN_BANK_RESPONSE', [
                'bank_response' => $bankResponse
            ]);

            if (!isset($bankResponse['token_generated']) || $bankResponse['token_generated'] !== true) {
                $errorMsg = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Unknown error';
                $debug[] = ['time' => microtime(true), 'step' => 'TOKEN_NOT_GENERATED', 'error' => $errorMsg];
                throw new RuntimeException("Failed to generate token: " . $errorMsg);
            }

            $generatedCode = $bankResponse['pin'] ?? $bankResponse['atm_pin'] ?? null;
            $tokenReference = $bankResponse['token_reference'] ?? $bankResponse['sat_number'] ?? null;
            
            $this->logEvent($swapRef, 'TOKEN_GENERATED', [
                'institution' => $participant['provider_code'] ?? $destination['institution'],
                'token_reference' => $tokenReference,
                'has_code' => !empty($generatedCode)
            ]);
            
            $debug[] = ['time' => microtime(true), 'step' => 'TOKEN_GENERATED_SUCCESS'];
            error_log("REQUEST_TOKEN SUCCESS - Code: " . ($generatedCode ? substr($generatedCode, -4) : 'null'));

            return [
                'token_generated' => true,
                'generated_code' => $generatedCode,
                'pin' => $generatedCode,
                'token_reference' => $tokenReference,
                'sat_number' => $bankResponse['sat_number'] ?? $tokenReference,
                'expires_at' => $bankResponse['expires_at'] ?? $bankResponse['expiry'] ?? null,
                'instrument_id' => $bankResponse['instrument_id'] ?? null,
                'sat_id' => $bankResponse['sat_id'] ?? null,
                'issuer_bank' => $bankResponse['issuer_bank'] ?? null,
                'acquirer_network' => $bankResponse['acquirer_network'] ?? null,
                'raw_response' => $bankResponse
            ];

        } catch (Exception $e) {
            $debug[] = ['time' => microtime(true), 'step' => 'REQUEST_TOKEN_EXCEPTION', 'error' => $e->getMessage()];
            error_log("REQUEST_TOKEN EXCEPTION: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Store destination token reference in database
     */
    private function storeDestinationToken(int $swapId, array $tokenResult): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE swap_requests 
                SET metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb
                WHERE swap_id = ?
            ");
            
            $metadata = json_encode([
                'destination_token' => [
                    'token_reference' => $tokenResult['token_reference'] ?? null,
                    'sat_number' => $tokenResult['sat_number'] ?? null,
                    'generated_code' => $tokenResult['generated_code'] ?? $tokenResult['pin'] ?? null,
                    'expires_at' => $tokenResult['expires_at'] ?? null,
                    'instrument_id' => $tokenResult['instrument_id'] ?? null,
                    'sat_id' => $tokenResult['sat_id'] ?? null,
                    'issuer_bank' => $tokenResult['issuer_bank'] ?? null,
                    'acquirer_network' => $tokenResult['acquirer_network'] ?? null,
                    'institution' => 'DESTINATION'
                ]
            ]);
            
            $stmt->execute([$metadata, $swapId]);
            
            $this->logEvent((string)$swapId, 'DESTINATION_TOKEN_STORED', [
                'token_reference' => $tokenResult['token_reference'] ?? null,
                'has_code' => !empty($tokenResult['generated_code']),
                'expires_at' => $tokenResult['expires_at'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Failed to store destination token: " . $e->getMessage());
        }
    }

    private function sendWithdrawalSms(string $phone, string $code, ?string $satNumber, float $amount, string $currency, ?string $expiry = null): void
    {
        $expiryText = $expiry ? date('Y-m-d H:i:s', strtotime($expiry)) : date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        error_log("=== SMS WOULD BE SENT ===");
        error_log("To: " . $phone);
        error_log("Code: " . $code);
        error_log("SAT Number: " . ($satNumber ?? 'N/A'));
        error_log("Amount: " . $amount . " " . $currency);
        error_log("Expires: " . $expiryText);
        
        if ($this->smsService) {
            $message = "Your withdrawal code is: {$code}\n";
            $message .= "Amount: {$amount} {$currency}\n";
            if ($satNumber) {
                $message .= "Reference: {$satNumber}\n";
            }
            $message .= "Valid until: {$expiryText}";
            
            $this->smsService->send($phone, $message);
            error_log("SMS SENT via service");
        } else {
            error_log("SMS NOT SENT - service unavailable");
        }
    }

    /**
     * Cancel a pending swap
     */
    public function cancelSwap(string $swapRef, string $reason = 'User requested cancellation', ?int $cancelledBy = null): array
    {
        $this->swapDB->beginTransaction();
        
        try {
            $stmt = $this->swapDB->prepare("
                SELECT 
                    sr.*,
                    ht.hold_reference,
                    ht.status as hold_status,
                    ht.participant_id,
                    p.config as participant_config,
                    sv.voucher_id,
                    sv.status as voucher_status,
                    ca.authorization_id,
                    ca.status as card_auth_status
                FROM swap_requests sr
                LEFT JOIN hold_transactions ht ON sr.swap_uuid = ht.swap_reference
                LEFT JOIN participants p ON ht.participant_id = p.participant_id
                LEFT JOIN swap_vouchers sv ON sr.swap_id = sv.swap_id
                LEFT JOIN card_authorizations ca ON sr.swap_uuid = ca.swap_reference
                WHERE sr.swap_uuid = ?
                AND sr.status IN ('pending', 'processing')
                FOR UPDATE
            ");
            $stmt->execute([$swapRef]);
            $swap = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$swap) {
                throw new RuntimeException("Swap not found or already completed/cancelled");
            }
            
            error_log("[CANCEL] Cancelling swap: $swapRef, Reason: $reason");
            
            if ($swap['hold_reference'] && $swap['hold_status'] === 'ACTIVE' && $swap['participant_config']) {
                $participant = json_decode($swap['participant_config'], true);
                $bankClient = new GenericBankClient($participant);
                
                $releaseResult = $bankClient->releaseHold([
                    'hold_reference' => $swap['hold_reference'],
                    'reason' => $reason
                ]);
                
                if (!isset($releaseResult['success']) || $releaseResult['success'] !== true) {
                    throw new RuntimeException("Failed to release hold: " . ($releaseResult['message'] ?? 'Unknown error'));
                }
                
                $this->updateHoldStatus($swap['hold_reference'], 'RELEASED');
            }
            
            // Release FX quote if applicable
            if (isset($swap['fx_quote_uuid'])) {
                $this->swapDB->prepare("
                    UPDATE fx_quotes SET status = 'CANCELLED' WHERE quote_uuid = ?
                ")->execute([$swap['fx_quote_uuid']]);
            }
            
            if ($swap['voucher_id'] && $swap['voucher_status'] === 'ACTIVE') {
                $voidStmt = $this->swapDB->prepare("
                    UPDATE swap_vouchers 
                    SET status = 'VOIDED',
                        voided_at = NOW(),
                        void_reason = ?
                    WHERE voucher_id = ?
                ");
                $voidStmt->execute([$reason, $swap['voucher_id']]);
            }
            
            if ($swap['authorization_id'] && $swap['card_auth_status'] === 'ACTIVE') {
                $voidAuth = $this->swapDB->prepare("
                    UPDATE card_authorizations 
                    SET status = 'VOIDED',
                        voided_at = NOW(),
                        void_reason = ?
                    WHERE authorization_id = ?
                ");
                $voidAuth->execute([$reason, $swap['authorization_id']]);
            }
            
            $updateStmt = $this->swapDB->prepare("
                UPDATE swap_requests 
                SET status = 'cancelled',
                    metadata = jsonb_set(
                        COALESCE(metadata, '{}'::jsonb),
                        '{cancellation}',
                        jsonb_build_object(
                            'cancelled_at', to_jsonb(NOW()),
                            'cancelled_by', to_jsonb(?),
                            'reason', to_jsonb(?)
                        )
                    )
                WHERE swap_uuid = ?
                RETURNING swap_id
            ");
            $updateStmt->execute([$cancelledBy ?? 'system', $reason, $swapRef]);
            
            if ($swap['hold_reference']) {
                $queueStmt = $this->swapDB->prepare("
                    DELETE FROM settlement_queue 
                    WHERE hold_reference = ? AND status = 'PENDING'
                ");
                $queueStmt->execute([$swap['hold_reference']]);
            }
            
            $this->logApiMessage(
                $swapRef,
                'swap_cancelled',
                'internal',
                null,
                '/api/swap/cancel',
                ['reason' => $reason],
                ['success' => true],
                null
            );
            
            $this->logEvent($swapRef, 'SWAP_CANCELLED', [
                'reason' => $reason,
                'cancelled_by' => $cancelledBy ?? 'system'
            ]);
            
            $this->swapDB->commit();
            
            return [
                'success' => true,
                'message' => 'Swap cancelled successfully',
                'swap_reference' => $swapRef,
                'hold_released' => !empty($swap['hold_reference'])
            ];
            
        } catch (Exception $e) {
            $this->swapDB->rollBack();
            error_log("[CANCEL] Failed for $swapRef: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Record card swipe transaction
     */
    public function recordCardSwipe(string $cardSuffix, float $amount, string $merchantId): array
    {
        $stmt = $this->swapDB->prepare("
            SELECT * FROM card_authorizations 
            WHERE card_suffix = ? AND status = 'ACTIVE'
            AND expiry_at > NOW()
            FOR UPDATE
        ");
        $stmt->execute([$cardSuffix]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$card) {
            throw new RuntimeException("Card authorization not found or expired");
        }
        
        if ($card['remaining_balance'] < $amount) {
            throw new RuntimeException("Insufficient funds on card");
        }
        
        $feePortion = ($amount / $card['authorized_amount']) * $card['fee_amount'];
        $vatPortion = ($amount / $card['authorized_amount']) * $card['vat_amount'];
        
        $holdRef = $card['hold_reference'];
        $sourceInstitution = $card['source_institution'];
        
        $txnId = $this->recordCardTransaction($cardSuffix, $amount, $merchantId, $holdRef, $feePortion, $vatPortion);
        
        $this->queueSettlementForCardSwipe($txnId, $holdRef, $sourceInstitution, $merchantId, $amount, $feePortion);
        
        $newBalance = $card['remaining_balance'] - $amount;
        $updateStmt = $this->swapDB->prepare("
            UPDATE card_authorizations 
            SET remaining_balance = ?,
                used_amount = used_amount + ?,
                updated_at = NOW()
            WHERE authorization_id = ?
        ");
        $updateStmt->execute([$newBalance, $amount, $card['authorization_id']]);
        
        return [
            'success' => true,
            'transaction_id' => $txnId,
            'remaining_balance' => $newBalance,
            'fee_deducted' => $feePortion
        ];
    }

    private function recordCardTransaction(string $cardSuffix, float $amount, string $merchantId, string $holdRef, float $feePortion, float $vatPortion): string
    {
        $txnId = 'CARD-' . uniqid() . '-' . bin2hex(random_bytes(4));
        
        $stmt = $this->swapDB->prepare("
            INSERT INTO card_transactions 
            (transaction_id, card_suffix, amount, merchant_id, hold_reference, 
             fee_amount, vat_amount, status, settlement_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETED', 'PENDING', NOW())
        ");
        
        $stmt->execute([$txnId, $cardSuffix, $amount, $merchantId, $holdRef, $feePortion, $vatPortion]);
        
        return $txnId;
    }

    private function queueSettlementForCardSwipe(string $txnId, string $holdRef, string $sourceInstitution, string $merchantId, float $amount, float $feePortion): void
    {
        $stmt = $this->swapDB->prepare("
            INSERT INTO settlement_queue 
            (transaction_id, hold_reference, source_institution, destination_institution,
             amount, fee_amount, type, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'CARD_SWIPE', 'PENDING', NOW())
        ");
        
        $stmt->execute([
            $txnId,
            $holdRef,
            $sourceInstitution,
            $merchantId,
            $amount,
            $feePortion
        ]);
    }

    /**
     * Process card settlements
     */
    public function processCardSettlements(): array
    {
        $stats = ['processed' => 0, 'total_amount' => 0, 'total_fees' => 0];
        
        $pending = $this->swapDB->prepare("
            SELECT sq.*, ca.source_institution, ca.hold_reference, p.config as participant_config
            FROM settlement_queue sq
            JOIN card_authorizations ca ON sq.hold_reference = ca.hold_reference
            LEFT JOIN participants p ON ca.source_institution = p.name OR ca.source_institution = p.provider_code
            WHERE sq.type = 'CARD_SWIPE' AND sq.status = 'PENDING'
            ORDER BY sq.created_at ASC
            LIMIT 100
            FOR UPDATE SKIP LOCKED
        ");
        $pending->execute();
        
        while ($txn = $pending->fetch(PDO::FETCH_ASSOC)) {
            try {
                $this->swapDB->beginTransaction();
                
                $sourceKey = $this->findInstitutionKey($txn['source_institution']);
                if (!$sourceKey || !isset($this->participants[$sourceKey])) {
                    throw new RuntimeException("Source institution not found: {$txn['source_institution']}");
                }
                
                $sourceParticipant = $this->participants[$sourceKey];
                $bankClient = new GenericBankClient($sourceParticipant);
                
                $debitResult = $bankClient->debitHold([
                    'hold_reference' => $txn['hold_reference'],
                    'amount' => $txn['amount'],
                    'reference' => $txn['transaction_id'],
                    'reason' => 'Card swipe settlement'
                ]);
                
                if (!isset($debitResult['success']) || $debitResult['success'] !== true) {
                    throw new RuntimeException("Failed to debit source: " . ($debitResult['message'] ?? 'Unknown'));
                }
                
                $this->creditMerchant($txn['destination_institution'], $txn['amount'], $txn['transaction_id']);
                
                $updateStmt = $this->swapDB->prepare("
                    UPDATE settlement_queue 
                    SET status = 'SETTLED', settled_at = NOW()
                    WHERE transaction_id = ?
                ");
                $updateStmt->execute([$txn['transaction_id']]);
                
                $updateCardTxn = $this->swapDB->prepare("
                    UPDATE card_transactions 
                    SET settlement_status = 'SETTLED', settled_at = NOW()
                    WHERE transaction_id = ?
                ");
                $updateCardTxn->execute([$txn['transaction_id']]);
                
                $this->swapDB->commit();
                
                $stats['processed']++;
                $stats['total_amount'] += $txn['amount'];
                $stats['total_fees'] += $txn['fee_amount'];
                
            } catch (Exception $e) {
                $this->swapDB->rollBack();
                error_log("Failed to settle card transaction {$txn['transaction_id']}: " . $e->getMessage());
                
                $failStmt = $this->swapDB->prepare("
                    UPDATE settlement_queue 
                    SET status = 'FAILED', error_message = ?
                    WHERE transaction_id = ?
                ");
                $failStmt->execute([$e->getMessage(), $txn['transaction_id']]);
            }
        }
        
        return $stats;
    }

    private function creditMerchant(string $merchantId, float $amount, string $reference): void
    {
        $stmt = $this->swapDB->prepare("
            INSERT INTO merchant_settlements 
            (merchant_id, amount, reference, status, created_at)
            VALUES (?, ?, ?, 'PENDING', NOW())
        ");
        $stmt->execute([$merchantId, $amount, $reference]);
    }

    /**
     * Process expired holds
     */
    public function processExpiredHolds(): array
    {
        $stats = [
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
            'holds_released' => 0,
            'swaps_updated' => 0,
            'card_auths_voided' => 0
        ];
        
        try {
            $expired = $this->swapDB->prepare("
                SELECT 
                    ht.hold_id,
                    ht.hold_reference,
                    ht.swap_reference,
                    ht.participant_id,
                    ht.amount,
                    ht.hold_expiry,
                    p.config as participant_config,
                    sr.swap_id,
                    sr.status as swap_status,
                    ca.authorization_id
                FROM hold_transactions ht
                JOIN swap_requests sr ON ht.swap_reference = sr.swap_uuid
                LEFT JOIN participants p ON ht.participant_id = p.participant_id
                LEFT JOIN card_authorizations ca ON ht.hold_reference = ca.hold_reference AND ca.status = 'ACTIVE'
                WHERE ht.status = 'ACTIVE' 
                AND ht.hold_expiry < NOW()
                ORDER BY ht.hold_expiry ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            ");
            $expired->bindValue(':limit', self::EXPIRY_BATCH_SIZE, PDO::PARAM_INT);
            $expired->execute();
            
            while ($hold = $expired->fetch(PDO::FETCH_ASSOC)) {
                try {
                    $this->swapDB->beginTransaction();
                    
                    $this->logEvent($hold['swap_reference'], 'EXPIRY_PROCESSING', [
                        'hold_reference' => $hold['hold_reference'],
                        'expiry_time' => $hold['hold_expiry']
                    ]);
                    
                    $holdReleased = false;
                    if ($hold['participant_config']) {
                        try {
                            $participant = json_decode($hold['participant_config'], true);
                            $bankClient = new GenericBankClient($participant);
                            
                            $releaseResult = $bankClient->releaseHold([
                                'hold_reference' => $hold['hold_reference'],
                                'reason' => 'Hold expired after ' . self::HOLD_EXPIRY_HOURS . ' hours'
                            ]);
                            
                            if (isset($releaseResult['success']) && $releaseResult['success'] === true) {
                                $holdReleased = true;
                            } else {
                                $errorMsg = $releaseResult['message'] ?? 'Unknown error';
                                throw new RuntimeException("Bank release failed: " . $errorMsg);
                            }
                        } catch (Exception $e) {
                            $this->logEvent($hold['swap_reference'], 'EXPIRY_BANK_RELEASE_FAILED', [
                                'hold_reference' => $hold['hold_reference'],
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    $updateHold = $this->swapDB->prepare("
                        UPDATE hold_transactions 
                        SET status = 'EXPIRED', 
                            released_at = NOW(),
                            metadata = jsonb_set(
                                COALESCE(metadata, '{}'::jsonb),
                                '{expiry_details}',
                                jsonb_build_object(
                                    'expired_at', to_jsonb(NOW()),
                                    'bank_released', to_jsonb(?),
                                    'reason', 'Auto-expired after 24 hours'
                                )
                            )
                        WHERE hold_reference = ?
                    ");
                    $updateHold->execute([$holdReleased ? 'true' : 'false', $hold['hold_reference']]);
                    $stats['holds_released']++;
                    
                    if ($hold['authorization_id']) {
                        $voidAuth = $this->swapDB->prepare("
                            UPDATE card_authorizations 
                            SET status = 'EXPIRED', 
                                expired_at = NOW(),
                                void_reason = 'Associated hold expired'
                            WHERE authorization_id = ?
                        ");
                        $voidAuth->execute([$hold['authorization_id']]);
                        $stats['card_auths_voided']++;
                    }
                    
                    $updateSwap = $this->swapDB->prepare("
                        UPDATE swap_requests 
                        SET status = 'expired',
                            metadata = jsonb_set(
                                COALESCE(metadata, '{}'::jsonb),
                                '{expiry}',
                                jsonb_build_object(
                                    'expired_at', to_jsonb(NOW()),
                                    'hold_reference', to_jsonb(?)
                                )
                            )
                        WHERE swap_uuid = ?
                        AND status IN ('pending', 'processing')
                    ");
                    $updateSwap->execute([$hold['hold_reference'], $hold['swap_reference']]);
                    $stats['swaps_updated'] += $updateSwap->rowCount();
                    
                    $voidVoucher = $this->swapDB->prepare("
                        UPDATE swap_vouchers 
                        SET status = 'EXPIRED', 
                            voided_at = NOW(),
                            void_reason = 'Associated hold expired'
                        WHERE swap_id = ? 
                        AND status = 'ACTIVE'
                    ");
                    $voidVoucher->execute([$hold['swap_id']]);
                    
                    $removeSettlement = $this->swapDB->prepare("
                        DELETE FROM settlement_queue 
                        WHERE hold_reference = ? AND status = 'PENDING'
                    ");
                    $removeSettlement->execute([$hold['hold_reference']]);
                    
                    $this->logEvent($hold['swap_reference'], 'EXPIRY_PROCESSED', [
                        'hold_reference' => $hold['hold_reference'],
                        'bank_released' => $holdReleased
                    ]);
                    
                    $this->swapDB->commit();
                    $stats['processed']++;
                    
                } catch (Exception $e) {
                    $this->swapDB->rollBack();
                    $stats['failed']++;
                    $stats['errors'][] = [
                        'hold' => $hold['hold_reference'],
                        'error' => $e->getMessage()
                    ];
                    
                    $this->logEvent($hold['swap_reference'], 'EXPIRY_PROCESSING_FAILED', [
                        'hold_reference' => $hold['hold_reference'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
        } catch (Exception $e) {
            error_log("[EXPIRY] Fatal error in processExpiredHolds: " . $e->getMessage());
            $stats['fatal_error'] = $e->getMessage();
        }
        
        return $stats;
    }

    public function processExpiredVouchers(): array
    {
        $stats = ['processed' => 0];
        
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE swap_vouchers 
                SET status = 'EXPIRED',
                    metadata = jsonb_set(
                        COALESCE(metadata, '{}'::jsonb),
                        '{expired_at}',
                        to_jsonb(NOW())
                    )
                WHERE expiry_at < NOW() 
                AND status = 'ACTIVE'
            ");
            $stmt->execute();
            
            $stats['processed'] = $stmt->rowCount();
            
            if ($stats['processed'] > 0) {
                $this->logEvent('CRON', 'VOUCHER_EXPIRY_PROCESSED', [
                    'count' => $stats['processed']
                ]);
            }
            
        } catch (Exception $e) {
            error_log("[EXPIRY] Failed to process expired vouchers: " . $e->getMessage());
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }

    public function processExpiredCardAuthorizations(): array
    {
        $stats = ['processed' => 0];
        
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE card_authorizations 
                SET status = 'EXPIRED',
                    expired_at = NOW(),
                    metadata = jsonb_set(
                        COALESCE(metadata, '{}'::jsonb),
                        '{expired_at}',
                        to_jsonb(NOW())
                    )
                WHERE expiry_at < NOW() 
                AND status = 'ACTIVE'
            ");
            $stmt->execute();
            
            $stats['processed'] = $stmt->rowCount();
            
            if ($stats['processed'] > 0) {
                $this->logEvent('CRON', 'CARD_AUTH_EXPIRY_PROCESSED', [
                    'count' => $stats['processed']
                ]);
            }
            
        } catch (Exception $e) {
            error_log("[EXPIRY] Failed to process expired card authorizations: " . $e->getMessage());
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }

    public function processAllExpired(): array
    {
        $result = [
            'holds' => $this->processExpiredHolds(),
            'vouchers' => $this->processExpiredVouchers(),
            'card_authorizations' => $this->processExpiredCardAuthorizations(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        error_log("[EXPIRY] Completed: " . json_encode($result));
        
        return $result;
    }

    private function queueSettlementMessage(string $swapRef, array $source, array $destination, float $amount, array $holdResult, ?array $feeDetails = null): void
    {
        error_log("[QUEUE_SETTLEMENT] Starting for swap: $swapRef");
        
        $beneficiaryPhone = isset($destination['cashout']['beneficiary_phone']) 
            ? substr($destination['cashout']['beneficiary_phone'], -8) 
            : null;
        
        $metadata = [
            'source_type' => $source['asset_type'] ?? 'UNKNOWN',
            'source_reference' => $this->maskIdentifier($source),
            'beneficiary_phone' => $beneficiaryPhone,
            'hold_reference' => $holdResult['hold_reference'] ?? null
        ];
        
        if ($feeDetails) {
            $metadata['fee'] = [
                'fee_id' => $feeDetails['fee_id'] ?? null,
                'total_fee' => $feeDetails['fee_amount'],
                'net_amount' => $feeDetails['net_amount'],
                'split' => $feeDetails['split'] ?? null
            ];
        }
        
        // Add FX info if applicable
        if ($this->fxContext && $this->fxContext['needs_fx']) {
            $metadata['fx'] = [
                'source_currency' => $this->fxContext['source_currency'],
                'destination_currency' => $this->fxContext['destination_currency'],
                'exchange_rate' => $this->fxContext['rate'],
                'source_amount' => $this->fxContext['source_amount'],
                'destination_amount' => $this->fxContext['destination_amount']
            ];
        }

        $stmt = $this->swapDB->prepare("
            INSERT INTO settlement_messages
            (transaction_id, from_participant, to_participant, amount, type, status, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, 'PENDING', ?, NOW())
        ");

        $type = $destination['delivery_mode'] ?? 'DEPOSIT';
        $type = strtoupper($type) . '_SETTLEMENT';

        $stmt->execute([
            $swapRef,
            $source['institution'],
            $destination['institution'],
            $amount,
            $type,
            json_encode($metadata)
        ]);
        
        error_log("[QUEUE_SETTLEMENT] Success");
    }

    private function handleUndispensedAmount(string $origin, array $destination, float $amount, string $ref): void
    {
        if ($destination['asset_type'] === 'E-WALLET') {
            $this->logEvent('MICRO_SWAP', 'INFO', [
                'participant' => $origin, 'amount' => $amount, 'ref' => $ref
            ]);
            $stmt = $this->swapDB->prepare("
                INSERT INTO settlement_messages 
                (transaction_id, from_participant, to_participant, amount, type, status, metadata, created_at)
                VALUES (?, ?, ?, ?, 'RETURN_SETTLEMENT', 'PENDING', ?, NOW())
            ");
            $stmt->execute([
                $ref, $destination['institution'], $origin, $amount,
                json_encode(['reason' => 'undispensed_cashout_amount'])
            ]);
        } else {
            $this->logEvent('SWAP_TO_SWAP', 'INFO', ['amount' => $amount, 'ref' => $ref]);
            $currency = $this->fxContext['destination_currency'] ?? 'BWP';
            $this->settlement->updateNetPosition($destination['institution'], $origin, $amount, 'swap_to_swap', $currency);
        }
    }

    private function recordMasterSwap(
        string $swapRef, 
        array $source, 
        array $destination, 
        string $sourceCurrency,
        string $destinationCurrency,
        array $verificationResult,
        ?float $exchangeRate = null,
        ?string $fxQuoteUuid = null,
        ?string $originalSwapRef = null
    ): int {
        $sourceDetails = [
            'institution' => $source['institution'],
            'asset_type' => $source['asset_type'],
            'reference' => $this->maskIdentifier($source),
            'holder_name' => $verificationResult['asset_details']['holder_name'] ?? null,
            'available_balance' => $verificationResult['asset_details']['available_balance'] ?? null,
            'currency' => $sourceCurrency
        ];

        $destinationAssetType = 'UNKNOWN';
        
        if (isset($destination['delivery_mode']) && $destination['delivery_mode'] === 'cashout') {
            $destinationAssetType = 'CASHOUT';
        } elseif (isset($destination['asset_type'])) {
            $destinationAssetType = $destination['asset_type'];
        } elseif (isset($destination['beneficiary_account'])) {
            $destinationAssetType = 'ACCOUNT';
        } elseif (isset($destination['beneficiary_wallet'])) {
            $destinationAssetType = 'WALLET';
        } elseif (isset($destination['cashout'])) {
            $destinationAssetType = 'CASHOUT';
        } elseif (in_array($destination['delivery_mode'] ?? '', ['card_load', 'card'])) {
            $destinationAssetType = 'CARD';
        }

        $destinationDetails = [
            'institution' => $destination['institution'],
            'asset_type' => $destinationAssetType,
            'delivery_mode' => $destination['delivery_mode'] ?? 'deposit',
            'card_type' => $this->getCardType($destination),
            'currency' => $destinationCurrency,
            'beneficiary' => $destination['cashout']['beneficiary_phone'] ?? 
                            $destination['beneficiary_account'] ?? 
                            $destination['beneficiary_wallet'] ?? 
                            $destination['card_suffix'] ??
                            $destination['cashout']['beneficiary'] ??
                            null
        ];
        
        // Add FX info to destination details if applicable
        if ($exchangeRate) {
            $destinationDetails['exchange_rate'] = $exchangeRate;
            $destinationDetails['original_currency'] = $sourceCurrency;
            $destinationDetails['original_amount'] = $source['amount'];
        }

        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_requests 
            (swap_uuid, from_currency, to_currency, amount, source_details, destination_details, 
             source_currency, destination_currency, exchange_rate, fx_quote_uuid, original_swap_ref, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            RETURNING swap_id
        ");

        $stmt->execute([
            $swapRef, 
            $sourceCurrency, 
            $destinationCurrency, 
            $source['amount'],
            json_encode($sourceDetails), 
            json_encode($destinationDetails),
            $sourceCurrency,
            $destinationCurrency,
            $exchangeRate,
            $fxQuoteUuid,
            $originalSwapRef
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function updateSwapLedgerFees(string $swapRef, string $from, string $to, float $amount, string $currency): void
    {
        $stmt = $this->swapDB->prepare("
            SELECT total_amount FROM swap_fee_collections 
            WHERE swap_reference = ? 
            ORDER BY fee_id DESC LIMIT 1
        ");
        $stmt->execute([$swapRef]);
        $feeResult = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $swapFee = $feeResult ? (float)$feeResult['total_amount'] : 0.00;

        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_ledgers 
            (swap_reference, from_institution, to_institution, amount, currency_code, swap_fee, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");

        $stmt->execute([$swapRef, $from, $to, $amount, $currency, $swapFee]);
    }

    private function processDeposit(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult): void
    {
        // Use FX-converted amount if available
        $grossAmount = $this->fxContext 
            ? (float)($this->fxContext['destination_amount'] ?? $destination['amount'])
            : (float)$destination['amount'];
        
        $feeDetails = $this->deductSwapFee(
            $swapRef,
            'DEPOSIT',
            $grossAmount,
            $source['institution'],
            $destination['institution']
        );
        
        $netAmount = $feeDetails['net_amount'];
        
        $destInstitutionKey = $this->findInstitutionKey($destination['institution']);
        if (!$destInstitutionKey) {
            throw new RuntimeException("Destination institution not found: {$destination['institution']}");
        }
        $participant = $this->participants[$destInstitutionKey];
        
        $bankClient = new GenericBankClient($participant);

        $result = $bankClient->transfer([
            'reference' => $swapRef,
            'source_institution' => $source['institution'],
            'source_hold_reference' => $holdResult['hold_reference'] ?? null,
            'destination_account' => $destination['beneficiary_account'] ?? $destination['beneficiary_wallet'] ?? null,
            'amount' => $netAmount,
            'currency' => $currency,
            'action' => 'PROCESS_DEPOSIT'
        ], 'deposit_direct');

        $this->debugApiCall('process_deposit', ['reference' => $swapRef], $result);

        $this->logApiMessage(
            $swapRef,
            'process_deposit',
            'outgoing',
            $participant,
            '/api/process-deposit',
            ['reference' => $swapRef, 'destination' => $destination['beneficiary_account'] ?? $destination['beneficiary_wallet'] ?? null],
            $result,
            null
        );

        if (!isset($result['success']) || $result['success'] !== true) {
            $errorMsg = 'Bank communication failed';
            if (isset($result['curl_error']) && !empty($result['curl_error'])) {
                $errorMsg .= ': ' . $result['curl_error'];
            } elseif (isset$result['status_code'])) {
                $errorMsg .= ': HTTP ' . $result['status_code'];
            }
            throw new RuntimeException("Deposit failed: " . $errorMsg);
        }

        $bankResponse = $result['data'] ?? [];

        if (!isset($bankResponse['processed']) || $bankResponse['processed'] !== true) {
            $errorMsg = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Unknown error';
            throw new RuntimeException("Deposit failed: " . $errorMsg);
        }
        
        $this->queueSettlementMessage($swapRef, $source, $destination, $grossAmount, $holdResult, $feeDetails);
        $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'deposit', $currency);
    }

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

    private function loadFlows(): void
    {
        $flowsFile = __DIR__ . "/../../CORE_CONFIG/flows.php";
        if (file_exists($flowsFile)) {
            $this->flowsConfig = include $flowsFile;
        }
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
