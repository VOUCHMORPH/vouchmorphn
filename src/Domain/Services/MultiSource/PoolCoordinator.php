<?php
declare(strict_types=1);

namespace Domain\Services\MultiSource;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\ContributionCalculator;
use Domain\Services\MultiSourceFeeCalculator;
use Domain\Repositories\FundingPoolRepository;
use Domain\Repositories\PoolContributionRepository;
use Domain\ValueObjects\PoolStatus;
use Infrastructure\Crypto\AggregateSigner;
use Infrastructure\Banks\GenericBankClient;

class PoolCoordinator
{
    private PDO $db;
    private SwapService $swapService;
    private HybridSettlementStrategy $settlement;
    private AggregateSigner $aggregateSigner;
    private ContributionCalculator $contributionCalculator;
    private MultiSourceFeeCalculator $feeCalculator;
    private FundingPoolRepository $poolRepository;
    private PoolContributionRepository $contributionRepository;
    private PoolStateMachine $stateMachine;
    private $logger;
    private array $config;
    private string $countryCode;
    private ?array $forexRateSnapshot = null;

    public function __construct(
        PDO $db,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        AggregateSigner $aggregateSigner,
        array $config,
        string $countryCode,
        $logger = null
    ) {
        $this->db = $db;
        $this->swapService = $swapService;
        $this->settlement = $settlement;
        $this->aggregateSigner = $aggregateSigner;
        $this->config = $config;
        $this->countryCode = $countryCode;
        
        // Use default logger if none provided
        if ($logger === null) {
            $this->logger = new class {
                public function info($message, array $context = []) {
                    error_log("[POOL] INFO: " . $message . " " . json_encode($context));
                }
                public function error($message, array $context = []) {
                    error_log("[POOL] ERROR: " . $message . " " . json_encode($context));
                }
                public function warning($message, array $context = []) {
                    error_log("[POOL] WARNING: " . $message . " " . json_encode($context));
                }
                public function debug($message, array $context = []) {
                    error_log("[POOL] DEBUG: " . $message . " " . json_encode($context));
                }
                public function log($level, $message, array $context = []) {
                    error_log("[POOL] {$level}: " . $message . " " . json_encode($context));
                }
            };
        } else {
            $this->logger = $logger;
        }
        
        // Initialize dependencies
        $this->contributionCalculator = new ContributionCalculator();
        $this->feeCalculator = new MultiSourceFeeCalculator($config, $countryCode);
        $this->poolRepository = new FundingPoolRepository($db);
        $this->contributionRepository = new PoolContributionRepository($db);
        $this->stateMachine = new PoolStateMachine();
    }

    public function execute(array $payload): array
    {
        $this->logger->info('PoolCoordinator executing multi-source swap', [
            'sources' => count($payload['sources'] ?? []),
            'amount' => $payload['amount'] ?? 0
        ]);

        $this->db->beginTransaction();
        $pool = null;
        $heldSources = [];
        
        try {
            // 1. Create pool
            $pool = $this->createPool($payload);
            $this->logger->info('Pool created', ['pool_id' => $pool['id']]);
            
            // 2. Calculate contributions
            $contributions = $this->calculateContributions($pool, $payload);
            $this->logger->info('Contributions calculated', ['count' => count($contributions)]);
            
            // 3. Transition to VERIFYING
            $this->stateMachine->transition($pool, PoolStatus::VERIFYING);
            
            // 4. Verify sources (Bug 1 fixed: checks 'verified' instead of 'success')
            $verifications = $this->verifySources($contributions, $payload);
            $this->logger->info('Sources verified', ['verified' => count($verifications)]);
            
            // 5. Transition to HOLDING
            $this->stateMachine->transition($pool, PoolStatus::HOLDING);
            
            // 6. Place holds (Bug 2 fixed: captures held sources for rollback)
            $holds = $this->placeHolds($pool, $contributions, $verifications, $heldSources);
            $this->logger->info('Holds placed', ['holds' => count($holds)]);
            
            // 7. Transition to FUNDED
            $this->stateMachine->transition($pool, PoolStatus::FUNDED);
            
            // 8. Generate master signature
            $masterSignature = $this->aggregateSigner->signAggregate($pool, $holds, $verifications);
            $this->logger->info('Master signature generated');
            
            // 9. Transition to DESTINATION_PENDING
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_PENDING);
            
            // 10. Execute destination
            $destinationResult = $this->executeDestination($pool, $contributions, $masterSignature);
            $this->logger->info('Destination executed', ['success' => $destinationResult['success'] ?? false]);
            
            // 11. Transition to DESTINATION_COMPLETED
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_COMPLETED);
            
