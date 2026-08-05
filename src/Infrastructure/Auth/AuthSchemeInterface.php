<?php
declare(strict_types=1);

namespace Infrastructure\Auth;

/**
 * A single pluggable authentication scheme. Each scheme knows how to
 * both VERIFY an inbound request claiming to use it, and SIGN an
 * outbound request that needs to use it. One class per scheme —
 * adding a new counterparty's auth style means adding one small
 * class here, never touching the files that receive/send requests.
 */
interface AuthSchemeInterface
{
    /** Unique identifier, e.g. 'API_KEY', 'HMAC_SHARED_SECRET', 'OAUTH_BEARER' */
    public function name(): string;

    /**
     * Verify an inbound request. $context carries whatever this
     * scheme needs (headers, raw body, expected secret/key, etc.).
     * Returns true/false — never throws for "just didn't match",
     * only for genuine misconfiguration.
     */
    public function verify(array $headers, string $rawBody, array $context): bool;

    /**
     * Sign/decorate an outbound request. Returns the headers to add.
     */
    public function sign(string $rawBody, array $context): array;
}
