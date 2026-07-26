<?php
require_once __DIR__ . '/../../src/Infrastructure/Crypto/CertificateManager.php';

use Infrastructure\Crypto\CertificateManager;

// Create a test payload identical to what's being sent
$payload = [
    '_skip_hold' => true,
    'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
    'amount' => 494,
    'asset_type' => 'WALLET',
    'currency' => 'BWP',
    'destination_asset_type' => 'WALLET',
    'destination_identifier' => '10000002',
    'destination_identifier_type' => 'account',
    'destination_institution' => 'ZURUBANK',
    'from_institution' => 'SACCUSSALIS',
    'hold_reference' => 'MIXED_SWAP_1785052432_DEST_0',
    'phone' => '10000002',
    'reference' => 'MIXED_SWAP_1785052432_DEST_0',
    'source_hold' => null,
    'source_institution' => 'SACCUSSALIS',
    'source_verification' => [
        'payload' => [
            'action' => 'VERIFY_ASSET',
            'reference' => 'MIXED_SWAP_1785052432',
            'asset_type' => 'ACCOUNT',
            'amount' => 2000,
            'currency' => 'BWP',
            'institution' => 'SACCUSSALIS',
            'timestamp' => 1785052434,
            'swap_type' => 'MULTI_DESTINATION',
            'requester' => 'VOUCHMORPH',
            'from_institution' => 'SACCUSSALIS',
            'source_institution' => 'SACCUSSALIS',
            'source_identifier' => '10000001',
            'source_identifier_type' => 'account'
        ],
        'signature' => null,
        'source' => 'SACCUSSALIS',
        'timestamp' => 1785052434
    ],
    'timestamp' => 1785052434,
    'to_institution' => 'ZURUBANK',
    'wallet_phone' => '10000002'
];

$certManager = new CertificateManager('VOUCHMORPH');

// Step 1: Show what VouchMorph signs
$payloadWithTimestamp = array_merge($payload, ['timestamp' => time()]);
ksort($payloadWithTimestamp);
$jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "=== WHAT VOUCHMORPH SIGNS ===\n";
echo "JSON: " . $jsonToSign . "\n\n";
echo "JSON length: " . strlen($jsonToSign) . "\n";
echo "JSON MD5: " . md5($jsonToSign) . "\n\n";

// Step 2: Create signed request
$signed = $certManager->createSignedRequest($payload, 'VOUCHMORPH');

// Step 3: Show what's being sent
echo "=== WHAT'S BEING SENT ===\n";
$sentPayload = $signed;
unset($sentPayload['signature']);
unset($sentPayload['certificate']);
ksort($sentPayload);
$sentJson = json_encode($sentPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "JSON: " . $sentJson . "\n\n";
echo "JSON length: " . strlen($sentJson) . "\n";
echo "JSON MD5: " . md5($sentJson) . "\n\n";

// Step 4: Verify the signature locally
$verifyResult = $certManager->verifySignedRequest($signed);

echo "=== VERIFICATION RESULT ===\n";
echo "Verified: " . ($verifyResult['verified'] ? "✅ YES" : "❌ NO") . "\n";
echo "Message: " . ($verifyResult['message'] ?? 'N/A') . "\n";

// Step 5: Debug - try with and without requester
$testPayloads = [
    'with_requester' => $sentPayload,
    'without_requester' => array_diff_key($sentPayload, ['requester' => null])
];

foreach ($testPayloads as $name => $testPayload) {
    ksort($testPayload);
    $testJson = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $decodedSig = base64_decode($signed['signature']);
    
    // Extract public key
    $cert = $signed['certificate'];
    $tempCert = tempnam(sys_get_temp_dir(), 'cert_');
    file_put_contents($tempCert, $cert);
    $cmd = "openssl x509 -in " . escapeshellarg($tempCert) . " -pubkey -noout 2>&1";
    $publicKey = shell_exec($cmd);
    unlink($tempCert);
    
    $keyResource = openssl_pkey_get_public($publicKey);
    $result = openssl_verify($testJson, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
    
    echo "\n--- Test: $name ---\n";
    echo "JSON: $testJson\n";
    echo "Result: " . ($result === 1 ? "VALID ✅" : "INVALID ❌") . " (result: $result)\n";
}

