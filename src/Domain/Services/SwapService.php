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
    private array $endpoints;
    private array $assets;
    private array $flows;
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
        'ACCOUNT', 'BANK-WALLET', 'MNO-WALLET', 'CARD', 'ATM', 'CASHOUT-VOUCHER'
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
        
        // Load configuration from the 4-file YAML structure
        $this->loadConfiguration($country);
        
        // Initialize fee service
        $this->feeService = new FeeService($this->feesConfig, $this->config['currency'] ?? 'BWP');
        
        // Initialize settlement strategy
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        
        // Initialize forex service
        $this->forexService = new ForexService($this->swapDB, $this->config, $this->participants, $this->feeService);
        
        // Initialize card service
        $vouchmorphConfig = $this->participants['vouchmorph'] ?? [];
        $this->cardService = new CardService($this->swapDB, $this->countryCode, $vouchmorphConfig);
        
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
        
        // Initialize SMS service if configured
        try {
            if (isset($this->config['communication']['sms_gateway']['enabled']) && $this->config['communication']['sms_gateway']['enabled']) {
                $smsGatewayConfig = $this->config['communication']['sms_gateway'];
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
     * Load configuration from the 4-file YAML structure
     */
    private function loadConfiguration(string $country): void
    {
        $countryPath = __DIR__ . '/../../Core/Config/Countries/' . $country;
        
        // 1. Load participants.yaml (registry)
        $participantsPath = $countryPath . '/participants.yaml';
        if (!file_exists($participantsPath)) {
            throw new RuntimeException("Participants config not found: {$participantsPath}");
        }
        $this->participants = $this->parseParticipantsYaml($participantsPath);
        
        // 2. Load endpoints.yaml (connection details)
        $endpointsPath = $countryPath . '/endpoints.yaml';
        if (file_exists($endpointsPath)) {
            $this->endpoints = $this->parseEndpointsYaml($endpointsPath);
        } else {
            $this->endpoints = [];
        }
        
        // 3. Load assets.yaml from global config
        $assetsPath = __DIR__ . '/../../Core/Config/assets.yaml';
        if (file_exists($assetsPath)) {
            $this->assets = $this->parseAssetsYaml($assetsPath);
        } else {
            $this->assets = [];
        }
        
        // 4. Load flows.yaml from global config
        $flowsPath = __DIR__ . '/../../Core/Config/flows.yaml';
        if (file_exists($flowsPath)) {
            $this->flows = $this->parseFlowsYaml($flowsPath);
        } else {
            $this->flows = [];
        }
        
        // 5. Load fees configuration (from country-specific fees.json)
        $feesPath = $countryPath . '/fees.json';
        if (file_exists($feesPath)) {
            $feesContent = file_get_contents($feesPath);
            $this->feesConfig = json_decode($feesContent, true) ?? [];
        }
        
        // 6. Load ATM notes (from country-specific config or default)
        $atmNotesPath = $countryPath . '/atm_notes.json';
        if (file_exists($atmNotesPath)) {
            $atmContent = file_get_contents($atmNotesPath);
            $this->atmNotes = json_decode($atmContent, true) ?? ['BWP' => [10, 20, 50, 100, 200]];
        } else {
            $this->atmNotes = ['BWP' => [10, 20, 50, 100, 200]];
        }
        
        // Merge endpoints into participants for easy access
        foreach ($this->participants as $code => &$participant) {
            if (isset($this->endpoints[$code])) {
                $participant['endpoints'] = $this->endpoints[$code]['endpoints'] ?? [];
                $participant['base_url'] = $this->endpoints[$code]['base_url'] ?? null;
                $participant['auth'] = $this->endpoints[$code]['auth'] ?? null;
                $participant['callbacks'] = $this->endpoints[$code]['callbacks'] ?? [];
                $participant['phone_format'] = $this->endpoints[$code]['phone_format'] ?? ['prefix' => '+', 'country_code' => '267'];
                $participant['message_profile'] = $this->endpoints[$code]['message_profile'] ?? [];
                $participant['retry_policy'] = $this->endpoints[$code]['retry_policy'] ?? ['max_retries' => 3];
            }
            
            // Add default currency from config if not set
            if (!isset($participant['default_currency'])) {
                $participant['default_currency'] = $this->config['currency'] ?? 'BWP';
            }
            
            // Add country code from registry if not set
            if (!isset($participant['country_code'])) {
                $participant['country_code'] = $this->countryCode;
            }
        }
        
        error_log("[SwapService] Loaded configuration for {$country}: " . count($this->participants) . " participants");
    }

    /**
     * Parse participants.yaml file
     */
    private function parseParticipantsYaml(string $path): array
    {
        $content = file_get_contents($path);
        $participants = [];
        
        // Simple YAML parser for our structure
        $lines = explode("\n", $content);
        $currentParticipant = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            // Check for participant (indented with 2 spaces)
            if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
                $currentParticipant = strtolower($matches[1]);
                $participants[$currentParticipant] = [];
                continue;
            }
            
            // Parse participant properties
            if ($currentParticipant && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                // Remove quotes
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
                
                // Handle nested structures
                if ($key === 'assets' && preg_match('/^\[(.*)\]$/', $value, $arr)) {
                    $participants[$currentParticipant][$key] = array_map('trim', explode(',', $arr[1]));
                } else {
                    $participants[$currentParticipant][$key] = $value;
                }
            }
            
            // Parse routing
            if ($currentParticipant && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches)) {
                $participants[$currentParticipant]['routing'][$matches[1]] = $matches[2];
            }
            
            // Parse limits
            if ($currentParticipant && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches) && strpos($line, 'limits') !== false) {
                $participants[$currentParticipant]['limits'][$matches[1]] = $matches[2];
            }
            
            // Parse compliance
            if ($currentParticipant && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches) && strpos($line, 'compliance') !== false) {
                $value = $matches[2];
                if ($value === 'true') $value = true;
                if ($value === 'false') $value = false;
                $participants[$currentParticipant]['compliance'][$matches[1]] = $value;
            }
        }
        
        return $participants;
    }

    /**
     * Parse endpoints.yaml file
     */
    private function parseEndpointsYaml(string $path): array
    {
        $content = file_get_contents($path);
        $endpoints = [];
        
        $lines = explode("\n", $content);
        $currentParticipant = null;
        $currentSection = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            // Check for participant (top-level)
            if (preg_match('/^([A-Z_]+):$/', $line, $matches)) {
                $currentParticipant = strtolower($matches[1]);
                $endpoints[$currentParticipant] = [];
                $currentSection = null;
                continue;
            }
            
            if (!$currentParticipant) continue;
            
            // Parse section headers (deposit:, cashout:, common:, connection:, auth:, callbacks:)
            if (preg_match('/^  ([a-z_]+):$/', $line, $matches)) {
                $currentSection = $matches[1];
                $endpoints[$currentParticipant][$currentSection] = [];
                continue;
            }
            
            // Parse simple key-value pairs
            if ($currentSection && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
                $endpoints[$currentParticipant][$currentSection][$key] = $value;
                continue;
            }
            
            // Parse flat properties (base_url, timeout_ms, etc.)
            if (preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if ($value === 'true') $value = true;
                if ($value === 'false') $value = false;
                if (is_numeric($value)) $value = (float)$value;
                $endpoints[$currentParticipant][$key] = $value;
            }
        }
        
        return $endpoints;
    }

    /**
     * Parse assets.yaml file
     */
    private function parseAssetsYaml(string $path): array
    {
        $content = file_get_contents($path);
        $assets = [];
        
        $lines = explode("\n", $content);
        $currentAsset = null;
        $currentSection = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            // Check for asset type (top-level)
            if (preg_match('/^([A-Z-]+):$/', $line, $matches)) {
                $currentAsset = $matches[1];
                $assets[$currentAsset] = [];
                $currentSection = null;
                continue;
            }
            
            if (!$currentAsset) continue;
            
            // Parse section headers
            if (preg_match('/^  ([a-z_]+):$/', $line, $matches)) {
                $currentSection = $matches[1];
                if ($currentSection === 'fields') {
                    $assets[$currentAsset][$currentSection] = [];
                } else {
                    $assets[$currentAsset][$currentSection] = [];
                }
                continue;
            }
            
            // Parse array values (delivery modes)
            if ($currentSection === 'delivery' && preg_match('/^    - (.+)$/', $line, $matches)) {
                $assets[$currentAsset][$currentSection][] = trim($matches[1]);
                continue;
            }
            
            // Parse fields
            if ($currentSection === 'fields' && preg_match('/^    - name: (.+)$/', $line, $matches)) {
                $currentField = ['name' => trim($matches[1])];
                $assets[$currentAsset]['fields'][] = $currentField;
                continue;
            }
            
            // Parse field properties
            if ($currentSection === 'fields' && isset($currentField) && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches)) {
                $idx = count($assets[$currentAsset]['fields']) - 1;
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if ($value === 'true') $value = true;
                if ($value === 'false') $value = false;
                $assets[$currentAsset]['fields'][$idx][$matches[1]] = $value;
                continue;
            }
            
            // Parse simple key-value pairs
            if ($currentSection && preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if ($value === 'true') $value = true;
                if ($value === 'false') $value = false;
                if (is_numeric($value)) $value = (float)$value;
                $assets[$currentAsset][$key] = $value;
                $currentSection = null;
            }
        }
        
        return $assets;
    }

    /**
     * Parse flows.yaml file
     */
    private function parseFlowsYaml(string $path): array
    {
        $content = file_get_contents($path);
        $flows = [];
        
        $lines = explode("\n", $content);
        $currentSection = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            // Parse sections
            if (preg_match('/^([a-z_]+):$/', $line, $matches)) {
                $currentSection = $matches[1];
                $flows[$currentSection] = [];
                continue;
            }
            
            // Parse orchestration defaults
            if ($currentSection === 'orchestration' && preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $flows[$currentSection][$matches[1]] = trim($matches[2]);
                continue;
            }
            
            // Parse states
            if ($currentSection === 'states' && preg_match('/^  - (.+)$/', $line, $matches)) {
                $flows[$currentSection][] = trim($matches[1]);
                continue;
            }
        }
        
        return $flows;
    }

    /**
     * Get asset definition from assets.yaml
     */
    public function getAssetDefinition(string $assetType): array
    {
        return $this->assets[$assetType] ?? [];
    }

    /**
     * Get allowed corridors from flows.yaml
     */
    public function getCorridors(): array
    {
        return $this->flows['corridors'] ?? [];
    }

    /**
     * Check if a corridor is allowed
     */
    public function isCorridorAllowed(string $sourceAsset, string $destAsset, string $operation = 'SWAP'): bool
    {
        $corridors = $this->getCorridors();
        
        foreach ($corridors as $corridor) {
            if ($corridor['source'] === $sourceAsset && $corridor['destination'] === $destAsset) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get participant endpoint configuration
     */
    public function getParticipantEndpoint(string $institution, string $operation, string $action): ?string
    {
        $key = $this->findInstitutionKey($institution);
        if (!$key || !isset($this->endpoints[$key])) {
            return null;
        }
        
        $endpointConfig = $this->endpoints[$key];
        
        // Check deposit/cashout specific endpoints
        if (isset($endpointConfig[$operation][$action])) {
            return $endpointConfig[$operation][$action];
        }
        
        // Check common endpoints
        if (isset($endpointConfig['common'][$action])) {
            return $endpointConfig['common'][$action];
        }
        
        return null;
    }

    /**
     * Get full participant configuration
     */
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
            if (isset($participant['id']) && strtolower($participant['id']) === $searchLower) {
                return $key;
            }
        }
        return null;
    }

    // ============================================================
    // The rest of the existing methods remain the same
    // (executeMultiSourceSwap, executeSwap, verifySourceAsset, 
    //  placeHold, debitSource, processDestination, etc.)
    // ============================================================

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
                case 'CASHOUT-VOUCHER':
                    $voucher = $source['CASHOUT-VOUCHER'] ?? [];
                    $payload['CASHOUT-VOUCHER_number'] = $voucher['CASHOUT-VOUCHER_number'] ?? $source['identifier'] ?? null;
                    $payload['claimant_phone'] = $this->formatPhoneForInstitution(
                        $voucher['claimant_phone'] ?? $source['phone'] ?? null, 
                        $participant
                    );
                    break;
                case 'ACCOUNT':
                    $account = $source['account'] ?? [];
                    $payload['account_number'] = $account['account_number'] ?? $source['identifier'] ?? null;
                    break;
                case 'MNO-WALLET':
                    $wallet = $source['MNO-WALLET'] ?? [];
                    $payload['wallet_phone'] = $this->formatPhoneForInstitution(
                        $wallet['wallet_phone'] ?? $source['identifier'] ?? null, 
                        $participant
                    );
                    break;
                case 'BANK-WALLET':
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

    // The rest of the existing methods (executeSwap, verifySourceAsset, placeHold, 
    // debitSource, processDestination, processCashout, processDeposit, 
    // processCardLoad, processCardIssuance, storeFees, recordSwap, 
    // formatPhoneForInstitution, sanitizePhones, buildResponse, logEvent, etc.)
    // remain exactly as they were in your original file.
    // 
    // The key changes are:
    // 1. Added loadConfiguration() method
    // 2. Added YAML parsers for the 4-file structure
    // 3. Added getAssetDefinition(), getCorridors(), isCorridorAllowed()
    // 4. Added getParticipantEndpoint() for dynamic endpoint lookup
    // 5. Modified getParticipant() to work with new structure
    // 6. Added findInstitutionKey() to handle both code and id lookups
    //
    // All existing business logic remains untouched.
}
