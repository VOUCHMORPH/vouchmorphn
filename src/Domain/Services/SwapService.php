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
use Domain\Services\MultiSource\MultiSourceSwapOrchestrator;
use Infrastructure\Adapters\InstitutionAdapterFactory;
use Infrastructure\SMS\SmsNotificationService;
use Infrastructure\Mojaloop\IdempotencyService;
use Infrastructure\Crypto\SignatureVerifier;
use Infrastructure\Crypto\MessageSigner;
use Infrastructure\Crypto\CertificateManager;
use Infrastructure\Crypto\AggregateSigner;

/**
 * SIGNED ATOMIC SWAP ORCHESTRATOR
 * 
 * Institution-agnostic - all institutions derived from payload
 * No hardcoded institution names anywhere
 * Supports ACCOUNT and WALLET asset types for deposits
 * 
 * NOW WITH ADAPTER PATTERN - each institution has its own adapter
 * No GenericBankClient used directly - all institution communication via adapters
 * 
 * PIN POLICY (UPDATED):
 * - PIN is NO LONGER REQUIRED for wallet and account sources
 * - Authentication is handled through:
 *   - Hooked sources (OAuth/API tokens from user_authorized_sources)
 *   - Access tokens from source_accounts table
 *   - Institution-specific authentication methods
 * - PIN is OPTIONAL - only forwarded if present (backward compatibility)
 * - Destination operations (deposit, credit, transfer) do NOT require PIN
 */
class SwapService
{
    // ============================================================
    // IDENTITY TYPES - single source of truth.
    // ============================================================
    private const IDENTITY_TYPES_SELF_SERVICE = ['phone', 'email'];
    private const IDENTITY_TYPES_AGENT_VERIFIABLE = ['national_id', 'birth_certificate', 'voter_id'];

    private function isValidIdentityType(string $type): bool
    {
        return in_array($type, array_merge(self::IDENTITY_TYPES_SELF_SERVICE, self::IDENTITY_TYPES_AGENT_VERIFIABLE), true);
    }

    private function isAgentVerifiableIdentityType(string $type): bool
    {
        return in_array($type, self::IDENTITY_TYPES_AGENT_VERIFIABLE, true);
    }

    private function validIdentityTypesLabel(): string
    {
        return implode(', ', array_merge(self::IDENTITY_TYPES_SELF_SERVICE, self::IDENTITY_TYPES_AGENT_VERIFIABLE));
    }

    private PDO $swapDB;
    private array $config = [];
    private array $participants = [];
    private array $endpoints = [];
    private string $countryCode;
    private array $feesConfig = [];
    private array $atmNotes = [];
    private array $feeCalculationDetails = [];
    
    private HybridSettlementStrategy $settlement;
    private FeeService $feeService;
    private ForexService $forexService;
    private ?CardService $cardService = null;
    private ?SmsNotificationService $smsService = null;
    private ?ContributionCalculator $contributionCalculator = null;
    private ?MultiSourceFeeCalculator $multiSourceFeeCalculator = null;
    private ?MultiSourceSwapOrchestrator $multiSourceOrchestrator = null;
    private $logger = null;
    
    private MessageSigner $messageSigner;
    private SignatureVerifier $signatureVerifier;
    private ?CertificateManager $certificateManager = null;
    
    private InstitutionAdapterFactory $adapterFactory;
    
    private bool $inAtomicSwap = false;
    private ?string $currentSwapRef = null;
    private ?int $currentHoldId = null;
    private ?string $currentHoldReference = null;
    private ?string $currentHoldInstitution = null;
    private array $executedSteps = [];
    private array $stepResults = [];
    private array $signedPayloads = [];
    private array $pendingRemainder = [];

    public function __construct(
        PDO $swapDB, 
        array $config, 
        string $country,
        $logger = null
    ) {
        $this->swapDB = $swapDB;
        $this->config = $config;
        $this->countryCode = strtoupper($country);
        
        if ($logger === null) {
            $this->logger = new class {
                public function emergency($message, array $context = []) {
                    error_log("[SwapService][EMERGENCY] " . $message . " " . json_encode($context));
                }
                public function alert($message, array $context = []) {
                    error_log("[SwapService][ALERT] " . $message . " " . json_encode($context));
                }
                public function critical($message, array $context = []) {
                    error_log("[SwapService][CRITICAL] " . $message . " " . json_encode($context));
                }
                public function error($message, array $context = []) {
                    error_log("[SwapService][ERROR] " . $message . " " . json_encode($context));
                }
                public function warning($message, array $context = []) {
                    error_log("[SwapService][WARNING] " . $message . " " . json_encode($context));
                }
                public function notice($message, array $context = []) {
                    error_log("[SwapService][NOTICE] " . $message . " " . json_encode($context));
                }
                public function info($message, array $context = []) {
                    error_log("[SwapService][INFO] " . $message . " " . json_encode($context));
                }
                public function debug($message, array $context = []) {
                    error_log("[SwapService][DEBUG] " . $message . " " . json_encode($context));
                }
                public function log($level, $message, array $context = []) {
                    error_log("[SwapService][{$level}] " . $message . " " . json_encode($context));
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
        
        $countryConfig = \Core\Config\LoadCountry::getConfig();
        
        $this->participants = $countryConfig['participants'] ?? [];
        $this->feesConfig = $countryConfig['fees'] ?? [];
        $this->atmNotes = $countryConfig['atm_notes'] ?? [];
        
        error_log("[SwapService] Loaded fees config from LoadCountry");
        error_log("[SwapService] Config keys: " . implode(', ', array_keys($this->feesConfig)));
        
        $this->adapterFactory = new InstitutionAdapterFactory(
            $this->participants,
            $this->logger
        );
        $this->logger->info("InstitutionAdapterFactory initialized");
        
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        $this->forexService = new ForexService(
            $this->swapDB, 
            $countryConfig,
            $this->participants
        );
        $this->feeService = new FeeService(
            $this->feesConfig,
            $countryConfig,
            $countryConfig['currency'] ?? 'BWP',
            $this->forexService
        );
        $this->feeService->setParticipants($this->participants);
        
       $commConfig = $countryConfig['communication'] ?? [];
if (!empty($commConfig)) {
    $this->smsService = new SmsNotificationService($this->swapDB, $commConfig);
}
        
        $vouchmorphConfig = $this->participants['vouchmorph'] ?? [];
        if (!empty($vouchmorphConfig)) {
            $this->cardService = new CardService($this->swapDB, $this->countryCode, $vouchmorphConfig);
        }
        
        error_log("[SwapService] Initializing Multi-Source components...");
        
        try {
            $this->contributionCalculator = new ContributionCalculator();
            $this->multiSourceFeeCalculator = new MultiSourceFeeCalculator($this->config, $this->countryCode);
            
            $aggregateSigner = new AggregateSigner(
                $this->certificateManager ?? new CertificateManager('VOUCHMORPH'),
                $this->signatureVerifier
            );
            
            $this->multiSourceOrchestrator = new MultiSourceSwapOrchestrator(
                $this->swapDB,
                $this,
                $this->settlement,
                $aggregateSigner,
                $this->config,
                $this->countryCode,
                $this->logger
            );
            
            error_log("[SwapService] Multi-Source components initialized successfully");
            $this->logger->info("Multi-Source Swap Orchestrator initialized");
            
        } catch (Exception $e) {
            error_log("[SwapService] ERROR initializing Multi-Source: " . $e->getMessage());
            $this->logger->error("Multi-Source initialization failed", ['error' => $e->getMessage()]);
            $this->multiSourceOrchestrator = null;
        }
        
        $this->logger->info("Signed SwapService initialized (multi-source " . ($this->multiSourceOrchestrator ? 'ENABLED' : 'DISABLED') . ")", ['country' => $country]);
    }

    // ============================================================================
    // INSTITUTION EXTRACTION - NO HARDCODING
    // ============================================================================

    private function extractSourceInstitution(array $payload): string
    {
        $source = $payload['from_institution'] ?? 
                  $payload['source_institution'] ?? 
                  $payload['source']['institution'] ?? 
                  $payload['source_details']['institution'] ?? 
                  $payload['participant']['source'] ?? 
                  $payload['bank'] ?? 
                  null;
        
        if (empty($source)) {
            $availableKeys = implode(', ', array_keys($payload));
            error_log("[SwapService] No source institution found. Available keys: {$availableKeys}");
            throw new RuntimeException(
                "No source institution found in payload. Please provide 'from_institution' or 'source_institution'. " .
                "Available keys: {$availableKeys}"
            );
        }
        
        error_log("[SwapService] Extracted source institution: {$source}");
        return $source;
    }

    private function extractDestinationInstitution(array $payload): string
    {
        $dest = $payload['to_institution'] ?? 
                $payload['destination_institution'] ?? 
                $payload['destination']['institution'] ?? 
                $payload['destination_details']['institution'] ?? 
                $payload['participant']['destination'] ?? 
                null;
        
        if (empty($dest)) {
            $availableKeys = implode(', ', array_keys($payload));
            error_log("[SwapService] No destination institution found. Available keys: {$availableKeys}");
            throw new RuntimeException(
                "No destination institution found in payload. Please provide 'to_institution' or 'destination_institution'. " .
                "Available keys: {$availableKeys}"
            );
        }
        
        error_log("[SwapService] Extracted destination institution: {$dest}");
        return $dest;
    }

    public function extractDestinationAssetType(array $payload): string
    {
        $assetType = strtoupper($payload['destination_asset_type'] ?? 
                                  $payload['asset_type'] ?? 
                                  $payload['destination_type'] ?? 
                                  'WALLET');
        
        if (!in_array($assetType, ['ACCOUNT', 'WALLET'])) {
            error_log("[SwapService] WARNING: Invalid destination_asset_type '{$assetType}', defaulting to WALLET");
            $assetType = 'WALLET';
        }
        
        return $assetType;
    }

    private function extractSourceIdentifier(array $payload): array
    {
        $sourceIdentifier = null;
        $sourceIdentifierType = null;
        
        if (!empty($payload['_is_hooked']) && !empty($payload['source_reference'])) {
            $sourceIdentifier = $payload['source_identifier'] ?? null;
            $sourceIdentifierType = $payload['source_identifier_type'] ?? 'auto';
            if ($sourceIdentifier) {
                return [
                    'identifier' => $sourceIdentifier,
                    'type' => $sourceIdentifierType,
                    'has_value' => true,
                    'is_hooked' => true
                ];
            }
        }
        
        $sourceIdentifier = $payload['source_identifier'] ?? 
                           $payload['source_account'] ?? 
                           $payload['source_phone'] ?? 
                           $payload['source_wallet_phone'] ?? 
                           $payload['wallet_phone'] ?? 
                           $payload['phone'] ?? 
                           $payload['source_national_id'] ?? 
                           $payload['national_id'] ?? 
                           $payload['source_email'] ?? 
                           $payload['email'] ?? 
                           $payload['source']['identifier'] ?? 
                           $payload['source']['account'] ?? 
                           null;
        
        $sourceIdentifierType = $payload['source_identifier_type'] ?? 
                               $payload['identifier_type'] ?? 
                               'auto';
        
        if (!empty($sourceIdentifier)) {
            return [
                'identifier' => $sourceIdentifier,
                'type' => $sourceIdentifierType,
                'has_value' => true,
                'is_hooked' => !empty($payload['_is_hooked'])
            ];
        }
        
        return [
            'identifier' => null,
            'type' => null,
            'has_value' => false,
            'is_hooked' => false
        ];
    }

    public function extractDestinationIdentifier(array $payload): array
    {
        $destinationIdentifier = null;
        $destinationIdentifierType = null;
        
        $destinationIdentifier = $payload['destination_identifier'] ?? 
                                 $payload['destination_account'] ?? 
                                 $payload['destination_phone'] ?? 
                                 $payload['destination_national_id'] ?? 
                                 $payload['destination_email'] ?? 
                                 $payload['beneficiary_account'] ?? 
                                 $payload['beneficiary_phone'] ?? 
                                 $payload['beneficiary_identifier'] ?? 
                                 $payload['client_phone'] ?? 
                                 $payload['account_number'] ?? 
                                 $payload['phone'] ?? 
                                 $payload['email'] ?? 
                                 $payload['national_id'] ?? 
                                 $payload['destination']['identifier'] ?? 
                                 null;
        
        $destinationIdentifierType = $payload['destination_identifier_type'] ?? 
                                     $payload['identifier_type'] ?? 
                                     'account';
        
        if (!empty($destinationIdentifier)) {
            return [
                'identifier' => $destinationIdentifier,
                'type' => $destinationIdentifierType,
                'has_value' => true
            ];
        }
        
        return [
            'identifier' => null,
            'type' => null,
            'has_value' => false
        ];
    }

    private function extractBeneficiaryPhone(array $payload): ?string
    {
        return $payload['beneficiary_phone'] ?? 
               $payload['beneficiary_identifier'] ?? 
               $payload['client_phone'] ?? 
               null;
    }

    private function validateInstitutions(array $payload, bool $requireDestination = true): void
    {
        $source = $this->extractSourceInstitution($payload);
        error_log("[SwapService] Source institution validated: {$source}");
        
        if ($requireDestination) {
            $dest = $this->extractDestinationInstitution($payload);
            error_log("[SwapService] Destination institution validated: {$dest}");
        }
    }

    // ============================================================================
    // SOURCE LINKING METHODS (Hooking)
    // ============================================================================

    public function initiateSourceLink(array $params): array
    {
        error_log("[SwapService] initiateSourceLink called");
        
        $institution = $params['institution'] ?? null;
        if (!$institution) {
            return ['success' => false, 'message' => 'Institution required'];
        }
        
        $adapter = $this->adapterFactory->getAdapter($institution);
        return $adapter->initiateSourceLink($params);
    }

    public function verifySourceLink(array $params): array
    {
        error_log("[SwapService] verifySourceLink called");
        
        $institution = $params['institution'] ?? null;
        if (!$institution) {
            return ['success' => false, 'message' => 'Institution required'];
        }
        
        $adapter = $this->adapterFactory->getAdapter($institution);
        return $adapter->verifySourceLink($params);
    }

    public function getHookedSources(int $userId): array
    {
        error_log("[SwapService] getHookedSources called for user: {$userId}");
        
        $sql = "SELECT * FROM user_authorized_sources WHERE user_id = :user_id AND status = 'active'";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($sources as &$source) {
            if ($this->isTokenExpired($source['token_expires_at'])) {
                try {
                    $refreshed = $this->refreshHookedSource($userId, $source['source_reference']);
                    $source['access_token'] = $refreshed['access_token'];
                    $source['token_expires_at'] = $refreshed['expires_at'];
                } catch (Exception $e) {
                    error_log("[SwapService] Failed to refresh token for source: " . $source['source_reference']);
                    $source['status'] = 'expired';
                }
            }
        }
        
        return $sources;
    }

    public function refreshHookedSource(int $userId, string $sourceReference): array
    {
        error_log("[SwapService] refreshHookedSource: {$sourceReference}");
        
        $sql = "SELECT * FROM user_authorized_sources WHERE source_reference = :source_ref AND user_id = :user_id";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':source_ref' => $sourceReference, ':user_id' => $userId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$source) {
            throw new RuntimeException("Source not found");
        }
        
        $adapter = $this->adapterFactory->getAdapter($source['institution']);
        $result = $adapter->refreshSourceToken(['refresh_token' => $source['refresh_token']]);
        
        if (!$result['success']) {
            throw new RuntimeException("Failed to refresh token: " . ($result['message'] ?? 'Unknown error'));
        }
        
        $sql = "UPDATE user_authorized_sources SET access_token = :access_token, token_expires_at = :expires_at WHERE source_reference = :source_ref";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':access_token' => $result['access_token'],
            ':expires_at' => $result['expires_at'],
            ':source_ref' => $sourceReference
        ]);
        
        return [
            'success' => true,
            'access_token' => $result['access_token'],
            'expires_at' => $result['expires_at']
        ];
    }

    public function revokeHookedSource(int $userId, string $sourceReference): array
    {
        error_log("[SwapService] revokeHookedSource: {$sourceReference}");
        
        $sql = "SELECT * FROM user_authorized_sources WHERE source_reference = :source_ref AND user_id = :user_id";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':source_ref' => $sourceReference, ':user_id' => $userId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$source) {
            throw new RuntimeException("Source not found");
        }
        
        $adapter = $this->adapterFactory->getAdapter($source['institution']);
        $adapter->revokeSourceToken(['token' => $source['access_token']]);
        
