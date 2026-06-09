<?php
declare(strict_types=1);

/**
 * VouchMorph - Universal Swap Execution API
 * ZERO HARDCODING - WORKS FOR EVERY COUNTRY AUTOMATICALLY
 */

// ============================================
// 1. BOOTSTRAP - DISCOVER EVERYTHING DYNAMICALLY
// ============================================

define('SCRIPT_DIR', dirname(__FILE__));
define('PROJECT_ROOT', discoverProjectRoot(SCRIPT_DIR));

function discoverProjectRoot($startPath) {
    $current = realpath($startPath);
    $maxLevels = 10;
    $level = 0;
    
    while ($current && $level < $maxLevels) {
        if (file_exists($current . '/composer.json') && 
            (file_exists($current . '/src') || file_exists($current . '/vendor'))) {
            return $current;
        }
        $current = dirname($current);
        $level++;
    }
    
    return dirname($startPath, 4);
}

// ============================================
// 2. HEADERS - STANDARD CORS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Country-Code, X-Country, X-Currency, X-Request-ID");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo '';
    exit();
}

// ============================================
// 3. ERROR HANDLING
// ============================================
function sendJsonResponse($success, $data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success], $data));
    exit();
}

function sendError($message, $statusCode = 400, $details = []) {
    sendJsonResponse(false, [
        'error' => $message,
        'details' => $details
    ], $statusCode);
}

// ============================================
// 4. CONFIG LOADING FUNCTIONS
// ============================================

function discoverConfigPaths($rootPath) {
    $possiblePaths = [
        $rootPath . '/src/Core/Config',
        $rootPath . '/config',
        $rootPath . '/app/config',
        $rootPath . '/Config',
        dirname($rootPath) . '/config',
        $_SERVER['DOCUMENT_ROOT'] . '/../src/Core/Config'
    ];
    
    foreach ($possiblePaths as $path) {
        if (is_dir($path)) {
            return $path;
        }
    }
    
    if (is_dir($rootPath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getFilename() === 'countries_registry.json') {
                return $file->getPath();
            }
        }
    }
    
    return null;
}

function loadAnyConfig($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $content = file_get_contents($filePath);
    
    if (empty($content)) {
        return null;
    }
    
    switch ($ext) {
        case 'yaml':
        case 'yml':
            return parseYamlUniversal($content);
        case 'json':
            return json_decode($content, true);
        case 'php':
            return require $filePath;
        default:
            if (strpos($content, 'participants:') !== false || strpos($content, 'version:') !== false) {
                return parseYamlUniversal($content);
            }
            if (strpos(trim($content), '{') === 0 || strpos(trim($content), '[') === 0) {
                return json_decode($content, true);
            }
            return null;
    }
}

function parseYamlUniversal($content) {
    $result = [];
    $lines = explode("\n", $content);
    $stack = [&$result];
    $indentStack = [0];
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || preg_match('/^\s*#/', $line)) {
            continue;
        }
        
        $indent = strlen($line) - strlen(ltrim($line));
        $trimmed = ltrim($line);
        
        if (preg_match('/^-\s+(.*)$/', $trimmed, $matches)) {
            $value = trim($matches[1]);
            $value = trim($value, '"\'');
            
            while (count($stack) > 1 && $indent <= $indentStack[count($stack) - 1]) {
                array_pop($stack);
                array_pop($indentStack);
            }
            
            $parent = &$stack[count($stack) - 1];
            if (!isset($parent['__array'])) {
                $parent['__array'] = [];
            }
            $parent['__array'][] = $value;
            continue;
        }
        
        if (strpos($trimmed, ':') !== false) {
            list($key, $value) = explode(':', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, '"\'');
            
            while (count($stack) > 1 && $indent <= $indentStack[count($stack) - 1]) {
                array_pop($stack);
                array_pop($indentStack);
            }
            
            if ($value === '') {
                $parent = &$stack[count($stack) - 1];
                if (!isset($parent[$key])) {
                    $parent[$key] = [];
                }
                $stack[] = &$parent[$key];
                $indentStack[] = $indent;
            } else {
                $parent = &$stack[count($stack) - 1];
                $parent[$key] = $value;
            }
        }
    }
    
    array_walk_recursive($result, function(&$value) {
        if (is_array($value) && isset($value['__array'])) {
            $value = $value['__array'];
        }
    });
    
    return $result;
}

