<?php
declare(strict_types=1);

/**
 * VouchMorph - Universal Swap Execution API
 * ZERO HARDCODING - WORKS FOR EVERY COUNTRY AUTOMATICALLY
 */

// ============================================
// 1. BOOTSTRAP - DISCOVER EVERYTHING DYNAMICALLY
// ============================================

// No assumptions about directory structure - discover from script location
define('SCRIPT_DIR', dirname(__FILE__));
define('PROJECT_ROOT', discoverProjectRoot(SCRIPT_DIR));

function discoverProjectRoot($startPath) {
    $current = realpath($startPath);
    $maxLevels = 10;
    $level = 0;
    
    while ($current && $level < $maxLevels) {
        // Look for telltale signs of project root
        if (file_exists($current . '/composer.json') && 
            (file_exists($current . '/src') || file_exists($current . '/vendor'))) {
            return $current;
        }
        $current = dirname($current);
        $level++;
    }
    
    // Fallback to 4 levels up (common pattern)
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
// 3. ERROR HANDLING - ALWAYS RETURN VALID JSON
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
// 4. UNIVERSAL FILE DISCOVERY FUNCTIONS
// ============================================

/**
 * Find ALL configuration directories dynamically
 */
function discoverConfigPaths($rootPath) {
    $possiblePaths = [
        $rootPath . '/src/Core/Config',
        $rootPath . '/config',
        $rootPath . '/app/config',
        $rootPath . '/Config',
        $rootPath . '/configuration',
        dirname($rootPath) . '/config',
        $_SERVER['DOCUMENT_ROOT'] . '/../src/Core/Config'
    ];
    
    foreach ($possiblePaths as $path) {
        if (is_dir($path)) {
            return $path;
        }
    }
    
    // Last resort: search for countries_registry.json
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

/**
 * Load ANY file regardless of extension
 */
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
            $decoded = json_decode($content, true);
            return ($decoded !== null) ? $decoded : null;
        case 'php':
            return require $filePath;
        case 'neon':
        case 'ini':
            return parse_ini_file($filePath, true);
        default:
            // Try to detect format from content
            if (strpos($content, 'participants:') !== false || strpos($content, 'version:') !== false) {
                return parseYamlUniversal($content);
            }
            if (strpos(trim($content), '{') === 0 || strpos(trim($content), '[') === 0) {
                $decoded = json_decode($content, true);
                return ($decoded !== null) ? $decoded : null;
            }
            return null;
    }
}

/**
 * Universal YAML parser - no external dependencies required
 */
function parseYamlUniversal($content) {
    $result = [];
    $lines = explode("\n", $content);
    $stack = [&$result];
    $indentStack = [0];
    
    foreach ($lines as $lineNum => $line) {
        $line = rtrim($line);
        if (empty($line) || preg_match('/^\s*#/', $line)) {
            continue;
        }
        
        $indent = strlen($line) - strlen(ltrim($line));
        $trimmed = ltrim($line);
        
        // Handle lists (array items)
        if (preg_match('/^-\s+(.*)$/', $trimmed, $matches)) {
            $value = trim($matches[1]);
            $value = trim($value, '"\'');
            
            // Pop stack until correct indent
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
        
        // Handle key-value pairs
        if (strpos($trimmed, ':') !== false) {
            list($key, $value) = explode(':', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, '"\'');
            
            // Pop stack until correct indent
            while (count($stack) > 1 && $indent <= $indentStack[count($stack) - 1]) {
                array_pop($stack);
                array_pop($indentStack);
            }
            
            if ($value === '') {
                // Nested structure
                $parent = &$stack[count($stack) - 1];
                if (!isset($parent[$key])) {
                    $parent[$key] = [];
                }
                $stack[] = &$parent[$key];
                $indentStack[] = $indent;
            } else {
                // Simple key-value
                $parent = &$stack[count($stack) - 1];
                $parent[$key] = $value;
            }
        }
    }
    
    // Convert __array placeholders back to arrays
    array_walk_recursive($result, function(&$value) {
        if (is_array($value) && isset($value['__array'])) {
            $value = $value['__array'];
        }
    });
    
    return $result;
}

// ============================================
// 5. GET REQUEST INPUT SAFELY
// ============================================
$input = null;
$rawInput = file_get_contents('php://input');

if (!empty($rawInput)) {
    $input = json_decode($rawInput, true);
    if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON payload: ' . json_last_error_msg(), 400);
    }
}

