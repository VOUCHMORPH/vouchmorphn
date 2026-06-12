<?php
// Infrastructure/Crypto/SignatureVerifier.php

namespace Infrastructure\Crypto;

use PDO;
use Exception;

class SignatureVerifier
{
    private ?PDO $db = null;  // Allow null by using ?PDO
    private ?CertificateManager $certManager = null;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
        
        // Initialize CertificateManager for certificate-based verification
        try {
            $this->certManager = new CertificateManager();
            if ($this->certManager->isConfigured()) {
                error_log("SignatureVerifier: CertificateManager initialized");
            }
        } catch (Exception $e) {
            error_log("SignatureVerifier: CertificateManager init failed - " . $e->getMessage());
        }
    }
    
    /**
     * Get RSA public key for an institution from institution_keys table
     * (Legacy method - prefer certificates over this)
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
     * Verify a request using certificate (preferred method)
     * This is the Visa/Mastercard standard way
     */
    public function verifyWithCertificate(array $request): array
    {
        if (!$this->certManager) {
            error_log("SignatureVerifier: CertificateManager not available");
            return ['verified' => false, 'message' => 'CertificateManager not available'];
        }
        
        return $this->certManager->verifySignedRequest($request);
    }
    
    /**
     * Verify RSA signature from an institution
     * Uses openssl_verify - same as Visa/Mastercard
     * (Legacy method - use verifyWithCertificate when possible)
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
     * (Legacy method - use verifyWithCertificate when possible)
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
     * (Legacy method - use verifyWithCertificate when possible)
     */
    public function verifyForInstitution(array $payload, string $signature, string $institution, ?int $timestamp = null): bool
    {
        // First try to verify using certificate if the payload contains one
        if (isset($payload['certificate'])) {
            $result = $this->verifyWithCertificate($payload);
            if ($result['verified']) {
                error_log("SignatureVerifier: Certificate verification successful for {$institution}");
                return true;
            } else {
                error_log("SignatureVerifier: Certificate verification failed for {$institution}: " . ($result['message'] ?? 'Unknown'));
                // Fall through to legacy verification if certificate fails
            }
        }
        
        // Legacy verification
        $publicKey = $this->getInstitutionPublicKey($institution);
        
        if (!$publicKey) {
            error_log("Cannot verify signature: No public key for {$institution}");
            return false;
        }
        
        // Reconstruct verification dictionary cleanly
        $payloadToVerify = [];
        foreach ($payload as $key => $value) {
            if ($key !== 'signature' && $key !== 'timestamp' && $key !== 'requester' && $key !== 'certificate') {
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
    
    /**
     * Universal verification - tries certificate first, falls back to public key
     * This is the recommended method for all incoming requests
     */
    public function verifyRequest(array $request): array
    {
        // Try certificate verification first (preferred)
        if (isset($request['certificate'])) {
            $result = $this->verifyWithCertificate($request);
            if ($result['verified']) {
                return [
                    'verified' => true,
                    'method' => 'certificate',
                    'requester' => $result['requester'] ?? $request['requester'] ?? 'UNKNOWN',
                    'message' => 'Verified using certificate chain'
                ];
            } else {
                error_log("SignatureVerifier: Certificate verification failed: " . ($result['message'] ?? 'Unknown'));
                // Continue to legacy verification
            }
        }
        
        // Fall back to legacy signature verification
        $signature = $request['signature'] ?? null;
        $requester = $request['requester'] ?? 'UNKNOWN';
        $timestamp = $request['timestamp'] ?? null;
        
        if (!$signature) {
            return [
                'verified' => false,
                'method' => 'none',
                'message' => 'No signature or certificate provided'
            ];
        }
        
        $publicKey = $this->getInstitutionPublicKey($requester);
        if (!$publicKey) {
            return [
                'verified' => false,
                'method' => 'legacy',
                'message' => "No public key found for {$requester}"
            ];
        }
        
        $payloadToVerify = $request;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['requester']);
        unset($payloadToVerify['timestamp']);
        unset($payloadToVerify['certificate']);
        ksort($payloadToVerify);
        
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            return [
                'verified' => false,
                'method' => 'legacy',
                'message' => 'Invalid public key'
            ];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        return [
            'verified' => $isValid,
            'method' => 'legacy',
            'requester' => $requester,
            'message' => $isValid ? 'Verified using legacy signature' : 'Invalid signature'
        ];
    }
}
