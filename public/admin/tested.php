<?php
// ============================================================
// BULK SWAP TEST - Run this on VouchMorph server
// Tests all swap types and reports which files need fixing
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuration
$config = [
    'base_url' => 'https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php',
    'api_key' => 'vouchmorph_live_1aB2cD3eF4gH5iJ6',
    'test_data' => [
        'voucher_number' => '625448346',
        'voucher_pin' => '005442',
        'account_number' => '10000001',
        'phone' => '+26770000037',
        'identity_value' => '1234567890',
        'ewallet_pin' => '174165',
        'amount' => 100
    ]
];

// ============================================================
// TEST DEFINITIONS - All swap types
// ============================================================
$tests = [
    'VOUCHER_TO_ACCOUNT' => [
        'description' => 'ZURUBANK Voucher → SACCUSSALIS Account',
        'payload' => [
            'swap_type' => 'DEPOSIT',
            'from_institution' => 'ZURUBANK',
            'source_institution' => 'ZURUBANK',
            'asset_type' => 'VOUCHER',
            'source_identifier' => $config['test_data']['voucher_number'],
            'voucher_number' => $config['test_data']['voucher_number'],
            'voucher_pin' => $config['test_data']['voucher_pin'],
            'amount' => $config['test_data']['amount'],
            'currency' => 'BWP',
            'to_institution' => 'SACCUSSALIS',
            'destination_institution' => 'SACCUSSALIS',
            'destination_asset_type' => 'ACCOUNT',
            'destination_identifier' => $config['test_data']['account_number'],
            'destination_identifier_type' => 'account',
            'reference' => 'TEST_VOUCHER_ACCOUNT_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'ZURUBANK' => ['/api/v1/verify_asset.php', '/api/v1/hold.php'],
            'SACCUSSALIS' => ['/api/v1/verify_account.php', '/api/v1/transaction/credit_funds.php'],
            'VOUCHMORPH' => ['src/Domain/Services/SwapService.php']
        ]
    ],
    
    'ACCOUNT_TO_VOUCHER' => [
        'description' => 'SACCUSSALIS Account → ZURUBANK Voucher',
        'payload' => [
            'swap_type' => 'DEPOSIT',
            'from_institution' => 'SACCUSSALIS',
            'source_institution' => 'SACCUSSALIS',
            'asset_type' => 'ACCOUNT',
            'source_identifier' => $config['test_data']['account_number'],
            'amount' => 50,
            'currency' => 'BWP',
            'to_institution' => 'ZURUBANK',
            'destination_institution' => 'ZURUBANK',
            'destination_asset_type' => 'VOUCHER',
            'destination_identifier' => $config['test_data']['phone'],
            'destination_identifier_type' => 'phone',
            'reference' => 'TEST_ACCOUNT_VOUCHER_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'SACCUSSALIS' => ['/api/v1/verify_asset.php', '/api/v1/hold.php'],
            'ZURUBANK' => ['/api/v1/verify_account.php', '/api/v1/transaction/credit_funds.php'],
            'VOUCHMORPH' => ['src/Domain/Services/SwapService.php']
        ]
    ],
    
    'ACCOUNT_TO_ACCOUNT' => [
        'description' => 'SACCUSSALIS Account → ZURUBANK Account',
        'payload' => [
            'swap_type' => 'DEPOSIT',
            'from_institution' => 'SACCUSSALIS',
            'source_institution' => 'SACCUSSALIS',
            'asset_type' => 'ACCOUNT',
            'source_identifier' => $config['test_data']['account_number'],
            'amount' => 75,
            'currency' => 'BWP',
            'to_institution' => 'ZURUBANK',
            'destination_institution' => 'ZURUBANK',
            'destination_asset_type' => 'ACCOUNT',
            'destination_identifier' => $config['test_data']['account_number'],
            'destination_identifier_type' => 'account',
            'reference' => 'TEST_ACCOUNT_ACCOUNT_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'SACCUSSALIS' => ['/api/v1/verify_asset.php', '/api/v1/hold.php'],
            'ZURUBANK' => ['/api/v1/verify_account.php', '/api/v1/transaction/credit_funds.php']
        ]
    ],
    
    'VOUCHER_TO_VOUCHER' => [
        'description' => 'ZURUBANK Voucher → ZURUBANK Voucher',
        'payload' => [
            'swap_type' => 'DEPOSIT',
            'from_institution' => 'ZURUBANK',
            'source_institution' => 'ZURUBANK',
            'asset_type' => 'VOUCHER',
            'source_identifier' => $config['test_data']['voucher_number'],
            'voucher_number' => $config['test_data']['voucher_number'],
            'voucher_pin' => $config['test_data']['voucher_pin'],
            'amount' => $config['test_data']['amount'],
            'currency' => 'BWP',
            'to_institution' => 'ZURUBANK',
            'destination_institution' => 'ZURUBANK',
            'destination_asset_type' => 'VOUCHER',
            'destination_identifier' => $config['test_data']['phone'],
            'destination_identifier_type' => 'phone',
            'reference' => 'TEST_VOUCHER_VOUCHER_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'ZURUBANK' => ['/api/v1/verify_asset.php', '/api/v1/hold.php', '/api/v1/voucher/issue.php']
        ]
    ],
    
    'IDENTITY_SWAP' => [
        'description' => 'Voucher → Identity (National ID)',
        'payload' => [
            'swap_type' => 'IDENTITY',
            'from_institution' => 'ZURUBANK',
            'source_institution' => 'ZURUBANK',
            'asset_type' => 'VOUCHER',
            'source_identifier' => $config['test_data']['voucher_number'],
            'voucher_number' => $config['test_data']['voucher_number'],
            'voucher_pin' => $config['test_data']['voucher_pin'],
            'amount' => $config['test_data']['amount'],
            'currency' => 'BWP',
            'identity_type' => 'national_id',
            'identity_value' => $config['test_data']['identity_value'],
            'reference' => 'TEST_IDENTITY_SWAP_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'ZURUBANK' => ['/api/v1/verify_asset.php', '/api/v1/hold.php'],
            'VOUCHMORPH' => ['src/Domain/Services/SwapService.php', 'src/Domain/Services/IdentityResolver.php']
        ]
    ],
    
    'CASHOUT_VOUCHER_TO_ATM' => [
        'description' => 'Voucher → ATM Cashout Code',
        'payload' => [
            'swap_type' => 'CASHOUT',
            'from_institution' => 'ZURUBANK',
            'source_institution' => 'ZURUBANK',
            'asset_type' => 'VOUCHER',
            'source_identifier' => $config['test_data']['voucher_number'],
            'voucher_number' => $config['test_data']['voucher_number'],
            'voucher_pin' => $config['test_data']['voucher_pin'],
            'amount' => $config['test_data']['amount'],
            'currency' => 'BWP',
            'to_institution' => 'ATM',
            'destination_institution' => 'ATM',
            'delivery_method' => 'ATM',
            'beneficiary_phone' => $config['test_data']['phone'],
            'reference' => 'TEST_CASHOUT_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'ZURUBANK' => ['/api/v1/verify_asset.php', '/api/v1/hold.php', '/api/v1/cashout/generate.php']
        ]
    ],
    
    'MULTI_SOURCE' => [
        'description' => 'Multi-Source (2 sources → 1 destination)',
        'payload' => [
            'swap_type' => 'MULTI_SOURCE',
            'sources' => [
                [
                    'institution' => 'ZURUBANK',
                    'asset_type' => 'VOUCHER',
                    'identifier' => $config['test_data']['voucher_number'],
                    'voucher_number' => $config['test_data']['voucher_number'],
                    'voucher_pin' => $config['test_data']['voucher_pin'],
                    'amount' => 50,
                    'asset_fields' => [
                        'voucher_number' => $config['test_data']['voucher_number'],
                        'voucher_pin' => $config['test_data']['voucher_pin']
                    ]
                ],
                [
                    'institution' => 'SACCUSSALIS',
                    'asset_type' => 'ACCOUNT',
                    'identifier' => $config['test_data']['account_number'],
                    'amount' => 50,
                    'asset_fields' => [
                        'account_number' => $config['test_data']['account_number']
                    ]
                ]
            ],
            'to_institution' => 'ZURUBANK',
            'destination_institution' => 'ZURUBANK',
            'destination_asset_type' => 'ACCOUNT',
            'destination_identifier' => $config['test_data']['account_number'],
            'destination_identifier_type' => 'account',
            'amount' => 100,
            'currency' => 'BWP',
            'reference' => 'TEST_MULTI_SOURCE_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'ZURUBANK' => ['/api/v1/verify_asset.php', '/api/v1/hold.php'],
            'SACCUSSALIS' => ['/api/v1/verify_asset.php', '/api/v1/hold.php'],
            'VOUCHMORPH' => ['src/Domain/Services/MultiSource/MultiSourceSwapOrchestrator.php']
        ]
    ],
    
    'MULTI_DESTINATION' => [
        'description' => 'Multi-Destination (1 source → 2 destinations)',
        'payload' => [
            'swap_type' => 'MULTI_DESTINATION',
            'from_institution' => 'ZURUBANK',
            'source_institution' => 'ZURUBANK',
            'asset_type' => 'VOUCHER',
            'source_identifier' => $config['test_data']['voucher_number'],
            'voucher_number' => $config['test_data']['voucher_number'],
            'voucher_pin' => $config['test_data']['voucher_pin'],
            'currency' => 'BWP',
            'destinations' => [
                [
                    'to_institution' => 'SACCUSSALIS',
                    'destination_institution' => 'SACCUSSALIS',
                    'destination_asset_type' => 'ACCOUNT',
                    'destination_identifier' => $config['test_data']['account_number'],
                    'destination_identifier_type' => 'account',
                    'amount' => 50
                ],
                [
                    'to_institution' => 'ZURUBANK',
                    'destination_institution' => 'ZURUBANK',
                    'destination_asset_type' => 'ACCOUNT',
                    'destination_identifier' => $config['test_data']['account_number'],
                    'destination_identifier_type' => 'account',
                    'amount' => 50
                ]
            ],
            'reference' => 'TEST_MULTI_DEST_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'ZURUBANK' => ['/api/v1/verify_asset.php', '/api/v1/hold.php'],
            'SACCUSSALIS' => ['/api/v1/verify_account.php', '/api/v1/transaction/credit_funds.php'],
            'VOUCHMORPH' => ['src/Domain/Services/SwapService.php']
        ]
    ],
    
    'EWALLET_PIN_TO_ACCOUNT' => [
        'description' => 'E-Wallet PIN → Account',
        'payload' => [
            'swap_type' => 'DEPOSIT',
            'from_institution' => 'SACCUSSALIS',
            'source_institution' => 'SACCUSSALIS',
            'asset_type' => 'EWALLET_PIN',
            'source_identifier' => $config['test_data']['ewallet_pin'],
            'pin' => $config['test_data']['ewallet_pin'],
            'amount' => 80,
            'currency' => 'BWP',
            'to_institution' => 'ZURUBANK',
            'destination_institution' => 'ZURUBANK',
            'destination_asset_type' => 'ACCOUNT',
            'destination_identifier' => $config['test_data']['account_number'],
            'destination_identifier_type' => 'account',
            'reference' => 'TEST_EWALLET_ACCOUNT_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'SACCUSSALIS' => ['/api/v1/verify_asset.php', '/api/v1/hold.php'],
            'ZURUBANK' => ['/api/v1/verify_account.php', '/api/v1/transaction/credit_funds.php']
        ]
    ],
    
    'ACCOUNT_TO_CASHOUT' => [
        'description' => 'Account → ATM Cashout',
        'payload' => [
            'swap_type' => 'CASHOUT',
            'from_institution' => 'SACCUSSALIS',
            'source_institution' => 'SACCUSSALIS',
            'asset_type' => 'ACCOUNT',
            'source_identifier' => $config['test_data']['account_number'],
            'amount' => 50,
            'currency' => 'BWP',
            'to_institution' => 'ATM',
            'destination_institution' => 'ATM',
            'delivery_method' => 'ATM',
            'beneficiary_phone' => $config['test_data']['phone'],
            'reference' => 'TEST_ACCOUNT_CASHOUT_' . time(),
            'country_code' => 'Botswana'
        ],
        'expected_success' => true,
        'files_to_check' => [
            'SACCUSSALIS' => ['/api/v1/verify_asset.php', '/api/v1/hold.php', '/api/v1/cashout/generate.php']
        ]
    ]
];

