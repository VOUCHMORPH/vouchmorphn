<?php
// public/admin/incident_command.php
//
// Incident Command (VM-GOV-001). One place for every alarm, incident,
// freeze, customer notice and report:
//   Board       - alarms by severity: what to do now, who owns it, how to reach them
//   Incident    - playbook steps with owner and deadline; record what was done;
//                 generate, send and record the Bank notice, 48 h and closure reports
//   Controls    - freeze now (one person); lift only with a second admin
//   Notices     - customer notices: drafted by one admin, approved by the Incident Commander
//   Reports     - every report generated, with its sending status
//   Contacts    - who holds each role, backup, phone and email
//   Messages    - every alarm notification and whether it went out
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__, 2));
require_once PROJECT_ROOT . '/vendor/autoload.php';
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/AdminAudit.php';
require_once PROJECT_ROOT . '/src/Application/Incident/Playbooks.php';
require_once PROJECT_ROOT . '/src/Application/Incident/ReportBuilder.php';
require_once PROJECT_ROOT . '/src/Application/Incident/IncidentDesk.php';
require_once PROJECT_ROOT . '/src/Application/Incident/ServiceControls.php';

use Application\Utils\SessionManager;
use Application\Incident\IncidentDesk;
use Application\Incident\Playbooks;
use Application\Incident\ServiceControls;

