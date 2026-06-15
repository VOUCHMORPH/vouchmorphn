<?php
// test_forex_real.php - Test different currencies with NO database
require_once __DIR__ . '/../../src/Domain/Services/FeeService.php';

use Domain\Services\FeeService;

// Load your actual fees config
$feesJson = file_get_contents(__DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json');
$feesConfig = json_decode($feesJson, true);

// Create FeeService with NO database (pass null for forex)
$feeService = new FeeService([], $feesConfig, 'BWP', null);

// Set participants with DIFFERENT currencies
$participants = [
    'CAZACOM' => [
        'limits' => ['currency' => 'ZAR'],  // South African Rand
        'cross_border' => ['supported_currencies' => ['ZAR', 'BWP']],
        'country' => 'ZA'
    ],
    'ZURUBANK' => [
        'limits' => ['currency' => 'BWP'],  // Botswana Pula
        'cross_border' => ['supported_currencies' => ['BWP', 'ZAR', 'USD']],
        'country' => 'BW'
    ]
];
$feeService->setParticipants($participants);

echo "=== DIFFERENT CURRENCIES TEST (ZAR → BWP) ===\n\n";

// Test with ZAR to BWP
$payload = [
    'swap_type' => 'CASHOUT',
    'source_institution' => 'CAZACOM',      // ZAR currency
    'destination_institution' => 'ZURUBANK', // BWP currency
    'currency' => 'ZAR',
    'destination_currency' => 'BWP',        // Explicit destination currency
    'amount' => 100
];

$result = $feeService->calculateFees('CASHOUT', 100, $payload);

echo "Source Currency: {$result['gross_amount_currency']}\n";
echo "Destination Currency: {$result['net_amount_currency']}\n";
echo "Original Amount: 100 {$result['gross_amount_currency']}\n";
echo "Total Fee (F1): {$result['total_fee']} {$result['total_fee_currency']}\n";
echo "Net after fee: {$result['net_amount_source_currency']} {$result['gross_amount_currency']}\n";

if ($result['forex']['applied']) {
    echo "\n--- FOREX APPLIED ---\n";
    echo "Exchange Rate: {$result['forex']['rate']}\n";
    echo "After conversion: {$result['net_amount_destination_currency']} {$result['net_amount_currency']}\n";
    echo "VouchMorph FX Profit: {$result['forex']['vouchmorph_profit']} {$result['net_amount_currency']}\n";
} else {
    echo "\nNo forex applied (currencies are the same)\n";
}

echo "\n=== FULL BREAKDOWN ===\n";
foreach ($result['breakdown'] as $fee) {
    $currency = $fee['currency'] ?? $result['gross_amount_currency'];
    echo "  {$fee['name']}: {$fee['amount']} {$currency}\n";
}

echo "\n=== SUMMARY ===\n";
echo "Customer in South Africa requests: 100 ZAR cashout\n";
echo "Fee deducted: 10 ZAR\n";
echo "Net after fee: 90 ZAR\n";
echo "Forex conversion (ZAR → BWP): 90 × {$result['forex']['rate']} = {$result['net_amount_destination_currency']} BWP\n";
echo "Amount to send to ZURUBANK: {$result['net_amount_destination_currency']} BWP\n";
