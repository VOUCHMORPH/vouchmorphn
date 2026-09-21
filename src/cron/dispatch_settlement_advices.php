<?php
declare(strict_types=1);
/**
 * cron/dispatch_settlement_advices.php
 *
 * Each settlement cycle, tells every paying bank what it owes:
 *   - to each receiving bank, for the swaps it originated (PRINCIPAL lines)
 *   - to VouchMorph, for the fee invoices raised on it (FEE lines)
 * One net amount per receiving bank per cycle, with every swap reference or
 * invoice behind it listed on the line, so settlement is tracked per
 * transaction. The paying bank pays each line through the central bank
 * using the line reference; poll_settlement_confirmations.php then asks the
 * receiving bank whether that reference arrived, and marks every swap on the
 * line settled.
 *
 * Real-world rules applied:
 *  - Mobile-money issuers (CAZACOM, MTN) are not central bank members; their
 *    sponsor bank pays and receives for them (settlement.json).
 *  - "On-us" swaps, where payer and payee settle at the same bank, need no
 *    interbank payment and are closed as settled within that bank.
 *  - A swap or invoice is advised exactly once (settlement_advice_items key).
 *  - Only items created before the cycle time are included; later ones wait
 *    for the next cycle.
 *
 * Schedule every 15 minutes. It creates one advice per paying bank per cycle
 * (08:00, 10:30, 13:00, 15:30, 17:30, 20:00 Botswana time) and retries
 * delivery of any advice a bank did not acknowledge.
 * Options: --now  treat the current moment as a cycle (for testing).
 * Exit: 0 ok, 1 some deliveries failed, 2 fatal, 3 another run holds the lock.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Infrastructure/Adapters/InstitutionAdapterFactory.php';
require_once __DIR__ . '/../../src/Domain/Services/Fees/FeeLedger.php';

use Core\Config\LoadCountry;
use Infrastructure\Adapters\InstitutionAdapterFactory;

$tz = new DateTimeZone('Africa/Gaborone');
$now = new DateTimeImmutable('now', $tz);
$cfg = json_decode((string)file_get_contents(__DIR__ . '/../Core/Config/Countries/Botswana/settlement.json'), true) ?: [];
$sponsor = $cfg['settling_bank'] ?? [];
$members = $cfg['central_bank_members'] ?? [];
$clearingPrefix = $cfg['clearing_account_prefix'] ?? 'VMCLR-';
$feeBank = getenv($cfg['fee_account']['bank_env'] ?? 'VOUCHMORPH_FEE_BANK') ?: ($cfg['fee_account']['default_bank'] ?? 'ZURUBANK');
$feeAccount = getenv($cfg['fee_account']['account_env'] ?? 'VOUCHMORPH_FEE_ACCOUNT') ?: ($cfg['fee_account']['default_account'] ?? 'VOUCHMORPH-FEES');
$settling = fn(string $inst): string => $sponsor[strtoupper($inst)] ?? strtoupper($inst);
$feeSource = strtoupper($cfg['fee_source'] ?? 'INVOICE');
// Nothing created before this moment is advised (backlog held for clean-up).
$adviseFromUtc = (new DateTimeImmutable($cfg['advise_from'] ?? '1970-01-01T00:00:00Z'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');   // LEDGER: shares from fee_ledger (Section 23 Rev. 2)

function latest_cycle(DateTimeImmutable $now, array $cycles): DateTimeImmutable {
    sort($cycles);
    $best = null;
    foreach ([$now->modify('-1 day'), $now] as $day) {
        foreach ($cycles as $c) {
            [$h, $m] = array_map('intval', explode(':', $c));
            $t = $day->setTime($h, $m);
            if ($t <= $now && ($best === null || $t > $best)) $best = $t;
        }
    }
    return $best ?? $now;
}
function next_business_noon(DateTimeImmutable $t): DateTimeImmutable {
    $d = $t->modify('+1 day');
    while ((int)$d->format('N') >= 6) $d = $d->modify('+1 day');
    return $d->setTime(12, 0);
}
function money(float $v): string { return number_format($v, 2, '.', ''); }

$cycleAt = in_array('--now', $argv ?? [], true) ? $now : latest_cycle($now, $cfg['cycles'] ?? ['08:00', '10:30', '13:00', '15:30', '17:30', '20:00']);
$stamp = $cycleAt->format('ymdHi');
$cycleUtc = $cycleAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

try {
    $db = \Core\Database\DBConnection::getConnection();
    if (!$db) throw new RuntimeException('Database unavailable');
    if (!$db->query("SELECT pg_try_advisory_lock(7743003)")->fetchColumn()) { error_log('[ADVICE] another run in progress'); exit(3); }
    $hasStatusCol = (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'swap_requests' AND column_name = 'settlement_status'")->fetchColumn();

    $stats = ['cycle' => $cycleAt->format(DATE_ATOM), 'advise_from' => $adviseFromUtc . ' UTC', 'fee_source' => $feeSource, 'swaps_advised' => 0, 'invoices_advised' => 0, 'fee_shares_advised' => 0, 'fee_shares_on_us' => 0, 'on_us_closed' => 0,
              'skipped_no_instruction' => 0, 'skipped_not_member' => 0, 'advices_created' => 0, 'delivered' => 0, 'delivery_failed' => 0];

    // ---------- 1. collect what is owed and not yet advised ----------
    $swaps = $db->prepare("
        SELECT DISTINCT ON (sc.swap_reference)
               sc.confirmation_id, sc.swap_reference, sc.destination_institution, so.source_institution,
               so.amount, COALESCE(so.currency, sc.currency, 'BWP') AS currency
        FROM settlement_confirmations sc
        JOIN settlement_outbox so ON so.swap_reference = sc.swap_reference AND so.message_type = 'SETTLEMENT_INSTRUCTION'
        WHERE sc.status = 'PENDING' AND sc.advice_line_ref IS NULL AND sc.created_at <= ? AND sc.created_at >= ?
          AND NOT EXISTS (SELECT 1 FROM settlement_advice_items i WHERE i.item_type = 'SWAP' AND i.item_reference = sc.swap_reference)
        ORDER BY sc.swap_reference, so.created_at ASC
    ");
    $swaps->execute([$cycleUtc, $adviseFromUtc]);
    $swapRows = $swaps->fetchAll(PDO::FETCH_ASSOC);

    $noInstr = $db->prepare("
        SELECT COUNT(*) FROM settlement_confirmations sc
        WHERE sc.status = 'PENDING' AND sc.advice_line_ref IS NULL AND sc.created_at <= ? AND sc.created_at >= ?
          AND NOT EXISTS (SELECT 1 FROM settlement_outbox so WHERE so.swap_reference = sc.swap_reference AND so.message_type = 'SETTLEMENT_INSTRUCTION')
    ");
    $noInstr->execute([$cycleUtc, $adviseFromUtc]);
    $stats['skipped_no_instruction'] = (int)$noInstr->fetchColumn();

    $fees = $db->prepare("
        SELECT so.message_uuid::text AS message_uuid, so.source_institution, so.amount, so.currency
        FROM settlement_outbox so
        WHERE so.message_type = 'FEE_INVOICE' AND so.status NOT IN ('ACKNOWLEDGED', 'CANCELLED') AND so.created_at <= ? AND so.created_at >= ?
          AND NOT EXISTS (SELECT 1 FROM settlement_advice_items i WHERE i.item_type = 'FEE_INVOICE' AND i.item_reference = so.message_uuid::text)
    ");
    $fees->execute([$cycleUtc, $adviseFromUtc]);
    // With the fee ledger live, the old whole-fee invoices are not advised (they overcharge the source).
    $feeRows = $feeSource === 'LEDGER' ? [] : $fees->fetchAll(PDO::FETCH_ASSOC);

    // Fee-ledger shares owed by the institution holding the customer's fee to the
    // institution that earned them. The payer's own shares are RETAINED_BY_PAYER
    // and never appear here.
    $shareRows = [];
    if ($feeSource === 'LEDGER') {
        $sh = $db->prepare("
            SELECT f.entry_id, f.leg_reference, f.fee_role, f.institution, f.payer_institution, f.amount, f.currency
            FROM fee_ledger f
            WHERE f.status = 'EARNED' AND f.earned_at <= ? AND f.earned_at >= ?
              AND NOT EXISTS (SELECT 1 FROM settlement_advice_items i WHERE i.item_type = 'FEE_LEDGER' AND i.item_reference = f.entry_id::text)
            ORDER BY f.entry_id
        ");
        $sh->execute([$cycleUtc, $adviseFromUtc]);
        $shareRows = $sh->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------- 2. group into lines per paying bank ----------
    $lines = [];   // [debtor][key] => line
    $onUs = [];
    foreach ($swapRows as $r) {
        $debtor = $settling($r['source_institution']);
        $creditor = $settling($r['destination_institution']);
        if ($debtor === $creditor) { $onUs[] = $r + ['bank' => $debtor]; continue; }
        if (!in_array($debtor, $members, true) || !in_array($creditor, $members, true)) { $stats['skipped_not_member']++; continue; }
        $key = "P|{$creditor}";
        $lines[$debtor][$key] ??= ['kind' => 'PRINCIPAL', 'creditor_bank' => $creditor, 'creditor_account' => $clearingPrefix . $creditor,
                                   'amount' => 0.0, 'currency' => $r['currency'], 'items' => []];
        $lines[$debtor][$key]['amount'] += (float)$r['amount'];
        $lines[$debtor][$key]['items'][] = ['type' => 'SWAP', 'reference' => $r['swap_reference'], 'amount' => (float)$r['amount'], 'confirmation_id' => (int)$r['confirmation_id']];
    }
    foreach ($feeRows as $r) {
        $debtor = $settling($r['source_institution']);
        if (!in_array($debtor, $members, true)) { $stats['skipped_not_member']++; continue; }
        $key = "F|{$feeBank}";
        $lines[$debtor][$key] ??= ['kind' => 'FEE', 'creditor_bank' => $feeBank, 'creditor_account' => $feeAccount,
                                   'amount' => 0.0, 'currency' => $r['currency'] ?: 'BWP', 'items' => []];
        $lines[$debtor][$key]['amount'] += (float)$r['amount'];
        $lines[$debtor][$key]['items'][] = ['type' => 'FEE_INVOICE', 'reference' => $r['message_uuid'], 'amount' => (float)$r['amount']];
    }

    // Fee-ledger shares: VouchMorph's (levy + 35%) on the fee line to its fee account;
    // a destination's shares travel on its principal line (paid with its settlement).
    $onUsShares = [];
    foreach ($shareRows as $r) {
        $debtor = $settling($r['payer_institution']);
        if (!in_array($debtor, $members, true)) { $stats['skipped_not_member']++; continue; }
        if ($r['institution'] === 'VOUCHMORPH') {
            $key = "F|{$feeBank}";
            $lines[$debtor][$key] ??= ['kind' => 'FEE', 'creditor_bank' => $feeBank, 'creditor_account' => $feeAccount,
                                       'amount' => 0.0, 'currency' => $r['currency'] ?: 'BWP', 'items' => []];
        } else {
            $creditor = $settling($r['institution']);
            if ($creditor === $debtor) { $onUsShares[] = (int)$r['entry_id']; continue; }   // same settling bank: settled internally
            if (!in_array($creditor, $members, true)) { $stats['skipped_not_member']++; continue; }
            $key = "P|{$creditor}";
            $lines[$debtor][$key] ??= ['kind' => 'PRINCIPAL', 'creditor_bank' => $creditor, 'creditor_account' => $clearingPrefix . $creditor,
                                       'amount' => 0.0, 'currency' => $r['currency'] ?: 'BWP', 'items' => []];
        }
        $lines[$debtor][$key]['amount'] += (float)$r['amount'];
        $lines[$debtor][$key]['items'][] = ['type' => 'FEE_LEDGER', 'reference' => (string)$r['entry_id'], 'amount' => (float)$r['amount'], 'entry_id' => (int)$r['entry_id']];
    }
    if ($onUsShares) {
        $in = implode(',', array_fill(0, count($onUsShares), '?'));
        $db->prepare("UPDATE fee_ledger SET status = 'PAID', paid_at = NOW(), note = COALESCE(note, '') || 'On-us: payer and earner settle at the same bank' WHERE entry_id IN ($in) AND status = 'EARNED'")->execute($onUsShares);
        $stats['fee_shares_on_us'] = count($onUsShares);
    }

    // ---------- 3. on-us swaps: settled inside one bank, no interbank payment ----------
    foreach ($onUs as $r) {
        $db->prepare("UPDATE settlement_confirmations SET status = 'CONFIRMED', confirmed_at = NOW(), last_error = ? WHERE confirmation_id = ? AND status = 'PENDING'")
           ->execute(["On-us: payer and payee settle at {$r['bank']}; no interbank payment", $r['confirmation_id']]);
        if ($hasStatusCol) {
            $db->prepare("UPDATE swap_requests SET settlement_status = 'CONFIRMED', settlement_confirmed_at = NOW() WHERE swap_uuid = ?")->execute([$r['swap_reference']]);
        }
        $stats['on_us_closed']++;
        // The bank settled it internally, so it earns the settlement fee.
        if ($feeSource === 'LEDGER') {
            \Domain\Services\Fees\FeeLedger::fromCountryConfig($db)->settleLeg((string)$r['swap_reference'], (string)$r['bank']);
        }
    }

    // ---------- 4. write one advice per paying bank ----------
    foreach ($lines as $debtor => $debtorLines) {
        $adviceId = "ADV-{$stamp}-{$debtor}";
        $n = 1;
        $exists = $db->prepare("SELECT 1 FROM settlement_advices WHERE advice_id = ?");
        while (true) { $exists->execute([$adviceId]); if (!$exists->fetchColumn()) break; $adviceId = "ADV-{$stamp}-{$debtor}-" . (++$n); }

        $db->beginTransaction();
        $total = array_sum(array_map(fn($l) => round($l['amount'], 2), $debtorLines));
        $db->prepare("INSERT INTO settlement_advices (advice_id, cycle_at, debtor_bank, line_count, total_amount, currency) VALUES (?, ?, ?, ?, ?, 'BWP')")
           ->execute([$adviceId, $cycleUtc, $debtor, count($debtorLines), money($total)]);
        foreach ($debtorLines as $l) {
            $base = 'VM' . $stamp . '-' . substr($debtor, 0, 4) . substr($l['creditor_bank'], 0, 4) . '-' . ($l['kind'] === 'FEE' ? 'F' : 'P');
            $ref = $base . ($n > 1 ? $n : '');
            $db->prepare("INSERT INTO settlement_advice_lines (line_ref, advice_id, kind, debtor_bank, creditor_bank, creditor_account, amount, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$ref, $adviceId, $l['kind'], $debtor, $l['creditor_bank'], $l['creditor_account'], money($l['amount']), $l['currency']]);
            $item = $db->prepare("INSERT INTO settlement_advice_items (line_ref, item_type, item_reference, amount) VALUES (?, ?, ?, ?)");
            foreach ($l['items'] as $it) {
                $item->execute([$ref, $it['type'], $it['reference'], money($it['amount'])]);
                if ($it['type'] === 'SWAP') {
                    $db->prepare("UPDATE settlement_confirmations SET advice_line_ref = ? WHERE confirmation_id = ?")->execute([$ref, $it['confirmation_id']]);
                    $stats['swaps_advised']++;
                } elseif ($it['type'] === 'FEE_LEDGER') {
                    $db->prepare("UPDATE fee_ledger SET status = 'ADVISED', advice_line_ref = ? WHERE entry_id = ? AND status = 'EARNED'")->execute([$ref, $it['entry_id']]);
                    $stats['fee_shares_advised']++;
                } else {
                    $stats['invoices_advised']++;
                }
            }
        }
        $db->commit();
        $stats['advices_created']++;
    }

    // ---------- 5. deliver every advice not yet acknowledged by its bank ----------
    $participants = LoadCountry::getConfig()['participants'] ?? [];
    $logger = new class {
        public function info($m, $c = []) {} public function debug($m, $c = []) {} public function log($l, $m, $c = []) {}
        public function error($m, $c = []) { error_log("[ADVICE] ERROR: {$m} " . json_encode($c)); }
        public function warning($m, $c = []) { error_log("[ADVICE] WARNING: {$m} " . json_encode($c)); }
    };
    $factory = new InstitutionAdapterFactory($participants, $logger);

    $pending = $db->query("SELECT * FROM settlement_advices WHERE status IN ('CREATED', 'DELIVERY_FAILED') AND delivery_attempts < 20 ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pending as $a) {
        $lineRows = $db->prepare("SELECT * FROM settlement_advice_lines WHERE advice_id = ? ORDER BY line_ref");
        $lineRows->execute([$a['advice_id']]);
        $payloadLines = [];
        foreach ($lineRows->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $its = $db->prepare("SELECT item_type AS type, item_reference AS reference, amount FROM settlement_advice_items WHERE line_ref = ? ORDER BY item_reference");
            $its->execute([$l['line_ref']]);
            $payloadLines[] = [
                'line_ref' => $l['line_ref'], 'kind' => $l['kind'], 'creditor_bank' => $l['creditor_bank'],
                'creditor_account' => $l['creditor_account'], 'amount' => money((float)$l['amount']), 'currency' => $l['currency'],
                'items' => array_map(fn($i) => ['type' => $i['type'], 'reference' => $i['reference'], 'amount' => money((float)$i['amount'])], $its->fetchAll(PDO::FETCH_ASSOC)),
            ];
        }
        $cycleLocal = (new DateTimeImmutable($a['cycle_at'], new DateTimeZone('UTC')))->setTimezone($tz);
        $payload = [
            'message_type' => 'SETTLEMENT_ADVICE',
            'advice_id' => $a['advice_id'],
            'debtor_bank' => $a['debtor_bank'],
            'cycle_at' => $cycleLocal->format(DATE_ATOM),
            'due_by' => next_business_noon($cycleLocal)->format(DATE_ATOM),
            'currency' => $a['currency'],
            'total_amount' => money((float)$a['total_amount']),
            'pay_via' => 'CENTRAL_BANK',
            'instructions' => 'Pay each line through the central bank using line_ref as reference_code, to creditor_bank / creditor_account.',
            'lines' => $payloadLines,
        ];
        try {
            $res = $factory->getAdapter($a['debtor_bank'])->sendSettlementAdvice($payload);
        } catch (Throwable $e) {
            $res = ['success' => false, 'message' => $e->getMessage()];
        }
        if (!empty($res['success'])) {
            $db->prepare("UPDATE settlement_advices SET status = 'DELIVERED', delivered_at = NOW(), delivery_attempts = delivery_attempts + 1, last_error = NULL WHERE advice_id = ?")->execute([$a['advice_id']]);
            $stats['delivered']++;
        } else {
            $db->prepare("UPDATE settlement_advices SET status = 'DELIVERY_FAILED', delivery_attempts = delivery_attempts + 1, last_error = ? WHERE advice_id = ?")
               ->execute([mb_substr((string)($res['message'] ?? 'delivery failed'), 0, 500), $a['advice_id']]);
            $stats['delivery_failed']++;
            error_log("[ADVICE] {$a['advice_id']} not delivered to {$a['debtor_bank']}: " . ($res['message'] ?? '?'));
        }
    }

    $db->query("SELECT pg_advisory_unlock(7743003)");
    echo json_encode($stats) . PHP_EOL;
    exit($stats['delivery_failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[ADVICE] FATAL: ' . $e->getMessage());
    echo json_encode(['fatal' => $e->getMessage()]) . PHP_EOL;
    exit(2);
}
