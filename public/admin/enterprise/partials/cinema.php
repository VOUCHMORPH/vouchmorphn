<?php
/**
 * partials/cinema.php — VouchMorph Enterprise sign-in and sign-out sequences.
 *
 * Include once per page, at the end of <body> (shell-foot.php does this).
 *
 *  - Sign-in: plays once, on the first page after a successful login
 *    (login.php sets $_SESSION['vm_play_intro']). The flag is cleared
 *    here, so a refresh never replays it.
 *  - Sign-out: any link to logout.php on the page is intercepted. The
 *    tape "stops" over the live page while logout.php is called with a
 *    POST; the session report then shows the real figures it returns.
 *    If JavaScript or the request fails, the link works normally and
 *    logout.php renders the same sequence as a standalone page.
 *
 * Standalone use (logout.php): set $cinemaMode = 'outro-page' and
 * $cinemaSummary = [...] before including.
 *
 * Everything is namespaced (#vmcStage, .vmc-*) so it cannot collide
 * with shell.css. Sounds are synthesised in the browser: no files.
 */

if (!function_exists('vmcFirstName')) {
    function vmcFirstName(string $name): string { $p = preg_split('/\s+/', trim($name)); return $p[0] ?? $name; }
    function vmcInitialName(string $name): string {
        $p = preg_split('/\s+/', trim($name));
        return count($p) > 1 ? (preg_match('/^./u', $p[0], $vmcM) ? $vmcM[0] : '') . '. ' . end($p) : $name;
    }
}

$cinemaMode = $cinemaMode ?? 'page';
$vmcUser = $_SESSION['enterprise_user'] ?? null;
$vmcIntro = null;

if ($cinemaMode === 'page' && $vmcUser && !empty($_SESSION['vm_play_intro'])) {
    unset($_SESSION['vm_play_intro']);

    // The boot screen says what the audit chain check actually found.
    // It runs the database's own verifier, capped at 1.5 s so a large
    // log can never hold up sign-in; if it cannot finish, the screen
    // says ONLINE rather than claiming a check that did not happen.
    $vmcAudit = 'ONLINE';
    try {
        $vmcDb = function_exists('getDBConnection') ? getDBConnection() : null;
        if ($vmcDb instanceof PDO && $vmcDb->query("SELECT to_regprocedure('audit_chain_verify()') IS NOT NULL")->fetchColumn()) {
            $vmcDb->beginTransaction();
            $vmcDb->exec("SET LOCAL statement_timeout = 1500");
            $vmcRow = $vmcDb->query("SELECT intact FROM audit_chain_verify()")->fetch(PDO::FETCH_ASSOC);
            $vmcDb->commit();
            $vmcAudit = !empty($vmcRow['intact']) ? 'INTACT' : 'ATTENTION';
            if ($vmcAudit === 'ATTENTION') {
                error_log('[ENTERPRISE] audit chain verification failed at sign-in for org user ' . ($vmcUser['org_user_id'] ?? '?'));
            }
        }
    } catch (Throwable $e) {
        if (isset($vmcDb) && $vmcDb instanceof PDO && $vmcDb->inTransaction()) { $vmcDb->rollBack(); }
        $vmcAudit = 'ONLINE';
    }

    $vmcName = (string)($vmcUser['full_name'] ?? $vmcUser['username'] ?? 'Operator');
    $vmcIntro = [
        'name' => $vmcName,
        'first' => vmcFirstName($vmcName),
        'initial' => vmcInitialName($vmcName),
        'org' => (string)($vmcUser['organization_name'] ?? 'Your organization'),
        'role' => (string)($vmcUser['role_label'] ?? ucwords(str_replace('_', ' ', (string)($vmcUser['role'] ?? '')))),
        'audit' => $vmcAudit,
    ];
}

if ($vmcUser && empty($_SESSION['vmc_logout_token'])) {
    $_SESSION['vmc_logout_token'] = bin2hex(random_bytes(16));
}

$vmcConfig = [
    'mode' => $cinemaMode,
    'intro' => $vmcIntro,
    'outro' => $cinemaMode === 'outro-page' ? ($cinemaSummary ?? null) : null,
    'user' => $vmcUser ? [
        'name' => (string)($vmcUser['full_name'] ?? $vmcUser['username'] ?? ''),
        'first' => vmcFirstName((string)($vmcUser['full_name'] ?? $vmcUser['username'] ?? '')),
        'initial' => vmcInitialName((string)($vmcUser['full_name'] ?? $vmcUser['username'] ?? '')),
        'org' => (string)($vmcUser['organization_name'] ?? ''),
    ] : null,
    'token' => $_SESSION['vmc_logout_token'] ?? null,
    'loginUrl' => $cinemaLoginUrl ?? '/admin/enterprise/login.php',
];
$vmcStartOn = $vmcIntro !== null || $cinemaMode === 'outro-page';
?>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=VT323&family=Caveat:wght@600&display=swap" rel="stylesheet">
<style>
#vmcStage{
  --vmc-ink:#0B1B2B;--vmc-brass:#9C7A3C;--vmc-brass-hi:#C9A227;--vmc-brass-tint:#F4EFE3;--vmc-night:#0A1420;--vmc-phos:#F2C572;
  --vmc-serif:'Source Serif 4',Georgia,serif;--vmc-cond:'IBM Plex Sans Condensed','IBM Plex Sans',sans-serif;--vmc-crt:'VT323',ui-monospace,monospace;
  position:fixed;inset:0;z-index:2147483000;display:none;overflow:hidden;background:#03070b;font-family:'IBM Plex Sans',system-ui,sans-serif;
  -webkit-font-smoothing:antialiased;color:#fff;box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}
