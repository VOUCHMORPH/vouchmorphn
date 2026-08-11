<?php
// index.php - VouchMorph Landing Page
// Visual system matches login.php: serif editorial, brass/ink palette,
// frame-mat motif, doodle field, script accents. No session needed here.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph™ — Money, Without Walls</title>
<meta name="description" content="VouchMorph connects every wallet to every cash point. Send from any MNO to any MNO. Cash out at any ATM. Deposit vouchers instantly. Financial freedom without boundaries.">
<meta name="keywords" content="mobile money, cross-network payments, ATM cashout, wallet interoperability, fintech Botswana">
<meta name="author" content="VouchMorph">
<link rel="icon" type="image/x-icon" href="/favicon.ico">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&family=Alex+Brush&display=swap" rel="stylesheet">

<style>
/* ============================================================
   VOUCHMORPH — LANDING PAGE
   Same brand system as sign-in: serif display, brass/ink editorial
   palette, frame-mat motif, doodle field, script accents. The
   signature element here is "The Ledger" — the before/after
   comparison recast as a bank journal, since the subject is
   literally about reconciling accounts that used to be closed to
   each other.
   ============================================================ */
:root {
    --paper:        #FBF9F5;
    --panel:        #FFFFFF;
    --ink-900:      #16232E;
    --ink-700:      #24384A;
    --ink-500:      #5B6B78;
    --ink-300:      #9AA6AC;
    --line:         #E4DFD3;
    --line-strong:  #CFC7B4;
    --brass:        #B4884A;
    --brass-deep:   #8A6530;
    --brass-tint:   #F6EFDF;
    --mint:         #6E9A85;
    --mint-tint:    #EAF1EC;
    --danger:       #b3261e;
    --f-display: 'Source Serif 4', 'IBM Plex Sans', serif;
    --f-body: 'IBM Plex Sans', sans-serif;
    --f-cond: 'IBM Plex Sans Condensed', sans-serif;
    --f-mono: 'IBM Plex Mono', monospace;
    --f-script: 'Alex Brush', 'Brush Script MT', cursive;
    --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px; --sp-4: 16px;
    --sp-5: 20px; --sp-6: 24px; --sp-7: 32px; --sp-8: 40px;
    --sp-9: 48px; --sp-10: 64px; --sp-11: 96px;
    --frame-inset: 14px;
    --maxw: 1160px;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    font-family: var(--f-body);
    background: var(--paper);
    color: var(--ink-900);
    font-size: 15.5px;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}
:focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }
a { color: inherit; }
img, svg { display: block; }
.wrap { max-width: var(--maxw); margin: 0 auto; padding: 0 var(--sp-6); }
@media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }

.eyebrow {
    font-family: var(--f-cond);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--brass-deep);
}
.script-accent {
    font-family: var(--f-script);
    font-weight: 700;
    color: var(--ink-900);
    line-height: 1;
}
h1, h2, h3 { font-family: var(--f-display); font-weight: 600; color: var(--ink-900); letter-spacing: -0.005em; }

/* ============================================================
   NAV
   ============================================================ */
.navbar {
    position: sticky; top: 0; z-index: 500;
    background: rgba(251,249,245,0.92);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--line);
}
.navbar .wrap { display: flex; align-items: center; justify-content: space-between; padding-top: var(--sp-4); padding-bottom: var(--sp-4); }
.brand-mark { display: flex; flex-direction: column; text-decoration: none; }
.brand-mark .mark { font-family: var(--f-display); font-weight: 600; font-size: 21px; color: var(--ink-900); letter-spacing: 0.005em; }
.brand-mark .mark sup { font-size: 10px; color: var(--brass-deep); }
.brand-mark .division { margin-top: 3px; font-family: var(--f-cond); font-size: 9.5px; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; color: var(--ink-300); padding-top: 3px; border-top: 1.5px solid var(--brass); display: inline-block; }
.nav-links { display: flex; gap: var(--sp-7); align-items: center; }
.nav-links a { font-family: var(--f-cond); text-decoration: none; color: var(--ink-500); font-size: 12.5px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; transition: color .15s; }
.nav-links a:hover { color: var(--brass-deep); }
.nav-buttons { display: flex; gap: var(--sp-3); }
@media (max-width: 860px) { .nav-links { display: none; } }

.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: var(--sp-2);
    padding: 12px 22px;
    font-family: var(--f-cond); font-weight: 700; font-size: 12.5px;
    letter-spacing: 0.06em; text-transform: uppercase;
    text-decoration: none; cursor: pointer; border-radius: 0;
    transition: all .15s ease;
}
.btn svg { width: 14px; height: 14px; transition: transform .15s; }
.btn:hover svg { transform: translateX(3px); }
.btn-primary { background: var(--ink-900); color: #fff; border: 1.5px solid var(--ink-900); }
.btn-primary:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }
.btn-outline { background: transparent; color: var(--ink-900); border: 1.5px solid var(--line-strong); }
.btn-outline:hover { border-color: var(--brass); color: var(--brass-deep); }
.btn-large { padding: 16px 30px; font-size: 13px; }

/* ============================================================
   FRAME MOTIF — reused museum-label mat from the sign-in panel
   ============================================================ */