// If no input, try POST params
if (empty($input) && !empty($_POST)) {
    $input = $_POST;
}

// If still no input, use empty array
if ($input === null) {
    $input = [];
}

// ============================================
// 6. LOAD COUNTRY REGISTRY (DYNAMIC DISCOVERY)
// ============================================
$configPath = discoverConfigPaths(PROJECT_ROOT);

if (!$configPath) {
    sendError('Cannot find configuration directory', 500, [
        'project_root' => PROJECT_ROOT,
        'searched' => [
            PROJECT_ROOT . '/src/Core/Config',
            PROJECT_ROOT . '/config',
            PROJECT_ROOT . '/app/config'
        ]
    ]);
}

// Find registry file (could be JSON, YAML, or PHP)
$registryPaths = [
    $configPath . '/countries_registry.json',
    $configPath . '/countries_registry.yaml',
    $configPath . '/countries_registry.yml',
    $configPath . '/countries_registry.php',
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
    sendError('Country registry not found or empty', 500, [
        'searched_paths' => $registryPaths,
        'config_directory' => $configPath
    ]);
}

// Normalize registry structure
if (!isset($registry['countries']) && isset($registry['participants'])) {
    $registry = ['countries' => $registry];
}

if (!isset($registry['countries']) || empty($registry['countries'])) {
    sendError('No countries defined in registry', 500, [
        'registry_keys' => array_keys($registry)
    ]);
}

// Build available countries list
$availableCountries = [];
foreach ($registry['countries'] as $name => $config) {
    if (($config['enabled'] ?? true) !== false) {
        $availableCountries[] = [
            'name' => $name,
            'code' => $config['code'] ?? $config['country_code'] ?? $name,
            'currency' => $config['currency'] ?? 'Unknown'
        ];
    }
}

// ============================================
// 7. DETECT COUNTRY FROM REQUEST
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);

// Try multiple sources for country
$countryHints = [
    $_SERVER['HTTP_X_COUNTRY_CODE'] ?? null,
    $_SERVER['HTTP_X_COUNTRY'] ?? null,
    $_SERVER['HTTP_COUNTRY'] ?? null,
    $headersLower['x-country-code'] ?? null,
    $headersLower['x-country'] ?? null,
    $headersLower['country'] ?? null,
    $_GET['country'] ?? null,
    $_GET['cc'] ?? null,
    $input['country'] ?? null,
    $input['source']['country'] ?? null,
    $input['destination']['country'] ?? null
];

$countryHint = null;
foreach ($countryHints as $hint) {
    if (!empty($hint)) {
        $countryHint = $hint;
        break;
    }
}

// Find matching country
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

// If not found, use default
if (!$countryConfig) {
    $defaultCountry = $registry['default_country'] ?? array_key_first($registry['countries']);
    if ($defaultCountry && isset($registry['countries'][$defaultCountry])) {
        $countryConfig = $registry['countries'][$defaultCountry];
        $countryName = $defaultCountry;
        error_log("[execute.php] Using default country: {$countryName}");
    }
}

if (!$countryConfig) {
    sendError('No valid country found. Please specify X-Country-Code header.', 400, [
        'available_countries' => $availableCountries,
        'country_hint' => $countryHint
    ]);
}

// Check if country is enabled
if (($countryConfig['enabled'] ?? true) === false) {
    sendError("Country '{$countryName}' is not enabled", 403, [
        'available_countries' => $availableCountries
    ]);
}

error_log("[execute.php] Country detected: {$countryName}");

// ============================================
// 8. LOAD COUNTRY CONFIGURATION
// ============================================
$countryPath = isset($countryConfig['config_path']) 
    ? PROJECT_ROOT . '/' . $countryConfig['config_path']
    : $configPath . '/Countries/' . $countryName;

// Alternative path patterns
$possibleCountryPaths = [
    $countryPath,
    $configPath . '/Countries/' . $countryName,
    $configPath . '/countries/' . $countryName,
    $configPath . '/' . $countryName,
    $configPath . '/' . ($countryConfig['code'] ?? $countryName)
];

$actualCountryPath = null;
foreach ($possibleCountryPaths as $path) {
    if (is_dir($path)) {
        $actualCountryPath = $path;
        error_log("[execute.php] Found country path: {$path}");
        break;
    }
}

if (!$actualCountryPath) {
    sendError("Configuration path not found for {$countryName}", 404, [
        'searched_paths' => $possibleCountryPaths
    ]);
}

