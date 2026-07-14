<?php
// public/user/dashboard.php - FIXED VERSION

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Config/AssetTypeRegistry.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Application\Utils\SessionManager;
use Core\Config\AssetTypeRegistry;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

// FIX: Get user from session properly
$user = SessionManager::user();
$userId = $user['id'] ?? $user['user_id'] ?? null;

if (!$userId) {
    header("Location: login.php");
    exit;
}

// Get full user details from database
try {
    $db = DBConnection::getConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dbUser) {
        $user = array_merge($user, $dbUser);
        $user['id'] = $user['user_id'];
    }
} catch (Exception $e) {
    error_log("Dashboard: Failed to fetch user: " . $e->getMessage());
}

// Get user identifiers
$userIdentifiers = [];
try {
    $stmt = $db->prepare("
        SELECT identity_type, identity_value, is_verified 
        FROM user_identities 
        WHERE user_id = ? AND is_verified = true
    ");
    $stmt->execute([$userId]);
    $userIdentifiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback to users table
    $userIdentifiers = [];
}

$validIdentifiers = [];
$typeIcons = ['phone' => '📱', 'phone2' => '📱', 'phone3' => '📱', 'email' => '✉️', 'national_id' => '🆔', 'drivers_license' => '🚗', 'passport' => '📖'];

// Add from user table
foreach (['phone', 'phone2', 'phone3', 'email', 'national_id', 'drivers_license', 'passport'] as $type) {
    if (!empty($user[$type])) {
        $validIdentifiers[] = [
            'type' => $type,
            'value' => $user[$type],
            'icon' => $typeIcons[$type] ?? '🔑',
            'is_verified' => true
        ];
    }
}

// Add from user_identities table
foreach ($userIdentifiers as $id) {
    if (!in_array($id['identity_value'], array_column($validIdentifiers, 'value'))) {
        $validIdentifiers[] = [
            'type' => $id['identity_type'],
            'value' => $id['identity_value'],
            'icon' => $typeIcons[$id['identity_type']] ?? '🔑',
            'is_verified' => (bool)$id['is_verified']
        ];
    }
}

$primaryIdentifier = '';
foreach (['phone', 'email', 'national_id'] as $type) {
    if (!empty($user[$type])) {
        $primaryIdentifier = $user[$type];
        break;
    }
}
if (empty($primaryIdentifier) && !empty($validIdentifiers)) {
    $primaryIdentifier = $validIdentifiers[0]['value'];
}

// Load config
$config = LoadCountry::getConfig();
$countryName = $config['country'] ?? 'Botswana';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';
$currency = $config['currency'] ?? 'BWP';

// Load participants
function parseParticipantsYaml($path) {
    $participants = [];
    if (!file_exists($path)) return $participants;

    $lines = explode("\n", file_get_contents($path));
    $currentCode = null;
    $inAssetTypes = false;

    foreach ($lines as $rawLine) {
        $line = rtrim($rawLine);
        if (trim($line) === '' || ltrim($line)[0] === '#') continue;

        $indent = strlen($line) - strlen(ltrim($line));
        $trimmed = trim($line);

        if ($indent === 2 && preg_match('/^([A-Z0-9_]+):$/', $trimmed, $m)) {
            $currentCode = $m[1];
            $participants[$currentCode] = [
                'code' => $currentCode, 'name' => $currentCode,
                'type' => 'BANK', 'asset_types' => [],
            ];
            $inAssetTypes = false;
            continue;
        }
        if ($currentCode === null) continue;

        if ($indent === 4 && preg_match('/^(name|type|country|status):\s*(.+)$/', $trimmed, $m)) {
            $participants[$currentCode][$m[1]] = trim($m[2], '"\'');
            $inAssetTypes = false;
            continue;
        }
        if ($indent === 4 && $trimmed === 'asset_types:') { $inAssetTypes = true; continue; }
        if ($indent === 4) { $inAssetTypes = false; continue; }
        if ($inAssetTypes && $indent === 6 && preg_match('/^- (.+)$/', $trimmed, $m)) {
            $participants[$currentCode]['asset_types'][] = trim($m[1], '"\'');
        }
    }

    foreach ($participants as $code => &$p) {
        if (empty($p['asset_types'])) {
            $p['asset_types'] = ['ACCOUNT'];
        }
    }
    return $participants;
}

$countryFolder = __DIR__ . '/../../src/Core/Config/Countries/' . $countryName;
$participants = parseParticipantsYaml($countryFolder . '/participants.yaml');

// Asset types - FIX: Map to what SwapService supports
AssetTypeRegistry::initialize();
$allAssetTypes = AssetTypeRegistry::all();

// FIX: Only use asset types that SwapService supports
$supportedAssetTypes = ['ACCOUNT', 'WALLET'];
$assetFieldsMap = [];
$assetUIMap = [];

foreach ($allAssetTypes as $code => $cfg) {
    if (in_array($code, $supportedAssetTypes)) {
        $assetFieldsMap[$code] = $cfg['fields'] ?? [];
        $assetUIMap[$code] = $cfg['ui'] ?? [];
    }
}

// FIX: Destination asset types - only what SwapService supports
$destinationAssetTypes = ['ACCOUNT', 'WALLET'];

// Get balance
$balance = 0;
try {
    $stmt = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$userId]);
    $balance = (float)($stmt->fetchColumn() ?: 0);
} catch (Exception $e) {
    error_log("Balance error: " . $e->getMessage());
}

