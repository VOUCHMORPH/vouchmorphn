<?php
// /public/generate_hold_sig.php

require_once __DIR__ . '/../../vendor/autoload.php';

use Infrastructure\Crypto\MessageSigner;

header("Content-Type: text/plain");

$signer = new MessageSigner();

if (!$signer->isReady()) {
    echo "ERROR: Private key not loaded\n";
    exit;
}

// This payload matches what placeHoldSigned() sends to hold.php
$payload = [
    'action' => 'PLACE_HOLD',
    'reference' => 'TEST_HOLD_' . time(),
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'hold_reason' => 'PENDING_SWAP',
    'destination_institution' => 'ZURUBANK',
    'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour')),
    'source_identifier' => '+26770000000',
    'source_identifier_type' => 'phone',
    'asset_id' => 4,
    'wallet_phone' => '+26770000000',
    'phone' => '+26770000000',
    'national_id' => '+26770000000',
    'email' => '+26770000000'
];

// Generate signed request
$signed = $signer->createSignedRequest($payload, 'VOUCHMORPH');

echo "========================================\n";
echo "HOLD SIGNATURE GENERATED\n";
echo "========================================\n\n";
echo "SIGNATURE: " . $signed['signature'] . "\n\n";
echo "TIMESTAMP: " . $signed['timestamp'] . "\n\n";
echo "PAYLOAD:\n";
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";
echo "========================================\n";
echo "Copy these values to test hold.php\n";
echo "========================================\n";
