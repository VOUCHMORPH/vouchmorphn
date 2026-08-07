<?php
declare(strict_types=1);

namespace Infrastructure\Messaging;

/**
 * How a request/response body is serialized on the wire - completely
 * separate from HOW it's authenticated (see Infrastructure\Auth\AuthSchemeInterface).
 * A real switch could require ISO 20022 XML + mTLS, or JSON + HMAC, or
 * any other combination - these two axes never depend on each other,
 * so they're never allowed to know about each other in code either.
 */
interface MessageFormatterInterface
{
    /** Unique identifier, e.g. 'JSON', 'ISO20022' */
    public function name(): string;

    /** Serialize an outgoing payload to wire format. */
    public function encode(array $payload): string;

    /** Parse an incoming wire-format response back into an array. */
    public function decode(string $raw): array;

    /** The Content-Type header value this format expects. */
    public function contentType(): string;
}
