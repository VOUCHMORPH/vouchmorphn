<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VouchMorph – Swap Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ============================================================
   ROOT VARIABLES
   ============================================================ */
:root {
    --bg: #0a0a12;
    --surface: rgba(255,255,255,0.04);
    --surface-hover: rgba(255,255,255,0.08);
    --border: rgba(255,255,255,0.08);
    --border-active: rgba(0,240,255,0.3);
    --text: #ffffff;
    --text-muted: #8888a0;
    --text-dim: #505070;
    --primary: #00f0ff;
    --primary-dark: #00c0d0;
    --gradient: linear-gradient(135deg, #00f0ff 0%, #b000ff 100%);
    --success: #00e676;
    --warning: #ffc107;
    --danger: #ff5252;
    --radius: 12px;
    --radius-sm: 8px;
    --shadow: 0 8px 32px rgba(0,0,0,0.4);
    --font: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
    min-height: 100vh;
    padding: 16px;
    line-height: 1.5;
}

/* Scrollbar */
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--text-dim); border-radius: 4px; }

/* ============================================================
   CONTAINER
   ============================================================ */
.container { max-width: 1200px; margin: 0 auto; }

/* ============================================================
   TOPBAR
   ============================================================ */
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 20px;
}
.logo {
    font-size: 22px;
    font-weight: 800;
    background: var(--gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.topbar-right {
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: 14px;
}
.topbar-right .greeting { color: var(--text-muted); }
.logout-btn {
    color: var(--text-muted);
    text-decoration: none;
    padding: 6px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    transition: var(--transition);
}
.logout-btn:hover { background: var(--surface-hover); color: var(--text); }

/* ============================================================
   SECTIONS
   ============================================================ */
.section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 16px;
}
.section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 12px;
}
.section-title .badge {
    background: var(--primary);
    color: #000;
    font-size: 10px;
    padding: 1px 10px;
    border-radius: 10px;
    font-weight: 700;
}
.section-title .badge-secondary {
    background: rgba(255,255,255,0.06);
    color: var(--text-muted);
    font-size: 10px;
    padding: 1px 10px;
    border-radius: 10px;
}

/* ============================================================
   ACTION ROW - TWO MAIN BUTTONS
   ============================================================ */
.action-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.action-btn {
    padding: 20px 24px;
    background: var(--surface);
    border: 2px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    transition: var(--transition);
    text-align: left;
    font-family: var(--font);
    color: var(--text);
    position: relative;
}
.action-btn:hover {
    border-color: var(--border-active);
    background: var(--surface-hover);
    transform: translateY(-2px);
}
.action-btn .icon { font-size: 28px; display: block; margin-bottom: 6px; }
.action-btn .label { font-size: 16px; font-weight: 600; }
.action-btn .desc { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.action-btn .selected-info {
    font-size: 13px;
    color: var(--primary);
    margin-top: 4px;
}
.action-btn.active {
    border-color: var(--primary);
    background: rgba(0,240,255,0.06);
    box-shadow: 0 0 40px rgba(0,240,255,0.03);
}

/* ============================================================
   SOURCE / DESTINATION GRID
   ============================================================ */
.source-grid, .dest-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 10px;
}
.source-item, .dest-item {
    padding: 12px 14px;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 10px;
}
.source-item:hover, .dest-item:hover {
    background: var(--surface-hover);
    border-color: var(--border-active);
}
.source-item.selected, .dest-item.selected {
    border-color: var(--primary);
    background: rgba(0,240,255,0.08);
}
.source-item .icon { font-size: 18px; flex-shrink: 0; }
.source-item .info { flex: 1; min-width: 0; }
.source-item .name { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.source-item .detail { font-size: 11px; color: var(--text-muted); }
.source-item .balance-display {
    font-size: 13px;
    font-weight: 600;
    color: var(--success);
    white-space: nowrap;
}
.source-item .balance-display.unknown { color: var(--text-dim); }
.source-item .actions {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}
.balance-btn {
    background: none;
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text-muted);
    padding: 2px 8px;
    font-size: 10px;
    cursor: pointer;
    transition: var(--transition);
}
.balance-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
}
.balance-btn.loading {
    opacity: 0.5;
    pointer-events: none;
}
.remove-btn {
    background: none;
    border: none;
    color: var(--text-dim);
    cursor: pointer;
    font-size: 14px;
    padding: 0 4px;
    transition: var(--transition);
}
.remove-btn:hover { color: var(--danger); }

/* ============================================================
   FORMS
   ============================================================ */
.form-group { margin-bottom: 14px; }
.form-group label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 4px;
}
.form-control {
    width: 100%;
    padding: 10px 14px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-size: 14px;
    font-family: var(--font);
    transition: var(--transition);
}
.form-control:focus {
    outline: none;
    border-color: var(--primary);
}
.form-control::placeholder { color: var(--text-dim); }
.form-control option { background: var(--bg); }
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.quick-amounts {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.quick-amount {
    padding: 4px 14px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    border-radius: 14px;
    font-size: 11px;
    cursor: pointer;
    transition: var(--transition);
    color: var(--text-muted);
    font-family: var(--font);
}
.quick-amount:hover {
    border-color: var(--primary);
    color: var(--text);
}

/* ============================================================
   PREVIEW BOX
   ============================================================ */
.preview-box {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 16px;
    margin: 12px 0;
}
.preview-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.preview-row:last-child { border-bottom: none; }
.preview-row .label { color: var(--text-muted); font-size: 13px; }
.preview-row .value { font-weight: 600; font-size: 14px; }
.preview-row .value.highlight {
    color: var(--primary);
    font-size: 20px;
}
.preview-row .value.success { color: var(--success); }
.preview-row .value.warning { color: var(--warning); }
.preview-row .value.danger { color: var(--danger); }

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
    padding: 10px 24px;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 600;
    font-family: var(--font);
    cursor: pointer;
    transition: var(--transition);
}
.btn-primary {
    background: var(--gradient);
    color: #000;
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,240,255,0.2); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.btn-success {
    background: var(--success);
    color: #000;
}
.btn-success:hover { transform: translateY(-2px); }
.btn-danger {
    background: var(--danger);
    color: #fff;
}
.btn-danger:hover { transform: translateY(-2px); }
.btn-secondary {
    background: rgba(255,255,255,0.06);
    color: var(--text);
    border: 1px solid var(--border);
}
.btn-secondary:hover { background: var(--surface-hover); }
.btn-sm { padding: 6px 14px; font-size: 12px; }
.btn-block { width: 100%; }
.btn-group {
    display: flex;
    gap: 10px;
    margin-top: 12px;
    flex-wrap: wrap;
}

/* ============================================================
   MODAL
   ============================================================ */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.75);
    backdrop-filter: blur(8px);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    max-width: 640px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 24px;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
}
.modal-header h2 { font-size: 20px; font-weight: 700; }
.modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 24px;
    cursor: pointer;
    transition: var(--transition);
}
.modal-close:hover { color: var(--text); }

/* ============================================================
   MESSAGES
   ============================================================ */
.message {
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    margin: 8px 0;
    font-size: 13px;
    display: none;
}
.message.show { display: block; }
.message.info { background: rgba(0,240,255,0.1); border-left: 3px solid var(--primary); color: var(--primary); }
.message.success { background: rgba(0,230,118,0.1); border-left: 3px solid var(--success); color: var(--success); }
.message.error { background: rgba(255,82,82,0.1); border-left: 3px solid var(--danger); color: var(--danger); }
.message.warning { background: rgba(255,193,7,0.1); border-left: 3px solid var(--warning); color: var(--warning); }

