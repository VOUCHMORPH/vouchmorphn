<?php
/**
 * enterprise/induction.php — role induction.
 *
 * Read each section. Prove it with the check. Declare. Until then the rest
 * of the platform stays closed; the manual never does.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';                 // the gate exempts this page
$user = requireEnterpriseAuth();

require_once dirname(__DIR__, 3) . '/src/Application/Enterprise/InductionCurriculum.php';
require_once dirname(__DIR__, 3) . '/src/Application/Enterprise/InductionService.php';
require_once dirname(__DIR__, 3) . '/src/Core/Database/CredentialsDBConnection.php';
require_once dirname(__DIR__, 3) . '/src/Infrastructure/Credentials/CredentialsRepository.php';

use Application\Enterprise\{InductionCurriculum, InductionService};
use Infrastructure\Credentials\CredentialsRepository;

$pdo    = getDBConnection();
$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
$orgId  = (int)($user['organization_id'] ?? 0);
$role   = (string)($user['role'] ?? '');
$name   = (string)($user['full_name'] ?? $user['username'] ?? '');
$org    = (string)($user['organization_name'] ?? 'Your organisation');

$svc = new InductionService($pdo, function (int $uid, string $pw): bool {
    $cred = CredentialsRepository::fromEnvironment()->findUserCredentialByUserId($uid);
    return $cred && password_verify($pw, $cred['password_hash']);
});

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------------------
// JSON actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        requireCsrfToken($in['csrf_token'] ?? null);
        $out = match ($in['action'] ?? '') {
            'status'  => $svc->status($userId, $orgId, $role),
            'open'    => $svc->open($userId, $orgId, $role, (string)$in['section']),
            'answer'  => $svc->answer($userId, $orgId, $role, (string)$in['section'], (int)$in['choice']),
            'help'    => ['id' => $svc->requestHelp($userId, $orgId, $role, (string)$in['section'], (string)($in['note'] ?? ''))],
            'declare' => $svc->declare($userId, $orgId, $role, $name, (string)$in['typed_name'],
                                       (string)$in['password'], $_SERVER['REMOTE_ADDR'] ?? null),
            default   => throw new RuntimeException('Unknown action'),
        };
        if (($in['action'] ?? '') === 'declare') {
            unset($_SESSION['induction_ok']);   // the gate re-checks and lets them through
        }
        echo json_encode(['ok' => true, 'data' => $out]);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (!isset(InductionCurriculum::ROLES[$role])) {
    http_response_code(403);
    exit('Your role has no induction configured. Contact your IT Manager.');
}

$status = $svc->status($userId, $orgId, $role);
$csrf   = generateCsrfToken();
$first  = explode(' ', trim($name))[0] ?: 'there';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Induction · <?= h($status['role_label']) ?> · VouchMorph</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Serif:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="partials/shell.css">
<script>
(function () {
  var t = localStorage.getItem('vm_theme'); if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
  var s = localStorage.getItem('vm_skin'); if (s && s !== 'control-room') document.documentElement.setAttribute('data-skin', s);
})();
</script>
<style>
/* Induction — built on the Center-Stage tokens: two inks, one working
   colour, square edges, drawn borders, an 8px grid. The serif appears in
   exactly one place, the reading column, because reading is the job. */
