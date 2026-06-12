<?php
// src/Infrastructure/Crypto/MessagerSigner.php

namespace Infrastructure\Crypto;

class MessagerSigner
{
    private $privateKey;
    
    public function __construct(?string $privateKey = null)
    {
        if ($privateKey) {
            $this->privateKey = openssl_pkey_get_private($privateKey);
        } else {
            $privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY');
            if ($privateKeyContent) {
                $privateKeyContent = str_replace(['\\n', '\n'], "\n", $privateKeyContent);
                
                if (strpos($privateKeyContent, '-----BEGIN PRIVATE KEY-----') === false) {
                    $privateKeyContent = "-----BEGIN PRIVATE KEY-----\n" . 
                                         chunk_split(trim($privateKeyContent), 64, "\n") . 
                                         "-----END PRIVATE KEY-----\n";
                }
                
                $this->privateKey = openssl_pkey_get_private($privateKeyContent);
            }
        }
    }
    
    public function sign(array $payload, $privateKey = null): string
    {
        $key = $privateKey ?? $this->privateKey;
        
        if (!$key) {
            return '';
        }
        
        ksort($payload);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $signature = '';
        openssl_sign($payloadJson, $signature, $key, OPENSSL_ALGO_SHA256);
        
        return base64_encode($signature);
    }
    
    public function signWithTimestamp(array $payload, $privateKey = null): array
    {
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
        ksort($payloadWithTimestamp);
        
        $signature = $this->sign($payloadWithTimestamp, $privateKey);
        
        return [
            'payload' => $payloadWithTimestamp,
            'signature' => $signature,
            'timestamp' => $timestamp
        ];
    }
    
    public function createSignedRequest(array $payload, string $requester = 'VOUCHMORPH'): array
    {
        $signed = $this->signWithTimestamp($payload);
        
        $request = array_merge($signed['payload'], [
            'signature' => $signed['signature'],
            'requester' => $requester
        ]);
        
        // Attach certificate if available (for Visa-style PKI)
        $certManager = new CertificateManager();
        if ($certManager->isConfigured() && $certManager->getMyCertificate()) {
            $request['certificate'] = $certManager->getMyCertificate();
        }
        
        return $request;
    }
    
    public function getPrivateKey()
    {
        return $this->privateKey;
    }
    
    public function isReady(): bool
    {
        return $this->privateKey !== null;
    }
}
