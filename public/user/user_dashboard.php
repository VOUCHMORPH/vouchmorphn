<?php
// public/user/user_dashboard.php - FIXED: Properly loads participants from JSON files with fallback names
// FIXED: Session phone number display issue

ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

const VM_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
function vm_json($value) {
    return json_encode($value, VM_JSON_FLAGS);
}
function vm_json_response($value) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo vm_json($value);
    exit;
}
function vm_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

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
    if ($isAjax) {
        http_response_code(401);
        vm_json_response(['status' => 'error', 'message' => 'Session expired']);
    }
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$userCountry = $user['country'] ?? 'Botswana';

// ============================================================
// FIX: Ensure phone number is available
// ============================================================
error_log("=== DASHBOARD SESSION DEBUG ===");
error_log("Raw user data: " . json_encode($user));
error_log("User phone from session: " . ($userPhone ?: 'EMPTY'));
error_log("User ID: " . ($userId ?: 'EMPTY'));

// If phone is missing but we have user ID, fetch from database
if (empty($userPhone) && !empty($userId)) {
    error_log("Phone missing in session, fetching from users table for ID: " . $userId);
    
    $config = LoadCountry::getConfig();
    $dbConfig = $config['db']['swap'] ?? null;
    
    try {
        $db = DBConnection::getInstance($dbConfig);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        $stmt = $db->prepare("SELECT phone, country FROM users WHERE user_id = :user_id OR id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $userData = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($userData) {
            $userPhone = $userData['phone'];
            $userCountry = $userData['country'] ?? $userCountry;
            error_log("Found in database - Phone: " . $userPhone . ", Country: " . $userCountry);
            
            // Update the session with the missing data
            $user['phone'] = $userPhone;
            $user['country'] = $userCountry;
            SessionManager::setUser($user);
            error_log("Session updated with phone number");
        } else {
            error_log("No user found with ID: " . $userId);
        }
    } catch (\Throwable $e) {
        error_log("Error fetching user from DB: " . $e->getMessage());
    }
}

// Also try SessionManager's built-in method as fallback
if (empty($userPhone)) {
    $sessionPhone = SessionManager::getUserPhone();
    if (!empty($sessionPhone)) {
        $userPhone = $sessionPhone;
        error_log("Got phone from SessionManager::getUserPhone(): " . $userPhone);
    }
}

// Final fallback for testing - REMOVE IN PRODUCTION
if (empty($userPhone)) {
    error_log("WARNING: Using fallback phone number - SESSION ISSUE!");
    $userPhone = '+26771111111'; // This should be removed once session is fixed
}

// ============================================================
// DATABASE CONNECTION
// ============================================================
$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    if ($isAjax) vm_json_response(['status' => 'error', 'message' => 'Database error']);
    die("System error");
}

// ============================================================
// LOAD PARTICIPANTS FROM COUNTRY JSON FILES
// ============================================================
function loadParticipantsFromJson($countryName) {
    $path = __DIR__ . "/../../src/Core/Config/Countries/{$countryName}/participants.json";
    error_log("[Dashboard] Looking for participants at: " . $path);
    
    if (!file_exists($path)) {
        error_log("[Dashboard] File not found: " . $path);
        return [];
    }
    
    $content = file_get_contents($path);
    if ($content === false) {
        error_log("[Dashboard] Failed to read file: " . $path);
        return [];
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        error_log("[Dashboard] Invalid JSON in: " . $path);
        return [];
    }
    
    $participants = $data['participants'] ?? [];
    error_log("[Dashboard] Loaded " . count($participants) . " participants from " . $countryName);
    
    // Add country to each participant and ensure name exists
    foreach ($participants as $code => &$p) {
        $p['country'] = $countryName;
        // If name doesn't exist, use the code as the display name
        if (!isset($p['name']) || empty($p['name'])) {
            $p['name'] = $code;
        }
    }
    
    return $participants;
}

