<?php
echo "========================================\n";
echo "AGGRESSIVE DIAGNOSTIC TEST\n";
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
require_once '/var/www/html/src/Infrastructure/Crypto/CertificateManager.php';

// Test with no parameter (default)
$cm1 = new CertificateManager();
echo "   No parameter: myName = " . $cm1->myName . "\n";
echo "   Configured: " . ($cm1->isConfigured() ? "✅ YES" : "❌ NO") . "\n\n";

// Test with VOUCHMORPH parameter
$cm2 = new CertificateManager('VOUCHMORPH');
echo "   With 'VOUCHMORPH': myName = " . $cm2->myName . "\n";
echo "   Configured: " . ($cm2->isConfigured() ? "✅ YES" : "❌ NO") . "\n\n";

// 3. Check what GenericBankClient is actually using
echo "3. GENERIC BANK CLIENT INITIALIZATION:\n";
$config = ['provider_code' => 'ZURUBANK'];
$gbc = new Infrastructure\Banks\GenericBankClient($config);
echo "   CertificateManager configured: " . ($gbc->certManager && $gbc->certManager->isConfigured() ? "✅ YES" : "❌ NO") . "\n";
if ($gbc->certManager) {
    echo "   CertificateManager myName: " . $gbc->certManager->myName . "\n";
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

$signed = $cm2->createSignedRequest($testPayload, $requester);
echo "   Signed payload created\n";
echo "   Signature length: " . strlen($signed['signature']) . "\n";
echo "   Certificate length: " . strlen($signed['certificate']) . "\n";

$verified = $cm2->verifySignedRequest($signed);
echo "   Local verification result: " . ($verified['verified'] ? "✅ VALID" : "❌ INVALID") . "\n";
echo "   Message: " . $verified['message'] . "\n\n";

// 5. Check if private key matches certificate
echo "5. PRIVATE KEY / CERTIFICATE MATCH:\n";
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
    
    echo "   Certificate modulus: " . (isset($certMod[0]) ? substr($certMod[0], 0, 30) . '...' : 'NOT FOUND') . "\n";
    echo "   Private key modulus: " . (isset($keyMod[0]) ? substr($keyMod[0], 0, 30) . '...' : 'NOT FOUND') . "\n";
    
    if ($certCode === 0 && $keyCode === 0) {
        $match = ($certMod[0] ?? '') === ($keyMod[0] ?? '');
        echo "   MATCH: " . ($match ? "✅ YES" : "❌ NO") . "\n";
    } else {
        echo "   ❌ Could not read certificate or private key\n";
    }
    
    unlink($tempCert);
    unlink($tempKey);
}