/* ============================================================
   IDENTITY SWAPS
   ============================================================ */
.identity-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin-bottom: 6px;
}
.identity-item .info { flex: 1; }
.identity-item .info .amount { font-weight: 600; color: var(--primary); }
.identity-item .info .identity { font-size: 13px; }
.identity-item .info .identity .label { color: var(--text-muted); }
.identity-item .info .meta { font-size: 11px; color: var(--text-dim); margin-top: 2px; }
.identity-item .status {
    font-size: 12px;
    font-weight: 600;
    padding: 2px 12px;
    border-radius: 10px;
    margin: 0 8px;
}
.identity-item .status.pending { background: rgba(255,193,7,0.15); color: var(--warning); }
.identity-item .status.claimed { background: rgba(0,230,118,0.15); color: var(--success); }
.identity-item .status.expired { background: rgba(255,82,82,0.15); color: var(--danger); }
.identity-item .claim-btn {
    padding: 4px 16px;
    border: none;
    border-radius: var(--radius-sm);
    background: var(--primary);
    color: #000;
    font-weight: 600;
    font-size: 12px;
    cursor: pointer;
    transition: var(--transition);
}
.identity-item .claim-btn:hover { transform: scale(1.05); }

/* ============================================================
   MULTI-SOURCE
   ============================================================ */
.multi-source-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin-bottom: 6px;
}
.multi-source-item .checkbox {
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
    cursor: pointer;
    flex-shrink: 0;
}
.multi-source-item .info { flex: 1; min-width: 0; }
.multi-source-item .name { font-size: 13px; font-weight: 500; }
.multi-source-item .detail { font-size: 11px; color: var(--text-muted); }
.multi-source-item .amount-input {
    width: 100px;
    padding: 4px 8px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    font-size: 13px;
    text-align: right;
    font-family: var(--font);
}
.multi-source-item .amount-input:focus {
    outline: none;
    border-color: var(--primary);
}
.multi-source-item .balance {
    font-size: 11px;
    color: var(--text-muted);
    white-space: nowrap;
}
.distribution-mode {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
}
.distribution-mode .mode-btn {
    padding: 4px 14px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    border-radius: 14px;
    font-size: 11px;
    cursor: pointer;
    transition: var(--transition);
    color: var(--text-muted);
    font-family: var(--font);
}
.distribution-mode .mode-btn.active {
    border-color: var(--primary);
    color: var(--primary);
    background: rgba(0,240,255,0.08);
}
.distribution-mode .mode-btn:hover { color: var(--text); }

/* ============================================================
   ACTIVITY
   ============================================================ */
.activity-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.activity-item:last-child { border-bottom: none; }
.activity-item .left { display: flex; align-items: center; gap: 10px; }
.activity-item .left .icon { font-size: 16px; }
.activity-item .left .details .type { font-size: 13px; font-weight: 500; }
.activity-item .left .details .time { font-size: 11px; color: var(--text-muted); }
.activity-item .right { text-align: right; }
.activity-item .right .amount { font-size: 14px; font-weight: 600; }
.activity-item .right .status {
    font-size: 11px;
    padding: 1px 10px;
    border-radius: 10px;
}
.activity-item .right .status.completed { color: var(--success); background: rgba(0,230,118,0.1); }
.activity-item .right .status.pending { color: var(--warning); background: rgba(255,193,7,0.1); }
.activity-item .right .status.failed { color: var(--danger); background: rgba(255,82,82,0.1); }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .action-row { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .source-grid, .dest-grid { grid-template-columns: 1fr; }
    .topbar { flex-wrap: wrap; gap: 8px; }
    .modal { padding: 16px; margin: 10px; }
    .multi-source-item { flex-wrap: wrap; }
    .multi-source-item .amount-input { width: 100%; }
    .identity-item { flex-wrap: wrap; gap: 8px; }
}
@media (max-width: 480px) {
    .action-btn { padding: 14px 16px; }
    .section { padding: 14px; }
    .source-item, .dest-item { padding: 10px 12px; }
}
</style>
</head>
<body>

<div class="container">

<!-- ============================================================
     TOPBAR
     ============================================================ -->
<div class="topbar">
    <div class="logo">VOUCHMORPH</div>
    <div class="topbar-right">
        <span class="greeting">Hello, <span id="userName">User</span></span>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<!-- ============================================================
     MAIN MESSAGE
     ============================================================ -->
<div id="mainMessage" class="message"></div>

<!-- ============================================================
     ACTION ROW - TWO MAIN BUTTONS
     ============================================================ -->
<div class="action-row">
    <button class="action-btn" id="swapFromBtn" onclick="openSwap('from')">
        <span class="icon">📤</span>
        <div class="label">Swap From</div>
        <div class="desc">Select source to send from</div>
        <div class="selected-info" id="fromSelectedInfo">No source selected</div>
    </button>
    <button class="action-btn" id="swapToBtn" onclick="openSwap('to')">
        <span class="icon">📥</span>
        <div class="label">Swap To</div>
        <div class="desc">Select destination to receive</div>
        <div class="selected-info" id="toSelectedInfo">No destination selected</div>
    </button>
</div>

<!-- ============================================================
     SOURCES SECTION
     ============================================================ -->
<div class="section" id="sourcesSection">
    <div class="section-title">
        <span>Your Sources</span>
        <div>
            <span class="badge-secondary" id="sourceCount">0 connected</span>
            <button class="btn btn-sm btn-secondary" onclick="addSource()" style="margin-left:10px;">+ Add</button>
        </div>
    </div>
    <div class="source-grid" id="sourceGrid">
        <div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--text-muted);">
            No sources connected. Click "Add" to connect a bank account, wallet, or card.
        </div>
    </div>
</div>

<!-- ============================================================
     DESTINATIONS SECTION
     ============================================================ -->
<div class="section" id="destinationsSection">
    <div class="section-title">
        <span>Saved Destinations</span>
        <div>
            <span class="badge-secondary" id="destCount">0 saved</span>
            <button class="btn btn-sm btn-secondary" onclick="addDestination()" style="margin-left:10px;">+ Add</button>
        </div>
    </div>
    <div class="dest-grid" id="destGrid">
        <div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--text-muted);">
            No destinations saved. Click "Add" to save a recipient.
        </div>
    </div>
</div>

<!-- ============================================================
     IDENTITY SWAPS SECTION
     ============================================================ -->
<div class="section" id="identitySection">
    <div class="section-title">
        <span>Identity Swaps</span>
        <span class="badge" id="identityCount">0 pending</span>
    </div>
    <div id="identityList">
        <div style="text-align:center;padding:16px;color:var(--text-muted);font-size:13px;">
            No pending identity swaps.
        </div>
    </div>
</div>

<!-- ============================================================
     RECENT ACTIVITY
     ============================================================ -->
<div class="section">
    <div class="section-title">
        <span>Recent Activity</span>
        <span class="badge-secondary">Last 10</span>
    </div>
    <div id="activityList">
        <div style="text-align:center;padding:16px;color:var(--text-muted);font-size:13px;">
            No recent activity.
        </div>
    </div>
</div>

</div>

<!-- ============================================================
     MODAL
     ============================================================ -->
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" id="modalContent">
        <div class="modal-header">
            <h2 id="modalTitle">Swap</h2>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div id="modalBody">
            <!-- Dynamic content -->
        </div>
    </div>
