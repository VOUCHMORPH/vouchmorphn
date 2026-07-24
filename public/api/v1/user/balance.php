<?php
// public/api/v1/user/balance.php
// Get balance for a specific source - DYNAMIC from country config

require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
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

// ============================================================
// 5. LOAD COUNTRY CONFIG
// ============================================================
$countryConfig = LoadCountry::getConfig();
if (empty($countryConfig)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Country configuration not loaded']);
    exit();
}

$participants = $countryConfig['participants'] ?? [];
if (empty($participants[$institution])) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "Institution '{$institution}' not configured",
        'available_institutions' => array_keys($participants)
    ]);
    exit();
}

// ============================================================
// 6. GET INSTITUTION CONFIGURATION
// ============================================================
$participantConfig = $participants[$institution];

$baseUrl = rtrim($participantConfig['base_url'] ?? '', '/');
if (empty($baseUrl)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "No base_url configured for {$institution}"]);
    exit();
}

// ============================================================
// 7. GET BALANCE ENDPOINT
// ============================================================
$endpoints = $participantConfig['endpoints'] ?? [];
$sourceEndpoints = $endpoints['source'] ?? [];
$balanceEndpoint = $sourceEndpoints['get_balance'] ?? $sourceEndpoints['balance'] ?? null;

if (!$balanceEndpoint) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "No balance endpoint configured for {$institution}"
    ]);
    exit();
}

$fullUrl = $baseUrl . '/' . ltrim($balanceEndpoint, '/');

// ============================================================
// 8. GET AUTHENTICATION
// ============================================================
$authConfig = $participantConfig['auth'] ?? [];
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
// 9. BUILD PARAMETERS
// ============================================================
$params = [];
$paramMapping = $sourceEndpoints['param_mapping'] ?? [];
$identifierTypeLower = strtolower($identifierType);

$defaultParamMap = [
    'phone' => ['phone', 'wallet_phone', 'beneficiary_phone', 'client_phone'],
    'account' => ['account_number', 'account_id', 'source_identifier'],
    'card' => ['card_number', 'card_id'],
    'auto' => ['source_identifier', 'identifier', 'account_number', 'phone']
];

$paramMap = !empty($paramMapping) ? $paramMapping : ($defaultParamMap[$identifierTypeLower] ?? $defaultParamMap['auto']);

foreach ($paramMap as $paramName) {
    $params[$paramName] = $identifier;
}

if ($sourceEndpoints['requires_asset_type'] ?? false) {
    $params['asset_type'] = $source['asset_type'] ?? 'ACCOUNT';
}

$queryString = http_build_query($params);
$requestUrl = $fullUrl . (strpos($fullUrl, '?') === false ? '?' : '&') . $queryString;

// ============================================================
// 10. MAKE REQUEST
// ============================================================
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $requestUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, $participantConfig['timeout_ms'] ?? 5000);
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
// 11. EXTRACT BALANCE
// ============================================================
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

if ($balance === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "No balance found in response from {$institution}"
    ]);
    exit();
}

$currency = array_get($data, $currencyField);
if (!$currency) {
    $currency = $source['currency'] ?? 'BWP';
}

// ============================================================
// 12. RETURN
// ============================================================
echo json_encode([
    'success' => true,
    'data' => [
        'balance' => (float)$balance,
        'currency' => strtoupper($currency),
        'account' => $identifier,
        'institution' => $institution
    ]
]);

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
