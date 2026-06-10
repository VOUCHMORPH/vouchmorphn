<?php
// Infrastructure/Crypto/MessageSigner.php

namespace Infrastructure\Crypto;

class MessageSigner
{
    private string $privateKey;
    
    public function __construct(?string $privateKey = null)
    {
        $this->privateKey = $privateKey ?? getenv('VOUCHMORPH_PRIVATE_KEY');
    }
    
    public function sign(array $payload, ?string $privateKey = null): string
    {
        $key = $privateKey ?? $this->privateKey;
        $data = json_encode($payload);
        return base64_encode(hash_hmac('sha256', $data, $key, true));
    }
    
    public function signWithTimestamp(array $payload, ?string $privateKey = null): array
    {
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['_timestamp' => $timestamp]);
        $signature = $this->sign($payloadWithTimestamp, $privateKey);
        
        return [
            'payload' => $payloadWithTimestamp,
            'signature' => $signature,
            'timestamp' => $timestamp
        ];
    }
}
