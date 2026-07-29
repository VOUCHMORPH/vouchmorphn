<?php
/**
 * VouchMorph Investment Memorandum
 * Series Seed — BWP 1,500,000 for 20% Equity
 * Prepared for Institutional Investors
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
        /* ============================================================
           PROFESSIONAL INVESTMENT MEMORANDUM STYLES
           Clean · Institutional · Readable
           ============================================================ */
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
            --green-tint:   #E5EEE7;
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
            padding: 60px 40px 50px;
            border-bottom: 3px solid var(--brass);
            margin-bottom: 32px;
        }

        .cover .logo {
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 32px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--ink-900);
        }

        .cover .logo span { color: var(--brass); }

        .cover .tagline {
            font-family: var(--f-cond);
            font-weight: 500;
            font-size: 18px;
            color: var(--ink-500);
            letter-spacing: 0.06em;
            margin-top: 6px;
        }

        .cover .title {
            font-family: var(--f-cond);
            font-size: 42px;
            font-weight: 700;
            color: var(--ink-900);
            margin-top: 30px;
            letter-spacing: 0.02em;
        }

        .cover .subtitle {
            font-size: 18px;
            color: var(--ink-500);
            margin-top: 8px;
            font-weight: 300;
        }

        .cover .investment-box {
            display: inline-block;
            border: 2px solid var(--brass);
            padding: 20px 50px;
            margin-top: 30px;
            background: var(--brass-tint);
        }

        .cover .investment-box .amount {
            font-family: var(--f-cond);
            font-size: 36px;
            font-weight: 700;
            color: var(--ink-900);
        }

        .cover .investment-box .label {
            font-size: 14px;
            color: var(--ink-500);
            font-weight: 500;
            margin-top: 2px;
        }

        .cover .meta {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-top: 30px;
            font-size: 13px;
            color: var(--ink-500);
            flex-wrap: wrap;
        }

        .cover .meta strong { color: var(--ink-900); }

        .cover .confidential {
            margin-top: 30px;
            font-size: 11px;
            color: var(--seal-red);
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        /* ----- TYPOGRAPHY ----- */
        h1 {
            font-family: var(--f-cond);
            font-size: 28px;
            font-weight: 700;
            color: var(--ink-900);
            margin-top: 40px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--line);
        }

        h2 {
            font-family: var(--f-cond);
            font-size: 20px;
            font-weight: 700;
            color: var(--ink-700);
            margin-top: 28px;
            margin-bottom: 10px;
        }

        h3 {
            font-family: var(--f-cond);
            font-size: 16px;
            font-weight: 600;
            color: var(--ink-700);
            margin-top: 18px;
            margin-bottom: 8px;
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
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
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
            font-size: 28px;
            font-weight: 700;
            color: var(--ink-900);
        }

        .metric-card .label {
            font-size: 11px;
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
            .cover .investment-box { padding: 14px 30px; }
            .cover .investment-box .amount { font-size: 28px; }
            .cover .meta { gap: 16px; flex-direction: column; align-items: center; }
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .feature-grid { grid-template-columns: 1fr; }
            .timeline-item { flex-direction: column; gap: 2px; }
            .timeline-item .phase { min-width: auto; }
            .timeline-item .date { min-width: auto; }
        }

        @media print {
            body { background: #fff; padding: 0; }
            .memo-container { box-shadow: none; border: none; padding: 40px 50px; }
            .no-print { display: none !important; }
            .metric-card { background: #EEF1EF !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .highlight-box { background: #F4EFE3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .table-highlight td { background: #F4EFE3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            th { background: #EEF1EF !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .cover .investment-box { background: #F4EFE3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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
        <button class="btn btn-primary" onclick="window.print()">📄 Print / PDF</button>
        <a href="?download=1" class="btn btn-primary" style="background:var(--ledger-green);border-color:var(--ledger-green);">⬇ Download PDF</a>
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
            <div class="tagline">Building Africa's Identity-Based Financial Access Network</div>

            <div class="title">Investment Memorandum</div>
            <div class="subtitle">Series Seed · Strategic Investment Opportunity</div>

            <div class="investment-box">
                <div class="amount">BWP 1,500,000</div>
                <div class="label">for 20% Equity</div>
            </div>

            <div class="meta">
                <span><strong>Company:</strong> VouchMorph (Pty) Ltd</span>
                <span><strong>Country:</strong> Botswana</span>
                <span><strong>First Market:</strong> Angola</span>
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
            <strong>VouchMorph is raising BWP 1,500,000 for 20% equity</strong> to commercialise in Angola, expand across SADC, and establish Africa's first identity-based financial access network.
        </div>

        <!-- ============================================================ -->
        <!-- 02 · INVESTMENT SNAPSHOT -->
        <!-- ============================================================ -->
        <h1>02 · Investment Snapshot</h1>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="number">BWP 1.5M</div>
                <div class="label">Investment Required</div>
            </div>
            <div class="metric-card">
                <div class="number">20%</div>
                <div class="label">Equity Offered</div>
            </div>
            <div class="metric-card">
                <div class="number">BWP 7.5M</div>
                <div class="label">Pre-Money Valuation</div>
            </div>
            <div class="metric-card">
                <div class="number">18</div>
                <div class="label">Months to Commercial Launch</div>
            </div>
            <div class="metric-card">
                <div class="number">40M</div>
                <div class="label">Target Market Population</div>
            </div>
            <div class="metric-card">
                <div class="number">26M</div>
                <div class="label">Unbanked Adults</div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 03 · WHY NOW -->
        <!-- ============================================================ -->
        <h1>03 · Why Now?</h1>

        <p>Angola has already built the infrastructure. The missing piece is the <strong>identity layer</strong>.</p>

        <div class="feature-grid">
            <div class="feature-card">
                <span class="icon">🏦</span>
                <div class="label">KWiK Instant Payment</div>
                <div class="desc">BNA's real-time payment rail already deployed</div>
            </div>
            <div class="feature-card">
                <span class="icon">💳</span>
                <div class="label">Multicaixa Network</div>
                <div class="desc">National card and ATM network operated by EMIS</div>
            </div>
            <div class="feature-card">
                <span class="icon">📱</span>
                <div class="label">Mobile Money</div>
                <div class="desc">Africell, Unitel, Movicel — 30M+ mobile users</div>
            </div>
            <div class="feature-card">
                <span class="icon">⚖️</span>
                <div class="label">Regulatory Sandbox</div>
                <div class="desc">BNA actively accepting fintech applications</div>
            </div>
            <div class="feature-card">
                <span class="icon">📋</span>
                <div class="label">Startup Law</div>
                <div class="desc">Legal framework for innovative enterprises</div>
            </div>
            <div class="feature-card">
                <span class="icon">👥</span>
                <div class="label">Political Will</div>
                <div class="desc">Presidential commitment to digital inclusion</div>
            </div>
        </div>

        <div class="highlight-box">
            <strong>VouchMorph does not compete with KWiK.</strong> It expands KWiK — enabling anyone with a phone number, email, or national ID to receive value, even without a bank account.
        </div>

        <!-- ============================================================ -->
        <!-- 04 · THE ANGOLA OPPORTUNITY -->
        <!-- ============================================================ -->
        <h1>04 · The Angola Opportunity</h1>

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
                    <tr><td><strong>Informal economy</strong></td><td>80% of GDP</td><td>Cash-based, needs digital access</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 05 · MARKET ENTRY TIMELINE -->
        <!-- ============================================================ -->
        <h1>05 · Market Entry Timeline</h1>

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
        <!-- 06 · WHAT VOUCHMORPH DOES -->
        <!-- ============================================================ -->
        <h1>06 · What VouchMorph Does</h1>

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
        <!-- 07 · THE PROBLEM WE SOLVE -->
        <!-- ============================================================ -->
        <h1>07 · The Problem We Solve</h1>

        <p>Today, sending money across institutions in Africa is fragmented, slow, and requires accounts on both ends.</p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Problem</th><th>Impact</th></tr>
                </thead>
                <tbody>
                    <tr><td>Bank-to-bank transfers</td><td>Require accounts at both banks</td></tr>
                    <tr><td>Mobile money silos</td><td>Can't send from Airtel to Movicel</td></tr>
                    <tr><td>Bank-to-mobile money</td><td>Limited or non-existent</td></tr>
                    <tr><td>Government disbursements</td><td>Millions of beneficiaries without accounts</td></tr>
                    <tr><td>Remittances</td><td>High fees, slow settlement</td></tr>
                </tbody>
            </table>
        </div>

        <div class="highlight-box">
            <strong>VouchMorph solves all of these.</strong> One platform — any identity, any institution, any network.
        </div>

        <!-- ============================================================ -->
        <!-- 08 · BUSINESS MODEL -->
        <!-- ============================================================ -->
        <h1>08 · Business Model</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Revenue Stream</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Institution Integration</strong></td><td>One-time onboarding fee per institution</td></tr>
                    <tr><td><strong>API Subscription</strong></td><td>Monthly/annual access to the interoperability layer</td></tr>
                    <tr><td><strong>Transaction Fee</strong></td><td>Per orchestrated swap (≈BWP 2–5 per transaction)</td></tr>
                    <tr><td><strong>Government Contracts</strong></td><td>Disbursement and social payment programs</td></tr>
                    <tr><td><strong>Enterprise White-label</strong></td><td>Licensed deployments for corporates</td></tr>
                    <tr><td><strong>Cross-border Premium</strong></td><td>Additional margin on international flows</td></tr>
                </tbody>
            </table>
        </div>

        <h2>Illustrative Economics</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Scenario</th><th>Transactions/Month</th><th>Avg Fee</th><th>Annual Revenue</th></tr>
                </thead>
                <tbody>
                    <tr><td>Pilot Phase</td><td>5,000</td><td>BWP 2.50</td><td>BWP 150,000</td></tr>
                    <tr><td>Commercial Launch</td><td>50,000</td><td>BWP 2.50</td><td>BWP 1,500,000</td></tr>
                    <tr><td>Angola Scale</td><td>500,000</td><td>BWP 2.50</td><td>BWP 15,000,000</td></tr>
                    <tr><td class="table-highlight">SADC Region</td><td class="table-highlight">5,000,000+</td><td class="table-highlight">BWP 2.50</td><td class="table-highlight">BWP 150,000,000+</td></tr>
                </tbody>
            </table>
        </div>

        <p style="font-size:12px;color:var(--ink-300);">* These are illustrative projections based on addressable market analysis. Actual figures depend on adoption rates and partnership agreements.</p>

        <!-- ============================================================ -->
        <!-- 09 · WHY VOUCHMORPH WINS -->
        <!-- ============================================================ -->
        <h1>09 · Why VouchMorph Wins</h1>

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
        <!-- 10 · COMPETITIVE MOAT -->
        <!-- ============================================================ -->
        <h1>10 · Competitive Moat</h1>

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
                    <tr><td><strong>ABCCI Partnership</strong></td><td>Institutional access to Angolan ecosystem</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 11 · USE OF FUNDS -->
        <!-- ============================================================ -->
        <h1>11 · Use of Funds</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Purpose</th><th>Amount (BWP)</th><th>%</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Angola Market Entry</strong></td><td>250,000</td><td>17%</td></tr>
                    <tr><td><strong>Office Setup (Botswana & Angola)</strong></td><td>180,000</td><td>12%</td></tr>
                    <tr><td><strong>Contract Software Engineers</strong></td><td>350,000</td><td>23%</td></tr>
                    <tr><td><strong>Regulatory & Legal</strong></td><td>120,000</td><td>8%</td></tr>
                    <tr><td><strong>Security Audits & Compliance</strong></td><td>80,000</td><td>5%</td></tr>
                    <tr><td><strong>Infrastructure & Cloud</strong></td><td>160,000</td><td>11%</td></tr>
                    <tr><td><strong>Working Capital</strong></td><td>220,000</td><td>15%</td></tr>
                    <tr><td><strong>Sales & Partnerships</strong></td><td>80,000</td><td>5%</td></tr>
                    <tr><td><strong>Travel (8-person delegation)</strong></td><td>60,000</td><td>4%</td></tr>
                    <tr><td class="table-highlight"><strong>TOTAL</strong></td><td class="table-highlight"><strong>1,500,000</strong></td><td class="table-highlight"><strong>100%</strong></td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 12 · TEAM -->
        <!-- ============================================================ -->
        <h1>12 · Team</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Name</th><th>Role</th><th>Experience</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Magdaline Lewis</strong></td><td>CEO, VouchMorph</td><td>Financial services, regulatory strategy</td></tr>
                    <tr><td><strong>Marvin Sehunelo</strong></td><td>Product Manager</td><td>System architecture, platform development</td></tr>
                    <tr><td><strong>Dr Tshenolo Kealeboga</strong></td><td>Operations Manager</td><td>Operations, risk management</td></tr>
                    <tr><td><strong>Zhetu Mabusa</strong></td><td>Compliance</td><td>AML/CFT, regulatory compliance</td></tr>
                    <tr><td><strong>Itumeleng Garebatshabe</strong></td><td>Partner, Intellegere</td><td>Security governance, 18 years experience</td></tr>
                    <tr><td><strong>Isaac T Kgosiyareng</strong></td><td>Partner, Malakana</td><td>Infrastructure, 99.99% SLA</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 13 · WHAT 20% BUYS -->
        <!-- ============================================================ -->
        <h1>13 · What 20% Buys</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Investor Right</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Equity Ownership</strong></td><td>20% of VouchMorph (Pty) Ltd</td></tr>
                    <tr><td><strong>Board Representation</strong></td><td>One board seat</td></tr>
                    <tr><td><strong>Information Rights</strong></td><td>Quarterly financial and operational updates</td></tr>
                    <tr><td><strong>Pre-emptive Rights</strong></td><td>Right to participate in future funding rounds</td></tr>
                    <tr><td><strong>Tag-Along Rights</strong></td><td>Right to sell shares in a majority sale</td></tr>
                    <tr><td><strong>Drag-Along Rights</strong></td><td>Right to compel sale in a qualified transaction</td></tr>
                    <tr><td><strong>Anti-Dilution Protection</strong></td><td>Standard weighted average anti-dilution</td></tr>
                </tbody>
            </table>
        </div>

        <p style="font-size:12px;color:var(--ink-300);">Full terms to be set out in the Shareholders' Agreement and Investment Agreement.</p>

        <!-- ============================================================ -->
        <!-- 14 · EXIT SCENARIOS -->
        <!-- ============================================================ -->
        <h1>14 · Exit Scenarios</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Exit Path</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Strategic Acquisition</strong></td><td>Major fintech or infrastructure player acquires VouchMorph</td></tr>
                    <tr><td><strong>IPO / Public Listing</strong></td><td>Listing on SADC or international exchange</td></tr>
                    <tr><td><strong>Series A / Venture Growth</strong></td><td>Secondary sale to larger institutional investor</td></tr>
                    <tr><td><strong>Trade Sale</strong></td><td>Acquisition by mobile network operator or bank</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 15 · TIMELINE TO LIQUIDITY -->
        <!-- ============================================================ -->
        <h1>15 · Timeline to Liquidity</h1>

        <div class="timeline">
            <div class="timeline-item">
                <span class="phase">0–12 Months</span>
                <span class="desc"><strong>Seed Round</strong> — Angola market entry, BNA sandbox</span>
            </div>
            <div class="timeline-item">
                <span class="phase">12–24 Months</span>
                <span class="desc"><strong>Commercial Launch</strong> — Angola operational, revenue generation</span>
            </div>
            <div class="timeline-item">
                <span class="phase">24–36 Months</span>
                <span class="desc"><strong>Series A</strong> — SADC expansion, institutional funding</span>
            </div>
            <div class="timeline-item">
                <span class="phase">36–60 Months</span>
                <span class="desc"><strong>Exit / IPO</strong> — Mature business, acquisition or public listing</span>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 16 · WHY WE NEED INVESTORS -->
        <!-- ============================================================ -->
        <h1>16 · Why We Need Investors</h1>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Without Investment</th><th>With Investment</th></tr>
                </thead>
                <tbody>
                    <tr><td>3–5 years to market</td><td>18 months to commercial launch</td></tr>
                    <tr><td>Limited institutional engagement</td><td>Full regulatory and institutional support</td></tr>
                    <tr><td>Slow technical deployment</td><td>Accelerated integration with banks and MNOs</td></tr>
                    <tr><td>Single-country focus</td><td>Multi-country SADC expansion</td></tr>
                </tbody>
            </table>
        </div>

        <div class="highlight-box">
            <strong>The investment accelerates everything.</strong> With BWP 1.5M, we compress 5 years of organic growth into 18 months of strategic execution.
        </div>

        <!-- ============================================================ -->
        <!-- 17 · CONCLUSION -->
        <!-- ============================================================ -->
        <h1>17 · Conclusion</h1>

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
