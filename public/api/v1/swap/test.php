<?php
// test_swap_debug.php - Place in /var/www/html/public/api/v1/swap/test_swap_debug.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<pre>";
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "VOUCHMORPH SWAP SERVICE DIAGNOSTIC TEST\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

// ============================================================
// TEST 1: Database Connection
// ============================================================
echo "TEST 1: Database Connection\n";
echo "───────────────────────────────────────────────────────────────────────\n";

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;

try {
    $db = DBConnection::getConnection();
    if ($db) {
        echo "✅ Database connection: SUCCESS\n";
        $stmt = $db->query("SELECT 1");
        echo "   Query test: PASSED\n";
    } else {
        echo "❌ Database connection: FAILED (returned null)\n";
    }
} catch (Exception $e) {
    echo "❌ Database connection: ERROR - " . $e->getMessage() . "\n";
}
echo "\n";

// ============================================================
// TEST 2: Load Participants from YAML
// ============================================================
echo "TEST 2: Load Participants from YAML\n";
echo "───────────────────────────────────────────────────────────────────────\n";

$participantsPath = __DIR__ . '/../../../../src/Core/Config/Countries/Botswana/participants.yaml';

if (!file_exists($participantsPath)) {
    echo "❌ Participants file NOT FOUND at: {$participantsPath}\n";
} else {
    echo "✅ Participants file found\n";
    
    function parseParticipantsYaml($path) {
        $content = file_get_contents($path);
        $participants = [];
        $lines = explode("\n", $content);
        $inParticipants = false;
        $current = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^participants:$/', $line)) {
                $inParticipants = true;
                continue;
            }
            
            if ($inParticipants && preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
                $current = $matches[1];
                $participants[$current] = [];
                continue;
            }
            
            if ($current && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $value = trim($matches[2], '"\'');
                $participants[$current][$matches[1]] = $value;
            }
        }
        return $participants;
    }
    
    $participants = parseParticipantsYaml($participantsPath);
    echo "   Parsed participants: " . implode(', ', array_keys($participants)) . "\n";
    
    // Test lookup
    $testInstitution = 'ZURUBANK';
    if (isset($participants[$testInstitution])) {
        echo "✅ Lookup '{$testInstitution}': FOUND\n";
        echo "   Data: " . json_encode($participants[$testInstitution]) . "\n";
    } else {
        echo "❌ Lookup '{$testInstitution}': NOT FOUND\n";
        echo "   Available keys: " . implode(', ', array_keys($participants)) . "\n";
    }
}
echo "\n";

// ============================================================
// TEST 3: SwapService Constructor
// ============================================================
echo "TEST 3: SwapService Constructor\n";
echo "───────────────────────────────────────────────────────────────────────\n";

if (!class_exists('Domain\Services\SwapService')) {
    echo "❌ SwapService class not found\n";
} else {
    echo "✅ SwapService class found\n";
    
    try {
        // Load config
        require_once __DIR__ . '/../../../../src/Core/Config/LoadCountry.php';
        $config = \Core\Config\LoadCountry::getConfig();
        
        $swapService = new \Domain\Services\SwapService($db, $config, 'Botswana');
        echo "✅ SwapService instantiated successfully\n";
        
        // Test getParticipants method if exists
        if (method_exists($swapService, 'getParticipants')) {
            $participants = $swapService->getParticipants();
            echo "   Participants from SwapService: " . implode(', ', array_keys($participants)) . "\n";
        } else {
            echo "   ⚠️ getParticipants() method not available\n";
        }
        
    } catch (Exception $e) {
        echo "❌ SwapService instantiation FAILED: " . $e->getMessage() . "\n";
    }
}
echo "\n";

// ============================================================
// TEST 4: Test Payload with Direct SwapService Call
// ============================================================
echo "TEST 4: Test Payload with Direct SwapService Call\n";
echo "───────────────────────────────────────────────────────────────────────\n";

if (isset($swapService) && $db) {
    $testPayload = [
        'reference' => 'DIAG-TEST-001',
        'idempotency_key' => 'DIAG-IDEMP-001',
        'swap_type' => 'CASHOUT',
        'from_institution' => 'ZURUBANK',
        'to_institution' => 'SACCUSSALIS',
        'asset_type' => 'VOUCHER',
        'voucher_number' => '710083197',
        'voucher_pin' => '657250',
        'amount' => 200,
        'currency' => 'BWP',
        'beneficiary_phone' => '+26770000000'
    ];
    
    echo "Test Payload:\n";
    echo json_encode($testPayload, JSON_PRETTY_PRINT) . "\n\n";
    
    try {
        $result = $swapService->executeAtomicSwap($testPayload);
        echo "✅ SwapService executed successfully\n";
        echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    } catch (Exception $e) {
        echo "❌ SwapService execution FAILED: " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
} else {
    echo "⚠️ Cannot run test - SwapService or DB not available\n";
}
echo "\n";

// ============================================================
// TEST 5: Check API Endpoint Reachability
// ============================================================
echo "TEST 5: API Endpoint Reachability\n";
echo "───────────────────────────────────────────────────────────────────────\n";

$apiUrl = 'https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php';
$apiKey = 'vouchmorph_live_1aB2cD3eF4gH5iJ6';

$testPayload = [
    'reference' => 'API-TEST-001',
    'idempotency_key' => 'API-IDEMP-001',
    'swap_type' => 'CASHOUT',
    'from_institution' => 'ZURUBANK',
    'to_institution' => 'SACCUSSALIS',
    'asset_type' => 'VOUCHER',
    'voucher_number' => '710083197',
    'voucher_pin' => '657250',
    'amount' => 200,
    'currency' => 'BWP',
    'beneficiary_phone' => '+26770000000'
];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($testPayload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_VERBOSE => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Status Code: {$httpCode}\n";
if ($curlError) {
    echo "CURL Error: {$curlError}\n";
}
echo "Response: " . $response . "\n\n";

// ============================================================
// TEST 6: Check PHP Extensions
// ============================================================
echo "TEST 6: PHP Extensions Check\n";
echo "───────────────────────────────────────────────────────────────────────\n";

$requiredExtensions = ['pdo', 'pdo_pgsql', 'pgsql', 'json', 'curl'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "✅ {$ext} - loaded\n";
    } else {
        echo "❌ {$ext} - NOT LOADED\n";
    }
}
echo "\n";

// ============================================================
// TEST 7: Check Directory Permissions
// ============================================================
echo "TEST 7: Directory Permissions\n";
echo "───────────────────────────────────────────────────────────────────────\n";

$configDir = __DIR__ . '/../../../../src/Core/Config/Countries/Botswana';
echo "Config directory: {$configDir}\n";
echo "Readable: " . (is_readable($configDir) ? 'YES' : 'NO') . "\n";
echo "Writable: " . (is_writable($configDir) ? 'YES' : 'NO') . "\n";

$yamlFile = $configDir . '/participants.yaml';
echo "participants.yaml readable: " . (is_readable($yamlFile) ? 'YES' : 'NO') . "\n";
echo "participants.yaml size: " . (file_exists($yamlFile) ? filesize($yamlFile) . ' bytes' : 'NOT FOUND') . "\n";

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "DIAGNOSTIC TEST COMPLETE\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "</pre>";
