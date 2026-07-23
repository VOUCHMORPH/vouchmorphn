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

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';

use VouchMorph\Services\SwapService;

header('Content-Type: application/json');

try {
    // ============================================================
    // AUTHENTICATION: Get user_id from session or request
    // ============================================================
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Get user_id from session (logged-in user)
    $userId = $_SESSION['user_id'] ?? null;
    
    // If not in session, check request body (API calls)
    if (!$userId) {
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $input['user_id'] ?? null;
    }
    
    // If still no userId, check Authorization header for JWT
    if (!$userId) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            // Validate JWT token (you'll need to implement this)
            // $userId = validateJwtToken($token);
            // For now, fall back to a simple check
            error_log("[verify_source] JWT authentication not implemented - using user_id from request");
        }
    }
    
    // If still no userId, throw error
    if (!$userId) {
        throw new RuntimeException("Authentication required. Please log in.");
    }
    
    $userId = (int)$userId;
    
    // ============================================================
    // Get and validate input
    // ============================================================
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new RuntimeException("Invalid JSON input");
    }
    
    if (empty($input['attempt_id']) || empty($input['otp'])) {
        throw new RuntimeException("Missing required fields: attempt_id, otp");
    }
    
    $attemptId = (int)$input['attempt_id'];
    $otp = trim($input['otp']);
    
    // ============================================================
    // Initialize SwapService with database and config
    // ============================================================
    
    // Get PDO connection from bootstrap
    global $swapDB;
    if (!$swapDB) {
        throw new RuntimeException("Database connection not available");
    }
    
    // Get country configuration
    $country = getenv('VOUCHMORPH_COUNTRY') ?: 'BW';
    $config = \Core\Config\LoadCountry::getConfig();
    
    // Initialize SwapService
    $swapService = new SwapService($swapDB, $config, $country);
    
    // ============================================================
    // Complete the source registration
    // ============================================================
    // Use the completeUserSourceRegistration method from SwapService
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
