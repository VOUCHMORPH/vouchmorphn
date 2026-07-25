<?php
// test_dashboard_payload.php
// Run this on VouchMorph server to see what the dashboard payload looks like

echo "=== DASHBOARD PAYLOAD TEST ===\n\n";

// Simulate what the dashboard's buildPayload() would produce
// based on the user's input

$state = [
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
    'deliveryMethod' => 'ATM'
];

// Build payload using the same logic as dashboard
function buildPayload($state) {
    $reference = 'SWAP_TEST_' . time();
    $idempotencyKey = 'IDEMP_TEST_' . time();
    $userId = 1;
    
    // Source currency
    $sourceCurrency = 'BWP';
    
    // Build source identifier
    $sourceIdentifier = $state['fromFields']['account_number'] ?? null;
    $sourceIdentifierType = 'account_number';
    
    $payload = [
        'swap_type' => $state['swapType'],
        'reference' => $reference,
        'idempotency_key' => $idempotencyKey,
        'user_id' => $userId,
        'from_institution' => $state['fromInst'],
        'source_institution' => $state['fromInst'],
        'asset_type' => $state['fromAsset'],
        'amount' => $state['fromAmount'],
        'currency' => $sourceCurrency,
        'wallet_pin' => null,
        'pin' => null,
        'asset_fields' => $state['fromFields'],
        'account_number' => $state['fromFields']['account_number'] ?? null,
        'source_identifier' => $sourceIdentifier,
        'source_identifier_type' => $sourceIdentifierType
    ];
    
    // DEPOSIT
    if ($state['swapType'] === 'DEPOSIT') {
        $payload['to_institution'] = $state['toInst'];
        $payload['destination_institution'] = $state['toInst'];
        $payload['destination_asset_type'] = $state['toAsset'];
        $payload['destination_currency'] = 'BWP';
        
        $destFields = $state['toFields'];
        $payload['destination_asset_fields'] = $destFields;
        
        // Add destination fields
        foreach ($destFields as $key => $value) {
            $payload['destination_' . $key] = $value;
        }
        $payload['amount'] = $state['fromAmount'];
        
        // Set destination identifier
        $payload['destination_identifier'] = $state['toFields']['phone'] ?? null;
        
        // FIX: Set destination_identifier_type based on asset type
        if ($state['toAsset'] === 'WALLET') {
            $payload['destination_identifier_type'] = 'phone';
        } elseif ($state['toAsset'] === 'ACCOUNT') {
            $payload['destination_identifier_type'] = 'account_number';
        } else {
            $payload['destination_identifier_type'] = 'account';
        }
    }
    
    return $payload;
}

$payload = buildPayload($state);

echo "=== PAYLOAD STRUCTURE ===\n";
echo "Swap Type: " . $payload['swap_type'] . "\n";
echo "Source: " . $payload['from_institution'] . "\n";
echo "Source Asset: " . $payload['asset_type'] . "\n";
echo "Source Identifier: " . $payload['source_identifier'] . "\n";
echo "Source Identifier Type: " . $payload['source_identifier_type'] . "\n";
echo "Amount: " . $payload['amount'] . "\n";
echo "Destination: " . $payload['destination_institution'] . "\n";
echo "Destination Asset: " . $payload['destination_asset_type'] . "\n";
echo "Destination Identifier: " . $payload['destination_identifier'] . "\n";
echo "Destination Identifier Type: " . $payload['destination_identifier_type'] . "\n";
echo "\n";

echo "=== FULL PAYLOAD (JSON) ===\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "=== KEY FIELDS TO CHECK ===\n";
echo "1. source_identifier_type: " . ($payload['source_identifier_type'] ?? 'MISSING') . "\n";
echo "2. destination_identifier_type: " . ($payload['destination_identifier_type'] ?? 'MISSING') . "\n";
echo "3. beneficiary_phone (for cashout): " . ($payload['beneficiary_phone'] ?? 'NOT SET (ok for deposit)') . "\n";
echo "4. client_phone (for cashout): " . ($payload['client_phone'] ?? 'NOT SET (ok for deposit)') . "\n";
echo "5. requester: " . ($payload['requester'] ?? 'NOT SET') . "\n";
echo "\n";

echo "=== WHAT SACCUSSALIS EXPECTS ===\n";
echo "For WALLET deposit:\n";
echo "  - destination_identifier_type should be: 'phone'\n";
echo "  - destination_identifier should be: '+26770000000'\n";
echo "  - destination_asset_type should be: 'WALLET'\n";
echo "\n";

if ($payload['destination_identifier_type'] === 'phone') {
    echo "✅ destination_identifier_type is CORRECT: 'phone'\n";
} else {
    echo "❌ destination_identifier_type is WRONG: '" . ($payload['destination_identifier_type'] ?? 'null') . "' (should be 'phone')\n";
}

if ($payload['destination_identifier'] === '+26770000000') {
    echo "✅ destination_identifier is CORRECT: '+26770000000'\n";
} else {
    echo "❌ destination_identifier is WRONG: '" . ($payload['destination_identifier'] ?? 'null') . "' (should be '+26770000000')\n";
}

echo "\n=== WHAT WOULD CAUSE 'INVALID SIGNATURE' ===\n";
echo "The signature is invalid because:\n";
echo "1. VouchMorph signs the payload WITH 'requester' included\n";
echo "2. Saccussalis verifies WITHOUT 'requester' (removes it)\n";
echo "3. The payloads don't match → signature verification fails\n";
echo "\n";
echo "FIX: Update CertificateManager.php to NOT include 'requester' in signed payload\n";
