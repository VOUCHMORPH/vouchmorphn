<?php
require_once '../../src/Infrastructure/Crypto/CertificateManager.php';
require_once '../../src/Infrastructure/Banks/GenericBankClient.php';

use Infrastructure\Crypto\CertificateManager;
use Infrastructure\Banks\GenericBankClient;

echo "========================================\n";
echo "VOUCHMORPH ACTUAL SIGNING TEST\n";
echo "========================================\n\n";

// 1. Check what CertificateManager loads
echo "1. CertificateManager with no parameter:\n";
$cm1 = new CertificateManager();
echo "   myName: " . $cm1->myName . "\n";
echo "   Configured: " . ($cm1->isConfigured() ? "✅ YES" : "❌ NO") . "\n";
echo "   Private key length: " . ($cm1->myPrivateKey ? strlen($cm1->myPrivateKey) : '0') . "\n";
echo "   Certificate length: " . ($cm1->myCertificate ? strlen($cm1->myCertificate) : '0') . "\n\n";

// 2. Check what CertificateManager loads with VOUCHMORPH
echo "2. CertificateManager with 'VOUCHMORPH':\n";
$cm2 = new CertificateManager('VOUCHMORPH');
echo "   myName: " . $cm2->myName . "\n";
echo "   Configured: " . ($cm2->isConfigured() ? "✅ YES" : "❌ NO") . "\n";
echo "   Private key length: " . ($cm2->myPrivateKey ? strlen($cm2->myPrivateKey) : '0') . "\n";
echo "   Certificate length: " . ($cm2->myCertificate ? strlen($cm2->myCertificate) : '0') . "\n\n";

// 3. Check GenericBankClient
echo "3. GenericBankClient (what's actually used in production):\n";
$config = ['provider_code' => 'ZURUBANK'];
$gbc = new GenericBankClient($config);
echo "   CertificateManager exists: " . ($gbc->certManager ? "✅ YES" : "❌ NO") . "\n";
if ($gbc->certManager) {
    echo "   myName: " . $gbc->certManager->myName . "\n";
    echo "   Configured: " . ($gbc->certManager->isConfigured() ? "✅ YES" : "❌ NO") . "\n";
    echo "   Private key length: " . ($gbc->certManager->myPrivateKey ? strlen($gbc->certManager->myPrivateKey) : '0') . "\n";
}

// 4. Test signing with GenericBankClient
echo "\n4. Test signing with GenericBankClient:\n";
$payload = [
    'action' => 'GENERATE_TOKEN',
    'amount' => 400,
    'beneficiary_phone' => '+26770000000',
    'currency' => 'BWP',
    'destination_institution' => 'SACCUSSALIS',
    'from_institution' => 'ZURUBANK',
    'hold_reference' => 'GBC_TEST_' . time(),
    'reference' => 'GBC_TEST_' . time(),
    'requester' => 'VOUCHMORPH',
    'source_institution' => 'ZURUBANK',
    'to_institution' => 'SACCUSSALIS'
];

$signed = $gbc->createSignedPayload($payload, 'VOUCHMORPH');
echo "   Signature created: " . (isset($signed['signature']) ? "✅ YES" : "❌ NO") . "\n";
echo "   Certificate included: " . (isset($signed['certificate']) ? "✅ YES" : "❌ NO") . "\n";
if (isset($signed['signature']) && isset($signed['certificate'])) {
    echo "   Signature length: " . strlen($signed['signature']) . "\n";
    echo "   Certificate length: " . strlen($signed['certificate']) . "\n";
}

// 5. Verify with SACCUSSALIS-style verification
echo "\n5. Verifying with SACCUSSALIS-style verification:\n";
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
echo "   JSON verified: " . $jsonToVerify . "\n";
