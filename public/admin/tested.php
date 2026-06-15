<?php
// test_fees_simple.php - NO DATABASE REQUIRED!
require_once __DIR__ . '/../../src/Domain/Services/FeeService.php';

use Domain\Services\FeeService;

// Since we're only testing fee calculation (not forex rates),
// we can pass null for forex service
// Fee calculation will work without forex when currencies are the same

// Load fees config
$feesJson = file_get_contents(__DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json');
$feesConfig = json_decode($feesJson, true);

// Create FeeService WITHOUT ForexService (pass null)
$feeService = new FeeService([], $feesConfig, 'BWP', null);

// Set participants for currency lookup (matching your participants.yaml structure)
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
    ],
    'VOUCHMORPH' => [
        'limits' => ['currency' => 'BWP'],
        'cross_border' => ['supported_currencies' => ['BWP', 'ZAR', 'EUR', 'USD', 'GBP']],
        'country' => 'BW'
    ]
];
$feeService->setParticipants($participants);

echo "=== FEE CALCULATION TEST ===\n\n";

// Test 1: Same currency (BWP → BWP) - No forex needed
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
echo "Total Fee: {$result1['total_fee']} {$result1['total_fee_currency']}\n";
echo "Net Amount (to send): {$result1['net_amount']} {$result1['net_amount_currency']}\n";
echo "Gross Amount: {$result1['gross_amount']} {$result1['gross_amount_currency']}\n";
echo "Forex Applied: " . ($result1['forex']['applied'] ? 'YES' : 'NO') . "\n\n";

// Test 2: Show full breakdown
echo "Test 2: Full Fee Breakdown\n";
echo "----------------------------------------\n";
foreach ($result1['breakdown'] as $fee) {
    echo "  {$fee['name']}: {$fee['amount']} {$fee['currency']}\n";
    if (isset($fee['formula'])) {
        echo "    Formula: {$fee['formula']}\n";
    }
    if (isset($fee['earned_at'])) {
        echo "    Earned at: {$fee['earned_at']}\n";
    }
}

echo "\n✓ Test completed successfully!\n";
echo "Expected: 10 BWP fee, 90 BWP to send\n";
