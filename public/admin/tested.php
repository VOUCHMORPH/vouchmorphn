<?php
// test_vault_secrets.php
// Run: php test_vault_secrets.php

echo "═══════════════════════════════════════════════════════════════════\n";
echo "VAULT SECRETS TEST (Reading from /vault/secrets/ ONLY)\n";
echo "NO ENVIRONMENT VARIABLE FALLBACK\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$vaultSecretsPath = '/vault/secrets/';

echo "Reading secrets from: {$vaultSecretsPath}\n";
echo "Directory exists: " . (file_exists($vaultSecretsPath) ? 'YES' : 'NO') . "\n\n";

// List all files in vault secrets directory
if (file_exists($vaultSecretsPath)) {
    $files = scandir($vaultSecretsPath);
    echo "Files in /vault/secrets/:\n";
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $content = trim(file_get_contents($vaultSecretsPath . $file));
            // Mask API keys
            if (strpos($file, 'api_key') !== false || strpos($file, 'key') !== false) {
                $displayContent = '***HIDDEN***';
            } else {
                $displayContent = $content;
            }
            echo "  📄 {$file} = {$displayContent}\n";
        }
    }
    echo "\n";
} else {
    echo "❌ /vault/secrets/ directory does NOT exist!\n";
    echo "   Vault is not mounted to this container.\n\n";
}

// Define required secrets
$requiredSecrets = [
    'zurubank_base_url',
    'zurubank_verify_endpoint',
    'zurubank_hold_endpoint',
    'zurubank_api_key',
    'saccussalis_base_url',
    'saccussalis_generate_token_endpoint',
    'saccussalis_api_key',
    'cazacom_base_url',
    'cazacom_verify_endpoint',
    'cazacom_hold_endpoint',
    'cazacom_api_key',
];

echo "Checking required secrets:\n\n";

$found = 0;
$missing = 0;

foreach ($requiredSecrets as $secret) {
    $filePath = $vaultSecretsPath . $secret;
    if (file_exists($filePath)) {
        $value = trim(file_get_contents($filePath));
        if (!empty($value)) {
            echo "✅ {$secret} = " . (strpos($secret, 'api_key') !== false ? '***HIDDEN***' : $value) . "\n";
            $found++;
        } else {
            echo "❌ {$secret} = EMPTY FILE\n";
            $missing++;
        }
    } else {
        echo "❌ {$secret} = FILE NOT FOUND\n";
        $missing++;
    }
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "Summary:\n";
echo "   Found: {$found}\n";
echo "   Missing: {$missing}\n";

if ($missing > 0) {
    echo "\n⚠️ Missing secrets in /vault/secrets/\n";
    echo "   Fix: Ensure Vault is properly linked to PHP service\n";
    echo "   Railway CLI: railway service connect --service php --to vault\n";
} else {
    echo "\n✅ ALL SECRETS AVAILABLE IN VAULT!\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