</div>

<script>
// ============================================================
// ============================================================
// CONFIGURATION
// ============================================================
// ============================================================
const CONFIG = {
    currency: 'BWP',
    currencySymbol: 'BWP',
    country: 'Botswana',
    api: {
        preview: '/api/v1/swap/preview.php',
        execute: '/api/v1/swap/execute.php',
        sources: '/api/v1/sources/list.php',
        balance: '/api/v1/sources/balance.php',
        addSource: '/api/v1/sources/add.php',
        removeSource: '/api/v1/sources/remove.php',
        destinations: '/api/v1/destinations/list.php',
        addDestination: '/api/v1/destinations/add.php',
        removeDestination: '/api/v1/destinations/remove.php',
        identityPending: '/api/v1/swap/identity/pending.php',
        identityClaim: '/api/v1/swap/identity/claim.php',
        identityInitiate: '/api/v1/swap/identity/initiate.php',
        activity: '/api/v1/swap/activity.php',
    }
};

// ============================================================
// PARTICIPANTS (from participants.yaml)
// ============================================================
const PARTICIPANTS = {
    'ZURUBANK': { name: 'ZURUBANK', type: 'BANK', asset_types: ['ACCOUNT'] },
    'MASCOM': { name: 'MASCOM', type: 'MNO', asset_types: ['WALLET'] },
    'ORANGE': { name: 'ORANGE', type: 'MNO', asset_types: ['WALLET'] },
    'CAZACOM': { name: 'CAZACOM', type: 'MNO', asset_types: ['WALLET'] },
    'BOC': { name: 'BOC', type: 'BANK', asset_types: ['ACCOUNT'] },
    'ATM': { name: 'ATM', type: 'ATM', asset_types: ['CASHOUT'] },
    'AGENT': { name: 'Agent', type: 'AGENT', asset_types: ['CASHOUT'] },
};

const ASSET_TYPES = {
    'ACCOUNT': { label: 'Bank Account', icon: '🏦', fields: ['account_number', 'account_name', 'bank_code'] },
    'WALLET': { label: 'Mobile Wallet', icon: '📱', fields: ['phone', 'wallet_name'] },
    'CARD': { label: 'Card', icon: '💳', fields: ['card_number', 'card_expiry', 'card_holder'] },
    'CASHOUT': { label: 'Cashout', icon: '💰', fields: ['beneficiary_phone'] },
};

// ============================================================
// STATE
// ============================================================
let state = {
    sources: [],
    destinations: [],
    identitySwaps: [],
    activity: [],
    selectedSource: null,
    selectedDestination: null,
    swapType: null, // 'from' or 'to'
    isMultiSource: false,
    selectedSources: [],
    distributionMode: 'ratio', // 'ratio' or 'manual'
    previewData: null,
    step: 0,
    currentSwapPayload: null,
    userName: 'User',
};

// ============================================================
// ============================================================
// INITIALIZATION
// ============================================================
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Load all data
    loadSources();
    loadDestinations();
    loadIdentitySwaps();
    loadActivity();
    updateUI();
});

// ============================================================
// ============================================================
// SOURCE MANAGEMENT
// ============================================================
// ============================================================
function loadSources() {
    // Mock data - replace with API call
    state.sources = [
        {
            id: 'src_1',
            institution: 'ZURUBANK',
            asset_type: 'ACCOUNT',
            identifier: '1234567890',
            name: 'Main Account',
            balance: null,
            is_default: true,
            saved: true
        },
        {
            id: 'src_2',
            institution: 'MASCOM',
            asset_type: 'WALLET',
            identifier: '71234567',
            name: 'Mobile Money',
            balance: null,
            is_default: false,
            saved: true
        },
        {
            id: 'src_3',
            institution: 'ZURUBANK',
            asset_type: 'CARD',
            identifier: '****1234',
            name: 'Visa Card',
            balance: null,
            is_default: false,
            saved: true
        }
    ];
    renderSources();
}

