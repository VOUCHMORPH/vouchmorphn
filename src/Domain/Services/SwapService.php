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
 * 
 * IDENTITY PIN FLOW:
 * - Each hold gets its own unique PIN (sent via SMS)
 * - PIN is the "GREEN LIGHT" - one PIN verification authorizes the ENTIRE identity
 * - After PIN verification, ALL holds for that identity are marked as "authorized"
 * - Authorization expires after 1 hour (security)
 * - This allows agents to finalize multiple holds with one PIN entry
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
        
        // ✅ STRICT: Load atm_notes with NO fallbacks
        $this->loadAtmNotesStrict($country);
        
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

public function getUserSourceAccounts(int $userId): array
{
    $stmt = $this->swapDB->prepare("
        SELECT id, institution, asset_type, identifier, identifier_type, account_name, currency, status, confirmed_at, last_used_at
        FROM user_source_accounts WHERE user_id = :user_id AND deleted_at IS NULL ORDER BY institution
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
    
   public function getHookedSources(int $userId): array
{
    $sql = "SELECT * FROM source_accounts 
            WHERE user_id = :user_id 
            AND status = 'active' 
            AND is_active = true 
            AND deleted_at IS NULL";
    $stmt = $this->swapDB->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sources as &$source) {
        // Decrypt the tokens for use
        if (!empty($source['access_token'])) {
            $source['access_token'] = $this->decryptSourceSecret($source['access_token']);
        }
        if (!empty($source['refresh_token'])) {
            $source['refresh_token'] = $this->decryptSourceSecret($source['refresh_token']);
        }
        
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
    $sql = "SELECT * FROM source_accounts 
            WHERE source_reference = :source_ref 
            AND user_id = :user_id 
            AND deleted_at IS NULL";
    $stmt = $this->swapDB->prepare($sql);
    $stmt->execute([':source_ref' => $sourceReference, ':user_id' => $userId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$source) {
        throw new RuntimeException("Source not found");
    }
    
    // Decrypt refresh token before using
    $refreshToken = $this->decryptSourceSecret($source['refresh_token']);
    if (!$refreshToken) {
        throw new RuntimeException("Invalid refresh token");
    }
    
    $adapter = $this->adapterFactory->getAdapter($source['institution']);
    $result = $adapter->refreshSourceToken(['refresh_token' => $refreshToken]);
    
    if (!$result['success']) {
        throw new RuntimeException("Failed to refresh token: " . ($result['message'] ?? 'Unknown error'));
    }
    
    // Encrypt the new tokens
    $encryptedToken = $this->encryptSourceSecret($result['access_token']);
    
    $stmt = $this->swapDB->prepare("
        UPDATE source_accounts 
        SET access_token = :access_token, 
            token_expires_at = :expires_at, 
            updated_at = NOW() 
        WHERE source_reference = :source_ref
    ");
    $stmt->execute([
        ':access_token' => $encryptedToken,
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
    $sql = "SELECT * FROM user_source_accounts WHERE source_reference = :source_ref AND user_id = :user_id";
    $stmt = $this->swapDB->prepare($sql);
    $stmt->execute([':source_ref' => $sourceReference, ':user_id' => $userId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) {
        throw new RuntimeException("Source not found");
    }
    $adapter = $this->adapterFactory->getAdapter($source['institution']);
    $adapter->revokeSourceToken(['token' => $source['access_token']]);
    $stmt = $this->swapDB->prepare("UPDATE user_source_accounts SET status = 'revoked', deleted_at = NOW() WHERE source_reference = :source_ref");
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
// PENDING SOURCES MANAGEMENT - GET, DELETE, RETRY
// ============================================================================

// ============================================================================
// PENDING SOURCES MANAGEMENT - GET, DELETE, RETRY
// ============================================================================

/**
 * Get all pending sources for a user
 * Includes user_source_accounts, agent_destination_accounts, and registration attempts
 */
public function getPendingSources(int $userId): array
{
    $sources = [];
    
    // 1. Pending user source accounts
    $stmt = $this->swapDB->prepare("
        SELECT 
            id,
            institution,
            asset_type,
            identifier,
            identifier_type,
            account_name,
            currency,
            source_reference,
            status,
            proposed_at as created_at,
            'user_source' as type
        FROM user_source_accounts
        WHERE user_id = :user_id 
        AND status IN ('pending_confirmation', 'pending', 'proposed', 'failed')
        AND deleted_at IS NULL
        ORDER BY proposed_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // 2. Pending agent destination accounts
    $stmt = $this->swapDB->prepare("
        SELECT 
            id,
            institution,
            asset_type,
            identifier,
            identifier_type,
            account_name,
            account_type,
            status,
            proposed_at as created_at,
            'agent_destination' as type
        FROM agent_destination_accounts
        WHERE user_id = :user_id 
        AND status IN ('pending_confirmation', 'pending', 'proposed', 'failed')
        AND deleted_at IS NULL
        ORDER BY proposed_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // 3. Pending registration attempts (OTP/OAuth in progress)
    $stmt = $this->swapDB->prepare("
        SELECT 
            id,
            institution,
            asset_type,
            identifier,
            identifier_type,
            account_name,
            status,
            otp_method,
            otp_expires_at,
            created_at,
            'registration_attempt' as type
        FROM user_source_registration_attempts
        WHERE user_id = :user_id 
        AND status IN ('otp_pending', 'oauth_pending')
        ORDER BY created_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // 4. Pending agent registration attempts
    $stmt = $this->swapDB->prepare("
        SELECT 
            id,
            institution,
            asset_type,
            identifier,
            identifier_type,
            account_name,
            account_type,
            status,
            otp_method,
            otp_expires_at,
            created_at,
            'agent_attempt' as type
        FROM agent_registration_attempts
        WHERE user_id = :user_id 
        AND status IN ('otp_pending', 'oauth_pending')
        ORDER BY created_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // Add institution names and check expiry
    foreach ($sources as &$source) {
        $source['institution_name'] = $this->participants[$source['institution']]['name'] ?? $source['institution'];
        
        // Check if OTP is about to expire
        if (!empty($source['otp_expires_at'])) {
            $expiryTime = strtotime($source['otp_expires_at']);
            $source['is_expiring'] = ($expiryTime - time()) < 60; // Less than 1 minute
            $source['expires_at'] = $source['otp_expires_at'];
        } elseif (!empty($source['created_at'])) {
            // Check if created more than 3 minutes ago (auto-expire)
            $createdTime = strtotime($source['created_at']);
            $source['is_expiring'] = (time() - $createdTime) > 150; // More than 2.5 minutes
        }
    }
    
    return $sources;
}

/**
 * Delete a pending source (soft delete)
 */
public function deletePendingSource(int $userId, string $type, int $sourceId): array
{
    $table = $this->getPendingSourceTable($type);
    $idColumn = $this->getPendingSourceIdColumn($type);
    
    // Check ownership
    $stmt = $this->swapDB->prepare("
        SELECT id, status, institution, identifier FROM {$table}
        WHERE {$idColumn} = :id AND user_id = :user_id AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $sourceId, ':user_id' => $userId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$source) {
        throw new RuntimeException("Source not found or does not belong to you.");
    }
    
    // Soft delete
    $stmt = $this->swapDB->prepare("
        UPDATE {$table}
        SET status = 'cancelled',
            deleted_at = NOW(),
            updated_at = NOW()
        WHERE {$idColumn} = :id AND user_id = :user_id
    ");
    $stmt->execute([':id' => $sourceId, ':user_id' => $userId]);
    
    // Also cancel any pending attempts for this source
    if ($type === 'user_source' || $type === 'agent_destination') {
        $this->cancelPendingAttemptsBySource($userId, $source['institution'] ?? '', $source['identifier'] ?? '');
    }
    
    return ['success' => true, 'message' => 'Source deleted successfully.'];
}

/**
 * Retry a failed/cancelled source
 */
public function retryPendingSource(int $userId, string $type, int $sourceId): array
{
    $table = $this->getPendingSourceTable($type);
    $idColumn = $this->getPendingSourceIdColumn($type);
    
    // Get the source details
    $stmt = $this->swapDB->prepare("
        SELECT * FROM {$table}
        WHERE {$idColumn} = :id AND user_id = :user_id AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $sourceId, ':user_id' => $userId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$source) {
        throw new RuntimeException("Source not found or does not belong to you.");
    }
    
    if (!in_array($source['status'], ['cancelled', 'rejected', 'failed'])) {
        throw new RuntimeException("This source cannot be retried (status: {$source['status']}).");
    }
    
    // Reset the status
    $stmt = $this->swapDB->prepare("
        UPDATE {$table}
        SET status = 'pending_confirmation',
            deleted_at = NULL,
            updated_at = NOW()
        WHERE {$idColumn} = :id AND user_id = :user_id
    ");
    $stmt->execute([':id' => $sourceId, ':user_id' => $userId]);
    
    // For user_source_accounts, initiate a new verification
    if ($type === 'user_source') {
        $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn.com', '/')
            . '/user/source_oauth_callback.php';
        
        return $this->initiateUserSourceRegistration(
            $userId,
            $source['institution'],
            $source['asset_type'],
            $source['identifier'],
            $source['identifier_type'],
            $source['account_name'] ?? null
        );
    }
    
    // For agent destinations
    if ($type === 'agent_destination') {
        $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn.com', '/')
            . '/api/v1/agent/oauth_callback.php';
        
        return $this->initiateAgentDestinationRegistration(
            $userId,
            $source['institution'],
            $source['asset_type'],
            $source['identifier'],
            $source['identifier_type'],
            $source['account_name'] ?? null
        );
    }
    
    return ['success' => true, 'message' => 'Source retry initiated.', 'status' => 'pending_confirmation'];
}

/**
 * Resend OTP for a pending attempt
 */
public function resendOtpForAttempt(int $userId, int $attemptId): array
{
    // Find the attempt
    $stmt = $this->swapDB->prepare("
        SELECT * FROM user_source_registration_attempts
        WHERE id = :id AND user_id = :user_id AND status = 'otp_pending'
        UNION
        SELECT * FROM agent_registration_attempts
        WHERE id = :id AND user_id = :user_id AND status = 'otp_pending'
    ");
    $stmt->execute([':id' => $attemptId, ':user_id' => $userId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$attempt) {
        throw new RuntimeException("OTP attempt not found or not pending.");
    }
    
    // Generate new OTP
    $otp = $this->generateOtpPin();
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    
    // Determine which table
    $table = isset($attempt['oauth_state']) 
        ? 'user_source_registration_attempts' 
        : 'agent_registration_attempts';
    
    // Update the attempt
    $stmt = $this->swapDB->prepare("
        UPDATE {$table}
        SET otp_expires_at = :expires_at
        WHERE id = :id AND user_id = :user_id
    ");
    $stmt->execute([
        ':expires_at' => date('Y-m-d H:i:s', time() + 600),
        ':id' => $attemptId,
        ':user_id' => $userId
    ]);
    
    // Send the OTP
    if ($this->smsService) {
        try {
            $this->smsService->sendCashoutCode(
                $attempt['identifier'], 
                $otp, 
                0, 
                'VERIFY_' . $attemptId
            );
        } catch (Exception $e) {
            throw new RuntimeException("Failed to send verification code: " . $e->getMessage());
        }
    }
    
    return ['success' => true, 'message' => 'Verification code resent successfully.'];
}

/**
 * Cancel pending attempts by source
 */
private function cancelPendingAttemptsBySource(int $userId, string $institution, string $identifier): void
{
    // Cancel user source attempts
    $stmt = $this->swapDB->prepare("
        UPDATE user_source_registration_attempts
        SET status = 'cancelled', cancelled_at = NOW()
        WHERE user_id = :user_id 
        AND institution = :institution 
        AND identifier = :identifier 
        AND status IN ('otp_pending', 'oauth_pending')
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':institution' => $institution,
        ':identifier' => $identifier
    ]);
    
    // Cancel agent attempts
    $stmt = $this->swapDB->prepare("
        UPDATE agent_registration_attempts
        SET status = 'cancelled'
        WHERE user_id = :user_id 
        AND institution = :institution 
        AND identifier = :identifier 
        AND status IN ('otp_pending', 'oauth_pending')
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':institution' => $institution,
        ':identifier' => $identifier
    ]);
}

/**
 * Get the table name for a source type
 */
private function getPendingSourceTable(string $type): string
{
    return match($type) {
        'user_source' => 'user_source_accounts',
        'agent_destination' => 'agent_destination_accounts',
        'registration_attempt' => 'user_source_registration_attempts',
        'agent_attempt' => 'agent_registration_attempts',
        default => throw new RuntimeException("Unknown source type: {$type}")
    };
}

/**
 * Get the ID column name for a source type
 */
private function getPendingSourceIdColumn(string $type): string
{
    return match($type) {
        'user_source' => 'id',
        'agent_destination' => 'id',
        'registration_attempt' => 'id',
        'agent_attempt' => 'id',
        default => 'id'
    };
}

/**
 * Encrypt a source secret (access_token or refresh_token)
 * Matches the encryption used in enterprise add_source.php
 * 
 * @param string|null $plaintext The plain text to encrypt
 * @return string|null Base64-encoded encrypted string or null if invalid
 */
private function encryptSourceSecret(?string $plaintext): ?string
{
    if (empty($plaintext)) {
        return null;
    }
    
    $key = getenv('VOUCHMORPH_TOKEN_ENC_KEY');
    if (!$key) {
        error_log("[SwapService] VOUCHMORPH_TOKEN_ENC_KEY not set - cannot encrypt");
        return null;
    }
    
    // Generate a random IV
    $iv = openssl_random_pseudo_bytes(16);
    
    $encrypted = openssl_encrypt(
        $plaintext,
        'AES-256-CBC',
        $key,
        0,
        $iv
    );
    
    if ($encrypted === false) {
        error_log("[SwapService] Encryption failed: " . openssl_error_string());
        return null;
    }
    
    // Combine IV + encrypted data and base64 encode
    return base64_encode($iv . $encrypted);
}
    
/**
 * Decrypt a source secret (access_token or refresh_token)
 * Matches the encryption used in enterprise add_source.php
 * 
 * @param string|null $encrypted Base64-encoded encrypted string
 * @return string|null Decrypted plain text or null if invalid
 */
private function decryptSourceSecret(?string $encrypted): ?string
{
    if (empty($encrypted)) {
        return null;
    }
    
    $key = getenv('VOUCHMORPH_TOKEN_ENC_KEY');
    if (!$key) {
        error_log("[SwapService] VOUCHMORPH_TOKEN_ENC_KEY not set - cannot decrypt");
        return null;
    }
    
    $data = base64_decode($encrypted);
    if ($data === false || strlen($data) < 16) {
        error_log("[SwapService] Invalid encrypted data format");
        return null;
    }
    
    // Extract IV (first 16 bytes) and ciphertext (rest)
    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    
    $decrypted = openssl_decrypt(
        $ciphertext,
        'AES-256-CBC',
        $key,
        0,
        $iv
    );
    
    if ($decrypted === false) {
        error_log("[SwapService] Decryption failed: " . openssl_error_string());
        return null;
    }
    
    return $decrypted;
}

/**
 * Get a decrypted access token from source_accounts
 * This should be used whenever we need to use the token for API calls
 */
private function getDecryptedAccessToken(array $source): ?string
{
    if (empty($source['access_token'])) {
        return null;
    }
    return $this->decryptSourceSecret($source['access_token']);
}

/**
 * Get a decrypted refresh token from source_accounts
 */
private function getDecryptedRefreshToken(array $source): ?string
{
    if (empty($source['refresh_token'])) {
        return null;
    }
    return $this->decryptSourceSecret($source['refresh_token']);
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
                $holdPayload['amount'] = $destAmount;
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
                $totalHeld += ($destAmount);
                
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
                $holdPayload['amount'] = $amount;
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
                $totalHeld += ($amount);
                
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
                    'amount' => $amount,
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

    // ✅ FIX: Track whether THIS call opened the atomic transaction
    $openedHere = !$this->inAtomicSwap;
    if ($openedHere) {
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
        $identityHoldStored = $this->storeIdentityHold(
            $payload,
            $swapRef,
            $holdResult,
            $this->currentHoldId
        );
        $identityHoldId = $identityHoldStored['hold_id'];
        $claimPin = $identityHoldStored['claim_pin'];

        $this->updateHoldStatus($this->currentHoldId, 'PENDING_IDENTITY');

        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // ✅ FIX: Commit the atomic swap if this call opened it
        if ($openedHere) {
            $this->commitAtomicSwap();
        }

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
            'claim_pin' => $claimPin, // NEW - plaintext, shown once to the sender's dashboard
            'message' => 'Swap paused. Recipient must confirm identity and choose destination within 24 hours.',
            'access_methods' => $this->getIdentityAccessMethods($identityType, $payload['identity_value'])
        ];

    } catch (\Throwable $e) {
        error_log("[SwapService] initiateSwapToIdentity FAILED (" . get_class($e) . "): " . $e->getMessage());
        if ($openedHere) {
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
    $identityType = $identitySwap['identity_type'];
    $suppliedPin = (string)($payload['pin'] ?? '');

    if (!in_array($confirmedByType, ['user', 'agent'], true)) {
        throw new RuntimeException("confirmed_by_type must be 'user' or 'agent'");
    }

    if ($confirmedByType === 'agent') {
        if (!$this->isAgentVerifiableIdentityType($identityType)) {
            throw new RuntimeException("Agents can only confirm document-based identity types (" . implode(', ', self::IDENTITY_TYPES_AGENT_VERIFIABLE) . "), not {$identityType}");
        }
        $documentVerified = ($payload['identity_document_verified'] ?? null) === true
            || ($payload['national_id_verified'] ?? null) === true;
        if (!$documentVerified) {
            throw new RuntimeException("Agent must verify the physical {$identityType} first");
        }
    }

// PIN check applies REGARDLESS of confirmed_by_type.
    $this->verifyIdentityClaimPin($identitySwap, $suppliedPin, $confirmedByType);
    return $this->finalizeIdentityHoldNoPin($identitySwap, $payload);
}

/**
 * ============================================================
 * finalizeIdentityHoldNoPin()
 * ============================================================
 * Now respects the _skip_pin_verification flag for aggregated claims.
 * If the identity is already authorized, PIN verification is skipped.
 */
private function finalizeIdentityHoldNoPin(array $identitySwap, array $payload): array
{
    $swapRef = $identitySwap['swap_reference'];
    $holdId = $identitySwap['hold_id'];
    $confirmedByType = $payload['confirmed_by_type'] ?? 'agent';
    $confirmedById = $payload['confirmed_by_id'] ?? null;
 
    $destinationType = strtoupper($payload['destination_type'] ?? 'CASHOUT');
    if (!in_array($destinationType, ['CASHOUT', 'DEPOSIT'])) {
        throw new RuntimeException("destination_type must be 'CASHOUT' or 'DEPOSIT'");
    }
 
    $sourcePayload = json_decode($identitySwap['source_payload'], true);
    $sourceInstitution = $identitySwap['source_institution'];
 
    $sourcePayload['from_institution'] = $sourceInstitution;
    $sourcePayload['source_institution'] = $sourceInstitution;
    $sourcePayload['amount'] = (float)$identitySwap['amount'];
    $sourcePayload['currency'] = $identitySwap['currency'] ?? 'BWP';
    $sourcePayload['asset_type'] = $identitySwap['source_asset_type'] ?? 'ACCOUNT';
 
    $openedHere = !$this->inAtomicSwap;
 
    error_log("[DEBUG][agg_claim] finalizeIdentityHoldNoPin hold_id={$holdId} swap_reference={$swapRef} openedHere=" . ($openedHere ? 'true' : 'false') . " (inAtomicSwap was " . ($this->inAtomicSwap ? 'true' : 'false') . " on entry)");
 
    try {
        if ($openedHere) {
            $this->beginAtomicSwap($swapRef);
        } else {
            $this->currentSwapRef = $swapRef;
        }
 
        // ============================================================
        // FIX: If PIN verification is skipped (identity authorized),
        // don't call verifyIdentityClaimPin again
        // ============================================================
        $skipPinVerification = $payload['_skip_pin_verification'] ?? false;
        $isAuthorized = $this->isIdentityAuthorized(
            $identitySwap['identity_type'], 
            $identitySwap['identity_value']
        );
        
        if (!$skipPinVerification && !$isAuthorized) {
            // Only verify PIN if not already authorized
            // This is for single hold finalization (not aggregated)
            $suppliedPin = (string)($payload['pin'] ?? '');
            $this->verifyIdentityClaimPin($identitySwap, $suppliedPin, $confirmedByType);
        } else {
            error_log("[DEBUG][agg_claim] finalizeIdentityHoldNoPin: Skipping PIN verification for hold {$holdId} (identity already authorized)");
        }
 
        // MOVED INSIDE the transaction
        $this->updateIdentityHoldStatus($identitySwap['hold_id'], 'confirmed', [
            'confirmed_by_type' => $confirmedByType,
            'confirmed_by_id' => $confirmedById,
            'confirmation_method' => $payload['confirmation_method'] ?? ($confirmedByType === 'user' ? 'dashboard' : 'agent_portal'),
            'destination_type' => $destinationType,
            'authorized_skip' => $skipPinVerification || $isAuthorized ? true : false
        ]);
 
        error_log("[SwapService] Re-verifying asset availability for institution: {$sourceInstitution}");
        $verificationResult = $this->verifyAssetSigned($sourcePayload, $sourceInstitution);
        if (!($verificationResult['verified'] ?? false)) {
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
 
        if ($openedHere) {
            error_log("[DEBUG][agg_claim] finalizeIdentityHoldNoPin hold_id={$holdId} committing (openedHere=true)");
            $this->commitAtomicSwap();
        } else {
            error_log("[DEBUG][agg_claim] finalizeIdentityHoldNoPin hold_id={$holdId} NOT committing here - outer caller owns the transaction (openedHere=false)");
        }
 
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
        error_log("[SwapService] finalizeIdentityHoldNoPin FAILED (" . get_class($e) . "): " . $e->getMessage());
        error_log("[DEBUG][agg_claim] finalizeIdentityHoldNoPin hold_id={$holdId} EXCEPTION openedHere={$openedHere} - " . ($openedHere ? 'rolling back (this call owns the transaction)' : 'NOT rolling back here - outer caller owns it'));
        if ($openedHere) {
            $this->rollbackAtomicSwap($e->getMessage());
        }
        throw $e;
    }
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
    // ✅ STRICT: Must have denominations
    if (!isset($this->atmNotes[$currency])) {
        throw new RuntimeException(
            "Cannot create earmarked balance for currency {$currency}: " .
            "No ATM denominations configured. Add '{$currency}' to atm_notes.json."
        );
    }
    
    $denominations = $this->atmNotes[$currency];
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
        return 0;
    }
}
 
/**
 * Aggregates all OPEN earmarked balances for a given account into a
 * single figure to check a withdrawal against.
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
        'entries' => $rows,
    ];
}
 
/**
 * Enforces the partial-withdrawal rule against any open earmarked
 * balance on this account.
 */
private function validateEarmarkedWithdrawal(string $institution, string $identifier, float $requestedAmount): void
{
    $summary = $this->getOpenEarmarkedSummary($institution, $identifier);
    if ($summary === null) {
        return;
    }
 
    $remaining = $summary['total_remaining'];
    $threshold = $summary['threshold'];
 
    if ($requestedAmount >= $remaining) {
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
 * after a withdrawal has genuinely succeeded.
 */
private function consumeEarmarkedBalance(string $institution, string $identifier, float $amountWithdrawn, ?string $swapReference = null): void
{
    $summary = $this->getOpenEarmarkedSummary($institution, $identifier);
    if ($summary === null) {
        return;
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
     */
    public function confirmCashout(array $payload): array
    {
        error_log("[SwapService] ===== confirmCashout START =====");
        error_log("[SwapService] Payload keys: " . implode(', ', array_keys($payload)));

        $swapReference = $payload['swap_reference'] ?? null;
        $authId = $payload['auth_id'] ?? null;
        $code = $payload['code'] ?? null;
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'] ?? null;
        $cashoutPoint = $payload['cashout_point'] ?? 'ATM';

        $voucherNumber = $payload['voucher_number'] ?? null;
        $atmId = $payload['atm_id'] ?? null;
        $cashoutReference = $payload['cashout_reference'] ?? null;
        $requester = $payload['requester'] ?? 'SYSTEM';
        $isCallback = $payload['is_callback'] ?? false;

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

        $authorization = $this->findAuthorization($swapReference, $authId, $voucherNumber);

        if (!$authorization) {
            throw new RuntimeException("No pending cashout authorization found");
        }

        $authId = $authorization['auth_id'];
        $swapRef = $authorization['swap_reference'];

        $sourceInstitution = $sourceInstitutionOverride ?? $authorization['source_institution'];
        $destinationInstitution = $destinationInstitution ?? $authorization['destination_institution'] ?? 'ATM';
        $amountToSend = $amountOverride ?? (float)$authorization['amount'];
        $feeAmount = $feeOverride ?? (float)($authorization['fee_amount'] ?? 0);
        $currency = $authorization['currency'] ?? 'BWP';
        $userId = $userIdOverride ?? $authorization['user_id'];
        $holdReference = $holdReferenceOverride ?? $authorization['hold_reference'] ?? $swapRef;

        error_log("[SwapService] Auth: id={$authId}, swap={$swapRef}, source={$sourceInstitution}, amount={$amountToSend}");

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
            error_log("[SwapService] ATM Callback - skipping destination verification (cash already dispensed)");

            // ============================================================
            // FIX: this used to UPDATE instant_money_vouchers, a table that
            // does not exist on VouchMorph's side (it belongs to the
            // ZuruBank schema) - every call silently threw and was
            // swallowed by the catch, doing nothing.
            //
            // Two real VouchMorph-side tables actually track this:
            //
            // 1. cashout_authorizations.code_used_at - already exists on
            //    this table, was never being set anywhere. Records "the
            //    code was used at the ATM/agent", distinct from full debit
            //    completion (status/completed_at, set later below by
            //    updateCashoutAuthorizationStatus()).
            //
            // 2. swap_vouchers - a separate table keyed by swap_id (the
            //    NUMERIC swap_requests.swap_id, not the string
            //    swap_reference), storing a hashed code (code_hash) rather
            //    than the plaintext voucher/ATM code. Since cash is
            //    already dispensed by this point, re-verifying the code
            //    isn't security-critical here - we just need to resolve
            //    the numeric swap_id and mark that voucher row redeemed.
            // ============================================================
            try {
                $stmt = $this->swapDB->prepare("
                    UPDATE cashout_authorizations
                    SET code_used_at = COALESCE(code_used_at, NOW())
                    WHERE auth_id = :auth_id
                ");
                $stmt->execute([':auth_id' => $authId]);
                error_log("[SwapService] code_used_at recorded for auth_id={$authId}");
            } catch (Exception $e) {
                error_log("[SwapService] Failed to record code_used_at for auth_id={$authId}: " . $e->getMessage());
            }

            if ($voucherNumber) {
                try {
                    $swapIdStmt = $this->swapDB->prepare("
                        SELECT swap_id FROM swap_requests WHERE swap_uuid = :swap_uuid LIMIT 1
                    ");
                    $swapIdStmt->execute([':swap_uuid' => $swapRef]);
                    $swapIdRow = $swapIdStmt->fetch(PDO::FETCH_ASSOC);
                    $swapIdForVoucher = $swapIdRow ? (int)$swapIdRow['swap_id'] : null;

                    if ($swapIdForVoucher) {
                        $voucherStmt = $this->swapDB->prepare("
                            UPDATE swap_vouchers
                            SET status = 'redeemed'
                            WHERE swap_id = :swap_id
                            AND status IN ('active', 'issued', 'pending')
                        ");
                        $voucherStmt->execute([':swap_id' => $swapIdForVoucher]);
                        error_log("[SwapService] swap_vouchers marked redeemed for swap_id={$swapIdForVoucher}, voucher={$voucherNumber}");
                    } else {
                        error_log("[SwapService] No swap_requests row found for swap_ref={$swapRef} - cannot resolve swap_id to update swap_vouchers");
                    }
                } catch (Exception $e) {
                    error_log("[SwapService] Voucher update warning: " . $e->getMessage());
                }
            }
        }

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

        try {
            $sourceIdentifierForLedger = $authorization['source_identifier'] ?? null;
            if ($sourceIdentifierForLedger) {
                $this->consumeEarmarkedBalance($sourceInstitution, $sourceIdentifierForLedger, $amountToSend + $feeAmount, $swapRef);
            } else {
                error_log("[SwapService] No source_identifier on cashout_authorizations for auth_id={$authId} (swap_ref={$swapRef}) - cannot consume earmarked balance, likely a pre-migration record.");
            }
        } catch (Exception $e) {
            error_log("[SwapService] Non-fatal: failed to consume earmarked balance after successful debit: " . $e->getMessage());
        }

        $this->updateCashoutAuthorizationStatus($authId, 'COMPLETED', $cashoutPoint);
        $this->updateHoldForSwap($swapRef, 'DEBITED');
        $this->updateSwapRequestStatus($swapRef, 'completed');

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
 * into one figure.
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
 * ============================================================
 * IDENTITY AUTHORIZATION METHODS - The "Green Light"
 * ============================================================
 * These methods handle the PIN verification and authorization flow.
 * One PIN verification grants authorization for ALL holds under an identity.
 * Authorization expires after 1 hour.
 */

/**
 * Check if an identity is already authorized (PIN verified)
 * This is the "green light" that allows finalizing all holds
 */
private function isIdentityAuthorized(string $identityType, string $identityValue): bool
{
    $sql = "
        SELECT 1 FROM identity_swap_holds 
        WHERE identity_type = :type 
          AND identity_value = :value 
          AND status = 'pending'
          AND authorized_at IS NOT NULL
          AND authorized_at > NOW() - INTERVAL '1 hour'
        LIMIT 1
    ";
    
    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':type' => $identityType, ':value' => $identityValue]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("[SwapService] Failed to check identity authorization: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all pending holds for an identity as "authorized"
 * This means the PIN has been verified and the agent can finalize them
 * This is the "green light" - one PIN verification grants access to ALL holds
 */
private function markIdentityHoldsAuthorized(string $identityType, string $identityValue, string $authorizedBy = 'pin_verification'): int
{
    $sql = "
        UPDATE identity_swap_holds 
        SET 
            authorized_at = NOW(),
            authorized_by = :authorized_by,
            authorization_type = 'otp_pin_verified',
            otp_pin_attempts = 0
        WHERE identity_type = :type 
          AND identity_value = :value 
          AND status = 'pending'
          AND hold_expires_at > NOW()
    ";
    
    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':type' => $identityType,
            ':value' => $identityValue,
            ':authorized_by' => $authorizedBy
        ]);
        $count = $stmt->rowCount();
        error_log("[SwapService] Marked {$count} holds as authorized for identity {$identityType}={$identityValue}");
        return $count;
    } catch (PDOException $e) {
        error_log("[SwapService] Failed to mark identity as authorized: " . $e->getMessage());
        return 0;
    }
}

/**
 * ============================================================
 * The core bundling operation. One PIN check (against any hold),
 * then N individual deposits into the agent's account.
 * ============================================================
 * The PIN is the "GREEN LIGHT" - once verified, ALL holds are authorized.
 * This allows the agent to finalize multiple holds with one PIN entry.
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
    // 1. Verify the agent's destination account
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

    // 2. Get ALL pending holds for this identity
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

    // 3. Check currencies
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

    // 4. ============================================================
    // PIN check happens ONCE against ANY hold with a valid PIN hash.
    // This is the "GREEN LIGHT" - it authorizes the ENTIRE identity.
    // ============================================================
    
    // Find a hold that has a valid PIN hash
    $holdWithPin = null;
    foreach ($pendingHolds as $hold) {
        if (!empty($hold['otp_pin_hash'])) {
            $holdWithPin = $hold;
            break;
        }
    }

    if (!$holdWithPin) {
        throw new RuntimeException("No PIN has been set for this identity. Please initiate a new swap.");
    }

    // Verify the PIN against this hold - this gives the "green light"
    $this->verifyIdentityClaimPin($holdWithPin, $pin);
    
    // After verifyIdentityClaimPin() marks the identity as authorized,
    // all holds are now authorized (the green light is ON)

    $beneficiaryPhone = $holdWithPin['otp_pin_sent_to'] ?? null;
    if (empty($beneficiaryPhone)) {
        $latestSourcePayload = json_decode($holdWithPin['source_payload'], true);
        $beneficiaryPhone = $latestSourcePayload['notification_phone'] ?? $latestSourcePayload['beneficiary_phone'] ?? null;
    }

    // 5. Process EACH hold as a SEPARATE transaction
    // NOW we can finalize ALL holds because the identity is authorized
    // The PIN verification happens ONCE, then all holds are finalized
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
            // These flags tell finalizeIdentityHoldNoPin to skip PIN verification
            '_skip_pin_verification' => true,
            '_authorized_by' => 'identity_authorization',
        ];

        try {
            // Process each hold as its own independent atomic transaction
            $result = $this->executeSingleHoldTransaction($hold, $confirmationPayload);

            $netAmount = $result['result']['amount'] ?? $hold['amount'];

            $depositResults[] = [
                'hold_id' => $hold['hold_id'],
                'swap_reference' => $hold['swap_reference'],
                'gross_amount' => (float)$hold['amount'],
                'net_deposited' => (float)$netAmount,
                'status' => 'completed',
                'transaction_reference' => $result['transaction_reference'] ?? null,
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
            // CONTINUE to next hold - don't stop!
        }
    }

    if (empty($depositResults)) {
        throw new RuntimeException("All underlying swaps failed to deposit - nothing was claimed. See individual errors and retry.");
    }

    // 6. Calculate totals from successful deposits ONLY
    $actuallyClaimedGross = round((float)array_sum(array_column($depositResults, 'gross_amount')), 2);
    $actuallyClaimedNet = round($totalDepositedNet, 2);

    // cash_now and remainder are computed against NET (what the agent's account actually holds after fees)
    $adjustedCashNow = min($cashNowAmount, $actuallyClaimedNet);
    $adjustedRemainder = round($actuallyClaimedNet - $adjustedCashNow, 2);

    $response = [
        'status' => empty($failedHolds) ? 'success' : 'partial_success',
        'identity_type' => $identityType,
        'identity_value' => $identityValue,
        'currency' => $currency,
        'requested_full_amount' => $fullAmount,
        'actually_claimed_gross' => $actuallyClaimedGross,
        'actually_claimed_net' => $actuallyClaimedNet,
        'total_deposited_net' => $actuallyClaimedNet,
        'swap_count' => count($pendingHolds),
        'successful_deposits' => $depositResults,
        'failed_deposits' => $failedHolds,
        'cash_now_amount' => $adjustedCashNow,
        'remainder_reswap' => null,
    ];

    // 7. Handle remainder re-swap (if any)
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
            $response['status'] = 'partial_success';
            $response['requires_manual_reconciliation'] = true;
        }
    }

    return $response;
}

/**
 * Execute a single hold as its own independent transaction
 * This ensures that if one hold fails, others are not affected
 */
private function executeSingleHoldTransaction(array $hold, array $confirmationPayload): array
{
    $holdId = $hold['hold_id'];
 
    error_log("[DEBUG][agg_claim] executeSingleHoldTransaction START hold_id={$holdId} swap_reference={$hold['swap_reference']}");
 
    try {
        // finalizeIdentityHoldNoPin() owns the transaction lifecycle itself
        $result = $this->finalizeIdentityHoldNoPin($hold, $confirmationPayload);
 
        error_log("[DEBUG][agg_claim] executeSingleHoldTransaction SUCCESS hold_id={$holdId}");
 
        return $result;
 
    } catch (Exception $e) {
        error_log("[DEBUG][agg_claim] executeSingleHoldTransaction FAILED hold_id={$holdId} error=" . $e->getMessage());
        
        // Defensive safety net
        if ($this->swapDB->inTransaction()) {
            error_log("[DEBUG][agg_claim] WARNING - PDO still in transaction after hold_id={$holdId} failure. Forcing rollback.");
            try {
                $this->swapDB->rollBack();
            } catch (Exception $rollbackError) {
                error_log("[DEBUG][agg_claim] Forced rollback also failed: " . $rollbackError->getMessage());
            }
        }
 
        if ($this->inAtomicSwap) {
            error_log("[DEBUG][agg_claim] WARNING - inAtomicSwap flag still true after hold_id={$holdId} failure. Forcibly resetting.");
            $this->resetAtomicState();
        }
 
        error_log("[SwapService] Single hold transaction failed for hold {$holdId}: " . $e->getMessage());
        throw $e;
    }
}

// ============================================================================
// AGENT DESTINATION REGISTRATION METHODS
// ============================================================================

/**
 * Phase 1: Verify account + trigger OTP/OAuth, then create pending attempt
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

    $identifierType = match($assetType) {
        'WALLET', 'BANK-WALLET' => 'phone',
        'CARD' => 'card_number',
        'ACCOUNT' => 'account_number',
        default => 'account_number'
    };

    $verifyPayload = [
        'action' => 'VERIFY_ACCOUNT',
        'reference' => 'AGENT_DEST_' . $userId . '_' . time(),
        'account_identifier' => $identifier,
        'identifier_type' => $identifierType,
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

    $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn.com', '/')
        . '/api/v1/agent/oauth_callback.php';

    $linkResult = null;
    try {
        $linkResult = $this->initiateSourceLink([
            'institution' => $institution,
            'identifier' => $identifier,
            'identifier_type' => $identifierType,
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
 */
public function cancelAgentDestination(int $userId, int $destinationId): array
{
    error_log("[SwapService] cancelAgentDestination: user={$userId}, destination_id={$destinationId}");
    
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
    
    if (!in_array($destination['status'], ['pending_confirmation', 'rejected'])) {
        throw new RuntimeException("This account cannot be cancelled (status: {$destination['status']}).");
    }
    
    $stmt = $this->swapDB->prepare("
        UPDATE agent_destination_accounts 
        SET status = 'cancelled', 
            deleted_at = NOW(),
            updated_at = NOW()
        WHERE id = :id AND user_id = :user_id
    ");
    $stmt->execute([':id' => $destinationId, ':user_id' => $userId]);
    
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

    $netDeposited = (float)($depositResult['result']['amount'] ?? $fullAmount);
    $adjustedCashNow = min($cashNowAmount, $netDeposited);
    $remainder = round($netDeposited - $adjustedCashNow, 2);

    $response = [
        'deposit' => $depositResult,
        'gross_amount' => $fullAmount,
        'net_deposited' => $netDeposited,
        'cash_now_amount' => $adjustedCashNow,
        'remainder_reswap' => null,
    ];

    // STEP 2: Process remainder swap AFTER the deposit atomic transaction is complete
    if ($remainder > 0) {
        try {
            error_log("[SwapService] Processing remainder swap for {$swapReference}: {$remainder} (net-based)");

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


// ============================================================================
// USER SOURCE ACCOUNT REGISTRATION - mirrors agent destination registration
// ============================================================================

public function initiateUserSourceRegistration(
    int $userId,
    string $institution,
    string $assetType,
    string $identifier,
    string $identifierType,
    ?string $accountName = null
): array {
    error_log("[SwapService] initiateUserSourceRegistration: user={$userId}, institution={$institution}, identifier={$identifier}");

    $assetType = strtoupper(trim($assetType));
    $eligibleAssetTypes = ['ACCOUNT', 'WALLET', 'BANK-WALLET', 'CARD'];
    if (!in_array($assetType, $eligibleAssetTypes, true)) {
        throw new RuntimeException("Source must be Account, Wallet, or Card - '{$assetType}' is not eligible.");
    }

    $identifierType = match($assetType) {
        'WALLET', 'BANK-WALLET' => 'phone',
        'CARD' => 'card_number',
        'ACCOUNT' => 'account_number',
        default => 'account_number'
    };

    // ============================================================
    // FIX: SKIP verifyAsset here - the bank doesn't know the user yet
    // The account will be verified during the OTP/OAuth completion
    // ============================================================

    // Check for duplicates in user_source_accounts
    $stmt = $this->swapDB->prepare("
        SELECT id, status FROM user_source_accounts
        WHERE user_id = :user_id AND institution = :institution AND identifier = :identifier
        AND deleted_at IS NULL
    ");
    $stmt->execute([':user_id' => $userId, ':institution' => $institution, ':identifier' => $identifier]);
    if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException("You already have this account registered as a source (status: {$existing['status']}).");
    }

    // Check for pending attempts
    $stmt = $this->swapDB->prepare("
        SELECT id FROM user_source_registration_attempts
        WHERE user_id = :user_id AND institution = :institution AND identifier = :identifier
        AND status IN ('otp_pending', 'oauth_pending') AND otp_expires_at > NOW()
    ");
    $stmt->execute([':user_id' => $userId, ':institution' => $institution, ':identifier' => $identifier]);
    if ($stmt->fetch()) {
        throw new RuntimeException("A verification attempt is already pending for this account.");
    }

    $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn.com', '/')
        . '/user/source_oauth_callback.php';

    // ============================================================
    // Initiate OAuth or OTP - this does NOT verify the account
    // It just starts the flow with the bank
    // ============================================================
    $linkResult = null;
    try {
        $linkResult = $this->initiateSourceLink([
            'institution' => $institution,
            'identifier' => $identifier,
            'identifier_type' => $identifierType,
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
        // This should be rare and flagged for manual review
        error_log("[SwapService] {$institution} has no OTP/OAuth support for sources - registering without ownership proof");
        $id = $this->insertUserSourceAccount(
            $userId, $institution, $assetType, $identifier, $identifierType,
            $accountName ?? null,
            'BWP',
            false, null, null, null, 'pending_confirmation'
        );
        return [
            'requires_otp' => false,
            'requires_redirect' => false,
            'otp_supported' => false,
            'id' => $id,
            'status' => 'pending_confirmation',
            'message' => "Registered without ownership verification - awaiting manual review.",
        ];
    }

    if ($isOauth) {
        // OAuth path - store attempt with state
        $stmt = $this->swapDB->prepare("
            INSERT INTO user_source_registration_attempts (
                user_id, institution, asset_type, identifier, identifier_type,
                account_name, oauth_state, otp_supported, status
            ) VALUES (
                :user_id, :institution, :asset_type, :identifier, :identifier_type,
                :account_name, :oauth_state, true, 'oauth_pending'
            ) RETURNING id
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':institution' => $institution,
            ':asset_type' => $assetType,
            ':identifier' => $identifier,
            ':identifier_type' => $identifierType,
            ':account_name' => $accountName ?? null,
            ':oauth_state' => $linkResult['state'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'requires_otp' => false,
            'requires_redirect' => true,
            'otp_supported' => true,
            'attempt_id' => $row ? (int)$row['id'] : 0,
            'redirect_url' => $linkResult['redirect_url'],
            'message' => "You'll be taken to {$institution}'s login page to confirm ownership.",
        ];
    }

    // OTP path - bank sends the code to the phone IT has on file
    $stmt = $this->swapDB->prepare("
        INSERT INTO user_source_registration_attempts (
            user_id, institution, asset_type, identifier, identifier_type,
            account_name, bank_auth_id, otp_method, otp_expires_at, otp_supported, status
        ) VALUES (
            :user_id, :institution, :asset_type, :identifier, :identifier_type,
            :account_name, :auth_id, :method, :expires_at, true, 'otp_pending'
        ) RETURNING id
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':institution' => $institution,
        ':asset_type' => $assetType,
        ':identifier' => $identifier,
        ':identifier_type' => $identifierType,
        ':account_name' => $accountName ?? null,
        ':auth_id' => $linkResult['auth_id'] ?? null,
        ':method' => $linkResult['method'] ?? 'sms',
        ':expires_at' => date('Y-m-d H:i:s', time() + (int)($linkResult['expires_in'] ?? 300)),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'requires_otp' => true,
        'requires_redirect' => false,
        'otp_supported' => true,
        'attempt_id' => $row ? (int)$row['id'] : 0,
        'method' => $linkResult['method'] ?? 'sms',
        'message' => $linkResult['message'] ?? "Verification code sent by {$institution} to your registered phone.",
    ];
}

public function completeUserSourceRegistration(int $userId, int $attemptId, string $otp): array
{
    $stmt = $this->swapDB->prepare("
        SELECT * FROM user_source_registration_attempts
        WHERE id = :id AND user_id = :user_id AND status = 'otp_pending'
    ");
    $stmt->execute([':id' => $attemptId, ':user_id' => $userId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attempt) {
        throw new RuntimeException("Verification attempt not found.");
    }
    if (strtotime($attempt['otp_expires_at']) < time()) {
        throw new RuntimeException("Verification code expired. Start again.");
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

    // ============================================================
    // FIX: NOW verify the account exists - we have authorization!
    // ============================================================
    $adapter = $this->adapterFactory->getAdapter($attempt['institution']);
    $verifyPayload = [
        'action' => 'VERIFY_ASSET',
        'reference' => 'USER_SRC_' . $userId . '_' . time(),
        'source_identifier' => $attempt['identifier'],
        'identifier_type' => $attempt['identifier_type'],
        'asset_type' => $attempt['asset_type'],
        'requester' => 'VOUCHMORPH',
        'timestamp' => time(),
        'from_institution' => $attempt['institution'],
        'source_institution' => $attempt['institution'],
        'access_token' => $verifyResult['access_token'] ?? null,
    ];
    
    $assetVerification = $adapter->verifyAsset($verifyPayload, [
        'institution' => $attempt['institution'],
        'purpose' => 'user_source_verification',
    ]);

    if (!($assetVerification['verified'] ?? false)) {
        throw new RuntimeException("Account not found or not verifiable at {$attempt['institution']}: " . ($assetVerification['message'] ?? 'Unknown reason'));
    }

    // Insert the source account
    $id = $this->insertUserSourceAccount(
        (int)$attempt['user_id'],
        $attempt['institution'],
        $attempt['asset_type'],
        $attempt['identifier'],
        $attempt['identifier_type'],
        $attempt['account_name'],
        $assetVerification['currency'] ?? 'BWP',
        true,
        $verifyResult['access_token'] ?? null,
        $verifyResult['refresh_token'] ?? null,
        $verifyResult['expires_at'] ?? null,
        'active'
    );

    $stmt = $this->swapDB->prepare("UPDATE user_source_registration_attempts SET status = 'completed', completed_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $attemptId]);

    return ['id' => $id, 'status' => 'active', 'message' => "Ownership verified. Account added as a source."];
}

public function completeUserSourceRegistrationByState(string $oauthState, string $code): array
{
    $stmt = $this->swapDB->prepare("
        SELECT * FROM user_source_registration_attempts WHERE oauth_state = :state AND status = 'oauth_pending'
    ");
    $stmt->execute([':state' => $oauthState]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) {
        throw new RuntimeException("Registration attempt not found or already completed.");
    }

    $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn.com', '/')
        . '/user/source_oauth_callback.php';

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

    $id = $this->insertUserSourceAccount(
        (int)$attempt['user_id'], $attempt['institution'], $attempt['asset_type'],
        $attempt['identifier'], $attempt['identifier_type'], $attempt['account_name'],
        'BWP', true,
        $verifyResult['access_token'] ?? null,
        $verifyResult['refresh_token'] ?? null,
        $verifyResult['expires_at'] ?? null,
        'active'
    );

    $stmt = $this->swapDB->prepare("UPDATE user_source_registration_attempts SET status = 'completed', completed_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $attempt['id']]);

    return ['id' => $id, 'status' => 'active', 'institution' => $attempt['institution'], 'message' => "Bank login verified. Source is now active."];
}

private function insertUserSourceAccount(
    int $userId, string $institution, string $assetType, string $identifier,
    string $identifierType, ?string $accountName, string $currency, bool $isHooked,
    ?string $accessToken, ?string $refreshToken, ?string $tokenExpiresAt, string $status
): int {
    // Validate asset type for users
    $userEligibleAssetTypes = ['ACCOUNT', 'WALLET', 'BANK-WALLET', 'CARD'];
    if (!in_array($assetType, $userEligibleAssetTypes, true)) {
        throw new RuntimeException("Invalid asset type for user source: {$assetType}. Users can only add Account, Wallet, or Card.");
    }
    
    // Generate unique source reference
    $sourceReference = 'SRC_' . $userId . '_' . bin2hex(random_bytes(6));
    
    // Encrypt tokens if provided
    $encryptedAccess = $accessToken ? $this->encryptSourceSecret($accessToken) : null;
    $encryptedRefresh = $refreshToken ? $this->encryptSourceSecret($refreshToken) : null;
    
    // ============================================================
    // FIX: Remove created_at column - it doesn't exist in the table
    // ============================================================
    $stmt = $this->swapDB->prepare("
        INSERT INTO user_source_accounts (
            user_id, institution, asset_type, identifier, identifier_type,
            account_name, currency, is_hooked, access_token, refresh_token,
            token_expires_at, source_reference, status, proposed_at, confirmed_at, updated_at
        ) VALUES (
            :user_id, :institution, :asset_type, :identifier, :identifier_type,
            :account_name, :currency, :is_hooked, :access_token, :refresh_token,
            :token_expires_at, :source_reference, :status,
            NOW(),
            CASE WHEN :status_active = 'active' THEN NOW() ELSE NULL END,
            NOW()
        ) RETURNING id
    ");
    
    $stmt->execute([
        ':user_id' => $userId,
        ':institution' => $institution,
        ':asset_type' => $assetType,
        ':identifier' => $identifier,
        ':identifier_type' => $identifierType,
        ':account_name' => $accountName,
        ':currency' => $currency,
        ':is_hooked' => $isHooked ? 't' : 'f',
        ':access_token' => $encryptedAccess,
        ':refresh_token' => $encryptedRefresh,
        ':token_expires_at' => $tokenExpiresAt,
        ':source_reference' => $sourceReference,
        ':status' => $status,
        ':status_active' => $status === 'active' ? 'active' : 'inactive'
    ]);
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}

public function cancelUserSourceAccount(int $userId, int $sourceId): array
{
    $stmt = $this->swapDB->prepare("
        SELECT id, status FROM user_source_accounts
        WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $sourceId, ':user_id' => $userId]);
    if (!$stmt->fetch()) {
        throw new RuntimeException("Source account not found or does not belong to you.");
    }
    $stmt = $this->swapDB->prepare("
        UPDATE user_source_accounts SET status = 'cancelled', deleted_at = NOW(), updated_at = NOW()
        WHERE id = :id AND user_id = :user_id
    ");
    $stmt->execute([':id' => $sourceId, ':user_id' => $userId]);
    return ['success' => true, 'message' => 'Source account removed.', 'id' => $sourceId];
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
        ':status2' => $status,
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
 * Returns this user's APPROVED (active) agent destination accounts
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
 * Whether this user has at least one approved agent destination
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

    /**
     * STRICT loader - NO FALLBACKS
     * Must find atm_notes.json in country folder
     */
    private function loadAtmNotesStrict(string $country): void
    {
        $countryFolder = __DIR__ . '/../../Core/Config/Countries/' . $country;
        $atmNotesPath = $countryFolder . '/atm_notes.json';
        
        if (!file_exists($atmNotesPath)) {
            throw new RuntimeException(
                "Required file not found: {$atmNotesPath}. " .
                "Country '{$country}' must have atm_notes.json in its config folder."
            );
        }
        
        $content = file_get_contents($atmNotesPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read atm_notes.json from {$countryFolder}");
        }
        
        $this->atmNotes = json_decode($content, true);
        
        if (!is_array($this->atmNotes) || empty($this->atmNotes)) {
            throw new RuntimeException(
                "Invalid atm_notes.json in {$countryFolder}. " .
                "Must contain a valid JSON object with currency denominations."
            );
        }
        
        // Validate each currency has denominations
        foreach ($this->atmNotes as $currency => $denominations) {
            if (!is_array($denominations) || empty($denominations)) {
                throw new RuntimeException(
                    "Currency '{$currency}' in atm_notes.json has no denominations. " .
                    "Each currency must have an array of note values."
                );
            }
            
            // Sort descending for proper calculation
            rsort($denominations);
            $this->atmNotes[$currency] = $denominations;
        }
        
        error_log("[SwapService] Loaded ATM notes from {$atmNotesPath}: " . json_encode($this->atmNotes));
    }

    private function validateCashoutAmount(float $requestedAmount, string $currency): array
    {
        // ✅ STRICT: Must have denominations
        if (!isset($this->atmNotes[$currency])) {
            throw new RuntimeException(
                "No ATM denominations configured for currency: {$currency}. " .
                "Cannot validate cashout amount."
            );
        }
        
        $denominations = $this->atmNotes[$currency];
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
    
    // ============================================================
    // BUSINESS RULES:
    // 1. DEPOSIT: Always deliver FULL amount (no ATM rounding)
    // 2. CASHOUT with VOUCHER: Apply ATM rounding, but if amount < smallest note, FAIL
    // 3. CASHOUT with other assets: Apply ATM rounding, remainder stays at source
    // ============================================================
    $isDeposit = ($feeType === 'DEPOSIT');
    $assetType = strtoupper($payload['asset_type'] ?? '');
    $isVoucher = ($assetType === 'VOUCHER');
    
    $noteBreakdown = [];
    $multiplier = null;
    
    // DEPOSIT always delivers full amount (no ATM rounding)
    if ($isDeposit) {
        $dispensableAmount = $netAmountDestCurrency;
        $remainderBalance = 0;
        $multiplier = null;
        $denominations = [];
        
        error_log("[SwapService] FULL delivery: DEPOSIT - amount: {$dispensableAmount} {$destinationCurrency}");
        
    } else {
        // CASHOUT - apply ATM rounding for ALL asset types
        if (!isset($this->atmNotes[$destinationCurrency])) {
            throw new RuntimeException(
                "No ATM denominations configured for currency: {$destinationCurrency}. " .
                "Please add '{$destinationCurrency}' to atm_notes.json in the country config."
            );
        }
        
        $denominations = $this->atmNotes[$destinationCurrency];
        $smallestDenom = min($denominations);
        
        // Check if VOUCHER amount is less than smallest denomination
        if ($isVoucher && $netAmountDestCurrency < $smallestDenom) {
            throw new RuntimeException(
                "Voucher cashout amount ({$netAmountDestCurrency} {$destinationCurrency}) is below the minimum ATM denomination ({$smallestDenom} {$destinationCurrency}). " .
                "Please request a larger amount or use DEPOSIT instead."
            );
        }
        
        // ============================================================
        // FIX: Proper greedy multi-denomination breakdown instead of
        // "largest note only". This mirrors validateCashoutAmount()'s
        // already-correct algorithm, which was never being used on
        // this path. Previously, 990 BWP with [200,100,50,20,10] would
        // compute dispensable=800 (4x200) and leave 190 as remainder,
        // even though 990 is exactly dispensable with a proper mix
        // (4x200 + 1x100 + 1x50 + 2x20 = 990, remainder 0).
        // ============================================================
        $sortedDesc = $denominations;
        rsort($sortedDesc);
        
        $remaining = $netAmountDestCurrency;
        foreach ($sortedDesc as $note) {
            if ($remaining >= $note) {
                $count = floor($remaining / $note);
                $noteBreakdown[$note] = $count;
                $remaining = round($remaining - ($note * $count), 2);
            }
        }
        
        $dispensableAmount = round($netAmountDestCurrency - $remaining, 2);
        $remainderBalance = $remaining;
        
        // For VOUCHER, if dispensable amount is 0 (shouldn't happen due to check above)
        if ($isVoucher && $dispensableAmount <= 0) {
            throw new RuntimeException(
                "Voucher cashout amount ({$netAmountDestCurrency} {$destinationCurrency}) cannot be dispensed by ATM. " .
                "Please request a larger amount or use DEPOSIT instead."
            );
        }
        
        error_log("[SwapService] ATM rounding applied for {$assetType} - dispensable: {$dispensableAmount}, remainder: {$remainderBalance}, breakdown: " . json_encode($noteBreakdown));
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
        'note_breakdown' => $noteBreakdown,
        'dispensable_amount' => $dispensableAmount,
        'remainder_balance' => $remainderBalance,
        'denominations' => $denominations ?? [],
        'is_deposit' => $isDeposit,
        'is_voucher' => $isVoucher,
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
            'note_breakdown' => $noteBreakdown,
            'Amount_4' => $dispensableAmount,
            'Remainder_1' => $remainderBalance,
            'is_deposit' => $isDeposit,
            'is_voucher' => $isVoucher
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
    error_log("  Note breakdown: " . json_encode($noteBreakdown));
    error_log("  Amount_4 (dispensable): {$dispensableAmount}");
    error_log("  Remainder_1: {$remainderBalance}");
    if ($isDeposit) {
        error_log("  [DEPOSIT] Full delivery - no ATM rounding applied");
    } elseif ($isVoucher) {
        error_log("  [VOUCHER CASHOUT] ATM rounding applied - must meet minimum denomination");
    } else {
        error_log("  [CASHOUT] ATM rounding applied - remainder stays at source");
    }
    
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
        'note_breakdown' => $noteBreakdown,
        'denominations' => $denominations ?? [],
        'is_deposit' => $isDeposit,
        'is_voucher' => $isVoucher,
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

   /**
     * Verify asset at source institution
     * STANDARD: Returns consistent structure with verification proof
     */
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
        $result = $adapter->verifyAsset($verifyPayload, [
            'swap_reference' => $this->currentSwapRef,
            'institution' => $institution,
            'source_identifier' => $sourceId['identifier'] ?? null,
            'signed_payloads' => $this->signedPayloads,
            'timestamp' => $timestamp
        ]);

        // ============================================================
        // STANDARDIZED RESPONSE STRUCTURE
        // ============================================================
        return [
            'success' => $result['success'] ?? $result['verified'] ?? false,
            'verified' => $result['verified'] ?? false,
            'message' => $result['message'] ?? 'Asset verification completed',
            'asset_id' => $result['asset_id'] ?? null,
            'account_id' => $result['account_id'] ?? null,
            'account_name' => $result['account_name'] ?? null,
            'balance' => $result['balance'] ?? 0,
            'currency' => $result['currency'] ?? $payload['currency'] ?? 'BWP',
            'original_payload' => $result['original_payload'] ?? $verifyPayload,
            'signature' => $result['signature'] ?? null,
            'certificate' => $result['certificate'] ?? null,
            'timestamp' => $result['timestamp'] ?? $timestamp,
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'data' => $result['data'] ?? []
        ];
    }

    /**
     * Place hold on source institution
     * STANDARD: Returns consistent structure with hold proof
     */
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

        $holdPlaced = $result['hold_placed'] ?? false;

        if ($holdPlaced) {
            $holdId = $this->createLocalHold($payload, $institution, $result['hold_reference'] ?? null);
            $this->currentHoldId = $holdId;
            $this->currentHoldReference = $result['hold_reference'] ?? $this->currentHoldReference;
            $this->currentHoldInstitution = $institution;
            $result['local_hold_id'] = $holdId;
        }

        // ============================================================
        // STANDARDIZED RESPONSE STRUCTURE
        // ============================================================
        return [
            'success' => $holdPlaced,
            'hold_placed' => $holdPlaced,
            'hold_reference' => $result['hold_reference'] ?? null,
            'hold_id' => $result['hold_id'] ?? null,
            'local_hold_id' => $result['local_hold_id'] ?? null,
            'status' => $result['status'] ?? ($holdPlaced ? 'ACTIVE' : 'FAILED'),
            'original_payload' => $result['original_payload'] ?? $holdPayload,
            'signature' => $result['signature'] ?? null,
            'certificate' => $result['certificate'] ?? null,
            'timestamp' => $result['timestamp'] ?? $timestamp,
            'message' => $result['message'] ?? ($holdPlaced ? 'Hold placed successfully' : 'Hold placement failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'data' => $result['data'] ?? []
        ];
    }

    /**
     * Debit source institution
     * STANDARD: Uses debitFunds not debitHold
     */
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
            'source_institution' => $institution,
            'action' => 'DEBIT_FUNDS'
        ];

        $this->forwardPin($payload, $debitPayload);

        $adapter = $this->adapterFactory->getAdapter($institution);
        $result = $adapter->debit($debitPayload, [
            'swap_reference' => $this->currentSwapRef,
            'institution' => $institution,
            'hold_reference' => $this->currentHoldReference,
            'signed_payloads' => $this->signedPayloads
        ]);

        $debited = $result['debited'] ?? false;

        // ============================================================
        // STANDARDIZED RESPONSE STRUCTURE
        // ============================================================
        return [
            'success' => $debited,
            'debited' => $debited,
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'status' => $result['status'] ?? ($debited ? 'COMPLETED' : 'FAILED'),
            'message' => $result['message'] ?? ($debited ? 'Debit completed' : 'Debit failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'data' => $result['data'] ?? []
        ];
    }

    /**
     * Release hold
     * STANDARD: Consistent with adapter and bank client
     */
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
                'released' => false,
                'message' => 'No hold reference available for release',
                'hold_reference' => null,
                'status_code' => 0,
                'curl_error' => null,
                'raw_response' => null,
                'data' => []
            ];
        }

        $releasePayload = [
            'action' => 'RELEASE_HOLD',
            'hold_reference' => $holdRef,
            'reason' => 'Multi-source swap rolled back',
            'from_institution' => $institution,
            'source_institution' => $institution,
            'reference' => $this->currentSwapRef ?? 'RELEASE_' . uniqid()
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

            $released = $result['released'] ?? $result['success'] ?? false;

            $this->logger->info("Hold released successfully", [
                'institution' => $institution,
                'hold_reference' => $holdRef,
                'success' => $released
            ]);

            // ============================================================
            // STANDARDIZED RESPONSE STRUCTURE
            // ============================================================
            return [
                'success' => $released,
                'released' => $released,
                'message' => $result['message'] ?? ($released ? 'Hold released' : 'Release failed'),
                'hold_reference' => $holdRef,
                'status' => $result['status'] ?? ($released ? 'RELEASED' : 'FAILED'),
                'released_at' => $result['released_at'] ?? date('Y-m-d H:i:s'),
                'status_code' => $result['status_code'] ?? 0,
                'curl_error' => $result['curl_error'] ?? null,
                'raw_response' => $result['raw_response'] ?? null,
                'data' => $result['data'] ?? []
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

            // ============================================================
            // STANDARDIZED ERROR RESPONSE STRUCTURE
            // ============================================================
            return [
                'success' => false,
                'released' => false,
                'message' => 'Failed to release hold: ' . $e->getMessage(),
                'hold_reference' => $holdRef,
                'status' => 'FAILED',
                'status_code' => 500,
                'curl_error' => null,
                'raw_response' => null,
                'data' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Credit destination (pool credit)
     * STANDARD: Returns consistent credit response
     */
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

        $credited = $result['credited'] ?? false;

        if (!$credited) {
            return [
                'success' => false,
                'credited' => false,
                'message' => $result['message'] ?? 'Pool credit failed',
                'status_code' => $result['status_code'] ?? 0,
                'curl_error' => $result['curl_error'] ?? null,
                'raw_response' => $result['raw_response'] ?? null,
                'data' => $result['data'] ?? []
            ];
        }

        $this->assertStepIntegrity(
            $result,
            'credited',
            ['transaction_reference'],
            'CREDIT_DESTINATION'
        );

        // ============================================================
        // STANDARDIZED RESPONSE STRUCTURE
        // ============================================================
        return [
            'success' => true,
            'credited' => true,
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'message' => $result['message'] ?? 'Pool credit successful',
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'data' => $result['data'] ?? []
        ];
    }

    /**
 * Generate cashout token
 * STANDARD: Consistent with adapter and bank client
 */
private function generateCashoutToken(array $payload, string $institution, float $amount): array
{
    $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
    $sourceInstitution = $this->extractSourceInstitution($payload);
    $sourceId = $this->extractSourceIdentifier($payload);

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
        'destination_institution' => $institution,
        'source_identifier' => $sourceId['identifier'] ?? null,
        'source_identifier_type' => $sourceId['type'] ?? null,
    ];

    if (isset($payload['note_breakdown'])) {
        $tokenPayload['note_breakdown'] = $payload['note_breakdown'];
    }

    $adapter = $this->adapterFactory->getAdapter($institution);
    $result = $adapter->generateCashoutToken($tokenPayload, [
        'swap_reference' => $this->currentSwapRef,
        'source_institution' => $sourceInstitution,
        'destination_institution' => $institution,
        'hold_reference' => $this->currentHoldReference,
        'beneficiary_phone' => $beneficiaryPhone,
        'signed_payloads' => $this->signedPayloads
    ]);

    $success = $result['success'] ?? false;

    return [
        'success' => $success,
        'cashout_code' => $result['cashout_code'] ?? null,
        'atm_pin' => $result['atm_pin'] ?? null,
        'voucher_number' => $result['voucher_number'] ?? null,
        'swap_code' => $result['swap_code'] ?? null,
        'expires_at' => $result['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours')),
        'transaction_reference' => $result['transaction_reference'] ?? null,
        'message' => $result['message'] ?? ($success ? 'Token generated' : 'Token generation failed'),
        'status_code' => $result['status_code'] ?? 0,
        'curl_error' => $result['curl_error'] ?? null,
        'raw_response' => $result['raw_response'] ?? null,
        'data' => $result['data'] ?? []
    ];
}
    /**
     * Verify destination account
     * STANDARD: Returns consistent verification structure
     */
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
        $result = $adapter->verifyAccount($verifyPayload, [
            'swap_reference' => $this->currentSwapRef,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $institution,
            'destination_identifier' => $destinationIdentifier,
            'destination_asset_type' => $destinationAssetType,
            'signed_payloads' => $this->signedPayloads
        ]);

        $verified = $result['verified'] ?? false;
        $success = $result['success'] ?? $verified;

        // ============================================================
        // STANDARDIZED RESPONSE STRUCTURE
        // ============================================================
        return [
            'success' => $success,
            'verified' => $verified,
            'message' => $result['message'] ?? 'Account verification completed',
            'account_name' => $result['account_name'] ?? null,
            'account_type' => $result['account_type'] ?? null,
            'status' => $result['status'] ?? 'ACTIVE',
            'currency' => $result['currency'] ?? null,
            'account_identifier' => $destinationIdentifier['identifier'],
            'identifier_type' => $destinationIdentifier['type'],
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'data' => $result['data'] ?? []
        ];
    }

    /**
     * Process deposit with proof
     * STANDARD: Consistent across all layers
     */
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
        
        $credited = $result['credited'] ?? false;

        if (!$credited) {
            // ============================================================
            // STANDARDIZED ERROR RESPONSE STRUCTURE
            // ============================================================
            return [
                'success' => false,
                'credited' => false,
                'message' => $result['message'] ?? 'Deposit failed',
                'transaction_reference' => $result['transaction_reference'] ?? null,
                'status' => 'FAILED',
                'status_code' => $result['status_code'] ?? 0,
                'curl_error' => $result['curl_error'] ?? null,
                'raw_response' => $result['raw_response'] ?? null,
                'data' => $result['data'] ?? []
            ];
        }

        // ============================================================
        // STANDARDIZED RESPONSE STRUCTURE
        // ============================================================
        return [
            'success' => true,
            'credited' => true,
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'message' => $result['message'] ?? 'Deposit successful',
            'status' => $result['status'] ?? 'COMPLETED',
            'new_balance' => $result['new_balance'] ?? null,
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'data' => $result['data'] ?? []
        ];
    }

    /**
     * Process destination with proof (for standard swaps)
     * STANDARD: Returns consistent response
     */
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

        $success = $result['success'] ?? false;

        if (!$success) {
            return [
                'success' => false,
                'credited' => false,
                'message' => $result['message'] ?? 'Destination processing failed',
                'transaction_reference' => $result['transaction_reference'] ?? null,
                'status' => 'FAILED',
                'status_code' => $result['status_code'] ?? 0,
                'curl_error' => $result['curl_error'] ?? null,
                'raw_response' => $result['raw_response'] ?? null,
                'data' => $result['data'] ?? []
            ];
        }

        // ============================================================
        // STANDARDIZED RESPONSE STRUCTURE
        // ============================================================
        return [
            'success' => true,
            'credited' => true,
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'message' => $result['message'] ?? 'Destination processed successfully',
            'status' => 'COMPLETED',
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'data' => $result['data'] ?? []
        ];
    }

    // ============================================================================
    // REMAINING PRIVATE METHODS - KEEP AS IS
    // ============================================================================

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

   private function storeIdentityHold(array $payload, string $swapRef, array $holdResult, int $holdId): array
{
    $sourceInstitution = $this->extractSourceInstitution($payload);
    $identityType = strtolower($payload['identity_type']);
    $identityValue = $payload['identity_value'];
 
    $owner = $this->findVerifiedIdentityOwner($identityType, $identityValue);
    $notificationPhone = $payload['notification_phone'] ?? $payload['beneficiary_phone'] ?? null;

    $levyAmount = (float)($this->feesConfig['DEPOSIT']['fee_components']['F7']['amount'] ?? 0);
 
    $claimType = null;
    $otpHash = null;
    $otpPlaintext = null;
    $otpDestination = null;
    $otpDestinationType = null; // 'phone' or 'email'
    $requiresDual = false;
 
    if ($owner) {
        // ============================================================
        // FIX: Registered/verified identities now ALSO get an OTP,
        // sent to the OWNER'S OWN registered contact (not whatever
        // notification_phone the sender supplied - that could be
        // stale or belong to someone else). This lets:
        //   - the owner finalize with just their account PIN (no OTP
        //     needed) when self-service and logged in, OR
        //   - an agent finalize on the owner's behalf, but ONLY with
        //     the OTP the owner shows them (never the account PIN,
        //     which the agent should never see/know).
        // ============================================================
        $claimType = 'account_pin';
        error_log("[SwapService] Identity {$identityType}={$identityValue} is a VERIFIED registered owner (user_id={$owner['user_id']}) - generating OTP for agent-assisted claims, account PIN available for self-service");

        [$otpDestination, $otpDestinationType] = $this->getOwnerContactForOtp($owner['user_id']);

        if ($otpDestination) {
            $otp = $this->generateOtpPin();
            $otpPlaintext = $otp;
            $otpHash = password_hash($otp, PASSWORD_DEFAULT);

            if ($otpDestinationType === 'phone' && $this->smsService) {
                try {
                    $this->smsService->sendCashoutCode($otpDestination, $otp, (float)$payload['amount'], $swapRef);
                    $this->trackIdentityOtpSmsAttempt($swapRef, $otpDestination, 'queued');
                } catch (Exception $e) {
                    error_log("[SwapService] Failed to SMS claim PIN to registered owner: " . $e->getMessage());
                    $this->trackIdentityOtpSmsAttempt($swapRef, $otpDestination, 'failed', $e->getMessage());
                }
            } elseif ($otpDestinationType === 'email') {
                error_log("[SwapService] Registered owner's contact is email ({$otpDestination}) - email OTP delivery not wired in this method yet, PIN available via account login fallback only");
            }
        } else {
            error_log("[SwapService] WARNING: Registered owner user_id={$owner['user_id']} has no usable phone/email on file for OTP delivery - agent-assisted claims will not be possible until this is fixed");
        }

    } elseif ($notificationPhone) {
        $claimType = 'otp_pin';
        $otp = $this->generateOtpPin();
        $otpPlaintext = $otp;
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $otpDestination = $notificationPhone;
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
        $claimType = 'dual_confirmation';
        $requiresDual = true;
        error_log("[SwapService] WARNING: Identity {$identityType}={$identityValue} has no registered owner and no phone - flagged for dual confirmation");
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
            ':otp_pin_sent_to' => $otpHash ? $otpDestination : null,
            ':otp_pin_sent_at' => $otpHash ? date('Y-m-d H:i:s') : null,
            ':requires_dual' => $requiresDual ? 't' : 'f',
            ':claim_type' => $claimType
        ]);
 
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'hold_id' => $row ? (int)$row['hold_id'] : 0,
            'claim_pin' => $otpPlaintext,
            'claim_type' => $claimType,
        ];
 
    } catch (PDOException $e) {
        error_log("[SwapService] Failed to store identity hold: " . $e->getMessage());
        throw new RuntimeException("Failed to store identity hold: " . $e->getMessage());
    }
}

/**
 * Looks up a verified owner's own phone/email for OTP delivery,
 * for the case where money is sent to a REGISTERED identity and we
 * need to notify the actual account holder (not the sender's
 * arbitrary notification_phone, which may be wrong or belong to
 * someone else entirely).
 *
 * @return array{0: ?string, 1: ?string} [destination, type] where
 *         type is 'phone' or 'email', or [null, null] if neither exists.
 */
private function getOwnerContactForOtp(int $userId): array
{
    try {
        $stmt = $this->swapDB->prepare("SELECT phone, email FROM users WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [null, null];
        }
        if (!empty($user['phone'])) {
            return [$user['phone'], 'phone'];
        }
        if (!empty($user['email'])) {
            return [$user['email'], 'email'];
        }
        return [null, null];
    } catch (PDOException $e) {
        error_log("[SwapService] getOwnerContactForOtp failed for user_id={$userId}: " . $e->getMessage());
        return [null, null];
    }
}

/**
 * Track an identity-swap OTP PIN send attempt in message_outbox
 */
private function trackIdentityOtpSmsAttempt(
    string $swapRef,
    string $phone,
    string $status,
    ?string $providerError = null
): void {
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
 * Verifies the PIN supplied at claim time.
 * 
 * FIX: This now checks ALL pending holds for the identity to find a matching PIN.
 * Once a match is found, it CLEARS the PIN hash (single-use) and marks the 
 * IDENTITY as authorized (the "green light") allowing ALL holds to be finalized.
 */
private function verifyIdentityClaimPin(array $identitySwap, string $suppliedPin, string $confirmedByType = 'agent'): void
{
    $claimType = $identitySwap['claim_type'] ?? null;
    $holdId = (int)$identitySwap['hold_id'];
    $identityType = $identitySwap['identity_type'];
    $identityValue = $identitySwap['identity_value'];
 
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

    // ============================================================
    // FIX: For a registered/verified identity, branch on WHO is
    // finalizing rather than always trusting the account PIN path.
    // ============================================================
    if ($claimType === 'account_pin' && $confirmedByType === 'user') {
        $owner = $this->findVerifiedIdentityOwner($identityType, $identityValue);
        if (!$owner) {
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
 
        $stmt = $this->swapDB->prepare("UPDATE users SET transaction_pin_attempts = 0 WHERE user_id = :id");
        $stmt->execute([':id' => $owner['user_id']]);
        
        $this->markIdentityHoldsAuthorized($identityType, $identityValue, 'account_pin_verification');
        return;
    }

    // Every other path (unregistered identity, OR a registered
    // identity being finalized by an agent) requires the OTP.
    if ($claimType === 'account_pin' || $claimType === 'otp_pin') {
        $stmt = $this->swapDB->prepare("
            SELECT hold_id, otp_pin_hash, otp_pin_locked_until, otp_pin_attempts
            FROM identity_swap_holds 
            WHERE identity_type = :type 
              AND identity_value = :value 
              AND status = 'pending'
              AND otp_pin_hash IS NOT NULL
              AND hold_expires_at > NOW()
            ORDER BY created_at ASC
        ");
        $stmt->execute([
            ':type' => $identityType,
            ':value' => $identityValue
        ]);
        $allHolds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($allHolds)) {
            if ($claimType === 'account_pin') {
                throw new RuntimeException("No OTP is available for this claim right now. Ask the account owner to check their registered phone/email, or have them finalize it themselves by logging in.");
            }
            throw new RuntimeException("No pending holds found with a PIN for this identity.");
        }
        
        $matchedHold = null;
        $firstHold = $allHolds[0];
        
        foreach ($allHolds as $hold) {
            $this->assertNotLocked($hold['otp_pin_locked_until'] ?? null, 'claim PIN');
            
            if (!empty($hold['otp_pin_hash']) && password_verify($suppliedPin, $hold['otp_pin_hash'])) {
                $matchedHold = $hold;
                break;
            }
        }
        
        if (!$matchedHold) {
            $targetHold = $firstHold;
            foreach ($allHolds as $hold) {
                if ((int)($hold['otp_pin_attempts'] ?? 0) > (int)($targetHold['otp_pin_attempts'] ?? 0)) {
                    $targetHold = $hold;
                }
            }
            $this->recordFailedIdentityOtpAttempt(
                (int)$targetHold['hold_id'], 
                (int)($targetHold['otp_pin_attempts'] ?? 0)
            );
            throw new RuntimeException("Incorrect claim PIN.");
        }
 
        $stmt = $this->swapDB->prepare("
            UPDATE identity_swap_holds
            SET 
                otp_pin_verified_at = NOW(),
                otp_pin_attempts = 0,
                otp_pin_hash = NULL
            WHERE hold_id = :id
        ");
        $stmt->execute([':id' => $matchedHold['hold_id']]);
        
        $this->markIdentityHoldsAuthorized($identityType, $identityValue, 'pin_verification');
 
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

    
/**
 * beginAtomicSwap() - Hardened so $this->inAtomicSwap is only set to true AFTER the PDO
 * transaction has actually started successfully.
 */
private function beginAtomicSwap(string $reference): void
{
    if ($this->inAtomicSwap) {
        throw new RuntimeException("Already in atomic swap: {$this->currentSwapRef}");
    }
 
    error_log("[DEBUG][agg_claim] beginAtomicSwap reference={$reference} pdo_in_transaction=" . ($this->swapDB->inTransaction() ? 'true' : 'false'));
 
    $this->swapDB->beginTransaction();
 
    $this->currentSwapRef = $reference;
    $this->inAtomicSwap = true;
    $this->executedSteps = [];
    $this->stepResults = [];
    $this->signedPayloads = [];
 
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
            $this->pendingRemainder = [];
            
        } catch (Exception $e) {
            error_log("[SwapService] ERROR processing remainder for {$remainder['swap_reference']}: " . $e->getMessage());
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
        if (!isset($this->atmNotes[$currency])) {
            throw new RuntimeException(
                "No ATM denominations configured for currency: {$currency}. " .
                "Please check atm_notes.json in the country config."
            );
        }
        
        return $this->atmNotes[$currency];
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

    /**
     * Lets a logged-in user attach an identity to their own account.
     *
     * Enforces global uniqueness across ALL users: if identity_type +
     * identity_value is already registered (in any status) to a DIFFERENT
     * user, this is rejected outright - nobody can claim someone else's
     * national ID / phone / email as their own to intercept future funds
     * sent to it.
     *
     * Phone numbers: an OTP is texted immediately; the identity only
     * becomes 'verified' (and thus usable for claim_type='account_pin')
     * after verifyUserIdentityOtp() succeeds.
     *
     * national_id / birth_certificate / voter_id: there's no way to prove
     * physical document ownership from a dashboard form alone, so these
     * are inserted as 'pending_review' and require manual/ops approval
     * before they flip to 'verified' - same posture already used for
     * agent destination accounts and user source accounts elsewhere in
     * this file when a bank offers no OTP/OAuth proof.
     *
     * email: also inserted as 'pending_review' for now (no email-OTP
     * channel wired up in this codebase yet) - flagged here rather than
     * silently trusting it.
     */
    public function registerUserIdentity(int $userId, string $identityType, string $identityValue): array
    {
        $identityType = strtolower(trim($identityType));
        $identityValue = trim($identityValue);

        if (!$this->isValidIdentityType($identityType)) {
            throw new RuntimeException("Invalid identity_type. Must be one of: " . $this->validIdentityTypesLabel());
        }
        if ($identityValue === '') {
            throw new RuntimeException("identity_value is required");
        }

        // ============================================================
        // UNIQUENESS: reject if this identity is already registered to
        // a DIFFERENT user, in any status (pending_review counts too -
        // first claimant wins the review queue, not a race at verify time).
        // ============================================================
        $stmt = $this->swapDB->prepare("
            SELECT user_id, status FROM user_identities
            WHERE identity_type = :type AND identity_value = :value
            LIMIT 1
        ");
        $stmt->execute([':type' => $identityType, ':value' => $identityValue]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && (int)$existing['user_id'] !== $userId) {
            error_log("[SwapService] registerUserIdentity: REJECTED - {$identityType}={$identityValue} already registered to a different user_id={$existing['user_id']}");
            throw new RuntimeException("This identity is already registered to another VouchMorph account. If this is a mistake, contact support.");
        }

        if ($existing && (int)$existing['user_id'] === $userId) {
            // Already theirs - idempotent response rather than an error.
            if ($existing['status'] === 'verified') {
                return [
                    'requires_otp' => false,
                    'status' => 'verified',
                    'message' => 'This identity is already verified on your account.',
                ];
            }
            return [
                'requires_otp' => false,
                'status' => $existing['status'],
                'message' => 'This identity is already registered and awaiting verification.',
            ];
        }

        // Self-service phone OTP path.
        if ($identityType === 'phone') {
            if (!$this->smsService) {
                throw new RuntimeException("SMS verification is not available right now - try again later.");
            }

            $otp = $this->generateOtpPin();
            $otpHash = password_hash($otp, PASSWORD_DEFAULT);

            $stmt = $this->swapDB->prepare("
                INSERT INTO user_identities (
                    user_id, identity_type, identity_value, status,
                    otp_pin_hash, otp_expires_at, created_at
                ) VALUES (
                    :user_id, :type, :value, 'pending_otp',
                    :otp_hash, :otp_expires_at, NOW()
                ) RETURNING id
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':type' => $identityType,
                ':value' => $identityValue,
                ':otp_hash' => $otpHash,
                ':otp_expires_at' => date('Y-m-d H:i:s', time() + 600),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $attemptId = $row ? (int)$row['id'] : 0;

            try {
                $this->smsService->sendCashoutCode($identityValue, $otp, 0, 'IDENTITY_VERIFY_' . $attemptId);
            } catch (Exception $e) {
                error_log("[SwapService] registerUserIdentity: failed to SMS verification code: " . $e->getMessage());
                throw new RuntimeException("Could not send the verification code - try again.");
            }

            error_log("[SwapService] registerUserIdentity: OTP sent for phone identity, user_id={$userId}, attempt_id={$attemptId}");

            return [
                'requires_otp' => true,
                'attempt_id' => $attemptId,
                'status' => 'pending_otp',
                'message' => 'A verification code has been texted to this number.',
            ];
        }

        // Document / email path - no self-service proof available yet.
        $stmt = $this->swapDB->prepare("
            INSERT INTO user_identities (
                user_id, identity_type, identity_value, status, created_at
            ) VALUES (
                :user_id, :type, :value, 'pending_review', NOW()
            ) RETURNING id
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $identityType,
            ':value' => $identityValue,
        ]);

        error_log("[SwapService] registerUserIdentity: {$identityType}={$identityValue} submitted for manual review, user_id={$userId}");

        return [
            'requires_otp' => false,
            'status' => 'pending_review',
            'message' => 'Submitted for review. Document-based identities are verified manually before they can be used with your transaction PIN.',
        ];
    }


  // Completes phone-based identity registration.
    
    public function verifyUserIdentityOtp(int $userId, int $attemptId, string $otp): array
    {
        $stmt = $this->swapDB->prepare("
            SELECT * FROM user_identities
            WHERE id = :id AND user_id = :user_id AND status = 'pending_otp'
        ");
        $stmt->execute([':id' => $attemptId, ':user_id' => $userId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attempt) {
            throw new RuntimeException("Verification attempt not found.");
        }
        if (strtotime($attempt['otp_expires_at']) < time()) {
            throw new RuntimeException("Verification code expired. Start again.");
        }
        if (empty($attempt['otp_pin_hash']) || !password_verify($otp, $attempt['otp_pin_hash'])) {
            throw new RuntimeException("Incorrect verification code.");
        }

        $stmt = $this->swapDB->prepare("
            UPDATE user_identities
            SET status = 'verified', otp_pin_hash = NULL, verified_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $attemptId]);

        error_log("[SwapService] verifyUserIdentityOtp: identity id={$attemptId} verified for user_id={$userId}");

        return [
            'status' => 'verified',
            'message' => 'Identity verified. You can now finalize identity swaps sent to it with your transaction PIN.',
        ];
    }
}
