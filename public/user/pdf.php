<?php
/**
 * VouchMorph Investment Memorandum
 * Confidential · Prepared for Strategic Investors
 */

// Check if dompdf is available
$useDompdf = false;
$dompdfAvailable = false;

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    try {
        require_once __DIR__ . '/../../vendor/autoload.php';
        if (class_exists('Dompdf\Dompdf')) {
            $dompdfAvailable = true;
            $useDompdf = true;
        }
    } catch (Exception $e) {
        $dompdfAvailable = false;
        $useDompdf = false;
    }
}

if (isset($_GET['download']) && $useDompdf && $dompdfAvailable) {
    try {
        $html = getMemoHTML();
        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream('VouchMorph_Investment_Memorandum.pdf', ['Attachment' => true]);
        exit;
    } catch (Exception $e) {
        $useDompdf = false;
        $dompdfAvailable = false;
    }
}

echo getMemoHTML();


function getMemoHTML(): string
{
    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph · Investment Memorandum</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink-900:      #0F2138;
            --ink-700:      #1D3557;
            --ink-500:      #4A5A6E;
            --ink-300:      #8A96A3;
            --line:         #D3DAD6;
            --brass:        #8A6D3B;
            --brass-tint:   #F4EFE3;
            --seal-red:     #7A2118;
            --ledger-green: #24513A;
            --panel:        #FFFFFF;
            --paper:        #EEF1EF;
            --max-width:    1100px;
            --f-body:       "IBM Plex Sans", sans-serif;
            --f-cond:       "IBM Plex Sans Condensed", sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            font-size: 14px;
            line-height: 1.7;
            padding: 24px;
        }

        .memo-container {
            max-width: var(--max-width);
            margin: 0 auto;
            background: var(--panel);
            padding: 48px 60px;
            box-shadow: 0 4px 24px rgba(15,33,56,0.06);
        }

        /* ----- COVER ----- */
        .cover {
            text-align: center;
            padding: 50px 40px 40px;
            border-bottom: 3px solid var(--brass);
            margin-bottom: 32px;
        }

        .cover .logo {
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 28px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--ink-900);
        }

        .cover .logo span { color: var(--brass); }

        .cover .tagline {
            font-family: var(--f-cond);
            font-weight: 500;
            font-size: 16px;
            color: var(--ink-500);
            letter-spacing: 0.06em;
            margin-top: 4px;
        }

        .cover .title {
            font-family: var(--f-cond);
            font-size: 38px;
            font-weight: 700;
            color: var(--ink-900);
            margin-top: 28px;
            letter-spacing: 0.02em;
        }

        .cover .subtitle {
            font-size: 17px;
            color: var(--ink-500);
            margin-top: 6px;
            font-weight: 300;
        }

        .cover .meta {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-top: 28px;
            font-size: 13px;
            color: var(--ink-500);
            flex-wrap: wrap;
        }

        .cover .meta strong { color: var(--ink-900); }

        .cover .confidential {
            margin-top: 28px;
            font-size: 11px;
            color: var(--seal-red);
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        /* ----- TYPOGRAPHY ----- */
        h1 {
            font-family: var(--f-cond);
            font-size: 26px;
            font-weight: 700;
            color: var(--ink-900);
            margin-top: 36px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--line);
        }

        h2 {
            font-family: var(--f-cond);
            font-size: 19px;
            font-weight: 700;
            color: var(--ink-700);
            margin-top: 24px;
            margin-bottom: 10px;
        }

        p {
            margin-bottom: 12px;
            color: var(--ink-500);
            max-width: 800px;
        }

        .highlight-box {
            background: var(--brass-tint);
            border-left: 4px solid var(--brass);
            padding: 14px 20px;
            margin: 16px 0;
            font-size: 15px;
            color: var(--ink-700);
            max-width: 800px;
        }

        .highlight-box strong { color: var(--ink-900); }

        /* ----- METRICS GRID ----- */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin: 16px 0 20px;
        }

        .metric-card {
            background: var(--paper);
            border: 1px solid var(--line);
            padding: 14px 16px;
            text-align: center;
        }

        .metric-card .number {
            font-family: var(--f-cond);
            font-size: 26px;
            font-weight: 700;
            color: var(--ink-900);
        }

        .metric-card .label {
            font-size: 10px;
            text-transform: uppercase;
            color: var(--ink-300);
            letter-spacing: 0.06em;
            font-weight: 600;
            margin-top: 2px;
        }

        /* ----- TABLES ----- */
        .table-wrap {
            overflow-x: auto;
            margin: 12px 0 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th {
            background: var(--paper);
            color: var(--ink-500);
            padding: 10px 14px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            border-bottom: 2px solid var(--line);
        }

        td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            font-size: 13px;
            color: var(--ink-500);
        }

        .table-highlight td {
            background: var(--brass-tint);
            font-weight: 600;
            color: var(--ink-900);
        }

        /* ----- TIMELINE ----- */
        .timeline {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin: 12px 0 16px;
            padding-left: 20px;
            border-left: 3px solid var(--brass);
        }

        .timeline-item {
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }

        .timeline-item .phase {
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 13px;
            color: var(--ink-900);
            min-width: 100px;
        }

        .timeline-item .desc {
            color: var(--ink-500);
            font-size: 13px;
        }

        .timeline-item .date {
            font-size: 11px;
            color: var(--ink-300);
            font-weight: 500;
            min-width: 80px;
        }

        /* ----- FEATURE GRID ----- */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin: 12px 0 16px;
        }

        .feature-card {
            background: var(--paper);
            border: 1px solid var(--line);
            padding: 14px 16px;
        }

        .feature-card .icon { font-size: 22px; display: block; margin-bottom: 4px; }
        .feature-card .label {
            font-weight: 600;
            font-size: 14px;
            color: var(--ink-900);
        }
        .feature-card .desc {
            font-size: 12px;
            color: var(--ink-500);
            margin-top: 2px;
        }

        /* ----- PARTNER LOGOS ----- */
        .partners {
            display: flex;
            justify-content: center;
            gap: 40px;
            flex-wrap: wrap;
            padding: 16px;
            background: var(--paper);
            border: 1px solid var(--line);
            margin: 12px 0 16px;
        }

        .partner {
            text-align: center;
        }

        .partner .name {
            font-weight: 700;
            font-size: 15px;
            color: var(--ink-900);
        }

        .partner .role {
            font-size: 11px;
            color: var(--ink-300);
            letter-spacing: 0.04em;
        }

        /* ----- FOOTER ----- */
        .footer {
            border-top: 2px solid var(--line);
            padding-top: 20px;
            margin-top: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 11px;
            color: var(--ink-300);
        }

        .footer .confidential {
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--seal-red);
            font-size: 10px;
        }

        /* ----- RESPONSIVE ----- */
        @media (max-width: 768px) {
            .memo-container { padding: 24px 20px; }
            .cover { padding: 30px 20px; }
            .cover .title { font-size: 28px; }
            .cover .meta { gap: 16px; flex-direction: column; align-items: center; }
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .feature-grid { grid-template-columns: 1fr; }
            .timeline-item { flex-direction: column; gap: 2px; }
            .timeline-item .phase { min-width: auto; }
            .timeline-item .date { min-width: auto; }
            .partners { gap: 20px; }
        }

        @media print {
            body { background: #fff; padding: 0; }
            .memo-container { box-shadow: none; border: none; padding: 40px 50px; }
            .no-print { display: none !important; }
            .metric-card { background: #EEF1EF !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .highlight-box { background: #F4EFE3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .table-highlight td { background: #F4EFE3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            th { background: #EEF1EF !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .partners { background: #EEF1EF !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        .download-bar {
            max-width: var(--max-width);
            margin: 0 auto 16px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 8px 0;
        }

        .btn {
            height: 36px;
            padding: 0 20px;
            font-size: 12px;
            font-weight: 600;
            font-family: var(--f-cond);
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            box-sizing: border-box;
        }

        .btn-primary {
            background: var(--ink-900);
            color: #fff;
            border-color: var(--ink-900);
        }
        .btn-primary:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--line);
            color: var(--ink-500);
        }
        .btn-outline:hover { border-color: var(--brass); color: var(--ink-900); background: var(--brass-tint); }
    </style>
