<?php
// public/user/dashboard.php - FIXED VOUCHER FIELDS

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

// Get ALL identifiers from session
$userIdentifiers = [];
$userIdentifiers['phone'] = $user['phone'] ?? null;
$userIdentifiers['phone2'] = $user['phone2'] ?? null;
$userIdentifiers['phone3'] = $user['phone3'] ?? null;
$userIdentifiers['email'] = $user['email'] ?? null;
$userIdentifiers['national_id'] = $user['national_id'] ?? null;
$userIdentifiers['drivers_license'] = $user['drivers_license'] ?? null;
$userIdentifiers['passport'] = $user['passport'] ?? null;

// Get primary identifier
$primaryIdentifier = '';
$primaryType = 'phone';
$displayIdentifier = '';

if (!empty($user['phone'])) {
    $primaryIdentifier = $user['phone'];
    $primaryType = 'phone';
    $displayIdentifier = $user['phone'];
} elseif (!empty($user['email'])) {
    $primaryIdentifier = $user['email'];
    $primaryType = 'email';
    $displayIdentifier = $user['email'];
} elseif (!empty($user['national_id'])) {
    $primaryIdentifier = $user['national_id'];
    $primaryType = 'national_id';
    $displayIdentifier = $user['national_id'];
} elseif (!empty($user['drivers_license'])) {
    $primaryIdentifier = $user['drivers_license'];
    $primaryType = 'drivers_license';
    $displayIdentifier = $user['drivers_license'];
} elseif (!empty($user['passport'])) {
    $primaryIdentifier = $user['passport'];
    $primaryType = 'passport';
    $displayIdentifier = $user['passport'];
} elseif (!empty($user['phone2'])) {
    $primaryIdentifier = $user['phone2'];
    $primaryType = 'phone';
    $displayIdentifier = $user['phone2'];
} elseif (!empty($user['phone3'])) {
    $primaryIdentifier = $user['phone3'];
    $primaryType = 'phone';
    $displayIdentifier = $user['phone3'];
}

// Build valid identifiers list
$validIdentifiers = [];
$typeIcons = [
    'phone' => '📱',
    'phone2' => '📱',
    'phone3' => '📱',
    'email' => '✉️',
    'national_id' => '🆔',
    'drivers_license' => '🚗',
    'passport' => '📖'
];

foreach ($userIdentifiers as $type => $value) {
    if (!empty($value)) {
        $validIdentifiers[] = [
            'type' => $type,
            'value' => $value,
            'display' => $type . ': ' . $value,
            'icon' => $typeIcons[$type] ?? '🔑'
        ];
    }
}

$loggedPhone = htmlspecialchars($user['phone'] ?? $displayIdentifier);

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;

$config = LoadCountry::getConfig();
$countryCode = $config['country_code'] ?? 'BW';
$countryName = $config['country'] ?? 'Botswana';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';
$currency = $config['currency'] ?? 'BWP';

// Load ATM notes
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

// Load participants
$countryFolder = __DIR__ . '/../../src/Core/Config/Countries/' . $countryName;
$participantsYamlPath = $countryFolder . '/participants.yaml';

function parseParticipantsYaml($path) {
    $participants = [];
    if (!file_exists($path)) return $participants;
    
    $content = file_get_contents($path);
    $lines = explode("\n", $content);
    $current = null;
    $inParticipants = false;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        if (preg_match('/^participants:$/', $line)) {
            $inParticipants = true;
            continue;
        }
        
        if ($inParticipants && preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
            $current = $matches[1];
            $participants[$current] = [
                'code' => $current,
                'name' => $current,
                'asset_types' => [],
                'delivery_modes' => ['CASHOUT', 'DEPOSIT'],
                'type' => 'BANK'
            ];
            continue;
        }
        
        if ($current && preg_match('/^    name: (.+)$/', $line, $matches)) {
            $participants[$current]['name'] = trim($matches[1], '"\'');
            continue;
        }
        
        if ($current && preg_match('/^    type: (.+)$/', $line, $matches)) {
            $participants[$current]['type'] = trim($matches[1], '"\'');
            continue;
        }
        
        if ($current && preg_match('/^    asset_types:$/', $line)) {
            $participants[$current]['asset_types'] = [];
            continue;
        }
        
        if ($current && isset($participants[$current]['asset_types']) && preg_match('/^      - (.+)$/', $line, $matches)) {
            $participants[$current]['asset_types'][] = trim($matches[1]);
            continue;
        }
        
        if ($current && preg_match('/^    delivery_modes:$/', $line)) {
            $participants[$current]['delivery_modes'] = [];
            continue;
        }
        
        if ($current && isset($participants[$current]['delivery_modes']) && preg_match('/^      - (.+)$/', $line, $matches)) {
            $participants[$current]['delivery_modes'][] = trim($matches[1]);
        }
    }
    
    foreach ($participants as $code => &$p) {
        if (empty($p['asset_types'])) {
            if ($code === 'ZURUBANK') {
                $p['asset_types'] = ['VOUCHER', 'ACCOUNT'];
            } elseif ($code === 'VOUCHMORPH') {
                $p['asset_types'] = ['VOUCHER', 'ACCOUNT'];
            } elseif ($code === 'SACCUSSALIS') {
                $p['asset_types'] = ['ACCOUNT'];
            } elseif ($code === 'CAZACOM') {
                $p['asset_types'] = ['MNO-WALLET'];
            } else {
                $p['asset_types'] = ['ACCOUNT'];
            }
        }
    }
    
    return $participants;
}

$participants = parseParticipantsYaml($participantsYamlPath);

// ============================================================
// LOAD ASSET TYPES FROM AssetTypeRegistry (assets.yaml)
// ============================================================
AssetTypeRegistry::initialize();
$allAssetTypes = AssetTypeRegistry::all();

$assetFieldsMap = [];
$assetUIMap = [];
$assetRulesMap = [];
$assetTypeNames = [];

foreach ($allAssetTypes as $code => $config) {
    $assetFieldsMap[$code] = $config['fields'] ?? [];
    $assetUIMap[$code] = $config['ui'] ?? [];
    $assetRulesMap[$code] = [
        'hold_required' => $config['hold_required'] ?? false,
        'hold_expiry_seconds' => $config['hold_expiry_seconds'] ?? 300,
        'reversal_window_seconds' => $config['reversal_window_seconds'] ?? 86400,
        'partial_debit_allowed' => $config['partial_debit_allowed'] ?? false,
        'delivery_modes' => $config['delivery_modes'] ?? ['deposit', 'cashout']
    ];
    $assetTypeNames[] = $code;
}

