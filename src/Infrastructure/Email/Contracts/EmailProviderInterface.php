<?php
declare(strict_types=1);

namespace Infrastructure\Email\Contracts;

/**
 * Contract for any email-sending provider.
 * Mirrors Infrastructure\SMS\Contracts\ProviderInterface so email and SMS
 * adapters can be wired through the same CommunicationFactory pattern.
 */
interface EmailProviderInterface
{
    /**
     * Send a single transactional email.
     *
     * @return array{success: bool, message: string}
     */
    public function sendEmail(string $to, string $subject, string $htmlBody): array;

    /**
     * Whether this provider has everything it needs (host, credentials, etc.)
     * to actually attempt a send. Callers should check this before relying
     * on the provider rather than discovering it fails at send time.
     */
    public function isConfigured(): bool;
}
