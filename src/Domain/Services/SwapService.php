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
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\SMS\SmsNotificationService;
use Infrastructure\Mojaloop\IdempotencyService;
use Infrastructure\Crypto\SignatureVerifier;
use Infrastructure\Crypto\MessageSigner;
use Infrastructure\Crypto\CertificateManager;
use Infrastructure\Crypto\AggregateSigner; 


/**
 * SIGNED ATOMIC SWAP ORCHESTRATOR
 * 
 * CASHOUT FLOW (3 steps at destination):
 * 1. generate_token - Creates ATM/Agent cashout code
 * 2. verify_token - Validates code when user cashes out
 * 3. confirm_cashout - Confirms cashout completed, THEN debit source
 * 
 * DEPOSIT FLOW (1 step at destination):
 * 1. process_deposit - Direct deposit to account/wallet, THEN debit source
 * 
 * MULTI-SOURCE FLOW:
 * 1. Create Virtual Funding Pool
 * 2. Calculate contributions from multiple sources
 * 3. Verify all sources
 * 4. Place holds on all sources
 * 5. Execute destination action ONCE
 * 6. Debit all sources
 * 7. Settlement & invoicing
 * 
 * MATHEMATICAL MODEL:
 * - Amount_1 = Requested amount
 * - F1 = Total customer upfront fee
 * - Amount_2 = Amount_1 - F1 (net after fees)
 * - M = Banknote multiplier (from ATM notes)
 * - Amount_4 = M × floor(Amount_2 / M) (dispensable amount)
 * - Remainder_1 = Amount_2 - Amount_4 (stays at source)
 */
class SwapService
{
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
    
    private bool $inAtomicSwap = false;
    private ?string $currentSwapRef = null;
    private ?int $currentHoldId = null;
    private ?string $currentHoldReference = null;
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
        
        // ============================================================
        // LOAD COUNTRY CONFIGURATION USING LOADCOUNTRY
        // ============================================================
        $countryConfig = \Core\Config\LoadCountry::getConfig();
        
        $this->participants = $countryConfig['participants'] ?? [];
        $this->feesConfig = $countryConfig['fees'] ?? [];
        $this->atmNotes = $countryConfig['atm_notes'] ?? [];
        
        error_log("[SwapService] Loaded fees config from LoadCountry");
        error_log("[SwapService] Config keys: " . implode(', ', array_keys($this->feesConfig)));
        if (isset($this->feesConfig['CASHOUT'])) {
            error_log("[SwapService] CASHOUT fee_components found: " . json_encode(array_keys($this->feesConfig['CASHOUT']['fee_components'] ?? [])));
        }
        error_log("[SwapService] Participants loaded: " . count($this->participants));
        
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
        
        // ============================================================
        // MULTI-SOURCE ENABLED
        // ============================================================
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
    // SOURCE LINKING METHODS (Hooking)
    // ============================================================================

    /**
     * Initialize source linking - Step 1
     * - For ZURUBANK: Returns redirect URL
     * - For SACCUSSALIS: Sends OTP
     */
    public function initiateSourceLink(array $params): array
    {
        error_log("[SwapService] initiateSourceLink called");
        
        $institution = $params['institution'] ?? null;
        if (!$institution) {
            return ['success' => false, 'message' => 'Institution required'];
        }
        
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant);
        
