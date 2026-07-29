<?php
/**
 * VouchMorph Investment Memorandum
 * Professional A4 Document · 12 Pages
 * Designed for Chrome PDF generation
 */

// ============================================================
// 1. CHECK IF DOMPDF IS AVAILABLE (fallback only)
// ============================================================
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

// ============================================================
// 2. IF DOWNLOAD REQUESTED
// ============================================================
if (isset($_GET['download'])) {
    if ($useDompdf && $dompdfAvailable) {
        try {
            $html = getMemoHTML(true);
            $dompdf = new Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $dompdf->stream('VouchMorph_Investment_Memorandum.pdf', ['Attachment' => true]);
            exit;
        } catch (Exception $e) {
            // Fall through to browser print
        }
    }
    
    // If dompdf fails or isn't available, show print instructions
    echo '<!DOCTYPE html>
    <html>
    <head><title>PDF Generation</title></head>
    <body style="font-family: Arial, sans-serif; text-align: center; padding: 60px 20px; max-width: 600px; margin: 0 auto;">
        <h1 style="color: #0F2138;">📄 PDF Generation</h1>
        <p style="color: #4A5A6E; font-size: 16px; line-height: 1.6;">
            For best results, use your browser\'s <strong>"Print to PDF"</strong> function.
        </p>
        <div style="background: #F4EFE3; border-left: 4px solid #8A6D3B; padding: 16px 20px; margin: 20px 0; text-align: left;">
            <p style="margin: 0; font-size: 14px; color: #1D3557;">
                <strong>💡 Steps:</strong><br>
                1. Click <strong>"View Document"</strong> below<br>
                2. Press <strong>Ctrl+P</strong> (or Cmd+P)<br>
                3. Select <strong>"Save as PDF"</strong><br>
                4. Choose <strong>"A4"</strong> paper size
            </p>
        </div>
        <a href="?view=1" class="btn" style="display: inline-block; padding: 14px 40px; background: #0F2138; color: #fff; text-decoration: none; font-weight: 600; margin-top: 12px;">View Document</a>
        <p style="color: #8A96A3; font-size: 12px; margin-top: 24px;">The document is 12 pages · Professionally designed · Investment-grade quality</p>
    </body>
    </html>';
    exit;
}

// ============================================================
// 3. SHOW DOCUMENT
// ============================================================
if (isset($_GET['view'])) {
    echo getMemoHTML(false);
    exit;
}

// ============================================================
// 4. LANDING PAGE
// ============================================================
echo '<!DOCTYPE html>
<html>
<head><title>VouchMorph · Investment Memorandum</title></head>
<body style="font-family: Arial, sans-serif; text-align: center; padding: 60px 20px; max-width: 600px; margin: 0 auto;">
    <h1 style="color: #0F2138;">VOUCHMORPH</h1>
    <p style="color: #8A6D3B; font-size: 18px; font-weight: 600; margin-top: -8px;">Investment Memorandum</p>
    <div style="border: 2px solid #8A6D3B; padding: 24px; margin: 24px 0; background: #F4EFE3;">
        <div style="font-size: 28px; font-weight: 700; color: #0F2138;">BWP 1,500,000</div>
        <div style="font-size: 14px; color: #4A5A6E;">for 20% Equity</div>
    </div>
    <p style="color: #4A5A6E; font-size: 16px; line-height: 1.6;">
        A professionally designed, 12-page investment memorandum.
    </p>
    <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 24px;">
        <a href="?view=1" class="btn" style="display: inline-block; padding: 14px 32px; background: #0F2138; color: #fff; text-decoration: none; font-weight: 600;">📄 View Document</a>
        <a href="?download=1" class="btn" style="display: inline-block; padding: 14px 32px; background: #24513A; color: #fff; text-decoration: none; font-weight: 600;">⬇ Download PDF</a>
    </div>
    <p style="color: #8A96A3; font-size: 12px; margin-top: 20px;">12 pages · A4 · Investment-grade quality</p>
</body>
</html>';


