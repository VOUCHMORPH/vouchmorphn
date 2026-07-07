<?php
// public/user/dashboard.php - REDESIGNED v3
// Philosophy: ask WHO and HOW MUCH. Resolve asset type/delivery mode
// silently whenever there's only one real answer for that institution;
// only surface a choice when the institution genuinely offers more than
// one option. Fluid scaling from phone to large-screen/TV via clamp().

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Config/AssetTypeRegistry.php';

use Application\Utils\SessionManager;
use Core\Config\AssetTypeRegistry;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user = SessionManager::getUser();
$userId = $user['user_id'] ?? null;

$userIdentifiers = [
    'phone' => $user['phone'] ?? null,
    'phone2' => $user['phone2'] ?? null,
    'phone3' => $user['phone3'] ?? null,
    'email' => $user['email'] ?? null,
    'national_id' => $user['national_id'] ?? null,
    'drivers_license' => $user['drivers_license'] ?? null,
    'passport' => $user['passport'] ?? null,
];

$primaryIdentifier = '';
foreach (['phone', 'email', 'national_id', 'drivers_license', 'passport', 'phone2', 'phone3'] as $type) {
    if (!empty($user[$type])) { $primaryIdentifier = $user[$type]; break; }
}

$typeIcons = ['phone' => '📱', 'phone2' => '📱', 'phone3' => '📱', 'email' => '✉️', 'national_id' => '🆔', 'drivers_license' => '🚗', 'passport' => '📖'];
$validIdentifiers = [];
foreach ($userIdentifiers as $type => $value) {
    if (!empty($value)) {
        $validIdentifiers[] = ['type' => $type, 'value' => $value, 'icon' => $typeIcons[$type] ?? '🔑'];
    }
}

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;

$config = LoadCountry::getConfig();
$countryName = $config['country'] ?? 'Botswana';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';
$currency = $config['currency'] ?? 'BWP';

$atmNotesPath = __DIR__ . '/../../src/Core/Config/Countries/' . $countryName . '/atm_notes.json';
$atmDenominations = [200, 100, 50, 20, 10];
if (file_exists($atmNotesPath)) {
    $atmData = json_decode(file_get_contents($atmNotesPath), true);
    $atmDenominations = $atmData[$currency] ?? $atmDenominations;
}

try {
    $swapDB = DBConnection::getConnection();
} catch (Exception $e) {
    die("Database error");
}

// ============================================================
// FIXED PARSER: indentation-depth aware
// ============================================================
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
            error_log("[DASHBOARD] WARNING: no asset_types parsed for {$code}");
            $p['asset_types'] = ['ACCOUNT'];
        }
    }
    return $participants;
}

$countryFolder = __DIR__ . '/../../src/Core/Config/Countries/' . $countryName;
$participants = parseParticipantsYaml($countryFolder . '/participants.yaml');

AssetTypeRegistry::initialize();
$allAssetTypes = AssetTypeRegistry::all();
$assetFieldsMap = [];
$assetUIMap = [];
$assetDeliveryModes = [];

foreach ($allAssetTypes as $code => $cfg) {
    $assetFieldsMap[$code] = $cfg['fields'] ?? [];
    $assetUIMap[$code] = $cfg['ui'] ?? [];
    $assetDeliveryModes[$code] = $cfg['delivery_modes'] ?? ['deposit', 'cashout'];
}

$cloudBalances = [];
$cloudTotal = 0;
try {
    $stmt = $swapDB->prepare("
        SELECT identity_type, identity_value, SUM(amount) as total_amount, COUNT(*) as count, MAX(created_at) as newest
        FROM identity_swap_holds WHERE user_id = ? AND status = 'pending'
        GROUP BY identity_type, identity_value ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    $cloudBalances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cloudBalances as &$cb) {
        $cloudTotal += (float)$cb['total_amount'];
        $cb['expires_at'] = date('Y-m-d H:i:s', strtotime($cb['newest']) + 86400);
    }
} catch (Exception $e) { error_log("Cloud balance error: " . $e->getMessage()); }

$recentSwaps = [];
try {
    $stmt = $swapDB->prepare("
        SELECT swap_reference, amount, from_institution, to_institution, status, created_at, swap_type
        FROM swap_ledgers WHERE user_id = ? ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("Recent swaps error: " . $e->getMessage()); }

$apiUrl = '/api/v1/swap/execute.php';
$previewUrl = '/api/v1/swap/preview.php';
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: 'vouchmorph_live_1aB2cD3eF4gH5iJ6';

$participantOptions = [];
foreach ($participants as $code => $p) {
    $participantOptions[$code] = [
        'name' => $p['name'] ?? $code,
        'type' => $p['type'] ?? 'BANK',
        'asset_types' => $p['asset_types'] ?? ['ACCOUNT'],
    ];
}
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
    --ink: #121212; --paper: #F7F5F0; --panel: #FFFFFF;
    --cobalt: #2440FF; --amber: #FFB400; --forest: #14804A; --line: #D8D4CB;
    --fs-body: clamp(0.95rem, 0.85rem + 0.3vw, 1.25rem);
    --fs-label: clamp(0.7rem, 0.65rem + 0.15vw, 0.9rem);
    --fs-h1: clamp(1.3rem, 1rem + 1.2vw, 2.4rem);
    --fs-h2: clamp(1.1rem, 0.95rem + 0.6vw, 1.6rem);
    --fs-amount: clamp(2rem, 1.4rem + 2.4vw, 4.5rem);
    --fs-code: clamp(1.8rem, 1.3rem + 2vw, 3.2rem);
    --space-unit: clamp(0.9rem, 0.75rem + 0.5vw, 1.6rem);
    --tap-min: 48px;
    --clip: clamp(10px, 0.8vw, 20px);
}
* { margin: 0; padding: 0; box-sizing: border-box; }
html { font-size: 16px; }
body {
    font-family: 'Inter', sans-serif; background: var(--paper); color: var(--ink);
    min-height: 100vh; font-size: var(--fs-body); line-height: 1.4;
}
.font-display { font-family: 'Space Grotesk', sans-serif; }
button, input, select { font-family: inherit; font-size: inherit; }
button:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid var(--cobalt); outline-offset: 2px; }
.clip { clip-path: polygon(0 0, calc(100% - var(--clip)) 0, 100% var(--clip), 100% 100%, 0 100%); }

