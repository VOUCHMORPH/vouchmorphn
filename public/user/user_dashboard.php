<?php
// public/user/dashboard.php - SWAP SERVICE DASHBOARD
// This is a swap service dashboard, NOT a wallet dashboard.
// Balances are fetched from source institutions on demand.

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

$user = SessionManager::user();
$userId = $user['id'] ?? $user['user_id'] ?? null;

if (!$userId) {
    header("Location: login.php");
    exit;
}

// Get full user details
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

// Load config
$config = LoadCountry::getConfig();
$countryName = $config['country'] ?? 'Botswana';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';
$currency = $config['currency'] ?? 'BWP';

// Load participants from YAML
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

// Supported asset types
$supportedAssetTypes = ['ACCOUNT', 'WALLET'];

// Get user's hooked sources (saved sources)
$hookedSources = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM user_authorized_sources 
        WHERE user_id = ? AND status = 'active'
        ORDER BY institution, identifier
    ");
    $stmt->execute([$userId]);
    $hookedSources = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Failed to fetch hooked sources: " . $e->getMessage());
}

// Get user identifiers (for receiving money)
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
    $userIdentifiers = [];
}

// Also get from users table
$typeIcons = ['phone' => '📱', 'phone2' => '📱', 'phone3' => '📱', 'email' => '✉️', 'national_id' => '🆔', 'drivers_license' => '🚗', 'passport' => '📖'];
$validIdentifiers = [];

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

// Get recent swaps (swap ledger - NOT wallet transactions)
$recentSwaps = [];
try {
    $stmt = $db->prepare("
        SELECT swap_reference, amount, from_institution, to_institution, 
               status, created_at, swap_type, reference
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

// Get identity swaps (pending and completed)
$identitySwaps = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM identity_swap_holds 
        WHERE (source_identifier = ? OR identity_value = ?)
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$primaryIdentifier, $primaryIdentifier]);
    $identitySwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table may not exist yet
    $identitySwaps = [];
}

// Prepare participant options
$participantOptions = [];
foreach ($participants as $code => $p) {
    $participantOptions[$code] = [
        'name' => $p['name'] ?? $code,
        'type' => $p['type'] ?? 'BANK',
        'asset_types' => $p['asset_types'] ?? ['ACCOUNT'],
    ];
}

$identifiersJson = json_encode($validIdentifiers);
$hookedSourcesJson = json_encode($hookedSources);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VouchMorph Swap | <?= htmlspecialchars($countryName) ?></title>
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

/* Topbar */
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

/* Balance display - shown ONLY on request */
.balance-display {
    background: var(--ink);
    color: var(--paper);
    padding: calc(var(--space-unit) * 1.5) calc(var(--space-unit) * 1.6);
    margin-bottom: var(--space-unit);
    display: none;
}
.balance-display.visible { display: block; }
.balance-display .label {
    font-size: var(--fs-label);
    opacity: 0.5;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.balance-display .amount {
    font-family: 'Space Grotesk', sans-serif;
    font-size: var(--fs-h1);
    font-weight: 700;
    margin-top: 0.2em;
}
.balance-display .source-info {
    font-size: var(--fs-label);
    opacity: 0.5;
    margin-top: 0.3em;
}

/* Buttons */
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
    gap: 8px;
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
.btn-amber {
    background: var(--amber);
    color: var(--ink);
}
.btn-amber:hover { background: #e6a200; }
.btn:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-sm { padding: 0.6em 1em; min-height: 36px; font-size: 0.7rem; }
.btn-lg { padding: 1.2em 2em; font-size: var(--fs-body); }
.btn-block { width: 100%; }
.btn-icon { font-size: 1.2em; }

/* Grid */
.grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-unit); }
.grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-unit); }

/* Cards */
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