.frame-mat { position: relative; }
.frame-line { position: absolute; inset: var(--frame-inset); border: 1px solid rgba(22,35,46,0.14); pointer-events: none; }
.frame-strip { position: absolute; color: rgba(22,35,46,0.4); font-family: var(--f-mono); font-size: 9.5px; letter-spacing: 0.26em; text-transform: uppercase; white-space: nowrap; z-index: 2; }
.frame-strip.top { top: calc(var(--frame-inset) - 9px); left: calc(var(--frame-inset) + 26px); background: var(--paper); padding: 0 10px; }
.frame-strip.bottom { bottom: calc(var(--frame-inset) - 9px); right: calc(var(--frame-inset) + 26px); background: var(--paper); padding: 0 10px; }

/* ============================================================
   HERO
   ============================================================ */
.hero { position: relative; padding: var(--sp-10) 0 var(--sp-9); overflow: hidden; }
.hero-frame { position: relative; margin: 0 var(--sp-4); min-height: 560px; }
.doodle-field { position: absolute; inset: var(--frame-inset); z-index: 0; overflow: hidden; opacity: 0.9; }
.doodle-field svg { position: absolute; stroke: rgba(22,35,46,0.14); fill: none; stroke-width: 1.3; stroke-linecap: round; stroke-linejoin: round; }
.doodle-field svg.brass { stroke: rgba(180,136,74,0.34); }
.doodle-field svg.faint { stroke: rgba(22,35,46,0.08); }

.hero-content { position: relative; z-index: 2; max-width: 680px; padding: var(--sp-9) var(--sp-8); }
.halo { text-shadow: 0 0 8px var(--paper), 0 0 8px var(--paper), 0 0 14px var(--paper), 0 0 14px var(--paper); }

.stamp-badge {
    display: inline-flex; align-items: center; gap: 8px;
    border: 1.5px solid var(--brass); color: var(--brass-deep);
    padding: 7px 14px; font-family: var(--f-cond); font-size: 11px;
    font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;
    margin-bottom: var(--sp-6); transform: rotate(-1.2deg);
    background: var(--brass-tint);
}
.stamp-badge svg { width: 13px; height: 13px; }

.hero h1 { font-size: clamp(2.6rem, 5.6vw, 4.2rem); line-height: 1.04; margin-bottom: var(--sp-6); }
.hero h1 .script-accent { font-size: 1.15em; color: var(--brass-deep); display: inline-block; padding: 0 0.06em; }
.hero p.lede { font-size: 17px; color: var(--ink-700); max-width: 480px; margin-bottom: var(--sp-7); }
.hero-ctas { display: flex; gap: var(--sp-4); flex-wrap: wrap; margin-bottom: var(--sp-8); }

.ledger-line { display: flex; gap: var(--sp-8); flex-wrap: wrap; border-top: 1.5px solid var(--line-strong); padding-top: var(--sp-5); }
.ledger-line .item { display: flex; flex-direction: column; }
.ledger-line .item .num { font-family: var(--f-mono); font-size: 22px; font-weight: 600; color: var(--ink-900); }
.ledger-line .item .lbl { font-family: var(--f-cond); font-size: 10.5px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-300); margin-top: 2px; }

@media (max-width: 720px) {
    .hero-content { padding: var(--sp-8) var(--sp-5); }
    .hero-frame { min-height: 0; }
}

/* ============================================================
   SECTION SCAFFOLD
   ============================================================ */
section { padding: var(--sp-11) 0; border-bottom: 1px solid var(--line); }
section.no-border { border-bottom: none; }
.section-head { max-width: 620px; margin-bottom: var(--sp-9); }
.section-head h2 { font-size: clamp(1.9rem, 3.4vw, 2.6rem); margin-top: var(--sp-3); }
.section-head p { color: var(--ink-500); font-size: 15px; margin-top: var(--sp-3); max-width: 540px; }
.section-head.center { margin-left: auto; margin-right: auto; text-align: center; }

/* Content is visible by default — JS only arms the hidden state if it
   can also guarantee revealing it (progressive enhancement, so a slow
   or blocked script never leaves real content invisible). */
.js-reveal .reveal { opacity: 0; transform: translateY(22px); transition: opacity .55s ease, transform .55s ease; }
.reveal.in { opacity: 1; transform: translateY(0); }

/* ============================================================
   THE LEDGER — signature element. Before/after recast as a bank
   journal: two ruled columns of entries, old ones struck through
   with a "VOID" stamp, new ones checked off with a brass tick,
   center spine like a real ledger book.
   ============================================================ */
