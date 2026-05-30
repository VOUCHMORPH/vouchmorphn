<?php
// public/user/user_dashboard.php
ob_start();

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

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    die("System error");
}

// Load participants from database (synced with participants.json)
$stmt = $db->prepare("SELECT * FROM participants WHERE status = 'ACTIVE'");
$stmt->execute();
$dbParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build complete participant config with asset types
$participants = [];
foreach ($dbParticipants as $p) {
    $caps = json_decode($p['capabilities'] ?? '{}', true);
    $walletTypes = $caps['wallet_types'] ?? [];
    
    $participants[$p['provider_code']] = [
        'name' => $p['name'],
        'provider_code' => $p['provider_code'],
        'type' => $p['type'],
        'category' => $p['category'],
        'country_code' => $p['country_code'] ?? 'BW',
        'wallet_types' => $walletTypes,
        'base_url' => $p['base_url'],
        'status' => $p['status']
    ];
}

// Load user's saved sources
$stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE'");
$stmt->execute([':user_id' => $userId]);
$fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper to get asset type icon
function getAssetIcon($type) {
    return match(strtoupper($type)) {
        'ACCOUNT' => '🏦',
        'WALLET' => '📱',
        'E-WALLET' => '📱',
        'CARD' => '💳',
        'VOUCHER' => '🎫',
        'ATM' => '🏧',
        default => '💰'
    };
}

