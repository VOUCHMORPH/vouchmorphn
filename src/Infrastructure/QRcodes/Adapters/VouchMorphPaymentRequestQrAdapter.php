<?php
declare(strict_types=1);

namespace Infrastructure\QRcodes\Adapters;

use Infrastructure\QRcodes\Contracts\QrAdapterInterface;
use Infrastructure\QRcodes\Contracts\QrPayload;
use RuntimeException;

/**
 * VOUCHMORPH_PAYMENT_REQUEST_V1
 *
 * The QR an agent/merchant generates to receive a fixed, specific
 * amount. Unlike VOUCHMORPH_HOOK_V1, this QR does NOT carry the
 * destination account or amount in its payload — only a request_id.
 * The actual amount, destination, and status live server-side in the
 * payment_requests table and are looked up fresh at scan time.
 *
 * Wire format (before HMAC): {"t":"payreq","v":1,"rid":<int>,"exp":<unix>}
 * Encoded string: base64url(json) + "." + hex(hmac_sha256(json, key))
 *
 * See VouchMorphHookQrAdapter's class doc for why decode() never
 * throws on a bad signature or expired code — same discipline here.
 */
class VouchMorphPaymentRequestQrAdapter implements QrAdapterInterface
{
    private const SPEC_NAME = 'VOUCHMORPH_PAYMENT_REQUEST_V1';

    private string $signingKey;

    public function __construct(?string $signingKey = null)
    {
        $key = $signingKey
            ?? (class_exists('Security\Encryption\KeyVault')
                ? (\Security\Encryption\KeyVault::getInstance()->getKey('qr_payment_request_signing_key') ?? null)
                : null)
            ?? getenv('QR_PAYMENT_REQUEST_SIGNING_KEY');

        if (empty($key) || strlen($key) < 32) {
            throw new RuntimeException(
                'QR payment-request signing key is missing or too short (min 32 bytes required). ' .
                'Set QR_PAYMENT_REQUEST_SIGNING_KEY or a KeyVault key named qr_payment_request_signing_key.'
            );
        }
        $this->signingKey = $key;
    }

    public function getSpecName(): string
    {
        return self::SPEC_NAME;
    }

    public function matches(string $rawQrString): bool
    {
        return $this->tryParseStructure($rawQrString) !== null;
    }

    public function decode(string $rawQrString): QrPayload
    {
        $parsed = $this->tryParseStructure($rawQrString);
        if ($parsed === null) {
            throw new RuntimeException('Not a valid VouchMorph payment-request QR structure.');
        }
        [$json, $sigHex] = $parsed;
        $data = json_decode($json, true);

        if (empty($data['rid'])) {
            throw new RuntimeException('Payment-request QR is missing its request_id field.');
        }

        $requestId = (int)$data['rid'];
        $expiry = $data['exp'] ?? null;

        $expectedSig = hash_hmac('sha256', $json, $this->signingKey);
        if (!hash_equals($expectedSig, strtolower($sigHex))) {
            return new QrPayload('payreq', [
                'valid' => false,
                'reason' => 'signature_mismatch',
                'request_id' => $requestId,
            ]);
        }

        if ($expiry !== null && (int)$expiry < time()) {
            return new QrPayload('payreq', [
                'valid' => false,
                'reason' => 'expired',
                'request_id' => $requestId,
                'expired_at' => (int)$expiry,
            ]);
        }

        return new QrPayload('payreq', [
            'valid' => true,
            'request_id' => $requestId,
            'expires_at' => $expiry,
        ]);
    }

    public function encode(QrPayload $payload): string
    {
        $data = $payload->data;

        if (empty($data['request_id'])) {
            throw new RuntimeException('request_id required to encode a payment-request QR.');
        }

        $expiry = $data['expires_at'] ?? (time() + 600); // 10 min fallback

        $json = json_encode([
            't' => 'payreq',
            'v' => 1,
            'rid' => $data['request_id'],
            'exp' => $expiry,
        ]);

        $sig = hash_hmac('sha256', $json, $this->signingKey);

        return $this->base64UrlEncode($json) . '.' . $sig;
    }

    /**
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
        if (!is_array($decoded) || ($decoded['t'] ?? null) !== 'payreq' || ($decoded['v'] ?? null) !== 1) {
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
