<?php
declare(strict_types=1);

namespace Infrastructure\Messaging;

final class MessageFormatterFactory
{
    public static function forConfig(array $config): MessageFormatterInterface
    {
        $format = strtoupper($config['message_format'] ?? 'JSON');

        return match ($format) {
            'JSON' => new JsonMessageFormatter(),
            'ISO20022' => new Iso20022MessageFormatter(),
            default => throw new \RuntimeException("Unknown message_format: {$format}"),
        };
    }
}
