<?php
require_once '/var/www/html/src/Infrastructure/Crypto/CertificateManager.php';
require_once '/var/www/html/src/Infrastructure/Banks/GenericBankClient.php';

use Infrastructure\Crypto\CertificateManager;
use Infrastructure\Banks\GenericBankClient;

echo "========================================\n";
echo "VOUCHMORPH FINAL TEST\n";
echo "========================================\n\n";

// 1. What does GenericBankClient actually use?
echo "1. GenericBankClient CertificateManager:\n";
$config = ['provider_code' => 'ZURUBANK'];
$gbc = new GenericBankClient($config);

if ($gbc->certManager) {
    echo "   myName: " . $gbc->certManager->myName . "\n";
    echo "   Configured: " . ($gbc->certManager->isConfigured() ? "✅ YES" : "❌ NO") . "\n";
    echo "   Private key length: " . ($gbc->certManager->myPrivateKey ? strlen($gbc->certManager->myPrivateKey) : '0') . "\n";
    echo "   Certificate length: " . ($gbc->certManager->myCertificate ? strlen($gbc->certManager->myCertificate) : '0') . "\n\n";
} else {
    echo "   ❌ No CertificateManager!\n\n";
}

// 2. Check VOUCHMORPH_PARTNER_NAME environment variable
echo "2. VOUCHMORPH_PARTNER_NAME: " . (getenv('VOUCHMORPH_PARTNER_NAME') ?: 'NOT SET') . "\n\n";

// 3. Sign a payload with GenericBankClient and check the signature
echo "3. Sign with GenericBankClient:\n";
$payload = [
    'action' => 'GENERATE_TOKEN',
    'amount' => 400,
    'beneficiary_phone' => '+26770000000',
    'currency' => 'BWP',
    'destination_institution' => 'SACCUSSALIS',
    'from_institution' => 'ZURUBANK',
    'hold_reference' => 'FINAL_TEST_' . time(),
    'reference' => 'FINAL_TEST_' . time(),
    'requester' => 'VOUCHMORPH',
    'source_institution' => 'ZURUBANK',
    'to_institution' => 'SACCUSSALIS'
];

$signed = $gbc->createSignedPayload($payload, 'VOUCHMORPH');

if (isset($signed['signature']) && isset($signed['certificate'])) {
    echo "   ✅ Signature created\n";
    echo "   Signature length: " . strlen($signed['signature']) . "\n";
    echo "   Certificate length: " . strlen($signed['certificate']) . "\n\n";
    
    // 4. Verify the signature locally (using the same method as SACCUSSALIS)
    echo "4. Verify signature (SACCUSSALIS method):\n";
    $publicKey = openssl_pkey_get_public($signed['certificate']);
    $payloadToVerify = $signed;
    unset($payloadToVerify['signature']);
    unset($payloadToVerify['certificate']);
    // KEEP requester - SACCUSSALIS expects it
    ksort($payloadToVerify);
    $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $decodedSig = base64_decode($signed['signature']);
    $result = openssl_verify($jsonToVerify, $decodedSig, $publicKey, OPENSSL_ALGO_SHA256);
    
    echo "   openssl_verify result: " . $result . " (1=valid, 0=invalid)\n";
    echo "   Result: " . ($result === 1 ? "✅ VALID" : "❌ INVALID") . "\n";
    
    if ($result !== 1) {
        echo "\n❌ THE SIGNATURE FROM GENERICBANKCLIENT IS INVALID!\n";
        echo "This means the private key used by GenericBankClient does NOT match the certificate.\n";
        echo "Check what private key GenericBankClient is loading.\n";
    }
} else {
    echo "   ❌ Failed to create signature\n";
}
