<?php
// public/api/v1/user/all_balances.php
// Get balances for all user sources - AGGREGATED
// Loads endpoints from /src/Core/Config/Countries/Botswana/endpoints.yaml
// API keys loaded from Railway environment variables (NO HARDCODING)

require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;

// ============================================================
// 0. ENVIRONMENT VARIABLE HELPER
// ============================================================
function getEnvVar($name, $default = null) {
    // Try getenv()
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }
    
    // Try $_ENV
    if (isset($_ENV[$name])) {
        return $_ENV[$name];
    }
    
    // Try $_SERVER
    if (isset($_SERVER[$name])) {
        return $_SERVER[$name];
    }
    
    // Try apache_getenv()
    if (function_exists('apache_getenv')) {
        $value = apache_getenv($name);
        if ($value !== false) {
            return $value;
        }
    }
    
    // Try uppercase version
    $upperName = strtoupper($name);
    if (isset($_ENV[$upperName])) {
        return $_ENV[$upperName];
    }
    
    $value = getenv($upperName);
    if ($value !== false) {
        return $value;
    }
    
    return $default;
}

// ============================================================
// 1. AUTHENTICATION
// ============================================================
SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$userData = SessionManager::getUser();
$userId = $userData['id'] ?? $userData['user_id'] ?? 0;
$userCountry = $userData['country'] ?? getEnvVar('VOUCHMORPH_COUNTRY') ?: 'Botswana';

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'User ID not found in session']);
    exit();
}

// ============================================================
// 2. DATABASE CONNECTION
// ============================================================
$pdo = DBConnection::getConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// ============================================================
// 3. GET ALL ACTIVE SOURCES FOR THE USER
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        id, 
        user_id,
        institution, 
        asset_type, 
        identifier, 
        identifier_type,
        account_name,
        currency,
        is_hooked,
        source_reference,
        status,
        confirmed_at,
        proposed_at
    FROM user_source_accounts
    WHERE user_id = :user_id 
    AND status = 'active' 
    AND deleted_at IS NULL
    ORDER BY institution, proposed_at DESC
");
$stmt->execute([':user_id' => $userId]);
$sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 4. LOAD YAML PARSER
// ============================================================
if (!function_exists('dashboard_yaml_parse_file')) {
    function dashboard_yaml_castScalar(string $v) {
        $v = trim($v);
        if ($v === '') return null;
        if ($v[0] === '"' || $v[0] === "'") {
            $quote = $v[0];
            $len = strlen($v);
            for ($i = 1; $i < $len; $i++) {
                if ($v[$i] === '\\' && $i + 1 < $len) { $i++; continue; }
                if ($v[$i] === $quote) return substr($v, 1, $i - 1);
            }
            return $v;
        }
        $hashPos = strpos($v, ' #');
        if ($hashPos !== false) $v = trim(substr($v, 0, $hashPos));
        $lower = strtolower($v);
        if ($lower === 'true' || $lower === 'yes') return true;
        if ($lower === 'false' || $lower === 'no') return false;
        if ($lower === 'null' || $v === '~') return null;
        if (is_numeric($v)) return $v + 0;
        if ($v[0] === '[' && str_ends_with($v, ']')) {
            $inner = trim(substr($v, 1, -1));
            if ($inner === '') return [];
            return array_map(fn($x) => dashboard_yaml_castScalar(trim($x)), explode(',', $inner));
        }
        return $v;
    }

    function dashboard_yaml_tokenize(string $content): array {
        $raw = explode("\n", str_replace("\r\n", "\n", $content));
        $lines = [];
        foreach ($raw as $line) {
            $trimmedRight = rtrim($line);
            if ($trimmedRight === '') continue;
            $stripped = ltrim($trimmedRight);
            if ($stripped === '' || $stripped[0] === '#') continue;
            if (preg_match('/^---\s*$/', $stripped) || preg_match('/^\.\.\.\s*$/', $stripped)) continue;
            $indent = strlen($trimmedRight) - strlen($stripped);
            $lines[] = [$indent, $stripped];
        }
        return array_values($lines);
    }

    function dashboard_yaml_parseBlock(array &$lines, int &$idx, int $blockIndent): array {
        $result = [];
        while ($idx < count($lines)) {
            [$indent, $content] = $lines[$idx];
            if ($blockIndent === -1) $blockIndent = $indent;
            if ($indent < $blockIndent) break;
            if ($indent > $blockIndent) { $idx++; continue; }

            if (str_starts_with($content, '- ')) {
                $itemContent = trim(substr($content, 2));
                if ($itemContent !== '' && preg_match('/^([A-Za-z0-9_\.\-]+):\s*(.*)$/', $itemContent, $m)) {
                    $lines[$idx] = [$indent + 2, $itemContent];
                    $item = dashboard_yaml_parseBlock($lines, $idx, $indent + 2);
                } elseif ($itemContent === '') {
                    $idx++;
                    $item = dashboard_yaml_parseBlock($lines, $idx, -1);
                } else {
                    $item = dashboard_yaml_castScalar($itemContent);
                    $idx++;
                }
                $result[] = $item;
                continue;
            }

            if (preg_match('/^([^:]+):\s*(.*)$/', $content, $m)) {
                $key = trim($m[1]);
                $value = $m[2];
                $idx++;
                if ($value === '') {
                    if ($idx < count($lines) && $lines[$idx][0] > $indent) {
                        $result[$key] = dashboard_yaml_parseBlock($lines, $idx, -1);
                    } else {
                        $result[$key] = null;
                    }
                } else {
                    $result[$key] = dashboard_yaml_castScalar($value);
                }
                continue;
            }
            $idx++;
        }
        return $result;
    }

    function dashboard_yaml_parse_file(string $path): array {
        if (!file_exists($path)) return [];
        $content = file_get_contents($path);
        if ($content === false) return [];
        try {
            $lines = dashboard_yaml_tokenize($content);
            $idx = 0;
            return dashboard_yaml_parseBlock($lines, $idx, -1);
        } catch (\Throwable $e) {
            error_log("[dashboard_yaml_parse_file] Failed: " . $e->getMessage());
            return [];
        }
    }
}

