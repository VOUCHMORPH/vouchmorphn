<?php
// public/user/user_dashboard.php
ob_start();

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$userCountry = $user['country'] ?? 'Botswana';

$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    die("System error");
}

// ============================================================
// DYNAMICALLY LOAD ALL COUNTRY FOLDERS
// ============================================================
function getAllCountryFolders() {
    $basePath = __DIR__ . "/../../src/Core/Config/Countries/";
    $folders = [];
    if (is_dir($basePath)) {
        foreach (scandir($basePath) as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($basePath . $item)) {
                $folders[] = $item;
            }
        }
    }
    return $folders;
}

function loadCountryParticipants($countryName) {
    $path = __DIR__ . "/../../src/Core/Config/Countries/{$countryName}/participants.json";
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    $participants = $data['participants'] ?? [];
    
    // Add country info
    foreach ($participants as $code => &$p) {
        $p['country'] = $countryName;
    }
    return $participants;
}

// Load ALL participants from ALL country folders
$allCountryFolders = getAllCountryFolders();
$allParticipants = [];
$availableCountries = [];

foreach ($allCountryFolders as $countryFolder) {
    $participants = loadCountryParticipants($countryFolder);
    foreach ($participants as $code => $p) {
        $allParticipants[$code] = $p;
    }
    $availableCountries[] = $countryFolder;
}

// Load user's saved sources
$stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE'");
$stmt->execute([':user_id' => $userId]);
$fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getAssetTypes($participant) {
    return $participant['capabilities']['asset_types'] ?? [];
}

