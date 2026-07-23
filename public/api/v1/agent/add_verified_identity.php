<?php
/**
 * Agent-assisted addition of a government-issued identity to an
 * EXISTING user's account (distinct from register_identity_owner.php,
 * which creates a brand-new account).
 *
 * POST /api/v1/agent/add_verified_identity.php
 * Body: { target_lookup: "phone or email", identity_type: "national_id", identity_value: "..." }
 */
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../../../src/Domain/Models/Permission.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

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

    // Permission check — approved agent OR explicit role permission
    $isAgent = false;
    $stmt = $db->prepare("SELECT 1 FROM agent_destination_accounts WHERE user_id = :id AND status = 'active' AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $actingUserId]);
    if ($stmt->fetchColumn()) {
        $isAgent = true;
    } else {
        $stmt = $db->prepare("SELECT role_id FROM users WHERE user_id = :id");
        $stmt->execute([':id' => $actingUserId]);
        $roleId = $stmt->fetchColumn();
        if ($roleId) {
            $permission = new \Permission($db);
            foreach ($permission->getByRole((int)$roleId) as $p) {
                if (($p['name'] ?? '') === 'register_identity_owner') { $isAgent = true; break; }
            }
        }
    }

    if (!$isAgent) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not authorized to register identities on behalf of others.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $targetLookup = trim($input['target_lookup'] ?? '');
    $identityType = strtolower(trim($input['identity_type'] ?? ''));
    $identityValue = trim($input['identity_value'] ?? '');

    if (!$targetLookup || !$identityType || !$identityValue) {
        echo json_encode(['success' => false, 'message' => 'target_lookup, identity_type, and identity_value are all required.']);
        exit;
    }

    // Find the target account by phone or email
    $stmt = $db->prepare("SELECT user_id FROM users WHERE phone = :v OR phone2 = :v OR phone3 = :v OR email = :v LIMIT 1");
    $stmt->execute([':v' => $targetLookup]);
    $targetUserId = $stmt->fetchColumn();

    if (!$targetUserId) {
        echo json_encode(['success' => false, 'message' => 'No VouchMorph account found for that phone number or email.']);
        exit;
    }

    $config = LoadCountry::getConfig();
    $country = $actingUser['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, $config, $country);

    $result = $swapService->addVerifiedIdentityAsAgent((int)$targetUserId, $identityType, $identityValue, $actingUserId);

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
