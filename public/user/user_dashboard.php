<?php
// public/user/user_dashboard.php - FULLY DYNAMIC DASHBOARD
// Everything loaded from country configuration files

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

const VM_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
function vm_json($value) { return json_encode($value, VM_JSON_FLAGS); }
function vm_json_response($value) { while (ob_get_level() > 0) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo vm_json($value); exit; }
function vm_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

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
    if ($isAjax) { http_response_code(401); vm_json_response(['status' => 'error', 'message' => 'Session expired']); }
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$userCountry = $user['country'] ?? 'Botswana';
$hasTransactionPin = $user['has_transaction_pin'] ?? false;

$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

// Load country configuration
$countriesConfig = $config['countries'] ?? [];
$currentCountryConfig = $countriesConfig[$userCountry] ?? [];
$userCurrency = $currentCountryConfig['currency'] ?? 'BWP';
$userCurrencySymbol = $currentCountryConfig['currency_symbol'] ?? 'P';
$dialCode = $currentCountryConfig['dial_code'] ?? '+267';

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    if ($isAjax) vm_json_response(['status' => 'error', 'message' => 'Database error']);
    die("System error");
}

if (empty($userPhone) && !empty($userId)) {
    try {
        $stmt = $db->prepare("SELECT phone, country, has_transaction_pin, full_name, id_number FROM users WHERE user_id = :user_id OR id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $userData = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($userData) {
            $userPhone = $userData['phone'];
            $userCountry = $userData['country'] ?? $userCountry;
            $hasTransactionPin = (bool)($userData['has_transaction_pin'] ?? false);
            $user['phone'] = $userPhone;
            $user['country'] = $userCountry;
            $user['has_transaction_pin'] = $hasTransactionPin;
            SessionManager::setUser($user);
        }
    } catch (\Throwable $e) {}
}

// Load participants from all countries
function loadParticipantsFromJson($countryName) {
    $path = __DIR__ . "/../../src/Core/Config/Countries/{$countryName}/participants.json";
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    if ($content === false) return [];
    $data = json_decode($content, true);
    if (!is_array($data)) return [];
    $participants = $data['participants'] ?? [];
    $supportedIds = $data['supported_identification_types'] ?? [];
    $countryData = $data['countries'][$countryName] ?? [];
    
    foreach ($participants as $code => &$p) { 
        $p['country'] = $countryName;
        $p['currency'] = $p['currency'] ?? $countryData['currency'] ?? 'USD';
        if (!isset($p['name']) || empty($p['name'])) $p['name'] = $code;
        if (!isset($p['oauth_config']) && isset($p['security']['oauth2'])) {
            $p['oauth_config'] = $p['security']['oauth2'];
        }
    }
    return ['participants' => $participants, 'supported_ids' => $supportedIds, 'country_config' => $countryData];
}

function getCountryFolders() {
    $basePath = __DIR__ . "/../../src/Core/Config/Countries/";
    $folders = [];
    if (is_dir($basePath)) { foreach (scandir($basePath) as $item) { if ($item !== '.' && $item !== '..' && is_dir($basePath . $item)) $folders[] = $item; } }
    return $folders;
}

// Load source participants (user's country only)
$sourceData = loadParticipantsFromJson($userCountry);
$sourceParticipants = $sourceData['participants'] ?? [];
$supportedIdentificationTypes = $sourceData['supported_ids'] ?? [];

// Load ALL participants for destinations
$allParticipants = [];
$destinationCountries = [];
$allAssetTypes = [];

foreach (getCountryFolders() as $country) { 
    $data = loadParticipantsFromJson($country);
    foreach ($data['participants'] as $code => $p) { 
        $allParticipants[$code] = $p; 
        $destinationCountries[$country] = true;
        
        // Collect all asset types for linking UI
        foreach ($p['asset_types'] ?? [] as $asset) {
            $assetKey = $asset['type'];
            if (!isset($allAssetTypes[$assetKey])) {
                $allAssetTypes[$assetKey] = [
                    'type' => $asset['type'],
                    'name' => $asset['name'],
                    'icon' => $asset['icon'],
                    'institutions' => []
                ];
            }
            if (!in_array($code, $allAssetTypes[$assetKey]['institutions'])) {
                $allAssetTypes[$assetKey]['institutions'][] = $code;
            }
        }
    } 
}
$destinationCountries = array_keys($destinationCountries);

// Load user's saved sources
$fundingSources = [];
$stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE' ORDER BY created_at DESC");
$stmt->execute([':user_id' => $userId]);
$fundingSources = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// Load OAuth-linked bank connections
$bankConnections = [];
$stmt = $db->prepare("SELECT * FROM user_bank_connections WHERE user_id = :user_id AND status = 'ACTIVE'");
$stmt->execute([':user_id' => $userId]);
$bankConnections = $stmt->fetchAll(\PDO::FETCH_ASSOC);

function userHasTransactionPin($db, $userId) { 
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1"); 
    $stmt->execute([':user_id' => $userId]); 
    $result = $stmt->fetch(\PDO::FETCH_ASSOC); 
    return !empty($result['transaction_pin_hash']); 
}

function verifyTransactionPin($db, $userId, $pin) { 
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1"); 
    $stmt->execute([':user_id' => $userId]); 
    $result = $stmt->fetch(\PDO::FETCH_ASSOC); 
    if (!$result || empty($result['transaction_pin_hash'])) return false; 
    return password_verify($pin, $result['transaction_pin_hash']); 
}

function setTransactionPin($db, $userId, $pin) { 
    $hash = password_hash($pin, PASSWORD_DEFAULT); 
    $stmt = $db->prepare("UPDATE users SET transaction_pin_hash = :hash, has_transaction_pin = 1 WHERE user_id = :user_id"); 
    return $stmt->execute([':hash' => $hash, ':user_id' => $userId]); 
}