/* Main swap buttons - TWO BUTTONS: Swap From and Swap To */
.swap-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-unit);
    margin-bottom: calc(var(--space-unit) * 1.5);
}
.swap-btn {
    padding: calc(var(--space-unit) * 1.5);
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: var(--panel);
    border: 3px solid var(--line);
}
.swap-btn:hover { transform: translateY(-3px); border-color: var(--ink); }
.swap-btn .icon { font-size: clamp(2.4rem, 2rem + 0.8vw, 3.2rem); display: block; margin-bottom: 0.4em; }
.swap-btn .label {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: var(--fs-h2);
}
.swap-btn .sub-label {
    font-size: var(--fs-label);
    opacity: 0.5;
    margin-top: 0.2em;
}
.swap-btn.swap-from { border-color: var(--cobalt); }
.swap-btn.swap-from:hover { border-color: var(--cobalt); background: #f0f3ff; }
.swap-btn.swap-to { border-color: var(--forest); }
.swap-btn.swap-to:hover { border-color: var(--forest); background: #f0fff4; }

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
    background: var(--panel);
    border: 2px solid var(--line);
}
.action-btn:hover { transform: translateY(-3px); border-color: var(--ink); }
.action-btn .icon { font-size: clamp(1.8rem, 1.5rem + 0.5vw, 2.6rem); display: block; margin-bottom: 0.4em; }
.action-btn .label {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: var(--fs-label);
}

/* Activity */
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
.activity-item .status.pending_cashout { color: var(--amber); }
.activity-item .status.pending_identity { color: var(--cobalt); }

/* Identifier items */
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

/* Badges */
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
.badge-info { background: #e3f2fd; color: #0d47a1; }

/* Modal */
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
    max-width: 640px;
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

/* Form */
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
.form-control option { padding: 0.5em; }
select.form-control { appearance: auto; }

/* Step indicator */
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

/* Asset pills */
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

/* Source items for multi-source */
.source-item {
    display: flex;
    align-items: center;
    gap: 0.8em;
    padding: 0.7em 1em;
    background: var(--panel);
    border: 2px solid var(--line);
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 0.5em;
}
.source-item:hover { border-color: var(--ink); }
.source-item.selected { border-color: var(--cobalt); background: #f0f3ff; }
.source-item .source-icon { font-size: 1.4em; }
.source-item .source-info { flex: 1; }
.source-item .source-name { font-weight: 600; }
.source-item .source-detail { font-size: var(--fs-label); opacity: 0.5; }
.source-item .source-balance { font-family: 'Space Grotesk', sans-serif; font-weight: 600; }
.source-item .source-check { color: var(--cobalt); font-size: 1.2em; }
.source-item .source-amount {
    display: flex;
    gap: 0.5em;
    align-items: center;
}
.source-item .source-amount input {
    width: 100px;
    padding: 0.4em 0.8em;
    border: 2px solid var(--line);
    font-size: var(--fs-body);
}

/* Message */
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
.preview-row .value.success-text { color: var(--forest); }
.preview-row .value.fee-text { color: var(--amber); }

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
.w-full { width: 100%; }
.flex-wrap { flex-wrap: wrap; }
.items-center { align-items: center; }

/* Identity card */
.identity-card {
    padding: var(--space-unit);
    border: 2px solid var(--line);
    margin-bottom: var(--space-unit);
}
.identity-card.pending { border-color: var(--amber); }
.identity-card.completed { border-color: var(--forest); }
.identity-card .identity-detail {
    display: flex;
    gap: 0.5em;
    font-size: var(--fs-label);
}

@media (max-width: 768px) {
    .grid-3, .grid-2 { grid-template-columns: 1fr; }
    .swap-actions { grid-template-columns: 1fr; }
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
    <!-- Balance Display - ONLY shown when requested -->
    <div id="balanceDisplay" class="balance-display">
        <div class="label" id="balanceLabel">Available Balance</div>
        <div class="amount" id="balanceAmount"><?= $currencySymbol ?> 0.00</div>
        <div class="source-info" id="balanceSource">—</div>
    </div>

    <!-- TWO MAIN SWAP BUTTONS: Swap From → Swap To -->
    <div class="swap-actions">
        <div class="swap-btn swap-from" onclick="openModal('swap_from')">
            <span class="icon">📤</span>
            <div class="label">Swap From</div>
            <div class="sub-label">Select source &amp; amount</div>
        </div>
        <div class="swap-btn swap-to" onclick="openModal('swap_to')">
            <span class="icon">📥</span>
            <div class="label">Swap To</div>
            <div class="sub-label">Select destination</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="action-grid">
        <div class="action-btn" onclick="openModal('identity_swap')">
            <span class="icon">🔑</span>
            <div class="label">Identity Swap</div>
        </div>
        <div class="action-btn" onclick="openModal('multi_source')">
            <span class="icon">🔗</span>
            <div class="label">Multi-Source</div>
        </div>
        <div class="action-btn" onclick="openModal('hooked_sources')">
            <span class="icon">🔌</span>
            <div class="label">My Sources</div>
        </div>
        <div class="action-btn" onclick="openModal('identities')">
            <span class="icon">🆔</span>
            <div class="label">Identities</div>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid-3 mb-16">
        <div class="card">
            <div class="card-title">Connected Sources</div>
            <div class="card-value" style="font-size:var(--fs-h2);"><?= count($hookedSources) ?></div>
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

    <!-- Identity Swaps Section -->
    <?php if (!empty($identitySwaps)): ?>
    <div class="card mb-16">
        <div class="flex-between mb-16">
            <div class="card-title" style="margin-bottom:0;">Identity Swaps</div>
            <span class="text-muted" style="font-size:0.7rem;">Pending &amp; Completed</span>
        </div>
        <?php foreach (array_slice($identitySwaps, 0, 5) as $iswap): ?>
            <div class="identity-card <?= strtolower($iswap['status'] ?? 'pending') ?>">
                <div class="flex-between">
                    <div>
                        <div><strong><?= htmlspecialchars($iswap['identity_type'] ?? '') ?></strong>: <?= htmlspecialchars($iswap['identity_value'] ?? '') ?></div>
                        <div class="identity-detail">
                            <span><?= $currencySymbol ?> <?= number_format($iswap['amount'] ?? 0, 2) ?></span>
                            <span>·</span>
                            <span><?= htmlspecialchars($iswap['source_institution'] ?? '') ?></span>
                            <span>·</span>
                            <span><?= date('M d, H:i', strtotime($iswap['created_at'] ?? 'now')) ?></span>
                        </div>
                    </div>
                    <div>
                        <span class="badge <?= ($iswap['status'] ?? 'pending') === 'completed' ? 'badge-success' : (($iswap['status'] ?? 'pending') === 'pending' ? 'badge-pending' : 'badge-danger') ?>">
                            <?= htmlspecialchars($iswap['status'] ?? 'pending') ?>
                        </span>
                        <?php if (($iswap['status'] ?? '') === 'pending' && ($iswap['identity_value'] ?? '') === $primaryIdentifier): ?>
                            <button class="btn btn-sm btn-primary" onclick="claimIdentity('<?= $iswap['swap_reference'] ?>')">Claim</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent Activity (Swap Ledger) -->
    <div class="card mb-16">
        <div class="flex-between mb-16">
            <div class="card-title" style="margin-bottom:0;">Recent Swaps</div>
            <span class="text-muted" style="font-size:0.7rem;">Last 10</span>
        </div>
        <?php if (empty($recentSwaps)): ?>
            <div class="text-center text-muted" style="padding:1.5em 0;">No swaps yet.</div>
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
            <h2 id="modalTitle">Swap</h2>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<script>
// ============================================================================
// DATA FROM PHP
// ============================================================================
const participants = <?= json_encode($participantOptions) ?>;
const hookedSources = <?= $hookedSourcesJson ?>;
const userIdentifiers = <?= $identifiersJson ?>;
const supportedAssetTypes = <?= json_encode($supportedAssetTypes) ?>;
const currencySymbol = '<?= $currencySymbol ?>';
const currency = '<?= $currency ?>';
const apiUrl = '/api/v1/swap/execute.php';
const previewUrl = '/api/v1/swap/preview.php';
const balanceUrl = '/api/v1/source/balance.php';

const assetLabel = { ACCOUNT: 'Account', WALLET: 'Wallet' };
const typeIcons = { ACCOUNT: '🏦', WALLET: '📱' };

let currentMode = null;
let currentStep = 0;
let selSource = null;
let selDestination = null;
let selSourceAsset = null;
let selDestAsset = null;
let selectedMultiSources = [];
let pendingPayload = null;
let identitySwapRef = null;

// ============================================================================
// MODAL CONTROL
// ============================================================================

function openModal(mode) {
    currentMode = mode;
    currentStep = 0;
    selSource = null;
    selDestination = null;
    selSourceAsset = null;
    selDestAsset = null;
    selectedMultiSources = [];
    pendingPayload = null;
    identitySwapRef = null;
    
    const titles = {
        'swap_from': '📤 Swap From',
        'swap_to': '📥 Swap To',
        'identity_swap': '🔑 Identity Swap',
        'multi_source': '🔗 Multi-Source Swap',
        'hooked_sources': '🔌 My Connected Sources',
        'identities': '🆔 Your Identities'
    };
    
    document.getElementById('modalTitle').textContent = titles[mode] || 'Swap';
    document.getElementById('modal').classList.add('active');
    
    switch(mode) {
        case 'hooked_sources': renderHookedSources(); break;
        case 'identities': renderIdentities(); break;
        case 'identity_swap': renderIdentitySwap(); break;
        case 'multi_source': renderMultiSource(); break;
        case 'swap_from': renderSwapFrom(); break;
        case 'swap_to': renderSwapTo(); break;
        default: renderSwapFrom();
    }
}

function closeModal() {
    document.getElementById('modal').classList.remove('active');
}

// ============================================================================
// SWAP FROM - SOURCE SELECTION
// ============================================================================

function renderSwapFrom() {
    const body = document.getElementById('modalBody');
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>
        <div class="step-label">
            <span class="active">1. Select Source</span>
            <span>2. Amount</span>
            <span>3. Confirm</span>
        </div>
        <p class="text-muted" style="margin-bottom:1em;">Choose where the money comes from.</p>
    `;
    
    // Source selection
    html += `<div class="form-group"><label>Source</label>`;
    html += `<select class="form-control" id="sourceSelect" onchange="updateSourceDetails(this.value)">`;
    html += `<option value="">Select source...</option>`;
    
    if (hookedSources.length > 0) {
        html += `<optgroup label="🔌 Your Connected Sources">`;
        hookedSources.forEach(s => {
            const label = `${s.institution} - ${s.identifier}`;
            html += `<option value="hooked_${s.source_reference}">${label}</option>`;
        });
        html += `</optgroup>`;
    }
    
    html += `<optgroup label="🏦 Institutions">`;
    Object.entries(participants).forEach(([code, p]) => {
        html += `<option value="inst_${code}">${p.name} (${code})</option>`;
    });
    html += `</optgroup>`;
    html += `</select></div>`;
    
    // Asset type
    html += `
        <div class="form-group" id="sourceAssetGroup" style="display:none;">
            <label>Asset Type</label>
            <div class="asset-pills" id="sourceAssetPills">
    `;
    supportedAssetTypes.forEach(a => {
        html += `<span class="asset-pill" onclick="selectSourceAsset('${a}')">${assetLabel[a] || a}</span>`;
    });
    html += `
            </div>
        </div>
    `;
    
    // Source identifier (for manual entry)
    html += `
        <div class="form-group" id="sourceIdGroup" style="display:none;">
            <label>Source Identifier</label>
            <input type="text" class="form-control" id="sourceIdentifier" placeholder="Phone, email, national ID, or account number">
        </div>
        <div id="sourceBalanceGroup" style="display:none;" class="mt-8">
            <button class="btn btn-secondary btn-sm" onclick="checkSourceBalance()">💰 Check Balance</button>
            <span id="sourceBalanceResult" style="margin-left:0.5em;font-weight:600;"></span>
        </div>
        <div id="step0Message" class="message"></div>
        <button class="btn btn-primary btn-block" onclick="goToSwapFromAmount()" disabled id="step0Next">
            Next →
        </button>
    `;
    
    body.innerHTML = html;
    
    setTimeout(() => {
        document.getElementById('sourceSelect')?.addEventListener('change', checkSwapFromStep0);
        checkSwapFromStep0();
    }, 100);
}

function updateSourceDetails(value) {
    const assetGroup = document.getElementById('sourceAssetGroup');
    const idGroup = document.getElementById('sourceIdGroup');
    const balanceGroup = document.getElementById('sourceBalanceGroup');
    const result = document.getElementById('sourceBalanceResult');
    const pills = document.querySelectorAll('#sourceAssetPills .asset-pill');
    
    selSourceAsset = null;
    pills.forEach(el => el.classList.remove('active'));
    if (result) result.textContent = '';
    
    if (value.startsWith('hooked_')) {
        const ref = value.replace('hooked_', '');
        const source = hookedSources.find(s => s.source_reference === ref);
        if (source) {
            assetGroup.style.display = 'block';
            idGroup.style.display = 'none';
            balanceGroup.style.display = 'block';
            selSourceAsset = source.asset_type || 'ACCOUNT';
            pills.forEach(el => {
                const label = el.textContent.trim();
                const match = Object.entries(assetLabel).find(([k,v]) => v === label);
                if (match && match[0] === selSourceAsset) el.classList.add('active');
            });
        }
    } else if (value.startsWith('inst_')) {
        const code = value.replace('inst_', '');
        const p = participants[code];
        if (p) {
            assetGroup.style.display = 'block';
            idGroup.style.display = 'block';
            balanceGroup.style.display = 'block';
            selSourceAsset = (p.asset_types && p.asset_types.length > 0) ? p.asset_types[0] : 'ACCOUNT';
            pills.forEach(el => {
                const label = el.textContent.trim();
                const match = Object.entries(assetLabel).find(([k,v]) => v === label);
                if (match && match[0] === selSourceAsset) el.classList.add('active');
            });
        }
    } else {
        assetGroup.style.display = 'none';
        idGroup.style.display = 'none';
        balanceGroup.style.display = 'none';
    }
    checkSwapFromStep0();
}

function selectSourceAsset(asset) {
    selSourceAsset = asset;
    document.querySelectorAll('#sourceAssetPills .asset-pill').forEach(el => {
        const label = el.textContent.trim();
        const match = Object.entries(assetLabel).find(([k,v]) => v === label);
        el.classList.toggle('active', match && match[0] === asset);
    });
    checkSwapFromStep0();
}

function checkSwapFromStep0() {
    const source = document.getElementById('sourceSelect')?.value;
    const btn = document.getElementById('step0Next');
    const msg = document.getElementById('step0Message');
    
    if (!source) { if (btn) btn.disabled = true; return; }
    
    if (source.startsWith('hooked_')) {
        if (btn) btn.disabled = false;
        return;
    }
    
    if (source.startsWith('inst_')) {
        const hasAsset = selSourceAsset !== null;
        const identifier = document.getElementById('sourceIdentifier')?.value.trim();
        if (hasAsset && identifier) {
            if (btn) btn.disabled = false;
            if (msg) { msg.className = 'message'; msg.textContent = ''; }
        } else {
            if (btn) btn.disabled = true;
            if (msg) {
                msg.className = 'message show warning';
                msg.textContent = !hasAsset ? 'Please select an asset type.' : 'Please enter your source identifier.';
            }
        }
        return;
    }
    if (btn) btn.disabled = true;
}

function checkSourceBalance() {
    const source = document.getElementById('sourceSelect')?.value;
    const result = document.getElementById('sourceBalanceResult');
    if (!result) return;
    
    result.textContent = '⏳ Checking...';
    result.style.color = 'var(--ink)';
    
    let payload = {};
    
    if (source.startsWith('hooked_')) {
        const ref = source.replace('hooked_', '');
        const hooked = hookedSources.find(s => s.source_reference === ref);
        if (hooked) {
            payload = {
                source_reference: ref,
                institution: hooked.institution,
                identifier: hooked.identifier,
                asset_type: selSourceAsset || hooked.asset_type || 'ACCOUNT'
            };
        }
    } else if (source.startsWith('inst_')) {
        const code = source.replace('inst_', '');
        const identifier = document.getElementById('sourceIdentifier')?.value.trim();
        if (!identifier) {
            result.textContent = '⚠️ Enter identifier first';
            result.style.color = 'var(--red)';
            return;
        }
        payload = {
            institution: code,
            identifier: identifier,
            asset_type: selSourceAsset || 'ACCOUNT'
        };
    } else {
        result.textContent = '⚠️ Select a source first';
        result.style.color = 'var(--red)';
        return;
    }
    
    fetch(balanceUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.balance !== undefined) {
            result.textContent = `${currencySymbol} ${Number(data.balance).toFixed(2)}`;
            result.style.color = 'var(--forest)';
        } else {
            result.textContent = `⚠️ ${data.message || 'Could not fetch balance'}`;
            result.style.color = 'var(--red)';
        }
    })
    .catch(err => {
        result.textContent = '⚠️ Error checking balance';
        result.style.color = 'var(--red)';
    });
}

function goToSwapFromAmount() {
    const source = document.getElementById('sourceSelect')?.value;
    if (!source) return;
    
    let sourceData = {};
    
    if (source.startsWith('hooked_')) {
        const ref = source.replace('hooked_', '');
        const hooked = hookedSources.find(s => s.source_reference === ref);
        if (hooked) {
            sourceData = {
                type: 'hooked',
                source_reference: ref,
                institution: hooked.institution,
                identifier: hooked.identifier,
                asset_type: selSourceAsset || hooked.asset_type || 'ACCOUNT',
                access_token: hooked.access_token
            };
        }
    } else if (source.startsWith('inst_')) {
        const code = source.replace('inst_', '');
        const identifier = document.getElementById('sourceIdentifier')?.value.trim();
        if (!identifier) return;
        sourceData = {
            type: 'manual',
            institution: code,
            identifier: identifier,
            asset_type: selSourceAsset || 'ACCOUNT'
        };
    }
    
    if (!sourceData.institution) return;
    selSource = sourceData;
    currentStep = 1;
    renderSwapFromAmount();
}

function renderSwapFromAmount() {
    const body = document.getElementById('modalBody');
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot done"></div>
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
        </div>
        <div class="step-label">
            <span>1. Select Source</span>
            <span class="active">2. Amount</span>
            <span>3. Confirm</span>
        </div>
        <div class="flex-between text-muted" style="font-size:0.8rem;margin-bottom:1em;">
            <span>${selSource.institution}</span>
            <span>${selSource.identifier}</span>
            <span>${assetLabel[selSource.asset_type] || selSource.asset_type}</span>
        </div>
        <div class="form-group">
            <label>Amount to Send (${currencySymbol})</label>
            <input type="number" class="form-control" id="amountInput" placeholder="0.00" step="0.01" min="0.01">
            <div class="quick-amounts">
                ${[50, 100, 200, 500, 1000].map(a => 
                    `<span class="quick-amount" onclick="document.getElementById('amountInput').value=${a}">${a}</span>`
                ).join('')}
            </div>
        </div>
        <div class="form-group">
            <label>Your PIN</label>
            <input type="password" class="form-control" id="pinInput" placeholder="Enter your PIN" maxlength="6">
        </div>
        <div id="step1Message" class="message"></div>
        <div class="flex gap-16">
            <button class="btn btn-secondary" onclick="currentStep=0;renderSwapFrom()">← Back</button>
            <button class="btn btn-primary" onclick="goToSwapFromConfirm()">Preview →</button>
        </div>
    `;
    
    body.innerHTML = html;
}

function goToSwapFromConfirm() {
    const amount = parseFloat(document.getElementById('amountInput')?.value) || 0;
    const pin = document.getElementById('pinInput')?.value || '';
    const msg = document.getElementById('step1Message');
    
    if (amount <= 0) {
        if (msg) { msg.className = 'message show error'; msg.textContent = 'Enter a valid amount.'; }
        return;
    }
    if (!pin || pin.length < 4) {
        if (msg) { msg.className = 'message show error'; msg.textContent = 'Enter your PIN.'; }
        return;
    }
    if (msg) { msg.className = 'message'; msg.textContent = ''; }
    
    // Build payload for SwapService
    pendingPayload = {
        swap_type: 'STANDARD',
        from_institution: selSource.institution,
        source_institution: selSource.institution,
        source_identifier: selSource.identifier,
        asset_type: selSource.asset_type || 'ACCOUNT',
        amount: amount,
        currency: currency,
        pin: pin,
        wallet_pin: pin,
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2,8)
    };
    
    if (selSource.type === 'hooked') {
        pendingPayload.source_reference = selSource.source_reference;
        pendingPayload._is_hooked = true;
        pendingPayload.access_token = selSource.access_token;
    }
    
    currentStep = 2;
    renderSwapFromConfirm();
}

function renderSwapFromConfirm() {
    const body = document.getElementById('modalBody');
    const amount = pendingPayload?.amount || 0;
    const from = pendingPayload?.from_institution || '?';
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot done"></div>
            <div class="step-dot done"></div>
            <div class="step-dot active"></div>
        </div>
        <div class="step-label">
            <span>1. Select Source</span>
            <span>2. Amount</span>
            <span class="active">3. Confirm</span>
        </div>
        <div id="previewContainer">
            <div class="preview-box">
                <div class="preview-row"><span class="label">From</span><span class="value">${from}</span></div>
                <div class="preview-row"><span class="label">Source</span><span class="value">${selSource?.identifier || '—'}</span></div>
                <div class="preview-row"><span class="label">Asset</span><span class="value">${assetLabel[selSource?.asset_type] || selSource?.asset_type || '—'}</span></div>
                <div class="preview-row"><span class="label">Amount</span><span class="value">${currencySymbol} ${amount.toFixed(2)}</span></div>
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
            <button class="btn btn-secondary" onclick="currentStep=1;renderSwapFromAmount()">← Back</button>
            <button class="btn btn-success" id="confirmBtn" onclick="executeSwap()" disabled>Confirm & Send</button>
        </div>
    `;
    
    body.innerHTML = html;
    
    // Get fee preview
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
}

// ============================================================================
// SWAP TO - DESTINATION SELECTION
// ============================================================================

function renderSwapTo() {
    const body = document.getElementById('modalBody');
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>
        <div class="step-label">
            <span class="active">1. Select Destination</span>
            <span>2. Amount</span>
            <span>3. Confirm</span>
        </div>
        <p class="text-muted" style="margin-bottom:1em;">Choose where the money goes.</p>
    `;
    
    // Destination selection
    html += `<div class="form-group"><label>Destination</label>`;
    html += `<select class="form-control" id="destSelect" onchange="updateDestDetails(this.value)">`;
    html += `<option value="">Select destination...</option>`;
    
    if (userIdentifiers.length > 0) {
        html += `<optgroup label="👤 Your Identifiers">`;
        userIdentifiers.forEach(id => {
            html += `<option value="id_${id.value}">${id.value} (${id.type})</option>`;
        });
        html += `</optgroup>`;
    }
    
    html += `<optgroup label="🏦 Institutions">`;
    Object.entries(participants).forEach(([code, p]) => {
        html += `<option value="inst_${code}">${p.name} (${code})</option>`;
    });
    html += `</optgroup>`;
    html += `</select></div>`;
    
    // Destination asset type
    html += `
        <div class="form-group" id="destAssetGroup" style="display:none;">
            <label>Destination Asset Type</label>
            <div class="asset-pills" id="destAssetPills">
    `;
    supportedAssetTypes.forEach(a => {
        html += `<span class="asset-pill" onclick="selectDestAsset('${a}')">${assetLabel[a] || a}</span>`;
    });
    html += `
            </div>
        </div>
    `;
    
    // Destination identifier
    html += `
        <div class="form-group" id="destIdGroup" style="display:none;">
            <label>Destination Identifier</label>
            <input type="text" class="form-control" id="destIdentifier" placeholder="Phone, email, national ID, or account number">
        </div>
        <div id="destBalanceGroup" style="display:none;" class="mt-8">
            <button class="btn btn-secondary btn-sm" onclick="checkDestBalance()">💰 Check Balance</button>
            <span id="destBalanceResult" style="margin-left:0.5em;font-weight:600;"></span>
        </div>
        <div id="step0Message" class="message"></div>
        <button class="btn btn-primary btn-block" onclick="goToSwapToAmount()" disabled id="step0Next">
            Next →
        </button>
    `;
    
    body.innerHTML = html;
    
    setTimeout(() => {
        document.getElementById('destSelect')?.addEventListener('change', checkSwapToStep0);
        checkSwapToStep0();
    }, 100);
}

function updateDestDetails(value) {
    const assetGroup = document.getElementById('destAssetGroup');
    const idGroup = document.getElementById('destIdGroup');
    const balanceGroup = document.getElementById('destBalanceGroup');
    const result = document.getElementById('destBalanceResult');
    const pills = document.querySelectorAll('#destAssetPills .asset-pill');
    
    selDestAsset = null;
    pills.forEach(el => el.classList.remove('active'));
    if (result) result.textContent = '';
    
    if (value.startsWith('id_')) {
        const identifier = value.replace('id_', '');
        const id = userIdentifiers.find(i => i.value === identifier);
        if (id) {
            assetGroup.style.display = 'block';
            idGroup.style.display = 'none';
            balanceGroup.style.display = 'none';
            selDestAsset = (id.type === 'phone' || id.type === 'email') ? 'WALLET' : 'ACCOUNT';
            pills.forEach(el => {
                const label = el.textContent.trim();
                const match = Object.entries(assetLabel).find(([k,v]) => v === label);
                if (match && match[0] === selDestAsset) el.classList.add('active');
            });
        }
    } else if (value.startsWith('inst_')) {
        const code = value.replace('inst_', '');
        const p = participants[code];
        if (p) {
            assetGroup.style.display = 'block';
            idGroup.style.display = 'block';
            balanceGroup.style.display = 'block';
            selDestAsset = (p.asset_types && p.asset_types.length > 0) ? p.asset_types[0] : 'ACCOUNT';
            pills.forEach(el => {
                const label = el.textContent.trim();
                const match = Object.entries(assetLabel).find(([k,v]) => v === label);
                if (match && match[0] === selDestAsset) el.classList.add('active');
            });
        }
    } else {
        assetGroup.style.display = 'none';
        idGroup.style.display = 'none';
        balanceGroup.style.display = 'none';
    }
    checkSwapToStep0();
}

function selectDestAsset(asset) {
    selDestAsset = asset;
    document.querySelectorAll('#destAssetPills .asset-pill').forEach(el => {
        const label = el.textContent.trim();
        const match = Object.entries(assetLabel).find(([k,v]) => v === label);
        el.classList.toggle('active', match && match[0] === asset);
    });
    checkSwapToStep0();
}

function checkSwapToStep0() {
    const dest = document.getElementById('destSelect')?.value;
    const btn = document.getElementById('step0Next');
    const msg = document.getElementById('step0Message');
    
    if (!dest) { if (btn) btn.disabled = true; return; }
    
    if (dest.startsWith('id_')) {
        if (btn) btn.disabled = false;
        return;
    }
    
    if (dest.startsWith('inst_')) {
        const hasAsset = selDestAsset !== null;
        const identifier = document.getElementById('destIdentifier')?.value.trim();
        if (hasAsset && identifier) {
            if (btn) btn.disabled = false;
            if (msg) { msg.className = 'message'; msg.textContent = ''; }
        } else {
            if (btn) btn.disabled = true;
            if (msg) {
                msg.className = 'message show warning';
                msg.textContent = !hasAsset ? 'Select an asset type.' : 'Enter the destination identifier.';
            }
        }
        return;
    }
    if (btn) btn.disabled = true;
}

function checkDestBalance() {
    const dest = document.getElementById('destSelect')?.value;
    const result = document.getElementById('destBalanceResult');
    if (!result) return;
    
    result.textContent = '⏳ Checking...';
    result.style.color = 'var(--ink)';
    
    let payload = {};
    
    if (dest.startsWith('inst_')) {
        const code = dest.replace('inst_', '');
        const identifier = document.getElementById('destIdentifier')?.value.trim();
        if (!identifier) {
            result.textContent = '⚠️ Enter identifier first';
            result.style.color = 'var(--red)';
            return;
        }
        payload = {
            institution: code,
            identifier: identifier,
            asset_type: selDestAsset || 'ACCOUNT'
        };
    } else {
        result.textContent = '⚠️ Select a destination';
        result.style.color = 'var(--red)';
        return;
    }
    
    fetch(balanceUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.balance !== undefined) {
            result.textContent = `${currencySymbol} ${Number(data.balance).toFixed(2)}`;
            result.style.color = 'var(--forest)';
        } else {
            result.textContent = `⚠️ ${data.message || 'Could not fetch balance'}`;
            result.style.color = 'var(--red)';
        }
    })
    .catch(err => {
        result.textContent = '⚠️ Error checking balance';
        result.style.color = 'var(--red)';
    });
}

function goToSwapToAmount() {
    const dest = document.getElementById('destSelect')?.value;
    if (!dest) return;
    
    let destData = {};
    
    if (dest.startsWith('id_')) {
        const identifier = dest.replace('id_', '');
        const id = userIdentifiers.find(i => i.value === identifier);
        if (id) {
            destData = {
                type: 'identity',
                identifier: identifier,
                identity_type: id.type,
                asset_type: selDestAsset || 'WALLET'
            };
        }
    } else if (dest.startsWith('inst_')) {
        const code = dest.replace('inst_', '');
        const identifier = document.getElementById('destIdentifier')?.value.trim();
        if (!identifier) return;
        destData = {
            type: 'institution',
            institution: code,
            identifier: identifier,
            asset_type: selDestAsset || 'ACCOUNT'
        };
    }
    
    if (!destData.identifier) return;
    selDestination = destData;
    currentStep = 1;
    renderSwapToAmount();
}

function renderSwapToAmount() {
    const body = document.getElementById('modalBody');
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot done"></div>
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
        </div>
        <div class="step-label">
            <span>1. Select Destination</span>
            <span class="active">2. Amount</span>
            <span>3. Confirm</span>
        </div>
        <div class="flex-between text-muted" style="font-size:0.8rem;margin-bottom:1em;">
            <span>${selDestination.institution || 'Identity'}</span>
            <span>${selDestination.identifier}</span>
            <span>${assetLabel[selDestination.asset_type] || selDestination.asset_type}</span>
        </div>
        <div class="form-group">
            <label>Amount to Send (${currencySymbol})</label>
            <input type="number" class="form-control" id="amountInput" placeholder="0.00" step="0.01" min="0.01">
            <div class="quick-amounts">
                ${[50, 100, 200, 500, 1000].map(a => 
                    `<span class="quick-amount" onclick="document.getElementById('amountInput').value=${a}">${a}</span>`
                ).join('')}
            </div>
        </div>
        <div class="form-group">
            <label>Your PIN</label>
            <input type="password" class="form-control" id="pinInput" placeholder="Enter your PIN" maxlength="6">
        </div>
        <div id="step1Message" class="message"></div>
        <div class="flex gap-16">
            <button class="btn btn-secondary" onclick="currentStep=0;renderSwapTo()">← Back</button>
            <button class="btn btn-primary" onclick="goToSwapToConfirm()">Preview →</button>
        </div>
    `;
    
    body.innerHTML = html;
}

function goToSwapToConfirm() {
    const amount = parseFloat(document.getElementById('amountInput')?.value) || 0;
    const pin = document.getElementById('pinInput')?.value || '';
    const msg = document.getElementById('step1Message');
    
    if (amount <= 0) {
        if (msg) { msg.className = 'message show error'; msg.textContent = 'Enter a valid amount.'; }
        return;
    }
    if (!pin || pin.length < 4) {
        if (msg) { msg.className = 'message show error'; msg.textContent = 'Enter your PIN.'; }
        return;
    }
    if (msg) { msg.className = 'message'; msg.textContent = ''; }
    
    // Use first participant as source if not specified
    const sourceInst = Object.keys(participants)[0] || 'ZURUBANK';
    
    pendingPayload = {
        swap_type: 'STANDARD',
        from_institution: sourceInst,
        source_institution: sourceInst,
        source_identifier: userIdentifiers[0]?.value || '',
        to_institution: selDestination.institution || 'ZURUBANK',
        destination_institution: selDestination.institution || 'ZURUBANK',
        destination_identifier: selDestination.identifier,
        destination_asset_type: selDestination.asset_type || 'ACCOUNT',
        asset_type: selDestination.asset_type || 'ACCOUNT',
        amount: amount,
        currency: currency,
        pin: pin,
        wallet_pin: pin,
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2,8)
    };
    
    currentStep = 2;
    renderSwapToConfirm();
}

function renderSwapToConfirm() {
    const body = document.getElementById('modalBody');
    const amount = pendingPayload?.amount || 0;
    const to = pendingPayload?.to_institution || '?';
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot done"></div>
            <div class="step-dot done"></div>
            <div class="step-dot active"></div>
        </div>
        <div class="step-label">
            <span>1. Select Destination</span>
            <span>2. Amount</span>
            <span class="active">3. Confirm</span>
        </div>
        <div id="previewContainer">
            <div class="preview-box">
                <div class="preview-row"><span class="label">To</span><span class="value">${to}</span></div>
                <div class="preview-row"><span class="label">Destination</span><span class="value">${selDestination?.identifier || '—'}</span></div>
                <div class="preview-row"><span class="label">Asset</span><span class="value">${assetLabel[selDestination?.asset_type] || selDestination?.asset_type || '—'}</span></div>
                <div class="preview-row"><span class="label">Amount</span><span class="value">${currencySymbol} ${amount.toFixed(2)}</span></div>
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
            <button class="btn btn-secondary" onclick="currentStep=1;renderSwapToAmount()">← Back</button>
            <button class="btn btn-success" id="confirmBtn" onclick="executeSwap()" disabled>Confirm & Send</button>
        </div>
    `;
    
    body.innerHTML = html;
    
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
}

// ============================================================================
// EXECUTE SWAP (shared)
// ============================================================================

async function executeSwap() {
    const btn = document.getElementById('confirmBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Processing...';
    
    const status = document.getElementById('previewStatus');
    if (status) status.innerHTML = '⏳ Processing transaction...';
    
    try {
        const resp = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pendingPayload)
        });
        const result = await resp.json();
        
        if (result.status === 'success' || result.status === 'pending_cashout' || result.success) {
            document.getElementById('modalBody').innerHTML = `
                <div class="text-center" style="padding:1.5em 0;">
                    <div style="font-size:3em;color:var(--forest);">✅</div>
                    <h3 style="margin:0.5em 0 0.3em;">Success!</h3>
                    <p class="text-muted">Swap completed successfully</p>
                    <p style="font-size:0.7rem;opacity:0.5;">Ref: ${result.reference || result.swap_reference || 'N/A'}</p>
                    ${result.atm_code ? `<div style="margin:0.5em 0;padding:0.5em;background:var(--panel);border:2px solid var(--ink);"><div style="font-size:0.6rem;opacity:0.5;">ATM CODE</div><div style="font-family:monospace;font-size:1.6rem;letter-spacing:0.1em;">${result.atm_code}</div></div>` : ''}
                    ${result.voucher_number ? `<div style="margin:0.5em 0;padding:0.5em;background:var(--panel);border:2px solid var(--ink);"><div style="font-size:0.6rem;opacity:0.5;">VOUCHER</div><div style="font-family:monospace;font-size:1.6rem;letter-spacing:0.1em;">${result.voucher_number}</div></div>` : ''}
                    <button class="btn btn-primary btn-block mt-16" onclick="closeModal();location.reload();">Done</button>
                </div>
            `;
        } else {
            document.getElementById('modalBody').innerHTML = `
                <div class="text-center" style="padding:1.5em 0;">
                    <div style="font-size:3em;color:var(--red);">❌</div>
                    <h3 style="margin:0.5em 0 0.3em;">Failed</h3>
                    <p style="color:var(--red);">${result.message || result.error || 'Swap failed.'}</p>
                    <button class="btn btn-secondary btn-block mt-16" onclick="currentStep=1;currentMode==='swap_from'?renderSwapFromAmount():renderSwapToAmount()">← Back</button>
                </div>
            `;
        }
    } catch (err) {
        document.getElementById('modalBody').innerHTML = `
            <div class="text-center" style="padding:1.5em 0;">
                <div style="font-size:3em;color:var(--red);">❌</div>
                <h3 style="margin:0.5em 0 0.3em;">Error</h3>
                <p style="color:var(--red);">${err.message || 'Network error'}</p>
                <button class="btn btn-secondary btn-block mt-16" onclick="closeModal()">Close</button>
            </div>
        `;
    }
}