function renderSources() {
    const grid = document.getElementById('sourceGrid');
    if (!grid) return;
    
    document.getElementById('sourceCount').textContent = state.sources.length + ' connected';
    
    if (state.sources.length === 0) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--text-muted);">
                No sources connected. Click "Add" to connect a bank account, wallet, or card.
            </div>
        `;
        return;
    }
    
    grid.innerHTML = state.sources.map(src => {
        const assetInfo = ASSET_TYPES[src.asset_type] || ASSET_TYPES['ACCOUNT'];
        const isSelected = state.selectedSource === src.id;
        const balanceDisplay = src.balance !== null 
            ? `${CONFIG.currencySymbol} ${src.balance.toFixed(2)}`
            : '—';
        const balanceClass = src.balance !== null ? '' : 'unknown';
        
        return `
            <div class="source-item ${isSelected ? 'selected' : ''}" 
                 data-id="${src.id}"
                 onclick="selectSource('${src.id}')">
                <span class="icon">${assetInfo.icon}</span>
                <div class="info">
                    <div class="name">${src.name}</div>
                    <div class="detail">${src.institution} • ${src.asset_type} • ${src.identifier}</div>
                </div>
                <span class="balance-display ${balanceClass}">${balanceDisplay}</span>
                <div class="actions">
                    <button class="balance-btn" onclick="event.stopPropagation();checkBalance('${src.id}')" 
                            id="balanceBtn_${src.id}">🔄</button>
                    <button class="remove-btn" onclick="event.stopPropagation();removeSource('${src.id}')">✕</button>
                </div>
            </div>
        `;
    }).join('');
}

function selectSource(id) {
    state.selectedSource = state.selectedSource === id ? null : id;
    renderSources();
    updateUI();
}

async function checkBalance(id) {
    const src = state.sources.find(s => s.id === id);
    if (!src) return;
    
    const btn = document.getElementById('balanceBtn_' + id);
    if (btn) {
        btn.textContent = '⏳';
        btn.classList.add('loading');
    }
    
    try {
        // Simulate API call - replace with actual
        await new Promise(r => setTimeout(r, 800));
        
        // Mock balance response
        const mockBalances = {
            'src_1': 5420.75,
            'src_2': 3200.50,
            'src_3': 4250.25,
        };
        
        src.balance = mockBalances[id] || 0;
        renderSources();
        
        showMessage('mainMessage', 
            `${src.name}: ${CONFIG.currencySymbol} ${src.balance.toFixed(2)}`, 
            'success'
        );
    } catch (e) {
        showMessage('mainMessage', 'Failed to get balance', 'error');
    }
    
    if (btn) {
        btn.textContent = '🔄';
        btn.classList.remove('loading');
    }
}

function addSource() {
    openModal('Connect a Source', `
        <div id="addSourceMessage" class="message"></div>
        
        <div class="form-group">
            <label>Institution</label>
            <select class="form-control" id="addInst">
                ${Object.keys(PARTICIPANTS).map(k => 
                    `<option value="${k}">${PARTICIPANTS[k].name}</option>`
                ).join('')}
            </select>
        </div>
        
        <div class="form-group">
            <label>Asset Type</label>
            <select class="form-control" id="addAssetType" onchange="updateAssetFields()">
                ${Object.keys(ASSET_TYPES).map(k => 
                    `<option value="${k}">${ASSET_TYPES[k].label}</option>`
                ).join('')}
            </select>
        </div>
        
        <div id="assetFields">
            <div class="form-group">
                <label>Identifier</label>
                <input class="form-control" id="addIdentifier" placeholder="Account number, phone, or card number">
            </div>
            <div class="form-group">
                <label>Name (optional)</label>
                <input class="form-control" id="addName" placeholder="e.g., Main Account">
            </div>
        </div>
        
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="addSaveSource" checked>
                Save this source for future swaps
            </label>
        </div>
        
        <button class="btn btn-primary btn-block" onclick="saveNewSource()">Connect Source</button>
        <button class="btn btn-secondary btn-block" onclick="useManualSource()" style="margin-top:8px;">
            Use this once (don't save)
        </button>
    `);
}

function updateAssetFields() {
    const type = document.getElementById('addAssetType').value;
    const container = document.getElementById('assetFields');
    const info = ASSET_TYPES[type] || ASSET_TYPES['ACCOUNT'];
    
    let html = '';
    info.fields.forEach(field => {
        const label = field.replace('_', ' ').toUpperCase();
        html += `
            <div class="form-group">
                <label>${label}</label>
                <input class="form-control" id="addField_${field}" placeholder="Enter ${field.replace('_', ' ')}">
            </div>
        `;
    });
    // Add name field
    html += `
        <div class="form-group">
            <label>Name (optional)</label>
            <input class="form-control" id="addName" placeholder="e.g., My Account">
        </div>
    `;
    container.innerHTML = html;
}

function saveNewSource() {
    const inst = document.getElementById('addInst').value;
    const assetType = document.getElementById('addAssetType').value;
    const name = document.getElementById('addName').value.trim() || `${inst} ${ASSET_TYPES[assetType].label}`;
    const saveSource = document.getElementById('addSaveSource')?.checked ?? true;
    
    // Get identifier from dynamic fields
    const info = ASSET_TYPES[assetType];
    let identifier = '';
    info.fields.forEach(field => {
        const el = document.getElementById('addField_' + field);
        if (el && el.value.trim()) {
            identifier = el.value.trim();
        }
    });
    
    if (!identifier) {
        showModalMessage('addSourceMessage', 'Please enter an identifier.', 'error');
        return;
    }
    
    const newSource = {
        id: 'src_' + Date.now(),
        institution: inst,
        asset_type: assetType,
        identifier: identifier,
        name: name,
        balance: null,
        is_default: false,
        saved: saveSource
    };
    
    if (saveSource) {
        state.sources.push(newSource);
        renderSources();
        closeModal();
        showMessage('mainMessage', `Source "${name}" saved successfully!`, 'success');
    } else {
        // Use once without saving
        state.selectedSource = newSource.id;
        state.sources.push({ ...newSource, saved: false, id: 'temp_' + Date.now() });
        renderSources();
        closeModal();
        showMessage('mainMessage', 'Source ready for swap (not saved).', 'info');
        updateUI();
    }
}

function useManualSource() {
    // Same as save but with saveSource = false
    const inst = document.getElementById('addInst').value;
    const assetType = document.getElementById('addAssetType').value;
    const name = document.getElementById('addName').value.trim() || `${inst} ${ASSET_TYPES[assetType].label}`;
    
    const info = ASSET_TYPES[assetType];
    let identifier = '';
    info.fields.forEach(field => {
        const el = document.getElementById('addField_' + field);
        if (el && el.value.trim()) {
            identifier = el.value.trim();
        }
    });
    
    if (!identifier) {
        showModalMessage('addSourceMessage', 'Please enter an identifier.', 'error');
        return;
    }
    
    const newSource = {
        id: 'temp_' + Date.now(),
        institution: inst,
        asset_type: assetType,
        identifier: identifier,
        name: name + ' (one-time)',
        balance: null,
        is_default: false,
        saved: false
    };
    
    state.selectedSource = newSource.id;
    state.sources.push(newSource);
    renderSources();
    closeModal();
    showMessage('mainMessage', 'Source ready for swap (not saved).', 'info');
    updateUI();
}

function removeSource(id) {
    if (!confirm('Remove this source?')) return;
    state.sources = state.sources.filter(s => s.id !== id);
    if (state.selectedSource === id) state.selectedSource = null;
    renderSources();
    updateUI();
}

// ============================================================
// ============================================================
// DESTINATION MANAGEMENT
// ============================================================
// ============================================================
function loadDestinations() {
    // Mock data - replace with API call
    state.destinations = [
        {
            id: 'dest_1',
            institution: 'BOC',
            asset_type: 'ACCOUNT',
            identifier: '9876543210',
            name: 'Business Account',
            saved: true
        },
        {
            id: 'dest_2',
            institution: 'ORANGE',
            asset_type: 'WALLET',
            identifier: '76345678',
            name: 'Sister\'s Wallet',
            saved: true
        }
    ];
    renderDestinations();
}

function renderDestinations() {
    const grid = document.getElementById('destGrid');
    if (!grid) return;
    
    document.getElementById('destCount').textContent = state.destinations.length + ' saved';
    
    if (state.destinations.length === 0) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--text-muted);">
                No destinations saved. Click "Add" to save a recipient.
            </div>
        `;
        return;
    }
    
    grid.innerHTML = state.destinations.map(dest => {
        const assetInfo = ASSET_TYPES[dest.asset_type] || ASSET_TYPES['ACCOUNT'];
        const isSelected = state.selectedDestination === dest.id;
        
        return `
            <div class="dest-item ${isSelected ? 'selected' : ''}" 
                 data-id="${dest.id}"
                 onclick="selectDestination('${dest.id}')">
                <span class="icon">${assetInfo.icon}</span>
                <div class="info">
                    <div class="name">${dest.name}</div>
                    <div class="detail">${dest.institution} • ${dest.asset_type} • ${dest.identifier}</div>
                </div>
                <div class="actions">
                    <button class="remove-btn" onclick="event.stopPropagation();removeDestination('${dest.id}')">✕</button>
                </div>
            </div>
        `;
    }).join('');
}

function selectDestination(id) {
    state.selectedDestination = state.selectedDestination === id ? null : id;
    renderDestinations();
    updateUI();
}

function addDestination() {
    openModal('Add Destination', `
        <div id="addDestMessage" class="message"></div>
        
        <div class="form-group">
            <label>Institution</label>
            <select class="form-control" id="addDestInst">
                ${Object.keys(PARTICIPANTS).filter(k => k !== 'ATM' && k !== 'AGENT').map(k => 
                    `<option value="${k}">${PARTICIPANTS[k].name}</option>`
                ).join('')}
            </select>
        </div>
        
        <div class="form-group">
            <label>Asset Type</label>
            <select class="form-control" id="addDestAssetType" onchange="updateDestFields()">
                ${Object.keys(ASSET_TYPES).filter(k => k !== 'CASHOUT').map(k => 
                    `<option value="${k}">${ASSET_TYPES[k].label}</option>`
                ).join('')}
            </select>
        </div>
        
        <div id="destFields">
            <div class="form-group">
                <label>Identifier</label>
                <input class="form-control" id="addDestIdentifier" placeholder="Account number, phone, or card number">
            </div>
            <div class="form-group">
                <label>Name (optional)</label>
                <input class="form-control" id="addDestName" placeholder="e.g., Business Account">
            </div>
        </div>
        
        <button class="btn btn-primary btn-block" onclick="saveNewDestination()">Save Destination</button>
        <button class="btn btn-secondary btn-block" onclick="useManualDestination()" style="margin-top:8px;">
            Use this once (don't save)
        </button>
    `);
}

