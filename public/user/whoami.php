<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;

header('Content-Type: application/json');

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = SessionManager::getUser();
$userId = (int)($user['id'] ?? $user['user_id'] ?? 0);

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session has no user id']);
    exit;
}

try {
    $db = DBConnection::getConnection();

    $stmt = $db->prepare("SELECT is_agent, is_admin FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'is_agent' => (bool)($row['is_agent'] ?? false),
        'is_admin' => (bool)($row['is_admin'] ?? false),
    ]);

} catch (Exception $e) {
    error_log("whoami.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to determine user role']);
}
