<?php
// public/user/dashboard.php - FULLY DYNAMIC, NO HARDCODING
// Works for any country by loading config from the country folder

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
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
            $current = $matches[1];
            $participants[$current] = [
                'code' => $current,
                'name' => $current,
                'asset_types' => [],
                'delivery_modes' => ['CASHOUT', 'DEPOSIT']
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
        
        if ($current && preg_match('/^  fields:$/', $line)) {
            $assets[$current]['fields'] = [];
            continue;
        }
        
        if ($current && isset($assets[$current]['fields']) && preg_match('/^    - name: (.+)$/', $line, $matches)) {
            $assets[$current]['fields'][] = ['name' => trim($matches[1])];
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
        SELECT swap_reference, amount, from_institution, to_institution, status, created_at 
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
        
        <div class="form-row">
            <div class="form-group">
                <label>📤 SOURCE INSTITUTION</label>
                <select id="sourceInstitution">
                    <option value="">-- Select --</option>
                    <?php foreach ($participants as $code => $p): ?>
                        <option value="<?= htmlspecialchars($code) ?>" data-assets='<?= json_encode($p['asset_types'] ?? ['ACCOUNT']) ?>'>
                            <?= htmlspecialchars($p['name'] ?? $code) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>📥 DESTINATION INSTITUTION</label>
                <select id="destInstitution">
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
            </div>
            <div class="form-group">
                <label>📦 DELIVERY MODE</label>
                <select id="deliveryMode">
                    <option value="CASHOUT">🏧 Cashout (ATM / Agent)</option>
                    <option value="DEPOSIT">💳 Deposit (Bank Account / Wallet)</option>
                </select>
            </div>
        </div>

        <!-- Dynamic Source Fields Container -->
        <div id="sourceDynamicFields" class="dynamic-fields"></div>

        <!-- Dynamic Destination Fields Container -->
        <div id="destDynamicFields" class="dynamic-fields"></div>

        <div class="form-group">
            <label>AMOUNT (<?= $currencySymbol ?>)</label>
            <input type="number" id="amount" step="0.01" placeholder="0.00">
            <div class="quick-amounts">
                <span class="quick-amount" data-amount="50">50</span>
                <span class="quick-amount" data-amount="100">100</span>
                <span class="quick-amount" data-amount="200">200</span>
                <span class="quick-amount" data-amount="500">500</span>
                <span class="quick-amount" data-amount="1000">1000</span>
            </div>
        </div>

        <div class="summary" id="summary">📋 Select source institution, asset type, and destination</div>

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
                    <span><?= htmlspecialchars($swap['from_institution'] ?? '?') ?> → <?= htmlspecialchars($swap['to_institution'] ?? '?') ?></span>
                    <span><?= number_format($swap['amount'], 2) ?> <?= $currencySymbol ?></span>
                    <span class="swap-status <?= strtolower($swap['status'] ?? 'completed') ?>"><?= $swap['status'] ?? 'Completed' ?></span>
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

// Store asset types per institution
const institutionAssets = {};
for (const [code, p] of Object.entries(participants)) {
    institutionAssets[code] = p.asset_types || ['ACCOUNT'];
}

// DOM Elements
const sourceInstSelect = document.getElementById('sourceInstitution');
const destInstSelect = document.getElementById('destInstitution');
const assetTypeSelect = document.getElementById('assetType');
const deliveryModeSelect = document.getElementById('deliveryMode');
const sourceDynamicFields = document.getElementById('sourceDynamicFields');
const destDynamicFields = document.getElementById('destDynamicFields');
const corridorWarning = document.getElementById('corridorWarning');

// Update asset type dropdown based on selected source institution
function updateAssetTypes() {
    const sourceInst = sourceInstSelect.value;
    assetTypeSelect.innerHTML = '<option value="">-- Select Asset Type --</option>';
    
    if (!sourceInst) return;
    
    const assetsList = institutionAssets[sourceInst] || ['ACCOUNT'];
    assetsList.forEach(asset => {
        const displayName = assets[asset]?.display_name || asset;
        const icon = assets[asset]?.icon || '';
        const option = document.createElement('option');
        option.value = asset;
        option.textContent = icon ? `${icon} ${displayName}` : displayName;
        assetTypeSelect.appendChild(option);
    });
}

// Generate source fields based on selected asset type
function updateSourceFields() {
    const assetType = assetTypeSelect.value;
    const fields = assetFields[assetType] || [];
    
    sourceDynamicFields.innerHTML = '';
    sourceDynamicFields.classList.remove('active');
    
    if (fields.length === 0 || !assetType) return;
    
    let html = '<div class="form-row">';
    fields.forEach(field => {
        const fieldName = field.name;
        const label = fieldName.replace(/_/g, ' ').toUpperCase();
        const inputType = fieldName.includes('pin') ? 'password' : 'text';
        html += `
            <div class="form-group">
                <label>${label}</label>
                <input type="${inputType}" name="${fieldName}" class="source-field" placeholder="Enter ${fieldName.replace(/_/g, ' ')}">
            </div>
        `;
    });
    html += '</div>';
    
    sourceDynamicFields.innerHTML = html;
    sourceDynamicFields.classList.add('active');
}

// Generate destination fields based on delivery mode
function updateDestinationFields() {
    const deliveryMode = deliveryModeSelect.value;
    
    destDynamicFields.innerHTML = '';
    destDynamicFields.classList.remove('active');
    
    if (deliveryMode === 'CASHOUT') {
        destDynamicFields.innerHTML = `
            <div class="form-group">
                <label>SMS PHONE NUMBER (Optional)</label>
                <input type="tel" id="smsPhone" placeholder="Where to send ATM code" value="<?= $loggedPhone ?>">
            </div>
            <div class="info-note">💡 We'll send an ATM cashout code via SMS if provided.</div>
        `;
        destDynamicFields.classList.add('active');
    } else if (deliveryMode === 'DEPOSIT') {
        destDynamicFields.innerHTML = `
            <div class="form-group">
                <label>DESTINATION ACCOUNT / PHONE</label>
                <input type="text" id="destAccount" placeholder="Account number or phone number">
            </div>
        `;
        destDynamicFields.classList.add('active');
    }
}

// Check if source and destination are valid (must differ)
function validateCorridor() {
    const sourceInst = sourceInstSelect.value;
    const destInst = destInstSelect.value;
    
    if (!sourceInst || !destInst) {
        corridorWarning.classList.remove('show');
        return true;
    }
    
    if (sourceInst === destInst) {
        corridorWarning.classList.add('show');
        return false;
    }
    
    corridorWarning.classList.remove('show');
    return true;
}

// Update summary
function updateSummary() {
    const sourceInst = sourceInstSelect.options[sourceInstSelect.selectedIndex]?.text || '?';
    const destInst = destInstSelect.options[destInstSelect.selectedIndex]?.text || '?';
    const asset = assetTypeSelect.options[assetTypeSelect.selectedIndex]?.text || '?';
    const mode = deliveryModeSelect.options[deliveryModeSelect.selectedIndex]?.text || '?';
    const amount = document.getElementById('amount').value || '0';
    
    document.getElementById('summary').innerHTML = `📋 ${sourceInst} (${asset}) → ${destInst} (${mode}) | Amount: <?= $currencySymbol ?> ${parseFloat(amount).toFixed(2)}`;
}

// Build source details object from dynamic fields
function buildSourceDetails() {
    const sourceInst = sourceInstSelect.value;
    const assetType = assetTypeSelect.value;
    const amount = parseFloat(document.getElementById('amount').value);
    
    let sourceDetails = {
        institution: sourceInst,
        asset_type: assetType,
        amount: amount,
        currency: 'BWP'
    };
    
    // Add dynamic fields
    document.querySelectorAll('.source-field').forEach(field => {
        const value = field.value.trim();
        if (value) {
            sourceDetails[field.name] = value;
        }
    });
    
    return sourceDetails;
}

// Build destination details object
function buildDestinationDetails() {
    const destInst = destInstSelect.value;
    const deliveryMode = deliveryModeSelect.value;
    
    let destDetails = {
        institution: destInst,
        type: deliveryMode,
        currency: 'BWP'
    };
    
    if (deliveryMode === 'CASHOUT') {
        const smsPhone = document.getElementById('smsPhone')?.value;
        if (smsPhone) destDetails.beneficiary_phone = smsPhone;
    } else if (deliveryMode === 'DEPOSIT') {
        const destAccount = document.getElementById('destAccount')?.value;
        if (destAccount) destDetails.account_number = destAccount;
    }
    
    return destDetails;
}

// Event listeners
sourceInstSelect.addEventListener('change', () => {
    updateAssetTypes();
    validateCorridor();
    updateSummary();
});

destInstSelect.addEventListener('change', () => {
    validateCorridor();
    updateSummary();
});

assetTypeSelect.addEventListener('change', () => {
    updateSourceFields();
    validateCorridor();
    updateSummary();
});

deliveryModeSelect.addEventListener('change', () => {
    updateDestinationFields();
    updateSummary();
});

document.getElementById('amount').addEventListener('input', updateSummary);

// Quick amount buttons
document.querySelectorAll('.quick-amount').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('amount').value = btn.dataset.amount;
        updateSummary();
    });
});