function updateDestFields() {
    const type = document.getElementById('addDestAssetType').value;
    const container = document.getElementById('destFields');
    const info = ASSET_TYPES[type] || ASSET_TYPES['ACCOUNT'];
    
    let html = '';
    info.fields.forEach(field => {
        const label = field.replace('_', ' ').toUpperCase();
        html += `
            <div class="form-group">
                <label>${label}</label>
                <input class="form-control" id="addDestField_${field}" placeholder="Enter ${field.replace('_', ' ')}">
            </div>
        `;
    });
    html += `
        <div class="form-group">
            <label>Name (optional)</label>
            <input class="form-control" id="addDestName" placeholder="e.g., My Account">
        </div>
    `;
    container.innerHTML = html;
}

function saveNewDestination() {
    const inst = document.getElementById('addDestInst').value;
    const assetType = document.getElementById('addDestAssetType').value;
    const name = document.getElementById('addDestName').value.trim() || `${inst} ${ASSET_TYPES[assetType].label}`;
    
    const info = ASSET_TYPES[assetType];
    let identifier = '';
    info.fields.forEach(field => {
        const el = document.getElementById('addDestField_' + field);
        if (el && el.value.trim()) {
            identifier = el.value.trim();
        }
    });
    
    if (!identifier) {
        showModalMessage('addDestMessage', 'Please enter an identifier.', 'error');
        return;
    }
    
    state.destinations.push({
        id: 'dest_' + Date.now(),
        institution: inst,
        asset_type: assetType,
        identifier: identifier,
        name: name,
        saved: true
    });
    
    renderDestinations();
    closeModal();
    showMessage('mainMessage', `Destination "${name}" saved!`, 'success');
}

function useManualDestination() {
    const inst = document.getElementById('addDestInst').value;
    const assetType = document.getElementById('addDestAssetType').value;
    const name = document.getElementById('addDestName').value.trim() || `${inst} ${ASSET_TYPES[assetType].label}`;
    
    const info = ASSET_TYPES[assetType];
    let identifier = '';
    info.fields.forEach(field => {
        const el = document.getElementById('addDestField_' + field);
        if (el && el.value.trim()) {
            identifier = el.value.trim();
        }
    });
    
    if (!identifier) {
        showModalMessage('addDestMessage', 'Please enter an identifier.', 'error');
        return;
    }
    
    state.selectedDestination = 'temp_dest_' + Date.now();
    state.destinations.push({
        id: 'temp_dest_' + Date.now(),
        institution: inst,
        asset_type: assetType,
        identifier: identifier,
        name: name + ' (one-time)',
        saved: false
    });
    renderDestinations();
    closeModal();
    showMessage('mainMessage', 'Destination ready for swap (not saved).', 'info');
    updateUI();
}

function removeDestination(id) {
    if (!confirm('Remove this destination?')) return;
    state.destinations = state.destinations.filter(d => d.id !== id);
    if (state.selectedDestination === id) state.selectedDestination = null;
    renderDestinations();
    updateUI();
}

// ============================================================
// ============================================================
// SWAP OPERATIONS
// ============================================================
// ============================================================
function openSwap(type) {
    state.swapType = type;
    state.step = 0;
    state.previewData = null;
    state.isMultiSource = false;
    state.selectedSources = [];
    
    if (type === 'from') {
        openSwapFrom();
    } else {
        openSwapTo();
    }
}

function openSwapFrom() {
    const hasSources = state.sources.length > 0;
    const hasSelected = state.selectedSource !== null;
    
    let html = `
        <div id="swapMessage" class="message"></div>
        
        <div class="form-group">
            <label>Select Source</label>
            ${hasSources ? `
                <select class="form-control" id="swapSourceSelect" onchange="onSourceSelectChange()">
                    <option value="">-- Select a saved source --</option>
                    ${state.sources.filter(s => s.saved !== false).map(s => 
                        `<option value="${s.id}" ${state.selectedSource === s.id ? 'selected' : ''}>
                            ${s.name} - ${s.institution} (${s.identifier})
                        </option>`
                    ).join('')}
                    <option value="__manual__">-- Enter manually (one-time) --</option>
                </select>
            ` : `
                <div style="color:var(--text-muted);padding:8px 0;">No saved sources. Click "Add" to connect one.</div>
                <button class="btn btn-secondary btn-sm" onclick="closeModal();addSource();">+ Add Source</button>
            `}
        </div>
    `;
    
    // If source selected, show details and proceed
    if (hasSelected) {
        const src = state.sources.find(s => s.id === state.selectedSource);
        if (src) {
            html += `
                <div class="preview-box" style="border-color:var(--primary);">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <div style="font-weight:600;">${src.name}</div>
                            <div style="font-size:13px;color:var(--text-muted);">${src.institution} • ${src.asset_type}</div>
                            <div style="font-size:13px;color:var(--text-muted);">${src.identifier}</div>
                        </div>
                        <div>
                            ${src.balance !== null ? 
                                `<span style="color:var(--success);font-weight:600;">${CONFIG.currencySymbol} ${src.balance.toFixed(2)}</span>` :
                                `<button class="balance-btn" onclick="checkBalance('${src.id}')">🔄 Check Balance</button>`
                            }
                        </div>
                    </div>
                </div>
            `;
        }
    }
    
    // Swap type selection
    html += `
        <div class="form-group">
            <label>Swap Type</label>
            <select class="form-control" id="swapTypeSelect" onchange="onSwapTypeChange()">
                <option value="DEPOSIT">Deposit (Send to account/wallet)</option>
                <option value="CASHOUT">Cashout (Generate ATM/Agent code)</option>
                <option value="IDENTITY">Send to Identity</option>
                <option value="MULTI_SOURCE">Multi-Source (Combine multiple sources)</option>
            </select>
        </div>
    `;
    
    // Amount
    html += `
        <div class="form-group">
            <label>Amount (${CONFIG.currencySymbol})</label>
            <input type="number" class="form-control" id="swapAmount" placeholder="0.00" step="0.01" min="0.01">
            <div class="quick-amounts">
                ${[50, 100, 200, 500, 1000].map(a => 
                    `<span class="quick-amount" onclick="document.getElementById('swapAmount').value=${a}">${a}</span>`
                ).join('')}
            </div>
        </div>
        
        <div class="form-group">
            <label>Your PIN</label>
            <input type="password" class="form-control" id="swapPin" placeholder="Enter your PIN" maxlength="6">
        </div>
    `;
    
    // Multi-source section (hidden initially)
    html += `
        <div id="multiSourceSection" style="display:none;">
            <div class="form-group">
                <label>Select Sources</label>
                <div id="multiSourceList">
                    ${state.sources.filter(s => s.saved !== false).map(s => `
                        <div class="multi-source-item">
                            <input type="checkbox" class="checkbox" value="${s.id}" onchange="updateMultiSourceTotal()">
                            <div class="info">
                                <div class="name">${s.name}</div>
                                <div class="detail">${s.institution} • ${s.identifier}</div>
                            </div>
                            <span class="balance">${s.balance !== null ? CONFIG.currencySymbol + ' ' + s.balance.toFixed(2) : '—'}</span>
                            <input type="number" class="amount-input" placeholder="Amount" step="0.01" min="0" onchange="updateMultiSourceTotal()">
                        </div>
                    `).join('')}
                </div>
            </div>
            <div class="distribution-mode">
                <button class="mode-btn active" onclick="setDistributionMode('ratio')">Split by Ratio</button>
                <button class="mode-btn" onclick="setDistributionMode('manual')">Manual Entry</button>
            </div>
            <div id="multiSourceTotal" style="text-align:right;color:var(--text-muted);font-size:13px;">
                Total: ${CONFIG.currencySymbol} 0.00
            </div>
        </div>
    `;
    
    // Buttons
    const canProceed = hasSelected || state.sources.length > 0;
    html += `
        <div class="btn-group">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="swapPreviewBtn" onclick="previewSwap()" ${!canProceed ? 'disabled' : ''}>
                Preview Swap →
            </button>
        </div>
    `;
    
    setModalContent('Swap From', html);
}