.shell { max-width: min(1400px, 92vw); margin: 0 auto; }
.topbar {
    background: var(--ink); color: var(--paper);
    padding: var(--space-unit) calc(var(--space-unit) * 1.2);
    display: flex; align-items: center; justify-content: space-between;
}
.topbar-inner { max-width: min(1400px, 92vw); margin: 0 auto; width: 100%; display: flex; align-items: center; justify-content: space-between; }
.logo { display: flex; align-items: center; gap: 0.6em; }
.logo-mark {
    width: clamp(28px, 2.2vw, 44px); height: clamp(28px, 2.2vw, 44px);
    display: flex; align-items: center; justify-content: center;
    background: var(--cobalt); font-weight: 700; font-size: clamp(13px, 1.2vw, 20px);
    clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
}
.logo-text { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: var(--fs-h2); letter-spacing: -0.02em; }
.user-area { display: flex; align-items: center; gap: 1.2em; font-size: var(--fs-label); }
.user-area .id { opacity: 0.75; }
.logout-btn { background: none; border: none; color: var(--paper); opacity: 0.5; cursor: pointer; text-decoration: none; }
.logout-btn:hover { opacity: 1; }

.home { padding: calc(var(--space-unit) * 1.5) 0 calc(var(--space-unit) * 3); }

.pay-hero {
    width: 100%; display: flex; align-items: center; justify-content: space-between;
    padding: calc(var(--space-unit) * 1.4) calc(var(--space-unit) * 1.6);
    background: var(--ink); color: var(--paper); border: none; cursor: pointer;
    margin-bottom: var(--space-unit); transition: transform 0.15s;
}
.pay-hero:hover { transform: translateY(-2px); }
.pay-hero .label { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: var(--fs-h1); text-align: left; }
.pay-hero .sub { font-size: var(--fs-label); opacity: 0.6; margin-top: 0.3em; text-align: left; }
.pay-hero .arrow { font-size: var(--fs-h1); color: var(--cobalt); }

.cloud-strip {
    display: none; width: 100%; align-items: center; justify-content: space-between;
    padding: var(--space-unit) calc(var(--space-unit) * 1.2);
    background: var(--amber); border: 2px solid var(--ink); cursor: pointer;
    margin-bottom: var(--space-unit); transition: transform 0.15s;
}
.cloud-strip.visible { display: flex; }
.cloud-strip:hover { transform: translateY(-2px); }
.cloud-strip .label { font-size: var(--fs-label); font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.65; }
.cloud-strip .amount { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: var(--fs-h1); }

.shortcut-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
    gap: calc(var(--space-unit) * 0.7); margin-bottom: calc(var(--space-unit) * 1.5);
}
.shortcut-tile {
    background: var(--panel); border: 2px solid var(--ink);
    padding: calc(var(--space-unit) * 1.1); text-align: left; cursor: pointer;
    transition: transform 0.15s; min-height: var(--tap-min);
}
.shortcut-tile:hover { transform: translateY(-3px); }
.shortcut-tile .icon { font-size: clamp(1.4rem, 1.1rem + 1vw, 2.4rem); display: block; margin-bottom: 0.4em; }
.shortcut-tile .label { font-family: 'Space Grotesk', sans-serif; font-weight: 600; font-size: var(--fs-h2); }
.shortcut-tile .desc { font-size: var(--fs-label); opacity: 0.5; margin-top: 0.3em; }

.section-title { font-size: var(--fs-label); font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.5; margin: calc(var(--space-unit) * 1.2) 0 0.6em; }

.activity-inline { display: none; }
.activity-link { display: flex; align-items: center; justify-content: space-between; padding: 0.9em 0; border-top: 2px solid var(--line); cursor: pointer; font-weight: 500; }
@media (min-width: 900px) {
    .activity-link { display: none; }
    .activity-inline { display: block; }
}
.history-item { display: flex; justify-content: space-between; padding: 0.9em 0; border-bottom: 1px solid var(--line); }
.history-item:last-child { border-bottom: none; }
.history-item .route { font-weight: 500; }
.history-item .amount { font-family: 'Space Grotesk', sans-serif; font-weight: 600; }
.history-item .status { font-size: var(--fs-label); }
.history-item .status.completed { color: var(--forest); }

.panel-overlay { display: none; position: fixed; inset: 0; background: var(--paper); z-index: 1000; overflow-y: auto; }
.panel-overlay.active { display: block; }
.panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: var(--space-unit) calc(var(--space-unit) * 1.2);
    border-bottom: 2px solid var(--ink); background: var(--paper);
    position: sticky; top: 0; z-index: 10;
}
.panel-header-inner { max-width: min(900px, 92vw); margin: 0 auto; width: 100%; display: flex; align-items: center; justify-content: space-between; }
.panel-header button { background: none; border: none; cursor: pointer; color: var(--ink); font-size: clamp(1.2rem, 1rem + 0.5vw, 1.8rem); padding: 0.3em; }
.panel-header .title { font-family: 'Space Grotesk', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; font-size: var(--fs-h2); }
.panel-body { max-width: min(900px, 92vw); margin: 0 auto; padding: calc(var(--space-unit) * 1.5) 0 calc(var(--space-unit) * 3); }

.step-dots { display: flex; gap: 0.5em; margin-bottom: var(--space-unit); }
.step-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--line); }
.step-dot.active { background: var(--cobalt); width: 24px; border-radius: 4px; }
.step-dot.done { background: var(--forest); }

.who-search {
    width: 100%; padding: 1em 1.1em; background: var(--panel); border: 2px solid var(--line);
    font-size: var(--fs-h2); margin-bottom: var(--space-unit); outline: none;
}
.who-search:focus { border-color: var(--cobalt); }
.who-list { display: flex; flex-direction: column; gap: 0.6em; }
.who-card {
    display: flex; align-items: center; justify-content: space-between;
    padding: calc(var(--space-unit) * 0.9) var(--space-unit);
    background: var(--panel); border: 2px solid var(--line); cursor: pointer; transition: all 0.15s;
    min-height: var(--tap-min);
}
.who-card:hover, .who-card.active { border-color: var(--ink); }
.who-card.active { background: rgba(36,64,255,0.05); border-color: var(--cobalt); }
.who-card .main { display: flex; align-items: center; gap: 0.8em; }
.who-card .badge { font-size: clamp(1.1rem, 0.9rem + 0.5vw, 1.6rem); }
.who-card .name { font-family: 'Space Grotesk', sans-serif; font-weight: 600; font-size: var(--fs-h2); }
.who-card .assets { font-size: var(--fs-label); opacity: 0.45; margin-top: 0.2em; }

