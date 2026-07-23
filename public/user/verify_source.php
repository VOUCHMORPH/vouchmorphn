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

use Domain\Services\SwapService;
use Core\Database\DBConnection;

header('Content-Type: application/json');

try {
    // ============================================================
    // AUTHENTICATION: API Key method (primary)
    // ============================================================
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new RuntimeException("Invalid JSON input");
    }
    
    // Get API key from header
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
    
    // Get user_id from request body
    $userId = $input['user_id'] ?? null;
    
    // If user_id not provided, try to get it from the attempt
    if (!$userId && isset($input['attempt_id'])) {
        $db = DBConnection::getConnection();
        if ($db) {
            $stmt = $db->prepare("
                SELECT user_id FROM user_source_registration_attempts 
                WHERE id = :id
            ");
            $stmt->execute([':id' => (int)$input['attempt_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $userId = (int)$result['user_id'];
                error_log("[verify_source] User from attempt: {$userId}");
            }
        }
    }
    
    // If still no userId, check session (for dashboard users)
    if (!$userId && session_status() === PHP_SESSION_NONE) {
        session_start();
        $userId = $_SESSION['user_id'] ?? null;
    }
    
    // If still no userId, throw error
    if (!$userId) {
        error_log("[verify_source] No user_id found. API Key: " . ($apiKey ? 'present' : 'missing'));
        throw new RuntimeException("Authentication required. Please provide user_id or log in.");
    }
    
    $userId = (int)$userId;
    
    // ============================================================
    // Validate input
    // ============================================================
    
    if (empty($input['attempt_id']) || empty($input['otp'])) {
        throw new RuntimeException("Missing required fields: attempt_id, otp");
    }
    
    $attemptId = (int)$input['attempt_id'];
    $otp = trim($input['otp']);
    
    error_log("[verify_source] Verifying: user_id={$userId}, attempt_id={$attemptId}, otp={$otp}");
    
    // ============================================================
    // Initialize SwapService with database and config
    // ============================================================
    
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new RuntimeException("Database connection failed");
    }
    
    $country = getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $config = \Core\Config\LoadCountry::getConfig();
    
    $swapService = new SwapService($db, $config, $country);
    
    // ============================================================
    // Complete the source registration
    // ============================================================
    $result = $swapService->completeUserSourceRegistration($userId, $attemptId, $otp);
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (Exception $e) {
    error_log("[verify_source] ERROR: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