.ledger-book {
    background: var(--panel);
    border: 1.5px solid var(--line-strong);
    position: relative;
    overflow: hidden;
}
.ledger-spine {
    position: absolute; left: 50%; top: 0; bottom: 0; width: 1px;
    background: repeating-linear-gradient(to bottom, var(--line-strong) 0, var(--line-strong) 6px, transparent 6px, transparent 12px);
    transform: translateX(-0.5px);
    display: none;
}
.ledger-grid { display: grid; grid-template-columns: 1fr 1fr; }
.ledger-col { padding: var(--sp-8); }
.ledger-col.old { }
.ledger-col.new { background: var(--mint-tint); }
.ledger-col-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: var(--sp-6); padding-bottom: var(--sp-4); border-bottom: 1.5px solid var(--ink-900); }
.ledger-col-head h3 { font-size: 18px; }
.ledger-col-head .tag { font-family: var(--f-mono); font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-300); }
.ledger-col.new .ledger-col-head .tag { color: var(--mint); }
.ledger-entry { display: flex; align-items: flex-start; gap: var(--sp-4); padding: var(--sp-4) 0; border-bottom: 1px dashed var(--line); }
.ledger-entry:last-child { border-bottom: none; }
.ledger-entry .mark { font-family: var(--f-mono); font-size: 13px; font-weight: 700; width: 20px; flex-shrink: 0; padding-top: 2px; }
.ledger-col.old .ledger-entry .mark { color: var(--danger); }
.ledger-col.new .ledger-entry .mark { color: var(--mint); }
.ledger-entry .txt { font-size: 14px; color: var(--ink-700); }
.ledger-col.old .ledger-entry .txt { text-decoration: line-through; text-decoration-color: rgba(179,38,30,0.45); color: var(--ink-500); }
.ledger-col.new .ledger-entry .txt { color: var(--ink-900); font-weight: 500; }

@media (max-width: 780px) { .ledger-grid { grid-template-columns: 1fr; } .ledger-spine { display: none; } .ledger-col.old { border-bottom: 1.5px solid var(--line-strong); } }

/* ============================================================
   FEATURES — itemized like statement line-items, numbered as
   entry codes (##) rather than a false "step" sequence.
   ============================================================ */
.feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 0; border-top: 1.5px solid var(--ink-900); border-left: 1px solid var(--line); }
.feature-card { padding: var(--sp-7) var(--sp-6); border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); transition: background .2s; position: relative; }
.feature-card:hover { background: var(--brass-tint); }
.feature-card .code { font-family: var(--f-mono); font-size: 11px; color: var(--brass-deep); letter-spacing: 0.06em; margin-bottom: var(--sp-3); display: block; }
.feature-card .ico { width: 30px; height: 30px; margin-bottom: var(--sp-4); stroke: var(--ink-900); fill: none; stroke-width: 1.5; }
.feature-card h3 { font-size: 16.5px; margin-bottom: var(--sp-2); font-weight: 600; }
.feature-card p { color: var(--ink-500); font-size: 13.5px; line-height: 1.55; }

/* ============================================================
   HOW IT WORKS — a real 3-step sequence, styled as ticket stubs
   ============================================================ */
.stub-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--sp-6); }
.stub {
    background: var(--panel); border: 1.5px dashed var(--line-strong);
    padding: var(--sp-7) var(--sp-6); position: relative;
}
.stub::before, .stub::after {
    content: ''; position: absolute; width: 16px; height: 16px; background: var(--paper);
    border-radius: 50%; top: 50%; transform: translateY(-50%);
}
.stub::before { left: -9px; }
.stub::after { right: -9px; }
.stub .step-tag { font-family: var(--f-mono); font-size: 11px; color: var(--brass-deep); letter-spacing: 0.1em; text-transform: uppercase; }
.stub h3 { font-size: 19px; margin: var(--sp-3) 0 var(--sp-2); }
.stub p { color: var(--ink-500); font-size: 13.5px; }
.stub-connector { display: flex; align-items: center; justify-content: center; }
.stub-connector svg { width: 26px; height: 14px; stroke: var(--line-strong); }
@media (max-width: 900px) { .stub-connector { display: none; } }

/* ============================================================
   PHOTO SLOTS — everywhere the page wants a real photograph
   instead of an icon or illustration. Rendered as a clearly-marked
   placeholder (dashed frame + corner tag + caption) so it's obvious
   what to shoot/license and where it goes — the image version of
   the "replace this logo" note. Drop a real <img> in and remove
   .is-placeholder to go live; the frame styling still applies.
   ============================================================ */
.photo-slot {
    position: relative;
    background: var(--brass-tint);
    border: 1.5px dashed var(--line-strong);
    overflow: hidden;
    display: flex; align-items: center; justify-content: center;
    text-align: center;
}
.photo-slot img { width: 100%; height: 100%; object-fit: cover; display: block; }
.photo-slot.is-placeholder .placeholder-copy { padding: var(--sp-5); }
.photo-slot .placeholder-copy .ico { width: 26px; height: 26px; margin: 0 auto var(--sp-3); stroke: var(--brass-deep); fill: none; stroke-width: 1.4; }
.photo-slot .placeholder-copy .need { font-family: var(--f-cond); font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--brass-deep); }
.photo-slot .placeholder-copy .spec { font-family: var(--f-mono); font-size: 10.5px; color: var(--ink-300); margin-top: 4px; }
.photo-tag {
    position: absolute; top: 10px; left: 10px; z-index: 2;
    font-family: var(--f-mono); font-size: 9px; letter-spacing: 0.1em; text-transform: uppercase;
    background: var(--paper); border: 1px solid var(--line-strong); color: var(--ink-500);
    padding: 3px 7px;
}
/* Real photography, once dropped in, gets a quiet duotone pass so
   every image reads as one consistent brand rather than stock-photo
   grab-bag — this is the only place color imagery is allowed on an
   otherwise ink/brass/paper page. */
