<?php
// public/user/user_dashboard.php - COMPLETE FIX WITH TOKEN PERSISTENCE

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
            $swapData = json_decode($_POST['swap_data'] ?? '{}', true);
            
            error_log("Verifying PIN for user: " . $userId);
            
            if (verifyTransactionPin($db, $userId, $pin)) {
                // Store ALL swap data in session
                $_SESSION['pending_swap'] = $swapData;
                $_SESSION['pending_swap_expires'] = time() + 300;
                error_log("Swap data stored in session: " . json_encode($swapData));
                echo json_encode(['status' => 'success']);
            } else {
                throw new Exception('Invalid PIN');
            }
        } catch (Exception $e) {
            error_log("PIN verification failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'swap_linked') {
        try {
            // Check if we have pending swap data
            if (!isset($_SESSION['pending_swap'])) {
                throw new Exception('No pending swap found. Please try again.');
            }
            
            if ($_SESSION['pending_swap_expires'] < time()) {
                unset($_SESSION['pending_swap']);
                throw new Exception('Pending swap expired. Please try again.');
            }
            
            $swapData = $_SESSION['pending_swap'];
            unset($_SESSION['pending_swap']);
            unset($_SESSION['pending_swap_expires']);
            
            $amount = (float)($swapData['amount'] ?? 0);
            $destInstitution = $swapData['dest_institution'] ?? '';
            $destIdentifier = $swapData['dest_identifier'] ?? '';
            $destAction = $swapData['dest_action'] ?? 'deposit';
            $sourceId = (int)($swapData['source_id'] ?? 0);
            
            error_log("Processing linked swap - Amount: $amount, Dest: $destInstitution, Action: $destAction");
            
            if ($amount < 10) throw new Exception('Minimum amount is 10.00');
            if (!$destInstitution) throw new Exception('Destination institution required');
            if (!$destIdentifier) throw new Exception('Destination identifier required');
            
            // Get source details
            $stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $sourceId, 'user_id' => $userId]);
            $source = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$source) throw new Exception('Source not found');
            
            $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
            
            // Record swap transaction
            $stmt = $db->prepare("INSERT INTO swap_transactions (swap_reference, user_id, source_institution, destination_institution, destination_identifier, amount, dest_action, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$swapReference, $userId, $source['institution_code'], $destInstitution, $destIdentifier, $amount, $destAction]);
            
            error_log("Linked swap completed: " . $swapReference);
            
            echo json_encode(['status' => 'success', 'swap_reference' => $swapReference, 'message' => "Swap of BWP {$amount} to {$destAction} completed"]);
        } catch (Exception $e) {
            error_log("Linked swap failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
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
            
            error_log("Processing ad-hoc swap - Amount: $amount, Source: $sourceInstitution, Dest: $destInstitution, Action: $destAction");
            
            if ($amount < 10) throw new Exception('Minimum amount is 10.00');
            if (!$sourceInstitution) throw new Exception('Source institution required');
            if (!$destInstitution) throw new Exception('Destination institution required');
            if (!$destIdentifier) throw new Exception('Destination identifier required');
            if (!$sourceIdentifier) throw new Exception('Source identifier required');
            if (!$instPin) throw new Exception('Institution PIN required');
            
            $swapReference = 'VM-ADHOC-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
            
            // Record swap transaction
            $stmt = $db->prepare("INSERT INTO swap_transactions (swap_reference, user_id, source_institution, source_asset_type, source_identifier, destination_institution, destination_identifier, amount, dest_action, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$swapReference, $userId, $sourceInstitution, $assetType, $sourceIdentifier, $destInstitution, $destIdentifier, $amount, $destAction]);
            
            error_log("Ad-hoc swap completed: " . $swapReference);
            
            echo json_encode(['status' => 'success', 'swap_reference' => $swapReference, 'message' => "Swap of BWP {$amount} to {$destAction} completed"]);
        } catch (Exception $e) {
            error_log("Ad-hoc swap failed: " . $e->getMessage());
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
    
    .app { display: flex; min-height: 100vh; }
    .sidebar { width: 280px; background: #0a0a0a; border-right: 1px solid #1a1a1a; padding: 32px 24px; }
    .main { flex: 1; padding: 32px 48px; }
    .right-panel { width: 380px; background: #0a0a0a; border-left: 1px solid #1a1a1a; padding: 32px 24px; }
    
    .logo { font-size: 14px; letter-spacing: 4px; margin-bottom: 48px; color: rgba(255,255,255,0.5); }
    .logo strong { color: #FFFFFF; font-weight: 500; }
    
    .nav-item { display: block; width: 100%; background: transparent; border: none; padding: 14px 0; font-family: inherit; font-size: 13px; letter-spacing: 1px; color: rgba(255,255,255,0.5); cursor: pointer; text-align: left; border-bottom: 1px solid #1a1a1a; transition: all 0.1s; }
    .nav-item:hover { color: #FFFFFF; border-bottom-color: #FFFFFF; }
    .nav-item.active { color: #FFFFFF; border-bottom-color: #FFFFFF; }
    
    .user-section { margin-top: auto; padding-top: 32px; border-top: 1px solid #1a1a1a; }
    .user-phone { font-size: 12px; color: rgba(255,255,255,0.3); margin-bottom: 8px; }
    .user-badge { font-size: 10px; color: #4CAF50; }
    
    .balance-label { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3); margin-bottom: 8px; text-transform: uppercase; }
    .balance-amount { font-size: 48px; font-weight: 500; letter-spacing: -2px; margin-bottom: 32px; }
    .balance-currency { font-size: 14px; color: rgba(255,255,255,0.3); margin-left: 8px; }
    
    .primary-btn { width: 100%; background: #FFFFFF; border: none; padding: 16px 24px; font-family: inherit; font-size: 13px; font-weight: 500; letter-spacing: 2px; color: #000000; cursor: pointer; margin-bottom: 12px; transition: opacity 0.1s; }
    .primary-btn:hover { opacity: 0.9; }
    .secondary-btn { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.2); padding: 16px 24px; font-family: inherit; font-size: 13px; letter-spacing: 2px; color: #FFFFFF; cursor: pointer; margin-bottom: 12px; transition: all 0.1s; }
    .secondary-btn:hover { border-color: #FFFFFF; }
    
    .form-group { margin-bottom: 20px; }
    .form-label { font-size: 10px; letter-spacing: 1px; color: rgba(255,255,255,0.4); margin-bottom: 8px; display: block; text-transform: uppercase; }
    .form-input, .form-select { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 14px 16px; font-family: inherit; font-size: 14px; color: #FFFFFF; }
    .form-input:focus, .form-select:focus { outline: none; border-color: #FFFFFF; }
    .form-select option { background: #000000; }
    
    .radio-group { display: flex; gap: 24px; margin-top: 8px; }
    .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; }
    .radio-label input { accent-color: #FFFFFF; }
    
    .pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 24px 0; }
    .pin-btn { background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 16px; font-size: 20px; font-family: inherit; color: #FFFFFF; cursor: pointer; transition: all 0.05s; }
    .pin-btn:active { background: #FFFFFF; color: #000000; }
    .pin-dots { display: flex; justify-content: center; gap: 16px; margin: 24px 0; }
    .pin-dot { width: 12px; height: 12px; border: 1px solid rgba(255,255,255,0.3); transition: all 0.1s; }
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
    .error-modal .modal-header { color: #ff4444; border-bottom-color: rgba(255,68,68,0.3); }
    
    .panel-title { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3); margin-bottom: 20px; text-transform: uppercase; }
    .source-item { padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.06); cursor: pointer; }
    .source-item:hover { background: rgba(255,255,255,0.03); padding-left: 8px; }
    .source-name { font-size: 13px; font-weight: 500; }
    .source-type { font-size: 10px; color: rgba(255,255,255,0.3); margin-top: 4px; }
    
    .step { display: none; }
    .step.active { display: block; }
    
    .section-divider { margin: 24px 0 16px 0; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.08); }
    .badge { font-size: 9px; padding: 4px 8px; margin-left: 8px; }
    .badge-deposit { background: rgba(76, 175, 80, 0.2); border: 1px solid #4CAF50; color: #4CAF50; }
    .badge-cashout { background: rgba(255, 152, 0, 0.2); border: 1px solid #FF9800; color: #FF9800; }
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
                <label class="form-label">DESTINATION ACTION</label>
                <div class="radio-group">
                    <label class="radio-label">
                        <input type="radio" name="destAction" value="deposit" checked> DEPOSIT TO ACCOUNT
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="destAction" value="cashout"> CASHOUT (ATM/Agent)
                    </label>
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
            <div id="sourcesListContainer"></div>
            <button class="secondary-btn" style="margin-top: 24px;" onclick="showLinkModal()">+ LINK NEW SOURCE</button>
        </div>
        
        <div id="stepSecurity" class="step">
            <div class="panel-title">🔒 SECURITY</div>
            <button class="secondary-btn" onclick="showPinSetupModal()">SET TRANSACTION PIN</button>
            <button class="secondary-btn" onclick="logout()">LOGOUT</button>
        </div>
    </div>
    
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

<!-- PIN Verification Modal -->
<div id="pinVerifyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">VERIFY TRANSACTION PIN</div>
        <div class="modal-body">
            <div class="pin-dots" id="verifyPinDots"></div>
            <div class="pin-pad" id="verifyPinPad"></div>
            <div class="error-text" id="verifyPinError" style="color: #ff4444; font-size: 12px; text-align: center;"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" id="verifyPinCancel">CANCEL</button>
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

<!-- Error Modal -->
<div id="errorModal" class="modal error-modal">
    <div class="modal-content">
        <div class="modal-header">⚠️ ERROR</div>
        <div class="modal-body">
            <div id="errorMessage"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn modal-btn-primary" onclick="closeErrorModal()">OK</button>
        </div>
    </div>
</div>

<!-- Success Modal -->
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
let participants = [];
let destinationCountries = [];
let linkedSources = [];
let hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;

function debugLog(message) {
    console.log('[DEBUG]', message);
    const debugDiv = document.getElementById('debugInfo');
    if (debugDiv) {
        const time = new Date().toLocaleTimeString();
        debugDiv.innerHTML = `<div>${time}: ${message}</div>` + debugDiv.innerHTML;
        if (debugDiv.children.length > 10) debugDiv.removeChild(debugDiv.lastChild);
    }
}

function showError(message) {
    debugLog('ERROR: ' + message);
    document.getElementById('errorMessage').innerHTML = message;
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

async function loadData() {
    debugLog('Loading participants data...');
    try {
        const partRes = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_participants'
        });
        const partData = await partRes.json();
        if (partData.success) {
            participants = partData.participants;
            destinationCountries = partData.countries;
            debugLog(`Loaded ${participants.length} participants, ${destinationCountries.length} countries`);
            renderInstitutionList();
            renderInstitutionSelects();
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
    }
}

function renderInstitutionList() {
    const container = document.getElementById('institutionList');
    if (container) {
        container.innerHTML = participants.map(p => `<div>• ${p.name} (${p.country})</div>`).join('');
    }
}

function renderInstitutionSelects() {
    const adhocSelect = document.getElementById('adhocInstitution');
    if (adhocSelect) {
        adhocSelect.innerHTML = '<option value="">-- Select institution --</option>' + 
            participants.map(p => `<option value="${p.code}">${p.name} (${p.country})</option>`).join('');
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
    document.getElementById('linkedSourceSection').style.display = sourceType === 'linked' ? 'block' : 'none';
    document.getElementById('adhocSourceSection').style.display = sourceType === 'linked' ? 'none' : 'block';
}

function showStep(step) {
    ['swap', 'sources', 'security'].forEach(s => {
        document.getElementById(`step${s.charAt(0).toUpperCase() + s.slice(1)}`).classList.remove('active');
    });
    document.getElementById(`step${step.charAt(0).toUpperCase() + step.slice(1)}`).classList.add('active');
}

document.getElementById('adhocInstitution')?.addEventListener('change', function() {
    const instCode = this.value;
    const assetSelect = document.getElementById('adhocAssetType');
    const institution = participants.find(p => p.code === instCode);
    if (institution && institution.asset_types) {
        assetSelect.innerHTML = '<option value="">-- Select asset type --</option>' +
            institution.asset_types.map(a => `<option value="${a.type}">${a.icon} ${a.name}</option>`).join('');
    }
});

document.getElementById('destCountry')?.addEventListener('change', function() {
    const country = this.value;
    const destSelect = document.getElementById('destInstitution');
    const filtered = participants.filter(p => p.country === country);
    destSelect.innerHTML = '<option value="">-- Select institution --</option>' +
        filtered.map(p => `<option value="${p.code}">${p.name}</option>`).join('');
});

document.getElementById('linkInstitution')?.addEventListener('change', function() {
    const instCode = this.value;
    const assetSelect = document.getElementById('linkAssetType');
    const institution = participants.find(p => p.code === instCode);
    if (institution && institution.asset_types) {
        assetSelect.innerHTML = '<option value="">-- Select asset type --</option>' +
            institution.asset_types.map(a => `<option value="${a.type}">${a.name}</option>`).join('');
    }
});

// PIN Setup Modal
let currentPinInput = '';
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
    if (currentPinInput.length === 6) savePin(currentPinInput);
}

function showPinSetupModal() {
    currentPinInput = '';
    renderPinDots();
    renderPinPad();
    document.getElementById('pinModal').style.display = 'flex';
}

function closePinModal() {
    document.getElementById('pinModal').style.display = 'none';
}

async function savePin(pin) {
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
    }
}

// PIN Verification Modal
let pendingSwapData = null;
let verifyPinInput = '';

function renderVerifyPinDots() {
    const container = document.getElementById('verifyPinDots');
    if (!container) return;
    let dots = '';
    for (let i = 0; i < 6; i++) {
        dots += `<div class="pin-dot ${i < verifyPinInput.length ? 'filled' : ''}"></div>`;
    }
    container.innerHTML = dots;
}

function renderVerifyPinPad() {
    const container = document.getElementById('verifyPinPad');
    if (!container) return;
    const nums = [1,2,3,4,5,6,7,8,9,'⌫',0,'CLR'];
    container.innerHTML = nums.map(n => `<button class="verify-pin-btn" data-value="${n}">${n}</button>`).join('');
    
    container.querySelectorAll('.verify-pin-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const val = btn.dataset.value;
            if (val === '⌫') {
                verifyPinInput = verifyPinInput.slice(0, -1);
            } else if (val === 'CLR') {
                verifyPinInput = '';
            } else if (verifyPinInput.length < 6) {
                verifyPinInput += val;
            }
            renderVerifyPinDots();
            if (verifyPinInput.length === 6) submitPinVerification();
        });
    });
}

function showPinVerificationModal(swapData) {
    pendingSwapData = swapData;
    verifyPinInput = '';
    renderVerifyPinDots();
    renderVerifyPinPad();
    document.getElementById('verifyPinError').innerHTML = '';
    document.getElementById('pinVerifyModal').style.display = 'flex';
}

function closePinVerifyModal() {
    document.getElementById('pinVerifyModal').style.display = 'none';
    pendingSwapData = null;
    verifyPinInput = '';
}

async function submitPinVerification() {
    if (verifyPinInput.length !== 6) {
        document.getElementById('verifyPinError').innerHTML = 'PIN must be 6 digits';
        return;
    }
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=verify_pin&pin=${verifyPinInput}&swap_data=${JSON.stringify(pendingSwapData)}`
        });
        const data = await res.json();
        
        if (data.status === 'success') {
            closePinVerifyModal();
            // Now execute the swap
            await executeLinkedSwap(pendingSwapData);
        } else {
            document.getElementById('verifyPinError').innerHTML = data.message;
        }
    } catch(e) {
        document.getElementById('verifyPinError').innerHTML = 'Verification failed';
    }
}

document.getElementById('verifyPinCancel')?.addEventListener('click', () => {
    closePinVerifyModal();
});

// Swap Functions
async function initiateSwap() {
    const sourceType = document.getElementById('sourceType').value;
    const amount = parseFloat(document.getElementById('swapAmount').value);
    const destCountry = document.getElementById('destCountry').value;
    const destInstitution = document.getElementById('destInstitution').value;
    const destAction = document.querySelector('input[name="destAction"]:checked').value;
    const destIdentifier = document.getElementById('destIdentifier').value;
    
    if (!amount || amount < 10) { showError('Amount must be at least 10 BWP'); return; }
    if (!destCountry) { showError('Select destination country'); return; }
    if (!destInstitution) { showError('Select destination institution'); return; }
    if (!destIdentifier) { showError('Enter destination identifier'); return; }
    
    debugLog(`Initiating ${sourceType} swap - Amount: ${amount}, Dest: ${destInstitution}, Action: ${destAction}`);
    
    if (sourceType === 'linked') {
        const sourceId = document.getElementById('linkedSourceSelect').value;
        if (!sourceId) { showError('Select a linked source'); return; }
        
        if (!hasTransactionPin) {
            showError('Please set your transaction PIN first');
            showPinSetupModal();
            return;
        }
        
        const swapData = {
            type: 'linked',
            source_id: sourceId,
            amount: amount,
            dest_institution: destInstitution,
            dest_identifier: destIdentifier,
            dest_action: destAction
        };
        
        showPinVerificationModal(swapData);
    } else {
        const sourceInstitution = document.getElementById('adhocInstitution').value;
        const assetType = document.getElementById('adhocAssetType').value;
        const sourceIdentifier = document.getElementById('adhocIdentifier').value;
        const instPin = document.getElementById('adhocPin').value;
        
        if (!sourceInstitution) { showError('Select source institution'); return; }
        if (!assetType) { showError('Select asset type'); return; }
        if (!sourceIdentifier) { showError('Enter your identifier'); return; }
        if (!instPin) { showError('Enter your institution PIN'); return; }
        
        await executeAdhocSwap(amount, sourceInstitution, assetType, sourceIdentifier, instPin, destInstitution, destIdentifier, destAction);
    }
}

async function executeLinkedSwap(swapData) {
    debugLog('Executing linked swap...');
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=swap_linked&source_id=${swapData.source_id}&amount=${swapData.amount}&dest_institution=${swapData.dest_institution}&dest_identifier=${swapData.dest_identifier}&dest_action=${swapData.dest_action}`
        });
        const data = await res.json();
        debugLog('Linked swap response: ' + JSON.stringify(data));
        
        if (data.status === 'success') {
            showSuccess(`Swap completed!\n\nReference: ${data.swap_reference}\nAmount: ${swapData.amount} BWP\nAction: ${swapData.dest_action.toUpperCase()}\nDestination: ${swapData.dest_identifier}`);
            document.getElementById('swapAmount').value = '';
            document.getElementById('destIdentifier').value = '';
        } else {
            showError(data.message);
        }
    } catch(e) {
        showError(e.message);
    }
}

async function executeAdhocSwap(amount, sourceInstitution, assetType, sourceIdentifier, instPin, destInstitution, destIdentifier, destAction) {
    debugLog('Executing ad-hoc swap...');
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=swap_adhoc&amount=${amount}&source_institution=${sourceInstitution}&asset_type=${assetType}&source_identifier=${sourceIdentifier}&inst_pin=${instPin}&dest_institution=${destInstitution}&dest_identifier=${destIdentifier}&dest_action=${destAction}`
        });
        const data = await res.json();
        debugLog('Ad-hoc swap response: ' + JSON.stringify(data));
        
        if (data.status === 'success') {
            showSuccess(`Swap completed!\n\nReference: ${data.swap_reference}\nAmount: ${amount} BWP\nAction: ${destAction.toUpperCase()}\nDestination: ${destIdentifier}`);
            document.getElementById('swapAmount').value = '';
            document.getElementById('destIdentifier').value = '';
            document.getElementById('adhocIdentifier').value = '';
            document.getElementById('adhocPin').value = '';
        } else {
            showError(data.message);
        }
    } catch(e) {
        showError(e.message);
    }
}

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
    
    if (!institution) { showError('Select institution'); return; }
    if (!assetType) { showError('Select asset type'); return; }
    if (!identifier) { showError('Enter identifier'); return; }
    if (!pin) { showError('Enter PIN'); return; }
    
    showSuccess('Source linked successfully! (Demo)');
    closeLinkModal();
    loadData();
}

function selectLinkedSource(id, name) {
    document.getElementById('linkedSourceSelect').value = id;
    showSuccess(`Selected source: ${name}`);
    document.getElementById('sourceType').value = 'linked';
    toggleSourceFields();
    showStep('swap');
}

function logout() { window.location.href = 'logout.php'; }

loadData();
toggleSourceFields();

<?php if (!$hasTransactionPin): ?>
setTimeout(() => { showPinSetupModal(); }, 1000);
<?php endif; ?>
</script>
</body>
</html>
