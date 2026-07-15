<?php

namespace Security\Auth;

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

class JwtAuth
{
    private string $secretKey;

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    public function generateToken(array $payload, int $expiry = 3600): string
    {
        $payload['exp'] = time() + $expiry;
        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    public function verifyToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
            return (array)$decoded;
        } catch (\Exception $e) {
            return null;
        }
    }
}
