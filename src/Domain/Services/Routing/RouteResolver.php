<?php
declare(strict_types=1);

namespace Domain\Services\Routing;

/**
 * Turns (source, destination, operation) into an ExecutionPlan.
 * Pure decision logic - no I/O to banks or switches happens here.
 *
 * Health checking is intentionally NOT implemented yet (see
 * RailHealthServiceInterface below) - until we know Kwik's real health
 * signal (poll endpoint? webhook heartbeat? timeout-based inference?),
 * a fake health check would just be guessing. For now every SWITCH plan
 * always includes a DIRECT fallbackMode, and the caller (DirectExecutionStrategy
 * vs SwitchExecutionStrategy) decides what to do if the switch call fails.
 */
final class RouteResolver
{
    public function __construct(
        private array $policy,          // from RoutingPolicyConfig::load()
        private ?RailHealthServiceInterface $health = null,
    ) {}

    public function resolve(
        string $sourceInstitution,
        string $destinationInstitution,
        string $operation, // 'DEPOSIT' | 'CASHOUT' | 'VOUCHER' | 'IDENTITY' | 'STANDARD' | 'MULTI_SOURCE' | 'MULTI_DESTINATION'
    ): ExecutionPlan {
        $operation = strtoupper($operation);

        $opPolicy = $this->policy['operation_policy'][$operation] ?? null;

        // No policy for this operation at all -> DIRECT, reservation on by
        // default (safer default: assume delayed execution unless told otherwise).
        if ($opPolicy === null) {
            return new ExecutionPlan(
                mode: ExecutionPlan::MODE_DIRECT,
                reservationRequired: true,
                reason: "No routing policy defined for operation {$operation}; defaulting to DIRECT.",
            );
        }

        $reservationRequired = (bool)($opPolicy['reservation'] ?? true);
        $preferredRails = $opPolicy['preferred'] ?? ['DIRECT'];

        foreach ($preferredRails as $rail) {
            if ($rail === 'DIRECT') {
                return new ExecutionPlan(
                    mode: ExecutionPlan::MODE_DIRECT,
                    reservationRequired: $reservationRequired,
                    reason: "DIRECT selected (either preferred, or no switch rail matched).",
                );
            }

            if ($this->railSupportsRoute($rail, $sourceInstitution, $destinationInstitution, $operation)) {
                if ($this->health && !$this->health->isAvailable($rail)) {
                    // Known-down: skip to next preferred rail (usually DIRECT).
                    continue;
                }

                return new ExecutionPlan(
                    mode: ExecutionPlan::MODE_SWITCH,
                    rail: $rail,
                    reservationRequired: $reservationRequired,
                    fallbackMode: ExecutionPlan::MODE_DIRECT,
                    reason: "{$rail} supports {$operation} for both {$sourceInstitution} and {$destinationInstitution}.",
                );
            }
        }

        // Nothing in the preferred list worked and DIRECT wasn't reachable either
        // (e.g. institution has zero rails registered) -> unroutable.
        if (!$this->railSupportsRoute('DIRECT', $sourceInstitution, $destinationInstitution, $operation)) {
            return new ExecutionPlan(
                mode: ExecutionPlan::MODE_UNROUTABLE,
                reason: "No compatible rail found for {$sourceInstitution} -> {$destinationInstitution} ({$operation}).",
            );
        }

        return new ExecutionPlan(
            mode: ExecutionPlan::MODE_DIRECT,
            reservationRequired: $reservationRequired,
            reason: "Fell through to DIRECT as last resort.",
        );
    }

    private function railSupportsRoute(string $rail, string $source, string $dest, string $operation): bool
    {
        $sourceRails = $this->policy['participant_rails'][$source] ?? ['DIRECT'];
        $destRails = $this->policy['participant_rails'][$dest] ?? ['DIRECT'];

        if (!in_array($rail, $sourceRails, true) || !in_array($rail, $destRails, true)) {
            return false;
        }

        if ($rail === 'DIRECT') {
            return true; // DIRECT has no per-operation capability gate today
        }

        $opKey = strtolower($operation);
        $sourceOps = $this->policy['participant_ops'][$source] ?? [];
        $destOps = $this->policy['participant_ops'][$dest] ?? [];

        return ($sourceOps[$opKey] ?? false) && ($destOps[$opKey] ?? false);
    }
}
