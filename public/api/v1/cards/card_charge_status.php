<?php
declare(strict_types=1);

// ⚠ Adjust these includes to match whatever your other /api/v1/agent/*.php
// files use (e.g. status.php, finalize_claim.php) — this needs the same
// country/DB bootstrap they have, which I can't see from the frontend.
require_once __DIR__ . '/../../../src/Application/Utils/SessionManager.php';
// require_once __DIR__ . '/../../../src/Core/Bootstrap.php';

use Application\Utils\SessionManager;

header('Content-Type: application/json');
SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user = SessionManager::getUser();
$agentUserId = $user['id'] ?? $user['user_id'] ?? null;

if (empty($agentUserId)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not identify your account']);
    exit();
}

// ⚠ Confirm this matches however status.php currently checks is_agent —
// mirror that exact check rather than reinventing it here.
if (empty($user['is_agent'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Agent account required']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$cardSuffix = trim((string)($input['card_suffix'] ?? ''));
$destinationAccountId = $input['destination_account_id'] ?? null;

if ($cardSuffix === '' || empty($destinationAccountId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'card_suffix and destination_account_id are required']);
    exit();
}

try {
    // ⚠ Replace with however your other endpoints get their PDO instance —
    // e.g. DBConnection::getInstance() based on your log output.
    $db = null; // <-- fix this

    // ---- 1. Card + owner lookup ----
    // ⚠ Table/column names below are placeholders — match your real schema
    // (whatever My.php / GetCardSources.php already query against).
    $stmt = $db->prepare("SELECT id, user_id FROM vouchmorph_cards WHERE card_suffix = :suffix LIMIT 1");
    $stmt->execute(['suffix' => $cardSuffix]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Card not found']);
        exit();
    }

    if ((string)$card['user_id'] === (string)$agentUserId) {
        echo json_encode(['success' => true, 'data' => ['code' => 'SELF_CARD']]);
        exit();
    }

    // ---- 2. Active, unexpired hooked sources ----
    $stmt = $db->prepare("
        SELECT institution, identifier, asset_type, authorized_amount, available_balance, currency
        FROM card_hooks
        WHERE card_id = :card_id
          AND status = 'active'
          AND expires_at > NOW()
    ");
    $stmt->execute(['card_id' => $card['id']]);
    $hookedSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($hookedSources)) {
        echo json_encode(['success' => true, 'data' => ['code' => 'NO_HOOK']]);
        exit();
    }

    // ---- 3. Destination lookup + filter exact-match sources ----
    $stmt = $db->prepare("
        SELECT institution, identifier
        FROM agent_destination_accounts
        WHERE id = :id AND agent_user_id = :agent_user_id AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute(['id' => $destinationAccountId, 'agent_user_id' => $agentUserId]);
    $destination = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$destination) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid or unapproved destination account']);
        exit();
    }

    $normalize = fn($v) => strtolower(preg_replace('/[^0-9a-zA-Z]/', '', (string)$v));
    $destInst = strtoupper((string)$destination['institution']);
    $destIdent = $normalize($destination['identifier']);

    $eligible = array_values(array_filter($hookedSources, function ($s) use ($destInst, $destIdent, $normalize) {
        $sameInst = strtoupper((string)$s['institution']) === $destInst;
        $sameIdent = $normalize($s['identifier']) === $destIdent;
        return !($sameInst && $sameIdent); // exclude ONLY exact same-account matches; same bank + different account stays eligible
    }));

    if (empty($eligible)) {
        echo json_encode(['success' => true, 'data' => ['code' => 'ONLY_SOURCE_IS_DESTINATION']]);
        exit();
    }

    // ---- 4. OK — return only the aggregate, never individual account details ----
    $available = array_sum(array_map(
        fn($s) => (float)($s['available_balance'] ?? $s['authorized_amount'] ?? 0),
        $eligible
    ));
    $currency = $eligible[0]['currency'] ?? 'BWP';

    echo json_encode([
        'success' => true,
        'data' => [
            'code' => 'OK',
            'available' => $available,
            'currency' => $currency,
        ],
    ]);

} catch (\Throwable $e) {
    error_log('[card_charge_status] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong checking that card']);
}
