<?php
/**
 * Delete/remove a source account
 * DELETE /api/v1/user/sources/delete.php
 * 
 * Request body:
 * {
 *   "source_id": 456
 * }
 * 
 * Response:
 * {
 *   "success": true,
 *   "message": "Source account removed."
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
    
    if (empty($input['source_id'])) {
        throw new RuntimeException("Missing required field: source_id");
    }
    
    $sourceId = (int)$input['source_id'];
    
    // Initialize SwapService
    $swapService = new SwapService();
    
    // Cancel source
    $result = $swapService->cancelUserSourceAccount($userId, $sourceId);
    
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
