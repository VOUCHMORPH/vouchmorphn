<?php
// /public/sign_test.php

require_once __DIR__ . '/../../vendor/autoload.php';
use Infrastructure\Crypto\MessageSigner;

header("Content-Type: text/plain");

$signer = new MessageSigner();

// The exact payload that placeHoldSigned sends
$payload = [
    'action' => 'PLACE_HOLD',
    'reference' => 'SWAP_TEST_001',
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

// Sort to ensure consistent order
ksort($payload);

// Get timestamp
$timestamp = time();

// Create the exact string that gets signed
$payloadWithTimestamp = array_merge($payload, ['_timestamp' => $timestamp]);
ksort($payloadWithTimestamp);
$jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES);

// Sign it
$signature = '';
$privateKey = openssl_pkey_get_private(str_replace('\\n', "\n", getenv('VOUCHMORPH_PRIVATE_KEY')));
openssl_sign($jsonToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
$signatureBase64 = base64_encode($signature);

echo "=== VOUCHMORPH SIGNING DATA ===\n\n";
echo "TIMESTAMP: " . $timestamp . "\n";
echo "SIGNATURE: " . $signatureBase64 . "\n\n";
echo "JSON THAT WAS SIGNED:\n";
echo $jsonToSign . "\n\n";
echo "=== COPY THIS JSON FOR SACCUSSALIS ===\n";
