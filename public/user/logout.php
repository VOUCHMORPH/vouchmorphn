<?php
/* ============================================================================
 * logout.php — VouchMorph
 * ----------------------------------------------------------------------------
 * "Dusk over the water."
 *
 * The session is destroyed before a single byte of HTML is sent. Everything
 * after that is a farewell on the way to login.php, and it can fail in any
 * way it likes without affecting whether the user is actually logged out.
 *
 * No database call. The sequence shows no session figures, so querying for
 * them would only add latency and one more way for logout to break.
 * ========================================================================== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];
session_unset();
session_destroy();

if (ini_get('session.use_cookies')) {
    $cp = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $cp['path'], $cp['domain'], $cp['secure'], $cp['httponly']);
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- If JS is off or fails, land on login regardless. -->
<meta http-equiv="refresh" content="20;url=login.php">
<title>VouchMorph — signed out</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400&family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{
  --cor:'Cormorant Garamond',Georgia,'Times New Roman',serif;
  --f:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;
  --m:ui-monospace,SFMono-Regular,'Cascadia Mono',Consolas,monospace;
  --brass:#C8A265;
}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:100%;height:100%;background:#000;overflow:hidden;font-family:var(--f)}

#cv{position:fixed;inset:0;width:100%;height:100%;z-index:1}
.grain{position:fixed;inset:0;z-index:2;pointer-events:none;opacity:.06;
  background-image:radial-gradient(circle at 1px 1px,#fff 1px,transparent 0);
  background-size:3px 3px;mix-blend-mode:overlay}
.vig{position:fixed;inset:0;z-index:3;pointer-events:none;
  background:radial-gradient(ellipse at 50% 46%,transparent 30%,rgba(0,0,0,.88) 100%)}

.words{position:fixed;inset:0;z-index:6;display:flex;flex-direction:column;
  align-items:center;justify-content:center;text-align:center;padding:34px;pointer-events:none}
.ln{position:absolute;opacity:0;transition:opacity 2.1s ease;
  font-family:var(--cor);font-weight:300;color:#F0E4CC}
.ln.in{opacity:1}
.m1{font-size:clamp(17px,2.5vw,27px);letter-spacing:.13em;font-style:italic;max-width:22ch}
.m2{font-size:clamp(24px,6.2vw,72px);letter-spacing:.24em;text-indent:.24em;color:#F6ECD6;
  white-space:nowrap}

.tail{position:absolute;display:flex;flex-direction:column;align-items:center;
  transform:translateY(clamp(60px,9.5vw,110px))}
.hairline{width:0;height:1px;background:rgba(200,162,101,.55);
  transition:width 2.4s cubic-bezier(.16,1,.3,1)}
.hairline.in{width:min(340px,46vw)}
.wordmark{font-family:var(--cor);font-weight:400;font-size:clamp(13px,1.7vw,18px);
  letter-spacing:.46em;text-indent:.46em;color:rgba(240,228,204,.74);
  margin-top:clamp(16px,2vw,24px);opacity:0;transition:opacity 1.8s ease}
.wordmark.in{opacity:1}
.tagline{font-family:var(--m);font-size:9px;letter-spacing:.34em;
  color:rgba(240,228,204,.28);margin-top:13px;opacity:0;transition:opacity 1.6s ease}
.tagline.in{opacity:1}

#skip,#snd{position:fixed;top:18px;z-index:40;background:transparent;
  border:1px solid rgba(240,228,204,.15);color:rgba(240,228,204,.38);
  font:600 10.5px/1 var(--f);letter-spacing:.08em;padding:10px 15px;cursor:pointer}
#skip{right:18px}
#snd{right:96px}
#skip:hover,#snd:hover{color:#F0E4CC;border-color:rgba(240,228,204,.5)}
#snd.on{color:var(--brass);border-color:rgba(200,162,101,.55)}
#skip:focus-visible,#snd:focus-visible{outline:1px solid var(--brass);outline-offset:2px}

noscript div{position:fixed;inset:0;z-index:60;background:#000;display:flex;
  align-items:center;justify-content:center;color:#F0E4CC;font-size:15px;
  font-family:var(--cor);letter-spacing:.08em}
noscript a{color:var(--brass);margin-left:8px}

@media(prefers-reduced-motion:reduce){
  *,*::before,*::after{transition-duration:.01ms!important;animation-duration:.01ms!important}
}
</style>
</head>
<body>

<noscript><div>You have been signed out.<a href="login.php">Return to login</a></div></noscript>

<canvas id="cv"></canvas>
<i class="grain"></i><i class="vig"></i>

<div class="words" id="words">
  <div class="ln m1" id="l1">Rich is a number that sits still.</div>
  <div class="ln m1" id="l2">Wealth is the part that moves.</div>
  <div class="ln m2" id="l3">YOU ARE WEALTHY</div>
  <div class="tail">
    <div class="hairline" id="hr"></div>
    <div class="wordmark" id="wm">VOUCHMORPH</div>
    <div class="tagline" id="tg">UNTIL NEXT TIME</div>
  </div>
</div>

<button id="snd" type="button">Sound off</button>
<button id="skip" type="button">Skip</button>

<script>
(function () {
'use strict';

/* --------------------------------------------------------------------------
 * PACE — 1.0 is the full cut, about 16.4s end to end. 0.7 gives roughly 11.5s
 * and still reads as unhurried. Below about 0.55 it stops feeling deliberate
 * and starts feeling like a transition, which defeats the whole thing.
 * ----------------------------------------------------------------------- */
