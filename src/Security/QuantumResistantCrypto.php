<?php
// src/Security/QuantumResistantCrypto.php

namespace Security;

/**
 * Quantum-resistant cryptography for long-term data protection
 * NIST SP 800-208 (Post-Quantum Cryptography)
 * For data that must remain secure for 10+ years
 */
class QuantumResistantCrypto
{
    /**
     * SHA-3 for quantum-resistant hashing
     */
    public static function hash(string $data): string
    {
        return hash('sha3-512', $data);
    }
    
    /**
     * Create a key fingerprint for audit (quantum-resistant)
     */
    public static function fingerprint(string $key): string
    {
        return substr(self::hash($key), 0, 32);
    }
    
    /**
     * Store sensitive data with quantum-resistant encryption
     * Uses AES-256-GCM (quantum-safe with 256-bit keys)
     */
    public static function encryptSensitiveData(string $data, string $key): string
    {
        $iv = random_bytes(12);
        $encrypted = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        
        return base64_encode($iv . $encrypted . $tag);
    }
    
    /**
     * Decrypt sensitive data
     */
    public static function decryptSensitiveData(string $encrypted, string $key): string
    {
        $decoded = base64_decode($encrypted);
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, -16);
        $ciphertext = substr($decoded, 12, -16);
        
        return openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    }
    
    /**
     * Generate a quantum-resistant key (256+ bits)
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * HKDF key derivation (quantum-safe)
     */
    public static function deriveKey(string $ikm, string $salt, string $info, int $length = 32): string
    {
        return hash_hkdf('sha3-512', $ikm, $length, $info, $salt);
    }
    
    /**
     * Secure compare (constant time)
     */
    public static function secureCompare(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
