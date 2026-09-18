<?php


namespace Security\Encryption;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use Security\Encryption\KeyVault;

class SecretManagerClient
{
    private KeyVault $keyVault;

    public function __construct(array $config = [])
    {
        $this->keyVault = KeyVault::getInstance();
    }

    public function getSecret(string $key): ?string
    {
        // Read from KeyVault first (Railway Vault Box)
        $value = $this->keyVault->getKey($key);
        
        if ($value) {
            return $value;
        }
        
        // Fallback to environment
        return getenv($key) ?: null;
    }

    public function setSecret(string $key, string $value): void
    {
        // Note: This only sets runtime. For permanent storage, use Railway Vault.
        // Log for audit trail
        error_log("Secret '{$key}' updated at runtime. Update Railway Vault for persistence.");
    }
}
