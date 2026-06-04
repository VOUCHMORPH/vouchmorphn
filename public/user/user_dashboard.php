<?php
// public/user/user_dashboard.php - Calls YOUR VouchMorph API

ini_set('display_errors', 1);
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
$hasTransactionPin = $user['has_transaction_pin'] ?? false;

// ============================================================
// API BASE URL (YOUR EXISTING API)
// ============================================================
$apiBaseUrl = rtrim(getenv('VOUCHMORPH_API_URL') ?: 'https://vouchmorphn-production.up.railway.app', '/');

// ============================================================
// LOAD PARTICIPANTS FROM CONFIG (for UI display only)
// ============================================================
$allParticipants = [];
$destinationCountries = [];

$participantsPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/participants.json';

if (file_exists($participantsPath)) {
    $jsonContent = file_get_contents($participantsPath);
    $data = json_decode($jsonContent, true);
    
    if ($data && isset($data['participants'])) {
        foreach ($data['participants'] as $code => $participant) {
            $assetTypes = [];
            if (isset($participant['capabilities']['asset_types'])) {
                foreach ($participant['capabilities']['asset_types'] as $assetType) {
                    $assetTypes[] = [
                        'type' => $assetType,
                        'name' => ucfirst(strtolower(str_replace('_', ' ', $assetType))),
                        'icon' => getAssetIcon($assetType)
                    ];
                }
            }
            
            $allParticipants[$code] = [
                'code' => $code,
                'name' => $participant['name'] ?? $participant['provider_code'] ?? $code,
                'country' => $participant['country'] ?? $userCountry,
                'currency' => $participant['settlement']['currency'] ?? 'BWP',
                'asset_types' => $assetTypes,
                'status' => $participant['status'] ?? 'ACTIVE'
            ];
            
            $destinationCountries[$participant['country'] ?? $userCountry] = true;
        }
    }
}

function getAssetIcon($type) {
    $icons = [
        'ACCOUNT' => '🏦', 'MNO-WALLET' => '📱', 'BANK-WALLET' => '🏦',
        'CASHOUT-VOUCHER' => '🎫', 'AIRTIME' => '📞', 'ATM' => '🏧'
    ];
    return $icons[$type] ?? '📄';
}

$destinationCountries = array_keys($destinationCountries);
$userCurrency = 'BWP';

// ============================================================
// LOAD LINKED SOURCES
// ============================================================
$fundingSources = [];
try {
    $db = DBConnection::getInstance();
    $stmt = $db->prepare("SELECT id, institution_code, institution_name, source_type FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE'");
    $stmt->execute([':user_id' => $userId]);
    $fundingSources = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Error loading sources: " . $e->getMessage());
}

