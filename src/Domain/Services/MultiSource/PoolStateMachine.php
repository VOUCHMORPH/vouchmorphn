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
     * 
     * Note: Status strings must fit in the database column (varchar(20))
     * PENDING_ID_CLAIM (16 chars) instead of PENDING_IDENTITY_CLAIM (23 chars)
     */
    private array $transitions = [
        'CREATED' => ['VERIFYING', 'CANCELLED'],
        'VERIFYING' => ['HOLDING', 'FAILED', 'CANCELLED'],
        'HOLDING' => ['FUNDED', 'FAILED', 'ROLLED_BACK'],
        'FUNDED' => ['DESTINATION_PENDING', 'FAILED'],
        'DESTINATION_PENDING' => ['DESTINATION_COMPLETED', 'PENDING_CASHOUT', 'PENDING_ID_CLAIM', 'FAILED'],
        'DESTINATION_COMPLETED' => ['DEBITING', 'FAILED'],
        'DEBITING' => ['SETTLING', 'FAILED', 'ROLLED_BACK'],
        'SETTLING' => ['INVOICING', 'FAILED'],
        'INVOICING' => ['COMPLETED', 'FAILED'],
        'COMPLETED' => [],
        'FAILED' => [],
        'CANCELLED' => [],
        'ROLLED_BACK' => [],
        'PENDING_CASHOUT' => ['DEBITING', 'FAILED', 'CANCELLED', 'ROLLED_BACK'],
        'PENDING_ID_CLAIM' => ['DEBITING', 'FAILED', 'CANCELLED', 'ROLLED_BACK']
    ];

    public function transition(array &$pool, string $newStatus, array $metadata = []): void
    {
        $currentStatus = $pool['status'] ?? 'CREATED';
        
        if ($currentStatus === $newStatus) {
            return;
        }

        if (!$this->canTransition($currentStatus, $newStatus)) {
            throw new RuntimeException(
                "Invalid state transition from {$currentStatus} to {$newStatus}"
            );
        }

        $pool['status'] = $newStatus;
        $pool['updated_at'] = date('Y-m-d H:i:s');
        
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

    public function canTransition(string $current, string $new): bool
    {
        $allowed = $this->transitions[$current] ?? [];
        return in_array($new, $allowed);
    }

    public function getAllowedTransitions(string $current): array
    {
        return $this->transitions[$current] ?? [];
    }

    public function isTerminal(string $state): bool
    {
        return empty($this->transitions[$state] ?? []);
    }

    public function isFailureState(string $state): bool
    {
        return in_array($state, ['FAILED', 'CANCELLED', 'ROLLED_BACK']);
    }

    public function isSuccessState(string $state): bool
    {
        return $state === 'COMPLETED';
    }

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
}
