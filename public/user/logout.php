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
<!-- Fallback in case JS is disabled: land on login after 8s regardless -->
<meta http-equiv="refresh" content="8;url=login.php">
<title>VouchMorph</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body {
        width: 100%; height: 100%;
        background: #04120E;
        overflow: hidden;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    .letterbox {
        position: fixed; left: 0; right: 0; height: 8vh; background: #000; z-index: 5;
    }
    .letterbox.top { top: 0; }
    .letterbox.bottom { bottom: 0; }

    .stage {
        position: fixed; inset: 0;
        display: flex; align-items: center; justify-content: center;
        text-align: center;
        padding: 0 24px;
    }

    .line {
        position: absolute;
        font-weight: 300;
        font-size: clamp(20px, 4vw, 34px);
        color: rgba(255,255,255,0.92);
        letter-spacing: 0.02em;
        opacity: 0;
        max-width: 720px;
        line-height: 1.5;
    }
    .line.show {
        animation: lineFade 1.9s ease forwards;
    }
    @keyframes lineFade {
        0%   { opacity: 0; transform: translateY(10px) scale(0.98); }
        18%  { opacity: 1; transform: translateY(0) scale(1); }
        75%  { opacity: 1; transform: translateY(0) scale(1); }
        100% { opacity: 0; transform: translateY(-8px) scale(1.01); }
    }

    .end-card {
        position: absolute;
        opacity: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 18px;
    }
    .end-card.show { animation: endFade 3s ease forwards; }
    @keyframes endFade {
        0%   { opacity: 0; transform: scale(0.9); }
        20%  { opacity: 1; transform: scale(1); }
        82%  { opacity: 1; transform: scale(1); }
        100% { opacity: 0; }
    }
    .end-word {
        font-weight: 600;
        font-size: clamp(30px, 6vw, 52px);
        letter-spacing: 0.22em;
        color: #FFFFFF;
        text-transform: uppercase;
    }
    .end-rule {
        width: 64px; height: 2px;
        background: #00A878;
    }
    .end-sub {
        font-size: 13px;
        font-weight: 400;
        letter-spacing: 0.08em;
        color: rgba(255,255,255,0.55);
        text-transform: uppercase;
    }

    .brand-card {
        position: absolute;
        opacity: 0;
        text-align: center;
    }
    .brand-card.show { animation: brandFade 2.6s ease forwards; }
    @keyframes brandFade {
        0%   { opacity: 0; }
        25%  { opacity: 1; }
        80%  { opacity: 1; }
        100% { opacity: 0; }
    }
    .brand-mark {
        font-size: 24px;
        font-weight: 800;
        color: #FFFFFF;
        letter-spacing: -0.01em;
    }
    .brand-mark sup { color: #00A878; font-size: 11px; }
    .brand-tag {
        margin-top: 8px;
        font-size: 12px;
        font-weight: 400;
        color: rgba(255,255,255,0.45);
        letter-spacing: 0.05em;
    }

    .curtain {
        position: fixed; inset: 0;
        background: #000;
        opacity: 0;
        pointer-events: none;
        z-index: 10;
        transition: opacity 1.4s ease;
    }
    .curtain.down { opacity: 1; }

    .skip-btn {
        position: fixed; bottom: 10vh; right: 24px;
        z-index: 20;
        background: transparent;
        border: 1px solid rgba(255,255,255,0.25);
        color: rgba(255,255,255,0.6);
        font-family: inherit;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        padding: 9px 16px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .skip-btn:hover { border-color: rgba(255,255,255,0.6); color: #fff; }
</style>
</head>
<body>

<div class="letterbox top"></div>
<div class="letterbox bottom"></div>

<div class="stage" id="stage"></div>
<div class="curtain" id="curtain"></div>

<button class="skip-btn" id="skipBtn" onclick="skipToLogin()">Skip &rsaquo;</button>

<script>
// Each line is shown, then fades before the next appears.
// Timings are in milliseconds and deliberately unhurried — this is meant
// to feel like the credits rolling, not a loading spinner.
const LINES = [
    "Every swap, verified.",
    "Every identity, protected.",
    "Nothing moved without your say-so.",
    "Thank you for trusting VouchMorph today."
];

const LINE_DURATION = 2600;   // how long each line's fade cycle takes
const LINE_GAP = 2300;        // spacing between line starts
const END_CARD_DELAY = LINE_GAP * LINES.length + 300;
const END_CARD_DURATION = 3400;
const BRAND_DELAY = END_CARD_DELAY + END_CARD_DURATION - 400;
const BRAND_DURATION = 2900;
const CURTAIN_DELAY = BRAND_DELAY + BRAND_DURATION - 600;
const REDIRECT_DELAY = CURTAIN_DELAY + 1500;

const stage = document.getElementById('stage');
let redirectTimer = null;
let skipped = false;

function addNode(html, delay, className) {
    const el = document.createElement('div');
    el.className = className;
    el.innerHTML = html;
    stage.appendChild(el);
    setTimeout(() => { if (!skipped) el.classList.add('show'); }, delay);
}

LINES.forEach((text, i) => {
    addNode(text, i * LINE_GAP + 200, 'line');
});

addNode(
    `<div class="end-word">The End</div><div class="end-rule"></div><div class="end-sub">Session closed</div>`,
    END_CARD_DELAY,
    'end-card'
);

addNode(
    `<div class="brand-mark">VouchMorph<sup>TM</sup></div><div class="brand-tag">See you again soon</div>`,
    BRAND_DELAY,
    'brand-card'
);

function goToLogin() {
    window.location.href = 'login.php';
}

function skipToLogin() {
    skipped = true;
    document.getElementById('curtain').classList.add('down');
    clearTimeout(redirectTimer);
    setTimeout(goToLogin, 500);
}

setTimeout(() => { if (!skipped) document.getElementById('curtain').classList.add('down'); }, CURTAIN_DELAY);
redirectTimer = setTimeout(goToLogin, REDIRECT_DELAY);
</script>

</body>
</html>