.amount-stage { text-align: center; padding: calc(var(--space-unit) * 1.5) 0; }
.amount-currency { font-size: var(--fs-h2); opacity: 0.4; font-family: 'Space Grotesk', sans-serif; }
.amount-input {
    width: 100%; text-align: center; border: none; background: transparent;
    font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: var(--fs-amount);
    color: var(--ink); outline: none; padding: 0.2em 0;
}
.amount-input::placeholder { color: var(--line); }
.quick-amounts { display: flex; flex-wrap: wrap; gap: 0.5em; justify-content: center; margin-top: 0.5em; }
.quick-amount {
    padding: 0.5em 1.1em; background: var(--panel); border: 1px solid var(--line);
    font-size: var(--fs-label); cursor: pointer; transition: all 0.15s;
}
.quick-amount:hover { background: var(--ink); color: var(--paper); border-color: var(--ink); }

.asset-choice { display: flex; gap: 0.6em; justify-content: center; flex-wrap: wrap; margin-top: var(--space-unit); }
.asset-pill {
    padding: 0.6em 1.2em; background: var(--panel); border: 2px solid var(--line);
    cursor: pointer; font-weight: 600; transition: all 0.15s; font-size: var(--fs-label);
}
.asset-pill:hover { border-color: var(--ink); }
.asset-pill.active { background: var(--cobalt); border-color: var(--cobalt); color: var(--paper); }

.field { margin: 0 0 var(--space-unit); }
.field-label { font-size: var(--fs-label); font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.55; margin-bottom: 0.4em; }
.text-input, select.text-input {
    width: 100%; padding: 0.9em 1em; background: var(--panel); border: 2px solid var(--line);
    outline: none; transition: border-color 0.2s; font-size: var(--fs-body);
}
.text-input:focus { border-color: var(--cobalt); }
.info-note { padding: 0.9em 1.1em; background: rgba(36,64,255,0.06); border-left: 3px solid var(--cobalt); font-size: var(--fs-label); line-height: 1.5; margin: 0.6em 0 var(--space-unit); }

.btn-primary {
    width: 100%; padding: 1.1em; background: var(--ink); color: var(--paper); border: none;
    font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: var(--fs-h2);
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5em;
    min-height: var(--tap-min); transition: opacity 0.2s;
}
.btn-primary:hover { opacity: 0.85; }
.btn-primary:disabled { opacity: 0.35; cursor: not-allowed; }
.btn-secondary {
    width: 100%; padding: 1em; background: transparent; color: var(--ink); border: 2px solid var(--ink);
    font-weight: 600; cursor: pointer; min-height: var(--tap-min);
}

.confirm-box { padding: calc(var(--space-unit) * 1.2); background: var(--panel); border: 2px solid var(--ink); margin-bottom: var(--space-unit); }
.confirm-row { display: flex; justify-content: space-between; padding: 0.6em 0; }
.confirm-row .label { opacity: 0.5; font-size: var(--fs-label); }
.confirm-row .value { font-weight: 600; }
.confirm-row .value.highlight { color: var(--cobalt); font-family: 'Space Grotesk', sans-serif; font-size: var(--fs-h2); }
.confirm-divider { border-top: 2px solid var(--line); margin: 0.4em 0; }

.success-box { text-align: center; padding: calc(var(--space-unit) * 2) 0; }
.success-check {
    width: clamp(56px, 5vw, 96px); height: clamp(56px, 5vw, 96px); background: var(--forest);
    display: flex; align-items: center; justify-content: center; font-size: clamp(1.6rem, 2vw, 2.6rem);
    color: var(--paper); margin: 0 auto 0.7em;
}
.success-title { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: var(--fs-h1); margin-bottom: 0.3em; }
.success-ref { font-size: var(--fs-label); opacity: 0.5; margin-bottom: var(--space-unit); }
.code-box { padding: 1em 1.6em; background: var(--panel); border: 2px solid var(--ink); display: inline-block; margin: 0.6em auto; }
.code-label { font-size: var(--fs-label); text-transform: uppercase; opacity: 0.5; }
.code-value { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: var(--fs-code); letter-spacing: 0.1em; }

.source-entry { background: var(--panel); border: 2px solid var(--line); padding: calc(var(--space-unit) * 0.9); margin-bottom: 0.7em; }
.source-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.7em; }
.source-header .num { font-size: var(--fs-label); font-weight: 700; opacity: 0.5; }
.source-header button { background: none; border: none; opacity: 0.35; cursor: pointer; font-size: 1.1em; }
.add-source-btn { width: 100%; padding: 0.9em; background: transparent; border: 2px dashed var(--line); cursor: pointer; font-weight: 600; }
.add-source-btn:hover { border-color: var(--ink); }
.source-summary { padding: 0.8em 1em; background: rgba(36,64,255,0.05); border: 1px solid rgba(36,64,255,0.2); margin-top: 0.7em; }
.source-summary .total { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: var(--fs-h2); color: var(--cobalt); }

.identifier-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.8em; }
.identifier-card { background: var(--panel); border: 2px solid var(--line); padding: 1em; text-align: center; }
.identifier-card .icon { font-size: clamp(1.3rem, 1vw + 1rem, 2rem); display: block; margin-bottom: 0.3em; }

.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(18,18,18,0.85); z-index: 2000; align-items: center; justify-content: center; padding: var(--space-unit); }
.modal-overlay.show { display: flex; }
.modal-box { background: var(--paper); max-width: min(520px, 100%); width: 100%; max-height: 90vh; overflow-y: auto; padding: calc(var(--space-unit) * 1.3); }
.modal-box h2 { font-family: 'Space Grotesk', sans-serif; font-size: var(--fs-h1); margin-bottom: 0.6em; }
.modal-error { display: none; padding: 0.8em 1em; background: rgba(18,18,18,0.06); border-left: 3px solid var(--ink); margin-bottom: 0.7em; font-size: var(--fs-label); }
.modal-error.show { display: block; }
.modal-actions { display: flex; gap: 0.8em; margin-top: var(--space-unit); }
.modal-actions button { flex: 1; padding: 0.9em; border: none; cursor: pointer; font-weight: 600; }
.modal-actions .btn-cancel { background: transparent; border: 2px solid var(--ink); }
.modal-actions .btn-confirm { background: var(--ink); color: var(--paper); }
.modal-actions .btn-confirm:disabled { opacity: 0.4; cursor: not-allowed; }

