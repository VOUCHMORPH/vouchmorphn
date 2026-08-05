<?php
declare(strict_types=1);
// cron/poll_settlement_confirmations.php — run every 1-2 minutes.

require_once __DIR__ . '/../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../src/Infrastructure/Adapters/InstitutionAdapterFactory.php';

use Core\Config\LoadCountry;
use Infrastructure\Adapters\InstitutionAdapterFactory;

$db = \Core\Database\DBConnection::getConnection();
$countryConfig = LoadCountry::getConfig();
$participants = $countryConfig['participants'] ?? [];

$logger = new class {
    public function info($m, $c = []) { error_log("[POLL_SETTLEMENT] INFO: {$m} " . json_encode($c)); }
    public function error($m, $c = []) { error_log("[POLL_SETTLEMENT] ERROR: {$m} " . json_encode($c)); }
    public function warning($m, $c = []) { error_log("[POLL_SETTLEMENT] WARNING: {$m} " . json_encode($c)); }
    public function debug($m, $c = []) {}
    public function log($l, $m, $c = []) {}
};

$adapterFactory = new InstitutionAdapterFactory($participants, $logger);

$stmt = $db->prepare("
    SELECT * FROM settlement_confirmations
    WHERE status = 'PENDING' AND confirmation_mode = 'POLL'
    ORDER BY created_at ASC
    LIMIT 50
");
$stmt->execute();
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

$checked = 0;
$confirmed = 0;
$failed = 0;

foreach ($pending as $row) {
    $institution = $row['destination_institution'];
    $participant = $participants[$institution] ?? [];
    $pollConfig = $participant['settlement_confirmation'] ?? [];
    $maxAttempts = (int)($pollConfig['poll_max_attempts'] ?? 20);
    $delaySeconds = (int)($pollConfig['poll_delay_seconds'] ?? 30);

    // Skip if not enough time has passed since last attempt.
    if ($row['last_attempt_at'] && (time() - strtotime($row['last_attempt_at'])) < $delaySeconds) {
        continue;
    }

    if ((int)$row['attempts'] >= $maxAttempts) {
        $db->prepare("
            UPDATE settlement_confirmations SET status = 'FAILED', last_error = 'Max poll attempts exceeded'
            WHERE confirmation_id = ?
        ")->execute([$row['confirmation_id']]);
        $db->prepare("UPDATE swap_requests SET settlement_status = 'FAILED' WHERE swap_uuid = ?")
            ->execute([$row['swap_reference']]);

        error_log("[POLL_SETTLEMENT] {$row['swap_reference']} exceeded max attempts — flagged FAILED, needs manual reconciliation");
        $failed++;
        continue;
    }

    try {
        $adapter = $adapterFactory->getAdapter($institution);
        $result = $adapter->checkSettlementStatus([
            'reference' => $row['settlement_reference'],
            'swap_reference' => $row['swap_reference'],
        ]);

        $checked++;
        $db->prepare("
            UPDATE settlement_confirmations SET attempts = attempts + 1, last_attempt_at = NOW()
            WHERE confirmation_id = ?
        ")->execute([$row['confirmation_id']]);

        if ($result['settled'] ?? false) {
            $db->prepare("
                UPDATE settlement_confirmations SET status = 'CONFIRMED', confirmed_at = NOW()
                WHERE confirmation_id = ?
            ")->execute([$row['confirmation_id']]);
            $db->prepare("
                UPDATE swap_requests SET settlement_status = 'CONFIRMED', settlement_confirmed_at = NOW()
                WHERE swap_uuid = ?
            ")->execute([$row['swap_reference']]);
            $confirmed++;
            error_log("[POLL_SETTLEMENT] {$row['swap_reference']} CONFIRMED by {$institution}");
        }
        // else: still pending, will retry next cycle within max_attempts.

    } catch (\Throwable $e) {
        error_log("[POLL_SETTLEMENT] Poll failed for {$row['swap_reference']}: " . $e->getMessage());
        $db->prepare("
            UPDATE settlement_confirmations SET attempts = attempts + 1, last_attempt_at = NOW(), last_error = ?
            WHERE confirmation_id = ?
        ")->execute([$e->getMessage(), $row['confirmation_id']]);
    }
}

echo json_encode(['checked' => $checked, 'confirmed' => $confirmed, 'failed' => $failed]) . PHP_EOL;
