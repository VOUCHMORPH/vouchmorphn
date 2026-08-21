<?php
declare(strict_types=1);

/**
 * Minimal RFC 6238 TOTP generator — no composer dependency (packagist
 * isn't reachable from this sandbox's network allowlist). Matches
 * PragmaRX\Google2FA's defaults: SHA1, 6 digits, 30-second period.
 *
 * Usage: php totp.php <base32-secret>
 * Prints the current 6-digit code.
 */

function base32Decode(string $b32): string
{
    $b32 = strtoupper(rtrim($b32, '='));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($b32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) continue;
        $bytes .= chr(bindec($byte));
    }
    return $bytes;
}

function totp(string $base32Secret, int $period = 30, int $digits = 6, ?int $time = null): string
{
    $time = $time ?? time();
    $counter = intdiv($time, $period);
    $key = base32Decode($base32Secret);
    $counterBytes = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter

    $hash = hash_hmac('sha1', $counterBytes, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);

    $code = $binary % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    echo totp($argv[1]) . "\n";
}
