<?php
/**
 * PAYLOAD STRUCTURE METADATA EXTRACTOR
 * Extracts expected payload fields from each endpoint PHP file
 * Compares with VouchMorph's actual payload structure
 */

// ============================================================
// CONFIGURATION
// ============================================================
$baseDir = __DIR__; // Current directory
$banks = [
    'zurubank' => [
        'path' => 'zurubank/Backend/api/v1/',
        'files' => [
            'verify_asset.php' => 'ZURUBANK verify_asset.php',
            'hold.php' => 'ZURUBANK hold.php',
            'atm/generate_code.php' => 'ZURUBANK generate_code.php',
            'settlement/notify_debit.php' => 'ZURUBANK notify_debit.php'
        ]
    ],
    'saccussalis' => [
        'path' => 'saccussalis/backend/api/v1/',
        'files' => [
            'verify_asset.php' => 'SACCUSSALIS verify_asset.php',
            'hold.php' => 'SACCUSSALIS hold.php',
            'transaction/credit_funds.php' => 'SACCUSSALIS credit_funds.php',
            'verify_account.php' => 'SACCUSSALIS verify_account.php'
        ]
    ],
    'cazacom' => [
        'path' => 'cazacom/backend/api/v1/mno/',
        'files' => [
            'verify_wallet.php' => 'CAZACOM verify_wallet.php'
        ]
    ]
];

// ============================================================
// VOUCHMORPH PAYLOAD STRUCTURES
// ============================================================
$vouchmorphPayloads = [
    'DEPOSIT' => [
        'swap_type' => 'string (DEPOSIT)',
        'from_institution' => 'string (ZURUBANK)',
        'source_institution' => 'string (ZURUBANK)',
        'asset_type' => 'string (VOUCHER, ACCOUNT, WALLET)',
        'source_identifier' => 'string',
        'amount' => 'number',
        'currency' => 'string (BWP)',
        'to_institution' => 'string (SACCUSSALIS)',
        'destination_institution' => 'string (SACCUSSALIS)',
        'destination_asset_type' => 'string (ACCOUNT, WALLET)',
        'destination_identifier' => 'string',
        'destination_identifier_type' => 'string (account, phone)',
        'reference' => 'string',
        'country_code' => 'string (Botswana)',
        'wallet_pin' => 'string (optional)',
        'pin' => 'string (optional)',
        'voucher_number' => 'string (optional)',
        'voucher_pin' => 'string (optional)'
    ],
    'CASHOUT' => [
        'swap_type' => 'string (CASHOUT)',
        'from_institution' => 'string (ZURUBANK)',
        'source_institution' => 'string (ZURUBANK)',
        'asset_type' => 'string (VOUCHER, ACCOUNT)',
        'source_identifier' => 'string',
        'amount' => 'number',
        'currency' => 'string (BWP)',
        'to_institution' => 'string (ATM)',
        'destination_institution' => 'string (ATM)',
        'delivery_method' => 'string (ATM, AGENT)',
        'beneficiary_phone' => 'string',
        'reference' => 'string',
        'country_code' => 'string (Botswana)'
    ],
    'IDENTITY' => [
        'swap_type' => 'string (IDENTITY)',
        'from_institution' => 'string (ZURUBANK)',
        'source_institution' => 'string (ZURUBANK)',
        'asset_type' => 'string (VOUCHER, ACCOUNT)',
        'source_identifier' => 'string',
        'amount' => 'number',
        'currency' => 'string (BWP)',
        'identity_type' => 'string (national_id, phone, email)',
        'identity_value' => 'string',
        'reference' => 'string',
        'country_code' => 'string (Botswana)'
    ],
    'MULTI_SOURCE' => [
        'swap_type' => 'string (MULTI_SOURCE)',
        'sources' => 'array of source objects',
        'to_institution' => 'string',
        'destination_institution' => 'string',
        'destination_asset_type' => 'string',
        'destination_identifier' => 'string',
        'destination_identifier_type' => 'string',
        'amount' => 'number',
        'currency' => 'string',
        'reference' => 'string',
        'country_code' => 'string (Botswana)'
    ],
    'MULTI_DESTINATION' => [
        'swap_type' => 'string (MULTI_DESTINATION)',
        'from_institution' => 'string',
        'source_institution' => 'string',
        'asset_type' => 'string',
        'source_identifier' => 'string',
        'currency' => 'string',
        'destinations' => 'array of destination objects',
        'reference' => 'string',
        'country_code' => 'string (Botswana)'
    ]
];

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Extract payload fields from a PHP file
 */
