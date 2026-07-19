<?php
// api/v1/agent/search_claim.php - Agent looks up a pending identity swap for a client
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

    // Gate: only approved agents may search identities at all.
    if (!$swapService->isApprovedAgent((int)$userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have an approved agent destination account yet.']);
        exit;
    }

    $pending = $swapService->getPendingIdentitySwaps($identityType, $identityValue, 'pending');

    // Never leak PIN hashes, raw source_payload, or metadata to the client.
    $safe = array_map(function ($s) {
        return [
            'hold_id' => $s['hold_id'] ?? null,
            'swap_reference' => $s['swap_reference'] ?? null,
            'amount' => $s['amount'] ?? null,
            'currency' => $s['currency'] ?? null,
            'source_institution' => $s['source_institution'] ?? null,
            'hold_expires_at' => $s['hold_expires_at'] ?? null,
            'created_at' => $s['created_at'] ?? null,
        ];
    }, $pending);

    echo json_encode(['success' => true, 'data' => $safe]);
} catch (\Throwable $e) {
    error_log("[agent/search_claim] Error for user {$userId}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Search failed']);
}