.photo-slot img { filter: grayscale(0.35) sepia(0.18) contrast(1.03); }

/* ============================================================
   FOUNDER'S NOTE — a letter, not a bio card. Styled like the
   inside cover of a bank's annual report: portrait on the left,
   signed letter on the right, one pulled line in brass.
   ============================================================ */
.letter { display: grid; grid-template-columns: 0.85fr 1.4fr; gap: var(--sp-9); align-items: start; }
.letter-portrait { aspect-ratio: 4 / 5; }
.letter-portrait .placeholder-copy .need { color: var(--brass-deep); }
.letter-cred { margin-top: var(--sp-4); }
.letter-cred .name { font-family: var(--f-display); font-size: 17px; font-weight: 600; }
.letter-cred .role { font-family: var(--f-cond); font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-300); margin-top: 2px; }

.letter-body .script-accent { font-size: 30px; color: var(--brass-deep); display: block; margin-bottom: var(--sp-4); }
.letter-body p { color: var(--ink-700); font-size: 15px; margin-bottom: var(--sp-4); max-width: 560px; }
.letter-pull {
    font-family: var(--f-display); font-style: italic; font-size: 21px; font-weight: 500;
    color: var(--ink-900); border-left: 3px solid var(--brass); padding-left: var(--sp-5);
    margin: var(--sp-6) 0; max-width: 480px;
}
.letter-sign { margin-top: var(--sp-7); display: flex; align-items: center; gap: var(--sp-5); }
.letter-sign .script-accent { font-size: 34px; margin-bottom: 0; }
.letter-sign .sign-meta { border-left: 1.5px solid var(--line-strong); padding-left: var(--sp-5); }
.letter-sign .sign-meta .name { font-family: var(--f-cond); font-weight: 700; font-size: 13px; color: var(--ink-900); }
.letter-sign .sign-meta .role { font-family: var(--f-cond); font-size: 11px; color: var(--ink-300); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px; }

@media (max-width: 800px) { .letter { grid-template-columns: 1fr; } .letter-portrait { max-width: 260px; } }

/* ============================================================
   PROOF STRIP — three real-world photos (agent, ATM, customer),
   taped in like receipt stubs rather than a slick gallery, to keep
   the "financial life, not stock photography" feel.
   ============================================================ */
.proof-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--sp-6); }
.proof-card { background: var(--panel); border: 1.5px solid var(--line-strong); padding: var(--sp-3) var(--sp-3) var(--sp-4); transform: rotate(var(--tilt, 0deg)); }
.proof-card:nth-child(1) { --tilt: -1.4deg; }
.proof-card:nth-child(2) { --tilt: 0.8deg; }
.proof-card:nth-child(3) { --tilt: -0.6deg; }
.proof-card .photo-slot { aspect-ratio: 4 / 3; }
.proof-card .cap { margin-top: var(--sp-3); font-family: var(--f-cond); font-size: 12px; font-weight: 700; color: var(--ink-700); text-align: center; }
.proof-card .cap .sub { display: block; font-weight: 400; color: var(--ink-300); font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 2px; }
@media (max-width: 780px) { .proof-strip { grid-template-columns: 1fr; max-width: 320px; margin: 0 auto; } }

/* ============================================================
   TRUST STRIP
   ============================================================ */
.trust-strip { display: flex; flex-wrap: wrap; gap: var(--sp-8); justify-content: center; padding: var(--sp-7) 0; }
.trust-strip .item { display: flex; align-items: center; gap: var(--sp-3); font-family: var(--f-cond); font-size: 12px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ink-500); }
.trust-strip svg { width: 18px; height: 18px; stroke: var(--brass); fill: none; stroke-width: 1.6; }

/* ============================================================
   WAITLIST — an "application" form on paper, matching the
   sign-in input styling exactly
   ============================================================ */
.apply-wrap { display: grid; grid-template-columns: 1fr 1.15fr; gap: 0; border: 1.5px solid var(--line-strong); background: var(--panel); }
.apply-side { background: var(--ink-900); color: var(--paper); padding: var(--sp-9) var(--sp-7); display: flex; flex-direction: column; justify-content: space-between; }
.apply-side .script-accent { font-size: 40px; color: var(--brass); margin-bottom: var(--sp-5); }
.apply-side h2 { color: #fff; font-size: 1.9rem; }
.apply-side p { color: rgba(251,249,245,0.7); font-size: 13.5px; margin-top: var(--sp-4); max-width: 320px; }
.apply-side .perk { margin-top: var(--sp-8); border-top: 1px solid rgba(251,249,245,0.18); padding-top: var(--sp-5); }
.apply-side .perk .big { font-family: var(--f-mono); font-size: 26px; color: var(--brass); font-weight: 700; }
.apply-side .perk .small { font-family: var(--f-cond); font-size: 10.5px; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(251,249,245,0.55); margin-top: 2px; }

.apply-form { padding: var(--sp-8) var(--sp-7); }
.field { margin-bottom: var(--sp-5); }
.field label { display: block; margin-bottom: var(--sp-2); font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-500); font-family: var(--f-cond); }
.field input, .field select {
    width: 100%; padding: 13px 14px; border: 1.5px solid var(--line);
    font-size: 14.5px; font-family: var(--f-body); background: #fff; color: var(--ink-900);
    border-radius: 0; transition: border-color .15s;
}
.field input:focus, .field select:focus { outline: none; border-color: var(--brass); }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-4); }
.apply-form .btn { width: 100%; margin-top: var(--sp-3); }
.apply-form .fine { font-size: 11px; color: var(--ink-300); margin-top: var(--sp-4); text-align: center; font-family: var(--f-cond); letter-spacing: 0.03em; }