SessionManager::start();
if (!SessionManager::isAdminLoggedIn()) { header('Location: admin_login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$adminId = (int)SessionManager::getAdminId();
$role = (int)SessionManager::getAdminRoleId();
$db = \Core\Database\DBConnection::getConnection();
$desk = new IncidentDesk($db);
$ROLE_NAMES = [999 => 'Managing Director / Incident Commander', 4 => 'Compliance Officer', 10 => 'Accountant', 11 => 'Settlement Officer', 20 => 'Operations', 5 => 'Auditor (view)', 3 => 'Regulator (view)'];
$canView = in_array($role, [999, 4, 10, 11, 20, 5, 3], true);
if (!$canView) { http_response_code(403); exit('Your role does not have access to Incident Command.'); }
$readOnly = in_array($role, [5, 3], true);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ts(?string $t): string {
    if (!$t) return '-';
    return (new DateTimeImmutable($t, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Gaborone'))->format('d M H:i');
}
function due(?string $t): string {
    if (!$t) return '';
    $d = (new DateTimeImmutable($t, new DateTimeZone('UTC')))->getTimestamp() - time();
    if ($d < 0) return '<span class="late">overdue ' . human(-$d) . '</span>';
    return '<span class="due">in ' . human($d) . '</span>';
}
function human(int $s): string { return $s >= 86400 ? floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h' : ($s >= 3600 ? floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm' : max(1, (int)floor($s / 60)) . 'm'); }

// ------------------------------------------------------------------ report download
if (isset($_GET['download'])) {
    $r = $desk->report((int)$_GET['download']);
    if (!$r) { http_response_code(404); exit('Report not found'); }
    \Application\Admin\AdminAudit::recordOrLog($db, $adminId, 'REPORT_DOWNLOADED', 'report', (string)$r['report_id'], ['format' => $_GET['format'] ?? 'pdf'], 'INCIDENT');
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $r['title']);
    if (($_GET['format'] ?? 'pdf') === 'html' || !class_exists('\\Dompdf\\Dompdf')) {
        header('Content-Type: text/html; charset=utf-8');
        echo $r['html']; exit;
    }
    $pdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $pdf->loadHtml($r['html']);
    $pdf->setPaper('A4');
    $pdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
    echo $pdf->output(); exit;
}

// ------------------------------------------------------------------ actions (POST/redirect/GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = $_POST['back'] ?? 'incident_command.php';
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['ic_flash'] = ['bad', 'Your form expired. Nothing was changed; please try again.'];
        header('Location: ' . $back); exit;
    }
    if ($readOnly) { $_SESSION['ic_flash'] = ['bad', 'Your role can view but not act.']; header('Location: ' . $back); exit; }
    try {
        $a = $_POST['action'] ?? '';
        $msg = 'Done.';
        switch ($a) {
            case 'ack_alert': $desk->ackAlert((int)$_POST['alert_id'], $adminId); $msg = 'Alarm acknowledged.'; break;
            case 'resolve_alert': $desk->resolveAlert((int)$_POST['alert_id'], $adminId, (string)$_POST['resolution']); $msg = 'Alarm resolved and recorded.'; break;
            case 'open_incident':
                $id = $desk->openIncident((string)$_POST['severity'], (string)$_POST['playbook'], (string)$_POST['title'], (string)($_POST['summary'] ?? ''), 'MANUAL', null, $adminId, $role);
                if (!empty($_POST['alert_id'])) $db->prepare("UPDATE ic_alerts SET incident_id = ? WHERE alert_id = ?")->execute([$id, (int)$_POST['alert_id']]);
                $back = 'incident_command.php?incident=' . urlencode($id); $msg = "Incident {$id} opened with its playbook steps."; break;
            case 'step':
                $desk->completeAction((int)$_POST['action_id'], $adminId, $role, (string)$_POST['remedy'], ($_POST['outcome'] ?? '') === 'skip'); $msg = 'Step recorded.'; break;
            case 'update_incident':
                $desk->updateIncident((string)$_POST['incident_id'], $adminId, $role, $_POST); $msg = 'Incident updated.'; break;
            case 'report':
                $rid = $desk->generateIncidentReport((string)$_POST['type'], (string)$_POST['incident_id'], $adminId, $role); $msg = "Report {$rid} generated. Review it, then send."; break;
            case 'send_report':
                $r = $desk->sendReport((int)$_POST['report_id'], $adminId, $role);
                $msg = $r['emailed'] ? 'Report emailed and recorded against the incident.' : 'Not emailed: ' . ($r['reason'] ?? 'failed for ' . implode(', ', $r['failed'] ?? [])) . '. Download it, send it, then mark it sent.'; break;
            case 'mark_sent': $desk->markReportSent((int)$_POST['report_id'], $adminId, $role, (string)$_POST['note']); $msg = 'Recorded as sent.'; break;
            case 'freeze':
                $cid = $desk->freeze((string)$_POST['scope'], (string)($_POST['target'] ?? ''), (string)$_POST['reason'], (string)($_POST['customer_message'] ?? ''), $adminId, $role, ($_POST['incident_id'] ?? '') ?: null);
                $msg = "Frozen now (control {$cid}). Lifting it will need a second admin."; break;
            case 'request_resume': $desk->requestResume((int)$_POST['control_id'], $adminId, $role, (string)$_POST['reason']); $msg = 'Lifting requested. A different admin must approve.'; break;
            case 'approve_resume': $desk->approveResume((int)$_POST['control_id'], $adminId, $role); $msg = 'Freeze lifted (four-eyes approval recorded).'; break;
            case 'draft_notice': $bid = $desk->draftBroadcast($_POST, $adminId, $role); $msg = "Notice {$bid} drafted. The Incident Commander must approve it before customers see it."; break;
            case 'decide_notice':
                $r = $desk->decideBroadcast((int)$_POST['broadcast_id'], $adminId, $role, ($_POST['decision'] ?? '') === 'approve');
                $msg = $r['published'] ? 'Notice published to customers' . (($r['sms_sent'] ?? 0) ? " and SMS sent to {$r['sms_sent']}." : '.') : 'Notice rejected.'; break;
            case 'withdraw_notice': $desk->withdrawBroadcast((int)$_POST['broadcast_id'], $adminId, $role); $msg = 'Notice withdrawn.'; break;
            case 'contact': $desk->updateContact((string)$_POST['role_code'], $_POST, $adminId, $role); $msg = 'Contact saved.'; break;
            default: throw new RuntimeException('Unknown action.');
        }
        $_SESSION['ic_flash'] = ['good', $msg];
    } catch (Throwable $e) {
        $_SESSION['ic_flash'] = ['bad', $e instanceof RuntimeException ? $e->getMessage() : 'That could not be done. It has been logged.'];
        if (!$e instanceof RuntimeException) error_log('[incident_command] ' . $e->getMessage());
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['ic_flash'] ?? null; unset($_SESSION['ic_flash']);
$tab = $_GET['tab'] ?? (isset($_GET['incident']) ? 'incident' : 'board');
$contacts = [];
$tablesReady = true;
try { $contacts = $desk->contacts(); } catch (Throwable $e) { $tablesReady = false; }
$csrf = h($_SESSION['csrf_token']);
$self = 'incident_command.php';
$who = function (?string $code) use ($contacts): string {
    if (!$code) return '';
    $c = $contacts[$code] ?? null;
    if (!$c) return h($code);
    $how = array_filter([$c['phone'] ?? '', $c['email'] ?? '']);
    return '<b>' . h($c['holder_name'] ?: $c['role_title']) . '</b> <span class="muted">(' . h($c['role_title']) . ')</span>'
        . ($how ? '<br><span class="reach">' . h(implode(' · ', $how)) . '</span>' : '<br><span class="late">no phone or email on file</span>');
};
$form = fn(string $action, string $inner, string $back = '') => '<form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="back" value="' . h($back ?: $_SERVER['REQUEST_URI']) . '">' . $inner . '</form>';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Incident Command · VouchMorph</title>
<style>
:root{--ink:#14202e;--navy:#1F3A5F;--brass:#9C7A3C;--paper:#f4f6f8;--line:#d5dde6;--bad:#b3261e;--warn:#8a5a00;--good:#1f7a4d;--muted:#5b6775}
*{box-sizing:border-box}body{margin:0;font:14px/1.45 system-ui,-apple-system,Segoe UI,sans-serif;background:var(--paper);color:var(--ink)}
header{background:var(--navy);color:#fff;padding:12px 22px;display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid var(--brass)}
header a{color:#fff;opacity:.85;margin-left:14px;text-decoration:none}header b{font-size:17px}
nav{display:flex;gap:4px;padding:10px 22px;background:#fff;border-bottom:1px solid var(--line);flex-wrap:wrap}
nav a{padding:7px 12px;border:1px solid var(--line);text-decoration:none;color:var(--navy);font-weight:600;font-size:13px}nav a.on{background:var(--navy);color:#fff;border-color:var(--navy)}
main{padding:18px 22px;max-width:1280px;margin:0 auto}
.card{background:#fff;border:1px solid var(--line);padding:14px 16px;margin-bottom:14px}.card h2{margin:0 0 10px;font-size:15px;color:var(--navy)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:14px}
.metric{background:#fff;border:1px solid var(--line);padding:12px}.metric .n{font-size:26px;font-weight:700}.metric .l{font-size:12px;color:var(--muted)}
table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid var(--line);padding:8px 6px;text-align:left;vertical-align:top;font-size:13px}th{font-size:12px;color:var(--muted);font-weight:600}
.sev{display:inline-block;padding:1px 7px;font-weight:700;font-size:12px;border:1px solid}.SEV1{color:#fff;background:var(--bad);border-color:var(--bad)}.SEV2{color:var(--bad);border-color:var(--bad)}.SEV3{color:var(--warn);border-color:var(--warn)}.SEV4{color:var(--muted);border-color:var(--line)}
.late{color:var(--bad);font-weight:700}.due{color:var(--warn)}.muted{color:var(--muted)}.reach{font-size:12px;color:var(--navy)}
.flash{padding:10px 14px;margin-bottom:12px;border-left:4px solid}.flash.good{background:#e9f6ef;border-color:var(--good)}.flash.bad{background:#fdecea;border-color:var(--bad)}
input,select,textarea{font:inherit;padding:6px 8px;border:1px solid var(--line);width:100%}textarea{min-height:60px}
button{font:inherit;font-weight:600;padding:6px 12px;border:1px solid var(--navy);background:#fff;color:var(--navy);cursor:pointer}button.primary{background:var(--navy);color:#fff}button.danger{background:var(--bad);border-color:var(--bad);color:#fff}
form.inline{display:inline}.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:8px}.row label{font-size:12px;color:var(--muted);display:block;margin-bottom:3px}
.what{background:#f7f9fb;border-left:3px solid var(--brass);padding:6px 10px;margin-top:4px;font-size:13px}.frozen{background:#fdecea;border:1px solid var(--bad);padding:10px 14px;margin-bottom:12px;font-weight:600;color:var(--bad)}
.steps td.done{background:#f1f8f4}.pill{font-size:11px;padding:1px 6px;border:1px solid var(--line)}
</style></head><body>
<header><div><b>Incident Command</b> <span class="muted" style="color:#c9d3de">· VouchMorph sandbox · VM-GOV-001</span></div>
<div><?= h($ROLE_NAMES[$role] ?? 'Admin') ?><a href="admin_dashboard.php">Admin dashboard</a><a href="accounts.php">Accounts</a></div></header>
<nav>
<?php foreach (['board' => 'Alarm board', 'incidents' => 'Incidents', 'controls' => 'Controls', 'notices' => 'Customer notices', 'reports' => 'Reports', 'contacts' => 'Contacts', 'messages' => 'Messages sent'] as $k => $v): ?>
  <a href="<?= $self ?>?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $v ?></a>
<?php endforeach; ?>
</nav>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div><?php endif; ?>
<?php if (!$tablesReady): ?><div class="flash bad">Incident Command tables are missing. Run database/migrations/2026_09_22_incident_command.sql.</div></main></body></html><?php exit; endif; ?>
<?php
$active = $desk->activeControls();
$svc = array_values(array_filter($active, fn($c) => $c['scope'] === 'SERVICE'));
if ($svc): ?><div class="frozen">THE WHOLE SERVICE IS FROZEN since <?= ts($svc[0]['frozen_at']) ?>: <?= h($svc[0]['reason']) ?>. New swaps, claims and cash-outs are refused on every channel.</div><?php endif; ?>

<?php if ($tab === 'board'):
    $alerts = $db->query("SELECT * FROM ic_alerts WHERE status <> 'RESOLVED' ORDER BY severity, created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $incs = $db->query("SELECT * FROM ic_incidents WHERE status <> 'CLOSED' ORDER BY severity, opened_at")->fetchAll(PDO::FETCH_ASSOC);
    $bySev = array_count_values(array_column($alerts, 'severity'));
    $awaiting = count(array_filter($active, fn($c) => $c['resume_requested_by'] && !$c['resumed_at']));
    $drafts = (int)$db->query("SELECT COUNT(*) FROM ic_broadcasts WHERE status = 'DRAFT'")->fetchColumn();
?>
<div class="grid">
  <div class="metric"><div class="n" style="color:var(--bad)"><?= $bySev['SEV1'] ?? 0 ?></div><div class="l">SEV1 alarms</div></div>
  <div class="metric"><div class="n" style="color:var(--bad)"><?= $bySev['SEV2'] ?? 0 ?></div><div class="l">SEV2 alarms</div></div>
  <div class="metric"><div class="n" style="color:var(--warn)"><?= ($bySev['SEV3'] ?? 0) + ($bySev['SEV4'] ?? 0) ?></div><div class="l">SEV3/4 alarms</div></div>
  <div class="metric"><div class="n"><?= count($incs) ?></div><div class="l">Open incidents</div></div>
  <div class="metric"><div class="n"><?= count($active) ?></div><div class="l">Freezes in force<?= $awaiting ? " · {$awaiting} awaiting second admin" : '' ?></div></div>
  <div class="metric"><div class="n"><?= $drafts ?></div><div class="l">Notices awaiting approval</div></div>
</div>

<?php if ($incs): ?><div class="card"><h2>Open incidents and their deadlines</h2><table><tr><th>Incident</th><th>What</th><th>Bank notice (2 h)</th><th>48 h report</th><th>Open steps</th></tr>
<?php foreach ($incs as $i): $open = $db->prepare("SELECT COUNT(*) FROM ic_incident_actions WHERE incident_id = ? AND status = 'PENDING'"); $open->execute([$i['incident_id']]); ?>
<tr><td><a href="<?= $self ?>?incident=<?= urlencode($i['incident_id']) ?>"><?= h($i['incident_id']) ?></a><br><span class="sev <?= h($i['severity']) ?>"><?= h($i['severity']) ?></span> <span class="pill"><?= h($i['status']) ?></span></td>
<td><?= h($i['title']) ?><br><span class="muted"><?= h($i['playbook'] . ' · ' . Playbooks::get($i['playbook'])['title']) ?> · opened <?= ts($i['opened_at']) ?></span></td>
<td><?= $i['bank_notify_due'] ? ($i['bank_notified_at'] ? 'Sent ' . ts($i['bank_notified_at']) : due($i['bank_notify_due'])) : '<span class="muted">not required</span>' ?></td>
<td><?= $i['report_48h_due'] ? ($i['report_48h_sent_at'] ? 'Sent ' . ts($i['report_48h_sent_at']) : due($i['report_48h_due'])) : '<span class="muted">not required</span>' ?></td>
<td><?= (int)$open->fetchColumn() ?></td></tr>
<?php endforeach; ?></table></div><?php endif; ?>

<div class="card"><h2>Alarms</h2>
<?php if (!$alerts): ?><p class="muted">No open alarms.</p><?php endif; ?>
<table>
<?php foreach ($alerts as $a): $rule = Playbooks::rule($a['rule_code']); $d = json_decode((string)$a['detail'], true) ?: []; ?>
<tr><td style="width:88px"><span class="sev <?= h($a['severity']) ?>"><?= h($a['severity']) ?></span><br><span class="muted"><?= ts($a['created_at']) ?></span></td>
<td><b><?= h($a['title']) ?></b> <span class="pill"><?= h($a['rule_code']) ?></span> <?= $a['status'] === 'ACKED' ? '<span class="pill">acknowledged</span>' : '' ?>
<div class="what"><b>What to do now:</b> <?= h($rule['now']) ?>
<?php if ($rule['notify']): ?><br><b>Who:</b> <?= implode('; ', array_map(fn($r) => $who($r), $rule['notify'])) ?><?php endif; ?>
<?php if ($a['incident_id']): ?><br><b>Incident:</b> <a href="<?= $self ?>?incident=<?= urlencode($a['incident_id']) ?>"><?= h($a['incident_id']) ?></a> (playbook steps are there)<?php elseif ($a['playbook']): ?><br><b>Playbook:</b> <?= h($a['playbook'] . ' · ' . Playbooks::get($a['playbook'])['title']) ?><?php endif; ?>
</div></td>
<td style="width:280px"><?php if (!$readOnly): ?>
  <?php if ($a['status'] === 'OPEN') echo $form('ack_alert', '<input type="hidden" name="alert_id" value="' . (int)$a['alert_id'] . '"><button>Acknowledge</button>'); ?>
  <?php if (!$a['incident_id'] && $a['playbook']) echo $form('open_incident', '<input type="hidden" name="alert_id" value="' . (int)$a['alert_id'] . '"><input type="hidden" name="severity" value="' . h($a['severity']) . '"><input type="hidden" name="playbook" value="' . h($a['playbook']) . '"><input type="hidden" name="title" value="' . h($a['title']) . '"><input type="hidden" name="summary" value="' . h($rule['now']) . '"><button>Open incident</button>'); ?>
  <?= $form('resolve_alert', '<input type="hidden" name="alert_id" value="' . (int)$a['alert_id'] . '"><input name="resolution" placeholder="What was done (required)" required minlength="5" style="margin-top:6px"><button style="margin-top:4px">Resolve</button>') ?>
<?php endif; ?></td></tr>
<?php endforeach; ?></table></div>

<?php elseif ($tab === 'incidents'):
    $incs = $db->query("SELECT * FROM ic_incidents ORDER BY (status = 'CLOSED'), opened_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); ?>
<?php if (!$readOnly): ?><div class="card"><h2>Open an incident</h2><?= $form('open_incident', '<div class="row"><div><label>Severity</label><select name="severity"><option>SEV1</option><option selected>SEV2</option><option>SEV3</option><option>SEV4</option></select></div><div><label>Scenario (VM-GOV-001)</label><select name="playbook">' . implode('', array_map(fn($k, $p) => '<option value="' . h($k) . '">' . h($k . ' · ' . $p['title']) . '</option>', array_keys(Playbooks::all()), Playbooks::all())) . '</select></div></div><div class="row"><div><label>What happened</label><input name="title" required minlength="5"></div></div><div class="row"><div><label>Details</label><textarea name="summary"></textarea></div></div><button class="primary">Open incident with its playbook steps</button>', $self . '?tab=incidents') ?></div><?php endif; ?>
<div class="card"><h2>Incident register</h2><table><tr><th>Incident</th><th>What</th><th>Opened</th><th>Status</th></tr>
<?php foreach ($incs as $i): ?><tr><td><a href="<?= $self ?>?incident=<?= urlencode($i['incident_id']) ?>"><?= h($i['incident_id']) ?></a> <span class="sev <?= h($i['severity']) ?>"><?= h($i['severity']) ?></span></td><td><?= h($i['title']) ?><br><span class="muted"><?= h($i['playbook']) ?> · <?= h($i['trigger_source']) ?></span></td><td><?= ts($i['opened_at']) ?></td><td><?= h($i['status']) ?></td></tr><?php endforeach; ?>
</table></div>

<?php elseif ($tab === 'incident' && ($inc = $desk->incident((string)($_GET['incident'] ?? '')))):
    $acts = $desk->actions($inc['incident_id']); $log = $desk->incidentLog($inc['incident_id']); $ctrls = $desk->controlsFor($inc['incident_id']);
    $reps = $db->prepare("SELECT * FROM ic_reports WHERE incident_id = ? ORDER BY generated_at DESC"); $reps->execute([$inc['incident_id']]); $reps = $reps->fetchAll(PDO::FETCH_ASSOC);
    $pb = Playbooks::get($inc['playbook']); $back = $self . '?incident=' . urlencode($inc['incident_id']); ?>
<div class="card"><h2><?= h($inc['incident_id']) ?> · <span class="sev <?= h($inc['severity']) ?>"><?= h($inc['severity']) ?></span> · <?= h($inc['title']) ?></h2>
<p class="muted">Scenario <?= h($inc['playbook'] . ' · ' . $pb['title']) ?> · opened <?= ts($inc['opened_at']) ?> (<?= h($inc['trigger_source']) ?>) · status <b><?= h($inc['status']) ?></b></p>
<div class="grid">
  <div class="metric"><div class="l">Bank notice (2 h)</div><div><?= $inc['bank_notify_due'] ? ($inc['bank_notified_at'] ? 'Sent ' . ts($inc['bank_notified_at']) : due($inc['bank_notify_due'])) : 'Not required' ?></div></div>
  <div class="metric"><div class="l">Written report (48 h)</div><div><?= $inc['report_48h_due'] ? ($inc['report_48h_sent_at'] ? 'Sent ' . ts($inc['report_48h_sent_at']) : due($inc['report_48h_due'])) : 'Not required' ?></div></div>
  <div class="metric"><div class="l">Customers affected</div><div><?= $inc['customers_affected'] ?? 'Being established' ?></div></div>
  <div class="metric"><div class="l">Money involved</div><div><?= $inc['money_at_risk'] !== null ? 'P' . number_format((float)$inc['money_at_risk'], 2) : 'Being established' ?></div></div>
</div>
<?php if (!$readOnly) echo $form('update_incident', '<input type="hidden" name="incident_id" value="' . h($inc['incident_id']) . '"><div class="row"><div><label>Status</label><select name="status">' . implode('', array_map(fn($s) => '<option' . ($s === $inc['status'] ? ' selected' : '') . '>' . $s . '</option>', ['OPEN', 'CONTAINED', 'RESOLVED', 'CLOSED'])) . '</select></div><div><label>Severity</label><select name="severity">' . implode('', array_map(fn($s) => '<option' . ($s === $inc['severity'] ? ' selected' : '') . '>' . $s . '</option>', ['SEV1', 'SEV2', 'SEV3', 'SEV4'])) . '</select></div><div><label>Customers affected</label><input name="customers_affected" value="' . h($inc['customers_affected']) . '"></div><div><label>Money involved (BWP)</label><input name="money_at_risk" value="' . h($inc['money_at_risk']) . '"></div></div><div class="row"><div><label>Summary</label><textarea name="summary">' . h($inc['summary']) . '</textarea></div><div><label>Root cause (needed for the 48 h report)</label><textarea name="root_cause">' . h($inc['root_cause']) . '</textarea></div></div><button class="primary">Save</button>', $back); ?>
</div>

<div class="card"><h2>Playbook steps · who does what, by when</h2><table class="steps"><tr><th>#</th><th>Owner</th><th>Action</th><th>Hands to</th><th>Deadline</th><th>What was done</th></tr>
<?php foreach ($acts as $s): ?>
<tr><td class="<?= $s['status'] !== 'PENDING' ? 'done' : '' ?>"><?= (int)$s['step_no'] ?></td><td style="width:210px"><?= $who($s['owner_role']) ?></td><td><?= h($s['action']) ?></td><td><?= h($contacts[$s['hands_to']]['holder_name'] ?? ($s['hands_to'] ?? '-')) ?></td>
<td><?= ts($s['due_at']) ?><br><?= $s['status'] === 'PENDING' ? due($s['due_at']) : '' ?></td>
<td style="width:300px"><?php if ($s['status'] !== 'PENDING'): ?><b><?= h($s['status']) ?></b> <?= ts($s['done_at']) ?><br><?= nl2br(h($s['remedy'])) ?>
<?php elseif (!$readOnly): echo $form('step', '<input type="hidden" name="action_id" value="' . (int)$s['action_id'] . '"><textarea name="remedy" placeholder="What was done (required)" required minlength="5"></textarea><button class="primary">Done</button> <button name="outcome" value="skip">Not applicable</button>', $back); endif; ?></td></tr>
<?php endforeach; ?></table></div>

<div class="card"><h2>Reports</h2>
<?php if (!$readOnly) foreach (['BANK_NOTICE' => 'Bank notice (2 h)', 'REPORT_48H' => 'Written report (48 h)', 'CLOSURE' => 'Closure report'] as $t => $label) echo $form('report', '<input type="hidden" name="incident_id" value="' . h($inc['incident_id']) . '"><input type="hidden" name="type" value="' . $t . '"><button>Generate ' . $label . '</button> ', $back); ?>
<table style="margin-top:10px"><tr><th>Report</th><th>Generated</th><th>Sending</th><th></th></tr>
<?php foreach ($reps as $r): ?><tr><td><?= h($r['title']) ?></td><td><?= ts($r['generated_at']) ?></td><td><?= h($r['sent_status']) ?><?= $r['sent_at'] ? ' ' . ts($r['sent_at']) : '' ?><br><span class="muted"><?= h($r['send_note']) ?></span></td>
<td><a href="<?= $self ?>?download=<?= (int)$r['report_id'] ?>">PDF</a> · <a href="<?= $self ?>?download=<?= (int)$r['report_id'] ?>&format=html" target="_blank">View</a>
<?php if (!$readOnly && !in_array($r['sent_status'], ['EMAILED', 'MARKED_SENT'], true)): ?><br><?= $form('send_report', '<input type="hidden" name="report_id" value="' . (int)$r['report_id'] . '"><button>Email to recipients</button>', $back) ?><?= $form('mark_sent', '<input type="hidden" name="report_id" value="' . (int)$r['report_id'] . '"><input name="note" placeholder="Sent how, to whom" required minlength="5"><button>Mark sent</button>', $back) ?><?php endif; ?></td></tr><?php endforeach; ?></table></div>

<?php if ($ctrls): ?><div class="card"><h2>Freezes linked to this incident</h2><table><?php foreach ($ctrls as $c): ?><tr><td><?= h($c['scope'] . ' ' . $c['target']) ?></td><td><?= h($c['reason']) ?></td><td><?= $c['resumed_at'] ? 'Lifted ' . ts($c['resumed_at']) : '<b>In force</b>' ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<div class="card"><h2>Timeline</h2><table><?php foreach ($log as $l): ?><tr><td style="width:120px"><?= ts($l['at']) ?></td><td><?= h($l['entry']) ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($tab === 'controls'):
    $recent = $db->query("SELECT * FROM ic_controls WHERE resumed_at IS NOT NULL ORDER BY resumed_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $openIncs = $db->query("SELECT incident_id, title FROM ic_incidents WHERE status <> 'CLOSED' ORDER BY opened_at DESC")->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="card"><h2>Freeze now</h2>
<p class="muted">Takes effect immediately on every channel (app, API, agent, enterprise batches). Only one person is needed to stop; lifting always needs a second admin. Who may freeze: whole service, institution or flow: Managing Director. Agent or client: Managing Director or Compliance Officer.</p>
<?php if (!$readOnly) echo $form('freeze', '<div class="row"><div><label>What</label><select name="scope"><option value="SERVICE">Whole swap service</option><option value="INSTITUTION">An institution (e.g. SACCUSSALIS)</option><option value="FLOW">A swap flow (DEPOSIT, CASHOUT, IDENTITY_HOLD, IDENTITY_CLAIM, CARD_LOAD, PAYMENT_REQUEST, ENTERPRISE_BATCH)</option><option value="AGENT">An agent (user id)</option><option value="CLIENT">A client (user id)</option></select></div><div><label>Which (not needed for the whole service)</label><input name="target" placeholder="e.g. ZURUBANK, CASHOUT or 42"></div><div><label>Incident</label><select name="incident_id"><option value="">(none yet)</option>' . implode('', array_map(fn($i) => '<option value="' . h($i['incident_id']) . '">' . h($i['incident_id'] . ' · ' . $i['title']) . '</option>', $openIncs)) . '</select></div></div><div class="row"><div><label>Reason (audit trail and Bank notice)</label><textarea name="reason" required minlength="10"></textarea></div><div><label>What customers see (optional; a safe default is used)</label><textarea name="customer_message"></textarea></div></div><button class="danger">Freeze now</button>', $self . '?tab=controls'); ?></div>
<div class="card"><h2>Freezes in force</h2><?php if (!$active): ?><p class="muted">Nothing is frozen.</p><?php endif; ?><table>
<?php foreach ($active as $c): ?><tr><td><b><?= h($c['scope']) ?></b> <?= h($c['target']) ?><br><span class="muted">since <?= ts($c['frozen_at']) ?> by admin <?= (int)$c['frozen_by'] ?><?= $c['incident_id'] ? ' · ' . h($c['incident_id']) : '' ?></span></td><td><?= h($c['reason']) ?></td>
<td style="width:320px"><?php if ($readOnly): ?>
<?php elseif (!$c['resume_requested_by']): echo $form('request_resume', '<input type="hidden" name="control_id" value="' . (int)$c['control_id'] . '"><textarea name="reason" placeholder="Why it is safe to lift (required)" required minlength="10"></textarea><button>Request lifting</button>', $self . '?tab=controls');
else: ?>Lifting requested by admin <?= (int)$c['resume_requested_by'] ?> at <?= ts($c['resume_requested_at']) ?>: <i><?= h($c['resume_reason']) ?></i><br>
<?php if ((int)$c['resume_requested_by'] === $adminId): ?><span class="muted">Waiting for a different admin to approve.</span><?php elseif (IncidentDesk::allowed($role, IncidentDesk::RESUME_APPROVE_ROLES)): echo $form('approve_resume', '<input type="hidden" name="control_id" value="' . (int)$c['control_id'] . '"><button class="primary">Approve lifting (second admin)</button>', $self . '?tab=controls'); else: ?><span class="muted">Needs the Managing Director or Compliance Officer.</span><?php endif; ?>
<?php endif; ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Recently lifted</h2><table><?php foreach ($recent as $c): ?><tr><td><?= h($c['scope'] . ' ' . $c['target']) ?></td><td>frozen <?= ts($c['frozen_at']) ?> by admin <?= (int)$c['frozen_by'] ?></td><td>lifted <?= ts($c['resumed_at']) ?>: requested by admin <?= (int)$c['resume_requested_by'] ?>, approved by admin <?= (int)$c['resumed_by'] ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($tab === 'notices'):
    $bs = $db->query("SELECT * FROM ic_broadcasts ORDER BY drafted_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $openIncs = $db->query("SELECT incident_id, title FROM ic_incidents WHERE status <> 'CLOSED' ORDER BY opened_at DESC")->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="card"><h2>Draft a customer notice</h2><p class="muted">Shown as a banner in the customer and agent dashboards once the Incident Commander approves it (never the person who drafted it). SMS goes to customers with a phone number on networks the SMS gateway covers.</p>
<?php if (!$readOnly) echo $form('draft_notice', '<div class="row"><div><label>Audience</label><select name="audience"><option value="ALL">All customers</option><option value="AGENTS">Agents only</option></select></div><div><label>Level</label><select name="level"><option>INFO</option><option>WARNING</option><option>CRITICAL</option></select></div><div><label>Show for (hours)</label><input name="hours" type="number" value="24" min="1" max="168"></div><div><label>Incident</label><select name="incident_id"><option value="">(none)</option>' . implode('', array_map(fn($i) => '<option value="' . h($i['incident_id']) . '">' . h($i['incident_id']) . '</option>', $openIncs)) . '</select></div></div><div class="row"><div><label>Title</label><input name="title" required minlength="5" placeholder="Service paused"></div></div><div class="row"><div><label>Message</label><textarea name="body" required minlength="10" placeholder="Your money is safe with your bank. We expect to resume by 14:00."></textarea></div></div><label><input type="checkbox" name="send_sms" value="1" style="width:auto"> Also send by SMS</label><br><button class="primary" style="margin-top:8px">Draft for approval</button>', $self . '?tab=notices'); ?></div>
<div class="card"><h2>Notices</h2><table><tr><th>Notice</th><th>Status</th><th></th></tr>
<?php foreach ($bs as $b): ?><tr><td><b><?= h($b['title']) ?></b> <span class="pill"><?= h($b['level']) ?></span> <span class="pill"><?= h($b['audience']) ?></span><br><?= h($b['body']) ?><br><span class="muted">drafted by admin <?= (int)$b['drafted_by'] ?> <?= ts($b['drafted_at']) ?><?= $b['send_sms'] ? ' · SMS requested' : '' ?></span></td>
<td><?= h($b['status']) ?><?= $b['approved_at'] ? '<br><span class="muted">by admin ' . (int)$b['approved_by'] . ' ' . ts($b['approved_at']) . '</span>' : '' ?><?= $b['status'] === 'PUBLISHED' ? '<br><span class="muted">until ' . ts($b['expires_at']) . ' · SMS sent ' . (int)$b['sms_sent'] . '</span>' : '' ?></td>
<td><?php if (!$readOnly && $b['status'] === 'DRAFT'): ?><?php if ((int)$b['drafted_by'] === $adminId): ?><span class="muted">Waiting for the Incident Commander.</span><?php elseif (IncidentDesk::allowed($role, IncidentDesk::BROADCAST_APPROVE_ROLES)): ?><?= $form('decide_notice', '<input type="hidden" name="broadcast_id" value="' . (int)$b['broadcast_id'] . '"><button class="primary" name="decision" value="approve">Approve and publish</button> <button name="decision" value="reject">Reject</button>', $self . '?tab=notices') ?><?php else: ?><span class="muted">Needs the Incident Commander.</span><?php endif; ?>
<?php elseif (!$readOnly && $b['status'] === 'PUBLISHED'): echo $form('withdraw_notice', '<input type="hidden" name="broadcast_id" value="' . (int)$b['broadcast_id'] . '"><button>Withdraw</button>', $self . '?tab=notices'); endif; ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($tab === 'reports'):
    $reps = $db->query("SELECT report_id, report_type, incident_id, period, title, generated_at, sent_status, sent_at, send_note FROM ic_reports ORDER BY generated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="card"><h2>Every report</h2><p class="muted">Incident reports are generated from the incident page; the accountant's daily sign-off and month-end pack from Accounts.</p><table><tr><th>Report</th><th>Generated</th><th>Sending</th><th></th></tr>
<?php foreach ($reps as $r): ?><tr><td><?= h($r['title']) ?> <span class="pill"><?= h($r['report_type']) ?></span></td><td><?= ts($r['generated_at']) ?></td><td><?= h($r['sent_status']) ?> <?= $r['sent_at'] ? ts($r['sent_at']) : '' ?><br><span class="muted"><?= h($r['send_note']) ?></span></td><td><a href="<?= $self ?>?download=<?= (int)$r['report_id'] ?>">PDF</a> · <a href="<?= $self ?>?download=<?= (int)$r['report_id'] ?>&format=html" target="_blank">View</a><?= $r['incident_id'] ? ' · <a href="' . $self . '?incident=' . urlencode($r['incident_id']) . '">incident</a>' : '' ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($tab === 'contacts'): ?>
<div class="card"><h2>Who to call</h2><p class="muted">From VM-GOV-001 Part 2. Alarms and reports are sent to these addresses; an alarm for a role with no email stays visible here but cannot be emailed.</p><table><tr><th>Role</th><th>Holder</th><th>Backup</th><th>Decides</th><th>Phone / email</th></tr>
<?php foreach ($contacts as $c): ?><tr><td><b><?= h($c['role_title']) ?></b><?= $c['external'] ? ' <span class="pill">external</span>' : '' ?></td><td colspan="<?= in_array($role, [999, 4], true) ? 1 : 1 ?>"><?= h($c['holder_name']) ?></td><td><?= h($contacts[$c['backup_role']]['role_title'] ?? '-') ?></td><td class="muted"><?= h($c['decides']) ?></td>
<td style="width:330px"><?php if (in_array($role, [999, 4], true)): echo $form('contact', '<input type="hidden" name="role_code" value="' . h($c['role_code']) . '"><input name="holder_name" value="' . h($c['holder_name']) . '" placeholder="Name"><input name="phone" value="' . h($c['phone']) . '" placeholder="Phone"><input name="email" value="' . h($c['email']) . '" placeholder="Email"><button>Save</button>', $self . '?tab=contacts'); else: echo h(implode(' · ', array_filter([$c['phone'], $c['email']])) ?: 'not on file'); endif; ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($tab === 'messages'):
    $ns = $db->query("SELECT * FROM ic_notifications ORDER BY created_at DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="card"><h2>Alarm messages</h2><p class="muted">Every notification, and whether it actually went out. PENDING means email is not configured yet; NO_ADDRESS means the role has no email on the Contacts tab.</p><table><tr><th>When</th><th>To</th><th>Subject</th><th>Status</th></tr>
<?php foreach ($ns as $n): ?><tr><td><?= ts($n['created_at']) ?></td><td><?= h($contacts[$n['role_code']]['holder_name'] ?? $n['role_code']) ?><br><span class="muted"><?= h($n['address'] ?? '') ?></span></td><td><?= h($n['subject']) ?></td><td><?= h($n['status']) ?><br><span class="muted"><?= h($n['error'] ?? '') ?></span></td></tr><?php endforeach; ?></table></div>
<?php else: ?><div class="card"><p>Not found.</p></div><?php endif; ?>
</main></body></html>
