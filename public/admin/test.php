<?php
// Place this at: /public/admin/test.php
header('Content-Type: application/json');

// Function to mask sensitive data
function maskValue($value) {
    if (empty($value)) return 'NOT SET';
    if (strlen($value) <= 10) return '***';
    return substr($value, 0, 8) . '...' . substr($value, -4);
}

$result = [
    'service' => 'VouchMorph API Key Debugger',
    'timestamp' => date('Y-m-d H:i:s'),
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'headers_received' => [],
    'api_keys_found' => [],
    'environment_variables' => [],
    'test_results' => []
];

// 1. Check incoming headers for API key
$headers = getallheaders();
$headers_lower = array_change_key_case($headers, CASE_LOWER);

$result['headers_received'] = [
    'x-api-key' => isset($headers_lower['x-api-key']) ? maskValue($headers_lower['x-api-key']) : 'NOT SET',
    'authorization' => isset($headers_lower['authorization']) ? maskValue($headers_lower['authorization']) : 'NOT SET',
    'api-key' => isset($headers_lower['api-key']) ? maskValue($headers_lower['api-key']) : 'NOT SET'
];

// 2. Check all possible environment variable sources for API keys
$possible_keys = [
    // Direct API keys
    'API_KEY_SYSTEM',
    'API_KEY_PARTNER_1',
    'API_KEY_PARTNER_2',
    'API_KEY_PARTNER_3',
    'API_KEY_PARTNER_4',
    'API_KEY_CAZACOM',
    'API_KEY_ZURUBANK',
    'API_KEY_SACCUSSALIS',
    'VOUCHMORPH_API_KEY',
    'SYSTEM_API_KEY',
    
    // Upstream pattern (Railway Vault)
    'UPSTREAM_ZURUBANK_KEY',
    'UPSTREAM_CAZACOM_KEY',
    'UPSTREAM_SACCUSSALIS_KEY',
    'UPSTREAM_VOUCHMORPH_KEY',
    
    // Generic patterns
    'API_KEY',
    'APP_KEY',
    'SECRET_KEY'
];

foreach ($possible_keys as $key) {
    $value = getenv($key);
    if ($value !== false && !empty($value)) {
        $result['api_keys_found'][$key] = maskValue($value);
    }
}

// 3. Scan all environment variables for anything containing KEY or API
foreach ($_SERVER as $key => $value) {
    if (preg_match('/KEY|API|TOKEN|SECRET/i', $key) && !empty($value) && !is_array($value)) {
        if (!isset($result['environment_variables'][$key])) {
            $result['environment_variables'][$key] = maskValue($value);
        }
    }
}

// 4. Check if getenv() returns different values
$result['getenv_check'] = [];
$sample_keys = ['API_KEY_SYSTEM', 'VOUCHMORPH_API_KEY', 'UPSTREAM_ZURUBANK_KEY'];
foreach ($sample_keys as $key) {
    $env_value = getenv($key);
    $server_value = $_SERVER[$key] ?? null;
    $result['getenv_check'][$key] = [
        'getenv' => maskValue($env_value),
        '_SERVER' => maskValue($server_value),
        'match' => ($env_value === $server_value)
    ];
}

// 5. Test each found API key against the execute endpoint
if (isset($_GET['test_keys']) && $_GET['test_keys'] === 'true') {
    $test_payload = json_encode([
        'source' => [
            'institution' => 'TEST',
            'asset_type' => 'ACCOUNT',
            'amount' => 1
        ],
        'destination' => [
            'institution' => 'TEST'
        ]
    ]);
    
    foreach ($result['api_keys_found'] as $key_name => $masked) {
        $actual_key = getenv($key_name);
        if ($actual_key) {
            $ch = curl_init('https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $test_payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-Key: ' . $actual_key,
                'X-Country-Code: BW'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $result['test_results'][$key_name] = [
                'http_code' => $http_code,
                'success' => ($http_code === 200),
                'response' => json_decode($response, true)
            ];
        }
    }
}

// 6. Add curl command examples
$result['curl_examples'] = [
    'example_1' => 'curl -X POST https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php -H "Content-Type: application/json" -H "X-API-Key: YOUR_API_KEY" -H "X-Country-Code: BW" -k -d \'{"source":{"institution":"ZURUBANK","amount":100},"destination":{"institution":"CAZACOM"}}\'',
    'example_2' => 'curl -X POST https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php -H "Content-Type: application/json" -H "Authorization: Bearer YOUR_API_KEY" -H "X-Country-Code: BW" -k -d \'{"source":{"institution":"ZURUBANK","amount":100},"destination":{"institution":"CAZACOM"}}\''
];

// 7. Add recommendation
if (empty($result['api_keys_found'])) {
    $result['recommendation'] = 'NO API KEYS FOUND IN ENVIRONMENT. Check Railway Vault variables.';
} else {
    $working_keys = array_filter($result['test_results'] ?? [], function($test) {
        return $test['success'] === true;
    });
    
    if (empty($working_keys) && isset($_GET['test_keys'])) {
        $result['recommendation'] = 'API keys found but none work. Check if execute.php is reading the correct environment variables.';
        $result['recommendation'] .= ' The API key might need to be in the database or a different format.';
    } elseif (empty($working_keys)) {
        $result['recommendation'] = 'Add &test_keys=true to URL to test each key against the API';
    } else {
        $result['recommendation'] = 'Use one of the working keys above';
        $result['working_keys'] = array_keys($working_keys);
    }
}

echo json_encode($result, JSON_PRETTY_PRINT);
