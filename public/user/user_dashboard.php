<?php
// public/user/user_dashboard.php - VouchMorph Swap Dashboard
// Reads from participants.yaml and assets.yaml

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

function vm_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/bootstrap.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    if ($isAjax) { http_response_code(401); echo json_encode(['status' => 'error', 'message' => 'Session expired']); exit; }
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$userCountry = $user['country'] ?? 'Botswana';
$userFullName = $user['full_name'] ?? $user['username'] ?? 'User';

// Get PIN status
$hasTransactionPin = false;
try {
    $db = DBConnection::getInstance();
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasTransactionPin = !empty($userData['transaction_pin_hash']);
} catch (Throwable $e) {
    error_log("PIN status error: " . $e->getMessage());
}

// ============================================================
// LOAD YAML FILES
// ============================================================

function loadYamlFile($path) {
    if (!file_exists($path)) return [];
    
    $content = file_get_contents($path);
    $result = [];
    $lines = explode("\n", $content);
    $current = null;
    $currentSub = null;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        $indent = strlen($line) - strlen(ltrim($line));
        $trimmed = trim($line);
        
        // List item (array element) - handles "- value"
        if (preg_match('/^- (.+)$/', $trimmed, $matches)) {
            $value = trim($matches[1]);
            if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
            
            if ($currentSub && isset($result[$current][$currentSub]) && is_array($result[$current][$currentSub])) {
                $result[$current][$currentSub][] = $value;
            } elseif ($current && isset($result[$current]) && is_array($result[$current])) {
                $result[$current][] = $value;
            }
            continue;
        }
        
        // Key-value pair
        if (preg_match('/^([a-z_][a-z0-9_]*): ?(.*)$/', $trimmed, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            
            if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
            if ($value === 'true') $value = true;
            if ($value === 'false') $value = false;
            if (is_numeric($value) && $value !== '') $value = (float)$value;
            
            // Empty value with no indent = new section
            if ($value === '' && $indent === 0) {
                $current = $key;
                $currentSub = null;
                $result[$key] = [];
            } 
            // Indent 2 = property of current section
            elseif ($indent === 2 && $current) {
                $result[$current][$key] = $value;
                $currentSub = $key;
            }
            // Indent 4 = nested property
            elseif ($indent === 4 && $current && $currentSub) {
                if (!is_array($result[$current][$currentSub])) {
                    $result[$current][$currentSub] = [];
                }
                $result[$current][$currentSub][$key] = $value;
            }
            // Top level
            elseif ($indent === 0) {
                $result[$key] = $value;
            }
        }
    }
    
    return $result;
}

// Load from correct paths
$baseConfigPath = __DIR__ . '/../../src/Core/Config';
$countryConfigPath = $baseConfigPath . '/Countries/' . $userCountry;

$assets = loadYamlFile($baseConfigPath . '/assets.yaml');
$participantsRaw = loadYamlFile($countryConfigPath . '/participants.yaml');
$participants = $participantsRaw['participants'] ?? [];

// Load country registry
$countries = [];
$countryRegistryPath = $baseConfigPath . '/countries_registry.json';
if (file_exists($countryRegistryPath)) {
    $registryContent = file_get_contents($countryRegistryPath);
    $registryData = json_decode($registryContent, true);
    if ($registryData && isset($registryData['countries'])) {
        $countries = $registryData['countries'];
    }
}

// Build participants by country
$participantsByCountry = [];
$allAssetTypes = [];

foreach ($participants as $code => $data) {
    $countryCode = $data['country'] ?? 'BW';
    $countryName = $countryCode;
    foreach ($countries as $c) {
        if ($c['code'] === $countryCode) {
            $countryName = $c['name'];
            break;
        }
    }
    
    if (!isset($participantsByCountry[$countryName])) {
        $participantsByCountry[$countryName] = [];
    }
    
    $assetTypes = $data['asset_types'] ?? [];
    foreach ($assetTypes as $assetType) {
        $allAssetTypes[$assetType] = $assets[$assetType] ?? ['ui' => ['icon' => '📄', 'display_name' => $assetType]];
    }
    
    $participantsByCountry[$countryName][] = [
        'code' => $code,
        'name' => $data['name'] ?? $code,
        'type' => $data['type'] ?? 'BANK',
        'asset_types' => $assetTypes,
        'limits' => $data['limits'] ?? ['min_amount' => 10, 'max_amount' => 500000, 'currency' => 'BWP']
    ];
}

