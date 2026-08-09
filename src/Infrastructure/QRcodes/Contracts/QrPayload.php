<?php
declare(strict_types=1);

namespace Infrastructure\QRcodes\Contracts;

/**
 * A decoded (or pre-encode) QR payload — spec-agnostic on purpose, so
 * QrCodeService never needs to know what any given QR spec's fields
 * mean.
 *
 * $type — a short tag naming what kind of QR this is within its own
 *   spec (e.g. 'hook' for a VouchMorph Card hook QR). Each adapter
 *   defines its own vocabulary for this; QrCodeService doesn't
 *   interpret it.
 *
 * $data — spec-specific fields, entirely up to the adapter. QrCodeService
 *   never reads $data itself — only the adapter that produced/consumes
 *   a given spec does.
 */
final class QrPayload
{
    public function __construct(
        public readonly string $type,
        public readonly array $data = []
    ) {
    }
}
