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

// ============================================================
// AUTHENTICATION - same pattern as authorize.php
// ============================================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);
$providedKey = $headersLower['x-api-key'] ?? null;

$validKeys = array_filter([getenv('API_KEY_SYSTEM')]);
foreach ($container->get('participants') as $code => $participant) {
    $val = getenv('API_KEY_' . strtoupper($code));
    if ($val) $validKeys[] = $val;
}

if (!$providedKey || !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: invalid or missing API key']);
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

// Each source must identify itself and its owner. If owner_user_id is
// omitted, it defaults to the requesting card owner - this is the
// self-funded case. A THIRD-PARTY source (someone else's account funding
// this card) MUST explicitly carry its own owner_user_id AND be verified
// as consented separately - this endpoint does not itself collect that
// consent, it assumes upstream flows (source linking / hooking consent)
// already gated it. Flagging this because it's a real security boundary,
// not just a data field.
$cardOwnerUserId = (int)(SessionManager::getUser()['user_id'] ?? 0);
if (!$cardOwnerUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not resolve card owner from session']);
    exit();
}

$sources = [];
foreach ($input['sources'] as $idx => $src) {
    foreach (['institution', 'asset_type', 'identifier'] as $field) {
        if (empty($src[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "sources[{$idx}].{$field} is required"]);
            exit();
        }
    }
    $sources[] = array_merge($src, [
        'owner_user_id' => (int)($src['owner_user_id'] ?? $cardOwnerUserId),
    ]);
}

// ============================================================
// EXECUTE
// ============================================================
$db = $container->get(PDO::class);
$swapService = $container->get('Domain\Services\SwapService');
$cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));

try {
    $result = $cardService->hookSourcesToCard(
        $input['card_suffix'],
        $sources,
        $swapService,
        $cardOwnerUserId
    );

    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("[CardHook] Hook failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => 'Hook failed due to a system error - no sources were held',
    ]);
}
