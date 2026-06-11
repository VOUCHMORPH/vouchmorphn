<?php
// /public/generate_sig.php

require_once __DIR__ . '/../../vendor/autoload.php';

use Infrastructure\Crypto\MessageSigner;

header("Content-Type: text/plain");

$signer = new MessageSigner();

if (!$signer->isReady()) {
    echo "ERROR: Private key not loaded\n";
    echo "Check that VOUCHMORPH_PRIVATE_KEY is set in environment\n";
    exit;
}

// Create test payload
$payload = [
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

// Generate signed request
$signed = $signer->createSignedRequest($payload, 'VOUCHMORPH');

echo "========================================\n";
echo "SIGNATURE GENERATED\n";
echo "========================================\n\n";
echo "SIGNATURE: " . $signed['signature'] . "\n\n";
echo "TIMESTAMP: " . $signed['timestamp'] . "\n\n";
echo "PAYLOAD:\n";
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";
echo "========================================\n";
echo "Copy these values to Saccussalis test\n";
echo "========================================\n";
