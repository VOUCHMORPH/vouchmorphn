<?php
declare(strict_types=1);

/**
 * VouchMorph - KYC Document Upload API
 */

define('ROOT_PATH', dirname(__DIR__, 5));

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

// Bootstrap - load container
$container = require_once ROOT_PATH . '/src/bootstrap.php';

// Load system config
require_once ROOT_PATH . '/src/Core/Config/SystemCountry.php';
require_once ROOT_PATH . '/src/Core/Config/LoadCountry.php';
$country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW';

// Load required classes
require_once ROOT_PATH . '/src/Core/Database/DBConnection.php';
require_once ROOT_PATH . '/src/Domain/Services/KYCDocumentService.php';

use Core\Database\DBConnection;
use Domain\Services\KYCDocumentService;

// Load environment
$envFile = ROOT_PATH . "/src/Core/Config/Countries/{$country}/.env_{$country}";
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('get_env_val')) {
    function get_env_val(string $key) {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? ($_SERVER[$key] ?? null);
        }
        return $val;
    }
}

// Authentication
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

$validKeys = array_filter([get_env_val('API_KEY_SYSTEM')]);
if (!$providedKey || !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Handle file upload
if (empty($_FILES['document'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No document uploaded']);
    exit();
}

$applicationId = $_POST['application_id'] ?? '';
$documentType = $_POST['document_type'] ?? '';

if (!$applicationId || !$documentType) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'application_id and document_type required']);
    exit();
}

// Database connection
try {
    $pdo = DBConnection::getConnection();
    if (!$pdo) throw new Exception('Database connection failed');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit();
}

// Process upload
try {
    $kycService = new KYCDocumentService($pdo);
    $result = $kycService->processUpload($applicationId, $documentType, $_FILES['document']);
    
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $result]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
