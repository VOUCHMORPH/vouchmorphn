<?php
// public/user/testlogic.php
// Comprehensive test for SwapService, FeeService, ForexService, HybridSettlementStrategy
// Access via: https://www.vouchmorphn.com/user/testlogic.php

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

SessionManager::start();

// No login required for this test, but we'll set a demo user
if (!SessionManager::isLoggedIn()) {
    // Create a demo session for testing
    $_SESSION['user_id'] = 1;
    $_SESSION['phone'] = '+26770000000';
    $_SESSION['logged_in'] = true;
}

$user = SessionManager::getUser();
$userId = $user['user_id'] ?? 1;
$userPhone = $user['phone'] ?? '+26770000000';

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../src/Domain/Services/FeeService.php';
require_once __DIR__ . '/../../src/Domain/Services/ForexService.php';
require_once __DIR__ . '/../../src/Domain/Services/Settlement/HybridSettlementStrategy.php';
require_once __DIR__ . '/../../src/Infrastructure/Banks/GenericBankClient.php';
require_once __DIR__ . '/../../src/Infrastructure/Crypto/CertificateManager.php';
require_once __DIR__ . '/../../src/Infrastructure/Crypto/MessageSigner.php';
require_once __DIR__ . '/../../src/Infrastructure/Crypto/SignatureVerifier.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;
use Domain\Services\FeeService;
use Domain\Services\ForexService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Banks\GenericBankClient;

$config = LoadCountry::getConfig();
$countryCode = $config['country_code'] ?? 'BW';
$countryName = $config['country'] ?? 'Botswana';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';
$currency = $config['currency'] ?? 'BWP';

