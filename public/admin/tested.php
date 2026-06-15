<?php
require_once __DIR__ . '/../../src/Domain/Services/FeeService.php';

use Domain\Services\FeeService;

// Load your fees.json
$feesJson = file_get_contents(__DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json');
$feesConfig = json_decode($feesJson, true);

echo "=== FEE CALCULATION TEST ===\n\n";
echo "Loaded fees config with keys: " . implode(', ', array_keys($feesConfig)) . "\n\n";

// Initialize FeeService
$feeService = new FeeService([], $feesConfig, 'BWP');

// Test CASHOUT product with amount 100
$payload = [
    'swap_type' => 'CASHOUT',
    'source_institution' => 'SACCUSSALIS',
    'destination_institution' => 'ZURUBANK',
    'currency' => 'BWP',
    'amount' => 100
];

echo "Testing CASHOUT with amount: 100 BWP\n";
echo "----------------------------------------\n";

$result = $feeService->calculateFees('CASHOUT', 100, $payload);

echo "Total Fee: " . $result['total_fee'] . " BWP\n";
echo "Net Amount (after fees): " . $result['net_amount'] . " BWP\n";
echo "Gross Amount: " . $result['gross_amount'] . " BWP\n\n";

echo "Fee Breakdown:\n";
foreach ($result['breakdown'] as $fee) {
    echo "  - {$fee['name']} ({$fee['slot']}): {$fee['amount']} BWP\n";
    if (isset($fee['formula'])) {
        echo "    Formula: {$fee['formula']}\n";
    }
}

echo "\nDistribution:\n";
$distribution = $result['distribution'] ?? [];
if (!empty($distribution)) {
    echo "  Levy Fees Total: " . ($distribution['levy_fees_total'] ?? 0) . " BWP\n";
    echo "  Net Distributable Pool: " . ($distribution['net_distributable_pool'] ?? 0) . " BWP\n";
    echo "  Platform Share: " . ($distribution['platform']['amount'] ?? 0) . " BWP\n";
    echo "  Source Share: " . ($distribution['source_institution']['amount'] ?? 0) . " BWP\n";
    echo "  Destination Base Share: " . ($distribution['destination_institution']['amount'] ?? 0) . " BWP\n";
    
    $destSplit = $result['destination_split'] ?? null;
    if ($destSplit) {
        echo "    - Generate Code Fee: {$destSplit['generate_code_fee']} BWP\n";
        echo "    - Cashout Completion Fee: {$destSplit['cashout_completion_fee']} BWP\n";
    }
}

echo "\nExpected Results:\n";
echo "  Amount to send to ZURUBANK: " . $result['net_amount'] . " BWP\n";
echo "  (Original 100 BWP - 10 BWP fee = 90 BWP)\n";