.empty-state { text-align: center; padding: calc(var(--space-unit) * 3) 0; opacity: 0.5; }
.empty-state .icon { font-size: clamp(2rem, 3vw, 3.5rem); margin-bottom: 0.5em; }

@keyframes spin { to { transform: rotate(360deg); } }
.spinner { display: inline-block; width: 1.4em; height: 1.4em; border: 3px solid var(--line); border-top-color: var(--ink); border-radius: 50%; animation: spin 0.8s linear infinite; }

@media (min-width: 1400px) {
    .shortcut-grid { grid-template-columns: repeat(4, 1fr); }
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
    <div id="cloudStrip" class="cloud-strip clip <?= $cloudTotal > 0 ? 'visible' : '' ?>" onclick="openPanel('cloud')" tabindex="0">
        <div><div class="label">Waiting for you</div><div class="amount"><?= $currencySymbol ?> <?= number_format($cloudTotal, 2) ?></div></div>
        <span style="font-size:1.4em;">›</span>
    </div>

    <button class="pay-hero clip" onclick="openFlow('send')">
        <div><div class="label">PAY SOMEONE</div><div class="sub">Type who, then how much</div></div>
        <span class="arrow">→</span>
    </button>

    <div class="shortcut-grid">
        <div class="shortcut-tile clip" onclick="openFlow('cashout')" tabindex="0">
            <span class="icon">💵</span><div class="label">CASHOUT</div><div class="desc">Get cash, no deposit needed</div>
        </div>
        <div class="shortcut-tile clip" onclick="openFlow('identity')" tabindex="0">
            <span class="icon">🔐</span><div class="label">TO IDENTITY</div><div class="desc">They choose how to receive it</div>
        </div>
        <div class="shortcut-tile clip" onclick="openFlow('pool')" tabindex="0">
            <span class="icon">📦</span><div class="label">COMBINE SOURCES</div><div class="desc">Use several accounts at once</div>
        </div>
        <div class="shortcut-tile clip" onclick="openPanel('identifiers')" tabindex="0">
            <span class="icon">🔑</span><div class="label">YOUR IDENTIFIERS</div><div class="desc">What people can send to</div>
        </div>
    </div>

    <div class="activity-link" onclick="openPanel('history')" tabindex="0">
        <span>Recent activity</span><span style="opacity:0.4;font-size:1.2em;">›</span>
    </div>

    <div class="activity-inline">
        <div class="section-title">RECENT ACTIVITY</div>
        <?php if (empty($recentSwaps)): ?>
            <div style="opacity:0.4; padding: 0.6em 0;">No activity yet.</div>
        <?php else: foreach ($recentSwaps as $swap): ?>
            <div class="history-item">
                <div><div class="route"><?= htmlspecialchars($swap['from_institution'] ?? '?') ?> → <?= htmlspecialchars($swap['to_institution'] ?? '?') ?></div></div>
                <div style="text-align:right;">
                    <div class="amount"><?= $currencySymbol ?> <?= number_format($swap['amount'] ?? 0, 2) ?></div>
                    <div class="status completed"><?= htmlspecialchars($swap['status'] ?? 'Completed') ?></div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
</div>

<!-- FLOW PANEL -->
<div id="flowPanel" class="panel-overlay">
    <div class="panel-header"><div class="panel-header-inner">
        <button onclick="flowBack()" aria-label="Back">‹</button>
        <span class="title" id="flowTitle">PAY</span>
        <button onclick="closeFlow()" aria-label="Close">✕</button>
    </div></div>
    <div class="panel-body" id="flowBody"></div>
</div>

<!-- CLOUD / HISTORY / IDENTIFIERS PANELS -->
<div id="cloudPanel" class="panel-overlay">
    <div class="panel-header"><div class="panel-header-inner">
        <button onclick="closePanel('cloud')">‹</button><span class="title">WAITING FOR YOU</span><button onclick="closePanel('cloud')">✕</button>
    </div></div>
    <div class="panel-body">
        <?php if (empty($cloudBalances)): ?>
            <div class="empty-state"><div class="icon">☁️</div><div class="font-display" style="font-weight:600;font-size:var(--fs-h2);">Nothing waiting</div></div>
        <?php else: foreach ($cloudBalances as $cb): ?>
            <div class="history-item">
                <div><?= htmlspecialchars($cb['identity_type']) ?>: <?= htmlspecialchars($cb['identity_value']) ?></div>
                <div class="amount"><?= $currencySymbol ?> <?= number_format($cb['total_amount'], 2) ?></div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div id="historyPanel" class="panel-overlay">
    <div class="panel-header"><div class="panel-header-inner">
        <button onclick="closePanel('history')">‹</button><span class="title">RECENT ACTIVITY</span><button onclick="closePanel('history')">✕</button>
    </div></div>
    <div class="panel-body">
        <?php if (empty($recentSwaps)): ?>
            <div class="empty-state"><div class="icon">📭</div>No activity yet.</div>
        <?php else: foreach ($recentSwaps as $swap): ?>
            <div class="history-item">
                <div><?= htmlspecialchars($swap['from_institution'] ?? '?') ?> → <?= htmlspecialchars($swap['to_institution'] ?? '?') ?></div>
                <div class="amount"><?= $currencySymbol ?> <?= number_format($swap['amount'] ?? 0, 2) ?></div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div id="identifiersPanel" class="panel-overlay">
    <div class="panel-header"><div class="panel-header-inner">
        <button onclick="closePanel('identifiers')">‹</button><span class="title">YOUR IDENTIFIERS</span><button onclick="closePanel('identifiers')">✕</button>
    </div></div>
    <div class="panel-body">
        <?php if (empty($validIdentifiers)): ?>
            <div class="empty-state"><div class="icon">🔑</div>No identifiers added.</div>
        <?php else: ?>
            <div class="identifier-grid">
                <?php foreach ($validIdentifiers as $id): ?>
                    <div class="identifier-card"><span class="icon"><?= $id['icon'] ?></span><div style="font-weight:600;"><?= htmlspecialchars($id['value']) ?></div></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="confirmModal" class="modal-overlay">
    <div class="modal-box">
        <h2>CONFIRM</h2>
        <div id="modalDetails"><div style="text-align:center;padding:1.5em;"><span class="spinner"></span><br><br>Calculating fees...</div></div>
        <div id="modalError" class="modal-error"></div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeConfirm()">CANCEL</button>
            <button id="confirmBtn" class="btn-confirm">CONFIRM</button>
        </div>
    </div>
</div>

<script>
const participants = <?= json_encode($participantOptions) ?>;
const assetFields = <?= json_encode($assetFieldsMap) ?>;
const assetUI = <?= json_encode($assetUIMap) ?>;
const assetDeliveryModes = <?= json_encode($assetDeliveryModes) ?>;
const currencySymbol = '<?= $currencySymbol ?>';
const currency = '<?= $currency ?>';
const userIdentifiers = <?= $identifiersJson ?>;
const apiUrl = '<?= $apiUrl ?>';
const previewUrl = '<?= $previewUrl ?>';
const apiKey = '<?= $apiKey ?>';
const loggedPhone = '<?= htmlspecialchars($primaryIdentifier) ?>';

const badgeMap = { BANK: '🏦', MNO: '📱', ORCHESTRATOR: '⚙️' };
const assetLabel = { ACCOUNT: 'ACCOUNT', WALLET: 'WALLET', 'MNO-WALLET': 'MOBILE WALLET', 'BANK-WALLET': 'BANK WALLET', VOUCHER: 'VOUCHER', CARD: 'CARD', ATM: 'ATM' };

// ============================================================
// FILTERING FUNCTIONS - source vs destination aware
// ============================================================
function getDepositCapableAssets(instCode) {
    const raw = participants[instCode]?.asset_types || ['ACCOUNT'];
    return raw.filter(a => (assetDeliveryModes[a] || ['deposit']).includes('deposit'));
}
function getCashoutCapableAssets(instCode) {
    const raw = participants[instCode]?.asset_types || ['ACCOUNT'];
    return raw.filter(a => (assetDeliveryModes[a] || ['deposit']).includes('cashout'));
}
function getAllAssets(instCode) {
    return participants[instCode]?.asset_types || ['ACCOUNT'];
}

// ============================================================
// STATE
// ============================================================
let currentFlow = null;
let step = 0;
let selWho = null;
let selAsset = null;
let selFromInst = null;
let selFromAsset = null;
let sources = [];
let sourceCounter = 0;
let identityType = 'phone';
let pendingPayload = null, pendingResult = null, previewData = null;

function openPanel(t){ const m={cloud:'cloudPanel',history:'historyPanel',identifiers:'identifiersPanel'}; document.getElementById(m[t])?.classList.add('active'); }
function closePanel(t){ const m={cloud:'cloudPanel',history:'historyPanel',identifiers:'identifiersPanel'}; document.getElementById(m[t])?.classList.remove('active'); }

function openFlow(type) {
    currentFlow = type; step = 0; selWho = selAsset = selFromInst = selFromAsset = null;
    sources = []; sourceCounter = 0;
    document.getElementById('flowPanel').classList.add('active');
    render();
}
function closeFlow() { document.getElementById('flowPanel').classList.remove('active'); currentFlow = null; }
function flowBack() { if (step > 0) { step--; render(); } else closeFlow(); }

function titleFor(flow) {
    return { send: 'PAY SOMEONE', cashout: 'CASHOUT', identity: 'TO IDENTITY', pool: 'COMBINE SOURCES' }[flow] || 'PAY';
}

function render() {
    document.getElementById('flowTitle').textContent = titleFor(currentFlow);
    const body = document.getElementById('flowBody');
    if (currentFlow === 'pool') { body.innerHTML = renderPool(); return; }
    if (currentFlow === 'identity') { body.innerHTML = renderIdentity(); return; }
    if (step === 0) body.innerHTML = renderWho();
    else if (step === 1) body.innerHTML = renderAmount();
    else if (step === 2) body.innerHTML = renderConfirmStep();
    else body.innerHTML = renderDone();
}

function dots(total, current) {
    let h = '<div class="step-dots">';
    for (let i = 0; i < total; i++) h += `<div class="step-dot ${i===current?'active':(i<current?'done':'')}"></div>`;
    return h + '</div>';
}

// ============================================================
// STEP 0: WHO - filtered by delivery capability based on flow
// ============================================================
function renderWho() {
    const isCashout = currentFlow === 'cashout';
    const isPool = currentFlow === 'pool';
    const rows = Object.entries(participants).map(([code, p]) => {
        const badge = badgeMap[p.type] || '🏦';
        const assets = isCashout ? getCashoutCapableAssets(code) : getDepositCapableAssets(code);
        if (assets.length === 0) return ''; // hide if no compatible assets
        const assetDisplay = assets.map(a => assetLabel[a] || a).join(' · ');
        return `<div class="who-card" data-code="${code}" data-name="${p.name.toLowerCase()}" onclick="pickWho('${code}')" tabindex="0">
            <div class="main"><span class="badge">${badge}</span><div><div class="name">${p.name}</div><div class="assets">${assetDisplay}</div></div></div>
            <span style="opacity:0.3;font-size:1.2em;">›</span>
        </div>`;
    }).filter(Boolean).join('');
    const label = isCashout ? 'CASHOUT FROM' : (isPool ? 'PAY TO' : 'PAY TO');
    return `
        ${dots(3, 0)}
        <div style="font-weight:700;font-size:var(--fs-h2);margin-bottom:0.5em;">${label}</div>
        <input class="who-search" placeholder="Search..." oninput="filterWho(this.value)" autofocus>
        <div class="who-list" id="whoList">${rows}</div>
    `;
}
function filterWho(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#whoList .who-card').forEach(el => {
        el.style.display = el.dataset.name.includes(q) ? '' : 'none';
    });
}
function pickWho(code) {
    selWho = code;
    const isCashout = currentFlow === 'cashout';
    const assets = isCashout ? getCashoutCapableAssets(code) : getDepositCapableAssets(code);
    selAsset = assets.length === 1 ? assets[0] : null;
    step = 1;
    render();
}

