<?php
// public/api/v1/user/balance.php
// Get balance for a specific source - DYNAMIC from endpoints.yaml

require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;

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
$userCountry = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'User ID not found in session']);
    exit();
}

// ============================================================
// 2. GET REQUEST DATA
// ============================================================
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_GET;
}

$institution = $input['institution'] ?? null;
$identifier = $input['identifier'] ?? null;
$identifierType = $input['identifier_type'] ?? 'auto';
$assetType = $input['asset_type'] ?? null;

if (!$institution || !$identifier) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Institution and identifier are required']);
    exit();
}

// ============================================================
// 3. DATABASE CONNECTION
// ============================================================
$pdo = DBConnection::getConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// ============================================================
// 4. VERIFY USER OWNS THIS SOURCE
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        id, 
        institution, 
        asset_type, 
        identifier, 
        identifier_type,
        account_name,
        currency,
        status
    FROM user_source_accounts
    WHERE user_id = :user_id 
    AND institution = :institution 
    AND identifier = :identifier
    AND status = 'active'
    AND deleted_at IS NULL
");
$stmt->execute([
    ':user_id' => $userId,
    ':institution' => $institution,
    ':identifier' => $identifier
]);

$source = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$source) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'error' => 'Source not found or not owned by this user'
    ]);
    exit();
}

// Use asset_type from source if not provided
if (!$assetType) {
    $assetType = $source['asset_type'] ?? 'ACCOUNT';
}

// ============================================================
// 5. LOAD YAML PARSER (SAME AS DASHBOARD)
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
// 6. LOAD ENDPOINTS CONFIG FROM endpoints.yaml
// ============================================================
$endpointsConfig = [];
$endpointsPath = __DIR__ . '/../../../../src/Core/Config/Countries/' . $userCountry . '/endpoints.yaml';
if (file_exists($endpointsPath)) {
    $parsed = dashboard_yaml_parse_file($endpointsPath);
    $endpointsConfig = $parsed ?? [];
    error_log("[Balance] Loaded endpoints from: {$endpointsPath}");
    error_log("[Balance] Endpoints keys: " . json_encode(array_keys($endpointsConfig)));
} else {
    error_log("[Balance] endpoints.yaml NOT found at: {$endpointsPath}");
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => "Endpoints configuration not found for country: {$userCountry}"
    ]);
    exit();
}

// Also load participants for fallback config
$participants = [];
$participantsPath = __DIR__ . '/../../../../src/Core/Config/Countries/' . $userCountry . '/participants.yaml';
if (file_exists($participantsPath)) {
    $parsed = dashboard_yaml_parse_file($participantsPath);
    $participants = $parsed['participants'] ?? $parsed ?? [];
}

// ============================================================
// 7. GET INSTITUTION CONFIGURATION
// ============================================================
if (!isset($endpointsConfig[$institution])) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "Institution '{$institution}' not configured in endpoints.yaml",
        'available_institutions' => array_keys($endpointsConfig)
    ]);
    exit();
}

$endpointConfig = $endpointsConfig[$institution];

// Get base_url from endpoints.yaml, fallback to participants.yaml
$baseUrl = rtrim($endpointConfig['base_url'] ?? '', '/');
if (empty($baseUrl)) {
    $participantConfig = $participants[$institution] ?? [];
    $baseUrl = rtrim($participantConfig['base_url'] ?? '', '/');
}

if (empty($baseUrl)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "No base_url configured for {$institution}"]);
    exit();
}

// ============================================================
// 8. GET BALANCE ENDPOINT
// ============================================================
$endpoints = $endpointConfig['endpoints'] ?? [];
$sourceEndpoints = $endpoints['source'] ?? [];
$balanceEndpoint = $sourceEndpoints['get_balance'] ?? $sourceEndpoints['balance'] ?? null;

error_log("[Balance] {$institution} - balance endpoint: " . ($balanceEndpoint ?? 'NOT FOUND'));

if (!$balanceEndpoint) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "No balance endpoint configured for {$institution} in endpoints.yaml",
        'available_endpoints' => array_keys($sourceEndpoints)
    ]);
    exit();
}

$fullUrl = $baseUrl . '/' . ltrim($balanceEndpoint, '/');

// ============================================================
// 9. GET AUTHENTICATION
// ============================================================
$authConfig = $endpointConfig['auth'] ?? [];
$headerName = $authConfig['header_name'] ?? 'X-API-KEY';
$secretSource = $authConfig['secret_source'] ?? [];
$apiKeyEnv = $secretSource['name'] ?? strtoupper($institution) . '_API_KEY';
$apiKey = getenv($apiKeyEnv);

if (!$apiKey) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "No API key for {$institution} (env: {$apiKeyEnv})"
    ]);
    exit();
}

