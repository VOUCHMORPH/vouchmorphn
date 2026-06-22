<?php
declare(strict_types=1);

namespace Domain\Services\MultiSource;

use RuntimeException;

/**
 * Pool State Machine
 * Manages state transitions for multi-source funding pools
 */
class PoolStateMachine
{
    /**
     * Valid state transitions
     * Current state => [allowed next states]
     */
    private array $transitions = [
        'CREATED' => ['VERIFYING', 'CANCELLED'],
        'VERIFYING' => ['HOLDING', 'FAILED', 'CANCELLED'],
        'HOLDING' => ['FUNDED', 'FAILED', 'ROLLED_BACK'],
        'FUNDED' => ['DESTINATION_PENDING', 'FAILED'],
        'DESTINATION_PENDING' => ['DESTINATION_COMPLETED', 'FAILED'],
        'DESTINATION_COMPLETED' => ['DEBITING', 'FAILED'],
        'DEBITING' => ['SETTLING', 'FAILED', 'ROLLED_BACK'],
        'SETTLING' => ['INVOICING', 'FAILED'],
        'INVOICING' => ['COMPLETED', 'FAILED'],
        'COMPLETED' => [],
        'FAILED' => [],
        'CANCELLED' => [],
        'ROLLED_BACK' => []
    ];

    /**
     * Transition a pool to a new state
     * 
     * @param array $pool Pool data (must have 'status' and 'id' keys)
     * @param string $newStatus New status to transition to
     * @param array $metadata Additional metadata to merge
     * @throws RuntimeException If transition is invalid
     */
    public function transition(array &$pool, string $newStatus, array $metadata = []): void
    {
        $currentStatus = $pool['status'] ?? 'CREATED';
        
        // If already in target state, skip
        if ($currentStatus === $newStatus) {
            return;
        }

        // Check if transition is allowed
        if (!$this->canTransition($currentStatus, $newStatus)) {
            throw new RuntimeException(
                "Invalid state transition from {$currentStatus} to {$newStatus}"
            );
        }

        // Update status
        $pool['status'] = $newStatus;
        $pool['updated_at'] = date('Y-m-d H:i:s');
        
        // Merge metadata
        if (!empty($metadata)) {
            $pool['metadata'] = array_merge($pool['metadata'] ?? [], $metadata);
        }

        error_log(sprintf(
            "[PoolStateMachine] Pool %s: %s → %s",
            $pool['id'] ?? 'unknown',
            $currentStatus,
            $newStatus
        ));
    }

    /**
     * Check if a transition is valid
     * 
     * @param string $current Current state
     * @param string $new Target state
     * @return bool True if transition is allowed
     */
    public function canTransition(string $current, string $new): bool
    {
        $allowed = $this->transitions[$current] ?? [];
        return in_array($new, $allowed);
    }

    /**
     * Get all allowed next states for a given state
     * 
     * @param string $current Current state
     * @return array List of allowed next states
     */
    public function getAllowedTransitions(string $current): array
    {
        return $this->transitions[$current] ?? [];
    }

    /**
     * Check if a state is terminal (no further transitions allowed)
     * 
     * @param string $state State to check
     * @return bool True if terminal
     */
    public function isTerminal(string $state): bool
    {
        return empty($this->transitions[$state] ?? []);
    }

    /**
     * Check if a state is a failure state
     * 
     * @param string $state State to check
     * @return bool True if failed
     */
    public function isFailureState(string $state): bool
    {
        return in_array($state, ['FAILED', 'CANCELLED', 'ROLLED_BACK']);
    }

    /**
     * Check if a state is a success state
     * 
     * @param string $state State to check
     * @return bool True if completed
     */
    public function isSuccessState(string $state): bool
    {
        return $state === 'COMPLETED';
    }

