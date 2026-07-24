<?php
// src/API/User/Balance.php
// Get balance for a user's source - FULLY DYNAMIC from country config
// NO HARDCODING - everything comes from participants.yaml and config.php

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
        status,
        is_hooked,
        source_reference,
        access_token,
        token_expires_at
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
        'error' => 'Source not found or not owned by this user',
        'institution' => $institution,
        'identifier' => $identifier
    ]);
    exit();
}

// ============================================================
// 5. LOAD COUNTRY CONFIG (NO HARDCODING)
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
        'error' => "Institution '{$institution}' not configured for this country",
        'available_institutions' => array_keys($participants)
    ]);
    exit();
}

// ============================================================
// 6. GET INSTITUTION CONFIGURATION
// ============================================================
$participantConfig = $participants[$institution];

// Base URL
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
        'error' => "No balance endpoint configured for {$institution}",
        'available_endpoints' => array_keys($sourceEndpoints)
    ]);
    exit();
}

$fullUrl = $baseUrl . '/' . ltrim($balanceEndpoint, '/');

// ============================================================
// 8. GET AUTHENTICATION CONFIGURATION
// ============================================================
$authConfig = $participantConfig['auth'] ?? [];
$authType = $authConfig['type'] ?? 'API_KEY';
$headerName = $authConfig['header_name'] ?? 'X-API-KEY';

// Get API key from environment
$secretSource = $authConfig['secret_source'] ?? [];
$apiKeyEnv = $secretSource['name'] ?? strtoupper($institution) . '_API_KEY';
$apiKey = getenv($apiKeyEnv);

if (!$apiKey) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "No API key configured for {$institution}",
        'expected_env' => $apiKeyEnv
    ]);
    exit();
}

// ============================================================
// 9. GET ADDITIONAL HEADERS
// ============================================================
$additionalHeaders = $sourceEndpoints['headers'] ?? $sourceEndpoints['headers'] ?? [];

// ============================================================
// 10. BUILD REQUEST PARAMETERS (DYNAMIC)
// ============================================================
$params = [];

// Get parameter mapping from config or use defaults
$paramMapping = $sourceEndpoints['param_mapping'] ?? [];
$identifierTypeLower = strtolower($identifierType);

// Default parameter mapping based on identifier type
$defaultParamMap = [
    'phone' => ['phone', 'wallet_phone', 'beneficiary_phone', 'client_phone', 'msisdn'],
    'account' => ['account_number', 'account_id', 'source_identifier', 'identifier'],
    'card' => ['card_number', 'card_id', 'card_no'],
    'email' => ['email', 'email_address'],
    'national_id' => ['national_id', 'id_number', 'identity_value'],
    'auto' => ['source_identifier', 'identifier', 'account_number', 'phone', 'wallet_phone']
];

// Use custom mapping if provided, otherwise use defaults
$paramMap = !empty($paramMapping) ? $paramMapping : ($defaultParamMap[$identifierTypeLower] ?? $defaultParamMap['auto']);

// Build parameters
foreach ($paramMap as $paramName) {
    $params[$paramName] = $identifier;
}

// Add asset_type if the endpoint requires it
$requiresAssetType = $sourceEndpoints['requires_asset_type'] ?? false;
if ($requiresAssetType) {
    $params['asset_type'] = $source['asset_type'] ?? 'ACCOUNT';
}

// Add source_identifier_type if needed
if ($sourceEndpoints['requires_identifier_type'] ?? false) {
    $params['identifier_type'] = $identifierType;
}

// Add currency if needed
if ($sourceEndpoints['requires_currency'] ?? false) {
    $params['currency'] = $source['currency'] ?? 'BWP';
}

// Add any static parameters from config
$staticParams = $sourceEndpoints['static_params'] ?? [];
foreach ($staticParams as $key => $value) {
    $params[$key] = $value;
}

// Add hooked source access token if available
if (!empty($source['access_token']) && ($source['is_hooked'] ?? false)) {
    // Decrypt the access token
    $accessToken = decryptSourceSecret($source['access_token']);
    if ($accessToken) {
        $params['access_token'] = $accessToken;
        
        // Also try different header names for token
        if ($sourceEndpoints['token_header'] ?? false) {
            $additionalHeaders[$sourceEndpoints['token_header']] = 'Bearer ' . $accessToken;
        }
    }
}

// ============================================================
// 11. MAKE THE REQUEST
// ============================================================
$queryString = http_build_query($params);
$requestUrl = $fullUrl . (strpos($fullUrl, '?') === false ? '?' : '&') . $queryString;