.ind{min-height:100vh;display:grid;grid-template-columns:320px 1fr;background:var(--paper);color:var(--ink);font-family:var(--f-body)}
.ind-rail{border-right:var(--border) solid var(--line);padding:var(--u4) var(--u3);position:sticky;top:0;height:100vh;overflow:auto;display:flex;flex-direction:column;gap:var(--u3)}
.ind-brand{font-family:var(--f-display);font-weight:700;letter-spacing:.14em;font-size:12px}
.ind-who{border:var(--border) solid var(--line);padding:var(--u2)}
.ind-who .k{font-family:var(--f-mono);font-size:10px;letter-spacing:.12em;text-transform:uppercase;opacity:.7}
.ind-who .v{font-family:var(--f-display);font-size:20px;font-weight:600;margin-top:4px}
.ind-meter{height:8px;border:var(--border) solid var(--line);position:relative}
.ind-meter i{position:absolute;inset:0 auto 0 0;background:var(--ink);transition:width .5s}
.ind-meter-l{display:flex;justify-content:space-between;font-family:var(--f-mono);font-size:11px;margin-top:6px}
.ind-group{font-family:var(--f-mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;opacity:.7;margin:var(--u2) 0 var(--u1)}
.ind-list{list-style:none;margin:0;padding:0}
.ind-list li button{all:unset;box-sizing:border-box;width:100%;display:grid;grid-template-columns:32px 1fr auto;align-items:center;gap:var(--u1);padding:10px 8px;border-bottom:1px solid var(--line);cursor:pointer;font-size:14px}
.ind-list li button[disabled]{cursor:not-allowed;opacity:.38}
.ind-list li button.cur{background:var(--sky-tint);box-shadow:inset 4px 0 0 var(--sky)}
.ind-list .n{font-family:var(--f-mono);font-size:11px}
.ind-list .st{font-family:var(--f-mono);font-size:11px}
.ind-manual-link{margin-top:auto;border:var(--border) solid var(--line);padding:var(--u2);text-decoration:none;color:var(--ink);display:block}
.ind-manual-link b{font-family:var(--f-display);display:block}
.ind-main{display:flex;flex-direction:column;min-height:100vh}
.ind-stage:focus{outline:none}   /* focus is moved here for screen readers; no visible ring needed */
.ind-stage{flex:1;padding:var(--u7) var(--u6) calc(var(--u8) + var(--u4));max-width:820px;width:100%;margin:0 auto}
.ind-kicker{font-family:var(--f-mono);font-size:11px;letter-spacing:.16em;text-transform:uppercase}
.ind-h1{font-family:var(--f-display);font-size:44px;line-height:1.05;font-weight:700;letter-spacing:-.01em;margin:var(--u2) 0 var(--u3)}
.ind-lede{font-family:'IBM Plex Serif',Georgia,serif;font-size:20px;line-height:1.55}
.ind-read{font-family:'IBM Plex Serif',Georgia,serif;font-size:19px;line-height:1.7}
.ind-read p{margin:0 0 var(--u3)}
.ind-read b{font-family:var(--f-body);font-weight:600}
.ind-keep{border:var(--border) solid var(--line);padding:var(--u3);margin:var(--u4) 0}
.ind-keep h3{font-family:var(--f-mono);font-size:11px;letter-spacing:.16em;text-transform:uppercase;margin:0 0 var(--u2)}
.ind-keep ul{margin:0;padding-left:20px;line-height:1.7}
.ind-check{border:var(--border-thick) solid var(--line);padding:var(--u4);margin-top:var(--u5)}
.ind-check .q{font-family:var(--f-display);font-size:22px;font-weight:600;line-height:1.3;margin:var(--u1) 0 var(--u3)}
.ind-opt{display:grid;grid-template-columns:32px 1fr;gap:var(--u2);align-items:start;border:var(--border) solid var(--line);padding:var(--u2);margin-bottom:var(--u1);cursor:pointer;font-size:16px;line-height:1.5}
.ind-opt input{position:absolute;opacity:0;pointer-events:none}
.ind-opt .l{font-family:var(--f-mono);width:28px;height:28px;border:var(--border) solid var(--line);display:grid;place-items:center;font-size:12px}
.ind-opt:has(input:checked){background:var(--sky-tint);box-shadow:inset 4px 0 0 var(--sky)}
.ind-opt:has(input:checked) .l{background:var(--ink);color:var(--paper)}
.ind-verdict{margin-top:var(--u3);padding:var(--u3);border:var(--border) solid var(--line)}
.ind-verdict .t{font-family:var(--f-display);font-size:20px;font-weight:700;margin-bottom:var(--u1)}
.ind-verdict.no{background:var(--paper-dim)}
.ind-bar{position:fixed;left:320px;right:0;bottom:0;border-top:var(--border) solid var(--line);background:var(--paper);display:flex;align-items:center;gap:var(--u2);padding:var(--u2) var(--u6);z-index:5}
.ind-bar[hidden]{display:none!important}   /* shell.css sets display on bars; the hidden attribute must win */
.ind-bar .clock{font-family:var(--f-mono);font-size:12px;flex:1}
.ind-btn{all:unset;box-sizing:border-box;display:inline-block;font-family:var(--f-display);font-weight:600;font-size:15px;letter-spacing:.02em;padding:12px 22px;border:var(--border) solid var(--line);cursor:pointer;background:var(--paper);color:var(--ink)}
.ind-btn.go{background:var(--sky);border-color:var(--sky);color:#000}
.ind-btn.go:hover{background:var(--sky-deep);border-color:var(--sky-deep)}
.ind-btn[disabled]{opacity:.35;cursor:not-allowed}
.ind-drawer{position:fixed;inset:0 0 0 auto;width:min(520px,100%);background:var(--paper);border-left:var(--border-thick) solid var(--line);transform:translateX(100%);transition:transform .25s;z-index:10;padding:var(--u5) var(--u4);overflow:auto}
.ind-drawer.open{transform:none}
.ind-field{display:block;margin:var(--u2) 0}
.ind-field span{display:block;font-family:var(--f-mono);font-size:11px;letter-spacing:.12em;text-transform:uppercase;margin-bottom:6px}
.ind-field input,.ind-field textarea{width:100%;box-sizing:border-box;border:var(--border) solid var(--line);background:var(--paper);color:var(--ink);padding:12px;font:16px var(--f-body)}
.ind-err{border:var(--border) solid var(--line);background:var(--paper-dim);padding:var(--u2);margin-top:var(--u2);font-size:15px}
.ind-promises{display:grid;grid-template-columns:repeat(3,1fr);border:var(--border) solid var(--line);margin:var(--u5) 0}
.ind-promises div{padding:var(--u3);border-right:var(--border) solid var(--line)}
.ind-promises div:last-child{border-right:0}
.ind-promises b{font-family:var(--f-display);font-size:28px;display:block;margin-bottom:6px}
.cert{border:var(--border-thick) solid var(--line);padding:var(--u6) var(--u5);position:relative;text-align:center}
.cert:before{content:"";position:absolute;inset:8px;border:1px solid var(--line);pointer-events:none}
.cert .seal{font-family:var(--f-mono);font-size:11px;letter-spacing:.3em;text-transform:uppercase}
.cert .nm{font-family:'IBM Plex Serif',Georgia,serif;font-style:italic;font-size:44px;margin:var(--u3) 0 var(--u1)}
.cert .rl{font-family:var(--f-display);font-size:18px;letter-spacing:.08em;text-transform:uppercase}
.cert .meta{display:grid;grid-template-columns:repeat(3,1fr);margin-top:var(--u5);border-top:var(--border) solid var(--line);font-size:13px}
.cert .meta div{padding:var(--u2);border-right:1px solid var(--line)}
.cert .meta div:last-child{border-right:0}
.cert .meta span{display:block;font-family:var(--f-mono);font-size:10px;letter-spacing:.12em;text-transform:uppercase;opacity:.7;margin-bottom:4px}
.cert .fp{font-family:var(--f-mono);font-size:10px;margin-top:var(--u3);word-break:break-all;opacity:.75}
@media (max-width:900px){.ind{grid-template-columns:1fr}.ind-rail{position:static;height:auto;border-right:0;border-bottom:var(--border) solid var(--line)}.ind-bar{left:0;padding:var(--u2)}.ind-stage{padding:var(--u5) var(--u3) calc(var(--u8)+var(--u5))}.ind-h1{font-size:32px}.ind-promises{grid-template-columns:1fr}.ind-promises div{border-right:0;border-bottom:var(--border) solid var(--line)}}
@media print{.ind-rail,.ind-bar,.no-print{display:none!important}.ind{display:block}.ind-stage{padding:0}}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>
<div class="ind">
  <aside class="ind-rail" aria-label="Induction progress">
    <div class="ind-brand">VOUCHMORPH · INDUCTION</div>
    <div class="ind-who">
      <div class="k">Role</div>
      <div class="v"><?= h($status['role_label']) ?></div>
      <div class="k" style="margin-top:12px"><?= h($org) ?></div>
    </div>
    <div>
      <div class="ind-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" id="meter"><i id="meterFill"></i></div>
      <div class="ind-meter-l"><span id="meterTxt"></span><span id="meterPct"></span></div>
    </div>
    <nav id="rail"></nav>
    <a class="ind-manual-link" href="manual.php" target="_blank" rel="noopener">
      <b>My role manual</b>
      <small>Always available — before, during and after induction.</small>
    </a>
  </aside>

  <main class="ind-main">
    <div class="ind-stage" id="stage" tabindex="-1" aria-live="polite"></div>
    <div class="ind-bar no-print" id="bar" hidden>
      <div class="clock" id="clock"></div>
      <button class="ind-btn" id="helpBtn" type="button">I don’t understand</button>
      <button class="ind-btn go" id="understoodBtn" type="button" disabled>Understood</button>
    </div>
  </main>
</div>

<aside class="ind-drawer" id="drawer" aria-hidden="true" aria-labelledby="drawerTitle">
  <div class="ind-kicker">Help is part of induction</div>
  <h2 class="ind-h1" id="drawerTitle" style="font-size:30px">Not clear yet? That is fine.</h2>
  <p class="ind-lede" style="font-size:17px">Your role manual explains this in practical steps. You can also ask for help — your supervisor is told, and your question is kept with your induction record so the answer reaches you.</p>
  <p><a class="ind-btn" href="manual.php" target="_blank" rel="noopener">Open my manual</a></p>
  <label class="ind-field"><span>What is unclear? (optional)</span><textarea id="helpNote" rows="4" maxlength="1000"></textarea></label>
  <div style="display:flex;gap:8px">
    <button class="ind-btn go" id="helpSend" type="button">Ask for help</button>
    <button class="ind-btn" id="helpClose" type="button">Back to reading</button>
  </div>
  <div id="helpMsg"></div>
</aside>

<script>
(() => {
  const CSRF = <?= json_encode($csrf) ?>;
  const FIRST = <?= json_encode($first) ?>;
  const NAME = <?= json_encode($name) ?>;
  const ORG = <?= json_encode($org) ?>;
  let S = <?= json_encode($status) ?>;
  let cur = null, section = null, timer = null, remaining = 0, readToEnd = false, choice = null, locked = false;

  const $ = id => document.getElementById(id);
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const rich = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
  const L = 'ABCDE';

  async function api(action, body = {}) {
    const r = await fetch('induction.php', {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({action, csrf_token: CSRF, ...body})});
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Something went wrong');
    return j.data;
  }

  function paintRail() {
    $('meterFill').style.width = S.percent + '%';
    $('meterTxt').textContent = `${S.done} of ${S.total} understood`;
    $('meterPct').textContent = S.percent + '%';
    const groups = [['Foundation — everyone', S.sections.filter(s => s.foundation)],
                    [`Your role — ${S.role_label}`, S.sections.filter(s => !s.foundation)]];
    let n = 0;
    $('rail').innerHTML = groups.map(([label, list]) => `<div class="ind-group">${esc(label)}</div><ul class="ind-list">` +
      list.map(s => { n++; return `<li><button type="button" data-id="${s.id}" ${s.locked ? 'disabled' : ''} class="${s.id === cur ? 'cur' : ''}"
        aria-current="${s.id === cur}"><span class="n">${String(n).padStart(2,'0')}</span><span>${esc(s.title)}</span>
        <span class="st">${s.done ? '✓' : (s.changed ? 'UPDATED' : (s.locked ? '—' : s.minutes + '′'))}</span></button></li>`; }).join('') + '</ul>').join('');
    $('rail').querySelectorAll('button[data-id]').forEach(b => b.onclick = () => openSection(b.dataset.id));
  }

  function welcome() {
    $('bar').hidden = true;
    const mins = S.sections.reduce((a, s) => a + s.minutes, 0);
    const refresh = S.refresh_required ? `<div class="ind-err" style="margin-bottom:24px"><b>Your induction has been updated.</b> Only the sections marked UPDATED need your attention; everything else you already understood still counts.</div>` : '';
    $('stage').innerHTML = `${refresh}
      <div class="ind-kicker">${esc(ORG)} · ${esc(S.role_label)}</div>
      <h1 class="ind-h1">Welcome, ${esc(FIRST)}.</h1>
      <p class="ind-lede">Before you use VouchMorph, you will read how it works, what your role is trusted with, and what to do when something goes wrong. It takes about ${mins} minutes. You can stop at any point and resume exactly where you left off.</p>
      <div class="ind-promises">
        <div><b>Read.</b>Each section, properly. The Understood button waits until you have.</div>
        <div><b>Prove.</b>One short question per section. A wrong answer is explained, never penalised.</div>
        <div><b>Declare.</b>Your name and password confirm you were inducted. That record is yours.</div>
      </div>
      <button class="ind-btn go" id="begin" type="button">${S.done ? 'Continue where I left off' : 'Begin'}</button>`;
    $('begin').onclick = () => openSection(S.next);
    $('stage').focus();
  }

  async function openSection(id) {
    try { section = await api('open', {section: id}); } catch (e) { return flash(e.message); }
    cur = id; choice = null; readToEnd = section.done; remaining = section.seconds_remaining; locked = false;
    paintRail();
    const group = id.startsWith('F') ? 'Foundation' : S.role_label;
    $('stage').innerHTML = `
      <div class="ind-kicker">${esc(group)} · ${section.position} of ${section.total} · ${section.minutes} min read</div>
      <h1 class="ind-h1">${esc(section.title)}</h1>
      <div class="ind-read">${section.body.map(p => `<p>${rich(p)}</p>`).join('')}</div>
      <div class="ind-keep"><h3>Remember</h3><ul>${section.points.map(p => `<li>${rich(p)}</li>`).join('')}</ul></div>
      <div id="endMark"></div>
      <section class="ind-check" aria-labelledby="qTitle">
        <div class="ind-kicker">Check your understanding</div>
        <div class="q" id="qTitle">${esc(section.check.q)}</div>
        <div role="radiogroup" aria-labelledby="qTitle">${section.check.display.map((o, i) => `
          <label class="ind-opt"><input type="radio" name="opt" value="${i}" ${section.done ? 'disabled' : ''}>
            <span class="l">${L[i]}</span><span>${esc(o)}</span></label>`).join('')}</div>
        <div id="verdict"></div>
      </section>`;
    document.querySelectorAll('input[name=opt]').forEach(r => r.onchange = () => { choice = +r.value; gate(); });
    $('bar').hidden = false;
    window.scrollTo({top: 0});
    $('stage').focus();

    new IntersectionObserver((es, ob) => { if (es.some(e => e.isIntersecting)) { readToEnd = true; gate(); ob.disconnect(); } })
      .observe($('endMark'));

    clearInterval(timer);
    timer = setInterval(() => { if (remaining > 0) remaining--; gate(); }, 1000);
    if (section.done) { $('verdict').innerHTML = `<div class="ind-verdict"><div class="t">Understood.</div>${esc(section.check.why || '')}</div>`; }
    gate();
  }

  function gate() {
    const btn = $('understoodBtn');
    if (!section) return;
    if (section.done) { $('clock').textContent = 'Already understood.'; btn.textContent = S.next ? 'Next section' : (S.inducted ? 'Done' : 'Go to declaration'); btn.disabled = false; return; }
    btn.textContent = 'Understood';
    const parts = [];
    if (remaining > 0) parts.push(`Reading time · ${Math.floor(remaining/60)}:${String(remaining%60).padStart(2,'0')}`);
    if (!readToEnd) parts.push('Read to the end');
    if (choice === null) parts.push('Answer the check');
    $('clock').textContent = parts.length ? parts.join('  ·  ') : 'Ready when you are.';
    btn.disabled = locked || remaining > 0 || !readToEnd || choice === null;
  }

  $('understoodBtn').onclick = async () => {
    if (section.done) { return S.next ? openSection(S.next) : (S.inducted ? certificate() : declaration()); }
    locked = true; gate();
    let res;
    try { res = await api('answer', {section: cur, choice}); } catch (e) { locked = false; gate(); return flash(e.message); }
    if (res.correct) {
      S = await api('status');
      section.done = true;
      $('verdict').innerHTML = `<div class="ind-verdict"><div class="t">Correct.</div>${esc(res.why)}</div>`;
      document.querySelectorAll('input[name=opt]').forEach(r => r.disabled = true);
      paintRail(); locked = false; gate();
      $('verdict').scrollIntoView({behavior: 'smooth', block: 'center'});
    } else {
      $('verdict').innerHTML = `<div class="ind-verdict no"><div class="t">Not quite.</div>${esc(res.why)}
        <p style="margin:16px 0 0;display:flex;gap:8px;flex-wrap:wrap"><button class="ind-btn" type="button" id="reread">Read the section again</button>
        <a class="ind-btn" href="manual.php" target="_blank" rel="noopener">Open my manual</a></p></div>`;
      $('reread').onclick = () => openSection(cur);
      locked = false; choice = null;
      document.querySelectorAll('input[name=opt]').forEach(r => r.checked = false);
      remaining = section.min_seconds; readToEnd = false;
      new IntersectionObserver((es, ob) => { if (es.some(e => e.isIntersecting)) { readToEnd = true; gate(); ob.disconnect(); } }).observe($('endMark'));
      gate();
    }
  };

  function declaration() {
    clearInterval(timer); $('bar').hidden = true; cur = null; paintRail();
    $('stage').innerHTML = `
      <div class="ind-kicker">Final step</div>
      <h1 class="ind-h1">Your declaration</h1>
      <p class="ind-lede">You have read and understood all ${S.total} sections of the ${esc(S.role_label)} induction.</p>
      <div class="ind-keep"><h3>I declare that</h3><ul>
        <li>I have read every section of this induction and answered each check myself.</li>
        <li>I understand what my role may and may not do, and I will act within it.</li>
        <li>I will stop, record and escalate anything that looks wrong, and never correct money myself.</li>
        <li>I will use my manual whenever I am unsure.</li></ul></div>
      <label class="ind-field"><span>Type your full name — ${esc(NAME)}</span><input id="typed" autocomplete="off"></label>
      <label class="ind-field"><span>Password</span><input id="pw" type="password" autocomplete="current-password"></label>
      <label style="display:flex;gap:12px;align-items:flex-start;margin:16px 0;font-size:16px"><input type="checkbox" id="agree" style="width:20px;height:20px;margin-top:2px"> I make this declaration truthfully.</label>
      <button class="ind-btn go" id="declareBtn" type="button">Declare myself inducted</button>
      <div id="decMsg"></div>`;
    $('declareBtn').onclick = async () => {
      if (!$('agree').checked) return ($('decMsg').innerHTML = `<div class="ind-err">Tick the box to confirm.</div>`);
      try {
        const d = await api('declare', {typed_name: $('typed').value, password: $('pw').value});
        S = await api('status'); paintRail(); certificate(d);
      } catch (e) { $('decMsg').innerHTML = `<div class="ind-err">${esc(e.message)}</div>`; $('pw').value = ''; }
    };
  }

  function certificate(d) {
    $('bar').hidden = true;
    const when = new Date(d ? d.declared_at : S.declared_at);
    const ref = d ? d.certificate_ref : (S.certificate_ref || 'On record');
    const fp = d ? d.curriculum_sha256 : (S.curriculum_sha256 || '');
    $('stage').innerHTML = `
      <div class="cert">
        <div class="seal">VouchMorph · Certificate of induction</div>
        <div class="nm">${esc(NAME)}</div>
        <div class="rl">${esc(S.role_label)} · ${esc(ORG)}</div>
        <p class="ind-lede" style="font-size:16px;max-width:520px;margin:24px auto 0">has read, understood and declared the full induction for this role, and may now use VouchMorph within it.</p>
        <div class="meta">
          <div><span>Declared</span>${when.toLocaleString('en-GB', {dateStyle:'long', timeStyle:'short', timeZone:'Africa/Gaborone'})} CAT</div>
          <div><span>Reference</span>${esc(ref)}</div>
          <div><span>Sections</span>${S.total} of ${S.total} understood</div>
        </div>
        ${fp ? `<div class="fp">Curriculum fingerprint ${esc(fp)}</div>` : ''}
      </div>
      <p class="no-print" style="display:flex;gap:8px;margin-top:24px;flex-wrap:wrap">
        <a class="ind-btn go" href="index.php">Continue to VouchMorph</a>
        <button class="ind-btn" type="button" onclick="window.print()">Print certificate</button>
        <a class="ind-btn" href="manual.php">Open my manual</a></p>`;
  }

  // Help drawer
  const drawer = $('drawer');
  $('helpBtn').onclick = () => { drawer.classList.add('open'); drawer.setAttribute('aria-hidden', 'false'); $('helpNote').focus(); };
  $('helpClose').onclick = () => { drawer.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); };
  $('helpSend').onclick = async () => {
    try { await api('help', {section: cur, note: $('helpNote').value});
      $('helpMsg').innerHTML = `<div class="ind-err">Sent. Your supervisor has been told. Keep reading, or use your manual in the meantime.</div>`; $('helpNote').value = '';
    } catch (e) { $('helpMsg').innerHTML = `<div class="ind-err">${esc(e.message)}</div>`; }
  };
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $('helpClose').click(); });

  function flash(m) { const d = document.createElement('div'); d.className = 'ind-err'; d.textContent = m; $('stage').prepend(d); setTimeout(() => d.remove(), 6000); }

  paintRail();
  if (S.inducted) certificate(); else if (S.done === S.total) declaration(); else welcome();
})();
</script>
</body>
</html>
