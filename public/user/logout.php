<?php
/* ============================================================================
 * logout.php — VouchMorph
 * ----------------------------------------------------------------------------
 * Order matters here:
 *   1. read what we need from the session
 *   2. fetch today's swaps (before the session is gone)
 *   3. destroy the session and clear the cookie
 *   4. only then emit any output
 *
 * The user is logged out by the time a single byte reaches the browser.
 * Everything below step 3 is a five-second farewell on the way to login.php.
 * ========================================================================== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* --- 1. what we need, before the session goes -------------------------- */
$sessionUser = $_SESSION['user'] ?? [];
$userId = $sessionUser['user_id'] ?? $sessionUser['id'] ?? ($_SESSION['user_id'] ?? null);
$userId = is_numeric($userId) ? (int) $userId : null;

/* --- 2. today's departures ---------------------------------------------
 * ADJUST THIS ONE BLOCK to match your schema. The dashboard's
 * /api/v1/swap/history.php already returns this shape, so the column names
 * below are taken from it — but the table name is a guess, and the code
 * checks that the table exists before querying so a wrong guess degrades to
 * an empty board instead of a fatal error.
 *
 * If the board comes up with only the THIS SESSION row, this is why: point
 * SWAP_TABLE_CANDIDATES at the right table.
 * -------------------------------------------------------------------- */
const SWAP_TABLE_CANDIDATES = ['swaps', 'swap_transactions', 'transactions', 'swap_records'];
const MAX_ROWS = 3;   // keeps the whole sequence at five seconds

$departures   = [];
$sessionTotal = 0.0;
$currency     = 'BWP';

if ($userId) {
    try {
        $dsn = getenv('DATABASE_URL');
        if ($dsn) {
            $p = parse_url($dsn);
            $pdo = new PDO(
                sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=2',
                    $p['host'],
                    $p['port'] ?? 5432,
                    ltrim($p['path'] ?? '', '/')
                ),
                $p['user'] ?? '',
                urldecode($p['pass'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                ]
            );

            // Which of the candidate tables actually exists?
            $in = implode(',', array_fill(0, count(SWAP_TABLE_CANDIDATES), '?'));
            $st = $pdo->prepare(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name IN ($in) LIMIT 1"
            );
            $st->execute(SWAP_TABLE_CANDIDATES);
            $table = $st->fetchColumn();

            if ($table) {
                $q = $pdo->prepare(
                    "SELECT created_at, swap_type, source_institution,
                            destination_institution, amount, currency
                     FROM \"{$table}\"
                     WHERE user_id = :uid
                       AND created_at >= CURRENT_DATE
                     ORDER BY created_at DESC
                     LIMIT :lim"
                );
                $q->bindValue(':uid', $userId, PDO::PARAM_INT);
                $q->bindValue(':lim', MAX_ROWS, PDO::PARAM_INT);
                $q->execute();

                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $amount = (float) ($row['amount'] ?? 0);
                    $sessionTotal += $amount;
                    if (!empty($row['currency'])) {
                        $currency = strtoupper(substr($row['currency'], 0, 3));
                    }

                    $from = strtoupper((string) ($row['source_institution'] ?? 'SOURCE'));
                    $type = strtoupper((string) ($row['swap_type'] ?? 'DEPOSIT'));
                    if ($type === 'IDENTITY') {
                        $to = 'IDENTITY';
                    } elseif ($type === 'CASHOUT') {
                        $to = 'CASH:ATM';
                    } else {
                        $to = strtoupper((string) ($row['destination_institution'] ?? 'DEST'));
                    }

                    $departures[] = [
                        'time'   => date('H:i', strtotime((string) $row['created_at'])),
                        'route'  => substr($from, 0, 8) . ' > ' . substr($to, 0, 9),
                        'amount' => number_format($amount, 2),
                    ];
                }
                // oldest first, the way a board reads
                $departures = array_reverse($departures);
            }
        }
    } catch (Throwable $e) {
        // A farewell screen must never be the thing that breaks logout.
        error_log('[logout] departures lookup failed: ' . $e->getMessage());
        $departures = [];
    }
}

/* --- 3. destroy everything --------------------------------------------- */
$_SESSION = [];
session_unset();
session_destroy();

if (ini_get('session.use_cookies')) {
    $cp = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $cp['path'], $cp['domain'], $cp['secure'], $cp['httponly']);
}