@media (max-width: 860px) {
    .apply-wrap { grid-template-columns: 1fr; }
    .field-row { grid-template-columns: 1fr; }
}

/* ============================================================
   FOOTER
   ============================================================ */
footer { padding: var(--sp-8) 0 var(--sp-9); }
footer .wrap { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--sp-5); }
.footer-links { display: flex; gap: var(--sp-6); font-family: var(--f-cond); font-size: 11.5px; letter-spacing: 0.05em; text-transform: uppercase; font-weight: 700; }
.footer-links a { color: var(--ink-500); text-decoration: none; transition: color .15s; }
.footer-links a:hover { color: var(--brass-deep); }
.footer-copy { color: var(--ink-300); font-size: 11.5px; font-family: var(--f-mono); }
</style>
</head>
<body>

<!-- NAV -->
<nav class="navbar">
    <div class="wrap">
        <a href="/" class="brand-mark">
            <span class="mark">VOUCHMORPH<sup>™</sup></span>
            <span class="division">Money Without Walls</span>
        </a>
        <div class="nav-links">
            <a href="#ledger">Why VouchMorph</a>
            <a href="#letter">Founder's Note</a>
            <a href="#features">Features</a>
            <a href="#how-it-works">How It Works</a>
            <a href="#waitlist">Early Access</a>
        </div>
        <div class="nav-buttons">
            <a href="login.php" class="btn btn-outline">Sign In</a>
            <a href="register.php" class="btn btn-primary">
                Register
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
        </div>
    </div>
</nav>

<!-- HERO -->
<section class="hero no-border">
    <div class="hero-frame frame-mat">
        <div class="frame-line"></div>
        <div class="frame-strip top"><span>Est. Botswana</span></div>
        <div class="frame-strip bottom"><span>Voucher · Wallet · Cash</span></div>
        <div class="doodle-field" id="doodleField" aria-hidden="true"></div>

        <div class="hero-content">
            <span class="stamp-badge halo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 6v6c0 5 3.5 8 8 10 4.5-2 8-5 8-10V6l-8-4Z"/></svg>
                Bank of Botswana Sandbox Ready
            </span>
            <h1 class="halo">Every wallet.<br>One <span class="script-accent">account.</span><br>No walls.</h1>
            <p class="lede halo">VouchMorph reconciles every wallet, card, voucher, and ATM into a single ledger that's yours — send across networks, cash out anywhere, deposit vouchers instantly.</p>
            <div class="hero-ctas">
                <a href="#waitlist" class="btn btn-primary btn-large">
                    Request Early Access
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                <a href="#how-it-works" class="btn btn-outline btn-large">See how it works</a>
            </div>
            <div class="ledger-line halo">
                <div class="item"><span class="num">0%</span><span class="lbl">Lock-in</span></div>
                <div class="item"><span class="num">∞</span><span class="lbl">Networks</span></div>
                <div class="item"><span class="num">&lt;60s</span><span class="lbl">To cash out</span></div>
            </div>
        </div>
    </div>
</section>

<!-- THE LEDGER — signature comparison element -->
<section id="ledger">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">The Reconciliation</span>
            <h2>Every account you already have.<br>One ledger that finally agrees.</h2>
            <p>Mobile money shouldn't be a series of locked rooms. Here's the entry we're closing out, and the one we're opening.</p>
        </div>

        <div class="ledger-book reveal">
            <div class="ledger-spine"></div>
            <div class="ledger-grid">
                <div class="ledger-col old">
                    <div class="ledger-col-head">
                        <h3>Closed Account</h3>
                        <span class="tag">Before</span>
                    </div>
                    <div class="ledger-entry"><span class="mark">✕</span><span class="txt">M-Pesa only speaks to M-Pesa</span></div>
                    <div class="ledger-entry"><span class="mark">✕</span><span class="txt">Airtel only speaks to Airtel</span></div>
                    <div class="ledger-entry"><span class="mark">✕</span><span class="txt">ATM cash-out needs your own card</span></div>
                    <div class="ledger-entry"><span class="mark">✕</span><span class="txt">A voucher redeems at one store, once</span></div>
                    <div class="ledger-entry"><span class="mark">✕</span><span class="txt">Cash-out means finding your agent</span></div>
                </div>
                <div class="ledger-col new">
                    <div class="ledger-col-head">
                        <h3>Open Account</h3>
                        <span class="tag">With VouchMorph</span>
                    </div>
                    <div class="ledger-entry"><span class="mark">✓</span><span class="txt">Any wallet reaches any wallet</span></div>
                    <div class="ledger-entry"><span class="mark">✓</span><span class="txt">Any network reaches any network</span></div>
                    <div class="ledger-entry"><span class="mark">✓</span><span class="txt">Any participating ATM accepts your ID</span></div>
                    <div class="ledger-entry"><span class="mark">✓</span><span class="txt">A voucher becomes cash in any wallet, instantly</span></div>
                    <div class="ledger-entry"><span class="mark">✓</span><span class="txt">Any agent becomes your agent</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FOUNDER'S NOTE -->
