<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

namespace Security\Encryption;

use Security\Encryption\KeyVault;

/**
 * TokenEncryptor - AES-256-GCM with authentication
 * 
 * FIXED: Upgraded from CBC to GCM mode with authentication tag
 * FIXED: No hardcoded fallback keys
 * 
 * NOTE: This changes the output format. Existing encrypted data encrypted
 * with the old CBC mode cannot be decrypted by this version. Use migrateLegacyCiphertext()
 * to re-encrypt any existing data before deployment.
 */
class TokenEncryptor
{
    private string $cipher = 'aes-256-gcm';
    private string $key;

    public function __construct(?string $key = null)
    {
        if ($key === null) {
            $keyVault = KeyVault::getInstance();
            $key = $keyVault->getEncryptionKey();
        }
        
        if (!$key || strlen($key) < 32) {
            throw new \RuntimeException(
                'Encryption key missing or too short (min 32 bytes required)'
            );
        }
        
        $this->key = hash('sha256', $key, true);
    }

    /**
     * Encrypt data with AES-256-GCM (authenticated encryption)
     * Returns: base64(iv . ciphertext . tag)
     */
    public function encrypt(string $data): string
    {
        $iv = random_bytes(12); // GCM recommended nonce length
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }
        
        return base64_encode($iv . $encrypted . $tag);
    }

    /**
     * Decrypt data from AES-256-GCM
     * Verifies authentication tag to prevent tampering
     */
    public function decrypt(string $encrypted): string
    {
        $decoded = base64_decode($encrypted);
        
        $ivLen = 12; // GCM nonce length
        $tagLen = 16; // GCM tag length
        
        if (strlen($decoded) < $ivLen + $tagLen) {
            throw new \RuntimeException('Invalid encrypted data: too short');
        }
        
        $iv = substr($decoded, 0, $ivLen);
        $tag = substr($decoded, -$tagLen);
        $ciphertext = substr($decoded, $ivLen, -$tagLen);
        
        $decrypted = openssl_decrypt(
            $ciphertext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed: ' . openssl_error_string());
        }
        
        return $decrypted;
    }

    /**
     * Migrate legacy CBC ciphertext to GCM format
     * Call this once during deployment to re-encrypt existing data
     */
    public function migrateLegacyCiphertext(string $legacyCbcData): string
    {
        // Decrypt with old CBC format
        $decoded = base64_decode($legacyCbcData);
        $iv = substr($decoded, 0, 16);
        $ciphertext = substr($decoded, 16);
        
        $decrypted = openssl_decrypt(
            $ciphertext,
            'AES-256-CBC',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($decrypted === false) {
            throw new \RuntimeException('Legacy decryption failed');
        }
        
        // Re-encrypt with GCM
        return $this->encrypt($decrypted);
    }
}
