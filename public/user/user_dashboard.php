<?php
// public/user/user_dashboard.php - Orchestration Layer Dashboard

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

// If country registry not found, log error
if (empty($countries)) {
    error_log("Country registry not found at: " . $countryRegistryPath);
}

// ============================================================
// LOAD PARTICIPANTS WITH FULL ASSET TYPE DEFINITIONS
// ============================================================
$allParticipants = [];
$participantsByCountry = [];
$participantAssetDefs = [];

$participantsPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/participants.json';

if (file_exists($participantsPath)) {
    $jsonContent = file_get_contents($participantsPath);
    $data = json_decode($jsonContent, true);
    
    if ($data && isset($data['participants'])) {
        foreach ($data['participants'] as $code => $participant) {
            // Skip VOUCHMORPH as a source/destination option (it's the orchestrator)
            if ($code === 'VOUCHMORPH') continue;
            
            $assetTypeDefinitions = [];
            $assetTypesList = [];
            
            if (isset($participant['asset_type_definitions'])) {
                foreach ($participant['asset_type_definitions'] as $assetType => $def) {
                    $assetTypeDefinitions[$assetType] = $def;
                    $assetTypesList[] = [
                        'type' => $assetType,
                        'name' => $def['name'] ?? $assetType,
                        'icon' => $def['ui']['icon'] ?? getAssetIcon($assetType),
                        'delivery_modes' => $def['delivery_modes'] ?? ['deposit', 'cashout'],
                        'amount_config' => $def['amount'] ?? null,
                        'fields' => $def['fields'] ?? []
                    ];
                }
            } elseif (isset($participant['capabilities']['asset_types'])) {
                foreach ($participant['capabilities']['asset_types'] as $assetType) {
                    $assetTypesList[] = [
                        'type' => $assetType,
                        'name' => ucfirst(strtolower(str_replace('_', ' ', $assetType))),
                        'icon' => getAssetIcon($assetType),
                        'delivery_modes' => ['deposit', 'cashout'],
                        'amount_config' => null,
                        'fields' => []
                    ];
                }
            }
            
            // Get participant country from participant data, default to user's country
            $participantCountryCode = $participant['country'] ?? $userCountry;
            $participantCountry = $countriesByCode[$participantCountryCode] ?? ['name' => $participantCountryCode, 'code' => $participantCountryCode];
            
            $participantData = [
                'code' => $code,
                'name' => $participant['name'] ?? $participant['provider_code'] ?? $code,
                'country_code' => $participantCountryCode,
                'country_name' => $participantCountry['name'] ?? $participantCountryCode,
                'currency' => $participant['settlement']['currency'] ?? 'BWP',
                'asset_types' => $assetTypesList,
                'asset_type_definitions' => $assetTypeDefinitions,
                'status' => $participant['status'] ?? 'ACTIVE',
                'routing_priority' => $participant['routing']['priority'] ?? 100,
                'category' => $participant['category'] ?? 'BANK'
            ];
            
            $allParticipants[$code] = $participantData;
            $participantAssetDefs[$code] = $assetTypeDefinitions;
            
            // Group by country code for destination filtering
            if (!isset($participantsByCountry[$participantCountryCode])) {
                $participantsByCountry[$participantCountryCode] = [];
            }
            $participantsByCountry[$participantCountryCode][] = $participantData;
        }
    }
}

function getAssetIcon($type) {
    $icons = [
        'ACCOUNT' => '🏦', 'MNO-WALLET' => '📱', 'BANK-WALLET' => '👛',
        'CASHOUT-VOUCHER' => '🎫', 'ATM' => '🏧', 'CARD' => '💳'
    ];
    return $icons[$type] ?? '📄';
}

function getDestinationFieldForAsset($assetType, $category, $deliveryMode = 'deposit') {
    // Determine what field to show for destination based on asset type and category
    if ($category === 'BANK') {
        if ($deliveryMode === 'deposit') {
            return [
                'name' => 'account_number',
                'label' => 'Account Number',
                'type' => 'text',
                'placeholder' => 'Enter account number',
                'pattern' => '^[0-9]{8,16}$',
                'hint' => 'Enter the recipient\'s bank account number (8-16 digits)',
                'required' => true
            ];
        } else {
            return [
                'name' => 'account_number',
                'label' => 'Account Number',
                'type' => 'text',
                'placeholder' => 'Enter account number',
                'pattern' => '^[0-9]{8,16}$',
                'hint' => 'Enter the account number to debit',
                'required' => true
            ];
        }
    } elseif ($category === 'MNO') {
        return [
            'name' => 'phone_number',
            'label' => 'Phone Number',
            'type' => 'tel',
            'placeholder' => '+267XXXXXXXXX',
            'pattern' => '^\\+?[0-9]{10,15}$',
            'hint' => 'Enter the mobile wallet phone number (e.g., +26771XXXXXX)',
            'required' => true
        ];
    } elseif ($assetType === 'CASHOUT-VOUCHER') {
        return [
            'name' => 'voucher_number',
            'label' => 'Voucher Number',
            'type' => 'text',
            'placeholder' => 'Enter voucher number',
            'pattern' => '^[A-Z0-9]{8,16}$',
            'hint' => 'Enter the voucher code',
            'required' => true
        ];
    }
    
    return [
        'name' => 'identifier',
        'label' => 'Identifier',
        'type' => 'text',
        'placeholder' => 'Enter identifier',
        'pattern' => null,
        'hint' => 'Enter the recipient identifier',
        'required' => true
    ];
}

