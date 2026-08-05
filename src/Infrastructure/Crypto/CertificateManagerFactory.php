<?php
// src/Infrastructure/Crypto/CertificateManagerFactory.php
declare(strict_types=1);

namespace Infrastructure\Crypto;

/**
 * Both SwapService and GenericBankClient independently instantiate
 * CertificateManager('VOUCHMORPH'), each re-reading identical env
 * vars/cert files per request. This is a single-process, single-request
 * cache — not a security concern (same process, same trust boundary),
 * just avoids redundant file/env I/O.
 */
final class CertificateManagerFactory
{
    private static array $instances = [];

    public static function get(string $partnerName = 'VOUCHMORPH'): CertificateManager
    {
        if (!isset(self::$instances[$partnerName])) {
            self::$instances[$partnerName] = new CertificateManager($partnerName);
        }
        return self::$instances[$partnerName];
    }
}
