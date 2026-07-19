<?php
// api/v1/swap/pending_claims.php - Money waiting for the logged-in user
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
    echo json_encode(['success' => false, 'error' => 'Session has no user id', 'data' => []]);
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

    $claims = $swapService->getPendingClaimsForUser((int)$userId);

    // Never leak PIN hashes or full source payloads to the client.
    $safeClaims = array_map(function ($c) {
        return [
            'hold_id' => $c['hold_id'] ?? null,
            'swap_reference' => $c['swap_reference'] ?? null,
            'amount' => $c['amount'] ?? null,
            'currency' => $c['currency'] ?? null,
            'identity_type' => $c['identity_type'] ?? null,
            'identity_value' => $c['identity_value'] ?? null,
            'hold_expires_at' => $c['hold_expires_at'] ?? null,
            'created_at' => $c['created_at'] ?? null,
            'source_institution' => $c['source_institution'] ?? null,
            'claim_type' => $c['claim_type'] ?? 'account_pin',
        ];
    }, $claims);

    echo json_encode(['success' => true, 'data' => $safeClaims]);
} catch (\Throwable $e) {
    error_log("[pending_claims] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load pending claims', 'data' => []]);
}
