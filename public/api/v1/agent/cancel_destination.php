<?php
// /api/v1/agent/cancel_destination.php

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../Application/Utils/SessionManager.php';

use Application\Utils\SessionManager;
use Infrastructure\Database\DBConnection;
use Domain\Services\SwapService;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userData = SessionManager::getUser();
$userId = $userData['id'] ?? $userData['user_id'] ?? 0;

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user session']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$destinationId = $input['destination_id'] ?? 0;

if (!$destinationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'destination_id required']);
    exit;
}

try {
    $db = DBConnection::getInstance();
    $swapService = new SwapService($db, [], 'Botswana');
    
    $result = $swapService->cancelAgentDestination($userId, $destinationId);
    
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
