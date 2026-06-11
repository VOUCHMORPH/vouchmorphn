<?php
// /public/generate_signature.php

require_once __DIR__ . '/../../vendor/autoload.php'; 

use Infrastructure\Crypto\MessageSigner;

header("Content-Type: text/plain");

echo "========================================\n";
echo "Generating Test Signature\n";
echo "========================================\n\n";

$signer = new MessageSigner();

if (!$signer->isReady()) {
    echo "❌ MessageSigner is not ready - private key not loaded\n";
    exit;
}

echo "✅ MessageSigner is ready\n\n";

// Create test payload
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
$signedRequest = $signer->createSignedRequest($testPayload, 'VOUCHMORPH');

echo "========================================\n";
echo "SIGNATURE GENERATED SUCCESSFULLY!\n";
echo "========================================\n\n";
echo "SIGNATURE: " . $signedRequest['signature'] . "\n\n";
echo "TIMESTAMP: " . $signedRequest['timestamp'] . "\n\n";
echo "FULL SIGNED PAYLOAD:\n";
echo json_encode($signedRequest, JSON_PRETTY_PRINT) . "\n";
