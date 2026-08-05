<?php
declare(strict_types=1);
// api/v1/callbacks/settlement_confirmed.php
//
// Destination institutions configured with settlement_confirmation.mode: PUSH
// call this to attest they received the interbank debit VouchMorph sent.

require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception('Invalid JSON payload');

    $swapRef = $input['swap_reference'] ?? null;
    $settled = $input['settled'] ?? null;
    $institution = $input['institution'] ?? null; // whichever institution is calling — verified below

    if (!$swapRef || $settled === null || !$institution) {
        throw new Exception('swap_reference, settled, and institution are required');
    }

    // Auth: reuse the same per-institution API key pattern used
    // elsewhere — this institution's own key, checked against the
    // participant record it claims to be.
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expectedKey = getenv(strtoupper($institution) . '_VOUCHMORPH_API_KEY') ?: '';
    if (!$expectedKey || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid authentication']);
        exit;
    }

    $db = \Core\Database\DBConnection::getConnection();

    $stmt = $db->prepare("
        SELECT * FROM settlement_confirmations
        WHERE swap_reference = ? AND destination_institution = ? AND status = 'PENDING'
    ");
    $stmt->execute([$swapRef, $institution]);
    $confirmation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$confirmation) {
        // Already confirmed, or never existed — idempotent no-op rather
        // than an error, since a real institution's retry logic might
        // call this more than once.
        echo json_encode(['success' => true, 'message' => 'No pending confirmation found — already resolved or unknown reference']);
        exit;
    }

    $newStatus = $settled ? 'CONFIRMED' : 'FAILED';

    $db->prepare("
        UPDATE settlement_confirmations
        SET status = ?, confirmed_at = NOW(), settlement_reference = COALESCE(?, settlement_reference)
        WHERE confirmation_id = ?
    ")->execute([$newStatus, $input['settlement_reference'] ?? null, $confirmation['confirmation_id']]);

    $db->prepare("
        UPDATE swap_requests
        SET settlement_status = ?, settlement_confirmed_at = NOW()
        WHERE swap_uuid = ?
    ")->execute([$newStatus, $swapRef]);

    echo json_encode(['success' => true, 'message' => "Settlement marked {$newStatus}"]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    error_log("[SettlementConfirmed] " . $e->getMessage());
}
