<?php
declare(strict_types=1);
/**
 * cron/poll_settlement_confirmations.php
 *
 * Confirms that each receiving bank has actually been paid, and closes the
 * settlement of every swap and fee invoice it covers.
 *
 * How it works (see dispatch_settlement_advices.php for the other half):
 *  - Swaps are paid in advice lines: one net payment per paying bank ->
 *    receiving bank per cycle, made through the central bank with the line
 *    reference. For each open line, this asks the RECEIVING bank (or the
 *    sponsor bank that receives for a mobile-money issuer) whether that
 *    reference arrived. If yes, every swap on the line becomes CONFIRMED
 *    ("Destination settled" on the admin dashboard) and the line is PAID.
 *  - Fee lines are checked the same way at VouchMorph's fee bank; when paid,
 *    each fee invoice on the line becomes ACKNOWLEDGED ("Fee paid").
 *  - Swaps not yet on an advice are not asked about: nothing has been paid
 *    for them yet. They are still flagged overdue after the deadline.
 *  - A bank is asked once per cycle per line, plus once straight away.
 *  - Slow or unanswered is never FAILED: after noon on the next business day
 *    the swap is marked overdue (overdue_at) and escalated, and checking
 *    continues. FAILED only when the bank explicitly reports the money was
 *    not received.
 *
 * Schedule every 15 minutes. Exit: 0 ok, 1 some checks errored, 2 fatal, 3 locked.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Infrastructure/Adapters/InstitutionAdapterFactory.php';
require_once __DIR__ . '/../../src/Domain/Services/Fees/FeeLedger.php';

use Core\Config\LoadCountry;
use Infrastructure\Adapters\InstitutionAdapterFactory;

const DEFAULT_CYCLES = ['08:00', '10:30', '13:00', '15:30', '17:30', '20:00'];
const DEFAULT_OVERDUE_HOUR = 12;

$tz = new DateTimeZone('Africa/Gaborone');
$now = new DateTimeImmutable('now', $tz);
$cfg = json_decode((string)file_get_contents(__DIR__ . '/../Core/Config/Countries/Botswana/settlement.json'), true) ?: [];
$sponsor = $cfg['settling_bank'] ?? [];
$settling = fn(string $inst): string => $sponsor[strtoupper($inst)] ?? strtoupper($inst);
$askAlways = in_array('--now', $argv ?? [], true);   // testing: ignore the cycle timetable

function latestCycle(DateTimeImmutable $now, array $cycles): DateTimeImmutable {
    sort($cycles);
    $best = null;
    foreach ([$now->modify('-1 day'), $now] as $day) {
        foreach ($cycles as $c) {
            [$h, $m] = array_map('intval', explode(':', $c));
            $t = $day->setTime($h, $m);
            if ($t <= $now && ($best === null || $t > $best)) $best = $t;
        }
    }
    return $best ?? $now->modify('-1 day');
}
function overdueDeadline(DateTimeImmutable $created, int $hour): DateTimeImmutable {
    $d = $created->modify('+1 day');
    while ((int)$d->format('N') >= 6) $d = $d->modify('+1 day');
    return $d->setTime($hour, 0);
}
function toLocal(?string $ts, DateTimeZone $tz): ?DateTimeImmutable {
    if ($ts === null || $ts === '') return null;
    return (new DateTimeImmutable($ts, new DateTimeZone('UTC')))->setTimezone($tz);
}

try {
    $db = \Core\Database\DBConnection::getConnection();
    if (!$db) throw new RuntimeException('Database unavailable');
    if (!$db->query("SELECT pg_try_advisory_lock(7743002)")->fetchColumn()) { error_log('[POLL_SETTLEMENT] another run in progress'); exit(3); }

    $participants = LoadCountry::getConfig()['participants'] ?? [];
    $logger = new class {
        public function info($m, $c = []) {} public function debug($m, $c = []) {} public function log($l, $m, $c = []) {}
        public function error($m, $c = []) { error_log("[POLL_SETTLEMENT] ERROR: {$m} " . json_encode($c)); }
        public function warning($m, $c = []) { error_log("[POLL_SETTLEMENT] WARNING: {$m} " . json_encode($c)); }
    };
    $factory = new InstitutionAdapterFactory($participants, $logger);
    $hasStatusCol = (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'swap_requests' AND column_name = 'settlement_status'")->fetchColumn();
    $cycles = $cfg['cycles'] ?? DEFAULT_CYCLES;
    $cycleStart = latestCycle($now, $cycles);

    $ledger = \Domain\Services\Fees\FeeLedger::fromCountryConfig($db);
    $hasLedger = (bool)$db->query("SELECT to_regclass('fee_ledger') IS NOT NULL")->fetchColumn();
    // Fee shares on a line become PAID when the receiving bank confirms the line.
    $markSharesPaid = function (string $lineRef) use ($db, $hasLedger): int {
        if (!$hasLedger) return 0;
        $st = $db->prepare("UPDATE fee_ledger SET status = 'PAID', paid_at = NOW() WHERE advice_line_ref = ? AND status = 'ADVISED'");
        $st->execute([$lineRef]);
        return $st->rowCount();
    };
    $stats = ['fee_shares_paid' => 0, 'settlement_fees_earned' => 0, 'open_swaps' => 0, 'lines_checked' => 0, 'lines_paid' => 0, 'swaps_settled' => 0, 'fee_lines_paid' => 0,
              'invoices_paid' => 0, 'failed' => 0, 'newly_overdue' => 0, 'errors' => 0, 'not_due' => 0];

    // Ask the receiving bank whether a line reference was paid.
    $ask = function (string $bank, string $lineRef, array $swapRefs) use ($factory): array {
        $result = $factory->getAdapter($bank)->checkSettlementStatus(
            ['reference' => $lineRef, 'swap_references' => $swapRefs],
            ['swap_reference' => $swapRefs[0] ?? $lineRef, 'settlement_reference' => $lineRef]
        );
        $explicitFail = !empty($result['failed']) || in_array(strtoupper((string)($result['status'] ?? '')), ['FAILED', 'REJECTED', 'RETURNED'], true);
        return [$result, $explicitFail];
    };

    // ---------- 1. overdue flags on every open swap ----------
    $open = $db->query("SELECT confirmation_id, swap_reference, destination_institution, created_at, overdue_at FROM settlement_confirmations WHERE status = 'PENDING' ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
    $stats['open_swaps'] = count($open);
    foreach ($open as $row) {
        $inst = $settling($row['destination_institution']);
        $overdueHour = (int)($participants[$inst]['settlement_confirmation']['overdue_hour'] ?? DEFAULT_OVERDUE_HOUR);
        if (empty($row['overdue_at']) && $now >= overdueDeadline(toLocal($row['created_at'], $tz) ?? $now, $overdueHour)) {
            $db->prepare("UPDATE settlement_confirmations SET overdue_at = NOW() WHERE confirmation_id = ?")->execute([$row['confirmation_id']]);
            $stats['newly_overdue']++;
        }
    }

    // ---------- 2. principal lines: one question per open line ----------
    // Every open principal line - including lines that carry only a destination's
    // fee shares (e.g. a code fee earned before its cash-out is collected).
    $lines = $db->query("
        SELECT l.line_ref, l.creditor_bank, l.debtor_bank, MAX(sc.last_attempt_at) AS last_attempt_at,
               COALESCE(array_to_json(array_agg(sc.swap_reference ORDER BY sc.swap_reference) FILTER (WHERE sc.swap_reference IS NOT NULL)), '[]') AS swaps
        FROM settlement_advice_lines l
        LEFT JOIN settlement_confirmations sc ON sc.advice_line_ref = l.line_ref AND sc.status = 'PENDING'
        WHERE l.kind = 'PRINCIPAL' AND l.status = 'OPEN'
        GROUP BY l.line_ref, l.creditor_bank, l.debtor_bank
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lines as $l) {
        $swapRefs = json_decode($l['swaps'], true) ?: [];
        $last = toLocal($l['last_attempt_at'], $tz);
        if (!$askAlways && $last !== null && $last >= $cycleStart) { $stats['not_due']++; continue; }
        try {
            [$result, $explicitFail] = $ask($l['creditor_bank'], $l['line_ref'], $swapRefs);
            $stats['lines_checked']++;
            $db->prepare("UPDATE settlement_confirmations SET attempts = attempts + 1, last_attempt_at = NOW(), last_error = ? WHERE advice_line_ref = ? AND status = 'PENDING'")
               ->execute([empty($result['success']) ? mb_substr((string)($result['message'] ?? 'check failed'), 0, 500) : null, $l['line_ref']]);
            if (!empty($result['settled'])) {
                $db->beginTransaction();
                $db->prepare("UPDATE settlement_advice_lines SET status = 'PAID', paid_at = NOW(), receipt_reference = ? WHERE line_ref = ? AND status = 'OPEN'")
                   ->execute([mb_substr((string)($result['settlement_reference'] ?? ''), 0, 100), $l['line_ref']]);
                $upd = $db->prepare("UPDATE settlement_confirmations SET status = 'CONFIRMED', confirmed_at = NOW(), last_error = NULL WHERE advice_line_ref = ? AND status = 'PENDING' RETURNING swap_reference");
                $upd->execute([$l['line_ref']]);
                $settled = $upd->fetchAll(PDO::FETCH_COLUMN);
                if ($hasStatusCol && $settled) {
                    $in = implode(',', array_fill(0, count($settled), '?'));
                    $db->prepare("UPDATE swap_requests SET settlement_status = 'CONFIRMED', settlement_confirmed_at = NOW() WHERE swap_uuid IN ($in)")->execute($settled);
                }
                $stats['fee_shares_paid'] += $markSharesPaid($l['line_ref']);
                $db->commit();
                $stats['lines_paid']++;
                $stats['swaps_settled'] += count($settled);
                // The paying bank performed this settlement: it earns the 2% settlement fee on each leg.
                foreach ($settled as $legRef) {
                    $stats['settlement_fees_earned'] += $ledger->settleLeg((string)$legRef, (string)$l['debtor_bank']) > 0 ? 1 : 0;
                }
            } elseif ($explicitFail) {
                $reason = mb_substr((string)($result['message'] ?? 'receiving bank reports the payment as not received'), 0, 500);
                $upd = $db->prepare("UPDATE settlement_confirmations SET status = 'FAILED', last_error = ? WHERE advice_line_ref = ? AND status = 'PENDING' RETURNING swap_reference");
                $upd->execute([$reason, $l['line_ref']]);
                $failed = $upd->fetchAll(PDO::FETCH_COLUMN);
                if ($hasStatusCol && $failed) {
                    $in = implode(',', array_fill(0, count($failed), '?'));
                    $db->prepare("UPDATE swap_requests SET settlement_status = 'FAILED' WHERE swap_uuid IN ($in)")->execute($failed);
                }
                $stats['failed'] += count($failed);
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            $db->prepare("UPDATE settlement_confirmations SET attempts = attempts + 1, last_attempt_at = NOW(), last_error = ? WHERE advice_line_ref = ? AND status = 'PENDING'")
               ->execute([mb_substr($e->getMessage(), 0, 500), $l['line_ref']]);
            error_log("[POLL_SETTLEMENT] check failed for line {$l['line_ref']} at {$l['creditor_bank']}: " . $e->getMessage());
        }
    }

    // ---------- 3. fee lines: asked at VouchMorph's fee bank ----------
    $feeLines = $db->query("SELECT line_ref, creditor_bank FROM settlement_advice_lines WHERE kind = 'FEE' AND status = 'OPEN' ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($feeLines as $l) {
        $items = $db->prepare("SELECT item_reference FROM settlement_advice_items WHERE line_ref = ? AND item_type = 'FEE_INVOICE'");
        $items->execute([$l['line_ref']]);
        $invoiceIds = $items->fetchAll(PDO::FETCH_COLUMN);
        try {
            [$result] = $ask($l['creditor_bank'], $l['line_ref'], []);
            $stats['lines_checked']++;
            if (!empty($result['settled'])) {
                $db->beginTransaction();
                $db->prepare("UPDATE settlement_advice_lines SET status = 'PAID', paid_at = NOW(), receipt_reference = ? WHERE line_ref = ? AND status = 'OPEN'")
                   ->execute([mb_substr((string)($result['settlement_reference'] ?? ''), 0, 100), $l['line_ref']]);
                if ($invoiceIds) {
                    $in = implode(',', array_fill(0, count($invoiceIds), '?'));
                    $upd = $db->prepare("UPDATE settlement_outbox SET status = 'ACKNOWLEDGED', acknowledged_at = NOW() WHERE message_type = 'FEE_INVOICE' AND message_uuid::text IN ($in) AND status <> 'ACKNOWLEDGED'");
                    $upd->execute($invoiceIds);
                    $stats['invoices_paid'] += $upd->rowCount();
                }
                $stats['fee_shares_paid'] += $markSharesPaid($l['line_ref']);
                $db->commit();
                $stats['fee_lines_paid']++;
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            error_log("[POLL_SETTLEMENT] fee line {$l['line_ref']} check failed at {$l['creditor_bank']}: " . $e->getMessage());
        }
    }

    $db->query("SELECT pg_advisory_unlock(7743002)");
    echo json_encode($stats) . PHP_EOL;
    exit($stats['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[POLL_SETTLEMENT] FATAL: ' . $e->getMessage());
    echo json_encode(['fatal' => $e->getMessage()]) . PHP_EOL;
    exit(2);
}
