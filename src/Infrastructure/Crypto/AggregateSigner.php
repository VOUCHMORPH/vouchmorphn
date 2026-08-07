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
                'institution' => $hold['institution'],              // was: $hold['source']['institution']
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

    private function verifySourceSignatures(array $holds, array $verifications): void
    {
        foreach ($holds as $index => $hold) {
            $verification = $verifications[$index] ?? null;
            if (!$verification) {
                throw new RuntimeException("Missing verification for source: {$hold['source']['institution']}");
            }

            // verifyWithCertificate() expects a single request array containing
            // the payload fields plus 'signature' and 'certificate' keys - it
            // does not take them as separate arguments.
            $request = array_merge($verification['payload'] ?? [], [
                'signature' => $hold['signature'] ?? '',
                'certificate' => $hold['certificate'] ?? ''
            ]);

            $result = $this->signatureVerifier->verifyWithCertificate($request);

            if (!$result['verified']) {
                throw new RuntimeException("Invalid signature from: {$hold['source']['institution']}");
            }
        }
    }
}
