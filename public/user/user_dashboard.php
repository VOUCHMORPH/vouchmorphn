<?php
// public/user/user_dashboard.php - FIXED with proper session handling

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
// DIRECT PARTICIPANT LOADER
// ============================================================
$allParticipants = [];
$destinationCountries = [];

$participantsPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/participants.json';

error_log("Loading participants from: " . $participantsPath);

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
                'base_url' => $participant['base_url'] ?? '',
                'category' => $participant['category'] ?? 'BANK',
                'status' => $participant['status'] ?? 'ACTIVE'
            ];
            
            $destCountry = $participant['country'] ?? $userCountry;
            $destinationCountries[$destCountry] = true;
        }
    }
} else {
    error_log("Participants file NOT FOUND at: " . $participantsPath);
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
$userCurrencySymbol = 'P';

// Database connection
try {
    $db = DBConnection::getInstance();
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    error_log("DB Error: " . $e->getMessage());
    $db = null;
}

// Refresh PIN status
if ($db && $userId) {
    try {
        $stmt = $db->prepare("SELECT has_transaction_pin FROM users WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $dbPinStatus = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($dbPinStatus) {
            $hasTransactionPin = (bool)$dbPinStatus['has_transaction_pin'];
            $user['has_transaction_pin'] = $hasTransactionPin;
            SessionManager::setUser($user);
        }
    } catch (\Throwable $e) {}
}

// Load linked sources
$fundingSources = [];
if ($db && $userId) {
    try {
        $stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE'");
        $stmt->execute([':user_id' => $userId]);
        $fundingSources = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

// PIN functions
function verifyTransactionPin($db, $userId, $pin) { 
    if (!$db) return false;
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1"); 
    $stmt->execute([':user_id' => $userId]); 
    $result = $stmt->fetch(\PDO::FETCH_ASSOC); 
    if (!$result || empty($result['transaction_pin_hash'])) return false; 
    return password_verify($pin, $result['transaction_pin_hash']); 
}

function setTransactionPin($db, $userId, $pin) { 
    if (!$db) return false;
    $hash = password_hash($pin, PASSWORD_DEFAULT); 
    $stmt = $db->prepare("UPDATE users SET transaction_pin_hash = :hash, has_transaction_pin = true WHERE user_id = :user_id"); 
    return $stmt->execute([':hash' => $hash, ':user_id' => $userId]); 
}

// AJAX Handlers
if ($isAjax) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    error_log("AJAX Action: " . $action);
    
    if ($action === 'get_participants') {
        echo json_encode(['success' => true, 'participants' => array_values($allParticipants), 'countries' => $destinationCountries]);
        exit;
    }
    
    if ($action === 'get_linked_sources') {
        $sources = [];
        foreach ($fundingSources as $fs) {
            $sources[] = ['id' => $fs['id'], 'name' => $fs['institution_name'], 'type' => $fs['source_type']];
        }
        echo json_encode(['success' => true, 'sources' => $sources]);
        exit;
    }
    
    if ($action === 'set_transaction_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            if (strlen($pin) !== 6 || !ctype_digit($pin)) throw new Exception('PIN must be 6 digits');
            if (setTransactionPin($db, $userId, $pin)) {
                $_SESSION['has_pin'] = true;
                echo json_encode(['status' => 'success', 'message' => 'PIN set successfully']);
            } else {
                throw new Exception('Failed to set PIN');
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'verify_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            error_log("Verifying PIN for user: " . $userId);
            
            if (verifyTransactionPin($db, $userId, $pin)) {
                $token = bin2hex(random_bytes(32));
                $_SESSION['swap_token'] = $token;
                $_SESSION['swap_token_expires'] = time() + 300;
                error_log("Token created: " . $token . " for user: " . $userId);
                echo json_encode(['status' => 'success', 'token' => $token]);
            } else {
                throw new Exception('Invalid PIN');
            }
        } catch (Exception $e) {
            error_log("PIN verification failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'swap') {
        try {
            $token = $_POST['token'] ?? '';
            error_log("Swap request - Token received: " . $token);
            error_log("Session token: " . ($_SESSION['swap_token'] ?? 'NOT SET'));
            error_log("Session expires: " . ($_SESSION['swap_token_expires'] ?? 'NOT SET'));
            error_log("Current time: " . time());
            
            if (!isset($_SESSION['swap_token'])) {
                throw new Exception('No swap session found. Please verify your PIN first.');
            }
            
            if ($_SESSION['swap_token'] !== $token) {
                error_log("Token mismatch: Session=" . $_SESSION['swap_token'] . " Received=" . $token);
                throw new Exception('Invalid session token. Please try again.');
            }
            
            if ($_SESSION['swap_token_expires'] < time()) {
                throw new Exception('Session expired. Please verify your PIN again.');
            }
            
            $amount = (float)($_POST['amount'] ?? 0);
            $sourceInstitution = $_POST['source_institution'] ?? '';
            $destInstitution = $_POST['dest_institution'] ?? '';
            $destIdentifier = $_POST['dest_identifier'] ?? '';
            
            error_log("Swap details - Amount: $amount, Source: $sourceInstitution, Dest: $destInstitution, Identifier: $destIdentifier");
            
            if ($amount < 10) throw new Exception('Minimum amount is 10.00');
            if (!$sourceInstitution) throw new Exception('Source institution required');
            if (!$destInstitution) throw new Exception('Destination institution required');
            if (!$destIdentifier) throw new Exception('Destination identifier required');
            
            $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
            
            // Clear the token after use (one-time use)
            unset($_SESSION['swap_token']);
            unset($_SESSION['swap_token_expires']);
            
            error_log("Swap completed successfully: " . $swapReference);
            
            echo json_encode(['status' => 'success', 'swap_reference' => $swapReference, 'message' => "Swap of BWP {$amount} completed"]);
        } catch (Exception $e) {
            error_log("Swap failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Invalid action: ' . $action]);
    exit;
}

$participantsJson = json_encode(array_values($allParticipants));
$countriesJson = json_encode($destinationCountries);
$sourcesJson = json_encode(array_map(function($s) { return ['id' => $s['id'], 'name' => $s['institution_name'], 'type' => $s['source_type']]; }, $fundingSources));
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
    
    /* Layout */
    .app { display: flex; min-height: 100vh; }
    .sidebar { width: 280px; background: #0a0a0a; border-right: 1px solid #1a1a1a; padding: 32px 24px; }
    .main { flex: 1; padding: 32px 48px; }
    .right-panel { width: 360px; background: #0a0a0a; border-left: 1px solid #1a1a1a; padding: 32px 24px; }
    
    /* Logo */
    .logo { font-size: 14px; letter-spacing: 4px; margin-bottom: 48px; color: rgba(255,255,255,0.5); }
    .logo strong { color: #FFFFFF; font-weight: 500; }
    
    /* Navigation */
    .nav-item { display: block; width: 100%; background: transparent; border: none; padding: 14px 0; font-family: inherit; font-size: 13px; letter-spacing: 1px; color: rgba(255,255,255,0.5); cursor: pointer; text-align: left; border-bottom: 1px solid #1a1a1a; transition: all 0.1s; }
    .nav-item:hover { color: #FFFFFF; border-bottom-color: #FFFFFF; }
    .nav-item.active { color: #FFFFFF; border-bottom-color: #FFFFFF; }
    
    /* User section */
    .user-section { margin-top: auto; padding-top: 32px; border-top: 1px solid #1a1a1a; }
    .user-phone { font-size: 12px; color: rgba(255,255,255,0.3); margin-bottom: 8px; }
    .user-badge { font-size: 10px; color: #4CAF50; }
    
    /* Balance */
    .balance-label { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3); margin-bottom: 8px; text-transform: uppercase; }
    .balance-amount { font-size: 48px; font-weight: 500; letter-spacing: -2px; margin-bottom: 32px; }
    .balance-currency { font-size: 14px; color: rgba(255,255,255,0.3); margin-left: 8px; }
    
    /* Buttons */
    .primary-btn { width: 100%; background: #FFFFFF; border: none; padding: 16px 24px; font-family: inherit; font-size: 13px; font-weight: 500; letter-spacing: 2px; color: #000000; cursor: pointer; margin-bottom: 12px; transition: opacity 0.1s; }
    .primary-btn:hover { opacity: 0.9; }
    .secondary-btn { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.2); padding: 16px 24px; font-family: inherit; font-size: 13px; letter-spacing: 2px; color: #FFFFFF; cursor: pointer; margin-bottom: 12px; transition: all 0.1s; }
    .secondary-btn:hover { border-color: #FFFFFF; }
    
    /* Forms */
    .form-group { margin-bottom: 20px; }
    .form-label { font-size: 10px; letter-spacing: 1px; color: rgba(255,255,255,0.4); margin-bottom: 8px; display: block; text-transform: uppercase; }
    .form-input, .form-select { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 14px 16px; font-family: inherit; font-size: 14px; color: #FFFFFF; }
    .form-input:focus, .form-select:focus { outline: none; border-color: #FFFFFF; }
    .form-select option { background: #000000; }
    
    /* PIN Pad */
    .pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 24px 0; }
    .pin-btn { background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 16px; font-size: 20px; font-family: inherit; color: #FFFFFF; cursor: pointer; transition: all 0.05s; }
    .pin-btn:active { background: #FFFFFF; color: #000000; }
    .pin-dots { display: flex; justify-content: center; gap: 16px; margin: 24px 0; }
    .pin-dot { width: 12px; height: 12px; border: 1px solid rgba(255,255,255,0.3); transition: all 0.1s; }
    .pin-dot.filled { background: #FFFFFF; border-color: #FFFFFF; }
    
    /* SHARP EDGE MODAL */
    .modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.98); z-index: 1000; display: none; align-items: center; justify-content: center; }
    .modal-content { width: 420px; max-width: 90%; background: #000000; border: 1px solid rgba(255,255,255,0.15); }
    .modal-header { padding: 24px 28px; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 16px; letter-spacing: 1px; text-transform: uppercase; }
    .modal-body { padding: 28px; }
    .modal-footer { padding: 20px 28px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; gap: 12px; justify-content: flex-end; }
    .modal-btn { background: transparent; border: 1px solid rgba(255,255,255,0.2); padding: 10px 20px; font-family: inherit; font-size: 11px; letter-spacing: 1px; color: rgba(255,255,255,0.6); cursor: pointer; }
    .modal-btn:hover { border-color: #FFFFFF; color: #FFFFFF; }
    .modal-btn-primary { background: #FFFFFF; border-color: #FFFFFF; color: #000000; }
    
    /* SHARP EDGE ERROR MODAL - Same style */
    .error-modal .modal-content { border-color: #ff4444; }
    .error-modal .modal-header { color: #ff4444; border-bottom-color: rgba(255,68,68,0.3); }
    .error-details { background: rgba(255,68,68,0.1); padding: 12px; margin-top: 16px; font-family: monospace; font-size: 11px; border-left: 2px solid #ff4444; word-break: break-all; }
    
    /* Panel */
    .panel-title { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3); margin-bottom: 20px; text-transform: uppercase; }
    .source-item { padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.06); cursor: pointer; }
    .source-item:hover { background: rgba(255,255,255,0.03); padding-left: 8px; }
    .source-name { font-size: 13px; font-weight: 500; }
    .source-type { font-size: 10px; color: rgba(255,255,255,0.3); margin-top: 4px; }
    
    .step { display: none; }
    .step.active { display: block; }
</style>
</head>
<body>

<div class="app">
    <!-- Sidebar -->
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
    
    <!-- Main Content -->
    <div class="main">
        <div class="balance-label">AVAILABLE BALANCE</div>
        <div class="balance-amount">0.00 <span class="balance-currency">BWP</span></div>
        
        <!-- STEP 1: SWAP -->
        <div id="stepSwap" class="step active">
            <div class="panel-title">⟡ NEW SWAP</div>
            
            <div class="form-group">
                <label class="form-label">SOURCE TYPE</label>
                <select id="sourceType" class="form-select" onchange="toggleSourceFields()">
                    <option value="linked">LINKED SOURCE (VM PIN)</option>
                    <option value="adhoc">AD-HOC (INSTITUTION CREDENTIALS)</option>
                </select>
            </div>
            
            <!-- Linked Source Section -->
            <div id="linkedSourceSection">
                <div class="form-group">
                    <label class="form-label">SELECT LINKED SOURCE</label>
                    <select id="linkedSourceSelect" class="form-select">
                        <option value="">-- Select source --</option>
                    </select>
                </div>
            </div>
            
            <!-- Ad-hoc Source Section -->
            <div id="adhocSourceSection" style="display: none;">
                <div class="form-group">
                    <label class="form-label">SOURCE INSTITUTION</label>
                    <select id="adhocInstitution" class="form-select">
                        <option value="">-- Select institution --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">ASSET TYPE</label>
                    <select id="adhocAssetType" class="form-select">
                        <option value="">-- Select asset type --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">IDENTIFIER (Phone/Account)</label>
                    <input type="text" id="adhocIdentifier" class="form-input" placeholder="e.g., 71234567">
                </div>
                <div class="form-group">
                    <label class="form-label">INSTITUTION PIN</label>
                    <input type="password" id="adhocPin" class="form-input" placeholder="Enter your PIN">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">AMOUNT (BWP)</label>
                <input type="number" id="swapAmount" class="form-input" placeholder="Minimum 10.00" step="0.01" min="10">
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION COUNTRY</label>
                <select id="destCountry" class="form-select">
                    <option value="">-- Select country --</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION INSTITUTION</label>
                <select id="destInstitution" class="form-select">
                    <option value="">-- Select institution --</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION IDENTIFIER</label>
                <input type="text" id="destIdentifier" class="form-input" placeholder="Account number / Phone number">
            </div>
            
            <button class="primary-btn" onclick="initiateSwap()">EXECUTE SWAP →</button>
        </div>
        
        <!-- STEP 2: LINKED SOURCES -->
        <div id="stepSources" class="step">
            <div class="panel-title">🔗 LINKED SOURCES</div>
            <div id="sourcesListContainer"></div>
            <button class="secondary-btn" style="margin-top: 24px;" onclick="showLinkModal()">+ LINK NEW SOURCE</button>
        </div>
        
        <!-- STEP 3: SECURITY -->
        <div id="stepSecurity" class="step">
            <div class="panel-title">🔒 SECURITY</div>
            <button class="secondary-btn" onclick="showPinSetupModal()">SET TRANSACTION PIN</button>
            <button class="secondary-btn" onclick="logout()">LOGOUT</button>
        </div>
    </div>
    
    <!-- Right Panel -->
    <div class="right-panel">
        <div class="panel-title">📋 AVAILABLE INSTITUTIONS</div>
        <div id="institutionList" style="font-size: 12px; color: rgba(255,255,255,0.5); line-height: 1.8;"></div>
        <div style="margin-top: 24px;">
            <div class="panel-title">🔧 DEBUG INFO</div>
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
            <div class="error-text" id="pinError" style="color: #ff4444; font-size: 12px; text-align: center;"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closePinModal()">CANCEL</button>
        </div>
    </div>
</div>

<!-- Link Source Modal -->
<div id="linkModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">LINK SOURCE</div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">INSTITUTION</label>
                <select id="linkInstitution" class="form-select">
                    <option value="">-- Select --</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">ASSET TYPE</label>
                <select id="linkAssetType" class="form-select">
                    <option value="">-- Select --</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">IDENTIFIER</label>
                <input type="text" id="linkIdentifier" class="form-input" placeholder="Account number / Phone number">
            </div>
            <div class="form-group">
                <label class="form-label">INSTITUTION PIN</label>
                <input type="password" id="linkPin" class="form-input" placeholder="Your PIN">
            </div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closeLinkModal()">CANCEL</button>
            <button class="modal-btn modal-btn-primary" onclick="saveLinkedSource()">SAVE →</button>
        </div>
    </div>
</div>

<!-- SHARP EDGE ERROR MODAL -->
<div id="errorModal" class="modal error-modal">
    <div class="modal-content">
        <div class="modal-header">⚠️ ERROR</div>
        <div class="modal-body">
            <div id="errorMessage" style="margin-bottom: 16px;"></div>
            <div id="errorDetails" class="error-details" style="display: none;"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn modal-btn-primary" onclick="closeErrorModal()">OK</button>
        </div>
    </div>
</div>

<!-- SHARP EDGE SUCCESS MODAL -->
<div id="successModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">✓ SUCCESS</div>
        <div class="modal-body">
            <div id="successMessage"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn modal-btn-primary" onclick="closeSuccessModal()">OK</button>
        </div>
    </div>
</div>

<script>
// Configuration
let participants = [];
let destinationCountries = [];
let linkedSources = [];
let currentPinInput = '';
let hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;
let swapToken = null;

// Debug logging
function debugLog(message) {
    console.log('[DEBUG]', message);
    const debugDiv = document.getElementById('debugInfo');
    if (debugDiv) {
        const time = new Date().toLocaleTimeString();
        debugDiv.innerHTML = `<div>${time}: ${message}</div>` + debugDiv.innerHTML;
        if (debugDiv.children.length > 10) {
            debugDiv.removeChild(debugDiv.lastChild);
        }
    }
}

// Sharp Edge Error Modal
function showError(message, details = null) {
    debugLog('ERROR: ' + message);
    document.getElementById('errorMessage').innerHTML = message;
    const detailsDiv = document.getElementById('errorDetails');
    if (details) {
        detailsDiv.innerHTML = details;
        detailsDiv.style.display = 'block';
    } else {
        detailsDiv.style.display = 'none';
    }
    document.getElementById('errorModal').style.display = 'flex';
}

function closeErrorModal() {
    document.getElementById('errorModal').style.display = 'none';
}

function showSuccess(message) {
    debugLog('SUCCESS: ' + message);
    document.getElementById('successMessage').innerHTML = message;
    document.getElementById('successModal').style.display = 'flex';
}

function closeSuccessModal() {
    document.getElementById('successModal').style.display = 'none';
}

// ============================================================
// INITIALIZATION - LOAD DATA FROM SERVER
// ============================================================
async function loadData() {
    debugLog('Loading participants data...');
    try {
        const partRes = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_participants'
        });
        const partData = await partRes.json();
        debugLog('Participants response: ' + JSON.stringify(partData).substring(0, 200));
        
        if (partData.success) {
            participants = partData.participants;
            destinationCountries = partData.countries;
            debugLog(`Loaded ${participants.length} participants, ${destinationCountries.length} countries`);
            renderInstitutionList();
            renderInstitutionSelects();
        } else {
            showError('Failed to load participants', 'No participants data received');
        }
        
        const sourcesRes = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_linked_sources'
        });
        const sourcesData = await sourcesRes.json();
        if (sourcesData.success) {
            linkedSources = sourcesData.sources;
            debugLog(`Loaded ${linkedSources.length} linked sources`);
            renderLinkedSourcesSelect();
            renderLinkedSourcesList();
        }
    } catch(e) {
        debugLog('Failed to load data: ' + e.message);
        showError('Failed to load data', e.message);
    }
}

function renderInstitutionList() {
    const container = document.getElementById('institutionList');
    if (!container) return;
    container.innerHTML = participants.map(p => `<div>• ${p.name} (${p.country})</div>`).join('');
}

function renderInstitutionSelects() {
    const adhocSelect = document.getElementById('adhocInstitution');
    if (adhocSelect) {
        adhocSelect.innerHTML = '<option value="">-- Select institution --</option>' + 
            participants.map(p => `<option value="${p.code}" data-country="${p.country}">${p.name} (${p.country})</option>`).join('');
    }
    
    const linkSelect = document.getElementById('linkInstitution');
    if (linkSelect) {
        linkSelect.innerHTML = '<option value="">-- Select --</option>' + 
            participants.map(p => `<option value="${p.code}">${p.name}</option>`).join('');
    }
    
    const destCountrySelect = document.getElementById('destCountry');
    if (destCountrySelect) {
        destCountrySelect.innerHTML = '<option value="">-- Select country --</option>' + 
            destinationCountries.map(c => `<option value="${c}">${c}</option>`).join('');
    }
}

function renderLinkedSourcesSelect() {
    const select = document.getElementById('linkedSourceSelect');
    if (select) {
        select.innerHTML = '<option value="">-- Select source --</option>' +
            linkedSources.map(s => `<option value="${s.id}">${s.name} (${s.type})</option>`).join('');
    }
}

function renderLinkedSourcesList() {
    const container = document.getElementById('sourcesListContainer');
    if (container) {
        if (linkedSources.length === 0) {
            container.innerHTML = '<div style="color: rgba(255,255,255,0.3);">No linked sources found.</div>';
        } else {
            container.innerHTML = linkedSources.map(s => 
                `<div class="source-item" onclick="selectLinkedSource(${s.id}, '${s.name}')">
                    <div class="source-name">${s.name}</div>
                    <div class="source-type">${s.type}</div>
                </div>`
            ).join('');
        }
    }
}

function toggleSourceFields() {
    const sourceType = document.getElementById('sourceType').value;
    const linkedSection = document.getElementById('linkedSourceSection');
    const adhocSection = document.getElementById('adhocSourceSection');
    
    if (sourceType === 'linked') {
        linkedSection.style.display = 'block';
        adhocSection.style.display = 'none';
    } else {
        linkedSection.style.display = 'none';
        adhocSection.style.display = 'block';
    }
}

function showStep(step) {
    document.getElementById('stepSwap').classList.remove('active');
    document.getElementById('stepSources').classList.remove('active');
    document.getElementById('stepSecurity').classList.remove('active');
    document.getElementById('step' + step.charAt(0).toUpperCase() + step.slice(1)).classList.add('active');
}

// ============================================================
// ASSET TYPES - Dynamic from participants
// ============================================================
document.getElementById('adhocInstitution')?.addEventListener('change', function() {
    const instCode = this.value;
    const assetSelect = document.getElementById('adhocAssetType');
    const institution = participants.find(p => p.code === instCode);
    if (institution && institution.asset_types) {
        assetSelect.innerHTML = '<option value="">-- Select asset type --</option>' +
            institution.asset_types.map(a => `<option value="${a.type}">${a.icon} ${a.name}</option>`).join('');
    } else {
        assetSelect.innerHTML = '<option value="">-- Select asset type --</option>';
    }
});

document.getElementById('destCountry')?.addEventListener('change', function() {
    const country = this.value;
    const destSelect = document.getElementById('destInstitution');
    const filtered = participants.filter(p => p.country === country);
    destSelect.innerHTML = '<option value="">-- Select institution --</option>' +
        filtered.map(p => `<option value="${p.code}">${p.name}</option>`).join('');
});

// ============================================================
// PIN SETUP
// ============================================================
function renderPinDots() {
    const container = document.getElementById('pinDots');
    if (!container) return;
    let dots = '';
    for (let i = 0; i < 6; i++) {
        dots += `<div class="pin-dot ${i < currentPinInput.length ? 'filled' : ''}"></div>`;
    }
    container.innerHTML = dots;
}

function renderPinPad() {
    const container = document.getElementById('pinPad');
    if (!container) return;
    const nums = [1,2,3,4,5,6,7,8,9,'⌫',0,'CLR'];
    container.innerHTML = nums.map(n => `<button class="pin-btn" onclick="pinInputHandler('${n}')">${n}</button>`).join('');
}

function pinInputHandler(value) {
    if (value === '⌫') {
        currentPinInput = currentPinInput.slice(0, -1);
    } else if (value === 'CLR') {
        currentPinInput = '';
    } else if (currentPinInput.length < 6) {
        currentPinInput += value;
    }
    renderPinDots();
    
    if (currentPinInput.length === 6) {
        savePin(currentPinInput);
    }
}

function showPinSetupModal() {
    currentPinInput = '';
    renderPinDots();
    renderPinPad();
    document.getElementById('pinModal').style.display = 'flex';
}

function closePinModal() {
    document.getElementById('pinModal').style.display = 'none';
    currentPinInput = '';
}

async function savePin(pin) {
    debugLog('Saving PIN...');
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=set_transaction_pin&pin=${pin}`
        });
        const data = await res.json();
        if (data.status === 'success') {
            hasTransactionPin = true;
            document.getElementById('pinStatus').innerHTML = 'PIN: SET';
            closePinModal();
            showSuccess('Transaction PIN set successfully!');
        } else {
            document.getElementById('pinError').innerHTML = data.message;
        }
    } catch(e) {
        document.getElementById('pinError').innerHTML = 'Failed to save PIN';
        debugLog('Save PIN error: ' + e.message);
    }
}

// ============================================================
// SWAP EXECUTION
// ============================================================
async function initiateSwap() {
    debugLog('Initiating swap...');
    
    const sourceType = document.getElementById('sourceType').value;
    const amount = parseFloat(document.getElementById('swapAmount').value);
    const destCountry = document.getElementById('destCountry').value;
    const destInstitution = document.getElementById('destInstitution').value;
    const destIdentifier = document.getElementById('destIdentifier').value;
    
    // Validation
    const errors = [];
    if (!amount || amount < 10) errors.push('Amount must be at least 10 BWP');
    if (!destCountry) errors.push('Please select destination country');
    if (!destInstitution) errors.push('Please select destination institution');
    if (!destIdentifier) errors.push('Please enter destination identifier');
    
    if (errors.length > 0) {
        showError('Validation Error', errors.join('<br>'));
        return;
    }
    
    let sourceInstitution = '';
    let sourceIdentifier = '';
    
    if (sourceType === 'linked') {
        const sourceId = document.getElementById('linkedSourceSelect').value;
        if (!sourceId) {
            showError('Validation Error', 'Please select a linked source');
            return;
        }
        
        if (!hasTransactionPin) {
            showError('PIN Required', 'Please set your transaction PIN first');
            showPinSetupModal();
            return;
        }
        
        // Show PIN modal for verification
        showPinVerificationModal(async (pin) => {
            debugLog('Verifying PIN...');
            try {
                const verifyRes = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=verify_pin&pin=${pin}`
                });
                const verifyData = await verifyRes.json();
                debugLog('PIN verify response: ' + JSON.stringify(verifyData));
                
                if (verifyData.status !== 'success') {
                    showError('PIN Verification Failed', verifyData.message);
                    return;
                }
                swapToken = verifyData.token;
                debugLog('Token received: ' + swapToken);
                
                // Execute swap
                await executeSwap(amount, destInstitution, destIdentifier, 'linked', sourceId);
            } catch(e) {
                debugLog('PIN verification error: ' + e.message);
                showError('Error', e.message);
            }
        });
    } else {
        // Ad-hoc swap - no PIN needed
        sourceInstitution = document.getElementById('adhocInstitution').value;
        const assetType = document.getElementById('adhocAssetType').value;
        sourceIdentifier = document.getElementById('adhocIdentifier').value;
        const instPin = document.getElementById('adhocPin').value;
        
        if (!sourceInstitution) errors.push('Please select source institution');
        if (!assetType) errors.push('Please select asset type');
        if (!sourceIdentifier) errors.push('Please enter your identifier');
        if (!instPin) errors.push('Please enter your institution PIN');
        
        if (errors.length > 0) {
            showError('Validation Error', errors.join('<br>'));
            return;
        }
        
        await executeSwap(amount, destInstitution, destIdentifier, 'adhoc', null, sourceInstitution, assetType, sourceIdentifier, instPin);
    }
}

function showPinVerificationModal(callback) {
    // Create a temporary PIN modal for verification
    let tempPinInput = '';
    const tempModal = document.createElement('div');
    tempModal.className = 'modal';
    tempModal.style.display = 'flex';
    tempModal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">VERIFY TRANSACTION PIN</div>
            <div class="modal-body">
                <div class="pin-dots" id="tempPinDots"></div>
                <div class="pin-pad" id="tempPinPad"></div>
                <div class="error-text" id="tempPinError" style="color: #ff4444; font-size: 12px; text-align: center;"></div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn" id="tempPinCancel">CANCEL</button>
            </div>
        </div>
    `;
    document.body.appendChild(tempModal);
    
    const renderDots = () => {
        const container = document.getElementById('tempPinDots');
        let dots = '';
        for (let i = 0; i < 6; i++) {
            dots += `<div class="pin-dot ${i < tempPinInput.length ? 'filled' : ''}"></div>`;
        }
        container.innerHTML = dots;
    };
    
    const renderPad = () => {
        const container = document.getElementById('tempPinPad');
        const nums = [1,2,3,4,5,6,7,8,9,'⌫',0,'CLR'];
        container.innerHTML = nums.map(n => `<button class="pin-btn" data-value="${n}">${n}</button>`).join('');
        
        container.querySelectorAll('.pin-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const val = btn.dataset.value;
                if (val === '⌫') {
                    tempPinInput = tempPinInput.slice(0, -1);
                } else if (val === 'CLR') {
                    tempPinInput = '';
                } else if (tempPinInput.length < 6) {
                    tempPinInput += val;
                }
                renderDots();
                if (tempPinInput.length === 6) {
                    tempModal.remove();
                    callback(tempPinInput);
                }
            });
        });
    };
    
    document.getElementById('tempPinCancel').addEventListener('click', () => {
        tempModal.remove();
    });
    
    renderDots();
    renderPad();
}

async function executeSwap(amount, destInstitution, destIdentifier, type, sourceId, sourceInstitution = null, assetType = null, sourceIdentifier = null, instPin = null) {
    debugLog('Executing swap...');
    debugLog(`Amount: ${amount}, Dest: ${destInstitution}, Identifier: ${destIdentifier}`);
    
    let body = `action=swap&token=${swapToken || ''}&amount=${amount}&dest_institution=${destInstitution}&dest_identifier=${destIdentifier}`;
    
    if (type === 'linked') {
        body += `&source_institution=linked&source_id=${sourceId}`;
    } else {
        body += `&source_institution=${sourceInstitution}&asset_type=${assetType}&source_identifier=${sourceIdentifier}&inst_pin=${instPin}`;
    }
    
    try {
        const swapRes = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
        const swapData = await swapRes.json();
        debugLog('Swap response: ' + JSON.stringify(swapData));
        
        if (swapData.status === 'success') {
            showSuccess(`Swap completed successfully!\n\nReference: ${swapData.swap_reference}\nAmount: ${amount} BWP\nDestination: ${destIdentifier}`);
            document.getElementById('swapAmount').value = '';
            document.getElementById('destIdentifier').value = '';
            swapToken = null;
        } else {
            showError('Swap Failed', swapData.message);
        }
    } catch(e) {
        debugLog('Swap error: ' + e.message);
        showError('Swap Error', e.message);
    }
}

// ============================================================
// LINK SOURCE
// ============================================================
function showLinkModal() {
    document.getElementById('linkModal').style.display = 'flex';
}

function closeLinkModal() {
    document.getElementById('linkModal').style.display = 'none';
}

async function saveLinkedSource() {
    const institution = document.getElementById('linkInstitution').value;
    const assetType = document.getElementById('linkAssetType').value;
    const identifier = document.getElementById('linkIdentifier').value;
    const pin = document.getElementById('linkPin').value;
    
    if (!institution) { showError('Error', 'Select institution'); return; }
    if (!assetType) { showError('Error', 'Select asset type'); return; }
    if (!identifier) { showError('Error', 'Enter identifier'); return; }
    if (!pin) { showError('Error', 'Enter PIN'); return; }
    
    showSuccess('Source linked successfully! (Demo - database integration pending)');
    closeLinkModal();
    loadData();
}

document.getElementById('linkInstitution')?.addEventListener('change', function() {
    const instCode = this.value;
    const assetSelect = document.getElementById('linkAssetType');
    const institution = participants.find(p => p.code === instCode);
    if (institution && institution.asset_types) {
        assetSelect.innerHTML = '<option value="">-- Select asset type --</option>' +
            institution.asset_types.map(a => `<option value="${a.type}">${a.name}</option>`).join('');
    } else {
        assetSelect.innerHTML = '<option value="">-- Select asset type --</option>';
    }
});

function selectLinkedSource(id, name) {
    showSuccess(`Selected source: ${name}`);
}

function logout() {
    window.location.href = 'logout.php';
}

// Initialize
loadData();
toggleSourceFields();

<?php if (!$hasTransactionPin): ?>
setTimeout(() => { showPinSetupModal(); }, 1000);
<?php endif; ?>
</script>
</body>
</html>
