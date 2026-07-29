<?php
/**
 * VouchMorph Company Profile PDF Generator
 * 
 * Usage: Place this file in your web root and access via browser
 * It will generate a styled HTML page that you can "Print to PDF"
 */

// ============================================================
// 1. CHECK IF DOMPDF IS AVAILABLE (optional - for auto-download)
// ============================================================
$useDompdf = false;
$dompdfAvailable = false;

// Try to load dompdf if it exists (silent check - no errors if missing)
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    try {
        require_once __DIR__ . '/../../vendor/autoload.php';
        if (class_exists('Dompdf\Dompdf')) {
            $dompdfAvailable = true;
            $useDompdf = true;
        }
    } catch (Exception $e) {
        // dompdf not available - continue without it
        $dompdfAvailable = false;
        $useDompdf = false;
    }
}

// ============================================================
// 2. HANDLE DOWNLOAD REQUEST
// ============================================================
if (isset($_GET['download']) && $useDompdf && $dompdfAvailable) {
    try {
        $html = getProfileHTML();
        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream('VouchMorph_Profile.pdf', ['Attachment' => true]);
        exit;
    } catch (Exception $e) {
        // If dompdf fails, fall back to HTML view with error
        $useDompdf = false;
        $dompdfAvailable = false;
    }
}

// ============================================================
// 3. SHOW PROFILE PAGE
// ============================================================
echo getProfileHTML();


