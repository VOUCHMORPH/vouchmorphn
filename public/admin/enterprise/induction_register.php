<?php
/**
 * enterprise/induction_register.php — who is inducted, who is due, who asked for help.
 *
 * Owner, Auditor and IT Manager: full register and CSV export.
 * Supervisor: sees and resolves help requests.
 * The register is the organisation's training record.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
$user = requireEnterpriseAuth();
require_once dirname(__DIR__, 3) . '/src/Application/Enterprise/InductionCurriculum.php';
require_once dirname(__DIR__, 3) . '/src/Application/Enterprise/InductionService.php';

use Application\Enterprise\{InductionCurriculum as C, InductionService};

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$role  = (string)($user['role'] ?? '');
$orgId = (int)($user['organization_id'] ?? 0);
$me    = (int)($user['user_id'] ?? $user['id'] ?? 0);
$full  = in_array($role, ['owner', 'auditor', 'it_manager_enterprise'], true);
$help  = $full || $role === 'supervisor';

if (!$help) {
    http_response_code(403);
    exit('The induction register is available to the Owner, Auditors, IT Managers and Supervisors.');
}

$pdo = getDBConnection();
$svc = new InductionService($pdo, fn() => false);
$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($role !== 'auditor')) {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $res = trim((string)($_POST['resolution'] ?? ''));
    if (mb_strlen($res) < 5) {
        $msg = 'Write how the question was answered (at least a few words).';
    } else {
        $pdo->prepare('UPDATE induction_help_requests SET resolved_at = now(), resolved_by = :me, resolution = :r
                        WHERE id = :id AND organization_id = :o AND resolved_at IS NULL')
            ->execute([':me' => $me, ':r' => $res, ':id' => (int)$_POST['id'], ':o' => $orgId]);
        $msg = 'Marked as answered.';
    }
}

$rows = $full ? $svc->register($orgId) : [];

if ($full && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="induction_register_' . date('Ymd') . '.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Name', 'Email', 'Role', 'Status', 'Declared', 'Curriculum version', 'Wrong answers', 'Open help requests']);
    foreach ($rows as $r) {
        fputcsv($f, [$r['full_name'], $r['email'], C::ROLES[$r['role_code']] ?? $r['role_code'], $r['status'],
                     $r['declared_at'], $r['curriculum_version'], $r['wrong_answers'], $r['open_help_requests']]);
    }
    exit;
}

$hq = $pdo->prepare(
    'SELECT h.*, u.full_name FROM induction_help_requests h JOIN users u ON u.user_id = h.user_id
      WHERE h.organization_id = :o AND h.resolved_at IS NULL ORDER BY h.created_at');
$hq->execute([':o' => $orgId]);
$requests = $hq->fetchAll(PDO::FETCH_ASSOC);

$counts = ['INDUCTED' => 0, 'REFRESH DUE' => 0, 'NOT INDUCTED' => 0];
foreach ($rows as $r) { $counts[$r['status']]++; }
$csrf = generateCsrfToken();

$titleOf = function (string $roleCode, string $sectionId): string {
    foreach (C::forRole($roleCode) as $s) { if ($s['id'] === $sectionId) return $s['title']; }
    return $sectionId;
};
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Induction register · VouchMorph</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="partials/shell.css">
<style>
.reg{max-width:1120px;margin:0 auto;padding:var(--u6) var(--u4);font-family:var(--f-body);color:var(--ink);background:var(--paper)}
.reg h1{font-family:var(--f-display);font-size:40px;margin:var(--u2) 0 var(--u4)}
.reg-k{font-family:var(--f-mono);font-size:11px;letter-spacing:.16em;text-transform:uppercase}
.reg-kpis{display:grid;grid-template-columns:repeat(3,1fr);border:var(--border) solid var(--line);margin-bottom:var(--u5)}
.reg-kpis div{padding:var(--u3);border-right:var(--border) solid var(--line)}.reg-kpis div:last-child{border-right:0}
.reg-kpis b{font-family:var(--f-display);font-size:40px;display:block}
.reg table{width:100%;border-collapse:collapse;border:var(--border) solid var(--line);margin-bottom:var(--u5)}
.reg th,.reg td{padding:12px var(--u2);text-align:left;border-bottom:1px solid var(--line);vertical-align:top;font-size:14px}
.reg th{font-family:var(--f-mono);font-size:11px;letter-spacing:.12em;text-transform:uppercase;border-bottom:var(--border) solid var(--line)}
.pill{font-family:var(--f-mono);font-size:11px;padding:3px 8px;border:1px solid var(--line)}
.pill.ok{background:var(--sky-tint)}.pill.due{background:var(--paper-dim)}.pill.no{background:var(--ink);color:var(--paper)}
.reg-btn{all:unset;box-sizing:border-box;cursor:pointer;font-family:var(--f-display);font-weight:600;padding:8px 14px;border:var(--border) solid var(--line);color:var(--ink);text-decoration:none}
.reg input[type=text]{border:var(--border) solid var(--line);padding:8px;width:100%;box-sizing:border-box;background:var(--paper);color:var(--ink)}
.reg-msg{border:var(--border) solid var(--line);padding:var(--u2);margin-bottom:var(--u3);background:var(--paper-dim)}
</style></head><body><div class="reg">
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <div class="reg-k">VouchMorph · <?= h($user['organization_name'] ?? '') ?></div>
  <div style="display:flex;gap:8px"><a class="reg-btn" href="index.php">Dashboard</a><?php if ($full): ?><a class="reg-btn" href="?export=csv">Export CSV</a><?php endif; ?></div>
</div>
<h1>Induction register</h1>
<?php if ($msg): ?><div class="reg-msg"><?= h($msg) ?></div><?php endif; ?>

<?php if ($full): ?>
<div class="reg-kpis">
  <div><span class="reg-k">Inducted</span><b><?= $counts['INDUCTED'] ?></b></div>
  <div><span class="reg-k">Refresh due</span><b><?= $counts['REFRESH DUE'] ?></b></div>
  <div><span class="reg-k">Not inducted</span><b><?= $counts['NOT INDUCTED'] ?></b></div>
</div>
<table><thead><tr><th>Person</th><th>Role</th><th>Status</th><th>Declared</th><th>Wrong answers</th><th>Open help</th></tr></thead><tbody>
<?php foreach ($rows as $r): $cls = ['INDUCTED' => 'ok', 'REFRESH DUE' => 'due', 'NOT INDUCTED' => 'no'][$r['status']]; ?>
<tr><td><?= h($r['full_name']) ?><br><small><?= h($r['email']) ?></small></td>
<td><?= h(C::ROLES[$r['role_code']] ?? $r['role_code']) ?></td>
<td><span class="pill <?= $cls ?>"><?= h($r['status']) ?></span></td>
<td><?= $r['declared_at'] ? h((new DateTimeImmutable($r['declared_at']))->setTimezone(new DateTimeZone('Africa/Gaborone'))->format('d M Y H:i')) . ' CAT' : '—' ?></td>
<td><?= (int)$r['wrong_answers'] ?></td><td><?= (int)$r['open_help_requests'] ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<h2 style="font-family:var(--f-display)">Questions waiting for an answer</h2>
<?php if (!$requests): ?><p>None. Everyone who asked has been answered.</p><?php else: ?>
<table><thead><tr><th>Person</th><th>Section</th><th>Their question</th><th>Asked</th><th><?= $role === 'auditor' ? '' : 'Answer' ?></th></tr></thead><tbody>
<?php foreach ($requests as $q): ?>
<tr><td><?= h($q['full_name']) ?><br><small><?= h(C::ROLES[$q['role_code']] ?? $q['role_code']) ?></small></td>
<td><?= h($titleOf($q['role_code'], $q['section_id'])) ?></td>
<td><?= $q['note'] !== '' && $q['note'] !== null ? h($q['note']) : '<em>No details given</em>' ?></td>
<td><?= h((new DateTimeImmutable($q['created_at']))->setTimezone(new DateTimeZone('Africa/Gaborone'))->format('d M H:i')) ?></td>
<td><?php if ($role !== 'auditor'): ?><form method="post" style="display:flex;gap:8px">
  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
  <input type="text" name="resolution" placeholder="How you answered it" required minlength="5">
  <button class="reg-btn" type="submit">Answered</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php endif; ?>
</div></body></html>
