<?php
declare(strict_types=1);

namespace Infrastructure\QRcodes\Adapters;

use Infrastructure\QRcodes\Contracts\QrAdapterInterface;
use Infrastructure\QRcodes\Contracts\QrPayload;
use RuntimeException;

/**
 * VOUCHMORPH_ACCOUNT_V1
 *
 * A "here's my account" QR for any saved source — lets a friend scan
 * it to pre-fill the destination step of a swap instead of typing the
 * institution/identifier manually. NOT a payment authorization: no
 * amount, no expiry-gated transaction record. Signed only so a
 * scanned code can't be silently tampered with in transit (e.g. a
 * modified screenshot swapping in a different identifier).
 *
 * Wire format (before HMAC): {"t":"acct","v":1,"inst":"...","atype":"...","id":"...","idt":"...","name":"..."}
 */
class VouchMorphAccountQrAdapter implements QrAdapterInterface
{
    private const SPEC_NAME = 'VOUCHMORPH_ACCOUNT_V1';

    private string $signingKey;

    public function __construct(?string $signingKey = null)
    {
        $key = $signingKey
            ?? (class_exists('Security\Encryption\KeyVault')
                ? (\Security\Encryption\KeyVault::getInstance()->getKey('qr_account_signing_key') ?? null)
                : null)
            ?? getenv('QR_ACCOUNT_SIGNING_KEY');

        if (empty($key) || strlen($key) < 32) {
            throw new RuntimeException(
                'QR account signing key is missing or too short (min 32 bytes required). ' .
                'Set QR_ACCOUNT_SIGNING_KEY or a KeyVault key named qr_account_signing_key.'
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
            throw new RuntimeException('Not a valid VouchMorph account QR structure.');
        }
        [$json, $sigHex] = $parsed;
        $data = json_decode($json, true);

        if (empty($data['inst']) || empty($data['id']) || empty($data['atype'])) {
            throw new RuntimeException('Account QR is missing required fields.');
        }

        $expectedSig = hash_hmac('sha256', $json, $this->signingKey);
        if (!hash_equals($expectedSig, strtolower($sigHex))) {
            return new QrPayload('acct', [
                'valid' => false,
                'reason' => 'signature_mismatch',
            ]);
        }

        return new QrPayload('acct', [
            'valid' => true,
            'institution' => $data['inst'],
            'asset_type' => $data['atype'],
            'identifier' => $data['id'],
            'identifier_type' => $data['idt'] ?? null,
            'display_name' => $data['name'] ?? null,
        ]);
    }

    public function encode(QrPayload $payload): string
    {
        $data = $payload->data;
        foreach (['institution', 'asset_type', 'identifier'] as $field) {
            if (empty($data[$field])) {
                throw new RuntimeException("{$field} required to encode an account QR.");
            }
        }

        $json = json_encode([
            't' => 'acct',
            'v' => 1,
            'inst' => $data['institution'],
            'atype' => $data['asset_type'],
            'id' => $data['identifier'],
            'idt' => $data['identifier_type'] ?? null,
            'name' => $data['display_name'] ?? null,
        ]);

        $sig = hash_hmac('sha256', $json, $this->signingKey);

        return $this->base64UrlEncode($json) . '.' . $sig;
    }

    private function tryParseStructure(string $rawQrString): ?array
    {
        $parts = explode('.', $rawQrString, 2);
        if (count($parts) !== 2 || $parts[1] === '') return null;

        $json = $this->base64UrlDecode($parts[0]);
        if ($json === '') return null;

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded['t'] ?? null) !== 'acct' || ($decoded['v'] ?? null) !== 1) {
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