// ============================================================
// 10. BUILD PARAMETERS - INSTITUTION SPECIFIC
// ============================================================
$params = [];

// SACCUSSALIS: expects 'type' and 'identifier'
if ($institution === 'SACCUSSALIS') {
    $assetTypeUpper = strtoupper($assetType);
    if ($assetTypeUpper === 'WALLET' || $assetTypeUpper === 'MNO-WALLET' || $assetTypeUpper === 'BANK-WALLET') {
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
// Generic fallback
else {
    $params['identifier'] = $identifier;
}

// Add additional parameters if needed
if ($sourceEndpoints['requires_asset_type'] ?? false) {
    $params['asset_type'] = $assetType;
}

if ($sourceEndpoints['requires_identifier_type'] ?? false) {
    $params['identifier_type'] = $identifierType;
}

if ($sourceEndpoints['requires_currency'] ?? false) {
    $params['currency'] = $source['currency'] ?? 'BWP';
}

// Add static parameters from config
$staticParams = $sourceEndpoints['static_params'] ?? [];
foreach ($staticParams as $key => $value) {
    $params[$key] = $value;
}

$queryString = http_build_query($params);
$requestUrl = $fullUrl . (strpos($fullUrl, '?') === false ? '?' : '&') . $queryString;

error_log("[Balance] Request URL: {$requestUrl}");

// ============================================================
// 11. MAKE REQUEST
// ============================================================
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $requestUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, $endpointConfig['timeout_ms'] ?? 5000);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    $headerName . ': ' . $apiKey,
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => "Request failed: {$curlError}"
    ]);
    exit();
}

$data = json_decode($response, true);

if (!$data) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => "Invalid JSON response from {$institution}"
    ]);
    exit();
}

// ============================================================
// 12. EXTRACT BALANCE - HANDLE DIFFERENT RESPONSE FORMATS
// ============================================================
$balance = null;
$currency = 'BWP';
$account = $identifier;

// SACCUSSALIS format: { status: 'success', data: { balance: 1000 } }
if (isset($data['data']['balance'])) {
    $balance = $data['data']['balance'];
    $currency = $data['data']['currency'] ?? 'BWP';
    $account = $data['data']['account_number'] ?? $data['data']['account_id'] ?? $data['data']['wallet_id'] ?? $identifier;
} 
// ZURUBANK format: { balance: 1000 }
elseif (isset($data['balance'])) {
    $balance = $data['balance'];
    $currency = $data['currency'] ?? 'BWP';
    $account = $data['account_number'] ?? $identifier;
}
// { data: { available_balance: 1000 } }
elseif (isset($data['data']['available_balance'])) {
    $balance = $data['data']['available_balance'];
    $currency = $data['data']['currency'] ?? 'BWP';
}
// { available_balance: 1000 }
elseif (isset($data['available_balance'])) {
    $balance = $data['available_balance'];
    $currency = $data['currency'] ?? 'BWP';
}
// { status: 'success', balance: 1000 }
elseif (isset($data['balance']) && isset($data['status']) && $data['status'] === 'success') {
    $balance = $data['balance'];
    $currency = $data['currency'] ?? 'BWP';
}
// Fallback using response mapping from config
else {
    $responseMapping = $sourceEndpoints['response_mapping'] ?? [];
    $balanceField = $responseMapping['balance'] ?? 'balance';
    $currencyField = $responseMapping['currency'] ?? 'currency';
    
    $balance = array_get($data, $balanceField);
    if ($balance === null) {
        $fallbacks = ['balance', 'total_balance', 'available_balance', 'amount'];
        foreach ($fallbacks as $fallback) {
            $balance = array_get($data, $fallback);
            if ($balance !== null) break;
        }
    }
    
    $currency = array_get($data, $currencyField);
    if (!$currency) {
        $currency = $source['currency'] ?? 'BWP';
    }
}

if ($balance === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "No balance found in response from {$institution}"
    ]);
    exit();
}

// ============================================================
// 13. RETURN
// ============================================================
echo json_encode([
    'success' => true,
    'data' => [
        'balance' => (float)$balance,
        'currency' => strtoupper($currency),
        'account' => $account,
        'institution' => $institution,
        'identifier' => $identifier,
        'identifier_type' => $identifierType,
        'asset_type' => $assetType
    ]
]);

/**
 * Get nested array value using dot notation
 */
function array_get($array, $key, $default = null) {
    if (is_null($key)) return $default;
    if (isset($array[$key])) return $array[$key];
    
    $keys = explode('.', $key);
    $current = $array;
    
    foreach ($keys as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return $default;
        }
        $current = $current[$segment];
    }
    return $current;
}
