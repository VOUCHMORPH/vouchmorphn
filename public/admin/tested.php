<?php
// test_vouchmorph_certificate.php
// Run on VouchMorph server

require_once __DIR__ . '/src/Infrastructure/Crypto/CertificateManager.php';

use Infrastructure\Crypto\CertificateManager;

echo "=== VOUCHMORPH CERTIFICATE MANAGER TEST ===\n\n";

$cm = new CertificateManager('VOUCHMORPH');

echo "CertificateManager configured: " . ($cm->isConfigured() ? "YES" : "NO") . "\n";

// Test payloads
$testPayloads = [
    'VERIFY_ASSET' => [
        'action' => 'VERIFY_ASSET',
        'reference' => 'TEST_VERIFY_ASSET',
        'asset_type' => 'ACCOUNT',
        'amount' => 1000,
        'currency' => 'BWP',
        'source_identifier' => '10000001',
        'source_identifier_type' => 'auto',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'swap_type' => 'DEPOSIT'
    ],
    'PLACE_HOLD' => [
        'action' => 'PLACE_HOLD',
        'reference' => 'TEST_PLACE_HOLD',
        'asset_type' => 'ACCOUNT',
        'asset_id' => 10,
        'amount' => 1000,
        'currency' => 'BWP',
        'source_identifier' => '10000001',
        'source_identifier_type' => 'auto',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'destination_institution' => 'SACCUSSALIS',
        'hold_reason' => 'PENDING_SWAP',
        'user_id' => 12,
        'expiry' => date('Y-m-d H:i:s', strtotime('+24 hours'))
    ],
    'PROCESS_DEPOSIT_WITH_PROOF' => [
        '_skip_hold' => true,
        'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
        'reference' => 'TEST_DEPOSIT',
        'amount' => 994,
        'currency' => 'BWP',
        'asset_type' => 'WALLET',
        'destination_asset_type' => 'WALLET',
        'destination_identifier' => '+26770000000',
        'destination_identifier_type' => 'phone',
        'destination_institution' => 'SACCUSSALIS',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'to_institution' => 'SACCUSSALIS',
        'hold_reference' => 'SWAP_1784973884251',
        'user_id' => 42,
        'bank' => 'ZURUBANK'
    ]
];

foreach ($testPayloads as $name => $payload) {
    echo "\n=== TESTING $name ===\n";
    echo "Original payload keys: " . implode(', ', array_keys($payload)) . "\n";
    
    $signed = $cm->createSignedRequest($payload, 'VOUCHMORPH');
    
    echo "Signed payload keys: " . implode(', ', array_keys($signed)) . "\n";
    echo "Has signature: " . (isset($signed['signature']) ? 'YES' : 'NO') . "\n";
    echo "Has certificate: " . (isset($signed['certificate']) ? 'YES' : 'NO') . "\n";
    echo "Has requester: " . (isset($signed['requester']) ? 'YES (value: ' . $signed['requester'] . ')' : 'NO') . "\n";
    echo "Has timestamp: " . (isset($signed['timestamp']) ? 'YES' : 'NO') . "\n";
    
    // Check what was actually signed
    $payloadToVerify = $signed;
    unset($payloadToVerify['signature']);
    unset($payloadToVerify['certificate']);
    // Keep requester - it's now part of the payload (but is it in the signed data?)
    
    echo "Payload fields used for signing: " . implode(', ', array_keys($payloadToVerify)) . "\n";
    echo "JSON to sign: " . json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    
    // Verify the signature
    $verification = $cm->verifySignedRequest($signed);
    echo "Verification result: " . ($verification['verified'] ? "VALID ✓" : "INVALID ✗") . "\n";
    echo "Message: " . $verification['message'] . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