// ============================================================
// 4. THE PROFILE HTML GENERATOR FUNCTION
// ============================================================
function getProfileHTML(): string
{
    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Company Profile</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           VOUCHMORPH PROFILE STYLES
           Matches the Enterprise Dashboard aesthetic
           ============================================================ */
        :root {
            --paper:        #EEF1EF;
            --panel:        #FFFFFF;
            --ink-900:      #0F2138;
            --ink-700:      #1D3557;
            --ink-500:      #4A5A6E;
            --ink-300:      #8A96A3;
            --line:         #D3DAD6;
            --line-strong:  #AEB8B2;
            --brass:        #8A6D3B;
            --brass-tint:   #F4EFE3;
            --seal-red:     #7A2118;
            --amber:        #8A5A0B;
            --amber-bg:     #FEF3C7;
            --ledger-green: #24513A;
            --green-tint:   #E5EEE7;
            --blue-tint:    #E7EEF4;
            --danger:       #b3261e;
            --danger-bg:    #fbeceb;
            
            --max-width:    1100px;
            --f-body:       "IBM Plex Sans", sans-serif;
            --f-cond:       "IBM Plex Sans Condensed", sans-serif;
            --f-mono:       "IBM Plex Mono", monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            font-size: 14px;
            line-height: 1.6;
            padding: 24px;
        }

        .profile-container {
            max-width: var(--max-width);
            margin: 0 auto;
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 48px 60px;
            box-shadow: 0 4px 24px rgba(15,33,56,0.08);
        }

        /* ----- HEADER ----- */
        .profile-header {
            border-bottom: 3px solid var(--brass);
            padding-bottom: 24px;
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }

        .logo {
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 28px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .logo span { color: var(--brass); }

        .logo-sub {
            font-family: var(--f-cond);
            font-weight: 500;
            font-size: 13px;
            color: var(--ink-500);
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .header-tag {
            text-align: right;
        }

        .header-tag .badge {
            display: inline-block;
            padding: 4px 16px;
            background: var(--brass);
            color: var(--panel);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-family: var(--f-cond);
        }

        .header-tag .date {
            display: block;
            font-size: 12px;
            color: var(--ink-300);
            margin-top: 4px;
            font-family: var(--f-mono);
        }

        /* ----- TYPOGRAPHY ----- */
        h1 {
            font-family: var(--f-cond);
            font-size: 36px;
            font-weight: 700;
            letter-spacing: 0.02em;
            margin-bottom: 4px;
        }

        h2 {
            font-family: var(--f-cond);
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.02em;
            margin-top: 32px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--line);
            color: var(--ink-700);
        }

        h3 {
            font-family: var(--f-cond);
            font-size: 17px;
            font-weight: 700;
            margin-top: 20px;
            margin-bottom: 10px;
            color: var(--ink-700);
        }

        h4 {
            font-family: var(--f-cond);
            font-size: 14px;
            font-weight: 700;
            margin-top: 12px;
            margin-bottom: 6px;
            color: var(--ink-500);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        p {
            margin-bottom: 12px;
            color: var(--ink-500);
        }

        .highlight-box {
            background: var(--brass-tint);
            border-left: 4px solid var(--brass);
            padding: 16px 20px;
            margin: 16px 0;
            font-style: italic;
            font-size: 15px;
            color: var(--ink-700);
        }

        .highlight-box strong {
            color: var(--ink-900);
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
            font-family: var(--f-cond);
        }

        td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            font-size: 13px;
            color: var(--ink-500);
        }

        tr:hover { background: var(--brass-tint); }

        .table-highlight td {
            background: var(--brass-tint);
            font-weight: 600;
            color: var(--ink-900);
        }

        /* ----- CARDS / FEATURE GRID ----- */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin: 12px 0 16px;
        }

        .feature-card {
            background: var(--paper);
            border: 1px solid var(--line);
            padding: 16px 18px;
        }

        .feature-card .icon { font-size: 24px; display: block; margin-bottom: 6px; }
        .feature-card .label {
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 14px;
            color: var(--ink-900);
        }
        .feature-card .desc {
            font-size: 12px;
            color: var(--ink-500);
            margin-top: 4px;
        }

        /* ----- FLOW DIAGRAM ----- */
        .flow-diagram {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            padding: 20px;
            background: var(--paper);
            border: 1px solid var(--line);
            margin: 12px 0 16px;
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 13px;
        }

        .flow-step {
            background: var(--ink-900);
            color: #fff;
            padding: 8px 16px;
            border-radius: 0;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 11px;
        }

        .flow-arrow {
            color: var(--brass);
            font-size: 18px;
            font-weight: 300;
        }

        /* ----- ARCHITECTURE DIAGRAM ----- */
        .arch-diagram {
            background: var(--ink-900);
            color: #fff;
            padding: 20px 24px;
            margin: 12px 0 16px;
            font-family: var(--f-mono);
            font-size: 12px;
            line-height: 2;
            white-space: pre-wrap;
            overflow-x: auto;
        }

        .arch-diagram .brass { color: var(--brass); }
        .arch-diagram .muted { color: var(--ink-300); }
        .arch-diagram .line { color: var(--line-strong); }

        /* ----- FOOTER ----- */
        .profile-footer {
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

        .profile-footer .confidential {
            font-family: var(--f-cond);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--seal-red);
            font-size: 10px;
        }

        /* ----- RESPONSIVE ----- */
        @media (max-width: 768px) {
            .profile-container { padding: 24px 20px; }
            h1 { font-size: 26px; }
            h2 { font-size: 19px; }
            .profile-header { flex-direction: column; }
            .header-tag { text-align: left; width: 100%; }
            .flow-diagram { flex-direction: column; gap: 4px; }
            .flow-arrow { transform: rotate(90deg); }
            .feature-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            body { padding: 12px; }
            .profile-container { padding: 16px 14px; }
            .arch-diagram { font-size: 10px; padding: 12px; }
        }

        /* ----- PRINT STYLES ----- */
        @media print {
            body { background: #fff; padding: 0; }
            .profile-container { box-shadow: none; border: none; padding: 40px 50px; }
            .no-print { display: none !important; }
            tr:hover { background: transparent; }
            .arch-diagram { background: #0F2138 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .flow-step { background: #0F2138 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .feature-card { background: #EEF1EF !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .highlight-box { background: #F4EFE3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .table-highlight td { background: #F4EFE3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            th { background: #EEF1EF !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .header-tag .badge { background: #8A6D3B !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        /* ----- DOWNLOAD BUTTON ----- */
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
            transition: all 0.15s;
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

        .btn-primary:hover {
            background: var(--brass);
            border-color: var(--brass);
            color: var(--ink-900);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--line);
            color: var(--ink-500);
        }

        .btn-outline:hover {
            border-color: var(--brass);
            color: var(--ink-900);
            background: var(--brass-tint);
        }
    </style>
</head>
<body>

    <div class="download-bar no-print">
        <button class="btn btn-primary" onclick="window.print()">📄 Print / PDF</button>
        <a href="?download=1" class="btn btn-primary" style="background:var(--ledger-green);border-color:var(--ledger-green);">⬇ Download PDF</a>
    </div>

    <div class="profile-container">

        <!-- ============================================================ -->
        <!-- HEADER -->
        <!-- ============================================================ -->
        <div class="profile-header">
            <div>
                <div class="logo">VOUCHMORPH <span>·</span></div>
                <div class="logo-sub">The Universal Access Layer for Money</div>
            </div>
            <div class="header-tag">
                <span class="badge">Proprietary &amp; Confidential</span>
                <span class="date">2026</span>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 01 · EXECUTIVE SUMMARY -->
        <!-- ============================================================ -->
        <h1>01 · Executive Summary</h1>

        <p><strong>VouchMorph is a non-custodial financial interoperability layer</strong> that transforms fragmented banking, mobile money, and teller infrastructures into a unified global access network for money.</p>

        <p>Unlike traditional systems that move funds, VouchMorph enables <strong>value access through pre-authorised holds and identity-bound messaging</strong>, allowing seamless transactions across institutions and borders.</p>

        <div class="feature-grid">
            <div class="feature-card">
                <span class="icon">🔒</span>
                <div class="label">Non-Custodial</div>
                <div class="desc">Funds remain in originating institutions — no balance-sheet risk</div>
            </div>
            <div class="feature-card">
                <span class="icon">🌐</span>
                <div class="label">Infrastructure-Agnostic</div>
                <div class="desc">Connects banks, wallets, and teller services</div>
            </div>
            <div class="feature-card">
                <span class="icon">🌍</span>
                <div class="label">Cross-Border Native</div>
                <div class="desc">Multi-country by design, not by integration</div>
            </div>
            <div class="feature-card">
                <span class="icon">📈</span>
                <div class="label">Network-Driven</div>
                <div class="desc">Each country added increases total system value</div>
            </div>
        </div>

        <div class="highlight-box">
            <strong>VouchMorph is the access layer of financial systems</strong> — enabling money to be used anywhere, regardless of where it is held.
        </div>

        <!-- ============================================================ -->
        <!-- 02 · CORPORATE IDENTITY -->
        <!-- ============================================================ -->
        <h2>02 · Corporate Identity</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Attribute</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><strong>Legal Name</strong></td><td>VouchMorph Proprietary Limited</td></tr>
                    <tr><td><strong>Registration</strong></td><td>CIPA BW00009655259</td></tr>
                    <tr><td><strong>Headquarters</strong></td><td>Gaborone, Botswana</td></tr>
                    <tr><td><strong>Core System</strong></td><td>MLIPS — Message-Linked Identity Payment System</td></tr>
                    <tr><td><strong>Status</strong></td><td>Central Bank Regulatory Sandbox Applicant</td></tr>
                    <tr><td><strong>Patents</strong></td><td>Under Assessment</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 03 · THE PROBLEM -->
        <!-- ============================================================ -->
        <h2>03 · The Problem</h2>

        <p><strong>Money exists. Access fails.</strong></p>

        <p>Across emerging markets, the financial infrastructure required to serve consumers and businesses already exists. What is missing is the <strong>connective tissue between systems</strong> — the layer that lets value flow regardless of where it is held.</p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Problem</th><th>Impact</th></tr>
                </thead>
                <tbody>
                    <tr><td>Fragmented banking systems</td><td>Limited interoperability between institutions</td></tr>
                    <tr><td>Mobile money silos</td><td>Restricted cross-network transfers</td></tr>
                    <tr><td>Teller service dependency</td><td>Frequent access failures and downtime</td></tr>
                    <tr><td>Cross-border inefficiency</td><td>High cost and slow settlement times</td></tr>
                </tbody>
            </table>
        </div>

        <div class="highlight-box">
            <strong>The infrastructure exists. The connectivity layer is missing.</strong>
        </div>

        <!-- ============================================================ -->
        <!-- 04 · THE SOLUTION -->
        <!-- ============================================================ -->
        <h2>04 · The Solution</h2>

        <h3>VouchMorph Overview</h3>

        <p>VouchMorph is an <strong>orchestration layer</strong> that connects banks, mobile money networks, and teller services (ATMs, agents) into a single addressable access network.</p>

        <h4>What It Enables</h4>

        <ul style="padding-left:20px;margin-bottom:16px;color:var(--ink-500);">
            <li><strong>Any-to-any value access</strong> across institutions</li>
            <li><strong>Cross-institution transactions</strong> without custody</li>
            <li><strong>Cross-border interoperability</strong> by design</li>
            <li><strong>Real-time execution</strong> with delayed net settlement</li>
        </ul>

        <h4>The Core Flow</h4>

        <div class="flow-diagram">
            <span class="flow-step">1 · Initiate</span>
            <span class="flow-arrow">→</span>
            <span class="flow-step">2 · Hold</span>
            <span class="flow-arrow">→</span>
            <span class="flow-step">3 · Process</span>
            <span class="flow-arrow">→</span>
            <span class="flow-step">4 · Execute</span>
            <span class="flow-arrow">→</span>
            <span class="flow-step">5 · Settle</span>
        </div>

        <p style="font-size:13px;color:var(--ink-300);">Value is locked at source before authorisation, eliminating the need for custodial transfers.</p>

        <!-- ============================================================ -->
        <!-- 05 · TECHNICAL ARCHITECTURE -->
        <!-- ============================================================ -->
        <h2>05 · Technical Architecture</h2>

        <h3>5.1 System Architecture</h3>

        <p>Users and institutions interact through a <strong>unified orchestration layer</strong> that abstracts the underlying rails.</p>

        <div class="arch-diagram">
┌─────────────────────────────────────────────────────────────┐
│                    <span class="brass">VOUCHMORPH ORCHESTRATION LAYER</span>           │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐       │
│  │  SWAP   │  │   HOLD  │  │  DEBIT  │  │ CREDIT  │       │
│  │ ENGINE  │  │  ENGINE │  │  ENGINE │  │ ENGINE  │       │
│  └─────────┘  └─────────┘  └─────────┘  └─────────┘       │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────┐    │
│  │         <span class="brass">INSTITUTION ADAPTER FACTORY</span>                 │    │
│  │  ┌─────┐  ┌─────┐  ┌─────┐  ┌─────┐  ┌─────┐     │    │
│  │  │BANK │  │MOMO │  │ATM  │  │CARD │  │VOUCH│     │    │
│  │  └─────┘  └─────┘  └─────┘  └─────┘  └─────┘     │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
        </div>

        <h3>5.2 Key Technical Components</h3>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Component</th><th>Purpose</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>SwapService</strong></td><td>Orchestrates atomic swaps across institutions</td></tr>
                    <tr><td><strong>Hold Engine</strong></td><td>Pre-authorises funds at source</td></tr>
                    <tr><td><strong>Identity System</strong></td><td>Enables phone/ID-bound value claims</td></tr>
                    <tr><td><strong>Settlement Engine</strong></td><td>Double-entry net settlement</td></tr>
                    <tr><td><strong>Multi-Source Orchestrator</strong></td><td>Splits single transaction across sources</td></tr>
                    <tr><td><strong>Message Outbox</strong></td><td>Asynchronous SMS/notification delivery</td></tr>
                </tbody>
            </table>
        </div>

        <h3>5.3 Non-Custodial Settlement</h3>

        <div class="arch-diagram">
┌──────────────┐                    ┌──────────────┐
│  Source      │                    │  Destination │
│  Institution │                    │  Institution │
│              │                    │              │
│  ┌────────┐  │                    │  ┌────────┐  │
│  │ HOLD   │  │                    │  │ CREDIT │  │
│  │ DEBIT  │  │    <span class="brass">VOUCHMORPH</span>      │  │  (net) │  │
│  └────────┘  │    SETTLEMENT      │  └────────┘  │
│              │                    │              │
└──────────────┘                    └──────────────┘
        </div>

        <p style="font-size:13px;color:var(--ink-300);"><strong>Key Principle:</strong> Funds move directly between institutions — VouchMorph only orchestrates and records.</p>

        <!-- ============================================================ -->
        <!-- 06 · PRODUCT SUITE -->
        <!-- ============================================================ -->
        <h2>06 · Product Suite</h2>

        <h3>01 · Prestaged SWAP Engine</h3>
        <ul style="padding-left:20px;margin-bottom:12px;color:var(--ink-500);">
            <li>Hold → Process → Settle workflow</li>
            <li>Operates across all financial rails (bank, mobile money, card, voucher)</li>
            <li>Atomic execution with rollback protection</li>
            <li>Supports multi-source and multi-destination transactions</li>
        </ul>

        <h3>02 · VMTC — VouchMorph Transaction Card</h3>
        <ul style="padding-left:20px;margin-bottom:12px;color:var(--ink-500);">
            <li>Converts value messages into card payments</li>
            <li>Compatible with POS, online, and teller services</li>
            <li>Enables any source (bank, wallet, voucher) to fund card transactions</li>
            <li>Bridges digital value to physical payment rails</li>
        </ul>

        <h3>03 · Settlement Engine</h3>
        <ul style="padding-left:20px;margin-bottom:12px;color:var(--ink-500);">
            <li>Double-entry ledger with real-time posting</li>
            <li>Net settlement optimisation between institutions</li>
            <li>Supports BWP, ZAR, USD, and multi-currency transactions</li>
            <li>Automated reconciliation and dispute management</li>
        </ul>

        <h3>04 · Identity-Bound Payments</h3>
        <ul style="padding-left:20px;margin-bottom:12px;color:var(--ink-500);">
            <li>Send value to a phone number, national ID, or email</li>
            <li>Recipient claims via SMS PIN or dashboard</li>
            <li>Agent-assisted verification for government-issued IDs</li>
            <li>24-hour hold with automatic expiry and release</li>
        </ul>

        <!-- ============================================================ -->
        <!-- 07 · BUSINESS MODEL -->
        <!-- ============================================================ -->
        <h2>07 · Business Model</h2>

        <h3>Revenue Streams</h3>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Stream</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Per-Transaction Fees</strong></td><td>Orchestrated flow fees (≈0.5–2% depending on volume)</td></tr>
                    <tr><td><strong>Interchange Participation</strong></td><td>Card-rail conversion revenue sharing</td></tr>
                    <tr><td><strong>Integration Fees</strong></td><td>Institutional onboarding and platform access</td></tr>
                    <tr><td><strong>Cross-Border Premium</strong></td><td>Additional margin on international flows</td></tr>
                </tbody>
            </table>
        </div>

        <h3>Unit Economics</h3>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Metric</th><th>Value</th></tr>
                </thead>
                <tbody>
                    <tr><td>Average Revenue per Transaction</td><td>≈ USD 0.25</td></tr>
                    <tr><td>Average Cost per Transaction</td><td>≈ USD 0.03</td></tr>
                    <tr class="table-highlight"><td><strong>Gross Margin</strong></td><td><strong>≈ 88%</strong></td></tr>
                    <tr><td>Break-even Volume</td><td>≈ 2.5M transactions/year</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 08 · MULTI-COUNTRY NETWORK EFFECT -->
        <!-- ============================================================ -->
        <h2>08 · Multi-Country Network Effect</h2>

        <h3>Network Architecture</h3>

        <p>Traditional cross-border systems require <strong>pairwise integrations</strong> between every two participants, scaling at <strong>n(n−1)/2</strong>. VouchMorph collapses this to a <strong>single integration per participant</strong> — a hub model where every new country adds linear cost but compounding network value.</p>

        <h3>Scaling Principle</h3>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Model</th><th>Integration Cost</th></tr>
                </thead>
                <tbody>
                    <tr><td>Traditional bilateral</td><td>n(n−1)/2</td></tr>
                    <tr class="table-highlight"><td><strong>VouchMorph hub</strong></td><td><strong>n</strong></td></tr>
                </tbody>
            </table>
        </div>

        <div class="highlight-box">
            <strong>VouchMorph converts interoperability into a network property.</strong>
        </div>

        <!-- ============================================================ -->
        <!-- 09 · CROSS-BORDER VALUE FLOW -->
        <!-- ============================================================ -->
        <h2>09 · Cross-Border Value Flow</h2>

        <p>Cross-border transactions execute in <strong>real time at the access layer</strong> while settlement between institutions occurs on a <strong>delayed net basis</strong>. This decouples user experience from interbank settlement constraints.</p>

        <div class="arch-diagram">
┌─────────────────────────────────────────────────────────────────────────┐
│                         <span class="brass">REAL-TIME ACCESS LAYER</span>                        │
│                                                                         │
│   Botswana (BWP)        ────────────────►       South Africa (ZAR)    │
│   ┌─────────────┐                          ┌─────────────────────────┐ │
│   │  Initiate   │                          │  ₿ Hold placed,          │ │
│   │  BWP 1,000  │                          │  awaiting confirmation  │ │
│   └─────────────┘                          └─────────────────────────┘ │
│          │                                            │                │
│          └───────────── <span class="brass">VOUCHMORPH</span> ──────────────────┘                │
│                         ORCHESTRATION                                  │
│                                                                         │
├─────────────────────────────────────────────────────────────────────────┤
│                         <span class="muted">NET SETTLEMENT LAYER</span>                           │
│                                                                         │
│   ┌─────────────────────────────────────────────────────────────────┐  │
│   │  ┌─────────────┐    ┌─────────────┐    ┌─────────────────────┐ │  │
│   │  │ Bank of     │    │ South       │    │ Net Position:       │ │  │
│   │  │ Botswana    │◄───│ African     │    │ BWP -950 / ZAR +750 │ │  │
│   │  │ ────────────│    │ Reserve     │    └─────────────────────┘ │  │
│   │  │ Settle:     │    └─────────────┘                            │  │
│   │  │ ₿ 950 BWP   │                                               │  │
│   │  └─────────────┘                                               │  │
│   └─────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────┘
        </div>

        <!-- ============================================================ -->
        <!-- 10 · MARKET OPPORTUNITY -->
        <!-- ============================================================ -->
        <h2>10 · Market Opportunity</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Segment</th><th>Indicative Value</th></tr>
                </thead>
                <tbody>
                    <tr><td>Global payments flows</td><td>USD 200T+ annually</td></tr>
                    <tr><td>Global remittances</td><td>USD 800B+ annually</td></tr>
                    <tr><td>African digital payments</td><td>USD 150B+ (growing at 20% CAGR)</td></tr>
                    <tr><td>Emerging markets payments</td><td>Highest growth segment</td></tr>
                </tbody>
            </table>
        </div>

        <p><strong>Target Market:</strong> VouchMorph targets the <strong>underserved interoperability gap</strong> within emerging markets first, before extending its rails into established financial corridors.</p>

        <h3>Key Market Drivers</h3>

        <ul style="padding-left:20px;margin-bottom:12px;color:var(--ink-500);">
            <li>✅ Mobile money penetration in Sub-Saharan Africa (&gt;45%)</li>
            <li>✅ Growing cross-border trade within Africa (AfCFTA)</li>
            <li>✅ Regulatory push for financial inclusion</li>
            <li>✅ Increasing demand for real-time payments</li>
        </ul>

        <!-- ============================================================ -->
        <!-- 11 · COMPETITIVE POSITIONING -->
        <!-- ============================================================ -->
        <h2>11 · Competitive Positioning</h2>

        <h3>Existing Players &amp; Limitations</h3>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Player</th><th>Limitation</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>SWIFT</strong></td><td>Slow settlement, message-only rails, legacy infrastructure</td></tr>
                    <tr><td><strong>Visa / Mastercard</strong></td><td>Card rails only, custodial, high fees</td></tr>
                    <tr><td><strong>Mobile Network Operators</strong></td><td>Siloed within single networks, no cross-network capability</td></tr>
                    <tr><td><strong>Commercial Banks</strong></td><td>Fragmented, bilateral integrations only</td></tr>
                    <tr><td><strong>M-Pesa / Airtel Money</strong></td><td>Limited to single-country, single-network</td></tr>
                </tbody>
            </table>
        </div>

        <h3>The VouchMorph Advantage</h3>

        <div class="feature-grid">
            <div class="feature-card">
                <span class="icon">🔗</span>
                <div class="label">Cross-Institution</div>
                <div class="desc">Works across banks, mobile money, and teller services</div>
            </div>
            <div class="feature-card">
                <span class="icon">🌍</span>
                <div class="label">Cross-Border</div>
                <div class="desc">Native multi-country by design</div>
            </div>
            <div class="feature-card">
                <span class="icon">🔒</span>
                <div class="label">Non-Custodial</div>
                <div class="desc">No balance-sheet risk — funds never leave source</div>
            </div>
            <div class="feature-card">
                <span class="icon">📈</span>
                <div class="label">Network-Driven</div>
                <div class="desc">Compounding value with each integration</div>
            </div>
            <div class="feature-card">
                <span class="icon">🆔</span>
                <div class="label">Identity-Bound</div>
                <div class="desc">Send to phone, email, or national ID</div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 12 · TRACTION & ROADMAP -->
        <!-- ============================================================ -->
        <h2>12 · Traction &amp; Roadmap</h2>

        <h3>Current Status</h3>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Area</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Codebase</strong></td><td>Production-ready with 519+ files, 80+ controllers/services</td></tr>
                    <tr><td><strong>Institution Adapters</strong></td><td>ZuruBank, Bank of Botswana, and generic bank adapters</td></tr>
                    <tr><td><strong>Authentication</strong></td><td>Role-based enterprise access (Owner, Approver, Loader, Viewer, etc.)</td></tr>
                    <tr><td><strong>Core Features</strong></td><td>Batches, beneficiaries, rations, source accounts, traceability</td></tr>
                </tbody>
            </table>
        </div>

        <h3>Roadmap</h3>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Phase</th><th>Focus</th><th>Timeline</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Sandbox</strong></td><td>Bank of Botswana regulatory sandbox execution</td><td>0 – 6 months</td></tr>
                    <tr><td><strong>Launch</strong></td><td>Commercial launch, first integrations live</td><td>6 – 12 months</td></tr>
                    <tr><td><strong>Expansion</strong></td><td>Multi-country rollout, partner network</td><td>12 – 24 months</td></tr>
                </tbody>
            </table>
        </div>

        <h3>Key Partnerships (Targeted)</h3>

        <ul style="padding-left:20px;margin-bottom:12px;color:var(--ink-500);">
            <li>Bank of Botswana (regulatory sandbox)</li>
            <li>ZuruBank (Zimbabwe pilot)</li>
            <li>Multiple SADC region banks</li>
            <li>Mobile network operators</li>
        </ul>

        <!-- ============================================================ -->
        <!-- 13 · TEAM -->
        <!-- ============================================================ -->
        <h2>13 · Team</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Name</th><th>Role</th><th>Expertise</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Magdaline Lewis</strong></td><td>Managing Director</td><td>Financial services, strategy, regulatory</td></tr>
                    <tr><td><strong>Marvin Sehunelo</strong></td><td>Product Manager</td><td>Product development, system architecture</td></tr>
                    <tr><td><strong>Dr Tshenolo Kealeboga</strong></td><td>Operations Manager</td><td>Operations, risk management</td></tr>
                    <tr><td><strong>Zhetu Mabusa</strong></td><td>Compliance</td><td>Regulatory compliance, AML/CFT</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 14 · INVESTMENT SUMMARY -->
        <!-- ============================================================ -->
        <h2>14 · Investment Summary</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Metric</th><th>Detail</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Raise</strong></td><td>[To be confirmed]</td></tr>
                    <tr><td><strong>Valuation</strong></td><td>[To be confirmed]</td></tr>
                    <tr><td><strong>Use of Funds</strong></td><td>Product development, multi-country expansion, institutional partnerships</td></tr>
                    <tr><td><strong>Current Stage</strong></td><td>Pre-revenue, regulatory sandbox applicant</td></tr>
                    <tr><td><strong>Capital Required</strong></td><td>USD 500K – 1.5M (Series Seed)</td></tr>
                </tbody>
            </table>
        </div>

        <p style="font-size:13px;color:var(--ink-300);">Investor materials including detailed financial model, capitalisation table, and term sheet are available under separate cover and subject to non-disclosure agreement.</p>

        <!-- ============================================================ -->
        <!-- 15 · RISK & MITIGATION -->
        <!-- ============================================================ -->
        <h2>15 · Risk &amp; Mitigation</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Risk</th><th>Mitigation</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Regulatory exposure</strong></td><td>Non-custodial model — no client funds held</td></tr>
                    <tr><td><strong>Integration complexity</strong></td><td>Standardised API layer across all rails</td></tr>
                    <tr><td><strong>Fraud and abuse</strong></td><td>Bank-level controls inherited from source institution</td></tr>
                    <tr><td><strong>Adoption risk</strong></td><td>Network effect driven — incentive alignment</td></tr>
                    <tr><td><strong>Currency volatility</strong></td><td>Multi-currency settlement with real-time forex</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- 16 · CONCLUSION -->
        <!-- ============================================================ -->
        <h2>16 · Conclusion</h2>

        <p><strong>VouchMorph does not replace financial systems. It connects them.</strong><br>
        <strong>It does not move money. It enables access to money.</strong></p>

        <p>As VouchMorph expands across institutions and borders, it becomes a <strong>global financial access network</strong> — the universal layer through which value flows on top of existing infrastructure.</p>

        <div class="highlight-box" style="font-size:16px;text-align:center;border-left-color:var(--ledger-green);">
            <strong>VouchMorph is building the financial equivalent of the internet —<br>
            a universal access layer where value flows freely across systems and borders.</strong>
        </div>

        <!-- ============================================================ -->
        <!-- FOOTER -->
        <!-- ============================================================ -->
        <div class="profile-footer">
            <div>
                <div><strong>VouchMorph Proprietary Limited</strong></div>
                <div style="color:var(--ink-300);">Registration: CIPA BW00009655259</div>
                <div style="color:var(--ink-300);">Headquarters: Gaborone, Botswana</div>
            </div>
            <div style="text-align:right;">
                <div class="confidential">Proprietary &amp; Confidential</div>
                <div style="color:var(--ink-300);font-size:10px;margin-top:4px;">© 2026 VouchMorph Proprietary Limited · All rights reserved.</div>
            </div>
        </div>

    </div>

</body>
</html>';
}
?>