<section id="letter">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">From The Founder</span>
            <h2>Why we're building this</h2>
        </div>

        <div class="letter reveal">
            <div>
                <div class="photo-slot is-placeholder letter-portrait">
                    <span class="photo-tag">Photo — Founder</span>
                    <div class="placeholder-copy">
                        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.8-4.5 5-6.5 8-6.5s6.2 2 8 6.5"/></svg>
                        <div class="need">Portrait, 4:5</div>
                        <div class="spec">Real photo of founder/CEO<br>e.g. 1000×1250px, natural light</div>
                    </div>
                </div>
                <div class="letter-cred">
                    <div class="name">[Founder Name]</div>
                    <div class="role">Founder &amp; CEO, VouchMorph</div>
                </div>
            </div>

            <div class="letter-body">
                <span class="script-accent">Dear reader,</span>
                <p>I grew up watching my mother send money home through three different agents because no single wallet reached everyone she needed to pay. That wasn't a technology problem — the technology already existed in every one of those wallets. It was a walls problem.</p>
                <p>VouchMorph doesn't ask anyone to leave their bank, their network, or their agent. It asks them to agree on one ledger, so the money you already have can finally move the way you actually live — across networks, across accounts, across a counter at any ATM.</p>
                <div class="letter-pull">We're not building a new wallet. We're building the room where all your existing wallets can finally talk to each other.</div>
                <p>We're doing this inside the Bank of Botswana's regulatory sandbox, deliberately, so that trust is earned before scale — not the other way around. If you've felt the friction of moving your own money, this is for you.</p>

                <div class="letter-sign">
                    <span class="script-accent">Signature</span>
                    <div class="sign-meta">
                        <div class="name">[Founder Name]</div>
                        <div class="role">Founder &amp; CEO</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FEATURES -->
<section id="features">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">The Line Items</span>
            <h2>One identity. Every capability.</h2>
            <p>Everything below draws from the same account — nothing here needs a separate app, a separate login, or a separate network.</p>
        </div>

        <div class="feature-grid reveal">
            <div class="feature-card">
                <span class="code">§ 01</span>
                <svg class="ico" viewBox="0 0 24 24"><rect x="3" y="9" width="18" height="12" rx="1"/><path d="M7 9V6a5 5 0 0 1 10 0v3"/></svg>
                <h3>Cash Out, Any ATM</h3>
                <p>No card in hand, no problem — your VouchMorph identity is accepted at any participating machine.</p>
            </div>
            <div class="feature-card">
                <span class="code">§ 02</span>
                <svg class="ico" viewBox="0 0 24 24"><path d="M3 21V10l9-6 9 6v11"/><path d="M9 21v-6h6v6"/></svg>
                <h3>Agent, Anywhere</h3>
                <p>Every partner agent becomes your agent — cash in or out with no network restriction attached.</p>
            </div>
            <div class="feature-card">
                <span class="code">§ 03</span>
                <svg class="ico" viewBox="0 0 24 24"><path d="M4 18h6M14 6h6M4 6h2M18 18h2M9 6h6M9 18h6" /></svg>
                <h3>Wallet to Any Wallet</h3>
                <p>Send from one network straight into another. No middle hop, no manual conversion, no delay.</p>
            </div>
            <div class="feature-card">
                <span class="code">§ 04</span>
                <svg class="ico" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 10h18M7 15h4"/></svg>
                <h3>Voucher Banking</h3>
                <p>Load any ATM or e-wallet voucher directly into your VouchMorph balance, on the spot.</p>
            </div>
            <div class="feature-card">
                <span class="code">§ 05</span>
                <svg class="ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/></svg>
                <h3>Reverse Cash-In</h3>
                <p>Feed physical cash into an ATM and route it into any digital wallet you hold — not just one bank's app.</p>
            </div>
            <div class="feature-card">
                <span class="code">§ 06</span>
                <svg class="ico" viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"/><circle cx="17" cy="7" r="2.4"/><path d="M2 21c0-3.9 2.7-7 6-7s6 3.1 6 7M14 14c2.8.3 4.6 2.6 5 6"/></svg>
                <h3>Family Pooling</h3>
                <p>Link every wallet your household holds under one dashboard, without asking anyone to switch banks.</p>
            </div>
            <div class="feature-card">
                <span class="code">§ 07</span>
                <svg class="ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 2.5 15.5 0 18M12 3c-2.5 2.5-2.5 15.5 0 18"/></svg>
                <h3>Cross-Network Send</h3>
                <p>Reach any mobile money user directly, regardless of which network they signed up on.</p>
            </div>
            <div class="feature-card">
                <span class="code">§ 08</span>
                <svg class="ico" viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/></svg>
                <h3>Combine Sources</h3>
                <p>One payment, several sources — split evenly, by balance, or automatically. VouchMorph does the math.</p>
            </div>
        </div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section id="how-it-works">
    <div class="wrap">
        <div class="section-head center reveal" style="margin-left:auto;margin-right:auto;">
            <span class="eyebrow">Opening The Account</span>
            <h2>Three entries. Fully reconciled.</h2>
            <p style="margin-left:auto;margin-right:auto;">From locked-in to limitless — the whole process takes minutes, not paperwork.</p>
        </div>

        <div class="stub-row reveal">
            <div class="stub">
                <span class="step-tag">Entry 01</span>
                <h3>Link</h3>
                <p>Connect the wallets, bank accounts, and vouchers you already hold to one VouchMorph identity.</p>
            </div>
            <div class="stub-connector"><svg viewBox="0 0 26 14" fill="none" stroke-width="1.5"><path d="M1 7h20M15 1l6 6-6 6"/></svg></div>
            <div class="stub">
                <span class="step-tag">Entry 02</span>
                <h3>Verify</h3>
                <p>One biometric or PIN login. No new SIM, no repeated KYC per bank.</p>
            </div>
            <div class="stub-connector"><svg viewBox="0 0 26 14" fill="none" stroke-width="1.5"><path d="M1 7h20M15 1l6 6-6 6"/></svg></div>
            <div class="stub">
                <span class="step-tag">Entry 03</span>
                <h3>Transact</h3>
                <p>Send, withdraw, deposit, cash out — across any network, anywhere it's accepted.</p>
            </div>
        </div>
    </div>
