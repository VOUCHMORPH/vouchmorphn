<?php
// public/api/v1/user/all_balances.php
// Get balances for all user sources - AGGREGATED
// Pulls configuration from country config files - NO HARDCODING

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
// Using ONLY columns that exist in the table
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
        access_token,
        refresh_token,
        token_expires_at,
        status,
        last_used_at,
        proposed_at,
        confirmed_at,
        updated_at
    FROM user_source_accounts
    WHERE user_id = :user_id 
    AND status = 'active' 
    AND deleted_at IS NULL
    ORDER BY institution, proposed_at DESC
");
$stmt->execute([':user_id' => $userId]);
$sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 4. LOAD COUNTRY CONFIG (NO HARDCODING)
// ============================================================
$countryConfig = LoadCountry::getConfig();
if (empty($countryConfig)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Country configuration not loaded']);
    exit();
}

$participants = $countryConfig['participants'] ?? [];

// ============================================================
// 5. FETCH BALANCE FOR EACH SOURCE
// ============================================================
$results = [];
$totalByCurrency = [];
$successCount = 0;
$failureCount = 0;

foreach ($sources as $source) {
    $institution = $source['institution'];
    $identifier = $source['identifier'];
    $identifierType = $source['identifier_type'] ?? 'auto';
    
    // Skip if institution not configured
    if (!isset($participants[$institution])) {
        $results[] = [
            'source' => $source,
            'balance' => [
                'success' => false,
                'error' => "Institution '{$institution}' not configured",
                'institution' => $institution
            ]
        ];
        $failureCount++;
        continue;
    }
    
    // Get balance for this source
    $balanceResult = getSourceBalance($source, $participants);
    
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
// 6. RETURN RESPONSE
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
// 7. HELPER FUNCTION: Get balance for a single source
// ============================================================
function getSourceBalance($source, $participants) {
    $institution = $source['institution'];
    $identifier = $source['identifier'];
    $identifierType = $source['identifier_type'] ?? 'auto';
    
    // Get participant config
    $participantConfig = $participants[$institution] ?? null;
    if (!$participantConfig) {
        return ['success' => false, 'error' => "Institution '{$institution}' not found in config"];
    }
    
    // Get balance endpoint
    $endpoints = $participantConfig['endpoints'] ?? [];
    $sourceEndpoints = $endpoints['source'] ?? [];
    $balanceEndpoint = $sourceEndpoints['get_balance'] ?? $sourceEndpoints['balance'] ?? null;
    
    if (!$balanceEndpoint) {
        return ['success' => false, 'error' => "No balance endpoint configured for '{$institution}'"];
    }
    
    // Build URL
    $baseUrl = rtrim($participantConfig['base_url'] ?? '', '/');
    if (empty($baseUrl)) {
        return ['success' => false, 'error' => "No base_url configured for '{$institution}'"];
    }
    
    $fullUrl = $baseUrl . '/' . ltrim($balanceEndpoint, '/');
    
    // Get authentication
    $authConfig = $participantConfig['auth'] ?? [];
    $headerName = $authConfig['header_name'] ?? 'X-API-KEY';
    $secretSource = $authConfig['secret_source'] ?? [];
    $apiKeyEnv = $secretSource['name'] ?? strtoupper($institution) . '_API_KEY';
    $apiKey = getenv($apiKeyEnv);
    
    if (!$apiKey) {
        return ['success' => false, 'error' => "No API key for '{$institution}' (env: {$apiKeyEnv})"];
    }
    
    // Build parameters
    $params = [];
    $paramMapping = $sourceEndpoints['param_mapping'] ?? [];
    $identifierTypeLower = strtolower($identifierType);
    
    // Default parameter mapping
    $defaultParamMap = [
        'phone' => ['phone', 'wallet_phone', 'beneficiary_phone', 'client_phone', 'msisdn'],
        'account' => ['account_number', 'account_id', 'source_identifier', 'identifier'],
        'card' => ['card_number', 'card_id', 'card_no'],
        'email' => ['email', 'email_address'],
        'national_id' => ['national_id', 'id_number', 'identity_value'],
        'auto' => ['source_identifier', 'identifier', 'account_number', 'phone', 'wallet_phone']
    ];
    
    $paramMap = !empty($paramMapping) ? $paramMapping : ($defaultParamMap[$identifierTypeLower] ?? $defaultParamMap['auto']);
    
    foreach ($paramMap as $paramName) {
        $params[$paramName] = $identifier;
    }
    
    // Add additional parameters if needed
    if ($sourceEndpoints['requires_asset_type'] ?? false) {
        $params['asset_type'] = $source['asset_type'] ?? 'ACCOUNT';
    }
    
    if ($sourceEndpoints['requires_identifier_type'] ?? false) {
        $params['identifier_type'] = $identifierType;
    }
    
    if ($sourceEndpoints['requires_currency'] ?? false) {
        $params['currency'] = $source['currency'] ?? 'BWP';
    }
    
    // Add static parameters
    $staticParams = $sourceEndpoints['static_params'] ?? [];
    foreach ($staticParams as $key => $value) {
        $params[$key] = $value;
    }
    
    // Build query string
    $queryString = http_build_query($params);
    $requestUrl = $fullUrl . (strpos($fullUrl, '?') === false ? '?' : '&') . $queryString;
    
    // Build headers
    $headers = [
        $headerName . ': ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: VouchMorph/1.0'
    ];
    
    // Add correlation ID
    $correlationId = uniqid('bal_', true);
    $headers[] = 'X-Correlation-ID: ' . $correlationId;
    
    // Make request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $requestUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $participantConfig['timeout_ms'] ?? 5000);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => "Request failed: {$curlError}"];
    }
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => "HTTP {$httpCode} from {$institution}"];
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        return ['success' => false, 'error' => "Invalid JSON response from {$institution}"];
    }
    
    // Extract balance using response mapping
    $responseMapping = $sourceEndpoints['response_mapping'] ?? [];
    $balanceField = $responseMapping['balance'] ?? 'balance';
    $currencyField = $responseMapping['currency'] ?? 'currency';
    $accountField = $responseMapping['account'] ?? 'account_number';
    $statusField = $responseMapping['status'] ?? 'status';
    
    // Extract balance using dot notation
    $balance = array_get($data, $balanceField);
    if ($balance === null) {
        $fallbacks = ['balance', 'total_balance', 'available_balance', 'amount', 'data.balance', 'data.total_balance'];
        foreach ($fallbacks as $fallback) {
            $balance = array_get($data, $fallback);
            if ($balance !== null) break;
        }
    }
    
    if ($balance === null) {
        return ['success' => false, 'error' => "No balance found in response from {$institution}"];
    }
    
    // Extract currency
    $currency = array_get($data, $currencyField);
    if (!$currency) {
        $currencyFallbacks = ['currency', 'currency_code', 'data.currency', 'data.currency_code'];
        foreach ($currencyFallbacks as $fallback) {
            $currency = array_get($data, $fallback);
            if ($currency) break;
        }
    }
    $currency = $currency ?? $source['currency'] ?? 'BWP';
    
    // Extract account identifier
    $account = array_get($data, $accountField);
    if (!$account) {
        $account = $identifier;
    }
    
    // Extract status
    $status = array_get($data, $statusField);
    if (!$status) {
        $status = 'active';
    }
    
    return [
        'success' => true,
        'balance' => (float)$balance,
        'currency' => strtoupper($currency),
        'account' => $account,
        'institution' => $institution,
        'identifier' => $identifier,
        'identifier_type' => $identifierType,
        'status' => $status
    ];
}

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