        return $bankClient->initiateSourceLink($params);
    }

    /**
     * Verify source linking - Step 2
     * - For ZURUBANK: Exchange code for token
     * - For SACCUSSALIS: Verify OTP
     */
    public function verifySourceLink(array $params): array
    {
        error_log("[SwapService] verifySourceLink called");
        
        $institution = $params['institution'] ?? null;
        if (!$institution) {
            return ['success' => false, 'message' => 'Institution required'];
        }
        
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant);
        
        return $bankClient->verifySourceLink($params);
    }

    /**
     * Get all hooked sources for a user
     */
    public function getHookedSources(int $userId): array
    {
        error_log("[SwapService] getHookedSources called for user: {$userId}");
        
        $sql = "SELECT * FROM user_authorized_sources WHERE user_id = :user_id AND status = 'active'";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check token expiry and refresh if needed
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

    /**
     * Refresh a hooked source token
     */
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
        
        $participant = $this->getParticipant($source['institution']);
        $bankClient = new GenericBankClient($participant);
        
        $result = $bankClient->refreshSourceToken(['refresh_token' => $source['refresh_token']]);
        
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

    /**
     * Revoke a hooked source
     */
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
        
        $participant = $this->getParticipant($source['institution']);
        $bankClient = new GenericBankClient($participant);
        
        $bankClient->revokeSourceToken(['token' => $source['access_token']]);
        
        $sql = "UPDATE user_authorized_sources SET status = 'revoked' WHERE source_reference = :source_ref";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':source_ref' => $sourceReference]);
        
        return ['success' => true, 'message' => 'Source revoked successfully'];
    }

    /**
     * Check if token is expired
     */
    private function isTokenExpired(?string $expiresAt): bool
    {
        if (!$expiresAt) return true;
        return strtotime($expiresAt) < time();
    }

    // ============================================================================
    // EXECUTE SWAP WITH HOOKED SOURCE
    // ============================================================================

    /**
     * Execute a swap using a hooked source (no PIN required)
     */
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
        
        // Get the hooked source
        $sql = "SELECT * FROM user_authorized_sources WHERE source_reference = :source_ref AND user_id = :user_id AND status = 'active'";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':source_ref' => $sourceReference, ':user_id' => $userId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$source) {
            throw new RuntimeException("Hooked source not found or inactive");
        }
        
        // Check token expiry
        if ($this->isTokenExpired($source['token_expires_at'])) {
            $refreshed = $this->refreshHookedSource($userId, $sourceReference);
            $source['access_token'] = $refreshed['access_token'];
            $source['token_expires_at'] = $refreshed['expires_at'];
        }
        
        // Build payload with source token
        $swapPayload = $payload;
        $swapPayload['from_institution'] = $source['institution'];
        $swapPayload['asset_type'] = $source['asset_type'];
        $swapPayload['source_identifier'] = $source['identifier'];
        $swapPayload['access_token'] = $source['access_token'];
        $swapPayload['_is_hooked'] = true;
        
        // Update last used
        $sql = "UPDATE user_authorized_sources SET last_used_at = NOW() WHERE source_reference = :source_ref";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':source_ref' => $sourceReference]);
        
        // Execute the swap
        return $this->executeAtomicSwap($swapPayload);
    }

    // ============================================================================
    // MULTI-SOURCE WITH HOOKED SOURCES
    // ============================================================================

    /**
     * Execute multi-source swap with hooked sources
     */
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
            
            // Get hooked source
            $sql = "SELECT * FROM user_authorized_sources WHERE source_reference = :source_ref AND user_id = :user_id AND status = 'active'";
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':source_ref' => $sourceRef, ':user_id' => $userId]);
            $hookedSource = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hookedSource) {
                throw new RuntimeException("Hooked source not found: {$sourceRef}");
            }
            
            // Check token expiry
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
        
        // Build payload for multi-source swap
        $multiPayload = $payload;
        $multiPayload['sources'] = $resolvedSources;
        $multiPayload['amount'] = $totalAmount;
        $multiPayload['_is_multi_hooked'] = true;
        
        return $this->executeAtomicSwap($multiPayload);
    }

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
        
        error_log("[SwapService] Mathematical calculation with forex:");
        error_log("  Amount_1: {$amount} {$sourceCurrency}");
        error_log("  F1 (fee): {$totalFee} {$sourceCurrency}");
        error_log("  Amount_2: {$netAmountSourceCurrency} {$sourceCurrency}");
        if ($forexApplied) {
            error_log("  Exchange Rate: {$exchangeRate} ({$sourceCurrency} → {$destinationCurrency})");
            error_log("  Amount_3: {$netAmountDestCurrency} {$destinationCurrency}");
        }
        error_log("  M (multiplier): {$multiplier} {$destinationCurrency}");
        error_log("  Amount_4 (dispensable): {$dispensableAmount} {$destinationCurrency}");
        error_log("  Remainder_1: {$remainderBalance} {$destinationCurrency}");
        
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

    private function extractSourceIdentifier(array $payload): array
    {
        $sourceIdentifier = null;
        $sourceIdentifierType = null;
        
        // Check for hooked source first
        if (!empty($payload['_is_hooked']) && !empty($payload['source_reference'])) {
            // Source identifier already resolved, use it
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
        
        if (!empty($payload['source_identifier'])) {
            $sourceIdentifier = $payload['source_identifier'];
            $sourceIdentifierType = $payload['source_identifier_type'] ?? 'auto';
        } elseif (!empty($payload['source_phone'])) {
            $sourceIdentifier = $payload['source_phone'];
            $sourceIdentifierType = 'phone';
        } elseif (!empty($payload['source_wallet_phone'])) {
            $sourceIdentifier = $payload['source_wallet_phone'];
            $sourceIdentifierType = 'phone';
        } elseif (!empty($payload['wallet_phone'])) {
            $sourceIdentifier = $payload['wallet_phone'];
            $sourceIdentifierType = 'phone';
        } elseif (!empty($payload['phone'])) {
            $sourceIdentifier = $payload['phone'];
            $sourceIdentifierType = 'phone';
        } elseif (!empty($payload['source_national_id'])) {
            $sourceIdentifier = $payload['source_national_id'];
            $sourceIdentifierType = 'national_id';
        } elseif (!empty($payload['national_id'])) {
            $sourceIdentifier = $payload['national_id'];
            $sourceIdentifierType = 'national_id';
        } elseif (!empty($payload['source_email'])) {
            $sourceIdentifier = $payload['source_email'];
            $sourceIdentifierType = 'email';
        } elseif (!empty($payload['email'])) {
            $sourceIdentifier = $payload['email'];
            $sourceIdentifierType = 'email';
        }
        
        return [
            'identifier' => $sourceIdentifier,
            'type' => $sourceIdentifierType,
            'has_value' => !empty($sourceIdentifier),
            'is_hooked' => !empty($payload['_is_hooked'])
        ];
    }

    private function extractDestinationIdentifier(array $payload): array
    {
        $destinationIdentifier = null;
        $destinationIdentifierType = null;
        
        if (!empty($payload['destination_identifier'])) {
            $destinationIdentifier = $payload['destination_identifier'];
            $destinationIdentifierType = $payload['destination_identifier_type'] ?? 'auto';
        } elseif (!empty($payload['destination_account'])) {
            $destinationIdentifier = $payload['destination_account'];
            $destinationIdentifierType = 'account';
        } elseif (!empty($payload['destination_phone'])) {
            $destinationIdentifier = $payload['destination_phone'];
            $destinationIdentifierType = 'phone';
        } elseif (!empty($payload['destination_national_id'])) {
            $destinationIdentifier = $payload['destination_national_id'];
            $destinationIdentifierType = 'national_id';
        } elseif (!empty($payload['destination_email'])) {
            $destinationIdentifier = $payload['destination_email'];
            $destinationIdentifierType = 'email';
        } elseif (!empty($payload['beneficiary_account'])) {
            $destinationIdentifier = $payload['beneficiary_account'];
            $destinationIdentifierType = 'account';
        } elseif (!empty($payload['beneficiary_phone'])) {
            $destinationIdentifier = $payload['beneficiary_phone'];
            $destinationIdentifierType = 'phone';
        }
        
        return [
            'identifier' => $destinationIdentifier,
            'type' => $destinationIdentifierType,
            'has_value' => !empty($destinationIdentifier)
        ];
    }

    private function extractBeneficiaryPhone(array $payload): ?string
    {
        return $payload['beneficiary_phone'] ?? 
               $payload['beneficiary_identifier'] ?? 
               $payload['client_phone'] ?? 
               null;
    }

    public function executeAtomicSwap(array $payload): array
    {
        error_log("[SwapService] executeAtomicSwap called");
        error_log("[SwapService] Payload keys: " . implode(', ', array_keys($payload)));
        error_log("[SwapService] swap_type: " . ($payload['swap_type'] ?? 'NOT SET'));
        error_log("[SwapService] has sources: " . (isset($payload['sources']) ? 'YES (' . count($payload['sources']) . ')' : 'NO'));
        
        if (isset($payload['original_payload'])) {
            error_log("[SwapService] Signed envelope detected, extracting original_payload");
            $this->signedPayloads['envelope'] = [
                'signature' => $payload['signature'] ?? null,
                'timestamp' => $payload['timestamp'] ?? null
            ];
            $payload = $payload['original_payload'];
        }
        
        $sourceInst = $payload['from_institution'] ?? $payload['source_institution'] ?? null;
        $destInst = $payload['to_institution'] ?? $payload['destination_institution'] ?? null;
        $swapType = $payload['swap_type'] ?? 'STANDARD';
        
        $isMultiSource = isset($payload['sources']) && is_array($payload['sources']) && count($payload['sources']) > 1;
        
        if ($isMultiSource) {
            $swapType = 'MULTI_SOURCE';
            error_log("[SwapService] MULTI-SOURCE DETECTED: " . count($payload['sources']) . " sources");
            
            foreach ($payload['sources'] as $idx => $source) {
                $isHooked = isset($source['is_hooked']) && $source['is_hooked'] === true;
                error_log("[SwapService] Source " . ($idx + 1) . ": " . ($source['institution'] ?? 'unknown') . 
                    " - " . ($source['amount'] ?? 0) . " " . ($source['currency'] ?? 'BWP') .
                    ($isHooked ? " [HOOKED]" : ""));
            }
        }
        
        if (empty($sourceInst) && !$isMultiSource) {
            throw new RuntimeException("Missing source institution (from_institution or source_institution)");
        }
        if (empty($destInst)) {
            throw new RuntimeException("Missing destination institution (to_institution or destination_institution)");
        }
        
        error_log("[SwapService] Source: " . ($sourceInst ?? 'MULTI_SOURCE') . ", Dest: {$destInst}, Type: {$swapType}");
        
        if ($swapType === 'CASHOUT') {
            $amount = (float)($payload['amount'] ?? 0);
            $currency = $payload['currency'] ?? $this->config['currency'] ?? 'BWP';
            $validation = $this->validateCashoutAmount($amount, $currency);
            
            if (!$validation['is_exact']) {
                $this->logger->warning("Cashout amount cannot be dispensed exactly", $validation);
                $payload['_cashout_validation'] = $validation;
            }
            
            if ($validation['dispensable_amount'] != $amount) {
                $payload['original_requested_amount'] = $amount;
                $payload['amount'] = $validation['dispensable_amount'];
                $payload['remainder_balance'] = $validation['remainder_balance'];
                $payload['note_breakdown'] = $validation['note_breakdown'];
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
                'CASHOUT' => $this->executeSignedCashout($payload),
                'DEPOSIT' => $this->executeSignedDeposit($payload),
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

    // ============================================================
    // EXECUTE SIGNED CASHOUT - GENERATE CODE ONLY (NO DEBIT)
    // ============================================================

    private function executeSignedCashout(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedCashout START (CODE GENERATION ONLY) =====");
        
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        $deliveryMethod = strtoupper($payload['delivery_method'] ?? 'ATM');
        
        // Check if using hooked source
        $isHooked = isset($payload['_is_hooked']) && $payload['_is_hooked'] === true;
        if ($isHooked) {
            error_log("[SwapService] Using HOOKED source: " . ($payload['source_reference'] ?? 'unknown'));
        }
        
        if (isset($payload['_cashout_validation'])) {
            $this->feeCalculationDetails['cashout_validation'] = $payload['_cashout_validation'];
        }
        if (isset($payload['note_breakdown'])) {
            $this->feeCalculationDetails['note_breakdown'] = $payload['note_breakdown'];
        }
        
        // STEP 1: VERIFY ASSET
        error_log("[SwapService] STEP 1: Verifying asset with source institution: {$sourceInstitution}");
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
        
        // STEP 2: PLACE HOLD
        error_log("[SwapService] STEP 2: Placing hold on asset at source: {$sourceInstitution}");
        $holdResult = $this->executeStep('PLACE_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
            return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        });
        
        if (!($holdResult['hold_placed'] ?? false)) {
            $errorMessage = $holdResult['message'] ?? 'Failed to place hold';
            error_log("[SwapService] HOLD FAILED: {$errorMessage}");
            throw new RuntimeException("Hold failed: {$errorMessage}");
        }
        
        error_log("[SwapService] Hold placed successfully");
        
        $this->signedPayloads['hold'] = [
            'payload' => $holdResult['original_payload'],
            'signature' => $holdResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $holdResult['timestamp'],
            'is_hooked' => $isHooked
        ];
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? $holdResult['data']['hold_reference'] ?? null;
        
        // STEP 3: CALCULATE FEES
        error_log("[SwapService] STEP 3: Calculating fees with mathematical model");
        $feeBreakdown = $this->calculateFeesWithDetails('CASHOUT', $amount, $payload);
        $amountToSend = $feeBreakdown['dispensable_amount'];
        $remainderAtSource = $feeBreakdown['remainder_balance'];
        $netAmount = $feeBreakdown['net_amount'];
        
        error_log("[SwapService] Mathematical breakdown:");
        error_log("  Amount_1 (requested): {$amount}");
        error_log("  F1 (total fee): {$feeBreakdown['total_fee']}");
        error_log("  Amount_2 (net after fees): {$netAmount}");
        error_log("  Amount_4 (dispensable): {$amountToSend}");
        error_log("  Remainder_1 (stays at source): {$remainderAtSource}");
        
        $currency = $payload['currency'] ?? 'BWP';

        $notes = $this->atmNotes[$currency] ?? [];

        if (empty($notes)) {
            throw new RuntimeException(
                "No ATM denominations configured for {$currency}"
            );
        }

        $lowestDenomination = min($notes);

        if ($amountToSend > 0 && $amountToSend < $lowestDenomination) {
            $deliveryMethod = 'AGENT';
            $amountToSend = $netAmount;
            $remainderAtSource = 0;
            error_log(
                "[SwapService] Amount below ATM minimum denomination. "
                . "Switching to AGENT cashout: {$amountToSend}"
            );
        }

        if ($amountToSend <= 0) {
            throw new RuntimeException(
                "Amount after fees ({$netAmount} {$currency}) is too small to deliver."
            );
        }
        
        // STEP 4: GENERATE CODE AT DESTINATION
        error_log("[SwapService] STEP 4: Generating cashout code at DESTINATION: {$destinationInstitution} for amount: {$amountToSend}");
        
        $generateResult = $this->generateCashoutToken($payload, $destinationInstitution, $amountToSend);
        
        if (!($generateResult['success'] ?? false)) {
            throw new RuntimeException("Code generation failed: " . ($generateResult['message'] ?? 'Unknown error'));
        }
        
        if (empty($generateResult['atm_pin']) && empty($generateResult['voucher_number'])) {
            throw new RuntimeException("Destination failed: No code generated");
        }
        
        error_log("[SwapService] Cashout code generated successfully");
        
        // STEP 5: STORE IN CASHOUT_AUTHORIZATIONS TABLE
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
        
        $result = [
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
            'is_hooked' => $isHooked
        ];
        
        error_log("[SwapService] ===== executeSignedCashout SUCCESS (pending cashout) =====");
        
        return $result;
    }

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
            
            error_log("[SwapService] Cashout authorization stored: auth_id={$authId}, swap_ref={$swapReference}");
            
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
        
        $participant = $this->getParticipant($destinationInstitution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $verifyPayload = [
            'reference' => $payload['reference'] ?? $this->generateReference(),
            'code' => $code,
            'swap_code' => $swapCode,
            'action' => 'VERIFY_TOKEN'
        ];
        
        $result = $bankClient->verifyToken($verifyPayload);
        
        if (!$result['success']) {
            throw new RuntimeException("Token verification failed: " . ($result['curl_error'] ?? 'Unknown error'));
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'status' => 'verified',
            'verified' => $data['verified'] ?? false,
            'amount' => $data['amount'] ?? 0,
            'message' => $data['message'] ?? 'Code verified successfully'
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
        
        $authorization = $this->getCashoutAuthorization($swapReference, $authId);
        
        if (!$authorization) {
            throw new RuntimeException("No pending cashout authorization found");
        }
        
        $sourceInstitution = $authorization['source_institution'];
        $amountToSend = (float)$authorization['amount'];
        $feeAmount = (float)$authorization['fee_amount'];
        
        $participant = $this->getParticipant($destinationInstitution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $confirmPayload = [
            'reference' => $swapReference,
            'auth_id' => $authId,
            'code' => $code,
            'swap_code' => $authorization['swap_code'],
            'amount' => $amountToSend,
            'action' => 'CONFIRM_CASHOUT'
        ];
        
        $confirmResult = $bankClient->confirmCashout($confirmPayload);
        
        if (!$confirmResult['success']) {
            throw new RuntimeException("Cashout confirmation failed: " . ($confirmResult['curl_error'] ?? 'Unknown error'));
        }
        
        $data = $confirmResult['data'] ?? [];
        
        if (!($data['confirmed'] ?? false)) {
            throw new RuntimeException("Cashout not confirmed by destination institution");
        }
        
        error_log("[SwapService] Cashout confirmed, debiting source: {$sourceInstitution} for {$amountToSend}");
        
        $debitPayload = [
            'reference' => $swapReference,
            'hold_reference' => $authorization['swap_reference'],
            'amount' => $amountToSend + $feeAmount,
            'reason' => 'Cashout completed successfully'
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

    // ============================================================
    // EXECUTE SIGNED DEPOSIT
    // ============================================================

    private function executeSignedDeposit(array $payload): array
    {
        error_log("[SwapService] ===== executeSignedDeposit START =====");
        
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $destinationInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        $destinationIdentifier = $this->extractDestinationIdentifier($payload);
        
        $isHooked = isset($payload['_is_hooked']) && $payload['_is_hooked'] === true;
        if ($isHooked) {
            error_log("[SwapService] Using HOOKED source for deposit");
        }
        
        error_log("[SwapService] STEP 1: Verifying asset with source institution: {$sourceInstitution}");
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
        
        error_log("[SwapService] STEP 2: Verifying destination account at: {$destinationInstitution}");
        
        if (empty($destinationIdentifier['identifier'])) {
            throw new RuntimeException("Destination identifier is required for deposit");
        }
        
        $accountVerification = $this->executeStep('VERIFY_ACCOUNT', function() use ($payload, $destinationInstitution, $destinationIdentifier) {
            return $this->verifyAccount($payload, $destinationInstitution, $destinationIdentifier);
        });
        
        if (!($accountVerification['verified'] ?? false)) {
            throw new RuntimeException("Destination account verification failed: " . ($accountVerification['message'] ?? 'Account not found'));
        }
        
        error_log("[SwapService] STEP 3: Calculating fees");
        $feeBreakdown = $this->calculateFeesWithDetails('DEPOSIT', $amount, $payload);
        $netAmount = $feeBreakdown['net_amount'] ?? $amount;
        
        error_log("[SwapService] STEP 4: Placing hold on source: {$sourceInstitution}");
        $holdResult = $this->executeStep('PLACE_HOLD_SIGNED', function() use ($payload, $sourceInstitution, $verificationResult) {
            return $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        });
        
        if (!($holdResult['hold_placed'] ?? false)) {
            throw new RuntimeException("Hold failed: " . ($holdResult['message'] ?? 'Unknown error'));
        }
        
        $this->signedPayloads['hold'] = [
            'payload' => $holdResult['original_payload'],
            'signature' => $holdResult['signature'],
            'source' => $sourceInstitution,
            'timestamp' => $holdResult['timestamp'],
            'is_hooked' => $isHooked
        ];
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? null;
        
        error_log("[SwapService] STEP 5: Processing deposit at DESTINATION: {$destinationInstitution} for amount: {$netAmount}");
        
        $depositResult = $this->executeStep('PROCESS_DEPOSIT_WITH_PROOF', function() use ($payload, $destinationInstitution, $netAmount, $accountVerification) {
            $depositPayload = $payload;
            $depositPayload['amount'] = $netAmount;
            $depositPayload['account_verification'] = $accountVerification;
            return $this->processDepositWithProof($depositPayload, $destinationInstitution, $netAmount);
        });
        
        if (!($depositResult['success'] ?? false)) {
            throw new RuntimeException("Deposit failed: " . ($depositResult['message'] ?? 'Unknown error'));
        }
        
        error_log("[SwapService] STEP 6: Debiting source {$sourceInstitution} for amount: {$amount}");
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
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'original_amount' => $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'fee_calculation_details' => $this->feeCalculationDetails,
            'deposit_reference' => $depositResult['transaction_reference'] ?? null,
            'destination_account' => $destinationIdentifier['identifier'],
            'settlement' => $settlementResult,
            'signature_chain' => $this->signedPayloads,
            'is_hooked' => $isHooked
        ];
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    private function verifyAccount(array $payload, string $institution, array $destinationIdentifier): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $verifyPayload = [
            'action' => 'VERIFY_ACCOUNT',
            'reference' => $this->currentSwapRef,
            'account_identifier' => $destinationIdentifier['identifier'],
            'identifier_type' => $destinationIdentifier['type'],
            'requester' => 'VOUCHMORPH',
            'timestamp' => time()
        ];
        
        $result = $bankClient->verifyAccount($verifyPayload);
        
        if (!$result['success']) {
            return ['verified' => false, 'message' => $result['curl_error'] ?? 'Account verification failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'verified' => $data['verified'] ?? false,
            'message' => $data['message'] ?? null,
            'account_name' => $data['account_name'] ?? null
        ];
    }

    private function generateCashoutToken(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $beneficiaryPhone = $this->extractBeneficiaryPhone($payload);
        
        $tokenPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'hold_reference' => $this->currentHoldReference,
            'action' => 'GENERATE_TOKEN',
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null,
            'beneficiary_phone' => $beneficiaryPhone
        ];
        
        if (isset($payload['note_breakdown'])) {
            $tokenPayload['note_breakdown'] = $payload['note_breakdown'];
        }
        
        $result = $bankClient->generateToken($tokenPayload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Token generation failed'];
        }
        
        $data = $result['data'] ?? [];
        
        if (empty($data['atm_pin']) && empty($data['voucher_number'])) {
            return ['success' => false, 'message' => 'No code generated'];
        }
        
        return [
            'success' => true,
            'swap_code' => $data['swap_code'] ?? $data['voucher_number'],
            'atm_pin' => $data['atm_pin'] ?? null,
            'voucher_number' => $data['voucher_number'] ?? null,
            'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'))
        ];
    }

    // ============================================================
    // SIGNED INSTITUTION COMMUNICATION METHODS
    // ============================================================

    private function forwardPin(array $originalPayload, array &$targetPayload): void
    {
        // If using hooked source, we might not need PIN
        $isHooked = isset($originalPayload['_is_hooked']) && $originalPayload['_is_hooked'] === true;
        
        if ($isHooked) {
            error_log("[SwapService] Using hooked source - skipping PIN check");
            // Hooked sources use access_token, not PIN
            if (!empty($originalPayload['access_token'])) {
                $targetPayload['access_token'] = $originalPayload['access_token'];
                error_log("[SwapService] Forwarded access_token from hooked source");
            }
            if (!empty($originalPayload['source_reference'])) {
                $targetPayload['source_reference'] = $originalPayload['source_reference'];
                error_log("[SwapService] Forwarded source_reference from hooked source");
            }
            return;
        }
        
        // Regular PIN-based flow
        if (!empty($originalPayload['wallet_pin'])) {
            $targetPayload['wallet_pin'] = $originalPayload['wallet_pin'];
            $targetPayload['pin'] = $originalPayload['wallet_pin'];
            error_log("[SwapService] Forwarded wallet_pin to payload");
        } elseif (!empty($originalPayload['pin'])) {
            $targetPayload['pin'] = $originalPayload['pin'];
            $targetPayload['wallet_pin'] = $originalPayload['pin'];
            error_log("[SwapService] Forwarded pin to payload");
        }
        
        if (!empty($originalPayload['asset_fields']) && is_array($originalPayload['asset_fields'])) {
            $targetPayload['asset_fields'] = $originalPayload['asset_fields'];
            error_log("[SwapService] Forwarded asset_fields to payload");
        }
    }

    private function verifyAssetSigned(array $payload, string $institution): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
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
            'requester' => 'VOUCHMORPH'
        ];
        
        $this->forwardPin($payload, $verifyPayload);
        
        if ($sourceId['has_value']) {
            $verifyPayload['source_identifier'] = $sourceId['identifier'];
            $verifyPayload['source_identifier_type'] = $sourceId['type'];
        }
        
        error_log("[SwapService] verifyAssetSigned payload has PIN: " . 
            (isset($verifyPayload['wallet_pin']) || isset($verifyPayload['pin']) ? 'YES' : 'NO'));
        
        $result = $bankClient->verifyAssetSigned($verifyPayload);
        
        if (!$result['success']) {
            return ['verified' => false, 'message' => 'Unable to reach source institution'];
        }
        
        $data = $result['data'] ?? [];
        $verified = $data['verified'] ?? false;
        
        if ($verified !== true) {
            return ['verified' => false, 'message' => $data['message'] ?? 'Asset not available'];
        }
        
        return [
            'verified' => true,
            'message' => $data['message'] ?? 'Asset verified',
            'asset_id' => $data['asset_id'] ?? null,
            'original_payload' => $data['payload'] ?? null,
            'signature' => $data['signature'] ?? null,
            'certificate' => $data['certificate'] ?? null,
            'timestamp' => $data['timestamp'] ?? $timestamp
        ];
    }

    private function placeHoldSigned(array $payload, string $institution, array $verificationResult): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $assetType = strtoupper($payload['asset_type'] ?? 'ACCOUNT');
        $timestamp = time();
        $sourceId = $this->extractSourceIdentifier($payload);
        
        $holdPayload = [
            'action' => 'PLACE_HOLD',
            'reference' => $this->currentSwapRef,
            'asset_type' => $assetType,
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? $this->config['currency'] ?? 'BWP',
            'hold_reason' => $payload['hold_reason'] ?? 'PENDING_SWAP',
            'destination_institution' => $payload['to_institution'] ?? $payload['destination_institution'] ?? null,
            'expiry' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'timestamp' => $timestamp
        ];
        
        $this->forwardPin($payload, $holdPayload);
        
        if ($sourceId['has_value']) {
            $holdPayload['source_identifier'] = $sourceId['identifier'];
            $holdPayload['source_identifier_type'] = $sourceId['type'];
        }
        
        if (isset($verificationResult['asset_id'])) {
            $holdPayload['asset_id'] = $verificationResult['asset_id'];
        }
        
        error_log("[SwapService] placeHoldSigned payload has PIN: " . 
            (isset($holdPayload['wallet_pin']) || isset($holdPayload['pin']) ? 'YES' : 'NO'));
        
        $result = $bankClient->placeHoldSigned($holdPayload);
        
        if (!$result['success']) {
            return ['hold_placed' => false, 'message' => $result['curl_error'] ?? 'Hold request failed'];
        }
        
        $data = $result['data'] ?? [];
        
        $holdId = $this->createLocalHold($payload, $institution, $data['hold_reference'] ?? null);
        $this->currentHoldId = $holdId;
        
        return [
            'hold_placed' => true,
            'hold_reference' => $data['hold_reference'] ?? null,
            'local_hold_id' => $holdId,
            'message' => $data['message'] ?? 'Hold placed successfully',
            'original_payload' => $data['payload'] ?? null,
            'signature' => $data['signature'] ?? null,
            'certificate' => $data['certificate'] ?? null,
            'timestamp' => $data['timestamp'] ?? $timestamp
        ];
    }

    private function processDepositWithProof(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $destId = $this->extractDestinationIdentifier($payload);
        
        $depositPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'source_details' => $payload['source_details'] ?? [],
            'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null
        ];
        
        $this->forwardPin($payload, $depositPayload);
        
        if ($destId['has_value']) {
            $depositPayload['destination_identifier'] = $destId['identifier'];
            $depositPayload['destination_identifier_type'] = $destId['type'];
        }
        
        $result = $bankClient->processDepositWithProof($depositPayload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Deposit failed'];
        }
        
        $data = $result['data'] ?? [];
        
        return [
            'success' => true,
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'message' => $data['message'] ?? 'Deposit successful'
        ];
    }

    private function debitSource(array $payload, string $institution): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $debitPayload = [
            'reference' => $payload['reference'] ?? $this->currentSwapRef,
            'hold_reference' => $payload['hold_reference'] ?? $this->currentHoldReference,
            'amount' => $payload['amount'] ?? 0,
            'reason' => $payload['reason'] ?? 'Swap completed successfully'
        ];
        
        $this->forwardPin($payload, $debitPayload);
        
        $result = $bankClient->debitFunds($debitPayload);
        
        if (!$result['success']) {
            return ['debited' => false, 'message' => $result['curl_error'] ?? 'Debit failed'];
        }
        
        return [
            'debited' => true,
            'transaction_reference' => $result['data']['transaction_reference'] ?? null,
            'message' => $result['data']['message'] ?? 'Debit successful'
        ];
    }

    private function executeSignedStandardSwap(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceInstitution = $payload['from_institution'] ?? $payload['source_institution'];
        $destInstitution = $payload['to_institution'] ?? $payload['destination_institution'];
        
        $verificationResult = $this->verifyAssetSigned($payload, $sourceInstitution);
        if (!($verificationResult['verified'] ?? false)) {
            throw new RuntimeException("Asset verification failed");
        }
        
        $holdResult = $this->placeHoldSigned($payload, $sourceInstitution, $verificationResult);
        if (!($holdResult['hold_placed'] ?? false)) {
            throw new RuntimeException("Hold failed");
        }
        
        $this->currentHoldReference = $holdResult['hold_reference'] ?? null;
        
        $feeBreakdown = $this->calculateFeesWithDetails('SWAP', $amount, $payload);
        $netAmount = $feeBreakdown['net_amount'] ?? $amount;
        
        $destinationResult = $this->processDestinationWithProof($payload, $destInstitution, $netAmount);
        if (!($destinationResult['success'] ?? false)) {
            throw new RuntimeException("Destination processing failed");
        }
        
        $debitResult = $this->debitSource($payload, $sourceInstitution);
        if (!($debitResult['debited'] ?? false)) {
            throw new RuntimeException("Debit failed");
        }
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $netAmount,
            'fee' => $feeBreakdown['total_fee'] ?? 0
        ];
    }

    private function processDestinationWithProof(array $payload, string $institution, float $amount): array
    {
        $participant = $this->getParticipant($institution);
        $bankClient = new GenericBankClient($participant, $payload);
        
        $destId = $this->extractDestinationIdentifier($payload);
        
        $transferPayload = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_type' => $payload['destination_type'] ?? 'ACCOUNT',
            'action' => 'PROCESS_TRANSFER_WITH_PROOF',
            'source_verification' => $this->signedPayloads['verification'] ?? null,
            'source_hold' => $this->signedPayloads['hold'] ?? null
        ];
        
        $this->forwardPin($payload, $transferPayload);
        
        if ($destId['has_value']) {
            $transferPayload['destination_identifier'] = $destId['identifier'];
            $transferPayload['destination_identifier_type'] = $destId['type'];
        }
        
        $result = $bankClient->transferWithProof($transferPayload);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['curl_error'] ?? 'Processing failed'];
        }
        
        return ['success' => true];
    }

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

    private function executeCardIssuance(array $payload): array
    {
        if (!$this->cardService) {
            throw new RuntimeException("Card service not initialized");
        }
        return $this->cardService->issueCard($payload);
    }

    // ============================================================
    // LOCAL DATABASE OPERATIONS
    // ============================================================

    private function createLocalHold(array $payload, string $institution, ?string $externalHoldRef): int
    {
        $sourceId = $this->extractSourceIdentifier($payload);
        
        $sourceDetails = [
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
        
        $validStatuses = ['ACTIVE', 'HELD', 'PENDING_CASHOUT', 'DEBITED', 'RELEASED', 'CANCELLED', 'FAILED'];
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

    // ============================================================
    // ATOMIC BOUNDARY METHODS
    // ============================================================

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
        if ($this->currentHoldId) {
            $this->updateHoldStatus($this->currentHoldId, 'RELEASED');
        }
        
        $this->swapDB->rollBack();
        
        $result = [
            'status' => 'rolled_back',
            'reference' => $this->currentSwapRef,
            'reason' => $reason
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

    // ============================================================
    // HELPER METHODS
    // ============================================================

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

    // ============================================================
    // PUBLIC METHODS
    // ============================================================

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
                return $participant['id'] ?? 0;
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
            $participant = $this->getParticipant($source['institution']);
            $bankClient = new GenericBankClient($participant);
            
            $payload = [
                'action' => 'GET_BALANCE',
                'asset_type' => $source['asset_type'] ?? 'ACCOUNT',
                'source_identifier' => $source['identifier']
            ];
            
            $result = $bankClient->getBalance($payload);
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
