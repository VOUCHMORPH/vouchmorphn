<?php
// src/Security/HSMKeyManager.php

namespace Security;

use Security\Encryption\KeyVault;

/**
 * Hardware Security Module Interface
 * ISO 27001:2022 Annex A.10 (Cryptography)
 * Keys NEVER leave the HSM - European banking standard
 * 
 * WARNING: SoftwareHSM is NOT persistent across requests.
 * Keys generated in one request cannot be used in another.
 * This is suitable ONLY for development/testing.
 * For production, set HSM_TYPE to 'aws' or 'azure'.
 */
class HSMKeyManager
{
    private $hsmClient;
    private $sessionHandle;
    private $masterKeyHandle;
    private $vaultClient;
    private string $hsmType;
    
    public function __construct()
    {
        $this->hsmType = getenv('HSM_TYPE') ?: 'software';
        
        // Check if using software HSM in production
        if ($this->hsmType === 'software' && getenv('APP_ENV') === 'production') {
            error_log("[HSMKeyManager] WARNING: Using SoftwareHSM in production! " .
                      "Keys will NOT persist across requests. " .
                      "Set HSM_TYPE to 'aws' or 'azure' for production.");
        }
        
        $this->connectToHSM();
        $this->authenticate();
        $this->loadMasterKey();
    }

    private function connectToHSM(): void
    {
        $hsmType = getenv('HSM_TYPE') ?: 'software';

        if ($hsmType === 'aws') {
            if (!class_exists(\Aws\CloudHSM\CloudHSMClient::class)) {
                throw new \RuntimeException(
                    "HSM_TYPE=aws but the AWS SDK is not installed. Run: composer require aws/aws-sdk-php"
                );
            }
            $this->hsmClient = new \Aws\CloudHSM\CloudHSMClient([
                'region' => getenv('AWS_REGION') ?: 'af-south-1',
                'endpoint' => getenv('HSM_ENDPOINT'),
                'version' => 'latest',
                'credentials' => [
                    'key' => $this->getSecretFromVault('aws/access_key'),
                    'secret' => $this->getSecretFromVault('aws/secret_key')
                ]
            ]);
        } elseif ($hsmType === 'azure') {
            if (!class_exists(\Azure\KeyVault\KeyClient::class)) {
                throw new \RuntimeException(
                    "HSM_TYPE=azure but the Azure SDK is not installed. Run: composer require azure/azure-sdk-for-php"
                );
            }
            $this->hsmClient = new \Azure\KeyVault\KeyClient(
                getenv('AZURE_VAULT_URL'),
                new \Azure\Identity\DefaultAzureCredential()
            );
        } else {
            // Software HSM for development (NEVER in production)
            error_log("[HSMKeyManager] Using SoftwareHSM - keys do not persist across requests");
            $this->hsmClient = new SoftwareHSM();
        }
    }
    
    private function authenticate(): void
    {
        $result = $this->hsmClient->login([
            'Username' => $this->getSecretFromVault('hsm/app_user'),
            'Password' => $this->getSecretFromVault('hsm/app_password'),
            'AuthenticationMethod' => 'CERTIFICATE'
        ]);
        
        $this->sessionHandle = $result['SessionHandle'] ?? bin2hex(random_bytes(16));
    }
    
    private function loadMasterKey(): void
    {
        $masterKeyId = getenv('HSM_MASTER_KEY_ID');
        if (!$masterKeyId) {
            $masterKeyId = $this->generateKey('vouchmorph_master', [
                'spec' => 'AES_256',
                'usage' => ['ENCRYPT_DECRYPT']
            ]);
        }
        
        $this->masterKeyHandle = $masterKeyId;
    }
    
    private function getVaultClient(): ?\GuzzleHttp\Client
    {
        if ($this->vaultClient !== null) {
            return $this->vaultClient;
        }

        if (!class_exists(\GuzzleHttp\Client::class)) {
            return null;
        }

        // The token used to talk to Vault must come from the environment,
        // not from Vault itself - getSecretFromVault() falls back to env
        // when there is no client yet, which is exactly what happens here.
        $this->vaultClient = new \GuzzleHttp\Client([
            'base_uri' => getenv('VAULT_ADDR') ?: 'https://vault.internal:8200',
            'headers' => [
                'X-Vault-Token' => getenv('VAULT_TOKEN') ?: ''
            ]
        ]);

        return $this->vaultClient;
    }
    
    /**
     * Sign data using HSM - key never leaves hardware
     */
    public function sign(string $keyHandle, string $data, string $algorithm = 'RSASSA_PSS_SHA_256'): string
    {
        $result = $this->hsmClient->sign([
            'KeyHandle' => $keyHandle,
            'Message' => hash('sha256', $data, true),
            'SigningAlgorithm' => $algorithm,
            'SessionHandle' => $this->sessionHandle
        ]);
        
        return base64_encode($result['Signature']);
    }
    
    /**
     * Verify signature using HSM
     */
    public function verify(string $keyHandle, string $data, string $signature): bool
    {
        $result = $this->hsmClient->verify([
            'KeyHandle' => $keyHandle,
            'Message' => hash('sha256', $data, true),
            'Signature' => base64_decode($signature),
            'SigningAlgorithm' => 'RSASSA_PSS_SHA_256',
            'SessionHandle' => $this->sessionHandle
        ]);
        
        return $result['Success'] ?? false;
    }
    
