<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Domain/Models/Permission.php';

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

    // FIX: Don't trust a 'role'/'is_agent' field on the session object —
    // SessionManager is a pure passthrough of whatever was set at login,
    // and agent status can change after login. Look it up fresh from
    // the database every time using the reliable part of the session:
    // the user_id.
    $isAgent = false;

    $stmt = $db->prepare("
        SELECT 1 FROM agent_destination_accounts
        WHERE user_id = :id AND status = 'active' AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    if ($stmt->fetchColumn()) {
        $isAgent = true;
    }

    $roleId = null;
    if (!$isAgent) {
        $stmt = $db->prepare("SELECT role_id FROM users WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        $roleId = $stmt->fetchColumn();
        if ($roleId) {
            $permission = new \Permission($db);
            foreach ($permission->getByRole((int)$roleId) as $p) {
                if (($p['name'] ?? '') === 'register_identity_owner') {
                    $isAgent = true;
                    break;
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'is_agent' => $isAgent,
    ]);

} catch (Exception $e) {
    error_log("whoami.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to determine user role']);
}
