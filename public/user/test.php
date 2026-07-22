<?php
require_once '../../../src/Infrastructure/Crypto/CertificateManager.php';
use Infrastructure\Crypto\CertificateManager;

echo "========================================\n";
echo "VOUCHMORPH SIGNING TEST\n";
echo "========================================\n\n";

// Get the private key and certificate
$privateKeyContent = getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT');
$certContent = getenv('VOUCHMORPH_CERT_CONTENT');

if (!$privateKeyContent || !$certContent) {
    echo "❌ Missing private key or certificate\n";
    exit;
}

$privateKeyContent = str_replace(['\\n', '\n'], "\n", $privateKeyContent);
$certContent = str_replace(['\\n', '\n'], "\n", $certContent);

// Create test payload
$testPayload = [
    'action' => 'GENERATE_TOKEN',
    'amount' => 400,
    'beneficiary_phone' => '+26770000000',
    'currency' => 'BWP',
    'destination_institution' => 'SACCUSSALIS',
    'from_institution' => 'ZURUBANK',
    'hold_reference' => 'TEST_' . time(),
    'reference' => 'TEST_' . time(),
    'requester' => 'VOUCHMORPH',
    'source_institution' => 'ZURUBANK',
    'to_institution' => 'SACCUSSALIS'
];
$testPayload['timestamp'] = time();
ksort($testPayload);
$jsonToSign = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "1. JSON to sign:\n" . $jsonToSign . "\n\n";

// Sign with private key
$privateKey = openssl_pkey_get_private($privateKeyContent);
if (!$privateKey) {
    echo "❌ Failed to load private key\n";
    exit;
}

$signature = '';
openssl_sign($jsonToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
$signatureB64 = base64_encode($signature);

echo "2. Generated signature:\n" . $signatureB64 . "\n\n";

// Verify with the certificate
$publicKey = openssl_pkey_get_public($certContent);
$result = openssl_verify($jsonToSign, $signature, $publicKey, OPENSSL_ALGO_SHA256);

echo "3. Manual verification with openssl:\n";
echo "   openssl_verify result: " . $result . " (1=valid, 0=invalid)\n";
echo "   Result: " . ($result === 1 ? "✅ VALID" : "❌ INVALID") . "\n\n";

// Now check what GenericBankClient is actually doing
echo "4. Checking GenericBankClient:\n";
$config = ['provider_code' => 'ZURUBANK'];
$gbc = new \Infrastructure\Banks\GenericBankClient($config);
echo "   CertificateManager exists: " . ($gbc->certManager ? "✅ YES" : "❌ NO") . "\n";
if ($gbc->certManager) {
    echo "   myName: " . $gbc->certManager->myName . "\n";
    echo "   Configured: " . ($gbc->certManager->isConfigured() ? "✅ YES" : "❌ NO") . "\n";
}

echo "\n5. Testing GenericBankClient signing:\n";
$payload = [
    'action' => 'TEST_GBC',
    'amount' => 100,
    'reference' => 'GBC_TEST_' . time()
];
$signed = $gbc->createSignedPayload($payload, 'VOUCHMORPH');
echo "   Signature created: " . (isset($signed['signature']) ? "✅ YES" : "❌ NO") . "\n";
echo "   Certificate included: " . (isset($signed['certificate']) ? "✅ YES" : "❌ NO") . "\n";
if (isset($signed['signature'])) {
    echo "   Signature length: " . strlen($signed['signature']) . "\n";
}