function openSwapTo() {
    const hasDestinations = state.destinations.length > 0;
    const hasSelected = state.selectedDestination !== null;
    
    let html = `
        <div id="swapMessage" class="message"></div>
        
        <div class="form-group">
            <label>Select Destination</label>
            ${hasDestinations ? `
                <select class="form-control" id="swapDestSelect" onchange="onDestSelectChange()">
                    <option value="">-- Select a saved destination --</option>
                    ${state.destinations.filter(d => d.saved !== false).map(d => 
                        `<option value="${d.id}" ${state.selectedDestination === d.id ? 'selected' : ''}>
                            ${d.name} - ${d.institution} (${d.identifier})
                        </option>`
                    ).join('')}
                    <option value="__manual__">-- Enter manually (one-time) --</option>
                </select>
            ` : `
                <div style="color:var(--text-muted);padding:8px 0;">No saved destinations. Click "Add" to save one.</div>
                <button class="btn btn-secondary btn-sm" onclick="closeModal();addDestination();">+ Add Destination</button>
            `}
        </div>
    `;
    
    if (hasSelected) {
        const dest = state.destinations.find(d => d.id === state.selectedDestination);
        if (dest) {
            html += `
                <div class="preview-box" style="border-color:var(--primary);">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <div style="font-weight:600;">${dest.name}</div>
                            <div style="font-size:13px;color:var(--text-muted);">${dest.institution} • ${dest.asset_type}</div>
                            <div style="font-size:13px;color:var(--text-muted);">${dest.identifier}</div>
                        </div>
                    </div>
                </div>
            `;
        }
    }
    
    html += `
        <div class="btn-group">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="destConfirmBtn" onclick="confirmDestination()" ${!hasSelected ? 'disabled' : ''}>
                Confirm Destination →
            </button>
        </div>
    `;
    
    setModalContent('Swap To', html);
}

function onSourceSelectChange() {
    const select = document.getElementById('swapSourceSelect');
    if (!select) return;
    
    const value = select.value;
    if (value === '__manual__') {
        closeModal();
        addSource();
        return;
    }
    
    state.selectedSource = value || null;
    renderSources();
    updateUI();
    
    // Rebuild modal with selection
    openSwap('from');
}

function onDestSelectChange() {
    const select = document.getElementById('swapDestSelect');
    if (!select) return;
    
    const value = select.value;
    if (value === '__manual__') {
        closeModal();
        addDestination();
        return;
    }
    
    state.selectedDestination = value || null;
    renderDestinations();
    updateUI();
    
    // Rebuild modal with selection
    openSwap('to');
}

function onSwapTypeChange() {
    const select = document.getElementById('swapTypeSelect');
    if (!select) return;
    
    const type = select.value;
    const multiSection = document.getElementById('multiSourceSection');
    
    if (type === 'MULTI_SOURCE') {
        multiSection.style.display = 'block';
        state.isMultiSource = true;
    } else {
        multiSection.style.display = 'none';
        state.isMultiSource = false;
        state.selectedSources = [];
    }
}

function setDistributionMode(mode) {
    state.distributionMode = mode;
    document.querySelectorAll('.distribution-mode .mode-btn').forEach(btn => {
        btn.classList.toggle('active', btn.textContent.toLowerCase().includes(mode));
    });
}

function updateMultiSourceTotal() {
    const items = document.querySelectorAll('.multi-source-item');
    let total = 0;
    items.forEach(item => {
        const checkbox = item.querySelector('.checkbox');
        const input = item.querySelector('.amount-input');
        if (checkbox.checked && input.value) {
            total += parseFloat(input.value) || 0;
        }
    });
    document.getElementById('multiSourceTotal').textContent = `Total: ${CONFIG.currencySymbol} ${total.toFixed(2)}`;
}

function confirmDestination() {
    if (!state.selectedDestination) {
        showModalMessage('swapMessage', 'Please select a destination.', 'warning');
        return;
    }
    closeModal();
    // Now open the "Swap From" flow with destination pre-selected
    openSwap('from');
    // Show that destination is selected
    showMessage('mainMessage', 'Destination selected. Now choose your source and amount.', 'info');
}

// ============================================================
// ============================================================
// PREVIEW & EXECUTE
// ============================================================
// ============================================================
async function previewSwap() {
    const amount = document.getElementById('swapAmount')?.value;
    const pin = document.getElementById('swapPin')?.value;
    const swapType = document.getElementById('swapTypeSelect')?.value || 'DEPOSIT';
    
    if (!state.selectedSource) {
        showModalMessage('swapMessage', 'Please select a source.', 'warning');
        return;
    }
    
    const src = state.sources.find(s => s.id === state.selectedSource);
    if (!src) {
        showModalMessage('swapMessage', 'Source not found.', 'error');
        return;
    }
    
    if (!amount || parseFloat(amount) <= 0) {
        showModalMessage('swapMessage', 'Please enter a valid amount.', 'warning');
        return;
    }
    
    if (!pin || pin.length < 4) {
        showModalMessage('swapMessage', 'Please enter your PIN.', 'warning');
        return;
    }
    
    // Build payload
    let payload = {
        swap_type: swapType,
        from_institution: src.institution,
        source_institution: src.institution,
        source_identifier: src.identifier,
        asset_type: src.asset_type,
        amount: parseFloat(amount),
        currency: CONFIG.currency,
        pin: pin,
        wallet_pin: pin,
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8),
    };
    
    // Add destination if selected
    if (state.selectedDestination) {
        const dest = state.destinations.find(d => d.id === state.selectedDestination);
        if (dest) {
            payload.to_institution = dest.institution;
            payload.destination_institution = dest.institution;
            payload.destination_identifier = dest.identifier;
            payload.destination_identifier_type = dest.asset_type === 'ACCOUNT' ? 'account' : 'phone';
            payload.destination_asset_type = dest.asset_type;
        }
    }
    
    // Multi-source
    if (swapType === 'MULTI_SOURCE') {
        const sources = [];
        const items = document.querySelectorAll('.multi-source-item');
        items.forEach(item => {
            const checkbox = item.querySelector('.checkbox');
            const input = item.querySelector('.amount-input');
            if (checkbox.checked && input.value) {
                const srcId = checkbox.value;
                const source = state.sources.find(s => s.id === srcId);
                if (source) {
                    sources.push({
                        institution: source.institution,
                        asset_type: source.asset_type,
                        identifier: source.identifier,
                        amount: parseFloat(input.value) || 0,
                    });
                }
            }
        });
        if (sources.length < 2) {
            showModalMessage('swapMessage', 'Select at least 2 sources for multi-source swap.', 'warning');
            return;
        }
        payload.sources = sources;
        payload.distribution_mode = state.distributionMode;
    }
    
    state.currentSwapPayload = payload;
    
    // Show preview
    try {
        const btn = document.getElementById('swapPreviewBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Loading...'; }
        
        // Call preview API
        const response = await fetch(CONFIG.api.preview, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        
        if (btn) { btn.disabled = false; btn.textContent = 'Preview Swap →'; }
        
        if (data.success || data.preview) {
            state.previewData = data.preview || data;
            showPreview();
        } else {
            showModalMessage('swapMessage', data.message || 'Preview failed.', 'error');
        }
    } catch (e) {
        // Mock preview for demo
        showPreviewMock(payload);
    }
}

