<?php
require_once __DIR__ . '/src/Domain/Services/FeeService.php';
require_once __DIR__ . '/src/Domain/Services/ForexService.php';

use Domain\Services\FeeService;
use Domain\Services\ForexService;

// Mock PDO for testing
$mockDb = new class {
    public function prepare($sql) { return new class { public function execute($p) {} public function fetch($f) { return null; } }; }
    public function exec($sql) { return 0; }
};

// Load fees config
$feesJson = file_get_contents(__DIR__ . '/src/Core/Config/Countries/Botswana/fees.json');
$feesConfig = json_decode($feesJson, true);

// Create ForexService with mock participants
$participants = [
    'CAZACOM' => ['currency' => 'ZAR', 'country' => 'South Africa'],
    'ZURUBANK' => ['currency' => 'BWP', 'country' => 'Botswana']
];

$forexService = new ForexService($mockDb, [], $participants);
$feeService = new FeeService([], $feesConfig, 'BWP', $forexService);
$feeService->setParticipants($participants);

echo "=== FOREX FEE CALCULATION TEST ===\n\n";

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

// Test 2: Different currencies (ZAR → BWP)
echo "Test 2: Different Currencies (ZAR → BWP)\n";
echo "----------------------------------------\n";
$payload2 = [
    'swap_type' => 'CASHOUT',
    'source_institution' => 'CAZACOM',
    'destination_institution' => 'ZURUBANK',
    'currency' => 'ZAR',
    'destination_currency' => 'BWP',
    'amount' => 100
];
$result2 = $feeService->calculateFees('CASHOUT', 100, $payload2);
echo "Original amount: 100 {$result2['gross_amount_currency']}\n";
echo "Fee (F1): {$result2['total_fee']} {$result2['total_fee_currency']}\n";
echo "Net after fee: {$result2['net_amount_source_currency']} {$result2['gross_amount_currency']}\n";
echo "Forex rate: {$result2['forex']['rate']}\n";
echo "After forex: {$result2['net_amount_destination_currency']} {$result2['net_amount_currency']}\n";
echo "Forex applied: " . ($result2['forex']['applied'] ? 'YES' : 'NO') . "\n";

if ($result2['forex']['applied']) {
    echo "  VouchMorph profit: {$result2['forex']['vouchmorph_profit']} {$result2['net_amount_currency']}\n";
}