// ============================================================
// LOAD LINKED SOURCES
// ============================================================
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
// AJAX HANDLERS
// ============================================================
if ($isAjax) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    // Get participants for UI
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
            $assetDef = $participantAssetDefs[$fs['institution_code']][$fs['asset_type']] ?? null;
            $sources[] = [
                'id' => $fs['id'],
                'name' => $fs['institution_name'] ?? $fs['institution_code'],
                'type' => $fs['source_type'],
                'asset_type' => $fs['asset_type'],
                'identifier' => $fs['identifier'],
                'vault_slot' => $fs['vault_slot'],
                'needs_auth' => !empty($assetDef['fields']) && count(array_filter($assetDef['fields'], function($f) { 
                    return $f['type'] === 'password'; 
                })) > 0
            ];
        }
        echo json_encode(['success' => true, 'sources' => $sources]);
        exit;
    }
    
    // Get asset definition for a participant/asset type
    if ($action === 'get_asset_definition') {
        $participantCode = $_POST['participant_code'] ?? '';
        $assetType = $_POST['asset_type'] ?? '';
        
        $def = $participantAssetDefs[$participantCode][$assetType] ?? null;
        echo json_encode(['success' => true, 'definition' => $def, 'user_phone' => $userPhone]);
        exit;
    }
    
    // Get destination field for a participant
    if ($action === 'get_destination_field') {
        $participantCode = $_POST['participant_code'] ?? '';
        $deliveryMode = $_POST['delivery_mode'] ?? 'deposit';
        
        $participant = $allParticipants[$participantCode] ?? null;
        if (!$participant) {
            echo json_encode(['success' => false, 'message' => 'Participant not found']);
            exit;
        }
        
        // For destination, determine the appropriate field based on category
        $primaryAssetType = null;
        if (!empty($participant['asset_types'])) {
            $primaryAssetType = $participant['asset_types'][0]['type'];
        }
        
        $field = getDestinationFieldForAsset($primaryAssetType, $participant['category'], $deliveryMode);
        
        echo json_encode([
            'success' => true, 
            'field' => $field,
            'participant_name' => $participant['name'],
            'category' => $participant['category'],
            'currency' => $participant['currency']
        ]);
        exit;
    }
    
    // Set transaction PIN
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
    
    // Verify PIN
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
    
    // Execute linked swap
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
            $authFields = json_decode($_POST['auth_fields'] ?? '{}', true);
            
            $db = DBConnection::getInstance();
            $stmt = $db->prepare("SELECT institution_code, source_type, asset_type, identifier FROM user_funding_sources WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $sourceId, 'user_id' => $userId]);
            $source = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$source) throw new Exception('Source not found');
            
            $sourcePayload = [
                'institution' => $source['institution_code'],
                'asset_type' => $source['asset_type'],
                'identifier' => $source['identifier'],
                'amount' => $amount
            ];
            
            foreach ($authFields as $key => $value) {
                $sourcePayload[$key] = $value;
            }
            
            $result = callVouchMorphApi('v1/swap/execute', [
                'source' => $sourcePayload,
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
    
    // Execute ad-hoc swap
    if ($action === 'swap_adhoc') {
        try {
            $amount = (float)($_POST['amount'] ?? 0);
            $sourceInstitution = $_POST['source_institution'] ?? '';
            $assetType = $_POST['asset_type'] ?? '';
            $destInstitution = $_POST['dest_institution'] ?? '';
            $destIdentifier = $_POST['dest_identifier'] ?? '';
            $destAction = $_POST['dest_action'] ?? 'deposit';
            $dynamicFields = json_decode($_POST['dynamic_fields'] ?? '{}', true);
            
            $sourcePayload = [
                'institution' => $sourceInstitution,
                'asset_type' => $assetType,
                'amount' => $amount
            ];
            
            foreach ($dynamicFields as $key => $value) {
                $sourcePayload[$key] = $value;
            }
            
            $result = callVouchMorphApi('v1/swap/execute', [
                'source' => $sourcePayload,
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
$participantsByCountryJson = json_encode($participantsByCountry);
$countriesJson = json_encode($countries);
$sourcesJson = json_encode(array_map(function($s) { 
    return [
        'id' => $s['id'], 
        'name' => $s['institution_name'] ?? $s['institution_code'], 
        'type' => $s['source_type'],
        'asset_type' => $s['asset_type'],
        'identifier' => $s['identifier']
    ]; 
}, $fundingSources));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VOUCHMORPH | ORCHESTRATION DASHBOARD</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #000000; font-family: 'Space Grotesk', monospace; color: #FFFFFF; }
    
    .app { display: flex; min-height: 100vh; }
    .sidebar { width: 280px; background: #0a0a0a; border-right: 1px solid #1a1a1a; padding: 32px 24px; position: relative; }
    .main { flex: 1; padding: 32px 48px; max-width: 700px; }
    .right-panel { width: 360px; background: #0a0a0a; border-left: 1px solid #1a1a1a; padding: 32px 24px; }
    
    .logo { font-size: 14px; letter-spacing: 4px; margin-bottom: 48px; color: rgba(255,255,255,0.5); }
    .logo strong { color: #FFFFFF; }
    
    .nav-item { display: block; width: 100%; background: transparent; border: none; padding: 14px 0; font-family: inherit; font-size: 13px; letter-spacing: 1px; color: rgba(255,255,255,0.5); cursor: pointer; text-align: left; border-bottom: 1px solid #1a1a1a; }
    .nav-item:hover { color: #FFFFFF; border-bottom-color: #FFFFFF; }
    
    .user-section { position: absolute; bottom: 32px; left: 24px; right: 24px; padding-top: 32px; border-top: 1px solid #1a1a1a; }
    .user-phone { font-size: 12px; color: rgba(255,255,255,0.3); margin-bottom: 8px; }
    .user-badge { font-size: 10px; color: #4CAF50; }
    
    .balance-label { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3); margin-bottom: 8px; text-transform: uppercase; }
    .balance-amount { font-size: 48px; font-weight: 500; letter-spacing: -2px; margin-bottom: 32px; }
    
    .primary-btn { width: 100%; background: #FFFFFF; border: none; padding: 16px 24px; font-family: inherit; font-size: 13px; font-weight: 500; letter-spacing: 2px; color: #000000; cursor: pointer; margin-top: 24px; transition: opacity 0.2s; }
    .primary-btn:hover { opacity: 0.9; }
    .primary-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .secondary-btn { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.2); padding: 12px 20px; font-family: inherit; font-size: 12px; letter-spacing: 1px; color: #FFFFFF; cursor: pointer; margin-bottom: 12px; transition: all 0.2s; }
    .secondary-btn:hover { border-color: #FFFFFF; }
    
    .form-group { margin-bottom: 20px; }
    .form-label { font-size: 10px; letter-spacing: 1px; color: rgba(255,255,255,0.4); margin-bottom: 8px; display: block; text-transform: uppercase; }
    .form-label .required { color: #ff4444; margin-left: 4px; }
    .form-label .optional { color: rgba(255,255,255,0.2); font-size: 9px; margin-left: 4px; }
    .form-input, .form-select { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 12px 16px; font-family: inherit; font-size: 14px; color: #FFFFFF; border-radius: 0; transition: border-color 0.2s; }
    .form-input:focus, .form-select:focus { outline: none; border-color: #FFFFFF; }
    .form-input:read-only { opacity: 0.6; cursor: not-allowed; background: rgba(255,255,255,0.05); }
    .form-select option { background: #000000; }
    
    .radio-group { display: flex; gap: 24px; margin-top: 8px; }
    .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; }
    .radio-label input { accent-color: #FFFFFF; width: 16px; height: 16px; }
    .radio-label.disabled { opacity: 0.3; cursor: not-allowed; }
    
    .pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 24px 0; }
    .pin-btn { background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 16px; font-size: 20px; font-family: inherit; color: #FFFFFF; cursor: pointer; transition: all 0.1s; }
    .pin-btn:active { background: #FFFFFF; color: #000000; }
    .pin-dots { display: flex; justify-content: center; gap: 16px; margin: 24px 0; }
    .pin-dot { width: 12px; height: 12px; border: 1px solid rgba(255,255,255,0.3); border-radius: 50%; }
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
    
    .source-item { padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.06); cursor: pointer; transition: all 0.2s; }
    .source-item:hover { background: rgba(255,255,255,0.03); padding-left: 8px; }
    .source-item .source-name { font-size: 14px; }
    .source-item .source-detail { font-size: 10px; color: rgba(255,255,255,0.3); margin-top: 4px; }
    
    .badge { font-size: 9px; padding: 4px 8px; margin-left: 8px; border-radius: 2px; }
    .badge-deposit { background: rgba(76,175,80,0.2); border: 1px solid #4CAF50; color: #4CAF50; }
    .badge-cashout { background: rgba(255,152,0,0.2); border: 1px solid #FF9800; color: #FF9800; }
    .panel-title { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.3); margin-bottom: 20px; text-transform: uppercase; }
    
    .dynamic-field-group { animation: fadeIn 0.2s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
    
    .field-hint { font-size: 9px; color: rgba(255,255,255,0.25); margin-top: 4px; display: block; }
    .field-hint.error { color: #ff4444; }
    
    .amount-hint { font-size: 10px; color: rgba(255,255,255,0.3); margin-top: 4px; }
    
    .loading-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #FFFFFF; border-radius: 50%; animation: spin 0.6s linear infinite; margin-right: 8px; }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    .participant-category { font-size: 9px; color: rgba(255,255,255,0.3); margin-left: 8px; }
    .country-flag { font-size: 14px; margin-right: 8px; }
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
            <div class="user-phone"><?= vm_h($userPhone) ?></div>
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
                    <select id="linkedSourceSelect" class="form-select" onchange="onLinkedSourceChange()">
                        <option value="">-- Select source --</option>
                    </select>
                </div>
                <div id="linkedAuthFields"></div>
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
                <div id="dynamicAssetFields"></div>
            </div>
            
            <div class="form-group">
                <label class="form-label">AMOUNT (<span id="amountCurrency">BWP</span>)</label>
                <input type="number" id="amount" class="form-input" placeholder="Enter amount" step="0.01">
                <div id="amountHint" class="amount-hint"></div>
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION COUNTRY</label>
                <select id="destCountry" class="form-select" onchange="onDestinationCountryChange()">
                    <option value="">-- Select Country --</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION INSTITUTION</label>
                <select id="destInstitution" class="form-select" onchange="onDestinationInstitutionChange()">
                    <option value="">-- Select Institution --</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">DESTINATION ACTION</label>
                <div class="radio-group" id="destActionGroup">
                    <label class="radio-label"><input type="radio" name="destAction" value="deposit" checked> DEPOSIT</label>
                    <label class="radio-label"><input type="radio" name="destAction" value="cashout"> CASHOUT</label>
                </div>
            </div>
            
            <div id="destinationFieldsContainer">
                <div class="form-group">
                    <label class="form-label" id="destIdentifierLabel">DESTINATION IDENTIFIER</label>
                    <input type="text" id="destIdentifier" class="form-input" placeholder="Enter identifier">
                    <div id="destIdentifierHint" class="field-hint"></div>
                </div>
            </div>
            
            <button class="primary-btn" onclick="initiateSwap()" id="executeBtn">EXECUTE SWAP →</button>
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
        <div class="panel-title">📍 PARTICIPANTS BY COUNTRY</div>
        <div id="institutionList" style="font-size: 12px; line-height: 1.8;"></div>
        <div style="margin-top: 24px;">
            <div class="panel-title">🌍 COUNTRIES REGISTERED</div>
            <div id="countryList" style="font-size: 11px; line-height: 1.6;"></div>
        </div>
        <div style="margin-top: 24px;">
            <div class="panel-title">🔧 DEBUG</div>
            <div id="debugInfo" style="font-size: 10px; font-family: monospace; color: rgba(255,255,255,0.3); word-break: break-all;"></div>
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
// Global data
let participants = [], participantsByCountry = [], countries = [], linkedSources = [];
let hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;
let currentUserPhone = '<?php echo addslashes($userPhone); ?>';
let pendingSwapData = null;
let currentPinInput = '', verifyPinInput = '';
let currentAssetDefinition = null;
let currentDestinationField = null;

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

function getAssetDefinition(participantCode, assetType) {
    const participant = participants.find(p => p.code === participantCode);
    if (!participant || !participant.asset_type_definitions) return null;
    return participant.asset_type_definitions[assetType];
}

// Destination Country Selection (from country registry)
function onDestinationCountryChange() {
    const countryCode = document.getElementById('destCountry').value;
    const destSelect = document.getElementById('destInstitution');
    
    if (!countryCode || !participantsByCountry[countryCode]) {
        destSelect.innerHTML = '<option value="">-- Select Institution --</option>';
        resetDestinationField();
        return;
    }
    
    const countryParticipants = participantsByCountry[countryCode];
    destSelect.innerHTML = '<option value="">-- Select Institution --</option>' + 
        countryParticipants.map(p => {
            const categoryIcon = p.category === 'BANK' ? '🏦' : (p.category === 'MNO' ? '📱' : '🏢');
            return `<option value="${p.code}">${categoryIcon} ${p.name} <span class="participant-category">(${p.category})</span></option>`;
        }).join('');
    
    resetDestinationField();
    debugLog(`Selected country: ${countryCode}, found ${countryParticipants.length} participants`);
}

// Destination Institution Selection
async function onDestinationInstitutionChange() {
    const institutionCode = document.getElementById('destInstitution').value;
    const deliveryMode = document.querySelector('input[name="destAction"]:checked')?.value || 'deposit';
    
    if (!institutionCode) {
        resetDestinationField();
        return;
    }
    
    debugLog(`Loading destination field for: ${institutionCode} (${deliveryMode})`);
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get_destination_field&participant_code=${encodeURIComponent(institutionCode)}&delivery_mode=${encodeURIComponent(deliveryMode)}`
        });
        const data = await res.json();
        
        if (data.success && data.field) {
            currentDestinationField = data.field;
            updateDestinationField(data.field, data.participant_name, data.category);
        } else {
            resetDestinationField();
        }
    } catch(e) {
        debugLog('Error loading destination field: ' + e.message);
        resetDestinationField();
    }
}

function updateDestinationField(field, participantName, category) {
    const labelEl = document.getElementById('destIdentifierLabel');
    const inputEl = document.getElementById('destIdentifier');
    const hintEl = document.getElementById('destIdentifierHint');
    
    if (labelEl) {
        labelEl.innerHTML = field.label.toUpperCase() + (field.required ? '<span class="required">*</span>' : '');
    }
    
    if (inputEl) {
        inputEl.type = field.type || 'text';
        inputEl.placeholder = field.placeholder || `Enter ${field.label.toLowerCase()}`;
        if (field.pattern) {
            inputEl.setAttribute('pattern', field.pattern);
        } else {
            inputEl.removeAttribute('pattern');
        }
        inputEl.value = '';
    }
    
    if (hintEl) {
        let hintText = field.hint || '';
        if (category === 'BANK') {
            hintText = hintText || 'Enter the bank account number to receive funds';
        } else if (category === 'MNO') {
            hintText = hintText || 'Enter the mobile wallet phone number (e.g., +26771XXXXXX)';
        }
        hintEl.innerHTML = hintText;
    }
    
    debugLog(`Destination field updated: ${field.label} (${category})`);
}

function resetDestinationField() {
    const labelEl = document.getElementById('destIdentifierLabel');
    const inputEl = document.getElementById('destIdentifier');
    const hintEl = document.getElementById('destIdentifierHint');
    
    if (labelEl) labelEl.innerHTML = 'DESTINATION IDENTIFIER';
    if (inputEl) {
        inputEl.type = 'text';
        inputEl.placeholder = 'Enter identifier';
        inputEl.removeAttribute('pattern');
        inputEl.value = '';
    }
    if (hintEl) hintEl.innerHTML = '';
    currentDestinationField = null;
}

// Source-side functions
async function renderAssetFields() {
    const institutionCode = document.getElementById('adhocInstitution').value;
    const assetType = document.getElementById('adhocAssetType').value;
    
    if (!institutionCode || !assetType) {
        document.getElementById('dynamicAssetFields').innerHTML = '';
        currentAssetDefinition = null;
        updateAmountConstraints(null);
        return;
    }
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get_asset_definition&participant_code=${encodeURIComponent(institutionCode)}&asset_type=${encodeURIComponent(assetType)}`
        });
        const data = await res.json();
        
        if (data.success && data.definition) {
            currentAssetDefinition = data.definition;
            updateAmountConstraints(data.definition.amount);
            updateDeliveryModes(data.definition);
            renderDynamicFields(data.definition, data.user_phone);
        } else {
            currentAssetDefinition = null;
            document.getElementById('dynamicAssetFields').innerHTML = '<div class="field-hint error">Asset definition not found</div>';
        }
    } catch(e) {
        debugLog('Error loading asset definition: ' + e.message);
    }
}

function updateAmountConstraints(amountConfig) {
    const amountField = document.getElementById('amount');
    const amountHint = document.getElementById('amountHint');
    const amountCurrency = document.getElementById('amountCurrency');
    
    if (amountConfig) {
        if (amountConfig.min) amountField.min = amountConfig.min;
        if (amountConfig.max) amountField.max = amountConfig.max;
        if (amountConfig.step) amountField.step = amountConfig.step;
        if (amountConfig.currency) amountCurrency.innerText = amountConfig.currency;
        
        let hintText = '';
        if (amountConfig.min && amountConfig.max) {
            hintText = `Min: ${amountConfig.min} ${amountConfig.currency} | Max: ${amountConfig.max} ${amountConfig.currency}`;
        } else if (amountConfig.min) {
            hintText = `Minimum amount: ${amountConfig.min} ${amountConfig.currency}`;
        } else if (amountConfig.max) {
            hintText = `Maximum amount: ${amountConfig.max} ${amountConfig.currency}`;
        }
        amountHint.innerText = hintText;
    } else {
        amountField.min = 10;
        amountField.max = null;
        amountField.step = 0.01;
        amountHint.innerText = '';
    }
}

function updateDeliveryModes(assetDef) {
    const depositRadio = document.querySelector('input[name="destAction"][value="deposit"]');
    const cashoutRadio = document.querySelector('input[name="destAction"][value="cashout"]');
    const depositLabel = depositRadio?.parentElement;
    const cashoutLabel = cashoutRadio?.parentElement;
    
    if (assetDef && assetDef.delivery_modes) {
        if (depositLabel) depositLabel.style.display = assetDef.delivery_modes.includes('deposit') ? 'flex' : 'none';
        if (cashoutLabel) cashoutLabel.style.display = assetDef.delivery_modes.includes('cashout') ? 'flex' : 'none';
        
        if (assetDef.delivery_modes.includes('deposit') && depositRadio) depositRadio.checked = true;
        else if (assetDef.delivery_modes.includes('cashout') && cashoutRadio) cashoutRadio.checked = true;
    } else {
        if (depositLabel) depositLabel.style.display = 'flex';
        if (cashoutLabel) cashoutLabel.style.display = 'flex';
    }
}

function renderDynamicFields(assetDef, userPhone) {
    let html = '';
    
    if (assetDef.fields && assetDef.fields.length > 0) {
        assetDef.fields.forEach(field => {
            let fieldValue = '';
            let isReadonly = field.readonly || false;
            
            if (field.source && field.source.type === 'session') {
                if (field.source.field === 'phone') {
                    fieldValue = userPhone;
                    isReadonly = true;
                }
            }
            
            const patternAttr = field.pattern ? `pattern="${field.pattern}"` : '';
            const minAttr = field.min !== undefined ? `min="${field.min}"` : '';
            const maxAttr = field.max !== undefined ? `max="${field.max}"` : '';
            const minLengthAttr = field.min_length ? `minlength="${field.min_length}"` : '';
            const maxLengthAttr = field.max_length ? `maxlength="${field.max_length}"` : '';
            
            html += `
                <div class="form-group dynamic-field-group">
                    <label class="form-label">
                        ${field.label}
                        ${field.required ? '<span class="required">*</span>' : '<span class="optional">(optional)</span>'}
                    </label>
                    <input
                        class="form-input dynamic-field"
                        type="${field.type || 'text'}"
                        id="field_${field.name}"
                        name="${field.name}"
                        data-field-name="${field.name}"
                        data-required="${field.required ? 'true' : 'false'}"
                        data-pattern="${field.pattern || ''}"
                        ${patternAttr}
                        ${minAttr}
                        ${maxAttr}
                        ${minLengthAttr}
                        ${maxLengthAttr}
                        value="${fieldValue.replace(/"/g, '&quot;')}"
                        ${isReadonly ? 'readonly' : ''}
                        ${field.required ? 'required' : ''}
                        placeholder="${field.placeholder || ''}"
                    >
                    ${field.hint ? `<div class="field-hint">${field.hint}</div>` : ''}
                </div>
            `;
        });
    }
    
    document.getElementById('dynamicAssetFields').innerHTML = html;
}

async function renderLinkedAuthFields() {
    const sourceId = document.getElementById('linkedSourceSelect').value;
    const source = linkedSources.find(s => s.id == sourceId);
    
    if (!source || !source.asset_type) {
        document.getElementById('linkedAuthFields').innerHTML = '';
        return;
    }
    
    const sourceParticipant = participants.find(p => p.code === source.name.toUpperCase() || p.name === source.name);
    if (!sourceParticipant) {
        document.getElementById('linkedAuthFields').innerHTML = '';
        return;
    }
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get_asset_definition&participant_code=${encodeURIComponent(sourceParticipant.code)}&asset_type=${encodeURIComponent(source.asset_type)}`
        });
        const data = await res.json();
        
        if (data.success && data.definition && data.definition.fields) {
            const authFields = data.definition.fields.filter(f => f.type === 'password');
            
            if (authFields.length === 0) {
                document.getElementById('linkedAuthFields').innerHTML = '';
                return;
            }
            
            let html = `<div class="form-group"><div class="field-hint">Authentication required for ${source.name}</div></div>`;
            
            authFields.forEach(field => {
                html += `
                    <div class="form-group dynamic-field-group">
                        <label class="form-label">
                            ${field.label}
                            ${field.required ? '<span class="required">*</span>' : '<span class="optional">(optional)</span>'}
                        </label>
                        <input
                            class="form-input auth-field"
                            type="${field.type || 'password'}"
                            id="auth_${field.name}"
                            data-auth-name="${field.name}"
                            data-required="${field.required ? 'true' : 'false'}"
                            ${field.required ? 'required' : ''}
                            placeholder="${field.placeholder || ''}"
                        >
                    </div>
                `;
            });
            
            document.getElementById('linkedAuthFields').innerHTML = html;
        } else {
            document.getElementById('linkedAuthFields').innerHTML = '';
        }
    } catch(e) {
        debugLog('Error loading linked auth fields: ' + e.message);
    }
}

function collectDynamicFields() {
    const fields = {};
    document.querySelectorAll('.dynamic-field').forEach(field => {
        const fieldName = field.dataset.fieldName;
        if (fieldName && field.value) {
            fields[fieldName] = field.value;
        }
    });
    return fields;
}

function collectLinkedAuthFields() {
    const authFields = {};
    document.querySelectorAll('.auth-field').forEach(field => {
        const authName = field.dataset.authName;
        if (authName && field.value) {
            authFields[authName] = field.value;
        }
    });
    return authFields;
}

function validateDynamicFields() {
    let isValid = true;
    document.querySelectorAll('.dynamic-field[data-required="true"]').forEach(field => {
        if (!field.value.trim()) {
            showError(`${field.parentElement.querySelector('.form-label')?.textContent || 'Field'} is required`);
            isValid = false;
        }
        
        const pattern = field.dataset.pattern;
        if (pattern && field.value.trim()) {
            try {
                const regex = new RegExp(pattern);
                if (!regex.test(field.value.trim())) {
                    showError(`Invalid format for ${field.parentElement.querySelector('.form-label')?.textContent || 'field'}`);
                    isValid = false;
                }
            } catch(e) {}
        }
    });
    return isValid;
}

// Event handlers
document.getElementById('destActionGroup')?.addEventListener('change', function() {
    if (document.getElementById('destInstitution').value) {
        onDestinationInstitutionChange();
    }
});

document.getElementById('adhocInstitution')?.addEventListener('change', function() {
    const inst = participants.find(p => p.code === this.value);
    const assetSelect = document.getElementById('adhocAssetType');
    if (inst && inst.asset_types) {
        assetSelect.innerHTML = '<option value="">-- Select --</option>' + 
            inst.asset_types.map(a => `<option value="${a.type}">${a.icon} ${a.name}</option>`).join('');
    } else {
        assetSelect.innerHTML = '<option value="">-- Select --</option>';
    }
    renderAssetFields();
});

document.getElementById('adhocAssetType')?.addEventListener('change', renderAssetFields);

function onLinkedSourceChange() {
    renderLinkedAuthFields();
}

function toggleSourceFields() {
    const type = document.getElementById('sourceType').value;
    document.getElementById('linkedSourceSection').style.display = type === 'linked' ? 'block' : 'none';
    document.getElementById('adhocSourceSection').style.display = type === 'linked' ? 'none' : 'block';
    if (type === 'linked') {
        onLinkedSourceChange();
    }
}

async function loadData() {
    debugLog('Loading data...');
    const executeBtn = document.getElementById('executeBtn');
    if (executeBtn) executeBtn.disabled = true;
    
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: 'action=get_participants' 
        });
        const data = await res.json();
        if(data.success) { 
            participants = data.participants; 
            participantsByCountry = data.participants_by_country;
            countries = data.countries;
            renderInstitutionList(); 
            renderSelects(); 
            renderCountrySelect();
            renderCountryList();
            debugLog(`Loaded ${participants.length} participants across ${Object.keys(participantsByCountry).length} countries`);
            debugLog(`Loaded ${countries.length} countries from registry`);
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
        showError('Failed to load data: ' + e.message);
    } finally {
        if (executeBtn) executeBtn.disabled = false;
    }
}

function renderInstitutionList() {
    const container = document.getElementById('institutionList');
    if(container) {
        let html = '';
        for (const [countryCode, countryParticipants] of Object.entries(participantsByCountry)) {
            const country = countries.find(c => c.code === countryCode);
            const flag = country?.flag || '📍';
            html += `<div style="margin-top: 12px;"><strong>${flag} ${country?.name || countryCode}</strong></div>`;
            countryParticipants.forEach(p => {
                const categoryIcon = p.category === 'BANK' ? '🏦' : (p.category === 'MNO' ? '📱' : '🏢');
                html += `<div style="margin-left: 12px;">${categoryIcon} ${p.name} <span style="color: rgba(255,255,255,0.3);">(${p.currency})</span></div>`;
            });
        }
        container.innerHTML = html || '<div class="field-hint">No participants found</div>';
    }
}

function renderCountryList() {
    const container = document.getElementById('countryList');
    if (container && countries.length > 0) {
        container.innerHTML = countries.map(c => 
            `<div>${c.flag || '🏳️'} ${c.name} (${c.currency}) - ${c.phone_code}</div>`
        ).join('');
    } else if (container) {
        container.innerHTML = '<div class="field-hint">No countries in registry</div>';
    }
}

function renderCountrySelect() {
    const destCountry = document.getElementById('destCountry');
    if (destCountry && countries.length > 0) {
        destCountry.innerHTML = '<option value="">-- Select Country --</option>' + 
            countries.map(c => `<option value="${c.code}">${c.flag || '🏳️'} ${c.name} (${c.currency})</option>`).join('');
    } else if (destCountry) {
        // Fallback to participants-based countries
        const countryCodes = Object.keys(participantsByCountry);
        destCountry.innerHTML = '<option value="">-- Select Country --</option>' + 
            countryCodes.map(code => `<option value="${code}">${code}</option>`).join('');
    }
}

function renderSelects() {
    const adhocSelect = document.getElementById('adhocInstitution');
    if(adhocSelect) adhocSelect.innerHTML = '<option value="">-- Select --</option>' + participants.map(p => `<option value="${p.code}">${p.name}</option>`).join('');
}

function renderLinkedSources() {
    const container = document.getElementById('sourcesList');
    const select = document.getElementById('linkedSourceSelect');
    
    const sourcesHtml = linkedSources.map(s => 
        `<div class="source-item" onclick="selectLinkedSource(${s.id}, '${s.name.replace(/'/g, "\\'")}')">
            <div class="source-name">${s.name}</div>
            <div class="source-detail">${s.asset_type || s.type} • ${s.identifier}</div>
        </div>`
    ).join('');
    
    if(container) container.innerHTML = sourcesHtml || '<div class="field-hint">No linked sources found</div>';
    if(select) select.innerHTML = '<option value="">-- Select --</option>' + linkedSources.map(s => `<option value="${s.id}">${s.name} (${s.asset_type})</option>`).join('');
}

function selectLinkedSource(id, name) {
    document.getElementById('linkedSourceSelect').value = id;
    document.getElementById('sourceType').value = 'linked';
    toggleSourceFields();
    onLinkedSourceChange();
    showStep('swap');
    showSuccess(`Selected: ${name}`);
}

function showStep(step) {
    ['swap', 'sources', 'security'].forEach(s => {
        const el = document.getElementById(`step${s.charAt(0).toUpperCase() + s.slice(1)}`);
        if (el) el.classList.remove('active');
    });
    const target = document.getElementById(`step${step.charAt(0).toUpperCase() + step.slice(1)}`);
    if (target) target.classList.add('active');
}

// PIN Setup Functions
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

// PIN Verify Functions
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
    const destAction = document.querySelector('input[name="destAction"]:checked')?.value;
    const destIdentifier = document.getElementById('destIdentifier').value;
    
    let amountConfig = currentAssetDefinition?.amount;
    const minAmount = amountConfig?.min || 10;
    const maxAmount = amountConfig?.max;
    
    if(!amount || amount < minAmount) { 
        showError(`Amount must be at least ${minAmount} ${amountConfig?.currency || 'BWP'}`); 
        return; 
    }
    if(maxAmount && amount > maxAmount) {
        showError(`Amount cannot exceed ${maxAmount} ${amountConfig?.currency || 'BWP'}`);
        return;
    }
    if(!destCountry) { showError('Select destination country'); return; }
    if(!destInstitution) { showError('Select destination institution'); return; }
    if(!destIdentifier) { showError('Enter destination identifier'); return; }
    
    if(sourceType === 'linked') {
        const sourceId = document.getElementById('linkedSourceSelect').value;
        if(!sourceId) { showError('Select a linked source'); return; }
        
        if(!validateDynamicFields()) return;
        const authFields = collectLinkedAuthFields();
        
        if(!hasTransactionPin) { showError('Set transaction PIN first'); showPinSetupModal(); return; }
        showPinVerifyModal({ 
            type:'linked', 
            source_id: sourceId, 
            amount, 
            dest_institution: destInstitution, 
            dest_identifier: destIdentifier, 
            dest_action: destAction,
            auth_fields: authFields
        });
    } else {
        const sourceInstitution = document.getElementById('adhocInstitution').value;
        const assetType = document.getElementById('adhocAssetType').value;
        
        if(!sourceInstitution) { showError('Select source institution'); return; }
        if(!assetType) { showError('Select asset type'); return; }
        
        if(!validateDynamicFields()) return;
        const dynamicFields = collectDynamicFields();
        
        await executeAdhocSwap(amount, sourceInstitution, assetType, dynamicFields, destInstitution, destIdentifier, destAction);
    }
}

async function executeSwap() {
    const data = pendingSwapData;
    debugLog(`Executing linked swap via API...`);
    const executeBtn = document.getElementById('executeBtn');
    if (executeBtn) {
        executeBtn.disabled = true;
        executeBtn.innerHTML = '<span class="loading-spinner"></span> PROCESSING...';
    }
    
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: `action=swap_linked&source_id=${data.source_id}&amount=${data.amount}&dest_institution=${data.dest_institution}&dest_identifier=${data.dest_identifier}&dest_action=${data.dest_action}&auth_fields=${encodeURIComponent(JSON.stringify(data.auth_fields || {}))}` 
        });
        const result = await res.json();
        debugLog('API Response: ' + JSON.stringify(result));
        if(result.status === 'success') { 
            showSuccess(`Swap complete!\nRef: ${result.swap_reference}\nAmount: ${data.amount} BWP`);
            document.getElementById('amount').value = '';
            document.getElementById('destIdentifier').value = '';
            document.getElementById('linkedAuthFields').innerHTML = '';
        } else { 
            showError(result.message); 
        }
    } catch(e) { 
        showError(e.message);
    } finally {
        if (executeBtn) {
            executeBtn.disabled = false;
            executeBtn.innerHTML = 'EXECUTE SWAP →';
        }
    }
}

async function executeAdhocSwap(amount, srcInst, assetType, dynamicFields, destInst, destId, action) {
    debugLog(`Executing ad-hoc swap via API...`);
    const executeBtn = document.getElementById('executeBtn');
    if (executeBtn) {
        executeBtn.disabled = true;
        executeBtn.innerHTML = '<span class="loading-spinner"></span> PROCESSING...';
    }
    
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: `action=swap_adhoc&amount=${amount}&source_institution=${encodeURIComponent(srcInst)}&asset_type=${encodeURIComponent(assetType)}&dest_institution=${encodeURIComponent(destInst)}&dest_identifier=${encodeURIComponent(destId)}&dest_action=${action}&dynamic_fields=${encodeURIComponent(JSON.stringify(dynamicFields))}` 
        });
        const result = await res.json();
        debugLog('API Response: ' + JSON.stringify(result));
        if(result.status === 'success') { 
            showSuccess(`Swap complete!\nRef: ${result.swap_reference}\nAmount: ${amount} BWP`);
            document.getElementById('amount').value = '';
            document.getElementById('destIdentifier').value = '';
            document.getElementById('dynamicAssetFields').innerHTML = '';
            document.getElementById('adhocInstitution').value = '';
            document.getElementById('adhocAssetType').innerHTML = '<option value="">-- Select --</option>';
        } else { 
            showError(result.message); 
        }
    } catch(e) { 
        showError(e.message);
    } finally {
        if (executeBtn) {
            executeBtn.disabled = false;
            executeBtn.innerHTML = 'EXECUTE SWAP →';
        }
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
