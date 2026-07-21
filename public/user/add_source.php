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

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Domain/Services/SwapService.php';

use VouchMorph\Services\SwapService;

header('Content-Type: application/json');

try {
    // Authenticate user
    $userId = authenticateUser(); // Your auth function
    
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
    
    // Initialize SwapService
    $swapService = new SwapService();
    
    // Initiate registration
    $result = $swapService->initiateUserSourceRegistration(
        $userId,
        $institution,
        $assetType,
        $identifier,
        null, // Will be auto-detected
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
        'message' => $e->getMessage()
    ]);
}