// Get recent swaps
$recentSwaps = [];
try {
    $stmt = $db->prepare("
        SELECT swap_reference, amount, from_institution, to_institution, 
               status, created_at, swap_type
        FROM swap_ledgers 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Recent swaps error: " . $e->getMessage());
}

$participantOptions = [];
foreach ($participants as $code => $p) {
    $participantOptions[$code] = [
        'name' => $p['name'] ?? $code,
        'type' => $p['type'] ?? 'BANK',
        'asset_types' => $p['asset_types'] ?? ['ACCOUNT'],
    ];
}

$apiUrl = '/api/v1/swap/execute.php';
$previewUrl = '/api/v1/swap/preview.php';
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: 'vouchmorph_live_1aB2cD3eF4gH5iJ6';
$identifiersJson = json_encode($validIdentifiers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VouchMorph | <?= htmlspecialchars($countryName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --ink: #121212;
    --paper: #F7F5F0;
    --panel: #FFFFFF;
    --cobalt: #2440FF;
    --amber: #FFB400;
    --forest: #14804A;
    --red: #D32F2F;
    --line: #D8D4CB;
    --fs-body: clamp(0.95rem, 0.85rem + 0.3vw, 1.25rem);
    --fs-label: clamp(0.7rem, 0.65rem + 0.15vw, 0.9rem);
    --fs-h1: clamp(1.3rem, 1rem + 1.2vw, 2.4rem);
    --fs-h2: clamp(1.1rem, 0.95rem + 0.6vw, 1.6rem);
    --fs-amount: clamp(2rem, 1.4rem + 2.4vw, 4.5rem);
    --space-unit: clamp(0.9rem, 0.75rem + 0.5vw, 1.6rem);
    --tap-min: 48px;
    --clip: clamp(10px, 0.8vw, 20px);
}
* { margin: 0; padding: 0; box-sizing: border-box; }
html { font-size: 16px; }
body {
    font-family: 'Inter', sans-serif;
    background: var(--paper);
    color: var(--ink);
    min-height: 100vh;
    font-size: var(--fs-body);
    line-height: 1.4;
}
.font-display { font-family: 'Space Grotesk', sans-serif; }
button, input, select { font-family: inherit; font-size: inherit; }
button:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid var(--cobalt); outline-offset: 2px; }
.clip { clip-path: polygon(0 0, calc(100% - var(--clip)) 0, 100% var(--clip), 100% 100%, 0 100%); }

.shell { max-width: min(1400px, 92vw); margin: 0 auto; }

/* Topbar - consistent */
.topbar {
    background: var(--ink);
    color: var(--paper);
    padding: var(--space-unit) calc(var(--space-unit) * 1.2);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.topbar-inner {
    max-width: min(1400px, 92vw);
    margin: 0 auto;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.logo {
    display: flex;
    align-items: center;
    gap: 0.6em;
}
.logo-mark {
    width: clamp(28px, 2.2vw, 44px);
    height: clamp(28px, 2.2vw, 44px);
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--cobalt);
    font-weight: 700;
    font-size: clamp(13px, 1.2vw, 20px);
    clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
}
.logo-text {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: var(--fs-h2);
    letter-spacing: -0.02em;
}
.user-area {
    display: flex;
    align-items: center;
    gap: 1.2em;
    font-size: var(--fs-label);
}
.user-area .id { opacity: 0.75; }
.logout-btn {
    background: none;
    border: none;
    color: var(--paper);
    opacity: 0.5;
    cursor: pointer;
    text-decoration: none;
}
.logout-btn:hover { opacity: 1; }

.home { padding: calc(var(--space-unit) * 1.5) 0 calc(var(--space-unit) * 3); }

/* Balance Card */
.balance-card {
    background: var(--ink);
    color: var(--paper);
    padding: calc(var(--space-unit) * 1.5) calc(var(--space-unit) * 1.6);
    margin-bottom: var(--space-unit);
}
.balance-card .label {
    font-size: var(--fs-label);
    opacity: 0.5;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.balance-card .amount {
    font-family: 'Space Grotesk', sans-serif;
    font-size: var(--fs-h1);
    font-weight: 700;
    margin-top: 0.2em;
}

/* Buttons - consistent */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 1em 1.4em;
    border: none;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: var(--fs-label);
    cursor: pointer;
    transition: all 0.2s;
    min-height: var(--tap-min);
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.btn-primary {
    background: var(--ink);
    color: var(--paper);
}
.btn-primary:hover { background: #2a2a2a; }
.btn-secondary {
    background: var(--panel);
    color: var(--ink);
    border: 2px solid var(--line);
}
.btn-secondary:hover { border-color: var(--ink); }
.btn-success {
    background: var(--forest);
    color: var(--paper);
}
.btn-success:hover { background: #0d6e3a; }
.btn-danger {
    background: var(--red);
    color: var(--paper);
}
.btn-danger:hover { background: #b71c1c; }
.btn-outline {
    background: transparent;
    color: var(--ink);
    border: 2px solid var(--ink);
}
.btn-outline:hover { background: var(--ink); color: var(--paper); }
.btn:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-sm { padding: 0.6em 1em; min-height: 36px; font-size: 0.7rem; }
.btn-lg { padding: 1.2em 2em; font-size: var(--fs-body); }
.btn-block { width: 100%; }

/* Grid - consistent */
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-unit); }
.grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-unit); }
.grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-unit); }