    /**
     * Generate new key - never extractable
     */
    public function generateKey(string $keyId, array $attributes): string
    {
        $result = $this->hsmClient->generateKey([
            'KeyId' => $keyId,
            'KeySpec' => $attributes['spec'] ?? 'RSA_4096',
            'KeyUsage' => $attributes['usage'] ?? ['ENCRYPT_DECRYPT', 'SIGN_VERIFY'],
            'Extractable' => false,
            'NoModify' => true,
            'Label' => "vouchmorph_{$keyId}",
            'SessionHandle' => $this->sessionHandle
        ]);
        
        return $result['KeyHandle'];
    }
    
    /**
     * Encrypt data using HSM
     */
    public function encrypt(string $plaintext, string $context = 'default'): array
    {
        $result = $this->hsmClient->encrypt([
            'KeyHandle' => $this->masterKeyHandle,
            'Plaintext' => $plaintext,
            'EncryptionAlgorithm' => 'AES_GCM',
            'SessionHandle' => $this->sessionHandle
        ]);
        
        return [
            'ciphertext' => base64_encode($result['Ciphertext']),
            'iv' => base64_encode($result['Iv'] ?? random_bytes(12)),
            'tag' => base64_encode($result['AuthenticationTag'] ?? '')
        ];
    }
    
    /**
     * Decrypt data using HSM
     */
    public function decrypt(string $ciphertext, string $iv, string $tag): string
    {
        $result = $this->hsmClient->decrypt([
            'KeyHandle' => $this->masterKeyHandle,
            'Ciphertext' => base64_decode($ciphertext),
            'Iv' => base64_decode($iv),
            'AuthenticationTag' => base64_decode($tag),
            'EncryptionAlgorithm' => 'AES_GCM',
            'SessionHandle' => $this->sessionHandle
        ]);
        
        return $result['Plaintext'];
    }
    
    private function getSecretFromVault(string $path): string
    {
        try {
            $client = $this->getVaultClient();
            if ($client === null) {
                throw new \RuntimeException('Vault client unavailable');
            }
            $response = $client->get('/v1/secret/data/' . $path);
            $data = json_decode($response->getBody(), true);
            return $data['data']['data']['value'];
        } catch (\Throwable $e) {
            // Fallback to environment for development
            return getenv(strtoupper(str_replace('/', '_', $path))) ?: '';
        }
    }
}

/**
 * Software HSM for development only (NEVER use in production)
 * 
 * WARNING: This implementation does NOT persist keys across requests.
 * Keys generated in one request are lost when the request ends.
 * This is suitable ONLY for testing/development.
 */
class SoftwareHSM
{
    private $keys = [];
    
    public function login(array $params): array
    {
        return ['SessionHandle' => bin2hex(random_bytes(16))];
    }
    
    public function generateKey(array $params): array
    {
        $keyId = $params['KeyId'];
        $spec = $params['KeySpec'] ?? 'RSA_4096';

        if (str_starts_with($spec, 'AES')) {
            $this->keys[$keyId] = ['type' => 'AES', 'material' => random_bytes(32)];
        } else {
            $this->keys[$keyId] = [
                'type' => 'RSA',
                'material' => openssl_pkey_new([
                    'private_key_bits' => 4096,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA
                ])
            ];
        }

        // Log warning about non-persistence
        error_log("[SoftwareHSM] WARNING: Generated key '{$keyId}' will NOT persist across requests");

        return ['KeyHandle' => $keyId];
    }

    public function encrypt(array $params): array
    {
        $key = $this->keys[$params['KeyHandle']] ?? null;
        if (!$key || $key['type'] !== 'AES') {
            throw new \RuntimeException("AES key handle '{$params['KeyHandle']}' not found - keys do not persist");
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($params['Plaintext'], 'aes-256-gcm', $key['material'], OPENSSL_RAW_DATA, $iv, $tag);

        return ['Ciphertext' => $ciphertext, 'Iv' => $iv, 'AuthenticationTag' => $tag];
    }

    public function decrypt(array $params): array
    {
        $key = $this->keys[$params['KeyHandle']] ?? null;
        if (!$key || $key['type'] !== 'AES') {
            throw new \RuntimeException("AES key handle '{$params['KeyHandle']}' not found - keys do not persist");
        }

        $plaintext = openssl_decrypt(
            $params['Ciphertext'],
            'aes-256-gcm',
            $key['material'],
            OPENSSL_RAW_DATA,
            $params['Iv'],
            $params['AuthenticationTag']
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed - invalid ciphertext or authentication tag');
        }

        return ['Plaintext' => $plaintext];
    }
    
    public function sign(array $params): array
    {
        $key = $this->keys[$params['KeyHandle']] ?? null;
        if (!$key || $key['type'] !== 'RSA') {
            throw new \RuntimeException("RSA key handle '{$params['KeyHandle']}' not found - keys do not persist");
        }
        openssl_sign($params['Message'], $signature, $key['material'], OPENSSL_ALGO_SHA256);
        return ['Signature' => $signature];
    }

    public function verify(array $params): array
    {
        $key = $this->keys[$params['KeyHandle']] ?? null;
        if (!$key || $key['type'] !== 'RSA') {
            return ['Success' => false];
        }
        $details = openssl_pkey_get_details($key['material']);
        $publicKey = openssl_pkey_get_public($details['key']);
        $valid = openssl_verify($params['Message'], $params['Signature'], $publicKey, OPENSSL_ALGO_SHA256);
        return ['Success' => $valid === 1];
    }
}
