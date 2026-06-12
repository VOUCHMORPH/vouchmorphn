<?php
// Infrastructure/Crypto/SignatureVerifier.php

namespace Infrastructure\Crypto;

use PDO;
use Exception;

class SignatureVerifier
{
    private ?PDO $db = null;  // Allow null by using ?PDO
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }
    
    /**
     * Get RSA public key for an institution from institution_keys table
     */
    public function getInstitutionPublicKey(string $institution): ?string
    {
        if (!$this->db) {
            error_log("No database connection for SignatureVerifier - cannot get public key for {$institution}");
            return null;
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT public_key 
                FROM institution_keys 
                WHERE institution = :institution 
                AND is_active = true
                AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([':institution' => $institution]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row && !empty($row['public_key'])) {
                error_log("Found RSA public key for institution: {$institution}");
                return $row['public_key'];
            }
            
            error_log("No public key found for institution: {$institution}");
            return null;
            
        } catch (Exception $e) {
            error_log("Error getting public key for {$institution}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Verify RSA signature from an institution
     * Uses openssl_verify - same as Visa/Mastercard
     */
    public function verify(array $payload, string $signature, string $publicKey): bool
    {
        $payloadJson = json_encode($payload);
        
        // Verify RSA signature using openssl
        $result = openssl_verify(
            $payloadJson,
            base64_decode($signature),
            $publicKey,
            OPENSSL_ALGO_SHA256
        );
        
        $isValid = ($result === 1);
        
        if ($result === -1) {
            error_log("Signature verification error: " . openssl_error_string());
        }
        
        return $isValid;
    }
    
    /**
     * Verify signed payload with timestamp (prevents replay attacks)
     */
    public function verifyWithTimestamp(array $signedPayload, string $publicKey, int $maxAgeSeconds = 300): bool
    {
        $payload = $signedPayload['payload'] ?? [];
        $signature = $signedPayload['signature'] ?? '';
        $timestamp = $signedPayload['timestamp'] ?? 0;
        
        // Reject old messages (prevent replay attacks)
        if (abs(time() - $timestamp) > $maxAgeSeconds) {
            error_log("Signature rejected: timestamp too old (age: " . abs(time() - $timestamp) . "s)");
            return false;
        }
        
        // Include timestamp in verification if present
        if ($timestamp) {
            $payloadToVerify = array_merge($payload, ['_timestamp' => $timestamp]);
        } else {
            $payloadToVerify = $payload;
        }
        
        return $this->verify($payloadToVerify, $signature, $publicKey);
    }
    
    /**
     * Get institution public key and verify signature in one call
     */
    public function verifyForInstitution(array $payload, string $signature, string $institution, ?int $timestamp = null): bool
    {
        $publicKey = $this->getInstitutionPublicKey($institution);
        
        if (!$publicKey) {
            error_log("Cannot verify signature: No public key for {$institution}");
            return false;
        }
        
        // Reconstruct verification dictionary cleanly
        $payloadToVerify = [];
        foreach ($payload as $key => $value) {
            if ($key !== 'signature' && $key !== 'timestamp' && $key !== 'requester') {
                $payloadToVerify[$key] = $value;
            }
        }
        
        // Route the tracking timestamp into its signature-calculation field key
        $targetTimestamp = $timestamp ?? $payload['timestamp'] ?? $payload['_timestamp'] ?? null;
        if ($targetTimestamp !== null) {
            $payloadToVerify['_timestamp'] = (int)$targetTimestamp;
        }
        
        return $this->verify($payloadToVerify, $signature, $publicKey);
    }
}
