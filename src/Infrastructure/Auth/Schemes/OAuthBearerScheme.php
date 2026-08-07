<?php
declare(strict_types=1);

namespace Infrastructure\Auth\Schemes;

use Infrastructure\Auth\AuthSchemeInterface;

/**
 * Bearer-token verification (OAuth2 client-credentials style, e.g.
 * MTN's real MoMo API, or any bank that issues short-lived tokens
 * rather than a long-lived shared secret). Verification here checks
 * against a cached/introspected token store — the actual
 * token-issuing flow lives elsewhere (source_linking in endpoints.yaml).
 */
final class OAuthBearerScheme implements AuthSchemeInterface
{
    public function name(): string { return 'OAUTH_BEARER'; }

    public function verify(array $headers, string $rawBody, array $context): bool
    {
        $headersLower = array_change_key_case($headers, CASE_LOWER);
        $auth = $headersLower['authorization'] ?? '';
        if (stripos($auth, 'Bearer ') !== 0) return false;
        $token = substr($auth, 7);

        $validate = $context['validate_callback'] ?? null;
        if (!is_callable($validate)) return false;

        return (bool)$validate($token);
    }

    public function sign(string $rawBody, array $context): array
    {
        return ['Authorization' => 'Bearer ' . ($context['access_token'] ?? '')];
    }
}
