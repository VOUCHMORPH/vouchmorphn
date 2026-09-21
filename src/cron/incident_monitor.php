<?php
declare(strict_types=1);
/**
 * cron/incident_monitor.php - run every 5 minutes.
 *
 * Checks every condition VM-GOV-001 says needs a person, raises an alarm
 * (with what to do now and who to call), opens an incident with its playbook
 * steps where the rule calls for one, notifies the roles concerned, and
 * clears alarms whose condition has gone. Each rule runs on its own: one
 * failing (for example a table that does not exist yet) never stops the rest.
 *
 * Rules
 *   CAP_BREACH            completed transaction above the sandbox cap (S11, SEV1)
 *   SETTLEMENT_FAILED     receiving bank says a settlement was not received (S3, SEV2)
 *   SETTLEMENT_OVERDUE    settlements past noon next business day (S2, SEV3)
 *   ADVICE_UNDELIVERED    settlement advice not delivered after 3 attempts (SEV3)
 *   HOLDS_NEAR_EXPIRY     holds open more than 20 hours (S7, SEV2)
 *   AUDIT_CHAIN_BROKEN    tamper-evident audit trail fails verification (S9, SEV1)
 *   BANK_NOTICE_OVERDUE   2-hour Bank notice not sent for a SEV1/SEV2 (SEV1)
 *   REPORT_48H_OVERDUE    48-hour written report not sent (SEV2)
 *   ACTION_OVERDUE        a playbook step past its deadline (SEV3, notifies its owner)
 *   SELF_BILLED_INVOICE   fee invoices addressed to VouchMorph itself (SEV4)
 *   DAILY_SIGNOFF_MISSING yesterday's reconciliation not signed by 09:00 (S2, SEV3)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Application/Admin/AdminAudit.php';
require_once __DIR__ . '/../../src/Application/Incident/Playbooks.php';
require_once __DIR__ . '/../../src/Application/Incident/ReportBuilder.php';
require_once __DIR__ . '/../../src/Application/Incident/IncidentDesk.php';
require_once __DIR__ . '/../../src/Application/Incident/ServiceControls.php';

use Application\Incident\IncidentDesk;
use Application\Incident\ServiceControls;

$db = \Core\Database\DBConnection::getConnection();
if (!$db) { fwrite(STDERR, "[MONITOR] no database\n"); exit(2); }
if (!$db->query("SELECT pg_try_advisory_lock(7743011)")->fetchColumn()) { exit(3); }
$desk = new IncidentDesk($db);
$tz = new DateTimeZone('Africa/Gaborone');
$now = new DateTimeImmutable('now', $tz);
$stats = [];

function rule(string $name, callable $fn, array &$stats): void {
    try { $stats[$name] = $fn(); }
    catch (Throwable $e) { $stats[$name] = 'skipped: ' . substr($e->getMessage(), 0, 120); }
}

// S11: completed transactions above the cap. One alarm per transaction; the
// first opens a SEV1 incident, the rest attach to it while it is open.
rule('CAP_BREACH', function () use ($db, $desk) {
    $cap = ServiceControls::capLimit();
    $rows = $db->query("
        SELECT swap_uuid, amount, user_id, created_at, COALESCE(metadata->>'swap_type', '') AS swap_type,
               COALESCE(metadata->>'source_institution', '') AS source
        FROM swap_requests WHERE LOWER(status) = 'completed' AND amount > {$cap} ORDER BY created_at
    ")->fetchAll(PDO::FETCH_ASSOC);
    $new = 0;
    foreach ($rows as $r) {
        $id = $desk->raiseAlert('CAP_BREACH', 'CAP_BREACH:' . $r['swap_uuid'],
            sprintf('Completed %s of P%s exceeded the P%s cap (%s)', $r['swap_type'] ?: 'transaction', number_format((float)$r['amount'], 2), number_format($cap, 0), $r['swap_uuid']),
            ['swap_reference' => $r['swap_uuid'], 'amount' => (float)$r['amount'], 'user_id' => $r['user_id'], 'source' => $r['source'], 'completed_at' => $r['created_at'], 'cap' => $cap]);
        if ($id) $new++;
    }
    // Keep one incident for the batch: attach later breach alarms to the first open S11 incident.
    $db->exec("
        UPDATE ic_alerts a SET incident_id = x.incident_id
        FROM (SELECT incident_id FROM ic_incidents WHERE playbook = 'S11' AND status <> 'CLOSED' ORDER BY opened_at LIMIT 1) x
        WHERE a.rule_code = 'CAP_BREACH' AND a.status <> 'RESOLVED' AND a.incident_id IS NULL
    ");
    if ($rows) {
        $total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
        // The incident's headline states the whole breach, not just the first transaction found.
        $db->prepare("UPDATE ic_incidents SET title = ?, money_at_risk = COALESCE(money_at_risk, ?), customers_affected = COALESCE(customers_affected, ?)
                      WHERE playbook = 'S11' AND status <> 'CLOSED' AND trigger_source = 'AUTO'")
           ->execute([sprintf('%d completed transaction(s) exceeded the P%s sandbox cap, P%s in total', count($rows), number_format($cap, 0), number_format($total, 2)),
                      $total, count(array_unique(array_column($rows, 'user_id')))]);
    }
    return count($rows) . " breaching, {$new} new";
}, $stats);

rule('SETTLEMENT_FAILED', function () use ($db, $desk) {
    $rows = $db->query("SELECT swap_reference, destination_institution, amount, last_error FROM settlement_confirmations WHERE status = 'FAILED'")->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($rows as $r) {
        $keys[] = $k = 'SETTLEMENT_FAILED:' . $r['swap_reference'];
        $desk->raiseAlert('SETTLEMENT_FAILED', $k, "{$r['destination_institution']} reports settlement of {$r['swap_reference']} (P{$r['amount']}) not received", $r);
    }
    return count($rows) . ' failed, ' . $desk->autoResolve('SETTLEMENT_FAILED', $keys) . ' cleared';
}, $stats);

rule('SETTLEMENT_OVERDUE', function () use ($db, $desk) {
    $rows = $db->query("
        SELECT destination_institution, COUNT(*) AS n, SUM(amount) AS amount, MIN(created_at) AS oldest
        FROM settlement_confirmations WHERE status = 'PENDING' AND overdue_at IS NOT NULL GROUP BY 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($rows as $r) {
        $keys[] = $k = 'SETTLEMENT_OVERDUE:' . $r['destination_institution'];
        $desk->raiseAlert('SETTLEMENT_OVERDUE', $k, "{$r['n']} settlements to {$r['destination_institution']} overdue (P" . number_format((float)$r['amount'], 2) . ')', $r);
    }
    return count($rows) . ' institutions, ' . $desk->autoResolve('SETTLEMENT_OVERDUE', $keys) . ' cleared';
}, $stats);

rule('ADVICE_UNDELIVERED', function () use ($db, $desk) {
    $rows = $db->query("SELECT advice_id, debtor_bank, total_amount, delivery_attempts, last_error FROM settlement_advices WHERE status = 'DELIVERY_FAILED' AND delivery_attempts >= 3")->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($rows as $r) {
        $keys[] = $k = 'ADVICE_UNDELIVERED:' . $r['advice_id'];
        $desk->raiseAlert('ADVICE_UNDELIVERED', $k, "Settlement advice {$r['advice_id']} to {$r['debtor_bank']} undelivered after {$r['delivery_attempts']} attempts", $r);
    }
    return count($rows) . ' undelivered, ' . $desk->autoResolve('ADVICE_UNDELIVERED', $keys) . ' cleared';
}, $stats);

rule('HOLDS_NEAR_EXPIRY', function () use ($db, $desk) {
    $rows = $db->query("
        SELECT participant_name, COUNT(*) AS n, MIN(created_at) AS oldest FROM hold_transactions
        WHERE UPPER(status) IN ('ACTIVE', 'HELD') AND created_at < NOW() - INTERVAL '20 hours' GROUP BY 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($rows as $r) {
        $keys[] = $k = 'HOLDS_NEAR_EXPIRY:' . $r['participant_name'];
        $desk->raiseAlert('HOLDS_NEAR_EXPIRY', $k, "{$r['n']} holds at {$r['participant_name']} open more than 20 hours", $r);
    }
    return count($rows) . ' institutions, ' . $desk->autoResolve('HOLDS_NEAR_EXPIRY', $keys) . ' cleared';
}, $stats);

rule('AUDIT_CHAIN_BROKEN', function () use ($db, $desk) {
    $has = $db->query("SELECT to_regprocedure('audit_chain_verify()') IS NOT NULL")->fetchColumn();
    if (!$has) return 'audit chain migration not applied';
    $db->beginTransaction();
    $db->exec("SET LOCAL statement_timeout = 20000");
    $v = $db->query("SELECT intact, first_bad_seq, reason FROM audit_chain_verify()")->fetch(PDO::FETCH_ASSOC);
    $db->commit();
    if (!$v['intact']) {
        $desk->raiseAlert('AUDIT_CHAIN_BROKEN', 'AUDIT_CHAIN_BROKEN', "Audit trail fails verification at position {$v['first_bad_seq']}: {$v['reason']}", $v);
        return 'BROKEN';
    }
    $desk->autoResolve('AUDIT_CHAIN_BROKEN', []);
    return 'intact';
}, $stats);

rule('BANK_NOTICE_OVERDUE', function () use ($db, $desk) {
    $rows = $db->query("SELECT incident_id, severity, title, bank_notify_due FROM ic_incidents WHERE status <> 'CLOSED' AND bank_notified_at IS NULL AND bank_notify_due < NOW()")->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($rows as $r) {
        $keys[] = $k = 'BANK_NOTICE_OVERDUE:' . $r['incident_id'];
        $desk->raiseAlert('BANK_NOTICE_OVERDUE', $k, "Bank of Botswana notice overdue for {$r['incident_id']} ({$r['severity']}): {$r['title']}", $r);
    }
    return count($rows) . ' overdue, ' . $desk->autoResolve('BANK_NOTICE_OVERDUE', $keys) . ' cleared';
}, $stats);

rule('REPORT_48H_OVERDUE', function () use ($db, $desk) {
    $rows = $db->query("SELECT incident_id, severity, title FROM ic_incidents WHERE status <> 'CLOSED' AND report_48h_sent_at IS NULL AND report_48h_due < NOW()")->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($rows as $r) {
        $keys[] = $k = 'REPORT_48H_OVERDUE:' . $r['incident_id'];
        $desk->raiseAlert('REPORT_48H_OVERDUE', $k, "48-hour report overdue for {$r['incident_id']}: {$r['title']}", $r);
    }
    return count($rows) . ' overdue, ' . $desk->autoResolve('REPORT_48H_OVERDUE', $keys) . ' cleared';
}, $stats);

rule('ACTION_OVERDUE', function () use ($db, $desk) {
    $rows = $db->query("
        SELECT a.action_id, a.incident_id, a.step_no, a.owner_role, a.action, a.due_at FROM ic_incident_actions a
        JOIN ic_incidents i ON i.incident_id = a.incident_id
        WHERE a.status = 'PENDING' AND a.due_at < NOW() AND i.status <> 'CLOSED'
    ")->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($rows as $r) {
        $keys[] = $k = 'ACTION_OVERDUE:' . $r['action_id'];
        if ($desk->raiseAlert('ACTION_OVERDUE', $k, "{$r['incident_id']} step {$r['step_no']} ({$r['owner_role']}) is overdue", $r)) {
            $desk->notify([$r['owner_role']], "Overdue: {$r['incident_id']} step {$r['step_no']}", "Your step in incident {$r['incident_id']} is past its deadline:\n\n{$r['action']}\n\nComplete it in Incident Command, or record why it cannot be done.", null, $r['incident_id']);
        }
    }
    return count($rows) . ' overdue, ' . $desk->autoResolve('ACTION_OVERDUE', $keys) . ' cleared';
}, $stats);

rule('SELF_BILLED_INVOICE', function () use ($db, $desk) {
    $r = $db->query("
        SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM settlement_outbox
        WHERE message_type = 'FEE_INVOICE' AND status <> 'ACKNOWLEDGED' AND source_institution LIKE 'VOUCHMORPH%'
          AND NOT EXISTS (SELECT 1 FROM acct_fee_reversals f WHERE f.invoice_uuid = settlement_outbox.message_uuid::text AND f.status = 'APPROVED')
    ")->fetch(PDO::FETCH_ASSOC);
    if ((int)$r['n'] > 0) {
        $desk->raiseAlert('SELF_BILLED_INVOICE', 'SELF_BILLED_INVOICE', "{$r['n']} fee invoices addressed to VouchMorph itself (P" . number_format((float)$r['amount'], 2) . ')', $r);
    } else {
        $desk->autoResolve('SELF_BILLED_INVOICE', []);
    }
    return "{$r['n']} open";
}, $stats);

rule('DAILY_SIGNOFF_MISSING', function () use ($db, $desk, $now) {
    if ((int)$now->format('G') < 9) return 'before 09:00';
    $d = $now->modify('-1 day');
    while ((int)$d->format('N') >= 6) $d = $d->modify('-1 day');
    $date = $d->format('Y-m-d');
    $s = $db->prepare("SELECT 1 FROM acct_daily_signoffs WHERE business_date = ?");
    $s->execute([$date]);
    if ($s->fetchColumn()) { $desk->autoResolve('DAILY_SIGNOFF_MISSING', []); return "{$date} signed"; }
    $desk->raiseAlert('DAILY_SIGNOFF_MISSING', "DAILY_SIGNOFF_MISSING:{$date}", "Reconciliation for {$date} not signed off", ['business_date' => $date]);
    return "{$date} missing";
}, $stats);

$db->query("SELECT pg_advisory_unlock(7743011)");
echo json_encode($stats) . PHP_EOL;
