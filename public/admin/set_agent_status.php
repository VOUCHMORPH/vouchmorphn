<?php
/**
 * Admin-only: grant or revoke agent role for a user.
 *
 * POST /api/v1/admin/set_agent_status.php
 * Body: { target_user_id: 12, is_agent: true }
 *
 * Uses the single roles table as the source of truth - no separate
 * is_agent/is_admin booleans. Granting = set users.role_id to the
 * 'agent' role. Revoking = set it back to the plain 'user' role.
 */
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';

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

    // Admin check via the roles join - matches whoami.php's logic
    $stmt = $db->prepare("
        SELECT r.role_name
        FROM users u
        JOIN roles r ON r.role_id = u.role_id
        WHERE u.user_id = :id
    ");
    $stmt->execute([':id' => $actingUserId]);
    $actingRole = $stmt->fetchColumn();

    if (!in_array($actingRole, ['admin', 'super_admin'], true)) {
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

    // Look up the fixed role_ids for 'agent' and 'user' rather than
    // hardcoding numbers, so this survives a future roles reseed.
    $stmt = $db->prepare("SELECT role_id FROM roles WHERE role_name = :name LIMIT 1");
    $stmt->execute([':name' => $makeAgent ? 'agent' : 'user']);
    $newRoleId = $stmt->fetchColumn();

    if (!$newRoleId) {
        echo json_encode(['success' => false, 'message' => "Role '" . ($makeAgent ? 'agent' : 'user') . "' not found in roles table. Has it been created?"]);
        exit;
    }

    // Don't silently downgrade someone who currently holds a
    // different elevated platform role (admin, super_admin, etc.)
    // when revoking agent status - only touch it if they're
    // currently 'agent' or plain 'user'.
    $stmt = $db->prepare("
        SELECT r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :id
    ");
    $stmt->execute([':id' => $targetUserId]);
    $targetCurrentRole = $stmt->fetchColumn();

    if ($targetCurrentRole === false) {
        echo json_encode(['success' => false, 'message' => 'Target user not found.']);
        exit;
    }

    if (!$makeAgent && !in_array($targetCurrentRole, ['agent', 'user'], true)) {
        echo json_encode(['success' => false, 'message' => "Target user's current role is '{$targetCurrentRole}', not 'agent' - refusing to overwrite an unrelated elevated role. Change it manually if intended."]);
        exit;
    }

    $stmt = $db->prepare("UPDATE users SET role_id = :role_id WHERE user_id = :target");
    $stmt->execute([':role_id' => $newRoleId, ':target' => $targetUserId]);

    error_log("[set_agent_status] Admin user_id={$actingUserId} set role_id={$newRoleId} (" . ($makeAgent ? 'agent' : 'user') . ") for user_id={$targetUserId}");

    echo json_encode([
        'success' => true,
        'message' => $makeAgent ? 'Agent role granted.' : 'Agent role revoked (reverted to plain user).',
        'target_user_id' => $targetUserId,
        'is_agent' => $makeAgent,
    ]);

} catch (Exception $e) {
    error_log("set_agent_status.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update role.']);
}
