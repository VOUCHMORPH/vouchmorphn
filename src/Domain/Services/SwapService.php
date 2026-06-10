<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\FeeService;
use Domain\Services\ForexService;
use Domain\Services\CardService;
use Domain\Services\ContributionCalculator;
use Domain\Services\MultiSourceFeeCalculator;
use Domain\Services\MultiSourceSwapExecutor;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\SMS\SmsNotificationService;
use Infrastructure\SMS\SmsGatewayClient;
use Infrastructure\Mojaloop\IdempotencyService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * ATOMIC SWAP ORCHESTRATOR
 * 
 * Delegates to specialized services:
 * - FeeService: Fee calculation and collection
 * - ForexService: Exchange rate management
 * - CardService: Card issuance and management
 * - MultiSourceSwapExecutor: Multi-source contribution swaps
 * - HybridSettlementStrategy: Settlement recording
 */
class SwapService
{
    private PDO $swapDB;
    private array $config;
    private array $participants;
    private array $endpoints;
    private array $assets;
    private array $flows;
    private string $countryCode;
    private array $feesConfig = [];
    private array $atmNotes = [];
    
    // Service dependencies
    private HybridSettlementStrategy $settlement;
    private FeeService $feeService;
    private ForexService $forexService;
    private ?CardService $cardService = null;
    private ?SmsNotificationService $smsService = null;
    private ?ContributionCalculator $contributionCalculator = null;
    private ?MultiSourceFeeCalculator $multiSourceFeeCalculator = null;
    private ?MultiSourceSwapExecutor $multiSourceExecutor = null;
    private ?LoggerInterface $logger = null;
    
    // Atomic state
    private bool $inAtomicSwap = false;
    private ?string $currentSwapRef = null;
    private ?int $currentHoldId = null;
    private array $executedSteps = [];
    private array $stepResults = [];

    public function __construct(
        PDO $swapDB, 
        array $config, 
        string $country,
        ?LoggerInterface $logger = null
    ) {
        $this->swapDB = $swapDB;
        $this->config = $config;
        $this->countryCode = strtoupper($country);
        $this->logger = $logger ?? new NullLogger();
        
        // Load configuration
        $this->loadConfiguration($country);
        
        // Initialize services
        $this->settlement = new HybridSettlementStrategy($this->swapDB);
        $this->feeService = new FeeService($this->feesConfig, $this->config['currency'] ?? 'BWP');
        $this->forexService = new ForexService($this->swapDB, $this->config, $this->participants, $this->feeService);
        
        // Initialize card service if configured
        $vouchmorphConfig = $this->participants['vouchmorph'] ?? [];
        if (!empty($vouchmorphConfig)) {
            $this->cardService = new CardService($this->swapDB, $this->countryCode, $vouchmorphConfig);
        }
        
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
        $this->initSmsService();
        
        $this->logger->info("SwapService initialized", ['country' => $country]);
    }

