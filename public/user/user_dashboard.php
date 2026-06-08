<?php
// public/user/user_dashboard.php - VouchMorph Swap Dashboard
// Clean horizontal layout: Source on top, Destination below

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
$pinLockedUntil = null;

try {
    $db = DBConnection::getInstance();
    $stmt = $db->prepare("SELECT transaction_pin_hash, pin_locked_until FROM users WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasTransactionPin = !empty($userData['transaction_pin_hash']);
    $pinLockedUntil = $userData['pin_locked_until'] ?? null;
} catch (Throwable $e) {
    error_log("PIN status error: " . $e->getMessage());
}

// ============================================================
// LOAD CONFIGURATION
// ============================================================
$countries = [];
$allParticipants = [];
$participantsByCountry = [];
$assetTypesList = ['ACCOUNT', 'MNO-WALLET', 'BANK-WALLET', 'CARD', 'ATM', 'CASHOUT-VOUCHER'];

// Load country registry
$countryRegistryPath = __DIR__ . '/../../src/Core/Config/countries_registry.json';
if (file_exists($countryRegistryPath)) {
    $registryContent = file_get_contents($countryRegistryPath);
    $registryData = json_decode($registryContent, true);
    if ($registryData && isset($registryData['countries'])) {
        $countries = $registryData['countries'];
    }
}

// Load participants from YAML
$participantsPath = __DIR__ . '/../../src/Core/Config/Countries/' . $userCountry . '/participants.yaml';
if (file_exists($participantsPath)) {
    $yamlContent = file_get_contents($participantsPath);
    $data = parseParticipantsYaml($yamlContent);
    if ($data && isset($data['participants'])) {
        foreach ($data['participants'] as $code => $participant) {
            if ($code === 'VOUCHMORPH') continue;
            
            $participantCode = strtoupper($code);
            $participantCountry = $participant['country'] ?? $userCountry;
            
            $allParticipants[$participantCode] = [
                'code' => $participantCode,
                'name' => $participant['name'] ?? $participantCode,
                'type' => $participant['type'] ?? 'BANK',
                'category' => getCategoryFromType($participant['type'] ?? 'BANK'),
                'country' => $participantCountry,
                'currency' => $participant['limits']['currency'] ?? 'BWP',
                'assets' => $participant['assets'] ?? ['ACCOUNT'],
                'min_amount' => $participant['limits']['min_amount'] ?? 10,
                'max_amount' => $participant['limits']['max_amount'] ?? 500000
            ];
            
            if (!isset($participantsByCountry[$participantCountry])) {
                $participantsByCountry[$participantCountry] = [];
            }
            $participantsByCountry[$participantCountry][] = $allParticipants[$participantCode];
        }
    }
}

// Load linked sources
$fundingSources = [];
try {
    $db = DBConnection::getInstance();
    $stmt = $db->prepare("
        SELECT id, institution_code, institution_name, asset_type, identifier 
        FROM user_funding_sources 
        WHERE user_id = :user_id AND status = 'ACTIVE'
        ORDER BY created_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Error loading sources: " . $e->getMessage());
}

function getCategoryFromType($type) {
    $map = [
        'BANK' => '🏦 Bank',
        'MNO' => '📱 Mobile Network',
        'ORCHESTRATOR' => '⚡ Orchestrator',
        'FINANCIAL_INSTITUTION' => '🏦 Bank'
    ];
    return $map[$type] ?? '🏢 Institution';
}

function parseParticipantsYaml($content) {
    $result = ['participants' => []];
    $lines = explode("\n", $content);
    $current = null;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
            $current = $matches[1];
            $result['participants'][$current] = [];
            continue;
        }
        
        if ($current && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
            if ($value === 'true') $value = true;
            if ($value === 'false') $value = false;
            if (is_numeric($value)) $value = (float)$value;
            $result['participants'][$current][$key] = $value;
        }
        
        if ($current && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches)) {
            $result['participants'][$current]['limits'][$matches[1]] = $matches[2];
        }
    }
    return $result;
}