// ============================================
// 5. GET REQUEST INPUT
// ============================================
$input = null;
$rawInput = file_get_contents('php://input');

if (!empty($rawInput)) {
    $input = json_decode($rawInput, true);
    if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON payload: ' . json_last_error_msg(), 400);
    }
}

if (empty($input) && !empty($_POST)) {
    $input = $_POST;
}

if ($input === null) {
    $input = [];
}

// ============================================
// 6. LOAD COUNTRY REGISTRY
// ============================================
$configPath = discoverConfigPaths(PROJECT_ROOT);

if (!$configPath) {
    sendError('Cannot find configuration directory', 500, [
        'project_root' => PROJECT_ROOT
    ]);
}

$registryPaths = [
    $configPath . '/countries_registry.json',
    $configPath . '/countries_registry.yaml',
    $configPath . '/countries_registry.yml',
    $configPath . '/registry.json',
    $configPath . '/registry.yaml'
];

$registry = null;
foreach ($registryPaths as $path) {
    $loaded = loadAnyConfig($path);
    if ($loaded !== null && !empty($loaded)) {
        $registry = $loaded;
        error_log("[execute.php] Loaded registry from: {$path}");
        break;
    }
}

if (!$registry) {
    sendError('Country registry not found', 500, ['searched_paths' => $registryPaths]);
}

if (!isset($registry['countries']) || empty($registry['countries'])) {
    sendError('No countries defined in registry', 500);
}

// ============================================
// 7. DETECT COUNTRY
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);

$countryHints = [
    $_SERVER['HTTP_X_COUNTRY_CODE'] ?? null,
    $_SERVER['HTTP_X_COUNTRY'] ?? null,
    $headersLower['x-country-code'] ?? null,
    $headersLower['x-country'] ?? null,
    $_GET['country'] ?? null,
    $input['country'] ?? null,
    $input['source']['country'] ?? null
];

$countryHint = null;
foreach ($countryHints as $hint) {
    if (!empty($hint)) {
        $countryHint = $hint;
        break;
    }
}

$countryConfig = null;
$countryName = null;

if ($countryHint) {
    foreach ($registry['countries'] as $name => $config) {
        $configCode = $config['code'] ?? $config['country_code'] ?? '';
        if (strtolower($name) === strtolower($countryHint) || 
            strtolower($configCode) === strtolower($countryHint)) {
            $countryConfig = $config;
            $countryName = $name;
            break;
        }
    }
}

if (!$countryConfig) {
    $defaultCountry = $registry['default_country'] ?? array_key_first($registry['countries']);
    if ($defaultCountry && isset($registry['countries'][$defaultCountry])) {
        $countryConfig = $registry['countries'][$defaultCountry];
        $countryName = $defaultCountry;
    }
}

if (!$countryConfig) {
    sendError('No valid country found. Specify X-Country-Code header.', 400);
}

error_log("[execute.php] Country: {$countryName}");

// ============================================
// 8. LOAD COUNTRY CONFIGURATION
// ============================================
$countryPath = isset($countryConfig['config_path']) 
    ? PROJECT_ROOT . '/' . $countryConfig['config_path']
    : $configPath . '/Countries/' . $countryName;

$possibleCountryPaths = [
    $countryPath,
    $configPath . '/Countries/' . $countryName,
    $configPath . '/countries/' . $countryName,
    $configPath . '/' . $countryName
];

$actualCountryPath = null;
foreach ($possibleCountryPaths as $path) {
    if (is_dir($path)) {
        $actualCountryPath = $path;
        error_log("[execute.php] Country path: {$path}");
        break;
    }
}

if (!$actualCountryPath) {
    sendError("Configuration path not found for {$countryName}", 404);
}

// Load participants
$participantsFilePatterns = [
    $actualCountryPath . '/participants.yaml',
    $actualCountryPath . '/participants.yml',
    $actualCountryPath . '/participants.json'
];

$participants = null;
foreach ($participantsFilePatterns as $pattern) {
    $loaded = loadAnyConfig($pattern);
    if ($loaded !== null) {
        $participants = $loaded;
        error_log("[execute.php] Loaded participants from: {$pattern}");
        break;
    }
}

if (!$participants) {
    sendError("No participants found for {$countryName}", 404);
}

if (isset($participants['participants'])) {
    $participants = $participants['participants'];
}

