<?php
// api/v1/agent/search_claim.php - Agent looks up a client's identity by aggregated balance
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';
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
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$identityType = strtolower(trim($body['identity_type'] ?? ''));
$identityValue = trim($body['identity_value'] ?? '');

// Must stay in sync with SwapService::IDENTITY_TYPES_AGENT_VERIFIABLE -
// agents can only handle document types they can physically inspect,
// never phone/email (those are self-service only).
$agentVerifiableTypes = ['national_id', 'birth_certificate', 'voter_id'];
if (!in_array($identityType, $agentVerifiableTypes, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Agents can only search document-based identities (National ID, Birth Certificate, Voter ID).'
    ]);
    exit;
}
if ($identityValue === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Identity value is required']);
    exit;
}
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../../../src/Core/Config/LoadCountry.php';
use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;
try {
    $db = DBConnection::getConnection();
    $country = $userData['country'] ?? getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    // Gate: only approved agents may search identities at all.
    if (!$swapService->isApprovedAgent((int)$userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have an approved agent destination account yet.']);
        exit;
    }

    // ============================================================
    // CHANGED: aggregated lookup instead of one row per swap.
    // Returns null (no pending balance) or a single grouped figure -
    // or, in the multi_currency edge case, a small list of per-currency
    // groups rather than one merged (and wrong) total.
    // ============================================================
    $aggregate = $swapService->getAggregatedIdentityBalance($identityType, $identityValue);

    if ($aggregate === null) {
        echo json_encode(['success' => true, 'data' => null, 'message' => 'No pending balance found for this identity.']);
        exit;
    }

    // Never leak PIN hashes, raw source_payload, or metadata to the client.
    // hold_ids/swap_references are fine to expose - they're opaque
    // identifiers the agent never types, just references finalize_claim
    // will send back.
    if (!empty($aggregate['multi_currency'])) {
        $safe = [
            'multi_currency' => true,
            'identity_type' => $aggregate['identity_type'],
            'identity_value' => $aggregate['identity_value'],
            'balances' => array_map(function ($b) {
                return [
                    'currency' => $b['currency'],
                    'total_amount' => $b['total_amount'],
                    'swap_count' => $b['swap_count'],
                    'newest_created_at' => $b['newest_created_at'],
                    'earliest_expires_at' => $b['earliest_expires_at'],
                ];
            }, $aggregate['balances']),
        ];
    } else {
        $safe = [
            'multi_currency' => false,
            'identity_type' => $aggregate['identity_type'],
            'identity_value' => $aggregate['identity_value'],
            'currency' => $aggregate['currency'],
            'total_amount' => $aggregate['total_amount'],
            'swap_count' => $aggregate['swap_count'],
            'newest_created_at' => $aggregate['newest_created_at'],
            'earliest_expires_at' => $aggregate['earliest_expires_at'],
        ];
    }

    echo json_encode(['success' => true, 'data' => $safe]);
} catch (\Throwable $e) {
    error_log("[agent/search_claim] Error for user {$userId}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Search failed']);
}
