<?php
// src/Infrastructure/Crypto/CertificateManager.php

namespace Infrastructure\Crypto;

class CertificateManager
{
    private ?string $caCert = null;
    private ?string $myPrivateKey = null; 
    private ?string $myCertificate = null;
    private ?string $myName = null;
    private $logger;
    
    public function __construct(?string $vouchmorphpartnerName = null)
    {
        $this->myName = $vouchmorphpartnerName ?? getenv('VOUCHMORPH_PARTNER_NAME') ?: 'VOUCHMORPH';
        
        // Load CA certificate
        $caPath = getenv('VOUCHMORPH_CA_CERT');
        if ($caPath && file_exists($caPath)) {
            $this->caCert = file_get_contents($caPath);
        } else {
            $this->caCert = getenv('VOUCHMORPH_CA_CERT_CONTENT');
            if ($this->caCert) {
                $this->caCert = str_replace(['\\n', '\n'], "\n", $this->caCert);
            }
        }
        
        // Load member's private key
        $privateKeyPath = getenv($this->myName . '_PRIVATE_KEY');
        if ($privateKeyPath && file_exists($privateKeyPath)) {
            $this->myPrivateKey = file_get_contents($privateKeyPath);
        } else {
            $this->myPrivateKey = getenv($this->myName . '_PRIVATE_KEY_CONTENT');
            if ($this->myPrivateKey) {
                $this->myPrivateKey = str_replace(['\\n', '\n'], "\n", $this->myPrivateKey);
            }
        }
        
        // Load member's certificate
        $certPath = getenv($this->myName . '_CERT');
        if ($certPath && file_exists($certPath)) {
            $this->myCertificate = file_get_contents($certPath);
        } else {
            $this->myCertificate = getenv($this->myName . '_CERT_CONTENT');
            if ($this->myCertificate) {
                $this->myCertificate = str_replace(['\\n', '\n'], "\n", $this->myCertificate);
            }
        }
        
        // Simple logger
        $this->logger = function($msg, $level = 'info') {
            error_log("[CertificateManager] $level: $msg");
        };
    }
    
    public function loadCertificate($certificateString) {
        $cleaned = str_replace(['\/', '\n'], ['/', "\n"], $certificateString);
        return openssl_x509_read($cleaned);
    }
    
    public function verifyCertificate(string $certificatePem): bool
    {
        if (!$this->caCert) {
            error_log("CertificateManager: No CA certificate to verify against");
            return false;
        }
        
        $tempCert = tempnam(sys_get_temp_dir(), 'cert_');
        $tempCA = tempnam(sys_get_temp_dir(), 'ca_');
        
        file_put_contents($tempCert, $certificatePem);
        file_put_contents($tempCA, $this->caCert);
        
        $cmd = "openssl verify -CAfile " . escapeshellarg($tempCA) . " " . escapeshellarg($tempCert) . " 2>&1";
        exec($cmd, $output, $returnCode);
        $result = ($returnCode === 0);
        
        unlink($tempCert);
        unlink($tempCA);
        
        error_log("CertificateManager: Certificate verification: " . ($result ? "PASSED" : "FAILED"));
        return $result;
    }
    
    public function extractPublicKeyFromCert(string $certificatePem): ?string
    {
        $tempCert = tempnam(sys_get_temp_dir(), 'extract_');
        file_put_contents($tempCert, $certificatePem);
        
        $cmd = "openssl x509 -in " . escapeshellarg($tempCert) . " -pubkey -noout 2>&1";
        $publicKey = shell_exec($cmd);
        
        unlink($tempCert);
        
        return $publicKey ?: null;
    }
    