        $sql = "UPDATE user_authorized_sources SET status = 'revoked' WHERE source_reference = :source_ref";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':source_ref' => $sourceReference]);
        
        return ['success' => true, 'message' => 'Source revoked successfully'];
    }

    private function isTokenExpired(?string $expiresAt): bool
    {
        if (!$expiresAt) return true;
        return strtotime($expiresAt) < time();
    }

    public function executeSwapWithHookedSource(array $payload): array
    {
        error_log("[SwapService] executeSwapWithHookedSource called");
        
        $sourceReference = $payload['source_reference'] ?? null;
        if (!$sourceReference) {
            throw new RuntimeException("source_reference required");
        }
        
        $userId = $payload['user_id'] ?? null;
        if (!$userId) {
            throw new RuntimeException("user_id required");
        }
        
        $sql = "SELECT * FROM user_authorized_sources WHERE source_reference = :source_ref AND user_id = :user_id AND status = 'active'";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':source_ref' => $sourceReference, ':user_id' => $userId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$source) {
            throw new RuntimeException("Hooked source not found or inactive");
        }
        
        if ($this->isTokenExpired($source['token_expires_at'])) {
            $refreshed = $this->refreshHookedSource($userId, $sourceReference);
            $source['access_token'] = $refreshed['access_token'];
            $source['token_expires_at'] = $refreshed['expires_at'];
        }
        
        $swapPayload = $payload;
        $swapPayload['from_institution'] = $source['institution'];
        $swapPayload['asset_type'] = $source['asset_type'];
        $swapPayload['source_identifier'] = $source['identifier'];
        $swapPayload['access_token'] = $source['access_token'];
        $swapPayload['_is_hooked'] = true;
        
        $sql = "UPDATE user_authorized_sources SET last_used_at = NOW() WHERE source_reference = :source_ref";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':source_ref' => $sourceReference]);
        
        return $this->executeAtomicSwap($swapPayload);
    }

    public function executeMultiSourceWithHookedSources(array $payload): array
    {
        error_log("[SwapService] executeMultiSourceWithHookedSources called");
        
        $userId = $payload['user_id'] ?? null;
        if (!$userId) {
            throw new RuntimeException("user_id required");
        }
        
        $sources = $payload['sources'] ?? [];
        if (empty($sources) || count($sources) < 2) {
            throw new RuntimeException("At least 2 sources required for multi-source swap");
        }
        
        $resolvedSources = [];
        $totalAmount = 0;
        
        foreach ($sources as $source) {
            $sourceRef = $source['source_reference'] ?? null;
            $amount = (float)($source['amount'] ?? 0);
            
            if (!$sourceRef) {
                throw new RuntimeException("source_reference required for each source");
            }
            
            if ($amount <= 0) {
                throw new RuntimeException("Amount must be greater than 0 for each source");
            }
            
            $sql = "SELECT * FROM user_authorized_sources WHERE source_reference = :source_ref AND user_id = :user_id AND status = 'active'";
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':source_ref' => $sourceRef, ':user_id' => $userId]);
            $hookedSource = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hookedSource) {
                throw new RuntimeException("Hooked source not found: {$sourceRef}");
            }
            
            if ($this->isTokenExpired($hookedSource['token_expires_at'])) {
                $refreshed = $this->refreshHookedSource($userId, $sourceRef);
                $hookedSource['access_token'] = $refreshed['access_token'];
                $hookedSource['token_expires_at'] = $refreshed['expires_at'];
            }
            
            $resolvedSources[] = [
                'institution' => $hookedSource['institution'],
                'asset_type' => $hookedSource['asset_type'],
                'identifier' => $hookedSource['identifier'],
                'amount' => $amount,
                'access_token' => $hookedSource['access_token'],
                'source_reference' => $sourceRef,
                'currency' => $hookedSource['currency'] ?? 'BWP',
                'is_hooked' => true
            ];
            
            $totalAmount += $amount;
        }
        
        $multiPayload = $payload;
        $multiPayload['sources'] = $resolvedSources;
        $multiPayload['amount'] = $totalAmount;
        $multiPayload['_is_multi_hooked'] = true;
        
        return $this->executeAtomicSwap($multiPayload);
    }

    // ============================================================================
    // TABLE POPULATION METHODS - UPDATED WITH FIXES
    // ============================================================================

    /**
     * Populate all tracking tables from swap data
     * Called after successful swap completion
     */
    private function populateTrackingTables(array $swapData, array $details, ?array $destResponse = null): void
    {
        $swapType = $swapData['swap_type'] ?? 'STANDARD';
        $swapRef = $swapData['reference'] ?? $this->currentSwapRef;
        $userId = $details['user_id'] ?? $swapData['user_id'] ?? null;
        
        try {
            // 1. Always populate swap_requests - capture the ID
            $swapId = $this->populateSwapRequest($swapRef, $swapData, $details, $userId);
            
            // 2. Always populate swap_transactions - pass the ID AND swapRef
            if ($swapId) {
                $this->populateSwapTransaction($swapId, $swapRef, $swapData, $details, $userId);
            } else {
                $this->logger->warning("No swap_id available, skipping swap_transactions", ['swap_ref' => $swapRef]);
            }
            
            // 3. Populate type-specific tables
            if ($swapType === 'CASHOUT') {
                $this->populateCashoutAuthorization($swapRef, $swapData, $details, $destResponse, $userId);
                $this->populateMessageOutbox($swapRef, $swapData, $details, $destResponse, $userId);
            } elseif ($swapType === 'DEPOSIT') {
                $this->populateDepositTransaction($swapRef, $swapData, $details, $userId);
            }
            
            $this->logger->info("Tracking tables populated", ['reference' => $swapRef, 'type' => $swapType]);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to populate tracking tables", [
                'reference' => $swapRef,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get numeric swap_request_id from swap_uuid
     */
    private function getSwapRequestId(string $swapRef): ?int
    {
        $sql = "SELECT swap_request_id FROM swap_requests WHERE swap_uuid = :swap_uuid";
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':swap_uuid' => $swapRef]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['swap_request_id'] : null;
        } catch (PDOException $e) {
            $this->logger->error("Failed to get swap_request_id", ['error' => $e->getMessage(), 'swap_ref' => $swapRef]);
            return null;
        }
    }

    private function populateSwapRequest(string $swapRef, array $swapData, array $details, ?int $userId = null): ?int
    {
        // Extract forex data from feeCalculationDetails
        $forexRate = $this->feeCalculationDetails['exchange_rate'] ?? null;
        $forexFeePercent = $this->feeCalculationDetails['forex_fee_percent'] ?? null;
        $forexFeeAmount = $this->feeCalculationDetails['forex_fee_amount'] ?? null;
        $totalForexFee = $this->feeCalculationDetails['total_forex_fee'] ?? null;
        
        $sql = "
            INSERT INTO swap_requests (
                swap_uuid,
                from_currency,
                to_currency,
                amount,
                source_details,
                destination_details,
                status,
                created_at,
                source_country,
                destination_country,
                fee_breakdown,
                metadata,
                retry_count,
                forex_rate,
                forex_fee_percent,
                forex_fee_amount,
                total_forex_fee,
                trade_metadata,
                original_swap_ref,
                user_id
            ) VALUES (
                :swap_uuid,
                :from_currency,
                :to_currency,
                :amount,
                :source_details::jsonb,
                :destination_details::jsonb,
                :status,
                :created_at,
                :source_country,
                :destination_country,
                :fee_breakdown::jsonb,
                :metadata::jsonb,
                0,
                :forex_rate,
                :forex_fee_percent,
                :forex_fee_amount,
                :total_forex_fee,
                :trade_metadata::jsonb,
                :original_swap_ref,
                :user_id
            ) ON CONFLICT (swap_uuid) DO UPDATE SET
                status = EXCLUDED.status,
                forex_rate = EXCLUDED.forex_rate,
                forex_fee_percent = EXCLUDED.forex_fee_percent,
                forex_fee_amount = EXCLUDED.forex_fee_amount,
                total_forex_fee = EXCLUDED.total_forex_fee,
                trade_metadata = EXCLUDED.trade_metadata,
                fee_breakdown = EXCLUDED.fee_breakdown,
                user_id = EXCLUDED.user_id
            RETURNING swap_id
        ";
        
        $status = $swapData['status'] ?? 'pending';
        if (isset($details['status'])) {
            $status = $details['status'];
        }
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':swap_uuid' => $swapRef,
                ':from_currency' => $details['currency'] ?? $swapData['currency'] ?? 'BWP',
                ':to_currency' => $details['destination_currency'] ?? $swapData['destination_currency'] ?? $details['currency'] ?? 'BWP',
                ':amount' => $swapData['amount'] ?? $details['amount'] ?? 0,
                ':source_details' => json_encode($details),
                ':destination_details' => json_encode([
                    'institution' => $details['destination_institution'] ?? $swapData['to_institution'] ?? null,
                    'identifier' => $details['destination_identifier'] ?? null,
                    'asset_type' => $details['destination_asset_type'] ?? null
                ]),
                ':status' => strtolower($status),
                ':created_at' => date('Y-m-d H:i:s'),
                ':source_country' => $details['source_country'] ?? 'BW',
                ':destination_country' => $details['destination_country'] ?? 'BW',
                ':fee_breakdown' => json_encode($details['fee_breakdown'] ?? $this->feeCalculationDetails ?? []),
                ':metadata' => json_encode([
                    'hold_id' => $this->currentHoldId,
                    'swap_type' => $swapData['swap_type'] ?? 'STANDARD',
                    'source_institution' => $details['source_institution'] ?? $swapData['from_institution'] ?? null
                ]),
                ':forex_rate' => $forexRate,
                ':forex_fee_percent' => $forexFeePercent,
                ':forex_fee_amount' => $forexFeeAmount,
                ':total_forex_fee' => $totalForexFee,
                ':trade_metadata' => json_encode([
                    'user_id' => $userId,
                    'request_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]),
                ':original_swap_ref' => $swapData['original_swap_ref'] ?? null,
                ':user_id' => $userId
            ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $swapId = $row ? (int)($row['swap_id'] ?? 0) : 0;
        
        $this->logger->debug("swap_requests populated", ['swap_uuid' => $swapRef, 'swap_id' => $swapId, 'user_id' => $userId]);
        
        return $swapId > 0 ? $swapId : null;
        
    } catch (PDOException $e) {
        $this->logger->error("Failed to populate swap_requests", ['error' => $e->getMessage(), 'swap_ref' => $swapRef]);
        return null;
    }
    }

    private function populateSwapTransaction(int $swapId, string $swapRef, array $swapData, array $details, ?int $userId = null): void
    {
        $sql = "
            INSERT INTO swap_transactions (
                swap_id,
                from_account_details,
                to_account_details,
                amount,
                status,
                created_at,
                updated_at,
                metadata,
                transaction_id,
                ledger_entry_id,
                settlement_batch_id,
                error_message,
                retry_count,
                user_id
            ) VALUES (
                :swap_id,
                :from_account_details::jsonb,
                :to_account_details::jsonb,
                :amount,
                :status,
                :created_at,
                :updated_at,
                :metadata::jsonb,
                :transaction_id,
                :ledger_entry_id,
                :settlement_batch_id,
                :error_message,
                0,
                :user_id
            )
        ";
        
        $status = $swapData['status'] ?? 'pending';
        if (isset($details['status'])) {
            $status = $details['status'];
        }
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':swap_id' => $swapId,
                ':from_account_details' => json_encode([
                    'institution' => $details['source_institution'] ?? $swapData['from_institution'] ?? null,
                    'identifier' => $details['source_identifier'] ?? null,
                    'asset_type' => $details['asset_type'] ?? null
                ]),
                ':to_account_details' => json_encode([
                    'institution' => $details['destination_institution'] ?? $swapData['to_institution'] ?? null,
                    'identifier' => $details['destination_identifier'] ?? null,
                    'asset_type' => $details['destination_asset_type'] ?? null
                ]),
                ':amount' => $swapData['amount'] ?? $details['amount'] ?? 0,
                ':status' => strtolower($status),
                ':created_at' => date('Y-m-d H:i:s'),
                ':updated_at' => date('Y-m-d H:i:s'),
                ':metadata' => json_encode([
                    'hold_id' => $this->currentHoldId,
                    'swap_type' => $swapData['swap_type'] ?? 'STANDARD',
                    'swap_reference' => $swapRef
                ]),
                ':transaction_id' => $details['transaction_id'] ?? null,
                ':ledger_entry_id' => $details['ledger_entry_id'] ?? null,
                ':settlement_batch_id' => $details['settlement_batch_id'] ?? null,
                ':error_message' => $details['error_message'] ?? null,
                ':user_id' => $userId
            ]);
            
            $this->logger->debug("swap_transactions populated", ['swap_id' => $swapId, 'swap_ref' => $swapRef, 'user_id' => $userId]);
            
        } catch (PDOException $e) {
            $this->logger->error("Failed to populate swap_transactions", ['error' => $e->getMessage(), 'swap_id' => $swapId, 'swap_ref' => $swapRef]);
        }
    }

    private function populateCashoutAuthorization(string $swapRef, array $swapData, array $details, ?array $destResponse, ?int $userId = null): void
    {
        if (!$destResponse) {
            return;
        }
        
        $sql = "
            INSERT INTO cashout_authorizations (
                swap_reference,
                client_phone,
                source_institution,
                source_wallet,
                amount,
                currency,
                fee_amount,
                swap_code,
                pin_code,
                code_expiry,
                cashout_point,
                cashout_provider,
                status,
                created_at,
                updated_at,
                metadata,
                user_id
            ) VALUES (
                :swap_ref,
                :client_phone,
                :source_inst,
                :source_wallet,
                :amount,
                :currency,
                :fee_amount,
                :swap_code,
                :pin_code,
                :code_expiry,
                :cashout_point,
                :cashout_provider,
                :status,
                :created_at,
                :updated_at,
                :metadata::jsonb,
                :user_id
            ) ON CONFLICT (swap_reference) DO UPDATE SET
    status = EXCLUDED.status,
    updated_at = NOW(),
    completed_at = CASE WHEN EXCLUDED.status = 'COMPLETED' THEN NOW() ELSE cashout_authorizations.completed_at END,
    user_id = EXCLUDED.user_id
        ";
        
        $status = 'PENDING';
        if (isset($destResponse['status'])) {
            $status = strtoupper($destResponse['status']);
        } elseif (isset($swapData['status'])) {
            $status = strtoupper($swapData['status']);
        }
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':swap_ref' => $swapRef,
                ':client_phone' => $details['beneficiary_phone'] ?? $details['client_phone'] ?? null,
                ':source_inst' => $details['source_institution'] ?? $swapData['from_institution'] ?? null,
                ':source_wallet' => $details['source_identifier'] ?? null,
                ':amount' => $swapData['amount'] ?? $details['amount'] ?? 0,
                ':currency' => $swapData['currency'] ?? $details['currency'] ?? 'BWP',
                ':fee_amount' => $details['fee_amount'] ?? 0,
                ':swap_code' => $destResponse['cashout_code'] ?? $destResponse['swap_code'] ?? null,
                ':pin_code' => $destResponse['pin_code'] ?? null,
                ':code_expiry' => $destResponse['expiry'] ?? null,
                ':cashout_point' => $details['delivery_method'] ?? $swapData['delivery_method'] ?? 'ATM',
                ':cashout_provider' => $details['destination_institution'] ?? $swapData['to_institution'] ?? null,
                ':status' => $status,
                ':created_at' => date('Y-m-d H:i:s'),
                ':updated_at' => date('Y-m-d H:i:s'),
                ':metadata' => json_encode([
                    'source' => 'swap_service',
                    'hold_id' => $this->currentHoldId,
                    'destination_response' => $destResponse
                ]),
                ':user_id' => $userId
            ]);
            
            $this->logger->debug("cashout_authorizations populated", ['swap_ref' => $swapRef, 'user_id' => $userId]);
            
        } catch (PDOException $e) {
            $this->logger->error("Failed to populate cashout_authorizations", ['error' => $e->getMessage(), 'swap_ref' => $swapRef]);
        }
    }
    
    private function populateDepositTransaction(string $swapRef, array $swapData, array $details, ?int $userId = null): void
{
    // Get beneficiary phone from identity_swap_holds
    $clientPhone = null;
    try {
        $stmt = $this->swapDB->prepare("
            SELECT otp_pin_sent_to 
            FROM identity_swap_holds 
            WHERE swap_reference = :swap_ref
            LIMIT 1
        ");
        $stmt->execute([':swap_ref' => $swapRef]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && !empty($result['otp_pin_sent_to'])) {
            $clientPhone = $result['otp_pin_sent_to'];
        }
    } catch (PDOException $e) {
        $this->logger->warning("Failed to get beneficiary phone", ['error' => $e->getMessage()]);
    }

    // Fallback if not found
    if (empty($clientPhone)) {
        $clientPhone = $details['client_phone'] ?? 
                       $details['beneficiary_phone'] ?? 
                       $details['notification_phone'] ?? 
                       'unknown_' . substr($swapRef, 0, 20);
    }

    $sql = "
        INSERT INTO deposit_transactions (
            transaction_reference,
            client_phone,
            source_type,
            source_institution,
            source_account,
            destination_type,
            destination_institution,
            destination_account,
            amount,
            currency,
            fee_amount,
            status,
            created_at,
            updated_at,
            completed_at,
            metadata,
            user_id
        ) VALUES (
            :tx_ref,
            :client_phone,
            :source_type,
            :source_inst,
            :source_account,
            :dest_type,
            :dest_inst,
            :dest_account,
            :amount,
            :currency,
            :fee_amount,
            :status,
            :created_at,
            :updated_at,
            :completed_at,
            :metadata::jsonb,
            :user_id
        ) ON CONFLICT (transaction_reference) DO UPDATE SET
            status = EXCLUDED.status,
            updated_at = NOW(),
            completed_at = CASE 
                WHEN EXCLUDED.status = 'COMPLETED' THEN NOW() 
                ELSE deposit_transactions.completed_at 
            END,
            user_id = EXCLUDED.user_id
    ";
    
    $status = 'COMPLETED';
    if (isset($swapData['status'])) {
        $status = strtoupper($swapData['status']);
    } elseif (isset($details['status'])) {
        $status = strtoupper($details['status']);
    }
    
    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':tx_ref' => $swapRef,
            ':client_phone' => $clientPhone,
            ':source_type' => $details['asset_type'] ?? $swapData['asset_type'] ?? 'ACCOUNT',
            ':source_inst' => $details['source_institution'] ?? $swapData['from_institution'] ?? null,
            ':source_account' => $details['source_identifier'] ?? null,
            ':dest_type' => $details['destination_asset_type'] ?? $swapData['destination_asset_type'] ?? 'ACCOUNT',
            ':dest_inst' => $details['destination_institution'] ?? $swapData['to_institution'] ?? null,
            ':dest_account' => $details['destination_identifier'] ?? null,
            ':amount' => $swapData['amount'] ?? $details['amount'] ?? 0,
            ':currency' => $swapData['currency'] ?? $details['currency'] ?? 'BWP',
            ':fee_amount' => $details['fee_amount'] ?? 0,
            ':status' => $status,
            ':created_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':completed_at' => $status === 'COMPLETED' ? date('Y-m-d H:i:s') : null,
            ':metadata' => json_encode([
                'source' => 'swap_service',
                'hold_id' => $this->currentHoldId
            ]),
            ':user_id' => $userId
        ]);
        
        $this->logger->debug("deposit_transactions populated", ['tx_ref' => $swapRef, 'user_id' => $userId]);
        
    } catch (PDOException $e) {
        $this->logger->error("Failed to populate deposit_transactions", ['error' => $e->getMessage(), 'swap_ref' => $swapRef]);
    }
}

    /**
     * Populate message_outbox table
     */
    private function populateMessageOutbox(string $swapRef, array $swapData, array $details, ?array $destResponse, ?int $userId = null): void
    {
        if (!$destResponse || empty($destResponse['cashout_code'])) {
            return;
        }
        
        $phone = $details['beneficiary_phone'] ?? $details['client_phone'] ?? null;
        if (!$phone) {
            return;
        }
        
        $code = $destResponse['cashout_code'] ?? $destResponse['swap_code'] ?? null;
        $pin = $destResponse['pin_code'] ?? null;
        $amount = $swapData['amount'] ?? $details['amount'] ?? 0;
        $currency = $swapData['currency'] ?? $details['currency'] ?? 'BWP';
        $expiry = $destResponse['expiry'] ?? null;
        
        $message = "Your VouchMorph cashout code: {$code}";
        if ($pin) {
            $message .= " PIN: {$pin}";
        }
        $message .= " Amount: {$amount} {$currency}";
        if ($expiry) {
            $message .= " Expires: {$expiry}";
        }
        
        $sql = "
            INSERT INTO message_outbox (
                channel,
                destination,
                payload,
                status,
                created_at,
                sent_at,
                user_id
            ) VALUES (
                'SMS',
                :destination,
                :payload::jsonb,
                'queued',
                :created_at,
                NULL,
                :user_id
            ) ON CONFLICT (destination, created_at) DO NOTHING
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':destination' => $phone,
                ':payload' => json_encode([
                    'phone' => $phone,
                    'message' => $message,
                    'swap_reference' => $swapRef,
                    'code' => $code,
                    'pin' => $pin,
                    'amount' => $amount,
                    'currency' => $currency,
                    'expiry' => $expiry,
                    'user_id' => $userId,
                    'api_response' => [
                        'success' => true,
                        'message' => 'SMS queued from swap_service'
                    ]
                ]),
                ':created_at' => date('Y-m-d H:i:s'),
                ':user_id' => $userId
            ]);
            
            $this->logger->debug("message_outbox populated", ['destination' => $phone, 'swap_ref' => $swapRef, 'user_id' => $userId]);
            
        } catch (PDOException $e) {
            $this->logger->error("Failed to populate message_outbox", ['error' => $e->getMessage(), 'swap_ref' => $swapRef]);
        }
    }

    public function executeAtomicSwap(array $payload): array
    {
        error_log("[SwapService] executeAtomicSwap called");
        error_log("[SwapService] Payload keys: " . implode(', ', array_keys($payload)));
        
        if (isset($payload['original_payload'])) {
            error_log("[SwapService] Signed envelope detected, extracting original_payload");
            $this->signedPayloads['envelope'] = [
                'signature' => $payload['signature'] ?? null,
                'timestamp' => $payload['timestamp'] ?? null
            ];
            $payload = $payload['original_payload'];
        }
        
        $swapType = $payload['swap_type'] ?? 'STANDARD';
        
        $isMultiSource = isset($payload['sources']) && is_array($payload['sources']) && count($payload['sources']) > 0;
        $isMultiDestination = isset($payload['destinations']) && is_array($payload['destinations']) && count($payload['destinations']) >= 1;
        
        if ($isMultiSource) {
            $swapType = 'MULTI_SOURCE';
            error_log("[SwapService] MULTI-SOURCE DETECTED: " . count($payload['sources']) . " sources");
        }
        
        if ($isMultiDestination) {
            $swapType = 'MULTI_DESTINATION';
            error_log("[SwapService] MULTI-DESTINATION DETECTED: " . count($payload['destinations']) . " destinations");
        }
        
        if ($swapType !== 'IDENTITY' && $swapType !== 'CONFIRM_IDENTITY') {
            if ($isMultiSource) {
                foreach ($payload['sources'] as $idx => $source) {
                    if (empty($source['institution'])) {
                        throw new RuntimeException("Source institution required for source at index {$idx}");
                    }
                    error_log("[SwapService] Multi-source source {$idx}: {$source['institution']}");
                }
            }
            
            if ($isMultiDestination) {
                foreach ($payload['destinations'] as $idx => $dest) {
                    $isIdentity = isset($dest['identity_type']) && !empty($dest['identity_value']);
                    
                    if ($isIdentity) {
                        $identityType = strtolower($dest['identity_type'] ?? '');
                        if (!$this->isValidIdentityType($identityType)) {
                            throw new RuntimeException("Invalid identity_type for destination at index {$idx}. Must be one of: " . $this->validIdentityTypesLabel());
                        }
                        if (empty($dest['identity_value'])) {
                            throw new RuntimeException("identity_value required for identity destination at index {$idx}");
                        }
                        error_log("[SwapService] Multi-destination dest {$idx}: IDENTITY ({$identityType}={$dest['identity_value']})");
                        
                    } else {
                        if (empty($dest['to_institution']) && empty($dest['destination_institution'])) {
                            throw new RuntimeException("Destination institution required for destination at index {$idx}");
                        }
                        error_log("[SwapService] Multi-destination dest {$idx}: BANK (" . ($dest['to_institution'] ?? $dest['destination_institution']) . ")");
                    }
                }
            }
            
            if (!$isMultiSource && !$isMultiDestination) {
                $this->validateInstitutions($payload, $swapType !== 'DEPOSIT');
                
                $sourceInst = $this->extractSourceInstitution($payload);
                error_log("[SwapService] Source: {$sourceInst}, Type: {$swapType}");
                
                if ($swapType !== 'DEPOSIT') {
                    $destInst = $this->extractDestinationInstitution($payload);
                    error_log("[SwapService] Destination: {$destInst}");
                }
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
                'MULTI_DESTINATION' => $this->executeMultiDestinationSwap($payload),
                'CASHOUT' => $this->executeSignedCashout($payload),
                'DEPOSIT' => $this->executeSignedDeposit($payload),
                'IDENTITY' => $this->initiateSwapToIdentity($payload),
                'CONFIRM_IDENTITY' => $this->confirmAndFinalizeIdentitySwap($payload),
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
            
                } catch (\Throwable $e) {
            $this->logger->error("Atomic swap failed", [
                'reference' => $ref,
                'step' => $this->getLastStep(),
                'error' => $e->getMessage(),
                'exception_class' => get_class($e)
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

    public function executeMultiDestinationSwap(array $payload): array
    {
        error_log("[SwapService] ===== executeMultiDestinationSwap START =====");
        
        $sourceInstitution = $this->extractSourceInstitution($payload);
        
        $destinations = $payload['destinations'] ?? [];
        if (empty($destinations)) {
            throw new RuntimeException("At least 1 destination required for multi-destination swap");
        }
        
        $currency = $payload['currency'] ?? $this->config['currency'] ?? 'BWP';
        $sourceIdentifier = $this->extractSourceIdentifier($payload);
        $multiDestRef = $payload['reference'] ?? $this->generateReference();
        
        $identityDestinations = [];
        $bankDestinations = [];
        
        foreach ($destinations as $idx => $dest) {
            $amount = (float)($dest['amount'] ?? 0);
            if ($amount <= 0) {
                throw new RuntimeException("Amount must be greater than 0 for destination " . ($idx + 1));
            }
            
            $isIdentity = isset($dest['identity_type']) && !empty($dest['identity_value']);
            
            if ($isIdentity) {
                $identityType = strtolower($dest['identity_type']);
                if (!$this->isValidIdentityType($identityType)) {
                    throw new RuntimeException("Invalid identity_type for destination " . ($idx + 1) . ". Must be one of: " . $this->validIdentityTypesLabel());
                }

                $dest['destination_currency'] = $dest['destination_currency'] ?? $dest['currency'] ?? $currency;

                $identityDestinations[] = [
                    'index' => $idx,
                    'amount' => $amount,
                    'identity_type' => $identityType,
                    'identity_value' => $dest['identity_value'],
                    'beneficiary_phone' => $dest['beneficiary_phone'] ?? null,
                    'delivery_method' => $dest['delivery_method'] ?? 'DEPOSIT',
                    'currency' => $dest['destination_currency'],
                    'original' => $dest
                ];

                error_log("[SwapService] Identity destination detected: {$identityType}={$dest['identity_value']}, amount={$amount}");
                
            } else {
                $institution = $dest['to_institution'] ?? $dest['destination_institution'] ?? $dest['institution'];
                if (empty($institution)) {
                    throw new RuntimeException("Destination institution required for destination " . ($idx + 1));
                }
                
                $identifier = $this->extractDestinationIdentifier($dest);
                if (!$identifier['has_value']) {
                    throw new RuntimeException("Destination identifier required for destination " . ($idx + 1));
                }
                
                $deliveryMethod = strtoupper($dest['delivery_method'] ?? 'DEPOSIT');
                if (!in_array($deliveryMethod, ['DEPOSIT', 'CASHOUT', 'VOUCHER', 'AGENT', 'WALLET', 'CARD', 'ATM'])) {
                    throw new RuntimeException("Invalid delivery_method for destination " . ($idx + 1) . ": {$deliveryMethod}");
                }
                
                $dest['currency'] = $dest['currency'] ?? $currency;
                $dest['delivery_method'] = $deliveryMethod;
                $dest['_destination_index'] = $idx;
                $dest['_sub_reference'] = $multiDestRef . '_DEST_' . $idx;
                $bankDestinations[] = $dest;
            }
        }
        
        error_log("[SwapService] Multi-destination: " . count($bankDestinations) . " bank destinations, " . count($identityDestinations) . " identity destinations");
        error_log("[SwapService] Source institution: {$sourceInstitution}");
        
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
        
        $destinationResults = [];
        $successfulDestinations = [];
        $failedDestinations = [];
        $totalFees = 0;
        $totalDelivered = 0;
        $totalHeld = 0;
        
        foreach ($bankDestinations as $idx => $dest) {
            $destAmount = (float)$dest['amount'];
            $destInstitution = $dest['to_institution'] ?? $dest['destination_institution'] ?? $dest['institution'];
            $deliveryMethod = $dest['delivery_method'];
            $destIdentifier = $this->extractDestinationIdentifier($dest);
            $subRef = $dest['_sub_reference'];
            
            error_log("[SwapService] Processing destination " . ($idx + 1) . ": {$destInstitution} - {$destAmount} via {$deliveryMethod}");
            
            $destHoldRef = null;
            $destHoldId = null;
            
            try {
                $feeType = 'DEPOSIT';
                
                if ($deliveryMethod === 'ATM' || $deliveryMethod === 'AGENT' || $deliveryMethod === 'CASHOUT') {
                    $feeType = 'CASHOUT';
                } elseif (isset($dest['identity_type']) || isset($dest['identity_value'])) {
                    $feeType = 'DEPOSIT';
                } elseif ($dest['destination_asset_type'] === 'CARD') {
                    $feeType = 'CARD_LOAD';
                }
                
                $feeBreakdown = $this->calculateFeesWithDetails($feeType, $destAmount, array_merge($payload, $dest));
                $netAmount = $feeBreakdown['net_amount'] ?? $destAmount;
                $feeAmount = $feeBreakdown['total_fee'] ?? 0;
                
                $adjustment = $this->adjustAmountForDelivery($netAmount, $deliveryMethod, $dest['currency'] ?? $currency);
                $deliverableAmount = $adjustment['deliverable_amount'];
                $remainderAtSource = $adjustment['remainder_at_source'] ?? 0;
                
                if ($deliverableAmount <= 0) {
                    throw new RuntimeException("Deliverable amount is zero for destination " . ($idx + 1));
                }
                
                $holdPayload = $payload;
                $holdPayload['amount'] = $destAmount + $feeAmount;
                $holdPayload['hold_reason'] = 'MULTI_DESTINATION_DEST_' . $idx;
                $holdPayload['reference'] = $subRef;
                $holdPayload['to_institution'] = $destInstitution;
                $holdPayload['destination_institution'] = $destInstitution;
                $holdPayload['destination_identifier'] = $destIdentifier['identifier'];
                $holdPayload['destination_identifier_type'] = $destIdentifier['type'];
                $holdPayload['from_institution'] = $sourceInstitution;
                $holdPayload['source_institution'] = $sourceInstitution;
                
                $originalSwapRef = $this->currentSwapRef;
                $this->currentSwapRef = $subRef;
                
                $holdResult = $this->executeStep('PLACE_HOLD_SIGNED_DEST_' . $idx, function() use ($holdPayload, $sourceInstitution, $verificationResult) {
                    return $this->placeHoldSigned($holdPayload, $sourceInstitution, $verificationResult);
                });
                
                $this->currentSwapRef = $originalSwapRef;
                
                if (!($holdResult['hold_placed'] ?? false)) {
                    throw new RuntimeException("Hold failed for destination " . ($idx + 1) . ": " . ($holdResult['message'] ?? 'Unknown error'));
                }
                
                $this->assertStepIntegrity(
                    $holdResult,
                    'hold_placed',
                    ['hold_reference', 'signature'],
                    'PLACE_HOLD_SIGNED_DEST_' . $idx
                );
                
                $destHoldRef = $holdResult['hold_reference'];
                $destHoldId = $holdResult['local_hold_id'];
                $totalHeld += ($destAmount + $feeAmount);
                
                error_log("[SwapService] Hold placed for destination " . ($idx + 1) . ": {$destHoldRef}");
                
                $originalHoldRef = $this->currentHoldReference;
                $originalHoldId = $this->currentHoldId;
                $this->currentHoldReference = $destHoldRef;
                $this->currentHoldId = $destHoldId;
                
                $destResult = match($deliveryMethod) {
                    'CASHOUT', 'AGENT', 'ATM' => $this->processMultiDestinationCashout(
                        $payload, 
                        $dest, 
                        $destInstitution, 
                        $deliverableAmount,
                        $destIdentifier
                    ),
                    'DEPOSIT', 'WALLET', 'CARD' => $this->processMultiDestinationDeposit(
                        $payload,
                        $dest,
                        $destInstitution,
                        $deliverableAmount,
                        $destIdentifier
                    ),
                    'VOUCHER' => $this->processMultiDestinationVoucher(
                        $payload,
                        $dest,
                        $destInstitution,
                        $deliverableAmount,
                        $destIdentifier
                    ),
                    default => throw new RuntimeException("Unsupported delivery method: {$deliveryMethod}")
                };
                
                $this->currentHoldReference = $originalHoldRef;
                $this->currentHoldId = $originalHoldId;
                
                if (!($destResult['success'] ?? false)) {
                    throw new RuntimeException("Destination processing failed: " . ($destResult['message'] ?? 'Unknown error'));
                }
                
                error_log("[SwapService] Debiting hold for destination " . ($idx + 1) . ": {$destHoldRef}");
                
                $this->currentHoldReference = $destHoldRef;
                $this->currentHoldId = $destHoldId;
                
                $debitPayload = [
                    'reference' => $subRef,
                    'hold_reference' => $destHoldRef,
                    'amount' => $destAmount + $feeAmount,
                    'reason' => 'Multi-destination swap - destination ' . ($idx + 1),
                    'from_institution' => $sourceInstitution,
                    'source_institution' => $sourceInstitution
                ];
                
                $debitResult = $this->executeStep('DEBIT_SOURCE_DEST_' . $idx, function() use ($debitPayload, $sourceInstitution) {
                    return $this->debitSource($debitPayload, $sourceInstitution);
                });
                
                $this->currentHoldReference = $originalHoldRef;
                $this->currentHoldId = $originalHoldId;
                
                if (!($debitResult['debited'] ?? false)) {
                    throw new RuntimeException("Debit failed for destination " . ($idx + 1) . ": " . ($debitResult['message'] ?? 'Unknown error'));
                }
                
                $this->updateHoldStatus($destHoldId, 'DEBITED');
                
                $successResult = [
                    'index' => $idx,
                    'sub_reference' => $subRef,
                    'destination_institution' => $destInstitution,
                    'destination_identifier' => $destIdentifier['identifier'],
                    'destination_identifier_type' => $destIdentifier['type'],
                    'delivery_method' => $deliveryMethod,
                    'requested_amount' => $destAmount,
                    'fee' => $feeAmount,
                    'net_amount' => $netAmount,
                    'deliverable_amount' => $deliverableAmount,
                    'remainder_at_source' => $remainderAtSource,
                    'hold_reference' => $destHoldRef,
                    'hold_id' => $destHoldId,
                    'fee_breakdown' => $feeBreakdown,
                    'adjustment' => $adjustment,
                    'transaction_reference' => $destResult['transaction_reference'] ?? null,
                    'voucher_code' => $destResult['voucher_code'] ?? null,
                    'status' => 'success',
                    'result' => $destResult,
                    'type' => 'bank'
                ];
                
                $destinationResults[] = $successResult;
                $successfulDestinations[] = $successResult;
                $totalFees += $feeAmount;
                $totalDelivered += $deliverableAmount;
                
            } catch (Exception $e) {
                error_log("[SwapService] Destination " . ($idx + 1) . " FAILED: " . $e->getMessage());
                
                if (isset($destHoldRef) && isset($destHoldId)) {
                    try {
                        error_log("[SwapService] Releasing hold for failed destination: {$destHoldRef}");
                        
                        $adapter = $this->adapterFactory->getAdapter($sourceInstitution);
                        $adapter->releaseHold([
                            'hold_reference' => $destHoldRef,
                            'action' => 'RELEASE_HOLD',
                            'reason' => 'Destination failed - ' . $e->getMessage()
                        ], []);
                        
                        $this->updateHoldStatus($destHoldId, 'RELEASED');
                        
                    } catch (Exception $releaseError) {
                        error_log("[SwapService] Failed to release hold: " . $releaseError->getMessage());
                    }
                }
                
                $failedResult = [
                    'index' => $idx,
                    'sub_reference' => $subRef ?? null,
                    'destination_institution' => $destInstitution ?? 'unknown',
                    'destination_identifier' => $destIdentifier['identifier'] ?? null,
                    'delivery_method' => $deliveryMethod ?? 'unknown',
                    'requested_amount' => $destAmount ?? 0,
                    'hold_reference' => $destHoldRef ?? null,
                    'hold_id' => $destHoldId ?? null,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'can_retry' => true,
                    'type' => 'bank'
                ];
                
                $destinationResults[] = $failedResult;
                $failedDestinations[] = $failedResult;
            }
        }
        
        foreach ($identityDestinations as $identityDest) {
            $idx = $identityDest['index'];
            $amount = $identityDest['amount'];
            $identityType = $identityDest['identity_type'];
            $identityValue = $identityDest['identity_value'];
            $beneficiaryPhone = $identityDest['beneficiary_phone'];
            $deliveryMethod = $identityDest['delivery_method'];
            $subRef = $multiDestRef . '_ID_' . $idx;
            
            error_log("[SwapService] Processing identity destination " . ($idx + 1) . ": {$identityType}={$identityValue}, amount={$amount}");
            
            $destHoldRef = null;
            $destHoldId = null;
            
            try {
                $feeType = 'DEPOSIT';
                
                if ($deliveryMethod === 'ATM' || $deliveryMethod === 'AGENT' || $deliveryMethod === 'CASHOUT') {
                    $feeType = 'CASHOUT';
                }
                
                $feeBreakdown = $this->calculateFeesWithDetails($feeType, $amount, array_merge($payload, $identityDest['original']));
                $netAmount = $feeBreakdown['net_amount'] ?? $amount;
                $feeAmount = $feeBreakdown['total_fee'] ?? 0;
                
                $holdPayload = $payload;
                $holdPayload['amount'] = $amount + $feeAmount;
                $holdPayload['hold_reason'] = 'MULTI_DESTINATION_IDENTITY_' . $idx;
                $holdPayload['reference'] = $subRef;
                $holdPayload['from_institution'] = $sourceInstitution;
                $holdPayload['source_institution'] = $sourceInstitution;
                
                $originalSwapRef = $this->currentSwapRef;
                $this->currentSwapRef = $subRef;
                
                $holdResult = $this->executeStep('PLACE_HOLD_IDENTITY_' . $idx, function() use ($holdPayload, $sourceInstitution, $verificationResult) {
                    return $this->placeHoldSigned($holdPayload, $sourceInstitution, $verificationResult);
                });
                
                $this->currentSwapRef = $originalSwapRef;
                
                if (!($holdResult['hold_placed'] ?? false)) {
                    throw new RuntimeException("Hold failed for identity " . ($idx + 1) . ": " . ($holdResult['message'] ?? 'Unknown error'));
                }
                
                $this->assertStepIntegrity(
                    $holdResult,
                    'hold_placed',
                    ['hold_reference', 'signature'],
                    'PLACE_HOLD_IDENTITY_' . $idx
                );
                
                $destHoldRef = $holdResult['hold_reference'];
                $destHoldId = $holdResult['local_hold_id'];
                $totalHeld += ($amount + $feeAmount);
                
                error_log("[SwapService] Hold placed for identity " . ($idx + 1) . ": {$destHoldRef}");
                
                $identityPayload = $payload;
                $identityPayload['swap_type'] = 'IDENTITY';
                $identityPayload['amount'] = $amount;
                $identityPayload['identity_type'] = $identityType;
                $identityPayload['identity_value'] = $identityValue;
                $identityPayload['beneficiary_phone'] = $beneficiaryPhone;
                $identityPayload['currency'] = $currency;
                $identityPayload['reference'] = $subRef;
                $identityPayload['delivery_method'] = $deliveryMethod;
                $identityPayload['from_institution'] = $sourceInstitution;
                $identityPayload['source_institution'] = $sourceInstitution;
                $identityPayload['_skip_hold'] = true;
                $identityPayload['hold_reference'] = $destHoldRef;
                
                $originalHoldRef = $this->currentHoldReference;
                $originalHoldId = $this->currentHoldId;
                $this->currentHoldReference = $destHoldRef;
                $this->currentHoldId = $destHoldId;
                
                $identityResult = $this->initiateSwapToIdentity($identityPayload);
                
                $this->currentHoldReference = $originalHoldRef;
                $this->currentHoldId = $originalHoldId;
                
                if (!($identityResult['status'] ?? false)) {
                    throw new RuntimeException("Identity processing failed: " . ($identityResult['message'] ?? 'Unknown error'));
                }
                
                error_log("[SwapService] Debiting hold for identity " . ($idx + 1) . ": {$destHoldRef}");
                
                $this->currentHoldReference = $destHoldRef;
                $this->currentHoldId = $destHoldId;
                
                $debitPayload = [
                    'reference' => $subRef,
                    'hold_reference' => $destHoldRef,
                    'amount' => $amount + $feeAmount,
                    'reason' => 'Multi-destination identity - ' . ($idx + 1),
                    'from_institution' => $sourceInstitution,
                    'source_institution' => $sourceInstitution
                ];
                
                $debitResult = $this->executeStep('DEBIT_IDENTITY_' . $idx, function() use ($debitPayload, $sourceInstitution) {
                    return $this->debitSource($debitPayload, $sourceInstitution);
                });
                
                $this->currentHoldReference = $originalHoldRef;
                $this->currentHoldId = $originalHoldId;
                
                if (!($debitResult['debited'] ?? false)) {
                    throw new RuntimeException("Debit failed for identity " . ($idx + 1) . ": " . ($debitResult['message'] ?? 'Unknown error'));
                }
                
                $this->updateHoldStatus($destHoldId, 'DEBITED');
                
                $successResult = [
                    'index' => $idx,
                    'type' => 'identity',
                    'identity_type' => $identityType,
                    'identity_value' => $identityValue,
                    'amount' => $amount,
                    'beneficiary_phone' => $beneficiaryPhone,
                    'delivery_method' => $deliveryMethod,
                    'fee' => $feeAmount,
                    'net_amount' => $netAmount,
                    'hold_reference' => $destHoldRef,
                    'hold_id' => $destHoldId,
                    'swap_reference' => $identityResult['swap_reference'] ?? null,
                    'expires_at' => $identityResult['expires_at'] ?? null,
                    'status' => 'pending_identity_confirmation',
                    'message' => $identityResult['message'] ?? 'Identity swap initiated - recipient must confirm identity within 24 hours',
                    'fee_breakdown' => $feeBreakdown,
                    'result' => $identityResult
                ];
                
                $destinationResults[] = $successResult;
                $successfulDestinations[] = $successResult;
                $totalFees += $feeAmount;
                
                error_log("[SwapService] Identity swap initiated: {$identityType}={$identityValue}, reference={$identityResult['swap_reference']}");
                
            } catch (Exception $e) {
                error_log("[SwapService] Identity destination " . ($idx + 1) . " FAILED: " . $e->getMessage());
                
                if (isset($destHoldRef) && isset($destHoldId)) {
                    try {
                        error_log("[SwapService] Releasing hold for failed identity: {$destHoldRef}");
                        
                        $adapter = $this->adapterFactory->getAdapter($sourceInstitution);
                        $adapter->releaseHold([
                            'hold_reference' => $destHoldRef,
                            'action' => 'RELEASE_HOLD',
                            'reason' => 'Identity destination failed - ' . $e->getMessage()
                        ], []);
                        
                        $this->updateHoldStatus($destHoldId, 'RELEASED');
                        
                    } catch (Exception $releaseError) {
                        error_log("[SwapService] Failed to release hold: " . $releaseError->getMessage());
                    }
                }
                
                $failedResult = [
                    'index' => $idx,
                    'type' => 'identity',
                    'identity_type' => $identityType,
                    'identity_value' => $identityValue,
                    'amount' => $amount,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'can_retry' => true
                ];
                
                $destinationResults[] = $failedResult;
                $failedDestinations[] = $failedResult;
            }
        }
        
        $multiDestId = $this->storeMultiDestinationRecord(
            $multiDestRef,
            $sourceInstitution,
            $destinations,
            $destinationResults,
            $totalFees,
            $totalDelivered,
            count($successfulDestinations),
            count($failedDestinations)
        );
        
        $settlementResults = [];
        foreach ($successfulDestinations as $destResult) {
            if (isset($destResult['type']) && $destResult['type'] === 'bank' && isset($destResult['destination_institution'])) {
                $settlement = $this->settlement->updateNetPosition(
                    $multiDestRef,
                    $sourceInstitution,
                    $destResult['destination_institution'],
                    $destResult['deliverable_amount'],
                    'MULTI_DESTINATION_COMPLETED',
                    $currency
                );
                
                if ($destResult['fee'] > 0) {
                    $this->settlement->invoiceFee(
                        $multiDestRef,
                        $sourceInstitution,
                        $this->getParticipantId($sourceInstitution),
                        'MULTI_DESTINATION_FEE',
                        $destResult['fee'],
                        $currency
                    );
                }
                
                $settlementResults[] = [
                    'destination_institution' => $destResult['destination_institution'],
                    'amount' => $destResult['deliverable_amount'],
                    'fee' => $destResult['fee'],
                    'hold_reference' => $destResult['hold_reference'],
                    'settlement' => $settlement
                ];
            }
        }
        
        return [
            'status' => count($failedDestinations) > 0 ? 'partial_success' : 'success',
            'reference' => $multiDestRef,
            'source_institution' => $sourceInstitution,
            'total_destinations' => count($destinations),
            'successful_destinations' => count($successfulDestinations),
            'failed_destinations' => count($failedDestinations),
            'total_amount' => array_sum(array_column($destinations, 'amount')),
            'total_fees' => $totalFees,
            'total_delivered' => $totalDelivered,
            'total_held' => $totalHeld,
            'multi_destination_id' => $multiDestId,
            'destinations' => $destinationResults,
            'settlement' => $settlementResults,
            'fee_calculation_details' => $this->feeCalculationDetails,
            'signature_chain' => $this->signedPayloads,
            'can_retry' => count($failedDestinations) > 0
        ];
    }

    // ============================================================================
    // MULTI-DESTINATION HELPER METHODS
    // ============================================================================

    private function storeMultiDestinationRecord(
        string $reference,
        string $sourceInstitution,
        array $destinations,
        array $results,
        float $totalFees,
        float $totalDelivered,
        int $successCount,
        int $failedCount
    ): int {
        $sql = "
            INSERT INTO multi_destination_swaps (
                reference,
                source_institution,
                total_destinations,
                successful_count,
                failed_count,
                total_amount,
                total_fees,
                total_delivered,
                status,
                destinations_payload,
                results_payload,
                created_at,
                updated_at
            ) VALUES (
                :reference,
                :source_institution,
                :total_destinations,
                :successful_count,
                :failed_count,
                :total_amount,
                :total_fees,
                :total_delivered,
                :status,
                :destinations_payload::jsonb,
                :results_payload::jsonb,
                NOW(),
                NOW()
            ) RETURNING id
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':reference' => $reference,
                ':source_institution' => $sourceInstitution,
                ':total_destinations' => count($destinations),
                ':successful_count' => $successCount,
                ':failed_count' => $failedCount,
                ':total_amount' => array_sum(array_column($destinations, 'amount')),
                ':total_fees' => $totalFees,
                ':total_delivered' => $totalDelivered,
                ':status' => $failedCount > 0 ? 'partial' : 'completed',
                ':destinations_payload' => json_encode($destinations),
                ':results_payload' => json_encode($results)
            ]);
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : 0;
            
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to store multi-destination record: " . $e->getMessage());
            return 0;
        }
    }

    private function processMultiDestinationCashout(
        array $basePayload,
        array $dest,
        string $institution,
        float $amount,
        array $identifier
    ): array {
        $beneficiaryPhone = $dest['beneficiary_phone'] ?? $dest['client_phone'] ?? null;
        $sourceInstitution = $this->extractSourceInstitution($basePayload);

        $cashoutPayload = [
            'reference' => $this->currentSwapRef . '_DEST_' . ($dest['_destination_index'] ?? 0),
            'amount' => $amount,
            'currency' => $dest['currency'] ?? 'BWP',
            'delivery_method' => $dest['delivery_method'],
            'beneficiary_phone' => $beneficiaryPhone,
            'destination_identifier' => $identifier['identifier'],
            'destination_identifier_type' => $identifier['type'],
            'hold_reference' => $this->currentHoldReference,
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null,
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'to_institution' => $institution,
            'destination_institution' => $institution,
            'action' => 'GENERATE_TOKEN'
        ];

        $fieldsToCopy = ['wallet_pin', 'pin', 'access_token', 'source_reference', '_is_hooked'];
        foreach ($fieldsToCopy as $field) {
            if (isset($dest[$field])) {
                $cashoutPayload[$field] = $dest[$field];
            } elseif (isset($basePayload[$field])) {
                $cashoutPayload[$field] = $basePayload[$field];
            }
        }

        $adapter = $this->adapterFactory->getAdapter($institution);
        $result = $adapter->generateCashoutToken($cashoutPayload, [
            'swap_reference' => $this->currentSwapRef,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $institution,
            'hold_reference' => $this->currentHoldReference,
            'signed_payloads' => $this->signedPayloads
        ]);

        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => $result['message'] ?? 'Cashout generation failed'];
        }

        if ($beneficiaryPhone && isset($result['atm_pin']) && $this->smsService) {
            try {
                $this->smsService->sendCashoutCode(
                    $beneficiaryPhone,
                    $result['atm_pin'],
                    $amount,
                    $result['voucher_number'] ?? null
                );
            } catch (Exception $e) {
                error_log("[SwapService] SMS failed but continuing: " . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'voucher_code' => $result['voucher_number'] ?? $result['swap_code'] ?? null,
            'atm_pin' => $result['atm_pin'] ?? null,
            'message' => $result['message'] ?? 'Cashout code generated'
        ];
    }

    private function processMultiDestinationDeposit(
        array $basePayload,
        array $dest,
        string $institution,
        float $amount,
        array $identifier
    ): array {
        $sourceInstitution = $this->extractSourceInstitution($basePayload);
        $destinationAssetType = $this->extractDestinationAssetType($dest);

        $depositPayload = [
            'reference' => $this->currentSwapRef . '_DEST_' . ($dest['_destination_index'] ?? 0),
            'amount' => $amount,
            'currency' => $dest['currency'] ?? 'BWP',
            'destination_identifier' => $identifier['identifier'],
            'destination_identifier_type' => $identifier['type'],
            'destination_asset_type' => $destinationAssetType,
            'hold_reference' => $this->currentHoldReference,
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null,
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'to_institution' => $institution,
            'destination_institution' => $institution,
            'action' => 'PROCESS_DEPOSIT_WITH_PROOF'
        ];

        if ($destinationAssetType === 'ACCOUNT') {
            $depositPayload['account_number'] = $identifier['identifier'];
            $depositPayload['destination_account'] = $identifier['identifier'];
        } else {
            $depositPayload['phone'] = $identifier['identifier'];
            $depositPayload['wallet_phone'] = $identifier['identifier'];
        }

        $fieldsToCopy = ['access_token', 'source_reference', '_is_hooked', 'account_name', 'bank_code', 'branch_code'];
        foreach ($fieldsToCopy as $field) {
            if (isset($dest[$field])) {
                $depositPayload[$field] = $dest[$field];
            } elseif (isset($basePayload[$field])) {
                $depositPayload[$field] = $basePayload[$field];
            }
        }

        $adapter = $this->adapterFactory->getAdapter($institution);
        $result = $adapter->credit($depositPayload, [
            'swap_reference' => $this->currentSwapRef,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $institution,
            'hold_reference' => $this->currentHoldReference,
            'signed_payloads' => $this->signedPayloads
        ]);

        if (!($result['credited'] ?? false)) {
            return ['success' => false, 'message' => $result['message'] ?? 'Deposit failed'];
        }

        $this->assertStepIntegrity(
            $result,
            'credited',
            ['transaction_reference'],
            'PROCESS_DEPOSIT_WITH_PROOF_DEST_' . ($dest['_destination_index'] ?? 0)
        );

        return [
            'success' => true,
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'message' => $result['message'] ?? 'Deposit successful'
        ];
    }

    private function processMultiDestinationVoucher(
        array $basePayload,
        array $dest,
        string $institution,
        float $amount,
        array $identifier
    ): array {
        $sourceInstitution = $this->extractSourceInstitution($basePayload);
        
        $voucherPayload = [
            'reference' => $this->currentSwapRef . '_DEST_' . ($dest['_destination_index'] ?? 0),
            'amount' => $amount,
            'currency' => $dest['currency'] ?? 'BWP',
            'destination_identifier' => $identifier['identifier'],
            'destination_identifier_type' => $identifier['type'],
            'hold_reference' => $this->currentHoldReference,
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null,
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'to_institution' => $institution,
            'destination_institution' => $institution,
            'action' => 'GENERATE_VOUCHER',
            'voucher_type' => $dest['voucher_type'] ?? 'GENERIC',
            'voucher_details' => $dest['voucher_details'] ?? []
        ];
        
        $fieldsToCopy = ['access_token', 'source_reference', '_is_hooked'];
        foreach ($fieldsToCopy as $field) {
            if (isset($dest[$field])) {
                $voucherPayload[$field] = $dest[$field];
            } elseif (isset($basePayload[$field])) {
                $voucherPayload[$field] = $basePayload[$field];
            }
        }
        
        $adapter = $this->adapterFactory->getAdapter($institution);
        $result = $adapter->generateVoucher($voucherPayload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Voucher generation failed'];
        }
        
        $data = $result['data'] ?? [];
        
        if (empty($data['voucher_code']) && empty($data['transaction_reference'])) {
            $this->logger->error("Voucher generation returned success but no proof", [
                'institution' => $institution,
                'data' => $data
            ]);
            return ['success' => false, 'message' => 'Voucher generated but no voucher_code returned'];
        }
        
        $beneficiaryPhone = $dest['beneficiary_phone'] ?? $dest['client_phone'] ?? null;
        if ($beneficiaryPhone && isset($data['voucher_code']) && $this->smsService) {
            try {
                $this->smsService->sendVoucherCode(
                    $beneficiaryPhone,
                    $data['voucher_code'],
                    $amount,
                    $dest['voucher_type'] ?? 'GENERIC'
                );
            } catch (Exception $e) {
                error_log("[SwapService] SMS failed but continuing: " . $e->getMessage());
            }
        }
        
        return [
            'success' => true,
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'voucher_code' => $data['voucher_code'] ?? null,
            'message' => $data['message'] ?? 'Voucher generated'
        ];
    }

    // ============================================================================
    // EXECUTE SIGNED CASHOUT - UPDATED WITH TABLE POPULATION
    // ============================================================================

    private function executeSignedCashout(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedCashout START =====");
        
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $this->extractSourceInstitution($payload);
        $destinationInstitution = $this->extractDestinationInstitution($payload);
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        $deliveryMethod = strtoupper($payload['delivery_method'] ?? 'ATM');
        
        if (empty($payload['destination_currency'])) {
            $payload['destination_currency'] = $this->config['currency'] ?? 'BWP';
            error_log("[SwapService] No destination_currency provided for CASHOUT - defaulting to ATM currency: {$payload['destination_currency']}");
        }
        
        $isHooked = isset($payload['_is_hooked']) && $payload['_is_hooked'] === true;
        $skipHold = isset($payload['_skip_hold']) && $payload['_skip_hold'] === true;
        
        error_log("[SwapService] Source: {$sourceInstitution}, Dest: {$destinationInstitution}, Amount: {$amount}");

         
// Reject early if this withdrawal would leave an un-redeemable dust
// amount of earmarked identity money behind. Uses the SOURCE side of
// this cashout, since a cashout's "source" is the account the money
// is being withdrawn FROM (e.g. the shop/agent's account that
// received identity-swap money earlier).
$sourceIdForEarmarkCheck = $this->extractSourceIdentifier($payload);
if ($sourceIdForEarmarkCheck['has_value']) {
    $this->validateEarmarkedWithdrawal($sourceInstitution, $sourceIdForEarmarkCheck['identifier'], $amount);
}

        
        if (isset($payload['_cashout_validation'])) {
            $this->feeCalculationDetails['cashout_validation'] = $payload['_cashout_validation'];
        }
        if (isset($payload['note_breakdown'])) {
            $this->feeCalculationDetails['note_breakdown'] = $payload['note_breakdown'];
        }
        
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
            'timestamp' => $verificationResult['timestamp'],
            'is_hooked' => $isHooked
        ];
        
        if (!$skipHold) {
            $holdResult = $this->executeStep('PLACE_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
                return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
            });
            
            if (!($holdResult['hold_placed'] ?? false)) {
                $errorMessage = $holdResult['message'] ?? 'Failed to place hold';
                error_log("[SwapService] HOLD FAILED: {$errorMessage}");
                throw new RuntimeException("Hold failed: {$errorMessage}");
            }
            
            $this->assertStepIntegrity(
                $holdResult,
                'hold_placed',
                $isHooked ? ['hold_reference'] : ['hold_reference', 'signature'],
                'PLACE_HOLD_SIGNED'
            );
            
            $this->signedPayloads['hold'] = [
                'payload' => $holdResult['original_payload'],
                'signature' => $holdResult['signature'],
                'source' => $sourceInstitution,
                'timestamp' => $holdResult['timestamp'],
                'is_hooked' => $isHooked
            ];
            
            $this->currentHoldReference = $holdResult['hold_reference'] ?? $holdResult['data']['hold_reference'] ?? null;
        } else {
            error_log("[SwapService] SKIPPING hold placement - using existing hold");
        }
        
        $feeBreakdown = $this->calculateFeesWithDetails('CASHOUT', $amount, $payload);
        $amountToSend = $feeBreakdown['dispensable_amount'];
        $remainderAtSource = $feeBreakdown['remainder_balance'];
        $netAmount = $feeBreakdown['net_amount'];
        
        $currency = $feeBreakdown['destination_currency']
            ?? $payload['destination_currency']
            ?? $payload['currency']
            ?? 'BWP';
        $notes = $this->atmNotes[$currency] ?? [];
        
        if (empty($notes)) {
            throw new RuntimeException("No ATM denominations configured for {$currency}");
        }
        
        $lowestDenomination = min($notes);
        
        if ($amountToSend > 0 && $amountToSend < $lowestDenomination) {
            $deliveryMethod = 'AGENT';
            $amountToSend = $netAmount;
            $remainderAtSource = 0;
            error_log("[SwapService] Amount below ATM minimum denomination. Switching to AGENT cashout: {$amountToSend}");
        }
        
        if ($amountToSend <= 0) {
            throw new RuntimeException("Amount after fees ({$netAmount} {$currency}) is too small to deliver.");
        }
        
        $generateResult = $this->generateCashoutToken($payload, $destinationInstitution, $amountToSend);
        
        if (!($generateResult['success'] ?? false)) {
            throw new RuntimeException("Code generation failed: " . ($generateResult['message'] ?? 'Unknown error'));
        }
        
        if (empty($generateResult['atm_pin']) && empty($generateResult['voucher_number'])) {
            throw new RuntimeException("Destination failed: No code generated");
        }
        
        $destSplit = $this->feeCalculationDetails['destination_split'] ?? [];
$generateCodeFeePercent = $destSplit['generate_code_fee_percent'] ?? 10;
$destinationShare = $this->feeCalculationDetails['revenue_split']['destination_institution_percent'] ?? null;
// Prefer whatever the fee engine actually computed for the destination's
// generate-code portion if present; otherwise derive from percentages.
$generateCodeFeeAmount = $this->feeCalculationDetails['destination_split']['generate_code_fee_computed']
    ?? round((($feeBreakdown['total_fee'] ?? 0) * ($this->feesConfig['CASHOUT']['distribution']['split']['destination_institution_percent'] ?? 50) / 100)
        * ($generateCodeFeePercent / 100), 2);
$levyAmount = (float)($this->feesConfig['CASHOUT']['fee_components']['F7']['amount'] ?? 0);
 
 
// Reuse the identifier extracted earlier in this method (from the
// earmarked-balance validation step) rather than re-deriving it -
// same payload, same result, no reason to call this twice.
$sourceIdForAuth = $sourceIdForEarmarkCheck ?? $this->extractSourceIdentifier($payload);
 
$authId = $this->storeCashoutAuthorization(
    $this->currentSwapRef,
    $beneficiaryPhone,
    $sourceInstitution,
    $sourceIdForAuth['identifier'] ?? null,
    $sourceIdForAuth['type'] ?? null,
    $destinationInstitution,
    $amountToSend,
    $feeBreakdown['total_fee'] ?? 0,
    $generateCodeFeeAmount,
    $levyAmount,
    $generateResult['voucher_number'] ?? $generateResult['swap_code'],
    $generateResult['atm_pin'],
    $generateResult['expires_at']
);

 
// Buffer window: source-side hold must outlive the destination's
// code by a margin, so a release-hold cron never fires before a
// legitimate last-second redemption callback can arrive and be
// processed. Without this, releasing exactly at code_expiry risks
// a double-spend: client's balance freed up while the destination
// institution is simultaneously paying out cash on the same code.
$holdReleaseBufferHours = 6;
$holdExpiresAt = date('Y-m-d H:i:s', strtotime($generateResult['expires_at'] . " +{$holdReleaseBufferHours} hours"));
$this->updateHoldExpiry($this->currentHoldId, $holdExpiresAt);
 
$this->updateHoldStatus($this->currentHoldId, 'PENDING_CASHOUT');

        
        $this->populateTrackingTables(
            [
                'swap_type' => 'CASHOUT',
                'reference' => $this->currentSwapRef,
                'amount' => $amountToSend,
                'currency' => $payload['currency'] ?? 'BWP',
                'status' => 'pending',
                'from_institution' => $sourceInstitution,
                'to_institution' => $destinationInstitution,
                'user_id' => $payload['user_id'] ?? null
            ],
            $payload,
            $generateResult
        );
        
        return [
            'status' => 'pending',
            'reference' => $this->currentSwapRef,
            'hold_reference' => $this->currentHoldReference,
            'auth_id' => $authId,
            'swap_code' => $generateResult['voucher_number'] ?? $generateResult['swap_code'],
            'atm_code' => $generateResult['atm_pin'] ?? null,
            'voucher_number' => $generateResult['voucher_number'] ?? null,
            'amount' => $amountToSend,
            'original_requested_amount' => $payload['original_requested_amount'] ?? $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'delivery_method' => $deliveryMethod,
            'code_expiry' => $generateResult['expires_at'],
            'message' => 'Cashout code generated. User must cash out at ATM/Agent to complete the swap.',
            'fee_calculation_details' => $this->feeCalculationDetails,
            'signature_chain' => $this->signedPayloads,
            'is_hooked' => $isHooked,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $destinationInstitution
        ];
    }


     
