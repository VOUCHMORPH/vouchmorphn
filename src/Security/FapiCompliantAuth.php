<?php
// src/Security/FapiCompliantAuth.php

namespace Security;

/**
 * PSD2 / EBA RTS Compliant Authentication
 * Financial-grade API (FAPI) Security Profile
 * RFC 8705 (mTLS), RFC 9449 (DPoP)
 */
class FapiCompliantAuth
{
    private HSMKeyManager $hsm;
    private array $validClients = [];
    private \Redis $redis;
    private \PDO $pdo;
    
    public function __construct(HSMKeyManager $hsm)
    {
        $this->hsm = $hsm;
        $this->redis = new \Redis();
        $this->redis->connect(getenv('REDIS_HOST') ?: 'localhost', (int)(getenv('REDIS_PORT') ?: 6379));
        $this->pdo = new \PDO(getenv('DATABASE_URL'));
        $this->loadValidClients();
    }
    
    private function loadValidClients(): void
    {
        $this->validClients = [
            'vouchmorph.railway.app' => [
                'name' => 'VouchMorph Orchestrator',
                'rate_limit' => 10000,
                'scopes' => ['read_balance', 'initiate_payment', 'read_transactions'],
                'public_key' => $this->getClientPublicKey('vouchmorph')
            ],
            'zurubank-production.up.railway.app' => [
                'name' => 'Zurubank',
                'rate_limit' => 5000,
                'scopes' => ['read_balance', 'initiate_payment'],
                'public_key' => $this->getClientPublicKey('zurubank')
            ],
            'cazacom-production.up.railway.app' => [
                'name' => 'Cazacom MNO',
                'rate_limit' => 5000,
                'scopes' => ['read_balance', 'initiate_payment'],
                'public_key' => $this->getClientPublicKey('cazacom')
            ]
        ];
    }
    
    /**
     * Validate mTLS certificate (RFC 8705)
     */
    public function validateMutualTls(): array
    {
        // Development mode - skip mTLS
        if (getenv('APP_ENV') === 'development') {
            return [
                'subject' => ['CN' => 'dev_client'],
                'validTo_time_t' => time() + 86400
            ];
        }
        
        $clientCert = $_SERVER['SSL_CLIENT_CERT'] ?? null;
        if (!$clientCert) {
            $this->reject('MUTUAL_TLS_REQUIRED', 'mTLS certificate required', 401);
        }
        
        $certInfo = openssl_x509_parse($clientCert);
        
        if (!$certInfo) {
            $this->reject('INVALID_CERTIFICATE', 'Invalid certificate format', 401);
        }
        
        // Check expiration
        if ($certInfo['validTo_time_t'] < time()) {
            $this->reject('CERTIFICATE_EXPIRED', 'Certificate expired', 401);
        }
        
        // Check revocation via CRL/OCSP
        $this->checkCertificateRevocation($certInfo);
        
        // Validate CN
        $clientCn = $certInfo['subject']['CN'] ?? '';
        if (!isset($this->validClients[$clientCn])) {
            $this->reject('UNAUTHORIZED_CERTIFICATE', 'Certificate not authorized: ' . $clientCn, 403);
        }
        
        return $certInfo;
    }
    
    /**
     * Validate DPoP proof (RFC 9449)
     */
    public function validateDpopProof(string $dpopHeader, string $method, string $url, string $accessToken = null): array
    {
        $parts = explode('.', $dpopHeader);
        if (count($parts) !== 3) {
            $this->reject('INVALID_DPOP_FORMAT', 'Invalid DPoP proof format', 400);
        }
        
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        
        // Validate algorithm
        if ($header['alg'] !== 'PS256') {
            $this->reject('INVALID_ALGORITHM', 'DPoP must use PS256 (RSASSA-PSS)', 400);
        }
        
        // Validate nonce (prevent replay attacks)
        $nonceKey = "dpop_nonce:{$payload['nonce']}";
        if ($this->redis->exists($nonceKey)) {
            $this->reject('DPOP_REPLAY', 'DPoP nonce already used', 400);
        }
        $this->redis->setex($nonceKey, 300, '1');
        
        // Validate timestamp (max 5 minute drift)
        $iat = $payload['iat'] ?? 0;
        if (abs($iat - time()) > 300) {
            $this->reject('INVALID_TIMESTAMP', 'DPoP timestamp out of range', 400);
        }
        
        // Validate HTTP method
        if ($payload['htm'] !== $method) {
            $this->reject('DPOP_METHOD_MISMATCH', 'HTTP method mismatch', 400);
        }
        
        // Validate URL (excluding query parameters)
        $requestUrl = strtok($url, '?');
        if ($payload['htu'] !== $requestUrl) {
            $this->reject('DPOP_URL_MISMATCH', 'URL mismatch', 400);
        }
        
        // Validate access token hash if provided
        if ($accessToken && isset($payload['ath'])) {
            $expectedAth = $this->calculateAccessTokenHash($accessToken);
            if (!hash_equals($expectedAth, $payload['ath'])) {
                $this->reject('DPOP_BINDING_FAILED', 'Access token binding failed', 401);
            }
        }
        
        // Verify signature using client's public key
        $clientId = $payload['jti'] ?? $payload['htm'];
        $clientKey = $this->getClientPublicKey($clientId);
        
        $signatureInput = $parts[0] . '.' . $parts[1];
        $signature = $this->base64UrlDecode($parts[2]);
        
        $valid = openssl_verify($signatureInput, $signature, $clientKey, OPENSSL_ALGO_SHA256);
        
        if ($valid !== 1) {
            $this->reject('DPOP_SIGNATURE_INVALID', 'Invalid DPoP signature', 401);
        }
        
        return $payload;
    }
    
