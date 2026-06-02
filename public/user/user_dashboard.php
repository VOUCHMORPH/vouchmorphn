<?php
// public/user/user_dashboard.php - COMPLETE REDESIGN with Transaction PIN Security

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
$hasTransactionPin = $user['has_transaction_pin'] ?? false;

// ============================================================
// FIX: Ensure phone number is available
// ============================================================
error_log("=== DASHBOARD SESSION DEBUG ===");
error_log("Raw user data: " . json_encode($user));

$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    if ($isAjax) vm_json_response(['status' => 'error', 'message' => 'Database error']);
    die("System error");
}

// If phone is missing but we have user ID, fetch from database
if (empty($userPhone) && !empty($userId)) {
    error_log("Phone missing in session, fetching from users table for ID: " . $userId);
    
    try {
        $stmt = $db->prepare("SELECT phone, country, has_transaction_pin FROM users WHERE user_id = :user_id OR id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $userData = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($userData) {
            $userPhone = $userData['phone'];
            $userCountry = $userData['country'] ?? $userCountry;
            $hasTransactionPin = (bool)($userData['has_transaction_pin'] ?? false);
            error_log("Found in database - Phone: " . $userPhone . ", Has PIN: " . ($hasTransactionPin ? 'Yes' : 'No'));
            
            // Update the session with the missing data
            $user['phone'] = $userPhone;
            $user['country'] = $userCountry;
            $user['has_transaction_pin'] = $hasTransactionPin;
            SessionManager::setUser($user);
            error_log("Session updated");
        }
    } catch (\Throwable $e) {
        error_log("Error fetching user from DB: " . $e->getMessage());
    }
}

// ============================================================
// LOAD PARTICIPANTS FROM COUNTRY JSON FILES
// ============================================================
function loadParticipantsFromJson($countryName) {
    $path = __DIR__ . "/../../src/Core/Config/Countries/{$countryName}/participants.json";
    
    if (!file_exists($path)) {
        error_log("[Dashboard] File not found: " . $path);
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
        if (!isset($p['name']) || empty($p['name'])) {
            $p['name'] = $code;
        }
    }
    
    return $participants;
}

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

$sourceParticipants = loadParticipantsFromJson($userCountry);

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

// Load user's saved sources from database
$fundingSources = [];
$stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE'");
$stmt->execute([':user_id' => $userId]);
$fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getAssetTypes($participant) {
    return $participant['capabilities']['asset_types'] ?? [];
}

// ============================================================
// TRANSACTION PIN MANAGEMENT FUNCTIONS
// ============================================================

// Check if user has transaction PIN set
function userHasTransactionPin($db, $userId) {
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return !empty($result['transaction_pin_hash']);
}

// Verify transaction PIN
function verifyTransactionPin($db, $userId, $pin) {
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || empty($result['transaction_pin_hash'])) {
        return false;
    }
    
    return password_verify($pin, $result['transaction_pin_hash']);
}

// Set transaction PIN
function setTransactionPin($db, $userId, $pin) {
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET transaction_pin_hash = :hash, has_transaction_pin = 1 WHERE user_id = :user_id");
    return $stmt->execute([':hash' => $hash, ':user_id' => $userId]);
}