// ============================================================
// 5. LOAD ENDPOINTS CONFIG
// ============================================================
$endpointsConfig = [];
$endpointsPath = __DIR__ . '/../../../../src/Core/Config/Countries/' . $userCountry . '/endpoints.yaml';
if (file_exists($endpointsPath)) {
    $parsed = dashboard_yaml_parse_file($endpointsPath);
    $endpointsConfig = $parsed ?? [];
    error_log("[Balance] Loaded endpoints from: {$endpointsPath}");
} else {
    error_log("[Balance] endpoints.yaml NOT found at: {$endpointsPath}");
}

$participants = [];
$participantsPath = __DIR__ . '/../../../../src/Core/Config/Countries/' . $userCountry . '/participants.yaml';
if (file_exists($participantsPath)) {
    $parsed = dashboard_yaml_parse_file($participantsPath);
    $participants = $parsed['participants'] ?? $parsed ?? [];
}

// ============================================================
// 6. FETCH BALANCE FOR EACH SOURCE
// ============================================================
$results = [];
$totalByCurrency = [];
$successCount = 0;
$failureCount = 0;

foreach ($sources as $source) {
    $institution = $source['institution'];
    $identifier = $source['identifier'];
    $identifierType = $source['identifier_type'] ?? 'auto';
    $assetType = strtoupper($source['asset_type'] ?? 'ACCOUNT');
    
    // Skip if institution not configured in endpoints
    if (!isset($endpointsConfig[$institution])) {
        $results[] = [
            'source' => $source,
            'balance' => [
                'success' => false,
                'error' => "Institution '{$institution}' not configured in endpoints.yaml",
                'institution' => $institution
            ]
        ];
        $failureCount++;
        continue;
    }
    
    // Get balance for this source
    $balanceResult = getSourceBalance($source, $endpointsConfig, $participants);
    
    if ($balanceResult['success']) {
        $successCount++;
        $currency = $balanceResult['currency'] ?? $source['currency'] ?? 'BWP';
        $totalByCurrency[$currency] = ($totalByCurrency[$currency] ?? 0) + $balanceResult['balance'];
    } else {
        $failureCount++;
    }
    
    $results[] = [
        'source' => $source,
        'balance' => $balanceResult
    ];
}

