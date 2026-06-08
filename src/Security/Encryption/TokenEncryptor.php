<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

namespace Security\Encryption;

use Security\Encryption\KeyVault;

class TokenEncryptor
{
    private string $cipher = 'AES-256-CBC';
    private string $key;

    public function __construct(?string $key = null)
    {
        if ($key === null) {
            $keyVault = KeyVault::getInstance();
            $key = $keyVault->getEncryptionKey();
        }
        
        $this->key = hash('sha256', $key, true);
    }

    public function encrypt(string $data): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $encrypted): string
    {
        $decoded = base64_decode($encrypted);
        $iv = substr($decoded, 0, 16);
        $ciphertext = substr($decoded, 16);
        return openssl_decrypt($ciphertext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);
    }
}
