<?php
declare(strict_types=1);

namespace Infrastructure\USSD\Contracts;

/**
 * Normalized inbound USSD request - identical shape regardless of gateway.
 */
final class UssdSessionRequest
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $phoneNumber,   // raw, uncleaned - business logic cleans it
        public readonly string $text,          // full input string, e.g. "1*2*500"
        public readonly array $rawPayload = [] // original payload, for gateway-specific edge cases
    ) {}

    /** Convenience: last-entered value only (what AT calls "text" at current level) */
    public function levels(): array
    {
        return $this->text === '' ? [] : explode('*', trim($this->text, '*'));
    }
}
