<?php
// public/user/user_dashboard.php - VouchMorph Orchestration Dashboard
// Complete rewrite with proper PIN security and clean UI

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

// Get PIN status from database
$hasTransactionPin = false;
$pinAttempts = 0;
$pinLockedUntil = null;
$walletUuid = null;

try {
    $db = DBConnection::getInstance();
    $stmt = $db->prepare("
        SELECT 
            transaction_pin_hash, 
            has_transaction_pin, 
            pin_attempts, 
            pin_locked_until,
            wallet_uuid
        FROM users 
        WHERE user_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userData) {
        $hasTransactionPin = !empty($userData['transaction_pin_hash']) || ($userData['has_transaction_pin'] == 1);
        $pinAttempts = (int)($userData['pin_attempts'] ?? 0);
        $pinLockedUntil = $userData['pin_locked_until'] ?? null;
        $walletUuid = $userData['wallet_uuid'] ?? null;
    }
} catch (Throwable $e) {
    error_log("PIN status check error: " . $e->getMessage());
}

// ============================================================
// API BASE URL
// ============================================================
$apiBaseUrl = rtrim(getenv('VOUCHMORPH_API_URL') ?: 'https://vouchmorph.up.railway.app', '/');

// ============================================================
// LOAD CONFIGURATION
// ============================================================
$countries = [];
$allParticipants = [];
$participantsByCountry = [];

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
    $data = parseSimpleParticipantsYaml($yamlContent);
    if ($data && isset($data['participants'])) {
        foreach ($data['participants'] as $code => $participant) {
            if ($code === 'VOUCHMORPH') continue;
            
            $participantCode = strtoupper($code);
            $participantCountry = $participant['country'] ?? $userCountry;
            
            $allParticipants[$participantCode] = [
                'code' => $participantCode,
                'name' => $participant['name'] ?? $participantCode,
                'category' => $participant['type'] ?? 'BANK',
                'country_code' => $participantCountry,
                'currency' => $participant['limits']['currency'] ?? 'BWP',
                'assets' => $participant['assets'] ?? [],
                'status' => $participant['status'] ?? 'ACTIVE'
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
        SELECT id, institution_code, institution_name, source_type, asset_type, identifier, vault_slot 
        FROM user_funding_sources 
        WHERE user_id = :user_id AND status = 'ACTIVE'
        ORDER BY created_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Error loading sources: " . $e->getMessage());
}

function parseSimpleParticipantsYaml($content) {
    $result = ['participants' => []];
    $lines = explode("\n", $content);
    $currentParticipant = null;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
            $currentParticipant = $matches[1];
            $result['participants'][$currentParticipant] = [];
            continue;
        }
        
        if ($currentParticipant && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
            if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
            if ($value === 'true') $value = true;
            if ($value === 'false') $value = false;
            if (is_numeric($value)) $value = (float)$value;
            $result['participants'][$currentParticipant][$key] = $value;
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
    
    // Get participants for UI
    if ($action === 'get_participants') {
        echo json_encode([
            'success' => true,
            'participants' => array_values($allParticipants),
            'participants_by_country' => $participantsByCountry,
            'countries' => $countries,
            'user_phone' => $userPhone,
            'wallet_uuid' => $walletUuid
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
                'identifier' => maskIdentifier($fs['identifier'], $fs['asset_type']),
                'identifier_raw' => $fs['identifier']
            ];
        }
        echo json_encode(['success' => true, 'sources' => $sources]);
        exit;
    }
    
    // SET TRANSACTION PIN
    if ($action === 'set_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            if (strlen($pin) !== 6 || !ctype_digit($pin)) {
                throw new Exception('PIN must be exactly 6 digits');
            }
            
            $db = DBConnection::getInstance();
            $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("
                UPDATE users 
                SET transaction_pin_hash = :pin, 
                    has_transaction_pin = 1,
                    pin_attempts = 0,
                    pin_locked_until = NULL
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ':pin' => $hashedPin,
                ':user_id' => $userId
            ]);
            
            echo json_encode(['status' => 'success', 'message' => 'PIN set successfully']);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // VERIFY TRANSACTION PIN (with lockout)
    if ($action === 'verify_pin') {
        try {
            $pin = $_POST['pin'] ?? '';
            if (strlen($pin) !== 6 || !ctype_digit($pin)) {
                throw new Exception('PIN must be 6 digits');
            }
            
            $db = DBConnection::getInstance();
            
            $stmt = $db->prepare("
                SELECT transaction_pin_hash, pin_attempts, pin_locked_until 
                FROM users 
                WHERE user_id = :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userData || empty($userData['transaction_pin_hash'])) {
                throw new Exception('Transaction PIN not set. Please set your PIN first.');
            }
            
            // Check lockout
            if ($userData['pin_locked_until'] && strtotime($userData['pin_locked_until']) > time()) {
                $minutesRemaining = ceil((strtotime($userData['pin_locked_until']) - time()) / 60);
                throw new Exception("Too many attempts. Try again in {$minutesRemaining} minutes.");
            }
            
            // Verify PIN
            if (!password_verify($pin, $userData['transaction_pin_hash'])) {
                $newAttempts = ($userData['pin_attempts'] ?? 0) + 1;
                
                if ($newAttempts >= 3) {
                    $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET pin_attempts = :attempts, pin_locked_until = :locked 
                        WHERE user_id = :user_id
                    ");
                    $stmt->execute([
                        ':attempts' => $newAttempts,
                        ':locked' => $lockUntil,
                        ':user_id' => $userId
                    ]);
                    throw new Exception('Too many failed attempts. PIN locked for 15 minutes.');
                } else {
                    $stmt = $db->prepare("
                        UPDATE users SET pin_attempts = :attempts WHERE user_id = :user_id
                    ");
                    $stmt->execute([':attempts' => $newAttempts, ':user_id' => $userId]);
                    throw new Exception("Invalid PIN. " . (3 - $newAttempts) . " attempts remaining.");
                }
            }
            
            // Success - reset attempts
            $stmt = $db->prepare("
                UPDATE users 
                SET pin_attempts = 0, pin_locked_until = NULL 
                WHERE user_id = :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
            
            $_SESSION['pin_verified'] = true;
            $_SESSION['pin_verified_at'] = time();
            
            echo json_encode(['status' => 'success', 'message' => 'PIN verified']);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // CHECK PIN STATUS
    if ($action === 'check_pin_status') {
        try {
            $db = DBConnection::getInstance();
            $stmt = $db->prepare("
                SELECT 
                    CASE WHEN transaction_pin_hash IS NOT NULL THEN 1 ELSE 0 END as has_pin,
                    pin_attempts,
                    pin_locked_until
                FROM users 
                WHERE user_id = :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
            $status = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $isLocked = false;
            $lockMinutes = 0;
            
            if ($status && $status['pin_locked_until'] && strtotime($status['pin_locked_until']) > time()) {
                $isLocked = true;
                $lockMinutes = ceil((strtotime($status['pin_locked_until']) - time()) / 60);
            }
            
            echo json_encode([
                'status' => 'success',
                'has_pin' => (bool)($status['has_pin'] ?? false),
                'attempts_remaining' => max(0, 3 - ($status['pin_attempts'] ?? 0)),
                'is_locked' => $isLocked,
                'lock_minutes' => $lockMinutes
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // EXECUTE LINKED SWAP
    if ($action === 'swap_linked') {
        try {
            if (!isset($_SESSION['pin_verified']) || $_SESSION['pin_verified_at'] < (time() - 300)) {
                throw new Exception('PIN verification required');
            }
            
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $destInstitution = $_POST['dest_institution'] ?? '';
            $destIdentifier = $_POST['dest_identifier'] ?? '';
            
            if ($amount <= 0) throw new Exception('Invalid amount');
            if (empty($destInstitution)) throw new Exception('Destination institution required');
            if (empty($destIdentifier)) throw new Exception('Destination identifier required');
            
            $db = DBConnection::getInstance();
            $stmt = $db->prepare("
                SELECT institution_code, asset_type, identifier 
                FROM user_funding_sources 
                WHERE id = :id AND user_id = :user_id AND status = 'ACTIVE'
            ");
            $stmt->execute(['id' => $sourceId, 'user_id' => $userId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$source) throw new Exception('Source not found');
            
            // Call the swap API
            $result = callVouchMorphApi('v1/swap/execute', [
                'source' => [
                    'institution' => $source['institution_code'],
                    'asset_type' => $source['asset_type'],
                    'identifier' => $source['identifier'],
                    'amount' => $amount
                ],
                'destination' => [
                    'institution' => $destInstitution,
                    'identifier' => $destIdentifier,
                    'delivery_mode' => 'deposit'
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
    
    // LOGOUT
    if ($action === 'logout') {
        SessionManager::destroy();
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

function maskIdentifier($identifier, $assetType) {
    if (empty($identifier)) return '••••';
    if ($assetType === 'MNO-WALLET' && strlen($identifier) >= 8) {
        return substr($identifier, 0, 4) . '••••' . substr($identifier, -4);
    }
    if (strlen($identifier) >= 8) {
        return '••••' . substr($identifier, -4);
    }
    return '••••';
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
        return ['status' => 'error', 'message' => "API error: HTTP {$httpCode}"];
    }
    
    return json_decode($response, true) ?: ['status' => 'error', 'message' => 'Invalid response'];
}

// Prepare data for JavaScript
$countriesJson = json_encode($countries);
$participantsJson = json_encode(array_values($allParticipants));
$sourcesJson = json_encode(array_map(function($s) { 
    return [
        'id' => $s['id'], 
        'name' => $s['institution_name'] ?? $s['institution_code'], 
        'type' => $s['source_type'],
        'asset_type' => $s['asset_type'],
        'identifier' => maskIdentifier($s['identifier'], $s['asset_type']),
        'identifier_raw' => $s['identifier']
    ]; 
}, $fundingSources));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>VOUCHMORPH | SWAP</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body { 
        background: #0a0a0a; 
        font-family: 'Space Grotesk', monospace; 
        color: #FFFFFF; 
        min-height: 100vh; 
        display: flex; 
        align-items: center; 
        justify-content: center;
    }
    
    /* Main Container */
    .container { 
        max-width: 520px; 
        width: 90%; 
        margin: 32px auto; 
    }
    
    /* Header */
    .header {
        text-align: center;
        margin-bottom: 48px;
        padding-bottom: 24px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    
    .logo {
        font-size: 12px;
        letter-spacing: 4px;
        color: rgba(255,255,255,0.4);
        margin-bottom: 16px;
    }
    
    .logo span {
        color: #FFFFFF;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-top: 8px;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        background: rgba(255,255,255,0.05);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }
    
    .user-details {
        text-align: left;
    }
    
    .user-name {
        font-size: 14px;
        font-weight: 500;
        letter-spacing: 0.5px;
    }
    
    .user-phone {
        font-size: 11px;
        color: rgba(255,255,255,0.4);
        margin-top: 2px;
    }
    
    .pin-status {
        font-size: 9px;
        padding: 4px 10px;
        border-radius: 20px;
        background: rgba(76,175,80,0.15);
        color: #4CAF50;
        letter-spacing: 0.5px;
    }
    
    .pin-status.not-set {
        background: rgba(255,152,0,0.15);
        color: #FF9800;
    }
    
    .pin-status.locked {
        background: rgba(244,67,54,0.15);
        color: #f44336;
    }
    
    /* 3 MAIN BUTTONS */
    .main-buttons {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-bottom: 32px;
    }
    
    .main-btn {
        background: transparent;
        border: 1px solid rgba(255,255,255,0.08);
        padding: 20px 24px;
        font-family: inherit;
        font-size: 15px;
        font-weight: 500;
        letter-spacing: 1.5px;
        color: #FFFFFF;
        cursor: pointer;
        transition: all 0.2s ease;
        text-align: center;
        border-radius: 0;
        position: relative;
        overflow: hidden;
    }
    
    .main-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: rgba(255,255,255,0.02);
        transition: left 0.3s ease;
    }
    
    .main-btn:hover {
        border-color: rgba(255,255,255,0.25);
    }
    
    .main-btn:hover::before {
        left: 0;
    }
    
    .main-btn .btn-icon {
        font-size: 20px;
        margin-right: 12px;
    }
    
    /* Action Panels */
    .action-panel {
        display: none;
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid rgba(255,255,255,0.05);
        animation: fadeIn 0.3s ease;
    }
    
    .action-panel.active {
        display: block;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
    }
    
    .panel-title {
        font-size: 10px;
        letter-spacing: 2px;
        color: rgba(255,255,255,0.3);
        text-transform: uppercase;
    }
    
    .panel-close {
        background: none;
        border: none;
        color: rgba(255,255,255,0.3);
        font-size: 20px;
        cursor: pointer;
        padding: 4px 8px;
    }
    
    .panel-close:hover {
        color: #FFFFFF;
    }
    
    /* Forms */
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-label {
        font-size: 10px;
        letter-spacing: 1px;
        color: rgba(255,255,255,0.4);
        margin-bottom: 8px;
        display: block;
        text-transform: uppercase;
    }
    
    .form-label .required {
        color: #f44336;
        margin-left: 4px;
    }
    
    .form-input, .form-select {
        width: 100%;
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.1);
        padding: 14px 16px;
        font-family: inherit;
        font-size: 14px;
        color: #FFFFFF;
        border-radius: 0;
        transition: border-color 0.2s;
    }
    
    .form-input:focus, .form-select:focus {
        outline: none;
        border-color: rgba(255,255,255,0.4);
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
        margin-top: 6px;
    }
    
    /* Source List */
    .sources-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 24px;
        max-height: 300px;
        overflow-y: auto;
    }
    
    .source-item {
        padding: 14px 16px;
        background: rgba(255,255,255,0.02);
        border: 1px solid rgba(255,255,255,0.05);
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .source-item:hover {
        background: rgba(255,255,255,0.05);
        border-color: rgba(255,255,255,0.15);
        transform: translateX(4px);
    }
    
    .source-item.selected {
        border-color: #4CAF50;
        background: rgba(76,175,80,0.05);
    }
    
    .source-name {
        font-size: 14px;
        font-weight: 500;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .source-detail {
        font-size: 11px;
        color: rgba(255,255,255,0.4);
        margin-top: 6px;
        font-family: monospace;
    }
    
    .source-badge {
        font-size: 9px;
        padding: 2px 8px;
        background: rgba(255,255,255,0.05);
        border-radius: 12px;
    }
    
    /* Buttons */
    .primary-btn {
        width: 100%;
        background: #FFFFFF;
        border: none;
        padding: 16px 24px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 2px;
        color: #000000;
        cursor: pointer;
        margin-top: 16px;
        transition: opacity 0.2s;
    }
    
    .primary-btn:hover {
        opacity: 0.9;
    }
    
    .primary-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .secondary-btn {
        background: transparent;
        border: 1px solid rgba(255,255,255,0.15);
        padding: 12px 20px;
        font-family: inherit;
        font-size: 11px;
        letter-spacing: 1px;
        color: #FFFFFF;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .secondary-btn:hover {
        border-color: rgba(255,255,255,0.4);
    }
    
    .danger-btn {
        border-color: rgba(244,67,54,0.3);
        color: #f44336;
    }
    
    .danger-btn:hover {
        border-color: #f44336;
    }
    
    /* PIN Input Modal */
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
        max-width: 90%;
        background: #0a0a0a;
        border: 1px solid rgba(255,255,255,0.1);
    }
    
    .modal-header {
        padding: 24px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        font-size: 14px;
        letter-spacing: 1px;
        text-transform: uppercase;
        text-align: center;
    }
    
    .modal-body {
        padding: 28px;
    }
    
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
        letter-spacing: 1px;
        color: rgba(255,255,255,0.6);
        cursor: pointer;
    }
    
    .modal-btn-primary {
        background: #FFFFFF;
        border-color: #FFFFFF;
        color: #000000;
    }
    
    .modal-btn-primary:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    /* PIN Pad */
    .pin-input-group {
        text-align: center;
        margin-bottom: 24px;
    }
    
    .pin-display {
        font-size: 32px;
        letter-spacing: 8px;
        font-family: monospace;
        background: rgba(255,255,255,0.03);
        padding: 12px;
        border-radius: 0;
        text-align: center;
    }
    
    .pin-pad {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin: 20px 0;
    }
    
    .pin-btn {
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.1);
        padding: 16px;
        font-size: 20px;
        font-family: inherit;
        color: #FFFFFF;
        cursor: pointer;
        transition: all 0.1s;
    }
    
    .pin-btn:active {
        background: #FFFFFF;
        color: #000000;
    }
    
    .error-text {
        color: #f44336;
        text-align: center;
        margin-top: 12px;
        font-size: 11px;
    }
    
    .success-text {
        color: #4CAF50;
        text-align: center;
        margin-top: 12px;
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
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* Empty states */
    .empty-state {
        text-align: center;
        padding: 48px 24px;
        color: rgba(255,255,255,0.3);
        font-size: 12px;
    }
    
    /* Scrollbar */
    ::-webkit-scrollbar {
        width: 4px;
    }
    
    ::-webkit-scrollbar-track {
        background: rgba(255,255,255,0.03);
    }
    
    ::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.2);
    }
    
    /* Responsive */
    @media (max-width: 480px) {
        .container { width: 95%; margin: 20px auto; }
        .main-btn { padding: 16px 20px; font-size: 13px; }
        .form-input, .form-select { padding: 12px 14px; }
    }
</style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="logo">V<span>OUCH</span>M<span>ORPH</span></div>
        <div class="user-info">
            <div class="user-avatar">👤</div>
            <div class="user-details">
                <div class="user-name"><?= vm_h($userFullName) ?></div>
                <div class="user-phone"><?= vm_h($userPhone) ?></div>
            </div>
            <div class="pin-status <?= $hasTransactionPin ? '' : 'not-set' ?>" id="pinStatus">
                <?= $hasTransactionPin ? '✓ PIN SET' : '⚠ SET PIN' ?>
            </div>
        </div>
    </div>
    
    <!-- 3 MAIN BUTTONS -->
    <div class="main-buttons">
        <button class="main-btn" onclick="showPanel('linked')">
            <span class="btn-icon">🔗</span> SEND FROM LINKED SOURCE
        </button>
        <button class="main-btn" onclick="showPanel('adhoc')">
            <span class="btn-icon">🏦</span> SEND FROM BANK ACCOUNT
        </button>
        <button class="main-btn" onclick="showPanel('security')">
            <span class="btn-icon">🔒</span> SECURITY
        </button>
    </div>
    
    <!-- PANEL 1: LINKED SOURCE -->
    <div id="panelLinked" class="action-panel">
        <div class="panel-header">
            <div class="panel-title">🔗 SELECT SOURCE</div>
            <button class="panel-close" onclick="hidePanel('linked')">✕</button>
        </div>
        <div id="linkedSourcesList" class="sources-list">
            <div class="empty-state">Loading sources...</div>
        </div>
        <div id="linkedForm" style="display: none;">
            <div class="form-group">
                <label class="form-label">AMOUNT (BWP)</label>
                <input type="number" id="linkedAmount" class="form-input" placeholder="Enter amount" step="0.01" autocomplete="off">
                <div class="field-hint">Minimum amount: 10 BWP</div>
            </div>
            <div class="form-group">
                <label class="form-label">DESTINATION COUNTRY</label>
                <select id="linkedDestCountry" class="form-select">
                    <option value="">-- Select Country --</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">DESTINATION INSTITUTION</label>
                <select id="linkedDestInstitution" class="form-select">
                    <option value="">-- Select Institution --</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">DESTINATION IDENTIFIER</label>
                <input type="text" id="linkedDestIdentifier" class="form-input" placeholder="Account number or phone number" autocomplete="off">
                <div class="field-hint">Recipient's account number or mobile wallet phone number</div>
            </div>
            <button class="primary-btn" onclick="initiateLinkedSwap()" id="linkedExecuteBtn">SEND →</button>
        </div>
    </div>
    
    <!-- PANEL 2: AD-HOC BANK ACCOUNT -->
    <div id="panelAdhoc" class="action-panel">
        <div class="panel-header">
            <div class="panel-title">🏦 BANK ACCOUNT DETAILS</div>
            <button class="panel-close" onclick="hidePanel('adhoc')">✕</button>
        </div>
        <div class="form-group">
            <label class="form-label">SOURCE BANK</label>
            <select id="adhocSourceBank" class="form-select">
                <option value="">-- Select Bank --</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">ACCOUNT NUMBER</label>
            <input type="text" id="adhocAccountNumber" class="form-input" placeholder="Your account number" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label">AMOUNT (BWP)</label>
            <input type="number" id="adhocAmount" class="form-input" placeholder="Enter amount" step="0.01" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label">DESTINATION COUNTRY</label>
            <select id="adhocDestCountry" class="form-select">
                <option value="">-- Select Country --</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">DESTINATION INSTITUTION</label>
            <select id="adhocDestInstitution" class="form-select">
                <option value="">-- Select Institution --</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">DESTINATION IDENTIFIER</label>
            <input type="text" id="adhocDestIdentifier" class="form-input" placeholder="Recipient account or phone number" autocomplete="off">
        </div>
        <button class="primary-btn" onclick="initiateAdhocSwap()" id="adhocExecuteBtn">SEND →</button>
    </div>
    
    <!-- PANEL 3: SECURITY -->
    <div id="panelSecurity" class="action-panel">
        <div class="panel-header">
            <div class="panel-title">🔒 SECURITY SETTINGS</div>
            <button class="panel-close" onclick="hidePanel('security')">✕</button>
        </div>
        <button class="secondary-btn" style="width:100%; margin-bottom:12px;" onclick="showPinSetup()">SET TRANSACTION PIN</button>
        <button class="secondary-btn danger-btn" style="width:100%;" onclick="logout()">LOGOUT</button>
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
            <button class="modal-btn modal-btn-primary" id="verifySubmitBtn" onclick="submitPinVerification()">VERIFY</button>
        </div>
    </div>
</div>

<script>
// ============================================================
// GLOBAL VARIABLES
// ============================================================
let participants = [];
let participantsByCountry = {};
let countries = [];
let linkedSources = [];
let currentLinkedSourceId = null;
let pendingSwapData = null;
let currentPinInput = '';
let verifyPinInput = '';

// ============================================================
// UI FUNCTIONS
// ============================================================
function showPanel(panel) {
    document.querySelectorAll('.action-panel').forEach(p => p.classList.remove('active'));
    document.getElementById(`panel${panel.charAt(0).toUpperCase() + panel.slice(1)}`).classList.add('active');
    
    if (panel === 'linked') loadLinkedSources();
    if (panel === 'adhoc') loadAdhocBanks();
}

function hidePanel(panel) {
    document.getElementById(`panel${panel.charAt(0).toUpperCase() + panel.slice(1)}`).classList.remove('active');
}

function showError(msg) { alert('❌ ' + msg); }
function showSuccess(msg) { alert('✓ ' + msg); }

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
            
            populateCountrySelect('linkedDestCountry');
            populateCountrySelect('adhocDestCountry');
        }
        
        await loadLinkedSources();
        await checkPinStatus();
        
    } catch(e) {
        console.error('Load error:', e);
    }
}

function populateCountrySelect(selectId) {
    const select = document.getElementById(selectId);
    if (!select) return;
    
    select.innerHTML = '<option value="">-- Select Country --</option>';
    if (countries.length > 0) {
        countries.forEach(c => {
            select.innerHTML += `<option value="${c.code}">${c.flag || '🌍'} ${c.name} (${c.currency})</option>`;
        });
    } else {
        // Fallback to participants-based countries
        const countryCodes = Object.keys(participantsByCountry);
        countryCodes.forEach(code => {
            select.innerHTML += `<option value="${code}">${code}</option>`;
        });
    }
    
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
        container.innerHTML = '<div class="empty-state">🔗 No linked sources found.<br>Contact support to link your account.</div>';
        form.style.display = 'none';
        return;
    }
    
    container.innerHTML = linkedSources.map(s => `
        <div class="source-item ${currentLinkedSourceId === s.id ? 'selected' : ''}" onclick="selectLinkedSource(${s.id}, '${s.name.replace(/'/g, "\\'")}')">
            <div class="source-name">
                ${s.name}
                <span class="source-badge">${s.asset_type || s.type}</span>
            </div>
            <div class="source-detail">${s.identifier}</div>
        </div>
    `).join('');
}

function selectLinkedSource(id, name) {
    currentLinkedSourceId = id;
    renderLinkedSources();
    document.getElementById('linkedForm').style.display = 'block';
    document.getElementById('linkedAmount').focus();
    document.getElementById('linkedForm').scrollIntoView({ behavior: 'smooth' });
}

function initiateLinkedSwap() {
    const amount = parseFloat(document.getElementById('linkedAmount').value);
    const destCountry = document.getElementById('linkedDestCountry').value;
    const destInstitution = document.getElementById('linkedDestInstitution').value;
    const destIdentifier = document.getElementById('linkedDestIdentifier').value.trim();
    
    if (!currentLinkedSourceId) { showError('Select a source first'); return; }
    if (!amount || amount < 10) { showError('Enter valid amount (min 10 BWP)'); return; }
    if (!destCountry) { showError('Select destination country'); return; }
    if (!destInstitution) { showError('Select destination institution'); return; }
    if (!destIdentifier) { showError('Enter destination identifier'); return; }
    
    pendingSwapData = {
        source_id: currentLinkedSourceId,
        amount: amount,
        dest_institution: destInstitution,
        dest_identifier: destIdentifier
    };
    
    showPinVerifyModal();
}

// ============================================================
// AD-HOC BANK ACCOUNT
// ============================================================
function loadAdhocBanks() {
    const sourceSelect = document.getElementById('adhocSourceBank');
    sourceSelect.innerHTML = '<option value="">-- Select Bank --</option>';
    
    participants.filter(p => p.category === 'BANK').forEach(p => {
        sourceSelect.innerHTML += `<option value="${p.code}">🏦 ${p.name}</option>`;
    });
}

function initiateAdhocSwap() {
    const sourceBank = document.getElementById('adhocSourceBank').value;
    const accountNumber = document.getElementById('adhocAccountNumber').value.trim();
    const amount = parseFloat(document.getElementById('adhocAmount').value);
    const destCountry = document.getElementById('adhocDestCountry').value;
    const destInstitution = document.getElementById('adhocDestInstitution').value;
    const destIdentifier = document.getElementById('adhocDestIdentifier').value.trim();
    
    if (!sourceBank) { showError('Select source bank'); return; }
    if (!accountNumber) { showError('Enter account number'); return; }
    if (!amount || amount < 10) { showError('Enter valid amount'); return; }
    if (!destCountry) { showError('Select destination country'); return; }
    if (!destInstitution) { showError('Select destination institution'); return; }
    if (!destIdentifier) { showError('Enter destination identifier'); return; }
    
    pendingSwapData = {
        type: 'adhoc',
        source_bank: sourceBank,
        account_number: accountNumber,
        amount: amount,
        dest_institution: destInstitution,
        dest_identifier: destIdentifier
    };
    
    showPinVerifyModal();
}

// ============================================================
// PIN MANAGEMENT
// ============================================================
async function checkPinStatus() {
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=check_pin_status'
        });
        const data = await res.json();
        
        if (data.status === 'success') {
            const statusEl = document.getElementById('pinStatus');
            if (data.has_pin) {
                if (data.is_locked) {
                    statusEl.innerHTML = `🔒 LOCKED (${data.lock_minutes} min)`;
                    statusEl.className = 'pin-status locked';
                } else {
                    statusEl.innerHTML = '✓ PIN SET';
                    statusEl.className = 'pin-status';
                }
            } else {
                statusEl.innerHTML = '⚠ SET PIN';
                statusEl.className = 'pin-status not-set';
            }
        }
    } catch(e) {
        console.error('PIN status check failed:', e);
    }
}

// PIN SETUP
function showPinSetup() {
    currentPinInput = '';
    renderPinPad('pinPad', pinInput, 'pinDisplay');
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
    
    document.getElementById('pinError').innerHTML = '<span class="loading-spinner"></span> Setting PIN...';
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=set_pin&pin=${currentPinInput}`
        });
        const data = await res.json();
        
        if (data.status === 'success') {
            closePinModal();
            await checkPinStatus();
            showSuccess('PIN set successfully!');
        } else {
            document.getElementById('pinError').innerHTML = data.message;
        }
    } catch(e) {
        document.getElementById('pinError').innerHTML = 'Failed to set PIN';
    }
}

// PIN VERIFY
function showPinVerifyModal() {
    verifyPinInput = '';
    renderPinPad('verifyPinPad', verifyPinHandler, 'verifyPinDisplay');
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

async function submitPinVerification() {
    if (verifyPinInput.length !== 6) return;
    
    const errorEl = document.getElementById('verifyPinError');
    const submitBtn = document.getElementById('verifySubmitBtn');
    
    errorEl.innerHTML = '<span class="loading-spinner"></span> Verifying...';
    submitBtn.disabled = true;
    
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
            submitBtn.disabled = false;
            verifyPinInput = '';
            updatePinDisplay('verifyPinDisplay', '');
            
            if (data.message.includes('locked')) {
                setTimeout(() => {
                    closePinVerifyModal();
                    showPanel('security');
                    checkPinStatus();
                }, 2000);
            }
        }
    } catch(e) {
        errorEl.innerHTML = e.message;
        submitBtn.disabled = false;
    }
}

// ============================================================
// SWAP EXECUTION
// ============================================================
async function executePendingSwap() {
    if (!pendingSwapData) return;
    
    let btn = null;
    let action = '';
    let body = '';
    
    if (pendingSwapData.source_id) {
        // Linked source swap
        btn = document.getElementById('linkedExecuteBtn');
        action = 'swap_linked';
        body = `action=${action}&source_id=${pendingSwapData.source_id}&amount=${pendingSwapData.amount}&dest_institution=${pendingSwapData.dest_institution}&dest_identifier=${encodeURIComponent(pendingSwapData.dest_identifier)}`;
    } else {
        // Ad-hoc swap
        btn = document.getElementById('adhocExecuteBtn');
        action = 'swap_adhoc';
        body = `action=${action}&amount=${pendingSwapData.amount}&source_institution=${pendingSwapData.source_bank}&account_number=${encodeURIComponent(pendingSwapData.account_number)}&dest_institution=${pendingSwapData.dest_institution}&dest_identifier=${encodeURIComponent(pendingSwapData.dest_identifier)}`;
    }
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span> PROCESSING...';
    }
    
    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
        const result = await res.json();
        
        if (result.status === 'success') {
            showSuccess(`Swap complete!\nAmount: ${pendingSwapData.amount} BWP\nReference: ${result.swap_reference || 'completed'}`);
            
            // Clear form
            if (pendingSwapData.source_id) {
                document.getElementById('linkedAmount').value = '';
                document.getElementById('linkedDestIdentifier').value = '';
                document.getElementById('linkedForm').style.display = 'none';
                currentLinkedSourceId = null;
                renderLinkedSources();
            } else {
                document.getElementById('adhocAmount').value = '';
                document.getElementById('adhocAccountNumber').value = '';
                document.getElementById('adhocDestIdentifier').value = '';
            }
            
            hidePanel('linked');
            hidePanel('adhoc');
            pendingSwapData = null;
        } else {
            showError(result.message);
        }
    } catch(e) {
        showError(e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'SEND →';
        }
    }
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function renderPinPad(containerId, handlerFunc, displayId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    const nums = [1,2,3,4,5,6,7,8,9,'⌫',0,'CLR'];
    container.innerHTML = nums.map(n => 
        `<button class="pin-btn" onclick="handlerFunc('${n}')">${n}</button>`
    ).join('');
}

function updatePinDisplay(displayId, value) {
    const display = document.getElementById(displayId);
    if (display) {
        display.innerHTML = value.padEnd(6, '•').split('').join(' ');
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

// Make functions global for onclick
window.pinInput = pinInput;
window.verifyPinHandler = verifyPinHandler;
window.showPinSetup = showPinSetup;
window.closePinModal = closePinModal;
window.closePinVerifyModal = closePinVerifyModal;
window.submitPinVerification = submitPinVerification;
window.selectLinkedSource = selectLinkedSource;
window.initiateLinkedSwap = initiateLinkedSwap;
window.initiateAdhocSwap = initiateAdhocSwap;
window.showPanel = showPanel;
window.hidePanel = hidePanel;
window.logout = logout;

// Initialize
loadData();

// If no PIN set, remind user after a moment
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
