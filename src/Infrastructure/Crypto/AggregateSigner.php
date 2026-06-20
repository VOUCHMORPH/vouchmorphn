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

    public function signAggregate(FundingPool $pool, array $holds, array $verifications): array
    {
        // Verify all source signatures
        $this->verifySourceSignatures($holds, $verifications);

        // Build aggregate payload
        $payload = [
            'pool_id' => $pool->getPoolId(),
            'swap_reference' => $pool->getSwapReference(),
            'total_amount' => $pool->getFundedAmount(),
            'currency' => $pool->getCurrency(),
            'destination_institution' => $pool->getDestinationInstitution(),
            'timestamp' => time(),
            'contributors' => array_map(function($hold) {
                return [
                    'institution' => $hold['source']['institution'],
                    'amount' => $hold['amount'],
                    'hold_reference' => $hold['hold_reference'],
                    'source_signature' => $hold['signature'],
                    'source_certificate' => $hold['certificate']
                ];
            }, $holds)
        ];

        ksort($payload);

        $signature = $this->certManager->signPayload($payload, $this->systemId);
        $certificate = $this->certManager->getCertificate($this->systemId);

        return [
            'signature' => $signature,
            'certificate' => $certificate,
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'timestamp' => time()
        ];
    }

    private function verifySourceSignatures(array $holds, array $verifications): void
    {
        foreach ($holds as $index => $hold) {
            $verification = $verifications[$index] ?? null;
            if (!$verification) {
                throw new RuntimeException("Missing verification for source: {$hold['source']['institution']}");
            }

            $isValid = $this->signatureVerifier->verifySignature(
                $verification['payload'] ?? [],
                $hold['signature'] ?? '',
                $hold['certificate'] ?? ''
            );

            if (!$isValid) {
                throw new RuntimeException("Invalid signature from: {$hold['source']['institution']}");
            }
        }
    }
}