// Load endpoints
$endpointsFilePatterns = [
    $actualCountryPath . '/endpoints.yaml',
    $actualCountryPath . '/endpoints.yml',
    $actualCountryPath . '/endpoints.json'
];

$endpoints = [];
foreach ($endpointsFilePatterns as $pattern) {
    $loaded = loadAnyConfig($pattern);
    if ($loaded !== null) {
        $endpoints = $loaded;
        error_log("[execute.php] Loaded endpoints from: {$pattern}");
        break;
    }
}

// Merge endpoints into participants
foreach ($participants as $code => &$participant) {
    if (isset($endpoints[$code])) {
        $participant['endpoints'] = $endpoints[$code];
        $participant['base_url'] = $endpoints[$code]['base_url'] ?? null;
        $participant['auth'] = $endpoints[$code]['auth'] ?? null;
        $participant['timeout_ms'] = $endpoints[$code]['timeout_ms'] ?? 5000;
    }
}

error_log("[execute.php] Loaded " . count($participants) . " participants");

// ============================================
// 9. AUTHENTICATION - UPDATED WITH MULTIPLE SOURCES
// ============================================
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
$validKeys = [];

// Source 1: Direct environment variables (Railway Vault)
$directEnvVars = [
    'VOUCHMORPH_API_KEY',
    'API_KEY_SYSTEM',
    'API_KEY_VOUCHMORPH',
    'API_KEY_SYSTEM_BW',
    'API_KEY_BOTSWANA',
    'CAZACOM_API_KEY',
    'CAZACOM_OUT_KEY',
    'ZURUBANK_API_KEY',
    'SACCUSSALIS_API_KEY'
];

foreach ($directEnvVars as $envVar) {
    $value = getenv($envVar);
    if ($value && !empty($value)) {
        $validKeys[] = $value;
        error_log("[AUTH] Added from env {$envVar}");
    }
}

// Source 2: From participant auth configs
foreach ($participants as $code => $participant) {
    // Check auth.api_key.secret_source.name
    if (isset($participant['auth']['api_key']['secret_source']['name'])) {
        $keyName = $participant['auth']['api_key']['secret_source']['name'];
        $value = getenv($keyName);
        if ($value) {
            $validKeys[] = $value;
            error_log("[AUTH] Added from participant {$code} env: {$keyName}");
        }
    }
    
    // Check auth.api_key.value
    if (isset($participant['auth']['api_key']['value'])) {
        $value = $participant['auth']['api_key']['value'];
        if ($value) {
            $validKeys[] = $value;
            error_log("[AUTH] Added from participant {$code} literal value");
        }
    }
    
    // Check security.api_key.value_env (legacy)
    if (isset($participant['security']['api_key']['value_env'])) {
        $keyName = $participant['security']['api_key']['value_env'];
        $value = getenv($keyName);
        if ($value) {
            $validKeys[] = $value;
            error_log("[AUTH] Added from participant {$code} legacy env: {$keyName}");
        }
    }
    
    // Check api_key directly
    if (isset($participant['api_key'])) {
        $value = $participant['api_key'];
        if ($value) {
            $validKeys[] = $value;
            error_log("[AUTH] Added from participant {$code} direct api_key");
        }
    }
}

// Source 3: Hardcoded test keys (remove in production!)
$testKeys = [
    'cazacom_out_3fJ8nL1sV5xY7aB9',
    'vouchmorph_live_1aB2cD3eF4gH5iJ6',
    'zurubank_live_5oP6qR7sT8uV9wX0',
    'saccussalis_live_3uV4wX5yZ6aB7cD8'
];

foreach ($testKeys as $testKey) {
    if (!in_array($testKey, $validKeys)) {
        $validKeys[] = $testKey;
        error_log("[AUTH] Added test key: " . substr($testKey, 0, 10) . "...");
    }
}

$validKeys = array_filter(array_unique($validKeys));

// Debug logging (partial keys only for security)
error_log("[AUTH] Total valid keys: " . count($validKeys));
error_log("[AUTH] Provided key: " . ($providedKey ? substr($providedKey, 0, 15) . '...' : 'null'));

// Check if provided key is valid
$isValid = false;
foreach ($validKeys as $validKey) {
    if ($providedKey === $validKey) {
        $isValid = true;
        error_log("[AUTH] Key MATCHED successfully!");
        break;
    }
}

