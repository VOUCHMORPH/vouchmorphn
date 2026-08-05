<?php
declare(strict_types=1);

namespace Domain\Services\Routing\Exceptions;

/**
 * Thrown when a switch rail is genuinely unreachable (network failure,
 * timeout, connection refused) — as opposed to the switch responding
 * with an authoritative rejection (insufficient funds, bad participant,
 * AML hold). Only THIS exception should trigger a fallback to DIRECT;
 * a real rejection from the switch must propagate as a normal failure.
 */
class SwitchUnavailableException extends \RuntimeException
{
}