// ============================================================
// STEP 1: AMOUNT
// ============================================================
function renderAmount() {
    const p = participants[selWho];
    const isCashout = currentFlow === 'cashout';
    const assets = isCashout ? getCashoutCapableAssets(selWho) : getDepositCapableAssets(selWho);
    const needsAssetChoice = assets.length > 1;
    const assetChoiceHtml = needsAssetChoice ? `
        <div style="font-weight:700;font-size:var(--fs-label);text-transform:uppercase;margin-top:var(--space-unit);opacity:0.5;">SELECT TYPE</div>
        <div class="asset-choice">
            ${assets.map(a => `<button class="asset-pill ${selAsset===a?'active':''}" onclick="chooseAsset('${a}')">${assetLabel[a]||a}</button>`).join('')}
        </div>` : '';

    const destFieldsHtml = isCashout ? `
        <div class="field"><div class="field-label">BENEFICIARY PHONE</div>
            <input class="text-input" id="beneficiaryPhone" placeholder="+267 7X XXX XXX" value="${loggedPhone}"></div>
        <div class="info-note">💳 They receive a withdrawal code via SMS. No destination account needed.</div>
    ` : `
        <div class="field"><div class="field-label">${selAsset === 'ACCOUNT' ? 'ACCOUNT NUMBER' : 'RECIPIENT IDENTIFIER'}</div>
            <input class="text-input" id="destInput" placeholder="Enter identifier"></div>
    `;

    return `
        ${dots(3, 1)}
        <div class="amount-stage">
            <div class="amount-currency">${currencySymbol}</div>
            <input type="number" class="amount-input" id="amountInput" placeholder="0" oninput="updateSummary()">
            <div class="quick-amounts">
                ${[50,100,200,500,1000].map(a=>`<span class="quick-amount" onclick="document.getElementById('amountInput').value=${a};updateSummary()">${a}</span>`).join('')}
            </div>
            ${assetChoiceHtml}
        </div>
        ${destFieldsHtml}
        <div class="field"><div class="field-label">SEND FROM</div>
            <select class="text-input" id="fromSelect" onchange="onFromChange()">
                <option value="">SELECT INSTITUTION</option>
                ${Object.entries(participants).map(([c,pp])=>`<option value="${c}">${pp.name}</option>`).join('')}
            </select>
        </div>
        <div id="fromAssetField"></div>
        <div class="field"><div class="field-label">YOUR IDENTIFIER</div>
            <select class="text-input" id="sourceIdentifier">
                <option value="">SELECT</option>
                ${userIdentifiers.map(id=>`<option value="${id.value}">${id.icon} ${id.value}</option>`).join('')}
            </select>
        </div>
        <div class="field"><div class="field-label">YOUR PIN</div>
            <input type="password" class="text-input" id="pinInput" placeholder="••••" autocomplete="new-password"></div>
        <button class="btn-primary" onclick="goConfirm()">REVIEW →</button>
    `;
}
function chooseAsset(a) {
    selAsset = a;
    document.querySelectorAll('.asset-pill').forEach(el => el.classList.toggle('active', el.textContent.trim() === (assetLabel[a]||a)));
}
function onFromChange() {
    selFromInst = document.getElementById('fromSelect').value;
    const assets = getAllAssets(selFromInst);
    selFromAsset = assets.length === 1 ? assets[0] : null;
    const container = document.getElementById('fromAssetField');
    if (assets.length > 1) {
        container.innerHTML = `<div class="field"><div class="field-label">YOUR ASSET TYPE</div>
            <div class="asset-choice" style="justify-content:flex-start;">
                ${assets.map(a=>`<button type="button" class="asset-pill" onclick="selFromAsset='${a}'; this.parentElement.querySelectorAll('.asset-pill').forEach(x=>x.classList.remove('active')); this.classList.add('active')">${assetLabel[a]||a}</button>`).join('')}
            </div></div>`;
    } else {
        container.innerHTML = '';
    }
}
function updateSummary() {}

