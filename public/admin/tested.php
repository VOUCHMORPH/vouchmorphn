<?php
/**
 * Test script for calculateFeesWithDetails() - ATM Rounding Logic
 * 
 * Run from command line: php test_fee_calculation.php
 */

// ============================================================
// MOCK CLASSES FOR TESTING
// ============================================================

class MockFeeService
{
    public function calculateFees(string $feeType, float $amount, array $payload): array
    {
        $fee = ($feeType === 'DEPOSIT') ? 6 : 10;
        $netAmountSourceCurrency = $amount - $fee;
        
        // Mock forex - no forex applied
        return [
            'total_fee' => $fee,
            'net_amount_source_currency' => $netAmountSourceCurrency,
            'net_amount_destination_currency' => $netAmountSourceCurrency,
            'forex' => [
                'applied' => false,
                'rate' => 1.0,
                'vouchmorph_profit' => 0
            ],
            'breakdown' => [],
            'distribution' => [],
            'destination_split' => []
        ];
    }
}

class MockSwapService
{
    private array $atmNotes = [
        'BWP' => [200, 100, 50, 20, 10],
        'USD' => [100, 50, 20, 10, 5, 1],
        'ZAR' => [200, 100, 50, 20, 10]
    ];
    
    private array $feeCalculationDetails = [];
    private $feeService;
    
    public function __construct()
    {
        $this->feeService = new MockFeeService();
    }
    
    /**
     * DIRECT COPY OF calculateFeesWithDetails FROM SwapService.php
     * This is exactly what will run in production
     */
    private function calculateFeesWithDetails(string $feeType, float $amount, array $payload): array
    {
        $this->feeCalculationDetails = [];
        
        $sourceCurrency = $payload['currency'] ?? 'BWP';
        $destinationCurrency = $payload['destination_currency'] ?? $sourceCurrency;
        
        $feeResult = $this->feeService->calculateFees($feeType, $amount, $payload);
        
        $totalFee = $feeResult['total_fee'] ?? 0;
        $netAmountSourceCurrency = $feeResult['net_amount_source_currency'] ?? ($amount - $totalFee);
        
        $forexApplied = $feeResult['forex']['applied'] ?? false;
        $exchangeRate = $feeResult['forex']['rate'] ?? 1.0;
        $netAmountDestCurrency = $feeResult['net_amount_destination_currency'] ?? $netAmountSourceCurrency;
        
        // ============================================================
        // BUSINESS RULES:
        // 1. DEPOSIT: Always deliver FULL amount (no ATM rounding)
        // 2. CASHOUT with VOUCHER: Deliver FULL amount (no ATM rounding)
        // 3. CASHOUT with other assets: Apply ATM rounding, remainder stays at source
        // ============================================================
        $isDeposit = ($feeType === 'DEPOSIT');
        $assetType = strtoupper($payload['asset_type'] ?? '');
        $isVoucher = ($assetType === 'VOUCHER');
        
        // DEPOSIT always delivers full amount, OR VOUCHER cashout delivers full amount
        if ($isDeposit || $isVoucher) {
            // FULL delivery - NO ATM rounding
            $dispensableAmount = $netAmountDestCurrency;
            $remainderBalance = 0;
            $multiplier = null;
            $denominations = [];
            
            $deliveryType = $isDeposit ? "DEPOSIT" : "VOUCHER";
            echo "  [FULL DELIVERY] {$deliveryType} - amount: {$dispensableAmount} {$destinationCurrency}\n";
            
        } else {
            // CASHOUT with non-voucher (ACCOUNT, WALLET, CARD, etc.) - apply ATM rounding
            if (!isset($this->atmNotes[$destinationCurrency])) {
                throw new RuntimeException(
                    "No ATM denominations configured for currency: {$destinationCurrency}. " .
                    "Please add '{$destinationCurrency}' to atm_notes.json in the country config."
                );
            }
            
            $denominations = $this->atmNotes[$destinationCurrency];
            $multiplier = $denominations[0] ?? null;
            
            if ($multiplier === null) {
                throw new RuntimeException(
                    "Invalid denominations for currency {$destinationCurrency}: " . 
                    json_encode($denominations)
                );
            }
            
            $dispensableAmount = $multiplier * floor($netAmountDestCurrency / $multiplier);
            $remainderBalance = $netAmountDestCurrency - $dispensableAmount;
            
            if ($dispensableAmount <= 0 && $netAmountDestCurrency > 0) {
                $smallestDenom = min($denominations);
                $dispensableAmount = $smallestDenom * floor($netAmountDestCurrency / $smallestDenom);
                $remainderBalance = $netAmountDestCurrency - $dispensableAmount;
                $multiplier = $smallestDenom;
                echo "  Using smallest denomination {$smallestDenom} for amount {$netAmountDestCurrency} {$destinationCurrency}\n";
            }
            
            echo "  [ATM ROUNDING] {$assetType} - ATM rounding applied\n";
        }
        
        $this->feeCalculationDetails = [
            'fee_type' => $feeType,
            'original_amount' => $amount,
            'original_currency' => $sourceCurrency,
            'total_fee' => $totalFee,
            'total_fee_currency' => $sourceCurrency,
            'net_amount_source_currency' => $netAmountSourceCurrency,
            'forex_applied' => $forexApplied,
            'exchange_rate' => $exchangeRate,
            'net_amount_destination_currency' => $netAmountDestCurrency,
            'destination_currency' => $destinationCurrency,
            'multiplier' => $multiplier,
            'dispensable_amount' => $dispensableAmount,
            'remainder_balance' => $remainderBalance,
            'denominations' => $denominations ?? [],
            'is_full_delivery' => ($isDeposit || $isVoucher),
            'is_voucher' => $isVoucher,
            'is_deposit' => $isDeposit,
            'breakdown' => $feeResult['breakdown'] ?? [],
            'revenue_split' => $feeResult['distribution'] ?? [],
            'destination_split' => $feeResult['destination_split'] ?? [],
            'mathematical_formulas' => [
                'Amount_1' => $amount,
                'F1' => $totalFee,
                'Amount_2' => $netAmountSourceCurrency,
                'Exchange_Rate' => $exchangeRate,
                'Amount_3' => $netAmountDestCurrency,
                'M' => $multiplier,
                'Amount_4' => $dispensableAmount,
                'Remainder_1' => $remainderBalance,
                'is_full_delivery' => ($isDeposit || $isVoucher),
                'is_voucher' => $isVoucher,
                'is_deposit' => $isDeposit
            ]
        ];
        
        $multiplierDisplay = $multiplier ?? 'N/A (FULL DELIVERY)';
        
        echo "\n  Mathematical calculation:\n";
        echo "    Amount_1: {$amount} {$sourceCurrency}\n";
        echo "    F1 (fee): {$totalFee} {$sourceCurrency}\n";
        echo "    Amount_2: {$netAmountSourceCurrency} {$sourceCurrency}\n";
        if ($forexApplied) {
            echo "    Exchange Rate: {$exchangeRate}\n";
            echo "    Amount_3: {$netAmountDestCurrency} {$destinationCurrency}\n";
        }
        echo "    M (multiplier): {$multiplierDisplay}\n";
        echo "    Amount_4 (dispensable): {$dispensableAmount}\n";
        echo "    Remainder_1: {$remainderBalance}\n";
        
        return [
            'total_fee' => $totalFee,
            'total_fee_currency' => $sourceCurrency,
            'net_amount' => $netAmountDestCurrency,
            'net_amount_source_currency' => $netAmountSourceCurrency,
            'net_amount_destination_currency' => $netAmountDestCurrency,
            'dispensable_amount' => $dispensableAmount,
            'remainder_balance' => $remainderBalance,
            'exchange_rate' => $exchangeRate,
            'forex_applied' => $forexApplied,
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'multiplier' => $multiplier,
            'denominations' => $denominations ?? [],
            'is_full_delivery' => ($isDeposit || $isVoucher),
            'is_voucher' => $isVoucher,
            'is_deposit' => $isDeposit,
            'components' => $this->feeCalculationDetails
        ];
    }
    
