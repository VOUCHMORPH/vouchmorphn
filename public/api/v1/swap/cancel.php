<?php
declare(strict_types=1);

// POST /api/v1/swap/cancel
// {
//     "swap_reference": "6ba6bb1970b0b53f297ade7c86715966",
//     "reason": "User changed mind"
// }
//
// Only cancels swaps that are still 'pending' with no hold placed at any
// source institution yet. Once a hold exists, releasing it carries real
// fee-withholding implications (see SwapService::releaseCashoutHold) that
// a same-second user-initiated cancel should not silently trigger, so
// those cases are rejected with a clear message instead.

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/Application/Utils/SessionManager.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function isValidApiKey(?string $providedKey): bool
{
    $validKey = getenv('VOUCHMORPH_API_KEY') ?: '';
    if ($validKey === '' || $providedKey === null || $providedKey === '') {
        return false;
    }
    return hash_equals($validKey, $providedKey);
}

function getApiKeyFromRequest(): ?string
{
    $headers = getallheaders() ?: [];
    $headersLower = array_change_key_case($headers, CASE_LOWER);

    if (!empty($headersLower['x-api-key'])) {
        return $headersLower['x-api-key'];
    }
    if (!empty($headersLower['authorization'])) {
        $auth = $headersLower['authorization'];
        return strpos($auth, 'Bearer ') === 0 ? substr($auth, 7) : $auth;
    }
    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
        exit();
    }

    SessionManager::start();
    $sessionUserId = SessionManager::isLoggedIn()
        ? (int)(SessionManager::getUser()['id'] ?? SessionManager::getUser()['user_id'] ?? 0)
        : 0;

    $providedKey = getApiKeyFromRequest();
    $isPartnerAuth = isValidApiKey($providedKey);

    if (!$isPartnerAuth && !$sessionUserId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid API key']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['swap_reference'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'swap_reference required']);
        exit();
    }

    $swapRef = (string)$input['swap_reference'];
    $reason = (string)($input['reason'] ?? 'User requested cancellation');

    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare('SELECT swap_id, user_id, status FROM swap_requests WHERE swap_uuid = :ref');
    $stmt->execute([':ref' => $swapRef]);
    $swap = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$swap) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Swap not found']);
        exit();
    }

    if (!$isPartnerAuth && (int)$swap['user_id'] !== $sessionUserId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Not authorized to cancel this swap']);
        exit();
    }

    if (strtolower((string)$swap['status']) !== 'pending') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'This swap can no longer be self-cancelled. Please contact support.',
            'status' => $swap['status'],
        ]);
        exit();
    }

    $holdStmt = $db->prepare(
        "SELECT COUNT(*) FROM hold_transactions
         WHERE swap_reference = :ref
         AND status NOT IN ('RELEASED', 'CANCELLED', 'FAILED')"
    );
    $holdStmt->execute([':ref' => $swapRef]);
    if ((int)$holdStmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'A hold has already been placed on this swap. Please contact support.',
        ]);
        exit();
    }

    $update = $db->prepare(
        "UPDATE swap_requests
         SET status = 'cancelled',
             metadata = jsonb_set(COALESCE(metadata, '{}'::jsonb), '{cancellation_reason}', to_jsonb(:reason::text))
         WHERE swap_id = :id AND status = 'pending'"
    );
    $update->execute([':reason' => $reason, ':id' => $swap['swap_id']]);

    if ($update->rowCount() === 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Swap status changed before cancellation could complete. Please retry.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'swap_reference' => $swapRef,
        'status' => 'cancelled',
        'reason' => $reason,
    ]);

} catch (\Throwable $e) {
    error_log('[SWAP CANCEL] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error']);
}