// ============================================================
// 7. RETURN RESPONSE
// ============================================================
echo json_encode([
    'success' => true,
    'data' => [
        'sources' => $results,
        'total' => $totalByCurrency,
        'summary' => [
            'total_sources' => count($sources),
            'successful' => $successCount,
            'failed' => $failureCount,
            'currencies' => array_keys($totalByCurrency)
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $userId
    ]
]);

exit;

// ============================================================
// 8. HELPER FUNCTION: Get balance for a single source
// ============================================================
function getSourceBalance($source, $endpointsConfig, $participants) {
    $institution = $source['institution'];
    $identifier = $source['identifier'];
    $identifierType = $source['identifier_type'] ?? 'auto';
    $assetType = strtoupper($source['asset_type'] ?? 'ACCOUNT');
    
    // Get endpoint config
    $endpointConfig = $endpointsConfig[$institution] ?? null;
    if (!$endpointConfig) {
        return ['success' => false, 'error' => "Institution '{$institution}' not found in endpoints config"];
    }
    
    // Get balance endpoint
    $endpoints = $endpointConfig['endpoints'] ?? [];
    $sourceEndpoints = $endpoints['source'] ?? [];
    $balanceEndpoint = $sourceEndpoints['get_balance'] ?? $sourceEndpoints['balance'] ?? null;
    
    if (!$balanceEndpoint) {
        return ['success' => false, 'error' => "No balance endpoint configured for '{$institution}' in endpoints.yaml"];
    }
    
    // Build URL
    $baseUrl = rtrim($endpointConfig['base_url'] ?? '', '/');
    if (empty($baseUrl)) {
        $participantConfig = $participants[$institution] ?? [];
        $baseUrl = rtrim($participantConfig['base_url'] ?? '', '/');
    }
    
    if (empty($baseUrl)) {
        return ['success' => false, 'error' => "No base_url configured for '{$institution}'"];
    }
    
    $fullUrl = $baseUrl . '/' . ltrim($balanceEndpoint, '/');
    
    // ============================================================
    // GET API KEY FROM ENVIRONMENT VARIABLES (NO HARDCODING!)
    // ============================================================
    $authConfig = $endpointConfig['auth'] ?? [];
    $headerName = $authConfig['header_name'] ?? 'X-API-KEY';
    $secretSource = $authConfig['secret_source'] ?? [];
    $apiKeyEnv = $secretSource['name'] ?? strtoupper($institution) . '_API_KEY';
    
    // Use the getEnvVar() function to get the API key
    $apiKey = getEnvVar($apiKeyEnv);
    
    // Log what we're looking for (for debugging)
    error_log("[Balance] Looking for API key: {$apiKeyEnv}");
    
    if (!$apiKey) {
        // Check if the environment variable exists but is empty
        $envExists = isset($_ENV[$apiKeyEnv]) || getenv($apiKeyEnv) !== false;
        return [
            'success' => false, 
            'error' => "No API key for '{$institution}' (env: {$apiKeyEnv})" . ($envExists ? ' - variable exists but is empty' : ' - variable not found')
        ];
    }
    
    // Build parameters
    $params = [];
    
    // SACCUSSALIS: expects 'type' and 'identifier'
    if ($institution === 'SACCUSSALIS') {
        if ($assetType === 'WALLET' || $assetType === 'MNO-WALLET' || $assetType === 'BANK-WALLET') {
            $params['type'] = 'wallet';
        } else {
            $params['type'] = 'account';
        }
        $params['identifier'] = $identifier;
    } 
    // ZURUBANK: expects 'source_identifier'
    elseif ($institution === 'ZURUBANK') {
        $params['source_identifier'] = $identifier;
    } 
    else {
        $params['identifier'] = $identifier;
    }
    
    $queryString = http_build_query($params);
    $requestUrl = $fullUrl . (strpos($fullUrl, '?') === false ? '?' : '&') . $queryString;
    
    // Build headers
    $headers = [
        $headerName . ': ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    // Make request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $requestUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $endpointConfig['timeout_ms'] ?? 5000);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => "Request failed: {$curlError}"];
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        return ['success' => false, 'error' => "Invalid JSON response from {$institution}"];
    }
    
    // Extract balance
    $balance = null;
    $currency = 'BWP';
    
    if (isset($data['data']['balance'])) {
        $balance = $data['data']['balance'];
        $currency = $data['data']['currency'] ?? 'BWP';
    } 
    elseif (isset($data['balance'])) {
        $balance = $data['balance'];
        $currency = $data['currency'] ?? 'BWP';
    }
    elseif (isset($data['available_balance'])) {
        $balance = $data['available_balance'];
        $currency = $data['currency'] ?? 'BWP';
    }
    
    if ($balance === null) {
        return ['success' => false, 'error' => "No balance found in response from {$institution}"];
    }
    
    return [
        'success' => true,
        'balance' => (float)$balance,
        'currency' => strtoupper($currency),
        'account' => $identifier,
        'institution' => $institution
    ];
}
