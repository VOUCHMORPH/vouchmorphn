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
use Domain\Services\Compliance\SanctionsScreeningService;
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
    private const IDENTITY_PROFILE_GOVERNMENT_TYPES = ['national_id', 'voter_id', 'birth_certificate', 'drivers_license', 'passport'];

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
    private SanctionsScreeningService $sanctionsScreening;
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
    private bool $postDeliveryDebitFailure = false;   // NEW

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
$this->certificateManager = \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
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
        $this->loadAtmNotesStrict($countryConfig, $country);        
        error_log("[SwapService] Loaded fees config from LoadCountry");
        error_log("[SwapService] Config keys: " . implode(', ', array_keys($this->feesConfig)));
        
        $this->adapterFactory = new InstitutionAdapterFactory(
            $this->participants,
            $this->logger
        );
        $this->logger->info("InstitutionAdapterFactory initialized");
        
     $this->settlement = new HybridSettlementStrategy($this->swapDB, [], $this->participants);
$this->sanctionsScreening = new SanctionsScreeningService(
    $this->swapDB,
    getenv('SANCTIONS_SCREENING_MODE') ?: 'LOCAL_LIST',
    (bool)(getenv('SANCTIONS_FAIL_OPEN') ?: false)
);
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
    $this->cardService = new CardService($this->swapDB, $this->countryCode, $vouchmorphConfig, $this->feeService, $this->forexService);
}
        
        error_log("[SwapService] Initializing Multi-Source components...");
        
        try {
            $this->contributionCalculator = new ContributionCalculator();
$this->multiSourceFeeCalculator = new MultiSourceFeeCalculator($this->feesConfig, $this->countryCode);            
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

 // --- Fix 1: src/Domain/Services/SwapService.php ---
// Add this getter anywhere in the class (e.g. right after __construct):

public function getAdapterFactory(): \Infrastructure\Adapters\InstitutionAdapterFactory
{
    return $this->adapterFactory;
}

 public function setCurrentSwapReference(string $reference): void
    {
        $this->currentSwapRef = $reference;
    }
 
public function getParticipants(): array
{
    return $this->participants;
}

private function extractOriginatorPartyData(array $payload): array
{
    return [
        'name' => $payload['originator_name'] ?? null,
        'id_number' => $payload['originator_id_number'] ?? null,
    ];
}

private function extractBeneficiaryPartyData(array $payload): array
{
    return [
        'name' => $payload['beneficiary_name'] ?? null,
        'id_number' => $payload['beneficiary_id_number'] ?? null,
    ];
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
    
    // CARD added deliberately — this is a VISA_MASTERCARD_CARD destination
    // (card LOAD via CardAcquirerBankClient::processDepositWithProof()),
    // not VouchMorph's own vaulted CARD system (that's a completely
    // separate flow through CardService, never through this method).
    $validTypes = ['ACCOUNT', 'WALLET', 'CARD'];
    
    if (!in_array($assetType, $validTypes)) {
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
    
    // First, determine the asset type
    $assetType = $this->extractDestinationAssetType($payload);
    
    // Set the appropriate identifier type based on asset type
    if ($assetType === 'WALLET') {
    $destinationIdentifierType = $payload['destination_identifier_type'] ?? 
                                 $payload['identifier_type'] ?? 
                                 'phone';
} elseif ($assetType === 'ACCOUNT') {
    $destinationIdentifierType = $payload['destination_identifier_type'] ?? 
                                 $payload['identifier_type'] ?? 
                                 'account_number';
} elseif ($assetType === 'CARD') {
    $destinationIdentifierType = $payload['destination_identifier_type'] ?? 
                                 $payload['identifier_type'] ?? 
                                 'card_token';
} else {
    $destinationIdentifierType = $payload['destination_identifier_type'] ?? 
                                 $payload['identifier_type'] ?? 
                                 'account';
}

$destinationIdentifier = $payload['destination_identifier'] ?? 
                         $payload['destination_card_token'] ??      // NEW — checked before
                                                                     // the generic fallbacks so
                                                                     // a card token doesn't get
                                                                     // mistaken for anything else
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

public function assertCanBeSourcePublic(string $institution): void
{
    $this->assertCanBeSource($institution);
}
 
   private function validateInstitutions(array $payload, bool $requireDestination = true): void
{
    $source = $this->extractSourceInstitution($payload);
    $this->assertCanBeSource($source);
    error_log("[SwapService] Source institution validated: {$source}");
    
    if ($requireDestination) {
        $dest = $this->extractDestinationInstitution($payload);
        error_log("[SwapService] Destination institution validated: {$dest}");
    }
}

/**
 * Rejects institutions that cannot be used as a swap source, at the
 * earliest possible point — before any verify/hold/debit attempt is
 * made. Driven by participants.yaml's capabilities.source flag, so
 * this is a config change, not a code change, when an institution's
 * real API capability changes (e.g. if MTN later integrates
 * Collection API and becomes debit-capable).
 */
private function assertCanBeSource(string $institution): void
{
    $participant = $this->participants[$institution]
        ?? $this->participants[strtoupper($institution)]
        ?? null;

    if ($participant === null) {
        // Unknown institution — let the existing downstream "Participant
        // not found" handling in getParticipant()/adapter resolution
        // catch this; not this method's job to guess.
        return;
    }

    $canBeSource = $participant['capabilities']['source'] ?? true; // default true: don't silently block institutions that haven't been given a capabilities block yet

    if ($canBeSource === false) {
        throw new RuntimeException(
            "{$institution} cannot be used as a source for this swap — " .
            "it only supports receiving funds (deposit), not being debited. " .
            "Choose a different source institution."
        );
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

public function getForexService(): ForexService
{
    return $this->forexService;
}
    private function executeMultiSourceSwap(array $payload): array
    {
        error_log("[SwapService] ===== executeMultiSourceSwap START =====");
        error_log("[SwapService] Multi-Source payload has " . count($payload['sources'] ?? []) . " sources");
 
        if ($this->multiSourceOrchestrator === null) {
            error_log("[SwapService] Multi-Source orchestrator not available");
            $this->logger->error("Multi-source swap requested but orchestrator not initialized");
            throw new RuntimeException("Multi-source orchestrator is not available. This swap cannot be processed.");
        }
 
        try {
            error_log("[SwapService] Delegating to MultiSourceOrchestrator");
            $result = $this->multiSourceOrchestrator->execute($payload);
            error_log("[SwapService] MultiSourceOrchestrator returned: " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
            return $result;
        } catch (Exception $e) {
            error_log("[SwapService] MultiSourceOrchestrator threw exception: " . $e->getMessage());
            $this->logger->error("Multi-source swap failed", ['error' => $e->getMessage()]);
 
            throw new RuntimeException("Multi-source swap failed: " . $e->getMessage());
        }
    }
 

// ============================================================================
// PENDING SOURCES MANAGEMENT - GET, DELETE, RETRY
// ============================================================================

public function getPendingSources(int $userId): array
{
    $sources = [];

    // USER SOURCES
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
            proposed_at AS created_at,
            'user_source' AS type,
            NULL AS rejection_reason
        FROM user_source_accounts
        WHERE user_id = :user_id
        AND status IN ('pending_confirmation','pending','proposed','failed')
        AND deleted_at IS NULL
        ORDER BY proposed_at DESC
    ");

    $stmt->execute([':user_id'=>$userId]);
    $sources = array_merge($sources,$stmt->fetchAll(PDO::FETCH_ASSOC));


    // AGENT DESTINATIONS
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
            proposed_at AS created_at,
            'agent_destination' AS type,
            rejection_reason
        FROM agent_destination_accounts
        WHERE user_id = :user_id
        AND status IN ('pending_confirmation','pending','proposed','failed')
        AND deleted_at IS NULL
        ORDER BY proposed_at DESC
    ");

    $stmt->execute([':user_id'=>$userId]);
    $sources = array_merge($sources,$stmt->fetchAll(PDO::FETCH_ASSOC));


    // USER REGISTRATION ATTEMPTS
    $stmt=$this->swapDB->prepare("
        SELECT
            id,
            institution,
            asset_type,
            identifier,
            identifier_type,
            account_name,
            NULL AS account_type,
            status,
            created_at,
            'registration_attempt' AS type,
            NULL AS rejection_reason,
            otp_method,
            otp_expires_at
        FROM user_source_registration_attempts
        WHERE user_id=:user_id
        AND status IN ('otp_pending','oauth_pending')
        ORDER BY created_at DESC
    ");

    $stmt->execute([':user_id'=>$userId]);
    $sources=array_merge($sources,$stmt->fetchAll(PDO::FETCH_ASSOC));


    // AGENT REGISTRATION ATTEMPTS
    $stmt=$this->swapDB->prepare("
        SELECT
            id,
            institution,
            asset_type,
            identifier,
            identifier_type,
            account_name,
            account_type,
            status,
            created_at,
            'agent_attempt' AS type,
            NULL AS rejection_reason,
            otp_method,
            otp_expires_at
        FROM agent_registration_attempts
        WHERE user_id=:user_id
        AND status IN ('otp_pending','oauth_pending')
        ORDER BY created_at DESC
    ");

    $stmt->execute([':user_id'=>$userId]);
    $sources=array_merge($sources,$stmt->fetchAll(PDO::FETCH_ASSOC));


    foreach($sources as &$source){

        $source['institution_name'] =
            $this->participants[$source['institution']]['name']
            ?? $source['institution'];


        if(!empty($source['otp_expires_at'])){

            $expiry=strtotime($source['otp_expires_at']);

            $source['is_expiring'] =
                ($expiry-time()) < 60;

            $source['expires_at'] =
                $source['otp_expires_at'];
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

    // Only account tables support soft delete
    $hasDeletedAt = in_array($type, [
        'user_source',
        'agent_destination'
    ]);

    // Check ownership
    $sql = "
        SELECT id, status 
        FROM {$table}
        WHERE id = :id
        AND user_id = :user_id
    ";

    if ($hasDeletedAt) {
        $sql .= " AND deleted_at IS NULL ";
    }

    $stmt = $this->swapDB->prepare($sql);

    $stmt->execute([
        ':id' => $sourceId,
        ':user_id' => $userId
    ]);

    $source = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$source) {
        throw new RuntimeException(
            "Source not found or does not belong to you."
        );
    }


    // Account tables
    if ($hasDeletedAt) {

        $stmt = $this->swapDB->prepare("
            UPDATE {$table}
            SET 
                status = 'cancelled',
                deleted_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
            AND user_id = :user_id
        ");

    } 
    // Registration attempt tables
    else {

        $cancelColumn = ($type === 'registration_attempt')
            ? 'cancelled_at'
            : null;


        $stmt = $this->swapDB->prepare("
            UPDATE {$table}
            SET 
                status = 'cancelled',
                cancelled_at = NOW()
            WHERE id = :id
            AND user_id = :user_id
        ");
    }


    $stmt->execute([
        ':id'=>$sourceId,
        ':user_id'=>$userId
    ]);


    // Cancel related attempts
    if ($type === 'user_source' || $type === 'agent_destination') {

        $this->cancelPendingAttemptsBySource(
            $userId,
            $source['institution'] ?? '',
            $source['identifier'] ?? ''
        );
    }


    return [
        'success'=>true,
        'message'=>'Source deleted successfully.'
    ];
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
        $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn-production.up.railway.app', '/')
            . '/api/v1/user/source_oauth_callback.php';
        
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
        $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn-production.up.railway.app', '/')
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
 $otpEncrypted = $this->encryptSourceSecret($otp); // reuses the existing AES-256-CBC helper
    
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
// In SwapService.php

private function encryptSourceSecret(?string $plaintext): ?string 
{ 
    return \Infrastructure\Crypto\SourceSecretCipher::encrypt($plaintext); 
}

private function decryptSourceSecret(?string $encrypted): ?string 
{ 
    return \Infrastructure\Crypto\SourceSecretCipher::decrypt($encrypted); 
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
    

/**
 * ============================================================================
 * FIX: SAVEPOINT-protected tracking table population
 * ============================================================================
 *
 * ROOT CAUSE THIS FIXES:
 * In PostgreSQL, once ANY statement inside a transaction fails, the entire
 * transaction enters an "aborted" state (SQLSTATE 25P02). Every subsequent
 * statement is rejected - INCLUDING a plain PHP try/catch that logs the
 * error and "continues". Worse: the eventual $pdo->commit() does NOT throw
 * an exception in this state - Postgres silently converts it into a
 * ROLLBACK. This means the swap function returns 'status: committed' with
 * a real-looking auth_id/hold_id, while zero rows actually exist in the
 * database.
 *
 * A plain try/catch around an INSERT cannot rescue this - PHP has no idea
 * the underlying Postgres session is poisoned. Only a real SAVEPOINT +
 * ROLLBACK TO SAVEPOINT clears the aborted state and lets the outer
 * transaction continue normally.
 *
 * This file wraps every individually-"non-critical" tracking insert in its
 * own SAVEPOINT via runInSavepoint(), so a failure in, say, audit_logs
 * can no longer silently kill the swap_requests / hold_transactions /
 * cashout_authorizations rows that already committed successfully earlier
 * in the same atomic swap.
 * ============================================================================
 */

/**
 * Run a callable inside a Postgres SAVEPOINT so that if it fails, only ITS
 * work is undone - the outer atomic-swap transaction is NOT poisoned.
 *
 * WHY THIS EXISTS (read before removing it):
 * "catch, log, and continue" is not actually possible against a live
 * Postgres connection without a SAVEPOINT. Skipping this wrapper
 * reintroduces the exact bug where executeAtomicSwap() reports
 * 'status: committed' with a real auth_id/hold_id, but the whole
 * transaction was silently rolled back underneath it.
 */
private function runInSavepoint(string $label, callable $fn)
{
    $safeName = 'sp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $label);

    try {
        $this->swapDB->exec("SAVEPOINT {$safeName}");
    } catch (PDOException $e) {
        // Can't even create the savepoint - the connection is already in a
        // state we can't safely operate on. Rethrow rather than silently
        // proceeding, since we no longer know what state we're in.
        $this->logger->error("Failed to create savepoint {$safeName}", ['error' => $e->getMessage()]);
        throw $e;
    }

    try {
        $result = $fn();
        $this->swapDB->exec("RELEASE SAVEPOINT {$safeName}");
        return $result;
    } catch (\Throwable $e) {
        try {
            $this->swapDB->exec("ROLLBACK TO SAVEPOINT {$safeName}");
            $this->swapDB->exec("RELEASE SAVEPOINT {$safeName}");
        } catch (PDOException $rollbackError) {
            // If even ROLLBACK TO SAVEPOINT fails, the outer transaction is
            // genuinely unrecoverable - surface this loudly rather than
            // pretending everything is fine.
            $this->logger->error(
                "ROLLBACK TO SAVEPOINT {$safeName} itself failed - outer transaction may be unrecoverable",
                [
                    'original_error' => $e->getMessage(),
                    'rollback_error' => $rollbackError->getMessage()
                ]
            );
        }
        throw $e;
    }
}

/**
 * Populate all tracking tables from swap data
 * Called after successful swap completion
 *
 * CRITICAL FIXES:
 * 1. NEVER let a tracking failure roll back the transaction - but this can
 *    ONLY be guaranteed with a SAVEPOINT per insert (see runInSavepoint()
 *    above). A bare try/catch alone does NOT achieve this on Postgres.
 * 2. cashout_authorizations is ALREADY populated by storeCashoutAuthorization()
 *    earlier in executeSignedCashout(). DO NOT populate it again here!
 * 3. Each table population runs in its own SAVEPOINT + try/catch, so one
 *    failure cannot affect any other table or the outer commit.
 * 4. Detailed logging of what was populated and what failed.
 */
private function populateTrackingTables(array $swapData, array $details, ?array $destResponse = null): void
{
    $swapType = $swapData['swap_type'] ?? 'STANDARD';
    $swapRef = $swapData['reference'] ?? $this->currentSwapRef;
    $userId = $details['user_id'] ?? $swapData['user_id'] ?? null;

    $populated = [];
    $errors = [];
    $swapId = null;

    // ============================================================
    // 1. Populate swap_requests - master record
    // ============================================================
    try {
        $swapId = $this->runInSavepoint('swap_request_' . $swapRef, function () use ($swapRef, $swapData, $details, $userId) {
            return $this->populateSwapRequest($swapRef, $swapData, $details, $userId);
        });
        if ($swapId) {
            $populated[] = 'swap_requests (id: ' . $swapId . ')';
        } else {
            $errors[] = 'swap_requests returned no swap_id';
        }
    } catch (\Throwable $e) {
        $errors[] = 'swap_requests: ' . $e->getMessage();
        $this->logger->error("Failed to populate swap_requests", [
            'reference' => $swapRef,
            'error' => $e->getMessage()
        ]);
        // Safely continued: the SAVEPOINT rollback above already undid
        // only this insert's effect, so the outer transaction is intact.
    }

    // ============================================================
    // 2. Populate swap_transactions - links to swap_id
    // ============================================================
    if ($swapId) {
        try {
            $this->runInSavepoint('swap_transaction_' . $swapRef, function () use ($swapId, $swapRef, $swapData, $details, $userId) {
                $this->populateSwapTransaction($swapId, $swapRef, $swapData, $details, $userId);
            });
            $populated[] = 'swap_transactions';
        } catch (\Throwable $e) {
            $errors[] = 'swap_transactions: ' . $e->getMessage();
            $this->logger->error("Failed to populate swap_transactions", [
                'reference' => $swapRef,
                'swap_id' => $swapId,
                'error' => $e->getMessage()
            ]);
        }
    } else {
        $this->logger->warning("No swap_id available, skipping swap_transactions", ['swap_ref' => $swapRef]);
        $errors[] = 'swap_transactions skipped (no swap_id)';
    }

    // ============================================================
    // 3. Populate type-specific tables
    // ============================================================
    if ($swapType === 'CASHOUT') {
        // ⚠️ CRITICAL: cashout_authorizations is ALREADY populated by
        // storeCashoutAuthorization() earlier in executeSignedCashout().
        // DO NOT populate it again here - that would cause:
        //   1. Duplicate work
        //   2. Potential SQL errors with ON CONFLICT
        //   3. Risk of throwing exceptions that roll back the transaction
        //
        // We ONLY populate message_outbox here (SMS notifications)
        try {
            $this->runInSavepoint('message_outbox_' . $swapRef, function () use ($swapRef, $swapData, $details, $destResponse, $userId) {
                $this->populateMessageOutbox($swapRef, $swapData, $details, $destResponse, $userId);
            });
            $populated[] = 'message_outbox';
        } catch (\Throwable $e) {
            $errors[] = 'message_outbox: ' . $e->getMessage();
            $this->logger->error("Failed to populate message_outbox", [
                'reference' => $swapRef,
                'error' => $e->getMessage()
            ]);
        }

    } elseif ($swapType === 'DEPOSIT') {
        try {
            $this->runInSavepoint('deposit_tx_' . $swapRef, function () use ($swapRef, $swapData, $details, $userId) {
                $this->populateDepositTransaction($swapRef, $swapData, $details, $userId);
            });
            $populated[] = 'deposit_transactions';
        } catch (\Throwable $e) {
            $errors[] = 'deposit_transactions: ' . $e->getMessage();
            $this->logger->error("Failed to populate deposit_transactions", [
                'reference' => $swapRef,
                'error' => $e->getMessage()
            ]);
        }

    } elseif ($swapType === 'IDENTITY' || $swapType === 'CONFIRM_IDENTITY') {
        // Identity swaps are tracked in identity_swap_holds table
        // No additional tracking needed here
        $populated[] = 'identity_swap_holds (already populated)';

    } elseif ($swapType === 'MULTI_SOURCE' || $swapType === 'MULTI_DESTINATION') {
        // Multi-source/destination swaps have their own tracking
        // No additional tracking needed here
        $populated[] = 'multi_destination_swaps (already populated)';
    }

    // ============================================================
    // 4. Populate audit_logs (optional but recommended)
    // ============================================================
    try {
        $this->runInSavepoint('audit_log_' . $swapRef, function () use ($swapRef, $swapType, $swapData, $details, $userId) {
            $this->populateAuditLog($swapRef, $swapType, $swapData, $details, $userId);
        });
        $populated[] = 'audit_logs';
    } catch (\Throwable $e) {
        $errors[] = 'audit_logs: ' . $e->getMessage();
        $this->logger->warning("Failed to populate audit_logs", [
            'reference' => $swapRef,
            'error' => $e->getMessage()
        ]);
    }

    // ============================================================
    // 5. Log summary
    // ============================================================
    if (empty($errors)) {
        $this->logger->info("All tracking tables populated successfully", [
            'reference' => $swapRef,
            'type' => $swapType,
            'tables' => $populated
        ]);
    } else {
        $this->logger->warning("Tracking tables populated with errors", [
            'reference' => $swapRef,
            'type' => $swapType,
            'populated' => $populated,
            'errors' => $errors
        ]);
    }
}


private function populateAuditLog(string $swapRef, string $swapType, array $swapData, array $details, ?int $userId = null): void
{
    // Use the single writeAuditLogEntry() method for consistency
    $this->writeAuditLogEntry(
        'swap_requests',
        $swapRef,
        'SWAP_' . strtoupper($swapType) . '_CREATED',
        'financial',
        $userId,
        $userId ? 'user' : 'system',
        $userId ?? 0,
        null
    );
}

/**
 * Single write path for every audit_logs insert in this class.
 * Computes entry_hash = SHA256(prev_hash || canonical fields) so the
 * chain is provable, and centralizes the dynamic-column introspection
 * that was previously duplicated across populateAuditLog(),
 * releaseCashoutHold(), confirmCashout(), and addVerifiedIdentityAsAgent().
 */
private function writeAuditLogEntry(
    string $entityType,
    string $entityId,
    string $action,
    string $category,
    ?int $userId = null,
    string $performedByType = 'system',
    ?int $performedById = 0,
    ?array $detailsPayload = null
): void {
    try {
        $stmt = $this->swapDB->query("SELECT * FROM audit_logs LIMIT 0");
        $cols = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $cols[] = $stmt->getColumnMeta($i)['name'];
        }
    } catch (\Throwable $e) {
        $this->writeAuditFallback($entityId, $action, 'audit_logs table unreadable: ' . $e->getMessage(), $userId);
        return;
    }

    if (!in_array('entity_id', $cols)) {
        $this->writeAuditFallback($entityId, $action, "audit_logs missing 'entity_id' - columns: " . implode(', ', $cols), $userId);
        return;
    }

    // Fetch the previous hash for chaining. Global chain (not per-entity)
    // - simpler to verify end-to-end, and gaps/tampering show up the
    // same way regardless of which entity_type/entity_id they touch.
    $prevHash = null;
    if (in_array('entry_hash', $cols)) {
        try {
            $prevHash = $this->swapDB->query(
                "SELECT entry_hash FROM audit_logs ORDER BY audit_id DESC LIMIT 1"
            )->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            // No prior rows, or column doesn't exist yet - chain starts at null.
        }
    }

    $performedAt = date('Y-m-d H:i:s');
    $canonical = json_encode([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'action' => $action,
        'performed_at' => $performedAt,
        'performed_by_id' => $performedById,
    ]);
    $entryHash = hash('sha256', ($prevHash ?? '') . $canonical);

    $fields = ['entity_type', 'entity_id', 'action', 'category'];
    $placeholders = [':entity_type', ':entity_id', ':action', ':category'];
    $params = [
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':action' => $action,
        ':category' => $category,
    ];

    $optional = [
        'severity' => 'info',
        'performed_at' => $performedAt,
        'performed_by_type' => $performedByType,
        'performed_by_id' => $performedById,
        'audit_uuid' => uniqid('audit_', true),
        'timestamp' => $performedAt,
        'user_id' => $userId,
        'prev_hash' => $prevHash,
        'entry_hash' => $entryHash,
    ];
    foreach ($optional as $col => $val) {
        if (in_array($col, $cols) && $val !== null) {
            $fields[] = $col;
            $placeholders[] = ":{$col}";
            $params[":{$col}"] = $val;
        }
    }
    // details/notes/metadata - whichever this deployment's schema has
    if ($detailsPayload !== null) {
        foreach (['details', 'notes'] as $col) {
            if (in_array($col, $cols)) {
                $fields[] = $col;
                $placeholders[] = ":{$col}";
                $params[":{$col}"] = json_encode($detailsPayload);
                break;
            }
        }
        if (in_array('metadata', $cols)) {
            $fields[] = 'metadata';
            $placeholders[] = ':metadata::jsonb';
            $params[':metadata'] = json_encode($detailsPayload);
        }
    }

    $sql = "INSERT INTO audit_logs (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";

    try {
        $this->runInSavepoint('audit_' . $entityId . '_' . uniqid(), function () use ($sql, $params) {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute($params);
        });
    } catch (\Throwable $e) {
        $this->writeAuditFallback($entityId, $action, 'Audit log insert failed: ' . $e->getMessage(), $userId);
    }
}

 
/**
 * Dead-simple, fixed-schema fallback for when the real audit_logs write
 * can't happen. Deliberately has NO dynamic column introspection and NO
 * ON CONFLICT clause, so it can't fail the same way the main path can.
 * Ops/compliance should monitor this table directly - any row in it
 * means "money moved (or a swap was attempted) without a normal audit
 * trail entry" and needs a human to look at it.
 *
 * Migration (run once):
 *   CREATE TABLE IF NOT EXISTS audit_log_failures (
 *       id BIGSERIAL PRIMARY KEY,
 *       swap_reference VARCHAR(255) NOT NULL,
 *       swap_type VARCHAR(50),
 *       user_id INTEGER,
 *       reason TEXT NOT NULL,
 *       created_at TIMESTAMP NOT NULL DEFAULT NOW(),
 *       resolved_at TIMESTAMP,
 *       resolved_by VARCHAR(100)
 *   );
 *   CREATE INDEX IF NOT EXISTS idx_audit_log_failures_unresolved
 *       ON audit_log_failures (created_at) WHERE resolved_at IS NULL;
 */
private function writeAuditFallback(string $swapRef, string $swapType, string $reason, ?int $userId): void
{
    try {
        $this->swapDB->exec("
            CREATE TABLE IF NOT EXISTS audit_log_failures (
                id BIGSERIAL PRIMARY KEY,
                swap_reference VARCHAR(255) NOT NULL,
                swap_type VARCHAR(50),
                user_id INTEGER,
                reason TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                resolved_at TIMESTAMP,
                resolved_by VARCHAR(100)
            )
        ");
        $stmt = $this->swapDB->prepare("
            INSERT INTO audit_log_failures (swap_reference, swap_type, user_id, reason)
            VALUES (:ref, :type, :user_id, :reason)
        ");
        $stmt->execute([
            ':ref' => $swapRef,
            ':type' => $swapType,
            ':user_id' => $userId,
            ':reason' => $reason
        ]);
        $this->logger->critical("Audit log write failed - recorded to audit_log_failures", [
            'swap_reference' => $swapRef,
            'reason' => $reason
        ]);
    } catch (\Throwable $e) {
        // If even THIS fails, there's nothing left to do but scream into
        // the log as loudly as possible - this should never happen given
        // the table's trivial schema, but don't let it throw further and
        // risk taking down the money-movement path over an audit issue.
        $this->logger->emergency("audit_log_failures fallback ITSELF failed - manual DB investigation required", [
            'swap_reference' => $swapRef,
            'original_reason' => $reason,
            'fallback_error' => $e->getMessage()
        ]);
    }
}
 


    
    /**
     * Get numeric swap_request_id from swap_uuid
     */
   private function getSwapRequestId(string $swapRef): ?int
{
    $sql = "SELECT swap_id FROM swap_requests WHERE swap_uuid = :swap_uuid";
    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':swap_uuid' => $swapRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['swap_id'] : null;   // FIXED
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
        swap_uuid, from_currency, to_currency, amount, source_details,
        destination_details, status, created_at, completed_at, source_country,
        destination_country, fee_breakdown, metadata, retry_count, forex_rate,
        forex_fee_percent, forex_fee_amount, total_forex_fee, trade_metadata,
        original_swap_ref, user_id, execution_rail, execution_rail_reference
    ) VALUES (
        :swap_uuid, :from_currency, :to_currency, :amount, :source_details::jsonb,
        :destination_details::jsonb, :status, :created_at, :completed_at, :source_country,
        :destination_country, :fee_breakdown::jsonb, :metadata::jsonb, 0, :forex_rate,
        :forex_fee_percent, :forex_fee_amount, :total_forex_fee, :trade_metadata::jsonb,
        :original_swap_ref, :user_id, :execution_rail, :execution_rail_reference
    ) ON CONFLICT (swap_uuid) DO UPDATE SET
        status = EXCLUDED.status,
        completed_at = COALESCE(swap_requests.completed_at, EXCLUDED.completed_at),
        forex_rate = EXCLUDED.forex_rate,
        forex_fee_percent = EXCLUDED.forex_fee_percent,
        forex_fee_amount = EXCLUDED.forex_fee_amount,
        total_forex_fee = EXCLUDED.total_forex_fee,
        trade_metadata = EXCLUDED.trade_metadata,
        fee_breakdown = EXCLUDED.fee_breakdown,
        user_id = EXCLUDED.user_id,
        execution_rail = EXCLUDED.execution_rail,
        execution_rail_reference = EXCLUDED.execution_rail_reference
    RETURNING swap_id
";

$status = $swapData['status'] ?? 'pending';
if (isset($details['status'])) {
    $status = $details['status'];
}

// Only stamp completed_at on the write that actually reports completion.
// COALESCE in the ON CONFLICT clause above means this never gets
// overwritten once set, and never gets set on a later non-completed update.
$completedAt = (strtolower($status) === 'completed') ? date('Y-m-d H:i:s') : null;
       
        
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
    ':completed_at' => $completedAt,
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
    ':user_id' => $userId,
    ':execution_rail' => $details['execution_rail'] ?? 'DIRECT',
    ':execution_rail_reference' => $details['execution_rail_reference'] ?? null
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
 * ============================================================================
 * FIX 1: populateMessageOutbox() — the ON CONFLICT clause targets a
 * constraint that does not exist on message_outbox, so this fails on
 * EVERY cashout. Two changes:
 *   1. Drop the bogus ON CONFLICT (destination, created_at) — there is no
 *      unique index backing it. If you actually want de-duplication,
 *      create the index first (see the migration below) and keep it.
 *   2. RETHROW instead of swallowing, so runInSavepoint() (the call site
 *      in populateTrackingTables()) is the single source of truth for
 *      success/failure — no more double-error-message artifact from the
 *      inner catch silently succeeding while the transaction is already
 *      poisoned underneath it.
 * ============================================================================
 */
/**
 * ============================================================================
 * FIX A: populateMessageOutbox() was missing message_id, which the table
 * requires NOT NULL. Generate one the same way trackIdentityOtpSmsAttempt()
 * already does elsewhere in this class, for consistency.
 * ============================================================================
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
 
    // FIX: message_id is NOT NULL on this table - generate one.
    $messageId = 'SMS_' . uniqid() . '_' . substr($swapRef, 0, 10);
 
    $sql = "
        INSERT INTO message_outbox (
            message_id,
            channel,
            destination,
            payload,
            status,
            created_at,
            sent_at,
            user_id
        ) VALUES (
            :message_id,
            'SMS',
            :destination,
            :payload::jsonb,
            'queued',
            :created_at,
            NULL,
            :user_id
        )
    ";
 
    $stmt = $this->swapDB->prepare($sql);
    $stmt->execute([
        ':message_id' => $messageId,
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
 
    $this->logger->debug("message_outbox populated", ['message_id' => $messageId, 'destination' => $phone, 'swap_ref' => $swapRef, 'user_id' => $userId]);
}
 
 /**
 * Records a swap that was executed via an external rail (e.g. a
 * national switch), not through this class's own verify/hold/debit
 * pipeline. Switch-executed swaps never call executeAtomicSwap() -
 * SwitchExecutionStrategy calls the switch adapter directly - so
 * without this, they'd leave zero trace in swap_requests, breaking
 * every admin report that queries it (Transaction Certificate,
 * Institution Settlement Summary, etc.).
 *
 * Reuses the same tracking-table population already used by the
 * DIRECT path, so both rails end up in the same reportable shape.
 */
public function recordExternalRailExecution(array $payload, array $railResult, string $railName): array
{
    $ref = $payload['reference'] ?? $this->generateReference();
    $this->currentSwapRef = $ref;
    $this->currentHoldId = null; // no local hold exists for switch-executed swaps
    $this->swapDB->prepare("UPDATE swap_requests SET settlement_status = 'NOT_APPLICABLE' WHERE swap_uuid = ?")
    ->execute([$ref]);

    $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'] ?? null;
    $destInstitution = $payload['to_institution'] ?? $payload['destination_institution'] ?? null;

    $swapData = [
        'swap_type' => $payload['swap_type'] ?? 'STANDARD',
        'reference' => $ref,
        'amount' => $payload['amount'] ?? 0,
        'currency' => $payload['currency'] ?? 'BWP',
        'status' => ($railResult['status'] ?? 'completed'),
        'from_institution' => $sourceInstitution,
        'to_institution' => $destInstitution,
        'user_id' => $payload['user_id'] ?? null,
    ];

    $details = array_merge($payload, [
        'source_institution' => $sourceInstitution,
        'destination_institution' => $destInstitution,
        'status' => $swapData['status'],
        'execution_rail' => $railName,
        'execution_rail_reference' => $railResult['reference'] ?? null,
    ]);

    $this->populateTrackingTables($swapData, $details, $railResult);

    return ['reference' => $ref, 'status' => $swapData['status']];
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
    if ($swapType !== 'IDENTITY' && $swapType !== 'CONFIRM_IDENTITY') {
        $originatorParty = $this->extractOriginatorPartyData($payload);
        $beneficiaryParty = $this->extractBeneficiaryPartyData($payload);
        $screening = $this->sanctionsScreening->screenSwapParties(
            $ref, $originatorParty['name'], $originatorParty['id_number'],
            $beneficiaryParty['name'], $beneficiaryParty['id_number']
        );
        if ($screening['blocked']) {
            $this->logger->critical('Swap blocked by sanctions screening', [
                'reference' => $ref,
                'originator_result' => $screening['originator']['result'] ?? null,
                'beneficiary_result' => $screening['beneficiary']['result'] ?? null,
            ]);
            throw new RuntimeException('This transaction cannot be processed. Please contact VouchMorph support.');
        }
    }
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
                'STANDARD' => $this->resolveStandardSwapDeliveryMethod($payload),
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
    
    // BANK DESTINATIONS LOOP
    foreach ($bankDestinations as $idx => $dest) {
        $destAmount = (float)$dest['amount'];
        $destInstitution = $dest['to_institution'] ?? $dest['destination_institution'] ?? $dest['institution'];
        $deliveryMethod = $dest['delivery_method'];
        $destIdentifier = $this->extractDestinationIdentifier($dest);
        $subRef = $dest['_sub_reference'];
        
        error_log("[SwapService] Processing destination " . ($idx + 1) . ": {$destInstitution} - {$destAmount} via {$deliveryMethod}");
        
        $destHoldRef = null;
        $destHoldId = null;
        $deliverySucceededForThisDest = false;  // NEW - reset per iteration
        
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
            
            // FIX: same card-acquirer signature exemption applied at
            // executeSignedDeposit()'s PLACE_HOLD_SIGNED check - see that
            // comment for the full explanation. This is the
            // multi-destination equivalent of the same check.
            $isCardAcquirer = isset($this->participants[$sourceInstitution]['card_acquirer']);

            $this->assertStepIntegrity(
                $holdResult,
                'hold_placed',
                $isCardAcquirer ? ['hold_reference'] : ['hold_reference', 'signature'],
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
            
            // NEW: Delivery succeeded - mark flag BEFORE debit attempt
            $deliverySucceededForThisDest = true;
            
            error_log("[SwapService] Debiting hold for destination " . ($idx + 1) . ": {$destHoldRef}");
            
            $this->currentHoldReference = $destHoldRef;
            $this->currentHoldId = $destHoldId;
            
            $debitPayload = [
                'reference' => $subRef,
                'hold_reference' => $destHoldRef,
                'amount' => $destAmount,
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

            // Persist swap_requests row
            $childSwapType = in_array($deliveryMethod, ['CASHOUT', 'AGENT', 'ATM'], true) ? 'CASHOUT' : 'DEPOSIT';

            $this->postLedgerLegs(
                $subRef,
                $sourceInstitution,
                $payload['asset_type'] ?? 'ACCOUNT',
                $sourceIdentifier['identifier'] ?? null,
                $destAmount,
                $destInstitution,
                $dest['destination_asset_type'] ?? 'ACCOUNT',
                $destIdentifier['identifier'] ?? null,
                $deliverableAmount,
                $feeAmount,
                $dest['currency'] ?? $currency,
                $destHoldRef
            );

            $this->populateTrackingTables(
                [
                    'swap_type' => $childSwapType,
                    'reference' => $subRef,
                    'amount' => $destAmount,
                    'currency' => $dest['currency'] ?? $currency,
                    'status' => 'completed',
                    'from_institution' => $sourceInstitution,
                    'to_institution' => $destInstitution,
                    'user_id' => $payload['user_id'] ?? null,
                ],
                array_merge($payload, $dest, [
                    'source_institution' => $sourceInstitution,
                    'destination_institution' => $destInstitution,
                    'destination_identifier' => $destIdentifier['identifier'] ?? null,
                    'destination_identifier_type' => $destIdentifier['type'] ?? null,
                    'fee_amount' => $feeAmount,
                    'status' => 'completed',
                ]),
                $destResult
            );
            
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
            
            // NEW: Check if delivery already succeeded before debit failed
            $deliveryAlreadySucceeded = $deliverySucceededForThisDest;
            
            if (isset($destHoldRef) && isset($destHoldId)) {
                if ($deliveryAlreadySucceeded) {
                    // NEW: Destination already has real money - do NOT release hold
                    // Flag for manual reconciliation
                    $this->logger->critical("HOLD NOT RELEASED - destination already delivered before debit failed", [
                        'sub_reference' => $subRef,
                        'hold_reference' => $destHoldRef,
                        'source_institution' => $sourceInstitution,
                        'destination_institution' => $destInstitution ?? 'unknown',
                        'amount' => $destAmount ?? 0,
                        'currency' => $dest['currency'] ?? $currency,
                        'error' => $e->getMessage()
                    ]);
                    
                    $this->recordManualReconciliationRequired(
                        $subRef ?? 'UNKNOWN',
                        $destHoldRef,
                        $sourceInstitution,
                        $destInstitution ?? null,
                        $destAmount ?? 0,
                        $dest['currency'] ?? $currency,
                        $e->getMessage()
                    );
                    
                    $this->updateHoldStatus($destHoldId, 'DEBIT_FAILED');
                    
                } else {
                    // ============================================================
                    // FIX: Normal rollback - release the hold with proper asset_type
                    // and source_identifier fields (matching SwapService::releaseHold())
                    // ============================================================
                    try {
                        error_log("[SwapService] Releasing hold for failed destination: {$destHoldRef}");
                        
                        // Get source asset type and identifier
                        $sourceAssetType = $payload['asset_type'] ?? 'ACCOUNT';
                        $sourceIdentifierValue = $this->extractSourceIdentifier($payload);
                        
                        $adapter = $this->adapterFactory->getAdapter($sourceInstitution);
                        $releaseResult = $adapter->releaseHold([
                            'hold_reference' => $destHoldRef,
                            'action' => 'RELEASE_HOLD',
                            'reason' => 'Destination failed - ' . $e->getMessage(),
                            // FIX: Added required fields that SwapService::releaseHold() already includes
                            'asset_type' => $sourceAssetType,
                            'source_identifier' => $sourceIdentifierValue['identifier'] ?? null,
                            'source_identifier_type' => $sourceIdentifierValue['type'] ?? null
                        ], []);
                        
                        error_log("[SwapService] Release result: " . json_encode($releaseResult));
                        
                        $this->updateHoldStatus($destHoldId, 'RELEASED');
                        
                    } catch (Exception $releaseError) {
                        error_log("[SwapService] Failed to release hold: " . $releaseError->getMessage());
                        // Log the failed release attempt for monitoring
                        $this->logger->error("HOLD RELEASE FAILED IN ROLLBACK", [
                            'hold_reference' => $destHoldRef,
                            'hold_id' => $destHoldId,
                            'source_institution' => $sourceInstitution,
                            'error' => $releaseError->getMessage()
                        ]);
                    }
                }
            }

            // Trace the failure
            if (isset($subRef)) {
                $this->populateTrackingTables(
                    [
                        'swap_type' => 'DEPOSIT',
                        'reference' => $subRef,
                        'amount' => $destAmount ?? 0,
                        'currency' => $dest['currency'] ?? $currency,
                        'status' => 'failed',
                        'from_institution' => $sourceInstitution,
                        'to_institution' => $destInstitution ?? null,
                        'user_id' => $payload['user_id'] ?? null,
                    ],
                    array_merge($payload, $dest ?? [], [
                        'source_institution' => $sourceInstitution,
                        'destination_institution' => $destInstitution ?? null,
                        'error_message' => $e->getMessage(),
                        'status' => 'failed',
                        'delivery_succeeded' => $deliveryAlreadySucceeded ? 'true' : 'false'
                    ]),
                    null
                );
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
                'type' => 'bank',
                'delivery_succeeded' => $deliveryAlreadySucceeded
            ];
            
            $destinationResults[] = $failedResult;
            $failedDestinations[] = $failedResult;
        }
    }
    
    // IDENTITY DESTINATIONS LOOP
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
        $deliverySucceededForThisDest = false;  // NEW - reset per iteration
        
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
            
            // FIX: same card-acquirer signature exemption applied at
            // executeSignedDeposit()'s PLACE_HOLD_SIGNED check - see that
            // comment for the full explanation. This is the identity-
            // destination equivalent of the same check.
            $isCardAcquirer = isset($this->participants[$sourceInstitution]['card_acquirer']);

            $this->assertStepIntegrity(
                $holdResult,
                'hold_placed',
                $isCardAcquirer ? ['hold_reference'] : ['hold_reference', 'signature'],
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
            
            // NEW: Delivery succeeded - mark flag BEFORE debit attempt
            $deliverySucceededForThisDest = true;
            
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

            // Post ledger legs for this identity child swap
            $this->postLedgerLegs(
                $subRef,
                $sourceInstitution,
                $payload['asset_type'] ?? 'ACCOUNT',
                $sourceIdentifier['identifier'] ?? null,
                $amount,
                null,
                null,
                null,
                0,
                $feeAmount,
                $identityDest['currency'] ?? $currency,
                $destHoldRef
            );

            $this->populateTrackingTables(
                [
                    'swap_type' => 'IDENTITY',
                    'reference' => $subRef,
                    'amount' => $amount,
                    'currency' => $identityDest['currency'] ?? $currency,
                    'status' => 'pending_identity_confirmation',
                    'from_institution' => $sourceInstitution,
                    'to_institution' => null,
                    'user_id' => $payload['user_id'] ?? null,
                ],
                array_merge($payload, $identityDest['original'] ?? [], [
                    'source_institution' => $sourceInstitution,
                    'identity_type' => $identityType,
                    'identity_value' => $identityValue,
                    'fee_amount' => $feeAmount,
                    'status' => 'pending_identity_confirmation',
                ]),
                $identityResult
            );
            
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
            
            // NEW: Check if delivery already succeeded before debit failed
            $deliveryAlreadySucceeded = $deliverySucceededForThisDest;
            
            if (isset($destHoldRef) && isset($destHoldId)) {
                if ($deliveryAlreadySucceeded) {
                    // NEW: Identity already initiated - do NOT release hold
                    // Flag for manual reconciliation
                    $this->logger->critical("HOLD NOT RELEASED - identity already initiated before debit failed", [
                        'sub_reference' => $subRef,
                        'hold_reference' => $destHoldRef,
                        'source_institution' => $sourceInstitution,
                        'identity_type' => $identityType ?? 'unknown',
                        'identity_value' => $identityValue ?? 'unknown',
                        'amount' => $amount ?? 0,
                        'currency' => $identityDest['currency'] ?? $currency,
                        'error' => $e->getMessage()
                    ]);
                    
                    $this->recordManualReconciliationRequired(
                        $subRef ?? 'UNKNOWN',
                        $destHoldRef,
                        $sourceInstitution,
                        null,  // No destination institution for identity
                        $amount ?? 0,
                        $identityDest['currency'] ?? $currency,
                        $e->getMessage()
                    );
                    
                    $this->updateHoldStatus($destHoldId, 'DEBIT_FAILED');
                    
                } else {
                    // ============================================================
                    // FIX: Normal rollback - release the hold with proper asset_type
                    // and source_identifier fields (matching SwapService::releaseHold())
                    // ============================================================
                    try {
                        error_log("[SwapService] Releasing hold for failed identity: {$destHoldRef}");
                        
                        // Get source asset type and identifier
                        $sourceAssetType = $payload['asset_type'] ?? 'ACCOUNT';
                        $sourceIdentifierValue = $this->extractSourceIdentifier($payload);
                        
                        $adapter = $this->adapterFactory->getAdapter($sourceInstitution);
                        $releaseResult = $adapter->releaseHold([
                            'hold_reference' => $destHoldRef,
                            'action' => 'RELEASE_HOLD',
                            'reason' => 'Identity destination failed - ' . $e->getMessage(),
                            // FIX: Added required fields that SwapService::releaseHold() already includes
                            'asset_type' => $sourceAssetType,
                            'source_identifier' => $sourceIdentifierValue['identifier'] ?? null,
                            'source_identifier_type' => $sourceIdentifierValue['type'] ?? null
                        ], []);
                        
                        error_log("[SwapService] Release result: " . json_encode($releaseResult));
                        
                        $this->updateHoldStatus($destHoldId, 'RELEASED');
                        
                    } catch (Exception $releaseError) {
                        error_log("[SwapService] Failed to release hold: " . $releaseError->getMessage());
                        // Log the failed release attempt for monitoring
                        $this->logger->error("HOLD RELEASE FAILED IN ROLLBACK", [
                            'hold_reference' => $destHoldRef,
                            'hold_id' => $destHoldId,
                            'source_institution' => $sourceInstitution,
                            'error' => $releaseError->getMessage()
                        ]);
                    }
                }
            }

            if (isset($subRef)) {
                $this->populateTrackingTables(
                    [
                        'swap_type' => 'IDENTITY',
                        'reference' => $subRef,
                        'amount' => $amount ?? 0,
                        'currency' => $identityDest['currency'] ?? $currency,
                        'status' => 'failed',
                        'from_institution' => $sourceInstitution,
                        'to_institution' => null,
                        'user_id' => $payload['user_id'] ?? null,
                    ],
                    array_merge($payload, $identityDest['original'] ?? [], [
                        'source_institution' => $sourceInstitution,
                        'identity_type' => $identityType ?? null,
                        'identity_value' => $identityValue ?? null,
                        'error_message' => $e->getMessage(),
                        'status' => 'failed',
                        'delivery_succeeded' => $deliveryAlreadySucceeded ? 'true' : 'false'
                    ]),
                    null
                );
            }
         
            $failedResult = [
                'index' => $idx,
                'type' => 'identity',
                'identity_type' => $identityType,
                'identity_value' => $identityValue,
                'amount' => $amount,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'can_retry' => true,
                'delivery_succeeded' => $deliveryAlreadySucceeded
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

    // ----------------------------------------------------------------------
// 3. storeMultiDestinationRecord() — runs after real debits at multiple
//    institutions have already succeeded
// ----------------------------------------------------------------------
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
        return $this->runInSavepoint('multi_dest_record_' . $reference, function () use ($sql, $reference, $sourceInstitution, $destinations, $results, $totalFees, $totalDelivered, $successCount, $failedCount) {
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
        });
    } catch (\Throwable $e) {
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
            
            // FIX: same card-acquirer signature exemption applied at
            // executeSignedDeposit()'s PLACE_HOLD_SIGNED check - see that
            // comment for the full explanation.
            $isCardAcquirer = isset($this->participants[$sourceInstitution]['card_acquirer']);

            $this->assertStepIntegrity(
                $holdResult,
                'hold_placed',
                ($isHooked || $isCardAcquirer) ? ['hold_reference'] : ['hold_reference', 'signature'],
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

    // 7. Audit log - use shared method
    $this->writeAuditLogEntry(
        'cashout_authorizations',
        (string)$authId,
        'CASHOUT_HOLD_RELEASED',
        'financial',
        null,
        'system',
        0,
        [
            'held_amount' => $heldAmount,
            'generate_code_fee_withheld' => $generateCodeFee,
            'levy_withheld' => $levy,
            'released_amount' => $releaseAmount,
            'swap_reference' => $swapRef,
            'hold_reference_used' => $holdReferenceForRelease,
        ]
    );

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
    
    // Capability gate — refuse to attempt deposit types the destination hasn't declared support for
    
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
        
        // FIX: this is the SAME "hold response must include a signature"
        // requirement already fixed once at GenericInstitutionAdapter::
        // placeHold() -- but SwapService keeps its own INDEPENDENT copy of
        // this check via assertStepIntegrity(), so fixing the adapter layer
        // alone wasn't enough. The $isHooked branch already exempts one
        // legitimate no-signature case (VouchMorph's own pre-provisioned
        // card flow); card acquirers like FNBB are a second legitimate
        // case for the same underlying reason -- real card acquiring never
        // signs individual authorization responses message-by-message
        // (TLS + the request-side signature already cover that trust
        // relationship; the authorization_code itself is the audit proof,
        // same as a receipt code). Confirmed live: FNBB's /Authorize.php
        // correctly returns hold_placed=true with a real
        // authorization_reference, and STILL got rejected here because
        // this check never learned about the adapter-layer exemption.
        // Detected the same way: presence of a card_acquirer block on the
        // institution's participant config.
        $isCardAcquirer = isset($this->participants[$sourceInstitution]['card_acquirer']);

        $this->assertStepIntegrity(
            $holdResult,
            'hold_placed',
            ($isHooked || $isCardAcquirer) ? ['hold_reference'] : ['hold_reference', 'signature'],
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
    // Deposit already succeeded above (processDepositWithProof) - the
    // destination has real money. Flag this so rollbackAtomicSwap()
    // does NOT release the source hold.
    $this->postDeliveryDebitFailure = true;
    $this->postDeliveryDestinationInstitution = $destinationInstitution;
    $this->postDeliveryAmount = $netAmount;
    $this->postDeliveryCurrency = $payload['currency'] ?? 'BWP';
    throw new RuntimeException("Debit failed after destination delivery succeeded: " . ($debitResult['message'] ?? 'Unknown error'));
}
    
    $this->updateHoldStatus($this->currentHoldId, 'DEBITED');

 // NEW: start settlement confirmation tracking — destination already
// attested delivery via processDepositWithProof() above; now confirm
// they were actually paid.
$this->recordSettlementPending(
    $this->currentSwapRef,
    $destinationInstitution,
    $debitResult['transaction_reference'] ?? $this->currentSwapRef,
    $netAmount,
    $payload['currency'] ?? 'BWP'
);

    
    // Post ledger legs
    $sourceIdentifier = $this->extractSourceIdentifier($payload);
    $this->postLedgerLegs(
        $this->currentSwapRef,
        $sourceInstitution,
        $payload['asset_type'] ?? 'ACCOUNT',
        $sourceIdentifier['identifier'] ?? null,
        $amount,
        $destinationInstitution,
        $destinationAssetType,
        $destinationIdentifier['identifier'] ?? null,
        $netAmount,
        $feeBreakdown['total_fee'] ?? 0,
        $payload['currency'] ?? 'BWP',
        $this->currentHoldReference
    );
    
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
    
    // Persist the actual transaction reference from the deposit result
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
        $depositResult  // Now passing the actual deposit result instead of null
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
            $this->currentHoldId,
            $skipHold
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
 * ============================================================================
 * NEW METHODS TO ADD TO SwapService.php
 * ============================================================================
 * Add these near the existing identity-swap helper section (alongside
 * storeIdentityHold(), createEarmarkedBalance(), etc.). They replace
 * createEarmarkedBalance()/getOpenEarmarkedSummary()/
 * validateEarmarkedWithdrawal()/consumeEarmarkedBalance() for any NEW claim
 * going through the aggregated flow — those four methods can stay in place
 * untouched for now to keep any already-open earmarked balances resolvable
 * under the old logic, but nothing new should call createEarmarkedBalance()
 * after this lands.
 * ============================================================================
 */

// ============================================================================
// PER-INSTITUTION RECEIVING/HOLDING ACCOUNT CONFIG
// ============================================================================

/**
 * Reads the receiving/holding account pair configured for an institution +
 * currency (see participants.yaml `identity_accounts` block). Throws if the
 * institution hasn't been onboarded for identity-swap consolidation, so a
 * claim fails loudly and immediately rather than attempting to deposit into
 * an account that doesn't exist.
 */
private function getIdentityHoldingAccounts(string $institution, string $currency): array
{
    $participant = $this->participants[$institution] ?? $this->participants[strtoupper($institution)] ?? null;

    if ($participant === null) {
        throw new RuntimeException("Unknown institution: {$institution}");
    }

    $supportsHolding = $participant['capabilities']['identity_holding'] ?? false;
    if (!$supportsHolding) {
        throw new RuntimeException(
            "{$institution} has not been onboarded to support identity-swap consolidation " .
            "(no receiving/holding accounts configured). Contact VouchMorph ops to complete " .
            "onboarding before claims can settle at this institution."
        );
    }

    $accounts = $participant['identity_accounts'][$currency] ?? null;
    if ($accounts === null || empty($accounts['receiving_identifier']) || empty($accounts['holding_identifier'])) {
        throw new RuntimeException(
            "{$institution} supports identity holding but has no receiving/holding accounts " .
            "configured for currency {$currency}. Add an identity_accounts.{$currency} block " .
            "to participants.yaml for this institution."
        );
    }

    return [
        'receiving_identifier' => $accounts['receiving_identifier'],
        'receiving_identifier_type' => $accounts['receiving_identifier_type'] ?? 'account_number',
        'holding_identifier' => $accounts['holding_identifier'],
        'holding_identifier_type' => $accounts['holding_identifier_type'] ?? 'account_number',
    ];
}

// ============================================================================
// CONFIG: source-side settlement account
// ============================================================================

private function getSourceSettlementAccount(string $institution, string $currency): array
{
    $participant = $this->participants[$institution] ?? $this->participants[strtoupper($institution)] ?? null;
    if ($participant === null) {
        throw new RuntimeException("Unknown institution: {$institution}");
    }

    $account = $participant['settlement_account'][$currency] ?? null;
    if ($account === null || empty($account['identifier'])) {
        throw new RuntimeException(
            "{$institution} has no settlement_account configured for currency {$currency}. " .
            "Add a settlement_account.{$currency} block to participants.yaml — confirm the real " .
            "account number with the bank before enabling this."
        );
    }

    return [
        'identifier' => $account['identifier'],
        'identifier_type' => $account['identifier_type'] ?? 'account_number',
    ];
}

// ============================================================================
// SWITCH ELIGIBILITY — per-leg (this source + this destination), fixed to
// match the REAL participants.yaml schema (switch_participant_ids, a map of
// switch-name => this institution's own participant ID within that switch —
// NOT $participant['settlement']['switches'], which doesn't exist in this
// schema at all).
// ============================================================================

private function getCommonSwitch(array $institutions): ?string
{
    $switchSets = [];
    foreach (array_unique($institutions) as $inst) {
        $participant = $this->participants[$inst] ?? null;
        $switchIds = $participant['switch_participant_ids'] ?? [];
        if (empty($switchIds)) {
            return null; // this institution isn't reachable via any switch at all
        }
        $switchSets[] = array_keys($switchIds);
    }

    if (empty($switchSets)) {
        return null;
    }

    $common = array_shift($switchSets);
    foreach ($switchSets as $set) {
        $common = array_intersect($common, $set);
        if (empty($common)) {
            return null;
        }
    }

    return $common[0] ?? null;
}

// ============================================================================
// STEP A: CLOSE THE HOLD — always direct, never routed through a switch.
// The switch decision only ever applies to what happens to the proceeds
// AFTER the hold is closed — never to the hold itself.
// ============================================================================

private function debitHoldToSourceSettlement(array $identitySwap): void
{
    $sourceInstitution = $identitySwap['source_institution'];

    $debitPayload = [
        'reference' => $identitySwap['swap_reference'] . '_SETTLE',
        'hold_reference' => $identitySwap['hold_reference'],
        'amount' => (float)$identitySwap['amount'],
        'reason' => 'Identity claim finalization',
        'from_institution' => $sourceInstitution,
        'source_institution' => $sourceInstitution,
    ];

    $result = $this->debitSource($debitPayload, $sourceInstitution);

    if (!($result['debited'] ?? false)) {
        throw new RuntimeException("Failed to debit hold for {$identitySwap['hold_id']}: " . ($result['message'] ?? 'Unknown error'));
    }

    $this->updateHoldStatus((int)$identitySwap['hold_id'], 'DEBITED');
}

// ============================================================================
// STEP B: MOVE PROCEEDS ONWARD — switch-aware, decided per source+
// destination pair.
// ============================================================================

private function settleToDestinationReceiving(
    string $sourceInstitution,
    string $destinationInstitution,
    string $currency,
    float $amount,
    string $reference
): float {
    $switchCode = $this->getCommonSwitch([$sourceInstitution, $destinationInstitution]);

    if ($switchCode !== null) {
        return $this->settleViaSwitch($switchCode, $sourceInstitution, $destinationInstitution, $currency, $amount, $reference);
    }

    return $this->settleDirect($sourceInstitution, $destinationInstitution, $currency, $amount, $reference);
}

private function settleViaSwitch(
    string $switchCode,
    string $sourceInstitution,
    string $destinationInstitution,
    string $currency,
    float $amount,
    string $reference
): float {
    $sourceSettlement = $this->getSourceSettlementAccount($sourceInstitution, $currency);
    $destAccounts = $this->getIdentityHoldingAccounts($destinationInstitution, $currency);

    $this->logger->info("Settling identity claim leg via switch", [
        'switch' => $switchCode, 'source' => $sourceInstitution, 'destination' => $destinationInstitution,
        'amount' => $amount, 'reference' => $reference,
    ]);

    $switchAdapter = $this->adapterFactory->getAdapter($switchCode);

    $originSwitchId = $this->participants[$sourceInstitution]['switch_participant_ids'][$switchCode] ?? null;
    $destSwitchId = $this->participants[$destinationInstitution]['switch_participant_ids'][$switchCode] ?? null;
    $vouchmorphSwitchId = $this->participants['VOUCHMORPH']['switch_participant_ids'][$switchCode] ?? null;

    if (!$originSwitchId || !$destSwitchId) {
        throw new RuntimeException("Switch participant IDs missing for {$sourceInstitution}/{$destinationInstitution} on rail {$switchCode} despite getCommonSwitch() reporting a match.");
    }

    try {
        $result = $switchAdapter->submitTransfer([
            'method' => 'PUSH',
            'requester_participant_id' => $vouchmorphSwitchId,
            'origin_participant_id' => $originSwitchId,
            'destination_participant_id' => $destSwitchId,
            // Pulling from the SETTLEMENT account, never the customer's
            // account — the hold was already closed by
            // debitHoldToSourceSettlement() before this method runs.
            'origin_account_number' => $sourceSettlement['identifier'],
            'destination_account_number' => $destAccounts['receiving_identifier'],
            'amount' => $amount,
            'currency' => $currency,
            'idempotency_key' => $reference,
        ]);
    } catch (\Domain\Services\Routing\Exceptions\SwitchUnavailableException $e) {
        // Genuine network/timeout failure — fall back to direct. A real
        // rejection (bad participant, insufficient funds, AML hold) is
        // NOT this exception type and propagates as a hard failure.
        $this->logger->warning("Switch unavailable for identity settlement, falling back to direct", [
            'reference' => $reference, 'error' => $e->getMessage(),
        ]);
        return $this->settleDirect($sourceInstitution, $destinationInstitution, $currency, $amount, $reference);
    }

    if (!($result['status'] ?? null)) {
        throw new RuntimeException("Switch settlement failed for {$reference}");
    }

    return $amount; // switch settlement is atomic — full amount or throws
}

private function settleDirect(
    string $sourceInstitution,
    string $destinationInstitution,
    string $currency,
    float $amount,
    string $reference
): float {
    $sourceSettlement = $this->getSourceSettlementAccount($sourceInstitution, $currency);
    $destAccounts = $this->getIdentityHoldingAccounts($destinationInstitution, $currency);

    $payload = [
        'reference' => $reference,
        'amount' => $amount,
        'currency' => $currency,
        'destination_identifier' => $destAccounts['receiving_identifier'],
        'destination_identifier_type' => $destAccounts['receiving_identifier_type'],
        'destination_asset_type' => 'ACCOUNT',
        'to_institution' => $destinationInstitution,
        'destination_institution' => $destinationInstitution,
        'from_institution' => $sourceInstitution,
        'source_institution' => $sourceInstitution,
        'source_identifier' => $sourceSettlement['identifier'],
        'source_type' => 'INSTITUTION_SETTLEMENT_ACCOUNT',
        'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
        'account_number' => $destAccounts['receiving_identifier'],
        'destination_account' => $destAccounts['receiving_identifier'],
    ];

    $adapter = $this->adapterFactory->getAdapter($destinationInstitution);
    $result = $adapter->credit($payload, [
        'destination_institution' => $destinationInstitution,
        'source_type' => 'INSTITUTION_SETTLEMENT_ACCOUNT',
    ]);

    if (!($result['credited'] ?? false)) {
        throw new RuntimeException("Direct settlement to receiving account failed: " . ($result['message'] ?? 'Unknown error'));
    }

    return $amount;
}

/**
 * POS counterpart to settleToDestinationReceiving() — credits a named
 * MERCHANT account at the destination institution, not that
 * institution's general receiving pool. Same switch-vs-direct
 * decision, same source-side settlement-account debit; only the
 * destination credit target differs.
 */
private function settlePosToMerchant(
    string $sourceInstitution,
    string $destinationInstitution,
    string $merchantAccountIdentifier,
    string $merchantAccountIdentifierType,
    string $currency,
    float $amount,
    string $reference
): float {
    $switchCode = $this->getCommonSwitch([$sourceInstitution, $destinationInstitution]);

    if ($switchCode !== null) {
        return $this->settlePosViaSwitch(
            $switchCode, $sourceInstitution, $destinationInstitution,
            $merchantAccountIdentifier, $currency, $amount, $reference
        );
    }

    return $this->settlePosDirect(
        $sourceInstitution, $destinationInstitution,
        $merchantAccountIdentifier, $merchantAccountIdentifierType, $currency, $amount, $reference
    );
}

private function settlePosViaSwitch(
    string $switchCode,
    string $sourceInstitution,
    string $destinationInstitution,
    string $merchantAccountIdentifier,
    string $currency,
    float $amount,
    string $reference
): float {
    $sourceSettlement = $this->getSourceSettlementAccount($sourceInstitution, $currency);

    $this->logger->info("Settling POS swipe to merchant via switch", [
        'switch' => $switchCode, 'source' => $sourceInstitution, 'destination' => $destinationInstitution,
        'merchant_account' => $merchantAccountIdentifier, 'amount' => $amount, 'reference' => $reference,
    ]);

    $switchAdapter = $this->adapterFactory->getAdapter($switchCode);

    $originSwitchId = $this->participants[$sourceInstitution]['switch_participant_ids'][$switchCode] ?? null;
    $destSwitchId = $this->participants[$destinationInstitution]['switch_participant_ids'][$switchCode] ?? null;
    $vouchmorphSwitchId = $this->participants['VOUCHMORPH']['switch_participant_ids'][$switchCode] ?? null;

    if (!$originSwitchId || !$destSwitchId) {
        throw new RuntimeException("Switch participant IDs missing for {$sourceInstitution}/{$destinationInstitution} on rail {$switchCode} despite getCommonSwitch() reporting a match.");
    }

    try {
        $result = $switchAdapter->submitTransfer([
            'method' => 'PUSH',
            'requester_participant_id' => $vouchmorphSwitchId,
            'origin_participant_id' => $originSwitchId,
            'destination_participant_id' => $destSwitchId,
            'origin_account_number' => $sourceSettlement['identifier'],
            // Directly to the MERCHANT's account, not a receiving pool.
            'destination_account_number' => $merchantAccountIdentifier,
            'amount' => $amount,
            'currency' => $currency,
            'idempotency_key' => $reference,
        ]);
    } catch (\Domain\Services\Routing\Exceptions\SwitchUnavailableException $e) {
        $this->logger->warning("Switch unavailable for POS merchant settlement, falling back to direct", [
            'reference' => $reference, 'error' => $e->getMessage(),
        ]);
        return $this->settlePosDirect(
            $sourceInstitution, $destinationInstitution, $merchantAccountIdentifier,
            'account_number', $currency, $amount, $reference
        );
    }

    if (!($result['status'] ?? null)) {
        throw new RuntimeException("Switch settlement to merchant failed for {$reference}");
    }

    return $amount;
}

private function settlePosDirect(
    string $sourceInstitution,
    string $destinationInstitution,
    string $merchantAccountIdentifier,
    string $merchantAccountIdentifierType,
    string $currency,
    float $amount,
    string $reference
): float {
    $sourceSettlement = $this->getSourceSettlementAccount($sourceInstitution, $currency);

    $payload = [
        'reference' => $reference,
        'amount' => $amount,
        'currency' => $currency,
        // Directly to the MERCHANT's own account — the key difference
        // from settleDirect()'s institution-pool version.
        'destination_identifier' => $merchantAccountIdentifier,
        'destination_identifier_type' => $merchantAccountIdentifierType,
        'destination_asset_type' => 'ACCOUNT',
        'to_institution' => $destinationInstitution,
        'destination_institution' => $destinationInstitution,
        'from_institution' => $sourceInstitution,
        'source_institution' => $sourceInstitution,
        'source_identifier' => $sourceSettlement['identifier'],
        'source_type' => 'INSTITUTION_SETTLEMENT_ACCOUNT',
        'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
        'account_number' => $merchantAccountIdentifier,
        'destination_account' => $merchantAccountIdentifier,
    ];

    $adapter = $this->adapterFactory->getAdapter($destinationInstitution);
    $result = $adapter->credit($payload, [
        'destination_institution' => $destinationInstitution,
        'destination_identifier' => $merchantAccountIdentifier,
        'source_type' => 'INSTITUTION_SETTLEMENT_ACCOUNT',
    ]);

    if (!($result['credited'] ?? false)) {
        throw new RuntimeException("Direct settlement to merchant account failed: " . ($result['message'] ?? 'Unknown error'));
    }

    return $amount;
}

/**
 * Public passthrough — see SwapService::settleCardSwipeToDestination()
 * for the ATM/institution-level counterpart. Use this one for POS;
 * that one for ATM cash.
 */
public function settleCardSwipeToMerchant(
    string $sourceInstitution,
    string $destinationInstitution,
    string $merchantAccountIdentifier,
    string $merchantAccountIdentifierType,
    string $currency,
    float $amount,
    string $reference
): float {
    return $this->settlePosToMerchant(
        $sourceInstitution, $destinationInstitution,
        $merchantAccountIdentifier, $merchantAccountIdentifierType,
        $currency, $amount, $reference
    );
}

 /**
 * PLACEHOLDER — no real merchant-onboarding table exists yet in this
 * codebase. Assumes the ISO 8583 merchant ID (field 42) IS the real
 * account identifier directly, which will not be true for real
 * traffic. Replace once merchant onboarding/KYC data exists.
 */
public function resolveMerchantAccountByMerchantId(string $merchantId): ?array
{
    // Real implementation needs a merchant_accounts (or similar) table:
    //   SELECT account_identifier, account_identifier_type
    //   FROM merchant_accounts WHERE merchant_id = ? AND status = 'active'
    return [
        'identifier' => $merchantId, // WRONG for real traffic — placeholder only
        'identifier_type' => 'account_number',
    ];
}

 
// ============================================================================
// STEP A+B TOGETHER, per hold — this is what executeIdentityClaimWithSplit()
// calls in its consolidation loop.
// ============================================================================

private function finalizeHoldToReceiving(
    array $identitySwap,
    string $destinationInstitution,
    string $consolidationReference
): array {
    $sourceInstitution = $identitySwap['source_institution'];
    $currency = $identitySwap['currency'] ?? 'BWP';
    $amount = (float)$identitySwap['amount'];

    $sourcePayload = json_decode($identitySwap['source_payload'], true);
    $sourcePayload['from_institution'] = $sourceInstitution;
    $sourcePayload['source_institution'] = $sourceInstitution;
    $sourcePayload['amount'] = $amount;
    $sourcePayload['currency'] = $currency;
    $sourcePayload['asset_type'] = $identitySwap['source_asset_type'] ?? 'ACCOUNT';

    $verificationResult = $this->verifyAssetSigned($sourcePayload, $sourceInstitution);
    if (!($verificationResult['verified'] ?? false)) {
        throw new RuntimeException("Source funds no longer available for hold {$identitySwap['hold_id']}. Claim cancelled for this hold.");
    }

    $this->currentHoldReference = $identitySwap['hold_reference'];
    $this->currentHoldId = (int)$identitySwap['hold_id'];

    $recvId = $this->recordReceivingDepositAttempt(
        $consolidationReference, (int)$identitySwap['hold_id'], $destinationInstitution,
        $currency, 'pending', $amount
    );

    try {
        $this->debitHoldToSourceSettlement($identitySwap);

        $reference = $identitySwap['swap_reference'] . '_SETTLE';
        $netLanded = $this->settleToDestinationReceiving(
            $sourceInstitution, $destinationInstitution, $currency, $amount, $reference
        );

        $this->updateReceivingDepositStatus($recvId, 'landed', $reference);
        $this->updateIdentityHoldStatus((int)$identitySwap['hold_id'], 'completed', [
            'final_destination_type' => 'HOLDING_CONSOLIDATED',
            'consolidation_reference' => $consolidationReference,
        ]);

        return ['success' => true, 'net_amount' => $netLanded, 'hold_id' => $identitySwap['hold_id']];

    } catch (\Throwable $e) {
        $this->updateReceivingDepositStatus($recvId, 'failed', null);
        if ($this->currentHoldId && strpos($e->getMessage(), 'Failed to debit') === false) {
            // Hold was debited but settlement onward failed — money is
            // gone from the source but hasn't landed anywhere confirmed.
            // Flag for manual reconciliation rather than losing track of it.
            $this->recordManualReconciliationRequired(
                $identitySwap['swap_reference'], $identitySwap['hold_reference'], $sourceInstitution,
                $destinationInstitution, $amount, $currency,
                "Hold debited but settlement to receiving account failed: " . $e->getMessage()
            );
        }
        throw $e;
    }
}

private function recordReceivingDepositAttempt(
    string $consolidationReference,
    int $holdId,
    string $institution,
    string $currency,
    string $receivingIdentifier,
    float $amount
): int {
    $stmt = $this->swapDB->prepare("
        INSERT INTO identity_receiving_deposits (
            consolidation_reference, hold_id, institution, currency,
            receiving_identifier, amount, status
        ) VALUES (:ref, :hold_id, :inst, :ccy, :recv, :amt, 'pending')
        RETURNING id
    ");
    $stmt->execute([
        ':ref' => $consolidationReference, ':hold_id' => $holdId, ':inst' => $institution,
        ':ccy' => $currency, ':recv' => $receivingIdentifier, ':amt' => $amount,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}

private function updateReceivingDepositStatus(int $id, string $status, ?string $txRef): void
{
    try {
        $stmt = $this->swapDB->prepare("
            UPDATE identity_receiving_deposits
            SET status = :status, transaction_reference = :tx_ref,
                confirmed_at = CASE WHEN :status2 = 'landed' THEN NOW() ELSE confirmed_at END
            WHERE id = :id
        ");
        $stmt->execute([':status' => $status, ':status2' => $status, ':tx_ref' => $txRef, ':id' => $id]);
    } catch (\Throwable $e) {
        error_log("[SwapService] Failed to update identity_receiving_deposits id={$id}: " . $e->getMessage());
    }
}

// ============================================================================
// STEP 2: SWEEP RECEIVING -> HOLDING
// ============================================================================

/**
 * Confirms every expected receiving-account deposit for this consolidation
 * landed, then instructs the bank to sweep the confirmed total from their
 * receiving account into their holding account.
 *
 * ⚠️ NEW ADAPTER ACTION REQUIRED: 'INTERNAL_SWEEP'. This is deliberately
 * NOT modeled as a debit()+credit() pair using the existing customer-hold
 * primitives — those assume a hold_reference tied to a customer's own
 * money. A sweep between two of the bank's OWN internal accounts is a
 * different operation and needs its own adapter method. Confirm with each
 * bank whether they can support this before enabling consolidation for
 * that institution (see getIdentityHoldingAccounts()'s capability gate).
 */
private function sweepReceivingToHolding(
    string $institution,
    string $currency,
    string $consolidationReference
): float {
    $accounts = $this->getIdentityHoldingAccounts($institution, $currency);

    $stmt = $this->swapDB->prepare("
        SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'landed' THEN amount ELSE 0 END) AS landed_amount,
               SUM(CASE WHEN status = 'landed' THEN 1 ELSE 0 END) AS landed_count
        FROM identity_receiving_deposits WHERE consolidation_reference = :ref
    ");
    $stmt->execute([':ref' => $consolidationReference]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    $expected = (int)$summary['total'];
    $landed = (int)$summary['landed_count'];
    $landedAmount = (float)($summary['landed_amount'] ?? 0);

    if ($landed === 0) {
        throw new RuntimeException("No confirmed deposits landed for consolidation {$consolidationReference} — nothing to sweep.");
    }
    if ($landed < $expected) {
        $this->logger->warning("Sweeping partial consolidation — not all legs landed", [
            'consolidation_reference' => $consolidationReference,
            'expected' => $expected, 'landed' => $landed,
        ]);
        // Proceeding with what landed rather than blocking indefinitely.
        // The failed leg(s) remain visible in identity_receiving_deposits
        // for manual follow-up / retry.
    }

    $adapter = $this->adapterFactory->getAdapter($institution);

    $sweepPayload = [
        'action' => 'INTERNAL_SWEEP',
        'reference' => $consolidationReference . '_SWEEP',
        'from_identifier' => $accounts['receiving_identifier'],
        'to_identifier' => $accounts['holding_identifier'],
        'amount' => $landedAmount,
        'currency' => $currency,
        'reason' => 'Identity claim consolidation',
    ];

    if (!method_exists($adapter, 'internalSweep')) {
        throw new RuntimeException(
            "{$institution}'s adapter does not implement internalSweep() — this institution's " .
            "adapter needs to be extended before identity-swap consolidation can complete there."
        );
    }

    $result = $adapter->internalSweep($sweepPayload, [
        'consolidation_reference' => $consolidationReference,
        'institution' => $institution,
    ]);

    if (!($result['success'] ?? false)) {
        throw new RuntimeException("Sweep to holding account failed: " . ($result['message'] ?? 'Unknown error'));
    }

    return $landedAmount;
}

// ============================================================================
// STEP 3a: PAY OUT FROM HOLDING TO THE REAL DESTINATION (merchant deposit)
// ============================================================================

private function payHoldingToMerchant(
    string $institution,
    string $currency,
    float $amount,
    string $destIdentifier,
    string $destIdentifierType,
    string $destAssetType,
    string $reference
): array {
    $accounts = $this->getIdentityHoldingAccounts($institution, $currency);

    $payload = [
        'reference' => $reference,
        'amount' => $amount,
        'currency' => $currency,
        'destination_identifier' => $destIdentifier,
        'destination_identifier_type' => $destIdentifierType,
        'destination_asset_type' => $destAssetType,
        'to_institution' => $institution,
        'destination_institution' => $institution,
        // Source is the bank's OWN holding account, not a VouchMorph
        // pool and not a customer — mirrors the VM_POOL pattern used
        // elsewhere in creditDestination() for internally-sourced credits.
        'from_institution' => $institution,
        'source_institution' => $institution,
        'source_identifier' => $accounts['holding_identifier'],
        'source_type' => 'IDENTITY_HOLDING_ACCOUNT',
        'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
    ];

    if ($destAssetType === 'ACCOUNT') {
        $payload['account_number'] = $destIdentifier;
        $payload['destination_account'] = $destIdentifier;
    } else {
        $payload['phone'] = $destIdentifier;
        $payload['wallet_phone'] = $destIdentifier;
    }

    $adapter = $this->adapterFactory->getAdapter($institution);
    $result = $adapter->credit($payload, [
        'destination_institution' => $institution,
        'destination_identifier' => $destIdentifier,
        'source_type' => 'IDENTITY_HOLDING_ACCOUNT',
    ]);

    if (!($result['credited'] ?? false)) {
        throw new RuntimeException("Payout from holding to destination failed: " . ($result['message'] ?? 'Unknown error'));
    }

    return [
        'success' => true,
        'transaction_reference' => $result['transaction_reference'] ?? null,
    ];
}

// ============================================================================
// STEP 3b: PAY OUT FROM HOLDING AS A CASHOUT CODE (ATM/agent)
// ============================================================================

private function generateCashoutFromHolding(
    string $institution,
    string $currency,
    float $amount,
    string $deliveryMethod,
    ?string $beneficiaryPhone,
    string $reference
): array {
    $accounts = $this->getIdentityHoldingAccounts($institution, $currency);

    $payload = [
        'reference' => $reference,
        'amount' => $amount,
        'currency' => $currency,
        'delivery_method' => $deliveryMethod,
        'beneficiary_phone' => $beneficiaryPhone,
        'from_institution' => $institution,
        'source_institution' => $institution,
        'source_identifier' => $accounts['holding_identifier'],
        'source_type' => 'IDENTITY_HOLDING_ACCOUNT',
        'to_institution' => $institution,
        'destination_institution' => $institution,
        'action' => 'GENERATE_TOKEN',
    ];

    $adapter = $this->adapterFactory->getAdapter($institution);
    $result = $adapter->generateCashoutToken($payload, [
        'source_institution' => $institution,
        'destination_institution' => $institution,
        'source_type' => 'IDENTITY_HOLDING_ACCOUNT',
    ]);

    if (!($result['success'] ?? false)) {
        throw new RuntimeException("Cashout generation from holding failed: " . ($result['message'] ?? 'Unknown error'));
    }

    if ($beneficiaryPhone && isset($result['atm_pin']) && $this->smsService) {
        try {
            $this->smsService->sendCashoutCode($beneficiaryPhone, $result['atm_pin'], $amount, $result['voucher_number'] ?? null);
        } catch (Exception $e) {
            error_log("[SwapService] SMS failed but continuing: " . $e->getMessage());
        }
    }

    return [
        'success' => true,
        'transaction_reference' => $result['transaction_reference'] ?? null,
        'swap_code' => $result['voucher_number'] ?? $result['swap_code'] ?? null,
        'atm_code' => $result['atm_pin'] ?? null,
        'expires_at' => $result['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours')),
    ];
}

// ============================================================================
// STEP 4: PLACE A REAL HOLD ON WHATEVER'S LEFT IN THE HOLDING ACCOUNT
// ============================================================================

/**
 * Replaces createEarmarkedBalance(). Places an actual bank-side hold on the
 * remaining balance in the institution's HOLDING account, tied to the
 * identity, and records it in identity_holding_positions. No dust-threshold
 * math needed — the remainder isn't sitting in a spendable account anymore.
 */
private function placeHoldOnHoldingRemainder(
    string $institution,
    string $currency,
    float $amount,
    string $identityType,
    string $identityValue,
    array $sourceHoldIds,
    string $consolidationReference
): int {
    $accounts = $this->getIdentityHoldingAccounts($institution, $currency);

    $holdPayload = [
        'action' => 'PLACE_HOLD',
        'reference' => $consolidationReference . '_REMAIN_HOLD',
        'asset_type' => 'ACCOUNT',
        'amount' => $amount,
        'currency' => $currency,
        'source_identifier' => $accounts['holding_identifier'],
        'source_identifier_type' => $accounts['holding_identifier_type'],
        'hold_reason' => 'IDENTITY_HOLDING_REMAINDER',
        // Deliberately no expiry cap here — see file header note on
        // indefinite/bank-agreed holds. If a bank requires a concrete
        // expiry on their end for their own dormancy handling, confirm
        // that value with them and set it explicitly rather than reusing
        // the 24h customer-hold default.
        'timestamp' => time(),
        'from_institution' => $institution,
        'source_institution' => $institution,
    ];

    $adapter = $this->adapterFactory->getAdapter($institution);
    $result = $adapter->placeHold($holdPayload, [
        'institution' => $institution,
        'source_type' => 'IDENTITY_HOLDING_ACCOUNT',
    ]);

    if (!($result['hold_placed'] ?? false)) {
        throw new RuntimeException("Failed to place hold on holding account remainder: " . ($result['message'] ?? 'Unknown error'));
    }

    $stmt = $this->swapDB->prepare("
        INSERT INTO identity_holding_positions (
            identity_type, identity_value, institution, currency,
            holding_identifier, hold_reference, amount, source_hold_ids,
            consolidation_reference, status
        ) VALUES (
            :type, :value, :inst, :ccy, :holding_id, :hold_ref, :amount,
            :source_holds::jsonb, :consol_ref, 'open'
        ) RETURNING id
    ");
    $stmt->execute([
        ':type' => $identityType, ':value' => $identityValue, ':inst' => $institution,
        ':ccy' => $currency, ':holding_id' => $accounts['holding_identifier'],
        ':hold_ref' => $result['hold_reference'] ?? null, ':amount' => $amount,
        ':source_holds' => json_encode($sourceHoldIds), ':consol_ref' => $consolidationReference,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $positionId = $row ? (int)$row['id'] : 0;

    $this->logger->info("Identity holding position created", [
        'position_id' => $positionId, 'identity_type' => $identityType, 'identity_value' => $identityValue,
        'institution' => $institution, 'amount' => $amount,
    ]);

    return $positionId;
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
    // ----------------------------------------------------------------------
// 4. consumeEarmarkedBalance() — THE MOST DANGEROUS ONE.
//    Called from confirmCashout() immediately after a real bank debit
//    has already succeeded. Each ledger-entry update AND its audit
//    insert now share one savepoint per entry, so a failure on one
//    entry can't poison the rest of the loop or the outer commit.
// ----------------------------------------------------------------------
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
            $this->runInSavepoint('consume_earmarked_' . $entryId . '_' . ($swapReference ?? uniqid()), function () use ($entryId, $consumeFromThisEntry, $newEntryRemaining, $newStatus, $swapReference) {
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
            });
 
            error_log("[SwapService] Earmarked balance {$entryId} consumed {$consumeFromThisEntry}, remaining {$newEntryRemaining}, status {$newStatus}");
 
        } catch (\Throwable $e) {
            error_log("[SwapService] Failed to consume earmarked balance {$entryId}: " . $e->getMessage());
            // Safe to continue the loop now: the savepoint rollback already
            // undid only THIS entry's update+audit insert. The outer
            // transaction (cashout completion, hold status, audit log,
            // eventual commit) is unaffected.
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

    // ============================================================
    // FIX: this method updates cashout_authorizations / hold_transactions /
    // swap_requests via runInSavepoint(), which requires an OPEN PDO
    // transaction to issue SAVEPOINT. When called directly (e.g. from
    // the ATM callback webhook) instead of through executeAtomicSwap(),
    // no transaction was open, every SAVEPOINT call threw
    // SQLSTATE[25P01], and the bank debit succeeded while every local
    // status update silently failed - leaving completed cashouts stuck
    // showing PENDING forever. Open a transaction here if one isn't
    // already active, mirroring the $openedHere pattern used elsewhere
    // in this class (e.g. initiateSwapToIdentity()).
    // ============================================================
    $openedHere = !$this->swapDB->inTransaction();
    if ($openedHere) {
        $this->swapDB->beginTransaction();
    }

    try {
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
            if ($openedHere) {
                $this->swapDB->commit();
            }
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
            // Commit the DEBIT_FAILED status write before throwing - we
            // want that record to persist even though this call fails,
            // rather than having the catch block below roll it back.
            if ($openedHere) {
                $this->swapDB->commit();
            }
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
        $this->updateCashoutAuthorizationStatus($authId, 'COMPLETED', $cashoutPoint);
$this->updateHoldForSwap($swapRef, 'DEBITED');
$this->updateSwapRequestStatus($swapRef, 'completed');

// NEW: destination already attested cash dispensed (confirmCashout
// succeeded above); now confirm the source-side debit actually paid them.
$this->recordSettlementPending(
    $swapRef,
    $destinationInstitution,
    $debitResult['transaction_reference'] ?? $swapRef,
    $amountToSend,
    $currency
);
        $this->updateHoldForSwap($swapRef, 'DEBITED');
        $this->updateSwapRequestStatus($swapRef, 'completed');

        // Post ledger legs for cashout
        $this->postLedgerLegs(
            $swapRef,
            $sourceInstitution,
            'ACCOUNT',
            $authorization['source_identifier'] ?? null,
            $amountToSend + $feeAmount,
            $destinationInstitution,
            'CASHOUT',
            $voucherNumber ?? $authorization['swap_code'],
            $amountToSend,
            $feeAmount,
            $currency,
            $holdReference
        );

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

        // Audit log - use shared method
        $this->writeAuditLogEntry(
            'cashout_authorizations',
            (string)$authId,
            'CASHOUT_CONFIRMED',
            'financial',
            $userId,
            $isCallback ? 'system' : 'user',
            $isCallback ? 0 : ($userId ?? 0),
            [
                'mode' => $mode,
                'voucher_number' => $voucherNumber ?? $authorization['swap_code'],
                'amount' => $amountToSend,
                'atm_id' => $atmId,
                'cashout_reference' => $cashoutReference,
                'swap_reference' => $swapRef,
                'source_institution' => $sourceInstitution,
                'destination_institution' => $destinationInstitution,
                'user_id' => $userId
            ]
        );

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

        if ($openedHere) {
            $this->swapDB->commit();
        }

        return $response;

    } catch (\Throwable $e) {
        if ($openedHere && $this->swapDB->inTransaction()) {
            $this->swapDB->rollBack();
        }
        error_log("[SwapService] confirmCashout FAILED: " . $e->getMessage());
        throw $e;
    }
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
 * ============================================================================
 * REPLACE finalizeAggregatedIdentityClaim() (agent path) WITH THIS.
 * ============================================================================
 * Same PIN "green light" logic as before (unchanged). What changes: instead
 * of looping per-hold through completeIdentitySwapAsDeposit() straight into
 * $destAccount, it collects all pending holds and hands them ONE call to
 * executeIdentityClaimWithSplit(), which does receiving -> holding -> split.
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
    // 1. Verify the agent's destination account (unchanged)
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

    // 2. Get ALL pending holds for this identity (unchanged)
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

    $fullAmount = round((float)array_sum(array_column($pendingHolds, 'amount')), 2);
    if ($cashNowAmount < 0 || $cashNowAmount > $fullAmount) {
        throw new RuntimeException("Requested cash amount must be between 0 and {$fullAmount}.");
    }

    // 3. PIN check ONCE — the "green light" (unchanged)
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
    $this->verifyIdentityClaimPin($holdWithPin, $pin);

    $beneficiaryPhone = $holdWithPin['otp_pin_sent_to'] ?? null;
    if (empty($beneficiaryPhone)) {
        $latestSourcePayload = json_decode($holdWithPin['source_payload'], true);
        $beneficiaryPhone = $latestSourcePayload['notification_phone'] ?? $latestSourcePayload['beneficiary_phone'] ?? null;
    }

    // 4. ONE call does receiving -> holding -> payout -> remainder-hold.
    // cashNowAmount here means "pay this much to the merchant now"; unlike
    // the old version there's no separate re-swap-as-new-IDENTITY-hold step
    // for the remainder — step 5 inside executeIdentityClaimWithSplit()
    // places a real bank-side hold on it directly.
    $result = $this->executeIdentityClaimWithSplit(
        $pendingHolds,
        $destAccount['institution'],
        'DEPOSIT',
        [
            'destination_identifier' => $destAccount['identifier'],
            'destination_identifier_type' => $destAccount['identifier_type'],
            'destination_asset_type' => $destAccount['asset_type'],
        ],
        $cashNowAmount,
        $confirmedByType,
        $confirmedById,
        $beneficiaryPhone,
        $identityType,
        $identityValue
    );

    return $result;
}


/**
 * ============================================================================
 * REPLACE finalizeAggregatedIdentityClaimSelfService() (self-service path,
 * from claim_identity.php) WITH THIS. Same structure as the agent version
 * above, minus the pre-registered destination-account lookup — the caller
 * supplies destination details directly.
 * ============================================================================
 */
public function finalizeAggregatedIdentityClaimSelfService(
    string $identityType,
    string $identityValue,
    string $pin,
    int $confirmedById,
    string $destinationType,
    array $destinationDetails,
    ?float $cashNowAmount = null // null = claim everything now (typical self-service default)
): array {
    $destinationType = strtoupper($destinationType);
    if (!in_array($destinationType, ['CASHOUT', 'DEPOSIT'], true)) {
        throw new RuntimeException("destination_type must be 'CASHOUT' or 'DEPOSIT'");
    }
    if ($destinationType === 'DEPOSIT' && empty($destinationDetails['destination_institution'])) {
        throw new RuntimeException("destination_institution is required for DEPOSIT");
    }
    if ($destinationType === 'CASHOUT' && empty($destinationDetails['destination_institution'])) {
        throw new RuntimeException("destination_institution is required for CASHOUT");
    }
    $destinationInstitution = $destinationDetails['destination_institution'];

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

    $holdWithPin = null;
    foreach ($pendingHolds as $hold) {
        if (!empty($hold['otp_pin_hash'])) {
            $holdWithPin = $hold;
            break;
        }
    }
    if (!$holdWithPin) {
        throw new RuntimeException(
            "No PIN has been set for this identity yet. If this identity is registered to your " .
            "account, log in and finalize with your transaction PIN instead. Otherwise ask the " .
            "sender to confirm the claim PIN was sent, or contact VouchMorph support."
        );
    }
    // Hard-locked to 'user' — agent-assisted claims must go through
    // finalizeAggregatedIdentityClaim() instead, never this method.
    $this->verifyIdentityClaimPin($holdWithPin, $pin, 'user');

    $beneficiaryPhone = $holdWithPin['otp_pin_sent_to'] ?? null;
    if (empty($beneficiaryPhone)) {
        $latestSourcePayload = json_decode($holdWithPin['source_payload'], true);
        $beneficiaryPhone = $latestSourcePayload['notification_phone'] ?? $latestSourcePayload['beneficiary_phone'] ?? null;
    }

    return $this->executeIdentityClaimWithSplit(
        $pendingHolds,
        $destinationInstitution,
        $destinationType,
        $destinationDetails,
        $cashNowAmount,
        'user',
        $confirmedById,
        $beneficiaryPhone,
        $identityType,
        $identityValue
    );
}

/**
 * ============================================================================
 * ADD TO SwapService.php — the single orchestration method both
 * finalizeAggregatedIdentityClaim() (agent) and
 * finalizeAggregatedIdentityClaimSelfService() (self-service) now call,
 * instead of each looping over completeIdentitySwapAsDeposit()/
 * completeIdentitySwapAsCashout() independently. One code path, one thing
 * to test.
 * ============================================================================
 *
 * Flow:
 *   1. (optional) switch fast-path if every institution shares one — see
 *      attemptSwitchConsolidation(). Currently disabled (unverified adapter
 *      interface) — falls through to step 2 always for now.
 *   2. Debit each hold's source, deposit into destination institution's
 *      RECEIVING account.
 *   3. Sweep RECEIVING -> HOLDING for the confirmed total.
 *   4. Pay $cashNowAmount (or everything, if null) from HOLDING to the real
 *      destination — a merchant deposit, or a generated cashout code.
 *   5. Whatever's left: place a real hold on the HOLDING account balance,
 *      tied to the identity, recorded in identity_holding_positions.
 *
 * All destination holds MUST be at the SAME institution — that's what
 * "consolidation" means here. If cash sent to this identity came from
 * multiple *source* institutions, that's fine (each is a separate debit +
 * receiving-account deposit); they all converge at the ONE destination
 * institution the caller specifies.
 */
public function executeIdentityClaimWithSplit(
    array $holds,                    // identity_swap_holds rows, all same identity + currency
    string $destinationInstitution,  // where consolidation + payout happens
    string $destinationType,         // 'DEPOSIT' | 'CASHOUT'
    array $destinationDetails,       // DEPOSIT: destination_identifier/_type/_asset_type. CASHOUT: delivery_method
    ?float $cashNowAmount,           // null = pay out everything now, no remainder hold
    string $confirmedByType,
    ?int $confirmedById,
    ?string $beneficiaryPhone,
    string $identityType,
    string $identityValue
): array {
    if (empty($holds)) {
        throw new RuntimeException("No holds provided to finalize.");
    }

    $currencies = array_unique(array_column($holds, 'currency'));
    if (count($currencies) > 1) {
        throw new RuntimeException("Cannot consolidate holds across multiple currencies in one claim.");
    }
    $currency = $currencies[0] ?? 'BWP';

    $destinationType = strtoupper($destinationType);
    if (!in_array($destinationType, ['DEPOSIT', 'CASHOUT'], true)) {
        throw new RuntimeException("destination_type must be 'DEPOSIT' or 'CASHOUT'");
    }

    // Capability gate — fail loudly up front.
    $this->getIdentityHoldingAccounts($destinationInstitution, $currency);

    $consolidationReference = 'CONSOL_' . time() . '_' . bin2hex(random_bytes(4));

    // ------------------------------------------------------------
    // Debit + settle each hold (switch-vs-direct decided per leg
    // inside finalizeHoldToReceiving() -> settleToDestinationReceiving()).
    // ------------------------------------------------------------
    $landedHoldIds = [];
    $failedHolds = [];
    $totalLanded = 0.0;

    foreach ($holds as $hold) {
        try {
            $depositResult = $this->finalizeHoldToReceiving($hold, $destinationInstitution, $consolidationReference);
            $totalLanded += $depositResult['net_amount'];
            $landedHoldIds[] = (int)$hold['hold_id'];
        } catch (\Throwable $e) {
            error_log("[SwapService] executeIdentityClaimWithSplit: hold {$hold['hold_id']} failed: " . $e->getMessage());
            $failedHolds[] = [
                'hold_id' => $hold['hold_id'],
                'swap_reference' => $hold['swap_reference'],
                'gross_amount' => (float)$hold['amount'],
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    if (empty($landedHoldIds)) {
        throw new RuntimeException("All underlying holds failed to reach the receiving account — nothing was claimed. See individual errors and retry.");
    }

    // ------------------------------------------------------------
    // STEP 3: sweep RECEIVING -> HOLDING.
    // ------------------------------------------------------------
    $sweptAmount = $this->sweepReceivingToHolding($destinationInstitution, $currency, $consolidationReference);

    // ------------------------------------------------------------
    // STEP 4: pay out the cash-now portion (or all of it).
    //
    // FIX: no fee was being calculated anywhere in this method at all.
    // Per the agreed rule (fee applies only to money actually delivered
    // now, never to money going back into a HOLDING/identity-parked
    // state), the fee is computed against payoutAmount specifically --
    // the leg that's actually leaving the pool right now -- and
    // deducted from what the destination institution is instructed to
    // deposit/dispense. The remainder calculation below stays based on
    // the GROSS payoutAmount, not the fee-reduced net: the fee is
    // VouchMorph's revenue taken out of the delivered leg, it doesn't
    // change how much of the pool was consumed.
    // ------------------------------------------------------------
    $payoutAmount = $cashNowAmount === null ? $sweptAmount : min($cashNowAmount, $sweptAmount);
    $remainder = round($sweptAmount - $payoutAmount, 2);

    $netPayoutAmount = $payoutAmount;
    $feeBreakdown = null;
    if ($payoutAmount > 0) {
        $feeType = $destinationType === 'CASHOUT' ? 'CASHOUT' : 'DEPOSIT';
        $feeBreakdown = $this->calculateFeesWithDetails($feeType, $payoutAmount, array_merge(
            $destinationDetails,
            [
                'currency' => $currency,
                'institution' => $destinationInstitution,
                'destination_institution' => $destinationInstitution,
                'asset_type' => $destinationDetails['destination_asset_type'] ?? 'ACCOUNT',
            ]
        ));
        $netPayoutAmount = round($feeBreakdown['net_amount_source_currency'] ?? $payoutAmount, 2);
    }

    $payoutResult = null;
    if ($netPayoutAmount > 0) {
        if ($destinationType === 'DEPOSIT') {
            $payoutResult = $this->payHoldingToMerchant(
                $destinationInstitution,
                $currency,
                $netPayoutAmount,
                $destinationDetails['destination_identifier'],
                $destinationDetails['destination_identifier_type'] ?? 'account_number',
                $destinationDetails['destination_asset_type'] ?? 'ACCOUNT',
                $consolidationReference . '_PAYOUT'
            );
        } else {
            $payoutResult = $this->generateCashoutFromHolding(
                $destinationInstitution,
                $currency,
                $netPayoutAmount,
                $destinationDetails['delivery_method'] ?? 'ATM',
                $beneficiaryPhone,
                $consolidationReference . '_PAYOUT'
            );
        }
    }

    // ------------------------------------------------------------
    // STEP 5: hold whatever's left, no bounce-back re-swap.
    // ------------------------------------------------------------
    $holdingPositionId = null;
    if ($remainder > 0) {
        $holdingPositionId = $this->placeHoldOnHoldingRemainder(
            $destinationInstitution,
            $currency,
            $remainder,
            $identityType,
            $identityValue,
            $landedHoldIds,
            $consolidationReference
        );
    }

    // Audit trail for the consolidation as a whole.
    $this->writeAuditLogEntry(
        'identity_holding_positions',
        $consolidationReference,
        'IDENTITY_CLAIM_CONSOLIDATED',
        'financial',
        $confirmedById,
        $confirmedByType,
        $confirmedById ?? 0,
        [
            'identity_type' => $identityType,
            'identity_value' => $identityValue,
            'institution' => $destinationInstitution,
            'holds_landed' => $landedHoldIds,
            'holds_failed' => array_column($failedHolds, 'hold_id'),
            'swept_amount' => $sweptAmount,
            'payout_amount' => $payoutAmount,
            'remainder' => $remainder,
        ]
    );

    return [
        'status' => empty($failedHolds) ? 'success' : 'partial_success',
        'identity_type' => $identityType,
        'identity_value' => $identityValue,
        'currency' => $currency,
        'consolidation_reference' => $consolidationReference,
        'holds_landed' => count($landedHoldIds),
        'holds_failed' => $failedHolds,
        'total_consolidated' => $sweptAmount,
        'payout_amount_gross' => $payoutAmount,
        'fee' => $feeBreakdown,
        'payout_amount_net' => $netPayoutAmount,
        'payout_result' => $payoutResult,
        'remainder_held' => $remainder,
        'holding_position_id' => $holdingPositionId,
    ];
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

    $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn-production.up.railway.app', '/')
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

    $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn-production.up.railway.app', '/')
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

    $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn-production.up.railway.app', '/')
        . '/api/v1/user/source_oauth_callback.php';

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

    $callbackUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://vouchmorphn-production.up.railway.app', '/')
        . '/api/v1/user/source_oauth_callback.php';

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

  
 private function resolveStandardSwapDeliveryMethod(array $payload): array
{
    $deliveryMethod = strtoupper(
        $payload['delivery_method']
        ?? ($payload['destination_asset_type'] ?? null)
        ?? 'DEPOSIT'
    );

    if (in_array($deliveryMethod, ['CASHOUT', 'ATM', 'AGENT', 'VOUCHER'], true)) {
        error_log("[SwapService] STANDARD swap resolved to CASHOUT (delivery_method={$deliveryMethod})");
        $payload['swap_type'] = 'CASHOUT';
        return $this->executeSignedCashout($payload);
    }

    error_log("[SwapService] STANDARD swap resolved to DEPOSIT (delivery_method={$deliveryMethod})");
    $payload['swap_type'] = 'DEPOSIT';
    return $this->executeSignedDeposit($payload);
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
    // FIX: same card-acquirer signature exemption applied at
    // executeSignedDeposit()'s PLACE_HOLD_SIGNED check - see that comment
    // for the full explanation.
    $isCardAcquirer = isset($this->participants[$sourceInstitution]['card_acquirer']);
    $this->assertStepIntegrity(
        $holdResult,
        'hold_placed',
        ($isHooked || $isCardAcquirer) ? ['hold_reference'] : ['hold_reference', 'signature'],
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

    // FIX: mark the hold DEBITED, same as CASHOUT/DEPOSIT do
    $this->updateHoldStatus($this->currentHoldId, 'DEBITED');

    // Post ledger legs for standard swap
    $sourceIdentifier = $this->extractSourceIdentifier($payload);
    $destIdentifier = $this->extractDestinationIdentifier($payload);
    $this->postLedgerLegs(
        $this->currentSwapRef,
        $sourceInstitution,
        $payload['asset_type'] ?? 'ACCOUNT',
        $sourceIdentifier['identifier'] ?? null,
        $amount,
        $destInstitution,
        $payload['destination_asset_type'] ?? 'ACCOUNT',
        $destIdentifier['identifier'] ?? null,
        $netAmount,
        $feeBreakdown['total_fee'] ?? 0,
        $payload['currency'] ?? 'BWP',
        $this->currentHoldReference
    );

    // FIX: this flow was silently skipping tracking entirely
    $this->populateTrackingTables(
        [
            'swap_type' => 'STANDARD',
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'currency' => $payload['currency'] ?? 'BWP',
            'status' => 'completed',
            'from_institution' => $sourceInstitution,
            'to_institution' => $destInstitution,
            'user_id' => $payload['user_id'] ?? null
        ],
        $payload,
        $destinationResult
    );
    
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
private function loadAtmNotesStrict(array $countryConfig, string $countryFallback): void
    {
        // Prefer atm_notes LoadCountry already resolved and loaded — it
        // already solved the "country code vs folder name" mismatch that
        // was the actual bug here (Countries/BW/atm_notes.json doesn't
        // exist; Countries/Botswana/atm_notes.json does, and LoadCountry
        // already found it a moment earlier in this same constructor).
        if (!empty($countryConfig['atm_notes']) && is_array($countryConfig['atm_notes'])) {
            $this->atmNotes = $countryConfig['atm_notes'];
            foreach ($this->atmNotes as $currency => $denominations) {
                if (!is_array($denominations) || empty($denominations)) {
                    throw new RuntimeException(
                        "Currency '{$currency}' in atm_notes has no denominations. " .
                        "Each currency must have an array of note values."
                    );
                }
                rsort($denominations);
                $this->atmNotes[$currency] = $denominations;
            }
            error_log("[SwapService] Loaded ATM notes from countryConfig (already resolved by LoadCountry): " . json_encode($this->atmNotes));
            return;
        }

        // Fallback: derive the folder name the same way LoadCountry does —
        // prefer countryConfig['country'] (the real folder name), never
        // the raw constructor argument alone.
        $countryFolderName = $countryConfig['country'] ?? $countryFallback;
        $countryFolder = __DIR__ . '/../../Core/Config/Countries/' . $countryFolderName;
        $atmNotesPath = $countryFolder . '/atm_notes.json';

        if (!file_exists($atmNotesPath)) {
            throw new RuntimeException(
                "Required file not found: {$atmNotesPath}. " .
                "Country '{$countryFolderName}' must have atm_notes.json in its config folder."
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

        foreach ($this->atmNotes as $currency => $denominations) {
            if (!is_array($denominations) || empty($denominations)) {
                throw new RuntimeException(
                    "Currency '{$currency}' in atm_notes.json has no denominations. " .
                    "Each currency must have an array of note values."
                );
            }
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
            'reference' => $payload['reference'] ?? $this->currentSwapRef,   
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
            try {
                $holdId = $this->createLocalHold($payload, $institution, $result['hold_reference'] ?? null);
            } catch (\Throwable $localHoldError) {
                // The bank-side hold is REAL and already placed. Failing to
                // record it locally must never leave it silently dangling —
                // attempt an emergency release immediately rather than
                // orphaning real held funds with zero VouchMorph trace.
                $this->logger->critical("Local hold record failed AFTER a real hold was placed at {$institution} - attempting emergency release", [
                    'institution' => $institution,
                    'hold_reference' => $result['hold_reference'] ?? null,
                    'error' => $localHoldError->getMessage(),
                ]);
                try {
                    $adapter = $this->adapterFactory->getAdapter($institution);
                    $adapter->releaseHold([
                        'hold_reference' => $result['hold_reference'] ?? null,
                        'action' => 'RELEASE_HOLD',
                        'reason' => 'Local hold recording failed - emergency release',
                    ], []);
                    $this->logger->warning("Emergency release succeeded for orphaned hold", ['hold_reference' => $result['hold_reference'] ?? null]);
                } catch (\Throwable $releaseError) {
                    $this->logger->emergency("EMERGENCY RELEASE ALSO FAILED - real money is held at {$institution} with NO local record. Manual intervention required.", [
                        'institution' => $institution,
                        'hold_reference' => $result['hold_reference'] ?? null,
                        'release_error' => $releaseError->getMessage(),
                    ]);
                }
                throw new RuntimeException(
                    "Hold was placed at {$institution} (reference: " . ($result['hold_reference'] ?? 'unknown') . ") " .
                    "but could not be recorded locally: " . $localHoldError->getMessage() . ". An emergency release was attempted."
                );
            }
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
    // ============================================================
    // FIX: The agent float-minimum floor must NOT fire just because
    // this institution+identifier happens to appear in
    // agent_destination_accounts. An account is not permanently "an
    // agent account" - being an agent destination is a role a person
    // opts into for finalizing identity claims, and the same person
    // routinely uses that same account as an ordinary source for their
    // own standard/deposit/cashout swaps. Without this gate, a user who
    // is also an approved agent gets the agent float rule wrongly
    // applied to every normal swap they do out of that account.
    //
    // The check now only runs when the CALLER explicitly marks this
    // debit as an agent drawing down their own registered float
    // (_agent_float_debit => true), never inferred from identity.
    // ============================================================
    if ($sourceId['has_value'] && !empty($payload['_agent_float_debit'])) {
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
 * Called after debitSource() succeeds in ANY DIRECT flow (deposit,
 * cashout, identity finalization). At this point the destination
 * institution has ALREADY attested it delivered value to the customer
 * (processDeposit succeeded, or confirmCashout said cash was dispensed)
 * — they are now genuinely owed money, and VouchMorph's debitSource()
 * call was VouchMorph's attempt to pay them. This method starts
 * tracking whether that payment actually landed, per the destination
 * institution's configured confirmation mode.
 *
 * Switch-executed swaps never call this — the switch's own
 * submitTransfer() response already atomically confirms both legs,
 * so settlement_status stays NOT_APPLICABLE for those.
 */
private function recordSettlementPending(
    string $swapRef,
    string $destinationInstitution,
    string $settlementReference,
    float $amount,
    string $currency
): void {
    $destParticipant = $this->participants[$destinationInstitution] ?? [];
    $confirmationConfig = $destParticipant['settlement_confirmation'] ?? ['mode' => 'POLL'];
    $mode = strtoupper($confirmationConfig['mode'] ?? 'POLL');

    try {
        $this->runInSavepoint('settlement_pending_' . $swapRef, function () use (
            $swapRef, $destinationInstitution, $settlementReference, $amount, $currency, $mode
        ) {
            $stmt = $this->swapDB->prepare("
                INSERT INTO settlement_confirmations (
                    swap_reference, destination_institution, settlement_reference,
                    amount, currency, confirmation_mode, status
                ) VALUES (?, ?, ?, ?, ?, ?, 'PENDING')
                ON CONFLICT (swap_reference) DO NOTHING
            ");
            $stmt->execute([$swapRef, $destinationInstitution, $settlementReference, $amount, $currency, $mode]);

            $stmt = $this->swapDB->prepare("
                UPDATE swap_requests SET settlement_status = 'UNCONFIRMED' WHERE swap_uuid = ?
            ");
            $stmt->execute([$swapRef]);
        });

        error_log("[SwapService] Settlement confirmation tracking started: swap={$swapRef}, destination={$destinationInstitution}, mode={$mode}");
    } catch (\Throwable $e) {
        error_log("[SwapService] Failed to record settlement pending for {$swapRef}: " . $e->getMessage());
        // Non-fatal — the customer already has their money. This is a
        // tracking failure, not a swap failure. Same discipline as
        // every other tracking-table write in this class.
    }
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

    // ============================================================
    // FIX: ADD asset_type to the release payload
    // ZuruBank's hold.php requires this to auto-detect the release target
    // (VOUCHER, ACCOUNT, or WALLET)
    // ============================================================
    $releasePayload = [
        'action' => 'RELEASE_HOLD',
        'hold_reference' => $holdRef,
        'asset_type' => $sourcePayload['asset_type'] ?? 'ACCOUNT',   // ADDED - critical fix
        'reason' => 'Multi-source swap rolled back',
        'from_institution' => $institution,
        'source_institution' => $institution,
        'reference' => $this->currentSwapRef ?? 'RELEASE_' . uniqid()
    ];

    $this->forwardPin($sourcePayload, $releasePayload);
    
    if (!empty($sourcePayload['access_token'])) {
        $releasePayload['access_token'] = $sourcePayload['access_token'];
    }

    // Add certificate if available
    if (!empty($sourcePayload['certificate'])) {
        $releasePayload['certificate'] = $sourcePayload['certificate'];
    }
    
    // Add signature if available
    if (!empty($sourcePayload['signature'])) {
        $releasePayload['signature'] = $sourcePayload['signature'];
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
            'from_institution' => 'VM_POOL',     
            'source_institution' => 'VM_POOL', 
            'sender_phone' => 'VM_POOL',
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
    } elseif ($destinationAssetType === 'CARD') {
        $creditPayload['card_token'] = $destId['identifier'];
        $creditPayload['destination_card_token'] = $destId['identifier'];
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
    } elseif ($destinationAssetType === 'CARD') {
        // Maps onto CardAcquirerBankClient::processDepositWithProof()'s
        // expected field — that method checks card_token OR
        // destination_identifier, this sets both for safety.
        $depositPayload['card_token'] = $destId['identifier'];
        $depositPayload['destination_card_token'] = $destId['identifier'];
        error_log("[SwapService] Destination is CARD: {$destId['identifier']}");
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

   private function storeIdentityHold(array $payload, string $swapRef, array $holdResult, int $holdId, bool $isReusedHold = false): array
{
    $sourceInstitution = $this->extractSourceInstitution($payload);
    $identityType = strtolower($payload['identity_type']);
    $identityValue = $payload['identity_value'];
 
    $owner = $this->findVerifiedIdentityOwner($identityType, $identityValue);
    $notificationPhone = $payload['notification_phone'] ?? $payload['beneficiary_phone'] ?? null;

    // FIX: this used to read $levyAmount from feesConfig directly and never
    // use it anywhere -- dead code, no fee was ever actually calculated or
    // charged for placing this hold. Now computes a real fee via the same
    // calculateFeesWithDetails() wrapper used everywhere else in this class.
    //
    // Gated on !$isReusedHold: storeIdentityHold() is the only call site
    // for this method, called unconditionally by initiateSwapToIdentity()
    // whether a hold was freshly placed OR an existing one is being reused
    // via _skip_hold (e.g. a MULTI_SOURCE pool hold placed earlier by
    // different code). Charging this fee on the reuse path would
    // double-charge a hold that may already have been fee'd when it was
    // first created. Only a genuinely NEW hold gets a hold fee.
    //
    // IMPORTANT: this fee does NOT reduce $payload['amount'] below, and the
    // full gross amount is still what gets held/verified at the source bank
    // and stored as this row's `amount`. Reducing the stored amount instead
    // would mean only the smaller net gets captured later in
    // finalizeHoldToReceiving() -- the uncaptured difference (the fee)
    // would simply release back to the client on hold expiry, which is the
    // opposite of collecting it. Instead, the fee is recorded here (in
    // metadata, no schema change needed) and must be netted out of what
    // source ultimately owes destination in the settlement bill --
    // consistent with VouchMorph never custodying money at any point in
    // this design. Whoever builds the settle/bill step needs to read
    // metadata->hold_fee for every consolidated hold and include it in
    // what's deducted from the source's settlement obligation.
    $holdFeeBreakdown = null;
    $holdFeeAmount = 0.0;
    $holdLevyAmount = 0.0;
    if (!$isReusedHold) {
        $holdFeeBreakdown = $this->calculateFeesWithDetails('IDENTITY_HOLD', (float)$payload['amount'], array_merge(
            $payload,
            ['institution' => $sourceInstitution]
        ));
        $holdFeeAmount = (float)($holdFeeBreakdown['total_fee'] ?? 0);
        $holdLevyAmount = (float)($holdFeeBreakdown['swap_levy'] ?? 0);
    }
 
    $claimType = null;
    $otpHash = null;
    $otpPlaintext = null;
    $otpEncrypted = null;
    $otpDestination = null;
    $otpDestinationType = null; // 'phone' or 'email'
    $requiresDual = false;
    $otpFallbackUsed = false;
 
    if ($owner) {
        // ============================================================
        // Registered/verified identities get BOTH claim paths:
        //   - account_pin: owner finalizes self-service, no OTP needed
        //   - OTP: an agent finalizes on the owner's behalf, using an
        //     OTP the owner reads out to them (never the account PIN,
        //     which the agent should never see/know).
        //
        // OTP delivery preference:
        //   1. the owner's own registered phone/email (most trustworthy)
        //   2. FALLBACK: the notification_phone/beneficiary_phone the
        //      sender supplied, if the owner has no contact on file.
        //      Without this fallback, agent-assisted finalization was
        //      silently impossible whenever a verified user's profile
        //      had no phone/email on file - self-service via account
        //      PIN still works either way.
        // ============================================================
        $claimType = 'account_pin';
        error_log("[SwapService] Identity {$identityType}={$identityValue} is a VERIFIED registered owner (user_id={$owner['user_id']}) - generating OTP for agent-assisted claims, account PIN available for self-service");

        [$otpDestination, $otpDestinationType] = $this->getOwnerContactForOtp($owner['user_id']);

        if (!$otpDestination && $notificationPhone) {
            error_log("[SwapService] Registered owner user_id={$owner['user_id']} has no phone/email on file - falling back to sender-supplied notification_phone for OTP delivery");
            $otpDestination = $notificationPhone;
            $otpDestinationType = 'phone';
            $otpFallbackUsed = true;
        }

        if ($otpDestination) {
            $otp = $this->generateOtpPin();
            $otpPlaintext = $otp;
            $otpHash = password_hash($otp, PASSWORD_DEFAULT);
            $otpEncrypted = $this->encryptSourceSecret($otp); // reuses the existing AES-256-CBC helper

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

            if ($otpFallbackUsed) {
                // Compliance visibility: this OTP went to an address that
                // isn't on the verified owner's own profile. Not a security
                // hole (same trust level as the unregistered otp_pin path
                // below), but worth its own audit trail entry.
                $this->writeAuditLogEntry(
                    'identity_swap_holds',
                    $swapRef,
                    'IDENTITY_OTP_FALLBACK_USED',
                    'identity',
                    $owner['user_id'],
                    'system',
                    0,
                    [
                        'identity_type' => $identityType,
                        'identity_value' => $identityValue,
                        'owner_user_id' => $owner['user_id'],
                        'otp_sent_to' => $otpDestination,
                        'reason' => 'owner has no phone/email on file',
                    ]
                );
            }
        } else {
            error_log("[SwapService] WARNING: Registered owner user_id={$owner['user_id']} has no usable phone/email on file, and no notification_phone/beneficiary_phone was supplied - agent-assisted claims will not be possible until one of these is fixed. Self-service via account PIN is still available.");
        }

    } elseif ($notificationPhone) {
        $claimType = 'otp_pin';
        $otp = $this->generateOtpPin();
        $otpPlaintext = $otp;
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $otpEncrypted = $this->encryptSourceSecret($otp);
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
            otp_pin_hash, otp_pin_encrypted, otp_pin_sent_to, otp_pin_sent_at,
            requires_dual_confirmation, claim_type
        ) VALUES (
            :swap_ref, :source_institution, :source_identifier, :asset_type,
            :amount, :currency, :identity_type, :identity_value,
            :hold_reference, :hold_id, :expires_at, 'pending',
            :source_payload::jsonb, :metadata::jsonb, :created_by,
            :otp_pin_hash, :otp_pin_encrypted, :otp_pin_sent_to, :otp_pin_sent_at,
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
                'hold_result' => $holdResult,
                'hold_fee' => [
                    'total_fee' => $holdFeeAmount,
                    'swap_levy' => $holdLevyAmount,
                    'breakdown' => $holdFeeBreakdown['breakdown'] ?? [],
                    // must be netted out of source's obligation at
                    // settlement time -- see the FIX comment above
                    // $holdFeeBreakdown for why this isn't deducted
                    // from the held/captured amount itself.
                ],
            ]),
            ':created_by' => $payload['user_id'] ?? null,
            ':otp_pin_hash' => $otpHash,
            ':otp_pin_encrypted' => $otpEncrypted,
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
 * Marks an identity_swap_holds row's hold fee as waived -- called from
 * rollbackAtomicSwap() when a hold's release is caused by VouchMorph's
 * own system failure, not a client choice. Never deletes or refunds
 * anything, since the fee was never collected as cash upfront (it's
 * netted into the settlement bill at finalize time). Whatever later
 * builds the settle/bill step must check metadata->hold_fee->waived
 * and skip any hold where it's true.
 */
private function waiveIdentityHoldFee(int $holdId, string $waivedReason): void
{
    try {
        $stmt = $this->swapDB->prepare("
            UPDATE identity_swap_holds
            SET metadata = jsonb_set(
                jsonb_set(metadata, '{hold_fee,waived}', 'true'::jsonb, true),
                '{hold_fee,waived_reason}', to_jsonb(:reason::text), true
            )
            WHERE hold_id = :hold_id
        ");
        $stmt->execute([':hold_id' => $holdId, ':reason' => $waivedReason]);

        $this->logger->info("Identity hold fee waived", [
            'hold_id' => $holdId,
            'reason' => $waivedReason,
        ]);
    } catch (PDOException $e) {
        // Deliberately non-fatal: failing to mark a fee as waived must
        // never block the actual hold release, which is the operation
        // that matters for the client. Log loudly so this doesn't go
        // unnoticed -- an un-waived fee row needs manual correction
        // before it's netted into a settlement bill.
        error_log("[SwapService] CRITICAL: Failed to waive hold fee for hold_id={$holdId} - "
            . "this fee may incorrectly get netted into a settlement bill unless corrected "
            . "manually: " . $e->getMessage());
    }
}

 public function getDecryptedClaimPin(array $identitySwapRow): ?string
{
    if (empty($identitySwapRow['otp_pin_encrypted'])) return null;
    return $this->decryptSourceSecret($identitySwapRow['otp_pin_encrypted']);
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
    string $codeExpiry,
    ?string $poolId = null  
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
            updated_at,
            metadata
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
            NOW(),
            :metadata::jsonb
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
            ':cashout_provider' => $destinationInstitution,
            ':metadata' => json_encode($poolId ? ['pool_id' => $poolId] : []),
        ]);
 
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $authId = $row ? (int)$row['auth_id'] : 0;
 
error_log("[SwapService] Cashout authorization stored: auth_id={$authId}, source={$sourceIdentifierType}:{$sourceIdentifier}, generate_code_fee={$generateCodeFeeAmount}, levy={$levyAmount}, pool_id=" . ($poolId ?? 'none'));
     
        return $authId;
 
    } catch (PDOException $e) {
        error_log("[SwapService] Failed to store cashout authorization: " . $e->getMessage());
        throw new RuntimeException("Failed to store cashout authorization: " . $e->getMessage());
    }
}

 /**
 * Records a pool-sourced cashout authorization. Called by
 * PoolCoordinator::executeCashoutDestination() after the destination
 * bank successfully generates a code, so the ATM callback webhook has
 * something to find later — mirrors storeCashoutAuthorization()'s
 * single-source shape but stamps source_institution as 'VM_POOL'
 * (no single real source) and links back to the pool via metadata.
 */
public function storePoolCashoutAuthorization(
    string $poolId,
    string $swapReference,
    string $destinationInstitution,
    float $amount,
    string $swapCode,
    string $pinCode,
    string $codeExpiry,
   ?string $beneficiaryPhone = null   // NEW
): int {
    return $this->storeCashoutAuthorization(
        $swapReference,
        $beneficiaryPhone ?? 'VM_POOL',   
        'VM_POOL',
        null,
        null,
        $destinationInstitution,
        $amount,
        0, 0, 0,             // fees settled separately via invoice() in completeDeferredPool()
        $swapCode,
        $pinCode,
        $codeExpiry,
        $poolId
    );
}

/**
 * Looks up whether a cashout voucher/swap_reference belongs to a
 * multi-source pool, for cashout_confirm.php to route correctly.
 * Returns null for an ordinary single-source cashout.
 */
public function getPoolIdForCashoutVoucher(?string $voucherNumber, ?string $swapReference): ?string
{
    $sql = "SELECT metadata FROM cashout_authorizations WHERE 1=1";
    $params = [];
    if ($voucherNumber) {
        $sql .= " AND swap_code = :voucher";
        $params[':voucher'] = $voucherNumber;
    } elseif ($swapReference) {
        $sql .= " AND swap_reference = :swap_ref";
        $params[':swap_ref'] = $swapReference;
    } else {
        return null;
    }
    $sql .= " ORDER BY created_at DESC LIMIT 1";

    try {
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['metadata'])) {
            return null;
        }
        $metadata = json_decode($row['metadata'], true) ?: [];
        return $metadata['pool_id'] ?? null;
    } catch (PDOException $e) {
        error_log("[SwapService] getPoolIdForCashoutVoucher failed: " . $e->getMessage());
        return null;
    }
}

 public function confirmPoolCashout(string $poolId, array $confirmationPayload = []): array
{
    if ($this->multiSourceOrchestrator === null) {
        throw new RuntimeException("Multi-source swaps are not enabled");
    }
    return $this->multiSourceOrchestrator->confirmPoolCashout($poolId, $confirmationPayload);
}

public function confirmPoolIdentityClaim(string $poolId): array
{
    if ($this->multiSourceOrchestrator === null) {
        throw new RuntimeException("Multi-source swaps are not enabled");
    }
    return $this->multiSourceOrchestrator->confirmPoolIdentityClaim($poolId);
}

public function cancelExpiredPoolCashouts(int $bufferHours = 6): array
{
    if ($this->multiSourceOrchestrator === null) {
        return ['total_expired' => 0, 'released' => 0, 'errors' => 0, 'details' => []];
    }
    return $this->multiSourceOrchestrator->cancelExpiredPoolCashouts($bufferHours);
}

public function cancelExpiredPoolIdentityClaims(): array
{
    if ($this->multiSourceOrchestrator === null) {
        return ['total_expired' => 0, 'cancelled' => 0, 'errors' => 0, 'details' => []];
    }
    return $this->multiSourceOrchestrator->cancelExpiredPoolIdentityClaims();
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
 
// ----------------------------------------------------------------------
// 5. updateCashoutAuthorizationStatus() — marks PENDING -> COMPLETED etc.
// ----------------------------------------------------------------------
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
        $this->runInSavepoint('update_cashout_auth_' . $authId, function () use ($sql, $status, $authId, $cashoutPoint) {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':auth_id' => $authId,
                ':cashout_point' => $cashoutPoint
            ]);
        });
        error_log("[SwapService] Cashout authorization {$authId} status updated to: {$status}");
    } catch (\Throwable $e) {
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
    $sql = "
        UPDATE swap_requests
        SET status = :status,
            completed_at = CASE
                WHEN LOWER(:status_check) = 'completed' AND completed_at IS NULL THEN NOW()
                ELSE completed_at
            END
        WHERE swap_uuid = :swap_ref
    ";
    $stmt = $this->swapDB->prepare($sql);
    $stmt->execute([
        ':status' => $status,
        ':status_check' => $status,
        ':swap_ref' => $swapRef
    ]);
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
    // --- DIAGNOSTIC: check for a silently-aborted transaction before commit ---
    error_log("[DIAG] Pre-commit errorInfo: " . json_encode($this->swapDB->errorInfo()));
    error_log("[DIAG] Pre-commit inTransaction: " . ($this->swapDB->inTransaction() ? 'YES' : 'NO'));

    $this->swapDB->commit();

    // --- DIAGNOSTIC: check errorInfo immediately after commit, and re-read the
    // just-written hold on the SAME connection, in the SAME process ---
    error_log("[DIAG] Post-commit errorInfo: " . json_encode($this->swapDB->errorInfo()));
    if ($this->currentHoldId) {
        try {
            $check = $this->swapDB->query(
                "SELECT hold_id FROM hold_transactions WHERE hold_id = " . (int)$this->currentHoldId
            )->fetchColumn();
            error_log("[DIAG] Immediate post-commit re-read of hold_id {$this->currentHoldId}: " . var_export($check, true));
        } catch (\Throwable $e) {
            error_log("[DIAG] Post-commit re-read THREW: " . $e->getMessage());
        }
    }
    // --- END DIAGNOSTIC ---

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

 /**
 * Get asset context (asset_type, source_identifier, source_identifier_type) 
 * for a hold from hold_transactions.source_details
 * 
 * This allows releaseHold() calls to include the correct asset context
 * so the bank's hold.php can properly resolve the asset type.
 */
private function getHoldAssetContext(?int $holdId): array
{
    if (!$holdId) return [];
    
    try {
        $stmt = $this->swapDB->prepare("
            SELECT source_details 
            FROM hold_transactions 
            WHERE hold_id = :id
        ");
        $stmt->execute([':id' => $holdId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row || empty($row['source_details'])) {
            return [];
        }
        
        $details = json_decode($row['source_details'], true) ?: [];
        
        return array_filter([
            'asset_type' => $details['asset_type'] ?? null,
            'source_identifier' => $details['source_identifier'] ?? null,
            'source_identifier_type' => $details['source_identifier_type'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log("[SwapService] getHoldAssetContext failed for hold_id={$holdId}: " . $e->getMessage());
        return [];
    }
}

/**
 * Called ONLY when a source debit fails AFTER the destination has
 * already been credited/dispensed. In this state, releasing the hold
 * would double-pay: the destination already got real value, and
 * releasing gives the source's money back to the customer as
 * available balance. This must never happen automatically.
 *
 * Runs on its own connection state, deliberately AFTER the outer
 * $this->swapDB->rollBack() in rollbackAtomicSwap(), so this record
 * survives even though the swap's own DB transaction was rolled back.
 */
private function recordManualReconciliationRequired(
    string $swapRef,
    ?string $holdReference,
    ?string $sourceInstitution,
    ?string $destinationInstitution,
    float $amount,
    string $currency,
    string $reason
): void {
    try {
        $this->swapDB->exec("
            CREATE TABLE IF NOT EXISTS swap_manual_reconciliation_required (
                id BIGSERIAL PRIMARY KEY,
                swap_reference VARCHAR(255) NOT NULL,
                hold_reference VARCHAR(255),
                source_institution VARCHAR(100),
                destination_institution VARCHAR(100),
                amount NUMERIC(18,2),
                currency CHAR(3),
                reason TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                resolved_at TIMESTAMP,
                resolved_by VARCHAR(100),
                resolution_notes TEXT
            )
        ");
        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_manual_reconciliation_required
                (swap_reference, hold_reference, source_institution, destination_institution, amount, currency, reason)
            VALUES (:ref, :hold_ref, :src, :dest, :amount, :ccy, :reason)
        ");
        $stmt->execute([
            ':ref' => $swapRef,
            ':hold_ref' => $holdReference,
            ':src' => $sourceInstitution,
            ':dest' => $destinationInstitution,
            ':amount' => $amount,
            ':ccy' => $currency,
            ':reason' => $reason,
        ]);
        $this->logger->critical("MANUAL RECONCILIATION REQUIRED: destination already paid but source debit failed - hold NOT released, flagged for ops", [
            'swap_reference' => $swapRef,
            'hold_reference' => $holdReference,
            'source_institution' => $sourceInstitution,
            'destination_institution' => $destinationInstitution,
            'amount' => $amount,
            'currency' => $currency,
            'reason' => $reason,
        ]);
    } catch (\Throwable $e) {
        // Same discipline as writeAuditFallback(): if even this fails,
        // scream as loudly as possible rather than losing the signal.
        $this->logger->emergency("swap_manual_reconciliation_required write ITSELF failed - manual DB investigation required immediately", [
            'swap_reference' => $swapRef,
            'hold_reference' => $holdReference,
            'original_reason' => $reason,
            'fallback_error' => $e->getMessage(),
        ]);
    }
}
 
  private function rollbackAtomicSwap(string $reason): array
{
    $holdReference = $this->currentHoldReference;
    $holdInstitution = $this->currentHoldInstitution;
    $swapRef = $this->currentSwapRef;
    $isPostDeliveryFailure = $this->postDeliveryDebitFailure;   // NEW - capture before reset
    $amount = (float)($this->stepResults['amount'] ?? 0);        // best-effort, see note below

    // FIX: Get asset context for the hold before releasing
    // This ensures source_identifier and asset_type are sent to the bank's release endpoint
    $assetContext = [];
    if ($this->currentHoldId) {
        $assetContext = $this->getHoldAssetContext($this->currentHoldId);
        if (!empty($assetContext)) {
            error_log("[SwapService] rollbackAtomicSwap: Retrieved asset context for hold_id={$this->currentHoldId}: " . json_encode($assetContext));
        }
    }

    $releaseResult = null;
    
    // NEW: Skip release when post-delivery failure flag is set
    // Destination already has real money, so we must NOT release the hold
    if ($isPostDeliveryFailure) {
        $this->logger->critical("Skipping hold release - destination already delivered before debit failed", [
            'reference' => $swapRef,
            'hold_reference' => $holdReference,
            'institution' => $holdInstitution,
            'amount' => $amount
        ]);
    }
    if (!$isPostDeliveryFailure && $holdReference && $holdInstitution) {
        try {
            $adapter = $this->adapterFactory->getAdapter($holdInstitution);
            $releasePayload = array_merge([
                'hold_reference' => $holdReference,
                'action' => 'RELEASE_HOLD',
                'reason' => 'Atomic swap rolled back: ' . $reason
            ], $assetContext);
            
            $releaseResult = $adapter->releaseHold($releasePayload, [
                'swap_reference' => $swapRef,
                'institution' => $holdInstitution
            ]);

            // FIX: atomic rollback means the reversal is caused by
            // VouchMorph's own system, never by a client choice -- charging
            // a hold fee here would be billing the client for VouchMorph's
            // own failure. The fee isn't collected as cash upfront (it's
            // netted into the settlement bill at finalize -- see
            // storeIdentityHold()'s FIX comment), so "waiving" it means
            // marking it void here so it's excluded from that netting,
            // rather than refunding money that was never actually taken.
            // Contrast with a voluntary cancellation or unclaimed-hold
            // expiry, where the reservation WAS successfully placed and
            // the fee is kept -- those are separate code paths, not this
            // one, and are unaffected by this change.
            if ($this->currentHoldId) {
                $this->waiveIdentityHoldFee($this->currentHoldId, 'ATOMIC_ROLLBACK: ' . $reason);
            }

            $this->logger->info("Released real hold during rollback", [
                'reference' => $swapRef,
                'hold_reference' => $holdReference,
                'institution' => $holdInstitution,
                'asset_context' => $assetContext,
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

    // NEW: Write manual-reconciliation flag AFTER rollback on fresh transaction state
    if ($isPostDeliveryFailure) {
        $this->recordManualReconciliationRequired(
            $swapRef ?? 'UNKNOWN',
            $holdReference,
            $holdInstitution,
            $this->postDeliveryDestinationInstitution ?? null,
            $this->postDeliveryAmount ?? 0.0,
            $this->postDeliveryCurrency ?? 'BWP',
            $reason
        );
    }

    // Only log to swap_rollback_log if NOT a post-delivery failure
    // (or modify to log the manual reconciliation state)
    if ($holdReference && !$isPostDeliveryFailure) {
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
        'status' => $isPostDeliveryFailure ? 'rolled_back_manual_reconciliation_required' : 'rolled_back',
        'reference' => $swapRef,
        'reason' => $reason,
        'hold_released' => $isPostDeliveryFailure ? false : ($releaseResult['released'] ?? null),
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
        $this->postDeliveryDebitFailure = false;   // NEW
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
                ':hold_ref' => 'HOLD_' . ($externalHoldRef ?? $payload['reference'] ?? $this->currentSwapRef) . '_' . $institution,
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

    
// ----------------------------------------------------------------------
// 1. updateHoldStatus() — called from nearly every swap flow
// ----------------------------------------------------------------------
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
        $this->runInSavepoint('update_hold_status_' . $holdId, function () use ($sql, $status, $holdId) {
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':status' => $status, ':hold_id' => $holdId]);
        });
        error_log("[SwapService] Hold status updated to: {$status} for hold_id: {$holdId}");
    } catch (\Throwable $e) {
        error_log("[SwapService] Failed to update hold status: " . $e->getMessage());
    }
}
 
// ----------------------------------------------------------------------
// 2. updateHoldExpiry() — called from executeSignedCashout
// ----------------------------------------------------------------------
private function updateHoldExpiry(?int $holdId, string $expiresAt): void
{
    if ($holdId === null) return;
 
    try {
        $this->runInSavepoint('update_hold_expiry_' . $holdId, function () use ($holdId, $expiresAt) {
            $stmt = $this->swapDB->prepare("
                UPDATE hold_transactions SET expires_at = :expires_at, updated_at = NOW()
                WHERE hold_id = :id
            ");
            $stmt->execute([':expires_at' => $expiresAt, ':id' => $holdId]);
        });
        error_log("[SwapService] Hold {$holdId} expiry set to {$expiresAt}");
    } catch (\Throwable $e) {
        error_log("[SwapService] Failed to update hold expiry: " . $e->getMessage());
    }
}

    /**
     * Posts switch-level ledger legs for a completed money movement.
     * Not a custodial ledger - VouchMorph never holds these funds. This
     * proves what was instructed and confirmed at each institution.
     * Call ONLY after the real debit (and credit/dispense, where synchronous)
     * has actually succeeded - never at hold or code-generation time.
     */
    private function postLedgerLegs(
        string $swapRef,
        string $sourceInstitution,
        string $sourceAssetType,
        ?string $sourceIdentifier,
        float $grossDebited,
        ?string $destInstitution,
        ?string $destAssetType,
        ?string $destIdentifier,
        float $netCredited,
        float $feeAmount,
        string $currency,
        ?string $holdReference = null
    ): void {
        $swapId = $this->getSwapRequestId($swapRef);
        $legs = [
            ['leg' => 'SOURCE_DEBIT', 'institution' => $sourceInstitution, 'asset_type' => $sourceAssetType,
             'identifier' => $sourceIdentifier, 'amount' => $grossDebited],
        ];
        if ($destInstitution && $netCredited > 0) {
            $legs[] = ['leg' => 'DEST_CREDIT', 'institution' => $destInstitution, 'asset_type' => $destAssetType,
                       'identifier' => $destIdentifier, 'amount' => $netCredited];
        }
        if ($feeAmount > 0) {
            $legs[] = ['leg' => 'FEE_RECEIVABLE', 'institution' => $sourceInstitution, 'asset_type' => $sourceAssetType,
                       'identifier' => $sourceIdentifier, 'amount' => $feeAmount];
        }

        foreach ($legs as $i => $leg) {
            try {
                $this->runInSavepoint('ledger_' . $swapRef . '_' . $i, function () use ($leg, $swapRef, $currency, $holdReference, $swapId) {
                    $stmt = $this->swapDB->prepare("
                        INSERT INTO ledger_entries
                            (swap_reference, leg, institution, asset_type, identifier, amount, currency, hold_reference, confirmed_at, swap_id)
                        VALUES
                            (:ref, :leg, :inst, :atype, :ident, :amount, :ccy, :hold, NOW(), :swap_id)
                    ");
                    $stmt->execute([
                        ':ref' => $swapRef, ':leg' => $leg['leg'], ':inst' => $leg['institution'],
                        ':atype' => $leg['asset_type'], ':ident' => $leg['identifier'], ':amount' => $leg['amount'],
                        ':ccy' => $currency, ':hold' => $holdReference, ':swap_id' => $swapId,
                    ]);
                });
            } catch (\Throwable $e) {
                error_log("[SwapService] Failed to post ledger leg {$leg['leg']} for {$swapRef}: " . $e->getMessage());
            }
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
/**
 * Settles a card-swipe leg to the institution that physically
 * dispensed cash (ATM) or credited a merchant (POS), sourced from a
 * specific hooked source institution's settlement account. Reuses the
 * SAME switch-vs-direct decision logic identity-claim consolidation
 * already uses — a card swipe settlement and an identity-claim
 * settlement are the same underlying operation (move money from one
 * institution's settlement position to another's receiving account),
 * just triggered by a different event.
 */
public function settleCardSwipeToDestination(
    string $sourceInstitution,
    string $destinationInstitution,
    string $currency,
    float $amount,
    string $reference
): float {
    return $this->settleToDestinationReceiving(
        $sourceInstitution, $destinationInstitution, $currency, $amount, $reference
    );
}

 /**
 * PLACEHOLDER — see class header note. Assumes field 32 arrives as
 * the institution's own participants.yaml code directly, which will
 * NOT be true for any real switch/acquirer traffic. Replace with a
 * real acquirer-ID → institution-code lookup once that mapping data
 * exists.
 */
public function resolveInstitutionByAcquirerId(string $acquirerId): ?string
{
    // Best-effort direct match only.
    if (isset($this->participants[$acquirerId])) {
        return $acquirerId;
    }
    foreach ($this->participants as $code => $participant) {
        if (($participant['iso8583_acquirer_id'] ?? null) === $acquirerId) {
            return $code;
        }
    }
    return null;
}
 
 /**
 * Exposes the already-constructed, already-wired FeeService instance
 * for read-only fee previews by external protocol adapters (Mojaloop
 * QuotesHandler, etc.) that need a fee estimate without executing a
 * real swap. This is the SAME FeeService instance used internally by
 * calculateFeesWithDetails() — already has setParticipants() called,
 * already has the real ForexService wired in via the constructor.
 *
 * Safe to call from outside: FeeService::calculateFees() only mutates
 * FeeService's OWN internal $context/$calculatedFees state, never
 * touches SwapService's atomic-swap state ($currentSwapRef,
 * $currentHoldId, $inAtomicSwap, etc.) — so calling this mid-swap or
 * standalone cannot corrupt an in-progress transaction.
 */
public function getFeeService(): FeeService
{
    return $this->feeService;
}

/**
     * Invoices a flat platform-owned fee to VouchMorph's own settlement
     * position, debited against the given source institution. Exposed
     * publicly so callers like CardService::activateCard() — which hold
     * a SwapService instance but not a HybridSettlementStrategy of their
     * own — can settle a fee the same way PoolCoordinator and the
     * standard swap flows already do for PLATFORM_FEE/VOUCHMORPH_FEE,
     * instead of debiting the customer and crediting nobody.
     */
   public function invoicePlatformFee(
        string $reference,
        string $sourceInstitution,
        string $feeType,
        float $amount,
        string $currency
    ) {
        $result = $this->settlement->invoiceFee(
            $reference,
            $sourceInstitution,
            $this->getParticipantId('VOUCHMORPH'),
            $feeType,
            $amount,
            $currency
        );

        // HybridSettlementStrategy::invoiceFee() isn't guaranteed to
        // return an array in every code path (confirmed: it returned a
        // plain string here) — normalize rather than declare a return
        // type this method doesn't actually control, which previously
        // turned a successful settlement call into a hard TypeError.
        if (!is_array($result)) {
            return ['success' => true, 'raw_result' => $result];
        }
        return $result;
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

/**
 * Same as getSourceAvailableBalance(), but also reports whether the
 * returned figure is a REAL queried balance or a synthetic ceiling
 * (card sources — see CardAcquirerBankClient::getBalance(), which has
 * no real balance to query). Use this instead of the float-only version
 * anywhere the distinction matters — e.g. CardService::hookSourcesToCard()'s
 * "no available balance" guard, where a synthetic 0 means "missing
 * max_single_auth_amount config," not "customer has no money."
 */
public function getSourceAvailableBalanceDetailed(array $source): array
{
    try {
        $adapter = $this->adapterFactory->getAdapter($source['institution']);

        $payload = [
            'action' => 'GET_BALANCE',
            'asset_type' => $source['asset_type'] ?? 'ACCOUNT',
            'source_identifier' => $source['identifier'],
            'requested_amount' => $source['authorized_amount'] ?? $source['amount'] ?? 0,
        ];

        $result = $adapter->getBalance($payload, [
            'source' => $source,
            'institution' => $source['institution']
        ]);

        $data = $result['data'] ?? [];
        return [
            'balance' => (float)($result['balance'] ?? $data['balance'] ?? 0),
            'is_synthetic' => (bool)($data['is_synthetic'] ?? false),
        ];
    } catch (Exception $e) {
        $this->logger->warning("Failed to get balance for source", [
            'source' => $source['institution'],
            'error' => $e->getMessage()
        ]);
        return ['balance' => 0, 'is_synthetic' => false];
    }
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
    // FIX: Self-service identity addition can ONLY use phone, email, 
    // or nickname. Government-issued IDs (national_id, voters_id, 
    // birth_certificate, drivers_license, passport) can NEVER be 
    // self-added — VouchMorph has no way to verify authenticity 
    // without a human agent/organization checking the physical 
    // document. These identities must be added by an approved agent 
    // via addVerifiedIdentityAsAgent().
    // ============================================================
    $selfServiceTypes = ['phone', 'email', 'nickname'];
    $governmentTypes = ['national_id', 'voters_id', 'birth_certificate', 'drivers_license', 'passport'];

    if (in_array($identityType, $governmentTypes, true)) {
        throw new RuntimeException(
            "Government-issued IDs can only be added with help from a VouchMorph agent or government official. " .
            "Please visit an agent to verify and add this identity to your account."
        );
    }

    if (!in_array($identityType, $selfServiceTypes, true)) {
        throw new RuntimeException("Unsupported identity type: {$identityType}");
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

    // Email path - self-service with email verification
    if ($identityType === 'email') {
        // For email, we could send a verification link or OTP via email
        // For now, we'll mark it as pending_review since we don't have 
        // an email OTP service in this method yet
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

        error_log("[SwapService] registerUserIdentity: email identity submitted, user_id={$userId}");

        return [
            'requires_otp' => false,
            'status' => 'pending_review',
            'message' => 'Email submitted for verification. Please check your email for a verification link.',
        ];
    }

    // Nickname path - self-service, no verification required
    if ($identityType === 'nickname') {
    // Nicknames are self-verified by definition - no OTP needed
    $stmt = $this->swapDB->prepare("
        INSERT INTO user_identities (
            user_id, identity_type, identity_value, status, verified, created_at
        ) VALUES (
            :user_id, :type, :value, 'verified', true, NOW()
        ) RETURNING id
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':type' => $identityType,
        ':value' => $identityValue,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $identityId = $row ? (int)$row['id'] : 0;

    return [
        'requires_otp' => false,
        'identity_id' => $identityId,
        'status' => 'verified',
        'verified' => true,
        'message' => 'Nickname registered and verified immediately.',
    ];
}

    // Document path - no self-service proof available yet.
    // This should never be reached since we block government types above,
    // but kept as a fallback for safety.
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


    /**
 * ============================================================
 * addVerifiedIdentityAsAgent()
 * ============================================================
 * Agent-assisted (or government-official-assisted) addition of a
 * government-issued identity to an EXISTING user's account, after
 * the agent has physically verified the document. This is the
 * counterpart to registerUserIdentity()'s self-service path, which
 * deliberately REFUSES national_id/voters_id/birth_certificate/
 * drivers_license/passport from the account owner directly — those
 * can only land here, going straight to 'verified' (no pending_review
 * queue), because a human has already checked the physical document.
 *
 * Same global-uniqueness rule as registerUserIdentity(): one
 * identity_type+identity_value can only ever belong to one user_id.
 */
public function addVerifiedIdentityAsAgent(
    int $targetUserId,
    string $identityType,
    string $identityValue,
    int $agentUserId
): array {
    $identityType = strtolower(trim($identityType));
    $identityValue = trim($identityValue);

    if ($identityValue === '') {
        throw new RuntimeException("identity_value is required");
    }

    if (!in_array($identityType, self::IDENTITY_PROFILE_GOVERNMENT_TYPES, true)) {
        throw new RuntimeException(
            "Invalid identity_type for agent verification. Must be one of: " .
            implode(', ', self::IDENTITY_PROFILE_GOVERNMENT_TYPES) .
            ". Phone, email, and nickname are self-service only."
        );
    }

    // Confirm the target account actually exists.
    $stmt = $this->swapDB->prepare("SELECT user_id FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $targetUserId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException("No VouchMorph account found for that user.");
    }

    // Global uniqueness check — same rule as registerUserIdentity().
    $stmt = $this->swapDB->prepare("
        SELECT id, user_id, status FROM user_identities
        WHERE identity_type = :type AND identity_value = :value
        LIMIT 1
    ");
    $stmt->execute([':type' => $identityType, ':value' => $identityValue]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && (int)$existing['user_id'] !== $targetUserId) {
        error_log("[SwapService] addVerifiedIdentityAsAgent: REJECTED - {$identityType}={$identityValue} already registered to a different user_id={$existing['user_id']}");
        throw new RuntimeException("This identity is already registered to another VouchMorph account.");
    }

    $identityId = null;

    if ($existing && (int)$existing['user_id'] === $targetUserId) {
        // Already theirs — upgrade to verified if it wasn't already (e.g.
        // was sitting in pending_review from a self-service submission),
        // otherwise this is just an idempotent no-op.
        $identityId = (int)$existing['id'];

        if ($existing['status'] !== 'verified') {
            $stmt = $this->swapDB->prepare("
                UPDATE user_identities
                SET status = 'verified', verified = true, otp_pin_hash = NULL, verified_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $identityId]);
            error_log("[SwapService] addVerifiedIdentityAsAgent: upgraded existing identity id={$identityId} to verified");
        }
    } else {
        // Brand new — insert straight to 'verified' since the agent has
        // already physically checked the document.
        $stmt = $this->swapDB->prepare("
            INSERT INTO user_identities (
                user_id, identity_type, identity_value, status, verified, verified_at, created_at
            ) VALUES (
                :user_id, :type, :value, 'verified', true, NOW(), NOW()
            ) RETURNING id
        ");
        $stmt->execute([
            ':user_id' => $targetUserId,
            ':type' => $identityType,
            ':value' => $identityValue,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $identityId = $row ? (int)$row['id'] : 0;
    }

    // Audit trail — who verified this, and for whom.
    $this->writeAuditLogEntry(
        'user_identities',
        (string)$identityId,
        'IDENTITY_VERIFIED_BY_AGENT',
        'identity',
        $targetUserId,
        'agent',
        $agentUserId,
        [
            'target_user_id' => $targetUserId,
            'identity_type' => $identityType,
            'identity_value' => $identityValue,
        ]
    );

    error_log("[SwapService] addVerifiedIdentityAsAgent: {$identityType}={$identityValue} verified for user_id={$targetUserId} by agent_user_id={$agentUserId}");

    return [
        'success' => true,
        'status' => 'verified',
        'verified' => true,
        'identity_id' => $identityId,
        'identity_type' => $identityType,
        'identity_value' => $identityValue,
        'target_user_id' => $targetUserId,
        'message' => "Identity verified and added to the account. It can now be used to receive identity swaps finalized with the account's transaction PIN.",
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
        SET status = 'verified', verified = true, otp_pin_hash = NULL, verified_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $attemptId]);

    error_log("[SwapService] verifyUserIdentityOtp: identity id={$attemptId} verified for user_id={$userId}");

    return [
        'status' => 'verified',
        'verified' => true,
        'message' => 'Identity verified. You can now finalize identity swaps sent to it with your transaction PIN.',
    ];
}
}
