<?php
declare(strict_types=1);

namespace Domain\Services\Routing;

/**
 * STUB. Not wired to anything real. Exists so the interface shape is
 * settled before the Kwik meeting - fill in submit()/status() once
 * EMIS confirms whether it's one call or quote->transfer->commit.
 */
final class SwitchExecutionStrategy implements ExecutionStrategyInterface
{
    public function __construct(private string $railName) {}

    public function execute(array $payload, ExecutionPlan $plan): array
    {
        throw new \RuntimeException(
            "SwitchExecutionStrategy for rail '{$this->railName}' is not yet implemented. " .
            "This plan should have had fallbackMode=DIRECT set - the caller " .
            "(not this class) is responsible for catching this and retrying " .
            "via DirectExecutionStrategy."
        );
    }
}
