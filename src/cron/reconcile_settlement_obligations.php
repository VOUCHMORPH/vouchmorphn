<?php
declare(strict_types=1);
// cron/reconcile_settlement_obligations.php — run every 1-2 minutes.

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';

$db = \Core\Database\DBConnection::getConnection();

$stmt = $db->prepare("
    SELECT * FROM settlement_obligations
    WHERE status = 'PENDING_NOTIFY'
    ORDER BY created_at ASC
    LIMIT 100
");
$stmt->execute();
$obligations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$switchBaseUrl = getenv('CENTRALSWITCH_BASE_URL') ?: 'https://centralswitch-production.up.railway.app';

// Health check first — no point attempting notifications if the switch is still down.
$health = @file_get_contents($switchBaseUrl . '/health');
if ($health === false) {
    error_log("[RECONCILE] Switch unreachable, skipping this run — " . count($obligations) . " obligations still pending");
    exit;
}

foreach ($obligations as $ob) {
    // TODO: centralswitch needs a real endpoint for this — does not
    // exist yet. Expected contract:
    //   POST /api/notify_bilateral_settlement.php
    //   { origin_institution, destination_institution, amount, currency,
    //     vouchmorph_reference }
    // Should mark the equivalent switch_transactions/net position as
    // already-settled-bilaterally, so the switch's own ledger doesn't
    // double-count when normal traffic resumes.
    //
    // Until that endpoint exists, this loop deliberately does nothing
    // destructive — it just tracks attempts so nothing is silently lost.
    $stmt = $db->prepare("
        UPDATE settlement_obligations
        SET attempts = attempts + 1, last_attempt_at = NOW(),
            last_error = 'notify_bilateral_settlement endpoint not yet implemented on centralswitch'
        WHERE obligation_id = :id
    ");
    $stmt->execute([':id' => $ob['obligation_id']]);
}

echo json_encode(['checked' => count($obligations), 'switch_reachable' => true]) . PHP_EOL;