    /**
     * Execute swap with ATOMIC guarantees
     * Delegates to specialized services for business logic
     */
    public function executeAtomicSwap(array $payload): array
    {
        $ref = $payload['reference'] ?? $this->generateReference();
        $idempotencyKey = $payload['idempotency_key'] ?? $payload['idempotencyKey'] ?? null;
        
        // Idempotency check FIRST (no transaction yet)
        if ($idempotencyKey) {
            $cached = $this->checkIdempotency($idempotencyKey);
            if ($cached) {
                $this->logger->info("Idempotency cache hit", ['key' => $idempotencyKey]);
                return $cached;
            }
        }
        
        // Determine swap type
        $isMultiSource = $this->isMultiSourceContribution($payload);
        $swapType = $payload['swap_type'] ?? ($isMultiSource ? 'MULTI_SOURCE' : 'STANDARD');
        
        // BEGIN ATOMIC BOUNDARY
        $this->beginAtomicSwap($ref);
        
        try {
            $result = match($swapType) {
                'MULTI_SOURCE' => $this->executeMultiSourceSwap($payload),
                'CASHOUT' => $this->executeCashout($payload),
                'DEPOSIT' => $this->executeDeposit($payload),
                'CARD_ISSUE' => $this->executeCardIssuance($payload),
                default => $this->executeStandardSwap($payload),
            };
            
            // Commit the atomic transaction
            $commitResult = $this->commitAtomicSwap();
            $result = array_merge($result, ['atomic_commit' => $commitResult]);
            
            // Store idempotency result
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

    /**
     * Execute standard swap using FeeService and ForexService
     */
    private function executeStandardSwap(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceCurrency = $payload['source_currency'] ?? $payload['from_currency'] ?? 'BWP';
        $destCurrency = $payload['destination_currency'] ?? $payload['to_currency'] ?? 'BWP';
        $sourceInstitution = $payload['source_institution'] ?? $payload['from_institution'];
        $destInstitution = $payload['destination_institution'] ?? $payload['to_institution'];
        
        // STEP 1: Create hold
        $holdId = $this->createHold($payload);
        $this->currentHoldId = $holdId;
        $this->recordStep('hold_created', ['hold_id' => $holdId]);
        
        // STEP 2: Get exchange rate from ForexService
        $fxRate = $this->executeStep('GET_EXCHANGE_RATE', function() use ($sourceCurrency, $destCurrency, $amount) {
            return $this->forexService->getRate($sourceCurrency, $destCurrency, $amount);
        });
        
        // STEP 3: Calculate fees using FeeService
        $feeBreakdown = $this->executeStep('CALCULATE_FEES', function() use ($payload, $amount) {
            $transactionType = $payload['transaction_type'] ?? 'SWAP';
            return $this->feeService->calculateFees($transactionType, $amount, $payload);
        });
        
        // STEP 4: Calculate net amount after fees
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        $destAmount = $netAmount * ($fxRate['rate'] ?? 1);
        
        $this->recordStep('amounts_calculated', [
            'gross' => $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'net' => $netAmount,
            'dest_amount' => $destAmount,
            'fx_rate' => $fxRate['rate'] ?? 1
        ]);
        
        // STEP 5: Process destination (external API)
        $destinationResult = $this->executeStep('PROCESS_DESTINATION', function() use ($payload, $destAmount, $destCurrency) {
            return $this->processDestination($payload, $destAmount, $destCurrency);
        });
        
        // STEP 6: Update hold status to DEBITED
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        // STEP 7: Record settlement using HybridSettlementStrategy
        $settlementResult = $this->executeStep('RECORD_SETTLEMENT', function() use ($payload, $destinationResult, $feeBreakdown) {
            return $this->settlement->recordSettlement($payload, $destinationResult, $feeBreakdown);
        });
        
        // STEP 8: Collect fees using FeeService
        $this->executeStep('COLLECT_FEES', function() use ($payload, $feeBreakdown) {
            return $this->feeService->collectFees($payload, $feeBreakdown);
        });
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'hold_id' => $this->currentHoldId,
            'amount' => $amount,
            'fee' => $feeBreakdown['total_fee'] ?? 0,
            'fx_rate' => $fxRate['rate'] ?? 1,
            'destination_amount' => $destAmount,
            'settlement' => $settlementResult
        ];
    }

    /**
     * Execute cashout using FeeService and ForexService
     */
    private function executeCashout(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $beneficiaryPhone = $payload['beneficiary_phone'] ?? $payload['client_phone'] ?? null;
        $sourceCurrency = $payload['source_currency'] ?? 'BWP';
        
        // STEP 1: Create hold
        $holdId = $this->createHold($payload);
        $this->currentHoldId = $holdId;
        
        // STEP 2: Calculate cashout fees using FeeService
        $feeBreakdown = $this->executeStep('CALCULATE_CASHOUT_FEES', function() use ($payload, $amount) {
            return $this->feeService->calculateFees('CASHOUT', $amount, $payload);
        });
        
        $netAmount = $amount - ($feeBreakdown['total_fee'] ?? 0);
        
        // STEP 3: Generate ATM token/voucher
        $tokenResult = $this->executeStep('GENERATE_TOKEN', function() use ($payload, $netAmount) {
            return $this->generateAtmToken($payload, $netAmount);
        });
        
        // STEP 4: Send SMS notification via SmsNotificationService
        if ($beneficiaryPhone && $this->smsService && isset($tokenResult['atm_pin'])) {
            $this->executeStep('SEND_SMS', function() use ($beneficiaryPhone, $tokenResult, $netAmount) {
                return $this->smsService->sendCashoutCode(
                    $beneficiaryPhone,
                    $tokenResult['atm_pin'],
                    $netAmount,
                    $tokenResult['voucher_number'] ?? null
                );
            });
        }
        
        // STEP 5: Update hold to DEBITED
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        // STEP 6: Collect fees
        $this->feeService->collectFees($payload, $feeBreakdown);
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'atm_code' => $tokenResult['atm_pin'] ?? null,
            'voucher_number' => $tokenResult['voucher_number'] ?? null,
            'amount' => $netAmount,
            'fee' => $feeBreakdown['total_fee'] ?? 0
        ];
    }

