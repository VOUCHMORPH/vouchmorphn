<?php
// public/user/user_dashboard.php
// Make sure there is ABSOLUTELY NO whitespace before <?php

// Turn off error output for JSON responses
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    ini_set('display_errors', 0);
    error_reporting(0);
}

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
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }
    die("System error");
}

// Dynamically load all country folders
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
    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return [];
    }
    $participants = $data['participants'] ?? [];
    foreach ($participants as $code => &$p) {
        $p['country'] = $countryName;
    }
    return $participants;
}

// Load ALL participants
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

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Handle AJAX requests FIRST - before any HTML output
if ($isAjax) {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing action']);
        exit;
    }
    
    if ($action === 'get_institutions_by_country') {
        $country = $_POST['country'] ?? $_GET['country'] ?? '';
        $instList = [];
        foreach ($allParticipants as $code => $p) {
            if ($p['country'] === $country && ($p['status'] ?? 'ACTIVE') === 'ACTIVE') {
                $instList[] = [
                    'code' => $code,
                    'name' => $p['name'],
                    'type' => $p['type'] ?? '',
                    'category' => $p['category'] ?? '',
                    'asset_types' => getAssetTypes($p)
                ];
            }
        }
        echo json_encode(['success' => true, 'institutions' => $instList]);
        exit;
    }
    
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
    

    if ($action === 'swap_single') {
        try {
            $sourceType = strtoupper(trim($_POST['source_type'] ?? ''));
            $sourceInstitution = trim($_POST['source_institution'] ?? '');
            $sourceIdentifier = trim($_POST['source_identifier'] ?? '');
            $sourcePin = trim($_POST['source_pin'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $destInstitution = trim($_POST['dest_institution'] ?? '');
            $destAction = trim($_POST['dest_action'] ?? '');
            $destValue = trim($_POST['dest_value'] ?? '');

            if ($amount < 10) throw new Exception('Minimum swap amount is 10.00');
            if (!$sourceInstitution || !$sourceType || !$sourceIdentifier) throw new Exception('Source details are incomplete');
            if (!$destInstitution || !$destAction || !$destValue) throw new Exception('Destination details are incomplete');
            if (!isset($allParticipants[$sourceInstitution])) throw new Exception('Source institution not found');
            if (!isset($allParticipants[$destInstitution])) throw new Exception('Destination institution not found');

            $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
            $withdrawalCode = $destAction === 'cashout' ? (string)random_int(100000, 999999) : null;

            // NOTE: Replace this simulation block with SwapService execution once your service method is final.
            // Example future call:
            // $swapService = new SwapService($db);
            // $result = $swapService->executeSingleSwap([...]);

            echo json_encode([
                'status' => 'success',
                'message' => $destAction === 'cashout'
                    ? 'Swap request created. Share the withdrawal code with the beneficiary.'
                    : 'Swap request created successfully. The destination account will be credited after settlement.',
                'swap_reference' => $swapReference,
                'withdrawal_code' => $withdrawalCode
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

// If not AJAX, output HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph | Swap Money</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%2300F0FF'/%3E%3Cpath d='M18 22h22l-6-6 4-4 13 13-13 13-4-4 6-6H18v-6zm28 20H24l6 6-4 4-13-13 13-13 4 4-6 6h22v6z' fill='%230a0a0f'/%3E%3C/svg%3E">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --bg: #07080d;
        --panel: #0d1018;
        --panel-2: #121722;
        --line: rgba(255,255,255,0.10);
        --muted: #8d96a8;
        --text: #f7fbff;
        --accent: #00F0FF;
        --accent-soft: rgba(0,240,255,0.12);
        --danger: #ff4d5e;
        --success: #30f5c8;
        --shadow: 0 28px 80px rgba(0,0,0,.55);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background:
            radial-gradient(circle at top left, rgba(0,240,255,.14), transparent 34%),
            radial-gradient(circle at bottom right, rgba(88,101,242,.12), transparent 36%),
            var(--bg);
        font-family: 'Inter', sans-serif;
        color: var(--text);
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 18px;
    }
    button, input, select { font-family: inherit; }
    .ussd-container {
        max-width: 520px;
        width: 100%;
        background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.015));
        border: 1px solid var(--line);
        box-shadow: var(--shadow);
        overflow: hidden;
        border-radius: 28px;
        backdrop-filter: blur(18px);
    }
    .ussd-header {
        background: linear-gradient(135deg, var(--accent), #8ff8ff);
        padding: 18px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: #071018;
    }
    .brand { display:flex; flex-direction:column; gap:3px; }
    .ussd-header h1 { font-size: 16px; letter-spacing: .08em; font-weight: 900; color: #071018; }
    .tagline { font-size: 10px; font-weight: 700; opacity:.72; text-transform: uppercase; letter-spacing:.12em; }
    .ussd-header .balance { font-size: 11px; background: rgba(7,16,24,0.14); padding: 7px 10px; color: #071018; border-radius: 999px; font-weight:800; }
    .progress-wrap { padding: 14px 20px 0; background: var(--panel); }
    .progress-label { display:flex; justify-content:space-between; color: var(--muted); font-size: 11px; margin-bottom:8px; }
    .progress-track { height: 6px; background: rgba(255,255,255,.08); border-radius:999px; overflow:hidden; }
    .progress-bar { height: 100%; width: 8%; background: linear-gradient(90deg, var(--accent), var(--success)); border-radius:999px; transition: width .25s ease; }
    .ussd-screen { min-height: 560px; padding: 22px 20px 24px; background: var(--panel); border-bottom: 1px solid var(--line); overflow-y: auto; max-height: 70vh; }
    .question { font-size: 21px; font-weight: 850; line-height: 1.25; margin-bottom: 8px; letter-spacing:-.02em; }
    .subtitle { color: var(--muted); font-size:13px; line-height:1.5; margin-bottom:18px; }
    .options { display: flex; flex-direction: column; gap: 10px; }
    .option-btn {
        background: rgba(255,255,255,.035); border: 1px solid var(--line); padding: 15px 16px;
        text-align: left; color: var(--text); font-size: 14px; font-weight: 700;
        cursor: pointer; transition: all 0.2s; display: flex; justify-content: space-between; align-items: center;
        border-radius: 18px; gap:12px;
    }
    .option-btn small { display:block; color: var(--muted); font-weight:600; margin-top:4px; line-height:1.35; }
    .option-btn:hover { border-color: var(--accent); background: var(--accent-soft); transform: translateY(-1px); }
    .back-btn {
        margin-top: 18px; background: transparent; border: 1px solid transparent; color: var(--muted);
        font-size: 13px; cursor: pointer; padding: 12px; text-align: center; width: 100%; border-radius:14px;
    }
    .back-btn:hover { color: var(--accent); border-color: var(--line); }
    .input-group { margin-top: 10px; }
    .ussd-input, .ussd-select {
        width: 100%; padding: 15px 16px; background: rgba(0,0,0,.22); border: 1px solid var(--line);
        color: var(--text); font-size: 15px; margin-bottom: 12px; border-radius: 16px;
    }
    .ussd-input::placeholder { color:#687184; }
    .ussd-select { color: var(--text); }
    .ussd-input:focus, .ussd-select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(0,240,255,.08); }
    .submit-btn, .done-btn {
        width: 100%; margin-top: 8px; padding: 15px; background: linear-gradient(135deg, var(--accent), #b9fbff);
        border: none; color: #071018; font-weight: 900; font-size: 14px; cursor: pointer; border-radius: 16px;
    }
    .secondary-btn { background: rgba(255,255,255,.08) !important; color: var(--text) !important; border:1px solid var(--line) !important; }
    .ussd-footer { padding: 14px 20px; background: #070910; border-top: 1px solid var(--line); font-size: 11px; color: var(--muted); display: flex; justify-content: space-between; }
    .loading { text-align: center; padding: 48px 20px; color: var(--muted); }
    .spinner { width: 34px; height: 34px; border: 2px solid rgba(255,255,255,.12); border-top-color: var(--accent); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 14px; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .result-screen { text-align: center; padding-top:30px; }
    .result-icon { font-size: 54px; margin-bottom: 16px; }
    .result-icon.success { color: var(--success); }
    .result-icon.error { color: var(--danger); }
    .result-message { font-size: 14px; color: var(--muted); margin-top: 10px; line-height:1.5; }
    .info-text { font-size: 12px; color: var(--accent); margin-top: 12px; text-align: center; font-weight:700; }
    .multi-item, .summary-card { background: rgba(255,255,255,.035); border: 1px solid var(--line); padding: 13px; margin-bottom: 9px; display: flex; justify-content: space-between; align-items: center; border-radius: 16px; gap:10px; }
    .summary-card { display:block; }
    .summary-row { display:flex; justify-content:space-between; gap:15px; font-size:13px; padding:8px 0; border-bottom:1px solid rgba(255,255,255,.06); }
    .summary-row:last-child { border-bottom:0; }
    .summary-row span:first-child { color:var(--muted); }
    .summary-row span:last-child { text-align:right; font-weight:800; }
    .remove-btn { background: none; border: none; color: var(--danger); cursor: pointer; font-size: 16px; }
    .add-btn { background: transparent; border: 1px dashed rgba(255,255,255,.18); padding: 13px; text-align: center; cursor: pointer; margin-top: 8px; border-radius:16px; color:var(--text); width:100%; }
    .add-btn:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
    .notice { border: 1px solid rgba(0,240,255,.18); background: var(--accent-soft); color: #cdfcff; padding: 12px 14px; border-radius: 16px; font-size: 12px; line-height:1.45; margin-bottom: 14px; }
    @media (max-width: 560px) { body { padding:0; align-items:stretch; } .ussd-container { border-radius:0; min-height:100vh; } .ussd-screen { max-height:none; min-height:calc(100vh - 142px); } }
</style>
</head>
<body>

<div class="ussd-container">
    <div class="ussd-header">
        <div class="brand"><h1><i class="fas fa-exchange-alt"></i> VOUCHMORPH</h1><div class="tagline">Client swap console</div></div>
        <div class="balance"><i class="fas fa-user"></i> <?= htmlspecialchars(substr($userPhone, -6)) ?></div>
    </div>
    <div class="progress-wrap">
        <div class="progress-label"><span id="progressTitle">Start</span><span id="progressPercent">0%</span></div>
        <div class="progress-track"><div id="progressBar" class="progress-bar"></div></div>
    </div>
    
    <div id="screen" class="ussd-screen">
        <div class="loading"><div class="spinner"></div><div>Loading...</div></div>
    </div>
    
    <div class="ussd-footer">
        <span>Swap Money • Securely</span>
        <span><i class="fas fa-shield-alt"></i> Secured</span>
    </div>
</div>

<script>
// Data from PHP
const allParticipants = <?php 
    $list = [];
    foreach ($allParticipants as $code => $p) {
        $list[] = [
            'code' => $code,
            'name' => $p['name'],
            'type' => $p['type'] ?? '',
            'category' => $p['category'] ?? '',
            'country' => $p['country'],
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
            'id' => $fs['source_id'],
            'name' => $fs['institution_name'],
            'code' => $fs['institution_code'],
            'type' => $fs['source_type'],
            'masked' => $fs['masked_identifier'],
            'country' => $fs['institution_country']
        ];
    }
    echo json_encode($sources);
?>;

const availableCountries = <?php echo json_encode($availableCountries); ?>;
const userCountry = "<?= addslashes($userCountry) ?>";

function getAssetIcon(type) {
    const icons = {'ACCOUNT':'🏦','VOUCHER':'🎫','ATM':'🏧','E-WALLET':'📱','CARD':'💳'};
    return icons[type] || '💰';
}

function getParticipantsByCountry(country) {
    return allParticipants.filter(p => p.country === country && p.status === 'ACTIVE');
}

function getInstitutionsByCountry(country, callback) {
    const formData = new FormData();
    formData.append('action', 'get_institutions_by_country');
    formData.append('country', country);

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) {
        if (!res.ok) throw new Error('Network error: ' + res.status);
        return res.json();
    })
    .then(function(data) { callback(data.institutions || []); })
    .catch(function(err) {
        console.error('Institution loading failed:', err);
        callback([]);
    });
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, function(ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch];
    });
}

function jsString(value) {
    return String(value ?? '').replace(/\/g, '\\').replace(/'/g, "\'").replace(/
/g, '\n');
}

function setProgress() {
    const map = {
        init: [6, 'Start'], saved_sources: [12, 'Saved sources'], link_source: [16, 'Link source'], link_form: [24, 'Secure link'],
        single_select_country: [18, 'Source country'], single_select_institution: [28, 'Source institution'], single_select_asset: [38, 'Source type'], single_source_form: [48, 'Source details'], single_amount: [58, 'Amount'], single_dest_country: [68, 'Destination country'], single_dest_institution: [72, 'Loading'], single_dest_institution_list: [76, 'Destination institution'], single_dest_action: [84, 'Destination action'], single_dest_details: [90, 'Destination details'], single_confirm: [96, 'Confirm'], processing: [98, 'Processing'], result: [100, 'Done'],
        multi_source_list: [28, 'Build sources'], multi_source_add: [32, 'Add source'], multi_source_select_country: [38, 'Source country'], multi_source_select_institution: [46, 'Source institution'], multi_source_select_asset: [54, 'Source type'], multi_source_form: [62, 'Source details'], multi_dest_country: [72, 'Destination country'], multi_dest_institution: [78, 'Loading'], multi_dest_institution_list: [82, 'Destination institution'], multi_dest_action: [88, 'Destination action'], multi_dest_details: [92, 'Destination details'], multi_dest_list: [96, 'Review split']
    };
    const item = map[state.step] || [10, 'Continue'];
    document.getElementById('progressBar').style.width = item[0] + '%';
    document.getElementById('progressTitle').textContent = item[1];
    document.getElementById('progressPercent').textContent = item[0] + '%';
}

let state = {
    step: 'init',
    swapMode: 'single',
    sources: [],
    destinations: [],
    tempSource: {},
    tempDest: {},
    currentCountryList: []
};

function render() {
    setProgress();
    const screen = document.getElementById('screen');
    let html = '';
    
    switch(state.step) {
        case 'init':
            html = '<div class="question">What would you like to do?</div><div class="subtitle">Move value from vouchers, wallets, cards, or accounts into a destination your client chooses.</div><div class="notice"><i class="fas fa-shield-alt"></i> Your details are encrypted and only used to process this swap request.</div><div class="options">' +
                '<button class="option-btn" onclick="startSwap(\'single\')"><span>➡️ 1 Source → 1 Destination<small>Best for a simple transfer or cashout.</small></span><i class="fas fa-chevron-right"></i></button>' +
                '<button class="option-btn" onclick="startSwap(\'multi_source\')"><span>🔄 Multiple Sources → 1 Destination<small>Combine several balances into one payout.</small></span><i class="fas fa-chevron-right"></i></button>' +
                '<button class="option-btn" onclick="startSwap(\'multi_dest\')"><span>🧩 1 Source → Multiple Destinations<small>Split one balance to several recipients.</small></span><i class="fas fa-chevron-right"></i></button>' +
                '<button class="option-btn" onclick="goTo(\'saved_sources\')"><span>🔗 My Saved Sources<small>Use a source you linked before.</small></span><i class="fas fa-chevron-right"></i></button>' +
                '<button class="option-btn" onclick="goTo(\'link_source\')"><span>➕ Link New Source<small>Save a wallet, voucher, account, or card.</small></span><i class="fas fa-chevron-right"></i></button>' +
                '</div>';
            break;
            
        case 'saved_sources':
            if (fundingSources.length === 0) {
                html = '<div class="question">No saved sources</div><button class="back-btn" onclick="goTo(\'init\')">← Back</button>';
            } else {
                let opts = '';
                for (let i = 0; i < fundingSources.length; i++) {
                    const s = fundingSources[i];
                    opts += '<button class="option-btn" onclick="useSavedSource(' + s.id + ', \'' + s.code + '\', \'' + s.type + '\')">' +
                        '<span>' + getAssetIcon(s.type) + ' ' + s.name + ' (' + s.masked + ')</span>' +
                        '<i class="fas fa-chevron-right"></i></button>';
                }
                opts += '<button class="back-btn" onclick="goTo(\'init\')">← Back</button>';
                html = '<div class="question">🔗 Saved Sources</div><div class="options">' + opts + '</div>';
            }
            break;
            
        case 'link_source':
            let linkHtml = '<div class="question">➕ Link New Source</div><div class="options">';
            const countriesSet = {};
            for (let i = 0; i < allParticipants.length; i++) {
                countriesSet[allParticipants[i].country] = true;
            }
            const countryList = Object.keys(countriesSet);
            for (let c = 0; c < countryList.length; c++) {
                const country = countryList[c];
                const insts = getParticipantsByCountry(country);
                for (let i = 0; i < insts.length; i++) {
                    const inst = insts[i];
                    linkHtml += '<button class="option-btn" onclick="showLinkForm(\'' + inst.code + '\', \'' + inst.name.replace(/'/g, "\\'") + '\', ' + JSON.stringify(inst.asset_types) + ')">' +
                        '<span>🏛️ ' + inst.name + ' (' + country + ')</span>' +
                        '<i class="fas fa-chevron-right"></i></button>';
                }
            }
            linkHtml += '<button class="back-btn" onclick="goTo(\'init\')">← Back</button></div>';
            html = linkHtml;
            break;
            
        case 'link_form':
            html = '<div class="question">➕ Link ' + state.linkInstName + '</div>' +
                '<div class="input-group">' +
                '<select id="linkAssetType" class="ussd-select">' +
                '<option value="">Select asset type</option>';
            for (let i = 0; i < state.linkAssetTypes.length; i++) {
                html += '<option value="' + state.linkAssetTypes[i] + '">' + getAssetIcon(state.linkAssetTypes[i]) + ' ' + state.linkAssetTypes[i] + '</option>';
            }
            html += '</select>' +
                '<input type="text" id="linkIdentifier" class="ussd-input" placeholder="Account/Phone/Card number">' +
                '<input type="password" id="linkPin" class="ussd-input" placeholder="PIN (if any)">' +
                '<button class="submit-btn" onclick="submitLinkSource()">Link Source</button>' +
                '</div>' +
                '<button class="back-btn" onclick="goTo(\'link_source\')">← Back</button>';
            break;
            
        case 'single_select_country':
            let countryHtml = '<div class="question">Select Source Country</div><div class="options">';
            const uniqueCountries = {};
            for (let i = 0; i < allParticipants.length; i++) {
                uniqueCountries[allParticipants[i].country] = true;
            }
            const srcCountries = Object.keys(uniqueCountries);
            for (let i = 0; i < srcCountries.length; i++) {
                countryHtml += '<button class="option-btn" onclick="singleSelectCountry(\'' + srcCountries[i] + '\')">' +
                    '<span>' + srcCountries[i] + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            countryHtml += '<button class="back-btn" onclick="goTo(\'init\')">← Back</button></div>';
            html = countryHtml;
            break;
            
        case 'single_select_institution':
            let instHtml = '<div class="question">Select Source Institution</div><div class="options">';
            const insts = getParticipantsByCountry(state.tempSource.country);
            for (let i = 0; i < insts.length; i++) {
                instHtml += '<button class="option-btn" onclick="singleSelectInstitution(\'' + insts[i].code + '\')">' +
                    '<span>🏛️ ' + insts[i].name + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            instHtml += '<button class="back-btn" onclick="goTo(\'single_select_country\')">← Back</button></div>';
            html = instHtml;
            break;
            
        case 'single_select_asset':
            const sInst = allParticipants.find(function(p) { return p.code === state.tempSource.code; });
            let assetHtml = '<div class="question">Select Asset Type at ' + (sInst ? sInst.name : '') + '</div><div class="options">';
            const assetTypes = sInst ? sInst.asset_types : [];
            for (let i = 0; i < assetTypes.length; i++) {
                assetHtml += '<button class="option-btn" onclick="singleSelectAsset(\'' + assetTypes[i] + '\')">' +
                    '<span>' + getAssetIcon(assetTypes[i]) + ' ' + assetTypes[i] + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            assetHtml += '<button class="back-btn" onclick="goTo(\'single_select_institution\')">← Back</button></div>';
            html = assetHtml;
            break;
            
        case 'single_source_form':
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
            html = '<div class="question">Enter ' + sAsset + ' Details</div>' + formHtml +
                   '<button class="submit-btn" onclick="submitSingleSourceForm()">Continue</button>' +
                   '<button class="back-btn" onclick="goTo(\'single_select_asset\')">← Back</button>';
            break;
            
        case 'single_amount':
            html = '<div class="question">💰 Enter Amount</div>' +
                   '<input type="number" id="amountInput" class="ussd-input" placeholder="0.00" step="0.01" min="10">' +
                   '<button class="submit-btn" onclick="submitSingleAmount()">Continue</button>' +
                   '<button class="back-btn" onclick="goBackToSource()">← Back</button>';
            break;
            
        case 'single_dest_country':
            let destCountryHtml = '<div class="question">Select Destination Country</div><div class="options">';
            const allCountries = {};
            for (let i = 0; i < allParticipants.length; i++) {
                allCountries[allParticipants[i].country] = true;
            }
            const destCountries = Object.keys(allCountries);
            for (let i = 0; i < destCountries.length; i++) {
                destCountryHtml += '<button class="option-btn" onclick="singleDestCountry(\'' + destCountries[i] + '\')">' +
                    '<span>' + destCountries[i] + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            destCountryHtml += '<button class="back-btn" onclick="goTo(\'single_amount\')">← Back</button></div>';
            html = destCountryHtml;
            break;
            
        case 'single_dest_institution':
            html = '<div class="loading"><div class="spinner"></div><div>Loading...</div></div>';
            getInstitutionsByCountry(state.tempDest.country, function(insts) {
                state.destInstitutions = insts;
                goTo('single_dest_institution_list');
            });
            return;
            
        case 'single_dest_institution_list':
            let destInstHtml = '<div class="question">Select Institution in ' + state.tempDest.country + '</div><div class="options">';
            for (let i = 0; i < (state.destInstitutions || []).length; i++) {
                const inst = state.destInstitutions[i];
                destInstHtml += '<button class="option-btn" onclick="singleDestInstitution(\'' + inst.code + '\')">' +
                    '<span>🏛️ ' + inst.name + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            destInstHtml += '<button class="back-btn" onclick="goTo(\'single_dest_country\')">← Back</button></div>';
            html = destInstHtml;
            break;
            
        case 'single_dest_action':
            html = '<div class="question">📥 Choose Action</div><div class="options">' +
                '<button class="option-btn" onclick="singleDestAction(\'deposit\')">🏦 Deposit to Account/Wallet</button>' +
                '<button class="option-btn" onclick="singleDestAction(\'cashout\')">💰 Cashout (ATM/Agent)</button>' +
                '</div><button class="back-btn" onclick="goTo(\'single_dest_institution_list\')">← Back</button>';
            break;
            
        case 'single_dest_details':
            let detailHtml = '';
            if (state.tempDest.action === 'cashout') {
                detailHtml = '<div class="question">💰 Cashout Details</div>' +
                    '<input type="tel" id="beneficiaryPhone" class="ussd-input" placeholder="Beneficiary phone number">' +
                    '<select id="payoutMethod" class="ussd-select"><option value="atm">🏧 ATM Withdrawal</option><option value="agent">🏪 Agent Cashout</option></select>' +
                    '<button class="submit-btn" onclick="submitSingleDestDetails()">Continue</button>';
            } else {
                detailHtml = '<div class="question">🏦 Destination Details</div>' +
                    '<input type="text" id="destAccount" class="ussd-input" placeholder="Account number or phone number">' +
                    '<button class="submit-btn" onclick="submitSingleDestDetails()">Continue</button>';
            }
            detailHtml += '<button class="back-btn" onclick="goTo(\'single_dest_action\')">← Back</button>';
            html = detailHtml;
            break;
            
        case 'single_confirm':
            const srcInst2 = allParticipants.find(function(p) { return p.code === state.tempSource.code; });
            const dstInst2 = allParticipants.find(function(p) { return p.code === state.tempDest.institution; });
            html = '<div class="question">📋 Confirm Swap</div>' +
                '<div class="summary-card">' +
                '<div class="summary-row"><span>From</span><span>' + (srcInst2 ? srcInst2.name : '') + ' • ' + state.tempSource.asset_type + '</span></div>' +
                '<div class="summary-row"><span>Amount</span><span>' + parseFloat(state.tempSource.amount).toFixed(2) + '</span></div>' +
                '<div class="summary-row"><span>To</span><span>' + (dstInst2 ? dstInst2.name : '') + '</span></div>' +
                '<div class="summary-row"><span>Destination</span><span>' + (state.tempDest.action === 'cashout' ? '💰 Cashout to ' + escapeHtml(state.tempDest.value) : '🏦 ' + escapeHtml(state.tempDest.value)) + '</span></div>' +
                '</div>' +
                '<div style="display:flex; gap:12px; margin-top:20px;">' +
                '<button class="submit-btn" style="flex:1;" onclick="executeSingleSwap(false)">✅ Swap</button>' +
                '<button class="submit-btn secondary-btn" style="flex:1;" onclick="executeSingleSwap(true)">💾 Swap & Save</button>' +
                '</div>' +
                '<button class="back-btn" onclick="goTo(\'single_dest_details\')">← Edit</button>';
            break;
            
        case 'multi_source_list':
            let msHtml = '<div class="question">📋 Sources to Combine</div>';
            if (state.sources.length > 0) {
                let total = 0;
                for (let i = 0; i < state.sources.length; i++) {
                    const src = state.sources[i];
                    total += src.amount;
                    msHtml += '<div class="multi-item">' +
                        '<span>' + getAssetIcon(src.asset_type) + ' ' + src.institution_name + ' • ' + src.asset_type + ' • ' + src.amount + '</span>' +
                        '<button class="remove-btn" onclick="removeSource(' + i + ')"><i class="fas fa-trash"></i></button>' +
                        '</div>';
                }
                msHtml += '<div class="info-text">Total: ' + total + '</div>';
            } else {
                msHtml += '<div class="info-text">No sources added</div>';
            }
            msHtml += '<button class="add-btn" onclick="addMultiSource()">+ Add Source</button>';
            if (state.sources.length > 0) {
                msHtml += '<button class="submit-btn" style="margin-top:12px;" onclick="goToMultiDest()">Continue to Destination →</button>';
            }
            msHtml += '<button class="back-btn" onclick="goTo(\'init\')">← Back</button>';
            html = msHtml;
            break;
            
        case 'multi_source_add':
            html = '<div class="question">Add Source</div><div class="options">' +
                '<button class="option-btn" onclick="useSavedForMulti()">🔗 Use Saved Source</button>' +
                '<button class="option-btn" onclick="manualForMulti()">📝 Enter Manually</button>' +
                '<button class="back-btn" onclick="goTo(\'multi_source_list\')">← Back</button>' +
                '</div>';
            break;
            
        case 'multi_source_select_country':
            let msCountryHtml = '<div class="question">Select Source Country</div><div class="options">';
            const msCountriesSet = {};
            for (let i = 0; i < allParticipants.length; i++) {
                msCountriesSet[allParticipants[i].country] = true;
            }
            const msCountries = Object.keys(msCountriesSet);
            for (let i = 0; i < msCountries.length; i++) {
                msCountryHtml += '<button class="option-btn" onclick="multiSourceCountry(\'' + msCountries[i] + '\')">' +
                    '<span>' + msCountries[i] + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            msCountryHtml += '<button class="back-btn" onclick="goTo(\'multi_source_add\')">← Back</button></div>';
            html = msCountryHtml;
            break;
            
        case 'multi_source_select_institution':
            let msInstHtml = '<div class="question">Select Institution</div><div class="options">';
            const msInsts = getParticipantsByCountry(state.tempSource.country);
            for (let i = 0; i < msInsts.length; i++) {
                msInstHtml += '<button class="option-btn" onclick="multiSourceInstitution(\'' + msInsts[i].code + '\')">' +
                    '<span>🏛️ ' + msInsts[i].name + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            msInstHtml += '<button class="back-btn" onclick="goTo(\'multi_source_select_country\')">← Back</button></div>';
            html = msInstHtml;
            break;
            
        case 'multi_source_select_asset':
            const msInstObj = allParticipants.find(function(p) { return p.code === state.tempSource.code; });
            let msAssetHtml = '<div class="question">Select Asset Type</div><div class="options">';
            const msAssetTypes = msInstObj ? msInstObj.asset_types : [];
            for (let i = 0; i < msAssetTypes.length; i++) {
                msAssetHtml += '<button class="option-btn" onclick="multiSourceAsset(\'' + msAssetTypes[i] + '\')">' +
                    '<span>' + getAssetIcon(msAssetTypes[i]) + ' ' + msAssetTypes[i] + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            msAssetHtml += '<button class="back-btn" onclick="goTo(\'multi_source_select_institution\')">← Back</button></div>';
            html = msAssetHtml;
            break;
            
        case 'multi_source_form':
            let msFormHtml = '';
            const msAsset = state.tempSource.asset_type;
            if (msAsset === 'VOUCHER') {
                msFormHtml = '<input type="text" id="msVoucherNumber" class="ussd-input" placeholder="Voucher number">' +
                             '<input type="password" id="msVoucherPin" class="ussd-input" placeholder="PIN">';
            } else if (msAsset === 'ACCOUNT') {
                msFormHtml = '<input type="text" id="msAccountNumber" class="ussd-input" placeholder="Account number">' +
                             '<input type="password" id="msAccountPin" class="ussd-input" placeholder="PIN">';
            } else if (msAsset === 'CARD') {
                msFormHtml = '<input type="text" id="msCardNumber" class="ussd-input" placeholder="Card number">' +
                             '<input type="password" id="msCardPin" class="ussd-input" placeholder="PIN">';
            } else {
                msFormHtml = '<input type="tel" id="msWalletPhone" class="ussd-input" placeholder="Phone number">' +
                             '<input type="password" id="msWalletPin" class="ussd-input" placeholder="PIN">';
            }
            html = '<div class="question">Enter ' + msAsset + ' Details</div>' + msFormHtml +
                   '<input type="number" id="msAmount" class="ussd-input" placeholder="Amount" step="0.01" min="10">' +
                   '<button class="submit-btn" onclick="submitMultiSource()">Add Source</button>' +
                   '<button class="back-btn" onclick="goTo(\'multi_source_select_asset\')">← Back</button>';
            break;
            
        case 'multi_dest_country':
            let mdCountryHtml = '<div class="question">Select Destination Country</div><div class="options">';
            const mdCountriesSet = {};
            for (let i = 0; i < allParticipants.length; i++) {
                mdCountriesSet[allParticipants[i].country] = true;
            }
            const mdCountries = Object.keys(mdCountriesSet);
            for (let i = 0; i < mdCountries.length; i++) {
                mdCountryHtml += '<button class="option-btn" onclick="multiDestCountry(\'' + mdCountries[i] + '\')">' +
                    '<span>' + mdCountries[i] + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            mdCountryHtml += '<button class="back-btn" onclick="goTo(\'multi_source_list\')">← Back</button></div>';
            html = mdCountryHtml;
            break;
            
        case 'multi_dest_institution':
            html = '<div class="loading"><div class="spinner"></div><div>Loading...</div></div>';
            getInstitutionsByCountry(state.tempDest.country, function(insts) {
                state.destInstitutions = insts;
                goTo('multi_dest_institution_list');
            });
            return;
            
        case 'multi_dest_institution_list':
            let mdInstHtml = '<div class="question">Select Institution in ' + state.tempDest.country + '</div><div class="options">';
            for (let i = 0; i < (state.destInstitutions || []).length; i++) {
                const inst = state.destInstitutions[i];
                mdInstHtml += '<button class="option-btn" onclick="multiDestInstitution(\'' + inst.code + '\')">' +
                    '<span>🏛️ ' + inst.name + '</span>' +
                    '<i class="fas fa-chevron-right"></i></button>';
            }
            mdInstHtml += '<button class="back-btn" onclick="goTo(\'multi_dest_country\')">← Back</button></div>';
            html = mdInstHtml;
            break;
            
        case 'multi_dest_action':
            html = '<div class="question">📥 Choose Action</div><div class="options">' +
                '<button class="option-btn" onclick="multiDestAction(\'deposit\')">🏦 Deposit</button>' +
                '<button class="option-btn" onclick="multiDestAction(\'cashout\')">💰 Cashout</button>' +
                '</div><button class="back-btn" onclick="goTo(\'multi_dest_institution_list\')">← Back</button>';
            break;
            
        case 'multi_dest_details':
            let mdDetailHtml = '';
            if (state.tempDest.action === 'cashout') {
                mdDetailHtml = '<div class="question">💰 Cashout Details</div>' +
                    '<input type="tel" id="mdPhone" class="ussd-input" placeholder="Beneficiary phone number">' +
                    '<input type="number" id="mdAmount" class="ussd-input" placeholder="Amount" step="0.01" min="10">' +
                    '<button class="submit-btn" onclick="submitMultiDest()">Add Destination</button>';
            } else {
                mdDetailHtml = '<div class="question">🏦 Deposit Details</div>' +
                    '<input type="text" id="mdAccount" class="ussd-input" placeholder="Account number">' +
                    '<input type="number" id="mdAmount" class="ussd-input" placeholder="Amount" step="0.01" min="10">' +
                    '<button class="submit-btn" onclick="submitMultiDest()">Add Destination</button>';
            }
            mdDetailHtml += '<button class="back-btn" onclick="goTo(\'multi_dest_action\')">← Back</button>';
            html = mdDetailHtml;
            break;
            
        case 'multi_dest_list':
            let mdListHtml = '<div class="question">📋 Destinations to Split</div>';
            let totalAmount = 0;
            if (state.destinations.length > 0) {
                for (let i = 0; i < state.destinations.length; i++) {
                    const dest = state.destinations[i];
                    totalAmount += dest.amount;
                    mdListHtml += '<div class="multi-item">' +
                        '<span>' + (dest.action === 'cashout' ? '💰' : '🏦') + ' ' + dest.institution_name + ' • ' + dest.amount + '</span>' +
                        '<button class="remove-btn" onclick="removeDestination(' + i + ')"><i class="fas fa-trash"></i></button>' +
                        '</div>';
                }
                mdListHtml += '<div class="info-text">Total: ' + totalAmount + '</div>';
            }
            mdListHtml += '<button class="add-btn" onclick="addMultiDest()">+ Add Destination</button>';
            if (state.destinations.length > 0 && state.sources.length === 1) {
                mdListHtml += '<button class="submit-btn" style="margin-top:12px;" onclick="executeMultiDestSwap()">Execute Split Swap →</button>';
            }
            mdListHtml += '<button class="back-btn" onclick="goTo(\'init\')">← Back</button>';
            html = mdListHtml;
            break;
            
        case 'processing':
            html = '<div class="loading"><div class="spinner"></div><div>Processing...</div></div>';
            break;
            
        case 'result':
            const isSuccess = state.result && state.result.status === 'success';
            html = '<div class="result-screen">' +
                '<div class="result-icon ' + (isSuccess ? 'success' : 'error') + '">' + (isSuccess ? '✅' : '❌') + '</div>' +
                '<div style="font-size:18px;font-weight:700;">' + (isSuccess ? 'SWAP COMPLETED' : 'SWAP FAILED') + '</div>' +
                '<div class="result-message">' + (state.result ? (state.result.message || (isSuccess ? 'Success!' : 'Failed')) : '') + '</div>' +
                (state.result && state.result.swap_reference ? '<div style="font-size:11px;">Ref: ' + state.result.swap_reference.substring(0,16) + '...</div>' : '') +
                (state.result && state.result.withdrawal_code ? '<div class="info-text">💰 Code: ' + state.result.withdrawal_code + '</div>' : '') +
                '<button class="done-btn" onclick="reset()">Done</button>' +
                '</div>';
            break;
    }
    
    screen.innerHTML = html;
}

function goTo(step) { state.step = step; render(); }
function startSwap(mode) { state.swapMode = mode; state.sources = []; state.destinations = []; goTo(mode === 'single' ? 'single_select_country' : 'multi_source_list'); }

// Single source functions
function singleSelectCountry(country) { state.tempSource = { country: country }; goTo('single_select_institution'); }
function singleSelectInstitution(code) { state.tempSource.code = code; goTo('single_select_asset'); }
function singleSelectAsset(asset) { state.tempSource.asset_type = asset; goTo('single_source_form'); }
function submitSingleSourceForm() {
    const asset = state.tempSource.asset_type;
    let identifier = '';
    if (asset === 'VOUCHER') identifier = document.getElementById('voucherNumber')?.value;
    else if (asset === 'ACCOUNT') identifier = document.getElementById('accountNumber')?.value;
    else if (asset === 'CARD') identifier = document.getElementById('cardNumber')?.value;
    else identifier = document.getElementById('walletPhone')?.value;
    let pin = '';
    if (asset === 'VOUCHER') pin = document.getElementById('voucherPin')?.value;
    else if (asset === 'ACCOUNT') pin = document.getElementById('accountPin')?.value;
    else if (asset === 'CARD') pin = document.getElementById('cardPin')?.value;
    else pin = document.getElementById('walletPin')?.value;
    if (!identifier) { alert('Enter required fields'); return; }
    state.tempSource.identifier = identifier;
    state.tempSource.pin = pin;
    goTo('single_amount');
}
function submitSingleAmount() {
    const amount = document.getElementById('amountInput')?.value;
    if (!amount || parseFloat(amount) < 10) { alert('Enter valid amount (min 10)'); return; }
    state.tempSource.amount = parseFloat(amount);
    goTo('single_dest_country');
}
function singleDestCountry(country) { state.tempDest = { country: country }; goTo('single_dest_institution'); }
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
    const inst = allParticipants.find(function(p) { return p.code === state.tempSource.code; });
    state.sources.push({
        institution: state.tempSource.code,
        institution_name: inst ? inst.name : '',
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
function multiDestCountry(country) { state.tempDest = { country: country }; goTo('multi_dest_institution'); }
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
    const inst = allParticipants.find(function(p) { return p.code === state.tempDest.institution; });
    state.destinations.push({
        institution: state.tempDest.institution,
        institution_name: inst ? inst.name : '',
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
function executeMultiDestSwap() { alert('Multi-destination swap executed! Total: ' + state.destinations.reduce(function(s,d){ return s + d.amount; }, 0)); reset(); }

function useSavedSource(id, code, type) {
    if (state.swapMode === 'multi_source') {
        const inst = allParticipants.find(function(p) { return p.code === code; });
        state.sources.push({ institution: code, institution_name: inst ? inst.name : '', asset_type: type, identifier: null, amount: null });
        goTo('multi_source_list');
    } else {
        state.tempSource = { code: code, asset_type: type };
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
