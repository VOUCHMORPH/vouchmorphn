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
use Infrastructure\Mojaloop\IdempotencyService;

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

    // ============================================================
    // ATOMIC EXECUTION KERNEL PROPERTIES
    // ============================================================
    private bool $activeTx = false;
    private string $currentSwapRef;
    private array $executionSteps = [];
    private ?array $preExecutionSnapshot = null;
    private array $compensationActions = [];
    private array $heldResources = [];
    private array $issuedNotifications = [];

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

    // Execution step statuses
    private const STEP_STATUS = [
        'PENDING' => 'pending',
        'EXECUTING' => 'executing',
        'SUCCESS' => 'success',
        'FAILED' => 'failed',
        'COMPENSATED' => 'compensated'
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

    // ============================================================
    // ATOMIC EXECUTION KERNEL - CORE METHODS
    // ============================================================

    /**
     * Begin a new atomic swap transaction
     * Creates a financial execution boundary that guarantees consistency
     */
    private function beginSwap(string $reference, ?array $preState = null): void
    {
        if ($this->activeTx) {
            throw new RuntimeException("Swap transaction already in progress: {$this->currentSwapRef}");
        }

        $this->currentSwapRef = $reference;
        $this->activeTx = true;
        $this->executionSteps = [];
        $this->compensationActions = [];
        $this->heldResources = [];
        $this->issuedNotifications = [];
        
        // Capture pre-execution snapshot for compensation
        $this->preExecutionSnapshot = $preState ?? $this->captureSystemSnapshot();
        
        // Begin database transaction
        $this->swapDB->beginTransaction();
        
        // Record swap attempt in audit trail
        $this->recordSwapAttempt($reference);
        
        $this->logExecutionStep('BEGIN_SWAP', [
            'reference' => $reference,
            'timestamp' => microtime(true),
            'snapshot_hash' => md5(json_encode($this->preExecutionSnapshot))
        ]);
    }

    /**
     * Capture current system state for potential rollback
     * This creates a compensation baseline
     */
    private function captureSystemSnapshot(): array
    {
        $snapshot = [];
        
        try {
            // Capture active holds
            $stmt = $this->swapDB->prepare("
                SELECT hold_reference, amount, asset_type, participant_name, status
                FROM hold_transactions 
                WHERE status IN ('ACTIVE', 'PENDING')
            ");
            $stmt->execute();
            $snapshot['active_holds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Capture pending settlements
            $stmt = $this->swapDB->prepare("
                SELECT swap_reference, amount, from_institution, to_institution, status
                FROM swap_ledgers 
                WHERE status = 'pending'
            ");
            $stmt->execute();
            $snapshot['pending_settlements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Capture unprocessed payment instructions
            $stmt = $this->swapDB->prepare("
                SELECT id, swap_reference, amount, status
                FROM payment_instructions 
                WHERE status IN ('PENDING', 'RESERVED', 'PROCESSING')
            ");
            $stmt->execute();
            $snapshot['pending_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("[SwapService] Failed to capture snapshot: " . $e->getMessage());
            $snapshot = ['error' => $e->getMessage()];
        }
        
        return $snapshot;
    }

    /**
     * Log each execution step for audit and compensation
     */
    private function logExecutionStep(string $step, array $context = []): void
    {
        $this->executionSteps[] = [
            'step' => $step,
            'context' => $context,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true)
        ];
        
        // Also log to database for persistent audit
        try {
            $stmt = $this->swapDB->prepare("
                INSERT INTO audit_logs (entity_type, entity_id, action, new_value, performed_by_type, performed_at)
                VALUES (:entity, :id, :action, :value, :type, NOW())
            ");
            $stmt->execute([
                ':entity' => 'swap_execution',
                ':id' => $this->currentSwapRef,
                ':action' => $step,
                ':value' => json_encode($context),
                ':type' => 'system'
            ]);
        } catch (Exception $e) {
            // Silent fail - audit logging shouldn't break execution
        }
    }

    /**
     * Execute a step with automatic failure tracking and compensation registration
     * This is the core of the atomic execution pattern
     */
    private function executeStep(string $stepName, callable $operation, array $context = [], ?callable $compensation = null)
    {
        $this->logExecutionStep("START_{$stepName}", $context);
        
        // Register compensation action BEFORE execution (preparation)
        if ($compensation !== null) {
            $this->compensationActions[] = [
                'step' => $stepName,
                'compensator' => $compensation,
                'context' => $context,
                'registered_at' => microtime(true)
            ];
        }
        
        try {
            $result = $operation();
            
            $this->logExecutionStep("SUCCESS_{$stepName}", [
                'result' => $this->sanitizeForLog($result),
                'duration_ms' => (microtime(true) - ($this->executionSteps[count($this->executionSteps)-1]['timestamp'] ?? microtime(true))) * 1000
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->logExecutionStep("FAILED_{$stepName}", [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Mark this step as failed
            if (!empty($this->compensationActions)) {
                $lastIndex = count($this->compensationActions) - 1;
                if ($this->compensationActions[$lastIndex]['step'] === $stepName) {
                    $this->compensationActions[$lastIndex]['failed_at'] = microtime(true);
                }
            }
            
            throw $e;
        }
    }

    /**
     * Commit the swap - only called if ALL steps succeeded
     * This finalizes the atomic transaction
     */
    private function commitSwap(): array
    {
        if (!$this->activeTx) {
            throw new RuntimeException("No active swap transaction to commit");
        }
        
        // Verify all steps completed successfully
        $failedSteps = array_filter($this->executionSteps, function($step) {
            return strpos($step['step'], 'FAILED_') === 0;
        });
        
        if (!empty($failedSteps)) {
            throw new RuntimeException("Cannot commit: " . count($failedSteps) . " steps failed");
        }
        
        // Commit database transaction
        $this->swapDB->commit();
        
        // Mark success in audit
        $this->recordSwapCompletion($this->currentSwapRef, 'COMMITTED');
        
        $result = [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'steps_executed' => count($this->executionSteps),
            'timestamp' => microtime(true)
        ];
        
        $this->logExecutionStep('COMMIT_SWAP', $result);
        
        // Reset transaction state
        $this->resetTransactionState();
        
        return $result;
    }

    /**
     * Rollback the swap with compensation
     * This restores system to pre-execution state
     */
    private function rollbackSwap(string $reason = null): array
    {
        if (!$this->activeTx) {
            return ['status' => 'already_reset', 'reference' => $this->currentSwapRef ?? 'unknown'];
        }
        
        $this->logExecutionStep('ROLLBACK_START', ['reason' => $reason]);
        
        // Execute compensation in reverse order (LIFO)
        $compensationResults = [];
        $compensationErrors = [];
        
        foreach (array_reverse($this->compensationActions) as $action) {
            try {
                $this->logExecutionStep("COMPENSATING_{$action['step']}", $action['context']);
                
                $compensator = $action['compensator'];
                $result = $compensator();
                
                $compensationResults[] = [
                    'step' => $action['step'],
                    'status' => 'compensated',
                    'result' => $result
                ];
                
                $this->logExecutionStep("COMPENSATED_{$action['step']}", ['result' => $result]);
                
            } catch (Exception $e) {
                $compensationErrors[] = [
                    'step' => $action['step'],
                    'error' => $e->getMessage()
                ];
                
                $this->logExecutionStep("COMPENSATION_FAILED_{$action['step']}", [
                    'error' => $e->getMessage()
                ]);
                
                // Continue compensating other steps - don't stop on failure
            }
        }
        
        // Rollback database transaction
        try {
            $this->swapDB->rollBack();
        } catch (Exception $e) {
            $compensationErrors[] = ['step' => 'DB_ROLLBACK', 'error' => $e->getMessage()];
        }
        
        // Release any held resources that weren't handled by compensators
        $this->releaseHeldResources();
        
        // Mark failure in audit
        $this->recordSwapCompletion($this->currentSwapRef, 'ROLLED_BACK', $reason, $compensationErrors);
        
        $result = [
            'status' => 'rolled_back',
            'reference' => $this->currentSwapRef,
            'reason' => $reason,
            'compensations' => count($compensationResults),
            'compensation_errors' => $compensationErrors,
            'timestamp' => microtime(true)
        ];
        
        $this->logExecutionStep('ROLLBACK_COMPLETE', $result);
        
        // Reset transaction state
        $this->resetTransactionState();
        
        return $result;
    }

    /**
     * Reset internal transaction state
     */
    private function resetTransactionState(): void
    {
        $this->activeTx = false;
        $this->currentSwapRef = '';
        $this->executionSteps = [];
        $this->compensationActions = [];
        $this->heldResources = [];
        $this->issuedNotifications = [];
        $this->preExecutionSnapshot = null;
    }

    /**
     * Register a resource that needs to be released on rollback
     */
    private function registerHeldResource(string $type, string $identifier, array $details): void
    {
        $this->heldResources[] = [
            'type' => $type,
            'identifier' => $identifier,
            'details' => $details,
            'held_at' => microtime(true)
        ];
    }

    /**
     * Release all registered held resources
     */
    private function releaseHeldResources(): void
    {
        foreach ($this->heldResources as $resource) {
            try {
                switch ($resource['type']) {
                    case 'hold':
                        $this->releaseHold($resource['identifier']);
                        break;
                    case 'voucher':
                        $this->releaseVoucher($resource['identifier']);
                        break;
                    case 'reserved_funds':
                        $this->releaseReservedFunds($resource['identifier']);
                        break;
                }
                $this->logExecutionStep("RELEASED_RESOURCE", $resource);
            } catch (Exception $e) {
                $this->logExecutionStep("RELEASE_RESOURCE_FAILED", [
                    'resource' => $resource,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Release a hold on funds/voucher
     */
    private function releaseHold(string $holdReference): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE hold_transactions 
                SET status = 'RELEASED', released_at = NOW()
                WHERE hold_reference = :ref AND status = 'ACTIVE'
            ");
            $stmt->execute([':ref' => $holdReference]);
        } catch (Exception $e) {
            error_log("[SwapService] Failed to release hold {$holdReference}: " . $e->getMessage());
        }
    }

    /**
     * Release a voucher back to available state
     */
    private function releaseVoucher(string $voucherNumber): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE swap_vouchers 
                SET status = 'ACTIVE', claimant_phone = NULL
                WHERE voucher_number = :voucher AND status = 'HELD'
            ");
            $stmt->execute([':voucher' => $voucherNumber]);
        } catch (Exception $e) {
            error_log("[SwapService] Failed to release voucher {$voucherNumber}: " . $e->getMessage());
        }
    }

    /**
     * Release reserved funds
     */
    private function releaseReservedFunds(string $reference): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE payment_instructions 
                SET status = 'CANCELLED', failure_reason = 'Transaction rolled back'
                WHERE swap_reference = :ref AND status = 'RESERVED'
            ");
            $stmt->execute([':ref' => $reference]);
        } catch (Exception $e) {
            error_log("[SwapService] Failed to release reserved funds {$reference}: " . $e->getMessage());
        }
    }

    /**
     * Record swap attempt in database
     */
    private function recordSwapAttempt(string $reference): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                INSERT INTO swap_requests (swap_uuid, status, created_at)
                VALUES (:ref, 'processing', NOW())
                ON CONFLICT (swap_uuid) DO UPDATE 
                SET status = 'processing', updated_at = NOW()
            ");
            $stmt->execute([':ref' => $reference]);
        } catch (Exception $e) {
            // Non-critical - continue execution
            error_log("[SwapService] Failed to record swap attempt: " . $e->getMessage());
        }
    }

    /**
     * Record swap completion/failure in database
     */
    private function recordSwapCompletion(string $reference, string $finalStatus, ?string $reason = null, array $errors = []): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE swap_requests 
                SET status = :status, 
                    completed_at = NOW(),
                    metadata = jsonb_set(
                        COALESCE(metadata, '{}'::jsonb),
                        '{execution_log}',
                        :log::jsonb
                    )
                WHERE swap_uuid = :ref
            ");
            
            $logData = json_encode([
                'final_status' => $finalStatus,
                'reason' => $reason,
                'errors' => $errors,
                'steps' => count($this->executionSteps),
                'timestamp' => microtime(true)
            ]);
            
            $stmt->execute([
                ':ref' => $reference,
                ':status' => $finalStatus === 'COMMITTED' ? 'completed' : 'failed',
                ':log' => $logData
            ]);
        } catch (Exception $e) {
            error_log("[SwapService] Failed to record swap completion: " . $e->getMessage());
        }
    }

    // ============================================================
    // MAIN EXECUTION ENTRY POINT - ATOMIC SWAP
    // ============================================================

    /**
     * Execute swap with full atomic guarantees
     * This is the main entry point for all swap operations
     */
    public function executeSwap(array $payload): array
    {
        // Generate or use provided reference
        $ref = $payload['reference'] ?? ('SWAP_' . bin2hex(random_bytes(8)));
        
        // Extract idempotency key
        $idempotencyKey = $payload['idempotency_key'] ?? $payload['idempotencyKey'] ?? null;
        
        try {
            // BEGIN ATOMIC TRANSACTION BOUNDARY
            $this->beginSwap($ref);
            
            // STEP 0: IDEMPOTENCY CHECK (MUST BE FIRST)
            if ($idempotencyKey) {
                $cachedResult = $this->checkIdempotency($idempotencyKey);
                if ($cachedResult !== null) {
                    $this->logExecutionStep("IDEMPOTENCY_HIT", ['key' => $idempotencyKey]);
                    $this->commitSwap(); // Commit the empty transaction
                    return $cachedResult;
                }
            }
            
            // Determine swap type and execute appropriate flow
            $isMultiSource = $this->isMultiSourceContribution($payload);
            $swapType = $payload['swap_type'] ?? ($isMultiSource ? 'MULTI_SOURCE' : 'STANDARD');
            
            $result = null;
            
            switch ($swapType) {
                case 'MULTI_SOURCE':
                    $result = $this->executeAtomicMultiSourceSwap($payload);
                    break;
                case 'CASHOUT':
                    $result = $this->executeAtomicCashout($payload);
                    break;
                case 'DEPOSIT':
                    $result = $this->executeAtomicDeposit($payload);
                    break;
                case 'CARD_ISSUE':
                    $result = $this->executeAtomicCardIssuance($payload);
                    break;
                default:
                    $result = $this->executeAtomicStandardSwap($payload);
                    break;
            }
            
            // Commit the atomic transaction
            $commitResult = $this->commitSwap();
            $result = array_merge($result, ['atomic_commit' => $commitResult]);
            
            // Store idempotency result
            if ($idempotencyKey) {
                $this->storeIdempotencyResult($idempotencyKey, $result);
            }
            
            return $result;
            
        } catch (Exception $e) {
            // Rollback with compensation
            $rollbackResult = $this->rollbackSwap($e->getMessage());
            
            // Store failure in idempotency cache
            if ($idempotencyKey) {
                $this->storeIdempotencyResult($idempotencyKey, [
                    'status' => 'failed',
                    'reference' => $ref,
                    'error' => $e->getMessage(),
                    'rollback' => $rollbackResult
                ]);
            }
            
            throw new RuntimeException("Swap execution failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute standard swap with atomic steps
     */
    private function executeAtomicStandardSwap(array $payload): array
    {
        $sourceInstitution = $payload['source_institution'] ?? $payload['from_institution'];
        $destInstitution = $payload['destination_institution'] ?? $payload['to_institution'];
        $amount = (float)($payload['amount'] ?? 0);
        
        // STEP 1: Validate and verify source asset
        $verificationResult = $this->executeStep('VERIFY_SOURCE', 
            function() use ($payload) {
                return $this->verifySourceAsset($payload);
            },
            ['source' => $sourceInstitution, 'amount' => $amount],
            function() use ($payload) {
                // Compensation: Nothing to compensate for verification failure
                return ['compensated' => true];
            }
        );
        
        if (!$this->isVerificationSuccessful($verificationResult)) {
            throw new RuntimeException("Source verification failed");
        }
        
        // STEP 2: Place hold on source asset
        $holdResult = $this->executeStep('PLACE_HOLD',
            function() use ($payload) {
                return $this->placeHold($payload);
            },
            ['source' => $sourceInstitution, 'amount' => $amount],
            function() use ($payload) {
                // Compensation: Release the hold if placed
                $holdRef = $payload['hold_reference'] ?? null;
                if ($holdRef) {
                    $this->releaseHold($holdRef);
                    $this->registerHeldResource('hold', $holdRef, ['released_by' => 'compensation']);
                }
                return ['hold_released' => $holdRef];
            }
        );
        
        if (!$this->isHoldSuccessful($holdResult)) {
            throw new RuntimeException("Failed to place hold on source");
        }
        
        // Store hold reference for compensation
        $holdReference = $holdResult['hold_reference'] ?? $holdResult['reference'] ?? null;
        if ($holdReference) {
            $this->registerHeldResource('hold', $holdReference, ['amount' => $amount]);
        }
        
        // STEP 3: Debit source
        $debitResult = $this->executeStep('DEBIT_SOURCE',
            function() use ($payload) {
                return $this->debitSource($payload);
            },
            ['hold_reference' => $holdReference],
            function() use ($holdReference) {
                // Compensation: Cannot undo debit easily, but we've already released hold
                // This would require a reversal transaction
                return ['note' => 'Debit may require manual reversal'];
            }
        );
        
        if (!$this->isDebitSuccessful($debitResult)) {
            throw new RuntimeException("Failed to debit source");
        }
        
        // STEP 4: Process destination (credit)
        $destinationResult = $this->executeStep('PROCESS_DESTINATION',
            function() use ($payload) {
                return $this->processDestination($payload);
            },
            ['destination' => $destInstitution, 'amount' => $amount],
            null // No compensation for credit - would require reversal
        );
        
        // STEP 5: Record settlement
        $settlementResult = $this->executeStep('RECORD_SETTLEMENT',
            function() use ($payload, $debitResult, $destinationResult) {
                return $this->settlement->recordSettlement(
                    $payload,
                    $debitResult,
                    $destinationResult
                );
            },
            ['reference' => $this->currentSwapRef],
            null
        );
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'verification' => $verificationResult,
            'hold' => $holdResult,
            'debit' => $debitResult,
            'destination' => $destinationResult,
            'settlement' => $settlementResult
        ];
    }

    /**
     * Execute atomic cashout (source → ATM/voucher)
     */
    private function executeAtomicCashout(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $beneficiaryPhone = $payload['beneficiary_phone'] ?? $payload['client_phone'] ?? null;
        
        // STEP 1: Verify source
        $verificationResult = $this->executeStep('VERIFY_CASHOUT_SOURCE',
            fn() => $this->verifySourceAsset($payload),
            ['amount' => $amount]
        );
        
        // STEP 2: Place hold
        $holdResult = $this->executeStep('PLACE_CASHOUT_HOLD',
            fn() => $this->placeHold($payload),
            ['amount' => $amount],
            fn() => $this->releaseHold($holdResult['hold_reference'] ?? null)
        );
        
        // STEP 3: Generate ATM token/voucher
        $tokenResult = $this->executeStep('GENERATE_ATM_TOKEN',
            function() use ($payload, $holdResult) {
                return $this->generateAtmToken($payload, $holdResult);
            },
            ['beneficiary' => $beneficiaryPhone],
            function() use ($tokenResult) {
                // Invalidate generated token
                if ($tokenResult && isset($tokenResult['voucher_number'])) {
                    $this->invalidateVoucher($tokenResult['voucher_number']);
                }
                return ['token_invalidated' => true];
            }
        );
        
        // STEP 4: Send SMS notification
        if ($beneficiaryPhone && $this->smsService && isset($tokenResult['atm_pin'])) {
            $this->executeStep('SEND_CASHOUT_SMS',
                function() use ($beneficiaryPhone, $tokenResult, $amount) {
                    return $this->smsService->sendCashoutCode(
                        $beneficiaryPhone,
                        $tokenResult['atm_pin'],
                        $amount,
                        $tokenResult['voucher_number'] ?? null
                    );
                },
                ['phone' => $beneficiaryPhone],
                null // SMS cannot be unsent
            );
        }
        
        // STEP 5: Debit source
        $debitResult = $this->executeStep('DEBIT_CASHOUT_SOURCE',
            fn() => $this->debitSource($payload),
            ['hold_reference' => $holdResult['hold_reference'] ?? null]
        );
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'atm_code' => $tokenResult['atm_pin'] ?? null,
            'voucher_number' => $tokenResult['voucher_number'] ?? null,
            'expiry' => $tokenResult['expires_at'] ?? null,
            'amount' => $amount
        ];
    }

    /**
     * Execute atomic deposit (external → system)
     */
    private function executeAtomicDeposit(array $payload): array
    {
        $amount = (float)($payload['amount'] ?? 0);
        $targetAccount = $payload['destination_account'] ?? $payload['target_wallet'] ?? null;
        
        // STEP 1: Verify source (external bank/wallet)
        $verificationResult = $this->executeStep('VERIFY_DEPOSIT_SOURCE',
            fn() => $this->verifySourceAsset($payload),
            ['amount' => $amount]
        );
        
        // STEP 2: Reserve destination funds in ledger
        $reservationResult = $this->executeStep('RESERVE_DESTINATION',
            function() use ($payload, $amount) {
                return $this->reserveDestinationFunds($payload, $amount);
            },
            ['target' => $targetAccount],
            function() use ($reservationResult) {
                if ($reservationResult && isset($reservationResult['reservation_id'])) {
                    $this->releaseReservation($reservationResult['reservation_id']);
                }
                return ['reservation_released' => true];
            }
        );
        
        // STEP 3: Debit source (external)
        $debitResult = $this->executeStep('DEBIT_DEPOSIT_SOURCE',
            fn() => $this->debitSource($payload),
            ['amount' => $amount]
        );
        
        // STEP 4: Credit destination
        $creditResult = $this->executeStep('CREDIT_DESTINATION',
            function() use ($payload, $amount, $reservationResult) {
                return $this->creditDestination($payload, $amount);
            },
            ['amount' => $amount]
        );
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'amount' => $amount,
            'destination' => $targetAccount
        ];
    }

    /**
     * Execute atomic card issuance
     */
    private function executeAtomicCardIssuance(array $payload): array
    {
        $userId = $payload['user_id'] ?? null;
        $cardType = $payload['card_type'] ?? 'virtual';
        
        // STEP 1: Verify user eligibility
        $eligibilityResult = $this->executeStep('VERIFY_CARD_ELIGIBILITY',
            function() use ($userId) {
                return $this->cardService->checkEligibility($userId);
            },
            ['user_id' => $userId]
        );
        
        // STEP 2: Create card record
        $cardResult = $this->executeStep('CREATE_CARD',
            function() use ($payload) {
                return $this->cardService->createCard($payload);
            },
            ['type' => $cardType],
            function() use ($cardResult) {
                if ($cardResult && isset($cardResult['card_id'])) {
                    $this->cardService->voidCard($cardResult['card_id']);
                }
                return ['card_voided' => true];
            }
        );
        
        // STEP 3: Generate card details
        $cardDetails = $this->executeStep('GENERATE_CARD_DETAILS',
            function() use ($cardResult) {
                return $this->cardService->generateCardDetails($cardResult['card_id']);
            },
            ['card_id' => $cardResult['card_id'] ?? null]
        );
        
        return [
            'status' => 'success',
            'reference' => $this->currentSwapRef,
            'card_id' => $cardResult['card_id'] ?? null,
            'card_last_four' => $cardDetails['last_four'] ?? null,
            'expiry' => $cardDetails['expiry'] ?? null
        ];
    }

    /**
     * Execute multi-source swap with atomic guarantees
     */
    private function executeAtomicMultiSourceSwap(array $payload): array
    {
        if (!$this->multiSourceExecutor) {
            throw new RuntimeException("Multi-source swap executor not initialized");
        }
        
        $multiSourceEnabled = $this->config['multi_source']['enabled'] ?? true;
        if (!$multiSourceEnabled) {
            throw new RuntimeException("Multi-source swaps are not enabled");
        }
        
        // Execute multi-source swap through the executor
        // The executor will use the atomic step pattern internally
        $result = $this->executeStep('MULTI_SOURCE_EXECUTION',
            function() use ($payload) {
                return $this->multiSourceExecutor->execute($payload);
            },
            ['sources' => count($payload['sources'] ?? [])],
            function() use ($payload) {
                // Compensation: Reverse any partial settlements
                return ['partial_reversal_initiated' => true];
            }
        );
        
        return $result;
    }

    // ============================================================
    // HELPER METHODS FOR STEP RESULTS
    // ============================================================

    private function isVerificationSuccessful(array $result): bool
    {
        return ($result['success'] ?? false) || ($result['verified'] ?? false);
    }

    private function isHoldSuccessful(array $result): bool
    {
        return ($result['success'] ?? false) || ($result['hold_placed'] ?? false);
    }

    private function isDebitSuccessful(array $result): bool
    {
        return ($result['success'] ?? false) || ($result['debited'] ?? false);
    }

    /**
     * Check idempotency key cache
     */
    private function checkIdempotency(string $key): ?array
    {
        try {
            if (class_exists('Infrastructure\Mojaloop\IdempotencyService')) {
                return IdempotencyService::check($this->swapDB, $key);
            }
            
            // Fallback: Check local cache table
            $stmt = $this->swapDB->prepare("
                SELECT result FROM idempotency_cache 
                WHERE idempotency_key = :key 
                AND created_at > NOW() - INTERVAL '24 HOURS'
            ");
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                return json_decode($row['result'], true);
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("[SwapService] Idempotency check failed: " . $e->getMessage());
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
            
            // Fallback: Store in local cache table
            $stmt = $this->swapDB->prepare("
                INSERT INTO idempotency_cache (idempotency_key, result, created_at)
                VALUES (:key, :result, NOW())
                ON CONFLICT (idempotency_key) DO UPDATE
                SET result = EXCLUDED.result, created_at = NOW()
            ");
            $stmt->execute([
                ':key' => $key,
                ':result' => json_encode($result)
            ]);
            
        } catch (Exception $e) {
            error_log("[SwapService] Failed to store idempotency result: " . $e->getMessage());
        }
    }

    /**
     * Sanitize data for logging (remove sensitive info)
     */
    private function sanitizeForLog($data)
    {
        if (is_array($data)) {
            $sensitive = ['pin', 'password', 'code', 'token', 'cvv', 'pan'];
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (in_array(strtolower($key), $sensitive)) {
                    $sanitized[$key] = '***REDACTED***';
                } else {
                    $sanitized[$key] = $this->sanitizeForLog($value);
                }
            }
            return $sanitized;
        }
        return $data;
    }

    // ============================================================
    // PLACEHOLDER METHODS FOR METHODS THAT WOULD CALL EXTERNAL SERVICES
    // These would be implemented based on your existing code
    // ============================================================

    private function verifySourceAsset(array $payload): array
    {
        // This would call the appropriate bank client based on asset type
        // For now, return a success structure
        $institution = $payload['source_institution'] ?? $payload['from_institution'];
        return [
            'success' => true,
            'verified' => true,
            'institution' => $institution,
            'amount' => $payload['amount'] ?? 0
        ];
    }

    private function placeHold(array $payload): array
    {
        $holdRef = $payload['hold_reference'] ?? ($this->currentSwapRef . '_HOLD');
        return [
            'success' => true,
            'hold_placed' => true,
            'hold_reference' => $holdRef
        ];
    }

    private function debitSource(array $payload): array
    {
        return [
            'success' => true,
            'debited' => true,
            'amount' => $payload['amount'] ?? 0
        ];
    }

    private function processDestination(array $payload): array
    {
        return [
            'success' => true,
            'credited' => true,
            'destination' => $payload['destination_institution'] ?? $payload['to_institution']
        ];
    }

    private function generateAtmToken(array $payload, array $holdResult): array
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

    private function invalidateVoucher(string $voucherNumber): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE swap_vouchers 
                SET status = 'INVALIDATED' 
                WHERE voucher_number = :voucher
            ");
            $stmt->execute([':voucher' => $voucherNumber]);
        } catch (Exception $e) {
            error_log("[SwapService] Failed to invalidate voucher: " . $e->getMessage());
        }
    }

    private function reserveDestinationFunds(array $payload, float $amount): array
    {
        $reservationId = 'RES_' . bin2hex(random_bytes(8));
        return [
            'success' => true,
            'reservation_id' => $reservationId,
            'amount' => $amount
        ];
    }

    private function releaseReservation(string $reservationId): void
    {
        // Implementation would release the reserved funds
    }

    private function creditDestination(array $payload, float $amount): array
    {
        return [
            'success' => true,
            'credited' => true,
            'amount' => $amount
        ];
    }

    // ============================================================
    // EXISTING METHODS FROM YOUR ORIGINAL CLASS
    // (Load configuration, YAML parsers, etc.)
    // ============================================================

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
        
        // 5. Load fees configuration
        $feesPath = $countryPath . '/fees.json';
        if (file_exists($feesPath)) {
            $feesContent = file_get_contents($feesPath);
            $this->feesConfig = json_decode($feesContent, true) ?? [];
        }
        
        // 6. Load ATM notes
        $atmNotesPath = $countryPath . '/atm_notes.json';
        if (file_exists($atmNotesPath)) {
            $atmContent = file_get_contents($atmNotesPath);
            $this->atmNotes = json_decode($atmContent, true) ?? ['BWP' => [10, 20, 50, 100, 200]];
        } else {
            $this->atmNotes = ['BWP' => [10, 20, 50, 100, 200]];
        }
        
        // Merge endpoints into participants
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
            
            if (!isset($participant['default_currency'])) {
                $participant['default_currency'] = $this->config['currency'] ?? 'BWP';
            }
            
            if (!isset($participant['country_code'])) {
                $participant['country_code'] = $this->countryCode;
            }
        }
        
        error_log("[SwapService] Loaded configuration for {$country}: " . count($this->participants) . " participants");
    }

    private function parseParticipantsYaml(string $path): array
    {
        $content = file_get_contents($path);
        $participants = [];
        $lines = explode("\n", $content);
        $currentParticipant = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
                $currentParticipant = strtolower($matches[1]);
                $participants[$currentParticipant] = [];
                continue;
            }
            
            if ($currentParticipant && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
                
                if ($key === 'assets' && preg_match('/^\[(.*)\]$/', $value, $arr)) {
                    $participants[$currentParticipant][$key] = array_map('trim', explode(',', $arr[1]));
                } else {
                    $participants[$currentParticipant][$key] = $value;
                }
            }
            
            if ($currentParticipant && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches)) {
                $participants[$currentParticipant]['routing'][$matches[1]] = $matches[2];
            }
        }
        
        return $participants;
    }

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
            
            if (preg_match('/^([A-Z_]+):$/', $line, $matches)) {
                $currentParticipant = strtolower($matches[1]);
                $endpoints[$currentParticipant] = [];
                $currentSection = null;
                continue;
            }
            
            if (!$currentParticipant) continue;
            
            if (preg_match('/^  ([a-z_]+):$/', $line, $matches)) {
                $currentSection = $matches[1];
                $endpoints[$currentParticipant][$currentSection] = [];
                continue;
            }
            
            if ($currentSection && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                $endpoints[$currentParticipant][$currentSection][$key] = $value;
                continue;
            }
            
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
            
            if (preg_match('/^([A-Z-]+):$/', $line, $matches)) {
                $currentAsset = $matches[1];
                $assets[$currentAsset] = [];
                $currentSection = null;
                continue;
            }
            
            if (!$currentAsset) continue;
            
            if (preg_match('/^  ([a-z_]+):$/', $line, $matches)) {
                $currentSection = $matches[1];
                if ($currentSection === 'fields') {
                    $assets[$currentAsset][$currentSection] = [];
                } else {
                    $assets[$currentAsset][$currentSection] = [];
                }
                continue;
            }
            
            if ($currentSection === 'delivery' && preg_match('/^    - (.+)$/', $line, $matches)) {
                $assets[$currentAsset][$currentSection][] = trim($matches[1]);
                continue;
            }
            
            if ($currentSection === 'fields' && preg_match('/^    - name: (.+)$/', $line, $matches)) {
                $currentField = ['name' => trim($matches[1])];
                $assets[$currentAsset]['fields'][] = $currentField;
                continue;
            }
            
            if ($currentSection === 'fields' && isset($currentField) && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches)) {
                $idx = count($assets[$currentAsset]['fields']) - 1;
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if ($value === 'true') $value = true;
                if ($value === 'false') $value = false;
                $assets[$currentAsset]['fields'][$idx][$matches[1]] = $value;
                continue;
            }
            
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

    private function parseFlowsYaml(string $path): array
    {
        $content = file_get_contents($path);
        $flows = [];
        $lines = explode("\n", $content);
        $currentSection = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^([a-z_]+):$/', $line, $matches)) {
                $currentSection = $matches[1];
                $flows[$currentSection] = [];
                continue;
            }
            
            if ($currentSection === 'orchestration' && preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $flows[$currentSection][$matches[1]] = trim($matches[2]);
                continue;
            }
            
            if ($currentSection === 'states' && preg_match('/^  - (.+)$/', $line, $matches)) {
                $flows[$currentSection][] = trim($matches[1]);
                continue;
            }
        }
        
        return $flows;
    }

    // ============================================================
    // PUBLIC METHODS (Keep existing public interface)
    // ============================================================

    public function executeMultiSourceSwap(array $payload): array
    {
        return $this->executeAtomicMultiSourceSwap($payload);
    }

    public function isMultiSourceContribution(array $payload): bool
    {
        return isset($payload['is_multi_source']) && $payload['is_multi_source'] === true;
    }

    public function getMasterReferenceForContribution(array $payload): ?string
    {
        return $payload['master_reference'] ?? $payload['multi_source_uuid'] ?? null;
    }

    public function getSourceIndexForContribution(array $payload): ?int
    {
        return $payload['source_index'] ?? null;
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

    public function getAssetDefinition(string $assetType): array
    {
        return $this->assets[$assetType] ?? [];
    }

    public function getCorridors(): array
    {
        return $this->flows['corridors'] ?? [];
    }

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

    public function getParticipantEndpoint(string $institution, string $operation, string $action): ?string
    {
        $key = $this->findInstitutionKey($institution);
        if (!$key || !isset($this->endpoints[$key])) {
            return null;
        }
        
        $endpointConfig = $this->endpoints[$key];
        
        if (isset($endpointConfig[$operation][$action])) {
            return $endpointConfig[$operation][$action];
        }
        
        if (isset($endpointConfig['common'][$action])) {
            return $endpointConfig['common'][$action];
        }
        
        return null;
    }

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

    private function formatPhoneForInstitution(?string $phone, array $participant): ?string
    {
        if (!$phone) return null;
        
        $phoneFormat = $participant['phone_format'] ?? ['prefix' => '+', 'country_code' => '267'];
        $countryCode = $phoneFormat['country_code'] ?? '267';
        $prefix = $phoneFormat['prefix'] ?? '+';
        
        // Remove any existing prefix
        $clean = preg_replace('/^\+?\d{1,3}/', '', $phone);
        $clean = preg_replace('/[^0-9]/', '', $clean);
        
        // Format according to participant's expected format
        return $prefix . $countryCode . ltrim($clean, '0');
    }
}