    public function runTest(string $name, string $feeType, float $amount, array $payload, float $expectedDispensable, float $expectedRemainder): bool
    {
        echo "\n========================================\n";
        echo "TEST: {$name}\n";
        echo "========================================\n";
        echo "Input: feeType={$feeType}, amount={$amount}, asset_type={$payload['asset_type']}\n";
        
        $result = $this->calculateFeesWithDetails($feeType, $amount, $payload);
        
        echo "\nResult:\n";
        echo "  dispensable_amount: " . $result['dispensable_amount'] . "\n";
        echo "  remainder_balance: " . $result['remainder_balance'] . "\n";
        echo "  is_full_delivery: " . ($result['is_full_delivery'] ? 'true' : 'false') . "\n";
        echo "  is_voucher: " . ($result['is_voucher'] ? 'true' : 'false') . "\n";
        echo "  is_deposit: " . ($result['is_deposit'] ? 'true' : 'false') . "\n";
        
        $passed = (
            $result['dispensable_amount'] == $expectedDispensable &&
            $result['remainder_balance'] == $expectedRemainder
        );
        
        echo "\nExpected: dispensable={$expectedDispensable}, remainder={$expectedRemainder}\n";
        echo "Status: " . ($passed ? "✅ PASSED" : "❌ FAILED") . "\n";
        
        return $passed;
    }
}

// ============================================================
// RUN TESTS
// ============================================================

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     ATM ROUNDING LOGIC TEST - calculateFeesWithDetails    ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";

$service = new MockSwapService();
$passed = 0;
$total = 0;

