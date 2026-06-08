<?php
// public/user/user_dashboard.php - Simplified Orchestration Dashboard

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
// API BASE URL
// ============================================================
$apiBaseUrl = rtrim(getenv('VOUCHMORPH_API_URL') ?: 'https://vouchmorph.up.railway.app', '/');

// ============================================================
// LOAD COUNTRY REGISTRY
// ============================================================
$countries = [];
$countriesByCode = [];
$countryRegistryPath = __DIR__ . '/../../src/Core/Config/countries_registry.json';

if (file_exists($countryRegistryPath)) {
    $registryContent = file_get_contents($countryRegistryPath);
    $registryData = json_decode($registryContent, true);
    if ($registryData && isset($registryData['countries'])) {
        foreach ($registryData['countries'] as $country) {
            $countries[] = $country;
            $countriesByCode[$country['code']] = $country;
        }
    }
}

// ============================================================
// LOAD PARTICIPANTS FROM NEW YAML STRUCTURE
// ============================================================
$allParticipants = [];
$participantsByCountry = [];
$participantAssetDefs = [];

// Load participants.yaml (registry)
$participantsPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/participants.yaml';

if (file_exists($participantsPath)) {
    $yamlContent = file_get_contents($participantsPath);
    // Simple YAML parser for participants (since we don't have yaml_parse in all PHP versions)
    $data = parseSimpleYaml($yamlContent);
    
    if ($data && isset($data['participants'])) {
        foreach ($data['participants'] as $code => $participant) {
            if ($code === 'VOUCHMORPH') continue;
            
            $allParticipants[$code] = [
                'code' => $code,
                'name' => $participant['name'] ?? $code,
                'category' => $participant['type'] ?? 'BANK',
                'country_code' => $participant['country'] ?? $userCountry,
                'currency' => $participant['limits']['currency'] ?? 'BWP',
                'assets' => $participant['assets'] ?? [],
                'status' => $participant['status'] ?? 'ACTIVE'
            ];
            
            $countryCode = $participant['country'] ?? $userCountry;
            if (!isset($participantsByCountry[$countryCode])) {
                $participantsByCountry[$countryCode] = [];
            }
            $participantsByCountry[$countryCode][] = $allParticipants[$code];
        }
    }
}

// Load endpoints.yaml for connection details
$endpointsPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/endpoints.yaml';
$endpointsConfig = [];
if (file_exists($endpointsPath)) {
    $yamlContent = file_get_contents($endpointsPath);
    $endpointsConfig = parseSimpleYaml($yamlContent);
}

function parseSimpleYaml($content) {
    // Very simple YAML parser for our specific structure
    $result = [];
    $lines = explode("\n", $content);
    $currentSection = null;
    $currentParticipant = null;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        // Check for section header (no indent)
        if (preg_match('/^([A-Z_]+):/', $line, $matches) && strpos($line, '  ') === false) {
            $currentSection = $matches[1];
            if ($currentSection === 'participants') {
                $result[$currentSection] = [];
            }
            continue;
        }
        
        // Check for participant (indented under participants)
        if ($currentSection === 'participants' && preg_match('/^  ([A-Z_]+):/', $line, $matches)) {
            $currentParticipant = $matches[1];
            $result['participants'][$currentParticipant] = [];
            continue;
        }
        
        // Parse participant properties
        if ($currentParticipant && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            // Remove quotes if present
            if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
            if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
            
            // Handle numeric values
            if (is_numeric($value)) $value = (float)$value;
            if ($value === 'true') $value = true;
            if ($value === 'false') $value = false;
            
            $result['participants'][$currentParticipant][$key] = $value;
        }
        
        // Parse nested structures (assets, routing, limits)
        if ($currentParticipant && preg_match('/^    ([a-z_]+):$/', $line, $matches)) {
            // Start of nested section - skip for now, keep simple
        }
        
        if ($currentParticipant && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
            if (is_numeric($value)) $value = (float)$value;
            
            // Determine parent section from context
            if (strpos($line, 'routing:') !== false) {
                $result['participants'][$currentParticipant]['routing'][$key] = $value;
            } elseif (strpos($line, 'limits:') !== false) {
                $result['participants'][$currentParticipant]['limits'][$key] = $value;
            } else {
                $result['participants'][$currentParticipant][$key] = $value;
            }
        }
    }
    
    return $result;
}

