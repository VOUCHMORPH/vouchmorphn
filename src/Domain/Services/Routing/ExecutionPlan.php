<?php
declare(strict_types=1);

namespace Domain\Services\Routing;

/**
 * The output of route resolution. SwapService (or whoever calls the
 * resolver) doesn't need to know WHY this plan was chosen - only what
 * to do with it.
 */
final class ExecutionPlan
{
    public const MODE_DIRECT = 'DIRECT';
    public const MODE_SWITCH = 'SWITCH';
    public const MODE_UNROUTABLE = 'UNROUTABLE';

    public function __construct(
        public readonly string $mode,               // DIRECT | SWITCH | UNROUTABLE
        public readonly ?string $rail = null,        // e.g. 'KWIK', null for DIRECT
        public readonly bool $reservationRequired = true,
        public readonly ?string $fallbackMode = null, // e.g. self::MODE_DIRECT
        public readonly string $reason = '',          // human-readable, for logs/audit
    ) {}

    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'rail' => $this->rail,
            'reservation_required' => $this->reservationRequired,
            'fallback_mode' => $this->fallbackMode,
            'reason' => $this->reason,
        ];
    }
}
