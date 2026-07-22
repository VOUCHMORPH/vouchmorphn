<?php
require_once '../../src/Infrastructure/Crypto/CertificateManager.php';
require_once '../../src/Infrastructure/Banks/GenericBankClient.php';
require_once '../../src/Infrastructure/Crypto/MessageSigner.php';

use Infrastructure\Banks\GenericBankClient;

echo "========================================\n";
echo "TESTING SEND METHOD\n";
echo "========================================\n\n";

$config = ['provider_code' => 'SACCUSSALIS'];
$gbc = new GenericBankClient($config);

// Create a test payload
$payload = [
    'action' => 'GENERATE_TOKEN',
    'amount' => 400,
    'beneficiary_phone' => '+26770000000',
    'currency' => 'BWP',
    'destination_institution' => 'SACCUSSALIS',
    'from_institution' => 'ZURUBANK',
    'hold_reference' => 'SEND_TEST_' . time(),
    'reference' => 'SEND_TEST_' . time(),
    'requester' => 'VOUCHMORPH',
    'source_institution' => 'ZURUBANK',
    'to_institution' => 'SACCUSSALIS'
];

// Sign the payload
$reflection = new ReflectionClass($gbc);
$method = $reflection->getMethod('createSignedPayload');
$method->setAccessible(true);
$signed = $method->invoke($gbc, $payload, 'VOUCHMORPH');

echo "1. Signed payload keys: " . implode(', ', array_keys($signed)) . "\n";
echo "   Signature present: " . (isset($signed['signature']) ? 'YES' : 'NO') . "\n";
echo "   Certificate present: " . (isset($signed['certificate']) ? 'YES' : 'NO') . "\n\n";

// Now check what send() does with it
$sendMethod = $reflection->getMethod('send');
$sendMethod->setAccessible(true);

// Just test the JSON encoding, not the actual send
$jsonPayload = json_encode($signed);
echo "2. JSON payload (first 500 chars):\n" . substr($jsonPayload, 0, 500) . "...\n\n";

// Check if the signature survived JSON encoding
$decoded = json_decode($jsonPayload, true);
echo "3. After JSON decode:\n";
echo "   Signature present: " . (isset($decoded['signature']) ? 'YES' : 'NO') . "\n";
echo "   Certificate present: " . (isset($decoded['certificate']) ? 'YES' : 'NO') . "\n";

if (isset($decoded['signature']) && isset($decoded['certificate'])) {
    echo "   Signature length: " . strlen($decoded['signature']) . "\n";
    echo "   Certificate length: " . strlen($decoded['certificate']) . "\n";
    echo "\n✅ Signature and certificate survived JSON encoding!\n";
    echo "The problem must be on the SACCUSSALIS receiving side or network transmission.\n";
} else {
    echo "\n❌ Signature or certificate was lost in JSON encoding!\n";
}
