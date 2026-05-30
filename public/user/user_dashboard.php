<?php
// public/user/user_dashboard.php
ob_start();

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    error_reporting(0);
    ini_set('display_errors', 0);
}

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
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$systemCountry = $user['country'] ?? 'BW';

$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

if (empty($dbConfig) || empty($dbConfig['host'])) {
    $dbConfig = null;
}

try {
    $db = DBConnection::getInstance($dbConfig);
    if (!$db) throw new \Exception("Failed to get database connection");
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_funding_sources (
            source_id BIGSERIAL PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            institution_code VARCHAR(100) NOT NULL,
            institution_name VARCHAR(150),
            institution_country VARCHAR(5) DEFAULT 'BW',
            source_type VARCHAR(30) NOT NULL,
            source_label VARCHAR(100),
            masked_identifier VARCHAR(100),
            encrypted_identifier TEXT,
            identifier_hash VARCHAR(255),
            linked_phone VARCHAR(30),
            is_default BOOLEAN DEFAULT FALSE,
            status VARCHAR(20) DEFAULT 'ACTIVE',
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(user_id, institution_code, identifier_hash)
        )
    ");
    
} catch (\Throwable $e) {
    error_log("USER DASHBOARD DB ERROR: " . $e->getMessage());
    die("System error");
}

function formatPhoneNumber($phoneNumber, $countryCode = 'BW') {
    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    $countryCodes = ['BW' => '267', 'ZA' => '27', 'NG' => '234', 'KE' => '254', 'GH' => '233'];
    $code = $countryCodes[$countryCode] ?? '267';
    if (empty($cleanNumber)) return '';
    if (substr($cleanNumber, 0, strlen($code)) === $code) return '+' . $cleanNumber;
    if (substr($cleanNumber, 0, 1) === '0') $cleanNumber = substr($cleanNumber, 1);
    return '+' . $code . $cleanNumber;
}

function maskIdentifier($value, $visible = 4) {
    $value = trim($value);
    if ($value === '') return '';
    $len = strlen($value);
    if ($len <= $visible) return str_repeat('*', $len);
    return str_repeat('*', $len - $visible) . substr($value, -$visible);
}

// Load participants
$stmt = $db->prepare("SELECT * FROM participants WHERE status = 'ACTIVE' ORDER BY name");
$stmt->execute();
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$participantConfig = [];
foreach ($participants as $p) {
    $participantConfig[$p['provider_code']] = $p;
}

