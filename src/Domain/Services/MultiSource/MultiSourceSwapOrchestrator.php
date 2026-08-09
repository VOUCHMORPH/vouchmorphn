<?php
declare(strict_types=1);

namespace Domain\Services\MultiSource;

use PDO;
use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Crypto\AggregateSigner;

/**
 * Multi-Source Swap Orchestrator
 * 
 * This is the ENTRY POINT for multi-source swaps.
 * It delegates to PoolCoordinator and returns results.
 */
class MultiSourceSwapOrchestrator
{
    private PoolCoordinator $coordinator;
    private $logger;

    public function __construct(
        PDO $db,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        AggregateSigner $aggregateSigner,
        array $config,
        string $countryCode,
        $logger = null
    ) {
        // Use a default logger if none provided
        if ($logger === null) {
            $this->logger = new class {
                public function emergency($message, array $context = []) { error_log("[MULTI] EMERGENCY: " . $message); }
                public function alert($message, array $context = []) { error_log("[MULTI] ALERT: " . $message); }
                public function critical($message, array $context = []) { error_log("[MULTI] CRITICAL: " . $message); }
                public function error($message, array $context = []) { error_log("[MULTI] ERROR: " . $message); }
                public function warning($message, array $context = []) { error_log("[MULTI] WARNING: " . $message); }
                public function notice($message, array $context = []) { error_log("[MULTI] NOTICE: " . $message); }
                public function info($message, array $context = []) { error_log("[MULTI] INFO: " . $message . " " . json_encode($context)); }
                public function debug($message, array $context = []) { error_log("[MULTI] DEBUG: " . $message); }
                public function log($level, $message, array $context = []) { error_log("[MULTI] " . $level . ": " . $message); }
            };
        } else {
            $this->logger = $logger;
        }
        
        $this->coordinator = new PoolCoordinator(
            $db,
            $swapService,
            $settlement,
            $aggregateSigner,
            $config,
            $countryCode,
            $this->logger
        );
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
    public function confirmPoolCashout(string $poolId, array $confirmationPayload = []): array
{
    return $this->coordinator->confirmPoolCashout($poolId, $confirmationPayload);
}

public function confirmPoolIdentityClaim(string $poolId): array
{
    return $this->coordinator->confirmPoolIdentityClaim($poolId);
}
}
