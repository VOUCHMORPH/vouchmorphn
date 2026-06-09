<?php
// Secure Vault Reader - No environment variables needed
class RailwayVaultReader {
    private $vaultUrl;
    private $vaultToken;
    
    public function __construct() {
        // These come from Railway injection (not API keys)
        $this->vaultUrl = getenv('RAILWAY_SERVICE_VAULT_URL');
        $this->vaultToken = getenv('RAILWAY_VAULT_TOKEN');
        
        if (!$this->vaultUrl || !$this->vaultToken) {
            error_log("Vault not available - running in local mode");
        }
    }
    
    /**
     * Get secret directly from Railway Vault
     */
    public function getSecret(string $path, string $key = 'value'): ?string {
        if (!$this->vaultUrl || !$this->vaultToken) {
            // Fallback for local development only
            return getenv($path) ?: null;
        }
        
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->vaultUrl . '/v1/' . ltrim($path, '/'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->vaultToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return $data['data'][$key] ?? $data[$key] ?? null;
            }
            
            error_log("Vault read failed for {$path}: HTTP {$httpCode}");
            return null;
            
        } catch (Exception $e) {
            error_log("Vault error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get API key for a specific participant
     */
    public function getApiKey(string $participant): ?string {
        // Try multiple path patterns
        $paths = [
            "secret/participants/{$participant}/api_key",
            "secret/swap-system/api_keys/{$participant}",
            "secret/api_keys/{$participant}",
            "kv/participants/{$participant}"
        ];
        
        foreach ($paths as $path) {
            $key = $this->getSecret($path);
            if ($key) {
                error_log("Retrieved API key for {$participant} from vault path: {$path}");
                return $key;
            }
        }
        
        return null;
    }
    
    /**
     * Get all API keys from vault
     */
    public function getAllApiKeys(): array {
        $keys = [];
        
        // List of participants from config
        $participants = ['ZURUBANK', 'SACCUSSALIS', 'CAZACOM', 'VOUCHMORPH'];
        
        foreach ($participants as $participant) {
            $key = $this->getApiKey($participant);
            if ($key) {
                $keys[] = $key;
            }
        }
        
        return $keys;
    }
}

// Initialize vault reader
$vault = new RailwayVaultReader();

// For authentication - read API keys from vault, NOT from env
function authenticate($providedKey, $vault) {
    $validKeys = $vault->getAllApiKeys();
    
    // Also check system key
    $systemKey = $vault->getSecret('secret/swap-system/system_api_key');
    if ($systemKey) {
        $validKeys[] = $systemKey;
    }
    
    if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid API key',
            'message' => 'API key not found in vault'
        ]);
        exit();
    }
    
    return true;
}