</section>

<!-- PROOF STRIP — real-world photography -->
<section class="no-border" style="padding-top: 0;">
    <div class="wrap">
        <div class="section-head center reveal" style="margin-left:auto;margin-right:auto;">
            <span class="eyebrow">On The Ground</span>
            <h2>Not a concept. A counter, an ATM, a real handoff.</h2>
        </div>
        <div class="proof-strip reveal">
            <div class="proof-card">
                <div class="photo-slot is-placeholder">
                    <span class="photo-tag">Photo — Agent</span>
                    <div class="placeholder-copy">
                        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 21V10l9-6 9 6v11"/><path d="M9 21v-6h6v6"/></svg>
                        <div class="need">Landscape, 4:3</div>
                        <div class="spec">Agent handing over cash<br>at a real partner location</div>
                    </div>
                </div>
                <div class="cap">Partner Agent<span class="sub">Gaborone</span></div>
            </div>
            <div class="proof-card">
                <div class="photo-slot is-placeholder">
                    <span class="photo-tag">Photo — ATM</span>
                    <div class="placeholder-copy">
                        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="9" width="18" height="12" rx="1"/><path d="M7 9V6a5 5 0 0 1 10 0v3"/></svg>
                        <div class="need">Landscape, 4:3</div>
                        <div class="spec">Customer cashing out<br>at a partner ATM</div>
                    </div>
                </div>
                <div class="cap">Cash Pickup<span class="sub">Any Partner ATM</span></div>
            </div>
            <div class="proof-card">
                <div class="photo-slot is-placeholder">
                    <span class="photo-tag">Photo — Customer</span>
                    <div class="placeholder-copy">
                        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 18h6"/></svg>
                        <div class="need">Landscape, 4:3</div>
                        <div class="spec">Real customer using<br>VouchMorph on their phone</div>
                    </div>
                </div>
                <div class="cap">A Real Swap<span class="sub">On Their Own Phone</span></div>
            </div>
        </div>
    </div>
</section>

<!-- TRUST STRIP -->
<section class="no-border" style="padding-top: var(--sp-8); padding-bottom: var(--sp-8);">
    <div class="wrap">
        <div class="trust-strip reveal">
            <span class="item"><svg viewBox="0 0 24 24"><path d="M12 2 4 6v6c0 5 3.5 8 8 10 4.5-2 8-5 8-10V6l-8-4Z"/></svg>Encrypted end to end</span>
            <span class="item"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="10"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>PIN protected</span>
            <span class="item"><svg viewBox="0 0 24 24"><path d="m4 12 5 5L20 6"/></svg>ISO 27001 aligned</span>
            <span class="item"><svg viewBox="0 0 24 24"><path d="M3 21V10l9-6 9 6v11"/><path d="M9 21v-6h6v6"/></svg>Regulated sandbox partner</span>
        </div>
    </div>
</section>