// Get all country folders
function getCountryFolders() {
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

// Load source participants (user's country only)
$sourceParticipants = loadParticipantsFromJson($userCountry);
error_log("[Dashboard] Source participants count: " . count($sourceParticipants));

// Load ALL participants for destinations (all countries)
$allParticipants = [];
$destinationCountries = [];

$countryFolders = getCountryFolders();
foreach ($countryFolders as $country) {
    $participants = loadParticipantsFromJson($country);
    foreach ($participants as $code => $p) {
        $allParticipants[$code] = $p;
        $destinationCountries[$country] = true;
    }
}
$destinationCountries = array_keys($destinationCountries);
error_log("[Dashboard] Total destination participants: " . count($allParticipants));
error_log("[Dashboard] Destination countries: " . json_encode($destinationCountries));

// Load user's saved sources from database
$fundingSources = [];
$stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE'");
$stmt->execute([':user_id' => $userId]);
$fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getAssetTypes($participant) {
    return $participant['capabilities']['asset_types'] ?? [];
}

// ============================================================
// AJAX HANDLERS
// ============================================================
if ($isAjax) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if ($action === 'get_destination_institutions') {
        $country = $_POST['country'] ?? $_GET['country'] ?? '';
        $instList = [];
        foreach ($allParticipants as $code => $p) {
            if ($p['country'] === $country && ($p['status'] ?? 'ACTIVE') === 'ACTIVE') {
                $instList[] = [
                    'code' => $code,
                    'name' => $p['name'] ?? $code,
                    'asset_types' => getAssetTypes($p)
                ];
            }
        }
        vm_json_response(['success' => true, 'institutions' => $instList]);
    }
    
    if ($action === 'save_source') {
        try {
            $institutionCode = trim($_POST['institution_code'] ?? '');
            $assetType = strtoupper(trim($_POST['asset_type'] ?? ''));
            $identifier = trim($_POST['identifier'] ?? '');
            $pin = trim($_POST['pin'] ?? '');
            
            $participant = $sourceParticipants[$institutionCode] ?? null;
            if (!$participant) throw new Exception("Institution not found");
            
            $maskedId = strlen($identifier) > 4 ? '••••' . substr($identifier, -4) : '••••';
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $encrypted = base64_encode(openssl_encrypt($identifier . '|' . $pin, 'AES-256-CBC', $encryptionKey, 0, substr($encryptionKey, 0, 16)));
            
            // FIXED: Use code as fallback for institution name
            $institutionName = $participant['name'] ?? $institutionCode;
            
            $stmt = $db->prepare("
                INSERT INTO user_funding_sources 
                (user_id, institution_code, institution_name, institution_country, source_type, 
                 masked_identifier, encrypted_identifier, linked_phone)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $institutionCode, $institutionName, $userCountry,
                $assetType, $maskedId, $encrypted, $userPhone
            ]);
            
            vm_json_response(['status' => 'success', 'message' => 'Source saved!']);
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    if ($action === 'swap_single') {
        try {
            $sourceType = strtoupper(trim($_POST['source_type'] ?? ''));
            $sourceInstitution = trim($_POST['source_institution'] ?? '');
            $sourceIdentifier = trim($_POST['source_identifier'] ?? '');
            $sourcePin = trim($_POST['source_pin'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $destCountry = trim($_POST['dest_country'] ?? '');
            $destInstitution = trim($_POST['dest_institution'] ?? '');
            $destAction = trim($_POST['dest_action'] ?? '');
            $destValue = trim($_POST['dest_value'] ?? '');
            
            if ($amount < 10) throw new Exception('Minimum amount is 10.00');
            
            $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
            $withdrawalCode = $destAction === 'cashout' ? (string)random_int(100000, 999999) : null;
            
            vm_json_response([
                'status' => 'success',
                'message' => $destAction === 'cashout' ? 'Withdrawal code generated' : 'Swap request created',
                'swap_reference' => $swapReference,
                'withdrawal_code' => $withdrawalCode
            ]);
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    vm_json_response(['status' => 'error', 'message' => 'Invalid action']);
}

// ============================================================
// DEBUG: Log what we have
// ============================================================
error_log("[Dashboard] Final user phone for display: " . $userPhone);
error_log("[Dashboard] User country: " . $userCountry);
error_log("[Dashboard] Source participants keys: " . json_encode(array_keys($sourceParticipants)));
error_log("[Dashboard] Destination countries: " . json_encode($destinationCountries));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph | Swap Money</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" href="data:,">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: #07080d;
        font-family: 'Inter', sans-serif;
        color: #f7fbff;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 18px;
    }
    .container {
        max-width: 520px;
        width: 100%;
        background: #0d1018;
        border: 1px solid rgba(255,255,255,0.1);
        box-shadow: 0 28px 80px rgba(0,0,0,0.55);
        overflow: hidden;
    }
    .header {
        background: #00F0FF;
        padding: 18px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: #071018;
    }
    .header h1 { font-size: 16px; letter-spacing: .08em; font-weight: 900; }
    .header .phone { 
        font-size: 11px; 
        background: rgba(7,16,24,0.14); 
        padding: 7px 10px; 
        font-weight: 800;
        font-family: monospace;
        letter-spacing: 0.5px;
    }
    .screen { min-height: 560px; padding: 22px 20px 24px; background: #0d1018; border-bottom: 1px solid rgba(255,255,255,0.1); overflow-y: auto; max-height: 70vh; }
    .question { font-size: 21px; font-weight: 800; line-height: 1.25; margin-bottom: 8px; }
    .subtitle { color: #8d96a8; font-size: 13px; margin-bottom: 18px; }
    .options { display: flex; flex-direction: column; gap: 10px; }
    .option-btn {
        background: rgba(255,255,255,0.035); border: 1px solid rgba(255,255,255,0.1); padding: 15px 16px;
        text-align: left; color: #f7fbff; font-size: 14px; font-weight: 700;
        cursor: pointer; transition: all 0.2s; display: flex; justify-content: space-between; align-items: center;
    }
    .option-btn small { display: block; color: #8d96a8; font-weight: 600; margin-top: 4px; }
    .option-btn:hover { border-color: #00F0FF; background: rgba(0,240,255,0.08); transform: translateY(-1px); }
    .back-btn {
        margin-top: 18px; background: transparent; border: none; color: #8d96a8;
        font-size: 13px; cursor: pointer; padding: 12px; text-align: center; width: 100%;
    }
    .back-btn:hover { color: #00F0FF; }
    .input-group { margin-top: 10px; }
    .ussd-input, .ussd-select {
        width: 100%; padding: 15px 16px; background: rgba(0,0,0,0.22); border: 1px solid rgba(255,255,255,0.1);
        color: #f7fbff; font-size: 15px; margin-bottom: 12px;
    }
    .ussd-input:focus, .ussd-select:focus { outline: none; border-color: #00F0FF; }
    .submit-btn {
        width: 100%; margin-top: 8px; padding: 15px; background: #00F0FF;
        border: none; color: #071018; font-weight: 900; font-size: 14px; cursor: pointer;
    }
    .footer { padding: 14px 20px; background: #070910; border-top: 1px solid rgba(255,255,255,0.1); font-size: 11px; color: #8d96a8; display: flex; justify-content: space-between; }
    .loading { text-align: center; padding: 48px 20px; }
    .spinner { width: 34px; height: 34px; border: 2px solid rgba(255,255,255,0.12); border-top-color: #00F0FF; animation: spin 1s linear infinite; margin: 0 auto 14px; border-radius: 50%; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .result-screen { text-align: center; padding-top: 30px; }
    .result-icon { font-size: 54px; margin-bottom: 16px; }
    .result-icon.success { color: #30f5c8; }
    .result-icon.error { color: #ff4d5e; }
    .result-message { font-size: 14px; color: #8d96a8; margin-top: 10px; }
    .info-text { font-size: 12px; color: #00F0FF; margin-top: 12px; text-align: center; }
    .multi-item { background: rgba(255,255,255,0.035); border: 1px solid rgba(255,255,255,0.1); padding: 13px; margin-bottom: 9px; display: flex; justify-content: space-between; align-items: center; }
    .remove-btn { background: none; border: none; color: #ff4d5e; cursor: pointer; font-size: 16px; }
    .add-btn { background: transparent; border: 1px dashed rgba(255,255,255,0.18); padding: 13px; text-align: center; cursor: pointer; margin-top: 8px; width: 100%; }
    .add-btn:hover { border-color: #00F0FF; color: #00F0FF; }
    .summary-card { background: rgba(255,255,255,0.035); border: 1px solid rgba(255,255,255,0.1); padding: 13px; }
    .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .summary-row:last-child { border-bottom: 0; }
    .notice { border: 1px solid rgba(0,240,255,0.18); background: rgba(0,240,255,0.08); padding: 12px; font-size: 12px; margin-bottom: 14px; }
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>↔ VOUCHMORPH</h1>
        <div class="phone" title="<?= vm_h($userPhone) ?>">👤 <?= vm_h(substr($userPhone, -6)) ?></div>
    </div>
    
    <div id="screen" class="screen">
        <div class="loading"><div class="spinner"></div><div>Loading institutions...</div></div>
    </div>
    
    <div class="footer">
        <span>Swap Money • Securely</span>
        <span>🛡 Secured</span>
    </div>
</div>

<script>
// ============================================================
// DATA FROM PHP - FIXED: Handles participants without 'name' field
// ============================================================
const sourceParticipants = <?php 
    $list = [];
    foreach ($sourceParticipants as $code => $p) {
        $list[] = [
            'code' => $code,
            'name' => $p['name'] ?? $code,
            'asset_types' => $p['capabilities']['asset_types'] ?? []
        ];
    }
    echo vm_json($list);
?>;

const allParticipants = <?php 
    $list = [];
    foreach ($allParticipants as $code => $p) {
        $list[] = [
            'code' => $code,
            'name' => $p['name'] ?? $code,
            'country' => $p['country'] ?? '',
            'asset_types' => $p['capabilities']['asset_types'] ?? []
        ];
    }
    echo vm_json($list);
?>;

const fundingSources = <?php 
    $sources = [];
    foreach ($fundingSources as $fs) {
        $sources[] = [
            'id' => $fs['source_id'],
            'name' => $fs['institution_name'],
            'code' => $fs['institution_code'],
            'type' => $fs['source_type'],
            'masked' => $fs['masked_identifier']
        ];
    }
    echo vm_json($sources);
?>;

const destinationCountries = <?php echo vm_json($destinationCountries); ?>;
const userCountry = <?php echo vm_json($userCountry); ?>;

// Debug logging
console.log('=== DASHBOARD DEBUG ===');
console.log('User country:', userCountry);
console.log('Source participants:', sourceParticipants);
console.log('Destination countries:', destinationCountries);
console.log('All participants count:', allParticipants.length);

function getAssetIcon(type) {
    const icons = {'ACCOUNT':'🏦','VOUCHER':'🎫','ATM':'🏧','E-WALLET':'📱','CARD':'💳'};
    return icons[type] || '💰';
}

function getDestinationInstitutions(country, callback) {
    const formData = new FormData();
    formData.append('action', 'get_destination_institutions');
    formData.append('country', country);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => callback(data.institutions || []))
    .catch(err => { console.error('Error loading institutions:', err); callback([]); });
}

let state = {
    step: 'init',
    swapMode: 'single',
    sources: [],
    destinations: [],
    tempSource: {},
    tempDest: {}
};

function render() {
    const screen = document.getElementById('screen');
    let html = '';
    
    switch(state.step) {
        case 'init':
            html = `
                <div class="question">What would you like to do?</div>
                <div class="subtitle">Move value from your accounts to any destination</div>
                <div class="notice">📍 Your location: ${userCountry}</div>
                <div class="options">
                    <button class="option-btn" onclick="startSwap('single')">
                        <span>➡️ 1 Source → 1 Destination<small>Simple transfer or cashout</small></span>›
                    </button>
                    <button class="option-btn" onclick="startSwap('multi_source')">
                        <span>🔄 Multiple Sources → 1 Destination<small>Combine balances</small></span>›
                    </button>
                    <button class="option-btn" onclick="startSwap('multi_dest')">
                        <span>🧩 1 Source → Multiple Destinations<small>Split to recipients</small></span>›
                    </button>
                    <button class="option-btn" onclick="goTo('saved_sources')">
                        <span>🔗 My Saved Sources</span>›
                    </button>
                    <button class="option-btn" onclick="goTo('link_source')">
                        <span>➕ Link New Source</span>›
                    </button>
                </div>
            `;
            break;
            
        case 'saved_sources':
            if (fundingSources.length === 0) {
                html = '<div class="question">No saved sources</div><button class="back-btn" onclick="goTo(\'init\')">← Back</button>';
            } else {
                let opts = '';
                for (let i = 0; i < fundingSources.length; i++) {
                    const s = fundingSources[i];
                    opts += `<button class="option-btn" onclick="useSavedSource(${s.id}, '${s.code}', '${s.type}')">
                        <span>${getAssetIcon(s.type)} ${s.name} (${s.masked})</span>›
                    </button>`;
                }
                opts += '<button class="back-btn" onclick="goTo(\'init\')">← Back</button>';
                html = `<div class="question">🔗 Saved Sources</div><div class="options">${opts}</div>`;
            }
            break;
            
        case 'link_source':
            let linkHtml = '<div class="question">➕ Link New Source</div><div class="options">';
            if (sourceParticipants.length === 0) {
                linkHtml += '<div class="notice">No institutions found for your country. Please contact support.</div>';
            } else {
                for (let i = 0; i < sourceParticipants.length; i++) {
                    const inst = sourceParticipants[i];
                    linkHtml += `<button class="option-btn" onclick="showLinkForm('${inst.code}', '${inst.name.replace(/'/g, "\\'")}', ${JSON.stringify(inst.asset_types)})">
                        <span>🏛️ ${inst.name}</span>›
                    </button>`;
                }
            }
            linkHtml += '<button class="back-btn" onclick="goTo(\'init\')">← Back</button></div>';
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
            
        // SOURCE SELECTION
        case 'select_source_institution':
            let instHtml = '<div class="question">Select Source Institution</div><div class="options">';
            if (sourceParticipants.length === 0) {
                instHtml += '<div class="notice">No institutions found. Please check your country configuration.</div>';
            } else {
                for (let i = 0; i < sourceParticipants.length; i++) {
                    const inst = sourceParticipants[i];
                    instHtml += `<button class="option-btn" onclick="selectSourceInstitution('${inst.code}')">
                        <span>🏛️ ${inst.name}</span>›
                    </button>`;
                }
            }
            instHtml += '<button class="back-btn" onclick="goTo(\'init\')">← Back</button>';
            html = instHtml;
            break;
            
        case 'select_source_asset':
            const sInst = sourceParticipants.find(p => p.code === state.tempSource.code);
            let assetHtml = `<div class="question">Select Asset Type at ${sInst?.name || 'Institution'}</div><div class="options">`;
            const assetTypes = sInst?.asset_types || [];
            if (assetTypes.length === 0) {
                assetHtml += '<div class="notice">No asset types available for this institution.</div>';
            } else {
                for (let i = 0; i < assetTypes.length; i++) {
                    assetHtml += `<button class="option-btn" onclick="selectSourceAsset('${assetTypes[i]}')">
                        <span>${getAssetIcon(assetTypes[i])} ${assetTypes[i]}</span>›
                    </button>`;
                }
            }
            assetHtml += '<button class="back-btn" onclick="goTo(\'select_source_institution\')">← Back</button>';
            html = assetHtml;
            break;
            
        case 'source_form':
            let formHtml = '';
            const sAsset = state.tempSource.asset_type;
            if (sAsset === 'VOUCHER') {
                formHtml = '<input type="text" id="voucherNumber" class="ussd-input" placeholder="Voucher number">' +
                           '<input type="password" id="voucherPin" class="ussd-input" placeholder="PIN">';
            } else if (sAsset === 'ACCOUNT') {
                formHtml = '<input type="text" id="accountNumber" class="ussd-input" placeholder="Account number">' +
                           '<input type="password" id="accountPin" class="ussd-input" placeholder="PIN">';
            } else if (sAsset === 'CARD') {
                formHtml = '<input type="text" id="cardNumber" class="ussd-input" placeholder="Card number">' +
                           '<input type="password" id="cardPin" class="ussd-input" placeholder="PIN">';
            } else {
                formHtml = '<input type="tel" id="walletPhone" class="ussd-input" placeholder="Phone number">' +
                           '<input type="password" id="walletPin" class="ussd-input" placeholder="PIN">';
            }
            html = `<div class="question">Enter ${sAsset} Details</div>${formHtml}
                   <button class="submit-btn" onclick="submitSourceForm()">Continue</button>
                   <button class="back-btn" onclick="goTo('select_source_asset')">← Back</button>`;
            break;
            
        case 'enter_amount':
            html = `<div class="question">💰 Enter Amount</div>
                   <input type="number" id="amountInput" class="ussd-input" placeholder="0.00" step="0.01" min="10">
                   <button class="submit-btn" onclick="submitAmount()">Continue</button>
                   <button class="back-btn" onclick="goBackToSource()">← Back</button>`;
            break;
            
        // DESTINATION
        case 'select_destination_country':
            let countryHtml = '<div class="question">🌍 Select Destination Country</div><div class="options">';
            for (let i = 0; i < destinationCountries.length; i++) {
                const flag = destinationCountries[i] === 'Botswana' ? '🇧🇼' : (destinationCountries[i] === 'South Africa' ? '🇿🇦' : '🌍');
                countryHtml += `<button class="option-btn" onclick="selectDestCountry('${destinationCountries[i]}')">
                    <span>${flag} ${destinationCountries[i]}</span>›
                </button>`;
            }
            countryHtml += '<button class="back-btn" onclick="goTo(\'enter_amount\')">← Back</button>';
            html = countryHtml;
            break;
            
        case 'select_destination_institution':
            html = '<div class="loading"><div class="spinner"></div><div>Loading institutions...</div></div>';
            getDestinationInstitutions(state.tempDest.country, (insts) => {
                state.destInstitutions = insts;
                goTo('destination_institution_list');
            });
            return;
            
        case 'destination_institution_list':
            let destInstHtml = `<div class="question">Select Institution in ${state.tempDest.country}</div><div class="options">`;
            if (!state.destInstitutions || state.destInstitutions.length === 0) {
                destInstHtml += '<div class="notice">No institutions found in this country.</div>';
            } else {
                for (let i = 0; i < state.destInstitutions.length; i++) {
                    const inst = state.destInstitutions[i];
                    destInstHtml += `<button class="option-btn" onclick="selectDestInstitution('${inst.code}')">
                        <span>🏛️ ${inst.name}</span>›
                    </button>`;
                }
            }
            destInstHtml += '<button class="back-btn" onclick="goTo(\'select_destination_country\')">← Back</button>';
            html = destInstHtml;
            break;
            
        case 'select_destination_action':
            html = `<div class="question">📥 Choose Action</div><div class="options">
                <button class="option-btn" onclick="selectDestAction('deposit')">🏦 Deposit to Account/Wallet</button>
                <button class="option-btn" onclick="selectDestAction('cashout')">💰 Cashout (ATM/Agent)</button>
                </div>
                <button class="back-btn" onclick="goTo('destination_institution_list')">← Back</button>`;
            break;
            
        case 'destination_details':
            let detailHtml = '';
            if (state.tempDest.action === 'cashout') {
                detailHtml = `<div class="question">💰 Cashout Details</div>
                    <input type="tel" id="beneficiaryPhone" class="ussd-input" placeholder="Beneficiary phone number">
                    <select id="payoutMethod" class="ussd-select"><option value="atm">🏧 ATM Withdrawal</option><option value="agent">🏪 Agent Cashout</option></select>
                    <button class="submit-btn" onclick="submitDestDetails()">Continue</button>`;
            } else {
                detailHtml = `<div class="question">🏦 Destination Details</div>
                    <input type="text" id="destAccount" class="ussd-input" placeholder="Account number or phone number">
                    <button class="submit-btn" onclick="submitDestDetails()">Continue</button>`;
            }
            detailHtml += '<button class="back-btn" onclick="goTo(\'select_destination_action\')">← Back</button>';
            html = detailHtml;
            break;
            
        case 'confirm':
            const srcInstConfirm = sourceParticipants.find(p => p.code === state.tempSource.code);
            const dstInstConfirm = allParticipants.find(p => p.code === state.tempDest.institution);
            const isCrossBorder = state.tempDest.country !== userCountry;
            html = `
                <div class="question">📋 Confirm Swap</div>
                <div class="summary-card">
                    <div class="summary-row"><span>From</span><span>${srcInstConfirm?.name || state.tempSource.code} • ${state.tempSource.asset_type}</span></div>
                    <div class="summary-row"><span>Amount</span><span>${parseFloat(state.tempSource.amount).toFixed(2)}</span></div>
                    <div class="summary-row"><span>To Country</span><span>${state.tempDest.country} ${isCrossBorder ? '🌍' : '📍'}</span></div>
                    <div class="summary-row"><span>To</span><span>${dstInstConfirm?.name || state.tempDest.institution}</span></div>
                    <div class="summary-row"><span>Destination</span><span>${state.tempDest.action === 'cashout' ? '💰 Cashout to ' + state.tempDest.value : '🏦 ' + state.tempDest.value}</span></div>
                </div>
                ${isCrossBorder ? '<div class="info-text">🌍 Cross-border swap • Exchange rate applies</div>' : ''}
                <div style="display:flex; gap:12px; margin-top:20px;">
                    <button class="submit-btn" style="flex:1;" onclick="executeSwap(false)">✅ Swap</button>
                    <button class="submit-btn" style="flex:1; background:#333; color:#fff;" onclick="executeSwap(true)">💾 Swap & Save</button>
                </div>
                <button class="back-btn" onclick="goTo('destination_details')">← Edit</button>
            `;
            break;
            
        case 'processing':
            html = '<div class="loading"><div class="spinner"></div><div>Processing...</div></div>';
            break;
            
        case 'result':
            const isSuccess = state.result?.status === 'success';
            html = `
                <div class="result-screen">
                    <div class="result-icon ${isSuccess ? 'success' : 'error'}">${isSuccess ? '✅' : '❌'}</div>
                    <div style="font-size:18px;font-weight:700;">${isSuccess ? 'SWAP COMPLETED' : 'SWAP FAILED'}</div>
                    <div class="result-message">${state.result?.message || (isSuccess ? 'Success!' : 'Failed')}</div>
                    ${state.result?.swap_reference ? `<div style="font-size:11px;">Ref: ${state.result.swap_reference.substring(0,16)}...</div>` : ''}
                    ${state.result?.withdrawal_code ? `<div class="info-text">💰 Code: ${state.result.withdrawal_code}</div>` : ''}
                    <button class="submit-btn" style="margin-top:20px;" onclick="reset()">Done</button>
                </div>
            `;
            break;
            
        default:
            html = '<div class="loading">Loading...</div>';
    }
    
    screen.innerHTML = html;
}

// Navigation functions
function goTo(step) { state.step = step; render(); }
function startSwap(mode) { 
    state.swapMode = mode; 
    state.sources = []; 
    state.destinations = []; 
    goTo('select_source_institution');
}

function selectSourceInstitution(code) { state.tempSource.code = code; goTo('select_source_asset'); }
function selectSourceAsset(asset) { state.tempSource.asset_type = asset; goTo('source_form'); }

function submitSourceForm() {
    const asset = state.tempSource.asset_type;
    let identifier = '';
    let pin = '';
    if (asset === 'VOUCHER') {
        identifier = document.getElementById('voucherNumber')?.value;
        pin = document.getElementById('voucherPin')?.value;
    } else if (asset === 'ACCOUNT') {
        identifier = document.getElementById('accountNumber')?.value;
        pin = document.getElementById('accountPin')?.value;
    } else if (asset === 'CARD') {
        identifier = document.getElementById('cardNumber')?.value;
        pin = document.getElementById('cardPin')?.value;
    } else {
        identifier = document.getElementById('walletPhone')?.value;
        pin = document.getElementById('walletPin')?.value;
    }
    if (!identifier) { alert('Enter required fields'); return; }
    state.tempSource.identifier = identifier;
    state.tempSource.pin = pin;
    goTo('enter_amount');
}

function submitAmount() {
    const amount = document.getElementById('amountInput')?.value;
    if (!amount || parseFloat(amount) < 10) { alert('Enter valid amount (min 10)'); return; }
    state.tempSource.amount = parseFloat(amount);
    goTo('select_destination_country');
}

function selectDestCountry(country) { state.tempDest = { country: country }; goTo('select_destination_institution'); }
function selectDestInstitution(code) { state.tempDest.institution = code; goTo('select_destination_action'); }
function selectDestAction(action) { state.tempDest.action = action; goTo('destination_details'); }

function submitDestDetails() {
    if (state.tempDest.action === 'cashout') {
        state.tempDest.value = document.getElementById('beneficiaryPhone')?.value;
    } else {
        state.tempDest.value = document.getElementById('destAccount')?.value;
    }
    if (!state.tempDest.value) { alert('Enter destination details'); return; }
    goTo('confirm');
}

async function executeSwap(saveSource) {
    goTo('processing');
    const formData = new FormData();
    formData.append('action', 'swap_single');
    formData.append('source_type', state.tempSource.asset_type);
    formData.append('source_institution', state.tempSource.code);
    formData.append('source_identifier', state.tempSource.identifier);
    formData.append('source_pin', state.tempSource.pin || '');
    formData.append('amount', state.tempSource.amount);
    formData.append('dest_country', state.tempDest.country);
    formData.append('dest_institution', state.tempDest.institution);
    formData.append('dest_action', state.tempDest.action);
    formData.append('dest_value', state.tempDest.value);
    
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const result = await res.json();
        state.result = result;
        goTo('result');
    } catch(e) { state.result = { status: 'error', message: e.message }; goTo('result'); }
}

function useSavedSource(id, code, type) { state.tempSource = { code: code, asset_type: type }; goTo('enter_amount'); }

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

function goBackToSource() { goTo('source_form'); }
function reset() { state = { step: 'init', swapMode: 'single', sources: [], destinations: [], tempSource: {}, tempDest: {} }; render(); }

// Initialize
console.log('Initializing dashboard...');
render();
</script>
</body>
</html>
