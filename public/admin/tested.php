<?php
// test_fees.php - Use real database for testing
require_once __DIR__ . '/../../src/Domain/Services/FeeService.php';
require_once __DIR__ . '/../../src/Domain/Services/ForexService.php';

use Domain\Services\FeeService;
use Domain\Services\ForexService;

// Use a real PDO connection (even for testing)
// Adjust these credentials to match your test database
$db = new PDO('pgsql:host=localhost;dbname=test_db', 'username', 'password');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Load participants from YAML or create minimal test participants
$participants = [
    'ZURUBANK' => [
        'limits' => ['currency' => 'BWP'],
        'cross_border' => ['supported_currencies' => ['BWP', 'ZAR', 'USD']],
        'country' => 'BW'
    ],
    'SACCUSSALIS' => [
        'limits' => ['currency' => 'BWP'],
        'cross_border' => ['supported_currencies' => ['BWP', 'ZAR', 'EUR']],
        'country' => 'BW'
    ],
    'CAZACOM' => [
        'limits' => ['currency' => 'BWP'],
        'cross_border' => ['supported_currencies' => ['BWP', 'ZAR']],
        'country' => 'BW'
    ]
];

// Load fees config
$feesJson = file_get_contents(__DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json');
$feesConfig = json_decode($feesJson, true);

// Create services
$forexService = new ForexService($db, [], $participants);
$feeService = new FeeService([], $feesConfig, 'BWP', $forexService);
$feeService->setParticipants($participants);

echo "=== FEE CALCULATION TEST ===\n\n";

// Test 1: Same currency (BWP → BWP)
echo "Test 1: Same Currency (BWP → BWP)\n";
echo "----------------------------------------\n";
$payload1 = [
    'swap_type' => 'CASHOUT',
    'source_institution' => 'SACCUSSALIS',
    'destination_institution' => 'ZURUBANK',
    'currency' => 'BWP',
    'amount' => 100
];
$result1 = $feeService->calculateFees('CASHOUT', 100, $payload1);
echo "Amount to send: {$result1['net_amount']} {$result1['net_amount_currency']}\n";
echo "Forex applied: " . ($result1['forex']['applied'] ? 'YES' : 'NO') . "\n\n";

// Test 2: Different currencies (BWP → ZAR)
echo "Test 2: Different Currencies (BWP → ZAR)\n";
echo "----------------------------------------\n";
$payload2 = [
    'swap_type' => 'CASHOUT',
    'source_institution' => 'SACCUSSALIS',
    'destination_institution' => 'VOUCHMORPH',
    'currency' => 'BWP',
    'destination_currency' => 'ZAR',
    'amount' => 100
];
$result2 = $feeService->calculateFees('CASHOUT', 100, $payload2);
echo "Original amount: 100 {$result2['gross_amount_currency']}\n";
echo "Fee (F1): {$result2['total_fee']} {$result2['total_fee_currency']}\n";
echo "Net after fee: {$result2['net_amount_source_currency']} {$result2['gross_amount_currency']}\n";
if ($result2['forex']['applied']) {
    echo "Forex rate: {$result2['forex']['rate']}\n";
    echo "After forex: {$result2['net_amount_destination_currency']} {$result2['net_amount_currency']}\n";
    echo "VouchMorph profit: {$result2['forex']['vouchmorph_profit']} {$result2['net_amount_currency']}\n";
}