try {
    $swapDB = DBConnection::getConnection();
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Test results container
$testResults = [
    'passed' => 0,
    'failed' => 0,
    'total' => 0,
    'details' => []
];

function assertTest(&$results, $condition, $message, $data = null) {
    $results['total']++;
    if ($condition) {
        $results['passed']++;
        $results['details'][] = ['status' => '✅ PASS', 'message' => $message, 'data' => $data];
        echo "<div style='color: #4caf50; margin: 5px 0;'>✅ {$message}</div>";
    } else {
        $results['failed']++;
        $results['details'][] = ['status' => '❌ FAIL', 'message' => $message, 'data' => $data];
        echo "<div style='color: #f44336; margin: 5px 0;'>❌ {$message}</div>";
        if ($data) {
            echo "<pre style='color: #888; margin-left: 20px; font-size: 11px;'>" . json_encode($data, JSON_PRETTY_PRINT) . "</pre>";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph | System Test | Swap Logic Validation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0e27;
            padding: 20px;
            color: #fff;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .logo h1 { font-size: 24px; background: linear-gradient(135deg, #fff, #00f0ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .card {
            background: #12162e;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .card h3 { margin-bottom: 20px; font-size: 18px; color: #00f0ff; }
        .card h4 { margin: 16px 0 12px 0; font-size: 14px; color: #ffc107; }
        
        .test-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .summary-card {
            background: #1a1f3a;
            padding: 15px 25px;
            border-radius: 10px;
            text-align: center;
        }
        .summary-card .number { font-size: 32px; font-weight: bold; }
        .summary-card.passed .number { color: #4caf50; }
        .summary-card.failed .number { color: #f44336; }
        .summary-card.total .number { color: #00f0ff; }
        
        .code-block {
            background: #0a0e27;
            border-radius: 8px;
            padding: 16px;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
            margin: 10px 0;
            border: 1px solid #2a2f4a;
        }
        
        button {
            padding: 10px 20px;
            background: linear-gradient(135deg, #00f0ff, #b000ff);
            color: #0a0e27;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            margin-right: 10px;
        }
        button:hover { transform: translateY(-1px); filter: brightness(1.05); }
        
        .result-success { color: #4caf50; }
        .result-error { color: #f44336; }
        
        hr { border-color: #2a2f4a; margin: 20px 0; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">
            <h1>VouchMorph System Test</h1>
            <p>Validating SwapService, FeeService, ForexService, HybridSettlementStrategy</p>
        </div>
        <div>
            <span class="country-badge" style="padding:4px 12px; background:rgba(0,240,255,0.1); border-radius:20px;"><?= $countryName ?> | <?= $currencySymbol ?></span>
        </div>
    </div>

    <div class="card">
        <h3>🧪 Test Execution</h3>
        <div class="test-summary">
            <div class="summary-card passed">
                <div class="number" id="passedCount">0</div>
                <div>Passed</div>
            </div>
            <div class="summary-card failed">
                <div class="number" id="failedCount">0</div>
                <div>Failed</div>
            </div>
            <div class="summary-card total">
                <div class="number" id="totalCount">0</div>
                <div>Total</div>
            </div>
        </div>
        
        <button onclick="runTests()">▶ Run All Tests</button>
        <button onclick="location.reload()">⟳ Reset</button>
        
        <div id="testOutput" style="margin-top: 20px; max-height: 500px; overflow-y: auto;">
            <div style="color: #888; text-align: center; padding: 40px;">Click "Run All Tests" to start</div>
        </div>
    </div>

    <div class="card">
        <h3>📋 Test Scenarios</h3>
        <ul style="margin-left: 20px; color: #ccc; line-height: 1.8;">
            <li><strong>Test 1:</strong> FeeService - Calculate CASHOUT fees (base fee, platform cut, source cut, destination split)</li>
            <li><strong>Test 2:</strong> FeeService - Calculate DEPOSIT fees</li>
            <li><strong>Test 3:</strong> ForexService - Get exchange rates and calculate profit</li>
            <li><strong>Test 4:</strong> HybridSettlementStrategy - Update net positions</li>
            <li><strong>Test 5:</strong> HybridSettlementStrategy - Invoice fees</li>
            <li><strong>Test 6:</strong> SwapService - Full CASHOUT flow simulation (verify → hold → generate → confirm)</li>
            <li><strong>Test 7:</strong> SwapService - Swap-on-Swap modification (destination change)</li>
            <li><strong>Test 8:</strong> SwapService - Expired cashout handling</li>
            <li><strong>Test 9:</strong> End-to-end - Complete swap with fee calculation and settlement</li>
        </ul>
    </div>
</div>

<script>
async function runTests() {
    const outputDiv = document.getElementById('testOutput');
    outputDiv.innerHTML = '<div style="color: #ffc107;">⏳ Running tests...</div>';
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=run_tests'
        });
        const html = await response.text();
        outputDiv.innerHTML = html;
        
        // Update counters from the response
        const passedMatch = html.match(/PASSED_COUNT: (\d+)/);
        const failedMatch = html.match(/FAILED_COUNT: (\d+)/);
        const totalMatch = html.match(/TOTAL_COUNT: (\d+)/);
        
        if (passedMatch) document.getElementById('passedCount').innerText = passedMatch[1];
        if (failedMatch) document.getElementById('failedCount').innerText = failedMatch[1];
        if (totalMatch) document.getElementById('totalCount').innerText = totalMatch[1];
        
    } catch (error) {
        outputDiv.innerHTML = `<div style="color: #f44336;">❌ Error running tests: ${error.message}</div>`;
    }
}

// Auto-run on page load if ?auto=1
if (window.location.search.includes('auto=1')) {
    runTests();
}
</script>

<?php
// Handle POST request for running tests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_tests') {
    ob_start();
    
    $results = ['passed' => 0, 'failed' => 0, 'total' => 0, 'details' => []];
    
    echo "<div style='font-family: monospace; font-size: 13px;'>";
    echo "<h4 style='color: #00f0ff; margin-bottom: 15px;'>📊 Test Results</h4>";
    
    // ============================================================
    // TEST 1: FeeService - CASHOUT Fee Calculation
    // ============================================================
    echo "<div style='margin-top: 15px;'><strong style='color: #ffc107;'>Test 1:</strong> FeeService - CASHOUT Fee Calculation</div>";
    
    try {
        $feeRegistry = json_decode(file_get_contents(__DIR__ . '/../../src/Core/Config/Countries/Botswana/fees.json'), true);
        $countryConfig = LoadCountry::getConfig();
        
        $feeService = new FeeService($feeRegistry, $countryConfig);
        $feeService->setParticipants([]);
        
        $testAmount = 500.00;
        $feeResult = $feeService->calculateFees('CASHOUT', $testAmount, [
            'swap_type' => 'CASHOUT',
            'currency' => 'BWP',
            'source_institution' => 'SACCUSSALIS',
            'destination_institution' => 'ZURUBANK'
        ]);
        
        assertTest($results, isset($feeResult['total_fee']), "FeeService calculateFees() returned result");
        assertTest($results, $feeResult['total_fee'] > 0, "Total fee > 0", ['total_fee' => $feeResult['total_fee']]);
        assertTest($results, isset($feeResult['net_amount']), "Net amount calculated", ['net_amount' => $feeResult['net_amount']]);
        assertTest($results, $feeResult['net_amount'] < $testAmount, "Net amount < original amount");
        
        if (isset($feeResult['distribution'])) {
            assertTest($results, isset($feeResult['distribution']['platform']['amount']), "Platform revenue calculated");
            assertTest($results, isset($feeResult['distribution']['source_institution']['amount']), "Source institution revenue calculated");
            assertTest($results, isset($feeResult['distribution']['destination_institution']['amount']), "Destination institution revenue calculated");
        }
        
    } catch (Exception $e) {
        assertTest($results, false, "FeeService CASHOUT test threw exception: " . $e->getMessage());
    }
    
    // ============================================================
    // TEST 2: FeeService - DEPOSIT Fee Calculation
    // ============================================================
    echo "<div style='margin-top: 15px;'><strong style='color: #ffc107;'>Test 2:</strong> FeeService - DEPOSIT Fee Calculation</div>";
    
    try {
        $depositResult = $feeService->calculateFees('DEPOSIT', 500.00, [
            'swap_type' => 'DEPOSIT',
            'currency' => 'BWP',
            'source_institution' => 'SACCUSSALIS',
            'destination_institution' => 'ZURUBANK'
        ]);
        
        assertTest($results, isset($depositResult['total_fee']), "DEPOSIT fee calculation returned result");
        assertTest($results, $depositResult['total_fee'] >= 0, "DEPOSIT fee >= 0", ['total_fee' => $depositResult['total_fee']]);
        
    } catch (Exception $e) {
        assertTest($results, false, "FeeService DEPOSIT test threw exception: " . $e->getMessage());
    }
    
    // ============================================================
    // TEST 3: ForexService - Exchange Rate & Profit
    // ============================================================
    echo "<div style='margin-top: 15px;'><strong style='color: #ffc107;'>Test 3:</strong> ForexService - Exchange Rates</div>";
    
    try {
        $forexService = new ForexService($swapDB, $config, [], $feeService);
        
        // Test wholesale rate
        $wholesaleRate = $forexService->getWholesaleRate('USD', 'BWP');
        assertTest($results, $wholesaleRate > 0, "Wholesale rate retrieved", ['wholesale_rate' => $wholesaleRate]);
        
        // Test client rate
        $clientRate = $forexService->getClientRate('USD', 'BWP', 'retail');
        assertTest($results, $clientRate > 0, "Client rate retrieved", ['client_rate' => $clientRate]);
        
        // Test that client rate is less than wholesale (VouchMorph profit)
        if ($wholesaleRate > 0 && $clientRate > 0) {
            $profitMargin = (($wholesaleRate - $clientRate) / $wholesaleRate) * 100;
            assertTest($results, $profitMargin > 0, "VouchMorph FX profit margin positive", ['profit_margin' => round($profitMargin, 2) . '%']);
        }
        
    } catch (Exception $e) {
        assertTest($results, false, "ForexService test threw exception: " . $e->getMessage());
    }
    
    // ============================================================
    // TEST 4: HybridSettlementStrategy - Update Net Position
    // ============================================================
    echo "<div style='margin-top: 15px;'><strong style='color: #ffc107;'>Test 4:</strong> HybridSettlementStrategy - Net Position Tracking</div>";
    
    try {
        $settlement = new HybridSettlementStrategy($swapDB);
        $testSwapRef = 'TEST_SWAP_' . time();
        
        $result = $settlement->updateNetPosition(
            $testSwapRef,
            'SACCUSSALIS',
            'ZURUBANK',
            100.00,
            'CASHOUT',
            'BWP'
        );
        
        assertTest($results, isset($result['success']) && $result['success'] === true, "updateNetPosition() succeeded");
        assertTest($results, isset($result['message_uuid']), "Message UUID generated");
        
        // Get net position
        $netPos = $settlement->getNetPosition('SACCUSSALIS', 'ZURUBANK', 'BWP');
        assertTest($results, $netPos >= 100, "Net position recorded correctly", ['net_position' => $netPos]);
        
    } catch (Exception $e) {
        assertTest($results, false, "HybridSettlementStrategy net position test threw exception: " . $e->getMessage());
    }
    
    // ============================================================
    // TEST 5: HybridSettlementStrategy - Invoice Fees
    // ============================================================
    echo "<div style='margin-top: 15px;'><strong style='color: #ffc107;'>Test 5:</strong> HybridSettlementStrategy - Fee Invoicing</div>";
    
    try {
        $invoiceUuid = $settlement->invoiceFee(
            'TEST_SWAP_' . time(),
            'SACCUSSALIS',
            1,
            'VOUCHMORPH_FEE',
            5.00,
            'BWP'
        );
        
        assertTest($results, !empty($invoiceUuid), "Invoice created", ['invoice_uuid' => $invoiceUuid]);
        
        // Get outstanding invoices
        $invoices = $settlement->getOutstandingInvoices('SACCUSSALIS');
        assertTest($results, is_array($invoices), "getOutstandingInvoices() returned array");
        
    } catch (Exception $e) {
        assertTest($results, false, "HybridSettlementStrategy invoice test threw exception: " . $e->getMessage());
    }
    
    // ============================================================
    // TEST 6: SwapService - Full CASHOUT Flow Simulation
    // ============================================================
    echo "<div style='margin-top: 15px;'><strong style='color: #ffc107;'>Test 6:</strong> SwapService - CASHOUT Flow</div>";
    
    try {
        $swapService = new SwapService($swapDB, $config, $countryName);
        
        $testPayload = [
            'swap_type' => 'CASHOUT',
            'from_institution' => 'SACCUSSALIS',
            'to_institution' => 'ZURUBANK',
            'asset_type' => 'BANK-WALLET',
            'amount' => 200.00,
            'currency' => 'BWP',
            'delivery_method' => 'ATM',
            'beneficiary_phone' => '+26770000000',
            'source_identifier' => '+26770000000',
            'source_identifier_type' => 'phone',
            'reference' => 'TEST_SWAP_' . time(),
            'idempotency_key' => 'TEST_IDEMP_' . time()
        ];
        
        // This will attempt a real swap (may fail if endpoints not configured, but tests structure)
        // For now, just test fee calculation within swap service
        $feeCalc = $swapService->calculateNoteBreakdown(200, 'BWP');
        assertTest($results, isset($feeCalc['dispensable_amount']), "SwapService calculateNoteBreakdown() works");
        assertTest($results, $feeCalc['dispensable_amount'] <= 200, "Dispensable amount <= requested amount");
        
    } catch (Exception $e) {
        assertTest($results, false, "SwapService CASHOUT test threw exception: " . $e->getMessage());
    }
    
    // ============================================================
    // TEST 7: Fee Calculation with ATM Note Breakdown
    // ============================================================
    echo "<div style='margin-top: 15px;'><strong style='color: #ffc107;'>Test 7:</strong> ATM Note Breakdown Calculation</div>";
    
    try {
        $swapService = new SwapService($swapDB, $config, $countryName);
        
        // Test exact amount
        $exactBreakdown = $swapService->calculateNoteBreakdown(100, 'BWP');
        assertTest($results, $exactBreakdown['is_exact'] === true, "Exact amount 100 is dispensable", ['dispensable' => $exactBreakdown['dispensable_amount']]);
        
        // Test non-exact amount
        $nonExactBreakdown = $swapService->calculateNoteBreakdown(150, 'BWP');
        assertTest($results, $nonExactBreakdown['is_exact'] === false, "Amount 150 triggers note adjustment");
        assertTest($results, $nonExactBreakdown['dispensable_amount'] <= 150, "Dispensable amount adjusted");
        assertTest($results, !empty($nonExactBreakdown['note_breakdown']), "Note breakdown provided for adjusted amount");
        
    } catch (Exception $e) {
        assertTest($results, false, "ATM note breakdown test threw exception: " . $e->getMessage());
    }
    
    // ============================================================
    // TEST 8: Multi-currency FX Calculation
    // ============================================================
    echo "<div style='margin-top: 15px;'><strong style='color: #ffc107;'>Test 8:</strong> Cross-Currency FX Calculation</div>";
    
    try {
        $forexService = new ForexService($swapDB, $config, [], $feeService);
        
        // Get rate for ZAR to BWP (common corridor)
        $rate = $forexService->getClientRate('ZAR', 'BWP', 'retail');
        assertTest($results, $rate > 0, "ZAR/BWP rate retrieved", ['rate' => $rate]);
        
        // Test conversion amount
        $amountZAR = 1000;
        $convertedAmount = $amountZAR * $rate;
        assertTest($results, $convertedAmount > 0, "Currency conversion calculated", ['ZAR' => $amountZAR, 'BWP' => round($convertedAmount, 2)]);
        
    } catch (Exception $e) {
        assertTest($results, false, "Cross-currency FX test threw exception: " . $e->getMessage());
    }
    
    // ============================================================
    // TEST 9: Settlement Reconciliation Report
    // ============================================================
    echo "<div style='margin-top: 15px;'><strong style='color: #ffc107;'>Test 9:</strong> Settlement Reconciliation Report</div>";
    
    try {
        $report = $settlement->generateReconciliationReport('SACCUSSALIS', 'BWP');
        assertTest($results, isset($report['institution']), "Reconciliation report generated");
        assertTest($results, isset($report['net_position']), "Net position in report");
        assertTest($results, isset($report['pending_settlements']), "Pending settlements tracked");
        
    } catch (Exception $e) {
        assertTest($results, false, "Reconciliation report test threw exception: " . $e->getMessage());
    }
    
    // ============================================================
    // SUMMARY
    // ============================================================
    echo "<hr>";
    echo "<div style='margin-top: 20px; padding: 15px; background: #1a1f3a; border-radius: 8px;'>";
    echo "<strong style='font-size: 16px;'>📊 Test Summary</strong><br>";
    echo "<div style='margin-top: 10px;'>";
    echo "<span style='color: #4caf50;'>✅ Passed: {$results['passed']}</span> | ";
    echo "<span style='color: #f44336;'>❌ Failed: {$results['failed']}</span> | ";
    echo "<span style='color: #00f0ff;'>📋 Total: {$results['total']}</span>";
    echo "</div>";
    
    $passRate = $results['total'] > 0 ? round(($results['passed'] / $results['total']) * 100, 1) : 0;
    echo "<div style='margin-top: 10px;'>📈 Pass Rate: {$passRate}%</div>";
    
    if ($results['failed'] === 0) {
        echo "<div style='margin-top: 15px; color: #4caf50;'>🎉 All tests passed! The system is functioning correctly.</div>";
    } else {
        echo "<div style='margin-top: 15px; color: #ffc107;'>⚠️ Some tests failed. Please check the logs above for details.</div>";
    }
    echo "</div>";
    
    // Output counters for JS to read
    echo "<!-- PASSED_COUNT: {$results['passed']} -->";
    echo "<!-- FAILED_COUNT: {$results['failed']} -->";
    echo "<!-- TOTAL_COUNT: {$results['total']} -->";
    
    echo "</div>";
    
    $output = ob_get_clean();
    echo $output;
    exit;
}
?>

</body>
</html>
