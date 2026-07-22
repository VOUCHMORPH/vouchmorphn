<?php
require_once '../../../src/Infrastructure/Crypto/CertificateManager.php';

echo "========================================\n";
echo "AGGRESSIVE DIAGNOSTIC TEST - VOUCHMORPH\n";
echo "========================================\n\n";

// 1. Check environment variables
echo "1. ENVIRONMENT VARIABLES:\n";
$vars = [
    'VOUCHMORPH_PRIVATE_KEY_CONTENT',
    'VOUCHMORPH_CERT_CONTENT',
    'VOUCHMORPH_CA_CERT_CONTENT',
    'VOUCHMORPH_PARTNER_NAME'
];
foreach ($vars as $var) {
    $val = getenv($var);
    if ($val) {
        echo "   ✅ $var: SET (length: " . strlen($val) . ")\n";
    } else {
        echo "   ❌ $var: NOT SET\n";
    }
}
echo "\n";

// 2. Check CertificateManager initialization
echo "2. CERTIFICATEMANAGER INITIALIZATION:\n";

// Test with VOUCHMORPH parameter
$cm = new CertificateManager('VOUCHMORPH');
echo "   With 'VOUCHMORPH': myName = " . $cm->myName . "\n";
echo "   Configured: " . ($cm->isConfigured() ? "✅ YES" : "❌ NO") . "\n\n";

// 3. Check if private key matches certificate
echo "3. PRIVATE KEY / CERTIFICATE MATCH:\n";
$cert = getenv('VOUCHMORPH_CERT_CONTENT');
$key = getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT');
if ($cert && $key) {
    $cert = str_replace(['\\n', '\n'], "\n", $cert);
    $key = str_replace(['\\n', '\n'], "\n", $key);
    
    $tempCert = tempnam(sys_get_temp_dir(), 'cert_');
    $tempKey = tempnam(sys_get_temp_dir(), 'key_');
    file_put_contents($tempCert, $cert);
    file_put_contents($tempKey, $key);
    
    exec("openssl x509 -noout -modulus -in $tempCert 2>&1", $certMod, $certCode);
    exec("openssl rsa -noout -modulus -in $tempKey 2>&1", $keyMod, $keyCode);
    
    echo "   Certificate modulus: " . (isset($certMod[0]) ? substr($certMod[0], 0, 50) . '...' : 'NOT FOUND') . "\n";
    echo "   Private key modulus: " . (isset($keyMod[0]) ? substr($keyMod[0], 0, 50) . '...' : 'NOT FOUND') . "\n";
    
    if ($certCode === 0 && $keyCode === 0) {
        $match = ($certMod[0] ?? '') === ($keyMod[0] ?? '');
        echo "   MODULUS MATCH: " . ($match ? "✅ YES" : "❌ NO") . "\n";
        if (!$match) {
            echo "   ❌ THE CERTIFICATE AND PRIVATE KEY DO NOT MATCH!\n";
            echo "   This is why SACCUSSALIS rejects the signature.\n";
        }
    } else {
        echo "   ❌ Could not read certificate or private key\n";
    }
    
    unlink($tempCert);
    unlink($tempKey);
}
echo "\n";

// 4. Test signing and verification locally
echo "4. LOCAL SIGNING AND VERIFICATION TEST:\n";
$testPayload = [
    'action' => 'TEST',
    'amount' => 100,
    'currency' => 'BWP',
    'reference' => 'TEST_' . time()
];
$requester = 'VOUCHMORPH';

$signed = $cm->createSignedRequest($testPayload, $requester);
echo "   Signed payload created\n";
echo "   Signature length: " . strlen($signed['signature']) . "\n";
echo "   Certificate length: " . strlen($signed['certificate']) . "\n";

$verified = $cm->verifySignedRequest($signed);
echo "   Local verification result: " . ($verified['verified'] ? "✅ VALID" : "❌ INVALID") . "\n";
echo "   Message: " . $verified['message'] . "\n";