function showPreviewMock(payload) {
    // Build a mock preview for testing
    const fee = payload.amount * 0.02;
    const forexFee = 0;
    const net = payload.amount - fee;
    
    state.previewData = {
        requested_amount: payload.amount,
        total_fee: fee,
        forex_fee: forexFee,
        net_amount: net,
        source_currency: CONFIG.currency,
        destination_currency: CONFIG.currency,
        exchange_rate: 1.0,
        breakdown: {
            transaction_fee: fee,
            forex_fee: 0,
            vat: 0,
        },
        mat

hematical_formulas: {
            'Amount_1': payload.amount,
            'F1': fee,
            'Amount_2': net,
        }
    };
    showPreview();
}

function showPreview() {
    const data = state.previewData;
    if (!data) return;
    
    const html = `
        <div id="previewContent">
            <div class="preview-box">
                <div class="preview-row">
                    <span class="label">Requested Amount</span>
                    <span class="value">${CONFIG.currencySymbol} ${(data.requested_amount || data.amount || 0).toFixed(2)}</span>
                </div>
                <div class="preview-row">
                    <span class="label">Transaction Fee</span>
                    <span class="value warning">${CONFIG.currencySymbol} ${(data.total_fee || 0).toFixed(2)}</span>
                </div>
                ${data.forex_fee ? `
                <div class="preview-row">
                    <span class="label">Forex Fee</span>
                    <span class="value warning">${CONFIG.currencySymbol} ${data.forex_fee.toFixed(2)}</span>
                </div>
                ` : ''}
                ${data.exchange_rate && data.exchange_rate !== 1 ? `
                <div class="preview-row">
                    <span class="label">Exchange Rate</span>
                    <span class="value">${data.exchange_rate.toFixed(4)}</span>
                </div>
                ` : ''}
                <div class="preview-row" style="border-bottom:none;padding-top:8px;">
                    <span class="label" style="font-weight:600;">Net Amount</span>
                    <span class="value highlight">${CONFIG.currencySymbol} ${(data.net_amount || 0).toFixed(2)}</span>
                </div>
            </div>
            
            <div style="font-size:12px;color:var(--text-dim);margin-bottom:12px;">
                ${data.mathematical_formulas ? 
                    Object.entries(data.mathematical_formulas).map(([k,v]) => 
                        `${k} = ${v}`
                    ).join(' • ') : ''
                }
            </div>
            
            <div class="btn-group">
                <button class="btn btn-secondary" onclick="backToSwapForm()">← Back</button>
                <button class="btn btn-success" id="confirmSwapBtn" onclick="executeSwap()">
                    ✅ Confirm & Execute
                </button>
            </div>
        </div>
    `;
    
    document.getElementById('modalBody').innerHTML = html;
}

function backToSwapForm() {
    openSwap('from');
}

async function executeSwap() {
    const btn = document.getElementById('confirmSwapBtn');
    if (btn) {
        btn.disabled = true;
        btn.textContent = '⏳ Processing...';
    }
    
    try {
        const response = await fetch(CONFIG.api.execute, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(state.currentSwapPayload)
        });
        const result = await response.json();
        
        if (result.success || result.status === 'success' || result.status === 'pending_cashout') {
            showSwapResult(result, true);
        } else {
            showSwapResult(result, false);
        }
    } catch (e) {
        // Mock success for demo
        showSwapResult({
            status: 'success',
            reference: 'SWAP_' + Date.now(),
            amount: state.currentSwapPayload.amount,
            fee: state.currentSwapPayload.amount * 0.02,
            message: 'Swap completed successfully!'
        }, true);
    }
}

function showSwapResult(result, success) {
    const isPending = result.status === 'pending_cashout';
    const icon = success ? (isPending ? '⏳' : '✅') : '❌';
    const color = success ? (isPending ? 'var(--warning)' : 'var(--success)') : 'var(--danger)';
    
    let html = `
        <div style="text-align:center;padding:20px 0;">
            <div style="font-size:48px;color:${color};">${icon}</div>
            <h3 style="margin:12px 0 4px;">${result.message || (success ? 'Swap Completed' : 'Swap Failed')}</h3>
            <p style="color:var(--text-muted);font-size:14px;">
                Reference: ${result.reference || result.swap_reference || 'N/A'}
            </p>
    `;
    
    if (success && result.amount) {
        html += `
            <div style="margin:12px 0;padding:12px;background:rgba(255,255,255,0.05);border-radius:var(--radius-sm);">
                <div style="font-size:12px;color:var(--text-muted);">Amount</div>
                <div style="font-size:24px;font-weight:700;">${CONFIG.currencySymbol} ${(result.amount || 0).toFixed(2)}</div>
                ${result.fee ? `<div style="font-size:12px;color:var(--text-muted);">Fee: ${CONFIG.currencySymbol} ${result.fee.toFixed(2)}</div>` : ''}
            </div>
        `;
    }
    
    if (isPending && result.atm_code) {
        html += `
            <div style="margin:12px 0;padding:16px;background:rgba(0,240,255,0.08);border:2px solid var(--primary);border-radius:var(--radius-sm);">
                <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">ATM/Agent Code</div>
                <div style="font-size:28px;font-weight:700;font-family:monospace;letter-spacing:4px;">${result.atm_code}</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Expires: ${result.code_expiry || '24 hours'}</div>
            </div>
        `;
    }
    
    if (!success && result.error) {
        html += `
            <div style="margin:12px 0;padding:12px;background:rgba(255,82,82,0.1);border-radius:var(--radius-sm);color:var(--danger);font-size:14px;">
                ${result.error}
            </div>
        `;
    }
    
    html += `
            <button class="btn btn-primary btn-block" onclick="closeModal();loadActivity();" style="margin-top:16px;">
                Done
            </button>
        </div>
    `;
    
    document.getElementById('modalBody').innerHTML = html;
}

// ============================================================
// ============================================================
// IDENTITY SWAPS
// ============================================================
// ============================================================
function loadIdentitySwaps() {
    // Mock data - replace with API call
    state.identitySwaps = [
        {
            id: 'id_1',
            swap_reference: 'SWAP_ID_001',
            amount: 500.00,
            currency: 'BWP',
            identity_type: 'national_id',
            identity_value: '123456789',
            status: 'pending',
            source_institution: 'ZURUBANK',
            created_at: '2026-07-14 10:30:00',
            expires_at: '2026-07-15 10:30:00'
        },
        {
            id: 'id_2',
            swap_reference: 'SWAP_ID_002',
            amount: 250.00,
            currency: 'BWP',
            identity_type: 'phone',
            identity_value: '71234567',
            status: 'claimed',
            source_institution: 'MASCOM',
            created_at: '2026-07-13 14:20:00',
            expires_at: '2026-07-14 14:20:00'
        }
    ];
    renderIdentitySwaps();
}