// Get recent swaps
$recentSwaps = [];
try {
    $stmt = $swapDB->prepare("
        SELECT swap_reference, amount, from_institution, to_institution, 
               status, created_at, fee_amount, swap_type
        FROM swap_ledgers 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching recent swaps: " . $e->getMessage());
}

$apiUrl = '/api/v1/swap/execute.php';
$previewUrl = '/api/v1/swap/preview.php';
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: 'vouchmorph_live_1aB2cD3eF4gH5iJ6';

$denominationsList = implode(', ', $atmDenominations);

$participantOptions = [];
foreach ($participants as $code => $p) {
    $participantOptions[$code] = [
        'name' => $p['name'] ?? $code,
        'asset_types' => $p['asset_types'] ?? ['ACCOUNT']
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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0e27;
            padding: 16px;
            color: #fff;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .logo h1 { font-size: 22px; background: linear-gradient(135deg, #fff, #00f0ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .logo p { font-size: 11px; color: #888; margin-top: 2px; }
        .badge { padding: 4px 12px; background: rgba(0,240,255,0.1); border: 1px solid #00f0ff; border-radius: 20px; font-size: 11px; }
        .user-info { text-align: right; }
        .user-phone { color: #00f0ff; font-weight: bold; }
        
        .card {
            background: #12162e;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .card h3 { font-size: 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        
        .swap-type-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }
        .swap-type-option {
            padding: 16px 12px;
            background: #1a1f3a;
            border: 2px solid #2a2f4a;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .swap-type-option:hover { border-color: #00f0ff; }
        .swap-type-option.active { border-color: #00f0ff; background: rgba(0,240,255,0.05); }
        .swap-type-option .icon { font-size: 28px; display: block; margin-bottom: 4px; }
        .swap-type-option .label { font-size: 13px; font-weight: 600; }
        .swap-type-option .desc { font-size: 10px; color: #888; margin-top: 4px; }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        .form-group { margin-bottom: 12px; }
        label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #a0a0b0;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        select, input {
            width: 100%;
            padding: 10px 12px;
            background: #1a1f3a;
            border: 1px solid #2a2f4a;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
        }
        select:focus, input:focus { outline: none; border-color: #00f0ff; }
        select option { background: #1a1f3a; padding: 6px; }
        select optgroup { background: #0a0e27; color: #00f0ff; font-weight: bold; font-size: 12px; }
        
        .help-text { font-size: 11px; color: #888; margin-top: 4px; }
        .quick-amounts {
            display: flex;
            gap: 6px;
            margin-top: 6px;
            flex-wrap: wrap;
        }
        .quick-amount {
            padding: 4px 12px;
            background: #1a1f3a;
            border-radius: 16px;
            cursor: pointer;
            font-size: 12px;
            border: 1px solid #2a2f4a;
            color: #a0a0b0;
        }
        .quick-amount:hover { background: #00f0ff; color: #0a0e27; }
        
        .info-box {
            background: rgba(0, 240, 255, 0.04);
            border-left: 3px solid #00f0ff;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 12px;
            margin: 8px 0;
            color: #a0a0b0;
        }
        .info-box strong { color: #fff; }
        
        .warning-box {
            background: rgba(255, 193, 7, 0.08);
            border-left: 3px solid #ffc107;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 12px;
            margin: 8px 0;
            display: none;
        }
        .warning-box.show { display: block; }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #00f0ff, #b000ff);
            color: #0a0e27;
            width: 100%;
            padding: 14px;
        }
        .btn-primary:hover { transform: translateY(-1px); filter: brightness(1.05); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-danger { background: #ff6b6b; color: #fff; padding: 4px 12px; font-size: 12px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-danger:hover { background: #ff4444; }
        .btn-add {
            background: transparent;
            border: 2px dashed #2a2f4a;
            color: #888;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
            font-size: 13px;
            margin-top: 8px;
        }
        .btn-add:hover { border-color: #00f0ff; color: #00f0ff; background: rgba(0,240,255,0.05); }
        
        .source-entry {
            background: #0a0e27;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 10px;
            border: 1px solid #2a2f4a;
        }
        .source-entry .source-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .source-entry .source-number { color: #888; font-size: 12px; }
        .source-entry .source-fields {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
        .source-entry .source-fields .form-group { margin-bottom: 0; }
        .source-entry .source-fields select,
        .source-entry .source-fields input { padding: 8px 10px; font-size: 13px; }
        
        .multi-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: #0a0e27;
            border-radius: 8px;
            border: 1px solid #2a2f4a;
            margin-bottom: 12px;
            cursor: pointer;
        }
        .multi-toggle input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #00f0ff;
            cursor: pointer;
            flex-shrink: 0;
        }
        .multi-toggle .count { color: #00f0ff; font-weight: bold; font-size: 14px; margin-left: auto; }
        .multi-toggle .hint { color: #888; font-size: 12px; }
        
        .source-summary {
            background: rgba(0, 240, 255, 0.05);
            border-radius: 8px;
            padding: 12px;
            margin: 10px 0;
            border: 1px solid rgba(0, 240, 255, 0.15);
            font-size: 13px;
        }
        .source-summary .total { color: #00f0ff; font-weight: bold; font-size: 18px; }
        .source-summary .list { color: #a0a0b0; font-size: 12px; margin-top: 4px; }
        
        .result {
            padding: 16px;
            border-radius: 8px;
            margin-top: 16px;
            display: none;
        }
        .result.success { background: rgba(76, 175, 80, 0.15); border: 1px solid #4caf50; display: block; color: #4caf50; }
        .result.error { background: rgba(244, 67, 54, 0.15); border: 1px solid #f44336; display: block; color: #f44336; }
        .result .code { font-size: 28px; font-family: monospace; letter-spacing: 3px; color: #00f0ff; }
        
        .swap-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid #1a1f3a;
            font-size: 12px;
            flex-wrap: wrap;
            gap: 6px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .swap-item:hover { background: #1a1f3a; border-radius: 6px; }
        .swap-status.completed { color: #4caf50; }
        .swap-status.failed { color: #f44336; }
        .swap-status.pending { color: #ffc107; }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.85);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.show { display: flex; }
        .modal {
            background: #12162e;
            border-radius: 16px;
            padding: 24px;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .modal h2 { font-size: 20px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        .modal .details { background: #0a0e27; border-radius: 10px; padding: 14px; margin-bottom: 16px; }
        .modal .row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #1a1f3a;
        }
        .modal .row:last-child { border-bottom: none; }
        .modal .row .label { color: #888; font-size: 13px; }
        .modal .row .value { font-weight: bold; font-size: 14px; }
        .modal .row .value.highlight { color: #00f0ff; }
        .modal .row .value.negative { color: #ff6b6b; }
        .modal .total { font-size: 18px; padding-top: 10px; margin-top: 8px; border-top: 2px solid #00f0ff; color: #00f0ff; }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        .modal-actions button { flex: 1; padding: 12px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; border: none; }
        .btn-cancel { background: transparent; border: 1px solid #ff6b6b !important; color: #ff6b6b; }
        .btn-cancel:hover { background: rgba(255,107,107,0.1); }
        .btn-confirm { background: linear-gradient(135deg, #00f0ff, #b000ff); color: #0a0e27; }
        .btn-confirm:hover { transform: translateY(-1px); filter: brightness(1.05); }
        .btn-confirm:disabled { opacity: 0.5; cursor: not-allowed; }
        .modal-error { color: #ff6b6b; font-size: 13px; display: none; padding: 10px; background: rgba(255,107,107,0.1); border-radius: 6px; margin-bottom: 12px; }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.2);
            border-radius: 50%;
            border-top-color: #00f0ff;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .hidden { display: none !important; }
        
        .asset-fields-container {
            background: #0a0e27;
            border-radius: 8px;
            padding: 12px;
            margin-top: 8px;
        }
        
        .destination-type-selector {
            display: flex;
            gap: 10px;
            margin: 8px 0 12px 0;
        }
        .dest-option {
            padding: 8px 16px;
            background: #1a1f3a;
            border: 2px solid #2a2f4a;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            flex: 1;
            text-align: center;
            transition: all 0.2s;
        }
        .dest-option:hover { border-color: #00f0ff; }
        .dest-option.active { border-color: #00f0ff; background: rgba(0,240,255,0.05); }
        
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; gap: 8px; }
            .swap-type-selector { grid-template-columns: 1fr 1fr; }
            .source-entry .source-fields { grid-template-columns: 1fr; }
            .modal { padding: 16px; }
            .modal-actions { flex-direction: column; }
            .header { flex-direction: column; text-align: center; }
            .user-info { text-align: center; }
        }
        @media (max-width: 480px) {
            .swap-type-selector { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <!-- HEADER -->
    <div class="header">
        <div class="logo">
            <h1>💱 VouchMorph</h1>
            <p>🇧🇼 <?= htmlspecialchars($countryName) ?> · Send Money Anywhere</p>
        </div>
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <span class="badge"><?= htmlspecialchars($currency) ?></span>
            <div class="user-info">
                <div>👤 <span class="user-phone"><?= htmlspecialchars($primaryIdentifier) ?></span></div>
                <div style="font-size:10px; margin-top:2px;"><a href="logout.php" style="color:#888;">Logout</a></div>
            </div>
        </div>
    </div>

    <!-- SWAP TYPE SELECTOR -->
    <div class="card">
        <h3>🔄 What do you want to do?</h3>
        <div class="swap-type-selector" id="swapTypeSelector">
            <div class="swap-type-option active" data-value="STANDARD" onclick="selectSwapType('STANDARD')">
                <span class="icon">⬆️➡️</span>
                <div class="label">Standard Swap</div>
                <div class="desc">One source → One destination</div>
            </div>
            <div class="swap-type-option" data-value="IDENTITY" onclick="selectSwapType('IDENTITY')">
                <span class="icon">🔐</span>
                <div class="label">Swap to Identity</div>
                <div class="desc">Send to National ID / Phone / Email</div>
            </div>
            <div class="swap-type-option" data-value="MULTI_SOURCE" onclick="selectSwapType('MULTI_SOURCE')">
                <span class="icon">📦</span>
                <div class="label">Multi-Source</div>
                <div class="desc">Combine funds from multiple sources</div>
            </div>
        </div>
        
        <div id="swapTypeHelp" class="info-box" style="margin-top:8px;">
            💡 <strong>Standard Swap:</strong> Send money from one account to another.
            <span id="identityHelp" style="display:none;">🔐 <strong>Swap to Identity:</strong> Send money to someone's National ID, Phone, or Email. They claim it later by confirming their identity.</span>
            <span id="multiHelp" style="display:none;">📦 <strong>Multi-Source:</strong> Combine money from multiple accounts to send a larger amount.</span>
        </div>
    </div>

    <!-- MAIN FORM -->
    <div class="card" id="mainForm">
        <h3>📝 Fill in the details</h3>
        
        <!-- ============================================================
             STANDARD SWAP - One Source to One Destination
             ============================================================ -->
        <div id="standardSwapSection">
            <!-- SOURCE -->
            <div class="form-row">
                <div class="form-group">
                    <label>📤 Source Institution</label>
                    <select id="stdFromInstitution" onchange="updateStdAssetTypes()">
                        <option value="">-- Select --</option>
                        <?php foreach ($participants as $code => $p): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>🏷️ Asset Type</label>
                    <select id="stdAssetType" onchange="updateStdAssetFields()">
                        <option value="">-- Select --</option>
                        <?php foreach ($allAssetTypes as $code => $config): ?>
                            <option value="<?= htmlspecialchars($code) ?>">
                                <?= ($config['ui']['icon'] ?? '📦') . ' ' . ($config['ui']['display_name'] ?? $code) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div id="stdAssetFieldsContainer" class="asset-fields-container">
                <div class="info-box">Select an asset type above to see fields</div>
            </div>
            
            <div class="form-group">
                <label>🔑 Your Identifier</label>
                <select id="stdSourceIdentifier" style="width:100%; padding:10px 12px; background:#1a1f3a; border:1px solid #2a2f4a; border-radius:8px; color:#fff; font-size:14px;">
                    <option value="">-- Select your identifier --</option>
                    <?php foreach ($validIdentifiers as $id): ?>
                        <option value="<?= htmlspecialchars($id['value']) ?>" data-type="<?= htmlspecialchars($id['type']) ?>">
                            <?= $id['icon'] ?> <?= htmlspecialchars($id['value']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="help-text">Only identifiers you've added are shown.</div>
            </div>
            
            <!-- DESTINATION -->
            <div class="form-row">
                <div class="form-group">
                    <label>📥 Destination Institution</label>
                    <select id="stdToInstitution" onchange="validateStdCorridor()">
                        <option value="">-- Select --</option>
                        <?php foreach ($participants as $code => $p): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>📦 Delivery Method</label>
                    <select id="stdSwapType" onchange="updateStdDestination()">
                        <option value="DEPOSIT" selected>💳 Deposit</option>
                        <option value="CASHOUT">🏧 Cashout (ATM Code)</option>
                    </select>
                </div>
            </div>
            
            <!-- Destination Type Selector (Deposit only) -->
            <div id="stdDestTypeContainer" style="display:none;">
                <label style="font-size:11px;color:#888;text-transform:uppercase;">📥 Destination Type</label>
                <div class="destination-type-selector">
                    <div class="dest-option active" data-value="ACCOUNT" onclick="selectStdDestType('ACCOUNT')">🏦 Account</div>
                    <div class="dest-option" data-value="WALLET" onclick="selectStdDestType('WALLET')">📱 Wallet</div>
                </div>
            </div>
            
            <div id="stdDestinationFields">
                <!-- Dynamic based on delivery method -->
            </div>
            
            <!-- Amount -->
            <div class="form-group">
                <label>💰 Amount (<?= $currencySymbol ?>)</label>
                <input type="number" id="stdAmount" step="0.01" placeholder="0.00">
                <div class="quick-amounts">
                    <?php foreach ($atmDenominations as $denom): ?>
                        <span class="quick-amount" data-amount="<?= $denom ?>" onclick="document.getElementById('stdAmount').value=this.dataset.amount;updateSummary();"><?= $denom ?></span>
                    <?php endforeach; ?>
                    <span class="quick-amount" data-amount="500" onclick="document.getElementById('stdAmount').value=this.dataset.amount;updateSummary();">500</span>
                    <span class="quick-amount" data-amount="1000" onclick="document.getElementById('stdAmount').value=this.dataset.amount;updateSummary();">1000</span>
                </div>
            </div>
            
            <div id="stdCorridorWarning" class="warning-box">⚠️ Source and destination must be different.</div>
        </div>
        
        <!-- ============================================================
             IDENTITY SWAP - Send to an Identity
             ============================================================ -->
        <div id="identitySwapSection" style="display:none;">
            <!-- SOURCE -->
            <div class="form-row">
                <div class="form-group">
                    <label>📤 Source Institution</label>
                    <select id="idFromInstitution" onchange="updateIdAssetTypes()">
                        <option value="">-- Select --</option>
                        <?php foreach ($participants as $code => $p): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>🏷️ Asset Type</label>
                    <select id="idAssetType" onchange="updateIdAssetFields()">
                        <option value="">-- Select --</option>
                        <?php foreach ($allAssetTypes as $code => $config): ?>
                            <option value="<?= htmlspecialchars($code) ?>">
                                <?= ($config['ui']['icon'] ?? '📦') . ' ' . ($config['ui']['display_name'] ?? $code) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div id="idAssetFieldsContainer" class="asset-fields-container">
                <div class="info-box">Select an asset type above to see fields</div>
            </div>
            
            <div class="form-group">
                <label>🔑 Your Identifier</label>
                <select id="idSourceIdentifier" style="width:100%; padding:10px 12px; background:#1a1f3a; border:1px solid #2a2f4a; border-radius:8px; color:#fff; font-size:14px;">
                    <option value="">-- Select your identifier --</option>
                    <?php foreach ($validIdentifiers as $id): ?>
                        <option value="<?= htmlspecialchars($id['value']) ?>" data-type="<?= htmlspecialchars($id['type']) ?>">
                            <?= $id['icon'] ?> <?= htmlspecialchars($id['value']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="help-text">Only identifiers you've added are shown.</div>
            </div>
            
            <!-- IDENTITY (The Destination) -->
            <div class="info-box" style="margin-bottom:12px;">
                🔐 The money will be held for this identity. The recipient will claim it later.
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>🆔 Identity Type</label>
                    <select id="idIdentityType">
                        <option value="national_id">🆔 National ID</option>
                        <option value="phone">📱 Phone Number</option>
                        <option value="email">✉️ Email</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>🔑 Identity Value</label>
                    <input type="text" id="idIdentityValue" placeholder="e.g., 123456789 or +26770000000">
                </div>
            </div>
            
            <!-- Amount -->
            <div class="form-group">
                <label>💰 Amount (<?= $currencySymbol ?>)</label>
                <input type="number" id="idAmount" step="0.01" placeholder="0.00">
                <div class="quick-amounts">
                    <?php foreach ($atmDenominations as $denom): ?>
                        <span class="quick-amount" data-amount="<?= $denom ?>" onclick="document.getElementById('idAmount').value=this.dataset.amount;updateSummary();"><?= $denom ?></span>
                    <?php endforeach; ?>
                    <span class="quick-amount" data-amount="500" onclick="document.getElementById('idAmount').value=this.dataset.amount;updateSummary();">500</span>
                    <span class="quick-amount" data-amount="1000" onclick="document.getElementById('idAmount').value=this.dataset.amount;updateSummary();">1000</span>
                </div>
            </div>
            
            <!-- CONFIRM IDENTITY will be handled by the recipient -->
            <div class="info-box">
                📋 After this swap, the recipient will get a reference. They can claim it by confirming their identity.
            </div>
        </div>
        
        <!-- ============================================================
             MULTI-SOURCE SWAP
             ============================================================ -->
        <div id="multiSourceSection" style="display:none;">
            <!-- DESTINATION -->
            <div class="form-row">
                <div class="form-group">
                    <label>📥 Destination Institution</label>
                    <select id="msToInstitution" onchange="updateMsDestination()">
                        <option value="">-- Select --</option>
                        <?php foreach ($participants as $code => $p): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>📦 Delivery Method</label>
                    <select id="msSwapType" onchange="updateMsDestination()">
                        <option value="DEPOSIT" selected>💳 Deposit</option>
                        <option value="CASHOUT">🏧 Cashout (ATM Code)</option>
                    </select>
                </div>
            </div>
            
            <!-- Destination Type Selector (Deposit only) -->
            <div id="msDestTypeContainer" style="display:none;">
                <label style="font-size:11px;color:#888;text-transform:uppercase;">📥 Destination Type</label>
                <div class="destination-type-selector">
                    <div class="dest-option active" data-value="ACCOUNT" onclick="selectMsDestType('ACCOUNT')">🏦 Account</div>
                    <div class="dest-option" data-value="WALLET" onclick="selectMsDestType('WALLET')">📱 Wallet</div>
                </div>
            </div>
            
            <div id="msDestinationFields">
                <!-- Dynamic based on delivery method -->
            </div>
            
            <!-- Multi-Source Toggle -->
            <div class="multi-toggle" onclick="toggleMultiSource(event)">
                <input type="checkbox" id="multiSourceCheckbox" onchange="toggleMultiSource(event)">
                <span class="label">📦 Combine Multiple Sources</span>
                <span class="count" id="sourceCount">0 sources</span>
                <span class="hint">Add funds from multiple accounts</span>
            </div>
            
            <div id="sourcesContainer" style="display:none;">
                <div id="sourceEntries"></div>
                <button class="btn-add" onclick="addSource()">➕ Add Source</button>
                <div id="sourceSummary" class="source-summary" style="display:none;">
                    <div>💰 Total: <span class="total" id="totalSourceAmount"><?= $currencySymbol ?> 0.00</span></div>
                    <div class="list" id="sourceList">No sources configured</div>
                </div>
            </div>
        </div>
        
        <!-- PIN (Common) -->
        <div class="form-group" id="pinGroup">
            <label>🔑 Your PIN</label>
            <input type="password" id="userPin" placeholder="Enter your PIN" autocomplete="new-password">
        </div>
        
        <!-- Summary -->
        <div class="info-box" id="summary" style="margin-top:12px;">
            📋 Select a swap type and fill in the fields above
        </div>
        
        <button class="btn btn-primary" id="executeBtn">🚀 Execute Swap</button>
    </div>

    <!-- RESULT -->
    <div id="result" class="result"></div>

    <!-- RECENT SWAPS -->
    <div class="card">
        <h3>📋 Recent Activity</h3>
        <?php if (empty($recentSwaps)): ?>
            <div style="text-align:center; padding:20px; color:#888; font-size:13px;">No swaps yet. Try one above! 🚀</div>
        <?php else: ?>
            <?php foreach ($recentSwaps as $swap): ?>
                <?php $ref = $swap['swap_reference'] ?? null; if (!$ref) continue; ?>
                <div class="swap-item" onclick="window.location.href='history.php?id=<?= urlencode($ref) ?>'">
                    <div>
                        <strong><?= htmlspecialchars($swap['from_institution'] ?? '?') ?></strong>
                        <span style="color:#888;">→</span>
                        <strong><?= htmlspecialchars($swap['to_institution'] ?? '?') ?></strong>
                        <?php if (!empty($swap['swap_type'])): ?>
                            <span style="font-size:10px; color:#888; margin-left:6px;"><?= htmlspecialchars($swap['swap_type']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($swap['fee_amount']) && $swap['fee_amount'] > 0): ?>
                            <div class="fee-display" style="color:#ffc107;font-size:10px;">Fee: <?= number_format($swap['fee_amount'], 2) ?> <?= $currencySymbol ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:bold;"><?= number_format($swap['amount'] ?? 0, 2) ?> <?= $currencySymbol ?></div>
                        <div class="swap-status <?= strtolower($swap['status'] ?? 'completed') ?>"><?= $swap['status'] ?? 'Completed' ?></div>
                        <div style="font-size:10px; color:#888;"><?= date('M d, H:i', strtotime($swap['created_at'] ?? 'now')) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- CONFIRMATION MODAL -->
<div id="confirmModal" class="modal-overlay">
    <div class="modal">
        <h2>🔄 Confirm Swap</h2>
        <div id="modalDetails" class="details">
            <div style="text-align:center; padding:20px;">
                <div class="loading-spinner"></div>
                <br>Calculating fees...
            </div>
        </div>
        <div id="modalError" class="modal-error"></div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeConfirmation()">Cancel</button>
            <button id="confirmBtn" class="btn-confirm">✅ Confirm</button>
        </div>
    </div>
</div>

<script>
// ============================================================
// CONFIGURATION - DYNAMIC FROM PHP
// ============================================================
const participants = <?= json_encode($participantOptions) ?>;
const assetFields = <?= json_encode($assetFieldsMap) ?>;
const assetUI = <?= json_encode($assetUIMap) ?>;
const currencySymbol = '<?= $currencySymbol ?>';
const userIdentifiers = <?= $identifiersJson ?>;
const apiUrl = '<?= $apiUrl ?>';
const previewUrl = '<?= $previewUrl ?>';
const apiKey = '<?= $apiKey ?>';

// ============================================================
// STATE
// ============================================================
let currentSwapType = 'STANDARD';
let stdDestType = 'ACCOUNT';
let msDestType = 'ACCOUNT';
let sources = [];
let sourceCounter = 0;
let pendingPayload = null;
let previewData = null;

// ============================================================
// DYNAMIC ASSET FIELD FUNCTIONS - FROM assets.yaml
// ============================================================

function renderAssetFields(prefix, assetType, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        console.error('[renderAssetFields] Container not found:', containerId);
        return;
    }
    
    console.log('[renderAssetFields] Asset type:', assetType, 'Prefix:', prefix);
    console.log('[renderAssetFields] Available asset fields:', Object.keys(assetFields));
    container.innerHTML = '';
    
    if (!assetType) {
        container.innerHTML = '<div class="info-box">Please select an asset type</div>';
        return;
    }
    
    const fields = assetFields[assetType] || [];
    console.log('[renderAssetFields] Fields for', assetType, ':', fields);
    
    if (!fields || fields.length === 0) {
        container.innerHTML = '<div class="info-box">✅ No additional fields required for ' + assetType + '</div>';
        return;
    }
    
    let html = '<div class="form-row">';
    fields.forEach(f => {
        const isPin = f.type === 'password' || f.name.includes('pin') || f.vault_field === 'pin';
        const fieldId = prefix + '_' + f.name;
        const required = f.required ? ' required' : '';
        const pattern = f.pattern ? ` pattern="${f.pattern}"` : '';
        const minAttr = f.min !== undefined ? ` min="${f.min}"` : '';
        const maxAttr = f.max !== undefined ? ` max="${f.max}"` : '';
        const minLen = f.min_length ? ` minlength="${f.min_length}"` : '';
        const maxLen = f.max_length ? ` maxlength="${f.max_length}"` : '';
        const placeholder = f.placeholder || '';
        const helpText = f.help_text || '';
        const label = f.label || f.name;
        
        html += `
            <div class="form-group">
                <label>${label}</label>
                <input type="${isPin ? 'password' : (f.type || 'text')}" 
                       id="${fieldId}" 
                       placeholder="${placeholder}"
                       ${required}${pattern}${minAttr}${maxAttr}${minLen}${maxLen}
                       class="asset-field"
                       autocomplete="${isPin ? 'new-password' : 'on'}">
                ${helpText ? `<div style="font-size:10px;color:#888;margin-top:4px;">💡 ${helpText}</div>` : ''}
                ${isPin ? '<div style="font-size:10px;color:#b000ff;margin-top:4px;">🔑 PIN field</div>' : ''}
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
    console.log('[renderAssetFields] Rendered', fields.length, 'fields for', assetType);
}

function collectAssetFields(prefix, assetType) {
    const fields = {};
    const def = assetFields[assetType] || [];
    
    def.forEach(f => {
        const el = document.getElementById(prefix + '_' + f.name);
        if (el) {
            const value = el.value.trim();
            if (value) {
                fields[f.name] = value;
            }
        }
    });
    
    return fields;
}

// ============================================================
// SWAP TYPE SELECTOR
// ============================================================

function selectSwapType(type) {
    currentSwapType = type;
    
    document.querySelectorAll('.swap-type-option').forEach(el => {
        el.classList.toggle('active', el.dataset.value === type);
    });
    
    const isStandard = type === 'STANDARD';
    const isIdentity = type === 'IDENTITY';
    const isMulti = type === 'MULTI_SOURCE';
    
    document.getElementById('standardSwapSection').style.display = isStandard ? 'block' : 'none';
    document.getElementById('identitySwapSection').style.display = isIdentity ? 'block' : 'none';
    document.getElementById('multiSourceSection').style.display = isMulti ? 'block' : 'none';
    
    document.getElementById('identityHelp').style.display = isIdentity ? 'inline' : 'none';
    document.getElementById('multiHelp').style.display = isMulti ? 'inline' : 'none';
    
    if (isStandard) {
        updateStdDestination();
    }
    if (isIdentity) {
        updateIdAssetTypes();
    }
    if (isMulti) {
        updateMsDestination();
        if (sources.length === 0) addSource();
    }
    
    updateSummary();
}

// ============================================================
// STANDARD SWAP FUNCTIONS - DYNAMIC
// ============================================================

function updateStdAssetTypes() {
    const inst = document.getElementById('stdFromInstitution').value;
    const assetSelect = document.getElementById('stdAssetType');
    assetSelect.innerHTML = '<option value="">-- Select --</option>';
    if (inst && participants[inst]) {
        (participants[inst].asset_types || ['ACCOUNT']).forEach(type => {
            const ui = assetUI[type] || {};
            const opt = document.createElement('option');
            opt.value = type;
            opt.textContent = (ui.icon || '') + ' ' + (ui.display_name || type);
            assetSelect.appendChild(opt);
        });
    }
    // Show fields for first asset type if only one option
    if (assetSelect.options.length === 2 && assetSelect.options[1].value) {
        assetSelect.value = assetSelect.options[1].value;
        updateStdAssetFields();
    }
    updateSummary();
}

function updateStdAssetFields() {
    const assetType = document.getElementById('stdAssetType').value;
    renderAssetFields('std', assetType, 'stdAssetFieldsContainer');
}

function selectStdDestType(type) {
    stdDestType = type;
    document.querySelectorAll('#stdDestTypeContainer .dest-option').forEach(el => {
        el.classList.toggle('active', el.dataset.value === type);
    });
    updateStdDestination();
}

function updateStdDestination() {
    const swapType = document.getElementById('stdSwapType').value;
    const container = document.getElementById('stdDestinationFields');
    const typeContainer = document.getElementById('stdDestTypeContainer');
    
    if (swapType === 'CASHOUT') {
        typeContainer.style.display = 'none';
        container.innerHTML = `
            <div class="form-group">
                <label>📱 Beneficiary Phone</label>
                <input type="tel" id="stdBeneficiaryPhone" placeholder="+267XXXXXXXX" value="<?= $loggedPhone ?>">
            </div>
            <div class="info-box">🏧 They will receive an ATM code via SMS.</div>
        `;
        return;
    }
    
    // DEPOSIT
    typeContainer.style.display = 'block';
    if (stdDestType === 'ACCOUNT') {
        container.innerHTML = `
            <div class="form-group">
                <label>🏦 Account Number</label>
                <input type="text" id="stdDestAccount" placeholder="Enter account number">
            </div>
            <div class="form-group">
                <label>🏦 Account Name (Optional)</label>
                <input type="text" id="stdDestAccountName" placeholder="Enter account name">
            </div>
            <div class="info-box">💳 Depositing to a bank account.</div>
        `;
    } else {
        container.innerHTML = `
            <div class="form-group">
                <label>📱 Phone Number (Wallet)</label>
                <input type="tel" id="stdDestPhone" placeholder="+267XXXXXXXX" value="<?= $loggedPhone ?>">
            </div>
            <div class="info-box">📱 Depositing to a mobile wallet.</div>
        `;
    }
}

function validateStdCorridor() {
    const from = document.getElementById('stdFromInstitution').value;
    const to = document.getElementById('stdToInstitution').value;
    const warn = document.getElementById('stdCorridorWarning');
    if (from && to && from === to) {
        warn.classList.add('show');
        return false;
    }
    warn.classList.remove('show');
    return true;
}

// ============================================================
// IDENTITY SWAP FUNCTIONS - DYNAMIC
// ============================================================

function updateIdAssetTypes() {
    const inst = document.getElementById('idFromInstitution').value;
    const assetSelect = document.getElementById('idAssetType');
    assetSelect.innerHTML = '<option value="">-- Select --</option>';
    if (inst && participants[inst]) {
        (participants[inst].asset_types || ['ACCOUNT']).forEach(type => {
            const ui = assetUI[type] || {};
            const opt = document.createElement('option');
            opt.value = type;
            opt.textContent = (ui.icon || '') + ' ' + (ui.display_name || type);
            assetSelect.appendChild(opt);
        });
    }
    if (assetSelect.options.length === 2 && assetSelect.options[1].value) {
        assetSelect.value = assetSelect.options[1].value;
        updateIdAssetFields();
    }
    updateSummary();
}

function updateIdAssetFields() {
    const assetType = document.getElementById('idAssetType').value;
    renderAssetFields('id', assetType, 'idAssetFieldsContainer');
}

// ============================================================
// MULTI-SOURCE FUNCTIONS - DYNAMIC
// ============================================================

function selectMsDestType(type) {
    msDestType = type;
    document.querySelectorAll('#msDestTypeContainer .dest-option').forEach(el => {
        el.classList.toggle('active', el.dataset.value === type);
    });
    updateMsDestination();
}

function updateMsDestination() {
    const swapType = document.getElementById('msSwapType').value;
    const container = document.getElementById('msDestinationFields');
    const typeContainer = document.getElementById('msDestTypeContainer');
    
    if (swapType === 'CASHOUT') {
        typeContainer.style.display = 'none';
        container.innerHTML = `
            <div class="form-group">
                <label>📱 Beneficiary Phone</label>
                <input type="tel" id="msBeneficiaryPhone" placeholder="+267XXXXXXXX" value="<?= $loggedPhone ?>">
            </div>
            <div class="info-box">🏧 They will receive an ATM code via SMS.</div>
        `;
        return;
    }
    
    // DEPOSIT
    typeContainer.style.display = 'block';
    if (msDestType === 'ACCOUNT') {
        container.innerHTML = `
            <div class="form-group">
                <label>🏦 Account Number</label>
                <input type="text" id="msDestAccount" placeholder="Enter account number">
            </div>
            <div class="form-group">
                <label>🏦 Account Name (Optional)</label>
                <input type="text" id="msDestAccountName" placeholder="Enter account name">
            </div>
            <div class="info-box">💳 Depositing to a bank account.</div>
        `;
    } else {
        container.innerHTML = `
            <div class="form-group">
                <label>📱 Phone Number (Wallet)</label>
                <input type="tel" id="msDestPhone" placeholder="+267XXXXXXXX" value="<?= $loggedPhone ?>">
            </div>
            <div class="info-box">📱 Depositing to a mobile wallet.</div>
        `;
    }
}

function toggleMultiSource(event) {
    const checked = document.getElementById('multiSourceCheckbox').checked;
    document.getElementById('sourcesContainer').style.display = checked ? 'block' : 'none';
    if (checked && sources.length === 0) addSource();
    updateSummary();
}

function addSource() {
    sourceCounter++;
    const sourceId = 'source_' + sourceCounter;
    
    // Build asset type options dynamically
    let assetOptions = '';
    for (const [code, config] of Object.entries(assetUI)) {
        const icon = config.icon || '📦';
        const name = config.display_name || code;
        assetOptions += `<option value="${code}">${icon} ${name}</option>`;
    }
    
    const entry = document.createElement('div');
    entry.className = 'source-entry';
    entry.id = sourceId;
    entry.innerHTML = `
        <div class="source-header">
            <span class="source-number">📤 Source ${sourceCounter}</span>
            <button class="btn-danger" onclick="removeSource('${sourceId}')">✕</button>
        </div>
        <div class="source-fields">
            <div class="form-group">
                <label>Institution</label>
                <select id="${sourceId}_institution" onchange="updateSourceAssetTypes('${sourceId}')">
                    <option value="">-- Select --</option>
                    ${Object.entries(participants).map(([code, p]) => 
                        `<option value="${code}">${p.name}</option>`
                    ).join('')}
                </select>
            </div>
            <div class="form-group">
                <label>Asset Type</label>
                <select id="${sourceId}_assetType" onchange="updateSourceFields('${sourceId}')">
                    <option value="">-- Select --</option>
                    ${assetOptions}
                </select>
            </div>
            <div class="form-group">
                <label>Amount (${currencySymbol})</label>
                <input type="number" id="${sourceId}_amount" step="0.01" placeholder="0.00" oninput="updateSummary()">
            </div>
        </div>
        <div id="${sourceId}_fields" class="asset-fields-container" style="margin-top:8px;"></div>
        <div class="form-group" style="margin-top:8px;">
            <label>Identifier</label>
            <select id="${sourceId}_identifierSelect" style="width:100%; padding:8px 10px; background:#1a1f3a; border:1px solid #2a2f4a; border-radius:8px; color:#fff; font-size:13px;">
                <option value="">-- Select --</option>
                ${userIdentifiers.map(id => 
                    `<option value="${id.value}" data-type="${id.type}">${id.icon} ${id.value}</option>`
                ).join('')}
            </select>
        </div>
    `;
    
    document.getElementById('sourceEntries').appendChild(entry);
    sources.push({ id: sourceId, counter: sourceCounter });
    updateSourceCount();
    updateSummary();
}

function removeSource(sourceId) {
    const entry = document.getElementById(sourceId);
    if (entry) {
        entry.remove();
        sources = sources.filter(s => s.id !== sourceId);
        updateSourceCount();
        updateSummary();
    }
    if (sources.length === 0) addSource();
}

function updateSourceCount() {
    document.getElementById('sourceCount').textContent = sources.length + ' source' + (sources.length > 1 ? 's' : '');
}

function updateSourceAssetTypes(sourceId) {
    const inst = document.getElementById(sourceId + '_institution').value;
    const assetSelect = document.getElementById(sourceId + '_assetType');
    assetSelect.innerHTML = '<option value="">-- Select --</option>';
    if (inst && participants[inst]) {
        (participants[inst].asset_types || ['ACCOUNT']).forEach(type => {
            const ui = assetUI[type] || {};
            const opt = document.createElement('option');
            opt.value = type;
            opt.textContent = (ui.icon || '') + ' ' + (ui.display_name || type);
            assetSelect.appendChild(opt);
        });
    }
    // Show fields if only one option
    if (assetSelect.options.length === 2 && assetSelect.options[1].value) {
        assetSelect.value = assetSelect.options[1].value;
        updateSourceFields(sourceId);
    }
}

function updateSourceFields(sourceId) {
    const assetType = document.getElementById(sourceId + '_assetType').value;
    const container = document.getElementById(sourceId + '_fields');
    container.innerHTML = '';
    
    if (!assetType) {
        container.innerHTML = '<div class="info-box">Please select an asset type</div>';
        return;
    }
    
    const fields = assetFields[assetType] || [];
    if (fields.length === 0) {
        container.innerHTML = '<div class="info-box">✅ No additional fields</div>';
        return;
    }
    
    let html = '<div class="form-row" style="margin-bottom:0;">';
    fields.forEach(f => {
        const isPin = f.type === 'password' || f.name.includes('pin') || f.vault_field === 'pin';
        const fieldId = sourceId + '_' + f.name;
        html += `
            <div class="form-group">
                <label>${f.label || f.name}</label>
                <input type="${isPin ? 'password' : (f.type || 'text')}" 
                       id="${fieldId}" 
                       placeholder="${f.placeholder || ''}"
                       class="source-asset-field">
                ${isPin ? '<div style="font-size:10px;color:#b000ff;margin-top:4px;">🔑 PIN field</div>' : ''}
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

// ============================================================
// SUMMARY
// ============================================================

function updateSummary() {
    const isStandard = currentSwapType === 'STANDARD';
    const isIdentity = currentSwapType === 'IDENTITY';
    const isMulti = currentSwapType === 'MULTI_SOURCE';
    
    if (isStandard) {
        const from = document.getElementById('stdFromInstitution')?.value || '?';
        const to = document.getElementById('stdToInstitution')?.value || '?';
        const amount = parseFloat(document.getElementById('stdAmount')?.value) || 0;
        const type = document.getElementById('stdSwapType')?.value || 'DEPOSIT';
        const sourceId = document.getElementById('stdSourceIdentifier')?.value || '?';
        const destType = stdDestType || 'ACCOUNT';
        
        let summary = `⬆️➡️ ${from} → ${to}`;
        if (amount > 0) summary += ` · ${currencySymbol} ${amount.toFixed(2)}`;
        summary += ` · ${type}`;
        if (type === 'DEPOSIT') summary += ` · ${destType}`;
        document.getElementById('summary').innerHTML = '📋 ' + summary;
        return;
    }
    
    if (isIdentity) {
        const from = document.getElementById('idFromInstitution')?.value || '?';
        const amount = parseFloat(document.getElementById('idAmount')?.value) || 0;
        const idType = document.getElementById('idIdentityType')?.value || '?';
        const idValue = document.getElementById('idIdentityValue')?.value || '?';
        document.getElementById('summary').innerHTML = `🔐 ${from} → ${idType}: ${idValue} · ${currencySymbol} ${amount.toFixed(2)}`;
        return;
    }
    
    if (isMulti) {
        let total = 0;
        let details = [];
        sources.forEach(s => {
            const amt = parseFloat(document.getElementById(s.id + '_amount')?.value) || 0;
            total += amt;
            const inst = document.getElementById(s.id + '_institution')?.value || '?';
            if (amt > 0) details.push(inst + ': ' + amt.toFixed(2));
        });
        document.getElementById('totalSourceAmount').textContent = currencySymbol + ' ' + total.toFixed(2);
        document.getElementById('sourceList').textContent = details.length > 0 ? details.join('; ') : 'No sources configured';
        document.getElementById('sourceSummary').style.display = total > 0 ? 'block' : 'none';
        const to = document.getElementById('msToInstitution')?.value || '?';
        const type = document.getElementById('msSwapType')?.value || 'DEPOSIT';
        document.getElementById('summary').innerHTML = `📦 ${to} · ${type} · ${currencySymbol} ${total.toFixed(2)} · ${sources.length} source(s)`;
        return;
    }
}

// ============================================================
// BUILD PAYLOAD - DYNAMIC
// ============================================================

function buildPayload() {
    const isStandard = currentSwapType === 'STANDARD';
    const isIdentity = currentSwapType === 'IDENTITY';
    const isMulti = currentSwapType === 'MULTI_SOURCE';
    
    const payload = {
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8),
        currency: '<?= $currency ?>'
    };
    
    if (isStandard) {
        payload.swap_type = document.getElementById('stdSwapType').value || 'DEPOSIT';
        payload.from_institution = document.getElementById('stdFromInstitution').value || '';
        payload.to_institution = document.getElementById('stdToInstitution').value || '';
        payload.asset_type = document.getElementById('stdAssetType').value || 'ACCOUNT';
        payload.source_identifier = document.getElementById('stdSourceIdentifier').value || '';
        payload.amount = parseFloat(document.getElementById('stdAmount').value) || 0;
        payload.destination_asset_type = stdDestType || 'ACCOUNT';
        
        // Collect asset fields dynamically
        const assetFieldsData = collectAssetFields('std', payload.asset_type);
        Object.assign(payload, assetFieldsData);
        
        // Destination
        if (payload.swap_type === 'CASHOUT') {
            payload.beneficiary_phone = document.getElementById('stdBeneficiaryPhone')?.value || '';
            payload.destination_identifier = payload.beneficiary_phone;
            payload.destination_identifier_type = 'phone';
        } else {
            if (stdDestType === 'ACCOUNT') {
                payload.destination_account = document.getElementById('stdDestAccount')?.value || '';
                payload.account_name = document.getElementById('stdDestAccountName')?.value || '';
                payload.destination_identifier = payload.destination_account;
                payload.destination_identifier_type = 'account';
            } else {
                payload.destination_phone = document.getElementById('stdDestPhone')?.value || '';
                payload.destination_identifier = payload.destination_phone;
                payload.destination_identifier_type = 'phone';
            }
        }
        
    } else if (isIdentity) {
        payload.swap_type = 'IDENTITY';
        payload.from_institution = document.getElementById('idFromInstitution').value || '';
        payload.asset_type = document.getElementById('idAssetType').value || 'ACCOUNT';
        payload.source_identifier = document.getElementById('idSourceIdentifier').value || '';
        payload.amount = parseFloat(document.getElementById('idAmount').value) || 0;
        payload.identity_type = document.getElementById('idIdentityType').value || 'national_id';
        payload.identity_value = document.getElementById('idIdentityValue').value || '';
        
        // Collect asset fields dynamically
        const assetFieldsData = collectAssetFields('id', payload.asset_type);
        Object.assign(payload, assetFieldsData);
        
    } else if (isMulti) {
        payload.swap_type = 'MULTI_SOURCE';
        payload.sources = [];
        let total = 0;
        
        sources.forEach(s => {
            const inst = document.getElementById(s.id + '_institution')?.value || '';
            const asset = document.getElementById(s.id + '_assetType')?.value || 'ACCOUNT';
            const amount = parseFloat(document.getElementById(s.id + '_amount')?.value) || 0;
            const ident = document.getElementById(s.id + '_identifierSelect')?.value || '';
            
            if (inst && amount > 0) {
                const source = { institution: inst, asset_type: asset, amount: amount, identifier: ident };
                // Collect asset fields for this source
                const fields = assetFields[asset] || [];
                fields.forEach(f => {
                    const el = document.getElementById(s.id + '_' + f.name);
                    if (el && el.value.trim()) {
                        source[f.name] = el.value.trim();
                    }
                });
                payload.sources.push(source);
                total += amount;
            }
        });
        
        payload.amount = total;
        payload.to_institution = document.getElementById('msToInstitution').value || '';
        payload.delivery_method = document.getElementById('msSwapType').value || 'DEPOSIT';
        payload.destination_asset_type = msDestType || 'ACCOUNT';
        payload.contribution_strategy = 'SMART';
        
        if (payload.delivery_method === 'CASHOUT') {
            payload.beneficiary_phone = document.getElementById('msBeneficiaryPhone')?.value || '';
            payload.destination_identifier = payload.beneficiary_phone;
            payload.destination_identifier_type = 'phone';
        } else {
            if (msDestType === 'ACCOUNT') {
                payload.destination_account = document.getElementById('msDestAccount')?.value || '';
                payload.account_name = document.getElementById('msDestAccountName')?.value || '';
                payload.destination_identifier = payload.destination_account;
                payload.destination_identifier_type = 'account';
            } else {
                payload.destination_phone = document.getElementById('msDestPhone')?.value || '';
                payload.destination_identifier = payload.destination_phone;
                payload.destination_identifier_type = 'phone';
            }
        }
    }
    
    // PIN
    const pin = document.getElementById('userPin')?.value || '';
    if (pin) {
        payload.pin = pin;
        payload.wallet_pin = pin;
    }
    
    console.log('Final Payload:', payload);
    return payload;
}

// ============================================================
// EXECUTE
// ============================================================

document.getElementById('executeBtn').addEventListener('click', async function() {
    const pin = document.getElementById('userPin').value;
    if (!pin) { alert('🔑 Enter your PIN'); return; }
    
    const isStandard = currentSwapType === 'STANDARD';
    const isIdentity = currentSwapType === 'IDENTITY';
    const isMulti = currentSwapType === 'MULTI_SOURCE';
    
    if (isStandard) {
        const from = document.getElementById('stdFromInstitution').value;
        const to = document.getElementById('stdToInstitution').value;
        const amount = parseFloat(document.getElementById('stdAmount').value);
        const sourceId = document.getElementById('stdSourceIdentifier').value;
        
        if (!from) { alert('Select source institution'); return; }
        if (!to) { alert('Select destination institution'); return; }
        if (!sourceId) { alert('Select your source identifier'); return; }
        if (!amount || amount <= 0) { alert('Enter valid amount'); return; }
        if (from === to) { alert('Source and destination must be different'); return; }
        
        if (document.getElementById('stdSwapType').value === 'CASHOUT') {
            if (!document.getElementById('stdBeneficiaryPhone').value) { alert('Enter beneficiary phone'); return; }
        } else if (stdDestType === 'ACCOUNT') {
            if (!document.getElementById('stdDestAccount').value) { alert('Enter destination account number'); return; }
        } else {
            if (!document.getElementById('stdDestPhone').value) { alert('Enter destination phone number'); return; }
        }
        
    } else if (isIdentity) {
        const from = document.getElementById('idFromInstitution').value;
        const amount = parseFloat(document.getElementById('idAmount').value);
        const idValue = document.getElementById('idIdentityValue').value.trim();
        const sourceId = document.getElementById('idSourceIdentifier').value;
        
        if (!from) { alert('Select source institution'); return; }
        if (!sourceId) { alert('Select your source identifier'); return; }
        if (!amount || amount <= 0) { alert('Enter valid amount'); return; }
        if (!idValue) { alert('Enter the identity value'); return; }
        
    } else if (isMulti) {
        let hasError = false;
        let total = 0;
        sources.forEach(s => {
            const amt = parseFloat(document.getElementById(s.id + '_amount')?.value) || 0;
            const inst = document.getElementById(s.id + '_institution')?.value;
            const ident = document.getElementById(s.id + '_identifierSelect')?.value;
            if (!inst) { hasError = true; alert('Select institution for source ' + s.counter); return; }
            if (!ident) { hasError = true; alert('Select identifier for source ' + s.counter); return; }
            if (amt <= 0) { hasError = true; alert('Enter valid amount for source ' + s.counter); return; }
            total += amt;
        });
        if (hasError) return;
        if (sources.length < 2) { alert('Add at least 2 sources'); return; }
        if (total <= 0) { alert('Total amount must be > 0'); return; }
        if (!document.getElementById('msToInstitution').value) { alert('Select destination institution'); return; }
        
        if (document.getElementById('msSwapType').value === 'CASHOUT') {
            if (!document.getElementById('msBeneficiaryPhone').value) { alert('Enter beneficiary phone'); return; }
        } else if (msDestType === 'ACCOUNT') {
            if (!document.getElementById('msDestAccount').value) { alert('Enter destination account number'); return; }
        } else {
            if (!document.getElementById('msDestPhone').value) { alert('Enter destination phone number'); return; }
        }
    }
    
    const payload = buildPayload();
    await showConfirmation(payload);
});

// ============================================================
// CONFIRMATION MODAL (unchanged)
// ============================================================

async function showConfirmation(payload) {
    const modal = document.getElementById('confirmModal');
    const details = document.getElementById('modalDetails');
    const error = document.getElementById('modalError');
    const confirmBtn = document.getElementById('confirmBtn');
    
    error.style.display = 'none';
    confirmBtn.disabled = true;
    confirmBtn.textContent = '⏳ Loading...';
    details.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><br>Calculating fees...</div>';
    modal.classList.add('show');
    
    try {
        const resp = await fetch(previewUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': apiKey },
            body: JSON.stringify(payload)
        });
        const result = await resp.json();
        
        if (!result.success) throw new Error(result.error || 'Fee calculation failed');
        
        previewData = result.preview;
        pendingPayload = payload;
        
        const p = previewData;
        const totalFee = p.total_fee || 0;
        const netAmount = p.net_amount_destination_currency || p.amount || 0;
        
        let feeHTML = '';
        if (p.fee_breakdown && p.fee_breakdown.length > 0) {
            feeHTML = '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #1a1f3a;">';
            p.fee_breakdown.forEach(item => {
                if ((item.amount || 0) > 0) {
                    feeHTML += `<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;color:#ccc;">
                        <span>${item.name || item.slot || 'Fee'}</span>
                        <span>${(item.amount || 0).toFixed(2)} ${p.source_currency || 'BWP'}</span>
                    </div>`;
                }
            });
            feeHTML += '</div>';
        }
        
        details.innerHTML = `
            <div style="margin-bottom:12px;">
                <div style="font-size:12px;color:#888;">${p.swap_type || 'Swap'}</div>
                <div style="color:#00f0ff;">${p.source_institution || '?'} → ${p.destination_institution || '?'}</div>
            </div>
            <div class="row"><span class="label">💰 Amount</span><span class="value">${(p.amount_requested || p.amount || 0).toFixed(2)} ${p.source_currency || 'BWP'}</span></div>
            <div class="row"><span class="label">📊 Fee</span><span class="value negative">${totalFee.toFixed(2)} ${p.source_currency || 'BWP'}</span></div>
            ${feeHTML}
            <div class="row total"><span class="label">📥 You Receive</span><span class="value highlight">${netAmount.toFixed(2)} ${p.destination_currency || p.source_currency || 'BWP'}</span></div>
            ${p.is_multi_source ? `<div class="info-box" style="margin-top:10px;">📦 Multi-Source · ${p.source_count || 0} source(s)</div>` : ''}
            ${p.identity_type ? `<div class="info-box" style="margin-top:10px;">🔐 Identity: ${p.identity_type} → ${p.identity_value}</div>` : ''}
        `;
        
        confirmBtn.disabled = false;
        confirmBtn.textContent = '✅ Confirm & Execute';
        
    } catch (err) {
        error.textContent = '❌ ' + err.message;
        error.style.display = 'block';
        details.innerHTML = '<div style="text-align:center;padding:20px;color:#ff6b6b;">❌ Failed to calculate fees</div>';
        confirmBtn.disabled = true;
        confirmBtn.textContent = '❌ Error';
    }
}

function closeConfirmation() {
    document.getElementById('confirmModal').classList.remove('show');
    pendingPayload = null;
    previewData = null;
}

document.getElementById('confirmBtn').addEventListener('click', async function() {
    if (!pendingPayload) return;
    
    const btn = this;
    const resultDiv = document.getElementById('result');
    btn.disabled = true;
    btn.innerHTML = '<div class="loading-spinner"></div> Executing...';
    document.getElementById('modalError').style.display = 'none';
    
    try {
        const resp = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': apiKey },
            body: JSON.stringify(pendingPayload)
        });
        const result = await resp.json();
        
        const isSuccess = result.success === true || result.status === 'pending_cashout' || result.atomic_commit?.status === 'committed';
        
        if (isSuccess) {
            closeConfirmation();
            resultDiv.className = 'result success';
            const ref = result.reference || result.swap_reference || 'N/A';
            const atmCode = result.atm_code || result.atm_pin || result.data?.atm_code || null;
            
            let html = `<strong>✅ Swap Successful!</strong><br><br>`;
            html += `📋 Reference: <span style="color:#888;font-size:12px;">${ref}</span><br>`;
            html += `💰 Amount: ${currencySymbol} ${(pendingPayload.amount || 0).toFixed(2)}<br>`;
            if (result.fee) html += `📊 Fee: ${currencySymbol} ${parseFloat(result.fee).toFixed(2)}<br>`;
            if (atmCode) html += `<br>🏧 <span class="code">${atmCode}</span><br><span style="font-size:12px;color:#888;">ATM Cashout Code</span>`;
            if (pendingPayload.identity_type) {
                html += `<br>🔐 Identity: ${pendingPayload.identity_type} → ${pendingPayload.identity_value}`;
            }
            html += `<br><br><a href="history.php?id=${ref}" style="color:#00f0ff;">View Details →</a>`;
            resultDiv.innerHTML = html;
            resultDiv.scrollIntoView({ behavior: 'smooth' });
            setTimeout(() => location.reload(), 3000);
        } else {
            const err = result.message || result.error || 'Unknown error';
            document.getElementById('modalError').textContent = '❌ ' + err;
            document.getElementById('modalError').style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '🔄 Try Again';
        }
    } catch (err) {
        document.getElementById('modalError').textContent = '❌ Network error: ' + err.message;
        document.getElementById('modalError').style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '🔄 Try Again';
    }
});

// ============================================================
// EVENT LISTENERS & INITIALIZATION
// ============================================================

document.addEventListener('DOMContentLoaded', function() {
    // Initialize
    updateStdDestination();
    updateMsDestination();
    updateSummary();
    selectSwapType('STANDARD');
    
    // Auto-detect identifier type for identity
    document.getElementById('idSourceIdentifier')?.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.dataset.type) {
            const typeMap = { 'phone': 'phone', 'email': 'email', 'national_id': 'national_id' };
            const idType = document.getElementById('idIdentityType');
            if (idType && typeMap[opt.dataset.type]) {
                idType.value = typeMap[opt.dataset.type];
            }
        }
        updateSummary();
    });
    
    // Debug output
    console.log('✅ VouchMorph Dashboard loaded');
    console.log('👤 User:', userIdentifiers);
    console.log('🏦 Participants:', Object.keys(participants));
    console.log('📦 Asset Types:', Object.keys(assetFields));
    console.log('📋 Asset Fields:', assetFields);
    console.log('📋 VOUCHER Fields:', assetFields['VOUCHER']);
});
</script>
</body>
</html>