var PACE = 1.0;

var RED = matchMedia('(prefers-reduced-motion:reduce)').matches;
var T0 = performance.now();
var done = false;
function el() { return (performance.now() - T0) / 1000; }
function at(ms) { return ms * PACE; }

function leave() {
  if (done) return;
  done = true;
  window.location.replace('login.php');
}
document.getElementById('skip').onclick = leave;
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') leave(); });
/* Hard backstop — nobody is ever stranded on this page. */
setTimeout(leave, at(16400) + 2600);

/* ============================================================== audio ==
 * A minor waltz, 3/4 at 66bpm: Dm - Gm - A7, resolving to D major with a
 * Picardy third as the closing line arrives. Scheduled from wherever we
 * currently are, so enabling sound mid-sequence still lands in time.
 * ==================================================================== */
var AC = null, mg = null, snd = false, scored = false, voices = [];
try { snd = localStorage.getItem('vm_sound') === '1'; } catch (e) {}

function ainit() {
  if (!AC) {
    var C = window.AudioContext || window.webkitAudioContext;
    if (!C) return false;
    AC = new C();
    mg = AC.createGain();
    mg.gain.value = 0.44;
    mg.connect(AC.destination);
  }
  return true;
}
function bow(f, when, dur, vol, det) {
  if (!AC) return;
  if (when < 0) { dur += when; when = 0; if (dur <= 0.25) return; }
  var t = AC.currentTime + when;
  var o = AC.createOscillator(), g = AC.createGain();
  o.type = 'sine';
  o.frequency.setValueAtTime(f, t);
  if (det) o.detune.setValueAtTime(det, t);
  g.gain.setValueAtTime(0.0001, t);
  g.gain.exponentialRampToValueAtTime(vol, t + dur * 0.34);
  g.gain.setValueAtTime(vol, t + dur * 0.62);
  g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
  o.connect(g); g.connect(mg);
  o.start(t); o.stop(t + dur + 0.08);
  voices.push(o);
}
function pluck(f, when, dur, vol) {
  if (!AC || when < -0.05) return;
  var t = AC.currentTime + Math.max(0, when);
  var o = AC.createOscillator(), g = AC.createGain();
  o.type = 'triangle';
  o.frequency.setValueAtTime(f, t);
  g.gain.setValueAtTime(0.0001, t);
  g.gain.exponentialRampToValueAtTime(vol, t + 0.012);
  g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
  o.connect(g); g.connect(mg);
  o.start(t); o.stop(t + dur + 0.05);
  voices.push(o);
}
function roomTone(offset) {
  if (!AC) return;
  var dur = Math.max(2, at(15000) / 1000 - offset);
  var n = Math.floor(AC.sampleRate * Math.min(dur, 17));
  var b = AC.createBuffer(1, n, AC.sampleRate), d = b.getChannelData(0), i;
  for (i = 0; i < n; i++) d[i] = Math.random() * 2 - 1;
  var t = AC.currentTime, s = AC.createBufferSource(); s.buffer = b;
  var f = AC.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = 240; f.Q.value = 0.5;
  var g = AC.createGain();
  g.gain.setValueAtTime(0.0001, t);
  g.gain.exponentialRampToValueAtTime(0.030, t + 2.4);
  g.gain.setValueAtTime(0.030, t + Math.max(2.5, dur - 2.2));
  g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
  s.connect(f); f.connect(g); g.connect(mg);
  s.start(t); s.stop(t + dur);
  voices.push(s);
}