function extractPayloadFields($filePath) {
    if (!file_exists($filePath)) {
        return ['error' => 'File not found: ' . $filePath];
    }
    
    $content = file_get_contents($filePath);
    $fields = [
        'required' => [],
        'optional' => [],
        'post' => [],
        'get' => []
    ];
    
    // Extract all $input['field'] patterns
    preg_match_all('/\$input\[\''([^\']+)\'\]/', $content, $matches);
    $inputFields = array_unique($matches[1]);
    
    foreach ($inputFields as $field) {
        // Check if field is required (has empty() or !isset() check)
        if (preg_match('/empty\(\$input\[\'' . preg_quote($field, '/') . '\'\]\)|!isset\(\$input\[\'' . preg_quote($field, '/') . '\'\]\)/', $content)) {
            $fields['required'][] = $field;
        } else {
            $fields['optional'][] = $field;
        }
    }
    
    // Extract $_POST fields
    preg_match_all('/\$_POST\[\''([^\']+)\'\]/', $content, $matches);
    $fields['post'] = array_unique($matches[1]);
    
    // Extract $_GET fields
    preg_match_all('/\$_GET\[\''([^\']+)\'\]/', $content, $matches);
    $fields['get'] = array_unique($matches[1]);
    
    return $fields;
}

/**
 * Check if VouchMorph payload matches endpoint expectations
 */
function comparePayload($endpointFields, $vouchmorphFields) {
    $results = [
        'matched' => [],
        'missing' => [],
        'extra' => []
    ];
    
    $endpointRequired = array_merge($endpointFields['required'], $endpointFields['post']);
    $endpointAll = array_merge($endpointRequired, $endpointFields['optional']);
    $vouchmorphKeys = array_keys($vouchmorphFields);
    
    foreach ($endpointRequired as $field) {
        if (in_array($field, $vouchmorphKeys)) {
            $results['matched'][] = $field;
        } else {
            $results['missing'][] = $field;
        }
    }
    
    foreach ($vouchmorphKeys as $field) {
        if (!in_array($field, $endpointAll) && $field !== 'swap_type') {
            $results['extra'][] = $field;
        }
    }
    
    return $results;
}

// ============================================================
// RUN EXTRACTION
// ============================================================

echo "==========================================\n";
echo "PAYLOAD STRUCTURE METADATA EXTRACTOR\n";
echo "==========================================\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================\n\n";

$allResults = [];

foreach ($banks as $bankName => $bank) {
    echo "==========================================\n";
    echo strtoupper($bankName) . " ENDPOINTS\n";
    echo "==========================================\n\n";
    
    foreach ($bank['files'] as $file => $label) {
        $fullPath = $bank['path'] . $file;
        $absolutePath = $baseDir . '/' . $fullPath;
        
        echo "📁 " . $label . "\n";
        echo "📄 File: " . $fullPath . "\n";
        echo "\n";
        
        $fields = extractPayloadFields($absolutePath);
        
        if (isset($fields['error'])) {
            echo "❌ " . $fields['error'] . "\n";
            echo "\n";
            continue;
        }
        
        echo "Expected payload fields:\n";
        echo "----------------------------------------\n";
        
        if (!empty($fields['required'])) {
            echo "🔴 REQUIRED:\n";
            foreach ($fields['required'] as $field) {
                echo "   - " . $field . "\n";
            }
            echo "\n";
        }
        
        if (!empty($fields['optional'])) {
            echo "🟢 OPTIONAL:\n";
            foreach ($fields['optional'] as $field) {
                echo "   - " . $field . "\n";
            }
            echo "\n";
        }
        
        if (!empty($fields['post'])) {
            echo "🟡 FROM \$_POST:\n";
            foreach ($fields['post'] as $field) {
                echo "   - " . $field . "\n";
            }
            echo "\n";
        }
        
        if (!empty($fields['get'])) {
            echo "🔵 FROM \$_GET:\n";
            foreach ($fields['get'] as $field) {
                echo "   - " . $field . "\n";
            }
            echo "\n";
        }
        
        $allResults[$label] = $fields;
        echo "----------------------------------------\n\n";
    }
}

// ============================================================
// COMPARISON WITH VOUCHMORPH PAYLOADS
// ============================================================

echo "==========================================\n";
echo "VOUCHMORPH PAYLOAD STRUCTURES\n";
echo "==========================================\n\n";

foreach ($vouchmorphPayloads as $type => $fields) {
    echo "📦 " . $type . " payload:\n";
    echo "----------------------------------------\n";
    foreach ($fields as $field => $type) {
        echo "   " . $field . " => " . $type . "\n";
    }
    echo "\n";
}

// ============================================================
// COMPARISON TABLE
// ============================================================

echo "==========================================\n";
echo "PAYLOAD COMPARISON TABLE\n";
echo "==========================================\n\n";

echo "| Endpoint | Required Fields | Optional Fields | Match |\n";
echo "|----------|----------------|-----------------|--------|\n";

$comparisons = [];

foreach ($allResults as $endpoint => $fields) {
    // Determine which VouchMorph payload type to compare with
    $matchType = 'DEPOSIT';
    if (strpos($endpoint, 'cashout') !== false || strpos($endpoint, 'CASHOUT') !== false) {
        $matchType = 'CASHOUT';
    } elseif (strpos($endpoint, 'identity') !== false || strpos($endpoint, 'IDENTITY') !== false) {
        $matchType = 'IDENTITY';
    }
    
    $vouchFields = $vouchmorphPayloads[$matchType] ?? [];
    $comparison = comparePayload($fields, $vouchFields);
    $comparisons[$endpoint] = $comparison;
    
    $requiredStr = implode(', ', array_slice($fields['required'], 0, 5));
    if (count($fields['required']) > 5) {
        $requiredStr .= ', ...';
    }
    
    $optionalStr = implode(', ', array_slice($fields['optional'], 0, 5));
    if (count($fields['optional']) > 5) {
        $optionalStr .= ', ...';
    }
    
    $matchStatus = '✅';
    if (!empty($comparison['missing'])) {
        $matchStatus = '❌';
    } elseif (!empty($comparison['extra'])) {
        $matchStatus = '⚠️';
    }
    
    echo "| " . $endpoint . " | " . ($requiredStr ?: 'none') . " | " . ($optionalStr ?: 'none') . " | " . $matchStatus . " |\n";
}

echo "\n";

// ============================================================
// ISSUES FOUND
// ============================================================

echo "==========================================\n";
echo "ISSUES FOUND\n";
echo "==========================================\n\n";

$issues = [];

foreach ($comparisons as $endpoint => $comparison) {
    if (!empty($comparison['missing'])) {
        $issues[] = "❌ " . $endpoint . " expects these fields that VouchMorph doesn't send: " . implode(', ', $comparison['missing']);
    }
    if (!empty($comparison['extra'])) {
        $issues[] = "⚠️ " . $endpoint . " doesn't expect these fields that VouchMorph sends: " . implode(', ', $comparison['extra']);
    }
}

if (empty($issues)) {
    echo "✅ All payloads match!\n";
} else {
    foreach ($issues as $issue) {
        echo $issue . "\n";
    }
}

echo "\n";

// ============================================================
// FILES THAT NEED FIXING
// ============================================================

echo "==========================================\n";
echo "FILES THAT NEED FIXING\n";
echo "==========================================\n\n";

$fixes = [
    'CAZACOM verify_wallet.php' => 'Add \'source_identifier\' to phone extraction: $phone = $input[\'source_identifier\'] ?? ...',
    'SACCUSSALIS hold.php' => 'Add require_once __DIR__ . \'/../../helpers/crypto.php\';',
    'ZURUBANK generate_code.php' => 'Fix hold_reference extraction from source_hold array',
    'ZURUBANK verify_asset.php' => 'Remove \'is_frozen\' from SELECT (already done)'
];

foreach ($fixes as $file => $fix) {
    echo "📁 " . $file . "\n";
    echo "   🔧 " . $fix . "\n\n";
}

echo "==========================================\n";
echo "Test completed: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================\n";