// ============================================================================
// IDENTITY SWAP
// ============================================================================

function renderIdentitySwap() {
    const body = document.getElementById('modalBody');
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>
        <div class="step-label">
            <span class="active">1. Sender Info</span>
            <span>2. Recipient</span>
            <span>3. Confirm</span>
        </div>
        <p class="text-muted" style="margin-bottom:1em;">Send money to someone's identity. They'll claim it within 24 hours.</p>
    `;
    
    // Source selection
    html += `<div class="form-group"><label>Your Source</label>`;
    html += `<select class="form-control" id="identitySourceSelect">`;
    html += `<option value="">Select source...</option>`;
    
    if (hookedSources.length > 0) {
        hookedSources.forEach(s => {
            html += `<option value="hooked_${s.source_reference}">${s.institution} - ${s.identifier}</option>`;
        });
    }
    Object.entries(participants).forEach(([code, p]) => {
        html += `<option value="inst_${code}">${p.name} (${code})</option>`;
    });
    html += `</select></div>`;
    
    html += `
        <div class="form-group" id="identitySourceIdGroup" style="display:none;">
            <label>Your Source Identifier</label>
            <input type="text" class="form-control" id="identitySourceId" placeholder="Phone, email, or national ID">
        </div>
        <div class="form-group">
            <label>Identity Type</label>
            <select class="form-control" id="identityTypeSelect">
                <option value="national_id">National ID</option>
                <option value="phone">Phone</option>
                <option value="email">Email</option>
            </select>
        </div>
        <div class="form-group">
            <label>Recipient Identity Value</label>
            <input type="text" class="form-control" id="identityValue" placeholder="e.g. 123456789, 0712345678, email@example.com">
        </div>
        <div class="form-group">
            <label>Amount (${currencySymbol})</label>
            <input type="number" class="form-control" id="identityAmount" placeholder="0.00" step="0.01" min="0.01">
        </div>
        <div class="form-group">
            <label>Your PIN</label>
            <input type="password" class="form-control" id="identityPin" placeholder="Enter your PIN" maxlength="6">
        </div>
        <div id="identityMsg" class="message"></div>
        <button class="btn btn-primary btn-block" onclick="initiateIdentitySwap()">
            Send to Identity →
        </button>
    `;
    
    body.innerHTML = html;
    
    document.getElementById('identitySourceSelect')?.addEventListener('change', function() {
        document.getElementById('identitySourceIdGroup').style.display = 
            this.value && !this.value.startsWith('hooked_') ? 'block' : 'none';
    });
}

function initiateIdentitySwap() {
    const source = document.getElementById('identitySourceSelect')?.value;
    const identityType = document.getElementById('identityTypeSelect')?.value;
    const identityValue = document.getElementById('identityValue')?.value.trim();
    const amount = parseFloat(document.getElementById('identityAmount')?.value) || 0;
    const pin = document.getElementById('identityPin')?.value || '';
    const sourceId = document.getElementById('identitySourceId')?.value.trim();
    const msg = document.getElementById('identityMsg');
    
    if (!source) { if (msg) { msg.className = 'message show error'; msg.textContent = 'Select a source.'; } return; }
    if (!identityValue) { if (msg) { msg.className = 'message show error'; msg.textContent = 'Enter the recipient identity.'; } return; }
    if (amount <= 0) { if (msg) { msg.className = 'message show error'; msg.textContent = 'Enter a valid amount.'; } return; }
    if (!pin || pin.length < 4) { if (msg) { msg.className = 'message show error'; msg.textContent = 'Enter your PIN.'; } return; }
    if (msg) { msg.className = 'message'; msg.textContent = ''; }
    
    let sourceInstitution = '', sourceIdentifier = '', assetType = 'ACCOUNT', isHooked = false;
    
    if (source.startsWith('hooked_')) {
        const ref = source.replace('hooked_', '');
        const hooked = hookedSources.find(s => s.source_reference === ref);
        if (hooked) {
            sourceInstitution = hooked.institution;
            sourceIdentifier = hooked.identifier;
            assetType = hooked.asset_type || 'ACCOUNT';
            isHooked = true;
        }
    } else if (source.startsWith('inst_')) {
        sourceInstitution = source.replace('inst_', '');
        sourceIdentifier = sourceId || '';
        if (!sourceIdentifier) {
            if (msg) { msg.className = 'message show error'; msg.textContent = 'Enter your source identifier.'; }
            return;
        }
    }
    
    if (!sourceInstitution || !sourceIdentifier) {
        if (msg) { msg.className = 'message show error'; msg.textContent = 'Fill in all source details.'; }
        return;
    }
    
    const payload = {
        swap_type: 'IDENTITY',
        from_institution: sourceInstitution,
        source_institution: sourceInstitution,
        source_identifier: sourceIdentifier,
        asset_type: assetType,
        amount: amount,
        currency: currency,
        pin: pin,
        wallet_pin: pin,
        identity_type: identityType,
        identity_value: identityValue,
        reference: 'IDENTITY_' + Date.now(),
        idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2,8)
    };
    
    if (isHooked) {
        const ref = source.replace('hooked_', '');
        const hooked = hookedSources.find(s => s.source_reference === ref);
        if (hooked) {
            payload.source_reference = ref;
            payload._is_hooked = true;
            payload.access_token = hooked.access_token;
        }
    }
    
    const btn = document.querySelector('#modalBody .btn-primary');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Initiating...'; }
    if (msg) { msg.className = 'message show info'; msg.textContent = 'Initiating identity swap...'; }
    
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(result => {
        if (result.status === 'pending_identity_confirmation') {
            identitySwapRef = result.swap_reference;
            document.getElementById('modalBody').innerHTML = `
                <div class="text-center" style="padding:1.5em 0;">
                    <div style="font-size:3em;color:var(--amber);">⏳</div>
                    <h3 style="margin:0.5em 0 0.3em;">Identity Swap Initiated</h3>
                    <p class="text-muted">The recipient must claim this swap within 24 hours.</p>
                    <div class="preview-box" style="text-align:left;">
                        <div class="preview-row"><span class="label">Reference</span><span class="value">${result.swap_reference}</span></div>
                        <div class="preview-row"><span class="label">Recipient</span><span class="value">${identityType}: ${identityValue}</span></div>
                        <div class="preview-row"><span class="label">Amount</span><span class="value">${currencySymbol} ${amount.toFixed(2)}</span></div>
                        <div class="preview-row"><span class="label">Expires</span><span class="value">${result.expires_at || '24 hours'}</span></div>
                    </div>
                    <p style="font-size:0.8rem;opacity:0.5;margin-top:0.5em;">
                        Recipient can claim at: <strong>/claim.php?ref=${result.swap_reference}</strong>
                    </p>
                    <button class="btn btn-primary btn-block mt-16" onclick="closeModal();location.reload();">Done</button>
                </div>
            `;
        } else {
            document.getElementById('modalBody').innerHTML = `
                <div class="text-center" style="padding:1.5em 0;">
                    <div style="font-size:3em;color:var(--red);">❌</div>
                    <h3 style="margin:0.5em 0 0.3em;">Failed</h3>
                    <p style="color:var(--red);">${result.message || result.error || 'Failed to initiate identity swap.'}</p>
                    <button class="btn btn-secondary btn-block mt-16" onclick="renderIdentitySwap()">← Try Again</button>
                </div>
            `;
        }
    })
    .catch(err => {
        document.getElementById('modalBody').innerHTML = `
            <div class="text-center" style="padding:1.5em 0;">
                <div style="font-size:3em;color:var(--red);">❌</div>
                <h3 style="margin:0.5em 0 0.3em;">Error</h3>
                <p style="color:var(--red);">${err.message || 'Network error'}</p>
                <button class="btn btn-secondary btn-block mt-16" onclick="renderIdentitySwap()">← Try Again</button>
            </div>
        `;
    });
}

function claimIdentity(swapRef) {
    window.location.href = `claim.php?ref=${swapRef}`;
}

// ============================================================================
// MULTI-SOURCE SWAP
// ============================================================================

function renderMultiSource() {
    const body = document.getElementById('modalBody');
    
    let html = `
        <div class="step-indicator">
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>
        <div class="step-label">
            <span class="active">1. Select Sources</span>
            <span>2. Destination</span>
            <span>3. Confirm</span>
        </div>
        <p class="text-muted" style="margin-bottom:1em;">Combine money from multiple sources to send as one.</p>
    `;
    
    // Available sources
    html += `<div class="form-group"><label>Available Sources</label>`;
    
    if (hookedSources.length === 0) {
        html += `<div class="text-muted" style="padding:1em 0;">No hooked sources available. Connect a source first.</div>`;
    } else {
        hookedSources.forEach((s) => {
            const isSelected = selectedMultiSources.some(sl => sl.source_reference === s.source_reference);
            html += `
                <div class="source-item ${isSelected ? 'selected' : ''}" onclick="toggleMultiSource('${s.source_reference}')">
                    <span class="source-icon">${typeIcons[s.asset_type] || '🏦'}</span>
                    <div class="source-info">
                        <div class="source-name">${s.institution}</div>
                        <div class="source-detail">${s.identifier}</div>
                    </div>
                    <div class="source-amount">
                        <input type="number" class="form-control" id="ms_amount_${s.source_reference}" 
                               placeholder="Amount" step="0.01" min="0.01" 
                               ${!isSelected ? 'disabled' : ''}
                               onchange="updateMultiSourceTotal()">
                        <button class="btn btn-sm btn-secondary" onclick="checkMultiSourceBalance('${s.source_reference}')">💰</button>
                    </div>
                    <span class="source-check">${isSelected ? '✅' : '☐'}</span>
                </div>
            `;
        });
    }
    html += `</div>`;
    
    // Total
    html += `
        <div class="flex-between" style="padding:0.5em 0;border-top:2px solid var(--line);">
            <span class="text-muted">Total Amount</span>
            <span class="source-total" id="multiSourceTotal">${currencySymbol} 0.00</span>
        </div>
    `;
    
    // Destination
    html += `
        <div class="form-group">
            <label>Destination</label>
            <select class="form-control" id="multiDestSelect" onchange="checkMultiSourceReady()">
                <option value="">Select destination...</option>
    `;
    if (userIdentifiers.length > 0) {
        userIdentifiers.forEach(id => {
            html += `<option value="id_${id.value}">${id.value} (${id.type})</option>`;
        });
    }
    Object.entries(participants).forEach(([code, p]) => {
        html += `<option value="inst_${code}">${p.name} (${code})</option>`;
    });
    html += `</select></div>`;
    
    html += `
        <div class="form-group" id="multiDestIdGroup" style="display:none;">
            <label>Destination Identifier</label>
            <input type="text" class="form-control" id="multiDestId" placeholder="Account number, phone, or email">
        </div>
        <div class="form-group">
            <label>Destination Asset Type</label>
            <div class="asset-pills" id="multiDestAssetPills">
    `;
    supportedAssetTypes.forEach(a => {
        html += `<span class="asset-pill" onclick="selectMultiDestAsset('${a}')">${assetLabel[a] || a}</span>`;
    });
    html += `
            </div>
        </div>
        <div id="multiMsg" class="message"></div>
        <button class="btn btn-primary btn-block" onclick="executeMultiSourceSwap()" id="multiExecuteBtn" disabled>
            Execute Multi-Source Swap →
        </button>
    `;
    
    body.innerHTML = html;
    
    document.getElementById('multiDestSelect')?.addEventListener('change', function() {
        document.getElementById('multiDestIdGroup').style.display = 
            this.value && this.value.startsWith('inst_') ? 'block' : 'none';
        checkMultiSourceReady();
    });
    document.getElementById('multiDestId')?.addEventListener('input', checkMultiSourceReady);
    document.querySelectorAll('#multiDestAssetPills .asset-pill').forEach(el => {
        el.addEventListener('click', checkMultiSourceReady);
    });
}

let multiDestAsset = 'ACCOUNT';

function toggleMultiSource(ref) {
    const idx = selectedMultiSources.findIndex(s => s.source_reference === ref);
    if (idx >= 0) {
        selectedMultiSources.splice(idx, 1);
    } else {
        const source = hookedSources.find(s => s.source_reference === ref);
        if (source) {
            selectedMultiSources.push({
                source_reference: ref,
                institution: source.institution,
                identifier: source.identifier,
                asset_type: source.asset_type || 'ACCOUNT',
                amount: 0
            });
        }
    }
    
    document.querySelectorAll('.source-item').forEach(el => {
        const input = el.querySelector('input');
        const refAttr = input?.id?.replace('ms_amount_', '');
        if (refAttr) {
            const isSelected = selectedMultiSources.some(s => s.source_reference === refAttr);
            el.classList.toggle('selected', isSelected);
            input.disabled = !isSelected;
            if (!isSelected) input.value = '';
            el.querySelector('.source-check').textContent = isSelected ? '✅' : '☐';
        }
    });
    
    updateMultiSourceTotal();
    checkMultiSourceReady();
}

function updateMultiSourceTotal() {
    let total = 0;
    selectedMultiSources.forEach(s => {
        const input = document.getElementById(`ms_amount_${s.source_reference}`);
        const amount = parseFloat(input?.value) || 0;
        s.amount = amount;
        total += amount;
    });
    document.getElementById('multiSourceTotal').textContent = `${currencySymbol} ${total.toFixed(2)}`;
    checkMultiSourceReady();
}

function checkMultiSourceBalance(ref) {
    const source = hookedSources.find(s => s.source_reference === ref);
    if (!source) return;
    
    const input = document.getElementById(`ms_amount_${ref}`);
    const btn = input?.closest('.source-amount')?.querySelector('button');
    if (btn) btn.textContent = '⏳';
    
    fetch(balanceUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            source_reference: ref,
            institution: source.institution,
            identifier: source.identifier,
            asset_type: source.asset_type || 'ACCOUNT'
        })
    })
    .then(res => res.json())
    .then(data => {
        if (btn) {
            if (data.success && data.balance !== undefined) {
                btn.textContent = `${currencySymbol} ${Number(data.balance).toFixed(2)}`;
                btn.style.color = 'var(--forest)';
            } else {
                btn.textContent = '⚠️';
                btn.style.color = 'var(--red)';
            }
        }
    })
    .catch(() => {
        if (btn) { btn.textContent = '❌'; btn.style.color = 'var(--red)'; }
    });
}

function selectMultiDestAsset(asset) {
    multiDestAsset = asset;
    document.querySelectorAll('#multiDestAssetPills .asset-pill').forEach(el => {
        const label = el.textContent.trim();
        const match = Object.entries(assetLabel).find(([k,v]) => v === label);
        el.classList.toggle('active', match && match[0] === asset);
    });
    checkMultiSourceReady();
}

function checkMultiSourceReady() {
    const dest = document.getElementById('multiDestSelect')?.value;
    const btn = document.getElementById('multiExecuteBtn');
    const msg = document.getElementById('multiMsg');
    
    const hasSources = selectedMultiSources.length >= 2;
    let allHaveAmount = true, total = 0;
    selectedMultiSources.forEach(s => {
        const input = document.getElementById(`ms_amount_${s.source_reference}`);
        const amount = parseFloat(input?.value) || 0;
        if (amount <= 0) allHaveAmount = false;
        total += amount;
    });
    
    let destValid = false;
    if (dest) {
        if (dest.startsWith('id_')) destValid = true;
        else if (dest.startsWith('inst_')) {
            const id = document.getElementById('multiDestId')?.value.trim();
            destValid = !!id;
        }
    }
    
    const ready = hasSources && allHaveAmount && total > 0 && destValid && multiDestAsset;
    
    if (btn) btn.disabled = !ready;
    if (msg && !ready) {
        let reason = '';
        if (!hasSources) reason = 'Select at least 2 sources.';
        else if (!allHaveAmount) reason = 'Enter amounts for all selected sources.';
        else if (total <= 0) reason = 'Total amount must be > 0.';
        else if (!destValid) reason = 'Select a destination and fill in details.';
        if (reason) { msg.className = 'message show warning'; msg.textContent = reason; }
        else { msg.className = 'message'; msg.textContent = ''; }
    } else if (msg) { msg.className = 'message'; msg.textContent = ''; }
}

function executeMultiSourceSwap() {
    const dest = document.getElementById('multiDestSelect')?.value;
    const msg = document.getElementById('multiMsg');
    const btn = document.getElementById('multiExecuteBtn');
    
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Processing...'; }
    
    let destInstitution = '', destIdentifier = '', destType = multiDestAsset || 'ACCOUNT';
    
    if (dest.startsWith('id_')) {
        destIdentifier = dest.replace('id_', '');
        destInstitution = 'IDENTITY';
    } else if (dest.startsWith('inst_')) {
        destInstitution = dest.replace('inst_', '');
        destIdentifier = document.getElementById('multiDestId')?.value.trim() || '';
    }
    
    if (!destInstitution || !destIdentifier) {
        if (msg) { msg.className = 'message show error'; msg.textContent = 'Fill in destination details.'; }
        if (btn) { btn.disabled = false; btn.textContent = 'Execute Multi-Source Swap →'; }
        return;
    }
    
    const sources = selectedMultiSources.map(s => ({
        source_reference: s.source_reference,
        institution: s.institution,
        identifier: s.identifier,
        asset_type: s.asset_type || 'ACCOUNT',
        amount: s.amount
    }));
    
    const total = selectedMultiSources.reduce((sum, s) => sum + s.amount, 0);
    
    const payload = {
        swap_type: 'MULTI_SOURCE',
        sources: sources,
        to_institution: destInstitution,
        destination_institution: destInstitution,
        destination_identifier: destIdentifier,
        destination_asset_type: destType,
        asset_type: destType,
        amount: total,
        currency: currency,
        reference: 'MULTI_' + Date.now(),
        idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2,8)
    };
    
    if (msg) { msg.className = 'message show info'; msg.textContent = 'Processing multi-source swap...'; }
    
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(result => {
        if (result.status === 'success' || result.status === 'pending_cashout' || result.success) {
            document.getElementById('modalBody').innerHTML = `
                <div class="text-center" style="padding:1.5em 0;">
                    <div style="font-size:3em;color:var(--forest);">✅</div>
                    <h3 style="margin:0.5em 0 0.3em;">Multi-Source Swap Complete!</h3>
                    <p class="text-muted">Combined ${selectedMultiSources.length} sources successfully</p>
                    <div class="preview-box" style="text-align:left;">
                        <div class="preview-row"><span class="label">Reference</span><span class="value">${result.reference || result.swap_reference || 'N/A'}</span></div>
                        <div class="preview-row"><span class="label">Total</span><span class="value">${currencySymbol} ${total.toFixed(2)}</span></div>
                        <div class="preview-row"><span class="label">Destination</span><span class="value">${destInstitution}</span></div>
                        <div class="preview-row"><span class="label">Sources</span><span class="value">${selectedMultiSources.length}</span></div>
                    </div>
                    <button class="btn btn-primary btn-block mt-16" onclick="closeModal();location.reload();">Done</button>
                </div>
            `;
        } else {
            document.getElementById('modalBody').innerHTML = `
                <div class="text-center" style="padding:1.5em 0;">
                    <div style="font-size:3em;color:var(--red);">❌</div>
                    <h3 style="margin:0.5em 0 0.3em;">Failed</h3>
                    <p style="color:var(--red);">${result.message || result.error || 'Multi-source swap failed.'}</p>
                    <button class="btn btn-secondary btn-block mt-16" onclick="renderMultiSource()">← Try Again</button>
                </div>
            `;
        }
    })
    .catch(err => {
        document.getElementById('modalBody').innerHTML = `
            <div class="text-center" style="padding:1.5em 0;">
                <div style="font-size:3em;color:var(--red);">❌</div>
                <h3 style="margin:0.5em 0 0.3em;">Error</h3>
                <p style="color:var(--red);">${err.message || 'Network error'}</p>
                <button class="btn btn-secondary btn-block mt-16" onclick="renderMultiSource()">← Try Again</button>
            </div>
        `;
    });
}

// ============================================================================
// HOOKED SOURCES
// ============================================================================

function renderHookedSources() {
    const body = document.getElementById('modalBody');
    
    let html = `
        <div style="margin-bottom:1em;">
            <p class="text-muted">Your connected sources. These are saved accounts/wallets you can swap from.</p>
        </div>
    `;
    
    if (hookedSources.length === 0) {
        html += `
            <div class="text-center" style="padding:2em 0;">
                <div style="font-size:2.4em;margin-bottom:0.5em;">🔌</div>
                <p class="text-muted">No sources connected yet.</p>
                <p style="font-size:0.8rem;opacity:0.5;">Connect a source via your bank's OAuth flow.</p>
            </div>
        `;
    } else {
        hookedSources.forEach(s => {
            const isExpired = s.token_expires_at && new Date(s.token_expires_at) < new Date();
            html += `
                <div class="identifier-item">
                    <span class="icon">${typeIcons[s.asset_type] || '🏦'}</span>
                    <div>
                        <div class="value">${s.institution}</div>
                        <div class="type">${s.identifier} · ${assetLabel[s.asset_type] || s.asset_type}</div>
                    </div>
                    <span class="badge ${isExpired ? 'badge-danger' : 'badge-success'}">
                        ${isExpired ? '⚠️ Expired' : '✅ Active'}
                    </span>
                    <button class="btn btn-sm btn-danger" onclick="revokeSource('${s.source_reference}')">Revoke</button>
                </div>
            `;
        });
    }
    
    html += `
        <button class="btn btn-secondary btn-block mt-16" onclick="closeModal()">Close</button>
    `;
    
    body.innerHTML = html;
}

function revokeSource(ref) {
    if (!confirm('Revoke this source?')) return;
    
    fetch('/api/v1/source/revoke.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ source_reference: ref })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) renderHookedSources();
        else alert(data.message || 'Failed to revoke');
    })
    .catch(err => alert('Error: ' + err.message));
}

// ============================================================================
// IDENTITIES
// ============================================================================

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
                    <span class="icon">${typeIcons[id.identity_type] || '🔑'}</span>
                    <div>
                        <div class="value">${id.identity_value}</div>
                        <div class="type">${id.identity_type}</div>
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

// ============================================================================
// KEYBOARD SHORTCUTS
// ============================================================================

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>