<!-- WAITLIST / APPLY -->
<section id="waitlist" class="no-border">
    <div class="wrap">
        <div class="apply-wrap reveal">
            <div class="apply-side">
                <div>
                    <div class="script-accent">Apply.</div>
                    <h2>Open your account before anyone else.</h2>
                    <p>Early access is limited while we're inside the Bank of Botswana sandbox — join the list and we'll reach out the moment your cohort opens.</p>
                </div>
                <div class="perk">
                    <div class="big">10,000</div>
                    <div class="small">First users · lifetime 0% fees</div>
                </div>
            </div>
            <form class="apply-form" method="POST" action="waitlist-handler.php">
                <div class="field">
                    <label>Full name</label>
                    <input type="text" name="fullname" placeholder="Thabo Molefe" required>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>Email address</label>
                        <input type="email" name="email" placeholder="you@example.com" required>
                    </div>
                    <div class="field">
                        <label>Country</label>
                        <input type="text" name="country" value="Botswana">
                    </div>
                </div>
                <div class="field">
                    <label>Primary wallet</label>
                    <select name="primary_wallet">
                        <option value="">Select your primary wallet</option>
                        <option>M-Pesa</option>
                        <option>Airtel Money</option>
                        <option>Orange Money</option>
                        <option>Moov Money</option>
                        <option>Bank Account</option>
                        <option>Other</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    Request Early Access
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>
                <div class="fine">No spam. One email when your cohort opens.</div>
            </form>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer>
    <div class="wrap">
        <a href="/" class="brand-mark">
            <span class="mark" style="font-size:16px;">VOUCHMORPH<sup>™</sup></span>
        </a>
        <div class="footer-links">
            <a href="#">Privacy</a>
            <a href="#">Terms</a>
            <a href="login.php">Sign In</a>
            <a href="register.php">Register</a>
        </div>
        <div class="footer-copy">© 2026 VouchMorph — Ready for Bank of Botswana</div>
    </div>
</footer>

<script>
// Scroll reveal — same restrained fade used across the app. Only arms
// the hidden state (.js-reveal on <html>) when IntersectionObserver is
// actually available, so content is never stuck invisible.
if ('IntersectionObserver' in window) {
    document.documentElement.classList.add('js-reveal');
    const revealEls = document.querySelectorAll('.reveal');
    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.12 });
    revealEls.forEach(el => io.observe(el));
}

// Smooth scroll for on-page anchors
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', function (e) {
        const target = document.querySelector(this.getAttribute('href'));
        if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); }
    });
});

// ------------------------------------------------------------
// Doodle field — same generator as login.php, scattered thin-line
// financial icons across the hero's frame mat only (signature
// restraint: used once, not repeated through the page).
// ------------------------------------------------------------
const DOODLE_COUNT = 90;
const DOODLE_LIBRARY = [
    { vb: '0 0 48 40', p: '<rect x="2" y="10" width="44" height="26" rx="4"/><path d="M2 18h44"/><circle cx="36" cy="27" r="3"/>' },
    { vb: '0 0 40 40', p: '<circle cx="14" cy="14" r="10"/><circle cx="24" cy="24" r="10"/>' },
    { vb: '0 0 40 48', p: '<rect x="6" y="2" width="28" height="44" rx="5"/><path d="M14 40h12"/><path d="M14 12h12M14 20h12M14 28h6"/>' },
    { vb: '0 0 52 40', p: '<path d="M4 20h30M26 10l10 10-10 10"/><path d="M48 20H18M26 30 16 20l10-10"/>' },
    { vb: '0 0 40 40', p: '<circle cx="20" cy="20" r="17"/><path d="M3 20h34M20 3c5 5 5 29 0 34M20 3c-5 5-5 29 0 34"/>' },
    { vb: '0 0 34 34', p: '<path d="M4 26 26 4M26 4h-10M26 4v10"/>' },
    { vb: '0 0 30 30', p: '<rect x="3" y="7" width="24" height="17" rx="2"/><path d="M3 12h24"/>' },
    { vb: '0 0 30 36', p: '<rect x="4" y="12" width="22" height="20" rx="3"/><path d="M9 12V8a6 6 0 0 1 12 0v4"/><circle cx="15" cy="21" r="2"/>' },
    { vb: '0 0 40 30', p: '<path d="M2 26c6-14 12 6 18-6s10-14 18 4"/><ellipse cx="12" cy="10" rx="9" ry="6"/><path d="M12 16v6"/>' },
    { vb: '0 0 30 30', p: '<path d="M15 3v24M4 8l11-5 11 5M4 22l11 5 11-5M4 8v14M26 8v14"/>' },
    { vb: '0 0 20 20', p: '<path d="M10 1 12.5 7 19 8l-4.7 4.4L15.5 19 10 15.7 4.5 19l1.2-6.6L1 8l6.5-1Z"/>' },
    { vb: '0 0 26 20', p: '<rect x="2" y="2" width="22" height="16" rx="2"/><path d="M2 7h22"/><path d="M6 12h6"/>' },
];
(function generateDoodles() {
    const field = document.getElementById('doodleField');
    if (!field) return;
    const frag = document.createDocumentFragment();
    const svgNS = 'http://www.w3.org/2000/svg';
    for (let i = 0; i < DOODLE_COUNT; i++) {
        const icon = DOODLE_LIBRARY[Math.floor(Math.random() * DOODLE_LIBRARY.length)];
        const svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('viewBox', icon.vb);
        svg.innerHTML = icon.p;
        const size = 14 + Math.random() * 36;
        const top = Math.random() * 96;
        const left = Math.random() * 96;
        const rotate = Math.round(Math.random() * 40 - 20);
        svg.style.width = size + 'px';
        svg.style.top = top + '%';
        svg.style.left = left + '%';
        svg.style.transform = `rotate(${rotate}deg)`;
        const roll = Math.random();
        if (roll < 0.22) svg.classList.add('brass');
        else if (roll < 0.55) svg.classList.add('faint');
        frag.appendChild(svg);
    }
    field.appendChild(frag);
})();
</script>
</body>
</html>
