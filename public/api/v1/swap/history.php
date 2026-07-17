<?php
declare(strict_types=1);

/**
 * VouchMorph - Swap History API
 * Returns swap history for a user
 */
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use Core\Database\DBConnection;

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Country-Code, X-Country");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function getAllApiKeysFromEnvironment(): array {
    $keys = [];
    $allVars = array_merge($_ENV, $_SERVER, getenv());
    
    foreach ($allVars as $name => $value) {
        if (is_string($value) && !empty($value)) {
            if (preg_match('/KEY|API|TOKEN|SECRET/i', $name) || strlen($value) >= 32) {
                $keys[] = $value;
            }
        }
    }
    
    return array_unique(array_filter($keys));
}

function getApiKeyFromRequest(): ?string {
    $headers = getallheaders();
    if ($headers) {
        $headersLower = array_change_key_case($headers, CASE_LOWER);
        
        if (isset($headersLower['x-api-key']) && !empty($headersLower['x-api-key'])) {
            return $headersLower['x-api-key'];
        }
        
        if (isset($headersLower['authorization']) && !empty($headersLower['authorization'])) {
            $auth = $headersLower['authorization'];
            if (strpos($auth, 'Bearer ') === 0) {
                return substr($auth, 7);
            }
            return $auth;
        }
    }
    
    if (isset($_SERVER['HTTP_X_API_KEY']) && !empty($_SERVER['HTTP_X_API_KEY'])) {
        return $_SERVER['HTTP_X_API_KEY'];
    }
    
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'Bearer ') === 0) {
            return substr($auth, 7);
        }
        return $auth;
    }
    
    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
        exit();
    }
    
    $providedKey = getApiKeyFromRequest();
    $validKeys = getAllApiKeysFromEnvironment();
    
    if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid API key']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON payload', 400);
    }
    
    $userId = $input['user_id'] ?? 0;
    $limit = min((int)($input['limit'] ?? 50), 100);
    
    if (!$userId) {
        throw new Exception('user_id required', 400);
    }
    
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get country config for currency
    $countryConfig = \Core\Config\LoadCountry::getConfig();
    $currency = $countryConfig['currency'] ?? 'BWP';
    
    // Query swap history from hold_transactions and related tables
    $sql = "
        SELECT 
            ht.hold_id,
            ht.swap_reference,
            ht.participant_name as source_institution,
            ht.destination_institution,
            ht.amount,
            ht.currency,
            ht.status,
            ht.created_at,
            ht.updated_at,
            ht.metadata,
            ca.swap_code,
            ca.pin_code as atm_code,
            ca.code_expiry,
            ca.status as cashout_status,
            ca.amount as cashout_amount,
            ca.fee_amount,
            ca.completed_at
        FROM hold_transactions ht
        LEFT JOIN cashout_authorizations ca ON ht.swap_reference = ca.swap_reference
        WHERE ht.source_details::text LIKE :user_search
        ORDER BY ht.created_at DESC
        LIMIT :limit
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_search', '%"user_id":' . $userId . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $swaps = [];
    foreach ($results as $row) {
        $metadata = json_decode($row['metadata'] ?? '{}', true);
        $sourceDetails = json_decode($row['source_details'] ?? '{}', true);
        
        $swap = [
            'reference' => $row['swap_reference'],
            'source_institution' => $row['source_institution'],
            'destination_institution' => $row['destination_institution'],
            'amount' => (float)$row['amount'],
            'currency' => $row['currency'] ?? $currency,
            'status' => $row['status'] ?? 'unknown',
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'fee' => (float)($row['fee_amount'] ?? 0),
            'swap_code' => $row['swap_code'] ?? null,
            'atm_code' => $row['atm_code'] ?? null,
            'code_expiry' => $row['code_expiry'] ?? null,
        ];
        
        // Add destination currency if available
        if (isset($metadata['destination_currency'])) {
            $swap['destination_currency'] = $metadata['destination_currency'];
        }
        
        // Add swap type from metadata
        if (isset($metadata['swap_type'])) {
            $swap['swap_type'] = $metadata['swap_type'];
        }
        
        // Add source identifier
        if (isset($sourceDetails['source_identifier'])) {
            $swap['source_identifier'] = $sourceDetails['source_identifier'];
        }
        
        // Add destination identifier from metadata
        if (isset($metadata['destination_identifier'])) {
            $swap['destination_identifier'] = $metadata['destination_identifier'];
        }
        
        // Add fee breakdown if available
        if (isset($metadata['fee_breakdown'])) {
            $swap['fee_breakdown'] = $metadata['fee_breakdown'];
        }
        
        // Add exchange rate if forex was applied
        if (isset($metadata['exchange_rate'])) {
            $swap['exchange_rate'] = $metadata['exchange_rate'];
        }
        if (isset($metadata['net_amount_destination'])) {
            $swap['net_amount_destination'] = $metadata['net_amount_destination'];
        }
        
        $swaps[] = $swap;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $swaps,
        'total' => count($swaps),
        'user_id' => $userId
    ]);
    
} catch (Exception $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    http_response_code($code);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    
    error_log("[HISTORY] Error: " . $e->getMessage());
}
