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
            return $payload;
        }
        
        $signer = new MessageSigner($this->myPrivateKey);
        
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
        ksort($payloadWithTimestamp);
        
        $signature = $signer->sign($payloadWithTimestamp);
        
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
        
        if (!$certificate || !$signature) {
            return ['verified' => false, 'message' => 'Missing certificate or signature'];
        }
        
        if (!$this->verifyCertificate($certificate)) {
            return ['verified' => false, 'message' => 'Certificate not trusted'];
        }
        
        $publicKey = $this->extractPublicKeyFromCert($certificate);
        if (!$publicKey) {
            return ['verified' => false, 'message' => 'Cannot extract public key'];
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
            return ['verified' => false, 'message' => 'Invalid public key'];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        return ['verified' => $isValid, 'requester' => $requester];
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