var B = 0.909 * PACE, BAR = B * 3;
function startScore(offset) {
  if (scored || !AC) return;
  scored = true;
  roomTone(offset);

  var CH = [
    { b: 146.8, c: [220.0, 293.7, 349.2] },   /* Dm */
    { b:  98.0, c: [196.0, 233.1, 293.7] },   /* Gm */
    { b: 110.0, c: [164.8, 220.0, 277.2] }    /* A7 */
  ];
  var MEL = [
    [[0, 440.0, 1.5]],
    [[0, 466.2, 0.75], [0.75, 440.0, 0.75], [1.5, 392.0, 1.0]],
    [[0, 349.2, 0.9], [1.0, 329.6, 1.1]]
  ];
  for (var i = 0; i < 3; i++) {
    var t = i * BAR - offset, ch = CH[i], k;
    bow(ch.b, t, BAR * 0.94, 0.070);
    bow(ch.b * 1.5, t, BAR * 0.94, 0.024, 6);
    for (k = 0; k < 3; k++) bow(ch.c[k], t + B, B * 1.7, 0.028, k * 4 - 4);
    for (k = 0; k < 3; k++) bow(ch.c[k], t + B * 2, B * 0.85, 0.021, k * 4 - 4);
    (function (t2) {
      MEL[i].forEach(function (n) {
        pluck(n[1], t2 + n[0] * PACE, n[2] * PACE, 0.055);
        pluck(n[1] * 2, t2 + n[0] * PACE + 0.015, n[2] * PACE * 0.5, 0.013);
      });
    })(t);
  }
  /* D major. The one warm gesture in the whole piece. */
  var res = 3 * BAR - offset;
  [146.8, 220.0, 293.7, 370.0].forEach(function (f, i) {
    bow(f, res, 6.2, 0.052, i * 3);
  });
}

var sndBtn = document.getElementById('snd');
function paintSnd() {
  sndBtn.textContent = snd ? 'Sound on' : 'Sound off';
  sndBtn.classList.toggle('on', snd);
}
paintSnd();
sndBtn.onclick = function () {
  snd = !snd;
  try { localStorage.setItem('vm_sound', snd ? '1' : '0'); } catch (e) {}
  paintSnd();
  if (snd) {
    if (ainit()) AC.resume().then(function () { startScore(el()); }).catch(function () {});
  } else if (mg && AC) {
    try { mg.gain.linearRampToValueAtTime(0.0001, AC.currentTime + 0.4); } catch (e) {}
  }
};
/* If the browser already trusts us — the click on Log out often counts —
   the score starts on its own. If not, the button is right there. */
if (snd && ainit()) {
  AC.resume().then(function () {
    if (AC.state === 'running') startScore(el());
  }).catch(function () {});
}

/* ============================================================== canvas = */
var cv = document.getElementById('cv'), g = cv.getContext('2d'),
    W = 0, H = 0, DPR = Math.min(devicePixelRatio || 1, 2), i;