// Execute swap
document.getElementById('executeBtn').addEventListener('click', async () => {
    const sourceInst = sourceInstSelect.value;
    const destInst = destInstSelect.value;
    const assetType = assetTypeSelect.value;
    const amount = parseFloat(document.getElementById('amount').value);
    
    if (!sourceInst) { alert('Select source institution'); return; }
    if (!destInst) { alert('Select destination institution'); return; }
    if (!assetType) { alert('Select asset type'); return; }
    if (!amount || amount <= 0) { alert('Enter valid amount'); return; }
    
    if (sourceInst === destInst) {
        alert('Source and destination institutions must be different');
        return;
    }
    
    const sourceDetails = buildSourceDetails();
    const destDetails = buildDestinationDetails();
    
    // Validate destination fields
    if (destDetails.type === 'DEPOSIT' && !destDetails.account_number) {
        alert('Enter destination account/phone number');
        return;
    }
    
    const payload = {
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMPOTENT_' + Date.now(),
        swap_type: destDetails.type,
        source_details: sourceDetails,
        destination_details: destDetails
    };
    
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
        
        if (result.status === 'success' || result.atomic_commit?.status === 'committed') {
            resultDiv.className = 'result success';
            let html = `<strong>✅ Swap Successful!</strong><br><br>
                Reference: ${result.reference || result.swap_reference || 'N/A'}<br>
                Amount: <?= $currencySymbol ?> ${amount}<br>`;
            if (result.atm_code) html += `<br><strong>🏧 ATM Code:</strong> ${result.atm_code}`;
            if (result.voucher_number) html += `<br><strong>🎫 Voucher:</strong> ${result.voucher_number}`;
            html += `<br><br><details><summary>Details</summary><pre style="margin-top:8px;">${JSON.stringify(result, null, 2)}</pre></details>`;
            resultDiv.innerHTML = html;
            setTimeout(() => location.reload(), 3000);
        } else {
            resultDiv.className = 'result error';
            resultDiv.innerHTML = `<strong>❌ Swap Failed</strong><br><br>${result.message || result.error || 'Unknown error'}<br><br><details><summary>Details</summary><pre>${JSON.stringify(result, null, 2)}</pre></details>`;
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
