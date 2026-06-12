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
                // CRITICAL: Convert literal \n to actual newlines
                // Railway stores newlines as literal '\n' characters
                $privateKeyContent = str_replace('\\n', "\n", $privateKeyContent);
                $privateKeyContent = str_replace('\n', "\n", $privateKeyContent);
                
                // Ensure proper PEM format
                if (strpos($privateKeyContent, '-----BEGIN PRIVATE KEY-----') === false) {
                    $privateKeyContent = "-----BEGIN PRIVATE KEY-----\n" . 
                                         chunk_split(trim($privateKeyContent), 64, "\n") . 
                                         "-----END PRIVATE KEY-----\n";
                }
                
                $this->privateKey = openssl_pkey_get_private($privateKeyContent);
                if (!$this->privateKey) {
                    error_log("Failed to load private key: " . openssl_error_string());
                } else {
                    error_log("Private key loaded successfully!");
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
        
        // FIX 1: Sort keys alphabetically for consistent JSON
        ksort($payload);
        
        // FIX 2: Use consistent JSON flags (no escaped slashes, unicode preserved)
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        error_log("SIGNING PAYLOAD: " . $payloadJson);
        
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
     */
    public function signWithTimestamp(array $payload, $privateKey = null): array
    {
        $timestamp = time();
        
        // FIX 3: Use 'timestamp' (not '_timestamp') to match what will be sent
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
        
        // FIX 4: Sort keys before signing
        ksort($payloadWithTimestamp);
        
        $signature = $this->sign($payloadWithTimestamp, $privateKey);
        
        return [
            'payload' => $payloadWithTimestamp,
            'signature' => $signature,
            'timestamp' => $timestamp
        ];
    }
    
    /**
     * Create a signed request ready to send to another institution
     */
    public function createSignedRequest(array $payload, string $requester = 'VOUCHMORPH'): array
    {
        $signed = $this->signWithTimestamp($payload);
        
        // FIX 5: Use the signed payload directly (not merging with original)
        // This ensures what we send is EXACTLY what we signed
        return array_merge($signed['payload'], [
            'signature' => $signed['signature'],
            'requester' => $requester
        ]);
        // Note: 'timestamp' is already in $signed['payload']
    }
    
    /**
     * Get the raw private key resource
     */
    public function getPrivateKey()
    {
        return $this->privateKey;
    }
    
    /**
     * Check if signer is ready
     */
    public function isReady(): bool
    {
        return $this->privateKey !== null;
    }
}
