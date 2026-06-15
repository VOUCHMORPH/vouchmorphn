<?php
// test_fees_simple.php - NO DATABASE REQUIRED!
require_once __DIR__ . '/../../src/Domain/Services/FeeService.php';

use Domain\Services\FeeService;

// Create a mock class that implements the required ForexService interface
// but doesn't require a database connection
class MockForexService
{
    public function getClientRate(string $from, string $to, string $clientTier = 'retail'): float
    {
        // Return mock exchange rates for testing
        $rates = [
            'BWP_ZAR' => 1.35,
            'ZAR_BWP' => 0.74,
            'BWP_USD' => 0.074,
            'USD_BWP' => 13.50,
        ];
        
        $key = "{$from}_{$to}";
        return $rates[$key] ?? 1.0;
    }
    
    public function getWholesaleRate(string $from, string $to): float
    {
        // Return mock wholesale rates (slightly better than client rates)
        $rates = [
            'BWP_ZAR' => 1.36,
            'ZAR_BWP' => 0.745,
            'BWP_USD' => 0.0745,
            'USD_BWP' => 13.60,
        ];
        
        $key = "{$from}_{$to}";
        return $rates[$key] ?? 1.0;
    }
}

// Load fees config
$feesJson = file_get_contents(__DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json');
$feesConfig = json_decode($feesJson, true);

// Create mock forex service (no database needed!)
$mockForex = new MockForexService();

// Create FeeService with the mock forex service
$feeService = new FeeService([], $feesConfig, 'BWP', $mockForex);

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

// Test 1: Same currency (BWP → BWP) - No forex
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

// Test 2: Different currencies (BWP → ZAR) - With forex
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
echo "Total Fee: {$result2['total_fee']} {$result2['total_fee_currency']}\n";
echo "Net after fee (source currency): {$result2['net_amount_source_currency']} {$result2['gross_amount_currency']}\n";
if ($result2['forex']['applied']) {
    echo "Forex Rate: {$result2['forex']['rate']}\n";
    echo "Net after forex (destination currency): {$result2['net_amount_destination_currency']} {$result2['net_amount_currency']}\n";
    echo "VouchMorph FX Profit: {$result2['forex']['vouchmorph_profit']} {$result2['net_amount_currency']}\n";
}
echo "\n";

// Test 3: Show full breakdown
echo "Test 3: Full Fee Breakdown\n";
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