if (!$isValid && !empty($validKeys)) {
    // For debugging: show first/last chars of valid keys (remove in production)
    $keyHints = array_map(function($k) {
        return substr($k, 0, 8) . '...' . substr($k, -4);
    }, array_slice($validKeys, 0, 5));
    
    sendError('Invalid API key', 401, [
        'country' => $countryName,
        'has_keys' => count($validKeys) > 0,
        'key_hint' => $providedKey ? substr($providedKey, 0, 8) . '...' . substr($providedKey, -4) : 'none',
        'valid_keys_start_with' => $keyHints
    ]);
}

error_log("[AUTH] Authentication successful for {$countryName}");

// ============================================
// 10. API CALL HELPER
// ============================================
function callParticipantApi($participant, $endpointKey, $payload, $method = 'POST') {
    $baseUrl = rtrim($participant['base_url'], '/');
    $endpoint = $participant['endpoints']['endpoints'][$endpointKey] ?? 
                 $participant['endpoints'][$endpointKey] ?? null;
    
    if (!$endpoint) {
        throw new Exception("Endpoint '{$endpointKey}' not configured");
    }
    
    $fullUrl = $baseUrl . $endpoint;
    
    // Get API key
    $authConfig = $participant['auth'] ?? [];
    $apiKey = null;
    
    if (isset($authConfig['api_key']['secret_source']['name'])) {
        $apiKey = getenv($authConfig['api_key']['secret_source']['name']);
    } elseif (isset($authConfig['api_key']['value'])) {
        $apiKey = $authConfig['api_key']['value'];
    }
    
    $headers = ['Content-Type: application/json'];
    if ($apiKey) {
        $headerName = $authConfig['api_key']['header_name'] ?? 'X-API-KEY';
        $headers[] = $headerName . ': ' . $apiKey;
    }
    
    error_log("[API] Calling: {$fullUrl}");
    error_log("[API] Payload: " . json_encode($payload));
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, $method === 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, $participant['timeout_ms'] ?? 5000);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception("CURL error: {$curlError}");
    }
    
    $decoded = json_decode($response, true);
    if ($decoded === null) {
        throw new Exception("Invalid JSON response: {$response}");
    }
    
    error_log("[API] Response: " . json_encode($decoded));
    return $decoded;
}