// Handle AJAX
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'swap') {
        try {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $sourceAssetType = $_POST['source_asset_type'] ?? '';
            $destInstitution = trim($_POST['dest_institution'] ?? '');
            $destAssetType = $_POST['dest_asset_type'] ?? '';
            $destValue = trim($_POST['dest_value'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $isCrossBorder = $_POST['is_cross_border'] ?? '0';
            
            // Get source
            $stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE source_id = ? AND user_id = ?");
            $stmt->execute([$sourceId, $userId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$source) throw new Exception("Source not found");
            
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $decryptedIdentifier = openssl_decrypt(
                base64_decode($source['encrypted_identifier']), 
                'AES-256-CBC', 
                $encryptionKey, 
                0, 
                substr($encryptionKey, 0, 16)
            );
            
            $sourcePayload = [
                'institution' => $source['institution_code'],
                'asset_type' => $sourceAssetType,
                'amount' => $amount,
                'currency' => 'BWP'
            ];
            
            if ($sourceAssetType === 'ACCOUNT') {
                $sourcePayload['account_number'] = $decryptedIdentifier;
            } elseif ($sourceAssetType === 'CARD') {
                $sourcePayload['card_number'] = $decryptedIdentifier;
            } else {
                $sourcePayload['phone'] = $decryptedIdentifier;
            }
            
            $destinationDetails = [];
            if ($destAssetType === 'CASHOUT') {
                $destinationDetails = ['cashout' => ['beneficiary_phone' => $destValue]];
                $deliveryMode = 'cashout';
            } elseif ($destAssetType === 'ACCOUNT') {
                $destinationDetails = ['beneficiary_account' => $destValue];
                $deliveryMode = 'deposit';
            } else {
                $destinationDetails = ['beneficiary_wallet' => $destValue];
                $deliveryMode = 'deposit';
            }
            
            $swapPayload = [
                'source' => $sourcePayload,
                'destination' => array_merge([
                    'institution' => $destInstitution,
                    'delivery_mode' => $deliveryMode,
                    'amount' => $amount,
                    'currency' => 'BWP'
                ], $destinationDetails),
                'metadata' => [
                    'user_id' => $userId,
                    'is_cross_border' => $isCrossBorder === '1',
                    'source_country' => $source['institution_country'] ?? 'BW',
                    'dest_country' => $participants[$destInstitution]['country_code'] ?? 'BW'
                ]
            ];
            
            $countryConfigPath = __DIR__ . "/../../src/Core/Config/Countries/{$systemCountry}/config.php";
            $countryConfig = file_exists($countryConfigPath) ? require $countryConfigPath : [];
            
            $swapService = new SwapService($db, $countryConfig, $systemCountry, $encryptionKey, $participants);
            $result = $swapService->executeSwap($swapPayload);
            
            echo json_encode($result);
            exit;
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    if ($action === 'link_source') {
        try {
            $institutionCode = trim($_POST['institution_code'] ?? '');
            $assetType = strtoupper(trim($_POST['asset_type'] ?? ''));
            $identifier = trim($_POST['identifier'] ?? '');
            
            $participant = $participants[$institutionCode] ?? null;
            if (!$participant) throw new Exception("Institution not found");
            
            // Validate asset type is supported
            if (!in_array($assetType, $participant['wallet_types'])) {
                throw new Exception("Institution does not support {$assetType}");
            }
            
            $identifierHash = hash('sha256', $identifier);
            $maskedId = strlen($identifier) > 4 ? '••••' . substr($identifier, -4) : '••••';
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $encrypted = base64_encode(openssl_encrypt($identifier, 'AES-256-CBC', $encryptionKey, 0, substr($encryptionKey, 0, 16)));
            
            $stmt = $db->prepare("
                INSERT INTO user_funding_sources 
                (user_id, institution_code, institution_name, institution_country, source_type, 
                 masked_identifier, encrypted_identifier, identifier_hash, linked_phone)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $institutionCode,
                $participant['name'],
                $participant['country_code'],
                $assetType,
                $maskedId,
                $encrypted,
                $identifierHash,
                $userPhone
            ]);
            
            echo json_encode(['status' => 'success', 'message' => 'Source linked successfully']);
            exit;
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    if ($action === 'get_asset_types') {
        $institutionCode = $_GET['institution'] ?? '';
        $inst = $participants[$institutionCode] ?? null;
        if ($inst) {
            echo json_encode(['success' => true, 'wallet_types' => $inst['wallet_types']]);
        } else {
            echo json_encode(['success' => false, 'wallet_types' => []]);
        }
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph | eChenchela Ha!</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
        color: #ffffff;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }
    
    .ussd-container {
        max-width: 420px;
        width: 100%;
        background: #0a0a0f;
        border: 1px solid #222;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        overflow: hidden;
    }
    
    .ussd-header {
        background: #00F0FF;
        padding: 16px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .ussd-header h1 {
        font-size: 16px;
        font-weight: 700;
        color: #0a0a0f;
        letter-spacing: -0.5px;
    }
    
    .ussd-header .tagline {
        font-size: 10px;
        font-weight: 500;
        color: #0a0a0f;
        background: rgba(10, 10, 15, 0.2);
        padding: 4px 8px;
    }
    
    .ussd-screen {
        min-height: 400px;
        padding: 24px 20px;
        background: #0f0f15;
        border-bottom: 1px solid #222;
    }
    
    .question {
        font-size: 18px;
        font-weight: 600;
        line-height: 1.4;
        margin-bottom: 24px;
    }
    
    .options {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .option-btn {
        background: transparent;
        border: 1px solid #333;
        padding: 14px 16px;
        text-align: left;
        color: #ffffff;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .option-btn:hover {
        border-color: #00F0FF;
        background: rgba(0, 240, 255, 0.05);
    }
    
    .option-btn .icon {
        font-size: 18px;
        margin-right: 12px;
    }
    
    .back-btn {
        margin-top: 20px;
        background: transparent;
        border: none;
        color: #666;
        font-size: 13px;
        cursor: pointer;
        padding: 8px 0;
        text-align: center;
        width: 100%;
    }
    
    .back-btn:hover {
        color: #00F0FF;
    }
    
    .input-group {
        margin-top: 16px;
    }
    
    .ussd-input, .ussd-select {
        width: 100%;
        padding: 14px 16px;
        background: #050505;
        border: 1px solid #333;
        color: #00F0FF;
        font-size: 16px;
        font-family: monospace;
        margin-bottom: 12px;
    }
    
    .ussd-select {
        color: #fff;
        font-family: 'Inter';
    }
    
    .ussd-input:focus, .ussd-select:focus {
        outline: none;
        border-color: #00F0FF;
    }
    
    .submit-btn {
        width: 100%;
        margin-top: 8px;
        padding: 14px;
        background: #00F0FF;
        border: none;
        color: #0a0a0f;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
    }
    
    .submit-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .keypad-hint {
        margin-top: 16px;
        font-size: 11px;
        color: #444;
        text-align: center;
    }
    
    .ussd-footer {
        padding: 12px 20px;
        background: #050505;
        border-top: 1px solid #222;
        font-size: 10px;
        color: #444;
        text-align: center;
        display: flex;
        justify-content: space-between;
    }
    
    .loading {
        text-align: center;
        padding: 40px 20px;
    }
    
    .spinner {
        width: 32px;
        height: 32px;
        border: 2px solid #333;
        border-top-color: #00F0FF;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 12px;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    .result-screen {
        text-align: center;
    }
    
    .result-icon {
        font-size: 48px;
        margin-bottom: 16px;
    }
    
    .result-icon.success { color: #00F0FF; }
    .result-icon.error { color: #FF3030; }
    
    .result-message {
        font-size: 14px;
        color: #888;
        margin-top: 8px;
    }
    
    .done-btn {
        margin-top: 24px;
        padding: 14px;
        background: #00F0FF;
        border: none;
        color: #0a0a0f;
        font-weight: 700;
        cursor: pointer;
        width: 100%;
    }
    
    .info-text {
        font-size: 12px;
        color: #00F0FF;
        margin-top: 8px;
        text-align: center;
    }
    
    .hidden {
        display: none;
    }
    
    .country-badge {
        font-size: 10px;
        color: #888;
        margin-left: 8px;
    }
</style>
</head>
<body>

<div class="ussd-container">
    <div class="ussd-header">
        <h1><i class="fas fa-bolt"></i> VOUCHMORPH</h1>
        <div class="tagline">🇧🇼 eChenchela Ha! • Send Money</div>
    </div>
    
    <div id="screen" class="ussd-screen">
        <div class="loading">
            <div class="spinner"></div>
            <div>Loading...</div>
        </div>
    </div>
    
    <div class="ussd-footer">
        <span>Secured by VouchMorph</span>
        <span><i class="fas fa-shield-alt"></i> ISO 27001</span>
    </div>
</div>

<script>
// Participants data from PHP
const participants = <?php 
    $list = [];
    foreach ($participants as $code => $p) {
        $list[] = [
            'code' => $code,
            'name' => $p['name'],
            'country' => $p['country_code'],
            'wallet_types' => $p['wallet_types']
        ];
    }
    echo json_encode($list);
?>;

// Funding sources
const fundingSources = <?php 
    $sources = [];
    foreach ($fundingSources as $fs) {
        $sources[] = [
            'id' => $fs['source_id'],
            'name' => $fs['institution_name'],
            'code' => $fs['institution_code'],
            'type' => $fs['source_type'],
            'masked' => $fs['masked_identifier'],
            'country' => $fs['institution_country'] ?? 'BW'
        ];
    }
    echo json_encode($sources);
?>;

// Country slogans
const slogans = {
    'BW': '🇧🇼 eChenchela Ha!',
    'ZA': '🇿🇦 Yenza Inzinto!',
    'NG': '🇳🇬 Gbanja!',
    'KE': '🇰🇪 Chapaa!',
    'GH': '🇬🇭 Faako!'
};

// State machine
let state = {
    step: 'init',
    data: {
        source_id: null,
        source_code: null,
        source_asset: null,
        source_country: null,
        amount: null,
        dest_institution: null,
        dest_asset: null,
        dest_country: null,
        dest_value: null,
        is_cross_border: false
    }
};

// Get country display
function getCountryDisplay(code) {
    const flag = code === 'BW' ? '🇧🇼' : code === 'ZA' ? '🇿🇦' : code === 'NG' ? '🇳🇬' : '🌍';
    return `${flag} ${code}`;
}

// Get institution by code
function getInstitution(code) {
    return participants.find(p => p.code === code);
}

// Render screen
function render() {
    const screen = document.getElementById('screen');
    let html = '';
    
    switch(state.step) {
        case 'init':
            html = `
                <div class="question">📱 Choose service</div>
                <div class="options">
                    <button class="option-btn" onclick="goTo('select_source')">
                        <span><span class="icon">💰</span> Send Money</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <button class="option-btn" onclick="goTo('link_source')">
                        <span><span class="icon">🔗</span> Link Account/Wallet</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    <button class="option-btn" onclick="goTo('history')">
                        <span><span class="icon">📜</span> Recent Transactions</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div class="info-text">🇧🇼 ${slogans['BW'] || 'Send Money Anywhere'}</div>
            `;
            break;
            
        case 'select_source':
            if (fundingSources.length === 0) {
                html = `
                    <div class="question">No saved sources</div>
                    <div class="options">
                        <button class="option-btn" onclick="goTo('link_source')">
                            Link New Account <i class="fas fa-plus"></i>
                        </button>
                        <button class="option-btn" onclick="goTo('init')">
                            Back <i class="fas fa-arrow-left"></i>
                        </button>
                    </div>
                `;
            } else {
                let optionsHtml = '';
                fundingSources.forEach(source => {
                    const icon = source.type === 'ACCOUNT' ? '🏦' : (source.type === 'CARD' ? '💳' : '📱');
                    optionsHtml += `
                        <button class="option-btn" onclick="selectSource(${source.id}, '${source.code}', '${source.type}', '${source.country}')">
                            <span>${icon} ${source.name} (${source.masked})</span>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    `;
                });
                optionsHtml += `
                    <button class="option-btn" onclick="goTo('link_source')">
                        <span>🔗 Link New Source</span>
                        <i class="fas fa-plus"></i>
                    </button>
                    <button class="option-btn" onclick="goTo('init')">
                        <span>← Back</span>
                    </button>
                `;
                html = `
                    <div class="question">Select source</div>
                    <div class="options">${optionsHtml}</div>
                `;
            }
            break;
            
        case 'select_source_asset':
            const sourceInst = getInstitution(state.data.source_code);
            const assetOptions = sourceInst?.wallet_types || [];
            
            if (assetOptions.length === 0) {
                html = `<div class="question">No asset types available</div><button class="back-btn" onclick="goTo('select_source')">Back</button>`;
            } else {
                let assetHtml = '';
                assetOptions.forEach(asset => {
                    const icon = asset === 'ACCOUNT' ? '🏦' : (asset === 'CARD' ? '💳' : (asset === 'VOUCHER' ? '🎫' : '📱'));
                    assetHtml += `
                        <button class="option-btn" onclick="selectSourceAsset('${asset}')">
                            <span>${icon} ${asset}</span>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    `;
                });
                assetHtml += `<button class="back-btn" onclick="goTo('select_source')">← Back</button>`;
                html = `
                    <div class="question">Select asset type at ${sourceInst?.name}</div>
                    <div class="options">${assetHtml}</div>
                `;
            }
            break;
            
        case 'enter_amount':
            html = `
                <div class="question">💰 Enter amount (BWP)</div>
                <div class="input-group">
                    <input type="number" id="amountInput" class="ussd-input" placeholder="0.00" step="0.01" min="10" autofocus>
                    <button class="submit-btn" onclick="submitAmount()">Continue</button>
                </div>
                <button class="back-btn" onclick="goTo('select_source_asset')">← Back</button>
                <div class="keypad-hint">Minimum BWP 10.00</div>
            `;
            break;
            
        case 'select_destination':
            let destHtml = '<div class="question">Select destination institution</div><div class="options">';
            participants.forEach(p => {
                const countryFlag = p.country === 'BW' ? '🇧🇼' : (p.country === 'ZA' ? '🇿🇦' : '🌍');
                destHtml += `
                    <button class="option-btn" onclick="selectDestination('${p.code}', '${p.country}')">
                        <span>${countryFlag} ${p.name}</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                `;
            });
            destHtml += `<button class="back-btn" onclick="goTo('enter_amount')">← Back</button></div>`;
            html = destHtml;
            break;
            
        case 'select_destination_asset':
            const destInst = getInstitution(state.data.dest_institution);
            const destAssetOptions = destInst?.wallet_types || [];
            // Add CASHOUT option for ATM withdrawals
            const allOptions = [...destAssetOptions];
            if (!allOptions.includes('CASHOUT')) allOptions.push('CASHOUT');
            
            let assetHtml = '<div class="question">Select receiving method</div><div class="options">';
            allOptions.forEach(asset => {
                const icon = asset === 'ACCOUNT' ? '🏦' : (asset === 'CARD' ? '💳' : (asset === 'CASHOUT' ? '💰' : '📱'));
                const label = asset === 'CASHOUT' ? 'Cashout (ATM)' : asset;
                assetHtml += `
                    <button class="option-btn" onclick="selectDestinationAsset('${asset}')">
                        <span>${icon} ${label}</span>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                `;
            });
            
            // Show cross-border notice if countries differ
            if (state.data.source_country !== destInst?.country) {
                assetHtml += `<div class="info-text" style="margin-top: 12px;">🌍 Cross-border transfer from ${state.data.source_country} to ${destInst?.country}</div>`;
            }
            
            assetHtml += `<button class="back-btn" onclick="goTo('select_destination')">← Back</button></div>`;
            html = assetHtml;
            break;
            
        case 'enter_destination_details':
            const destInstDetail = getInstitution(state.data.dest_institution);
            let placeholder = '';
            let inputType = 'text';
            
            if (state.data.dest_asset === 'CASHOUT') {
                placeholder = 'Beneficiary phone number';
                inputType = 'tel';
            } else if (state.data.dest_asset === 'ACCOUNT') {
                placeholder = 'Account number';
            } else {
                placeholder = 'Wallet phone number';
                inputType = 'tel';
            }
            
            html = `
                <div class="question">Enter ${placeholder}</div>
                <div class="input-group">
                    <input type="${inputType}" id="destInput" class="ussd-input" placeholder="${placeholder}" autofocus>
                    <button class="submit-btn" onclick="submitDestination()">Continue</button>
                </div>
                <button class="back-btn" onclick="goTo('select_destination_asset')">← Back</button>
            `;
            break;
            
        case 'confirm':
            const sourceInstConfirm = getInstitution(state.data.source_code);
            const destInstConfirm = getInstitution(state.data.dest_institution);
            const amountFormatted = parseFloat(state.data.amount).toFixed(2);
            const isCrossBorder = state.data.source_country !== state.data.dest_country;
            const crossBorderNote = isCrossBorder ? '<div class="info-text" style="margin-top: 8px;">🌍 Cross-border fees may apply</div>' : '';
            
            html = `
                <div class="question">📋 Confirm transaction</div>
                <div class="options">
                    <button class="option-btn" style="justify-content: space-between;">
                        <span>From</span>
                        <span>${sourceInstConfirm?.name} • ${state.data.source_asset}</span>
                    </button>
                    <button class="option-btn" style="justify-content: space-between;">
                        <span>Amount</span>
                        <span>BWP ${amountFormatted}</span>
                    </button>
                    <button class="option-btn" style="justify-content: space-between;">
                        <span>To</span>
                        <span>${destInstConfirm?.name}</span>
                    </button>
                    <button class="option-btn" style="justify-content: space-between;">
                        <span>Receive as</span>
                        <span>${state.data.dest_asset === 'CASHOUT' ? '💰 Cashout' : state.data.dest_asset}</span>
                    </button>
                    <button class="option-btn" style="justify-content: space-between;">
                        <span>Destination</span>
                        <span>${state.data.dest_value}</span>
                    </button>
                </div>
                ${crossBorderNote}
                <div style="display: flex; gap: 12px; margin-top: 20px;">
                    <button class="submit-btn" style="flex:1;" onclick="executeSwap()">✅ Confirm</button>
                    <button class="back-btn" style="flex:1; margin-top:0;" onclick="goTo('enter_destination_details')">✏️ Edit</button>
                </div>
            `;
            break;
            
        case 'processing':
            html = `
                <div class="loading">
                    <div class="spinner"></div>
                    <div>Processing transaction...</div>
                    <div class="keypad-hint">Please wait</div>
                </div>
            `;
            break;
            
        case 'result':
            const isSuccess = state.result?.status === 'success';
            const resultIcon = isSuccess ? '✅' : '❌';
            const resultClass = isSuccess ? 'success' : 'error';
            const resultMsg = isSuccess ? (state.result?.message || 'Transaction successful!') : (state.result?.message || 'Transaction failed');
            const ref = state.result?.swap_reference || '';
            const slogan = slogans[state.data.dest_country] || slogans['BW'];
            html = `
                <div class="result-screen">
                    <div class="result-icon ${resultClass}">${resultIcon}</div>
                    <div style="font-size: 20px; font-weight: 700;">${isSuccess ? 'COMPLETED' : 'FAILED'}</div>
                    <div class="result-message">${resultMsg}</div>
                    ${ref ? `<div style="font-size: 11px; color: #666; margin-top: 8px;">Ref: ${ref.substring(0, 16)}...</div>` : ''}
                    <div class="info-text" style="margin-top: 16px;">${slogan}</div>
                    <button class="done-btn" onclick="reset()">Done</button>
                </div>
            `;
            break;
            
        case 'link_source':
            html = `
                <div class="question">🔗 Link new source</div>
                <div class="input-group">
                    <select id="linkInstitution" class="ussd-select" onchange="updateLinkAssetTypes()">
                        <option value="">Select institution</option>
                        ${participants.map(p => `<option value="${p.code}" data-wallet-types='${JSON.stringify(p.wallet_types)}'>${p.name} (${p.country})</option>`).join('')}
                    </select>
                    <select id="linkAssetType" class="ussd-select">
                        <option value="">Select asset type</option>
                    </select>
                    <input type="text" id="linkIdentifier" class="ussd-input" placeholder="Account/Phone/Card number">
                    <button class="submit-btn" onclick="linkSource()">Link Source</button>
                </div>
                <button class="back-btn" onclick="goTo('init')">← Back</button>
            `;
            break;
            
        case 'history':
            html = `<div class="loading"><div class="spinner"></div><div>Loading transactions...</div></div>`;
            loadTransactions();
            break;
            
        case 'history_list':
            let txHtml = '<div class="question">📜 Recent transactions</div><div class="options">';
            if (state.transactions && state.transactions.length > 0) {
                state.transactions.slice(0, 5).forEach(tx => {
                    const statusIcon = tx.status === 'completed' ? '✅' : (tx.status === 'pending' ? '⏳' : '❌');
                    txHtml += `
                        <button class="option-btn" style="justify-content: space-between;">
                            <span>${statusIcon} ${tx.amount} BWP</span>
                            <span style="font-size: 11px; color:#666;">${tx.date}</span>
                        </button>
                    `;
                });
            } else {
                txHtml += '<div style="padding: 20px; text-align: center; color: #666;">No transactions</div>';
            }
            txHtml += `<button class="back-btn" onclick="goTo('init')">← Back</button></div>`;
            html = txHtml;
            break;
            
        default:
            html = `<div class="loading">Error: Unknown step</div>`;
    }
    
    screen.innerHTML = html;
}

// Navigation functions
function goTo(step) {
    state.step = step;
    render();
}

function selectSource(id, code, type, country) {
    state.data.source_id = id;
    state.data.source_code = code;
    state.data.source_asset = type;
    state.data.source_country = country;
    goTo('select_source_asset');
}

function selectSourceAsset(asset) {
    state.data.source_asset = asset;
    goTo('enter_amount');
}

function submitAmount() {
    const amount = document.getElementById('amountInput')?.value;
    if (!amount || parseFloat(amount) < 10) {
        alert('Enter valid amount (minimum BWP 10)');
        return;
    }
    state.data.amount = parseFloat(amount);
    goTo('select_destination');
}

function selectDestination(code, country) {
    state.data.dest_institution = code;
    state.data.dest_country = country;
    state.data.is_cross_border = (state.data.source_country !== country);
    goTo('select_destination_asset');
}

function selectDestinationAsset(asset) {
    state.data.dest_asset = asset;
    goTo('enter_destination_details');
}

function submitDestination() {
    const value = document.getElementById('destInput')?.value;
    if (!value) {
        alert('Enter destination details');
        return;
    }
    state.data.dest_value = value;
    goTo('confirm');
}

async function executeSwap() {
    goTo('processing');
    
    const formData = new FormData();
    formData.append('action', 'swap');
    formData.append('source_id', state.data.source_id);
    formData.append('source_asset_type', state.data.source_asset);
    formData.append('amount', state.data.amount);
    formData.append('dest_institution', state.data.dest_institution);
    formData.append('dest_asset_type', state.data.dest_asset);
    formData.append('dest_value', state.data.dest_value);
    formData.append('is_cross_border', state.data.is_cross_border ? '1' : '0');
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        state.result = result;
        goTo('result');
    } catch (error) {
        state.result = { status: 'error', message: error.message };
        goTo('result');
    }
}

function reset() {
    state = {
        step: 'init',
        data: {
            source_id: null,
            source_code: null,
            source_asset: null,
            source_country: null,
            amount: null,
            dest_institution: null,
            dest_asset: null,
            dest_country: null,
            dest_value: null,
            is_cross_border: false
        }
    };
    render();
}

function updateLinkAssetTypes() {
    const select = document.getElementById('linkInstitution');
    const code = select.value;
    const inst = participants.find(p => p.code === code);
    const assetSelect = document.getElementById('linkAssetType');
    
    if (inst && inst.wallet_types) {
        assetSelect.innerHTML = '<option value="">Select asset type</option>';
        inst.wallet_types.forEach(type => {
            assetSelect.innerHTML += `<option value="${type}">${type}</option>`;
        });
    } else {
        assetSelect.innerHTML = '<option value="">No asset types available</option>';
    }
}

async function linkSource() {
    const institution = document.getElementById('linkInstitution').value;
    const assetType = document.getElementById('linkAssetType').value;
    const identifier = document.getElementById('linkIdentifier').value;
    
    if (!institution || !assetType || !identifier) {
        alert('Please fill all fields');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'link_source');
    formData.append('institution_code', institution);
    formData.append('asset_type', assetType);
    formData.append('identifier', identifier);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        
        if (result.status === 'success') {
            alert('Source linked successfully!');
            location.reload();
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function loadTransactions() {
    try {
        const response = await fetch(window.location.href + '?action=transactions', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        state.transactions = data.transactions || [];
        goTo('history_list');
    } catch (error) {
        state.transactions = [];
        goTo('history_list');
    }
}

// Initialize
render();
</script>
</body>
</html>