// ============================================================
// IDENTITY FLOW
// ============================================================
function renderIdentity() {
    if (step === 0) {
        return `
            ${dots(3,0)}
            <div class="info-note">🔐 Funds are held against this identity. The recipient chooses cashout or deposit later — fees are set at that point, not now.</div>
            <div class="field"><div class="field-label">THEY IDENTIFY BY</div>
                <div class="asset-choice" style="justify-content:flex-start;">
                    <button class="asset-pill active" onclick="identityType='phone';this.parentElement.querySelectorAll('.asset-pill').forEach(x=>x.classList.remove('active'));this.classList.add('active')">PHONE</button>
                    <button class="asset-pill" onclick="identityType='national_id';this.parentElement.querySelectorAll('.asset-pill').forEach(x=>x.classList.remove('active'));this.classList.add('active')">NATIONAL ID</button>
                    <button class="asset-pill" onclick="identityType='email';this.parentElement.querySelectorAll('.asset-pill').forEach(x=>x.classList.remove('active'));this.classList.add('active')">EMAIL</button>
                </div></div>
            <div class="field"><div class="field-label">VALUE</div><input class="text-input" id="identityValue" placeholder="Enter phone, ID or email"></div>
            <button class="btn-primary" onclick="step=1;render()">NEXT →</button>
        `;
    }
    if (step === 1) {
        return `
            ${dots(3,1)}
            <div class="amount-stage">
                <div class="amount-currency">${currencySymbol}</div>
                <input type="number" class="amount-input" id="amountInput" placeholder="0">
                <div class="quick-amounts">${[50,100,200,500,1000].map(a=>`<span class="quick-amount" onclick="document.getElementById('amountInput').value=${a}">${a}</span>`).join('')}</div>
            </div>
            <div class="field"><div class="field-label">SEND FROM</div>
                <select class="text-input" id="fromSelect" onchange="onFromChange()">
                    <option value="">SELECT INSTITUTION</option>
                    ${Object.entries(participants).map(([c,pp])=>`<option value="${c}">${pp.name}</option>`).join('')}
                </select></div>
            <div id="fromAssetField"></div>
            <div class="field"><div class="field-label">YOUR IDENTIFIER</div>
                <select class="text-input" id="sourceIdentifier"><option value="">SELECT</option>
                    ${userIdentifiers.map(id=>`<option value="${id.value}">${id.icon} ${id.value}</option>`).join('')}</select></div>
            <div class="field"><div class="field-label">YOUR PIN</div>
                <input type="password" class="text-input" id="pinInput" placeholder="••••" autocomplete="new-password"></div>
            <button class="btn-primary" onclick="goConfirm()">REVIEW →</button>
        `;
    }
    if (step === 2) return renderConfirmStep();
    return renderDone();
}