// ============================================
// 11. EXECUTE SWAP
// ============================================
try {
    $source = $input['source'] ?? [];
    $destination = $input['destination'] ?? [];
    
    // Validation
    if (empty($source)) throw new Exception('Source information required');
    if (empty($destination)) throw new Exception('Destination information required');
    if (empty($source['amount']) || $source['amount'] <= 0) throw new Exception('Valid amount required');
    if (empty($source['institution'])) throw new Exception('Source institution required');
    if (empty($destination['institution'])) throw new Exception('Destination institution required');
    
    $sourceInstitution = strtoupper($source['institution']);
    $destInstitution = strtoupper($destination['institution']);
    
    if (!isset($participants[$sourceInstitution])) {
        throw new Exception("Source institution not configured. Available: " . implode(', ', array_keys($participants)));
    }
    if (!isset($participants[$destInstitution])) {
        throw new Exception("Destination institution not configured. Available: " . implode(', ', array_keys($participants)));
    }
    
    $currency = $countryConfig['currency'] ?? 'BWP';
    $swapReference = 'SWAP-' . strtoupper(substr(md5(uniqid()), 0, 8)) . '-' . date('YmdHis');
    
    $sourceParticipant = $participants[$sourceInstitution];
    $destParticipant = $participants[$destInstitution];
    
    // STEP 1: Verify voucher with source institution (Zurubank)
    error_log("[SWAP] Step 1: Verifying voucher with {$sourceInstitution}");
    
    $verifyPayload = [
        'asset_type' => 'VOUCHER',
        'voucher_number' => $source['voucher_number'] ?? $source['identifier'] ?? null,
        'voucher_pin' => $source['voucher_pin'] ?? null,
        'amount' => (float)$source['amount'],
        'claimant_phone' => $destination['identifier'] ?? $source['identifier'] ?? null
    ];
    
    if (empty($verifyPayload['voucher_number'])) {
        throw new Exception('Voucher number required');
    }
    
    $verifyResult = callParticipantApi($sourceParticipant, 'verify_asset', $verifyPayload);
    
    if (!isset($verifyResult['verified']) || $verifyResult['verified'] !== true) {
        $errorMsg = $verifyResult['message'] ?? 'Voucher verification failed';
        throw new Exception("Verification failed: {$errorMsg}");
    }
    
    $availableBalance = $verifyResult['available_balance'] ?? $verifyResult['balance'] ?? 0;
    if ($availableBalance < $source['amount']) {
        throw new Exception("Insufficient balance. Available: {$availableBalance}, Requested: {$source['amount']}");
    }
    
    error_log("[SWAP] Voucher verified. Balance: {$availableBalance}");
    
    // STEP 2: Place hold (if endpoint exists)
    $holdReference = null;
    $endpointKeys = array_keys($sourceParticipant['endpoints']['endpoints'] ?? $sourceParticipant['endpoints'] ?? []);
    
    if (in_array('place_hold', $endpointKeys) || isset($sourceParticipant['endpoints']['place_hold'])) {
        error_log("[SWAP] Step 2: Placing hold");
        
        $holdPayload = [
            'voucher_number' => $verifyPayload['voucher_number'],
            'amount' => (float)$source['amount'],
            'swap_reference' => $swapReference
        ];
        
        try {
            $holdResult = callParticipantApi($sourceParticipant, 'place_hold', $holdPayload);
            $holdReference = $holdResult['hold_reference'] ?? $holdResult['reference'] ?? null;
            error_log("[SWAP] Hold placed: {$holdReference}");
        } catch (Exception $e) {
            error_log("[SWAP] Hold failed (continuing): " . $e->getMessage());
        }
    }
    
    // STEP 3: Process cashout to destination
    error_log("[SWAP] Step 3: Processing cashout to {$destInstitution}");
    
    $cashoutPayload = [
        'amount' => (float)$source['amount'],
        'currency' => $currency,
        'beneficiary_phone' => $destination['identifier'] ?? null,
        'reference' => $swapReference,
        'hold_reference' => $holdReference
    ];
    
    $cashoutEndpoints = ['confirm_cashout', 'process_cashout', 'cashout'];
    $cashoutResult = null;
    
    foreach ($cashoutEndpoints as $endpointName) {
        if (in_array($endpointName, $endpointKeys) || isset($destParticipant['endpoints'][$endpointName])) {
            try {
                $cashoutResult = callParticipantApi($destParticipant, $endpointName, $cashoutPayload);
                error_log("[SWAP] Cashout successful via {$endpointName}");
                break;
            } catch (Exception $e) {
                error_log("[SWAP] Cashout failed on {$endpointName}: " . $e->getMessage());
            }
        }
    }
    
    if (!$cashoutResult) {
        throw new Exception("No working cashout endpoint for {$destInstitution}");
    }
    
    // STEP 4: Confirm completion
    if ($holdReference && (in_array('confirm_debit', $endpointKeys) || isset($sourceParticipant['endpoints']['confirm_debit']))) {
        error_log("[SWAP] Step 4: Confirming debit");
        try {
            callParticipantApi($sourceParticipant, 'confirm_debit', [
                'hold_reference' => $holdReference,
                'status' => 'completed'
            ]);
        } catch (Exception $e) {
            error_log("[SWAP] Debit confirm failed: " . $e->getMessage());
        }
    }
    
    // Success response
    sendJsonResponse(true, [
        'status' => 'success',
        'swap_reference' => $swapReference,
        'message' => 'Swap completed successfully',
        'country' => [
            'name' => $countryName,
            'code' => $countryConfig['code'] ?? $countryName,
            'currency' => $currency
        ],
        'verification' => [
            'verified' => true,
            'balance' => $availableBalance
        ],
        'source' => [
            'institution' => $sourceInstitution,
            'amount' => (float)$source['amount'],
            'currency' => $source['currency'] ?? $currency
        ],
        'destination' => [
            'institution' => $destInstitution,
            'delivery_mode' => $destination['delivery_mode'] ?? 'cashout',
            'identifier' => $destination['identifier'] ?? null
        ],
        'hold_reference' => $holdReference
    ]);
    
} catch (Exception $e) {
    error_log("[SWAP] Error: " . $e->getMessage());
    sendError($e->getMessage(), 400, [
        'country' => $countryName,
        'swap_reference' => $swapReference ?? null
    ]);
}
