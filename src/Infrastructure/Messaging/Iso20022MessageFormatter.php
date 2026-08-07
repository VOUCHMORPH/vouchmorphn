<?php
declare(strict_types=1);

namespace Infrastructure\Messaging;

/**
 * ============================================================================
 * NOT YET IMPLEMENTED - READ BEFORE FILLING THIS IN
 * ============================================================================
 * This is deliberately a stub, not a best-effort guess. ISO 20022 covers a
 * large family of message types (pain.001 for credit transfer initiation,
 * pacs.008 for interbank credit transfer, pacs.002 for status reports,
 * camt.05x for cash management/reporting, and many more) - each with its
 * own XML schema, namespace, and required fields. Which ones a real switch
 * actually uses, in what combination, with which local customizations
 * (many national switches use a country-specific ISO 20022 profile, not
 * the raw ISO base schema), can only come from that switch operator's own
 * integration specification.
 *
 * Building a "looks complete" implementation without that spec would bake
 * in wrong field mappings and namespace assumptions that fail silently in
 * production - worse than this loud, honest stub. When you have the real
 * spec in hand:
 *   1. Confirm exactly which message type(s) apply to submitTransfer /
 *      create_quote / transaction_status (likely pacs.008 for the
 *      transfer itself, pacs.002 for the status response - but confirm,
 *      don't assume).
 *   2. Implement encode()/decode() against that exact schema.
 *   3. Remove this docblock once real logic replaces the exceptions below.
 * ============================================================================
 */
final class Iso20022MessageFormatter implements MessageFormatterInterface
{
    public function name(): string { return 'ISO20022'; }

    public function encode(array $payload): string
    {
        throw new \RuntimeException(
            'ISO20022MessageFormatter::encode() is not implemented - no real message ' .
            'spec has been provided yet. See the class docblock before implementing this ' .
            'against a guessed schema.'
        );
    }

    public function decode(string $raw): array
    {
        throw new \RuntimeException(
            'ISO20022MessageFormatter::decode() is not implemented - no real message ' .
            'spec has been provided yet. See the class docblock before implementing this ' .
            'against a guessed schema.'
        );
    }

    public function contentType(): string { return 'application/xml'; }
}