// ============================================================
// TEST 1: VOUCHER + CASHOUT (NO remainder)
// ============================================================
$total++;
if ($service->runTest(
    'VOUCHER + CASHOUT (Full delivery, no rounding)',
    'CASHOUT',
    1000,
    ['asset_type' => 'VOUCHER', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    890,
    0
)) {
    $passed++;
}

// ============================================================
// TEST 2: VOUCHER + DEPOSIT (NO remainder)
// ============================================================
$total++;
if ($service->runTest(
    'VOUCHER + DEPOSIT (Full delivery, no rounding)',
    'DEPOSIT',
    1000,
    ['asset_type' => 'VOUCHER', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    894,
    0
)) {
    $passed++;
}

// ============================================================
// TEST 3: ACCOUNT + CASHOUT (ATM rounding applied)
// ============================================================
$total++;
if ($service->runTest(
    'ACCOUNT + CASHOUT (ATM rounding - 200 multiplier)',
    'CASHOUT',
    915,
    ['asset_type' => 'ACCOUNT', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    800,
    105
)) {
    $passed++;
}

// ============================================================
// TEST 4: ACCOUNT + DEPOSIT (NO remainder)
// ============================================================
$total++;
if ($service->runTest(
    'ACCOUNT + DEPOSIT (Full delivery, no rounding)',
    'DEPOSIT',
    915,
    ['asset_type' => 'ACCOUNT', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    909,
    0
)) {
    $passed++;
}

// ============================================================
// TEST 5: WALLET + CASHOUT (ATM rounding applied)
// ============================================================
$total++;
if ($service->runTest(
    'WALLET + CASHOUT (ATM rounding - 200 multiplier)',
    'CASHOUT',
    850,
    ['asset_type' => 'WALLET', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    800,
    40
)) {
    $passed++;
}

// ============================================================
// TEST 6: CARD + CASHOUT (ATM rounding applied with different amount)
// ============================================================
$total++;
if ($service->runTest(
    'CARD + CASHOUT (ATM rounding - 200 multiplier)',
    'CASHOUT',
    650,
    ['asset_type' => 'CARD', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    600,
    40
)) {
    $passed++;
}

// ============================================================
// TEST 7: VOUCHER + CASHOUT with different amount
// ============================================================
$total++;
if ($service->runTest(
    'VOUCHER + CASHOUT (Full delivery, different amount)',
    'CASHOUT',
    715,
    ['asset_type' => 'VOUCHER', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    705,
    0
)) {
    $passed++;
}

// ============================================================
// TEST 8: VOUCHER + DEPOSIT with different amount
// ============================================================
$total++;
if ($service->runTest(
    'VOUCHER + DEPOSIT (Full delivery, different amount)',
    'DEPOSIT',
    715,
    ['asset_type' => 'VOUCHER', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    709,
    0
)) {
    $passed++;
}

// ============================================================
// TEST 9: ACCOUNT + CASHOUT with small amount
// ============================================================
$total++;
if ($service->runTest(
    'ACCOUNT + CASHOUT (Small amount)',
    'CASHOUT',
    205,
    ['asset_type' => 'ACCOUNT', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    0,
    195
)) {
    $passed++;
}

// ============================================================
// TEST 10: ACCOUNT + CASHOUT with exact note match
// ============================================================
$total++;
if ($service->runTest(
    'ACCOUNT + CASHOUT (Exact note match - 200)',
    'CASHOUT',
    500,
    ['asset_type' => 'ACCOUNT', 'currency' => 'BWP', 'destination_currency' => 'BWP'],
    400,
    90
)) {
    $passed++;
}

// ============================================================
// SUMMARY
// ============================================================
// ============================================================
// EXPECTED BEHAVIOR SUMMARY
// ============================================================
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║              EXPECTED BEHAVIOR SUMMARY                    ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "┌────────────┬────────────┬──────────┬─────────────┬──────────────┐\n";
echo "│ Source Type│ Swap Type  │ Amount   │ Dispensed   │ Remainder    │\n";
echo "├────────────┼────────────┼──────────┼─────────────┼──────────────┤\n";
echo "│ VOUCHER    │ CASHOUT    │ 1,000    │ 890         │ 0            │\n";
echo "│ VOUCHER    │ DEPOSIT    │ 1,000    │ 894         │ 0            │\n";
echo "│ ACCOUNT    │ CASHOUT    │ 915      │ 800         │ 105          │\n";
echo "│ ACCOUNT    │ DEPOSIT    │ 915      │ 909         │ 0            │\n";
echo "│ WALLET     │ CASHOUT    │ 850      │ 800         │ 40           │\n";
echo "│ CARD       │ CASHOUT    │ 650      │ 600         │ 40           │\n";
echo "│ VOUCHER    │ CASHOUT    │ 715      │ 705         │ 0            │\n";
echo "│ VOUCHER    │ DEPOSIT    │ 715      │ 709         │ 0            │\n";
echo "│ ACCOUNT    │ CASHOUT    │ 205      │ 0           │ 195          │\n";
echo "│ ACCOUNT    │ CASHOUT    │ 500      │ 400         │ 90           │\n";
echo "└────────────┴────────────┴──────────┴─────────────┴──────────────┘\n";
echo "\n";
echo "KEY:\n";
echo "  ✅ VOUCHER = FULL delivery (NO ATM rounding, NO remainder)\n";
echo "  ✅ DEPOSIT = FULL delivery (NO ATM rounding, NO remainder)\n";
echo "  ❌ ACCOUNT/WALLET/CARD + CASHOUT = ATM rounding applied\n";
echo "\n";
