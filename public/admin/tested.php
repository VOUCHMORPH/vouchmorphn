<?php
// test_vault_api.php
// Run: php test_vault_api.php

echo "═══════════════════════════════════════════════════════════════════\n";
echo "VAULT API TEST (Fetch secrets directly from Vault)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$vaultUrl = getenv('RAILWAY_SERVICE_VAULT_URL');
$vaultToken = getenv('RAILWAY_SERVICE_VAULT_TOKEN') ?: getenv('VAULT_TOKEN');

echo "Vault URL: {$vaultUrl}\n";
echo "Vault Token: " . ($vaultToken ? '***HIDDEN***' : 'NOT FOUND') . "\n\n";

if (!$vaultUrl) {
    echo "❌ VAULT_URL not found\n";
    exit(1);
}

if (!$vaultToken) {
    echo "❌ VAULT_TOKEN not found\n";
    echo "   Railway should automatically inject this when services are linked.\n";
    exit(1);
}

// Function to fetch secret from Vault
function fetchVaultSecret($vaultUrl, $vaultToken, $path) {
    $url = "https://{$vaultUrl}/v1/secret/data/{$path}";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-Vault-Token: {$vaultToken}",
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['data']['data'] ?? null;
    }
    
    return null;
}

// List of secrets to fetch
$secretsToFetch = [
    'zurubank',
    'saccussalis', 
    'cazacom'
];

foreach ($secretsToFetch as $secret) {
    echo "▶ Fetching: {$secret}\n";
    $result = fetchVaultSecret($vaultUrl, $vaultToken, $secret);
    
    if ($result) {
        echo "   ✅ SUCCESS:\n";
        foreach ($result as $key => $value) {
            $displayValue = (strpos($key, 'api_key') !== false || strpos($key, 'key') !== false) 
                ? '***HIDDEN***' 
                : $value;
            echo "      {$key}: {$displayValue}\n";
        }
    } else {
        echo "   ❌ FAILED - secret not found or access denied\n";
    }
    echo "\n";
}

echo "═══════════════════════════════════════════════════════════════════\n";
