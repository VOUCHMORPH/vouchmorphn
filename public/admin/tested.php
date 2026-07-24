<?php
// test_balance.php
// Test balance endpoints for ZURUBANK and SACCUSSALIS

$config = [
    'ZURUBANK' => [
        'url' => 'https://zurubank-production.up.railway.app/Backend/api/v1/accounts/balance.php',
        'api_key' => getenv('ZURUBANK_API_KEY') ?: 'zurubank_live_3uV4wX5yZ6aB7cD8', // Use your actual key
        'account' => '10000001',
        'param' => 'account_number'
    ],
    'SACCUSSALIS_ACCOUNT' => [
        'url' => 'https://saccussalis-production.up.railway.app/backend/api/v1/balance.php',
        'api_key' => 'saccussalis_live_3uV4wX5yZ6aB7cD8',
        'account' => '10000001',
        'param' => 'account_id'
    ],
    'SACCUSSALIS_WALLET' => [
        'url' => 'https://saccussalis-production.up.railway.app/backend/api/v1/balance.php',
        'api_key' => 'saccussalis_live_3uV4wX5yZ6aB7cD8',
        'account' => '+26770000000',
        'param' => 'wallet_phone'
    ]
];

echo "========================================\n";
echo "BALANCE ENDPOINT TEST\n";
echo "========================================\n\n";

foreach ($config as $name => $endpoint) {
    echo "📊 Testing {$name}...\n";
    echo "   URL: {$endpoint['url']}\n";
    echo "   Account: {$endpoint['account']}\n";
    echo "   Parameter: {$endpoint['param']}\n";
    echo "   API Key: " . substr($endpoint['api_key'], 0, 10) . "...\n";
    
    $url = $endpoint['url'] . '?' . $endpoint['param'] . '=' . urlencode($endpoint['account']);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-KEY: ' . $endpoint['api_key'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "   ❌ cURL Error: {$error}\n";
    } else {
        echo "   HTTP Code: {$httpCode}\n";
        $data = json_decode($response, true);
        if ($data) {
            echo "   Response:\n";
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            
            if (isset($data['balance'])) {
                echo "   ✅ Balance: {$data['balance']} " . ($data['currency'] ?? 'BWP') . "\n";
            } else {
                echo "   ⚠️  No balance field found in response\n";
            }
        } else {
            echo "   Raw Response: {$response}\n";
        }
    }
    echo "\n";
}

echo "========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";
