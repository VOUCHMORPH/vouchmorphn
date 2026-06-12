<?php
// VouchMorph Side: test_signature_generation.php
// Run this on VouchMorph's server to test different signature methods

// Load their private key (from file or environment)
$privateKeyContent = file_get_contents('path/to/their/private.key');
// Or from environment: $privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY');

// Ensure proper line breaks
$privateKeyContent = str_replace(['\\n', '\n'], "\n", $privateKeyContent);
$privateKey = openssl_pkey_get_private($privateKeyContent);

if (!$privateKey) {
    die("Failed to load private key: " . openssl_error_string());
}

// Create test payload
$payload = [
    'action' => 'PLACE_HOLD',
    'reference' => 'TEST_' . (time() * 1000), // milliseconds like JS Date.now()
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'timestamp' => time()
];

echo "========== SIGNATURE GENERATION TESTS ==========\n";
echo "Test Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

// Method 1: Standard JSON (no sorting, as-is)
$json1 = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$signature1 = '';
openssl_sign($json1, $signature1, $privateKey, OPENSSL_ALGO_SHA256);
$signature1_b64 = base64_encode($signature1);

echo "Method 1 (No sort, original order):\n";
echo "  JSON: " . $json1 . "\n";
echo "  Signature: " . $signature1_b64 . "\n\n";

// Method 2: Sorted keys
$sorted = $payload;
ksort($sorted);
$json2 = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$signature2 = '';
openssl_sign($json2, $signature2, $privateKey, OPENSSL_ALGO_SHA256);
$signature2_b64 = base64_encode($signature2);

echo "Method 2 (Sorted keys alphabetically):\n";
echo "  JSON: " . $json2 . "\n";
echo "  Signature: " . $signature2_b64 . "\n\n";

// Method 3: With _timestamp instead of timestamp
$withUnderscore = $payload;
$withUnderscore['_timestamp'] = $withUnderscore['timestamp'];
unset($withUnderscore['timestamp']);
$json3 = json_encode($withUnderscore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$signature3 = '';
openssl_sign($json3, $signature3, $privateKey, OPENSSL_ALGO_SHA256);
$signature3_b64 = base64_encode($signature3);

echo "Method 3 (_timestamp instead of timestamp):\n";
echo "  JSON: " . $json3 . "\n";
echo "  Signature: " . $signature3_b64 . "\n\n";

// Method 4: With JSON_PRESERVE_ZERO_FRACTION (forces .0 on integers)
$json4 = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
$signature4 = '';
openssl_sign($json4, $signature4, $privateKey, OPENSSL_ALGO_SHA256);
$signature4_b64 = base64_encode($signature4);

echo "Method 4 (JSON_PRESERVE_ZERO_FRACTION):\n";
echo "  JSON: " . $json4 . "\n";
echo "  Signature: " . $signature4_b64 . "\n\n";

// Method 5: Without JSON_UNESCAPED_SLASHES (slashes escaped)
$json5 = json_encode($payload);
$signature5 = '';
openssl_sign($json5, $signature5, $privateKey, OPENSSL_ALGO_SHA256);
$signature5_b64 = base64_encode($signature5);

echo "Method 5 (Slashes escaped - default JSON):\n";
echo "  JSON: " . $json5 . "\n";
echo "  Signature: " . $signature5_b64 . "\n\n";

// Method 6: Without any timestamp field
$noTimestamp = $payload;
unset($noTimestamp['timestamp']);
$json6 = json_encode($noTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$signature6 = '';
openssl_sign($json6, $signature6, $privateKey, OPENSSL_ALGO_SHA256);
$signature6_b64 = base64_encode($signature6);

echo "Method 6 (No timestamp field):\n";
echo "  JSON: " . $json6 . "\n";
echo "  Signature: " . $signature6_b64 . "\n\n";

// Generate public key fingerprint for verification
$publicKeyDetails = openssl_pkey_get_details(openssl_pkey_get_public($privateKey));
if ($publicKeyDetails && isset($publicKeyDetails['key'])) {
    $publicKeyFingerprint = hash('sha256', $publicKeyDetails['key']);
    echo "Public Key Fingerprint: " . $publicKeyFingerprint . "\n";
}

echo "\n========== INSTRUCTIONS ==========\n";
echo "Send these signatures and the public key fingerprint to Saccussalis for testing.\n";
echo "Also send the EXACT JSON strings used for signing.\n";

// Clean up
openssl_free_key($privateKey);
?>
