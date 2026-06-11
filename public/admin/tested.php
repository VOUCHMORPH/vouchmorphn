<?php
// /public/diagnose.php - Run this on VOUCHMORPH
require_once __DIR__ . '/../../vendor/autoload.php';
use Infrastructure\Crypto\MessageSigner;

header("Content-Type: text/plain");

// Get private key
$privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY');
$privateKeyContent = str_replace('\\n', "\n", $privateKeyContent);
$privateKey = openssl_pkey_get_private($privateKeyContent);

if (!$privateKey) {
    die("ERROR: Cannot load private key\n");
}

// Extract public key from private key
$details = openssl_pkey_get_details($privateKey);
$correctPublicKey = $details['key'];

echo "=== CORRECT PUBLIC KEY (use this in Saccussalis DB) ===\n";
echo $correctPublicKey . "\n\n";

// Test payload
$payload = [
    'action' => 'PLACE_HOLD',
    'reference' => 'DIAG_' . time(),
    'amount' => 100,
    'asset_id' => 4
];
ksort($payload);

$timestamp = time();
$payloadWithTs = $payload;
$payloadWithTs['_timestamp'] = $timestamp;
ksort($payloadWithTs);

$jsonToSign = json_encode($payloadWithTs, JSON_UNESCAPED_SLASHES);
echo "=== JSON THAT WILL BE SIGNED ===\n";
echo $jsonToSign . "\n\n";

// Sign
$signature = '';
openssl_sign($jsonToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
$signatureBase64 = base64_encode($signature);
echo "SIGNATURE: " . $signatureBase64 . "\n";
echo "TIMESTAMP: " . $timestamp . "\n\n";

// Now verify it ourselves immediately
echo "=== VERIFYING WITH OUR OWN PUBLIC KEY ===\n";
$result = openssl_verify($jsonToSign, $signature, $correctPublicKey, OPENSSL_ALGO_SHA256);
echo "Self-verification result: " . ($result === 1 ? "VALID ✓" : "INVALID ✗") . "\n\n";

echo "=== INSTRUCTIONS ===\n";
echo "1. Copy the PUBLIC KEY above\n";
echo "2. In Saccussalis DB, run:\n";
echo "   UPDATE trusted_partners SET public_key = 'THE_PUBLIC_KEY_ABOVE' WHERE name = 'VOUCHMORPH';\n";
echo "3. After updating, run this same script again\n";