    /**
     * Execute deposit using ForexService
     */
    private function executeDeposit(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $sourceCurrency = $payload['source_currency'] ?? 'BWP';
        $destCurrency = $payload['destination_currency'] ?? 'BWP';
        
        // STEP 1: Create hold
        $holdId = $this->createHold($payload);
        $this->currentHoldId = $holdId;
        
        // STEP 2: Get exchange rate if currencies differ
        $fxRate = null;
        if ($sourceCurrency !== $destCurrency) {
            $fxRate = $this->executeStep('GET_EXCHANGE_RATE', function() use ($sourceCurrency, $destCurrency, $amount) {
                return $this->forexService->getRate($sourceCurrency, $destCurrency, $amount);
            });
        }
        
        // STEP 3: Process deposit
        $depositResult = $this->executeStep('PROCESS_DEPOSIT', function() use ($payload, $amount, $fxRate) {
            return $this->processDeposit($payload, $amount, $fxRate);
        });
        
        // STEP 4: Update hold
        $this->updateHoldStatus($this->currentHoldId, 'DEBITED');
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'fx_rate' => $fxRate['rate'] ?? 1
        ];
    }

    /**
     * Execute card issuance using CardService
     */
    private function executeCardIssuance(array $payload): array
    {
        if (!$this->cardService) {
            throw new RuntimeException("Card service not initialized");
        }
        
        $userId = $payload['user_id'] ?? null;
        
        // STEP 1: Verify eligibility via CardService
        $eligibilityResult = $this->executeStep('VERIFY_ELIGIBILITY', function() use ($userId) {
            return $this->cardService->checkEligibility($userId);
        });
        
        // STEP 2: Create card via CardService
        $cardResult = $this->executeStep('CREATE_CARD', function() use ($payload) {
            return $this->cardService->createCard($payload);
        });
        
        // STEP 3: Generate card details
        $cardDetails = $this->executeStep('GENERATE_CARD_DETAILS', function() use ($cardResult) {
            return $this->cardService->generateCardDetails($cardResult['card_id']);
        });
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'card_id' => $cardResult['card_id'] ?? null,
            'card_last_four' => $cardDetails['last_four'] ?? null
        ];
    }

    /**
     * Execute multi-source swap using MultiSourceSwapExecutor
     */
    private function executeMultiSourceSwap(array $payload): array
    {
        if (!$this->multiSourceExecutor) {
            throw new RuntimeException("Multi-source swap executor not initialized");
        }
        
        return $this->executeStep('MULTI_SOURCE_EXECUTION', function() use ($payload) {
            return $this->multiSourceExecutor->execute($payload);
        });
    }

    /**
     * Execute a step with automatic tracking
     */
    private function executeStep(string $stepName, callable $operation)
    {
        $startTime = microtime(true);
        $this->recordStep("START_{$stepName}");
        
        try {
            $result = $operation();
            $duration = (microtime(true) - $startTime) * 1000;
            $this->recordStep("SUCCESS_{$stepName}", ['duration_ms' => $duration, 'result' => $result]);
            $this->stepResults[$stepName] = $result;
            return $result;
        } catch (Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->recordStep("FAILED_{$stepName}", [
                'duration_ms' => $duration,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create hold in hold_transactions table
     */
    private function createHold(array $payload): int
    {
        $sql = "
            INSERT INTO hold_transactions (
                hold_reference,
                swap_reference,
                participant_name,
                asset_type,
                amount,
                currency,
                status,
                source_details,
                destination_institution,
                placed_at,
                created_at,
                updated_at
            ) VALUES (
                :hold_ref,
                :swap_ref,
                :participant_name,
                :asset_type,
                :amount,
                :currency,
                'ACTIVE',
                :source_details::jsonb,
                :destination,
                NOW(),
                NOW(),
                NOW()
            ) RETURNING hold_id
        ";
        
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':hold_ref' => 'HOLD_' . $this->currentSwapRef,
            ':swap_ref' => $this->currentSwapRef,
            ':participant_name' => $payload['source_institution'] ?? $payload['from_institution'] ?? 'UNKNOWN',
            ':asset_type' => $payload['asset_type'] ?? 'ACCOUNT',
            ':amount' => $payload['amount'] ?? 0,
            ':currency' => $payload['currency'] ?? 'BWP',
            ':source_details' => json_encode($payload['source_details'] ?? []),
            ':destination' => $payload['destination_institution'] ?? $payload['to_institution'] ?? 'UNKNOWN'
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$row['hold_id'];
    }

    /**
     * Update hold status
     */
    private function updateHoldStatus(int $holdId, string $status): void
    {
        $sql = "
            UPDATE hold_transactions 
            SET status = :status,
                debited_at = CASE WHEN :status = 'DEBITED' THEN NOW() ELSE debited_at END,
                released_at = CASE WHEN :status = 'RELEASED' THEN NOW() ELSE released_at END,
                updated_at = NOW()
            WHERE hold_id = :hold_id
        ";
        
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':hold_id' => $holdId
        ]);
    }

    /**
     * Begin atomic boundary - start PostgreSQL transaction
     */
    private function beginAtomicSwap(string $reference): void
    {
        if ($this->inAtomicSwap) {
            throw new RuntimeException("Already in atomic swap: {$this->currentSwapRef}");
        }
        
        $this->currentSwapRef = $reference;
        $this->inAtomicSwap = true;
        $this->executedSteps = [];
        $this->stepResults = [];
        
        // Start PostgreSQL database transaction
        $this->swapDB->beginTransaction();
        
        $this->auditLog('SWAP_START', ['reference' => $reference]);
        $this->logger->info("Atomic swap begun", ['reference' => $reference]);
    }

    /**
     * Commit atomic swap
     */
    private function commitAtomicSwap(): array
    {
        $this->swapDB->commit();
        
        $result = [
            'status' => 'committed',
            'reference' => $this->currentSwapRef,
            'hold_id' => $this->currentHoldId,
            'steps_completed' => count($this->executedSteps)
        ];
        
        $this->auditLog('SWAP_COMMIT', $result);
        $this->logger->info("Atomic swap committed", $result);
        
        $this->resetAtomicState();
        return $result;
    }

    /**
     * Rollback atomic swap
     */
    private function rollbackAtomicSwap(string $reason): array
    {
        $rollbackSteps = [];
        
        // Release hold if exists
        if ($this->currentHoldId) {
            try {
                $this->updateHoldStatus($this->currentHoldId, 'RELEASED');
                $rollbackSteps[] = 'hold_released';
            } catch (Exception $e) {
                $this->logger->error("Failed to release hold", ['hold_id' => $this->currentHoldId, 'error' => $e->getMessage()]);
                $rollbackSteps[] = 'hold_release_failed';
            }
        }
        
        // Rollback database transaction
        try {
            $this->swapDB->rollBack();
            $rollbackSteps[] = 'db_rolled_back';
        } catch (Exception $e) {
            $this->logger->critical("Database rollback failed!", ['error' => $e->getMessage()]);
            $rollbackSteps[] = 'db_rollback_failed';
        }
        
        $result = [
            'status' => 'rolled_back',
            'reference' => $this->currentSwapRef,
            'reason' => $reason,
            'rollback_steps' => $rollbackSteps
        ];
        
        $this->auditLog('SWAP_ROLLBACK', $result);
        $this->logger->warning("Atomic swap rolled back", $result);
        
        $this->resetAtomicState();
        return $result;
    }

    /**
     * Reset atomic state
     */
    private function resetAtomicState(): void
    {
        $this->inAtomicSwap = false;
        $this->currentSwapRef = null;
        $this->currentHoldId = null;
        $this->executedSteps = [];
        $this->stepResults = [];
    }

    /**
     * Record step for debugging
     */
    private function recordStep(string $step, ?array $data = null): void
    {
        $this->executedSteps[] = [
            'step' => $step,
            'timestamp' => microtime(true),
            'data' => $data
        ];
        
        $this->logger->debug("Step completed", [
            'swap_ref' => $this->currentSwapRef,
            'step' => $step,
            'step_number' => count($this->executedSteps)
        ]);
    }

    /**
     * Get last executed step
     */
    private function getLastStep(): string
    {
        if (empty($this->executedSteps)) {
            return 'none';
        }
        $last = end($this->executedSteps);
        return $last['step'];
    }

    /**
     * Audit log using audit_logs table
     */
    private function auditLog(string $action, array $data): void
    {
        try {
            $sql = "
                INSERT INTO audit_logs (
                    entity_type,
                    entity_id,
                    action,
                    category,
                    severity,
                    new_value,
                    performed_by_type,
                    performed_at
                ) VALUES (
                    'swap',
                    :reference,
                    :action,
                    'transaction',
                    'info',
                    :value::jsonb,
                    'system',
                    NOW()
                )
            ";
            
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':reference' => $this->currentSwapRef,
                ':action' => $action,
                ':value' => json_encode($data)
            ]);
        } catch (Exception $e) {
            $this->logger->error("Audit log failed", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check idempotency
     */
    private function checkIdempotency(string $key): ?array
    {
        try {
            if (class_exists('Infrastructure\Mojaloop\IdempotencyService')) {
                return IdempotencyService::check($this->swapDB, $key);
            }
            
            $sql = "SELECT result FROM idempotency_keys WHERE key = :key AND created_at > NOW() - INTERVAL '24 hours'";
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $row ? json_decode($row['result'], true) : null;
        } catch (Exception $e) {
            $this->logger->error("Idempotency check failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Store idempotency result
     */
    private function storeIdempotencyResult(string $key, array $result): void
    {
        try {
            if (class_exists('Infrastructure\Mojaloop\IdempotencyService')) {
                IdempotencyService::store($this->swapDB, $key, $result);
                return;
            }
            
            $sql = "
                INSERT INTO idempotency_keys (key, operation, result, created_at)
                VALUES (:key, 'swap', :result::jsonb, NOW())
                ON CONFLICT (key) DO UPDATE SET result = EXCLUDED.result, created_at = NOW()
            ";
            $stmt = $this->swapDB->prepare($sql);
            $stmt->execute([
                ':key' => $key,
                ':result' => json_encode($result)
            ]);
        } catch (Exception $e) {
            $this->logger->error("Failed to store idempotency result", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Initialize SMS service
     */
    private function initSmsService(): void
    {
        try {
            if (isset($this->config['communication']['sms_gateway']['enabled']) && 
                $this->config['communication']['sms_gateway']['enabled']) {
                $smsGatewayConfig = $this->config['communication']['sms_gateway'];
                $this->smsService = new SmsNotificationService($this->swapDB, $smsGatewayConfig);
                $this->logger->info("SMS Service initialized");
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to initialize SMS service", ['error' => $e->getMessage()]);
            $this->smsService = null;
        }
    }

    /**
     * Generate unique reference
     */
    private function generateReference(): string
    {
        return 'SWAP_' . bin2hex(random_bytes(16));
    }

    /**
     * Load configuration from YAML files
     */
    private function loadConfiguration(string $country): void
    {
        $countryPath = __DIR__ . '/../../Core/Config/Countries/' . $country;
        
        // Load participants
        $participantsPath = $countryPath . '/participants.yaml';
        if (file_exists($participantsPath)) {
            $this->participants = $this->parseYaml($participantsPath);
        }
        
        // Load endpoints
        $endpointsPath = $countryPath . '/endpoints.yaml';
        if (file_exists($endpointsPath)) {
            $this->endpoints = $this->parseYaml($endpointsPath);
        }
        
        // Load assets
        $assetsPath = __DIR__ . '/../../Core/Config/assets.yaml';
        if (file_exists($assetsPath)) {
            $this->assets = $this->parseYaml($assetsPath);
        }
        
        // Load flows
        $flowsPath = __DIR__ . '/../../Core/Config/flows.yaml';
        if (file_exists($flowsPath)) {
            $this->flows = $this->parseYaml($flowsPath);
        }
        
        // Load fees
        $feesPath = $countryPath . '/fees.json';
        if (file_exists($feesPath)) {
            $this->feesConfig = json_decode(file_get_contents($feesPath), true) ?? [];
        }
        
        // Load ATM notes
        $atmNotesPath = $countryPath . '/atm_notes.json';
        if (file_exists($atmNotesPath)) {
            $this->atmNotes = json_decode(file_get_contents($atmNotesPath), true) ?? ['BWP' => [10, 20, 50, 100, 200]];
        } else {
            $this->atmNotes = ['BWP' => [10, 20, 50, 100, 200]];
        }
        
        $this->logger->info("Configuration loaded", ['country' => $country, 'participants' => count($this->participants)]);
    }

    /**
     * Simple YAML parser
     */
    private function parseYaml(string $path): array
    {
        $content = file_get_contents($path);
        $data = [];
        $lines = explode("\n", $content);
        $currentKey = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^([a-z_]+):$/', $line, $matches)) {
                $currentKey = $matches[1];
                $data[$currentKey] = [];
            } elseif ($currentKey && preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                $data[$currentKey][$matches[1]] = $value;
            }
        }
        
        return $data;
    }

    // ============================================================
    // DELEGATED METHODS (call external services)
    // ============================================================

    /**
     * Process destination through appropriate channel
     */
    private function processDestination(array $payload, float $amount, string $currency): array
    {
        $destinationInstitution = $payload['destination_institution'] ?? $payload['to_institution'];
        $participant = $this->getParticipant($destinationInstitution);
        
        $bankClient = new GenericBankClient($participant);
        
        $request = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'currency' => $currency,
            'destination_details' => $payload['destination_details'] ?? [],
            'beneficiary_phone' => $payload['beneficiary_phone'] ?? null,
            'beneficiary_account' => $payload['beneficiary_account'] ?? null
        ];
        
        $result = $bankClient->processPayment($request);
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Destination processing failed: " . ($result['error'] ?? 'Unknown'));
        }
        
        return $result;
    }

    /**
     * Process deposit
     */
    private function processDeposit(array $payload, float $amount, ?array $fxRate): array
    {
        $destinationInstitution = $payload['destination_institution'] ?? $payload['to_institution'];
        $participant = $this->getParticipant($destinationInstitution);
        
        $bankClient = new GenericBankClient($participant);
        
        $request = [
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'fx_rate' => $fxRate['rate'] ?? 1,
            'source_details' => $payload['source_details'] ?? [],
            'client_phone' => $payload['client_phone'] ?? null
        ];
        
        $result = $bankClient->processDeposit($request);
        
        if (!($result['success'] ?? false)) {
            throw new RuntimeException("Deposit processing failed: " . ($result['error'] ?? 'Unknown'));
        }
        
        return $result;
    }

    /**
     * Generate ATM token
     */
    private function generateAtmToken(array $payload, float $amount): array
    {
        $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $voucherNumber = 'VCH_' . bin2hex(random_bytes(8));
        
        return [
            'success' => true,
            'atm_pin' => $pin,
            'voucher_number' => $voucherNumber,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours'))
        ];
    }

    // ============================================================
    // PUBLIC METHODS
    // ============================================================

    public function isMultiSourceContribution(array $payload): bool
    {
        return isset($payload['is_multi_source']) && $payload['is_multi_source'] === true;
    }

    public function getParticipant(string $institution): array
    {
        $key = strtolower($institution);
        if (isset($this->participants[$key])) {
            return $this->participants[$key];
        }
        
        foreach ($this->participants as $code => $participant) {
            if (isset($participant['provider_code']) && strtolower($participant['provider_code']) === $key) {
                return $participant;
            }
        }
        
        throw new RuntimeException("Participant not found: {$institution}");
    }

    public function getSourceAvailableBalance(array $source): float
    {
        try {
            $participant = $this->getParticipant($source['institution']);
            $bankClient = new GenericBankClient($participant);
            $result = $bankClient->verifyAsset($source);
            
            if (($result['success'] ?? false)) {
                $data = $result['data'] ?? [];
                return (float)($data['available_balance'] ?? $data['balance'] ?? 0);
            }
            
            return 0;
        } catch (Exception $e) {
            $this->logger->error("Failed to get balance", ['error' => $e->getMessage()]);
            return 0;
        }
    }

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

    public function getHoldStatus(int $holdId): ?array
    {
        $sql = "SELECT * FROM hold_transactions WHERE hold_id = :hold_id";
        $stmt = $this->swapDB->prepare($sql);
        $stmt->execute([':hold_id' => $holdId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