// ============================================================
// 5. THE FULL MEMORANDUM HTML
// ============================================================
function getMemoHTML(bool $isPdf = false): string
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
           PAGE SETUP — A4 Magazine Style
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "IBM Plex Sans", sans-serif;
            background: #EEF1EF;
            color: #0F2138;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            background: #FFFFFF;
            padding: 18mm 16mm;
            margin-bottom: 24px;
            box-shadow: 0 4px 24px rgba(15,33,56,0.08);
            position: relative;
            page-break-after: always;
            page-break-inside: avoid;
        }

        /* ============================================================
           PRINT STYLES — Clean PDF
           ============================================================ */
        @media print {
            body { background: #fff; padding: 0; margin: 0; }
            .page { 
                width: 100%; 
                min-height: 100vh; 
                margin: 0; 
                padding: 18mm 16mm;
                box-shadow: none;
                page-break-after: always;
                page-break-inside: avoid;
            }
            .page:last-child { page-break-after: avoid; }
            .no-print { display: none !important; }
        }

        /* ============================================================
           TYPOGRAPHY
           ============================================================ */
        .brand {
            font-family: "IBM Plex Sans Condensed", sans-serif;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #0F2138;
        }
        .brand span { color: #8A6D3B; }

        .page-number {
            position: absolute;
            bottom: 14mm;
            right: 16mm;
            font-size: 10px;
            color: #8A96A3;
            font-family: "IBM Plex Mono", monospace;
            letter-spacing: 0.06em;
        }

        h1 {
            font-family: "IBM Plex Sans Condensed", sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: #0F2138;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        h2 {
            font-family: "IBM Plex Sans Condensed", sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: #1D3557;
            margin-top: 24px;
            margin-bottom: 12px;
        }

        h3 {
            font-family: "IBM Plex Sans Condensed", sans-serif;
            font-size: 16px;
            font-weight: 600;
            color: #1D3557;
            margin-top: 16px;
            margin-bottom: 6px;
        }

        p { 
            font-size: 13px; 
            line-height: 1.7; 
            color: #4A5A6E;
            margin-bottom: 10px;
            max-width: 600px;
        }

        .highlight-box {
            background: #F4EFE3;
            border-left: 4px solid #8A6D3B;
            padding: 14px 18px;
            margin: 14px 0;
            font-size: 14px;
            color: #1D3557;
            max-width: 600px;
        }
        .highlight-box strong { color: #0F2138; }

        /* ============================================================
           METRICS
           ============================================================ */
        .metrics-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 12px 0;
        }

        .metric {
            background: #EEF1EF;
            border: 1px solid #D3DAD6;
            padding: 12px 16px;
            text-align: center;
            flex: 1;
            min-width: 100px;
        }
        .metric .num {
            font-family: "IBM Plex Sans Condensed", sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: #0F2138;
        }
        .metric .lbl {
            font-size: 10px;
            text-transform: uppercase;
            color: #8A96A3;
            letter-spacing: 0.06em;
            font-weight: 600;
        }

        /* ============================================================
           TABLES
           ============================================================ */
        .table-wrap { overflow-x: auto; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th {
            background: #EEF1EF;
            color: #4A5A6E;
            padding: 8px 12px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 600;
            border-bottom: 2px solid #D3DAD6;
        }
        td {
            padding: 8px 12px;
            border-bottom: 1px solid #D3DAD6;
            color: #4A5A6E;
            font-size: 12px;
        }
        .table-highlight td { background: #F4EFE3; font-weight: 600; color: #0F2138; }

        /* ============================================================
           SOLUTIONS GRID
           ============================================================ */
        .solutions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 10px 0;
        }
        .solution-card {
            background: #EEF1EF;
            border: 1px solid #D3DAD6;
            padding: 14px 16px;
            border-top: 3px solid #8A6D3B;
        }
        .solution-card .icon { font-size: 24px; display: block; margin-bottom: 4px; }
        .solution-card .label {
            font-weight: 700;
            font-size: 14px;
            color: #0F2138;
        }
        .solution-card .desc { font-size: 12px; color: #4A5A6E; margin-top: 2px; }
        .solution-card .example {
            font-size: 11px;
            color: #8A96A3;
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px dashed #D3DAD6;
            font-style: italic;
        }

        /* ============================================================
           TIMELINE
           ============================================================ */
        .timeline {
            padding-left: 16px;
            border-left: 3px solid #8A6D3B;
            margin: 10px 0;
        }
        .tl-item {
            display: flex;
            gap: 16px;
            margin-bottom: 4px;
            align-items: baseline;
        }
        .tl-item .phase {
            font-weight: 700;
            font-size: 12px;
            color: #0F2138;
            min-width: 80px;
        }
        .tl-item .date { font-size: 10px; color: #8A96A3; min-width: 60px; }
        .tl-item .desc { font-size: 12px; color: #4A5A6E; }

        /* ============================================================
           PARTNERS
           ============================================================ */
        .partners {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            padding: 12px 16px;
            background: #EEF1EF;
            border: 1px solid #D3DAD6;
            margin: 10px 0;
        }
        .partner { text-align: center; }
        .partner .name { font-weight: 700; font-size: 14px; color: #0F2138; }
        .partner .role { font-size: 10px; color: #8A96A3; letter-spacing: 0.04em; }

        /* ============================================================
           FEATURES
           ============================================================ */
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 8px;
            margin: 10px 0;
        }
        .feature {
            background: #EEF1EF;
            border: 1px solid #D3DAD6;
            padding: 10px 12px;
            text-align: center;
        }
        .feature .icon { font-size: 20px; display: block; }
        .feature .label { font-weight: 600; font-size: 12px; color: #0F2138; }
        .feature .desc { font-size: 10px; color: #4A5A6E; }

        /* ============================================================
           FOOTER
           ============================================================ */
        .footer-text {
            position: absolute;
            bottom: 14mm;
            left: 16mm;
            right: 16mm;
            border-top: 1px solid #D3DAD6;
            padding-top: 10px;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #8A96A3;
        }
        .footer-text .conf {
            color: #7A2118;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-size: 8px;
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 800px) {
            .page { width: 100%; min-height: auto; padding: 20px; }
            .solutions-grid { grid-template-columns: 1fr; }
            .metrics-row { flex-direction: column; }
            .features { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>';

    // ============================================================
    // PAGE 1 — COVER
    // ============================================================
    $html .= '
    <div class="page" style="display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;">
        <div style="margin-bottom:auto;width:100%;text-align:left;">
            <div class="brand">VOUCHMORPH <span>·</span></div>
        </div>
        
        <div style="flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;">
            <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-weight:500;font-size:18px;color:#8A96A3;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:8px;">Investment Memorandum</div>
            
            <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-weight:700;font-size:52px;color:#0F2138;line-height:1.1;letter-spacing:-1px;max-width:500px;margin:12px 0;">
                The Bridge Between Any Source of Money and Any Identity
            </div>
            
            <div style="border:2px solid #8A6D3B;padding:20px 48px;background:#F4EFE3;margin:24px 0;">
                <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-size:36px;font-weight:700;color:#0F2138;">BWP 1,500,000</div>
                <div style="font-size:14px;color:#4A5A6E;font-weight:500;">for 20% Equity</div>
            </div>
            
            <div style="display:flex;gap:32px;font-size:13px;color:#4A5A6E;flex-wrap:wrap;justify-content:center;margin-top:12px;">
                <span><strong style="color:#0F2138;">Company:</strong> VouchMorph (Pty) Ltd</span>
                <span><strong style="color:#0F2138;">Country:</strong> Botswana</span>
                <span><strong style="color:#0F2138;">First Market:</strong> Angola</span>
                <span><strong style="color:#0F2138;">Stage:</strong> Regulatory Sandbox</span>
            </div>
            
            <div style="margin-top:32px;font-size:11px;color:#7A2118;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;">Proprietary &amp; Confidential · 2026</div>
        </div>
        
        <div style="margin-top:auto;width:100%;text-align:left;font-size:9px;color:#8A96A3;border-top:1px solid #D3DAD6;padding-top:10px;">
            <span>Prepared for Strategic Investors</span>
            <span style="float:right;">Page 1</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 2 — EXECUTIVE SUMMARY
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>Executive Summary</h1>
        
        <p style="font-size:14px;font-weight:500;color:#0F2138;margin-bottom:16px;">
            VouchMorph is a non-custodial financial interoperability layer that connects banks, mobile money networks, and teller services into a unified access network for money.
        </p>
        
        <p>Unlike traditional systems that move funds between accounts, VouchMorph enables <strong>value access through identity-bound messaging</strong> — allowing seamless transactions across institutions without requiring accounts at either end.</p>
        
        <p>The company has completed its MVP, secured a regulatory sandbox position in Botswana, and is now executing its entry into Angola — a market of <strong>40 million people</strong> with <strong>26 million unbanked adults</strong> and a government actively pursuing digital financial inclusion.</p>
        
        <div class="highlight-box">
            <strong>VouchMorph is raising growth capital</strong> to commercialise in Angola, expand across SADC, and establish Africa\'s first identity-based financial access network.
        </div>
        
        <div style="margin-top:16px;">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                <div style="background:#0F2138;color:#fff;padding:12px;text-align:center;">
                    <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-size:28px;font-weight:700;">40M</div>
                    <div style="font-size:9px;text-transform:uppercase;letter-spacing:0.04em;opacity:0.7;">Population</div>
                </div>
                <div style="background:#0F2138;color:#fff;padding:12px;text-align:center;">
                    <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-size:28px;font-weight:700;">26M</div>
                    <div style="font-size:9px;text-transform:uppercase;letter-spacing:0.04em;opacity:0.7;">Unbanked Adults</div>
                </div>
                <div style="background:#0F2138;color:#fff;padding:12px;text-align:center;">
                    <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-size:28px;font-weight:700;">30M+</div>
                    <div style="font-size:9px;text-transform:uppercase;letter-spacing:0.04em;opacity:0.7;">Mobile Users</div>
                </div>
            </div>
        </div>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 2</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 3 — STRATEGIC PARTNERS & INVESTMENT HIGHLIGHTS
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>Strategic Partners &amp; Highlights</h1>
        
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
        
        <h2>Investment Highlights</h2>
        
        <div class="metrics-row">
            <div class="metric"><div class="num">40M</div><div class="lbl">Population</div></div>
            <div class="metric"><div class="num">26M</div><div class="lbl">Unbanked Adults</div></div>
            <div class="metric"><div class="num">30M+</div><div class="lbl">Mobile Users</div></div>
            <div class="metric"><div class="num">Patent</div><div class="lbl">Granted</div></div>
            <div class="metric"><div class="num">20%</div><div class="lbl">Equity Offered</div></div>
        </div>
        
        <div class="highlight-box" style="max-width:100%;">
            <strong>The Angola-Botswana bilateral relationship is at an all-time high.</strong> The Presidents have committed to deepening economic cooperation — VouchMorph is the financial infrastructure that delivers on that commitment.
        </div>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 3</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 4 — THE VOUCHMORPH SOLUTIONS
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>The VouchMorph Solutions</h1>
        <p style="font-size:14px;font-weight:500;color:#0F2138;">Six ways to move value across any institution, to any identity.</p>
        
        <div class="solutions-grid">
            <div class="solution-card">
                <span class="icon">🔄</span>
                <div class="label">Standard Swap</div>
                <div class="desc">Bank to bank, wallet to wallet, or bank to wallet. Simple, fast, non-custodial.</div>
                <div class="example">Bank customer → mobile money user</div>
            </div>
            <div class="solution-card">
                <span class="icon">💰</span>
                <div class="label">Cashout Swap</div>
                <div class="desc">Convert digital value to cash at any ATM, agent, or teller. Secure codes for instant redemption.</div>
                <div class="example">Digital payment → cash withdrawal</div>
            </div>
            <div class="solution-card">
                <span class="icon">🏦</span>
                <div class="label">Deposit Swap</div>
                <div class="desc">Credit funds into any bank account, mobile wallet, or card — sender needs no account.</div>
                <div class="example">Government disbursement → citizen\'s account</div>
            </div>
            <div class="solution-card">
                <span class="icon">🆔</span>
                <div class="label">Swap to Identity</div>
                <div class="desc">Send to a phone number, email, or national ID — no account required at either end.</div>
                <div class="example">Family member → relative\'s phone number</div>
            </div>
            <div class="solution-card">
                <span class="icon">📦</span>
                <div class="label">Multi-Source Swap</div>
                <div class="desc">Combine funds from multiple accounts, wallets, or cards into a single transaction.</div>
                <div class="example">Business → supplier using multiple accounts</div>
            </div>
            <div class="solution-card">
                <span class="icon">🎯</span>
                <div class="label">Multi-Destination Swap</div>
                <div class="desc">Send one payment to multiple recipients simultaneously in their preferred form.</div>
                <div class="example">Payroll → 1,000 employees, 1,000 accounts</div>
            </div>
        </div>
        
        <div class="highlight-box" style="max-width:100%;">
            <strong>All swaps are:</strong> Non-custodial · Atomic execution · Fully traceable · Patent-protected
        </div>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 4</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 5 — HOW IT WORKS + WHY ANGOLA
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>How It Works</h1>
        
        <p style="font-size:14px;font-weight:500;color:#0F2138;">Five-stage transaction lifecycle — secure, atomic, and non-custodial.</p>
        
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:4px;margin:12px 0;">
            <div style="background:#0F2138;color:#fff;padding:10px 6px;text-align:center;font-family:\'IBM Plex Sans Condensed\',sans-serif;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">1. Initiate</div>
            <div style="background:#0F2138;color:#fff;padding:10px 6px;text-align:center;font-family:\'IBM Plex Sans Condensed\',sans-serif;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">2. Hold</div>
            <div style="background:#0F2138;color:#fff;padding:10px 6px;text-align:center;font-family:\'IBM Plex Sans Condensed\',sans-serif;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">3. Process</div>
            <div style="background:#0F2138;color:#fff;padding:10px 6px;text-align:center;font-family:\'IBM Plex Sans Condensed\',sans-serif;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">4. Execute</div>
            <div style="background:#0F2138;color:#fff;padding:10px 6px;text-align:center;font-family:\'IBM Plex Sans Condensed\',sans-serif;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">5. Settle</div>
        </div>
        
        <p style="font-size:12px;color:#8A96A3;">Value is locked at source before authorisation, eliminating the need for custodial transfers.</p>
        
        <h2>Why Angola?</h2>
        
        <p>Angola is a <strong>strategically selected first market</strong> based on regulatory readiness, infrastructure availability, and addressable population.</p>
        
        <div class="table-wrap">
            <table>
                <thead><tr><th>Indicator</th><th>Value</th><th>Implication</th></tr></thead>
                <tbody>
                    <tr><td><strong>Population</strong></td><td>40.2 million</td><td>Large addressable market</td></tr>
                    <tr><td><strong>Unbanked adults</strong></td><td>26 million</td><td>Target users for identity payments</td></tr>
                    <tr><td><strong>Mobile users</strong></td><td>30.6 million</td><td>Digital access exists</td></tr>
                    <tr><td><strong>Banking penetration</strong></td><td>28%</td><td>Vast underserved population</td></tr>
                    <tr><td><strong>Government beneficiaries</strong></td><td>1.7M households</td><td>Immediate use case</td></tr>
                </tbody>
            </table>
        </div>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 5</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 6 — BUSINESS MODEL
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>Business Model</h1>
        
        <p style="font-size:14px;font-weight:500;color:#0F2138;">Multiple revenue streams across the financial interoperability value chain.</p>
        
        <div class="table-wrap">
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
        </div>
        
        <h2>Why VouchMorph Wins</h2>
        
        <div class="features">
            <div class="feature"><span class="icon">🔗</span><div class="label">Identity-Based</div><div class="desc">Send to a phone number, not an account</div></div>
            <div class="feature"><span class="icon">🏛️</span><div class="label">Institution Agnostic</div><div class="desc">Works across any bank, MNO, or payment service</div></div>
            <div class="feature"><span class="icon">📈</span><div class="label">Network Effect</div><div class="desc">Each institution added increases value for all</div></div>
            <div class="feature"><span class="icon">🔒</span><div class="label">Non-Custodial</div><div class="desc">No client funds held — lower regulatory burden</div></div>
            <div class="feature"><span class="icon">⚡</span><div class="label">Real-Time</div><div class="desc">Instant execution with delayed net settlement</div></div>
            <div class="feature"><span class="icon">🛡️</span><div class="label">Patent Granted</div><div class="desc">Unique identity-routing and swap architecture</div></div>
        </div>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 6</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 7 — COMPETITIVE MOAT
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>Competitive Moat</h1>
        
        <p style="font-size:14px;font-weight:500;color:#0F2138;">Six layers of defensibility that make VouchMorph difficult to replicate.</p>
        
        <div class="table-wrap">
            <table>
                <thead><tr><th>Moat Element</th><th>Why It Matters</th></tr></thead>
                <tbody>
                    <tr><td><strong>Patent Granted</strong></td><td>Identity-based routing, swap architecture, settlement method — legally protected</td></tr>
                    <tr><td><strong>First Mover Angola</strong></td><td>Established relationships with BNA, ABCCI, commercial banks, and MNOs</td></tr>
                    <tr><td><strong>Botswana Sandbox</strong></td><td>Regulatory validation and reference — a proven model for other markets</td></tr>
                    <tr><td><strong>Institution Adapters</strong></td><td>Working integrations with multiple banking and mobile money rails</td></tr>
                    <tr><td><strong>Non-Custodial Model</strong></td><td>No balance-sheet risk — significantly easier regulatory compliance</td></tr>
                    <tr><td><strong>Strategic Partners</strong></td><td>Intellegere (security) and Malakana (infrastructure) provide world-class execution</td></tr>
                </tbody>
            </table>
        </div>
        
        <div class="highlight-box" style="max-width:100%;">
            <strong>Competitive Comparison:</strong><br>
            SWIFT · Slow, message-only · Visa/Mastercard · Card-only, custodial<br>
            Mobile Money · Siloed, single-network · Commercial Banks · Fragmented, bilateral<br>
            <strong>VouchMorph · Identity-based, non-custodial, institution-agnostic</strong>
        </div>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 7</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 8 — MARKET ENTRY TIMELINE
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>Market Entry Timeline</h1>
        
        <p style="font-size:14px;font-weight:500;color:#0F2138;">A clear, phased path to commercial launch and regional expansion.</p>
        
        <div class="timeline">
            <div class="tl-item"><span class="phase">Phase 1</span><span class="date">Q3 2026</span><span class="desc"><strong>Botswana Sandbox</strong> — Regulatory validation</span></div>
            <div class="tl-item"><span class="phase">Phase 2</span><span class="date">Q3 2026</span><span class="desc"><strong>ABCCI Membership</strong> — Business ecosystem entry</span></div>
            <div class="tl-item"><span class="phase">Phase 3</span><span class="date">Q3–Q4 2026</span><span class="desc"><strong>Angola Market Mission</strong> — Stakeholder meetings, bank workshops</span></div>
            <div class="tl-item"><span class="phase">Phase 4</span><span class="date">Q4 2026</span><span class="desc"><strong>BNA Sandbox Application</strong> — Formal regulatory submission</span></div>
            <div class="tl-item"><span class="phase">Phase 5</span><span class="date">Q1 2027</span><span class="desc"><strong>Pilot Implementation</strong> — First institutions live</span></div>
            <div class="tl-item"><span class="phase">Phase 6</span><span class="date">Q2 2027</span><span class="desc"><strong>Commercial Launch</strong> — Full operations commence</span></div>
            <div class="tl-item"><span class="phase">Phase 7</span><span class="date">Q3 2027</span><span class="desc"><strong>Government Programs</strong> — Beneficiary disbursements</span></div>
            <div class="tl-item"><span class="phase">Phase 8</span><span class="date">2028</span><span class="desc"><strong>SADC Expansion</strong> — Botswana, Namibia, Zambia, and beyond</span></div>
        </div>
        
        <div class="highlight-box" style="max-width:100%;">
            <strong>With investment:</strong> 18 months to commercial launch<br>
            <strong>Without investment:</strong> 3–5 years organic growth
        </div>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 8</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 9 — USE OF FUNDS
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>Use of Funds</h1>
        
        <p style="font-size:14px;font-weight:500;color:#0F2138;">Capital allocation to accelerate commercialisation and regional expansion.</p>
        
        <div class="table-wrap">
            <table>
                <thead><tr><th>Category</th><th>Focus Area</th><th>Weight</th></tr></thead>
                <tbody>
                    <tr><td><strong>Product Development</strong></td><td>Platform enhancement, feature expansion, security hardening</td><td>~30%</td></tr>
                    <tr><td><strong>Market Entry</strong></td><td>Angola commercialisation, regulatory engagement, institutional partnerships</td><td>~25%</td></tr>
                    <tr><td><strong>Team Expansion</strong></td><td>Engineering, operations, compliance, business development</td><td>~20%</td></tr>
                    <tr><td><strong>Infrastructure</strong></td><td>Cloud hosting, disaster recovery, security operations</td><td>~15%</td></tr>
                    <tr><td><strong>Working Capital</strong></td><td>Operational runway and strategic reserves</td><td>~10%</td></tr>
                </tbody>
            </table>
        </div>
        
        <p style="font-size:12px;color:#8A96A3;">Detailed budget breakdown available upon request. Angola chamber of commerce covers local costs.</p>
        
        <div style="background:#F4EFE3;border:1px solid #D3DAD6;padding:14px 18px;margin:12px 0;">
            <div style="font-weight:700;font-size:14px;color:#0F2138;">Investment Summary</div>
            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:6px;">
                <span><strong style="color:#1D3557;">Amount:</strong> Growth capital</span>
                <span><strong style="color:#1D3557;">Equity:</strong> 20%</span>
                <span><strong style="color:#1D3557;">Valuation:</strong> BWP 7.5M pre-money</span>
                <span><strong style="color:#1D3557;">Stage:</strong> Seed / Growth</span>
            </div>
        </div>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 9</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 10 — TEAM
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>Leadership Team</h1>
        
        <p style="font-size:14px;font-weight:500;color:#0F2138;">A team with deep fintech, regulatory, and infrastructure expertise.</p>
        
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Role</th><th>Background</th></tr></thead>
                <tbody>
                    <tr><td><strong>Magdaline Lewis</strong></td><td>CEO, VouchMorph</td><td>Financial services, regulatory strategy</td></tr>
                    <tr><td><strong>Marvin Sehunelo</strong></td><td>Product Manager</td><td>System architecture, platform development</td></tr>
                    <tr><td><strong>Dr Tshenolo Kealeboga</strong></td><td>Operations Manager</td><td>Operations, risk management</td></tr>
                    <tr><td><strong>Zhetu Mabusa</strong></td><td>Compliance</td><td>AML/CFT, regulatory compliance</td></tr>
                </tbody>
            </table>
        </div>
        
        <h2>Strategic Partners</h2>
        
        <div class="table-wrap">
            <table>
                <thead><tr><th>Partner</th><th>Role</th><th>Capability</th></tr></thead>
                <tbody>
                    <tr><td><strong>Intellegere Holdings</strong></td><td>Cybersecurity &amp; Engineering</td><td>Security governance, CEH-certified team, 18 years experience</td></tr>
                    <tr><td><strong>Malakana Enterprises</strong></td><td>Infrastructure</td><td>Cloud hosting, 99.99% SLA, disaster recovery</td></tr>
                    <tr><td><strong>ABCCI</strong></td><td>Angola Market Entry</td><td>Local partnerships, regulatory access, business networks</td></tr>
                </tbody>
            </table>
        </div>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 10</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 11 — INVESTMENT PROPOSAL
    // ============================================================
    $html .= '
    <div class="page">
        <div class="brand" style="margin-bottom:16px;">VOUCHMORPH <span>·</span></div>
        <h1>Investment Proposal</h1>
        
        <p style="font-size:14px;font-weight:500;color:#0F2138;">A strategic opportunity to participate in Africa\'s next-generation financial infrastructure.</p>
        
        <div style="border:2px solid #8A6D3B;padding:20px 24px;background:#F4EFE3;margin:16px 0;text-align:center;">
            <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-size:32px;font-weight:700;color:#0F2138;">BWP 1,500,000</div>
            <div style="font-size:14px;color:#4A5A6E;">for 20% Equity</div>
            <div style="font-size:12px;color:#8A96A3;margin-top:4px;">Pre-money valuation: BWP 7.5 million</div>
        </div>
        
        <div class="table-wrap">
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
        </div>
        
        <div class="highlight-box" style="max-width:100%;border-left-color:#24513A;">
            <strong>Investor Benefits:</strong><br>
            Board representation · Information rights · Pre-emptive rights<br>
            Tag-along and drag-along rights · Anti-dilution protection<br>
            <span style="font-size:12px;color:#8A96A3;">Full terms in Shareholders\' Agreement</span>
        </div>
        
        <p style="font-size:12px;color:#8A96A3;">Strategic investors interested in participating in Africa\'s digital financial transformation.</p>
        
        <div class="footer-text">
            <span>VouchMorph (Pty) Ltd · Gaborone, Botswana</span>
            <span class="conf">Proprietary &amp; Confidential</span>
            <span>Page 11</span>
        </div>
    </div>';

    // ============================================================
    // PAGE 12 — CONCLUSION
    // ============================================================
    $html .= '
    <div class="page" style="display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;">
        <div style="margin-bottom:auto;width:100%;text-align:left;">
            <div class="brand">VOUCHMORPH <span>·</span></div>
        </div>
        
        <div style="flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;">
            <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-size:42px;font-weight:700;color:#0F2138;line-height:1.2;max-width:500px;">
                Africa has already invested billions building payment rails.
            </div>
            
            <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-size:28px;font-weight:700;color:#8A6D3B;margin-top:16px;">
                VouchMorph is building the identity layer.
            </div>
            
            <div style="font-size:16px;color:#4A5A6E;max-width:450px;margin-top:16px;line-height:1.6;">
                Our first deployment is Angola.<br>
                Our ambition is every interoperable payment network in Africa.
            </div>
            
            <div style="border-top:3px solid #8A6D3B;padding-top:20px;margin-top:28px;max-width:400px;">
                <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-size:18px;font-weight:700;color:#0F2138;">
                    VouchMorph is not a payments company.
                </div>
                <div style="font-family:\'IBM Plex Sans Condensed\',sans-serif;font-size:18px;font-weight:700;color:#8A6D3B;">
                    VouchMorph is the access layer of the African financial system.
                </div>
            </div>
        </div>
        
        <div style="margin-top:auto;width:100%;text-align:center;border-top:1px solid #D3DAD6;padding-top:10px;">
            <div style="font-size:11px;color:#4A5A6E;font-weight:500;">VouchMorph Proprietary Limited · Registration: CIPA BW00009655259</div>
            <div style="font-size:9px;color:#8A96A3;margin-top:2px;">Gaborone, Botswana · © 2026 VouchMorph · All rights reserved</div>
            <div style="font-size:8px;color:#7A2118;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;margin-top:4px;">Proprietary &amp; Confidential</div>
            <div style="font-size:9px;color:#8A96A3;margin-top:2px;">Page 12</div>
        </div>
    </div>';

    $html .= '
</body>
</html>';

    return $html;
}
?>
