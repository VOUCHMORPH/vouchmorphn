<?php
// api/v1/agent/status.php - This user's agent status + approved/pending destinations
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$userData = SessionManager::getUser();
$userId = $userData['id'] ?? $userData['user_id'] ?? null;

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session has no user id']);
    exit;
}

require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

try {
    $db = DBConnection::getConnection();
    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    $approved = $swapService->getApprovedAgentDestinations((int)$userId);

    // Also fetch pending/rejected ones directly, since SwapService
    // only exposes the approved list (used for pre-fill) - the
    // "My Agent Accounts" screen needs the full picture.
    $stmt = $db->prepare("
        SELECT id, institution, asset_type, identifier, identifier_type,
               account_name, account_type, status, rejection_reason,
               proposed_at, confirmed_at
        FROM agent_destination_accounts
        WHERE user_id = :user_id AND deleted_at IS NULL
        ORDER BY
            CASE WHEN status = 'pending_confirmation' THEN 1
                 WHEN status = 'active' THEN 2
                 ELSE 3 END,
            proposed_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'is_agent' => count($approved) > 0,
            'approved_destinations' => $approved,
            'all_destinations' => $all,
        ]
    ]);
} catch (\Throwable $e) {
    error_log("[agent/status] Error for user {$userId}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load agent status']);
}
