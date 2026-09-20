<?php
declare(strict_types=1);
/**
 * cron/poll_settlement_confirmations.php
 *
 * Confirms that each destination institution has actually received the
 * money its source owes it for a swap, and closes the swap's settlement.
 *
 * Schedule this every 15 minutes. The job itself decides what is due, so
 * running it more often only means it checks sooner after a cycle, never
 * that it hammers the banks.
 *
 * How it decides (per institution, from participants.yaml
 * settlement_confirmation; defaults in brackets):
 *   cycles               times of day to check, Botswana time
 *                        [08:00, 10:30, 13:00, 15:30, 17:30, 20:00]
 *   overdue_hour         a settlement not confirmed by this hour on the
 *                        next business day is overdue [12]
 *
 *  - POLL institutions are asked once per cycle, plus once straight
 *    after the swap so fast payers close immediately.
 *  - PUSH institutions are never polled (they call
 *    /api/v1/callbacks/settlement_confirmed.php); only the overdue
 *    check applies to them.
 *  - An unanswered or slow settlement is never marked FAILED. Past its
 *    deadline it gets overdue_at, stays PENDING, keeps being checked,
 *    and appears as "overdue" on the admin dashboard for escalation.
 *  - FAILED is only recorded when the institution says the money was
 *    not paid (settled=false with failed=true, or status FAILED/
 *    REJECTED/RETURNED).
 *
 * Previously: polled every 20-30 s and marked FAILED after 15-20
 * attempts, i.e. five to ten minutes, although interbank settlement
 * runs in batches later in the day.
 *
 * Exit codes: 0 ok, 1 some checks errored, 2 fatal, 3 another run holds the lock.
 */

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Infrastructure/Adapters/InstitutionAdapterFactory.php';

use Core\Config\LoadCountry;
use Infrastructure\Adapters\InstitutionAdapterFactory;

const DEFAULT_CYCLES = ['08:00', '10:30', '13:00', '15:30', '17:30', '20:00'];
const DEFAULT_OVERDUE_HOUR = 12;
const BATCH = 500;

$tz = new DateTimeZone('Africa/Gaborone');
$now = new DateTimeImmutable('now', $tz);

/** Most recent cycle time at or before $now (today or yesterday's last). */
function latestCycle(DateTimeImmutable $now, array $cycles): DateTimeImmutable {
    sort($cycles);
    $best = null;
    foreach ([$now->modify('-1 day'), $now] as $day) {
        foreach ($cycles as $c) {
            [$h, $m] = array_map('intval', explode(':', $c));
            $t = $day->setTime($h, $m);
            if ($t <= $now && ($best === null || $t > $best)) { $best = $t; }
        }
    }
    return $best ?? $now->modify('-1 day');
}

/** Next business day (Mon-Fri) after $created, at $hour. Public holidays are not excluded. */
function overdueDeadline(DateTimeImmutable $created, int $hour): DateTimeImmutable {
    $d = $created->modify('+1 day');
    while ((int)$d->format('N') >= 6) { $d = $d->modify('+1 day'); }
    return $d->setTime($hour, 0);
}

function toLocal(?string $ts, DateTimeZone $tz): ?DateTimeImmutable {
    if ($ts === null || $ts === '') return null;
    // Timestamps in these tables are written by the database in its own
    // time zone; read them as UTC unless they carry an offset.
    $dt = new DateTimeImmutable($ts, new DateTimeZone('UTC'));
    return $dt->setTimezone($tz);
}

