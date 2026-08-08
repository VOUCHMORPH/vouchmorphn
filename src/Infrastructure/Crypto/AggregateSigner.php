<?php
declare(strict_types=1);

namespace Infrastructure\Crypto;

use Domain\Models\FundingPool;
use RuntimeException;

class AggregateSigner
{
    private CertificateManager $certManager;
    private SignatureVerifier $signatureVerifier;
    private string $systemId;

    public function __construct(
        CertificateManager $certManager,
        SignatureVerifier $signatureVerifier,
        string $systemId = 'VOUCHMORPH'
    ) {
        $this->certManager = $certManager;
        $this->signatureVerifier = $signatureVerifier;
        $this->systemId = $systemId;
    }

    public function signAggregate(array $pool, array $holds, array $verifications): array
    {
        $this->verifySourceSignatures($holds, $verifications);

        $payload = [
            'pool_id' => $pool['id'],
            'swap_reference' => $pool['reference'],
            'total_amount' => $pool['amount'],
            'currency' => $pool['currency'],
            'destination_institution' => $pool['destination_institution'],
            'contributors' => array_map(function ($hold) {
                return [
                    'institution' => $hold['institution'],
                    'amount' => $hold['amount'],
                    'hold_reference' => $hold['hold_reference'],
                    'source_signature' => $hold['signature'],
                    'source_certificate' => $hold['certificate']
                ];
            }, $holds)
        ];

        ksort($payload);

        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signedRequest = $this->certManager->createSignedRequest($payload, $this->systemId);

        if (!isset($signedRequest['signature'])) {
            throw new RuntimeException('AggregateSigner: failed to sign aggregate payload - check that CertificateManager has a private key and certificate configured');
        }

        return [
            'signature' => $signedRequest['signature'],
            'certificate' => $signedRequest['certificate'] ?? $this->certManager->getMyCertificate(),
            'payload' => $signedRequest,
            'payload_hash' => $payloadHash,
            'timestamp' => $signedRequest['timestamp'] ?? time()
        ];
    }

    /**
     * Verifies each hold's own signature against its own signed payload.
     * Deliberately does NOT reuse $verification['payload'] here - that is
     * the VERIFY_ASSET payload, a different signed document from the
     * PLACE_HOLD payload each $hold carries in 'original_payload'.
     * Signing and verifying must operate on the same document, or this
     * always fails regardless of whether the hold was legitimately signed.
     */
    private function verifySourceSignatures(array $holds, array $verifications): void
{
    foreach ($holds as $index => $hold) {
        $institution = $hold['institution'] ?? 'unknown';

        $verification = $verifications[$index] ?? null;
        if (!$verification) {
            throw new RuntimeException("Missing verification for source: {$institution}");
        }

        // Verify using the institution's PINNED public key rather than a
        // CA-chain certificate check. Each bank's response is signed with
        // its own key, but its embedded certificate is self-signed (not
        // issued by VouchMorph's CA) - verifying via CA chain always fails
        // for a genuinely self-signed cert, regardless of signature
        // validity. VouchMorph already has each institution's real public
        // key pinned via {INSTITUTION}_PUBLIC_KEY - use that directly.
        $publicKey = $this->getPinnedPublicKey($institution);
        if (!$publicKey) {
            throw new RuntimeException("No pinned public key configured for source: {$institution}");
        }

        $payloadToVerify = $hold['original_payload'] ?? [];
        ksort($payloadToVerify);
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            throw new RuntimeException("Invalid pinned public key for source: {$institution}");
        }

        $decodedSignature = base64_decode($hold['signature'] ?? '');
        $result = openssl_verify($jsonToVerify, $decodedSignature, $keyResource, OPENSSL_ALGO_SHA256);

        if ($result !== 1) {
            throw new RuntimeException("Invalid signature from: {$institution}");
        }
    }
}

private function getPinnedPublicKey(string $institution): ?string
{
    $envName = strtoupper($institution) . '_PUBLIC_KEY';
    $key = getenv($envName);
    if (!$key) {
        return null;
    }
    return str_replace(['\\n', '\n'], "\n", $key);
}