    /**
     * Create a signed request with certificate
     * 
     * FIX: Include 'requester' in the signed payload BEFORE signing
     * This ensures compatibility with ZuruBank and other partners
     * who expect 'requester' to be part of the signed data.
     */
    public function createSignedRequest(array $payload, string $requester): array
    {
        if (!$this->myPrivateKey || !$this->myCertificate) {
            error_log("CertificateManager: Cannot sign request - missing private key or certificate");
            return $payload;
        }
        
        $timestamp = time();
        
        // FIX: Include requester in the payload BEFORE signing
        $payloadWithTimestamp = array_merge($payload, [
            'timestamp' => $timestamp,
            'requester' => $requester  // ← REQUIRED: Include requester in signed payload
        ]);
        ksort($payloadWithTimestamp);
        
        $jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // DEBUG: Log exactly what bytes are being signed
        error_log("CertificateManager: SIGNING JSON: " . $jsonToSign);

        $signature = '';
        $keyResource = openssl_pkey_get_private($this->myPrivateKey);
        
        if (!$keyResource) {
            error_log("CertificateManager: Failed to load private key for signing");
            return $payload;
        }
        
        $signResult = openssl_sign($jsonToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        openssl_free_key($keyResource);
        
        if (!$signResult) {
            error_log("CertificateManager: Failed to create signature");
            return $payload;
        }
        
        error_log("CertificateManager: Created signed request for {$requester} with timestamp {$timestamp}");
        error_log("CertificateManager: Signature length: " . strlen(base64_encode($signature)));
        
        // Return the signed payload with requester already included
        // No need to add requester again since it's already in $payloadWithTimestamp
        return array_merge($payloadWithTimestamp, [
            'signature' => base64_encode($signature),
            'certificate' => $this->myCertificate
        ]);
    }
    
    /**
     * Verify a signed request
     * 
     * FIX: Do NOT remove 'requester' from the payload before verification
     * since it's now included in the signed payload.
     */
    public function verifySignedRequest(array $request): array
    {
        $certificate = $request['certificate'] ?? null;
        $signature = $request['signature'] ?? null;
        $requester = $request['requester'] ?? 'UNKNOWN';
        
        if (!$certificate || !$signature) {
            error_log("CertificateManager: Missing certificate or signature for {$requester}");
            return ['verified' => false, 'message' => 'Missing certificate or signature', 'requester' => $requester];
        }
        
        if (!$this->verifyCertificate($certificate)) {
            error_log("CertificateManager: Certificate not trusted for {$requester}");
            return ['verified' => false, 'message' => 'Certificate not trusted', 'requester' => $requester];
        }
        
        $publicKey = $this->extractPublicKeyFromCert($certificate);
        if (!$publicKey) {
            error_log("CertificateManager: Cannot extract public key for {$requester}");
            return ['verified' => false, 'message' => 'Cannot extract public key', 'requester' => $requester];
        }
        
        // Prepare payload for verification
        // Remove only signature and certificate (not requester)
        $payloadToVerify = $request;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['certificate']);
        // DO NOT remove 'requester' - it's now part of the signed payload
        ksort($payloadToVerify);
        
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        // DEBUG: Log what's being verified
        error_log("CertificateManager: VERIFYING JSON: " . $jsonToVerify);
        
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            error_log("CertificateManager: Invalid public key for {$requester}");
            return ['verified' => false, 'message' => 'Invalid public key', 'requester' => $requester];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        error_log("CertificateManager: openssl_verify result: " . $result . " (1=valid, 0=invalid, -1=error)");
        error_log("CertificateManager: Request from {$requester} - Signature: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
        
        if ($result === -1) {
            error_log("CertificateManager: OpenSSL error: " . openssl_error_string());
        }
        
        return [
            'verified' => $isValid, 
            'requester' => $requester,
            'message' => $isValid ? 'Signature verified' : 'Invalid signature'
        ];
    }
    
    /**
     * Verify a signed response from a partner
     * 
     * Partners may or may not include 'requester' in their signed payload.
     * This method checks both possibilities.
     */
    public function verifySignedResponse(array $response): array
    {
        $certificate = $response['certificate'] ?? null;
        $signature = $response['signature'] ?? null;
        $responder = $response['requester'] ?? $response['responder'] ?? 'UNKNOWN';
        
        if (!$certificate || !$signature) {
            error_log("CertificateManager: No certificate/signature in response from {$responder}");
            return ['verified' => false, 'message' => 'Missing certificate or signature', 'responder' => $responder];
        }
        
        if (!$this->verifyCertificate($certificate)) {
            error_log("CertificateManager: Response certificate not trusted for {$responder}");
            return ['verified' => false, 'message' => 'Certificate not trusted', 'responder' => $responder];
        }
        
        $publicKey = $this->extractPublicKeyFromCert($certificate);
        if (!$publicKey) {
            error_log("CertificateManager: Cannot extract public key from response for {$responder}");
            return ['verified' => false, 'message' => 'Cannot extract public key', 'responder' => $responder];
        }
        
        // Try verification with requester included
        $payloadToVerify = $response;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['certificate']);
        // Keep requester if present - it may be part of the signed payload
        ksort($payloadToVerify);
        
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        error_log("CertificateManager: Verifying response payload for {$responder}: " . substr($jsonToVerify, 0, 200) . "...");
        
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            error_log("CertificateManager: Invalid public key for {$responder}");
            return ['verified' => false, 'message' => 'Invalid public key', 'responder' => $responder];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        // If verification failed and requester was present, try without it
        if (!$isValid && isset($response['requester'])) {
            error_log("CertificateManager: Retrying verification without requester for {$responder}");
            
            $payloadToVerifyNoRequester = $response;
            unset($payloadToVerifyNoRequester['signature']);
            unset($payloadToVerifyNoRequester['certificate']);
            unset($payloadToVerifyNoRequester['requester']);
            ksort($payloadToVerifyNoRequester);
            
            $jsonToVerifyNoRequester = json_encode($payloadToVerifyNoRequester, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            $resultNoRequester = openssl_verify($jsonToVerifyNoRequester, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
            $isValid = ($resultNoRequester === 1);
            
            if ($isValid) {
                error_log("CertificateManager: Response from {$responder} - SIGNATURE VALID (without requester)");
            }
        }
        
        if ($isValid) {
            error_log("CertificateManager: Response from {$responder} - SIGNATURE VALID ✓");
        } else {
            error_log("CertificateManager: Response from {$responder} - SIGNATURE INVALID ✗ (openssl result: {$result})");
            error_log("CertificateManager: Failed verification payload: " . substr($jsonToVerify, 0, 500) . "...");
        }
        
        return ['verified' => $isValid, 'responder' => $responder];
    }
    
    public function getMyCertificate(): ?string
    {
        return $this->myCertificate;
    }
    
    public function isConfigured(): bool
    {
        return ($this->caCert !== null && $this->myPrivateKey !== null && $this->myCertificate !== null);
    }
}
