<?php
declare(strict_types=1);

namespace Domain\Services\Routing;

use Domain\Services\SwapService;

/**
 * Wraps the EXISTING SwapService flow unchanged. This is what an
 * ExecutionPlan with mode=DIRECT gets handed to. Zero behavior change
 * from what SwapService already does today.
 */
final class DirectExecutionStrategy implements ExecutionStrategyInterface
{
    public function __construct(private SwapService $swapService) {}

    public function execute(array $payload, ExecutionPlan $plan): array
    {
        // Nothing about verify/hold/deposit/cashout/debit changes.
        // reservationRequired on the plan is informational here - DIRECT
        // already reservation-gates per operation type inside SwapService
        // itself (executeSignedCashout always holds, executeSignedDeposit
        // always holds pre-debit, etc.) so we don't need to thread it
        // through yet. Once a SWITCH strategy exists that genuinely skips
        // the hold, THIS is where reservationRequired starts mattering for
        // DIRECT too (e.g. to decide skip-hold on synchronous deposit paths).
        return $this->swapService->executeAtomicSwap($payload);
    }
}

interface ExecutionStrategyInterface
{
    public function execute(array $payload, ExecutionPlan $plan): array;
}