// ============================================================
// POOL FLOW
// ============================================================
function renderPool() {
    if (step === 0) {
        const rows = Object.entries(participants).map(([code, p]) => {
            const assets = getDepositCapableAssets(code);
            if (assets.length === 0) return '';
            const assetDisplay = assets.map(a => assetLabel[a] || a).join(' · ');
            return `<div class="who-card" data-code="${code}" data-name="${p.name.toLowerCase()}" onclick="pickWho('${code}')" tabindex="0">
                <div class="main"><span class="badge">${badgeMap[p.type]||'🏦'}</span><div><div class="name">${p.name}</div><div class="assets">${assetDisplay}</div></div></div>
                <span style="opacity:0.3;font-size:1.2em;">›</span>
            </div>`;
        }).filter(Boolean).join('');
        return `
            ${dots(3,0)}
            <div style="font-weight:700;font-size:var(--fs-h2);margin-bottom:0.5em;">PAY TO</div>
            <input class="who-search" placeholder="Search..." oninput="filterWho(this.value)" autofocus>
            <div class="who-list" id="whoList">${rows}</div>
        `;
    }
    if (step === 1) {
        const assets = getDepositCapableAssets(selWho);
        return `
            ${dots(3,1)}
            <div class="field"><div class="field-label">PAYING ${participants[selWho]?.name} — DESTINATION TYPE</div>
                <div class="asset-choice" style="justify-content:flex-start;">
                    ${assets.map(a=>`<button class="asset-pill ${selAsset===a?'active':''}" onclick="chooseAsset('${a}')">${assetLabel[a]||a}</button>`).join('')}
                </div></div>
            <div class="field"><div class="field-label">${selAsset==='ACCOUNT'?'ACCOUNT NUMBER':'IDENTIFIER'}</div>
                <input class="text-input" id="destInput" placeholder="Enter identifier"></div>
            <div style="font-weight:700;font-size:var(--fs-label);text-transform:uppercase;margin:var(--space-unit) 0 0.6em;opacity:0.5;">SOURCES (2+ REQUIRED)</div>
            <div id="sourceEntries"></div>
            <button class="add-source-btn" onclick="addSource()">+ ADD SOURCE</button>
            <div class="source-summary"><div>TOTAL: <span class="total" id="totalSourceAmount">${currencySymbol} 0.00</span></div></div>
            <div class="field" style="margin-top:1em;"><div class="field-label">YOUR PIN</div>
                <input type="password" class="text-input" id="pinInput" placeholder="••••" autocomplete="new-password"></div>
            <button class="btn-primary" onclick="goConfirm()">REVIEW →</button>
        `;
    }
    if (step === 2) return renderConfirmStep();
    return renderDone();
}
function addSource() {
    sourceCounter++;
    const id = 'src_' + sourceCounter;
    const entry = document.createElement('div');
    entry.className = 'source-entry'; entry.id = id;
    entry.innerHTML = `
        <div class="source-header"><span class="num">SOURCE ${sourceCounter}</span><button onclick="removeSource('${id}')">✕</button></div>
        <select class="text-input" id="${id}_inst" onchange="onSourceInstChange('${id}')" style="margin-bottom:0.6em;">
            <option value="">SELECT INSTITUTION</option>
            ${Object.entries(participants).map(([c,p])=>`<option value="${c}">${p.name}</option>`).join('')}
        </select>
        <div id="${id}_assetField"></div>
        <input type="number" class="text-input" id="${id}_amount" placeholder="AMOUNT (${currencySymbol})" style="margin:0.6em 0;" oninput="updatePoolTotal()">
        <select class="text-input" id="${id}_ident"><option value="">YOUR IDENTIFIER</option>
            ${userIdentifiers.map(u=>`<option value="${u.value}">${u.icon} ${u.value}</option>`).join('')}</select>
    `;
    document.getElementById('sourceEntries').appendChild(entry);
    sources.push({ id });
    updatePoolTotal();
}
function removeSource(id) {
    document.getElementById(id)?.remove();
    sources = sources.filter(s => s.id !== id);
    updatePoolTotal();
}
function onSourceInstChange(id) {
    const inst = document.getElementById(id + '_inst').value;
    const assets = getAllAssets(inst);
    const field = document.getElementById(id + '_assetField');
    const s = sources.find(x => x.id === id);
    if (s) { s.inst = inst; s.asset = assets.length === 1 ? assets[0] : null; }
    field.innerHTML = assets.length > 1
        ? `<div class="asset-choice" style="justify-content:flex-start;">${assets.map(a=>`<button type="button" class="asset-pill" onclick="setSourceAsset('${id}','${a}',this)">${assetLabel[a]||a}</button>`).join('')}</div>`
        : '';
}
function setSourceAsset(id, a, el) {
    const s = sources.find(x => x.id === id); if (s) s.asset = a;
    el.parentElement.querySelectorAll('.asset-pill').forEach(x=>x.classList.remove('active'));
    el.classList.add('active');
}
function updatePoolTotal() {
    let total = 0;
    sources.forEach(s => { total += parseFloat(document.getElementById(s.id + '_amount')?.value) || 0; });
    const el = document.getElementById('totalSourceAmount');
    if (el) el.textContent = currencySymbol + ' ' + total.toFixed(2);
}