</head>
<body>

    <!-- ============================================================ -->
    <!-- DOWNLOAD BAR -->
    <!-- ============================================================ -->
    <div class="download-bar no-print">
        <button class="btn btn-primary" onclick="window.print()">Print / PDF</button>
        <a href="?download=1" class="btn btn-primary" style="background:var(--ledger-green);border-color:var(--ledger-green);">Download PDF</a>
    </div>

    <!-- ============================================================ -->
    <!-- MEMORANDUM CONTENT -->
    <!-- ============================================================ -->
    <div class="memo-container">

        <!-- ============================================================ -->
        <!-- COVER PAGE -->
        <!-- ============================================================ -->
        <div class="cover">
            <div class="logo">VOUCHMORPH <span>·</span></div>
            <div class="tagline">Building Africa\'s Identity-Based Financial Access Network</div>

            <div class="title">Investment Memorandum</div>
            <div class="subtitle">Confidential · For Strategic Investors</div>

            <div class="meta">
                <span><strong>Company:</strong> VouchMorph (Pty) Ltd</span>
                <span><strong>Headquarters:</strong> Gaborone, Botswana</span>
                <span><strong>First Market:</strong> Republic of Angola</span>
                <span><strong>Stage:</strong> Regulatory Sandbox</span>
            </div>

            <div class="confidential">Proprietary &amp; Confidential · 2026</div>
        </div>

        <!-- ============================================================ -->
        <!-- 01 · EXECUTIVE SUMMARY -->
        <!-- ============================================================ -->
        <h1>01 · Executive Summary</h1>

        <p><strong>VouchMorph is a non-custodial financial interoperability layer</strong> that connects banks, mobile money networks, and teller services into a unified access network for money.</p>

        <p>Unlike traditional systems that move funds between accounts, VouchMorph enables <strong>value access through identity-bound messaging</strong> — allowing seamless transactions across institutions without requiring accounts at either end.</p>

        <p>The company has completed its MVP, secured a regulatory sandbox position in Botswana, and is now executing its entry into Angola — a market of <strong>40 million people</strong> with <strong>26 million unbanked adults</strong> and a government actively pursuing digital financial inclusion.</p>

        <div class="highlight-box">
            <strong>VouchMorph is raising growth capital</strong> to commercialise in Angola, expand across SADC, and establish Africa\'s first identity-based financial access network.
        </div>

        <!-- ============================================================ -->
        <!-- 02 · INVESTMENT HIGHLIGHTS -->
        <!-- ============================================================ -->
        <h1>02 · Investment Highlights</h1>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="number">40M</div>
                <div class="label">Target Market Population</div>
            </div>
            <div class="metric-card">
                <div class="number">26M</div>
                <div class="label">Unbanked Adults</div>
            </div>
            <div class="metric-card">
                <div class="number">30M+</div>
                <div class="label">Mobile Users</div>
            </div>
            <div class="metric-card">
                <div class="number">80%</div>
                <div class="label">Informal Economy</div>
            </div>
            <div class="metric-card">
                <div class="number">BWP 7.5M</div>
                <div class="label">Pre-Money Valuation</div>
            </div>
            <div class="metric-card">
                <div class="number">20%</div>
                <div class="label">Equity Offered</div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 03 · STRATEGIC PARTNERS -->
        <!-- ============================================================ -->
        <h1>03 · Strategic Partners</h1>

        <p>VouchMorph has established strategic partnerships with leading technology and infrastructure firms to ensure world-class delivery.</p>

        <div class="partners">
            <div class="partner">
                <div class="name">Intellegere Holdings</div>
                <div class="role">Cybersecurity &amp; Software Engineering</div>
            </div>
            <div class="partner">
                <div class="name">Malakana Enterprises</div>
                <div class="role">Networking &amp; Cloud Infrastructure</div>
            </div>
            <div class="partner">
                <div class="name">ABCCI</div>
                <div class="role">Angola Market Entry &amp; Partnership</div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 04 · WHY ANGOLA? -->
        <!-- ============================================================ -->
        <h1>04 · Why Angola?</h1>

        <p>Angola is not a random choice. It is a <strong>strategically selected first market</strong> based on regulatory readiness, infrastructure availability, and addressable population.</p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Indicator</th><th>Value</th><th>Implication</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Population</strong></td><td>40.2 million</td><td>Large addressable market</td></tr>
                    <tr><td><strong>Unbanked adults</strong></td><td>26 million</td><td>Target users for identity payments</td></tr>
                    <tr><td><strong>Mobile users</strong></td><td>30.6 million</td><td>Digital access exists</td></tr>
                    <tr><td><strong>Banking penetration</strong></td><td>28%</td><td>Vast underserved population</td></tr>
                    <tr><td><strong>Government beneficiaries</strong></td><td>1.7M households</td><td>Immediate use case</td></tr>
                </tbody>
            </table>
        </div>

        <div class="highlight-box">
            <strong>The Angola-Botswana bilateral relationship is at an all-time high.</strong> The Presidents have committed to deepening economic cooperation — VouchMorph is the financial infrastructure that delivers on that commitment.
        </div>

        <!-- ============================================================ -->
        <!-- 05 · WHAT VOUCHMORPH DOES -->
        <!-- ============================================================ -->
        <h1>05 · What VouchMorph Does</h1>

        <p><strong>Simple explanation:</strong> VouchMorph lets anyone with a phone number, email, or national ID send and receive money across any bank or mobile money network — <strong>without needing an account at either end</strong>.</p>

        <div class="feature-grid">
            <div class="feature-card">
                <span class="icon">📤</span>
                <div class="label">Send to Identity</div>
                <div class="desc">Send money to a phone number, email, or national ID</div>
            </div>
            <div class="feature-card">
                <span class="icon">🏦</span>
                <div class="label">Any Institution</div>
                <div class="desc">Works across banks, mobile money, and teller services</div>
            </div>
            <div class="feature-card">
                <span class="icon">💳</span>
                <div class="label">Cash Out Anywhere</div>
                <div class="desc">Recipients can claim at ATMs, agents, or mobile wallets</div>
            </div>
            <div class="feature-card">
                <span class="icon">🔒</span>
                <div class="label">Non-Custodial</div>
                <div class="desc">VouchMorph never holds client funds — no balance-sheet risk</div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 06 · THE PROBLEM WE SOLVE -->
        <!-- ============================================================ -->
        <h1>06 · The Problem We Solve</h1>

        <p>Today, sending money across institutions in Africa is fragmented, slow, and requires accounts on both ends.</p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Problem</th><th>Impact</th></tr>
                </thead>
                <tbody>
                    <tr><td>Bank-to-bank transfers</td><td>Require accounts at both banks</td></tr>
                    <tr><td>Mobile money silos</td><td>Can\'t send across networks</td></tr>
                    <tr><td>Bank-to-mobile money</td><td>Limited or non-existent</td></tr>
                    <tr><td>Government disbursements</td><td>Millions of beneficiaries without accounts</td></tr>
                </tbody>
            </table>
        </div>

        <div class="highlight-box">
            <strong>VouchMorph solves all of these.</strong> One platform — any identity, any institution, any network.
        </div>

        <!-- ============================================================ -->
        <!-- 07 · BUSINESS MODEL -->
        <!-- ============================================================ -->
        <h1>07 · Business Model</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Revenue Stream</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Institution Integration</strong></td><td>One-time onboarding fee per institution</td></tr>
                    <tr><td><strong>API Subscription</strong></td><td>Monthly/annual access to the interoperability layer</td></tr>
                    <tr><td><strong>Transaction Fee</strong></td><td>Per orchestrated swap</td></tr>
                    <tr><td><strong>Government Contracts</strong></td><td>Disbursement and social payment programs</td></tr>
                    <tr><td><strong>Enterprise White-label</strong></td><td>Licensed deployments for corporates</td></tr>
                    <tr><td><strong>Cross-border Premium</strong></td><td>Additional margin on international flows</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 08 · WHY VOUCHMORPH WINS -->
        <!-- ============================================================ -->
        <h1>08 · Why VouchMorph Wins</h1>

        <div class="feature-grid">
            <div class="feature-card">
                <span class="icon">🔗</span>
                <div class="label">Identity-Based</div>
                <div class="desc">Send to a phone number, not an account number</div>
            </div>
            <div class="feature-card">
                <span class="icon">🏛️</span>
                <div class="label">Institution Agnostic</div>
                <div class="desc">Works across any bank, MNO, or payment service</div>
            </div>
            <div class="feature-card">
                <span class="icon">📈</span>
                <div class="label">Network Effect</div>
                <div class="desc">Each institution added increases value for all</div>
            </div>
            <div class="feature-card">
                <span class="icon">🔒</span>
                <div class="label">Non-Custodial</div>
                <div class="desc">No client funds held — lower regulatory burden</div>
            </div>
            <div class="feature-card">
                <span class="icon">⚡</span>
                <div class="label">Real-Time</div>
                <div class="desc">Instant execution with delayed net settlement</div>
            </div>
            <div class="feature-card">
                <span class="icon">🛡️</span>
                <div class="label">Patent Pending</div>
                <div class="desc">Unique identity-routing technology</div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 09 · COMPETITIVE MOAT -->
        <!-- ============================================================ -->
        <h1>09 · Competitive Moat</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Moat Element</th><th>Why It Matters</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Patent Pending</strong></td><td>Identity-based routing, settlement architecture</td></tr>
                    <tr><td><strong>First Mover Angola</strong></td><td>Established relationships with BNA, ABCCI</td></tr>
                    <tr><td><strong>Botswana Sandbox</strong></td><td>Regulatory validation and reference</td></tr>
                    <tr><td><strong>Institution Adapters</strong></td><td>Working integrations with multiple rails</td></tr>
                    <tr><td><strong>Non-Custodial Model</strong></td><td>No balance-sheet risk — easier compliance</td></tr>
                    <tr><td><strong>Strategic Partners</strong></td><td>Intellegere (security), Malakana (infrastructure)</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 10 · MARKET ENTRY TIMELINE -->
        <!-- ============================================================ -->
        <h1>10 · Market Entry Timeline</h1>

        <div class="timeline">
            <div class="timeline-item">
                <span class="phase">Phase 1</span>
                <span class="date">Q3 2026</span>
                <span class="desc"><strong>Botswana Sandbox</strong> — Regulatory validation</span>
            </div>
            <div class="timeline-item">
                <span class="phase">Phase 2</span>
                <span class="date">Q3 2026</span>
                <span class="desc"><strong>ABCCI Membership</strong> — Business ecosystem entry</span>
            </div>
            <div class="timeline-item">
                <span class="phase">Phase 3</span>
                <span class="date">Q3–Q4 2026</span>
                <span class="desc"><strong>Angola Market Mission</strong> — Stakeholder meetings, bank workshops</span>
            </div>
            <div class="timeline-item">
                <span class="phase">Phase 4</span>
                <span class="date">Q4 2026</span>
                <span class="desc"><strong>BNA Sandbox Application</strong> — Formal regulatory submission</span>
            </div>
            <div class="timeline-item">
                <span class="phase">Phase 5</span>
                <span class="date">Q1 2027</span>
                <span class="desc"><strong>Pilot Implementation</strong> — First institutions live</span>
            </div>
            <div class="timeline-item">
                <span class="phase">Phase 6</span>
                <span class="date">Q2 2027</span>
                <span class="desc"><strong>Commercial Launch</strong> — Full operations commence</span>
            </div>
            <div class="timeline-item">
                <span class="phase">Phase 7</span>
                <span class="date">Q3 2027</span>
                <span class="desc"><strong>Government Programs</strong> — Beneficiary disbursements</span>
            </div>
            <div class="timeline-item">
                <span class="phase">Phase 8</span>
                <span class="date">2028</span>
                <span class="desc"><strong>SADC Expansion</strong> — Botswana, Namibia, Zambia</span>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 11 · TEAM -->
        <!-- ============================================================ -->
        <h1>11 · Leadership Team</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Name</th><th>Role</th><th>Background</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Magdaline Lewis</strong></td><td>CEO, VouchMorph</td><td>Financial services, regulatory strategy</td></tr>
                    <tr><td><strong>Marvin Sehunelo</strong></td><td>Product Manager</td><td>System architecture, platform development</td></tr>
                    <tr><td><strong>Dr Tshenolo Kealeboga</strong></td><td>Operations Manager</td><td>Operations, risk management</td></tr>
                    <tr><td><strong>Zhetu Mabusa</strong></td><td>Compliance</td><td>AML/CFT, regulatory compliance</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 12 · STRATEGIC PARTNERS DETAILED -->
        <!-- ============================================================ -->
        <h1>12 · Strategic Partners</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Partner</th><th>Role</th><th>Capability</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Intellegere Holdings</strong></td><td>Cybersecurity &amp; Engineering</td><td>Security governance, architecture, CEH-certified team</td></tr>
                    <tr><td><strong>Malakana Enterprises</strong></td><td>Infrastructure</td><td>Cloud hosting, 99.99% SLA, disaster recovery</td></tr>
                    <tr><td><strong>ABCCI</strong></td><td>Angola Market Entry</td><td>Local partnerships, regulatory access, business networks</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 13 · USE OF FUNDS -->
        <!-- ============================================================ -->
        <h1>13 · Use of Funds</h1>

        <p>The investment will be allocated to accelerate VouchMorph\'s commercialisation and regional expansion.</p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Category</th><th>Focus Area</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Product Development</strong></td><td>Platform enhancement, feature expansion, security hardening</td></tr>
                    <tr><td><strong>Market Entry</strong></td><td>Angola commercialisation, regulatory engagement, institutional partnerships</td></tr>
                    <tr><td><strong>Team Expansion</strong></td><td>Engineering, operations, compliance, and business development</td></tr>
                    <tr><td><strong>Infrastructure</strong></td><td>Cloud hosting, disaster recovery, security operations</td></tr>
                    <tr><td><strong>Working Capital</strong></td><td>Operational runway and strategic reserves</td></tr>
                </tbody>
            </table>
        </div>

        <p style="font-size:12px;color:var(--ink-300);">Detailed budget breakdown available upon request.</p>

        <!-- ============================================================ -->
        <!-- 14 · INVESTMENT PROPOSAL -->
        <!-- ============================================================ -->
        <h1>14 · Investment Proposal</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Item</th><th>Detail</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Investment Amount</strong></td><td>Growth capital for commercialisation</td></tr>
                    <tr><td><strong>Equity Offered</strong></td><td>20%</td></tr>
                    <tr><td><strong>Pre-Money Valuation</strong></td><td>BWP 7.5 million</td></tr>
                    <tr><td><strong>Funding Stage</strong></td><td>Seed / Growth</td></tr>
                    <tr><td><strong>Purpose</strong></td><td>Angola commercialisation, SADC expansion</td></tr>
                </tbody>
            </table>
        </div>

        <div class="highlight-box" style="border-left-color:var(--ledger-green);">
            <strong>Investor Benefits:</strong> Board representation, information rights, pre-emptive rights, tag-along and drag-along rights, anti-dilution protection. Full terms in Shareholders\' Agreement.
        </div>

        <!-- ============================================================ -->
        <!-- 15 · CONCLUSION -->
        <!-- ============================================================ -->
        <h1>15 · Conclusion</h1>

        <p><strong>Africa has already invested billions building payment rails.</strong></p>

        <p>VouchMorph is building the <strong>identity layer</strong> that allows every African to use them.</p>

        <p>Our first deployment is Angola. Our ambition is every interoperable payment network in Africa.</p>

        <div class="highlight-box" style="font-size:16px;text-align:center;border-left-color:var(--ledger-green);">
            <strong>VouchMorph is not a payments company.</strong><br>
            <strong>VouchMorph is the access layer of the African financial system.</strong>
        </div>

        <!-- ============================================================ -->
        <!-- FOOTER -->
        <!-- ============================================================ -->
        <div class="footer">
            <div>
                <strong>VouchMorph Proprietary Limited</strong><br>
                Registration: CIPA BW00009655259<br>
                Gaborone, Botswana
            </div>
            <div style="text-align:right;">
                <div class="confidential">Proprietary &amp; Confidential</div>
                <div style="color:var(--ink-300);font-size:10px;margin-top:4px;">© 2026 VouchMorph · All rights reserved.</div>
            </div>
        </div>

    </div>

</body>
</html>';
}
?>