/**
 * Release a cashout hold when it expires or is cancelled.
 * 
 * @param int $authId
 * @param string $reason
 * @return array
 */
public function releaseCashoutHold(int $authId, string $reason): array
{
    error_log("[SwapService] ===== releaseCashoutHold: auth_id={$authId}, reason={$reason} =====");

    $stmt = $this->swapDB->prepare("SELECT * FROM cashout_authorizations WHERE auth_id = :id");
    $stmt->execute([':id' => $authId]);
    $auth = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auth) {
        throw new RuntimeException("Cashout authorization not found: {$authId}");
    }

    if ($auth['status'] === 'COMPLETED') {
        error_log("[SwapService] auth_id={$authId} already COMPLETED - refusing to release");
        return ['status' => 'already_completed', 'auth_id' => $authId];
    }

    if (in_array($auth['status'], ['EXPIRED', 'DEBIT_FAILED'], true) && $auth['released_at']) {
        error_log("[SwapService] auth_id={$authId} already released at {$auth['released_at']}");
        return ['status' => 'already_released', 'auth_id' => $authId];
    }

    $sourceInstitution = $auth['source_institution'];
    $heldAmount = (float)$auth['amount'];
    $generateCodeFee = (float)($auth['generate_code_fee_amount'] ?? 0);
    $levy = (float)($auth['levy_amount'] ?? 0);
    $currency = $auth['currency'] ?? 'BWP';
    $swapRef = $auth['swap_reference'];

    $withheld = $generateCodeFee + $levy;
    $releaseAmount = max(0, $heldAmount - $withheld);

    error_log("[SwapService] Release breakdown: held={$heldAmount}, generate_code_fee={$generateCodeFee}, levy={$levy}, releasing={$releaseAmount}");

    // 1. Settle the generate-code fee to the destination institution
    if ($generateCodeFee > 0) {
        try {
            $this->settlement->invoiceFee(
                $swapRef,
                $sourceInstitution,
                $this->getParticipantId($auth['cashout_provider']),
                'CASHOUT_GENERATE_CODE_FEE',
                $generateCodeFee,
                $currency
            );
        } catch (Exception $e) {
            error_log("[SwapService] Failed to invoice generate-code fee on release: " . $e->getMessage());
        }
    }

    // 2. Withhold the levy
    if ($levy > 0) {
        try {
            $this->settlement->invoiceFee(
                $swapRef,
                $sourceInstitution,
                $this->getParticipantId('VOUCHMORPH'),
                'SWAP_LEVY',
                $levy,
                $currency
            );
        } catch (Exception $e) {
            error_log("[SwapService] Failed to invoice levy on release: " . $e->getMessage());
        }
    }

    // ============================================================
    // FIX: Look up the REAL bank-side hold reference ONCE.
    // swap_code is the client-facing ATM/voucher redemption code,
    // a completely different identifier. Using it here would tell
    // the source institution to debit/release against the wrong thing.
    // ============================================================
    $realHoldReference = $this->getHoldReferenceForSwap($swapRef);

    if (!$realHoldReference) {
        error_log("[SwapService] WARNING: No hold_transactions row found for swap_ref={$swapRef} during release - falling back to swap reference itself, but this should be investigated as a data integrity gap.");
    }
    $holdReferenceForRelease = $realHoldReference ?? $swapRef;

    // 3. Debit the withheld portion from the source hold (the fees are
    // real money that must leave the source institution), then release
    // whatever's left of the hold back to the customer's availability.
    if ($withheld > 0) {
        try {
            $adapter = $this->adapterFactory->getAdapter($sourceInstitution);
            $debitResult = $adapter->debit([
                'reference' => $swapRef . '_RELEASE_WITHHOLD',
                'hold_reference' => $holdReferenceForRelease,
                'amount' => $withheld,
                'reason' => 'Cashout expired - withholding generate-code fee + levy: ' . $reason,
                'from_institution' => $sourceInstitution,
                'source_institution' => $sourceInstitution,
            ], []);

            if (!(($debitResult['success'] ?? false) || ($debitResult['debited'] ?? false))) {
                error_log("[SwapService] WARNING: withheld-fee debit failed during release for auth_id={$authId} - proceeding with release anyway; reconcile manually. Response: " . json_encode($debitResult));
            }
        } catch (Exception $e) {
            error_log("[SwapService] Withheld-fee debit threw during release: " . $e->getMessage());
        }
    }

    // 4. Release the remaining hold
    try {
        $adapter = $this->adapterFactory->getAdapter($sourceInstitution);
        $releaseResult = $adapter->releaseHold([
            'hold_reference' => $holdReferenceForRelease,
            'action' => 'RELEASE_HOLD',
            'reason' => "Cashout expired unredeemed: {$reason}. Released " . $releaseAmount . " of " . $heldAmount . " (withheld {$withheld} in fees).",
        ], []);
    } catch (Exception $e) {
        error_log("[SwapService] Hold release call failed for auth_id={$authId}: " . $e->getMessage());
        $releaseResult = ['success' => false, 'error' => $e->getMessage()];
    }

    // 5. Update cashout authorization status
    $stmt = $this->swapDB->prepare("
        UPDATE cashout_authorizations
        SET status = 'EXPIRED', released_at = NOW(), release_reason = :reason, updated_at = NOW()
        WHERE auth_id = :id
    ");
    $stmt->execute([':reason' => $reason, ':id' => $authId]);

    // 6. Update the hold status
    $this->updateHoldForSwap($swapRef, 'PARTIALLY_RELEASED');

    // 7. Audit log
    try {
        $auditStmt = $this->swapDB->prepare("
            INSERT INTO audit_logs
            (entity_type, entity_id, action, category, severity, performed_by, metadata, performed_at)
            VALUES
            ('cashout_authorizations', :auth_id, 'CASHOUT_HOLD_RELEASED', 'financial', 'info', 'SYSTEM_CRON', :metadata, NOW())
        ");
        $auditStmt->execute([
            ':auth_id' => $authId,
            ':metadata' => json_encode([
                'reason' => $reason,
                'held_amount' => $heldAmount,
                'generate_code_fee_withheld' => $generateCodeFee,
                'levy_withheld' => $levy,
                'released_amount' => $releaseAmount,
                'swap_reference' => $swapRef,
                'hold_reference_used' => $holdReferenceForRelease,
            ])
        ]);
    } catch (Exception $e) {
        error_log("[SwapService] Audit log warning on release: " . $e->getMessage());
    }

    return [
        'status' => 'released',
        'auth_id' => $authId,
        'held_amount' => $heldAmount,
        'generate_code_fee_withheld' => $generateCodeFee,
        'levy_withheld' => $levy,
        'released_amount' => $releaseAmount,
        'release_result' => $releaseResult,
        'hold_reference_used' => $holdReferenceForRelease,
    ];
}
/**
 * Get the real hold reference for a swap.
 * 
 * Looks up the bank-side hold reference from hold_transactions.
 * 
 * @param string $swapRef
 * @return string|null
 */
private function getHoldReferenceForSwap(string $swapRef): ?string
{
    try {
        $stmt = $this->swapDB->prepare("
            SELECT hold_reference 
            FROM hold_transactions 
            WHERE swap_reference = :swap_ref 
            ORDER BY hold_id DESC 
            LIMIT 1
        ");
        $stmt->execute([':swap_ref' => $swapRef]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['hold_reference'])) {
            return $result['hold_reference'];
        }
        
        // Fallback: check cashout_authorizations
        $stmt = $this->swapDB->prepare("
            SELECT hold_reference 
            FROM cashout_authorizations 
            WHERE swap_reference = :swap_ref 
            ORDER BY auth_id DESC 
            LIMIT 1
        ");
        $stmt->execute([':swap_ref' => $swapRef]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['hold_reference'])) {
            return $result['hold_reference'];
        }
        
        return null;
    } catch (Exception $e) {
        error_log("[SwapService] Error getting hold reference for swap_ref={$swapRef}: " . $e->getMessage());
        return null;
    }
}



/* =================================================================
 * cancelExpiredCashouts(): the cron entry point,
 * mirroring cancelExpiredIdentitySwaps()'s pattern. Only picks up
 * authorizations whose code_expiry + 6h buffer has fully passed -
 * never acts on the bare code_expiry alone.
 * ================================================================= */

public function cancelExpiredCashouts(int $bufferHours = 6): array
{
    error_log("[SwapService] ===== cancelExpiredCashouts (buffer={$bufferHours}h) =====");

    $results = ['total_expired' => 0, 'released' => 0, 'errors' => 0, 'details' => []];

    $sql = "
        SELECT * FROM cashout_authorizations
        WHERE status IN ('PENDING', 'VERIFIED')
        AND code_expiry + (:buffer || ' hours')::interval < NOW()
    ";

    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':buffer' => $bufferHours]);
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['total_expired'] = count($expired);

        foreach ($expired as $auth) {
            try {
                // Get the actual hold reference before releasing
                $swapRef = $auth['swap_reference'];
                $realHoldReference = $this->getHoldReferenceForSwap($swapRef);
                
                if (!$realHoldReference) {
                    error_log("[SwapService] WARNING: No hold reference found for swap_ref={$swapRef} during cron expiration");
                }

                $result = $this->releaseCashoutHold(
                    (int)$auth['auth_id'],
                    "Unredeemed {$bufferHours}h past code expiry ({$auth['code_expiry']})"
                );
                $results['released']++;
                $results['details'][] = [
                    'auth_id' => $auth['auth_id'],
                    'swap_reference' => $auth['swap_reference'],
                    'status' => $result['status'],
                    'released_amount' => $result['released_amount'] ?? null,
                    'hold_reference_used' => $result['hold_reference_used'] ?? null,
                ];
            } catch (Exception $e) {
                error_log("[SwapService] Failed to release cashout hold for auth_id={$auth['auth_id']}: " . $e->getMessage());
                $results['errors']++;
                $results['details'][] = [
                    'auth_id' => $auth['auth_id'],
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;

    } catch (PDOException $e) {
        error_log("[SwapService] Failed to query expired cashouts: " . $e->getMessage());
        throw new RuntimeException("Failed to cancel expired cashouts: " . $e->getMessage());
    }
}
    private function executeSignedDeposit(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedDeposit START =====");
        
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $this->extractSourceInstitution($payload);
        $destinationInstitution = $this->extractDestinationInstitution($payload);
        $destinationIdentifier = $this->extractDestinationIdentifier($payload);
        $destinationAssetType = $this->extractDestinationAssetType($payload);
        
        if (empty($payload['destination_currency'])) {
            $destParticipant = $this->participants[$destinationInstitution] ?? null;
            $payload['destination_currency'] = $destParticipant['limits']['currency']
                ?? ($payload['currency'] ?? 'BWP');
            error_log("[SwapService] No destination_currency provided for DEPOSIT - defaulting from destination institution config: {$payload['destination_currency']}");
        }
        
        $isHooked = isset($payload['_is_hooked']) && $payload['_is_hooked'] === true;
        $skipHold = isset($payload['_skip_hold']) && $payload['_skip_hold'] === true;
        
        error_log("[SwapService] Source: {$sourceInstitution}, Dest: {$destinationInstitution}, Amount: {$amount}, AssetType: {$destinationAssetType}");
        
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
            'timestamp' => $verificationResult['timestamp'],
            'is_hooked' => $isHooked
        ];
        
        if (empty($destinationIdentifier['identifier'])) {
            throw new RuntimeException("Destination identifier is required for deposit");
        }
        
        $accountVerification = $this->executeStep('VERIFY_ACCOUNT', function() use ($payload, $destinationInstitution, $destinationIdentifier, $destinationAssetType) {
            $verifyPayload = $payload;
            $verifyPayload['destination_asset_type'] = $destinationAssetType;
            return $this->verifyAccount($verifyPayload, $destinationInstitution, $destinationIdentifier);
        });
        
        if (!($accountVerification['verified'] ?? false)) {
            throw new RuntimeException("Destination verification failed: " . ($accountVerification['message'] ?? 'Not found'));
        }
        
        $feeBreakdown = $this->calculateFeesWithDetails('DEPOSIT', $amount, $payload);
        $netAmount = $feeBreakdown['net_amount'] ?? $amount;
        
        if (!$skipHold) {
            $holdResult = $this->executeStep('PLACE_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
                return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
            });
            
            if (!($holdResult['hold_placed'] ?? false)) {
                throw new RuntimeException("Hold failed: " . ($holdResult['message'] ?? 'Unknown error'));
            }
            
            $this->assertStepIntegrity(
                $holdResult,
                'hold_placed',
                $isHooked ? ['hold_reference'] : ['hold_reference', 'signature'],
                'PLACE_HOLD_SIGNED'
            );
            
            $this->signedPayloads['hold'] = [
                'payload' => $holdResult['original_payload'],
                'signature' => $holdResult['signature'],
                'source' => $sourceInstitution,
                'timestamp' => $holdResult['timestamp'],
                'is_hooked' => $isHooked
            ];
            
            $this->currentHoldReference = $holdResult['hold_reference'] ?? null;
        } else {
            error_log("[SwapService] SKIPPING hold placement - using existing hold");
        }
        
        $depositResult = $this->executeStep('PROCESS_DEPOSIT_WITH_PROOF', function() use ($payload, $destinationInstitution, $netAmount, $accountVerification, $destinationAssetType) {
            $depositPayload = $payload;
            $depositPayload['amount'] = $netAmount;
            $depositPayload['account_verification'] = $accountVerification;
            $depositPayload['to_institution'] = $destinationInstitution;
            $depositPayload['destination_institution'] = $destinationInstitution;
            $depositPayload['from_institution'] = $this->extractSourceInstitution($payload);
            $depositPayload['source_institution'] = $this->extractSourceInstitution($payload);
            $depositPayload['destination_asset_type'] = $destinationAssetType;
            $depositPayload['asset_type'] = $destinationAssetType;
            $depositPayload['_skip_hold'] = true;
            
            return $this->processDepositWithProof($depositPayload, $destinationInstitution, $netAmount);
        });
        
        if (!($depositResult['credited'] ?? false)) {
            throw new RuntimeException("Deposit failed: " . ($depositResult['message'] ?? 'Unknown error'));
        }
        
        $this->assertStepIntegrity(
            $depositResult,
            'credited',
            ['transaction_reference'],
            'PROCESS_DEPOSIT_WITH_PROOF'
        );
        
        $debitResult = $this->executeStep('DEBIT_SOURCE', function() use ($payload, $sourceInstitution) {
            return $this->debitSource($payload, $sourceInstitution);
        });
        
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed: " . ($debitResult['message'] ?? 'Unknown error'));
        }
        
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        $settlementResult = $this->settlement->updateNetPosition(
            $this->currentSwapRef,
            $sourceInstitution,
            $destinationInstitution,
            $netAmount,
            'DEPOSIT_COMPLETED',
            $this->config['currency'] ?? 'BWP'
        );
        
        $this->settlement->invoiceFee(
            $this->currentSwapRef,
            $sourceInstitution,
            $this->getParticipantId($sourceInstitution),
            'VOUCHMORPH_FEE',
            $feeBreakdown['total_fee'] ?? 0,
            $this->config['currency'] ?? 'BWP'
        );
        
        $this->populateTrackingTables(
            [
                'swap_type' => 'DEPOSIT',
                'reference' => $this->currentSwapRef,
                'amount' => $netAmount,
                'currency' => $payload['currency'] ?? 'BWP',
                'status' => 'completed',
                'from_institution' => $sourceInstitution,
                'to_institution' => $destinationInstitution,
                'user_id' => $payload['user_id'] ?? null
            ],
            $payload,
            null
        );
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'original_amount' => $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'fee_calculation_details' => $this->feeCalculationDetails,
            'deposit_reference' => $depositResult['transaction_reference'] ?? null,
            'destination_identifier' => $destinationIdentifier['identifier'],
            'destination_asset_type' => $destinationAssetType,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $destinationInstitution,
            'settlement' => $settlementResult,
            'signature_chain' => $this->signedPayloads,
            'is_hooked' => $isHooked
        ];
    }

    // ============================================================================
    // IDENTITY SWAP FLOW
    // ============================================================================

    public function initiateSwapToIdentity(array $payload): array
    {
        error_log("[SwapService] ===== initiateSwapToIdentity (PAUSE AT HOLD) =====");

        $sourceInstitution = $this->extractSourceInstitution($payload);

        $required = ['amount', 'from_institution', 'source_identifier', 'identity_type', 'identity_value'];
        foreach ($required as $field) {
            if (empty($payload[$field])) {
                throw new RuntimeException("Missing required field: {$field}");
            }
        }

        $identityType = strtolower($payload['identity_type']);
        if (!$this->isValidIdentityType($identityType)) {
            throw new RuntimeException("Invalid identity_type. Must be one of: " . $this->validIdentityTypesLabel());
        }

        $skipHold = isset($payload['_skip_hold']) && $payload['_skip_hold'] === true;
        $swapRef = $payload['reference'] ?? $this->currentSwapRef ?? $this->generateReference();

        if (!$this->inAtomicSwap) {
            $this->beginAtomicSwap($swapRef);
        } else {
            $this->currentSwapRef = $swapRef;
        }

        try {
            if (!$skipHold) {
                error_log("[SwapService] STEP 1: Verify asset at source: {$sourceInstitution}");
                $verificationResult = $this->executeStep('VERIFY_ASSET_SIGNED', function() use ($payload, $sourceInstitution) {
                    return $this->verifyAssetSigned($payload, $sourceInstitution);
                });

                if (!($verificationResult['verified'] ?? false)) {
                    throw new RuntimeException("Asset verification failed: " . ($verificationResult['message'] ?? 'Unknown'));
                }

                $this->signedPayloads['verification'] = [
                    'payload' => $verificationResult['original_payload'],
                    'signature' => $verificationResult['signature'],
                    'source' => $sourceInstitution,
                    'timestamp' => $verificationResult['timestamp']
                ];

                error_log("[SwapService] STEP 2: Place hold on source");
                $holdResult = $this->executeStep('PLACE_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
                    return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
                });

                if (!($holdResult['hold_placed'] ?? false)) {
                    throw new RuntimeException("Hold failed: " . ($holdResult['message'] ?? 'Unknown'));
                }

                $this->assertStepIntegrity(
                    $holdResult,
                    'hold_placed',
                    ['hold_reference', 'signature'],
                    'PLACE_HOLD_SIGNED'
                );

                $this->signedPayloads['hold'] = [
                    'payload' => $holdResult['original_payload'],
                    'signature' => $holdResult['signature'],
                    'source' => $sourceInstitution,
                    'timestamp' => $holdResult['timestamp']
                ];

                $this->currentHoldReference = $holdResult['hold_reference'];
                $this->currentHoldId = $holdResult['local_hold_id'];

            } else {
                error_log("[SwapService] SKIPPING verify+hold - reusing existing hold: " . ($payload['hold_reference'] ?? $this->currentHoldReference ?? 'unknown'));

                $existingHoldRef = $payload['hold_reference'] ?? $this->currentHoldReference ?? null;
                $existingHoldId = $this->currentHoldId ?? null;

                if (empty($existingHoldRef) || $existingHoldId === null) {
                    throw new RuntimeException("_skip_hold set but no existing hold_reference/hold_id available to reuse");
                }

                $this->currentHoldReference = $existingHoldRef;
                $this->currentHoldId = $existingHoldId;

                $holdResult = [
                    'hold_reference' => $existingHoldRef,
                    'local_hold_id' => $existingHoldId
                ];
            }

            error_log("[SwapService] STEP 3: Store identity mapping (PAUSED)");
            $identityHoldId = $this->storeIdentityHold(
                $payload,
                $swapRef,
                $holdResult,
                $this->currentHoldId
            );

            $this->updateHoldStatus($this->currentHoldId, 'PENDING_IDENTITY');

            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            return [
                'status' => 'pending_identity_confirmation',
                'swap_reference' => $swapRef,
                'hold_reference' => $this->currentHoldReference,
                'hold_id' => $identityHoldId,
                'amount' => (float)$payload['amount'],
                'currency' => $payload['currency'] ?? 'BWP',
                'identity_type' => $identityType,
                'identity_value' => $payload['identity_value'],
                'source_institution' => $sourceInstitution,
                'expires_at' => $expiresAt,
                'message' => 'Swap paused. Recipient must confirm identity and choose destination within 24 hours.',
                'access_methods' => $this->getIdentityAccessMethods($identityType, $payload['identity_value'])
            ];

                } catch (\Throwable $e) {
            error_log("[SwapService] initiateSwapToIdentity FAILED (" . get_class($e) . "): " . $e->getMessage());
            if (!$this->inAtomicSwap) {
                $this->rollbackAtomicSwap($e->getMessage());
            }
            throw $e;
        }

    }

    public function confirmAndFinalizeIdentitySwap(array $payload): array
{
    error_log("[SwapService] ===== confirmAndFinalizeIdentitySwap =====");
    
    $swapRef = $payload['swap_reference'] ?? null;
    if (!$swapRef) {
        throw new RuntimeException("swap_reference required");
    }
    
    $identitySwap = $this->getIdentitySwapByReference($swapRef);
    if (!$identitySwap) {
        throw new RuntimeException("Identity swap not found: {$swapRef}");
    }
    
    if ($identitySwap['status'] !== 'pending') {
        throw new RuntimeException("Swap is not pending. Current status: " . $identitySwap['status']);
    }
    
    if (strtotime($identitySwap['hold_expires_at']) < time()) {
        throw new RuntimeException("Swap has expired (24hrs). Please initiate a new swap.");
    }
    
    $confirmedByType = $payload['confirmed_by_type'] ?? null;
$confirmedById = $payload['confirmed_by_id'] ?? null;
$identityType = $identitySwap['identity_type'];
$identityValue = $identitySwap['identity_value'];
$suppliedPin = (string)($payload['pin'] ?? '');
 
if (!in_array($confirmedByType, ['user', 'agent'], true)) {
    throw new RuntimeException("confirmed_by_type must be 'user' or 'agent'");
}
 
if ($confirmedByType === 'agent') {
    if (!$this->isAgentVerifiableIdentityType($identityType)) {
        throw new RuntimeException("Agents can only confirm document-based identity types (" . implode(', ', self::IDENTITY_TYPES_AGENT_VERIFIABLE) . "), not {$identityType}");
    }
    // Physical document check stays as an ADDITIONAL agent-side
    // control (matches how agents work in practice) - it does not
    // replace the PIN check below, both are required.
    $documentVerified = ($payload['identity_document_verified'] ?? null) === true
        || ($payload['national_id_verified'] ?? null) === true;
    if (!$documentVerified) {
        throw new RuntimeException("Agent must verify the physical {$identityType} first");
    }
}
 
// PIN check applies REGARDLESS of confirmed_by_type - a person
// relaying their PIN through an agent still must supply it. This
// is what stops a dishonest agent from finalizing alone.
$this->verifyIdentityClaimPin($identitySwap, $suppliedPin);
 

        
        $destinationType = strtoupper($payload['destination_type'] ?? 'CASHOUT');
        if (!in_array($destinationType, ['CASHOUT', 'DEPOSIT'])) {
            throw new RuntimeException("destination_type must be 'CASHOUT' or 'DEPOSIT'");
        }
        
        $this->updateIdentityHoldStatus($identitySwap['hold_id'], 'confirmed', [
            'confirmed_by_type' => $confirmedByType,
            'confirmed_by_id' => $confirmedById,
            'confirmation_method' => $payload['confirmation_method'] ?? ($confirmedByType === 'user' ? 'dashboard' : 'agent_portal'),
            'destination_type' => $destinationType
        ]);
        
        $sourcePayload = json_decode($identitySwap['source_payload'], true);
        $sourceInstitution = $identitySwap['source_institution'];
        
        $sourcePayload['from_institution'] = $sourceInstitution;
        $sourcePayload['source_institution'] = $sourceInstitution;
        $sourcePayload['amount'] = (float)$identitySwap['amount'];
        $sourcePayload['currency'] = $identitySwap['currency'] ?? 'BWP';
        $sourcePayload['asset_type'] = $identitySwap['source_asset_type'] ?? 'ACCOUNT';
        
        if (!$this->inAtomicSwap) {
            $this->beginAtomicSwap($swapRef);
        } else {
            $this->currentSwapRef = $swapRef;
        }
        
        try {
            error_log("[SwapService] Re-verifying asset availability for institution: {$sourceInstitution}");
            $verificationResult = $this->verifyAssetSigned($sourcePayload, $sourceInstitution);
            if (!($verificationResult['verified'] ?? false)) {
                $this->updateIdentityHoldStatus($identitySwap['hold_id'], 'cancelled', [
                    'cancellation_reason' => 'Funds no longer available'
                ]);
                throw new RuntimeException("Source funds no longer available. Swap cancelled.");
            }
            
            $this->currentHoldReference = $identitySwap['hold_reference'];
            $this->currentHoldId = $identitySwap['hold_id'];
            
            if ($destinationType === 'CASHOUT') {
                $result = $this->completeIdentitySwapAsCashout($sourcePayload, $identitySwap, $payload);
            } else {
                $result = $this->completeIdentitySwapAsDeposit($sourcePayload, $identitySwap, $payload);
            }
            
            $this->updateIdentityHoldStatus($identitySwap['hold_id'], 'completed', [
                'final_destination_type' => $destinationType,
                'final_destination_payload' => $payload['destination_details'] ?? [],
                'final_transaction_reference' => $result['transaction_reference'] ?? null
            ]);
            
            $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
            
            // ✅ ADD THIS ONE LINE
            $this->commitAtomicSwap();
            
            return [
                'status' => 'completed',
                'swap_reference' => $swapRef,
                'hold_id' => $identitySwap['hold_id'],
                'destination_type' => $destinationType,
                'amount' => (float)$identitySwap['amount'],
                'currency' => $identitySwap['currency'] ?? 'BWP',
                'transaction_reference' => $result['transaction_reference'] ?? null,
                'message' => "Identity swap completed via {$destinationType}",
                'result' => $result
            ];
            
                } catch (\Throwable $e) {
            error_log("[SwapService] confirmAndFinalizeIdentitySwap FAILED (" . get_class($e) . "): " . $e->getMessage());
            if (!$this->inAtomicSwap) {
                $this->rollbackAtomicSwap($e->getMessage());
            }
            throw $e;
        }
    }

    private function completeIdentitySwapAsCashout(array $sourcePayload, array $identitySwap, array $confirmationPayload): array
    {
        $sourceInstitution = $identitySwap['source_institution'];
        $destinationInstitution = $confirmationPayload['destination_institution'] ?? 'ATM';
        
        $cashoutPayload = [
            'swap_type' => 'CASHOUT',
            'reference' => $identitySwap['swap_reference'],
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'source_identifier' => $identitySwap['source_identifier'],
            'asset_type' => $identitySwap['source_asset_type'] ?? 'ACCOUNT',
            'amount' => (float)$identitySwap['amount'],
            'currency' => $identitySwap['currency'] ?? 'BWP',
            'to_institution' => $destinationInstitution,
            'destination_institution' => $destinationInstitution,
            'delivery_method' => $confirmationPayload['delivery_method'] ?? 'ATM',
            'beneficiary_phone' => $confirmationPayload['beneficiary_phone'] ?? null,
            'beneficiary_identifier' => $confirmationPayload['beneficiary_identifier'] ?? null,
            'client_phone' => $confirmationPayload['client_phone'] ?? null,
            '_skip_hold' => true,
        ];
        
        $fieldsToCopy = ['_is_hooked', 'access_token', 'source_reference', 'wallet_pin', 'pin'];
        foreach ($fieldsToCopy as $field) {
            if (isset($sourcePayload[$field])) {
                $cashoutPayload[$field] = $sourcePayload[$field];
            }
        }
        
        $cashoutPayload['_identity_confirmed'] = true;
        $cashoutPayload['_identity_type'] = $identitySwap['identity_type'];
        $cashoutPayload['_identity_value'] = $identitySwap['identity_value'];
        $cashoutPayload['_confirmed_by_type'] = $confirmationPayload['confirmed_by_type'] ?? 'user';
        $cashoutPayload['_confirmed_by_id'] = $confirmationPayload['confirmed_by_id'] ?? 0;
        
        return $this->executeSignedCashout($cashoutPayload);
    }

    private function completeIdentitySwapAsDeposit(array $sourcePayload, array $identitySwap, array $confirmationPayload): array
    {
        $sourceInstitution = $identitySwap['source_institution'];
        $destinationInstitution = $confirmationPayload['destination_institution'] ?? 'BANK';
        $destinationAssetType = $this->extractDestinationAssetType($confirmationPayload);
        $destIdentifier = $confirmationPayload['destination_identifier'] ?? null;
        $destIdentifierType = $confirmationPayload['destination_identifier_type'] ?? 'account';
        
        error_log("[SwapService] completeIdentitySwapAsDeposit: dest={$destinationInstitution}, identifier={$destIdentifier}, asset_type={$destinationAssetType}");
        
        $depositPayload = [
            'swap_type' => 'DEPOSIT',
            'reference' => $identitySwap['swap_reference'],
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'source_identifier' => $identitySwap['source_identifier'],
            'asset_type' => $identitySwap['source_asset_type'] ?? 'ACCOUNT',
            'amount' => (float)$identitySwap['amount'],
            'currency' => $identitySwap['currency'] ?? 'BWP',
            'to_institution' => $destinationInstitution,
            'destination_institution' => $destinationInstitution,
            'destination_identifier' => $destIdentifier,
            'destination_identifier_type' => $destIdentifierType,
            'destination_asset_type' => $destinationAssetType,
            'asset_type' => $destinationAssetType,
            '_skip_hold' => true,
        ];
        
        if ($destinationAssetType === 'ACCOUNT') {
            $depositPayload['destination_account'] = $destIdentifier;
            $depositPayload['account_number'] = $destIdentifier;
            error_log("[SwapService] Identity deposit to ACCOUNT: {$destIdentifier}");
        } else {
            $depositPayload['beneficiary_phone'] = $destIdentifier;
            $depositPayload['phone'] = $destIdentifier;
            $depositPayload['wallet_phone'] = $destIdentifier;
            error_log("[SwapService] Identity deposit to WALLET: {$destIdentifier}");
        }
        
        $fieldsToCopy = ['_is_hooked', 'access_token', 'source_reference', 'wallet_pin', 'pin', 'account_name', 'bank_code', 'branch_code'];
        foreach ($fieldsToCopy as $field) {
            if (isset($sourcePayload[$field])) {
                $depositPayload[$field] = $sourcePayload[$field];
            }
            if (isset($confirmationPayload[$field])) {
                $depositPayload[$field] = $confirmationPayload[$field];
            }
        }
        
        $depositPayload['_identity_confirmed'] = true;
        $depositPayload['_identity_type'] = $identitySwap['identity_type'];
        $depositPayload['_identity_value'] = $identitySwap['identity_value'];
        $depositPayload['_confirmed_by_type'] = $confirmationPayload['confirmed_by_type'] ?? 'user';
        $depositPayload['_confirmed_by_id'] = $confirmationPayload['confirmed_by_id'] ?? 0;
        
$result = $this->executeSignedDeposit($depositPayload);
 
// Money has now genuinely landed in the destination account and the
// source hold is fully closed - this is the only point where
// VouchMorph can still create a tracking record for "how much of
// this account's new balance is earmarked identity money."
if (($result['status'] ?? null) === 'success') {
    $this->createEarmarkedBalance(
        (int)$identitySwap['hold_id'],
        $destinationInstitution,
        (string)$destIdentifier,
        $destIdentifierType,
        (float)$identitySwap['amount'],
        $identitySwap['currency'] ?? 'BWP'
    );
}
 
return $result;
 
    }

    // ============================================================================
    // PUBLIC IDENTITY SWAP METHODS
    // ============================================================================

    public function getIdentitySwapByReference(string $swapReference): ?array
    {
        $sql = "
            SELECT 
                h.*,
                CASE 
                    WHEN h.hold_expires_at < NOW() THEN 'expired'
                    ELSE h.status
                END as current_status
            FROM identity_swap_holds h
            WHERE h.swap_reference = :swap_ref
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':swap_ref' => $swapReference]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to get identity swap: " . $e->getMessage());
            return null;
        }
    }

    public function getPendingIdentitySwaps(string $identityType, string $identityValue, string $status = 'pending'): array
    {
        $sql = "
            SELECT 
                h.hold_id,
                h.swap_reference,
                h.amount,
                h.currency,
                h.identity_type,
                h.identity_value,
                h.hold_expires_at,
                h.status,
                h.created_at,
                h.source_institution,
                h.source_identifier,
                h.metadata,
                ht.status as hold_status,
                CASE 
                    WHEN h.hold_expires_at < NOW() THEN 'expired'
                    ELSE h.status
                END as current_status
            FROM identity_swap_holds h
            LEFT JOIN hold_transactions ht ON h.hold_id = ht.hold_id
            WHERE h.identity_type = :identity_type
                AND h.identity_value = :identity_value
        ";
        
        if ($status !== 'all') {
            $sql .= " AND h.status = :status AND h.hold_expires_at > NOW()";
        }
        
        $sql .= " ORDER BY h.created_at DESC";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $params = [
                ':identity_type' => $identityType,
                ':identity_value' => $identityValue
            ];
            if ($status !== 'all') {
                $params[':status'] = $status;
            }
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to get pending identity swaps: " . $e->getMessage());
            throw new RuntimeException("Failed to get pending identity swaps: " . $e->getMessage());
        }
    }

    public function getAgentPendingSwaps(int $agentId, array $filters = []): array
    {
        $sql = "
            SELECT 
                h.hold_id,
                h.swap_reference,
                h.amount,
                h.currency,
                h.identity_type,
                h.identity_value,
                h.hold_expires_at,
                h.status,
                h.created_at,
                h.source_institution,
                h.source_identifier,
                h.metadata,
                ht.status as hold_status,
                CASE 
                    WHEN h.hold_expires_at < NOW() THEN 'expired'
                    ELSE h.status
                END as current_status
            FROM identity_swap_holds h
            LEFT JOIN hold_transactions ht ON h.hold_id = ht.hold_id
            WHERE h.identity_type = 'national_id'
            AND h.status = 'pending'
            AND h.hold_expires_at > NOW()
        ";
        
        if (!empty($filters['search'])) {
            $sql .= " AND (h.identity_value LIKE :search OR h.swap_reference LIKE :search)";
        }
        
        $sql .= " ORDER BY h.created_at DESC LIMIT 100";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $params = [];
            if (!empty($filters['search'])) {
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to get agent pending swaps: " . $e->getMessage());
            throw new RuntimeException("Failed to get agent pending swaps: " . $e->getMessage());
        }
    }

