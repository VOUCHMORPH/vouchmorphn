<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Modal Preview Gallery</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<style>
:root {
    --bg: #FAF9F6;
    --surface: #FFFFFF;
    --surface-muted: #F4F3EF;
    --border: rgba(16,30,27,0.12);
    --border-strong: rgba(16,30,27,0.22);
    --text: #10201C;
    --text-muted: #63706A;
    --text-dim: #8A968F;
    --primary: #10201C;
    --accent: #00A878;
    --accent-2: #FF7A59;
    --accent-soft: rgba(0,168,120,0.10);
    --success: #1F8A54;
    --warning: #B8860B;
    --danger: #C62828;
    --font: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --font-mono: ui-monospace, SFMono-Regular, 'Cascadia Mono', Consolas, monospace;
    --radius: 0;
    --max-w: 1040px;
}

* { margin: 0; padding: 0; box-sizing: border-box; }
body { 
    background: var(--bg); 
    color: var(--text); 
    font-family: var(--font); 
    padding: 40px 24px; 
    line-height: 1.5; 
    -webkit-font-smoothing: antialiased;
}

h1 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 8px;
}

.subtitle {
    font-size: 14px;
    color: var(--text-muted);
    margin-bottom: 32px;
}

/* ============================================================
   MODAL PREVIEWS — visual gallery of all modals
   ============================================================ */
.preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
    gap: 32px;
    max-width: var(--max-w);
    margin: 0 auto;
}

.preview-card {
    background: var(--surface);
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(16,30,27,0.06);
}

.preview-card-header {
    padding: 14px 20px;
    background: var(--surface-muted);
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.preview-card-header h3 {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text);
}

.preview-card-header .badge {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-dim);
    background: var(--bg);
    padding: 2px 10px;
    border: 1px solid var(--border);
}

.preview-card-body {
    padding: 20px;
}

/* ============================================================
   MODAL COMPONENTS — shared styles for all previews
   ============================================================ */
.modal-preview {
    background: var(--surface);
    border: 1px solid var(--border-strong);
    max-width: 480px;
    margin: 0 auto;
    animation: modalPop 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes modalPop {
    0% { 
        transform: scale(0.92) translateY(8px); 
        opacity: 0;
    }
    100% { 
        transform: scale(1) translateY(0); 
        opacity: 1;
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 22px;
    background: var(--surface-muted);
    border-bottom: 1px solid var(--border);
}

.modal-header h2 {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text);
    font-family: var(--font-mono);
}

.modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 20px;
    cursor: pointer;
    line-height: 1;
    padding: 4px;
}

.modal-body {
    padding: 22px;
}

/* Review Hero */
.review-hero {
    text-align: center;
    padding: 6px 0 18px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
}

.review-hero-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 8px;
    font-family: var(--font-mono);
}

.review-hero-amount {
    font-size: 42px;
    font-weight: 600;
    color: var(--text);
    font-family: var(--font-mono);
}

.review-hero-note {
    font-size: 11px;
    color: var(--success);
    font-weight: 600;
    margin-top: 8px;
}

/* Preview rows */
.preview-box {
    margin: 0;
}

.preview-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 13px 0;
    gap: 12px;
    font-size: 13px;
    border-bottom: 1px solid var(--border);
}

.preview-row:last-child {
    border-bottom: none;
}

.preview-row span:first-child {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-dim);
}

.preview-row .value {
    font-weight: 600;
    color: var(--text);
    text-align: right;
    font-family: var(--font-mono);
}

.preview-row .value.highlight {
    color: var(--accent);
    font-size: 20px;
}

/* Security reassurance */
.preview-security {
    display: flex;
    gap: 14px;
    align-items: flex-start;
    padding: 14px;
    background: var(--surface-muted);
    margin: 16px 0;
    border: 1px solid var(--border);
}

.preview-security-icon {
    width: 34px;
    height: 34px;
    background: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 15px;
    flex-shrink: 0;
}

