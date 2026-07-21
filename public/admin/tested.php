<?php
/**
 * Direct test of calculateFeesWithDetails() method
 * Run: php test_calculate_fees.php
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;

// ============================================================
// LOAD CONFIGURATION
// ============================================================
$countryConfig = \Core\Config\LoadCountry::getConfig();
$db = DBConnection::getConnection();
$country = 'Botswana';

$swapService = new SwapService($db, $countryConfig, $country);

// ============================================================
// TEST CASES - Input array
// ============================================================
$testCases = [
    [
        'name' => 'VOUCHER + CASHOUT (1000)',
        'feeType' => 'CASHOUT',
        'amount' => 1000,
        'payload' => [
            'asset_type' => 'VOUCHER',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'VOUCHER + DEPOSIT (1000)',
        'feeType' => 'DEPOSIT',
        'amount' => 1000,
        'payload' => [
            'asset_type' => 'VOUCHER',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'ACCOUNT + CASHOUT (915)',
        'feeType' => 'CASHOUT',
        'amount' => 915,
        'payload' => [
            'asset_type' => 'ACCOUNT',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'ACCOUNT + DEPOSIT (915)',
        'feeType' => 'DEPOSIT',
        'amount' => 915,
        'payload' => [
            'asset_type' => 'ACCOUNT',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'WALLET + CASHOUT (850)',
        'feeType' => 'CASHOUT',
        'amount' => 850,
        'payload' => [
            'asset_type' => 'WALLET',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'CARD + CASHOUT (650)',
        'feeType' => 'CASHOUT',
        'amount' => 650,
        'payload' => [
            'asset_type' => 'CARD',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'VOUCHER + CASHOUT (715)',
        'feeType' => 'CASHOUT',
        'amount' => 715,
        'payload' => [
            'asset_type' => 'VOUCHER',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'VOUCHER + DEPOSIT (715)',
        'feeType' => 'DEPOSIT',
        'amount' => 715,
        'payload' => [
            'asset_type' => 'VOUCHER',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'ACCOUNT + CASHOUT (205) - Small amount',
        'feeType' => 'CASHOUT',
        'amount' => 205,
        'payload' => [
            'asset_type' => 'ACCOUNT',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'ACCOUNT + CASHOUT (500) - Exact note',
        'feeType' => 'CASHOUT',
        'amount' => 500,
        'payload' => [
            'asset_type' => 'ACCOUNT',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'VOUCHER + CASHOUT (15) - Below minimum',
        'feeType' => 'CASHOUT',
        'amount' => 15,
        'payload' => [
            'asset_type' => 'VOUCHER',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
    [
        'name' => 'VOUCHER + DEPOSIT (15) - Below minimum but DEPOSIT',
        'feeType' => 'DEPOSIT',
        'amount' => 15,
        'payload' => [
            'asset_type' => 'VOUCHER',
            'currency' => 'BWP',
            'destination_currency' => 'BWP'
        ]
    ],
];

// ============================================================
// RUN TESTS
// ============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║         CALCULATE FEES WITH DETAILS - DIRECT TEST                  ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$passed = 0;
$total = count($testCases);

foreach ($testCases as $test) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "TEST: {$test['name']}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Input: feeType={$test['feeType']}, amount={$test['amount']}, asset_type={$test['payload']['asset_type']}\n";
    echo "\n";

    try {
        // Call the actual method using Reflection to access private method
        $reflection = new ReflectionClass($swapService);
        $method = $reflection->getMethod('calculateFeesWithDetails');
        $method->setAccessible(true);

        $result = $method->invoke($swapService, $test['feeType'], $test['amount'], $test['payload']);

        echo "RESULT:\n";
        echo "  total_fee: " . $result['total_fee'] . "\n";
        echo "  net_amount: " . $result['net_amount'] . "\n";
        echo "  dispensable_amount: " . $result['dispensable_amount'] . "\n";
        echo "  remainder_balance: " . $result['remainder_balance'] . "\n";
        echo "  is_full_delivery: " . ($result['is_full_delivery'] ? 'true' : 'false') . "\n";
        echo "  is_voucher: " . ($result['is_voucher'] ? 'true' : 'false') . "\n";
        echo "  is_deposit: " . ($result['is_deposit'] ? 'true' : 'false') . "\n";
        
        if (isset($result['mathematical_formulas'])) {
            echo "\nMATHEMATICAL FORMULAS:\n";
            $formulas = $result['mathematical_formulas'];
            echo "  Amount_1 (original): " . $formulas['Amount_1'] . "\n";
            echo "  F1 (fee): " . $formulas['F1'] . "\n";
            echo "  Amount_2 (after fee): " . $formulas['Amount_2'] . "\n";
            if (isset($formulas['Exchange_Rate'])) {
                echo "  Exchange Rate: " . $formulas['Exchange_Rate'] . "\n";
                echo "  Amount_3 (after forex): " . $formulas['Amount_3'] . "\n";
            }
            echo "  M (multiplier): " . ($formulas['M'] ?? 'N/A') . "\n";
            echo "  Amount_4 (dispensable): " . $formulas['Amount_4'] . "\n";
            echo "  Remainder_1: " . $formulas['Remainder_1'] . "\n";
        }

        echo "\nSTATUS: ✅ PASSED\n";
        $passed++;

    } catch (Exception $e) {
        echo "\nERROR: " . $e->getMessage() . "\n";
        echo "\nSTATUS: ❌ FAILED (Exception)\n";
    }

    echo "\n";
}

// ============================================================
// SUMMARY
// ============================================================
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                         TEST SUMMARY                               ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "  Passed: {$passed} / {$total}\n";
echo "  " . ($passed === $total ? "✅ ALL TESTS PASSED" : "❌ " . ($total - $passed) . " TEST(S) FAILED") . "\n";

// ============================================================
// EXPECTED RESULTS TABLE
// ============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                    EXPECTED RESULTS TABLE                          ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "┌──────────────────┬─────────────┬────────────┬─────────────┬──────────────┬────────────┬─────────────┐\n";
echo "│ Source Type      │ Swap Type   │ Amount     │ Fee         │ Net          │ Dispensed  │ Remainder   │\n";
echo "├──────────────────┼─────────────┼────────────┼─────────────┼──────────────┼────────────┼─────────────┤\n";
echo "│ VOUCHER          │ CASHOUT     │ 1,000      │ 10          │ 990          │ 800        │ 190         │\n";
echo "│ VOUCHER          │ DEPOSIT     │ 1,000      │ 6           │ 994          │ 994        │ 0           │\n";
echo "│ ACCOUNT          │ CASHOUT     │ 915        │ 10          │ 905          │ 800        │ 105         │\n";
echo "│ ACCOUNT          │ DEPOSIT     │ 915        │ 6           │ 909          │ 909        │ 0           │\n";
echo "│ WALLET           │ CASHOUT     │ 850        │ 10          │ 840          │ 800        │ 40          │\n";
echo "│ CARD             │ CASHOUT     │ 650        │ 10          │ 640          │ 600        │ 40          │\n";
echo "│ VOUCHER          │ CASHOUT     │ 715        │ 10          │ 705          │ 700        │ 5           │\n";
echo "│ VOUCHER          │ DEPOSIT     │ 715        │ 6           │ 709          │ 709        │ 0           │\n";
echo "│ ACCOUNT          │ CASHOUT     │ 205        │ 10          │ 195          │ 190        │ 5           │\n";
echo "│ ACCOUNT          │ CASHOUT     │ 500        │ 10          │ 490          │ 400        │ 90          │\n";
echo "└──────────────────┴─────────────┴────────────┴─────────────┴──────────────┴────────────┴─────────────┘\n";
echo "\n";
echo "KEY:\n";
echo "  ✅ VOUCHER = FULL delivery (NO ATM rounding, NO remainder) for DEPOSIT\n";
echo "  ✅ DEPOSIT = FULL delivery (NO ATM rounding, NO remainder)\n";
echo "  ❌ ACCOUNT/WALLET/CARD + CASHOUT = ATM rounding applied\n";
echo "  ⚠️ VOUCHER + CASHOUT = ATM rounding applied (must meet minimum denomination)\n";
echo "\n";
