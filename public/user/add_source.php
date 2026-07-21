<?php
/**
 * Add a source account for an individual user
 * POST /api/v1/user/add_source.php
 * 
 * Request body:
 * {
 *   "institution": "BankName",
 *   "asset_type": "ACCOUNT|WALLET|BANK-WALLET|CARD|VOUCHER",
 *   "identifier": "account_number_or_phone_or_card",
 *   "account_name": "Optional account nickname"
 * }
 * 
 * Response:
 * {
 *   "requires_otp": true/false,
 *   "requires_redirect": true/false,
 *   "attempt_id": 123,
 *   "redirect_url": "https://bank.com/oauth",
 *   "method": "sms|email",
 *   "message": "Description of next step"
 * }
 */

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

header('Content-Type: application/json');

try {
    // ============================================================
    // Use SessionManager for authentication
    // ============================================================
    SessionManager::start();
    
    if (!SessionManager::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $userData = SessionManager::getUser();
    $userId = $userData['id'] ?? $userData['user_id'] ?? null;
    
    if (empty($userId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Session has no user id']);
        exit;
    }
    
    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new RuntimeException("Invalid JSON input");
    }
    
    $required = ['institution', 'asset_type', 'identifier'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new RuntimeException("Missing required field: {$field}");
        }
    }
    
    $institution = trim($input['institution']);
    $assetType = strtoupper(trim($input['asset_type']));
    $identifier = trim($input['identifier']);
    $accountName = $input['account_name'] ?? null;
    
    // ============================================================
    // FIX: Determine identifier type based on asset_type
    // ============================================================
    $identifierType = match($assetType) {
        'WALLET', 'BANK-WALLET' => 'phone',
        'CARD' => 'card_number',
        'ACCOUNT' => 'account_number',
        default => 'account_number'
    };
    
    // Initialize database and config
    $db = DBConnection::getConnection();
    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $config = LoadCountry::getConfig();
    
    // Initialize SwapService
    $swapService = new SwapService($db, $config, $country);
    
    // Initiate registration - pass the identifier type
    $result = $swapService->initiateUserSourceRegistration(
        $userId,
        $institution,
        $assetType,
        $identifier,
        $identifierType,  // <-- FIXED: Pass string, not null
        $accountName
    );
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
