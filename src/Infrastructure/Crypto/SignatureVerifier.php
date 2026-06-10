<?php
// Infrastructure/Crypto/SignatureVerifier.php

namespace Infrastructure\Crypto;

class SignatureVerifier
{
    public function verify(array $payload, string $signature, string $publicKey): bool
    {
        $data = json_encode($payload);
        $expected = base64_encode(hash_hmac('sha256', $data, $publicKey, true));
        return hash_equals($expected, $signature);
    }
    
    public function verifyWithTimestamp(array $signedPayload, string $publicKey, int $maxAgeSeconds = 300): bool
    {
        $payload = $signedPayload['payload'] ?? [];
        $signature = $signedPayload['signature'] ?? '';
        $timestamp = $signedPayload['timestamp'] ?? 0;
        
        // Reject old messages (prevent replay attacks)
        if (abs(time() - $timestamp) > $maxAgeSeconds) {
            return false;
        }
        
        return $this->verify($payload, $signature, $publicKey);
    }
}
