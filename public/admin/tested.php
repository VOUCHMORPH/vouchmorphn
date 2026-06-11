<?php
header("Content-Type: text/plain");

$privateKey = getenv('VOUCHMORPH_PRIVATE_KEY');

// Convert literal \n to actual newlines
$privateKey = str_replace('\\n', "\n", $privateKey);
$privateKey = str_replace('\n', "\n", $privateKey);

$key = openssl_pkey_get_private($privateKey);
if (!$key) {
    echo "ERROR: Failed to load private key\n";
    exit;
}

// Extract the public key
$details = openssl_pkey_get_details($key);
$publicKey = $details['key'];

echo "=== THIS IS THE EXACT PUBLIC KEY THAT MATCHES YOUR PRIVATE KEY ===\n\n";
echo $publicKey;
echo "\n\n=== Copy this entire public key into Saccussalis trusted_partners table ===\n";
