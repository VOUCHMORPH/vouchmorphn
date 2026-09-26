<?php
declare(strict_types=1);

/**
 * VouchMorph Card Network - Hook Sources Endpoint
 *
 * The user-facing "press hook" action. Places a real, all-or-nothing hold
 * across every source in the request. "Hook successful" only ever means
 * every single source is genuinely held - a partial hold state can never
 * be returned as success.
 *
 * This can take as long as it needs (no card-swipe SLA here) since nothing
 * is waiting at a merchant terminal - it's the user tapping a button.
 *
 * AUTH FIX: previously required BOTH SessionManager login AND an
 * X-API-Key header matching a bank participant's machine credential.
 * The X-API-Key check belongs on machine-to-machine endpoints (a bank
 * calling in, an ATM callback) - not here, where the caller is the
 * user's own browser after logging in, exactly like Activate.php and
 * My.php. The frontend never sends X-API-Key on this call (see
 * buildHeaders() in the dashboard), so that check could never pass -
 * this wasn't a missing key on the client side, it was a stray check
 * that didn't belong on this endpoint. Removed; session auth alone is
 * the correct and sufficient boundary here.
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Domain\Services\CardService;
use Application\Utils\SessionManager;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
// An admin session's id is an admin_id, which can equal some customer's
// user_id - never let it act on a customer's sources.
if (!SessionManager::isUser()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only a customer can hook sources to a card.']);
    exit();
}

// ============================================================
// INPUT
// ============================================================
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload: ' . json_last_error_msg()]);
    exit();
}

if (empty($input['card_suffix'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'card_suffix is required']);
    exit();
}

if (empty($input['sources']) || !is_array($input['sources']) || count($input['sources']) < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'At least one source is required in sources[]']);
    exit();
}

// Every hooked source belongs to the signed-in user, who is the only person
// who can put it on a card - their own card or, through its QR or suffix,
// someone else's. owner_user_id used to be read from each source in this
// body (defaulting to the card owner), so a request could hook any account
// number and name anyone as its owner. The owner now comes from the session
// alone, and CardService::hookSourcesToCard() refuses any source that is
// not one of this user's verified sources (SourceOwnershipGuard).
$requestingUserId = (int)(SessionManager::getUser()['user_id'] ?? 0);
if (!$requestingUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not resolve the signed-in user from the session']);
    exit();
}

$sources = [];
foreach ($input['sources'] as $idx => $src) {
    if (!is_array($src)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "sources[{$idx}] must be an object"]);
        exit();
    }
    foreach (['institution', 'asset_type', 'identifier'] as $field) {
        if (empty($src[$field]) || !is_scalar($src[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "sources[{$idx}].{$field} is required"]);
            exit();
        }
    }
    if (!isset($src['authorized_amount']) || !is_numeric($src['authorized_amount']) || (float)$src['authorized_amount'] <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "sources[{$idx}].authorized_amount is required and must be greater than zero"]);
        exit();
    }
    if (isset($src['owner_user_id']) && (int)$src['owner_user_id'] !== $requestingUserId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You can only hook your own sources. The owner of that account has to hook it themselves.']);
        exit();
    }
    // Only these fields go on to the institution. Anything else in the body
    // (access tokens, source references, SwapService's internal "_" flags)
    // is dropped rather than forwarded with the hold.
    $sources[] = array_filter([
        'institution' => (string)$src['institution'],
        'asset_type' => (string)$src['asset_type'],
        'identifier' => trim((string)$src['identifier']),
        'identifier_type' => isset($src['identifier_type']) && is_scalar($src['identifier_type']) ? (string)$src['identifier_type'] : null,
        'currency' => isset($src['currency']) && is_scalar($src['currency']) ? (string)$src['currency'] : null,
        'pin' => isset($src['pin']) && is_scalar($src['pin']) ? (string)$src['pin'] : null,
        'wallet_pin' => isset($src['wallet_pin']) && is_scalar($src['wallet_pin']) ? (string)$src['wallet_pin'] : null,
        'voucher_pin' => isset($src['voucher_pin']) && is_scalar($src['voucher_pin']) ? (string)$src['voucher_pin'] : null,
    ], fn($value) => $value !== null && $value !== '') + [
        'owner_user_id' => $requestingUserId,
        'authorized_amount' => (float)$src['authorized_amount'],
    ];
}

// ============================================================
// EXECUTE
// ============================================================
$db = $container->get(PDO::class);

// Incident Command gate (2026-09-21): freezes on the service, the CARD_HOOK
// flow, the card owner or any source institution stop the hook before any
// hold; the cap on the card's pool total is enforced in CardService.
require_once ROOT_PATH . '/src/Application/Admin/AdminAudit.php';
require_once ROOT_PATH . '/src/Application/Incident/Playbooks.php';
require_once ROOT_PATH . '/src/Application/Incident/ReportBuilder.php';
require_once ROOT_PATH . '/src/Application/Incident/IncidentDesk.php';
require_once ROOT_PATH . '/src/Application/Incident/ServiceControls.php';
foreach ($sources as $gateSource) {
    $gate = \Application\Incident\ServiceControls::check($db, [
        'amount' => 0, 'flow' => 'CARD_HOOK', 'source' => $gateSource['institution'] ?? '', 'user_id' => (string)$requestingUserId,
    ]);
    if ($gate !== null) {
        http_response_code($gate['http']);
        echo json_encode(['success' => false, 'error' => $gate['message'], 'code' => $gate['code'], 'funds_moved' => false]);
        exit;
    }
}

$swapService = $container->get('Domain\Services\SwapService');
$cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));

try {
    $result = $cardService->hookSourcesToCard(
        $input['card_suffix'],
        $sources,
        $swapService,
        $requestingUserId
    );

    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[CardHook] Hook failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => 'Hook failed due to a system error - no sources were held',
    ]);
}
