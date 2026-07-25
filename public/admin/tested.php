<?php
// test_dashboard_diagnostic.php
// Run this on VouchMorph server to diagnose dashboard payload issues

echo "=== DASHBOARD DIAGNOSTIC TEST ===\n\n";

// Simulate the dashboard's buildPayload() function
// Based on the user input shown in the screenshot

$input = [
    'fromInst' => 'SACCUSSALIS',
    'fromAsset' => 'ACCOUNT',
    'fromAmount' => 1000,
    'fromFields' => [
        'account_number' => '10000001'
    ],
    'swapType' => 'DEPOSIT',
    'toInst' => 'SACCUSSALIS',
    'toAsset' => 'WALLET',
    'toFields' => [
        'phone' => '+26770000000'
    ],
    'beneficiaryPhone' => '',
    'deliveryMethod' => 'ATM',
    'userId' => 1
];

function buildDashboardPayload($input) {
    $reference = 'SWAP_DASH_' . time();
    $idempotencyKey = 'IDEMP_DASH_' . time();
    
    $pin = null;
    $sourceCurrency = 'BWP';
    
    // Source fields
    $sourceAssetFields = $input['fromFields'];
    $sourceIdField = $input['fromFields']['account_number'] ?? null;
    
    // Build payload
    $payload = [
        'swap_type' => $input['swapType'],
        'reference' => $reference,
        'idempotency_key' => $idempotencyKey,
        'user_id' => $input['userId'],
        'from_institution' => $input['fromInst'],
        'source_institution' => $input['fromInst'],
        'asset_type' => $input['fromAsset'],
        'amount' => $input['fromAmount'],
        'currency' => $sourceCurrency,
        'wallet_pin' => null,
        'pin' => null,
        'asset_fields' => $sourceAssetFields,
        'account_number' => $input['fromFields']['account_number'] ?? null,
        'source_identifier' => $sourceIdField,
        'source_identifier_type' => 'account_number'
    ];
    
    // DEPOSIT
    if ($input['swapType'] === 'DEPOSIT') {
        $payload['to_institution'] = $input['toInst'];
        $payload['destination_institution'] = $input['toInst'];
        $payload['destination_asset_type'] = $input['toAsset'];
        $payload['destination_currency'] = 'BWP';
        
        $destFields = $input['toFields'];
        $payload['destination_asset_fields'] = $destFields;
        
        foreach ($destFields as $key => $value) {
            $payload['destination_' . $key] = $value;
        }
        $payload['amount'] = $input['fromAmount'];
        
        // Set destination identifier
        $payload['destination_identifier'] = $input['toFields']['phone'] ?? null;
        
        // Set identifier type based on asset type
        if ($input['toAsset'] === 'WALLET') {
            $payload['destination_identifier_type'] = 'phone';
        } elseif ($input['toAsset'] === 'ACCOUNT') {
            $payload['destination_identifier_type'] = 'account_number';
        } else {
            $payload['destination_identifier_type'] = 'account';
        }
    }
    
    // REMOVE requester if present (CertificateManager will add it after signing)
    unset($payload['requester']);
    
    return $payload;
}

$payload = buildDashboardPayload($input);

echo "=== 1. DASHBOARD PAYLOAD (BEFORE SIGNING) ===\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "=== 2. KEY FIELDS CHECK ===\n";
$checks = [
    'swap_type' => $payload['swap_type'] ?? 'MISSING',
    'from_institution' => $payload['from_institution'] ?? 'MISSING',
    'source_identifier' => $payload['source_identifier'] ?? 'MISSING',
    'source_identifier_type' => $payload['source_identifier_type'] ?? 'MISSING',
    'amount' => $payload['amount'] ?? 'MISSING',
    'destination_institution' => $payload['destination_institution'] ?? 'MISSING',
    'destination_asset_type' => $payload['destination_asset_type'] ?? 'MISSING',
    'destination_identifier' => $payload['destination_identifier'] ?? 'MISSING',
    'destination_identifier_type' => $payload['destination_identifier_type'] ?? 'MISSING',
    'requester' => isset($payload['requester']) ? 'PRESENT (BAD)' : 'NOT SET (GOOD)',
];
foreach ($checks as $key => $value) {
    $status = ($value === 'MISSING' || strpos($value, 'BAD') !== false) ? '❌' : '✅';
    echo "  $status $key: $value\n";
}

echo "\n=== 3. WHAT SACCUSSALIS EXPECTS ===\n";
$expected = [
    'destination_identifier_type' => 'phone',
    'destination_identifier' => '+26770000000',
    'destination_asset_type' => 'WALLET',
    'source_identifier_type' => 'account_number',
    'source_identifier' => '10000001'
];
foreach ($expected as $key => $value) {
    $actual = $payload[$key] ?? 'MISSING';
    $status = ($actual === $value) ? '✅' : '❌';
    echo "  $status $key: expected '$value', got '$actual'\n";
}

