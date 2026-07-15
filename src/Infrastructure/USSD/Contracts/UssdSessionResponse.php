<?php
declare(strict_types=1);

namespace Infrastructure\USSD\Contracts;

/**
 * Normalized outbound USSD response - the menu state machine builds this,
 * the gateway adapter formats it into wire format.
 */
final class UssdSessionResponse
{
    public function __construct(
        public readonly string $message,
        public readonly bool $continueSession, // true = CON (expect more input), false = END
    ) {}

    public static function continue(string $message): self
    {
        return new self($message, true);
    }

    public static function end(string $message): self
    {
        return new self($message, false);
    }
}
