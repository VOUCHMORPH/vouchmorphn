<?php
require_once '../../src/Infrastructure/Crypto/CertificateManager.php';
require_once '../../src/Infrastructure/Banks/GenericBankClient.php';
require_once '../../src/Infrastructure/Crypto/MessageSigner.php';

use Infrastructure\Banks\GenericBankClient;

$config = ['provider_code' => 'SACCUSSALIS'];
$gbc = new GenericBankClient($config);

// Create test payload
$payload = [
    'action' => 'GENERATE_TOKEN',
    'amount' => 400,
    'beneficiary_phone' => '+26770000000',
    'currency' => 'BWP',
    'destination_institution' => 'SACCUSSALIS',
    'from_institution' => 'ZURUBANK',
    'hold_reference' => 'PAYLOAD_TEST_' . time(),
    'reference' => 'PAYLOAD_TEST_' . time(),
    'requester' => 'VOUCHMORPH',
    'source_institution' => 'ZURUBANK',
    'to_institution' => 'SACCUSSALIS'
];

// Sign
$reflection = new ReflectionClass($gbc);
$method = $reflection->getMethod('createSignedPayload');
$method->setAccessible(true);
$signed = $method->invoke($gbc, $payload, 'VOUCHMORPH');

// The original signature
echo "1. Original signature:\n" . $signed['signature'] . "\n\n";

// JSON encode and decode (what happens during transmission)
$json = json_encode($signed);
$decoded = json_decode($json, true);

echo "2. Signature after JSON encode/decode:\n" . $decoded['signature'] . "\n\n";

// Compare
if ($signed['signature'] === $decoded['signature']) {
    echo "✅ Signatures match! JSON encoding is not the problem.\n";
} else {
    echo "❌ Signatures DO NOT match! JSON encoding is corrupting the signature.\n";
}

// Check if the signature has escaped slashes
if (strpos($json, '\\/') !== false) {
    echo "\n⚠️ WARNING: JSON contains escaped slashes '\\/' which could be the problem!\n";
    echo "The signature might be getting corrupted when the slash is escaped.\n";
}
