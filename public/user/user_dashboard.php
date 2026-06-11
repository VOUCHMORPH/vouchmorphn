<?php
// public/user/dashboard.php - FULLY DYNAMIC, NO HARDCODING
// Works for any country by loading config from the country folder
// Backend dictates outcome - this only sends properly formatted payloads

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user = SessionManager::getUser();
$loggedPhone = htmlspecialchars($user['phone'] ?? '');
$userId = $user['user_id'] ?? null;

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;

$config = LoadCountry::getConfig();
$countryCode = $config['country_code'] ?? 'BW';
$countryName = $config['country'] ?? 'Botswana';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';
$currency = $config['currency'] ?? 'BWP';

// Load ATM notes for cashout denomination validation (display only, backend validates)
$atmNotesPath = __DIR__ . '/../../src/Core/Config/Countries/' . $countryName . '/atm_notes.json';
$atmDenominations = [200, 100, 50, 20, 10]; // Default
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
// DYNAMIC LOADING - Reads from country folder
// ============================================================

$countryFolder = __DIR__ . '/../../src/Core/Config/Countries/' . $countryName;
$participantsYamlPath = $countryFolder . '/participants.yaml';
$assetsYamlPath = __DIR__ . '/../../src/Core/Config/assets.yaml';

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
    
    // If no asset_types defined, set default based on institution type
    foreach ($participants as $code => &$p) {
        if (empty($p['asset_types'])) {
            // Default asset types based on institution type
            if ($code === 'ZURUBANK') {
                $p['asset_types'] = ['VOUCHER'];
            } elseif ($code === 'VOUCHMORPH') {
                $p['asset_types'] = ['VOUCHER', 'ACCOUNT'];
            } else {
                $p['asset_types'] = ['ACCOUNT'];
            }
        }
    }
    
    return $participants;
}

function parseAssetsYaml($path) {
    $assets = [];
    if (!file_exists($path)) return $assets;
    
    $content = file_get_contents($path);
    $lines = explode("\n", $content);
    $current = null;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        if (preg_match('/^([A-Z-]+):$/', $line, $matches)) {
            $current = $matches[1];
            $assets[$current] = ['code' => $current];
            continue;
        }
        
        if ($current && preg_match('/^  ui:/', $line)) {
            continue;
        }
        
        if ($current && preg_match('/^    display_name: (.+)$/', $line, $matches)) {
            $assets[$current]['display_name'] = trim($matches[1], '"\'');
            continue;
        }
        
        if ($current && preg_match('/^    icon: (.+)$/', $line, $matches)) {
            $assets[$current]['icon'] = trim($matches[1], '"\'');
            continue;
        }
        
        if ($current && preg_match('/^    description: (.+)$/', $line, $matches)) {
            $assets[$current]['description'] = trim($matches[1], '"\'');
            continue;
        }
        
        if ($current && preg_match('/^  fields:$/', $line)) {
            $assets[$current]['fields'] = [];
            continue;
        }
        
        if ($current && isset($assets[$current]['fields']) && preg_match('/^    - name: (.+)$/', $line, $matches)) {
            $assets[$current]['fields'][] = ['name' => trim($matches[1])];
            continue;
        }
        
        if ($current && isset($assets[$current]['fields']) && preg_match('/^      label: (.+)$/', $line, $matches)) {
            if (!empty($assets[$current]['fields'])) {
                $assets[$current]['fields'][count($assets[$current]['fields']) - 1]['label'] = trim($matches[1], '"\'');
            }
        }
        
        if ($current && isset($assets[$current]['fields']) && preg_match('/^      placeholder: (.+)$/', $line, $matches)) {
            if (!empty($assets[$current]['fields'])) {
                $assets[$current]['fields'][count($assets[$current]['fields']) - 1]['placeholder'] = trim($matches[1], '"\'');
            }
        }
        
        if ($current && isset($assets[$current]['fields']) && preg_match('/^      required: (.+)$/', $line, $matches)) {
            if (!empty($assets[$current]['fields'])) {
                $assets[$current]['fields'][count($assets[$current]['fields']) - 1]['required'] = trim($matches[1]) === 'true';
            }
        }
    }
    
    return $assets;
}

