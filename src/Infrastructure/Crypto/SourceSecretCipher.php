<?php
declare(strict_types=1);

namespace Infrastructure\Crypto;

/**
 * Shared AES-256-CBC helper for reversible secrets (source access/refresh
 * tokens, identity claim PINs). Extracted so read-only endpoints (history,
 * details) can decrypt without instantiating the full SwapService.
 */
class SourceSecretCipher
{
    public static function encrypt(?string $plaintext): ?string
    {
        if (empty($plaintext)) return null;
        $key = getenv('VOUCHMORPH_TOKEN_ENC_KEY');
        if (!$key) { error_log("[SourceSecretCipher] VOUCHMORPH_TOKEN_ENC_KEY not set"); return null; }
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, 0, $iv);
        return $encrypted === false ? null : base64_encode($iv . $encrypted);
    }

    public static function decrypt(?string $encrypted): ?string
    {
        if (empty($encrypted)) return null;
        $key = getenv('VOUCHMORPH_TOKEN_ENC_KEY');
        if (!$key) { error_log("[SourceSecretCipher] VOUCHMORPH_TOKEN_ENC_KEY not set"); return null; }
        $data = base64_decode($encrypted);
        if ($data === false || strlen($data) < 16) return null;
        $decrypted = openssl_decrypt(substr($data, 16), 'AES-256-CBC', $key, 0, substr($data, 0, 16));
        return $decrypted === false ? null : $decrypted;
    }
}