.preview-security-text strong {
    display: block;
    font-size: 12px;
    margin-bottom: 4px;
}

.preview-security-text p {
    font-size: 11px;
    color: var(--text-muted);
    line-height: 1.5;
}

.preview-reassure {
    text-align: center;
    font-size: 11px;
    color: var(--text-dim);
    margin-top: 10px;
}

/* Modal actions */
.modal-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 20px;
}

.modal-actions .btn-secondary {
    flex: 0 0 auto;
    text-transform: none;
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-size: 12px;
    width: auto;
}

.modal-actions .btn-primary {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

/* Buttons */
.btn {
    padding: 14px 24px;
    border: none;
    font-size: 13px;
    font-weight: 700;
    font-family: var(--font);
    cursor: pointer;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    transition: all 0.2s ease;
    width: 100%;
}

.btn-primary {
    background: var(--primary);
    color: #fff;
}

.btn-primary:hover {
    background: var(--accent);
}

.btn-secondary {
    background: transparent;
    color: var(--text);
    border: 1px solid var(--border-strong);
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
}

.btn-secondary:hover {
    background: var(--surface-muted);
}

.btn-danger {
    background: var(--danger);
    color: #fff;
}

.btn-danger:hover {
    background: #b71c1c;
}

.cta-row {
    display: flex;
    justify-content: center;
    margin-top: 10px;
    gap: 10px;
}

.cta-row .btn {
    flex: 1 1 0;
    max-width: 360px;
}

/* Unhook summary */
.unhook-summary {
    border: 1px solid var(--border);
    padding: 20px;
    text-align: center;
    margin-bottom: 18px;
}

.unhook-summary .icon {
    font-size: 36px;
    margin-bottom: 8px;
}

.unhook-summary .amount {
    font-size: 24px;
    font-weight: 600;
    font-family: var(--font-mono);
    color: var(--accent);
}

/* Result box */
.result-box {
    text-align: center;
    padding: 10px 0;
    position: relative;
    overflow: visible;
}

.result-box .icon {
    font-size: 42px;
    color: var(--success);
    margin-bottom: 8px;
}

.result-box .result-title {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 8px;
}

.result-box .result-sub {
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 16px;
    font-family: var(--font-mono);
}

/* ATM code */
.atm-code {
    margin: 16px 0;
    padding: 18px;
    background: var(--surface-muted);
    border: 1px solid var(--border);
}

.atm-code .code {
    font-size: 24px;
    font-weight: 700;
    font-family: var(--font-mono);
    letter-spacing: 4px;
    color: var(--accent);
}

/* Confetti */
.confetti-piece {
    position: absolute;
    top: 10%;
    width: 6px;
    height: 6px;
    background: var(--accent);
    animation: confettiFall 1.1s ease-in forwards;
}

@keyframes confettiFall {
    0% { transform: translateY(-10px) rotate(0deg); opacity: 1; }
    100% { transform: translateY(140px) rotate(280deg); opacity: 0; }
}

/* Labels */
.preview-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-dim);
    display: block;
    margin-bottom: 4px;
}

