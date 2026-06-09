<?php
// /public/admin/test.php
header('Content-Type: application/json');

// Secure Vault Reader
class RailwayVaultReader {
    private $vaultUrl;
    private $vaultToken;
    
    public function __construct() {
        $this->vaultUrl = getenv('RAILWAY_SERVICE_VAULT_URL');
        $this->vaultToken = getenv('RAILWAY_VAULT_TOKEN');
    }
    
    public function getSecret(string $path, string $key = 'value'): ?string {
        if (!$this->vaultUrl || !$this->vaultToken) {
            return null;
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
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return $data['data'][$key] ?? $data[$key] ?? null;
            }
            
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    public function getApiKey(string $participant): ?string {
        $paths = [
            "secret/participants/{$participant}/api_key",
            "secret/swap-system/api_keys/{$participant}",
            "secret/api_keys/{$participant}"
        ];
        
        foreach ($paths as $path) {
            $key = $this->getSecret($path);
            if ($key) return $key;
        }
        
        return null;
    }
    
    public function getAllApiKeys(): array {
        $keys = [];
        $participants = ['ZURUBANK', 'SACCUSSALIS', 'CAZACOM', 'VOUCHMORPH'];
        
        foreach ($participants as $participant) {
            $key = $this->getApiKey($participant);
            if ($key) $keys[$participant] = $key;
        }
        
        return $keys;
    }
}

// Initialize
$vault = new RailwayVaultReader();

// Collect debug info
$debug = [
    'vault_url_available' => getenv('RAILWAY_SERVICE_VAULT_URL') ? 'yes' : 'no',
    'vault_token_available' => getenv('RAILWAY_VAULT_TOKEN') ? 'yes' : 'no',
    'vault_url_value' => getenv('RAILWAY_SERVICE_VAULT_URL') ? substr(getenv('RAILWAY_SERVICE_VAULT_URL'), 0, 30) . '...' : 'not set',
    'api_keys_from_vault' => [],
    'environment_variables' => []
];

// Try to get API keys from vault
$keys = $vault->getAllApiKeys();
if (!empty($keys)) {
    foreach ($keys as $participant => $key) {
        $debug['api_keys_from_vault'][$participant] = substr($key, 0, 10) . '...' . substr($key, -5);
    }
} else {
    $debug['api_keys_from_vault'] = 'No keys found in vault';
}

// Also check environment variables (for debugging)
$env_keys = ['API_KEY_SYSTEM', 'API_KEY_ZURUBANK', 'API_KEY_SACCUSSALIS', 'VOUCHMORPH_API_KEY'];
foreach ($env_keys as $key) {
    $value = getenv($key);
    if ($value) {
        $debug['environment_variables'][$key] = substr($value, 0, 10) . '...' . substr($value, -5);
    }
}

// Output results
echo json_encode([
    'service' => 'Railway Vault Test',
    'timestamp' => date('Y-m-d H:i:s'),
    'debug' => $debug,
    'status' => !empty($keys) ? 'Vault working' : 'Vault not accessible',
    'next_steps' => empty($keys) ? [
        '1. Go to Railway Dashboard',
        '2. Click on your service',
        '3. Go to Variables tab',
        '4. Add API keys as service variables (not just project variables)',
        '5. Or use: railway variables set API_KEY_ZURUBANK=your_key_here'
    ] : 'Vault is working! Use the keys above.'
], JSON_PRETTY_PRINT);