echo "\n=== 4. CERTIFICATE MANAGER SIGNING BEHAVIOR ===\n";
echo "The CertificateManager will:\n";
echo "  1. Receive payload: " . json_encode(array_keys($payload)) . "\n";
echo "  2. Check for 'requester' in payload: " . (isset($payload['requester']) ? 'FOUND' : 'NOT FOUND') . "\n";
echo "  3. Remove 'requester' before signing: YES (if present)\n";
echo "  4. Sign payload WITHOUT 'requester'\n";
echo "  5. Add 'requester' AFTER signing: YES\n";
echo "  6. Send signed payload with 'requester'\n\n";

echo "=== 5. WHY SIGNATURE VERIFICATION FAILS ===\n";
echo "The issue is NOT the dashboard payload structure.\n";
echo "The issue is the SIGNING process:\n\n";
echo "  Step 1: VouchMorph signs the payload\n";
echo "    - Payload to sign: " . json_encode($payload) . "\n";
echo "    - BUT CertificateManager REMOVES 'requester' before signing\n";
echo "    - So it actually signs: " . json_encode(array_diff_key($payload, ['requester' => 1])) . "\n\n";
echo "  Step 2: Saccussalis receives the signed payload\n";
echo "    - Saccussalis REMOVES 'requester' before verification\n";
echo "    - So it verifies: " . json_encode(array_diff_key($payload, ['requester' => 1])) . "\n\n";
echo "  Result: The signed payload and verified payload MATCH ✅\n";
echo "  BUT there's a different issue...\n\n";

echo "=== 6. THE REAL ISSUE ===\n";
echo "The certificate verification is failing because:\n";
echo "  1. VouchMorph is signing WITHOUT 'requester'\n";
echo "  2. Saccussalis is verifying WITHOUT 'requester'\n";
echo "  3. BUT the actual SIGNATURE was generated from the payload WITHOUT 'requester'\n";
echo "  4. AND Saccussalis is verifying the payload WITHOUT 'requester'\n";
echo "  5. This SHOULD work!\n\n";
echo "The issue is likely that:\n";
echo "  - The CertificateManager on Saccussalis is NOT removing 'requester'\n";
echo "  - OR the CertificateManager on VouchMorph is NOT removing 'requester'\n";
echo "  - OR there's a version mismatch between the two\n\n";

echo "=== 7. COMPARE WITH WORKING CURL COMMAND ===\n";
echo "The curl command that works sends:\n";
$curlPayload = [
    'swap_type' => 'DEPOSIT',
    'reference' => 'SWAP_ACC_TO_WALLET_1784977378',
    'idempotency_key' => 'IDEMP_ACC_TO_WALLET_1784977378',
    'user_id' => 1,
    'from_institution' => 'SACCUSSALIS',
    'source_institution' => 'SACCUSSALIS',
    'asset_type' => 'ACCOUNT',
    'amount' => 100,
    'currency' => 'BWP',
    'asset_fields' => ['account_number' => '10000001'],
    'account_number' => '10000001',
    'source_identifier' => '10000001',
    'to_institution' => 'SACCUSSALIS',
    'destination_institution' => 'SACCUSSALIS',
    'destination_asset_type' => 'WALLET',
    'destination_currency' => 'BWP',
    'destination_identifier' => '+26770000000'
];
echo "  " . json_encode($curlPayload) . "\n\n";

echo "Dashboard payload (without requester):\n";
$dashPayload = $payload;
unset($dashPayload['requester']);
echo "  " . json_encode($dashPayload) . "\n\n";

$diff = array_diff_assoc($curlPayload, $dashPayload);
if (empty($diff)) {
    echo "✅ PAYLOADS MATCH! The dashboard is sending the correct structure.\n";
    echo "   The issue is in the SIGNING/VERIFICATION process.\n";
} else {
    echo "❌ PAYLOADS DIFFER:\n";
    foreach ($diff as $key => $value) {
        echo "   - $key: curl='$value', dashboard='" . ($dashPayload[$key] ?? 'MISSING') . "'\n";
    }
}

echo "\n=== 8. RECOMMENDED FIX ===\n";
echo "1. Deploy updated CertificateManager.php to VouchMorph:\n";
echo "   - Ensure it REMOVES 'requester' BEFORE signing\n";
echo "   - Ensure it ADDS 'requester' AFTER signing\n";
echo "\n";
echo "2. Deploy updated CertificateManager.php to Saccussalis:\n";
echo "   - Ensure it REMOVES 'requester' BEFORE verification\n";
echo "\n";
echo "3. Deploy updated CertificateManager.php to ZuruBank:\n";
echo "   - Ensure it REMOVES 'requester' BEFORE verification\n";
echo "\n";
echo "4. Test the dashboard again\n";

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