/* Cards - consistent */
.card {
    background: var(--panel);
    border: 2px solid var(--line);
    padding: var(--space-unit);
}
.card-title {
    font-size: var(--fs-label);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.5;
    margin-bottom: 0.3em;
}
.card-value {
    font-family: 'Space Grotesk', sans-serif;
    font-size: var(--fs-h1);
    font-weight: 700;
}

/* Quick action buttons */
.action-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-unit);
    margin-bottom: calc(var(--space-unit) * 1.5);
}
.action-btn {
    padding: calc(var(--space-unit) * 1.2) var(--space-unit);
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}
.action-btn:hover { transform: translateY(-3px); }
.action-btn .icon { font-size: clamp(1.8rem, 1.5rem + 0.5vw, 2.6rem); display: block; margin-bottom: 0.4em; }
.action-btn .label {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: var(--fs-label);
}

/* Activity - consistent */
.activity-item {
    display: flex;
    justify-content: space-between;
    padding: 0.7em 0;
    border-bottom: 1px solid var(--line);
}
.activity-item:last-child { border-bottom: none; }
.activity-item .route { font-weight: 500; }
.activity-item .amount { font-family: 'Space Grotesk', sans-serif; font-weight: 600; }
.activity-item .status { font-size: var(--fs-label); }
.activity-item .status.completed { color: var(--forest); }
.activity-item .status.pending { color: var(--amber); }
.activity-item .status.failed { color: var(--red); }