// ============================================================
// AJAX HANDLERS
// ============================================================
if ($isAjax) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    // Get participants for dropdowns
    if ($action === 'get_participants') {
        echo json_encode([
            'success' => true,
            'participants' => array_values($allParticipants),
            'participants_by_country' => $participantsByCountry,
            'countries' => $countries,
            'user_country' => $userCountry,
            'asset_types' => $assetTypesList
        ]);
        exit;
    }
    
    // Get source details (limits, assets)
    if ($action === 'get_source_details') {
        $participantCode = $_POST['participant_code'] ?? '';
        $participant = $allParticipants[$participantCode] ?? null;
        
        if ($participant) {
            echo json_encode([
                'success' => true,
                'min_amount' => $participant['min_amount'] ?? 10,
                'max_amount' => $participant['max_amount'] ?? 500000,
                'currency' => $participant['currency'] ?? 'BWP',
                'assets' => $participant['assets'] ?? ['ACCOUNT']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Participant not found']);
        }
        exit;
    }
    
    // Get destination field based on participant type and asset
    if ($action === 'get_destination_fields') {
        $participantCode = $_POST['participant_code'] ?? '';
        $deliveryMode = $_POST['delivery_mode'] ?? 'deposit';
        $participant = $allParticipants[$participantCode] ?? null;
        
        if (!$participant) {
            echo json_encode(['success' => false]);
            exit;
        }
        
        $fields = [];
        $type = $participant['type'] ?? 'BANK';
        
        if ($type === 'BANK') {
            $fields = [
                ['name' => 'account_number', 'label' => 'Account Number', 'type' => 'text', 'placeholder' => 'Enter account number', 'required' => true, 'pattern' => '^[0-9]{8,16}$'],
                ['name' => 'account_name', 'label' => 'Account Name', 'type' => 'text', 'placeholder' => 'Account holder name', 'required' => false]
            ];
        } elseif ($type === 'MNO') {
            $fields = [
                ['name' => 'phone_number', 'label' => 'Phone Number', 'type' => 'tel', 'placeholder' => '+267 71 234 5678', 'required' => true, 'pattern' => '^\\+?267[0-9]{8}$']
            ];
        } else {
            $fields = [
                ['name' => 'identifier', 'label' => 'Identifier', 'type' => 'text', 'placeholder' => 'Enter identifier', 'required' => true]
            ];
        }
        
        echo json_encode([
            'success' => true,
            'fields' => $fields,
            'participant_name' => $participant['name'],
            'currency' => $participant['currency'] ?? 'BWP',
            'delivery_modes' => ['deposit', 'cashout']
        ]);
        exit;
    }
    
    // SET PIN
    if ($action === 'set_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            if (strlen($pin) !== 6 || !ctype_digit($pin)) {
                throw new Exception('PIN must be 6 digits');
            }
            
            $db = DBConnection::getInstance();
            $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
            $stmt = $db->prepare("
                UPDATE users SET transaction_pin_hash = :pin, has_transaction_pin = 1, pin_attempts = 0 
                WHERE user_id = :user_id
            ");
            $stmt->execute([':pin' => $hashedPin, ':user_id' => $userId]);
            
            echo json_encode(['status' => 'success', 'message' => 'PIN set']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // VERIFY PIN
    if ($action === 'verify_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            if (strlen($pin) !== 6 || !ctype_digit($pin)) {
                throw new Exception('PIN must be 6 digits');
            }
            
            $db = DBConnection::getInstance();
            $stmt = $db->prepare("SELECT transaction_pin_hash, pin_attempts, pin_locked_until FROM users WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userData || empty($userData['transaction_pin_hash'])) {
                throw new Exception('PIN not set');
            }
            
            if ($userData['pin_locked_until'] && strtotime($userData['pin_locked_until']) > time()) {
                $minutes = ceil((strtotime($userData['pin_locked_until']) - time()) / 60);
                throw new Exception("Locked for {$minutes} minutes");
            }
            
            if (!password_verify($pin, $userData['transaction_pin_hash'])) {
                $newAttempts = ($userData['pin_attempts'] ?? 0) + 1;
                if ($newAttempts >= 3) {
                    $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $stmt = $db->prepare("UPDATE users SET pin_attempts = 3, pin_locked_until = :locked WHERE user_id = :user_id");
                    $stmt->execute([':locked' => $lockUntil, ':user_id' => $userId]);
                    throw new Exception('Too many attempts. Locked for 15 minutes.');
                } else {
                    $stmt = $db->prepare("UPDATE users SET pin_attempts = :attempts WHERE user_id = :user_id");
                    $stmt->execute([':attempts' => $newAttempts, ':user_id' => $userId]);
                    throw new Exception("Invalid PIN. " . (3 - $newAttempts) . " attempts left.");
                }
            }
            
            $stmt = $db->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = NULL WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            
            $_SESSION['pin_verified'] = true;
            $_SESSION['pin_verified_at'] = time();
            
            echo json_encode(['status' => 'success', 'message' => 'PIN verified']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // EXECUTE SWAP
    if ($action === 'execute_swap') {
        try {
            if (!isset($_SESSION['pin_verified']) || $_SESSION['pin_verified_at'] < (time() - 300)) {
                throw new Exception('PIN verification required');
            }
            
            $sourceParticipant = $_POST['source_participant'] ?? '';
            $sourceAsset = $_POST['source_asset'] ?? 'ACCOUNT';
            $sourceIdentifier = $_POST['source_identifier'] ?? '';
            $amount = (float)($_POST['amount'] ?? 0);
            $destParticipant = $_POST['dest_participant'] ?? '';
            $destDeliveryMode = $_POST['dest_delivery_mode'] ?? 'deposit';
            $destFields = json_decode($_POST['dest_fields'] ?? '{}', true);
            
            if ($amount <= 0) throw new Exception('Invalid amount');
            if (empty($sourceParticipant)) throw new Exception('Select source');
            if (empty($destParticipant)) throw new Exception('Select destination');
            
            // Build destination identifier from fields
            $destIdentifier = '';
            if (isset($destFields['account_number'])) {
                $destIdentifier = $destFields['account_number'];
            } elseif (isset($destFields['phone_number'])) {
                $destIdentifier = $destFields['phone_number'];
            } elseif (isset($destFields['identifier'])) {
                $destIdentifier = $destFields['identifier'];
            }
            
            if (empty($destIdentifier)) throw new Exception('Enter destination identifier');
            
            // Call swap API
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
            unset($_SESSION['pin_verified_at']);
            
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                echo json_encode(['status' => 'success', 'data' => $result, 'message' => 'Swap completed successfully']);
            } else {
                echo json_encode(['status' => 'error', 'message' => "API error: HTTP {$httpCode}"]);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // LOGOUT
    if ($action === 'logout') {
        SessionManager::destroy();
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

// Prepare data for JavaScript
$countriesJson = json_encode($countries);
$participantsJson = json_encode(array_values($allParticipants));
$userCountryJson = json_encode($userCountry);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>VOUCHMORPH | SWAP</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body { 
        background: #0a0a0a; 
        font-family: 'Inter', sans-serif; 
        color: #FFFFFF; 
        min-height: 100vh; 
    }
    
    /* Container */
    .container { 
        max-width: 900px; 
        width: 95%; 
        margin: 0 auto; 
        padding: 24px 0 48px;
    }
    
    /* Header */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 0;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        margin-bottom: 40px;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .logo {
        font-size: 14px;
        letter-spacing: 4px;
        color: rgba(255,255,255,0.4);
    }
    
    .logo span { color: #FFFFFF; font-weight: 600; }
    
    .user-area {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    
    .user-info {
        text-align: right;
    }
    
    .user-name {
        font-size: 13px;
        font-weight: 500;
    }
    
    .user-phone {
        font-size: 11px;
        color: rgba(255,255,255,0.4);
        margin-top: 2px;
    }
    
    .pin-badge {
        font-size: 9px;
        padding: 4px 10px;
        background: rgba(76,175,80,0.15);
        border: 1px solid rgba(76,175,80,0.3);
        border-radius: 20px;
        color: #4CAF50;
    }
    
    .pin-badge.not-set {
        background: rgba(255,152,0,0.15);
        border-color: rgba(255,152,0,0.3);
        color: #FF9800;
        cursor: pointer;
    }
    
    /* Swap Card */
    .swap-card {
        background: rgba(255,255,255,0.02);
        border: 1px solid rgba(255,255,255,0.05);
        padding: 32px;
        margin-bottom: 24px;
    }
    
    /* Source Row (Horizontal) */
    .source-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 32px;
        padding-bottom: 32px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    
    .source-label {
        font-size: 10px;
        letter-spacing: 1px;
        color: rgba(255,255,255,0.3);
        margin-bottom: 8px;
        text-transform: uppercase;
    }
    
    .source-select {
        flex: 1;
        min-width: 180px;
    }
    
    .arrow-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        color: rgba(255,255,255,0.2);
        font-size: 24px;
        padding-top: 24px;
    }
    
    /* Destination Section (Vertical below) */
    .destination-section {
        margin-top: 16px;
    }
    
    .section-title {
        font-size: 10px;
        letter-spacing: 2px;
        color: rgba(255,255,255,0.3);
        margin-bottom: 20px;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .section-title::before {
        content: '';
        width: 20px;
        height: 1px;
        background: rgba(255,255,255,0.2);
    }
    
    .form-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 20px;
    }
    
    .form-group {
        flex: 1;
        min-width: 180px;
    }
    
    .form-label {
        font-size: 11px;
        letter-spacing: 0.5px;
        color: rgba(255,255,255,0.4);
        margin-bottom: 6px;
        display: block;
    }
    
    .form-label .required {
        color: #f44336;
        margin-left: 4px;
    }
    
    .form-input, .form-select {
        width: 100%;
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.1);
        padding: 12px 14px;
        font-family: inherit;
        font-size: 14px;
        color: #FFFFFF;
        transition: all 0.2s;
    }
    
    .form-input:focus, .form-select:focus {
        outline: none;
        border-color: rgba(255,255,255,0.3);
    }
    
    .form-input::placeholder {
        color: rgba(255,255,255,0.2);
    }
    
    .form-select option {
        background: #0a0a0a;
    }
    
    .field-hint {
        font-size: 9px;
        color: rgba(255,255,255,0.25);
        margin-top: 4px;
    }
    
    /* Amount Row */
    .amount-row {
        background: rgba(255,255,255,0.02);
        padding: 16px;
        margin: 24px 0;
        border-left: 2px solid #FFFFFF;
    }
    
    .amount-input {
        font-size: 24px;
        font-weight: 500;
        padding: 12px;
        text-align: center;
    }
    
    /* Radio Group */
    .radio-group {
        display: flex;
        gap: 24px;
        margin-top: 6px;
    }
    
    .radio-label {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        font-size: 13px;
    }
    
    .radio-label input {
        accent-color: #FFFFFF;
        width: 16px;
        height: 16px;
    }
    
    /* Submit Button */
    .submit-btn {
        width: 100%;
        background: #FFFFFF;
        border: none;
        padding: 16px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 2px;
        color: #000000;
        cursor: pointer;
        margin-top: 16px;
        transition: opacity 0.2s;
    }
    
    .submit-btn:hover { opacity: 0.9; }
    .submit-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    
    /* Security Section */
    .security-section {
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid rgba(255,255,255,0.05);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .security-btn {
        background: transparent;
        border: 1px solid rgba(255,255,255,0.15);
        padding: 10px 20px;
        font-family: inherit;
        font-size: 11px;
        letter-spacing: 1px;
        color: #FFFFFF;
        cursor: pointer;
    }
    
    .security-btn:hover { border-color: rgba(255,255,255,0.4); }
    
    /* Modals */
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.98);
        z-index: 1000;
        display: none;
        align-items: center;
        justify-content: center;
    }
    
    .modal-content {
        width: 360px;
        background: #0a0a0a;
        border: 1px solid rgba(255,255,255,0.1);
    }
    
    .modal-header {
        padding: 24px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        font-size: 14px;
        text-align: center;
        text-transform: uppercase;
    }
    
    .modal-body { padding: 28px; }
    .modal-footer {
        padding: 20px 24px;
        border-top: 1px solid rgba(255,255,255,0.05);
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }
    
    .modal-btn {
        background: transparent;
        border: 1px solid rgba(255,255,255,0.2);
        padding: 10px 20px;
        font-family: inherit;
        font-size: 11px;
        color: rgba(255,255,255,0.6);
        cursor: pointer;
    }
    
    .modal-btn-primary {
        background: #FFFFFF;
        border-color: #FFFFFF;
        color: #000000;
    }
    
    .pin-input-group {
        text-align: center;
        margin-bottom: 24px;
    }
    
    .pin-display {
        font-size: 28px;
        letter-spacing: 12px;
        font-family: monospace;
        background: rgba(255,255,255,0.03);
        padding: 16px;
        text-align: center;
    }
    
    .pin-pad {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin-top: 20px;
    }
    
    .pin-btn {
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.1);
        padding: 16px;
        font-size: 20px;
        font-family: inherit;
        color: #FFFFFF;
        cursor: pointer;
    }
    
    .pin-btn:active {
        background: #FFFFFF;
        color: #000000;
    }
    
    .error-text {
        color: #f44336;
        text-align: center;
        margin-top: 16px;
        font-size: 11px;
    }
    
    .loading-spinner {
        display: inline-block;
        width: 14px;
        height: 14px;
        border: 2px solid rgba(0,0,0,0.2);
        border-top-color: #000000;
        border-radius: 50%;
        animation: spin 0.6s linear infinite;
        margin-right: 8px;
        vertical-align: middle;
    }
    
    @keyframes spin { to { transform: rotate(360deg); } }
    
    /* Responsive */
    @media (max-width: 700px) {
        .container { width: 100%; padding: 16px; }
        .swap-card { padding: 20px; }
        .source-row { flex-direction: column; gap: 12px; }
        .arrow-icon { display: none; }
        .form-row { flex-direction: column; }
        .form-group { min-width: 100%; }
    }
</style>
</head>
<body>

<div class="container">
    <!-- Header -->
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
    
    <!-- Main Swap Card -->
    <div class="swap-card">
        <!-- SOURCE ROW (Horizontal) -->
        <div class="source-row">
            <div class="source-select">
                <div class="source-label">FROM</div>
                <select id="sourceParticipant" class="form-select" onchange="onSourceParticipantChange()">
                    <option value="">Select institution</option>
                </select>
            </div>
            <div class="source-select">
                <div class="source-label">ASSET TYPE</div>
                <select id="sourceAssetType" class="form-select" onchange="onSourceAssetChange()">
                    <option value="ACCOUNT">🏦 Bank Account</option>
                    <option value="MNO-WALLET">📱 Mobile Wallet</option>
                    <option value="BANK-WALLET">👛 Bank Wallet</option>
                    <option value="CARD">💳 Card</option>
                </select>
            </div>
            <div class="arrow-icon">→</div>
            <div class="source-select">
                <div class="source-label">YOUR IDENTIFIER</div>
                <input type="text" id="sourceIdentifier" class="form-input" placeholder="Account number or phone number">
                <div class="field-hint" id="sourceHint">Your account number or mobile number</div>
            </div>
        </div>
        
        <!-- AMOUNT -->
        <div class="amount-row">
            <div class="form-group" style="margin-bottom: 0;">
                <div class="form-label">AMOUNT</div>
                <input type="number" id="amount" class="form-input amount-input" placeholder="0.00" step="0.01">
                <div class="field-hint" id="amountHint">Min: 10 BWP | Max: 500,000 BWP</div>
            </div>
        </div>
        
        <!-- DESTINATION SECTION (Vertical) -->
        <div class="destination-section">
            <div class="section-title">DESTINATION</div>
            
            <div class="form-row">
                <div class="form-group">
                    <div class="form-label">COUNTRY</div>
                    <select id="destCountry" class="form-select" onchange="onDestCountryChange()">
                        <option value="">Select country</option>
                    </select>
                    <div class="field-hint">Leave empty to use your country (<?= $userCountry ?>)</div>
                </div>
                <div class="form-group">
                    <div class="form-label">INSTITUTION</div>
                    <select id="destParticipant" class="form-select" onchange="onDestParticipantChange()">
                        <option value="">Select institution</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <div class="form-label">ACTION</div>
                    <div class="radio-group" id="deliveryModeGroup">
                        <label class="radio-label"><input type="radio" name="deliveryMode" value="deposit" checked> DEPOSIT (Send money in)</label>
                        <label class="radio-label"><input type="radio" name="deliveryMode" value="cashout"> CASHOUT (Withdraw money out)</label>
                    </div>
                </div>
            </div>
            
            <div class="form-row" id="destinationFieldsContainer">
                <!-- Dynamic fields will appear here -->
                <div class="form-group">
                    <div class="form-label">DESTINATION IDENTIFIER</div>
                    <input type="text" id="destIdentifier" class="form-input" placeholder="Enter account number or phone number">
                    <div class="field-hint" id="destHint">Recipient's account number or mobile number</div>
                </div>
            </div>
        </div>
        
        <!-- SUBMIT BUTTON -->
        <button class="submit-btn" onclick="executeSwap()" id="submitBtn">SEND →</button>
    </div>
    
    <!-- Security Footer -->
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
            <div class="pin-input-group">
                <div class="pin-display" id="pinDisplay">••••••</div>
            </div>
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
            <div class="pin-input-group">
                <div class="pin-display" id="verifyPinDisplay">••••••</div>
            </div>
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
// GLOBAL DATA
// ============================================================
let participants = [];
let participantsByCountry = {};
let countries = [];
let userCountry = '<?= $userCountry ?>';
let pendingSwapData = null;
let currentPinInput = '';
let verifyPinInput = '';
let destinationFields = [];

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
            userCountry = data.user_country;
            
            // Populate source dropdown
            const sourceSelect = document.getElementById('sourceParticipant');
            sourceSelect.innerHTML = '<option value="">Select institution</option>';
            participants.forEach(p => {
                const icon = p.type === 'BANK' ? '🏦' : (p.type === 'MNO' ? '📱' : '🏢');
                sourceSelect.innerHTML += `<option value="${p.code}">${icon} ${p.name} (${p.country})</option>`;
            });
            
            // Populate country dropdown
            const countrySelect = document.getElementById('destCountry');
            countrySelect.innerHTML = '<option value="">-- Select country (optional) --</option>';
            if (countries.length > 0) {
                countries.forEach(c => {
                    const selected = (c.code === userCountry) ? 'selected' : '';
                    countrySelect.innerHTML += `<option value="${c.code}" ${selected}>${c.flag || '🌍'} ${c.name} (${c.currency})</option>`;
                });
            } else {
                // Fallback to participants-based countries
                Object.keys(participantsByCountry).forEach(code => {
                    const selected = (code === userCountry) ? 'selected' : '';
                    countrySelect.innerHTML += `<option value="${code}" ${selected}>${code}</option>`;
                });
            }
            
            // Trigger initial destination load based on default country
            onDestCountryChange();
        }
    } catch(e) {
        console.error('Load error:', e);
    }
}

// ============================================================
// SOURCE SIDE
// ============================================================
async function onSourceParticipantChange() {
    const participantCode = document.getElementById('sourceParticipant').value;
    const assetSelect = document.getElementById('sourceAssetType');
    
    if (!participantCode) {
        assetSelect.innerHTML = '<option value="ACCOUNT">🏦 Bank Account</option>';
        document.getElementById('amountHint').innerHTML = 'Min: 10 BWP | Max: 500,000 BWP';
        return;
    }
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get_source_details&participant_code=${encodeURIComponent(participantCode)}`
        });
        const data = await res.json();
        
        if (data.success) {
            // Update asset types dropdown based on what participant supports
            const assets = data.assets || ['ACCOUNT'];
            assetSelect.innerHTML = assets.map(a => {
                const label = getAssetLabel(a);
                return `<option value="${a}">${label}</option>`;
            }).join('');
            
            // Update amount limits
            const min = data.min_amount || 10;
            const max = data.max_amount || 500000;
            const currency = data.currency || 'BWP';
            document.getElementById('amountHint').innerHTML = `Min: ${min} ${currency} | Max: ${max.toLocaleString()} ${currency}`;
            document.getElementById('amount').min = min;
            document.getElementById('amount').max = max;
            
            // Update source hint
            const asset = assetSelect.value;
            updateSourceHint(asset);
        }
    } catch(e) {
        console.error('Error loading source details:', e);
    }
}

function onSourceAssetChange() {
    const asset = document.getElementById('sourceAssetType').value;
    updateSourceHint(asset);
}

function updateSourceHint(assetType) {
    const hintEl = document.getElementById('sourceHint');
    const inputEl = document.getElementById('sourceIdentifier');
    
    switch(assetType) {
        case 'ACCOUNT':
            hintEl.innerHTML = 'Your bank account number (8-16 digits)';
            inputEl.placeholder = 'Enter account number';
            break;
        case 'MNO-WALLET':
            hintEl.innerHTML = 'Your mobile wallet phone number (+267XXXXXXXXX)';
            inputEl.placeholder = 'Enter phone number';
            break;
        case 'BANK-WALLET':
            hintEl.innerHTML = 'Your bank wallet ID or phone number';
            inputEl.placeholder = 'Enter wallet identifier';
            break;
        case 'CARD':
            hintEl.innerHTML = 'Your card number (last 4 digits or full)';
            inputEl.placeholder = 'Enter card number';
            break;
        default:
            hintEl.innerHTML = 'Your identifier for this account';
            inputEl.placeholder = 'Enter identifier';
    }
}

function getAssetLabel(asset) {
    const labels = {
        'ACCOUNT': '🏦 Bank Account',
        'MNO-WALLET': '📱 Mobile Wallet',
        'BANK-WALLET': '👛 Bank Wallet',
        'CARD': '💳 Card',
        'ATM': '🏧 ATM',
        'CASHOUT-VOUCHER': '🎫 Voucher'
    };
    return labels[asset] || asset;
}

// ============================================================
// DESTINATION SIDE
// ============================================================
function onDestCountryChange() {
    const countrySelect = document.getElementById('destCountry');
    const selectedCountry = countrySelect.value;
    const destSelect = document.getElementById('destParticipant');
    
    let countryParticipants = [];
    
    if (!selectedCountry) {
        // Use user's country as default
        countryParticipants = participantsByCountry[userCountry] || [];
    } else {
        countryParticipants = participantsByCountry[selectedCountry] || [];
    }
    
    destSelect.innerHTML = '<option value="">Select institution</option>';
    countryParticipants.forEach(p => {
        const icon = p.type === 'BANK' ? '🏦' : (p.type === 'MNO' ? '📱' : '🏢');
        destSelect.innerHTML += `<option value="${p.code}">${icon} ${p.name}</option>`;
    });
}

async function onDestParticipantChange() {
    const participantCode = document.getElementById('destParticipant').value;
    const deliveryMode = document.querySelector('input[name="deliveryMode"]:checked')?.value || 'deposit';
    
    if (!participantCode) {
        resetDestinationFields();
        return;
    }
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get_destination_fields&participant_code=${encodeURIComponent(participantCode)}&delivery_mode=${deliveryMode}`
        });
        const data = await res.json();
        
        if (data.success) {
            destinationFields = data.fields || [];
            renderDestinationFields(destinationFields);
            document.getElementById('destHint').innerHTML = `Recipient's ${data.fields[0]?.label.toLowerCase() || 'identifier'} at ${data.participant_name}`;
        } else {
            resetDestinationFields();
        }
    } catch(e) {
        console.error('Error loading destination fields:', e);
        resetDestinationFields();
    }
}

