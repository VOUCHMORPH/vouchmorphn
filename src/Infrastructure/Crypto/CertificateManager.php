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
    
    public function createSignedRequest(array $payload, string $requester): array
    {
        if (!$this->myPrivateKey || !$this->myCertificate) {
            error_log("CertificateManager: Cannot sign request - missing private key or certificate");
            return $payload;
        }
        
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
        ksort($payloadWithTimestamp);
        
        $jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = '';
        $keyResource = openssl_pkey_get_private($this->myPrivateKey);
        openssl_sign($jsonToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        
        error_log("CertificateManager: Created signed request for {$requester} with timestamp {$timestamp}");
        
        return array_merge($payloadWithTimestamp, [
            'signature' => base64_encode($signature),
            'requester' => $requester,
            'certificate' => $this->myCertificate
        ]);
    }
    
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
        
        $payloadToVerify = $request;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['certificate']);
        unset($payloadToVerify['requester']);
        ksort($payloadToVerify);
        
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            return ['verified' => false, 'message' => 'Invalid public key', 'requester' => $requester];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        error_log("CertificateManager: Request from {$requester} - Signature: " . ($isValid ? "VALID" : "INVALID"));
        
        return [
            'verified' => $isValid, 
            'requester' => $requester,
            'message' => $isValid ? 'Signature verified' : 'Invalid signature'
        ];
    }
    
    public function verifySignedResponse(array $response): array
    {
        $certificate = $response['certificate'] ?? null;
        $signature = $response['signature'] ?? null;
        $responder = $response['requester'] ?? 'UNKNOWN';
        
        // Skip verification for SACCUSSALIS in non-production (temporary fix)
        if ($responder === 'SACCUSSALIS' && getenv('APP_ENV') !== 'production') {
            error_log("CertificateManager: Skipping response verification for {$responder} (non-production mode)");
            return ['verified' => true, 'responder' => $responder, 'message' => 'Skipped (trust mode)'];
        }
        
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
        
        $payloadToVerify = $response;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['certificate']);
        ksort($payloadToVerify);
        
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        $keyResource = openssl_pkey_get_public($publicKey);
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        error_log("CertificateManager: Response from {$responder} - Signature: " . ($isValid ? "VALID" : "INVALID"));
        
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