    /**
     * Get the transition path from start to end
     * 
     * @param string $start Starting state
     * @param string $end Target state
     * @return array|null Path of states, or null if no path exists
     */
    public function getTransitionPath(string $start, string $end): ?array
    {
        if ($start === $end) {
            return [$start];
        }

        $visited = [];
        $queue = [[$start]];

        while (!empty($queue)) {
            $path = array_shift($queue);
            $current = end($path);

            if ($current === $end) {
                return $path;
            }

            if (in_array($current, $visited)) {
                continue;
            }

            $visited[] = $current;

            foreach ($this->transitions[$current] ?? [] as $next) {
                if (!in_array($next, $visited)) {
                    $newPath = $path;
                    $newPath[] = $next;
                    $queue[] = $newPath;
                }
            }
        }

        return null;
    }
}<?php
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
        
        try {
            // 1. Create pool
            $pool = $this->createPool($payload);
            $this->logger->info('Pool created', ['pool_id' => $pool->getPoolId()]);
            
            // 2. Calculate contributions
            $contributions = $this->calculateContributions($pool, $payload);
            $this->logger->info('Contributions calculated', ['count' => count($contributions)]);
            
            // 3. Transition to VERIFYING
            $this->stateMachine->transition($pool, PoolStatus::VERIFYING);
            
            // 4. Verify sources
            $verifications = $this->verifySources($contributions, $payload);
            $this->logger->info('Sources verified', ['verified' => count($verifications)]);
            
            // 5. Transition to HOLDING
            $this->stateMachine->transition($pool, PoolStatus::HOLDING);
            
            // 6. Place holds
            $holds = $this->placeHolds($pool, $contributions, $verifications);
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
            $this->logger->info('Destination executed', ['success' => $destinationResult['credited'] ?? false]);
            
            // 11. Transition to DESTINATION_COMPLETED
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_COMPLETED);
            
            // 12. Debit sources
            $debits = $this->debitSources($pool, $holds);
            $this->logger->info('Sources debited', ['debits' => count($debits)]);
            
            // 13. Transition to SETTLING
            $this->stateMachine->transition($pool, PoolStatus::SETTLING);
            
            // 14. Settle
            $settlementResult = $this->settle($pool, $contributions);
            $this->logger->info('Settlement completed');
            
            // 15. Transition to INVOICING
            $this->stateMachine->transition($pool, PoolStatus::INVOICING);
            
            // 16. Invoice
            $invoiceResult = $this->invoice($pool, $contributions);
            $this->logger->info('Invoicing completed');
            
            // 17. Complete
            $this->stateMachine->transition($pool, PoolStatus::COMPLETED);
            
            $this->db->commit();
            
            return $this->buildResponse($pool, $contributions, $destinationResult, $settlementResult, $invoiceResult);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Multi-source swap failed', ['error' => $e->getMessage()]);
            $this->rollback($pool ?? null);
            throw new RuntimeException("Multi-source swap failed: " . $e->getMessage());
        }
    }

    private function createPool(array $payload): FundingPool
    {
        $poolId = $payload['pool_id'] ?? 'POOL_' . uniqid();
        
        $pool = new FundingPool([
            'pool_id' => $poolId,
            'sources' => $payload['sources'] ?? [],
            'total_amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'BWP',
            'status' => PoolStatus::CREATED,
            'source_institution' => $payload['from_institution'] ?? $payload['source_institution'] ?? null,
            'destination_institution' => $payload['to_institution'] ?? $payload['destination_institution'] ?? null,
            'reference' => $payload['reference'] ?? uniqid(),
            'metadata' => []
        ]);
        
        // Save to repository
        $this->poolRepository->save($pool);
        
        return $pool;
    }

    private function calculateContributions(FundingPool $pool, array $payload): array
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
            
            if (!$result['verified']) {
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

    private function placeHolds(FundingPool $pool, array $contributions, array $verifications): array
    {
        $holds = [];
        
        foreach ($contributions as $index => $contribution) {
            $institution = $contribution['institution'];
            $amount = $contribution['amount'];
            
            $holdPayload = [
                'account_id' => $contribution['account_id'],
                'amount' => $amount,
                'currency' => $contribution['currency'] ?? 'BWP',
                'hold_reason' => 'MULTI_SOURCE_SWAP',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                'reference' => $pool->getReference() ?? uniqid(),
                'from_institution' => $institution,
                'source_institution' => $institution
            ];
            
            $verificationResult = $verifications[$index] ?? [];
            $result = $this->swapService->placeHoldSigned($holdPayload, $institution, $verificationResult);
            
            if (!$result['hold_placed']) {
                throw new RuntimeException("Hold failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            $holds[] = [
                'index' => $index,
                'institution' => $institution,
                'hold_id' => $result['hold_id'] ?? null,
                'hold_reference' => $result['hold_reference'] ?? null,
                'amount' => $amount
            ];
        }
        
        return $holds;
    }

    private function executeDestination(FundingPool $pool, array $contributions, string $masterSignature): array
    {
        $destinationInstitution = $pool->getDestinationInstitution();
        $totalAmount = $pool->getTotalAmount();
        $currency = $pool->getCurrency() ?? 'BWP';
        
        $destinationPayload = [
            'destination_account_id' => $pool->getDestinationAccountId() ?? null,
            'destination_institution' => $destinationInstitution,
            'to_institution' => $destinationInstitution,
            'amount' => $totalAmount,
            'currency' => $currency,
            'reference' => $pool->getReference() ?? uniqid(),
            'master_signature' => $masterSignature,
            'pool_id' => $pool->getPoolId(),
            'sources' => $contributions
        ];
        
        return $this->swapService->creditDestination($destinationPayload, $destinationInstitution);
    }

    private function debitSources(FundingPool $pool, array $holds): array
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
                'reference' => $pool->getReference() ?? uniqid(),
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

    private function settle(FundingPool $pool, array $contributions): array
    {
        $sourceInstitutions = array_column($contributions, 'institution');
        $destinationInstitution = $pool->getDestinationInstitution();
        $totalAmount = $pool->getTotalAmount();
        $currency = $pool->getCurrency() ?? 'BWP';
        
        return $this->settlement->updateNetPosition(
            $pool->getReference() ?? uniqid(),
            implode(',', $sourceInstitutions),
            $destinationInstitution,
            $totalAmount,
            'MULTI_SOURCE_COMPLETED',
            $currency
        );
    }

    private function invoice(FundingPool $pool, array $contributions): array
    {
        // Calculate fees
        $feeResult = $this->feeCalculator->calculate(
            $pool->getTotalAmount() ?? 0,
            $contributions,
            $pool->toArray()
        );
        
        $invoiceResults = [];
        
        // Invoice platform fee
        if (isset($feeResult['platform_fee']) && $feeResult['platform_fee'] > 0) {
            $result = $this->settlement->invoiceFee(
                $pool->getReference() ?? uniqid(),
                'VOUCHMORPH',
                1,
                'PLATFORM_FEE',
                $feeResult['platform_fee'],
                $pool->getCurrency() ?? 'BWP'
            );
            $invoiceResults[] = $result;
        }
        
        // Invoice each source
        foreach ($feeResult['source_fees'] ?? [] as $sourceFee) {
            $result = $this->settlement->invoiceFee(
                $pool->getReference() ?? uniqid(),
                $sourceFee['institution'],
                0,
                'SOURCE_FEE',
                $sourceFee['amount'],
                $pool->getCurrency() ?? 'BWP'
            );
            $invoiceResults[] = $result;
        }
        
        return $invoiceResults;
    }

    private function rollback(?FundingPool $pool): void
    {
        if ($pool !== null) {
            try {
                $this->poolRepository->updateStatus($pool->getPoolId(), PoolStatus::FAILED);
                $this->logger->warning('Pool rolled back', ['pool_id' => $pool->getPoolId()]);
            } catch (Exception $e) {
                $this->logger->error('Rollback failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function buildResponse(FundingPool $pool, array $contributions, array $destinationResult, array $settlementResult, array $invoiceResult): array
    {
        return [
            'success' => true,
            'pool_id' => $pool->getPoolId(),
            'reference' => $pool->getReference(),
            'status' => PoolStatus::COMPLETED->value,
            'total_amount' => $pool->getTotalAmount(),
            'currency' => $pool->getCurrency() ?? 'BWP',
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
            'pool_id' => $pool->getPoolId(),
            'status' => $pool->getStatus()->value ?? PoolStatus::UNKNOWN->value,
            'amount' => $pool->getTotalAmount() ?? 0,
            'currency' => $pool->getCurrency() ?? 'BWP',
            'created_at' => $pool->getCreatedAt() ?? null,
            'updated_at' => $pool->getUpdatedAt() ?? null
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
            'status' => PoolStatus::CANCELLED->value,
            'reason' => $reason
        ];
    }
}
