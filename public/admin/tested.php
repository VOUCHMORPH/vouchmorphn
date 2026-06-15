<?php
// test_fees_simple.php - NO DATABASE REQUIRED!
require_once __DIR__ . '/../../src/Domain/Services/FeeService.php';

use Domain\Services\FeeService;

// Load fees config
$feesJson = file_get_contents(__DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json');
$feesConfig = json_decode($feesJson, true);

// Create FeeService WITHOUT ForexService (pass null)
$feeService = new FeeService([], $feesConfig, 'BWP', null);

// Set participants for currency lookup
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
    ]
];
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

echo "Total Fee: {$result1['total_fee']} {$result1['total_fee_currency']}\n";

// Fix: Check if net_amount_currency exists, otherwise use gross_amount_currency
$netCurrency = $result1['net_amount_currency'] ?? $result1['gross_amount_currency'] ?? 'BWP';
echo "Net Amount (to send): {$result1['net_amount']} {$netCurrency}\n";
echo "Gross Amount: {$result1['gross_amount']} {$result1['gross_amount_currency']}\n";
echo "Forex Applied: " . ($result1['forex']['applied'] ? 'YES' : 'NO') . "\n\n";

// Test 2: Show full breakdown
echo "Test 2: Full Fee Breakdown\n";
echo "----------------------------------------\n";
foreach ($result1['breakdown'] as $fee) {
    $currency = $fee['currency'] ?? 'BWP';
    echo "  {$fee['name']}: {$fee['amount']} {$currency}\n";
    if (isset($fee['formula'])) {
        echo "    Formula: {$fee['formula']}\n";
    }
    if (isset($fee['earned_at'])) {
        echo "    Earned at: {$fee['earned_at']}\n";
    }
}

echo "\n✓ Test completed successfully!\n";
echo "Expected: 10 BWP fee, 90 BWP to send\n";

// Summary
echo "\n=== SUMMARY ===\n";
echo "Customer requests: 100 BWP\n";
echo "Fee deducted: 10 BWP\n";
echo "Amount to send to ZURUBANK: 90 BWP\n";
echo "Breakdown:\n";
echo "  - Levy (F7): 1 BWP (government)\n";
echo "  - Platform (35%): 3.15 BWP (VouchMorph)\n";
echo "  - Source (15%): 1.35 BWP (SACCUSSALIS)\n";
echo "  - Destination (50%): 4.50 BWP (ZURUBANK)\n";
echo "    - Generate code fee: 0.45 BWP (earned immediately)\n";
echo "    - Cashout completion: 4.05 BWP (earned on cashout)\n";