#vmcStage *,#vmcStage *::before,#vmcStage *::after{box-sizing:border-box;margin:0;padding:0}
#vmcStage.vmc-on{display:block}
#vmcStage button{font:inherit;cursor:pointer}
#vmcStage :focus-visible{outline:2px solid var(--vmc-brass-hi);outline-offset:3px}
html.vmc-lock,html.vmc-lock body{overflow:hidden}
.vmc-room{position:absolute;inset:0;background:radial-gradient(60% 45% at 50% 38%,rgba(201,162,39,.10),transparent 70%),radial-gradient(120% 80% at 50% 30%,#122235 0%,#0A1420 45%,#03070b 100%)}
.vmc-viewport{position:absolute;left:50%;top:52%;width:800px;height:640px;transform:translate(-50%,-50%) scale(var(--vmc-fit,1));transform-origin:50% 50%}
.vmc-camera{position:absolute;inset:0;transform-origin:0 0}
.vmc-camera svg{position:absolute;inset:0;overflow:visible}
.vmc-disk-layer{position:absolute;inset:0;perspective:900px;perspective-origin:495px 445px}
.vmc-disk-layer.vmc-over{z-index:3}
.vmc-disk{position:absolute;left:420px;top:444px;width:150px;height:156px;transform-origin:50% 0;will-change:transform;filter:drop-shadow(0 14px 18px rgba(0,0,0,.55))}
.vmc-front{z-index:2;pointer-events:none}
.vmc-crt{position:absolute;inset:0;display:none;background:#050806;color:var(--vmc-phos);overflow:hidden}
.vmc-crt.vmc-on{display:block}
.vmc-glass{position:absolute;inset:2.2vmin;border-radius:3.5vmin/5vmin;overflow:hidden;background:radial-gradient(120% 100% at 50% 50%,#0f160f 0%,#070b08 70%,#020302 100%);box-shadow:inset 0 0 12vmin rgba(0,0,0,.9),0 0 0 1px rgba(242,197,114,.06)}
.vmc-glass::after{content:"";position:absolute;inset:0;pointer-events:none;z-index:9;background:repeating-linear-gradient(to bottom,rgba(0,0,0,.28) 0 1px,transparent 1px 3px);mix-blend-mode:multiply}
.vmc-glass::before{content:"";position:absolute;inset:0;pointer-events:none;z-index:10;background:radial-gradient(90% 70% at 30% 20%,rgba(255,255,255,.05),transparent 60%);animation:vmcFlicker 3.2s infinite steps(1)}
@keyframes vmcFlicker{0%,100%{opacity:1}47%{opacity:.85}48%{opacity:1}73%{opacity:.92}}
.vmc-boot,.vmc-report{position:absolute;inset:0;font-family:var(--vmc-crt);white-space:pre-wrap;text-shadow:0 0 6px rgba(242,197,114,.65),1px 0 0 rgba(255,80,60,.25),-1px 0 0 rgba(80,200,255,.2)}
.vmc-boot{padding:6vmin 7vmin;font-size:clamp(18px,3.1vmin,34px);line-height:1.22}
.vmc-report{padding:7vmin 8vmin;font-size:clamp(18px,3.2vmin,36px);line-height:1.25;z-index:5}
.vmc-attn{color:#ff8a6a;text-shadow:0 0 8px rgba(255,120,90,.7)}
.vmc-caret{display:inline-block;width:.55em;height:1em;background:var(--vmc-phos);vertical-align:-.12em;animation:vmcBlink .9s steps(1) infinite;box-shadow:0 0 8px rgba(242,197,114,.8)}
@keyframes vmcBlink{50%{opacity:0}}
.vmc-osd{position:absolute;font-family:var(--vmc-crt);color:#fff;font-size:clamp(22px,4vmin,44px);text-shadow:2px 2px 0 rgba(0,0,0,.6);z-index:12;display:none}
.vmc-osd.vmc-on{display:block}
.vmc-osd-tl{top:5vmin;left:6vmin}.vmc-osd-br{bottom:5vmin;right:6vmin}
.vmc-noise{position:absolute;inset:0;width:100%;height:100%;image-rendering:pixelated;opacity:0;z-index:8;mix-blend-mode:screen}
.vmc-track{position:absolute;left:0;right:0;height:14%;top:-20%;z-index:11;opacity:0;background:linear-gradient(transparent,rgba(255,255,255,.35) 40%,rgba(255,255,255,.08) 60%,transparent);filter:blur(1px)}
.vmc-bars{position:absolute;inset:0;display:none;grid-template-rows:67% 8% 25%;z-index:7}
.vmc-bars.vmc-on{display:grid}
.vmc-bars .vmc-row{display:grid;grid-template-columns:repeat(7,1fr)}
.vmc-title{position:absolute;inset:0;display:none;z-index:6;background:#070d15;overflow:hidden}
.vmc-title.vmc-on{display:block}
.vmc-floor{position:absolute;left:-50%;right:-50%;bottom:-8%;height:52%;background:repeating-linear-gradient(to right,rgba(201,162,39,.35) 0 1px,transparent 1px 7%),repeating-linear-gradient(to bottom,rgba(201,162,39,.35) 0 1px,transparent 1px 9%);transform:perspective(320px) rotateX(64deg);transform-origin:50% 0;opacity:.55;-webkit-mask-image:linear-gradient(transparent,#000 45%);mask-image:linear-gradient(transparent,#000 45%);animation:vmcRoll 1.6s linear infinite}
@keyframes vmcRoll{to{background-position:0 0,0 9%}}
.vmc-sun{position:absolute;left:50%;top:22%;width:44vmin;height:44vmin;transform:translateX(-50%);border-radius:50%;background:repeating-linear-gradient(to bottom,rgba(201,162,39,.20) 0 6%,transparent 6% 9%);opacity:0;-webkit-mask-image:linear-gradient(#000 20%,transparent 85%);mask-image:linear-gradient(#000 20%,transparent 85%)}
.vmc-card{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:6vmin}
.vmc-presents{font-family:var(--vmc-cond);font-size:clamp(12px,1.9vmin,17px);letter-spacing:.34em;color:rgba(244,239,227,.65);opacity:0;margin-bottom:2.2vmin}
.vmc-stripes{width:min(68vw,760px);display:grid;gap:.55vmin;margin-bottom:1.6vmin}
.vmc-stripes i{display:block;height:.8vmin;min-height:4px;transform-origin:0 50%;transform:scaleX(0)}
.vmc-logo{position:relative;font-family:var(--vmc-serif);font-weight:700;font-size:clamp(46px,11.5vmin,150px);letter-spacing:.02em;line-height:1;white-space:nowrap}
.vmc-logo .vmc-l{display:inline-block;opacity:0;background:linear-gradient(180deg,#FFF3CF 0%,#EBCB7A 34%,#A9832F 58%,#F3D48A 60%,#7A5B25 100%);-webkit-background-clip:text;background-clip:text;color:transparent;filter:drop-shadow(0 .4vmin 0 rgba(0,0,0,.55))}
.vmc-logo sup{font-size:.22em;vertical-align:.9em;color:var(--vmc-brass-hi);font-family:var(--vmc-cond);-webkit-text-fill-color:var(--vmc-brass-hi);opacity:0}
.vmc-glint{position:absolute;inset:0;pointer-events:none;background:linear-gradient(105deg,transparent 40%,rgba(255,255,255,.9) 50%,transparent 60%);background-size:250% 100%;background-position:150% 0;-webkit-background-clip:text;background-clip:text;color:transparent;font:inherit;letter-spacing:inherit}
.vmc-sub{font-family:var(--vmc-cond);font-weight:600;font-size:clamp(13px,2.4vmin,24px);letter-spacing:.42em;color:var(--vmc-brass-tint);margin-top:2.4vmin;opacity:0}
.vmc-welcome{margin-top:6vmin;min-height:12vmin}
.vmc-hi{font-family:var(--vmc-serif);font-style:italic;font-size:clamp(20px,4.2vmin,42px);color:#fff}
.vmc-roleline{font-size:clamp(13px,2vmin,18px);color:rgba(244,239,227,.7);margin-top:1.2vmin;opacity:0}
.vmc-flash{position:absolute;inset:0;background:#fff;opacity:0;z-index:40;pointer-events:none}
.vmc-closing{position:absolute;inset:0;display:none;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:8vmin;z-index:6;background:#050806}
.vmc-closing.vmc-on{display:flex}
.vmc-big{font-family:var(--vmc-serif);font-weight:600;font-size:clamp(26px,5.6vmin,58px);color:#FBF3DF;line-height:1.2;max-width:22ch;opacity:0}
.vmc-small{font-family:var(--vmc-serif);font-style:italic;font-size:clamp(17px,3vmin,30px);color:var(--vmc-phos);margin-top:3vmin;opacity:0}
.vmc-remove{font-family:var(--vmc-crt);font-size:clamp(20px,3.4vmin,38px);color:var(--vmc-phos);margin-top:7vmin;opacity:0;text-shadow:0 0 8px rgba(242,197,114,.7)}
.vmc-bye{position:absolute;inset:0;z-index:45;display:none;align-items:center;justify-content:center;background:var(--vmc-night);text-align:center;padding:6vmin}
.vmc-bye.vmc-on{display:flex}
.vmc-bye-in{max-width:560px;opacity:0}
.vmc-mark{font-family:var(--vmc-serif);font-weight:600;font-size:22px;color:var(--vmc-brass-tint)}
.vmc-rule{width:56px;height:2px;background:var(--vmc-brass);margin:18px auto 26px}
.vmc-bye h2{font-family:var(--vmc-serif);font-weight:600;font-size:clamp(28px,5vw,44px);line-height:1.15;color:#fff}
.vmc-bye p{color:rgba(255,255,255,.72);font-size:15.5px;line-height:1.6;margin-top:14px}
.vmc-seal{display:inline-block;margin-top:22px;padding:8px 14px;border:1px solid rgba(201,162,39,.5);color:var(--vmc-brass-hi);font-size:13.5px}
.vmc-again{display:inline-block;margin-top:30px;background:var(--vmc-brass);border:1.5px solid var(--vmc-brass);color:var(--vmc-ink);padding:12px 22px;font-family:var(--vmc-cond);font-weight:700;font-size:14px;letter-spacing:.05em;text-decoration:none}
.vmc-again:hover{background:var(--vmc-brass-hi)}
.vmc-ctrls{position:absolute;top:calc(18px + env(safe-area-inset-top,0px));right:20px;z-index:60;display:flex;gap:8px}
.vmc-ctrls button{background:rgba(10,20,32,.6);color:rgba(255,255,255,.85);border:1px solid rgba(255,255,255,.25);padding:8px 14px;font-family:var(--vmc-cond);font-weight:600;font-size:13px;letter-spacing:.04em;backdrop-filter:blur(4px)}
.vmc-ctrls button:hover{border-color:var(--vmc-brass-hi);color:#fff}
.vmc-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
@media (prefers-reduced-motion:reduce){.vmc-floor,.vmc-glass::before,.vmc-caret{animation:none}}
</style>

<div id="vmcStage" class="<?php echo $vmcStartOn ? 'vmc-on' : ''; ?>" role="dialog" aria-modal="true" aria-label="VouchMorph Enterprise">
  <div class="vmc-sr" id="vmcLive" aria-live="polite"></div>
  <div class="vmc-room" id="vmcRoom"></div>
  <div class="vmc-viewport" id="vmcViewport">
    <div class="vmc-camera" id="vmcCamera">
      <svg viewBox="0 0 800 640" width="800" height="640" aria-hidden="true" focusable="false">
        <defs>
          <linearGradient id="vmcCaseG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F0EADB"/><stop offset=".6" stop-color="#E3DBC7"/><stop offset="1" stop-color="#CFC5AE"/></linearGradient>
          <linearGradient id="vmcBezelG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2a2a26"/><stop offset="1" stop-color="#171714"/></linearGradient>
          <radialGradient id="vmcGlassG" cx=".5" cy=".45" r=".7"><stop offset="0" stop-color="#16201a"/><stop offset="1" stop-color="#070a08"/></radialGradient>
          <clipPath id="vmcScrClip"><rect x="238" y="76" width="324" height="226" rx="24"/></clipPath>
          <pattern id="vmcKeys" width="26" height="15" patternUnits="userSpaceOnUse"><rect x="2" y="2" width="22" height="11" rx="2" fill="#D6CDB8" stroke="#B9AF97" stroke-width=".8"/></pattern>
          <linearGradient id="vmcDeskG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2a2017"/><stop offset=".08" stop-color="#1a140e"/><stop offset="1" stop-color="#070504"/></linearGradient>
          <radialGradient id="vmcPoolG" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#C9A227" stop-opacity=".22"/><stop offset="1" stop-color="#C9A227" stop-opacity="0"/></radialGradient>
        </defs>
        <rect x="-4000" y="560" width="8800" height="4000" fill="url(#vmcDeskG)"/>
        <line x1="-4000" y1="560" x2="4800" y2="560" stroke="#C9A227" stroke-opacity=".28"/>
        <ellipse cx="400" cy="600" rx="620" ry="120" fill="url(#vmcPoolG)"/>
        <rect x="190" y="30" width="420" height="330" rx="22" fill="url(#vmcCaseG)" stroke="#B3A98F" stroke-width="2"/>
        <rect x="218" y="58" width="364" height="262" rx="14" fill="url(#vmcBezelG)"/>
        <rect x="238" y="76" width="324" height="226" rx="24" fill="url(#vmcGlassG)"/>
        <g clip-path="url(#vmcScrClip)">
          <rect id="vmcScrGlow" x="238" y="76" width="324" height="226" fill="#F2C572" opacity="0"/>
          <rect id="vmcScrLine" x="238" y="187" width="324" height="4" fill="#FFF6DD" opacity="0"/>
          <circle id="vmcScrDot" cx="400" cy="189" r="3" fill="#FFF6DD" opacity="0"/>
          <path d="M252 92 Q330 80 420 86 L300 190 Q250 150 252 92Z" fill="#fff" opacity=".05"/>
        </g>
        <text x="400" y="343" text-anchor="middle" font-family="Source Serif 4, Georgia, serif" font-size="13" font-weight="600" letter-spacing="3" fill="#8a7240">VOUCHMORPH</text>
        <circle cx="556" cy="338" r="5" fill="#CFC6B1" stroke="#A89E86"/><circle cx="574" cy="338" r="5" fill="#CFC6B1" stroke="#A89E86"/>
        <circle id="vmcMonLed" cx="592" cy="338" r="3.5" fill="#3d3a2c"/>
        <rect x="350" y="358" width="100" height="20" fill="#D8D0BD" stroke="#B3A98F"/>
        <rect x="120" y="376" width="560" height="152" rx="12" fill="url(#vmcCaseG)" stroke="#B3A98F" stroke-width="2"/>
        <line x1="132" y1="394" x2="668" y2="394" stroke="#C8BEA6"/>
        <rect x="150" y="416" width="160" height="38" rx="2" fill="#0B1B2B"/>
        <text x="230" y="440" text-anchor="middle" font-family="Source Serif 4, Georgia, serif" font-size="14" font-weight="700" letter-spacing="2" fill="#E9C46A">VOUCHMORPH</text>
        <rect x="150" y="458" width="160" height="3" fill="#C9A227"/><rect x="150" y="463" width="160" height="3" fill="#9C7A3C"/><rect x="150" y="468" width="160" height="3" fill="#6E5326"/>
        <g stroke="#BDB39C" stroke-width="2"><line x1="152" y1="490" x2="310" y2="490"/><line x1="152" y1="498" x2="310" y2="498"/><line x1="152" y1="506" x2="310" y2="506"/></g>
        <rect x="380" y="418" width="240" height="70" rx="5" fill="#D9D1BE" stroke="#B3A98F"/>
        <rect x="410" y="440" width="170" height="10" rx="4" fill="#0d0d0c"/>
        <rect x="640" y="476" width="24" height="32" rx="3" fill="#CFC6B1" stroke="#A89E86"/>
        <circle cx="652" cy="464" r="3" fill="#6fe07a"/>
        <path d="M150 552 L650 552 L700 628 L100 628 Z" fill="url(#vmcCaseG)" stroke="#B3A98F" stroke-width="2"/>
        <path d="M170 562 L630 562 L668 618 L132 618 Z" fill="url(#vmcKeys)"/>
      </svg>
      <div class="vmc-disk-layer vmc-over" id="vmcDiskLayer">
        <div class="vmc-disk" id="vmcDisk" aria-hidden="true">
          <svg viewBox="0 0 150 156" width="150" height="156" focusable="false">
            <defs>
              <linearGradient id="vmcMetal" x1="0" x2="1"><stop offset="0" stop-color="#9aa3ad"/><stop offset=".45" stop-color="#e9edf1"/><stop offset="1" stop-color="#8e969f"/></linearGradient>
              <linearGradient id="vmcSheenG" x1="0" y1="0" x2="1" y2="1"><stop offset=".35" stop-color="#fff" stop-opacity="0"/><stop offset=".5" stop-color="#fff" stop-opacity=".22"/><stop offset=".65" stop-color="#fff" stop-opacity="0"/></linearGradient>
              <clipPath id="vmcDiskShape"><path d="M6 0 H138 L150 12 V150 a6 6 0 0 1 -6 6 H6 a6 6 0 0 1 -6 -6 V6 a6 6 0 0 1 6 -6Z"/></clipPath>
            </defs>
            <path d="M6 0 H138 L150 12 V150 a6 6 0 0 1 -6 6 H6 a6 6 0 0 1 -6 -6 V6 a6 6 0 0 1 6 -6Z" fill="#0B1B2B"/>
            <rect x="38" y="0" width="72" height="52" rx="2" fill="url(#vmcMetal)"/>
            <rect x="80" y="8" width="18" height="36" rx="2" fill="#2b3440"/>
            <rect x="14" y="66" width="122" height="84" rx="3" fill="#F4EFE3"/>
            <rect x="14" y="66" width="122" height="14" rx="3" fill="#9C7A3C"/>
            <text x="75" y="76.5" text-anchor="middle" font-family="Source Serif 4, Georgia, serif" font-weight="700" font-size="9.5" letter-spacing="1.4" fill="#FFF3CF">VOUCHMORPH</text>
            <text x="21" y="92" font-family="IBM Plex Sans Condensed, sans-serif" font-weight="600" font-size="6.2" letter-spacing=".6" fill="#4A5A6E">ENTERPRISE · DISK 1 OF 1</text>
            <text id="vmcDiskOrg" x="21" y="112" font-family="Caveat, cursive" font-size="14" fill="#1D3557"></text>
            <text id="vmcDiskName" x="21" y="129" font-family="Caveat, cursive" font-size="12" fill="#1D3557"></text>
            <line x1="21" y1="136" x2="128" y2="136" stroke="#D3DAD6"/>
            <rect x="6" y="140" width="9" height="9" fill="#050b12"/>
            <path d="M8 6 L16 6 L8 14Z" fill="#C9A227"/>
            <g clip-path="url(#vmcDiskShape)"><path id="vmcSheen" d="M0 0 H150 V156 H0Z" fill="url(#vmcSheenG)"/></g>
          </svg>
        </div>
      </div>
      <svg class="vmc-front" viewBox="0 0 800 640" width="800" height="640" aria-hidden="true" focusable="false">
        <path fill-rule="evenodd" fill="#D9D1BE" stroke="#B3A98F" d="M385 418 H615 a5 5 0 0 1 5 5 V483 a5 5 0 0 1 -5 5 H385 a5 5 0 0 1 -5 -5 V423 a5 5 0 0 1 5 -5Z M414 440 H576 a4 4 0 0 1 4 4 V446 a4 4 0 0 1 -4 4 H414 a4 4 0 0 1 -4 -4 V444 a4 4 0 0 1 4 -4Z"/>
        <path d="M410 452 H580" stroke="#B3A98F" stroke-width="1"/>
        <rect x="556" y="462" width="26" height="12" rx="2" fill="#CFC6B1" stroke="#A89E86"/>
        <rect id="vmcDriveLed" x="592" y="465" width="14" height="6" rx="1" fill="#2d3a26"/>
        <text x="392" y="480" font-family="IBM Plex Sans Condensed, sans-serif" font-size="8" font-weight="600" fill="#8a8068" letter-spacing=".5">3½″  DRIVE A:</text>
      </svg>
    </div>
  </div>

  <div class="vmc-crt" id="vmcCrt">
    <div class="vmc-glass" id="vmcGlass">
      <div class="vmc-boot" id="vmcBoot"></div>
      <div class="vmc-bars" id="vmcBars"></div>
      <div class="vmc-title" id="vmcTitle">
        <div class="vmc-floor"></div><div class="vmc-sun" id="vmcSun"></div>
        <div class="vmc-card">
          <div class="vmc-presents" id="vmcPresents">VOUCHMORPH (PTY) LTD PRESENTS</div>
          <div class="vmc-stripes" id="vmcStripes"><i style="background:#F3D48A"></i><i style="background:#C9A227"></i><i style="background:#9C7A3C"></i><i style="background:#6E5326"></i></div>
          <div class="vmc-logo" id="vmcLogo"><span class="vmc-glint" id="vmcGlint" aria-hidden="true">VOUCHMORPH</span></div>
          <div class="vmc-sub" id="vmcSub">ENTERPRISE COMMAND CENTRE</div>
          <div class="vmc-welcome"><div class="vmc-hi" id="vmcHi"></div><div class="vmc-roleline" id="vmcRoleLine"></div></div>
        </div>
      </div>
      <div class="vmc-report" id="vmcReport"></div>
      <div class="vmc-closing" id="vmcClosing">
        <div class="vmc-big" id="vmcBig">Every action you took today is on the record.</div>
        <div class="vmc-small" id="vmcSmall"></div>
        <div class="vmc-remove" id="vmcRemove">PLEASE REMOVE DISK FROM DRIVE A:</div>
      </div>
      <canvas class="vmc-noise" id="vmcNoise" width="160" height="90"></canvas>
      <div class="vmc-track" id="vmcTrack"></div>
      <div class="vmc-osd vmc-osd-tl" id="vmcOsdTL">PLAY ▶</div>
      <div class="vmc-osd vmc-osd-br" id="vmcOsdBR">SP 0:00:00</div>
    </div>
  </div>

  <div class="vmc-flash" id="vmcFlash"></div>

  <div class="vmc-bye" id="vmcBye">
    <div class="vmc-bye-in" id="vmcByeIn">
      <div class="vmc-mark">VOUCHMORPH<sup style="font-size:.5em">™</sup> Enterprise</div>
      <div class="vmc-rule"></div>
      <h2>You're signed out.</h2>
      <p id="vmcByeLine"></p>
      <div class="vmc-seal" id="vmcByeSeal"></div>
      <p style="font-size:14px">Using a shared computer? Close this browser window too.</p>
      <a class="vmc-again" id="vmcAgain" href="<?php echo htmlspecialchars($vmcConfig['loginUrl'], ENT_QUOTES, 'UTF-8'); ?>">Sign in again</a>
    </div>
  </div>

  <div class="vmc-ctrls">
    <button type="button" id="vmcSound" aria-pressed="true">Sound: on</button>
    <button type="button" id="vmcSkip">Skip intro</button>
  </div>
</div>
<?php if ($vmcStartOn): ?><script>document.documentElement.classList.add('vmc-lock');</script><?php endif; ?>
<script type="application/json" id="vmcConfig"><?php echo json_encode($vmcConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
<script>
(() => {
'use strict';
const $ = id => document.getElementById(id);
const stage = $('vmcStage'); if (!stage) return;
const CFG = JSON.parse($('vmcConfig').textContent || '{}');
const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------- scene sizing: computer at one third in the wide shot ---------- */
const SIZE = 1/3, Z = 1/SIZE;
function fit(){ const s = Math.min(innerWidth/860, innerHeight/700) * SIZE; $('vmcViewport').style.setProperty('--vmc-fit', s.toFixed(4)); }
addEventListener('resize', fit); fit();
const cam = (k, px, py) => `translate(${400 - k*px}px, ${320 - k*py}px) scale(${k})`;
const WIDE = cam(1, 400, 330), HOVER = cam(2.0*Z, 495, 522), BAY = cam(2.5*Z, 495, 452), SCREEN = cam(3.35*Z, 400, 189);

const pad = n => String(n).padStart(2, '0');
const say = t => { $('vmcLive').textContent = t; };

/* ---------- sound: synthesised; remembers the person's choice ---------- */
let ac = null;
let soundOn = localStorage.getItem('vmc_sound') !== 'off';
function ctx(){ if (!ac) { try { ac = new (window.AudioContext || window.webkitAudioContext)(); } catch(e){ ac = null; } } if (ac && ac.state === 'suspended') ac.resume().catch(()=>{}); return ac; }
function soundLabel(){ const b = $('vmcSound'); const blocked = soundOn && ac && ac.state !== 'running';
  b.textContent = !soundOn ? 'Sound: off' : (blocked ? 'Tap for sound' : 'Sound: on'); b.setAttribute('aria-pressed', String(soundOn)); }
$('vmcSound').onclick = () => {
  // Off -> on. On but blocked by the browser -> unlock. On and playing -> off.
  if (!soundOn) { soundOn = true; ctx(); }
  else if (!ac || ac.state !== 'running') { ctx(); }
  else { soundOn = false; }
  localStorage.setItem('vmc_sound', soundOn ? 'on' : 'off'); setTimeout(soundLabel, 60);
};
// Browsers block sound until the person interacts; the first tap or key unlocks it.
['pointerdown','keydown'].forEach(ev => addEventListener(ev, () => { if (soundOn) { ctx(); setTimeout(soundLabel, 60); } }, {once:true, capture:true}));
function tone(freq, dur, {type='sine', gain=.05, at=0, glide=null}={}){
  if (!soundOn || !ac || ac.state !== 'running') return; const c = ac, t = c.currentTime + at;
  const o = c.createOscillator(), g = c.createGain(); o.type = type; o.frequency.setValueAtTime(freq, t);
  if (glide) o.frequency.exponentialRampToValueAtTime(glide, t + dur);
  g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(gain, t + .01); g.gain.exponentialRampToValueAtTime(.0001, t + dur);
  o.connect(g).connect(c.destination); o.start(t); o.stop(t + dur + .05);
}
function noise(dur, {gain=.08, at=0, lo=400, hi=4000}={}){
  if (!soundOn || !ac || ac.state !== 'running') return; const c = ac, t = c.currentTime + at;
  const b = c.createBuffer(1, Math.ceil(c.sampleRate*dur), c.sampleRate), d = b.getChannelData(0);
  for (let i=0;i<d.length;i++) d[i] = (Math.random()*2-1) * (1 - i/d.length);
  const s = c.createBufferSource(); s.buffer = b; const f = c.createBiquadFilter(); f.type='bandpass'; f.frequency.value = Math.sqrt(lo*hi); f.Q.value = .8;
  const g = c.createGain(); g.gain.value = gain; s.connect(f).connect(g).connect(c.destination); s.start(t);
}
const sfx = {
  clack(){ noise(.07,{gain:.35,lo:1200,hi:5000}); tone(95,.18,{gain:.25,glide:50}); noise(.05,{gain:.2,at:.09,lo:800,hi:3000}); },
  seek(){ for (let i=0;i<9;i++) tone(130+(i%3)*18,.03,{type:'square',gain:.035,at:.12+i*.085}); tone(48,1.3,{type:'sawtooth',gain:.018,at:.05}); },
  crtOn(){ tone(58,.35,{gain:.3,glide:30}); noise(.25,{gain:.12,lo:3000,hi:9000}); tone(11800,1.1,{gain:.004,at:.1}); },
  beep(){ tone(880,.16,{type:'square',gain:.04}); },
  tick(){ tone(1800+Math.random()*400,.012,{type:'square',gain:.008}); },
  bars(){ tone(1000,.7,{gain:.045}); },
  sting(){ [261.6,329.6,392,523.3,659.3].forEach((f,i)=>{ tone(f,1.6-i*.12,{type:'triangle',gain:.05,at:i*.11}); tone(f*1.005,1.6-i*.12,{type:'sawtooth',gain:.012,at:i*.11}); }); tone(130.8,2.2,{type:'triangle',gain:.06,at:.5}); tone(523.3,2.0,{gain:.035,at:.55}); },
  stop(){ noise(.06,{gain:.3,lo:600,hi:3000}); tone(70,.2,{gain:.2,glide:40}); },
  resolve(){ [659.3,523.3,392,329.6].forEach((f,i)=>tone(f,1.4,{type:'triangle',gain:.04,at:i*.16})); tone(261.6,2.4,{gain:.05,at:.64}); },
  crtOff(){ tone(420,.5,{gain:.08,glide:40}); noise(.12,{gain:.08,lo:2000,hi:8000}); },
  eject(){ noise(.05,{gain:.3,lo:1500,hi:6000}); tone(160,.08,{type:'square',gain:.05,at:.02}); noise(.35,{gain:.06,at:.08,lo:500,hi:2500}); },
};

/* ---------- skippable timeline ---------- */
let run = null;
class Skipped extends Error {}
function wait(ms){ const r = run; return new Promise((res, rej) => { if (r.skipped) return rej(new Skipped()); const t = setTimeout(res, ms); r.timers.push(() => { clearTimeout(t); rej(new Skipped()); }); }); }
function anim(el, kf, opt){ const a = el.animate(kf, Object.assign({fill:'forwards'}, opt)); run.anims.push(a); return a; }
async function typeInto(el, text, cps=90, tick=true){ for (const ch of text){ if (run.skipped) throw new Skipped(); el.insertAdjacentText('beforeend', ch); if (tick && ch !== ' ' && Math.random() < .5) sfx.tick(); await wait(1000/cps); } }
function newRun(){ if (run) cancel(); run = { skipped:false, timers:[], anims:[] }; }
function cancel(){ if (!run) return; run.skipped = true; run.timers.forEach(f => f()); run.anims.forEach(a => { try { a.cancel(); } catch(e){} }); }

let noiseRaf = 0;
function noiseOn(on){ const c = $('vmcNoise'), g = c.getContext('2d'); cancelAnimationFrame(noiseRaf); if (!on){ c.style.opacity = 0; return; }
  const img = g.createImageData(c.width, c.height); const draw = () => { for (let i=0;i<img.data.length;i+=4){ const v = Math.random()*255|0; img.data[i]=img.data[i+1]=img.data[i+2]=v; img.data[i+3]=255; } g.putImageData(img,0,0); noiseRaf = requestAnimationFrame(draw); }; draw(); }
let counterIv = 0;
function counter(on){ clearInterval(counterIv); if (!on) return; let n = 0; const el = $('vmcOsdBR'); const upd = () => { el.textContent = 'SP 0:00:' + pad(n++); }; upd(); counterIv = setInterval(upd, 1000); }

function labelDisk(p){
  if (!p) return;
  // Written by hand, so the short form: the name up to its first comma.
  const shortOrg = String(p.org || '').split(',')[0].trim();
  const org = $('vmcDiskOrg'); org.textContent = shortOrg;
  org.removeAttribute('textLength');
  // Long organisation names are squeezed to fit the label rather than spilling off the disk.
  requestAnimationFrame(() => { try { if (org.getComputedTextLength() > 108) { org.setAttribute('textLength', '108'); org.setAttribute('lengthAdjust', 'spacingAndGlyphs'); } } catch(e){} });
  $('vmcDiskName').textContent = (p.initial || p.name || '') + ' · ' + new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'2-digit',year:'2-digit'});
}
function resetScene(){
  $('vmcCamera').getAnimations().forEach(a=>a.cancel()); $('vmcCamera').style.transform = WIDE;
  const disk = $('vmcDisk'); disk.getAnimations().forEach(a=>a.cancel()); disk.style.transform = 'translate(300px, 460px) rotate(18deg)'; disk.style.opacity = 1;
  $('vmcDiskLayer').classList.add('vmc-over');
  ['vmcScrGlow','vmcScrLine','vmcScrDot'].forEach(id => { const e=$(id); e.getAnimations().forEach(a=>a.cancel()); e.setAttribute('opacity',0); });
  $('vmcDriveLed').setAttribute('fill','#2d3a26'); $('vmcMonLed').setAttribute('fill','#3d3a2c');
  $('vmcCrt').classList.remove('vmc-on'); $('vmcCrt').style.opacity = 1; $('vmcCrt').style.background=''; $('vmcGlass').style.background=''; $('vmcGlass').getAnimations().forEach(a=>a.cancel());
  $('vmcBoot').textContent = ''; $('vmcReport').textContent = '';
  ['vmcBars','vmcTitle','vmcClosing','vmcBye'].forEach(id => $(id).classList.remove('vmc-on'));
  ['vmcOsdTL','vmcOsdBR'].forEach(id => $(id).classList.remove('vmc-on')); counter(false); noiseOn(false);
  $('vmcFlash').style.opacity = 0; stage.style.background = '';
  [$('vmcRoom'), $('vmcViewport')].forEach(e => { e.getAnimations().forEach(a=>a.cancel()); e.style.opacity = 1; });
}
function openStage(skipLabel){ $('vmcSound').style.display = ''; stage.classList.add('vmc-on'); document.documentElement.classList.add('vmc-lock'); $('vmcSkip').textContent = skipLabel; $('vmcSkip').style.display = ''; soundLabel(); $('vmcSkip').focus({preventScroll:true}); }
function closeStage(){ stage.classList.remove('vmc-on'); document.documentElement.classList.remove('vmc-lock'); resetScene(); }
addEventListener('keydown', e => { if (e.key === 'Escape' && stage.classList.contains('vmc-on') && $('vmcSkip').style.display !== 'none') $('vmcSkip').click(); });
// Wait briefly for the handwriting and CRT fonts so the disk label never flashes in a fallback face.
function fontsReady(){ return Promise.race([document.fonts ? document.fonts.load('600 14px Caveat').then(() => document.fonts.load('20px VT323')) : Promise.resolve(), new Promise(r => setTimeout(r, 700))]).catch(()=>{}); }

/* =================== SIGN-IN =================== */
async function playSignIn(w){
  newRun(); resetScene(); labelDisk(w); openStage('Skip intro');
  say('Signing in to VouchMorph Enterprise as ' + w.name + ', ' + w.org + '.');
  $('vmcSkip').onclick = () => { cancel(); finishSignIn(false); };
  if (reduce){ finishSignIn(false); return; }
  try {
    anim($('vmcRoom'), [{opacity:0},{opacity:1}], {duration:700});
    await Promise.all([fontsReady(), wait(350)]);
    anim($('vmcCamera'), [{transform:WIDE},{transform:HOVER}], {duration:1500, easing:'cubic-bezier(.65,0,.25,1)'});
    await wait(450);
    anim($('vmcDisk'), [{transform:'translate(300px, 460px) rotate(18deg) scale(1.1)'},{transform:'translate(0px, 44px) rotate(-2deg) scale(1)', offset:.8},{transform:'translate(0px, 40px) rotate(0deg) scale(1)'}], {duration:1250, easing:'cubic-bezier(.2,.7,.2,1)'});
    anim($('vmcSheen'), [{transform:'translateX(-150px)'},{transform:'translateX(150px)'}], {duration:1400, delay:800, easing:'ease-in-out'});
    await wait(1900);
    anim($('vmcCamera'), [{transform:HOVER},{transform:BAY}], {duration:620, easing:'cubic-bezier(.5,0,.3,1)'});
    anim($('vmcDisk'), [{transform:'translate(0px, 40px) rotateX(0deg)'},{transform:'translate(0px, 4px) rotateX(86deg)'}], {duration:520, easing:'cubic-bezier(.5,0,.3,1)'});
    await wait(520);
    $('vmcDiskLayer').classList.remove('vmc-over');
    anim($('vmcDisk'), [{transform:'translate(0px, 4px) rotateX(86deg) translateY(0px)'},{transform:'translate(0px, 0px) rotateX(86deg) translateY(-170px)'}], {duration:380, easing:'cubic-bezier(.6,0,.9,.6)'});
    await wait(360);
    $('vmcDisk').style.opacity = 0; sfx.clack();
    anim($('vmcCamera'), [{transform:BAY},{transform:cam(2.52*Z,495,449)},{transform:BAY}], {duration:160});
    $('vmcDriveLed').setAttribute('fill','#9dff7a'); anim($('vmcDriveLed'), [{opacity:1},{opacity:.35},{opacity:1}], {duration:180, iterations:8});
    sfx.seek();
    await wait(1300);
    $('vmcDriveLed').setAttribute('fill','#2d3a26');
    anim($('vmcCamera'), [{transform:BAY},{transform:WIDE, offset:.45},{transform:SCREEN}], {duration:2100, easing:'cubic-bezier(.55,0,.2,1)'});
    await wait(950);
    sfx.crtOn(); $('vmcMonLed').setAttribute('fill','#9dff7a');
    $('vmcScrLine').style.transformOrigin = '400px 189px';
    anim($('vmcScrLine'), [{opacity:0, transform:'scaleX(0)'},{opacity:1, transform:'scaleX(1)', offset:.35},{opacity:1, transform:'scaleX(1)'}], {duration:320, easing:'ease-out'});
    await wait(260);
    anim($('vmcScrGlow'), [{opacity:0},{opacity:.9},{opacity:.12}], {duration:500, easing:'ease-out'});
    await wait(760);
    $('vmcCrt').classList.add('vmc-on'); anim($('vmcCrt'), [{opacity:0},{opacity:1}], {duration:220});
    await wait(250);
    sfx.beep();
    await bootText(w);
    await wait(500);
    await tapeIntro(w);
    await wait(1200);
    finishSignIn(true);
  } catch (e) { if (!(e instanceof Skipped)) { console.error(e); finishSignIn(false); } }
}
async function bootText(w){
  const b = $('vmcBoot'); b.innerHTML = '';
  const caret = document.createElement('span'); caret.className = 'vmc-caret';
  const put = async (t, cps) => { caret.remove(); await typeInto(b, t, cps); b.appendChild(caret); };
  b.insertAdjacentText('beforeend', 'VOUCHMORPH BIOS v26.09   (C) 2026 VouchMorph (Pty) Ltd\n\n'); b.appendChild(caret);
  await wait(260);
  await put('CPU  VM-8026 ................ ', 140); await wait(160); await put('OK\n', 60);
  await put('MEMORY TEST  ', 140);
  for (let k=64;k<=640;k+=64){ caret.remove(); b.insertAdjacentText('beforeend', String(k).padStart(3,' ') + 'K'); b.appendChild(caret); await wait(55); if (k<640){ caret.remove(); b.lastChild.remove(); b.appendChild(caret); } }
  await put(' OK\n', 60);
  await put('SECURE ELEMENT ............. ', 140); await wait(120); await put('SEALED\n\n', 60);
  await put('READING DRIVE A:  ENTERPRISE DISK 1 OF 1\n', 110);
  await put('  Organization   ' + w.org + '\n', 120);
  await put('  Operator       ' + w.name + '\n', 120);
  await put('  Role           ' + w.role + '\n\n', 120);
  await put('VERIFYING AUDIT CHAIN ...... ', 140); await wait(300);
  if (w.audit === 'ATTENTION') { caret.remove(); const s = document.createElement('span'); s.className = 'vmc-attn'; b.appendChild(s); await typeInto(s, 'ATTENTION - REPORT TO COMPLIANCE', 60); b.insertAdjacentText('beforeend','\n'); b.appendChild(caret); }
  else { await put((w.audit || 'ONLINE') + '\n', 60); }
  await put('LOADING COMMAND.CTR  ', 140);
  for (let i=0;i<20;i++){ caret.remove(); b.insertAdjacentText('beforeend','█'); b.appendChild(caret); if (i%3===0) sfx.tick(); await wait(34); }
  await put('  100%', 60);
}
async function tapeIntro(w){
  $('vmcBoot').textContent = '';
  $('vmcOsdTL').textContent = 'PLAY ▶'; $('vmcOsdTL').classList.add('vmc-on'); $('vmcOsdBR').classList.add('vmc-on'); counter(true);
  noiseOn(true); anim($('vmcNoise'), [{opacity:.55},{opacity:.12}], {duration:600});
  anim($('vmcTrack'), [{top:'-20%', opacity:.9},{top:'110%', opacity:.6}], {duration:650, easing:'linear'});
  await wait(420);
  const bars = $('vmcBars'); bars.innerHTML = '';
  [['#c0c0c0','#c0c000','#00c0c0','#00c000','#c000c0','#c00000','#0000c0'],['#0000c0','#131313','#c000c0','#131313','#00c0c0','#131313','#c0c0c0'],['#00214c','#fff','#32006a','#131313','#090909','#131313','#1d1d1d']]
    .forEach(r => { const row = document.createElement('div'); row.className='vmc-row'; r.forEach(c => { const s=document.createElement('span'); s.style.background=c; row.appendChild(s); }); bars.appendChild(row); });
  bars.classList.add('vmc-on'); sfx.bars();
  await wait(760);
  bars.classList.remove('vmc-on'); anim($('vmcNoise'), [{opacity:.4},{opacity:0}], {duration:350});
  await wait(180); noiseOn(false); $('vmcOsdTL').classList.remove('vmc-on');
  $('vmcTitle').classList.add('vmc-on');
  const logo = $('vmcLogo'), glint = $('vmcGlint'); logo.querySelectorAll('.vmc-l,sup').forEach(e=>e.remove());
  [...'VOUCHMORPH'].forEach(ch => { const s=document.createElement('span'); s.className='vmc-l'; s.textContent=ch; logo.insertBefore(s, glint); });
  const tm = document.createElement('sup'); tm.textContent='™'; logo.insertBefore(tm, glint);
  $('vmcHi').textContent = ''; $('vmcRoleLine').textContent = w.org + '  ·  ' + w.role;
  ['vmcPresents','vmcSub','vmcRoleLine'].forEach(id => $(id).style.opacity = 0);
  anim($('vmcSun'), [{opacity:0, transform:'translateX(-50%) translateY(6vmin)'},{opacity:1, transform:'translateX(-50%) translateY(0)'}], {duration:1400, easing:'ease-out'});
  anim($('vmcPresents'), [{opacity:0, letterSpacing:'.6em'},{opacity:1, letterSpacing:'.34em'}], {duration:900, easing:'ease-out'});
  await wait(500);
  $('vmcStripes').querySelectorAll('i').forEach((i,n) => anim(i, [{transform:'scaleX(0)'},{transform:'scaleX(1)'}], {duration:520, delay:n*90, easing:'cubic-bezier(.2,.8,.2,1)'}));
  await wait(300);
  sfx.sting();
  logo.querySelectorAll('.vmc-l').forEach((l,n) => anim(l, [{opacity:0, transform:'translateY(-0.5em) rotateX(80deg)'},{opacity:1, transform:'translateY(0) rotateX(0)'}], {duration:520, delay:n*55, easing:'cubic-bezier(.2,.9,.25,1.2)'}));
  anim(tm, [{opacity:0},{opacity:1}], {duration:400, delay:650});
  await wait(900);
  anim(glint, [{backgroundPosition:'150% 0'},{backgroundPosition:'-60% 0'}], {duration:900, easing:'ease-in-out'});
  anim($('vmcSub'), [{opacity:0, letterSpacing:'.7em'},{opacity:1, letterSpacing:'.42em'}], {duration:900, easing:'ease-out'});
  await wait(900);
  await typeInto($('vmcHi'), 'Welcome back, ' + w.first + '.', 30, false);
  anim($('vmcRoleLine'), [{opacity:0},{opacity:1}], {duration:600});
}
function finishSignIn(animated){
  const done = () => { cancel(); closeStage(); say('Signed in.'); const h = document.querySelector('main, .stage-view.active, h1'); if (h) { h.setAttribute('tabindex','-1'); h.focus({preventScroll:true}); } };
  if (!animated || reduce){ done(); return; }
  counter(false); $('vmcOsdBR').classList.remove('vmc-on');
  $('vmcGlass').animate([{transform:'scale(1)', filter:'brightness(1)'},{transform:'scale(1.9)', filter:'brightness(2.2)'}], {duration:420, easing:'cubic-bezier(.7,0,.9,.4)', fill:'forwards'});
  stage.animate([{opacity:1},{opacity:1, offset:.6},{opacity:0}], {duration:700, fill:'forwards'});
  $('vmcFlash').animate([{opacity:0},{opacity:.85, offset:.7},{opacity:0}], {duration:700, fill:'forwards'});
  setTimeout(() => { done(); stage.getAnimations().forEach(a=>a.cancel()); }, 700);
}

/* =================== SIGN-OUT =================== */
function pageLayers(){ return [...document.body.children].filter(el => el !== stage && !['SCRIPT','STYLE','LINK'].includes(el.tagName)); }
async function playSignOut(summaryPromise, overPage, fallbackUrl){
  newRun(); resetScene(); labelDisk(CFG.user || CFG.outro);
  const bye = s => showBye(s, false);
  let summary = null;
  $('vmcSkip').onclick = async () => { cancel(); if (!summary) summary = await summaryPromise.catch(() => null); if (!summary && fallbackUrl) { location.href = fallbackUrl; return; } bye(summary); };
  openStage('Skip');
  say('Signing out.');
  try {
    stage.style.background = 'transparent';
    [$('vmcRoom'), $('vmcViewport')].forEach(e => e.style.opacity = 0);
    $('vmcCrt').classList.add('vmc-on'); $('vmcCrt').style.background = 'transparent'; $('vmcGlass').style.background = overPage ? 'transparent' : '#050806';
    $('vmcOsdTL').textContent = '■ STOP'; $('vmcOsdTL').classList.add('vmc-on'); sfx.stop();
    if (overPage && !reduce) pageLayers().forEach(el => anim(el, [{filter:'none', transform:'none'},{filter:'saturate(.3) contrast(1.3) brightness(.9)', transform:'translateX(-6px) skewX(-2deg)', offset:.2},{filter:'saturate(0) brightness(.25)', transform:'translateX(3px)', offset:.6},{filter:'brightness(0)', transform:'none'}], {duration:900, easing:'steps(6)'}));
    noiseOn(true); anim($('vmcNoise'), [{opacity:0},{opacity:.14},{opacity:0}], {duration:900});
    const [s] = await Promise.all([summaryPromise.catch(() => null), wait(900)]);
    summary = s;
    if (!summary) { if (fallbackUrl) { location.href = fallbackUrl; return; } }
    noiseOn(false);
    stage.style.background = ''; $('vmcCrt').style.background = ''; $('vmcGlass').style.background = '';
    $('vmcOsdTL').classList.remove('vmc-on');
    if (reduce) { bye(summary); return; }

    const rep = $('vmcReport'); rep.textContent = ''; sfx.beep();
    await typeInto(rep, 'SESSION REPORT   ' + summary.date + '\n\n', 90);
    await typeInto(rep, 'Operator ........ ' + summary.name + '\n', 110);
    await typeInto(rep, 'Organization .... ' + summary.org + '\n', 110);
    await typeInto(rep, 'Signed in ....... ' + (summary.signed_in || '--:--') + '\n', 110);
    await typeInto(rep, 'Signed out ...... ' + summary.signed_out + (summary.duration ? '   (' + summary.duration + ')' : '') + '\n', 110);
    await typeInto(rep, 'Actions logged .. ' + summary.actions + '\n', 110);
    await typeInto(rep, 'Waiting on you .. ' + (summary.waiting === null || summary.waiting === undefined ? 'n/a' : (summary.waiting === 0 ? 'none' : String(summary.waiting))) + '\n\n', 110);
    await typeInto(rep, 'AUDIT TRAIL ..... ', 110); await wait(350); await typeInto(rep, summary.sealed ? 'SEALED' : 'CHECK LOG', 20);
    await wait(1100);

    rep.textContent = '';
    $('vmcClosing').classList.add('vmc-on'); $('vmcSmall').textContent = 'Thank you, ' + summary.first + '. VouchMorph is standing by.';
    ['vmcBig','vmcSmall','vmcRemove'].forEach(id => $(id).style.opacity = 0);
    sfx.resolve();
    anim($('vmcBig'), [{opacity:0, transform:'translateY(10px)', filter:'blur(4px)'},{opacity:1, transform:'none', filter:'blur(0)'}], {duration:1100, easing:'ease-out'});
    await wait(1300);
    anim($('vmcSmall'), [{opacity:0},{opacity:1}], {duration:900});
    await wait(1800);
    anim($('vmcRemove'), [{opacity:1},{opacity:0},{opacity:1}], {duration:700, iterations:2, easing:'steps(1)'});
    await wait(1500);

    [$('vmcRoom'), $('vmcViewport')].forEach(e => e.style.opacity = 1);
    $('vmcCamera').style.transform = SCREEN;
    $('vmcDisk').style.transform = 'translate(0px, 0px) rotateX(86deg) translateY(-170px)'; $('vmcDisk').style.opacity = 0;
    $('vmcScrGlow').setAttribute('opacity', .12); $('vmcMonLed').setAttribute('fill','#9dff7a');
    anim($('vmcCrt'), [{opacity:1},{opacity:0}], {duration:500});
    anim($('vmcCamera'), [{transform:SCREEN},{transform:WIDE}], {duration:1500, easing:'cubic-bezier(.5,0,.2,1)'});
    await wait(1600); $('vmcCrt').classList.remove('vmc-on');
    sfx.crtOff();
    anim($('vmcScrGlow'), [{opacity:.12},{opacity:.9, offset:.15},{opacity:0}], {duration:350});
    $('vmcScrLine').style.transformOrigin = '400px 189px';
    anim($('vmcScrLine'), [{opacity:1, transform:'scaleX(1)'},{opacity:1, transform:'scaleX(.01)', offset:.7},{opacity:0, transform:'scaleX(0)'}], {duration:450, easing:'ease-in'});
    await wait(420);
    anim($('vmcScrDot'), [{opacity:1},{opacity:0}], {duration:900, easing:'ease-out'});
    $('vmcMonLed').setAttribute('fill','#3d3a2c');
    await wait(700);
    anim($('vmcCamera'), [{transform:WIDE},{transform:cam(1.7*Z,495,470)}], {duration:900, easing:'cubic-bezier(.5,0,.2,1)'});
    await wait(900);
    sfx.eject(); $('vmcDriveLed').setAttribute('fill','#9dff7a'); $('vmcDisk').style.opacity = 1;
    $('vmcDiskLayer').classList.remove('vmc-over');
    anim($('vmcDisk'), [{transform:'translate(0px, 0px) rotateX(86deg) translateY(-170px)'},{transform:'translate(0px, 4px) rotateX(86deg) translateY(0px)'}], {duration:320, easing:'cubic-bezier(.2,.8,.3,1)'});
    await wait(320); $('vmcDiskLayer').classList.add('vmc-over'); $('vmcDriveLed').setAttribute('fill','#2d3a26');
    anim($('vmcDisk'), [{transform:'translate(0px, 4px) rotateX(86deg)'},{transform:'translate(0px, 40px) rotateX(0deg)'}], {duration:520, easing:'cubic-bezier(.3,0,.2,1)'});
    await wait(900);
    anim($('vmcDisk'), [{transform:'translate(0px, 40px) rotate(0deg)'},{transform:'translate(-260px, 520px) rotate(-24deg)'}], {duration:1100, easing:'cubic-bezier(.5,0,.8,.4)'});
    await wait(700);
    showBye(summary, true);
  } catch (e) { if (!(e instanceof Skipped)) { console.error(e); if (summary) showBye(summary, false); else if (fallbackUrl) location.href = fallbackUrl; } }
}
function showBye(s, animated){
  cancel();
  s = s || {};
  $('vmcBye').classList.add('vmc-on');
  $('vmcByeLine').textContent = s.signed_out ? 'Your session closed at ' + s.signed_out + '. ' + (s.sealed ? 'Every action you took is sealed in the audit trail.' : 'Your sign-out could not be written to the audit trail; it has been logged for review.') : 'Your session has been closed.';
  $('vmcByeSeal').textContent = (s.actions !== undefined ? s.actions + ' action' + (s.actions === 1 ? '' : 's') + ' recorded' : 'Session closed') + (s.sealed ? ' · sign-out recorded' : '');
  if (s.login_url) $('vmcAgain').href = s.login_url;
  const inEl = $('vmcByeIn');
  if (animated && !reduce){ $('vmcBye').animate([{opacity:0},{opacity:1}], {duration:900, fill:'forwards'}); inEl.animate([{opacity:0, transform:'translateY(8px)'},{opacity:1, transform:'none'}], {duration:900, delay:400, fill:'forwards', easing:'ease-out'}); }
  else { inEl.style.opacity = 1; }
  $('vmcSkip').style.display = 'none'; $('vmcSound').style.display = 'none'; say("You're signed out.");
  $('vmcAgain').focus({preventScroll:true});
  try { history.replaceState(null, '', $('vmcAgain').getAttribute('href')); } catch(e){}
}

/* =================== wiring =================== */
if (CFG.mode === 'page' && CFG.intro) {
  if (!reduce) ctx();
  playSignIn(CFG.intro);
}
if (CFG.mode === 'outro-page' && CFG.outro) {
  labelDisk(CFG.outro);
  playSignOut(Promise.resolve(CFG.outro), false, null);
}
// Intercept sign-out links: play the sequence while logout.php runs.
if (CFG.mode === 'page' && CFG.user && CFG.token) {
  document.addEventListener('click', e => {
    const a = e.target.closest && e.target.closest('a[href*="logout.php"]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault(); ctx();
    const url = a.href;
    const summary = fetch(url, { method:'POST', credentials:'same-origin', headers:{ 'Accept':'application/json', 'Content-Type':'application/x-www-form-urlencoded' }, body:'token=' + encodeURIComponent(CFG.token) })
      .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
      .then(j => { if (!j || !j.ok) throw new Error('logout failed'); return j.summary; });
    playSignOut(summary, true, url);
  }, true);
}
})();
</script>