function renderIdentitySwaps() {
    const container = document.getElementById('identityList');
    const pending = state.identitySwaps.filter(s => s.status === 'pending');
    document.getElementById('identityCount').textContent = pending.length + ' pending';
    
    if (state.identitySwaps.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:16px;color:var(--text-muted);font-size:13px;">
                No identity swaps.
            </div>
        `;
        return;
    }
    
    container.innerHTML = state.identitySwaps.map(swap => `
        <div class="identity-item">
            <div class="info">
                <div class="amount">${CONFIG.currencySymbol} ${swap.amount.toFixed(2)}</div>
                <div class="identity">
                    <span class="label">${swap.identity_type}:</span> ${swap.identity_value}
                </div>
                <div class="meta">${swap.source_institution} • ${swap.created_at}</div>
            </div>
            <span class="status ${swap.status}">${swap.status}</span>
            ${swap.status === 'pending' ? 
                `<button class="claim-btn" onclick="claimIdentity('${swap.id}')">Claim</button>` : ''
            }
        </div>
    `).join('');
}

function claimIdentity(id) {
    const swap = state.identitySwaps.find(s => s.id === id);
    if (!swap) return;
    
    openModal('Claim Identity Swap', `
        <div id="claimMessage" class="message"></div>
        
        <div style="padding:12px;background:rgba(255,255,255,0.03);border-radius:var(--radius-sm);margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--text-muted);">Amount</span>
                <span style="font-weight:600;">${CONFIG.currencySymbol} ${swap.amount.toFixed(2)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:4px;">
                <span style="color:var(--text-muted);">From</span>
                <span>${swap.source_institution}</span>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:4px;">
                <span style="color:var(--text-muted);">Identity</span>
                <span>${swap.identity_type}: ${swap.identity_value}</span>
            </div>
        </div>
        
        <div class="form-group">
            <label>Confirm Your Identity</label>
            <input class="form-control" id="claimIdentityValue" placeholder="Enter ${swap.identity_type} to confirm" value="${swap.identity_value}">
        </div>
        
        <div class="form-group">
            <label>Choose Destination</label>
            <select class="form-control" id="claimDestType">
                <option value="CASHOUT">Cashout (ATM/Agent)</option>
                <option value="DEPOSIT">Deposit (Account/Wallet)</option>
            </select>
        </div>
        
        <div id="claimDestFields">
            <div class="form-group">
                <label>Destination Institution</label>
                <select class="form-control" id="claimDestInst">
                    ${Object.keys(PARTICIPANTS).filter(k => k !== 'ATM' && k !== 'AGENT').map(k => 
                        `<option value="${k}">${PARTICIPANTS[k].name}</option>`
                    ).join('')}
                </select>
            </div>
            <div class="form-group">
                <label>Destination Identifier</label>
                <input class="form-control" id="claimDestIdentifier" placeholder="Account number or phone">
            </div>
        </div>
        
        <button class="btn btn-success btn-block" onclick="submitClaim('${id}')">Claim Funds</button>
    `);
}

function submitClaim(id) {
    const identityValue = document.getElementById('claimIdentityValue')?.value.trim();
    const destType = document.getElementById('claimDestType')?.value;
    const destInst = document.getElementById('claimDestInst')?.value;
    const destIdentifier = document.getElementById('claimDestIdentifier')?.value.trim();
    
    if (!identityValue) {
        showModalMessage('claimMessage', 'Please confirm your identity.', 'warning');
        return;
    }
    
    if (!destIdentifier) {
        showModalMessage('claimMessage', 'Please enter a destination identifier.', 'warning');
        return;
    }
    
    // Mock claim success
    showModalMessage('claimMessage', '✅ Identity swap claimed! Funds will be sent to your destination.', 'success');
    
    // Update status
    const swap = state.identitySwaps.find(s => s.id === id);
    if (swap) {
        swap.status = 'claimed';
        renderIdentitySwaps();
    }
    
    setTimeout(() => closeModal(), 2000);
}

// ============================================================
// ============================================================
// ACTIVITY
// ============================================================
// ============================================================
function loadActivity() {
    // Mock data - replace with API call
    state.activity = [
        {
            id: 'act_1',
            type: 'DEPOSIT',
            amount: 500.00,
            status: 'completed',
            from: 'ZURUBANK',
            to: 'BOC',
            time: '2026-07-14 14:30:00'
        },
        {
            id: 'act_2',
            type: 'CASHOUT',
            amount: 200.00,
            status: 'pending',
            from: 'MASCOM',
            to: 'ATM',
            time: '2026-07-14 12:15:00'
        },
        {
            id: 'act_3',
            type: 'IDENTITY',
            amount: 150.00,
            status: 'completed',
            from: 'ZURUBANK',
            to: 'National ID: 123456789',
            time: '2026-07-13 09:00:00'
        }
    ];
    renderActivity();
}

function renderActivity() {
    const container = document.getElementById('activityList');
    
    if (state.activity.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:16px;color:var(--text-muted);font-size:13px;">
                No recent activity.
            </div>
        `;
        return;
    }
    
    const icons = {
        'DEPOSIT': '📥',
        'CASHOUT': '💰',
        'IDENTITY': '🔑',
        'MULTI_SOURCE': '📤',
        'TRANSFER': '↔️'
    };
    
    container.innerHTML = state.activity.map(act => `
        <div class="activity-item">
            <div class="left">
                <span class="icon">${icons[act.type] || '📋'}</span>
                <div class="details">
                    <div class="type">${act.type} <span style="color:var(--text-muted);font-weight:400;">${act.from} → ${act.to}</span></div>
                    <div class="time">${act.time}</div>
                </div>
            </div>
            <div class="right">
                <div class="amount">${CONFIG.currencySymbol} ${act.amount.toFixed(2)}</div>
                <span class="status ${act.status}">${act.status}</span>
            </div>
        </div>
    `).join('');
}

// ============================================================
// ============================================================
// UI HELPERS
// ============================================================
// ============================================================
function updateUI() {
    // Update Swap From button
    const fromBtn = document.getElementById('swapFromBtn');
    const fromInfo = document.getElementById('fromSelectedInfo');
    if (state.selectedSource) {
        const src = state.sources.find(s => s.id === state.selectedSource);
        if (src) {
            fromInfo.textContent = `${src.name} (${src.institution})`;
            fromBtn.classList.add('active');
        }
    } else {
        fromInfo.textContent = 'No source selected';
        fromBtn.classList.remove('active');
    }
    
    // Update Swap To button
    const toBtn = document.getElementById('swapToBtn');
    const toInfo = document.getElementById('toSelectedInfo');
    if (state.selectedDestination) {
        const dest = state.destinations.find(d => d.id === state.selectedDestination);
        if (dest) {
            toInfo.textContent = `${dest.name} (${dest.institution})`;
            toBtn.classList.add('active');
        }
    } else {
        toInfo.textContent = 'No destination selected';
        toBtn.classList.remove('active');
    }
}

function openModal(title, bodyHtml) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
    document.getElementById('modal').classList.add('active');
}

function setModalContent(title, bodyHtml) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
}

function closeModal() {
    document.getElementById('modal').classList.remove('active');
}

function showMessage(id, text, type = 'info') {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    el.className = `message show ${type}`;
    setTimeout(() => {
        el.classList.remove('show');
    }, 5000);
}

function showModalMessage(id, text, type = 'info') {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    el.className = `message show ${type}`;
    setTimeout(() => {
        el.classList.remove('show');
    }, 5000);
}

// Close modal on escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>