// Load linked sources
$fundingSources = [];
try {
    $db = DBConnection::getInstance();
    $stmt = $db->prepare("SELECT id, institution_code, institution_name, asset_type, identifier FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE'");
    $stmt->execute([':user_id' => $userId]);
    $fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Error loading sources: " . $e->getMessage());
}

// ============================================================
// AJAX HANDLERS
// ============================================================
if ($isAjax) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if ($action === 'get_config') {
        echo json_encode([
            'success' => true,
            'assets' => $assets,
            'participants_by_country' => $participantsByCountry,
            'countries' => $countries,
            'user_country' => $userCountry,
            'user_phone' => $userPhone,
            'has_pin' => $hasTransactionPin
        ]);
        exit;
    }
    
    if ($action === 'set_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            if (strlen($pin) !== 6 || !ctype_digit($pin)) throw new Exception('PIN must be 6 digits');
            
            $db = DBConnection::getInstance();
            $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET transaction_pin_hash = :pin WHERE user_id = :user_id");
            $stmt->execute([':pin' => $hashedPin, ':user_id' => $userId]);
            
            echo json_encode(['status' => 'success', 'message' => 'PIN set']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'verify_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            if (strlen($pin) !== 6 || !ctype_digit($pin)) throw new Exception('PIN must be 6 digits');
            
            $db = DBConnection::getInstance();
            $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userData || empty($userData['transaction_pin_hash'])) throw new Exception('PIN not set');
            if (!password_verify($pin, $userData['transaction_pin_hash'])) throw new Exception('Invalid PIN');
            
            $_SESSION['pin_verified'] = true;
            $_SESSION['pin_verified_at'] = time();
            
            echo json_encode(['status' => 'success', 'message' => 'PIN verified']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'execute_swap') {
        try {
            if (!isset($_SESSION['pin_verified']) || $_SESSION['pin_verified_at'] < (time() - 300)) {
                throw new Exception('PIN verification required');
            }
            
            $sourceParticipant = $_POST['source_participant'] ?? '';
            $sourceAsset = $_POST['source_asset'] ?? '';
            $sourceIdentifier = $_POST['source_identifier'] ?? '';
            $amount = (float)($_POST['amount'] ?? 0);
            $destParticipant = $_POST['dest_participant'] ?? '';
            $destDeliveryMode = $_POST['dest_delivery_mode'] ?? 'deposit';
            $destIdentifier = $_POST['dest_identifier'] ?? '';
            
            if ($amount <= 0) throw new Exception('Invalid amount');
            if (empty($sourceParticipant)) throw new Exception('Select source');
            if (empty($destParticipant)) throw new Exception('Select destination');
            if (empty($destIdentifier)) throw new Exception('Enter destination identifier');
            
            $apiBaseUrl = rtrim(getenv('VOUCHMORPH_API_URL') ?: 'https://vouchmorph.up.railway.app', '/');
            $url = $apiBaseUrl . '/api/v1/swap/execute';
            
            $payload = [
                'source' => [
                    'institution' => $sourceParticipant,
                    'asset_type' => $sourceAsset,
                    'identifier' => $sourceIdentifier,
                    'amount' => $amount
                ],
                'destination' => [
                    'institution' => $destParticipant,
                    'identifier' => $destIdentifier,
                    'delivery_mode' => $destDeliveryMode
                ],
                'user_id' => $userId
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            unset($_SESSION['pin_verified']);
            
            if ($httpCode === 200) {
                echo json_encode(['status' => 'success', 'data' => json_decode($response, true)]);
            } else {
                echo json_encode(['status' => 'error', 'message' => "API error: HTTP {$httpCode}"]);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'logout') {
        SessionManager::destroy();
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VOUCHMORPH | SWAP</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #0a0a0a; font-family: 'Inter', sans-serif; color: #FFFFFF; }
    .container { max-width: 900px; width: 95%; margin: 0 auto; padding: 24px 0 48px; }
    
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 0;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        margin-bottom: 32px;
        flex-wrap: wrap;
        gap: 16px;
    }
    .logo { font-size: 14px; letter-spacing: 4px; color: rgba(255,255,255,0.4); }
    .logo span { color: #FFFFFF; font-weight: 600; }
    .user-area { display: flex; align-items: center; gap: 16px; }
    .user-info { text-align: right; }
    .user-name { font-size: 13px; font-weight: 500; }
    .user-phone { font-size: 11px; color: rgba(255,255,255,0.4); margin-top: 2px; }
    .pin-badge {
        font-size: 9px; padding: 4px 10px; background: rgba(76,175,80,0.15);
        border: 1px solid rgba(76,175,80,0.3); border-radius: 20px; color: #4CAF50; cursor: pointer;
    }
    .pin-badge.not-set { background: rgba(255,152,0,0.15); border-color: rgba(255,152,0,0.3); color: #FF9800; }
    
    .swap-card {
        background: rgba(255,255,255,0.02);
        border: 1px solid rgba(255,255,255,0.05);
        padding: 28px;
        margin-bottom: 24px;
    }
    
    .source-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 28px;
        padding-bottom: 28px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .source-col { flex: 1; min-width: 180px; }
    .source-label {
        font-size: 9px; letter-spacing: 1.5px; color: rgba(255,255,255,0.3);
        margin-bottom: 8px; text-transform: uppercase;
    }
    .arrow-col {
        display: flex; align-items: center; justify-content: center;
        color: rgba(255,255,255,0.15); font-size: 24px; padding-top: 20px;
    }
    
    .form-select, .form-input {
        width: 100%; background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.1); padding: 12px 14px;
        font-family: inherit; font-size: 14px; color: #FFFFFF; cursor: pointer;
    }
    .form-select:focus, .form-input:focus { outline: none; border-color: rgba(255,255,255,0.3); }
    .form-select option { background: #0a0a0a; }
    .form-input::placeholder { color: rgba(255,255,255,0.2); }
    .field-hint { font-size: 9px; color: rgba(255,255,255,0.25); margin-top: 6px; }
    
    .amount-row {
        background: rgba(255,255,255,0.02); padding: 20px; margin: 24px 0;
        border-left: 3px solid #FFFFFF;
    }
    .amount-input { font-size: 28px; font-weight: 500; text-align: center; padding: 16px; }
    
    .destination-section { margin-top: 16px; }
    .section-title {
        font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3);
        margin-bottom: 20px; text-transform: uppercase; display: flex; align-items: center; gap: 8px;
    }
    .section-title::before { content: ''; width: 24px; height: 1px; background: rgba(255,255,255,0.2); }
    
    .form-row { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; }
    .form-group { flex: 1; min-width: 200px; }
    .form-label {
        font-size: 10px; letter-spacing: 0.5px; color: rgba(255,255,255,0.4);
        margin-bottom: 6px; display: block;
    }
    .form-label .required { color: #f44336; margin-left: 4px; }
    
    .radio-group { display: flex; gap: 24px; margin-top: 6px; flex-wrap: wrap; }
    .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; }
    .radio-label input { accent-color: #FFFFFF; width: 16px; height: 16px; }
    
    .submit-btn {
        width: 100%; background: #FFFFFF; border: none; padding: 16px;
        font-family: inherit; font-size: 13px; font-weight: 600; letter-spacing: 2px;
        color: #000000; cursor: pointer; margin-top: 24px; transition: opacity 0.2s;
    }
    .submit-btn:hover { opacity: 0.9; }
    .submit-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    
    .security-section {
        display: flex; justify-content: space-between; gap: 16px;
        margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.05);
    }
    .security-btn {
        background: transparent; border: 1px solid rgba(255,255,255,0.15);
        padding: 10px 20px; font-family: inherit; font-size: 11px;
        letter-spacing: 1px; color: #FFFFFF; cursor: pointer;
    }
    .security-btn:hover { border-color: rgba(255,255,255,0.4); }
    
    .modal {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.98); z-index: 1000; display: none;
        align-items: center; justify-content: center;
    }
    .modal-content { width: 360px; background: #0a0a0a; border: 1px solid rgba(255,255,255,0.1); }
    .modal-header { padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 14px; text-align: center; text-transform: uppercase; }
    .modal-body { padding: 28px; }
    .modal-footer { padding: 20px 24px; border-top: 1px solid rgba(255,255,255,0.05); display: flex; gap: 12px; justify-content: flex-end; }
    .modal-btn {
        background: transparent; border: 1px solid rgba(255,255,255,0.2);
        padding: 10px 20px; font-family: inherit; font-size: 11px;
        color: rgba(255,255,255,0.6); cursor: pointer;
    }
    .modal-btn-primary { background: #FFFFFF; border-color: #FFFFFF; color: #000000; }
    
    .pin-display {
        font-size: 28px; letter-spacing: 12px; font-family: monospace;
        background: rgba(255,255,255,0.03); padding: 16px; text-align: center;
    }
    .pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 20px; }
    .pin-btn {
        background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1);
        padding: 16px; font-size: 20px; font-family: inherit; color: #FFFFFF; cursor: pointer;
    }
    .pin-btn:active { background: #FFFFFF; color: #000000; }
    .error-text { color: #f44336; text-align: center; margin-top: 16px; font-size: 11px; }
    
    .loading-spinner {
        display: inline-block; width: 14px; height: 14px;
        border: 2px solid rgba(0,0,0,0.2); border-top-color: #000000;
        border-radius: 50%; animation: spin 0.6s linear infinite;
        margin-right: 8px; vertical-align: middle;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    @media (max-width: 700px) {
        .swap-card { padding: 20px; }
        .source-row { flex-direction: column; gap: 12px; }
        .arrow-col { display: none; }
        .form-row { flex-direction: column; }
        .form-group { min-width: 100%; }
        .security-section { flex-direction: column; }
        .security-btn { width: 100%; text-align: center; }
    }
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="logo">V<span>OUCH</span>M<span>ORPH</span></div>
        <div class="user-area">
            <div class="user-info">
                <div class="user-name"><?= vm_h($userFullName) ?></div>
                <div class="user-phone"><?= vm_h($userPhone) ?></div>
            </div>
            <div class="pin-badge <?= $hasTransactionPin ? '' : 'not-set' ?>" id="pinBadge" onclick="if(!<?= $hasTransactionPin ? 'true' : 'false' ?>) showPinSetup()">
                <?= $hasTransactionPin ? '✓ PIN SET' : '⚠ SET PIN' ?>
            </div>
        </div>
    </div>
    
    <div class="swap-card">
        <!-- SOURCE ROW -->
        <div class="source-row">
            <div class="source-col">
                <div class="source-label">FROM</div>
                <select id="sourceParticipant" class="form-select"><option value="">Select institution</option></select>
            </div>
            <div class="source-col">
                <div class="source-label">ASSET TYPE</div>
                <select id="sourceAssetType" class="form-select"><option value="">Select asset type</option></select>
            </div>
            <div class="arrow-col">→</div>
            <div class="source-col">
                <div class="source-label">YOUR IDENTIFIER</div>
                <input type="text" id="sourceIdentifier" class="form-input" placeholder="Enter account number or phone number">
                <div class="field-hint" id="sourceHint"></div>
            </div>
        </div>
        
        <!-- AMOUNT -->
        <div class="amount-row">
            <div class="form-group" style="margin-bottom:0;">
                <div class="form-label">AMOUNT</div>
                <input type="number" id="amount" class="form-input amount-input" placeholder="0.00" step="0.01">
                <div class="field-hint" id="amountHint"></div>
            </div>
        </div>
        
        <!-- DESTINATION SECTION -->
        <div class="destination-section">
            <div class="section-title">DESTINATION</div>
            <div class="form-row">
                <div class="form-group">
                    <div class="form-label">COUNTRY</div>
                    <select id="destCountry" class="form-select"><option value="">-- Select country --</option></select>
                </div>
                <div class="form-group">
                    <div class="form-label">INSTITUTION</div>
                    <select id="destParticipant" class="form-select"><option value="">Select institution</option></select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <div class="form-label">ACTION</div>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="deliveryMode" value="deposit" checked> DEPOSIT</label>
                        <label class="radio-label"><input type="radio" name="deliveryMode" value="cashout"> CASHOUT</label>
                    </div>
                </div>
            </div>
            <div id="destinationFieldsContainer">
                <div class="form-group">
                    <div class="form-label">DESTINATION IDENTIFIER <span class="required">*</span></div>
                    <input type="text" id="destIdentifier" class="form-input" placeholder="Enter account number or phone number">
                    <div class="field-hint"></div>
                </div>
            </div>
        </div>
        
        <button class="submit-btn" onclick="executeSwap()" id="submitBtn">SEND →</button>
    </div>
    
    <div class="security-section">
        <button class="security-btn" onclick="showPinSetup()">🔒 Set Transaction PIN</button>
        <button class="security-btn" onclick="logout()">🚪 Logout</button>
    </div>
</div>

<!-- PIN SETUP MODAL -->
<div id="pinModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">SET TRANSACTION PIN</div>
        <div class="modal-body">
            <div class="pin-display" id="pinDisplay">••••••</div>
            <div class="pin-pad" id="pinPad"></div>
            <div id="pinError" class="error-text"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closePinModal()">CANCEL</button>
        </div>
    </div>
</div>

<!-- PIN VERIFY MODAL -->
<div id="pinVerifyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">VERIFY PIN</div>
        <div class="modal-body">
            <div class="pin-display" id="verifyPinDisplay">••••••</div>
            <div class="pin-pad" id="verifyPinPad"></div>
            <div id="verifyPinError" class="error-text"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closePinVerifyModal()">CANCEL</button>
            <button class="modal-btn modal-btn-primary" onclick="submitPinVerification()">VERIFY</button>
        </div>
    </div>
</div>

<script>
// ============================================================
// GLOBAL DATA - FROM YAML FILES
// ============================================================
let assets = {};
let participantsByCountry = {};
let countries = [];
let userCountry = '';
let userPhone = '';
let hasPin = false;
let currentPinInput = '';
let verifyPinInput = '';
let pendingSwapData = null;

// ============================================================
// LOAD CONFIGURATION
// ============================================================
async function loadConfig() {
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_config'
        });
        const data = await res.json();
        
        if (data.success) {
            assets = data.assets;
            participantsByCountry = data.participants_by_country;
            countries = data.countries;
            userCountry = data.user_country;
            userPhone = data.user_phone;
            hasPin = data.has_pin;
            
            populateSourceParticipants();
            populateCountryDropdown();
            
            console.log('Config loaded:', { assets, participantsByCountry });
        }
    } catch(e) {
        console.error('Load error:', e);
    }
}

// Populate source participants dropdown
function populateSourceParticipants() {
    const select = document.getElementById('sourceParticipant');
    let options = '<option value="">Select institution</option>';
    
    for (let country in participantsByCountry) {
        participantsByCountry[country].forEach(p => {
            let icon = '🏦';
            if (p.type === 'MNO') icon = '📱';
            if (p.type === 'ORCHESTRATOR') icon = '⚡';
            
            options += `<option value="${p.code}" 
                data-asset-types='${JSON.stringify(p.asset_types)}'
                data-limits='${JSON.stringify(p.limits)}'>
                ${icon} ${p.name} (${country})
            </option>`;
        });
    }
    
    select.innerHTML = options;
    select.onchange = onSourceParticipantChange;
}

// Populate country dropdown
function populateCountryDropdown() {
    const select = document.getElementById('destCountry');
    let options = '<option value="">-- Select country --</option>';
    
    if (countries.length > 0) {
        countries.forEach(c => {
            const selected = (c.name === userCountry || c.code === userCountry) ? 'selected' : '';
            options += `<option value="${c.name}" ${selected}>${c.flag || '🌍'} ${c.name}</option>`;
        });
    } else {
        for (let country in participantsByCountry) {
            options += `<option value="${country}">${country}</option>`;
        }
    }
    
    select.innerHTML = options;
    select.onchange = onDestCountryChange;
}

// ============================================================
// SOURCE SIDE - FROM YAML
// ============================================================
function onSourceParticipantChange() {
    const opt = document.getElementById('sourceParticipant').selectedOptions[0];
    const assetTypesList = JSON.parse(opt?.dataset?.assetTypes || '[]');
    const limits = JSON.parse(opt?.dataset?.limits || '{"min_amount":10,"max_amount":500000,"currency":"BWP"}');
    
    // Populate asset types dropdown
    const assetSelect = document.getElementById('sourceAssetType');
    let assetOptions = '<option value="">Select asset type</option>';
    
    assetTypesList.forEach(assetType => {
        const assetDef = assets[assetType];
        const icon = assetDef?.ui?.icon || '📄';
        const displayName = assetDef?.ui?.display_name || assetType;
        assetOptions += `<option value="${assetType}">${icon} ${displayName}</option>`;
    });
    
    assetSelect.innerHTML = assetOptions;
    assetSelect.onchange = onSourceAssetChange;
    
    // Update amount limits
    document.getElementById('amountHint').innerHTML = `Min: ${limits.min_amount} ${limits.currency} | Max: ${limits.max_amount.toLocaleString()} ${limits.currency}`;
    document.getElementById('amount').min = limits.min_amount;
    document.getElementById('amount').max = limits.max_amount;
    
    if (assetTypesList.length > 0) {
        onSourceAssetChange();
    }
}

function onSourceAssetChange() {
    const assetType = document.getElementById('sourceAssetType').value;
    if (!assetType) return;
    
    const assetDef = assets[assetType];
    const hintEl = document.getElementById('sourceHint');
    const inputEl = document.getElementById('sourceIdentifier');
    
    if (assetDef?.fields?.length) {
        const firstField = assetDef.fields[0];
        hintEl.innerHTML = firstField.hint || `Enter your ${firstField.label?.toLowerCase()}`;
        inputEl.placeholder = firstField.placeholder || `Enter ${firstField.label?.toLowerCase()}`;
        
        if (firstField.readonly && firstField.source === 'session.phone' && userPhone) {
            inputEl.value = userPhone;
            inputEl.readOnly = true;
        } else {
            inputEl.readOnly = false;
            inputEl.value = '';
        }
    } else {
        hintEl.innerHTML = 'Enter your identifier';
        inputEl.placeholder = 'Enter identifier';
    }
}

// ============================================================
// DESTINATION SIDE
// ============================================================
function onDestCountryChange() {
    const country = document.getElementById('destCountry').value;
    const destSelect = document.getElementById('destParticipant');
    
    let participants = participantsByCountry[country] || [];
    let options = '<option value="">Select institution</option>';
    
    participants.forEach(p => {
        let icon = '🏦';
        if (p.type === 'MNO') icon = '📱';
        if (p.type === 'ORCHESTRATOR') icon = '⚡';
        options += `<option value="${p.code}">${icon} ${p.name}</option>`;
    });
    
    destSelect.innerHTML = options;
}

// ============================================================
// EXECUTE SWAP
// ============================================================
async function executeSwap() {
    const sourceParticipant = document.getElementById('sourceParticipant').value;
    const sourceAsset = document.getElementById('sourceAssetType').value;
    const sourceIdentifier = document.getElementById('sourceIdentifier').value.trim();
    const amount = parseFloat(document.getElementById('amount').value);
    const destParticipant = document.getElementById('destParticipant').value;
    const deliveryMode = document.querySelector('input[name="deliveryMode"]:checked')?.value || 'deposit';
    const destIdentifier = document.getElementById('destIdentifier').value.trim();
    
    if (!sourceParticipant) { alert('Select source institution'); return; }
    if (!sourceAsset) { alert('Select asset type'); return; }
    if (!sourceIdentifier) { alert('Enter your source identifier'); return; }
    if (!amount || amount <= 0) { alert('Enter valid amount'); return; }
    if (!destParticipant) { alert('Select destination institution'); return; }
    if (!destIdentifier) { alert('Enter destination identifier'); return; }
    
    pendingSwapData = {
        source_participant: sourceParticipant,
        source_asset: sourceAsset,
        source_identifier: sourceIdentifier,
        amount: amount,
        dest_participant: destParticipant,
        dest_delivery_mode: deliveryMode,
        dest_identifier: destIdentifier
    };
    
    showPinVerifyModal();
}

async function submitPinVerification() {
    if (verifyPinInput.length !== 6) return;
    
    const errorEl = document.getElementById('verifyPinError');
    errorEl.innerHTML = '<span class="loading-spinner"></span> Verifying...';
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=verify_pin&pin=${verifyPinInput}`
        });
        const data = await res.json();
        
        if (data.status === 'success') {
            closePinVerifyModal();
            await executePendingSwap();
        } else {
            errorEl.innerHTML = data.message;
            verifyPinInput = '';
            updatePinDisplay('verifyPinDisplay', '');
        }
    } catch(e) {
        errorEl.innerHTML = e.message;
    }
}

async function executePendingSwap() {
    if (!pendingSwapData) return;
    
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner"></span> PROCESSING...';
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=execute_swap&` + new URLSearchParams({
                source_participant: pendingSwapData.source_participant,
                source_asset: pendingSwapData.source_asset,
                source_identifier: pendingSwapData.source_identifier,
                amount: pendingSwapData.amount,
                dest_participant: pendingSwapData.dest_participant,
                dest_delivery_mode: pendingSwapData.dest_delivery_mode,
                dest_identifier: pendingSwapData.dest_identifier
            }).toString()
        });
        const result = await res.json();
        
        if (result.status === 'success') {
            alert(`✓ Swap Complete!\nAmount: ${pendingSwapData.amount} BWP`);
            document.getElementById('sourceIdentifier').value = '';
            document.getElementById('amount').value = '';
            document.getElementById('destIdentifier').value = '';
            document.getElementById('sourceParticipant').value = '';
            document.getElementById('destParticipant').value = '';
            pendingSwapData = null;
        } else {
            alert(`❌ Failed: ${result.message}`);
        }
    } catch(e) {
        alert('Error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'SEND →';
    }
}

// ============================================================
// PIN MANAGEMENT
// ============================================================
function showPinSetup() {
    currentPinInput = '';
    renderPinPad('pinPad', pinInput);
    updatePinDisplay('pinDisplay', '');
    document.getElementById('pinModal').style.display = 'flex';
}

function closePinModal() {
    document.getElementById('pinModal').style.display = 'none';
    document.getElementById('pinError').innerHTML = '';
}

function pinInput(val) {
    if (val === '⌫') {
        currentPinInput = currentPinInput.slice(0, -1);
    } else if (val === 'CLR') {
        currentPinInput = '';
    } else if (currentPinInput.length < 6) {
        currentPinInput += val;
    }
    updatePinDisplay('pinDisplay', currentPinInput);
    if (currentPinInput.length === 6) savePin();
}

async function savePin() {
    if (currentPinInput.length !== 6) return;
    const errorEl = document.getElementById('pinError');
    errorEl.innerHTML = '<span class="loading-spinner"></span> Setting PIN...';
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=set_pin&pin=${currentPinInput}`
        });
        const data = await res.json();
        if (data.status === 'success') {
            closePinModal();
            document.getElementById('pinBadge').innerHTML = '✓ PIN SET';
            document.getElementById('pinBadge').classList.remove('not-set');
            alert('PIN set successfully!');
        } else {
            errorEl.innerHTML = data.message;
        }
    } catch(e) { errorEl.innerHTML = 'Failed'; }
}

function showPinVerifyModal() {
    verifyPinInput = '';
    renderPinPad('verifyPinPad', verifyPinHandler);
    updatePinDisplay('verifyPinDisplay', '');
    document.getElementById('verifyPinError').innerHTML = '';
    document.getElementById('pinVerifyModal').style.display = 'flex';
}

function closePinVerifyModal() {
    document.getElementById('pinVerifyModal').style.display = 'none';
    verifyPinInput = '';
    pendingSwapData = null;
}

function verifyPinHandler(val) {
    if (val === '⌫') {
        verifyPinInput = verifyPinInput.slice(0, -1);
    } else if (val === 'CLR') {
        verifyPinInput = '';
    } else if (verifyPinInput.length < 6) {
        verifyPinInput += val;
    }
    updatePinDisplay('verifyPinDisplay', verifyPinInput);
    if (verifyPinInput.length === 6) submitPinVerification();
}

function renderPinPad(containerId, handlerFunc) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const nums = [1,2,3,4,5,6,7,8,9,'⌫',0,'CLR'];
    container.innerHTML = nums.map(n => `<button class="pin-btn" onclick="(${handlerFunc.name})('${n}')">${n}</button>`).join('');
}

function updatePinDisplay(displayId, value) {
    const display = document.getElementById(displayId);
    if (display) display.innerHTML = value.padEnd(6, '•').split('').join(' ');
}

function logout() {
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: 'action=logout'
    }).then(() => window.location.href = 'login.php').catch(() => window.location.href = 'login.php');
}

// Make functions global
window.onSourceParticipantChange = onSourceParticipantChange;
window.onSourceAssetChange = onSourceAssetChange;
window.onDestCountryChange = onDestCountryChange;
window.executeSwap = executeSwap;
window.showPinSetup = showPinSetup;
window.closePinModal = closePinModal;
window.closePinVerifyModal = closePinVerifyModal;
window.submitPinVerification = submitPinVerification;
window.pinInput = pinInput;
window.verifyPinHandler = verifyPinHandler;
window.logout = logout;

// Initialize
loadConfig();

<?php if (!$hasTransactionPin): ?>
setTimeout(() => {
    if (confirm('For security, please set your transaction PIN now.')) {
        showPinSetup();
    }
}, 1500);
<?php endif; ?>
</script>
</body>
</html>
