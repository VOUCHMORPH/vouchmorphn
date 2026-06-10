<?php
// test_php_env.php
// Run: php test_php_env.php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Infrastructure/Banks/GenericBankClient.php';

use Infrastructure\Banks\GenericBankClient;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "PHP CONTAINER ENVIRONMENT VARIABLES TEST\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Read directly from PHP container environment variables
$zurubankBaseUrl = getenv('ZURUBANK_BASE_URL');
$zurubankVerifyEndpoint = getenv('ZURUBANK_VERIFY_ENDPOINT');
$zurubankHoldEndpoint = getenv('ZURUBANK_HOLD_ENDPOINT');

$saccussalisBaseUrl = getenv('SACCUSSALIS_BASE_URL');
$saccussalisGenerateTokenEndpoint = getenv('SACCUSSALIS_GENERATE_TOKEN_ENDPOINT');

$cazacomBaseUrl = getenv('CAZACOM_BASE_URL');

echo "Environment Variables in PHP Container:\n";
echo "  ZURUBANK_BASE_URL: " . ($zurubankBaseUrl ?: "NOT SET") . "\n";
echo "  ZURUBANK_VERIFY_ENDPOINT: " . ($zurubankVerifyEndpoint ?: "NOT SET") . "\n";
echo "  ZURUBANK_HOLD_ENDPOINT: " . ($zurubankHoldEndpoint ?: "NOT SET") . "\n";
echo "  SACCUSSALIS_BASE_URL: " . ($saccussalisBaseUrl ?: "NOT SET") . "\n";
echo "  SACCUSSALIS_GENERATE_TOKEN_ENDPOINT: " . ($saccussalisGenerateTokenEndpoint ?: "NOT SET") . "\n";
echo "  CAZACOM_BASE_URL: " . ($cazacomBaseUrl ?: "NOT SET") . "\n\n";

if (!$zurubankBaseUrl) {
    echo "❌ ZURUBANK_BASE_URL is NOT SET in PHP container.\n";
    echo "   Add it via: railway variable set ZURUBANK_BASE_URL=... --service vouchmorphn\n";
    exit(1);
}

// Build config from environment variables
$config = [
    'name' => 'ZURUBANK',
    'provider_code' => 'ZURUBANK',
    'base_url' => $zurubankBaseUrl,
    'resource_endpoints' => [
        'verify_asset' => $zurubankVerifyEndpoint ?: '/api/v1/verify_asset.php',
        'place_hold' => $zurubankHoldEndpoint ?: '/api/v1/hold.php',
    ],
    'endpoints' => [
        'source' => [
            'verify_asset' => $zurubankVerifyEndpoint ?: '/api/v1/verify_asset.php',
            'place_hold' => $zurubankHoldEndpoint ?: '/api/v1/hold.php',
        ]
    ]
];

echo "Using configuration:\n";
echo "  Base URL: {$config['base_url']}\n";
echo "  Verify Endpoint: {$config['resource_endpoints']['verify_asset']}\n";
echo "  Full URL: {$config['base_url']}{$config['resource_endpoints']['verify_asset']}\n\n";

try {
    $client = new GenericBankClient($config);
    
    // Test 1: Verify Asset
    echo "▶ TEST 1: verifyAsset()\n";
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
    
    if ($result['success'] && ($result['data']['verified'] ?? false)) {
        echo "   ✅ VERIFY PASSED\n\n";
        
        // Test 2: Place Hold
        echo "▶ TEST 2: placeHold()\n";
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
        
        if ($holdResult['success'] && ($holdResult['data']['hold_placed'] ?? false)) {
            echo "   ✅ HOLD PASSED\n";
        } else {
            echo "   ❌ HOLD FAILED\n";
        }
        
    } else {
        echo "   ❌ VERIFY FAILED - Cannot proceed to hold test\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
