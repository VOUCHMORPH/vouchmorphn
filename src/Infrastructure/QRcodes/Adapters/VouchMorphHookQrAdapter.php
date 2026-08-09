<?php
declare(strict_types=1);

namespace Infrastructure\QRcodes\Adapters;

use Infrastructure\QRcodes\Contracts\QrAdapterInterface;
use Infrastructure\QRcodes\Contracts\QrPayload;
use RuntimeException;

/**
 * VOUCHMORPH_HOOK_V1
 *
 * The QR printed on the back of a physical VouchMorph Card. Its ONLY
 * job is to identify which card_suffix someone is pointing their
 * camera at — it is NOT a bearer credential and does not itself move
 * money. Whoever scans it still goes through the normal
 * hookSourcesToCard() consent/verify/hold pipeline afterward, exactly
 * as if they'd typed the card_suffix in by hand. Because of that, this
 * QR is safe to have a long expiry (it's printed on plastic, it can't
 * be reissued daily) — the real security boundary lives downstream, in
 * CardService::hookSourcesToCard()'s consent gate.
 *
 * Wire format (before HMAC): {"t":"hook","v":1,"suf":"<card_suffix>","exp":<unix>}
 * Encoded string: base64url(json) + "." + hex(hmac_sha256(json, key))
 *
 * ERROR HANDLING — read before touching this class:
 * matches() ONLY checks the structural shape (t/v present and correct).
 * decode() NEVER throws for a bad signature or an expired code — it
 * returns a QrPayload with data['valid'] = false and data['reason']
 * set, so the caller (see ResolveQr.php) can show the person a real
 * reason. This is deliberate: QrCodeService::decode() swallows any
 * exception a matched adapter's decode() throws and falls through to
 * a generic "not recognized" error — throwing here for a tampered or
 * expired code would silently destroy that information. Only throw
 * from decode() when the payload doesn't even structurally fit this
 * spec despite matches() saying yes (i.e. matches() was wrong).
 */
class VouchMorphHookQrAdapter implements QrAdapterInterface
{
    private const SPEC_NAME = 'VOUCHMORPH_HOOK_V1';

    private string $signingKey;

    public function __construct(?string $signingKey = null)
    {
        $key = $signingKey
            ?? (class_exists('Security\Encryption\KeyVault')
                ? (\Security\Encryption\KeyVault::getInstance()->getKey('qr_hook_signing_key') ?? null)
                : null)
            ?? getenv('QR_HOOK_SIGNING_KEY');

        if (empty($key) || strlen($key) < 32) {
            throw new RuntimeException(
                'QR hook signing key is missing or too short (min 32 bytes required). ' .
                'Set QR_HOOK_SIGNING_KEY or a KeyVault key named qr_hook_signing_key.'
            );
        }
        $this->signingKey = $key;
    }

    public function getSpecName(): string
    {
        return self::SPEC_NAME;
    }

    /**
     * Structural check only — no signature/expiry validation here.
     * A tampered or expired hook QR still `matches()` (it IS a hook
     * QR, just an invalid one) so decode() gets the chance to report
     * the specific reason rather than QrCodeService silently trying
     * other adapters and giving up with a generic error.
     */
    public function matches(string $rawQrString): bool
    {
        return $this->tryParseStructure($rawQrString) !== null;
    }

    public function decode(string $rawQrString): QrPayload
    {
        $parsed = $this->tryParseStructure($rawQrString);
        if ($parsed === null) {
            // matches() should have prevented this, but stay defensive:
            // this genuinely isn't a well-formed hook QR at all.
            throw new RuntimeException('Not a valid VouchMorph hook QR structure.');
        }
        [$json, $sigHex] = $parsed;
        $data = json_decode($json, true);

        if (empty($data['suf'])) {
            throw new RuntimeException('Hook QR is missing its card_suffix field.');
        }

        $cardSuffix = $data['suf'];
        $expiry = $data['exp'] ?? null;

        $expectedSig = hash_hmac('sha256', $json, $this->signingKey);
        if (!hash_equals($expectedSig, strtolower($sigHex))) {
            return new QrPayload('hook', [
                'valid' => false,
                'reason' => 'signature_mismatch',
                'card_suffix' => $cardSuffix, // not secret — it's printed on the card itself
            ]);
        }

        if ($expiry !== null && (int)$expiry < time()) {
            return new QrPayload('hook', [
                'valid' => false,
                'reason' => 'expired',
                'card_suffix' => $cardSuffix,
                'expired_at' => (int)$expiry,
            ]);
        }

        return new QrPayload('hook', [
            'valid' => true,
            'card_suffix' => $cardSuffix,
            'expires_at' => $expiry,
        ]);
    }

    public function encode(QrPayload $payload): string
    {
        $data = $payload->data;

        if (empty($data['card_suffix'])) {
            throw new RuntimeException('card_suffix required to encode a hook QR.');
        }

        $expiry = $data['expires_at'] ?? strtotime('+10 years');

        $json = json_encode([
            't' => 'hook',
            'v' => 1,
            'suf' => $data['card_suffix'],
            'exp' => $expiry,
        ]);

        $sig = hash_hmac('sha256', $json, $this->signingKey);

        return $this->base64UrlEncode($json) . '.' . $sig;
    }

    /**
     * Parses only as far as confirming this LOOKS like a hook QR
     * (correct top-level shape: base64.hex, with t=hook v=1 inside).
     * Does not verify signature or expiry — see matches()/decode()
     * docs above for why that split matters.
     *
     * @return array{0: string, 1: string}|null [json, sigHex] or null if malformed
     */
    private function tryParseStructure(string $rawQrString): ?array
    {
        $parts = explode('.', $rawQrString, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }

        $json = $this->base64UrlDecode($parts[0]);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded['t'] ?? null) !== 'hook' || ($decoded['v'] ?? null) !== 1) {
            return null;
        }

        return [$json, $parts[1]];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        $padded = $remainder ? strtr($data, '-_', '+/') . str_repeat('=', 4 - $remainder) : strtr($data, '-_', '+/');
        $decoded = base64_decode($padded, true);
        return $decoded === false ? '' : $decoded;
    }
}
