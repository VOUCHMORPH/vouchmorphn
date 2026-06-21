<?php
declare(strict_types=1);

/**
 * VouchMorph - Swap Preview API
 * Calculates fees and returns preview WITHOUT executing
 */

// Load bootstrap - this defines ROOT_PATH and everything else
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

// ============================================
// AUTHENTICATION
// ============================================

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
    
    // Get country from headers or payload
    $headers = getallheaders();
    $headersLower = array_change_key_case($headers ?: [], CASE_LOWER);
    $countryCode = $headersLower['x-country-code'] ?? $headersLower['x-country'] ?? $input['country'] ?? null;
    
    // Load country config
    $registryFile = ROOT_PATH . '/src/Core/Config/countries_registry.json';
    
    if (!file_exists($registryFile)) {
        throw new Exception('Country registry not found', 500);
    }
    
    $registry = json_decode(file_get_contents($registryFile), true);
    $countryConfig = null;
    
    if ($countryCode) {
        foreach ($registry['countries'] as $name => $config) {
            if (strtolower($name) === strtolower($countryCode) || 
                strtolower($config['code']) === strtolower($countryCode)) {
                $countryConfig = $config;
                break;
            }
        }
    }
    
    if (!$countryConfig) {
        $default = $registry['default_country'] ?? array_key_first($registry['countries']);
        $countryConfig = $registry['countries'][$default];
    }
    
    // Database connection - use the one from bootstrap
    $db = $container->get(PDO::class);
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get settings from container
    $settings = $container->get('settings');
    $participants = $container->get('participants');
    $feesConfig = $container->get('fees');
    $atmNotes = $container->get('atmNotes');
    
    $countryName = $countryConfig['name'] ?? 'Botswana';
    $currency = $settings['currency'] ?? 'BWP';
    
    // ============================================================
    // CALCULATE PREVIEW (NO EXECUTION)
    // ============================================================
    
    $amount = (float)($input['amount'] ?? 0);
    $sourceInst = $input['from_institution'] ?? $input['source_institution'] ?? null;
    $destInst = $input['to_institution'] ?? $input['destination_institution'] ?? null;
    $swapType = $input['swap_type'] ?? 'CASHOUT';
    $sourceCurrency = $input['currency'] ?? $currency;
    $destinationCurrency = $input['destination_currency'] ?? $sourceCurrency;
    
    if (!$sourceInst || !$destInst || $amount <= 0) {
        throw new Exception("Missing required fields: from_institution, to_institution, amount");
    }
    
    // Create fee payload
    $feePayload = [
        'amount' => $amount,
        'currency' => $sourceCurrency,
        'destination_currency' => $destinationCurrency,
        'from_institution' => $sourceInst,
        'to_institution' => $destInst,
        'source_institution' => $sourceInst,
        'destination_institution' => $destInst,
        'swap_type' => $swapType,
        'client_tier' => $input['client_tier'] ?? 'retail'
    ];
    
    // Build fee service and forex service manually
    $forexService = new \Domain\Services\ForexService(
        $db,
        $settings,
        $participants
    );
    
    $feeService = new \Domain\Services\FeeService(
        $feesConfig,
        $settings,
        $currency,
        $forexService
    );
    $feeService->setParticipants($participants);
    
    // Calculate fees
    $feeResult = $feeService->calculateFees($swapType, $amount, $feePayload);
    
    $totalFee = $feeResult['total_fee'] ?? 0;
    $netAmount = $feeResult['net_amount'] ?? $amount;
    $netAmountSourceCurrency = $feeResult['net_amount_source_currency'] ?? $amount;
    $netAmountDestCurrency = $feeResult['net_amount_destination_currency'] ?? $amount;
    
    // Get forex details
    $forexApplied = $feeResult['forex']['applied'] ?? false;
    $exchangeRate = $feeResult['forex']['rate'] ?? 1.0;
    $forexProfit = $feeResult['forex']['vouchmorph_profit'] ?? 0;
    
    // Get breakdown
    $breakdown = $feeResult['breakdown'] ?? [];
    
    // Get destination split details
    $destinationSplit = $feeResult['destination_split'] ?? null;
    $generateCodeFee = $destinationSplit['generate_code_fee'] ?? 0;
    $cashoutCompletionFee = $destinationSplit['cashout_completion_fee'] ?? 0;
    
    // Build preview response
    $preview = [
        'success' => true,
        'preview' => [
            'swap_type' => $swapType,
            'source_institution' => $sourceInst,
            'destination_institution' => $destInst,
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            
            // Amounts
            'amount_requested' => $amount,
            'total_fee' => $totalFee,
            'net_amount' => $netAmount,
            'net_amount_source_currency' => $netAmountSourceCurrency,
            'net_amount_destination_currency' => $netAmountDestCurrency,
            
            // Forex
            'forex_applied' => $forexApplied,
            'exchange_rate' => $exchangeRate,
            'forex_profit' => $forexProfit,
            
            // Fee breakdown
            'fee_breakdown' => $breakdown,
            
            // Destination split
            'destination_split' => $destinationSplit ? [
                'generate_code_fee' => $generateCodeFee,
                'cashout_completion_fee' => $cashoutCompletionFee
            ] : null,
            
            // Summary for display
            'summary' => [
                'amount_requested_formatted' => number_format($amount, 2) . ' ' . $sourceCurrency,
                'total_fee_formatted' => number_format($totalFee, 2) . ' ' . $sourceCurrency,
                'net_amount_formatted' => number_format($netAmountDestCurrency, 2) . ' ' . $destinationCurrency,
                'exchange_rate_formatted' => $forexApplied ? "1 {$sourceCurrency} = {$exchangeRate} {$destinationCurrency}" : 'N/A'
            ]
        ]
    ];
    
    echo json_encode($preview);
    
} catch (Exception $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    http_response_code($code);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    
    error_log("[PREVIEW] Error: " . $e->getMessage());
}
