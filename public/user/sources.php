<?php
/**
 * List user's source accounts
 * GET /api/v1/user/sources.php
 * 
 * Response:
 * {
 *   "sources": [
 *     {
 *       "id": 456,
 *       "institution": "BankName",
 *       "asset_type": "ACCOUNT",
 *       "source_identifier": "1234567890",
 *       "account_name": "My Account",
 *       "currency": "BWP",
 *       "status": "active",
 *       "is_hooked": true,
 *       "confirmed_at": "2024-01-01 12:00:00"
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../src/Services/SwapService.php';

use VouchMorph\Services\SwapService;

header('Content-Type: application/json');

try {
    // Authenticate user
    $userId = authenticateUser();
    
    // Initialize SwapService
    $swapService = new SwapService();
    
    // Get sources
    $sources = $swapService->getUserSourceAccounts($userId);
    
    echo json_encode([
        'success' => true,
        'data' => ['sources' => $sources]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
