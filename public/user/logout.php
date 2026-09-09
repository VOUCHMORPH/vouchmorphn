<?php
// logout.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Destroy all session data — happens immediately, before any of the
// visual sequence below. The user is already logged out at this point;
// everything after this is just a farewell moment on the way to login.
$_SESSION = [];
session_unset();
session_destroy();

// Clear the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!-- Fallback in case JS is disabled or fails: land on login regardless -->
<meta http-equiv="refresh" content="30;url=login.php">
<title>VouchMorph</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Sans+Condensed:wght@600;700&family=IBM+Plex+Mono:wght@500;600&family=Alex+Brush&family=Bebas+Neue&display=swap" rel="stylesheet">
<style>
    :root {
        --paper: #FBF9F5;
        --ink-900: #16232E;
        --ink-700: #24384A;
        --ink-500: #5B6B78;
        --brass: #B4884A;
        --brass-deep: #8A6530;
        --brass-tint: #F6EFDF;
        --f-display: 'Source Serif 4', serif;
        --f-body: 'IBM Plex Sans', sans-serif;
        --f-cond: 'IBM Plex Sans Condensed', sans-serif;
        --f-mono: 'IBM Plex Mono', monospace;
        --f-script: 'Alex Brush', cursive;
        --f-crawl: 'Bebas Neue', sans-serif;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { width: 100%; height: 100%; background: #000; overflow: hidden; font-family: var(--f-body); }

    #starfield { position: fixed; inset: 0; z-index: 1; background: #000; }

    /* ---- frame motif, matching login.php's museum-mat border ---- */
    .frame-inset { position: fixed; inset: 9vh 22px; z-index: 6; pointer-events: none; opacity: 0; transition: opacity 1s ease; }
    .frame-inset.show { opacity: 1; }
    .frame-line { position: absolute; inset: 0; border: 1px solid rgba(180,136,74,0.4); }
    .frame-strip { position: absolute; font-family: var(--f-mono); font-size: 10px; font-weight: 600; letter-spacing: 0.3em; text-transform: uppercase; color: var(--brass); background: #000; padding: 0 12px; white-space: nowrap; }
    .frame-strip.top { top: -9px; left: 30px; }
    .frame-strip.bottom { bottom: -9px; right: 30px; }
    .frame-strip.lateral { right: -22px; top: 50%; transform: translateY(-50%) rotate(180deg); writing-mode: vertical-rl; font-size: 12px; letter-spacing: 0.34em; font-weight: 600; color: var(--brass-deep); background: transparent; padding: 8px 0; }

    .letterbox { position: fixed; left: 0; right: 0; height: 9vh; background: #000; z-index: 5; opacity: 0; transition: opacity 1s ease; }
    .letterbox.top { top: 0; } .letterbox.bottom { bottom: 0; }
    .letterbox.show { opacity: 1; }

    /* ---- opening logo recede, Star Wars title style ---- */
    #logoStage { position: fixed; inset: 0; z-index: 4; display: flex; align-items: center; justify-content: center; perspective: 900px; }
    #logoCard { text-align: center; opacity: 0; }
    #logoCard.recede { animation: logoRecede 2.6s cubic-bezier(0.3,0,0.7,1) forwards; }
    @keyframes logoRecede {
        0%   { opacity: 0; transform: translateZ(0) scale(1); }
        12%  { opacity: 1; transform: translateZ(0) scale(1); }
        100% { opacity: 0; transform: translateZ(-700px) translateY(-40px) scale(0.25); }
    }
    #logoCard .mark { font-family: var(--f-display); font-weight: 600; font-size: clamp(38px, 7vw, 64px); color: var(--brass); letter-spacing: 0.01em; }
    #logoCard .mark sup { font-size: 13px; color: #fff; }
    #logoCard .rule { width: 70px; height: 1px; background: var(--brass); margin: 14px auto 0; }

    /* ---- farewell lines, serif italic on black ---- */
    #farewellStage { position: fixed; inset: 0; z-index: 5; display: flex; align-items: center; justify-content: center; text-align: center; padding: 0 24px; }
    .fline { position: absolute; font-family: var(--f-display); font-style: italic; font-weight: 400; font-size: clamp(19px, 3.4vw, 28px); color: rgba(246,239,223,0.92); opacity: 0; max-width: 680px; line-height: 1.5; }
    .fline.show { animation: flineFade 2.1s ease forwards; }
    @keyframes flineFade { 0%{opacity:0;transform:translateY(10px)} 20%{opacity:1;transform:translateY(0)} 75%{opacity:1} 100%{opacity:0;transform:translateY(-8px)} }

    /* ---- the crawl itself ---- */
    #crawlWrap { position: fixed; inset: 0; z-index: 5; overflow: hidden; perspective: 400px; perspective-origin: 50% 100%; display: none; }
    #crawlWrap.active { display: block; }
    #crawlTitle { position: absolute; top: 8vh; left: 0; right: 0; text-align: center; font-family: var(--f-crawl); letter-spacing: 0.06em; color: #FFE81F; text-shadow: 0 0 18px rgba(255,232,31,0.35); opacity: 0; z-index: 6; }
    #crawlTitle .ep { font-family: var(--f-mono); font-size: clamp(11px, 1.6vw, 14px); letter-spacing: 0.4em; color: var(--brass); text-shadow: none; }
    #crawlTitle .main { font-size: clamp(30px, 6vw, 54px); margin-top: 8px; }
    #crawlTitle.show { animation: titleFade 3.4s ease forwards; }
    @keyframes titleFade { 0%{opacity:0} 20%{opacity:1} 80%{opacity:1} 100%{opacity:0} }
    #crawlText { position: absolute; left: 50%; bottom: 0; width: 640px; margin-left: -320px; transform-origin: 50% 100%; transform: rotateX(28deg) translateY(0); font-family: var(--f-crawl); color: #FFE81F; text-shadow: 0 0 14px rgba(255,232,31,0.3); font-size: clamp(22px, 3.4vw, 30px); line-height: 1.9; text-align: center; letter-spacing: 0.02em; }
    #crawlText.roll { animation: crawlUp 15s linear forwards; }
    @keyframes crawlUp { 0% { transform: rotateX(28deg) translateY(60vh); } 100% { transform: rotateX(28deg) translateY(-190vh); } }
    .crawl-block { margin-bottom: 3.2em; }

    #flash { position: fixed; inset: 0; z-index: 15; background: #fff; opacity: 0; pointer-events: none; }
    #flash.pulse { animation: flashPulse 0.7s ease forwards; }
    @keyframes flashPulse { 0%{opacity:0} 35%{opacity:1} 100%{opacity:0} }

    /* ---- closing card, matching login.php's magazine panel ---- */
    #closingStage { position: fixed; inset: 0; z-index: 20; background: var(--brass-tint); display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; }
    #closingStage.show { animation: closingFade 2.8s ease forwards; }
    @keyframes closingFade { 0%{opacity:0} 18%{opacity:1} 82%{opacity:1} 100%{opacity:0} }
    #closingStage .card { text-align: center; padding: 0 24px; }
    #closingStage .mark { font-family: var(--f-display); font-weight: 600; font-size: 26px; color: var(--ink-900); }
    #closingStage .mark sup { font-size: 11px; color: var(--brass-deep); }
    #closingStage .script { font-family: var(--f-script); font-size: 34px; color: var(--ink-900); margin-top: 10px; }
    #closingStage .rule { width: 40px; height: 1px; background: var(--brass); margin: 18px auto 0; }

    #skipBtn { position: fixed; bottom: 8vh; right: 22px; z-index: 30; background: transparent; border: 1px solid rgba(255,255,255,0.28); color: rgba(255,255,255,0.65); font-family: var(--f-cond); font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; padding: 8px 15px; cursor: pointer; }
    #skipBtn:hover { border-color: #fff; color: #fff; }
</style>
</head>
<body>

<canvas id="starfield"></canvas>
<div class="letterbox top" id="lbTop"></div>
<div class="letterbox bottom" id="lbBottom"></div>
<div class="frame-inset" id="frameInset">
    <div class="frame-line"></div>
    <div class="frame-strip top">Vouchmorph</div>
    <div class="frame-strip bottom">Session Ended</div>
    <div class="frame-strip lateral">Vouchmorph&trade;</div>
</div>

<div id="logoStage">
    <div id="logoCard">
        <div class="mark">Vouchmorph<sup>&trade;</sup></div>
        <div class="rule"></div>
    </div>
</div>

<div id="farewellStage"></div>

<div id="crawlWrap">
    <div id="crawlTitle">
        <div class="ep">Episode 42</div>
        <div class="main">The Last Swap</div>
    </div>
    <div id="crawlText">
        <div class="crawl-block">It is a moment of transition. Having<br>issued its final transfer of the day, the<br>VOUCHMORPH network stands down.</div>
        <div class="crawl-block">Every wallet has been reconciled.<br>Every identity, verified. Every hold,<br>released or honored.</div>
        <div class="crawl-block">Across every institution in the network,<br>the ledger falls silent... until the<br>next session begins.</div>
        <div class="crawl-block">Your credentials are being cleared<br>from this device.</div>
        <div class="crawl-block">May your balance be ever in your favor.</div>
    </div>
</div>

<div id="flash"></div>

<div id="closingStage">
    <div class="card">
        <div class="mark">Vouchmorph<sup>&trade;</sup></div>
        <div class="script">Until next time</div>
        <div class="rule"></div>
    </div>
</div>

<button id="skipBtn" onclick="skipAll()">Skip &rsaquo;</button>

<script>
const canvas = document.getElementById('starfield');
const ctx = canvas.getContext('2d');
let W, H, stars = [];
const STAR_COUNT = 420;
let speed = 0.4;
let hyperActive = false;
let skipped = false;

function resize() { W = canvas.width = window.innerWidth; H = canvas.height = window.innerHeight; }
window.addEventListener('resize', resize);
resize();

function makeStar() {
    return { x: (Math.random() - 0.5) * W, y: (Math.random() - 0.5) * H, z: Math.random() * W };
}
for (let i = 0; i < STAR_COUNT; i++) stars.push(makeStar());

function drawStars() {
    ctx.fillStyle = hyperActive ? 'rgba(0,0,0,0.25)' : '#000';
    ctx.fillRect(0, 0, W, H);
    const cx = W / 2, cy = H / 2;
    for (let s of stars) {
        const prevZ = s.z;
        s.z -= speed * (hyperActive ? 26 : 1);
        if (s.z <= 1) { Object.assign(s, makeStar()); s.z = W; continue; }
        const k = 128 / s.z;
        const x = s.x * k + cx, y = s.y * k + cy;
        if (x < 0 || x > W || y < 0 || y > H) continue;
        const pk = 128 / prevZ;
        const px = s.x * pk + cx, py = s.y * pk + cy;
        const size = Math.max(0.4, (1 - s.z / W) * 2.4);
        ctx.strokeStyle = ctx.fillStyle = 'rgba(255,255,255,' + Math.min(1, (1 - s.z / W) + 0.15) + ')';
        if (hyperActive) {
            ctx.lineWidth = size;
            ctx.beginPath(); ctx.moveTo(px, py); ctx.lineTo(x, y); ctx.stroke();
        } else {
            ctx.beginPath(); ctx.arc(x, y, size, 0, Math.PI * 2); ctx.fill();
        }
    }
    requestAnimationFrame(drawStars);
}
drawStars();

let audioCtx = null;
function initAudio() {
    try {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
    } catch (e) {
        audioCtx = null;
    }
}

function playKwik() {
    if (!audioCtx) return;
    try {
        const now = audioCtx.currentTime;

        const osc = audioCtx.createOscillator();
        const oscGain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(1400, now);
        osc.frequency.exponentialRampToValueAtTime(180, now + 0.16);
        oscGain.gain.setValueAtTime(0.0001, now);
        oscGain.gain.exponentialRampToValueAtTime(0.5, now + 0.008);
        oscGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.17);
        osc.connect(oscGain); oscGain.connect(audioCtx.destination);
        osc.start(now); osc.stop(now + 0.18);

        const bufferSize = audioCtx.sampleRate * 0.12;
        const buffer = audioCtx.createBuffer(1, bufferSize, audioCtx.sampleRate);
        const data = buffer.getChannelData(0);
        for (let i = 0; i < bufferSize; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / bufferSize);
        const noise = audioCtx.createBufferSource();
        noise.buffer = buffer;
        const noiseFilter = audioCtx.createBiquadFilter();
        noiseFilter.type = 'highpass'; noiseFilter.frequency.value = 2500;
        const noiseGain = audioCtx.createGain();
        noiseGain.gain.setValueAtTime(0.35, now);
        noiseGain.gain.exponentialRampToValueAtTime(0.0001, now + 0.1);
        noise.connect(noiseFilter); noiseFilter.connect(noiseGain); noiseGain.connect(audioCtx.destination);
        noise.start(now);
    } catch (e) {}
}

const FLINES = ["Every swap, verified.", "Every identity, protected.", "Session closing..."];

function addFline(text, delay) {
    const el = document.createElement('div');
    el.className = 'fline';
    el.textContent = text;
    document.getElementById('farewellStage').appendChild(el);
    setTimeout(() => { if (!skipped) el.classList.add('show'); }, delay);
}

function goToLogin() {
    window.location.href = 'login.php';
}

function beginSequence() {
    initAudio();
    document.getElementById('lbTop').classList.add('show');
    document.getElementById('lbBottom').classList.add('show');
    document.getElementById('frameInset').classList.add('show');

    document.getElementById('logoCard').classList.add('recede');

    const farewellStart = 2600;
    FLINES.forEach((t, i) => addFline(t, farewellStart + i * 1900));
    const crawlStart = farewellStart + FLINES.length * 1900 + 500;

    setTimeout(() => {
        if (skipped) return;
        const wrap = document.getElementById('crawlWrap');
        wrap.classList.add('active');
        document.getElementById('crawlTitle').classList.add('show');
        setTimeout(() => { if (!skipped) document.getElementById('crawlText').classList.add('roll'); }, 2600);
    }, crawlStart);

    const hyperStart = crawlStart + 2600 + 15000;
    setTimeout(() => { if (!skipped) triggerHyperspace(); }, hyperStart);
}

function triggerHyperspace() {
    hyperActive = true;
    speed = 0.4;
    document.getElementById('lbTop').classList.remove('show');
    document.getElementById('lbBottom').classList.remove('show');
    document.getElementById('frameInset').classList.remove('show');
    let t0 = performance.now();
    function ramp() {
        const elapsed = performance.now() - t0;
        speed = 0.4 + Math.min(1, elapsed / 900) * 3.2;
        if (elapsed < 900 && !skipped) requestAnimationFrame(ramp);
    }
    ramp();
    playKwik();
    document.getElementById('crawlWrap').classList.remove('active');

    setTimeout(() => {
        if (skipped) return;
        document.getElementById('flash').classList.add('pulse');
        setTimeout(() => { if (!skipped) showClosingCard(); }, 350);
    }, 1300);
}

function showClosingCard() {
    document.getElementById('closingStage').classList.add('show');
    setTimeout(() => { if (!skipped) goToLogin(); }, 2700);
}

function skipAll() {
    skipped = true;
    goToLogin();
}

beginSequence();
</script>

</body>
</html>
