<?php
// test_forex_real.php - Test real forex with different currencies
require_once __DIR__ . '/../../src/Domain/Services/FeeService.php';
require_once __DIR__ . '/../../src/Domain/Services/ForexService.php';

use Domain\Services\FeeService;
use Domain\Services\ForexService;

// Use REAL database connection (not mock)
$db = new PDO('pgsql:host=localhost;dbname=your_db', 'your_user', 'your_password');

// Load participants from your real YAML
$participants = [/* Your real participants from YAML */];

// Load fees config
$feesJson = file_get_contents(__DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json');
$feesConfig = json_decode($feesJson, true);

// Create REAL ForexService (will fetch real rates from banks/API)
$forexService = new ForexService($db, [], $participants);

// Create FeeService with REAL ForexService
$feeService = new FeeService([], $feesConfig, 'BWP', $forexService);
$feeService->setParticipants($participants);

// Test with DIFFERENT currencies - ZAR to BWP
$payload = [
    'swap_type' => 'CASHOUT',
    'source_institution' => 'CAZACOM',     // ZAR currency
    'destination_institution' => 'ZURUBANK', // BWP currency
    'currency' => 'ZAR',
    'destination_currency' => 'BWP',
    'amount' => 100
];

$result = $feeService->calculateFees('CASHOUT', 100, $payload);

// Now forex WILL be applied because currencies differ
echo "Forex Applied: " . ($result['forex']['applied'] ? 'YES' : 'NO') . "\n";
echo "Exchange Rate: {$result['forex']['rate']}\n";
echo "Amount after forex: {$result['net_amount_destination_currency']} {$result['net_amount_currency']}\n";