function getAssetIcon($type) {
    $icons = ['ACCOUNT' => '🏦', 'VOUCHER' => '🎫', 'ATM' => '🏧', 'E-WALLET' => '📱', 'CARD' => '💳'];
    return $icons[$type] ?? '💰';
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_source') {
        try {
            $institutionCode = trim($_POST['institution_code'] ?? '');
            $assetType = strtoupper(trim($_POST['asset_type'] ?? ''));
            $identifier = trim($_POST['identifier'] ?? '');
            $pin = trim($_POST['pin'] ?? '');
            
            $participant = $allParticipants[$institutionCode] ?? null;
            if (!$participant) throw new Exception("Institution not found");
            
            $identifierHash = hash('sha256', $identifier . $pin);
            $maskedId = strlen($identifier) > 4 ? '••••' . substr($identifier, -4) : '••••';
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $encrypted = base64_encode(openssl_encrypt($identifier . '|' . $pin, 'AES-256-CBC', $encryptionKey, 0, substr($encryptionKey, 0, 16)));
            
            $stmt = $db->prepare("
                INSERT INTO user_funding_sources 
                (user_id, institution_code, institution_name, institution_country, source_type, 
                 masked_identifier, encrypted_identifier, identifier_hash, linked_phone)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $institutionCode, $participant['name'], $participant['country'],
                $assetType, $maskedId, $encrypted, $identifierHash, $userPhone
            ]);
            
            echo json_encode(['status' => 'success', 'message' => 'Source saved!']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    if ($action === 'get_institutions_by_country') {
        $country = $_GET['country'] ?? '';
        $instList = [];
        foreach ($allParticipants as $code => $p) {
            if ($p['country'] === $country && ($p['status'] ?? 'ACTIVE') === 'ACTIVE') {
                $instList[] = [
                    'code' => $code,
                    'name' => $p['name'],
                    'type' => $p['type'],
                    'category' => $p['category'],
                    'asset_types' => getAssetTypes($p)
                ];
            }
        }
        echo json_encode(['success' => true, 'institutions' => $instList]);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph | Swap Money</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: #0a0a0f;
        font-family: 'Inter', sans-serif;
        color: #ffffff;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }
    .ussd-container {
        max-width: 480px;
        width: 100%;
        background: #0a0a0f;
        border: 1px solid #222;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        overflow: hidden;
    }
    .ussd-header {
        background: #00F0FF;
        padding: 16px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .ussd-header h1 { font-size: 16px; font-weight: 700; color: #0a0a0f; }
    .ussd-header .balance { font-size: 11px; background: rgba(10,10,15,0.2); padding: 4px 8px; color: #0a0a0f; }
    .ussd-screen { min-height: 500px; padding: 24px 20px; background: #0f0f15; border-bottom: 1px solid #222; overflow-y: auto; max-height: 550px; }
    .question { font-size: 18px; font-weight: 600; line-height: 1.4; margin-bottom: 20px; }
    .options { display: flex; flex-direction: column; gap: 8px; }
    .option-btn {
        background: transparent; border: 1px solid #333; padding: 14px 16px;
        text-align: left; color: #ffffff; font-size: 14px; font-weight: 500;
        cursor: pointer; transition: all 0.2s; display: flex; justify-content: space-between; align-items: center;
    }
    .option-btn:hover { border-color: #00F0FF; background: rgba(0,240,255,0.05); }
    .back-btn {
        margin-top: 20px; background: transparent; border: none; color: #666;
        font-size: 13px; cursor: pointer; padding: 10px; text-align: center; width: 100%;
    }
    .back-btn:hover { color: #00F0FF; }
    .input-group { margin-top: 8px; }
    .ussd-input, .ussd-select {
        width: 100%; padding: 14px 16px; background: #050505; border: 1px solid #333;
        color: #00F0FF; font-size: 15px; margin-bottom: 12px;
    }
    .ussd-select { color: #fff; font-family: 'Inter'; }
    .ussd-input:focus, .ussd-select:focus { outline: none; border-color: #00F0FF; }
    .submit-btn {
        width: 100%; margin-top: 8px; padding: 14px; background: #00F0FF;
        border: none; color: #0a0a0f; font-weight: 700; font-size: 14px; cursor: pointer;
    }
    .keypad-hint { margin-top: 16px; font-size: 11px; color: #444; text-align: center; }
    .ussd-footer { padding: 12px 20px; background: #050505; border-top: 1px solid #222; font-size: 10px; color: #444; display: flex; justify-content: space-between; }
    .loading { text-align: center; padding: 40px 20px; }
    .spinner { width: 32px; height: 32px; border: 2px solid #333; border-top-color: #00F0FF; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 12px; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .result-screen { text-align: center; }
    .result-icon { font-size: 48px; margin-bottom: 16px; }
    .result-icon.success { color: #00F0FF; }
    .result-icon.error { color: #FF3030; }
    .result-message { font-size: 14px; color: #888; margin-top: 8px; }
    .done-btn { margin-top: 24px; padding: 14px; background: #00F0FF; border: none; color: #0a0a0f; font-weight: 700; cursor: pointer; width: 100%; }
    .info-text { font-size: 11px; color: #00F0FF; margin-top: 12px; text-align: center; }
    .multi-item { background: #050505; border: 1px solid #333; padding: 12px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
    .remove-btn { background: none; border: none; color: #FF3030; cursor: pointer; font-size: 16px; }
    .add-btn { background: transparent; border: 1px dashed #333; padding: 12px; text-align: center; cursor: pointer; margin-top: 8px; }
    .add-btn:hover { border-color: #00F0FF; color: #00F0FF; }
    hr { border-color: #222; margin: 12px 0; }
</style>
</head>
<body>

<div class="ussd-container">
    <div class="ussd-header">
        <h1><i class="fas fa-exchange-alt"></i> VOUCHMORPH</h1>
        <div class="balance"><i class="fas fa-user"></i> <?= htmlspecialchars(substr($userPhone, -6)) ?></div>
    </div>
    
    <div id="screen" class="ussd-screen">
        <div class="loading"><div class="spinner"></div><div>Loading...</div></div>
    </div>
    
    <div class="ussd-footer">
        <span>Swap Money • Anywhere</span>
        <span><i class="fas fa-shield-alt"></i> Secured</span>
    </div>
</div>

<script>
// Data from PHP - NO HARDCODED COUNTRIES
const allParticipants = <?php 
    $list = [];
    foreach ($allParticipants as $code => $p) {
        $list[] = [
            'code' => $code, 'name' => $p['name'], 'type' => $p['type'],
            'category' => $p['category'], 'country' => $p['country'],
            'status' => $p['status'] ?? 'ACTIVE',
            'asset_types' => $p['capabilities']['asset_types'] ?? []
        ];
    }
    echo json_encode($list);
?>;

const fundingSources = <?php 
    $sources = [];
    foreach ($fundingSources as $fs) {
        $sources[] = [
            'id' => $fs['source_id'], 'name' => $fs['institution_name'],
            'code' => $fs['institution_code'], 'type' => $fs['source_type'],
            'masked' => $fs['masked_identifier'], 'country' => $fs['institution_country']
        ];
    }
    echo json_encode($sources);
?>;

const availableCountries = <?php echo json_encode($availableCountries); ?>;
const userCountry = "<?= $userCountry ?>";

// Helper functions
function getAssetIcon(type) {
    const icons = {'ACCOUNT':'🏦','VOUCHER':'🎫','ATM':'🏧','E-WALLET':'📱','CARD':'💳'};
    return icons[type] || '💰';
}

function getParticipantsByCountry(country) {
    return allParticipants.filter(p => p.country === country && p.status === 'ACTIVE');
}

function getInstitutionsByCountry(country, callback) {
    fetch(`${window.location.href}?action=get_institutions_by_country&country=${encodeURIComponent(country)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => callback(data.institutions || []))
    .catch(() => callback([]));
}

// State
let state = {
    step: 'init',
    swapMode: 'single',
    sources: [],      // for multi-source: array of {institution, asset_type, identifier, pin, amount}
    destinations: [], // for multi-dest: array of {institution, action, value, amount}
    tempSource: {},
    tempDest: {},
    currentCountryList: []
};

function render() {
    const screen = document.getElementById('screen');
    let html = '';
    
    switch(state.step) {
        case 'init':
            html = `
                <div class="question">🔄 Choose Swap Type</div>
                <div class="options">
                    <button class="option-btn" onclick="startSwap('single')">
                        <span>➡️ 1 Source → 1 Destination</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <button class="option-btn" onclick="startSwap('multi_source')">
                        <span>🔄 Multiple Sources → 1 Destination</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <button class="option-btn" onclick="startSwap('multi_dest')">
                        <span>🔄 1 Source → Multiple Destinations</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <button class="option-btn" onclick="goTo('saved_sources')">
                        <span>🔗 My Saved Sources</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <button class="option-btn" onclick="goTo('link_source')">
                        <span>➕ Link New Source</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            `;
            break;
            
        // ========== SAVED SOURCES ==========
        case 'saved_sources':
            if (fundingSources.length === 0) {
                html = `<div class="question">No saved sources</div><button class="back-btn" onclick="goTo('init')">← Back</button>`;
            } else {
                let opts = '';
                fundingSources.forEach(s => {
                    opts += `<button class="option-btn" onclick="useSavedSource(${s.id}, '${s.code}', '${s.type}')">
                        <span>${getAssetIcon(s.type)} ${s.name} (${s.masked})</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>`;
                });
                opts += `<button class="back-btn" onclick="goTo('init')">← Back</button>`;
                html = `<div class="question">🔗 Saved Sources</div><div class="options">${opts}</div>`;
            }
            break;
            
        // ========== LINK NEW SOURCE ==========
        case 'link_source':
            let linkHtml = '<div class="question">➕ Link New Source</div><div class="options">';
            const sourceCountries = [...new Set(allParticipants.map(p => p.country))];
            sourceCountries.forEach(country => {
                const participantsInCountry = getParticipantsByCountry(country);
                participantsInCountry.forEach(inst => {
                    linkHtml += `<button class="option-btn" onclick="showLinkForm('${inst.code}', '${inst.name}', ${JSON.stringify(inst.asset_types)})">
                        <span>🏛️ ${inst.name} (${country})</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>`;
                });
            });
            linkHtml += `<button class="back-btn" onclick="goTo('init')">← Back</button></div>`;
            html = linkHtml;
            break;
            
        case 'link_form':
            html = `
                <div class="question">➕ Link ${state.linkInstName}</div>
                <div class="input-group">
                    <select id="linkAssetType" class="ussd-select">
                        <option value="">Select asset type</option>
                        ${state.linkAssetTypes.map(t => `<option value="${t}">${getAssetIcon(t)} ${t}</option>`).join('')}
                    </select>
                    <input type="text" id="linkIdentifier" class="ussd-input" placeholder="Account/Phone/Card number">
                    <input type="password" id="linkPin" class="ussd-input" placeholder="PIN (if any)">
                    <button class="submit-btn" onclick="submitLinkSource()">Link Source</button>
                </div>
                <button class="back-btn" onclick="goTo('link_source')">← Back</button>
            `;
            break;
            
        // ========== SINGLE SOURCE MODE ==========
        case 'single_select_country':
            let countryHtml = '<div class="question">Select Source Country</div><div class="options">';
            const uniqueCountries = [...new Set(allParticipants.map(p => p.country))];
            uniqueCountries.forEach(country => {
                countryHtml += `<button class="option-btn" onclick="singleSelectCountry('${country}')">
                    <span>${country}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            countryHtml += `<button class="back-btn" onclick="goTo('init')">← Back</button></div>`;
            html = countryHtml;
            break;
            
        case 'single_select_institution':
            let instHtml = '<div class="question">Select Source Institution</div><div class="options">';
            const insts = getParticipantsByCountry(state.tempSource.country);
            insts.forEach(inst => {
                instHtml += `<button class="option-btn" onclick="singleSelectInstitution('${inst.code}')">
                    <span>🏛️ ${inst.name}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            instHtml += `<button class="back-btn" onclick="goTo('single_select_country')">← Back</button></div>`;
            html = instHtml;
            break;
            
        case 'single_select_asset':
            const sInst = allParticipants.find(p => p.code === state.tempSource.code);
            let assetHtml = `<div class="question">Select Asset Type at ${sInst?.name}</div><div class="options">`;
            (sInst?.asset_types || []).forEach(asset => {
                assetHtml += `<button class="option-btn" onclick="singleSelectAsset('${asset}')">
                    <span>${getAssetIcon(asset)} ${asset}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            assetHtml += `<button class="back-btn" onclick="goTo('single_select_institution')">← Back</button></div>`;
            html = assetHtml;
            break;
            
        case 'single_source_form':
            let formHtml = '';
            const sAsset = state.tempSource.asset_type;
            if (sAsset === 'VOUCHER') {
                formHtml = `<input type="text" id="voucherNumber" class="ussd-input" placeholder="Voucher number">
                           <input type="password" id="voucherPin" class="ussd-input" placeholder="PIN">`;
            } else if (sAsset === 'ACCOUNT') {
                formHtml = `<input type="text" id="accountNumber" class="ussd-input" placeholder="Account number">
                           <input type="password" id="accountPin" class="ussd-input" placeholder="PIN">`;
            } else if (sAsset === 'CARD') {
                formHtml = `<input type="text" id="cardNumber" class="ussd-input" placeholder="Card number">
                           <input type="password" id="cardPin" class="ussd-input" placeholder="PIN">`;
            } else {
                formHtml = `<input type="tel" id="walletPhone" class="ussd-input" placeholder="Phone number">
                           <input type="password" id="walletPin" class="ussd-input" placeholder="PIN">`;
            }
            html = `<div class="question">Enter ${sAsset} Details</div>${formHtml}<button class="submit-btn" onclick="submitSingleSourceForm()">Continue</button>
                    <button class="back-btn" onclick="goTo('single_select_asset')">← Back</button>`;
            break;
            
        case 'single_amount':
            html = `<div class="question">💰 Enter Amount</div>
                    <input type="number" id="amountInput" class="ussd-input" placeholder="0.00" step="0.01" min="10">
                    <button class="submit-btn" onclick="submitSingleAmount()">Continue</button>
                    <button class="back-btn" onclick="goBackToSource()">← Back</button>`;
            break;
            
        case 'single_dest_country':
            let destCountryHtml = '<div class="question">Select Destination Country</div><div class="options">';
            const allCountries = [...new Set(allParticipants.map(p => p.country))];
            allCountries.forEach(country => {
                destCountryHtml += `<button class="option-btn" onclick="singleDestCountry('${country}')">
                    <span>${country}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            destCountryHtml += `<button class="back-btn" onclick="goTo('single_amount')">← Back</button></div>`;
            html = destCountryHtml;
            break;
            
        case 'single_dest_institution':
            html = `<div class="loading"><div class="spinner"></div><div>Loading...</div></div>`;
            getInstitutionsByCountry(state.tempDest.country, (insts) => {
                state.destInstitutions = insts;
                goTo('single_dest_institution_list');
            });
            return;
            
        case 'single_dest_institution_list':
            let destInstHtml = `<div class="question">Select Institution in ${state.tempDest.country}</div><div class="options">`;
            (state.destInstitutions || []).forEach(inst => {
                destInstHtml += `<button class="option-btn" onclick="singleDestInstitution('${inst.code}')">
                    <span>🏛️ ${inst.name}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            destInstHtml += `<button class="back-btn" onclick="goTo('single_dest_country')">← Back</button></div>`;
            html = destInstHtml;
            break;
            
        case 'single_dest_action':
            html = `<div class="question">📥 Choose Action</div><div class="options">
                    <button class="option-btn" onclick="singleDestAction('deposit')">🏦 Deposit to Account/Wallet</button>
                    <button class="option-btn" onclick="singleDestAction('cashout')">💰 Cashout (ATM/Agent)</button>
                    </div>
                    <button class="back-btn" onclick="goTo('single_dest_institution_list')">← Back</button>`;
            break;
            
        case 'single_dest_details':
            let detailHtml = '';
            if (state.tempDest.action === 'cashout') {
                detailHtml = `<div class="question">💰 Cashout Details</div>
                    <input type="tel" id="beneficiaryPhone" class="ussd-input" placeholder="Beneficiary phone number">
                    <select id="payoutMethod" class="ussd-select">
                        <option value="atm">🏧 ATM Withdrawal</option>
                        <option value="agent">🏪 Agent Cashout</option>
                    </select>
                    <button class="submit-btn" onclick="submitSingleDestDetails()">Continue</button>`;
            } else {
                detailHtml = `<div class="question">🏦 Destination Details</div>
                    <input type="text" id="destAccount" class="ussd-input" placeholder="Account number or phone number">
                    <button class="submit-btn" onclick="submitSingleDestDetails()">Continue</button>`;
            }
            detailHtml += `<button class="back-btn" onclick="goTo('single_dest_action')">← Back</button>`;
            html = detailHtml;
            break;
            
        case 'single_confirm':
            const srcInst = allParticipants.find(p => p.code === state.tempSource.code);
            const dstInst = allParticipants.find(p => p.code === state.tempDest.institution);
            html = `<div class="question">📋 Confirm Swap</div>
                <div class="options">
                    <button class="option-btn" style="justify-content:space-between"><span>From</span><span>${srcInst?.name} • ${state.tempSource.asset_type}</span></button>
                    <button class="option-btn" style="justify-content:space-between"><span>Amount</span><span>${parseFloat(state.tempSource.amount).toFixed(2)}</span></button>
                    <button class="option-btn" style="justify-content:space-between"><span>To</span><span>${dstInst?.name}</span></button>
                    <button class="option-btn" style="justify-content:space-between"><span>Destination</span><span>${state.tempDest.action === 'cashout' ? '💰 Cashout to ' + state.tempDest.value : '🏦 ' + state.tempDest.value}</span></button>
                </div>
                <div style="display:flex; gap:12px; margin-top:20px;">
                    <button class="submit-btn" style="flex:1;" onclick="executeSingleSwap(false)">✅ Swap</button>
                    <button class="submit-btn" style="flex:1; background:#333;" onclick="executeSingleSwap(true)">💾 Swap & Save</button>
                </div>
                <button class="back-btn" onclick="goTo('single_dest_details')">← Edit</button>`;
            break;
            
        // ========== MULTI-SOURCE MODE (Multiple Sources → 1 Destination) ==========
        case 'multi_source_list':
            let msHtml = '<div class="question">📋 Sources to Combine</div>';
            if (state.sources.length > 0) {
                let total = 0;
                state.sources.forEach((src, idx) => {
                    total += src.amount;
                    msHtml += `<div class="multi-item">
                        <span>${getAssetIcon(src.asset_type)} ${src.institution_name} • ${src.asset_type} • ${src.amount}</span>
                        <button class="remove-btn" onclick="removeSource(${idx})"><i class="fas fa-trash"></i></button>
                    </div>`;
                });
                msHtml += `<div class="info-text">Total: ${total}</div>`;
            } else {
                msHtml += '<div class="info-text">No sources added</div>';
            }
            msHtml += `<button class="add-btn" onclick="addMultiSource()">+ Add Source</button>`;
            if (state.sources.length > 0) {
                msHtml += `<button class="submit-btn" style="margin-top:12px;" onclick="goToMultiDest()">Continue to Destination →</button>`;
            }
            msHtml += `<button class="back-btn" onclick="goTo('init')">← Back</button>`;
            html = msHtml;
            break;
            
        case 'multi_source_add':
            html = `<div class="question">Add Source</div>
                <div class="options">
                    <button class="option-btn" onclick="useSavedForMulti()">🔗 Use Saved Source</button>
                    <button class="option-btn" onclick="manualForMulti()">📝 Enter Manually</button>
                    <button class="back-btn" onclick="goTo('multi_source_list')">← Back</button>
                </div>`;
            break;
            
        case 'multi_source_select_country':
            let msCountryHtml = '<div class="question">Select Source Country</div><div class="options">';
            const msCountries = [...new Set(allParticipants.map(p => p.country))];
            msCountries.forEach(c => {
                msCountryHtml += `<button class="option-btn" onclick="multiSourceCountry('${c}')">
                    <span>${c}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            msCountryHtml += `<button class="back-btn" onclick="goTo('multi_source_add')">← Back</button></div>`;
            html = msCountryHtml;
            break;
            
        case 'multi_source_select_institution':
            let msInstHtml = '<div class="question">Select Institution</div><div class="options">';
            const msInsts = getParticipantsByCountry(state.tempSource.country);
            msInsts.forEach(inst => {
                msInstHtml += `<button class="option-btn" onclick="multiSourceInstitution('${inst.code}')">
                    <span>🏛️ ${inst.name}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            msInstHtml += `<button class="back-btn" onclick="goTo('multi_source_select_country')">← Back</button></div>`;
            html = msInstHtml;
            break;
            
        case 'multi_source_select_asset':
            const msInstObj = allParticipants.find(p => p.code === state.tempSource.code);
            let msAssetHtml = `<div class="question">Select Asset Type</div><div class="options">`;
            (msInstObj?.asset_types || []).forEach(asset => {
                msAssetHtml += `<button class="option-btn" onclick="multiSourceAsset('${asset}')">
                    <span>${getAssetIcon(asset)} ${asset}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            msAssetHtml += `<button class="back-btn" onclick="goTo('multi_source_select_institution')">← Back</button></div>`;
            html = msAssetHtml;
            break;
            
        case 'multi_source_form':
            let msFormHtml = '';
            const msAsset = state.tempSource.asset_type;
            if (msAsset === 'VOUCHER') {
                msFormHtml = `<input type="text" id="msVoucherNumber" class="ussd-input" placeholder="Voucher number">
                             <input type="password" id="msVoucherPin" class="ussd-input" placeholder="PIN">`;
            } else if (msAsset === 'ACCOUNT') {
                msFormHtml = `<input type="text" id="msAccountNumber" class="ussd-input" placeholder="Account number">
                             <input type="password" id="msAccountPin" class="ussd-input" placeholder="PIN">`;
            } else if (msAsset === 'CARD') {
                msFormHtml = `<input type="text" id="msCardNumber" class="ussd-input" placeholder="Card number">
                             <input type="password" id="msCardPin" class="ussd-input" placeholder="PIN">`;
            } else {
                msFormHtml = `<input type="tel" id="msWalletPhone" class="ussd-input" placeholder="Phone number">
                             <input type="password" id="msWalletPin" class="ussd-input" placeholder="PIN">`;
            }
            html = `<div class="question">Enter ${msAsset} Details</div>${msFormHtml}
                    <input type="number" id="msAmount" class="ussd-input" placeholder="Amount" step="0.01" min="10">
                    <button class="submit-btn" onclick="submitMultiSource()">Add Source</button>
                    <button class="back-btn" onclick="goTo('multi_source_select_asset')">← Back</button>`;
            break;
            
        case 'multi_dest_country':
            let mdCountryHtml = '<div class="question">Select Destination Country</div><div class="options">';
            const mdCountries = [...new Set(allParticipants.map(p => p.country))];
            mdCountries.forEach(c => {
                mdCountryHtml += `<button class="option-btn" onclick="multiDestCountry('${c}')">
                    <span>${c}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            mdCountryHtml += `<button class="back-btn" onclick="goTo('multi_source_list')">← Back</button></div>`;
            html = mdCountryHtml;
            break;
            
        case 'multi_dest_institution':
            html = `<div class="loading"><div class="spinner"></div><div>Loading...</div></div>`;
            getInstitutionsByCountry(state.tempDest.country, (insts) => {
                state.destInstitutions = insts;
                goTo('multi_dest_institution_list');
            });
            return;
            
        case 'multi_dest_institution_list':
            let mdInstHtml = `<div class="question">Select Institution in ${state.tempDest.country}</div><div class="options">`;
            (state.destInstitutions || []).forEach(inst => {
                mdInstHtml += `<button class="option-btn" onclick="multiDestInstitution('${inst.code}')">
                    <span>🏛️ ${inst.name}</span>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            });
            mdInstHtml += `<button class="back-btn" onclick="goTo('multi_dest_country')">← Back</button></div>`;
            html = mdInstHtml;
            break;
            
        case 'multi_dest_action':
            html = `<div class="question">📥 Choose Action</div><div class="options">
                    <button class="option-btn" onclick="multiDestAction('deposit')">🏦 Deposit</button>
                    <button class="option-btn" onclick="multiDestAction('cashout')">💰 Cashout</button>
                    </div>
                    <button class="back-btn" onclick="goTo('multi_dest_institution_list')">← Back</button>`;
            break;
            
        case 'multi_dest_details':
            let mdDetailHtml = '';
            if (state.tempDest.action === 'cashout') {
                mdDetailHtml = `<div class="question">💰 Cashout Details</div>
                    <input type="tel" id="mdPhone" class="ussd-input" placeholder="Beneficiary phone number">
                    <input type="number" id="mdAmount" class="ussd-input" placeholder="Amount" step="0.01" min="10">
                    <button class="submit-btn" onclick="submitMultiDest()">Add Destination</button>`;
            } else {
                mdDetailHtml = `<div class="question">🏦 Deposit Details</div>
                    <input type="text" id="mdAccount" class="ussd-input" placeholder="Account number">
                    <input type="number" id="mdAmount" class="ussd-input" placeholder="Amount" step="0.01" min="10">
                    <button class="submit-btn" onclick="submitMultiDest()">Add Destination</button>`;
            }
            mdDetailHtml += `<button class="back-btn" onclick="goTo('multi_dest_action')">← Back</button>`;
            html = mdDetailHtml;
            break;
            
        case 'multi_dest_list':
            let mdListHtml = '<div class="question">📋 Destinations to Split</div>';
            let totalAmount = 0;
            if (state.destinations.length > 0) {
                state.destinations.forEach((dest, idx) => {
                    totalAmount += dest.amount;
                    mdListHtml += `<div class="multi-item">
                        <span>${dest.action === 'cashout' ? '💰' : '🏦'} ${dest.institution_name} • ${dest.amount}</span>
                        <button class="remove-btn" onclick="removeDestination(${idx})"><i class="fas fa-trash"></i></button>
                    </div>`;
                });
                mdListHtml += `<div class="info-text">Total: ${totalAmount}</div>`;
            }
            mdListHtml += `<button class="add-btn" onclick="addMultiDest()">+ Add Destination</button>`;
            if (state.destinations.length > 0 && state.sources.length === 1) {
                mdListHtml += `<button class="submit-btn" style="margin-top:12px;" onclick="executeMultiDestSwap()">Execute Split Swap →</button>`;
            }
            mdListHtml += `<button class="back-btn" onclick="goTo('init')">← Back</button>`;
            html = mdListHtml;
            break;
            
        case 'processing':
            html = `<div class="loading"><div class="spinner"></div><div>Processing...</div></div>`;
            break;
            
        case 'result':
            const isSuccess = state.result?.status === 'success';
            html = `<div class="result-screen">
                <div class="result-icon ${isSuccess ? 'success' : 'error'}">${isSuccess ? '✅' : '❌'}</div>
                <div style="font-size:18px;font-weight:700;">${isSuccess ? 'SWAP COMPLETED' : 'SWAP FAILED'}</div>
                <div class="result-message">${state.result?.message || (isSuccess ? 'Success!' : 'Failed')}</div>
                ${state.result?.swap_reference ? `<div style="font-size:11px;">Ref: ${state.result.swap_reference.substring(0,16)}...</div>` : ''}
                ${state.result?.withdrawal_code ? `<div class="info-text">💰 Code: ${state.result.withdrawal_code}</div>` : ''}
                <button class="done-btn" onclick="reset()">Done</button>
            </div>`;
            break;
    }
    
    screen.innerHTML = html;
}

// Navigation
function goTo(step) { state.step = step; render(); }
function startSwap(mode) { state.swapMode = mode; state.sources = []; state.destinations = []; goTo(mode === 'single' ? 'single_select_country' : (mode === 'multi_source' ? 'multi_source_list' : 'multi_source_list')); }

// Single source functions
function singleSelectCountry(country) { state.tempSource = { country }; goTo('single_select_institution'); }
function singleSelectInstitution(code) { state.tempSource.code = code; goTo('single_select_asset'); }
function singleSelectAsset(asset) { state.tempSource.asset_type = asset; goTo('single_source_form'); }
function submitSingleSourceForm() {
    const asset = state.tempSource.asset_type;
    state.tempSource.identifier = document.getElementById(asset === 'VOUCHER' ? 'voucherNumber' : (asset === 'ACCOUNT' ? 'accountNumber' : (asset === 'CARD' ? 'cardNumber' : 'walletPhone')))?.value;
    state.tempSource.pin = document.getElementById(asset === 'VOUCHER' ? 'voucherPin' : (asset === 'ACCOUNT' ? 'accountPin' : (asset === 'CARD' ? 'cardPin' : 'walletPin')))?.value;
    if (!state.tempSource.identifier) { alert('Enter required fields'); return; }
    goTo('single_amount');
}
function submitSingleAmount() {
    const amount = document.getElementById('amountInput')?.value;
    if (!amount || parseFloat(amount) < 10) { alert('Enter valid amount (min 10)'); return; }
    state.tempSource.amount = parseFloat(amount);
    goTo('single_dest_country');
}
function singleDestCountry(country) { state.tempDest = { country }; goTo('single_dest_institution'); }
function singleDestInstitution(code) { state.tempDest.institution = code; goTo('single_dest_action'); }
function singleDestAction(action) { state.tempDest.action = action; goTo('single_dest_details'); }
function submitSingleDestDetails() {
    if (state.tempDest.action === 'cashout') {
        state.tempDest.value = document.getElementById('beneficiaryPhone')?.value;
    } else {
        state.tempDest.value = document.getElementById('destAccount')?.value;
    }
    if (!state.tempDest.value) { alert('Enter destination details'); return; }
    goTo('single_confirm');
}
async function executeSingleSwap(saveSource) {
    goTo('processing');
    const formData = new FormData();
    formData.append('action', 'swap_single');
    formData.append('source_type', state.tempSource.asset_type);
    formData.append('source_institution', state.tempSource.code);
    formData.append('source_identifier', state.tempSource.identifier);
    formData.append('source_pin', state.tempSource.pin || '');
    formData.append('amount', state.tempSource.amount);
    formData.append('dest_institution', state.tempDest.institution);
    formData.append('dest_action', state.tempDest.action);
    formData.append('dest_value', state.tempDest.value);
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const result = await res.json();
        state.result = result;
        if (saveSource && result.status === 'success') {
            const saveForm = new FormData();
            saveForm.append('action', 'save_source');
            saveForm.append('institution_code', state.tempSource.code);
            saveForm.append('asset_type', state.tempSource.asset_type);
            saveForm.append('identifier', state.tempSource.identifier);
            saveForm.append('pin', state.tempSource.pin || '');
            await fetch(window.location.href, { method: 'POST', body: saveForm, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        }
        goTo('result');
    } catch(e) { state.result = { status: 'error', message: e.message }; goTo('result'); }
}

// Multi-source functions
function addMultiSource() { goTo('multi_source_add'); }
function useSavedForMulti() { goTo('saved_sources'); }
function manualForMulti() { state.tempSource = {}; goTo('multi_source_select_country'); }
function multiSourceCountry(country) { state.tempSource.country = country; goTo('multi_source_select_institution'); }
function multiSourceInstitution(code) { state.tempSource.code = code; goTo('multi_source_select_asset'); }
function multiSourceAsset(asset) { state.tempSource.asset_type = asset; goTo('multi_source_form'); }
function submitMultiSource() {
    const asset = state.tempSource.asset_type;
    let identifier = '';
    if (asset === 'VOUCHER') identifier = document.getElementById('msVoucherNumber')?.value;
    else if (asset === 'ACCOUNT') identifier = document.getElementById('msAccountNumber')?.value;
    else if (asset === 'CARD') identifier = document.getElementById('msCardNumber')?.value;
    else identifier = document.getElementById('msWalletPhone')?.value;
    const amount = parseFloat(document.getElementById('msAmount')?.value);
    if (!identifier || !amount || amount < 10) { alert('Enter valid details'); return; }
    const inst = allParticipants.find(p => p.code === state.tempSource.code);
    state.sources.push({
        institution: state.tempSource.code,
        institution_name: inst?.name,
        asset_type: asset,
        identifier: identifier,
        amount: amount
    });
    state.tempSource = {};
    goTo('multi_source_list');
}
function removeSource(idx) { state.sources.splice(idx, 1); render(); }
function goToMultiDest() { goTo('multi_dest_country'); }

// Multi-destination functions
function multiDestCountry(country) { state.tempDest = { country }; goTo('multi_dest_institution'); }
function multiDestInstitution(code) { state.tempDest.institution = code; goTo('multi_dest_action'); }
function multiDestAction(action) { state.tempDest.action = action; goTo('multi_dest_details'); }
function submitMultiDest() {
    let value = '';
    let amount = 0;
    if (state.tempDest.action === 'cashout') {
        value = document.getElementById('mdPhone')?.value;
        amount = parseFloat(document.getElementById('mdAmount')?.value);
    } else {
        value = document.getElementById('mdAccount')?.value;
        amount = parseFloat(document.getElementById('mdAmount')?.value);
    }
    if (!value || !amount || amount < 10) { alert('Enter valid details'); return; }
    const inst = allParticipants.find(p => p.code === state.tempDest.institution);
    state.destinations.push({
        institution: state.tempDest.institution,
        institution_name: inst?.name,
        action: state.tempDest.action,
        value: value,
        amount: amount
    });
    state.tempDest = {};
    if (state.swapMode === 'multi_dest') {
        goTo('multi_dest_list');
    } else {
        goTo('multi_source_list');
    }
}
function addMultiDest() { state.tempDest = {}; goTo('multi_dest_country'); }
function removeDestination(idx) { state.destinations.splice(idx, 1); render(); }
function executeMultiDestSwap() { alert('Multi-destination swap. Total: ' + state.destinations.reduce((s,d)=>s+d.amount,0)); reset(); }

function useSavedSource(id, code, type) {
    if (state.swapMode === 'multi_source') {
        const inst = allParticipants.find(p => p.code === code);
        state.sources.push({ institution: code, institution_name: inst?.name, asset_type: type, identifier: null, amount: null });
        goTo('multi_source_list');
    } else {
        state.tempSource = { code, asset_type: type };
        goTo('single_amount');
    }
}

function showLinkForm(code, name, assetTypes) {
    state.linkInstCode = code;
    state.linkInstName = name;
    state.linkAssetTypes = assetTypes;
    goTo('link_form');
}

async function submitLinkSource() {
    const assetType = document.getElementById('linkAssetType')?.value;
    const identifier = document.getElementById('linkIdentifier')?.value;
    const pin = document.getElementById('linkPin')?.value;
    if (!assetType || !identifier) { alert('Please fill all fields'); return; }
    const formData = new FormData();
    formData.append('action', 'save_source');
    formData.append('institution_code', state.linkInstCode);
    formData.append('asset_type', assetType);
    formData.append('identifier', identifier);
    formData.append('pin', pin);
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const result = await res.json();
        alert(result.message);
        if (result.status === 'success') location.reload();
    } catch(e) { alert('Error: ' + e.message); }
}

function goBackToSource() { goTo('single_source_form'); }
function reset() { state = { step: 'init', swapMode: 'single', sources: [], destinations: [], tempSource: {}, tempDest: {}, currentCountryList: [] }; render(); }

render();
</script>
</body>
</html>