try {
    $db = \Core\Database\DBConnection::getConnection();
    if (!$db) { throw new RuntimeException('Database unavailable'); }

    // Never run two copies at once.
    if (!$db->query("SELECT pg_try_advisory_lock(7743002)")->fetchColumn()) {
        error_log('[POLL_SETTLEMENT] another run is in progress; skipping');
        exit(3);
    }

    $participants = LoadCountry::getConfig()['participants'] ?? [];
    $logger = new class {
        public function info($m, $c = []) { error_log("[POLL_SETTLEMENT] INFO: {$m} " . json_encode($c)); }
        public function error($m, $c = []) { error_log("[POLL_SETTLEMENT] ERROR: {$m} " . json_encode($c)); }
        public function warning($m, $c = []) { error_log("[POLL_SETTLEMENT] WARNING: {$m} " . json_encode($c)); }
        public function debug($m, $c = []) {}
        public function log($l, $m, $c = []) {}
    };
    $factory = new InstitutionAdapterFactory($participants, $logger);

    $hasSettlementStatus = (bool)$db->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'swap_requests' AND column_name = 'settlement_status'
    ")->fetchColumn();
    $mirror = function (string $swapRef, string $status) use ($db, $hasSettlementStatus): void {
        if (!$hasSettlementStatus) return;
        $sql = $status === 'CONFIRMED'
            ? "UPDATE swap_requests SET settlement_status = 'CONFIRMED', settlement_confirmed_at = NOW() WHERE swap_uuid = ?"
            : "UPDATE swap_requests SET settlement_status = ? WHERE swap_uuid = ?";
        $status === 'CONFIRMED' ? $db->prepare($sql)->execute([$swapRef]) : $db->prepare($sql)->execute([$status, $swapRef]);
    };

    $rows = $db->query("
        SELECT * FROM settlement_confirmations
        WHERE status = 'PENDING'
        ORDER BY created_at ASC
        LIMIT " . BATCH
    )->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['open' => count($rows), 'checked' => 0, 'confirmed' => 0, 'failed' => 0, 'newly_overdue' => 0, 'errors' => 0, 'not_due' => 0];

    foreach ($rows as $row) {
        $inst = $row['destination_institution'];
        $cfg = $participants[$inst]['settlement_confirmation'] ?? [];
        $cycles = $cfg['cycles'] ?? DEFAULT_CYCLES;
        $overdueHour = (int)($cfg['overdue_hour'] ?? DEFAULT_OVERDUE_HOUR);
        $mode = strtoupper((string)($row['confirmation_mode'] ?: ($cfg['mode'] ?? 'POLL')));
        $created = toLocal($row['created_at'], $tz) ?? $now;
        $lastAttempt = toLocal($row['last_attempt_at'], $tz);

        // Overdue: flag once, keep open, keep checking.
        if (empty($row['overdue_at']) && $now >= overdueDeadline($created, $overdueHour)) {
            $db->prepare("UPDATE settlement_confirmations SET overdue_at = NOW() WHERE confirmation_id = ?")
               ->execute([$row['confirmation_id']]);
            $stats['newly_overdue']++;
            error_log("[POLL_SETTLEMENT] OVERDUE {$row['swap_reference']}: {$inst} has not confirmed receipt by the next business day at {$overdueHour}:00");
        }

        if ($mode !== 'POLL') { continue; }

        // Due if never asked, or a cycle has passed since the last ask.
        $due = $lastAttempt === null || $lastAttempt < latestCycle($now, $cycles);
        if (!$due) { $stats['not_due']++; continue; }

        try {
            $result = $factory->getAdapter($inst)->checkSettlementStatus([
                'reference' => $row['settlement_reference'],
                'swap_reference' => $row['swap_reference'],
            ]);
            $stats['checked']++;
            $db->prepare("UPDATE settlement_confirmations SET attempts = attempts + 1, last_attempt_at = NOW(), last_error = NULL WHERE confirmation_id = ?")
               ->execute([$row['confirmation_id']]);

            $explicitFail = !empty($result['failed'])
                || in_array(strtoupper((string)($result['status'] ?? '')), ['FAILED', 'REJECTED', 'RETURNED'], true);

            if (!empty($result['settled'])) {
                $db->prepare("UPDATE settlement_confirmations SET status = 'CONFIRMED', confirmed_at = NOW() WHERE confirmation_id = ?")
                   ->execute([$row['confirmation_id']]);
                $mirror($row['swap_reference'], 'CONFIRMED');
                $stats['confirmed']++;
            } elseif ($explicitFail) {
                $reason = (string)($result['message'] ?? $result['status'] ?? 'institution reported the settlement as not paid');
                $db->prepare("UPDATE settlement_confirmations SET status = 'FAILED', last_error = ? WHERE confirmation_id = ?")
                   ->execute([mb_substr($reason, 0, 500), $row['confirmation_id']]);
                $mirror($row['swap_reference'], 'FAILED');
                $stats['failed']++;
                error_log("[POLL_SETTLEMENT] FAILED {$row['swap_reference']}: {$inst} says not paid ({$reason}); needs reconciliation");
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $db->prepare("UPDATE settlement_confirmations SET attempts = attempts + 1, last_attempt_at = NOW(), last_error = ? WHERE confirmation_id = ?")
               ->execute([mb_substr($e->getMessage(), 0, 500), $row['confirmation_id']]);
            error_log("[POLL_SETTLEMENT] check failed for {$row['swap_reference']} at {$inst}: " . $e->getMessage());
        }
    }

    $db->query("SELECT pg_advisory_unlock(7743002)");
    echo json_encode($stats) . PHP_EOL;
    exit($stats['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    error_log('[POLL_SETTLEMENT] FATAL: ' . $e->getMessage());
    exit(2);
}
