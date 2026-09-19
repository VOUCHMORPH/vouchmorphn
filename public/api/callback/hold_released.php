<?php
declare(strict_types=1);
// api/v1/callbacks/hold_released.php
//
// Workflow stage 4: a source institution tells us it has released a hold on
// its own side -- the hold expired there, the money is back with its
// customer, and our status has to follow immediately instead of waiting for
// a cron to notice.
//
// Same shape and same authentication as settlement_confirmed.php and
// reservation_account_confirmed.php, deliberately: institutions already
// implement those two, so this is the third instance of a pattern they have
// rather than a new contract to negotiate.
//
// Expected body:
//   {
//     "hold_reference": "<the reference we were given when the hold was placed>",
//     "institution":    "<the caller, e.g. ZURUBANK>",
//     "reason":         "EXPIRED" | "CANCELLED_BY_BANK" | free text   (optional)
//     "released_at":    "2026-09-19 08:30:00"                          (optional)
//   }
//
// Header: X-API-Key, that institution's own VouchMorph key.
//
// Idempotent on hold_reference -- calling twice is safe, and a retry will
// not re-decide anything.

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../../src/Domain/Services/SwapService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception('Invalid JSON payload');

    $holdReference = $input['hold_reference'] ?? null;
    $institution = $input['institution'] ?? null;
    $reason = $input['reason'] ?? null;
    $releasedAt = $input['released_at'] ?? null;

    if (!$holdReference || !$institution) {
        throw new Exception('hold_reference and institution are required');
    }

    // Auth: this institution's own key, checked against the participant it
    // claims to be -- the same check the other two callbacks make.
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expectedKey = getenv(strtoupper($institution) . '_VOUCHMORPH_API_KEY') ?: '';
    if (!$expectedKey || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid authentication']);
        exit;
    }

    $db = \Core\Database\DBConnection::getConnection();
    if (!$db) {
        throw new Exception('Database unavailable');
    }

    $country = getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new \Domain\Services\SwapService($db, \Core\Config\LoadCountry::getConfig(), $country);

    $result = $swapService->recordBankHoldRelease(
        (string)$holdReference,
        (string)$institution,
        $reason !== null ? (string)$reason : null,
        $releasedAt !== null ? (string)$releasedAt : null
    );

    // An unknown reference is answered 200, not 404: a bank retrying against
    // a reference we never stored should stop retrying, and telling it the
    // endpoint is missing would be wrong.
    echo json_encode([
        'success' => true,
        'status' => $result['status'],
        'message' => $result['message'],
        'flagged_for_reconciliation' => $result['flagged'],
    ]);

} catch (Throwable $e) {
    error_log('[hold_released] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
