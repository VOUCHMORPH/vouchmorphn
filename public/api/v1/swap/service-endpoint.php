<?php
// public/api/v1/swap/service-endpoint.php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use Domain\Services\SwapService;
use Infrastructure\Database\DBConnection;
use Infrastructure\Session\SessionManager;

header('Content-Type: application/json');

// Disable error output to ensure clean JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Global exception handler
set_exception_handler(function (Throwable $e) {
    error_log("[SWAP_ENDPOINT] Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Internal System Error",
        "trace_id" => bin2hex(random_bytes(8))
    ]);
    exit;
});

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

// Authentication
SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

// Parse input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Malformed JSON"]);
    exit;
}

// Validate required fields for executeSwap
$required = ['source', 'destination'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
        exit;
    }
}

// Validate source has required sub-fields
if (empty($data['source']['institution']) || empty($data['source']['asset_type']) || empty($data['source']['amount'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Source must have institution, asset_type, and amount"]);
    exit;
}

// Validate destination has institution
if (empty($data['destination']['institution'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Destination must have institution"]);
    exit;
}

// Load country configuration
$country = getenv('SYSTEM_COUNTRY') ?: 'BW';
$configPath = __DIR__ . '/../../../config/countries/' . strtolower($country) . '/participants.json';

if (!file_exists($configPath)) {
    // Fallback to Botswana
    $configPath = __DIR__ . '/../../../config/countries/botswana/participants.json';
}

$participants = json_decode(file_get_contents($configPath), true);
$feesConfig = json_decode(file_get_contents(dirname($configPath) . '/fees.json'), true);

// Initialize database
$dbConfig = require __DIR__ . '/../../../src/Core/Database/db_config.php';
$swapDB = new PDO(
    "pgsql:host={$dbConfig['host']};dbname={$dbConfig['database']}",
    $dbConfig['user'],
    $dbConfig['password']
);
$swapDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialize SwapService
$settings = [];
$encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-test-key-32-chars-long!!!!';

$combinedConfig = [
    'participants' => $participants,
    'fees' => $feesConfig,
    'card_config' => json_decode(file_get_contents(dirname($configPath) . '/cards.json'), true),
    'atm_notes' => json_decode(file_get_contents(dirname($configPath) . '/atm_notes.json'), true)
];

$swapService = new SwapService($swapDB, $settings, $country, $encryptionKey, $combinedConfig);

// Execute swap
try {
    $result = $swapService->executeSwap($data);
    
    $httpCode = ($result['status'] === 'success') ? 200 : 400;
    http_response_code($httpCode);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    error_log("[SWAP_ENDPOINT] Execution error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage(),
        "swap_reference" => $result['swap_reference'] ?? null
    ]);
}
