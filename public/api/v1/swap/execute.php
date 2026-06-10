<?php
declare(strict_types=1);

/**
 * VouchMorphn - Swap Execution API
 * ZERO HARDCODING - Routes to SwapService
 */

use Core\Database\DBConnection;  // ← MOVED HERE (top of file)

// ============================================
// 1. BOOTSTRAP
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

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
// 2. DYNAMIC API KEY LOADER
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

// ============================================
// 3. MAIN EXECUTION
// ============================================

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed. Use POST.'
        ]);
        exit();
    }
    
    $providedKey = getApiKeyFromRequest();
    $validKeys = getAllApiKeysFromEnvironment();
    
    if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid API key'
        ]);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON payload', 400);
    }
    
    $headers = getallheaders();
    $headersLower = array_change_key_case($headers ?: [], CASE_LOWER);
    $countryCode = $headersLower['x-country-code'] ?? $headersLower['x-country'] ?? $input['country'] ?? null;
    
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
    
    // ============================================================
    // DATABASE CONNECTION - Using DBConnection class
    // ============================================================
    require_once ROOT_PATH . '/src/Core/Database/DBConnection.php';
    
    try {
        $db = DBConnection::getConnection();  // Now works because use statement is at top
        
        if (!$db) {
            throw new Exception("Database connection failed - DATABASE_URL not set or invalid");
        }
        
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        error_log("[EXECUTE] Database connected successfully via DBConnection");
        
    } catch (Throwable $e) {
        error_log("[EXECUTE] DB ERROR: " . $e->getMessage());
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
    
    $composerPath = ROOT_PATH . '/vendor/autoload.php';
    if (file_exists($composerPath)) {
        require_once $composerPath;
    }
    
    if (class_exists('Domain\Services\SwapService')) {
        $settings = [];
        $configFile = ROOT_PATH . '/' . $countryConfig['config_path'] . '/config.php';
        if (file_exists($configFile)) {
            $settings = require $configFile;
        }
        
        $swapService = new \Domain\Services\SwapService(
            $db,                           // PDO (now properly connected)
            $settings,                     // array config
            $countryConfig['code']         // string country
        );
        
        $result = $swapService->executeSwap($input);
        
        echo json_encode([
            'success' => true,
            'status' => $result['status'] ?? 'completed',
            'swap_reference' => $result['reference'] ?? $result['swap_reference'] ?? null,
            'data' => $result
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'status' => 'validated',
            'message' => 'Request validated successfully',
            'swap_reference' => 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('YmdHis')
        ]);
    }
    
} catch (Exception $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    http_response_code($code);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    
    error_log("[Execute] Error: " . $e->getMessage());
}