// ============================================================
// RUN TESTS
// ============================================================

$results = [];
$passCount = 0;
$failCount = 0;

echo "==========================================\n";
echo "VOUCHMORPH BULK SWAP TEST\n";
echo "==========================================\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================\n\n";

foreach ($tests as $testName => $test) {
    echo "Testing: " . $testName . "\n";
    echo "  Description: " . $test['description'] . "\n";
    echo "  Expected: " . ($test['expected_success'] ? 'SUCCESS' : 'FAILURE') . "\n";
    
    $payload = $test['payload'];
    $payloadJson = json_encode($payload);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config['base_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $config['api_key']
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $responseData = json_decode($response, true);
    
    $status = 'UNKNOWN';
    $error = '';
    $details = [];
    
    if ($curlError) {
        $status = 'CURL_ERROR';
        $error = $curlError;
    } elseif ($httpCode >= 200 && $httpCode < 300) {
        if ($responseData && isset($responseData['success'])) {
            if ($responseData['success'] === true) {
                $status = 'PASSED';
                $passCount++;
            } else {
                $status = 'FAILED';
                $failCount++;
                $error = $responseData['error'] ?? 'Unknown error';
                $details = $responseData['debug'] ?? [];
            }
        } else {
            $status = 'FAILED';
            $failCount++;
            $error = 'Invalid response format';
            $details = ['raw_response' => substr($response, 0, 500)];
        }
    } else {
        $status = 'HTTP_ERROR';
        $failCount++;
        $error = 'HTTP ' . $httpCode;
        $details = ['response' => substr($response, 0, 500)];
    }
    
    echo "  Result: " . $status . "\n";
    if ($error) {
        echo "  Error: " . $error . "\n";
    }
    
    $results[$testName] = [
        'status' => $status,
        'error' => $error,
        'details' => $details,
        'files_to_check' => $test['files_to_check'] ?? [],
        'response' => $responseData
    ];
    
    echo "  ---\n\n";
}

// ============================================================
// GENERATE REPORT
// ============================================================

echo "==========================================\n";
echo "TEST SUMMARY\n";
echo "==========================================\n";
echo "Total Tests: " . count($tests) . "\n";
echo "Passed: " . $passCount . "\n";
echo "Failed: " . $failCount . "\n";
echo "Pass Rate: " . round(($passCount / count($tests)) * 100, 2) . "%\n";
echo "==========================================\n\n";

// ============================================================
// FILES THAT NEED FIXING
// ============================================================

echo "==========================================\n";
echo "FILES THAT NEED FIXING\n";
echo "==========================================\n\n";

$filesToFix = [];
foreach ($results as $testName => $result) {
    if ($result['status'] !== 'PASSED' && !empty($result['files_to_check'])) {
        foreach ($result['files_to_check'] as $institution => $files) {
            foreach ($files as $file) {
                $key = $institution . ':' . $file;
                if (!isset($filesToFix[$key])) {
                    $filesToFix[$key] = [
                        'institution' => $institution,
                        'file' => $file,
                        'tests' => []
                    ];
                }
                $filesToFix[$key]['tests'][] = $testName . ' (' . $result['error'] . ')';
            }
        }
    }
}

if (empty($filesToFix)) {
    echo "✅ All tests passed! No files need fixing.\n";
} else {
    foreach ($filesToFix as $key => $info) {
        echo "📁 " . $info['institution'] . ":" . $info['file'] . "\n";
        echo "   Failed tests:\n";
        foreach ($info['tests'] as $test) {
            echo "     - " . $test . "\n";
        }
        echo "\n";
    }
}

// ============================================================
// DETAILED ERROR REPORT BY INSTITUTION
// ============================================================

echo "==========================================\n";
echo "DETAILED ERROR REPORT BY INSTITUTION\n";
echo "==========================================\n\n";

$institutionErrors = [];
foreach ($results as $testName => $result) {
    if ($result['status'] !== 'PASSED' && !empty($result['files_to_check'])) {
        foreach ($result['files_to_check'] as $institution => $files) {
            if (!isset($institutionErrors[$institution])) {
                $institutionErrors[$institution] = [];
            }
            $institutionErrors[$institution][] = [
                'test' => $testName,
                'error' => $result['error'],
                'files' => $files
            ];
        }
    }
}

foreach ($institutionErrors as $institution => $errors) {
    echo "🏦 " . $institution . "\n";
    echo "---\n";
    foreach ($errors as $error) {
        echo "  Test: " . $error['test'] . "\n";
        echo "  Error: " . $error['error'] . "\n";
        echo "  Files: " . implode(', ', $error['files']) . "\n";
        echo "\n";
    }
}

// ============================================================
// RAW JSON OUTPUT FOR LOGGING
// ============================================================

echo "==========================================\n";
echo "RAW JSON OUTPUT (for Railway logs)\n";
echo "==========================================\n\n";

$logOutput = [
    'timestamp' => date('Y-m-d H:i:s'),
    'summary' => [
        'total' => count($tests),
        'passed' => $passCount,
        'failed' => $failCount,
        'pass_rate' => round(($passCount / count($tests)) * 100, 2) . '%'
    ],
    'results' => $results,
    'files_to_fix' => $filesToFix,
    'institution_errors' => $institutionErrors
];

echo json_encode($logOutput, JSON_PRETTY_PRINT) . "\n";

echo "\n==========================================\n";
echo "Test completed: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================\n";
