<?php
// src/Security/Auth/TokenValidator.php

namespace Security\Auth;

use Security\FapiCompliantAuth;
use Security\HSMKeyManager;
use Security\AuditLogger;

/**
 * Enterprise-grade token validator with PSD2 compliance
 */
class TokenValidator
{
    private static ?FapiCompliantAuth $fapi = null;
    private static ?HSMKeyManager $hsm = null;
    private static ?AuditLogger $audit = null;
    
    private static function init(): void
    {
        if (self::$fapi === null) {
            self::$hsm = new HSMKeyManager();
            self::$fapi = new FapiCompliantAuth(self::$hsm);
            self::$audit = new AuditLogger();
        }
    }
    
    /**
     * Validate complete request with mTLS + DPoP + Token
     * This is the main entry point for all API endpoints
     */
    public static function validateSecureRequest(): array
    {
        self::init();
        
        $requestId = bin2hex(random_bytes(16));
        $startTime = microtime(true);
        
        try {
            $result = self::$fapi->validateRequest();
            
            // Log successful validation
            self::$audit->logAuthentication(
                $result['client_id'],
                'success',
                ['request_id' => $requestId]
            );
            
            return $result;
            
        } catch (\Exception $e) {
            // Log failed validation
            self::$audit->logAuthentication(
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'failure',
                ['error' => $e->getMessage(), 'request_id' => $requestId]
            );
            
            throw $e;
        }
    }
    
    /**
     * Legacy simple token validation (for internal use only)
     * WARNING: Do not use for external API endpoints
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
     * Generate JWT for service-to-service authentication
     */
    public static function generateServiceToken(string $clientId, array $scopes = ['read_balance'], int $ttl = 300): string
    {
        self::init();
        
        $now = time();
        $payload = [
            'jti' => bin2hex(random_bytes(16)),
            'iss' => getenv('OAUTH_ISSUER') ?: 'vouchmorph.internal',
            'sub' => $clientId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'scope' => $scopes,
            'client_id' => $clientId
        ];
        
        $header = ['alg' => 'PS256', 'typ' => 'JWT'];
        $encodedHeader = self::base64UrlEncode(json_encode($header));
        $encodedPayload = self::base64UrlEncode(json_encode($payload));
        
        $signatureInput = $encodedHeader . '.' . $encodedPayload;
        $signature = self::$hsm->sign(getenv('HSM_SIGNING_KEY'), $signatureInput);
        
        return $encodedHeader . '.' . $encodedPayload . '.' . $signature;
    }
    
    /**
     * Generate DPoP proof for outgoing requests
     */
    public static function generateDpopProof(string $method, string $url, string $accessToken = null): string
    {
        $now = time();
        $nonce = bin2hex(random_bytes(16));
        
        $payload = [
            'jti' => bin2hex(random_bytes(16)),
            'htm' => $method,
            'htu' => $url,
            'iat' => $now,
            'nonce' => $nonce
        ];
        
        if ($accessToken) {
            $payload['ath'] = base64_encode(hash('sha256', $accessToken, true));
        }
        
        $header = ['alg' => 'PS256', 'typ' => 'dpop+jwt'];
        $encodedHeader = self::base64UrlEncode(json_encode($header));
        $encodedPayload = self::base64UrlEncode(json_encode($payload));
        
        $signatureInput = $encodedHeader . '.' . $encodedPayload;
        
        self::init();
        $signature = self::$hsm->sign(getenv('HSM_CLIENT_KEY'), $signatureInput);
        
        return $encodedHeader . '.' . $encodedPayload . '.' . $signature;
    }
    
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
