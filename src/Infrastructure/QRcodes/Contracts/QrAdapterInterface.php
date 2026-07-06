<?php
declare(strict_types=1);

namespace Infrastructure\QRcodes\Contracts;

/**
 * Normalized QR payload - what SwapService/CardService actually consumes.
 * Never institution-specific field names past this point.
 */
final class QrPayload
{
    public function __construct(
        public readonly string $qrType,          // 'STATIC' | 'DYNAMIC'
        public readonly string $merchantOrPayeeId,
        public readonly ?float $amount,          // null for static/open-amount QR
        public readonly string $currency,
        public readonly ?string $reference,
        public readonly string $institution,     // resolved participant code
        public readonly array $raw = []           // original decoded fields, for audit
    ) {}
}

interface QrAdapterInterface
{
    /**
     * Decode a raw QR string (as scanned) into a normalized payload.
     * Throws if the QR doesn't match this adapter's spec (lets the
     * QrCodeService try the next registered adapter).
     */
    public function decode(string $rawQrString): QrPayload;

    /**
     * Encode a normalized payload into this adapter's QR wire format
     * (the string that then gets rendered as an actual QR image).
     */
    public function encode(QrPayload $payload): string;

    /**
     * Quick format sniff without fully decoding - used by QrCodeService
     * to pick the right adapter without throwing on every wrong guess.
     */
    public function matches(string $rawQrString): bool;

    public function getSpecName(): string; // 'EMVCO', 'MOJALOOP', 'PROPRIETARY_MPESA', etc.
}
