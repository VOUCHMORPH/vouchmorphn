<?php
declare(strict_types=1);

namespace Infrastructure\Messaging;

/**
 * Current, working format. Every existing participant (ZURUBANK, ABSA,
 * SACCUSSALIS, CAZACOM, MTN, CENTRALSWITCH) already speaks this - this
 * class exists so "JSON" is a real, selectable option alongside future
 * formats, not so it changes anything about today's behavior.
 */
final class JsonMessageFormatter implements MessageFormatterInterface
{
    public function name(): string { return 'JSON'; }

    public function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    public function decode(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Response was not valid JSON: ' . substr($raw, 0, 200));
        }
        return $data;
    }

    public function contentType(): string { return 'application/json'; }
}
