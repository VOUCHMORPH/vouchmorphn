<?php
// src/Infrastructure/Crypto/CertificateManager.php

namespace Infrastructure\Crypto;

class CertificateManager
{
    private ?string $caCert = null;
    private ?string $myPrivateKey = null;
    private ?string $myCertificate = null;
    private ?string $myName = null;
    
    public function __construct(?string $memberName = null)
    {
        $this->myName = $memberName ?? getenv('MEMBER_NAME') ?: 'VOUCHMORPH';
        
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
    
    public function createSignedRequest(array $payload, string $requester): array
    {
        if (!$this->myPrivateKey || !$this->myCertificate) {
            error_log("CertificateManager: Cannot sign request - missing private key or certificate");
            return $payload;
        }
        
        $signer = new MessageSigner($this->myPrivateKey);
        
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
        ksort($payloadWithTimestamp);
        
        $signature = $signer->sign($payloadWithTimestamp);
        
        error_log("CertificateManager: Created signed request for {$requester} with timestamp {$timestamp}");
        
        return array_merge($payloadWithTimestamp, [
            'signature' => $signature,
            'requester' => $requester,
            'certificate' => $this->myCertificate
        ]);
    }
    
    public function verifySignedRequest(array $request): array
    {
        $certificate = $request['certificate'] ?? null;
        $signature = $request['signature'] ?? null;
        $requester = $request['requester'] ?? 'UNKNOWN';
        
        if (!$certificate) {
            error_log("CertificateManager: No certificate provided for {$requester}");
            return ['verified' => false, 'message' => 'Missing certificate or signature', 'requester' => $requester];
        }
        
        if (!$signature) {
            error_log("CertificateManager: No signature provided for {$requester}");
            return ['verified' => false, 'message' => 'Missing certificate or signature', 'requester' => $requester];
        }
        
        // Step 1: Verify certificate chains to trusted CA
        if (!$this->verifyCertificate($certificate)) {
            error_log("CertificateManager: Certificate not trusted for {$requester}");
            return ['verified' => false, 'message' => 'Certificate not trusted', 'requester' => $requester];
        }
        
        // Step 2: Extract public key from certificate
        $publicKey = $this->extractPublicKeyFromCert($certificate);
        if (!$publicKey) {
            error_log("CertificateManager: Cannot extract public key for {$requester}");
            return ['verified' => false, 'message' => 'Cannot extract public key', 'requester' => $requester];
        }
        
        // Step 3: Prepare payload for verification
        // IMPORTANT: Remove signature, certificate, and requester fields
        // BUT keep 'timestamp' - it was part of the signed payload!
        $payloadToVerify = $request;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['certificate']);
        unset($payloadToVerify['requester']);
        // Do NOT unset 'timestamp' - it's part of the signed data
        
        ksort($payloadToVerify);
        
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        error_log("CertificateManager: Verifying payload for {$requester}: " . $jsonToVerify);
        
        // Step 4: Verify signature
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            error_log("CertificateManager: Invalid public key for {$requester}");
            return ['verified' => false, 'message' => 'Invalid public key', 'requester' => $requester];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        if ($isValid) {
            error_log("CertificateManager: Signature verified for {$requester}");
        } else {
            error_log("CertificateManager: Invalid signature for {$requester} - openssl result: {$result}");
        }
        
        return [
            'verified' => $isValid, 
            'requester' => $requester,
            'message' => $isValid ? 'Signature verified' : 'Invalid signature'
        ];
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