function csize() {
  W = innerWidth; H = innerHeight;
  cv.width = W * DPR; cv.height = H * DPR;
  g.setTransform(DPR, 0, 0, DPR, 0, 0);
}
addEventListener('resize', csize);
csize();

function towers(seed, span) {
  var s = seed, out = [], x = -40;
  function r() { s = (s * 16807) % 2147483647; return s / 2147483647; }
  while (x < span + 60) {
    var w = 26 + r() * 74;
    out.push({ x: x, w: w, h: 0.20 + r() * 0.52,
               steps: 1 + ((r() * 3) | 0), spire: r() < 0.14, mast: r() < 0.07 });
    x += w + 3 + r() * 14;
  }
  return out;
}
var far = towers(11, W * 1.25), mid = towers(37, W * 1.20), near = towers(91, W * 1.15);

var motes = [];
for (i = 0; i < 80; i++) motes.push({
  x: Math.random() * W, y: Math.random() * H,
  r: 0.5 + Math.random() * 1.5, v: 4 + Math.random() * 13, p: Math.random() * 6.283
});

function drawRank(list, base, scale, shade, winA, t) {
  for (var a = 0; a < list.length; a++) {
    var tw = list[a], hh = tw.h * base * scale, w = tw.w * scale,
        x = tw.x * scale, y = base - hh, j;
    g.fillStyle = shade;
    for (j = 0; j < tw.steps; j++) {
      var f = j / tw.steps, ww = w * (1 - f * 0.30), yy = y + hh * f * 0.34;
      g.fillRect(x + (w - ww) / 2, yy, ww, base - yy);
    }
    if (tw.spire) {
      var sw = w * 0.20;
      g.fillRect(x + w / 2 - sw / 2, y - hh * 0.20, sw, hh * 0.22);
      g.beginPath();
      g.moveTo(x + w / 2, y - hh * 0.34);
      g.lineTo(x + w / 2 + sw * 0.55, y - hh * 0.19);
      g.lineTo(x + w / 2 - sw * 0.55, y - hh * 0.19);
      g.closePath(); g.fill();
    }
    if (tw.mast) {
      g.fillRect(x + w / 2 - 1, y - hh * 0.42, 2, hh * 0.24);
      g.globalAlpha = 0.5 + 0.5 * Math.sin(t * 2.1 + a);
      g.fillStyle = 'rgba(255,150,90,.9)';
      g.beginPath(); g.arc(x + w / 2, y - hh * 0.44, 1.8, 0, 6.283); g.fill();
      g.globalAlpha = 1; g.fillStyle = shade;
    }
    if (winA > 0) {
      var cols = Math.max(1, Math.floor(w / 13)), rows = Math.max(2, Math.floor(hh / 17));
      for (j = 0; j < cols * rows; j++) {
        if (((a * 7 + j * 13) % 10) < 4) continue;
        var cc = j % cols, rr = (j / cols) | 0;
        g.globalAlpha = winA * (0.28 + ((a * 3 + j * 5) % 7) / 12);
        g.fillStyle = '#FFC271';
        g.fillRect(x + 6 + cc * ((w - 10) / cols), y + 9 + rr * ((hh - 14) / rows),
                   Math.max(1.4, (w - 10) / cols - 5), 3.2);
      }
      g.globalAlpha = 1; g.fillStyle = shade;
    }
  }
}

