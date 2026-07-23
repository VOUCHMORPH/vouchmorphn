<?php
/**
 * Admin-only: grant or revoke agent status for a user.
 *
 * POST admin/set_agent_status.php
 * Body: { target_user_id: 12, is_agent: true }
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;

header('Content-Type: application/json');

try {
    SessionManager::start();
    if (!SessionManager::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    $actingUser = SessionManager::getUser();
    $actingUserId = (int)($actingUser['id'] ?? $actingUser['user_id'] ?? 0);

    $db = DBConnection::getConnection();

    $stmt = $db->prepare("SELECT is_admin FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $actingUserId]);
    $isAdmin = (bool)$stmt->fetchColumn();

    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $targetUserId = (int)($input['target_user_id'] ?? 0);
    $makeAgent = filter_var($input['is_agent'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if (!$targetUserId || $makeAgent === null) {
        echo json_encode(['success' => false, 'message' => 'target_user_id and is_agent (true/false) are required.']);
        exit;
    }

    if ($makeAgent) {
        $stmt = $db->prepare("
            UPDATE users
            SET is_agent = true,
                agent_approved_by = :actor,
                agent_approved_at = NOW(),
                agent_revoked_by = NULL,
                agent_revoked_at = NULL
            WHERE user_id = :target
        ");
    } else {
        $stmt = $db->prepare("
            UPDATE users
            SET is_agent = false,
                agent_revoked_by = :actor,
                agent_revoked_at = NOW()
            WHERE user_id = :target
        ");
    }
    $stmt->execute([':actor' => $actingUserId, ':target' => $targetUserId]);

    error_log("[set_agent_status] Admin user_id={$actingUserId} set is_agent={$makeAgent} for user_id={$targetUserId}");

    echo json_encode([
        'success' => true,
        'message' => $makeAgent ? 'Agent status granted.' : 'Agent status revoked.',
        'target_user_id' => $targetUserId,
        'is_agent' => $makeAgent,
    ]);

} catch (Exception $e) {
    error_log("set_agent_status.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update agent status.']);
}
