<?php
// test_dashboard_api_diagnostic.php
// Run this on VouchMorph server to test the actual API call

echo "=== DASHBOARD API DIAGNOSTIC TEST ===\n\n";

$apiKey = 'vouchmorph_live_1aB2cD3eF4gH5iJ6';
$apiUrl = 'https://vouchmorphn-production.up.railway.app/api/v1/swap/preview.php';

$payload = [
    'swap_type' => 'DEPOSIT',
    'reference' => 'SWAP_DIAG_' . time(),
    'idempotency_key' => 'IDEMP_DIAG_' . time(),
    'user_id' => 1,
    'from_institution' => 'SACCUSSALIS',
    'source_institution' => 'SACCUSSALIS',
    'asset_type' => 'ACCOUNT',
    'amount' => 100,
    'currency' => 'BWP',
    'asset_fields' => [
        'account_number' => '10000001'
    ],
    'account_number' => '10000001',
    'source_identifier' => '10000001',
    'source_identifier_type' => 'account_number',
    'to_institution' => 'SACCUSSALIS',
    'destination_institution' => 'SACCUSSALIS',
    'destination_asset_type' => 'WALLET',
    'destination_currency' => 'BWP',
    'destination_asset_fields' => [
        'phone' => '+26770000000'
    ],
    'destination_phone' => '+26770000000',
    'destination_identifier' => '+26770000000',
    'destination_identifier_type' => 'phone'
];

echo "=== 1. SENDING PAYLOAD TO PREVIEW ===\n";
echo "Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-Key: ' . $apiKey,
    'X-Country-Code: Botswana'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "=== 2. RESPONSE ===\n";
echo "HTTP Code: " . $httpCode . "\n";
if ($curlError) {
    echo "CURL Error: " . $curlError . "\n";
}
echo "Response: " . $response . "\n\n";

$decoded = json_decode($response, true);
if ($decoded && isset($decoded['success']) && $decoded['success']) {
    echo "✅ PREVIEW SUCCESSFUL!\n";
    echo "The payload structure is correct.\n";
} else {
    echo "❌ PREVIEW FAILED!\n";
    echo "Error: " . ($decoded['error'] ?? 'Unknown error') . "\n";
}

echo "\n=== 3. NOW TEST EXECUTE ===\n";
$executeUrl = 'https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php';

$ch = curl_init($executeUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-Key: ' . $apiKey,
    'X-Country-Code: Botswana'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
if ($curlError) {
    echo "CURL Error: " . $curlError . "\n";
}
echo "Response: " . $response . "\n\n";

$decoded = json_decode($response, true);
if ($decoded && isset($decoded['status']) && $decoded['status'] === 'success') {
    echo "✅ EXECUTE SUCCESSFUL!\n";
    echo "The issue is fixed!\n";
} else {
    echo "❌ EXECUTE FAILED!\n";
    echo "Error: " . ($decoded['error'] ?? $decoded['message'] ?? 'Unknown error') . "\n";
    
    // Check for signature error
    if (strpos($response, 'signature') !== false || strpos($response, 'Certificate') !== false) {
        echo "\n=== 4. SIGNATURE ERROR DETECTED ===\n";
        echo "The issue is with certificate/signature verification.\n";
        echo "FIX: Deploy updated CertificateManager.php to ALL servers:\n";
        echo "  1. VouchMorph - REMOVE 'requester' BEFORE signing\n";
        echo "  2. Saccussalis - REMOVE 'requester' BEFORE verification\n";
        echo "  3. ZuruBank - REMOVE 'requester' BEFORE verification\n";
    }
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