// ============================================================
// REFRESH PIN STATUS
// ============================================================
try {
    $db = DBConnection::getInstance();
    $stmt = $db->prepare("SELECT has_transaction_pin FROM users WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $userId]);
    $pinStatus = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($pinStatus) {
        $hasTransactionPin = (bool)$pinStatus['has_transaction_pin'];
    }
} catch (\Throwable $e) {}

// ============================================================
// AJAX HANDLERS - CALL YOUR EXISTING API
// ============================================================
if ($isAjax) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    // Get participants for UI
    if ($action === 'get_participants') {
        echo json_encode(['success' => true, 'participants' => array_values($allParticipants), 'countries' => $destinationCountries]);
        exit;
    }
    
    // Get linked sources
    if ($action === 'get_linked_sources') {
        $sources = [];
        foreach ($fundingSources as $fs) {
            $sources[] = [
                'id' => $fs['id'],
                'name' => $fs['institution_name'] ?? $fs['institution_code'],
                'type' => $fs['source_type']
            ];
        }
        echo json_encode(['success' => true, 'sources' => $sources]);
        exit;
    }
    
    // Set transaction PIN - call your Auth API
    if ($action === 'set_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            if (strlen($pin) !== 6 || !ctype_digit($pin)) throw new Exception('PIN must be 6 digits');
            
            $result = callVouchMorphApi('auth/set_pin', [
                'pin' => $pin,
                'user_id' => $userId
            ]);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Verify PIN - call your Auth API
    if ($action === 'verify_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            
            $result = callVouchMorphApi('auth/verify_pin', [
                'pin' => $pin,
                'user_id' => $userId
            ]);
            
            if ($result['status'] === 'success') {
                $_SESSION['pin_verified'] = true;
                $_SESSION['pin_verified_at'] = time();
            }
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Execute linked swap - call YOUR /api/v1/swap/execute.php
    if ($action === 'swap_linked') {
        try {
            if (!isset($_SESSION['pin_verified']) || $_SESSION['pin_verified_at'] < (time() - 300)) {
                throw new Exception('PIN verification required');
            }
            
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $destInstitution = $_POST['dest_institution'] ?? '';
            $destIdentifier = $_POST['dest_identifier'] ?? '';
            $destAction = $_POST['dest_action'] ?? 'deposit';
            
            // Get source details
            $db = DBConnection::getInstance();
            $stmt = $db->prepare("SELECT institution_code, source_type FROM user_funding_sources WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $sourceId, 'user_id' => $userId]);
            $source = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$source) throw new Exception('Source not found');
            
            // Call YOUR swap execute API
            $result = callVouchMorphApi('v1/swap/execute', [
                'source' => [
                    'institution' => $source['institution_code'],
                    'asset_type' => $source['source_type'],
                    'amount' => $amount
                ],
                'destination' => [
                    'institution' => $destInstitution,
                    'identifier' => $destIdentifier,
                    'delivery_mode' => $destAction
                ],
                'user_id' => $userId
            ]);
            
            unset($_SESSION['pin_verified']);
            unset($_SESSION['pin_verified_at']);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Execute ad-hoc swap - call YOUR /api/v1/swap/execute.php
    if ($action === 'swap_adhoc') {
        try {
            $amount = (float)($_POST['amount'] ?? 0);
            $sourceInstitution = $_POST['source_institution'] ?? '';
            $assetType = $_POST['asset_type'] ?? '';
            $sourceIdentifier = $_POST['source_identifier'] ?? '';
            $instPin = $_POST['inst_pin'] ?? '';
            $destInstitution = $_POST['dest_institution'] ?? '';
            $destIdentifier = $_POST['dest_identifier'] ?? '';
            $destAction = $_POST['dest_action'] ?? 'deposit';
            
            // Call YOUR swap execute API
            $result = callVouchMorphApi('v1/swap/execute', [
                'source' => [
                    'institution' => $sourceInstitution,
                    'asset_type' => $assetType,
                    'identifier' => $sourceIdentifier,
                    'pin' => $instPin,
                    'amount' => $amount
                ],
                'destination' => [
                    'institution' => $destInstitution,
                    'identifier' => $destIdentifier,
                    'delivery_mode' => $destAction
                ],
                'user_id' => $userId
            ]);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
    exit;
}

// ============================================================
// HELPER: Call YOUR VouchMorph API
// ============================================================
function callVouchMorphApi($endpoint, $data) {
    global $apiBaseUrl;
    
    $url = $apiBaseUrl . '/api/' . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Requested-With: XMLHttpRequest'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['status' => 'error', 'message' => 'API connection error: ' . $curlError];
    }
    
    if ($httpCode !== 200) {
        return ['status' => 'error', 'message' => "API returned HTTP {$httpCode}"];
    }
    
    $result = json_decode($response, true);
    return $result ?: ['status' => 'error', 'message' => 'Invalid API response'];
}

// Prepare data for JavaScript
$participantsJson = json_encode(array_values($allParticipants));
$countriesJson = json_encode($destinationCountries);
$sourcesJson = json_encode(array_map(function($s) { 
    return ['id' => $s['id'], 'name' => $s['institution_name'] ?? $s['institution_code'], 'type' => $s['source_type']]; 
}, $fundingSources));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VOUCHMORPH | SWAP DASHBOARD</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #000000; font-family: 'Space Grotesk', monospace; color: #FFFFFF; }
    
    .app { display: flex; min-height: 100vh; }
    .sidebar { width: 280px; background: #0a0a0a; border-right: 1px solid #1a1a1a; padding: 32px 24px; }
    .main { flex: 1; padding: 32px 48px; max-width: 700px; }
    .right-panel { width: 360px; background: #0a0a0a; border-left: 1px solid #1a1a1a; padding: 32px 24px; }
    
    .logo { font-size: 14px; letter-spacing: 4px; margin-bottom: 48px; color: rgba(255,255,255,0.5); }
    .logo strong { color: #FFFFFF; }
    
    .nav-item { display: block; width: 100%; background: transparent; border: none; padding: 14px 0; font-family: inherit; font-size: 13px; letter-spacing: 1px; color: rgba(255,255,255,0.5); cursor: pointer; text-align: left; border-bottom: 1px solid #1a1a1a; }
    .nav-item:hover { color: #FFFFFF; border-bottom-color: #FFFFFF; }
    
    .user-section { margin-top: auto; padding-top: 32px; border-top: 1px solid #1a1a1a; }
    .user-phone { font-size: 12px; color: rgba(255,255,255,0.3); margin-bottom: 8px; }
    .user-badge { font-size: 10px; color: #4CAF50; }
    
    .balance-label { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3); margin-bottom: 8px; text-transform: uppercase; }
    .balance-amount { font-size: 48px; font-weight: 500; letter-spacing: -2px; margin-bottom: 32px; }
    
    .primary-btn { width: 100%; background: #FFFFFF; border: none; padding: 16px 24px; font-family: inherit; font-size: 13px; font-weight: 500; letter-spacing: 2px; color: #000000; cursor: pointer; margin-top: 24px; }
    .primary-btn:hover { opacity: 0.9; }
    .secondary-btn { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.2); padding: 12px 20px; font-family: inherit; font-size: 12px; letter-spacing: 1px; color: #FFFFFF; cursor: pointer; margin-bottom: 12px; }
    .secondary-btn:hover { border-color: #FFFFFF; }
    
    .form-group { margin-bottom: 20px; }
    .form-label { font-size: 10px; letter-spacing: 1px; color: rgba(255,255,255,0.4); margin-bottom: 8px; display: block; text-transform: uppercase; }
    .form-input, .form-select { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 12px 16px; font-family: inherit; font-size: 14px; color: #FFFFFF; }
    .form-input:focus, .form-select:focus { outline: none; border-color: #FFFFFF; }
    .form-select option { background: #000000; }
    
    .radio-group { display: flex; gap: 24px; margin-top: 8px; }
    .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; }
    .radio-label input { accent-color: #FFFFFF; width: 16px; height: 16px; }
    
    .pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 24px 0; }
    .pin-btn { background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 16px; font-size: 20px; font-family: inherit; color: #FFFFFF; cursor: pointer; }
    .pin-btn:active { background: #FFFFFF; color: #000000; }
    .pin-dots { display: flex; justify-content: center; gap: 16px; margin: 24px 0; }
    .pin-dot { width: 12px; height: 12px; border: 1px solid rgba(255,255,255,0.3); }
    .pin-dot.filled { background: #FFFFFF; border-color: #FFFFFF; }
    
    .modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.98); z-index: 1000; display: none; align-items: center; justify-content: center; }
    .modal-content { width: 420px; max-width: 90%; background: #000000; border: 1px solid rgba(255,255,255,0.15); }
    .modal-header { padding: 24px 28px; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 16px; letter-spacing: 1px; text-transform: uppercase; }
    .modal-body { padding: 28px; }
    .modal-footer { padding: 20px 28px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; gap: 12px; justify-content: flex-end; }
    .modal-btn { background: transparent; border: 1px solid rgba(255,255,255,0.2); padding: 10px 20px; font-family: inherit; font-size: 11px; letter-spacing: 1px; color: rgba(255,255,255,0.6); cursor: pointer; }
    .modal-btn:hover { border-color: #FFFFFF; color: #FFFFFF; }
    .modal-btn-primary { background: #FFFFFF; border-color: #FFFFFF; color: #000000; }
    
    .error-modal .modal-content { border-color: #ff4444; }
    .error-modal .modal-header { color: #ff4444; }
    
    .step { display: none; }
    .step.active { display: block; }
    
    .source-item { padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.06); cursor: pointer; }
    .source-item:hover { background: rgba(255,255,255,0.03); padding-left: 8px; }
    .badge { font-size: 9px; padding: 4px 8px; margin-left: 8px; }
    .badge-deposit { background: rgba(76,175,80,0.2); border: 1px solid #4CAF50; color: #4CAF50; }
    .badge-cashout { background: rgba(255,152,0,0.2); border: 1px solid #FF9800; color: #FF9800; }
    .panel-title { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3); margin-bottom: 20px; text-transform: uppercase; }
</style>
</head>
<body>

<div class="app">
    <div class="sidebar">
        <div class="logo"><strong>VOUCHMORPH</strong> SWAP</div>
        <button class="nav-item" onclick="showStep('swap')">⟡ INITIATE SWAP</button>
        <button class="nav-item" onclick="showStep('sources')">🔗 LINKED SOURCES</button>
        <button class="nav-item" onclick="showStep('security')">🔒 SECURITY</button>
        <div class="user-section">
            <div class="user-phone"><?= vm_h(substr($userPhone, -10)) ?></div>
            <div class="user-badge" id="pinStatus">PIN: <?= $hasTransactionPin ? 'SET' : 'NOT SET' ?></div>
        </div>
    </div>
    
    <div class="main">
        <div class="balance-label">AVAILABLE BALANCE</div>
        <div class="balance-amount">0.00 <span class="balance-currency">BWP</span></div>
        
        <div id="stepSwap" class="step active">
            <div class="panel-title">⟡ NEW SWAP</div>
            
            <div class="form-group">
                <label class="form-label">SOURCE TYPE</label>
                <select id="sourceType" class="form-select" onchange="toggleSourceFields()">
                    <option value="linked">LINKED SOURCE (VM PIN)</option>
                    <option value="adhoc">AD-HOC (INSTITUTION CREDENTIALS)</option>
                </select>
            </div>
            
            <div id="linkedSourceSection">
                <div class="form-group">
                    <label class="form-label">SELECT LINKED SOURCE</label>
                    <select id="linkedSourceSelect" class="form-select">
                        <option value="">-- Select source --</option>
                    </select>
                </div>
            </div>
            
            <div id="adhocSourceSection" style="display: none;">
                <div class="form-group">
                    <label class="form-label">SOURCE INSTITUTION</label>
                    <select id="adhocInstitution" class="form-select">
                        <option value="">-- Select --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">ASSET TYPE</label>
                    <select id="adhocAssetType" class="form-select">
                        <option value="">-- Select --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">IDENTIFIER</label>
                    <input type="text" id="adhocIdentifier" class="form-input" placeholder="Account number / Phone number">
                </div>
                <div class="form-group">
                    <label class="form-label">INSTITUTION PIN</label>
                    <input type="password" id="adhocPin" class="form-input" placeholder="Your PIN">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">AMOUNT (BWP)</label>
                <input type="number" id="amount" class="form-input" placeholder="Minimum 10.00" step="0.01" min="10">
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION COUNTRY</label>
                <select id="destCountry" class="form-select">
                    <option value="">-- Select --</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION INSTITUTION</label>
                <select id="destInstitution" class="form-select">
                    <option value="">-- Select --</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION ACTION</label>
                <div class="radio-group">
                    <label class="radio-label"><input type="radio" name="destAction" value="deposit" checked> DEPOSIT</label>
                    <label class="radio-label"><input type="radio" name="destAction" value="cashout"> CASHOUT</label>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION IDENTIFIER</label>
                <input type="text" id="destIdentifier" class="form-input" placeholder="Account number / Phone number">
            </div>
            
            <button class="primary-btn" onclick="initiateSwap()">EXECUTE SWAP →</button>
        </div>
        
        <div id="stepSources" class="step">
            <div class="panel-title">🔗 LINKED SOURCES</div>
            <div id="sourcesList"></div>
            <button class="secondary-btn" style="margin-top: 24px;" onclick="alert('Link source via OAuth or manual entry')">+ LINK NEW SOURCE</button>
        </div>
        
        <div id="stepSecurity" class="step">
            <div class="panel-title">🔒 SECURITY</div>
            <button class="secondary-btn" onclick="showPinSetupModal()">SET TRANSACTION PIN</button>
            <button class="secondary-btn" onclick="logout()">LOGOUT</button>
        </div>
    </div>
    
    <div class="right-panel">
        <div class="panel-title">📋 INSTITUTIONS</div>
        <div id="institutionList" style="font-size: 12px; line-height: 1.8;"></div>
        <div style="margin-top: 24px;">
            <div class="panel-title">🔧 DEBUG</div>
            <div id="debugInfo" style="font-size: 10px; font-family: monospace; color: rgba(255,255,255,0.3);"></div>
        </div>
    </div>
</div>

<!-- PIN Setup Modal -->
<div id="pinModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">SET TRANSACTION PIN</div>
        <div class="modal-body">
            <div class="pin-dots" id="pinDots"></div>
            <div class="pin-pad" id="pinPad"></div>
            <div id="pinError" style="color: #ff4444; text-align: center; margin-top: 16px;"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closePinModal()">CANCEL</button>
        </div>
    </div>
</div>

<!-- PIN Verify Modal -->
<div id="pinVerifyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">VERIFY PIN</div>
        <div class="modal-body">
            <div class="pin-dots" id="verifyPinDots"></div>
            <div class="pin-pad" id="verifyPinPad"></div>
            <div id="verifyPinError" style="color: #ff4444; text-align: center; margin-top: 16px;"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" id="verifyPinCancel">CANCEL</button>
        </div>
    </div>
</div>

<!-- Error Modal -->
<div id="errorModal" class="modal error-modal">
    <div class="modal-content">
        <div class="modal-header">⚠️ ERROR</div>
        <div class="modal-body"><div id="errorMessage"></div></div>
        <div class="modal-footer"><button class="modal-btn modal-btn-primary" onclick="closeErrorModal()">OK</button></div>
    </div>
</div>

<!-- Success Modal -->
<div id="successModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">✓ SUCCESS</div>
        <div class="modal-body"><div id="successMessage"></div></div>
        <div class="modal-footer"><button class="modal-btn modal-btn-primary" onclick="closeSuccessModal()">OK</button></div>
    </div>
</div>

<script>
let participants = [], destinationCountries = [], linkedSources = [];
let hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;
let pendingSwapData = null;
let currentPinInput = '', verifyPinInput = '';

function debugLog(msg) { 
    console.log(msg); 
    const d = document.getElementById('debugInfo'); 
    if(d) d.innerHTML = `<div>${new Date().toLocaleTimeString()}: ${msg}</div>` + d.innerHTML; 
    if(d && d.children.length > 10) d.removeChild(d.lastChild);
}

function showError(msg) { document.getElementById('errorMessage').innerHTML = msg; document.getElementById('errorModal').style.display = 'flex'; }
function closeErrorModal() { document.getElementById('errorModal').style.display = 'none'; }
function showSuccess(msg) { document.getElementById('successMessage').innerHTML = msg; document.getElementById('successModal').style.display = 'flex'; }
function closeSuccessModal() { document.getElementById('successModal').style.display = 'none'; }

async function loadData() {
    debugLog('Loading data...');
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: 'action=get_participants' 
        });
        const data = await res.json();
        if(data.success) { 
            participants = data.participants; 
            destinationCountries = data.countries; 
            renderInstitutionList(); 
            renderSelects(); 
            debugLog(`Loaded ${participants.length} participants`);
        }
        
        const res2 = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: 'action=get_linked_sources' 
        });
        const data2 = await res2.json();
        if(data2.success) { 
            linkedSources = data2.sources; 
            renderLinkedSources(); 
            debugLog(`Loaded ${linkedSources.length} linked sources`);
        }
    } catch(e) { 
        debugLog('Load error: ' + e.message);
    }
}

function renderInstitutionList() {
    const container = document.getElementById('institutionList');
    if(container) container.innerHTML = participants.map(p => `<div>• ${p.name} (${p.country})</div>`).join('');
}

function renderSelects() {
    const adhocSelect = document.getElementById('adhocInstitution');
    if(adhocSelect) adhocSelect.innerHTML = '<option value="">-- Select --</option>' + participants.map(p => `<option value="${p.code}">${p.name}</option>`).join('');
    
    const destCountry = document.getElementById('destCountry');
    if(destCountry) destCountry.innerHTML = '<option value="">-- Select --</option>' + destinationCountries.map(c => `<option value="${c}">${c}</option>`).join('');
}

function renderLinkedSources() {
    const container = document.getElementById('sourcesList');
    const select = document.getElementById('linkedSourceSelect');
    if(container) container.innerHTML = linkedSources.map(s => `<div class="source-item" onclick="selectLinkedSource(${s.id}, '${s.name}')">${s.name} (${s.type})</div>`).join('');
    if(select) select.innerHTML = '<option value="">-- Select --</option>' + linkedSources.map(s => `<option value="${s.id}">${s.name}</option>`).join('');
}

function selectLinkedSource(id, name) {
    document.getElementById('linkedSourceSelect').value = id;
    showStep('swap');
    showSuccess(`Selected: ${name}`);
}

document.getElementById('destCountry')?.addEventListener('change', function() {
    const country = this.value;
    const destSelect = document.getElementById('destInstitution');
    const filtered = participants.filter(p => p.country === country);
    destSelect.innerHTML = '<option value="">-- Select --</option>' + filtered.map(p => `<option value="${p.code}">${p.name}</option>`).join('');
});

document.getElementById('adhocInstitution')?.addEventListener('change', function() {
    const inst = participants.find(p => p.code === this.value);
    const assetSelect = document.getElementById('adhocAssetType');
    if(inst && inst.asset_types) assetSelect.innerHTML = '<option value="">-- Select --</option>' + inst.asset_types.map(a => `<option value="${a.type}">${a.icon} ${a.name}</option>`).join('');
});

function toggleSourceFields() {
    const type = document.getElementById('sourceType').value;
    document.getElementById('linkedSourceSection').style.display = type === 'linked' ? 'block' : 'none';
    document.getElementById('adhocSourceSection').style.display = type === 'linked' ? 'none' : 'block';
}

function showStep(step) {
    ['swap', 'sources', 'security'].forEach(s => {
        document.getElementById(`step${s.charAt(0).toUpperCase() + s.slice(1)}`).classList.remove('active');
    });
    document.getElementById(`step${step.charAt(0).toUpperCase() + step.slice(1)}`).classList.add('active');
}

// PIN Setup
function renderPinDots() {
    const container = document.getElementById('pinDots');
    if(!container) return;
    container.innerHTML = '';
    for(let i=0; i<6; i++) container.innerHTML += `<div class="pin-dot ${i < currentPinInput.length ? 'filled' : ''}"></div>`;
}
function renderPinPad() {
    const container = document.getElementById('pinPad');
    if(!container) return;
    const nums = [1,2,3,4,5,6,7,8,9,'⌫',0,'CLR'];
    container.innerHTML = nums.map(n => `<button class="pin-btn" onclick="pinInput('${n}')">${n}</button>`).join('');
}
function pinInput(val) {
    if(val === '⌫') currentPinInput = currentPinInput.slice(0,-1);
    else if(val === 'CLR') currentPinInput = '';
    else if(currentPinInput.length < 6) currentPinInput += val;
    renderPinDots();
    if(currentPinInput.length === 6) savePin();
}
function showPinSetupModal() { currentPinInput = ''; renderPinDots(); renderPinPad(); document.getElementById('pinModal').style.display = 'flex'; }
function closePinModal() { document.getElementById('pinModal').style.display = 'none'; }
async function savePin() {
    debugLog('Setting PIN...');
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: `action=set_pin&pin=${currentPinInput}` 
        });
        const data = await res.json();
        if(data.status === 'success') { 
            hasTransactionPin = true; 
            document.getElementById('pinStatus').innerHTML = 'PIN: SET'; 
            closePinModal(); 
            showSuccess('PIN set successfully!');
        } else { 
            document.getElementById('pinError').innerHTML = data.message;
        }
    } catch(e) { 
        document.getElementById('pinError').innerHTML = 'Failed: ' + e.message;
    }
}

// PIN Verify
function renderVerifyPinDots() {
    const container = document.getElementById('verifyPinDots');
    if(!container) return;
    container.innerHTML = '';
    for(let i=0; i<6; i++) container.innerHTML += `<div class="pin-dot ${i < verifyPinInput.length ? 'filled' : ''}"></div>`;
}
function renderVerifyPinPad() {
    const container = document.getElementById('verifyPinPad');
    if(!container) return;
    const nums = [1,2,3,4,5,6,7,8,9,'⌫',0,'CLR'];
    container.innerHTML = nums.map(n => `<button class="pin-btn" onclick="verifyPinHandler('${n}')">${n}</button>`).join('');
}
function verifyPinHandler(val) {
    if(val === '⌫') verifyPinInput = verifyPinInput.slice(0,-1);
    else if(val === 'CLR') verifyPinInput = '';
    else if(verifyPinInput.length < 6) verifyPinInput += val;
    renderVerifyPinDots();
    if(verifyPinInput.length === 6) submitPinVerification();
}
function showPinVerifyModal(swapData) { 
    pendingSwapData = swapData; 
    verifyPinInput = ''; 
    renderVerifyPinDots(); 
    renderVerifyPinPad(); 
    document.getElementById('pinVerifyModal').style.display = 'flex'; 
}
function closePinVerifyModal() { 
    document.getElementById('pinVerifyModal').style.display = 'none'; 
    pendingSwapData = null; 
}
async function submitPinVerification() {
    debugLog('Verifying PIN...');
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: `action=verify_pin&pin=${verifyPinInput}` 
        });
        const data = await res.json();
        if(data.status === 'success') { 
            closePinVerifyModal(); 
            await executeSwap();
        } else { 
            document.getElementById('verifyPinError').innerHTML = data.message;
        }
    } catch(e) { 
        document.getElementById('verifyPinError').innerHTML = e.message;
    }
}
document.getElementById('verifyPinCancel')?.addEventListener('click', () => closePinVerifyModal());

// Swap Execution
async function initiateSwap() {
    const sourceType = document.getElementById('sourceType').value;
    const amount = parseFloat(document.getElementById('amount').value);
    const destCountry = document.getElementById('destCountry').value;
    const destInstitution = document.getElementById('destInstitution').value;
    const destAction = document.querySelector('input[name="destAction"]:checked').value;
    const destIdentifier = document.getElementById('destIdentifier').value;
    
    if(!amount || amount<10) { showError('Amount must be at least 10 BWP'); return; }
    if(!destCountry) { showError('Select destination country'); return; }
    if(!destInstitution) { showError('Select destination institution'); return; }
    if(!destIdentifier) { showError('Enter destination identifier'); return; }
    
    if(sourceType === 'linked') {
        const sourceId = document.getElementById('linkedSourceSelect').value;
        if(!sourceId) { showError('Select a linked source'); return; }
        if(!hasTransactionPin) { showError('Set transaction PIN first'); showPinSetupModal(); return; }
        showPinVerifyModal({ type:'linked', source_id:sourceId, amount, dest_institution:destInstitution, dest_identifier:destIdentifier, dest_action:destAction });
    } else {
        const sourceInstitution = document.getElementById('adhocInstitution').value;
        const assetType = document.getElementById('adhocAssetType').value;
        const sourceIdentifier = document.getElementById('adhocIdentifier').value;
        const instPin = document.getElementById('adhocPin').value;
        if(!sourceInstitution) { showError('Select source institution'); return; }
        if(!assetType) { showError('Select asset type'); return; }
        if(!sourceIdentifier) { showError('Enter identifier'); return; }
        if(!instPin) { showError('Enter institution PIN'); return; }
        await executeAdhocSwap(amount, sourceInstitution, assetType, sourceIdentifier, instPin, destInstitution, destIdentifier, destAction);
    }
}

async function executeSwap() {
    const data = pendingSwapData;
    debugLog(`Executing linked swap via API...`);
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: `action=swap_linked&source_id=${data.source_id}&amount=${data.amount}&dest_institution=${data.dest_institution}&dest_identifier=${data.dest_identifier}&dest_action=${data.dest_action}` 
        });
        const result = await res.json();
        debugLog('API Response: ' + JSON.stringify(result));
        if(result.status === 'success') { 
            showSuccess(`Swap complete!\nRef: ${result.swap_reference}\nAmount: ${data.amount} BWP`);
            document.getElementById('amount').value = '';
            document.getElementById('destIdentifier').value = '';
        } else { 
            showError(result.message); 
        }
    } catch(e) { 
        showError(e.message);
    }
}

async function executeAdhocSwap(amount, srcInst, assetType, srcId, pin, destInst, destId, action) {
    debugLog(`Executing ad-hoc swap via API...`);
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: `action=swap_adhoc&amount=${amount}&source_institution=${srcInst}&asset_type=${assetType}&source_identifier=${srcId}&inst_pin=${pin}&dest_institution=${destInst}&dest_identifier=${destId}&dest_action=${action}` 
        });
        const result = await res.json();
        debugLog('API Response: ' + JSON.stringify(result));
        if(result.status === 'success') { 
            showSuccess(`Swap complete!\nRef: ${result.swap_reference}\nAmount: ${amount} BWP`);
            document.getElementById('amount').value = '';
            document.getElementById('destIdentifier').value = '';
            document.getElementById('adhocIdentifier').value = '';
            document.getElementById('adhocPin').value = '';
        } else { 
            showError(result.message); 
        }
    } catch(e) { 
        showError(e.message);
    }
}

function logout() { window.location.href = 'logout.php'; }

// Initialize
loadData();
toggleSourceFields();
<?php if(!$hasTransactionPin) echo 'setTimeout(() => showPinSetupModal(), 1000);'; ?>
</script>
</body>
</html>