// ============================================================
// AJAX HANDLERS
// ============================================================
if ($isAjax) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    // Check if user has transaction PIN
    if ($action === 'check_transaction_pin') {
        $hasPin = userHasTransactionPin($db, $userId);
        vm_json_response(['has_pin' => $hasPin]);
    }
    
    // Set transaction PIN (first time)
    if ($action === 'set_transaction_pin') {
        try {
            $pin = trim($_POST['pin'] ?? '');
            $confirmPin = trim($_POST['confirm_pin'] ?? '');
            
            if (strlen($pin) !== 6 || !ctype_digit($pin)) {
                throw new Exception('PIN must be 6 digits');
            }
            
            if ($pin !== $confirmPin) {
                throw new Exception('PINs do not match');
            }
            
            if (setTransactionPin($db, $userId, $pin)) {
                // Update session
                $user['has_transaction_pin'] = true;
                SessionManager::setUser($user);
                vm_json_response(['status' => 'success', 'message' => 'Transaction PIN set successfully']);
            } else {
                throw new Exception('Failed to set PIN');
            }
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    // Verify transaction PIN for sensitive operations
    if ($action === 'verify_transaction_pin') {
        try {
            $pin = trim($_POST['pin'] ?? '');
            $operation = $_POST['operation'] ?? 'swap';
            
            if (empty($pin)) {
                throw new Exception('PIN is required');
            }
            
            if (!verifyTransactionPin($db, $userId, $pin)) {
                throw new Exception('Invalid transaction PIN');
            }
            
            // Generate a short-lived consent token (valid for 5 minutes)
            $consentToken = bin2hex(random_bytes(32));
            $_SESSION['consent_token_' . $operation] = [
                'token' => $consentToken,
                'expires' => time() + 300 // 5 minutes
            ];
            
            vm_json_response([
                'status' => 'success',
                'message' => 'PIN verified',
                'consent_token' => $consentToken
            ]);
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    // Get destination institutions
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
    
    // Save source (requires transaction PIN verification)
    if ($action === 'save_source') {
        try {
            $consentToken = $_POST['consent_token'] ?? '';
            $operation = 'save_source';
            
            // Verify consent token
            if (!isset($_SESSION['consent_token_' . $operation]) || 
                $_SESSION['consent_token_' . $operation]['token'] !== $consentToken ||
                $_SESSION['consent_token_' . $operation]['expires'] < time()) {
                throw new Exception('Transaction authorization required or expired');
            }
            
            $institutionCode = trim($_POST['institution_code'] ?? '');
            $assetType = strtoupper(trim($_POST['asset_type'] ?? ''));
            $identifier = trim($_POST['identifier'] ?? '');
            
            $participant = $sourceParticipants[$institutionCode] ?? null;
            if (!$participant) throw new Exception("Institution not found");
            
            $maskedId = strlen($identifier) > 4 ? '••••' . substr($identifier, -4) : '••••';
            
            // Don't store actual credentials, just store a reference
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $encrypted = base64_encode(openssl_encrypt($identifier, 'AES-256-CBC', $encryptionKey, 0, substr($encryptionKey, 0, 16)));
            
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
            
            // Clear the consent token
            unset($_SESSION['consent_token_' . $operation]);
            
            vm_json_response(['status' => 'success', 'message' => 'Source saved!']);
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    // Execute swap (requires transaction PIN verification)
    if ($action === 'swap_single') {
        try {
            $consentToken = $_POST['consent_token'] ?? '';
            $operation = 'swap';
            
            // Verify consent token
            if (!isset($_SESSION['consent_token_' . $operation]) || 
                $_SESSION['consent_token_' . $operation]['token'] !== $consentToken ||
                $_SESSION['consent_token_' . $operation]['expires'] < time()) {
                throw new Exception('Transaction authorization required or expired');
            }
            
            $sourceType = strtoupper(trim($_POST['source_type'] ?? ''));
            $sourceInstitution = trim($_POST['source_institution'] ?? '');
            $sourceIdentifier = trim($_POST['source_identifier'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $destCountry = trim($_POST['dest_country'] ?? '');
            $destInstitution = trim($_POST['dest_institution'] ?? '');
            $destAction = trim($_POST['dest_action'] ?? '');
            $destValue = trim($_POST['dest_value'] ?? '');
            
            if ($amount < 10) throw new Exception('Minimum amount is 10.00');
            
            $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
            $withdrawalCode = $destAction === 'cashout' ? (string)random_int(100000, 999999) : null;
            
            // Log the transaction (you'll want a proper transaction table)
            error_log("SWAP EXECUTED: User $userId, Amount $amount, Ref $swapReference");
            
            // Clear the consent token
            unset($_SESSION['consent_token_' . $operation]);
            
            vm_json_response([
                'status' => 'success',
                'message' => $destAction === 'cashout' ? 'Withdrawal code generated' : 'Swap completed successfully',
                'swap_reference' => $swapReference,
                'withdrawal_code' => $withdrawalCode
            ]);
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    vm_json_response(['status' => 'error', 'message' => 'Invalid action']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph | Secure Swap Dashboard</title>
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
    .pin-input {
        font-family: 'Space Grotesk', monospace;
        font-size: 20px;
        letter-spacing: 8px;
        text-align: center;
    }
    .submit-btn {
        width: 100%; margin-top: 8px; padding: 15px; background: #00F0FF;
        border: none; color: #071018; font-weight: 900; font-size: 14px; cursor: pointer;
        transition: all 0.2s;
    }
    .submit-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,240,255,0.3);
    }
    .security-badge {
        display: inline-block;
        background: rgba(0,240,255,0.15);
        border: 1px solid rgba(0,240,255,0.3);
        padding: 4px 10px;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.5px;
        margin-bottom: 12px;
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
    .summary-card { background: rgba(255,255,255,0.035); border: 1px solid rgba(255,255,255,0.1); padding: 13px; border-radius: 0px; }
    .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .summary-row:last-child { border-bottom: 0; }
    .notice { border: 1px solid rgba(0,240,255,0.18); background: rgba(0,240,255,0.08); padding: 12px; font-size: 12px; margin-bottom: 14px; }
    .pin-dots {
        display: flex;
        justify-content: center;
        gap: 12px;
        margin: 20px 0;
    }
    .pin-dot {
        width: 16px;
        height: 16px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        transition: all 0.2s;
    }
    .pin-dot.filled {
        background: #00F0FF;
        box-shadow: 0 0 8px rgba(0,240,255,0.5);
    }
    .pin-numpad {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-top: 20px;
    }
    .numpad-btn {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        padding: 18px;
        font-size: 24px;
        font-weight: 600;
        color: #f7fbff;
        cursor: pointer;
        transition: all 0.1s;
        text-align: center;
    }
    .numpad-btn:active {
        background: #00F0FF;
        color: #071018;
        transform: scale(0.95);
    }
    .numpad-btn.delete {
        color: #ff4d5e;
    }
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>↔ VOUCHMORPH</h1>
        <div class="phone">👤 <?= vm_h(substr($userPhone, -6)) ?></div>
    </div>
    
    <div id="screen" class="screen">
        <div class="loading"><div class="spinner"></div><div>Loading dashboard...</div></div>
    </div>
    
    <div class="footer">
        <span>🔐 Transaction PIN required for all money movements</span>
        <span>🛡 Level 2 Security</span>
    </div>
</div>

<script>
// ============================================================
// DATA FROM PHP
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
const hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;

let pendingOperation = null;
let pendingData = null;
let currentConsentToken = null;

function getAssetIcon(type) {
    const icons = {'ACCOUNT':'🏦','VOUCHER':'🎫','ATM':'🏧','E-WALLET':'📱','CARD':'💳'};
    return icons[type] || '💰';
}

async function getDestinationInstitutions(country, callback) {
    const formData = new FormData();
    formData.append('action', 'get_destination_institutions');
    formData.append('country', country);
    
    const res = await fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    callback(data.institutions || []);
}

async function verifyTransactionPin(operation, data) {
    return new Promise((resolve, reject) => {
        pendingOperation = operation;
        pendingData = data;
        goTo('verify_pin');
        // The PIN entry will call resolveWithPin() when done
        window.resolvePin = (consentToken) => {
            resolve(consentToken);
        };
        window.rejectPin = () => {
            reject(new Error('PIN verification cancelled'));
        };
    });
}

async function executeWithPinVerification(operation, data) {
    try {
        const consentToken = await verifyTransactionPin(operation, data);
        data.consent_token = consentToken;
        return await executeOperation(operation, data);
    } catch (e) {
        return { status: 'error', message: e.message };
    }
}

async function executeOperation(operation, data) {
    const formData = new FormData();
    formData.append('action', operation);
    
    for (let key in data) {
        formData.append(key, data[key]);
    }
    
    const res = await fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    
    return await res.json();
}

let state = {
    step: 'init',
    swapMode: 'single',
    tempSource: {},
    tempDest: {},
    pinInput: '',
    maxPinLength: 6
};

async function checkAndSetupTransactionPin() {
    if (!hasTransactionPin) {
        goTo('setup_pin');
        return false;
    }
    return true;
}

function render() {
    const screen = document.getElementById('screen');
    let html = '';
    
    switch(state.step) {
        case 'init':
            html = `
                <div class="security-badge">🔐 LEVEL 2 SECURITY ACTIVE</div>
                <div class="question">What would you like to do?</div>
                <div class="subtitle">All transactions require your VouchMorph PIN</div>
                <div class="notice">📍 Your location: ${userCountry}</div>
                <div class="options">
                    <button class="option-btn" onclick="startSwap('single')">
                        <span>➡️ Send Money<small>Transfer or cashout</small></span>›
                    </button>
                    <button class="option-btn" onclick="goTo('saved_sources')">
                        <span>🔗 My Saved Sources</span>›
                    </button>
                    <button class="option-btn" onclick="goTo('link_source')">
                        <span>➕ Link New Source</span>›
                    </button>
                    <button class="option-btn" onclick="goTo('security_settings')">
                        <span>🔐 Security Settings</span>›
                    </button>
                </div>
            `;
            break;
            
        case 'setup_pin':
            html = `
                <div class="security-badge">🔐 FIRST TIME SETUP</div>
                <div class="question">Create Transaction PIN</div>
                <div class="subtitle">This 6-digit PIN will authorize all money movements</div>
                <div class="notice">⚠️ Never share this PIN with anyone. VouchMorph will never ask for it outside of transactions.</div>
                <div class="input-group">
                    <input type="password" id="newPin" class="ussd-input pin-input" maxlength="6" placeholder="••••••" inputmode="numeric">
                    <input type="password" id="confirmPin" class="ussd-input pin-input" maxlength="6" placeholder="Confirm PIN" inputmode="numeric">
                    <button class="submit-btn" onclick="submitTransactionPin()">Create PIN →</button>
                </div>
                <button class="back-btn" onclick="logout()">Logout</button>
            `;
            break;
            
        case 'verify_pin':
            const dots = [];
            for (let i = 0; i < state.maxPinLength; i++) {
                dots.push(`<div class="pin-dot ${i < state.pinInput.length ? 'filled' : ''}"></div>`);
            }
            html = `
                <div class="security-badge">🔐 TRANSACTION AUTHORIZATION</div>
                <div class="question">Enter VouchMorph PIN</div>
                <div class="subtitle">Required to ${pendingOperation === 'swap_single' ? 'complete this transaction' : 'link a new source'}</div>
                <div class="pin-dots">${dots.join('')}</div>
                <div class="pin-numpad" id="numpad">
                    ${[1,2,3,4,5,6,7,8,9].map(n => `<div class="numpad-btn" data-num="${n}">${n}</div>`).join('')}
                    <div class="numpad-btn" data-num="0">0</div>
                    <div class="numpad-btn delete" data-action="delete">⌫</div>
                    <div class="numpad-btn" data-action="clear">C</div>
                </div>
                <button class="back-btn" onclick="cancelPinVerification()">Cancel</button>
            `;
            break;
            
        case 'security_settings':
            html = `
                <div class="question">🔐 Security Settings</div>
                <div class="options">
                    <button class="option-btn" onclick="changeTransactionPin()">
                        <span>🔄 Change Transaction PIN</span>›
                    </button>
                    <button class="option-btn" onclick="goTo('init')">
                        <span>← Back to Dashboard</span>›
                    </button>
                </div>
            `;
            break;
            
        case 'change_pin':
            html = `
                <div class="question">Change Transaction PIN</div>
                <div class="input-group">
                    <input type="password" id="currentPin" class="ussd-input pin-input" maxlength="6" placeholder="Current PIN" inputmode="numeric">
                    <input type="password" id="newPin" class="ussd-input pin-input" maxlength="6" placeholder="New PIN (6 digits)" inputmode="numeric">
                    <input type="password" id="confirmPin" class="ussd-input pin-input" maxlength="6" placeholder="Confirm New PIN" inputmode="numeric">
                    <button class="submit-btn" onclick="submitPinChange()">Change PIN →</button>
                </div>
                <button class="back-btn" onclick="goTo('security_settings')">← Back</button>
            `;
            break;
            
        case 'saved_sources':
            if (fundingSources.length === 0) {
                html = '<div class="question">No saved sources</div><button class="back-btn" onclick="goTo(\'init\')">← Back</button>';
            } else {
                let opts = '';
                for (let i = 0; i < fundingSources.length; i++) {
                    const s = fundingSources[i];
                    opts += `<button class="option-btn" onclick="useSavedSource('${s.code}', '${s.type}')">
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
                linkHtml += '<div class="notice">No institutions found for your country.</div>';
            } else {
                for (let i = 0; i < sourceParticipants.length; i++) {
                    const inst = sourceParticipants[i];
                    linkHtml += `<button class="option-btn" onclick="initiateLinkSource('${inst.code}', '${inst.name.replace(/'/g, "\\'")}', ${JSON.stringify(inst.asset_types)})">
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
                <div class="security-badge">🔐 Requires Transaction PIN</div>
                <div class="input-group">
                    <select id="linkAssetType" class="ussd-select">
                        <option value="">Select asset type</option>
                        ${state.linkAssetTypes.map(t => `<option value="${t}">${getAssetIcon(t)} ${t}</option>`).join('')}
                    </select>
                    <input type="text" id="linkIdentifier" class="ussd-input" placeholder="Account/Phone/Card number">
                </div>
                <button class="submit-btn" onclick="submitLinkSource()">Continue →</button>
                <button class="back-btn" onclick="goTo('link_source')">← Back</button>
            `;
            break;
            
        // SWAP FLOW
        case 'select_source_institution':
            let instHtml = '<div class="question">Select Source</div><div class="options">';
            for (let i = 0; i < sourceParticipants.length; i++) {
                const inst = sourceParticipants[i];
                instHtml += `<button class="option-btn" onclick="selectSourceInstitution('${inst.code}')">
                    <span>🏛️ ${inst.name}</span>›
                </button>`;
            }
            instHtml += '<button class="back-btn" onclick="goTo(\'init\')">← Back</button>';
            html = instHtml;
            break;
            
        case 'select_source_asset':
            const sInst = sourceParticipants.find(p => p.code === state.tempSource.code);
            let assetHtml = `<div class="question">Select Asset Type</div><div class="options">`;
            const assetTypes = sInst?.asset_types || [];
            for (let i = 0; i < assetTypes.length; i++) {
                assetHtml += `<button class="option-btn" onclick="selectSourceAsset('${assetTypes[i]}')">
                    <span>${getAssetIcon(assetTypes[i])} ${assetTypes[i]}</span>›
                </button>`;
            }
            assetHtml += '<button class="back-btn" onclick="goTo(\'select_source_institution\')">← Back</button>';
            html = assetHtml;
            break;
            
        case 'source_form':
            let formHtml = '';
            const sAsset = state.tempSource.asset_type;
            if (sAsset === 'VOUCHER') {
                formHtml = '<input type="text" id="voucherNumber" class="ussd-input" placeholder="Voucher number">';
            } else if (sAsset === 'ACCOUNT') {
                formHtml = '<input type="text" id="accountNumber" class="ussd-input" placeholder="Account number">';
            } else if (sAsset === 'CARD') {
                formHtml = '<input type="text" id="cardNumber" class="ussd-input" placeholder="Card number">';
            } else {
                formHtml = '<input type="tel" id="walletPhone" class="ussd-input" placeholder="Phone number">';
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
            
        case 'select_destination_country':
            let countryHtml = '<div class="question">🌍 Select Country</div><div class="options">';
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
            let destInstHtml = `<div class="question">Select Institution</div><div class="options">`;
            for (let i = 0; i < (state.destInstitutions || []).length; i++) {
                const inst = state.destInstitutions[i];
                destInstHtml += `<button class="option-btn" onclick="selectDestInstitution('${inst.code}')">
                    <span>🏛️ ${inst.name}</span>›
                </button>`;
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
                    <select id="payoutMethod" class="ussd-select">
                        <option value="atm">🏧 ATM Withdrawal</option>
                        <option value="agent">🏪 Agent Cashout</option>
                    </select>
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
                <div class="security-badge">🔐 Will require Transaction PIN</div>
                <div class="summary-card">
                    <div class="summary-row"><span>From</span><span>${srcInstConfirm?.name || state.tempSource.code} • ${state.tempSource.asset_type}</span></div>
                    <div class="summary-row"><span>Amount</span><span>${parseFloat(state.tempSource.amount).toFixed(2)}</span></div>
                    <div class="summary-row"><span>To</span><span>${dstInstConfirm?.name || state.tempDest.institution}</span></div>
                    <div class="summary-row"><span>Destination</span><span>${state.tempDest.action === 'cashout' ? '💰 Cashout' : '🏦 Account Deposit'}</span></div>
                </div>
                ${isCrossBorder ? '<div class="info-text">🌍 Cross-border swap • Exchange rate applies</div>' : ''}
                <button class="submit-btn" onclick="executeSwap()">✅ Confirm & Authorize →</button>
                <button class="back-btn" onclick="goTo('destination_details')">← Edit</button>
            `;
            break;
            
        case 'processing':
            html = '<div class="loading"><div class="spinner"></div><div>Processing transaction...</div></div>';
            break;
            
        case 'result':
            const isSuccess = state.result?.status === 'success';
            html = `
                <div class="result-screen">
                    <div class="result-icon ${isSuccess ? 'success' : 'error'}">${isSuccess ? '✅' : '❌'}</div>
                    <div style="font-size:18px;font-weight:700;">${isSuccess ? 'TRANSACTION COMPLETE' : 'TRANSACTION FAILED'}</div>
                    <div class="result-message">${state.result?.message || ''}</div>
                    ${state.result?.swap_reference ? `<div style="font-size:11px; margin-top:10px;">Ref: ${state.result.swap_reference}</div>` : ''}
                    ${state.result?.withdrawal_code ? `<div class="info-text">💰 Withdrawal Code: ${state.result.withdrawal_code}</div>` : ''}
                    <button class="submit-btn" style="margin-top:20px;" onclick="reset()">Done</button>
                </div>
            `;
            break;
            
        default:
            html = '<div class="loading">Loading...</div>';
    }
    
    screen.innerHTML = html;
    
    // Attach numpad listeners if on PIN screen
    if (state.step === 'verify_pin') {
        attachNumpadListeners();
    }
}

function attachNumpadListeners() {
    document.querySelectorAll('.numpad-btn').forEach(btn => {
        btn.removeEventListener('click', handleNumpadClick);
        btn.addEventListener('click', handleNumpadClick);
    });
}

function handleNumpadClick(e) {
    const btn = e.currentTarget;
    const num = btn.dataset.num;
    const action = btn.dataset.action;
    
    if (num) {
        if (state.pinInput.length < state.maxPinLength) {
            state.pinInput += num;
            render();
        }
    } else if (action === 'delete') {
        state.pinInput = state.pinInput.slice(0, -1);
        render();
    } else if (action === 'clear') {
        state.pinInput = '';
        render();
    }
    
    // Auto-submit when PIN is complete
    if (state.pinInput.length === state.maxPinLength) {
        submitPinVerification();
    }
}

async function submitPinVerification() {
    if (state.pinInput.length !== state.maxPinLength) {
        alert('Please enter complete PIN');
        return;
    }
    
    goTo('processing');
    
    const result = await executeOperation('verify_transaction_pin', {
        pin: state.pinInput,
        operation: pendingOperation
    });
    
    if (result.status === 'success') {
        currentConsentToken = result.consent_token;
        state.pinInput = '';
        if (window.resolvePin) {
            window.resolvePin(currentConsentToken);
            window.resolvePin = null;
        }
        // Continue with the pending operation
        await completePendingOperation();
    } else {
        alert(result.message);
        state.pinInput = '';
        if (window.rejectPin) {
            window.rejectPin();
            window.rejectPin = null;
        }
        goTo('verify_pin');
    }
}

function cancelPinVerification() {
    if (window.rejectPin) {
        window.rejectPin();
        window.rejectPin = null;
    }
    state.pinInput = '';
    goTo('init');
}

async function completePendingOperation() {
    if (pendingOperation === 'swap_single') {
        const result = await executeOperation('swap_single', {
            ...pendingData,
            consent_token: currentConsentToken
        });
        state.result = result;
        goTo('result');
    } else if (pendingOperation === 'save_source') {
        const result = await executeOperation('save_source', {
            ...pendingData,
            consent_token: currentConsentToken
        });
        if (result.status === 'success') {
            alert('Source saved successfully!');
            location.reload();
        } else {
            alert('Error: ' + result.message);
            goTo('init');
        }
    }
    currentConsentToken = null;
    pendingOperation = null;
    pendingData = null;
}

async function submitTransactionPin() {
    const newPin = document.getElementById('newPin')?.value;
    const confirmPin = document.getElementById('confirmPin')?.value;
    
    if (!newPin || newPin.length !== 6 || !/^\d+$/.test(newPin)) {
        alert('PIN must be 6 digits');
        return;
    }
    
    if (newPin !== confirmPin) {
        alert('PINs do not match');
        return;
    }
    
    const result = await executeOperation('set_transaction_pin', {
        pin: newPin,
        confirm_pin: confirmPin
    });
    
    if (result.status === 'success') {
        alert('Transaction PIN created successfully!');
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

async function initiateLinkSource(code, name, assetTypes) {
    state.linkInstCode = code;
    state.linkInstName = name;
    state.linkAssetTypes = assetTypes;
    goTo('link_form');
}

async function submitLinkSource() {
    const assetType = document.getElementById('linkAssetType')?.value;
    const identifier = document.getElementById('linkIdentifier')?.value;
    
    if (!assetType || !identifier) {
        alert('Please fill all fields');
        return;
    }
    
    const result = await executeWithPinVerification('save_source', {
        institution_code: state.linkInstCode,
        asset_type: assetType,
        identifier: identifier
    });
    
    if (result.status === 'success') {
        alert('Source saved successfully!');
        location.reload();
    } else if (result.status === 'error') {
        alert('Error: ' + result.message);
        if (result.message.includes('authorization')) {
            goTo('init');
        }
    }
}

function goTo(step) { state.step = step; render(); }
function startSwap(mode) { state.swapMode = mode; goTo('select_source_institution'); }
function selectSourceInstitution(code) { state.tempSource.code = code; goTo('select_source_asset'); }
function selectSourceAsset(asset) { state.tempSource.asset_type = asset; goTo('source_form'); }

function submitSourceForm() {
    const asset = state.tempSource.asset_type;
    let identifier = '';
    if (asset === 'VOUCHER') identifier = document.getElementById('voucherNumber')?.value;
    else if (asset === 'ACCOUNT') identifier = document.getElementById('accountNumber')?.value;
    else if (asset === 'CARD') identifier = document.getElementById('cardNumber')?.value;
    else identifier = document.getElementById('walletPhone')?.value;
    
    if (!identifier) { alert('Enter required fields'); return; }
    state.tempSource.identifier = identifier;
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

async function executeSwap() {
    goTo('processing');
    
    const swapData = {
        source_type: state.tempSource.asset_type,
        source_institution: state.tempSource.code,
        source_identifier: state.tempSource.identifier,
        amount: state.tempSource.amount,
        dest_country: state.tempDest.country,
        dest_institution: state.tempDest.institution,
        dest_action: state.tempDest.action,
        dest_value: state.tempDest.value
    };
    
    const result = await executeWithPinVerification('swap_single', swapData);
    
    if (result.status === 'success') {
        state.result = result;
        goTo('result');
    } else {
        state.result = result;
        goTo('result');
    }
}

function useSavedSource(code, type) { 
    state.tempSource = { code: code, asset_type: type }; 
    goTo('enter_amount'); 
}

function goBackToSource() { goTo('source_form'); }

function changeTransactionPin() {
    goTo('change_pin');
}

async function submitPinChange() {
    const currentPin = document.getElementById('currentPin')?.value;
    const newPin = document.getElementById('newPin')?.value;
    const confirmPin = document.getElementById('confirmPin')?.value;
    
    if (!currentPin || currentPin.length !== 6) {
        alert('Enter current PIN');
        return;
    }
    
    if (!newPin || newPin.length !== 6 || !/^\d+$/.test(newPin)) {
        alert('New PIN must be 6 digits');
        return;
    }
    
    if (newPin !== confirmPin) {
        alert('New PINs do not match');
        return;
    }
    
    // Verify current PIN first
    const verifyResult = await executeOperation('verify_transaction_pin', {
        pin: currentPin,
        operation: 'change_pin'
    });
    
    if (verifyResult.status !== 'success') {
        alert('Current PIN is incorrect');
        return;
    }
    
    // Set new PIN
    const result = await executeOperation('set_transaction_pin', {
        pin: newPin,
        confirm_pin: confirmPin
    });
    
    if (result.status === 'success') {
        alert('PIN changed successfully!');
        goTo('security_settings');
    } else {
        alert('Error: ' + result.message);
    }
}

function logout() {
    window.location.href = 'logout.php';
}

function reset() { 
    state = { 
        step: 'init', 
        swapMode: 'single', 
        tempSource: {}, 
        tempDest: {},
        pinInput: '',
        maxPinLength: 6
    }; 
    currentConsentToken = null;
    pendingOperation = null;
    pendingData = null;
    render(); 
}

// Initialize
async function init() {
    console.log('Initializing dashboard...');
    await checkAndSetupTransactionPin();
    render();
}

init();
</script>
</body>
</html>