function frame() {
  if (done) return;
  var t = el();
  var reveal = RED ? 1 : Math.min(1, t / (3.4 * PACE));
  var push = 1 + t * 0.0075;
  var k;

  var q = g.createLinearGradient(0, 0, 0, H);
  q.addColorStop(0, '#07070A');
  q.addColorStop(.44, '#12100E');
  q.addColorStop(.66, 'rgba(' + Math.round(58 * reveal) + ',' + Math.round(34 * reveal) + ',' + Math.round(18 * reveal) + ',1)');
  q.addColorStop(.80, 'rgba(' + Math.round(128 * reveal) + ',' + Math.round(74 * reveal) + ',' + Math.round(30 * reveal) + ',1)');
  q.addColorStop(1, '#0B0805');
  g.fillStyle = q; g.fillRect(0, 0, W, H);

  var sy = H * 0.80;
  var sg = g.createRadialGradient(W * 0.62, sy, 6, W * 0.62, sy, Math.min(W, H) * 0.55 * push);
  sg.addColorStop(0, 'rgba(255,196,120,' + (0.50 * reveal) + ')');
  sg.addColorStop(.35, 'rgba(214,132,54,' + (0.18 * reveal) + ')');
  sg.addColorStop(1, 'rgba(0,0,0,0)');
  g.fillStyle = sg; g.fillRect(0, 0, W, H);

  for (k = 0; k < 4; k++) {
    g.globalAlpha = 0.05 * reveal;
    g.fillStyle = '#C98B4A';
    g.fillRect(0, H * (0.60 + k * 0.055) + Math.sin(t * 0.25 + k) * 4, W, H * 0.028);
  }
  g.globalAlpha = 1;

  var b = H * 0.855;
  g.save();
  g.translate(W * 0.5, b); g.scale(push, push); g.translate(-W * 0.5, -b);
  drawRank(far,  b,             1.00, 'rgba(16,13,12,' + (0.86 * reveal) + ')', 0.10 * reveal, t);
  drawRank(mid,  b + H * 0.035, 1.06, 'rgba(9,8,8,'    + (0.94 * reveal) + ')', 0.22 * reveal, t);
  drawRank(near, b + H * 0.085, 1.14, 'rgba(3,3,3,'    + (0.98 * reveal) + ')', 0.34 * reveal, t);
  g.restore();

  g.globalAlpha = reveal * 0.5;
  var wg = g.createLinearGradient(0, H * 0.88, 0, H);
  wg.addColorStop(0, 'rgba(60,34,14,.7)');
  wg.addColorStop(1, 'rgba(4,3,2,1)');
  g.fillStyle = wg; g.fillRect(0, H * 0.88, W, H * 0.12);
  for (k = 0; k < 26; k++) {
    g.globalAlpha = reveal * (0.03 + Math.random() * 0.05);
    g.fillStyle = '#E2A768';
    g.fillRect(Math.random() * W, H * 0.885 + Math.random() * H * 0.10, 10 + Math.random() * 70, 1);
  }
  g.globalAlpha = 1;

  for (k = 0; k < motes.length; k++) {
    var m = motes[k];
    m.y -= m.v * 0.012;
    if (m.y < 0) m.y = H;
    g.globalAlpha = reveal * (0.05 + 0.10 * Math.sin(t * 0.8 + m.p));
    g.fillStyle = '#F2CFA0';
    g.beginPath(); g.arc(m.x + Math.sin(t * 0.35 + m.p) * 10, m.y, m.r, 0, 6.283); g.fill();
  }
  g.globalAlpha = 1;

  requestAnimationFrame(frame);
}
requestAnimationFrame(frame);

/* ============================================================ the words = */
function show(id) { var e = document.getElementById(id); if (e && !done) e.classList.add('in'); }
function hide(id) { var e = document.getElementById(id); if (e && !done) e.classList.remove('in'); }

setTimeout(function () { show('l1'); }, at(2400));
setTimeout(function () { hide('l1'); }, at(5200));
setTimeout(function () { show('l2'); }, at(5600));
setTimeout(function () { hide('l2'); }, at(8200));
setTimeout(function () { show('l3'); }, at(8700));
setTimeout(function () { show('hr'); }, at(10100));
setTimeout(function () { show('wm'); }, at(11100));
setTimeout(function () { show('tg'); }, at(12200));

setTimeout(function () {
  if (done) return;
  var w = document.getElementById('words');
  w.style.transition = 'opacity 1.6s ease';
  w.style.opacity = '0';
  cv.style.transition = 'opacity 1.6s ease';
  cv.style.opacity = '0';
}, at(14600));
setTimeout(leave, at(16400));

})();
</script>
</body>
</html>
