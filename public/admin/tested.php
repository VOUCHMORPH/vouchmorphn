<?php
// /public/test_hold_directly.php

require_once __DIR__ . '/../vendor/autoload.php';
use Infrastructure\Crypto\MessageSigner;

$signer = new MessageSigner();

if (!$signer->isReady()) {
    die("ERROR: Private key not loaded\n");
}

// Create payload exactly as placeHoldSigned does
$payload = [
    'action' => 'PLACE_HOLD',
    'reference' => 'TEST_' . time(),
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

// Sign the payload
$signed = $signer->createSignedRequest($payload, 'VOUCHMORPH');

echo "Sending hold request to Saccussalis...\n";

// Send directly to Saccussalis hold endpoint
$ch = curl_init('https://saccussalis-production.up.railway.app/backend/api/v1/hold.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => $payload['action'],
    'reference' => $payload['reference'],
    'asset_type' => $payload['asset_type'],
    'amount' => $payload['amount'],
    'currency' => $payload['currency'],
    'hold_reason' => $payload['hold_reason'],
    'destination_institution' => $payload['destination_institution'],
    'expiry' => $payload['expiry'],
    'source_identifier' => $payload['source_identifier'],
    'source_identifier_type' => $payload['source_identifier_type'],
    'asset_id' => $payload['asset_id'],
    'wallet_phone' => $payload['wallet_phone'],
    'phone' => $payload['phone'],
    'national_id' => $payload['national_id'],
    'email' => $payload['email'],
    'requester' => 'VOUCHMORPH',
    'timestamp' => $signed['timestamp'],
    'signature' => $signed['signature']
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . $response . "\n";