/* Badge styles */
.badge-success {
    background: rgba(31,138,84,0.1);
    color: var(--success);
    padding: 2px 10px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

.badge-warning {
    background: rgba(184,134,11,0.1);
    color: var(--warning);
    padding: 2px 10px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

/* Responsive */
@media (max-width: 560px) {
    .preview-grid {
        grid-template-columns: 1fr;
    }
    .review-hero-amount {
        font-size: 28px;
    }
    .modal-actions {
        flex-direction: column;
    }
    .modal-actions .btn-secondary {
        width: 100%;
        text-align: center;
        padding: 12px;
    }
}
</style>
</head>
<body>

<h1>Modal Previews</h1>
<p class="subtitle">Every action now shows a real preview before you confirm — here's what each one looks like.</p>

<div class="preview-grid">

    <!-- ============================================================
         PREVIEW 1: SWAP PREVIEW
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Swap Preview</h3>
            <span class="badge">Before execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Review swap</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="review-hero">
                        <div class="review-hero-label">You'll receive</div>
                        <div class="review-hero-amount">1,450.00 BWP</div>
                        <div class="review-hero-note">Live quote — locked in for a few minutes</div>
                    </div>
                    <div class="preview-box">
                        <div class="preview-row">
                            <span>Swap type</span>
                            <span class="value">Deposit</span>
                        </div>
                        <div class="preview-row">
                            <span>From</span>
                            <span class="value">Zuru Bank</span>
                        </div>
                        <div class="preview-row">
                            <span>To</span>
                            <span class="value">Saccussalis</span>
                        </div>
                        <div class="preview-row">
                            <span>Amount</span>
                            <span class="value">1,500.00 BWP</span>
                        </div>
                        <div class="preview-row">
                            <span>Fee</span>
                            <span class="value">50.00 BWP</span>
                        </div>
                    </div>
                    <div class="preview-security">
                        <div class="preview-security-icon">🔒</div>
                        <div class="preview-security-text">
                            <strong>Your money is protected</strong>
                            <p>This swap is encrypted end-to-end and only moves once you tap Confirm below. Nothing happens until then.</p>
                        </div>
                    </div>
                    <div class="preview-reassure">Nothing is final until you confirm — you can still back out.</div>
                    <div class="modal-actions">
                        <button class="btn btn-secondary">Discard</button>
                        <button class="btn btn-primary">Confirm and swap</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW 2: HOOK PREVIEW
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Hook Preview</h3>
            <span class="badge">Before execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Preview hook</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="review-hero">
                        <div class="review-hero-label">You're about to hook</div>
                        <div class="review-hero-amount">2,850.00 BWP</div>
                        <div class="review-hero-note">2 sources will be hooked for 24 hours</div>
                    </div>
                    <div class="preview-box">
                        <div class="preview-row">
                            <span>Zuru Bank</span>
                            <span class="value">1,200.00 BWP</span>
                        </div>
                        <div class="preview-row">
                            <span>MNO Wallet</span>
                            <span class="value">1,650.00 BWP</span>
                        </div>
                        <div class="preview-row" style="border-bottom:none; font-weight:700;">
                            <span>Total held</span>
                            <span class="value highlight">2,850.00 BWP</span>
                        </div>
                    </div>
                    <div class="preview-reassure">These sources will be held for up to 24 hours. Nothing moves until you spend them.</div>
                    <div class="modal-actions">
                        <button class="btn btn-secondary">Cancel</button>
                        <button class="btn btn-primary">Confirm hook</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW 3: UNHOOK PREVIEW
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Unhook Preview</h3>
            <span class="badge">Before execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Unhook preview</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="unhook-summary" style="border-color: var(--warning);">
                        <div class="icon">🔓</div>
                        <div style="font-size:16px; font-weight:700; margin-bottom:4px;">Release this hook?</div>
                        <div style="font-size:12px; color:var(--text-muted);">2 sources · 2,850.00 BWP</div>
                        <div style="font-size:11px; color:var(--warning); margin-top:8px;">This releases every source in this hook — all together, not one at a time.</div>
                    </div>
                    <div style="font-size:12px; color:var(--text-muted); margin-bottom:16px;">
                        Anything already spent (e.g. in a swap) can't be unhooked. Unhooked sources go back to being normal, spendable sources in your Toolbox.
                    </div>
                    <div class="cta-row">
                        <button class="btn btn-secondary" style="flex:1;">Keep it hooked</button>
                        <button class="btn btn-danger" style="flex:1;">Unhook everything</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW 4: CLAIM PREVIEW
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Claim Preview</h3>
            <span class="badge">Before execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Claim preview</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="review-hero">
                        <div class="review-hero-label">You're claiming</div>
                        <div class="review-hero-amount">750.00 BWP</div>
                        <div class="review-hero-note">From Zuru Bank</div>
                    </div>
                    <div class="preview-box">
                        <div class="preview-row">
                            <span>Destination</span>
                            <span class="value">Deposit</span>
                        </div>
                        <div class="preview-row">
                            <span>Institution</span>
                            <span class="value">Saccussalis</span>
                        </div>
                        <div class="preview-row" style="border-bottom:none;">
                            <span>Account</span>
                            <span class="value">0011 2233 4455</span>
                        </div>
                    </div>
                    <div class="preview-reassure">This is final — confirm to complete the claim.</div>
                    <div class="modal-actions">
                        <button class="btn btn-secondary">Back</button>
                        <button class="btn btn-primary">Confirm claim</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW 5: ACTIVATE CARD PREVIEW
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Activate Card Preview</h3>
            <span class="badge">Before execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Activation preview</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="review-hero">
                        <div class="review-hero-label">Activate your VouchMorph Card</div>
                        <div class="review-hero-amount">25.00 BWP</div>
                        <div class="review-hero-note">One-time fee</div>
                    </div>
                    <div class="preview-box">
                        <div class="preview-row">
                            <span>From</span>
                            <span class="value">Zuru Bank</span>
                        </div>
                        <div class="preview-row" style="border-bottom:none;">
                            <span>Identifier</span>
                            <span class="value">0011 2233 4455</span>
                        </div>
                    </div>
                    <div class="preview-reassure">This fee activates your card immediately.</div>
                    <div class="modal-actions">
                        <button class="btn btn-secondary">Cancel</button>
                        <button class="btn btn-primary">Pay and activate</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW 6: SWIPE / EXECUTE PREVIEW
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Swipe Preview</h3>
            <span class="badge">Before execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Ready to swipe</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="review-hero">
                        <div class="review-hero-label">Total being paid</div>
                        <div class="review-hero-amount">2,850.00 BWP</div>
                        <div class="review-hero-note" style="color:var(--text-dim);">includes 50.00 BWP VouchMorph fee</div>
                    </div>
                    <div class="preview-box">
                        <div class="preview-row">
                            <span>Zuru Bank</span>
                            <span class="value">1,200.00 BWP</span>
                        </div>
                        <div class="preview-row">
                            <span>MNO Wallet</span>
                            <span class="value">1,650.00 BWP</span>
                        </div>
                    </div>
                    <div class="preview-reassure">This is the last step — swiping executes the swap immediately.</div>
                    <div class="modal-actions">
                        <button class="btn btn-secondary">Back</button>
                        <button class="btn btn-primary">Swipe</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW 7: SWAP RESULT (after execution)
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Swap Result</h3>
            <span class="badge">After execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Swap result</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="result-box" id="resultBoxRoot">
                        <div class="icon">✓</div>
                        <div class="result-title">Nice! Your money's on its way</div>
                        <div class="result-sub">Reference: SWAP_123456789</div>
                        <div style="font-size:22px; font-weight:600; font-family:var(--font-mono); margin-top:12px;">1,450.00 BWP</div>
                        <div class="result-stat" style="background:var(--accent-soft); border-left:3px solid var(--accent); padding:10px 14px; margin-top:14px; font-size:12px; color:var(--primary); text-align:left;">
                            You've made 3 swaps this month. You've got the hang of this. 🎉
                        </div>
                    </div>
                    <div class="cta-row" style="margin-top:16px;">
                        <button class="btn btn-primary">Done</button>
                    </div>
                    <div style="display:flex; justify-content:center; gap:20px; margin-top:12px;">
                        <button type="button" class="btn-secondary" style="background:transparent; border:none; color:var(--accent); font-size:12px; font-weight:600; cursor:pointer; font-family:var(--font);">View in Activity</button>
                        <button type="button" class="btn-secondary" style="background:transparent; border:none; color:var(--accent); font-size:12px; font-weight:600; cursor:pointer; font-family:var(--font);">Make another swap</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW 8: CASHOUT RESULT
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Cashout Result</h3>
            <span class="badge">After execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Swap result</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="result-box" id="resultBoxRoot2">
                        <div class="icon">✓</div>
                        <div class="result-title">Nice! Your cashout code is ready</div>
                        <div class="result-sub">Reference: SWAP_123456789</div>
                        <div class="atm-code">
                            <div class="code">7429 1836 5041</div>
                        </div>
                        <div style="margin-top:12px;">
                            <div style="font-size:22px; font-weight:600; font-family:var(--font-mono);">1,450.00 BWP</div>
                        </div>
                        <div class="result-stat" style="background:var(--accent-soft); border-left:3px solid var(--accent); padding:10px 14px; margin-top:14px; font-size:12px; color:var(--primary); text-align:left;">
                            You've made 3 swaps this month. You've got the hang of this. 🎉
                        </div>
                    </div>
                    <div class="cta-row" style="margin-top:16px;">
                        <button class="btn btn-primary">Done</button>
                    </div>
                    <div style="display:flex; justify-content:center; gap:20px; margin-top:12px;">
                        <button type="button" class="btn-secondary" style="background:transparent; border:none; color:var(--accent); font-size:12px; font-weight:600; cursor:pointer; font-family:var(--font);">View in Activity</button>
                        <button type="button" class="btn-secondary" style="background:transparent; border:none; color:var(--accent); font-size:12px; font-weight:600; cursor:pointer; font-family:var(--font);">Make another swap</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW 9: IDENTITY CLAIM RESULT
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Identity Claim Result</h3>
            <span class="badge">After execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Swap result</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="result-box" id="resultBoxRoot3">
                        <div class="icon">✓</div>
                        <div class="result-title">Sent! They'll be notified now</div>
                        <div class="result-sub">Reference: SWAP_123456789</div>
                        <div style="margin:12px 0; font-size:13px;">
                            <strong>National ID: 0000-1234-5678</strong>
                        </div>
                        <div style="font-size:22px; font-weight:600; font-family:var(--font-mono);">750.00 BWP</div>
                        <div class="atm-code" style="margin-top:12px;">
                            <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px;">Backup PIN — only share this if the recipient doesn't get the SMS</div>
                            <div class="code">4837 2910</div>
                        </div>
                    </div>
                    <div class="cta-row" style="margin-top:16px;">
                        <button class="btn btn-primary">Done</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW 10: HOOK SUCCESS
         ============================================================ -->
    <div class="preview-card">
        <div class="preview-card-header">
            <h3>Hook Success</h3>
            <span class="badge">After execution</span>
        </div>
        <div class="preview-card-body">
            <div class="modal-preview">
                <div class="modal-header">
                    <h2>Done</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="unhook-summary" style="border-color: var(--accent);">
                        <div style="font-size:36px; margin-bottom:8px;">✓</div>
                        <div style="font-size:16px; font-weight:700; color:var(--text);">2 sources hooked for 24 hours</div>
                        <div style="font-size:12px; color:var(--text-muted); margin-top:6px;">It's ready to use as a Swap source right away.</div>
                    </div>
                    <div class="cta-row" style="flex-direction:column; gap:10px;">
                        <button class="btn btn-primary" style="width:100%;">← Back to Card</button>
                        <button class="btn btn-secondary" style="width:100%;">Go to Home</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ============================================================
     FOOTER
     ============================================================ -->
<div style="max-width:var(--max-w); margin:48px auto 0; padding-top:24px; border-top:1px solid var(--border); text-align:center; font-size:12px; color:var(--text-dim); font-family:var(--font-mono);">
    &copy; 2026 VouchMorph Financial — Modal Preview Gallery
</div>

</body>
</html>
