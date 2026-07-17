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
        
        $smsConfig = $this->participants['sms'] ?? [];
        if (!empty($smsConfig)) {
            $this->smsService = new SmsNotificationService($smsConfig);
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
            // 1. Always populate swap_requests
            $this->populateSwapRequest($swapRef, $swapData, $details, $userId);
            
            // 2. Always populate swap_transactions
            $this->populateSwapTransaction($swapRef, $swapData, $details, $userId);
            
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
     * Populate swap_requests table
     * FIX: Removed updated_at = NOW() from ON CONFLICT since column doesn't exist
     */
    private function populateSwapRequest(string $swapRef, array $swapData, array $details, ?int $userId = null): void
    {
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
                retry_count
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
                0
            ) ON CONFLICT (swap_uuid) DO UPDATE SET
                status = EXCLUDED.status
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
                    'source_institution' => $details['source_institution'] ?? $swapData['from_institution'] ?? null,
                    'user_id' => $userId
                ])
            ]);
            
            $this->logger->debug("swap_requests populated", ['swap_uuid' => $swapRef]);
            
        } catch (PDOException $e) {
            $this->logger->error("Failed to populate swap_requests", ['error' => $e->getMessage(), 'swap_ref' => $swapRef]);
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

/**
 * Populate swap_transactions table
 * FIX: Uses numeric swap_id from swap_requests, not string reference
 */
private function populateSwapTransaction(string $swapRef, array $swapData, array $details, ?int $userId = null): void
{
    // Get the numeric swap_id from swap_requests
    $swapId = $this->getSwapRequestId($swapRef);
    if (!$swapId) {
        $this->logger->warning("No swap_request found for reference, skipping swap_transactions", ['swap_ref' => $swapRef]);
        return;
    }
    
    $sql = "
        INSERT INTO swap_transactions (
            swap_id,
            from_account_details,
            to_account_details,
            amount,
            status,
            created_at,
            updated_at,
            metadata
        ) VALUES (
            :swap_id,
            :from_account_details::jsonb,
            :to_account_details::jsonb,
            :amount,
            :status,
            :created_at,
            :updated_at,
            :metadata::jsonb
        )
    ";
    
    $status = $swapData['status'] ?? 'pending';
    if (isset($details['status'])) {
        $status = $details['status'];
    }
    
    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':swap_id' => $swapId,  // Now using integer ID
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
                'user_id' => $userId
            ])
        ]);
        
        $this->logger->debug("swap_transactions populated", ['swap_id' => $swapId, 'swap_ref' => $swapRef]);
        
    } catch (PDOException $e) {
        $this->logger->error("Failed to populate swap_transactions", ['error' => $e->getMessage(), 'swap_ref' => $swapRef]);
    }
}

    /**
     * Populate cashout_authorization table
     * FIX: Added userId to metadata
     */
    private function populateCashoutAuthorization(string $swapRef, array $swapData, array $details, ?array $destResponse, ?int $userId = null): void
    {
        if (!$destResponse) {
            return;
        }
        
        $sql = "
            INSERT INTO cashout_authorization (
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
                metadata
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
                :metadata::jsonb
            ) ON CONFLICT (swap_reference) DO UPDATE SET
                status = EXCLUDED.status,
                updated_at = NOW(),
                completed_at = CASE WHEN EXCLUDED.status = 'COMPLETED' THEN NOW() ELSE completed_at END
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
                    'destination_response' => $destResponse,
                    'user_id' => $userId
                ])
            ]);
            
            $this->logger->debug("cashout_authorization populated", ['swap_ref' => $swapRef]);
            
        } catch (PDOException $e) {
            $this->logger->error("Failed to populate cashout_authorization", ['error' => $e->getMessage(), 'swap_ref' => $swapRef]);
        }
    }

    /**
     * Populate deposit_transactions table
     * FIX: Added userId to metadata
     */
    private function populateDepositTransaction(string $swapRef, array $swapData, array $details, ?int $userId = null): void
    {
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
                metadata
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
                :metadata::jsonb
            ) ON CONFLICT (transaction_reference) DO UPDATE SET
                status = EXCLUDED.status,
                updated_at = NOW(),
                completed_at = CASE WHEN EXCLUDED.status = 'COMPLETED' THEN NOW() ELSE completed_at END
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
                ':client_phone' => $details['client_phone'] ?? $details['beneficiary_phone'] ?? null,
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
                ':metadata' => json_encode([
                    'source' => 'swap_service',
                    'hold_id' => $this->currentHoldId,
                    'user_id' => $userId
                ])
            ]);
            
            $this->logger->debug("deposit_transactions populated", ['tx_ref' => $swapRef]);
            
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
                sent_at
            ) VALUES (
                'SMS',
                :destination,
                :payload::jsonb,
                'queued',
                :created_at,
                NULL
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
                ':created_at' => date('Y-m-d H:i:s')
            ]);
            
            $this->logger->debug("message_outbox populated", ['destination' => $phone, 'swap_ref' => $swapRef]);
            
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
        
        $authId = $this->storeCashoutAuthorization(
            $this->currentSwapRef,
            $beneficiaryPhone,
            $sourceInstitution,
            $destinationInstitution,
            $amountToSend,
            $feeBreakdown['total_fee'] ?? 0,
            $generateResult['voucher_number'] ?? $generateResult['swap_code'],
            $generateResult['atm_pin'],
            $generateResult['expires_at']
        );
        
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
        
        $this->updateHoldStatus($this->currentHoldId, 'PENDING_CASHOUT');
        
        $this->populateTrackingTables(
            [
                'swap_type' => 'CASHOUT',
                'reference' => $this->currentSwapRef,
                'amount' => $amountToSend,
                'currency' => $payload['currency'] ?? 'BWP',
                'status' => 'pending_cashout',
                'from_institution' => $sourceInstitution,
                'to_institution' => $destinationInstitution,
                'user_id' => $payload['user_id'] ?? null
            ],
            $payload,
            $generateResult
        );
        
        return [
            'status' => 'pending_cashout',
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

    // ============================================================================
    // EXECUTE SIGNED DEPOSIT - UPDATED WITH TABLE POPULATION
    // ============================================================================

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

        } catch (Exception $e) {
            error_log("[SwapService] initiateSwapToIdentity FAILED: " . $e->getMessage());
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
        
        if ($confirmedByType === 'user') {
            $this->verifyUserOwnsIdentity($confirmedById, $identityType, $identityValue);
        } elseif ($confirmedByType === 'agent') {
            if (!$this->isAgentVerifiableIdentityType($identityType)) {
                throw new RuntimeException("Agents can only confirm document-based identity types (" . implode(', ', self::IDENTITY_TYPES_AGENT_VERIFIABLE) . "), not {$identityType}");
            }
            $verified = ($payload['identity_document_verified'] ?? null) === true
                || ($payload['national_id_verified'] ?? null) === true;
            if (!$verified) {
                throw new RuntimeException("Agent must verify the physical {$identityType} first");
            }
        } else {
            throw new RuntimeException("confirmed_by_type must be 'user' or 'agent'");
        }
        
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
            
        } catch (Exception $e) {
            error_log("[SwapService] confirmAndFinalizeIdentitySwap FAILED: " . $e->getMessage());
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
        
        return $this->executeSignedDeposit($depositPayload);
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
                    $adapter = $this->adapterFactory->getAdapter($swap['source_institution']);
                    
                    $releaseResult = $adapter->releaseHold([
                        'hold_reference' => $swap['hold_reference'],
                        'action' => 'RELEASE_HOLD',
                        'reason' => 'Identity swap expired after 24 hours'
                    ], []);
                    
                    $this->updateIdentityHoldStatus($swap['hold_id'], 'expired', [
                        'release_result' => $releaseResult,
                        'expired_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    $this->updateHoldStatus($swap['hold_id'], 'RELEASED');
                    
                    $results['cancelled']++;
                    $results['details'][] = [
                        'swap_reference' => $swap['swap_reference'],
                        'hold_id' => $swap['hold_id'],
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

    public function confirmCashout(array $payload): array
    {
        error_log("[SwapService] ===== confirmCashout START =====");
        
        $swapReference = $payload['swap_reference'] ?? null;
        $authId = $payload['auth_id'] ?? null;
        $code = $payload['code'] ?? null;
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        $cashoutPoint = $payload['cashout_point'] ?? 'ATM';
        
        if (!$swapReference && !$authId) {
            throw new RuntimeException("Swap reference or auth_id required");
        }
        
        if (!$destinationInstitution) {
            throw new RuntimeException("Destination institution required for confirmation");
        }
        
        $authorization = $this->getCashoutAuthorization($swapReference, $authId);
        
        if (!$authorization) {
            throw new RuntimeException("No pending cashout authorization found");
        }
        
        $sourceInstitution = $authorization['source_institution'];
        $amountToSend = (float)$authorization['amount'];
        $feeAmount = (float)$authorization['fee_amount'];
        
        $confirmPayload = [
            'reference' => $swapReference,
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
        
        $destAdapter = $this->adapterFactory->getAdapter($destinationInstitution);
        $confirmResult = $destAdapter->confirmCashout($confirmPayload, [
            'swap_reference' => $swapReference,
            'auth_id' => $authId,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $destinationInstitution,
            'amount' => $amountToSend,
            'cashout_point' => $cashoutPoint
        ]);
        
        if (!($confirmResult['confirmed'] ?? false)) {
            throw new RuntimeException("Cashout not confirmed by destination institution");
        }
        
        error_log("[SwapService] Cashout confirmed, debiting source: {$sourceInstitution}");
        
        $debitPayload = [
            'reference' => $swapReference,
            'hold_reference' => $authorization['swap_reference'],
            'amount' => $amountToSend + $feeAmount,
            'reason' => 'Cashout completed successfully',
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution
        ];
        
        $debitResult = $this->debitSource($debitPayload, $sourceInstitution);
        
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed: " . ($debitResult['message'] ?? 'Unknown error'));
        }
        
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        $this->updateCashoutAuthorizationStatus($authorization['auth_id'], 'COMPLETED', $cashoutPoint);
        
        $settlementResult = $this->settlement->updateNetPosition(
            $swapReference,
            $sourceInstitution,
            $destinationInstitution,
            $amountToSend,
            'CASHOUT_COMPLETED',
            $this->config['currency'] ?? 'BWP'
        );
        
        $this->settlement->invoiceFee(
            $swapReference,
            $sourceInstitution,
            $this->getParticipantId($sourceInstitution),
            'CASHOUT_COMPLETION_FEE',
            $feeAmount,
            $this->config['currency'] ?? 'BWP'
        );
        
        return [
            'status' => 'completed',
            'reference' => $swapReference,
            'auth_id' => $authorization['auth_id'],
            'message' => 'Cashout completed successfully',
            'amount' => $amountToSend,
            'fee' => $feeAmount,
            'client_refund' => 0,
            'settlement' => $settlementResult
        ];
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
        
        $sql = "
            INSERT INTO identity_swap_holds (
                swap_reference,
                source_institution,
                source_identifier,
                source_asset_type,
                amount,
                currency,
                identity_type,
                identity_value,
                hold_reference,
                hold_id,
                hold_expires_at,
                status,
                source_payload,
                metadata,
                created_by
            ) VALUES (
                :swap_ref,
                :source_institution,
                :source_identifier,
                :asset_type,
                :amount,
                :currency,
                :identity_type,
                :identity_value,
                :hold_reference,
                :hold_id,
                :expires_at,
                'pending',
                :source_payload::jsonb,
                :metadata::jsonb,
                :created_by
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
                ':identity_type' => $payload['identity_type'],
                ':identity_value' => $payload['identity_value'],
                ':hold_reference' => $holdResult['hold_reference'],
                ':hold_id' => $holdId,
                ':expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                ':source_payload' => json_encode($payload),
                ':metadata' => json_encode([
                    'signed_payloads' => $this->signedPayloads,
                    'hold_result' => $holdResult
                ]),
                ':created_by' => $payload['user_id'] ?? null
            ]);
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['hold_id'] : 0;
            
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to store identity hold: " . $e->getMessage());
            throw new RuntimeException("Failed to store identity hold: " . $e->getMessage());
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

    private function verifyUserOwnsIdentity(int $userId, string $identityType, string $identityValue): void
    {
        try {
            $stmt = $this->swapDB->prepare("SELECT to_regclass('user_identities')");
            $stmt->execute();
            $tableExists = $stmt->fetchColumn();
            
            if (!$tableExists) {
                error_log("[SwapService] user_identities table not found - skipping identity check");
                return;
            }
        } catch (Exception $e) {
            error_log("[SwapService] user_identities table check failed - skipping");
            return;
        }
        
        $sql = "
            SELECT COUNT(*) as count 
            FROM user_identities 
            WHERE user_id = :user_id 
            AND identity_type = :identity_type 
            AND identity_value = :identity_value
        ";
        
        try {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':identity_type' => $identityType,
                ':identity_value' => $identityValue
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (($result['count'] ?? 0) > 0) {
                error_log("[SwapService] User {$userId} verified owns identity {$identityType}:{$identityValue}");
            } else {
                error_log("[SwapService] User {$userId} does NOT own identity {$identityType}:{$identityValue} - can use AGENT route");
            }
            
        } catch (PDOException $e) {
            error_log("[SwapService] Failed to verify user identity: " . $e->getMessage());
        }
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

    // ============================================================================
    // CASHOUT AUTHORIZATION METHODS
    // ============================================================================

    private function storeCashoutAuthorization(
        string $swapReference,
        ?string $clientPhone,
        string $sourceInstitution,
        string $destinationInstitution,
        float $amount,
        float $feeAmount,
        ?string $swapCode,
        string $pinCode,
        string $codeExpiry
    ): int {
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
                updated_at
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
                ':source_wallet' => null,
                ':amount' => $amount,
                ':currency' => $this->config['currency'] ?? 'BWP',
                ':fee_amount' => $feeAmount,
                ':swap_code' => $swapCode,
                ':pin_code' => $pinCode,
                ':code_expiry' => $codeExpiry,
                ':cashout_point' => 'ATM',
                ':cashout_provider' => $destinationInstitution
            ]);
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $authId = $row ? (int)$row['auth_id'] : 0;
            
            error_log("[SwapService] Cashout authorization stored: auth_id={$authId}");
            
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
            SET status = :status,
                updated_at = NOW(),
                completed_at = CASE WHEN :status = 'COMPLETED' THEN NOW() ELSE completed_at END,
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
        return $result;
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
        
        $validStatuses = ['ACTIVE', 'HELD', 'PENDING_CASHOUT', 'DEBITED', 'RELEASED', 'CANCELLED', 'FAILED', 'PENDING_IDENTITY'];
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
            return (float)($result['data']['balance'] ?? 0);
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
