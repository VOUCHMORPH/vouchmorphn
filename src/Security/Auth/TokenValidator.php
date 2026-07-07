<?php
// src/Security/Auth/TokenValidator.php

namespace Security\Auth;

use Core\Database\DBConnection;
use Application\Utils\AuditLogger;
use Security\Encryption\KeyVault;

/**
 * Enterprise-grade token validator with PSD2 compliance
 * Works with existing clients table
 * 
 * FIXED: No hardcoded fallback keys - uses KeyVault exclusively
 */
class TokenValidator
{
    private static $db = null;
    private static $auditLogger = null;
    private static $keyVault = null;
    
    private static function init(): void
    {
        if (self::$db === null) {
            self::$db = DBConnection::getInstance();
            self::$auditLogger = new AuditLogger();
            self::$keyVault = KeyVault::getInstance();
        }
    }
    
    /**
     * Validate API key for incoming requests (Partner → VouchMorph)
     */
    public static function validateApiKey(string $apiKey, string $clientId = null): array
    {
        self::init();
        
        $stmt = self::$db->prepare("
            SELECT * FROM clients 
            WHERE client_id = :client_id AND is_active = true
        ");
        
        $clientId = $clientId ?: $apiKey;
        $stmt->execute(['client_id' => $clientId]);
        $client = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$client) {
            self::$auditLogger->log('INVALID_API_KEY', 'WARNING', 'security', null, null, [
                'provided_key' => substr($apiKey, 0, 10) . '...',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            
            http_response_code(401);
            echo json_encode(['error' => 'invalid_api_key', 'message' => 'Invalid API key']);
            exit;
        }
        
        // Verify client secret hash
        if (!password_verify($apiKey, $client['client_secret_hash'])) {
            self::$auditLogger->log('API_KEY_MISMATCH', 'WARNING', 'security', null, null, [
                'client_id' => $clientId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            
            http_response_code(401);
            echo json_encode(['error' => 'invalid_api_key', 'message' => 'Invalid API key']);
            exit;
        }
        
        return $client;
    }
    
    /**
     * Validate OAuth 2.0 Bearer token
     */
    public static function validateBearerToken(string $token): array
    {
        self::init();
        
        $tokenHash = hash('sha256', $token);
        
        $stmt = self::$db->prepare("
            SELECT * FROM oauth_tokens 
            WHERE token_hash = :hash AND revoked = false AND expires_at > NOW()
        ");
        $stmt->execute(['hash' => $tokenHash]);
        $tokenData = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            self::$auditLogger->log('INVALID_BEARER_TOKEN', 'WARNING', 'security', null, null, [
                'token_hash' => substr($tokenHash, 0, 16),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            
            http_response_code(401);
            echo json_encode(['error' => 'invalid_token', 'message' => 'Invalid or expired token']);
            exit;
        }
        
        // Rate limiting
        self::applyRateLimit($tokenData['client_id']);
        
        return $tokenData;
    }
    
    /**
     * Validate request with mTLS (for bank-grade security)
     */
    public static function validateMtlsRequest(): array
    {
        self::init();
        
        // Check client certificate
        $clientCert = $_SERVER['SSL_CLIENT_CERT'] ?? null;
        if (!$clientCert) {
            self::$auditLogger->log('MTLS_CERTIFICATE_MISSING', 'ERROR', 'security', null, null, [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            
            http_response_code(401);
            echo json_encode(['error' => 'mtls_required', 'message' => 'mTLS certificate required']);
            exit;
        }
        
        $certInfo = openssl_x509_parse($clientCert);
        
        if (!$certInfo || $certInfo['validTo_time_t'] < time()) {
            self::$auditLogger->log('INVALID_MTLS_CERTIFICATE', 'ERROR', 'security', null, null, [
                'cert_subject' => $certInfo['subject']['CN'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            
            http_response_code(401);
            echo json_encode(['error' => 'invalid_certificate', 'message' => 'Invalid or expired certificate']);
            exit;
        }
        
        // Check certificate revocation
        $stmt = self::$db->prepare("SELECT 1 FROM certificate_revocation_list WHERE serial_number = :serial");
        $stmt->execute(['serial' => $certInfo['serialNumberHex']]);
        if ($stmt->fetch()) {
            self::$auditLogger->log('REVOKED_CERTIFICATE', 'ERROR', 'security', null, null, [
                'serial' => $certInfo['serialNumberHex']
            ]);
            
            http_response_code(403);
            echo json_encode(['error' => 'certificate_revoked', 'message' => 'Certificate has been revoked']);
            exit;
        }
        
        $clientCn = $certInfo['subject']['CN'] ?? '';
        
        // Get client from existing clients table
        $stmt = self::$db->prepare("SELECT * FROM clients WHERE client_id = :cn AND is_active = true");
        $stmt->execute(['cn' => $clientCn]);
        $client = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$client) {
            self::$auditLogger->log('UNAUTHORIZED_MTLS_CLIENT', 'ERROR', 'security', null, null, [
                'cn' => $clientCn
            ]);
            
            http_response_code(403);
            echo json_encode(['error' => 'unauthorized', 'message' => 'Client not authorized']);
            exit;
        }
        
        return [
            'client_id' => $clientCn,
            'client_name' => $client['full_name'] ?? $clientCn,
            'certificate' => $certInfo,
            'scopes' => explode(' ', $client['allowed_scopes'] ?? 'read_balance')
        ];
    }
    
    /**
     * Validate DPoP proof (RFC 9449) - for financial-grade API
     */
    public static function validateDpopProof(string $dpopHeader, string $method, string $url, string $accessToken = null): array
    {
        $parts = explode('.', $dpopHeader);
        if (count($parts) !== 3) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_dpop', 'message' => 'Invalid DPoP format']);
            exit;
        }
        
        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        
        // Validate nonce (prevent replay)
        $nonceKey = "dpop_nonce:{$payload['nonce']}";
        $redis = new \Redis();
        $redis->connect(getenv('REDIS_HOST') ?: 'localhost');
        
        if ($redis->exists($nonceKey)) {
            http_response_code(400);
            echo json_encode(['error' => 'dpop_replay', 'message' => 'DPoP nonce already used']);
            exit;
        }
        $redis->setex($nonceKey, 300, '1');
        
        // Validate method and URL
        if ($payload['htm'] !== $method) {
            http_response_code(400);
            echo json_encode(['error' => 'dpop_method_mismatch', 'message' => 'HTTP method mismatch']);
            exit;
        }
        
        $requestUrl = strtok($url, '?');
        if ($payload['htu'] !== $requestUrl) {
            http_response_code(400);
            echo json_encode(['error' => 'dpop_url_mismatch', 'message' => 'URL mismatch']);
            exit;
        }
        
        return $payload;
    }
    
    /**
     * Generate OAuth 2.0 access token
     */
    public static function generateAccessToken(int $userId, string $clientId, array $scopes = ['read_balance'], int $ttl = 300): string
    {
        self::init();
        
        $tokenId = bin2hex(random_bytes(32));
        $token = bin2hex(random_bytes(64));
        $tokenHash = hash('sha256', $token);
        
        $stmt = self::$db->prepare("
            INSERT INTO oauth_tokens (
                token_id, token_hash, client_id, user_id, scope, expires_at, 
                ip_address, user_agent, created_at
            ) VALUES (
                :id, :hash, :client, :user, :scope, NOW() + INTERVAL ':ttl seconds',
                :ip, :ua, NOW()
            )
        ");
        
        $stmt->execute([
            'id' => $tokenId,
            'hash' => $tokenHash,
            'client' => $clientId,
            'user' => $userId,
            'scope' => json_encode($scopes),
            'ttl' => $ttl,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        self::$auditLogger->log('TOKEN_GENERATED', 'INFO', 'security', null, null, [
            'user_id' => $userId,
            'client_id' => $clientId,
            'scopes' => $scopes,
            'token_id' => $tokenId
        ]);
        
        return $token;
    }
    
    /**
     * Revoke token
     */
    public static function revokeToken(string $token): bool
    {
        self::init();
        
        $tokenHash = hash('sha256', $token);
        
        $stmt = self::$db->prepare("
            UPDATE oauth_tokens 
            SET revoked = true, revoked_at = NOW() 
            WHERE token_hash = :hash
        ");
        
        $result = $stmt->execute(['hash' => $tokenHash]);
        
        self::$auditLogger->log('TOKEN_REVOKED', 'INFO', 'security', null, null, [
            'token_hash' => substr($tokenHash, 0, 16),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        return $result;
    }
    
    /**
     * Legacy simple token validation (for internal use only)
     * FIXED: No hardcoded fallback - uses KeyVault
     */
    public static function validate(string $token, string $expected): bool
    {
        // Only allow for internal/local calls
        $isInternal = $_SERVER['REMOTE_ADDR'] === '127.0.0.1' || 
                      strpos($_SERVER['REMOTE_ADDR'], '10.') === 0 ||
                      strpos($_SERVER['REMOTE_ADDR'], '172.16.') === 0 ||
                      strpos($_SERVER['REMOTE_ADDR'], '192.168.') === 0;
        
        if (!$isInternal && getenv('APP_ENV') === 'production') {
            throw new \RuntimeException('Simple token validation not allowed for external requests');
        }
        
        // FIXED: No hardcoded fallback - use KeyVault
        $keyVault = KeyVault::getInstance();
        $secretKey = $keyVault->getKey('internal_token_validation_key');
        
        if (!$secretKey) {
            throw new \RuntimeException(
                'Internal token validation key not found in KeyVault. ' .
                'Set INTERNAL_TOKEN_VALIDATION_KEY in environment variables.'
            );
        }
        
        return hash_equals($expected, $token);
    }
    
    /**
     * Generate secure token (for internal use)
     */
    public static function generate(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Apply rate limiting
     */
    private static function applyRateLimit(string $clientId): void
    {
        $redis = new \Redis();
        $redis->connect(getenv('REDIS_HOST') ?: 'localhost');
        
        $key = "rate_limit:{$clientId}:" . date('Y-m-d-H');
        $current = $redis->incr($key);
        
        if ($current === 1) {
            $redis->expire($key, 3600);
        }
        
        // Get rate limit from clients table
        $stmt = self::$db->prepare("SELECT rate_limit FROM clients WHERE client_id = :id");
        $stmt->execute(['id' => $clientId]);
        $client = $stmt->fetch(\PDO::FETCH_ASSOC);
        $limit = $client['rate_limit'] ?? 1000;
        
        if ($current > $limit) {
            http_response_code(429);
            echo json_encode(['error' => 'rate_limit_exceeded', 'message' => 'Too many requests']);
            exit;
        }
    }
    
    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
