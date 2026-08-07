<?php
declare(strict_types=1);

namespace Infrastructure\Auth\Schemes;

use Infrastructure\Auth\AuthSchemeInterface;

final class ApiKeyScheme implements AuthSchemeInterface
{
    public function name(): string { return 'API_KEY'; }

    public function verify(array $headers, string $rawBody, array $context): bool
    {
        $headerName = strtolower($context['header_name'] ?? 'x-api-key');
        $expected = $context['expected_key'] ?? '';
        $headersLower = array_change_key_case($headers, CASE_LOWER);
        $provided = $headersLower[$headerName] ?? '';

        if ($expected === '' || $provided === '') return false;
        return hash_equals($expected, $provided);
    }

    public function sign(string $rawBody, array $context): array
    {
        $headerName = $context['header_name'] ?? 'X-API-Key';
        return [$headerName => $context['key'] ?? ''];
    }
}