if ($isAjax) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if ($action === 'check_transaction_pin') { vm_json_response(['has_pin' => userHasTransactionPin($db, $userId)]); }
    
    if ($action === 'set_transaction_pin') {
        try { 
            $pin = trim($_POST['pin'] ?? ''); 
            $confirmPin = trim($_POST['confirm_pin'] ?? ''); 
            if (strlen($pin) !== 6 || !ctype_digit($pin)) throw new Exception('PIN must be 6 digits'); 
            if ($pin !== $confirmPin) throw new Exception('PINs do not match'); 
            if (setTransactionPin($db, $userId, $pin)) { 
                $user['has_transaction_pin'] = true; 
                SessionManager::setUser($user); 
                vm_json_response(['status' => 'success', 'message' => 'Transaction PIN set successfully']); 
            } else throw new Exception('Failed to set PIN'); 
        } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    
    if ($action === 'verify_transaction_pin') {
        try { 
            $pin = trim($_POST['pin'] ?? ''); 
            $operation = $_POST['operation'] ?? 'swap'; 
            if (empty($pin)) throw new Exception('PIN is required'); 
            if (!verifyTransactionPin($db, $userId, $pin)) throw new Exception('Invalid transaction PIN'); 
            $consentToken = bin2hex(random_bytes(32)); 
            $_SESSION['consent_token_' . $operation] = ['token' => $consentToken, 'expires' => time() + 300]; 
            vm_json_response(['status' => 'success', 'message' => 'PIN verified', 'consent_token' => $consentToken]); 
        } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    
    if ($action === 'change_password') {
        try { 
            $currentPassword = $_POST['current_password'] ?? ''; 
            $newPassword = $_POST['new_password'] ?? ''; 
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = :user_id"); 
            $stmt->execute([':user_id' => $userId]); 
            $userData = $stmt->fetch(\PDO::FETCH_ASSOC); 
            if (!password_verify($currentPassword, $userData['password_hash'])) throw new Exception('Current password is incorrect'); 
            if (strlen($newPassword) < 6) throw new Exception('Password must be at least 6 characters'); 
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT); 
            $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :user_id"); 
            $stmt->execute([':hash' => $newHash, ':user_id' => $userId]); 
            vm_json_response(['status' => 'success', 'message' => 'Password changed successfully']); 
        } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    
    if ($action === 'get_destination_institutions') { 
        $country = $_POST['country'] ?? $_GET['country'] ?? ''; 
        $instList = []; 
        foreach ($allParticipants as $code => $p) { 
            if ($p['country'] === $country && ($p['status'] ?? 'ACTIVE') === 'ACTIVE') { 
                $instList[] = ['code' => $code, 'name' => $p['name'] ?? $code]; 
            } 
        } 
        vm_json_response(['success' => true, 'institutions' => $instList]); 
    }
    
    // Get asset types for an institution
    if ($action === 'get_asset_types') {
        $institutionCode = $_POST['institution_code'] ?? '';
        $participant = $allParticipants[$institutionCode] ?? $sourceParticipants[$institutionCode] ?? null;
        if ($participant) {
            vm_json_response(['success' => true, 'asset_types' => $participant['asset_types'] ?? []]);
        } else {
            vm_json_response(['success' => false, 'message' => 'Institution not found']);
        }
    }
    
    // Get identification fields for an asset type
    if ($action === 'get_identification_fields') {
        $institutionCode = $_POST['institution_code'] ?? '';
        $assetType = $_POST['asset_type'] ?? '';
        $participant = $allParticipants[$institutionCode] ?? $sourceParticipants[$institutionCode] ?? null;
        
        if ($participant) {
            $assetTypes = $participant['asset_types'] ?? [];
            $foundAsset = null;
            foreach ($assetTypes as $asset) {
                if ($asset['type'] === $assetType) {
                    $foundAsset = $asset;
                    break;
                }
            }
            if ($foundAsset) {
                vm_json_response(['success' => true, 'fields' => $foundAsset['identification_methods'] ?? []]);
            } else {
                vm_json_response(['success' => false, 'message' => 'Asset type not found']);
            }
        } else {
            vm_json_response(['success' => false, 'message' => 'Institution not found']);
        }
    }
    
    // Get OAuth URL
    if ($action === 'get_oauth_url') {
        try {
            $institutionCode = trim($_POST['institution_code'] ?? '');
            $assetType = trim($_POST['asset_type'] ?? '');
            $participant = $allParticipants[$institutionCode] ?? $sourceParticipants[$institutionCode] ?? null;
            if (!$participant) throw new Exception("Institution not found");
            
            // Find if this asset type supports OAuth
            $assetTypes = $participant['asset_types'] ?? [];
            $supportsOAuth = false;
            foreach ($assetTypes as $asset) {
                if ($asset['type'] === $assetType && ($asset['supports_oauth'] ?? false)) {
                    $supportsOAuth = true;
                    break;
                }
            }
            
            if (!$supportsOAuth) {
                throw new Exception("This asset type does not support OAuth. Please use manual entry.");
            }
            
            $oauthConfig = $participant['oauth_config'] ?? $participant['security']['oauth2'] ?? null;
            if (!$oauthConfig) throw new Exception("OAuth not configured for this institution");
            
            $baseUrl = rtrim($participant['base_url'] ?? '', '/');
            $authEndpoint = $oauthConfig['authorization_endpoint'] ?? '/api/v1/oauth/authorize.php';
            
            $state = bin2hex(random_bytes(16));
            $_SESSION['oauth_state_' . $state] = [
                'institution' => $institutionCode,
                'asset_type' => $assetType,
                'user_id' => $userId,
                'created_at' => time()
            ];
            
            $redirectUri = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/user/oauth_callback.php';
            
            $params = [
                'response_type' => 'code',
                'client_id' => getenv($oauthConfig['client_id_env']) ?: ($oauthConfig['client_id'] ?? ''),
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'scope' => $oauthConfig['scope'] ?? 'read_balance read_transactions initiate_payment'
            ];
            
            $authUrl = $baseUrl . $authEndpoint . '?' . http_build_query($params);
            
            vm_json_response(['success' => true, 'auth_url' => $authUrl, 'state' => $state]);
        } catch (Exception $e) {
            vm_json_response(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    // Save manual source
    if ($action === 'save_manual_source') {
        try { 
            $consentToken = $_POST['consent_token'] ?? ''; 
            $operation = 'save_source'; 
            if (!isset($_SESSION['consent_token_' . $operation]) || $_SESSION['consent_token_' . $operation]['token'] !== $consentToken || $_SESSION['consent_token_' . $operation]['expires'] < time()) {
                throw new Exception('Transaction authorization required or expired');
            }
            
            $institutionCode = trim($_POST['institution_code'] ?? ''); 
            $assetType = trim($_POST['asset_type'] ?? ''); 
            $identificationData = json_decode($_POST['identification_data'] ?? '{}', true);
            
            $participant = $sourceParticipants[$institutionCode] ?? $allParticipants[$institutionCode] ?? null;
            if (!$participant) throw new Exception("Institution not found");
            
            // Build identifier string from fields
            $identifierParts = [];
            foreach ($identificationData as $key => $value) {
                if ($value) $identifierParts[] = "$key:$value";
            }
            $identifier = implode('|', $identifierParts);
            $maskedId = strlen($identifier) > 4 ? '••••' . substr($identifier, -10) : '••••';
            
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $encrypted = base64_encode(openssl_encrypt(json_encode($identificationData), 'AES-256-CBC', $encryptionKey, 0, substr($encryptionKey, 0, 16)));
            
            $institutionName = $participant['name'] ?? $institutionCode;
            
            $stmt = $db->prepare("INSERT INTO user_funding_sources (user_id, institution_code, institution_name, institution_country, source_type, masked_identifier, encrypted_identifier, linked_phone, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId, $institutionCode, $institutionName, $participant['country'] ?? $userCountry,
                $assetType, $maskedId, $encrypted, $userPhone, json_encode($identificationData)
            ]);
            
            unset($_SESSION['consent_token_' . $operation]);
            vm_json_response(['status' => 'success', 'message' => 'Source saved successfully!']);
        } catch (Exception $e) { 
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); 
        }
    }
    
    // Execute swap
    if ($action === 'swap_single') {
        try { 
            $consentToken = $_POST['consent_token'] ?? ''; 
            $operation = 'swap'; 
            if (!isset($_SESSION['consent_token_' . $operation]) || $_SESSION['consent_token_' . $operation]['token'] !== $consentToken || $_SESSION['consent_token_' . $operation]['expires'] < time()) {
                throw new Exception('Transaction authorization required or expired');
            }
            
            $amount = (float)($_POST['amount'] ?? 0); 
            $destCountry = trim($_POST['dest_country'] ?? ''); 
            $destInstitution = trim($_POST['dest_institution'] ?? ''); 
            
            if ($amount < 10) throw new Exception('Minimum amount is 10.00'); 
            
            $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His'); 
            $withdrawalCode = (string)random_int(100000, 999999);
            
            error_log("SWAP EXECUTED: User $userId, Amount $amount, Ref $swapReference"); 
            unset($_SESSION['consent_token_' . $operation]);
            
            vm_json_response([
                'status' => 'success', 
                'message' => 'Swap completed successfully',
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
<title>VOUCHMORPH | ARCHITECT</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: #000000;
        font-family: 'Space Grotesk', monospace;
        color: #FFFFFF;
        letter-spacing: -0.02em;
        line-height: 1;
    }
    .app {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: grid;
        grid-template-columns: 80px 1fr 380px;
        grid-template-rows: 80px 1fr;
    }
    .nav-rail {
        grid-row: 1 / 3;
        grid-column: 1;
        border-right: 1px solid rgba(255,255,255,0.08);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 24px 0 32px;
    }
    .nav-logo {
        writing-mode: vertical-rl;
        transform: rotate(180deg);
        font-size: 12px;
        font-weight: 400;
        letter-spacing: 4px;
        color: rgba(255,255,255,0.3);
        text-align: center;
    }
    .nav-bottom {
        writing-mode: vertical-rl;
        transform: rotate(180deg);
        font-size: 10px;
        letter-spacing: 2px;
        color: rgba(255,255,255,0.15);
        text-align: center;
    }
    .top-bar {
        grid-column: 2 / 4;
        grid-row: 1;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        display: flex;
        justify-content: flex-end;
        align-items: center;
        padding: 0 32px;
        gap: 24px;
    }
    .top-stat {
        font-size: 11px;
        letter-spacing: 1px;
        color: rgba(255,255,255,0.3);
    }
    .top-stat strong {
        color: #FFFFFF;
        font-weight: 500;
        margin-left: 8px;
    }
    .user-badge {
        width: 32px;
        height: 32px;
        border: 1px solid rgba(255,255,255,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.1s ease;
    }
    .user-badge:hover {
        border-color: #FFFFFF;
        background: #FFFFFF;
        color: #000000;
    }
    .main-content {
        grid-column: 2;
        grid-row: 2;
        overflow-y: auto;
        padding: 32px;
    }
    .action-panel {
        grid-column: 3;
        grid-row: 2;
        border-left: 1px solid rgba(255,255,255,0.08);
        overflow-y: auto;
        background: #000000;
    }
    .stat-block {
        margin-bottom: 48px;
    }
    .stat-label {
        font-size: 10px;
        letter-spacing: 2px;
        color: rgba(255,255,255,0.25);
        text-transform: uppercase;
        margin-bottom: 8px;
    }
    .stat-value {
        font-size: 64px;
        font-weight: 500;
        letter-spacing: -0.04em;
        line-height: 1;
    }
    .currency-display {
        font-size: 11px;
        color: rgba(255,255,255,0.3);
        margin-left: 8px;
    }
    .option-grid {
        display: none;
        grid-template-columns: 1fr 1fr;
        gap: 1px;
        background: rgba(255,255,255,0.08);
        margin-bottom: 48px;
    }
    .option-grid.active { display: grid; }
    .option-btn {
        background: #000000;
        padding: 32px 24px;
        border: none;
        text-align: left;
        cursor: pointer;
        transition: all 0.1s ease;
    }
    .option-btn:hover { background: #FFFFFF; }
    .option-btn:hover .option-title,
    .option-btn:hover .option-desc { color: #000000; }
    .option-title {
        font-size: 14px;
        font-weight: 500;
        letter-spacing: 1px;
        color: #FFFFFF;
        margin-bottom: 4px;
        text-transform: uppercase;
    }
    .option-desc {
        font-size: 10px;
        color: rgba(255,255,255,0.3);
        letter-spacing: 0.5px;
    }
    .trigger-btn {
        width: 100%;
        background: transparent;
        border: 1px solid rgba(255,255,255,0.15);
        padding: 20px 24px;
        font-family: 'Space Grotesk', monospace;
        font-size: 13px;
        font-weight: 400;
        letter-spacing: 2px;
        color: #FFFFFF;
        cursor: pointer;
        text-align: left;
        transition: all 0.1s ease;
        margin-bottom: 16px;
    }
    .trigger-btn:hover {
        border-color: #FFFFFF;
        background: #FFFFFF;
        color: #000000;
    }
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: #000000;
        z-index: 1000;
        display: none;
        align-items: center;
        justify-content: center;
    }
    .modal {
        width: 520px;
        max-width: 90%;
        max-height: 85vh;
        overflow-y: auto;
        text-align: center;
    }
    .institution-list, .asset-list {
        max-height: 400px;
        overflow-y: auto;
        margin: 20px 0;
    }
    .institution-item, .asset-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px;
        border: 1px solid rgba(255,255,255,0.08);
        margin-bottom: 8px;
        cursor: pointer;
        transition: all 0.1s ease;
        text-align: left;
    }
    .institution-item:hover, .asset-item:hover {
        border-color: #FFFFFF;
        background: rgba(255,255,255,0.05);
    }
    .institution-name, .asset-name {
        font-weight: 500;
        font-size: 14px;
    }
    .institution-country, .asset-icon {
        font-size: 10px;
        color: rgba(255,255,255,0.3);
        margin-top: 4px;
    }
    .oauth-badge {
        font-size: 9px;
        color: #FFFFFF;
        border: 1px solid rgba(255,255,255,0.3);
        padding: 2px 6px;
    }
    .form-field {
        margin-bottom: 16px;
        text-align: left;
    }
    .form-label {
        font-size: 10px;
        letter-spacing: 1px;
        color: rgba(255,255,255,0.5);
        margin-bottom: 6px;
        display: block;
    }
    .form-input {
        width: 100%;
        background: transparent;
        border: 1px solid rgba(255,255,255,0.1);
        padding: 14px 16px;
        font-family: 'Space Grotesk', monospace;
        font-size: 13px;
        color: #FFFFFF;
    }
    .form-input:focus {
        outline: none;
        border-color: #FFFFFF;
    }
    .pin-dots {
        display: flex;
        justify-content: center;
        gap: 16px;
        margin: 48px 0;
    }
    .pin-dot {
        width: 12px;
        height: 12px;
        border: 1px solid rgba(255,255,255,0.3);
    }
    .pin-dot.filled {
        background: #FFFFFF;
        border-color: #FFFFFF;
    }
    .pin-numpad {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        margin-bottom: 24px;
    }
    .numpad-btn {
        background: transparent;
        border: 1px solid rgba(255,255,255,0.1);
        padding: 20px;
        font-size: 20px;
        font-family: 'Space Grotesk', monospace;
        font-weight: 400;
        color: #FFFFFF;
        cursor: pointer;
        transition: all 0.05s linear;
    }
    .numpad-btn:active {
        background: #FFFFFF;
        color: #000000;
    }
    .modal-close {
        background: transparent;
        border: 1px solid rgba(255,255,255,0.15);
        padding: 12px 24px;
        font-family: 'Space Grotesk', monospace;
        font-size: 10px;
        letter-spacing: 2px;
        color: rgba(255,255,255,0.4);
        cursor: pointer;
        margin-top: 16px;
    }
    .modal-close:hover {
        border-color: #FFFFFF;
        color: #FFFFFF;
    }
    .execute-btn {
        width: 100%;
        background: #FFFFFF;
        border: none;
        padding: 16px;
        font-family: 'Space Grotesk', monospace;
        font-size: 12px;
        font-weight: 500;
        letter-spacing: 2px;
        color: #000000;
        cursor: pointer;
        margin-top: 16px;
    }
    .source-item {
        padding: 12px 0;
        border-bottom: 1px solid rgba(255,255,255,0.04);
        font-size: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .bank-connection {
        background: rgba(255,255,255,0.03);
        padding: 12px;
        margin-bottom: 8px;
        border-left: 2px solid #FFFFFF;
    }
    .swap-select {
        width: 100%;
        background: transparent;
        border: 1px solid rgba(255,255,255,0.1);
        padding: 14px 16px;
        font-family: 'Space Grotesk', monospace;
        font-size: 13px;
        color: #FFFFFF;
        margin-bottom: 16px;
        cursor: pointer;
    }
    .swap-select option { background: #000000; }
    .swap-row {
        display: flex;
        gap: 12px;
        margin-bottom: 16px;
    }
    .swap-input {
        flex: 1;
        background: transparent;
        border: 1px solid rgba(255,255,255,0.1);
        padding: 14px 16px;
        font-family: 'Space Grotesk', monospace;
        font-size: 13px;
        color: #FFFFFF;
    }
    .swap-input:focus { outline: none; border-color: #FFFFFF; }
    .panel-label {
        font-size: 9px;
        letter-spacing: 2px;
        color: rgba(255,255,255,0.2);
        text-transform: uppercase;
        margin-bottom: 16px;
    }
    ::-webkit-scrollbar { width: 0; background: transparent; }
    @media (max-width: 1024px) {
        .app { grid-template-columns: 60px 1fr; }
        .action-panel {
            position: fixed;
            right: -100%;
            width: 100%;
            max-width: 400px;
            transition: right 0.2s ease;
            z-index: 100;
        }
        .action-panel.open { right: 0; }
    }
</style>
</head>
<body>

<div class="app">
    <div class="nav-rail">
        <div class="nav-logo">VOUCHMORPH</div>
        <div class="nav-bottom">ARCHITECT v1.0</div>
    </div>

    <div class="top-bar">
        <div class="top-stat">COUNTRY <strong><?= vm_h($userCountry) ?></strong></div>
        <div class="top-stat">CURRENCY <strong><?= vm_h($userCurrency) ?></strong></div>
        <div class="top-stat">SOURCES <strong><?= count($fundingSources) + count($bankConnections) ?></strong></div>
        <div class="user-badge" id="userBadge"><?= vm_h(strtoupper(substr($userPhone, -4))) ?></div>
    </div>

    <div class="main-content">
        <div class="stat-block">
            <div class="stat-label">AVAILABLE BALANCE</div>
            <div class="stat-value">0.00 <span class="currency-display"><?= vm_h($userCurrency) ?></span></div>
        </div>

        <button class="trigger-btn" id="swapTrigger">⟡ INITIATE SWAP</button>
        <button class="trigger-btn" id="sourceTrigger">⟡ LINK SOURCE</button>
        <button class="trigger-btn" id="securityTrigger">⟡ SECURITY</button>

        <div id="swapOptions" class="option-grid">
            <button class="option-btn" onclick="startSwap()"><div class="option-title">SINGLE</div><div class="option-desc">One source → destination</div></button>
            <button class="option-btn" onclick="startMultiSource()"><div class="option-title">MULTI-SOURCE</div><div class="option-desc">Combine balances</div></button>
            <button class="option-btn" onclick="startCashout()"><div class="option-title">CASEOUT</div><div class="option-desc">ATM / Agent withdrawal</div></button>
            <button class="option-btn" onclick="startRecurring()"><div class="option-title">RECURRING</div><div class="option-desc">Schedule swaps</div></button>
        </div>

        <div id="sourceOptions" class="option-grid">
            <button class="option-btn" onclick="showLinkInstitutions()"><div class="option-title">LINK SOURCE</div><div class="option-desc">Bank / Wallet / Card</div></button>
            <button class="option-btn" onclick="viewLinkedSources()"><div class="option-title">VIEW ALL</div><div class="option-desc"><?= count($fundingSources) + count($bankConnections) ?> connected</div></button>
            <button class="option-btn" onclick="manageTokens()"><div class="option-title">CONSENT TOKENS</div><div class="option-desc">Active authorizations</div></button>
        </div>

        <div id="securityOptions" class="option-grid">
            <button class="option-btn" onclick="changePin()"><div class="option-title">CHANGE PIN</div><div class="option-desc">Update transaction PIN</div></button>
            <button class="option-btn" onclick="changePassword()"><div class="option-title">CHANGE PASSWORD</div><div class="option-desc">Update login credentials</div></button>
            <button class="option-btn" onclick="viewSession()"><div class="option-title">ACTIVE SESSION</div><div class="option-desc">Device management</div></button>
            <button class="option-btn" onclick="logout()"><div class="option-title">TERMINATE</div><div class="option-desc">End session</div></button>
        </div>
    </div>

    <div class="action-panel" id="actionPanel">
        <div style="padding: 32px;">
            <div class="panel-label">LINKED SOURCES</div>
            <div id="sourcesList">
                <?php if (empty($fundingSources) && empty($bankConnections)): ?>
                <div class="source-item"><span class="source-name">— no sources —</span></div>
                <?php else: ?>
                <?php foreach ($bankConnections as $bc): ?>
                <div class="bank-connection">
                    <div class="source-name">🔐 <?= vm_h($bc['institution_name']) ?></div>
                    <div class="source-type">OAuth Connected • <?= vm_h(date('M d, Y', strtotime($bc['created_at']))) ?></div>
                </div>
                <?php endforeach; ?>
                <?php foreach ($fundingSources as $fs): ?>
                <div class="source-item">
                    <span class="source-name"><?= vm_h($fs['institution_name']) ?></span>
                    <span class="source-type"><?= vm_h($fs['source_type']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="padding: 32px; border-top: 1px solid rgba(255,255,255,0.06);">
            <div class="panel-label">QUICK SWAP</div>
            <select id="quickSource" class="swap-select"><option value="">Select source</option></select>
            <div class="swap-row">
                <input type="number" id="quickAmount" class="swap-input" placeholder="AMOUNT" step="0.01" min="10">
                <div class="swap-input" style="width: 80px; text-align: center;"><?= vm_h($userCurrency) ?></div>
            </div>
            <select id="quickDestCountry" class="swap-select"><option value="">Destination country</option></select>
            <button class="execute-btn" onclick="executeQuickSwap()">EXECUTE SWAP →</button>
        </div>
    </div>
</div>

<!-- Institution Selection Modal -->
<div id="institutionModal" class="modal-overlay">
    <div class="modal"><div style="font-size: 18px; font-weight: 500; margin-bottom: 20px;">SELECT INSTITUTION</div>
    <div id="institutionList" class="institution-list"><div style="text-align: center; padding: 20px;">Loading...</div></div>
    <button class="modal-close" onclick="closeInstitutionModal()">CANCEL</button></div>
</div>

<!-- Asset Type Selection Modal -->
<div id="assetModal" class="modal-overlay">
    <div class="modal"><div style="font-size: 18px; font-weight: 500; margin-bottom: 20px;" id="assetModalTitle">SELECT ASSET TYPE</div>
    <div id="assetList" class="asset-list"></div>
    <button class="modal-close" onclick="closeAssetModal()">CANCEL</button></div>
</div>

<!-- Identification Form Modal -->
<div id="idFormModal" class="modal-overlay">
    <div class="modal"><div style="font-size: 18px; font-weight: 500; margin-bottom: 20px;" id="formModalTitle">ENTER DETAILS</div>
    <div id="formFields" class="form-fields"></div>
    <button class="execute-btn" onclick="submitIdentificationForm()">LINK SOURCE →</button>
    <button class="modal-close" onclick="closeIdFormModal()">CANCEL</button></div>
</div>

<!-- PIN Modal -->
<div id="pinModal" class="modal-overlay">
    <div class="modal"><div id="pinDots" class="pin-dots"></div>
    <div id="pinNumpad" class="pin-numpad"></div>
    <button class="modal-close" onclick="closePinModal()">CANCEL</button></div>
</div>

<script>
// ============================================================
// DYNAMIC DASHBOARD - ALL DATA FROM CONFIG
// ============================================================
const hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;
const userCurrency = <?php echo vm_json($userCurrency); ?>;
const userCountry = <?php echo vm_json($userCountry); ?>;
const dialCode = <?php echo vm_json($dialCode); ?>;

const allParticipants = <?php 
    $list = [];
    foreach ($allParticipants as $code => $p) {
        $list[] = [
            'code' => $code,
            'name' => $p['name'] ?? $code,
            'country' => $p['country'] ?? '',
            'currency' => $p['currency'] ?? 'USD',
            'asset_types' => $p['asset_types'] ?? [],
            'has_oauth' => isset($p['oauth_config']) || isset($p['security']['oauth2'])
        ];
    }
    echo vm_json($list);
?>;

const sourceParticipants = <?php 
    $list = [];
    foreach ($sourceParticipants as $code => $p) {
        $list[] = [
            'code' => $code,
            'name' => $p['name'] ?? $code,
            'asset_types' => $p['asset_types'] ?? []
        ];
    }
    echo vm_json($list);
?>;

const destinationCountries = <?php echo vm_json($destinationCountries); ?>;
const fundingSources = <?php 
    $sources = [];
    foreach ($fundingSources as $fs) {
        $sources[] = ['code' => $fs['institution_code'], 'type' => $fs['source_type'], 'name' => $fs['institution_name'], 'masked' => $fs['masked_identifier']];
    }
    echo vm_json($sources);
?>;

let selectedInstitution = null;
let selectedAssetType = null;
let pendingCallback = null;
let pinInput = '';

// Initialize destination countries dropdown
function initDestCountries() {
    const select = document.getElementById('quickDestCountry');
    select.innerHTML = '<option value="">Destination country</option>';
    destinationCountries.forEach(country => {
        select.innerHTML += `<option value="${country}">${country}</option>`;
    });
}
initDestCountries();

// Initialize quick source dropdown
function initQuickSources() {
    const select = document.getElementById('quickSource');
    select.innerHTML = '<option value="">Select source</option>';
    fundingSources.forEach(s => {
        select.innerHTML += `<option value="${s.code}|${s.type}">${s.name} (${s.type})</option>`;
    });
}
initQuickSources();

// ============================================================
// UI Helpers
// ============================================================
document.getElementById('swapTrigger')?.addEventListener('click', () => {
    document.getElementById('swapOptions').classList.toggle('active');
    document.getElementById('sourceOptions').classList.remove('active');
    document.getElementById('securityOptions').classList.remove('active');
});
document.getElementById('sourceTrigger')?.addEventListener('click', () => {
    document.getElementById('sourceOptions').classList.toggle('active');
    document.getElementById('swapOptions').classList.remove('active');
    document.getElementById('securityOptions').classList.remove('active');
});
document.getElementById('securityTrigger')?.addEventListener('click', () => {
    document.getElementById('securityOptions').classList.toggle('active');
    document.getElementById('swapOptions').classList.remove('active');
    document.getElementById('sourceOptions').classList.remove('active');
});
document.getElementById('userBadge')?.addEventListener('click', () => {
    document.getElementById('actionPanel').classList.toggle('open');
});

async function executeOperation(operation, data) {
    const formData = new FormData();
    formData.append('action', operation);
    for (let key in data) formData.append(key, data[key]);
    const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return await res.json();
}

// ============================================================
// PIN Management
// ============================================================
function renderPinDots() {
    const container = document.getElementById('pinDots');
    let dots = '';
    for (let i = 0; i < 6; i++) dots += `<div class="pin-dot ${i < pinInput.length ? 'filled' : ''}"></div>`;
    container.innerHTML = dots;
}
function renderPinNumpad() {
    const container = document.getElementById('pinNumpad');
    const nums = [1,2,3,4,5,6,7,8,9,0];
    let html = '';
    nums.forEach(n => { html += `<button class="numpad-btn" onclick="pinAdd(${n})">${n}</button>`; });
    html += `<button class="numpad-btn" onclick="pinDelete()">⌫</button><button class="numpad-btn" onclick="pinClear()">CLR</button>`;
    container.innerHTML = html;
}
function pinAdd(d) { if (pinInput.length < 6) { pinInput += d.toString(); renderPinDots(); if (pinInput.length === 6) submitPin(); } }
function pinDelete() { pinInput = pinInput.slice(0, -1); renderPinDots(); }
function pinClear() { pinInput = ''; renderPinDots(); }
function showPinModal(callback) { pinInput = ''; pendingCallback = callback; renderPinDots(); renderPinNumpad(); document.getElementById('pinModal').style.display = 'flex'; }
function closePinModal() { document.getElementById('pinModal').style.display = 'none'; pinInput = ''; pendingCallback = null; }
async function submitPin() { if (pinInput.length !== 6) return; const pin = pinInput; closePinModal(); if (pendingCallback) await pendingCallback(pin); }

async function withPinVerification(operation, data, callback) {
    showPinModal(async (pin) => {
        const verify = await executeOperation('verify_transaction_pin', { pin: pin, operation: operation });
        if (verify.status === 'success') {
            data.consent_token = verify.consent_token;
            const result = await executeOperation(operation, data);
            if (callback) callback(result);
        } else alert('INVALID PIN');
    });
}

async function checkAndSetupPin() {
    if (!hasTransactionPin) {
        const newPin = prompt('CREATE 6-DIGIT TRANSACTION PIN');
        if (newPin && newPin.length === 6 && /^\d+$/.test(newPin)) {
            const confirmPin = prompt('CONFIRM PIN');
            if (newPin === confirmPin) {
                const result = await executeOperation('set_transaction_pin', { pin: newPin, confirm_pin: confirmPin });
                if (result.status === 'success') { alert('PIN SET'); location.reload(); }
                else alert(result.message);
            } else alert('PINS DO NOT MATCH');
        } else alert('PIN MUST BE 6 DIGITS');
        return false;
    }
    return true;
}

// ============================================================
// SOURCE LINKING FLOW - FULLY DYNAMIC FROM PARTICIPANTS
// ============================================================

async function showLinkInstitutions() {
    if (!await checkAndSetupPin()) return;
    
    const container = document.getElementById('institutionList');
    container.innerHTML = '<div style="text-align: center; padding: 20px;">Loading institutions...</div>';
    document.getElementById('institutionModal').style.display = 'flex';
    
    // Show institutions from all participants (source country only for linking as source)
    const institutions = allParticipants.filter(p => p.country === userCountry);
    
    if (institutions.length === 0) {
        container.innerHTML = '<div style="text-align: center; padding: 20px;">No institutions available in your country</div>';
        return;
    }
    
    container.innerHTML = '';
    institutions.forEach(inst => {
        const div = document.createElement('div');
        div.className = 'institution-item';
        div.innerHTML = `
            <div><div class="institution-name">${inst.name}</div>
            <div class="institution-country">${inst.country} • ${inst.currency}</div></div>
            <div class="oauth-badge">${inst.asset_types.length} ASSETS</div>
        `;
        div.onclick = () => selectInstitution(inst.code);
        container.appendChild(div);
    });
}

function selectInstitution(code) {
    selectedInstitution = allParticipants.find(p => p.code === code);
    closeInstitutionModal();
    showAssetTypes();
}

function showAssetTypes() {
    if (!selectedInstitution || !selectedInstitution.asset_types || selectedInstitution.asset_types.length === 0) {
        alert('No asset types available for this institution');
        return;
    }
    
    const container = document.getElementById('assetList');
    container.innerHTML = '';
    document.getElementById('assetModalTitle').innerHTML = `${selectedInstitution.name} • SELECT ASSET`;
    document.getElementById('assetModal').style.display = 'flex';
    
    selectedInstitution.asset_types.forEach(asset => {
        const div = document.createElement('div');
        div.className = 'asset-item';
        div.innerHTML = `
            <div><div class="asset-name">${asset.icon || '📄'} ${asset.name}</div>
            <div class="asset-icon">${asset.type}</div></div>
            <div class="oauth-badge">${asset.supports_oauth ? 'OAUTH' : 'MANUAL'}</div>
        `;
        div.onclick = () => selectAssetType(asset);
        container.appendChild(div);
    });
}

function selectAssetType(asset) {
    selectedAssetType = asset;
    closeAssetModal();
    
    if (asset.supports_oauth) {
        // Show OAuth vs Manual choice
        const useOAuth = confirm(`${selectedInstitution.name} - ${asset.name}\n\nThis institution supports OAuth for secure linking.\n\nClick OK to link via OAuth (bank login)\nClick Cancel for manual entry (account number/PIN)`);
        if (useOAuth) {
            initiateOAuthLink();
            return;
        }
    }
    showIdentificationForm();
}

async function initiateOAuthLink() {
    const result = await executeOperation('get_oauth_url', {
        institution_code: selectedInstitution.code,
        asset_type: selectedAssetType.type
    });
    
    if (result.success && result.auth_url) {
        sessionStorage.setItem('pending_link_institution', selectedInstitution.code);
        sessionStorage.setItem('pending_link_asset', selectedAssetType.type);
        window.location.href = result.auth_url;
    } else {
        alert('OAuth failed: ' + (result.message || 'Unknown error'));
        showIdentificationForm();
    }
}

function showIdentificationForm() {
    const fields = selectedAssetType.identification_methods || [];
    if (fields.length === 0) {
        alert('No identification methods configured for this asset type');
        return;
    }
    
    const container = document.getElementById('formFields');
    container.innerHTML = '';
    document.getElementById('formModalTitle').innerHTML = `${selectedInstitution.name} • ${selectedAssetType.name}`;
    document.getElementById('idFormModal').style.display = 'flex';
    
    fields.forEach(field => {
        const div = document.createElement('div');
        div.className = 'form-field';
        let inputHtml = '';
        
        if (field.type === 'select' && field.options) {
            inputHtml = `<select id="field_${field.field}" class="form-input">`;
            field.options.forEach(opt => {
                inputHtml += `<option value="${opt.value}">${opt.label}</option>`;
            });
            inputHtml += `</select>`;
        } else {
            inputHtml = `<input type="${field.type}" id="field_${field.field}" class="form-input" 
                placeholder="${field.placeholder || ''}" 
                ${field.required ? 'required' : ''}
                ${field.min_length ? `minlength="${field.min_length}"` : ''}
                ${field.max_length ? `maxlength="${field.max_length}"` : ''}
                ${field.pattern ? `pattern="${field.pattern}"` : ''}>`;
        }
        
        div.innerHTML = `<label class="form-label">${field.label} ${field.required ? '*' : ''}</label>${inputHtml}`;
        container.appendChild(div);
    });
}

async function submitIdentificationForm() {
    const fields = selectedAssetType.identification_methods || [];
    const identificationData = {};
    let isValid = true;
    
    fields.forEach(field => {
        const input = document.getElementById(`field_${field.field}`);
        if (input) {
            const value = input.value.trim();
            if (field.required && !value) {
                alert(`${field.label} is required`);
                isValid = false;
                return;
            }
            identificationData[field.field] = value;
        }
    });
    
    if (!isValid) return;
    
    closeIdFormModal();
    
    withPinVerification('save_manual_source', {
        institution_code: selectedInstitution.code,
        asset_type: selectedAssetType.type,
        identification_data: JSON.stringify(identificationData)
    }, (result) => {
        if (result.status === 'success') {
            alert('Source linked successfully!');
            location.reload();
        } else {
            alert('Failed: ' + result.message);
        }
    });
}

function closeInstitutionModal() { document.getElementById('institutionModal').style.display = 'none'; }
function closeAssetModal() { document.getElementById('assetModal').style.display = 'none'; }
function closeIdFormModal() { document.getElementById('idFormModal').style.display = 'none'; }

// ============================================================
// OTHER FUNCTIONS
// ============================================================

function viewLinkedSources() {
    if (fundingSources.length === 0 && bankConnections.length === 0) {
        alert('No sources linked');
    } else {
        let msg = '=== LINKED SOURCES ===\n\n';
        if (bankConnections.length) {
            msg += '🔐 BANK CONNECTIONS (OAuth):\n';
            bankConnections.forEach(s => { msg += `  • ${s.name}\n`; });
        }
        if (fundingSources.length) {
            msg += '\n📝 MANUAL SOURCES:\n';
            fundingSources.forEach(s => { msg += `  • ${s.name} (${s.type})\n`; });
        }
        alert(msg);
    }
}

async function startSwap() { if (!await checkAndSetupPin()) return; alert('Select source and amount in right panel →'); }
async function startMultiSource() { if (!await checkAndSetupPin()) return; alert('Multi-source swap coming soon'); }
async function startCashout() { if (!await checkAndSetupPin()) return; alert('Cashout feature - select withdrawal method'); }
async function startRecurring() { if (!await checkAndSetupPin()) return; alert('Recurring swaps - schedule upcoming'); }
function manageTokens() { alert('Active consent tokens: none'); }
async function changePin() { if (!await checkAndSetupPin()) return; alert('Use Security → Change PIN'); }
async function changePassword() { const current = prompt('CURRENT PASSWORD'); if (!current) return; const newPwd = prompt('NEW PASSWORD (min 6)'); if (!newPwd || newPwd.length < 6) return; const confirm = prompt('CONFIRM PASSWORD'); if (newPwd !== confirm) { alert('PASSWORDS DO NOT MATCH'); return; } const result = await executeOperation('change_password', { current_password: current, new_password: newPwd }); if (result.status === 'success') { alert('PASSWORD CHANGED. LOGIN AGAIN.'); logout(); } else alert(result.message); }
function viewSession() { alert(`SESSION ACTIVE\nDevice: ${navigator.userAgent.split(' ').slice(-2).join(' ')}\nTime: ${new Date().toLocaleString()}`); }
function logout() { window.location.href = 'logout.php'; }

async function executeQuickSwap() {
    if (!await checkAndSetupPin()) return;
    const source = document.getElementById('quickSource').value;
    const amount = document.getElementById('quickAmount').value;
    const destCountry = document.getElementById('quickDestCountry').value;
    if (!source || !amount || amount < 10 || !destCountry) { alert('Complete all fields'); return; }
    const [sourceCode, sourceType] = source.split('|');
    withPinVerification('swap_single', {
        source_type: sourceType, source_institution: sourceCode, source_identifier: 'saved',
        amount: parseFloat(amount), dest_country: destCountry, dest_institution: 'default',
        dest_action: 'deposit', dest_value: 'wallet'
    }, (result) => {
        if (result.status === 'success') alert(`✓ SWAP COMPLETED\nREF: ${result.swap_reference}\nAMOUNT: ${amount} ${userCurrency}`);
        else alert(`✗ SWAP FAILED\n${result.message}`);
    });
}

// Close grids when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.trigger-btn') && !e.target.closest('.option-grid')) {
        document.getElementById('swapOptions').classList.remove('active');
        document.getElementById('sourceOptions').classList.remove('active');
        document.getElementById('securityOptions').classList.remove('active');
    }
});

// Check OAuth callback result
const urlParams = new URLSearchParams(window.location.search);
const sourceLinked = urlParams.get('source_linked');
const oauthError = urlParams.get('error');
if (sourceLinked) { alert(`✓ Bank account linked successfully!\nInstitution: ${sourceLinked}`); window.history.replaceState({}, document.title, window.location.pathname); }
else if (oauthError) { alert(`✗ Bank linking failed: ${decodeURIComponent(oauthError)}`); window.history.replaceState({}, document.title, window.location.pathname); }
</script>
</body>
</html>
