<?php
// src/Infrastructure/Crypto/MessagerSigner.php

namespace Infrastructure\Crypto;

class MessagerSigner
{
    private $privateKey;
    private ?CertificateManager $certManager = null;
    
    public function __construct(?string $privateKey = null, ?CertificateManager $certManager = null)
    {
        $this->certManager = $certManager;
        
        if ($privateKey) {
            $this->privateKey = openssl_pkey_get_private($privateKey);
        } else if ($certManager) {
            $privateKeyContent = $certManager->getMyPrivateKey();
            if ($privateKeyContent) {
                $this->privateKey = openssl_pkey_get_private($privateKeyContent);
            }
        } else {
            // Fallback to environment variable
            $privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT');
            if (!$privateKeyContent) {
                $privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY');
            }
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
        
        if (!$this->privateKey) {
            error_log("MessagerSigner: Failed to load private key");
        } else {
            error_log("MessagerSigner: Private key loaded successfully");
        }
    }
    
    public function sign(array $payload, $privateKey = null): string
    {
        $key = $privateKey ?? $this->privateKey;
        
        if (!$key) {
            error_log("MessagerSigner: No private key available for signing");
            return '';
        }
        
        ksort($payload);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $signature = '';
        $success = openssl_sign($payloadJson, $signature, $key, OPENSSL_ALGO_SHA256);
        
        if (!$success) {
            error_log("MessagerSigner: Failed to sign payload: " . openssl_error_string());
            return '';
        }
        
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
    
    /**
     * Create signed request with certificate attached (Visa/Mastercard style)
     */
    public function createSignedRequest(array $payload, string $requester = 'VOUCHMORPH'): array
    {
        $signed = $this->signWithTimestamp($payload);
        
        $request = array_merge($signed['payload'], [
            'signature' => $signed['signature'],
            'requester' => $requester
        ]);
        
        // Attach certificate if available (Visa-style PKI)
        if ($this->certManager && $this->certManager->getMyCertificate()) {
            $request['certificate'] = $this->certManager->getMyCertificate();
            error_log("MessagerSigner: Certificate attached to request for {$requester}");
        } else {
            error_log("MessagerSigner: No certificate available to attach");
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