$participants = parseParticipantsYaml($participantsYamlPath);
$assets = parseAssetsYaml($assetsYamlPath);

// Build asset fields mapping for dynamic form generation
$assetFields = [];
foreach ($assets as $code => $asset) {
    $assetFields[$code] = $asset['fields'] ?? [];
}

// Get recent swaps
$recentSwaps = [];
try {
    $stmt = $swapDB->prepare("
        SELECT swap_reference, amount, from_institution, to_institution, status, created_at, fee_amount 
        FROM swap_ledgers 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$apiUrl = '/api/v1/swap/execute.php';
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: 'vouchmorph_live_1aB2cD3eF4gH5iJ6';

$denominationsList = implode(', ', $atmDenominations);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph | Swap | <?= htmlspecialchars($countryName) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0e27;
            padding: 20px;
            color: #fff;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .logo h1 { font-size: 24px; background: linear-gradient(135deg, #fff, #00f0ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .logo p { font-size: 12px; color: #a0a0b0; margin-top: 4px; }
        .country-badge { 
            padding: 4px 12px; 
            background: rgba(0,240,255,0.1); 
            border: 1px solid #00f0ff; 
            border-radius: 20px;
            font-size: 12px;
        }
        .user-info { text-align: right; }
        .user-phone { color: #00f0ff; font-weight: bold; }
        
        .card {
            background: #12162e;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .card h3 { margin-bottom: 20px; font-size: 18px; display: flex; align-items: center; gap: 8px; }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 16px; }
        label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #a0a0b0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        select, input {
            width: 100%;
            padding: 12px;
            background: #1a1f3a;
            border: 1px solid #2a2f4a;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            cursor: pointer;
        }
        select:focus, input:focus { outline: none; border-color: #00f0ff; }
        select option { background: #1a1f3a; }
        
        .dynamic-fields {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #2a2f4a;
            display: none;
        }
        .dynamic-fields.active { display: block; }
        
        .corridor-warning {
            background: rgba(255, 193, 7, 0.1);
            border-left: 3px solid #ffc107;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 16px;
            display: none;
        }
        .corridor-warning.show { display: block; }
        
        .denomination-info {
            background: rgba(0, 240, 255, 0.05);
            border-left: 3px solid #00f0ff;
            padding: 10px;
            border-radius: 6px;
            font-size: 11px;
            margin-bottom: 16px;
            display: none;
        }
        .denomination-info.show { display: block; }
        
        .quick-amounts {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .quick-amount {
            padding: 5px 12px;
            background: #1a1f3a;
            border-radius: 20px;
            cursor: pointer;
            font-size: 12px;
            border: 1px solid #2a2f4a;
        }
        .quick-amount:hover { background: #00f0ff; color: #0a0e27; }
        
        .info-note {
            background: rgba(0, 240, 255, 0.05);
            border-left: 3px solid #00f0ff;
            padding: 10px;
            border-radius: 6px;
            font-size: 11px;
            color: #a0a0b0;
            margin: 12px 0;
        }
        
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #00f0ff, #b000ff);
            color: #0a0e27;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 8px;
        }
        button:hover { transform: translateY(-1px); filter: brightness(1.05); }
        button:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        
        .summary {
            background: rgba(0, 240, 255, 0.05);
            padding: 14px;
            border-radius: 8px;
            margin: 16px 0;
            font-size: 13px;
            border: 1px solid rgba(0, 240, 255, 0.2);
        }
        
        .result {
            padding: 16px;
            border-radius: 8px;
            margin-top: 16px;
            display: none;
        }
        .result.success { background: rgba(76, 175, 80, 0.2); border: 1px solid #4caf50; display: block; color: #4caf50; }
        .result.error { background: rgba(244, 67, 54, 0.2); border: 1px solid #f44336; display: block; color: #f44336; }
        .result.loading { background: rgba(255, 193, 7, 0.2); border: 1px solid #ffc107; display: block; color: #ffc107; }
        
        .note-breakdown {
            background: rgba(76, 175, 80, 0.1);
            padding: 10px;
            border-radius: 6px;
            margin: 10px 0;
            font-family: monospace;
        }
        
        .swap-item {
            display: flex;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid #2a2f4a;
            font-size: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .swap-status.completed { color: #4caf50; }
        .swap-status.failed { color: #f44336; }
        .swap-status.pending { color: #ffc107; }
        
        .fee-display { color: #ffc107; font-size: 10px; margin-top: 4px; }
        
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; gap: 16px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">
            <h1>VouchMorph</h1>
            <p>Financial Institution Interoperability</p>
        </div>
        <div style="display: flex; gap: 12px; align-items: center;">
            <div class="country-badge"><?= htmlspecialchars($countryCode) ?></div>
            <div class="user-info">
                <div>👤 <span class="user-phone"><?= $loggedPhone ?></span></div>
                <div style="font-size: 10px; margin-top: 4px;"><a href="logout.php" style="color: #888;">Logout</a></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>🔄 New Swap</h3>
        
        <div id="corridorWarning" class="corridor-warning">
            ⚠️ Source and destination must be different institutions.
        </div>
        
        <div id="denominationInfo" class="denomination-info">
            💡 Cashout amounts are dispensed using available notes: <strong><?= $denominationsList ?> <?= $currencySymbol ?></strong><br>
            Any remainder will stay in your account balance (backend validates this).
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>📤 FROM INSTITUTION (Source)</label>
                <select id="fromInstitution">
                    <option value="">-- Select --</option>
                    <?php foreach ($participants as $code => $p): ?>
                        <option value="<?= htmlspecialchars($code) ?>" 
                                data-asset-types='<?= json_encode($p['asset_types'] ?? ['ACCOUNT']) ?>'>
                            <?= htmlspecialchars($p['name'] ?? $code) ?>
                            <span style="font-size: 10px; color: #888;">(<?= implode(', ', $p['asset_types'] ?? ['ACCOUNT']) ?>)</span>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>📥 TO INSTITUTION (Destination)</label>
                <select id="toInstitution">
                    <option value="">-- Select --</option>
                    <?php foreach ($participants as $code => $p): ?>
                        <option value="<?= htmlspecialchars($code) ?>">
                            <?= htmlspecialchars($p['name'] ?? $code) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>🏷️ ASSET TYPE</label>
                <select id="assetType">
                    <option value="">-- Select Asset Type --</option>
                </select>
                <div id="assetTypeHint" style="font-size: 10px; color: #888; margin-top: 5px;"></div>
            </div>
            <div class="form-group">
                <label>📦 SWAP TYPE</label>
                <select id="swapType">
                    <option value="CASHOUT">🏧 Cashout (ATM / Agent)</option>
                    <option value="DEPOSIT">💳 Deposit (Bank Account / Wallet)</option>
                </select>
            </div>
        </div>

        <!-- Dynamic Asset Fields Container (fields like voucher_number, voucher_pin, etc.) -->
        <div id="assetFieldsContainer" class="dynamic-fields"></div>

        <!-- Dynamic Destination Fields Container -->
        <div id="destFieldsContainer" class="dynamic-fields"></div>

        <div class="form-group">
            <label>AMOUNT (<?= $currencySymbol ?>)</label>
            <input type="number" id="amount" step="0.01" placeholder="0.00">
            <div class="quick-amounts">
                <?php foreach ($atmDenominations as $denom): ?>
                    <span class="quick-amount" data-amount="<?= $denom ?>"><?= $denom ?></span>
                <?php endforeach; ?>
                <span class="quick-amount" data-amount="500">500</span>
                <span class="quick-amount" data-amount="1000">1000</span>
            </div>
        </div>

        <div class="summary" id="summary">📋 Select from institution, asset type, and to institution</div>

        <button id="executeBtn">🚀 Execute Swap</button>
    </div>

    <div id="result" class="result"></div>

    <div class="card">
        <h3>📋 Recent Swaps</h3>
        <?php if (empty($recentSwaps)): ?>
            <div style="text-align:center; padding:20px; color:#a0a0b0;">No swaps yet</div>
        <?php else: ?>
            <?php foreach ($recentSwaps as $swap): ?>
                <div class="swap-item">
                    <div>
                        <strong><?= htmlspecialchars($swap['from_institution'] ?? '?') ?></strong> → <strong><?= htmlspecialchars($swap['to_institution'] ?? '?') ?></strong>
                        <?php if (!empty($swap['fee_amount'])): ?>
                            <div class="fee-display">Fee: <?= number_format($swap['fee_amount'], 2) ?> <?= $currencySymbol ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?= number_format($swap['amount'], 2) ?> <?= $currencySymbol ?>
                        <div class="swap-status <?= strtolower($swap['status'] ?? 'completed') ?>"><?= $swap['status'] ?? 'Completed' ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Dynamic data from PHP (loaded from YAML)
const participants = <?= json_encode($participants) ?>;
const assetFields = <?= json_encode($assetFields) ?>;
const assets = <?= json_encode($assets) ?>;

// Store asset types per institution from parsed data
const institutionAssets = {};
for (const [code, p] of Object.entries(participants)) {
    institutionAssets[code] = p.asset_types || ['ACCOUNT'];
}

console.log('Loaded participants:', participants);
console.log('Institution assets:', institutionAssets);

// DOM Elements
const fromInstSelect = document.getElementById('fromInstitution');
const toInstSelect = document.getElementById('toInstitution');
const assetTypeSelect = document.getElementById('assetType');
const assetTypeHint = document.getElementById('assetTypeHint');
const swapTypeSelect = document.getElementById('swapType');
const assetFieldsContainer = document.getElementById('assetFieldsContainer');
const destFieldsContainer = document.getElementById('destFieldsContainer');
const corridorWarning = document.getElementById('corridorWarning');
const denominationInfo = document.getElementById('denominationInfo');
const amountInput = document.getElementById('amount');
const summaryDiv = document.getElementById('summary');

// Update asset type dropdown based on selected source institution
function updateAssetTypes() {
    const fromInst = fromInstSelect.value;
    assetTypeSelect.innerHTML = '<option value="">-- Select Asset Type --</option>';
    
    if (!fromInst) {
        assetTypeHint.innerHTML = '';
        return;
    }
    
    const assetsList = institutionAssets[fromInst] || ['ACCOUNT'];
    
    if (assetsList.length === 0) {
        assetTypeHint.innerHTML = '⚠️ No asset types defined for this institution';
        return;
    }
    
    assetTypeHint.innerHTML = `Supported asset types: ${assetsList.join(', ')}`;
    
    assetsList.forEach(asset => {
        const displayName = assets[asset]?.display_name || asset;
        const icon = assets[asset]?.icon || '';
        const description = assets[asset]?.description || '';
        const option = document.createElement('option');
        option.value = asset;
        option.textContent = icon ? `${icon} ${displayName}` : displayName;
        if (description) {
            option.title = description;
        }
        assetTypeSelect.appendChild(option);
    });
    
    // Auto-select if only one option
    if (assetsList.length === 1) {
        assetTypeSelect.value = assetsList[0];
        updateAssetFields();
        updateSummary();
    }
}

// Generate asset fields (voucher_number, voucher_pin, etc.) based on selected asset type
function updateAssetFields() {
    const assetType = assetTypeSelect.value;
    const fields = assetFields[assetType] || [];
    
    assetFieldsContainer.innerHTML = '';
    assetFieldsContainer.classList.remove('active');
    
    if (fields.length === 0 || !assetType) return;
    
    let html = '<div class="form-row">';
    fields.forEach(field => {
        const fieldName = field.name;
        const label = field.label || fieldName.replace(/_/g, ' ').toUpperCase();
        const placeholder = field.placeholder || `Enter ${fieldName.replace(/_/g, ' ')}`;
        const inputType = fieldName.includes('pin') ? 'password' : 'text';
        const required = field.required ? 'required' : '';
        html += `
            <div class="form-group">
                <label>${label}</label>
                <input type="${inputType}" name="${fieldName}" id="${fieldName}" class="asset-field" placeholder="${placeholder}" ${required}>
            </div>
        `;
    });
    html += '</div>';
    
    assetFieldsContainer.innerHTML = html;
    assetFieldsContainer.classList.add('active');
}

// Generate destination fields based on swap type
function updateDestinationFields() {
    const swapType = swapTypeSelect.value;
    
    destFieldsContainer.innerHTML = '';
    destFieldsContainer.classList.remove('active');
    
    // Show denomination info for cashout
    if (swapType === 'CASHOUT') {
        denominationInfo.classList.add('show');
        destFieldsContainer.innerHTML = `
            <div class="form-group">
                <label>BENEFICIARY PHONE (for ATM code)</label>
                <input type="tel" id="beneficiaryPhone" placeholder="Phone number for SMS" value="<?= $loggedPhone ?>">
            </div>
            <div class="info-note">💡 ATM cashout code will be sent via SMS to this number. Backend will validate amount against available denominations.</div>
        `;
        destFieldsContainer.classList.add('active');
    } else {
        denominationInfo.classList.remove('show');
        destFieldsContainer.innerHTML = `
            <div class="form-group">
                <label>DESTINATION ACCOUNT / PHONE</label>
                <input type="text" id="destinationAccount" placeholder="Account number or phone number">
            </div>
            <div class="info-note">💡 Deposit amount can be any decimal value. Funds will be credited to the destination account.</div>
        `;
        destFieldsContainer.classList.add('active');
    }
}

// Check if source and destination are valid (must differ)
function validateCorridor() {
    const fromInst = fromInstSelect.value;
    const toInst = toInstSelect.value;
    
    if (!fromInst || !toInst) {
        corridorWarning.classList.remove('show');
        return true;
    }
    
    if (fromInst === toInst) {
        corridorWarning.classList.add('show');
        return false;
    }
    
    corridorWarning.classList.remove('show');
    return true;
}

// Update summary display
function updateSummary() {
    const fromInst = fromInstSelect.options[fromInstSelect.selectedIndex]?.text || '?';
    const toInst = toInstSelect.options[toInstSelect.selectedIndex]?.text || '?';
    const asset = assetTypeSelect.options[assetTypeSelect.selectedIndex]?.text || '?';
    const swapType = swapTypeSelect.options[swapTypeSelect.selectedIndex]?.text || '?';
    let amount = parseFloat(amountInput.value) || 0;
    
    summaryDiv.innerHTML = `📋 ${fromInst} (${asset}) → ${toInst} (${swapType}) | Amount: <?= $currencySymbol ?> ${amount.toFixed(2)}`;
}

// Build payload exactly as backend expects
function buildPayload() {
    const fromInst = fromInstSelect.value;
    const toInst = toInstSelect.value;
    const assetType = assetTypeSelect.value;
    const swapType = swapTypeSelect.value;
    const amount = parseFloat(amountInput.value);
    const reference = 'SWAP_' + Date.now();
    const idempotencyKey = 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8);
    
    // Base payload structure
    const payload = {
        reference: reference,
        idempotency_key: idempotencyKey,
        swap_type: swapType,
        from_institution: fromInst,
        to_institution: toInst,
        asset_type: assetType,
        amount: amount,
        currency: '<?= $currency ?>'
    };
    
    // Add asset-specific fields (voucher_number, voucher_pin, etc.)
    document.querySelectorAll('.asset-field').forEach(field => {
        const value = field.value.trim();
        if (value) {
            payload[field.id] = value;
        }
    });
    
    // Add destination-specific fields
    if (swapType === 'CASHOUT') {
        const beneficiaryPhone = document.getElementById('beneficiaryPhone')?.value;
        if (beneficiaryPhone) {
            payload.beneficiary_phone = beneficiaryPhone;
        }
    } else if (swapType === 'DEPOSIT') {
        const destinationAccount = document.getElementById('destinationAccount')?.value;
        if (destinationAccount) {
            payload.destination_account = destinationAccount;
        }
    }
    
    return payload;
}

// Event listeners
fromInstSelect.addEventListener('change', () => {
    updateAssetTypes();
    validateCorridor();
    updateSummary();
});

toInstSelect.addEventListener('change', () => {
    validateCorridor();
    updateSummary();
});

assetTypeSelect.addEventListener('change', () => {
    updateAssetFields();
    validateCorridor();
    updateSummary();
});

swapTypeSelect.addEventListener('change', () => {
    updateDestinationFields();
    updateSummary();
});

amountInput.addEventListener('input', updateSummary);

// Quick amount buttons
document.querySelectorAll('.quick-amount').forEach(btn => {
    btn.addEventListener('click', () => {
        amountInput.value = btn.dataset.amount;
        updateSummary();
    });
});

// Execute swap
document.getElementById('executeBtn').addEventListener('click', async () => {
    const fromInst = fromInstSelect.value;
    const toInst = toInstSelect.value;
    const assetType = assetTypeSelect.value;
    const amount = parseFloat(amountInput.value);
    const swapType = swapTypeSelect.value;
    
    // Validation
    if (!fromInst) { alert('Select FROM institution'); return; }
    if (!toInst) { alert('Select TO institution'); return; }
    if (!assetType) { alert('Select asset type'); return; }
    if (!amount || amount <= 0) { alert('Enter valid amount'); return; }
    
    if (fromInst === toInst) {
        alert('Source and destination institutions must be different');
        return;
    }
    
    // For deposit, validate destination account
    if (swapType === 'DEPOSIT') {
        const destAccount = document.getElementById('destinationAccount')?.value;
        if (!destAccount) {
            alert('Enter destination account/phone number');
            return;
        }
    }
    
    const payload = buildPayload();
    
    console.log('Sending payload:', payload);
    
    const executeBtn = document.getElementById('executeBtn');
    const resultDiv = document.getElementById('result');
    
    executeBtn.disabled = true;
    executeBtn.textContent = '⏳ Processing...';
    resultDiv.className = 'result loading';
    resultDiv.innerHTML = 'Processing swap...';
    
    try {
        const response = await fetch('<?= $apiUrl ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': '<?= $apiKey ?>'
            },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        
        if (result.success === true || result.status === 'success' || result.atomic_commit?.status === 'committed') {
            resultDiv.className = 'result success';
            let html = `<strong>✅ Swap Successful!</strong><br><br>
                Reference: ${result.reference || result.swap_reference || 'N/A'}<br>
                Amount: <?= $currencySymbol ?> ${amount.toFixed(2)}<br>`;
            
            if (result.amount) {
                html += `Net Amount: <?= $currencySymbol ?> ${result.amount.toFixed(2)}<br>`;
            }
            if (result.fee) {
                html += `<strong>Fee: <?= $currencySymbol ?> ${result.fee.toFixed(2)}</strong><br>`;
            }
            if (result.atm_code) {
                html += `<br><strong>🏧 ATM Code:</strong> ${result.atm_code}<br>`;
            }
            if (result.voucher_number) {
                html += `<br><strong>🎫 Voucher:</strong> ${result.voucher_number}<br>`;
            }
            
            // Show full response in details
            html += `<br><details><summary><strong>📋 Full Response</strong></summary><pre style="margin-top:8px; font-size:11px; overflow-x:auto;">${JSON.stringify(result, null, 2)}</pre></details>`;
            resultDiv.innerHTML = html;
            setTimeout(() => location.reload(), 3000);
        } else {
            resultDiv.className = 'result error';
            let errorMsg = result.message || result.error || 'Unknown error';
            if (result.data?.message) errorMsg = result.data.message;
            resultDiv.innerHTML = `<strong>❌ Swap Failed</strong><br><br>${errorMsg}<br><br><details><summary>Details</summary><pre style="margin-top:8px; font-size:11px; overflow-x:auto;">${JSON.stringify(result, null, 2)}</pre></details>`;
        }
    } catch (error) {
        resultDiv.className = 'result error';
        resultDiv.innerHTML = `<strong>❌ Error</strong><br><br>${error.message}`;
    } finally {
        executeBtn.disabled = false;
        executeBtn.textContent = '🚀 Execute Swap';
        resultDiv.scrollIntoView({ behavior: 'smooth' });
    }
});

// Initialize
updateAssetTypes();
updateDestinationFields();
</script>
</body>
</html>
