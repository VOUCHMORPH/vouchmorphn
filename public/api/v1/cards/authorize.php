<?php
declare(strict_types=1);

/**
 * VouchMorph Card Network - Authorization Endpoint
 *
 * This is the real-time entry point a merchant terminal/acquirer hits at
 * swipe time. It must return in well under a second, so it does ONLY the
 * fast-path check (authorizePooledSwipe) - no bank calls, no debits.
 *
 * The actual source debits, settlement, and shortfall billing happen
 * asynchronously afterward, picked up by scripts/daemons/card-pool-finalize-worker.php
 * from the card_pool_finalize_queue table this endpoint writes to on approval.
 *
 * Falls back to the existing single-hold authorizeTransaction() for cards
 * that were preloaded via issueCard()/loadCard() rather than hooked.
 *
 * SECURITY NOTES:
 * - CVV verification removed entirely (PCI-DSS prohibits CVV storage) -
 *   replaced by a TOTP-based dynamic code, verified inside CardService.
 * - Brand exclusivity check for pooled hooks confirms the card_suffix is
 *   a real VouchMorph-issued row in message_cards. It does NOT depend on
 *   a card_category/card_type column, since that distinction lives in the
 *   separate card-application intake flow, not the issued-card record -
 *   only hookSourcesToCard() ever writes to card_pool_hooks, so a row
 *   existing in message_cards at all is sufficient proof of VouchMorph
 *   issuance without assuming a column that hasn't been confirmed to exist.
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

// ============================================================
// BOOTSTRAP - real paths, not the old BUSINESS_LOGIC_LAYER structure
// ============================================================
$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';
require_once ROOT_PATH . '/src/Application/Utils/AuditLogger.php';

use Domain\Services\CardService;
use Application\Utils\AuditLogger;

/**
 * Unified with execute.php's auth model — VOUCHMORPH_API_KEY only,
 * exact constant-time comparison. Previously checked API_KEY_SYSTEM /
 * API_KEY_<INSTITUTION>, environment variables that were never
 * actually set in this deployment, meaning every call to this
 * endpoint was rejected regardless of the key supplied. Confirmed
 * live 21 Aug 2026.
 */
function isValidApiKeyForAuthorize(?string $providedKey): bool {
    $validKey = getenv('VOUCHMORPH_API_KEY') ?: '';

    if ($validKey === '') {
        error_log("[authorize.php] CRITICAL: VOUCHMORPH_API_KEY is not configured in this environment");
        return false;
    }
    if ($providedKey === null || $providedKey === '') {
        return false;
    }
    return hash_equals($validKey, $providedKey);
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);
$providedKey = $headersLower['x-api-key'] ?? null;

if (!isValidApiKeyForAuthorize($providedKey)) {
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

// FIX 2 (PART 1): Added dynamic_code to required fields validation
foreach (['card_suffix', 'amount', 'dynamic_code'] as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "{$field} is required"]);
        exit();
    }
}

$cardSuffix = (string)$input['card_suffix'];
$amount = (float)$input['amount'];

// FIX 2 (PART 2): Added dynamic_code to merchantContext so it reaches CardService::authorizePooledSwipe()
$merchantContext = [
    'merchant_reference' => $input['merchant_reference'] ?? null,
    'merchant_id' => $input['merchant_id'] ?? null,
    'merchant_name' => $input['merchant_name'] ?? null,
    'terminal_id' => $input['terminal_id'] ?? null,
    'acquirer' => $input['acquirer'] ?? null,
    'channel' => $input['channel'] ?? 'POS',
    'dynamic_code' => $input['dynamic_code'] ?? null,  // <-- FIXED: was missing
];

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid amount is required']);
    exit();
}

// ============================================================
// EXECUTE
// ============================================================
$db = $container->get(PDO::class);
$cardConfig = $container->get('countryConfig');
$cardService = new CardService($db, $container->get('countryCode'), $cardConfig);
$auditLogger = new AuditLogger();

$startTime = microtime(true);

try {
    // ============================================================
    // 1. CHECK: Is there an active pooled hook for this card?
    // ============================================================
    $hookCheck = $db->prepare("
        SELECT hook_reference FROM card_pool_hooks
        WHERE card_suffix = ? AND status = 'HOOKED' AND expires_at > NOW()
        ORDER BY created_at DESC LIMIT 1
    ");
    $hookCheck->execute([$cardSuffix]);
    $activeHook = $hookCheck->fetchColumn();

    if ($activeHook) {
        // ============================================================
        // EXCLUSIVITY CHECK: Pooled hooks are ONLY for VouchMorph-issued cards
        //
        // Simplified from a prior version that checked a card_category
        // column not confirmed to exist on message_cards - that would have
        // thrown a SQL error on every pooled swipe, caught by the generic
        // exception handler below and surfaced only as an opaque
        // "System error" response. This checks only what's actually known
        // to exist: a real, active row in message_cards for this suffix.
        // Since hookSourcesToCard() is the only writer of card_pool_hooks,
        // that row's existence is itself sufficient proof of VouchMorph
        // issuance - a Visa/Mastercard PAN would never have a matching
        // message_cards row regardless of what (incorrectly) ends up in
        // card_pool_hooks.
        // ============================================================
        // FIX 1: Changed 'status' to 'lifecycle_status' (the correct column name)
        $brandCheck = $db->prepare("
            SELECT 1 FROM message_cards 
            WHERE card_suffix = ? 
            AND lifecycle_status = 'ACTIVE'
            LIMIT 1
        ");
        $brandCheck->execute([$cardSuffix]);
        $isVouchMorphCard = $brandCheck->fetchColumn();

        if (!$isVouchMorphCard) {
            error_log("[CardAuth] Pooled hook rejected - card_suffix {$cardSuffix} not a VouchMorph-issued card");
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'authorized' => false,
                'response_code' => '58',
                'response_message' => 'Pooled hook authorization is only valid for VouchMorph-issued cards',
            ]);
            exit();
        }

        // Pooled card path - authorizePooledSwipe does NO bank calls, just a
        // local held-total check. This is the sub-second path.
        $result = $cardService->authorizePooledSwipe($cardSuffix, $amount, $merchantContext);

        if ($result['authorized'] ?? false) {
            // Queue the real debits/settlement for the background worker -
            // never do them in this request.
            $queueStmt = $db->prepare("
                INSERT INTO card_pool_finalize_queue (hook_reference, merchant_context, status)
                VALUES (?, ?::jsonb, 'PENDING')
            ");
            $queueStmt->execute([$result['hook_reference'], json_encode($merchantContext)]);
        }

    } else {
        // No active hook - fall back to the existing preloaded single-hold path
        // (cards funded via issueCard()/loadCard(), unrelated to pooling).
        // CVV is no longer checked here or anywhere - authorizeTransaction()
        // now verifies a TOTP-based dynamic_code instead, per PCI-DSS.
        $result = $cardService->authorizeTransaction(array_merge($input, $merchantContext));
    }

    $responseTime = round((microtime(true) - $startTime) * 1000);
    $result['processing_time_ms'] = $result['processing_time_ms'] ?? $responseTime;

    $auditLogger->log(
        ($result['authorized'] ?? false) ? 'CARD_AUTH_APPROVED' : 'CARD_AUTH_DECLINED',
        'INFO',
        'card_network',
        null,
        null,
        ['card_suffix' => $cardSuffix, 'amount' => $amount, 'response_time_ms' => $responseTime]
    );

    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("[CardAuth] Authorization error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'authorized' => false,
        'response_code' => '96',
        'response_message' => 'System error',
        'error' => $e->getMessage(),
    ]);
}
