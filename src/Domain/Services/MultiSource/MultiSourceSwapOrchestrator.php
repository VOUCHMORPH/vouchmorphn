<?php
declare(strict_types=1);

namespace Domain\Services\MultiSource;

use PDO;
use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Crypto\AggregateSigner;
use Psr\Log\LoggerInterface;

/**
 * Multi-Source Swap Orchestrator
 * 
 * This is the ENTRY POINT for multi-source swaps.
 * It delegates to PoolCoordinator and returns results.
 */
class MultiSourceSwapOrchestrator
{
    private PoolCoordinator $coordinator;
    private LoggerInterface $logger;

    public function __construct(
        PDO $db,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        AggregateSigner $aggregateSigner,
        array $config,
        string $countryCode,
        LoggerInterface $logger
    ) {
        $this->coordinator = new PoolCoordinator(
            $db,
            $swapService,
            $settlement,
            $aggregateSigner,
            $config,
            $countryCode,
            $logger
        );
        $this->logger = $logger;
    }

    public function execute(array $payload): array
    {
        $this->logger->info('Starting multi-source swap', [
            'source_count' => count($payload['sources'] ?? []),
            'amount' => $payload['amount'] ?? 0
        ]);

        return $this->coordinator->execute($payload);
    }

    public function getStatus(string $poolId): array
    {
        return $this->coordinator->getStatus($poolId);
    }

    public function cancel(string $poolId, string $reason): array
    {
        return $this->coordinator->cancel($poolId, $reason);
    }
}
