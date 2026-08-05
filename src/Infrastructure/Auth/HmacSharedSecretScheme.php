<?php
declare(strict_types=1);

namespace Infrastructure\Auth\Schemes;

use Infrastructure\Auth\AuthSchemeInterface;

final class HmacSharedSecretScheme implements AuthSchemeInterface
{
    private int $validityWindow = 300;

    public function name(): string { return 'HMAC_SHARED_SECRET'; }

    public function verify(array $headers, string $rawBody, array $context): bool
    {
        $headersLower = array_change_key_case($headers, CASE_LOWER);
        $timestampHeader = strtolower($context['timestamp_header'] ?? 'x-api-timestamp');
        $signatureHeader = strtolower($context['signature_header'] ?? 'x-api-signature');

        $timestamp = $headersLower[$timestampHeader] ?? null;
        $signature = $headersLower[$signatureHeader] ?? null;
        $secret = $context['secret'] ?? '';

        if (!$timestamp || !$signature || !$secret) return false;
        if (abs(time() - (int)$timestamp) > $this->validityWindow) return false;

        $expected = hash_hmac('sha256', $timestamp . $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public function sign(string $rawBody, array $context): array
    {
        $timestamp = time();
        $secret = $context['secret'] ?? '';
        $signature = hash_hmac('sha256', $timestamp . $rawBody, $secret);
        $timestampHeader = $context['timestamp_header'] ?? 'X-Api-Timestamp';
        $signatureHeader = $context['signature_header'] ?? 'X-Api-Signature';

        return [
            $timestampHeader => (string)$timestamp,
            $signatureHeader => $signature,
        ];
    }
}
