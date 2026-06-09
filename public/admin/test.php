<?php
// /public/admin/test_vault.php
header('Content-Type: application/json');

// Railway Vault variables are available via getenv() but may need specific patterns

$result = [
    'service' => 'Railway Vault API Key Test',
    'timestamp' => date('Y-m-d H:i:s'),
    'vault_variables' => [],
    'all_environment_variables' => [],
    'test_results' => []
];

// 1. Check specific Railway Vault variable patterns
$vault_patterns = [
    // Direct vault variables
    'VOUCHMORPH_API_KEY',
    'API_KEY_SYSTEM', 
    'API_KEY_ZURUBANK',
    'API_KEY_SACCUSSALIS',
    'API_KEY_CAZACOM',
    
    // Upstream pattern (common in Railway)
    'UPSTREAM_VOUCHMORPH_API_KEY',
    'UPSTREAM_ZURUBANK_KEY',
    'UPSTREAM_SACCUSSALIS_KEY',
    'UPSTREAM_CAZACOM_KEY',
    
    // Railway service-specific
    'RAILWAY_SERVICE_API_KEY',
    'SERVICE_API_KEY',
    
    // Generic patterns Railway might use
    'API_KEY',
    'APP_KEY',
    'SECRET_KEY'
];

foreach ($vault_patterns as $pattern) {
    // Try multiple ways to get the value
    $value = false;
    
    // Method 1: getenv()
    if (getenv($pattern) !== false) {
        $value = getenv($pattern);
    }
    
    // Method 2: $_ENV
    if (!$value && isset($_ENV[$pattern])) {
        $value = $_ENV[$pattern];
    }
    
    // Method 3: $_SERVER
    if (!$value && isset($_SERVER[$pattern])) {
        $value = $_SERVER[$pattern];
    }
    
    if ($value && !empty($value)) {
        $result['vault_variables'][$pattern] = [
            'exists' => true,
            'value_masked' => substr($value, 0, 10) . '...' . substr($value, -5),
            'length' => strlen($value),
            'source' => 'railway_vault'
        ];
    }
}

// 2. Scan ALL environment variables for anything that looks like a key
$all_vars = array_merge($_ENV, $_SERVER, getenv());
foreach ($all_vars as $key => $value) {
    if (is_string($value) && !empty($value)) {
        // Look for long strings (32+ chars) or key-related names
        if (strlen($value) >= 32 || preg_match('/KEY|API|TOKEN|SECRET|VAULT/i', $key)) {
            if (!isset($result['all_environment_variables'][$key])) {
                $result['all_environment_variables'][$key] = [
                    'value_masked' => substr($value, 0, 10) . '...' . substr($value, -5),
                    'length' => strlen($value)
                ];
            }
        }
    }
}

// 3. Test each found key against the API
if (isset($_GET['test']) && $_GET['test'] === 'true') {
    $test_payload = json_encode([
        'test' => true,
        'source' => ['institution' => 'TEST', 'amount' => 1],
        'destination' => ['institution' => 'TEST']
    ]);
    
    // Combine all found keys
    $keys_to_test = [];
    foreach ($result['vault_variables'] as $key => $info) {
        $actual_value = getenv($key) ?: ($_ENV[$key] ?? $_SERVER[$key] ?? null);
        if ($actual_value) {
            $keys_to_test[$key] = $actual_value;
        }
    }
    
    foreach ($result['all_environment_variables'] as $key => $info) {
        if (!isset($keys_to_test[$key])) {
            $actual_value = getenv($key) ?: ($_ENV[$key] ?? $_SERVER[$key] ?? null);
            if ($actual_value) {
                $keys_to_test[$key] = $actual_value;
            }
        }
    }
    
    foreach ($keys_to_test as $key_name => $actual_key) {
        $ch = curl_init('https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $test_payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $actual_key
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result['test_results'][$key_name] = [
            'http_code' => $http_code,
            'works' => ($http_code === 200)
        ];
    }
}

// 4. Show railway CLI command to check vault
$result['railway_commands'] = [
    'list_all_vars' => 'railway variables',
    'get_specific' => 'railway variables get VARIABLE_NAME',
    'list_service_vars' => 'railway variables --service your-service-name'
];

// 5. Recommendation
if (empty($result['vault_variables'])) {
    $result['recommendation'] = 'No vault variables found. Make sure you have set variables in Railway Vault.';
    $result['how_to_fix'] = [
        '1. Go to Railway Dashboard',
        '2. Select your project',
        '3. Click on your service',
        '4. Go to "Variables" tab',
        '5. Add variables like: API_KEY_SYSTEM=your_key_here',
        '6. Redeploy the service'
    ];
} else {
    $working = array_filter($result['test_results'] ?? [], function($test) {
        return $test['works'] === true;
    });
    
    if (empty($working)) {
        $result['recommendation'] = 'Keys found in vault but none work. Run with ?test=true to test each key.';
    } else {
        $result['recommendation'] = 'Working API keys found! Use one of: ' . implode(', ', array_keys($working));
    }
}

echo json_encode($result, JSON_PRETTY_PRINT);