// Log the request (for debugging)
error_log("[Balance API] Request URL: {$requestUrl}");
error_log("[Balance API] Institution: {$institution}, Identifier: {$identifier}");

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $requestUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, $participantConfig['timeout_ms'] ?? 5000);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Build headers
$headers = [
    $headerName . ': ' . $apiKey,
    'Content-Type: application/json',
    'Accept: application/json',
    'User-Agent: VouchMorph/1.0'
];

// Add additional headers
foreach ($additionalHeaders as $headerKey => $headerValue) {
    if (is_string($headerKey)) {
        $headers[] = $headerKey . ': ' . $headerValue;
    } elseif (is_string($headerValue)) {
        $headers[] = $headerValue;
    }
}

// Add correlation ID for tracing
$correlationId = uniqid('bal_', true);
$headers[] = 'X-Correlation-ID: ' . $correlationId;

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Execute request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlInfo = curl_getinfo($ch);
curl_close($ch);

// ============================================================
// 12. HANDLE RESPONSE
// ============================================================
if ($curlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => "Request failed: {$curlError}",
        'institution' => $institution,
        'url' => $requestUrl
    ]);
    exit();
}

$data = json_decode($response, true);

if (!$data) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => "Invalid JSON response from {$institution}",
        'raw_response' => substr($response, 0, 500)
    ]);
    exit();
}

// ============================================================
// 13. EXTRACT BALANCE (DYNAMIC MAPPING)
// ============================================================
$responseMapping = $sourceEndpoints['response_mapping'] ?? [];
$balanceField = $responseMapping['balance'] ?? 'balance';
$currencyField = $responseMapping['currency'] ?? 'currency';
$accountField = $responseMapping['account'] ?? 'account_number';
$statusField = $responseMapping['status'] ?? 'status';
$messageField = $responseMapping['message'] ?? 'message';

// Extract using dot notation
$balance = array_get($data, $balanceField);
if ($balance === null) {
    // Try common fallbacks
    $fallbacks = ['balance', 'total_balance', 'available_balance', 'amount', 'data.balance', 'data.total_balance', 'data.available_balance'];
    foreach ($fallbacks as $fallback) {
        $balance = array_get($data, $fallback);
        if ($balance !== null) break;
    }
}

if ($balance === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => "No balance found in response from {$institution}",
        'fields_checked' => [$balanceField, ...$fallbacks],
        'raw_response' => $data
    ]);
    exit();
}

// Extract currency
$currency = array_get($data, $currencyField);
if (!$currency) {
    $currencyFallbacks = ['currency', 'currency_code', 'data.currency', 'data.currency_code', 'data.currency_iso'];
    foreach ($currencyFallbacks as $fallback) {
        $currency = array_get($data, $fallback);
        if ($currency) break;
    }
}
$currency = $currency ?? $source['currency'] ?? 'BWP';

// Extract account identifier
$account = array_get($data, $accountField);
if (!$account) {
    $accountFallbacks = ['account_number', 'account_id', 'wallet_phone', 'phone', 'identifier', 'data.account_number'];
    foreach ($accountFallbacks as $fallback) {
        $account = array_get($data, $fallback);
        if ($account) break;
    }
}
$account = $account ?? $identifier;

// Extract status
$status = array_get($data, $statusField);
if (!$status) {
    $status = 'active';
}

// Extract message
$message = array_get($data, $messageField);
if (!$message) {
    $message = 'Balance retrieved successfully';
}

// ============================================================
// 14. RETURN SUCCESS
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
        'status' => $status,
        'message' => $message,
        'raw' => $data // Optional - remove in production
    ]
]);

// ============================================================
// 15. HELPER FUNCTIONS
// ============================================================

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

/**
 * Decrypt a source secret (access_token or refresh_token)
 * This matches the encryption used in SwapService
 */
function decryptSourceSecret(?string $encrypted): ?string {
    if (empty($encrypted)) {
        return null;
    }
    
    $key = getenv('VOUCHMORPH_TOKEN_ENC_KEY');
    if (!$key) {
        error_log("[Balance API] VOUCHMORPH_TOKEN_ENC_KEY not set");
        return null;
    }
    
    $data = base64_decode($encrypted);
    if ($data === false || strlen($data) < 16) {
        return null;
    }
    
    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    
    $decrypted = openssl_decrypt(
        $ciphertext,
        'AES-256-CBC',
        $key,
        0,
        $iv
    );
    
    return $decrypted !== false ? $decrypted : null;
}
