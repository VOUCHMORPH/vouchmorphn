<?php
declare(strict_types=1);

namespace Infrastructure\QRcodes;

use Infrastructure\QRcodes\Contracts\QrAdapterInterface;
use Infrastructure\QRcodes\Contracts\QrPayload;
use RuntimeException;

class QrCodeService
{
    /** @var QrAdapterInterface[] */
    private array $adapters = [];

    public function registerAdapter(QrAdapterInterface $adapter): void
    {
        $this->adapters[] = $adapter;
    }

    public function decode(string $rawQrString): QrPayload
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->matches($rawQrString)) {
                try {
                    return $adapter->decode($rawQrString);
                } catch (\Throwable $e) {
                    error_log("[QrCodeService] {$adapter->getSpecName()} matched but decode failed: " . $e->getMessage());
                    continue; // try next adapter rather than fail hard on a false-positive match
                }
            }
        }

        throw new RuntimeException('QR code format not recognized by any registered adapter');
    }

    public function encode(QrPayload $payload, string $preferredSpec): string
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->getSpecName() === $preferredSpec) {
                return $adapter->encode($payload);
            }
        }
        throw new RuntimeException("No QR adapter registered for spec: {$preferredSpec}");
    }
}
