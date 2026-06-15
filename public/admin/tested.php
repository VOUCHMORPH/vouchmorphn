<?php
// test_forex_with_rates.php - With real exchange rates, NO database
require_once __DIR__ . '/../../src/Domain/Services/FeeService.php';

use Domain\Services\FeeService;

// Create a simple ForexService with hardcoded rates (no database needed)
class SimpleForexService
{
    private array $clientRates = [
        'ZAR_BWP' => 0.74,   // 1 ZAR = 0.74 BWP
        'BWP_ZAR' => 1.35,   // 1 BWP = 1.35 ZAR
        'USD_BWP' => 13.50,  // 1 USD = 13.50 BWP
        'BWP_USD' => 0.074,  // 1 BWP = 0.074 USD
        'EUR_BWP' => 14.70,  // 1 EUR = 14.70 BWP
        'BWP_EUR' => 0.068,  // 1 BWP = 0.068 EUR
    ];
    
    private array $wholesaleRates = [
        'ZAR_BWP' => 0.745,  // Slightly better for VouchMorph
        'BWP_ZAR' => 1.36,
        'USD_BWP' => 13.60,
        'BWP_USD' => 0.0745,
        'EUR_BWP' => 14.80,
        'BWP_EUR' => 0.0685,
    ];
    
    public function getClientRate(string $from, string $to, string $clientTier = 'retail'): float
    {
        if ($from === $to) return 1.0;
        $key = "{$from}_{$to}";
        return $this->clientRates[$key] ?? 1.0;
    }
    
    public function getWholesaleRate(string $from, string $to): float
    {
        if ($from === $to) return 1.0;
        $key = "{$from}_{$to}";
        return $this->wholesaleRates[$key] ?? 1.0;
    }
}

// Load your actual fees config
$feesJson = file_get_contents(__DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json');
$feesConfig = json_decode($feesJson, true);

// Create ForexService with real rates
$forexService = new SimpleForexService();

// Create FeeService WITH ForexService
$feeService = new FeeService([], $feesConfig, 'BWP', $forexService);

// Set participants with DIFFERENT currencies
$participants = [
    'CAZACOM' => [
        'limits' => ['currency' => 'ZAR'],
        'cross_border' => ['supported_currencies' => ['ZAR', 'BWP']],
        'country' => 'ZA'
    ],
    'ZURUBANK' => [
        'limits' => ['currency' => 'BWP'],
        'cross_border' => ['supported_currencies' => ['BWP', 'ZAR', 'USD']],
        'country' => 'BW'
    ]
];
$feeService->setParticipants($participants);

echo "=== DIFFERENT CURRENCIES TEST (ZAR → BWP) WITH REAL RATES ===\n\n";

// Test with ZAR to BWP
$payload = [
    'swap_type' => 'CASHOUT',
    'source_institution' => 'CAZACOM',
    'destination_institution' => 'ZURUBANK',
    'currency' => 'ZAR',
    'destination_currency' => 'BWP',
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
