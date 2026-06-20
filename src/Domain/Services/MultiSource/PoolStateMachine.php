<?php
declare(strict_types=1);

namespace Domain\Services\MultiSource;

use Domain\Models\FundingPool;
use Domain\ValueObjects\PoolStatus;
use RuntimeException;

class PoolStateMachine
{
    private array $transitions = [
        PoolStatus::CREATED->value => [
            PoolStatus::VERIFYING->value,
            PoolStatus::CANCELLED->value
        ],
        PoolStatus::VERIFYING->value => [
            PoolStatus::HOLDING->value,
            PoolStatus::FAILED->value,
            PoolStatus::CANCELLED->value
        ],
        PoolStatus::HOLDING->value => [
            PoolStatus::FUNDED->value,
            PoolStatus::FAILED->value,
            PoolStatus::ROLLED_BACK->value
        ],
        PoolStatus::FUNDED->value => [
            PoolStatus::DESTINATION_PENDING->value,
            PoolStatus::FAILED->value
        ],
        PoolStatus::DESTINATION_PENDING->value => [
            PoolStatus::DESTINATION_COMPLETED->value,
            PoolStatus::FAILED->value
        ],
        PoolStatus::DESTINATION_COMPLETED->value => [
            PoolStatus::DEBITING->value,
            PoolStatus::FAILED->value
        ],
        PoolStatus::DEBITING->value => [
            PoolStatus::SETTLING->value,
            PoolStatus::FAILED->value,
            PoolStatus::ROLLED_BACK->value
        ],
        PoolStatus::SETTLING->value => [
            PoolStatus::INVOICING->value,
            PoolStatus::FAILED->value
        ],
        PoolStatus::INVOICING->value => [
            PoolStatus::COMPLETED->value,
            PoolStatus::FAILED->value
        ],
        PoolStatus::COMPLETED->value => [],
        PoolStatus::FAILED->value => [],
        PoolStatus::CANCELLED->value => [],
        PoolStatus::ROLLED_BACK->value => []
    ];

    public function transition(FundingPool $pool, PoolStatus $newStatus, array $metadata = []): void
    {
        $currentStatus = $pool->getStatus();

        if (!$this->canTransition($currentStatus, $newStatus)) {
            throw new RuntimeException(
                "Invalid state transition from {$currentStatus->value} to {$newStatus->value}"
            );
        }

        $pool->setStatus($newStatus);
        $pool->setMetadata(array_merge($pool->getMetadata(), $metadata));

        error_log(sprintf(
            "[PoolStateMachine] Pool %s: %s → %s",
            $pool->getPoolId(),
            $currentStatus->value,
            $newStatus->value
        ));
    }

    public function canTransition(PoolStatus $current, PoolStatus $new): bool
    {
        $allowed = $this->transitions[$current->value] ?? [];
        return in_array($new->value, $allowed);
    }
}
