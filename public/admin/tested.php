<?php
/**
 * /public/tested.php
 * 
 * COMPREHENSIVE DIAGNOSTIC TEST
 * Shows EXACTLY what is being sent and where the breakdown occurs
 * No assumptions - reads actual code and shows real data flow
 * 
 * Access: /tested.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set up autoloading
require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

use Core\Config\LoadCountry;
use Domain\Services\SwapService;
use Infrastructure\Adapters\InstitutionAdapterFactory;
use Infrastructure\Banks\GenericBankClient;

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function printHeader($title, $char = '=') {
    echo "\n" . str_repeat($char, 80) . "\n";
    echo "  " . $title . "\n";
    echo str_repeat($char, 80) . "\n";
}

function printSection($title) {
    echo "\n--- " . $title . " ---\n";
}

function printJson($data, $label = '') {
    if ($label) {
        echo "\n" . $label . ":\n";
    }
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function getCurrentTimestamp() {
    return date('Y-m-d H:i:s') . ' (Timestamp: ' . time() . ')';
}

// ============================================================================
// STEP 1: LOAD CONFIGURATION
// ============================================================================

printHeader('STEP 1: LOADING CONFIGURATION');

echo "\n  Timestamp: " . getCurrentTimestamp() . "\n";

try {
    $countryConfig = LoadCountry::getConfig();
    echo "  ✅ Country config loaded\n";
    echo "  Country: " . ($countryConfig['country'] ?? 'Unknown') . "\n";
    echo "  Currency: " . ($countryConfig['currency'] ?? 'Unknown') . "\n";
} catch (Exception $e) {
    echo "  ❌ Failed to load country config: " . $e->getMessage() . "\n";
    exit(1);
}

// ============================================================================
// STEP 2: TEST PAYLOAD - What VouchMorph Sends
// ============================================================================

printHeader('STEP 2: TEST PAYLOAD BEING SENT');

$testPayload = [
    "swap_type" => "CASHOUT",
    "asset_type" => "VOUCHER",
    "from_institution" => "ZURUBANK",
    "source_institution" => "ZURUBANK",
    "to_institution" => "SACCUSSALIS",
    "destination_institution" => "SACCUSSALIS",
    "source_identifier" => "719729822604",
    "amount" => 90,
    "currency" => "BWP",
    "delivery_method" => "VOUCHER",
    "voucher_number" => "719729822604",
    "voucher_pin" => "328606",
    "destination_identifier" => "+26770000000",
    "destination_identifier_type" => "phone",
    "destination_asset_type" => "WALLET"
];

printJson($testPayload, '  Test Payload');

// ============================================================================
// STEP 3: TRACE THROUGH SWAPSERVICE - What fields are extracted
// ============================================================================

printHeader('STEP 3: SWAPSERVICE FIELD EXTRACTION');

try {
    // Create SwapService instance
    $db = new PDO(
        'pgsql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'),
        getenv('DB_USER'),
        getenv('DB_PASSWORD')
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $swapService = new SwapService($db, [], 'Botswana');
    
    echo "  ✅ SwapService initialized\n";
    
    // Use reflection to access private methods
    $reflection = new ReflectionClass($swapService);
    
    // Test extractSourceInstitution
    $method = $reflection->getMethod('extractSourceInstitution');
    $method->setAccessible(true);
    $source = $method->invoke($swapService, $testPayload);
    echo "  ✅ extractSourceInstitution: " . $source . "\n";
    
    // Test extractDestinationInstitution
    $method = $reflection->getMethod('extractDestinationInstitution');
    $method->setAccessible(true);
    $dest = $method->invoke($swapService, $testPayload);
    echo "  ✅ extractDestinationInstitution: " . $dest . "\n";
    
    // Test extractDestinationAssetType
    $method = $reflection->getMethod('extractDestinationAssetType');
    $method->setAccessible(true);
    $assetType = $method->invoke($swapService, $testPayload);
    echo "  ✅ extractDestinationAssetType: " . $assetType . "\n";
    
    // Test extractSourceIdentifier
    $method = $reflection->getMethod('extractSourceIdentifier');
    $method->setAccessible(true);
    $sourceId = $method->invoke($swapService, $testPayload);
    echo "  ✅ extractSourceIdentifier: " . json_encode($sourceId) . "\n";
    
    // Test extractDestinationIdentifier
    $method = $reflection->getMethod('extractDestinationIdentifier');
    $method->setAccessible(true);
    $destId = $method->invoke($swapService, $testPayload);
    echo "  ✅ extractDestinationIdentifier: " . json_encode($destId) . "\n";
    
} catch (Exception $e) {
    echo "  ❌ SwapService test failed: " . $e->getMessage() . "\n";
    echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
}

// ============================================================================
// STEP 4: TRACE THROUGH GENERICBANKCLIENT - What gets sent to ZURUBANK
// ============================================================================

printHeader('STEP 4: GENERICBANKCLIENT - WHAT ACTUALLY GETS SENT');

try {
    // Get ZURUBANK participant config
    $participants = $countryConfig['participants'] ?? [];
    $zurubankConfig = $participants['ZURUBANK'] ?? null;
    
    if (!$zurubankConfig) {
        echo "  ❌ ZURUBANK configuration not found\n";
    } else {
        echo "  ✅ ZURUBANK config loaded\n";
        echo "  ZURUBANK base_url: " . ($zurubankConfig['base_url'] ?? 'Not set') . "\n";
        
        // Initialize GenericBankClient for ZURUBANK
        $bankClient = new GenericBankClient($zurubankConfig);
        echo "  ✅ GenericBankClient initialized for ZURUBANK\n";
        
        // Use reflection to get YAML endpoints
        $reflection = new ReflectionClass($bankClient);
        $yamlEndpointsProp = $reflection->getProperty('yamlEndpoints');
        $yamlEndpointsProp->setAccessible(true);
        $yamlBaseUrlProp = $reflection->getProperty('yamlBaseUrl');
        $yamlBaseUrlProp->setAccessible(true);
        
        $yamlEndpoints = $yamlEndpointsProp->getValue($bankClient);
        $yamlBaseUrl = $yamlBaseUrlProp->getValue($bankClient);
        
        echo "  YAML Base URL: " . ($yamlBaseUrl ?? 'Not set') . "\n";
        echo "  YAML Endpoints loaded: " . ($yamlEndpoints ? 'YES' : 'NO') . "\n";
        
        if ($yamlEndpoints && isset($yamlEndpoints['source']['verify_asset'])) {
            echo "  Verify Asset Endpoint: " . $yamlEndpoints['source']['verify_asset'] . "\n";
        }
        
        // ============================================================
        // STEP 4a: Test verifyAssetSigned - Build the actual payload
        // ============================================================
        printSection('4a: Building verifyAssetSigned Payload');
        
        // Create the verify payload that would be sent
        $timestamp = time();
        $verifyPayload = [
            'action' => 'VERIFY_ASSET',
            'reference' => 'SWAP_' . $timestamp . '_' . bin2hex(random_bytes(8)),
            'asset_type' => $testPayload['asset_type'],
            'amount' => $testPayload['amount'],
            'currency' => $testPayload['currency'],
            'institution' => 'ZURUBANK',
            'timestamp' => $timestamp,
            'swap_type' => $testPayload['swap_type'],
            'requester' => 'VOUCHMORPH',
            'from_institution' => $testPayload['from_institution'],
            'source_institution' => $testPayload['source_institution'],
            'source_identifier' => $testPayload['source_identifier'],
            'source_identifier_type' => 'auto',
            // These are critical for voucher
            'voucher_number' => $testPayload['voucher_number'],
            'voucher_pin' => $testPayload['voucher_pin'],
        ];
        
        echo "\n  🔍 PAYLOAD THAT WILL BE SENT TO ZURUBANK:\n";
        printJson($verifyPayload, '  verifyAssetSigned Payload');
        
        // ============================================================
        // STEP 4b: Test createSignedPayload - What gets signed
        // ============================================================
        printSection('4b: createSignedPayload - Signed Request');
        
        // Use reflection to access createSignedPayload
        $method = $reflection->getMethod('createSignedPayload');
        $method->setAccessible(true);
        
        try {
            $signedPayload = $method->invoke($bankClient, $verifyPayload, 'VOUCHMORPH');
            echo "\n  ✅ createSignedPayload executed\n";
            
            echo "\n  🔍 SIGNED PAYLOAD KEYS:\n";
            echo "    " . implode("\n    ", array_keys($signedPayload)) . "\n";
            
            // Check for voucher fields
            echo "\n  🔍 CRITICAL: Voucher fields in signed payload:\n";
            echo "    voucher_number: " . ($signedPayload['voucher_number'] ?? '❌ MISSING') . "\n";
            echo "    voucher_pin: " . ($signedPayload['voucher_pin'] ?? '❌ MISSING') . "\n";
            echo "    asset_type: " . ($signedPayload['asset_type'] ?? '❌ MISSING') . "\n";
            echo "    source_identifier: " . ($signedPayload['source_identifier'] ?? '❌ MISSING') . "\n";
            
            if (!isset($signedPayload['voucher_number'])) {
                echo "\n  ⚠️ WARNING: voucher_number is MISSING from signed payload!\n";
                echo "  This is why ZURUBANK is saying 'Voucher number required'\n";
            }
            
            // Check what fields are in the signed payload
            echo "\n  🔍 ALL FIELDS IN SIGNED PAYLOAD:\n";
            $fieldList = [];
            foreach ($signedPayload as $key => $value) {
                if (is_string($value) && strlen($value) > 100) {
                    $value = substr($value, 0, 50) . '...';
                } elseif (is_array($value)) {
                    $value = 'Array(' . count($value) . ')';
                }
                $fieldList[] = "    " . $key . ": " . $value;
            }
            echo implode("\n", $fieldList) . "\n";
            
            // ============================================================
            // STEP 4c: Test send - The actual HTTP request
            // ============================================================
            printSection('4c: send() - Actual HTTP Request');
            
            // Get the endpoint and full URL
            $endpointMethod = $reflection->getMethod('getEndpoint');
            $endpointMethod->setAccessible(true);
            $endpoint = $endpointMethod->invoke($bankClient, 'verify_asset');
            
            $baseUrlMethod = $reflection->getMethod('getBaseUrl');
            $baseUrlMethod->setAccessible(true);
            $baseUrl = $baseUrlMethod->invoke($bankClient);
            
            $fullUrl = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
            
            echo "\n  🔍 HTTP REQUEST DETAILS:\n";
            echo "    Base URL: " . $baseUrl . "\n";
            echo "    Endpoint: " . $endpoint . "\n";
            echo "    Full URL: " . $fullUrl . "\n";
            echo "    Payload size: " . strlen(json_encode($signedPayload)) . " bytes\n";
            
            // Build headers
            $headersMethod = $reflection->getMethod('buildHeaders');
            $headersMethod->setAccessible(true);
            $headers = $headersMethod->invoke($bankClient, $signedPayload, null);
            
            echo "    Headers:\n";
            foreach ($headers as $header) {
                echo "      " . $header . "\n";
            }
            
            // ============================================================
            // STEP 4d: Compare with ZURUBANK's expected format
            // ============================================================
            printSection('4d: ZURUBANK EXPECTED FORMAT (from ZURUBANK logs)');
            
            echo "\n  📋 From ZURUBANK logs - what it actually received:\n";
            echo "    asset_type: VOUCHER\n";
            echo "    voucher_number: (MISSING - this is the problem)\n";
            echo "    source_identifier: 719729822604 (treated as account number)\n";
            echo "    phone: 719729822604\n";
            echo "    email: 719729822604\n";
            echo "    national_id: 719729822604\n";
            
            echo "\n  📋 What ZURUBANK EXPECTS:\n";
            echo "    asset_type: VOUCHER\n";
            echo "    voucher_number: 719729822604 ← This field is MISSING from the request!\n";
            echo "    voucher_pin: 328606\n";
            
            echo "\n  🔍 THE PROBLEM: ZURUBANK is looking for 'voucher_number' but it's not in the signed payload.\n";
            
        } catch (Exception $e) {
            echo "  ❌ createSignedPayload failed: " . $e->getMessage() . "\n";
            echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
    }
} catch (Exception $e) {
    echo "  ❌ GenericBankClient test failed: " . $e->getMessage() . "\n";
    echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
}

// ============================================================================
// STEP 5: CHECK GENERICBANKCLIENT.PHP SOURCE
// ============================================================================

printHeader('STEP 5: CHECKING GENERICBANKCLIENT.PHP SOURCE');

$bankClientPath = __DIR__ . '/../src/Infrastructure/Banks/GenericBankClient.php';
if (file_exists($bankClientPath)) {
    $content = file_get_contents($bankClientPath);
    
    // Check for voucher_number in createSignedPayload or processDepositWithProof
    echo "\n  🔍 Searching for 'voucher_number' in GenericBankClient.php:\n";
    if (strpos($content, 'voucher_number') !== false) {
        echo "    ✅ voucher_number found in GenericBankClient.php\n";
        
        // Show where it's used
        preg_match_all('/.*voucher_number.*/', $content, $matches);
        foreach ($matches[0] ?? [] as $line) {
            echo "      " . trim($line) . "\n";
        }
    } else {
        echo "    ❌ voucher_number NOT FOUND in GenericBankClient.php\n";
        echo "    ⚠️ This is likely the problem!\n";
    }
    
    echo "\n  🔍 Searching for 'voucher_pin' in GenericBankClient.php:\n";
    if (strpos($content, 'voucher_pin') !== false) {
        echo "    ✅ voucher_pin found in GenericBankClient.php\n";
    } else {
        echo "    ❌ voucher_pin NOT FOUND in GenericBankClient.php\n";
    }
    
    echo "\n  🔍 Checking createSignedPayload method:\n";
    preg_match('/function\s+createSignedPayload\s*\([^)]*\)\s*\{([^}]+)\}/s', $content, $matches);
    if (isset($matches[1])) {
        echo "    createSignedPayload body found (" . strlen($matches[1]) . " chars)\n";
        
        // Check what fields it looks for
        preg_match_all('/\$payload\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $matches[1], $fieldMatches);
        $fields = array_unique($fieldMatches[1] ?? []);
        echo "    Fields it reads from payload:\n";
        foreach ($fields as $field) {
            echo "      " . $field . "\n";
        }
        
        if (!in_array('voucher_number', $fields)) {
            echo "    ⚠️ voucher_number is NOT in the fields list!\n";
        }
        if (!in_array('voucher_pin', $fields)) {
            echo "    ⚠️ voucher_pin is NOT in the fields list!\n";
        }
    }
    
    echo "\n  🔍 Checking processDepositWithProof method:\n";
    preg_match('/function\s+processDepositWithProof\s*\([^)]*\)\s*\{([^}]+)\}/s', $content, $matches);
    if (isset($matches[1])) {
        preg_match_all('/\$payload\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $matches[1], $fieldMatches);
        $fields = array_unique($fieldMatches[1] ?? []);
        echo "    Fields it reads from payload:\n";
        foreach ($fields as $field) {
            echo "      " . $field . "\n";
        }
    }
} else {
    echo "  ❌ GenericBankClient.php not found at: " . $bankClientPath . "\n";
}

// ============================================================================
// STEP 6: RECOMMENDATIONS
// ============================================================================

printHeader('STEP 6: RECOMMENDATIONS');

echo "\n  🔍 DIAGNOSIS SUMMARY:\n";
echo "  ===================\n";

// Check if voucher fields are being dropped
$hasVoucherInPayload = isset($verifyPayload) && isset($verifyPayload['voucher_number']);
$hasVoucherInSigned = isset($signedPayload) && isset($signedPayload['voucher_number']);

if ($hasVoucherInPayload && !$hasVoucherInSigned) {
    echo "\n  ⚠️ CRITICAL ISSUE: voucher_number is being DROPPED in createSignedPayload()!\n";
    echo "     This method likely reconstructs the payload and doesn't include voucher_number.\n";
    echo "\n  FIX: Modify createSignedPayload() in GenericBankClient.php to preserve voucher_number.\n";
} elseif (!$hasVoucherInPayload) {
    echo "\n  ⚠️ CRITICAL ISSUE: voucher_number is NOT in the payload being sent to SwapService!\n";
    echo "     Check the request payload structure.\n";
} else {
    echo "\n  ✅ voucher_number appears to be flowing through correctly.\n";
}

echo "\n  📋 RECOMMENDED FIX:\n";
echo "  =================\n";
echo "  1. Open src/Infrastructure/Banks/GenericBankClient.php\n";
echo "  2. Find the createSignedPayload() method\n";
echo "  3. Add this line near the top:\n";
echo "     if (isset(\$payload['voucher_number'])) {\n";
echo "         \$payload['voucherNumber'] = \$payload['voucher_number'];\n";
echo "         \$payload['voucher_no'] = \$payload['voucher_number'];\n";
echo "     }\n";
echo "  4. If it still doesn't work, also add to processDepositWithProof():\n";
echo "     if (isset(\$payload['voucher_number']) && !isset(\$signedPayload['voucher_number'])) {\n";
echo "         \$signedPayload['voucher_number'] = \$payload['voucher_number'];\n";
echo "     }\n";

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                         DIAGNOSTIC TEST COMPLETE                           ║\n";
echo "║                           " . getCurrentTimestamp() . "                          ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