function renderDestinationFields(fields) {
    const container = document.getElementById('destinationFieldsContainer');
    
    if (!fields || fields.length === 0) {
        container.innerHTML = `
            <div class="form-group">
                <div class="form-label">DESTINATION IDENTIFIER</div>
                <input type="text" id="destIdentifier" class="form-input" placeholder="Enter identifier">
                <div class="field-hint">Recipient's identifier</div>
            </div>
        `;
        return;
    }
    
    let html = '';
    fields.forEach(field => {
        const required = field.required ? '<span class="required">*</span>' : '';
        const patternAttr = field.pattern ? `pattern="${field.pattern}"` : '';
        html += `
            <div class="form-group">
                <div class="form-label">${field.label} ${required}</div>
                <input type="${field.type || 'text'}" 
                       id="dest_${field.name}" 
                       name="${field.name}"
                       class="form-input" 
                       placeholder="${field.placeholder || ''}"
                       ${patternAttr}
                       ${field.required ? 'required' : ''}>
                <div class="field-hint">${field.hint || ''}</div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function resetDestinationFields() {
    const container = document.getElementById('destinationFieldsContainer');
    container.innerHTML = `
        <div class="form-group">
            <div class="form-label">DESTINATION IDENTIFIER</div>
            <input type="text" id="destIdentifier" class="form-input" placeholder="Enter account number or phone number">
            <div class="field-hint">Recipient's account number or mobile number</div>
        </div>
    `;
    destinationFields = [];
}

// Listen for delivery mode change
document.querySelectorAll('input[name="deliveryMode"]').forEach(radio => {
    radio.addEventListener('change', () => {
        if (document.getElementById('destParticipant').value) {
            onDestParticipantChange();
        }
    });
});

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
    
    // Validate
    if (!sourceParticipant) { alert('Please select source institution'); return; }
    if (!sourceIdentifier) { alert('Please enter your source identifier'); return; }
    if (!amount || amount <= 0) { alert('Please enter a valid amount'); return; }
    if (!destParticipant) { alert('Please select destination institution'); return; }
    
    // Collect destination fields
    let destFields = {};
    if (destinationFields.length > 0) {
        destinationFields.forEach(field => {
            const input = document.getElementById(`dest_${field.name}`);
            if (input) destFields[field.name] = input.value.trim();
        });
    } else {
        const genericInput = document.getElementById('destIdentifier');
        if (genericInput) destFields.identifier = genericInput.value.trim();
    }
    
    const destIdentifier = Object.values(destFields)[0];
    if (!destIdentifier) { alert('Please enter destination identifier'); return; }
    
    // Show PIN modal
    pendingSwapData = {
        source_participant: sourceParticipant,
        source_asset: sourceAsset,
        source_identifier: sourceIdentifier,
        amount: amount,
        dest_participant: destParticipant,
        dest_delivery_mode: deliveryMode,
        dest_fields: destFields
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
    
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="loading-spinner"></span> PROCESSING...';
    
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
                dest_fields: JSON.stringify(pendingSwapData.dest_fields)
            }).toString()
        });
        const result = await res.json();
        
        if (result.status === 'success') {
            alert(`✓ Swap Complete!\n\nAmount: ${pendingSwapData.amount} BWP\nFrom: ${pendingSwapData.source_participant}\nTo: ${pendingSwapData.dest_participant}\n\nReference: ${result.data?.swap_reference || 'completed'}`);
            
            // Clear form
            document.getElementById('sourceIdentifier').value = '';
            document.getElementById('amount').value = '';
            document.getElementById('sourceParticipant').value = '';
            document.getElementById('destParticipant').value = '';
            resetDestinationFields();
            pendingSwapData = null;
        } else {
            alert(`❌ Swap Failed\n\n${result.message}`);
        }
    } catch(e) {
        alert('Error: ' + e.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'SEND →';
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
    
    if (currentPinInput.length === 6) {
        savePin();
    }
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
    } catch(e) {
        errorEl.innerHTML = 'Failed to set PIN';
    }
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
    
    if (verifyPinInput.length === 6) {
        submitPinVerification();
    }
}

function renderPinPad(containerId, handlerFunc) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    const nums = [1,2,3,4,5,6,7,8,9,'⌫',0,'CLR'];
    container.innerHTML = nums.map(n => 
        `<button class="pin-btn" onclick="(${handlerFunc.name})('${n}')">${n}</button>`
    ).join('');
}

function updatePinDisplay(displayId, value) {
    const display = document.getElementById(displayId);
    if (display) {
        const masked = value.padEnd(6, '•').split('').join(' ');
        display.innerHTML = masked;
    }
}

function logout() {
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=logout'
    }).then(() => {
        window.location.href = 'login.php';
    }).catch(() => {
        window.location.href = 'login.php';
    });
}

// Make functions global
window.onSourceParticipantChange = onSourceParticipantChange;
window.onSourceAssetChange = onSourceAssetChange;
window.onDestCountryChange = onDestCountryChange;
window.onDestParticipantChange = onDestParticipantChange;
window.executeSwap = executeSwap;
window.showPinSetup = showPinSetup;
window.closePinModal = closePinModal;
window.closePinVerifyModal = closePinVerifyModal;
window.submitPinVerification = submitPinVerification;
window.logout = logout;

// Initialize
loadData();

// If no PIN set, remind user
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