/**
 * PATCH FOR: src/Domain/Services/SwapService.php
 * (continuation - apply after SwapService_hold_release_patch.php)
 * =================================================================
 */
 
 
/* =================================================================
 * EDIT 1 — ADD these new methods anywhere in the class (e.g. near
 * the identity swap helper methods).
 * ================================================================= */
 
/**
 * Called once identity-swap money has actually landed in a
 * destination account (after a successful DEPOSIT finalization).
 * Creates VouchMorph's own tracking ledger for that earmarked
 * balance, since no bank-side hold exists anymore to consult.
 */
private function createEarmarkedBalance(
    int $identitySwapHoldId,
    string $destInstitution,
    string $destIdentifier,
    string $destIdentifierType,
    float $amount,
    string $currency
): int {
    $denominations = $this->atmNotes[$currency] ?? [200, 100, 50, 20, 10];
    $smallestNote = min($denominations);
    $totalCashoutFee = (float)($this->feesConfig['CASHOUT']['fee_components']['F1']['amount'] ?? 0);
 
    $sql = "
        INSERT INTO identity_earmarked_balances (
            identity_swap_hold_id, destination_institution, destination_identifier,
            destination_identifier_type, currency, original_amount,
            withdrawn_amount, remaining_amount, smallest_note_amount,
            total_cashout_fee_amount, status
        ) VALUES (
            :hold_id, :institution, :identifier,
            :identifier_type, :currency, :amount,
            0, :amount, :smallest_note,
            :total_fee, 'open'
        ) RETURNING id
    ";
 
    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':hold_id' => $identitySwapHoldId,
            ':institution' => $destInstitution,
            ':identifier' => $destIdentifier,
            ':identifier_type' => $destIdentifierType,
            ':currency' => $currency,
            ':amount' => $amount,
            ':smallest_note' => $smallestNote,
            ':total_fee' => $totalCashoutFee,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $row ? (int)$row['id'] : 0;
 
        error_log("[SwapService] Earmarked balance created: id={$id}, {$destInstitution}/{$destIdentifier}, amount={$amount} {$currency}, threshold=" . ($smallestNote + $totalCashoutFee));
 
        return $id;
    } catch (PDOException $e) {
        error_log("[SwapService] Failed to create earmarked balance: " . $e->getMessage());
        // Non-fatal by design: the deposit itself already succeeded and
        // real money already moved. Failing to create the tracking
        // ledger shouldn't roll back a completed deposit - but it does
        // mean this account's earmarked money goes untracked, which
        // needs manual reconciliation. Logged loudly for that reason.
        return 0;
    }
}
 
