<?php
// test_generic_client_simple.php
// Run: php test_generic_client_simple.php

require_once __DIR__ . '/../../src/Infrastructure/Banks/GenericBankClient.php';

use Infrastructure\Banks\GenericBankClient;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "GENERIC BANK CLIENT ENDPOINT TEST\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Hardcoded test configuration (bypassing env vars for this test)
$config = [
    'name' => 'ZURUBANK',
    'provider_code' => 'ZURUBANK',
    'base_url' => 'https://zurubank-production.up.railway.app/Backend',
    'resource_endpoints' => [
        'verify_asset' => '/api/v1/verify_asset.php',
        'place_hold' => '/api/v1/hold.php',
    ],
    'endpoints' => [
        'source' => [
            'verify_asset' => '/api/v1/verify_asset.php',
            'place_hold' => '/api/v1/hold.php',
        ]
    ]
];

echo "Config:\n";
echo "  Base URL: {$config['base_url']}\n";
echo "  Verify Endpoint: {$config['resource_endpoints']['verify_asset']}\n";
echo "  Full URL: {$config['base_url']}{$config['resource_endpoints']['verify_asset']}\n\n";

try {
    $client = new GenericBankClient($config);
    
    // Test 1: Verify Asset
    echo "▶ Test 1: verifyAsset()\n";
    $result = $client->verifyAsset([
        'asset_type' => 'VOUCHER',
        'voucher_number' => '710083197',
        'voucher_pin' => '657250',
        'amount' => 200
    ]);
    
    echo "   HTTP Status: " . ($result['status_code'] ?? 'N/A') . "\n";
    echo "   Success: " . ($result['success'] ? 'true' : 'false') . "\n";
    echo "   Verified: " . (($result['data']['verified'] ?? false) ? 'true' : 'false') . "\n";
    echo "   Message: " . ($result['data']['message'] ?? 'N/A') . "\n";
    echo "   Result: " . ((($result['success'] && ($result['data']['verified'] ?? false)) ? '✅ PASS' : '❌ FAIL')) . "\n\n";
    
    // Test 2: Place Hold (only if verify passed)
    if ($result['success'] && ($result['data']['verified'] ?? false)) {
        echo "▶ Test 2: placeHold()\n";
        $holdResult = $client->placeHold([
            'asset_type' => 'VOUCHER',
            'voucher_number' => '710083197',
            'amount' => 200,
            'reference' => 'TEST_HOLD_' . uniqid(),
            'hold_reason' => 'PENDING_SWAP',
            'destination_institution' => 'SACCUSSALIS',
            'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour'))
        ]);
        
        echo "   HTTP Status: " . ($holdResult['status_code'] ?? 'N/A') . "\n";
        echo "   Success: " . ($holdResult['success'] ? 'true' : 'false') . "\n";
        echo "   Hold Placed: " . (($holdResult['data']['hold_placed'] ?? false) ? 'true' : 'false') . "\n";
        echo "   Hold Reference: " . ($holdResult['data']['hold_reference'] ?? 'N/A') . "\n";
        echo "   Message: " . ($holdResult['data']['message'] ?? 'N/A') . "\n";
        echo "   Result: " . ((($holdResult['success'] && ($holdResult['data']['hold_placed'] ?? false)) ? '✅ PASS' : '❌ FAIL')) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
