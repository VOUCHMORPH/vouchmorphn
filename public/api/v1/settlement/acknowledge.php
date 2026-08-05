<?php
declare(strict_types=1);

/**
 * VouchMorph - Settlement Acknowledgement Endpoint
 * =================================================
 * The inbound half of settlement delivery. An institution that received
 * a settlement instruction (via HybridSettlementStrategy::deliverToParticipant(),
 * see PATCH_HybridSettlementStrategy_delivery.md) calls this endpoint back
 * once they've actually settled their side, so VouchMorph can mark the
 * obligation acknowledged rather than leaving it PENDING/SENT forever.
 *
 * This closes the second half of the loop the earlier code review found:
 * HybridSettlementStrategy::acknowledgeSettlement() already existed and
 * was fully implemented -- nothing was ever calling it, because nothing
 * ever received an inbound acknowledgement. This file is that receiver.
 *
 * Mirrors execute.php's auth pattern: same X-API-Key model, same
 * hash_equals() constant-time comparison, same fail-closed-if-unconfigured
 * behavior. Reuses the SAME shared VOUCHMORPH_API_KEY as execute.php --
 * if you want per-institution acknowledgement credentials (recommended,
 * matches the outbound per-institution API key pattern already used in
 * GenericBankClient), see the note at the bottom.
 *
 * EXPECTED REQUEST from the institution:
 * POST /api/v1/settlement/acknowledge.php
 * Headers: X-API-Key: <institution's key>
 * Body:
 * {
 *   "message_uuid": "the instruction_id from the settlement instruction they received",
 *   "institution": "ZURUBANK",
 *   "proof": {
 *     "their_reference": "...",
 *     "settled_at": "2026-08-05T12:00:00Z",
 *     "any_other_proof_fields": "..."
 *   }
 * }
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

use Core\Database\DBConnection;
use Domain\Services\Settlement\HybridSettlementStrategy;

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

// ============================================================
// AUTH — same pattern as execute.php
// ============================================================
function isValidApiKey(?string $providedKey): bool
{
    $validKey = getenv('VOUCHMORPH_API_KEY') ?: '';
    if ($validKey === '') {
        error_log("[SETTLEMENT_ACK] CRITICAL: VOUCHMORPH_API_KEY not configured");
        return false;
    }
    if ($providedKey === null || $providedKey === '') {
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
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return $_SERVER['HTTP_X_API_KEY'];
    }
    return null;
}

$providedKey = getApiKeyFromRequest();
if (!isValidApiKey($providedKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
    exit;
}

// ============================================================
// PARSE + VALIDATE INPUT
// ============================================================
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$messageUuid = $input['message_uuid'] ?? null;
$institution = $input['institution'] ?? null;
$proof = $input['proof'] ?? [];

if (!$messageUuid || !$institution) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'message_uuid and institution are required']);
    exit;
}

// ============================================================
// CONNECT + ACKNOWLEDGE
// ============================================================
try {
    require_once __DIR__ . '/../../../Core/Database/DBConnection.php';
    $db = DBConnection::getConnection();
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Confirm this message_uuid actually involves the institution claiming
    // to acknowledge it -- don't let ZURUBANK acknowledge a settlement that
    // was addressed to SACCUSSALIS.
    $checkStmt = $db->prepare("
        SELECT source_institution, destination_institution
        FROM settlement_outbox
        WHERE message_uuid = :uuid
    ");
    $checkStmt->execute([':uuid' => $messageUuid]);
    $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No settlement message found for that message_uuid']);
        exit;
    }

    $involvedInstitutions = [$row['source_institution'], $row['destination_institution']];
    $matches = array_filter($involvedInstitutions, fn($i) => strtoupper($i) === strtoupper($institution));

    if (empty($matches)) {
        error_log("[SETTLEMENT_ACK] SECURITY: {$institution} attempted to acknowledge message_uuid={$messageUuid} which does not involve them (actual parties: " . implode(', ', $involvedInstitutions) . ")");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This institution is not a party to that settlement message']);
        exit;
    }

    $settlement = new HybridSettlementStrategy($db);
    $acknowledged = $settlement->acknowledgeSettlement($messageUuid, $institution, $proof);

    if (!$acknowledged) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to record acknowledgement']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message_uuid' => $messageUuid,
        'institution' => $institution,
        'acknowledged_at' => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    error_log('[SETTLEMENT_ACK] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error processing acknowledgement']);
}

/**
 * NOTE on per-institution credentials:
 * This currently reuses the single shared VOUCHMORPH_API_KEY, same as
 * execute.php. If/when you move to per-institution outbound credentials
 * for this endpoint too (recommended, matches GenericBankClient's
 * {$bankPrefix}_API_KEY pattern used for calls VouchMorph makes OUT),
 * change isValidApiKey() to look up a key specific to the $institution
 * claimed in the request body, e.g. getenv(strtoupper($institution) . '_INBOUND_API_KEY'),
 * and compare against that instead of the shared key. This also lets you
 * revoke one institution's acknowledgement credential without affecting
 * any other institution's.
 */