            // 12. Debit sources
            $debits = $this->debitSources($pool, $holds);
            $this->logger->info('Sources debited', ['debits' => count($debits)]);
            
            // 13. Settle
            $settlementResult = $this->settle($pool, $contributions);
            $this->logger->info('Settlement completed');
            
            // 14. Invoice
            $invoiceResult = $this->invoice($pool, $contributions);
            $this->logger->info('Invoicing completed');
            
            // 15. Complete
            $this->stateMachine->transition($pool, PoolStatus::COMPLETED);
            
            $this->db->commit();
            
            return $this->buildResponse($pool, $contributions, $destinationResult, $settlementResult, $invoiceResult);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Multi-source swap failed', ['error' => $e->getMessage()]);
            
            // Bug 2 fixed: Now releases holds properly with real institution calls
            $this->rollbackHolds($heldSources);
            
            $this->rollback($pool ?? null);
            throw new RuntimeException("Multi-source swap failed: " . $e->getMessage());
        }
    }

    private function createPool(array $payload): array
    {
        $poolId = $payload['pool_id'] ?? 'POOL_' . uniqid();
        
        // Bug 3: Take forex snapshot once at pool creation
        $this->forexRateSnapshot = $this->swapService->getForexRate(
            $payload['currency'] ?? 'BWP',
            $payload['destination_currency'] ?? 'BWP'
        );
        
        $pool = [
            'id' => $poolId,
            'sources' => $payload['sources'] ?? [],
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_currency' => $payload['destination_currency'] ?? 'BWP',
            'status' => PoolStatus::CREATED,
            'source_institution' => $payload['from_institution'] ?? $payload['source_institution'] ?? null,
            'destination_institution' => $payload['to_institution'] ?? $payload['destination_institution'] ?? null,
            'reference' => $payload['reference'] ?? uniqid(),
            'forex_rate' => $this->forexRateSnapshot,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Save to repository
        $this->poolRepository->save($pool);
        
        return $pool;
    }

    private function calculateContributions(array $pool, array $payload): array
    {
        return $this->contributionCalculator->calculate($pool, $payload);
    }

    private function verifySources(array $contributions, array $payload): array
    {
        $verifications = [];
        
        foreach ($contributions as $index => $contribution) {
            $institution = $contribution['institution'];
            $amount = $contribution['amount'];
            
            // Create verification payload
            $verifyPayload = [
                'account_id' => $contribution['account_id'],
                'amount' => $amount,
                'currency' => $contribution['currency'] ?? 'BWP',
                'reference' => $payload['reference'] ?? uniqid(),
                'from_institution' => $institution,
                'source_institution' => $institution
            ];
            
            // Call SwapService to verify asset
            $result = $this->swapService->verifyAssetSigned($verifyPayload, $institution);
            
            // Bug 1 FIX: Check 'verified' instead of 'success'
            if (!($result['verified'] ?? false)) {
                throw new RuntimeException("Verification failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            $verifications[] = [
                'index' => $index,
                'institution' => $institution,
                'verified' => true,
                'asset_id' => $result['asset_id'] ?? null,
                'balance' => $result['balance'] ?? 0
            ];
        }
        
        return $verifications;
    }

    private function placeHolds(array $pool, array $contributions, array $verifications, ?array &$heldSources = null): array
    {
        $holds = [];
        $heldSources = []; // Track successfully held sources for rollback
        
        foreach ($contributions as $index => $contribution) {
            $institution = $contribution['institution'];
            $amount = $contribution['amount'];
            
            $holdPayload = [
                'account_id' => $contribution['account_id'],
                'amount' => $amount,
                'currency' => $contribution['currency'] ?? 'BWP',
                'hold_reason' => 'MULTI_SOURCE_SWAP',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                'reference' => $pool['reference'] ?? uniqid(),
                'from_institution' => $institution,
                'source_institution' => $institution
            ];
            
            $verificationResult = $verifications[$index] ?? [];
            $result = $this->swapService->placeHoldSigned($holdPayload, $institution, $verificationResult);
            
            if (!$result['hold_placed']) {
                // Bug 2 FIX: Rollback all previously held sources with real institution calls
                $this->rollbackHolds($heldSources);
                throw new RuntimeException("Hold failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            $holdData = [
                'index' => $index,
                'institution' => $institution,
                'hold_id' => $result['hold_id'] ?? null,
                'hold_reference' => $result['hold_reference'] ?? null,
                'amount' => $amount,
                'source_payload' => $contribution // Store for rollback
            ];
            
            $holds[] = $holdData;
            $heldSources[] = $holdData; // Track for rollback
        }
        
        return $holds;
    }

    /**
     * Bug 2 FIX: Rollback holds with real institution calls
     */
    private function rollbackHolds(array $heldSources): void
    {
        if (empty($heldSources)) {
            return;
        }
        
        $this->logger->warning('Rolling back holds', ['count' => count($heldSources)]);
        
        foreach ($heldSources as $held) {
            try {
                // Release the REAL hold at the institution
                $releaseResult = $this->swapService->releaseHold(
                    $held['source_payload'] ?? [],
                    $held['institution'],
                    $held['hold_id'] ?? null,
                    $held['hold_reference'] ?? null
                );
                
                $this->logger->info('Released real hold', [
                    'institution' => $held['institution'],
                    'hold_id' => $held['hold_id'] ?? 'unknown',
                    'success' => $releaseResult['success'] ?? false
                ]);
                
                // Then clean up local bookkeeping
                $this->releaseLocalHold($held['hold_id'] ?? null);
                
            } catch (Exception $e) {
                $this->logger->error('Failed to release hold', [
                    'institution' => $held['institution'],
                    'hold_id' => $held['hold_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                // Continue trying to release other holds even if one fails
            }
        }
    }

    private function releaseLocalHold(?string $holdId): void
    {
        if ($holdId) {
            try {
                // Clean up local hold_transactions bookkeeping
                $stmt = $this->db->prepare("
                    UPDATE hold_transactions 
                    SET status = 'RELEASED', 
                        released_at = NOW(),
                        updated_at = NOW()
                    WHERE hold_id = ? AND status = 'HELD'
                ");
                $stmt->execute([$holdId]);
            } catch (Exception $e) {
                $this->logger->error('Failed to release local hold', [
                    'hold_id' => $holdId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function executeDestination(array $pool, array $contributions, string $masterSignature): array
    {
        $destinationInstitution = $pool['destination_institution'];
        $totalAmount = $pool['amount'];
        $currency = $pool['currency'] ?? 'BWP';
        
        $destinationPayload = [
            'destination_account_id' => $pool['destination_account_id'] ?? null,
            'destination_institution' => $destinationInstitution,
            'to_institution' => $destinationInstitution,
            'amount' => $totalAmount,
            'currency' => $currency,
            'reference' => $pool['reference'] ?? uniqid(),
            'master_signature' => $masterSignature,
            'pool_id' => $pool['id'],
            'sources' => $contributions
        ];
        
        return $this->swapService->creditDestination($destinationPayload, $destinationInstitution);
    }

    private function debitSources(array $pool, array $holds): array
    {
        $debits = [];
        
        foreach ($holds as $hold) {
            $institution = $hold['institution'];
            $holdReference = $hold['hold_reference'];
            $amount = $hold['amount'];
            
            $debitPayload = [
                'hold_reference' => $holdReference,
                'amount' => $amount,
                'reason' => 'Multi-source swap completed',
                'reference' => $pool['reference'] ?? uniqid(),
                'from_institution' => $institution,
                'source_institution' => $institution
            ];
            
            $result = $this->swapService->debitSource($debitPayload, $institution);
            
            if (!$result['debited']) {
                throw new RuntimeException("Debit failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            $debits[] = [
                'institution' => $institution,
                'debited' => true,
                'transaction_reference' => $result['transaction_reference'] ?? null
            ];
        }
        
        return $debits;
    }

    private function settle(array $pool, array $contributions): array
    {
        $sourceInstitutions = array_column($contributions, 'institution');
        $destinationInstitution = $pool['destination_institution'];
        $totalAmount = $pool['amount'];
        $currency = $pool['currency'] ?? 'BWP';
        
        return $this->settlement->updateNetPosition(
            $pool['reference'] ?? uniqid(),
            implode(',', $sourceInstitutions),
            $destinationInstitution,
            $totalAmount,
            'MULTI_SOURCE_COMPLETED',
            $currency
        );
    }

    private function invoice(array $pool, array $contributions): array
    {
        // Bug 3 FIX: Pass the forex rate snapshot to fee calculator
        $feeResult = $this->feeCalculator->calculate(
            $pool['amount'] ?? 0,
            $contributions,
            $pool,
            $this->forexRateSnapshot // Pass snapshot instead of fetching fresh rates
        );
        
        $invoiceResults = [];
        
        // Invoice platform fee
        if (isset($feeResult['platform_fee']) && $feeResult['platform_fee'] > 0) {
            $result = $this->settlement->invoiceFee(
                $pool['reference'] ?? uniqid(),
                'VOUCHMORPH',
                1,
                'PLATFORM_FEE',
                $feeResult['platform_fee'],
                $pool['currency'] ?? 'BWP'
            );
            $invoiceResults[] = $result;
        }
        
        // Invoice each source
        foreach ($feeResult['source_fees'] ?? [] as $sourceFee) {
            $result = $this->settlement->invoiceFee(
                $pool['reference'] ?? uniqid(),
                $sourceFee['institution'],
                0,
                'SOURCE_FEE',
                $sourceFee['amount'],
                $pool['currency'] ?? 'BWP'
            );
            $invoiceResults[] = $result;
        }
        
        return $invoiceResults;
    }

    private function rollback(?array $pool): void
    {
        if ($pool && isset($pool['id'])) {
            try {
                $this->poolRepository->updateStatus($pool['id'], PoolStatus::FAILED);
                $this->logger->warning('Pool rolled back', ['pool_id' => $pool['id']]);
            } catch (Exception $e) {
                $this->logger->error('Rollback failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function buildResponse(array $pool, array $contributions, array $destinationResult, array $settlementResult, array $invoiceResult): array
    {
        return [
            'success' => true,
            'pool_id' => $pool['id'],
            'reference' => $pool['reference'],
            'status' => PoolStatus::COMPLETED,
            'total_amount' => $pool['amount'],
            'currency' => $pool['currency'] ?? 'BWP',
            'forex_rate_used' => $this->forexRateSnapshot,
            'source_count' => count($contributions),
            'destination_result' => $destinationResult,
            'settlement' => $settlementResult,
            'invoices' => $invoiceResult,
            'completed_at' => date('Y-m-d H:i:s')
        ];
    }

    public function getStatus(string $poolId): array
    {
        $pool = $this->poolRepository->findById($poolId);
        
        if (!$pool) {
            return [
                'success' => false,
                'message' => "Pool not found: {$poolId}"
            ];
        }
        
        return [
            'success' => true,
            'pool_id' => $pool['id'],
            'status' => $pool['status'] ?? PoolStatus::UNKNOWN,
            'amount' => $pool['amount'] ?? 0,
            'currency' => $pool['currency'] ?? 'BWP',
            'created_at' => $pool['created_at'] ?? null,
            'updated_at' => $pool['updated_at'] ?? null
        ];
    }

    public function cancel(string $poolId, string $reason): array
    {
        $pool = $this->poolRepository->findById($poolId);
        
        if (!$pool) {
            return [
                'success' => false,
                'message' => "Pool not found: {$poolId}"
            ];
        }
        
        $this->poolRepository->updateStatus($poolId, PoolStatus::CANCELLED);
        
        return [
            'success' => true,
            'pool_id' => $poolId,
            'status' => PoolStatus::CANCELLED,
            'reason' => $reason
        ];
    }
}
