<?php
// generate_test_signature.php
// Run this on VouchMorph to get a real signature
 
require_once __DIR__ . '/../vendor/autoload.php';

use Infrastructure\Crypto\MessageSigner;

header("Content-Type: text/plain");

echo "========================================\n";
echo "Generating Test Signature from VouchMorph\n";
echo "========================================\n\n";

// Check if private key is available
$privateKey = getenv('VOUCHMORPH_PRIVATE_KEY');
if (!$privateKey) {
    echo "❌ VOUCHMORPH_PRIVATE_KEY not found in environment!\n";
    echo "   Please add it to Railway variables.\n";
    exit;
}

echo "✅ Private key found (length: " . strlen($privateKey) . " chars)\n\n";

// Create test payload (must match what Saccussalis expects)
$testPayload = [
    'action' => 'VERIFY_ASSET',
    'reference' => 'TEST_' . time(),
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'institution' => 'SACCUSSALIS',
    'timestamp' => time(),
    'swap_type' => 'CASHOUT',
    'source_identifier' => '+26770000000'
];

echo "Test Payload:\n";
echo json_encode($testPayload, JSON_PRETTY_PRINT) . "\n\n";

// Create signed request
$signer = new MessageSigner();
$signedRequest = $signer->createSignedRequest($testPayload, 'VOUCHMORPH');

echo "========================================\n";
echo "COPY THESE VALUES TO SACCUSSALIS TEST:\n";
echo "========================================\n\n";
echo "SIGNATURE: " . $signedRequest['signature'] . "\n\n";
echo "TIMESTAMP: " . $signedRequest['timestamp'] . "\n\n";
echo "PAYLOAD: " . json_encode($testPayload) . "\n\n";

// Also test if the signature can be verified locally (if we have Saccussalis public key)
echo "========================================\n";
echo "Local verification test (if Saccussalis public key exists):\n";
echo "========================================\n";

try {
    $db = \Core\Database\DBConnection::getConnection();
    $stmt = $db->prepare("SELECT public_key FROM institution_keys WHERE institution = 'SACCUSSALIS' AND is_active = true");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        $saccussalisPublicKey = $row['public_key'];
        
        // Verify the signature
        $payloadToVerify = $testPayload;
        $signature = $signedRequest['signature'];
        $timestamp = $signedRequest['timestamp'];
        
        $payloadJson = json_encode(array_merge($payloadToVerify, ['_timestamp' => $timestamp]));
        $result = openssl_verify(
            $payloadJson,
            base64_decode($signature),
            $saccussalisPublicKey,
            OPENSSL_ALGO_SHA256
        );
        
        if ($result === 1) {
            echo "✅ Local verification SUCCESSFUL!\n";
        } else {
            echo "❌ Local verification FAILED\n";
        }
    } else {
        echo "⚠️ SACCUSSALIS public key not found in institution_keys\n";
    }
} catch (Exception $e) {
    echo "⚠️ Could not test local verification: " . $e->getMessage() . "\n";
}
