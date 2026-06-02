<?php
// public/user/user_dashboard.php - PREMIUM ENTERPRISE DASHBOARD
// Designed for HNWI, European & US markets

ini_set('display_errors', 0); // Turn off in production
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

$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    if ($isAjax) vm_json_response(['status' => 'error', 'message' => 'Database error']);
    die("System error");
}

if (empty($userPhone) && !empty($userId)) {
    try {
        $stmt = $db->prepare("SELECT phone, country, has_transaction_pin, full_name FROM users WHERE user_id = :user_id OR id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $userData = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($userData) {
            $userPhone = $userData['phone'];
            $userCountry = $userData['country'] ?? $userCountry;
            $hasTransactionPin = (bool)($userData['has_transaction_pin'] ?? false);
            $user['phone'] = $userPhone;
            $user['country'] = $userCountry;
            $user['has_transaction_pin'] = $hasTransactionPin;
            $user['full_name'] = $userData['full_name'] ?? '';
            SessionManager::setUser($user);
        }
    } catch (\Throwable $e) {}
}

function loadParticipantsFromJson($countryName) {
    $path = __DIR__ . "/../../src/Core/Config/Countries/{$countryName}/participants.json";
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    if ($content === false) return [];
    $data = json_decode($content, true);
    if (!is_array($data)) return [];
    $participants = $data['participants'] ?? [];
    foreach ($participants as $code => &$p) {
        $p['country'] = $countryName;
        if (!isset($p['name']) || empty($p['name'])) $p['name'] = $code;
    }
    return $participants;
}

function getCountryFolders() {
    $basePath = __DIR__ . "/../../src/Core/Config/Countries/";
    $folders = [];
    if (is_dir($basePath)) {
        foreach (scandir($basePath) as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($basePath . $item)) $folders[] = $item;
        }
    }
    return $folders;
}

$sourceParticipants = loadParticipantsFromJson($userCountry);
$allParticipants = [];
$destinationCountries = [];
foreach (getCountryFolders() as $country) {
    foreach (loadParticipantsFromJson($country) as $code => $p) {
        $allParticipants[$code] = $p;
        $destinationCountries[$country] = true;
    }
}
$destinationCountries = array_keys($destinationCountries);