// ============================================================
// CONFIRM / EXECUTE
// ============================================================
function buildPayload() {
    const payload = { reference: 'SWAP_' + Date.now(), idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).slice(2,8), currency };
    const amt = parseFloat(document.getElementById('amountInput')?.value) || 0;
    const pin = document.getElementById('pinInput')?.value || '';

    if (currentFlow === 'send' || currentFlow === 'cashout') {
        payload.swap_type = currentFlow === 'cashout' ? 'CASHOUT' : 'DEPOSIT';
        payload.from_institution = selFromInst;
        payload.asset_type = selFromAsset || 'ACCOUNT';
        payload.source_identifier = document.getElementById('sourceIdentifier')?.value || '';
        payload.amount = amt;
        payload.to_institution = selWho;
        payload.destination_asset_type = selAsset || 'ACCOUNT';
        if (currentFlow === 'cashout') {
            payload.beneficiary_phone = document.getElementById('beneficiaryPhone')?.value || '';
            payload.destination_identifier = payload.beneficiary_phone;
            payload.destination_identifier_type = 'phone';
        } else {
            const dest = document.getElementById('destInput')?.value?.trim() || '';
            payload.destination_identifier = dest;
            payload.destination_identifier_type = selAsset === 'ACCOUNT' ? 'account' : 'phone';
            if (selAsset === 'ACCOUNT') payload.destination_account = dest; else payload.destination_phone = dest;
        }
    } else if (currentFlow === 'identity') {
        payload.swap_type = 'IDENTITY';
        payload.from_institution = selFromInst;
        payload.asset_type = selFromAsset || 'ACCOUNT';
        payload.source_identifier = document.getElementById('sourceIdentifier')?.value || '';
        payload.amount = amt;
        payload.identity_type = identityType;
        payload.identity_value = document.getElementById('identityValue')?.value?.trim() || '';
    } else if (currentFlow === 'pool') {
        payload.swap_type = 'MULTI_SOURCE';
        payload.sources = sources.map(s => ({
            institution: s.inst, asset_type: s.asset || 'ACCOUNT',
            amount: parseFloat(document.getElementById(s.id + '_amount')?.value) || 0,
            identifier: document.getElementById(s.id + '_ident')?.value || '',
        })).filter(s => s.institution && s.amount > 0);
        payload.amount = payload.sources.reduce((sum, s) => sum + s.amount, 0);
        payload.to_institution = selWho;
        payload.destination_asset_type = selAsset || 'ACCOUNT';
        payload.delivery_method = 'DEPOSIT';
        payload.contribution_strategy = 'SMART';
        const dest = document.getElementById('destInput')?.value?.trim() || '';
        payload.destination_identifier = dest;
        payload.destination_identifier_type = selAsset === 'ACCOUNT' ? 'account' : 'phone';
        if (selAsset === 'ACCOUNT') payload.destination_account = dest; else payload.destination_phone = dest;
    }
    if (pin) { payload.pin = pin; payload.wallet_pin = pin; }
    return payload;
}

function goConfirm() {
    const pin = document.getElementById('pinInput')?.value;
    if (!pin || pin.length < 4) { alert('ENTER YOUR PIN'); return; }
    if (currentFlow === 'pool') {
        if (sources.length < 2) { alert('ADD AT LEAST 2 SOURCES'); return; }
        for (const s of sources) if (!s.inst || !s.asset) { alert('COMPLETE EVERY SOURCE'); return; }
    } else if (currentFlow !== 'identity') {
        if (!selFromInst) { alert('SELECT SOURCE INSTITUTION'); return; }
    }
    pendingPayload = buildPayload();
    step = 2; render();
    showConfirm(pendingPayload);
}

function renderConfirmStep() {
    return `${dots(3,2)}<div id="confirmInline"><div style="text-align:center;padding:2em;"><span class="spinner"></span></div></div>`;
}

async function showConfirm(payload) {
    try {
        const resp = await fetch(previewUrl, { method:'POST', headers:{'Content-Type':'application/json','X-API-Key':apiKey}, body: JSON.stringify(payload) });
        const result = await resp.json();
        if (!result.success) throw new Error(result.error || 'Fee calculation failed');
        previewData = result.preview;
        const p = previewData;
        const netAmount = p.net_amount_destination_currency || p.amount || 0;
        document.getElementById('confirmInline').innerHTML = `
            <div class="confirm-box">
                <div class="confirm-row"><span class="label">ROUTE</span><span>${p.source_institution||'?'} → ${p.destination_institution||'?'}</span></div>
                <div class="confirm-row"><span class="label">AMOUNT</span><span>${(p.amount_requested||p.amount||0).toFixed(2)} ${p.source_currency||currency}</span></div>
                <div class="confirm-row"><span class="label">FEE</span><span>${(p.total_fee||0).toFixed(2)} ${p.source_currency||currency}</span></div>
                <div class="confirm-divider"></div>
                <div class="confirm-row"><span class="label">RECIPIENT GETS</span><span class="value highlight">${netAmount.toFixed(2)} ${p.destination_currency||p.source_currency||currency}</span></div>
            </div>
            <button class="btn-primary" onclick="executeSwap()">CONFIRM & SEND</button>
        `;
    } catch (err) {
        document.getElementById('confirmInline').innerHTML = `<div class="info-note" style="border-color:#c62828;">❌ ${err.message}</div><button class="btn-secondary" onclick="flowBack()">BACK</button>`;
    }
}
function closeConfirm(){}

async function executeSwap() {
    try {
        const resp = await fetch(apiUrl, { method:'POST', headers:{'Content-Type':'application/json','X-API-Key':apiKey}, body: JSON.stringify(pendingPayload) });
        const result = await resp.json();
        const ok = result.success === true || result.status === 'pending_cashout' || result.atomic_commit?.status === 'committed';
        if (ok) {
            pendingResult = { ref: result.reference || result.swap_reference || 'N/A', atmCode: result.atm_code || result.atm_pin || null };
            step = 3; render();
        } else {
            document.getElementById('confirmInline').innerHTML += `<div class="info-note" style="border-color:#c62828;margin-top:1em;">❌ ${result.message||result.error||'Failed'}</div>`;
        }
    } catch (err) {
        document.getElementById('confirmInline').innerHTML += `<div class="info-note" style="border-color:#c62828;margin-top:1em;">❌ ${err.message}</div>`;
    }
}
function renderDone() {
    const r = pendingResult || {};
    return `
        <div class="success-box">
            <div class="success-check">✓</div>
            <div class="success-title">${currentFlow==='identity' ? 'HELD FOR RECIPIENT' : 'DONE'}</div>
            <div class="success-ref">REF ${r.ref}</div>
            ${r.atmCode ? `<div class="code-box"><div class="code-label">WITHDRAWAL CODE</div><div class="code-value">${r.atmCode}</div></div>` : ''}
            <button class="btn-primary" style="max-width:280px;margin:1.2em auto 0;" onclick="closeFlow()">BACK TO HOME</button>
        </div>
    `;
}
</script>
</body>
</html>
