<?php
// Infrastructure/Crypto/MessageSigner.php

namespace Infrastructure\Crypto;

class MessageSigner
{
    private $privateKey;
    
    public function __construct(?string $privateKey = null)
    {
        if ($privateKey) {
            $this->privateKey = openssl_pkey_get_private($privateKey);
        } else {
            $privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY');
            if ($privateKeyContent) {
                $this->privateKey = openssl_pkey_get_private($privateKeyContent);
                if (!$this->privateKey) {
                    error_log("Failed to load VOUCHMORPH_PRIVATE_KEY: " . openssl_error_string());
                }
            } else {
                error_log("VOUCHMORPH_PRIVATE_KEY not found in environment");
            }
        }
    }
    
    /**
     * Sign payload with RSA private key
     * Returns base64 encoded signature
     */
    public function sign(array $payload, $privateKey = null): string
    {
        $key = $privateKey ?? $this->privateKey;
        
        if (!$key) {
            error_log("No private key available for signing");
            return '';
        }
        
        $payloadJson = json_encode($payload);
        
        // Generate RSA signature
        $signature = '';
        $success = openssl_sign($payloadJson, $signature, $key, OPENSSL_ALGO_SHA256);
        
        if (!$success) {
            error_log("Failed to sign payload: " . openssl_error_string());
            return '';
        }
        
        return base64_encode($signature);
    }
    
    /**
     * Sign payload with timestamp (includes timestamp in signed data)
     * This matches what Saccussalis expects for verification
     */
    public function signWithTimestamp(array $payload, $privateKey = null): array
    {
        $timestamp = time();
        // IMPORTANT: Add _timestamp to the payload BEFORE signing
        $payloadWithTimestamp = array_merge($payload, ['_timestamp' => $timestamp]);
        $signature = $this->sign($payloadWithTimestamp, $privateKey);
        
        return [
            'payload' => $payloadWithTimestamp,
            'signature' => $signature,
            'timestamp' => $timestamp
        ];
    }
    
    /**
     * Create a signed request ready to send to another institution
     * This is used by GenericBankClient when sending requests to banks
     */
    public function createSignedRequest(array $payload, string $requester = 'VOUCHMORPH'): array
    {
        $signed = $this->signWithTimestamp($payload);
        
        // Return the payload with signature and timestamp at the root level
        // This matches what Saccussalis expects in hold.php and verify_asset.php
        return array_merge($payload, [
            'signature' => $signed['signature'],
            'timestamp' => $signed['timestamp'],
            'requester' => $requester
        ]);
    }
    
    /**
     * Get the raw private key resource
     */
    public function getPrivateKey()
    {
        return $this->privateKey;
    }
}
