<?php
/**
 * enterprise/manual.php — the role manual.
 *
 * Available before, during and after induction. One page, for the role the
 * person actually holds. Owners, auditors and IT Managers may view any role's
 * manual so they can train and check others.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';                 // the gate exempts this page
$user = requireEnterpriseAuth();
require_once dirname(__DIR__, 3) . '/src/Application/Enterprise/InductionCurriculum.php';

use Application\Enterprise\InductionCurriculum as C;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rich(string $s): string { return preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', h($s)); }

$own = (string)($user['role'] ?? '');
$mayBrowse = in_array($own, ['owner', 'auditor', 'it_manager_enterprise'], true);
$role = ($mayBrowse && isset($_GET['role'], C::ROLES[$_GET['role']])) ? $_GET['role'] : $own;

if (!isset(C::ROLES[$role])) {
    http_response_code(403);
    exit('No manual exists for your role. Contact your IT Manager.');
}

$m = C::manual($role);
$foundation = C::foundation();
$roleSections = C::role($role);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manual · <?= h(C::ROLES[$role]) ?> · VouchMorph</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Serif:wght@400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="partials/shell.css">
<script>
(function () {
  var t = localStorage.getItem('vm_theme'); if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
  var s = localStorage.getItem('vm_skin'); if (s && s !== 'control-room') document.documentElement.setAttribute('data-skin', s);
})();
</script>
<style>
.man{max-width:960px;margin:0 auto;padding:var(--u6) var(--u4) var(--u8);background:var(--paper);color:var(--ink);font-family:var(--f-body)}
.man-top{display:flex;justify-content:space-between;align-items:center;gap:var(--u2);border-bottom:var(--border) solid var(--line);padding-bottom:var(--u2);flex-wrap:wrap}
.man-brand{font-family:var(--f-display);font-weight:700;letter-spacing:.14em;font-size:12px}
.man-k{font-family:var(--f-mono);font-size:11px;letter-spacing:.16em;text-transform:uppercase}
.man h1{font-family:var(--f-display);font-size:48px;line-height:1;margin:var(--u4) 0 var(--u2)}
.man .one{font-family:'IBM Plex Serif',Georgia,serif;font-size:22px;line-height:1.5;border-left:6px solid var(--sky);padding-left:var(--u2);margin:0 0 var(--u5)}
.man h2{font-family:var(--f-display);font-size:24px;margin:var(--u6) 0 var(--u2);display:flex;gap:var(--u2);align-items:baseline}
.man h2 .man-k{font-size:10px}
.man-grid{display:grid;grid-template-columns:1fr 1fr;border:var(--border) solid var(--line)}
.man-grid>div{padding:var(--u3)}
.man-grid>div+div{border-left:var(--border) solid var(--line)}
.man ul{margin:0;padding-left:20px;line-height:1.75}
.man-task{border:var(--border) solid var(--line);margin-bottom:var(--u2)}
.man-task summary{cursor:pointer;padding:var(--u2) var(--u3);font-family:var(--f-display);font-size:18px;font-weight:600;list-style:none;display:flex;justify-content:space-between}
.man-task summary::after{content:'+';font-family:var(--f-mono)}
.man-task[open] summary::after{content:'–'}
.man-task ol{margin:0;padding:0 var(--u3) var(--u3) calc(var(--u3) + 20px);line-height:1.8}
.man-never{border:var(--border-thick) solid var(--line);padding:var(--u3);background:var(--paper-dim)}
.man-never li{margin-bottom:4px}
.man table{width:100%;border-collapse:collapse;border:var(--border) solid var(--line)}
.man th,.man td{text-align:left;padding:12px var(--u2);border-bottom:1px solid var(--line);vertical-align:top}
.man th{font-family:var(--f-mono);font-size:11px;letter-spacing:.12em;text-transform:uppercase;border-bottom:var(--border) solid var(--line)}
.man-find{border:var(--border) solid var(--line);padding:10px 12px;font:15px var(--f-body);min-width:260px;background:var(--paper);color:var(--ink)}
.man-btn{all:unset;box-sizing:border-box;cursor:pointer;font-family:var(--f-display);font-weight:600;padding:10px 18px;border:var(--border) solid var(--line);color:var(--ink);text-decoration:none}
.man-btn.go{background:var(--sky);border-color:var(--sky);color:#000}
.man-card{border:var(--border) solid var(--line);padding:var(--u3);margin-bottom:var(--u2)}
.man-card h3{font-family:var(--f-display);margin:0 0 var(--u1);font-size:18px}
.hidden-by-search{display:none!important}
mark{background:var(--sky-tint);color:inherit}
@media (max-width:760px){.man-grid{grid-template-columns:1fr}.man-grid>div+div{border-left:0;border-top:var(--border) solid var(--line)}.man h1{font-size:34px}}
@media print{.no-print{display:none!important}.man-task{break-inside:avoid}.man-task ol{display:block}details>*{display:block}}
</style>
</head>
<body>
<div class="man">
  <div class="man-top">
    <div class="man-brand">VOUCHMORPH · ROLE MANUAL</div>
    <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input class="man-find" id="find" type="search" placeholder="Search this manual…" aria-label="Search this manual">
      <?php if ($mayBrowse): ?>
      <select class="man-find" style="min-width:0" onchange="location='manual.php?role='+this.value" aria-label="View another role's manual">
        <?php foreach (C::ROLES as $code => $label): ?><option value="<?= h($code) ?>" <?= $code === $role ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="man-btn" type="button" onclick="document.querySelectorAll('details').forEach(d=>d.open=true);window.print()">Print</button>
      <a class="man-btn go" href="induction.php">My induction</a>
    </div>
  </div>

  <div class="man-k" style="margin-top:32px"><?= h($user['organization_name'] ?? '') ?></div>
  <h1><?= h(C::ROLES[$role]) ?></h1>
  <p class="one"><?= h($m['one']) ?></p>

  <section data-s>
    <h2><span class="man-k">01</span>What you can and cannot do</h2>
    <div class="man-grid">
      <div><div class="man-k" style="margin-bottom:12px">You can</div><ul><?php foreach ($m['can'] as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul></div>
      <div><div class="man-k" style="margin-bottom:12px">You cannot</div><ul><?php foreach ($m['cannot'] as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul></div>
    </div>
  </section>

  <section data-s>
    <h2><span class="man-k">02</span>How do I…</h2>
    <?php foreach ($m['tasks'] as [$title, $steps]): ?>
      <details class="man-task" data-s><summary><?= h($title) ?></summary>
        <ol><?php foreach ($steps as $st): ?><li><?= h($st) ?></li><?php endforeach; ?></ol></details>
    <?php endforeach; ?>
  </section>

  <section data-s>
    <h2><span class="man-k">03</span>Never</h2>
    <div class="man-never"><ul><?php foreach ($m['never'] as $n): ?><li><?= h($n) ?></li><?php endforeach; ?></ul></div>
  </section>

  <section data-s>
    <h2><span class="man-k">04</span>Who to call</h2>
    <table><thead><tr><th>When</th><th>Who</th></tr></thead><tbody>
      <?php foreach ($m['escalate'] as $e): ?><tr data-s><td><?= h($e['When']) ?></td><td><?= h($e['Who']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </section>

  <section data-s>
    <h2><span class="man-k">05</span>Your role, in brief</h2>
    <?php foreach ($roleSections as $s): ?>
      <div class="man-card" data-s><h3><?= h($s['title']) ?></h3><ul><?php foreach ($s['points'] as $p): ?><li><?= rich($p) ?></li><?php endforeach; ?></ul></div>
    <?php endforeach; ?>
  </section>

  <section data-s>
    <h2><span class="man-k">06</span>The foundation, in brief</h2>
    <?php foreach ($foundation as $s): ?>
      <div class="man-card" data-s><h3><?= h($s['title']) ?></h3><ul><?php foreach ($s['points'] as $p): ?><li><?= rich($p) ?></li><?php endforeach; ?></ul></div>
    <?php endforeach; ?>
  </section>

  <p class="man-k" style="margin-top:48px;opacity:.7">Curriculum <?= h(C::VERSION_LABEL) ?> · fingerprint <?= h(substr(C::curriculumHash($role), 0, 16)) ?></p>
</div>
<script>
(() => {
  const input = document.getElementById('find');
  const blocks = [...document.querySelectorAll('[data-s]')].filter(b => !b.querySelector('[data-s]'));
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    blocks.forEach(b => {
      const hit = !q || b.textContent.toLowerCase().includes(q);
      b.classList.toggle('hidden-by-search', !hit);
      if (hit && q && b.tagName === 'DETAILS') b.open = true;
    });
    document.querySelectorAll('section[data-s]').forEach(sec => {
      const any = [...sec.querySelectorAll('[data-s]')].some(x => !x.classList.contains('hidden-by-search'));
      sec.classList.toggle('hidden-by-search', q && !any && !sec.textContent.toLowerCase().includes(q));
    });
  });
})();
</script>
</body>
</html>
