<?php
// public/admin/accounts.php
//
// The Accountant's workspace (VM-GOV-001: S2 daily sign-off, S3 reconciliation,
// S6 fee reversals, S12 month-end pack).
//   Daily        - the day's figures and exceptions; signing produces the signed
//                  sheet (a report) and clears the "not signed off" alarm
//   Settlement   - every advice line by cycle: owed, paid, the proof, what is open
//   Fees         - invoiced vs paid, reconciled against the paid fee lines;
//                  outstanding by payer and age
//   Reversals    - Accountant requests, Managing Director approves (four-eyes)
//   Month end    - the month-end pack, as a report, with CSV extracts
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__, 2));
require_once PROJECT_ROOT . '/vendor/autoload.php';
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/AdminAudit.php';
require_once PROJECT_ROOT . '/src/Application/Incident/Playbooks.php';
require_once PROJECT_ROOT . '/src/Application/Incident/ReportBuilder.php';
require_once PROJECT_ROOT . '/src/Application/Incident/IncidentDesk.php';

use Application\Utils\SessionManager;
use Application\Admin\AdminAudit;
use Application\Incident\IncidentDesk;
use Application\Incident\ReportBuilder;

SessionManager::start();
if (!SessionManager::isAdminLoggedIn()) { header('Location: admin_login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$adminId = (int)SessionManager::getAdminId();
$role = (int)SessionManager::getAdminRoleId();
$adminName = (string)(SessionManager::get('admin_full_name') ?: SessionManager::getAdminUsername() ?: "admin {$adminId}");
if (!in_array($role, [999, 10, 11, 4, 5, 3], true)) { http_response_code(403); exit('Your role does not have access to Accounts.'); }
$isAccountant = $role === 10 || $role === 999;
$db = \Core\Database\DBConnection::getConnection();
$desk = new IncidentDesk($db);
$tz = new DateTimeZone('Africa/Gaborone');

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function p($v): string { return 'P' . number_format((float)$v, 2); }
function ts(?string $t): string { return $t ? (new DateTimeImmutable($t, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Gaborone'))->format('d M H:i') : '-'; }
function q(PDO $db, string $sql, array $args = []): array { try { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return [['_error' => $e->getMessage()]]; } }
function one(PDO $db, string $sql, array $args = []) { $r = q($db, $sql, $args); return $r[0] ?? []; }

/** A business day's figures, from the swap and settlement records (Botswana day, stored as UTC). */
function dayFigures(PDO $db, string $date): array {
    $from = (new DateTimeImmutable($date . ' 00:00', new DateTimeZone('Africa/Gaborone')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $to = (new DateTimeImmutable($date . ' 00:00 +1 day', new DateTimeZone('Africa/Gaborone')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $sw = one($db, "SELECT COUNT(*) n, COALESCE(SUM(amount),0) v FROM swap_requests WHERE LOWER(status)='completed' AND created_at >= ? AND created_at < ?", [$from, $to]);
    $fi = one($db, "SELECT COUNT(*) n, COALESCE(SUM(amount),0) v FROM settlement_outbox WHERE message_type='FEE_INVOICE' AND created_at >= ? AND created_at < ?", [$from, $to]);
    $fp = one($db, "SELECT COUNT(*) n, COALESCE(SUM(amount),0) v FROM settlement_outbox WHERE message_type='FEE_INVOICE' AND status='ACKNOWLEDGED' AND acknowledged_at >= ? AND acknowledged_at < ?", [$from, $to]);
    $sc = one($db, "SELECT COUNT(*) n, COALESCE(SUM(amount),0) v FROM settlement_confirmations WHERE status='CONFIRMED' AND confirmed_at >= ? AND confirmed_at < ?", [$from, $to]);
    $op = one($db, "SELECT COUNT(*) n, COALESCE(SUM(amount),0) v, COUNT(*) FILTER (WHERE overdue_at IS NOT NULL) od FROM settlement_confirmations WHERE status='PENDING'");
    $fl = one($db, "SELECT COALESCE(SUM(amount) FILTER (WHERE kind='FEE' AND status='PAID' AND paid_at >= ? AND paid_at < ?),0) paid, COUNT(*) FILTER (WHERE status='OPEN') open FROM settlement_advice_lines", [$from, $to]);
    $cap = one($db, "SELECT COUNT(*) n FROM swap_requests WHERE LOWER(status)='completed' AND amount > 7000 AND created_at >= ? AND created_at < ?", [$from, $to]);
    return [
        'Swaps completed' => ($sw['n'] ?? 0) . ' · ' . p($sw['v'] ?? 0),
        'Fees invoiced' => ($fi['n'] ?? 0) . ' invoices · ' . p($fi['v'] ?? 0),
        'Fees received (invoices marked paid)' => ($fp['n'] ?? 0) . ' · ' . p($fp['v'] ?? 0),
        'Fee lines received at VouchMorph\'s fee account' => p($fl['paid'] ?? 0),
        'Fee check (received vs lines)' => abs((float)($fp['v'] ?? 0) - (float)($fl['paid'] ?? 0)) < 0.005 ? 'MATCHES' : 'DIFFERENCE ' . p((float)($fp['v'] ?? 0) - (float)($fl['paid'] ?? 0)),
        'Settlements confirmed' => ($sc['n'] ?? 0) . ' · ' . p($sc['v'] ?? 0),
        'Settlements still open (all days)' => ($op['n'] ?? 0) . ' · ' . p($op['v'] ?? 0) . ' · overdue ' . ($op['od'] ?? 0),
        'Advice lines open' => (string)($fl['open'] ?? 0),
        'Transactions above the P7,000 cap' => (string)($cap['n'] ?? 0),
    ];
}

// ------------------------------------------------------------------ CSV extracts
if (isset($_GET['csv'])) {
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : (new DateTimeImmutable('first day of last month', $tz))->format('Y-m');
    $sets = [
        'advice_lines' => ["SELECT l.line_ref, l.advice_id, l.kind, l.debtor_bank, l.creditor_bank, l.creditor_account, l.amount, l.status, l.receipt_reference, l.paid_at, l.created_at FROM settlement_advice_lines l WHERE to_char(l.created_at, 'YYYY-MM') = ? ORDER BY l.created_at", [$month]],
        'fee_invoices' => ["SELECT message_uuid, swap_reference, source_institution AS payer, amount, status, created_at, acknowledged_at FROM settlement_outbox WHERE message_type='FEE_INVOICE' AND to_char(created_at, 'YYYY-MM') = ? ORDER BY created_at", [$month]],
        'settlements' => ["SELECT swap_reference, destination_institution, amount, status, advice_line_ref, confirmed_at, overdue_at, created_at FROM settlement_confirmations WHERE to_char(created_at, 'YYYY-MM') = ? ORDER BY created_at", [$month]],
    ];
    $k = $_GET['csv'];
    if ($k === 'fee_statement') {
        // One institution's statement: every service it performed and the share it earned.
        $inst = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['institution'] ?? '')));
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
        $sets['fee_statement'] = ["SELECT earned_at, swap_reference, leg_reference, product, event AS service, fee_role AS share, institution, payer_institution AS paid_by,
                                          general_fee, percent_of_pool, amount, currency, status, advice_line_ref, paid_at, note
                                   FROM fee_ledger WHERE (institution = ? OR payer_institution = ?) AND earned_at >= ?::date AND earned_at < ?::date + 1
                                   ORDER BY earned_at, entry_id", [$inst, $inst, $from, $to]];
        $month = "{$inst}_{$from}_{$to}";
    }
    if (!isset($sets[$k])) { http_response_code(404); exit; }
    AdminAudit::recordOrLog($db, $adminId, 'REPORT_EXPORTED', 'accounts', $k, ['month' => $month, 'format' => 'csv'], 'DATA_ACCESS');
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"vouchmorph_{$k}_{$month}.csv\"");
    $rows = q($db, $sets[$k][0], $sets[$k][1]);
    $out = fopen('php://output', 'w');
    if ($rows && !isset($rows[0]['_error'])) { fputcsv($out, array_keys($rows[0])); foreach ($rows as $r) fputcsv($out, $r); }
    fclose($out); exit;
}

// ------------------------------------------------------------------ actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = $_POST['back'] ?? 'accounts.php';
    try {
        if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Your form expired. Nothing was changed.');
        switch ($_POST['action'] ?? '') {
            case 'signoff':
                if ($role !== 10 && $role !== 999) throw new RuntimeException('Only the Accountant signs the daily sheet.');
                $date = (string)$_POST['date'];
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Bad date.');
                $fig = dayFigures($db, $date);
                $ex = trim((string)($_POST['exceptions'] ?? ''));
                if (str_contains(implode(' ', $fig), 'DIFFERENCE') && mb_strlen($ex) < 10) throw new RuntimeException('The fee check shows a difference: explain it in the exceptions before signing.');
                $db->prepare("INSERT INTO acct_daily_signoffs (business_date, figures, exceptions, signed_by) VALUES (?, ?::jsonb, ?, ?)")->execute([$date, json_encode($fig), $ex ?: null, $adminId]);
                $rid = $desk->storeReport('DAILY_SIGNOFF', null, $date, "Daily reconciliation sign-off - {$date}",
                    ReportBuilder::dailySignoff($date, $fig, $ex ?: null, $adminName, gmdate('Y-m-d H:i:s')), 'INCIDENT_COMMANDER,ACCOUNTANT', $adminId);
                AdminAudit::recordOrLog($db, $adminId, 'DAILY_SIGNOFF', 'accounts', $date, ['report_id' => $rid], 'ADMIN');
                $db->prepare("UPDATE ic_alerts SET status='RESOLVED', resolved_by=?, resolved_at=NOW(), resolution='Signed off' WHERE dedupe_key = ? AND status <> 'RESOLVED'")->execute([$adminId, "DAILY_SIGNOFF_MISSING:{$date}"]);
                $msg = "Signed off {$date}. The signed sheet is report {$rid} (Incident Command > Reports)."; break;
            case 'reversal_request':
                if ($role !== 10) throw new RuntimeException('The Accountant requests fee reversals.');
                $inv = one($db, "SELECT message_uuid::text AS id, amount, status FROM settlement_outbox WHERE message_type='FEE_INVOICE' AND message_uuid::text = ?", [trim((string)$_POST['invoice_uuid'])]);
                if (!$inv || isset($inv['_error'])) throw new RuntimeException('No fee invoice with that id.');
                if ($inv['status'] === 'ACKNOWLEDGED') throw new RuntimeException('That invoice is already paid; a paid fee is refunded, not reversed.');
                if (mb_strlen(trim((string)$_POST['reason'])) < 10) throw new RuntimeException('Give the reason (at least 10 characters).');
                $db->prepare("INSERT INTO acct_fee_reversals (invoice_uuid, amount, reason, requested_by) VALUES (?, ?, ?, ?)")->execute([$inv['id'], $inv['amount'], trim((string)$_POST['reason']), $adminId]);
                AdminAudit::recordOrLog($db, $adminId, 'FEE_REVERSAL_REQUESTED', 'fee_invoice', $inv['id'], ['amount' => $inv['amount'], 'reason' => $_POST['reason']], 'ADMIN');
                $desk->notify(['INCIDENT_COMMANDER'], 'Fee reversal awaiting your approval', "The Accountant requested reversal of fee invoice {$inv['id']} (" . p($inv['amount']) . ").\nReason: {$_POST['reason']}\nApprove or reject in Accounts > Reversals.", null, null);
                $msg = 'Reversal requested. The Managing Director must approve it.'; break;
            case 'reversal_bulk_self_billed':
                if ($role !== 10) throw new RuntimeException('The Accountant requests fee reversals.');
                $rows = q($db, "SELECT message_uuid::text AS id, amount FROM settlement_outbox WHERE message_type='FEE_INVOICE' AND status <> 'ACKNOWLEDGED' AND source_institution LIKE 'VOUCHMORPH%'
                                AND NOT EXISTS (SELECT 1 FROM acct_fee_reversals f WHERE f.invoice_uuid = settlement_outbox.message_uuid::text AND f.status IN ('REQUESTED','APPROVED'))");
                $n = 0;
                foreach ($rows as $r) {
                    if (isset($r['_error'])) break;
                    $db->prepare("INSERT INTO acct_fee_reversals (invoice_uuid, amount, reason, requested_by) VALUES (?, ?, ?, ?)")->execute([$r['id'], $r['amount'], 'Invoice addressed to VouchMorph itself: cannot be collected (billing fault)', $adminId]);
                    $n++;
                }
                AdminAudit::recordOrLog($db, $adminId, 'FEE_REVERSAL_REQUESTED', 'fee_invoice', 'self-billed', ['count' => $n], 'ADMIN');
                if ($n) $desk->notify(['INCIDENT_COMMANDER'], "{$n} fee reversals awaiting approval", "The Accountant requested reversal of {$n} self-billed fee invoices. Approve in Accounts > Reversals.", null, null);
                $msg = "{$n} reversal requests raised for self-billed invoices."; break;
            case 'reversal_decide':
                if ($role !== 999) throw new RuntimeException('The Managing Director approves fee reversals.');
                $ids = array_map('intval', (array)($_POST['reversal_id'] ?? []));
                $approve = ($_POST['decision'] ?? '') === 'approve';
                $done = 0;
                foreach ($ids as $rid) {
                    $r = one($db, "SELECT * FROM acct_fee_reversals WHERE reversal_id = ? AND status = 'REQUESTED'", [$rid]);
                    if (!$r || isset($r['_error'])) continue;
                    if ((int)$r['requested_by'] === $adminId) throw new RuntimeException('Four-eyes rule: you cannot approve a reversal you requested.');
                    $db->beginTransaction();
                    $db->prepare("UPDATE acct_fee_reversals SET status = ?, decided_by = ?, decided_at = NOW() WHERE reversal_id = ?")->execute([$approve ? 'APPROVED' : 'REJECTED', $adminId, $rid]);
                    if ($approve) {
                        $db->prepare("UPDATE settlement_outbox SET status = 'CANCELLED', error_message = ? WHERE message_type = 'FEE_INVOICE' AND message_uuid::text = ? AND status <> 'ACKNOWLEDGED'")
                           ->execute(['Reversed: ' . $r['reason'], $r['invoice_uuid']]);
                    }
                    $db->commit();
                    AdminAudit::recordOrLog($db, $adminId, $approve ? 'FEE_REVERSAL_APPROVED' : 'FEE_REVERSAL_REJECTED', 'fee_invoice', $r['invoice_uuid'], ['amount' => $r['amount']], 'ADMIN', 'warning');
                    $done++;
                }
                $msg = "{$done} reversal(s) " . ($approve ? 'approved; the invoices are cancelled and will not be collected.' : 'rejected.'); break;
            case 'month_end':
                if (!in_array($role, [10, 999], true)) throw new RuntimeException('The Accountant prepares the month-end pack.');
                $m = (string)$_POST['month'];
                if (!preg_match('/^\d{4}-\d{2}$/', $m)) throw new RuntimeException('Bad month.');
                $sections = [
                    'Settlement by route (owed, paid, open)' => q($db, "SELECT debtor_bank AS payer, creditor_bank AS payee, kind, COUNT(*) AS lines, SUM(amount) AS amount, COUNT(*) FILTER (WHERE status='PAID') AS paid_lines, COALESCE(SUM(amount) FILTER (WHERE status='PAID'),0) AS paid FROM settlement_advice_lines WHERE to_char(created_at,'YYYY-MM')=? GROUP BY 1,2,3 ORDER BY 1,2,3", [$m]),
                    'Fees by payer' => q($db, "SELECT source_institution AS payer, COUNT(*) AS invoices, SUM(amount) AS invoiced, COALESCE(SUM(amount) FILTER (WHERE status='ACKNOWLEDGED'),0) AS paid, COALESCE(SUM(amount) FILTER (WHERE status='CANCELLED'),0) AS reversed, COALESCE(SUM(amount) FILTER (WHERE status NOT IN ('ACKNOWLEDGED','CANCELLED')),0) AS outstanding FROM settlement_outbox WHERE message_type='FEE_INVOICE' AND to_char(created_at,'YYYY-MM')=? GROUP BY 1 ORDER BY 1", [$m]),
                    'Swaps: completed = settled + on-us + open' => q($db, "SELECT COUNT(*) AS swaps, COUNT(*) FILTER (WHERE status='CONFIRMED' AND last_error LIKE 'On-us%') AS on_us, COUNT(*) FILTER (WHERE status='CONFIRMED' AND (last_error IS NULL OR last_error NOT LIKE 'On-us%')) AS settled, COUNT(*) FILTER (WHERE status='PENDING') AS open, COUNT(*) FILTER (WHERE status='FAILED') AS failed FROM settlement_confirmations WHERE to_char(created_at,'YYYY-MM')=?", [$m]),
                    'Open settlements by age' => q($db, "SELECT destination_institution, COUNT(*) FILTER (WHERE created_at > NOW()-INTERVAL '7 days') AS d0_7, COUNT(*) FILTER (WHERE created_at <= NOW()-INTERVAL '7 days' AND created_at > NOW()-INTERVAL '30 days') AS d8_30, COUNT(*) FILTER (WHERE created_at <= NOW()-INTERVAL '30 days') AS over_30, SUM(amount) AS amount FROM settlement_confirmations WHERE status='PENDING' GROUP BY 1 ORDER BY 1"),
                    'Fee reversals' => q($db, "SELECT invoice_uuid, amount, reason, status, requested_at, decided_at FROM acct_fee_reversals WHERE to_char(requested_at,'YYYY-MM')=? ORDER BY requested_at", [$m]),
                    'Incidents' => q($db, "SELECT incident_id, severity, playbook, title, status, opened_at, closed_at FROM ic_incidents WHERE to_char(opened_at,'YYYY-MM')=? ORDER BY opened_at", [$m]),
                    'Daily sign-offs' => q($db, "SELECT business_date, signed_at, COALESCE(exceptions,'') AS exceptions FROM acct_daily_signoffs WHERE to_char(business_date,'YYYY-MM')=? ORDER BY business_date", [$m]),
                ];
                foreach ($sections as $k => $v) if (isset($v[0]['_error'])) $sections[$k] = [['note' => 'Not available: ' . substr($v[0]['_error'], 0, 120)]];
                $rid = $desk->storeReport('MONTH_END', null, $m, "Month-end accounting pack - {$m}", ReportBuilder::monthEnd($m, $sections), 'INCIDENT_COMMANDER,HEAD_OF_PRODUCTS,COMPLIANCE_OFFICER', $adminId);
                $msg = "Month-end pack for {$m} is report {$rid} (Incident Command > Reports)."; break;
            default: throw new RuntimeException('Unknown action.');
        }
        $_SESSION['acct_flash'] = ['good', $msg];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['acct_flash'] = ['bad', $e instanceof RuntimeException ? $e->getMessage() : 'That could not be done. It has been logged.'];
        if (!$e instanceof RuntimeException) error_log('[accounts] ' . $e->getMessage());
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['acct_flash'] ?? null; unset($_SESSION['acct_flash']);
$tab = $_GET['tab'] ?? 'ledger';
$csrf = h($_SESSION['csrf_token']);
$form = fn(string $action, string $inner, string $back) => '<form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="back" value="' . h($back) . '">' . $inner . '</form>';
$y = new DateTimeImmutable('yesterday', $tz); while ((int)$y->format('N') >= 6) $y = $y->modify('-1 day');
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Accounts · VouchMorph</title>
<style>
:root{--navy:#1F3A5F;--brass:#9C7A3C;--line:#d5dde6;--bad:#b3261e;--good:#1f7a4d;--muted:#5b6775}
*{box-sizing:border-box}body{margin:0;font:14px/1.45 system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f6f8;color:#14202e}
header{background:var(--navy);color:#fff;padding:12px 22px;display:flex;justify-content:space-between;border-bottom:3px solid var(--brass)}header a{color:#fff;opacity:.85;margin-left:14px;text-decoration:none}
nav{display:flex;gap:4px;padding:10px 22px;background:#fff;border-bottom:1px solid var(--line);flex-wrap:wrap}nav a{padding:7px 12px;border:1px solid var(--line);text-decoration:none;color:var(--navy);font-weight:600;font-size:13px}nav a.on{background:var(--navy);color:#fff}
main{padding:18px 22px;max-width:1280px;margin:0 auto}.card{background:#fff;border:1px solid var(--line);padding:14px 16px;margin-bottom:14px}.card h2{margin:0 0 10px;font-size:15px;color:var(--navy)}
table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid var(--line);padding:7px 6px;text-align:left;font-size:13px;vertical-align:top}th{font-size:12px;color:var(--muted)}
.flash{padding:10px 14px;margin-bottom:12px;border-left:4px solid}.flash.good{background:#e9f6ef;border-color:var(--good)}.flash.bad{background:#fdecea;border-color:var(--bad)}
input,textarea,select{font:inherit;padding:6px 8px;border:1px solid var(--line)}textarea{width:100%;min-height:60px}button{font:inherit;font-weight:600;padding:6px 12px;border:1px solid var(--navy);background:#fff;color:var(--navy);cursor:pointer}button.primary{background:var(--navy);color:#fff}
.ok{color:var(--good);font-weight:700}.bad{color:var(--bad);font-weight:700}.muted{color:var(--muted)}
</style></head><body>
<header><div><b>Accounts</b> <span style="opacity:.7">· VouchMorph</span></div><div><?= h($adminName) ?><a href="incident_command.php">Incident Command</a><a href="admin_dashboard.php?view=invoices">Invoices &amp; Settlement</a><a href="admin_dashboard.php">Admin dashboard</a></div></header>
<nav><?php foreach (['ledger' => 'Fee ledger', 'daily' => 'Daily sign-off', 'settlement' => 'Settlement lines', 'fees' => 'Fees', 'reversals' => 'Reversals', 'monthend' => 'Month end'] as $k => $v): ?><a href="accounts.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $v ?></a><?php endforeach; ?></nav>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div><?php endif; ?>

<?php if ($tab === 'daily'):
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $y->format('Y-m-d');
    $fig = dayFigures($db, $date);
    $signed = one($db, "SELECT * FROM acct_daily_signoffs WHERE business_date = ?", [$date]);
    $recent = q($db, "SELECT business_date, signed_at, signed_by, COALESCE(exceptions,'') AS exceptions FROM acct_daily_signoffs ORDER BY business_date DESC LIMIT 10"); ?>
<div class="card"><h2>Daily reconciliation · <?= h($date) ?></h2>
<form method="get"><input type="hidden" name="tab" value="daily"><input type="date" name="date" value="<?= h($date) ?>"> <button>Show</button></form>
<table style="margin-top:10px"><?php foreach ($fig as $k => $v): ?><tr><td style="width:40%"><?= h($k) ?></td><td class="<?= str_contains($v, 'DIFFERENCE') ? 'bad' : (str_contains($v, 'MATCHES') ? 'ok' : '') ?>"><?= h($v) ?></td></tr><?php endforeach; ?></table>
<?php if ($signed && !isset($signed['_error'])): ?><p class="ok">Signed <?= ts($signed['signed_at']) ?> by admin <?= (int)$signed['signed_by'] ?>.</p>
<?php elseif ($isAccountant): echo $form('signoff', '<input type="hidden" name="date" value="' . h($date) . '"><p><label class="muted">Exceptions and follow-up (required if anything does not match)</label><textarea name="exceptions"></textarea></p><button class="primary">Sign off ' . h($date) . '</button>', 'accounts.php?tab=daily&date=' . h($date)); endif; ?>
</div>
<div class="card"><h2>Recent sign-offs</h2><table><?php foreach ($recent as $r): if (isset($r['_error'])) break; ?><tr><td><?= h($r['business_date']) ?></td><td><?= ts($r['signed_at']) ?></td><td class="muted"><?= h($r['exceptions']) ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($tab === 'ledger'):
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : (new DateTimeImmutable('first day of this month', $tz))->format('Y-m-d');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $ref = trim((string)($_GET['ref'] ?? ''));
    $ROLE = ['SWAP_LEVY' => 'Swap levy', 'SOURCE_SHARE' => 'Hold placement (13%)', 'PLATFORM_SHARE' => 'Platform (35%)', 'DESTINATION_SHARE' => 'Delivery (50%)',
             'DESTINATION_CODE_FEE' => 'Code issued (10% of 50%)', 'DESTINATION_CASHOUT_FEE' => 'Cash dispensed (90% of 50%)', 'SETTLEMENT_FEE' => 'Settlement (2%)'];
    $byInst = q($db, "SELECT institution, COUNT(DISTINCT leg_reference) AS legs,
            COALESCE(SUM(amount) FILTER (WHERE status <> 'REVERSED'),0) AS earned,
            COALESCE(SUM(amount) FILTER (WHERE status = 'RETAINED_BY_PAYER'),0) AS kept,
            COALESCE(SUM(amount) FILTER (WHERE status = 'PAID'),0) AS received,
            COALESCE(SUM(amount) FILTER (WHERE status IN ('EARNED','ADVISED')),0) AS owed,
            COALESCE(SUM(amount) FILTER (WHERE status = 'UNCOLLECTED'),0) AS uncollected,
            COALESCE(SUM(amount) FILTER (WHERE status = 'REVERSED'),0) AS reversed
        FROM fee_ledger WHERE earned_at >= ?::date AND earned_at < ?::date + 1 GROUP BY 1 ORDER BY (institution = 'VOUCHMORPH') DESC, 1", [$from, $to]);
    $byRole = q($db, "SELECT institution, fee_role, COUNT(*) AS services, COALESCE(SUM(amount) FILTER (WHERE status <> 'REVERSED'),0) AS earned
        FROM fee_ledger WHERE earned_at >= ?::date AND earned_at < ?::date + 1 GROUP BY 1,2 ORDER BY 1,2", [$from, $to]);
    $owedByPayer = q($db, "SELECT payer_institution AS payer, institution AS to_whom, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS shares
        FROM fee_ledger WHERE status IN ('EARNED','ADVISED') GROUP BY 1,2 ORDER BY 1,2"); ?>
<div class="card"><h2>Fee ledger · who performed each service and what they earned</h2>
<form method="get"><input type="hidden" name="tab" value="ledger">From <input type="date" name="from" value="<?= h($from) ?>"> to <input type="date" name="to" value="<?= h($to) ?>"> <button>Show</button></form>
<p class="muted">Split of every fee (Section 23 Rev. 2): swap levy P1 to VouchMorph first, then of the rest 13% source (hold placement), 2% settlement, 35% VouchMorph, 50% destination (cash-outs: 10% when the code is issued, 90% when cash is dispensed). Cash-out fee P10, deposit fee P6. "Kept" is a share the institution earned on a fee it already holds; "owed" is still to be paid to it on a settlement advice.</p>
<?php if (isset($byInst[0]['_error'])): ?><p class="bad">The fee ledger is not set up yet: run database/migrations/2026_09_22_fee_ledger.sql.</p><?php else: ?>
<table><tr><th>Institution</th><th>Swap legs</th><th>Earned</th><th>Kept (held already)</th><th>Received</th><th>Owed to it</th><th>Uncollected</th><th>Reversed</th><th>Statement</th></tr>
<?php foreach ($byInst as $r): ?><tr><td><b><?= h($r['institution']) ?></b></td><td><?= (int)$r['legs'] ?></td><td><b><?= p($r['earned']) ?></b></td><td><?= p($r['kept']) ?></td><td><?= p($r['received']) ?></td><td class="<?= (float)$r['owed'] > 0 ? 'bad' : '' ?>"><?= p($r['owed']) ?></td><td><?= p($r['uncollected']) ?></td><td class="muted"><?= p($r['reversed']) ?></td>
<td><a href="accounts.php?csv=fee_statement&institution=<?= urlencode($r['institution']) ?>&from=<?= h($from) ?>&to=<?= h($to) ?>">CSV</a></td></tr><?php endforeach; ?></table>
<?php endif; ?></div>
<div class="card"><h2>By service</h2><table><tr><th>Institution</th><th>Service / share</th><th>Times</th><th>Earned</th></tr>
<?php foreach ($byRole as $r): if (isset($r['_error'])) break; ?><tr><td><?= h($r['institution']) ?></td><td><?= h($ROLE[$r['fee_role']] ?? $r['fee_role']) ?></td><td><?= (int)$r['services'] ?></td><td><?= p($r['earned']) ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Still to be paid · by who holds the fee</h2><p class="muted">Paid through the settlement advices: VouchMorph's shares on the fee line, a destination's shares on its principal line.</p><table><tr><th>Holder of the fee (payer)</th><th>Owed to</th><th>Shares</th><th>Amount</th></tr>
<?php foreach ($owedByPayer as $r): if (isset($r['_error'])) break; ?><tr><td><?= h($r['payer']) ?></td><td><?= h($r['to_whom']) ?></td><td><?= (int)$r['shares'] ?></td><td><?= p($r['amount']) ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>One swap, service by service</h2><form method="get"><input type="hidden" name="tab" value="ledger"><input name="ref" value="<?= h($ref) ?>" placeholder="Swap or leg reference" style="width:360px"> <button>Look up</button></form>
<?php if ($ref !== ''): $rows = q($db, "SELECT leg_reference, product, event, fee_role, institution, payer_institution, general_fee, percent_of_pool, amount, status, advice_line_ref, earned_at, paid_at, note FROM fee_ledger WHERE swap_reference = ? OR leg_reference = ? ORDER BY leg_reference, entry_id", [$ref, $ref]); $tot = 0.0; ?>
<table style="margin-top:10px"><tr><th>Leg</th><th>Service</th><th>Performed by / entitled</th><th>Share</th><th>Amount</th><th>Status</th><th>Paid on</th></tr>
<?php foreach ($rows as $r): if (isset($r['_error'])) break; if ($r['status'] !== 'REVERSED') $tot += (float)$r['amount']; ?><tr><td><?= h($r['leg_reference']) ?><br><span class="muted"><?= h($r['product']) ?> · fee <?= p($r['general_fee']) ?></span></td><td><?= h($r['event']) ?><br><span class="muted"><?= ts($r['earned_at']) ?></span></td><td><b><?= h($r['institution']) ?></b><br><span class="muted">paid by <?= h($r['payer_institution']) ?></span></td><td><?= h($ROLE[$r['fee_role']] ?? $r['fee_role']) ?></td><td><?= p($r['amount']) ?></td><td><?= h($r['status']) ?><br><span class="muted"><?= h($r['note'] ?? '') ?></span></td><td><?= h($r['advice_line_ref'] ?? '') ?><br><span class="muted"><?= ts($r['paid_at']) ?></span></td></tr><?php endforeach; ?>
<tr><td colspan="4"><b>Total earned (excluding reversed)</b></td><td><b><?= p($tot) ?></b></td><td colspan="2"></td></tr></table>
<?php endif; ?></div>

<?php elseif ($tab === 'settlement'):
    $st = in_array($_GET['status'] ?? '', ['OPEN', 'PAID'], true) ? $_GET['status'] : 'OPEN';
    $lines = q($db, "SELECT l.*, (SELECT COUNT(*) FROM settlement_advice_items i WHERE i.line_ref = l.line_ref) AS items FROM settlement_advice_lines l WHERE l.status = ? ORDER BY l.created_at DESC LIMIT 300", [$st]);
    $adv = q($db, "SELECT status, COUNT(*) n FROM settlement_advices GROUP BY 1"); ?>
<div class="card"><h2>Settlement advice lines</h2><p class="muted">Each line is one payment a bank owes, paid through the central bank with the line reference. Advices: <?php foreach ($adv as $a) if (!isset($a['_error'])) echo h($a['status']) . ' ' . (int)$a['n'] . ' · '; ?></p>
<a href="accounts.php?tab=settlement&status=OPEN"><button<?= $st === 'OPEN' ? ' class="primary"' : '' ?>>Open</button></a> <a href="accounts.php?tab=settlement&status=PAID"><button<?= $st === 'PAID' ? ' class="primary"' : '' ?>>Paid</button></a>
<table style="margin-top:10px"><tr><th>Line</th><th>Payer → payee</th><th>Kind</th><th>Amount</th><th>Items</th><th>Created</th><th>Proof</th></tr>
<?php foreach ($lines as $l): if (isset($l['_error'])) { echo '<tr><td colspan="7" class="muted">Advice tables not found (run 2026_09_21_settlement_advices.sql).</td></tr>'; break; } ?>
<tr><td><?= h($l['line_ref']) ?></td><td><?= h($l['debtor_bank'] . ' → ' . $l['creditor_bank']) ?><br><span class="muted"><?= h($l['creditor_account']) ?></span></td><td><?= h($l['kind']) ?></td><td><?= p($l['amount']) ?></td><td><?= (int)$l['items'] ?></td><td><?= ts($l['created_at']) ?></td><td><?= $l['status'] === 'PAID' ? h($l['receipt_reference']) . '<br><span class="muted">' . ts($l['paid_at']) . '</span>' : '<span class="muted">awaiting receiving bank</span>' ?></td></tr>
<?php endforeach; ?></table></div>

<?php elseif ($tab === 'fees'):
    $byPayer = q($db, "SELECT source_institution AS payer, COUNT(*) FILTER (WHERE status NOT IN ('ACKNOWLEDGED','CANCELLED')) AS open_n,
        COALESCE(SUM(amount) FILTER (WHERE status NOT IN ('ACKNOWLEDGED','CANCELLED') AND created_at > NOW()-INTERVAL '7 days'),0) AS d0_7,
        COALESCE(SUM(amount) FILTER (WHERE status NOT IN ('ACKNOWLEDGED','CANCELLED') AND created_at <= NOW()-INTERVAL '7 days' AND created_at > NOW()-INTERVAL '30 days'),0) AS d8_30,
        COALESCE(SUM(amount) FILTER (WHERE status NOT IN ('ACKNOWLEDGED','CANCELLED') AND created_at <= NOW()-INTERVAL '30 days'),0) AS over_30,
        COALESCE(SUM(amount) FILTER (WHERE status='ACKNOWLEDGED'),0) AS paid
        FROM settlement_outbox WHERE message_type='FEE_INVOICE' GROUP BY 1 ORDER BY 1");
    $rec = one($db, "SELECT (SELECT COALESCE(SUM(amount),0) FROM settlement_outbox WHERE message_type='FEE_INVOICE' AND status='ACKNOWLEDGED') AS invoices_paid,
                            (SELECT COALESCE(SUM(amount),0) FROM settlement_advice_lines WHERE kind='FEE' AND status='PAID') AS lines_paid"); ?>
<div class="card"><h2>Fee reconciliation</h2>
<?php if (!isset($rec['_error'])): $d = (float)$rec['invoices_paid'] - (float)$rec['lines_paid']; ?>
<p>Invoices marked paid <b><?= p($rec['invoices_paid']) ?></b> · fee lines received <b><?= p($rec['lines_paid']) ?></b> · <?= abs($d) < 0.005 ? '<span class="ok">MATCHES</span>' : '<span class="bad">DIFFERENCE ' . p($d) . '</span>' ?></p>
<p class="muted">Also match the fee lines received against the fee account statement from VouchMorph's bank (<?= h(getenv('VOUCHMORPH_FEE_BANK') ?: 'ZURUBANK') ?>, account <?= h(getenv('VOUCHMORPH_FEE_ACCOUNT') ?: 'VOUCHMORPH-FEES') ?>).</p><?php endif; ?>
</div>
<div class="card"><h2>Outstanding fees by payer and age</h2><table><tr><th>Payer</th><th>Open invoices</th><th>0-7 days</th><th>8-30 days</th><th>Over 30 days</th><th>Paid to date</th></tr>
<?php foreach ($byPayer as $r): if (isset($r['_error'])) break; ?><tr><td><?= h($r['payer']) ?><?= str_starts_with((string)$r['payer'], 'VOUCHMORPH') ? ' <span class="bad">self-billed</span>' : '' ?></td><td><?= (int)$r['open_n'] ?></td><td><?= p($r['d0_7']) ?></td><td><?= p($r['d8_30']) ?></td><td class="<?= (float)$r['over_30'] > 0 ? 'bad' : '' ?>"><?= p($r['over_30']) ?></td><td><?= p($r['paid']) ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($tab === 'reversals'):
    $revs = q($db, "SELECT * FROM acct_fee_reversals ORDER BY (status = 'REQUESTED') DESC, requested_at DESC LIMIT 400");
    $selfBilled = one($db, "SELECT COUNT(*) n, COALESCE(SUM(amount),0) v FROM settlement_outbox WHERE message_type='FEE_INVOICE' AND status NOT IN ('ACKNOWLEDGED','CANCELLED') AND source_institution LIKE 'VOUCHMORPH%' AND NOT EXISTS (SELECT 1 FROM acct_fee_reversals f WHERE f.invoice_uuid = settlement_outbox.message_uuid::text AND f.status IN ('REQUESTED','APPROVED'))"); ?>
<div class="card"><h2>Request a fee reversal</h2><p class="muted">For a fee charged in error (VM-GOV-001 S6). The Accountant requests; the Managing Director approves. An approved reversal cancels the invoice so it is never collected.</p>
<?php if ($role === 10): echo $form('reversal_request', '<input name="invoice_uuid" placeholder="Fee invoice id" required style="width:340px"> <input name="reason" placeholder="Reason (required)" required minlength="10" style="width:380px"> <button class="primary">Request</button>', 'accounts.php?tab=reversals');
    if (!isset($selfBilled['_error']) && (int)$selfBilled['n'] > 0) echo '<p style="margin-top:10px">' . (int)$selfBilled['n'] . ' self-billed invoices (' . p($selfBilled['v']) . ') have no reversal yet. ' . $form('reversal_bulk_self_billed', '<button>Request reversal of all of them</button>', 'accounts.php?tab=reversals') . '</p>';
endif; ?></div>
<div class="card"><h2>Reversals</h2>
<?php if ($role === 999): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="reversal_decide"><input type="hidden" name="back" value="accounts.php?tab=reversals"><?php endif; ?>
<table><tr><?= $role === 999 ? '<th></th>' : '' ?><th>Invoice</th><th>Amount</th><th>Reason</th><th>Requested</th><th>Status</th></tr>
<?php foreach ($revs as $r): if (isset($r['_error'])) break; ?><tr><?= $role === 999 ? '<td>' . ($r['status'] === 'REQUESTED' && (int)$r['requested_by'] !== $adminId ? '<input type="checkbox" name="reversal_id[]" value="' . (int)$r['reversal_id'] . '" checked>' : '') . '</td>' : '' ?><td><?= h($r['invoice_uuid']) ?></td><td><?= p($r['amount']) ?></td><td><?= h($r['reason']) ?></td><td><?= ts($r['requested_at']) ?> by admin <?= (int)$r['requested_by'] ?></td><td><?= h($r['status']) ?><?= $r['decided_at'] ? ' ' . ts($r['decided_at']) . ' by admin ' . (int)$r['decided_by'] : '' ?></td></tr><?php endforeach; ?></table>
<?php if ($role === 999): ?><p><button class="primary" name="decision" value="approve">Approve selected</button> <button name="decision" value="reject">Reject selected</button></p></form><?php endif; ?></div>

<?php elseif ($tab === 'monthend'):
    $m = (new DateTimeImmutable('first day of last month', $tz))->format('Y-m'); ?>
<div class="card"><h2>Month-end pack (VM-GOV-001 S12)</h2><p class="muted">Settlement by route, fees by payer, completed = settled + on-us + open, open items by age, reversals, incidents and daily sign-offs. Due to the Head of Products on day 2.</p>
<?php if ($isAccountant) echo $form('month_end', '<input name="month" value="' . h($m) . '" pattern="\d{4}-\d{2}" style="width:110px"> <button class="primary">Generate pack</button>', 'accounts.php?tab=monthend'); ?>
<p style="margin-top:12px">CSV extracts for <?= h($m) ?>: <a href="accounts.php?csv=advice_lines&month=<?= h($m) ?>">advice lines</a> · <a href="accounts.php?csv=fee_invoices&month=<?= h($m) ?>">fee invoices</a> · <a href="accounts.php?csv=settlements&month=<?= h($m) ?>">settlements</a></p></div>
<?php endif; ?>
</main></body></html>
