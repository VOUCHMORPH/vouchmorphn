<?php
/**
 * Simple Investment Memorandum
 * Just view in browser → Ctrl+P → Save as PDF
 */

// If you want to force download, just show the page
header('Content-Type: text/html; charset=utf-8');

// Load the HTML file (if you save it separately)
// Or just echo the HTML directly
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph · Investment Memorandum</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           SIMPLE CLEAN STYLES — Minimal, works everywhere
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "IBM Plex Sans", sans-serif;
            background: #EEF1EF;
            color: #0F2138;
            padding: 20px;
        }

        /* Print button - only visible on screen */
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 999;
            background: #0F2138;
            color: #fff;
            border: none;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: "IBM Plex Sans Condensed", sans-serif;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .print-btn:hover { background: #8A6D3B; }

        .page {
            width: 210mm;
            min-height: 297mm;
            background: #FFFFFF;
            padding: 20mm 18mm;
            margin: 0 auto 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            position: relative;
            page-break-after: always;
        }

        /* ===== TYPOGRAPHY ===== */
        .brand {
            font-family: "IBM Plex Sans Condensed", sans-serif;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #0F2138;
            margin-bottom: 4px;
        }
        .brand span { color: #8A6D3B; }

        h1 {
            font-family: "IBM Plex Sans Condensed", sans-serif;
            font-size: 30px;
            font-weight: 700;
            color: #0F2138;
            margin-bottom: 8px;
        }

        h2 {
            font-family: "IBM Plex Sans Condensed", sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: #1D3557;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        p {
            font-size: 13px;
            line-height: 1.7;
            color: #4A5A6E;
            margin-bottom: 10px;
            max-width: 600px;
        }

        .highlight {
            background: #F4EFE3;
            border-left: 4px solid #8A6D3B;
            padding: 12px 16px;
            margin: 12px 0;
            font-size: 14px;
            color: #1D3557;
            max-width: 600px;
        }
        .highlight strong { color: #0F2138; }

        /* ===== METRICS ===== */
        .metrics {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 12px 0;
        }
        .metric {
            background: #EEF1EF;
            border: 1px solid #D3DAD6;
            padding: 10px 14px;
            text-align: center;
            flex: 1;
            min-width: 80px;
        }
        .metric .num {
            font-family: "IBM Plex Sans Condensed", sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: #0F2138;
        }
        .metric .lbl {
            font-size: 9px;
            text-transform: uppercase;
            color: #8A96A3;
            letter-spacing: 0.06em;
            font-weight: 600;
        }

        /* ===== TABLES ===== */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin: 8px 0;
        }
        th {
            background: #EEF1EF;
            color: #4A5A6E;
            padding: 6px 10px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 600;
            border-bottom: 2px solid #D3DAD6;
        }
        td {
            padding: 6px 10px;
            border-bottom: 1px solid #D3DAD6;
            color: #4A5A6E;
            font-size: 12px;
        }

        /* ===== PARTNERS ===== */
        .partners {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            padding: 10px 14px;
            background: #EEF1EF;
            border: 1px solid #D3DAD6;
            margin: 8px 0;
        }
        .partner { text-align: center; }
        .partner .name { font-weight: 700; font-size: 13px; color: #0F2138; }
        .partner .role { font-size: 9px; color: #8A96A3; }

        /* ===== FEATURES ===== */
        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            margin: 8px 0;
        }
        .feature {
            background: #EEF1EF;
            border: 1px solid #D3DAD6;
            padding: 8px 10px;
            text-align: center;
        }
        .feature .icon { font-size: 18px; display: block; }
        .feature .label { font-weight: 600; font-size: 11px; color: #0F2138; }

        /* ===== SOLUTIONS ===== */
        .solutions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 8px 0;
        }
        .solution {
            background: #EEF1EF;
            border: 1px solid #D3DAD6;
            padding: 12px 14px;
            border-top: 3px solid #8A6D3B;
        }
        .solution .icon { font-size: 22px; display: block; }
        .solution .label { font-weight: 700; font-size: 13px; color: #0F2138; }
        .solution .desc { font-size: 11px; color: #4A5A6E; }

        /* ===== FOOTER ===== */
        .footer-text {
            position: absolute;
            bottom: 16mm;
            left: 18mm;
            right: 18mm;
            border-top: 1px solid #D3DAD6;
            padding-top: 8px;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #8A96A3;
        }

        /* ===== PRINT ===== */
        @media print {
            body { background: #fff; padding: 0; margin: 0; }
            .page {
                box-shadow: none;
                margin: 0;
                padding: 18mm 16mm;
                page-break-after: always;
                min-height: 100vh;
                width: 100%;
            }
            .print-btn { display: none !important; }
            .no-print { display: none !important; }
        }

        @media (max-width: 700px) {
            .page { width: 100%; min-height: auto; padding: 16px; }
            .solutions { grid-template-columns: 1fr; }
            .features { grid-template-columns: 1fr 1fr; }
            .metrics { flex-direction: column; }
        }
    </style>
</head>
<body>

<button class="print-btn" onclick="window.print()">📄 Save as PDF</button>

<!-- ============================================================ -->
<!-- PAGE 1 — COVER -->
<!-- ============================================================ -->
<div class="page" style="display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;">
    <div style="width:100%;text-align:left;margin-bottom:40px;">
        <div class="brand">VOUCHMORPH <span>·</span></div>
    </div>

    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;">
        <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-weight:500;font-size:16px;color:#8A96A3;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:4px;">Investment Memorandum</div>

        <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-weight:700;font-size:44px;color:#0F2138;line-height:1.1;max-width:500px;margin:8px 0;">
            The Bridge Between Any Source of Money and Any Identity
        </div>

        <div style="border:2px solid #8A6D3B;padding:16px 40px;background:#F4EFE3;margin:16px 0;">
            <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-size:30px;font-weight:700;color:#0F2138;">BWP 1,500,000</div>
            <div style="font-size:13px;color:#4A5A6E;">for 20% Equity</div>
        </div>

        <div style="display:flex;gap:24px;font-size:12px;color:#4A5A6E;flex-wrap:wrap;justify-content:center;margin-top:8px;">
            <span><strong style="color:#0F2138;">Company:</strong> VouchMorph (Pty) Ltd</span>
            <span><strong style="color:#0F2138;">Country:</strong> Botswana</span>
            <span><strong style="color:#0F2138;">First Market:</strong> Angola</span>
            <span><strong style="color:#0F2138;">Stage:</strong> Regulatory Sandbox</span>
        </div>

        <div style="margin-top:24px;font-size:10px;color:#7A2118;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;">Proprietary &amp; Confidential · 2026</div>
    </div>

    <div style="width:100%;text-align:left;font-size:9px;color:#8A96A3;border-top:1px solid #D3DAD6;padding-top:8px;margin-top:20px;">
        <span>Prepared for Strategic Investors</span>
        <span style="float:right;">Page 1</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 2 — EXECUTIVE SUMMARY -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>Executive Summary</h1>

    <p style="font-size:14px;font-weight:500;color:#0F2138;margin-bottom:12px;">
        VouchMorph is a non-custodial financial interoperability layer that connects banks, mobile money networks, and teller services into a unified access network for money.
    </p>

    <p>Unlike traditional systems that move funds between accounts, VouchMorph enables <strong>value access through identity-bound messaging</strong> — allowing seamless transactions across institutions without requiring accounts at either end.</p>

    <p>The company has completed its MVP, secured a regulatory sandbox position in Botswana, and is now executing its entry into Angola — a market of <strong>40 million people</strong> with <strong>26 million unbanked adults</strong>.</p>

    <div class="highlight">
        <strong>VouchMorph is raising growth capital</strong> to commercialise in Angola, expand across SADC, and establish Africa's first identity-based financial access network.
    </div>

    <div style="margin-top:12px;">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;">
            <div style="background:#0F2138;color:#fff;padding:10px;text-align:center;">
                <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-size:26px;font-weight:700;">40M</div>
                <div style="font-size:8px;text-transform:uppercase;letter-spacing:0.04em;opacity:0.7;">Population</div>
            </div>
            <div style="background:#0F2138;color:#fff;padding:10px;text-align:center;">
                <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-size:26px;font-weight:700;">26M</div>
                <div style="font-size:8px;text-transform:uppercase;letter-spacing:0.04em;opacity:0.7;">Unbanked Adults</div>
            </div>
            <div style="background:#0F2138;color:#fff;padding:10px;text-align:center;">
                <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-size:26px;font-weight:700;">30M+</div>
                <div style="font-size:8px;text-transform:uppercase;letter-spacing:0.04em;opacity:0.7;">Mobile Users</div>
            </div>
        </div>
    </div>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 2</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 3 — STRATEGIC PARTNERS -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>Strategic Partners &amp; Highlights</h1>

    <p>VouchMorph has established strategic partnerships with leading technology and infrastructure firms.</p>

    <div class="partners">
        <div class="partner">
            <div class="name">Intellegere Holdings</div>
            <div class="role">Cybersecurity &amp; Engineering</div>
        </div>
        <div class="partner">
            <div class="name">Malakana Enterprises</div>
            <div class="role">Networking &amp; Cloud</div>
        </div>
        <div class="partner">
            <div class="name">ABCCI</div>
            <div class="role">Angola Market Entry</div>
        </div>
    </div>

    <h2>Investment Highlights</h2>

    <div class="metrics">
        <div class="metric"><div class="num">40M</div><div class="lbl">Population</div></div>
        <div class="metric"><div class="num">26M</div><div class="lbl">Unbanked</div></div>
        <div class="metric"><div class="num">30M+</div><div class="lbl">Mobile Users</div></div>
        <div class="metric"><div class="num">Patent</div><div class="lbl">Granted</div></div>
        <div class="metric"><div class="num">20%</div><div class="lbl">Equity</div></div>
    </div>

    <div class="highlight" style="max-width:100%;">
        <strong>The Angola-Botswana bilateral relationship is at an all-time high.</strong> VouchMorph is the financial infrastructure that delivers on that commitment.
    </div>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 3</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 4 — SOLUTIONS -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>The VouchMorph Solutions</h1>
    <p style="font-size:14px;font-weight:500;color:#0F2138;">Six ways to move value across any institution, to any identity.</p>

    <div class="solutions">
        <div class="solution">
            <span class="icon">🔄</span>
            <div class="label">Standard Swap</div>
            <div class="desc">Bank to bank, wallet to wallet, or bank to wallet. Simple, fast, non-custodial.</div>
        </div>
        <div class="solution">
            <span class="icon">💰</span>
            <div class="label">Cashout Swap</div>
            <div class="desc">Convert digital value to cash at any ATM, agent, or teller.</div>
        </div>
        <div class="solution">
            <span class="icon">🏦</span>
            <div class="label">Deposit Swap</div>
            <div class="desc">Credit funds into any bank account, mobile wallet, or card — sender needs no account.</div>
        </div>
        <div class="solution">
            <span class="icon">🆔</span>
            <div class="label">Swap to Identity</div>
            <div class="desc">Send to a phone number, email, or national ID — no account required at either end.</div>
        </div>
        <div class="solution">
            <span class="icon">📦</span>
            <div class="label">Multi-Source Swap</div>
            <div class="desc">Combine funds from multiple accounts, wallets, or cards into a single transaction.</div>
        </div>
        <div class="solution">
            <span class="icon">🎯</span>
            <div class="label">Multi-Destination Swap</div>
            <div class="desc">Send one payment to multiple recipients simultaneously in their preferred form.</div>
        </div>
    </div>

    <div class="highlight" style="max-width:100%;">
        <strong>All swaps are:</strong> Non-custodial · Atomic execution · Fully traceable · Patent-protected
    </div>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 4</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 5 — HOW IT WORKS + WHY ANGOLA -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>How It Works</h1>

    <p style="font-size:14px;font-weight:500;color:#0F2138;">Five-stage transaction lifecycle — secure, atomic, and non-custodial.</p>

    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:4px;margin:10px 0;">
        <div style="background:#0F2138;color:#fff;padding:8px 4px;text-align:center;font-family:'IBM Plex Sans Condensed',sans-serif;font-weight:600;font-size:10px;text-transform:uppercase;">1. Initiate</div>
        <div style="background:#0F2138;color:#fff;padding:8px 4px;text-align:center;font-family:'IBM Plex Sans Condensed',sans-serif;font-weight:600;font-size:10px;text-transform:uppercase;">2. Hold</div>
        <div style="background:#0F2138;color:#fff;padding:8px 4px;text-align:center;font-family:'IBM Plex Sans Condensed',sans-serif;font-weight:600;font-size:10px;text-transform:uppercase;">3. Process</div>
        <div style="background:#0F2138;color:#fff;padding:8px 4px;text-align:center;font-family:'IBM Plex Sans Condensed',sans-serif;font-weight:600;font-size:10px;text-transform:uppercase;">4. Execute</div>
        <div style="background:#0F2138;color:#fff;padding:8px 4px;text-align:center;font-family:'IBM Plex Sans Condensed',sans-serif;font-weight:600;font-size:10px;text-transform:uppercase;">5. Settle</div>
    </div>

    <p style="font-size:11px;color:#8A96A3;">Value is locked at source before authorisation, eliminating the need for custodial transfers.</p>

    <h2>Why Angola?</h2>

    <p>Angola is a <strong>strategically selected first market</strong> based on regulatory readiness and infrastructure availability.</p>

    <table>
        <thead><tr><th>Indicator</th><th>Value</th></tr></thead>
        <tbody>
            <tr><td><strong>Population</strong></td><td>40.2 million</td></tr>
            <tr><td><strong>Unbanked adults</strong></td><td>26 million</td></tr>
            <tr><td><strong>Mobile users</strong></td><td>30.6 million</td></tr>
            <tr><td><strong>Banking penetration</strong></td><td>28%</td></tr>
            <tr><td><strong>Government beneficiaries</strong></td><td>1.7M households</td></tr>
        </tbody>
    </table>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 5</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 6 — BUSINESS MODEL -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>Business Model</h1>

    <p style="font-size:14px;font-weight:500;color:#0F2138;">Multiple revenue streams across the financial interoperability value chain.</p>

    <table>
        <thead><tr><th>Revenue Stream</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td><strong>Institution Integration</strong></td><td>One-time onboarding fee per institution</td></tr>
            <tr><td><strong>API Subscription</strong></td><td>Monthly/annual access to the interoperability layer</td></tr>
            <tr><td><strong>Transaction Fee</strong></td><td>Per orchestrated swap</td></tr>
            <tr><td><strong>Government Contracts</strong></td><td>Disbursement and social payment programs</td></tr>
            <tr><td><strong>Enterprise White-label</strong></td><td>Licensed deployments for corporates</td></tr>
            <tr><td><strong>Cross-border Premium</strong></td><td>Additional margin on international flows</td></tr>
        </tbody>
    </table>

    <h2>Why VouchMorph Wins</h2>

    <div class="features">
        <div class="feature"><span class="icon">🔗</span><div class="label">Identity-Based</div></div>
        <div class="feature"><span class="icon">🏛️</span><div class="label">Institution Agnostic</div></div>
        <div class="feature"><span class="icon">📈</span><div class="label">Network Effect</div></div>
        <div class="feature"><span class="icon">🔒</span><div class="label">Non-Custodial</div></div>
        <div class="feature"><span class="icon">⚡</span><div class="label">Real-Time</div></div>
        <div class="feature"><span class="icon">🛡️</span><div class="label">Patent Granted</div></div>
    </div>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 6</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 7 — COMPETITIVE MOAT -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>Competitive Moat</h1>

    <p style="font-size:14px;font-weight:500;color:#0F2138;">Six layers of defensibility that make VouchMorph difficult to replicate.</p>

    <table>
        <thead><tr><th>Moat Element</th><th>Why It Matters</th></tr></thead>
        <tbody>
            <tr><td><strong>Patent Granted</strong></td><td>Identity-based routing — legally protected</td></tr>
            <tr><td><strong>First Mover Angola</strong></td><td>Relationships with BNA, ABCCI, commercial banks</td></tr>
            <tr><td><strong>Botswana Sandbox</strong></td><td>Regulatory validation and reference</td></tr>
            <tr><td><strong>Institution Adapters</strong></td><td>Working integrations with multiple rails</td></tr>
            <tr><td><strong>Non-Custodial Model</strong></td><td>No balance-sheet risk — easier compliance</td></tr>
            <tr><td><strong>Strategic Partners</strong></td><td>Intellegere (security) and Malakana (infrastructure)</td></tr>
        </tbody>
    </table>

    <div class="highlight" style="max-width:100%;">
        <strong>VouchMorph is identity-based, non-custodial, and institution-agnostic.</strong> No other player combines all three.
    </div>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 7</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 8 — TIMELINE -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>Market Entry Timeline</h1>

    <p style="font-size:14px;font-weight:500;color:#0F2138;">A clear, phased path to commercial launch and regional expansion.</p>

    <div style="padding-left:14px;border-left:3px solid #8A6D3B;margin:10px 0;">
        <div style="display:flex;gap:12px;margin-bottom:4px;"><span style="font-weight:700;font-size:12px;min-width:70px;color:#0F2138;">Phase 1</span><span style="font-size:10px;color:#8A96A3;min-width:50px;">Q3 2026</span><span style="font-size:12px;color:#4A5A6E;">Botswana Sandbox — Regulatory validation</span></div>
        <div style="display:flex;gap:12px;margin-bottom:4px;"><span style="font-weight:700;font-size:12px;min-width:70px;color:#0F2138;">Phase 2</span><span style="font-size:10px;color:#8A96A3;min-width:50px;">Q3 2026</span><span style="font-size:12px;color:#4A5A6E;">ABCCI Membership — Business ecosystem entry</span></div>
        <div style="display:flex;gap:12px;margin-bottom:4px;"><span style="font-weight:700;font-size:12px;min-width:70px;color:#0F2138;">Phase 3</span><span style="font-size:10px;color:#8A96A3;min-width:50px;">Q3–Q4 2026</span><span style="font-size:12px;color:#4A5A6E;">Angola Market Mission — Stakeholder meetings</span></div>
        <div style="display:flex;gap:12px;margin-bottom:4px;"><span style="font-weight:700;font-size:12px;min-width:70px;color:#0F2138;">Phase 4</span><span style="font-size:10px;color:#8A96A3;min-width:50px;">Q4 2026</span><span style="font-size:12px;color:#4A5A6E;">BNA Sandbox Application — Formal submission</span></div>
        <div style="display:flex;gap:12px;margin-bottom:4px;"><span style="font-weight:700;font-size:12px;min-width:70px;color:#0F2138;">Phase 5</span><span style="font-size:10px;color:#8A96A3;min-width:50px;">Q1 2027</span><span style="font-size:12px;color:#4A5A6E;">Pilot Implementation — First institutions live</span></div>
        <div style="display:flex;gap:12px;margin-bottom:4px;"><span style="font-weight:700;font-size:12px;min-width:70px;color:#0F2138;">Phase 6</span><span style="font-size:10px;color:#8A96A3;min-width:50px;">Q2 2027</span><span style="font-size:12px;color:#4A5A6E;">Commercial Launch — Full operations commence</span></div>
        <div style="display:flex;gap:12px;margin-bottom:4px;"><span style="font-weight:700;font-size:12px;min-width:70px;color:#0F2138;">Phase 7</span><span style="font-size:10px;color:#8A96A3;min-width:50px;">Q3 2027</span><span style="font-size:12px;color:#4A5A6E;">Government Programs — Beneficiary disbursements</span></div>
        <div style="display:flex;gap:12px;"><span style="font-weight:700;font-size:12px;min-width:70px;color:#0F2138;">Phase 8</span><span style="font-size:10px;color:#8A96A3;min-width:50px;">2028</span><span style="font-size:12px;color:#4A5A6E;">SADC Expansion — Botswana, Namibia, Zambia</span></div>
    </div>

    <div class="highlight" style="max-width:100%;">
        <strong>With investment:</strong> 18 months to commercial launch<br>
        <strong>Without investment:</strong> 3–5 years organic growth
    </div>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 8</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 9 — USE OF FUNDS -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>Use of Funds</h1>

    <p style="font-size:14px;font-weight:500;color:#0F2138;">Capital allocation to accelerate commercialisation and regional expansion.</p>

    <table>
        <thead><tr><th>Category</th><th>Focus Area</th><th>Weight</th></tr></thead>
        <tbody>
            <tr><td><strong>Product Development</strong></td><td>Platform enhancement, feature expansion</td><td>~30%</td></tr>
            <tr><td><strong>Market Entry</strong></td><td>Angola commercialisation, regulatory engagement</td><td>~25%</td></tr>
            <tr><td><strong>Team Expansion</strong></td><td>Engineering, operations, compliance</td><td>~20%</td></tr>
            <tr><td><strong>Infrastructure</strong></td><td>Cloud hosting, disaster recovery</td><td>~15%</td></tr>
            <tr><td><strong>Working Capital</strong></td><td>Operational runway and reserves</td><td>~10%</td></tr>
        </tbody>
    </table>

    <p style="font-size:11px;color:#8A96A3;">Detailed budget available upon request. ABCCI covers Angola local costs.</p>

    <div style="background:#F4EFE3;border:1px solid #D3DAD6;padding:12px 16px;margin:10px 0;">
        <div style="font-weight:700;font-size:13px;color:#0F2138;">Investment Summary</div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:4px;font-size:12px;">
            <span><strong>Amount:</strong> Growth capital</span>
            <span><strong>Equity:</strong> 20%</span>
            <span><strong>Valuation:</strong> BWP 7.5M pre-money</span>
            <span><strong>Stage:</strong> Seed / Growth</span>
        </div>
    </div>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 9</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 10 — TEAM -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>Leadership Team</h1>

    <p style="font-size:14px;font-weight:500;color:#0F2138;">A team with deep fintech, regulatory, and infrastructure expertise.</p>

    <table>
        <thead><tr><th>Name</th><th>Role</th><th>Background</th></tr></thead>
        <tbody>
            <tr><td><strong>Magdaline Lewis</strong></td><td>CEO</td><td>Financial services, regulatory strategy</td></tr>
            <tr><td><strong>Marvin Sehunelo</strong></td><td>Product Manager</td><td>System architecture, platform development</td></tr>
            <tr><td><strong>Dr Tshenolo Kealeboga</strong></td><td>Operations Manager</td><td>Operations, risk management</td></tr>
            <tr><td><strong>Zhetu Mabusa</strong></td><td>Compliance</td><td>AML/CFT, regulatory compliance</td></tr>
        </tbody>
    </table>

    <h2>Strategic Partners</h2>

    <table>
        <thead><tr><th>Partner</th><th>Role</th></tr></thead>
        <tbody>
            <tr><td><strong>Intellegere Holdings</strong></td><td>Cybersecurity &amp; Engineering — CEH-certified, 18 years</td></tr>
            <tr><td><strong>Malakana Enterprises</strong></td><td>Infrastructure — 99.99% SLA, disaster recovery</td></tr>
            <tr><td><strong>ABCCI</strong></td><td>Angola Market Entry — Local partnerships, regulatory access</td></tr>
        </tbody>
    </table>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 10</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 11 — INVESTMENT PROPOSAL -->
<!-- ============================================================ -->
<div class="page">
    <div class="brand" style="margin-bottom:12px;">VOUCHMORPH <span>·</span></div>

    <h1>Investment Proposal</h1>

    <p style="font-size:14px;font-weight:500;color:#0F2138;">A strategic opportunity to participate in Africa's next-generation financial infrastructure.</p>

    <div style="border:2px solid #8A6D3B;padding:16px 20px;background:#F4EFE3;margin:12px 0;text-align:center;">
        <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-size:28px;font-weight:700;color:#0F2138;">BWP 1,500,000</div>
        <div style="font-size:13px;color:#4A5A6E;">for 20% Equity</div>
        <div style="font-size:11px;color:#8A96A3;">Pre-money valuation: BWP 7.5 million</div>
    </div>

    <table>
        <thead><tr><th>Item</th><th>Detail</th></tr></thead>
        <tbody>
            <tr><td><strong>Investment Amount</strong></td><td>Growth capital for commercialisation</td></tr>
            <tr><td><strong>Equity Offered</strong></td><td>20%</td></tr>
            <tr><td><strong>Pre-Money Valuation</strong></td><td>BWP 7.5 million</td></tr>
            <tr><td><strong>Funding Stage</strong></td><td>Seed / Growth</td></tr>
            <tr><td><strong>Purpose</strong></td><td>Angola commercialisation, SADC expansion</td></tr>
        </tbody>
    </table>

    <div class="highlight" style="max-width:100%;border-left-color:#24513A;">
        <strong>Investor Benefits:</strong><br>
        Board representation · Information rights · Pre-emptive rights<br>
        Tag-along and drag-along rights · Anti-dilution protection
    </div>

    <div class="footer-text">
        <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
        <span>Page 11</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- PAGE 12 — CONCLUSION -->
<!-- ============================================================ -->
<div class="page" style="display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;">
    <div style="width:100%;text-align:left;margin-bottom:20px;">
        <div class="brand">VOUCHMORPH <span>·</span></div>
    </div>

    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;">
        <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-size:38px;font-weight:700;color:#0F2138;line-height:1.2;max-width:450px;">
            Africa has already invested billions building payment rails.
        </div>

        <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-size:26px;font-weight:700;color:#8A6D3B;margin-top:12px;">
            VouchMorph is building the identity layer.
        </div>

        <div style="font-size:15px;color:#4A5A6E;max-width:400px;margin-top:12px;line-height:1.6;">
            Our first deployment is Angola.<br>
            Our ambition is every interoperable payment network in Africa.
        </div>

        <div style="border-top:3px solid #8A6D3B;padding-top:16px;margin-top:24px;max-width:350px;">
            <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-size:16px;font-weight:700;color:#0F2138;">
                VouchMorph is not a payments company.
            </div>
            <div style="font-family:'IBM Plex Sans Condensed',sans-serif;font-size:16px;font-weight:700;color:#8A6D3B;">
                VouchMorph is the access layer of the African financial system.
            </div>
        </div>
    </div>

    <div style="width:100%;text-align:center;border-top:1px solid #D3DAD6;padding-top:8px;margin-top:16px;">
        <div style="font-size:10px;color:#4A5A6E;">VouchMorph Proprietary Limited · CIPA BW00009655259</div>
        <div style="font-size:8px;color:#8A96A3;">Gaborone, Botswana · © 2026 VouchMorph · All rights reserved</div>
        <div style="font-size:8px;color:#7A2118;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;margin-top:2px;">Proprietary &amp; Confidential</div>
        <div style="font-size:9px;color:#8A96A3;margin-top:2px;">Page 12</div>
    </div>
</div>

</body>
</html>
