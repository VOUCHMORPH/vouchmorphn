<?php
// /public/debug_key.php

header("Content-Type: text/plain");

echo "========================================\n";
echo "Debugging Private Key Loading\n";
echo "========================================\n\n";

// Get the private key from environment
$privateKeyEnv = getenv('VOUCHMORPH_PRIVATE_KEY');

echo "1. Environment variable check:\n";
echo "   VOUCHMORPH_PRIVATE_KEY exists: " . ($privateKeyEnv ? "YES" : "NO") . "\n";
if ($privateKeyEnv) {
    echo "   Length: " . strlen($privateKeyEnv) . " characters\n";
    echo "   First 50 chars: " . substr($privateKeyEnv, 0, 50) . "...\n";
    echo "   Contains 'BEGIN PRIVATE KEY': " . (strpos($privateKeyEnv, 'BEGIN PRIVATE KEY') !== false ? "YES" : "NO") . "\n";
    echo "   Contains '\\n': " . (strpos($privateKeyEnv, '\\n') !== false ? "YES" : "NO") . "\n";
    echo "   Contains actual newlines: " . (strpos($privateKeyEnv, "\n") !== false ? "YES" : "NO") . "\n";
}

echo "\n2. Attempting to load with openssl_pkey_get_private():\n";

// Try with raw value
$key1 = openssl_pkey_get_private($privateKeyEnv);
echo "   Direct load: " . ($key1 ? "SUCCESS" : "FAILED - " . openssl_error_string()) . "\n";

// Try after replacing literal \n with actual newlines
$fixedKey = str_replace('\\n', "\n", $privateKeyEnv);
$key2 = openssl_pkey_get_private($fixedKey);
echo "   After replacing \\n: " . ($key2 ? "SUCCESS" : "FAILED - " . openssl_error_string()) . "\n";

// Try after ensuring proper PEM format
if (strpos($fixedKey, '-----BEGIN PRIVATE KEY-----') === false) {
    $fixedKey = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(trim($fixedKey), 64, "\n") . "-----END PRIVATE KEY-----\n";
}
$key3 = openssl_pkey_get_private($fixedKey);
echo "   After ensuring PEM format: " . ($key3 ? "SUCCESS" : "FAILED - " . openssl_error_string()) . "\n";

echo "\n3. Current Railway variable format:\n";
echo "   If you see '\\n' in the output below, the key is stored with literal backslash-n:\n";
echo "   " . substr(json_encode($privateKeyEnv), 0, 200) . "...\n";