/**
 * Aggregates all OPEN earmarked balances for a given account into a
 * single figure to check a withdrawal against. Multiple identity
 * deposits into the same account (over time) are summed; the most
 * conservative (largest) threshold among them is used, so a
 * withdrawal can never slip through on a looser number from an
 * older entry.
 */
private function getOpenEarmarkedSummary(string $institution, string $identifier): ?array
{
    $stmt = $this->swapDB->prepare("
        SELECT id, remaining_amount, smallest_note_amount, total_cashout_fee_amount
        FROM identity_earmarked_balances
        WHERE destination_institution = :institution
        AND destination_identifier = :identifier
        AND status = 'open'
        ORDER BY created_at ASC
    ");
    $stmt->execute([':institution' => $institution, ':identifier' => $identifier]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
    if (empty($rows)) {
        return null;
    }
 
    $totalRemaining = array_sum(array_column($rows, 'remaining_amount'));
    $maxThreshold = 0.0;
    foreach ($rows as $r) {
        $threshold = (float)$r['smallest_note_amount'] + (float)$r['total_cashout_fee_amount'];
        $maxThreshold = max($maxThreshold, $threshold);
    }
 
    return [
        'total_remaining' => (float)$totalRemaining,
        'threshold' => $maxThreshold,
        'entries' => $rows, // ordered oldest-first for FIFO consumption
    ];
}
 
/**
 * Enforces the partial-withdrawal rule against any open earmarked
 * balance on this account: a withdrawal is only valid if it leaves
 * the earmarked remainder at exactly zero, or strictly above
 * (smallest note + total cashout fee). Does NOT mutate anything -
 * pure validation, safe to call before a hold is placed. Silently
 * returns (no-op) if the account has no open earmarked balance at
 * all - ordinary funds are never restricted by this rule.
 */
private function validateEarmarkedWithdrawal(string $institution, string $identifier, float $requestedAmount): void
{
    $summary = $this->getOpenEarmarkedSummary($institution, $identifier);
    if ($summary === null) {
        return; // No earmarked money on this account - unrestricted.
    }
 
    $remaining = $summary['total_remaining'];
    $threshold = $summary['threshold'];
 
    if ($requestedAmount >= $remaining) {
        // Fully consumes (or exceeds, if mixed with the account's own
        // funds) the earmarked balance - always allowed, since the
        // earmarked portion hits exactly zero.
        return;
    }
 
    $newRemaining = $remaining - $requestedAmount;
    if ($newRemaining > 0 && $newRemaining <= $threshold) {
        throw new RuntimeException(
            "This withdrawal would leave {$newRemaining} of earmarked identity money in this account, " .
            "which is too small to usefully redeem later (minimum note {$threshold} " .
            "including cashout fee). Withdraw the full remaining balance ({$remaining}) " .
            "instead, or leave enough that more than {$threshold} remains."
        );
    }
}

private function isAgentAccount(string $institution, string $identifier): bool
{
    try {
        $stmt = $this->swapDB->prepare("
            SELECT 1 FROM agent_destination_accounts
            WHERE institution = :institution AND identifier = :identifier
            AND status = 'active' AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':institution' => $institution, ':identifier' => $identifier]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("[SwapService] isAgentAccount check failed: " . $e->getMessage());
        return false;
    }
}

private function validateAgentMinimumBalance(string $institution, string $identifier, float $amountBeingDebited): void
{
    if (!$this->isAgentAccount($institution, $identifier)) {
        return;
    }

    $currentBalance = $this->getSourceAvailableBalance([
        'institution' => $institution,
        'identifier' => $identifier,
        'asset_type' => 'ACCOUNT',
    ]);

    $resultingBalance = round($currentBalance - $amountBeingDebited, 2);

    if ($resultingBalance < 1.00) {
        throw new RuntimeException(
            "This would leave your agent account at {$resultingBalance} {$this->config['currency']}, below the required minimum of 1.00. " .
            "Please deposit funds into your agent account before completing this transaction."
        );
    }
}
    
/**
 * Actually decrements the earmarked ledger, FIFO across open entries,
 * after a withdrawal has genuinely succeeded (called post-debit, never
 * pre-emptively - a validated-but-failed cashout must not consume the
 * ledger). Marks entries 'depleted' once their remaining hits zero.
 */
private function consumeEarmarkedBalance(string $institution, string $identifier, float $amountWithdrawn, ?string $swapReference = null): void
{
    $summary = $this->getOpenEarmarkedSummary($institution, $identifier);
    if ($summary === null) {
        return; // Nothing earmarked on this account - ordinary withdrawal, nothing to track.
    }
 
    $remainingToConsume = $amountWithdrawn;
 
    foreach ($summary['entries'] as $entry) {
        if ($remainingToConsume <= 0) {
            break;
        }
 
        $entryId = (int)$entry['id'];
        $entryRemaining = (float)$entry['remaining_amount'];
        $consumeFromThisEntry = min($entryRemaining, $remainingToConsume);
        $newEntryRemaining = round($entryRemaining - $consumeFromThisEntry, 2);
        $newStatus = $newEntryRemaining <= 0.005 ? 'depleted' : 'open';
 
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE identity_earmarked_balances
                SET withdrawn_amount = withdrawn_amount + :consumed,
                    remaining_amount = :new_remaining,
                    status = :status,
                    depleted_at = CASE WHEN :status = 'depleted' THEN NOW() ELSE depleted_at END,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':consumed' => $consumeFromThisEntry,
                ':new_remaining' => max(0, $newEntryRemaining),
                ':status' => $newStatus,
                ':id' => $entryId,
            ]);
 
            $auditStmt = $this->swapDB->prepare("
                INSERT INTO identity_earmarked_withdrawals
                (earmarked_balance_id, swap_reference, amount, remaining_after)
                VALUES (:balance_id, :swap_ref, :amount, :remaining)
            ");
            $auditStmt->execute([
                ':balance_id' => $entryId,
                ':swap_ref' => $swapReference,
                ':amount' => $consumeFromThisEntry,
                ':remaining' => max(0, $newEntryRemaining),
            ]);
 
            error_log("[SwapService] Earmarked balance {$entryId} consumed {$consumeFromThisEntry}, remaining {$newEntryRemaining}, status {$newStatus}");
 
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to consume earmarked balance {$entryId}: " . $e->getMessage());
        }
 
        $remainingToConsume -= $consumeFromThisEntry;
    }
 
    if ($remainingToConsume > 0.005) {
        error_log("[SwapService] WARNING: withdrew {$amountWithdrawn} from {$institution}/{$identifier} but only {$summary['total_remaining']} was earmarked - {$remainingToConsume} came from the account's own funds, which is expected and fine.");
    }
}

    
     
public function cancelExpiredIdentitySwaps(): array
{
    error_log("[SwapService] ===== cancelExpiredIdentitySwaps =====");
 
    $results = ['total_expired' => 0, 'cancelled' => 0, 'errors' => 0, 'details' => []];
 
    $sql = "
        SELECT * FROM identity_swap_holds
        WHERE status = 'pending'
        AND hold_expires_at < NOW()
    ";
 
    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute();
        $expiredSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['total_expired'] = count($expiredSwaps);
 
        foreach ($expiredSwaps as $swap) {
            try {
                $levy = (float)($swap['levy_amount'] ?? 0);
                $sourceInstitution = $swap['source_institution'];
                $swapRef = $swap['swap_reference'];
 
                if ($levy > 0) {
                    try {
                        $this->settlement->invoiceFee(
                            $swapRef,
                            $sourceInstitution,
                            $this->getParticipantId('VOUCHMORPH'),
                            'SWAP_LEVY',
                            $levy,
                            $swap['currency'] ?? 'BWP'
                        );
 
                        $adapter = $this->adapterFactory->getAdapter($sourceInstitution);
                        $adapter->debit([
                            'reference' => $swapRef . '_EXPIRED_LEVY',
                            'hold_reference' => $swap['hold_reference'],
                            'amount' => $levy,
                            'reason' => 'Identity swap expired unclaimed - withholding non-refundable levy',
                            'from_institution' => $sourceInstitution,
                            'source_institution' => $sourceInstitution,
                        ], []);
                    } catch (Exception $e) {
                        error_log("[SwapService] Failed to withhold levy on expired identity swap {$swapRef}: " . $e->getMessage());
                    }
                }
 
                $adapter = $this->adapterFactory->getAdapter($sourceInstitution);
                $releaseResult = $adapter->releaseHold([
                    'hold_reference' => $swap['hold_reference'],
                    'action' => 'RELEASE_HOLD',
                    'reason' => "Identity swap expired after 24 hours. Withheld levy: {$levy}."
                ], []);
 
                $this->updateIdentityHoldStatus($swap['hold_id'], 'expired', [
                    'release_result' => $releaseResult,
                    'levy_withheld' => $levy,
                    'expired_at' => date('Y-m-d H:i:s')
                ]);
 
                $this->updateHoldStatus($swap['hold_id'], $levy > 0 ? 'PARTIALLY_RELEASED' : 'RELEASED');
 
                $results['cancelled']++;
                $results['details'][] = [
                    'swap_reference' => $swap['swap_reference'],
                    'hold_id' => $swap['hold_id'],
                    'levy_withheld' => $levy,
                    'status' => 'expired'
                ];
 
            } catch (Exception $e) {
                error_log("[SwapService] Failed to cancel swap {$swap['swap_reference']}: " . $e->getMessage());
                $results['errors']++;
                $results['details'][] = [
                    'swap_reference' => $swap['swap_reference'],
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
 
        return $results;
 
    } catch (PDOException $e) {
        error_log("[SwapService] Failed to get expired swaps: " . $e->getMessage());
        throw new RuntimeException("Failed to cancel expired swaps: " . $e->getMessage());
    }
}


    // ============================================================================
    // EXECUTE VERIFY CASHOUT & CONFIRM CASHOUT
    // ============================================================================

    public function verifyCashout(array $payload): array
    {
        error_log("[SwapService] ===== verifyCashout START =====");
        
        $code = $payload['code'] ?? null;
        $swapCode = $payload['swap_code'] ?? null;
        $authId = $payload['auth_id'] ?? null;
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        $cashoutPoint = $payload['cashout_point'] ?? 'ATM';
        
        if (!$code && !$swapCode && !$authId) {
            throw new RuntimeException("Code, swap_code, or auth_id required");
        }
        
        $authorization = null;
        if ($authId) {
            $authorization = $this->getCashoutAuthorization(null, $authId);
        } elseif ($swapCode) {
            $authorization = $this->getCashoutAuthorization($swapCode, null);
        }
        
        if ($authorization && $authorization['pin_code'] === $code) {
            $this->updateCashoutAuthorizationStatus($authorization['auth_id'], 'VERIFIED', $cashoutPoint);
            
            return [
                'status' => 'verified',
                'verified' => true,
                'auth_id' => $authorization['auth_id'],
                'amount' => (float)$authorization['amount'],
                'swap_reference' => $authorization['swap_reference'],
                'message' => 'Code verified successfully'
            ];
        }
        
        if (!$destinationInstitution) {
            throw new RuntimeException("Destination institution required for verification");
        }
        
        $verifyPayload = [
            'reference' => $payload['reference'] ?? $this->generateReference(),
            'code' => $code,
            'swap_code' => $swapCode,
            'action' => 'VERIFY_TOKEN',
            'to_institution' => $destinationInstitution,
            'destination_institution' => $destinationInstitution
        ];
        
        $adapter = $this->adapterFactory->getAdapter($destinationInstitution);
        $result = $adapter->verifyCashoutToken($verifyPayload, [
            'swap_reference' => $swapCode ?? $authId ?? null,
            'destination_institution' => $destinationInstitution,
            'cashout_point' => $cashoutPoint
        ]);
        
        return [
            'status' => 'verified',
            'verified' => $result['verified'] ?? false,
            'amount' => $result['amount'] ?? 0,
            'message' => $result['message'] ?? 'Code verified successfully'
        ];
    }

    // ============================================================================
    // CONFIRM CASHOUT - UPDATED WITH SECURE VERSION
    // ============================================================================

    /**
     * Confirm cashout - Handles both destination bank verification AND ATM callbacks
     * 
     * SCENARIO 1: Manual/Agent confirmation (is_callback=false)
     *   - Verifies with destination institution before processing
     *   - Uses BankAPIInterface::confirmCashout() to confirm with destination bank
     * 
     * SCENARIO 2: ATM Callback (is_callback=true)
     *   - Called by atm_cashout_voucher.php when bank calls Vouchmorph
     *   - Skips destination verification (ATM already verified)
     *   - Debits source hold immediately
     * 
     * SECURITY: Amount, source_institution, hold_reference are pulled from
     * OUR OWN stored authorization record via findAuthorization(),
     * NOT from the bank's webhook body. The webhook only tells us WHICH
     * voucher/swap to confirm, never HOW MUCH to debit.
     */
    public function confirmCashout(array $payload): array
    {
        error_log("[SwapService] ===== confirmCashout START =====");
        error_log("[SwapService] Payload keys: " . implode(', ', array_keys($payload)));

        // ============================================================
        // 1. EXTRACT PAYLOAD - ONLY IDENTIFIERS, NOT MONEY AMOUNTS
        // ============================================================
        $swapReference = $payload['swap_reference'] ?? null;
        $authId = $payload['auth_id'] ?? null;
        $code = $payload['code'] ?? null;
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'] ?? null;
        $cashoutPoint = $payload['cashout_point'] ?? 'ATM';

        // Callback-specific fields (identifiers only — NOT money amounts)
        $voucherNumber = $payload['voucher_number'] ?? null;
        $atmId = $payload['atm_id'] ?? null;
        $cashoutReference = $payload['cashout_reference'] ?? null;
        $requester = $payload['requester'] ?? 'SYSTEM';
        $isCallback = $payload['is_callback'] ?? false;

        // Override fields — trusted-internal-caller-only.
        // The webhook controller must NOT populate these from the raw bank payload.
        $sourceInstitutionOverride = $payload['_source_institution'] ?? null;
        $amountOverride = $payload['_amount'] ?? null;
        $feeOverride = $payload['_fee_amount'] ?? null;
        $holdReferenceOverride = $payload['_hold_reference'] ?? null;
        $userIdOverride = $payload['_user_id'] ?? null;

        if (!$swapReference && !$authId && !$voucherNumber) {
            throw new RuntimeException("Swap reference, auth_id, or voucher_number required");
        }

        if ($isCallback) {
            $mode = 'ATM_CALLBACK';
            error_log("[SwapService] Mode: ATM CALLBACK - voucher={$voucherNumber}, atm={$atmId}");
        } else {
            $mode = 'MANUAL_VERIFICATION';
            error_log("[SwapService] Mode: MANUAL VERIFICATION - dest={$destinationInstitution}");
        }

        // ============================================================
        // 2. FIND AUTHORIZATION — source of truth for amount/institution
        // ============================================================
        $authorization = $this->findAuthorization($swapReference, $authId, $voucherNumber);

        if (!$authorization) {
            throw new RuntimeException("No pending cashout authorization found");
        }

        $authId = $authorization['auth_id'];
        $swapRef = $authorization['swap_reference'];

        // Overrides only apply if explicitly passed by a TRUSTED INTERNAL
        // caller that has already validated them — the webhook controller
        // deliberately never sets these.
        $sourceInstitution = $sourceInstitutionOverride ?? $authorization['source_institution'];
        $destinationInstitution = $destinationInstitution ?? $authorization['destination_institution'] ?? 'ATM';
        $amountToSend = $amountOverride ?? (float)$authorization['amount'];
        $feeAmount = $feeOverride ?? (float)($authorization['fee_amount'] ?? 0);
        $currency = $authorization['currency'] ?? 'BWP';
        $userId = $userIdOverride ?? $authorization['user_id'];
        $holdReference = $holdReferenceOverride ?? $authorization['hold_reference'] ?? $swapRef;

        error_log("[SwapService] Auth: id={$authId}, swap={$swapRef}, source={$sourceInstitution}, amount={$amountToSend}");

        // ============================================================
        // 3. IDEMPOTENCY — webhook retries must not double-debit
        // ============================================================
        if ($authorization['status'] === 'COMPLETED') {
            error_log("[SwapService] Cashout already completed");
            return [
                'status' => 'already_completed',
                'swap_reference' => $swapRef,
                'auth_id' => $authId,
                'message' => 'Cashout was already completed',
                'amount' => $amountToSend
            ];
        }

        // ============================================================
        // 4. DESTINATION VERIFICATION (MANUAL MODE ONLY)
        // ============================================================
        if (!$isCallback) {
            if (!$destinationInstitution) {
                throw new RuntimeException("Destination institution required for manual confirmation");
            }

            error_log("[SwapService] Verifying with destination: {$destinationInstitution}");

            $confirmPayload = [
                'reference' => $swapRef,
                'auth_id' => $authId,
                'code' => $code,
                'swap_code' => $authorization['swap_code'],
                'amount' => $amountToSend,
                'action' => 'CONFIRM_CASHOUT',
                'to_institution' => $destinationInstitution,
                'destination_institution' => $destinationInstitution,
                'from_institution' => $sourceInstitution,
                'source_institution' => $sourceInstitution
            ];

            try {
                $destAdapter = $this->adapterFactory->getAdapter($destinationInstitution);
                $confirmResult = $destAdapter->confirmCashout($confirmPayload, [
                    'swap_reference' => $swapRef,
                    'auth_id' => $authId,
                    'source_institution' => $sourceInstitution,
                    'destination_institution' => $destinationInstitution,
                    'amount' => $amountToSend,
                    'cashout_point' => $cashoutPoint
                ]);

                if (!($confirmResult['confirmed'] ?? false)) {
                    throw new RuntimeException("Cashout not confirmed by destination: " . ($confirmResult['message'] ?? 'Unknown'));
                }

                error_log("[SwapService] Destination confirmed");

            } catch (Exception $e) {
                error_log("[SwapService] Destination verification failed: " . $e->getMessage());
                throw new RuntimeException("Destination verification failed: " . $e->getMessage());
            }
        } else {
            // ATM/bank callback — cash is already dispensed, nothing to verify.
            // "destination_institution" here is 'ATM'/'AGENT', not a
            // BankAPIInterface participant, so we never call an adapter.
            error_log("[SwapService] ATM Callback - skipping destination verification (cash already dispensed)");

            if ($voucherNumber) {
                try {
                    $stmt = $this->swapDB->prepare("
                        UPDATE instant_money_vouchers
                        SET status = 'confirmed',
                            redeemed_at = COALESCE(redeemed_at, NOW()),
                            redeemed_by = COALESCE(redeemed_by, :requester)
                        WHERE voucher_number = :voucher_number
                        AND status IN ('redeemed', 'active', 'hold', 'pending')
                    ");
                    $stmt->execute([
                        ':voucher_number' => $voucherNumber,
                        ':requester' => $requester
                    ]);
                    error_log("[SwapService] Voucher confirmed: {$voucherNumber}");
                } catch (Exception $e) {
                    error_log("[SwapService] Voucher update warning: " . $e->getMessage());
                }
            }
        }

        // ============================================================
        // 5. DEBIT SOURCE HOLD — amount always comes from OUR record
        // ============================================================
        error_log("[SwapService] Debiting source: {$sourceInstitution}, amount: " . ($amountToSend + $feeAmount));

        try {
            $sourceAdapter = $this->adapterFactory->getAdapter($sourceInstitution);
        } catch (Exception $e) {
            throw new RuntimeException("Failed to get source adapter: " . $e->getMessage());
        }

        $debitPayload = [
            'reference' => $swapRef,
            'hold_reference' => $holdReference,
            'amount' => $amountToSend + $feeAmount,
            'reason' => 'Cashout confirmed: ' . $mode,
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'user_id' => $userId,
            'action' => 'DEBIT_FUNDS'
        ];

    $debitResult = $sourceAdapter->debit($debitPayload, []);
        
        $debitSuccess = ($debitResult['success'] ?? false) || ($debitResult['debited'] ?? false);
if (!$debitSuccess) {
    $errorMsg = $debitResult['data']['message'] ?? $debitResult['message'] ?? 'Unknown';
    error_log("[SwapService] Debit failed: " . $errorMsg);
    $this->updateCashoutAuthorizationStatus($authId, 'DEBIT_FAILED', $cashoutPoint);
    throw new RuntimeException("Debit failed: " . $errorMsg);
}

        error_log("[SwapService] Debit successful");

         
error_log("[SwapService] Debit successful");
 
// Consume the earmarked ledger now that money has actually, 
// successfully left the account - never before this point.
try {
    $sourceIdentifierForLedger = $authorization['source_identifier'] ?? null;
if ($sourceIdentifierForLedger) {
    $this->consumeEarmarkedBalance($sourceInstitution, $sourceIdentifierForLedger, $amountToSend + $feeAmount, $swapRef);
} else {
    // Older authorizations created before this fix will have no
    // source_identifier on record - log it so it's visible in
    // reconciliation rather than silently skipping ledger consumption.
    error_log("[SwapService] No source_identifier on cashout_authorizations for auth_id={$authId} (swap_ref={$swapRef}) - cannot consume earmarked balance, likely a pre-migration record.");
}

} catch (Exception $e) {
    error_log("[SwapService] Non-fatal: failed to consume earmarked balance after successful debit: " . $e->getMessage());
}
 


        // ============================================================
        // 6. UPDATE STATUSES
        // ============================================================
        $this->updateCashoutAuthorizationStatus($authId, 'COMPLETED', $cashoutPoint);
        $this->updateHoldForSwap($swapRef, 'DEBITED');
        $this->updateSwapRequestStatus($swapRef, 'completed');

        // ============================================================
        // 7. SETTLEMENT
        // ============================================================
        $settlementResult = null;
        try {
            $settlementResult = $this->settlement->updateNetPosition(
                $swapRef,
                $sourceInstitution,
                $destinationInstitution,
                $amountToSend,
                'CASHOUT_COMPLETED',
                $currency
            );

            if ($feeAmount > 0) {
                $this->settlement->invoiceFee(
                    $swapRef,
                    $sourceInstitution,
                    $this->getParticipantId($sourceInstitution),
                    'CASHOUT_COMPLETION_FEE',
                    $feeAmount,
                    $currency
                );
            }
        } catch (Exception $e) {
            error_log("[SwapService] Settlement warning: " . $e->getMessage());
        }

        // ============================================================
        // 8. AUDIT LOG
        // ============================================================
        try {
            $auditStmt = $this->swapDB->prepare("
                INSERT INTO audit_logs
                (entity_type, entity_id, action, category, severity, performed_by, metadata, performed_at)
                VALUES
                ('cashout_authorizations', :auth_id, 'CASHOUT_CONFIRMED', 'financial', 'info', :performed_by, :metadata, NOW())
            ");
            $auditStmt->execute([
                ':auth_id' => $authId,
                ':performed_by' => $isCallback ? $requester : ($payload['performed_by'] ?? 'SYSTEM'),
                ':metadata' => json_encode([
                    'mode' => $mode,
                    'voucher_number' => $voucherNumber ?? $authorization['swap_code'],
                    'amount' => $amountToSend,
                    'atm_id' => $atmId,
                    'cashout_reference' => $cashoutReference,
                    'swap_reference' => $swapRef,
                    'source_institution' => $sourceInstitution,
                    'destination_institution' => $destinationInstitution,
                    'user_id' => $userId
                ])
            ]);
        } catch (Exception $e) {
            error_log("[SwapService] Audit log warning: " . $e->getMessage());
        }

        // ============================================================
        // 9. RESPONSE
        // ============================================================
        $response = [
            'status' => 'completed',
            'swap_reference' => $swapRef,
            'auth_id' => $authId,
            'amount' => $amountToSend,
            'fee' => $feeAmount,
            'currency' => $currency,
            'confirmed_by' => $mode,
            'debit_result' => $debitResult,
            'settlement' => $settlementResult,
            'message' => 'Cashout completed successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if ($isCallback) {
            $response['voucher_number'] = $voucherNumber;
            $response['atm_id'] = $atmId;
            $response['cashout_reference'] = $cashoutReference;
            $response['requester'] = $requester;
        }

        error_log("[SwapService] Cashout completed - Swap: {$swapRef}, Mode: {$mode}");

        return $response;
    }


 /**
 * Single-identity aggregate: what an agent sees after searching one
 * national_id. Sums all pending, non-expired holds for that identity
 * into one figure. Refuses to mix currencies rather than guessing.
 */
public function getAggregatedIdentityBalance(string $identityType, string $identityValue): ?array
{
    $sql = "
        SELECT identity_type, identity_value, currency,
               SUM(amount) AS total_amount,
               COUNT(*) AS swap_count,
               json_agg(hold_id ORDER BY created_at) AS hold_ids_json,
               json_agg(swap_reference ORDER BY created_at) AS swap_refs_json,
               MAX(created_at) AS newest_created_at,
               MIN(hold_expires_at) AS earliest_expires_at
        FROM identity_swap_holds
        WHERE identity_type = :identity_type
          AND identity_value = :identity_value
          AND status = 'pending'
          AND hold_expires_at > NOW()
        GROUP BY identity_type, identity_value, currency
    ";

    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':identity_type' => $identityType, ':identity_value' => $identityValue]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return null;
        }

        if (count($rows) > 1) {
            // Multiple currencies pending on the same identity - deliberately
            // NOT merged or converted. Surface all of them so the caller can
            // decide, rather than silently picking one or averaging.
            error_log("[SwapService] Identity {$identityType}={$identityValue} has pending balances in " . count($rows) . " different currencies");
            return [
                'identity_type' => $identityType,
                'identity_value' => $identityValue,
                'multi_currency' => true,
                'balances' => array_map(function ($row) {
                    $row['hold_ids'] = json_decode($row['hold_ids_json'], true);
                    unset($row['hold_ids_json'], $row['swap_refs_json']);
                    return $row;
                }, $rows)
            ];
        }

        $row = $rows[0];
        $row['hold_ids'] = json_decode($row['hold_ids_json'], true);
        $row['swap_references'] = json_decode($row['swap_refs_json'], true);
        unset($row['hold_ids_json'], $row['swap_refs_json']);
        $row['multi_currency'] = false;

        return $row;

    } catch (PDOException $e) {
        error_log("[SwapService] getAggregatedIdentityBalance failed: " . $e->getMessage());
        throw new RuntimeException("Failed to look up identity balance: " . $e->getMessage());
    }
}

/**
 * Browse/list version for the agent portal search results - one row
 * per identity (grouped), not one row per underlying swap.
 */
public function getAgentPendingSwapsAggregated(array $filters = []): array
{
    $sql = "
        SELECT identity_type, identity_value, currency,
               SUM(amount) AS total_amount,
               COUNT(*) AS swap_count,
               json_agg(hold_id ORDER BY created_at) AS hold_ids_json,
               MAX(created_at) AS newest_created_at,
               MIN(hold_expires_at) AS earliest_expires_at
        FROM identity_swap_holds
        WHERE identity_type = 'national_id'
          AND status = 'pending'
          AND hold_expires_at > NOW()
    ";
    $params = [];
    if (!empty($filters['search'])) {
        $sql .= " AND identity_value LIKE :search";
        $params[':search'] = '%' . $filters['search'] . '%';
    }
    $sql .= " GROUP BY identity_type, identity_value, currency ORDER BY newest_created_at DESC LIMIT 100";

    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['hold_ids'] = json_decode($row['hold_ids_json'], true);
            unset($row['hold_ids_json']);
        }
        return $rows;

    } catch (PDOException $e) {
        error_log("[SwapService] getAgentPendingSwapsAggregated failed: " . $e->getMessage());
        throw new RuntimeException("Failed to get aggregated pending swaps: " . $e->getMessage());
    }
}