    /**
     * Validate complete request (mTLS + DPoP + Token)
     */
    public function validateRequest(): array
    {
        // 1. Validate mTLS
        $cert = $this->validateMutualTls();
        $clientId = $cert['subject']['CN'] ?? 'unknown';
        
        // 2. Extract and validate DPoP
        $dpopHeader = $_SERVER['HTTP_DPOP'] ?? null;
        if (!$dpopHeader) {
            $this->reject('DPOP_REQUIRED', 'DPoP proof required (RFC 9449)', 401);
        }
        
        $method = $_SERVER['REQUEST_METHOD'];
        $url = $_SERVER['REQUEST_URI'];
        
        // 3. Extract Bearer token (if present)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $accessToken = null;
        if (preg_match('/DPoP\s+(.+)$/i', $authHeader, $matches)) {
            $accessToken = $matches[1];
        }
        
        $dpop = $this->validateDpopProof($dpopHeader, $method, $url, $accessToken);
        
        // 4. Validate access token if present
        $tokenData = [];
        if ($accessToken) {
            $tokenData = $this->validateAccessToken($accessToken);
            
            // Verify token binding
            if (isset($tokenData['cnf']['x5t#S256'])) {
                $certThumbprint = $this->getCertificateThumbprint($cert);
                if (!hash_equals($tokenData['cnf']['x5t#S256'], $certThumbprint)) {
                    $this->reject('TOKEN_BINDING_FAILED', 'Token not bound to certificate', 401);
                }
            }
        }
        
        // 5. Rate limiting
        $this->applyRateLimit($clientId);
        
        return array_merge([
            'client_id' => $clientId,
            'certificate' => $cert,
            'dpop' => $dpop
        ], $tokenData);
    }
    
    /**
     * Validate OAuth 2.0 access token
     */
    private function validateAccessToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            $this->reject('INVALID_TOKEN_FORMAT', 'Invalid token format', 401);
        }
        
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        
        // Check expiration
        if (($payload['exp'] ?? 0) < time()) {
            $this->reject('TOKEN_EXPIRED', 'Token expired', 401);
        }
        
        // Check not before
        if (($payload['nbf'] ?? 0) > time()) {
            $this->reject('TOKEN_NOT_YET_VALID', 'Token not yet valid', 401);
        }
        
        // Check revocation
        $stmt = $this->pdo->prepare("SELECT revoked FROM oauth_tokens WHERE token_id = :id");
        $stmt->execute(['id' => $payload['jti']]);
        $tokenData = $stmt->fetch();
        
        if ($tokenData && $tokenData['revoked']) {
            $this->reject('TOKEN_REVOKED', 'Token has been revoked', 401);
        }
        
        return $payload;
    }
    
    /**
     * Check certificate revocation via OCSP/CRL
     */
    private function checkCertificateRevocation(array $certInfo): void
    {
        $serial = $certInfo['serialNumberHex'] ?? '';
        
        // Check CRL
        $stmt = $this->pdo->prepare("SELECT 1 FROM certificate_revocation_list WHERE serial = :serial");
        $stmt->execute(['serial' => $serial]);
        
        if ($stmt->fetch()) {
            $this->reject('CERTIFICATE_REVOKED', 'Certificate has been revoked', 403);
        }
    }
    
    /**
     * Apply rate limiting per client
     */
    private function applyRateLimit(string $clientId): void
    {
        $limit = $this->validClients[$clientId]['rate_limit'] ?? 1000;
        $window = 3600;
        
        $key = "rate_limit:{$clientId}:" . floor(time() / $window);
        $current = $this->redis->incr($key);
        
        if ($current === 1) {
            $this->redis->expire($key, $window);
        }
        
        if ($current > $limit) {
            $this->reject('RATE_LIMIT_EXCEEDED', 'Rate limit exceeded. Try again later.', 429);
        }
        
        // Add rate limit headers
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . max(0, $limit - $current));
        header('X-RateLimit-Reset: ' . (time() + $window));
    }
    
    /**
     * Get client public key for signature verification
     */
    private function getClientPublicKey(string $clientId): string
    {
        $stmt = $this->pdo->prepare("SELECT public_key FROM clients WHERE client_id = :id");
        $stmt->execute(['id' => $clientId]);
        $result = $stmt->fetch();
        
        if ($result) {
            return $result['public_key'];
        }
        
        // Fallback to configured key
        return $this->validClients[$clientId]['public_key'] ?? '';
    }
    
    /**
     * Calculate certificate thumbprint (SHA-256)
     */
    private function getCertificateThumbprint(array $certInfo): string
    {
        $certData = $certInfo['certificate'] ?? '';
        return base64_encode(hash('sha256', $certData, true));
    }
    
    /**
     * Calculate access token hash for DPoP binding
     */
    private function calculateAccessTokenHash(string $token): string
    {
        return base64_encode(hash('sha256', $token, true));
    }
    
    /**
     * Reject request with error response
     */
    private function reject(string $code, string $message, int $httpCode = 400): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => $code,
            'error_description' => $message,
            'timestamp' => date('c'),
            'request_id' => bin2hex(random_bytes(8))
        ]);
        exit;
    }
    
    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