/* Identifier - consistent */
.identifier-item {
    display: flex;
    align-items: center;
    gap: 0.8em;
    padding: 0.7em;
    background: var(--paper);
    border: 1px solid var(--line);
}
.identifier-item .icon { font-size: 1.4em; }
.identifier-item .value { font-weight: 500; }
.identifier-item .type {
    font-size: var(--fs-label);
    opacity: 0.5;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.badge {
    display: inline-block;
    padding: 0.15em 0.7em;
    font-size: 0.6rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-radius: 2px;
    margin-left: auto;
}
.badge-success { background: #e8f5e9; color: #2e7d32; }
.badge-pending { background: #fff3e0; color: #e65100; }
.badge-danger { background: #ffebee; color: #c62828; }

/* Modal - consistent */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: var(--space-unit);
}
.modal-overlay.active { display: flex; }
.modal {
    background: var(--paper);
    max-width: 560px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: calc(var(--space-unit) * 1.5);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-unit);
    padding-bottom: var(--space-unit);
    border-bottom: 2px solid var(--line);
}
.modal-header h2 {
    font-family: 'Space Grotesk', sans-serif;
    font-size: var(--fs-h1);
}
.modal-close {
    background: none;
    border: none;
    font-size: 1.6em;
    cursor: pointer;
    color: #888;
    padding: 0.2em;
}
.modal-close:hover { color: var(--ink); }

/* Form - consistent */
.form-group { margin-bottom: var(--space-unit); }
.form-group label {
    display: block;
    font-size: var(--fs-label);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.5;
    margin-bottom: 0.3em;
}
.form-control {
    width: 100%;
    padding: 0.8em 1em;
    border: 2px solid var(--line);
    background: var(--panel);
    font-size: var(--fs-body);
    transition: border-color 0.2s;
}
.form-control:focus { outline: none; border-color: var(--cobalt); }
.form-control::placeholder { color: #bbb; }

/* Step indicator - consistent */
.step-indicator {
    display: flex;
    gap: 0.5em;
    margin-bottom: var(--space-unit);
}
.step-dot {
    flex: 1;
    height: 4px;
    border-radius: 2px;
    background: var(--line);
    transition: background 0.3s;
}
.step-dot.active { background: var(--cobalt); }
.step-dot.done { background: var(--forest); }
.step-label {
    display: flex;
    justify-content: space-between;
    font-size: var(--fs-label);
    opacity: 0.5;
    margin-bottom: var(--space-unit);
}
.step-label .active { opacity: 1; font-weight: 600; }

/* Quick amounts */
.quick-amounts {
    display: flex;
    gap: 0.5em;
    flex-wrap: wrap;
    margin-top: 0.5em;
}
.quick-amount {
    padding: 0.4em 1.2em;
    border: 1px solid var(--line);
    cursor: pointer;
    font-size: var(--fs-label);
    transition: all 0.2s;
    background: var(--panel);
}
.quick-amount:hover { border-color: var(--ink); background: var(--ink); color: var(--paper); }

/* Asset pills - consistent */
.asset-pills {
    display: flex;
    gap: 0.5em;
    flex-wrap: wrap;
}
.asset-pill {
    padding: 0.5em 1.2em;
    border: 2px solid var(--line);
    cursor: pointer;
    font-weight: 600;
    font-size: var(--fs-label);
    transition: all 0.2s;
    background: var(--panel);
}
.asset-pill:hover { border-color: var(--ink); }
.asset-pill.active { background: var(--cobalt); border-color: var(--cobalt); color: var(--paper); }

/* Message - consistent */
.message {
    padding: 0.8em 1em;
    margin: var(--space-unit) 0;
    font-size: var(--fs-label);
    display: none;
}
.message.show { display: block; }
.message.info { background: #e3f2fd; border-left: 3px solid #0d47a1; color: #0d47a1; }
.message.success { background: #e8f5e9; border-left: 3px solid #2e7d32; color: #2e7d32; }
.message.error { background: #ffebee; border-left: 3px solid #c62828; color: #c62828; }
.message.warning { background: #fff3e0; border-left: 3px solid #e65100; color: #e65100; }

/* Preview box */
.preview-box {
    background: var(--panel);
    border: 2px solid var(--line);
    padding: var(--space-unit);
    margin: var(--space-unit) 0;
}
.preview-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5em 0;
    border-bottom: 1px solid var(--line);
}
.preview-row:last-child { border-bottom: none; }
.preview-row .label { opacity: 0.5; }
.preview-row .value { font-weight: 600; }
.preview-row .value.highlight {
    color: var(--cobalt);
    font-family: 'Space Grotesk', sans-serif;
    font-size: var(--fs-h2);
}

/* Spinner */
.spinner {
    display: inline-block;
    width: 1.4em;
    height: 1.4em;
    border: 3px solid var(--line);
    border-top-color: var(--ink);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.flex { display: flex; }
.flex-between { display: flex; justify-content: space-between; align-items: center; }
.gap-8 { gap: 8px; }
.gap-16 { gap: 16px; }
.mt-8 { margin-top: 8px; }
.mt-16 { margin-top: 16px; }
.mb-16 { margin-bottom: 16px; }
.text-center { text-align: center; }
.text-muted { opacity: 0.5; }

@media (max-width: 768px) {
    .grid-4, .grid-3, .grid-2 { grid-template-columns: 1fr; }
    .action-grid { grid-template-columns: repeat(2, 1fr); }
    .modal { margin: 0; max-height: 100vh; border-radius: 0; }
}
@media (max-width: 480px) {
    .action-grid { grid-template-columns: 1fr 1fr; }
    .user-area .id { display: none; }
}
</style>
</head>
<body>

<div class="topbar"><div class="topbar-inner">
    <div class="logo"><div class="logo-mark clip">V</div><span class="logo-text">VOUCHMORPH</span></div>
    <div class="user-area">
        <span class="id"><?= htmlspecialchars($primaryIdentifier) ?></span>
        <a href="logout.php" class="logout-btn">Log out</a>
    </div>
</div></div>

<div class="shell">
<div class="home">
    <!-- Balance -->
    <div class="balance-card clip">
        <div class="label">Available Balance</div>
        <div class="amount"><?= $currencySymbol ?> <?= number_format($balance, 2) ?></div>
    </div>

    <!-- Quick Actions -->
    <div class="action-grid">
        <div class="action-btn card" onclick="openModal('deposit')">
            <span class="icon">💰</span>
            <div class="label">Deposit</div>
        </div>
        <div class="action-btn card" onclick="openModal('withdraw')">
            <span class="icon">🏦</span>
            <div class="label">Withdraw</div>
        </div>
        <div class="action-btn card" onclick="openModal('transfer')">
            <span class="icon">📤</span>
            <div class="label">Transfer</div>
        </div>
        <div class="action-btn card" onclick="openModal('identities')">
            <span class="icon">🔑</span>
            <div class="label">Identities</div>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid-3 mb-16">
        <div class="card">
            <div class="card-title">Balance</div>
            <div class="card-value"><?= $currencySymbol ?> <?= number_format($balance, 2) ?></div>
        </div>
        <div class="card">
            <div class="card-title">Recent Swaps</div>
            <div class="card-value" style="font-size:var(--fs-h2);"><?= count($recentSwaps) ?></div>
        </div>
        <div class="card">
            <div class="card-title">Identifiers</div>
            <div class="card-value" style="font-size:var(--fs-h2);"><?= count($validIdentifiers) ?></div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="card mb-16">
        <div class="flex-between mb-16">
            <div class="card-title" style="margin-bottom:0;">Recent Activity</div>
            <span class="text-muted" style="font-size:0.7rem;">Last 10</span>
        </div>
        <?php if (empty($recentSwaps)): ?>
            <div class="text-center text-muted" style="padding:1.5em 0;">No transactions yet.</div>
        <?php else: ?>
            <?php foreach ($recentSwaps as $swap): ?>
                <div class="activity-item">
                    <div>
                        <div class="route"><?= htmlspecialchars($swap['from_institution'] ?? '?') ?> → <?= htmlspecialchars($swap['to_institution'] ?? '?') ?></div>
                        <div style="font-size:0.7rem;opacity:0.4;"><?= date('M d, H:i', strtotime($swap['created_at'])) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div class="amount"><?= $currencySymbol ?> <?= number_format($swap['amount'] ?? 0, 2) ?></div>
                        <span class="status <?= strtolower($swap['status'] ?? 'pending') ?>"><?= htmlspecialchars($swap['status'] ?? 'pending') ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Identifiers -->
    <div class="card">
        <div class="flex-between mb-16">
            <div class="card-title" style="margin-bottom:0;">Your Identifiers</div>
            <button class="btn btn-secondary btn-sm" onclick="openModal('identities')">Manage</button>
        </div>
        <?php if (empty($validIdentifiers)): ?>
            <div class="text-muted" style="padding:0.5em 0;">No identifiers added.</div>
        <?php else: ?>
            <?php foreach (array_slice($validIdentifiers, 0, 3) as $id): ?>
                <div class="identifier-item">
                    <span class="icon"><?= $id['icon'] ?></span>
                    <div>
                        <div class="value"><?= htmlspecialchars($id['value']) ?></div>
                        <div class="type"><?= htmlspecialchars($id['type']) ?></div>
                    </div>
                    <?php if ($id['is_verified']): ?>
                        <span class="badge badge-success">Verified</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (count($validIdentifiers) > 3): ?>
                <div class="text-muted" style="font-size:0.7rem;margin-top:0.5em;">+<?= count($validIdentifiers) - 3 ?> more</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- MODAL -->
<div id="modal" class="modal-overlay" onclick="if(event.target===this)closeModal()">
    <div class="modal" id="modalContent">
        <div class="modal-header">
            <h2 id="modalTitle">Deposit</h2>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<script>
const participants = <?= json_encode($participantOptions) ?>;
const assetFields = <?= json_encode($assetFieldsMap) ?>;
const destinationAssetTypes = <?= json_encode($destinationAssetTypes) ?>;
const currencySymbol = '<?= $currencySymbol ?>';
const currency = '<?= $currency ?>';
const userIdentifiers = <?= $identifiersJson ?>;
const apiUrl = '<?= $apiUrl ?>';
const previewUrl = '<?= $previewUrl ?>';
const apiKey = '<?= $apiKey ?>';

const badgeMap = { BANK: '🏦', MNO: '📱', ORCHESTRATOR: '⚙️' };
const assetLabel = { ACCOUNT: 'ACCOUNT', WALLET: 'WALLET' };

let currentType = null;
let currentStep = 0;
let selFrom = null;
let selTo = null;
let selAsset = null;
let pendingPayload = null;

function openModal(type) {
    currentType = type;
    currentStep = 0;
    selFrom = null;
    selTo = null;
    selAsset = null;
    pendingPayload = null;
    
    const titles = {
        'deposit': '💰 Deposit Funds',
        'withdraw': '🏦 Withdraw Funds',
        'transfer': '📤 Send Money',
        'identities': '🔑 Your Identities'
    };
    
    document.getElementById('modalTitle').textContent = titles[type] || 'Transaction';
    document.getElementById('modal').classList.add('active');
    
    if (type === 'identities') {
        renderIdentities();
    } else {
        renderStep();
    }
}

function closeModal() {
    document.getElementById('modal').classList.remove('active');
}

function renderStep() {
    const body = document.getElementById('modalBody');
    
    if (currentStep === 0) body.innerHTML = renderStep0();
    else if (currentStep === 1) body.innerHTML = renderStep1();
    else if (currentStep === 2) body.innerHTML = renderStep2();
}

function renderStep0() {
    const isDeposit = currentType === 'deposit';
    const isWithdraw = currentType === 'withdraw';
    const isTransfer = currentType === 'transfer';
    
    let fromOptions = Object.entries(participants);
    let toOptions = Object.entries(participants);
    
    if (isDeposit) {
        fromOptions = userIdentifiers.length > 0 ? userIdentifiers.map(id => ['user_' + id.value, { name: id.value, type: 'USER' }]) : [];
        toOptions = Object.entries(participants);
    } else if (isWithdraw) {
        fromOptions = Object.entries(participants);
        toOptions = userIdentifiers.length > 0 ? userIdentifiers.map(id => ['user_' + id.value, { name: id.value, type: 'USER' }]) : [];
    }
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>
        <div class="step-label">
            <span class="active">1. Select</span>
            <span>2. Amount</span>
            <span>3. Confirm</span>
        </div>
    `;
    
    if (fromOptions.length > 0) {
        html += `
            <div class="form-group">
                <label>${isDeposit ? 'From (Your Source)' : isWithdraw ? 'From (Your Institution)' : 'From'}</label>
                <select class="form-control" id="fromSelect" onchange="selFrom=this.value">
                    <option value="">Select source...</option>
        `;
        fromOptions.forEach(([code, p]) => {
            const label = p.name || code;
            html += `<option value="${code}">${label}</option>`;
        });
        html += `</select></div>`;
    }
    
    if (toOptions.length > 0) {
        html += `
            <div class="form-group">
                <label>${isDeposit ? 'To (Destination)' : isWithdraw ? 'To (Your Account)' : 'To'}</label>
                <select class="form-control" id="toSelect" onchange="selTo=this.value">
                    <option value="">Select destination...</option>
        `;
        toOptions.forEach(([code, p]) => {
            const label = p.name || code;
            html += `<option value="${code}">${label}</option>`;
        });
        html += `</select></div>`;
    }
    
    html += `
        <button class="btn btn-primary btn-block" onclick="currentStep=1;renderStep()" disabled id="step0Next">
            Next →
        </button>
    `;
    
    // Enable next when both selected
    setTimeout(() => {
        document.getElementById('fromSelect')?.addEventListener('change', checkStep0);
        document.getElementById('toSelect')?.addEventListener('change', checkStep0);
        checkStep0();
    }, 100);
    
    return html;
}

function checkStep0() {
    const from = document.getElementById('fromSelect')?.value;
    const to = document.getElementById('toSelect')?.value;
    const btn = document.getElementById('step0Next');
    if (btn) btn.disabled = !from || !to;
}

function renderStep1() {
    const from = document.getElementById('fromSelect')?.value;
    const to = document.getElementById('toSelect')?.value;
    
    if (!from || !to) {
        return `
            <div class="message show error">Please select both source and destination.</div>
            <button class="btn btn-secondary btn-block" onclick="currentStep=0;renderStep()">← Back</button>
        `;
    }
    
    // FIX: Only use supported asset types (ACCOUNT, WALLET)
    let assetOptions = destinationAssetTypes;
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot done"></div>
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
        </div>
        <div class="step-label">
            <span>1. Select</span>
            <span class="active">2. Amount</span>
            <span>3. Confirm</span>
        </div>
        <div class="text-muted" style="font-size:0.8rem;margin-bottom:1em;">
            ${from} → ${to}
        </div>
        <div class="form-group">
            <label>Amount (${currencySymbol})</label>
            <input type="number" class="form-control" id="amountInput" placeholder="0.00" step="0.01" min="0.01">
            <div class="quick-amounts">
                ${[50, 100, 200, 500, 1000].map(a => 
                    `<span class="quick-amount" onclick="document.getElementById('amountInput').value=${a}">${a}</span>`
                ).join('')}
            </div>
        </div>
        <div class="form-group">
            <label>Asset Type</label>
            <div class="asset-pills" id="assetPills">
    `;
    
    assetOptions.forEach(a => {
        const label = assetLabel[a] || a;
        const isActive = selAsset === a || (!selAsset && a === 'ACCOUNT');
        html += `<span class="asset-pill ${isActive ? 'active' : ''}" onclick="selectAsset('${a}')">${label}</span>`;
    });
    
    if (!selAsset) selAsset = 'ACCOUNT';
    
    html += `
            </div>
        </div>
        <div class="form-group">
            <label>Your PIN</label>
            <input type="password" class="form-control" id="pinInput" placeholder="Enter your PIN" maxlength="6">
        </div>
        <div id="step1Message" class="message"></div>
        <div class="flex gap-16">
            <button class="btn btn-secondary" onclick="currentStep=0;renderStep()">← Back</button>
            <button class="btn btn-primary" onclick="goToConfirm()" id="step1Next">Preview →</button>
        </div>
    `;
    
    return html;
}

function selectAsset(asset) {
    selAsset = asset;
    document.querySelectorAll('#assetPills .asset-pill').forEach(el => {
        el.classList.toggle('active', el.textContent.trim() === (assetLabel[asset] || asset));
    });
}

function goToConfirm() {
    const amount = parseFloat(document.getElementById('amountInput')?.value) || 0;
    const pin = document.getElementById('pinInput')?.value || '';
    const from = document.getElementById('fromSelect')?.value;
    const to = document.getElementById('toSelect')?.value;
    
    const msg = document.getElementById('step1Message');
    if (!msg) return;
    
    if (amount <= 0) {
        msg.className = 'message show error';
        msg.textContent = 'Please enter a valid amount.';
        return;
    }
    
    if (!pin || pin.length < 4) {
        msg.className = 'message show error';
        msg.textContent = 'Please enter your PIN.';
        return;
    }
    
    msg.className = 'message';
    msg.textContent = '';
    
    // FIX: Build payload correctly
    const isDeposit = currentType === 'deposit';
    const isWithdraw = currentType === 'withdraw';
    const isTransfer = currentType === 'transfer';
    
    // FIX: Map from/to properly
    let fromInstitution = from;
    let toInstitution = to;
    let sourceIdentifier = '';
    
    // If from is a user identifier, use it as source_identifier
    if (from.startsWith('user_')) {
        sourceIdentifier = from.replace('user_', '');
        // Find the actual institution from the identifier
        const matched = userIdentifiers.find(id => id.value === sourceIdentifier);
        if (matched) {
            // Use the first participant as fallback
            fromInstitution = Object.keys(participants)[0] || 'ZURUBANK';
        }
    }
    
    // If to is a user identifier, use it as destination
    if (to.startsWith('user_')) {
        toInstitution = Object.keys(participants)[0] || 'ZURUBANK';
    }
    
    pendingPayload = {
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2,8),
        currency: currency,
        swap_type: isDeposit ? 'DEPOSIT' : (isWithdraw ? 'WITHDRAWAL' : 'TRANSFER'),
        from_institution: fromInstitution,
        to_institution: toInstitution,
        amount: amount,
        destination_asset_type: selAsset || 'ACCOUNT',
        pin: pin,
        wallet_pin: pin,
        source_identifier: sourceIdentifier || userIdentifiers[0]?.value || '',
    };
    
    currentStep = 2;
    renderStep();
}

function renderStep2() {
    const amount = pendingPayload?.amount || 0;
    const from = pendingPayload?.from_institution || '?';
    const to = pendingPayload?.to_institution || '?';
    const asset = pendingPayload?.destination_asset_type || 'ACCOUNT';
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot done"></div>
            <div class="step-dot done"></div>
            <div class="step-dot active"></div>
        </div>
        <div class="step-label">
            <span>1. Select</span>
            <span>2. Amount</span>
            <span class="active">3. Confirm</span>
        </div>
        <div id="previewContainer">
            <div class="preview-box">
                <div class="preview-row"><span class="label">From</span><span class="value">${from}</span></div>
                <div class="preview-row"><span class="label">To</span><span class="value">${to}</span></div>
                <div class="preview-row"><span class="label">Amount</span><span class="value">${currencySymbol} ${amount.toFixed(2)}</span></div>
                <div class="preview-row"><span class="label">Asset</span><span class="value">${assetLabel[asset] || asset}</span></div>
                <div class="preview-row" style="border-bottom:none;padding-top:0.8em;">
                    <span class="label">Total</span>
                    <span class="value highlight">${currencySymbol} ${amount.toFixed(2)}</span>
                </div>
            </div>
            <div id="previewStatus" class="text-center text-muted" style="padding:0.5em 0;">
                <span class="spinner"></span> Calculating fees...
            </div>
        </div>
        <div class="flex gap-16">
            <button class="btn btn-secondary" onclick="currentStep=1;renderStep()">← Back</button>
            <button class="btn btn-success" id="confirmBtn" onclick="executeSwap()" disabled>Confirm & Send</button>
        </div>
    `;
    
    // Get preview
    fetch(previewUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(pendingPayload)
    })
    .then(res => res.json())
    .then(data => {
        const status = document.getElementById('previewStatus');
        const confirmBtn = document.getElementById('confirmBtn');
        if (data.success || data.preview) {
            const p = data.preview || data;
            const fee = p.total_fee || p.fee || 0;
            const net = p.net_amount || p.amount || amount;
            status.innerHTML = `✅ Fee: ${currencySymbol} ${fee.toFixed(2)} | Net: ${currencySymbol} ${net.toFixed(2)}`;
            if (confirmBtn) confirmBtn.disabled = false;
        } else {
            status.innerHTML = `⚠️ ${data.message || 'Fee calculation failed. Proceed anyway.'}`;
            if (confirmBtn) confirmBtn.disabled = false;
        }
    })
    .catch(() => {
        const status = document.getElementById('previewStatus');
        const confirmBtn = document.getElementById('confirmBtn');
        status.innerHTML = '⚠️ Could not calculate fees. Proceed anyway.';
        if (confirmBtn) confirmBtn.disabled = false;
    });
    
    return html;
}

async function executeSwap() {
    const btn = document.getElementById('confirmBtn');
    btn.disabled = true;
    btn.textContent = 'Processing...';
    
    const status = document.getElementById('previewStatus');
    if (status) status.innerHTML = '⏳ Processing transaction...';
    
    try {
        const resp = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pendingPayload)
        });
        const result = await resp.json();
        
        if (result.success || result.status === 'success' || result.status === 'pending_cashout') {
            document.getElementById('modalBody').innerHTML = `
                <div class="text-center" style="padding:1.5em 0;">
                    <div style="font-size:3em;color:var(--forest);">✅</div>
                    <h3 style="margin:0.5em 0 0.3em;">Success!</h3>
                    <p class="text-muted">Transaction completed successfully</p>
                    <p style="font-size:0.7rem;opacity:0.5;">Ref: ${result.reference || result.swap_reference || 'N/A'}</p>
                    ${result.atm_code ? `<div style="margin:0.5em 0;padding:0.5em;background:var(--panel);border:2px solid var(--ink);"><div style="font-size:0.6rem;opacity:0.5;">ATM CODE</div><div style="font-family:'Space Grotesk',monospace;font-size:1.6rem;letter-spacing:0.1em;">${result.atm_code}</div></div>` : ''}
                    <button class="btn btn-primary btn-block mt-16" onclick="closeModal()">Done</button>
                </div>
            `;
        } else {
            document.getElementById('modalBody').innerHTML = `
                <div class="text-center" style="padding:1.5em 0;">
                    <div style="font-size:3em;color:var(--red);">❌</div>
                    <h3 style="margin:0.5em 0 0.3em;">Failed</h3>
                    <p style="color:var(--red);">${result.message || result.error || 'Transaction failed.'}</p>
                    <button class="btn btn-secondary btn-block mt-16" onclick="currentStep=1;renderStep()">← Back</button>
                </div>
            `;
        }
    } catch (err) {
        document.getElementById('modalBody').innerHTML = `
            <div class="text-center" style="padding:1.5em 0;">
                <div style="font-size:3em;color:var(--red);">❌</div>
                <h3 style="margin:0.5em 0 0.3em;">Error</h3>
                <p style="color:var(--red);">${err.message || 'Network error'}</p>
                <button class="btn btn-secondary btn-block mt-16" onclick="currentStep=1;renderStep()">← Back</button>
            </div>
        `;
    }
}

function renderIdentities() {
    const body = document.getElementById('modalBody');
    let html = `
        <div style="margin-bottom:1em;">
            <p class="text-muted">These are the ways people can send money to you.</p>
        </div>
    `;
    
    if (userIdentifiers.length === 0) {
        html += `<div class="text-center text-muted" style="padding:2em 0;">No identifiers added.</div>`;
    } else {
        userIdentifiers.forEach(id => {
            html += `
                <div class="identifier-item">
                    <span class="icon">${id.icon}</span>
                    <div>
                        <div class="value">${id.value}</div>
                        <div class="type">${id.type}</div>
                    </div>
                    ${id.is_verified ? '<span class="badge badge-success">Verified</span>' : ''}
                </div>
            `;
        });
    }
    
    html += `
        <div class="text-center text-muted" style="font-size:0.7rem;padding:1em 0;">
            To add more identifiers, contact support.
        </div>
        <button class="btn btn-secondary btn-block" onclick="closeModal()">Close</button>
    `;
    
    body.innerHTML = html;
}

// Close modal on escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>