/**
 * The core bundling operation. One PIN check (against the most
 * recently-issued PIN across all pending holds for this identity),
 * then N individual deposits into the agent's account - each with
 * its own fee calc and its own audit trail, same as if the agent had
 * claimed each source separately. Remainder (if any) re-swaps back to
 * the SAME identity as ONE fresh pending hold, not one per source.
 */
public function finalizeAggregatedIdentityClaim(
    string $identityType,
    string $identityValue,
    string $pin,
    string $confirmedByType,
    ?int $confirmedById,
    int $destinationAccountId,
    float $cashNowAmount,
    ?int $agentUserId = null
): array {
    $sql = "
        SELECT institution, identifier, identifier_type, asset_type
        FROM agent_destination_accounts
        WHERE id = :id AND status = 'active' AND deleted_at IS NULL
    ";
    $params = [':id' => $destinationAccountId];
    if ($agentUserId !== null) {
        $sql .= " AND user_id = :user_id";
        $params[':user_id'] = $agentUserId;
    }
    $stmt = $this->swapDB->prepare($sql);
    $stmt->execute($params);
    $destAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$destAccount) {
        throw new RuntimeException("Agent destination account not found, not active, or not owned by this agent.");
    }

    $stmt = $this->swapDB->prepare("
        SELECT * FROM identity_swap_holds
        WHERE identity_type = :identity_type AND identity_value = :identity_value
          AND status = 'pending' AND hold_expires_at > NOW()
        ORDER BY created_at ASC
    ");
    $stmt->execute([':identity_type' => $identityType, ':identity_value' => $identityValue]);
    $pendingHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendingHolds)) {
        throw new RuntimeException("No pending balance found for this identity.");
    }

    $currencies = array_unique(array_column($pendingHolds, 'currency'));
    if (count($currencies) > 1) {
        throw new RuntimeException(
            "This identity has pending balances in multiple currencies (" . implode(', ', $currencies) . "). " .
            "Claim each currency separately, or contact VouchMorph support."
        );
    }
    $currency = $currencies[0] ?? 'BWP';

    $fullAmount = round((float)array_sum(array_column($pendingHolds, 'amount')), 2);
    if ($cashNowAmount < 0 || $cashNowAmount > $fullAmount) {
        throw new RuntimeException("Requested cash amount must be between 0 and {$fullAmount}.");
    }

    // PIN check happens ONCE, against whichever hold most recently sent a
    // PIN (falls back to most recently created for account_pin claim types,
    // which never set otp_pin_sent_at).
    usort($pendingHolds, function ($a, $b) {
        $aKey = $a['otp_pin_sent_at'] ?? $a['created_at'];
        $bKey = $b['otp_pin_sent_at'] ?? $b['created_at'];
        return strtotime($bKey) <=> strtotime($aKey);
    });
    $latestHold = $pendingHolds[0];
    $this->verifyIdentityClaimPin($latestHold, $pin);
    // Re-sort back to chronological (oldest first) for the deposit loop -
    // purely cosmetic/FIFO ordering, not security-relevant.
    usort($pendingHolds, fn($a, $b) => strtotime($a['created_at']) <=> strtotime($b['created_at']));

    $beneficiaryPhone = $latestHold['otp_pin_sent_to'] ?? null;
    if (empty($beneficiaryPhone)) {
        $latestSourcePayload = json_decode($latestHold['source_payload'], true);
        $beneficiaryPhone = $latestSourcePayload['notification_phone'] ?? $latestSourcePayload['beneficiary_phone'] ?? null;
    }

    $depositResults = [];
    $failedHolds = [];
    $totalDepositedNet = 0.0;

    foreach ($pendingHolds as $hold) {
        $confirmationPayload = [
            'confirmed_by_type' => $confirmedByType,
            'confirmed_by_id' => $confirmedById,
            'identity_document_verified' => true,
            'destination_type' => 'DEPOSIT',
            'destination_institution' => $destAccount['institution'],
            'destination_identifier' => $destAccount['identifier'],
            'destination_identifier_type' => $destAccount['identifier_type'],
            'destination_asset_type' => $destAccount['asset_type'],
            'client_phone' => $beneficiaryPhone,
            'beneficiary_phone' => $beneficiaryPhone,
        ];

        try {
            $result = $this->finalizeIdentityHoldNoPin($hold, $confirmationPayload);
            $netAmount = $result['result']['amount'] ?? $hold['amount'];

            $depositResults[] = [
                'hold_id' => $hold['hold_id'],
                'swap_reference' => $hold['swap_reference'],
                'gross_amount' => (float)$hold['amount'],
                'net_deposited' => (float)$netAmount,
                'status' => 'completed',
            ];
            $totalDepositedNet += (float)$netAmount;

        } catch (Exception $e) {
            error_log("[SwapService] finalizeAggregatedIdentityClaim: hold {$hold['hold_id']} FAILED: " . $e->getMessage());
            $failedHolds[] = [
                'hold_id' => $hold['hold_id'],
                'swap_reference' => $hold['swap_reference'],
                'gross_amount' => (float)$hold['amount'],
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
            // A single source failing (e.g. its bank briefly unreachable)
            // doesn't block the others - it stays 'pending' and can be
            // retried on the next claim attempt.
        }
    }

    if (empty($depositResults)) {
        throw new RuntimeException("All underlying swaps failed to deposit - nothing was claimed. See individual errors and retry.");
    }

    $actuallyClaimedGross = round((float)array_sum(array_column($depositResults, 'gross_amount')), 2);
    $adjustedCashNow = min($cashNowAmount, $actuallyClaimedGross);
    $adjustedRemainder = round($actuallyClaimedGross - $adjustedCashNow, 2);

    $response = [
        'status' => empty($failedHolds) ? 'success' : 'partial_success',
        'identity_type' => $identityType,
        'identity_value' => $identityValue,
        'currency' => $currency,
        'requested_full_amount' => $fullAmount,
        'actually_claimed_gross' => $actuallyClaimedGross,
        'total_deposited_net' => round($totalDepositedNet, 2),
        'swap_count' => count($pendingHolds),
        'successful_deposits' => $depositResults,
        'failed_deposits' => $failedHolds,
        'cash_now_amount' => $adjustedCashNow,
        'remainder_reswap' => null,
    ];

    if ($adjustedRemainder > 0) {
        try {
            $result = $this->executeAtomicSwap([
                'swap_type' => 'IDENTITY',
                'reference' => 'AGG_REMAIN_' . time() . '_' . bin2hex(random_bytes(4)),
                'from_institution' => $destAccount['institution'],
                'source_institution' => $destAccount['institution'],
                'source_identifier' => $destAccount['identifier'],
                'source_identifier_type' => $destAccount['identifier_type'],
                'asset_type' => $destAccount['asset_type'],
                'amount' => $adjustedRemainder,
                'currency' => $currency,
                'identity_type' => $identityType,
                'identity_value' => $identityValue,
                'beneficiary_phone' => $beneficiaryPhone,
                'notification_phone' => $beneficiaryPhone,
            ]);
            $response['remainder_reswap'] = [
                'status' => 'completed',
                'amount' => $adjustedRemainder,
                'result' => $result,
            ];
        } catch (Exception $e) {
            error_log("[SwapService] finalizeAggregatedIdentityClaim: remainder reswap FAILED: " . $e->getMessage());
            $response['remainder_reswap'] = [
                'status' => 'failed',
                'amount' => $adjustedRemainder,
                'error' => $e->getMessage(),
            ];
            // Money already landed in the agent's account for the FULL
            // claimed amount at this point - a failed remainder reswap
            // here is a real, uncompensated loss to the client, not just
            // a log line. Flag it loudly for manual reconciliation.
            $response['status'] = 'partial_success';
            $response['requires_manual_reconciliation'] = true;
        }
    }

    return $response;
}   
// ============================================================================
// AGENT DESTINATION REGISTRATION METHODS
// ============================================================================

/**
 * Phase 1: Verify account + trigger OTP/OAuth, then create pending attempt
 * Does NOT create the agent_destination_accounts row yet
 */
/**
 * Phase 1: Verify account + trigger OTP/OAuth, then create pending attempt
 * Does NOT create the agent_destination_accounts row yet
 */