// Load linked sources
$fundingSources = [];
try {
    $db = DBConnection::getInstance();
    $stmt = $db->prepare("
        SELECT id, institution_code, institution_name, source_type, asset_type, identifier, vault_slot 
        FROM user_funding_sources 
        WHERE user_id = :user_id AND status = 'ACTIVE'
    ");
    $stmt->execute([':user_id' => $userId]);
    $fundingSources = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Error loading sources: " . $e->getMessage());
}

// ============================================================
// AJAX HANDLERS
// ============================================================
if ($isAjax) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    // Get all participants
    if ($action === 'get_participants') {
        echo json_encode([
            'success' => true,
            'participants' => array_values($allParticipants),
            'participants_by_country' => $participantsByCountry,
            'countries' => $countries,
            'user_phone' => $userPhone
        ]);
        exit;
    }
    
    // Get linked sources
    if ($action === 'get_linked_sources') {
        $sources = [];
        foreach ($fundingSources as $fs) {
            $sources[] = [
                'id' => $fs['id'],
                'name' => $fs['institution_name'] ?? $fs['institution_code'],
                'type' => $fs['source_type'],
                'asset_type' => $fs['asset_type'],
                'identifier' => $fs['identifier']
            ];
        }
        echo json_encode(['success' => true, 'sources' => $sources]);
        exit;
    }
    
    // Execute swap
    if ($action === 'swap_linked') {
        try {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $destInstitution = $_POST['dest_institution'] ?? '';
            $destIdentifier = $_POST['dest_identifier'] ?? '';
            $pin = $_POST['pin'] ?? '';
            
            $db = DBConnection::getInstance();
            $stmt = $db->prepare("SELECT institution_code, asset_type, identifier FROM user_funding_sources WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $sourceId, 'user_id' => $userId]);
            $source = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$source) throw new Exception('Source not found');
            
            $result = callVouchMorphApi('v1/swap/execute', [
                'source' => [
                    'institution' => $source['institution_code'],
                    'asset_type' => $source['asset_type'],
                    'identifier' => $source['identifier'],
                    'amount' => $amount,
                    'pin' => $pin
                ],
                'destination' => [
                    'institution' => $destInstitution,
                    'identifier' => $destIdentifier,
                    'delivery_mode' => 'deposit'
                ],
                'user_id' => $userId
            ]);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'swap_adhoc') {
        try {
            $amount = (float)($_POST['amount'] ?? 0);
            $sourceInstitution = $_POST['source_institution'] ?? '';
            $assetType = $_POST['asset_type'] ?? '';
            $accountNumber = $_POST['account_number'] ?? '';
            $pin = $_POST['pin'] ?? '';
            $destInstitution = $_POST['dest_institution'] ?? '';
            $destIdentifier = $_POST['dest_identifier'] ?? '';
            
            $result = callVouchMorphApi('v1/swap/execute', [
                'source' => [
                    'institution' => $sourceInstitution,
                    'asset_type' => $assetType,
                    'identifier' => $accountNumber,
                    'amount' => $amount,
                    'pin' => $pin
                ],
                'destination' => [
                    'institution' => $destInstitution,
                    'identifier' => $destIdentifier,
                    'delivery_mode' => 'deposit'
                ],
                'user_id' => $userId
            ]);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Set PIN
    if ($action === 'set_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            if (strlen($pin) !== 6 || !ctype_digit($pin)) throw new Exception('PIN must be 6 digits');
            
            $db = DBConnection::getInstance();
            $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET transaction_pin_hash = :pin, has_transaction_pin = 1 WHERE user_id = :user_id");
            $stmt->execute([':pin' => $hashedPin, ':user_id' => $userId]);
            
            echo json_encode(['status' => 'success', 'message' => 'PIN set successfully']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Verify PIN
    if ($action === 'verify_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            
            $db = DBConnection::getInstance();
            $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($pin, $user['transaction_pin_hash'])) {
                throw new Exception('Invalid PIN');
            }
            
            $_SESSION['pin_verified'] = true;
            $_SESSION['pin_verified_at'] = time();
            
            echo json_encode(['status' => 'success', 'message' => 'PIN verified']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

function callVouchMorphApi($endpoint, $data) {
    global $apiBaseUrl;
    
    $url = $apiBaseUrl . '/api/' . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['status' => 'error', 'message' => "API returned HTTP {$httpCode}"];
    }
    
    return json_decode($response, true) ?: ['status' => 'error', 'message' => 'Invalid response'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VOUCHMORPH</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #000000; font-family: 'Space Grotesk', monospace; color: #FFFFFF; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    
    .container { max-width: 500px; width: 90%; margin: 48px auto; }
    
    /* 3 MAIN BUTTONS */
    .main-buttons { display: flex; flex-direction: column; gap: 16px; margin-bottom: 48px; }
    .main-btn { background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 24px; font-family: inherit; font-size: 18px; font-weight: 500; letter-spacing: 2px; color: #FFFFFF; cursor: pointer; transition: all 0.2s; text-align: center; }
    .main-btn:hover { border-color: #FFFFFF; background: rgba(255,255,255,0.03); }
    
    /* Action panels - hidden by default */
    .action-panel { display: none; margin-top: 32px; padding-top: 32px; border-top: 1px solid rgba(255,255,255,0.1); }
    .action-panel.active { display: block; }
    
    .panel-title { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3); margin-bottom: 24px; text-transform: uppercase; }
    
    .form-group { margin-bottom: 20px; }
    .form-label { font-size: 10px; letter-spacing: 1px; color: rgba(255,255,255,0.4); margin-bottom: 8px; display: block; text-transform: uppercase; }
    .form-input, .form-select { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 14px 16px; font-family: inherit; font-size: 14px; color: #FFFFFF; border-radius: 0; }
    .form-input:focus, .form-select:focus { outline: none; border-color: #FFFFFF; }
    .form-select option { background: #000000; }
    
    .primary-btn { width: 100%; background: #FFFFFF; border: none; padding: 16px 24px; font-family: inherit; font-size: 13px; font-weight: 500; letter-spacing: 2px; color: #000000; cursor: pointer; margin-top: 16px; transition: opacity 0.2s; }
    .primary-btn:hover { opacity: 0.9; }
    .primary-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    
    .secondary-btn { background: transparent; border: 1px solid rgba(255,255,255,0.2); padding: 12px 20px; font-family: inherit; font-size: 11px; letter-spacing: 1px; color: #FFFFFF; cursor: pointer; margin-right: 12px; }
    .secondary-btn:hover { border-color: #FFFFFF; }
    
    .source-item { padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.06); cursor: pointer; transition: all 0.2s; }
    .source-item:hover { background: rgba(255,255,255,0.03); padding-left: 8px; }
    .source-name { font-size: 14px; }
    .source-detail { font-size: 10px; color: rgba(255,255,255,0.3); margin-top: 4px; }
    
    .badge { font-size: 9px; padding: 2px 6px; margin-left: 8px; border-radius: 2px; background: rgba(255,255,255,0.1); }
    
    .user-info { text-align: center; margin-bottom: 32px; padding-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.05); }
    .user-phone { font-size: 12px; color: rgba(255,255,255,0.4); letter-spacing: 1px; }
    .pin-status { font-size: 10px; color: #4CAF50; margin-top: 4px; }
    .pin-status.not-set { color: rgba(255,255,255,0.3); }
    
    .modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.98); z-index: 1000; display: none; align-items: center; justify-content: center; }
    .modal-content { width: 360px; background: #000000; border: 1px solid rgba(255,255,255,0.15); }
    .modal-header { padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 14px; letter-spacing: 1px; text-transform: uppercase; }
    .modal-body { padding: 24px; }
    .modal-footer { padding: 20px 24px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; gap: 12px; justify-content: flex-end; }
    .modal-btn { background: transparent; border: 1px solid rgba(255,255,255,0.2); padding: 10px 20px; font-family: inherit; font-size: 11px; letter-spacing: 1px; color: rgba(255,255,255,0.6); cursor: pointer; }
    .modal-btn-primary { background: #FFFFFF; border-color: #FFFFFF; color: #000000; }
    
    .pin-dots { display: flex; justify-content: center; gap: 16px; margin: 24px 0; }
    .pin-dot { width: 12px; height: 12px; border: 1px solid rgba(255,255,255,0.3); border-radius: 50%; }
    .pin-dot.filled { background: #FFFFFF; border-color: #FFFFFF; }
    
    .pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 24px 0; }
    .pin-btn { background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 16px; font-size: 20px; font-family: inherit; color: #FFFFFF; cursor: pointer; }
    .pin-btn:active { background: #FFFFFF; color: #000000; }
    
    .error-text { color: #ff4444; text-align: center; margin-top: 16px; font-size: 12px; }
    
    .loading-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(0,0,0,0.2); border-top-color: #000000; border-radius: 50%; animation: spin 0.6s linear infinite; margin-right: 8px; }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    hr { border-color: rgba(255,255,255,0.05); margin: 16px 0; }
    
    .field-hint { font-size: 9px; color: rgba(255,255,255,0.25); margin-top: 4px; }
</style>
</head>
<body>

<div class="container">
    <div class="user-info">
        <div class="user-phone"><?= vm_h($userPhone) ?></div>
        <div class="pin-status <?= $hasTransactionPin ? '' : 'not-set' ?>" id="pinStatus">
            <?= $hasTransactionPin ? '✓ PIN SET' : '⚠ PIN NOT SET' ?>
        </div>
    </div>
    
    <!-- 3 MAIN BUTTONS -->
    <div class="main-buttons">
        <button class="main-btn" onclick="showPanel('linked')">🔗 SEND FROM LINKED SOURCE</button>
        <button class="main-btn" onclick="showPanel('adhoc')">🏦 SEND FROM BANK ACCOUNT</button>
        <button class="main-btn" onclick="showPanel('security')">🔒 SECURITY</button>
    </div>
    
    <!-- PANEL 1: LINKED SOURCE -->
    <div id="panelLinked" class="action-panel">
        <div class="panel-title">🔗 SELECT LINKED SOURCE</div>
        <div id="linkedSourcesList"></div>
        <div id="linkedForm" style="display: none;">
            <div class="form-group">
                <label class="form-label">AMOUNT (BWP)</label>
                <input type="number" id="linkedAmount" class="form-input" placeholder="Enter amount" step="0.01">
            </div>
            <div class="form-group">
                <label class="form-label">DESTINATION COUNTRY</label>
                <select id="linkedDestCountry" class="form-select"></select>
            </div>
            <div class="form-group">
                <label class="form-label">DESTINATION INSTITUTION</label>
                <select id="linkedDestInstitution" class="form-select"></select>
            </div>
            <div class="form-group">
                <label class="form-label">DESTINATION IDENTIFIER</label>
                <input type="text" id="linkedDestIdentifier" class="form-input" placeholder="Account number or phone number">
                <div class="field-hint">Bank account number or mobile wallet phone number</div>
            </div>
            <div class="form-group">
                <label class="form-label">TRANSACTION PIN</label>
                <input type="password" id="linkedPin" class="form-input" placeholder="6-digit PIN" maxlength="6">
            </div>
            <button class="primary-btn" onclick="executeLinkedSwap()" id="linkedExecuteBtn">SEND →</button>
        </div>
    </div>
    
    <!-- PANEL 2: AD-HOC (BANK ACCOUNT) -->
    <div id="panelAdhoc" class="action-panel">
        <div class="panel-title">🏦 SEND FROM BANK ACCOUNT</div>
        <div class="form-group">
            <label class="form-label">SOURCE BANK</label>
            <select id="adhocSourceBank" class="form-select"></select>
        </div>
        <div class="form-group">
            <label class="form-label">ACCOUNT NUMBER</label>
            <input type="text" id="adhocAccountNumber" class="form-input" placeholder="Your account number">
        </div>
        <div class="form-group">
            <label class="form-label">ACCOUNT PIN</label>
            <input type="password" id="adhocAccountPin" class="form-input" placeholder="Bank account PIN">
        </div>
        <div class="form-group">
            <label class="form-label">AMOUNT (BWP)</label>
            <input type="number" id="adhocAmount" class="form-input" placeholder="Enter amount" step="0.01">
        </div>
        <div class="form-group">
            <label class="form-label">DESTINATION COUNTRY</label>
            <select id="adhocDestCountry" class="form-select"></select>
        </div>
        <div class="form-group">
            <label class="form-label">DESTINATION INSTITUTION</label>
            <select id="adhocDestInstitution" class="form-select"></select>
        </div>
        <div class="form-group">
            <label class="form-label">DESTINATION IDENTIFIER</label>
            <input type="text" id="adhocDestIdentifier" class="form-input" placeholder="Recipient account or phone number">
            <div class="field-hint">Recipient's bank account number or mobile wallet phone number</div>
        </div>
        <button class="primary-btn" onclick="executeAdhocSwap()" id="adhocExecuteBtn">SEND →</button>
    </div>
    
    <!-- PANEL 3: SECURITY -->
    <div id="panelSecurity" class="action-panel">
        <div class="panel-title">🔒 SECURITY</div>
        <button class="secondary-btn" onclick="showPinSetup()">SET TRANSACTION PIN</button>
        <button class="secondary-btn" onclick="logout()">LOGOUT</button>
    </div>
</div>

<!-- PIN SETUP MODAL -->
<div id="pinModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">SET TRANSACTION PIN</div>
        <div class="modal-body">
            <div class="pin-dots" id="pinDots"></div>
            <div class="pin-pad" id="pinPad"></div>
            <div id="pinError" class="error-text"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closePinModal()">CANCEL</button>
        </div>
    </div>
</div>

<script>
// Global data
let participants = [];
let participantsByCountry = {};
let countries = [];
let linkedSources = [];
let currentLinkedSourceId = null;
let hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;
let currentPinInput = '';

// ============================================================
// UI Functions
// ============================================================
function showPanel(panel) {
    document.querySelectorAll('.action-panel').forEach(p => p.classList.remove('active'));
    document.getElementById(`panel${panel.charAt(0).toUpperCase() + panel.slice(1)}`).classList.add('active');
    
    if (panel === 'linked') loadLinkedSources();
    if (panel === 'adhoc') loadAdhocData();
}

function showError(msg) { alert('Error: ' + msg); }

// ============================================================
// LOAD DATA
// ============================================================
async function loadData() {
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_participants'
        });
        const data = await res.json();
        if (data.success) {
            participants = data.participants;
            participantsByCountry = data.participants_by_country;
            countries = data.countries;
            
            // Populate country selects
            populateCountrySelect('linkedDestCountry');
            populateCountrySelect('adhocDestCountry');
        }
    } catch(e) {
        console.error('Load error:', e);
    }
}

function populateCountrySelect(selectId) {
    const select = document.getElementById(selectId);
    if (!select) return;
    
    select.innerHTML = '<option value="">-- Select Country --</option>';
    countries.forEach(c => {
        select.innerHTML += `<option value="${c.code}">${c.flag || '🏳️'} ${c.name}</option>`;
    });
    
    select.onchange = () => updateInstitutionSelect(selectId, select.value);
}

function updateInstitutionSelect(selectId, countryCode) {
    const targetId = selectId === 'linkedDestCountry' ? 'linkedDestInstitution' : 'adhocDestInstitution';
    const targetSelect = document.getElementById(targetId);
    
    if (!countryCode || !participantsByCountry[countryCode]) {
        targetSelect.innerHTML = '<option value="">-- Select Institution --</option>';
        return;
    }
    
    const countryParticipants = participantsByCountry[countryCode];
    targetSelect.innerHTML = '<option value="">-- Select Institution --</option>';
    countryParticipants.forEach(p => {
        const icon = p.category === 'BANK' ? '🏦' : (p.category === 'MNO' ? '📱' : '🏢');
        targetSelect.innerHTML += `<option value="${p.code}">${icon} ${p.name}</option>`;
    });
}

// ============================================================
// LINKED SOURCES
// ============================================================
async function loadLinkedSources() {
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_linked_sources'
        });
        const data = await res.json();
        if (data.success) {
            linkedSources = data.sources;
            renderLinkedSources();
        }
    } catch(e) {
        console.error('Error loading sources:', e);
    }
}

function renderLinkedSources() {
    const container = document.getElementById('linkedSourcesList');
    const form = document.getElementById('linkedForm');
    
    if (!linkedSources.length) {
        container.innerHTML = '<div class="field-hint" style="text-align:center; padding:24px;">No linked sources found. Contact support to link your bank account or wallet.</div>';
        form.style.display = 'none';
        return;
    }
    
    container.innerHTML = linkedSources.map(s => `
        <div class="source-item" onclick="selectLinkedSource(${s.id}, '${s.name.replace(/'/g, "\\'")}')">
            <div class="source-name">${s.name} <span class="badge">${s.asset_type || s.type}</span></div>
            <div class="source-detail">${s.identifier}</div>
        </div>
    `).join('');
}

function selectLinkedSource(id, name) {
    currentLinkedSourceId = id;
    document.getElementById('linkedForm').style.display = 'block';
    document.getElementById('linkedAmount').focus();
    // Scroll to form
    document.getElementById('linkedForm').scrollIntoView({ behavior: 'smooth' });
}

async function executeLinkedSwap() {
    const amount = parseFloat(document.getElementById('linkedAmount').value);
    const destCountry = document.getElementById('linkedDestCountry').value;
    const destInstitution = document.getElementById('linkedDestInstitution').value;
    const destIdentifier = document.getElementById('linkedDestIdentifier').value;
    const pin = document.getElementById('linkedPin').value;
    
    if (!currentLinkedSourceId) { showError('Select a source first'); return; }
    if (!amount || amount < 1) { showError('Enter valid amount'); return; }
    if (!destCountry) { showError('Select destination country'); return; }
    if (!destInstitution) { showError('Select destination institution'); return; }
    if (!destIdentifier) { showError('Enter destination identifier'); return; }
    if (!pin || pin.length !== 6) { showError('Enter 6-digit PIN'); return; }
    
    const btn = document.getElementById('linkedExecuteBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner"></span> PROCESSING...';
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=swap_linked&source_id=${currentLinkedSourceId}&amount=${amount}&dest_institution=${destInstitution}&dest_identifier=${encodeURIComponent(destIdentifier)}&pin=${pin}`
        });
        const result = await res.json();
        
        if (result.status === 'success') {
            alert(`✓ Success!\nAmount: ${amount} BWP\nReference: ${result.swap_reference || 'completed'}`);
            document.getElementById('linkedAmount').value = '';
            document.getElementById('linkedDestIdentifier').value = '';
            document.getElementById('linkedPin').value = '';
            document.getElementById('linkedForm').style.display = 'none';
            currentLinkedSourceId = null;
        } else {
            showError(result.message);
        }
    } catch(e) {
        showError(e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'SEND →';
    }
}

// ============================================================
// AD-HOC (BANK ACCOUNT)
// ============================================================
function loadAdhocData() {
    const sourceSelect = document.getElementById('adhocSourceBank');
    sourceSelect.innerHTML = '<option value="">-- Select Bank --</option>';
    
    // Show only BANK type participants
    participants.filter(p => p.category === 'BANK').forEach(p => {
        sourceSelect.innerHTML += `<option value="${p.code}">🏦 ${p.name}</option>`;
    });
}

async function executeAdhocSwap() {
    const sourceBank = document.getElementById('adhocSourceBank').value;
    const accountNumber = document.getElementById('adhocAccountNumber').value;
    const accountPin = document.getElementById('adhocAccountPin').value;
    const amount = parseFloat(document.getElementById('adhocAmount').value);
    const destCountry = document.getElementById('adhocDestCountry').value;
    const destInstitution = document.getElementById('adhocDestInstitution').value;
    const destIdentifier = document.getElementById('adhocDestIdentifier').value;
    
    if (!sourceBank) { showError('Select source bank'); return; }
    if (!accountNumber) { showError('Enter account number'); return; }
    if (!accountPin) { showError('Enter account PIN'); return; }
    if (!amount || amount < 1) { showError('Enter valid amount'); return; }
    if (!destCountry) { showError('Select destination country'); return; }
    if (!destInstitution) { showError('Select destination institution'); return; }
    if (!destIdentifier) { showError('Enter destination identifier'); return; }
    
    const btn = document.getElementById('adhocExecuteBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner"></span> PROCESSING...';
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=swap_adhoc&amount=${amount}&source_institution=${sourceBank}&asset_type=ACCOUNT&account_number=${encodeURIComponent(accountNumber)}&pin=${accountPin}&dest_institution=${destInstitution}&dest_identifier=${encodeURIComponent(destIdentifier)}`
        });
        const result = await res.json();
        
        if (result.status === 'success') {
            alert(`✓ Success!\nAmount: ${amount} BWP\nReference: ${result.swap_reference || 'completed'}`);
            document.getElementById('adhocAmount').value = '';
            document.getElementById('adhocAccountNumber').value = '';
            document.getElementById('adhocAccountPin').value = '';
            document.getElementById('adhocDestIdentifier').value = '';
        } else {
            showError(result.message);
        }
    } catch(e) {
        showError(e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'SEND →';
    }
}

// ============================================================
// PIN SETUP
// ============================================================
function showPinSetup() {
    currentPinInput = '';
    renderPinDots();
    renderPinPad();
    document.getElementById('pinModal').style.display = 'flex';
}

function closePinModal() {
    document.getElementById('pinModal').style.display = 'none';
}

function renderPinDots() {
    const container = document.getElementById('pinDots');
    if (!container) return;
    container.innerHTML = '';
    for (let i = 0; i < 6; i++) {
        container.innerHTML += `<div class="pin-dot ${i < currentPinInput.length ? 'filled' : ''}"></div>`;
    }
}

function renderPinPad() {
    const container = document.getElementById('pinPad');
    if (!container) return;
    const nums = [1,2,3,4,5,6,7,8,9,'⌫',0,'CLR'];
    container.innerHTML = nums.map(n => `<button class="pin-btn" onclick="pinInput('${n}')">${n}</button>`).join('');
}

function pinInput(val) {
    if (val === '⌫') {
        currentPinInput = currentPinInput.slice(0, -1);
    } else if (val === 'CLR') {
        currentPinInput = '';
    } else if (currentPinInput.length < 6) {
        currentPinInput += val;
    }
    renderPinDots();
    
    if (currentPinInput.length === 6) {
        savePin();
    }
}

async function savePin() {
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=set_pin&pin=${currentPinInput}`
        });
        const data = await res.json();
        
        if (data.status === 'success') {
            hasTransactionPin = true;
            document.getElementById('pinStatus').innerHTML = '✓ PIN SET';
            document.getElementById('pinStatus').classList.remove('not-set');
            closePinModal();
            alert('PIN set successfully!');
        } else {
            document.getElementById('pinError').innerHTML = data.message;
        }
    } catch(e) {
        document.getElementById('pinError').innerHTML = 'Failed to set PIN';
    }
}

function logout() {
    window.location.href = 'logout.php';
}

// Initialize
loadData();

// If no PIN set, remind user
<?php if (!$hasTransactionPin): ?>
setTimeout(() => {
    if (confirm('For security, please set your transaction PIN now.')) {
        showPinSetup();
    }
}, 1000);
<?php endif; ?>
</script>
</body>
</html>
