<?php
// public/user/user_dashboard.php - AVANT-GARDE ARCHITECTURAL DASHBOARD
// Designed for discerning tastes - Sharp edges, brutalist elegance

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

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    if ($isAjax) vm_json_response(['status' => 'error', 'message' => 'Database error']);
    die("System error");
}

if (empty($userPhone) && !empty($userId)) {
    try {
        $stmt = $db->prepare("SELECT phone, country, has_transaction_pin FROM users WHERE user_id = :user_id OR id = :user_id LIMIT 1");
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

function loadParticipantsFromJson($countryName) {
    $path = __DIR__ . "/../../src/Core/Config/Countries/{$countryName}/participants.json";
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    if ($content === false) return [];
    $data = json_decode($content, true);
    if (!is_array($data)) return [];
    $participants = $data['participants'] ?? [];
    foreach ($participants as $code => &$p) { $p['country'] = $countryName; if (!isset($p['name']) || empty($p['name'])) $p['name'] = $code; }
    return $participants;
}

function getCountryFolders() {
    $basePath = __DIR__ . "/../../src/Core/Config/Countries/";
    $folders = [];
    if (is_dir($basePath)) { foreach (scandir($basePath) as $item) { if ($item !== '.' && $item !== '..' && is_dir($basePath . $item)) $folders[] = $item; } }
    return $folders;
}

$sourceParticipants = loadParticipantsFromJson($userCountry);
$allParticipants = [];
$destinationCountries = [];
foreach (getCountryFolders() as $country) { foreach (loadParticipantsFromJson($country) as $code => $p) { $allParticipants[$code] = $p; $destinationCountries[$country] = true; } }
$destinationCountries = array_keys($destinationCountries);

$fundingSources = [];
$stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE'");
$stmt->execute([':user_id' => $userId]);
$fundingSources = $stmt->fetchAll(\PDO::FETCH_ASSOC);

function getAssetTypes($participant) { return $participant['capabilities']['asset_types'] ?? []; }
function userHasTransactionPin($db, $userId) { $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1"); $stmt->execute([':user_id' => $userId]); $result = $stmt->fetch(\PDO::FETCH_ASSOC); return !empty($result['transaction_pin_hash']); }
function verifyTransactionPin($db, $userId, $pin) { $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1"); $stmt->execute([':user_id' => $userId]); $result = $stmt->fetch(\PDO::FETCH_ASSOC); if (!$result || empty($result['transaction_pin_hash'])) return false; return password_verify($pin, $result['transaction_pin_hash']); }
function setTransactionPin($db, $userId, $pin) { $hash = password_hash($pin, PASSWORD_DEFAULT); $stmt = $db->prepare("UPDATE users SET transaction_pin_hash = :hash, has_transaction_pin = 1 WHERE user_id = :user_id"); return $stmt->execute([':hash' => $hash, ':user_id' => $userId]); }

if ($isAjax) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    if ($action === 'check_transaction_pin') { vm_json_response(['has_pin' => userHasTransactionPin($db, $userId)]); }
    if ($action === 'set_transaction_pin') {
        try { $pin = trim($_POST['pin'] ?? ''); $confirmPin = trim($_POST['confirm_pin'] ?? ''); if (strlen($pin) !== 6 || !ctype_digit($pin)) throw new Exception('PIN must be 6 digits'); if ($pin !== $confirmPin) throw new Exception('PINs do not match'); if (setTransactionPin($db, $userId, $pin)) { $user['has_transaction_pin'] = true; SessionManager::setUser($user); vm_json_response(['status' => 'success', 'message' => 'Transaction PIN set successfully']); } else throw new Exception('Failed to set PIN'); } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    if ($action === 'verify_transaction_pin') {
        try { $pin = trim($_POST['pin'] ?? ''); $operation = $_POST['operation'] ?? 'swap'; if (empty($pin)) throw new Exception('PIN is required'); if (!verifyTransactionPin($db, $userId, $pin)) throw new Exception('Invalid transaction PIN'); $consentToken = bin2hex(random_bytes(32)); $_SESSION['consent_token_' . $operation] = ['token' => $consentToken, 'expires' => time() + 300]; vm_json_response(['status' => 'success', 'message' => 'PIN verified', 'consent_token' => $consentToken]); } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    if ($action === 'change_password') {
        try { $currentPassword = $_POST['current_password'] ?? ''; $newPassword = $_POST['new_password'] ?? ''; $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = :user_id"); $stmt->execute([':user_id' => $userId]); $userData = $stmt->fetch(\PDO::FETCH_ASSOC); if (!password_verify($currentPassword, $userData['password_hash'])) throw new Exception('Current password is incorrect'); if (strlen($newPassword) < 6) throw new Exception('Password must be at least 6 characters'); $newHash = password_hash($newPassword, PASSWORD_DEFAULT); $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :user_id"); $stmt->execute([':hash' => $newHash, ':user_id' => $userId]); vm_json_response(['status' => 'success', 'message' => 'Password changed successfully']); } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    if ($action === 'get_destination_institutions') { $country = $_POST['country'] ?? $_GET['country'] ?? ''; $instList = []; foreach ($allParticipants as $code => $p) { if ($p['country'] === $country && ($p['status'] ?? 'ACTIVE') === 'ACTIVE') { $instList[] = ['code' => $code, 'name' => $p['name'] ?? $code, 'asset_types' => getAssetTypes($p)]; } } vm_json_response(['success' => true, 'institutions' => $instList]); }
    if ($action === 'save_source') {
        try { $consentToken = $_POST['consent_token'] ?? ''; $operation = 'save_source'; if (!isset($_SESSION['consent_token_' . $operation]) || $_SESSION['consent_token_' . $operation]['token'] !== $consentToken || $_SESSION['consent_token_' . $operation]['expires'] < time()) throw new Exception('Transaction authorization required or expired'); $institutionCode = trim($_POST['institution_code'] ?? ''); $assetType = strtoupper(trim($_POST['asset_type'] ?? '')); $identifier = trim($_POST['identifier'] ?? ''); $participant = $sourceParticipants[$institutionCode] ?? null; if (!$participant) throw new Exception("Institution not found"); $maskedId = strlen($identifier) > 4 ? '••••' . substr($identifier, -4) : '••••'; $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!'; $encrypted = base64_encode(openssl_encrypt($identifier, 'AES-256-CBC', $encryptionKey, 0, substr($encryptionKey, 0, 16))); $institutionName = $participant['name'] ?? $institutionCode; $stmt = $db->prepare("INSERT INTO user_funding_sources (user_id, institution_code, institution_name, institution_country, source_type, masked_identifier, encrypted_identifier, linked_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"); $stmt->execute([$userId, $institutionCode, $institutionName, $userCountry, $assetType, $maskedId, $encrypted, $userPhone]); unset($_SESSION['consent_token_' . $operation]); vm_json_response(['status' => 'success', 'message' => 'Source saved!']); } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    if ($action === 'swap_single') {
        try { $consentToken = $_POST['consent_token'] ?? ''; $operation = 'swap'; if (!isset($_SESSION['consent_token_' . $operation]) || $_SESSION['consent_token_' . $operation]['token'] !== $consentToken || $_SESSION['consent_token_' . $operation]['expires'] < time()) throw new Exception('Transaction authorization required or expired'); $amount = (float)($_POST['amount'] ?? 0); $destCountry = trim($_POST['dest_country'] ?? ''); $destInstitution = trim($_POST['dest_institution'] ?? ''); $destAction = trim($_POST['dest_action'] ?? ''); $destValue = trim($_POST['dest_value'] ?? ''); if ($amount < 10) throw new Exception('Minimum amount is 10.00'); $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His'); $withdrawalCode = $destAction === 'cashout' ? (string)random_int(100000, 999999) : null; error_log("SWAP EXECUTED: User $userId, Amount $amount, Ref $swapReference"); unset($_SESSION['consent_token_' . $operation]); vm_json_response(['status' => 'success', 'message' => $destAction === 'cashout' ? 'Withdrawal code generated' : 'Swap completed successfully', 'swap_reference' => $swapReference, 'withdrawal_code' => $withdrawalCode]); } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
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
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: #000000;
        font-family: 'Space Grotesk', monospace;
        color: #FFFFFF;
        letter-spacing: -0.02em;
        line-height: 1;
    }

    /* BRUTALIST ARCHITECTURAL GRID */
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

    /* LEFT RAIL - MINIMAL NAV */
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

    /* TOP BAR */
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

    /* MAIN CONTENT AREA */
    .main-content {
        grid-column: 2;
        grid-row: 2;
        overflow-y: auto;
        padding: 32px;
    }

    /* RIGHT PANEL - ACTION AREA */
    .action-panel {
        grid-column: 3;
        grid-row: 2;
        border-left: 1px solid rgba(255,255,255,0.08);
        overflow-y: auto;
        background: #000000;
    }

    /* CORE TYPOGRAPHY */
    h1 {
        font-size: 48px;
        font-weight: 500;
        letter-spacing: -0.03em;
        margin-bottom: 8px;
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

    /* OPTION GRID - BUTTONS APPEAR ON CLICK */
    .option-grid {
        display: none;
        grid-template-columns: 1fr 1fr;
        gap: 1px;
        background: rgba(255,255,255,0.08);
        margin-bottom: 48px;
    }

    .option-grid.active {
        display: grid;
    }

    .option-btn {
        background: #000000;
        padding: 32px 24px;
        border: none;
        text-align: left;
        cursor: pointer;
        transition: all 0.1s ease;
    }

    .option-btn:hover {
        background: #FFFFFF;
    }

    .option-btn:hover .option-title,
    .option-btn:hover .option-desc {
        color: #000000;
    }

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

    /* TRIGGER BUTTON - SHARP, THIN */
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

    /* SECONDARY BUTTONS */
    .sec-btn {
        width: 100%;
        background: transparent;
        border: 1px solid rgba(255,255,255,0.08);
        padding: 16px 20px;
        font-family: 'Space Grotesk', monospace;
        font-size: 11px;
        font-weight: 400;
        letter-spacing: 1px;
        color: rgba(255,255,255,0.6);
        cursor: pointer;
        text-align: left;
        transition: all 0.1s ease;
        margin-bottom: 1px;
    }

    .sec-btn:hover {
        border-color: #FFFFFF;
        color: #FFFFFF;
    }

    /* PIN MODAL - BRUTALIST */
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
        width: 360px;
        text-align: center;
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

    /* RIGHT PANEL CONTENT */
    .panel-section {
        padding: 32px;
        border-bottom: 1px solid rgba(255,255,255,0.06);
    }

    .panel-label {
        font-size: 9px;
        letter-spacing: 2px;
        color: rgba(255,255,255,0.2);
        text-transform: uppercase;
        margin-bottom: 16px;
    }

    .source-item {
        padding: 12px 0;
        border-bottom: 1px solid rgba(255,255,255,0.04);
        font-size: 12px;
        display: flex;
        justify-content: space-between;
    }

    .source-name {
        font-weight: 400;
    }

    .source-type {
        color: rgba(255,255,255,0.3);
        font-size: 10px;
    }

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

    .swap-input:focus {
        outline: none;
        border-color: #FFFFFF;
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

    .swap-select option {
        background: #000000;
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

    .execute-btn:hover {
        opacity: 0.9;
    }

    /* SCROLLBAR */
    ::-webkit-scrollbar {
        width: 0;
        background: transparent;
    }

    /* RESPONSIVE */
    @media (max-width: 1024px) {
        .app {
            grid-template-columns: 60px 1fr;
        }
        .action-panel {
            position: fixed;
            right: -100%;
            width: 100%;
            max-width: 400px;
            transition: right 0.2s ease;
            z-index: 100;
        }
        .action-panel.open {
            right: 0;
        }
    }
</style>
</head>
<body>

<div class="app">
    <!-- LEFT NAVIGATION -->
    <div class="nav-rail">
        <div class="nav-logo">VOUCHMORPH</div>
        <div class="nav-bottom">ARCHITECT v1.0</div>
    </div>

    <!-- TOP BAR -->
    <div class="top-bar">
        <div class="top-stat">COUNTRY <strong><?= vm_h($userCountry) ?></strong></div>
        <div class="top-stat">SOURCES <strong><?= count($fundingSources) ?></strong></div>
        <div class="user-badge" id="userBadge"><?= vm_h(strtoupper(substr($userPhone, -4))) ?></div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="stat-block">
            <div class="stat-label">AVAILABLE BALANCE</div>
            <h1>0.00 <span style="font-size: 20px; opacity: 0.3;">USD</span></h1>
        </div>

        <!-- TRIGGER BUTTONS - CLICK TO SHOW OPTIONS -->
        <button class="trigger-btn" id="swapTrigger">⟡ INITIATE TRANSFER</button>
        <button class="trigger-btn" id="sourceTrigger">⟡ MANAGE SOURCES</button>
        <button class="trigger-btn" id="securityTrigger">⟡ SECURITY</button>

        <!-- OPTION GRIDS - APPEAR ON BUTTON CLICK -->
        <div id="swapOptions" class="option-grid">
            <button class="option-btn" onclick="startSwap('single')">
                <div class="option-title">SINGLE</div>
                <div class="option-desc">One source → destination</div>
            </button>
            <button class="option-btn" onclick="startMultiSource()">
                <div class="option-title">MULTI-SOURCE</div>
                <div class="option-desc">Combine balances</div>
            </button>
            <button class="option-btn" onclick="startCashout()">
                <div class="option-title">CASEOUT</div>
                <div class="option-desc">ATM / Agent withdrawal</div>
            </button>
            <button class="option-btn" onclick="startRecurring()">
                <div class="option-title">RECURRING</div>
                <div class="option-desc">Schedule transfers</div>
            </button>
        </div>

        <div id="sourceOptions" class="option-grid">
            <button class="option-btn" onclick="linkNewSource()">
                <div class="option-title">LINK NEW</div>
                <div class="option-desc">Connect institution</div>
            </button>
            <button class="option-btn" onclick="viewLinkedSources()">
                <div class="option-title">VIEW ALL</div>
                <div class="option-desc"><?= count($fundingSources) ?> connected</div>
            </button>
            <button class="option-btn" onclick="manageTokens()">
                <div class="option-title">CONSENT TOKENS</div>
                <div class="option-desc">Active authorizations</div>
            </button>
        </div>

        <div id="securityOptions" class="option-grid">
            <button class="option-btn" onclick="changePin()">
                <div class="option-title">CHANGE PIN</div>
                <div class="option-desc">Update transaction PIN</div>
            </button>
            <button class="option-btn" onclick="changePassword()">
                <div class="option-title">CHANGE PASSWORD</div>
                <div class="option-desc">Update login credentials</div>
            </button>
            <button class="option-btn" onclick="viewSession()">
                <div class="option-title">ACTIVE SESSION</div>
                <div class="option-desc">Device management</div>
            </button>
            <button class="option-btn" onclick="logout()">
                <div class="option-title">TERMINATE</div>
                <div class="option-desc">End session</div>
            </button>
        </div>
    </div>

    <!-- RIGHT ACTION PANEL -->
    <div class="action-panel" id="actionPanel">
        <div style="padding: 32px;">
            <div class="panel-label">LINKED SOURCES</div>
            <div id="sourcesList">
                <?php if (empty($fundingSources)): ?>
                <div class="source-item">
                    <span class="source-name">— no sources —</span>
                </div>
                <?php else: ?>
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
            <select id="quickSource" class="swap-select">
                <option value="">Select source</option>
                <?php foreach ($fundingSources as $fs): ?>
                <option value="<?= vm_h($fs['institution_code']) ?>|<?= vm_h($fs['source_type']) ?>">
                    <?= vm_h($fs['institution_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="swap-row">
                <input type="number" id="quickAmount" class="swap-input" placeholder="AMOUNT" step="0.01" min="10">
                <select id="quickCurrency" class="swap-input" style="width: 80px;">
                    <option>USD</option>
                    <option>EUR</option>
                    <option>GBP</option>
                </select>
            </div>
            <select id="quickDestCountry" class="swap-select">
                <option value="">Destination country</option>
                <?php foreach ($destinationCountries as $country): ?>
                <option value="<?= vm_h($country) ?>"><?= vm_h($country) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="execute-btn" onclick="executeQuickSwap()">EXECUTE →</button>
        </div>
    </div>
</div>

<!-- PIN MODAL -->
<div id="pinModal" class="modal-overlay">
    <div class="modal">
        <div id="pinDots" class="pin-dots"></div>
        <div id="pinNumpad" class="pin-numpad"></div>
        <button class="modal-close" onclick="closePinModal()">CANCEL</button>
    </div>
</div>

<script>
// ============================================================
// ARCHITECTURAL DASHBOARD - SHARP, MINIMAL, PRECISE
// ============================================================
const hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;
const fundingSources = <?php 
    $sources = [];
    foreach ($fundingSources as $fs) {
        $sources[] = ['code' => $fs['institution_code'], 'type' => $fs['source_type'], 'name' => $fs['institution_name']];
    }
    echo vm_json($sources);
?>;
const destinationCountries = <?php echo vm_json($destinationCountries); ?>;

let pendingCallback = null;
let pinInput = '';

// OPTION TOGGLES - BUTTONS APPEAR ONLY WHEN TRIGGER CLICKED
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

// User badge - toggle right panel on mobile
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

// PIN MODAL
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
    const nums = [1,2,3,4,5,6,7,8,9,0];
    let html = '';
    nums.forEach(n => { html += `<button class="numpad-btn" onclick="pinAdd(${n})">${n}</button>`; });
    html += `<button class="numpad-btn" onclick="pinDelete()">⌫</button>`;
    html += `<button class="numpad-btn" onclick="pinClear()">CLR</button>`;
    container.innerHTML = html;
}

function pinAdd(d) { if (pinInput.length < 6) { pinInput += d.toString(); renderPinDots(); if (pinInput.length === 6) submitPin(); } }
function pinDelete() { pinInput = pinInput.slice(0, -1); renderPinDots(); }
function pinClear() { pinInput = ''; renderPinDots(); }

function showPinModal(callback) {
    pinInput = '';
    pendingCallback = callback;
    renderPinDots();
    renderPinNumpad();
    document.getElementById('pinModal').style.display = 'flex';
}

function closePinModal() {
    document.getElementById('pinModal').style.display = 'none';
    pinInput = '';
    pendingCallback = null;
}

async function submitPin() {
    if (pinInput.length !== 6) return;
    const pin = pinInput;
    closePinModal();
    if (pendingCallback) await pendingCallback(pin);
}

async function withPinVerification(operation, data, callback) {
    showPinModal(async (pin) => {
        const verify = await executeOperation('verify_transaction_pin', { pin: pin, operation: operation });
        if (verify.status === 'success') {
            data.consent_token = verify.consent_token;
            const result = await executeOperation(operation, data);
            if (callback) callback(result);
        } else {
            alert('INVALID PIN');
        }
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

async function startSwap() { if (!await checkAndSetupPin()) return; alert('Select source and amount in right panel →'); }
async function startMultiSource() { if (!await checkAndSetupPin()) return; alert('Multi-source swap coming soon'); }
async function startCashout() { if (!await checkAndSetupPin()) return; alert('Cashout feature - select withdrawal method'); }
async function startRecurring() { if (!await checkAndSetupPin()) return; alert('Recurring transfers - schedule upcoming'); }
async function linkNewSource() { if (!await checkAndSetupPin()) return; alert('Link source - institution selection'); }
function viewLinkedSources() { if (fundingSources.length === 0) alert('No sources linked'); else { let msg = 'SOURCES:\n'; fundingSources.forEach(s => { msg += `• ${s.name} (${s.type})\n`; }); alert(msg); } }
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
        if (result.status === 'success') alert(`✓ COMPLETED\nREF: ${result.swap_reference}`);
        else alert(`✗ FAILED\n${result.message}`);
    });
}

// Auto-close option grids when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.trigger-btn') && !e.target.closest('.option-grid')) {
        document.getElementById('swapOptions').classList.remove('active');
        document.getElementById('sourceOptions').classList.remove('active');
        document.getElementById('securityOptions').classList.remove('active');
    }
});
</script>
</body>
</html>
