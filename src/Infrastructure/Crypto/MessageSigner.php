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
                if (strpos($privateKeyContent, '\\n') !== false) {
                    $privateKeyContent = str_replace('\\n', "\n", $privateKeyContent);
                }
                if (strpos($privateKeyContent, '\n') !== false) {
                    $privateKeyContent = str_replace('\n', "\n", $privateKeyContent);
                }
                
                // Also ensure the key has proper BEGIN/END lines
                if (strpos($privateKeyContent, '-----BEGIN PRIVATE KEY-----') === false) {
                    $privateKeyContent = "-----BEGIN PRIVATE KEY-----\n" . 
                                         chunk_split(trim($privateKeyContent), 64, "\n") . 
                                         "-----END PRIVATE KEY-----\n";
                }
                
                // Ensure the key ends with a newline
                if (substr($privateKeyContent, -1) !== "\n") {
                    $privateKeyContent .= "\n";
                }
                
                error_log("Loading private key. Length: " . strlen($privateKeyContent));
                
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
     */
    public function signWithTimestamp(array $payload, $privateKey = null): array
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
    
    /**
     * Create a signed request ready to send to another institution
     */
    public function createSignedRequest(array $payload, string $requester = 'VOUCHMORPH'): array
    {
        $signed = $this->signWithTimestamp($payload);
        
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
    
    /**
     * Check if signer is ready
     */
    public function isReady(): bool
    {
        return $this->privateKey !== null;
    }
}
