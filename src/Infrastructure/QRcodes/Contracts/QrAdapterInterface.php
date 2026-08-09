<?php
declare(strict_types=1);

namespace Infrastructure\QRcodes\Contracts;

/**
 * Implemented per QR spec (e.g. one per card-hook format, one per any
 * future spec) and registered into QrCodeService via registerAdapter().
 *
 * IMPORTANT — how QrCodeService actually uses this (see its decode()):
 * it tries each registered adapter's matches() in turn; on the first
 * match it calls decode(); if decode() THROWS, QrCodeService logs it
 * and moves on to the next adapter as if this one hadn't matched at
 * all — the thrown exception's message never reaches the caller. Only
 * use matches()/decode() throwing for "this adapter picked the wrong
 * spec by mistake." For a validation failure that's still genuinely
 * this spec (bad signature, expired code, tampered data), decode()
 * should return a QrPayload describing the failure — via its own
 * $data shape, e.g. a 'valid' => false + 'reason' => '...' pair — so
 * the specific reason survives to reach the person scanning the code.
 */
interface QrAdapterInterface
{
    /**
     * Structural check only: "does this raw string look like MY
     * spec's format?" Should NOT perform cryptographic or expiry
     * validation — that belongs in decode(), expressed through the
     * returned payload, not through matches() returning false.
     */
    public function matches(string $rawQrString): bool;

    /**
     * Parse a raw QR string into a QrPayload. Throw only when the
     * input doesn't actually fit this spec's shape despite matches()
     * saying yes (rare — usually means matches() was too loose).
     * Otherwise, always return a QrPayload, using its $data to convey
     * success or a specific validation failure.
     */
    public function decode(string $rawQrString): QrPayload;

    /**
     * Encode a QrPayload back into this spec's raw QR string.
     */
    public function encode(QrPayload $payload): string;

    /**
     * A unique, human-readable name for this spec (e.g.
     * 'VOUCHMORPH_HOOK_V1'). QrCodeService::encode() selects the
     * adapter to use by matching this against its $preferredSpec
     * argument.
     */
    public function getSpecName(): string;
}