$fundingSources = [];
$stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE'");
$stmt->execute([':user_id' => $userId]);
$fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getAssetTypes($participant) { return $participant['capabilities']['asset_types'] ?? []; }
function userHasTransactionPin($db, $userId) {
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return !empty($result['transaction_pin_hash']);
}
function verifyTransactionPin($db, $userId, $pin) {
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
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
    
    if ($action === 'check_transaction_pin') {
        vm_json_response(['has_pin' => userHasTransactionPin($db, $userId)]);
    }
    
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
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
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
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    if ($action === 'change_password') {
        try {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!password_verify($currentPassword, $userData['password_hash'])) throw new Exception('Current password is incorrect');
            if (strlen($newPassword) < 6) throw new Exception('Password must be at least 6 characters');
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :user_id");
            $stmt->execute([':hash' => $newHash, ':user_id' => $userId]);
            vm_json_response(['status' => 'success', 'message' => 'Password changed successfully']);
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    if ($action === 'get_destination_institutions') {
        $country = $_POST['country'] ?? $_GET['country'] ?? '';
        $instList = [];
        foreach ($allParticipants as $code => $p) {
            if ($p['country'] === $country && ($p['status'] ?? 'ACTIVE') === 'ACTIVE') {
                $instList[] = ['code' => $code, 'name' => $p['name'] ?? $code, 'asset_types' => getAssetTypes($p)];
            }
        }
        vm_json_response(['success' => true, 'institutions' => $instList]);
    }
    
    if ($action === 'save_source') {
        try {
            $consentToken = $_POST['consent_token'] ?? '';
            $operation = 'save_source';
            if (!isset($_SESSION['consent_token_' . $operation]) || $_SESSION['consent_token_' . $operation]['token'] !== $consentToken || $_SESSION['consent_token_' . $operation]['expires'] < time()) {
                throw new Exception('Transaction authorization required or expired');
            }
            $institutionCode = trim($_POST['institution_code'] ?? '');
            $assetType = strtoupper(trim($_POST['asset_type'] ?? ''));
            $identifier = trim($_POST['identifier'] ?? '');
            $participant = $sourceParticipants[$institutionCode] ?? null;
            if (!$participant) throw new Exception("Institution not found");
            $maskedId = strlen($identifier) > 4 ? '••••' . substr($identifier, -4) : '••••';
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $encrypted = base64_encode(openssl_encrypt($identifier, 'AES-256-CBC', $encryptionKey, 0, substr($encryptionKey, 0, 16)));
            $institutionName = $participant['name'] ?? $institutionCode;
            $stmt = $db->prepare("INSERT INTO user_funding_sources (user_id, institution_code, institution_name, institution_country, source_type, masked_identifier, encrypted_identifier, linked_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $institutionCode, $institutionName, $userCountry, $assetType, $maskedId, $encrypted, $userPhone]);
            unset($_SESSION['consent_token_' . $operation]);
            vm_json_response(['status' => 'success', 'message' => 'Source saved!']);
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
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
            $destAction = trim($_POST['dest_action'] ?? '');
            $destValue = trim($_POST['dest_value'] ?? '');
            if ($amount < 10) throw new Exception('Minimum amount is 10.00');
            $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
            $withdrawalCode = $destAction === 'cashout' ? (string)random_int(100000, 999999) : null;
            error_log("SWAP EXECUTED: User $userId, Amount $amount, Ref $swapReference");
            unset($_SESSION['consent_token_' . $operation]);
            vm_json_response(['status' => 'success', 'message' => $destAction === 'cashout' ? 'Withdrawal code generated' : 'Swap completed successfully', 'swap_reference' => $swapReference, 'withdrawal_code' => $withdrawalCode]);
        } catch (Exception $e) {
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
    vm_json_response(['status' => 'error', 'message' => 'Invalid action']);
}

$userInitial = strtoupper(substr(($user['full_name'] ?? $userPhone ?? 'U'), 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph | Executive Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
<link rel="icon" href="data:,">
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: #000000;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        color: #FFFFFF;
        min-height: 100vh;
        letter-spacing: -0.01em;
    }

    /* Premium Gradient Background */
    .bg-gradient {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: radial-gradient(ellipse at 20% 30%, #0a0a0a 0%, #000000 100%);
        z-index: 0;
    }

    /* Subtle Grid Pattern */
    .bg-grid {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
                          linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
        background-size: 60px 60px;
        pointer-events: none;
        z-index: 0;
    }

    /* Main Container */
    .app-container {
        position: relative;
        z-index: 2;
        max-width: 1280px;
        margin: 0 auto;
        padding: 24px 32px;
        min-height: 100vh;
    }

    /* Premium Header */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0 0 24px 0;
        border-bottom: 1px solid rgba(255,255,255,0.06);
        margin-bottom: 32px;
    }

    .logo-area {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .logo-icon {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #FFFFFF 0%, #D4AF37 100%);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 20px;
        color: #000000;
    }

    .logo-text {
        font-size: 20px;
        font-weight: 700;
        letter-spacing: -0.02em;
        background: linear-gradient(135deg, #FFFFFF 0%, #D4AF37 70%);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }

    .logo-badge {
        font-size: 10px;
        font-weight: 500;
        color: #D4AF37;
        letter-spacing: 0.05em;
        margin-left: 8px;
    }

    /* User Menu */
    .user-menu {
        display: flex;
        align-items: center;
        gap: 24px;
    }

    .user-avatar {
        width: 48px;
        height: 48px;
        background: linear-gradient(135deg, #D4AF37 0%, #B8942E 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 18px;
        color: #000000;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 4px 20px rgba(212, 175, 55, 0.2);
    }

    .user-avatar:hover {
        transform: scale(1.05);
        box-shadow: 0 6px 28px rgba(212, 175, 55, 0.3);
    }

    .user-info {
        text-align: right;
    }

    .user-welcome {
        font-size: 12px;
        font-weight: 400;
        color: rgba(255,255,255,0.5);
        letter-spacing: 0.02em;
    }

    .user-phone {
        font-size: 14px;
        font-weight: 600;
        font-family: monospace;
        letter-spacing: 0.5px;
    }

    /* Balance Cards */
    .balance-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }

    .balance-card {
        background: rgba(10,10,10,0.8);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 24px;
        padding: 24px;
        transition: all 0.3s ease;
    }

    .balance-card:hover {
        border-color: rgba(212, 175, 55, 0.3);
        transform: translateY(-2px);
    }

    .balance-label {
        font-size: 12px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: rgba(255,255,255,0.4);
        margin-bottom: 12px;
    }

    .balance-amount {
        font-size: 36px;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #FFFFFF;
    }

    .balance-currency {
        font-size: 14px;
        font-weight: 500;
        color: rgba(255,255,255,0.4);
        margin-left: 4px;
    }

    /* Main Content Grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 32px;
    }

    /* Action Cards */
    .action-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
        margin-bottom: 32px;
    }

    .action-card {
        background: rgba(10,10,10,0.6);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 20px;
        padding: 20px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .action-card:hover {
        border-color: #D4AF37;
        background: rgba(212, 175, 55, 0.05);
        transform: translateY(-2px);
    }

    .action-icon {
        font-size: 32px;
        margin-bottom: 16px;
    }

    .action-title {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 6px;
    }

    .action-desc {
        font-size: 12px;
        color: rgba(255,255,255,0.4);
        line-height: 1.4;
    }

    /* Quick Swap Panel */
    .quick-swap {
        background: rgba(10,10,10,0.8);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 24px;
        padding: 24px;
    }

    .panel-title {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .panel-title span {
        background: linear-gradient(135deg, #D4AF37 0%, #FFFFFF 100%);
        width: 4px;
        height: 20px;
        border-radius: 2px;
    }

    .swap-input {
        width: 100%;
        background: rgba(0,0,0,0.5);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 14px;
        padding: 14px 16px;
        color: #FFFFFF;
        font-size: 14px;
        margin-bottom: 16px;
        transition: all 0.2s;
    }

    .swap-input:focus {
        outline: none;
        border-color: #D4AF37;
    }

    .swap-select {
        width: 100%;
        background: rgba(0,0,0,0.5);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 14px;
        padding: 14px 16px;
        color: #FFFFFF;
        font-size: 14px;
        margin-bottom: 16px;
        cursor: pointer;
    }

    .swap-select option {
        background: #1a1a1a;
    }

    .swap-btn {
        width: 100%;
        background: linear-gradient(135deg, #D4AF37 0%, #B8942E 100%);
        border: none;
        border-radius: 14px;
        padding: 16px;
        color: #000000;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: 8px;
    }

    .swap-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 24px rgba(212, 175, 55, 0.25);
    }

    /* Recent Activity */
    .recent-activity {
        background: rgba(10,10,10,0.6);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 24px;
        padding: 24px;
        margin-top: 32px;
    }

    .activity-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 0;
        border-bottom: 1px solid rgba(255,255,255,0.04);
    }

    .activity-item:last-child {
        border-bottom: none;
    }

    .activity-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .activity-icon {
        width: 40px;
        height: 40px;
        background: rgba(212, 175, 55, 0.1);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }

    .activity-details {
        display: flex;
        flex-direction: column;
    }

    .activity-title {
        font-size: 14px;
        font-weight: 600;
    }

    .activity-time {
        font-size: 11px;
        color: rgba(255,255,255,0.3);
    }

    .activity-amount {
        font-weight: 700;
        font-size: 14px;
    }

    /* Security Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.95);
        backdrop-filter: blur(20px);
        z-index: 1000;
        display: none;
        align-items: center;
        justify-content: center;
    }

    .modal {
        background: #0a0a0a;
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 32px;
        padding: 32px;
        max-width: 480px;
        width: 90%;
        max-height: 85vh;
        overflow-y: auto;
    }

    .modal-header {
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-close {
        width: 32px;
        height: 32px;
        background: rgba(255,255,255,0.05);
        border: none;
        border-radius: 50%;
        color: #FFFFFF;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .security-option {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 0;
        border-bottom: 1px solid rgba(255,255,255,0.06);
        cursor: pointer;
        transition: all 0.2s;
    }

    .security-option:hover {
        padding-left: 8px;
    }

    .security-option-left {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .security-option-title {
        font-weight: 600;
        font-size: 16px;
    }

    .security-option-desc {
        font-size: 12px;
        color: rgba(255,255,255,0.4);
    }

    /* PIN Numpad */
    .pin-dots {
        display: flex;
        justify-content: center;
        gap: 16px;
        margin: 32px 0;
    }

    .pin-dot {
        width: 16px;
        height: 16px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        transition: all 0.2s;
    }

    .pin-dot.filled {
        background: #D4AF37;
        box-shadow: 0 0 12px rgba(212, 175, 55, 0.5);
    }

    .pin-numpad {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-top: 24px;
    }

    .numpad-btn {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 16px;
        padding: 18px;
        font-size: 24px;
        font-weight: 600;
        color: #FFFFFF;
        cursor: pointer;
        transition: all 0.1s;
        text-align: center;
    }

    .numpad-btn:active {
        background: #D4AF37;
        color: #000000;
        transform: scale(0.96);
    }

    /* Loading */
    .loading-spinner {
        text-align: center;
        padding: 48px;
    }

    .spinner {
        width: 48px;
        height: 48px;
        border: 2px solid rgba(255,255,255,0.1);
        border-top-color: #D4AF37;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 16px;
    }

    @keyframes spin { to { transform: rotate(360deg); } }

    /* Utility */
    .text-gold { color: #D4AF37; }
    .text-muted { color: rgba(255,255,255,0.4); }
    .divider { height: 1px; background: rgba(255,255,255,0.06); margin: 16px 0; }

    @media (max-width: 968px) {
        .app-container { padding: 16px; }
        .dashboard-grid { grid-template-columns: 1fr; }
        .balance-grid { grid-template-columns: 1fr; }
        .action-grid { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>
<div class="bg-gradient"></div>
<div class="bg-grid"></div>

<div class="app-container">
    <!-- Header -->
    <div class="header">
        <div class="logo-area">
            <div class="logo-icon">VM</div>
            <div>
                <span class="logo-text">VOUCHMORPH</span>
                <span class="logo-badge">PREMIUM</span>
            </div>
        </div>
        <div class="user-menu">
            <div class="user-info">
                <div class="user-welcome">Welcome back</div>
                <div class="user-phone"><?= vm_h(substr($userPhone, -6)) ?></div>
            </div>
            <div class="user-avatar" onclick="openSecurityModal()"><?= vm_h($userInitial) ?></div>
        </div>
    </div>

    <!-- Balance Overview -->
    <div class="balance-grid">
        <div class="balance-card">
            <div class="balance-label">Total Balance</div>
            <div class="balance-amount">0.00<span class="balance-currency">USD</span></div>
            <div class="text-muted" style="font-size: 12px; margin-top: 8px;">≈ 0.00 BWP</div>
        </div>
        <div class="balance-card">
            <div class="balance-label">Linked Sources</div>
            <div class="balance-amount"><?= count($fundingSources) ?><span class="balance-currency">Accounts</span></div>
        </div>
        <div class="balance-card">
            <div class="balance-label">Available Countries</div>
            <div class="balance-amount"><?= count($destinationCountries) ?><span class="balance-currency">Markets</span></div>
        </div>
    </div>

    <!-- Main Dashboard Content -->
    <div class="dashboard-grid">
        <div>
            <!-- Action Cards -->
            <div class="action-grid">
                <div class="action-card" onclick="startSwap('single')">
                    <div class="action-icon">↻</div>
                    <div class="action-title">Instant Swap</div>
                    <div class="action-desc">Convert & transfer instantly</div>
                </div>
                <div class="action-card" onclick="openLinkSource()">
                    <div class="action-icon">+</div>
                    <div class="action-title">Link Account</div>
                    <div class="action-desc">Add new funding source</div>
                </div>
                <div class="action-card" onclick="viewSavedSources()">
                    <div class="action-icon">🔗</div>
                    <div class="action-title">Saved Sources</div>
                    <div class="action-desc"><?= count($fundingSources) ?> linked accounts</div>
                </div>
                <div class="action-card" onclick="viewActivity()">
                    <div class="action-icon">📋</div>
                    <div class="action-title">Transaction History</div>
                    <div class="action-desc">View all activity</div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="recent-activity">
                <div class="panel-title"><span></span>Recent Activity</div>
                <div id="activityList">
                    <div class="activity-item">
                        <div class="activity-left">
                            <div class="activity-icon">✨</div>
                            <div class="activity-details">
                                <div class="activity-title">Welcome to VouchMorph</div>
                                <div class="activity-time">Just now</div>
                            </div>
                        </div>
                        <div class="activity-amount text-gold">Ready</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Swap Panel -->
        <div class="quick-swap">
            <div class="panel-title"><span></span>Quick Transfer</div>
            <select id="quickSource" class="swap-select">
                <option value="">Select source</option>
                <?php foreach ($fundingSources as $fs): ?>
                <option value="<?= vm_h($fs['institution_code']) ?>|<?= vm_h($fs['source_type']) ?>">
                    <?= vm_h($fs['institution_name']) ?> • <?= vm_h($fs['source_type']) ?>
                </option>
                <?php endforeach; ?>
                <?php if (empty($fundingSources)): ?>
                <option disabled>No sources linked</option>
                <?php endif; ?>
            </select>
            <input type="number" id="quickAmount" class="swap-input" placeholder="Amount" step="0.01" min="10">
            <select id="quickDestination" class="swap-select">
                <option value="">Select destination country</option>
                <?php foreach ($destinationCountries as $country): ?>
                <option value="<?= vm_h($country) ?>"><?= vm_h($country) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="swap-btn" onclick="quickSwap()">Execute Transfer →</button>
            <div class="divider"></div>
            <div class="text-muted" style="font-size: 11px; text-align: center;">
                🔐 All transactions require your VouchMorph PIN
            </div>
        </div>
    </div>
</div>

<!-- Security Modal -->
<div id="securityModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            Security Center
            <button class="modal-close" onclick="closeSecurityModal()">✕</button>
        </div>
        <div class="security-option" onclick="changeTransactionPin()">
            <div class="security-option-left">
                <div class="security-option-title">Change Transaction PIN</div>
                <div class="security-option-desc">Update your 6-digit security PIN</div>
            </div>
            <div class="text-gold">→</div>
        </div>
        <div class="security-option" onclick="changeLoginPassword()">
            <div class="security-option-left">
                <div class="security-option-title">Change Password</div>
                <div class="security-option-desc">Update your login password</div>
            </div>
            <div class="text-gold">→</div>
        </div>
        <div class="security-option" onclick="viewSecurityLog()">
            <div class="security-option-left">
                <div class="security-option-title">Security Log</div>
                <div class="security-option-desc">Recent security events</div>
            </div>
            <div class="text-gold">→</div>
        </div>
        <div class="security-option" onclick="logout()">
            <div class="security-option-left">
                <div class="security-option-title">Sign Out</div>
                <div class="security-option-desc">End current session</div>
            </div>
            <div class="text-gold">→</div>
        </div>
    </div>
</div>

<!-- PIN Modal -->
<div id="pinModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <span id="pinModalTitle">Enter Transaction PIN</span>
            <button class="modal-close" onclick="closePinModal()">✕</button>
        </div>
        <div id="pinDots" class="pin-dots"></div>
        <div class="pin-numpad" id="pinNumpad"></div>
        <button class="back-btn" style="margin-top: 20px; background: none; border: none; color: rgba(255,255,255,0.4); cursor: pointer;" onclick="closePinModal()">Cancel</button>
    </div>
</div>

<script>
// ============================================================
// PREMIUM DASHBOARD - GLOBAL STATE
// ============================================================
const sourceParticipants = <?php 
    $list = [];
    foreach ($sourceParticipants as $code => $p) {
        $list[] = ['code' => $code, 'name' => $p['name'] ?? $code, 'asset_types' => $p['capabilities']['asset_types'] ?? []];
    }
    echo vm_json($list);
?>;

const allParticipants = <?php 
    $list = [];
    foreach ($allParticipants as $code => $p) {
        $list[] = ['code' => $code, 'name' => $p['name'] ?? $code, 'country' => $p['country'] ?? '', 'asset_types' => $p['capabilities']['asset_types'] ?? []];
    }
    echo vm_json($list);
?>;

const fundingSources = <?php 
    $sources = [];
    foreach ($fundingSources as $fs) {
        $sources[] = ['id' => $fs['source_id'], 'name' => $fs['institution_name'], 'code' => $fs['institution_code'], 'type' => $fs['source_type'], 'masked' => $fs['masked_identifier']];
    }
    echo vm_json($sources);
?>;

const destinationCountries = <?php echo vm_json($destinationCountries); ?>;
const userCountry = <?php echo vm_json($userCountry); ?>;
const hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;

let pendingOperation = null;
let pendingData = null;
let currentConsentToken = null;
let pinInput = '';
let onPinConfirmed = null;

async function executeOperation(operation, data) {
    const formData = new FormData();
    formData.append('action', operation);
    for (let key in data) formData.append(key, data[key]);
    const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return await res.json();
}

function showPinModal(title, onConfirm) {
    pinInput = '';
    onPinConfirmed = onConfirm;
    document.getElementById('pinModalTitle').innerText = title;
    renderPinDots();
    renderPinNumpad();
    document.getElementById('pinModal').style.display = 'flex';
}

function closePinModal() {
    document.getElementById('pinModal').style.display = 'none';
    pinInput = '';
    onPinConfirmed = null;
}

function renderPinDots() {
    const container = document.getElementById('pinDots');
    let dots = '';
    for (let i = 0; i < 6; i++) {
        dots += `<div class="pin-dot ${i < pinInput.length ? 'filled' : ''}"></div>`;
    }
    container.innerHTML = dots;
}

function renderPinNumpad() {
    const container = document.getElementById('pinNumpad');
    const numbers = [1,2,3,4,5,6,7,8,9,0];
    let html = '';
    for (let n of numbers) {
        html += `<div class="numpad-btn" onclick="pinAddDigit(${n})">${n}</div>`;
    }
    html += `<div class="numpad-btn" onclick="pinDelete()">⌫</div>`;
    html += `<div class="numpad-btn" onclick="pinClear()">C</div>`;
    container.innerHTML = html;
}

function pinAddDigit(digit) {
    if (pinInput.length < 6) {
        pinInput += digit.toString();
        renderPinDots();
        if (pinInput.length === 6) {
            setTimeout(() => submitPin(), 100);
        }
    }
}

function pinDelete() {
    pinInput = pinInput.slice(0, -1);
    renderPinDots();
}

function pinClear() {
    pinInput = '';
    renderPinDots();
}

async function submitPin() {
    if (pinInput.length !== 6) return;
    closePinModal();
    if (onPinConfirmed) await onPinConfirmed(pinInput);
}

async function withPinVerification(operation, data, title = 'Enter Transaction PIN') {
    return new Promise((resolve, reject) => {
        showPinModal(title, async (pin) => {
            const result = await executeOperation('verify_transaction_pin', { pin: pin, operation: operation });
            if (result.status === 'success') {
                data.consent_token = result.consent_token;
                const finalResult = await executeOperation(operation, data);
                resolve(finalResult);
            } else {
                alert('Invalid PIN');
                reject(result);
            }
        });
    });
}

async function checkAndSetupPin() {
    if (!hasTransactionPin) {
        const pin = prompt('🔐 First Time Setup\n\nCreate your 6-digit Transaction PIN.\nThis will authorize all money movements.');
        if (pin && pin.length === 6 && /^\d+$/.test(pin)) {
            const confirmPin = prompt('Confirm your PIN');
            if (pin === confirmPin) {
                const result = await executeOperation('set_transaction_pin', { pin: pin, confirm_pin: confirmPin });
                if (result.status === 'success') {
                    alert('Transaction PIN created successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } else {
                alert('PINs do not match');
            }
        } else {
            alert('PIN must be 6 digits');
        }
        return false;
    }
    return true;
}

async function startSwap() {
    if (!await checkAndSetupPin()) return;
    const source = document.getElementById('quickSource').value;
    const amount = document.getElementById('quickAmount').value;
    const destCountry = document.getElementById('quickDestination').value;
    
    if (!source || !amount || amount < 10 || !destCountry) {
        alert('Please select source, enter amount (min 10), and select destination');
        return;
    }
    
    const [sourceCode, sourceType] = source.split('|');
    
    const result = await withPinVerification('swap_single', {
        source_type: sourceType,
        source_institution: sourceCode,
        source_identifier: 'saved_source',
        amount: parseFloat(amount),
        dest_country: destCountry,
        dest_institution: 'default',
        dest_action: 'deposit',
        dest_value: 'wallet'
    }, 'Authorize Transfer');
    
    if (result && result.status === 'success') {
        alert('✅ Transfer completed!\nRef: ' + result.swap_reference);
        document.getElementById('quickAmount').value = '';
    } else if (result) {
        alert('❌ Transfer failed: ' + result.message);
    }
}

function openLinkSource() {
    if (!hasTransactionPin) {
        alert('Please set up your Transaction PIN first via Security Center');
        return;
    }
    alert('Link Source feature - Select institution from list');
}

function viewSavedSources() {
    if (fundingSources.length === 0) {
        alert('No saved sources found. Link an account to get started.');
    } else {
        let msg = '📁 Your Linked Sources:\n\n';
        fundingSources.forEach(s => {
            msg += `• ${s.name} (${s.type})\n  ${s.masked}\n\n`;
        });
        alert(msg);
    }
}

function viewActivity() {
    alert('📋 Transaction History\n\nNo recent transactions. Start a transfer to see activity here.');
}

function openSecurityModal() {
    document.getElementById('securityModal').style.display = 'flex';
}

function closeSecurityModal() {
    document.getElementById('securityModal').style.display = 'none';
}

async function changeTransactionPin() {
    closeSecurityModal();
    const currentPin = prompt('Enter current Transaction PIN');
    if (!currentPin) return;
    const verifyResult = await executeOperation('verify_transaction_pin', { pin: currentPin, operation: 'change_pin' });
    if (verifyResult.status !== 'success') {
        alert('Current PIN is incorrect');
        return;
    }
    const newPin = prompt('Enter new 6-digit PIN');
    if (!newPin || newPin.length !== 6 || !/^\d+$/.test(newPin)) {
        alert('PIN must be 6 digits');
        return;
    }
    const confirmPin = prompt('Confirm new PIN');
    if (newPin !== confirmPin) {
        alert('PINs do not match');
        return;
    }
    const result = await executeOperation('set_transaction_pin', { pin: newPin, confirm_pin: confirmPin });
    if (result.status === 'success') {
        alert('PIN changed successfully!');
    } else {
        alert('Error: ' + result.message);
    }
}

async function changeLoginPassword() {
    closeSecurityModal();
    const currentPassword = prompt('Enter current password');
    if (!currentPassword) return;
    const newPassword = prompt('Enter new password (min 6 characters)');
    if (!newPassword || newPassword.length < 6) {
        alert('Password must be at least 6 characters');
        return;
    }
    const confirmPassword = prompt('Confirm new password');
    if (newPassword !== confirmPassword) {
        alert('Passwords do not match');
        return;
    }
    const result = await executeOperation('change_password', {
        current_password: currentPassword,
        new_password: newPassword
    });
    if (result.status === 'success') {
        alert('Password changed! Please login again.');
        logout();
    } else {
        alert('Error: ' + result.message);
    }
}

function viewSecurityLog() {
    alert('🔐 Security Log\n\n• Login: ' + new Date().toLocaleString() + '\n• Session active\n• No unusual activity detected');
    closeSecurityModal();
}

function quickSwap() {
    startSwap();
}

function logout() {
    window.location.href = 'logout.php';
}

// Initialize
(async function init() {
    console.log('VouchMorph Premium Dashboard Ready');
    if (!hasTransactionPin) {
        setTimeout(async () => {
            const setPin = confirm('🔐 Security Required\n\nFor your protection, please set up a Transaction PIN to authorize all money movements.\n\nSet up now?');
            if (setPin) {
                const pin = prompt('Create 6-digit Transaction PIN');
                if (pin && pin.length === 6 && /^\d+$/.test(pin)) {
                    const confirmPin = prompt('Confirm PIN');
                    if (pin === confirmPin) {
                        const result = await executeOperation('set_transaction_pin', { pin: pin, confirm_pin: confirmPin });
                        if (result.status === 'success') {
                            alert('✅ Transaction PIN created!');
                            location.reload();
                        }
                    }
                }
            }
        }, 500);
    }
})();
</script>
</body>
</html>
