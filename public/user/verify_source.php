<?php
/**
 * Verify source with OTP
 * POST /api/v1/user/verify_source.php
 * 
 * Request body:
 * {
 *   "attempt_id": 123,
 *   "otp": "123456"
 * }
 * 
 * Response:
 * {
 *   "id": 456,
 *   "status": "active",
 *   "message": "Ownership verified"
 * }
 */

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../src/Services/SwapService.php';

use VouchMorph\Services\SwapService;

header('Content-Type: application/json');

try {
    // Authenticate user
    $userId = authenticateUser();
    
    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new RuntimeException("Invalid JSON input");
    }
    
    if (empty($input['attempt_id']) || empty($input['otp'])) {
        throw new RuntimeException("Missing required fields: attempt_id, otp");
    }
    
    $attemptId = (int)$input['attempt_id'];
    $otp = trim($input['otp']);
    
    // Initialize SwapService
    $swapService = new SwapService();
    
    // Complete registration
    $result = $swapService->completeUserSourceRegistration($userId, $attemptId, $otp);
    
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