public function initiateAgentDestinationRegistration(
    int $userId,
    string $institution,
    string $assetType,
    string $identifier,
    string $identifierType,
    ?string $accountName = null
): array {
    error_log("[SwapService] initiateAgentDestinationRegistration: user={$userId}, institution={$institution}, identifier={$identifier}");

    $assetType = strtoupper(trim($assetType));
    $eligibleAssetTypes = ['ACCOUNT', 'WALLET', 'BANK-WALLET', 'CARD'];
    if (!in_array($assetType, $eligibleAssetTypes, true)) {
        throw new RuntimeException("Agent destinations must be Account, Wallet, or Card - '{$assetType}' is not eligible.");
    }

    // ============================================================
    // FIX: Auto-set identifier_type based on asset_type
    // This ensures the bank adapter knows what kind of identifier
    // it's looking at (phone for wallets, card_number for cards, etc.)
    // ============================================================
    $identifierType = match($assetType) {
        'WALLET', 'BANK-WALLET' => 'phone',        // Wallets use phone numbers
        'CARD' => 'card_number',                    // Cards use card numbers
        'ACCOUNT' => 'account_number',             // Accounts use account numbers
        default => 'account_number'
    };

    // Verify account exists and is business type
    $verifyPayload = [
        'action' => 'VERIFY_ACCOUNT',
        'reference' => 'AGENT_DEST_' . $userId . '_' . time(),
        'account_identifier' => $identifier,
        'identifier_type' => $identifierType,      // Now correctly set
        'requester' => 'VOUCHMORPH',
        'timestamp' => time(),
        'destination_asset_type' => $assetType,
    ];

    try {
        $adapter = $this->adapterFactory->getAdapter($institution);
        $verifyResult = $adapter->verifyAccount($verifyPayload, [
            'institution' => $institution,
            'purpose' => 'agent_destination_registration',
        ]);
    } catch (Exception $e) {
        error_log("[SwapService] Agent destination verification failed: " . $e->getMessage());
        throw new RuntimeException("Could not verify this account with {$institution}: " . $e->getMessage());
    }

    if (!($verifyResult['verified'] ?? false)) {
        throw new RuntimeException("Account not found or not verifiable at {$institution}: " . ($verifyResult['message'] ?? 'Unknown reason'));
    }

    $accountType = strtoupper($verifyResult['account_type'] ?? $verifyResult['data']['account_type'] ?? '');
    $eligibleTypes = ['BUSINESS', 'AGENT', 'MERCHANT'];

    if ($accountType === '') {
        throw new RuntimeException("{$institution} did not return an account type - contact VouchMorph support.");
    }
    if (!in_array($accountType, $eligibleTypes, true)) {
        throw new RuntimeException("This account is a {$accountType} account. Only business or agent-designated accounts are eligible.");
    }

    // Check for duplicates
    $stmt = $this->swapDB->prepare("
        SELECT id, status FROM agent_destination_accounts
        WHERE user_id = :user_id AND institution = :institution AND identifier = :identifier
        AND deleted_at IS NULL
    ");
    $stmt->execute([':user_id' => $userId, ':institution' => $institution, ':identifier' => $identifier]);
    if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException("You already have this account registered (status: {$existing['status']}).");
    }

    // Check for pending attempts
    $stmt = $this->swapDB->prepare("
        SELECT id FROM agent_registration_attempts
        WHERE user_id = :user_id AND institution = :institution AND identifier = :identifier
        AND status IN ('otp_pending', 'oauth_pending') AND otp_expires_at > NOW()
    ");
    $stmt->execute([':user_id' => $userId, ':institution' => $institution, ':identifier' => $identifier]);
    if ($stmt->fetch()) {
        throw new RuntimeException("A verification attempt is already pending for this account.");
    }

    // Trigger OAuth or OTP
    $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn.com', '/')
        . '/api/v1/agent/oauth_callback.php';

    $linkResult = null;
    try {
        $linkResult = $this->initiateSourceLink([
            'institution' => $institution,
            'identifier' => $identifier,
            'identifier_type' => $identifierType,  // Pass correct type
            'asset_type' => $assetType,
            'user_id' => $userId,
            'redirect_uri' => $callbackUrl,
        ]);
    } catch (Exception $e) {
        error_log("[SwapService] initiateSourceLink threw: " . $e->getMessage());
        $linkResult = ['success' => false, 'message' => $e->getMessage()];
    }

    $linkSucceeded = (bool)($linkResult['success'] ?? false);
    $isOauth = $linkSucceeded && ($linkResult['auth_type'] ?? null) === 'oauth';

    if (!$linkSucceeded) {
        // No OTP/OAuth support - register without ownership proof
        error_log("[SwapService] {$institution} has no OTP/OAuth support - registering without ownership proof");

        $id = $this->insertAgentDestinationAccount(
            $userId, $institution, $assetType, $identifier, $identifierType,
            $accountName ?? $verifyResult['account_name'] ?? null, $accountType,
            false, null, null, null
        );

        return [
            'requires_otp' => false,
            'requires_redirect' => false,
            'otp_supported' => false,
            'id' => $id,
            'status' => 'pending_confirmation',
            'account_type' => $accountType,
            'asset_type' => $assetType,
            'identifier_type' => $identifierType,
            'message' => "Registered without ownership verification - awaiting manual review.",
        ];
    }

    if ($isOauth) {
        // OAuth path - store attempt with state
        $stmt = $this->swapDB->prepare("
            INSERT INTO agent_registration_attempts (
                user_id, institution, asset_type, identifier, identifier_type,
                account_name, account_type, oauth_state, otp_supported, status
            ) VALUES (
                :user_id, :institution, :asset_type, :identifier, :identifier_type,
                :account_name, :account_type, :oauth_state, true, 'oauth_pending'
            ) RETURNING id
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':institution' => $institution,
            ':asset_type' => $assetType,
            ':identifier' => $identifier,
            ':identifier_type' => $identifierType,
            ':account_name' => $accountName ?? $verifyResult['account_name'] ?? null,
            ':account_type' => $accountType,
            ':oauth_state' => $linkResult['state'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $attemptId = $row ? (int)$row['id'] : 0;

        error_log("[SwapService] OAuth attempt {$attemptId} created for {$institution}");

        return [
            'requires_otp' => false,
            'requires_redirect' => true,
            'otp_supported' => true,
            'attempt_id' => $attemptId,
            'redirect_url' => $linkResult['redirect_url'],
            'asset_type' => $assetType,
            'identifier_type' => $identifierType,
            'message' => "You'll be taken to {$institution}'s login page to confirm ownership.",
        ];
    }

    // OTP path
    $stmt = $this->swapDB->prepare("
        INSERT INTO agent_registration_attempts (
            user_id, institution, asset_type, identifier, identifier_type,
            account_name, account_type, bank_auth_id, otp_method,
            otp_expires_at, otp_supported, status
        ) VALUES (
            :user_id, :institution, :asset_type, :identifier, :identifier_type,
            :account_name, :account_type, :auth_id, :method,
            :expires_at, true, 'otp_pending'
        ) RETURNING id
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':institution' => $institution,
        ':asset_type' => $assetType,
        ':identifier' => $identifier,
        ':identifier_type' => $identifierType,
        ':account_name' => $accountName ?? $verifyResult['account_name'] ?? null,
        ':account_type' => $accountType,
        ':auth_id' => $linkResult['auth_id'] ?? null,
        ':method' => $linkResult['method'] ?? 'sms',
        ':expires_at' => date('Y-m-d H:i:s', time() + (int)($linkResult['expires_in'] ?? 300)),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $attemptId = $row ? (int)$row['id'] : 0;

    error_log("[SwapService] OTP attempt {$attemptId} created for {$institution}");

    return [
        'requires_otp' => true,
        'requires_redirect' => false,
        'otp_supported' => true,
        'attempt_id' => $attemptId,
        'method' => $linkResult['method'] ?? 'sms',
        'asset_type' => $assetType,
        'identifier_type' => $identifierType,
        'message' => $linkResult['message'] ?? 'Verification code sent by the institution.',
    ];
}

/**
 * Cancel a pending agent destination registration
 * Allows users to cancel pending or rejected registrations
 */
public function cancelAgentDestination(int $userId, int $destinationId): array
{
    error_log("[SwapService] cancelAgentDestination: user={$userId}, destination_id={$destinationId}");
    
    // First check if this destination belongs to the user
    $stmt = $this->swapDB->prepare("
        SELECT id, status, institution, identifier, asset_type 
        FROM agent_destination_accounts 
        WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $destinationId, ':user_id' => $userId]);
    $destination = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$destination) {
        throw new RuntimeException("Destination account not found or does not belong to you.");
    }
    
    // Only allow cancellation if status is pending or rejected
    if (!in_array($destination['status'], ['pending_confirmation', 'rejected'])) {
        throw new RuntimeException("This account cannot be cancelled (status: {$destination['status']}).");
    }
    
    // Soft delete - set deleted_at and status to cancelled
    $stmt = $this->swapDB->prepare("
        UPDATE agent_destination_accounts 
        SET status = 'cancelled', 
            deleted_at = NOW(),
            updated_at = NOW()
        WHERE id = :id AND user_id = :user_id
    ");
    $stmt->execute([':id' => $destinationId, ':user_id' => $userId]);
    
    // Also cancel any pending registration attempts for this destination
    $stmt = $this->swapDB->prepare("
        UPDATE agent_registration_attempts 
        SET status = 'cancelled', 
            cancelled_at = NOW()
        WHERE user_id = :user_id 
        AND institution = :institution 
        AND identifier = :identifier 
        AND status IN ('otp_pending', 'oauth_pending')
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':institution' => $destination['institution'],
        ':identifier' => $destination['identifier']
    ]);
    
    error_log("[SwapService] Agent destination cancelled: id={$destinationId}, user={$userId}");
    
    return [
        'success' => true,
        'message' => 'Agent destination registration cancelled successfully.',
        'id' => $destinationId,
        'status' => 'cancelled'
    ];
}
    
/**
 * Phase 2: Complete OTP verification - creates the account row on success
 */
public function completeAgentDestinationRegistration(int $userId, int $attemptId, string $otp): array
{
    $stmt = $this->swapDB->prepare("
        SELECT * FROM agent_registration_attempts
        WHERE id = :id AND user_id = :user_id AND status = 'otp_pending'
    ");
    $stmt->execute([':id' => $attemptId, ':user_id' => $userId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attempt) {
        throw new RuntimeException("Verification attempt not found.");
    }
    if (strtotime($attempt['otp_expires_at']) < time()) {
        throw new RuntimeException("Verification code expired. Start registration again.");
    }

    try {
        $verifyResult = $this->verifySourceLink([
            'institution' => $attempt['institution'],
            'auth_id' => $attempt['bank_auth_id'],
            'otp' => $otp,
        ]);
    } catch (Exception $e) {
        throw new RuntimeException("Could not verify code: " . $e->getMessage());
    }

    if (!($verifyResult['success'] ?? false) || !($verifyResult['authorized'] ?? false)) {
        throw new RuntimeException($verifyResult['message'] ?? 'Incorrect or expired code.');
    }

    $id = $this->insertAgentDestinationAccount(
        (int)$attempt['user_id'],
        $attempt['institution'],
        $attempt['asset_type'],
        $attempt['identifier'],
        $attempt['identifier_type'],
        $attempt['account_name'],
        $attempt['account_type'],
        true,
        $verifyResult['access_token'] ?? null,
        $verifyResult['refresh_token'] ?? null,
        $verifyResult['expires_at'] ?? null
    );

    $stmt = $this->swapDB->prepare("
        UPDATE agent_registration_attempts SET status = 'completed', completed_at = NOW() WHERE id = :id
    ");
    $stmt->execute([':id' => $attemptId]);

    error_log("[SwapService] Agent destination {$id} created with OTP verification");

    return [
        'id' => $id,
        'status' => 'pending_confirmation',
        'account_type' => $attempt['account_type'],
        'message' => "Ownership verified. Awaiting approval.",
    ];
}

/**
 * Complete OAuth-based agent registration by state token
 */
public function completeAgentDestinationRegistrationByState(string $oauthState, string $code): array
{
    $stmt = $this->swapDB->prepare("
        SELECT * FROM agent_registration_attempts
        WHERE oauth_state = :state AND status = 'oauth_pending'
    ");
    $stmt->execute([':state' => $oauthState]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attempt) {
        throw new RuntimeException("Registration attempt not found or already completed.");
    }

    $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn.com', '/')
        . '/api/v1/agent/oauth_callback.php';

    try {
        $verifyResult = $this->verifySourceLink([
            'institution' => $attempt['institution'],
            'code' => $code,
            'redirect_uri' => $callbackUrl,
        ]);
    } catch (Exception $e) {
        throw new RuntimeException("Could not complete verification: " . $e->getMessage());
    }

    if (!($verifyResult['success'] ?? false) || !($verifyResult['authorized'] ?? false)) {
        throw new RuntimeException($verifyResult['message'] ?? 'Bank login could not be verified.');
    }

    // ↓↓↓ THIS is the block you replace ↓↓↓
    $id = $this->insertAgentDestinationAccount(
        (int)$attempt['user_id'],
        $attempt['institution'],
        $attempt['asset_type'],
        $attempt['identifier'],
        $attempt['identifier_type'],
        $attempt['account_name'],
        $attempt['account_type'],
        true,
        $verifyResult['access_token'] ?? null,
        $verifyResult['refresh_token'] ?? null,
        $verifyResult['expires_at'] ?? null,
        'active',
         null
    );
    // ↑↑↑ replaces the old call (which had no 'active' / 'SYSTEM_OAUTH_VERIFICATION' args) ↑↑↑

    $stmt = $this->swapDB->prepare("
        UPDATE agent_registration_attempts SET status = 'completed', completed_at = NOW() WHERE id = :id
    ");
    $stmt->execute([':id' => $attempt['id']]);

    error_log("[SwapService] Agent destination {$id} created via OAuth and auto-activated");

    return [
        'id' => $id,
        'status' => 'active',
        'account_type' => $attempt['account_type'],
        'institution' => $attempt['institution'],
        'message' => "Bank login verified. Your account is now active.",
    ];
}

public function finalizeIdentityClaimSplit(
    string $swapReference,
    string $pin,
    string $confirmedByType,
    ?int $confirmedById,
    int $destinationAccountId,
    float $cashNowAmount,
    ?int $agentUserId = null
): array {
    $sql = "
        SELECT institution, identifier, identifier_type, asset_type
        FROM agent_destination_accounts
        WHERE id = :id AND status = 'active' AND deleted_at IS NULL
    ";
    $params = [':id' => $destinationAccountId];

    if ($agentUserId !== null) {
        $sql .= " AND user_id = :user_id";
        $params[':user_id'] = $agentUserId;
    }

    $stmt = $this->swapDB->prepare($sql);
    $stmt->execute($params);
    $destAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$destAccount) {
        throw new RuntimeException("Agent destination account not found, not active, or not owned by this agent.");
    }

    $identitySwap = $this->getIdentitySwapByReference($swapReference);
    if (!$identitySwap) {
        throw new RuntimeException("Identity swap not found: {$swapReference}");
    }

    $fullAmount = (float)$identitySwap['amount'];
    if ($cashNowAmount < 0 || $cashNowAmount > $fullAmount) {
        throw new RuntimeException("Requested cash amount must be between 0 and {$fullAmount}.");
    }

    $remainder = round($fullAmount - $cashNowAmount, 2);

    // Get beneficiary phone
    $beneficiaryPhone = $identitySwap['otp_pin_sent_to'] ?? null;
    if (empty($beneficiaryPhone)) {
        $sourcePayload = json_decode($identitySwap['source_payload'], true);
        $beneficiaryPhone = $sourcePayload['notification_phone'] ?? 
                           $sourcePayload['beneficiary_phone'] ?? 
                           null;
    }

    // STEP 1: Deposit full amount into agent's account
    $depositResult = $this->confirmAndFinalizeIdentitySwap([
        'swap_reference' => $swapReference,
        'pin' => $pin,
        'confirmed_by_type' => $confirmedByType,
        'confirmed_by_id' => $confirmedById,
        'identity_document_verified' => true,
        'destination_type' => 'DEPOSIT',
        'destination_institution' => $destAccount['institution'],
        'destination_identifier' => $destAccount['identifier'],
        'destination_identifier_type' => $destAccount['identifier_type'],
        'destination_asset_type' => $destAccount['asset_type'],
        'client_phone' => $beneficiaryPhone,
        'beneficiary_phone' => $beneficiaryPhone,
    ]);

    $response = ['deposit' => $depositResult, 'remainder_reswap' => null];

    // STEP 2: Process remainder swap AFTER the deposit atomic transaction is complete
    if ($remainder > 0) {
        try {
            error_log("[SwapService] Processing remainder swap for {$swapReference}: {$remainder}");

            $result = $this->executeAtomicSwap([
                'swap_type' => 'IDENTITY',
                'reference' => $swapReference . '_REMAIN_' . time(),
                'from_institution' => $destAccount['institution'],
                'source_institution' => $destAccount['institution'],
                'source_identifier' => $destAccount['identifier'],
                'source_identifier_type' => $destAccount['identifier_type'],
                'asset_type' => $destAccount['asset_type'],
                'amount' => $remainder,
                'currency' => $identitySwap['currency'] ?? 'BWP',
                'identity_type' => $identitySwap['identity_type'],
                'identity_value' => $identitySwap['identity_value'],
                'beneficiary_phone' => $beneficiaryPhone,
                'notification_phone' => $beneficiaryPhone,
            ]);

            $response['remainder_reswap'] = [
                'status' => 'completed',
                'amount' => $remainder,
                'result' => $result
            ];

        } catch (Exception $e) {
            error_log("[SwapService] ERROR processing remainder for {$swapReference}: " . $e->getMessage());
            $response['remainder_reswap'] = [
                'status' => 'failed',
                'amount' => $remainder,
                'error' => $e->getMessage()
            ];
        }
    }

    return $response;
}
    
/**
 * Insert agent destination account (shared helper)
 */
private function insertAgentDestinationAccount(
    int $userId,
    string $institution,
    string $assetType,
    string $identifier,
    string $identifierType,
    ?string $accountName,
    string $accountType,
    bool $isHooked,
    ?string $accessToken,
    ?string $refreshToken,
    ?string $tokenExpiresAt,
    string $status = 'pending_confirmation',
    ?string $confirmedBy = null
): int {
    $sql = "
        INSERT INTO agent_destination_accounts (
            user_id, institution, asset_type, identifier, identifier_type,
            account_name, account_type, account_type_verified,
            is_hooked, access_token, refresh_token, token_expires_at,
            status, proposed_by, proposed_at,
            confirmed_by, confirmed_at
        ) VALUES (
            :user_id, :institution, :asset_type, :identifier, :identifier_type,
            :account_name, :account_type, true,
            :is_hooked, :access_token, :refresh_token, :token_expires_at,
            :status::varchar, :user_id, NOW(),
            :confirmed_by, CASE WHEN :status2::varchar = 'active' THEN NOW() ELSE NULL END
        ) RETURNING id
    ";
    $stmt = $this->swapDB->prepare($sql);
    $stmt->execute([
        ':user_id' => $userId,
        ':institution' => $institution,
        ':asset_type' => $assetType,
        ':identifier' => $identifier,
        ':identifier_type' => $identifierType,
        ':account_name' => $accountName,
        ':account_type' => $accountType,
        ':is_hooked' => $isHooked ? 't' : 'f',
        ':access_token' => $accessToken,
        ':refresh_token' => $refreshToken,
        ':token_expires_at' => $tokenExpiresAt,
        ':status' => $status,
        ':status2' => $status,   // same value, separate placeholder
        ':confirmed_by' => $confirmedBy,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}
/**
 * Simplified wrapper - delegates to initiateAgentDestinationRegistration
 */
public function proposeAgentDestinationAccount(
    int $userId,
    string $institution,
    string $assetType,
    string $identifier,
    string $identifierType,
    ?string $accountName = null
): array {
    return $this->initiateAgentDestinationRegistration(
        $userId,
        $institution,
        $assetType,
        $identifier,
        $identifierType,
        $accountName
    );
}
 
/**
 * Returns this user's APPROVED (active) agent destination accounts,
 * for pre-filling the destination fields when they finalize an
 * identity swap via deposit - so an approved agent never has to
 * manually re-type their own account each time.
 */
public function getApprovedAgentDestinations(int $userId): array
{
    $stmt = $this->swapDB->prepare("
        SELECT id, institution, asset_type, identifier, identifier_type, account_name, confirmed_at
        FROM agent_destination_accounts
        WHERE user_id = :user_id AND status = 'active' AND deleted_at IS NULL
        ORDER BY institution
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
 
/**
 * Whether this user has at least one approved agent destination -
 * cheap check for "should the dashboard show them as an agent at all".
 */
public function isApprovedAgent(int $userId): bool
{
    $stmt = $this->swapDB->prepare("
        SELECT 1 FROM agent_destination_accounts
        WHERE user_id = :user_id AND status = 'active' AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

    // ============================================================================
    // MULTI-SOURCE SWAP
    // ============================================================================

    private function executeMultiSourceSwap(array $payload): array
    {
        error_log("[SwapService] ===== executeMultiSourceSwap START =====");
        error_log("[SwapService] Multi-Source payload has " . count($payload['sources'] ?? []) . " sources");
        
        if ($this->multiSourceOrchestrator === null) {
            error_log("[SwapService] Multi-Source orchestrator not available - falling back to standard swap");
            $this->logger->warning("Multi-source swap requested but orchestrator not initialized - falling back to standard swap");
            
            if (isset($payload['sources']) && is_array($payload['sources']) && count($payload['sources']) > 0) {
                $firstSource = $payload['sources'][0];
                $payload['from_institution'] = $firstSource['institution'] ?? $payload['from_institution'];
                $payload['account_id'] = $firstSource['account_id'] ?? $payload['account_id'];
                $payload['amount'] = $firstSource['amount'] ?? $payload['amount'];
            }
            
            return $this->executeSignedStandardSwap($payload);
        }
        
        try {
            error_log("[SwapService] Delegating to MultiSourceOrchestrator");
            $result = $this->multiSourceOrchestrator->execute($payload);
            error_log("[SwapService] MultiSourceOrchestrator returned: " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
            return $result;
        } catch (Exception $e) {
            error_log("[SwapService] MultiSourceOrchestrator threw exception: " . $e->getMessage());
            $this->logger->error("Multi-source swap failed", ['error' => $e->getMessage()]);
            
            if (isset($payload['sources']) && is_array($payload['sources']) && count($payload['sources']) > 0) {
                $firstSource = $payload['sources'][0];
                $payload['from_institution'] = $firstSource['institution'] ?? $payload['from_institution'];
                $payload['account_id'] = $firstSource['account_id'] ?? $payload['account_id'];
                $payload['amount'] = $firstSource['amount'] ?? $payload['amount'];
                $this->logger->warning("Falling back to standard swap with first source");
                return $this->executeSignedStandardSwap($payload);
            }
            
            throw new RuntimeException("Multi-source swap failed: " . $e->getMessage());
        }
    }

    // ============================================================================
    // EXECUTE SIGNED STANDARD SWAP
    // ============================================================================

    private function executeSignedStandardSwap(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $this->extractSourceInstitution($payload);
        $destInstitution = $this->extractDestinationInstitution($payload);
        
        $verificationResult = $this->verifyAssetSigned($payload, $sourceInstitution);
        if (!($verificationResult['verified'] ?? false)) {
            throw new RuntimeException("Asset verification failed");
        }
        
        $holdResult = $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        if (!($holdResult['hold_placed'] ?? false)) {
            throw new RuntimeException("Hold failed");
        }
        
        $isHooked = isset($payload['_is_hooked']) && $payload['_is_hooked'] === true;
        $this->assertStepIntegrity(
            $holdResult,
            'hold_placed',
            $isHooked ? ['hold_reference'] : ['hold_reference', 'signature'],
            'PLACE_HOLD_SIGNED'
        );
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? null;
        
        $feeBreakdown = $this->calculateFeesWithDetails('SWAP', $amount, $payload);
        $netAmount = $feeBreakdown['net_amount'] ?? $amount;
        
        $destinationResult = $this->processDestinationWithProof($payload, $destInstitution, $netAmount);
        if (!($destinationResult['credited'] ?? false)) {
            throw new RuntimeException("Destination processing failed");
        }
        
        $this->assertStepIntegrity(
            $destinationResult,
            'credited',
            ['transaction_reference'],
            'PROCESS_DESTINATION_WITH_PROOF'
        );
        
        $debitResult = $this->debitSource($payload, $sourceInstitution);
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed");
        }
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $destInstitution
        ];
    }

    // ============================================================================
    // PRIVATE HELPER METHODS
    // ============================================================================

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
        
        $sourceCurrency = $payload['currency'] ?? $this->config['currency'] ?? 'BWP';
        $destinationCurrency = $payload['destination_currency'] ?? $sourceCurrency;
        
        $feeResult = $this->feeService->calculateFees($feeType, $amount, $payload);
        
        $totalFee = $feeResult['total_fee'] ?? 0;
        $netAmountSourceCurrency = $feeResult['net_amount_source_currency'] ?? ($amount - $totalFee);
        
        $forexApplied = $feeResult['forex']['applied'] ?? false;
        $exchangeRate = $feeResult['forex']['rate'] ?? 1.0;
        $netAmountDestCurrency = $feeResult['net_amount_destination_currency'] ?? $netAmountSourceCurrency;
        
        $denominations = $this->atmNotes[$destinationCurrency] ?? [200, 100, 50, 20, 10];
        $multiplier = $denominations[0] ?? 100;
        
        $dispensableAmount = $multiplier * floor($netAmountDestCurrency / $multiplier);
        $remainderBalance = $netAmountDestCurrency - $dispensableAmount;
        
        if ($dispensableAmount <= 0 && $netAmountDestCurrency > 0) {
            $smallestDenom = min($denominations);
            $dispensableAmount = $smallestDenom * floor($netAmountDestCurrency / $smallestDenom);
            $remainderBalance = $netAmountDestCurrency - $dispensableAmount;
            $multiplier = $smallestDenom;
            error_log("[SwapService] Using smallest denomination {$smallestDenom} for amount {$netAmountDestCurrency} {$destinationCurrency}");
        }
        
        $this->feeCalculationDetails = [
            'fee_type' => $feeType,
            'original_amount' => $amount,
            'original_currency' => $sourceCurrency,
            'total_fee' => $totalFee,
            'total_fee_currency' => $sourceCurrency,
            'net_amount_source_currency' => $netAmountSourceCurrency,
            'forex_applied' => $forexApplied,
            'exchange_rate' => $exchangeRate,
            'net_amount_destination_currency' => $netAmountDestCurrency,
            'destination_currency' => $destinationCurrency,
            'multiplier' => $multiplier,
            'dispensable_amount' => $dispensableAmount,
            'remainder_balance' => $remainderBalance,
            'denominations' => $denominations,
            'breakdown' => $feeResult['breakdown'] ?? [],
            'revenue_split' => $feeResult['distribution'] ?? [],
            'destination_split' => $feeResult['destination_split'] ?? [],
            'mathematical_formulas' => [
                'Amount_1' => $amount,
                'F1' => $totalFee,
                'Amount_2' => $netAmountSourceCurrency,
                'Exchange_Rate' => $exchangeRate,
                'Amount_3' => $netAmountDestCurrency,
                'M' => $multiplier,
                'Amount_4' => $dispensableAmount,
                'Remainder_1' => $remainderBalance
            ]
        ];
        
        error_log("[SwapService] Mathematical calculation:");
        error_log("  Amount_1: {$amount} {$sourceCurrency}");
        error_log("  F1 (fee): {$totalFee} {$sourceCurrency}");
        error_log("  Amount_2: {$netAmountSourceCurrency} {$sourceCurrency}");
        if ($forexApplied) {
            error_log("  Exchange Rate: {$exchangeRate}");
            error_log("  Amount_3: {$netAmountDestCurrency} {$destinationCurrency}");
        }
        error_log("  M (multiplier): {$multiplier}");
        error_log("  Amount_4 (dispensable): {$dispensableAmount}");
        error_log("  Remainder_1: {$remainderBalance}");
        
        return [
            'total_fee' => $totalFee,
            'total_fee_currency' => $sourceCurrency,
            'net_amount' => $netAmountDestCurrency,
            'net_amount_source_currency' => $netAmountSourceCurrency,
            'net_amount_destination_currency' => $netAmountDestCurrency,
            'dispensable_amount' => $dispensableAmount,
            'remainder_balance' => $remainderBalance,
            'exchange_rate' => $exchangeRate,
            'forex_applied' => $forexApplied,
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'multiplier' => $multiplier,
            'denominations' => $denominations,
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

    private function forwardPin(array $originalPayload, array &$targetPayload): void
    {
        $isHooked = isset($originalPayload['_is_hooked']) && $originalPayload['_is_hooked'] === true;
        
        if ($isHooked) {
            error_log("[SwapService] Using hooked source - skipping PIN");
            if (!empty($originalPayload['access_token'])) {
                $targetPayload['access_token'] = $originalPayload['access_token'];
            }
            if (!empty($originalPayload['source_reference'])) {
                $targetPayload['source_reference'] = $originalPayload['source_reference'];
            }
            return;
        }
        
        if (!empty($originalPayload['wallet_pin'])) {
            $targetPayload['wallet_pin'] = $originalPayload['wallet_pin'];
            $targetPayload['pin'] = $originalPayload['wallet_pin'];
            error_log("[SwapService] Forwarded wallet_pin (optional)");
        } elseif (!empty($originalPayload['voucher_pin'])) {
            $targetPayload['voucher_pin'] = $originalPayload['voucher_pin'];
            $targetPayload['pin'] = $originalPayload['voucher_pin'];
            error_log("[SwapService] Forwarded voucher_pin (optional)");
        } elseif (!empty($originalPayload['pin'])) {
            $targetPayload['pin'] = $originalPayload['pin'];
            $targetPayload['wallet_pin'] = $originalPayload['pin'];
            error_log("[SwapService] Forwarded pin (optional)");
        } else {
            error_log("[SwapService] No PIN provided - using alternative authentication");
        }
        
        if (!empty($originalPayload['access_token'])) {
            $targetPayload['access_token'] = $originalPayload['access_token'];
            error_log("[SwapService] Forwarded access_token for institution auth");
        }
        
        if (!empty($originalPayload['source_reference'])) {
            $targetPayload['source_reference'] = $originalPayload['source_reference'];
        }
    }

    // ============================================================================
    // ADAPTER-BASED PRIVATE METHODS
    // ============================================================================

    public function verifyAssetSigned(array $payload, string $institution): array
    {
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
            'requester' => 'VOUCHMORPH',
            'from_institution' => $institution,
            'source_institution' => $institution
        ];

        $this->forwardPin($payload, $verifyPayload);

        if ($sourceId['has_value']) {
            $verifyPayload['source_identifier'] = $sourceId['identifier'];
            $verifyPayload['source_identifier_type'] = $sourceId['type'];
        }

        $adapter = $this->adapterFactory->getAdapter($institution);
        return $adapter->verifyAsset($verifyPayload, [
            'swap_reference' => $this->currentSwapRef,
            'institution' => $institution,
            'source_identifier' => $sourceId['identifier'] ?? null,
            'signed_payloads' => $this->signedPayloads,
            'timestamp' => $timestamp
        ]);
    }

    public function placeHoldSigned(array $payload, string $institution, array $verificationResult): array
    {
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $timestamp = time();
        $sourceId = $this->extractSourceIdentifier($payload);
        $destInstitution = $payload['to_institution'] ?? $payload['destination_institution'] ?? null;

        $holdPayload = [
            'action' => 'PLACE_HOLD',
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? $this->config['currency'] ?? 'BWP',
            'hold_reason' => $payload['hold_reason'] ?? 'PENDING_SWAP',
            'destination_institution' => $destInstitution,
            'expiry' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'timestamp' => $timestamp,
            'from_institution' => $institution,
            'source_institution' => $institution,
            'user_id' => $payload['user_id'] ?? 0
        ];

        $this->forwardPin($payload, $holdPayload);

        if ($sourceId['has_value']) {
            $holdPayload['source_identifier'] = $sourceId['identifier'];
            $holdPayload['source_identifier_type'] = $sourceId['type'];
        }

        if (isset($verificationResult['asset_id'])) {
            $holdPayload['asset_id'] = $verificationResult['asset_id'];
        }

        $adapter = $this->adapterFactory->getAdapter($institution);
        $result = $adapter->placeHold($holdPayload, [
            'swap_reference' => $this->currentSwapRef,
            'institution' => $institution,
            'verification_result' => $verificationResult,
            'source_identifier' => $sourceId['identifier'] ?? null,
            'signed_payloads' => $this->signedPayloads,
            'timestamp' => $timestamp
        ]);

        if (!($result['hold_placed'] ?? false)) {
            return $result;
        }

        $holdId = $this->createLocalHold($payload, $institution, $result['hold_reference'] ?? null);
        $this->currentHoldId = $holdId;
        $this->currentHoldReference = $result['hold_reference'] ?? $this->currentHoldReference;
        $this->currentHoldInstitution = $institution;
        $result['local_hold_id'] = $holdId;

        return $result;
    }

    public function debitSource(array $payload, string $institution): array
{
    $sourceId = $this->extractSourceIdentifier($payload);
    if ($sourceId['has_value']) {
        $this->validateAgentMinimumBalance($institution, $sourceId['identifier'], (float)($payload['amount'] ?? 0));
    }

    $debitPayload = [
        'reference' => $payload['reference'] ?? $this->currentSwapRef,
        'hold_reference' => $payload['hold_reference'] ?? $this->currentHoldReference,
        'amount' => $payload['amount'] ?? 0,
        'reason' => $payload['reason'] ?? 'Swap completed successfully',
        'from_institution' => $institution,
        'source_institution' => $institution
    ];

    $this->forwardPin($payload, $debitPayload);

    $adapter = $this->adapterFactory->getAdapter($institution);
    return $adapter->debit($debitPayload, [
        'swap_reference' => $this->currentSwapRef,
        'institution' => $institution,
        'hold_reference' => $this->currentHoldReference,
        'signed_payloads' => $this->signedPayloads
    ]);
}

    public function releaseHold(
        array $sourcePayload,
        string $institution,
        ?string $holdId = null,
        ?string $holdReference = null
    ): array {
        $this->logger->info("releaseHold called", [
            'institution' => $institution,
            'hold_id' => $holdId,
            'hold_reference' => $holdReference
        ]);

        $holdRef = $holdReference ?? $sourcePayload['hold_reference'] ?? $this->currentHoldReference ?? null;
        
        if (empty($holdRef)) {
            $this->logger->warning("No hold reference available for release", [
                'institution' => $institution,
                'hold_id' => $holdId
            ]);
            return [
                'success' => false,
                'message' => 'No hold reference available for release'
            ];
        }

        $releasePayload = [
            'action' => 'RELEASE_HOLD',
            'hold_reference' => $holdRef,
            'reason' => 'Multi-source swap rolled back',
            'from_institution' => $institution,
            'source_institution' => $institution
        ];

        $this->forwardPin($sourcePayload, $releasePayload);
        
        if (!empty($sourcePayload['access_token'])) {
            $releasePayload['access_token'] = $sourcePayload['access_token'];
        }

        try {
            $adapter = $this->adapterFactory->getAdapter($institution);
            $result = $adapter->releaseHold($releasePayload, [
                'swap_reference' => $this->currentSwapRef ?? 'MULTI_SOURCE_ROLLBACK',
                'institution' => $institution,
                'hold_reference' => $holdRef,
                'signed_payloads' => $this->signedPayloads
            ]);

            if ($holdId) {
                $this->updateHoldStatus((int)$holdId, 'RELEASED');
            }

            $this->logger->info("Hold released successfully", [
                'institution' => $institution,
                'hold_reference' => $holdRef,
                'success' => $result['success'] ?? false
            ]);

            return [
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? 'Hold released',
                'hold_reference' => $holdRef
            ];

        } catch (Exception $e) {
            $this->logger->error("Failed to release hold", [
                'institution' => $institution,
                'hold_reference' => $holdRef,
                'error' => $e->getMessage()
            ]);

            if ($holdId) {
                $this->updateHoldStatus((int)$holdId, 'RELEASED');
            }

            return [
                'success' => false,
                'message' => 'Failed to release hold: ' . $e->getMessage(),
                'hold_reference' => $holdRef,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getForexRate(string $fromCurrency, string $toCurrency, string $clientTier = 'retail'): array
    {
        if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
            return [
                'rate' => 1.0,
                'wholesale_rate' => 1.0,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'applied' => false,
            ];
        }

        $clientRate = $this->forexService->getClientRate($fromCurrency, $toCurrency, $clientTier);
        $wholesaleRate = $this->forexService->getWholesaleRate($fromCurrency, $toCurrency);

        return [
            'rate' => $clientRate,
            'wholesale_rate' => $wholesaleRate,
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'applied' => true,
        ];
    }

    public function creditDestination(array $payload, string $institution): array
    {
        error_log("[SwapService] creditDestination called for institution: {$institution}");

        $amount = (float)($payload['amount'] ?? 0);
        $destId = $this->extractDestinationIdentifier($payload);
        $destinationAssetType = $this->extractDestinationAssetType($payload);

        $creditPayload = [
            'reference' => $payload['reference'] ?? $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
            'destination_asset_type' => $destinationAssetType,
            'asset_type' => $destinationAssetType,
            'to_institution' => $institution,
            'destination_institution' => $institution,
            'source_type' => 'VIRTUAL_POOL',
            'pool_id' => $payload['pool_id'] ?? null,
            'master_signature' => $payload['master_signature'] ?? null,
            'user_id' => $payload['user_id'] ?? 0, 
        ];

        if ($destId['has_value']) {
            $creditPayload['destination_identifier'] = $destId['identifier'];
            $creditPayload['destination_identifier_type'] = $destId['type'];

            if ($destinationAssetType === 'ACCOUNT') {
                $creditPayload['account_number'] = $destId['identifier'];
                $creditPayload['destination_account'] = $destId['identifier'];
            } else {
                $creditPayload['phone'] = $destId['identifier'];
                $creditPayload['wallet_phone'] = $destId['identifier'];
            }
        }

        $adapter = $this->adapterFactory->getAdapter($institution);
        $result = $adapter->credit($creditPayload, [
            'swap_reference' => $creditPayload['reference'],
            'destination_institution' => $institution,
            'destination_identifier' => $destId['identifier'] ?? null,
            'destination_asset_type' => $destinationAssetType,
            'pool_id' => $payload['pool_id'] ?? null,
        ]);

        if (!($result['credited'] ?? false)) {
            return ['success' => false, 'message' => $result['message'] ?? 'Pool credit failed'];
        }

        $this->assertStepIntegrity(
            $result,
            'credited',
            ['transaction_reference'],
            'CREDIT_DESTINATION'
        );

        return [
            'success' => true,
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'message' => $result['message'] ?? 'Pool credit successful',
        ];
    }

    private function generateCashoutToken(array $payload, string $institution, float $amount): array
    {
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        $sourceInstitution = $this->extractSourceInstitution($payload);

        $tokenPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'hold_reference' => $this->currentHoldReference,
            'action' => 'GENERATE_TOKEN',
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null,
            'beneficiary_phone' => $beneficiaryPhone,
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'to_institution' => $institution,
            'destination_institution' => $institution
        ];

        if (isset($payload['note_breakdown'])) {
            $tokenPayload['note_breakdown'] = $payload['note_breakdown'];
        }

        $adapter = $this->adapterFactory->getAdapter($institution);
        return $adapter->generateCashoutToken($tokenPayload, [
            'swap_reference' => $this->currentSwapRef,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $institution,
            'hold_reference' => $this->currentHoldReference,
            'beneficiary_phone' => $beneficiaryPhone,
            'signed_payloads' => $this->signedPayloads
        ]);
    }

    private function verifyAccount(array $payload, string $institution, array $destinationIdentifier): array
    {
        $sourceInstitution = $this->extractSourceInstitution($payload);
        $destinationAssetType = $this->extractDestinationAssetType($payload);

        $verifyPayload = [
            'action' => 'VERIFY_ACCOUNT',
            'reference' => $this->currentSwapRef,
            'account_identifier' => $destinationIdentifier['identifier'],
            'identifier_type' => $destinationIdentifier['type'],
            'requester' => 'VOUCHMORPH',
            'timestamp' => time(),
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'to_institution' => $institution,
            'destination_institution' => $institution,
            'destination_asset_type' => $destinationAssetType,
        ];

        $adapter = $this->adapterFactory->getAdapter($institution);
        return $adapter->verifyAccount($verifyPayload, [
            'swap_reference' => $this->currentSwapRef,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $institution,
            'destination_identifier' => $destinationIdentifier,
            'destination_asset_type' => $destinationAssetType,
            'signed_payloads' => $this->signedPayloads
        ]);
    }

    private function processDepositWithProof(array $payload, string $institution, float $amount): array
    {
        error_log("[SwapService] processDepositWithProof called for institution: {$institution}");
        
        $sourceInstitution = $this->extractSourceInstitution($payload);
        $destinationInstitution = $this->extractDestinationInstitution($payload);
        $destId = $this->extractDestinationIdentifier($payload);
        $sourceId = $this->extractSourceIdentifier($payload);
        $destinationAssetType = $this->extractDestinationAssetType($payload);
        
        error_log("[SwapService] processDepositWithProof: source={$sourceInstitution}, dest={$destinationInstitution}, amount={$amount}, asset_type={$destinationAssetType}");
        
        $depositPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null,
            'source_details' => $payload['source_details'] ?? [],
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'to_institution' => $destinationInstitution,
            'destination_institution' => $destinationInstitution,
            'bank' => $sourceInstitution,
            'destination_asset_type' => $destinationAssetType,
            'asset_type' => $destinationAssetType,
            'user_id' => $payload['user_id'] ?? 0, 
        ];
        
        if ($sourceId['has_value']) {
            $depositPayload['source_identifier'] = $sourceId['identifier'];
            $depositPayload['source_identifier_type'] = $sourceId['type'];
            $depositPayload['source_account'] = $sourceId['identifier'];
            error_log("[SwapService] Added source_identifier: {$sourceId['identifier']}");
        }
        
        if ($destId['has_value']) {
            $depositPayload['destination_identifier'] = $destId['identifier'];
            $depositPayload['destination_identifier_type'] = $destId['type'];
            
            if ($destinationAssetType === 'ACCOUNT') {
                $depositPayload['account_number'] = $destId['identifier'];
                $depositPayload['destination_account'] = $destId['identifier'];
                error_log("[SwapService] Destination is ACCOUNT: {$destId['identifier']}");
            } else {
                $depositPayload['phone'] = $destId['identifier'];
                $depositPayload['wallet_phone'] = $destId['identifier'];
                $depositPayload['beneficiary_phone'] = $destId['identifier'];
                error_log("[SwapService] Destination is WALLET: {$destId['identifier']}");
            }
        } else {
            error_log("[SwapService] WARNING: No destination identifier found!");
        }
        
        if ($this->currentHoldReference) {
            $depositPayload['hold_reference'] = $this->currentHoldReference;
            $depositPayload['_skip_hold'] = true;
        }
        
        $logPayload = $depositPayload;
        if (isset($logPayload['pin'])) $logPayload['pin'] = '******';
        if (isset($logPayload['certificate'])) $logPayload['certificate'] = '***CERT***';
        error_log("[SwapService] processDepositWithProof final payload: " . json_encode($logPayload));
        
        $adapter = $this->adapterFactory->getAdapter($destinationInstitution);
        $result = $adapter->credit($depositPayload, [
            'swap_reference' => $this->currentSwapRef,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $destinationInstitution,
            'destination_identifier' => $destId['identifier'] ?? null,
            'destination_asset_type' => $destinationAssetType,
            'hold_reference' => $this->currentHoldReference,
            'signed_payloads' => $this->signedPayloads
        ]);
        
        if (!($result['credited'] ?? false)) {
            return ['success' => false, 'message' => $result['message'] ?? 'Deposit failed'];
        }
        
        return [
            'success' => true,
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'message' => $result['message'] ?? 'Deposit successful',
            'credited' => true
        ];
    }

    private function processDestinationWithProof(array $payload, string $institution, float $amount): array
    {
        $destId = $this->extractDestinationIdentifier($payload);
        $sourceInstitution = $this->extractSourceInstitution($payload);
        
        $transferPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_type' => $payload['destination_type'] ?? 'ACCOUNT',
            'action' => 'PROCESS_TRANSFER_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null,
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'to_institution' => $institution,
            'destination_institution' => $institution
        ];
        
        if ($destId['has_value']) {
            $transferPayload['destination_identifier'] = $destId['identifier'];
            $transferPayload['destination_identifier_type'] = $destId['type'];
        }
        
        $adapter = $this->adapterFactory->getAdapter($institution);
        $result = $adapter->transferWithProof($transferPayload, [
            'swap_reference' => $this->currentSwapRef,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $institution,
            'destination_identifier' => $destId['identifier'] ?? null,
            'signed_payloads' => $this->signedPayloads
        ]);
        
        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => $result['message'] ?? 'Destination processing failed'];
        }
        
        return [
            'success' => true,
            'credited' => true,
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'message' => $result['message'] ?? 'Destination processed successfully'
        ];
    }

    private function executeCardIssuance(array $payload): array
    {
        if (!$this->cardService) {
            throw new RuntimeException("Card service not initialized");
        }
        return $this->cardService->issueCard($payload);
    }

    // ============================================================================
    // IDENTITY SWAP HELPER METHODS
    // ============================================================================

    private function storeIdentityHold(array $payload, string $swapRef, array $holdResult, int $holdId): int
{
    $sourceInstitution = $this->extractSourceInstitution($payload);
    $identityType = strtolower($payload['identity_type']);
    $identityValue = $payload['identity_value'];
 
    // Decide claim path: is this identity already a VERIFIED owner
    // in our system? If so, they'll use their personal account PIN
    // at claim time. If not, this is a first-time/unregistered
    // recipient and needs a one-time OTP PIN.
    $owner = $this->findVerifiedIdentityOwner($identityType, $identityValue);
    $notificationPhone = $payload['notification_phone'] ?? $payload['beneficiary_phone'] ?? null;

    $levyAmount = (float)($this->feesConfig['DEPOSIT']['fee_components']['F7']['amount'] ?? 0);
 
    $claimType = null;
    $otpHash = null;
    $requiresDual = false;
 
    if ($owner) {
        $claimType = 'account_pin';
        error_log("[SwapService] Identity {$identityType}={$identityValue} is a VERIFIED registered owner (user_id={$owner['user_id']}) - claim will require their account PIN");
   } elseif ($notificationPhone) {
        $claimType = 'otp_pin';
        $otp = $this->generateOtpPin();
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        error_log("[SwapService] Identity {$identityType}={$identityValue} is UNREGISTERED - generated one-time claim PIN, sending to {$notificationPhone}");
        if ($this->smsService) {
            try {
                $this->smsService->sendCashoutCode($notificationPhone, $otp, (float)$payload['amount'], $swapRef);
                $this->trackIdentityOtpSmsAttempt($swapRef, $notificationPhone, 'queued');
            } catch (Exception $e) {
                error_log("[SwapService] Failed to SMS claim PIN: " . $e->getMessage());
                $this->trackIdentityOtpSmsAttempt($swapRef, $notificationPhone, 'failed', $e->getMessage());
            }
        } else {
            error_log("[SwapService] SMS service not configured - claim PIN generated but never sent to {$notificationPhone}");
            $this->trackIdentityOtpSmsAttempt($swapRef, $notificationPhone, 'skipped_no_provider');
        }
    } else {
        // No registered owner AND no phone to send an OTP to.
        // This is the hard case flagged in review: an agent's word
        // alone must never be sufficient to release funds here.
        // Dual confirmation (two independent agents, or one agent +
        // a VouchMorph ops reviewer) is required, but that reviewer
        // workflow isn't built yet — so we deliberately block single-
        // actor finalization rather than silently allowing it.
        $claimType = 'dual_confirmation';
        $requiresDual = true;
        error_log("[SwapService] WARNING: Identity {$identityType}={$identityValue} has no registered owner and no phone - flagged for dual confirmation (not yet implemented; finalization will be blocked until built)");
    }
 
    $sql = "
        INSERT INTO identity_swap_holds (
            swap_reference, source_institution, source_identifier, source_asset_type,
            amount, currency, identity_type, identity_value,
            hold_reference, hold_id, hold_expires_at, status,
            source_payload, metadata, created_by,
            otp_pin_hash, otp_pin_sent_to, otp_pin_sent_at,
            requires_dual_confirmation, claim_type
        ) VALUES (
            :swap_ref, :source_institution, :source_identifier, :asset_type,
            :amount, :currency, :identity_type, :identity_value,
            :hold_reference, :hold_id, :expires_at, 'pending',
            :source_payload::jsonb, :metadata::jsonb, :created_by,
            :otp_pin_hash, :otp_pin_sent_to, :otp_pin_sent_at,
            :requires_dual, :claim_type
        ) RETURNING hold_id
    ";
 
    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':swap_ref' => $swapRef,
            ':source_institution' => $sourceInstitution,
            ':source_identifier' => $payload['source_identifier'],
            ':asset_type' => $payload['asset_type'] ?? 'ACCOUNT',
            ':amount' => $payload['amount'],
            ':currency' => $payload['currency'] ?? 'BWP',
            ':identity_type' => $identityType,
            ':identity_value' => $identityValue,
            ':hold_reference' => $holdResult['hold_reference'],
            ':hold_id' => $holdId,
            ':expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            ':source_payload' => json_encode($payload),
            ':metadata' => json_encode([
                'signed_payloads' => $this->signedPayloads,
                'hold_result' => $holdResult
            ]),
            ':created_by' => $payload['user_id'] ?? null,
            ':otp_pin_hash' => $otpHash,
            ':otp_pin_sent_to' => $otpHash ? $notificationPhone : null,
            ':otp_pin_sent_at' => $otpHash ? date('Y-m-d H:i:s') : null,
            ':requires_dual' => $requiresDual ? 't' : 'f',
            ':claim_type' => $claimType
        ]);
 
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['hold_id'] : 0;
 
    } catch (PDOException $e) {
        error_log("[SwapService] Failed to store identity hold: " . $e->getMessage());
        throw new RuntimeException("Failed to store identity hold: " . $e->getMessage());
    }
}


/**
 * Track an identity-swap OTP PIN send attempt in message_outbox, mirroring
 * populateMessageOutbox()'s pattern for cashout codes. This is the only
 * place that records whether an identity-swap PIN SMS was ever queued -
 * without it, there's no way to distinguish "PIN generated but SMS never
 * attempted" from "SMS attempted but provider rejected it" after the fact.
 */
private function trackIdentityOtpSmsAttempt(
    string $swapRef,
    string $phone,
    string $status,
    ?string $providerError = null
): void {
    // Generate a unique message_id if the table requires it
    $messageId = 'SMS_' . uniqid() . '_' . substr($swapRef, 0, 10);
    
    $sql = "
        INSERT INTO message_outbox (
            message_id,
            channel,
            destination,
            payload,
            status,
            created_at,
            sent_at
        ) VALUES (
            :message_id,
            'SMS',
            :destination,
            :payload::jsonb,
            :status,
            :created_at,
            :sent_at
        )
    ";

    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':message_id' => $messageId,
            ':destination' => $phone,
            ':payload' => json_encode([
                'phone' => $phone,
                'swap_reference' => $swapRef,
                'message_type' => 'identity_swap_otp_pin',
                'provider_error' => $providerError,
            ]),
            ':status' => $status,
            ':created_at' => date('Y-m-d H:i:s'),
            ':sent_at' => $status === 'queued' ? date('Y-m-d H:i:s') : null,
        ]);

        error_log("[SwapService] Identity OTP SMS attempt tracked: swap_ref={$swapRef}, phone={$phone}, status={$status}, message_id={$messageId}");
    } catch (PDOException $e) {
        // Non-fatal by design, same reasoning as the rest of populateTrackingTables():
        // the identity swap itself must not fail just because tracking failed.
        error_log("[SwapService] Failed to track identity OTP SMS attempt: " . $e->getMessage());
    }
}
    
    private function updateIdentityHoldStatus(int $holdId, string $status, array $additionalData = []): void
    {
        $validStatuses = ['pending', 'confirmed', 'completed', 'expired', 'cancelled'];
        if (!in_array($status, $validStatuses)) {
            throw new RuntimeException("Invalid status: {$status}");
        }
        
        $setClauses = [];
        $params = [':hold_id' => $holdId, ':status' => $status];
        
        $timestampMap = [
            'confirmed' => 'confirmed_at',
            'completed' => 'completed_at',
            'expired' => 'expired_at'
        ];
        
        if (isset($timestampMap[$status])) {
            $setClauses[] = "{$timestampMap[$status]} = NOW()";
        }
        
        if (!empty($additionalData)) {
            $setClauses[] = "metadata = metadata || :additional_data::jsonb";
            $params[':additional_data'] = json_encode($additionalData);
        }
        
        if (isset($additionalData['final_destination_type'])) {
            $setClauses[] = "final_destination_type = :dest_type";
            $params[':dest_type'] = $additionalData['final_destination_type'];
        }
        
        if (isset($additionalData['final_destination_payload'])) {
            $setClauses[] = "final_destination_payload = :dest_payload::jsonb";
            $params[':dest_payload'] = json_encode($additionalData['final_destination_payload']);
        }
        
        if (isset($additionalData['final_transaction_reference'])) {
            $setClauses[] = "final_transaction_reference = :tx_ref";
            $params[':tx_ref'] = $additionalData['final_transaction_reference'];
        }
        
        if (isset($additionalData['confirmed_by_type'])) {
            $setClauses[] = "confirmed_by_type = :confirmed_type";
            $params[':confirmed_type'] = $additionalData['confirmed_by_type'];
        }
        
        if (isset($additionalData['confirmed_by_id'])) {
            $setClauses[] = "confirmed_by_id = :confirmed_id";
            $params[':confirmed_id'] = $additionalData['confirmed_by_id'];
        }
        
        if (isset($additionalData['confirmation_method'])) {
            $setClauses[] = "confirmation_method = :conf_method";
            $params[':conf_method'] = $additionalData['confirmation_method'];
        }
        
        $setClauses[] = "status = :status";
        
        $sql = "UPDATE identity_swap_holds SET " . implode(', ', $setClauses) . " WHERE hold_id = :hold_id";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute($params);
            error_log("[SwapService] Identity hold {$holdId} updated to: {$status}");
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to update identity hold: " . $e->getMessage());
            throw new RuntimeException("Failed to update identity hold: " . $e->getMessage());
        }
    }

    
private function generateOtpPin(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
 
/**
 * Look up whether an identity_type/identity_value pair belongs to
 * a registered user with a VERIFIED (KYC-approved) identity record.
 * Returns the owning user's id if so, null otherwise.
 */
private function findVerifiedIdentityOwner(string $identityType, string $identityValue): ?array
{
    try {
        $stmt = $this->swapDB->prepare("
            SELECT user_id FROM user_identities
            WHERE identity_type = :type AND identity_value = :value AND status = 'verified'
            LIMIT 1
        ");
        $stmt->execute([':type' => $identityType, ':value' => $identityValue]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['user_id' => (int)$row['user_id']] : null;
    } catch (PDOException $e) {
        error_log("[SwapService] findVerifiedIdentityOwner failed: " . $e->getMessage());
        return null;
    }
}
 
/**
 * Verifies the PIN supplied at claim time against whichever claim
 * path was decided at initiation (account_pin / otp_pin /
 * dual_confirmation), with attempt-based lockout on both paths.
 * Throws on any failure - callers never get a silent pass.
 */
private function verifyIdentityClaimPin(array $identitySwap, string $suppliedPin): void
{
    $claimType = $identitySwap['claim_type'] ?? null;
    $holdId = (int)$identitySwap['hold_id'];
 
    if ($suppliedPin === '') {
        throw new RuntimeException("A PIN is required to finalize this claim.");
    }
 
    if ($claimType === 'dual_confirmation') {
        throw new RuntimeException(
            "This claim has no registered owner and no phone on file, so it requires " .
            "confirmation from two independent parties. That workflow is not yet available - " .
            "please escalate to VouchMorph ops rather than finalizing manually."
        );
    }
 
    if ($claimType === 'otp_pin') {
        $this->assertNotLocked($identitySwap['otp_pin_locked_until'] ?? null, 'claim PIN');
 
        $hash = $identitySwap['otp_pin_hash'] ?? null;
        if (!$hash || !password_verify($suppliedPin, $hash)) {
            $this->recordFailedIdentityOtpAttempt($holdId, (int)($identitySwap['otp_pin_attempts'] ?? 0));
            throw new RuntimeException("Incorrect claim PIN.");
        }
 
        // Single-use: clear it so it can't be replayed.
        $stmt = $this->swapDB->prepare("
            UPDATE identity_swap_holds
            SET otp_pin_hash = NULL, otp_pin_attempts = 0
            WHERE hold_id = :id
        ");
        $stmt->execute([':id' => $holdId]);
        return;
    }
 
    if ($claimType === 'account_pin') {
        $owner = $this->findVerifiedIdentityOwner($identitySwap['identity_type'], $identitySwap['identity_value']);
        if (!$owner) {
            // Identity was verified at initiation time but no longer is
            // (or was removed) - fail closed, don't fall back to OTP.
            throw new RuntimeException("This identity's verification status changed - claim cannot proceed. Contact support.");
        }
 
        $stmt = $this->swapDB->prepare("
            SELECT transaction_pin_hash, transaction_pin_attempts, transaction_pin_locked_until
            FROM users WHERE user_id = :id
        ");
        $stmt->execute([':id' => $owner['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
 
        if (!$user || empty($user['transaction_pin_hash'])) {
            throw new RuntimeException("No transaction PIN has been set on this account yet. Set one in your VouchMorph profile before claiming.");
        }
 
        $this->assertNotLocked($user['transaction_pin_locked_until'] ?? null, 'transaction PIN');
 
        if (!password_verify($suppliedPin, $user['transaction_pin_hash'])) {
            $this->recordFailedAccountPinAttempt($owner['user_id'], (int)($user['transaction_pin_attempts'] ?? 0));
            throw new RuntimeException("Incorrect transaction PIN.");
        }
 
        // Reset attempt counter on success.
        $stmt = $this->swapDB->prepare("UPDATE users SET transaction_pin_attempts = 0 WHERE user_id = :id");
        $stmt->execute([':id' => $owner['user_id']]);
        return;
    }
 
    throw new RuntimeException("Unknown claim type for this identity swap - cannot verify PIN.");
}
 
private function assertNotLocked(?string $lockedUntil, string $label): void
{
    if ($lockedUntil && strtotime($lockedUntil) > time()) {
        $waitMinutes = ceil((strtotime($lockedUntil) - time()) / 60);
        throw new RuntimeException("Too many incorrect attempts on the {$label}. Try again in {$waitMinutes} minute(s).");
    }
}
 
private function recordFailedIdentityOtpAttempt(int $holdId, int $currentAttempts): void
{
    $attempts = $currentAttempts + 1;
    $maxAttempts = 5;
    $lockUntil = $attempts >= $maxAttempts ? date('Y-m-d H:i:s', strtotime('+30 minutes')) : null;
 
    $stmt = $this->swapDB->prepare("
        UPDATE identity_swap_holds
        SET otp_pin_attempts = :attempts, otp_pin_locked_until = :lock
        WHERE hold_id = :id
    ");
    $stmt->execute([':attempts' => $attempts, ':lock' => $lockUntil, ':id' => $holdId]);
 
    if ($lockUntil) {
        error_log("[SECURITY] Identity swap hold {$holdId} claim PIN locked after {$attempts} failed attempts");
    }
}
 
private function recordFailedAccountPinAttempt(int $userId, int $currentAttempts): void
{
    $attempts = $currentAttempts + 1;
    $maxAttempts = 5;
    $lockUntil = $attempts >= $maxAttempts ? date('Y-m-d H:i:s', strtotime('+30 minutes')) : null;
 
    $stmt = $this->swapDB->prepare("
        UPDATE users
        SET transaction_pin_attempts = :attempts, transaction_pin_locked_until = :lock
        WHERE user_id = :id
    ");
    $stmt->execute([':attempts' => $attempts, ':lock' => $lockUntil, ':id' => $userId]);
 
    if ($lockUntil) {
        error_log("[SECURITY] User {$userId} transaction PIN locked after {$attempts} failed attempts");
    }
}
 
/**
 * Sets/replaces a user's personal transaction PIN. Called from the
 * new set_pin.php endpoint. Requires the user to already be
 * authenticated (session) - this does not verify identity itself,
 * the login session already did that.
 */
public function setUserTransactionPin(int $userId, string $pin): void
{
    if (!preg_match('/^\d{4,6}$/', $pin)) {
        throw new RuntimeException("PIN must be 4-6 digits.");
    }
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $stmt = $this->swapDB->prepare("
        UPDATE users
        SET transaction_pin_hash = :hash, transaction_pin_set_at = NOW(),
            transaction_pin_attempts = 0, transaction_pin_locked_until = NULL
        WHERE user_id = :id
    ");
    $stmt->execute([':hash' => $hash, ':id' => $userId]);
}
 
/**
 * Returns pending identity swaps for every VERIFIED identity a given
 * user owns - used by the new pending_claims.php endpoint to power
 * the "money waiting for you" banner in user_dashboard.php.
 */
public function getPendingClaimsForUser(int $userId): array
{
    $stmt = $this->swapDB->prepare("
        SELECT identity_type, identity_value FROM user_identities
        WHERE user_id = :id AND status = 'verified'
    ");
    $stmt->execute([':id' => $userId]);
    $identities = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
    $allPending = [];
    foreach ($identities as $identity) {
        $pending = $this->getPendingIdentitySwaps($identity['identity_type'], $identity['identity_value'], 'pending');
        foreach ($pending as $swap) {
            $swap['claim_type'] = $swap['claim_type'] ?? 'account_pin';
            $allPending[] = $swap;
        }
    }
    return $allPending;
}



    private function getIdentityAccessMethods(string $identityType, string $identityValue): array
    {
        $methods = [];
        
        $methods[] = [
            'type' => 'user_dashboard',
            'requires' => 'User must be registered and logged in',
            'verification' => 'User must own the identity'
        ];
        
        if ($this->isAgentVerifiableIdentityType($identityType)) {
            $methods[] = [
                'type' => 'agent_portal',
                'requires' => 'Agent must have access to VouchMorph Agent Portal',
                'verification' => "Agent must verify physical {$identityType}"
            ];
        }
        
        return $methods;
    }

    /* =================================================================
 * EDIT 1 — REPLACE storeCashoutAuthorization()'s signature and INSERT
 * to accept and persist the real source identifier + type, generic
 * across ACCOUNT/WALLET/VOUCHER/CARD - not hardcoded to "wallet".
 * ================================================================= */
 
private function storeCashoutAuthorization(
    string $swapReference,
    ?string $clientPhone,
    string $sourceInstitution,
    ?string $sourceIdentifier,
    ?string $sourceIdentifierType,
    string $destinationInstitution,
    float $amount,
    float $feeAmount,
    float $generateCodeFeeAmount,
    float $levyAmount,
    ?string $swapCode,
    string $pinCode,
    string $codeExpiry
): int {
    $sql = "
        INSERT INTO cashout_authorizations (
            swap_reference,
            client_phone,
            source_institution,
            source_identifier,
            source_identifier_type,
            amount,
            currency,
            fee_amount,
            generate_code_fee_amount,
            levy_amount,
            swap_code,
            pin_code,
            code_expiry,
            cashout_point,
            cashout_provider,
            status,
            created_at,
            updated_at
        ) VALUES (
            :swap_ref,
            :client_phone,
            :source_inst,
            :source_identifier,
            :source_identifier_type,
            :amount,
            :currency,
            :fee_amount,
            :generate_code_fee_amount,
            :levy_amount,
            :swap_code,
            :pin_code,
            :code_expiry,
            :cashout_point,
            :cashout_provider,
            'PENDING',
            NOW(),
            NOW()
        ) RETURNING auth_id
    ";
 
    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':swap_ref' => $swapReference,
            ':client_phone' => $clientPhone,
            ':source_inst' => $sourceInstitution,
            ':source_identifier' => $sourceIdentifier,
            ':source_identifier_type' => $sourceIdentifierType,
            ':amount' => $amount,
            ':currency' => $this->config['currency'] ?? 'BWP',
            ':fee_amount' => $feeAmount,
            ':generate_code_fee_amount' => $generateCodeFeeAmount,
            ':levy_amount' => $levyAmount,
            ':swap_code' => $swapCode,
            ':pin_code' => $pinCode,
            ':code_expiry' => $codeExpiry,
            ':cashout_point' => 'ATM',
            ':cashout_provider' => $destinationInstitution
        ]);
 
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $authId = $row ? (int)$row['auth_id'] : 0;
 
        error_log("[SwapService] Cashout authorization stored: auth_id={$authId}, source={$sourceIdentifierType}:{$sourceIdentifier}, generate_code_fee={$generateCodeFeeAmount}, levy={$levyAmount}");
 
        return $authId;
 
    } catch (PDOException $e) {
        error_log("[SwapService] Failed to store cashout authorization: " . $e->getMessage());
        throw new RuntimeException("Failed to store cashout authorization: " . $e->getMessage());
    }
}


    private function getCashoutAuthorization(?string $swapRef, ?int $authId): ?array
    {
        $sql = "
            SELECT * FROM cashout_authorizations 
            WHERE (swap_reference = :swap_ref OR auth_id = :auth_id)
            AND status IN ('PENDING', 'VERIFIED')
            AND code_expiry > NOW()
            ORDER BY created_at DESC LIMIT 1
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':swap_ref' => $swapRef,
                ':auth_id' => $authId
            ]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to get cashout authorization: " . $e->getMessage());
            return null;
        }
    }

    private function updateCashoutAuthorizationStatus(int $authId, string $status, ?string $cashoutPoint = null): void
    {
        $sql = "
    UPDATE cashout_authorizations 
    SET status = :status::text,
        updated_at = NOW(),
        completed_at = CASE WHEN :status::text = 'COMPLETED' THEN NOW() ELSE completed_at END,
        cashout_point = COALESCE(:cashout_point, cashout_point)
    WHERE auth_id = :auth_id
";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':auth_id' => $authId,
                ':cashout_point' => $cashoutPoint
            ]);
            error_log("[SwapService] Cashout authorization {$authId} status updated to: {$status}");
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to update cashout authorization: " . $e->getMessage());
        }
    }

    // ============================================================================
    // SUPPORTING METHODS FOR CONFIRM CASHOUT
    // ============================================================================

    /**
 * Find authorization by swap reference, auth_id, or voucher number
 * Source of truth for amount/institution - NOT from webhook payload
 */
private function findAuthorization(string $swapReference = null, int $authId = null, string $voucherNumber = null): ?array
{
    $sql = "SELECT * FROM cashout_authorizations WHERE 1=1";
    $params = [];

    if ($authId) {
        $sql .= " AND auth_id = :auth_id";
        $params[':auth_id'] = $authId;
    } elseif ($swapReference) {
        $sql .= " AND swap_reference = :swap_ref";
        $params[':swap_ref'] = $swapReference;
    } elseif ($voucherNumber) {
        $sql .= " AND swap_code = :voucher";
        $params[':voucher'] = $voucherNumber;
    }

    $sql .= " ORDER BY created_at DESC LIMIT 1";
    $stmt = $this->swapDB->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

    /**
     * Update hold status for a swap
     */
    private function updateHoldForSwap(string $swapRef, string $status): void
    {
        $sql = "SELECT hold_id FROM hold_transactions WHERE swap_reference = :swap_ref ORDER BY placed_at DESC LIMIT 1";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':swap_ref' => $swapRef]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $this->updateHoldStatus($result['hold_id'], $status);
        }
    }

    /**
     * Update swap request status
     */
    private function updateSwapRequestStatus(string $swapRef, string $status): void
    {
        $sql = "UPDATE swap_requests SET status = :status WHERE swap_uuid = :swap_ref";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':status' => $status, ':swap_ref' => $swapRef]);
    }

    // ============================================================================
    // ATOMIC BOUNDARY METHODS
    // ============================================================================

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
        
        // Process pending remainder AFTER the transaction is committed
        $this->processPendingRemainder();
        
        return $result;
    }

    /**
     * Process pending remainder swap after atomic transaction commits
     */
    private function processPendingRemainder(): void
    {
        if (empty($this->pendingRemainder)) {
            return;
        }

        $remainder = $this->pendingRemainder;
        
        try {
            error_log("[SwapService] Processing pending remainder for {$remainder['swap_reference']}: {$remainder['amount']}");

            $result = $this->executeAtomicSwap([
                'swap_type' => 'IDENTITY',
                'reference' => $remainder['swap_reference'] . '_REMAIN_' . time(),
                'from_institution' => $remainder['from_institution'],
                'source_institution' => $remainder['from_institution'],
                'source_identifier' => $remainder['source_identifier'],
                'source_identifier_type' => $remainder['source_identifier_type'],
                'asset_type' => $remainder['asset_type'],
                'amount' => $remainder['amount'],
                'currency' => $remainder['currency'],
                'identity_type' => $remainder['identity_type'],
                'identity_value' => $remainder['identity_value'],
                'beneficiary_phone' => $remainder['beneficiary_phone'],
                'notification_phone' => $remainder['beneficiary_phone'],
            ]);

            error_log("[SwapService] Remainder reswap completed: " . json_encode($result));
            
            // Clear pending remainder
            $this->pendingRemainder = [];
            
        } catch (Exception $e) {
            error_log("[SwapService] ERROR processing remainder for {$remainder['swap_reference']}: " . $e->getMessage());
            // Clear it so we don't retry infinitely
            $this->pendingRemainder = [];
        }
    }

    private function rollbackAtomicSwap(string $reason): array
    {
        $holdReference = $this->currentHoldReference;
        $holdInstitution = $this->currentHoldInstitution;
        $swapRef = $this->currentSwapRef;

        $releaseResult = null;
        if ($holdReference && $holdInstitution) {
            try {
                $adapter = $this->adapterFactory->getAdapter($holdInstitution);
                $releaseResult = $adapter->releaseHold([
                    'hold_reference' => $holdReference,
                    'action' => 'RELEASE_HOLD',
                    'reason' => 'Atomic swap rolled back: ' . $reason
                ], [
                    'swap_reference' => $swapRef,
                    'institution' => $holdInstitution
                ]);

                $this->logger->info("Released real hold during rollback", [
                    'reference' => $swapRef,
                    'hold_reference' => $holdReference,
                    'institution' => $holdInstitution,
                    'release_success' => $releaseResult['released'] ?? false
                ]);
            } catch (Exception $releaseError) {
                $this->logger->error("Failed to release real hold during rollback - hold may be stuck at institution", [
                    'reference' => $swapRef,
                    'hold_reference' => $holdReference,
                    'institution' => $holdInstitution,
                    'release_error' => $releaseError->getMessage()
                ]);
            }
        } elseif ($this->currentHoldId) {
            $this->logger->warning("Rollback has a local hold_id but no hold_reference/institution to release externally", [
                'reference' => $swapRef,
                'hold_id' => $this->currentHoldId
            ]);
        }

        $this->swapDB->rollBack();

        if ($holdReference) {
            try {
                $this->swapDB->exec("
                    CREATE TABLE IF NOT EXISTS swap_rollback_log (
                        id BIGSERIAL PRIMARY KEY,
                        swap_reference VARCHAR(255),
                        hold_reference VARCHAR(255),
                        institution VARCHAR(100),
                        reason TEXT,
                        release_attempted BOOLEAN DEFAULT FALSE,
                        release_succeeded BOOLEAN DEFAULT FALSE,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ");
                $stmt = $this->swapDB->prepare("
                    INSERT INTO swap_rollback_log
                        (swap_reference, hold_reference, institution, reason, release_attempted, release_succeeded)
                    VALUES
                        (:swap_ref, :hold_ref, :institution, :reason, :attempted, :succeeded)
                ");
                $stmt->execute([
                    ':swap_ref' => $swapRef,
                    ':hold_ref' => $holdReference,
                    ':institution' => $holdInstitution,
                    ':reason' => $reason,
                    ':attempted' => $releaseResult !== null ? 1 : 0,
                    ':succeeded' => ($releaseResult['released'] ?? false) ? 1 : 0
                ]);
            } catch (Exception $logError) {
                error_log("[SwapService] Failed to write rollback audit log: " . $logError->getMessage());
            }
        }

        $result = [
            'status' => 'rolled_back',
            'reference' => $swapRef,
            'reason' => $reason,
            'hold_released' => $releaseResult['released'] ?? null,
            'hold_reference' => $holdReference
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
        $this->currentHoldInstitution = null;
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

    private function assertStepIntegrity(array $result, string $successKey, array $requiredFields, string $stepName): void
    {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (empty($result[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            $msg = "{$stepName} reported {$successKey}=true but is missing required proof field(s): " . implode(', ', $missing);
            error_log("[SwapService] INTEGRITY CHECK FAILED: {$msg}");
            $this->logger->error($msg, ['step' => $stepName, 'result' => $result]);
            throw new RuntimeException($msg);
        }
    }

    private function createLocalHold(array $payload, string $institution, ?string $externalHoldRef): int
    {
        $sourceId = $this->extractSourceIdentifier($payload);
        
        $sourceDetails = [
            'user_id' => $payload['user_id'] ?? 0, 
            'source_identifier' => $sourceId['identifier'],
            'source_identifier_type' => $sourceId['type'],
            'source_institution' => $institution,
            'asset_type' => $payload['asset_type'] ?? 'ACCOUNT',
            'original_payload' => $payload,
            'is_hooked' => $sourceId['is_hooked'] ?? false
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
        
        $validStatuses = ['ACTIVE', 'HELD', 'PENDING_CASHOUT', 'DEBITED', 'RELEASED', 'PARTIALLY_RELEASED', 'CANCELLED', 'FAILED', 'PENDING_IDENTITY'];
        if (!in_array($status, $validStatuses)) return;
        
        $sql = "
            UPDATE hold_transactions 
            SET status = :status::text,
                debited_at = CASE WHEN :status::text = 'DEBITED' THEN NOW() ELSE debited_at END,
                released_at = CASE WHEN :status::text = 'RELEASED' THEN NOW() ELSE released_at END,
                updated_at = NOW()
            WHERE hold_id = :hold_id
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':status' => $status, ':hold_id' => $holdId]);
            error_log("[SwapService] Hold status updated to: {$status} for hold_id: {$holdId}");
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to update hold status: " . $e->getMessage());
        }
    }

private function updateHoldExpiry(?int $holdId, string $expiresAt): void
{
    if ($holdId === null) return;
    try {
        $stmt = $this->swapDB->prepare("
            UPDATE hold_transactions SET expires_at = :expires_at, updated_at = NOW()
            WHERE hold_id = :id
        ");
        $stmt->execute([':expires_at' => $expiresAt, ':id' => $holdId]);
        error_log("[SwapService] Hold {$holdId} expiry set to {$expiresAt}");
    } catch (PDOException $e) {
        error_log("[SwapService] Failed to update hold expiry: " . $e->getMessage());
    }
}

    
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
        $this->logger->warning("loadConfiguration() called but deprecated - config loaded from LoadCountry");
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

    // ============================================================================
    // PUBLIC METHODS
    // ============================================================================

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

    public function getParticipantId(string $institution): int
    {
        foreach ($this->participants as $code => $participant) {
            if (strtoupper($code) === strtoupper($institution)) {
                $id = $participant['id'] ?? 0;
                if (is_string($id) && !is_numeric($id)) {
                    return abs(crc32($id) % 1000000);
                }
                return (int)$id;
            }
        }
        return 0;
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

    public function getSourceAvailableBalance(array $source): float
    {
        try {
            $adapter = $this->adapterFactory->getAdapter($source['institution']);
            
            $payload = [
                'action' => 'GET_BALANCE',
                'asset_type' => $source['asset_type'] ?? 'ACCOUNT',
                'source_identifier' => $source['identifier']
            ];
            
            $result = $adapter->getBalance($payload, [
                'source' => $source,
                'institution' => $source['institution']
            ]);
            return (float)($result['balance'] ?? $result['data']['balance'] ?? 0);
        } catch (Exception $e) {
            $this->logger->warning("Failed to get balance for source", [
                'source' => $source['institution'],
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    public function getMultiSourceStatus(string $poolId): array
    {
        if ($this->multiSourceOrchestrator === null) {
            return [
                'success' => false,
                'message' => 'Multi-source swaps are not enabled'
            ];
        }
        
        try {
            return $this->multiSourceOrchestrator->getStatus($poolId);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error getting pool status: ' . $e->getMessage()
            ];
        }
    }

    public function cancelMultiSourcePool(string $poolId, string $reason): array
    {
        if ($this->multiSourceOrchestrator === null) {
            return [
                'success' => false,
                'message' => 'Multi-source swaps are not enabled'
            ];
        }
        
        try {
            return $this->multiSourceOrchestrator->cancel($poolId, $reason);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error cancelling pool: ' . $e->getMessage()
            ];
        }
    }
}
