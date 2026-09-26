<?php
declare(strict_types=1);

namespace Domain\Services;

use RuntimeException;

/**
 * A source the requester has not proved is theirs, or an identifier that
 * cannot be an account at all. Raised by SourceOwnershipGuard before any
 * verify, hold or debit, so nothing has moved when it is thrown.
 *
 * The message is written for the customer and is safe to show them: it
 * names the institution and at most the last four characters of the
 * identifier.
 */
final class SourceOwnershipException extends RuntimeException
{
}