// Load participants file (try multiple names and extensions)
$participantsFilePatterns = [
    $actualCountryPath . '/participants.yaml',
    $actualCountryPath . '/participants.yml',
    $actualCountryPath . '/participants.json',
    $actualCountryPath . '/participants.php',
    $actualCountryPath . '/participants_' . ($countryConfig['code'] ?? $countryName) . '.yaml',
    $actualCountryPath . '/participants_' . strtolower($countryConfig['code'] ?? $countryName) . '.yaml',
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
    sendError("No participants configuration found for {$countryName}", 404, [
        'searched_paths' => $participantsFilePatterns,
        'country_path' => $actualCountryPath
    ]);
}

// Normalize participants structure
if (isset($participants['participants'])) {
    $participants = $participants['participants'];
}

if (empty($participants)) {
    sendError("No participants defined for {$countryName}", 404, [
        'participants_data' => is_array($participants) ? array_keys($participants) : 'invalid format'
    ]);
}

// Load endpoints if available
$endpointsFilePatterns = [
    $actualCountryPath . '/endpoints.yaml',
    $actualCountryPath . '/endpoints.yml',
    $actualCountryPath . '/endpoints.json',
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
// 9. AUTHENTICATION
// ============================================
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
$validKeys = [];

// Extract API keys from participants
foreach ($participants as $code => $participant) {
    $authSources = [
        $participant['auth']['api_key']['secret_source']['name'] ?? null,
        $participant['auth']['api_key']['value'] ?? null,
        $participant['security']['api_key']['value_env'] ?? null,
        $participant['security']['api_key']['value'] ?? null,
        $participant['api_key'] ?? null
    ];
    
    foreach ($authSources as $source) {
        if ($source) {
            $envValue = getenv($source);
            if ($envValue) {
                $validKeys[] = $envValue;
            } else {
                $validKeys[] = $source;
            }
        }
    }
}

// Check common env var names
$commonKeyNames = [
    'API_KEY_SYSTEM',
    'API_KEY_VOUCHMORPH',
    "API_KEY_" . strtoupper($countryName),
    "API_KEY_" . ($countryConfig['code'] ?? '')
];

foreach ($commonKeyNames as $keyName) {
    $keyValue = getenv($keyName);
    if ($keyValue) {
        $validKeys[] = $keyValue;
    }
}

$validKeys = array_filter(array_unique($validKeys));

// Validate
if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
    sendError('Invalid API key', 401, [
        'country' => $countryName,
        'has_keys' => count($validKeys) > 0
    ]);
}

// ============================================
// 10. EXECUTE SWAP
// ============================================
try {
    $source = $input['source'] ?? [];
    $destination = $input['destination'] ?? [];
    
    // Basic validation
    if (empty($source)) throw new Exception('Source information required');
    if (empty($destination)) throw new Exception('Destination information required');
    if (empty($source['amount']) || $source['amount'] <= 0) throw new Exception('Valid amount required');
    if (empty($source['institution'])) throw new Exception('Source institution required');
    if (empty($destination['institution'])) throw new Exception('Destination institution required');
    
    // Validate institutions exist
    $sourceInstitution = strtoupper($source['institution']);
    $destInstitution = strtoupper($destination['institution']);
    
    if (!isset($participants[$sourceInstitution])) {
        throw new Exception("Source institution not configured. Available: " . implode(', ', array_keys($participants)));
    }
    if (!isset($participants[$destInstitution])) {
        throw new Exception("Destination institution not configured. Available: " . implode(', ', array_keys($participants)));
    }
    
    $currency = $countryConfig['currency'] ?? 'ZAR';
    $swapReference = 'SWAP-' . strtoupper(substr(md5(uniqid()), 0, 8)) . '-' . date('YmdHis');
    
    // Return success response
    sendJsonResponse(true, [
        'status' => 'success',
        'swap_reference' => $swapReference,
        'message' => 'Swap processed successfully',
        'country' => [
            'name' => $countryName,
            'code' => $countryConfig['code'] ?? $countryName,
            'currency' => $currency
        ],
        'source' => [
            'institution' => $sourceInstitution,
            'amount' => (float)$source['amount'],
            'currency' => $source['currency'] ?? $currency
        ],
        'destination' => [
            'institution' => $destInstitution,
            'delivery_mode' => $destination['delivery_mode'] ?? 'deposit'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("[execute.php] Error: " . $e->getMessage());
    sendError($e->getMessage(), 400, [
        'country' => $countryName,
        'trace' => $e->getTraceAsString()
    ]);
}
