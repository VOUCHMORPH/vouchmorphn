<?php
// /var/www/html/public/admin/test_preview_debug.php

echo "=== PREVIEW DEBUG TEST ===\n\n";

$apiKey = 'vouchmorph_live_1aB2cD3eF4gH5iJ6';
$url = 'https://vouchmorphn-production.up.railway.app/api/v1/swap/preview.php';

$payload = [
    'swap_type' => 'DEPOSIT',
    'reference' => 'SWAP_DEBUG_' . time(),
    'idempotency_key' => 'IDEMP_DEBUG_' . time(),
    'user_id' => 1,
    'from_institution' => 'SACCUSSALIS',
    'source_institution' => 'SACCUSSALIS',
    'asset_type' => 'ACCOUNT',
    'amount' => 100,
    'currency' => 'BWP',
    'asset_fields' => ['account_number' => '10000001'],
    'account_number' => '10000001',
    'source_identifier' => '10000001',
    'source_identifier_type' => 'account_number',
    'to_institution' => 'SACCUSSALIS',
    'destination_institution' => 'SACCUSSALIS',
    'destination_asset_type' => 'WALLET',
    'destination_currency' => 'BWP',
    'destination_asset_fields' => ['phone' => '+26770000000'],
    'destination_phone' => '+26770000000',
    'destination_identifier' => '+26770000000',
    'destination_identifier_type' => 'phone'
];

echo "1. PAYLOAD:\n";
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

echo "2. SENDING REQUEST...\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-Key: ' . $apiKey,
    'X-Country-Code: Botswana'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "3. RESPONSE:\n";
echo "HTTP Code: " . $httpCode . "\n";
echo "CURL Error: " . ($curlError ?: 'None') . "\n";
echo "Response: " . ($response ?: '(empty)') . "\n\n";

if ($response) {
    $decoded = json_decode($response, true);
    if ($decoded) {
        echo "4. DECODED RESPONSE:\n";
        echo json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
        
        if (isset($decoded['success']) && $decoded['success'] === true) {
            echo "\n✅ PREVIEW SUCCESSFUL!\n";
        } else {
            echo "\n❌ PREVIEW FAILED!\n";
            echo "Error: " . ($decoded['error'] ?? 'Unknown error') . "\n";
        }
    } else {
        echo "4. RESPONSE IS NOT VALID JSON\n";
        echo "Raw response: " . substr($response, 0, 500) . "\n";
    }
}

echo "\n=== DEBUG COMPLETE ===\n";
