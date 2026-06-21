<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

// Get authenticated user
$userId = getAuthenticatedUserId();

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get user's linked institutions from database
$institutions = getUserLinkedInstitutions($userId);

if (empty($institutions)) {
    echo json_encode([
        'success' => true,
        'balances' => [],
        'total_balance' => 0,
        'message' => 'No institutions linked'
    ]);
    exit;
}

$balances = [];
$totalBalance = 0;

foreach ($institutions as $institution) {
    // Get the balance directly from the institution's API
    $balanceResult = getBalanceFromInstitution($institution);
    
    $balances[] = [
        'institution' => $institution['name'],
        'institution_code' => $institution['code'],
        'account_id' => $institution['account_id'],
        'account_name' => $institution['account_name'],
        'balance' => $balanceResult['balance'] ?? 0,
        'currency' => $balanceResult['currency'] ?? $institution['currency'] ?? 'BWP',
        'last_updated' => date('Y-m-d H:i:s'),
        'success' => $balanceResult['success'] ?? false,
        'message' => $balanceResult['message'] ?? null
    ];
    
    if ($balanceResult['success'] ?? false) {
        $totalBalance += $balanceResult['balance'] ?? 0;
    }
}

echo json_encode([
    'success' => true,
    'balances' => $balances,
    'total_balance' => $totalBalance,
    'currency' => 'BWP',
    'last_updated' => date('Y-m-d H:i:s')
]);

/**
 * Get balance directly from institution's API
 * No adapters, no SwapService - just pure API call
 */
function getBalanceFromInstitution(array $institution): array
{
    // Get the balance endpoint from config
    $endpoint = $institution['endpoints']['balance'] ?? '/api/v1/accounts/balance.php';
    $baseUrl = rtrim($institution['base_url'], '/');
    $url = $baseUrl . $endpoint . '?account_id=' . urlencode($institution['account_id']);
    
    // Build headers with API key or token
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    // Add API key if available
    if (!empty($institution['api_key'])) {
        $headers[] = 'X-API-Key: ' . $institution['api_key'];
    }
    
    // Add OAuth token if available
    if (!empty($institution['access_token'])) {
        $headers[] = 'Authorization: Bearer ' . $institution['access_token'];
    }
    
    // Make the API call
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || $httpCode !== 200) {
        return [
            'success' => false,
            'message' => $error ?? 'HTTP ' . $httpCode,
            'balance' => 0
        ];
    }
    
    $data = json_decode($response, true);
    
    return [
        'success' => true,
        'balance' => (float) ($data['balance'] ?? $data['data']['balance'] ?? 0),
        'currency' => $data['currency'] ?? $data['data']['currency'] ?? 'BWP',
        'raw_response' => $data
    ];
}