/* --- 4. output ---------------------------------------------------------- */
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$BOARD = json_encode([
    'rows'     => $departures,
    'total'    => number_format($sessionTotal, 2),
    'currency' => $currency,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- If JS is off or fails, land on login anyway. -->
<meta http-equiv="refresh" content="12;url=login.php">
<title>VouchMorph — signed out</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --ink:#04120E;--flap:#141F1B;--flap-hi:#1C2C26;--flap-ink:#F2F5F3;
  --gold:#FFD24A;--accent:#00A878;--hot:#FF7A59;
  --f:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;
  --m:ui-monospace,SFMono-Regular,'Cascadia Mono',Consolas,monospace;
}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:100%;height:100%;background:var(--ink);overflow:hidden;font-family:var(--f)}
body::after{content:"";position:fixed;inset:0;pointer-events:none;z-index:8;
  background:repeating-linear-gradient(0deg,rgba(255,255,255,.02) 0 1px,transparent 1px 3px)}
body.gold{background:#120C00}

.flap{position:relative;display:inline-block;background:var(--flap);color:var(--flap-ink);
  font-family:var(--m);font-weight:700;text-align:center;overflow:hidden;
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.05),0 1px 0 rgba(0,0,0,.55)}
.flap .h{position:absolute;left:0;right:0;overflow:hidden;display:flex;justify-content:center;background:var(--flap)}
.flap .h.t{top:0;height:50%;align-items:flex-start;background:linear-gradient(180deg,var(--flap-hi),var(--flap))}
.flap .h.b{bottom:0;height:50%;align-items:flex-end;background:linear-gradient(180deg,var(--flap),#0E1815)}
.flap .h span{display:block;line-height:1}
.flap .h.b span{transform:translateY(-50%)}
.flap .seam{position:absolute;left:0;right:0;top:50%;height:1px;background:rgba(0,0,0,.72);z-index:6}
.flap .fx{position:absolute;left:0;right:0;overflow:hidden;display:flex;justify-content:center;
  backface-visibility:hidden;transform-style:preserve-3d;z-index:5}
.flap .fx.t{top:0;height:50%;align-items:flex-start;transform-origin:bottom;
  background:linear-gradient(180deg,var(--flap-hi),var(--flap))}
.flap .fx.b{bottom:0;height:50%;align-items:flex-end;transform-origin:top;
  background:linear-gradient(180deg,var(--flap),#0E1815)}
.flap .fx span{display:block;line-height:1}
.flap .fx.b span{transform:translateY(-50%)}
.gold-line .flap{--flap:#3A2E06;--flap-hi:#4C3C09;color:var(--gold);
  box-shadow:inset 0 0 0 1px rgba(255,210,74,.18),0 1px 0 rgba(0,0,0,.55)}

.wrap{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:3;
  opacity:0;transition:opacity .34s ease}
.wrap.in{opacity:1}
.inner{width:100%;max-width:940px;padding:20px}

.hdr{display:flex;justify-content:space-between;align-items:baseline;
  border-bottom:1px solid rgba(255,255,255,.16);padding-bottom:9px;margin-bottom:12px}
.hdr .l{font-family:var(--m);font-size:11px;letter-spacing:.28em;color:var(--accent)}
.hdr .r{font-family:var(--m);font-size:11px;letter-spacing:.14em;color:rgba(234,242,239,.4)}
.cols{display:flex;gap:9px;font-family:var(--m);font-size:9px;letter-spacing:.16em;
  color:rgba(234,242,239,.3);margin-bottom:8px}
.rows{display:flex;flex-direction:column;gap:5px}
.row{display:flex;gap:9px;opacity:0;transition:opacity .25s}
.row.up{opacity:1}
.row.session{margin-top:11px;padding-top:11px;border-top:1px dashed rgba(255,255,255,.18)}
.seg{display:flex;gap:2px}

.close{margin-top:28px;text-align:center;min-height:78px}
.line{display:flex;justify-content:center}
.tag{font-family:var(--m);font-size:9.5px;letter-spacing:.2em;margin-top:12px;opacity:0;transition:opacity .5s}
.tag.in{opacity:1}
.tag.common{color:rgba(234,242,239,.32)}
.tag.uncommon{color:#5AC8FA}
.tag.rare{color:var(--hot)}
.tag.legendary{color:var(--gold);text-shadow:0 0 14px rgba(255,210,74,.5)}

#skip{position:fixed;top:16px;right:16px;z-index:20;background:transparent;
  border:1px solid rgba(255,255,255,.2);color:rgba(234,242,239,.62);
  font:700 11px/1 var(--f);letter-spacing:.05em;padding:10px 15px;cursor:pointer}
#skip:hover{color:#fff;border-color:#fff}
#skip:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
#snd{position:fixed;top:16px;right:104px;z-index:20;background:transparent;
  border:1px solid rgba(255,255,255,.2);color:rgba(234,242,239,.62);
  font:700 11px/1 var(--f);letter-spacing:.05em;padding:10px 15px;cursor:pointer}
#snd.on{color:var(--accent);border-color:var(--accent)}
noscript div{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;
  color:#F2F5F3;font-size:15px;z-index:30;background:var(--ink)}
noscript a{color:var(--accent)}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition-duration:.01ms!important;animation-duration:.01ms!important}}
</style>
</head>
<body>

<noscript><div>You have been signed out. <a href="login.php">&nbsp;Return to login</a></div></noscript>

<button id="snd" type="button">Sound off</button>
<button id="skip" type="button">Skip</button>

<div class="wrap" id="wrap"><div class="inner" id="inner"></div></div>

<script>
(function () {
'use strict';

var BOARD = <?php echo $BOARD ?: '{"rows":[],"total":"0.00","currency":"BWP"}'; ?>;
var RED = matchMedia('(prefers-reduced-motion:reduce)').matches;
var SET = " ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.,:>";
var done = false;

function leave() {
  if (done) return;
  done = true;
  window.location.replace('login.php');
}
document.getElementById('skip').onclick = leave;
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') leave(); });
/* Hard backstop: never strand anyone here. */
setTimeout(leave, 9000);

/* ------------------------------------------------------------- audio */
var AC = null, master = null, snd = false, lastClack = 0;
try { snd = localStorage.getItem('vm_sound') === '1'; } catch (e) {}
function ainit() {
  if (!AC) {
    var C = window.AudioContext || window.webkitAudioContext;
    if (!C) return;
    AC = new C(); master = AC.createGain(); master.gain.value = .45; master.connect(AC.destination);
  }
  if (AC.state === 'suspended') AC.resume();
}
function nbuf(s) {
  var n = Math.floor(AC.sampleRate * s), b = AC.createBuffer(1, n, AC.sampleRate), d = b.getChannelData(0);
  for (var i = 0; i < n; i++) d[i] = Math.random() * 2 - 1;
  return b;
}
function clack() {
  if (!snd || !AC) return;
  var now = AC.currentTime;
  if (now - lastClack < .012) return;
  lastClack = now;
  var s = AC.createBufferSource(); s.buffer = nbuf(.045);
  var f = AC.createBiquadFilter(); f.type = 'bandpass';
  f.frequency.value = 2000 + Math.random() * 1100; f.Q.value = 1.7;
  var g = AC.createGain();
  g.gain.setValueAtTime(.13, now); g.gain.exponentialRampToValueAtTime(.001, now + .04);
  s.connect(f); f.connect(g); g.connect(master); s.start(now); s.stop(now + .055);
}
function tone(fr, du, ty, vo, to) {
  if (!snd || !AC) return;
  var now = AC.currentTime, o = AC.createOscillator(), g = AC.createGain();
  o.type = ty || 'sine'; o.frequency.setValueAtTime(fr, now);
  if (to) o.frequency.exponentialRampToValueAtTime(to, now + du);
  g.gain.setValueAtTime(vo || .14, now); g.gain.exponentialRampToValueAtTime(.001, now + du);
  o.connect(g); g.connect(master); o.start(now); o.stop(now + du + .02);
}
function thud()  { tone(94, .4, 'sine', .28, 42); }
function latch() { tone(230, .16, 'square', .06, 130); }
function ding()  { tone(880, .35, 'sine', .10); setTimeout(function(){ tone(1320,.45,'sine',.07); }, 80); }
function fanfare(){ [523,659,784,1047,1319].forEach(function(f,i){ setTimeout(function(){ tone(f,.55,'triangle',.10); }, i*105); }); }

var sndBtn = document.getElementById('snd');
function paintSnd(){ sndBtn.textContent = snd ? 'Sound on' : 'Sound off'; sndBtn.classList.toggle('on', snd); }
paintSnd();
sndBtn.onclick = function () {
  snd = !snd;
  try { localStorage.setItem('vm_sound', snd ? '1' : '0'); } catch (e) {}
  if (snd) { ainit(); latch(); }
  paintSnd();
};
if (snd) ainit();   // resumes silently if the context is already unlocked

/* -------------------------------------------------------------- flap */
function Flap(host, size, speed) {
  this.i = 0; this.busy = false; this.q = null; this.D = speed || 22;
  var el = document.createElement('div');
  el.className = 'flap';
  el.style.width = Math.round(size * .68) + 'px';
  el.style.height = size + 'px';
  el.style.fontSize = Math.round(size * .72) + 'px';
  el.innerHTML = '<div class="h t"><span> </span></div><div class="h b"><span> </span></div>' +
    '<div class="seam"></div><div class="fx t" style="display:none"><span></span></div>' +
    '<div class="fx b" style="display:none"><span></span></div>';
  host.appendChild(el);
  this.ht = el.querySelector('.h.t span'); this.hb = el.querySelector('.h.b span');
  this.ft = el.querySelector('.fx.t');     this.fb = el.querySelector('.fx.b');
  this.fts = this.ft.querySelector('span'); this.fbs = this.fb.querySelector('span');
}
Flap.prototype.to = function (ch) {
  var s = this, t = SET.indexOf(ch);
  if (t < 0) t = 0;
  if (t === this.i) return Promise.resolve();
  if (this.busy) { this.q = ch; return Promise.resolve(); }
  this.busy = true;
  var g = 0;
  function step() {
    if (s.i === t || g++ > 60) {
      s.busy = false;
      if (s.q) { var q = s.q; s.q = null; s.to(q); }
      return Promise.resolve();
    }
    var f = SET[s.i];
    s.i = (s.i + 1) % SET.length;
    return s.flip(f, SET[s.i]).then(step);
  }
  return step();
};
Flap.prototype.flip = function (f, t) {
  var s = this;
  return new Promise(function (res) {
    if (RED) { s.ht.textContent = t; s.hb.textContent = t; res(); return; }
    var D = s.D;
    s.ht.textContent = t; s.fts.textContent = f; s.fbs.textContent = t;
    s.ft.style.display = 'flex'; s.fb.style.display = 'flex';
    s.ft.style.transition = 'none'; s.fb.style.transition = 'none';
    s.ft.style.transform = 'rotateX(0deg)'; s.fb.style.transform = 'rotateX(90deg)';
    void s.ft.offsetHeight;
    s.ft.style.transition = 'transform ' + (D/2) + 'ms linear';
    s.ft.style.transform = 'rotateX(-90deg)';
    clack();
    setTimeout(function () {
      s.ft.style.display = 'none';
      s.fb.style.transition = 'transform ' + (D/2) + 'ms cubic-bezier(.4,1.5,.6,1)';
      s.fb.style.transform = 'rotateX(0deg)';
    }, D/2);
    setTimeout(function () { s.hb.textContent = t; s.fb.style.display = 'none'; res(); }, D + 4);
  });
};
function seg(host, size, width, speed) {
  var d = document.createElement('div'); d.className = 'seg'; host.appendChild(d);
  var f = [], i;
  for (i = 0; i < width; i++) f.push(new Flap(d, size, speed));
  return {
    flaps: f,
    set: function (txt, stagger) {
      var s = String(txt).toUpperCase().padEnd(width, ' ').slice(0, width);
      for (var k = 0; k < width; k++) (function (k) {
        setTimeout(function () { f[k].to(s[k]); }, (stagger || 0) * k);
      })(k);
    }
  };
}

/* --------------------------------------------------------- sign-offs */
var SIGNOFFS = [
  { id:'until',    w:4,  t:'UNTIL NEXT TIME',   r:'common' },
  { id:'balanced', w:4,  t:'LEDGER BALANCED',   r:'common' },
  { id:'gowell',   w:4,  t:'GO WELL',           r:'common' },
  { id:'allclear', w:4,  t:'ALL HOLDS CLEAR',   r:'common' },
  { id:'soon',     w:4,  t:'SEE YOU SOON',      r:'common' },
  { id:'sala',     w:2,  t:'SALA SENTLE',       r:'uncommon' },
  { id:'tsamaya',  w:2,  t:'TSAMAYA SENTLE',    r:'uncommon' },
  { id:'gate',     w:2,  t:'GATE SHUT',         r:'uncommon' },
  { id:'nothing',  w:2,  t:'NOTHING LEFT',      r:'uncommon' },
  { id:'sleeps',   w:1,  t:'THE SWITCH SLEEPS', r:'rare' },
  { id:'kea',      w:1,  t:'KE A LEBOGA',       r:'rare' },
  { id:'pula',     w:.5, t:'PULA',              r:'legendary' }
];
function roll() {
  var total = 0, i;
  for (i = 0; i < SIGNOFFS.length; i++) total += SIGNOFFS[i].w;
  var r = Math.random() * total, acc = 0;
  for (i = 0; i < SIGNOFFS.length; i++) { acc += SIGNOFFS[i].w; if (r <= acc) return SIGNOFFS[i]; }
  return SIGNOFFS[0];
}
var FOUND = {};
try { FOUND = JSON.parse(localStorage.getItem('vm_signoffs') || '{}'); } catch (e) { FOUND = {}; }

var eggHeld = false;
document.addEventListener('keydown', function (e) { if (e.key === 'v' || e.key === 'V') eggHeld = true; });
document.addEventListener('keyup',   function (e) { if (e.key === 'v' || e.key === 'V') eggHeld = false; });

/* ---------------------------------------------------------- sequence */
var wait = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };

async function play() {
  var pick = roll();
  var mob = innerWidth < 720;
  var S = mob ? 12 : 16;
  var Wtime = 5, Wroute = mob ? 15 : 20, Wamt = 10, Wstat = 10;
  var rowsData = (BOARD.rows || []).slice(0, 3);

  var inner = document.getElementById('inner');
  inner.innerHTML =
    '<div class="hdr"><span class="l">DEPARTURES</span><span class="r" id="hc"></span></div>' +
    '<div class="cols" id="cols"></div>' +
    '<div class="rows" id="rows"></div>' +
    '<div class="close"><div class="line" id="line"></div><div class="tag" id="tag"></div></div>';

  document.getElementById('hc').textContent =
    new Date().toLocaleTimeString('en-GB', { hour:'2-digit', minute:'2-digit' }) + '  ·  GABORONE';

  var cw = Math.round(S * .68) + 2;
  var cols = document.getElementById('cols');
  [['TIME',Wtime],['ROUTE',Wroute],['AMOUNT',Wamt],['STATUS',Wstat]].forEach(function (c) {
    var d = document.createElement('div');
    d.style.width = (c[1] * cw) + 'px';
    d.textContent = c[0];
    cols.appendChild(d);
  });

  var host = document.getElementById('rows');
  function mkRow(cls) {
    var r = document.createElement('div');
    r.className = 'row' + (cls ? ' ' + cls : '');
    host.appendChild(r);
    return { el:r, time:seg(r,S,Wtime,20), route:seg(r,S,Wroute,20),
             amt:seg(r,S,Wamt,20), stat:seg(r,S,Wstat,20) };
  }
  var rows = rowsData.map(function () { return mkRow(); });
  var sess = mkRow('session');

  document.getElementById('wrap').classList.add('in');
  await wait(RED ? 0 : 260);
  if (done) return;

  for (var i = 0; i < rows.length; i++) {
    rows[i].el.classList.add('up');
    rows[i].time.set(rowsData[i].time, 12);
    rows[i].route.set(rowsData[i].route, 12);
    rows[i].amt.set(rowsData[i].amount, 12);
    rows[i].stat.set('BOARDING', 12);
    await wait(260);
    if (done) return;
  }

  sess.el.classList.add('up');
  sess.time.set('NOW', 12);
  sess.route.set('THIS SESSION', 12);
  sess.amt.set(BOARD.total || '0.00', 12);
  sess.stat.set('OPEN', 12);
  await wait(520);
  if (done) return;

  for (var j = 0; j < rows.length; j++) (function (j) {
    setTimeout(function () {
      if (!done) { rows[j].stat.set('DEPARTED', 10); latch(); }
    }, j * 130);
  })(j);
  await wait(rows.length * 130 + 330);
  if (done) return;

  sess.stat.set('SIGNED OUT', 10);
  thud();
  await wait(600);
  if (done) return;

  var text = eggHeld ? 'GO ON THEN' : pick.t;
  var lineHost = document.getElementById('line');
  if (pick.r === 'legendary') {
    document.body.classList.add('gold');
    lineHost.classList.add('gold-line');
    fanfare();
  } else if (pick.r === 'rare') {
    ding();
  }
  seg(lineHost, mob ? 18 : 26, Math.max(text.length, 10), 32).set(text, 40);
  await wait(pick.r === 'legendary' ? 1150 : 800);
  if (done) return;

  var isNew = !FOUND[pick.id];
  FOUND[pick.id] = true;
  var n = 0, k;
  for (k in FOUND) if (FOUND.hasOwnProperty(k)) n++;
  try { localStorage.setItem('vm_signoffs', JSON.stringify(FOUND)); } catch (e) {}

  var tag = document.getElementById('tag');
  tag.className = 'tag in ' + pick.r;
  tag.textContent = (isNew ? 'NEW · ' : '') + pick.r.toUpperCase() +
                    ' · ' + n + ' OF ' + SIGNOFFS.length + ' FOUND';

  await wait(pick.r === 'legendary' ? 1250 : 950);
  document.getElementById('wrap').classList.remove('in');
  await wait(300);
  leave();
}

play().catch(function (e) { console.error('[logout]', e); leave(); });

})();
</script>
</body>
</html>