// Load user's saved sources
$stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE' ORDER BY is_default DESC");
$stmt->execute([':user_id' => $userId]);
$fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load transactions for display
$stmt = $db->prepare("
    SELECT swap_uuid, amount, status, created_at, destination_details, source_details
    FROM swap_requests 
    WHERE CAST(metadata AS TEXT) LIKE :pattern 
    ORDER BY created_at DESC LIMIT 20
");
$stmt->execute([':pattern' => '%' . $userPhone . '%']);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$error = null;
$success = null;

// Handle AJAX requests
if ($isAjax && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'transactions') {
        echo json_encode(['success' => true, 'transactions' => $transactions]);
        exit;
    }
    
    if ($_GET['action'] === 'rates') {
        // Return exchange rates
        echo json_encode(['success' => true, 'rates' => ['USD' => 0.075, 'ZAR' => 1.35, 'EUR' => 0.069]]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'link_source') {
        try {
            $institutionCode = trim($_POST['institution_code'] ?? '');
            $sourceType = strtoupper(trim($_POST['source_type'] ?? ''));
            $identifier = trim($_POST['identifier'] ?? '');
            
            $participant = $participantConfig[$institutionCode] ?? null;
            if (!$participant) throw new \Exception("Institution not found");
            
            $identifierHash = hash('sha256', $identifier);
            $maskedId = maskIdentifier($identifier, 4);
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $encrypted = base64_encode(openssl_encrypt($identifier, 'AES-256-CBC', $encryptionKey, 0, substr($encryptionKey, 0, 16)));
            
            $stmt = $db->prepare("
                INSERT INTO user_funding_sources 
                (user_id, institution_code, institution_name, institution_country, source_type, 
                 masked_identifier, encrypted_identifier, identifier_hash, linked_phone)
                VALUES (:user_id, :inst_code, :inst_name, :inst_country, :type, 
                        :masked, :encrypted, :hash, :phone)
            ");
            
            $stmt->execute([
                ':user_id' => $userId,
                ':inst_code' => $institutionCode,
                ':inst_name' => $participant['name'],
                ':inst_country' => $participant['country_code'] ?? 'BW',
                ':type' => $sourceType,
                ':masked' => $maskedId,
                ':encrypted' => $encrypted,
                ':hash' => $identifierHash,
                ':phone' => $userPhone
            ]);
            
            $success = "Source linked successfully!";
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    if ($action === 'unlink_source') {
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $db->prepare("UPDATE user_funding_sources SET status = 'REMOVED' WHERE source_id = ? AND user_id = ?")
            ->execute([$sourceId, $userId]);
        $success = "Source removed";
    }
    
    if ($action === 'swap') {
        if ($isAjax) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json');
        }
        
        try {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $destinationInstitution = trim($_POST['dest_institution'] ?? '');
            $destinationType = trim($_POST['dest_type'] ?? 'deposit');
            $destinationValue = trim($_POST['dest_value'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $isInternational = isset($_POST['is_international']) && $_POST['is_international'] === '1';
            
            // Get source details
            $stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE source_id = ? AND user_id = ?");
            $stmt->execute([$sourceId, $userId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$source) throw new \Exception("Source not found");
            
            // Validate same institution + same asset type
            $destParticipant = $participantConfig[$destinationInstitution] ?? null;
            if ($source['institution_code'] === $destinationInstitution && !$isInternational) {
                if ($source['source_type'] === $destinationType) {
                    throw new \Exception("Cannot send to same institution with same asset type. Try sending to a wallet if you have an account, or use cross-border.");
                }
            }
            
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $decryptedIdentifier = openssl_decrypt(base64_decode($source['encrypted_identifier']), 'AES-256-CBC', $encryptionKey, 0, substr($encryptionKey, 0, 16));
            
            $sourcePayload = [
                'institution' => $source['institution_code'],
                'asset_type' => $source['source_type'],
                'amount' => $amount,
                'currency' => 'BWP'
            ];
            
            if ($source['source_type'] === 'ACCOUNT') {
                $sourcePayload['account_number'] = $decryptedIdentifier;
            } elseif ($source['source_type'] === 'CARD') {
                $sourcePayload['card_number'] = $decryptedIdentifier;
            } else {
                $sourcePayload['phone'] = formatPhoneNumber($decryptedIdentifier, $systemCountry);
            }
            
            $destinationDetails = [];
            if ($destinationType === 'cashout') {
                $destinationDetails = ['cashout' => ['beneficiary_phone' => $destinationValue]];
            } elseif ($destinationType === 'bank') {
                $destinationDetails = ['beneficiary_account' => $destinationValue];
            } else {
                $destinationDetails = ['beneficiary_wallet' => $destinationValue];
            }
            
            $swapPayload = [
                'source' => $sourcePayload,
                'destination' => array_merge([
                    'institution' => $destinationInstitution,
                    'delivery_mode' => $destinationType === 'cashout' ? 'cashout' : 'deposit',
                    'amount' => $amount,
                    'currency' => 'BWP'
                ], $destinationDetails),
                'metadata' => ['user_id' => $userId, 'user_phone' => $userPhone, 'is_international' => $isInternational]
            ];
            
            $countryConfigPath = __DIR__ . "/../../src/Core/Config/Countries/{$systemCountry}/config.php";
            $countryConfig = file_exists($countryConfigPath) ? require $countryConfigPath : [];
            
            $swapService = new SwapService($db, $countryConfig, $systemCountry, $encryptionKey, $participantConfig);
            $result = $swapService->executeSwap($swapPayload);
            
            if ($isAjax) {
                echo json_encode($result);
                exit;
            }
            
        } catch (\Exception $e) {
            if ($isAjax) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
            $error = $e->getMessage();
        }
    }
    
    if (!$isAjax) {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph – Send Money</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        background: #0a0a0f;
        font-family: 'Inter', sans-serif;
        color: #FFFFFF;
        min-height: 100vh;
    }
    
    /* Main layout */
    .app {
        max-width: 800px;
        margin: 0 auto;
        padding: 2rem 1.5rem;
    }
    
    /* Sharp edges - Porsche design */
    .card, button, input, select, .source-item, .tx-item {
        border-radius: 0 !important;
    }
    
    /* Header */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #00F0FF;
    }
    
    .logo h1 {
        font-family: 'Clash Display', sans-serif;
        font-size: 1.5rem;
        font-weight: 600;
        letter-spacing: -0.02em;
    }
    
    .logo p {
        font-size: 0.65rem;
        color: #666;
        margin-top: 0.1rem;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .phone {
        font-family: monospace;
        font-size: 0.8rem;
        color: #00F0FF;
        background: rgba(0,240,255,0.1);
        padding: 0.4rem 0.8rem;
        border: 1px solid rgba(0,240,255,0.3);
    }
    
    .logout {
        color: #666;
        text-decoration: none;
        font-size: 0.8rem;
    }
    
    .logout:hover { color: #FF3030; }
    
    /* Amount display */
    .amount-card {
        background: #0f0f15;
        border: 1px solid #222;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        text-align: center;
    }
    
    .amount-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #666;
        margin-bottom: 0.5rem;
    }
    
    .amount-input {
        width: 100%;
        background: transparent;
        border: none;
        color: #00F0FF;
        font-size: 3rem;
        font-weight: 700;
        text-align: center;
        font-family: monospace;
    }
    
    .amount-input:focus { outline: none; }
    
    .currency {
        font-size: 1rem;
        color: #666;
        margin-left: 0.5rem;
    }
    
    /* Source / Destination cards */
    .flow-card {
        background: #0f0f15;
        border: 1px solid #222;
        margin-bottom: 1rem;
        overflow: hidden;
    }
    
    .flow-header {
        padding: 1rem;
        background: #0a0a0f;
        border-bottom: 1px solid #222;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
    }
    
    .flow-header h3 {
        font-size: 0.85rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .flow-header i:first-child { color: #00F0FF; }
    .arrow-icon { color: #666; transition: transform 0.2s; }
    .flow-header.collapsed .arrow-icon { transform: rotate(180deg); }
    
    .flow-body { padding: 1rem; }
    .flow-body.collapsed { display: none; }
    
    /* Source selector */
    .sources-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }
    
    .source-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.75rem;
        background: #050505;
        border: 1px solid #222;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .source-item:hover { border-color: #00F0FF; }
    .source-item.selected { border: 2px solid #00F0FF; background: rgba(0,240,255,0.05); }
    
    .source-icon {
        width: 40px;
        height: 40px;
        background: rgba(0,240,255,0.1);
        display: flex;
    align-items: center;
        justify-content: center;
    }
    
    .source-icon i { font-size: 1.2rem; color: #00F0FF; }
    
    .source-details {
        flex: 1;
    }
    
    .source-name {
        font-weight: 600;
        font-size: 0.9rem;
    }
    
    .source-meta {
        font-size: 0.7rem;
        color: #666;
        margin-top: 0.2rem;
    }
    
    .source-badge {
        font-size: 0.6rem;
        padding: 0.2rem 0.4rem;
        background: rgba(0,240,255,0.15);
        color: #00F0FF;
    }
    
    /* Add source button */
    .add-btn {
        width: 100%;
        padding: 0.75rem;
        background: transparent;
        border: 1px dashed #333;
        color: #666;
        cursor: pointer;
        text-align: center;
        transition: all 0.2s;
    }
    
    .add-btn:hover {
        border-color: #00F0FF;
        color: #00F0FF;
    }
    
    /* Destination form */
    .dest-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .form-group {
        margin-bottom: 1rem;
    }
    
    .form-group label {
        display: block;
        font-size: 0.65rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #666;
        margin-bottom: 0.3rem;
    }
    
    select, .dest-input {
        width: 100%;
        padding: 0.75rem;
        background: #050505;
        border: 1px solid #222;
        color: #fff;
        font-size: 0.85rem;
    }
    
    select:focus, .dest-input:focus {
        outline: none;
        border-color: #00F0FF;
    }
    
    /* International toggle */
    .international-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem;
        background: #050505;
        border: 1px solid #222;
        margin-bottom: 1rem;
    }
    
    .toggle-label {
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .toggle-switch {
        width: 40px;
        height: 20px;
        background: #222;
        border: none;
        cursor: pointer;
        position: relative;
    }
    
    .toggle-switch.active {
        background: #00F0FF;
    }
    
    .toggle-switch::after {
        content: '';
        position: absolute;
        width: 16px;
        height: 16px;
        background: #fff;
        top: 2px;
        left: 2px;
        transition: left 0.2s;
    }
    
    .toggle-switch.active::after {
        left: 22px;
        background: #0a0a0f;
    }
    
    /* Rate info */
    .rate-info {
        font-size: 0.7rem;
        color: #FFC107;
        text-align: center;
        padding: 0.5rem;
        background: rgba(255,193,7,0.1);
        margin-bottom: 1rem;
    }
    
    /* Execute button */
    .execute-btn {
        width: 100%;
        padding: 1rem;
        background: #00F0FF;
        border: none;
        color: #0a0a0f;
        font-weight: 700;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: 1rem;
    }
    
    .execute-btn:hover {
        background: #B000FF;
        color: #fff;
    }
    
    .execute-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    /* Recent transactions */
    .tx-list {
        max-height: 300px;
        overflow-y: auto;
    }
    
    .tx-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        border-bottom: 1px solid #222;
    }
    
    .tx-date { font-size: 0.7rem; color: #666; }
    .tx-amount { font-weight: 700; color: #00F0FF; }
    .tx-status {
        font-size: 0.6rem;
        padding: 0.2rem 0.4rem;
        border: 1px solid;
    }
    .status-completed { border-color: #00F0FF; color: #00F0FF; }
    .status-pending { border-color: #FFC107; color: #FFC107; }
    .status-failed { border-color: #FF3030; color: #FF3030; }
    
    .empty-state {
        text-align: center;
        padding: 2rem;
        color: #666;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.95);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }
    
    .modal-content {
        background: #0f0f15;
        border: 2px solid #00F0FF;
        max-width: 450px;
        width: 90%;
        padding: 1.5rem;
    }
    
    .modal-content h3 {
        font-family: 'Clash Display';
        margin-bottom: 1rem;
    }
    
    .modal-buttons {
        display: flex;
        gap: 1rem;
        margin-top: 1rem;
    }
    
    .btn-primary {
        flex: 1;
        padding: 0.75rem;
        background: #00F0FF;
        border: none;
        color: #0a0a0f;
        font-weight: 600;
        cursor: pointer;
    }
    
    .btn-secondary {
        flex: 1;
        padding: 0.75rem;
        background: transparent;
        border: 1px solid #333;
        color: #ccc;
        cursor: pointer;
    }
    
    /* Alert */
    .alert {
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        border-left: 3px solid;
    }
    .alert-error { background: rgba(255,48,48,0.1); border-left-color: #FF3030; }
    .alert-success { background: rgba(0,240,255,0.1); border-left-color: #00F0FF; }
    
    /* Hidden */
    .hidden { display: none; }
    
    @media (max-width: 600px) {
        .app { padding: 1rem; }
        .dest-row { grid-template-columns: 1fr; }
        .header { flex-direction: column; text-align: center; gap: 1rem; }
    }
</style>
</head>
<body>

<div class="app">
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <h1>VOUCHMORPH</h1>
            <p>send · swap · settle</p>
        </div>
        <div class="user-info">
            <div class="phone"><i class="fas fa-mobile-alt"></i> <?= htmlspecialchars(substr($userPhone, -8)) ?></div>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Amount Card -->
    <div class="amount-card">
        <div class="amount-label">How much?</div>
        <input type="number" id="amount" class="amount-input" step="0.01" placeholder="0.00">
        <span class="currency">BWP</span>
    </div>

    <!-- Source Card -->
    <div class="flow-card">
        <div class="flow-header" onclick="toggleCard(this)">
            <h3><i class="fas fa-arrow-up"></i> From</h3>
            <i class="fas fa-chevron-down arrow-icon"></i>
        </div>
        <div class="flow-body">
            <div id="sourcesList" class="sources-list">
                <?php if (empty($fundingSources)): ?>
                    <div class="empty-state" style="padding: 1rem;">No saved sources. Add one below.</div>
                <?php else: ?>
                    <?php foreach ($fundingSources as $source): ?>
                        <div class="source-item" data-source-id="<?= $source['source_id'] ?>" 
                             data-institution="<?= htmlspecialchars($source['institution_code']) ?>"
                             data-type="<?= $source['source_type'] ?>"
                             data-country="<?= $source['institution_country'] ?>"
                             onclick="selectSource(this)">
                            <div class="source-icon">
                                <i class="<?= $source['source_type'] === 'ACCOUNT' ? 'fas fa-building' : ($source['source_type'] === 'CARD' ? 'fas fa-credit-card' : 'fas fa-mobile-alt') ?>"></i>
                            </div>
                            <div class="source-details">
                                <div class="source-name"><?= htmlspecialchars($source['institution_name']) ?></div>
                                <div class="source-meta">
                                    <?= $source['source_type'] ?> • <?= $source['masked_identifier'] ?>
                                    <?php if ($source['is_default']): ?><span class="source-badge">DEFAULT</span><?php endif; ?>
                                </div>
                            </div>
                            <i class="fas fa-check-circle" style="color: #00F0FF; display: none;"></i>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button class="add-btn" onclick="showLinkModal()">
                <i class="fas fa-plus"></i> Link new account or wallet
            </button>
        </div>
    </div>

    <!-- Destination Card -->
    <div class="flow-card">
        <div class="flow-header" onclick="toggleCard(this)">
            <h3><i class="fas fa-arrow-down"></i> To</h3>
            <i class="fas fa-chevron-down arrow-icon"></i>
        </div>
        <div class="flow-body">
            <div class="dest-row">
                <div class="form-group">
                    <label>Send to</label>
                    <select id="destType">
                        <option value="cashout">💰 Cashout (ATM)</option>
                        <option value="bank">🏦 Bank Account</option>
                        <option value="wallet">📱 Mobile Wallet</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Institution</label>
                    <select id="destInstitution">
                        <option value="">Select institution</option>
                        <?php foreach ($participants as $p): ?>
                            <option value="<?= htmlspecialchars($p['provider_code']) ?>" 
                                    data-country="<?= $p['country_code'] ?? 'BW' ?>">
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Destination details</label>
                <input type="text" id="destValue" class="dest-input" placeholder="Phone number or account number">
            </div>
            
            <!-- International toggle -->
            <div class="international-toggle">
                <div class="toggle-label">
                    <i class="fas fa-globe"></i> Send to different country
                </div>
                <button type="button" id="internationalToggle" class="toggle-switch" onclick="toggleInternational()"></button>
            </div>
            <div id="rateInfo" class="rate-info hidden">
                <i class="fas fa-chart-line"></i> <span id="rateText">Exchange rate applied</span>
            </div>
        </div>
    </div>

    <!-- Execute Button -->
    <button class="execute-btn" id="executeBtn" onclick="executeSwap()">
        <i class="fas fa-bolt"></i> SEND MONEY
    </button>

    <!-- Recent Transactions -->
    <div class="flow-card" style="margin-top: 1.5rem;">
        <div class="flow-header" onclick="toggleCard(this)">
            <h3><i class="fas fa-history"></i> Recent</h3>
            <i class="fas fa-chevron-down arrow-icon"></i>
        </div>
        <div class="flow-body">
            <div id="transactionsList" class="tx-list">
                <?php if (empty($transactions)): ?>
                    <div class="empty-state">No transactions yet</div>
                <?php else: ?>
                    <?php foreach (array_slice($transactions, 0, 5) as $tx): ?>
                        <div class="tx-item">
                            <div>
                                <div class="tx-date"><?= date('d M H:i', strtotime($tx['created_at'])) ?></div>
                                <div style="font-size:0.7rem;"><?= $tx['status'] ?></div>
                            </div>
                            <div class="tx-amount"><?= number_format($tx['amount'], 2) ?> BWP</div>
                            <div><span class="tx-status status-<?= strtolower($tx['status'] ?? 'pending') ?>"><?= $tx['status'] ?? 'PENDING' ?></span></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button class="add-btn" style="margin-top: 0.5rem;" onclick="loadMoreTransactions()">
                <i class="fas fa-refresh"></i> Load more
            </button>
        </div>
    </div>
</div>

<!-- Link Source Modal -->
<div id="linkModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-link"></i> Link new source</h3>
        <form method="POST" id="linkForm">
            <input type="hidden" name="action" value="link_source">
            <div class="form-group">
                <label>Institution</label>
                <select name="institution_code" class="form-select" required>
                    <?php foreach ($participants as $p): ?>
                        <option value="<?= htmlspecialchars($p['provider_code']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="source_type" required>
                    <option value="ACCOUNT">Bank Account</option>
                    <option value="WALLET">Mobile Wallet</option>
                    <option value="CARD">Card</option>
                </select>
            </div>
            <div class="form-group">
                <label>Account/Phone/Card number</label>
                <input type="text" name="identifier" class="dest-input" required>
            </div>
            <div class="modal-buttons">
                <button type="submit" class="btn-primary">Link</button>
                <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
let selectedSourceId = null;
let isInternational = false;

function toggleCard(header) {
    header.classList.toggle('collapsed');
    const body = header.nextElementSibling;
    body.classList.toggle('collapsed');
}

function selectSource(element) {
    // Remove selected from all
    document.querySelectorAll('.source-item').forEach(item => {
        item.classList.remove('selected');
        item.querySelector('.fa-check-circle')?.setAttribute('style', 'display: none');
    });
    
    // Add selected to current
    element.classList.add('selected');
    const checkIcon = element.querySelector('.fa-check-circle');
    if (checkIcon) checkIcon.setAttribute('style', 'display: block');
    
    selectedSourceId = element.dataset.sourceId;
    
    // Check if same institution as destination
    checkSameInstitution();
}

function checkSameInstitution() {
    if (!selectedSourceId) return;
    
    const sourceItem = document.querySelector(`.source-item[data-source-id="${selectedSourceId}"]`);
    const sourceInst = sourceItem?.dataset.institution;
    const destInst = document.getElementById('destInstitution').value;
    const sourceType = sourceItem?.dataset.type;
    const destType = document.getElementById('destType').value;
    
    if (sourceInst === destInst && !isInternational) {
        if (sourceType === destType || 
            (sourceType === 'ACCOUNT' && destType === 'bank') ||
            (sourceType === 'WALLET' && destType === 'wallet')) {
            document.getElementById('executeBtn').disabled = true;
            document.getElementById('executeBtn').style.opacity = '0.5';
            alert('Cannot send to same institution with same asset type. Use a different destination or enable international.');
        } else {
            document.getElementById('executeBtn').disabled = false;
            document.getElementById('executeBtn').style.opacity = '1';
        }
    } else {
        document.getElementById('executeBtn').disabled = false;
        document.getElementById('executeBtn').style.opacity = '1';
    }
}

function toggleInternational() {
    const toggle = document.getElementById('internationalToggle');
    isInternational = !isInternational;
    
    if (isInternational) {
        toggle.classList.add('active');
        document.getElementById('rateInfo').classList.remove('hidden');
        fetchRates();
    } else {
        toggle.classList.remove('active');
        document.getElementById('rateInfo').classList.add('hidden');
    }
    
    checkSameInstitution();
}

async function fetchRates() {
    const destCountry = document.getElementById('destInstitution').selectedOptions[0]?.dataset.country || 'BW';
    const sourceCountry = 'BW';
    
    if (sourceCountry !== destCountry) {
        document.getElementById('rateText').innerHTML = `🌍 Cross-border transfer • Rates apply from ${sourceCountry} to ${destCountry}`;
    } else {
        document.getElementById('rateText').innerHTML = `📍 Same country transfer • No exchange fees`;
    }
}

async function executeSwap() {
    if (!selectedSourceId) {
        alert('Please select a source');
        return;
    }
    
    const amount = parseFloat(document.getElementById('amount').value);
    if (!amount || amount <= 0) {
        alert('Please enter amount');
        return;
    }
    
    const destInstitution = document.getElementById('destInstitution').value;
    if (!destInstitution) {
        alert('Please select destination institution');
        return;
    }
    
    const destValue = document.getElementById('destValue').value;
    if (!destValue) {
        alert('Please enter destination details');
        return;
    }
    
    const destType = document.getElementById('destType').value;
    
    const formData = new FormData();
    formData.append('action', 'swap');
    formData.append('source_id', selectedSourceId);
    formData.append('amount', amount);
    formData.append('dest_institution', destInstitution);
    formData.append('dest_type', destType);
    formData.append('dest_value', destValue);
    formData.append('is_international', isInternational ? '1' : '0');
    
    const btn = document.getElementById('executeBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESSING...';
    btn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        if (data.status === 'success') {
            alert('✅ Money sent successfully!\nReference: ' + (data.swap_reference || 'OK'));
            location.reload();
        } else {
            alert('❌ Failed: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function showLinkModal() {
    document.getElementById('linkModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('linkModal').style.display = 'none';
}

async function loadMoreTransactions() {
    const container = document.getElementById('transactionsList');
    container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    try {
        const response = await fetch(window.location.href + '?action=transactions', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        if (data.success && data.transactions.length > 0) {
            let html = '';
            for (const tx of data.transactions) {
                const statusClass = (tx.status || 'pending').toLowerCase();
                html += `
                    <div class="tx-item">
                        <div>
                            <div class="tx-date">${new Date(tx.created_at).toLocaleDateString()} ${new Date(tx.created_at).toLocaleTimeString()}</div>
                            <div style="font-size:0.7rem;">${tx.status || 'PENDING'}</div>
                        </div>
                        <div class="tx-amount">${parseFloat(tx.amount).toFixed(2)} BWP</div>
                        <div><span class="tx-status status-${statusClass}">${tx.status || 'PENDING'}</span></div>
                    </div>
                `;
            }
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="empty-state">No transactions found</div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="empty-state">Failed to load</div>';
    }
}

// Event listeners
document.getElementById('destInstitution').addEventListener('change', () => {
    if (isInternational) fetchRates();
    checkSameInstitution();
});
document.getElementById('destType').addEventListener('change', checkSameInstitution);

// Handle form submission for linking
document.getElementById('linkForm')?.addEventListener('submit', function(e) {
    // Form submits normally - page will reload
});
</script>
</body>
</html>
